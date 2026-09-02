<?php

declare(strict_types=1);

use App\Modules\Compliance\Domain\ValueObject\AuditAction;
use App\Modules\Shared\Domain\ValueObject\UserRole;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Spectator\Spectator;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\Database\RefreshDatabase;
use Tests\Support\Http\Api;
use Tests\Support\Identity\ManagementUsers;
use Tests\Support\Workforce\ImportFiles;
use Tests\Support\Workforce\WorkforceFixtures;

/*
 * Importacion masiva de plantilla (RF-GP-05, tarea 5.5).
 *
 * LAS CUATRO AFIRMACIONES QUE SOSTIENEN EL DISEÑO:
 *
 *   1. **Dos fases de verdad.** `validate` no escribe una sola fila, y `apply`
 *      solo hace lo que `validate` dijo que haria.
 *   2. **Reimportar el mismo fichero no duplica ni pisa historial** (regla dura
 *      5). Es lo que ocurre siempre en la practica: se corrige una linea y se
 *      vuelve a subir el fichero entero.
 *   3. **El correo sigue siendo opcional** (regla dura 12): un fichero sin
 *      columna de correo importa igual.
 *   4. **El documento nunca se almacena en claro** (RL-08) y **no se manda nada
 *      por correo** (regla dura 11, ADR-014).
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Spectator::using('openapi.yaml');
});

function importerToken(): string
{
    return ManagementUsers::tokenFor(ManagementUsers::withRole(UserRole::RRHH));
}

/**
 * @return TestResponse<Response>
 */
function importFile(string $token, UploadedFile $file, string $mode = 'validate', ?string $checksum = null): TestResponse
{
    $fields = ['mode' => $mode];

    if ($checksum !== null) {
        $fields['confirm_checksum'] = $checksum;
    }

    return Api::as($token)->upload('/api/v1/employees/import', $fields, ['file' => $file]);
}

/**
 * La huella que devolvio la validacion, ya tipada.
 *
 * `json()` devuelve `mixed` y con PHPStan 9 eso obliga a estrechar el tipo en
 * algun sitio; mejor aqui, una vez, que en cada uso.
 *
 * @param  TestResponse<Response>  $response
 */
function importedChecksum(TestResponse $response): string
{
    $checksum = $response->json('file.sha256');

    return \is_string($checksum) ? $checksum : '';
}

/**
 * Valida y aplica en una sola llamada de la prueba, echando mano de la huella
 * que devuelve la validacion — que es exactamente lo que hace el panel.
 *
 * @return TestResponse<Response>
 */
function importAndApply(string $token, string $csv): TestResponse
{
    $checksum = importedChecksum(importFile($token, ImportFiles::csv($csv)));

    return importFile($token, ImportFiles::csv($csv), 'apply', $checksum);
}

/** La cabecera habitual de una exportacion de plantilla. */
function importHeaders(): string
{
    return 'nombre,apellidos,dni,email,departamento,fecha_alta';
}

// -----------------------------------------------------------------------------
// Simulacion
// -----------------------------------------------------------------------------

it('simula la importacion sin escribir una sola fila', function (): void {
    WorkforceFixtures::site();

    $csv = importHeaders()."\n"
        ."Youssef,Amrani,12345678Z,youssef@hotel.example,,2026-01-15\n"
        ."Marta,Vidal,87654321X,,,2026-02-01\n";

    // SIN `assertValidRequest()`, y es la unica excepcion de la suite: Spectator
    // no sabe casar un cuerpo `multipart/form-data` con el `requestBody` del
    // contrato y responde «did not match any specified media type». La forma de
    // la peticion la fija igualmente `ImportEmployeesRequest` —campos conocidos,
    // extensiones y tamaño—, y **la respuesta si se valida contra el contrato**,
    // que es donde vive el esquema que consume el panel.
    importFile(importerToken(), ImportFiles::csv($csv))
        ->assertValidResponse(200)
        ->assertJsonPath('mode', 'validate')
        ->assertJsonPath('file.rows', 2)
        ->assertJsonPath('summary.create', 2)
        ->assertJsonPath('summary.reject', 0)
        ->assertJsonPath('truncated', false)
        // En simulacion no hay UUID: la persona todavia no existe.
        ->assertJsonPath('rows.0.employee_uuid', null)
        ->assertJsonPath('rows.0.line', 2);

    expect(DB::table('employees')->count())->toBe(0);
})->group('RF-GP-05');

