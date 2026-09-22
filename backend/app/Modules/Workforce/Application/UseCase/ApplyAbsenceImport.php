<?php

declare(strict_types=1);

namespace App\Modules\Workforce\Application\UseCase;

use App\Modules\Shared\Application\Port\Clock;
use App\Modules\Workforce\Application\Port\AbsenceRepository;
use App\Modules\Workforce\Application\Port\EmployeeImportDirectory;
use App\Modules\Workforce\Application\Port\EmployeeRepository;
use App\Modules\Workforce\Application\Port\WorkforceEventPublisher;
use App\Modules\Workforce\Domain\Event\AbsenceRegistered;
use App\Modules\Workforce\Domain\Model\Absence;
use App\Modules\Workforce\Domain\ValueObject\AbsenceImportOutcome;
use App\Modules\Workforce\Domain\ValueObject\AbsenceImportReport;
use App\Modules\Workforce\Domain\ValueObject\AbsenceImportRow;
use App\Modules\Workforce\Domain\ValueObject\AbsenceType;
use App\Modules\Workforce\Domain\ValueObject\ImportedAbsence;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Str;

/**
 * Escribe lo que {@see PlanAbsenceImport} decidio (**RF-GP-04**, fase de
 * aplicacion).
 *
 * ## Consume el informe, no vuelve a decidir
 *
 * Recibe el informe ya calculado y se limita a ejecutarlo. Es lo que garantiza
 * que **lo que se escribe es exactamente lo que se simulo**: con la decision
 * repetida aqui, los dos caminos podrian divergir y nadie lo notaria hasta que
 * el informe mintiera.
 *
 * ## Todo o nada, en una sola transaccion
 *
 * Un cuadrante de vacaciones a medias es peor que uno que no entra: deja al
 * cliente sin saber que ausencias estan registradas y cuales no, y la segunda
 * pasada chocaria con las que si entraron.
 *
 * **Las lineas rechazadas no revierten nada**: se saltan. Tumbar el lote por una
 * celda con una fecha mal escrita obligaria a repetir la revision de las otras
 * treinta y nueve.
 *
 * ## Un asiento por ausencia, no un resumen del lote
 *
 * Y aqui se separa de {@see ApplyEmployeeImport}, que publica un unico
 * `employee.imported` con las cifras. La razon es que alli **cada alta deja
 * ademas su propio rastro** por el camino de siempre, y aqui no hay ningun otro:
 * sin un `absence.registered` por linea, una carga de cuarenta bajas seria una
 * sola fila del trail y no habria forma de responder «¿quien registro esta?»
 * (decision 6 de la ficha 3.10). Cada evento lleva `source: import` y la huella
 * del fichero, que es lo que ata las cuarenta entre si.
 *
 * ## Por que no reutiliza `RegisterAbsenceHandler`
 *
 * Aquel abre **su propia transaccion por ausencia** y vuelve a resolver la
 * persona con una consulta. Con cuarenta lineas serian cuarenta transacciones
 * anidadas y cuarenta consultas de ficha, y cada una tomaria el
 * `pg_advisory_xact_lock` global de `audit_log` (ADR-010) —el mismo por el que
 * pasa cada fichaje del hotel— por separado. Aqui la transaccion es **una** y
 * las fichas se resuelven una vez por persona.
 */
