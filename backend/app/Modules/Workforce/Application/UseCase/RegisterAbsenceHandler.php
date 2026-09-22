<?php

declare(strict_types=1);

namespace App\Modules\Workforce\Application\UseCase;

use App\Modules\Shared\Application\Port\Clock;
use App\Modules\Workforce\Application\Command\RegisterAbsenceCommand;
use App\Modules\Workforce\Application\Port\AbsenceRepository;
use App\Modules\Workforce\Application\Port\EmployeeRepository;
use App\Modules\Workforce\Application\Port\WorkforceEventPublisher;
use App\Modules\Workforce\Domain\Event\AbsenceRegistered;
use App\Modules\Workforce\Domain\Exception\InvalidAbsencePeriod;
use App\Modules\Workforce\Domain\Exception\OverlappingAbsence;
use App\Modules\Workforce\Domain\Model\Absence;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Str;

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
 * Que la ausencia **toque al menos un dia de la relacion laboral** (decision 2
 * de la ficha 3.10). Eso no lo puede declarar el esquema —depende de dos
 * columnas de otra tabla— y sin ello se podrian registrar vacaciones de alguien
 * para el año anterior a su alta, que no justifican nada y ensucian el informe.
 * El solape **parcial** si se admite: el informe cuenta solo los dias de alta.
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
    ) {}

    /**
     * `null` si la persona no existe: es un `422` sobre `employee_uuid` y lo
     * traduce el controlador. **No `404`**, porque el identificador va en el
     * cuerpo y hay un campo que corregir en el formulario.
     *
     * @throws InvalidAbsencePeriod si el periodo no toca la relacion laboral
     * @throws OverlappingAbsence si pisa otra ausencia activa de esa persona
     */
    public function handle(RegisterAbsenceCommand $command): ?Absence
    {
        $employee = $this->employees->findByUuid($command->employeeUuid);

        if ($employee === null) {
            return null;
        }

        $absence = new Absence(
            uuid: Str::uuid7()->toString(),
            employeeUuid: $command->employeeUuid,
            type: $command->type,
            startsOn: self::asDate($command->startsOn),
            endsOn: self::asDate($command->endsOn),
            note: $command->note,
        );

        if (! $absence->fallsWithinEmployment($employee->hiredAt, $employee->terminatedAt)) {
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
        return new DateTimeImmutable($isoDate.' 00:00:00', new DateTimeZone('UTC'));
    }
}