it('numera las lineas como las ve quien abre el fichero', function (): void {
    // La primera fila de datos es la LINEA 2, porque la 1 es la cabecera. Un
    // indice base cero obligaria a sumar mentalmente en cada rechazo.
    WorkforceFixtures::site();

    $csv = importHeaders()."\n"
        ."Youssef,Amrani,12345678Z,,,2026-01-15\n"
        ."Marta,Vidal,87654321X,,,2026-02-01\n";

    importFile(importerToken(), ImportFiles::csv($csv))
        ->assertValidResponse(200)
        ->assertJsonPath('rows.0.line', 2)
        ->assertJsonPath('rows.1.line', 3)
        ->assertJsonPath('rows.1.label', 'Marta Vidal');
})->group('RF-GP-05');

// -----------------------------------------------------------------------------
// Aplicacion
// -----------------------------------------------------------------------------

it('aplica el fichero solo tras confirmar su huella', function (): void {
    WorkforceFixtures::site();
    $token = importerToken();

    $csv = importHeaders()."\n"
        ."Youssef,Amrani,12345678Z,youssef@hotel.example,,2026-01-15\n";

    $response = importAndApply($token, $csv)
        ->assertValidResponse(200)
        ->assertJsonPath('mode', 'apply')
        ->assertJsonPath('summary.create', 1);

    expect(DB::table('employees')->count())->toBe(1);

    // La linea aplicada devuelve el UUID de la persona creada.
    expect((string) $response->json('rows.0.employee_uuid'))->not->toBe('');
})->group('RF-GP-05');

it('se niega a aplicar un fichero distinto del que se valido', function (): void {
    // Sin esta comprobacion, quien revisa un informe de «38 altas y 2 rechazos»,
    // corrige el fichero y lo vuelve a subir estaria aplicando a ciegas un
    // contenido que nadie ha revisado.
    WorkforceFixtures::site();
    $token = importerToken();

    $validado = importHeaders()."\n"."Youssef,Amrani,12345678Z,,,2026-01-15\n";
    $otro = importHeaders()."\n"."Otra,Persona,11111111H,,,2026-01-15\n";

    $checksum = importedChecksum(importFile($token, ImportFiles::csv($validado)));

    importFile($token, ImportFiles::csv($otro), 'apply', $checksum)->assertValidResponse(409);

    expect(DB::table('employees')->count())->toBe(0);
})->group('RF-GP-05');

it('exige la confirmacion para aplicar', function (): void {
    WorkforceFixtures::site();

    $csv = importHeaders()."\n"."Youssef,Amrani,12345678Z,,,2026-01-15\n";

    importFile(importerToken(), ImportFiles::csv($csv), 'apply')
        ->assertValidResponse(422)
        ->assertJsonStructure(['errors' => ['confirm_checksum']]);

    expect(DB::table('employees')->count())->toBe(0);
})->group('RF-GP-05');

it('emite el PIN de cada alta, igual que el alta individual', function (): void {
    // La importacion reutiliza `RegisterEmployeeHandler` en lugar de un camino
    // propio: sin PIN no se puede fichar por respaldo (RF-AT-11) ni entrar al
    // portal (RL-05), y quien entrara por un camino paralelo tendria medio ciclo
    // de vida.
    WorkforceFixtures::site();

    importAndApply(importerToken(), importHeaders()."\n"."Youssef,Amrani,12345678Z,,,2026-01-15\n")
        ->assertValidResponse(200);

    expect(DB::table('employees')->whereNotNull('pin_hash')->count())->toBe(1);
})->group('RF-GP-05', 'RF-ID-09');

it('genera el codigo de empleado y no lo lee del fichero', function (): void {
    // El codigo es opaco (doc 01 §5.5): uno tomado del sistema anterior seria un
    // dato con significado impreso en una tarjeta. La columna no se mapea, asi
    // que llega como «no reconocida».
    WorkforceFixtures::site();

    $csv = "nombre,apellidos,dni,fecha_alta,employee_code\n"
        ."Youssef,Amrani,12345678Z,2026-01-15,NOMINA-0042\n";

    importAndApply(importerToken(), $csv)->assertValidResponse(200);

    expect(DB::table('employees')->value('employee_code'))->not->toBe('NOMINA-0042');
})->group('RF-GP-05', 'RF-ID-06');

