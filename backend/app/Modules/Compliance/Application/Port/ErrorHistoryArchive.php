<?php

declare(strict_types=1);

namespace App\Modules\Compliance\Application\Port;

/**
 * El historico de errores agrupado por huella (RF-PD-15, 90 dias).
 *
 * La tabla `error_events` la crea la **tarea 5.12**. Hasta entonces el
 * adaptador informa de que el almacen no esta instalado —{@see
 * \App\Modules\Compliance\Domain\ValueObject\RetentionTally::unavailable()}— en
 * lugar de decir «0 filas»: un informe que afirmase que el ciclo corto esta
 * corriendo sobre una tabla que no existe seria falso, y este informe se
 * archiva.
 *
 * Su plazo se declara aqui igualmente porque la decision de RL-11 se toma en
 * esta tarea, con el diseno delante, y no tres fases despues: la 5.12 crea la
 * tabla y la encuentra ya con su ciclo escrito.
 */
interface ErrorHistoryArchive extends ShortCycleArchive {}
