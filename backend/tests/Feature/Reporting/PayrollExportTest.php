<?php

declare(strict_types=1);

use App\Modules\Shared\Domain\ValueObject\UserRole;
use App\Modules\Shared\Infrastructure\Export\CsvDialect;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Spatie\SimpleExcel\SimpleExcelReader;
use Spectator\Spectator;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Tests\Support\Database\RefreshDatabase;
use Tests\Support\Http\Api;
use Tests\Support\Identity\ManagementUsers;
use Tests\Support\Product\LicenseKeys;
use Tests\Support\Reporting\PeriodReportFixtures;
use Tests\Support\Workforce\WorkforceFixtures;

/*
 * `GET /api/v1/reports/payroll-export` — la salida a nomina en el formato que
 * el cliente configura (**RF-IN-07**, RF-PD-01, tarea 3.9).
 *
 * ## Que se afirma aqui, y que no
 *
 * Lo que se afirma es **la promesa de RF-IN-07**: que el formato del fichero es
 * configuracion y no codigo, y que cambiarlo desde el panel produce otro fichero
 * sin desplegar nada (ADR-017, regla dura 13). Por eso los ajustes se cambian
 * **por el endpoint de ajustes** y no escribiendo la fila a mano: si la prueba
 * fabricara los dos extremos, no comprobaria que el camino existe.
 *
 * El calculo de las horas no se vuelve a probar: lo hace `PeriodReportTest` sobre
 * la misma consulta. El formato de una duracion tampoco: es de `PayrollCellTest`,
 * sobre el objeto de valor que lo implementa.
 *
 * ## El contrato se comprueba sobre la PETICION
 *
 * La respuesta es un fichero binario servido con `StreamedResponse`, y
 * `assertValidResponse` de Spectator espera un cuerpo JSON deserializable. La
 * forma del endpoint —los dos tipos de contenido, las cabeceras, los enumerados—
 * la comprueba `tests/Contract/OpenApiContractTest.php`. Es el mismo reparto que
 * hizo la descarga del informe por periodo.
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    // La salida a nomina es funcionalidad ACCESORIA (ADR-023) y `payroll_export`
    // es su primer consumidor. Su degradacion tiene fichero propio,
    // `tests/Feature/Product/LicenseDegradesAccessoriesTest.php`.
    LicenseKeys::grantAll();

    Spectator::using('openapi.yaml');
});

/**
 * Un centro, un departamento, una persona con apellido acentuado y tres
 * jornadas conocidas: 8 h + 8 h 30 + 6 h = **22:30**.
 *
 * Las mismas de `PeriodReportExportTest`, para que las dos pruebas hablen del
 * mismo informe: la salida a nomina no es otra consulta, y si las cifras dejaran
 * de coincidir seria porque alguien le dio una.
 *
 * @return array{rrhh: string, admin: string, site: int, department: int, employee: string}
 */
function contextoDeNomina(): array
{
    $site = WorkforceFixtures::site('Hotel de nomina', 'Europe/Madrid');
    $department = WorkforceFixtures::department($site, 'Cocina');
    $employee = WorkforceFixtures::employee(
        $site,
        $department,
        firstName: 'Lucía',
        lastName: 'Fernández',
        employeeCode: 'EMP-0007',
    );

    PeriodReportFixtures::workDay($site, $employee, '2026-03-02', '2026-03-02 06:00', '2026-03-02 14:00');
    PeriodReportFixtures::workDay($site, $employee, '2026-03-03', '2026-03-03 07:00', '2026-03-03 15:30');
    PeriodReportFixtures::workDay($site, $employee, '2026-03-05', '2026-03-05 09:00', '2026-03-05 15:00');

    return [
        'rrhh' => ManagementUsers::tokenFor(ManagementUsers::withRole(UserRole::RRHH)),
        // La configuracion es de `admin`: `rrhh` no lleva `settings:*` (§7.3).
        'admin' => ManagementUsers::tokenFor(ManagementUsers::withRole(UserRole::ADMIN)),
        'site' => $site,
        'department' => $department,
        'employee' => $employee,
    ];
}

/**
 * @return array<string, string>
 */
