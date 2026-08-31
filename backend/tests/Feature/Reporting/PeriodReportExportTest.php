<?php

declare(strict_types=1);

use App\Modules\Reporting\Infrastructure\Metrics\RedisReportExportMetrics;
use App\Modules\Shared\Domain\ValueObject\UserRole;
use App\Modules\Shared\Infrastructure\Export\CsvDialect;
use Illuminate\Contracts\Redis\Factory as Redis;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Spatie\SimpleExcel\SimpleExcelReader;
use Spectator\Spectator;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Tests\Support\Database\RefreshDatabase;
use Tests\Support\Http\Api;
use Tests\Support\Identity\ManagementUsers;
use Tests\Support\Reporting\FakeReportDocumentRenderer;
use Tests\Support\Reporting\PeriodReportFixtures;
use Tests\Support\Workforce\WorkforceFixtures;

/*
 * `GET /api/v1/reports/period/export` — la descarga del informe de horas en sus
 * tres formatos (**RF-IN-04**, tarea 2.9).
 *
 * ## Lo que cubren estas pruebas y lo que no
 *
 * El **borde**: codigo de estado, cabeceras, tipo de contenido, BOM, separador
 * segun idioma, celdas del XLSX como texto y el asiento de `audit_log` con su
 * formato. El calculo de las horas no se vuelve a probar aqui —lo hace
 * `PeriodReportTest`, sobre la misma consulta— y el formato `HH:MM` tampoco: es
 * de `ReportedDurationTest`, sobre el objeto de valor que lo implementa.
 *
 * ## El contrato se comprueba sobre la PETICION
 *
 * La respuesta es un fichero binario servido con `StreamedResponse`, y
 * `assertValidResponse` de Spectator espera un cuerpo JSON deserializable: un
 * `assertValidResponse` aqui no comprobaria nada y lo pareceria. La forma del
 * endpoint —los tres tipos de contenido, las cabeceras, el enumerado de
 * `format`— la comprueba `tests/Contract/OpenApiContractTest.php`. Es el mismo
 * reparto que hizo la exportacion legal de la 1.17.
 *
 * ## El motor de PDF se sustituye por un doble
 *
 * Estas pruebas son de borde y no de composicion: lo que comprueban del PDF es
 * que la respuesta sale con su tipo, su nombre y su huella, y para eso no hace
 * falta arrancar un Chromium por caso. El PDF de verdad —con su sello y su
 * huella— lo compone y lo lee `tests/Integration/Reporting/PeriodReportPdfSealTest.php`.
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Spectator::using('openapi.yaml');

    // El idioma del documento es configuracion de la instalacion (regla dura 13).
    App::setLocale('es');

    FakeReportDocumentRenderer::bind();
});

/**
 * Un centro, un departamento, una persona con tres jornadas conocidas y una
 * sesion de RRHH.
 *
 * Las mismas 22 h 30 de `PeriodReportTest`, para que las dos pruebas hablen del
 * mismo informe: si el fichero y la pantalla dejaran de coincidir, las cifras de
 * aqui dejarian de cuadrar con las de alli.
 *
 * @return array{token: string, site: int, department: int, employee: string}
 */
function contextoDeDescargaDeInforme(): array
{
    $site = WorkforceFixtures::site('Hotel de descargas', 'Europe/Madrid');
    $department = WorkforceFixtures::department($site, 'Cocina');
    $employee = WorkforceFixtures::employee($site, $department);

    PeriodReportFixtures::workDay($site, $employee, '2026-03-02', '2026-03-02 06:00', '2026-03-02 14:00');
    PeriodReportFixtures::workDay($site, $employee, '2026-03-03', '2026-03-03 07:00', '2026-03-03 15:30');
    PeriodReportFixtures::workDay($site, $employee, '2026-03-05', '2026-03-05 09:00', '2026-03-05 15:00');

    return [
        'token' => ManagementUsers::tokenFor(ManagementUsers::withRole(UserRole::RRHH)),
        'site' => $site,
        'department' => $department,
        'employee' => $employee,
    ];
}

/**
 * Los parametros del informe, iguales para los tres formatos.
 *
 * @return array<string, string>
 */