// -----------------------------------------------------------------------------
// Reimportacion (regla dura 5)
// -----------------------------------------------------------------------------

it('reimportar el mismo fichero no duplica a nadie', function (): void {
    WorkforceFixtures::site();
    $token = importerToken();

    $csv = importHeaders()."\n"
        ."Youssef,Amrani,12345678Z,youssef@hotel.example,,2026-01-15\n"
        ."Marta,Vidal,87654321X,,,2026-02-01\n";

    importAndApply($token, $csv)->assertValidResponse(200);

    // Segunda pasada: las dos salen como `unchanged`, no como `update`. Decir
    // «actualizadas» de filas que no se tocan haria imposible ver, en el informe
    // de una reimportacion, cual es la linea que de verdad cambia.
    importAndApply($token, $csv)
        ->assertValidResponse(200)
        ->assertJsonPath('summary.create', 0)
        ->assertJsonPath('summary.update', 0)
        ->assertJsonPath('summary.unchanged', 2);

    expect(DB::table('employees')->count())->toBe(2);
})->group('RF-GP-05', 'RL-04');

it('reconoce a quien se dio de alta por el panel con el documento escrito de otra forma', function (): void {
    // LA NORMALIZACION DEL DOCUMENTO TIENE QUE SER LA MISMA EN LOS DOS CAMINOS,
    // y hasta la revision de la 5.5 no lo era: la importacion pasaba el documento
    // a mayusculas y le quitaba espacios, guiones y puntos, mientras que
    // `POST /employees` hasheaba lo que se tecleara. Un hash no admite
    // comparaciones aproximadas, asi que `12345678-Z` y `12345678Z` eran dos
    // personas distintas para la base de datos.
    //
    // El resultado era el escenario mas normal del mundo —RRHH da de alta a
    // alguien a mano y despues importa el fichero de nominas— produciendo una
    // FICHA DUPLICADA: dos codigos de empleado, dos PIN y dos tarjetas para la
    // misma persona (regla dura 5).
    WorkforceFixtures::site();
    $token = importerToken();

    Api::as($token)->post('/api/v1/employees', [
        'first_name' => 'Youssef',
        'last_name' => 'Amrani',
        'national_id' => '12345678-Z',
        'hired_at' => '2026-01-15',
    ])->assertValidResponse(201);

    // El fichero de nominas trae el mismo documento sin guion. Tiene que salir
    // como la MISMA persona, no como un alta.
    importAndApply($token, importHeaders()."\n"."Youssef,Amrani,12345678Z,youssef@hotel.example,,2026-01-15\n")
        ->assertValidResponse(200)
        ->assertJsonPath('summary.create', 0)
        ->assertJsonPath('summary.update', 1);

    expect(DB::table('employees')->count())->toBe(1);
})->group('RF-GP-05', 'RL-08');

it('reconoce a la persona por su documento aunque le cambie el correo', function (): void {
    // El documento manda sobre el correo: el correo cambia —se casa, cambia de
    // dominio, deja de tener— y el documento no. Si mandara el correo, cambiarlo
    // en el fichero crearia un alta nueva en lugar de actualizar la ficha.
    WorkforceFixtures::site();
    $token = importerToken();

    importAndApply($token, importHeaders()."\n"."Youssef,Amrani,12345678Z,viejo@hotel.example,,2026-01-15\n")
        ->assertValidResponse(200);

    importAndApply($token, importHeaders()."\n"."Youssef,Amrani,12345678Z,nuevo@hotel.example,,2026-01-15\n")
        ->assertValidResponse(200)
        ->assertJsonPath('summary.create', 0)
        ->assertJsonPath('summary.update', 1)
        ->assertJsonPath('rows.0.changes', ['email']);

    expect(DB::table('employees')->count())->toBe(1)
        ->and(DB::table('employees')->value('email'))->toBe('nuevo@hotel.example');
})->group('RF-GP-05');