function nominaDeMarzo(string $formato = 'csv'): array
{
    return ['format' => $formato, 'from' => '2026-03-01', 'to' => '2026-03-07'];
}

/**
 * El cuerpo de una descarga en streaming.
 *
 * `StreamedResponse` no expone el cuerpo por `getContent()`: hay que enviarlo y
 * capturar la salida. Mismo apaño que en la descarga del informe.
 *
 * @param  TestResponse<Response>  $response
 */
function cuerpoDeLaNomina(TestResponse $response): string
{
    $base = $response->baseResponse;

    if (! $base instanceof StreamedResponse) {
        return (string) $base->getContent();
    }

    ob_start();
    $base->sendContent();

    return (string) ob_get_clean();
}

/**
 * Cambia ajustes **por el endpoint**, que es el camino que el cliente tiene.
 *
 * @param  array<string, string|list<string>>  $settings
 */
function configuraNomina(string $adminToken, array $settings): void
{
    Api::as($adminToken)->patch('/api/v1/settings', ['settings' => $settings])
        ->assertValidRequest()
        ->assertValidResponse(200);
}

// --- La plantilla de serie ---------------------------------------------------

it('descarga la nomina con la plantilla de serie: BOM, punto y coma, cabecera y HH:MM', function (): void {
    // EL VALOR DE SERIE ES EL PRODUCTO: una instalacion sin configurar nada tiene
    // que poder exportar a nomina, y lo que salga tiene que ser util.
    $contexto = contextoDeNomina();

    $respuesta = Api::as($contexto['rrhh'])
        ->get('/api/v1/reports/payroll-export', nominaDeMarzo())
        ->assertValidRequest()
        ->assertOk();

    $respuesta->assertHeader('Content-Type', 'text/csv; charset=utf-8');
    $respuesta->assertHeader('Cache-Control', 'no-store, private');
    // `nomina` y no `horas`, para que no se confunda en una carpeta de descargas
    // con el informe por periodo, que lleva las mismas fechas y otro contenido.
    $respuesta->assertHeader(
        'Content-Disposition',
        'attachment; filename=kronoqr-nomina-2026-03-01_2026-03-07.csv',
    );
    $respuesta->assertHeader('X-Kronoqr-Export-Rows', '1');

    $cuerpo = cuerpoDeLaNomina($respuesta);

    expect($cuerpo)->toStartWith(CsvDialect::BYTE_ORDER_MARK)
        // Las diez columnas de serie, con sus rotulos y el separador de serie.
        // Los rotulos con espacios van entrecomillados, que es lo que hace
        // `fputcsv` con el entrecomillado del RFC 4180.
        ->and($cuerpo)->toContain('"Código de empleado";Apellidos;Nombre;Departamento;Desde;Hasta;'
            .'"Horas trabajadas";"Horas contratadas";"Exceso de jornada";"Días de ausencia"')
        // Y la fila: el codigo, los apellidos con acento, el periodo y 22:30.
        ->and($cuerpo)->toContain('EMP-0007;Fernández;Lucía;')
        ->and($cuerpo)->toContain(';2026-03-01;2026-03-07;22:30;')
        // Fin de linea del RFC 4180, el que espera Excel en Windows.
        ->and($cuerpo)->toContain("\r\n")
        // Horas como reloj, nunca decimal, mientras nadie lo configure.
        ->and($cuerpo)->not->toContain('22,50')
        ->and($cuerpo)->not->toContain('22.50');
})->group('RF-IN-07', 'RF-IN-04');

it('no mete los criterios dentro del fichero: van en una cabecera', function (): void {
    // LA DIFERENCIA DELIBERADA con `/reports/period/export`. Aquel fichero lo abre
    // una persona y por eso lleva los criterios en celdas visibles; este lo
    // importa un programa, y una linea de comentario antes de la cabecera rompe
    // la importacion o da de alta un empleado llamado «Criterios de este informe».
    $contexto = contextoDeNomina();

    $respuesta = Api::as($contexto['rrhh'])
        ->get('/api/v1/reports/payroll-export', nominaDeMarzo())
        ->assertOk();

    $cuerpo = cuerpoDeLaNomina($respuesta);

    expect($cuerpo)->not->toContain('Criterios de este informe')
        ->and($cuerpo)->not->toContain('no se parte a medianoche');

    // No se pierden: viajan en base64 de UTF-8, que es lo unico que admite una
    // cabecera HTTP cuando el texto lleva acentos y saltos de linea.
    $criterios = base64_decode((string) $respuesta->headers->get('X-Kronoqr-Export-Criteria'), true);

    expect($criterios)->toBeString()
        ->and((string) $criterios)->toContain('no se parte a medianoche')
        ->and((string) $criterios)->toContain('no conoce el cuadrante');
})->group('RF-IN-07', 'RS-05');

