<?php

declare(strict_types=1);

namespace App\Modules\Identity\Domain\ValueObject;

use App\Modules\Identity\Domain\Exception\InvalidSigningKey;
use SensitiveParameter;

/**
 * Una de las claves HMAC con las que se firman las tarjetas (doc 02 §5.1,
 * ADR-005).
 *
 * **Por que el HMAC vive en el dominio de `Identity` y no en un adaptador.**
 * Firmar el payload no es un detalle de infraestructura: es la regla que hace
 * que una tarjeta sea o no sea de este sistema, y es exactamente lo que ADR-005
 * decide. Aqui dentro no hay nada del framework —`hash_hmac` y `hash_equals` son
 * funciones del nucleo de PHP— y todo es deterministico, asi que la verificacion
 * de firma se prueba en la suite unitaria, sin base de datos y en milisegundos.
 * Lo que si es infraestructura, y por eso esta fuera, es **de donde salen los
 * bytes de la clave**: eso lo resuelve el puerto `QrKeyProvider`.
 *
 * `Attendance` no conoce nada de esto (ADR-025): recibe un `CredentialResolution`
 * y no sabe que hay un HMAC detras.
 *
 * **La clave no se expone.** No hay un getter del secreto, no se serializa y el
 * parametro va marcado con `#[SensitiveParameter]` para que no aparezca en la
 * traza de ninguna excepcion. Un `var_dump` de este objeto en un log seria la
 * filtracion que permite fabricar la tarjeta de cualquiera.
 */
final readonly class QrSigningKey
{
    /**
     * Minimo de material de clave, en bytes.
     *
     * El Anexo B dice «32 bytes, base64» y esto lo exige en lugar de confiarlo.
     * HMAC-SHA256 con una clave mas corta que su salida pierde fuerza sin dar
     * ningun aviso: la instalacion seguiria funcionando, solo que peor, que es
     * la peor forma de fallar de un control criptografico.
     */
    public const int MINIMUM_SECRET_BYTES = 32;

    private const string KEY_ID_PATTERN = '/^[A-Za-z0-9]{2}$/';

    private function __construct(
        public string $id,
        #[SensitiveParameter]
        private string $secret,
    ) {}

    /**
     * @param  string  $base64  Los 32 bytes de clave, en base64 (Anexo B).
     *
     * @throws InvalidSigningKey
     */
    public static function fromBase64(string $id, #[SensitiveParameter] string $base64): self
    {
        if (preg_match(self::KEY_ID_PATTERN, $id) !== 1) {
            throw InvalidSigningKey::badKeyId($id);
        }

        // `strict` a proposito: una clave con un caracter de mas no puede
        // decodificarse «como se pueda». Si el instalador escribio mal la
        // variable, hay que enterarse.
        $secret = base64_decode($base64, true);

        if ($secret === false || $base64 === '') {
            throw InvalidSigningKey::notBase64($id);
        }

        if (\strlen($secret) < self::MINIMUM_SECRET_BYTES) {
            throw InvalidSigningKey::tooShort($id, \strlen($secret), self::MINIMUM_SECRET_BYTES);
        }

        return new self($id, $secret);
    }

    /**
     * Firma un token y devuelve el payload completo listo para imprimir.
     */
    public function sign(string $token): QrPayload
    {
        return QrPayload::of(
            $this->id,
            $token,
            $this->signatureFor(QrPayload::signingInputFor($this->id, $token)),
        );
    }

    /**
     * **Paso 3 de la verificacion del §5.2**: recalcula el HMAC y compara en
     * tiempo constante.
     *
     * `hash_equals` y no `===`. La comparacion normal de PHP se detiene en el
     * primer byte distinto, asi que el tiempo de respuesta revela cuantos
     * caracteres de la firma se acertaron; con eso, un atacante construye una
     * firma valida byte a byte sin conocer la clave. Es el ataque que la regla
     * dura 17 y RS-03 nombran, y `hash_equals` es la unica linea que lo cierra.
     */
    public function verifies(QrPayload $payload): bool
    {
        return hash_equals($this->signatureFor($payload->signingInput()), $payload->signature);
    }

    /**
     * Primeros 16 caracteres base64url del HMAC-SHA256 (§5.1).
     *
     * base64url **sin relleno**: `+` y `/` no sobreviven a un lector de QR que
     * interprete el contenido como URL, y el `=` de relleno tampoco pinta nada
     * en un campo de longitud fija.
     */
    private function signatureFor(string $signingInput): string
    {
        $mac = hash_hmac('sha256', $signingInput, $this->secret, true);

        $encoded = rtrim(strtr(base64_encode($mac), '+/', '-_'), '=');

        return substr($encoded, 0, QrPayload::SIGNATURE_LENGTH);
    }
}
