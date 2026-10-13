<?php

declare(strict_types=1);

use App\Modules\Attendance\Application\Command\RegisterScanCommand;
use App\Modules\Attendance\Application\UseCase\RegisterScanHandler;
use App\Modules\Shared\Domain\ValueObject\CredentialResolution;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\Attendance\AttendanceFixtures;
use Tests\Support\Concurrency\ChildSessions;
use Tests\Support\Concurrency\UseCaseInOtherProcess;
use Tests\Support\Database\CommittedDatabase;
use Tests\Support\Time\FrozenTime;

/*
 * **Dos cierres simultaneos del mismo turno** (revision del bloque 7 de la
 * 2.2.1; RN-06, RL-01, RL-04, regla dura 7 y 8).
 *
 * El defecto: los dos escaneos leian el tramo abierto sin candado. El segundo
 * esperaba en el `upsert` y, cuando el primero confirmaba, el `CASE` de
 * `persistEntries` conservaba la salida del primero en silencio, mientras su
 * agregado, su `DailyTotalsRecalculated` y su asiento `shift_entry.closed`
 * llevaban la suya. `daily_totals` y `audit_log` decian una hora y
 * `shift_entries` otra, y la conciliacion de ADR-057 §4 levantaba una alerta
 * critica falsa.
 *
 * Lo que se exige: el segundo espera al primero **antes** de decidir y decide
 * sobre lo confirmado, exactamente como si hubiera llegado despues. El
 * resultado es coherente entre `shift_entries`, `daily_totals`, `scan_events` y
 * `audit_log`, y el segundo escaneo **confirma** (regla dura 19): anti-rebote,
 * rechazo por orden si viene de la cola offline con una hora ya cubierta, o la
 * entrada siguiente.
 *
 * **Dos procesos**: el primer cierre se queda en una transaccion abierta de
 * esta sesion, con el tramo y la cadena tomados; el segundo corre en otro
 * proceso PHP ({@see UseCaseInOtherProcess}) y la prueba espera, por condicion
 * en `pg_locks`, a que este bloqueado antes de confirmar el primero. Sin
 * `RefreshDatabase`: su transaccion envolvente esconderia las filas al otro
 * proceso.
 */

uses(CommittedDatabase::class);

afterEach(function (): void {
    DB::purge('clock_out_race_probe');
    ChildSessions::waitUntilGone();
});

function carreraDeCierreSonda(): ConnectionInterface
{
    config(['database.connections.clock_out_race_probe' => config()->array('database.connections.'.config()->string('database.default'))]);

    return DB::connection('clock_out_race_probe');
}

/**
 * Espera, por condicion, a que alguna otra sesion este esperando un candado de
 * fila o de transaccion. El tope solo existe para que un hijo que no llega no
 * deje la prueba girando.
 */
function carreraDeCierreEsperaBloqueada(ConnectionInterface $probe, UseCaseInOtherProcess $child): bool
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
 * @param  array{device: int, deviceUuid: string}  $scenario
 */
function carreraDeCierreEscaneo(array $scenario, string $scanId, string $occurredAt): RegisterScanCommand
{
    return new RegisterScanCommand(
        scanId: $scanId,
        qrPayload: 'FH1.a3.tarjetacarrera0000.firma',
        occurredAt: new DateTimeImmutable($occurredAt),
        deviceId: $scenario['device'],
        deviceUuid: $scenario['deviceUuid'],
    );
}