// --- El formato es configuracion: RF-PD-01 --------------------------------

it('cambia el separador del fichero al cambiar el ajuste, sin tocar codigo', function (): void {
    // LA PROMESA ENTERA DE RF-IN-07 y el resultado esperado literal de la ficha:
    // «cambiar el formato de nomina por configuracion produce otro fichero sin
    // desplegar codigo».
    $contexto = contextoDeNomina();

    configuraNomina($contexto['admin'], ['PAYROLL_EXPORT_DELIMITER' => 'tab']);

    $cuerpo = cuerpoDeLaNomina(
        Api::as($contexto['rrhh'])->get('/api/v1/reports/payroll-export', nominaDeMarzo())->assertOk(),
    );

    expect($cuerpo)->toContain("EMP-0007\tFernández\tLucía")
        ->and($cuerpo)->not->toContain('EMP-0007;');
})->group('RF-IN-07', 'RF-PD-01');

it('cambia las horas a decimal con coma al cambiar el ajuste', function (): void {
    // La excepcion razonada al «nunca decimal»: este fichero lo importa un
    // programa que multiplica por un precio hora. Y el separador se ELIGE: `7.75`
    // leido con separador de miles español es setecientos setenta y cinco.
    $contexto = contextoDeNomina();

    configuraNomina($contexto['admin'], ['PAYROLL_EXPORT_HOURS_FORMAT' => 'decimal_comma']);

    $cuerpo = cuerpoDeLaNomina(
        Api::as($contexto['rrhh'])->get('/api/v1/reports/payroll-export', nominaDeMarzo())->assertOk(),
    );

    // La coma decimal NO obliga a entrecomillar porque el separador de campos es
    // `;`: es justo la combinacion que la instalacion española necesita.
    expect($cuerpo)->toContain(';22,50;')
        ->and($cuerpo)->not->toContain(';22:30;')
        // Y el cero tambien lleva sus dos decimales: `0` a secas y `0,00` no
        // significan lo mismo para un importador que espera un decimal.
        ->and($cuerpo)->toContain(';0,00;');
})->group('RF-IN-07', 'RF-PD-01', 'RF-IN-04');

it('cambia las columnas, su orden y sus rotulos al cambiar el ajuste', function (): void {
    // Tres cosas a la vez, que son las tres que un importador de nomina necesita
    // poder ajustar: CUALES, EN QUE ORDEN y COMO SE LLAMAN. El rotulo configurado
    // gana y no se traduce: esta escrito para encajar con SU programa.
    $contexto = contextoDeNomina();

    configuraNomina($contexto['admin'], [
        'PAYROLL_EXPORT_COLUMNS' => ['worked_hours=HORAS', 'employee_code=COD_EMPL', 'time_zone'],
        'PAYROLL_EXPORT_DATE_FORMAT' => 'dmy',
    ]);

    $cuerpo = cuerpoDeLaNomina(
        Api::as($contexto['rrhh'])->get('/api/v1/reports/payroll-export', nominaDeMarzo())->assertOk(),
    );

    expect($cuerpo)->toContain('HORAS;COD_EMPL;"Zona horaria"')
        // La zona del centro va DENTRO del fichero: sin ella quien lo importa
        // tiene que suponerla (regla dura 3).
        ->and($cuerpo)->toContain('22:30;EMP-0007;Europe/Madrid')
        // Y lo que ya no esta, no esta: las columnas de serie desaparecieron.
        ->and($cuerpo)->not->toContain('Apellidos');
})->group('RF-IN-07', 'RF-PD-01');

