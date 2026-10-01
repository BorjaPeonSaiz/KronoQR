<?php

declare(strict_types=1);

use App\Modules\Identity\Application\Command\IssueDeviceTokenCommand;
use App\Modules\Identity\Application\UseCase\IssueDeviceToken;
use App\Modules\Identity\Domain\ValueObject\IssuedAccessToken;
use App\Modules\Identity\Infrastructure\Persistence\Device;
use App\Modules\Identity\Infrastructure\Persistence\User;
use App\Modules\Product\Application\UseCase\GenerateDiagnosticsBundleHandler;
use App\Modules\Product\Domain\ValueObject\DiagnosticsActor;
use App\Modules\Product\Domain\ValueObject\DiagnosticsOptions;
use App\Modules\Product\Infrastructure\Diagnostics\Collector\KioskCollector;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\Attendance\AttendanceFixtures;
use Tests\Support\Database\RefreshDatabase;
use Tests\Support\Product\ErrorHistoryConnection;
use Tests\Support\Product\LicenseKeys;
use Tests\Support\Time\FrozenTime;
use Tests\Support\Workforce\WorkforceFixtures;

/*
 * Lo que el paquete de diagnostico lleva desde PR13 y PR14, contra la base de
 * datos de verdad (RF-PD-09, ADR-020):
 *
 * - de cada quiosco, la caducidad de su token, cuando se emparejo, el fichaje
 *   pendiente mas antiguo y la bateria del ultimo latido;
 * - de la configuracion, los ajustes guardados que pasan la lista de permitidos;
 * - de la instalacion, cuatro recuentos de volumen.
 *
 * Y, en los dos sentidos, que nada de eso abre una via para un nombre, un
 * codigo de empleado, un DNI o un uuid de persona.
 */

uses(RefreshDatabase::class);

const DIAG_KIOSK_NOMBRE_SEMBRADO = 'Prudencia Villalobos Arteaga';

const DIAG_KIOSK_CODIGO_SEMBRADO = 'EMP-778812';

const DIAG_KIOSK_DNI_SEMBRADO = '71234567L';

beforeEach(function (): void {
    FrozenTime::at('2026-09-30 09:00:00');
    LicenseKeys::grantAll();
    ErrorHistoryConnection::shareTestTransaction();
});

afterEach(function (): void {
    ErrorHistoryConnection::release();
});

/** @return array<string, mixed> */
function diagKioskPaquete(DiagnosticsOptions $options): array
{
    return app(GenerateDiagnosticsBundleHandler::class)->handle($options, DiagnosticsActor::User)->toArray();
}

/**
 * @param  array<string, mixed>  $bundle
 * @return array<string, mixed>
 */
function diagKioskDe(array $bundle, string $uuid): array
{
    /** @var list<array<string, mixed>> $kiosks */
    $kiosks = $bundle['kiosks'];

    foreach ($kiosks as $kiosk) {
        if ($kiosk['uuid'] === $uuid) {
            return $kiosk;
        }
    }

    throw new RuntimeException('El paquete no trae el quiosco '.$uuid);
}

