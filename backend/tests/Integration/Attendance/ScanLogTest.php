<?php

declare(strict_types=1);

use App\Modules\Attendance\Application\Port\ScanIntent;
use App\Modules\Attendance\Application\Port\ScanLog;
use App\Modules\Attendance\Application\Port\ScanRecord;
use App\Modules\Attendance\Application\Port\ScanResult;
use App\Modules\Attendance\Domain\ValueObject\ClockingAction;
use App\Modules\Attendance\Domain\ValueObject\ScanOrigin;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Assert;
use Tests\Support\Attendance\AttendanceFixtures;
use Tests\Support\Database\RefreshDatabase;
use Tests\Support\Time\Instants;
use Tests\Support\Workforce\WorkforceFixtures;

/*
 * `scan_events` contra PostgreSQL de verdad: la idempotencia de la regla dura 8
 * y las dos consultas que sostienen el anti-rebote de RF-AT-06.
 *
 * Lo importante de este fichero es **como** se comprueba la idempotencia: no se
 * pregunta si el escaneo existe, se intenta escribir y se mira si el UNIQUE lo
 * ha impedido. Un `SELECT` previo tiene condicion de carrera —entre la consulta
 * y la insercion cabe otra peticion con el mismo `scan_id`— y bajo el pico de un
 * cambio de turno esa carrera produce tramos duplicados en el registro legal de
 * alguien.
 */

uses(RefreshDatabase::class);

/**
 * @return array{site: int, employee: string, device: int}
 */
function escaneoFixture(): array
{
    $site = WorkforceFixtures::site('Hotel de escaneos');
    $device = AttendanceFixtures::device($site);

    return [
        'site' => $site,
        'employee' => WorkforceFixtures::employee($site),
        'device' => $device['id'],
    ];
}

/**
 * @param  array{site: int, employee: string, device: int}  $fixture
 */
function registro(
    array $fixture,
    string $scanId,
    ScanResult $result = ScanResult::CLOCK_IN,
    ?string $occurredAt = null,
    ?string $shiftEntryUuid = null,
): ScanRecord {
    return new ScanRecord(
        scanId: $scanId,
        deviceId: $fixture['device'],
        employeeUuid: $fixture['employee'],
        occurredAt: Instants::utc($occurredAt ?? '2026-03-14 07:02:00'),
        recordedAt: Instants::utc('2026-03-14 07:02:01'),
        origin: ScanOrigin::QR_KIOSK,
        intent: ScanIntent::AUTO,
        result: $result,
        shiftEntryUuid: $shiftEntryUuid,
        payloadFingerprint: hash('sha256', 'FH1.a3.7QK2mXpR9vLdN4tZbYcF1w.k9Xm2pQrT5vN8wLa'),
        clockSkewSeconds: 1,
        // `scan_events_chk_worked_minutes`: nulo solo en los tres rechazos de
        // verdad. Este fichero no prueba el acumulado, asi que un cero de
        // relleno basta en todo lo demas, anti-rebote incluido (ADR-031).
        workedMinutes: \in_array($result, [ScanResult::REJECTED_UNKNOWN, ScanResult::REJECTED_REVOKED, ScanResult::REJECTED_SIGNATURE], true) ? null : 0,
    );
}

/**
 * Un tramo vigente al que puedan apuntar los escaneos, y su `uuid` publico.
 *
 * Se inserta por SQL y no por el agregado a proposito: lo que este fichero
 * prueba es el adaptador, no el dominio, y un tramo escrito a mano es ademas el
 * caso realista de una jornada importada.
 *
 * @param  array{site: int, employee: string, device: int}  $fixture
 */
