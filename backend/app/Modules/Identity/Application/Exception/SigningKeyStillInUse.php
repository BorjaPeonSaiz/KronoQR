<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application\Exception;

use RuntimeException;

/**
 * Se ha intentado retirar una clave que todavia firma tarjetas vivas (RF-QR-07,
 * doc 02 §5.3).
 *
 * **Es el control que impide dejar a alguien sin poder fichar.** Retirar la
 * clave anterior no revoca nada: hace que sus firmas dejen de verificar, y desde
 * ese instante quien lleve una tarjeta de ese lote se planta delante del quiosco
 * a las 06:00 con un rechazo generico que no le dice nada (RS-03, y es correcto
 * que no se lo diga). Por eso la retirada solo se admite cuando el recuento es
 * cero, que es literalmente el procedimiento del §5.3.
 *
 * El mensaje dice **cuantas quedan y en que centro**, porque lo siguiente que
 * hara quien lo lea es ir a buscarlas.
 */
final class SigningKeyStillInUse extends RuntimeException
{
    public static function withCards(string $keyId, int $cards, string $siteName): self
    {
        return new self(
            'La clave '.$keyId.' todavia firma '.$cards.' credencial(es) activa(s) en '.$siteName.'. '
            .'Reimprime y entrega esas tarjetas antes de retirarla: '
            .'php artisan credentials:status --key-id='.$keyId
        );
    }

    /** Retirar la clave con la que se firma hoy dejaria la instalacion sin poder imprimir. */
    public static function isCurrentKey(string $keyId): self
    {
        return new self(
            'La clave '.$keyId.' es la actual: es con la que se firma todo lo que se imprime. '
            .'Retira la saliente, no la vigente.'
        );
    }
}
