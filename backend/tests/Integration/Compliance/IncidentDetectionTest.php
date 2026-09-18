<?php

declare(strict_types=1);

use App\Modules\Compliance\Application\Command\ResolveIncidentCommand;
use App\Modules\Compliance\Application\Port\IncidentLedger;
use App\Modules\Compliance\Application\UseCase\ResolveIncident;
use App\Modules\Compliance\Domain\ValueObject\AuditAction;
use App\Modules\Compliance\Domain\ValueObject\IncidentStatus;
use App\Modules\Compliance\Infrastructure\Notification\IncidentDigestNotification;
use App\Modules\Compliance\Infrastructure\Persistence\DatabaseIncidentLedger;
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
use Tests\Support\Time\FrozenTime;
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
    FrozenTime::at($now);

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

it('no abre missing_break aunque el tramo supere las seis horas: RN-12 queda suspendida hasta la pausa declarada', function (): void {
    // Decision de producto del 30-08-2026 (doc 01 §4, nota sobre RN-12): la regla
    // sigue evaluandose en `AnomalyDetectionPolicy` —su unitaria lo prueba— pero
    // la pasada no abre la incidencia hasta que el quiosco registre la intencion
    // de pausa (RF-AT-12, tarea 3.5). Siete horas seguidas, cerradas, dentro de
    // la ventana: ni incidencia ni aviso.
    Notification::fake();

    $scenario = departmentWithManager();
    shiftEntry(
        $scenario['employee'],
        $scenario['site'],
        '2026-03-14',
        '2026-03-14 06:00:00+00',
        '2026-03-14 13:00:00+00',
    );

    expect(runDetection())->toBe(0)
        ->and(DB::table('incidents')->count())->toBe(0);
    Notification::assertNothingSent();
})->group('RN-12', 'RF-PR-01');

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

    // Y el fallo llega a la serie que dispara la alerta (tarea 3.2). Sin esto,
    // el codigo de salida 1 se quedaba en el log del planificador: las tres
    // tareas de madrugada corren con `runInBackground()` y en ese camino un
    // codigo distinto de cero no produce ni excepcion ni fila en `error_events`.
    $publicado = ficheroDeDeteccion();

    expect($publicado)
        ->toContain('incident_detection_last_failures 1')
        ->toContain('incident_detection_last_findings 2')
        ->toContain('incident_detection_last_run_timestamp_seconds');
})->group('RF-PR-01');

it('publica la pasada limpia y tambien la que no encontro nada', function (): void {
    // Doc 02 §8.2. Una serie que solo aparece cuando algo va mal es
    // indistinguible de una tarea programada que dejo de ejecutarse, y de eso
    // vive `DeteccionDeIncidenciasAusente`: sin ella, apagar el planificador
    // seria la forma mas comoda de que la alerta de turnos abiertos no volviera
    // a sonar nunca.
    Notification::fake();

    departmentWithManager();

    expect(runDetection())->toBe(0);

    expect(ficheroDeDeteccion())
        ->toContain('incident_detection_last_failures 0')
        ->toContain('incident_detection_last_findings 0')
        ->toContain('incident_detection_last_run_timestamp_seconds '.strtotime(DETECTION_NOW.'+00:00'));
})->group('RF-PR-01');

/** El `.prom` de la revision diaria, tal como lo dejo la ultima pasada. */
function ficheroDeDeteccion(): string
{
    $directory = rtrim(config()->string('observability.metrics.textfile_path'), '/');

    return (string) file_get_contents($directory.'/kronoqr_incident_detection.prom');
}

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

/**
 * Deja escrito el ajuste del fichaje de pausa y tira la memoria por peticion.
 *
 * `OperationalSettingsProvider` esta enlazado con `scoped()`: en produccion esa
 * memoria muere con la peticion, pero una prueba de integracion comparte proceso
 * y contenedor con lo que ya se resolvio antes. Sin el `forgetScopedInstances()`
 * la pasada leeria el valor anterior y la prueba pasaria sin probar nada.
 */
function configuraFichajeDePausa(string $value): void
{
    DB::table('installation_settings')->updateOrInsert(
        ['key' => 'ATTENDANCE_BREAK_CLOCKING'],
        ['value' => json_encode($value, JSON_THROW_ON_ERROR), 'updated_at' => DETECTION_NOW.'+00'],
    );

    app()->forgetScopedInstances();
}

