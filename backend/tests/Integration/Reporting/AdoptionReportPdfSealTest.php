<?php

declare(strict_types=1);

use App\Modules\Compliance\Domain\ValueObject\AuditAction;
use App\Modules\Reporting\Application\Port\ReportDocumentRenderer;
use App\Modules\Reporting\Infrastructure\Adapter\BrowsershotReportRenderer;
use App\Modules\Shared\Domain\ValueObject\UserRole;
use Illuminate\Support\Facades\DB;
use Tests\Support\Attendance\AttendanceFixtures;
use Tests\Support\Database\RefreshDatabase;
use Tests\Support\Http\Api;
use Tests\Support\Identity\ManagementUsers;
use Tests\Support\Product\LicenseKeys;
use Tests\Support\Reporting\AdoptionFixtures;
use Tests\Support\Reporting\FakeReportDocumentRenderer;
use Tests\Support\Reporting\PeriodReportFixtures;
use Tests\Support\Time\FrozenTime;
use Tests\Support\Workforce\WorkforceFixtures;

/*
 * **El PDF sellado del cuadro de impacto**: fecha, emisor, periodo y huella del
 * contenido en el pie de cada pagina (**RF-IN-08**, tarea 3.13).
 *
 * ## Que hace este fichero que no hagan sus hermanas
 *
 * `tests/Feature/Reporting/AdoptionReportTest.php` descarga el cuadro en **CSV y
 * XLSX** y comprueba el borde: cabeceras, huella y asiento. El PDF se queda fuera
 * de alli a proposito —componerlo arranca un Chromium— y es este fichero el que lo
 * cierra, con el mismo reparto que `PeriodReportPdfSealTest` hizo con el informe
 * por periodo (RF-IN-04, tarea 2.9):
 *
 *   - **Con el motor de verdad**, porque es lo unico que demuestra que la
 *     instalacion puede imprimir este cuadro. Se salta con un motivo escrito si el
 *     contenedor no lleva Chromium, nunca falla con un error de proceso.
 *   - **Con el doble**, para poder leer el pie. Chromium incrusta la tipografia en
 *     subconjunto y codifica el contenido contra un CMap propio del fichero: buscar
 *     «2026-04-01» en los bytes no encuentra nada aunque la fecha este impresa, y
 *     extraerlo exigiria añadir un extractor de PDF a Composer para leer un fichero
 *     en una prueba. El reparto que queda es honesto: el PDF real demuestra que
 *     **el documento se compone**, y el HTML que se le entrega al motor —que es
 *     texto— demuestra **que dice**.
 *
 * ## Por que el cuadro se siembra en la base de datos
 *
 * Al contrario que en `PeriodReportPdfSealTest`, donde el informe se construye a
 * mano. Aqui lo que se afirma es **el camino completo de la descarga**: que la
 * huella que viaja en la cabecera es la que imprime el pie y la que queda en
 * `audit_log`, y que el asiento se escribe con el tamaño del fichero que salio de
 * verdad. Eso solo se puede afirmar pidiendo el PDF por el endpoint, y el endpoint
 * lee de la base de datos.
 *
 * ## El reloj se detiene con `FrozenTime`
 *
 * Los dos relojes: el puerto `Clock` que sella el documento y el Carbon que lee
 * Sanctum al comprobar el token (`FrozenTimeTest`, Architecture). Detener solo el
 * primero haria que el resultado dependiera del dia en que se ejecuta la suite.
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    // El cuadro es funcionalidad accesoria y su descarga exige
    // `Feature::ImpactDashboard`, igual que la consulta (ADR-019, regla dura 15).
    LicenseKeys::grantAll();
});

/**
 * Un marzo pequeño y conocido, y la cuenta de gestion que se lleva el papel.
 *
 * Dos jornadas cerradas y dos fichajes bastan: lo que este fichero comprueba es el
 * **sello**, no la aritmetica —esa es de `AdoptionIndicatorsTest`, con el conjunto
 * verificado a mano— ni las consultas, que son de las otras dos de integracion.
 *
 * La persona se llama `Amrani` a proposito: es el nombre que el pie, el asiento y la
 * cabecera de criterios **no pueden llevar** (regla dura 21).
 *
 * @return array{token: string, issuer: string, employee: string}
 */
