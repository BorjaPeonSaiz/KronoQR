<?php

declare(strict_types=1);

namespace App\Modules\Product\Domain\ValueObject;

/**
 * Por que una clave de licencia no ha podido verificarse (RF-PD-04, ADR-018).
 *
 * ## Aqui SI se distingue el motivo, al contrario que en el escaneo
 *
 * La regla dura 17 obliga a que los rechazos de escaneo sean genericos y de
 * tiempo constante, porque ahi hay un atacante intentando adivinar credenciales
 * ajenas. **Este caso es el contrario**, y el doc 01 §8.1 lo enmarca: la
 * alteracion de la clave de licencia *«es un control comercial, no de seguridad
 * de datos»*. Quien ve este motivo es la persona que administra la instalacion,
 * mirando **su propia** licencia, y lo que necesita es saber si tiene que pedir
 * una clave nueva, corregir un copiado a medias o avisar de que el paquete esta
 * mal construido. Ocultarselo solo produce una llamada de soporte.
 *
 * Nada de esto se filtra a la sonda publica de salud, que solo da el estado.
 */
enum LicenseRejection: string
{
    /**
     * La cadena no tiene la forma `KQL1.<carga>.<firma>`, o alguna de las dos
     * partes no es base64url, o la firma no mide 64 bytes.
     *
     * Es, con diferencia, el caso mas frecuente en campo: una clave pegada a
     * medias desde un correo, o con un salto de linea en medio.
     */
    case Malformed = 'malformed';

    /**
     * La forma es correcta y la firma no cuadra con la clave publica del
     * fabricante: la clave se altero, o la emitio otro emisor.
     *
     * Los dos casos son indistinguibles por construccion —eso es lo que hace una
     * firma— y la accion siguiente es la misma.
     */
    case BadSignature = 'bad_signature';

    /**
     * La firma cuadra pero la carga util no sirve: no es JSON, o le falta un
     * campo obligatorio, o un campo tiene un tipo imposible.
     *
     * Significa que el fabricante emitio mal. Es el unico motivo que no se
     * arregla en casa del cliente y por eso lleva texto propio.
     */
    case InvalidPayload = 'invalid_payload';

    /**
     * Esta compilacion del producto **no lleva clave publica del fabricante**
     * configurada, asi que ninguna clave puede verificarse.
     *
     * No es un fallo del cliente ni de su clave: es una compilacion de
     * desarrollo, o un despliegue al que le falta `LICENSE_PUBLIC_KEY`. Se
     * distingue de los otros tres porque la accion siguiente no es pedir otra
     * clave, sino revisar el despliegue — y porque `doctor` (5.9) tiene que
     * poder decirlo con esas palabras.
     */
    case NoPublicKey = 'no_public_key';
}
