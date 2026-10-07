<?php

declare(strict_types=1);

namespace App\Modules\Identity\Domain\ValueObject;

use DateTimeImmutable;

/**
 * De quien es la contrasena vigente de una cuenta de gestion (**RF-ID-10**).
 *
 * **El estado, nunca el valor.** Es lo que viaja en `password_status` del
 * contrato y lo que decide si la cuenta puede hacer algo mas que cambiarla.
 *
 * - `Own` — la fijo su titular: en el asistente de puesta en marcha o con el
 *   cambio de la contrasena propia.
 * - `Temporary` — la genero el servidor en un alta o en un restablecimiento y
 *   su titular aun no la ha cambiado. Sirve para entrar, pero solo para
 *   cambiarla.
 * - `TemporaryExpired` — temporal y caducada: ya no sirve para entrar.
 *
 * **Se deduce de un unico dato**, el instante de caducidad de la temporal, y no
 * de una columna de estado aparte: dos columnas que dicen lo mismo acaban
 * diciendo cosas distintas, y aqui la diferencia seria una credencial
 * compartida que no caduca nunca.
 */
enum PasswordStatus: string
{
    case Own = 'own';
    case Temporary = 'temporary';
    case TemporaryExpired = 'temporary_expired';

    /**
     * El estado de una contrasena cuya temporal caduca en `$temporaryExpiresAt`
     * (`null` si no es temporal), visto en el instante `$now`.
     *
     * **La frontera exacta ya esta caducada**: en el instante de caducidad la
     * contrasena deja de servir. Es la lectura de «caduca a las 72 horas» que no
     * regala un instante de mas, y la misma que el contrato describe en
     * `expires_at` («desde este instante ya no sirve para entrar»).
     */
    public static function of(?DateTimeImmutable $temporaryExpiresAt, DateTimeImmutable $now): self
    {
        if ($temporaryExpiresAt === null) {
            return self::Own;
        }

        return $now < $temporaryExpiresAt ? self::Temporary : self::TemporaryExpired;
    }

    /**
     * Si la cuenta tiene que fijar su propia contrasena antes de hacer nada mas.
     *
     * **Tambien la caducada**: con ella ya no se entra, pero una sesion abierta
     * antes de la caducidad sigue existiendo, y lo unico que puede hacer es
     * cambiarla.
     */
    public function requiresChange(): bool
    {
        return $this !== self::Own;
    }
}
