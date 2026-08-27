<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application\Exception;

use RuntimeException;

/**
 * No se abre sesion de portal (RF-ID-06, RS-03, RS-12, regla dura 17).
 *
 * **Una sola excepcion para cinco causas**: codigo de empleado inexistente, PIN
 * incorrecto, PIN nunca emitido, empleado que no esta en alta (RN-14) y bloqueo
 * por intentos activo. Todas salen como el mismo `401`.
 *
 * ## Por que el bloqueo tampoco se distingue, al contrario que en el panel
 *
 * El acceso de gestion responde `429` con `Retry-After` cuando la cuenta esta
 * bloqueada, y ahi es correcto: quien entra al panel ya sabe que su cuenta
 * existe —tiene correo de empresa— y decirle cuanto falta le ahorra una llamada
 * a soporte.
 *
 * Aqui no. La mitad publica de esta credencial es un **codigo de empleado**
 * impreso en una tarjeta que se lleva colgada del cuello: anunciar un bloqueo
 * confirmaria que ese codigo existe, que es justamente lo que RS-03 no permite.
 * Y ademas convertiria el propio bloqueo en un oraculo —bastaria con medir si
 * llega antes o despues de la comparacion del hash para saber si el PIN probado
 * era el bueno—, que es la razon por la que
 * `Shared\Domain\ValueObject\PinVerification` deja escrito que su
 * `retryAfterSeconds` **no sale por la API**. Se nombra en prosa y no con
 * `@see` porque el enlace haria que el formateador lo convirtiera en un `use`, y
 * un `use` de `Shared\Domain` desde aqui no aporta nada al codigo.
 *
 * Quien no consigue entrar tiene una salida que no depende de adivinar: RRHH le
 * restablece el PIN (RF-ID-09), y eso limpia el contador en el momento.
 *
 * **El detalle si se escribe en el log del servidor** (§8.1), donde tiene valor
 * para quien diagnostica y ninguno para quien prueba PIN.
 */
final class PortalAccessDenied extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('El codigo de empleado o el PIN no son correctos.');
    }
}
