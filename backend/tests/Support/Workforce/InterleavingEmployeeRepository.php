<?php

declare(strict_types=1);

namespace Tests\Support\Workforce;

use App\Modules\Shared\Domain\ValueObject\AccessScope;
use App\Modules\Shared\Domain\ValueObject\EmploymentStatus;
use App\Modules\Workforce\Application\Port\EmployeeRepository;
use App\Modules\Workforce\Application\Port\PinStatus;
use App\Modules\Workforce\Domain\Model\Employee;
use Closure;

/**
 * El repositorio de verdad, con un gancho que se ejecuta **justo despues de la
 * lectura de la ficha que el caso de uso va a escribir** (ADR-046 §5,
 * R7-RV-01 / R4-BE-01).
 *
 * Sirve para intercalar dos escrituras de la misma ficha de forma determinista:
 * la primera ya ha leido la fila y todavia no ha escrito; en ese hueco corre la
 * segunda entera, en otra sesion de PostgreSQL ({@see EmployeeWriteInOtherSession}).
 * Es exactamente el orden que dejaba a una persona dada de baja otra vez activa.
 *
 * **El gancho se dispara una sola vez, y solo para la ficha indicada.** La
 * importacion lee otras fichas antes y despues, y la escritura de la segunda
 * sesion no pasa por aqui (es otro proceso con su propio contenedor).
 *
 * ## Donde va el gancho (ADR-046 §2 y §3)
 *
 * Los casos de uso que escriben la ficha leen con `findForUpdate()` y escriben
 * con `saveProfile()` / `saveTermination()`. El gancho esta en
 * `findForUpdate()` —se dispara con el candado YA tomado— y `findByUuid()` es
 * delegacion pura: la comprobacion de una importacion lee con el y no debe
 * dispararlo.
 *
 * Si el gancho estuviera en `findByUuid()`, la segunda sesion nunca correria en
 * el hueco y la prueba lo diria: afirma que la otra escritura **esperaba** al
 * candado.
 */
final class InterleavingEmployeeRepository implements EmployeeRepository
{
    private bool $fired = false;

    /**
     * @param  Closure(): void  $afterRead  Lo que corre en el hueco entre la lectura y la escritura.
     */
    public function __construct(
        private readonly EmployeeRepository $inner,
        private readonly string $employeeUuid,
        private readonly Closure $afterRead,
    ) {}

    public function findByUuid(string $uuid): ?Employee
    {
        return $this->inner->findByUuid($uuid);
    }

    /**
     * La lectura bloqueante de quien va a escribir: el gancho corre aqui, con el
     * candado de la fila —y el de la cadena— ya tomados.
     */
    public function findForUpdate(string $uuid): ?Employee
    {
        $employee = $this->inner->findForUpdate($uuid);

        $this->afterReadOf($uuid);

        return $employee;
    }

    public function add(Employee $employee, ?string $nationalId = null): void
    {
        $this->inner->add($employee, $nationalId);
    }

    public function saveProfile(Employee $employee, bool $statusChanged): void
    {
        $this->inner->saveProfile($employee, $statusChanged);
    }

    public function saveTermination(Employee $employee): void
    {
        $this->inner->saveTermination($employee);
    }

    public function search(
        AccessScope $scope,
        ?int $departmentId,
        ?EmploymentStatus $status,
        ?string $search,
        ?PinStatus $pinStatus,
        ?bool $teleworking,
        int $limit,
        int $offset,
    ): array {
        return $this->inner->search($scope, $departmentId, $status, $search, $pinStatus, $teleworking, $limit, $offset);
    }

    public function countMatching(
        AccessScope $scope,
        ?int $departmentId,
        ?EmploymentStatus $status,
        ?string $search,
        ?PinStatus $pinStatus,
        ?bool $teleworking,
    ): int {
        return $this->inner->countMatching($scope, $departmentId, $status, $search, $pinStatus, $teleworking);
    }

    private function afterReadOf(string $uuid): void
    {
        if ($this->fired || $uuid !== $this->employeeUuid) {
            return;
        }

        $this->fired = true;
        ($this->afterRead)();
    }
}
