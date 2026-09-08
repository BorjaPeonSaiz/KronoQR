<?php

declare(strict_types=1);

use App\Modules\Attendance\Application\Port\WorkDayRepository;
use App\Modules\Attendance\Domain\Model\WorkDay;
use App\Modules\Attendance\Domain\ValueObject\ScanOrigin;
use App\Modules\Attendance\Domain\ValueObject\WorkDate;
use App\Modules\Product\Application\Command\ActivateLicenseCommand;
use App\Modules\Product\Application\UseCase\ActivateLicenseHandler;
use App\Modules\Product\Infrastructure\Adapter\DbBrandingProvider;
use App\Modules\Shared\Application\Port\BrandingProvider;
use App\Modules\Shared\Application\Port\FeatureGate;
use App\Modules\Shared\Domain\ValueObject\Branding;
use App\Modules\Shared\Domain\ValueObject\UserRole;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Spectator\Spectator;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Tests\Support\Database\RefreshDatabase;
use Tests\Support\Factory\ClockingPolicyFactory;
use Tests\Support\Http\Api;
use Tests\Support\Identity\ManagementUsers;
use Tests\Support\Product\FixedBranding;
use Tests\Support\Product\LicenseKeys;
use Tests\Support\Time\Instants;
use Tests\Support\Workforce\WorkforceFixtures;

/*
 * `GET /api/v1/reports/legal-export` extremo a extremo y contra el contrato
 * (RF-IN-05, RL-03, RL-06, tarea 1.17).
 *
 * Lo que estas pruebas cubren y las de integracion no es el **borde**: el codigo
 * de estado, las cabeceras de la descarga, la validacion de los parametros y que
 * el cuerpo sea de verdad un `text/csv` y no un JSON.
 *
 * **El contrato se comprueba con Spectator sobre la peticion.** La respuesta es
 * un fichero binario servido con `BinaryFileResponse`: `assertValidResponse` de
 * Spectator espera un cuerpo JSON deserializable, asi que aqui se afirma
 * directamente lo que el contrato declara —tipo de contenido, `Content-Disposition`,
 * `Cache-Control` y las dos cabeceras de recuento— y la forma del endpoint la
 * comprueba `tests/Contract/OpenApiContractTest.php`. Un `assertValidResponse`
 * que no pudiera leer el cuerpo no comprobaria nada y lo pareceria.
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Spectator::using('openapi.yaml');

    // El idioma del documento es configuracion de la instalacion (regla dura 13).
    App::setLocale('es');
});

/**
 * Centro, persona con una jornada registrada y sesion de RRHH, que es el rol que
 * atiende materialmente un requerimiento en un hotel.
 *
 * @return array{token: string, site: int, employee: string}
 */
function contextoDeDescargaLegal(): array
{
    $site = WorkforceFixtures::site('Hotel de descargas', 'Europe/Madrid');
    $employee = WorkforceFixtures::employee($site);

    $workDay = WorkDay::start($employee, $site, WorkDate::fromIsoDate('2026-03-14', Instants::madrid()));
    $workDay->clockIn(Str::uuid7()->toString(), Instants::utc('2026-03-14 06:00'), ScanOrigin::QR_KIOSK);
    $workDay->clockOut(Instants::utc('2026-03-14 14:00'), ScanOrigin::QR_KIOSK, ClockingPolicyFactory::standard());
    app(WorkDayRepository::class)->save($workDay);

    return [
        'token' => ManagementUsers::tokenFor(ManagementUsers::withRole(UserRole::RRHH)),
        'site' => $site,
        'employee' => $employee,
    ];
}

/**
 * @param  TestResponse<Response>  $response
 */
function cuerpoDescargado(TestResponse $response): string
{
    $base = $response->baseResponse;

    // `BinaryFileResponse` no expone el cuerpo por `getContent()`: hay que
    // enviarlo. `StreamedResponse` tampoco, y por eso se cubren los dos.
    if ($base instanceof StreamedResponse || $base instanceof BinaryFileResponse) {
        ob_start();
        $base->sendContent();

        return (string) ob_get_clean();
    }

    return (string) $base->getContent();
}