function tramoDeFixture(array $fixture, string $workDate, string $clockedInAt, ?string $clockedOutAt = null): string
{
    $uuid = Str::uuid7()->toString();

    DB::table('shift_entries')->insert([
        'uuid' => $uuid,
        'employee_id' => AttendanceFixtures::employeeIdOf($fixture['employee']),
        'site_id' => $fixture['site'],
        'work_date' => $workDate,
        'clocked_in_at' => $clockedInAt.'+00',
        'clocked_out_at' => $clockedOutAt === null ? null : $clockedOutAt.'+00',
        'duration_minutes' => $clockedOutAt === null ? null : 240,
        'status' => $clockedOutAt === null ? 'open' : 'closed',
        'clock_in_source' => 'qr_kiosk',
        'clock_out_source' => $clockedOutAt === null ? null : 'qr_kiosk',
        'version' => 1,
    ]);

    return $uuid;
}

it('escribe el escaneo con sus dos marcas de tiempo y sin el payload', function (): void {
    // Regla dura 9 y RS-03: `occurred_at` es el momento real y `recorded_at` la
    // recepcion; del payload solo queda su huella, para que quien lea la tabla
    // no pueda fabricar una tarjeta valida.
    $fixture = escaneoFixture();
    $scanId = Str::uuid7()->toString();

    expect(app(ScanLog::class)->record(registro($fixture, $scanId)))->toBeTrue();

    $fila = DB::table('scan_events')->where('scan_id', $scanId)->first();

    expect($fila?->result)->toBe('clock_in')
        ->and($fila?->origin)->toBe('qr_kiosk')
        ->and($fila?->intent)->toBe('auto')
        ->and($fila?->clock_skew_seconds)->toBe(1)
        ->and($fila?->payload_fingerprint)->toBe(hash('sha256', 'FH1.a3.7QK2mXpR9vLdN4tZbYcF1w.k9Xm2pQrT5vN8wLa'))
        ->and($fila?->employee_id)->toBe(AttendanceFixtures::employeeIdOf($fixture['employee']));

    // El payload en claro no esta en ninguna columna.
    expect(DB::table('scan_events')->where('payload_fingerprint', 'like', 'FH1%')->count())->toBe(0);
})->group('RF-AT-01', 'RF-AT-09', 'RS-03');

it('rechaza el mismo scan_id sin lanzar y sin escribir una segunda fila', function (): void {
    // Regla dura 8, RF-AT-07. `record()` devuelve `false` en lugar de lanzar:
    // un reenvio desde la cola offline es funcionamiento normal, no un error.
    $fixture = escaneoFixture();
    $scanId = Str::uuid7()->toString();
    $log = app(ScanLog::class);

    expect($log->record(registro($fixture, $scanId)))->toBeTrue()
        ->and($log->record(registro($fixture, $scanId)))->toBeFalse()
        ->and(DB::table('scan_events')->where('scan_id', $scanId)->count())->toBe(1);
})->group('RF-AT-07', 'RQ-03');

it('es la base de datos la que rechaza el scan_id repetido, no el codigo', function (): void {
    // La ultima linea de defensa de la regla dura 8, probada **por SQL
    // directo**: se inserta sin pasar por el puerto y sin `ON CONFLICT`, y lo
    // que responde es PostgreSQL con el nombre del indice dentro.
    //
    // Importa que sea asi y no una comprobacion en PHP: un `SELECT` previo
    // tiene condicion de carrera —entre la consulta y la insercion cabe otra
    // peticion con el mismo `scan_id`— y bajo concurrencia crea duplicados. El
    // recorrido entre procesos de verdad lo ejercita la prueba de idempotencia
    // concurrente de `tests/Feature/Attendance`.
    $fixture = escaneoFixture();
    $scanId = Str::uuid7()->toString();

    expect(app(ScanLog::class)->record(registro($fixture, $scanId)))->toBeTrue();

    // Dentro de una transaccion propia —que sobre la de `RefreshDatabase` es un
    // `SAVEPOINT`— porque en PostgreSQL un error aborta la transaccion entera:
    // sin ese punto de retorno, la prueba no podria seguir consultando despues.
    $duplicado = static fn (): mixed => DB::transaction(static fn (): bool => DB::table('scan_events')->insert([
        'scan_id' => $scanId,
        'device_id' => $fixture['device'],
        'employee_id' => AttendanceFixtures::employeeIdOf($fixture['employee']),
        'occurred_at' => '2026-03-14 07:02:00+00',
        'recorded_at' => '2026-03-14 07:02:05+00',
        'origin' => 'qr_kiosk',
        'intent' => 'auto',
        'result' => 'clock_in',
        'client_meta' => '{}',
        'flagged_for_review' => false,
        'worked_minutes' => 0,
    ]));

    try {
        $duplicado();
        Assert::fail('PostgreSQL ha aceptado dos escaneos con el mismo scan_id.');
    } catch (QueryException $rechazo) {
        expect($rechazo->getMessage())->toContain('scan_events_scan_id_unique');
    }

    expect(DB::table('scan_events')->where('scan_id', $scanId)->count())->toBe(1);
})->group('RF-AT-07', 'RQ-03');