it('quita la fila de cabecera cuando el importador no la admite', function (): void {
    // Hay importadores que tratan la primera linea como datos y acaban dando de
    // alta a un empleado llamado «Código de empleado».
    $contexto = contextoDeNomina();

    configuraNomina($contexto['admin'], ['PAYROLL_EXPORT_HEADER_ROW' => 'disabled']);

    $cuerpo = cuerpoDeLaNomina(
        Api::as($contexto['rrhh'])->get('/api/v1/reports/payroll-export', nominaDeMarzo())->assertOk(),
    );

    expect($cuerpo)->not->toContain('Código de empleado')
        ->and($cuerpo)->toContain('EMP-0007');
})->group('RF-IN-07', 'RF-PD-01');

it('escribe el fichero en latin1 con los acentos, sin fallar, cuando el programa lo exige', function (): void {
    // Los programas de nomina del sector llevan decadas instalados y varios solo
    // importan ISO-8859-1. Entregarles UTF-8 produce «Fernández» roto dentro de
    // la nomina de una persona.
    $contexto = contextoDeNomina();

    configuraNomina($contexto['admin'], ['PAYROLL_EXPORT_ENCODING' => 'latin1']);

    $respuesta = Api::as($contexto['rrhh'])
        ->get('/api/v1/reports/payroll-export', nominaDeMarzo())
        ->assertOk();

    // El `charset` se anuncia ademas: es para el programa que lo consuma por
    // HTTP, como el BOM lo es para el que abre el fichero descargado.
    $respuesta->assertHeader('Content-Type', 'text/csv; charset=iso-8859-1');

    $cuerpo = cuerpoDeLaNomina($respuesta);

    // Sin BOM —`latin1` no lo lleva— y con los acentos en su byte de
    // ISO-8859-1: «á» es 0xE1 y «í» es 0xED. Que el fichero NO sea UTF-8 valido
    // es justo lo que se esta comprobando.
    expect($cuerpo)->not->toStartWith(CsvDialect::BYTE_ORDER_MARK)
        ->and($cuerpo)->toContain("Fern\xE1ndez")
        ->and($cuerpo)->toContain("Luc\xEDa")
        ->and($cuerpo)->not->toContain('Fernández');

    // Y vuelve a ser legible al convertirlo, que es lo que hara el importador.
    expect(iconv('ISO-8859-1', 'UTF-8', $cuerpo))->toContain('Fernández');
})->group('RF-IN-07', 'RF-PD-01');

it('escribe la nomina sin BOM cuando el importador lo lee como parte de la primera columna', function (): void {
    $contexto = contextoDeNomina();

    configuraNomina($contexto['admin'], ['PAYROLL_EXPORT_ENCODING' => 'utf8']);

    $cuerpo = cuerpoDeLaNomina(
        Api::as($contexto['rrhh'])->get('/api/v1/reports/payroll-export', nominaDeMarzo())->assertOk(),
    );

    expect($cuerpo)->not->toStartWith(CsvDialect::BYTE_ORDER_MARK)
        // El primer byte es ya la primera celda —entrecomillada por llevar
        // espacios—, no la marca de orden de bytes.
        ->and($cuerpo)->toStartWith('"Código de empleado"');
})->group('RF-IN-07', 'RF-PD-01');

// --- XLSX --------------------------------------------------------------------

it('descarga la nomina como XLSX legible, con las columnas configuradas y como texto', function (): void {
    $contexto = contextoDeNomina();

    configuraNomina($contexto['admin'], [
        'PAYROLL_EXPORT_COLUMNS' => ['employee_code', 'worked_hours=HORAS'],
    ]);

    $respuesta = Api::as($contexto['rrhh'])
        ->get('/api/v1/reports/payroll-export', nominaDeMarzo('xlsx'))
        ->assertValidRequest()
        ->assertOk();

    $respuesta->assertHeader(
        'Content-Type',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
    );
    $respuesta->assertHeader(
        'Content-Disposition',
        'attachment; filename=kronoqr-nomina-2026-03-01_2026-03-07.xlsx',
    );

    // Se vuelve a abrir con la MISMA libreria que lo escribio: comprobar la
    // cabecera y quedarse ahi dejaria pasar un fichero corrupto.
    $ruta = tempnam(sys_get_temp_dir(), 'kq').'.xlsx';
    file_put_contents($ruta, cuerpoDeLaNomina($respuesta));

    $filas = SimpleExcelReader::create($ruta)->noHeaderRow()->getRows()->toArray();

    unlink($ruta);

    /** @var list<array<int, mixed>> $filas */
    $texto = static fn (array $fila): array => array_values(array_map(
        static fn (mixed $celda): string => is_scalar($celda) ? (string) $celda : '',
        $fila,
    ));

    expect($texto($filas[0]))->toBe(['Código de empleado', 'HORAS'])
        // La duracion es TEXTO y se lee tal cual: si la hoja la hubiera
        // interpretado como hora del reloj, aqui llegaria un objeto de fecha.
        ->and($texto($filas[1]))->toBe(['EMP-0007', '22:30']);
})->group('RF-IN-07', 'RF-IN-04');

