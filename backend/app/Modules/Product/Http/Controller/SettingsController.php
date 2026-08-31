<?php

declare(strict_types=1);

namespace App\Modules\Product\Http\Controller;

use App\Http\Controllers\Controller;
use App\Modules\Product\Application\UseCase\GetSettingsHandler;
use App\Modules\Product\Application\UseCase\UpdateSettingsHandler;
use App\Modules\Product\Domain\ValueObject\ResolvedSettings;
use App\Modules\Product\Http\Request\UpdateSettingsRequest;
use App\Modules\Product\Http\Resource\SettingCollectionResource;
use App\Modules\Product\Http\Support\SettingsTelemetry;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

/**
 * `GET` y `PATCH /api/v1/settings` — la configuracion de la instalacion
 * (**RF-PD-01**, regla dura 13).
 *
 * Recurso singular sin identificador en la ruta: la configuracion es una y es de
 * la instalacion. No hay alta ni baja de claves —el catalogo es codigo— y no hay
 * `DELETE`: volver al valor de serie es escribirlo, no borrar la fila.
 *
 * Delgado como el resto: autoriza, invoca el caso de uso y serializa el
 * `Resource`. **Ninguna decision vive aqui.** Que valores admite cada clave lo
 * dice el catalogo, que claves cambian de verdad lo decide el caso de uso, y el
 * asiento de `audit_log` lo escribe el listener de `Compliance`. Si este fichero
 * tuviera un `if` sobre una clave concreta, seria un dato de cliente en el
 * codigo.
 *
 * **Sin `404` posible.** Al contrario que `/site`, esto responde siempre: una
 * instalacion sin ninguna fila devuelve el catalogo con los valores de serie. El
 * valor por defecto **es** el producto.
 */
final class SettingsController extends Controller
{
    public function show(GetSettingsHandler $handler, SettingsTelemetry $telemetry): JsonResponse
    {
        Gate::authorize('view', ResolvedSettings::class);

        $settings = $telemetry->measureRead(
            static fn (): ResolvedSettings => $handler->handle(),
        );

        return (new SettingCollectionResource($settings))->response();
    }

    public function update(
        UpdateSettingsRequest $request,
        UpdateSettingsHandler $handler,
        SettingsTelemetry $telemetry,
    ): JsonResponse {
        $command = $request->toCommand();

        $settings = $telemetry->measureUpdate(
            array_keys($command->values),
            static fn (): ResolvedSettings => $handler->handle($command),
        );

        return (new SettingCollectionResource($settings))->response();
    }
}
