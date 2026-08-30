<?php

declare(strict_types=1);

namespace App\Modules\Attendance\Application\Command;

/**
 * La orden de contrastar `daily_totals` con sus eventos origen en un rango de
 * jornadas (RF-PR-02, ADR-007).
 *
 * **Las fechas viajan como texto ISO y no como `WorkDate`**, y no es pereza: un
 * `WorkDate` es una fecha civil **mas la zona en que es civil**, y la zona la
 * pone el centro. Quien lanza el comando escribe `--from=2026-03-01`, no una
 * zona horaria; resolverla es trabajo del caso de uso, que es quien tiene el
 * puerto que la sirve (RN-05).
 *
 * **Sin fechas significa «ayer»**, que es el camino del planificador: la pasada
 * nocturna revisa la jornada que acaba de cerrarse. Hacia atras no se va por
 * defecto —la decision de retroactividad del doc 01 §4— pero aqui, a diferencia
 * de la deteccion de incidencias, ampliar el rango no abre nada sobre nadie:
 * solo comprueba y, si hace falta, corrige una copia derivada.
 */
final readonly class ReconcileDailyTotalsCommand
{
    public function __construct(
        /** Fecha civil `YYYY-MM-DD` en la zona del centro, o `null` para «ayer». */
        public ?string $fromIsoDate = null,
        /** Fecha civil `YYYY-MM-DD`, o `null` para «la misma que `from`». */
        public ?string $toIsoDate = null,
    ) {}
}
