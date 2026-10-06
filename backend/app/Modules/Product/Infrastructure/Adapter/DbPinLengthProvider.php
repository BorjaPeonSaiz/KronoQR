<?php

declare(strict_types=1);

namespace App\Modules\Product\Infrastructure\Adapter;

use App\Modules\Product\Application\UseCase\GetSettingsHandler;
use App\Modules\Product\Domain\ValueObject\SettingKey;
use App\Modules\Shared\Application\Port\PinLengthProvider;
use App\Modules\Shared\Domain\ValueObject\PinLength;

/**
 * La longitud con la que se emiten los PIN, resuelta desde
 * `installation_settings` (**RF-ID-09**, `IDENTITY_PIN_LENGTH`, ADR-050).
 *
 * Hermano de {@see DbWeeklySummaryPreference}: misma cascada —fila de la
 * instalacion, y si no el valor de serie del catalogo— y el mismo motivo para
 * existir: `Workforce` no puede importar `Product` (doc 02 §1.6).
 *
 * **Aqui se deja subir el fallo.** Se pide al dar de alta o restablecer un PIN,
 * con una persona del panel delante y nunca en el camino del fichaje: si la
 * configuracion no se puede leer, es mejor un `500` que emitir un PIN con una
 * longitud que nadie ha elegido. El catalogo solo admite `"6"` y `"8"`, asi que
 * `from()` no puede fallar con un valor guardado por el producto.
 */
final readonly class DbPinLengthProvider implements PinLengthProvider
{
    public function __construct(private GetSettingsHandler $settings) {}

    public function current(): PinLength
    {
        return PinLength::from((int) $this->settings->handle()->text(SettingKey::IDENTITY_PIN_LENGTH));
    }
}
