<?php

declare(strict_types=1);

use App\Modules\Product\Infrastructure\Job\GenerateDataExportJob;
use App\Modules\Shared\Application\Port\Clock;
use App\Modules\Shared\Domain\ValueObject\UserRole;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Spectator\Spectator;
use Tests\Support\Database\RefreshDatabase;
use Tests\Support\Http\Api;
use Tests\Support\Identity\ManagementUsers;
use Tests\Support\Product\DataExports;
use Tests\Support\Product\LicenseKeys;
use Tests\Support\Time\FixedClock;
use Tests\Support\Workforce\WorkforceFixtures;

/*
 * Las tres rutas de `/api/v1/data-export` (RF-PD-14, RL-20, RS-05).
 *
 * LO QUE SE COMPRUEBA AQUI es que el mecanismo hace lo que RL-20 promete: el
 * cliente pide, ve el estado, descarga y puede comprobar que el fichero llego
 * entero; y que los tres desenlaces que no son «toma tu fichero» —una en curso,
 * una a medias, una purgada— se distinguen entre si, porque cada uno le cambia
 * la accion a quien lo recibe.
 *
 * Las respuestas se validan contra `openapi.yaml` con Spectator: el contrato es
 * la fuente de verdad (ADR-013), y una respuesta que no lo cumple rompe el
 * cliente TypeScript generado de el.
 *
 * LA COLA SE FALSEA EN CASI TODAS. La suite corre con `QUEUE_CONNECTION=sync`
 * (ver `phpunit.xml`), asi que sin `Queue::fake()` cada `POST` recorreria todas
 * las tablas de la instalacion **dentro de la peticion**: una prueba de
 * cabeceras HTTP tardaria lo que tarda una exportacion. Con la cola falseada, la
 * fila se queda en `pending`, que es exactamente el estado que el `202` promete.
 * La generacion de verdad se prueba en `DataExportVolumeTest` y en
 * `DataExportConsoleTest`.
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Spectator::using('openapi.yaml');
    WorkforceFixtures::site();
    LicenseKeys::install();

    DataExports::useTemporaryPath();
});

afterEach(function (): void {
    DataExports::cleanUpTemporaryPath();
});

function exportAdminToken(): string
{
    return ManagementUsers::tokenFor(ManagementUsers::withRole(UserRole::ADMIN));
}

it('acepta la peticion con 202 y deja la fila en pending', function (): void {
    Queue::fake();

    $response = Api::as(exportAdminToken())
        ->post('/api/v1/data-export')
        ->assertValidResponse(202);

    expect($response->json('data.status'))->toBe('pending')
        ->and($response->json('data.requested_via'))->toBe('panel')
        ->and($response->json('data.requested_by.name'))->toBeString()
        ->and($response->json('data.file_name'))->toBeNull()
        ->and($response->json('data.sha256'))->toBeNull()
        ->and($response->json('data.download_count'))->toBe(0)
        // `row_counts` vacio es un OBJETO y no una lista: con `[]` la respuesta
        // dejaria de cumplir el esquema, y el fallo solo se veria en la primera
        // exportacion de una instalacion nueva.
        ->and($response->json('data.row_counts'))->toBe([]);

    // Y la peticion ha quedado auditada antes de que exista ningun fichero
    // (RS-05): lo que se registra es la intencion.
    expect(DB::table('audit_log')->where('action', 'data_export.requested')->count())->toBe(1);
})->group('RF-PD-14', 'RL-20', 'RS-05');

it('lista las exportaciones de la mas reciente a la mas antigua, con las purgadas dentro', function (): void {
    // Regla dura 5: una lista que enseñara solo las descargables convertiria «tu
    // copia caduco» en «aqui no ha pasado nada».
    DataExports::completed(rowCounts: ['employees' => 3]);
    $purgada = DataExports::completed();

    DB::table('data_exports')->where('uuid', $purgada->uuid)->update([
        'status' => 'purged',
        'purged_at' => '2026-09-09 10:00:00+00',
        'file_path' => null,
    ]);

    $response = Api::as(exportAdminToken())->get('/api/v1/data-export')->assertValidResponse(200);

    expect($response->json('data'))->toHaveCount(2);

    /** @var list<array<string, mixed>> $filas */
    $filas = $response->json('data');

    $estados = array_map(
        static fn (array $fila): string => is_string($fila['status']) ? $fila['status'] : '',
        $filas,
    );

    expect($estados)->toContain('purged')
        ->and($estados)->toContain('completed');

    // La fila purgada conserva sus recuentos y su huella: existio y se sabe que
    // existio.
    $purgadas = array_values(array_filter($filas, static fn (array $fila): bool => $fila['status'] === 'purged'));

    expect($purgadas)->toHaveCount(1)
        ->and($purgadas[0]['sha256'])->toBeString()
        ->and($purgadas[0]['purged_at'])->toBeString()
        ->and($purgadas[0]['row_counts'])->not->toBe([]);
})->group('RF-PD-14', 'RN-13');

