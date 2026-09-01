<?php

declare(strict_types=1);

namespace KronoQR\LicenseIssuer;

use InvalidArgumentException;

/**
 * Emision de claves de licencia de KronoQR (RF-PD-04, ADR-018).
 *
 * ## ESTO NO ES PARTE DEL PRODUCTO
 *
 * Vive en `tools/` de la raiz del repositorio, que **no se copia a ninguna
 * imagen**: el `Dockerfile` de PHP hace `COPY backend/ ./` y este directorio
 * queda fuera. En el servidor del cliente solo vive la **clave publica** y el
 * verificador (§7.7, RS-08).
 *
 * ## La clave privada NO esta aqui, ni puede estarlo
 *
 * Se recibe como argumento, y quien invoca la lee de una variable de entorno o
 * de un fichero de su gestor de secretos. **Esta clase no la imprime nunca**,
 * ni en un mensaje de error ni en un volcado: un `var_dump` de un objeto que la
 * contuviera acabaria en un ticket algun dia. Por eso no se guarda como
 * propiedad — entra por parametro, se usa y se va.
 *
 * ## Formato: `KQL1.<carga>.<firma>`
 *
 * Documentado en `Ed25519LicenseVerifier` del producto y en
 * `docs/cliente/configuracion.md`. Base64url sin relleno para que la clave se
 * pueda pegar en un correo, en un `.env` y en una URL sin que nada la reescriba.
 *
 * **Se firma sobre el texto codificado tal y como viaja**, no sobre el JSON
 * reserializado: dos codificadores JSON ordenan las claves distinto, y firmar
 * sobre algo que hay que volver a serializar produce firmas que verifican en la
 * maquina del fabricante y no en la del cliente.
 */
final class LicenseIssuer
{
    private const string FORMAT = 'KQL1';

    /**
     * Genera un par ed25519 nuevo.
     *
     * Se ejecuta **una vez en la vida del producto** —o cada vez que se rote el
     * par—, y la publica se pega en `config/license.php` antes de construir la
     * imagen de release. Ver el README de este directorio.
     *
     * @return array{public: string, secret: string} en hexadecimal
     */
    public static function generateKeyPair(): array
    {
        self::requireSodium();

        $pair = \sodium_crypto_sign_keypair();

        return [
            'public' => \sodium_bin2hex(\sodium_crypto_sign_publickey($pair)),
            'secret' => \sodium_bin2hex(\sodium_crypto_sign_secretkey($pair)),
        ];
    }

    /**
     * Falla con un mensaje que dice **qué hacer** si falta la extensión.
     *
     * Sin esto, quien ejecute el emisor en una máquina sin `ext-sodium` recibe
     * un «Call to undefined function» y una traza. Es exactamente el tipo de
     * error que cuesta media hora averiguar y treinta segundos arreglar.
     *
     * @throws \RuntimeException
     */
    private static function requireSodium(): void
    {
        if (\function_exists('sodium_crypto_sign_keypair')) {
            return;
        }

        throw new \RuntimeException(
            'This machine has no PHP sodium extension, so it cannot sign licences. '
            .'Install ext-sodium (it is bundled with PHP since 7.2 and only needs enabling), '
            .'or run the issuer inside the project container.'
        );
    }

    /**
     * Emite una clave firmada.
     *
     * @param  array<string, mixed>  $claims  la carga util; ver el README para los campos obligatorios
     * @param  string  $secretKeyHex  la clave PRIVADA en hexadecimal, que esta clase no guarda ni imprime
     *
     * @throws InvalidArgumentException si la clave privada no tiene la forma de una ed25519
     */
    public static function issue(array $claims, string $secretKeyHex): string
    {
        self::requireSodium();

        $secret = self::secretKey($secretKeyHex);

        $json = json_encode($claims, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $payload = self::encode($json);
        $signature = \sodium_crypto_sign_detached($payload, $secret);

        // La clave privada se borra de memoria en cuanto deja de hacer falta.
        // Es una precaucion barata y sensata en un proceso que puede volcar.
        \sodium_memzero($secret);

        return self::FORMAT.'.'.$payload.'.'.self::encode($signature);
    }

    /**
     * @throws InvalidArgumentException
     */
    private static function secretKey(string $hex): string
    {
        $hex = trim($hex);

        if (preg_match('/^[0-9a-fA-F]{128}$/', $hex) !== 1) {
            // El mensaje NO repite lo recibido: seria imprimir media clave
            // privada en la consola de quien se equivoco de variable.
            throw new InvalidArgumentException(
                'The ed25519 secret key must be 128 hexadecimal characters. Check the variable you passed.'
            );
        }

        $bytes = \sodium_hex2bin(strtolower($hex));

        if (\strlen($bytes) !== \SODIUM_CRYPTO_SIGN_SECRETKEYBYTES) {
            throw new InvalidArgumentException('The ed25519 secret key has an unexpected length.');
        }

        return $bytes;
    }

    /** Base64url sin relleno. */
    private static function encode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }
}
