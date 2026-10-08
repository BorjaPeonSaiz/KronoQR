<?php

declare(strict_types=1);

use App\Modules\Attendance\Application\Command\AddShiftEntryCommand;
use App\Modules\Attendance\Application\Command\CorrectShiftCommand;
use App\Modules\Attendance\Application\Command\VoidShiftCommand;
use App\Modules\Attendance\Application\Port\CredentialResolver;
use App\Modules\Attendance\Application\UseCase\AddShiftEntryHandler;
use App\Modules\Attendance\Application\UseCase\CorrectShiftHandler;
use App\Modules\Attendance\Application\UseCase\VoidShiftHandler;
use App\Modules\Attendance\Domain\ValueObject\CorrectionReason;
use App\Modules\Compliance\Application\Command\RecordAuditEntryCommand;
use App\Modules\Compliance\Application\UseCase\RecordAuditEntry;
use App\Modules\Compliance\Domain\ValueObject\AuditAction;
use App\Modules\Compliance\Domain\ValueObject\AuditActor;
use App\Modules\Compliance\Domain\ValueObject\AuditPayload;
use App\Modules\Compliance\Domain\ValueObject\AuditSubject;
use App\Modules\Compliance\Infrastructure\Persistence\DatabaseWorkRecordArchive;
use App\Modules\Shared\Domain\ValueObject\UserRole;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\Attendance\AttendanceFixtures;
use Tests\Support\Attendance\FakeCredentialResolver;
use Tests\Support\Database\RefreshDatabase;
use Tests\Support\Http\Api;
use Tests\Support\Identity\ManagementUsers;
use Tests\Support\Time\FrozenTime;
use Tests\Support\Time\Instants;
use Tests\Support\Workforce\WorkforceFixtures;

/*
 * La conciliacion entre el registro horario y su auditoria contra PostgreSQL de
 * verdad (ADR-057 §4, RL-04, RS-07, R6-AR-01).
 *
 * POR QUE INTEGRACION. Lo que se prueba es la relacion entre dos tablas y lo que
 * pasa cuando alguien escribe en una por fuera de la aplicacion. Un doble en
 * memoria no puede corromper una fila «por detras».
 *
 * EL REGISTRO SE SIEMBRA POR LOS CASOS DE USO REALES: fichajes por la API del
 * quiosco, una correccion, una anulacion y un alta manual. Asi los asientos son
 * los que escribe de verdad `RecordShiftEntryAudit`, y la primera afirmacion de
 * cada prueba es que el uso normal del producto da CERO discrepancias.
 *
 * EL ATACANTE USA LA CONEXION DE LA APLICACION, que es la credencial que ADR-057
 * describe: `fichaje_app` puede hacer `UPDATE`, `INSERT` y `DELETE` sobre el
 * registro horario y no puede tocar `audit_log`. Por eso, ademas, la
 * verificacion de la cadena sigue en verde despues del ataque: es exactamente el
 * hueco que esta conciliacion cierra.
 */

uses(RefreshDatabase::class);

/** El «ahora» de todas las pruebas: la madrugada en la que corre la diaria. */
const WORK_RECORD_RECONCILIATION_AHORA = '2026-10-08 04:15:00';

const WORK_RECORD_RECONCILIATION_TARJETA_A = 'FH1.a3.Rc8NwLq2Zt7Yp4Kd9Xm1Va.Pq7Ws2Lk9Ty4Hn6B';

const WORK_RECORD_RECONCILIATION_TARJETA_B = 'FH1.a3.Hm3QvT8nLc2Wd6Ys1Kp9Zb.Jx4Ne7Ra2Uf5Gt8C';

const WORK_RECORD_RECONCILIATION_TARJETA_C = 'FH1.a3.Pz7LkW2qNc8Rt5Ym3Vb6Xd.Qe9Hs4Ju1Ko7Fa2T';

