<?php

declare(strict_types=1);

use App\Modules\Product\Application\Port\PlanUsageCounter;
use App\Modules\Product\Application\UseCase\BuildTelemetryReportHandler;
use App\Modules\Product\Application\UseCase\SendTelemetryHandler;
use App\Modules\Product\Domain\ValueObject\PlanLimit;
use App\Modules\Product\Domain\ValueObject\TelemetryBlockReason;
use App\Modules\Shared\Application\Port\FeatureGate;
use App\Modules\Shared\Domain\ValueObject\Feature;
use App\Modules\Shared\Domain\ValueObject\FeatureAvailability;
use App\Modules\Shared\Domain\ValueObject\FeatureRestriction;
use Psr\Log\NullLogger;
use Tests\Support\Product\InMemoryTelemetryStateStore;
use Tests\Support\Product\RecordingTelemetrySender;
use Tests\Support\Product\SpyTelemetryCounters;
use Tests\Support\Product\SpyTelemetryFacts;
use Tests\Support\Product\UnlicensedInstallation;
use Tests\Support\Time\FixedClock;

/*
 * **CON LA TELEMETRIA APAGADA NO SE CONSTRUYE NI SE ENVIA NADA** (RF-PD-12,
 * ADR-020, ficha 5.10 puntos 6 y 8).
 *
 * ## Que se comprueba de verdad, y por que no basta con «no hubo peticion»
 *
 * Una implementacion que montara el informe entero -recorriendo tablas, leyendo
 * Redis y ejecutando las nueve sondas de `doctor`- y luego decidiera no
 * enviarlo pasaria una prueba que solo mirase la red, y seria un producto que
 * gasta trabajo cada semana en una funcion que el cliente nunca activo. La
 * ficha dice que el sistema funciona «identicamente» sin telemetria, y eso
 * incluye no gastar una consulta en ella.
 *
 * Aqui los dobles **cuentan sus llamadas**: `$counters->calls`, `$facts->calls`,
 * `$state->loads` y `$sender->sent` tienen que quedarse todos a cero.
 *
 * ## Las tres condiciones, una por una
 *
 * La variable, el destino y la licencia. Cada una tiene su caso porque cada una
 * se olvida por su cuenta, y el motivo devuelto es lo que el comando imprime.
 *
 * Sin base de datos: los cuatro puertos son dobles y el reloj esta detenido.
 */

/**
 * @return array{handler: SendTelemetryHandler, sender: RecordingTelemetrySender, counters: SpyTelemetryCounters, facts: SpyTelemetryFacts, state: InMemoryTelemetryStateStore}
 */
function telemetriaCon(bool $enabled, string $endpoint, bool $licensed, bool $sinIdentidad = false): array
{
    $counters = new SpyTelemetryCounters(['scans_accepted' => 10]);
    $facts = new SpyTelemetryFacts;
    $state = $sinIdentidad ? new InMemoryTelemetryStateStore(null) : new InMemoryTelemetryStateStore;
    $sender = new RecordingTelemetrySender;
    $clock = FixedClock::at('2026-09-07 05:40:00');

    $build = new BuildTelemetryReportHandler(
        licenses: UnlicensedInstallation::licenses($clock),
        doctor: UnlicensedInstallation::doctor($clock),
        usage: new class implements PlanUsageCounter
        {
            public function count(PlanLimit $limit): int
            {
                // Si la telemetria estuviera apagada y alguien construyera el
                // informe igualmente, esto reventaria la prueba en el sitio.
                throw new RuntimeException('No se debe contar la plantilla con la telemetria apagada.');
            }
        },
        counters: $counters,
        facts: $facts,
        clock: $clock,
        productVersion: '2.1.0',
        phpVersion: '8.4.24',
        doctorLocale: 'es',
    );

    return [
        'handler' => new SendTelemetryHandler(
            reports: $build,
            sender: $sender,
            state: $state,
            features: new class($licensed) implements FeatureGate
            {
                public function __construct(private readonly bool $licensed) {}

                public function isEnabled(Feature $feature): bool
                {
                    return $this->licensed;
                }

                public function statusOf(Feature $feature): FeatureAvailability
                {
                    return $this->licensed
                        ? FeatureAvailability::granted($feature)
                        : FeatureAvailability::denied($feature, FeatureRestriction::NotInPlan);
                }
            },
            clock: $clock,
            logger: new NullLogger,
            enabled: $enabled,
            endpoint: $endpoint,
        ),
        'sender' => $sender,
        'counters' => $counters,
        'facts' => $facts,
        'state' => $state,
    ];
}

