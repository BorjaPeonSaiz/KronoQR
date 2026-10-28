<?php

declare(strict_types=1);

use App\Modules\Attendance\Application\Port\WorkDayRepository;
use App\Modules\Attendance\Domain\Event\DailyTotalsRecalculated;
use App\Modules\Attendance\Domain\Model\WorkDay;
use App\Modules\Attendance\Domain\ValueObject\ScanOrigin;
use App\Modules\Attendance\Domain\ValueObject\WorkDate;
use App\Modules\Attendance\Infrastructure\Projection\DailyTotalsProjector;
use App\Modules\Shared\Domain\ValueObject\UserRole;
use App\Modules\Workforce\Infrastructure\Persistence\Department;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spectator\Spectator;
use Tests\Support\Attendance\AttendanceFixtures;
use Tests\Support\Database\RefreshDatabase;
use Tests\Support\Http\Api;
use Tests\Support\Identity\ManagementUsers;
use Tests\Support\Time\FrozenTime;
use Tests\Support\Time\Instants;
use Tests\Support\Workforce\WorkforceFixtures;

/*
 * Escenario «Corrección manual trazada» del doc 01 §11 con el actor que dice
 * el escenario: **el responsable** del departamento de Ana, no RRHH (R3-QA-09
 * de la re-verificacion 2.2.0). El doc 01 §2 promete que el responsable
 * «corrige fichajes de su ámbito»; aqui se prueba en positivo, y que fuera de
 * su ambito no alcanza.
 *
 * Ana entra a las 06:00 UTC del sabado 14 de marzo de 2026 y olvida salir; el
 * responsable cierra el tramo a las 15:00 UTC: 540 minutos.
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Spectator::using('openapi.yaml');
    config()->set('identity.two_factor.required_roles', []);
    FrozenTime::at('2026-03-15 09:00:00');
});

/**
 * Hotel con Cocina, que dirige el responsable, y Recepcion, que no; Ana en
 * Cocina con un tramo abierto y Lucia en Recepcion con otro.
 *
 * @return array{token: string, responsable_id: int, ana_open: string, lucia_open: string}
 */
function correccionDelResponsableEscenario(): array
{
    $site = WorkforceFixtures::site('Hotel de correcciones por departamento');
    $responsable = ManagementUsers::withRole(UserRole::RESPONSABLE_DEPARTAMENTO);
    $cocina = WorkforceFixtures::department($site, 'Cocina');
    Department::query()->whereKey($cocina)->update(['manager_user_id' => $responsable->id]);
    $recepcion = WorkforceFixtures::department($site, 'Recepcion');

    $ana = WorkforceFixtures::employee($site, $cocina, 'active', 'Ana', 'Lopez');
    $lucia = WorkforceFixtures::employee($site, $recepcion, 'active', 'Lucia', 'Ferrer');

    return [
        'token' => ManagementUsers::tokenFor($responsable),
        'responsable_id' => $responsable->id,
        'ana_open' => correccionDelResponsableTramoAbierto($site, $ana),
        'lucia_open' => correccionDelResponsableTramoAbierto($site, $lucia),
    ];
}

/** Un tramo abierto desde las 06:00 UTC del 14 de marzo, escrito con el agregado. */
function correccionDelResponsableTramoAbierto(int $site, string $employee): string
{
    $jornada = WorkDay::start($employee, $site, WorkDate::fromIsoDate('2026-03-14', Instants::madrid()));
    $entrada = $jornada->clockIn(Str::uuid7()->toString(), Instants::utc('2026-03-14 06:00'), ScanOrigin::QR_KIOSK);
    app(WorkDayRepository::class)->save($jornada);

    foreach ($jornada->releaseEvents() as $evento) {
        if ($evento instanceof DailyTotalsRecalculated) {
            app(DailyTotalsProjector::class)->handle($evento);
        }
    }

    return $entrada->uuid();
}

it('deja al responsable cerrar a las 15:00 el tramo abierto de una persona de su departamento', function (): void {
    $escenario = correccionDelResponsableEscenario();

    Api::as($escenario['token'])
        ->patch('/api/v1/shift-entries/'.$escenario['ana_open'], [
            'clocked_out_at' => '2026-03-14T15:00:00Z',
            'reason_code' => 'OLVIDO_FICHAJE_SALIDA',
        ])
        ->assertValidRequest()
        ->assertValidResponse(200)
        ->assertJsonPath('action', 'closed')
        ->assertJsonPath('status', 'closed')
        ->assertJsonPath('clocked_out_at', '2026-03-14T15:00:00.000000Z')
        ->assertJsonPath('superseded_shift_entry_uuid', $escenario['ana_open'])
        ->assertJsonPath('daily_total_minutes', 540);
})->group('RN-13', 'RF-PA-04', 'RF-ID-03');

it('deja la correccion del responsable con el valor anterior, su autoria y el motivo', function (): void {
    $escenario = correccionDelResponsableEscenario();

    Api::as($escenario['token'])->patch('/api/v1/shift-entries/'.$escenario['ana_open'], [
        'clocked_out_at' => '2026-03-14T15:00:00Z',
        'reason_code' => 'OLVIDO_FICHAJE_SALIDA',
    ])->assertStatus(200);

    /** @var object{performed_by_user_id: int, reason_code: string, before: string} $correccion */
    $correccion = DB::table('shift_corrections')->sole();
    /** @var array{clocked_in_at: string, clocked_out_at: string|null} $antes */
    $antes = json_decode($correccion->before, true, 512, JSON_THROW_ON_ERROR);

    expect($correccion->performed_by_user_id)->toBe($escenario['responsable_id'])
        ->and($correccion->reason_code)->toBe('OLVIDO_FICHAJE_SALIDA')
        ->and($antes['clocked_out_at'])->toBeNull()
        ->and(new DateTimeImmutable($antes['clocked_in_at']))->toEqual(new DateTimeImmutable('2026-03-14T06:00:00Z'));
})->group('RN-13', 'RL-04', 'RF-PA-04');

it('conserva consultable el tramo original y recalcula el total sin acumular', function (): void {
    $escenario = correccionDelResponsableEscenario();

    Api::as($escenario['token'])->patch('/api/v1/shift-entries/'.$escenario['ana_open'], [
        'clocked_out_at' => '2026-03-14T15:00:00Z',
        'reason_code' => 'OLVIDO_FICHAJE_SALIDA',
    ])->assertStatus(200);

    /** @var object{status: string, clocked_out_at: string|null, version: int} $original */
    $original = DB::table('shift_entries')->where('uuid', $escenario['ana_open'])->sole();

    expect($original->status)->toBe('superseded')
        ->and($original->clocked_out_at)->toBeNull()
        ->and($original->version)->toBe(1)
        ->and(AttendanceFixtures::projectionDivergences())->toBe([]);
})->group('RN-13', 'RN-06', 'RF-PA-04');

it('no deja al responsable corregir a una persona de un departamento que no dirige', function (): void {
    $escenario = correccionDelResponsableEscenario();

    Api::as($escenario['token'])->patch('/api/v1/shift-entries/'.$escenario['lucia_open'], [
        'clocked_out_at' => '2026-03-14T15:00:00Z',
        'reason_code' => 'OLVIDO_FICHAJE_SALIDA',
    ])->assertStatus(403);

    expect(DB::table('shift_corrections')->count())->toBe(0)
        ->and(DB::table('shift_entries')->where('uuid', $escenario['lucia_open'])->value('status'))->toBe('open');
})->group('RF-ID-03', 'RF-PA-04', 'RS-05');