it('dos cierres simultaneos del mismo turno dejan el registro, la proyeccion y la auditoria de acuerdo', function (
    string $openedAt,
    string $closedAt,
    string $secondAt,
    string $expectedSecond,
    string $workDate,
): void {
    FrozenTime::at($secondAt);

    /** @var array{site: int, department: int, employee: string, device: int, deviceUuid: string, token: string} $scenario */
    $scenario = AttendanceFixtures::scenario();
    $employee = $scenario['employee'];
    $handler = app(RegisterScanHandler::class);
    $resolution = CredentialResolution::resolved($employee);

    // El turno abierto, confirmado.
    $handler->handle(carreraDeCierreEscaneo($scenario, Str::uuid7()->toString(), $openedAt), $resolution);

    $probe = carreraDeCierreSonda();
    $second = Str::uuid7()->toString();

    // El primer cierre, sin confirmar: tiene el tramo y la cadena.
    DB::beginTransaction();

    try {
        $first = $handler->handle(carreraDeCierreEscaneo($scenario, Str::uuid7()->toString(), $closedAt), $resolution);

        $child = UseCaseInOtherProcess::of('register_scan', [
            'employee' => $employee,
            'scan_id' => $second,
            'qr_payload' => 'FH1.a3.tarjetacarrera0000.firma',
            'occurred_at' => $secondAt,
            'device' => (string) $scenario['device'],
            'device_uuid' => $scenario['deviceUuid'],
        ], $secondAt)->start();

        if (! carreraDeCierreEsperaBloqueada($probe, $child)) {
            throw new RuntimeException('El segundo cierre no ha llegado a esperar al primero: '.$child->finish());
        }
    } finally {
        DB::commit();
    }

    // El segundo confirma: no se pierde ningun escaneo (regla dura 19).
    expect($child->finish())->toBe(UseCaseInOtherProcess::COMMITTED)
        ->and($first->result->value)->toBe('clock_out')
        ->and(DB::table('scan_events')->where('scan_id', $second)->value('result'))->toBe($expectedSecond);

    $employeeId = AttendanceFixtures::employeeIdOf($employee);

    // El turno se cerro una vez, con la hora del primero y en la jornada de su
    // entrada (RN-05).
    /** @var list<object{uuid: string, work_date: string, clocked_out_at: string|null, same_exit: bool|null}> $entries */
    $entries = DB::select(
        'SELECT uuid, work_date::text AS work_date, clocked_out_at, clocked_out_at = ?::timestamptz AS same_exit '
        .'FROM shift_entries WHERE employee_id = ? ORDER BY clocked_in_at',
        [$closedAt, $employeeId],
    );

    expect($entries[0]->same_exit)->toBeTrue()
        ->and($entries[0]->work_date)->toBe($workDate)
        ->and(count($entries))->toBe($expectedSecond === 'clock_in' ? 2 : 1);

    // `daily_totals` cuadra con los tramos (RN-06, regla dura 7).
    expect(AttendanceFixtures::projectionDivergences())->toBe([]);

    // Y el unico asiento de cierre dice la misma hora que la fila: la
    // conciliacion de §4 no tiene nada que senalar.
    $closings = DB::table('audit_log')
        ->where('action', 'shift_entry.closed')
        ->whereRaw("payload->>'employee_uuid' = ?", [$employee])
        ->get();

    expect($closings)->toHaveCount(1);

    /** @var object{payload: string} $closing */
    $closing = $closings->first();
    /** @var array{shift_entry_uuid: string, clocked_out_at: string} $payload */
    $payload = json_decode($closing->payload, true, 512, JSON_THROW_ON_ERROR);

    expect($payload['shift_entry_uuid'])->toBe($entries[0]->uuid)
        ->and((new DateTimeImmutable($payload['clocked_out_at']))->getTimestamp())
        ->toBe((new DateTimeImmutable($closedAt))->getTimestamp());
})->with([
    // El reintento de la misma persona veinte segundos despues: anti-rebote.
    'el segundo, dentro del anti-rebote' => [
        '2026-03-14T08:00:00Z', '2026-03-14T16:00:00Z', '2026-03-14T16:00:20Z', 'rejected_debounce', '2026-03-14',
    ],
    // Cola offline con una hora que el turno ya cubre: rechazo por orden,
    // marcado para revision, no una salida que reescriba la primera.
    'el segundo, de la cola offline con una hora anterior' => [
        '2026-03-14T08:00:00Z', '2026-03-14T16:00:00Z', '2026-03-14T15:30:00Z', 'rejected_out_of_order', '2026-03-14',
    ],
    // Media hora despues: la entrada siguiente, como si hubiera llegado tarde.
    'el segundo, fuera del anti-rebote' => [
        '2026-03-14T08:00:00Z', '2026-03-14T16:00:00Z', '2026-03-14T16:30:00Z', 'clock_in', '2026-03-14',
    ],
    // Turno de noche: el cierre de las 06:00 sigue en la jornada de la entrada
    // (RN-05, regla dura 4), gane quien gane.
    'turno de noche' => [
        '2026-03-14T21:00:00Z', '2026-03-15T05:00:00Z', '2026-03-15T05:00:20Z', 'rejected_debounce', '2026-03-14',
    ],
])->group('RN-06', 'RL-01', 'RL-04', 'RN-05', 'RF-AT-06', 'RQ-03');
