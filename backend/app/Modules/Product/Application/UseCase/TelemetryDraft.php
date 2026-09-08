<?php

declare(strict_types=1);

namespace App\Modules\Product\Application\UseCase;

use App\Modules\Product\Domain\ValueObject\TelemetryReport;

/**
 * El documento que se enviaria **y** la lectura cruda de los contadores con la
 * que se construyo (**RF-PD-12**).
 *
 * ## Por que viajan juntos
 *
 * El documento lleva `usage_7d` ya restado —lo ocurrido desde el envio
 * anterior—, y el estado tiene que guardar el **acumulado** para poder restar la
 * semana que viene. Devolver solo el informe obligaria a leer Redis dos veces, y
 * la segunda lectura no seria la misma que la primera: entre una y otra caben
 * los fichajes de un cambio de turno, que se perderian del recuento para
 * siempre.
 *
 * ## Solo se guarda si el envio sale bien
 *
 * Lo decide {@see SendTelemetryHandler}. Si el envio falla, el acumulado
 * anterior sigue en pie y la semana siguiente cubre las dos.
 */
final readonly class TelemetryDraft
{
    /**
     * @param  array<string, int>  $counters  Acumulados leidos para construir el informe.
     */
    public function __construct(
        public TelemetryReport $report,
        public array $counters,
    ) {}
}
