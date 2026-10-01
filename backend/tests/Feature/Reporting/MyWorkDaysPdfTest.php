<?php

declare(strict_types=1);

use App\Modules\Attendance\Application\Port\WorkDayRepository;
use App\Modules\Attendance\Domain\Event\DailyTotalsRecalculated;
use App\Modules\Attendance\Domain\Model\WorkDay;
use App\Modules\Attendance\Domain\ValueObject\ScanOrigin;
use App\Modules\Attendance\Domain\ValueObject\WorkDate;
use App\Modules\Attendance\Infrastructure\Projection\DailyTotalsProjector;
use App\Modules\Shared\Domain\ValueObject\UserRole;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Spectator\Spectator;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Tests\Support\Database\RefreshDatabase;
use Tests\Support\Factory\ClockingPolicyFactory;
use Tests\Support\Http\Api;
use Tests\Support\Identity\ManagementUsers;
use Tests\Support\Identity\PortalLogins;
use Tests\Support\Product\InstallationLocale;
use Tests\Support\Reporting\FakeReportDocumentRenderer;
use Tests\Support\Time\FrozenTime;
use Tests\Support\Time\Instants;
use Tests\Support\Workforce\WorkforceFixtures;

/*
 * `GET /api/v1/me/export?format=pdf` — el registro propio como PDF sellado
 * (PR19 de la verificacion de la 2.1.0, RF-ID-05, RL-05, RL-03; Anexo B del
 * doc 01: «CSV y PDF»).
 *
 * Pruebas de **borde**: el motor de PDF se sustituye por
 * {@see FakeReportDocumentRenderer}, que guarda el HTML del cuerpo y del pie.
 * Eso permite afirmar **que dice** el documento —el nombre de la persona, sus
 * tramos, el total del periodo, el sello— sin arrancar Chromium. El PDF de verdad
 * lo compone `tests/Integration/Reporting/PersonalRecordPdfSealTest.php`.
 *
 * Lo que solo ocurre por esta puerta y se prueba aqui: que el documento es el
 * del titular del token y de nadie mas, que el nombre del fichero no lleva ni
 * nombre ni codigo, que el rango y el limite son los del CSV y que la ausencia
 * de Chromium degrada el formato y no la descarga.
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Spectator::using('openapi.yaml');
    config()->set('identity.portal.rate_limit_per_minute', 10_000);
    FakeReportDocumentRenderer::bind();
});

/**
 * Centro en Madrid, una empleada con nombre conocido y su portal abierto, otra
 * persona del mismo centro y una cuenta de RRHH para corregir. El reloj se
 * detiene DESPUES de abrir las sesiones, por lo mismo que en `MyWorkDaysTest`.
 *
 * @return array{token: string, site: int, employee: string, other: string, rrhh: string}
 */
function pdfPropioContexto(): array
{
    $site = WorkforceFixtures::site('Hotel del registro en papel');
    $employee = WorkforceFixtures::employee($site, null, 'active', 'Lucía', 'Amrani Ruiz', 'E7K2M9XQ4');
    $other = WorkforceFixtures::employee($site, null, 'active', 'Tercera', 'Persona Ajena', 'E0THER000');

    $token = PortalLogins::open($employee);
    $rrhh = ManagementUsers::tokenFor(ManagementUsers::withRole(UserRole::RRHH));

    FrozenTime::at('2026-03-20 09:00:00');

    return ['token' => $token, 'site' => $site, 'employee' => $employee, 'other' => $other, 'rrhh' => $rrhh];
}

/**
 * Una jornada registrada por el quiosco, con su proyeccion recalculada. Devuelve
 * el UUID del tramo.
 */
function pdfPropioJornada(int $site, string $employee, string $workDate, string $entrada, ?string $salida): string
{
    $jornada = WorkDay::start($employee, $site, WorkDate::fromIsoDate($workDate, Instants::madrid()));
    $tramo = $jornada->clockIn(Str::uuid7()->toString(), Instants::inMadrid($entrada), ScanOrigin::QR_KIOSK);

    if ($salida !== null) {
        $jornada->clockOut(Instants::inMadrid($salida), ScanOrigin::QR_KIOSK, ClockingPolicyFactory::standard());
    }

    app(WorkDayRepository::class)->save($jornada);

    foreach ($jornada->releaseEvents() as $evento) {
        if ($evento instanceof DailyTotalsRecalculated) {
            app(DailyTotalsProjector::class)->handle($evento);
        }
    }

    return $tramo->uuid();
}

/**
 * El cuerpo de una descarga en streaming: `StreamedResponse` no lo expone por
 * `getContent()`, hay que enviarlo y capturar la salida.
 *
 * @param  TestResponse<Response>  $response
 */
