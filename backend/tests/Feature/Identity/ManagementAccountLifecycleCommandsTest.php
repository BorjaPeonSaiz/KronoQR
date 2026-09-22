<?php

declare(strict_types=1);

use App\Modules\Compliance\Domain\ValueObject\AuditAction;
use App\Modules\Identity\Infrastructure\Persistence\User;
use App\Modules\Shared\Domain\ValueObject\UserRole;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Tests\Feature\Quality\Support\Commands;
use Tests\Support\Database\RefreshDatabase;
use Tests\Support\Http\Api;
use Tests\Support\Identity\ManagementUsers;

/*
 * `identity:deactivate-user` e `identity:reset-password` — el ciclo de vida de
 * una cuenta de gestion que el producto no tenia (**RS-05**, **RS-06**,
 * **RL-16**; hallazgo **H-03** de la revision interna ASVS de 2026-09).
 *
 * ## Que faltaba
 *
 * `users.is_active` se consultaba al autenticar desde la Fase 1, pero **las dos
 * unicas escrituras del campo en todo `backend/app` lo ponian a `true`**. No
 * habia endpoint, ni comando, ni pantalla: dar de baja a alguien exigia editar
 * la fila a mano en PostgreSQL, fuera del producto y por tanto fuera del trail.
 * Tampoco habia forma de rotar una contrasena ya fijada. El control
 * compensatorio —la fila 17 de `docs/cliente/endurecimiento.md`: revisar las
 * cuentas cada trimestre y contrastarlas con la lista de personal— hacia el uso
 * indebido **detectable y no repudiable, no impedido**.
 *
 * ## Por que consola y no API
 *
 * El Anexo B del doc 01 no tiene ninguna ruta de gestion de usuarios, y «da de
 * baja a esta persona» o «cambiale la contrasena» por API serian, en manos de un
 * `admin` comprometido, la forma mas comoda de quedarse solo en la instalacion o
 * de prepararse el acceso a la cuenta de otro. Es el mismo canal por el que hoy
 * se crean cuentas (`identity:create-user`) y se retira un segundo factor
 * (`identity:2fa-reset`). La pantalla del panel es una decision de producto
 * pendiente (ficha 3.8, decision 16).
 */

uses(RefreshDatabase::class);

beforeEach(function (): void {
    // RS-06: el valor de serie obliga a `admin`, `rrhh` y `auditor`. Lo que se
    // prueba aqui es la baja y la rotacion de contrasena, no el reto, asi que se
    // vacia la lista para poder comprobar un acceso con la contrasena nueva sin
    // resolver un TOTP. Que sea configuracion y no una constante es lo que lo
    // permite (regla dura 13).
    config()->set('identity.two_factor.required_roles', []);

    // El limitador de `/auth/login` cuenta por origen y su contador vive en la
    // cache: se limpia para que una prueba no herede el cupo de otra.
    RateLimiter::clear('auth-ip:127.0.0.1');
});

/**
 * El cuerpo del alta publica del primer administrador, para comprobar que sigue
 * cerrada. Se declara aqui y no se importa de `SetupWizardTest`: las funciones
 * de un fichero de Pest son globales, y compartirlas entre suites acopla dos
 * ficheros que no tienen nada que ver.
 *
 * @return array<string, string>
 */
function altaDelPrimerAdministrador(): array
{
    return [
        'name' => 'Direccion del hotel',
        'email' => 'direccion@hotel.example',
        'password' => 'Una-Contrasena-Larga-1!',
        'locale' => 'es',
        'device_name' => 'Panel de gestion',
    ];
}

/**
 * La contrasena que el comando acaba de generar, leida de su salida.
 *
 * Va sola en su linea para que se pueda copiar sin arrastrar nada mas, y esta
 * funcion depende de eso a proposito: si alguien la pegara junto a un texto, la
 * prueba dejaria de encontrarla y seria una decision, no un descubrimiento en el
 * servidor de un cliente.
 */
