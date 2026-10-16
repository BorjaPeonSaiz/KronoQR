<?php

declare(strict_types=1);

use App\Modules\Compliance\Application\Exception\IncidentNotFound;
use App\Modules\Kiosk\Application\Exception\PairingCapacityExhausted;
use App\Modules\Product\Domain\Exception\DiagnosticsAuditNotDeferred;
use App\Modules\Reporting\Application\Exception\ReportRenderingBusy;
use App\Modules\Shared\Application\Port\UseCaseMetrics;
use App\Modules\Shared\Application\Support\UseCaseInstrumentation;
use App\Modules\Shared\Application\Support\UseCaseMetricsRegistry;
use App\Modules\Shared\Infrastructure\Metrics\RedisUseCaseMetrics;
use Illuminate\Contracts\Redis\Factory;
use Tests\Support\Telemetry\RecordingTracer;

/*
 * La medida comun de los casos de uso (doc 02 §8, bloque 24 de la 2.2.1), sin
 * base de datos ni Laravel.
 *
 * Lo que se afirma es lo que el ayudante promete a quien lo envuelve: devuelve
 * lo mismo que el cuerpo, relanza **la misma** excepcion, observa `ok` o
 * `error`, cierra el span en los dos caminos, y ni el puerto ni su resolucion
 * pueden romper el caso de uso. Y del adaptador, la puerta de las etiquetas: lo
 * que no tenga forma de `<modulo>.<accion_snake>` no llega a Redis.
 */

/**
 * Un puerto que apunta lo que observa en `$observado`.
 *
 * @param  ArrayObject<int, array{0: string, 1: string, 2: float}>  $observado
 */
function instrumentacionMetricasQueApuntan(ArrayObject $observado): UseCaseMetrics
{
    return new readonly class($observado) implements UseCaseMetrics
    {
        /**
         * @param  ArrayObject<int, array{0: string, 1: string, 2: float}>  $observed
         */
        public function __construct(private ArrayObject $observed) {}

        public function observe(string $useCase, string $outcome, float $seconds): void
        {
            $this->observed[] = [$useCase, $outcome, $seconds];
        }
    };
}

/**
 * Un puerto que siempre lanza, como un Redis caido sin la guarda del adaptador.
 */
function instrumentacionMetricasQueLanzan(): UseCaseMetrics
{
    return new class implements UseCaseMetrics
    {
        public function observe(string $useCase, string $outcome, float $seconds): void
        {
            throw new RuntimeException('Redis no responde');
        }
    };
}

/**
 * Un Redis que apunta en `$conexiones` cada vez que alguien le pide una
 * conexion, y la niega. Para el adaptador, que descarta antes de tocarla.
 *
 * @param  ArrayObject<int, string>  $conexiones
 */
function instrumentacionRedisQueApunta(ArrayObject $conexiones): Factory
{
    return new readonly class($conexiones) implements Factory
    {
        /**
         * @param  ArrayObject<int, string>  $connections
         */
        public function __construct(private ArrayObject $connections) {}

        /**
         * @param  UnitEnum|string|null  $name
         * @return never
         */
        public function connection($name = null)
        {
            $this->connections[] = 'connection';

            throw new RuntimeException('Connection refused [tcp://redis:6379]');
        }
    };
}

afterEach(function (): void {
    UseCaseMetricsRegistry::forget();
});

it('devuelve lo que devuelve el cuerpo y observa ok', function (): void {
    /** @var ArrayObject<int, array{0: string, 1: string, 2: float}> $observado */
    $observado = new ArrayObject;
    $metricas = instrumentacionMetricasQueApuntan($observado);
    UseCaseMetricsRegistry::resolveWith(static fn (): UseCaseMetrics => $metricas);

    $resultado = UseCaseInstrumentation::run('workforce.register_employee', static fn (): string => 'alta');

    [$caso, $desenlace, $segundos] = $observado->getArrayCopy()[0] ?? ['', '', -1.0];

    expect($resultado)->toBe('alta')
        ->and($observado)->toHaveCount(1)
        ->and($caso)->toBe('workforce.register_employee')
        ->and($desenlace)->toBe('ok')
        ->and($segundos)->toBeGreaterThanOrEqual(0.0);
})->group('RQ-06', 'RNF-D-01');

