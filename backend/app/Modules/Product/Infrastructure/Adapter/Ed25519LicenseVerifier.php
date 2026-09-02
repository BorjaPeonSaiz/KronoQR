<?php

declare(strict_types=1);

namespace App\Modules\Product\Infrastructure\Adapter;

use App\Modules\Product\Application\Port\LicenseVerifier;
use App\Modules\Product\Domain\Exception\InvalidLicenseKey;
use App\Modules\Product\Domain\ValueObject\License;
use App\Modules\Product\Domain\ValueObject\LicenseRejection;
use App\Modules\Product\Domain\ValueObject\LicenseVerification;
use SodiumException;

/**
 * Verifica la firma ed25519 de una clave de licencia con `sodium` nativo
 * (doc 02 §3.1, ADR-018, RF-PD-04).
 *
 * ## El formato: `KQL1.<carga>.<firma>`
 *
 * - `KQL1` — version del **formato**, no del producto. Si algun dia cambia el
 *   algoritmo o la forma de la carga util, una clave `KQL2` se distingue de una
 *   `KQL1` sin tener que adivinarlo, y esta version puede seguir aceptando las
 *   viejas. Sin prefijo, la primera migracion de formato obliga a reemitir todas
 *   las licencias vivas a la vez.
 * - `<carga>` — el JSON de las afirmaciones, en **base64url sin relleno**.
 * - `<firma>` — los 64 bytes de la firma detached sobre los **bytes de la carga
 *   tal y como viajan**, tambien en base64url sin relleno.
 *
 * Base64url y no base64 estandar para que la clave se pueda pegar en una URL, en
 * un `.env` y en un correo sin que nada la reescriba. Sin relleno para que no
 * acabe con `=` sueltos que algunos clientes de correo cortan.
 *
 * Se firma sobre el **texto codificado** y no sobre el JSON descodificado: dos
 * codificadores JSON distintos ordenan las claves de forma distinta, y firmar
 * sobre algo que hay que volver a serializar es la forma clasica de tener una
 * firma que verifica en la maquina del fabricante y no en la del cliente.
 *
 * ## Aqui NO se emite
 *
 * En el producto solo vive la **clave publica**. El emisor —con la privada— vive
 * en `tools/license-issuer/`, en la raiz del repositorio, que **no se copia a
 * ninguna imagen**: el `Dockerfile` de PHP hace `COPY backend/ ./` y ese
 * directorio esta fuera. La clave privada no esta en el repositorio en ninguna
 * forma (§7.7, RS-08): la custodia el fabricante y el emisor la lee de una
 * variable de entorno o de un fichero que se le indica.
 *
 * ## Sin red, y se comprueba
 *
 * Este fichero no tiene ninguna dependencia de red y no puede tenerla: la unica
 * entrada es una cadena y la unica salida es un objeto de dominio.
 * `tests/Feature/Product/LicenseVerificationIsLocalTest.php` lo ata con un
 * cliente HTTP simulado que hace fallar la prueba si alguien lo invoca.
 *
 * ## Tiempo constante
 *
 * `sodium_crypto_sign_verify_detached` ya lo es. No hay comparacion de cadenas
 * propia en ningun punto del camino, y por eso no hay `hash_equals` a la vista:
 * lo que se compara lo compara sodium.
 */