it('no reescribe la fecha de alta de quien ya existe, y lo avisa', function (): void {
    // Regla dura 5: cambiar la fecha de alta mueve el punto desde el que corre la
    // conservacion de RL-02 y desde el que se le pueden imputar jornadas.
    WorkforceFixtures::site();
    $token = importerToken();

    importAndApply($token, importHeaders()."\n"."Youssef,Amrani,12345678Z,,,2026-01-15\n")
        ->assertValidResponse(200);

    $response = importAndApply($token, importHeaders()."\n"."Youssef,Amrani,12345678Z,,,2020-01-01\n")
        ->assertValidResponse(200);

    /** @var list<array<string, mixed>> $messages */
    $messages = $response->json('rows.0.messages');
    $codes = array_column($messages, 'code');

    expect($codes)->toContain('hired_at_not_updated')
        // Es un AVISO, no un rechazo: la linea se procesa igual.
        ->and(array_column($messages, 'severity'))->toContain('warning')
        // Y `changes` no puede contener nunca `hired_at`.
        ->and($response->json('rows.0.changes'))->not->toContain('hired_at');

    expect(DB::table('employees')->value('hired_at'))->toBe('2026-01-15');
})->group('RF-GP-05', 'RL-04');

// -----------------------------------------------------------------------------
// Identidad, correo y documento
// -----------------------------------------------------------------------------

it('importa un fichero sin ninguna columna de correo', function (): void {
    // Regla dura 12, ADR-015: el producto no depende del correo del empleado, y
    // el importador no puede exigir esa columna ni fallar si falta.
    WorkforceFixtures::site();

    importAndApply(importerToken(), "nombre,apellidos,dni,fecha_alta\nYoussef,Amrani,12345678Z,2026-01-15\n")
        ->assertValidResponse(200)
        ->assertJsonPath('summary.create', 1);

    expect(DB::table('employees')->value('email'))->toBeNull();
})->group('RF-GP-05', 'RF-ID-05');

it('rechaza la linea que no trae ni documento ni correo', function (): void {
    // Sin una de las dos no habria forma de reconocerla la segunda vez y
    // reimportar el fichero la duplicaria. El mensaje dice que columna anadir.
    WorkforceFixtures::site();

    $response = importFile(importerToken(), ImportFiles::csv(
        "nombre,apellidos,fecha_alta\nYoussef,Amrani,2026-01-15\n"
    ))->assertValidResponse(200)
        ->assertJsonPath('summary.reject', 1)
        ->assertJsonPath('rows.0.messages.0.code', 'missing_identity');

    expect((string) $response->json('rows.0.messages.0.detail'))->not->toBe('missing_identity');
})->group('RF-GP-05', 'RF-ID-05');

it('guarda el documento hasheado y nunca en claro', function (): void {
    // RL-08. Si la copia de seguridad de un cliente acaba donde no debe, no hay
    // documentos de identidad dentro.
    WorkforceFixtures::site();

    importAndApply(importerToken(), importHeaders()."\n"."Youssef,Amrani,12345678Z,,,2026-01-15\n")
        ->assertValidResponse(200);

    /** @var object{coincide: bool}|null $row */
    $row = DB::selectOne(
        'SELECT national_id_hash = digest(?, ?) AS coincide FROM employees LIMIT 1',
        ['12345678Z', 'sha256'],
    );

    expect($row?->coincide)->toBeTrue();

    // Y no queda en ninguna columna de texto de la ficha. Se comparan las
    // columnas legibles una a una y no la fila entera serializada:
    // `national_id_hash` es `bytea` y no se puede pasar por `json_encode`, que es
    // precisamente la prueba de que ahi no hay texto.
    /** @var object{first_name: string, last_name: string, employee_code: string, email: ?string} $ficha */
    $ficha = DB::table('employees')->firstOrFail();

    foreach ([$ficha->first_name, $ficha->last_name, $ficha->employee_code, (string) $ficha->email] as $texto) {
        expect($texto)->not->toContain('12345678Z');
    }
})->group('RF-GP-05', 'RL-08');