it('no construye ni envia nada cuando falta una de las tres condiciones', function (
    bool $enabled,
    string $endpoint,
    bool $licensed,
    TelemetryBlockReason $motivo,
): void {
    $escenario = telemetriaCon($enabled, $endpoint, $licensed);

    $outcome = $escenario['handler']->handle();

    expect($outcome->blockedBy)->toBe($motivo)
        ->and($outcome->isEnabled())->toBeFalse()
        // No hay documento: no se construyo.
        ->and($outcome->draft)->toBeNull()
        ->and($outcome->delivery)->toBeNull()
        // Y nadie toco nada.
        ->and($escenario['sender']->sent)->toBe([])
        ->and($escenario['counters']->calls)->toBe(0)
        ->and($escenario['facts']->calls)->toBe(0)
        ->and($escenario['state']->establishes)->toBe(0)
        ->and($escenario['state']->reads)->toBe(0)
        ->and($escenario['state']->saved)->toBe([]);
})->with([
    'la variable esta en false' => [false, 'https://ejemplo.invalid/t', true, TelemetryBlockReason::DisabledByConfiguration],
    'no hay destino' => [true, '', true, TelemetryBlockReason::EndpointNotConfigured],
    'el destino son espacios' => [true, '   ', true, TelemetryBlockReason::EndpointNotConfigured],
    'la licencia no la incluye' => [true, 'https://ejemplo.invalid/t', false, TelemetryBlockReason::NotInLicense],
    'el destino no es https' => [true, 'http://ejemplo.invalid/t', true, TelemetryBlockReason::EndpointNotHttps],
    'el destino no tiene esquema' => [true, 'ejemplo.invalid/t', true, TelemetryBlockReason::EndpointNotHttps],
    'el destino es un fichero local' => [true, 'file:///etc/passwd', true, TelemetryBlockReason::EndpointNotHttps],
    'no falta una, faltan las tres' => [false, '', false, TelemetryBlockReason::DisabledByConfiguration],
])->group('RF-PD-12', 'RF-PD-05');

it('un destino sin cifrar se rechaza como si no hubiera destino', function (): void {
    // `configuracion.md` §3 quinquies le promete al cliente que el envio va
    // «sobre HTTPS con el certificado verificado». Sin esta comprobacion, la
    // promesa se rompe escribiendo cuatro caracteres en el `.env` y el producto
    // no diria nada: el documento saldria en claro por la red del hotel.
    //
    // Y se rechaza CON MOTIVO PROPIO, no como «no hay destino»: la accion del
    // cliente es distinta -hay una direccion escrita y hay que corregirla-.
    $escenario = telemetriaCon(true, 'http://telemetria.ejemplo.invalid/v1', true);

    $outcome = $escenario['handler']->handle();

    expect($outcome->blockedBy)->toBe(TelemetryBlockReason::EndpointNotHttps)
        ->and($outcome->draft)->toBeNull()
        ->and($escenario['sender']->sent)->toBe([])
        ->and($escenario['counters']->calls)->toBe(0);
})->group('RF-PD-12', 'RS-08');

it('la vista previa no fija ni guarda la identidad de la instalacion', function (): void {
    // Mirar no puede tener efectos. Quien ejecuta `product:telemetry` sin
    // `--send` esta decidiendo si activa la telemetria, y una consulta que
    // dejara la identidad de la instalacion en el disco de quien decidio que no
    // seria una respuesta distinta de la pregunta.
    $escenario = telemetriaCon(true, 'https://telemetria.ejemplo.invalid/v1', true);

    $outcome = $escenario['handler']->preview();

    expect($outcome->draft)->not->toBeNull()
        ->and($escenario['state']->establishes)->toBe(0)
        ->and($escenario['state']->saved)->toBe([])
        // Y como aqui SI habia una guardada, el identificador es el definitivo.
        ->and($outcome->identityStored)->toBeTrue();
})->group('RF-PD-12');

it('sin identidad guardada, la vista previa avisa de que la que ensena es provisional', function (): void {
    $escenario = telemetriaCon(true, 'https://telemetria.ejemplo.invalid/v1', true, sinIdentidad: true);

    $outcome = $escenario['handler']->preview();

    expect($outcome->identityStored)->toBeFalse()
        ->and($escenario['state']->saved)->toBe([])
        // El informe se construye igual: lo que el cliente tiene que ver es el
        // documento entero, no un hueco donde ira el identificador.
        ->and($outcome->draft?->report->installationId)->not->toBeEmpty();
})->group('RF-PD-12');
