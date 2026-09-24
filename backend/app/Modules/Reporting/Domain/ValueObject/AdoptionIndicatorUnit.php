<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Domain\ValueObject;

/**
 * En que esta expresado un indicador del cuadro de impacto (**RF-IN-08**).
 *
 * ## Tres y no cuatro: no hay «horas»
 *
 * Los tiempos se expresan en **minutos enteros**, igual que en el informe por
 * periodo y por el mismo motivo: los minutos suman y las horas decimales no.
 * `8,25 h` obliga a decidir un redondeo, y dos redondeos distintos en dos sitios
 * del producto es como se acaba con un cuadro que no cuadra con el informe.
 * Quien lo enseña a una persona escribe `HH:MM`, y eso lo hace la exportacion.
 *
 * ## Por que la unidad viaja con el indicador
 *
 * Porque es lo que dice al cliente como pintarlo —un `%`, un `HH:MM` o un numero
 * a secas— y como leer el `delta`: en `percent` son **puntos porcentuales**, no
 * una variacion relativa. Sin la unidad en la respuesta, la pantalla tendria que
 * llevar una tabla de doce claves duplicada, y el dia que se añadiera un
 * indicador saldria mal formateado en silencio.
 */
enum AdoptionIndicatorUnit: string
{
    /** De 0 a 100, con dos decimales. El `delta` va en puntos porcentuales. */
    case Percent = 'percent';

    /** Minutos enteros. La exportacion los escribe `HH:MM`, tambien por encima de 24 h. */
    case Minutes = 'minutes';

    /** Un recuento entero: incidencias abiertas, personas sin tarjeta. */
    case Count = 'count';
}