function descargaDeMarzo(string $formato): array
{
    return ['format' => $formato, 'from' => '2026-03-01', 'to' => '2026-03-07', 'granularity' => 'range'];
}

/**
 * El cuerpo de una descarga en streaming.
 *
 * `StreamedResponse` no expone el cuerpo por `getContent()`: hay que enviarlo y
 * capturar la salida. Mismo apaño que en la exportacion legal, y por lo mismo.
 *
 * @param  TestResponse<Response>  $response
 */
function cuerpoDeLaDescarga(TestResponse $response): string
{
    $base = $response->baseResponse;

    if (! $base instanceof StreamedResponse) {
        return (string) $base->getContent();
    }

    ob_start();
    $base->sendContent();

    return (string) ob_get_clean();
}

it('descarga el informe como CSV con BOM, punto y coma y las horas en HH:MM', function (): void {
    $contexto = contextoDeDescargaDeInforme();

    $respuesta = Api::as($contexto['token'])
        ->get('/api/v1/reports/period/export', descargaDeMarzo('csv'))
        ->assertValidRequest()
        ->assertOk();

    $respuesta->assertHeader('Content-Type', 'text/csv; charset=utf-8');
    $respuesta->assertHeader('Cache-Control', 'no-store, private');
    $respuesta->assertHeader(
        'Content-Disposition',
        'attachment; filename=kronoqr-horas-2026-03-01_2026-03-07.csv',
    );
    $respuesta->assertHeader('X-Kronoqr-Report-Rows', '1');

    $cuerpo = cuerpoDeLaDescarga($respuesta);

    // El BOM va antes que nada: sin el, Excel con configuracion regional
    // española lee el fichero en Windows-1252 y los acentos salen rotos.
    expect($cuerpo)->toStartWith(CsvDialect::BYTE_ORDER_MARK)
        // Separador `;` porque la instalacion habla español, donde la coma es el
        // separador decimal.
        ->and($cuerpo)->toContain('Trabajado;Contratado')
        // Fin de linea del RFC 4180, el que espera Excel en Windows.
        ->and($cuerpo)->toContain("\r\n")
        // Las horas, en `HH:MM` y nunca en decimal.
        ->and($cuerpo)->toContain('22:30')
        ->and($cuerpo)->not->toContain('22,5')
        // Y los criterios de inclusion, VISIBLES antes de la tabla.
        ->and($cuerpo)->toContain('no se parte a medianoche')
        // Con acentos de verdad, que es lo que el BOM defiende.
        ->and($cuerpo)->toContain('Desviación')
        // Y la desviacion negativa sale limpia, sin la comilla de neutralizacion
        // de formulas: `'-00:30` en una columna entera se lee como un fallo de la
        // exportacion. Ver `CsvDialect::neutralized()`.
        ->and($cuerpo)->not->toContain("'-");
})->group('RF-IN-04');

it('cambia el separador del CSV cuando la instalacion habla ingles', function (): void {
    // El punto y coma no es una preferencia: es lo que Excel espera cuando la
    // coma es el separador decimal. En una instalacion en ingles no lo es, y un
    // fichero con `;` mete todas las columnas en la primera celda.
    $contexto = contextoDeDescargaDeInforme();

    App::setLocale('en');

    $cuerpo = cuerpoDeLaDescarga(
        Api::as($contexto['token'])
            ->get('/api/v1/reports/period/export', descargaDeMarzo('csv'))
            ->assertOk(),
    );

    expect($cuerpo)->toContain('Worked,Contracted')
        ->and($cuerpo)->not->toContain('Worked;Contracted');
})->group('RF-IN-04');