function cuadroSelladoDeMarzo(): array
{
    $site = WorkforceFixtures::site('Hotel del cuadro sellado', 'Europe/Madrid');
    $employee = WorkforceFixtures::employee(
        $site,
        WorkforceFixtures::department($site, 'Recepcion'),
        firstName: 'Lucía',
        lastName: 'Amrani',
    );
    $device = AttendanceFixtures::device($site);
    $employeeId = AttendanceFixtures::employeeIdOf($employee);

    PeriodReportFixtures::workDay($site, $employee, '2026-03-02', '2026-03-02 06:00', '2026-03-02 14:00');
    PeriodReportFixtures::workDay($site, $employee, '2026-03-03', '2026-03-03 06:00', '2026-03-03 14:00');

    AdoptionFixtures::scan($device['id'], $employeeId, '2026-03-02 06:00:00+01', result: 'clock_in');
    AdoptionFixtures::scan($device['id'], $employeeId, '2026-03-02 14:00:00+01', result: 'clock_out');

    // Las 09:05 del 1 de abril en Madrid, que en UTC son las 07:05. El sello tiene
    // que decir 09:05 (ADR-040): un cuadro fechado dos horas antes de la hora que
    // vivio quien lo pidio parece generado por otro sistema.
    FrozenTime::at('2026-04-01 07:05:00');

    $admin = ManagementUsers::withRole(UserRole::ADMIN);

    return [
        'token' => ManagementUsers::tokenFor($admin),
        'issuer' => $admin->name,
        'employee' => $employee,
    ];
}

/**
 * Si el contenedor puede componer un PDF de verdad.
 *
 * **Nombre propio y no el `hayChromium()` de `PeriodReportPdfSealTest`**: las
 * funciones que Pest declara en un fichero de prueba son globales, asi que dos con
 * el mismo nombre no pueden convivir cuando la suite se ejecuta entera — y
 * apoyarse en la del otro fichero haria que estas pruebas fallaran al ejecutarse
 * solas, que es como se ejecutan cuando alguien esta arreglando justo esto.
 */
function hayMotorDePdfParaElCuadro(): bool
{
    // En la CI, laravel-pdf apunta al Chrome de puppeteer; en la imagen del
    // producto, al Chromium de la distribucion. Se comprueba el binario que de
    // verdad se va a usar.
    $configured = getenv('LARAVEL_PDF_CHROME_PATH');

    if (is_string($configured) && $configured !== '') {
        return is_executable($configured);
    }

    return is_executable('/usr/bin/chromium') || is_executable('/usr/bin/chromium-browser');
}

/**
 * El payload del asiento de la descarga, decodificado y con el tipo puesto.
 *
 * El constructor de consultas devuelve `stdClass|null` con columnas `mixed`, que es
 * lo honesto: PostgreSQL no promete el tipo PHP de un `JSONB`. Se comprueba aqui una
 * vez, y la comprobacion dice algo: si el asiento no existe, el fallo es «no hay
 * asiento» y no un error de tipos tres lineas mas abajo.
 *
 * @return array<string, mixed>
 */
function payloadDelAsientoSellado(mixed $entry): array
{
    expect($entry)->toBeObject('Descargar el cuadro tiene que dejar su asiento (regla dura 6).');

    $raw = is_object($entry) ? (get_object_vars($entry)['payload'] ?? null) : null;

    expect($raw)->toBeString('El asiento tiene que llevar su payload JSON.');

    $payload = json_decode(is_string($raw) ? $raw : '{}', true, 512, JSON_THROW_ON_ERROR);

    expect($payload)->toBeArray();

    /** @var array<string, mixed> $payload */
    return $payload;
}

/**
 * Las claves de un payload de auditoria, ordenadas.
 *
 * Se comparan ordenadas porque el asiento las normaliza al escribirlas: lo que se
 * afirma es que **no sobra ninguna**, no en que orden se guardaron.
 *
 * @param  array<string, mixed>  $payload
 * @return list<string>
 */
function clavesDelAsientoSellado(array $payload): array
{
    $keys = array_keys($payload);
    sort($keys);

    return $keys;
}

