<?php

declare(strict_types=1);

use App\Modules\Product\Application\UseCase\GenerateDiagnosticsBundleHandler;
use App\Modules\Product\Application\UseCase\RecordErrorEvent;
use App\Modules\Product\Domain\ValueObject\DiagnosticsActor;
use App\Modules\Product\Domain\ValueObject\DiagnosticsOptions;
use App\Modules\Product\Domain\ValueObject\ErrorMessageSanitizer;
use App\Modules\Product\Infrastructure\Capture\ExecutionContext;
use App\Modules\Shared\Application\Port\ErrorEventSink;
use App\Modules\Shared\Domain\ValueObject\ErrorLevel;
use App\Modules\Shared\Domain\ValueObject\ErrorReport;
use App\Modules\Shared\Domain\ValueObject\ErrorSource;
use App\Modules\Shared\Domain\ValueObject\UserRole;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Tests\Support\Attendance\AttendanceFixtures;
use Tests\Support\Database\RefreshDatabase;
use Tests\Support\Http\Api;
use Tests\Support\Identity\ManagementUsers;
use Tests\Support\Identity\PortalLogins;
use Tests\Support\Product\ErrorHistoryConnection;
use Tests\Support\Product\LicenseKeys;
use Tests\Support\Product\SeededPersonalData;
use Tests\Support\Time\FrozenTime;
use Tests\Support\Workforce\WorkforceFixtures;

/*
 * La prueba que decide si esta tarea esta bien hecha (ficha 5.9, §9.5 «con
 * volumen»): **un paquete generado sobre 500 empleados y 90 dias de fichajes, y
 * se inspecciona el contenido completo**.
 *
 * La pregunta que ADR-020 obliga a responder, escrita en la ficha con estas
 * palabras: *si tomo un paquete generado en una instalacion real con 500
 * empleados, ¿puedo identificar a alguien?*
 *
 * ## Por que con volumen y no con dos filas
 *
 * Porque con dos filas cualquier fuga se ve a simple vista y ninguna prueba hace
 * falta. Lo que se busca aqui es lo contrario: **el campo que se cuela entre
 * cientos de miles de caracteres** —un `first_name` en el `details` de una sonda,
 * un nombre de quiosco en una etiqueta de metrica, una direccion de correo en un
 * informe de actualizacion— y que nadie encontraria leyendo.
 *
 * Los datos se escriben con el constructor de consultas y no por los casos de
 * uso: lo que se comprueba es la SALIDA del paquete, y pasar por el camino de
 * fichaje 45.000 veces convertiria esta prueba en algo que nadie ejecuta.
 */

uses(RefreshDatabase::class);

/** Nombres, correos y DNI reconocibles: si alguno sale, se sabe por donde. */
const NOMBRES_SEMBRADOS = ['Marta', 'Filomena', 'Anastasio', 'Cunegunda'];

const APELLIDOS_SEMBRADOS = ['Lopez Garcia', 'Zaldivar Pou', 'Etxeberria Uribe'];

const CORREO_SEMBRADO = 'filomena.zaldivar@hotel-ejemplo.example';

const DNI_SEMBRADO = '49871234Z';

const NOMBRE_DE_QUIOSCO_SEMBRADO = 'Tablet de Anastasio (recepcion)';

/**
 * Una instalacion con 500 personas, tres quioscos y 90 dias de fichajes.
 *
 * @return list<string> Los UUID de los empleados.
 */