it('descarga el informe como XLSX legible, con las horas como texto', function (): void {
    $contexto = contextoDeDescargaDeInforme();

    $respuesta = Api::as($contexto['token'])
        ->get('/api/v1/reports/period/export', descargaDeMarzo('xlsx'))
        ->assertValidRequest()
        ->assertOk();

    $respuesta->assertHeader(
        'Content-Type',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
    );
    $respuesta->assertHeader(
        'Content-Disposition',
        'attachment; filename=kronoqr-horas-2026-03-01_2026-03-07.xlsx',
    );

    // Se vuelve a abrir con la MISMA libreria que lo escribio. Comprobar la
    // cabecera y quedarse ahi dejaria pasar un fichero corrupto: lo que hay que
    // afirmar es que una hoja de calculo puede leerlo.
    $ruta = tempnam(sys_get_temp_dir(), 'kq').'.xlsx';
    file_put_contents($ruta, cuerpoDeLaDescarga($respuesta));

    $filas = SimpleExcelReader::create($ruta)->noHeaderRow()->getRows()->toArray();

    unlink($ruta);

    /** @var list<array<int, mixed>> $filas */
    /**
     * Las celdas llegan con el tipo que la libreria dedujo al leerlas. Se
     * normalizan a texto AQUI y no en la afirmacion: si una duracion hubiera
     * viajado como fecha o como decimal, se veria en la comparacion en vez de
     * desaparecer en una conversion silenciosa.
     *
     * @param  array<int, mixed>  $fila
     * @return list<string>
     */
    $texto = static fn (array $fila): array => array_values(array_map(
        static fn (mixed $celda): string => is_scalar($celda) ? (string) $celda : '',
        $fila,
    ));

    $rotulos = $texto($filas[0]);
    $datos = $texto($filas[1]);

    expect($rotulos)->toContain('Trabajado')
        ->and($rotulos)->toContain('Desviación')
        // La celda de la duracion es TEXTO y se lee tal cual. Si la hoja la
        // hubiera interpretado como hora del reloj, aqui llegaria un objeto de
        // fecha o un decimal.
        ->and($datos)->toContain('22:30')
        // El identificador publico llega entero: es lo que permite cruzar esta
        // hoja con el registro legal sin depender de que dos personas no se
        // llamen igual.
        ->and($datos)->toContain($contexto['employee'])
        // Y ninguna celda ha acabado siendo una hora del reloj o un decimal.
        ->and(implode('|', $datos))->not->toContain('22,5')
        ->and(implode('|', $datos))->not->toContain('22.5');
})->group('RF-IN-04');

it('descarga el informe como PDF, con su tipo y su nombre de fichero', function (): void {
    $contexto = contextoDeDescargaDeInforme();

    $respuesta = Api::as($contexto['token'])
        ->get('/api/v1/reports/period/export', descargaDeMarzo('pdf'))
        ->assertValidRequest()
        ->assertOk();

    $respuesta->assertHeader('Content-Type', 'application/pdf');
    $respuesta->assertHeader(
        'Content-Disposition',
        'attachment; filename=kronoqr-horas-2026-03-01_2026-03-07.pdf',
    );

    expect(cuerpoDeLaDescarga($respuesta))->toBe(FakeReportDocumentRenderer::BYTES);

    // El HTML que se le entrego al motor lleva las horas y los criterios: es lo
    // que hace que este caso compruebe algo mas que un tipo de contenido.
    $html = FakeReportDocumentRenderer::lastHtml();

    expect($html)->toContain('22:30')
        ->and($html)->toContain('no se parte a medianoche')
        // Sin ninguna referencia a la red: el producto se instala en servidores
        // sin salida a internet (ADR-016).
        ->and($html)->not->toContain('http://')
        ->and($html)->not->toContain('https://');
})->group('RF-IN-04');

it('publica la misma huella del contenido en los tres formatos', function (): void {
    // Es lo que hace que la huella sirva para algo: el CSV que alguien adjunta a
    // un correo y el PDF que otra persona imprime son el MISMO informe, y tienen
    // que poder demostrarlo. Un hash del binario daria tres valores distintos.
    $contexto = contextoDeDescargaDeInforme();

    $huellas = [];

    foreach (['csv', 'xlsx', 'pdf'] as $formato) {
        $respuesta = Api::as($contexto['token'])
            ->get('/api/v1/reports/period/export', descargaDeMarzo($formato))
            ->assertOk();

        $huellas[$formato] = $respuesta->headers->get('X-Kronoqr-Report-Digest');
    }

    expect($huellas['csv'])->toMatch('/^[0-9a-f]{64}$/')
        ->and($huellas['xlsx'])->toBe($huellas['csv'])
        ->and($huellas['pdf'])->toBe($huellas['csv']);
})->group('RF-IN-04');

