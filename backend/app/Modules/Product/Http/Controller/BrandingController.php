<?php

declare(strict_types=1);

namespace App\Modules\Product\Http\Controller;

use App\Exceptions\ProblemDetails;
use App\Http\Controllers\Controller;
use App\Modules\Product\Application\UseCase\GetBrandingHandler;
use App\Modules\Product\Http\Resource\BrandingResource;
use App\Modules\Product\Http\Support\BrandingTelemetry;
use App\Modules\Shared\Application\Port\BrandingLogoReader;
use App\Modules\Shared\Domain\ValueObject\LogoFormat;
use App\Modules\Shared\Domain\ValueObject\LogoImage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * `GET /api/v1/branding` y `GET /api/v1/branding/logo` — la marca de la
 * instalacion para las tres aplicaciones (**RF-PD-08**, regla dura 13).
 *
 * ## Las dos son PUBLICAS, y tienen que serlo
 *
 * El quiosco enseña la pantalla de espera antes de que nadie escanee nada, y el
 * portal enseña la de acceso antes de que nadie se identifique: las dos llevan
 * ya la marca del hotel. Una marca tras autenticacion obligaria a pintar primero
 * el producto y repintar despues, que es exactamente el parpadeo que un cliente
 * ve como «el sistema se ha equivocado de hotel».
 *
 * **Lo que se publica es lo mismo que revela la tarjeta impresa** que cada
 * empleado lleva en el bolsillo: el nombre del hotel y su color. Ningun umbral
 * operativo, ninguna ruta del servidor, ninguna otra clave — y no por omision,
 * sino porque {@see GetBrandingHandler} nombra las cinco que salen.
 *
 * Su unica guarda es `throttle:branding`, por IP: la piden navegadores al
 * arrancar, no personas.
 *
 * ## Sin policy, y no es un olvido
 *
 * La regla dura 18 pide policy y prueba de autorizacion negativa **a cada
 * endpoint**, y estos dos no tienen a quien autorizar: se sirven sin sesion. Lo
 * que si esta probado en negativo es la otra mitad, que es donde vive el poder:
 * **cambiar** la marca es `PATCH /api/v1/settings`, con `settings:*` y rol
 * `admin`, y un token de quiosco o de portal recibe `403` alli.
 *
 * ## `404` en el logotipo dice exactamente lo mismo que «no hay ninguno»
 *
 * Un fichero fuera del directorio de marca, borrado, que no es PNG ni SVG o que
 * pasa de los limites responden igual que una instalacion sin logotipo
 * configurado. **La ruta configurada no se revela nunca**, ni en el cuerpo ni en
 * una cabecera: es una ruta del servidor del cliente y este endpoint es publico.
 */
final class BrandingController extends Controller
{
    public function show(GetBrandingHandler $handler, BrandingTelemetry $telemetry): JsonResponse
    {
        $branding = $telemetry->measureRead(
            static fn () => $handler->handle(),
        );

        return (new BrandingResource($branding))->response();
    }

    /**
     * Los bytes del logotipo, con el tipo que le corresponde **por su
     * contenido**.
     *
     * ## Se sirve entero y en memoria, no en streaming
     *
     * Cabe en 512 KiB por definicion —lo comprueba `LogoFileInspector` al
     * guardar y al leer—, asi que un `StreamedResponse` solo añadiria una
     * respuesta que no se puede cachear en un cuerpo que ya esta leido.
     *
     * ## Las cabeceras no son decoracion
     *
     * - `Cache-Control: public, max-age=31536000, immutable` porque la URL que
     *   publica `GET /api/v1/branding` lleva la huella del contenido: cambiar el
     *   logotipo cambia la URL, y la anterior puede quedarse cacheada para
     *   siempre sin que nadie llegue a ver la vieja. Es tambien lo que permite
     *   que el service worker del quiosco la guarde con `CacheFirst` y siga
     *   enseñando la marca sin red (RF-KI-03).
     * - `ETag` con la huella completa, y **se honra `If-None-Match`**: un cliente
     *   que ya tenga esa version recibe un `304` sin cuerpo en lugar de medio
     *   megabyte. Importa mas de lo que parece en el quiosco, que revalida al
     *   recuperar la red y suele hacerlo por una conexion de hotel.
     * - `X-Content-Type-Options: nosniff` **siempre**, aunque el tipo se haya
     *   decidido por el contenido: es la defensa que queda si algun dia la
     *   comprobacion se relajara.
     * - Y para un SVG, ademas, `Content-Security-Policy` con `sandbox`: un SVG
     *   es un documento, no una imagen, y abierto en una pestaña puede ejecutar
     *   lo que lleve dentro. El inspector ya rechaza los que traen un guion; esto
     *   es la segunda linea, en la puerta.
     */
    public function logo(Request $request, BrandingLogoReader $logos): Response
    {
        $logo = $logos->current();

        if (! $logo instanceof LogoImage) {
            return ProblemDetails::notFound(
                'Esta instalacion no tiene ningun logotipo utilizable configurado.',
            );
        }

        $headers = [
            'Content-Type' => $logo->mimeType(),
            'Cache-Control' => 'public, max-age=31536000, immutable',
            'X-Content-Type-Options' => 'nosniff',
        ];

        if ($logo->format === LogoFormat::SVG) {
            // `style-src 'unsafe-inline'` porque un logotipo vectorial lleva sus
            // colores en atributos y en un `<style>` interno: sin eso saldria en
            // negro. Todo lo demas, cerrado.
            $headers['Content-Security-Policy'] = "default-src 'none'; style-src 'unsafe-inline'; sandbox";
        }

        $response = new Response($logo->bytes, Response::HTTP_OK, $headers);

        // `setEtag()` y no una cabecera a mano: es lo que hace que
        // `isNotModified()` sepa comparar con `If-None-Match`. Symfony vacia el
        // cuerpo y deja el `304` con las cabeceras de validacion, que es
        // exactamente lo que promete el docblock.
        $response->setEtag($logo->sha256());
        $response->isNotModified($request);

        return $response;
    }
}