function instalacionConVolumen(): array
{
    $siteId = WorkforceFixtures::site();
    $departmentId = WorkforceFixtures::department($siteId, 'Pisos');

    $employees = [];
    $rows = [];

    for ($index = 0; $index < 500; $index++) {
        $uuid = Str::uuid7()->toString();
        $employees[] = $uuid;

        $rows[] = [
            'uuid' => $uuid,
            'site_id' => $siteId,
            'department_id' => $departmentId,
            'first_name' => NOMBRES_SEMBRADOS[$index % \count(NOMBRES_SEMBRADOS)],
            'last_name' => APELLIDOS_SEMBRADOS[$index % \count(APELLIDOS_SEMBRADOS)],
            'employee_code' => 'E'.str_pad((string) $index, 6, '0', STR_PAD_LEFT),
            // El correo es OPCIONAL en este producto (regla dura 12) y aqui se
            // rellena a proposito: si el paquete lo sacara, seria justo el dato
            // que ADR-020 promete que nunca sale.
            'email' => $index % 7 === 0 ? $index.'.'.CORREO_SEMBRADO : null,
            // Unico por fila: la columna lleva indice unico. El sufijo no cambia
            // lo que la prueba busca -que la raiz del DNI no salga del paquete-.
            'national_id_hash' => $index % 5 === 0 ? DNI_SEMBRADO.$index : null,
            'status' => 'active',
            'hired_at' => '2026-01-01',
            'locale' => 'es',
            'created_at' => '2026-01-01T00:00:00Z',
            'updated_at' => '2026-01-01T00:00:00Z',
        ];
    }

    DB::table('employees')->insert($rows);

    /** @var list<int> $employeeIds */
    $employeeIds = DB::table('employees')->orderBy('id')->pluck('id')->all();

    // Tres quioscos, uno de ellos con un nombre de persona dentro: es el caso
    // real por el que `devices.name` no sale del paquete.
    $devices = [];

    foreach (['Recepcion', NOMBRE_DE_QUIOSCO_SEMBRADO, 'Cocina'] as $position => $name) {
        $devices[] = [
            'uuid' => Str::uuid7()->toString(),
            'site_id' => $siteId,
            'name' => $name,
            'app_version' => '2.1.0',
            'status' => 'active',
            'pending_queue_size' => $position,
            'last_seen_at' => '2026-06-15T08:00:00Z',
            'created_at' => '2026-01-01T00:00:00Z',
            'updated_at' => '2026-01-01T00:00:00Z',
        ];
    }

    DB::table('devices')->insert($devices);

    /** @var list<int> $deviceIds */
    $deviceIds = DB::table('devices')->orderBy('id')->pluck('id')->all();

    // 90 dias de jornadas. Se insertan por lotes: 45.000 filas de una sentencia
    // agotan la memoria del driver.
    for ($day = 0; $day < 90; $day++) {
        $date = (new DateTimeImmutable('2026-03-17T00:00:00Z'))->modify('+'.$day.' days');
        $shifts = [];

        foreach ($employeeIds as $position => $employeeId) {
            $in = $date->modify('+7 hours')->format(DATE_ATOM);
            $out = $date->modify('+15 hours')->format(DATE_ATOM);

            $shifts[] = [
                'uuid' => Str::uuid7()->toString(),
                'employee_id' => $employeeId,
                'site_id' => $siteId,
                'work_date' => $date->format('Y-m-d'),
                'clocked_in_at' => $in,
                'clocked_out_at' => $out,
                'duration_minutes' => 480,
                'status' => 'closed',
                'clock_in_source' => 'qr_kiosk',
                'clock_out_source' => 'qr_kiosk',
                'version' => 1,
                'created_at' => $in,
                'updated_at' => $out,
            ];

            if ($position > 40) {
                // Con 500 x 90 la prueba tarda minutos sin aportar nada nuevo:
                // el volumen que importa es el de la PLANTILLA, que es lo que se
                // inspecciona campo a campo. Se dejan 41 personas fichando los 90
                // dias, que da unos 3.700 tramos.
                break;
            }
        }

        DB::table('shift_entries')->insert($shifts);
    }

    // Un escaneo por dispositivo, con `client_meta` cargado: es el campo por el
    // que se filtraria lo que la tablet quiso mandar y nadie reviso.
    DB::table('scan_events')->insert([[
        'scan_id' => Str::uuid7()->toString(),
        'device_id' => $deviceIds[0],
        'employee_id' => $employeeIds[0],
        'occurred_at' => '2026-06-15T07:00:00Z',
        'recorded_at' => '2026-06-15T07:00:01Z',
        'origin' => 'qr_kiosk',
        'intent' => 'auto',
        'result' => 'clock_in',
        'worked_minutes' => 0,
        'client_meta' => json_encode(['user_agent' => NOMBRE_DE_QUIOSCO_SEMBRADO, 'note' => CORREO_SEMBRADO]),
    ]]);

    return $employees;
}

/**
 * Errores reales del periodo, con PII dentro, escritos POR EL CAMINO REAL.
 *
 * ## Por que por el sumidero y no con `INSERT`
 *
 * Porque lo que se comprueba aqui no es que el paquete filtre columnas —eso ya
 * lo hace `ErrorEventsInDiagnosticsAndExportTest` con filas puestas a mano—,
 * sino que **el saneado que el producto aplica de verdad basta**. Escribir la
 * fila a mano se saltaria {@see RecordErrorEvent},
 * que es el unico camino de entrada, y la prueba pasaria afirmando algo que el
 * producto no hace.
 *
 * ## Las formas de PII estan elegidas, no inventadas
 *
 * Son las cuatro que un error de este producto puede llevar dentro:
 *
 * - un **nombre interpolado entre comillas**, que es como lo escriben PHP
 *   (`Employee '…' not found`) y PostgreSQL (`Key (…)=(…)`);
 * - un **correo** de la plantilla, que aqui es opcional y aun asi se rellena;
 * - un **DNI**;
 * - una **hora de fichaje**, que es dato de jornada de una persona concreta y
 *   esta tabla no guarda jornadas (RL-19).
 *
 * Y una quinta que no es PII pero lo parece: una clave de contexto inventada
 * (`employee_name`) que **no esta en la lista de permitidos** y tiene que caerse
 * entera.
 *
 * ## El `employee_uuid` es de la semilla a proposito
 *
 * Es el unico identificador de persona que ADR-020 admite en el paquete, y solo
 * porque es seudonimo: sin el, soporte no puede decir «los tres errores son de
 * la misma persona». La prueba afirma que **viaja**, para que quede escrito que
 * es una decision y no un descuido, y que el nombre de esa misma persona no.
 *
 * ## El nombre suelto, sin comillas, lo cubren las pruebas sembradas de abajo
 *
 * Hasta la 2.2.0 esta prueba no podia afirmar que un nombre **suelto, en
 * prosa** no saliera: {@see ErrorMessageSanitizer} era una lista negra de
 * patrones. ADR-048 lo cambio por una lista blanca de palabras, y las pruebas
 * «sembradas» del final de este fichero lo afirman por las tres puertas, en
 * todas las formas que pidio el dictamen de seguridad del bloque 19.
 *
 * @return int Cuantos grupos quedaron escritos, contando desde el primero.
 */
