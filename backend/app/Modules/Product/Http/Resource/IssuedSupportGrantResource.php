<?php

declare(strict_types=1);

namespace App\Modules\Product\Http\Resource;

use App\Modules\Product\Application\UseCase\IssuedSupportGrant;
use DateTimeImmutable;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Serializa el `201` de `POST /api/v1/support/grants`: el esquema
 * `IssuedSupportGrant` (**RF-PD-11**).
 *
 * ## Es la unica respuesta del producto que lleva un secreto
 *
 * El token en claro sale aqui **una sola vez** y no se puede volver a pedir: la
 * fila solo guarda su hash. El panel lo enseña con un boton de copiar y un aviso
 * de que no se volvera a mostrar, y quien lo recibe se lo hace llegar a soporte
 * por el canal de su contrato.
 *
 * **No hay `GET` que lo devuelva y no lo habra.** Un endpoint para «volver a ver
 * el token» convertiria la concesion en una credencial permanente recuperable
 * por cualquiera que entre luego al panel, que es justo lo que ADR-020 descarta.
 * Si se pierde, se revoca y se crea otra: treinta segundos.
 *
 * @property-read IssuedSupportGrant $resource
 */
final class IssuedSupportGrantResource extends JsonResource
{
    public static $wrap = null;

    public function __construct(IssuedSupportGrant $resource, private readonly DateTimeImmutable $asOf)
    {
        parent::__construct($resource);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var IssuedSupportGrant $issued */
        $issued = $this->resource;

        return [
            'data' => (new SupportGrantResource($issued->grant, $this->asOf))->toArray($request) + [
                'token' => $issued->token,
            ],
        ];
    }
}
