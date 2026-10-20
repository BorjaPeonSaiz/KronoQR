<?php

declare(strict_types=1);

use App\Support\Observability\Logging\ConsoleAwareStreamHandler;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Process\Process;

/*
 * **EL LOG DE UNA ORDEN LANZADA CON `docker compose exec` VA AL LOG DEL
 * CONTENEDOR, NO A LA TERMINAL DEL OPERADOR** (V1-PL-01, V21-4-PL-2, I11; decision
 * del propietario del 20-10-2026).
 *
 * La senal es `KQ_CONTAINER_MAIN`, que exporta el entrypoint y no hereda `exec`.
 * `phpunit.xml` la declara para toda la suite (que corre con `exec`); las pruebas
 * del desvio la retiran. En lugar de `/proc/1/fd/2`, un fichero temporal.
 */

/** Un fichero temporal que hace de `/proc/1/fd/2`. */
function consoleLogContainerFile(): string
{
    return (string) tempnam(sys_get_temp_dir(), 'kq-container-log-');
}

/**
 * Ejecuta $codigo en un PHP aparte con la aplicacion cargada y SIN
 * `KQ_CONTAINER_MAIN`: como una orden de `docker compose exec`.
 *
 * @param  array<string, string>  $env
 */
function consoleLogProceso(string $codigo, array $env = []): Process
{
    $proceso = new Process(
        ['php', '-r', 'require "vendor/autoload.php"; '.$codigo],
        base_path(),
        [ConsoleAwareStreamHandler::CONTAINER_MAIN => false, ...$env],
        timeout: 60.0,
    );
    $proceso->run();

    return $proceso;
}

afterEach(function (): void {
    putenv(ConsoleAwareStreamHandler::CONTAINER_MAIN.'=1');
    Log::forgetChannel('stderr');
});

it('solo desvia el destino de serie, solo en consola y solo fuera del proceso principal del contenedor', function (mixed $stream, string $sapi, bool $principal, bool $esperado): void {
    expect(ConsoleAwareStreamHandler::redirects($stream, $sapi, $principal))->toBe($esperado);
})->with([
    'docker compose exec … artisan' => ['php://stderr', 'cli', false, true],
    'horizon, scheduler, sus hijos o run --rm' => ['php://stderr', 'cli', true, false],
    'PHP-FPM' => ['php://stderr', 'fpm-fcgi', false, false],
    'un fichero configurado a proposito' => ['/var/log/kronoqr.log', 'cli', false, false],
])->group('RNF-D-02');

it('sin la senal del entrypoint, el canal stderr escribe en el log del contenedor', function (): void {
    putenv(ConsoleAwareStreamHandler::CONTAINER_MAIN);
    $contenedor = consoleLogContainerFile();
    config(['logging.channels.stderr.handler_with.containerLog' => $contenedor]);
    Log::forgetChannel('stderr');

    Log::channel('stderr')->warning('redis.circuit_opened', ['clave' => 'valor']);

    $lineas = array_values(array_filter(explode("\n", (string) file_get_contents($contenedor))));
    unlink($contenedor);

    expect($lineas)->toHaveCount(1)
        ->and(json_decode($lineas[0], true, flags: JSON_THROW_ON_ERROR))->toMatchArray([
            'message' => 'redis.circuit_opened',
            'level_name' => 'WARNING',
        ]);
})->group('RNF-D-02');

it('con la senal (el proceso principal, sus hijos y esta suite) no desvia nada', function (): void {
    $contenedor = consoleLogContainerFile();
    config(['logging.channels.stderr.handler_with.containerLog' => $contenedor]);
    Log::forgetChannel('stderr');

    Log::channel('stderr')->warning('redis.circuit_opened');

    expect((string) file_get_contents($contenedor))->toBe('');
    unlink($contenedor);
})->group('RNF-D-02');

it('si el log del contenedor no se puede escribir, sale por la salida de error', function (): void {
    $proceso = consoleLogProceso(
        '$h = new App\Support\Observability\Logging\ConsoleAwareStreamHandler("php://stderr", containerLog: "/nonexistent/kq/log");'
        .'$h->setFormatter(new Monolog\Formatter\JsonFormatter);'
        .'$h->handle(new Monolog\LogRecord(new DateTimeImmutable, "prueba", Monolog\Level::Warning, "kq.sin.destino"));'
    );

    expect($proceso->getExitCode())->toBe(0, $proceso->getErrorOutput())
        ->and($proceso->getErrorOutput())->toContain('kq.sin.destino');
})->group('RNF-D-02');

it('si el lector del log del contenedor muere, no lanza nada y sigue por la salida de error', function (): void {
    // Una tuberia cuyo lector (`true`) ya ha terminado: escribir en ella da EPIPE.
    $proceso = consoleLogProceso(
        '$p = proc_open(["true"], [0 => ["pipe", "r"]], $pipes); while (proc_get_status($p)["running"]) { usleep(10000); }'
        .'$h = new App\Support\Observability\Logging\ConsoleAwareStreamHandler("php://stderr", containerStream: $pipes[0]);'
        .'$h->setFormatter(new Monolog\Formatter\JsonFormatter);'
        .'foreach (["kq.primera", "kq.segunda"] as $m) { $h->handle(new Monolog\LogRecord(new DateTimeImmutable, "prueba", Monolog\Level::Warning, $m)); }'
        .'echo "sigue\n";'
    );

    expect($proceso->getExitCode())->toBe(0, $proceso->getErrorOutput())
        ->and($proceso->getOutput())->toContain('sigue')
        ->and($proceso->getErrorOutput())->toContain('kq.primera')
        ->and($proceso->getErrorOutput())->toContain('kq.segunda');
})->group('RNF-D-02');

it('regresion de V1-PL-01 y V21-4-PL-2: una orden de consola no deja lineas JSON en la terminal', function (): void {
    // Un proceso de consola aparte, como `docker compose exec … artisan`: arranca la
    // aplicacion y registra un WARNING como el del cortacircuitos.
    $contenedor = consoleLogContainerFile();
    $proceso = consoleLogProceso(
        '$app = require "bootstrap/app.php";'
        .'$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();'
        .'config(["logging.default" => "stack", "logging.channels.stack.channels" => ["stderr"],'
        .' "logging.channels.stderr.handler_with.containerLog" => getenv("KQ_PRUEBA_LOG")]);'
        .'Illuminate\Support\Facades\Log::warning("redis.circuit_opened", ["failure" => "RedisException"]);'
        .'echo "orden terminada\n";',
        ['KQ_PRUEBA_LOG' => $contenedor],
    );
    $log = (string) file_get_contents($contenedor);
    unlink($contenedor);

    expect($proceso->getExitCode())->toBe(0, $proceso->getErrorOutput())
        ->and($proceso->getOutput())->toContain('orden terminada')
        ->and($proceso->getErrorOutput())->not->toContain('redis.circuit_opened')
        ->and($proceso->getOutput())->not->toContain('redis.circuit_opened')
        ->and($log)->toContain('redis.circuit_opened');
})->group('RNF-D-02');
