<?php

declare(strict_types=1);

use App\Modules\Attendance\Application\Port\CredentialResolver;
use App\Modules\Compliance\Domain\ValueObject\AuditAction;
use App\Modules\Shared\Domain\ValueObject\UserRole;
use App\Modules\Workforce\Infrastructure\Persistence\Department;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Tests\Support\Attendance\AttendanceFixtures;
use Tests\Support\Attendance\FakeCredentialResolver;
use Tests\Support\Database\RefreshDatabase;
use Tests\Support\Http\Api;
use Tests\Support\Identity\ManagementUsers;
use Tests\Support\Time\FrozenTime;
use Tests\Support\Workforce\WorkforceFixtures;

/*
 * Patrones anomalos de uso de credencial contra PostgreSQL de verdad (RF-PR-06,
 * RN-16, tarea 3.11).
 *
 * POR QUE ESTA SUITE NO PODIA SER UNITARIA. Lo que se comprueba aqui vive en el
 * motor y en el cableado: que la consulta que lee `scan_events` traiga
 * exactamente los escaneos de quiosco aceptados, que `one_incident_per_finding`
 * impida la segunda incidencia identica al repetir el comando, que el asiento de
 * `audit_log` entre en la misma transaccion y -sobre todo- que **`shift_entries`
 * quede byte a byte igual que antes de la pasada**. Un doble en memoria daria las
 * cuatro por buenas sin haberlas comprobado.
 *
 * LO QUE ESTA SUITE AFIRMA Y NADIE MAS PUEDE AFIRMAR: que la deteccion no anula
 * ni marca ningun fichaje (RF-PR-06 §9.4, reglas duras 5 y 19). La regla no
 * recibe tramos, pero eso es un argumento; esto es la tabla antes y despues.
 *
 * EL RELOJ ESTA FIJO (regla dura 2): la ventana de treinta dias se cuenta hacia
 * atras desde «ahora», y sin fijarlo la prueba cambiaria de resultado cada dia.
 */

uses(RefreshDatabase::class);

/** El «ahora» de la pasada en todas las pruebas de este fichero. */
const PATTERN_NOW = '2026-03-20 04:35:00';

/**
 * Centro en Madrid, dos departamentos con responsables distintos y una persona
 * en cada uno.
 *
 * Dos departamentos a proposito: la decision 2 de la ficha abre **una incidencia
 * por cada persona del par** precisamente porque pueden ser de departamentos
 * distintos, y con un solo responsable esa afirmacion no se podria comprobar.
 *
 * @return array{site: int, ana: string, bruno: string, anaManager: int, brunoManager: int, device: int, otherDevice: int}
 */
function patternScenario(): array
{
    $site = WorkforceFixtures::site('Hotel de patrones', 'Europe/Madrid');

    $cocina = WorkforceFixtures::department($site, 'Cocina');
    $sala = WorkforceFixtures::department($site, 'Sala');

    $cocinaManager = ManagementUsers::withRole(UserRole::RESPONSABLE_DEPARTAMENTO);
    $salaManager = ManagementUsers::withRole(UserRole::RESPONSABLE_DEPARTAMENTO);

    Department::query()->whereKey($cocina)->update(['manager_user_id' => $cocinaManager->id]);
    Department::query()->whereKey($sala)->update(['manager_user_id' => $salaManager->id]);

    $device = AttendanceFixtures::device($site, 'Recepcion');
    $other = AttendanceFixtures::device($site, 'Cocina');

    return [
        'site' => $site,
        'ana' => WorkforceFixtures::employee($site, $cocina, firstName: 'Ana', lastName: 'Gomez'),
        'bruno' => WorkforceFixtures::employee($site, $sala, firstName: 'Bruno', lastName: 'Ferrer'),
        'anaManager' => $cocinaManager->id,
        'brunoManager' => $salaManager->id,
        'device' => $device['id'],
        'otherDevice' => $other['id'],
    ];
}

