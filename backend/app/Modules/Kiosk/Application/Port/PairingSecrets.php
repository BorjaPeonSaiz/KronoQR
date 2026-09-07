<?php

declare(strict_types=1);

namespace App\Modules\Kiosk\Application\Port;

use App\Modules\Kiosk\Domain\ValueObject\PairingCode;

/**
 * El sorteo y la comparacion de los dos secretos del emparejamiento
 * (**RF-PD-06**, RS-03).
 *
 * ## Por que esto es un puerto y no una funcion
 *
 * Por dos razones, y la segunda es la que importa:
 *
 * 1. El caso de uso no puede llamar a `random_bytes()` ni a `hash()` sin
 *    volverse imposible de probar de forma determinista: un codigo aleatorio no
 *    se puede afirmar en una prueba.
 * 2. **El `hash_equals` tiene que estar en un solo sitio.** Si cada camino
 *    comparara por su cuenta, bastaria un `===` escrito por costumbre en el
 *    tercer sitio para abrir el canal de tiempo que RS-03 cierra.
 *
 * ## Que se guarda y que no
 *
 * De los dos secretos se guarda **solo su SHA-256**, igual que
 * `devices.token_hash` y `credentials.secret_hash` (doc 01 §5). El codigo en
 * claro existe durante una respuesta HTTP y el secreto vive en la tablet: ninguno
 * de los dos vuelve a salir del servidor.
 *
 * ## Lo que este puerto NO hace
 *
 * No decide si el secreto es correcto: devuelve un booleano. La decision es del
 * agregado, que lo recibe ya resuelto — y por eso puede probarse sin hashear
 * nada.
 */
interface PairingSecrets
{
    /**
     * Un codigo de seis digitos sorteado con un CSPRNG.
     *
     * **Nunca `rand()` ni `mt_rand()`**: son predecibles a partir de unas pocas
     * salidas, y quien pueda predecir el codigo puede confirmarlo antes que el
     * administrador y llevarse el quiosco.
     */
    public function generateCode(): PairingCode;

    /**
     * El secreto de recogida: 32 bytes de CSPRNG en base64url.
     *
     * Es lo que impide que quien lea el codigo por encima del hombro recoja el
     * token: el codigo dice *que* solicitud se confirma y el secreto dice *quien*
     * la pidio.
     */
    public function generateSecret(): string;

    /** SHA-256 en hexadecimal, el formato que guarda la columna. */
    public function hash(string $secret): string;

    /**
     * Comparacion en **tiempo constante** del secreto contra el hash guardado.
     *
     * Se ejecuta **siempre**, tambien cuando la solicitud no existe: quien llama
     * pasa entonces un hash señuelo, para que el trabajo sea el mismo y la
     * respuesta no delate que `pairing_id` son reales (regla dura 17, RS-03).
     */
    public function matches(string $secret, string $expectedHash): bool;

    /**
     * Un hash con el que comparar cuando no hay fila.
     *
     * Tiene la forma y la longitud de uno real y **no es el hash de nada**: es el
     * segundo operando del `hash_equals` del camino en el que la solicitud no
     * existe. Sin el, ese camino se ahorraria la comparacion y costaria menos que
     * los otros dos rechazos.
     */
    public function decoyHash(): string;
}
