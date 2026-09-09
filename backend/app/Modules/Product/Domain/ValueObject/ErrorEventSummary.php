<?php

declare(strict_types=1);

namespace App\Modules\Product\Domain\ValueObject;

/**
 * El resumen del historico que viaja en el paquete de diagnostico: **cuantos
 * grupos hay por origen y por nivel, abiertos y resueltos** (RF-PD-15,
 * §11.6.6, decision 12 de la ficha 5.12).
 *
 * ## Recuentos, y por eso puede salir de la instalacion
 *
 * Aqui no hay ni un mensaje, ni una traza, ni un identificador: solo numeros.
 * Es lo primero que mira soporte al abrir un paquete —«hay ochenta grupos
 * abiertos de origen `worker`, empecemos por ahi»— y no identifica a nadie
 * (ADR-020, regla dura 21).
 *
 * ## Abiertos y resueltos por separado
 *
 * Porque la diferencia es informacion: cien grupos de los que noventa estan
 * resueltos describen una instalacion atendida; cien abiertos describen otra
 * cosa. Un unico total las confundiria.
 *
 * ## Las claves son las de los enums y siempre estan todas
 *
 * Un origen sin errores sale con ceros en lugar de no salir. Un paquete en el
 * que falta `scheduler` no dice «no hubo errores del planificador»: dice que
 * quien lo lee tiene que acordarse de que existe esa fila.
 */
final readonly class ErrorEventSummary
{
    /**
     * @param  array<string, array{open: int, resolved: int}>  $perSource  Por `ErrorSource`, en orden del enum.
     * @param  array<string, array{open: int, resolved: int}>  $perLevel  Por `ErrorLevel`, en orden del enum.
     * @param  int  $totalOpen  Grupos abiertos del periodo.
     * @param  int  $totalResolved  Grupos resueltos del periodo.
     */
    public function __construct(
        public array $perSource,
        public array $perLevel,
        public int $totalOpen,
        public int $totalResolved,
    ) {}
}
