<?php

declare(strict_types=1);

use App\Modules\Compliance\Application\Command\ResolveIncidentCommand;
use App\Modules\Compliance\Application\Port\IncidentLedger;
use App\Modules\Compliance\Application\UseCase\ResolveIncident;
use App\Modules\Compliance\Domain\ValueObject\AuditAction;
use App\Modules\Compliance\Domain\ValueObject\IncidentStatus;
use App\Modules\Compliance\Infrastructure\Notification\IncidentDigestNotification;
use App\Modules\Compliance\Infrastructure\Persistence\DatabaseIncidentLedger;
use App\Modules\Shared\Application\Port\Clock;
use App\Modules\Shared\Domain\ValueObject\AccessScope;
use App\Modules\Shared\Domain\ValueObject\UserRole;
use App\Modules\Workforce\Infrastructure\Persistence\Department;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Tests\Support\Attendance\AttendanceFixtures;
use Tests\Support\Compliance\FailingIncidentLedger;
use Tests\Support\Database\RefreshDatabase;
use Tests\Support\Identity\ManagementUsers;
use Tests\Support\Time\FixedClock;
use Tests\Support\Workforce\WorkforceFixtures;

/*
 * La deteccion automatica de incidencias contra PostgreSQL de verdad (RF-PR-01,
 * tarea 2.6).
 *
 * POR QUE ESTA SUITE NO PODIA SER UNITARIA. Lo que se comprueba aqui vive en el
 * motor: que el indice unico **parcial** con `NULLS NOT DISTINCT` impida la
 * segunda incidencia identica, que el tramo abierto siga abierto despues de la
 * pasada, y que el asiento de `audit_log` entre en la misma transaccion. Un doble
 * en memoria daria las tres por buenas sin haberlas comprobado.
 *
 * EL RELOJ ESTA FIJO (regla dura 2): sin eso, «un tramo abierto desde hace trece
 * horas» seria una prueba que cambia de resultado segun la hora a la que se
 * ejecute.
 */

uses(RefreshDatabase::class);

/** El «ahora» de todas las pruebas de este fichero. */
const DETECTION_NOW = '2026-03-14 19:00:00';

/**
 * Centro en Madrid, departamento con responsable y empleado dentro.
 *
 * @return array{site: int, department: int, employee: string, manager: int}
 */
function departmentWithManager(): array
{
    $site = WorkforceFixtures::site('Hotel de incidencias', 'Europe/Madrid');
    $department = WorkforceFixtures::department($site, 'Cocina');
    $manager = ManagementUsers::withRole(UserRole::RESPONSABLE_DEPARTAMENTO);

    Department::query()->whereKey($department)->update(['manager_user_id' => $manager->id]);

    return [
        'site' => $site,
        'department' => $department,
        'employee' => WorkforceFixtures::employee($site, $department),
        'manager' => $manager->id,
    ];
}

/**
 * Un tramo escrito directamente en la tabla: las pruebas de deteccion necesitan
 * estados que el fichaje tardaria trece horas en producir.
 */
function shiftEntry(string $employeeUuid, int $siteId, string $workDate, string $clockedInAt, ?string $clockedOutAt = null): string
{
    $uuid = Str::uuid7()->toString();

    DB::table('shift_entries')->insert([
        'uuid' => $uuid,
        'employee_id' => DB::table('employees')->where('uuid', $employeeUuid)->value('id'),
        'site_id' => $siteId,
        'work_date' => $workDate,
        'clocked_in_at' => $clockedInAt,
        'clocked_out_at' => $clockedOutAt,
        'duration_minutes' => $clockedOutAt === null
            ? null
            : intdiv(strtotime($clockedOutAt) - strtotime($clockedInAt), 60),
        'status' => $clockedOutAt === null ? 'open' : 'closed',
        'clock_in_source' => 'qr_kiosk',
        'clock_out_source' => $clockedOutAt === null ? null : 'qr_kiosk',
        'version' => 1,
        'created_at' => $clockedInAt,
        'updated_at' => $clockedInAt,
    ]);

    return $uuid;
}

function runDetection(string $now = DETECTION_NOW): int
{
    app()->instance(Clock::class, FixedClock::at($now));

    return Artisan::call('attendance:detect-incidents');
}