function erroresConPiiDeLaPlantilla(string $employeeUuid, string $deviceUuid): int
{
    /** @var ErrorEventSink $sumidero */
    $sumidero = app(ErrorEventSink::class);

    return $sumidero->recordAll([
        new ErrorReport(
            source: ErrorSource::Api,
            level: ErrorLevel::Error,
            message: "Employee 'Marta Lopez Garcia' not found for national id ".DNI_SEMBRADO,
            occurredAt: new DateTimeImmutable('2026-06-15T07:30:00Z'),
            appVersion: '2.1.0',
            context: [
                'route' => 'api/v1/scan',
                'method' => 'POST',
                'reason' => "la credencial de 'Filomena Zaldivar Pou' no resuelve",
                'employee_name' => 'Anastasio Etxeberria Uribe',
            ],
            exceptionClass: 'RuntimeException',
            employeeUuid: $employeeUuid,
            module: 'attendance',
        ),
        new ErrorReport(
            source: ErrorSource::Worker,
            level: ErrorLevel::Critical,
            message: 'SQLSTATE[23505] duplicate key value violates unique constraint "employees_email_unique" '
                .'DETAIL: Key (email)=('.CORREO_SEMBRADO.') already exists.',
            occurredAt: new DateTimeImmutable('2026-06-15T07:45:00Z'),
            appVersion: '2.1.0',
            context: ['job' => 'ImportEmployeesJob', 'queue' => 'default', 'attempts' => 3],
            exceptionClass: 'Illuminate\Database\QueryException',
            module: 'workforce',
        ),
        new ErrorReport(
            source: ErrorSource::Kiosk,
            level: ErrorLevel::Error,
            message: "no se pudo cerrar el turno de las 07:00:00 de 'Cunegunda Lopez Garcia' en "
                ."'".NOMBRE_DE_QUIOSCO_SEMBRADO."'",
            occurredAt: new DateTimeImmutable('2026-06-15T08:10:00Z'),
            appVersion: '2.1.0',
            context: ['scope' => 'queue', 'entries' => 4, 'cause' => 'network'],
            deviceId: $deviceUuid,
            employeeUuid: $employeeUuid,
            module: 'kiosk',
        ),
    ]);
}

beforeEach(function (): void {
    FrozenTime::at('2026-06-15 09:00:00');
    LicenseKeys::grantAll();

    // `error_events` se escribe por una conexion propia y esta suite no tiene el
    // enganche global que si tiene `Feature` (ver tests/Pest.php): sin el puente,
    // lo que escriba esta prueba sobrevive a su propio `RefreshDatabase`.
    ErrorHistoryConnection::shareTestTransaction();
});

afterEach(function (): void {
    ErrorHistoryConnection::release();
});

