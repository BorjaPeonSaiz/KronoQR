<?php

declare(strict_types=1);

namespace Tests\Support\Identity;

use App\Modules\Identity\Domain\ValueObject\TokenAbility;
use App\Modules\Identity\Infrastructure\Persistence\User;
use App\Modules\Shared\Domain\ValueObject\UserRole;
use Illuminate\Support\Str;
use Laravel\Sanctum\PersonalAccessToken;
use PragmaRX\Google2FA\Google2FA;
use Spatie\Permission\Models\Permission;
use Tests\Support\Attendance\AttendanceFixtures;

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

    /**
     * Secreto TOTP de las pruebas, en base32 y con la longitud que emite el
     * producto. Es un valor de prueba y no una credencial: no vale en ninguna
     * instalacion, porque el secreto de cada cuenta se genera al darla de alta.
     */
    public const string TOTP_SECRET = 'JBSWY3DPEHPK3PXPJBSWY3DPEHPK3PXP';

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
     * Los ambitos con los que se emitia el token de un `auditor` hasta la 2.2.1
     * (RF-ID-02, bloque 25; doc 02 §7.3, nota 9).
     *
     * Un token de sesion conserva sus ambitos hasta que caduca (12 h), asi que el
     * dia del despliegue habra auditores con este token en la mano. Lo que les
     * cierra la puerta entonces son las policies, por rol. Escrito una vez aqui
     * para que las pruebas de ese caso y la de la migracion digan lo mismo.
     *
     * @return list<string>
     */
    public static function legacyAuditorAbilities(): array
    {
        return ['attendance:read', 'audit:read', 'reports:legal'];
    }

    /** Token de un `auditor` emitido con los ambitos anteriores a la 2.2.1. */
    public static function legacyAuditorTokenFor(User $user): string
    {
        return $user->createToken('Pruebas', self::legacyAuditorAbilities())->plainTextToken;
    }

    /**
     * Token de una **cuenta de `users` con rol `kiosk`, que no es un quiosco**.
     *
     * El producto no la emite: el quiosco de verdad es un dispositivo
     * emparejado, y su token es
     * {@see AttendanceFixtures::kioskToken()}. Esta
     * existe para el caso que la policy cubre y el ambito no: una cuenta que
     * lleva `scan:write` y pasa el middleware `ability`, y a la que solo detiene
     * que su `tokenable` no es una fila de `devices` (RF-ID-04). Fuera de esas
     * pruebas, el portador del quiosco es el dispositivo (A3).
     */
    public static function kioskRoleAccountToken(): string
    {
        $device = self::withRole(UserRole::KIOSK);

        return self::tokenFor($device);
    }

    public static function tokenIdOf(string $plainTextToken): int
    {
        $token = PersonalAccessToken::findToken($plainTextToken);

        return $token instanceof PersonalAccessToken ? $token->id : 0;
    }

    /**
     * Deja a la cuenta con un **segundo factor ya activo** y devuelve su secreto
     * (RS-06).
     *
     * Escribe por el modelo y no por el puerto para que la prueba no dependa de
     * la maquinaria que esta comprobando: lo que se ejercita es el acceso, no la
     * persistencia del secreto.
     */
    public static function withActiveSecondFactor(User $user, string $secret = self::TOTP_SECRET): string
    {
        $user->two_factor_secret = $secret;
        $user->two_factor_confirmed_at = now();
        $user->two_factor_last_slice = null;
        $user->save();

        return $secret;
    }

    /**
     * Un token de **sesion pendiente de segundo factor**, como el que devuelve el
     * `202` de `POST /api/v1/auth/login`.
     *
     * Un unico ambito, `2fa:pending`, igual que el que emite
     * `SanctumAccessTokenIssuer::issuePendingFor()`. Es lo que permite probar que
     * ese token no alcanza ningun otro endpoint del producto.
     */
    public static function pendingTokenFor(User $user, string $deviceName = 'Panel de gestion'): string
    {
        return $user->createToken($deviceName, [TokenAbility::TWO_FACTOR_PENDING->value])->plainTextToken;
    }

    /**
     * El codigo TOTP valido **en este instante** para ese secreto.
     *
     * Se calcula con la misma libreria que verifica el servidor: una prueba que
     * escribiera el codigo a mano estaria comprobando su propia aritmetica.
     */
    public static function totpCodeFor(string $secret): string
    {
        return (new Google2FA)->getCurrentOtp($secret);
    }
}