const WORK_RECORD_RECONCILIATION_TARJETA_D = 'FH1.a3.Wb4Tn9Xc2Lq7Rm5Zp8Ks3Vd.Gh6Ja1Ne4Ry7Uo2P';

/** Apellido inconfundible: si aparece en la salida o en una metrica, se ha filtrado un nombre. */
const WORK_RECORD_RECONCILIATION_APELLIDO = 'Conciliadorez';

/**
 * Cuatro personas en un centro de Madrid, con su quiosco y su tarjeta.
 *
 * @return array{site: int, department: int, employee: string, device: int, deviceUuid: string, token: string, otra: string, tercera: string, cuarta: string, autor: int, metricas: string}
 */
function registroAuditadoEscenario(): array
{
    $escenario = AttendanceFixtures::scenario();
    $otra = WorkforceFixtures::employee(
        $escenario['site'],
        $escenario['department'],
        'active',
        'Remedios',
        WORK_RECORD_RECONCILIATION_APELLIDO,
        'RC'.Str::random(6),
    );

    $tercera = WorkforceFixtures::employee($escenario['site'], $escenario['department']);
    $cuarta = WorkforceFixtures::employee($escenario['site'], $escenario['department']);

    FrozenTime::at(WORK_RECORD_RECONCILIATION_AHORA);

    app()->instance(
        CredentialResolver::class,
        FakeCredentialResolver::new()
            ->resolving(WORK_RECORD_RECONCILIATION_TARJETA_A, $escenario['employee'])
            ->resolving(WORK_RECORD_RECONCILIATION_TARJETA_B, $otra)
            ->resolving(WORK_RECORD_RECONCILIATION_TARJETA_C, $tercera)
            ->resolving(WORK_RECORD_RECONCILIATION_TARJETA_D, $cuarta),
    );

    $metricas = sys_get_temp_dir().'/kronoqr-conciliacion-'.bin2hex(random_bytes(6));
    Config::set('observability.metrics.textfile_path', $metricas);
    Config::set('observability.metrics.enabled', true);

    return [
        ...$escenario,
        'otra' => $otra,
        'tercera' => $tercera,
        'cuarta' => $cuarta,
        'autor' => ManagementUsers::withRole(UserRole::RRHH)->id,
        'metricas' => $metricas,
    ];
}

/**
 * @param  array{token: string}  $escenario
 */
function registroAuditadoFichar(array $escenario, string $tarjeta, string $occurredAt, string $intent = 'auto'): void
{
    $scanId = Str::uuid7()->toString();

    $respuesta = Api::as($escenario['token'])
        ->withHeaders(['Idempotency-Key' => $scanId])
        ->post('/api/v1/scan', ['scan_id' => $scanId, 'occurred_at' => $occurredAt, 'qr_payload' => $tarjeta, 'intent' => $intent]);

    expect($respuesta->status())->toBe(200, $occurredAt.' '.$intent.': '.$respuesta->getContent());
}

/** El tramo vigente o anulado de esa persona que entra a esa hora. */
function registroAuditadoTramo(string $employeeUuid, string $clockedInAt): string
{
    $uuid = DB::table('shift_entries')
        ->where('employee_id', AttendanceFixtures::employeeIdOf($employeeUuid))
        ->where('clocked_in_at', Instants::utc($clockedInAt)->format('Y-m-d H:i:sP'))
        ->where('status', '<>', 'superseded')
        ->value('uuid');

    expect($uuid)->toBeString('No hay tramo de '.$employeeUuid.' que entre a las '.$clockedInAt.'.');

    return is_string($uuid) ? $uuid : throw new RuntimeException('Tramo no encontrado.');
}

/**
 * El uso normal del producto, por sus casos de uso, sobre las dos personas.
 *
 * @param  array{site: int, department: int, employee: string, device: int, deviceUuid: string, token: string, otra: string, tercera: string, cuarta: string, autor: int, metricas: string}  $escenario
 * @return array{corregido: string, sustituido: string, anulado: string, abierto: string, alta: string}
 */