final readonly class Ed25519LicenseVerifier implements LicenseVerifier
{
    /** Version del formato de clave. Va delante para poder cambiar de formato sin reemitir. */
    private const string FORMAT = 'KQL1';

    /** Longitud en bytes de una firma ed25519. */
    private const int SIGNATURE_BYTES = 64;

    /** Longitud en bytes de una clave publica ed25519. */
    private const int PUBLIC_KEY_BYTES = 32;

    public function __construct(
        /**
         * Clave publica del fabricante en hexadecimal (64 caracteres).
         *
         * Llega ya resuelta desde `ProductServiceProvider`, que la lee de
         * `config/license.php`. **Cadena vacia significa que esta compilacion no
         * lleva clave publica**, que es el estado de un arbol de desarrollo: se
         * traduce a `no_public_key` y el producto entero sigue funcionando en
         * modo degradado (regla dura 15).
         */
        private string $publicKeyHex,
    ) {}

    public function verify(string $signedKey): LicenseVerification
    {
        $publicKey = $this->publicKey();

        if ($publicKey === null) {
            return LicenseVerification::rejected(LicenseRejection::NoPublicKey);
        }

        $parts = explode('.', trim($signedKey));

        if (\count($parts) !== 3 || $parts[0] !== self::FORMAT) {
            return LicenseVerification::rejected(LicenseRejection::Malformed);
        }

        [, $encodedPayload, $encodedSignature] = $parts;

        $signature = self::decode($encodedSignature);
        $payload = self::decode($encodedPayload);

        if ($signature === null || $payload === null || \strlen($signature) !== self::SIGNATURE_BYTES) {
            return LicenseVerification::rejected(LicenseRejection::Malformed);
        }

        try {
            // Se firma sobre el texto codificado tal y como viaja. Ver el
            // docblock de la clase: firmar sobre el JSON reserializado produce
            // firmas que verifican en una maquina y no en otra.
            $signatureIsValid = sodium_crypto_sign_verify_detached($signature, $encodedPayload, $publicKey);
        } catch (SodiumException) {
            // Solo se llega aqui si sodium recibe algo con longitud imposible, y
            // las longitudes ya estan comprobadas. Se trata como firma mala y no
            // se propaga: este camino no puede lanzar (ADR-019).
            return LicenseVerification::rejected(LicenseRejection::BadSignature);
        }

        if (! $signatureIsValid) {
            return LicenseVerification::rejected(LicenseRejection::BadSignature);
        }

        return self::claimsOf($payload);
    }

    /**
     * De aqui en adelante la firma ya cuadro: lo que falle es un error de
     * **emision**, no una manipulacion.
     */
    private static function claimsOf(string $payload): LicenseVerification
    {
        try {
            $claims = json_decode($payload, associative: true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return LicenseVerification::rejected(LicenseRejection::InvalidPayload);
        }

        if (! \is_array($claims) || array_is_list($claims)) {
            return LicenseVerification::rejected(LicenseRejection::InvalidPayload);
        }

        try {
            /** @var array<string, mixed> $claims */
            return LicenseVerification::verified(License::fromClaims($claims));
        } catch (InvalidLicenseKey) {
            // El motivo exacto —que campo falta— se lo queda la activacion, que
            // vuelve a construir la licencia y si deja subir la excepcion. Aqui
            // interesa el resultado, porque este camino tambien lo recorre la
            // lectura y ahi no hay nadie a quien contarselo.
            return LicenseVerification::rejected(LicenseRejection::InvalidPayload);
        }
    }

    /**
     * La clave publica en bytes, o `null` si esta compilacion no lleva ninguna
     * utilizable.
     *
     * @return non-empty-string|null
     */
    private function publicKey(): ?string
    {
        $hex = trim($this->publicKeyHex);

        if ($hex === '' || preg_match('/^[0-9a-fA-F]{64}$/', $hex) !== 1) {
            return null;
        }

        $bytes = @hex2bin(strtolower($hex));

        return \is_string($bytes) && \strlen($bytes) === self::PUBLIC_KEY_BYTES ? $bytes : null;
    }

    /** Base64url sin relleno. `null` si la cadena no lo es. */
    private static function decode(string $value): ?string
    {
        if (preg_match('/^[A-Za-z0-9_-]+$/', $value) !== 1) {
            return null;
        }

        $decoded = base64_decode(strtr($value, '-_', '+/'), strict: true);

        return \is_string($decoded) ? $decoded : null;
    }
}
