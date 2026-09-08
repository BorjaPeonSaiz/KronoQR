<?php

declare(strict_types=1);

use App\Modules\Product\Application\Command\RevokeSupportAccessCommand;
use App\Modules\Product\Application\Port\SupportAccessRecorder;
use App\Modules\Product\Application\UseCase\RevokeSupportAccessHandler;
use App\Modules\Product\Domain\ValueObject\SupportRevocationOutcome;
use App\Modules\Product\Domain\ValueObject\SupportScope;
use App\Modules\Shared\Domain\ValueObject\UserRole;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\Support\Database\RefreshDatabase;
use Tests\Support\Http\Api;
use Tests\Support\Identity\ManagementUsers;
use Tests\Support\Product\LicenseKeys;
use Tests\Support\Product\SupportGrants;
use Tests\Support\Workforce\WorkforceFixtures;

/*
 * Conceder, usar y revocar dejan rastro en `audit_log` (RF-PD-11, RL-18, RL-04,
 * regla dura 6, ADR-020).
 *
 * ES LA PRUEBA QUE SOSTIENE EL REQUISITO ENTERO. RF-PD-11 no pide solo que el
 * acceso sea temporal y revocable: pide que quede registrado y que el registro
 * sea **visible para el cliente**. Sin estos tres asientos, la unica evidencia de
 * que el fabricante entro seria una fila que la aplicacion puede actualizar.
 *
 * Y la otra mitad, que es igual de importante: **el uso escribe un asiento por
 * ventana y no uno por peticion**. `audit_log` es una cadena encadenada por hash
 * bajo un candado global (ADR-010) —el mismo por el que pasa cada fichaje—, asi
 * que una sesion de soporte de veinte minutos con un asiento por peticion
 * degradaria el camino del quiosco (regla dura 19).
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    WorkforceFixtures::site();
    LicenseKeys::install();
    // La ventana de auditoria de usos vive en cache: cada prueba parte de una
    // limpia, o la segunda no veria ningun asiento de uso.
    Cache::flush();
});

/**
 * @return list<object{actor_type: string, actor_id: int|null, action: string, payload: string}>
 */
function asientosDeSoporte(string $action): array
{
    /** @var list<object{actor_type: string, actor_id: int|null, action: string, payload: string}> $rows */
    $rows = DB::table('audit_log')
        ->where('action', $action)
        ->orderBy('id')
        ->get(['actor_type', 'actor_id', 'action', 'payload'])
        ->all();

    return $rows;
}

it('conceder deja un asiento con el actor, el motivo y la caducidad', function (): void {
    $admin = ManagementUsers::withRole(UserRole::ADMIN);

    Api::as(ManagementUsers::tokenFor($admin))
        ->post('/api/v1/support/grants', [
            'reason' => 'Incidencia #123: la cola del quiosco no vacia',
            'scope' => 'read_only',
            'hours' => 8,
        ])
        ->assertStatus(201);

    $asientos = asientosDeSoporte('support_grant.granted');

    expect($asientos)->toHaveCount(1);

    /** @var array<string, mixed> $payload */
    $payload = json_decode($asientos[0]->payload, true, 512, JSON_THROW_ON_ERROR);

    // El actor es la CUENTA que lo autorizo, no la concesion: conceder lo hace el
    // cliente. Es la firma del encargo del art. 28 RGPD (RL-18).
    expect($asientos[0]->actor_type)->toBe('user')
        ->and($asientos[0]->actor_id)->toBe($admin->id)
        ->and($payload['scope'])->toBe('read_only')
        ->and($payload['hours'])->toBe(8)
        ->and($payload['reason'])->toBe('Incidencia #123: la cola del quiosco no vacia')
        ->and($payload['expires_at'])->toBeString()
        ->and($payload['granted_by_user_id'])->toBe($admin->id);
})->group('RF-PD-11', 'RL-18', 'RL-04');

