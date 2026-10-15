<?php

declare(strict_types=1);

use App\Modules\Attendance\Application\Command\AddShiftEntryCommand;
use App\Modules\Attendance\Application\Command\RegisterScanCommand;
use App\Modules\Attendance\Application\UseCase\AddShiftEntryHandler;
use App\Modules\Attendance\Application\UseCase\RegisterScanHandler;
use App\Modules\Attendance\Domain\ValueObject\CorrectionReason;
use App\Modules\Attendance\Domain\ValueObject\CorrectionReasonCode;
use App\Modules\Shared\Domain\ValueObject\CredentialResolution;
use App\Modules\Shared\Domain\ValueObject\UserRole;
use App\Modules\Workforce\Application\Command\UpdateSiteCommand;
use App\Modules\Workforce\Application\UseCase\UpdateSiteHandler;
use App\Modules\Workforce\Domain\Exception\SiteTimezoneLocked;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\Attendance\AttendanceFixtures;
use Tests\Support\Concurrency\ChildSessions;
use Tests\Support\Concurrency\UseCaseInOtherProcess;
use Tests\Support\Database\CommittedDatabase;
use Tests\Support\Identity\ManagementUsers;
use Tests\Support\Time\FrozenTime;

/*
 * **UN PRIMER FICHAJE CONCURRENTE NO DEJA LA ZONA CAMBIADA CON UN TRAMO
 * ATRIBUIDO CON LA VIEJA** (RN-23, ADR-056 §2, «Verificacion: integracion»).
 *
 * `PATCH /site` bloquea la fila del centro con `FOR UPDATE` y despues pregunta
 * si hay registro horario; el fichaje y el alta manual leen la zona con `FOR KEY
 * SHARE` antes de escribir su tramo. Los dos candados chocan, asi que solo hay
 * dos desenlaces posibles, y aqui se fuerza cada uno:
 *
 * - **El cambio de zona va primero**: el tramo espera y se atribuye con la zona
 *   nueva.
 * - **El tramo va primero**: el cambio espera, ve el tramo y se niega con
 *   `SiteTimezoneLocked`; la zona no cambia.
 *
 * **Las 23:30 UTC del 14 de marzo de 2026** son las 00:30 del 15 en Madrid
 * (UTC+1, aun sin horario de verano) y las 23:30 del 14 en Canarias (UTC+0):
 * la `work_date` dice con que zona se atribuyo el tramo.
 *
 * **Dos procesos**: el primero se queda en una transaccion abierta de esta
 * sesion; el segundo corre en otro proceso PHP ({@see UseCaseInOtherProcess}) y
 * la prueba espera, por condicion en `pg_locks`, a que este bloqueado antes de
 * confirmar el primero. Sin `RefreshDatabase`: su transaccion envolvente
 * esconderia las filas al otro proceso.
 */

uses(CommittedDatabase::class);

const SITE_TIMEZONE_RACE_NOW = '2026-03-14 23:30:00';

const SITE_TIMEZONE_RACE_SCAN_AT = '2026-03-14T23:30:00Z';

const SITE_TIMEZONE_RACE_IN = '2026-03-14T23:05:00Z';

const SITE_TIMEZONE_RACE_OUT = '2026-03-14T23:25:00Z';

const SITE_TIMEZONE_RACE_CARD = 'FH1.a3.tarjetazonahoraria00.firma';

beforeEach(function (): void {
    FrozenTime::at(SITE_TIMEZONE_RACE_NOW);
});

afterEach(function (): void {
    DB::purge('site_timezone_race_probe');
    ChildSessions::waitUntilGone();
});

function carreraDeZonaSonda(): ConnectionInterface
{
    config(['database.connections.site_timezone_race_probe' => config()->array('database.connections.'.config()->string('database.default'))]);

    return DB::connection('site_timezone_race_probe');
}

/**
 * Espera, por condicion, a que el otro proceso este esperando un candado de
 * fila. El tope solo existe para que un hijo que no llega no deje la prueba
 * girando; si termina sin esperar, la prueba lo dice con su desenlace.
 */
