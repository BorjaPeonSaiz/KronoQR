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
use App\Modules\Shared\Domain\ValueObject\UserRole;
use App\Modules\Workforce\Application\Command\UpdateSiteCommand;
use App\Modules\Workforce\Application\UseCase\UpdateSiteHandler;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\Attendance\AttendanceFixtures;
use Tests\Support\Attendance\FakeCredentialResolver;
use Tests\Support\Database\CommittedDatabase;
use Tests\Support\Database\SchemaOwner;
use Tests\Support\Http\Api;
use Tests\Support\Identity\ConsoleSignature;
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
 * EL ATACANTE USA LA CREDENCIAL MAS DEBIL QUE LE BASTA. Lo que `fichaje_app`
 * todavia puede hacer —insertar un tramo, anularlo sin correccion y, hasta el
 * bloque 7, borrarlo— se hace con la conexion de la aplicacion: es lo que los
 * privilegios no impiden y solo esta conciliacion detecta. Lo que desde ADR-057
 * §1 y §7 (bloque 6 de la 2.2.1) el motor le niega con `42501` —cambiar la
 * entrada de un tramo, su origen, el autor o el motivo de una correccion, la
 * jurisdiccion del perfil— se hace con el rol propietario del esquema, que es
 * quien todavia puede: una credencial de migracion filtrada o una copia
 * restaurada manipulada. En los dos casos nadie toca `audit_log`, y la
 * verificacion de la cadena sigue en verde: es el hueco que esta conciliacion
 * cierra.
 *
 * `CommittedDatabase` y no `RefreshDatabase`: el rol propietario abre otra
 * sesion, y no veria lo que la transaccion envolvente de la prueba no ha
 * confirmado. El vaciado y la restauracion del perfil los hace el trait.
 */

uses(CommittedDatabase::class);

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
        // El gauge de purgas revisadas (ADR-057 enmienda del 10-10-2026 §6)
        // sale en los dos ficheros, tambien a cero: sin serie, la alerta
        // PurgaFueraDeLimitesRevisada no tendria con que comparar.
        ->and($metricas)->toContain('work_record_reconciliation_reviewed_purges{scope="recent",explains_deletions="true"} 0')
        ->and($metricas)->toContain('work_record_reconciliation_reviewed_purges{scope="recent",explains_deletions="false"} 0')
        ->and(registroAuditadoMetricas($escenario['metricas'], 'full'))->toContain('work_record_reconciliation_entries_checked{scope="full"} 14')
        ->and(registroAuditadoMetricas($escenario['metricas'], 'full'))->toContain('work_record_reconciliation_reviewed_purges{scope="full",explains_deletions="true"} 0')
        ->toContain('work_record_reconciliation_reviewed_purges{scope="full",explains_deletions="false"} 0');
})->group('RL-04', 'RS-07', 'RN-13');

