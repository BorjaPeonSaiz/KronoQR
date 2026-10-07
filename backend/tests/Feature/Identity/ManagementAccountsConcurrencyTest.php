<?php

declare(strict_types=1);

use App\Modules\Identity\Infrastructure\Persistence\User;
use App\Modules\Shared\Domain\ValueObject\UserRole;
use Illuminate\Support\Facades\DB;
use Tests\Support\Concurrency\ParallelRequests;
use Tests\Support\Database\CommittedDatabase;
use Tests\Support\Http\Api;
use Tests\Support\Identity\ManagementUsers;
use Tests\Support\Product\LicenseKeys;
use Tests\Support\Time\FrozenTime;
use Tests\Support\Workforce\WorkforceFixtures;

/*
 * Las cuentas de gestion BAJO CONCURRENCIA (**RF-ID-10**, ADR-051 §6, RS-06,
 * RL-04).
 *
 * Cada escritura de cuentas pasa por el orden unico de candados del producto
 * —cadena de auditoria → padron (`ManagementAccountRosterLock`) → fila de
 * `users`— y el cambio de responsable de un departamento por fila del
 * departamento → cadena. Lo que se vigila aqui es lo que ninguna prueba en un
 * solo proceso puede ver:
 *
 *   - que dos `admin` que se dan de baja la una a la otra no dejan la
 *     instalacion sin `admin` activa;
 *   - que dos altas con el mismo correo dejan una cuenta y no dos;
 *   - que dos restablecimientos del segundo factor sobre la misma cuenta dejan
 *     un asiento y no se abrazan (un `40P01` sale como `500`).
 *
 * La baja contra la asignacion de responsable NO esta aqui: por HTTP la
 * asignacion es tan rapida que la baja no la pilla a medias ni sin candados
 * (medido el 07-10-2026). Va en `ManagementAccountLockOrderTest`
 * (Integration), determinista, junto con el orden de candados que impide el
 * abrazo.
 *
 * **Procesos de verdad** (`ParallelRequests`): en un bucle dentro de un proceso
 * las peticiones van en fila y estas pruebas pasarian sin candado. Medido
 * quitando la cadena y el padron de la baja y del restablecimiento del segundo
 * factor (07-10-2026): la pareja y el corro de bajas acaban sin ninguna `admin`
 * y los cuatro restablecimientos responden `200`. El alta sin la cadena NO cayo
 * en la pasada medida (el hash de la temporal escalona las peticiones); si dos
 * se cruzaran, el indice unico `users_email_unique` cortaria la segunda con un
 * `500`, que esta prueba tambien rechaza: su verde es la invariante, no la
 * prueba de que la carrera ocurre.
 */

uses(CommittedDatabase::class);

const MANAGEMENT_ACCOUNTS_CONCURRENCY_RING = 5;

const MANAGEMENT_ACCOUNTS_CONCURRENCY_CREATIONS = 4;

const MANAGEMENT_ACCOUNTS_CONCURRENCY_RESETS = 4;

beforeEach(function (): void {
    WorkforceFixtures::site();
    LicenseKeys::install();
    config()->set('identity.two_factor.required_roles', []);

    FrozenTime::at('2026-10-07 09:00:00');
});

/**
 * Una `admin` con su sesion, con los ambitos de su rol (incluido `accounts:*`).
 *
 * @return array{user: User, token: string}
 */
function adminConcurrente(): array
{
    $admin = ManagementUsers::withRole(UserRole::ADMIN);

    return ['user' => $admin, 'token' => ManagementUsers::tokenFor($admin)];
}

/**
 * Los codigos de respuesta, ordenados: el orden de llegada no es determinista.
 *
 * @param  list<array{status: int, body: mixed}>  $respuestas
 * @return list<int>
 */
function codigosConcurrentes(array $respuestas): array
{
    $codigos = array_column($respuestas, 'status');
    sort($codigos);

    return $codigos;
}

function asientosConcurrentes(string $accion): int
{
    return DB::table('audit_log')->where('action', $accion)->count();
}

function adminsActivasConcurrentes(): int
{
    return DB::table('users')
        ->join('model_has_roles', 'model_has_roles.model_id', '=', 'users.id')
        ->join('roles', 'roles.id', '=', 'model_has_roles.role_id')
        ->where('roles.name', UserRole::ADMIN->value)
        ->where('users.is_active', true)
        ->count();
}