it('lleva la caducidad del token del emisor real, el emparejamiento, el pendiente mas antiguo y la bateria', function (): void {
    $siteId = WorkforceFixtures::site();
    $device = AttendanceFixtures::device($siteId, DIAG_KIOSK_NOMBRE_SEMBRADO);

    // Por el caso de uso real: si `SanctumDeviceTokenIssuer` cambia el nombre
    // con el que crea el token, la subconsulta del colector deja de casar y
    // esta prueba lo dice.
    $token = app(IssueDeviceToken::class)->handle(new IssueDeviceTokenCommand($device['uuid']));
    expect($token)->toBeInstanceOf(IssuedAccessToken::class);

    DB::table('devices')->where('id', $device['id'])->update([
        'paired_at' => '2026-07-02 10:00:00+00',
        'last_seen_at' => '2026-09-30 08:59:00+00',
        'pending_queue_size' => 37,
        'oldest_pending_at' => '2026-09-29 05:58:31+00',
        'battery_level' => 14,
        'battery_charging' => false,
        'app_version' => '2.2.0',
    ]);

    /** @var string $expiresAt */
    $expiresAt = DB::table('personal_access_tokens')
        ->where('tokenable_id', $device['id'])
        ->where('name', 'kiosk:'.$device['uuid'])
        ->value('expires_at');

    $kiosk = diagKioskDe(diagKioskPaquete(DiagnosticsOptions::anonymized()), $device['uuid']);

    expect(array_keys($kiosk))->toBe(KioskCollector::FIELDS)
        ->and($kiosk['paired_at'])->toBe('2026-07-02T10:00:00.000000Z')
        ->and($kiosk['oldest_pending_at'])->toBe('2026-09-29T05:58:31.000000Z')
        ->and($kiosk['pending_queue_size'])->toBe(37)
        ->and($kiosk['battery_level'])->toBe(14)
        ->and($kiosk['battery_charging'])->toBeFalse()
        ->and($kiosk['app_version'])->toBe('2.2.0')
        // Solo el dia, y el del token que se acaba de emitir.
        ->and($kiosk['token_expires_on'])->toBe((new DateTimeImmutable($expiresAt))
        ->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d'))
        ->and($kiosk['token_expires_on'])->toMatch('/^\d{4}-\d{2}-\d{2}$/');
})->group('RF-PD-09');

it('con dos tokens en solape da la caducidad del mas tardio, y no confunde el token de una cuenta con el del quiosco', function (): void {
    $siteId = WorkforceFixtures::site();
    $device = AttendanceFixtures::device($siteId);

    DB::table('personal_access_tokens')->insert([
        [
            // El relevado tras una rotacion (ADR-044), con la caducidad adelantada.
            'tokenable_type' => Device::class,
            'tokenable_id' => $device['id'],
            'name' => 'kiosk:'.$device['uuid'],
            'token' => hash('sha256', 'viejo'),
            'abilities' => '[]',
            'expires_at' => '2026-10-01 09:00:00+00',
            'created_at' => '2026-07-02 10:00:00+00',
            'updated_at' => '2026-07-02 10:00:00+00',
        ],
        [
            'tokenable_type' => Device::class,
            'tokenable_id' => $device['id'],
            'name' => 'kiosk:'.$device['uuid'],
            'token' => hash('sha256', 'nuevo'),
            'abilities' => '[]',
            'expires_at' => '2026-12-29 09:00:00+00',
            'created_at' => '2026-09-30 08:00:00+00',
            'updated_at' => '2026-09-30 08:00:00+00',
        ],
        [
            // Una sesion de gestion con el MISMO id numerico y una caducidad
            // posterior: si el colector casara solo por `tokenable_id`, la daria.
            'tokenable_type' => User::class,
            'tokenable_id' => $device['id'],
            'name' => 'admin-session',
            'token' => hash('sha256', 'cuenta'),
            'abilities' => '[]',
            'expires_at' => '2027-06-01 09:00:00+00',
            'created_at' => '2026-09-30 08:00:00+00',
            'updated_at' => '2026-09-30 08:00:00+00',
        ],
    ]);

    $kiosk = diagKioskDe(diagKioskPaquete(DiagnosticsOptions::anonymized()), $device['uuid']);

    expect($kiosk['token_expires_on'])->toBe('2026-12-29');
})->group('RF-PD-09');

it('una tablet sin token ni latido sale con null en cada campo nuevo, no sin la clave', function (): void {
    $siteId = WorkforceFixtures::site();
    $device = AttendanceFixtures::device($siteId);

    $kiosk = diagKioskDe(diagKioskPaquete(DiagnosticsOptions::anonymized()), $device['uuid']);

    expect(array_keys($kiosk))->toBe(KioskCollector::FIELDS)
        ->and($kiosk['token_expires_on'])->toBeNull()
        ->and($kiosk['paired_at'])->toBeNull()
        ->and($kiosk['oldest_pending_at'])->toBeNull()
        ->and($kiosk['battery_level'])->toBeNull()
        ->and($kiosk['battery_charging'])->toBeNull();
})->group('RF-PD-09');

it('cuenta empleados activos, fichajes y tramos de 30 dias e incidencias abiertas, y solo eso', function (): void {
    $siteId = WorkforceFixtures::site();
    $departmentId = WorkforceFixtures::department($siteId);
    $device = AttendanceFixtures::device($siteId);

    $activos = [
        WorkforceFixtures::employee($siteId, $departmentId),
        WorkforceFixtures::employee($siteId, $departmentId),
        WorkforceFixtures::employee($siteId, $departmentId),
    ];
    WorkforceFixtures::employee($siteId, $departmentId, 'suspended');
    WorkforceFixtures::employee($siteId, $departmentId, 'terminated');

    $employeeId = AttendanceFixtures::employeeIdOf($activos[0]);

    // Escaneos: dos dentro de la ventana y uno de hace 45 dias.
    foreach (['2026-09-29 07:00:00+00', '2026-09-10 07:00:00+00', '2026-08-16 07:00:00+00'] as $occurredAt) {
        DB::table('scan_events')->insert([
            'scan_id' => Str::uuid7()->toString(),
            'device_id' => $device['id'],
            'employee_id' => $employeeId,
            'occurred_at' => $occurredAt,
            'recorded_at' => $occurredAt,
            'origin' => 'qr_kiosk',
            'intent' => 'auto',
            'result' => 'clock_in',
            'worked_minutes' => 0,
        ]);
    }

    // Tramos: dos en vigor en la ventana, uno anulado en la ventana (version
    // conservada, no horas) y uno antiguo.
    foreach ([
        ['2026-09-29', 'closed'],
        ['2026-09-15', 'closed'],
        ['2026-09-14', 'voided'],
        ['2026-08-01', 'closed'],
    ] as [$workDate, $status]) {
        DB::table('shift_entries')->insert([
            'uuid' => Str::uuid7()->toString(),
            'employee_id' => $employeeId,
            'site_id' => $siteId,
            'work_date' => $workDate,
            'clocked_in_at' => $workDate.' 07:00:00+00',
            'clocked_out_at' => $workDate.' 15:00:00+00',
            'duration_minutes' => 480,
            'status' => $status,
            'clock_in_source' => 'qr_kiosk',
            'clock_out_source' => 'qr_kiosk',
            'version' => 1,
            'created_at' => $workDate.' 15:00:00+00',
            'updated_at' => $workDate.' 15:00:00+00',
        ]);
    }

    // Incidencias: dos abiertas y una resuelta.
    foreach ([['2026-09-29', 'open'], ['2026-09-28', 'open'], ['2026-09-27', 'resolved']] as [$workDate, $status]) {
        DB::table('incidents')->insert([
            'employee_id' => $employeeId,
            'work_date' => $workDate,
            'type' => 'long_shift',
            'severity' => 'medium',
            'status' => $status,
            'detected_at' => $workDate.' 20:00:00+00',
            'resolved_at' => $status === 'open' ? null : $workDate.' 21:00:00+00',
            'context' => json_encode([]),
            'created_at' => $workDate.' 20:00:00+00',
            'updated_at' => $workDate.' 20:00:00+00',
        ]);
    }

    $bundle = diagKioskPaquete(DiagnosticsOptions::anonymized());

    /** @var array<string, mixed> $installation */
    $installation = $bundle['installation'];

    expect($installation['volume'])->toBe([
        'active_employees' => 3,
        'scan_events_last_30_days' => 2,
        'shift_entries_last_30_days' => 2,
        'open_incidents' => 2,
    ]);

    // Solo numeros: ningun uuid de persona sale por esta via.
    $json = (string) json_encode($installation);

    foreach ($activos as $uuid) {
        expect($json)->not->toContain($uuid);
    }
})->group('RF-PD-09', 'RL-19');

it('lleva los ajustes guardados con su procedencia, sin rotulos de nomina, sin marca y sin codigo de servicio', function (): void {
    WorkforceFixtures::site();

    foreach ([
        'ATTENDANCE_FUTURE_TOLERANCE_MINUTES' => 10,
        'PAYROLL_EXPORT_COLUMNS' => ['employee_code=Codigo de '.DIAG_KIOSK_NOMBRE_SEMBRADO, 'worked_hours'],
        'PAYROLL_EXPORT_ENCODING' => 'latin1',
        'BRANDING_APP_NAME' => 'Hotel Miramar Fichajes',
        'KIOSK_SERVICE_CODE' => '73519046',
    ] as $key => $value) {
        DB::table('installation_settings')->insert([
            'key' => $key,
            'value' => json_encode($value, JSON_THROW_ON_ERROR),
            'updated_at' => '2026-09-01 00:00:00+00',
        ]);
    }

    $bundle = diagKioskPaquete(DiagnosticsOptions::anonymized());

    /** @var array{installation_settings: array<string, array{value: mixed, source: string}>} $configuration */
    $configuration = $bundle['configuration'];
    $settings = $configuration['installation_settings'];
    $json = (string) json_encode($bundle);

    expect($settings['ATTENDANCE_FUTURE_TOLERANCE_MINUTES'])->toBe(['value' => 10, 'source' => 'stored'])
        ->and($settings['PAYROLL_EXPORT_ENCODING'])->toBe(['value' => 'latin1', 'source' => 'stored'])
        ->and($settings['PAYROLL_EXPORT_COLUMNS'])->toBe(['value' => ['employee_code', 'worked_hours'], 'source' => 'stored'])
        ->and($settings['ATTENDANCE_MAX_SHIFT_HOURS'])->toBe(['value' => 12, 'source' => 'default'])
        ->and($json)->not->toContain(DIAG_KIOSK_NOMBRE_SEMBRADO)
        ->and($json)->not->toContain('Hotel Miramar')
        ->and($json)->not->toContain('73519046');
})->group('RF-PD-09', 'RS-08');

it('ni el paquete anonimizado ni el completo sacan por las secciones nuevas un nombre, un codigo, un DNI o un uuid de empleado', function (): void {
    $siteId = WorkforceFixtures::site();
    $departmentId = WorkforceFixtures::department($siteId);
    $device = AttendanceFixtures::device($siteId, DIAG_KIOSK_NOMBRE_SEMBRADO);

    $uuid = WorkforceFixtures::employee(
        $siteId,
        $departmentId,
        firstName: 'Prudencia',
        lastName: 'Villalobos Arteaga',
        employeeCode: DIAG_KIOSK_CODIGO_SEMBRADO,
    );
    DB::table('employees')->where('uuid', $uuid)->update(['national_id_hash' => DIAG_KIOSK_DNI_SEMBRADO]);

    app(IssueDeviceToken::class)->handle(new IssueDeviceTokenCommand($device['uuid']));
    DB::table('devices')->where('id', $device['id'])->update([
        'oldest_pending_at' => '2026-09-29 05:58:31+00',
        'battery_level' => 80,
        'battery_charging' => true,
    ]);

    DB::table('installation_settings')->insert([
        'key' => 'PAYROLL_EXPORT_COLUMNS',
        'value' => json_encode(['employee_code='.DIAG_KIOSK_CODIGO_SEMBRADO], JSON_THROW_ON_ERROR),
        'updated_at' => '2026-09-01 00:00:00+00',
    ]);

    $anonimizado = diagKioskPaquete(DiagnosticsOptions::anonymized());
    $completo = diagKioskPaquete(DiagnosticsOptions::withPersonalData(7));

    foreach (['anonimizado' => $anonimizado, 'completo' => $completo] as $tipo => $bundle) {
        // Las secciones que esta tarea toca: en NINGUNO de los dos paquetes
        // llevan datos de una persona. `personal_data`, en el completo, si los
        // lleva, y es otra seccion con su propia prueba.
        $nuevas = (string) json_encode([$bundle['kiosks'], $bundle['configuration'], $bundle['installation']]);

        expect($nuevas)->not->toContain('Prudencia', 'Paquete '.$tipo.': el nombre sale por una seccion nueva.')
            ->and($nuevas)->not->toContain('Villalobos')
            ->and($nuevas)->not->toContain(DIAG_KIOSK_CODIGO_SEMBRADO)
            ->and($nuevas)->not->toContain(DIAG_KIOSK_DNI_SEMBRADO)
            ->and($nuevas)->not->toContain($uuid);
    }

    // Y el anonimizado entero, no solo las secciones nuevas.
    $json = (string) json_encode($anonimizado);

    expect($json)->not->toContain('Prudencia')
        ->and($json)->not->toContain(DIAG_KIOSK_CODIGO_SEMBRADO)
        ->and($json)->not->toContain(DIAG_KIOSK_DNI_SEMBRADO)
        ->and($json)->not->toContain($uuid)
        ->and($anonimizado['manifest'])->toMatchArray(['anonymized' => true])
        ->and($completo['manifest'])->toMatchArray(['anonymized' => false]);
})->group('RF-PD-09', 'RL-19', 'RS-08');
