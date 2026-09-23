<?php

declare(strict_types=1);

use App\Modules\Identity\Infrastructure\Persistence\User;
use App\Modules\Reporting\Application\Port\PayrollDocumentWriter;
use App\Modules\Reporting\Application\Port\ReportExportNotifier;
use App\Modules\Reporting\Application\UseCase\GenerateReportExportHandler;
use App\Modules\Reporting\Application\UseCase\PurgeExpiredReportExports;
use App\Modules\Reporting\Domain\Exception\ReportExportWriteFailed;
use App\Modules\Reporting\Domain\ValueObject\ReportExportKind;
use App\Modules\Reporting\Infrastructure\Job\GenerateReportExportJob;
use App\Modules\Reporting\Infrastructure\Notification\ReportExportReadyNotification;
use App\Modules\Shared\Application\Port\Clock;
use App\Modules\Shared\Domain\ValueObject\UserRole;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Spectator\Spectator;
use Tests\Support\Database\RefreshDatabase;
use Tests\Support\Http\Api;
use Tests\Support\Identity\ManagementUsers;
use Tests\Support\Product\LicenseKeys;
use Tests\Support\Reporting\ExplodingReportExportNotifier;
use Tests\Support\Reporting\ReportExports;
use Tests\Support\Reporting\UnavailablePayrollDocumentWriter;
use Tests\Support\Workforce\WorkforceFixtures;

/*
 * Las cuatro rutas de los informes en diferido (**RF-IN-06**, ADR-041, RS-05).
 *
 * LO QUE SE COMPRUEBA AQUI es el mecanismo entero del compromiso comercial del
 * doc 05 §5.4 —«se generan en segundo plano y se avisa con un enlace de descarga
 * cuando estan listos»—: que se pide, que se ve el estado, que el enlace llega,
 * que **solo sirve una vez** y que caduca.
 *
 * Y los desenlaces que no son «toma tu fichero», que son los que de verdad
 * distinguen un enlace caducable de una URL adivinable: el enlace gastado, el
 * caducado, el ajeno y el de una exportacion purgada. Cada uno le cambia la
 * accion a quien lo recibe, y por eso el contrato les da codigos distintos.
 *
 * Las respuestas se validan contra `openapi.yaml` con Spectator: el contrato es
 * la fuente de verdad (ADR-013), y una respuesta que no lo cumple rompe el
 * cliente TypeScript generado de el.
 *
 * LA COLA SE FALSEA EN CASI TODAS. La suite corre con `QUEUE_CONNECTION=sync`,
 * asi que sin `Queue::fake()` cada `POST` cruzaria la plantilla con el calendario
 * **dentro de la peticion**. Con la cola falseada la fila se queda en `pending`,
 * que es exactamente lo que el `202` promete. La generacion de verdad se prueba
 * llamando al caso de uso, y con volumen en `ReportExportVolumeTest`.
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Spectator::using('openapi.yaml');
    WorkforceFixtures::site();
    LicenseKeys::install();
    LicenseKeys::grantAll();

    ReportExports::useTemporaryPath();
});

afterEach(function (): void {
    ReportExports::cleanUpTemporaryPath();
});

/** Una cuenta que puede pedir informes: `rrhh`, como el informe sincrono. */
function reportExportRequester(): User
{
    return ManagementUsers::withRole(UserRole::RRHH);
}

/**
 * El enlace de descarga que devolvio la API, comprobando de paso que hay uno.
 *
 * Existe porque `json()` devuelve `mixed` y PHPStan 9 no admite convertirlo a
 * texto a ciegas — y porque la conversion silenciosa es peor que el error: un
 * `download` nulo se volveria cadena vacia y la prueba pediria `/download?` sin
 * token, que responde `404` y pareceria un fallo de autorizacion.
 */
function enlaceDeDescarga(mixed $url): string
{
    expect($url)->toBeString('La respuesta no trae enlace de descarga.');

    return \is_string($url) ? $url : '';
}

/**
 * El cuerpo minimo de una peticion valida.
 *
 * @return array<string, mixed>
 */
function reportExportBody(string $kind = 'period', string $format = 'csv'): array
{
    return [
        'kind' => $kind,
        'format' => $format,
        'from' => '2026-03-01',
        'to' => '2026-03-31',
        'granularity' => 'day',
        'group_by' => 'employee',
    ];
}