it('abre la incidencia del turno olvidado, la asigna y NO cierra el tramo', function (): void {
    // Escenario «Turno olvidado» del doc 01 §11: un tramo abierto desde hace
    // trece horas. Lo que se afirma no es solo que aparezca la incidencia, sino
    // las dos mitades de RN-08: **no se cierra automaticamente** y **se notifica
    // al responsable**.
    Notification::fake();

    $scenario = departmentWithManager();
    $entryUuid = shiftEntry($scenario['employee'], $scenario['site'], '2026-03-14', '2026-03-14 06:00:00+00');

    expect(runDetection())->toBe(0);

    $incident = DB::table('incidents')->first();

    expect($incident)->not->toBeNull()
        ->and($incident?->type)->toBe('open_shift_expired')
        ->and($incident?->severity)->toBe('medium')
        ->and($incident?->status)->toBe('open')
        ->and($incident?->work_date)->toBe('2026-03-14')
        ->and($incident?->assigned_to_user_id)->toBe($scenario['manager']);

    // La mitad que RN-08 protege: el tramo sigue exactamente como estaba.
    $entry = DB::table('shift_entries')->where('uuid', $entryUuid)->first();

    expect($entry?->status)->toBe('open')
        ->and($entry?->clocked_out_at)->toBeNull();

    Notification::assertSentOnDemand(IncidentDigestNotification::class);
})->group('RF-PR-01', 'RN-08');

it('no duplica nada al ejecutarse dos veces', function (): void {
    // La idempotencia la garantiza el indice unico parcial de `incidents`, no un
    // `SELECT` previo: por eso la prueba corre el comando entero dos veces en vez
    // de llamar al caso de uso.
    Notification::fake();

    $scenario = departmentWithManager();
    shiftEntry($scenario['employee'], $scenario['site'], '2026-03-14', '2026-03-14 06:00:00+00');

    runDetection();
    runDetection();

    expect(DB::table('incidents')->count())->toBe(1)
        // Y tampoco duplica el aviso: `notified_at` sellado en la primera pasada
        // deja la incidencia fuera del resumen de la segunda.
        ->and(DB::table('incidents')->whereNotNull('notified_at')->count())->toBe(1);

    Notification::assertSentOnDemandTimes(IncidentDigestNotification::class, 1);
})->group('RF-PR-01');

it('deja el asiento encadenado de la apertura, sin nombres', function (): void {
    // Regla dura 6: abrir una incidencia es un hecho con relevancia legal —afirma
    // que el registro de alguien no cuadra— y deja traza. El payload lleva
    // identificadores y numeros, nunca el nombre de la persona (regla dura 21).
    Notification::fake();

    $scenario = departmentWithManager();
    shiftEntry($scenario['employee'], $scenario['site'], '2026-03-14', '2026-03-14 06:00:00+00');

    runDetection();

    $entry = DB::table('audit_log')->where('action', AuditAction::IncidentOpened->value)->first();

    expect($entry)->not->toBeNull()
        ->and($entry?->actor_type)->toBe('system')
        ->and($entry?->subject_type)->toBe('incident');

    /** @var array<string, mixed> $payload */
    $payload = json_decode((string) $entry?->payload, true, 512, JSON_THROW_ON_ERROR);

    expect($payload['employee_uuid'])->toBe($scenario['employee'])
        ->and($payload['type'])->toBe('open_shift_expired')
        ->and($payload['severity'])->toBe('medium')
        ->and($payload['assigned_to_user_id'])->toBe($scenario['manager'])
        ->and($payload['context'])->toBe(['open_minutes' => 780, 'threshold_minutes' => 720])
        ->and(json_encode($payload, JSON_THROW_ON_ERROR))->not->toContain('Persona')
        ->and(json_encode($payload, JSON_THROW_ON_ERROR))->not->toContain('De Prueba');
})->group('RF-PR-01', 'RS-07');

