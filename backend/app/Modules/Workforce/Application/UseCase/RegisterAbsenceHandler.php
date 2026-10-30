<?php

declare(strict_types=1);

namespace App\Modules\Workforce\Application\UseCase;

use App\Modules\Shared\Application\Port\Clock;
use App\Modules\Shared\Application\Port\UuidGenerator;
use App\Modules\Shared\Application\Support\UseCaseInstrumentation;
use App\Modules\Shared\Domain\Support\CivilDate;
use App\Modules\Workforce\Application\Command\RegisterAbsenceCommand;
use App\Modules\Workforce\Application\Port\AbsenceRepository;
use App\Modules\Workforce\Application\Port\EmployeeRepository;
use App\Modules\Workforce\Application\Port\WorkforceEventPublisher;
use App\Modules\Workforce\Domain\Event\AbsenceRegistered;
use App\Modules\Workforce\Domain\Exception\EmployeeRecordMinimised;
use App\Modules\Workforce\Domain\Exception\InvalidAbsencePeriod;
use App\Modules\Workforce\Domain\Exception\OverlappingAbsence;
use App\Modules\Workforce\Domain\Model\Absence;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Database\ConnectionInterface;

/**
 * Registra una ausencia (**RF-GP-04**).
 *
 * ## Un caso de uso, una transaccion
 *
 * La fila y su asiento de `audit_log` son **un solo hecho**. El asiento es
 * sincrono y va dentro (ADR-027, regla dura 6): si falla, la ausencia no se
 * registra. Una ausencia sin traza cambia el absentismo de una persona sin que
 * nadie pueda explicar quien lo hizo, y eso acaba delante de alguien discutiendo
 * un expediente.
 *
 * ## El solape no se comprueba con un `SELECT` previo
 *
 * Lo decide `absences_no_overlap` en PostgreSQL, y el repositorio traduce su
 * `SQLSTATE 23P01` a {@see OverlappingAbsence}. Una comprobacion completa desde
 * PHP seria una condicion de carrera con aspecto de comprobacion: dos altas
 * simultaneas la pasan las dos, y el resultado es un dia contado dos veces en el
 * informe.
 *
 * ## Lo que si se comprueba antes, y por que
 *
 * Que la ausencia **caiga entera en uno de los periodos de alta** de la persona
 * (RF-GP-04, RN-27). Eso no lo puede declarar el esquema —depende de otra
 * tabla— y sin ello se podrian registrar vacaciones de alguien para el año
 * anterior a su alta, o en el hueco entre una baja y una readmision, que no
 * justifican nada y ensucian el informe.
 *
 * ## `now()` no aparece
 *
 * El instante del evento sale del puerto `Clock` (regla dura 2) y las fechas de
 * la ausencia las decide quien la registra. El `uuid` es v7 —lleva un reloj
 * dentro— y por eso se genera aqui, en la capa de aplicacion, y no en el
 * dominio.
 */
final readonly class RegisterAbsenceHandler
{
    public function __construct(
        private EmployeeRepository $employees,
        private AbsenceRepository $absences,
        private WorkforceEventPublisher $events,
        private Clock $clock,
        private ConnectionInterface $connection,
        private AbsenceEmploymentRecheck $recheck,
        private UuidGenerator $uuids,
    ) {}

    /**
     * `null` si la persona no existe: es un `422` sobre `employee_uuid` y lo
     * traduce el controlador. **No `404`**, porque el identificador va en el
     * cuerpo y hay un campo que corregir en el formulario.
     *
     * @throws InvalidAbsencePeriod si no cae entera en un periodo de alta
     * @throws OverlappingAbsence si pisa otra ausencia activa de esa persona
     * @throws EmployeeRecordMinimised si la ficha se minimizo por retencion (ADR-060)
     */
    public function handle(RegisterAbsenceCommand $command): ?Absence
    {
        return UseCaseInstrumentation::run('workforce.register_absence', fn (): ?Absence => $this->doHandle($command));
    }

    private function doHandle(RegisterAbsenceCommand $command): ?Absence
    {
        $employee = $this->employees->findByUuid($command->employeeUuid);

        if ($employee === null) {
            return null;
        }

        // ADR-060: una ficha minimizada no recibe filas nuevas (409).
        $employee->assertAcceptsPersonnelRecords();

        $absence = new Absence(
            uuid: $this->uuids->next(),
            employeeUuid: $command->employeeUuid,
            type: $command->type,
            startsOn: self::asDate($command->startsOn),
            endsOn: self::asDate($command->endsOn),
            note: $command->note,
        );

        if (! $absence->fallsWithin($this->employees->employmentHistory($absence->employeeUuid))) {
            throw InvalidAbsencePeriod::isOutsideEmployment($absence->isoStartsOn(), $absence->isoEndsOn());
        }

        return $this->connection->transaction(function () use ($absence, $command): Absence {
            $stored = $this->absences->add($absence, $command->registeredByUserId);

            // Dentro de la transaccion: el asiento es sincrono y si falla, la
            // ausencia no se registra (regla dura 6, ADR-027).
            $this->events->publish(new AbsenceRegistered(
                absenceUuid: $stored->uuid,
                employeeUuid: $stored->employeeUuid,
                type: $stored->type->value,
                startsOn: $stored->isoStartsOn(),
                endsOn: $stored->isoEndsOn(),
                version: $stored->version,
                // Si la habia, nunca su contenido: puede ser un diagnostico
                // (regla dura 21).
                hasNote: $stored->hasNote(),
                occurredAt: $this->clock->now(),
            ));

            // RN-14, ADR-046: la ficha de arriba se leyo sin candado; con la
            // cadena ya tomada por el asiento, una baja que confirmara entre
            // medias se ve ahora y deshace el registro entero.
            $this->recheck->assertStillWithinEmployment($absence);

            return $stored;
        });
    }

    /**
     * La fecha ya validada por el `FormRequest`, fijada a medianoche UTC para
     * que la comparacion sea entre fechas y no una pregunta sobre husos
     * horarios. Mismo criterio que `DateRange` y que las vigencias de contrato.
     */
    public static function asDate(string $isoDate): DateTimeImmutable
    {
        return CivilDate::midnight($isoDate, new DateTimeZone('UTC'));
    }
}