it('detecta las cuatro manipulaciones, dos con la credencial de la aplicacion y dos con el rol propietario, con la cadena de hash en verde', function (): void {
    $escenario = registroAuditadoEscenario();
    $tramos = registroAuditadoLegitimo($escenario);

    // 1. UPDATE: la entrada de la version vigente, una hora antes. Los minutos
    //    no se tocan y siguen siendo los del asiento: sale solo la entrada. Con
    //    el rol propietario: la aplicacion ya no tiene esa columna (ADR-057 §1).
    //    Y saltandose el *trigger* de transiciones (§2), que tambien le para a el.
    SchemaOwner::bypassingTransitionGuards(static fn (ConnectionInterface $owner): int => $owner->update(
        "UPDATE shift_entries SET clocked_in_at = clocked_in_at - interval '1 hour' WHERE uuid = ?",
        [$tramos['corregido']],
    ));

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

    // 3. DELETE: el alta manual, despues de quitar lo que la referencia. Con el
    //    rol propietario: la aplicacion ya no tiene `DELETE` (ADR-057 §3).
    $alta = DB::table('shift_entries')->where('uuid', $tramos['alta'])->value('id');
    SchemaOwner::connection()->table('shift_corrections')->where('shift_entry_id', $alta)->delete();
    SchemaOwner::connection()->table('shift_entries')->where('id', $alta)->delete();

    // 4. UPDATE status: el turno abierto de B, anulado sin correccion. Con la
    //    aplicacion: `status` es una columna que el dominio escribe.
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

    SchemaOwner::bypassingTransitionGuards(static fn (ConnectionInterface $owner): int => $owner->update(
        "UPDATE shift_entries SET clocked_in_at = clocked_in_at - interval '1 hour' WHERE uuid = ?",
        [$tramos['corregido']],
    ));
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
    SchemaOwner::connection()->table('shift_corrections')->where('shift_entry_id', $alta)->delete();
    SchemaOwner::connection()->table('shift_entries')->where('id', $alta)->delete();

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
 * Lo que borra una purga del registro con ese corte y su asiento
 * `retention.purge_executed`, fechado en el «ahora» de la prueba. Sin
 * `$borrar`, solo el asiento: lo que haria quien quiere tapar un borrado.
 *
 * **El borrado lo hace el rol propietario, no la funcion de purga.** Desde
 * ADR-057 §3 (bloque 7 de la 2.2.1) la aplicacion purga por
 * `work_record_purge_batch`, que calcula su corte con el reloj de PostgreSQL y
 * no admitiria el de un «ahora» simulado en 2027; la funcion tiene sus propias
 * pruebas (`WorkRecordPurgeFunctionTest`, `ApplyRetentionCommandTest`). Lo que
 * se prueba aqui es la conciliacion, que solo ve filas que faltan y asientos:
 * borra las mismas filas que la funcion —tramos con `work_date` anterior al
 * corte y lo que cuelga de ellos, y lo que no cuelga de nada por
 * `LEAST(occurred_at, recorded_at)`— sin claves ajenas (`replica`) para no
 * tener que repetir el orden por generaciones. Borrado y asiento van en dos
 * sesiones, cosa que la purga real no hace y que aqui no cambia nada.
 */
function registroAuditadoPurgar(int $site, string $corte, int $anos, bool $borrar = true): void
{
    $rows = 0;

    if ($borrar) {
        SchemaOwner::bypassingTransitionGuards(static function (ConnectionInterface $owner) use ($corte, &$rows): void {
            $instante = $corte.' 00:00:00+00';
            $vencidos = 'SELECT id FROM shift_entries WHERE work_date < ?::date';

            $rows += $owner->delete('DELETE FROM shift_corrections WHERE shift_entry_id IN ('.$vencidos.')', [$corte]);
            $rows += $owner->delete('DELETE FROM incidents WHERE work_date < ?::date OR shift_entry_id IN ('.$vencidos.')', [$corte, $corte]);
            $rows += $owner->delete(
                'DELETE FROM scan_events WHERE shift_entry_id IN ('.$vencidos.') '
                .'OR (shift_entry_id IS NULL AND LEAST(occurred_at, recorded_at) < ?::timestamptz)',
                [$corte, $instante],
            );
            $rows += $owner->delete('DELETE FROM discarded_scan_reports WHERE LEAST(occurred_at, recorded_at) < ?::timestamptz', [$instante]);
            $rows += $owner->delete('DELETE FROM shift_entries WHERE work_date < ?::date', [$corte]);
            $rows += $owner->delete('DELETE FROM daily_totals WHERE work_date < ?::date', [$corte]);
        });
    }

    DB::transaction(static function () use ($site, $corte, $anos, $rows): void {
        resolve(RecordAuditEntry::class)->handle(new RecordAuditEntryCommand(
            actor: AuditActor::system(),
            action: AuditAction::RetentionPurgeExecuted,
            subject: AuditSubject::of('retention'),
            payload: AuditPayload::of([
                'scope' => 'work_records',
                'cutoff_date' => $corte,
                'retention_years' => $anos,
                'site_id' => $site,
                'rows' => $rows,
            ]),
        ));
    });
}

it('no confunde una purga de retencion auditada con un borrado, y si el borrado que no lleva purga', function (): void {
    $escenario = registroAuditadoEscenario();
    $tramos = registroAuditadoLegitimo($escenario);

    // Un año despues, con un año de conservacion, la purga con corte el 7 de
    // octubre de 2026 es exactamente la que el producto podia hacer EN UNA
    // JURISDICCION SIN SUELO LEGAL CONOCIDO (aqui Portugal, que no esta en el
    // catalogo y recibe el de omision, 1): se lleva lo anterior (el alta del 6,
    // el turno de noche y la pausa de C, el tramo viejo de D) y deja su
    // asiento. En España esa purga es no admisible (RL-02): la prueba de abajo.
    //
    // No se usa España con cuatro años porque el esquema de pruebas solo tiene
    // particiones de audit_log para este año y el siguiente.
    SchemaOwner::connection()->table('compliance_profiles')->update(['jurisdiction' => 'PT']);
    FrozenTime::at('2027-10-08 05:00:00');
    registroAuditadoPurgar($escenario['site'], '2026-10-07', 1);

    $tras = registroAuditadoConciliar(true);

    expect(DB::table('shift_entries')->where('uuid', $tramos['alta'])->exists())->toBeFalse()
        ->and($tras['code'])->toBe(0, $tras['output']);

    // Un borrado de un tramo posterior al corte no se puede disfrazar de purga.
    SchemaOwner::connection()->table('scan_events')->where('shift_entry_id', DB::table('shift_entries')->where('uuid', $tramos['abierto'])->value('id'))->delete();
    SchemaOwner::connection()->table('shift_entries')->where('uuid', $tramos['abierto'])->delete();

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
    SchemaOwner::connection()->table('shift_corrections')->where('shift_entry_id', $alta)->delete();
    SchemaOwner::connection()->table('shift_entries')->where('id', $alta)->delete();
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
    SchemaOwner::bypassingTransitionGuards(static fn (ConnectionInterface $owner): int => $owner->table('shift_entries')
        ->where('uuid', $tramos['alta'])
        ->update(['clock_in_source' => 'qr_kiosk']));

    // La anulacion la firma otra persona.
    SchemaOwner::connection()->table('shift_corrections')
        ->where('shift_entry_id', DB::table('shift_entries')->where('uuid', $tramos['anulado'])->value('id'))
        ->update(['performed_by_user_id' => $otroAutor]);

    // La correccion de A cambia de motivo.
    SchemaOwner::connection()->table('shift_corrections')
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

// --- Bloque 5 de la 2.2.1: el suelo legal español y la purga revisada ------

/**
 * El rastro de un tramo de una jornada antigua que ya no esta en
 * `shift_entries`, como si lo hubiera borrado una purga. Se escribe a mano
 * porque el esquema de pruebas solo tiene particiones de `audit_log` para este
 * año y el siguiente, y una purga a tres años tiene que llevarse jornadas de
 * tres años atras.
 */
function registroAuditadoTramoAntiguo(int $site, string $jornada): string
{
    $uuid = Str::uuid7()->toString();

    resolve(RecordAuditEntry::class)->handle(new RecordAuditEntryCommand(
        actor: AuditActor::system(),
        action: AuditAction::ShiftEntryCreated,
        subject: AuditSubject::of('shift_entry'),
        payload: AuditPayload::of([
            'employee_uuid' => Str::uuid7()->toString(),
            'shift_entry_uuid' => $uuid,
            'site_id' => $site,
            'work_date' => $jornada,
            'clocked_in_at' => $jornada.'T06:00:00.000000Z',
            'origin' => 'qr_kiosk',
            'action' => 'clock_in',
        ]),
    ));

    return $uuid;
}

it('denuncia una purga española a tres años, y tras revisarla deja de contarla sin borrarla', function (): void {
    // RL-02: en España la purga a tres años no es admisible aunque su corte
    // sea el que esos tres años permitian. Se denuncia, y lo que borro sale
    // como borrado. Una persona la investiga y la da por revisada: deja de
    // contar como pendiente, vuelve a explicar lo que borro antes de su corte,
    // el asiento original sigue en audit_log y la cadena sigue integra.
    $escenario = registroAuditadoEscenario();
    $antiguo = registroAuditadoTramoAntiguo($escenario['site'], '2024-03-01');

    FrozenTime::at('2027-10-08 05:00:00');
    registroAuditadoPurgar($escenario['site'], '2024-10-08', 3, false);
    $maximo = DB::table('audit_log')->where('action', 'retention.purge_executed')->max('id');
    $purga = is_numeric($maximo) ? (int) $maximo : 0;

    $antes = registroAuditadoConciliar(true);

    expect($antes['code'])->toBe(1)
        ->and($antes['output'])->toContain('2 discrepancia(s)')
        ->and($antes['output'])->toContain('audit_log #'.$purga.' · purge_out_of_bounds · retention_years')
        ->and($antes['output'])->toContain('shift_entries '.$antiguo.' · audit_without_entry')
        ->and(registroAuditadoMetricas($escenario['metricas'], 'full'))
        ->toContain('work_record_reconciliation_discrepancies{scope="full",kind="purge_out_of_bounds"} 1')
        ->toContain('work_record_reconciliation_reviewed_purges{scope="full",explains_deletions="true"} 0')
        ->toContain('work_record_reconciliation_reviewed_purges{scope="full",explains_deletions="false"} 0');

    $revision = Artisan::call('compliance:reconcile-work-record', [
        '--acknowledge' => (string) $purga,
        '--reason' => 'INC-2027-14: el perfil se bajo a 3 años por error; se ha subido a 4 y se ha informado a la DPO.',
        ...ConsoleSignature::for($escenario['autor']),
    ]);

    expect($revision)->toBe(0, Artisan::output());

    $despues = registroAuditadoConciliar(true);

    expect($despues['code'])->toBe(0, $despues['output'])
        ->and($despues['output'])->toContain('purgas fuera de limites revisadas: 1 (no cuentan)')
        ->and(registroAuditadoMetricas($escenario['metricas'], 'full'))
        ->toContain('work_record_reconciliation_discrepancies{scope="full",kind="purge_out_of_bounds"} 0')
        ->toContain('work_record_reconciliation_discrepancies{scope="full",kind="audit_without_entry"} 0')
        ->toContain('work_record_reconciliation_reviewed_purges{scope="full",explains_deletions="true"} 1')
        ->toContain('work_record_reconciliation_reviewed_purges{scope="full",explains_deletions="false"} 0')
        // La purga revisada sigue en audit_log, con su revision detras.
        ->and(DB::table('audit_log')->where('id', $purga)->value('action'))->toBe('retention.purge_executed')
        ->and(DB::table('audit_log')->where('action', 'retention.purge_reviewed')->count())->toBe(1)
        ->and(Artisan::call('compliance:verify-audit-chain'))->toBe(0, Artisan::output());

    // Una jornada posterior al corte borrada despues sigue sin explicarse, y
    // una purga posterior igual de corta, sin revisar, sigue contando.
    $posterior = registroAuditadoTramoAntiguo($escenario['site'], '2024-10-08');
    FrozenTime::at('2027-11-08 05:00:00');
    registroAuditadoPurgar($escenario['site'], '2024-09-08', 3, false);

    $otra = registroAuditadoConciliar(true);

    expect($otra['code'])->toBe(1)
        ->and($otra['output'])->toContain('2 discrepancia(s)')
        ->and($otra['output'])->toContain('purge_out_of_bounds · retention_years')
        ->and($otra['output'])->toContain('shift_entries '.$posterior.' · audit_without_entry')
        ->and($otra['output'])->toContain('purgas fuera de limites revisadas: 1 (no cuentan)');
})->group('RS-07', 'RL-04', 'RL-02');

/**
 * El escenario con una correccion de zona por la orden de consola, que deja su
 * fila en la traza y su asiento firmado.
 *
 * @return array{site: int, metricas: string}
 */
function registroAuditadoZonaCorregida(): array
{
    $escenario = registroAuditadoEscenario();
    registroAuditadoLegitimo($escenario);

    $orden = Artisan::call('workforce:correct-site-timezone', [
        'timezone' => 'Atlantic/Canary',
        '--reason' => 'La puesta en marcha se hizo con Madrid y el hotel esta en Tenerife.',
        ...ConsoleSignature::for($escenario['autor']),
        '--force' => true,
    ]);

    expect($orden)->toBe(0, Artisan::output());

    $limpia = registroAuditadoConciliar(true);

    expect($limpia['code'])->toBe(0, $limpia['output'])
        ->and(DB::table('site_timezone_changes')->count())->toBe(1);

    return ['site' => $escenario['site'], 'metricas' => $escenario['metricas']];
}

it('denuncia la zona del centro cambiada por la funcion de la correccion fuera de la orden de consola', function (): void {
    // RN-23, bloque 2 de la 2.3.0. La orden de consola corrige la zona por
    // `site_correct_timezone` y deja `site.updated`: cuadra. La misma funcion
    // llamada a pelo con la credencial de la aplicacion —que tiene `EXECUTE`—
    // cambia la zona sin asiento: la zona ya no es la del asiento y la traza
    // tiene una fila sin firma.
    $escenario = registroAuditadoZonaCorregida();

    DB::select('SELECT public.site_correct_timezone(?::bigint, ?::text, ?::text)', [$escenario['site'], 'Atlantic/Canary', 'Europe/Lisbon']);

    /** @var int $asiento */
    $asiento = DB::table('audit_log')->where('action', 'site.updated')->max('id');

    foreach ([false, true] as $completa) {
        $pasada = registroAuditadoConciliar($completa);

        expect($pasada['code'])->toBe(1, $pasada['output'])
            ->and($pasada['output'])->toContain('site_timezone_differs_from_audit: 2')
            ->and($pasada['output'])->toContain('sites #'.$escenario['site'].' · site_timezone_differs_from_audit · timezone · audit_log #'.$asiento)
            ->and($pasada['output'])->toContain('sites #'.$escenario['site'].' · site_timezone_differs_from_audit · site_timezone_changes')
            // Nombres de campo, nunca valores.
            ->and($pasada['output'])->not->toContain('Lisbon');
    }

    expect(registroAuditadoMetricas($escenario['metricas'], 'recent'))
        ->toContain('work_record_reconciliation_discrepancies{scope="recent",kind="site_timezone_differs_from_audit"} 2')
        ->and(registroAuditadoMetricas($escenario['metricas'], 'full'))
        ->toContain('work_record_reconciliation_discrepancies{scope="full",kind="site_timezone_differs_from_audit"} 2');
})->group('RN-23', 'RL-04', 'RS-07');

it('denuncia un cambio de zona de ida y vuelta por la funcion, aunque la zona vuelva a ser la de su asiento', function (): void {
    // A->B->A entre dos pasadas: la zona coincide otra vez con el asiento, pero
    // la traza solo-append guarda los dos pasos y ninguno tiene asiento firmado.
    $escenario = registroAuditadoZonaCorregida();

    DB::select('SELECT public.site_correct_timezone(?::bigint, ?::text, ?::text)', [$escenario['site'], 'Atlantic/Canary', 'Europe/Lisbon']);
    DB::select('SELECT public.site_correct_timezone(?::bigint, ?::text, ?::text)', [$escenario['site'], 'Europe/Lisbon', 'Atlantic/Canary']);

    $pasada = registroAuditadoConciliar();

    expect(DB::table('sites')->where('id', $escenario['site'])->value('timezone'))->toBe('Atlantic/Canary')
        ->and($pasada['code'])->toBe(1, $pasada['output'])
        ->and($pasada['output'])->toContain('site_timezone_differs_from_audit: 2')
        ->and($pasada['output'])->not->toContain('· timezone ·');
})->group('RN-23', 'RL-04', 'RS-07');

it('denuncia un site.updated que cambia la zona despues del primer tramo sin la firma de la correccion', function (): void {
    // El panel no lo puede escribir (409): un asiento asi lo ha escrito otro
    // con la credencial de la aplicacion, que puede insertar en audit_log.
    $escenario = registroAuditadoZonaCorregida();

    DB::transaction(static function () use ($escenario): void {
        foreach (['Atlantic/Canary', 'Europe/Madrid'] as $antes) {
            resolve(RecordAuditEntry::class)->handle(new RecordAuditEntryCommand(
                actor: AuditActor::system(),
                action: AuditAction::SiteUpdated,
                subject: AuditSubject::of('site', $escenario['site']),
                payload: AuditPayload::of([
                    'previous_value' => ['name' => 'Hotel', 'timezone' => $antes],
                    'new_value' => ['name' => 'Hotel', 'timezone' => 'Atlantic/Canary'],
                ]),
            ));
        }
    });

    /** @var int $asiento */
    $asiento = DB::table('audit_log')->where('action', 'site.updated')->max('id');

    $pasada = registroAuditadoConciliar(true);

    // Solo el segundo: el primero no cambia la zona.
    expect($pasada['code'])->toBe(1, $pasada['output'])
        ->and($pasada['output'])->toContain('site_timezone_differs_from_audit: 1')
        ->and($pasada['output'])->toContain('sites #'.$escenario['site'].' · site_timezone_differs_from_audit · correction · audit_log #'.$asiento);
})->group('RN-23', 'RL-04', 'RS-07');

it('dice que la zona no se puede verificar si el centro no tiene ningun asiento, sin fallar', function (): void {
    // El centro de las pruebas lo crean los ayudantes sin asiento
    // `site.created`: es el caso de una instalacion anterior a la tarea 5.5.
    $escenario = registroAuditadoEscenario();
    registroAuditadoLegitimo($escenario);

    $pasada = registroAuditadoConciliar(true);

    expect($pasada['code'])->toBe(0, $pasada['output'])
        ->and($pasada['output'])->toContain('zona del centro no verificable: 1 centro(s) sin asiento site.*')
        ->and(registroAuditadoMetricas($escenario['metricas'], 'full'))
        ->toContain('work_record_reconciliation_unverifiable{scope="full",subject="site_timezone"} 1')
        ->toContain('work_record_reconciliation_unverifiable{scope="full",subject="site_timezone_change"} 0');
})->group('RN-23', 'RL-04');

it('da por buena la zona cambiada desde el panel antes del primer tramo', function (): void {
    // RN-23: sin registro horario el PATCH /site es el camino normal. Sus
    // `site.updated` no llevan firma y van antes del primer tramo en la cadena.
    WorkforceFixtures::site();
    app(UpdateSiteHandler::class)->handle(new UpdateSiteCommand(timezone: 'Atlantic/Canary'));
    app(UpdateSiteHandler::class)->handle(new UpdateSiteCommand(timezone: 'Europe/Madrid'));

    $escenario = registroAuditadoEscenario();
    registroAuditadoLegitimo($escenario);

    $pasada = registroAuditadoConciliar(true);

    expect($pasada['code'])->toBe(0, $pasada['output'])
        ->and($pasada['output'])->not->toContain('site_timezone_differs_from_audit')
        ->and($pasada['output'])->not->toContain('no verificable');
})->group('RN-23', 'RL-04');

it('no denuncia lo anterior a la linea base, y no usa una correccion de entonces para justificar una fila de hoy', function (): void {
    // Una instalacion que viene de la 2.1.0 o la 2.2.0: el panel cambiaba la
    // zona con fichajes hechos, sin firma, y la 2.2.1 corregia por consola sin
    // dejar fila en la traza. La migracion de la 2.3.0 fija la linea base en
    // el ultimo asiento: lo anterior es historia, no discrepancia.
    $escenario = registroAuditadoEscenario();
    registroAuditadoLegitimo($escenario);

    DB::transaction(static function () use ($escenario): void {
        $asientos = [
            // El panel de la 2.2.0, con fichajes hechos y sin firma.
            ['previous_value' => ['name' => 'Hotel', 'timezone' => 'Europe/Madrid'], 'new_value' => ['name' => 'Hotel', 'timezone' => 'Europe/Lisbon']],
            ['previous_value' => ['name' => 'Hotel', 'timezone' => 'Europe/Lisbon'], 'new_value' => ['name' => 'Hotel', 'timezone' => 'Europe/Madrid']],
            // Una correccion de la 2.2.1, firmada y sin fila en la traza.
            [
                'previous_value' => ['name' => 'Hotel', 'timezone' => 'Europe/Madrid'], 'new_value' => ['name' => 'Hotel', 'timezone' => 'Atlantic/Canary'],
                'via' => 'console', 'reason' => 'Se puso Madrid y era Canarias', 'shift_entry_count' => 3, 'responsible_user_id' => $escenario['autor'],
            ],
            ['previous_value' => ['name' => 'Hotel', 'timezone' => 'Atlantic/Canary'], 'new_value' => ['name' => 'Hotel', 'timezone' => 'Europe/Madrid']],
        ];

        foreach ($asientos as $payload) {
            resolve(RecordAuditEntry::class)->handle(new RecordAuditEntryCommand(
                actor: AuditActor::system(),
                action: AuditAction::SiteUpdated,
                subject: AuditSubject::of('site', $escenario['site']),
                payload: AuditPayload::of($payload),
            ));
        }
    });

    // La linea base que habria fijado la migracion al actualizar: el ultimo
    // asiento. La escribe el rol propietario; el vaciado la restaura.
    SchemaOwner::connection()->update('UPDATE site_timezone_audit_baseline SET audit_log_id = (SELECT max(id) FROM audit_log)');

    $limpia = registroAuditadoConciliar(true);

    // Tres cambios sin firma tras el primer tramo, anteriores a la linea base.
    expect($limpia['code'])->toBe(0, $limpia['output'])
        ->and($limpia['output'])->toContain('zona del centro no verificable: 0 centro(s) sin asiento site.*, 3 cambio(s)');

    // La correccion de la 2.2.1 (Madrid -> Canarias) no justifica una fila de hoy
    // con el mismo antes y despues.
    DB::select('SELECT public.site_correct_timezone(?::bigint, ?::text, ?::text)', [$escenario['site'], 'Europe/Madrid', 'Atlantic/Canary']);

    /** @var int $fila */
    $fila = DB::table('site_timezone_changes')->max('id');
    $pasada = registroAuditadoConciliar(true);

    expect($pasada['code'])->toBe(1, $pasada['output'])
        ->and($pasada['output'])->toContain('site_timezone_differs_from_audit: 2')
        ->and($pasada['output'])->toContain('sites #'.$escenario['site'].' · site_timezone_differs_from_audit · site_timezone_changes #'.$fila);
})->group('RN-23', 'RL-04', 'RS-07');

// --- Revisar una discrepancia de la zona (--acknowledge-site-change) ------------

const WORK_RECORD_RECONCILIATION_MOTIVO_ZONA = 'Investigado en el incidente 2026-14: la llamo el equipo de soporte en una prueba.';

/**
 * @param  array<string, string|bool>  $opciones
 * @return array{code: int, output: string}
 */
function registroAuditadoRevisarZona(array $opciones): array
{
    $code = Artisan::call('compliance:reconcile-work-record', $opciones);

    return ['code' => $code, 'output' => Artisan::output()];
}

function registroAuditadoRevisionesDeZona(): int
{
    return DB::table('audit_log')->where('action', 'site.timezone_discrepancy_reviewed')->count();
}

it('da por revisada una fila de la traza sin firma: sale de las pendientes y se publica como revisada', function (): void {
    $escenario = registroAuditadoZonaCorregida();
    DB::select('SELECT public.site_correct_timezone(?::bigint, ?::text, ?::text)', [$escenario['site'], 'Atlantic/Canary', 'Europe/Lisbon']);
    DB::select('SELECT public.site_correct_timezone(?::bigint, ?::text, ?::text)', [$escenario['site'], 'Europe/Lisbon', 'Atlantic/Canary']);

    /** @var list<int> $filas */
    $filas = DB::table('site_timezone_changes')->orderBy('id')->pluck('id')->all();
    $responsable = ManagementUsers::withRole(UserRole::RRHH)->id;

    foreach ([$filas[1], $filas[2]] as $fila) {
        $revision = registroAuditadoRevisarZona([
            '--acknowledge-site-change' => 'change:'.$fila,
            '--reason' => WORK_RECORD_RECONCILIATION_MOTIVO_ZONA,
            ...ConsoleSignature::for($responsable),
        ]);

        expect($revision['code'])->toBe(0, $revision['output']);
    }

    /** @var object{actor_id: int|string, subject_type: string, payload: string} $asiento */
    $asiento = DB::table('audit_log')->where('action', 'site.timezone_discrepancy_reviewed')->orderByDesc('id')->first();
    $payload = json_decode($asiento->payload, true, 8, JSON_THROW_ON_ERROR);
    \assert(is_array($payload));

    expect((int) $asiento->actor_id)->toBe($responsable)
        ->and($asiento->subject_type)->toBe('site')
        ->and($payload['reviewed_site_timezone_change_id'])->toBe($filas[2])
        ->and($payload['reason'])->toBe(WORK_RECORD_RECONCILIATION_MOTIVO_ZONA)
        ->and($payload['responsible_user_id'])->toBe($responsable);

    $pasada = registroAuditadoConciliar();

    expect($pasada['code'])->toBe(0, $pasada['output'])
        ->and($pasada['output'])->toContain('discrepancias de la zona del centro revisadas: 2 de la traza, 0 de asientos sin firma (no cuentan)')
        ->and(registroAuditadoMetricas($escenario['metricas'], 'recent'))
        ->toContain('work_record_reconciliation_reviewed_site_timezone_discrepancies{scope="recent",target="change"} 2')
        ->toContain('work_record_reconciliation_discrepancies{scope="recent",kind="site_timezone_differs_from_audit"} 0');

    // Una revision no se repite.
    $otra = registroAuditadoRevisarZona([
        '--acknowledge-site-change' => 'change:'.$filas[1],
        '--reason' => WORK_RECORD_RECONCILIATION_MOTIVO_ZONA,
        ...ConsoleSignature::for($responsable),
    ]);

    expect($otra['code'])->toBe(1, $otra['output'])
        ->and($otra['output'])->toContain('ya está revisada')
        ->and(registroAuditadoRevisionesDeZona())->toBe(2);
})->group('RN-23', 'RL-04', 'RS-07');

it('da por revisado un site.updated sin firma por su asiento', function (): void {
    $escenario = registroAuditadoZonaCorregida();

    DB::transaction(static function () use ($escenario): void {
        resolve(RecordAuditEntry::class)->handle(new RecordAuditEntryCommand(
            actor: AuditActor::system(),
            action: AuditAction::SiteUpdated,
            subject: AuditSubject::of('site', $escenario['site']),
            payload: AuditPayload::of([
                'previous_value' => ['name' => 'Hotel', 'timezone' => 'Europe/Madrid'],
                'new_value' => ['name' => 'Hotel', 'timezone' => 'Atlantic/Canary'],
            ]),
        ));
    });

    /** @var int $asiento */
    $asiento = DB::table('audit_log')->where('action', 'site.updated')->max('id');

    $revision = registroAuditadoRevisarZona([
        '--acknowledge-site-change' => 'audit:'.$asiento,
        '--reason' => WORK_RECORD_RECONCILIATION_MOTIVO_ZONA,
        ...ConsoleSignature::for(ManagementUsers::withRole(UserRole::ADMIN)->id),
    ]);

    expect($revision['code'])->toBe(0, $revision['output'])
        ->and(registroAuditadoConciliar(true)['code'])->toBe(0);
})->group('RN-23', 'RL-04', 'RS-07');

it('no deja revisar una discrepancia de la zona a una cuenta sin rol admin ni rrhh', function (UserRole $rol): void {
    $escenario = registroAuditadoZonaCorregida();
    DB::select('SELECT public.site_correct_timezone(?::bigint, ?::text, ?::text)', [$escenario['site'], 'Atlantic/Canary', 'Europe/Lisbon']);

    /** @var int $fila */
    $fila = DB::table('site_timezone_changes')->max('id');

    $revision = registroAuditadoRevisarZona([
        '--acknowledge-site-change' => 'change:'.$fila,
        '--reason' => WORK_RECORD_RECONCILIATION_MOTIVO_ZONA,
        ...ConsoleSignature::for(ManagementUsers::withRole($rol)->id),
    ]);

    // Tambien en la vista previa, sin motivo.
    $previa = registroAuditadoRevisarZona([
        '--acknowledge-site-change' => 'change:'.$fila,
        ...ConsoleSignature::for(ManagementUsers::withRole($rol)->id),
    ]);

    expect($revision['code'])->toBe(2, $revision['output'])
        ->and($revision['output'])->toContain('admin, rrhh')
        ->and($previa['code'])->toBe(2, $previa['output'])
        ->and(registroAuditadoRevisionesDeZona())->toBe(0);
})->with([
    'responsable de departamento' => [UserRole::RESPONSABLE_DEPARTAMENTO],
    'auditor' => [UserRole::AUDITOR],
])->group('RN-23', 'RS-07');

it('se niega a revisar lo que no es una discrepancia pendiente, y con opciones mal escritas', function (array $opciones, int $codigo, string $texto): void {
    $escenario = registroAuditadoZonaCorregida();

    /** @var int $fila */
    $fila = DB::table('site_timezone_changes')->max('id');
    $opciones = array_map(static fn (string $valor): string => str_replace(':fila', (string) $fila, $valor), $opciones);

    $revision = registroAuditadoRevisarZona([
        '--reason' => WORK_RECORD_RECONCILIATION_MOTIVO_ZONA,
        ...ConsoleSignature::for(ManagementUsers::withRole(UserRole::RRHH)->id),
        ...$opciones,
    ]);

    expect($revision['code'])->toBe($codigo, $revision['output'])
        ->and($revision['output'])->toContain($texto)
        ->and(registroAuditadoRevisionesDeZona())->toBe(0);
})->with([
    // La fila de la correccion de consola tiene su asiento firmado: cuadra.
    'una fila que cuadra' => [['--acknowledge-site-change' => 'change::fila'], 1, 'no es una discrepancia pendiente'],
    'una fila que no existe' => [['--acknowledge-site-change' => 'change:999999'], 1, 'no es una discrepancia pendiente'],
    'sin prefijo' => [['--acknowledge-site-change' => '12'], 2, 'change:<id>'],
    'con --full' => [['--acknowledge-site-change' => 'change::fila', '--full' => '1'], 2, 'no se combina'],
    'motivo corto' => [['--acknowledge-site-change' => 'change::fila', '--reason' => 'corto'], 1, 'al menos'],
])->group('RN-23', 'RS-07');

it('detecta que alguien reescribio como termino un tramo, por fichaje y por correccion', function (): void {
    // RN-05 (2.3.0): `closed_by` decide si el tramo del dia siguiente continua
    // la jornada, asi que reescribirlo es manipular el registro. Se compara con
    // la `action` del cierre del quiosco y con el `after.closed_by` de la
    // ultima correccion.
    $escenario = registroAuditadoEscenario();
    $tramos = registroAuditadoLegitimo($escenario);
    $pausa = registroAuditadoTramo($escenario['tercera'], '2026-10-06 07:00');

    SchemaOwner::bypassingTransitionGuards(static function (ConnectionInterface $owner) use ($pausa, $tramos): void {
        $owner->update("UPDATE shift_entries SET closed_by = 'clock_out' WHERE uuid = ?", [$pausa]);
        $owner->update("UPDATE shift_entries SET closed_by = 'break_start' WHERE uuid = ?", [$tramos['corregido']]);
    });

    $resultado = registroAuditadoConciliar(true);

    expect($resultado['code'])->toBe(1)
        ->and($resultado['output'])->toContain('2 discrepancia(s)')
        ->and($resultado['output'])->toContain('shift_entries '.$pausa.' · entry_differs_from_audit · closed_by')
        ->and($resultado['output'])->toContain('shift_entries '.$tramos['corregido'].' · entry_differs_from_audit · closed_by');
})->group('RL-04', 'RS-07', 'RN-05');