it('descarga el registro horario del periodo como CSV', function (): void {
    $contexto = contextoDeDescargaLegal();

    $respuesta = Api::as($contexto['token'])
        ->get('/api/v1/reports/legal-export', ['from' => '2026-03-01', 'to' => '2026-03-31'])
        ->assertValidRequest()
        ->assertOk();

    $respuesta
        // RL-06: tabular, legible y tratable. No JSON.
        ->assertHeader('content-type', 'text/csv; charset=utf-8')
        // El nombre del adjunto no lleva el nombre de ninguna persona (regla
        // dura 21): un «registro-Lucia-Fernandez.csv» divulga a quien se esta
        // inspeccionando con solo mirar la bandeja de entrada.
        ->assertHeader(
            'content-disposition',
            'attachment; filename=registro-horario-2026-03-01_2026-03-31.csv',
        )
        // El cuerpo es una lista nominal de la plantilla: ni un proxy ni un
        // navegador pueden guardarla.
        ->assertHeader('cache-control', 'no-store, private')
        // Las mismas cifras que afirma el asiento de `audit_log`: permiten
        // comprobar que la descarga llego entera sin abrir el fichero.
        ->assertHeader('x-kronoqr-export-shift-rows', '1')
        ->assertHeader('x-kronoqr-export-correction-rows', '0');

    $csv = cuerpoDescargado($respuesta);

    expect(str_starts_with($csv, "\xEF\xBB\xBF"))->toBeTrue('El CSV descargado no lleva BOM.')
        ->and($csv)->toContain('Criterios de inclusion')
        ->and($csv)->toContain('TRAMO;')
        ->and($csv)->toContain('08:00')
        ->and($csv)->toContain('2026-03-14 07:00')
        // La cabecera declara de QUE instalacion es el documento (RF-PD-08). Sale
        // de `installation_settings` desde la tarea 5.8, asi que dice lo mismo que
        // el cliente ve en su panel; sin configurar es el nombre del producto, y
        // nunca esta en blanco — un documento legal cuya primera linea dice
        // «Instalacion:» y nada mas no le sirve a nadie dos años despues.
        ->and($csv)->toContain('Instalacion;KronoQR');
})->group('RF-IN-05', 'RL-06', 'RL-03', 'RF-PD-08');

it('encabeza el documento de la Inspeccion con el nombre que el cliente configuro', function (): void {
    // Y sigue saliendo aunque la licencia no cubra la marca blanca: lo que se
    // degrada es el ROTULO, nunca el contenido. La exportacion para la Inspeccion
    // es registro legal y no se apaga jamas (regla dura 15, ADR-019).
    $contexto = contextoDeDescargaLegal();

    app()->instance(BrandingProvider::class, new FixedBranding(new Branding(
        applicationName: 'Hotel Marina',
        logoPath: null,
        accentColor: '#0f5c8c',
    )));

    $csv = cuerpoDescargado(
        Api::as($contexto['token'])
            ->get('/api/v1/reports/legal-export', ['from' => '2026-03-01', 'to' => '2026-03-31'])
            ->assertOk(),
    );

    // Entrecomillado porque lleva un espacio: es el RFC 4180 que aplica
    // `CsvDialect`, no un detalle de la marca.
    expect($csv)->toContain('Instalacion;"Hotel Marina"');
})->group('RF-IN-05', 'RL-06', 'RF-PD-08');

it('acota la descarga a una sola persona cuando se le pide', function (): void {
    $contexto = contextoDeDescargaLegal();
    $otra = WorkforceFixtures::employee($contexto['site']);

    $workDay = WorkDay::start($otra, $contexto['site'], WorkDate::fromIsoDate('2026-03-14', Instants::madrid()));
    $workDay->clockIn(Str::uuid7()->toString(), Instants::utc('2026-03-14 06:00'), ScanOrigin::QR_KIOSK);
    $workDay->clockOut(Instants::utc('2026-03-14 14:00'), ScanOrigin::QR_KIOSK, ClockingPolicyFactory::standard());
    app(WorkDayRepository::class)->save($workDay);

    $respuesta = Api::as($contexto['token'])
        ->get('/api/v1/reports/legal-export', [
            'from' => '2026-03-14',
            'to' => '2026-03-14',
            'employee_uuid' => $contexto['employee'],
        ])
        ->assertValidRequest()
        ->assertOk()
        ->assertHeader('x-kronoqr-export-shift-rows', '1');

    $csv = cuerpoDescargado($respuesta);

    expect($csv)->toContain($contexto['employee'])
        ->and($csv)->not->toContain($otra);
})->group('RF-IN-05', 'RS-05');