it('acepta la peticion con 202, la deja en pending y la audita', function (): void {
    Queue::fake();

    $response = Api::as(ManagementUsers::tokenFor(reportExportRequester()))
        ->post('/api/v1/reports/exports', reportExportBody())
        ->assertValidResponse(202);

    expect($response->json('data.status'))->toBe('pending')
        ->and($response->json('data.kind'))->toBe('period')
        ->and($response->json('data.format'))->toBe('csv')
        ->and($response->json('data.scope'))->toBe('all')
        ->and($response->json('data.parameters.from'))->toBe('2026-03-01')
        ->and($response->json('data.file_name'))->toBeNull()
        // La lista de criterios nace VACIA y no nula: describen el informe que se
        // genero —cuantos festivos tenia el periodo— y eso no se sabe hasta
        // ejecutarlo.
        ->and($response->json('data.criteria'))->toBe([])
        // El `202` NUNCA trae enlace: lo emite la consulta del estado (ADR-041).
        ->and($response->json('data.download'))->toBeNull();

    // La INTENCION queda auditada antes de que exista ningun fichero (RS-05):
    // un intento que revienta al minuto tiene que dejar rastro igual.
    expect(DB::table('audit_log')->where('action', 'report_export.requested')->count())->toBe(1);

    Queue::assertPushed(GenerateReportExportJob::class);
})->group('RF-IN-06', 'RS-05');

it('rechaza con 409 un segundo informe de la misma persona', function (): void {
    // Una en curso POR PERSONA (decision 2). Lo garantiza el indice unico parcial
    // y no un `SELECT` previo: dos pestañas pulsando a la vez pasarian cualquier
    // comprobacion en PHP.
    Queue::fake();

    $token = ManagementUsers::tokenFor(reportExportRequester());

    Api::as($token)->post('/api/v1/reports/exports', reportExportBody())->assertStatus(202);

    $response = Api::as($token)
        ->post('/api/v1/reports/exports', reportExportBody())
        ->assertValidResponse(409);

    expect($response->json('type'))->toBe('urn:kronoqr:problem:report-export-in-progress')
        // La fila que ocupa el turno viaja dentro para que la pantalla la enseñe
        // en lugar de invitar a pedir otro.
        ->and($response->json('export.status'))->toBe('pending');
})->group('RF-IN-06');

it('dos personas distintas pueden tener un informe en curso a la vez', function (): void {
    // La diferencia deliberada con la exportacion integra, donde el limite es de
    // la instalacion: que RRHH este generando el cierre de mes no puede impedirle
    // a otra cuenta pedir el suyo.
    Queue::fake();

    $rrhh = ManagementUsers::tokenFor(ManagementUsers::withRole(UserRole::RRHH));
    $admin = ManagementUsers::tokenFor(ManagementUsers::withRole(UserRole::ADMIN));

    Api::as($rrhh)->post('/api/v1/reports/exports', reportExportBody())->assertStatus(202);
    Api::as($admin)->post('/api/v1/reports/exports', reportExportBody())->assertStatus(202);
})->group('RF-IN-06');

it('la lista solo trae las del solicitante y nunca lleva enlaces', function (): void {
    $mias = reportExportRequester();
    $ajenas = ManagementUsers::withRole(UserRole::ADMIN);

    ReportExports::completedFor($mias->id);
    ReportExports::completedFor($ajenas->id);

    $response = Api::as(ManagementUsers::tokenFor($mias))
        ->get('/api/v1/reports/exports')
        ->assertValidResponse(200);

    expect($response->json('data'))->toHaveCount(1)
        ->and($response->json('data.0.status'))->toBe('completed')
        // Si la lista acuñara enlaces, un sondeo cada diez segundos dejaria veinte
        // vivos y mataria el que alguien estuviera usando.
        ->and($response->json('data.0.download'))->toBeNull();
})->group('RF-IN-06', 'RF-ID-03');

