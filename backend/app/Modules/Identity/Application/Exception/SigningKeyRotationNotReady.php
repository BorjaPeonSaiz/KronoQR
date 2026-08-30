<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application\Exception;

use RuntimeException;

/**
 * La configuracion no esta en estado de solape y la rotacion no puede empezar
 * (RF-QR-07, doc 02 §5.3).
 *
 * **El comando comprueba, no genera.** Las claves las pone el operador en el
 * gestor de secretos del servidor (regla dura 13, §7.7). Si la aplicacion las
 * fabricara, el secreto habria pasado por PHP, por la salida de un comando y por
 * el historial del interprete, y ademas quedaria dentro del proceso de un
 * producto que se despliega en el servidor de un cliente. Lo que si puede hacer
 * —y hace— es negarse a reemitir sesenta tarjetas cuando el estado de la
 * configuracion garantiza que ninguna de ellas va a poder fichar.
 *
 * Los tres motivos de este error son los tres estados en los que reemitir seria
 * un error caro de deshacer: sin clave actual, sin solape declarado —lo que
 * incluye el descuido de poner el mismo `key_id` en las dos variables, porque
 * entonces el llavero solo tiene una clave— y con una rotacion anterior sin
 * cerrar.
 */
final class SigningKeyRotationNotReady extends RuntimeException
{
    public static function noCurrentKey(): self
    {
        return new self(
            'No hay clave de firma actual configurada (QR_SIGNING_KEY_CURRENT_ID / QR_SIGNING_KEY_CURRENT). '
            .'Sin ella no se puede imprimir ninguna tarjeta nueva.'
        );
    }

    /**
     * Tambien cubre el descuido de repetir el `key_id` en las dos variables: el
     * llavero las colapsa en una sola clave y no hay solape que abrir.
     */
    public static function noPreviousKey(): self
    {
        return new self(
            'No hay solape: falta la clave saliente, o lleva el mismo key_id que la actual '
            .'(QR_SIGNING_KEY_PREVIOUS_ID / QR_SIGNING_KEY_PREVIOUS). '
            .'Antes de rotar, mueve la clave actual a PREVIOUS y pon la nueva, con otro key_id, en CURRENT.'
        );
    }

    /**
     * Quedan tarjetas activas firmadas con una clave que ya no esta en el
     * llavero: **esas personas no pueden fichar**. Rotar otra vez lo empeoraria.
     */
    public static function orphanedCards(string $keyId, int $cards): self
    {
        return new self(
            'Hay '.$cards.' credencial(es) activa(s) firmada(s) con la clave '.$keyId.', que ya no esta configurada: '
            .'esas personas no pueden fichar. Cierra esa rotacion antes de abrir otra '
            .'(docs/runbooks/rotacion-clave-qr.md).'
        );
    }
}
