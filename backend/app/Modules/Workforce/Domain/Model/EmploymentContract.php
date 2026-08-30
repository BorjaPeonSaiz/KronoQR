<?php

declare(strict_types=1);

namespace App\Modules\Workforce\Domain\Model;

use App\Modules\Workforce\Domain\Exception\InvalidEmploymentContract;
use App\Modules\Workforce\Domain\ValueObject\ScheduleType;
use DateInterval;
use DateTimeImmutable;
use InvalidArgumentException;

/**
 * El contrato de una persona durante un tramo de calendario (**RF-GP-02**, doc
 * 01 §5.5).
 *
 * ## Es historico, no un campo de la ficha
 *
 * Las horas contratadas cambian —de 20 h a 40 h en temporada alta, de 40 h a 30
 * h por una reduccion de jornada— y el informe de trabajadas frente a
 * contratadas (RF-IN-03) tiene que comparar cada dia contra **lo que estaba
 * pactado ese dia**. Guardarlas en `employees` daria un informe que cambia
 * retroactivamente cada vez que alguien firma un anexo: marzo pasaria a
 * compararse contra el contrato de julio. Por eso es una tabla con vigencia y
 * por eso nada se sobrescribe (regla dura 5).
 *
 * ## `validTo` nulo significa vigente, no «sin fecha»
 *
 * Un contrato abierto es el caso normal. Registrar uno nuevo **cierra** el
 * anterior el dia anterior al inicio del nuevo ({@see self::closedBefore()}), y
 * eso es lo que mantiene la serie sin huecos ni solapes. La ausencia de solape es
 * una invariante de verdad y no una preferencia: con dos contratos vigentes el
 * mismo dia, «¿cuantas horas tenia contratadas el 14 de marzo?» tiene dos
 * respuestas.
 *
 * ## La formula del prorrateo vive aqui
 *
 * Lo contratado de un periodo es **Σ dias naturales de vigencia dentro del
 * periodo × `weeklyHours` / 7**, en minutos y redondeado al final
 * ({@see self::contractedMinutesForDays()}).
 *
 * Se prorratea por **dia natural** y no por dia laborable porque este producto
 * no modela el cuadrante teorico de nadie: no sabe que dias libra una persona
 * —solo cuando ficho—, y repartir entre cinco dias inventaria un descanso en
 * sabado y domingo que en un hotel no existe. Con dias naturales, un periodo de
 * semanas completas da exactamente `semanas × weeklyHours`, que es la cifra que
 * RRHH espera ver, y un periodo partido da la fraccion proporcional en lugar de
 * un salto. El criterio sale escrito en `meta.criteria` de cada informe, que es
 * donde quien lo lee puede discutirlo.
 *
 * **El redondeo va al final y una sola vez.** `weeklyHours / 7` casi nunca es un
 * numero exacto de minutos: redondear por dia y sumar despues acumularia hasta
 * media hora de error en un mes.
 *
 * ## Sin reloj y sin persistencia
 *
 * Ninguna fecha se calcula aqui (regla dura 2): la vigencia la decide quien
 * registra el contrato y entra ya resuelta. Y como el resto de `Domain/`, esta
 * clase no sabe que existe una tabla: el repositorio traduce.
 */
