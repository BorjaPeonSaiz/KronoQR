<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Rule;

use App\Modules\Identity\Domain\Policy\ManagementPasswordLength;
use Closure;
use Illuminate\Validation\Rules\Password;

/**
 * La politica de robustez de RF-ID-01, **escrita una vez** (RF-ID-10).
 *
 * Se aplica donde una persona FIJA su contrasena: el primer administrador del
 * asistente y el cambio de la contrasena propia. Las temporales la cumplen por
 * construccion (las genera el servidor). Antes vivia copiada en el comando de
 * alta y en la peticion del asistente.
 *
 * - **Minimo configurable** (`IDENTITY_PASSWORD_MIN_LENGTH`, regla dura 13),
 *   recortado a [8, 72]: ni el `.env` de un cliente puede bajar de 8, ni subir
 *   por encima de lo que `bcrypt` llega a leer.
 * - **Letras, mayusculas y minusculas, cifras y simbolos.**
 * - **Como mucho 72 bytes en UTF-8**: `bcrypt` ignora lo que pasa de ahi, asi
 *   que una contrasena mas larga se rechaza en vez de truncarse en silencio. Con
 *   caracteres no ASCII caben menos de 72 caracteres, y por eso se mide en bytes.
 * - **Sin `uncompromised()`**: consulta un servicio externo y el producto se
 *   instala sin salida a internet (ADR-016).
 */
final class ManagementPasswordPolicy
{
    public const int FLOOR = ManagementPasswordLength::FLOOR;

    public const int MAX_BYTES = ManagementPasswordLength::MAX_BYTES;

    /**
     * @return list<mixed>
     */
    public static function rules(): array
    {
        return [
            'string',
            static function (string $attribute, mixed $value, Closure $fail): void {
                if (\is_string($value) && \strlen($value) > self::MAX_BYTES) {
                    $fail(__('accounts.password_too_long', ['max' => self::MAX_BYTES]));
                }
            },
            Password::min(self::minLength())->letters()->mixedCase()->numbers()->symbols(),
        ];
    }

    public static function minLength(): int
    {
        return ManagementPasswordLength::minimum(config()->integer('identity.password.min_length'));
    }
}
