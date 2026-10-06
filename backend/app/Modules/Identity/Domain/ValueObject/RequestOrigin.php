<?php

declare(strict_types=1);

namespace App\Modules\Identity\Domain\ValueObject;

use InvalidArgumentException;

/**
 * **De donde viene una peticion**, a efectos del bloqueo por origen del portal
 * (RS-12, ADR-050 §2).
 *
 * - Una IPv4 es su `/32`: la propia direccion.
 * - Una IPv6 es su **`/64`**: cualquier conexion domestica recibe un `/64`
 *   entero, y contar por direccion completa no frenaria a nadie que rote los 64
 *   bits bajos.
 * - Una IPv4 mapeada en IPv6 (`::ffff:203.0.113.7`) es esa IPv4: es la misma
 *   maquina vista por una pila de doble protocolo.
 *
 * Se construye desde la direccion que nginx entrega a PHP-FPM (`REMOTE_ADDR`, ya
 * corregida por `real_ip` cuando hay un proxy de confianza). La aplicacion
 * **nunca** lee `X-Forwarded-For`: eso es cosa del borde.
 *
 * **La clave lleva la direccion en claro**, y por eso no sale de aqui hacia
 * ningun log ni ningun `payload`: quien la escribe en un rastro la pasa antes
 * por el seudonimo de la instalacion (`ip_hash`, regla dura 21). Vive solo como
 * clave de la cache del contador, que no viaja en el paquete de diagnostico.
 *
 * Una direccion ilegible —no deberia ocurrir: PHP-FPM siempre la tiene— cae en
 * {@see self::unknown()}, un origen unico compartido. Es el lado seguro: quien
 * consiguiera llegar sin direccion comparte cubo con todos los demas como el,
 * en vez de saltarse la cuenta.
 */
final readonly class RequestOrigin
{
    private const string UNKNOWN = 'unknown';

    /** Los doce bytes que preceden a una IPv4 mapeada en IPv6 (`::ffff:0:0/96`). */
    private const string IPV4_MAPPED_PREFIX = "\0\0\0\0\0\0\0\0\0\0\xff\xff";

    private function __construct(private string $key) {}

    /**
     * @throws InvalidArgumentException si no es una direccion IPv4 ni IPv6
     */
    public static function of(string $address): self
    {
        $address = trim($address);

        if (filter_var($address, FILTER_VALIDATE_IP) === false) {
            throw new InvalidArgumentException('No es una direccion IPv4 ni IPv6.');
        }

        $packed = (string) inet_pton($address);

        if (\strlen($packed) === 16 && str_starts_with($packed, self::IPV4_MAPPED_PREFIX)) {
            $packed = substr($packed, 12);
        }

        if (\strlen($packed) === 4) {
            return new self((string) inet_ntop($packed));
        }

        // Los 64 bits altos, el resto a cero.
        return new self(inet_ntop(substr($packed, 0, 8).str_repeat("\0", 8)).'/64');
    }

    /** La direccion de la peticion, o el origen comun si no la hay o no se entiende. */
    public static function fromRemoteAddress(?string $address): self
    {
        if ($address === null || $address === '') {
            return self::unknown();
        }

        try {
            return self::of($address);
        } catch (InvalidArgumentException) {
            return self::unknown();
        }
    }

    public static function unknown(): self
    {
        return new self(self::UNKNOWN);
    }

    /**
     * La forma normalizada: `203.0.113.7` o `2001:db8:1:2::/64`.
     *
     * **En claro**: solo para la clave de cache y para el seudonimo. Nunca a un
     * log ni a un `payload` tal cual.
     */
    public function key(): string
    {
        return $this->key;
    }

    public function equals(self $other): bool
    {
        return $this->key === $other->key;
    }
}