/**
 * Un escaneo de quiosco escrito directamente en la tabla: la deteccion lee hacia
 * atras lo que el fichaje ya escribio, y montar cinco dias de fichajes reales
 * por el caso de uso no anadiria nada a lo que esta prueba afirma.
 */
function kioskScanRow(
    string $employeeUuid,
    int $deviceId,
    string $occurredAt,
    string $result = 'clock_in',
    string $origin = 'qr_kiosk',
    ?int $clockSkewSeconds = null,
    ?string $shiftEntryUuid = null,
): void {
    DB::table('scan_events')->insert([
        'scan_id' => Str::uuid7()->toString(),
        'device_id' => $deviceId,
        'employee_id' => DB::table('employees')->where('uuid', $employeeUuid)->value('id'),
        'occurred_at' => $occurredAt,
        'recorded_at' => $occurredAt,
        'origin' => $origin,
        'intent' => 'auto',
        'result' => $result,
        'shift_entry_id' => $shiftEntryUuid === null
            ? null
            : DB::table('shift_entries')->where('uuid', $shiftEntryUuid)->value('id'),
        // `rejected_debounce` es un desenlace ACEPTADO (ADR-031) y lleva
        // acumulado; los rechazos de verdad no (RS-03). El `CHECK` de la tabla
        // lo exige, y esta distincion es justo la que la deteccion ignora: el
        // filtro de la consulta descarta todo lo que empieza por `rejected_`.
        'worked_minutes' => str_starts_with($result, 'rejected') && $result !== 'rejected_debounce' ? null : 0,
        'client_meta' => '{}',
        'clock_skew_seconds' => $clockSkewSeconds,
        'flagged_for_review' => false,
    ]);
}

/** Un tramo cerrado cualquiera: esta aqui para poder afirmar que NO cambia. */
function patternShiftEntry(string $employeeUuid, int $siteId, string $workDate, string $in, string $out): string
{
    $uuid = Str::uuid7()->toString();

    DB::table('shift_entries')->insert([
        'uuid' => $uuid,
        'employee_id' => DB::table('employees')->where('uuid', $employeeUuid)->value('id'),
        'site_id' => $siteId,
        'work_date' => $workDate,
        'clocked_in_at' => $in,
        'clocked_out_at' => $out,
        'duration_minutes' => intdiv(strtotime($out) - strtotime($in), 60),
        'status' => 'closed',
        'clock_in_source' => 'qr_kiosk',
        'clock_out_source' => 'qr_kiosk',
        'version' => 1,
        'created_at' => $in,
        'updated_at' => $in,
    ]);

    return $uuid;
}

/** La tabla `shift_entries` entera, ordenada, para compararla consigo misma. */
function shiftEntriesSnapshot(): string
{
    return json_encode(
        DB::table('shift_entries')->orderBy('id')->get()->map(
            static fn (object $row): array => (array) $row,
        )->all(),
        JSON_THROW_ON_ERROR,
    );
}

function runPatternDetection(string $now = PATTERN_NOW): int
{
    FrozenTime::at($now);

    return Artisan::call('attendance:detect-patterns');
}

/** La tarjeta de Ana, la unica que el doble del resolutor conoce. */
const TARJETA_DE_ANA = 'FH1.a3.7QK2mXpR9vLdN4tZbYcF1w.k9Xm2pQrT5vN8wLa';

/**
 * Un fichaje **de verdad**: por el endpoint del quiosco, con el token del
 * dispositivo y pasando por `RegisterScanHandler` (decision 15).
 *
 * Existe porque el anti-rebote de RF-AT-06 solo se escribe si el fichaje pasa
 * por su caso de uso: escribir a mano un `rejected_debounce` daria por buena la
 * premisa que esta prueba quiere comprobar. Devuelve el `action` de la respuesta
 * —`clock_in`, `clock_out` o `debounced`— para poder afirmarla.
 */