it('la exportacion de otra persona responde 404 y no 403', function (): void {
    // `403` confirmaria que existe. Decision 2 de la ficha: solo el solicitante
    // la ve, tambien si quien pregunta es `admin`.
    $ajena = ReportExports::completedFor(ManagementUsers::withRole(UserRole::RRHH)->id);

    Api::as(ManagementUsers::tokenFor(ManagementUsers::withRole(UserRole::ADMIN)))
        ->get('/api/v1/reports/exports/'.$ajena->uuid)
        ->assertStatus(404);
})->group('RF-IN-06', 'RF-ID-03');

it('consultar el estado de una exportacion lista devuelve un enlace, y el siguiente invalida el anterior', function (): void {
    $user = reportExportRequester();
    $export = ReportExports::completedFor($user->id);
    $token = ManagementUsers::tokenFor($user);

    $primera = Api::as($token)
        ->get('/api/v1/reports/exports/'.$export->uuid)
        ->assertValidResponse(200);

    $primerEnlace = enlaceDeDescarga($primera->json('data.download.url'));

    expect($primerEnlace)->toContain('/download?token=')
        ->and($primera->json('data.download.expires_at'))->toBeString();

    $segunda = Api::as($token)->get('/api/v1/reports/exports/'.$export->uuid)->assertStatus(200);
    $segundoEnlace = enlaceDeDescarga($segunda->json('data.download.url'));

    expect($segundoEnlace)->not->toBe($primerEnlace);

    /*
     * El primero ya no vale: es la rotacion de ADR-041, que es lo que hace que un
     * enlace olvidado en un historial compartido muera en cuanto alguien vuelve a
     * abrir la pantalla.
     *
     * **`404` y no `410`, y la diferencia es exacta**: `410` dice «ese enlace se
     * gasto» y solo se puede afirmar cuando la fila no tiene ningun token vivo.
     * Aqui si lo tiene —el segundo—, asi que lo unico que se sabe del primero es
     * que no es el vigente, igual que de cualquier token inventado. Decir `410`
     * ahi seria afirmar algo que la fila no sostiene.
     */
    Api::guest()->get($primerEnlace)->assertStatus(404);
})->group('RF-IN-06');

it('el enlace entrega el fichero con sus cabeceras de comprobacion, y solo una vez', function (): void {
    $user = reportExportRequester();
    $export = ReportExports::completedFor($user->id, contents: "uuid;horas\n1;08:00\n");

    $enlace = enlaceDeDescarga(Api::as(ManagementUsers::tokenFor($user))
        ->get('/api/v1/reports/exports/'.$export->uuid)
        ->json('data.download.url'));

    $descarga = Api::guest()->get($enlace)->assertOk();

    expect($descarga->headers->get('X-Kronoqr-Export-Sha256'))->toBe(hash('sha256', "uuid;horas\n1;08:00\n"))
        ->and($descarga->headers->get('X-Kronoqr-Export-Rows'))->toBe('1')
        // Sobre un fichero con horas de personas identificadas, y con el token en
        // la propia URL, cualquier proxy del hotel podria quedarse una copia.
        ->and($descarga->headers->get('Cache-Control'))->toContain('no-store');

    // La descarga queda auditada ANTES de entregar el fichero (RS-05).
    expect(DB::table('audit_log')->where('action', 'report_export.downloaded')->count())->toBe(1);

    // Y el enlace esta gastado: repetir la URL —volver atras en el navegador,
    // reenviarla— responde `410` y no `404`, porque el fichero sigue ahi.
    $repetida = Api::guest()->get($enlace)->assertStatus(410);

    expect($repetida->json('type'))->toBe('urn:kronoqr:problem:report-export-link-used');

    expect(ReportExports::find($export->uuid)->downloadCount)->toBe(1);
})->group('RF-IN-06', 'RS-05');

it('un enlace caducado responde 410 con su propio motivo', function (): void {
    // Los dos `410` se distinguen a proposito: «vuelve a pulsar» y «se te ha
    // quedado la pestaña abierta demasiado tiempo» no son el mismo mensaje.
    Config::set('reporting.export.link_ttl_minutes', 1);

    $user = reportExportRequester();
    $export = ReportExports::completedFor($user->id);

    $enlace = enlaceDeDescarga(Api::as(ManagementUsers::tokenFor($user))
        ->get('/api/v1/reports/exports/'.$export->uuid)
        ->json('data.download.url'));

    // El vencimiento se fuerza en la fila en lugar de esperar un minuto: lo que
    // se prueba es la comparacion, no la paciencia.
    DB::table('report_exports')
        ->where('uuid', $export->uuid)
        ->update(['download_token_expires_at' => '2020-01-01 00:00:00+00']);

    $response = Api::guest()->get($enlace)->assertStatus(410);

    expect($response->json('type'))->toBe('urn:kronoqr:problem:report-export-link-expired');
})->group('RF-IN-06');

