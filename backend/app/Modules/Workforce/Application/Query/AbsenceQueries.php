<?php

declare(strict_types=1);

namespace App\Modules\Workforce\Application\Query;

use App\Modules\Shared\Application\Port\PersonalDataAccessLog;
use App\Modules\Workforce\Application\Port\AbsenceFilter;
use App\Modules\Workforce\Application\Port\AbsenceRepository;
use App\Modules\Workforce\Application\Port\AbsenceView;
use App\Modules\Workforce\Domain\ValueObject\AbsenceStatus;
use App\Modules\Workforce\Domain\ValueObject\AbsenceType;

/**
 * Lecturas de ausencias (**RF-GP-04**).
 *
 * **Por que existe si envuelve al repositorio casi sin añadir nada.** Por lo
 * mismo que {@see EmployeeQueries}: es donde vive el alcance de RF-ID-03 y donde
 * queda constancia de lo divulgado (RS-05). Si los controladores consultaran el
 * repositorio directamente, esas dos cosas habria que repetirlas en cada uno y
 * bastaria olvidarlas en uno.
 *
 * **El alcance entra como parametro y no se resuelve aqui.** Quien esta
 * autenticado lo sabe la capa HTTP; esta capa no puede preguntarselo al
 * contenedor sin atarse al transporte.
 *
 * ## Por que el listado deja asiento y el detalle no
 *
 * Una pagina de este listado es un conjunto de personas —nombre, codigo,
 * departamento— **y ademas la categoria por la que faltan**, que en el caso de
 * `sick_leave` es dato de salud. Quien la pide es un tercero, asi que el asiento
 * describe el alcance —filtros, pagina, cuantas filas— y nunca lo divulgado
 * (regla dura 21).
 *
 * El detalle individual no deja asiento propio, con el mismo criterio que la
 * ficha de un empleado: quien puede abrir una ausencia puede listar el indice, y
 * el asiento del indice ya dice que esa cuenta tuvo el conjunto delante.
 * Duplicarlo por cada detalle abierto llenaria `audit_log` con la operativa
 * ordinaria de RRHH sin cambiar la respuesta a «que se llevo esa cuenta»
 * (RL-15).
 */
final readonly class AbsenceQueries
{
    /** Vocabulario estable del `audit_log`, en ingles y sin datos dentro. */
    private const string DATASET = 'absence_register';

    public function __construct(
        private AbsenceRepository $absences,
        private PersonalDataAccessLog $disclosures,
    ) {}

    /**
     * @return array{items: list<AbsenceView>, total: int, page: int, per_page: int, total_pages: int}
     */
    public function page(AbsenceFilter $filter, int $page, int $perPage): array
    {
        $total = $this->absences->countMatching($filter);
        $items = $this->absences->search($filter, $perPage, ($page - 1) * $perPage);

        // Antes de devolver, no despues: si la escritura de auditoria falla, la
        // divulgacion no ocurre (regla dura 6, ADR-027). El recuento es el de
        // las filas de ESTA pagina, que es lo que de verdad sale por la
        // respuesta; `total` describe el filtro, no lo entregado.
        $this->disclosures->recordDisclosure(self::DATASET, \count($items), [
            'from' => $filter->from->format('Y-m-d'),
            'to' => $filter->to->format('Y-m-d'),
            ...($filter->departmentId === null ? [] : ['department_id' => $filter->departmentId]),
            // El UUID publico de la persona SI entra cuando el filtro acota a
            // una: es lo que distingue «alguien miro el cuadro del mes» de
            // «alguien consulto las ausencias de esta persona concreta», que
            // ante una brecha (RL-15) no es lo mismo. Nunca su nombre.
            ...($filter->employeeUuid === null ? [] : ['employee_uuid' => $filter->employeeUuid]),
            // El operador ternario y no `?->value ?? …`: el `value` de un enum
            // nunca es nulo, asi que el `??` de la derecha seria codigo muerto y
            // PHPStan 9 lo dice en voz alta. Lo que puede faltar es el filtro
            // entero.
            'type' => $filter->type instanceof AbsenceType ? $filter->type->value : 'any',
            'status' => $filter->status instanceof AbsenceStatus ? $filter->status->value : 'all',
            // El alcance con el que se sirvio la pagina (RF-ID-03). Va el tipo,
            // no la lista de departamentos: quien pregunto ya esta identificado
            // como actor.
            'scope' => $filter->scope->isUnrestricted() ? 'all' : 'departments',
            'page' => $page,
            'per_page' => $perPage,
        ]);

        return [
            'items' => $items,
            'total' => $total,
            'page' => $page,
            'per_page' => $perPage,
            'total_pages' => (int) ceil($total / $perPage),
        ];
    }

    public function find(string $uuid): ?AbsenceView
    {
        return $this->absences->viewOf($uuid);
    }

    /**
     * Las versiones anteriores de esa ausencia, de la mas antigua a la mas
     * reciente y sin incluirla (RN-13).
     *
     * @return list<AbsenceView>
     */
    public function historyOf(string $uuid): array
    {
        return $this->absences->historyOf($uuid);
    }
}
