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

    /**
     * Administrador **del cliente**, no un acceso de soporte que actua como tal
     * (**RF-PD-11**, ADR-020, tarea 5.9).
     *
     * Los tres alcances de una concesion de soporte se presentan como `admin`
     * ante las policies —es lo que les permite llegar a las pantallas que si les
     * corresponden—, asi que sin esta segunda comprobacion serian
     * indistinguibles del administrador del hotel justo aqui.
     *
     * **Ninguno de los tres lleva `license:*`**, asi que el middleware ya los
     * deja fuera. Esto es la segunda puerta, y existe por lo mismo que existen
     * siempre las dos (regla dura 18): un token emitido a mano, un ambito
     * añadido por error o un refactor de la lista de alcances no pueden bastar
     * para cruzarla.
     */
    private static function isInstallationAdministrator(ManagementActor $actor): bool
    {
        if ($actor->isSupportActor()) {
            return false;
        }

        return $actor->actsAs(...self::administrators());
    }

    /**
     * **La lectura tambien se le cierra al fabricante**, y no es celo: la
     * respuesta lleva la **razon social del cliente**, su plan y sus cifras de
     * plantilla. Es informacion comercial del cliente sobre su propio contrato,
     * y el fabricante ya la tiene por otro lado — no la saca de la instalacion
     * de nadie. Es ademas el mismo criterio con el que el paquete de diagnostico
     * excluye `customer_name` (doc 07 §6).
     */
    public function view(ManagementActor $actor): bool
    {
        return self::isInstallationAdministrator($actor);
    }

    /**
     * Activar una licencia es un acto del CLIENTE. Lo que se contrato lo decide
     * quien firma el contrato, y el fabricante no se activa a si mismo un plan
     * en la instalacion de otro (contrato: «lo que ningun alcance concede
     * nunca»).
     */
    public function activate(ManagementActor $actor): bool
    {
        return self::isInstallationAdministrator($actor);
    }
}
