<?php

declare(strict_types=1);

use App\Modules\Product\Application\Port\LicenseMetrics;
use App\Modules\Product\Application\Port\LicenseRepository;
use App\Modules\Product\Application\Port\LicenseStatePublisher;
use App\Modules\Product\Application\Port\LicenseVerifier;
use App\Modules\Product\Application\Port\PlanUsageCounter;
use App\Modules\Product\Application\UseCase\GetLicenseStatusHandler;
use App\Modules\Product\Application\UseCase\RecordPlanUsageHandler;
use App\Modules\Product\Domain\Event\PlanLimitExceeded;
use App\Modules\Product\Domain\ValueObject\License;
use App\Modules\Product\Domain\ValueObject\LicenseVerification;
use App\Modules\Product\Domain\ValueObject\PlanLimit;
use App\Modules\Product\Domain\ValueObject\StoredLicense;
use App\Modules\Shared\Application\Port\Clock;
use Tests\Support\Product\RecordingProductEvents;
use Tests\Support\Time\FixedClock;

/*
 * **EL CRUCE DEL UMBRAL, CALCULADO UNA VEZ SOBRE EL LOTE** (H-04 de la revision
 * de la 3.8; RF-PD-04, RF-GP-05, ADR-028).
 *
 * ## Que se prueba aqui y que no
 *
 * Aqui, la aritmetica: con cuanto se cruza, cuantas de las altas que acaban de
 * entrar quedan por encima del plan y cuando NO hay nada que anotar. Que la
 * importacion deje un solo asiento en `audit_log` —y que el alta de una en una
 * siga dejando el suyo— lo prueba
 * `tests/Feature/Product/PlanExcessSeatsOnImportTest.php`, que para eso necesita
 * base de datos.
 *
 * Sin base de datos y sin contenedor: `GetLicenseStatusHandler` es final y no se
 * puede doblar —es el punto unico de resolucion de la licencia, ADR-023—, asi
 * que lo que se sustituye son sus **puertos**, como hace
 * `Tests\Support\Product\UnlicensedInstallation`. El reloj esta detenido (regla
 * dura 2).
 *
 * ## Por que la cuenta vieja no valia para un lote
 *
 * `firstCrossing` era «el exceso vale exactamente 1», que solo es cierto cuando
 * las altas entran de una en una. Con 300 personas de golpe, lo cumplia como
 * mucho una fila por casualidad —y en la practica ninguna, porque la evaluacion
 * se difiere al `COMMIT` del lote y todas veian ya el recuento final—.
 */

/**
 * Un observador del plan con la licencia y el recuento que se le digan.
 *
 * `$contracted` es el `max_employees` de la clave firmada y `$actual` lo que
 * cuenta la instalacion **despues** de la operacion, que es cuando se evalua.
 */
function planUsageFor(int $contracted, int $actual, RecordingProductEvents $events): RecordPlanUsageHandler
{
    $clock = FixedClock::at('2026-06-15 09:00:00');

    $license = License::fromClaims([
        'license_id' => 'KQ-PRUEBAS-1',
        'customer_name' => 'Hotel de Pruebas, S.L.',
        'plan' => 'estandar',
        'max_employees' => $contracted,
        'max_devices' => 3,
        'features' => [],
        'valid_from' => '2026-01-01T00:00:00Z',
        'valid_until' => '2026-12-31T23:59:59Z',
        'issued_at' => '2025-12-15T10:00:00Z',
    ]);

    $status = new GetLicenseStatusHandler(
        licenses: new class($clock) implements LicenseRepository
        {
            public function __construct(private readonly Clock $clock) {}

            // Covariante a proposito: esta instalacion SIEMPRE tiene licencia, y
            // declararlo asi evita el `?` que nunca se cumple.
            public function current(): StoredLicense
            {
                return new StoredLicense('KQ1.firma-de-prueba', $this->clock->now(), null);
            }

            public function activate(string $signedKey, License $license, DateTimeImmutable $activatedAt, ?int $actorUserId): void
            {
                throw new RuntimeException('Esta prueba no activa licencias.');
            }

            public function markVerified(DateTimeImmutable $verifiedAt): void {}
        },
        verifier: new class($license) implements LicenseVerifier
        {
            public function __construct(private readonly License $license) {}

            public function verify(string $signedKey): LicenseVerification
            {
                return LicenseVerification::verified($this->license);
            }
        },
        probe: new class implements LicenseStatePublisher
        {
            public function publish(string $state): void {}
        },
        clock: $clock,
        expiryWarningDays: 30,
    );

    return new RecordPlanUsageHandler(
        status: $status,
        counter: new class($actual) implements PlanUsageCounter
        {
            public function __construct(private readonly int $actual) {}

            public function count(PlanLimit $limit): int
            {
                return $this->actual;
            }
        },
        events: $events,
        metrics: new class implements LicenseMetrics
        {
            public function limitExceeded(PlanLimit $limit): void {}
        },
        clock: $clock,
    );
}