it('un token que no es el vigente responde 404 sin decir nada mas', function (): void {
    // Aqui no se distingue nada: un token inventado no es un enlace gastado, es
    // sencillamente falso, y confirmarlo seria un oraculo sobre que `uuid`
    // existen.
    $user = reportExportRequester();
    $export = ReportExports::completedFor($user->id);

    Api::as(ManagementUsers::tokenFor($user))->get('/api/v1/reports/exports/'.$export->uuid);

    Api::guest()
        ->get('/api/v1/reports/exports/'.$export->uuid.'/download?token='.str_repeat('f', 64))
        ->assertStatus(404);
})->group('RF-IN-06');

it('la descarga sin token responde 404 aunque haya un enlace vivo', function (): void {
    // El `uuid` por si solo no abre nada: es la mitad del secreto, y la otra
    // mitad es el token (ADR-041). Con un enlace vigente emitido, pedir el
    // fichero sin token sigue siendo `404`.
    $user = reportExportRequester();
    $export = ReportExports::completedFor($user->id);

    Api::as(ManagementUsers::tokenFor($user))->get('/api/v1/reports/exports/'.$export->uuid);

    Api::guest()->get('/api/v1/reports/exports/'.$export->uuid.'/download')->assertStatus(404);
})->group('RF-IN-06');

it('una exportacion purgada sigue en la lista y su descarga responde 404', function (): void {
    // Regla dura 5: la fila se queda. Lo que ya no se puede es descargarla, y eso
    // es `404` y no `410`: aqui no hay ningun enlace que pedir de nuevo.
    $user = reportExportRequester();
    $export = ReportExports::completedFor($user->id);
    $token = ManagementUsers::tokenFor($user);

    $enlace = enlaceDeDescarga(Api::as($token)->get('/api/v1/reports/exports/'.$export->uuid)->json('data.download.url'));

    /*
     * Se purga **por el caso de uso** y no con un `UPDATE` a mano: desde la
     * revision, marcar `purged` sin minimizar viola el `CHECK`
     * `report_exports_chk_purged_is_minimised`, y escribir aqui a mano el estado
     * final seria mantener una segunda version de lo que significa purgar.
     */
    DB::table('report_exports')
        ->where('uuid', $export->uuid)
        ->update(['expires_at' => '2020-01-01 00:00:00+00']);

    app(PurgeExpiredReportExports::class)->handle();

    Api::guest()->get($enlace)->assertStatus(404);

    $lista = Api::as($token)->get('/api/v1/reports/exports')->assertValidResponse(200);

    expect($lista->json('data.0.status'))->toBe('purged')
        ->and($lista->json('data.0.sha256'))->toBeString();
})->group('RF-IN-06');

it('rechaza con 422 un PDF de nomina', function (): void {
    // Decision 5: un programa de nomina no importa un PDF. La regla vive en el
    // catalogo del dominio y la respalda un `CHECK` de la migracion.
    Queue::fake();

    Api::as(ManagementUsers::tokenFor(ManagementUsers::withRole(UserRole::RRHH)))
        ->post('/api/v1/reports/exports', reportExportBody(kind: 'payroll', format: 'pdf'))
        ->assertStatus(422);
})->group('RF-IN-06', 'RF-IN-07');

it('rechaza con 422 un rango invertido, que llega en el cuerpo y no en la URL', function (): void {
    // Las otras tres peticiones del informe son `GET` y leen el rango de la
    // cadena de consulta. Sin el ajuste de esta, la comprobacion del orden no
    // encontraria las fechas y se saltaria en silencio: el rango invertido
    // llegaria hasta el trabajo en cola y moriria alli.
    Queue::fake();

    Api::as(ManagementUsers::tokenFor(ManagementUsers::withRole(UserRole::RRHH)))
        ->post('/api/v1/reports/exports', [...reportExportBody(), 'from' => '2026-03-31', 'to' => '2026-03-01'])
        ->assertStatus(422);
})->group('RF-IN-06');

