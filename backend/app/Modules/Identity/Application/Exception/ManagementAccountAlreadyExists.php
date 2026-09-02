<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application\Exception;

use RuntimeException;

/**
 * Se pide crear el primer administrador y **ya hay cuentas de gestion**
 * (RF-PD-03).
 *
 * ## Por que es `409` y no `403`
 *
 * Porque no es un problema de permisos: nadie esta autenticado y nadie tiene por
 * que estarlo. Es el estado del sistema el que ha cambiado —la puesta en marcha
 * de cuentas ya paso— y por eso la accion siguiente no es «consigue un token»
 * sino «entra por la puerta normal».
 *
 * ## Por que el mensaje dice a donde ir
 *
 * Este es el caso que ocurre de verdad: alguien crea la cuenta, se le cierra la
 * pestaña antes de escanear el QR del autenticador y vuelve a empezar. Un
 * mensaje que solo dijera «ya existe» dejaria a esa persona **fuera de su propia
 * instalacion**, con la cuenta creada, sin segundo factor y sin saber que
 * `POST /api/v1/auth/login` le devuelve el mismo reto con
 * `enrolment_required: true`. Un callejon sin salida del que solo se sale con
 * consola es justo lo que RF-PD-03 prohibe por escrito.
 */
final class ManagementAccountAlreadyExists extends RuntimeException
{
    public const string TRANSLATION_KEY = 'setup.administrator_exists';

    public readonly string $translationKey;

    public function __construct()
    {
        $this->translationKey = self::TRANSLATION_KEY;

        parent::__construct(
            'This installation already has a management account: the first administrator '
            .'can only be created while there are none. Sign in at POST /api/v1/auth/login.',
        );
    }
}
