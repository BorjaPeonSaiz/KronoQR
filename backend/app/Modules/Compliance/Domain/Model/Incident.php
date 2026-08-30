<?php

declare(strict_types=1);

namespace App\Modules\Compliance\Domain\Model;

use App\Modules\Compliance\Domain\Exception\IncidentAlreadyClosed;
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
 * con su motivo y su traza (tarea 2.5): {@see self::resolvedBy()} exige quien,
 * cuando y por que, y no hay ningun otro camino que lleve a `resolved`. El
 * instante llega **de fuera** —del puerto `Clock` que inyecta el caso de uso—
 * porque el dominio no lee el reloj (regla dura 2).
 *
 * **Y no se cierra dos veces.** Resolver una incidencia ya cerrada lanza
 * {@see IncidentAlreadyClosed}, que la capa HTTP traduce a `409`. Es la
 * invariante que impide que dos pestañas abiertas sobre la misma bandeja dejen
 * dos notas encadenadas sobre el mismo hecho, cada una tapando a la anterior.
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
        /**
         * Los tres campos del cierre viajan juntos o no viajan (tarea 2.5).
         *
         * No son opcionales por comodidad: el `CHECK`
         * `incidents_chk_resolution_is_complete` del esquema afirma exactamente
         * lo mismo —abierta sin resolutor, cerrada con instante—, y una fila que
         * dijera «resuelta» sin decir quien ni cuando no se puede defender ante
         * una inspeccion.
         */
        public ?DateTimeImmutable $resolvedAt = null,
        public ?int $resolvedByUserId = null,
        public ?string $resolutionNote = null,
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
            type: $this->type,
            severity: $this->severity,
            status: $this->status,
            employeeUuid: $this->employeeUuid,
            siteId: $this->siteId,
            workDate: $this->workDate,
            shiftEntryUuid: $this->shiftEntryUuid,
            detectedAt: $this->detectedAt,
            assignedToUserId: $userId,
            context: $this->context,
            resolvedAt: $this->resolvedAt,
            resolvedByUserId: $this->resolvedByUserId,
            resolutionNote: $this->resolutionNote,
        );
    }

    /**
     * Reconstruye una incidencia **tal y como esta escrita** (tarea 2.5).
     *
     * Es la entrada del repositorio, no de la deteccion: `open()` decide cosas
     * —la severidad la pone el tipo, el estado nace `open`— y aqui no se decide
     * nada, se reproduce. Si esta entrada pasara por `open()`, una fila cerrada
     * volveria a la vida como abierta y una severidad que el catalogo cambiara
     * mañana reescribiria en silencio la que se aplico al detectarla.
     *
     * Las guardas de forma **si** se aplican: una fila con una fecha imposible o
     * con un instante que no es UTC no es una incidencia que alguien pueda
     * trabajar, venga de donde venga.
     *
     * @param  array<string, int>  $context
     */
    public static function restore(
        IncidentType $type,
        IncidentSeverity $severity,
        IncidentStatus $status,
        string $employeeUuid,
        int $siteId,
        string $workDate,
        ?string $shiftEntryUuid,
        DateTimeImmutable $detectedAt,
        ?int $assignedToUserId,
        array $context,
        ?DateTimeImmutable $resolvedAt,
        ?int $resolvedByUserId,
        ?string $resolutionNote,
    ): self {
        if (trim($employeeUuid) === '') {
            throw InvalidIncident::withoutEmployee();
        }

        if ($siteId < 1) {
            throw InvalidIncident::withoutSite($siteId);
        }

        self::guardIsoDate($workDate);
        self::guardUtc($detectedAt);

        if ($resolvedAt instanceof DateTimeImmutable) {
            self::guardUtc($resolvedAt);
        }

        return new self(
            type: $type,
            severity: $severity,
            status: $status,
            employeeUuid: $employeeUuid,
            siteId: $siteId,
            workDate: $workDate,
            shiftEntryUuid: $shiftEntryUuid,
            detectedAt: $detectedAt,
            assignedToUserId: $assignedToUserId,
            context: $context,
            resolvedAt: $resolvedAt,
            resolvedByUserId: $resolvedByUserId,
            resolutionNote: $resolutionNote,
        );
    }

    /**
     * Una persona la da por trabajada (**RF-PA-05**, RN-13).
     *
     * Cuatro invariantes, y ninguna es de formulario:
     *
     * 1. **Solo se cierra lo que esta abierto.** Una segunda resolucion no es
     *    una correccion de la primera: es otra afirmacion sobre el mismo hecho,
     *    firmada por otra persona, que taparia a la anterior en una tabla donde
     *    nada se sobrescribe (regla dura 5).
     * 2. **El desenlace es final.** `resolved` y `dismissed` no significan lo
     *    mismo —«habia algo y se arreglo» frente a «se miro y no habia nada»— y
     *    `open` no es un desenlace: reabrir desde aqui dejaria una incidencia
     *    abierta con nota de cierre.
     * 3. **La nota es obligatoria**, tambien al descartar. Es la mitad de la
     *    traza que RN-13 exige de cualquier intervencion humana sobre el
     *    registro; sin ella la bandeja se vacia y seis meses despues nadie puede
     *    explicar que se hizo.
     * 4. **No se resuelve antes de detectarse.** Lo afirma tambien el `CHECK`
     *    `incidents_chk_resolved_after_detected`, y aqui se comprueba para que
     *    el fallo sea del dominio y no una violacion de restriccion a medio
     *    camino de la transaccion.
     *
     * `$at` entra por parametro y no se lee de ningun reloj: el dominio no toca
     * el del sistema (regla dura 2), y sin eso no habria forma de probar de
     * manera determinista lo que mide `incident_resolution_seconds`.
     */
    public function resolvedBy(
        IncidentStatus $outcome,
        int $userId,
        string $note,
        DateTimeImmutable $at,
    ): self {
        if (! $this->status->isOpen()) {
            throw IncidentAlreadyClosed::inStatus($this->status->value);
        }

        if ($outcome->isOpen()) {
            throw InvalidIncident::withNonFinalOutcome($outcome->value);
        }

        if ($userId < 1) {
            throw InvalidIncident::withInvalidResolver($userId);
        }

        $trimmed = trim($note);

        if ($trimmed === '') {
            throw InvalidIncident::withoutResolutionNote();
        }

        self::guardUtc($at);

        if ($at < $this->detectedAt) {
            throw InvalidIncident::withResolutionBeforeDetection(
                $at->format(DATE_ATOM),
                $this->detectedAt->format(DATE_ATOM),
            );
        }

        return new self(
            type: $this->type,
            severity: $this->severity,
            status: $outcome,
            employeeUuid: $this->employeeUuid,
            siteId: $this->siteId,
            workDate: $this->workDate,
            shiftEntryUuid: $this->shiftEntryUuid,
            detectedAt: $this->detectedAt,
            assignedToUserId: $this->assignedToUserId,
            context: $this->context,
            resolvedAt: $at,
            resolvedByUserId: $userId,
            resolutionNote: $trimmed,
        );
    }

    /**
     * Cuanto tardo en trabajarse, en segundos: lo que observa el histograma
     * `incident_resolution_seconds{type}` (doc 02 §8.2) y lo que alimenta el
     * objetivo «< 24 h» del doc 01 §1.3.
     *
     * `null` mientras siga abierta, que es distinto de cero: una incidencia sin
     * resolver no ha tardado nada, es que todavia no ha terminado. Observar un
     * cero en el histograma diria lo contrario.
     */
    public function resolutionSeconds(): ?int
    {
        if (! $this->resolvedAt instanceof DateTimeImmutable) {
            return null;
        }

        return $this->resolvedAt->getTimestamp() - $this->detectedAt->getTimestamp();
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