// --- Granularidad y casos del calendario ------------------------------------

it('agrupa por mes y por dia cuando la nomina lo pide', function (string $granularidad, int $filas): void {
    // `range` por omision —una fila por persona— y los otros dos para las nominas
    // que cargan el detalle. `week` no existe: los periodos de nomina son el mes
    // o un rango libre.
    $contexto = contextoDeNomina();

    $respuesta = Api::as($contexto['rrhh'])
        ->get('/api/v1/reports/payroll-export', [
            ...nominaDeMarzo(),
            'granularity' => $granularidad,
        ])
        ->assertOk();

    $respuesta->assertHeader('X-Kronoqr-Export-Rows', (string) $filas);
})->with([
    'una fila por persona y periodo' => ['range', 1],
    'una fila por persona y mes' => ['month', 1],
    // Los siete dias del 1 al 7, incluidos los que no tienen actividad: para un
    // informe de absentismo omitirlos es un error (`/informe-nuevo`, paso 1).
    'una fila por persona y jornada' => ['day', 7],
])->group('RF-IN-07');

it('rechaza la granularidad semanal, que ninguna nomina sabe repartir', function (): void {
    // Las semanas a caballo de dos meses producen filas que ningun importador
    // sabe asignar a un periodo de nomina.
    $contexto = contextoDeNomina();

    Api::as($contexto['rrhh'])
        ->get('/api/v1/reports/payroll-export', [...nominaDeMarzo(), 'granularity' => 'week'])
        ->assertStatus(422)
        ->assertJsonStructure(['errors' => ['granularity']]);
})->group('RF-IN-07');

it('atribuye el turno nocturno a la jornada en la que empezo', function (): void {
    // RN-05 y regla dura 4: un turno 22:00 -> 06:00 es un unico tramo de la
    // jornada de su hora de inicio. Si el fichero de nomina lo partiera, la
    // nomina de marzo se llevaria horas de abril.
    $contexto = contextoDeNomina();

    PeriodReportFixtures::workDay(
        $contexto['site'],
        $contexto['employee'],
        '2026-03-07',
        '2026-03-07 22:00',
        '2026-03-08 06:00',
    );

    $cuerpo = cuerpoDeLaNomina(
        Api::as($contexto['rrhh'])
            ->get('/api/v1/reports/payroll-export', [...nominaDeMarzo(), 'granularity' => 'day'])
            ->assertOk(),
    );

    // Las ocho horas enteras el dia 7, y el dia 8 ni siquiera esta en el rango.
    expect($cuerpo)->toContain('2026-03-07;2026-03-07;08:00')
        ->and($cuerpo)->not->toContain('2026-03-08');
})->group('RF-IN-07', 'RN-05');

it('cuenta las 23 horas reales de la semana del cambio de hora', function (): void {
    // El 29 de marzo de 2026 los relojes se adelantan a las 02:00 en Europa. Un
    // turno de 00:00 a 08:00 dura SIETE horas, no ocho: si el fichero de nomina
    // dijera ocho, se pagaria una hora que nadie trabajo.
    $contexto = contextoDeNomina();

    PeriodReportFixtures::workDay(
        $contexto['site'],
        $contexto['employee'],
        '2026-03-29',
        '2026-03-29 00:00',
        '2026-03-29 08:00',
    );

    $cuerpo = cuerpoDeLaNomina(
        Api::as($contexto['rrhh'])
            ->get('/api/v1/reports/payroll-export', [
                'format' => 'csv',
                'from' => '2026-03-23',
                'to' => '2026-03-29',
            ])
            ->assertOk(),
    );

    expect($cuerpo)->toContain('2026-03-23;2026-03-29;07:00');
})->group('RF-IN-07', 'RN-05');

