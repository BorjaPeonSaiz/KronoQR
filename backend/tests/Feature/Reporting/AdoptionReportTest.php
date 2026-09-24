<?php

declare(strict_types=1);

use App\Modules\Compliance\Domain\ValueObject\AuditAction;
use App\Modules\Shared\Domain\ValueObject\UserRole;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Spectator\Spectator;
use Symfony\Component\HttpFoundation\Response;
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
 * `GET /api/v1/reports/adoption` y su descarga — el cuadro de impacto y adopcion
 * (**RF-IN-08**, **RNF-D-01**, tarea 3.13).
 *
 * ## Que se afirma aqui, y que no
 *
 * Aqui se afirma **la forma de la respuesta y el camino completo**: que los doce
 * indicadores llegan con sus criterios traducidos, que el periodo por omision es
 * el mes anterior resuelto en la zona del centro, que el techo responde `422`, que
 * la descarga sella el documento y deja asiento, y que leer el cuadro **no** deja
 * ninguno.
 *
 * La ARITMETICA no se vuelve a probar: es de `tests/Unit/Reporting/Domain/
 * AdoptionIndicatorsTest.php`, donde cada porcentaje se verifica a mano contra un
 * conjunto conocido. Que las CONSULTAS cuenten lo que dicen contar es de las
 * pruebas de integracion. Lo que esta separacion compra es que un numero mal
 * calculado falle en un sitio y solo en uno.
 *
 * ## El contrato se comprueba con Spectator sobre la consulta
 *
 * La descarga devuelve un fichero binario, y `assertValidResponse` espera un
 * cuerpo JSON deserializable: la forma de esa respuesta —los tres tipos de
 * contenido, las cabeceras— la comprueba `tests/Contract/OpenApiContractTest.php`.
 * Es el mismo reparto que hicieron la descarga del informe por periodo y la
 * salida a nomina.
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    // El cuadro es funcionalidad ACCESORIA (ADR-023) y `impact_dashboard` es su
    // PRIMER consumidor. Su degradacion tiene fichero propio,
    // `tests/Feature/Product/LicenseDegradesAccessoriesTest.php`.
    LicenseKeys::grantAll();

    Spectator::using('openapi.yaml');

    // El PDF del cuadro entra en las pruebas de borde con un motor de mentira, como
    // en la descarga del informe por periodo: componer un PDF de verdad arranca un
    // Chromium —unos segundos por caso y una dependencia del contenedor— y lo que
    // aqui se comprueba es el borde. El documento de verdad, con su sello dentro, lo
    // lee `tests/Integration/Reporting/AdoptionReportPdfSealTest.php`.
    FakeReportDocumentRenderer::bind();
});

/**
 * Un hotel con un mes de marzo conocido y un febrero peor.
 *
 * Las cifras estan elegidas para que cada indicador sea comprobable a mano:
 *
 * - **Marzo**: 4 jornadas, 3 completas (la del dia 5 queda con el turno abierto)
 *   -> 75,00 %.
 * - **Febrero**: 2 jornadas, 1 completa -> 50,00 %. Variacion: **+25,00 pp**.
 * - **Fichajes de marzo**: 7 aceptados —6 por tarjeta y 1 por PIN— y 1 rechazado.
 *   Reparto: 85,71 % por QR. Atendidos: 8.
 * - **Un intento fallido** del quiosco en marzo con 1 ocurrencia:
 *   8 / (8 + 1) = 88,89 % de disponibilidad. Muy por debajo del 99,9 % del §1.3,
 *   que es lo que se quiere: asi la prueba afirma tambien que el cuadro **dice
 *   que no se cumple** en lugar de enseñar siempre verde.
 * - **Una incidencia** detectada el 3 y resuelta el 4 a la misma hora: 1.440
 *   minutos, justo el objetivo de 24 h.
 * - **Dos personas**, una con tarjeta entregada y otra sin ella.
 *
 * @return array{token: string, site: int, employee: string, other: string}
 */