it('genera el fichero, deja los criterios dentro de la fila y lo audita', function (): void {
    /*
     * El camino completo sin la cola: pedir, generar y comprobar lo que queda.
     * Los criterios viajan en la fila porque el fichero de NOMINA no los lleva
     * dentro (decision 5), y porque el paso 1 de `/informe-nuevo` los exige
     * visibles para quien lee el informe.
     */
    $user = reportExportRequester();
    $export = ReportExports::pendingFor($user->id);

    app(GenerateReportExportHandler::class)->handle($export->uuid);

    $generada = ReportExports::find($export->uuid);

    expect($generada->status->value)->toBe('completed')
        ->and($generada->fileName)->toBe('kronoqr-horas-2026-03-01_2026-03-31.csv')
        ->and($generada->sha256)->toHaveLength(64)
        ->and($generada->rowCount)->not->toBeNull()
        ->and($generada->criteria)->not->toBe([])
        ->and($generada->expiresAt)->not->toBeNull()
        ->and(is_file((string) $generada->filePath))->toBeTrue();

    $asiento = DB::table('audit_log')->where('action', 'report_export.generated')->first();

    expect($asiento)->not->toBeNull();

    /** @var array<string, mixed> $payload */
    $payload = json_decode((string) ($asiento->payload ?? '{}'), true, 512, JSON_THROW_ON_ERROR);

    expect($payload['file_name'] ?? null)->toBe('kronoqr-horas-2026-03-01_2026-03-31.csv')
        // Nunca la ruta absoluta del fichero (regla dura 21, decision 7).
        ->and(json_encode($payload, JSON_THROW_ON_ERROR))->not->toContain('storage');
})->group('RF-IN-06', 'RS-05');

it('sin transporte de correo el aviso queda en el panel, y con transporte sale por correo', function (): void {
    /*
     * Decision 8. `panel` NO significa «no se aviso»: la pantalla es el canal de
     * serie y el unico que existe en una instalacion sin salida a internet (doc
     * 02 §11.6.2). Guardar cual de los dos fue es lo que permite responder «¿por
     * que no me llego el correo?» sin mirar los logs.
     */
    Notification::fake();
    Config::set('mail.default', 'array');

    $user = reportExportRequester();
    $sinCorreo = ReportExports::pendingFor($user->id);

    app(GenerateReportExportHandler::class)->handle($sinCorreo->uuid);

    expect(ReportExports::find($sinCorreo->uuid)->notificationChannel?->value)->toBe('panel');
    Notification::assertNothingSent();

    Config::set('mail.default', 'smtp');
    app()->forgetInstance(ReportExportNotifier::class);
    app()->forgetInstance(GenerateReportExportHandler::class);

    $conCorreo = ReportExports::pendingFor($user->id);

    app(GenerateReportExportHandler::class)->handle($conCorreo->uuid);

    expect(ReportExports::find($conCorreo->uuid)->notificationChannel?->value)->toBe('mail');
    Notification::assertSentOnDemand(ReportExportReadyNotification::class);
})->group('RF-IN-06');

it('la nomina en diferido sale con la plantilla configurada y con su propio conjunto auditado', function (): void {
    /*
     * RF-IN-07 y decision 5: el fichero de nomina es **el mismo informe** por
     * empleado pasado por la plantilla que el cliente configura, y lo escribe el
     * **mismo** escritor que la descarga sincrona. Con dos implementaciones, el
     * fichero que RRHH descarga desde la pantalla y el que llega por el enlace
     * podrian llevar columnas distintas — y una exportacion de nomina equivocada
     * no se descubre hasta la nomina siguiente.
     *
     * Y su asiento de divulgacion lleva `payroll_export` y no `period_report`
     * (RS-05): ante una revision de accesos, «saco el informe de marzo» y «saco
     * el fichero con el que se paga marzo» no son la misma frase.
     */
    $user = reportExportRequester();
    $export = ReportExports::pendingFor($user->id, kind: ReportExportKind::Payroll, format: 'csv');

    app(GenerateReportExportHandler::class)->handle($export->uuid);

    $generada = ReportExports::find($export->uuid);

    expect($generada->status->value)->toBe('completed')
        // `nomina` y no `horas`: en una carpeta de descargas los dos ficheros
        // llevan las mismas fechas y contenidos muy distintos.
        ->and($generada->fileName)->toStartWith('kronoqr-nomina-')
        ->and(is_file((string) $generada->filePath))->toBeTrue();

    /*
     * Y el fichero NO lleva el bloque de criterios dentro, al contrario que el
     * informe por periodo: una fila de comentario al principio rompe la
     * importacion del programa de nomina. Los criterios viajan en la fila, que es
     * donde la pantalla los enseña.
     */
    $contenido = (string) file_get_contents((string) $generada->filePath);

    expect($contenido)->not->toContain('kronoqr-horas')
        ->and($generada->criteria)->not->toBe([]);

    expect(DB::table('audit_log')
        ->where('action', 'personal_data.accessed')
        ->where('payload', 'like', '%payroll_export%')
        ->count())->toBe(1);
})->group('RF-IN-06', 'RF-IN-07', 'RS-05');