it('compone el PDF del cuadro con el motor de verdad y lo sella con la huella de su contenido', function (): void {
    /*
     * POR QUE NO SE BUSCA EL TEXTO DENTRO DEL PDF: esta en la cabecera del fichero.
     * Lo que si se puede afirmar, y es lo que importa aqui, es que la instalacion
     * **compone el documento** —no un fichero de cero bytes ni un error del motor—,
     * que salen los doce indicadores y que la huella del contenido viaja en la
     * cabecera para que quien descargue pueda comparar el papel sin abrirlo.
     */
    $context = cuadroSelladoDeMarzo();

    // Control: sin esto, la prueba pasaria igual con el doble enlazado por otra
    // prueba de la suite y no estaria comprobando ningun PDF.
    expect(app(ReportDocumentRenderer::class))->toBeInstanceOf(BrowsershotReportRenderer::class);

    $response = Api::as($context['token'])
        ->get('/api/v1/reports/adoption/export?format=pdf&from=2026-03-01&to=2026-03-31')
        ->assertOk();

    $pdf = (string) $response->getContent();

    expect($pdf)->toStartWith('%PDF-')
        ->and(strlen($pdf))->toBeGreaterThan(2000)
        ->and($response->headers->get('content-type'))->toContain('application/pdf')
        ->and($response->headers->get('content-disposition'))
        ->toBe('attachment; filename=kronoqr-adopcion-2026-03-01_2026-03-31.pdf')
        // Doce indicadores, doce filas: el cuadro los saca todos, tambien los que no
        // tienen valor (§1.3 y decision 2 de la ficha 3.13).
        ->and($response->headers->get('x-kronoqr-report-rows'))->toBe('12')
        ->and($response->headers->get('x-kronoqr-report-digest'))->toMatch('/^[0-9a-f]{64}$/')
        // El tamaño declarado es el del fichero que salio: es lo que permite
        // reconocer el adjunto concreto en una conversacion.
        ->and($response->headers->get('content-length'))->toBe((string) strlen($pdf));
})->group('RF-IN-08')->skip(
    ! hayMotorDePdfParaElCuadro(),
    'Chromium no esta instalado en este contenedor.',
);

it('repite en el pie del cuadro la fecha del centro, el emisor, el periodo y la huella', function (): void {
    /*
     * El pie va por el `footerHtml` del motor y no dentro del cuerpo: es la unica
     * forma de que Chromium lo imprima en TODAS las paginas. Un pie escrito en el
     * flujo del documento saldria una sola vez, al final, y una hoja suelta
     * fotocopiada del monton no diria de que cuadro es ni quien responde de el.
     *
     * Es el MISMO fragmento que el del informe por periodo, y compartirlo es
     * deliberado: el sello de dos documentos del mismo producto no puede divergir.
     */
    FakeReportDocumentRenderer::bind();

    $context = cuadroSelladoDeMarzo();

    $response = Api::as($context['token'])
        ->get('/api/v1/reports/adoption/export?format=pdf&from=2026-03-01&to=2026-03-31')
        ->assertOk();

    $pie = FakeReportDocumentRenderer::lastFooter();

    // Las cuatro cosas del sello. Se afirman una a una y no encadenadas tras un
    // `->not`: Pest estrecha el tipo de la expectativa al negar, y lo que se gana
    // con la cadena se paga con una afirmacion que deja de comprobarse.
    expect($pie)->toContain('2026-04-01 09:05');
    // Las 09:05 de Madrid, no las 07:05 de UTC (regla dura 3, ADR-040).
    expect($pie)->not->toContain('07:05');
    expect($pie)->toContain('Europe/Madrid');
    // El nombre de la cuenta emisora, nunca su correo (regla dura 12).
    expect($pie)->toContain($context['issuer']);
    expect($pie)->not->toContain('@');
    expect($pie)->toContain('2026-03-01');
    expect($pie)->toContain('2026-03-31');
    // La huella del pie es la de la cabecera: es lo que permite confirmar que el
    // papel que alguien tiene delante es el fichero que salio de aqui.
    expect($pie)->toContain((string) $response->headers->get('x-kronoqr-report-digest'));
    // Y ningun nombre de empleado: el unico nombre del documento es el de quien
    // responde de el (regla dura 21).
    expect($pie)->not->toContain('Amrani');

    // El cuerpo lleva las dos tablas del cuadro y tampoco lleva a nadie dentro.
    expect(FakeReportDocumentRenderer::lastHtml())->toContain('Cuadro de impacto y adopción');
    expect(FakeReportDocumentRenderer::lastHtml())->toContain('Reparto de fichajes por origen');
    expect(FakeReportDocumentRenderer::lastHtml())->not->toContain('Amrani');
    expect(FakeReportDocumentRenderer::lastHtml())->not->toContain($context['employee']);
})->group('RF-IN-08');