it('entrega un informe vacio con sus criterios en vez de un 204', function (): void {
    // «No hay nadie con horas en ese periodo» tambien es una afirmacion que hay
    // que poder descargar. Un `204` dejaria a quien lo pidio sin saber si el
    // periodo esta vacio o si la descarga fallo.
    $contexto = contextoDeDescargaDeInforme();

    $respuesta = Api::as($contexto['token'])
        ->get('/api/v1/reports/period/export', [
            'format' => 'csv',
            'from' => '2027-01-01',
            'to' => '2027-01-07',
            'granularity' => 'range',
            'department_id' => $contexto['department'],
        ])
        ->assertOk();

    $cuerpo = cuerpoDeLaDescarga($respuesta);

    expect($cuerpo)->toContain('Criterios de este informe')
        ->and($cuerpo)->toContain('Informe de horas por periodo');
})->group('RF-IN-04');

it('deja en audit_log el mismo asiento de la consulta, distinguido por el formato', function (): void {
    // Regla dura 6 y RS-05: descargar las horas de terceros no puede ser anonimo.
    // Y UN SOLO ASIENTO por divulgacion: separar «consultado» y «exportado»
    // obligaria a quien lea el trail a emparejar dos entradas para contestar una
    // sola pregunta.
    $contexto = contextoDeDescargaDeInforme();

    Api::as($contexto['token'])
        ->get('/api/v1/reports/period/export', descargaDeMarzo('xlsx'))
        ->assertOk();

    $asientos = DB::table('audit_log')->where('action', 'personal_data.accessed')->get();

    // UNO, no dos: la descarga no escribe un asiento propio ademas del de la
    // consulta que la produjo.
    expect($asientos)->toHaveCount(1);

    /** @var array<string, mixed> $payload */
    $payload = json_decode((string) ($asientos->first()->payload ?? '{}'), true, 512, JSON_THROW_ON_ERROR);

    expect($payload['dataset'] ?? null)->toBe('period_report')
        // El campo que distingue la descarga de la consulta.
        ->and($payload['format'] ?? null)->toBe('xlsx')
        ->and($payload['from'] ?? null)->toBe('2026-03-01')
        ->and($payload['employees'] ?? null)->toBe(1)
        // Identificadores, nunca nombres (regla dura 21).
        ->and($payload['employee_uuids'] ?? null)->toBe($contexto['employee'])
        ->and(json_encode($payload, JSON_THROW_ON_ERROR))->not->toContain('Persona');
})->group('RF-IN-04', 'RS-05');

it('marca json como formato invalido, porque esa forma la sirve el otro endpoint', function (): void {
    $contexto = contextoDeDescargaDeInforme();

    Api::as($contexto['token'])
        ->get('/api/v1/reports/period/export', descargaDeMarzo('json'))
        ->assertStatus(422)
        ->assertJsonPath('type', 'urn:kronoqr:problem:validation-failed');
})->group('RF-IN-04');

it('exige el formato en vez de suponer uno', function (): void {
    // Suponer CSV porque es el mas comun seria decidir por quien descarga.
    $contexto = contextoDeDescargaDeInforme();

    Api::as($contexto['token'])
        ->get('/api/v1/reports/period/export', ['from' => '2026-03-01', 'to' => '2026-03-07'])
        ->assertStatus(422)
        // Se señala EL CAMPO y no se compara el texto: el mensaje sale del
        // paquete de idioma del framework y no es una decision de este producto.
        ->assertJsonStructure(['errors' => ['format']]);
})->group('RF-IN-04');

it('responde 503 con problem+json cuando el motor de PDF no esta, y no un 500 opaco', function (): void {
    // La ausencia de Chromium degrada UN FORMATO, no la exportacion. Quien lo
    // recibe tiene la salida escrita: descargar el mismo informe en CSV o XLSX.
    $contexto = contextoDeDescargaDeInforme();

    FakeReportDocumentRenderer::bindFailing();

    Api::as($contexto['token'])
        ->get('/api/v1/reports/period/export', descargaDeMarzo('pdf'))
        ->assertStatus(503)
        ->assertJsonPath('type', 'urn:kronoqr:problem:service-unavailable');

    // Y los otros dos siguen funcionando con el motor caido, que es el objeto de
    // la decision.
    Api::as($contexto['token'])
        ->get('/api/v1/reports/period/export', descargaDeMarzo('csv'))
        ->assertOk();
})->group('RF-IN-04');

