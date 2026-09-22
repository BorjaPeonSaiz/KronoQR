<?php

declare(strict_types=1);

use App\Modules\Shared\Domain\ValueObject\UserRole;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Spectator\Spectator;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\Database\RefreshDatabase;
use Tests\Support\Http\Api;
use Tests\Support\Identity\ManagementUsers;
use Tests\Support\Observability\CapturedLog;
use Tests\Support\Workforce\ImportFiles;
use Tests\Support\Workforce\WorkforceFixtures;

/*
 * Carga de ausencias por fichero (**RF-GP-04**, tarea 3.10).
 *
 * LAS CUATRO AFIRMACIONES QUE SOSTIENEN EL DISEÑO:
 *
 *   1. **Dos fases de verdad.** `validate` no escribe una sola fila, y `apply`
 *      solo hace lo que `validate` dijo que haria.
 *   2. **Reimportar el mismo cuadrante es seguro**: las lineas identicas salen
 *      como `unchanged` y no como solape. Es lo que ocurre siempre en la
 *      practica —se corrige una fila y se vuelve a subir el fichero entero— y
 *      sin esa distincion daria treinta y nueve conflictos donde no hay ninguno.
 *   3. **La carga no corrige nada** (RN-13, regla dura 5): no existe `update`.
 *   4. **Cada ausencia aplicada deja su propio asiento** con `source: import` y
 *      la huella del fichero, y **nunca la nota** (regla dura 21).
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Spectator::using('openapi.yaml');
});

/**
 * @return TestResponse<Response>
 */
function cargarAusencias(
    string $token,
    UploadedFile $file,
    string $mode = 'validate',
    ?string $checksum = null,
): TestResponse {
    $fields = ['mode' => $mode];

    if ($checksum !== null) {
        $fields['confirm_checksum'] = $checksum;
    }

    return Api::as($token)->upload('/api/v1/absences/import', $fields, ['file' => $file]);
}

/**
 * La huella que devolvio la validacion, ya tipada.
 *
 * @param  TestResponse<Response>  $respuesta
 */
function huellaDeCarga(TestResponse $respuesta): string
{
    $checksum = $respuesta->json('file.sha256');

    return \is_string($checksum) ? $checksum : '';
}

/**
 * El codigo de empleado de una persona recien creada.
 */
function codigoDe(string $employeeUuid): string
{
    /** @var string|null $code */
    $code = DB::table('employees')->where('uuid', $employeeUuid)->value('employee_code');

    return $code ?? '';
}

/**
 * @return array{token: string, code: string, employee: string}
 */
function contextoDeCarga(): array
{
    $site = WorkforceFixtures::site('Hotel de cargas');
    $employee = WorkforceFixtures::employee($site);

    return [
        'token' => ManagementUsers::tokenFor(ManagementUsers::withRole(UserRole::RRHH)),
        'code' => codigoDe($employee),
        'employee' => $employee,
    ];
}

it('valida sin escribir nada y despues aplica exactamente lo revisado', function (): void {
    $contexto = contextoDeCarga();

    // Cabeceras en castellano: es lo que trae la hoja de calculo de un hotel.
    $csv = ImportFiles::csv(ImportFiles::rows(
        ['codigo', 'tipo', 'desde', 'hasta', 'nota'],
        [[$contexto['code'], 'vacaciones', '2026-03-02', '2026-03-06', '']],
    ));

    // SIN `assertValidRequest()`, por lo mismo que en la carga de plantilla y es
    // la unica excepcion de la suite: Spectator no sabe casar un cuerpo
    // `multipart/form-data` con el `requestBody` del contrato. La forma de la
    // peticion la fija igualmente `ImportAbsencesRequest` —campos conocidos,
    // extensiones y tamaño—, y **la respuesta si se valida contra el contrato**,
    // que es donde vive el esquema que consume el panel.
    $validacion = cargarAusencias($contexto['token'], $csv)
        ->assertValidResponse(200)
        ->assertJsonPath('mode', 'validate')
        ->assertJsonPath('summary.create', 1)
        ->assertJsonPath('summary.reject', 0)
        ->assertJsonPath('rows.0.outcome', 'create')
        // En simulacion todavia no existe.
        ->assertJsonPath('rows.0.absence_uuid', null)
        // `label` es el CODIGO de empleado, no el nombre de nadie.
        ->assertJsonPath('rows.0.label', $contexto['code']);

    // Y no ha escrito una sola fila.
    expect(DB::table('absences')->count())->toBe(0);

    $aplicado = cargarAusencias(
        $contexto['token'],
        ImportFiles::csv(ImportFiles::rows(
            ['codigo', 'tipo', 'desde', 'hasta', 'nota'],
            [[$contexto['code'], 'vacaciones', '2026-03-02', '2026-03-06', '']],
        )),
        'apply',
        huellaDeCarga($validacion),
    )
        ->assertValidResponse(200)
        ->assertJsonPath('mode', 'apply')
        ->assertJsonPath('summary.create', 1);

    expect($aplicado->json('rows.0.absence_uuid'))->toBeString();
    expect(DB::table('absences')->count())->toBe(1);
})->group('RF-GP-04');