it('el asiento de concesion NO lleva el token ni su hash', function (): void {
    // El trail se conserva cuatro años y se exporta. Un secreto ahi dentro es un
    // secreto publicado con retardo.
    $issued = SupportGrants::issue();

    $payload = asientosDeSoporte('support_grant.granted')[0]->payload;

    /** @var string $hash */
    $hash = DB::table('support_grants')->where('uuid', $issued->grant->uuid)->value('token_hash');

    expect($payload)->not->toContain($issued->token)
        ->and($payload)->not->toContain($hash);
})->group('RF-PD-11', 'RS-08');

it('usar el acceso deja un asiento cuyo actor es la CONCESION', function (): void {
    $issued = SupportGrants::issue(scope: SupportScope::Configuration);

    Api::as($issued->token)->get('/api/v1/settings')->assertOk();

    $asientos = asientosDeSoporte('support_grant.used');

    expect($asientos)->toHaveCount(1);

    /** @var array<string, mixed> $payload */
    $payload = json_decode($asientos[0]->payload, true, 512, JSON_THROW_ON_ERROR);

    // NO hay ninguna cuenta del fabricante en la instalacion y por eso no puede
    // haber ningun `user` aqui (ADR-020, regla dura 16). El actor es la fila de
    // `support_grants`, que es lo que permite responder «¿que hizo el acceso que
    // concedi el martes?» con un filtro por columna indexada.
    expect($asientos[0]->actor_type)->toBe('support_grant')
        ->and($asientos[0]->actor_id)->toBe($issued->grant->id)
        ->and($payload['grant_uuid'])->toBe($issued->grant->uuid)
        ->and($payload['method'])->toBe('GET')
        ->and($payload['route'])->toBe('api/v1/settings');
})->group('RF-PD-11', 'RL-18');

it('usar el acceso actualiza accessed_at, que es lo que ve el cliente', function (): void {
    $issued = SupportGrants::issue(scope: SupportScope::Configuration);

    expect(DB::table('support_grants')->where('uuid', $issued->grant->uuid)->value('accessed_at'))->toBeNull();

    Api::as($issued->token)->get('/api/v1/settings')->assertOk();

    expect(DB::table('support_grants')->where('uuid', $issued->grant->uuid)->value('accessed_at'))->not->toBeNull();
})->group('RF-PD-11');

it('un uso escribe UN asiento por ventana, no uno por peticion', function (): void {
    // La mitad que protege el camino de fichaje. Cinco peticiones seguidas dentro
    // de la misma ventana de 900 s dejan un solo asiento; `accessed_at`, en
    // cambio, se refresca en las cinco.
    $issued = SupportGrants::issue(scope: SupportScope::Configuration);

    for ($i = 0; $i < 5; $i++) {
        Api::as($issued->token)->get('/api/v1/settings')->assertOk();
    }

    expect(asientosDeSoporte('support_grant.used'))->toHaveCount(1);
})->group('RF-PD-11', 'RL-04');

it('con la ventana desactivada vuelve a haber un asiento por peticion', function (): void {
    // El control negativo del agrupamiento: sin el, esta suite pasaria igual con
    // un caso de uso que se limitara a auditar una vez y nunca mas.
    config()->set('product.support_use_audit_window_seconds', 0);

    $issued = SupportGrants::issue(scope: SupportScope::Configuration);

    Api::as($issued->token)->get('/api/v1/settings')->assertOk();
    Api::as($issued->token)->get('/api/v1/settings')->assertOk();
    Api::as($issued->token)->get('/api/v1/settings')->assertOk();

    expect(asientosDeSoporte('support_grant.used'))->toHaveCount(3);
})->group('RF-PD-11');

it('una peticion RECHAZADA por la policy tambien cuenta como uso', function (): void {
    // Y tiene que contar: que alguien con acceso de soporte intente entrar donde
    // no le corresponde es exactamente el hecho que el cliente quiere ver en su
    // trail (ADR-020, §8.1, T1199).
    $issued = SupportGrants::issue(scope: SupportScope::Diagnostics);

    Api::as($issued->token)->get('/api/v1/employees')->assertStatus(403);

    expect(asientosDeSoporte('support_grant.used'))->toHaveCount(1);
})->group('RF-PD-11');