function fichaje(int $deviceId, string $occurredAt): string
{
    FrozenTime::at($occurredAt);

    $scanId = Str::uuid7()->toString();

    $response = Api::as(AttendanceFixtures::tokenFor($deviceId))
        ->withHeaders(['Idempotency-Key' => $scanId])
        ->post('/api/v1/scan', [
            'scan_id' => $scanId,
            'occurred_at' => str_replace(' ', 'T', $occurredAt).'Z',
            'qr_payload' => TARJETA_DE_ANA,
        ]);

    $response->assertOk();

    $action = $response->json('action');

    return is_string($action) ? $action : '';
}

/**
 * El Gherkin del doc 01 §11, literal: dos fichajes de empleados distintos en el
 * mismo quiosco separados por **4 segundos**, repetidos en las mismas dos
 * personas durante **cinco dias**.
 *
 * @param  array{site: int, ana: string, bruno: string, anaManager: int, brunoManager: int, device: int, otherDevice: int}  $scenario
 */
function fiveDaysOfCoincidence(array $scenario): void
{
    for ($day = 10; $day <= 14; $day++) {
        $date = sprintf('2026-03-%02d', $day);
        kioskScanRow($scenario['ana'], $scenario['device'], $date.' 06:00:00+00');
        kioskScanRow($scenario['bruno'], $scenario['device'], $date.' 06:00:04+00');
    }
}

it('abre las dos incidencias del par, asignadas a sus responsables, SIN tocar ningun fichaje', function (): void {
    // EL ESCENARIO INELUDIBLE del doc 01 §9.4 y el Gherkin del §11, al pie de la
    // letra. Lo que se afirma son las dos mitades de RF-PR-06: que el indicio
    // llega a la bandeja de quien tiene que mirarlo, y que **el sistema no marca
    // el fichaje como fraudulento ni lo anula**.
    Notification::fake();

    $scenario = patternScenario();
    patternShiftEntry($scenario['ana'], $scenario['site'], '2026-03-10', '2026-03-10 06:00:00+00', '2026-03-10 14:00:00+00');
    patternShiftEntry($scenario['bruno'], $scenario['site'], '2026-03-10', '2026-03-10 06:00:04+00', '2026-03-10 14:00:00+00');

    fiveDaysOfCoincidence($scenario);

    $before = shiftEntriesSnapshot();

    expect(runPatternDetection())->toBe(0);

    $incidents = DB::table('incidents')->orderBy('id')->get();

    expect($incidents)->toHaveCount(2)
        ->and($incidents->pluck('type')->unique()->all())->toBe(['anomalous_pattern'])
        ->and($incidents->pluck('status')->unique()->all())->toBe(['open'])
        // La jornada del hallazgo es el ULTIMO dia con coincidencia (decision
        // 13b): no depende del borde de la ventana, asi que no avanza sola cada
        // noche y `one_incident_per_finding` absorbe la repeticion.
        ->and($incidents->pluck('work_date')->unique()->all())->toBe(['2026-03-14'])
        ->and($incidents->pluck('shift_entry_id')->unique()->all())->toBe([null])
        ->and($incidents->pluck('assigned_to_user_id')->sort()->values()->all())
        ->toBe(collect([$scenario['anaManager'], $scenario['brunoManager']])->sort()->values()->all());

    // LA MITAD QUE RF-PR-06 §9.4 PROTEGE: ni un tramo ha cambiado de estado, de
    // hora ni de version. La tabla entera, byte a byte.
    expect(shiftEntriesSnapshot())->toBe($before);

    // Y ninguna respuesta del sistema califica nada: `anomalous_pattern` describe
    // lo observado, no una conclusion.
    expect($incidents->pluck('status')->all())->not->toContain('fraud');
})->group('RF-PR-06', 'RF-PA-05');