function hotelConCuadroDeImpacto(): array
{
    $site = WorkforceFixtures::site('Hotel del cuadro', 'Europe/Madrid');
    $department = WorkforceFixtures::department($site, 'Recepcion');
    $employee = WorkforceFixtures::employee($site, $department, firstName: 'Lucía', lastName: 'Fernández');
    $other = WorkforceFixtures::employee($site, $department, firstName: 'Youssef', lastName: 'Amrani');
    $device = AttendanceFixtures::device($site);

    // Febrero: una cerrada y una abierta. La abierta es de la SEGUNDA persona
    // porque RN-01 no admite dos turnos abiertos del mismo empleado, y en marzo
    // hace falta otro.
    PeriodReportFixtures::workDay($site, $employee, '2026-02-10', '2026-02-10 06:00', '2026-02-10 14:00');
    PeriodReportFixtures::workDay($site, $other, '2026-02-11', '2026-02-11 06:00', null);

    // Marzo: tres jornadas cerradas y una con el turno abierto.
    PeriodReportFixtures::workDay($site, $employee, '2026-03-02', '2026-03-02 06:00', '2026-03-02 14:00');
    PeriodReportFixtures::workDay($site, $employee, '2026-03-03', '2026-03-03 06:00', '2026-03-03 14:00');
    PeriodReportFixtures::workDay($site, $employee, '2026-03-04', '2026-03-04 06:00', '2026-03-04 14:00');
    PeriodReportFixtures::workDay($site, $employee, '2026-03-05', '2026-03-05 06:00', null);

    $employeeId = AttendanceFixtures::employeeIdOf($employee);

    foreach (['02', '03', '04'] as $day) {
        AdoptionFixtures::scan($device['id'], $employeeId, '2026-03-'.$day.' 06:00:00+01', result: 'clock_in');
        AdoptionFixtures::scan($device['id'], $employeeId, '2026-03-'.$day.' 14:00:00+01', result: 'clock_out');
    }

    // Uno por PIN y uno rechazado: el segundo es un intento ATENDIDO y no un
    // fichaje, asi que cuenta en la disponibilidad y no en el reparto.
    AdoptionFixtures::scan($device['id'], $employeeId, '2026-03-05 06:00:00+01', origin: 'pin_kiosk');
    AdoptionFixtures::scan($device['id'], null, '2026-03-05 06:01:00+01', result: 'rejected_unknown');

    // Un intento que el quiosco no pudo cursar: nadie ficho ahi.
    AdoptionFixtures::kioskError('kiosk.camera.unavailable', 1, '2026-03-06 09:00:00+01');

    $rrhh = ManagementUsers::withRole(UserRole::RRHH);
    // La clave interna de la cuenta, para firmar la correccion y la entrega de la
    // tarjeta. `getKey()` y `getAuthIdentifier()` devuelven `mixed` los dos —el
    // identificador de un modelo puede ser cualquier cosa—, asi que se comprueba en
    // vez de castear a ciegas: con la cuenta equivocada, el asiento seria de otra
    // persona.
    $rrhhId = $rrhh->id;

    // Una correccion en marzo: 1 / 7 aceptados = 14,29 %.
    AdoptionFixtures::correction(
        AdoptionFixtures::shiftEntryIdOf($employee, '2026-03-02'),
        $rrhhId,
        '2026-03-10 09:00:00+01',
    );

    // Detectada el 3 a las 07:00 y resuelta el 4 a las 07:00: 1.440 minutos.
    AdoptionFixtures::incident($employeeId, '2026-03-03', '2026-03-03 07:00:00+01', '2026-03-04 07:00:00+01');
    // Y una abierta, que es la foto de hoy.
    AdoptionFixtures::incident($employeeId, '2026-03-09', '2026-03-09 07:00:00+01');

    // Una de las dos personas tiene tarjeta entregada y la otra no: el indicador
    // vale 1, que es la cola pendiente de RRHH.
    AdoptionFixtures::deliveredCredential($employeeId, $rrhhId, '2026-02-01 09:00:00+01');

    return [
        'token' => ManagementUsers::tokenFor($rrhh),
        'site' => $site,
        'employee' => $employee,
        'other' => $other,
    ];
}

