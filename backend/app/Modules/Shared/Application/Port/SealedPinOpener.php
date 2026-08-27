<?php

declare(strict_types=1);

namespace App\Modules\Shared\Application\Port;

use SensitiveParameter;

/**
 * Abre el PIN que el quiosco cifro con la clave publica de la instalacion
 * (RF-AT-11, RL-12, regla dura 19).
 *
 * ## Por que el PIN viaja cifrado y no en claro sobre TLS
 *
 * Porque el quiosco **no puede esperar a tener red para aceptar un fichaje**. La
 * regla dura 19 es tajante: la tablet confirma en local y encola. Con la tarjeta
 * eso es facil —el padron cacheado resuelve el QR sin servidor—, pero un PIN no
 * se puede verificar sin `pin_hash`, que no sale del servidor y no debe salir. La
 * unica salida que no obliga a elegir entre «bloquear al empleado» y «guardar el
 * PIN en claro en IndexedDB» es que el quiosco **selle** el PIN con una clave
 * publica en el momento de teclearlo: a partir de ahi lo que queda en la cola es
 * un criptograma que solo el servidor puede abrir, y la tablet ya no conserva
 * nada que valga para suplantar a nadie ni aunque se la lleven (RS-04, RL-12).
 *
 * El fichaje se confirma **provisionalmente** en la tablet y la verificacion real
 * —incluido el bloqueo escalonado de RS-12— ocurre en el servidor al recibirlo.
 * Si el PIN resulta invalido, el escaneo se registra como rechazo y el fichaje
 * queda marcado para revision: nunca se pierde la jornada por un problema que el
 * empleado no puede resolver delante de la pantalla.
 *
 * ## Sobre cerrado (`crypto_box_seal`) y no cifrado con clave compartida
 *
 * Un secreto compartido con la tablet seria un secreto **en** la tablet, y una
 * tablet colgada de una pared se pierde. Con un sobre cerrado de libsodium el
 * quiosco solo tiene la clave publica —que no es secreta— y cada sobre lleva su
 * propia clave efimera, asi que dos fichajes con el mismo PIN producen
 * criptogramas distintos: quien mire la cola no puede ni agrupar por PIN.
 *
 * **Formato exacto, para que el cliente no tenga que adivinarlo:**
 *
 * ```
 * pin_sealed = base64( crypto_box_seal( "123456", CLAVE_PUBLICA_DE_LA_INSTALACION ) )
 * ```
 *
 * - Curva X25519, la de `crypto_box`. La clave publica son 32 bytes en base64 y
 *   la sirve `GET /api/v1/kiosk/roster` en `pin_sealing_public_key`, junto al
 *   padron, porque es un dato mas de los que el quiosco necesita para funcionar
 *   sin red.
 * - El mensaje es el PIN en ASCII, seis digitos, **sin relleno ni terminador**.
 * - El criptograma resultante son 54 bytes (48 de sobre + 6 de PIN), o 72
 *   caracteres en base64 estandar.
 * - En el navegador: `sodium.crypto_box_seal(pin, publicKey)` de
 *   `libsodium-wrappers`.
 *
 * ## Lo que este puerto NO hace
 *
 * No dice por que un sobre no abre. Un sobre que no abre es un cliente mal
 * escrito o alguien probando, **nunca un PIN incorrecto**: la comprobacion del
 * PIN ocurre despues, contra `pin_hash`, y es la unica que cuenta como intento
 * fallido. Confundir las dos cosas permitiria bloquear el PIN de cualquiera
 * enviando basura, que es la regla dura 19 rota desde fuera.
 */
interface SealedPinOpener
{
    /**
     * @param  string  $sealed  Base64 del sobre cerrado tal y como lo genero el quiosco.
     * @return string|null El PIN en claro, o `null` si el sobre no abre —clave que no
     *                     corresponde, criptograma manipulado, base64 invalido o la
     *                     instalacion sin par de claves configurado—. Quien llama lo
     *                     traduce al **mismo** rechazo generico que cualquier otro
     *                     (regla dura 17).
     */
    public function open(#[SensitiveParameter] string $sealed): ?string;

    /**
     * La clave publica con la que hay que cerrar el sobre, en base64, o `null` si
     * la instalacion no tiene par de claves configurado.
     *
     * **Esta en el mismo puerto que `open()` a proposito.** Quien abre el sobre
     * es quien sabe con que se cierra, y las dos respuestas salen del mismo
     * secreto: una segunda clase que derivara la clave publica por su cuenta
     * seria un segundo sitio donde acertar, y el sintoma de fallar seria que
     * todos los fichajes por PIN se rechazan sin que nada mas parezca roto.
     *
     * `null` significa «esta instalacion no tiene fichaje por PIN configurado».
     * No es un error: el producto se vende a clientes que pueden no querer la
     * segunda via, y el quiosco tiene que poder ocultar el teclado en vez de
     * ofrecer una puerta que rechaza siempre.
     */
    public function publicKey(): ?string;
}
