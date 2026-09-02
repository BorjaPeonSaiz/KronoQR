<?php

declare(strict_types=1);

namespace App\Modules\Product\Http\Policy;

use App\Modules\Shared\Application\Port\ManagementActor;
use App\Modules\Shared\Domain\ValueObject\UserRole;

/**
 * Quien puede ver y activar la licencia (RF-PD-04, regla dura 18).
 *
 * ## Solo `admin`
 *
 * El Anexo B del doc 01 marca las dos rutas como `[rol: admin]` y el §7.3
 * concede `license:*` unicamente al administrador de instalacion. El middleware
 * `ability` comprueba el ambito y esta policy el rol: dos controles distintos
 * que aqui coinciden. Sin la policy, bastaria un token emitido a mano con el
 * ambito correcto para leer el nombre del cliente, su plan y sus cifras de
 * plantilla, o para sustituir la licencia por otra.
 *
 * **`rrhh` no entra**, aunque sea quien mas usa los informes que la licencia
 * gobierna: lo que se contrato es una decision de quien firma el contrato, no de
 * quien gestiona la plantilla. **El `auditor` tampoco**: su trabajo es el
 * registro horario, y la licencia no forma parte de el —de hecho, la promesa del
 * producto es justo la contraria (ADR-019)—. Lo que necesite sobre activaciones
 * lo tiene en `audit_log` con `audit:read`.
 *
 * **El quiosco menos que nadie.** Su token lleva tres ambitos y ninguno es
 * `license:*`, asi que se queda en el middleware; la policy lo rechazaria
 * igualmente. Es la segunda mitad de la regla dura 19: el quiosco no se entera
 * de la licencia por ningun camino, ni en el de fichaje ni en ningun otro.
 *
 * ## Dos metodos aunque el conjunto de roles sea el mismo
 *
 * Para que la autorizacion negativa pruebe cada endpoint por separado: un
 * `authorize()` que devolviera `true` en uno solo de los dos seria invisible
 * desde el otro.
 */
final class LicensePolicy
{
    /**
     * @return list<UserRole>
     */
    private static function administrators(): array
    {
        return [UserRole::ADMIN];
    }

    public function view(ManagementActor $actor): bool
    {
        return $actor->actsAs(...self::administrators());
    }

    public function activate(ManagementActor $actor): bool
    {
        return $actor->actsAs(...self::administrators());
    }
}