it('responde 409 con la exportacion en curso dentro del cuerpo', function (): void {
    // El panel enseña la que hay en lugar de pedir otra: es lo unico util que
    // puede hacer con este error, y por eso la fila viaja en el `409`.
    Queue::fake();

    $enCurso = DataExports::inProgress('running');

    $response = Api::as(exportAdminToken())->post('/api/v1/data-export')->assertStatus(409);

    expect($response->json('type'))->toBe('urn:kronoqr:problem:data-export-in-progress')
        ->and($response->json('export.uuid'))->toBe($enCurso->uuid)
        ->and($response->json('export.status'))->toBe('running');

    // Y no se ha creado ninguna fila de mas: la exclusion mutua la garantiza la
    // base de datos, no una comprobacion previa.
    expect(DB::table('data_exports')->count())->toBe(1);
})->group('RF-PD-14');

it('descarga el ZIP con la huella y el total de filas en las cabeceras', function (): void {
    $usuario = ManagementUsers::withRole(UserRole::ADMIN);
    $token = ManagementUsers::tokenFor($usuario);

    $export = DataExports::completed(
        requestedByUserId: $usuario->id,
        rowCounts: ['employees' => 500, 'shift_entries' => 45000],
    );

    $response = Api::as($token)
        ->get('/api/v1/data-export/'.$export->uuid.'/download')
        ->assertStatus(200);

    expect($response->headers->get('X-Kronoqr-Export-Sha256'))->toBe($export->sha256)
        // El total de filas de datos: 500 + 45.000. Es lo que permite comprobar
        // de un vistazo que la copia esta completa sin abrir el fichero.
        ->and($response->headers->get('X-Kronoqr-Export-Rows'))->toBe('45500')
        ->and($response->headers->get('Cache-Control'))->toContain('no-store')
        ->and($response->headers->get('Cache-Control'))->toContain('private')
        ->and((string) $response->headers->get('Content-Disposition'))->toContain($export->fileName ?? '');
})->group('RF-PD-14', 'RL-20');

it('audita cada descarga y cuenta cuantas van', function (): void {
    // RS-05. El fichero lleva todos los datos personales de la plantilla: el
    // cliente tiene que poder responder quien se lo llevo y cuando.
    $usuario = ManagementUsers::withRole(UserRole::ADMIN);
    $token = ManagementUsers::tokenFor($usuario);

    $export = DataExports::completed(requestedByUserId: $usuario->id);

    Api::as($token)->get('/api/v1/data-export/'.$export->uuid.'/download')->assertStatus(200);
    Api::as($token)->get('/api/v1/data-export/'.$export->uuid.'/download')->assertStatus(200);

    /** @var list<object{actor_type: string, actor_id: ?int, payload: string}> $asientos */
    $asientos = DB::table('audit_log')
        ->where('action', 'data_export.downloaded')
        ->orderBy('id')
        ->get()
        ->all();

    expect($asientos)->toHaveCount(2, 'Cada descarga deja su propio asiento: no se agrupan por ventana.');

    /** @var array<string, mixed> $primero */
    $primero = json_decode($asientos[0]->payload, true, 512, JSON_THROW_ON_ERROR);
    /** @var array<string, mixed> $segundo */
    $segundo = json_decode($asientos[1]->payload, true, 512, JSON_THROW_ON_ERROR);

    expect($primero['download_count'])->toBe(1)
        ->and($segundo['download_count'])->toBe(2)
        ->and($primero['sha256'])->toBe($export->sha256)
        // El actor es la cuenta que se lo llevo, no `system`.
        ->and($asientos[0]->actor_type)->toBe('user')
        ->and((int) $asientos[0]->actor_id)->toBe($usuario->id);

    $fila = DataExports::find($export->uuid);

    expect($fila->downloadCount)->toBe(2)
        ->and($fila->downloadedAt)->not->toBeNull();
})->group('RF-PD-14', 'RS-05', 'RL-04');