it('la nomina en diferido falla de forma visible si la plantilla no se puede resolver', function (): void {
    /*
     * La implementacion de reserva, sustituida aqui a proposito.
     *
     * Existe porque un fichero de nomina con **otras columnas de las
     * configuradas** es peor que ningun fichero: alguien lo importa en la
     * herramienta con la que se paga y no lo nota hasta la nomina siguiente. Asi
     * que cuando la plantilla no se puede resolver, el camino `payroll` falla en
     * voz alta y la fila queda en `failed` con un motivo que se lee.
     *
     * Lo que se comprueba es el desenlace —fila cerrada, motivo cerrado, ningun
     * fichero a medias en el disco—, que es lo que el panel enseña y lo que
     * impide que esa persona se quede con `409` para siempre.
     */
    app()->bind(PayrollDocumentWriter::class, UnavailablePayrollDocumentWriter::class);
    app()->forgetInstance(GenerateReportExportHandler::class);

    $user = reportExportRequester();
    $export = ReportExports::pendingFor($user->id, kind: ReportExportKind::Payroll, format: 'csv');

    expect(static fn () => app(GenerateReportExportHandler::class)->handle($export->uuid))
        ->toThrow(ReportExportWriteFailed::class);

    $fallida = ReportExports::find($export->uuid);

    expect($fallida->status->value)->toBe('failed')
        ->and($fallida->failureReason?->value)->toBe('write_failed')
        // Y no queda ningun fichero a medias: un CSV con media plantilla dentro,
        // invisible para la purga —que solo mira filas `completed`—, seria una
        // copia de horas nominales abandonada en el disco del cliente.
        ->and($fallida->filePath)->toBeNull();
})->group('RF-IN-07');

it('un fallo del aviso NO destruye un informe ya generado', function (): void {
    /*
     * **El hallazgo bloqueante de la revision.** El aviso estaba dentro del `try`
     * de la generacion, asi que cualquier excepcion suya —resolver la cuenta a la
     * que avisar es una consulta, y una consulta puede fallar— caia en el `catch`:
     * borraba el fichero recien escrito y marcaba `failed` una fila que estaba
     * `completed`, con `file_path`, `sha256` y `completed_at` a nulo.
     *
     * El resultado era el peor de todos: un `report_export.generated` en
     * `audit_log` que afirma un fichero que ya no existe. El asiento es
     * solo-apendice (regla dura 6): no se puede corregir despues.
     *
     * Lo que se afirma aqui es el desenlace correcto: el aviso revienta, la
     * excepcion sale, y **el informe sigue intacto**. Lo unico que falta es el
     * sello del aviso, y la pantalla —que es el canal de serie— lo enseña igual
     * (regla dura 12).
     */
    app()->bind(ReportExportNotifier::class, ExplodingReportExportNotifier::class);
    app()->forgetInstance(GenerateReportExportHandler::class);

    $user = reportExportRequester();
    $export = ReportExports::pendingFor($user->id);

    expect(static fn () => app(GenerateReportExportHandler::class)->handle($export->uuid))
        ->toThrow(RuntimeException::class);

    $generada = ReportExports::find($export->uuid);

    expect($generada->status->value)->toBe('completed')
        ->and($generada->filePath)->not->toBeNull()
        ->and(is_file((string) $generada->filePath))->toBeTrue()
        ->and($generada->sha256)->toHaveLength(64)
        ->and($generada->completedAt)->not->toBeNull()
        // Sin sellar, que es exactamente la verdad: no se aviso por ningun canal.
        ->and($generada->notifiedAt)->toBeNull();

    // Y el asiento de generacion sigue describiendo un fichero que existe.
    expect(DB::table('audit_log')->where('action', 'report_export.generated')->count())->toBe(1);

    // El turno de esa persona queda libre —la fila no esta en curso—, asi que la
    // siguiente peticion no choca con el indice unico.
    expect($generada->isInProgress())->toBeFalse();
})->group('RF-IN-06');

