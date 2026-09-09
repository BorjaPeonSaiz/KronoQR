<?php

declare(strict_types=1);

namespace App\Modules\Product\Http\Resource;

use App\Modules\Product\Domain\ValueObject\ErrorEvent;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Serializa el `200` de `POST /api/v1/diagnostics/errors/{id}/resolve`: el
 * esquema `ErrorEvent` **suelto**, sin envoltorio (RF-PD-15).
 *
 * `$wrap = null` porque el contrato declara el objeto en la raiz. La forma la
 * pone {@see ErrorEventPayload}, que es la misma que usa la pagina: el panel
 * sustituye la fila que acaba de resolver por la que devuelve este `POST`, y dos
 * serializaciones distintas se notarian ahi al instante.
 *
 * @property-read ErrorEvent $resource
 */
final class ErrorEventResource extends JsonResource
{
    public static $wrap = null;

    public function __construct(ErrorEvent $event, private readonly bool $withResolver = true)
    {
        parent::__construct($event);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var ErrorEvent $event */
        $event = $this->resource;

        return ErrorEventPayload::of($event, $this->withResolver);
    }
}
