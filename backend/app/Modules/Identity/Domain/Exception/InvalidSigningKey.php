<?php

declare(strict_types=1);

namespace App\Modules\Identity\Domain\Exception;

/**
 * La clave HMAC configurada no sirve para firmar (doc 02 §5.1, Anexo B).
 *
 * **Falla al arrancar la emision, no al verificar.** Una clave mal configurada
 * tiene que hacer ruido cuando RRHH emite una credencial, que es cuando alguien
 * puede corregirla; en el camino de fichaje, en cambio, se traduce en un rechazo
 * generico como cualquier otro (RS-03) y en una entrada de log de nivel `error`.
 *
 * El mensaje **no incluye la clave ni parte de ella**, solo su `key_id` y el
 * motivo.
 */
final class InvalidSigningKey extends IdentityDomainException
{
    public static function notBase64(string $keyId): self
    {
        return new self('La clave de firma "'.$keyId.'" no esta en base64.');
    }

    public static function tooShort(string $keyId, int $bytes, int $minimum): self
    {
        return new self(
            'La clave de firma "'.$keyId.'" tiene '.$bytes.' bytes y el minimo son '.$minimum.'.'
        );
    }

    public static function badKeyId(string $keyId): self
    {
        return new self('El identificador de clave "'.$keyId.'" no tiene 2 caracteres alfanumericos.');
    }

    public static function noneConfigured(): self
    {
        return new self(
            'No hay ninguna clave de firma de credenciales configurada. '
            .'Revisa QR_SIGNING_KEY_CURRENT_ID y QR_SIGNING_KEY_CURRENT (doc 02 Anexo B).'
        );
    }
}