/**
 * Los doce indicadores del cuerpo de la respuesta, con el tipo puesto.
 *
 * `TestResponse::json()` devuelve `mixed` —es un JSON arbitrario— y PHPStan 9 no
 * deja recorrerlo sin comprobarlo. Se comprueba **una vez** aqui en lugar de una por
 * prueba, y de paso la comprobacion dice algo: si el cuerpo no trae los indicadores,
 * el fallo es esa frase y no un error de tipos tres lineas mas abajo.
 *
 * @param  TestResponse<Response>  $response
 * @return list<array<string, mixed>>
 */
function indicadoresDelCuadro(TestResponse $response): array
{
    $indicators = $response->json('data.indicators');

    expect($indicators)->toBeArray('El cuadro tiene que traer sus indicadores en `data.indicators`.');

    /** @var list<array<string, mixed>> $indicators */
    return $indicators;
}

/**
 * El reparto por origen del cuerpo de la respuesta, con el tipo puesto.
 *
 * @param  TestResponse<Response>  $response
 * @return list<array<string, mixed>>
 */
function repartoDelCuadro(TestResponse $response): array
{
    $breakdown = $response->json('data.origin_breakdown');

    expect($breakdown)->toBeArray('El cuadro tiene que traer el reparto en `data.origin_breakdown`.');

    /** @var list<array<string, mixed>> $breakdown */
    return $breakdown;
}

/**
 * El payload del asiento de la exportacion, decodificado y con el tipo puesto.
 *
 * **Nombre propio y no el `payloadDelAsiento()` de las pruebas de ausencias**: las
 * funciones que Pest declara en un fichero de prueba son globales, asi que dos con
 * el mismo nombre no pueden convivir — y apoyarse en la del otro fichero haria que
 * estas pruebas fallaran al ejecutarse solas, que es como se ejecutan cuando alguien
 * esta arreglando justo esto.
 *
 * El constructor de consultas devuelve `stdClass|null` con columnas `mixed`, que es
 * lo honesto: PostgreSQL no promete el tipo PHP de un `JSONB`. Se comprueba aqui una
 * vez, y la comprobacion dice algo: si el asiento no existe, el fallo es «no hay
 * asiento» y no un error de tipos tres lineas mas abajo.
 *
 * @return array<string, mixed>
 */
function payloadDelAsientoDelCuadro(mixed $entry): array
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
 * @param  array<string, mixed>  $payload
 * @return list<string>
 */
function clavesOrdenadas(array $payload): array
{
    $keys = array_keys($payload);
    sort($keys);

    return $keys;
}

/**
 * El indicador de una clave dentro de la respuesta.
 *
 * @param  array<int, array<string, mixed>>  $indicators
 * @return array<string, mixed>
 */
function indicadorDelCuadro(array $indicators, string $key): array
{
    foreach ($indicators as $indicator) {
        if (($indicator['key'] ?? null) === $key) {
            return $indicator;
        }
    }

    throw new RuntimeException('El cuadro no trae el indicador «'.$key.'».');
}