it('genera un paquete anonimizado sobre 500 empleados y 90 dias sin una sola PII', function (): void {
    $employees = instalacionConVolumen();

    $bundle = app(GenerateDiagnosticsBundleHandler::class)
        ->handle(DiagnosticsOptions::anonymized(), DiagnosticsActor::User);

    $json = $bundle->toJson();

    // --- Nada que identifique a una persona ---------------------------------

    foreach (NOMBRES_SEMBRADOS as $name) {
        expect($json)->not->toContain($name, 'El paquete anonimizado contiene el nombre «'.$name.'».');
    }

    foreach (APELLIDOS_SEMBRADOS as $surname) {
        expect($json)->not->toContain($surname, 'El paquete anonimizado contiene el apellido «'.$surname.'».');
    }

    expect($json)->not->toContain(CORREO_SEMBRADO)
        ->and($json)->not->toContain('@hotel-ejemplo.example')
        ->and($json)->not->toContain(DNI_SEMBRADO)
        // Ni el nombre de un quiosco, que puede llevar el de una persona.
        ->and($json)->not->toContain(NOMBRE_DE_QUIOSCO_SEMBRADO)
        ->and($json)->not->toContain('Tablet de');

    // Ni un codigo de empleado (PR12, PR13, PR14): es un identificador directo y
    // la mitad de la credencial del portal. Los 500 de la semilla, uno a uno.
    /** @var list<string> $codigos */
    $codigos = DB::table('employees')->pluck('employee_code')->all();

    expect($codigos)->toHaveCount(500);

    $codigosQueSalen = array_values(array_filter(
        $codigos,
        static fn (string $codigo): bool => str_contains($json, $codigo),
    ));

    expect($codigosQueSalen)->toBe([], 'El paquete anonimizado contiene estos codigos de empleado: '
        .implode(', ', \array_slice($codigosQueSalen, 0, 10)));

    // --- Ni una hora de fichaje ---------------------------------------------

    // RL-19 en su literal: «sin registros de jornada». No hay ninguna seccion de
    // tramos, y por eso el UUID de un tramo tampoco aparece.
    /** @var object{uuid: string} $tramo */
    $tramo = DB::table('shift_entries')->orderBy('id')->first();

    expect($json)->not->toContain($tramo->uuid)
        ->and($bundle->sections)->not->toHaveKey('personal_data');

    // --- Ni un identificador de empleado, aunque sea un UUID ----------------

    // ADR-020 admite `employee_uuid` **donde haga falta** —el historico de
    // errores de la 5.12—, pero el paquete de hoy no tiene ninguna seccion que
    // lo necesite, asi que no aparece ninguno. Si alguna vez apareciera, seria
    // por una seccion nueva y esta linea obligaria a mirarla.
    foreach (\array_slice($employees, 0, 25) as $uuid) {
        expect($json)->not->toContain($uuid);
    }

    // --- Los quioscos si salen, y solo por su identificador -----------------

    /** @var list<array<string, mixed>> $kiosks */
    $kiosks = $bundle->sections['kiosks'];

    expect($kiosks)->toHaveCount(3);

    foreach ($kiosks as $kiosk) {
        expect(array_keys($kiosk))->toBe([
            'uuid', 'status', 'app_version', 'last_seen_at', 'pending_queue_size',
            // ADR-047 (2.2.0): donde guarda la cola y cuantos descartes no ha
            // avisado. Solo recuentos.
            'queue_storage', 'unreported_discards',
            // PR13: lo que hace falta para «el quiosco no sincroniza».
            'oldest_pending_at', 'battery_level', 'battery_charging', 'paired_at', 'token_expires_on',
        ]);
    }

    // PR14: los recuentos de volumen salen y son solo numeros.
    /** @var array{volume: array<string, int>} $installation */
    $installation = $bundle->sections['installation'];

    expect($installation['volume']['active_employees'])->toBe(500)
        ->and($installation['volume']['scan_events_last_30_days'])->toBe(1)
        ->and(array_filter($installation['volume'], is_int(...)))->toHaveCount(4);

    // --- Y el paquete sirve para algo ---------------------------------------

    // La otra mitad: un paquete vacio pasaria todo lo de arriba y no serviria
    // para diagnosticar nada.
    expect($bundle->manifest->sections)->toHaveCount(10)
        ->and($bundle->sections['doctor'])->toHaveKey('checks')
        ->and($bundle->sections['services'])->toHaveKey('database')
        ->and($bundle->sections['installation'])->toHaveKey('compliance_profile');
})->group('RF-PD-09', 'RL-19');

it('no lleva ningun secreto de la instalacion', function (): void {
    instalacionConVolumen();

    $json = app(GenerateDiagnosticsBundleHandler::class)
        ->handle(DiagnosticsOptions::anonymized(), DiagnosticsActor::User)
        ->toJson();

    // Los nombres de las variables tampoco, porque una clave ausente no dice
    // nada y una «redactada» confirma que existe.
    foreach ([
        'LICENSE_KEY', 'QR_SIGNING_KEY', 'BACKUP_ENCRYPTION_KEY', 'REVERB_APP_SECRET',
        'DB_PASSWORD', 'DB_USERNAME', 'REDIS_PASSWORD', 'APP_KEY', 'MAIL_PASSWORD',
        'IDENTITY_PIN_SEALING_SECRET_KEY', 'GRAFANA_ADMIN_PASSWORD',
    ] as $secret) {
        expect($json)->not->toContain($secret, 'El paquete menciona la clave secreta '.$secret.'.');
    }

    // Y tampoco la razon social del cliente, que es el riesgo que el doc 07 §6
    // dejo abierto en la tarea 5.3 y que se cierra aqui.
    expect($json)->not->toContain('customer_name')
        ->and($json)->not->toContain(LicenseKeys::defaults()['customer_name']);
})->group('RF-PD-09', 'RS-08');

