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
 * ## Y NUNCA UN ACCESO DE SOPORTE DEL FABRICANTE
 *
 * Regla dura 16, ADR-020 y art. 9 del RGPD. `SupportScope::ReadOnly` lleva
 * `employees:read` —el ambito del que cuelgan las dos rutas de lectura de
 * ausencias— y `SupportScope::actsAs()` devuelve `admin` ante las policies, asi
 * que sin esta comprobacion **pasa el middleware y pasa la lista de roles**: un
 * token del fabricante leeria el registro de ausencias entero, con el `type` y
 * con la `note`.
 *
 * Y no es un dato cualquiera. `sick_leave` es **dato de salud** —categoria
 * especial del art. 9 del RGPD— y la nota puede llevar un diagnostico: es el
 * conjunto mas sensible que este modulo sirve. El fabricante no accede a los
 * datos del cliente, y menos aun a los del art. 9, ni con una concesion vigente
 * ni mientras diagnostica una incidencia. Para eso esta el paquete
 * **anonimizado** (RL-19).
 *
 * Se cierra en **las siete** habilidades y no solo en las tres de lectura. Las
 * cuatro de escritura ya se quedan hoy en el middleware —ningun alcance de
 * soporte concede `employees:*`—, pero la regla dura 18 pide las dos
 * comprobaciones, y la que depende de la lista de ambitos de otro modulo no es
 * la que debe sostener sola «el fabricante no escribe una baja medica».
 *
 * Es el mismo cierre que ya hacen `DataExportPolicy`, `ErrorEventPolicy`,
 * `SettingsPolicy::updateConfidential()` y `ReportExportPolicy`, y por la misma
 * via: la pregunta va por el puerto {@see ManagementActor::isSupportActor()},
 * porque `Workforce` no puede importar nada de `Identity` ni de `Product`
 * (doc 02 §1.6, verificado por Deptrac).
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

    /**
     * Si quien pregunta es una cuenta **de la organizacion del cliente** con uno
     * de los roles indicados.
     *
     * Las dos condiciones en un solo sitio para que ninguna habilidad se pueda
     * escribir olvidando la primera: un `actsAs()` suelto en un metodo nuevo
     * seria una puerta abierta al fabricante sobre datos del art. 9, y no se
     * notaria al leerlo.
     */
    private static function isCustomerStaff(ManagementActor $actor, UserRole ...$roles): bool
    {
        if ($actor->isSupportActor()) {
            return false;
        }

        return $actor->actsAs(...$roles);
    }

    /** `GET /api/v1/absences`. */
    public function viewAny(ManagementActor $actor): bool
    {
        return self::isCustomerStaff($actor, ...self::readers());
    }

    /** `GET /api/v1/absences/{uuid}`. */
    public function view(ManagementActor $actor): bool
    {
        return self::isCustomerStaff($actor, ...self::readers());
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
        return self::isCustomerStaff($actor, ...self::writers());
    }

    /** `POST /api/v1/absences`. */
    public function create(ManagementActor $actor): bool
    {
        return self::isCustomerStaff($actor, ...self::writers());
    }

    /** `PATCH /api/v1/absences/{uuid}`. */
    public function correct(ManagementActor $actor): bool
    {
        return self::isCustomerStaff($actor, ...self::writers());
    }

    /** `POST /api/v1/absences/{uuid}/void`. */
    public function void(ManagementActor $actor): bool
    {
        return self::isCustomerStaff($actor, ...self::writers());
    }

    /** `POST /api/v1/absences/import`. */
    public function import(ManagementActor $actor): bool
    {
        return self::isCustomerStaff($actor, ...self::writers());
    }
}