it('entrega los doce indicadores del cuadro con su comparacion y sus criterios', function (): void {
    $context = hotelConCuadroDeImpacto();

    $response = Api::as($context['token'])
        ->withHeaders(['Accept-Language' => 'es'])
        ->get('/api/v1/reports/adoption?from=2026-03-01&to=2026-03-31')
        ->assertOk()
        ->assertValidResponse(200);

    $indicators = indicadoresDelCuadro($response);

    expect($indicators)->toHaveCount(12)
        // Periodo anterior: los 31 dias inmediatamente anteriores al 1 de marzo,
        // que terminan el 28 de febrero. Viaja RESUELTO para que el cliente no
        // tenga que restar fechas.
        ->and($response->json('meta.period'))->toBe(['from' => '2026-03-01', 'to' => '2026-03-31', 'days' => 31])
        ->and($response->json('meta.previous_period'))
        ->toBe(['from' => '2026-01-29', 'to' => '2026-02-28', 'days' => 31])
        ->and($response->json('meta.time_zone'))->toBe('Europe/Madrid')
        // Traducidos al idioma de la peticion, no claves sueltas.
        ->and($response->json('meta.criteria.0'))->toContain('registro completo');

    // 3 de 4 jornadas completas en marzo frente a 1 de 2 en febrero: +25 puntos.
    expect(indicadorDelCuadro($indicators, 'workdays_complete_ratio'))
        ->toMatchArray([
            'unit' => 'percent',
            'current' => 75.0,
            'previous' => 50.0,
            'delta' => 25.0,
            'target' => ['comparison' => 'at_least', 'value' => 99],
        ]);

    // 6 de 7 aceptados por tarjeta: 85,71 %. El rechazado no entra.
    expect(indicadorDelCuadro($indicators, 'qr_scans_ratio')['current'])->toBe(85.71);

    // 1.440 minutos, que son las 24 h del objetivo clavadas.
    // El JSON serializa 1440.0 como 1440: el numero no cambia, y comparar el tipo
    // de PHP tras un viaje por JSON seria comprobar el decodificador.
    expect(indicadorDelCuadro($indicators, 'incident_resolution_mean_minutes')['current'])->toEqual(1440);

    // Las dos fotos de hoy: una incidencia abierta y una persona sin tarjeta.
    expect(indicadorDelCuadro($indicators, 'open_incidents')['current'])->toEqual(1)
        ->and(indicadorDelCuadro($indicators, 'employees_without_credential')['current'])->toEqual(1)
        ->and(indicadorDelCuadro($indicators, 'open_incidents')['previous'])->toBeNull();
})->group('RF-IN-08');

it('mide la disponibilidad del acto de fichar contando los intentos que el quiosco no pudo cursar', function (): void {
    /*
     * RNF-D-01. Ocho fichajes atendidos —siete aceptados y uno rechazado por una
     * regla— y un intento que no llego a producir escaneo porque la camara no
     * estaba: 8 / 9 = 88,89 %.
     *
     * La cifra esta muy por debajo del 99,9 % a proposito: asi la prueba afirma
     * tambien que el cuadro **dice que no se cumple** en lugar de enseñar siempre
     * verde, que es el fallo que nadie detecta.
     */
    $context = hotelConCuadroDeImpacto();

    $indicators = indicadoresDelCuadro(Api::as($context['token'])
        ->get('/api/v1/reports/adoption?from=2026-03-01&to=2026-03-31')
        ->assertOk());

    expect(indicadorDelCuadro($indicators, 'clocking_availability_ratio'))
        ->toMatchArray([
            'unit' => 'percent',
            'current' => 88.89,
            'target' => ['comparison' => 'at_least', 'value' => 99.9],
        ]);
})->group('RF-IN-08', 'RNF-D-01');

it('reparte los fichajes por origen con los cuatro origenes y sin ningun identificador', function (): void {
    $context = hotelConCuadroDeImpacto();

    $response = Api::as($context['token'])
        ->get('/api/v1/reports/adoption?from=2026-03-01&to=2026-03-31')
        ->assertOk();

    $breakdown = repartoDelCuadro($response);

    expect($breakdown)->toHaveCount(4)
        ->and(array_column($breakdown, 'origin'))->toBe(['qr_kiosk', 'pin_kiosk', 'manual_admin', 'import'])
        ->and($breakdown[0]['scans'])->toBe(6)
        ->and($breakdown[1]['scans'])->toBe(1);

    // REGLA DURA 21: el cuadro es un agregado y no lleva a nadie dentro. Se
    // comprueba sobre el cuerpo entero y no campo a campo, para que un campo nuevo
    // con un `uuid` dentro no pueda colarse sin que esto se ponga en rojo.
    $body = $response->getContent();

    expect($body)->not->toContain($context['employee'])
        ->and($body)->not->toContain('Fernández')
        ->and($body)->not->toContain('employee_uuid');
})->group('RF-IN-08', 'RS-05');