function contrasenaGeneradaEn(string $output): string
{
    $lineas = array_map(trim(...), explode("\n", $output));

    $candidatas = array_values(array_filter(
        $lineas,
        static fn (string $linea): bool => \strlen($linea) >= 20 && ! str_contains($linea, ' '),
    ));

    return $candidatas[0] ?? '';
}

it('da de baja la cuenta y lo deja escrito en audit_log, sin nombres ni correos', function (): void {
    $user = ManagementUsers::withRole(UserRole::RRHH);

    [$exit, $output] = Commands::run(
        'identity:deactivate-user '.$user->email.' --reason="Baja al cierre de temporada"'
    );

    expect($exit)->toBe(0)
        // Regla dura 21: este comando se ejecuta con la salida redirigida a un
        // fichero de operacion tan a menudo como `identity:create-user`.
        ->and($output)->not->toContain($user->name)
        ->and($output)->not->toContain($user->email);

    $fila = DB::table('users')->where('uuid', $user->uuid)->first();

    // No se borra (regla dura 5): la fila sigue ahi, con su historial.
    expect($fila)->not->toBeNull()
        ->and($fila?->is_active)->toBeFalse();

    $asiento = DB::table('audit_log')
        ->where('action', AuditAction::ManagementAccountDeactivated->value)
        ->orderByDesc('id')
        ->first();

    expect($asiento)->not->toBeNull();

    $payload = (string) json_encode($asiento);

    expect($payload)->toContain($user->uuid)
        ->and($payload)->toContain('Baja al cierre de temporada')
        ->and($payload)->not->toContain($user->email)
        ->and($payload)->not->toContain($user->name)
        // Sin sesion detras, el actor es el sistema: atribuirselo a la ultima
        // persona que entro al panel seria falsificar el trail.
        ->and($asiento?->actor_type)->toBe('system');
})->group('RS-05', 'RS-06', 'RL-16');

it('deja sin valor la sesion abierta de la cuenta desactivada en la peticion siguiente', function (): void {
    /*
     * **La mitad que se olvida de una baja.** Marcar `is_active` a `false` y
     * dejar viva la sesion abierta en una tablet no da de baja a nadie durante
     * las doce horas siguientes, que es justo el tiempo que importa cuando la
     * baja es por sospecha y no por calendario.
     *
     * Se cierra por dos caminos a proposito, y esta prueba ejercita los dos a la
     * vez: el comando **revoca todos los tokens** de la cuenta, y ademas el
     * callback de Sanctum consulta `is_active` en cada peticion.
     */
    $user = ManagementUsers::withRole(UserRole::ADMIN);

    $sesion = ManagementUsers::tokenFor($user);
    $reto = ManagementUsers::pendingTokenFor($user);

    Api::as($sesion)->get('/api/v1/auth/me')->assertStatus(200);

    [$exit] = Commands::run('identity:deactivate-user '.$user->email.' --reason="Cuenta comprometida"');

    expect($exit)->toBe(0);

    Api::as($sesion)->get('/api/v1/auth/me')->assertStatus(401);
    Api::as($reto)->post('/api/v1/auth/2fa/verify', ['code' => '000000'])->assertStatus(401);

    expect(DB::table('personal_access_tokens')
        ->where('tokenable_type', $user->getMorphClass())
        ->where('tokenable_id', $user->id)
        ->count())->toBe(0);
})->group('RS-05', 'RS-06', 'RL-16');