it('no abre missing_break mientras el fichaje de pausa este desactivado', function (): void {
    // RN-12 y decision 8 de la ficha 3.5. Sin pausa declarada, un hueco entre dos
    // tramos puede ser una comida o el descanso entre dos turnos, y las dos cosas
    // se leen igual en la tabla: abrir la incidencia aqui seria señalar a quien
    // descanso sin fichar. El ajuste nace en `disabled` a proposito (decision 7).
    Notification::fake();

    $scenario = departmentWithManager();
    configuraFichajeDePausa('disabled');

    // Ocho horas seguidas, muy por encima de las seis del perfil por defecto.
    shiftEntry(
        $scenario['employee'],
        $scenario['site'],
        '2026-03-14',
        '2026-03-14 06:00:00+00',
        '2026-03-14 14:00:00+00',
    );

    expect(runDetection())->toBe(0)
        ->and(DB::table('incidents')->where('type', 'missing_break')->count())->toBe(0);
})->group('RN-12', 'RF-AT-12', 'RF-PR-01');

it('abre missing_break en cuanto la instalacion activa el fichaje de pausa', function (): void {
    // La otra mitad, y la que estrena RF-AT-12: donde el quiosco registra la
    // pausa, un tramo continuo de mas de seis horas **si** dice algo. No hace
    // falta tocar codigo ni reprocesar nada: la pasada siguiente lo abre.
    Notification::fake();

    $scenario = departmentWithManager();
    configuraFichajeDePausa('enabled');

    $entryUuid = shiftEntry(
        $scenario['employee'],
        $scenario['site'],
        '2026-03-14',
        '2026-03-14 06:00:00+00',
        '2026-03-14 14:00:00+00',
    );

    expect(runDetection())->toBe(0);

    $incidencia = DB::table('incidents')->where('type', 'missing_break')->first();

    expect($incidencia)->not->toBeNull()
        ->and($incidencia?->work_date)->toBe('2026-03-14')
        // Asignada al responsable, como cualquier otra (RF-PR-01).
        ->and($incidencia?->assigned_to_user_id)->toBe($scenario['manager'])
        ->and($incidencia?->shift_entry_id)
        ->toBe(DB::table('shift_entries')->where('uuid', $entryUuid)->value('id'));

    /** @var array<string, int> $contexto */
    $contexto = json_decode((string) $incidencia?->context, true, 512, JSON_THROW_ON_ERROR);

    // El umbral del perfil viaja con el hallazgo (regla dura 14): sin el, «480
    // minutos» no dice si aqui eso es mucho.
    expect($contexto)->toEqualCanonicalizing([
        'worked_minutes' => 480,
        'threshold_minutes' => 360,
    ]);
})->group('RN-12', 'RF-AT-12', 'RF-PR-01');

it('no abre missing_break sobre un tramo cortado por una pausa declarada', function (): void {
    // RN-12 dice «tramo continuo». Con el fichaje de pausa activado, ocho horas
    // repartidas en dos tramos de cuatro **no incumplen nada**: es exactamente el
    // caso que la regla persigue y la razon por la que no podia evaluarse antes
    // de que existiera la pausa (ADR-024).
    Notification::fake();

    $scenario = departmentWithManager();
    configuraFichajeDePausa('enabled');

    shiftEntry($scenario['employee'], $scenario['site'], '2026-03-14', '2026-03-14 06:00:00+00', '2026-03-14 10:00:00+00');
    shiftEntry($scenario['employee'], $scenario['site'], '2026-03-14', '2026-03-14 10:30:00+00', '2026-03-14 14:30:00+00');

    expect(runDetection())->toBe(0)
        ->and(DB::table('incidents')->where('type', 'missing_break')->count())->toBe(0);
})->group('RN-12', 'RF-AT-12');