it('reconstruye el escaneo original con su jornada para poder responder igual', function (): void {
    // RF-AT-07: la respuesta de un reenvio se **reconstruye** de lo registrado.
    // `work_date` sale del tramo al que apunta el escaneo, que es lo que permite
    // devolver la misma jornada aunque el turno cruzara la medianoche.
    $fixture = escaneoFixture();
    $scanId = Str::uuid7()->toString();
    $employeeId = AttendanceFixtures::employeeIdOf($fixture['employee']);
    $entryUuid = Str::uuid7()->toString();

    $shiftEntryId = DB::table('shift_entries')->insertGetId([
        'uuid' => $entryUuid,
        'employee_id' => $employeeId,
        'site_id' => $fixture['site'],
        'work_date' => '2026-03-13',
        'clocked_in_at' => '2026-03-13 21:00:00+00',
        'clocked_out_at' => '2026-03-14 05:00:00+00',
        'duration_minutes' => 480,
        'status' => 'closed',
        'clock_in_source' => 'qr_kiosk',
        'clock_out_source' => 'qr_kiosk',
        'version' => 1,
    ]);

    DB::table('scan_events')->insert([
        'scan_id' => $scanId,
        'device_id' => $fixture['device'],
        'employee_id' => $employeeId,
        'occurred_at' => '2026-03-14 05:00:00+00',
        'recorded_at' => '2026-03-14 05:00:01.250000+00',
        'origin' => 'qr_kiosk',
        'intent' => 'auto',
        'result' => 'clock_out',
        'shift_entry_id' => $shiftEntryId,
        'client_meta' => '{}',
        'flagged_for_review' => false,
        'worked_minutes' => 480,
    ]);

    $recuperado = app(ScanLog::class)->find($scanId);

    expect($recuperado?->result)->toBe(ScanResult::CLOCK_OUT)
        ->and($recuperado?->employeeUuid)->toBe($fixture['employee'])
        ->and($recuperado?->workDate)->toBe('2026-03-13')
        // Regla dura 8: el numero que se guardo con el escaneo, no uno
        // recalculado de la jornada actual.
        ->and($recuperado?->workedMinutes)->toBe(480)
        // La recepcion que se devuelve es la ORIGINAL, con sus decimales: es lo
        // que hace que la respuesta del reenvio sea identica.
        ->and($recuperado?->recordedAt->format('Y-m-d H:i:s.u'))->toBe('2026-03-14 05:00:01.250000')
        ->and(app(ScanLog::class)->find(Str::uuid7()->toString()))->toBeNull();
})->group('RF-AT-07');

