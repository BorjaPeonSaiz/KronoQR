<?php

declare(strict_types=1);

namespace App\Modules\Identity\Domain\ValueObject;

use App\Modules\Identity\Domain\Exception\MalformedQrPayload;
use SensitiveParameter;

/**
 * El contenido impreso en la tarjeta, tal y como lo define el doc 02 §5.1 y lo
 * fija ADR-005:
 *
 * ```
 * FH1.<key_id>.<token>.<sig>
 *
 * FH1      Prefijo y version del esquema (permite migrar sin ambiguedad)
 * key_id   Identificador de la clave de firma (2 caracteres). Habilita la
 *          rotacion con solape del §5.3
 * token    22 caracteres base64url = 128 bits. Opaco, sin PII, no enumerable
 * sig      Primeros 16 caracteres base64url de
 *          HMAC-SHA256(key[key_id], "FH1." + key_id + "." + token)
 *
 * Ejemplo: FH1.a3.7QK2mXpR9vLdN4tZbYcF1w.k9Xm2pQrT5vN8wLa
 * ```
 *
 * **Este objeto solo conoce la FORMA.** No firma, no verifica y no sabe que
 * empleado hay detras: eso es {@see QrSigningKey} y la tabla `credentials`. La
 * separacion importa porque la forma es lo unico que se puede comprobar sin
 * secretos, y es el primer filtro de la verificacion del §5.2.
 *
 * **Nada de lo que hay aqui identifica a nadie** (regla dura 10, ADR-005): el
 * token es aleatorio y no deriva del empleado, y no hay ningun identificador
 * secuencial. Una tarjeta encontrada en el suelo no dice de quien es.
 *
 * **Por que `tryParse()` ademas de `parse()`.** El camino de fichaje no puede
 * permitirse una excepcion como control de flujo: capturarla dentro del bucle de
 * verificacion introduce una rama con coste distinto al del camino normal, y RS-03
 * exige que los rechazos consuman el mismo tiempo. El verificador usa
 * `tryParse()` y sigue trabajando con un payload centinela; `parse()` es para
 * quien construye, no para quien verifica.
 */
final readonly class QrPayload
{
    /** Prefijo y version del esquema. Migrar a `FH2` sera inequivoco. */
    public const string PREFIX = 'FH1';

    /** Separador de los cuatro componentes. */
    public const string SEPARATOR = '.';

    /** Longitud de `key_id` (§5.1). Dos caracteres bastan para 3.844 claves. */
    public const int KEY_ID_LENGTH = 2;

    /** 22 caracteres base64url = 128 bits de aleatoriedad, sin relleno. */
    public const int TOKEN_LENGTH = 22;

    /** Primeros 16 caracteres base64url del HMAC = 96 bits de firma. */
    public const int SIGNATURE_LENGTH = 16;

    /**
     * Longitud maxima admitida ANTES de mirar nada mas.
     *
     * El payload completo son unos 47 caracteres. El tope existe porque este
     * metodo recibe lo que haya leido una camara: sin el, un QR de 3 kB haria
     * trabajar a `explode` y a `preg_match` en el camino mas caliente del
     * producto, y repetido es una denegacion de servicio barata.
     */
    public const int MAX_LENGTH = 96;

    /** `key_id`: alfanumerico y nada mas. Viaja impreso y se lee a ojo. */
    private const string KEY_ID_PATTERN = '/^[A-Za-z0-9]{2}$/';

    /** base64url sin relleno: el alfabeto de RFC 4648 §5. */
    private const string TOKEN_PATTERN = '/^[A-Za-z0-9_-]{22}$/';

    private const string SIGNATURE_PATTERN = '/^[A-Za-z0-9_-]{16}$/';

    private function __construct(
        public string $keyId,
        public string $token,
        public string $signature,
    ) {}

    /**
     * Construye un payload ya validado a partir de sus tres partes.
     *
     * @throws MalformedQrPayload
     */
    public static function of(string $keyId, #[SensitiveParameter] string $token, #[SensitiveParameter] string $signature): self
    {
        if (preg_match(self::KEY_ID_PATTERN, $keyId) !== 1) {
            throw MalformedQrPayload::badKeyId();
        }

        if (preg_match(self::TOKEN_PATTERN, $token) !== 1) {
            throw MalformedQrPayload::badToken();
        }

        if (preg_match(self::SIGNATURE_PATTERN, $signature) !== 1) {
            throw MalformedQrPayload::badSignature();
        }

        return new self($keyId, $token, $signature);
    }

    /**
     * Lee el texto del QR. **Paso 1 de la verificacion del §5.2.**
     *
     * @throws MalformedQrPayload
     */
    public static function parse(#[SensitiveParameter] string $raw): self
    {
        if ($raw === '' || \strlen($raw) > self::MAX_LENGTH) {
            throw MalformedQrPayload::badFormat();
        }

        $parts = explode(self::SEPARATOR, $raw);

        if (\count($parts) !== 4) {
            throw MalformedQrPayload::badFormat();
        }

        // El prefijo se compara primero porque es el paso 1 del §5.2, y con
        // `!==` y no con `hash_equals`: no es un secreto, es una constante
        // publica impresa en cada tarjeta. Comparar en tiempo constante algo que
        // el atacante ya conoce no protege nada.
        if ($parts[0] !== self::PREFIX) {
            throw MalformedQrPayload::badPrefix();
        }

        return self::of($parts[1], $parts[2], $parts[3]);
    }

    /**
     * Como {@see parse()}, pero devuelve `null` en vez de lanzar.
     *
     * Es la que usa el verificador: ver {@see QrPayload} sobre por que el camino
     * de fichaje no puede usar excepciones como control de flujo.
     */
    public static function tryParse(#[SensitiveParameter] string $raw): ?self
    {
        try {
            return self::parse($raw);
        } catch (MalformedQrPayload) {
            return null;
        }
    }

    /**
     * Lo que se firma: `"FH1." + key_id + "." + token` (§5.1).
     *
     * **La firma cubre el `key_id`**, y no es un detalle: si solo cubriera el
     * token, durante un solape de claves se podria coger una tarjeta firmada con
     * la clave anterior, cambiarle el `key_id` y forzar la verificacion contra
     * la otra clave.
     */
    public function signingInput(): string
    {
        return self::signingInputFor($this->keyId, $this->token);
    }

    /**
     * La misma cadena, para quien todavia no tiene el payload porque es
     * justamente la firma lo que esta calculando.
     *
     * Existe para que la forma de lo que se firma este escrita **una sola vez**:
     * si el firmante y el verificador la construyeran cada uno por su cuenta, el
     * dia que cambie una y no la otra ninguna tarjeta verificaria y la causa
     * seria invisible.
     */
    public static function signingInputFor(string $keyId, string $token): string
    {
        return self::PREFIX.self::SEPARATOR.$keyId.self::SEPARATOR.$token;
    }

    /**
     * El texto que se imprime en el QR.
     *
     * **Nunca se registra en un log** (regla dura 21 y §5.2): quien lo tenga
     * puede fichar por su dueno.
     */
    public function toString(): string
    {
        return $this->signingInput().self::SEPARATOR.$this->signature;
    }
}
