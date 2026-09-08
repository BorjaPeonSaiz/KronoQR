<?php

declare(strict_types=1);

namespace App\Modules\Product\Http\Policy;

use App\Modules\Shared\Application\Port\ManagementActor;
use App\Modules\Shared\Domain\ValueObject\UserRole;

/**
 * Quien puede generar el paquete de diagnostico (RF-PD-09, regla dura 18).
 *
 * ## `generate`: `admin` y solo `admin`
 *
 * El Anexo B marca la ruta como `[rol: admin]` y el §7.3 concede `diagnostics:*`
 * al administrador de instalacion. El middleware `ability` comprueba el ambito y
 * esta policy el rol: dos controles distintos que aqui coinciden. Sin la policy,
 * bastaria un token emitido a mano con el ambito correcto para sacar de la
 * instalacion el inventario completo de su configuracion.
 *
 * **`rrhh` no entra** aunque sea quien suele detectar el problema: generar el
 * paquete saca informacion del servidor hacia fuera, y esa es una decision del
 * administrador. **El `auditor` tampoco**: su ambito es el registro horario y
 * este fichero no forma parte de el. **El quiosco y el portal, menos**: sus
 * tokens no llevan `diagnostics:*` y se quedan en el middleware.
 *
 * **Un token de soporte SI entra** (RF-PD-11): un acceso concedido con alcance
 * `diagnostics` actua como `admin` ante esta policy, que es justamente para lo
 * que se concede. Genera el paquete **anonimizado**.
 *
 * ## `includePersonalData`: ademas, tiene que ser el cliente
 *
 * Es el metodo que convierte RL-19 en codigo. Incluir la plantilla y los
 * fichajes en un fichero que sale del servidor no lo puede decidir el
 * fabricante: **lo decide el responsable del tratamiento, que es el cliente**
 * (RL-16, ADR-020). Un actor de soporte con alcance `diagnostics` puede generar
 * el paquete anonimizado y recibe `403` si pide el otro.
 *
 * ## Por que se pregunta al actor y no se comprueba su clase
 *
 * `Product` no puede importar nada de `Identity` (doc 02 §1.6, verificado por
 * Deptrac), asi que un `instanceof User` aqui seria una violacion de frontera. La
 * pregunta va por el puerto compartido {@see ManagementActor::isSupportActor()},
 * que es donde vive el resto de lo que una policy necesita saber de quien esta
 * autenticado.
 *
 * ## Dos metodos aunque los dos exijan `admin`
 *
 * Para que la autorizacion negativa pruebe cada uno por separado, y porque no
 * exigen lo mismo: el segundo añade una condicion que el primero no tiene.
 */
final class DiagnosticsPolicy
{
    public function generate(ManagementActor $actor): bool
    {
        return $actor->actsAs(UserRole::ADMIN);
    }

    public function includePersonalData(ManagementActor $actor): bool
    {
        return $actor->actsAs(UserRole::ADMIN) && ! $actor->isSupportActor();
    }
}