function pdfPropioCuerpo(TestResponse $response): string
{
    $base = $response->baseResponse;

    if (! $base instanceof StreamedResponse) {
        return (string) $base->getContent();
    }

    ob_start();
    $base->sendContent();

    return (string) ob_get_clean();
}

it('descarga el registro propio en PDF sellado, con su tipo, su nombre de fichero y su huella', function (): void {
    $contexto = pdfPropioContexto();

    pdfPropioJornada($contexto['site'], $contexto['employee'], '2026-03-14', '2026-03-14 06:00', '2026-03-14 14:00');

    $respuesta = Api::as($contexto['token'])
        ->get('/api/v1/me/export', ['from' => '2026-03-01', 'to' => '2026-03-31', 'format' => 'pdf'])
        ->assertValidRequest()
        ->assertValidResponse(200);

    $respuesta->assertHeader('Content-Type', 'application/pdf')
        ->assertHeader('Cache-Control', 'no-store, private')
        // Ni nombre ni codigo de empleado en el nombre del fichero (regla dura
        // 21): el mismo patron que el CSV, con otra extension.
        ->assertHeader('Content-Disposition', 'attachment; filename=mi-registro-horario-2026-03-01_2026-03-31.pdf');

    $huella = (string) $respuesta->headers->get('X-Kronoqr-Report-Digest');

    expect($huella)->toMatch('/^[0-9a-f]{64}$/')
        ->and(pdfPropioCuerpo($respuesta))->toBe(FakeReportDocumentRenderer::BYTES)
        // El pie que el motor repite en cada pagina lleva la MISMA huella que la
        // cabecera: es lo que permite comprobar un papel contra la descarga.
        ->and(FakeReportDocumentRenderer::lastFooter())->toContain($huella)
        ->and((string) $respuesta->headers->get('Content-Disposition'))->not->toContain('Amrani')
        ->and((string) $respuesta->headers->get('Content-Disposition'))->not->toContain('E7K2M9XQ4');
})->group('RF-ID-05', 'RL-05', 'RL-03');

it('lleva el nombre de la persona, sus tramos, el total de cada dia y el total del periodo', function (): void {
    $contexto = pdfPropioContexto();

    pdfPropioJornada($contexto['site'], $contexto['employee'], '2026-03-14', '2026-03-14 06:00', '2026-03-14 14:00');
    pdfPropioJornada($contexto['site'], $contexto['employee'], '2026-03-16', '2026-03-16 07:00', '2026-03-16 15:30');

    Api::as($contexto['token'])
        ->get('/api/v1/me/export', ['from' => '2026-03-01', 'to' => '2026-03-31', 'format' => 'pdf'])
        ->assertOk();

    $html = FakeReportDocumentRenderer::lastHtml();

    // Es su propio registro: su nombre va en el cuerpo.
    expect($html)->toContain('Lucía Amrani Ruiz');
    // Los tramos en la hora del centro, en `HH:MM` y nunca decimal.
    expect($html)->toContain('2026-03-14 06:00');
    expect($html)->toContain('2026-03-14 14:00');
    expect($html)->toContain('2026-03-16 15:30');
    expect($html)->toContain('08:00');
    expect($html)->toContain('08:30');
    // 8:00 + 8:30 = 16:30, escrito como numero y no calculado por la prueba.
    expect($html)->toContain('Total del periodo');
    expect($html)->toContain('16:30');
    expect($html)->not->toContain('16,5');
    // Los criterios del documento, que lo explican solo dos años despues.
    expect($html)->toContain('No se parte a medianoche');
    // El titulo va a los metadatos del PDF: sin nombres.
    expect($html)->toMatch('/<title>Mi registro horario<\/title>/');
    // Ninguna referencia a la red (ADR-016).
    expect($html)->not->toContain('http://');
    expect($html)->not->toContain('https://');
})->group('RF-ID-05', 'RL-05', 'RN-06');

it('sella cada pagina con la fecha local, la persona que lo descarga, el periodo y la huella', function (): void {
    $contexto = pdfPropioContexto();

    pdfPropioJornada($contexto['site'], $contexto['employee'], '2026-03-14', '2026-03-14 06:00', '2026-03-14 14:00');

    Api::as($contexto['token'])
        ->get('/api/v1/me/export', ['from' => '2026-03-01', 'to' => '2026-03-31', 'format' => 'pdf'])
        ->assertOk();

    $pie = FakeReportDocumentRenderer::lastFooter();

    // El reloj esta detenido a las 09:00 UTC, que en Madrid son las 10:00: el
    // sello va en la hora del centro (regla dura 3, ADR-040).
    expect($pie)->toContain('2026-03-20 10:00');
    expect($pie)->toContain('Europe/Madrid');
    expect($pie)->toContain('Descargado por');
    expect($pie)->toContain('Lucía Amrani Ruiz');
    expect($pie)->toContain('2026-03-01');
    expect($pie)->toContain('2026-03-31');
})->group('RF-ID-05', 'RL-05');

