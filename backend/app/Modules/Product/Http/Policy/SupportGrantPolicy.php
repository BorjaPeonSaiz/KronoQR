<?php

declare(strict_types=1);

namespace App\Modules\Product\Http\Policy;

use App\Modules\Shared\Application\Port\ManagementActor;
use App\Modules\Shared\Domain\ValueObject\UserRole;

/**
 * Quien puede ver, conceder y revocar accesos de soporte (**RF-PD-11**, regla
 * dura 18).
 *
 * ## Solo `admin`, y ademas solo el cliente
 *
 * Son **dos** condiciones y las dos hacen falta. El Anexo B del doc 01 marca las
 * tres rutas como `[rol: admin]` y el §7.3 concede `support:*` unicamente al
 * administrador de instalacion; el middleware `ability` comprueba el ambito y
 * esta policy comprueba el rol.
 *
 * Y hay una tercera comprobacion que ninguna otra policy del producto necesita:
 * **quien recibe el acceso no puede ampliarselo**. Una concesion de soporte con
 * alcance `configuration` actua como `admin` ante las policies —tiene que
 * hacerlo, o no podria cambiar un ajuste— asi que, sin
 * {@see ManagementActor::isSupportActor()}, seria indistinguible del
 * administrador del hotel justo en el endpoint que decide cuanto acceso tiene el
 * fabricante. Ahi la escalada seria completa: concederse a si mismo una
 * concesion `read_only` de 72 horas, o revocar la que el cliente acaba de cortar.
 *
 * El token de soporte no lleva `support:*` y por tanto se quedaria en el
 * middleware. Esto es la segunda puerta, y existe por lo mismo que existen
 * siempre las dos: un token emitido a mano, un ambito añadido por error o un
 * refactor de la lista de alcances no pueden bastar para cruzarla.
 *
 * ## Quien no entra
 *
 * **`rrhh` no entra**, aunque gestione los datos que un acceso de soporte podria
 * llegar a leer: autorizar la entrada del fabricante es una decision de quien
 * responde de la instalacion, y ademas es la firma del encargo del art. 28 RGPD
 * (RL-18). **El `auditor` tampoco**: su trabajo es mirar el registro, y lo que
 * necesite sobre accesos de soporte lo tiene en `audit_log` con `audit:read`,
 * que es exactamente el reparto correcto —quien vigila no autoriza—. **El
 * `responsable_departamento` menos aun**: su alcance es su departamento y esto no
 * es de ningun departamento. **El quiosco y el portal** no llevan ninguno de
 * estos ambitos y se quedan en el middleware.
 *
 * ## Tres metodos aunque el conjunto de roles sea el mismo
 *
 * Para que la autorizacion negativa pruebe cada endpoint por separado: un
 * `authorize()` que devolviera `true` en uno solo de los tres seria invisible
 * desde los otros dos.
 */
final class SupportGrantPolicy
{
    public function viewAny(ManagementActor $actor): bool
    {
        return self::isInstallationAdministrator($actor);
    }

    public function grant(ManagementActor $actor): bool
    {
        return self::isInstallationAdministrator($actor);
    }

    public function revoke(ManagementActor $actor): bool
    {
        return self::isInstallationAdministrator($actor);
    }

    /**
     * Administrador **del cliente**, no un acceso del fabricante que actua como
     * tal. Ver el docblock de la clase.
     */
    private static function isInstallationAdministrator(ManagementActor $actor): bool
    {
        if ($actor->isSupportActor()) {
            return false;
        }

        return $actor->actsAs(UserRole::ADMIN);
    }
}