it('rechaza un rango por encima del techo sincrono igual que la consulta', function (): void {
    // El presupuesto de RNF-P-05 lo comprueba el caso de uso, que es el mismo:
    // esta prueba afirma que la descarga NO tiene un camino propio que se lo
    // salte. Un `422` que remite a la generacion en diferido, no un `503`.
    $contexto = contextoDeDescargaDeInforme();

    Api::as($contexto['token'])
        ->get('/api/v1/reports/period/export', [
            'format' => 'csv',
            'from' => '2026-01-01',
            'to' => '2026-12-31',
        ])
        ->assertStatus(422)
        ->assertJsonPath('type', 'urn:kronoqr:problem:validation-failed');
})->group('RF-IN-04', 'RNF-P-05');

it('no deja el nombre de nadie en el nombre del fichero', function (): void {
    // Regla dura 21. Un adjunto llamado «horas-Lucia-Fernandez.xlsx» divulga a
    // quien se esta mirando con solo ver la bandeja de entrada, y el filtro por
    // empleado de este informe existe.
    $contexto = contextoDeDescargaDeInforme();

    $respuesta = Api::as($contexto['token'])
        ->get('/api/v1/reports/period/export', [
            ...descargaDeMarzo('csv'),
            'employee_uuid' => $contexto['employee'],
        ])
        ->assertOk();

    $disposicion = (string) $respuesta->headers->get('Content-Disposition');

    expect($disposicion)->toBe('attachment; filename=kronoqr-horas-2026-03-01_2026-03-07.csv')
        ->and($disposicion)->not->toContain($contexto['employee']);
})->group('RF-IN-04', 'RS-05');

it('cuenta la descarga en report_exports_total con su formato', function (): void {
    // La metrica del §8.2. No dice quien descargo —eso es de `audit_log`, que
    // tiene control de acceso y retencion— sino cuantas veces y en que formato,
    // que es lo que decide si la dependencia de Chromium sigue justificada.
    $contexto = contextoDeDescargaDeInforme();

    app(Redis::class)->connection()->command('DEL', [RedisReportExportMetrics::REPORT_EXPORTS_TOTAL]);

    Api::as($contexto['token'])->get('/api/v1/reports/period/export', descargaDeMarzo('csv'))->assertOk();
    Api::as($contexto['token'])->get('/api/v1/reports/period/export', descargaDeMarzo('csv'))->assertOk();
    Api::as($contexto['token'])->get('/api/v1/reports/period/export', descargaDeMarzo('xlsx'))->assertOk();

    /** @var array<string, string> $serie */
    $serie = app(Redis::class)->connection()->command('HGETALL', [
        RedisReportExportMetrics::REPORT_EXPORTS_TOTAL,
    ]);

    expect($serie['format=csv'] ?? null)->toBe('2')
        ->and($serie['format=xlsx'] ?? null)->toBe('1')
        // Ninguna etiqueta con datos de nadie (regla dura 21).
        ->and(array_keys($serie))->toBe(['format=csv', 'format=xlsx']);
})->group('RF-IN-04');

it('descarga con el `include_open_shifts=true` que serializa el contrato', function (): void {
    // La descarga comparte las reglas con la consulta (`DescribesPeriodReport`),
    // asi que el literal `true` del panel tiene que valer tambien aqui: si la
    // pantalla lo aceptara y el fichero no, «Descargar» fallaria justo con la
    // casilla marcada, y quien lo adjunta a un correo no sabria por que.
    $contexto = contextoDeDescargaDeInforme();

    Api::as($contexto['token'])
        ->get('/api/v1/reports/period/export', [...descargaDeMarzo('csv'), 'include_open_shifts' => 'true'])
        ->assertValidRequest()
        ->assertOk()
        ->assertHeader('Content-Type', 'text/csv; charset=utf-8');
})->group('RF-IN-04');
