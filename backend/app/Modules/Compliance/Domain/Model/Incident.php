<?php

declare(strict_types=1);

namespace App\Modules\Compliance\Domain\Model;

use App\Modules\Compliance\Domain\Exception\InvalidIncident;
use App\Modules\Compliance\Domain\ValueObject\IncidentSeverity;
use App\Modules\Compliance\Domain\ValueObject\IncidentStatus;
use App\Modules\Compliance\Domain\ValueObject\IncidentType;
use DateTimeImmutable;
use DateTimeZone;

/**
 * Una **incidencia**: algo del registro horario que tiene que mirar una persona
 * (doc 01 §5.1, RF-PR-01).
 *
 * Vive en `Compliance` y no en `Attendance` —lo situa ahi el §5.1— y la
 * distincion importa: `Attendance` sabe **que ha pasado** con las horas de
 * alguien; `Compliance` sabe **quien responde de ello, con que urgencia y hasta
 * cuando queda abierto**. Por eso la severidad, el estado y el responsable son
 * de esta clase y no viajan en el hallazgo.
 *
 * **Se identifica por identificadores publicos, nunca por claves internas ni por
 * nombres** (regla dura 21): `employeeUuid` y `shiftEntryUuid`. La traduccion a
 * `employees.id` y `shift_entries.id` es trabajo del repositorio, igual que en
 * el resto del modulo.
 *
 * **Nace abierta y no se cierra sola.** La resolucion es un acto de una persona
 * con su motivo y su traza (tarea 2.5): aqui no hay ningun camino que lleve a
 * `resolved` sin que alguien lo pida.
 *
 * **`workDate` es una cadena ISO y no un objeto de fecha**, porque el objeto que
 * la representa —`WorkDate`, con la zona del centro que RN-05 exige— pertenece al
 * dominio de `Attendance` y este modulo no puede importarlo (doc 02 §1.6). Lo
 * que llega es la fecha ya decidida por quien si podia decidirla.
 */
final readonly class Incident
{
    /**
     * @param  array<string, int>  $context  Minutos medidos y umbral aplicado. Nunca datos personales.
     */
    private function __construct(
        public IncidentType $type,
        public IncidentSeverity $severity,
        public IncidentStatus $status,
        public string $employeeUuid,
        public int $siteId,
        public string $workDate,
        public ?string $shiftEntryUuid,
        public DateTimeImmutable $detectedAt,
        public ?int $assignedToUserId,
        public array $context,
    ) {}

    /**
     * Abre una incidencia a partir de un hallazgo de la deteccion.
     *
     * `$assignedToUserId` es el responsable del departamento del empleado
     * (`departments.manager_user_id`) y puede ser `null`: un departamento sin
     * responsable asignado **no hace que la incidencia se descarte**, la deja sin
     * asignar hasta que alguien lo nombre. Perder el hallazgo por un hueco de
     * configuracion seria exactamente lo contrario de para lo que existe.
     *
     * @param  array<string, int>  $context
     */
    public static function open(
        IncidentType $type,
        string $employeeUuid,
        int $siteId,
        string $workDate,
        ?string $shiftEntryUuid,
        DateTimeImmutable $detectedAt,
        ?int $assignedToUserId = null,
        array $context = [],
    ): self {
        if (trim($employeeUuid) === '') {
            throw InvalidIncident::withoutEmployee();
        }

        if ($siteId < 1) {
            throw InvalidIncident::withoutSite($siteId);
        }

        self::guardIsoDate($workDate);
        self::guardUtc($detectedAt);

        if ($assignedToUserId !== null && $assignedToUserId < 1) {
            throw InvalidIncident::withInvalidAssignee($assignedToUserId);
        }

        return new self(
            type: $type,
            // La severidad la decide el tipo y no quien abre la incidencia: si
            // cada detector pudiera elegirla, el mismo hecho entraria en la
            // bandeja con dos prioridades distintas segun quien lo viera.
            severity: $type->defaultSeverity(),
            status: IncidentStatus::Open,
            employeeUuid: $employeeUuid,
            siteId: $siteId,
            workDate: $workDate,
            shiftEntryUuid: $shiftEntryUuid,
            detectedAt: $detectedAt,
            assignedToUserId: $assignedToUserId,
            context: $context,
        );
    }

    /**
     * La misma incidencia con otro responsable.
     *
     * Existe porque el responsable de un departamento cambia y porque la
     * asignacion se resuelve fuera del dominio; devuelve una instancia nueva
     * —la clase es `readonly`— en vez de mutar.
     */
    public function assignedTo(?int $userId): self
    {
        if ($userId !== null && $userId < 1) {
            throw InvalidIncident::withInvalidAssignee($userId);
        }

        return new self(
            $this->type,
            $this->severity,
            $this->status,
            $this->employeeUuid,
            $this->siteId,
            $this->workDate,
            $this->shiftEntryUuid,
            $this->detectedAt,
            $userId,
            $this->context,
        );
    }

    /**
     * Si la incidencia describe un tramo concreto o la jornada entera.
     *
     * Es la diferencia entre «este turno de trece horas» y «el dia suma mas de
     * nueve», y lo que hace idempotente la deteccion: el indice unico parcial de
     * `incidents` incluye `shift_entry_id`.
     */
    public function isAboutASingleShiftEntry(): bool
    {
        return $this->shiftEntryUuid !== null;
    }

    /**
     * `Y-m-d` estricto y una fecha que existe: `2026-02-30` encaja con el
     * patron y no es un dia.
     */
    private static function guardIsoDate(string $workDate): void
    {
        $parsed = DateTimeImmutable::createFromFormat('!Y-m-d', $workDate, new DateTimeZone('UTC'));

        if ($parsed === false || $parsed->format('Y-m-d') !== $workDate) {
            throw InvalidIncident::withInvalidWorkDate($workDate);
        }
    }

    /** Regla dura 3: todo instante que se persiste es UTC. */
    private static function guardUtc(DateTimeImmutable $detectedAt): void
    {
        if ($detectedAt->getOffset() !== 0) {
            throw InvalidIncident::withNonUtcDetectionInstant($detectedAt->format(DATE_ATOM));
        }
    }
}
