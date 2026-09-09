<?php

declare(strict_types=1);

namespace App\Modules\Product\Infrastructure\Capture;

use App\Modules\Shared\Domain\ValueObject\ErrorSource;

/**
 * «Donde estoy» en el momento en que algo falla (RF-PD-15, tarea 5.12,
 * decision 2 de la ficha).
 *
 * Un marco es lo que {@see ExecutionContext} mantiene abierto mientras corre un
 * trabajo de cola, una tarea del planificador o un comando: el origen que le
 * corresponde a un error que ocurra ahi dentro, el contexto tecnico que lo situa
 * —`job`, `queue`, `attempts`, `command`— y si por si solo ya es `critical`.
 *
 * Es un objeto de valor y no una cadena porque el nivel viaja con el origen: un
 * trabajo que **agoto sus intentos** y uno que va por el primero tienen el mismo
 * `source` y distinta severidad, y quien lo decide es quien abrio el marco, no
 * quien lo lee despues.
 */
final readonly class ExecutionFrame
{
    /**
     * @param  array<string, scalar|null>  $context  Claves de la lista de permitidos del saneado (`job`, `queue`, `attempts`, `command`).
     * @param  bool  $critical  Si el marco basta para elevar la severidad, sin mirar la excepcion.
     */
    public function __construct(
        public ErrorSource $source,
        public array $context = [],
        public bool $critical = false,
    ) {}
}