it('busca los escaneos aceptados adyacentes a un instante, a los dos lados', function (): void {
    // Es lo que alimenta la ventana de RF-AT-06. Dos candidatos y no todos: uno
    // mas lejano no puede ganar, y asi las dos consultas caben en el indice
    // `(employee_id, occurred_at DESC)` en lugar de ordenar el historico entero
    // del empleado en cada fichaje.
    $fixture = escaneoFixture();
    $log = app(ScanLog::class);

    $log->record(registro($fixture, Str::uuid7()->toString(), ScanResult::CLOCK_IN, '2026-03-14 06:00:00'));
    $log->record(registro($fixture, Str::uuid7()->toString(), ScanResult::CLOCK_OUT, '2026-03-14 07:00:00'));
    $log->record(registro($fixture, Str::uuid7()->toString(), ScanResult::CLOCK_IN, '2026-03-14 09:00:00'));

    $adyacentes = $log->acceptedScansAdjacentTo($fixture['employee'], Instants::utc('2026-03-14 07:30:00'));

    expect($adyacentes)->toHaveCount(2)
        ->and($adyacentes[0]->occurredAt->format('H:i'))->toBe('07:00')
        ->and($adyacentes[1]->occurredAt->format('H:i'))->toBe('09:00')
        // Desde la tarea 3.5 lleva ademas QUE fue cada uno: sin eso el
        // anti-rebote no puede distinguir una vuelta de pausa de un rebote
        // (ADR-024).
        ->and($adyacentes[0]->action)->toBe(ClockingAction::CLOCK_OUT)
        ->and($adyacentes[1]->action)->toBe(ClockingAction::CLOCK_IN);
})->group('RF-AT-06', 'RF-AT-12');

it('ignora los escaneos rechazados al medir la ventana anti-rebote', function (): void {
    // Un `rejected_debounce` no reinicia la ventana: si lo hiciera, bastaria con
    // pasar la tarjeta cada 50 segundos para prolongarla indefinidamente y el
    // empleado no podria fichar la salida.
    $fixture = escaneoFixture();
    $log = app(ScanLog::class);

    $log->record(registro($fixture, Str::uuid7()->toString(), ScanResult::CLOCK_IN, '2026-03-14 06:00:00'));
    $log->record(registro($fixture, Str::uuid7()->toString(), ScanResult::REJECTED_DEBOUNCE, '2026-03-14 06:00:30'));
    $log->record(registro($fixture, Str::uuid7()->toString(), ScanResult::REJECTED_SIGNATURE, '2026-03-14 06:00:45'));

    $adyacentes = $log->acceptedScansAdjacentTo($fixture['employee'], Instants::utc('2026-03-14 06:01:00'));

    expect($adyacentes)->toHaveCount(1)
        ->and($adyacentes[0]->occurredAt->format('H:i:s'))->toBe('06:00:00');
})->group('RF-AT-06');

it('devuelve el ultimo escaneo aceptado con el tramo del que viene', function (): void {
    // RF-AT-12 y ADR-024. Es el tercer hecho de `ScanIntentPolicy`: sin el, una
    // vuelta de pausa y una entrada nueva son indistinguibles —las dos llegan
    // sin tramo abierto— y el turno de noche se partiria en dos jornadas.
    //
    // **Sin acotar por tiempo**: lo que se busca es el ESTADO en que quedo la
    // persona, y una pausa para comer dura lo que dura.
    $fixture = escaneoFixture();
    $log = app(ScanLog::class);
    $tramo = tramoDeFixture($fixture, '2026-03-13', '2026-03-13 21:00:00', '2026-03-14 01:00:00');

    $log->record(registro($fixture, Str::uuid7()->toString(), ScanResult::CLOCK_IN, '2026-03-13 21:00:00'));
    $log->record(registro($fixture, Str::uuid7()->toString(), ScanResult::BREAK_START, '2026-03-14 01:00:00', $tramo));

    $ultimo = $log->lastAcceptedScanOf($fixture['employee']);

    expect($ultimo?->action)->toBe(ClockingAction::BREAK_START)
        ->and($ultimo?->occurredAt->format('Y-m-d H:i'))->toBe('2026-03-14 01:00')
        // El tramo del que viene: es lo que el handler pasa a
        // `findWorkDayOfShiftEntry()` en lugar de la fecha civil del escaneo.
        ->and($ultimo?->breakInProgressShiftEntry())->toBe($tramo);
})->group('RF-AT-12', 'RN-05');

