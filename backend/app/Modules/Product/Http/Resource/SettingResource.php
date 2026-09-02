<?php

declare(strict_types=1);

namespace App\Modules\Product\Http\Resource;

use App\Modules\Product\Domain\ValueObject\SettingDefinition;
use App\Modules\Product\Domain\ValueObject\SettingValue;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Serializacion del esquema `InstallationSetting` del contrato (RF-PD-01).
 *
 * ## `source` no es decoracion
 *
 * «El cliente puso 12» y «nadie lo ha tocado y el producto vale 12» son
 * indistinguibles en el numero y muy distintos en la conversacion. El panel lo
 * necesita para enseñar que esta configurado de verdad, y quien atiende una
 * incidencia para saber si mirar el historial de cambios tiene sentido.
 *
 * ## `constraints` publica la regla, no la duplica
 *
 * Sale de {@see SettingDefinition}, que es la misma fuente que valida el `PATCH`.
 * Se envia para que el formulario del panel pueda acotar el control —un `min` y
 * un `max` en el `input`— sin llevar el catalogo copiado en TypeScript, que es
 * como se acaba con un maximo distinto en cada lado.
 *
 * Solo se emiten los campos que la clave tiene: un entero no lleva
 * `maximum_length` y una cadena libre no lleva `allowed`. Un objeto con nulos
 * obligaria al cliente a distinguir «sin limite» de «limite nulo».
 *
 * @property-read SettingValue $resource
 */
final class SettingResource extends JsonResource
{
    public static $wrap = null;

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var SettingValue $setting */
        $setting = $this->resource;

        $definition = $setting->key->definition();

        return [
            'key' => $setting->key->value,
            'value' => $setting->value(),
            'type' => $definition->type->value,
            'impact' => $definition->impact->value,
            'affects_worked_hours' => $setting->affectsWorkedHours(),
            'source' => $setting->isProductDefault ? 'product_default' : 'installation',
            ...$this->constraints($definition),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function constraints(SettingDefinition $definition): array
    {
        $constraints = [];

        if ($definition->minimum !== null) {
            $constraints['minimum'] = $definition->minimum;
        }

        if ($definition->maximum !== null) {
            $constraints['maximum'] = $definition->maximum;
        }

        if ($definition->maximumLength > 0) {
            $constraints['maximum_length'] = $definition->maximumLength;
        }

        if ($definition->pattern !== null) {
            $constraints['pattern'] = $definition->pattern;
        }

        if ($definition->allowed !== null) {
            $constraints['allowed'] = $definition->allowed;
        }

        // Sin restricciones publicables, `constraints` no se emite: el contrato
        // lo declara opcional a proposito, y un objeto vacio obligaria al cliente
        // a distinguirlo de la ausencia sin que signifiquen cosas distintas.
        return $constraints === [] ? [] : ['constraints' => $constraints];
    }
}