it('con datos personales lleva la plantilla, pero nunca el DNI ni el correo ni client_meta', function (): void {
    // La bandera autoriza a enviar la plantilla; **no** convierte el hash de un
    // DNI ni el de un PIN en algo que soporte necesite. Y `client_meta` sigue
    // fuera: su forma no la controla nadie.
    instalacionConVolumen();

    $bundle = app(GenerateDiagnosticsBundleHandler::class)
        ->handle(DiagnosticsOptions::withPersonalData(7), DiagnosticsActor::User);

    $json = $bundle->toJson();

    expect($bundle->manifest->anonymized)->toBeFalse()
        ->and($json)->toContain('Marta Lopez Garcia')
        // Y aun asi:
        ->and($json)->not->toContain(DNI_SEMBRADO)
        ->and($json)->not->toContain(CORREO_SEMBRADO)
        ->and($json)->not->toContain('client_meta')
        ->and($json)->not->toContain(NOMBRE_DE_QUIOSCO_SEMBRADO);

    /** @var array<string, mixed> $personal */
    $personal = $bundle->sections['personal_data'];

    /** @var array{items: list<array<string, mixed>>, total: int, truncated: bool} $employees */
    $employees = $personal['employees'];

    // SOLO LAS PERSONAS DEL PERIODO, no la plantilla entera (RL-19,
    // minimizacion). Con 500 dadas de alta y una ventana de 7 dias, solo fichan
    // las 41 que el escenario hace fichar (mas la del escaneo, que es una de
    // ellas). Sacar las 500 seria enviar la plantilla completa del hotel para
    // diagnosticar el problema de una persona.
    $total = DB::table('employees')->count();

    expect($total)->toBe(500)
        ->and($employees['total'])->toBeLessThan($total)
        ->and($employees['total'])->toBeGreaterThan(0)
        ->and($employees['truncated'])->toBeFalse()
        ->and(array_keys($employees['items'][0]))
        ->toBe(['uuid', 'employee_code', 'full_name', 'status', 'department_id']);

    // Y toda ficha incluida se puede poner en cara a algo del propio paquete:
    // si no aparece en ningun tramo, escaneo ni incidencia, no pinta nada aqui.
    /** @var array{items: list<array<string, mixed>>} $shifts */
    $shifts = $personal['shift_entries'];
    /** @var array{items: list<array<string, mixed>>} $scans */
    $scans = $personal['scan_events'];
    /** @var array{items: list<array<string, mixed>>} $incidents */
    $incidents = $personal['incidents'];

    $uuidsOf = static fn (array $items): array => array_values(array_filter(
        array_map(
            static fn (array $item): mixed => $item['employee_uuid'] ?? null,
            $items,
        ),
        is_string(...),
    ));

    $referenced = array_unique([
        ...$uuidsOf($shifts['items']),
        ...$uuidsOf($scans['items']),
        ...$uuidsOf($incidents['items']),
    ]);

    $included = array_values(array_filter(
        array_map(static fn (array $item): mixed => $item['uuid'] ?? null, $employees['items']),
        is_string(...),
    ));

    $unreferenced = array_values(array_diff($included, $referenced));

    expect($unreferenced)->toBe([], 'Estas fichas viajan sin aparecer en ningun tramo, fichaje ni incidencia: '
        .implode(', ', $unreferenced));
})->group('RF-PD-09', 'RL-19');

it('cabe en el tope configurado y dice de que ha prescindido', function (): void {
    // Un paquete que no llega a soporte no sirve de nada: lo corta el correo del
    // hotel. Cuando no cabe, se renuncia en el orden fijo de `DiagnosticsBundle`
    // y **queda anotado**, nunca en silencio.
    instalacionConVolumen();

    config(['product.diagnostics_max_bytes' => 40_000]);

    $bundle = app(GenerateDiagnosticsBundleHandler::class)
        ->handle(DiagnosticsOptions::withPersonalData(31), DiagnosticsActor::User);

    /** @var array<string, mixed> $personal */
    $personal = $bundle->sections['personal_data'];

    expect($personal['status'])->toBe('omitted')
        ->and($personal['reason'])->toBe('size_limit')
        ->and($personal['bytes'])->toBeGreaterThan(0)
        // Las seis secciones que responden a las primeras preguntas de cualquier
        // incidencia no se sacrifican nunca.
        ->and($bundle->sections['doctor'])->toHaveKey('checks')
        ->and($bundle->sections['installation'])->toHaveKey('product_version')
        ->and($bundle->sections['configuration'])->toHaveKey('env')
        ->and($bundle->sections['license'])->toHaveKey('state');
})->group('RF-PD-09');

it('lleva el historico de errores del periodo y ni una PII de las que los errores traian dentro', function (): void {
    // --- Arrange ------------------------------------------------------------

    $employees = instalacionConVolumen();

    /** @var string $deviceUuid */
    $deviceUuid = DB::table('devices')->orderBy('id')->value('uuid');

    $escritos = erroresConPiiDeLaPlantilla($employees[0], $deviceUuid);

    expect($escritos)->toBe(3, 'El sumidero no pudo escribir los tres errores sembrados.');

    // --- Act ----------------------------------------------------------------

    $bundle = app(GenerateDiagnosticsBundleHandler::class)
        ->handle(DiagnosticsOptions::anonymized(), DiagnosticsActor::User);

    $json = $bundle->toJson();

    /** @var array{status: string, total_groups: int, groups: list<array<string, mixed>>, summary: array{open: int}} $errores */
    $errores = $bundle->sections['error_events'];

    // --- Assert: la seccion sirve para diagnosticar --------------------------

    // Un paquete que dijera `unavailable` o que llegara con cero grupos pasaria
    // sin esfuerzo todo lo de abajo y no valdria para nada: soporte descartaria
    // la hipotesis correcta creyendo que no ha habido errores.
    expect($errores['status'])->toBe('ok')
        ->and($errores['total_groups'])->toBe(3)
        ->and($errores['groups'])->toHaveCount(3)
        ->and($errores['summary']['open'])->toBe(3);

    // --- Assert: ni un nombre, ni un correo, ni un DNI, ni una hora ----------

    foreach (NOMBRES_SEMBRADOS as $name) {
        expect($json)->not->toContain($name, 'El paquete contiene el nombre «'.$name.'», que venia dentro de un error.');
    }

    foreach (APELLIDOS_SEMBRADOS as $surname) {
        expect($json)->not->toContain($surname, 'El paquete contiene el apellido «'.$surname.'».');
    }

    expect($json)->not->toContain(CORREO_SEMBRADO)
        ->and($json)->not->toContain('@hotel-ejemplo.example')
        ->and($json)->not->toContain(DNI_SEMBRADO)
        ->and($json)->not->toContain(NOMBRE_DE_QUIOSCO_SEMBRADO)
        // La hora de fichaje que iba en el mensaje del quiosco: es dato de
        // jornada de una persona concreta y esta tabla no guarda jornadas.
        ->and($json)->not->toContain('07:00:00')
        // Y la clave de contexto que nadie declaro: se cae entera, con su valor.
        ->and($json)->not->toContain('employee_name');

    // --- Assert: ni el UUID del empleado; el de la tablet si ------------------

    // ADR-048 (H2): `employee_uuid` es un seudonimo cuya correspondencia tiene
    // el hotel, asi que el paquete ANONIMIZADO no lo lleva —ni en la columna ni
    // dentro del texto—. `device_id` identifica una tablet, no a una persona, y
    // se queda. Con `--with-personal-data` el grupo sale completo (lo fija
    // `ErrorEventsInDiagnosticsAndExportTest`).
    expect($json)->not->toContain($employees[0])
        ->and($json)->toContain($deviceUuid);

    // Y lo que queda del mensaje sigue diciendo que paso, que es la otra mitad:
    // un saneado que dejara la fila muda haria inutil la seccion entera.
    expect($json)->toContain('SQLSTATE[23505]')
        ->and($json)->toContain('duplicate key value violates unique constraint');
})->group('RL-19', 'RF-PD-15');