it('rechaza aplicar un fichero distinto del que se valido', function (): void {
    // `409` y no se escribe nada: quien reviso un informe, corrigio el fichero y
    // lo volvio a subir estaria aplicando a ciegas un contenido que nadie ha
    // revisado.
    $contexto = contextoDeCarga();

    cargarAusencias($contexto['token'], ImportFiles::csv(ImportFiles::rows(
        ['codigo', 'tipo', 'desde', 'hasta'],
        [[$contexto['code'], 'vacaciones', '2026-03-02', '2026-03-06']],
    )))->assertValidResponse(200);

    cargarAusencias(
        $contexto['token'],
        ImportFiles::csv(ImportFiles::rows(
            ['codigo', 'tipo', 'desde', 'hasta'],
            [[$contexto['code'], 'baja', '2026-03-02', '2026-03-06']],
        )),
        'apply',
        str_repeat('0', 64),
    )->assertStatus(409);

    expect(DB::table('absences')->count())->toBe(0);
})->group('RF-GP-04');

it('reimportar el mismo cuadrante no duplica: la linea identica sale como unchanged', function (): void {
    // Sin esta distincion, reimportar un cuadrante con una fila corregida daria
    // treinta y nueve conflictos donde no hay ninguno, y en la practica lleva a
    // que alguien borre lineas en vez de corregirlas.
    $contexto = contextoDeCarga();

    $filas = ImportFiles::rows(
        ['codigo', 'tipo', 'desde', 'hasta'],
        [[$contexto['code'], 'baja', '2026-03-02', '2026-03-06']],
    );

    $primera = cargarAusencias($contexto['token'], ImportFiles::csv($filas))->assertValidResponse(200);

    cargarAusencias($contexto['token'], ImportFiles::csv($filas), 'apply', huellaDeCarga($primera))
        ->assertValidResponse(200)
        ->assertJsonPath('summary.create', 1);

    $segunda = cargarAusencias($contexto['token'], ImportFiles::csv($filas))
        ->assertValidResponse(200)
        ->assertJsonPath('summary.create', 0)
        ->assertJsonPath('summary.unchanged', 1)
        ->assertJsonPath('summary.reject', 0)
        ->assertJsonPath('rows.0.outcome', 'unchanged');

    // Y la fila `unchanged` sabe de cual habla.
    expect($segunda->json('rows.0.absence_uuid'))->toBeString();

    cargarAusencias($contexto['token'], ImportFiles::csv($filas), 'apply', huellaDeCarga($segunda))
        ->assertValidResponse(200);

    expect(DB::table('absences')->count())->toBe(1);
})->group('RF-GP-04', 'RN-13');

it('rechaza cada linea con su codigo, y aplica las demas', function (): void {
    // Tumbar el lote entero por una celda mal escrita obligaria a repetir la
    // revision de las demas; y en la practica lleva a que alguien borre la linea
    // problematica en vez de corregirla.
    $contexto = contextoDeCarga();

    $csv = ImportFiles::csv(ImportFiles::rows(
        ['codigo', 'tipo', 'desde', 'hasta', 'nota'],
        [
            // Valida.
            [$contexto['code'], 'permiso', '2026-03-02', '2026-03-03', ''],
            // Persona que no existe.
            ['E0000000X', 'vacaciones', '2026-04-01', '2026-04-05', ''],
            // Tipo inventado.
            [$contexto['code'], 'excedencia', '2026-05-01', '2026-05-05', ''],
            // Periodo invertido.
            [$contexto['code'], 'vacaciones', '2026-06-10', '2026-06-01', ''],
            // `otro` sin nota.
            [$contexto['code'], 'otro', '2026-07-01', '2026-07-02', ''],
            // Pisa la primera linea del propio fichero.
            [$contexto['code'], 'baja', '2026-03-03', '2026-03-08', ''],
        ],
    ));

    $informe = cargarAusencias($contexto['token'], $csv)
        ->assertValidResponse(200)
        ->assertJsonPath('summary.create', 1)
        ->assertJsonPath('summary.reject', 5)
        ->assertJsonPath('rows.1.messages.0.code', 'unknown_employee')
        ->assertJsonPath('rows.2.messages.0.code', 'unknown_type')
        ->assertJsonPath('rows.3.messages.0.code', 'inverted_period')
        ->assertJsonPath('rows.4.messages.0.code', 'note_required')
        ->assertJsonPath('rows.5.messages.0.code', 'duplicate_in_file');

    // El texto viaja traducido y dice **que hacer**, no solo que fallo.
    expect($informe->json('rows.1.messages.0.detail'))->toBeString();
    expect($informe->json('rows.1.messages.0.detail'))->not->toBe('unknown_employee');

    // La linea es la del FICHERO, contando la cabecera: la primera de datos es
    // la 2, que es lo que la persona ve en su hoja de calculo.
    expect($informe->json('rows.0.line'))->toBe(2);
})->group('RF-GP-04');

