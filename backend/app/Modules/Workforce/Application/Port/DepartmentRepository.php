<?php

declare(strict_types=1);

namespace App\Modules\Workforce\Application\Port;

use App\Modules\Workforce\Domain\Exception\DepartmentNameAlreadyTaken;
use App\Modules\Workforce\Domain\Model\Department;

/**
 * Los departamentos de cada centro.
 */
interface DepartmentRepository
{
    /**
     * @throws DepartmentNameAlreadyTaken
     */
    public function add(Department $department): Department;

    /**
     * @throws DepartmentNameAlreadyTaken
     */
    public function save(Department $department): void;

    public function findById(int $id): ?Department;

    /**
     * El departamento con su fila tomada `FOR NO KEY UPDATE`, el mismo candado
     * que tomaria su `UPDATE`. **Dentro de una transaccion abierta** y **antes
     * de la cadena de auditoria** (ADR-046 §1.1: filas padre → cadena): no
     * choca con el `FOR KEY SHARE` con el que una ficha comprueba su clave
     * ajena, y si ordena dos cambios del mismo departamento.
     */
    public function findForUpdate(int $id): ?Department;

    /**
     * El departamento con su responsable (uuid y nombre), aunque la cuenta este
     * dada de baja.
     */
    public function findView(int $id): ?DepartmentView;

    /**
     * Los departamentos de la instalacion (ADR-040).
     *
     * @return list<Department>
     */
    public function all(): array;

    /**
     * Los departamentos de la instalacion con su responsable, por nombre, en
     * una sola consulta.
     *
     * @return list<DepartmentView>
     */
    public function allViews(): array;

    /**
     * Fija (`$managerUserId`) o quita (`null`) el responsable. Quien llama ha
     * comprobado ya que la cuenta es elegible ({@see ManagementAccountLookup}).
     */
    public function assignManager(int $departmentId, ?int $managerUserId): void;
}