it('deja el asiento de auditoria de la descarga', function (): void {
    // Regla dura 6: descargar el registro horario de la plantilla no puede ser
    // anonimo. Y aqui, al contrario que en el comando de consola, hay una sesion
    // detras: el actor es la cuenta que llamo.
    $contexto = contextoDeDescargaLegal();

    Api::as($contexto['token'])
        ->get('/api/v1/reports/legal-export', ['from' => '2026-03-01', 'to' => '2026-03-31'])
        ->assertOk();

    /** @var object{actor_type: string, actor_id: int|null, payload: string}|null $asiento */
    $asiento = DB::table('audit_log')->where('action', 'legal_export.generated')->orderByDesc('id')->first();

    expect($asiento)->not->toBeNull()
        ->and($asiento?->actor_type)->toBe('user')
        ->and($asiento?->actor_id)->not->toBeNull();
})->group('RS-05', 'RL-04', 'RF-IN-05');

it('rechaza un periodo mal formado o invertido', function (array $query, string $campo): void {
    $contexto = contextoDeDescargaLegal();

    Api::as($contexto['token'])
        ->get('/api/v1/reports/legal-export', $query)
        ->assertStatus(422)
        ->assertJsonPath('type', 'urn:kronoqr:problem:validation-failed')
        ->assertJsonStructure(['errors' => [$campo]]);
})->with([
    'sin periodo' => [[], 'from'],
    'sin fecha final' => [['from' => '2026-01-01'], 'to'],
    'con hora, que lo convertiria en un instante' => [['from' => '2026-01-01T00:00:00Z', 'to' => '2026-01-31'], 'from'],
    // Un periodo invertido no se «arregla» dando la vuelta a las fechas: el
    // fichero que acabaria en un expediente llevaria un periodo que nadie pidio.
    'invertido' => [['from' => '2026-01-31', 'to' => '2026-01-01'], 'to'],
    'con un identificador que no es un UUID' => [
        ['from' => '2026-01-01', 'to' => '2026-01-31', 'employee_uuid' => 'lucia'],
        'employee_uuid',
    ],
])->group('RF-IN-05', 'RQ-06');

it('no ignora en silencio un filtro que no existe', function (): void {
    // Un `?site_id=3` descartado sin decir nada dejaria a quien atiende el
    // requerimiento convencido de haber acotado la exportacion, habiendo
    // entregado la plantilla entera.
    $contexto = contextoDeDescargaLegal();

    Api::as($contexto['token'])
        ->get('/api/v1/reports/legal-export', [
            'from' => '2026-03-01',
            'to' => '2026-03-31',
            'site_id' => '3',
        ])
        ->assertStatus(422)
        ->assertJsonStructure(['errors' => ['site_id']]);
})->group('RF-IN-05', 'RQ-06');

it('encabeza el documento con el nombre del cliente aunque su plan no cubra la marca blanca', function (): void {
    // ESTA ES LA RAZON DE QUE EL NOMBRE NO SE DEGRADE (ADR-023, regla dura 15,
    // RL-06). Esta linea dice DE QUIEN es el registro horario que un inspector
    // tiene delante. Si dependiera del estado comercial de la licencia, dos
    // exportaciones del mismo mes podrian salir con encabezados distintos sin que
    // hubiera cambiado ningun dato — y un fallo transitorio de verificacion
    // (`LicenseUnverifiable`, es decir, un Redis caido) bastaria para provocarlo.
    //
    // POR LA CADENA REAL, no con un doble: lo que se comprueba es que el
    // decorador de licencia deja pasar el nombre.
    $contexto = contextoDeDescargaLegal();

    LicenseKeys::install();

    // Una licencia vigente cuyo plan NO incluye `white_label`.
    app(ActivateLicenseHandler::class)->handle(new ActivateLicenseCommand(
        LicenseKeys::current()->issue(['features' => ['advanced_reports']]),
    ));

    foreach ([FeatureGate::class, BrandingProvider::class, DbBrandingProvider::class] as $abstract) {
        app()->forgetInstance($abstract);
    }

    DB::table('installation_settings')->updateOrInsert(
        ['key' => 'BRANDING_APP_NAME'],
        ['value' => '"Hotel Marina"', 'updated_at' => '2026-01-01 00:00:00+00'],
    );

    $csv = cuerpoDescargado(
        Api::as($contexto['token'])
            ->get('/api/v1/reports/legal-export', ['from' => '2026-03-01', 'to' => '2026-03-31'])
            ->assertOk(),
    );

    expect($csv)->toContain('Instalacion;"Hotel Marina"')
        ->and($csv)->not->toContain('Instalacion;KronoQR');
})->group('RF-IN-05', 'RL-06', 'RF-PD-08', 'RF-PD-05');