/*
 * ---------------------------------------------------------------------------
 * LA PRUEBA SEMBRADA (ADR-048; RF-PD-15, RF-PD-09, RL-19; reglas duras 16 y 21;
 * dictamen de seguridad del bloque 19: H2, H3, H4, H7 y «la prueba sembrada
 * cubre ademas»).
 *
 * Sobre la misma instalacion de 500 empleados y 90 dias se siembran datos
 * personales FICTICIOS por las tres puertas por las que un error entra en
 * `error_events` —`POST /client-errors` con sesion de gestion y de portal, el
 * latido del quiosco y una excepcion del servidor captada por el enganche—, en
 * el mensaje, en las claves, en los valores, en valores anidados y en
 * `app_version`. Luego se genera el paquete anonimizado y se busca cada valor
 * en TODO su texto. Es la comprobacion que citan `operacion.md` §12.2 y
 * `obligaciones-legales.md`: lo que esta prueba no afirma, la guia no lo puede
 * prometer.
 * ---------------------------------------------------------------------------
 */

/**
 * Lo que el dictamen pide sembrar y {@see SeededPersonalData} no trae: apellidos
 * que son palabras (Mesa, Blanco, Mayor, Cruz, Vega, Campos), un correo con
 * dominio Unicode, `line <telefono>` (H3), la fecha de nacimiento escrita, las
 * IP y un nombre pegado en `app_version`.
 */
const DIAGNOSTICS_BUNDLE_VOLUME_EXTRA_PII = [
    'Ofelia Mesa Blanco', 'Ruperta Mayor Cruz', 'Anselma Vega Campos',
    'Ofelia', 'Ruperta', 'Anselma', 'Mesa', 'Blanco', 'Mayor', 'Cruz', 'Vega',
    '698765432', '698 765 432', '15 de marzo de 1985', 'marzo de 1985',
    '192.168.13.37', '2001:db8:85a3::8a2e:370:7334',
    'renée.zoë@hôtel-exemple.fr', 'hôtel-exemple.fr',
    'RosaFicticiana', 'OfeliaMesaBlanco',
];

/** Los codigos de catalogo de cada puerta de cliente, que se van alternando. */
const DIAGNOSTICS_BUNDLE_VOLUME_WEB_CODES = ['web.unhandled_error', 'web.unhandled_rejection', 'web.vue_error'];

const DIAGNOSTICS_BUNDLE_VOLUME_KIOSK_CODES = ['kiosk.camera.stream_lost', 'kiosk.heartbeat.failed', 'kiosk.unhandled_error'];

/**
 * Un informe de cliente con lo que faltaba, repartido entre las claves de texto
 * admitidas (cada valor por debajo de los 200 caracteres del contrato), una
 * clave que es un nombre y, si la puerta lo admite, un valor anidado. Lleva
 * dentro el UUID de un empleado de la semilla, que tampoco puede salir (H2).
 *
 * @return array<string, mixed>
 */
function volumenInformeConLoQueFaltaba(string $code, string $employeeUuid, bool $nested): array
{
    $context = [
        'message' => SeededPersonalData::TECHNICAL.' para Ofelia Mesa Blanco, Ruperta Mayor Cruz y Anselma Vega Campos',
        'reason' => 'código 739104, line 698765432, line 698 765 432',
        'cause' => 'GET /api/v1/me/days?t=x7k2m9 de quien nacio el 15 de marzo de 1985',
        'hook' => 'desde 192.168.13.37 y 2001:db8:85a3::8a2e:370:7334',
        'component' => 'renée.zoë@hôtel-exemple.fr',
        'scope' => 'Employee '.$employeeUuid.' not found',
        'source' => 'https://portal.hotel-ejemplo.es/me/days?t=x7k2m9#Ofelia',
        'Ofelia Mesa Blanco' => 'una clave que es un nombre',
    ];

    $nestedValue = ['detalle' => ['persona' => 'Ruperta Mayor Cruz', 'ip' => '192.168.13.37']];

    return [
        'code' => $code,
        'occurred_at' => '2026-10-03T09:00:00Z',
        'app_version' => 'OfeliaMesaBlanco',
        'context' => $nested ? [...$context, ...$nestedValue] : $context,
    ];
}