function registroAuditadoLegitimo(array $escenario): array
{
    // A: una jornada cerrada por el quiosco y despues corregida (sale una
    // version nueva y la vieja queda `superseded`).
    registroAuditadoFichar($escenario, WORK_RECORD_RECONCILIATION_TARJETA_A, '2026-10-07T06:00:00Z');
    registroAuditadoFichar($escenario, WORK_RECORD_RECONCILIATION_TARJETA_A, '2026-10-07T14:00:00Z');
    $original = registroAuditadoTramo($escenario['employee'], '2026-10-07 06:00');

    $corregido = resolve(CorrectShiftHandler::class)->handle(new CorrectShiftCommand(
        shiftEntryUuid: $original,
        clockedInAt: null,
        clockedOutAt: Instants::utc('2026-10-07 14:30'),
        reason: CorrectionReason::fromCode('OLVIDO_FICHAJE_SALIDA'),
        performedByUserId: $escenario['autor'],
    ))->shiftEntryUuid;

    // A: un segundo tramo duplicado y anulado.
    registroAuditadoFichar($escenario, WORK_RECORD_RECONCILIATION_TARJETA_A, '2026-10-07T15:00:00Z');
    registroAuditadoFichar($escenario, WORK_RECORD_RECONCILIATION_TARJETA_A, '2026-10-07T17:00:00Z');
    $anulado = registroAuditadoTramo($escenario['employee'], '2026-10-07 15:00');

    resolve(VoidShiftHandler::class)->handle(new VoidShiftCommand(
        shiftEntryUuid: $anulado,
        reason: CorrectionReason::fromCode('ERROR_DE_ESCANEO_DUPLICADO'),
        performedByUserId: $escenario['autor'],
    ));

    // B: un turno de noche todavia abierto y un alta manual del dia anterior.
    registroAuditadoFichar($escenario, WORK_RECORD_RECONCILIATION_TARJETA_B, '2026-10-07T20:00:00Z');
    $abierto = registroAuditadoTramo($escenario['otra'], '2026-10-07 20:00');

    resolve(AddShiftEntryHandler::class)->handle(new AddShiftEntryCommand(
        employeeUuid: $escenario['otra'],
        workDate: '2026-10-06',
        clockedInAt: Instants::utc('2026-10-06 08:00'),
        clockedOutAt: Instants::utc('2026-10-06 12:00'),
        reason: CorrectionReason::fromCode('OLVIDO_FICHAJE_ENTRADA'),
        performedByUserId: $escenario['autor'],
    ));
    $alta = registroAuditadoTramo($escenario['otra'], '2026-10-06 08:00');

    // C: un turno de noche 22:00 -> 06:00 de Madrid, cerrado y en un solo
    // tramo (RN-05); una jornada con pausa (`break_start` / `break_end`,
    // ADR-024), y un cierre con anomalia (13 h 30 min, por encima de las 12 h
    // de ATTENDANCE_MAX_SHIFT_HOURS: `anomalous`).
    registroAuditadoFichar($escenario, WORK_RECORD_RECONCILIATION_TARJETA_C, '2026-10-04T20:00:00Z');
    registroAuditadoFichar($escenario, WORK_RECORD_RECONCILIATION_TARJETA_C, '2026-10-05T04:00:00Z');
    registroAuditadoFichar($escenario, WORK_RECORD_RECONCILIATION_TARJETA_C, '2026-10-06T07:00:00Z');
    registroAuditadoFichar($escenario, WORK_RECORD_RECONCILIATION_TARJETA_C, '2026-10-06T10:00:00Z', 'break_start');
    registroAuditadoFichar($escenario, WORK_RECORD_RECONCILIATION_TARJETA_C, '2026-10-06T10:30:00Z', 'break_end');
    registroAuditadoFichar($escenario, WORK_RECORD_RECONCILIATION_TARJETA_C, '2026-10-06T15:00:00Z');
    registroAuditadoFichar($escenario, WORK_RECORD_RECONCILIATION_TARJETA_C, '2026-10-07T04:00:00Z');
    registroAuditadoFichar($escenario, WORK_RECORD_RECONCILIATION_TARJETA_C, '2026-10-07T17:30:00Z');

    // D: correcciones encadenadas v1 -> v2 -> v3 sobre la misma jornada...
    registroAuditadoFichar($escenario, WORK_RECORD_RECONCILIATION_TARJETA_D, '2026-10-07T07:00:00Z');
    registroAuditadoFichar($escenario, WORK_RECORD_RECONCILIATION_TARJETA_D, '2026-10-07T15:00:00Z');
    $v2 = resolve(CorrectShiftHandler::class)->handle(new CorrectShiftCommand(
        shiftEntryUuid: registroAuditadoTramo($escenario['cuarta'], '2026-10-07 07:00'),
        clockedInAt: null,
        clockedOutAt: Instants::utc('2026-10-07 15:30'),
        reason: CorrectionReason::fromCode('OLVIDO_FICHAJE_SALIDA'),
        performedByUserId: $escenario['autor'],
    ))->shiftEntryUuid;
    resolve(CorrectShiftHandler::class)->handle(new CorrectShiftCommand(
        shiftEntryUuid: $v2,
        clockedInAt: Instants::utc('2026-10-07 06:30'),
        clockedOutAt: null,
        reason: CorrectionReason::fromCode('OLVIDO_FICHAJE_ENTRADA'),
        performedByUserId: $escenario['autor'],
    ));

    // ...y una correccion de hoy sobre un tramo de hace trece dias: fuera de la
    // ventana diaria por su jornada, dentro por el asiento de la correccion. El
    // tramo viejo se da de alta a mano: el quiosco de la prueba se emparejo hoy
    // y no admite un fichaje anterior.
    resolve(AddShiftEntryHandler::class)->handle(new AddShiftEntryCommand(
        employeeUuid: $escenario['cuarta'],
        workDate: '2026-09-25',
        clockedInAt: Instants::utc('2026-09-25 07:00'),
        clockedOutAt: Instants::utc('2026-09-25 15:00'),
        reason: CorrectionReason::fromCode('OLVIDO_FICHAJE_ENTRADA'),
        performedByUserId: $escenario['autor'],
    ));
    resolve(CorrectShiftHandler::class)->handle(new CorrectShiftCommand(
        shiftEntryUuid: registroAuditadoTramo($escenario['cuarta'], '2026-09-25 07:00'),
        clockedInAt: null,
        clockedOutAt: Instants::utc('2026-09-25 15:15'),
        reason: CorrectionReason::fromCode('OLVIDO_FICHAJE_SALIDA'),
        performedByUserId: $escenario['autor'],
    ));

    return ['corregido' => $corregido, 'sustituido' => $original, 'anulado' => $anulado, 'abierto' => $abierto, 'alta' => $alta];
}