it('rechaza la linea que pisa una ausencia ya registrada, sin confundirla con una reimportacion', function (): void {
    $contexto = contextoDeCarga();

    $primera = cargarAusencias($contexto['token'], ImportFiles::csv(ImportFiles::rows(
        ['codigo', 'tipo', 'desde', 'hasta'],
        [[$contexto['code'], 'vacaciones', '2026-03-02', '2026-03-06']],
    )))->assertValidResponse(200);

    cargarAusencias(
        $contexto['token'],
        ImportFiles::csv(ImportFiles::rows(
            ['codigo', 'tipo', 'desde', 'hasta'],
            [[$contexto['code'], 'vacaciones', '2026-03-02', '2026-03-06']],
        )),
        'apply',
        huellaDeCarga($primera),
    )->assertValidResponse(200);

    // Mismos dias, OTRO tipo: eso no es reimportar, es un choque.
    cargarAusencias($contexto['token'], ImportFiles::csv(ImportFiles::rows(
        ['codigo', 'tipo', 'desde', 'hasta'],
        [[$contexto['code'], 'baja', '2026-03-04', '2026-03-08']],
    )))
        ->assertValidResponse(200)
        ->assertJsonPath('summary.reject', 1)
        ->assertJsonPath('rows.0.messages.0.code', 'overlapping_absence');
})->group('RF-GP-04');

it('avisa de las columnas que no reconoce una sola vez, en el fichero', function (): void {
    // Tres columnas desconocidas y cuarenta filas eran ciento veinte mensajes
    // identicos que sepultaban los rechazos de verdad.
    $contexto = contextoDeCarga();

    cargarAusencias($contexto['token'], ImportFiles::csv(ImportFiles::rows(
        ['codigo', 'tipo', 'desde', 'hasta', 'departamento'],
        [[$contexto['code'], 'vacaciones', '2026-03-02', '2026-03-06', 'Cocina']],
    )))
        ->assertValidResponse(200)
        ->assertJsonCount(1, 'file.warnings')
        ->assertJsonPath('file.warnings.0.code', 'unknown_column')
        ->assertJsonPath('file.warnings.0.column', 'departamento')
        ->assertJsonPath('file.warnings.0.severity', 'warning')
        // Y la fila no las repite.
        ->assertJsonCount(0, 'rows.0.messages')
        ->assertJsonPath('summary.create', 1);
})->group('RF-GP-04');

it('rechaza la nota demasiado larga en la revision, sin escribirla en ningun log', function (): void {
    /*
     * **Regla dura 21 y art. 9 del RGPD, y el hallazgo de la revision de
     * seguridad de la 3.10.**
     *
     * Antes, una celda de 501 caracteres pasaba la fase de comprobacion como
     * `create` —`shapeErrorsOf()` no miraba la longitud— y reventaba al aplicar
     * contra `absences_chk_note_length`. El mensaje de esa `QueryException` lleva
     * los **valores enlazados**: la nota entera y el tipo, que aqui es
     * `sick_leave`. Ese mensaje no llega al cliente, pero **se escribe en el log
     * tecnico**, que no esta saneado —solo lo esta `error_events`—. Es decir: un
     * texto libre sobre la salud de alguien en un fichero de texto que se rota,
     * se copia y viaja.
     *
     * La prueba afirma las tres mitades del arreglo: se rechaza en la revision
     * con su codigo, no se escribe nada, y el volcado del log no contiene el
     * texto.
     */
    $contexto = contextoDeCarga();

    // Un texto reconocible y de 501 caracteres: uno mas que el techo.
    $notaLarga = 'diagnostico-confidencial-'.str_repeat('x', 501 - \strlen('diagnostico-confidencial-'));

    expect(mb_strlen($notaLarga))->toBe(501);

    $csv = ImportFiles::csv(ImportFiles::rows(
        ['codigo', 'tipo', 'desde', 'hasta', 'nota'],
        [[$contexto['code'], 'baja', '2026-03-02', '2026-03-06', $notaLarga]],
    ));

    CapturedLog::around(function (CapturedLog $log) use ($contexto, $csv): void {
        cargarAusencias($contexto['token'], $csv)
            ->assertValidResponse(200)
            ->assertJsonPath('summary.create', 0)
            ->assertJsonPath('summary.reject', 1)
            ->assertJsonPath('rows.0.outcome', 'reject')
            ->assertJsonPath('rows.0.messages.0.code', 'note_too_long')
            ->assertJsonPath('rows.0.messages.0.column', 'note');

        // Cero escrituras: la fase de comprobacion no toca la tabla.
        expect(DB::table('absences')->count())->toBe(0);

        // Y NADA del texto en el log: ni la nota, ni el fragmento reconocible.
        expect($log->dump())->not->toContain('diagnostico-confidencial');
    });
})->group('RF-GP-04', 'RS-08');

