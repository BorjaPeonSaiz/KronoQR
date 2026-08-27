<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application\Exception;

use RuntimeException;

/**
 * Las credenciales no valen.
 *
 * **Una sola excepcion para las tres causas** —correo inexistente, contrasena
 * incorrecta, cuenta desactivada— porque la respuesta es la misma para las tres
 * (`401` con `urn:kronoqr:problem:invalid-credentials`). Tener tres excepciones
 * distintas invitaria a que alguien las mapeara a tres mensajes distintos «para
 * ayudar al usuario», y con eso el panel pasaria a confirmar que correos
 * pertenecen a la empresa.
 *
 * El detalle si se escribe en el log del servidor, donde tiene valor para quien
 * diagnostica y ninguno para quien prueba credenciales.
 */
final class AuthenticationFailed extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('Las credenciales no son validas.');
    }
}