/**
 * @return array{code: int, output: string}
 */
function registroAuditadoConciliar(bool $completa = false): array
{
    $code = Artisan::call('compliance:reconcile-work-record', $completa ? ['--full' => true] : []);

    return ['code' => $code, 'output' => Artisan::output()];
}

function registroAuditadoMetricas(string $directorio, string $alcance): string
{
    $fichero = $directorio.'/kronoqr_work_record_reconciliation_'.$alcance.'.prom';

    expect(is_file($fichero))->toBeTrue('La conciliacion no ha publicado '.$fichero.'.');

    return (string) file_get_contents($fichero);
}

it('da cero discrepancias con el uso normal del producto: fichar, corregir, anular y dar de alta', function (): void {
    $escenario = registroAuditadoEscenario();
    $tramos = registroAuditadoLegitimo($escenario);

    // El registro tiene lo que tiene que tener: catorce tramos —cinco de A y
    // B, cuatro de C, cinco de D—, con versiones sustituidas, un anulado, una
    // pausa, un turno de noche y un cierre anomalo.
    expect(DB::table('shift_entries')->count())->toBe(14)
        ->and(DB::table('shift_entries')->where('status', 'anomalous')->count())->toBe(1)
        ->and(DB::table('shift_entries')->where('status', 'superseded')->count())->toBe(4)
        ->and(DB::table('shift_entries')->max('version'))->toBe(3)
        ->and(DB::table('shift_entries')->where('uuid', $tramos['sustituido'])->value('status'))->toBe('superseded')
        ->and(DB::table('shift_entries')->where('uuid', $tramos['anulado'])->value('status'))->toBe('voided');

    $diaria = registroAuditadoConciliar();
    $completa = registroAuditadoConciliar(true);

    expect($diaria['code'])->toBe(0, $diaria['output'])
        ->and($diaria['output'])->toContain('14 tramos conciliados')
        ->and($completa['code'])->toBe(0, $completa['output'])
        ->and($completa['output'])->toContain('14 tramos conciliados');

    $metricas = registroAuditadoMetricas($escenario['metricas'], 'recent');

    expect($metricas)->toContain('work_record_reconciliation_discrepancies{scope="recent",kind="entry_without_audit"} 0')
        ->and($metricas)->toContain('work_record_reconciliation_discrepancies{scope="recent",kind="audit_without_entry"} 0')
        ->and($metricas)->toContain('work_record_reconciliation_entries_checked{scope="recent"} 14')
        ->and($metricas)->toContain('work_record_reconciliation_last_run_timestamp_seconds{scope="recent"} '.Instants::utc(WORK_RECORD_RECONCILIATION_AHORA)->getTimestamp())
        ->and(registroAuditadoMetricas($escenario['metricas'], 'full'))->toContain('work_record_reconciliation_entries_checked{scope="full"} 14');
})->group('RL-04', 'RS-07', 'RN-13');