it('revocar deja un asiento con quien lo hizo y si el acceso estaba vivo', function (): void {
    $admin = ManagementUsers::withRole(UserRole::ADMIN);
    $issued = SupportGrants::issue();

    Api::as(ManagementUsers::tokenFor($admin))
        ->delete('/api/v1/support/grants/'.$issued->grant->uuid)
        ->assertStatus(204);

    $asientos = asientosDeSoporte('support_grant.revoked');

    expect($asientos)->toHaveCount(1);

    /** @var array<string, mixed> $payload */
    $payload = json_decode($asientos[0]->payload, true, 512, JSON_THROW_ON_ERROR);

    expect($asientos[0]->actor_type)->toBe('user')
        ->and($payload['grant_uuid'])->toBe($issued->grant->uuid)
        ->and($payload['revoked_by_user_id'])->toBe($admin->id)
        // Distingue «se corto un acceso vivo» de «se retiro uno ya caducado»: una
        // decision urgente y una limpieza no son el mismo hecho.
        ->and($payload['was_active'])->toBeTrue();
})->group('RF-PD-11', 'RL-04');

it('los tres asientos caen en la misma familia del bloque D', function (): void {
    // `support_access`, la decima. Es lo que permite responder «¿ha entrado el
    // fabricante en mi instalacion?» con UNA consulta y no con tres.
    $issued = SupportGrants::issue(scope: SupportScope::Configuration);
    Api::as($issued->token)->get('/api/v1/settings')->assertOk();
    Api::as(ManagementUsers::tokenFor(ManagementUsers::withRole(UserRole::ADMIN)))
        ->delete('/api/v1/support/grants/'.$issued->grant->uuid)
        ->assertStatus(204);

    $acciones = DB::table('audit_log')
        ->where('action', 'like', 'support_grant.%')
        ->orderBy('id')
        ->pluck('action')
        ->all();

    expect($acciones)->toBe([
        'support_grant.granted',
        'support_grant.used',
        'support_grant.revoked',
    ]);
})->group('RF-PD-11', 'RL-04');

it('la cadena de hash sigue verificando despues de los tres asientos', function (): void {
    // Regla dura 6: `audit_log` es solo-apendice y encadenado. Si los asientos de
    // soporte se escribieran por un camino distinto del de todos los demas, la
    // cadena se rompería aqui y no en produccion.
    $issued = SupportGrants::issue(scope: SupportScope::Configuration);
    Api::as($issued->token)->get('/api/v1/settings')->assertOk();
    Api::as(ManagementUsers::tokenFor(ManagementUsers::withRole(UserRole::ADMIN)))
        ->delete('/api/v1/support/grants/'.$issued->grant->uuid)
        ->assertStatus(204);

    expect(Artisan::call('compliance:verify-audit-chain'))->toBe(0);
})->group('RF-PD-11', 'RL-04');

it('dos revocaciones simultaneas dejan UN solo asiento', function (): void {
    /*
     * LA CARRERA, FORZADA.
     *
     * Dos pestañas del panel —o el panel y la consola— revocando la misma
     * concesion a la vez. Las dos peticiones leen la fila **antes** de que
     * ninguna escriba, las dos ven `revoked_at` a nulo y las dos creen haber
     * revocado: la comprobacion en memoria del dominio no puede distinguirlas.
     *
     * Se reproduce llamando dos veces al caso de uso con la entidad ya hidratada
     * en las dos —que es exactamente el estado en el que quedan las dos
     * peticiones de la carrera real— y se afirma lo unico que importa: que
     * `audit_log` recibe **un** asiento y no dos. Dos asientos del mismo hecho en
     * una cadena encadenada por hash que se conserva cuatro años dejarian la
     * pregunta «¿cuando se corto ese acceso?» con dos respuestas.
     *
     * Quien decide es la base de datos: el `UPDATE` lleva `WHERE revoked_at IS
     * NULL` y devuelve las filas afectadas. Mismo criterio que la idempotencia
     * del fichaje, que la resuelve un `UNIQUE` y no un `SELECT` previo.
     */
    $issued = SupportGrants::issue();

    /** @var RevokeSupportAccessHandler $handler */
    $handler = app(RevokeSupportAccessHandler::class);

    $primera = $handler->handle(new RevokeSupportAccessCommand($issued->grant->uuid, null));
    $segunda = $handler->handle(new RevokeSupportAccessCommand($issued->grant->uuid, null));

    expect($primera)->toBe(SupportRevocationOutcome::Revoked)
        ->and($segunda)->toBe(SupportRevocationOutcome::AlreadyRevoked)
        ->and(asientosDeSoporte('support_grant.revoked'))->toHaveCount(1)
        // Y la fila conserva el instante de la que DE VERDAD retiro el acceso.
        ->and(DB::table('support_grants')->where('uuid', $issued->grant->uuid)->count())->toBe(1);
})->group('RF-PD-11', 'RL-04');