it('incluye cada correccion con su autor y su motivo, y la jornada que se quedo sin tramos', function (): void {
    // RN-13 y regla dura 5: el papel que una persona presenta no puede esconder
    // que alguien cambio sus horas, ni el dia cuyo tramo se anulo.
    $contexto = pdfPropioContexto();

    // El anulado va ANTES que el abierto: un turno abierto cubre todo lo que
    // viene detras (RN-02), y no se podria registrar otro despues.
    $anulado = pdfPropioJornada($contexto['site'], $contexto['employee'], '2026-03-14', '2026-03-14 07:00', '2026-03-14 15:00');
    $abierto = pdfPropioJornada($contexto['site'], $contexto['employee'], '2026-03-16', '2026-03-16 06:00', null);

    Api::as($contexto['rrhh'])
        ->patch('/api/v1/shift-entries/'.$abierto, [
            'clocked_out_at' => '2026-03-16T14:00:00Z',
            'reason_code' => 'OLVIDO_FICHAJE_SALIDA',
        ])
        ->assertStatus(200);

    Api::as($contexto['rrhh'])
        ->post('/api/v1/shift-entries/'.$anulado.'/void', ['reason_code' => 'ERROR_DE_ESCANEO_DUPLICADO'])
        ->assertStatus(200);

    Api::as($contexto['token'])
        ->get('/api/v1/me/export', ['from' => '2026-03-01', 'to' => '2026-03-31', 'format' => 'pdf'])
        ->assertOk();

    $html = FakeReportDocumentRenderer::lastHtml();

    expect($html)->toContain('Corrección');
    expect($html)->toContain('OLVIDO_FICHAJE_SALIDA');
    expect($html)->toContain('ERROR_DE_ESCANEO_DUPLICADO');
    expect($html)->toContain('Sin tramos registrados');
    // 06:00 → 15:00 de Madrid tras la correccion son nueve horas, y el tramo
    // anulado ya no suma: el total del periodo es 09:00.
    expect($html)->toContain('09:00');
})->group('RF-ID-05', 'RN-13', 'RL-04');

it('no mete a nadie mas en el PDF del registro propio', function (): void {
    // El alcance del documento es el del token. Un `JOIN` de mas en la consulta
    // se veria aqui antes que en el papel.
    $contexto = pdfPropioContexto();

    pdfPropioJornada($contexto['site'], $contexto['employee'], '2026-03-14', '2026-03-14 06:00', '2026-03-14 14:00');
    pdfPropioJornada($contexto['site'], $contexto['other'], '2026-03-14', '2026-03-14 07:15', '2026-03-14 15:45');

    Api::as($contexto['token'])
        ->get('/api/v1/me/export', ['from' => '2026-03-14', 'to' => '2026-03-14', 'format' => 'pdf'])
        ->assertOk();

    $documento = FakeReportDocumentRenderer::lastHtml().FakeReportDocumentRenderer::lastFooter();

    expect($documento)->not->toContain('Persona Ajena');
    expect($documento)->not->toContain($contexto['other']);
    expect($documento)->not->toContain('07:15');
    expect($documento)->not->toContain('15:45');
})->group('RF-ID-07', 'RL-05');

it('no compone el PDF de otra persona aunque se señale en la URL', function (string $parametro): void {
    // No hay `{uuid}` en la ruta, y un parametro que intente señalar a otra
    // persona se rechaza como campo desconocido (`ValidatesWorkDateRange`)
    // ANTES de componer nada: el motor no llega a recibir ningun documento.
    $contexto = pdfPropioContexto();

    pdfPropioJornada($contexto['site'], $contexto['other'], '2026-03-14', '2026-03-14 07:15', '2026-03-14 15:45');

    Api::as($contexto['token'])
        ->get('/api/v1/me/export?format=pdf&from=2026-03-14&to=2026-03-14&'.$parametro.'='.$contexto['other'])
        ->assertStatus(422)
        ->assertJsonStructure(['errors' => [$parametro]]);

    expect(static fn (): string => FakeReportDocumentRenderer::lastHtml())->toThrow(RuntimeException::class);
})->with(['employee_uuid', 'uuid', 'employee'])->group('RF-ID-07', 'RL-05');