it('detecta las cuatro manipulaciones con la credencial de la aplicacion, con la cadena de hash en verde', function (): void {
    $escenario = registroAuditadoEscenario();
    $tramos = registroAuditadoLegitimo($escenario);

    // 1. UPDATE: la entrada de la version vigente, una hora antes. Los minutos
    //    no se tocan y siguen siendo los del asiento: sale solo la entrada.
    DB::update("UPDATE shift_entries SET clocked_in_at = clocked_in_at - interval '1 hour' WHERE uuid = ?", [$tramos['corregido']]);

    // 2. INSERT: un tramo inventado para B, que no solapa con nada suyo.
    $inventado = Str::uuid7()->toString();
    DB::table('shift_entries')->insert([
        'uuid' => $inventado,
        'employee_id' => AttendanceFixtures::employeeIdOf($escenario['otra']),
        'site_id' => $escenario['site'],
        'work_date' => '2026-10-07',
        'clocked_in_at' => '2026-10-07 08:00:00+00',
        'clocked_out_at' => '2026-10-07 12:00:00+00',
        'duration_minutes' => 240,
        'status' => 'closed',
        'clock_in_source' => 'qr_kiosk',
        'clock_out_source' => 'qr_kiosk',
        'version' => 1,
        'created_at' => '2026-10-07 12:00:00+00',
        'updated_at' => '2026-10-07 12:00:00+00',
    ]);

    // 3. DELETE: el alta manual, despues de quitar lo que la referencia.
    $alta = DB::table('shift_entries')->where('uuid', $tramos['alta'])->value('id');
    DB::table('shift_corrections')->where('shift_entry_id', $alta)->delete();
    DB::table('shift_entries')->where('id', $alta)->delete();

    // 4. UPDATE status: el turno abierto de B, anulado sin correccion.
    DB::table('shift_entries')->where('uuid', $tramos['abierto'])->update(['status' => 'voided']);

    // La cadena no lo ve: nadie ha tocado `audit_log`.
    expect(Artisan::call('compliance:verify-audit-chain'))->toBe(0);

    foreach ([false, true] as $completa) {
        $resultado = registroAuditadoConciliar($completa);

        expect($resultado['code'])->toBe(1)
            ->and($resultado['output'])->toContain('4 discrepancia(s)')
            ->and($resultado['output'])->toContain('shift_entries '.$tramos['corregido'].' · entry_differs_from_audit · clocked_in_at · audit_log #')
            ->and($resultado['output'])->toContain('shift_entries '.$inventado.' · entry_without_audit')
            ->and($resultado['output'])->toContain('shift_entries '.$tramos['alta'].' · audit_without_entry')
            ->and($resultado['output'])->toContain('shift_entries '.$tramos['abierto'].' · retired_without_correction · status')
            ->and($resultado['output'])->toContain('discrepancia-registro-auditoria.md')
            // Identificadores y nombres de campo, nunca nombres (regla dura 21).
            ->and($resultado['output'])->not->toContain(WORK_RECORD_RECONCILIATION_APELLIDO)
            ->and($resultado['output'])->not->toContain('Remedios');
    }

    foreach (['recent', 'full'] as $alcance) {
        $metricas = registroAuditadoMetricas($escenario['metricas'], $alcance);

        foreach (['entry_without_audit', 'entry_differs_from_audit', 'retired_without_correction', 'audit_without_entry'] as $tipo) {
            expect($metricas)->toContain('work_record_reconciliation_discrepancies{scope="'.$alcance.'",kind="'.$tipo.'"} 1');
        }

        expect($metricas)->not->toContain(WORK_RECORD_RECONCILIATION_APELLIDO)
            ->and($metricas)->not->toContain($escenario['otra']);
    }
})->group('RL-04', 'RS-07', 'RN-13');

