<?php

declare(strict_types=1);

namespace App\Modules\Identity\Domain\ValueObject;

use App\Modules\Identity\Domain\Exception\MalformedQrPayload;
use SensitiveParameter;

/**
 * El `token` del payload: 22 caracteres base64url, 128 bits de aleatoriedad
 * (doc 02 §5.1).
 *
 * **Existe solo el tiempo de imprimir la tarjeta.** En la base de datos vive su
 * hash y nada mas (`credentials.secret_hash`): quien lea la tabla —una copia de
 * seguridad robada, un volcado de soporte, una inyeccion SQL— no puede fabricar
 * ninguna tarjeta valida. Es la mitad de la proteccion; la otra es la firma HMAC,
 * que impide fabricar una desde fuera.
 *
 * **Por que SHA-256 a secas y no bcrypt o Argon2.** Un hash lento existe para
 * proteger secretos que las personas eligen, con muy poca entropia. Este secreto
 * lo genera el servidor con 128 bits: probar todo el espacio es inviable con
 * cualquier algoritmo, asi que un hash lento no anadiria seguridad y si costaria
 * cientos de milisegundos **en el camino de fichaje**, que es el que tiene un
 * presupuesto de 2 s de extremo a extremo. Ademas el hash tiene que ser
 * determinista y sin sal: la verificacion resuelve la credencial **buscando por
 * el hash** (paso 4 del §5.2), y con sal por fila habria que recorrer la tabla
 * entera en cada escaneo.
 *
 * **No lleva PII ni nada secuencial** (regla dura 10, ADR-005): es aleatorio
 * puro y no dice de quien es hasta que la tabla lo resuelve.
 */
final readonly class CredentialSecret
{
    /** Bytes de entropia. 16 bytes = 128 bits = 22 caracteres base64url. */
    public const int ENTROPY_BYTES = 16;

    private const string PATTERN = '/^[A-Za-z0-9_-]{22}$/';

    private function __construct(
        #[SensitiveParameter]
        public string $value,
    ) {}

    /**
     * @throws MalformedQrPayload si no tiene la forma del §5.1
     */
    public static function fromString(#[SensitiveParameter] string $token): self
    {
        if (preg_match(self::PATTERN, $token) !== 1) {
            throw MalformedQrPayload::badToken();
        }

        return new self($token);
    }

    /**
     * Codifica en base64url sin relleno los bytes aleatorios que le den.
     *
     * **La aleatoriedad no se genera aqui.** `random_bytes()` es un efecto y el
     * dominio es deterministico (misma razon que la regla dura 2 con el reloj):
     * quien la produce es el adaptador del puerto `CredentialSecretFactory`, y
     * asi una prueba puede fijarla y comprobar el payload resultante caracter a
     * caracter.
     */
    public static function fromBytes(#[SensitiveParameter] string $bytes): self
    {
        return self::fromString(rtrim(strtr(base64_encode($bytes), '+/', '-_'), '='));
    }

    /**
     * Lo unico de este objeto que puede escribirse en algun sitio.
     */
    public function hash(): string
    {
        return self::hashOf($this->value);
    }

    /**
     * El mismo hash, para quien solo tiene el token leido de un QR y necesita
     * buscar la fila (paso 4 del §5.2).
     */
    public static function hashOf(#[SensitiveParameter] string $token): string
    {
        return hash('sha256', $token);
    }
}
