<?php

declare(strict_types=1);

namespace App\Modules\Product\Http\Controller;

use App\Http\Controllers\Controller;
use App\Modules\Product\Application\UseCase\GenerateDiagnosticsBundleHandler;
use App\Modules\Product\Domain\ValueObject\DiagnosticsActor;
use App\Modules\Product\Http\Request\GenerateDiagnosticsBundleRequest;
use App\Modules\Shared\Application\Port\ManagementActor;
use Illuminate\Http\JsonResponse;

/**
 * `POST /api/v1/diagnostics/bundle` (Anexo B, **RF-PD-09**, ADR-020).
 *
 * Delgado como el resto: autoriza —en el FormRequest—, invoca el caso de uso y
 * responde. Que entra en el paquete lo deciden los recolectores, el asiento lo
 * escribe el listener de `Compliance` y el aviso previo lo pinta el panel.
 *
 * ## Devuelve el paquete en la respuesta y no escribe nada en disco
 *
 * Un fichero por cada clic acumularia informacion del cliente en `storage/` sin
 * que nadie la borre nunca, y con `include_personal_data` eso serian copias de
 * la plantilla criando polvo. Por consola si se escribe, porque quien entra por
 * SSH necesita una ruta que pasar a `scp`.
 *
 * ## `Content-Disposition` con el nombre ya puesto
 *
 * Para que el navegador lo guarde sin que el panel tenga que inventarse un
 * nombre. El nombre lleva version e instante porque el caso normal es que un
 * cliente envie varios paquetes de la misma incidencia.
 *
 * ## No degrada con la licencia caducada (regla dura 15)
 *
 * Es justamente lo que se necesita cuando algo va mal.
 */
final class DiagnosticsController extends Controller
{
    public function generate(
        GenerateDiagnosticsBundleRequest $request,
        GenerateDiagnosticsBundleHandler $diagnostics,
    ): JsonResponse {
        $actor = $request->user();

        $bundle = $diagnostics->handle(
            $request->toOptions(),
            $actor instanceof ManagementActor && $actor->isSupportActor()
                ? DiagnosticsActor::SupportGrant
                : DiagnosticsActor::User,
        );

        /*
         * **El cuerpo lo serializa el paquete, no Laravel**, y eso es lo que
         * hace verificable el fichero descargado.
         *
         * Con `new JsonResponse($array)` el framework aplica sus propias
         * banderas: una latencia de `1.0` sale como `1`, quien reciba el fichero
         * lo relee como entero, la forma canonica cambia y
         * `product:diagnostics --verify` denunciaria como alterado un paquete
         * intacto. Soporte perderia la mañana buscando una manipulacion que no
         * existe.
         *
         * Y ademas hace que **el fichero que descarga el panel sea identico al
         * que escribe el comando**: mismo indentado, mismo orden, misma huella.
         * Un runbook escrito contra uno vale para el otro.
         */
        return JsonResponse::fromJsonString($bundle->toJson(), 200, [
            'Content-Disposition' => 'attachment; filename="'.$bundle->manifest->fileName().'"',
            // El paquete no se cachea en ningun sitio: es una foto de un
            // instante y puede llevar datos personales.
            'Cache-Control' => 'no-store',
        ]);
    }
}
