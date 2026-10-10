<?php

declare(strict_types=1);

use App\Modules\Product\Application\UseCase\RunDoctorHandler;
use App\Modules\Product\Domain\ValueObject\DoctorCheck;
use App\Modules\Product\Domain\ValueObject\DoctorReport;
use App\Modules\Product\Domain\ValueObject\DoctorStatus;
use App\Modules\Shared\Application\Port\KioskAppVersions;
use App\Modules\Shared\Domain\ValueObject\KioskAppVersionSurvey;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\Support\Attendance\AttendanceFixtures;
use Tests\Support\Database\RefreshDatabase;
use Tests\Support\Product\LicenseKeys;
use Tests\Support\Time\FrozenTime;
use Tests\Support\Workforce\WorkforceFixtures;

/*
 * La sonda `kiosk.app_version` de `product:doctor` (**RF-PD-13**, **RF-KI-07**;
 * bloque 1 de la 2.2.1).
 *
 * Lo que se afirma:
 *
 * - una tablet por detras de la version del servidor sale como **aviso**, con
 *   su `uuid` y la version que declara, y el arreglo dice que se pone al dia
 *   sola y como desregistrar el *service worker* SIN borrar los datos del sitio;
 * - **nunca** como fallo: `update.sh` aborta con el `2`, y justo despues de
 *   actualizar las tablets aun no se han puesto al dia;
 * - una tablet por delante (vuelta atras del servidor) solo se informa;
 * - un servidor de desarrollo no juzga a nadie;
 * - solo cuentan los quioscos activos con latido reciente;
 * - ni un nombre de quiosco en el informe, que viaja en el paquete de
 *   diagnostico (ADR-020, regla dura 21).
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    WorkforceFixtures::site();
    LicenseKeys::grantAll();
    FrozenTime::at('2026-06-15 09:00:00');
    config()->set('app.version', '2.2.1');
});

/**
 * Un quiosco con la version y el ultimo latido que se le digan.
 *
 * @param  string|null  $lastSeenAt  Instante UTC del ultimo latido, o `null` si nunca latio.
 */
function quioscoConVersion(string $name, ?string $appVersion, ?string $lastSeenAt = '2026-06-15 08:59:30+00', string $status = 'active'): string
{
    $device = AttendanceFixtures::device(WorkforceFixtures::site(), $name);

    DB::table('devices')->where('id', $device['id'])->update([
        'name' => $name,
        'status' => $status,
        'app_version' => $appVersion,
        'last_seen_at' => $lastSeenAt,
        'pending_queue_size' => 0,
    ]);

    return $device['uuid'];
}

function informeDeVersion(string $locale = 'es'): DoctorReport
{
    return resolve(RunDoctorHandler::class)->handle($locale);
}

/** La comprobacion `kiosk.app_version`; falla con el nombre si no aparece. */
function comprobacionDeVersion(DoctorReport $report): DoctorCheck
{
    foreach ($report->checks as $check) {
        if ($check->id === 'kiosk.app_version') {
            return $check;
        }
    }

    throw new RuntimeException('El informe no trae la comprobacion kiosk.app_version.');
}

it('avisa, sin fallar, de la tablet que sigue con una version anterior a la del servidor', function (): void {
    $desfasada = quioscoConVersion('Tablet de Maria', '2.2.0');
    $alDia = quioscoConVersion('Recepcion', '2.2.1');

    $report = informeDeVersion();
    $check = comprobacionDeVersion($report);

    expect($check->status)->toBe(DoctorStatus::Warning)
        ->and($check->summary)->toContain($desfasada.' (2.2.0)')
        ->and($check->summary)->toContain('2.2.1')
        ->and($check->summary)->not->toContain($alDia)
        ->and($check->details['minimum_app_version'] ?? null)->toBe('2.2.1')
        ->and($check->details['examined'] ?? null)->toBe(2)
        ->and($check->details['behind'] ?? null)->toBe([['device' => $desfasada, 'app_version' => '2.2.0']])
        ->and($check->details['ahead'] ?? null)->toBe([])
        // El arreglo: se pone al dia sola, y como desregistrar el service
        // worker SIN borrar los datos del sitio.
        ->and((string) $check->fix)->toContain('cola')
        ->and((string) $check->fix)->toContain('chrome://serviceworker-internals')
        ->and((string) $check->fix)->toContain('Unregister')
        ->and((string) $check->fix)->toContain('NO borres los datos del sitio')
        // Ni un nombre de quiosco: viaja en el paquete de diagnostico.
        ->and(json_encode($report->toArray(), JSON_THROW_ON_ERROR))->not->toContain('Tablet de Maria')
        ->and(json_encode($report->toArray(), JSON_THROW_ON_ERROR))->not->toContain('Recepcion');

    $en = comprobacionDeVersion(informeDeVersion('en'));

    expect($en->status)->toBe(DoctorStatus::Warning)
        ->and((string) $en->fix)->toContain('Do NOT clear the site data');
})->group('RF-PD-13', 'RF-KI-07');