it('abre la incidencia sin asignar cuando el departamento no tiene responsable', function (): void {
    // Regla dura 19 llevada al proceso: un hueco de configuracion no puede hacer
    // desaparecer un hallazgo. Queda visible en la bandeja, sin avisar a nadie.
    Notification::fake();

    $site = WorkforceFixtures::site('Hotel sin responsable', 'Europe/Madrid');
    $department = WorkforceFixtures::department($site, 'Sala');
    $employee = WorkforceFixtures::employee($site, $department);

    shiftEntry($employee, $site, '2026-03-14', '2026-03-14 06:00:00+00');

    runDetection();

    $incident = DB::table('incidents')->first();

    expect($incident)->not->toBeNull()
        ->and($incident?->assigned_to_user_id)->toBeNull()
        ->and($incident?->notified_at)->toBeNull();

    Notification::assertNothingSent();
})->group('RF-PR-01');

it('no revisa las jornadas anteriores a la ventana, y si los tramos abiertos', function (): void {
    // La decision de retroactividad (doc 01 §4): una jornada cerrada de hace un
    // mes no genera incidencias nuevas, y un tramo abierto de hace un mes si,
    // porque sigue creciendo.
    Notification::fake();

    $scenario = departmentWithManager();

    // Cerrada y antigua: nueve horas y media, que superarian RN-11 y RN-12.
    shiftEntry(
        $scenario['employee'],
        $scenario['site'],
        '2026-02-01',
        '2026-02-01 06:00:00+00',
        '2026-02-01 15:30:00+00',
    );

    // Abierta y todavia mas antigua.
    $forgotten = WorkforceFixtures::employee($scenario['site'], $scenario['department']);
    shiftEntry($forgotten, $scenario['site'], '2026-01-15', '2026-01-15 06:00:00+00');

    runDetection();

    $types = DB::table('incidents')
        ->join('employees', 'employees.id', '=', 'incidents.employee_id')
        ->pluck('incidents.type', 'employees.uuid')
        ->all();

    expect($types)->toBe([$forgotten => 'open_shift_expired']);
})->group('RF-PR-01', 'RN-08');

it('respeta el catalogo de tipos y severidades del esquema', function (): void {
    // La ultima linea de defensa (doc 02 §3.2): los CHECK de la migracion valen
    // tambien para una importacion o un script que no pase por el dominio.
    departmentWithManager();

    expect(fn () => DB::table('incidents')->insert([
        'employee_id' => DB::table('employees')->value('id'),
        'work_date' => '2026-03-14',
        'type' => 'inventado',
        'severity' => 'medium',
        'status' => 'open',
        'detected_at' => '2026-03-14 19:00:00+00',
        'context' => '{}',
        'created_at' => '2026-03-14 19:00:00+00',
        'updated_at' => '2026-03-14 19:00:00+00',
    ]))->toThrow(QueryException::class);
})->group('RF-PR-01');

it('convierte en incidencia el escaneo marcado por desfase de reloj', function (): void {
    // RN-15 leido hacia atras: `ReviewPolicy` marco `flagged_for_review` en el
    // momento del fichaje y hasta ahora nadie miraba esa columna. El fichaje se
    // registro igual —regla dura 19— y lo que se abre es la revision.
    Notification::fake();

    $scenario = departmentWithManager();
    $device = AttendanceFixtures::device($scenario['site']);
    $entryUuid = shiftEntry(
        $scenario['employee'],
        $scenario['site'],
        '2026-03-14',
        '2026-03-14 06:00:00+00',
        '2026-03-14 10:00:00+00',
    );

    DB::table('scan_events')->insert([
        'scan_id' => Str::uuid7()->toString(),
        'device_id' => $device['id'],
        'employee_id' => DB::table('employees')->where('uuid', $scenario['employee'])->value('id'),
        'occurred_at' => '2026-03-14 06:00:00+00',
        'recorded_at' => '2026-03-14 06:40:00+00',
        'origin' => 'qr_kiosk',
        'intent' => 'auto',
        'result' => 'clock_in',
        'shift_entry_id' => DB::table('shift_entries')->where('uuid', $entryUuid)->value('id'),
        'worked_minutes' => 0,
        'client_meta' => '{}',
        // Cuarenta minutos de adelanto, muy por encima de los quince de serie.
        'clock_skew_seconds' => 2400,
        'flagged_for_review' => true,
    ]);

    runDetection();

    $incident = DB::table('incidents')->where('type', 'clock_skew')->first();

    expect($incident)->not->toBeNull()
        ->and($incident?->severity)->toBe('low')
        ->and($incident?->work_date)->toBe('2026-03-14')
        ->and($incident?->shift_entry_id)->not->toBeNull();

    /** @var array<string, int> $context */
    $context = json_decode((string) $incident?->context, true, 512, JSON_THROW_ON_ERROR);

    // `toEqualCanonicalizing` y no `toBe`: JSONB no conserva el orden de las claves
    // (por eso el payload de `audit_log` se canonicaliza antes de encadenarlo).
    expect($context)->toEqualCanonicalizing(['clock_skew_seconds' => 2400, 'threshold_seconds' => 900]);
})->group('RN-15', 'RF-PR-01');