it('sella el PDF y el CSV del mismo periodo con la misma huella', function (): void {
    /*
     * La huella es del CONTENIDO y no del binario: dos formatos del mismo cuadro son
     * el mismo cuadro, y un hash de los bytes no serviria para comparar nada. Es lo
     * que permite que quien recibe el PDF en una reunion y quien abre el CSV en una
     * hoja de calculo puedan decir que estan mirando lo mismo.
     *
     * Con el doble del motor a proposito: la huella se calcula **antes** de saber en
     * que formato sale el fichero, asi que arrancar Chromium para afirmarlo solo
     * añadiria segundos y una dependencia a una prueba que no la necesita.
     */
    FakeReportDocumentRenderer::bind();

    $context = cuadroSelladoDeMarzo();

    $pdf = Api::as($context['token'])
        ->get('/api/v1/reports/adoption/export?format=pdf&from=2026-03-01&to=2026-03-31')
        ->assertOk();

    $csv = Api::as($context['token'])
        ->get('/api/v1/reports/adoption/export?format=csv&from=2026-03-01&to=2026-03-31')
        ->assertOk();

    expect($pdf->headers->get('x-kronoqr-report-digest'))
        ->toBe($csv->headers->get('x-kronoqr-report-digest'))
        ->and($pdf->headers->get('x-kronoqr-report-rows'))->toBe('12')
        ->and($csv->headers->get('x-kronoqr-report-rows'))->toBe('12');
})->group('RF-IN-08');

it('deja un unico asiento de la descarga con el periodo, el formato, la huella y el tamano', function (): void {
    /*
     * REGLA DURA 6 y decision 5 de la ficha 3.13. Lo que se registra no es un acceso
     * a los datos de nadie —el cuadro es un agregado de la instalacion entera— sino
     * que **un documento del sistema ha salido del sistema**: ese papel va a una
     * reunion, se adjunta a un correo y sostiene la renovacion de la licencia.
     *
     * UN SOLO ASIENTO por descarga, y esto es la mitad de la prueba: el controlador
     * compone, audita y **despues** responde, asi que un asiento por cada intento de
     * escritura o un asiento por pagina del PDF llenaria el trail —cuatro años de
     * retencion (RL-02)— de filas que no describen ninguna salida nueva.
     *
     * El doble del motor no cambia nada de lo que se afirma: el asiento describe el
     * fichero que se entrego, y `size_bytes` se compara contra los bytes de ESA
     * respuesta, sean del motor real o del doble.
     */
    FakeReportDocumentRenderer::bind();

    $context = cuadroSelladoDeMarzo();

    $response = Api::as($context['token'])
        ->get('/api/v1/reports/adoption/export?format=pdf&from=2026-03-01&to=2026-03-31')
        ->assertOk();

    $entries = DB::table('audit_log')
        ->where('action', AuditAction::AdoptionReportExported->value)
        ->get();

    expect($entries)->toHaveCount(1);

    $payload = payloadDelAsientoSellado($entries->first());

    expect($payload['from'])->toBe('2026-03-01')
        ->and($payload['to'])->toBe('2026-03-31')
        ->and($payload['format'])->toBe('pdf')
        ->and($payload['sha256'])->toBe($response->headers->get('x-kronoqr-report-digest'))
        ->and($payload['size_bytes'])->toBe(strlen((string) $response->getContent()))
        // REGLA DURA 21: ni un nombre y ni un identificador de persona. Y ninguna
        // clave de mas: el asiento dice exactamente que salio y de que periodo.
        ->and(clavesDelAsientoSellado($payload))->toBe(['format', 'from', 'sha256', 'size_bytes', 'to']);

    // Se comprueba tambien sobre el texto del payload y no solo campo a campo, para
    // que una clave nueva con un `uuid` o un nombre dentro no pueda colarse sin que
    // esto se ponga en rojo.
    $texto = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);

    expect($texto)->not->toContain('Amrani')
        ->and($texto)->not->toContain($context['employee'])
        ->and($texto)->not->toContain('employee_uuid');
})->group('RF-IN-08', 'RS-05');
