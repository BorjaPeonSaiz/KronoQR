<?php

declare(strict_types=1);

namespace App\Modules\Workforce\Application\Query;

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
 */
final readonly class EmployeeQueries
{
    public function __construct(
        private EmployeeRepository $employees,
        private EmployeePinRepository $pins,
    ) {}

    /**
     * @return array{items: list<Employee>, total: int, page: int, per_page: int, total_pages: int}
     */
    public function page(
        ?int $siteId,
        ?int $departmentId,
        ?EmploymentStatus $status,
        int $page,
        int $perPage,
    ): array {
        $total = $this->employees->countMatching($siteId, $departmentId, $status);

        return [
            'items' => $this->employees->search($siteId, $departmentId, $status, $perPage, ($page - 1) * $perPage),
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