it('publica el gauge de incidencias abiertas con todos los tipos, tambien a cero', function (): void {
    // Doc 02 §8.2. Una serie que desaparece es indistinguible de una que nunca
    // tuvo nada, y aqui el cero es justo lo que se mira: «no hay ningun turno
    // abierto de mas de doce horas».
    Notification::fake();

    $scenario = departmentWithManager();
    shiftEntry($scenario['employee'], $scenario['site'], '2026-03-14', '2026-03-14 06:00:00+00');

    runDetection();

    $directory = rtrim(config()->string('observability.metrics.textfile_path'), '/');
    @unlink($directory.'/kronoqr_incidents.prom');

    expect(Artisan::call('compliance:incident-metrics'))->toBe(0);

    $published = (string) file_get_contents($directory.'/kronoqr_incidents.prom');

    expect($published)
        ->toContain('incidents_open{type="open_shift_expired",severity="medium"} 1')
        ->toContain('incidents_open{type="insufficient_rest",severity="high"} 0')
        ->toContain('incidents_metrics_timestamp_seconds');
})->group('RF-PR-01');

it('no vuelve a abrir ni a avisar de una incidencia que ya se resolvio', function (): void {
    // El fallo que esto impide: la restriccion de idempotencia era **parcial**
    // —solo sobre `status = 'open'`—, asi que en cuanto un responsable resolvia la
    // incidencia, la pasada de la noche siguiente volvia a abrirla y a avisarle
    // mientras la jornada siguiera dentro de la ventana. Un tramo cerrado es una
    // fila inmutable: el mismo hallazgo sobre el no es un hecho nuevo.
    Notification::fake();

    $scenario = departmentWithManager();
    shiftEntry($scenario['employee'], $scenario['site'], '2026-03-14', '2026-03-14 06:00:00+00');

    runDetection();

    $incident = DB::table('incidents')->first();
    expect($incident)->not->toBeNull();

    // Se resuelve con el caso de uso real de la tarea 2.5, no con un UPDATE: lo
    // que hay que comprobar es que la deteccion respeta lo que la bandeja
    // escribe, no lo que esta prueba sepa escribir.
    app(ResolveIncident::class)->handle(new ResolveIncidentCommand(
        incidentId: (int) $incident?->id,
        outcome: IncidentStatus::Resolved,
        note: 'Hablado con la persona: salio a las 14:00 y se corrige el tramo.',
        resolvedByUserId: $scenario['manager'],
        scope: AccessScope::unrestricted(),
    ));

    runDetection();

    expect(DB::table('incidents')->count())->toBe(1)
        ->and(DB::table('incidents')->value('status'))->toBe('resolved');

    // Y el aviso tampoco vuelve: solo salio el de la primera pasada.
    Notification::assertSentOnDemandTimes(IncidentDigestNotification::class, 1);
})->group('RF-PR-01', 'RF-PA-05');

it('no sella el aviso cuando el correo no sale, y la deteccion termina igual', function (): void {
    // El fallo que esto impide: la notificacion era `ShouldQueue`, asi que
    // `notify()` solo encolaba, el `try/catch` del adaptador veia un exito siempre
    // y `notified_at` se sellaba sobre avisos que nadie recibia. Con el envio
    // sincrono, un SMTP mal configurado —lo mas comun de una instalacion recien
    // puesta en marcha— deja la incidencia pendiente de avisar.
    //
    // Sin `Notification::fake()` a proposito: lo que se prueba es justo el camino
    // real de envio.
    Config::set('mail.default', 'un-transporte-que-no-existe');

    $scenario = departmentWithManager();
    shiftEntry($scenario['employee'], $scenario['site'], '2026-03-14', '2026-03-14 06:00:00+00');

    // La deteccion NO falla por el correo: las incidencias ya estan abiertas y
    // visibles en la bandeja, que es lo que el registro necesita.
    expect(runDetection())->toBe(0);

    $incident = DB::table('incidents')->first();

    expect($incident)->not->toBeNull()
        ->and($incident?->status)->toBe('open')
        ->and($incident?->notified_at)->toBeNull();

    // Y sin sello, la pasada siguiente lo vuelve a intentar.
    Config::set('mail.default', 'array');

    runDetection();

    expect(DB::table('incidents')->value('notified_at'))->not->toBeNull();
})->group('RF-PR-01');