it('impide entrar con la contrasena correcta a una cuenta dada de baja', function (): void {
    $user = ManagementUsers::withRole(UserRole::RRHH, 'baja@hotel.example');

    Commands::run('identity:deactivate-user '.$user->email.' --reason="Fin de contrato"');

    // Un solo `401` para las tres causas —correo desconocido, contrasena mala y
    // cuenta desactivada—: distinguirlas convertiria el acceso al panel en un
    // comprobador de cuentas de la empresa (RS-03).
    Api::guest()->post('/api/v1/auth/login', [
        'email' => 'baja@hotel.example',
        'password' => ManagementUsers::PASSWORD,
        'device_name' => 'Panel de gestion',
    ])->assertStatus(401);
})->group('RS-05', 'RS-06', 'RL-16');

it('se niega y no escribe asiento si la cuenta ya estaba dada de baja', function (): void {
    // El trail cuenta HECHOS. Repetir el comando no cambia nada, y un asiento por
    // cada repeticion seria una via para llenar la cadena de ADR-010 —por la que
    // pasa cada fichaje— con escrituras que no dicen nada nuevo.
    $user = ManagementUsers::withRole(UserRole::AUDITOR);

    [$primero] = Commands::run('identity:deactivate-user '.$user->email.' --reason="Baja"');
    [$segundo, $salida] = Commands::run('identity:deactivate-user '.$user->email.' --reason="Baja"');

    expect($primero)->toBe(0)
        ->and($segundo)->toBe(1)
        // El mensaje distingue «ya estaba de baja» de «no existe»: confundirlos
        // mandaria al operador a buscar una errata en el correo que no hay.
        ->and($salida)->toContain('ya estaba dada de baja')
        ->and(DB::table('audit_log')
            ->where('action', AuditAction::ManagementAccountDeactivated->value)
            ->count())->toBe(1);
})->group('RS-05', 'RS-06', 'RL-16');

it('falla sin escribir nada cuando no hay ninguna cuenta con ese correo', function (): void {
    [$exit] = Commands::run('identity:deactivate-user nadie@hotel.example');

    expect($exit)->toBe(1)
        ->and(DB::table('audit_log')
            ->where('action', AuditAction::ManagementAccountDeactivated->value)
            ->count())->toBe(0);
})->group('RS-05', 'RS-06');

it('no reabre el alta publica del primer administrador al dar de baja al unico que hay', function (): void {
    /*
     * **La cautela que convierte una tarea rutinaria de RRHH en una escalada de
     * privilegios si se hace mal.** `POST /api/v1/setup/administrator` es la
     * unica escritura publica del producto y su unica guarda es que no exista
     * **ninguna** cuenta de gestion, activa o desactivada. Si contara solo las
     * activas, dar de baja a la ultima persona con acceso dejaria la creacion de
     * un administrador abierta a quien alcanzara la red del hotel.
     *
     * `SetupWizardTest` ya lo afirma poniendo `is_active` a mano; esta lo afirma
     * por el camino que ahora existe de verdad, que es el comando.
     */
    $user = ManagementUsers::withRole(UserRole::ADMIN);

    [$exit] = Commands::run('identity:deactivate-user '.$user->email.' --reason="Baja"');

    expect($exit)->toBe(0)
        ->and(User::query()->where('is_active', true)->count())->toBe(0);

    Api::guest()->post('/api/v1/setup/administrator', altaDelPrimerAdministrador())->assertStatus(409);

    expect(User::query()->count())->toBe(1);
})->group('RS-05', 'RS-06', 'RL-16', 'RF-PD-03');