it('mide el ultimo aceptado por occurred_at y no por orden de llegada', function (): void {
    // Regla dura 9: un lote offline sincronizado ahora puede traer escaneos
    // ANTERIORES a otros ya registrados. Si «ultimo» fuera el ultimo insertado,
    // la vuelta de una pausa de ayer reabriria la jornada de hoy.
    $fixture = escaneoFixture();
    $log = app(ScanLog::class);

    $log->record(registro($fixture, Str::uuid7()->toString(), ScanResult::CLOCK_OUT, '2026-03-14 14:00:00'));
    // Se escribe despues pero ocurrio antes.
    $log->record(registro($fixture, Str::uuid7()->toString(), ScanResult::CLOCK_IN, '2026-03-14 06:00:00'));

    expect($log->lastAcceptedScanOf($fixture['employee'])?->occurredAt->format('H:i'))->toBe('14:00');
})->group('RF-AT-12', 'RF-KI-03');

it('no inventa un ultimo aceptado para quien nunca ficho ni para los rechazos', function (): void {
    // Un rechazo no deja estado: `ScanIntentPolicy` tiene que ver `null` y
    // resolver `CLOCK_IN`, que es lo que hace que una tarjeta mal leida no
    // convierta la siguiente entrada en una vuelta de pausa.
    $fixture = escaneoFixture();
    $log = app(ScanLog::class);

    expect($log->lastAcceptedScanOf($fixture['employee']))->toBeNull()
        ->and($log->lastAcceptedScanOf(Str::uuid7()->toString()))->toBeNull();

    $log->record(registro($fixture, Str::uuid7()->toString(), ScanResult::REJECTED_SIGNATURE, '2026-03-14 06:00:00'));
    $log->record(registro($fixture, Str::uuid7()->toString(), ScanResult::REJECTED_DEBOUNCE, '2026-03-14 06:00:30'));

    expect($log->lastAcceptedScanOf($fixture['employee']))->toBeNull();
})->group('RF-AT-12', 'RF-AT-06');

it('registra un escaneo que no resolvio a ningun empleado', function (): void {
    // Doc 01 §5.5: `scan_events` registra TODO escaneo. La fila del rechazo es
    // la que permite investigar despues sin haber revelado nada en su momento,
    // y la que alimenta el contador de rechazos por firma del doc 01 §11.
    $fixture = escaneoFixture();
    $scanId = Str::uuid7()->toString();

    $anonimo = new ScanRecord(
        scanId: $scanId,
        deviceId: $fixture['device'],
        employeeUuid: null,
        occurredAt: Instants::utc('2026-03-14 07:02:00'),
        recordedAt: Instants::utc('2026-03-14 07:02:01'),
        origin: ScanOrigin::QR_KIOSK,
        intent: ScanIntent::AUTO,
        result: ScanResult::REJECTED_SIGNATURE,
        payloadFingerprint: hash('sha256', 'falsificado'),
    );

    expect(app(ScanLog::class)->record($anonimo))->toBeTrue();

    $fila = DB::table('scan_events')->where('scan_id', $scanId)->first();

    expect($fila?->employee_id)->toBeNull()
        // `scan_events_chk_rejected_has_no_shift_entry`: un tramo colgado de un
        // rechazo haria imposible auditar por que existe una jornada.
        ->and($fila?->shift_entry_id)->toBeNull();
})->group('RF-QR-02', 'RS-03');