it('toma el mes natural anterior cuando no se pide periodo, en la zona del centro', function (): void {
    /*
     * El 1 de abril a las 00:30 de Madrid el servidor en UTC sigue en marzo. Si la
     * omision se resolviera con la zona del servidor, el cuadro enseñaria
     * **febrero** justo el dia en que alguien entra a mirar como fue marzo.
     */
    hotelConCuadroDeImpacto();
    FrozenTime::at('2026-03-31 23:30:00');

    $response = Api::as(ManagementUsers::tokenFor(ManagementUsers::withRole(UserRole::ADMIN)))
        ->get('/api/v1/reports/adoption')
        ->assertOk();

    expect($response->json('meta.period'))->toBe(['from' => '2026-03-01', 'to' => '2026-03-31', 'days' => 31]);
})->group('RF-IN-08', 'RN-05');

it('completa el periodo cuando solo llega una de las dos fechas', function (): void {
    // `to` sin `from` empieza el dia 1 de su mes: el caso del enlace copiado a
    // medias, que tiene que dar un periodo con sentido en vez de un `422`.
    $context = hotelConCuadroDeImpacto();

    $response = Api::as($context['token'])
        ->get('/api/v1/reports/adoption?to=2026-03-20')
        ->assertOk();

    expect($response->json('meta.period'))->toBe(['from' => '2026-03-01', 'to' => '2026-03-20', 'days' => 20]);
})->group('RF-IN-08');

it('rechaza con report-too-large un periodo por encima del techo sincrono', function (): void {
    /*
     * Cuatrocientos dias, por encima de los 366 de `DateRange::MAXIMUM_DAYS`.
     *
     * **El `type` importa mas que el codigo**: los dos casos del `422` responden lo
     * mismo desde fuera y hacen falta cosas distintas. `validation-failed` se pinta
     * junto al campo; `report-too-large` es un aviso de la pantalla que dice que
     * hay que acortar. Y este cuadro **no tiene generacion en diferido**, asi que
     * el mensaje no ofrece una salida que no existe.
     */
    $context = hotelConCuadroDeImpacto();

    $response = Api::as($context['token'])
        ->get('/api/v1/reports/adoption?from=2025-01-01&to=2026-03-31')
        ->assertStatus(422);

    expect($response->json('type'))->toBe('urn:kronoqr:problem:report-too-large');
})->group('RF-IN-08', 'RNF-P-05');

it('rechaza como peticion invalida un periodo invertido', function (): void {
    // El otro caso del `422`, y con otro `type`: aqui hay una errata que corregir,
    // no un rango que acortar.
    $context = hotelConCuadroDeImpacto();

    $response = Api::as($context['token'])
        ->get('/api/v1/reports/adoption?from=2026-03-31&to=2026-03-01')
        ->assertStatus(422);

    expect($response->json('type'))->toBe('urn:kronoqr:problem:validation-failed');
})->group('RF-IN-08');

it('no deja asiento de divulgacion al leer el cuadro', function (): void {
    /*
     * DECISION 5 DE LA FICHA. Es la unica lectura de informes del producto que no
     * escribe en `audit_log`, y no por descuido: lo que sale son doce agregados de
     * la instalacion entera, sin un solo identificador (regla dura 21), asi que no
     * hay divulgacion de datos personales que registrar.
     *
     * Un asiento por cada apertura llenaria el trail —cuatro años de retencion
     * (RL-02)— de filas que no describen ninguna divulgacion, y con ello haria mas
     * dificil encontrar las que si.
     */
    $context = hotelConCuadroDeImpacto();
    $before = DB::table('audit_log')->count();

    Api::as($context['token'])
        ->get('/api/v1/reports/adoption?from=2026-03-01&to=2026-03-31')
        ->assertOk();

    expect(DB::table('audit_log')->count())->toBe($before);
})->group('RF-IN-08', 'RS-05');

// --- La descarga -------------------------------------------------------------