it('deja el asiento de auditoria de cada incidencia, con identificadores y sin nombres', function (): void {
    // Regla dura 6: la apertura tiene relevancia legal y entra en `audit_log` en
    // la MISMA transaccion que la fila. Regla dura 21: el asiento viaja entero en
    // la exportacion legal, asi que no puede llevar el nombre de nadie -ni el de
    // la contraparte, que es justo el dato nuevo de esta incidencia-.
    Notification::fake();

    $scenario = patternScenario();
    fiveDaysOfCoincidence($scenario);

    runPatternDetection();

    $entries = DB::table('audit_log')->where('action', AuditAction::IncidentOpened->value)->get();

    expect($entries)->toHaveCount(2)
        ->and($entries->pluck('actor_type')->unique()->all())->toBe(['system'])
        ->and($entries->pluck('subject_type')->unique()->all())->toBe(['incident']);

    /** @var array<string, mixed> $payload */
    $payload = json_decode((string) $entries->first()?->payload, true, 512, JSON_THROW_ON_ERROR);

    expect($payload['type'])->toBe('anomalous_pattern')
        ->and($payload['employee_uuid'])->toBeIn([$scenario['ana'], $scenario['bruno']]);

    $serialized = $entries->pluck('payload')->implode(' ');

    expect($serialized)->not->toContain('Ana')
        ->and($serialized)->not->toContain('Gomez')
        ->and($serialized)->not->toContain('Bruno')
        ->and($serialized)->not->toContain('Ferrer');
})->group('RF-PR-06', 'RF-PA-05', 'RS-05');

it('no duplica nada al ejecutar el comando dos veces', function (): void {
    // La idempotencia la garantiza `one_incident_per_finding` con
    // `NULLS NOT DISTINCT`, no un `SELECT` previo: por eso la prueba corre el
    // comando entero dos veces en vez de llamar al caso de uso.
    Notification::fake();

    $scenario = patternScenario();
    fiveDaysOfCoincidence($scenario);

    runPatternDetection();
    runPatternDetection();

    expect(DB::table('incidents')->count())->toBe(2)
        // Y tampoco se duplica el asiento: cuando la incidencia ya existia no ha
        // pasado nada nuevo que auditar.
        ->and(DB::table('audit_log')->where('action', AuditAction::IncidentOpened->value)->count())->toBe(2);
})->group('RF-PR-06', 'RS-05');

it('abre la incidencia de RN-16 sobre dos fichajes REALES en dos quioscos sin tiempo de transito', function (): void {
    // RN-16 con los dos quioscos, que es lo que la distingue de un anti-rebote.
    //
    // LOS DOS ESCANEOS SE REGISTRAN POR EL CAMINO DE FICHAJE DE VERDAD
    // (decision 15): escribirlos a mano producia un par que el sistema real
    // **nunca escribe**, porque el anti-rebote de RF-AT-06 es por PERSONA y no
    // por quiosco, asi que la segunda presentacion de la misma tarjeta 30 s
    // despues en otra tablet sale `rejected_debounce`. Ese era el agujero: la
    // consulta lo descartaba y RN-16 quedaba ciega justo en la franja que nadie
    // puede explicar, activa solo entre los 60 s del anti-rebote y los 120 s del
    // transito minimo.
    Notification::fake();

    $scenario = patternScenario();
    app()->instance(
        CredentialResolver::class,
        FakeCredentialResolver::new()->resolving(TARJETA_DE_ANA, $scenario['ana']),
    );

    $primero = fichaje($scenario['device'], '2026-03-14 06:00:00');
    $segundo = fichaje($scenario['otherDevice'], '2026-03-14 06:00:30');

    // La premisa de la prueba, escrita: el segundo fichaje es un anti-rebote de
    // verdad. Si algun dia dejara de serlo, esta prueba no estaria comprobando
    // lo que dice comprobar.
    expect($primero)->toBe('clock_in')
        ->and($segundo)->toBe('debounced')
        ->and(DB::table('scan_events')->where('result', 'rejected_debounce')->count())->toBe(1);

    expect(runPatternDetection())->toBe(0);

    $incident = DB::table('incidents')->first();

    expect($incident)->not->toBeNull()
        ->and($incident?->type)->toBe('anomalous_pattern')
        ->and($incident?->work_date)->toBe('2026-03-14')
        ->and($incident?->assigned_to_user_id)->toBe($scenario['anaManager'])
        // DECISION 14: el segundo escaneo no produjo tramo -es un anti-rebote-,
        // asi que la incidencia senala el del primero en vez de quedarse sin
        // ninguno.
        ->and($incident?->shift_entry_id)->not->toBeNull();

    /** @var array<string, mixed> $context */
    $context = json_decode((string) $incident?->context, true, 512, JSON_THROW_ON_ERROR);

    expect($context['pattern'])->toBe('impossible_sequence')
        ->and($context['gap_seconds'])->toBe(30)
        ->and($context['transit_seconds'])->toBe(120)
        ->and($context['from_device_id'])->toBe($scenario['device'])
        ->and($context['to_device_id'])->toBe($scenario['otherDevice']);
})->group('RN-16', 'RF-PR-06');

