<?php

declare(strict_types=1);

namespace App\Modules\Product\Http\Resource;

use App\Modules\Product\Domain\ValueObject\InvalidSetting;
use App\Modules\Product\Domain\ValueObject\ResolvedSettings;
use App\Modules\Product\Domain\ValueObject\SettingValue;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Serializa el `200` de `GET` y de `PATCH /api/v1/settings`: el esquema
 * `InstallationSettings` del contrato (RF-PD-01).
 *
 * ## Los dos endpoints devuelven lo mismo
 *
 * El `PATCH` responde el conjunto completo y no solo lo que cambio. Asi el panel
 * no tiene que recomponer el estado a partir de lo que envio —que es donde
 * aparecen las pantallas que enseñan un valor y guardan otro— y la respuesta
 * refleja lo que de verdad quedo escrito, incluidas las claves que se
 * descartaron por no cambiar nada.
 *
 * ## Siempre el catalogo entero
 *
 * `data` lleva las nueve claves, no solo las que tienen fila. Un panel que
 * enseñara unicamente lo guardado ocultaria justo lo que el cliente todavia no
 * ha configurado, que es lo primero que necesita ver quien acaba de instalar.
 *
 * ## `meta.unknown_keys` y `meta.invalid_keys`
 *
 * Son dos problemas distintos y por eso van en dos listas:
 *
 * - **`unknown_keys`**: filas guardadas cuya clave este binario no reconoce. No
 *   las lee nadie y no cambian nada; solo pueden venir de una version posterior o
 *   de una edicion a mano.
 * - **`invalid_keys`**: filas cuya clave SI existe y cuyo valor su definicion no
 *   admite. Estas **si** cambian algo: se han descartado y rige el valor de serie,
 *   asi que lo que se aplica no es lo que hay escrito en la tabla. Si la clave es
 *   de impacto `worked_hours`, eso mueve los minutos que se calculan.
 *
 * La lectura es tolerante para que una fila corrupta no impida fichar (regla
 * dura 19); publicarlas aqui es la otra mitad, para que el descarte no sea
 * silencioso. El motivo se traduce al idioma negociado: quien lo lee es una
 * persona.
 *
 * @property-read ResolvedSettings $resource
 */
final class SettingCollectionResource extends JsonResource
{
    public static $wrap = null;

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var ResolvedSettings $settings */
        $settings = $this->resource;

        return [
            'data' => array_map(
                static fn (SettingValue $value): array => (new SettingResource($value))->toArray($request),
                $settings->all(),
            ),
            'meta' => [
                'unknown_keys' => $settings->unknownKeys,
                'invalid_keys' => array_map(
                    fn (InvalidSetting $rejected): array => [
                        'key' => $rejected->key->value,
                        'reason' => $this->reasonFor($rejected),
                        'affects_worked_hours' => $rejected->affectsWorkedHours,
                    ],
                    $settings->invalidKeys,
                ),
            ],
        ];
    }

    /**
     * El motivo, en el idioma negociado por `Accept-Language`.
     *
     * El dominio guarda la clave de traduccion y sus parametros, nunca el texto:
     * no sabe en que idioma se va a leer esto, y meter castellano dentro de
     * `Domain/` es justo lo que la tarea 5.1 corrigio en las excepciones. Si
     * faltara la traduccion se devuelve el motivo tecnico en ingles, que es peor
     * que traducirlo y mucho mejor que una clave suelta.
     */
    private function reasonFor(InvalidSetting $rejected): string
    {
        $message = __($rejected->translationKey, $rejected->parameters);

        return is_string($message) && $message !== $rejected->translationKey
            ? $message
            : $rejected->detail;
    }
}
