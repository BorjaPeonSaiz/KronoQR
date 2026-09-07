<?php

declare(strict_types=1);

namespace App\Modules\Kiosk\Http\Policy;

use App\Modules\Shared\Application\Port\ManagementActor;
use App\Modules\Shared\Domain\ValueObject\UserRole;

/**
 * Quien puede confirmar un emparejamiento y gestionar la flota de quioscos
 * (**RF-PD-06**, regla dura 18).
 *
 * ## Solo `admin`, y con el mismo razonamiento que la configuracion
 *
 * El §7.3 del doc 02 —nota 5— concede `settings:*` unicamente al administrador de
 * instalacion, y las tres rutas de gestion del emparejamiento viajan bajo ese
 * ambito. El middleware `ability` comprueba el ambito y esta policy el rol: dos
 * controles distintos que aqui coinciden. Sin la policy, bastaria un token
 * emitido a mano con el ambito correcto para dar de alta un origen de fichajes.
 *
 * **`rrhh` no entra**, y es el caso que mas se le parece al autorizado: lleva
 * todos los ambitos de gestion —corrige fichajes, emite credenciales, genera
 * informes— y aun asi no llega aqui. Dar de alta un quiosco es crear un **origen
 * de fichajes**, y desvincularlo puede dejar un hotel sin poder fichar en pleno
 * cambio de turno: es la potestad de quien responde por la instalacion, no la de
 * quien gestiona la plantilla.
 *
 * **El `auditor` tampoco.** Es el rol que mira, y lo que necesita —quien vinculo
 * que quiosco y cuando— esta en `audit_log`, al que llega con `audit:read`.
 *
 * **Y el propio quiosco menos que nadie.** Un token de dispositivo no puede
 * confirmar emparejamientos —ni siquiera el suyo— ni leer la lista: conoceria a
 * sus vecinos y el estado de la instalacion. Ni siquiera pasa del middleware,
 * porque no lleva `settings:*`; esta policy es la segunda mitad.
 *
 * ## Se invoca por el `Gate`, al contrario que {@see KioskPolicy}
 *
 * Y la diferencia no es un descuido. Aquella autoriza a un **dispositivo**, cuyo
 * `tokenable` es una fila de `devices` que no implementa `Authorizable`: el
 * `Gate::before` del paquete de permisos reventaria con un `TypeError` antes de
 * decidir nada, asi que se invoca por su nombre. Estas tres rutas las llama una
 * **persona** desde el panel, con su cuenta y su rol, que es exactamente lo que el
 * `Gate` sabe manejar.
 *
 * ## Tres metodos aunque el conjunto de roles sea el mismo
 *
 * Para que la matriz de autorizacion negativa pruebe cada endpoint por separado
 * (regla dura 18): un `authorize()` que devolviera `true` en uno solo de los tres
 * seria invisible desde los otros dos.
 */
final class KioskPairingPolicy
{
    /**
     * @return list<UserRole>
     */
    private static function administrators(): array
    {
        return [UserRole::ADMIN];
    }

    /** `POST /api/v1/kiosk/pair/confirm`. */
    public function confirm(ManagementActor $actor): bool
    {
        return $actor->actsAs(...self::administrators());
    }

    /** `GET /api/v1/devices`. */
    public function viewAny(ManagementActor $actor): bool
    {
        return $actor->actsAs(...self::administrators());
    }

    /** `POST /api/v1/devices/{uuid}/unpair`. */
    public function unpair(ManagementActor $actor): bool
    {
        return $actor->actsAs(...self::administrators());
    }
}
