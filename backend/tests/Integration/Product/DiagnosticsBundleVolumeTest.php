<?php

declare(strict_types=1);

use App\Modules\Product\Application\UseCase\GenerateDiagnosticsBundleHandler;
use App\Modules\Product\Application\UseCase\RecordErrorEvent;
use App\Modules\Product\Domain\ValueObject\DiagnosticsActor;
use App\Modules\Product\Domain\ValueObject\DiagnosticsOptions;
use App\Modules\Product\Domain\ValueObject\ErrorMessageSanitizer;
use App\Modules\Shared\Application\Port\Clock;
use App\Modules\Shared\Application\Port\ErrorEventSink;
use App\Modules\Shared\Domain\ValueObject\ErrorLevel;
use App\Modules\Shared\Domain\ValueObject\ErrorReport;
use App\Modules\Shared\Domain\ValueObject\ErrorSource;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\Database\RefreshDatabase;
use Tests\Support\Product\ErrorHistoryConnection;
use Tests\Support\Product\LicenseKeys;
use Tests\Support\Time\FixedClock;
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
 * ## Lo que esta prueba NO afirma, dicho para que nadie lea de mas
 *
 * Que un nombre **suelto, sin comillas, en prosa** no salga. No sale porque no
 * puede: «Ana Ruiz» es indistinguible de «Cocina Central» para cualquier
 * expresion regular, y {@see ErrorMessageSanitizer} lo dice con esas palabras.
 * Lo que el saneado cubre es **donde** aparece un nombre —interpolado entre
 * comillas, que es la convencion de PHP y de PostgreSQL— y las formas que si
 * son reconocibles: correo, DNI, telefono, hora, secreto. Un productor que
 * escriba `"fallo de ".$empleado->fullName()` sin comillas se lo lleva al
 * paquete, y ninguna prueba lo va a impedir: lo impide la revision de quien
 * escribe el mensaje. Sembrarlo aqui solo pondria la suite en rojo permanente
 * sin cerrar el hueco.
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
    app()->instance(Clock::class, FixedClock::at('2026-06-15 09:00:00'));
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
        expect(array_keys($kiosk))
            ->toBe(['uuid', 'status', 'app_version', 'last_seen_at', 'pending_queue_size']);
    }

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

    // --- Assert: el UUID si viaja, y es lo unico que identifica --------------

    // ADR-020 lo admite donde haga falta, y aqui hace falta: sin el, soporte no
    // puede decir que dos de los tres errores son de la misma persona. Es
    // seudonimo y el hotel es el unico que puede resolverlo a un nombre.
    expect($json)->toContain($employees[0])
        ->and($json)->toContain($deviceUuid);

    // Y lo que queda del mensaje sigue diciendo que paso, que es la otra mitad:
    // un saneado que dejara la fila muda haria inutil la seccion entera.
    expect($json)->toContain('SQLSTATE[23505]')
        ->and($json)->toContain('duplicate key value violates unique constraint');
})->group('RL-19', 'RF-PD-15');
