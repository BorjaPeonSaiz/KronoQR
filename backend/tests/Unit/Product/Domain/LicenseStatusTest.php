<?php

declare(strict_types=1);

use App\Modules\Product\Domain\ValueObject\License;
use App\Modules\Product\Domain\ValueObject\LicenseNoticeSeverity;
use App\Modules\Product\Domain\ValueObject\LicenseRejection;
use App\Modules\Product\Domain\ValueObject\LicenseState;
use App\Modules\Product\Domain\ValueObject\LicenseStatus;
use App\Modules\Shared\Domain\ValueObject\Feature;
use App\Modules\Shared\Domain\ValueObject\FeatureRestriction;
use Tests\Support\Time\FixedClock;

/*
 * El estado de la licencia, calculado con el instante INYECTADO (regla dura 2).
 *
 * Aqui no hay base de datos, ni firma, ni HTTP: solo fechas. Es donde se puede
 * probar el dia exacto de la caducidad, el siguiente y el ultimo segundo del
 * ultimo dia sin tocar el reloj de la maquina, que es justo lo que alguien
 * discutira algun dia — la caducidad de una licencia acaba en una factura.
 */

/**
 * @param  array<string, mixed>  $claims
 */
function licenseWith(array $claims = []): License
{
    return License::fromClaims([
        'license_id' => 'test-1',
        'customer_name' => 'Hotel de Pruebas, S.L.',
        'plan' => 'estandar',
        'max_employees' => 50,
        'max_devices' => 3,
        'features' => ['advanced_reports', 'realtime_presence'],
        'valid_from' => '2026-01-01T00:00:00Z',
        'valid_until' => '2026-12-31T23:59:59Z',
        'issued_at' => '2025-12-15T10:00:00Z',
        ...$claims,
    ]);
}

function statusAt(string $wallClock, ?License $license = null, int $warningDays = 30): LicenseStatus
{
    return LicenseStatus::of(
        $license ?? licenseWith(),
        FixedClock::at($wallClock)->now(),
        $warningDays,
    );
}

it('decide el estado en los limites exactos de la vigencia', function (string $now, LicenseState $expected): void {
    expect(statusAt($now)->state)->toBe($expected);
})->with([
    // El segundo anterior al inicio: todavia no vige.
    'un segundo antes de empezar' => ['2025-12-31T23:59:59', LicenseState::NotYetValid],
    // El primer instante de vigencia. Vale.
    'el primer segundo de vigencia' => ['2026-01-01T00:00:00', LicenseState::Valid],
    'a mitad de vigencia' => ['2026-06-15T12:00:00', LicenseState::Valid],
    // El dia exacto de la caducidad vale ENTERO: `valid_until` es 23:59:59.
    'el ultimo dia por la mañana' => ['2026-12-31T09:00:00', LicenseState::ExpiringSoon],
    'el ultimo segundo de vigencia' => ['2026-12-31T23:59:59', LicenseState::ExpiringSoon],
    // Y el siguiente ya no.
    'el segundo siguiente a la caducidad' => ['2027-01-01T00:00:00', LicenseState::Expired],
    'el dia siguiente' => ['2027-01-01T09:00:00', LicenseState::Expired],
])->group('RF-PD-04');

it('avisa con la antelacion configurada, y ni un dia antes', function (string $now, LicenseState $expected): void {
    // 30 dias de serie. El aviso empieza cuando quedan 30 dias COMPLETOS, no 31.
    expect(statusAt($now)->state)->toBe($expected);
})->with([
    '32 dias antes: todavia nada' => ['2026-11-29T23:59:59', LicenseState::Valid],
    '31 dias antes: todavia nada' => ['2026-11-30T23:00:00', LicenseState::Valid],
    '30 dias completos antes: ya avisa' => ['2026-12-01T23:00:00', LicenseState::ExpiringSoon],
    '29 dias antes' => ['2026-12-02T23:00:00', LicenseState::ExpiringSoon],
])->group('RF-PD-04');

it('la ventana de aviso es configuracion y no una constante del dominio', function (): void {
    // Regla dura 13: el umbral llega resuelto desde `config/license.php`. Con la
    // ventana a cero, el aviso solo aparece el ultimo dia.
    expect(statusAt('2026-12-01T12:00:00', warningDays: 0)->state)->toBe(LicenseState::Valid)
        ->and(statusAt('2026-12-31T12:00:00', warningDays: 0)->state)->toBe(LicenseState::ExpiringSoon)
        // Y con una ventana larga, el aviso aparece mucho antes.
        ->and(statusAt('2026-06-15T12:00:00', warningDays: 300)->state)->toBe(LicenseState::ExpiringSoon);
})->group('RF-PD-04');

it('cuenta los dias que faltan y los que han pasado, nunca en negativo', function (): void {
    $before = statusAt('2026-12-01T00:00:00');
    $after = statusAt('2027-01-13T00:00:00');

    expect($before->daysUntilExpiry())->toBe(30)
        ->and($before->daysSinceExpiry())->toBeNull()
        // Pasada la caducidad, «faltan» cero dias y no menos veinte: un entero
        // con signo produce inevitablemente un «caduca en -20 dias» en pantalla.
        ->and($after->daysUntilExpiry())->toBe(0)
        ->and($after->daysSinceExpiry())->toBe(12);
})->group('RF-PD-04');

