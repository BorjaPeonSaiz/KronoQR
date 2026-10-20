<?php

declare(strict_types=1);

use App\Support\Observability\Logging\ConsoleAwareStreamHandler;
use Illuminate\Support\Facades\Log;
use Monolog\Level;
use Monolog\LogRecord;
use Symfony\Component\Process\Process;

/*
 * **EL LOG DE UNA ORDEN DE CONSOLA VA AL LOG DEL CONTENEDOR, NO A LA TERMINAL DEL
 * OPERADOR** (V1-PL-01, V21-4-PL-2, I11; decision del propietario del 20-10-2026).
 *
 * `docker compose exec app php artisan …` escribia en la terminal del IT, en
 * JSON, todo lo que el producto registraba: un INFO de rutina, el WARNING del
 * cortacircuitos, el ERROR con la excepcion entera. Aqui se comprueba que, en un
 * proceso de consola que no es el PID 1 —el caso de Pest y el de `exec`—, el canal
 * `stderr` escribe en el log del contenedor (en la prueba, un fichero temporal en
 * lugar de `/proc/1/fd/2`) y no en su salida de error.
 */

/** Un fichero temporal que hace de `/proc/1/fd/2`. */
function consoleLogContainerFile(): string
{
    return (string) tempnam(sys_get_temp_dir(), 'kq-container-log-');
}

it('solo desvia el destino de serie, solo en consola y solo si no es el proceso principal', function (mixed $stream, string $sapi, int|false $pid, bool $esperado): void {
    expect(ConsoleAwareStreamHandler::redirects($stream, $sapi, $pid))->toBe($esperado);
})->with([
    'docker compose exec … artisan' => ['php://stderr', 'cli', 4242, true],
    'horizon, scheduler o run --rm: PID 1' => ['php://stderr', 'cli', 1, false],
    'PHP-FPM' => ['php://stderr', 'fpm-fcgi', 4242, false],
    'un fichero configurado a proposito' => ['/var/log/kronoqr.log', 'cli', 4242, false],
])->group('RNF-D-02');

it('en un proceso de consola que no es el PID 1, el canal stderr escribe en el log del contenedor y no en stderr', function (): void {
    // Pest es exactamente eso: `cli` y no el PID 1.
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

it('un manejador construido para consola no-PID-1 abre el destino del contenedor', function (): void {
    $contenedor = consoleLogContainerFile();

    $handler = new ConsoleAwareStreamHandler('php://stderr', Level::Debug, containerLog: $contenedor);
    $handler->handle(new LogRecord(new DateTimeImmutable, 'prueba', Level::Info, 'kq.directo'));
    $handler->close();

    expect((string) file_get_contents($contenedor))->toContain('kq.directo');
    unlink($contenedor);
})->group('RNF-D-02');

it('regresion de V1-PL-01 y V21-4-PL-2: una orden de consola no deja lineas JSON en la terminal', function (): void {
    // Un proceso de consola de verdad, aparte, como `docker compose exec … artisan`:
    // arranca la aplicacion y registra un WARNING como el del cortacircuitos. Su
    // salida de error tiene que quedar limpia, y la linea, en el log del contenedor.
    $contenedor = consoleLogContainerFile();
    $codigo = 'require "vendor/autoload.php";'
        .'$app = require "bootstrap/app.php";'
        .'$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();'
        .'config(["logging.default" => "stack", "logging.channels.stack.channels" => ["stderr"],'
        .' "logging.channels.stderr.handler_with.containerLog" => getenv("KQ_PRUEBA_LOG")]);'
        .'Illuminate\Support\Facades\Log::warning("redis.circuit_opened", ["failure" => "RedisException"]);'
        .'echo "orden terminada\n";';

    $proceso = new Process(['php', '-r', $codigo], base_path(), ['KQ_PRUEBA_LOG' => $contenedor], timeout: 60.0);
    $proceso->run();
    $log = (string) file_get_contents($contenedor);
    unlink($contenedor);

    expect($proceso->getExitCode())->toBe(0, $proceso->getErrorOutput())
        ->and($proceso->getOutput())->toContain('orden terminada')
        ->and($proceso->getErrorOutput())->not->toContain('redis.circuit_opened')
        ->and($proceso->getOutput())->not->toContain('redis.circuit_opened')
        ->and($log)->toContain('redis.circuit_opened');
})->group('RNF-D-02');