it('abre el resto de hallazgos cuando uno falla, y lo dice en el codigo de salida', function (): void {
    // El listener corre en el despachador SINCRONO, asi que sin aislamiento por
    // hallazgo una excepcion en el tercero de cuarenta abortaba los treinta y
    // siete restantes, no salia el resumen y el comando moria con una traza.
    Notification::fake();

    $scenario = departmentWithManager();
    $otro = WorkforceFixtures::employee($scenario['site'], $scenario['department']);

    shiftEntry($scenario['employee'], $scenario['site'], '2026-03-14', '2026-03-14 06:00:00+00');
    shiftEntry($otro, $scenario['site'], '2026-03-14', '2026-03-14 05:00:00+00');

    // Un libro que se rompe con el primer hallazgo que le llega y funciona con el
    // resto. El orden de los hallazgos lo decide la consulta, asi que se elige por
    // empleado y no por posicion.
    app()->bind(IncidentLedger::class, fn (): IncidentLedger => new FailingIncidentLedger(
        app(DatabaseIncidentLedger::class),
        $otro,
    ));

    expect(runDetection())->toBe(1);

    $opened = DB::table('incidents')
        ->join('employees', 'employees.id', '=', 'incidents.employee_id')
        ->pluck('employees.uuid')
        ->all();

    // El que fallo no esta; el otro si, y su aviso ha salido.
    expect($opened)->toBe([$scenario['employee']]);

    Notification::assertSentOnDemand(IncidentDigestNotification::class);
})->group('RF-PR-01');

it('rechaza una ventana que no es un numero en vez de caer al valor configurado', function (): void {
    // `--days=siete` caia en silencio a los siete dias de `config`. Quien escribio
    // mal la opcion se quedaba creyendo que habia revisado tres meses.
    departmentWithManager();

    expect(Artisan::call('attendance:detect-incidents', ['--days' => 'siete']))->toBe(2)
        ->and(DB::table('incidents')->count())->toBe(0);
})->group('RF-PR-01');

it('deja asiento de divulgacion por cada resumen que sale por correo', function (): void {
    // RS-05 y RL-15: el aviso saca nombres de la plantilla de la instalacion por
    // SMTP, que es el unico camino por el que esos datos salen del servidor del
    // cliente. Sin asiento no se puede responder «que se fue, a quien y cuando».
    Notification::fake();

    $scenario = departmentWithManager();
    shiftEntry($scenario['employee'], $scenario['site'], '2026-03-14', '2026-03-14 06:00:00+00');

    runDetection();

    $entry = DB::table('audit_log')
        ->where('action', AuditAction::PersonalDataAccessed->value)
        ->orderByDesc('id')
        ->first();

    expect($entry)->not->toBeNull()
        // Lo abre el planificador: no hay persona detras (ADR-039).
        ->and($entry?->actor_type)->toBe('system');

    /** @var array<string, mixed> $payload */
    $payload = json_decode((string) $entry?->payload, true, 512, JSON_THROW_ON_ERROR);

    expect($payload['dataset'])->toBe('incident_digest')
        ->and($payload['record_count'])->toBe(1)
        ->and($payload['manager_user_id'])->toBe($scenario['manager'])
        ->and($payload['incident_count'])->toBe(1)
        // Identificadores, nunca nombres (regla dura 21).
        ->and($payload['employee_uuids'])->toBe($scenario['employee'])
        ->and(json_encode($payload, JSON_THROW_ON_ERROR))->not->toContain('Persona');
})->group('RF-PR-01', 'RS-05');
