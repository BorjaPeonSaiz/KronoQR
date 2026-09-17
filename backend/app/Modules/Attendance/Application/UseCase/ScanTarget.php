<?php

declare(strict_types=1);

namespace App\Modules\Attendance\Application\UseCase;

use App\Modules\Attendance\Domain\Model\WorkDay;
use App\Modules\Attendance\Domain\ValueObject\ClockingResolution;

/**
 * **Sobre que jornada actua este escaneo, con que accion y si hay que marcarlo**
 * (RF-AT-12, ADR-024).
 *
 * ## Por que las tres cosas viajan juntas
 *
 * Porque cargar la jornada de una vuelta de pausa puede **cambiar la decision
 * que ya se habia tomado**. El dominio resuelve `BREAK_END` con el `uuid` que el
 * escaneo anterior dejo escrito —no sabe, ni debe, si ese tramo sigue estando—;
 * si al buscarlo resulta que ya no existe —una purga por retencion (RL-02) es el
 * unico camino conocido— no hay ninguna jornada que continuar, y afirmar
 * `result: break_end` sobre una jornada nueva seria escribir en el registro
 * legal una pausa que no ocurrio.
 *
 * Entonces se degrada a una entrada normal sobre la jornada de la fecha **y se
 * marca para revision humana**: alguien tiene que mirar por que una vuelta de
 * pausa no encontro su tramo. No se rechaza (regla dura 19) ni se lanza: el
 * empleado esta delante de la tablet y no tiene nada que ver con una purga.
 *
 * Devolver los tres valores en un objeto y no por referencia es lo que mantiene
 * `RegisterScanHandler::processResolved()` por debajo de la complejidad del
 * §3.5 y, sobre todo, lo que impide que alguien use la resolucion **anterior** a
 * la degradacion para fijar `scan_events.result`.
 */
final readonly class ScanTarget
{
    private function __construct(
        /** La jornada que se va a modificar. Ya cargada o recien empezada. */
        public WorkDay $workDay,
        /** La decision **definitiva**: puede no ser la que entro, si hubo degradacion. */
        public ClockingResolution $resolution,
        /** RN-15: si este escaneo pide validacion humana. Solo se enciende, nunca se apaga. */
        public bool $flaggedForReview,
    ) {}

    /** El caso normal: la decision del dominio se aplica tal cual. */
    public static function of(WorkDay $workDay, ClockingResolution $resolution, bool $flaggedForReview): self
    {
        return new self($workDay, $resolution, $flaggedForReview);
    }

    /**
     * La vuelta de pausa no encontro su tramo: entrada normal y marcada.
     *
     * `flaggedForReview` entra a `true` **sin mirar el valor anterior**: si ya
     * venia marcado por desfase de reloj, sigue marcado; si no, lo queda por
     * esto. La marca es una sola bandera y lo que significa es «que lo mire una
     * persona», no «por que».
     */
    public static function degradedToClockIn(WorkDay $workDay): self
    {
        return new self($workDay, ClockingResolution::clockIn(), true);
    }
}
