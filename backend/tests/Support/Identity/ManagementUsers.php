<?php

declare(strict_types=1);

namespace Tests\Support\Identity;

use App\Modules\Identity\Infrastructure\Persistence\User;
use App\Modules\Shared\Domain\ValueObject\UserRole;
use Illuminate\Support\Str;
use Laravel\Sanctum\PersonalAccessToken;
use Spatie\Permission\Models\Permission;

/**
 * Cuentas de gestion para las pruebas de feature.
 *
 * Existe para que una prueba de autorizacion se lea como lo que comprueba —«un
 * auditor no puede dar de alta»— y no como cinco lineas de creacion de filas. Es
 * lo que pide el §3.5 sobre codigo de pruebas: factorias legibles que dejan
 * claro que caso se esta probando.
 *
 * **Los roles no se crean aqui**: los siembra la migracion del catalogo, que se
 * ejecuta con `RefreshDatabase` igual que en una instalacion real. Si esa
 * migracion se rompiera, estas pruebas fallarian, que es exactamente lo que se
 * quiere.
 */
final class ManagementUsers
{
    public const string PASSWORD = 'Contrasena-De-Prueba-1!';

    public static function withRole(UserRole $role, ?string $email = null): User
    {
        $user = User::query()->create([
            'uuid' => Str::uuid7()->toString(),
            'name' => 'Cuenta de prueba '.$role->value,
            'email' => $email ?? $role->value.'-'.Str::lower(Str::random(6)).'@kronoqr.test',
            'password' => self::PASSWORD,
            'locale' => 'es',
            'is_active' => true,
        ]);

        $user->assignRole($role->value);

        return $user;
    }

    /**
     * Token de sesion con los ambitos que le corresponden al rol, igual que los
     * emitiria `POST /api/v1/auth/login`.
     */
    public static function tokenFor(User $user): string
    {
        $abilities = [];

        /** @var Permission $permission */
        foreach ($user->getAllPermissions() as $permission) {
            $abilities[] = $permission->name;
        }

        return $user->createToken('Pruebas', $abilities)->plainTextToken;
    }

    /**
     * Token de **quiosco**: el que llevaria una tablet (RF-ID-04).
     *
     * Cuelga de una cuenta con rol `kiosk` en lugar de un dispositivo porque los
     * dispositivos son de la tarea 1.5. Lo que importa para la prueba de RS-04
     * es el ambito del token —`scan:write`, `roster:read`, `heartbeat:write`— y
     * ese es exactamente el que lleva: si aun asi alcanzara un endpoint de
     * gestion, el fallo seria real.
     */
    public static function kioskToken(): string
    {
        $device = self::withRole(UserRole::KIOSK);

        return self::tokenFor($device);
    }

    public static function tokenIdOf(string $plainTextToken): int
    {
        $token = PersonalAccessToken::findToken($plainTextToken);

        return $token instanceof PersonalAccessToken ? $token->id : 0;
    }
}