it('descarga el cuadro sellado y deja su asiento en audit_log', function (string $format, string $contentType): void {
    /*
     * Y aqui SI hay asiento: lo que se registra no es un acceso a datos de nadie,
     * es que **un documento del sistema ha salido del sistema** (regla dura 6). Ese
     * papel va a una reunion, se adjunta a un correo y se archiva fuera del
     * producto.
     *
     * El asiento se escribe ANTES de entregar el fichero, y por eso el documento se
     * compone entero en memoria: con una respuesta transmitida, un fallo de la
     * escritura llegaria cuando el fichero ya esta en el navegador (ADR-027).
     */
    $context = hotelConCuadroDeImpacto();

    $response = Api::as($context['token'])
        ->get('/api/v1/reports/adoption/export?format='.$format.'&from=2026-03-01&to=2026-03-31')
        ->assertOk();

    expect($response->headers->get('content-type'))->toContain($contentType)
        ->and($response->headers->get('cache-control'))->toContain('no-store')
        ->and($response->headers->get('content-disposition'))
        ->toBe('attachment; filename=kronoqr-adopcion-2026-03-01_2026-03-31.'.$format)
        // Doce indicadores, doce filas de datos.
        ->and($response->headers->get('x-kronoqr-report-rows'))->toBe('12')
        ->and($response->headers->get('x-kronoqr-report-digest'))->toMatch('/^[0-9a-f]{64}$/')
        // Los criterios viajan tambien en la cabecera, en base64 de UTF-8: una
        // cabecera HTTP no admite acentos ni saltos de linea.
        ->and(base64_decode((string) $response->headers->get('x-kronoqr-export-criteria'), true))
        ->toContain('registro completo');

    $entry = DB::table('audit_log')
        ->where('action', AuditAction::AdoptionReportExported->value)
        ->orderByDesc('id')
        ->first();

    $payload = payloadDelAsientoDelCuadro($entry);

    expect($payload['from'])->toBe('2026-03-01')
        ->and($payload['to'])->toBe('2026-03-31')
        ->and($payload['format'])->toBe($format)
        // La huella del asiento es la del CONTENIDO y la misma que la cabecera: es
        // lo que permite confirmar que el papel que alguien tiene delante es el que
        // salio de aqui.
        ->and($payload['sha256'])->toBe($response->headers->get('x-kronoqr-report-digest'))
        ->and($payload['size_bytes'])->toBeGreaterThan(0)
        // REGLA DURA 21: ni un nombre y ni un identificador de persona. Las claves
        // se comparan ORDENADAS porque el asiento las normaliza al escribirlas: lo
        // que se afirma es que no sobra ninguna, no en que orden se guardaron.
        ->and(clavesOrdenadas($payload))->toBe(['format', 'from', 'sha256', 'size_bytes', 'to']);
})->with([
    'CSV' => ['csv', 'text/csv'],
    'hoja de calculo' => ['xlsx', 'spreadsheetml'],
    'PDF' => ['pdf', 'application/pdf'],
])->group('RF-IN-08', 'RS-05');

it('sella los tres formatos del mismo cuadro con la misma huella', function (): void {
    // La huella es del CONTENIDO, no del binario: tres formatos del mismo cuadro son
    // el mismo cuadro, y es lo que permite poner un PDF impreso al lado de la hoja
    // de calculo y afirmar que dicen lo mismo. Un hash de los bytes daria tres
    // cifras distintas y no serviria para comparar nada.
    $context = hotelConCuadroDeImpacto();

    $digests = [];

    foreach (['csv', 'xlsx', 'pdf'] as $format) {
        $digests[$format] = Api::as($context['token'])
            ->get('/api/v1/reports/adoption/export?format='.$format.'&from=2026-03-01&to=2026-03-31')
            ->assertOk()
            ->headers->get('x-kronoqr-report-digest');
    }

    expect($digests['xlsx'])->toBe($digests['csv'])
        ->and($digests['pdf'])->toBe($digests['csv'])
        ->and($digests['csv'])->toMatch('/^[0-9a-f]{64}$/');
})->group('RF-IN-08');