it('no deja salir el contenido de la nota aunque el motor rechace la escritura', function (): void {
    /*
     * Defensa en profundidad del hallazgo anterior. Aunque la comprobacion previa
     * ya rechaza la nota larga, la traduccion del repositorio **no puede dejar
     * salir ninguna `QueryException` con su mensaje**: si mañana alguien añade una
     * restriccion nueva a la tabla, el fallo tiene que llegar envuelto.
     *
     * Se provoca por el camino que si escribe —`POST /absences`— saltandose el
     * `FormRequest` es imposible, asi que se comprueba lo que se puede comprobar
     * desde fuera: que una escritura legitima con nota **no** deja el texto en el
     * log ni siquiera cuando la peticion falla por otra causa (aqui, el solape).
     */
    $contexto = contextoDeCarga();

    CapturedLog::around(function (CapturedLog $log) use ($contexto): void {
        $cuerpo = [
            'employee_uuid' => $contexto['employee'],
            'type' => 'sick_leave',
            'starts_on' => '2026-03-02',
            'ends_on' => '2026-03-06',
            'note' => 'texto-clinico-reservado',
        ];

        Api::as($contexto['token'])->post('/api/v1/absences', $cuerpo)->assertStatus(201);

        // La segunda choca con `absences_no_overlap`: es el camino por el que una
        // `QueryException` con la nota en los bindings llegaba al manejador.
        Api::as($contexto['token'])->post('/api/v1/absences', $cuerpo)->assertStatus(409);

        expect($log->dump())->not->toContain('texto-clinico-reservado');
    });
})->group('RF-GP-04', 'RS-08');

it('deja un asiento por ausencia aplicada, con el origen y la huella y sin la nota', function (): void {
    /*
     * **Decision 6 de la ficha.** Un asiento resumen no bastaria: aqui cada
     * ausencia no deja ningun otro rastro, asi que sin uno por linea una carga de
     * cuarenta bajas seria una sola fila del trail y no habria forma de responder
     * «¿quien registro esta?».
     */
    $contexto = contextoDeCarga();

    $filas = ImportFiles::rows(
        ['codigo', 'tipo', 'desde', 'hasta', 'nota'],
        [[$contexto['code'], 'otro', '2026-03-02', '2026-03-06', 'Permiso por mudanza.']],
    );

    $validacion = cargarAusencias($contexto['token'], ImportFiles::csv($filas))->assertValidResponse(200);

    cargarAusencias($contexto['token'], ImportFiles::csv($filas), 'apply', huellaDeCarga($validacion))
        ->assertValidResponse(200);

    $asiento = DB::table('audit_log')->where('action', 'absence.registered')->orderByDesc('id')->first();

    expect($asiento)->not->toBeNull();

    /** @var array<string, mixed> $payload */
    $payload = json_decode((string) ($asiento->payload ?? '{}'), true, 512, JSON_THROW_ON_ERROR);

    expect($payload['source'] ?? null)->toBe('import');
    expect($payload['file_sha256'] ?? null)->toBe(huellaDeCarga($validacion));
    expect($payload['employee_uuid'] ?? null)->toBe($contexto['employee']);
    expect($payload['type'] ?? null)->toBe('other');
    expect($payload['has_note'] ?? null)->toBeTrue();

    // Ni la nota, ni el nombre del fichero (lo pone quien sube y puede llevar
    // dentro el nombre de una persona).
    expect((string) ($asiento->payload ?? ''))->not->toContain('mudanza');
    expect((string) ($asiento->payload ?? ''))->not->toContain('.csv');
})->group('RF-GP-04', 'RS-05');