it('no da cifras de vigencia cuando no hay licencia verificada', function (): void {
    $absent = LicenseStatus::absent(FixedClock::at('2026-06-15T12:00:00')->now(), 30);
    $broken = LicenseStatus::unverifiable(
        LicenseRejection::BadSignature,
        FixedClock::at('2026-06-15T12:00:00')->now(),
        30,
    );

    expect($absent->daysUntilExpiry())->toBeNull()
        ->and($absent->daysSinceExpiry())->toBeNull()
        ->and($absent->degradedSince())->toBeNull()
        ->and($broken->daysUntilExpiry())->toBeNull()
        // No se inventa un dia desde el que degradar: el aviso dice otra cosa.
        ->and($broken->degradedSince())->toBeNull()
        ->and($broken->rejection)->toBe(LicenseRejection::BadSignature);
})->group('RF-PD-04');

it('concede lo contratado mientras la licencia vige, incluso en la ventana de aviso', function (string $now): void {
    // `ExpiringSoon` NO degrada nada: a una licencia con veintinueve dias por
    // delante recortarle algo seria adelantar la caducidad un mes.
    $status = statusAt($now);

    expect($status->allows(Feature::AdvancedReports))->toBeTrue()
        ->and($status->allows(Feature::RealtimePresence))->toBeTrue()
        ->and($status->degradedFeatures())->not->toContain(Feature::AdvancedReports);
})->with([
    'vigente' => ['2026-06-15T12:00:00'],
    'a punto de caducar' => ['2026-12-20T12:00:00'],
])->group('RF-PD-04', 'RF-PD-05');

it('niega lo accesorio al caducar, con el motivo y la fecha', function (): void {
    $status = statusAt('2027-02-01T12:00:00');
    $availability = $status->availabilityOf(Feature::AdvancedReports);

    expect($availability->enabled)->toBeFalse()
        ->and($availability->restriction)->toBe(FeatureRestriction::LicenseExpired)
        // La fecha desde la que esta degradado, que es lo que hace honesto el
        // aviso (ADR-019): «desde el 31 de diciembre», no «no disponible».
        ->and($availability->since?->format('Y-m-d\TH:i:s'))->toBe('2026-12-31T23:59:59');
})->group('RF-PD-05');

it('distingue «no contratado» de «caducado», porque la accion es distinta', function (): void {
    // Renovar no arregla lo que nunca se compro, y decirle a un cliente que
    // renueve cuando lo que necesita es ampliar el plan es una llamada perdida.
    $status = statusAt('2026-06-15T12:00:00', licenseWith(['features' => ['advanced_reports']]));

    expect($status->availabilityOf(Feature::RealtimePresence)->restriction)
        ->toBe(FeatureRestriction::NotInPlan)
        ->and($status->availabilityOf(Feature::RealtimePresence)->since)->toBeNull()
        ->and($status->availabilityOf(Feature::AdvancedReports)->enabled)->toBeTrue();
})->group('RF-PD-05');

it('gobierna el aviso del panel y su tono', function (string $now, bool $notice, LicenseNoticeSeverity $severity): void {
    $status = statusAt($now);

    expect($status->state->needsNotice())->toBe($notice)
        ->and($status->state->severity())->toBe($severity);
})->with([
    'vigente: sin banner' => ['2026-06-15T12:00:00', false, LicenseNoticeSeverity::None],
    'caduca pronto: aviso' => ['2026-12-20T12:00:00', true, LicenseNoticeSeverity::Warning],
    'caducada: critico' => ['2027-02-01T12:00:00', true, LicenseNoticeSeverity::Critical],
    'aun no vigente: aviso' => ['2025-06-15T12:00:00', true, LicenseNoticeSeverity::Warning],
])->group('RF-PD-04');

it('con la vigencia sin empezar, dice desde cuando estara disponible', function (): void {
    $status = statusAt('2025-06-15T12:00:00');
    $availability = $status->availabilityOf(Feature::AdvancedReports);

    expect($status->state)->toBe(LicenseState::NotYetValid)
        ->and($availability->restriction)->toBe(FeatureRestriction::LicenseNotYetValid)
        ->and($availability->since?->format('Y-m-d'))->toBe('2026-01-01');
})->group('RF-PD-04');

it('enumera lo degradado en orden de catalogo', function (): void {
    // El orden no depende de como venga la lista en la clave: lo que se enseña
    // en el panel y lo que imprime `license:show` tienen que salir igual.
    $status = statusAt('2027-02-01T12:00:00');

    expect($status->degradedFeatures())->toBe(Feature::cases());
})->group('RF-PD-05');

it('devuelve lo degradado como lista, sin huecos, cuando solo falta parte', function (): void {
    // El caso que de verdad se da: licencia VIGENTE con un plan parcial. Lo
    // contratado se filtra y lo que queda tiene que seguir siendo una lista
    // —indices 0, 1, 2…— porque viaja a un JSON: con huecos, `degraded_features`
    // saldria como objeto y el panel no podria recorrerlo.
    $status = statusAt('2026-06-15T12:00:00', licenseWith(['features' => ['advanced_reports']]));

    $degraded = $status->degradedFeatures();

    expect($degraded)->not->toContain(Feature::AdvancedReports)
        ->and($degraded)->toContain(Feature::RealtimePresence)
        ->and(array_keys($degraded))->toBe(range(0, \count($degraded) - 1))
        ->and(json_encode($degraded))->toStartWith('[');
})->group('RF-PD-05');
