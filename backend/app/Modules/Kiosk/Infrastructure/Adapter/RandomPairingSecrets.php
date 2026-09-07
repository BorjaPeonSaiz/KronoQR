<?php

declare(strict_types=1);

namespace App\Modules\Kiosk\Infrastructure\Adapter;

use App\Modules\Kiosk\Application\Port\PairingSecrets;
use App\Modules\Kiosk\Domain\ValueObject\PairingCode;
use App\Modules\Shared\Domain\ValueObject\Base64Url;
use SensitiveParameter;

/**
 * El sorteo y la comparacion de los secretos del emparejamiento, con el CSPRNG
 * del sistema (**RF-PD-06**, RS-03).
 *
 * ## `random_int` y `random_bytes`, nunca `rand()` ni `mt_rand()`
 *
 * Los dos primeros leen del generador criptografico del sistema; los otros dos
 * son predecibles a partir de unas pocas salidas. Aqui eso no es teoria: quien
 * pueda predecir el proximo codigo puede confirmarlo antes que el administrador y
 * llevarse el quiosco, y quien pueda predecir el secreto puede recoger el token
 * de una tablet ajena.
 *
 * ## `random_int(0, 999999)` y despues `str_pad`, no `random_int(100000, 999999)`
 *
 * El segundo excluiria los codigos que empiezan por cero —el 10 % del espacio— y
 * lo haria en silencio. La diferencia no es de seguridad practica, es de
 * honestidad: el contrato dice `^[0-9]{6}$` y eso incluye `049 213`.
 *
 * ## El secreto es base64url y no hexadecimal
 *
 * 32 bytes en base64url son 43 caracteres; en hexadecimal serian 64. Viaja en un
 * JSON y se guarda en el almacenamiento local de una tablet: mas corto es mejor,
 * y `-` y `_` en lugar de `+` y `/` evitan que alguien lo pegue en una URL y lo
 * rompa. El `=` de relleno se quita por lo mismo.
 *
 * ## SHA-256 sin sal, igual que `devices.token_hash`
 *
 * Y es correcto **porque el secreto tiene 256 bits de entropia real**: no hay
 * diccionario que recorrer, asi que un hash lento no aporta nada y si costaria
 * en un endpoint que la tablet llama cada cinco segundos. Lo contrario es el PIN
 * (§7.5), que tiene un millon de combinaciones y por eso usa un hash caro.
 *
 * ## `matches()` compara con `hash_equals`, y se ejecuta SIEMPRE
 *
 * Tambien cuando la solicitud no existe: quien llama pasa entonces
 * {@see decoyHash()}. Un `===` aqui, escrito por costumbre, abriria el canal de
 * tiempo que RS-03 cierra — la comparacion de cadenas de PHP termina en el primer
 * byte distinto.
 */
final readonly class RandomPairingSecrets implements PairingSecrets
{
    /** Bytes del secreto de recogida antes de codificar. */
    private const int SECRET_BYTES = 32;

    /**
     * El hash señuelo: SHA-256 de una cadena fija que **no es el secreto de
     * nadie**.
     *
     * Se calcula una vez y es constante a proposito: generarlo al vuelo con
     * `random_bytes` en cada rechazo añadiria una lectura del CSPRNG que los
     * caminos con fila no pagan, que es justo la asimetria que se quiere evitar.
     * No hay riesgo en que sea publico: nadie puede enviar un secreto que
     * produzca este hash sin invertir SHA-256.
     */
    private const string DECOY_SOURCE = 'kronoqr:pairing:decoy';

    public function generateCode(): PairingCode
    {
        return PairingCode::of(str_pad(
            (string) random_int(0, 999_999),
            PairingCode::LENGTH,
            '0',
            STR_PAD_LEFT,
        ));
    }

    public function generateSecret(): string
    {
        return Base64Url::encode(random_bytes(self::SECRET_BYTES));
    }

    public function hash(#[SensitiveParameter] string $secret): string
    {
        return hash('sha256', $secret);
    }

    public function matches(#[SensitiveParameter] string $secret, string $expectedHash): bool
    {
        return hash_equals($expectedHash, $this->hash($secret));
    }

    public function decoyHash(): string
    {
        return hash('sha256', self::DECOY_SOURCE);
    }
}