final readonly class EmploymentContract
{
    /** Nadie contrata mas horas de las que tiene una semana. Es un absurdo, no un umbral legal. */
    private const float HOURS_IN_A_WEEK = 168.0;

    private const int DAYS_IN_A_WEEK = 7;

    private const int MINUTES_IN_AN_HOUR = 60;

    public function __construct(
        /** Identificador **publico** de la persona. La clave interna no cruza al dominio. */
        public string $employeeUuid,
        /** Horas pactadas por semana. Es la unica base del prorrateo (RF-IN-03). */
        public float $weeklyHours,
        /**
         * Horas pactadas al año, cuando el convenio las fija. **No entra en
         * ningun calculo de esta fase**: se guarda porque el doc 01 §5.5 la
         * declara y porque el computo anual del convenio de hosteleria es la
         * cifra que RRHH contrasta a mano. Si algun dia el informe la usa, sera
         * con su propio requisito y su propia formula, no reinterpretando esta.
         */
        public ?float $annualHours,
        public ScheduleType $scheduleType,
        /** Primer dia de vigencia, inclusive. */
        public DateTimeImmutable $validFrom,
        /** Ultimo dia de vigencia, inclusive; `null` si sigue vigente. */
        public ?DateTimeImmutable $validTo,
        /** Clave interna, o `null` si todavia no se ha persistido. */
        public ?int $id = null,
    ) {
        $this->assertIdentity();
        $this->assertHours();
        $this->assertPeriod();
    }

    public function isOpenEnded(): bool
    {
        return $this->validTo === null;
    }

    /**
     * Si el contrato estaba vigente ese dia natural.
     *
     * Los dos extremos entran: un contrato que va del 1 al 31 cubre el 1 y el 31.
     */
    public function covers(DateTimeImmutable $day): bool
    {
        if ($this->asDate($day) < $this->asDate($this->validFrom)) {
            return false;
        }

        return $this->validTo === null || $this->asDate($day) <= $this->asDate($this->validTo);
    }

    /**
     * Cuantos dias naturales de este contrato caen dentro del periodo, ambos
     * extremos incluidos.
     *
     * Cero cuando no se tocan. Es la primera mitad del prorrateo; la segunda es
     * {@see self::contractedMinutesForDays()}.
     */
    public function coveredDaysWithin(DateTimeImmutable $from, DateTimeImmutable $to): int
    {
        $start = max($this->asDate($this->validFrom), $this->asDate($from));
        $end = $this->validTo === null
            ? $this->asDate($to)
            : min($this->asDate($this->validTo), $this->asDate($to));

        if ($start > $end) {
            return 0;
        }

        return (int) $start->diff($end)->format('%a') + 1;
    }

    /**
     * Minutos contratados que corresponden a `$days` dias naturales de vigencia.
     *
     * **Es la definicion canonica de «horas contratadas del periodo»** y la
     * consulta del informe la reproduce en SQL para poder agregar por
     * departamento sin traerse fila a fila. Que haya dos expresiones de la misma
     * formula esta asumido y atado por una prueba de integracion que compara las
     * dos sobre el mismo caso: sin ella, la duplicacion seria una bomba de
     * relojeria.
     */
    public function contractedMinutesForDays(int $days): int
    {
        if ($days < 0) {
            throw new InvalidArgumentException('Un periodo de vigencia no tiene un numero negativo de dias.');
        }

        return (int) round($days * $this->weeklyHours * self::MINUTES_IN_AN_HOUR / self::DAYS_IN_A_WEEK);
    }

    /**
     * El mismo contrato cerrado **el dia anterior** a `$newStart`.
     *
     * Es lo que hace el registro de un contrato nuevo con el que ya habia: la
     * serie queda sin hueco —el nuevo empieza justo al dia siguiente— y sin
     * solape, que es lo que la restriccion de exclusion no admitiria.
     */
    public function closedBefore(DateTimeImmutable $newStart): self
    {
        $lastDay = $this->asDate($newStart)->sub(new DateInterval('P1D'));

        return new self(
            employeeUuid: $this->employeeUuid,
            weeklyHours: $this->weeklyHours,
            annualHours: $this->annualHours,
            scheduleType: $this->scheduleType,
            validFrom: $this->validFrom,
            validTo: $lastDay,
            id: $this->id,
        );
    }

    /**
     * Si las dos vigencias comparten algun dia natural.
     *
     * La comprobacion de verdad la hace `employment_contracts_no_overlap` en
     * PostgreSQL, porque una consulta previa desde PHP seria una carrera. Esto
     * existe para que el dominio sea probable sin base de datos y para que el
     * caso de uso pueda explicar el choque antes de intentarlo.
     */
    public function overlaps(self $other): bool
    {
        $thisEnd = $this->validTo === null ? null : $this->asDate($this->validTo);
        $otherEnd = $other->validTo === null ? null : $this->asDate($other->validTo);

        $startsAfterTheOtherEnds = $otherEnd !== null && $this->asDate($this->validFrom) > $otherEnd;
        $endsBeforeTheOtherStarts = $thisEnd !== null && $thisEnd < $this->asDate($other->validFrom);

        return ! $startsAfterTheOtherEnds && ! $endsBeforeTheOtherStarts;
    }

    public function isoValidFrom(): string
    {
        return $this->validFrom->format('Y-m-d');
    }

    public function isoValidTo(): ?string
    {
        return $this->validTo?->format('Y-m-d');
    }

    /**
     * La hora del dia no significa nada en una vigencia: son etiquetas de
     * calendario. Se normalizan a medianoche para que la comparacion sea entre
     * fechas y no una pregunta sobre husos horarios, igual que en `DateRange`.
     */
    private function asDate(DateTimeImmutable $moment): DateTimeImmutable
    {
        return $moment->setTime(0, 0);
    }

    private function assertIdentity(): void
    {
        if ($this->employeeUuid === '') {
            throw new InvalidArgumentException('Un contrato pertenece a una persona identificada por su UUID publico.');
        }
    }

    private function assertHours(): void
    {
        if ($this->weeklyHours <= 0.0) {
            throw InvalidEmploymentContract::hoursMustBePositive('horas semanales', $this->weeklyHours);
        }

        if ($this->weeklyHours > self::HOURS_IN_A_WEEK) {
            throw InvalidEmploymentContract::weeklyHoursExceedTheWeek($this->weeklyHours);
        }

        if ($this->annualHours !== null && $this->annualHours <= 0.0) {
            throw InvalidEmploymentContract::hoursMustBePositive('horas anuales', $this->annualHours);
        }
    }

    private function assertPeriod(): void
    {
        if ($this->validTo !== null && $this->asDate($this->validFrom) > $this->asDate($this->validTo)) {
            throw InvalidEmploymentContract::periodIsInverted($this->isoValidFrom(), (string) $this->isoValidTo());
        }
    }
}
