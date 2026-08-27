<?php

declare(strict_types=1);

namespace App\Modules\Shared\Infrastructure\Adapter;

use App\Modules\Shared\Application\Port\SealedPinOpener;
use SensitiveParameter;
use Throwable;

/**
 * Sobres cerrados de libsodium (`crypto_box_seal`) para el PIN del quiosco
 * (RF-AT-11, RL-12).
 *
 * ## Un solo secreto en la configuracion, no dos
 *
 * `IDENTITY_PIN_SEALING_SECRET_KEY` es la clave privada X25519 en base64, y la
 * publica **se deriva de ella** en cada arranque. Guardar las dos en el `.env`
 * habria dejado la puerta abierta a que una instalacion las cambiara por parejas
 * mal emparejadas —copiar y pegar de dos rotaciones distintas—, y el sintoma de
 * eso es que todos los fichajes por PIN se rechazan mientras nada mas parece
 * roto. Con una sola no puede haber desacuerdo.
 *
 * **La genera el instalador en el servidor del cliente y no sale de ahi** (§7.7,
 * regla dura 13): quien tenga la privada puede leer los PIN que viajen sellados,
 * asi que no se comparte entre instalaciones ni se guarda en el repositorio.
 *
 * ```
 * php artisan tinker --execute="echo base64_encode(sodium_crypto_box_secretkey(sodium_crypto_box_keypair()));"
 * ```
 *
 * ## Sin par de claves, sin fichaje por PIN
 *
 * Si la clave falta o no es valida, `publicKey()` devuelve `null` —el quiosco
 * oculta el teclado— y `open()` tambien —cualquier sobre que llegara igualmente
 * se traduce al rechazo generico—. **No se lanza ninguna excepcion**: una
 * instalacion que no quiere la segunda via es un caso legitimo del producto
 * (ADR-017), no una averia, y un `500` en el camino de fichaje seria un fallo
 * ruidoso por una funcionalidad que el cliente decidio no usar.
 *
 * ## Por que no se distingue por que un sobre no abre
 *
 * Base64 invalido, longitud imposible, criptograma manipulado o clave que no
 * corresponde: los cuatro devuelven `null` y recorren el mismo camino. Quien
 * llama lo traduce al rechazo generico de escaneo (regla dura 17, RS-03). Y
 * **ninguno cuenta como intento fallido de PIN**: un sobre que no abre no dice
 * nada sobre el PIN que lleva dentro, y contarlo permitiria bloquear el PIN de
 * cualquiera enviando basura con su codigo de empleado.
 */
final readonly class SodiumSealedPinOpener implements SealedPinOpener
{
    /**
     * Techo del criptograma aceptado, en bytes de PIN.
     *
     * El PIN son seis digitos, asi que el sobre legitimo mide
     * `SODIUM_CRYPTO_BOX_SEALBYTES + 6`. El margen esta para no clavar aqui una
     * longitud que el contrato ya fija en otro sitio —y que si algun dia cambia,
     * cambiaria alli—, no para admitir cualquier cosa: sin techo, un criptograma
     * de megabytes obligaria a descifrar antes de poder rechazarlo.
     */
    private const int MAX_PIN_BYTES = 64;

    public function open(#[SensitiveParameter] string $sealed): ?string
    {
        $keypair = $this->keypair();

        if ($keypair === null) {
            return null;
        }

        // Modo estricto: sin el, base64_decode acepta basura y devuelve bytes
        // que luego no abren, lo que convertiria un error de cliente en un
        // rechazo que parece criptografico.
        $ciphertext = base64_decode($sealed, true);

        if (! \is_string($ciphertext)
            || \strlen($ciphertext) <= SODIUM_CRYPTO_BOX_SEALBYTES
            || \strlen($ciphertext) > SODIUM_CRYPTO_BOX_SEALBYTES + self::MAX_PIN_BYTES) {
            sodium_memzero($keypair);

            return null;
        }

        try {
            $pin = sodium_crypto_box_seal_open($ciphertext, $keypair);
        } catch (Throwable) {
            // La extension lanza ante un par de claves malformado. Se trata como
            // «no abre», por lo mismo que las otras tres causas.
            $pin = false;
        } finally {
            sodium_memzero($keypair);
        }

        return \is_string($pin) ? $pin : null;
    }

    public function publicKey(): ?string
    {
        $secret = $this->secretKey();

        if ($secret === null) {
            return null;
        }

        try {
            $public = base64_encode(sodium_crypto_box_publickey_from_secretkey($secret));
        } catch (Throwable) {
            return null;
        } finally {
            sodium_memzero($secret);
        }

        return $public;
    }

    /**
     * El par de claves de libsodium, listo para abrir. `null` si la instalacion
     * no lo tiene configurado.
     *
     * Quien lo recibe se encarga de borrarlo de memoria con `sodium_memzero()`:
     * es material de clave y no debe quedar en un volcado de nucleo ni en un
     * informe de excepcion.
     */
    private function keypair(): ?string
    {
        $secret = $this->secretKey();

        if ($secret === null) {
            return null;
        }

        try {
            $keypair = sodium_crypto_box_keypair_from_secretkey_and_publickey(
                $secret,
                sodium_crypto_box_publickey_from_secretkey($secret),
            );
        } catch (Throwable) {
            return null;
        } finally {
            sodium_memzero($secret);
        }

        return $keypair;
    }

    /**
     * Los 32 bytes de la clave privada, decodificados. `null` si no hay clave o
     * no mide lo que tiene que medir.
     */
    private function secretKey(): ?string
    {
        $configured = config()->string('identity.pin.sealing.secret_key', '');

        if ($configured === '') {
            return null;
        }

        $secret = base64_decode($configured, true);

        if (! \is_string($secret) || \strlen($secret) !== SODIUM_CRYPTO_BOX_SECRETKEYBYTES) {
            return null;
        }

        return $secret;
    }
}
