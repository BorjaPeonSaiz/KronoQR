<?php

declare(strict_types=1);

namespace App\Modules\Attendance\Application\UseCase;

/**
 * Como termino el intento de corregir **una** jornada sospechosa (RF-PR-02).
 *
 * Son cuatro y no dos, porque de ellos dependen tres cosas que se leen desde
 * fuera —`projection_divergence_total`, el codigo de salida del comando y el
 * asiento de `audit_log`— y las cuatro situaciones exigen respuestas distintas:
 *
 *   · {@see self::Corrected} — la relectura bajo candado confirmo la divergencia
 *     y se reescribio la fila. **Es un incidente de integridad** (regla dura 7):
 *     cuenta como divergencia y deja asiento.
 *   · {@see self::ResolvedItself} — bajo el candado ya no divergia nada. Era la
 *     lectura de la pasada quedandose vieja mientras alguien fichaba: no se
 *     escribe, y no cuenta como divergencia.
 *   · {@see self::Failed} — la transaccion no pudo terminar por un motivo que no
 *     es contencion. La jornada sigue divergiendo: cuenta como divergencia **y**
 *     como fallo.
 *   · {@see self::Contended} — no se pudo tomar el candado, o PostgreSQL rompio
 *     un abrazo mortal. **No dice nada sobre la integridad de la proyeccion**:
 *     nadie llego a comparar nada. Cuenta como fallo —quedo trabajo sin hacer y
 *     el comando sale en rojo— pero **no** como divergencia: si contara, un pico
 *     de fichajes encenderia la alerta critica de integridad, que es la que
 *     significa «alguien escribio la tabla por un camino que no es el
 *     recalculo».
 */
enum CorrectionOutcome
{
    case Corrected;

    case ResolvedItself;

    case Failed;

    case Contended;

    /**
     * Si este desenlace habla de la **integridad** de la proyeccion.
     *
     * Solo dos lo hacen: el que reescribio una fila y el que encontro una
     * divergencia y no pudo con ella. Los otros dos hablan de concurrencia, que
     * es otra cosa y tiene otro contador.
     */
    public function countsAsDivergence(): bool
    {
        return $this === self::Corrected || $this === self::Failed;
    }

    /**
     * Si la pasada dejo trabajo sin hacer.
     */
    public function countsAsFailure(): bool
    {
        return $this === self::Failed || $this === self::Contended;
    }
}
