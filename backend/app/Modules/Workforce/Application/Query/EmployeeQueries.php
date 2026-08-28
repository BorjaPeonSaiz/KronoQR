<?php

declare(strict_types=1);

namespace App\Modules\Workforce\Application\Query;

use App\Modules\Shared\Application\Port\PersonalDataAccessLog;
use App\Modules\Shared\Domain\ValueObject\EmploymentStatus;
use App\Modules\Workforce\Application\Port\EmployeePinRepository;
use App\Modules\Workforce\Application\Port\EmployeeRepository;
use App\Modules\Workforce\Application\Port\PinStatus;
use App\Modules\Workforce\Domain\Model\Employee;

/**
 * Lecturas de plantilla.
 *
 * **Por que existe si envuelve al repositorio casi sin anadir nada.** Porque es
 * donde entrara el ambito por departamento de RF-ID-03 en la tarea 2.1: un
 * responsable solo puede ver a los empleados de su departamento y su centro, y
 * ese filtro tiene que aplicarse en un unico sitio del lado del servidor. Si los
 * controladores consultaran el repositorio directamente, ese filtro habria que
 * anadirlo en cada uno y bastaria olvidarlo en uno para que el ambito no
 * existiera.
 *
 * **Y por lo mismo es el sitio donde el listado deja constancia** (RS-05): una
 * pagina de este listado es un conjunto de personas —nombre, codigo, centro,
 * departamento— y quien la pide es un tercero. El asiento describe el alcance
 * —filtros, pagina, cuantas filas— y nunca lo divulgado (regla dura 21).
 *
 * La ficha individual ({@see find()}) **no** deja asiento propio, y no es un
 * olvido: quien puede abrir una ficha puede listar el indice, y el asiento del
 * indice ya dice que esa cuenta tuvo el directorio delante. Duplicarlo por cada
 * ficha abierta llenaria `audit_log` con la operativa ordinaria de RRHH sin
 * cambiar la respuesta a «que se llevo esa cuenta» (RL-15).
 */
final readonly class EmployeeQueries
{
    /** Vocabulario estable del `audit_log`, en ingles y sin datos dentro. */
    private const string DATASET = 'employee_directory';

    public function __construct(
        private EmployeeRepository $employees,
        private EmployeePinRepository $pins,
        private PersonalDataAccessLog $disclosures,
    ) {}

    /**
     * @return array{items: list<Employee>, total: int, page: int, per_page: int, total_pages: int}
     */
    public function page(
        ?int $siteId,
        ?int $departmentId,
        ?EmploymentStatus $status,
        ?string $search,
        int $page,
        int $perPage,
    ): array {
        $total = $this->employees->countMatching($siteId, $departmentId, $status, $search);

        $items = $this->employees->search($siteId, $departmentId, $status, $search, $perPage, ($page - 1) * $perPage);

        // Antes de devolver, no despues: si la escritura de auditoria falla, la
        // divulgacion no ocurre (regla dura 6, ADR-027). El recuento es el de las
        // filas de ESTA pagina, que es lo que de verdad sale por la respuesta;
        // `total` describe el filtro, no lo entregado.
        $this->disclosures->recordDisclosure(self::DATASET, \count($items), [
            ...($siteId === null ? [] : ['site_id' => $siteId]),
            ...($departmentId === null ? [] : ['department_id' => $departmentId]),
            'status' => $status === null ? 'any' : $status->value,
            // **El termino NO se guarda, solo si lo hubo.** Quien busca en el
            // panel escribe el nombre de una persona, asi que el termino es un
            // dato personal: escribirlo en `audit_log` seria copiar nombres a la
            // tabla de cuatro años de retencion que se enseña en una inspeccion
            // (regla dura 21, y el criterio de este mismo fichero de registrar
            // el alcance y nunca lo divulgado). Para acotar una brecha (RL-15)
            // basta con saber que la pagina salio de una busqueda: el recuento
            // de filas ya dice cuanto se llevo.
            'search' => $search !== null,
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

    public function find(string $uuid): ?Employee
    {
        return $this->employees->findByUuid($uuid);
    }

    /**
     * Situacion del PIN de una persona (RF-ID-09). **El estado, nunca el PIN.**
     *
     * `pending` para quien no exista: quien pregunta ya ha recibido su `404` por
     * otro camino, y devolver aqui un estado distinto para «no existe» seria un
     * matiz por el que se podria enumerar la plantilla (regla dura 17).
     */
    public function pinStatus(string $uuid): PinStatus
    {
        return $this->pins->statusFor($uuid) ?? PinStatus::Pending;
    }

    /**
     * El estado del PIN de una pagina entera, en una sola consulta.
     *
     * Sin esto, pintar un listado de cien personas costaria cien consultas —el
     * problema N+1 con otro nombre— y el listado de plantilla es de las
     * pantallas mas usadas del panel.
     *
     * @param  list<Employee>  $employees
     * @return array<string, PinStatus> Indexado por UUID de empleado.
     */
    public function pinStatusesFor(array $employees): array
    {
        return $this->pins->statusesFor(array_map(
            static fn (Employee $employee): string => $employee->uuid,
            $employees,
        ));
    }
}
