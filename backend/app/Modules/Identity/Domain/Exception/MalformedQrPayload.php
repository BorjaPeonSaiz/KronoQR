<?php

declare(strict_types=1);

namespace App\Modules\Identity\Domain\Exception;

/**
 * El texto leido del QR no tiene la forma `FH1.<key_id>.<token>.<sig>` del
 * doc 02 §5.1.
 *
 * **Su mensaje no sale nunca por la API** (RS-03, regla dura 17). Decir «el
 * prefijo no es FH1» o «el token mide 21 caracteres» a quien esta probando
 * payloads le ahorra el trabajo de descubrir el formato. El detalle es para el
 * log del servidor; el quiosco recibe el mismo rechazo generico que en los
 * demas casos.
 *
 * Por eso el constructor no acepta el payload recibido: un mensaje que lo
 * incluyera acabaria en un log, y ahi puede haber una tarjeta valida de alguien.
 */
final class MalformedQrPayload extends IdentityDomainException
{
    public static function badFormat(): self
    {
        return new self('El payload QR no tiene la forma FH1.<key_id>.<token>.<sig>.');
    }

    public static function badPrefix(): self
    {
        return new self('El payload QR no empieza por el prefijo del esquema.');
    }

    public static function badKeyId(): self
    {
        return new self('El identificador de clave del payload QR no tiene la forma esperada.');
    }

    public static function badToken(): self
    {
        return new self('El token del payload QR no tiene la forma esperada.');
    }

    public static function badSignature(): self
    {
        return new self('La firma del payload QR no tiene la forma esperada.');
    }
}
