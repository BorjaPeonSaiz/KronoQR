<?php

declare(strict_types=1);

namespace App\Modules\Attendance\Domain\ValueObject;

use App\Modules\Attendance\Domain\Policy\ManualEntryEligibility;
use App\Modules\Attendance\Domain\Policy\ScanIntentPolicy;
use DateInterval;
use DateTimeImmutable;

/**
 * **La cota de continuacion de RN-05**: hasta cuando un tramo que empieza en otro
 * dia civil sigue siendo de la misma jornada.
 *
 * Es el descanso minimo entre jornadas del perfil de cumplimiento (RN-10,
 * `min_rest_hours`), ya resuelto por quien construye esto (regla dura 14). El
 * razonamiento es el del art. 34.3 ET leido al derecho: si entre la ultima marca
 * de la jornada y la entrada que llega median **menos** que el descanso minimo,
 * no ha podido haber un descanso entre jornadas y sigue siendo la misma; con el
 * umbral o mas, **por definicion legal ya es otra**.
 *
 * **Una sola semantica para todos los caminos que la aplican**: la vuelta de
 * una pausa en el quiosco ({@see ScanIntentPolicy}),
 * el alta, la correccion y la anulacion manuales (`WorkDay::addEntry()`,
 * `correctEntry()` y `voidEntry()`) y el limite de las marcas de una persona de
 * baja ({@see ManualEntryEligibility}). Si
 * cada uno comparase por su cuenta, el dia que uno escribiera `<=` donde va `<`
 * el mismo hueco seria pausa en el quiosco y jornada nueva en el panel.
 *
 * **Estricta y en segundos reales**: con 12 h, 11 h 59 min 59 s continua y
 * 12 h 00 exactas ya no. Segundos reales porque es aritmetica sobre instantes
 * UTC, inmune al cambio de hora (RN-09): la noche de octubre tiene una hora mas
 * de reloj, no una hora mas de descanso.
 */
final readonly class ContinuationCeiling
{
    private function __construct(public WorkedDuration $minimumRest) {}

    /**
     * La cota, escrita como lo que es: el descanso minimo entre jornadas.
     */
    public static function belowRestOf(WorkedDuration $minimumRestBetweenWorkDays): self
    {
        return new self($minimumRestBetweenWorkDays);
    }

    /**
     * El primer instante que ya **no** continua una jornada cuya ultima marca
     * fue `$lastMark`. Es el unico sitio donde se suma la cota.
     */
    public function limitAfter(DateTimeImmutable $lastMark): DateTimeImmutable
    {
        return $lastMark->add(new DateInterval('PT'.$this->minimumRest->minutes.'M'));
    }

    /**
     * Si una marca que empieza en `$start` continua la jornada cuya ultima marca
     * fue `$lastMark`: es anterior a {@see limitAfter()}.
     *
     * Una marca anterior o simultanea a `$lastMark` continua siempre: no ha
     * pasado ningun descanso. Quien necesita ademas el orden —la cola offline
     * desordenada— lo comprueba antes por su cuenta.
     */
    public function continues(DateTimeImmutable $lastMark, DateTimeImmutable $start): bool
    {
        // Los objetos de fecha se comparan por instante, en cualquier zona.
        return $start < $this->limitAfter($lastMark);
    }

    /**
     * La cota en horas, para los mensajes: «12 h». El perfil la guarda en horas
     * enteras (`min_rest_hours`), asi que no hay fraccion que escribir.
     */
    public function hours(): string
    {
        return (string) intdiv($this->minimumRest->minutes, 60);
    }
}