it('sigue exportando a quien se dio de baja a mitad de periodo', function (): void {
    // RN-14: las horas de quien ya no esta siguen siendo suyas y hay que pagarlas.
    // Un fichero de nomina que se dejara fuera a quien causo baja el dia 5 seria
    // exactamente el que produce una nomina impagada.
    $contexto = contextoDeNomina();

    WorkforceFixtures::terminate($contexto['employee']);

    $cuerpo = cuerpoDeLaNomina(
        Api::as($contexto['rrhh'])->get('/api/v1/reports/payroll-export', nominaDeMarzo())->assertOk(),
    );

    expect($cuerpo)->toContain('EMP-0007')
        ->and($cuerpo)->toContain('22:30');
})->group('RF-IN-07', 'RN-14');

it('entrega un fichero con su cabecera y sin filas cuando no hay nadie que exportar', function (): void {
    // «No hay horas que pagar» tambien es una afirmacion que hay que poder
    // importar. Un `204` dejaria a quien lo pidio sin saber si el periodo esta
    // vacio o si la descarga fallo.
    //
    // Se filtra por un departamento SIN GENTE y no por un periodo sin fichajes:
    // en un periodo sin actividad la persona sigue saliendo con cero, que es lo
    // que exige el paso 1 de `/informe-nuevo` —para un informe de absentismo,
    // omitir los dias sin actividad es un error— y lo comprueba el caso de abajo.
    $contexto = contextoDeNomina();
    $vacio = WorkforceFixtures::department($contexto['site'], 'Lavanderia');

    $respuesta = Api::as($contexto['rrhh'])
        ->get('/api/v1/reports/payroll-export', [...nominaDeMarzo(), 'department_id' => $vacio])
        ->assertOk();

    $respuesta->assertHeader('X-Kronoqr-Export-Rows', '0');

    expect(cuerpoDeLaNomina($respuesta))->toContain('Código de empleado');
})->group('RF-IN-07');

it('exporta con cero a quien no ficho en el periodo, en vez de omitirlo', function (): void {
    // `/informe-nuevo`, paso 1: «¿los dias sin actividad aparecen con cero o se
    // omiten? Para un informe de absentismo, omitirlos es un error». En nomina
    // pesa aun mas: una persona que desaparece del fichero es una persona que no
    // aparece en la nomina.
    $contexto = contextoDeNomina();

    $respuesta = Api::as($contexto['rrhh'])
        ->get('/api/v1/reports/payroll-export', [
            'format' => 'csv',
            'from' => '2027-01-01',
            'to' => '2027-01-07',
        ])
        ->assertOk();

    $respuesta->assertHeader('X-Kronoqr-Export-Rows', '1');

    // Una sola lectura: `StreamedResponse` emite su cuerpo una vez, y pedirlo dos
    // veces devuelve la segunda vacio.
    $cuerpo = cuerpoDeLaNomina($respuesta);

    expect($cuerpo)->toContain('EMP-0007')
        ->and($cuerpo)->toContain(';00:00;');
})->group('RF-IN-07');

// --- Presupuesto sincrono, auditoria y forma del endpoint -------------------

it('remite a la generacion en diferido cuando el rango no cabe en el acto', function (): void {
    // El presupuesto de RNF-P-05 lo comprueba el mismo caso de uso: esta prueba
    // afirma que la nomina NO tiene un camino propio que se lo salte. Un `422`
    // que remite a `POST /reports/exports` con `kind: payroll`, no un `503`.
    $contexto = contextoDeNomina();

    Api::as($contexto['rrhh'])
        ->get('/api/v1/reports/payroll-export', [
            'format' => 'csv',
            'from' => '2026-01-01',
            'to' => '2026-12-31',
        ])
        ->assertStatus(422)
        // `type` PROPIO, el mismo que el informe por periodo: la salida es la
        // misma —pedirlo en segundo plano— y el cliente la reconoce por el `type`
        // y no por el texto.
        ->assertJsonPath('type', 'urn:kronoqr:problem:report-too-large');
})->group('RF-IN-07', 'RNF-P-05');