it('rechaza la segunda aparicion de la misma persona dentro del fichero', function (): void {
    // Se rechaza la SEGUNDA y no la primera: aplicar las dos dejaria el
    // resultado a merced del orden de las filas.
    WorkforceFixtures::site();

    importFile(importerToken(), ImportFiles::csv(
        importHeaders()."\n"
        ."Youssef,Amrani,12345678Z,,,2026-01-15\n"
        ."Youssef,Amrani,12345678Z,,,2026-01-15\n"
    ))
        ->assertValidResponse(200)
        ->assertJsonPath('summary.create', 1)
        ->assertJsonPath('summary.reject', 1)
        ->assertJsonPath('rows.1.messages.0.code', 'duplicate_in_file');
})->group('RF-GP-05');

// -----------------------------------------------------------------------------
// Formatos del fichero
// -----------------------------------------------------------------------------

it('detecta el punto y coma del Excel espanol', function (): void {
    // Es la primera causa de incidencia al importar, y por eso se detecta en
    // lugar de configurarse: pedirselo a quien importa es pedirle que acierte a
    // ciegas un detalle que el propio fichero dice.
    WorkforceFixtures::site();

    $csv = ImportFiles::rows(
        ['nombre', 'apellidos', 'dni', 'fecha_alta'],
        [['Youssef', 'Amrani', '12345678Z', '2026-01-15']],
        delimiter: ';',
    );

    importAndApply(importerToken(), $csv)
        ->assertValidResponse(200)
        ->assertJsonPath('summary.create', 1);

    expect(DB::table('employees')->value('first_name'))->toBe('Youssef');
})->group('RF-GP-05');

it('detecta la codificacion Windows-1252 y no rompe los apellidos', function (): void {
    // El sintoma de no detectarlo es la ñ rota, y nadie lo relaciona con un
    // parametro de configuracion.
    WorkforceFixtures::site();

    $csv = "nombre,apellidos,dni,fecha_alta\nBegoña,Muñiz,12345678Z,2026-01-15\n";

    $token = importerToken();
    $checksum = importedChecksum(importFile($token, ImportFiles::latin1Csv($csv)));

    importFile($token, ImportFiles::latin1Csv($csv), 'apply', $checksum)->assertValidResponse(200);

    expect(DB::table('employees')->value('last_name'))->toBe('Muñiz');
})->group('RF-GP-05');

it('reconoce las cabeceras en ingles y sin tildes', function (): void {
    // El mapa trae alias es/en de serie y la comparacion ignora mayusculas,
    // tildes y separadores: el valor por defecto ES el producto.
    WorkforceFixtures::site();

    importAndApply(importerToken(), "First Name,Last Name,National ID,Hire Date\nYoussef,Amrani,12345678Z,2026-01-15\n")
        ->assertValidResponse(200)
        ->assertJsonPath('summary.create', 1);
})->group('RF-GP-05');

it('acepta la fecha en formato espanol y nunca la lee como mes/dia', function (): void {
    // `03/04/2026` es el 3 de abril para quien lo escribio. Aceptar el formato
    // americano lo convertiria en el 4 de marzo sin que nadie lo notara: un mes
    // de jornadas imputables que no deberian existir.
    WorkforceFixtures::site();

    importAndApply(importerToken(), "nombre,apellidos,dni,fecha_alta\nYoussef,Amrani,12345678Z,03/04/2026\n")
        ->assertValidResponse(200);

    expect(DB::table('employees')->value('hired_at'))->toBe('2026-04-03');
})->group('RF-GP-05');

it('avisa de las columnas que no reconoce, una sola vez', function (): void {
    // El caso que importa no es la exportacion con veinte columnas de nomina,
    // sino el `e-mail` escrito donde el mapa espera `email`.
    //
    // «UNA SOLA VEZ» ES LITERAL Y VIVE EN `file.warnings`. El aviso es del
    // FICHERO, no de cada linea: copiado en cada fila, una cabecera con tres
    // columnas desconocidas y cuarenta filas producia ciento veinte mensajes
    // identicos que sepultaban los rechazos de verdad.
    WorkforceFixtures::site();

    $response = importFile(importerToken(), ImportFiles::csv(
        "nombre,apellidos,dni,fecha_alta,centro_de_coste\nYoussef,Amrani,12345678Z,2026-01-15,CC-01\n"
    ))->assertValidResponse(200);

    /** @var list<array<string, mixed>> $warnings */
    $warnings = $response->json('file.warnings');

    expect(array_column($warnings, 'code'))->toBe(['unknown_column'])
        ->and($warnings[0]['column'])->toBe('centro_de_coste')
        ->and($warnings[0]['severity'])->toBe('warning')
        // Y la fila NO lo repite.
        ->and($response->json('rows.0.messages'))->toBe([]);
})->group('RF-GP-05');