it('sale en el idioma de la instalacion', function (): void {
    // Un documento sale en el idioma de la instalacion, no en el del navegador
    // (UseInstallationLocale, regla dura 13).
    // Antes de abrir el portal: los ajustes se memorizan durante la prueba, y el
    // acceso de la preparacion ya los habria leido en castellano.
    InstallationLocale::set('en');
    $contexto = pdfPropioContexto();

    pdfPropioJornada($contexto['site'], $contexto['employee'], '2026-03-14', '2026-03-14 06:00', '2026-03-14 14:00');

    Api::as($contexto['token'])
        ->withHeaders(['Accept-Language' => 'es'])
        ->get('/api/v1/me/export', ['from' => '2026-03-01', 'to' => '2026-03-31', 'format' => 'pdf'])
        ->assertOk();

    expect(FakeReportDocumentRenderer::lastHtml())->toContain('Period total')
        ->and(FakeReportDocumentRenderer::lastHtml())->toMatch('/<title>My time record<\/title>/')
        ->and(FakeReportDocumentRenderer::lastFooter())->toContain('Downloaded by');
})->group('RF-ID-05', 'RF-PD-08');

it('aplica al PDF el mismo techo de rango que al CSV', function (array $rango): void {
    $contexto = pdfPropioContexto();

    Api::as($contexto['token'])
        ->get('/api/v1/me/export', [...$rango, 'format' => 'pdf'])
        ->assertValidResponse(422)
        ->assertJsonPath('type', 'urn:kronoqr:problem:validation-failed');
})->with([
    'invertido' => [['from' => '2026-03-31', 'to' => '2026-03-01']],
    'mas ancho que el techo' => [['from' => '2024-01-01', 'to' => '2026-03-01']],
])->group('RF-ID-05', 'RQ-06');

it('aplica al PDF la misma zona de limite que al CSV', function (): void {
    $contexto = pdfPropioContexto();

    // El acceso de la preparacion ya gasto el cupo de esta IP.
    config()->set('identity.portal.rate_limit_per_minute', 1);

    Api::as($contexto['token'])
        ->get('/api/v1/me/export', ['format' => 'pdf'])
        ->assertStatus(429);
})->group('RF-ID-05', 'RS-12');

it('responde 503 con problem+json sin Chromium, y el CSV sigue saliendo', function (): void {
    // La ausencia del motor degrada UN FORMATO, no la descarga del registro
    // legal (regla dura 15 por analogia, ADR-023).
    $contexto = pdfPropioContexto();

    FakeReportDocumentRenderer::bindFailing();

    Api::as($contexto['token'])
        ->get('/api/v1/me/export', ['format' => 'pdf'])
        ->assertValidResponse(503)
        ->assertJsonPath('type', 'urn:kronoqr:problem:service-unavailable');

    Api::as($contexto['token'])
        ->get('/api/v1/me/export', ['format' => 'csv'])
        ->assertOk();
})->group('RF-ID-05', 'RL-03');

it('sigue rechazando los formatos que no son CSV ni PDF', function (): void {
    $contexto = pdfPropioContexto();

    Api::as($contexto['token'])
        ->get('/api/v1/me/export', ['format' => 'xlsx'])
        ->assertStatus(422)
        ->assertJsonStructure(['errors' => ['format']]);
})->group('RF-ID-05', 'RQ-06');

it('deniega el PDF a cada rol de gestion', function (string $role): void {
    // Regla dura 18: `/me/*` es exclusivo de la sesion de portal. El registro de
    // otra persona se consulta por la ruta de gestion, que queda auditada.
    pdfPropioContexto();

    $token = ManagementUsers::tokenFor(ManagementUsers::withRole(UserRole::from($role)));

    Api::as($token)
        ->get('/api/v1/me/export', ['format' => 'pdf'])
        ->assertValidResponse(403);
})->with([
    'admin' => UserRole::ADMIN->value,
    'rrhh' => UserRole::RRHH->value,
    'auditor' => UserRole::AUDITOR->value,
    'responsable de departamento' => UserRole::RESPONSABLE_DEPARTAMENTO->value,
    'empleado' => UserRole::EMPLEADO->value,
])->group('RF-ID-07', 'RQ-07');

it('deniega el PDF a un quiosco y sin token', function (): void {
    pdfPropioContexto();

    Api::as(ManagementUsers::kioskToken())
        ->get('/api/v1/me/export', ['format' => 'pdf'])
        ->assertStatus(403);

    Api::guest()
        ->get('/api/v1/me/export', ['format' => 'pdf'])
        ->assertValidResponse(401)
        ->assertJsonPath('type', 'urn:kronoqr:problem:unauthenticated');
})->group('RF-ID-07', 'RQ-07', 'RS-04');

it('no escribe asiento de divulgacion por descargar el PDF propio', function (): void {
    // RS-05 registra el acceso a datos de terceros, y aqui no hay tercero.
    $contexto = pdfPropioContexto();

    Api::as($contexto['token'])->get('/api/v1/me/export', ['format' => 'pdf'])->assertOk();

    expect(DB::table('audit_log')->where('action', 'personal_data.accessed')->count())->toBe(0);
})->group('RS-05', 'RF-ID-05');