it('no vuelve a abrir el mismo indicio mientras siga abierto en la bandeja', function (): void {
    // DECISION 13c, hallazgo R-1 de seguridad. La segunda noche hay un dia mas de
    // coincidencia —el habito sigue— y la bandeja NO crece: el indicio ya esta
    // sobre la mesa de alguien. Sin esto, las mismas dos personas estrenaban dos
    // incidencias `high` cada madrugada y la bandeja que el runbook manda vaciar
    // crecia sola.
    Notification::fake();

    $scenario = patternScenario();
    fiveDaysOfCoincidence($scenario);

    runPatternDetection();

    expect(DB::table('incidents')->count())->toBe(2);

    // Al dia siguiente vuelven a coincidir, y la pasada de esa noche los ve.
    kioskScanRow($scenario['ana'], $scenario['device'], '2026-03-15 06:00:00+00');
    kioskScanRow($scenario['bruno'], $scenario['device'], '2026-03-15 06:00:04+00');

    runPatternDetection('2026-03-21 04:35:00');

    expect(DB::table('incidents')->count())->toBe(2)
        ->and(DB::table('audit_log')->where('action', AuditAction::IncidentOpened->value)->count())->toBe(2);
})->group('RF-PR-06');

it('cuenta como fallo, y lo dice en el log, el indicio que choca con otro distinto', function (): void {
    // DECISION 13d, bloqueante B2. Dos indicios distintos de la misma persona y
    // el mismo dia comparten la cuadrupla de `one_incident_per_finding`: la
    // coincidencia sistematica de Ana y su secuencia imposible del ultimo dia.
    // Seguir teniendo UNA sola incidencia es correcto -la bandeja no puede tener
    // dos filas ahi-; lo que no puede ser es que el segundo desaparezca sin
    // fila, sin asiento, sin fallo y sin log, que es lo que pasaba.
    Notification::fake();

    /** @var array<string, mixed> $logged */
    $logged = [];
    Event::listen(MessageLogged::class, function (MessageLogged $message) use (&$logged): void {
        $logged[$message->message] = $message->context;
    });

    $scenario = patternScenario();
    fiveDaysOfCoincidence($scenario);

    // El ultimo dia, Ana aparece ademas en el otro quiosco veinte segundos
    // despues: imposible, y sin tramo que senalar en ninguno de los dos
    // escaneos, asi que cae en la misma cuadrupla que su coincidencia.
    kioskScanRow($scenario['ana'], $scenario['otherDevice'], '2026-03-14 06:00:20+00');

    // Codigo de salida distinto de cero: la pasada dejo algo sin escribir.
    expect(runPatternDetection())->not->toBe(0);

    expect(DB::table('incidents')->where('work_date', '2026-03-14')->count())->toBe(2)
        ->and($logged)->toHaveKey('attendance.pattern_incident_collided');

    /** @var array<string, mixed> $context */
    $context = $logged['attendance.pattern_incident_collided'];

    expect($context['employee_uuid'])->toBe($scenario['ana'])
        ->and($context['work_date'])->toBe('2026-03-14')
        ->and($context['pattern'])->toBe('impossible_sequence')
        // Regla dura 21: identificadores y nada mas.
        ->and(array_keys($context))->toBe(['employee_uuid', 'work_date', 'pattern']);
})->group('RF-PR-06', 'RS-05');