it('deja exactamente una admin activa cuando dos se dan de baja la una a la otra a la vez', function (): void {
    $ana = adminConcurrente();
    $berta = adminConcurrente();
    $peticiones = [
        [$ana['token'], $berta['user']->uuid],
        [$berta['token'], $ana['user']->uuid],
    ];

    $respuestas = ParallelRequests::run(2, static fn (int $indice) => Api::as($peticiones[$indice][0])
        ->post('/api/v1/management-accounts/'.$peticiones[$indice][1].'/deactivate', ['reason' => 'Carrera de bajas']));

    // Quien pierde encuentra a la otra como ultima admin (409) o ya no tiene
    // sesion porque la baja se la cerro antes de autenticarla (401). Nunca un
    // 500: un abrazo de candados lo seria.
    expect(codigosConcurrentes($respuestas))->toBeIn([[200, 409], [200, 401]])
        ->and(adminsActivasConcurrentes())->toBe(1)
        ->and(asientosConcurrentes('user.deactivated'))->toBe(1);
})->group('RF-ID-10', 'RS-06', 'RL-04');

it('nunca deja la instalacion sin admin aunque cinco se den de baja en corro a la vez', function (): void {
    $admins = array_map(static fn (): array => adminConcurrente(), range(1, MANAGEMENT_ACCOUNTS_CONCURRENCY_RING));

    // Cada una da de baja a la siguiente, y la ultima a la primera.
    $respuestas = ParallelRequests::run(
        MANAGEMENT_ACCOUNTS_CONCURRENCY_RING,
        static fn (int $indice) => Api::as($admins[$indice]['token'])->post(
            '/api/v1/management-accounts/'.$admins[($indice + 1) % MANAGEMENT_ACCOUNTS_CONCURRENCY_RING]['user']->uuid.'/deactivate',
            ['reason' => 'Corro de bajas'],
        ),
    );

    $bajas = \count(array_keys(array_column($respuestas, 'status'), 200, true));

    expect(array_diff(array_column($respuestas, 'status'), [200, 401, 409]))->toBe([])
        ->and(adminsActivasConcurrentes())->toBeGreaterThanOrEqual(1)
        // Cada baja que respondio 200 es una cuenta menos y un asiento mas, ni
        // uno perdido ni uno de sobra.
        ->and(adminsActivasConcurrentes())->toBe(MANAGEMENT_ACCOUNTS_CONCURRENCY_RING - $bajas)
        ->and(asientosConcurrentes('user.deactivated'))->toBe($bajas);
})->group('RF-ID-10', 'RS-06', 'RL-04');

it('da de alta una sola cuenta cuando llegan a la vez varias altas con el mismo correo', function (): void {
    $admin = adminConcurrente();
    $correos = ['doble@hotel.example', 'DOBLE@hotel.example', 'Doble@Hotel.example', 'doble@HOTEL.EXAMPLE'];

    $respuestas = ParallelRequests::run(
        MANAGEMENT_ACCOUNTS_CONCURRENCY_CREATIONS,
        static fn (int $indice) => Api::as($admin['token'])->post('/api/v1/management-accounts', [
            'name' => 'Cuenta duplicada '.$indice,
            'email' => $correos[$indice],
            'role' => 'rrhh',
            'actor_current_password' => ManagementUsers::PASSWORD,
        ]),
    );

    expect(codigosConcurrentes($respuestas))->toBe([201, 409, 409, 409])
        ->and(DB::table('users')->whereRaw('lower(email) = ?', ['doble@hotel.example'])->count())->toBe(1)
        ->and(asientosConcurrentes('user.created'))->toBe(1)
        ->and(asientosConcurrentes('role_assignment.changed'))->toBe(1);
})->group('RF-ID-10', 'RS-06', 'RL-04');
it('retira el segundo factor una sola vez con un solo asiento cuando llegan varios restablecimientos a la vez', function (): void {
    $admin = adminConcurrente();
    $rrhh = ManagementUsers::withRole(UserRole::RRHH);
    ManagementUsers::withActiveSecondFactor($rrhh);

    $respuestas = ParallelRequests::run(
        MANAGEMENT_ACCOUNTS_CONCURRENCY_RESETS,
        static fn () => Api::as($admin['token'])->post(
            '/api/v1/management-accounts/'.$rrhh->uuid.'/two-factor/reset',
            ['reason' => 'Telefono extraviado', 'actor_current_password' => ManagementUsers::PASSWORD],
        ),
    );

    // El primero lo retira; los demas lo encuentran ya sin segundo factor
    // (409). Ningun 500: es como sale un `deadlock detected`.
    expect(codigosConcurrentes($respuestas))->toBe([200, 409, 409, 409])
        ->and(asientosConcurrentes('auth.two_factor_reset'))->toBe(1)
        ->and(DB::table('users')->where('id', $rrhh->id)->value('two_factor_confirmed_at'))->toBeNull();
})->group('RF-ID-10', 'RS-06', 'RL-04');