it('responde 409 al descargar una que todavia no ha terminado', function (): void {
    // `409` y no `404`: el panel sigue sondeando. Con un `404` habria dejado de
    // hacerlo justo cuando la generacion esta a medias.
    $enCurso = DataExports::inProgress('running');

    $response = Api::as(exportAdminToken())
        ->get('/api/v1/data-export/'.$enCurso->uuid.'/download')
        ->assertStatus(409);

    expect($response->json('type'))->toBe('urn:kronoqr:problem:data-export-not-ready');
})->group('RF-PD-14');

it('responde 404 con una purgada, con una fallida y con una que no existe', function (): void {
    // Los tres casos en los que no hay nada que esperar. La fila sigue en la
    // lista con su estado, para que se sepa que existio.
    $purgada = DataExports::completed();

    DB::table('data_exports')->where('uuid', $purgada->uuid)->update([
        'status' => 'purged',
        'purged_at' => '2026-09-09 10:00:00+00',
        'file_path' => null,
    ]);

    $fallida = DataExports::inProgress('pending');

    DB::table('data_exports')->where('uuid', $fallida->uuid)->update([
        'status' => 'failed',
        'failed_at' => '2026-09-08 10:16:00+00',
        'failure_reason' => 'unexpected',
    ]);

    $token = exportAdminToken();

    Api::as($token)->get('/api/v1/data-export/'.$purgada->uuid.'/download')->assertStatus(404);
    Api::as($token)->get('/api/v1/data-export/'.$fallida->uuid.'/download')->assertStatus(404);
    Api::as($token)->get('/api/v1/data-export/0199f6a2-9999-7d3b-8a90-1b2c3d4e5f61/download')->assertStatus(404);

    // Ninguna de las tres deja asiento de descarga: no se descargo nada.
    expect(DB::table('audit_log')->where('action', 'data_export.downloaded')->count())->toBe(0);
})->group('RF-PD-14', 'RS-05');

it('responde 404 si la fila dice completed pero alguien borro el fichero a mano', function (): void {
    // El caso real: alguien borra `storage/app/exports` para hacer sitio. Tiene
    // que responder `404`, no reventar con un error de sistema de ficheros.
    $export = DataExports::completed();

    unlink((string) $export->filePath);

    Api::as(exportAdminToken())
        ->get('/api/v1/data-export/'.$export->uuid.'/download')
        ->assertStatus(404);
})->group('RF-PD-14');

it('no expone la ruta del fichero en el servidor', function (): void {
    // Una ruta absoluta del sistema de ficheros no le sirve de nada al navegador
    // y describe la topologia de la maquina del cliente.
    $export = DataExports::completed();

    $response = Api::as(exportAdminToken())->get('/api/v1/data-export')->assertValidResponse(200);

    expect($response->json('data.0'))->not->toHaveKey('file_path')
        ->and($response->getContent())->not->toContain((string) $export->filePath);
})->group('RF-PD-14', 'RS-08');

it('devuelve requested_by nulo en la que se pidio desde la consola', function (): void {
    DataExports::completed(requestedVia: 'console');

    $response = Api::as(exportAdminToken())->get('/api/v1/data-export')->assertValidResponse(200);

    expect($response->json('data.0.requested_via'))->toBe('console')
        ->and($response->json('data.0.requested_by'))->toBeNull();
})->group('RF-PD-14');

