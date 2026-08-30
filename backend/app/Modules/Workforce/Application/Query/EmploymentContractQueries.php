<?php

declare(strict_types=1);

namespace App\Modules\Workforce\Application\Query;

use App\Modules\Workforce\Application\Port\EmploymentContractRepository;
use App\Modules\Workforce\Domain\Model\EmploymentContract;

/**
 * Las lecturas de contratos que sirve la API (**RF-GP-02**).
 *
 * Existe por lo mismo que {@see DepartmentQueries}: el controlador invoca a la
 * capa de aplicacion y no a un puerto de persistencia. Parece un envoltorio
 * fino, y lo es, pero es la costura por la que despues entran el alcance por
 * departamento o un filtro por vigencia sin tocar el controlador.
 */
final readonly class EmploymentContractQueries
{
    public function __construct(private EmploymentContractRepository $contracts) {}

    /**
     * La serie completa de una persona, del contrato mas antiguo al mas
     * reciente. Vacia si no existe o no tiene ninguno.
     *
     * @return list<EmploymentContract>
     */
    public function forEmployee(string $employeeUuid): array
    {
        return $this->contracts->forEmployee($employeeUuid);
    }
}
