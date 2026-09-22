<?php

declare(strict_types=1);

use App\Modules\Identity\Application\Port\UserAccounts;
use App\Modules\Identity\Domain\ValueObject\AuthenticatedUser;
use App\Modules\Identity\Infrastructure\Persistence\User;
use App\Modules\Shared\Domain\ValueObject\UserRole;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\Support\Database\RefreshDatabase;
use Tests\Support\Identity\ManagementUsers;

/*
 * **Migracion del coste de `bcrypt` al entrar** (hallazgo **H-13** de la
 * revision interna ASVS de 2026-09, OWASP A07).
 *
 * ## Que se corrige
 *
 * El producto hashea con `bcrypt` coste 12 y lo tiene probado, pero hasta la
 * tarea 3.8 **no existia ni un solo `needsRehash` en todo `backend/app`**. El
 * coste no era el problema; el problema era que no habia camino para cambiarlo:
 * el dia que 12 se quedara corto, la instalacion no podria migrar los hashes sin
 * un restablecimiento masivo de contrasenas, es decir, sin dejar al cliente sin
 * panel durante una tarde.
 *
 * ## Por que Integration y no Unit
 *
 * Lo que se afirma es que **la fila queda reescrita**: el hash es de la base de
 * datos y la migracion ocurre en el adaptador. Sin la tabla detras no hay nada
 * que comprobar. Se ejercita el puerto {@see UserAccounts} —no el endpoint—
 * porque el camino que migra es la comprobacion de credenciales, y asi la prueba
 * no depende del limitador de `/auth/login` ni del segundo factor.
 */

uses(RefreshDatabase::class);

/**
 * El hash guardado de esa cuenta, leido por la tabla.
 *
 * Por el constructor de consultas y no por el modelo: `password` esta en
 * `$hidden` y el modelo lo castea, y aqui lo que interesa es exactamente el
 * texto que hay en la columna.
 */
function hashGuardadoDe(string $uuid): string
{
    $valor = DB::table('users')->where('uuid', $uuid)->value('password');

    return \is_string($valor) ? $valor : '';
}

it('rehashea al coste vigente la contrasena guardada con un coste inferior', function (): void {
    $user = ManagementUsers::withRole(UserRole::RRHH, 'rehash@hotel.example');

    // Una instalacion vieja: el mismo secreto, hasheado con coste 10. Se escribe
    // por la tabla para que el cast `hashed` del modelo no lo vuelva a hashear.
    $antiguo = password_hash(ManagementUsers::PASSWORD, PASSWORD_BCRYPT, ['cost' => 10]);

    DB::table('users')->where('uuid', $user->uuid)->update(['password' => $antiguo]);

    expect(hashGuardadoDe($user->uuid))->toStartWith('$2y$10$');

    $autenticado = app(UserAccounts::class)
        ->verifyCredentials('rehash@hotel.example', ManagementUsers::PASSWORD);

    // El acceso sigue siendo correcto: migrar no puede costarle la sesion a
    // nadie.
    expect($autenticado)->toBeInstanceOf(AuthenticatedUser::class);

    $migrado = hashGuardadoDe($user->uuid);

    expect($migrado)->not->toBe($antiguo)
        ->and(Hash::needsRehash($migrado))->toBeFalse()
        // Y la contrasena sigue valiendo contra el hash nuevo, que es la mitad
        // que de verdad importa: un rehash que no verifica deja a la persona
        // fuera de su cuenta en el acceso siguiente.
        ->and(Hash::check(ManagementUsers::PASSWORD, $migrado))->toBeTrue();

    expect(app(UserAccounts::class)->verifyCredentials('rehash@hotel.example', ManagementUsers::PASSWORD))
        ->toBeInstanceOf(AuthenticatedUser::class);
})->group('RS-06');

it('no toca el hash que ya esta al coste vigente', function (): void {
    // El rehash es una migracion, no un efecto de cada acceso: reescribir la fila
    // en cada entrada seria una escritura por sesion sin ningun motivo.
    $user = ManagementUsers::withRole(UserRole::ADMIN, 'estable@hotel.example');

    $antes = hashGuardadoDe($user->uuid);

    app(UserAccounts::class)->verifyCredentials('estable@hotel.example', ManagementUsers::PASSWORD);

    expect(hashGuardadoDe($user->uuid))->toBe($antes);
})->group('RS-06');

it('no rehashea nada cuando la contrasena no es la buena', function (): void {
    /*
     * **RS-03 y regla dura 17.** Un rechazo no tiene con que rehashear —no hay
     * contrasena correcta de la que partir—, y darle trabajo extra a una rama y
     * no a la otra es exactamente la asimetria medible que el camino del rechazo
     * evita. Aqui se afirma por su efecto observable: la fila no se toca.
     */
    $user = ManagementUsers::withRole(UserRole::RRHH, 'fallido@hotel.example');

    $antiguo = password_hash(ManagementUsers::PASSWORD, PASSWORD_BCRYPT, ['cost' => 10]);

    DB::table('users')->where('uuid', $user->uuid)->update(['password' => $antiguo]);

    expect(app(UserAccounts::class)->verifyCredentials('fallido@hotel.example', 'Otra-Cosa-1!'))
        ->toBeNull()
        ->and(hashGuardadoDe($user->uuid))->toBe($antiguo);
})->group('RS-06', 'RS-03');

it('no rehashea la contrasena de una cuenta desactivada', function (): void {
    // La cuenta dada de baja no entra, asi que tampoco migra: escribirle un hash
    // nuevo seria trabajo —y una escritura— por una credencial que ya no abre
    // nada.
    $user = ManagementUsers::withRole(UserRole::AUDITOR, 'inactiva@hotel.example');

    $antiguo = password_hash(ManagementUsers::PASSWORD, PASSWORD_BCRYPT, ['cost' => 10]);

    User::query()->where('uuid', $user->uuid)->update(['is_active' => false]);
    DB::table('users')->where('uuid', $user->uuid)->update(['password' => $antiguo]);

    expect(app(UserAccounts::class)->verifyCredentials('inactiva@hotel.example', ManagementUsers::PASSWORD))
        ->toBeNull()
        ->and(hashGuardadoDe($user->uuid))->toBe($antiguo);
})->group('RS-06');