it('desactivar el fichaje de pausa no cierra las incidencias missing_break ya abiertas', function (): void {
    // Regla dura 5 y decision 8 de la ficha 3.5: **nada se borra ni se cierra
    // solo**. Suspender una regla deja de ABRIR incidencias; las que ya estan
    // abiertas describen una jornada real que alguien tiene que revisar, y
    // cerrarlas automaticamente destruiria el rastro de una decision que todavia
    // no ha tomado ninguna persona.
    //
    // Es ademas el camino que un hotel recorre de verdad: se activa la pausa,
    // se prueba una semana, y se apaga porque la plantilla no la ficha.
    Notification::fake();

    $scenario = departmentWithManager();
    configuraFichajeDePausa('enabled');

    shiftEntry(
        $scenario['employee'],
        $scenario['site'],
        '2026-03-14',
        '2026-03-14 06:00:00+00',
        '2026-03-14 14:00:00+00',
    );

    expect(runDetection())->toBe(0)
        ->and(DB::table('incidents')->where('type', 'missing_break')->count())->toBe(1);

    $incidencia = DB::table('incidents')->where('type', 'missing_break')->first();

    configuraFichajeDePausa('disabled');

    expect(runDetection())->toBe(0);

    $despues = DB::table('incidents')->where('type', 'missing_break')->first();

    // Sigue ahi, abierta y sin tocar: ni el estado, ni el momento de deteccion,
    // ni el responsable.
    expect(DB::table('incidents')->where('type', 'missing_break')->count())->toBe(1)
        ->and($despues?->status)->toBe(IncidentStatus::Open->value)
        ->and($despues?->detected_at)->toBe($incidencia?->detected_at)
        ->and($despues?->assigned_to_user_id)->toBe($scenario['manager']);
})->group('RN-12', 'RF-AT-12', 'RL-04');

// --- RN-18 · el fichaje irreconciliable --------------------------------------

/**
 * Un escaneo ya registrado como irreconciliable, tal y como lo deja el camino de
 * fichaje: sin tramo, sin acumulado y marcado para revision.
 */
function outOfOrderScan(
    string $employeeUuid,
    int $deviceId,
    string $occurredAt,
    ?string $scanId = null,
    string $recordedAt = '2026-03-14 18:00:00+00',
    int $clockSkewSeconds = 0,
): string {
    $uuid = $scanId ?? Str::uuid7()->toString();

    DB::table('scan_events')->insert([
        'scan_id' => $uuid,
        'device_id' => $deviceId,
        'employee_id' => DB::table('employees')->where('uuid', $employeeUuid)->value('id'),
        'occurred_at' => $occurredAt,
        'recorded_at' => $recordedAt,
        'clock_skew_seconds' => $clockSkewSeconds,
        'origin' => 'qr_kiosk',
        'intent' => 'auto',
        'result' => 'rejected_out_of_order',
        'shift_entry_id' => null,
        'worked_minutes' => null,
        'client_meta' => '{}',
        'flagged_for_review' => true,
    ]);

    return $uuid;
}

