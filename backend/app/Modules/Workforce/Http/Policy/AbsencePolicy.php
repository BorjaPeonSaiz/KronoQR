<?php

declare(strict_types=1);

namespace App\Modules\Workforce\Http\Policy;

use App\Modules\Shared\Application\Port\ManagementActor;
use App\Modules\Shared\Domain\ValueObject\UserRole;
use App\Modules\Workforce\Domain\Model\Absence;

/**
 * Quien puede ver y registrar ausencias (**RF-GP-04**, Anexo B del doc 01, regla
 * dura 18).
 *
 * ## Leer es «manager+», escribir es «rrhh+»
 *
 * El `responsable_departamento` **lee** —acotado a su departamento, RF-ID-03—
 * porque quien organiza el turno tiene que saber quien falta y por que
 * categoria: esa es la informacion que la pantalla existe para dar, y sin ella
 * el responsable acabaria preguntandolo por WhatsApp, que es peor para todos.
 *
 * **No escribe.** Registrar una ausencia cambia el absentismo de una persona,
 * que es un numero con consecuencias laborales, y el Anexo B situa la gestion de
 * plantilla en `rrhh+`. Ademas le falta el ambito: `employees:*` no esta en su
 * token (§7.3), solo `employees:read`, asi que ni siquiera pasa del middleware.
 * Las dos comprobaciones dicen lo mismo, que es como tiene que ser.
 *
 * El `auditor` no entra en ninguna: audita lo que quedo escrito, y lo suyo es
 * `GET /reports/legal-export`.
 *
 * ## Un metodo por endpoint, aunque tres compartan lista
 *
 * `create`, `correct`, `void` e `import` son el mismo conjunto de roles y aun
 * asi son cuatro metodos. No es simetria decorativa: son cuatro endpoints y la
 * regla dura 18 pide una prueba de autorizacion negativa **por endpoint**. Con
 * un solo metodo, el dia que el producto decida que un responsable puede
 * registrar las ausencias de su gente —que es una peticion razonable— habria que
 * partirlo con el riesgo de abrir tambien la anulacion.
 *
 * ## `viewNote` es autorizacion, no presentacion
 *
 * La nota de una ausencia puede llevar un diagnostico, y una baja medica es dato
 * de salud (regla dura 21). Que el `responsable_departamento` no la reciba es
 * una decision de **autorizacion** y por eso se declara aqui, junto al resto, y
 * no como un `if` dentro del `Resource`: escrita en el `Resource` seria invisible
 * desde la matriz de autorizacion y nadie la probaria en negativo.
 *
 * ## Se registra contra el modelo de DOMINIO
 *
 * {@see Absence} y no la fila de Eloquent, por lo mismo que el resto de las
 * policies de este modulo: asi la autorizacion se decide **antes** de tocar la
 * base de datos.
 */
final class AbsencePolicy
{
    /**
     * Quien puede **leer** ausencias («manager+»).
     *
     * El responsable entra acotado: el `403` no es su respuesta al listar —eso
     * convertiria su pantalla en un error permanente— sino una pagina que solo
     * contiene a su gente, resuelta en el `WHERE` de la consulta.
     *
     * @return list<UserRole>
     */
    private static function readers(): array
    {
        return [UserRole::ADMIN, UserRole::RRHH, UserRole::RESPONSABLE_DEPARTAMENTO];
    }

    /**
     * Quien puede **escribir** ausencias («rrhh+»).
     *
     * @return list<UserRole>
     */
    private static function writers(): array
    {
        return [UserRole::ADMIN, UserRole::RRHH];
    }

    /** `GET /api/v1/absences`. */
    public function viewAny(ManagementActor $actor): bool
    {
        return $actor->actsAs(...self::readers());
    }

    /** `GET /api/v1/absences/{uuid}`. */
    public function view(ManagementActor $actor): bool
    {
        return $actor->actsAs(...self::readers());
    }

    /**
     * Si el texto libre de la ausencia le corresponde a quien pregunta.
     *
     * Solo `rrhh+`. Para el responsable el campo **no viaja**, y no llega a
     * `null`: la diferencia importa porque `null` significa «no hay nota» y aqui
     * lo cierto es «no te corresponde».
     */
    public function viewNote(ManagementActor $actor): bool
    {
        return $actor->actsAs(...self::writers());
    }

    /** `POST /api/v1/absences`. */
    public function create(ManagementActor $actor): bool
    {
        return $actor->actsAs(...self::writers());
    }

    /** `PATCH /api/v1/absences/{uuid}`. */
    public function correct(ManagementActor $actor): bool
    {
        return $actor->actsAs(...self::writers());
    }

    /** `POST /api/v1/absences/{uuid}/void`. */
    public function void(ManagementActor $actor): bool
    {
        return $actor->actsAs(...self::writers());
    }

    /** `POST /api/v1/absences/import`. */
    public function import(ManagementActor $actor): bool
    {
        return $actor->actsAs(...self::writers());
    }
}
