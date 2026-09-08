<?php

declare(strict_types=1);

namespace App\Modules\Product\Http\Policy;

use App\Modules\Shared\Application\Port\ManagementActor;
use App\Modules\Shared\Domain\ValueObject\UserRole;

/**
 * Quien puede llevarse **todos** los datos de la instalacion (**RF-PD-14**,
 * RL-20, regla dura 18, nota 6 del doc 02 §7.3).
 *
 * ## `admin`, y ademas solo el cliente
 *
 * Son dos condiciones y las dos hacen falta.
 *
 * **`admin` y nadie mas** porque llevarse una copia completa de la plantilla, de
 * cuatro años de fichajes y de las cuentas de gestion es la potestad de quien
 * responde de la instalacion, y no hay ningun rol que la usaria por separado.
 * **`rrhh` no entra** aunque gestione a diario los datos que el fichero
 * contiene: gestionarlos dentro del producto, con su alcance y su auditoria, no
 * es lo mismo que sacarlos en un ZIP. **El `auditor` tampoco**: su trabajo es
 * mirar el registro dentro del sistema, y para lo que necesita entregar a la
 * Inspeccion esta la exportacion legal (RF-IN-05), acotada por periodo y por
 * persona. **El `responsable_departamento` menos aun**: su alcance es su
 * departamento y esto es la instalacion entera. **El quiosco y el portal** no
 * llevan `settings:*` y se quedan en el middleware.
 *
 * **Y nunca el fabricante** (regla dura 16, ADR-020). Una concesion de soporte
 * con alcance `configuration` lleva `settings:*` y actua como `admin` ante las
 * policies —tiene que hacerlo, o no podria cambiar un ajuste—, asi que sin
 * {@see ManagementActor::isSupportActor()} seria indistinguible del
 * administrador del hotel justo en el endpoint que se lleva todos los datos del
 * cliente. Ese es el escenario que ADR-020 existe para hacer imposible: el
 * fabricante no accede a los datos del cliente, y menos aun a todos a la vez.
 *
 * ## Por que se pregunta al actor y no se comprueba su clase
 *
 * `Product` no puede importar nada de `Identity` (doc 02 §1.6, verificado por
 * Deptrac), asi que un `instanceof User` aqui seria una violacion de frontera. La
 * pregunta va por el puerto compartido {@see ManagementActor}.
 *
 * ## Tres metodos aunque los tres exijan lo mismo
 *
 * Para que la autorizacion negativa pruebe cada endpoint por separado: un
 * `return true` colado en uno solo de los tres seria invisible desde los otros
 * dos.
 *
 * ## Ninguna de las tres consulta la licencia
 *
 * Regla dura 15 y ADR-019: RL-20 es la garantia de continuidad del cliente
 * *«aunque la relacion comercial termine»*, asi que degradarla con la licencia
 * caducada seria apagar exactamente la salida de emergencia.
 */
final class DataExportPolicy
{
    public function viewAny(ManagementActor $actor): bool
    {
        return self::isInstallationAdministrator($actor);
    }

    public function request(ManagementActor $actor): bool
    {
        return self::isInstallationAdministrator($actor);
    }

    public function download(ManagementActor $actor): bool
    {
        return self::isInstallationAdministrator($actor);
    }

    /** Administrador **del cliente**, no un acceso del fabricante que actua como tal. */
    private static function isInstallationAdministrator(ManagementActor $actor): bool
    {
        if ($actor->isSupportActor()) {
            return false;
        }

        return $actor->actsAs(UserRole::ADMIN);
    }
}
