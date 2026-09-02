<?php

declare(strict_types=1);

use App\Modules\Product\Domain\ValueObject\License;
use App\Modules\Product\Domain\ValueObject\LicenseRejection;
use App\Modules\Product\Domain\ValueObject\LicenseState;
use App\Modules\Product\Domain\ValueObject\LicenseStatus;
use App\Modules\Shared\Application\Port\FeatureGate;
use App\Modules\Shared\Domain\ValueObject\Feature;
use Tests\Support\Time\FixedClock;

/*
 * LA FRONTERA DE ADR-023, convertida en algo que falla solo.
 *
 * Es la prueba central de la tarea 5.3 y la que materializa ADR-019: con la
 * licencia caducada, ausente o ilegible, **nada del registro legal se puede
 * degradar** — y no porque nadie lo haga, sino porque no existe forma de
 * expresarlo.
 *
 * Las pruebas de feature (`LicenseDoesNotBlockTest`) comprueban lo mismo desde
 * fuera, con peticiones reales. Estas dos capas son complementarias: aquella
 * demuestra que HOY funciona, esta demuestra que no se puede romper mañana sin
 * cambiar el catalogo a proposito.
 */

/**
 * Los elementos del conjunto «nunca degradable» de ADR-023, tal y como los
 * enumera la tabla del ADR. Se escriben como las claves que alguien intentaria
 * usar si quisiera apagarlos.
 *
 * @return list<string>
 */
function neverDegradableNames(): array
{
    return [
        'clock_in',
        'clocking',
        'scan',
        'pin_clocking',
        'offline_queue_sync',
        'workday_lookup',
        'shift_entries',
        'employee_portal',
        'legal_export',
        'inspection_export',
        'audit_log',
        'audit',
        'shift_corrections',
        'corrections',
        'backups',
        'restore',
        'health',
        'error_events',
    ];
}

it('no existe forma de nombrar el registro legal como funcionalidad apagable', function (string $name): void {
    // La primera mitad de ADR-023: «el primer conjunto no es licenciable, de
    // modo que no existe forma de expresar su desactivacion». Con un enum, eso
    // deja de ser una promesa y pasa a ser el sistema de tipos: una clave que
    // pusiera `features: ["clock_in"]` no encuentra caso y se descarta.
    expect(Feature::tryFrom($name))->toBeNull(
        'ADR-023 declara «'.$name.'» registro legal y NO licenciable, pero existe un caso de Feature '
        .'con ese valor. Eso permitiria expresar su desactivacion en una clave de licencia.'
    );
})->with(neverDegradableNames())->group('RF-PD-05');

it('el catalogo de funcionalidades apagables es exactamente el de ADR-023', function (): void {
    // Si alguien añade un caso, tiene que decidir a que lado de la frontera cae
    // — que es lo que ADR-023 exige de toda tarea de la Fase 3 en adelante— y
    // esta lista se lo recuerda. La lista es CONTRACTUAL antes que tecnica: es
    // lo que se le puede decir a un cliente que perdera.
    expect(array_map(static fn (Feature $f): string => $f->value, Feature::cases()))->toBe([
        'advanced_reports',
        'impact_dashboard',
        'payroll_export',
        'weekly_email_summary',
        'realtime_presence',
        'white_label',
        'telemetry',
    ]);
})->group('RF-PD-05');

it('con la licencia caducada, ausente o ilegible, lo degradado es SOLO lo accesorio', function (LicenseStatus $status): void {
    // La segunda mitad: lo que se degrada es un subconjunto de `Feature`, y
    // `Feature` no contiene nada legal. Dicho de otro modo: por construccion, la
    // lista de degradados no puede contener el fichaje ni la exportacion legal.
    foreach ($status->degradedFeatures() as $feature) {
        expect($feature)->toBeInstanceOf(Feature::class)
            ->and(\in_array($feature, Feature::cases(), true))->toBeTrue();
    }

    expect($status->degradedFeatures())->not->toBeEmpty();
})->with([
    'caducada' => fn () => LicenseStatus::of(
        featureBoundaryLicense(),
        FixedClock::at('2027-06-01T10:00:00')->now(),
        30,
    ),
    'ausente' => fn () => LicenseStatus::absent(FixedClock::at('2026-06-01T10:00:00')->now(), 30),
    'ilegible' => fn () => LicenseStatus::unverifiable(
        LicenseRejection::BadSignature,
        FixedClock::at('2026-06-01T10:00:00')->now(),
        30,
    ),
])->group('RF-PD-05');

it('el punto unico de decision solo acepta funcionalidades accesorias', function (): void {
    // La firma del puerto es la garantia. Si algun dia alguien añadiera un
    // `isEnabled(string $name)`, esta prueba lo dice: bastaria esa cadena para
    // preguntar si el fichaje esta habilitado, y la pregunta no debe poder
    // formularse.
    $method = new ReflectionMethod(FeatureGate::class, 'isEnabled');
    $parameter = $method->getParameters()[0];

    expect((string) $parameter->getType())->toBe(Feature::class);

    $statusOf = new ReflectionMethod(FeatureGate::class, 'statusOf');

    expect((string) $statusOf->getParameters()[0]->getType())->toBe(Feature::class)
        // Y ningun metodo mas: el puerto no expone el estado de la licencia ni
        // nada que se parezca a «¿cabe un empleado mas?» (ADR-028).
        ->and(array_map(
            static fn (ReflectionMethod $m): string => $m->getName(),
            (new ReflectionClass(FeatureGate::class))->getMethods(),
        ))->toBe(['isEnabled', 'statusOf']);
})->group('RF-PD-05');

it('ningun estado de licencia puede expresar «registro apagado»', function (LicenseState $state): void {
    // El enum de estados tiene seis casos y ninguno significa «detenido». Es la
    // regla dura 15 escrita en el tipo: no hay un `LicenseState::Blocked` al que
    // alguien pueda hacer `match`.
    expect($state->value)->not->toContain('blocked')
        ->and($state->value)->not->toContain('locked')
        ->and($state->value)->not->toContain('disabled')
        ->and($state->value)->not->toContain('suspended');
})->with(LicenseState::cases())->group('RF-PD-05');

/**
 * Una licencia con vigencia terminada, para los casos de arriba.
 */
function featureBoundaryLicense(): License
{
    return License::fromClaims([
        'license_id' => 'lic-boundary',
        'customer_name' => 'Hotel de Pruebas, S.L.',
        'plan' => 'estandar',
        'max_employees' => 50,
        'max_devices' => 3,
        'features' => ['advanced_reports', 'realtime_presence'],
        'valid_from' => '2026-01-01T00:00:00Z',
        'valid_until' => '2026-12-31T23:59:59Z',
        'issued_at' => '2025-12-15T10:00:00Z',
    ]);
}
