<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Application\Port;

use App\Modules\Reporting\Domain\ValueObject\PresenceBoard;
use App\Modules\Reporting\Domain\ValueObject\PresenceEntry;
use App\Modules\Reporting\Domain\ValueObject\PresenceStatus;
use App\Modules\Shared\Domain\ValueObject\AccessScope;
use DateTimeImmutable;

/**
 * Quien esta dentro ahora mismo (**RF-PA-01**, RF-PA-02).
 *
 * ## Por que el alcance es el primer parametro
 *
 * Porque es la acotacion que quien llama **no puede elegir** (RF-ID-03). Entra
 * en la consulta —`WHERE department_id IN (...)`— y no filtra la lista ya
 * traida: si se aplicara despues, los recuentos de `PresenceBoard` describirian
 * a personas que quien pregunta no puede ver, que es una fuga por si misma. Es
 * el mismo criterio con el que `Workforce\Application\Query\EmployeeQueries`
 * pagina la plantilla.
 *
 * ## El instante y la zona entran, no se resuelven aqui
 *
 * `generatedAt` sale del puerto `Clock` (regla dura 2) y `timeZone` del centro
 * de la instalacion (ADR-040): los dos los resuelve el caso de uso y los entrega
 * ya hechos. Un adaptador que llamara a `now()` haria imposible probar esta
 * vista con un reloj fijo, y uno que dedujera la zona de las filas devolveria
 * una respuesta distinta cuando no hubiera ninguna.
 *
 * ## `stateOf` existe para la difusion, no para el panel
 *
 * El evento de dominio de un fichaje **no lleva nombre ni departamento** (regla
 * dura 21): lleva `employeeUuid`. Quien difunde al panel tiene que resolver la
 * fila entera por ese identificador, y tiene que resolverla con la **misma**
 * consulta que produce el listado, o el mensaje del WebSocket y el del sondeo
 * empezarian a discrepar en cuanto uno de los dos cambiara.
 *
 * ## `openShiftsByDepartment` es una metrica, no una vista
 *
 * Alimenta `open_shifts_current{site,department}` (doc 02 §8.2, doc 01 §9.2).
 * No lleva alcance y no debe llevarlo: la produce una tarea programada del
 * servidor, no una cuenta, y un recuento acotado por departamento no seria la
 * metrica de la instalacion. **Se recalcula entera cada vez** (regla dura 7):
 * un gauge que se incrementara con cada fichaje se desviaria en el primer
 * mensaje perdido y nadie lo notaria.
 */
interface LivePresenceReader
{
    public function board(
        AccessScope $scope,
        ?int $departmentId,
        ?string $search,
        PresenceStatus $status,
        DateTimeImmutable $generatedAt,
        string $timeZone,
    ): PresenceBoard;

    /**
     * La fila de una sola persona, con el mismo contenido que tendria en el
     * listado. `null` si ese empleado no existe o esta de baja: quien no esta en
     * plantilla no aparece en el panel de presencia ni entra en la difusion.
     */
    public function stateOf(string $employeeUuid): ?PresenceEntry;

    /**
     * Turnos abiertos ahora mismo, agrupados por nombre de departamento.
     *
     * La cadena vacia agrupa a quien no tiene departamento: es una situacion
     * real de una ficha recien creada y omitirla haria que la suma del gauge no
     * cuadrara con la gente que hay dentro.
     *
     * @return array<string, int>
     */
    public function openShiftsByDepartment(): array;
}