it('compone el PDF del cuadro con las dos tablas, el sello y ninguna referencia a la red', function (): void {
    /*
     * El PDF es el formato mas util de los tres —es el que se adjunta a una
     * renovacion de licencia—, asi que no basta con comprobar que responde `200` con
     * `application/pdf`: lo que importa es lo que lleva el papel.
     *
     * Se afirma sobre el HTML que se le entrego al motor, que es donde se ve que el
     * documento lleva **las dos tablas** —los indicadores y el reparto por origen—,
     * el bloque de criterios y la marca de la instalacion. El pie con el sello va
     * aparte: el motor lo repite en cada pagina y por eso se compone en su propio
     * fragmento, el MISMO que usa el informe por periodo.
     */
    $context = hotelConCuadroDeImpacto();

    $response = Api::as($context['token'])
        ->get('/api/v1/reports/adoption/export?format=pdf&from=2026-03-01&to=2026-03-31')
        ->assertOk();

    $html = FakeReportDocumentRenderer::lastHtml();
    $footer = FakeReportDocumentRenderer::lastFooter();

    expect($html)->toContain('Cuadro de impacto y adopción')
        // Primera tabla: rotulo del indicador y su cifra, en la unidad que le toca.
        ->and($html)->toContain('Jornadas con registro completo')
        ->and($html)->toContain('75,00 %')
        // Las horas en `HH:MM`, nunca en decimal ni en minutos.
        ->and($html)->toContain('24:00')
        // Segunda tabla: el reparto por origen, que es lo que esta vista añade sobre
        // la del informe por periodo.
        ->and($html)->toContain('Reparto de fichajes por origen')
        ->and($html)->toContain('Tarjeta QR en el quiosco')
        // Y los criterios, para que el papel se explique solo dos años despues.
        ->and($html)->toContain('registro completo')
        // Sin ninguna referencia a la red: el producto se instala en servidores sin
        // salida a internet (ADR-016).
        ->and($html)->not->toContain('http://');

    // El sello, en el pie que el motor repite en cada pagina: periodo y huella. Una
    // hoja suelta fotocopiada del monton tiene que seguir diciendo de que cuadro es.
    // La huella del pie es LA MISMA que la de la cabecera HTTP y la de los otros dos
    // formatos: es lo que convierte «este es el cuadro de marzo» en una afirmacion
    // comprobable sin abrir el fichero.
    expect($footer)->toContain('2026-03-01 → 2026-03-31')
        ->and($footer)->toContain((string) $response->headers->get('x-kronoqr-report-digest'));
})->group('RF-IN-08');

it('escribe las horas del fichero en HH:MM y nunca en decimal', function (): void {
    /*
     * `/informe-nuevo`, paso 6: lo que cruza la frontera del producto lo abre una
     * persona. Tres jornadas de ocho horas son **24:00**, que ademas es el caso que
     * delata un formateador que use `H:i` de PHP: aquel daria «00:00» al pasar de
     * las 24 h.
     */
    $context = hotelConCuadroDeImpacto();

    $csv = Api::as($context['token'])
        ->get('/api/v1/reports/adoption/export?format=csv&from=2026-03-01&to=2026-03-31')
        ->assertOk()
        ->getContent();

    expect($csv)->toContain('24:00')
        // Y el bloque de criterios va DENTRO del fichero, no solo en la cabecera:
        // este documento se archiva y se relee sin nadie al lado que lo explique.
        ->and($csv)->toContain('registro completo')
        ->and($csv)->toContain('88,89 %')
        /*
         * LA VARIACION DE UNA DURACION LLEVA SIGNO EN LOS DOS SENTIDOS: marzo trabajo
         * 24:00 frente a las 8:00 de febrero, asi que la columna «Variacion» dice
         * `+16:00`.
         *
         * `ReportedDuration` escribe el `−` de una duracion negativa pero no el `+` de
         * una positiva —en el informe de horas no hace falta—, y aqui si: en una
         * columna rotulada «Variacion», un `16:00` a secas junto a un `-12:30` de la
         * fila de arriba se lee como un valor absoluto, y quien compara dos meses no
         * sabe si el tiempo de resolucion subio o bajo.
         */
        ->and($csv)->toContain('+16:00')
        // Y el de un porcentaje va en PUNTOS PORCENTUALES, no en por ciento: marzo
        // registro el 75 % de sus jornadas completas frente al 50 % de febrero.
        ->and($csv)->toContain('+25,00 pp');
})->group('RF-IN-08');

it('rechaza un formato que no existe sin llegar a consultar nada', function (): void {
    $context = hotelConCuadroDeImpacto();

    Api::as($context['token'])
        ->get('/api/v1/reports/adoption/export?format=json&from=2026-03-01&to=2026-03-31')
        ->assertStatus(422);
})->group('RF-IN-08');