it('vuelve a cuadrar cuando se remedia desde el panel, como dice el runbook, y deja la traza', function (): void {
    // docs/runbooks/discrepancia-registro-auditoria.md §3: una hora manipulada
    // se corrige desde el panel a la del asiento, y un tramo inventado se anula.
    // Las dos quedan firmadas y con el valor manipulado como «antes».
    $escenario = registroAuditadoEscenario();
    $tramos = registroAuditadoLegitimo($escenario);

    DB::update("UPDATE shift_entries SET clocked_in_at = clocked_in_at - interval '1 hour' WHERE uuid = ?", [$tramos['corregido']]);
    $inventado = Str::uuid7()->toString();
    DB::table('shift_entries')->insert([
        'uuid' => $inventado,
        'employee_id' => AttendanceFixtures::employeeIdOf($escenario['otra']),
        'site_id' => $escenario['site'],
        'work_date' => '2026-10-07',
        'clocked_in_at' => '2026-10-07 08:00:00+00',
        'clocked_out_at' => '2026-10-07 12:00:00+00',
        'duration_minutes' => 240,
        'status' => 'closed',
        'clock_in_source' => 'qr_kiosk',
        'clock_out_source' => 'qr_kiosk',
        'version' => 1,
        'created_at' => '2026-10-07 12:00:00+00',
        'updated_at' => '2026-10-07 12:00:00+00',
    ]);

    expect(registroAuditadoConciliar()['code'])->toBe(1);

    resolve(CorrectShiftHandler::class)->handle(new CorrectShiftCommand(
        shiftEntryUuid: $tramos['corregido'],
        clockedInAt: Instants::utc('2026-10-07 06:00'),
        clockedOutAt: null,
        reason: CorrectionReason::fromCode('OTROS', 'Incidente de seguridad: hora devuelta a la del asiento.'),
        performedByUserId: $escenario['autor'],
    ));
    resolve(VoidShiftHandler::class)->handle(new VoidShiftCommand(
        shiftEntryUuid: $inventado,
        reason: CorrectionReason::fromCode('OTROS', 'Incidente de seguridad: tramo que nadie ficho.'),
        performedByUserId: $escenario['autor'],
    ));

    $diaria = registroAuditadoConciliar();

    expect($diaria['code'])->toBe(0, $diaria['output'])
        ->and(registroAuditadoConciliar(true)['code'])->toBe(0)
        // La traza: dos correcciones firmadas mas, con el «antes» manipulado.
        ->and(DB::table('shift_corrections')->where('reason_code', 'OTROS')->count())->toBe(2);
})->group('RL-04', 'RS-07', 'RN-13');

