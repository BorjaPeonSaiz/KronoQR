<?php

declare(strict_types=1);

namespace App\Modules\Workforce\Infrastructure\Adapter;

use App\Modules\Shared\Application\Port\ClockingEmployees;
use App\Modules\Shared\Domain\ValueObject\EmploymentStatus;
use App\Modules\Shared\Domain\ValueObject\RosterMember;
use App\Modules\Workforce\Infrastructure\Persistence\Employee;

/**
 * Los empleados de un centro que pueden fichar, en la forma minima del padron
 * (RF-KI-03, doc 02 §7.3).
 *
 * **Es la arista de ADR-025**: el puerto vive en `Shared/Application/Port` porque
 * lo necesita `Kiosk` y el dato lo tiene `Workforce`, y ninguno de los dos puede
 * importar al otro (§1.6). El enlace se declara en `WorkforceServiceProvider`.
 *
 * **El nombre se compone igual que en {@see EloquentEmployeeDirectory}**, y no
 * por casualidad: la forma minima —nombre de pila e inicial del primer apellido—
 * es la misma que devuelve `POST /api/v1/scan`, porque si el padron cacheado
 * dijera «Maria Garcia» y la confirmacion del fichaje dijera «Maria G.», el
 * quiosco enseñaria dos nombres distintos para la misma persona segun tuviera red
 * o no.
 *
 * **Se seleccionan cuatro columnas y no la fila entera.** `employees` tiene
 * correo, documento hasheado, PIN y foto: traerlos para descartarlos despues
 * significaria que un dia alguien los usa. Lo que no se lee no se filtra.
 */
final readonly class EloquentClockingEmployees implements ClockingEmployees
{
    public function atSite(int $siteId): array
    {
        /** @var list<Employee> $rows */
        $rows = Employee::query()
            ->select(['id', 'first_name', 'last_name', 'status'])
            ->where('site_id', $siteId)
            // RN-14: quien esta de baja no aparece en el padron. La regla se
            // aplica aqui, en el modulo dueño del estado laboral, y no en quien
            // pinta el padron: en dos sitios acabaria decidiendose distinto.
            ->where('status', EmploymentStatus::ACTIVE->value)
            ->get()
            ->all();

        $members = [];

        foreach ($rows as $row) {
            $members[] = new RosterMember($row->id, $this->displayName($row));
        }

        return $members;
    }

    private function displayName(Employee $row): string
    {
        $initial = mb_substr(trim($row->last_name), 0, 1);

        return trim($row->first_name).' '.mb_strtoupper($initial).'.';
    }
}