it('relanza la misma excepcion del cuerpo y observa error', function (): void {
    /** @var ArrayObject<int, array{0: string, 1: string, 2: float}> $observado */
    $observado = new ArrayObject;
    $metricas = instrumentacionMetricasQueApuntan($observado);
    UseCaseMetricsRegistry::resolveWith(static fn (): UseCaseMetrics => $metricas);
    $fallo = new RuntimeException('la base de datos no responde');

    $capturada = null;

    try {
        UseCaseInstrumentation::run('identity.log_out', static function () use ($fallo): never {
            throw $fallo;
        });
    } catch (RuntimeException $excepcion) {
        $capturada = $excepcion;
    }

    expect($capturada)->toBe($fallo)
        ->and($observado)->toHaveCount(1)
        ->and($observado->getArrayCopy()[0][1] ?? null)->toBe('error');
})->group('RQ-06', 'RNF-D-01');

it('sin adaptador registrado ejecuta el caso de uso igual', function (): void {
    // Es el estado de las pruebas unitarias de los casos de uso: medir no puede
    // ser requisito para ejecutarlos.
    expect(UseCaseInstrumentation::run('workforce.create_site', static fn (): int => 7))->toBe(7);
})->group('RQ-06', 'RNF-D-01');

it('un puerto que lanza no rompe el caso de uso', function (): void {
    UseCaseMetricsRegistry::resolveWith(static fn (): UseCaseMetrics => instrumentacionMetricasQueLanzan());

    expect(UseCaseInstrumentation::run('workforce.create_site', static fn (): int => 7))->toBe(7);
})->group('RQ-06', 'RNF-D-01');

it('un resolutor que lanza no rompe el caso de uso', function (): void {
    UseCaseMetricsRegistry::resolveWith(static function (): UseCaseMetrics {
        throw new RuntimeException('el contenedor no puede construir el adaptador');
    });

    expect(UseCaseInstrumentation::run('workforce.create_site', static fn (): int => 7))->toBe(7);
})->group('RQ-06', 'RNF-D-01');

it('cierra el span con el desenlace en el camino feliz y en el de error', function (): void {
    RecordingTracer::around(function (RecordingTracer $tracer): void {
        UseCaseInstrumentation::run('workforce.update_site', static fn (): bool => true);

        try {
            UseCaseInstrumentation::run('workforce.void_absence', static function (): never {
                throw new LogicException('fallo');
            });
        } catch (LogicException) {
            // Esperado: lo que se mira es el span.
        }

        $cerrados = $tracer->finishedSpans();

        expect($cerrados)->toHaveCount(2)
            ->and($cerrados[0]->getName())->toBe('workforce.update_site')
            ->and($cerrados[0]->getInstrumentationScope()->getName())->toBe('kronoqr.workforce')
            ->and($cerrados[0]->getAttributes()->get('outcome'))->toBe('ok')
            ->and($cerrados[1]->getName())->toBe('workforce.void_absence')
            ->and($cerrados[1]->getAttributes()->get('outcome'))->toBe('error');
    });
})->group('RQ-06', 'RNF-D-01');

it('cierra el span aunque el puerto de metricas lance', function (): void {
    RecordingTracer::around(function (RecordingTracer $tracer): void {
        UseCaseMetricsRegistry::resolveWith(static fn (): UseCaseMetrics => instrumentacionMetricasQueLanzan());

        UseCaseInstrumentation::run('identity.unlock_portal_origin', static fn (): bool => false);

        $cerrados = $tracer->finishedSpans();

        expect($cerrados)->toHaveCount(1)
            ->and($cerrados[0]->getAttributes()->get('outcome'))->toBe('ok');
    });
})->group('RQ-06', 'RNF-D-01');

it('el adaptador descarta un use_case con espacio, arroba o guion sin tocar Redis', function (string $useCase): void {
    /** @var ArrayObject<int, string> $conexiones */
    $conexiones = new ArrayObject;

    new RedisUseCaseMetrics(instrumentacionRedisQueApunta($conexiones))->observe($useCase, 'ok', 0.01);

    expect($conexiones)->toHaveCount(0);
})->with([
    'espacio' => ['workforce.register employee'],
    'arroba' => ['workforce.register@employee'],
    'guion' => ['workforce.register-employee'],
    'sin modulo' => ['register_employee'],
    'mayusculas' => ['Workforce.RegisterEmployee'],
])->group('RQ-06', 'RNF-D-01');