/**
 * El unico evento publicado, ya tipado.
 */
function planExcessEvent(RecordingProductEvents $events): PlanLimitExceeded
{
    expect($events->published)->toHaveCount(1);

    $event = $events->published[0];

    expect($event)->toBeInstanceOf(PlanLimitExceeded::class);

    /** @var PlanLimitExceeded $event */
    return $event;
}

it('publica un solo hecho por importacion, con el cruce y el exceso del lote', function (
    int $contracted,
    int $actual,
    int $added,
    bool $crossing,
    int $addedInExcess,
): void {
    $events = new RecordingProductEvents;

    planUsageFor($contracted, $actual, $events)->handleBatch(PlanLimit::Employees, $added);

    $event = planExcessEvent($events);

    expect($event->contracted)->toBe($contracted)
        // El recuento FINAL de la instalacion, no el de la fila que cruzo.
        ->and($event->reached)->toBe($actual)
        ->and($event->firstCrossing)->toBe($crossing)
        ->and($event->addedInExcess)->toBe($addedInExcess);
})->with([
    // El caso del informe: plan de 80, el hotel tenia 70 e importa 30. Cruza la
    // importacion, y veinte de las treinta quedan por encima del plan.
    'el lote cruza el umbral' => [80, 100, 30, true, 20],
    // El borde exacto: la instalacion estaba JUSTO en el tope, asi que las
    // treinta del fichero estan en exceso y el cruce sigue siendo de este lote.
    'estaba justo en el tope' => [80, 110, 30, true, 30],
    // Ya se operaba fuera de plan antes de importar: no es el cruce, y las
    // treinta cuentan enteras porque ninguna cabia.
    'ya se estaba en exceso' => [80, 120, 30, false, 30],
    // Un lote pequeño sobre un exceso grande: solo cuenta lo que trajo.
    'lote pequeño sobre un exceso grande' => [80, 120, 5, false, 5],
    // Una sola alta por el camino del lote da exactamente lo que daba el camino
    // individual: es la misma cuenta, no una paralela.
    'un lote de una sola alta' => [2, 3, 1, true, 1],
])->group('RF-PD-04', 'RF-GP-05');

it('no publica nada cuando la importacion entera cabe en el plan', function (): void {
    $events = new RecordingProductEvents;

    planUsageFor(contracted: 80, actual: 80, events: $events)->handleBatch(PlanLimit::Employees, 30);

    expect($events->published)->toBe([]);
})->group('RF-PD-04', 'RF-GP-05');

it('no publica nada por una importacion que no dio de alta a nadie', function (): void {
    // Un fichero que solo corrige apellidos no cambia el recuento. Escribir un
    // asiento de exceso por el diria que alguien se paso del plan el dia que
    // arreglo cuarenta fichas.
    $events = new RecordingProductEvents;

    planUsageFor(contracted: 3, actual: 40, events: $events)->handleBatch(PlanLimit::Employees, 0);

    expect($events->published)->toBe([]);
})->group('RF-PD-04', 'RF-GP-05');

it('deja el alta de una en una exactamente como estaba', function (int $actual, bool $crossing): void {
    // El camino individual no cambia con esta correccion: cruza cuando el exceso
    // es de uno y anota cada alta posterior en exceso (ADR-028).
    $events = new RecordingProductEvents;

    planUsageFor(contracted: 2, actual: $actual, events: $events)->handle(PlanLimit::Employees);

    expect(planExcessEvent($events)->firstCrossing)->toBe($crossing)
        // Una sola alta: como mucho una unidad de esta operacion esta en exceso.
        ->and(planExcessEvent($events)->addedInExcess)->toBe(1);
})->with([
    'el alta que cruza' => [3, true],
    'la siguiente en exceso' => [4, false],
    'la de mucho despues' => [40, false],
])->group('RF-PD-04');