final readonly class ApplyAbsenceImport
{
    public function __construct(
        private AbsenceRepository $absences,
        private EmployeeImportDirectory $directory,
        private EmployeeRepository $employees,
        private WorkforceEventPublisher $events,
        private Clock $clock,
        private ConnectionInterface $connection,
    ) {}

    public function handle(AbsenceImportReport $report, ?int $importedByUserId): AbsenceImportReport
    {
        $applied = $this->connection->transaction(
            fn (): array => $this->applyRows($report, $importedByUserId),
        );

        return $report->withRows($applied);
    }

    /**
     * @return list<AbsenceImportRow>
     */
    private function applyRows(AbsenceImportReport $report, ?int $importedByUserId): array
    {
        $rows = [];
        // Los UUID de las personas ya resueltas, para no repetir la consulta por
        // cada linea de la misma persona: un cuadrante de vacaciones tiene varias
        // filas por cabeza.
        $uuidByCode = [];

        foreach ($report->rows as $row) {
            $rows[] = $row->outcome === AbsenceImportOutcome::CREATE
                ? $this->create($row, $report->sha256, $importedByUserId, $uuidByCode)
                // `unchanged` no escribe porque no hay nada que cambiar, y
                // `reject` porque no se pudo interpretar.
                : $row;
        }

        return $rows;
    }

    /**
     * @param  array<string, string|null>  $uuidByCode
     */
    private function create(
        AbsenceImportRow $row,
        string $fileSha256,
        ?int $importedByUserId,
        array &$uuidByCode,
    ): AbsenceImportRow {
        $imported = $row->absence;

        if (! $imported instanceof ImportedAbsence) {
            return $row;
        }

        $employeeUuid = $uuidByCode[$imported->employeeCode]
            ??= $this->directory->uuidByEmployeeCode($imported->employeeCode);

        if ($employeeUuid === null) {
            // La fase de comprobacion ya rechazo las lineas sin persona, asi que
            // esto no es alcanzable: el respaldo existe porque el tipo lo
            // permite. Se salta en silencio en lugar de reventar el lote entero
            // por una linea que el informe dijo que estaba bien.
            return $row;
        }

        $absence = $this->absenceFrom($imported, $employeeUuid);

        if ($absence === null) {
            // El tipo de la celda no se reconoce. La fase de comprobacion ya
            // rechazo esas lineas, asi que no es alcanzable: el respaldo existe
            // porque el tipo lo permite. **Se salta la fila en lugar de
            // escribirla con un tipo inventado**, que es lo que hacia antes de la
            // revision con un `?? AbsenceType::Leave`: registrar un permiso donde
            // el fichero decia otra cosa es peor que no registrar nada, porque
            // nadie lo notaria.
            return $row;
        }

        if (! $this->fallsWithinEmployment($absence)) {
            // Igual que arriba: no es alcanzable desde el informe, pero si lo
            // fuera, saltarsela es preferible a escribir una ausencia de dias en
            // los que esa persona no estaba de alta.
            return $row;
        }

        $stored = $this->absences->add($absence, $importedByUserId);

        $this->events->publish(new AbsenceRegistered(
            absenceUuid: $stored->uuid,
            employeeUuid: $stored->employeeUuid,
            type: $stored->type->value,
            startsOn: $stored->isoStartsOn(),
            endsOn: $stored->isoEndsOn(),
            version: $stored->version,
            hasNote: $stored->hasNote(),
            occurredAt: $this->clock->now(),
            source: AbsenceRegistered::SOURCE_IMPORT,
            // La huella ata las cuarenta lineas de esta carga entre si. **El
            // nombre del fichero no viaja**: lo pone quien sube y puede llevar
            // dentro el nombre de una persona (regla dura 21).
            fileSha256: $fileSha256,
        ));

        return $row->appliedAs($stored->uuid);
    }

    /**
     * La ausencia que escribe esa linea, o `null` si su tipo no se reconoce.
     *
     * **`null` y no un tipo por omision**: escribir un permiso donde el fichero
     * decia otra cosa seria un dato inventado que nadie notaria, y la fila ya
     * habria pasado por un informe que decia `create`. La comprobacion previa
     * rechaza esas lineas, asi que esto no es alcanzable; existe porque el tipo
     * lo permite y porque un respaldo que escribe es peor que uno que se calla.
     */
    private function absenceFrom(ImportedAbsence $imported, string $employeeUuid): ?Absence
    {
        $type = AbsenceType::fromImportLabel($imported->type);

        if ($type === null) {
            return null;
        }

        return new Absence(
            uuid: Str::uuid7()->toString(),
            employeeUuid: $employeeUuid,
            type: $type,
            startsOn: RegisterAbsenceHandler::asDate($this->isoDateOf($imported->startsOn)),
            endsOn: RegisterAbsenceHandler::asDate($this->isoDateOf($imported->endsOn)),
            note: $imported->note,
        );
    }

    /**
     * La ausencia tiene que tocar la relacion laboral, igual que en el alta
     * individual (decision 2 de la ficha 3.10).
     */
    private function fallsWithinEmployment(Absence $absence): bool
    {
        $employee = $this->employees->findByUuid($absence->employeeUuid);

        return $employee === null
            || $absence->fallsWithinEmployment($employee->hiredAt, $employee->terminatedAt);
    }

    /**
     * La fecha ya validada, en el formato que espera el alta.
     *
     * La validacion ocurrio en {@see PlanAbsenceImport}: una linea con fecha
     * ilegible es `reject` y no llega hasta aqui.
     */
    private function isoDateOf(string $value): string
    {
        return PlanAbsenceImport::parseDate($value)?->format('Y-m-d') ?? '1970-01-01';
    }
}