/**
 * Siembra por las tres puertas y devuelve el UUID del empleado con sesion de
 * portal: es el `employee_uuid` que el control positivo busca con
 * `--with-personal-data`.
 *
 * @param  list<string>  $employees  Los UUID de la semilla de volumen.
 */
function volumenSembrarPorLasTresPuertas(array $employees): string
{
    $quiosco = AttendanceFixtures::scenario();
    $gestion = ManagementUsers::tokenFor(ManagementUsers::withRole(UserRole::ADMIN));
    $portal = PortalLogins::open($quiosco['employee']);

    // 1 y 2. `POST /client-errors` con sesion de gestion y con sesion de portal.
    foreach ([$gestion, $portal] as $token) {
        Api::as($token)->post('/api/v1/client-errors', ['errors' => [
            ...SeededPersonalData::clientReports(DIAGNOSTICS_BUNDLE_VOLUME_WEB_CODES),
            volumenInformeConLoQueFaltaba('web.vue_error', $employees[1], nested: true),
        ]])->assertStatus(202);
    }

    // 3. El latido del quiosco, con `client_errors`. Sin anidados: el contrato
    //    del latido los rechaza con 400, y eso lo prueba
    //    `HeartbeatClientErrorsPiiTest`.
    Api::as($quiosco['token'])->post('/api/v1/kiosk/heartbeat', [
        'app_version' => '2.2.0',
        'pending_queue_size' => 0,
        'client_errors' => [
            ...SeededPersonalData::clientReports(DIAGNOSTICS_BUNDLE_VOLUME_KIOSK_CODES, nested: false),
            volumenInformeConLoQueFaltaba('kiosk.unhandled_error', $employees[1], nested: false),
        ],
    ])->assertOk();

    // 4. Una excepcion del servidor que interpola los valores, captada por el
    //    enganche del manejador. Con la sesion del portal, para que el grupo
    //    lleve `employee_uuid` y el paquete anonimizado tenga que quitarlo; y
    //    con un nombre en la ruta, que acaba en `context.route`.
    app()->make(ExecutionContext::class)->reset();

    Route::middleware(['api', 'auth:sanctum'])->get(
        '/api/v1/__b19/ofelia-mesa-blanco',
        static fn () => throw new RuntimeException(
            SeededPersonalData::message(3).' | Employee '.$employees[2].' (Ruperta Mayor Cruz) line 698765432'
                .' desde 192.168.13.37 el 15 de marzo de 1985',
        ),
    );

    Api::as($portal)->get('/api/v1/__b19/ofelia-mesa-blanco')->assertStatus(500);

    return $quiosco['employee'];
}

/**
 * Todas las cadenas de `$data`, sin sus claves ni sus numeros —una clave
 * `max_…` o un recuento 1985 no son una fuga—, y con los UUID tapados: un grupo
 * de cuatro hexadecimales de un UUID aleatorio podria ser «1985». Los UUID se
 * comprueban aparte, contra la tabla de empleados.
 *
 * @param  array<array-key, mixed>  $data
 */
function volumenCadenasDe(array $data): string
{
    $strings = [];

    array_walk_recursive($data, static function (mixed $value) use (&$strings): void {
        // Tambien los numeros, como texto: un telefono que un cliente mando como
        // entero bajo `reason` es tan fuga como el mismo telefono entre comillas.
        if (\is_string($value) || \is_int($value) || \is_float($value)) {
            $strings[] = (string) $value;
        }
    });

    return (string) preg_replace(
        '/[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}/i',
        '[uuid]',
        implode("\n", $strings),
    );
}

/**
 * Todo lo sembrado. Los valores que son SOLO un numero corto (`739104`,
 * `28013`, `1985`) se buscan en la seccion de errores y no en todo el paquete:
 * el informe del doctor o una version pueden llevar un numero asi por azar, y
 * eso no es una fuga sino una prueba intermitente.
 *
 * @return array{everywhere: list<string>, errors_only: list<string>}
 */
function volumenValoresSembrados(): array
{
    $all = [...array_merge(...array_values(SeededPersonalData::all())), ...DIAGNOSTICS_BUNDLE_VOLUME_EXTRA_PII];
    $shortNumbers = array_values(array_filter(
        $all,
        static fn (string $value): bool => preg_match('/^\d{1,6}$/', $value) === 1,
    ));

    return ['everywhere' => array_values(array_diff($all, $shortNumbers)), 'errors_only' => $shortNumbers];
}

