<?php

declare(strict_types=1);

namespace App\Modules\Product\Http\Controller;

use App\Http\Controllers\Controller;
use App\Modules\Product\Application\UseCase\ActivateLicenseHandler;
use App\Modules\Product\Application\UseCase\DescribeLicenseHandler;
use App\Modules\Product\Domain\ValueObject\LicenseOverview;
use App\Modules\Product\Domain\ValueObject\LicenseStatus;
use App\Modules\Product\Http\Request\ActivateLicenseRequest;
use App\Modules\Product\Http\Resource\LicenseResource;
use App\Modules\Product\Http\Support\LicenseTelemetry;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

/**
 * `GET /api/v1/license` y `POST /api/v1/license/activate` (Anexo B del doc 01,
 * **RF-PD-04**, ADR-018, ADR-028).
 *
 * Delgado como el resto: autoriza, invoca el caso de uso y serializa. **Ninguna
 * decision vive aqui.** La firma la comprueba el verificador, el estado lo
 * decide el dominio con el reloj inyectado, el asiento lo escribe el listener de
 * `Compliance` y el texto del aviso lo compone el panel con sus traducciones.
 *
 * ## `GET` no devuelve `404` sin licencia
 *
 * «Sin licencia» **es** un estado del recurso, no la ausencia del recurso: la
 * instalacion existe y su licencia esta en `absent`. Un `404` obligaria al panel
 * a tratar el caso mas comun de una puesta en marcha como un error, y ademas
 * seria indistinguible de una ruta mal escrita.
 *
 * ## Ninguno de los dos degrada
 *
 * Ni con la licencia caducada, ni sin ella, ni con una clave ilegible. Es la
 * pantalla desde la que se arregla el problema: cerrarla al caducar dejaria al
 * cliente sin poder activar la renovacion que acaba de comprar. Mismo criterio
 * que `/settings` y `/compliance-profile` (ADR-019, regla dura 15).
 */
final class LicenseController extends Controller
{
    public function show(DescribeLicenseHandler $licenses, LicenseTelemetry $telemetry): JsonResponse
    {
        // El sujeto de la autorizacion es el propio estado de la licencia y no
        // un modelo Eloquent: la policy no autoriza sobre una fila —que puede no
        // existir— sino sobre «la licencia de esta instalacion».
        Gate::authorize('view', LicenseStatus::class);

        $overview = $telemetry->measureRead(
            static fn (): LicenseOverview => $licenses->handle(),
        );

        return (new LicenseResource($overview))->response();
    }

    public function activate(
        ActivateLicenseRequest $request,
        ActivateLicenseHandler $activate,
        DescribeLicenseHandler $licenses,
        LicenseTelemetry $telemetry,
    ): JsonResponse {
        $command = $request->toCommand();

        $overview = $telemetry->measureActivation(static function () use ($activate, $licenses, $command): LicenseOverview {
            $activate->handle($command);

            // Se vuelve a describir en lugar de componer la respuesta con lo que
            // devolvio la activacion: asi el `200` de activar y el de consultar
            // salen del mismo calculo, cifras de uso incluidas, y no pueden
            // divergir. Es lo que hace que la pantalla enseñe lo mismo antes y
            // despues de recargarla.
            return $licenses->handle();
        });

        return (new LicenseResource($overview))->response();
    }
}