it('abre UNA incidencia por jornada aunque la cola traiga varios escaneos imposibles', function (): void {
    // RN-18 leido hacia atras, igual que `clock_skew`: la columna la escribio el
    // fichaje y la pasada nocturna solo la lee. Dos escaneos imposibles del mismo
    // dia dicen lo mismo —«esta jornada no cuadra»— y la incidencia lo dice una
    // vez: la sostienen el agrupado del caso de uso y, debajo,
    // `one_incident_per_finding` con `NULLS NOT DISTINCT`.
    Notification::fake();

    $scenario = departmentWithManager();
    $device = AttendanceFixtures::device($scenario['site']);

    // **En orden descendente a proposito**: se inserta antes el de las 15:20 y
    // despues el de las 13:50, de modo que el mas temprano sea el ultimo por
    // `id`. Si el puerto dejara de ordenar por `occurred_at`, el contexto
    // señalaria al escaneo equivocado y quien trabaje la incidencia empezaria a
    // mirar por el sitio que no es. Insertados en orden natural, ese defecto no
    // se veria.
    //
    // El segundo lleva ademas un desfase de reloj enorme —una hora—, que es lo
    // normal en un elemento que drena tarde: sirve para afirmar abajo que no
    // abre TAMBIEN una incidencia `clock_skew`.
    outOfOrderScan($scenario['employee'], $device['id'], '2026-03-14 15:20:00+00', clockSkewSeconds: 3600);
    $primero = outOfOrderScan($scenario['employee'], $device['id'], '2026-03-14 13:50:00+00', clockSkewSeconds: 3600);

    expect(runDetection())->toBe(0);

    $incidents = DB::table('incidents')->where('type', 'out_of_order_scan')->get();

    // Una de RN-18 y **ninguna mas**: las filas de RN-18 tambien estan marcadas
    // para revision, asi que `inspectFlaggedScans()` las recorre; lo que no hace
    // es abrir `clock_skew` sobre ellas —no produjeron tramo, no hay jornada que
    // revisar por ese otro motivo— y este recuento es lo que lo fija.
    expect($incidents)->toHaveCount(1)
        ->and(DB::table('incidents')->count())->toBe(1);

    $incident = $incidents->first();

    expect($incident?->severity)->toBe('medium')
        ->and($incident?->status)->toBe('open')
        ->and($incident?->work_date)->toBe('2026-03-14')
        // La incidencia es de la JORNADA: el escaneo no produjo tramo y el que
        // estaba abierto no es el problema, sino el contexto.
        ->and($incident?->shift_entry_id)->toBeNull()
        // Y llega asignada al responsable del departamento, como las demas.
        ->and($incident?->assigned_to_user_id)->toBe($scenario['manager']);

    /** @var array<string, int|string> $context */
    $context = json_decode((string) $incident?->context, true, 512, JSON_THROW_ON_ERROR);

    // El PRIMERO de la jornada —por donde empieza a mirar quien la trabaja— y
    // cuantos fueron. `toEqualCanonicalizing` porque JSONB no conserva el orden.
    //
    // El instante va con sufijo `Z` y microsegundos, la forma del esquema
    // `UtcTimestamp` del contrato: es el mismo formato que cualquier otra fecha
    // de la API y el panel lo pinta al lado de ellas.
    expect($context)->toEqualCanonicalizing([
        'scan_id' => $primero,
        'occurred_at' => '2026-03-14T13:50:00.000000Z',
        'scans' => 2,
    ]);

    // Regla dura 21: ni nombre, ni apellidos, ni codigo de empleado en el
    // contexto que viaja al panel y a la exportacion.
    $employee = DB::table('employees')->where('uuid', $scenario['employee'])->first();
    $escrito = (string) ($incident->context ?? '');

    expect(str_contains($escrito, (string) ($employee->first_name ?? 'x')))->toBeFalse('el contexto lleva el nombre')
        ->and(str_contains($escrito, (string) ($employee->last_name ?? 'x')))->toBeFalse('el contexto lleva el apellido')
        ->and(str_contains($escrito, (string) ($employee->employee_code ?? 'x')))->toBeFalse('el contexto lleva el codigo');

    // Y el escaneo sigue marcado: la marca es el rastro del hecho, no un estado
    // que la deteccion consuma.
    expect(DB::table('scan_events')->where('scan_id', $primero)->value('flagged_for_review'))->toBeTrue();
})->group('RN-18', 'RF-PR-01');

it('separa por jornada los escaneos imposibles del mismo empleado', function (): void {
    // «Una por empleado y jornada» es exactamente eso: dos dias distintos son dos
    // incidencias. Sin esto, agrupar por empleado dejaria sin revisar el segundo
    // dia, y con `NULLS NOT DISTINCT` la fila ni siquiera entraria.
    Notification::fake();

    $scenario = departmentWithManager();
    $device = AttendanceFixtures::device($scenario['site']);

    outOfOrderScan($scenario['employee'], $device['id'], '2026-03-13 13:50:00+00');
    outOfOrderScan($scenario['employee'], $device['id'], '2026-03-14 13:50:00+00');

    expect(runDetection())->toBe(0);

    $workDates = DB::table('incidents')
        ->where('type', 'out_of_order_scan')
        ->orderBy('work_date')
        ->pluck('work_date')
        ->all();

    expect($workDates)->toBe(['2026-03-13', '2026-03-14']);
})->group('RN-18', 'RF-PR-01');

it('no duplica la incidencia del fichaje irreconciliable al repetir la pasada', function (): void {
    // La pasada es idempotente, y no por un `SELECT` previo: la segunda insercion
    // choca con `one_incident_per_finding` y se ignora. Alguien ejecutando el
    // comando a mano mientras el planificador corre es el caso real.
    Notification::fake();

    $scenario = departmentWithManager();
    $device = AttendanceFixtures::device($scenario['site']);

    outOfOrderScan($scenario['employee'], $device['id'], '2026-03-14 13:50:00+00');

    expect(runDetection())->toBe(0)
        ->and(runDetection())->toBe(0)
        ->and(DB::table('incidents')->where('type', 'out_of_order_scan')->count())->toBe(1);
})->group('RN-18', 'RF-PR-01');