function carreraDeZonaEsperaBloqueada(ConnectionInterface $probe, UseCaseInOtherProcess $child): bool
{
    for ($attempt = 0; $attempt < 10_000; $attempt++) {
        /** @var object{waiting: int} $row */
        $row = $probe->selectOne(
            "SELECT count(*) AS waiting FROM pg_locks WHERE NOT granted AND locktype IN ('transactionid', 'tuple')",
        );

        if ($row->waiting > 0) {
            return true;
        }

        if (! $child->isRunning()) {
            return false;
        }

        usleep(2_000);
    }

    return false;
}

/**
 * Deja abierta en esta sesion la transaccion de `$first` y arranca `$second` en
 * otro proceso; confirma la primera en cuanto la segunda espera.
 */
function carreraDeZona(callable $first, UseCaseInOtherProcess $second): string
{
    $probe = carreraDeZonaSonda();

    DB::beginTransaction();

    try {
        $first();
        $second->start();

        if (! carreraDeZonaEsperaBloqueada($probe, $second)) {
            throw new RuntimeException('El segundo no ha llegado a esperar al primero: '.$second->finish());
        }
    } finally {
        DB::commit();
    }

    return $second->finish();
}

/**
 * @return array{site: int, employee: string, device: int, deviceUuid: string, user: int}
 */
function escenarioDeCarreraDeZona(): array
{
    /** @var array{site: int, department: int, employee: string, device: int, deviceUuid: string, token: string} $scenario */
    $scenario = AttendanceFixtures::scenario('Europe/Madrid');

    return [
        'site' => $scenario['site'],
        'employee' => $scenario['employee'],
        'device' => $scenario['device'],
        'deviceUuid' => $scenario['deviceUuid'],
        'user' => ManagementUsers::withRole(UserRole::ADMIN)->id,
    ];
}

function zonaDelCentroEnLaCarrera(int $site): string
{
    $zona = DB::table('sites')->where('id', $site)->value('timezone');
    \assert(\is_string($zona));

    return $zona;
}

/**
 * @return list<string>
 */
function jornadasDeLaCarreraDeZona(string $employee): array
{
    /** @var list<object{work_date: string}> $rows */
    $rows = DB::select(
        'SELECT work_date::text AS work_date FROM shift_entries WHERE employee_id = ? ORDER BY clocked_in_at',
        [AttendanceFixtures::employeeIdOf($employee)],
    );

    return array_map(static fn (object $row): string => $row->work_date, $rows);
}

function cambioDeZonaACanarias(): UpdateSiteCommand
{
    return new UpdateSiteCommand(timezone: 'Atlantic/Canary');
}

it('si el cambio de zona va primero, el primer fichaje espera y se atribuye con la zona nueva', function (): void {
    $escenario = escenarioDeCarreraDeZona();

    $desenlace = carreraDeZona(
        static fn (): mixed => app(UpdateSiteHandler::class)->handle(cambioDeZonaACanarias()),
        UseCaseInOtherProcess::of('register_scan', [
            'employee' => $escenario['employee'],
            'scan_id' => Str::uuid7()->toString(),
            'qr_payload' => SITE_TIMEZONE_RACE_CARD,
            'occurred_at' => SITE_TIMEZONE_RACE_SCAN_AT,
            'device' => (string) $escenario['device'],
            'device_uuid' => $escenario['deviceUuid'],
        ], SITE_TIMEZONE_RACE_NOW),
    );

    // Con Madrid habria sido el 15: el fichaje leyo la zona que el cambio dejo.
    expect($desenlace)->toBe(UseCaseInOtherProcess::COMMITTED)
        ->and(zonaDelCentroEnLaCarrera($escenario['site']))->toBe('Atlantic/Canary')
        ->and(jornadasDeLaCarreraDeZona($escenario['employee']))->toBe(['2026-03-14'])
        ->and(AttendanceFixtures::projectionDivergences())->toBe([]);
})->group('RN-23', 'RN-05');