it('escribe el fichaje por PIN con su origen, su marca de revision y sin huella', function (): void {
    // RF-AT-11 (tarea 1.12) contra el esquema real: el CHECK
    // `scan_events_chk_origin` admite `pin_kiosk` desde la migracion de la 1.4, y
    // `flagged_for_review` tiene su indice parcial esperando a la bandeja de la
    // tarea 2.5. Sin esta fila, esa bandeja nacera sin historico que mostrar.
    //
    // **Sin huella de payload**: no hay tarjeta de la que tomarla. Inventar una a
    // partir del codigo de empleado habria metido dos cosas distintas en la
    // misma columna, y quien investigara un escaneo no sabria cual esta mirando.
    $fixture = escaneoFixture();
    $scanId = Str::uuid7()->toString();

    $porPin = new ScanRecord(
        scanId: $scanId,
        deviceId: $fixture['device'],
        employeeUuid: $fixture['employee'],
        occurredAt: Instants::utc('2026-03-14 07:02:00'),
        recordedAt: Instants::utc('2026-03-14 07:02:01'),
        origin: ScanOrigin::PIN_KIOSK,
        intent: ScanIntent::AUTO,
        result: ScanResult::CLOCK_IN,
        payloadFingerprint: null,
        clockSkewSeconds: 1,
        flaggedForReview: true,
        workedMinutes: 0,
    );

    expect(app(ScanLog::class)->record($porPin))->toBeTrue();

    $fila = DB::table('scan_events')->where('scan_id', $scanId)->first();

    expect($fila?->origin)->toBe('pin_kiosk')
        ->and($fila?->flagged_for_review)->toBeTrue()
        ->and($fila?->payload_fingerprint)->toBeNull();

    // Y el UNIQUE de `scan_id` vale igual por esta via (regla dura 8): un
    // reenvio desde la cola offline no puede duplicar el fichaje.
    expect(app(ScanLog::class)->record($porPin))->toBeFalse()
        ->and(DB::table('scan_events')->where('scan_id', $scanId)->count())->toBe(1);
})->group('RF-AT-11', 'RF-AT-07');

it('devuelve el tramo del break_start aunque una correccion lo haya sustituido', function (): void {
    // RF-AT-12 con RN-13 de por medio. Una correccion no toca `scan_events`:
    // crea una version nueva del tramo y jubila la anterior, y el escaneo sigue
    // apuntando a la vieja. Lo que este metodo tiene que devolver es **el uuid
    // que el escaneo dejo escrito**, sea cual sea su estado: de encontrar su
    // jornada se encarga `WorkDayRepository::findWorkDayOfAnyShiftEntry()`, que
    // resuelve igual sobre un tramo retirado.
    //
    // Si aqui se filtrara por vigencia, corregir la hora de entrada de alguien
    // que esta en la pausa le partiria el turno al volver.
    $fixture = escaneoFixture();
    $log = app(ScanLog::class);
    $original = tramoDeFixture($fixture, '2026-03-13', '2026-03-13 21:00:00', '2026-03-14 01:00:00');

    $log->record(registro($fixture, Str::uuid7()->toString(), ScanResult::BREAK_START, '2026-03-14 01:00:00', $original));

    // La correccion, tal y como la escribe `CorrectShiftHandler`: primero la
    // version anterior pasa a `superseded` —si no, la sucesora chocaria contra
    // `shift_entries_no_overlap`, que es exactamente lo que ese estado existe
    // para permitir (ADR-026)— y despues se enlazan.
    DB::table('shift_entries')->where('uuid', $original)->update(['status' => 'superseded']);

    $sucesora = tramoDeFixture($fixture, '2026-03-13', '2026-03-13 21:05:00', '2026-03-14 01:00:00');

    DB::table('shift_entries')->where('uuid', $original)->update([
        'superseded_by_id' => DB::table('shift_entries')->where('uuid', $sucesora)->value('id'),
    ]);

    $ultimo = $log->lastAcceptedScanOf($fixture['employee']);

    expect($ultimo?->action)->toBe(ClockingAction::BREAK_START)
        ->and($ultimo?->breakInProgressShiftEntry())->toBe($original);
})->group('RF-AT-12', 'RN-13', 'RN-05');