it('el adaptador descarta un desenlace que no es ok, rejected ni error', function (): void {
    /** @var ArrayObject<int, string> $conexiones */
    $conexiones = new ArrayObject;

    new RedisUseCaseMetrics(instrumentacionRedisQueApunta($conexiones))->observe('workforce.register_employee', 'failed', 0.01);

    expect($conexiones)->toHaveCount(0);
})->group('RQ-06', 'RNF-D-01');

it('el adaptador traga el fallo de Redis con una observacion valida', function (): void {
    /** @var ArrayObject<int, string> $conexiones */
    $conexiones = new ArrayObject;

    new RedisUseCaseMetrics(instrumentacionRedisQueApunta($conexiones))->observe('workforce.register_employee', 'ok', 0.01);

    // Pidio la conexion —la observacion era valida— y el fallo no salio.
    expect($conexiones)->toHaveCount(1);
})->group('RQ-06', 'RNF-D-01');

it('observa rejected con una excepcion de negocio y la relanza igual', function (Throwable $fallo): void {
    /** @var ArrayObject<int, array{0: string, 1: string, 2: float}> $observado */
    $observado = new ArrayObject;
    $metricas = instrumentacionMetricasQueApuntan($observado);
    UseCaseMetricsRegistry::resolveWith(static fn (): UseCaseMetrics => $metricas);

    $capturada = null;

    try {
        UseCaseInstrumentation::run('product.generate_diagnostics_bundle', static function () use ($fallo): never {
            throw $fallo;
        });
    } catch (Throwable $excepcion) {
        $capturada = $excepcion;
    }

    expect($capturada)->toBe($fallo)
        ->and($observado->getArrayCopy()[0][1] ?? null)->toBe('rejected');
})->with([
    // Una `DomainException`, como las bases de Attendance, Workforce e Identity.
    'DomainException' => [new DomainException('regla de negocio')],
    // Una de un modulo cuya base hereda de `RuntimeException`: cuenta donde vive.
    'Product\Domain\Exception' => [DiagnosticsAuditNotDeferred::in('/tmp/pendientes')],
    'Compliance\Application\Exception' => [IncidentNotFound::withId(7)],
])->group('RQ-06', 'RNF-D-01');

it('una excepcion de indisponibilidad de un modulo es error y no rejected', function (Throwable $fallo): void {
    expect(UseCaseInstrumentation::isRejection($fallo))->toBeFalse();
})->with([
    // Por su nombre, y por el de su padre aunque el suyo no lo diga.
    'ReportRenderingBusy' => [ReportRenderingBusy::allSlotsTaken(30)],
    'PairingCapacityExhausted (es una PairingUnavailable)' => [new PairingCapacityExhausted(3, 3)],
    'RuntimeException suelta' => [new RuntimeException('Redis no responde')],
])->group('RQ-06', 'RNF-D-01');

it('cierra el span con los atributos que describen el resultado, sin pisar outcome', function (): void {
    RecordingTracer::around(function (RecordingTracer $tracer): void {
        UseCaseInstrumentation::run(
            'workforce.correct_site_timezone',
            static fn (): int => 3,
            static fn (int $tramos): array => ['shift_entry_count' => $tramos, 'outcome' => 'pisado'],
        );

        $atributos = $tracer->finishedSpans()[0]->getAttributes();

        expect($atributos->get('shift_entry_count'))->toBe(3)
            ->and($atributos->get('outcome'))->toBe('ok');
    });
})->group('RQ-06', 'RNF-D-01');

it('un descriptor que lanza no impide cerrar el span ni devolver el resultado', function (): void {
    RecordingTracer::around(function (RecordingTracer $tracer): void {
        $resultado = UseCaseInstrumentation::run(
            'workforce.correct_site_timezone',
            static fn (): int => 3,
            static function (int $tramos): array {
                throw new LogicException('no se puede describir');
            },
        );

        expect($resultado)->toBe(3)
            ->and($tracer->finishedSpans())->toHaveCount(1);
    });
})->group('RQ-06', 'RNF-D-01');
