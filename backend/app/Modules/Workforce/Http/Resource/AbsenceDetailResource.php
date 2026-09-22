<?php

declare(strict_types=1);

namespace App\Modules\Workforce\Http\Resource;

use App\Modules\Workforce\Application\Port\AbsenceView;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Serializacion del esquema `AbsenceDetail`: la ausencia con su historial
 * (**RF-GP-04**, **RN-13**).
 *
 * **El historial va de la mas antigua a la mas reciente y no incluye la
 * propia.** Es lo que sostiene el «de que valor a cual» de la pantalla: sin el,
 * una correccion seria indistinguible de una reescritura, que es justo lo que la
 * regla dura 5 prohibe.
 *
 * **La nota se omite en el historial con el mismo criterio que en la ausencia
 * vigente.** No tendria ningun sentido protegerla en una y repartirla en las
 * versiones anteriores: seria la misma nota por otra puerta.
 *
 * @property-read AbsenceView $resource
 */
final class AbsenceDetailResource extends JsonResource
{
    public static $wrap = null;

    /**
     * @param  list<AbsenceView>  $history
     */
    public function __construct(
        AbsenceView $view,
        private readonly array $history,
        private readonly bool $includesNote,
    ) {
        parent::__construct($view);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var AbsenceView $view */
        $view = $this->resource;

        return [
            'absence' => (new AbsenceResource($view, $this->includesNote))->toArray($request),
            'history' => array_map(
                fn (AbsenceView $version): array => (new AbsenceResource($version, $this->includesNote))
                    ->toArray($request),
                $this->history,
            ),
        ];
    }
}