it('deja en audit_log la divulgacion con el conjunto payroll_export', function (): void {
    // RS-05 y regla dura 6: llevarse las horas de la plantilla en un fichero
    // preparado para otro sistema no puede ser anonimo. Y el conjunto distingue
    // «miro el cuadro de horas» de «se llevo el fichero de nomina», que ante una
    // brecha (RL-15) no es lo mismo.
    $contexto = contextoDeNomina();

    Api::as($contexto['rrhh'])->get('/api/v1/reports/payroll-export', nominaDeMarzo())->assertOk();

    $asientos = DB::table('audit_log')->where('action', 'personal_data.accessed')->get();

    expect($asientos)->toHaveCount(1);

    /** @var array<string, mixed> $payload */
    $payload = json_decode((string) ($asientos->first()->payload ?? '{}'), true, 512, JSON_THROW_ON_ERROR);

    expect($payload['dataset'] ?? null)->toBe('payroll_export')
        ->and($payload['format'] ?? null)->toBe('csv')
        ->and($payload['from'] ?? null)->toBe('2026-03-01')
        ->and($payload['group_by'] ?? null)->toBe('employee')
        ->and($payload['employees'] ?? null)->toBe(1)
        // Identificadores, nunca nombres (regla dura 21).
        ->and($payload['employee_uuids'] ?? null)->toBe($contexto['employee'])
        ->and(json_encode($payload, JSON_THROW_ON_ERROR))->not->toContain('Fern');
})->group('RF-IN-07', 'RS-05');

it('no deja el nombre de nadie en el nombre del fichero', function (): void {
    // Regla dura 21. Un adjunto llamado «nomina-Lucia-Fernandez.csv» divulga a
    // quien se esta mirando con solo ver la bandeja de entrada, y el filtro por
    // empleado de este endpoint existe.
    $contexto = contextoDeNomina();

    $respuesta = Api::as($contexto['rrhh'])
        ->get('/api/v1/reports/payroll-export', [
            ...nominaDeMarzo(),
            'employee_uuid' => $contexto['employee'],
        ])
        ->assertOk();

    $disposicion = (string) $respuesta->headers->get('Content-Disposition');

    expect($disposicion)->toBe('attachment; filename=kronoqr-nomina-2026-03-01_2026-03-07.csv')
        ->and($disposicion)->not->toContain($contexto['employee'])
        ->and($disposicion)->not->toContain('Fern');
})->group('RF-IN-07', 'RS-05');

it('exige el formato y no admite pdf ni json', function (string $formato): void {
    // Un PDF no se importa en ninguna nomina y arrancaria un Chromium para
    // producir un fichero que nadie puede usar; el JSON lo sirve el otro
    // endpoint. Y sin `format` no se supone CSV: quien pulsa un boton de descarga
    // ya ha elegido.
    $contexto = contextoDeNomina();

    $parametros = $formato === '' ? ['from' => '2026-03-01', 'to' => '2026-03-07'] : nominaDeMarzo($formato);

    Api::as($contexto['rrhh'])
        ->get('/api/v1/reports/payroll-export', $parametros)
        ->assertStatus(422)
        ->assertJsonStructure(['errors' => ['format']]);
})->with([
    'sin formato' => [''],
    'pdf' => ['pdf'],
    'json' => ['json'],
])->group('RF-IN-07');

it('deja pasar tambien al administrador, que es la otra mitad de rrhh+', function (): void {
    // El control positivo del rol que la matriz de autorizacion negativa no
    // cubre: sin el, los `403` de `AuthorizationNegativeTest` pasarian identicos
    // si esta ruta estuviera rota o no existiera.
    $contexto = contextoDeNomina();

    Api::as($contexto['admin'])
        ->get('/api/v1/reports/payroll-export', nominaDeMarzo())
        ->assertOk();
})->group('RF-IN-07', 'RF-ID-03');