it('no aborta la actualizacion por una tablet desfasada: product:doctor no sale con 2', function (): void {
    quioscoConVersion('Recepcion', '0.0.0');

    $code = Artisan::call('product:doctor');

    expect($code)->not->toBe(2)
        ->and(comprobacionDeVersion(informeDeVersion())->status)->toBe(DoctorStatus::Warning);
})->group('RF-PD-13', 'RF-KI-07');

it('da por correcta la flota al dia', function (): void {
    quioscoConVersion('Recepcion', '2.2.1');
    quioscoConVersion('Cocina', '2.2.1-dev');

    $check = comprobacionDeVersion(informeDeVersion());

    expect($check->status)->toBe(DoctorStatus::Ok)
        ->and($check->fix)->toBeNull()
        ->and($check->details['examined'] ?? null)->toBe(2)
        ->and($check->details['behind'] ?? null)->toBe([]);
})->group('RF-PD-13', 'RF-KI-07');

it('solo informa de la tablet por delante del servidor, sin pedir nada', function (): void {
    $porDelante = quioscoConVersion('Recepcion', '2.3.0');

    $check = comprobacionDeVersion(informeDeVersion());

    expect($check->status)->toBe(DoctorStatus::Ok)
        ->and($check->fix)->toBeNull()
        ->and($check->summary)->toContain($porDelante.' (2.3.0)')
        ->and($check->details['ahead'] ?? null)->toBe([['device' => $porDelante, 'app_version' => '2.3.0']]);
})->group('RF-PD-13', 'RF-KI-07');

it('no juzga a ninguna tablet con un servidor sin version publicada', function (string $servidor): void {
    config()->set('app.version', $servidor);
    quioscoConVersion('Recepcion', '0.0.0');

    $check = comprobacionDeVersion(informeDeVersion());

    expect($check->status)->toBe(DoctorStatus::Ok)
        ->and($check->fix)->toBeNull()
        ->and($check->details)->toBe(['enforced' => false]);
})->with([
    'desarrollo' => ['2.2.1-dev'],
    'desconocida' => ['0.0.0'],
])->group('RF-PD-13', 'RF-KI-07');

it('solo cuenta los quioscos activos con latido reciente', function (): void {
    // Callado (mas alla de los 600 s de silencio), revocado y sin latido nunca:
    // de los tres ya avisan `kiosk:health` y el panel, y su version declarada
    // puede ser de hace semanas.
    quioscoConVersion('Almacen', '2.2.0', lastSeenAt: '2026-06-15 08:40:00+00');
    quioscoConVersion('Antigua', '2.2.0', status: 'revoked');
    quioscoConVersion('Nueva', null, lastSeenAt: null);

    $check = comprobacionDeVersion(informeDeVersion());

    expect($check->status)->toBe(DoctorStatus::Ok)
        ->and($check->details['examined'] ?? null)->toBe(0);
})->group('RF-PD-13', 'RF-KI-07');

it('cuenta como desfasada la tablet que no declara version', function (): void {
    $sinVersion = quioscoConVersion('Recepcion', null);

    $check = comprobacionDeVersion(informeDeVersion());

    expect($check->status)->toBe(DoctorStatus::Warning)
        ->and($check->summary)->toContain($sinVersion.' (-)')
        ->and($check->details['behind'] ?? null)->toBe([['device' => $sinVersion, 'app_version' => null]]);
})->group('RF-PD-13', 'RF-KI-07');

it('avisa sin reventar si no puede leer los quioscos', function (): void {
    app()->instance(KioskAppVersions::class, new class implements KioskAppVersions
    {
        #[Override]
        public function survey(): KioskAppVersionSurvey
        {
            throw new RuntimeException('base de datos caida');
        }
    });

    $check = comprobacionDeVersion(informeDeVersion());

    expect($check->status)->toBe(DoctorStatus::Warning)
        ->and($check->details)->toBe(['failure' => RuntimeException::class])
        ->and($check->summary)->not->toContain('base de datos caida');
})->group('RF-PD-13', 'RF-KI-07');