it('atribuye la jornada en la zona del centro y no en UTC', function (string $occurredAt, string $expected): void {
    // RN-05 y regla dura 3: el escaneo no produjo tramo del que heredar la
    // jornada, asi que la deriva el caso de uso convirtiendo `occurred_at` a la
    // zona del centro —Madrid—. Los tres instantes estan elegidos para que en
    // UTC den un dia y en Madrid otro, o para caer en los dos cambios de hora:
    // con la conversion quitada o hecha en UTC, los tres siguen «verdes» en el
    // resto de pruebas y solo fallan aqui.
    Notification::fake();

    $scenario = departmentWithManager();
    $device = AttendanceFixtures::device($scenario['site']);

    outOfOrderScan($scenario['employee'], $device['id'], $occurredAt, recordedAt: '2026-10-25 12:00:00+00');

    expect(runDetection('2026-10-25 19:00:00'))->toBe(0)
        ->and(DB::table('incidents')->where('type', 'out_of_order_scan')->value('work_date'))->toBe($expected);
})->with([
    // 23:30 UTC del 14 son las 00:30 del 15 en Madrid (CET, +1).
    'la noche pasa al dia siguiente' => ['2026-03-14 23:30:00+00', '2026-03-15'],
    // 00:15 UTC del 25 de octubre son las 02:15 en Madrid, todavia CEST (+2):
    // es la madrugada en que el reloj retrocede y esa hora existe dos veces.
    'vuelta del horario de verano' => ['2026-10-25 00:15:00+00', '2026-10-25'],
    // 01:30 UTC del 29 de marzo son las 02:30 en Madrid: la hora que NO existe
    // ese dia, porque el reloj salta de 02:00 a 03:00.
    'salto del horario de verano' => ['2026-03-29 01:30:00+00', '2026-03-29'],
])->group('RN-18', 'RN-05', 'RN-09');

it('abre la incidencia del fichaje que se quedo dias en la cola', function (): void {
    // El caso que da sentido a RN-18: un elemento atascado que drena **ayer**
    // con el `occurred_at` de hace tres semanas. La ventana de la deteccion se
    // mide sobre `recorded_at` —cuando el servidor lo supo— precisamente para
    // esto: medida sobre el momento real, el escaneo que mas necesita que
    // alguien lo mire seria el unico que nadie mira.
    //
    // La jornada de la incidencia sigue siendo la del fichaje, no la de hoy.
    Notification::fake();

    $scenario = departmentWithManager();
    $device = AttendanceFixtures::device($scenario['site']);

    outOfOrderScan(
        $scenario['employee'],
        $device['id'],
        '2026-02-22 13:50:00+00',
        recordedAt: '2026-03-13 18:00:00+00',
    );

    expect(runDetection())->toBe(0);

    $incident = DB::table('incidents')->where('type', 'out_of_order_scan')->first();

    expect($incident)->not->toBeNull()
        ->and($incident?->work_date)->toBe('2026-02-22');
})->group('RN-18', 'RF-KI-04', 'RF-PR-01');

it('no mira los fichajes irreconciliables que llegaron antes de la ventana', function (): void {
    // La otra mitad de la ventana: lo que el servidor supo hace mas de los dias
    // de retroactividad ya se reviso en su pasada. Sin esta cota, cada noche se
    // volveria a recorrer el historico entero.
    Notification::fake();

    $scenario = departmentWithManager();
    $device = AttendanceFixtures::device($scenario['site']);

    outOfOrderScan(
        $scenario['employee'],
        $device['id'],
        '2026-03-14 13:50:00+00',
        // Treinta dias antes del «ahora» de la pasada, con siete de ventana.
        recordedAt: '2026-02-12 18:00:00+00',
    );

    expect(runDetection())->toBe(0)
        ->and(DB::table('incidents')->count())->toBe(0);
})->group('RN-18', 'RF-PR-01');
