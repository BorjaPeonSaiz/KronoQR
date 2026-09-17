<?php

declare(strict_types=1);

namespace Tests\Support\Attendance;

use App\Modules\Attendance\Application\Port\WorkDayLedger;
use App\Modules\Attendance\Domain\Model\WorkDay;
use App\Modules\Attendance\Domain\ValueObject\WorkDate;
use DateTimeImmutable;

/**
 * El ledger de verdad con **un fichaje confirmando en mitad de la lectura**.
 *
 * ## Que reproduce
 *
 * La reconciliacion de `daily_totals` (RF-PR-02) inspecciona un dia con dos
 * consultas seguidas y sin instantanea comun: primero los tramos vigentes
 * (`workDaysBetween`) y despues las filas de la proyeccion. Un fichaje que
 * confirme **entre las dos** —y a las 03:50 UTC, que es cuando corre la pasada,
 * el turno de noche de un hotel ficha— le deja una mitad nueva y otra vieja, y
 * eso parece una divergencia sin serlo.
 *
 * Este doble cierra esa ventana a mano: en cuanto la lectura en bloque devuelve,
 * ejecuta la clausura que se le dio —un escaneo real, en otro proceso, que
 * confirma su transaccion— y solo entonces deja seguir a la pasada. Sin el, la
 * carrera depende de que el planificador del sistema operativo intercale los dos
 * procesos en el milisegundo exacto, y una prueba que falla una vez de cada
 * treinta no es una prueba.
 *
 * ## Por que decora y no falsifica
 *
 * Todo lo demas —agrupar en agregados, aplicar ADR-026, resolver la zona del
 * centro— lo sigue haciendo la implementacion real contra PostgreSQL. Lo unico
 * que este doble aporta es **cuando** ocurre el fichaje. Un ledger falso en
 * memoria probaria el arnes.
 *
 * **El disparo es una sola vez y `workDayOf()` nunca lo dispara**: la relectura
 * que hace la correccion tiene que ver lo que de verdad hay en la base, no otro
 * fichaje mas.
 */
final class RacingWorkDayLedger implements WorkDayLedger
{
    private bool $fired = false;

    private bool $firedOnSingleRead = false;

    /** @var callable(): void */
    private $duringBlockRead;

    /** @var (callable(): void)|null */
    private $duringSingleRead;

    /**
     * @param  callable(): void  $duringBlockRead  lo que confirma mientras la pasada lee
     * @param  (callable(): void)|null  $duringSingleRead
     *                                                     Lo que ocurre **dentro de la transaccion de la
     *                                                     correccion**, con la fila de `daily_totals` ya bloqueada y antes de releer
     *                                                     la jornada. Es el unico punto desde el que se puede provocar un choque de
     *                                                     candados con un fichaje en vuelo, que es lo que exige la prueba del abrazo
     *                                                     mortal.
     */
    public function __construct(
        private readonly WorkDayLedger $inner,
        callable $duringBlockRead,
        ?callable $duringSingleRead = null,
    ) {
        $this->duringBlockRead = $duringBlockRead;
        $this->duringSingleRead = $duringSingleRead;
    }

    public function openWorkDays(): array
    {
        return $this->inner->openWorkDays();
    }

    public function workDaysBetween(WorkDate $from, WorkDate $to): array
    {
        $workDays = $this->inner->workDaysBetween($from, $to);

        if (! $this->fired) {
            // La marca se pone **antes** de disparar: la clausura bifurca, y el
            // hijo hereda esta misma instancia. Con la marca puesta despues, un
            // hijo que volviera a pasar por aqui montaria otra carrera dentro de
            // la carrera.
            $this->fired = true;

            ($this->duringBlockRead)();
        }

        return $workDays;
    }

    public function workDayOf(string $employeeUuid, WorkDate $workDate): ?WorkDay
    {
        // **Antes de delegar**, no despues: lo que la prueba del abrazo mortal
        // necesita es que el otro proceso ya este esperando un candado cuando
        // esta transaccion siga adelante, y la relectura tiene que ver el estado
        // de entonces.
        if ($this->duringSingleRead !== null && ! $this->firedOnSingleRead) {
            $this->firedOnSingleRead = true;

            ($this->duringSingleRead)();
        }

        return $this->inner->workDayOf($employeeUuid, $workDate);
    }

    public function lastClockOutBefore(string $employeeUuid, DateTimeImmutable $instant): ?DateTimeImmutable
    {
        return $this->inner->lastClockOutBefore($employeeUuid, $instant);
    }
}
