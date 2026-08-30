<?php

declare(strict_types=1);

namespace App\Modules\Compliance\Application\Port;

use App\Modules\Compliance\Domain\ValueObject\IncidentSeverity;
use App\Modules\Compliance\Domain\ValueObject\IncidentStatus;
use App\Modules\Compliance\Domain\ValueObject\IncidentType;
use App\Modules\Shared\Domain\ValueObject\AccessScope;

/**
 * Lo que se pide a la bandeja: hasta donde alcanza quien pregunta, que filtra y
 * que pagina quiere (RF-PA-05, RF-ID-03).
 *
 * **El alcance va primero y no es opcional.** Es la unica acotacion que quien
 * llama no elige, y ponerla en la primera posicion —igual que en
 * `LivePresenceQuery` y en `EmployeeQueries::page()`— hace que una llamada nueva
 * no pueda escribirse sin decidir de quien es. Se aplica **en la consulta**, no
 * filtrando la pagina ya traida: si se hiciera despues, el total describiria a
 * personas que quien pregunta no puede ver y la paginacion daria paginas vacias
 * intercaladas.
 *
 * **`status` tiene valor por omision y los otros tres no.** `open` es la pregunta
 * de quien abre la bandeja —que tengo pendiente—, y una bandeja que mezclara lo
 * pendiente con lo ya trabajado obligaria a distinguirlo fila a fila justo en la
 * pantalla que existe para no tener que hacerlo.
 */
final readonly class IncidentBoardQuery
{
    public function __construct(
        public AccessScope $scope,
        public IncidentStatus $status = IncidentStatus::Open,
        public ?IncidentType $type = null,
        public ?IncidentSeverity $severity = null,
        public ?int $departmentId = null,
        /** UUID **publico** del empleado; la traduccion a la clave interna es del adaptador. */
        public ?string $employeeUuid = null,
        public int $page = 1,
        public int $perPage = 25,
    ) {}
}