it('la exportacion purgada pierde el alcance y los filtros por persona', function (): void {
    /*
     * RL-11, minimizacion al purgar. Mientras el fichero existe, el alcance y los
     * dos filtros explican por que contiene lo que contiene; cuando el fichero se
     * borra dejan de tener uso operativo y lo unico que quedaria seria un dato
     * personal conservado sin plazo.
     *
     * **Es la razon por la que `report_exports` no necesita entrar en
     * `RetentionScope`**: pasado su plazo, la fila deja de contener datos
     * personales y conservarla indefinidamente es gratis. El hecho completo sigue
     * en `audit_log` (`report_export.requested`), que si tiene plazo: cuatro años.
     */
    $user = reportExportRequester();
    $export = ReportExports::completedFor(
        $user->id,
        expiresAt: app(Clock::class)->now()->modify('-1 hour'),
    );

    app(PurgeExpiredReportExports::class)->handle();

    $respuesta = Api::as(ManagementUsers::tokenFor($user))
        ->get('/api/v1/reports/exports')
        ->assertValidResponse(200);

    expect($respuesta->json('data.0.status'))->toBe('purged')
        ->and($respuesta->json('data.0.scope'))->toBeNull()
        ->and($respuesta->json('data.0.parameters.employee_uuid'))->toBeNull()
        ->and($respuesta->json('data.0.parameters.department_id'))->toBeNull()
        // Y lo que se queda, que es lo que permite que la lista se explique sola.
        ->and($respuesta->json('data.0.parameters.from'))->toBe('2026-03-01')
        ->and($respuesta->json('data.0.sha256'))->toBeString();
})->group('RL-11', 'RF-IN-06');

it('no dice «ese enlace ya se ha usado» de una exportacion de la que nunca se emitio ninguno', function (): void {
    /*
     * El oraculo que encontro la revision. Con `download_token_hash` a nulo hay
     * dos situaciones que desde fuera se parecen: el enlace se gasto, o nunca se
     * emitio. Responder `410` a las dos convertia la ruta de descarga —que va sin
     * sesion— en una forma de preguntar «¿existe esta exportacion y esta lista?»
     * componiendo una URL a mano.
     *
     * `downloaded_at` es lo que separa las dos, y es un hecho de la fila.
     */
    $user = reportExportRequester();
    $export = ReportExports::completedFor($user->id);

    // Sin pasar por `GET /reports/exports/{uuid}`: nunca se emitio enlace.
    Api::guest()
        ->get('/api/v1/reports/exports/'.$export->uuid.'/download?token='.str_repeat('a', 64))
        ->assertStatus(404);
})->group('RF-IN-06', 'RS-03');

it('no entrega el fichero de una exportacion cuyo plazo ya vencio', function (): void {
    // La purga corre una vez al dia: entre el vencimiento y esa pasada hay horas
    // en las que la fila sigue `completed` con su fichero en el disco. La
    // caducidad es una promesa al cliente, no un efecto secundario de cuando pase
    // el planificador.
    $user = reportExportRequester();
    $export = ReportExports::completedFor($user->id);

    $enlace = enlaceDeDescarga(Api::as(ManagementUsers::tokenFor($user))
        ->get('/api/v1/reports/exports/'.$export->uuid)
        ->json('data.download.url'));

    DB::table('report_exports')
        ->where('uuid', $export->uuid)
        ->update(['expires_at' => '2020-01-01 00:00:00+00']);

    Api::guest()->get($enlace)->assertStatus(404);
})->group('RF-IN-06');