it('la ventana diaria no mira lo antiguo y la pasada completa si', function (): void {
    $escenario = registroAuditadoEscenario();
    $tramos = registroAuditadoLegitimo($escenario);

    // Doce dias despues, el alta del 6 de octubre y todos sus asientos (el
    // ultimo, del 8) estan fuera de la ventana de siete dias, que empieza el 13
    // para los tramos y el 11 para los asientos. Alguien la borra.
    FrozenTime::at('2026-10-20 04:15:00');
    $alta = DB::table('shift_entries')->where('uuid', $tramos['alta'])->value('id');
    DB::table('shift_corrections')->where('shift_entry_id', $alta)->delete();
    DB::table('shift_entries')->where('id', $alta)->delete();

    expect(registroAuditadoConciliar()['code'])->toBe(0);

    $completa = registroAuditadoConciliar(true);

    expect($completa['code'])->toBe(1)
        ->and($completa['output'])->toContain('shift_entries '.$tramos['alta'].' · audit_without_entry');

    // Y la diaria de despues no apaga lo que encontro la semanal: cada una
    // publica su propio fichero.
    registroAuditadoConciliar();

    expect(registroAuditadoMetricas($escenario['metricas'], 'full'))
        ->toContain('work_record_reconciliation_discrepancies{scope="full",kind="audit_without_entry"} 1');
})->group('RL-04', 'RS-07');

/**
 * La purga real del registro —la misma SQL que `compliance:apply-retention`— y
 * su asiento `retention.purge_executed`, fechado en el «ahora» de la prueba.
 * Sin `$borrar`, solo el asiento: lo que haria quien quiere tapar un borrado.
 */
function registroAuditadoPurgar(int $site, string $corte, int $anos, bool $borrar = true): void
{
    DB::transaction(static function () use ($site, $corte, $anos, $borrar): void {
        $tallies = $borrar
            ? new DatabaseWorkRecordArchive(DB::connection())->purge(new DateTimeImmutable($corte.'T00:00:00Z'), 1000)
            : [];

        resolve(RecordAuditEntry::class)->handle(new RecordAuditEntryCommand(
            actor: AuditActor::system(),
            action: AuditAction::RetentionPurgeExecuted,
            subject: AuditSubject::of('retention'),
            payload: AuditPayload::of([
                'scope' => 'work_records',
                'cutoff_date' => $corte,
                'retention_years' => $anos,
                'site_id' => $site,
                'rows' => array_sum(array_map(static fn ($tally): int => $tally->rows, $tallies)),
            ]),
        ));
    });
}

it('no confunde una purga de retencion auditada con un borrado, y si el borrado que no lleva purga', function (): void {
    $escenario = registroAuditadoEscenario();
    $tramos = registroAuditadoLegitimo($escenario);

    // Un año despues, con un año de conservacion, la purga con corte el 7 de
    // octubre de 2026 es exactamente la que el producto podia hacer: se lleva
    // lo anterior (el alta del 6, el turno de noche y la pausa de C, el tramo
    // viejo de D) y deja su asiento.
    FrozenTime::at('2027-10-08 05:00:00');
    registroAuditadoPurgar($escenario['site'], '2026-10-07', 1);

    $tras = registroAuditadoConciliar(true);

    expect(DB::table('shift_entries')->where('uuid', $tramos['alta'])->exists())->toBeFalse()
        ->and($tras['code'])->toBe(0, $tras['output']);

    // Un borrado de un tramo posterior al corte no se puede disfrazar de purga.
    DB::table('scan_events')->where('shift_entry_id', DB::table('shift_entries')->where('uuid', $tramos['abierto'])->value('id'))->delete();
    DB::table('shift_entries')->where('uuid', $tramos['abierto'])->delete();

    $completa = registroAuditadoConciliar(true);

    expect($completa['code'])->toBe(1)
        ->and($completa['output'])->toContain('shift_entries '.$tramos['abierto'].' · audit_without_entry')
        ->and($completa['output'])->toContain('1 discrepancia(s)');
})->group('RL-04', 'RL-02', 'RS-07');

