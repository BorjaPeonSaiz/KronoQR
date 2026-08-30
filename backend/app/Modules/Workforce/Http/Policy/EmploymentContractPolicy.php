<?php

declare(strict_types=1);

namespace App\Modules\Workforce\Http\Policy;

use App\Modules\Shared\Application\Port\ManagementActor;
use App\Modules\Shared\Domain\ValueObject\UserRole;
use App\Modules\Workforce\Domain\Model\EmploymentContract;

/**
 * Quien puede ver y registrar el contrato de una persona (**RF-GP-02**, Anexo B
 * del doc 01, regla dura 18).
 *
 * ## `rrhh+`, y aqui son dos roles para las dos cosas
 *
 * Consultar y registrar son la misma lista —`{admin, rrhh}`— y aun asi son dos
 * metodos. No es simetria decorativa: son dos endpoints y la regla dura 18 pide
 * una prueba de autorizacion negativa **por endpoint**. Con un solo metodo, el
 * dia que el producto decida que un responsable puede consultar el contrato de
 * su gente —que es una peticion razonable— habria que partirlo con el riesgo de
 * abrir tambien la escritura.
 *
 * ## Por que el `responsable_departamento` no entra, teniendo alcance
 *
 * Desde la tarea 2.1 tiene alcance propio (RF-ID-03) y ve a su gente en
 * `GET /employees`. El contrato es otra cosa: son las condiciones laborales
 * pactadas —horas, tipo de jornada, computo anual— y el Anexo B situa la gestion
 * de plantilla en `rrhh+`. Ademas le falta el ambito: `employees:*` no esta en
 * su token (§7.3), solo `employees:read`, asi que ni siquiera pasa del
 * middleware. Las dos comprobaciones dicen lo mismo, que es como tiene que ser.
 *
 * El `auditor` tampoco: audita lo que quedo escrito y lo suyo es
 * `GET /reports/legal-export`.
 *
 * ## Se registra contra el modelo de DOMINIO
 *
 * {@see EmploymentContract} y no la fila de Eloquent, por lo mismo que el resto
 * de las policies de este modulo: asi la autorizacion se decide **antes** de
 * tocar la base de datos. Declarada sobre la fila, habria que cargarla para
 * poder preguntar si se puede leer.
 */
final class EmploymentContractPolicy
{
    /**
     * Roles que gestionan condiciones laborales («rrhh+»).
     *
     * Metodo y no constante por lo mismo que en las hermanas: el conjunto puede
     * cambiar, y lo que no cambia —el alcance— se resuelve, no se enumera.
     *
     * @return list<UserRole>
     */
    private static function managers(): array
    {
        return [UserRole::ADMIN, UserRole::RRHH];
    }

    /** `GET /api/v1/employees/{uuid}/contracts`. */
    public function viewAny(ManagementActor $actor): bool
    {
        return $actor->actsAs(...self::managers());
    }

    /** `POST /api/v1/employees/{uuid}/contracts`. */
    public function create(ManagementActor $actor): bool
    {
        return $actor->actsAs(...self::managers());
    }
}