it('si el primer fichaje va primero, el cambio de zona espera, lo ve y se niega', function (): void {
    $escenario = escenarioDeCarreraDeZona();

    $desenlace = carreraDeZona(
        static fn (): mixed => app(RegisterScanHandler::class)->handle(
            new RegisterScanCommand(
                scanId: Str::uuid7()->toString(),
                qrPayload: SITE_TIMEZONE_RACE_CARD,
                occurredAt: new DateTimeImmutable(SITE_TIMEZONE_RACE_SCAN_AT),
                deviceId: $escenario['device'],
                deviceUuid: $escenario['deviceUuid'],
            ),
            CredentialResolution::resolved($escenario['employee']),
        ),
        UseCaseInOtherProcess::of('update_site_timezone', ['timezone' => 'Atlantic/Canary'], SITE_TIMEZONE_RACE_NOW),
    );

    expect($desenlace)->toBe('rejected:'.SiteTimezoneLocked::class)
        ->and(zonaDelCentroEnLaCarrera($escenario['site']))->toBe('Europe/Madrid')
        ->and(jornadasDeLaCarreraDeZona($escenario['employee']))->toBe(['2026-03-15'])
        ->and(DB::table('audit_log')->where('action', 'site.updated')->count())->toBe(0);
})->group('RN-23', 'RN-05');

it('si el cambio de zona va primero, el alta manual espera y se valida con la zona nueva', function (): void {
    // La jornada la declara quien da el alta: el 14 solo es valido si la
    // entrada de las 23:05 UTC cae el 14 en la zona del centro, y eso solo es
    // cierto en Canarias. Con la zona leida fuera de la transaccion —la de
    // antes de este bloque— el alta usaba Madrid y se negaba (ADR-035).
    $escenario = escenarioDeCarreraDeZona();

    $desenlace = carreraDeZona(
        static fn (): mixed => app(UpdateSiteHandler::class)->handle(cambioDeZonaACanarias()),
        UseCaseInOtherProcess::of('add_shift_entry', [
            'employee' => $escenario['employee'],
            'work_date' => '2026-03-14',
            'clocked_in_at' => SITE_TIMEZONE_RACE_IN,
            'clocked_out_at' => SITE_TIMEZONE_RACE_OUT,
            'user' => (string) $escenario['user'],
        ], SITE_TIMEZONE_RACE_NOW),
    );

    expect($desenlace)->toBe(UseCaseInOtherProcess::COMMITTED)
        ->and(zonaDelCentroEnLaCarrera($escenario['site']))->toBe('Atlantic/Canary')
        ->and(jornadasDeLaCarreraDeZona($escenario['employee']))->toBe(['2026-03-14'])
        ->and(AttendanceFixtures::projectionDivergences())->toBe([]);
})->group('RN-23', 'RN-05', 'RF-PA-04');

it('si el alta manual va primero, el cambio de zona espera, la ve y se niega', function (): void {
    $escenario = escenarioDeCarreraDeZona();

    $desenlace = carreraDeZona(
        static fn (): mixed => app(AddShiftEntryHandler::class)->handle(new AddShiftEntryCommand(
            employeeUuid: $escenario['employee'],
            // En Madrid, las 23:05 UTC del 14 son las 00:05 del 15.
            workDate: '2026-03-15',
            clockedInAt: new DateTimeImmutable(SITE_TIMEZONE_RACE_IN),
            clockedOutAt: new DateTimeImmutable(SITE_TIMEZONE_RACE_OUT),
            reason: CorrectionReason::of(CorrectionReasonCode::OLVIDO_FICHAJE_ENTRADA),
            performedByUserId: $escenario['user'],
        )),
        UseCaseInOtherProcess::of('update_site_timezone', ['timezone' => 'Atlantic/Canary'], SITE_TIMEZONE_RACE_NOW),
    );

    expect($desenlace)->toBe('rejected:'.SiteTimezoneLocked::class)
        ->and(zonaDelCentroEnLaCarrera($escenario['site']))->toBe('Europe/Madrid')
        ->and(jornadasDeLaCarreraDeZona($escenario['employee']))->toBe(['2026-03-15'])
        ->and(DB::table('audit_log')->where('action', 'site.updated')->count())->toBe(0);
})->group('RN-23', 'RN-05', 'RF-PA-04');