it('un asiento de purga con corte futuro no tapa un borrado y sale como discrepancia', function (): void {
    // La aplicacion tiene INSERT sobre audit_log y la cadena no lleva secreto:
    // quien borra un tramo con su credencial puede añadir despues un asiento de
    // purga bien encadenado con el corte que quiera. No cuenta.
    $escenario = registroAuditadoEscenario();
    $tramos = registroAuditadoLegitimo($escenario);

    $alta = DB::table('shift_entries')->where('uuid', $tramos['alta'])->value('id');
    DB::table('shift_corrections')->where('shift_entry_id', $alta)->delete();
    DB::table('shift_entries')->where('id', $alta)->delete();
    registroAuditadoPurgar($escenario['site'], '9999-12-31', 4, false);

    expect(Artisan::call('compliance:verify-audit-chain'))->toBe(0);

    foreach ([false, true] as $completa) {
        $resultado = registroAuditadoConciliar($completa);

        expect($resultado['code'])->toBe(1)
            ->and($resultado['output'])->toContain('2 discrepancia(s)')
            ->and($resultado['output'])->toContain('shift_entries '.$tramos['alta'].' · audit_without_entry')
            ->and($resultado['output'])->toMatch('/audit_log #\d+ · purge_out_of_bounds · cutoff_date/');
    }

    expect(registroAuditadoMetricas($escenario['metricas'], 'full'))
        ->toContain('work_record_reconciliation_discrepancies{scope="full",kind="purge_out_of_bounds"} 1');
})->group('RL-04', 'RL-02', 'RS-07');

it('detecta un origen cambiado en la exportacion legal y una correccion con otro autor u otro motivo', function (): void {
    $escenario = registroAuditadoEscenario();
    $tramos = registroAuditadoLegitimo($escenario);
    $otroAutor = ManagementUsers::withRole(UserRole::RRHH)->id;

    // El alta manual pasa por un fichaje con tarjeta.
    DB::table('shift_entries')->where('uuid', $tramos['alta'])->update(['clock_in_source' => 'qr_kiosk']);

    // La anulacion la firma otra persona.
    DB::table('shift_corrections')
        ->where('shift_entry_id', DB::table('shift_entries')->where('uuid', $tramos['anulado'])->value('id'))
        ->update(['performed_by_user_id' => $otroAutor]);

    // La correccion de A cambia de motivo.
    DB::table('shift_corrections')
        ->where('shift_entry_id', DB::table('shift_entries')->where('uuid', $tramos['corregido'])->value('id'))
        ->update(['reason_code' => 'AJUSTE_ACORDADO_CON_RRHH']);

    foreach ([false, true] as $completa) {
        $resultado = registroAuditadoConciliar($completa);

        expect($resultado['code'])->toBe(1)
            ->and($resultado['output'])->toContain('shift_entries '.$tramos['alta'].' · entry_differs_from_audit · clock_in_source')
            ->and($resultado['output'])->toContain('shift_entries '.$tramos['anulado'].' · retired_without_correction · shift_corrections')
            ->and($resultado['output'])->toContain('shift_entries '.$tramos['corregido'].' · entry_differs_from_audit · shift_corrections')
            // La version sustituida exige la misma fila, colgada de su sustituta.
            ->and($resultado['output'])->toContain('shift_entries '.$tramos['sustituido'].' · retired_without_correction · shift_corrections')
            ->and($resultado['output'])->toContain('4 discrepancia(s)');
    }
})->group('RL-04', 'RN-13', 'RS-07');