it('una exportacion atascada no bloquea la siguiente para siempre', function (): void {
    /*
     * **EL BLOQUEANTE QUE ESTA PRUEBA PROTEGE.** Solo puede haber una
     * exportacion `pending|running` a la vez —lo impone un indice unico—, asi que
     * una fila que nadie termina deja al cliente sin poder ejercer RL-20: `409`
     * eterno en el panel y salida `2` en la consola.
     *
     * Y quedarse a medias es facil, no exotico: el trabajador de cola muere, o
     * alguien para los contenedores durante la generacion —que es literalmente
     * el paso 1 de cualquier actualizacion del producto—.
     *
     * Se simula envejeciendo la fila `running` mas alla del umbral, que es lo
     * que ve el sistema despues de un `SIGKILL`: nadie la volvio a tocar.
     */
    Queue::fake();

    $atascada = DataExports::inProgress('running');

    DB::table('data_exports')->where('uuid', $atascada->uuid)->update([
        'requested_at' => '2026-09-08 08:00:00+00',
        'started_at' => '2026-09-08 08:00:02+00',
    ]);

    // El reloj de la instalacion, dos horas despues de que arrancara: por encima
    // del umbral de una hora.
    app()->instance(Clock::class, FixedClock::at('2026-09-08 10:00:00'));

    $response = Api::as(exportAdminToken())
        ->post('/api/v1/data-export')
        ->assertValidResponse(202);

    expect($response->json('data.status'))->toBe('pending');

    // La vieja queda cerrada con el motivo que la documentacion del cliente
    // nombra: `stale`, no «fallida sin mas».
    $vieja = DB::table('data_exports')->where('uuid', $atascada->uuid)->first();

    expect($vieja?->status)->toBe('failed')
        ->and($vieja?->failure_reason)->toBe('stale')
        ->and($vieja?->failed_at)->not->toBeNull();

    // Y ahora hay dos filas: nada se ha borrado (regla dura 5).
    expect(DB::table('data_exports')->count())->toBe(2);
})->group('RF-PD-14', 'RL-20');

it('no da por atascada una exportacion que sigue dentro de su plazo', function (): void {
    // La otra mitad: si el umbral se aplicara mal, una exportacion grande que
    // lleva diez minutos escribiendo se declararia muerta y la peticion siguiente
    // empezaria una SEGUNDA copia completa sobre la misma base de datos.
    Queue::fake();

    $enCurso = DataExports::inProgress('running');

    DB::table('data_exports')->where('uuid', $enCurso->uuid)->update([
        'requested_at' => '2026-09-08 09:50:00+00',
        'started_at' => '2026-09-08 09:50:02+00',
    ]);

    app()->instance(Clock::class, FixedClock::at('2026-09-08 10:00:00'));

    Api::as(exportAdminToken())->post('/api/v1/data-export')->assertStatus(409);

    expect(DB::table('data_exports')->where('uuid', $enCurso->uuid)->value('status'))->toBe('running')
        ->and(DB::table('data_exports')->count())->toBe(1);
})->group('RF-PD-14', 'RL-20');

it('el umbral de obsolescencia nunca es menor que el tiempo maximo de generacion', function (): void {
    /*
     * Las dos cifras estan escritas en dos sitios —el `timeout` del trabajo y
     * `product.data_export_stale_after_seconds`— y **tienen que decir lo mismo**.
     * Si el umbral bajara por debajo del tiempo que la generacion puede tardar
     * legitimamente, una exportacion grande se declararia atascada mientras sigue
     * escribiendo, y la peticion siguiente lanzaria una segunda copia completa
     * sobre la misma base de datos.
     */
    expect(config('product.data_export_stale_after_seconds'))
        ->toBeGreaterThanOrEqual(
            GenerateDataExportJob::TIMEOUT_SECONDS,
            'PRODUCT_DATA_EXPORT_STALE_AFTER no puede ser menor que el timeout del trabajo de cola.',
        );
})->group('RF-PD-14');