it('no mira los escaneos que no son un uso de credencial en quiosco', function (): void {
    // Decision 4 de la ficha, corregida por la 15. Lo que queda fuera son los
    // TRES rechazos de verdad —desconocido, revocado y firma mala: ninguno es
    // una tarjeta valida presentada por alguien, y muchos ni siquiera resuelven
    // a una persona— y los origenes que no pasan por una tablet. Si un fichaje
    // manual entrara, una correccion administrativa hecha en dos minutos abriria
    // un indicio contra la persona corregida.
    //
    // `rejected_debounce` SI entra, y tiene su propia prueba: es un desenlace
    // aceptado (ADR-031) y sin el RN-16 quedaba ciega (decision 15).
    Notification::fake();

    $scenario = patternScenario();

    foreach (['rejected_unknown', 'rejected_revoked', 'rejected_signature'] as $index => $rechazo) {
        for ($day = 10; $day <= 14; $day++) {
            $date = sprintf('2026-03-%02d', $day);
            $hora = sprintf('%02d', 6 + $index * 2);

            kioskScanRow($scenario['ana'], $scenario['device'], $date.' '.$hora.':00:00+00', result: $rechazo);
            kioskScanRow($scenario['bruno'], $scenario['device'], $date.' '.$hora.':00:04+00', origin: 'manual_admin');
        }
    }

    // Y la secuencia imposible con la misma pareja de filas descartadas.
    kioskScanRow($scenario['ana'], $scenario['otherDevice'], '2026-03-14 06:00:10+00', result: 'rejected_unknown');

    expect(runPatternDetection())->toBe(0)
        ->and(DB::table('incidents')->count())->toBe(0);
})->group('RF-PR-06');

it('no escribe ningun nombre en el log tecnico ni en el historico de errores', function (): void {
    // Regla dura 21 y RF-PD-15. El log viaja a Loki y de ahi al paquete de
    // diagnostico (ADR-020), y `error_events` se envia entera al fabricante: si
    // el nombre de la persona senalada por un indicio viajara ahi, la fuga no
    // aparece en ninguna prueba, aparece en una exportacion.
    Notification::fake();

    /** @var array<string, mixed> $logged */
    $logged = [];

    // `Event::listen` y no `Log::spy()`: el doble del facade deja el contenedor
    // devolviendo `null` por `LoggerInterface`, y medio modulo `Product` se
    // construye con el. Aqui se escucha al logger de verdad.
    Event::listen(MessageLogged::class, function (MessageLogged $message) use (&$logged): void {
        $logged[$message->message] = $message->context;
    });

    $scenario = patternScenario();
    fiveDaysOfCoincidence($scenario);

    runPatternDetection();

    expect($logged)->toHaveKey('attendance.pattern_detection');

    $serialized = json_encode($logged, JSON_THROW_ON_ERROR);

    expect($serialized)->not->toContain('Ana')
        ->and($serialized)->not->toContain('Gomez')
        ->and($serialized)->not->toContain('Bruno')
        ->and($serialized)->not->toContain('Ferrer')
        // Ni siquiera el UUID: el apunte de la pasada son recuentos y ventana,
        // y de quien es cada indicio se mira en la bandeja, con autorizacion.
        ->and($serialized)->not->toContain($scenario['ana'])
        ->and($serialized)->not->toContain($scenario['bruno']);

    expect(DB::table('error_events')->count())->toBe(0);
})->group('RF-PD-15', 'RF-PR-06');
