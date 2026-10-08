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

/** Apellido inconfundible: si aparece en la salida o en una metrica, se ha filtrado un nombre. */
const WORK_RECORD_RECONCILIATION_APELLIDO = 'Conciliadorez';

/**
 * Dos personas en un centro de Madrid, con su quiosco y su tarjeta.
 *
 * @return array{site: int, department: int, employee: string, device: int, deviceUuid: string, token: string, otra: string, autor: int, metricas: string}
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

    FrozenTime::at(WORK_RECORD_RECONCILIATION_AHORA);

    app()->instance(
        CredentialResolver::class,
        FakeCredentialResolver::new()
            ->resolving(WORK_RECORD_RECONCILIATION_TARJETA_A, $escenario['employee'])
            ->resolving(WORK_RECORD_RECONCILIATION_TARJETA_B, $otra),
    );

    $metricas = sys_get_temp_dir().'/kronoqr-conciliacion-'.bin2hex(random_bytes(6));
    Config::set('observability.metrics.textfile_path', $metricas);
    Config::set('observability.metrics.enabled', true);

    return [
        ...$escenario,
        'otra' => $otra,
        'autor' => ManagementUsers::withRole(UserRole::RRHH)->id,
        'metricas' => $metricas,
    ];
}

/**
 * @param  array{token: string}  $escenario
 */
function registroAuditadoFichar(array $escenario, string $tarjeta, string $occurredAt): void
{
    $scanId = Str::uuid7()->toString();

    Api::as($escenario['token'])
        ->withHeaders(['Idempotency-Key' => $scanId])
        ->post('/api/v1/scan', ['scan_id' => $scanId, 'occurred_at' => $occurredAt, 'qr_payload' => $tarjeta])
        ->assertOk();
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
 * @param  array{site: int, department: int, employee: string, device: int, deviceUuid: string, token: string, otra: string, autor: int, metricas: string}  $escenario
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

    // El registro tiene lo que tiene que tener: cinco tramos, dos de ellos
    // retirados del conjunto vigente con su correccion.
    expect(DB::table('shift_entries')->count())->toBe(5)
        ->and(DB::table('shift_entries')->where('uuid', $tramos['sustituido'])->value('status'))->toBe('superseded')
        ->and(DB::table('shift_entries')->where('uuid', $tramos['anulado'])->value('status'))->toBe('voided');

    $diaria = registroAuditadoConciliar();
    $completa = registroAuditadoConciliar(true);

    expect($diaria['code'])->toBe(0, $diaria['output'])
        ->and($diaria['output'])->toContain('5 tramos conciliados')
        ->and($completa['code'])->toBe(0, $completa['output'])
        ->and($completa['output'])->toContain('5 tramos conciliados');

    $metricas = registroAuditadoMetricas($escenario['metricas'], 'recent');

    expect($metricas)->toContain('work_record_reconciliation_discrepancies{scope="recent",kind="entry_without_audit"} 0')
        ->and($metricas)->toContain('work_record_reconciliation_discrepancies{scope="recent",kind="audit_without_entry"} 0')
        ->and($metricas)->toContain('work_record_reconciliation_entries_checked{scope="recent"} 5')
        ->and($metricas)->toContain('work_record_reconciliation_last_run_timestamp_seconds{scope="recent"} '.Instants::utc(WORK_RECORD_RECONCILIATION_AHORA)->getTimestamp())
        ->and(registroAuditadoMetricas($escenario['metricas'], 'full'))->toContain('work_record_reconciliation_entries_checked{scope="full"} 5');
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

it('no confunde una purga de retencion auditada con un borrado, y si el borrado que no lleva purga', function (): void {
    $escenario = registroAuditadoEscenario();
    $tramos = registroAuditadoLegitimo($escenario);

    // La purga real del registro (la misma SQL que `compliance:apply-retention`)
    // con corte el 7 de octubre: se lleva el alta del 6 y deja su asiento.
    DB::transaction(function (): void {
        $corte = new DateTimeImmutable('2026-10-07T00:00:00Z');
        $tallies = new DatabaseWorkRecordArchive(DB::connection())->purge($corte, 1000);

        resolve(RecordAuditEntry::class)->handle(new RecordAuditEntryCommand(
            actor: AuditActor::system(),
            action: AuditAction::RetentionPurgeExecuted,
            subject: AuditSubject::of('retention'),
            payload: AuditPayload::of([
                'scope' => 'work_records',
                'cutoff_date' => $corte->format('Y-m-d'),
                'retention_years' => 4,
                'rows' => array_sum(array_map(static fn ($tally): int => $tally->rows, $tallies)),
            ]),
        ));
    });

    expect(DB::table('shift_entries')->where('uuid', $tramos['alta'])->exists())->toBeFalse()
        ->and(registroAuditadoConciliar(true)['code'])->toBe(0);

    // Un borrado de un tramo posterior al corte no se puede disfrazar de purga.
    DB::table('scan_events')->where('shift_entry_id', DB::table('shift_entries')->where('uuid', $tramos['abierto'])->value('id'))->delete();
    DB::table('shift_entries')->where('uuid', $tramos['abierto'])->delete();

    $completa = registroAuditadoConciliar(true);

    expect($completa['code'])->toBe(1)
        ->and($completa['output'])->toContain('shift_entries '.$tramos['abierto'].' · audit_without_entry')
        ->and($completa['output'])->toContain('1 discrepancia(s)');
})->group('RL-04', 'RL-02', 'RS-07');
