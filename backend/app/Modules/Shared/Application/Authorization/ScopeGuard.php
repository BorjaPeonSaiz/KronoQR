<?php

declare(strict_types=1);

namespace App\Modules\Shared\Application\Authorization;

use App\Modules\Shared\Application\Port\AuthorizationJournal;
use App\Modules\Shared\Application\Port\ManagementActor;
use App\Modules\Shared\Domain\Exception\AccessOutOfScope;
use App\Modules\Shared\Domain\ValueObject\AccessScope;

/**
 * La comprobacion de alcance **de un recurso concreto**, con su asiento
 * (**RF-ID-03**, RS-05, escenario «Aislamiento por departamento» del doc 01 §11).
 *
 * ## Por que existe en `Shared` y no en cada modulo
 *
 * La necesitan `Workforce` (la ficha), `Reporting` (el registro horario) y
 * `Attendance` (las correcciones), y ninguno puede importar a los otros (doc 02
 * §1.6). Repetirla tres veces significaria tres sitios donde olvidar el asiento, y
 * el asiento es lo que el escenario Gherkin exige por escrito: *«el intento queda
 * registrado en el trail de auditoria»*.
 *
 * ## Solo para el recurso individual
 *
 * **Los listados no pasan por aqui.** Un listado se acota **en la consulta**
 * —`WHERE department_id IN (...)`— y no devuelve `403`: quien tiene tres
 * departamentos ve a su gente y no se entera de que existe mas. Filtrar despues de
 * contar daria un `meta.total` que describe a personas que quien pregunta no puede
 * ver, que es una fuga por si misma; y devolver `403` al listar convertiria la
 * pantalla de plantilla de un responsable en un error permanente.
 *
 * Aqui llega lo que si tiene un sujeto identificable: una ficha, un registro
 * horario, un tramo. Ahi el `403` es la respuesta correcta y el asiento tiene a
 * quien apuntar.
 *
 * ## El asiento se escribe **antes** de lanzar
 *
 * Si dependiera del manejador de excepciones, un `catch` en cualquier punto del
 * camino dejaria el intento sin traza. Y va antes y no despues por lo mismo que en
 * el resto de la auditoria de lectura: si la escritura del asiento falla, la
 * peticion no puede acabar en `403` silencioso — acaba en error, que es ruidoso y
 * se arregla.
 */
final readonly class ScopeGuard
{
    public function __construct(private AuthorizationJournal $journal) {}

    /**
     * El alcance de quien esta autenticado, **fallando cerrado**.
     *
     * Existe aqui —y no como un ayudante en cada modulo— para que la respuesta a
     * «¿y si el actor no es una cuenta de gestion?» se escriba una sola vez. Y esa
     * respuesta es {@see AccessScope::forDepartments()} sin ningun departamento,
     * es decir **nadie**: si un token de quiosco o una sesion de portal llegaran
     * hasta una consulta de plantilla —les faltan el ambito y la policy, asi que no
     * pueden, pero el dia que alguien se equivoque—, lo que verian seria una lista
     * vacia y no la plantilla entera.
     *
     * @param  mixed  $actor  El `tokenable` del token. Se tipa laxo a proposito: el guard
     *                        entrega lo que haya autenticado.
     */
    public function scopeOf(mixed $actor): AccessScope
    {
        return $actor instanceof ManagementActor
            ? $actor->accessScope()
            : AccessScope::forDepartments();
    }

    /**
     * @param  string  $dataset  Vocabulario estable y en ingles: `employee_profile`,
     *                           `employee_workdays`, `shift_entry`.
     * @param  string|null  $employeeUuid  UUID publico de la persona afectada. **Nunca su
     *                                     nombre** (regla dura 21).
     * @param  array<string, scalar>  $context  Alcance del intento, sin datos personales.
     *
     * @throws AccessOutOfScope cuando el recurso queda fuera del alcance
     */
    public function ensureReaches(
        AccessScope $scope,
        ?int $departmentId,
        string $dataset,
        ?string $employeeUuid,
        array $context = [],
    ): void {
        if ($scope->reaches($departmentId)) {
            return;
        }

        $this->journal->recordScopeDenial($dataset, $employeeUuid, $context);

        throw new AccessOutOfScope;
    }
}