it('restablece la contrasena, la enseña una sola vez y deja el asiento', function (): void {
    $user = ManagementUsers::withRole(UserRole::RRHH, 'jefatura@hotel.example');

    [$exit, $output] = Commands::run('identity:reset-password '.$user->email);

    $nueva = contrasenaGeneradaEn($output);

    expect($exit)->toBe(0)
        ->and($nueva)->not->toBe('')
        // Regla dura 21, tambien aqui: ni el nombre ni el correo en la salida.
        ->and($output)->not->toContain($user->name)
        ->and($output)->not->toContain($user->email);

    // La contrasena nueva vale...
    Api::guest()->post('/api/v1/auth/login', [
        'email' => 'jefatura@hotel.example',
        'password' => $nueva,
        'device_name' => 'Panel de gestion',
    ])->assertStatus(200);

    RateLimiter::clear('auth-ip:127.0.0.1');

    // ...y la anterior ya no.
    Api::guest()->post('/api/v1/auth/login', [
        'email' => 'jefatura@hotel.example',
        'password' => ManagementUsers::PASSWORD,
        'device_name' => 'Panel de gestion',
    ])->assertStatus(401);

    $asiento = DB::table('audit_log')
        ->where('action', AuditAction::ManagementPasswordReset->value)
        ->orderByDesc('id')
        ->first();

    expect($asiento)->not->toBeNull();

    $payload = (string) json_encode($asiento);

    // El uuid si; la contrasena, el correo y el nombre, nunca. El hecho auditable
    // es que la credencial se sustituyo, no cual es.
    expect($payload)->toContain($user->uuid)
        ->and($payload)->not->toContain($nueva)
        ->and($payload)->not->toContain($user->email)
        ->and($payload)->not->toContain($user->name)
        ->and($asiento?->actor_type)->toBe('system');
})->group('RS-05', 'RS-06', 'RL-16');

it('genera una contrasena que cumple la politica de robustez de RF-ID-01', function (): void {
    // Generada y no elegida: quien la fija no la va a usar, y una contrasena
    // pensada por un tecnico acaba siendo la misma en las cuatro instalaciones
    // que atiende. Lo que no puede es quedarse por debajo de la politica que el
    // propio producto exige al FIJAR una contrasena.
    $user = ManagementUsers::withRole(UserRole::ADMIN);

    [, $output] = Commands::run('identity:reset-password '.$user->email);

    $nueva = contrasenaGeneradaEn($output);

    expect(\strlen($nueva))->toBeGreaterThanOrEqual(
        max(20, config()->integer('identity.password.min_length'))
    )
        ->and($nueva)->toMatch('/[a-z]/')
        ->and($nueva)->toMatch('/[A-Z]/')
        ->and($nueva)->toMatch('/[0-9]/')
        ->and($nueva)->toMatch('/[^a-zA-Z0-9]/')
        // Sin los caracteres que se confunden al leerlos de una pantalla y
        // teclearlos a mano: se entrega en persona y de viva voz.
        ->and($nueva)->not->toMatch('/[lIO01]/');
})->group('RS-06', 'RF-ID-01');

it('cierra las sesiones abiertas al restablecer la contrasena', function (): void {
    // De los dos motivos por los que se ejecuta esto —olvido y sospecha—, en el
    // que importa quien esta dentro lleva una sesion viva de hasta doce horas.
    // Cambiarle la contrasena sin cerrarla le retiraria una credencial que ya no
    // necesita.
    $user = ManagementUsers::withRole(UserRole::ADMIN);

    $sesion = ManagementUsers::tokenFor($user);

    Api::as($sesion)->get('/api/v1/auth/me')->assertStatus(200);

    [$exit] = Commands::run('identity:reset-password '.$user->email);

    expect($exit)->toBe(0);

    Api::as($sesion)->get('/api/v1/auth/me')->assertStatus(401);
})->group('RS-05', 'RS-06');

it('no restablece la contrasena de una cuenta dada de baja', function (): void {
    // Cambiarle la contrasena a una cuenta desactivada no le devuelve el acceso,
    // asi que hacerlo daria a entender lo contrario a quien lo ejecuta.
    $user = ManagementUsers::withRole(UserRole::RRHH);

    Commands::run('identity:deactivate-user '.$user->email.' --reason="Baja"');

    [$exit] = Commands::run('identity:reset-password '.$user->email);

    expect($exit)->toBe(1)
        ->and(DB::table('audit_log')
            ->where('action', AuditAction::ManagementPasswordReset->value)
            ->count())->toBe(0);
})->group('RS-06');