it('genera un paquete anonimizado sin ninguno de los datos sembrados por las tres puertas ni el UUID de ningun empleado', function (): void {
    // --- Arrange ------------------------------------------------------------

    FrozenTime::at('2026-10-03 09:30:00');
    $employees = instalacionConVolumen();
    volumenSembrarPorLasTresPuertas($employees);

    /** @var list<string> $todosLosUuid */
    $todosLosUuid = DB::table('employees')->pluck('uuid')->all();

    // --- Act ----------------------------------------------------------------

    $bundle = app(GenerateDiagnosticsBundleHandler::class)
        ->handle(DiagnosticsOptions::anonymized(), DiagnosticsActor::User);

    $json = $bundle->toJson();

    /** @var array<string, mixed> $paquete */
    $paquete = json_decode($json, true, flags: JSON_THROW_ON_ERROR);

    /** @var array{status: string, groups: list<array{source: string}>} $errores */
    $errores = $bundle->sections['error_events'];

    $valores = volumenValoresSembrados();

    $origenes = array_values(array_unique(array_column($errores['groups'], 'source')));
    sort($origenes);

    // --- Assert: la seccion trae lo sembrado (control positivo) --------------

    // Sin esto, un historico vacio pasaria todo lo de abajo.
    expect($errores['status'])->toBe('ok')
        ->and($origenes)->toBe(['admin', 'api', 'kiosk', 'portal'])
        ->and(volumenCadenasDe($errores))->toContain(SeededPersonalData::TECHNICAL);

    // --- Assert: ni un dato sembrado, en ninguna forma ------------------------

    expect(SeededPersonalData::leaksIn(volumenCadenasDe($paquete), $valores['everywhere']))
        ->toBe([], 'El paquete anonimizado contiene datos sembrados.')
        ->and(SeededPersonalData::leaksIn(volumenCadenasDe($errores), $valores['errors_only']))
        ->toBe([], 'La seccion de errores contiene numeros sembrados.');

    // --- Assert: ni uno de los 501 `employees.uuid` (H2) ----------------------

    // 500 de la semilla de volumen y el del portal.
    expect($todosLosUuid)->toHaveCount(501)
        ->and(SeededPersonalData::uuidsIn($json, $todosLosUuid))->toBe([]);
})->group('RF-PD-09', 'RF-PD-15', 'RL-19');

it('con datos personales el grupo del portal si lleva su employee_uuid', function (): void {
    // El control positivo de H2: la omision es del modo anonimizado, no un
    // grupo que se haya quedado sin el dato por el camino.
    FrozenTime::at('2026-10-03 09:30:00');
    $employees = instalacionConVolumen();
    $empleadoDelPortal = volumenSembrarPorLasTresPuertas($employees);

    $bundle = app(GenerateDiagnosticsBundleHandler::class)
        ->handle(DiagnosticsOptions::withPersonalData(7), DiagnosticsActor::User);

    /** @var array{groups: list<array{source: string, employee_uuid: string|null}>} $errores */
    $errores = $bundle->sections['error_events'];

    $delPortal = array_values(array_filter(
        $errores['groups'],
        static fn (array $grupo): bool => $grupo['source'] === 'portal',
    ));

    expect($delPortal)->not->toBeEmpty()
        ->and(array_values(array_unique(array_column($delPortal, 'employee_uuid'))))->toBe([$empleadoDelPortal]);
})->group('RF-PD-09', 'RF-PD-15', 'RL-19');

it('vuelve a sanear al empaquetar una fila que no paso por RecordErrorEvent', function (): void {
    // La segunda red (ADR-048): una fila escrita por otro camino —una version
    // anterior, una restauracion, un error futuro— sale del paquete saneada
    // aunque la tabla la tenga en claro.
    FrozenTime::at('2026-10-03 09:30:00');

    DB::table('error_events')->insert([
        'fingerprint' => hash('sha256', 'diagnostics-bundle-volume-segunda-red'),
        'level' => 'error',
        'source' => 'api',
        'module' => 'attendance',
        'message' => SeededPersonalData::TECHNICAL.' para Ofelia Mesa Blanco cuenta ES91 2100 0418 4502 0005 1332',
        'context' => json_encode(['reason' => 'la credencial de Ruperta Mayor Cruz', 'route' => '/api/v1/scan']),
        'app_version' => 'OfeliaMesaBlanco',
        'occurrences' => 1,
        'first_seen_at' => '2026-10-03T09:00:00Z',
        'last_seen_at' => '2026-10-03T09:00:00Z',
        'created_at' => '2026-10-03T09:00:00Z',
        'updated_at' => '2026-10-03T09:00:00Z',
    ]);

    $sembrados = ['Ofelia Mesa Blanco', 'Ruperta Mayor Cruz', 'OfeliaMesaBlanco', 'ES91 2100 0418 4502 0005 1332'];

    $bundle = app(GenerateDiagnosticsBundleHandler::class)
        ->handle(DiagnosticsOptions::anonymized(), DiagnosticsActor::User);

    /** @var array{groups: list<array<string, mixed>>} $errores */
    $errores = $bundle->sections['error_events'];

    // La tabla los tiene en claro —si no, la prueba no probaria nada— y el
    // paquete no.
    expect(SeededPersonalData::leaksIn(SeededPersonalData::textOfRows(DB::table('error_events')->get()), $sembrados))
        ->toBe($sembrados)
        ->and($errores['groups'])->toHaveCount(1)
        ->and(SeededPersonalData::leaksIn(volumenCadenasDe($errores), $sembrados))->toBe([])
        ->and(volumenCadenasDe($errores))->toContain(SeededPersonalData::TECHNICAL);
})->group('RF-PD-09', 'RF-PD-15', 'RL-19');
