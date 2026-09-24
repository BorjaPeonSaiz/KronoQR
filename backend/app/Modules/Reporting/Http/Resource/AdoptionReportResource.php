<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Http\Resource;

use App\Modules\Reporting\Domain\Policy\AdoptionIndicators;
use App\Modules\Reporting\Domain\ValueObject\AdoptionIndicator;
use App\Modules\Reporting\Domain\ValueObject\AdoptionOriginShare;
use App\Modules\Reporting\Domain\ValueObject\AdoptionReport;
use App\Modules\Reporting\Domain\ValueObject\DateRange;
use App\Modules\Shared\Domain\ValueObject\UtcInstant;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Lang;
use RuntimeException;

/**
 * Serializa el `200` de `GET /api/v1/reports/adoption`: el esquema
 * `AdoptionReport` del contrato (**RF-IN-08**).
 *
 * ## Aqui se traduce, y solo aqui
 *
 * Los criterios viajan desde el dominio como **claves** porque el dominio no tiene
 * idioma. La traduccion ocurre en el borde, con el idioma de la peticion. **Si
 * falta un texto se lanza** en vez de servir la clave: `adoption.criteria.origin`
 * impreso en una pantalla de direccion no parece un error, parece una pantalla
 * rota — y el cliente ingles no tiene forma de saber que eso era una frase.
 *
 * ## Aqui no se calcula nada
 *
 * Ni un porcentaje, ni una diferencia, ni un «cumple / no cumple». Todo llega
 * hecho de {@see AdoptionIndicators}. En
 * particular **el `delta` no se recalcula**: derivarlo aqui daria un segundo sitio
 * donde decidir que pasa cuando falta un extremo, y el cuadro entero se sostiene
 * sobre que esa decision esta en uno solo.
 *
 * ## Los nulos se serializan, no se omiten
 *
 * `current: null` sale en el JSON. Omitir la clave obligaria al cliente a
 * distinguir «no vino» de «vino nulo», que aqui significan lo mismo —«no se
 * sabe»— y ademas rompe `required` del contrato. Los doce indicadores salen
 * siempre y en el mismo orden, aunque alguno este vacio: uno que desapareciera de
 * la lista se leeria en la pantalla como una averia del cuadro.
 *
 * ## Ni un identificador de persona
 *
 * Por construccion, no por filtrado: el objeto de dominio que entra aqui no tiene
 * ninguno (regla dura 21). Es tambien la razon de que esta lectura no escriba
 * asiento de divulgacion.
 *
 * @property-read AdoptionReport $resource
 */
final class AdoptionReportResource extends JsonResource
{
    public static $wrap = null;

    public function __construct(AdoptionReport $report)
    {
        parent::__construct($report);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'data' => [
                'indicators' => array_map(self::indicator(...), $this->resource->indicators),
                'origin_breakdown' => array_map(self::origin(...), $this->resource->originBreakdown),
            ],
            'meta' => [
                'generated_at' => UtcInstant::of($this->resource->generatedAt),
                'time_zone' => $this->resource->timeZone,
                'period' => self::period($this->resource->range),
                'previous_period' => self::period($this->resource->previousRange),
                'criteria' => array_map(self::text(...), $this->resource->criteria),
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function indicator(AdoptionIndicator $indicator): array
    {
        return [
            'key' => $indicator->key->value,
            'unit' => $indicator->unit->value,
            'current' => $indicator->current,
            'previous' => $indicator->previous,
            'delta' => $indicator->delta,
            'target' => $indicator->target === null ? null : [
                'comparison' => $indicator->target->comparison->value,
                'value' => $indicator->target->value,
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function origin(AdoptionOriginShare $share): array
    {
        return [
            'origin' => $share->origin,
            'scans' => $share->scans,
            'share' => $share->share,
        ];
    }

    /**
     * `days` viaja resuelto y no se deja que lo calcule el cliente: «inclusive por
     * los dos extremos» es la clase de detalle que cada pantalla resuelve de una
     * manera, y es ademas lo que hace evidente que los dos periodos del cuadro
     * tienen la misma anchura.
     *
     * @return array<string, mixed>
     */
    private static function period(DateRange $range): array
    {
        return [
            'from' => $range->isoFrom(),
            'to' => $range->isoTo(),
            'days' => $range->days(),
        ];
    }

    private static function text(string $key): string
    {
        $line = Lang::get('reports.'.$key);

        if (! \is_string($line) || $line === 'reports.'.$key) {
            throw new RuntimeException(
                'Falta el texto «reports.'.$key.'» en lang/'.Lang::getLocale().'.'
            );
        }

        return $line;
    }
}
