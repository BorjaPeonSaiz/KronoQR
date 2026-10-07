<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application\Exception;

use RuntimeException;

/**
 * Ya hay una cuenta de gestion —activa o dada de baja— con ese correo
 * (RF-ID-10): `409`.
 *
 * Las bajas conservan su correo a proposito: el historico de lo que firmo una
 * persona no se reasigna a otra que llega despues con la misma direccion.
 *
 * **El mensaje no repite el correo** (regla dura 21): esta excepcion puede
 * acabar en un log o en `error_events`.
 */
final class ManagementAccountEmailTaken extends RuntimeException
{
    public const string TRANSLATION_KEY = 'accounts.email_taken';

    public readonly string $translationKey;

    public function __construct()
    {
        $this->translationKey = self::TRANSLATION_KEY;

        parent::__construct('Ya hay una cuenta de gestion con ese correo, activa o dada de baja.');
    }
}
