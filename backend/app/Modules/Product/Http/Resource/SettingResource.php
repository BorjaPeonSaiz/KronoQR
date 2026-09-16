<?php

declare(strict_types=1);

namespace App\Modules\Product\Http\Resource;

use App\Modules\Product\Domain\ValueObject\SettingDefinition;
use App\Modules\Product\Domain\ValueObject\SettingValue;
use App\Modules\Product\Http\Policy\SettingsPolicy;
use App\Modules\Shared\Application\Port\ManagementActor;
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
 * ## `redacted`: el fabricante no se lleva los secretos del cliente
 *
 * Va **siempre**, y vale `false` en todo salvo en una clave `confidential`
 * servida a un actor de soporte, donde ademas `value` sale `null`. Que este
 * siempre y no solo cuando es `true` es deliberado: un campo que aparece a
 * veces obliga a distinguir «ausente» de «falso», y es asi como se acaba con un
 * panel que enseña un valor vacio como si fuera el valor.
 *
 * Es aditivo sobre `/api/v1` (ADR-012): un cliente que no lo conozca lo ignora.
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
        $redacted = $definition->confidential && self::isSupportActor($request);

        return [
            'key' => $setting->key->value,
            'value' => $redacted ? null : $setting->value(),
            'redacted' => $redacted,
            'type' => $definition->type->value,
            'impact' => $definition->impact->value,
            'affects_worked_hours' => $setting->affectsWorkedHours(),
            'source' => $setting->isProductDefault ? 'product_default' : 'installation',
            ...$this->constraints($definition),
        ];
    }

    /**
     * Si quien pide esta pantalla es el **fabricante** y no el cliente.
     *
     * Un acceso de soporte con alcance `configuration` entra aqui a proposito
     * —configurar la instalacion del cliente es para lo que se concede— pero una
     * clave `confidential` no es configuracion: es un secreto vivo del cliente.
     * El codigo de servicio del quiosco (RF-KI-08) abre la pantalla de
     * mantenimiento de todas sus tablets, y quien viene a arreglar una incidencia
     * tecnica no lo necesita para nada.
     *
     * **Ni pretender que no existe.** Se devuelve la fila con `value: null` y
     * `redacted: true` en lugar de omitirla: ocultarla diria «ese ajuste no
     * existe en este producto», y quien esta al teléfono con el cliente tiene que
     * poder decirle «esto lo tienes puesto, míralo tú». El `PATCH` que la toque
     * recibe `403` por la otra mitad de la puerta
     * ({@see SettingsPolicy::updateConfidential()}).
     *
     * Se resuelve por el puerto compartido {@see ManagementActor::isSupportActor()},
     * igual que las policies de licencia, diagnostico y concesiones: la pregunta
     * «¿es soporte?» tiene una sola respuesta en el producto.
     */
    private static function isSupportActor(Request $request): bool
    {
        $actor = $request->user();

        return $actor instanceof ManagementActor && $actor->isSupportActor();
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