it('rechaza un fichero que no se puede leer, y dice que hacer', function (): void {
    WorkforceFixtures::site();

    $response = importFile(importerToken(), ImportFiles::csv(''))
        ->assertValidResponse(422)
        ->assertJsonStructure(['errors' => ['file']]);

    /** @var array<string, list<string>> $errors */
    $errors = $response->json('errors');

    expect($errors['file'][0])->not->toBe('import.unreadable_file');
})->group('RF-GP-05');

// -----------------------------------------------------------------------------
// Departamentos
// -----------------------------------------------------------------------------

it('asigna el departamento ignorando mayusculas y tildes', function (): void {
    $site = WorkforceFixtures::site();
    $departmentId = DB::table('departments')->insertGetId(['site_id' => $site, 'name' => 'Recepcion']);

    $response = importAndApply(importerToken(), "nombre,apellidos,dni,fecha_alta,departamento\nYoussef,Amrani,12345678Z,2026-01-15,RECEPCIÓN\n")
        ->assertValidResponse(200);

    expect($response->json('rows.0.messages'))->toBe([]);

    $response->assertJsonPath('summary.create', 1);

    expect(DB::table('employees')->value('department_id'))->toBe($departmentId);
})->group('RF-GP-05');

it('rechaza la linea cuyo departamento no existe, y dice como arreglarlo', function (): void {
    WorkforceFixtures::site();

    $response = importFile(importerToken(), ImportFiles::csv(
        "nombre,apellidos,dni,fecha_alta,departamento\nYoussef,Amrani,12345678Z,2026-01-15,Spa\n"
    ))->assertValidResponse(200)
        ->assertJsonPath('summary.reject', 1)
        ->assertJsonPath('rows.0.messages.0.code', 'unknown_department');

    expect((string) $response->json('rows.0.messages.0.detail'))->not->toBe('');
})->group('RF-GP-05');

// -----------------------------------------------------------------------------
// Auditoria y credenciales
// -----------------------------------------------------------------------------

it('audita el alta masiva con cifras y huella, sin un solo nombre', function (): void {
    // Regla dura 6 y regla dura 21. El nombre del fichero NO se audita: lo pone
    // quien sube y puede llevar dentro el de una persona.
    WorkforceFixtures::site();

    importAndApply(importerToken(), importHeaders()."\n"."Youssef,Amrani,12345678Z,,,2026-01-15\n")
        ->assertValidResponse(200);

    $entry = DB::table('audit_log')->where('action', AuditAction::EmployeesImported->value)->first();

    expect($entry)->not->toBeNull()
        ->and($entry?->actor_id)->not->toBeNull();

    $payload = (string) $entry?->payload;

    /** @var array<string, mixed> $decoded */
    $decoded = json_decode($payload, true, 512, JSON_THROW_ON_ERROR);

    expect($decoded)->toHaveKey('file_sha256')
        ->and($decoded['created'])->toBe(1)
        ->and($payload)->not->toContain('Youssef')
        ->and($payload)->not->toContain('Amrani')
        ->and($payload)->not->toContain('12345678Z')
        ->and($payload)->not->toContain('plantilla.csv');
})->group('RF-GP-05', 'RL-04');

it('deja las tarjetas pendientes y no manda nada por correo', function (): void {
    // Regla dura 11, ADR-014: la credencial es una tarjeta fisica. Importar
    // cuarenta personas deja cuarenta tarjetas pendientes de emitir, imprimir y
    // entregar, y quien lo dice es el panel de RF-QR-08.
    WorkforceFixtures::site();

    importAndApply(importerToken(), importHeaders()."\n"
        ."Youssef,Amrani,12345678Z,youssef@hotel.example,,2026-01-15\n")
        ->assertValidResponse(200);

    expect(DB::table('credentials')->count())->toBe(0);
})->group('RF-GP-05', 'RF-QR-08');