it('el asiento de revocacion es el de la primera, no el de la ultima', function (): void {
    // La otra mitad: la segunda llamada no puede reescribir `revoked_at` con un
    // instante posterior. Lo que consta es cuando se retiro el acceso.
    $issued = SupportGrants::issue();

    /** @var RevokeSupportAccessHandler $handler */
    $handler = app(RevokeSupportAccessHandler::class);

    $handler->handle(new RevokeSupportAccessCommand($issued->grant->uuid, null));
    $primerInstante = DB::table('support_grants')->where('uuid', $issued->grant->uuid)->value('revoked_at');

    $handler->handle(new RevokeSupportAccessCommand($issued->grant->uuid, null));

    expect(DB::table('support_grants')->where('uuid', $issued->grant->uuid)->value('revoked_at'))
        ->toBe($primerInstante);
})->group('RF-PD-11', 'RL-04');

it('dos usos SIMULTANEOS de la misma concesion abren una sola ventana', function (): void {
    // La prueba de arriba encadena peticiones; esta las lanza A LA VEZ. La
    // atomicidad la da `Cache::add()` (SET NX en Redis), y sin una prueba que
    // la ejercite con procesos concurrentes la afirmacion «un asiento por
    // ventana» descansa en un comentario. Cada hijo abre su propia conexion a
    // Redis: la del padre no se comparte tras el `fork`.
    //
    // CONTRA REDIS DE VERDAD, no contra el `array` de phpunit.xml: con la cache
    // en memoria cada proceso tiene la suya y los ocho «ganan», que es
    // exactamente el falso verde que esta prueba existe para no dar.
    config()->set('cache.default', 'redis');
    $issued = SupportGrants::issue(scope: SupportScope::Configuration);
    $grantId = $issued->grant->id;
    expect($grantId)->toBeInt();
    Cache::store('redis')->forget('product:support-use:'.$grantId);
    $children = [];

    for ($i = 0; $i < 8; $i++) {
        $pid = pcntl_fork();

        if ($pid === -1) {
            throw new RuntimeException('No se pudo crear el proceso hijo.');
        }

        if ($pid === 0) {
            app('redis')->purge('cache');
            $recorded = app(SupportAccessRecorder::class)->shouldRecord((int) $grantId, 900);
            file_put_contents(sys_get_temp_dir().'/support-use-'.getmypid(), $recorded ? '1' : '0');
            // SIGKILL y no `exit()`: el hijo comparte con el padre el socket de
            // PDO, y un cierre ordenado mandaria el Terminate de PostgreSQL por
            // ese socket, dejando al padre sin conexion. Sin destructores, el
            // descriptor del hijo se cierra sin decir nada y el del padre sigue.
            exec('kill -9 '.getmypid());
        }

        $children[] = $pid;
    }

    $recordedBy = 0;
    foreach ($children as $pid) {
        pcntl_waitpid($pid, $status);
        $result = sys_get_temp_dir().'/support-use-'.$pid;
        if (is_file($result)) {
            $recordedBy += (int) file_get_contents($result);
            unlink($result);
        }
    }

    expect($recordedBy)->toBe(1);
})->skip(! \function_exists('pcntl_fork'), 'pcntl no disponible')->group('RF-PD-11', 'RL-04');
