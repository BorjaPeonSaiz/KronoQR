<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Http\Controller;

use App\Exceptions\ProblemDetails;
use App\Http\Controllers\Controller;
use App\Modules\Reporting\Application\UseCase\DownloadReportExport;
use App\Modules\Reporting\Domain\Exception\ReportExportLinkUnavailable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * `GET /api/v1/reports/exports/{uuid}/download?token=…` — entrega el fichero
 * (**RF-IN-06**, **ADR-041**).
 *
 * ## Va SIN sesion, y eso es la decision
 *
 * No lleva `auth:sanctum`, no lleva `ability:` y no tiene policy. La autorizacion
 * es el token de un solo uso que consume {@see DownloadReportExport}, y la razon
 * es practica: un enlace que se abre con un clic —desde la pantalla, desde el
 * historial del navegador— no lleva cabecera `Authorization`, y obligar a que la
 * llevara significaria que el panel tendria que descargar el fichero entero en
 * memoria con `fetch` antes de ofrecerlo.
 *
 * Es precisamente esa ausencia la que exige que el enlace sea **corto, de un solo
 * uso y ligado a una fila**: la misma tecnica que las URL firmadas de Laravel,
 * hecha a mano para que el secreto no sea `APP_KEY` —que firma todo lo demas—
 * sino un token por descarga que se consume.
 *
 * ## Y por eso lleva zona propia y estrecha
 *
 * `throttle:report-download`, 30 r/m **por IP**. Es la unica defensa de volumen
 * que queda cuando no hay cuenta por la que contar, y lo que impide que alguien
 * que conozca un `uuid` pruebe tokens a la velocidad de la red. El resto de la
 * defensa es el tamaño del secreto: ~74 bits aleatorios del `uuid` v7 —48 de sus
 * 122 son marca de tiempo— mas 256 bits de token, y un solo uso.
 *
 * ## Tres respuestas, y cada una dice algo distinto
 *
 * - **`200`** con el fichero, `Content-Disposition: attachment` y las dos
 *   cabeceras de comprobacion (`X-Kronoqr-Export-Sha256` y `X-Kronoqr-Export-Rows`).
 * - **`410`** con `…link-used` o `…link-expired`: el fichero sigue ahi, la salida
 *   es pedir otro enlace desde la pantalla.
 * - **`404`** sin detalle: no existe, fallo, se purgo, sigue en curso, el fichero
 *   ya no esta en el disco o el token sencillamente no es el de nadie.
 *
 * ## El asiento ya esta escrito cuando se llega aqui
 *
 * `report_export.downloaded` (RS-05). Ver {@see DownloadReportExport}: si se
 * escribiera despues, una descarga cortada a la mitad sacaria del servidor un
 * fichero con las horas de la plantilla sin dejar rastro.
 */
final class ReportExportDownloadController extends Controller
{
    public function __invoke(
        string $uuid,
        Request $request,
        DownloadReportExport $exports,
    ): BinaryFileResponse|JsonResponse {
        try {
            $export = $exports->handle($uuid, $request->query('token') === null
                ? ''
                : (string) $request->query('token'));
        } catch (ReportExportLinkUnavailable $unavailable) {
            return $unavailable->expired
                ? ProblemDetails::reportExportLinkExpired()
                : ProblemDetails::reportExportLinkUsed();
        }

        if ($export === null || $export->filePath === null) {
            // Sin detalle: enumerar por que no esta —no existe, fallo, se purgo, es
            // de otra persona— no le cambia la accion a quien lo recibe y si diria
            // si ese identificador existe.
            throw new NotFoundHttpException;
        }

        $response = response()->download($export->filePath, $export->fileName ?? 'kronoqr-informe', [
            // Las dos cabeceras que permiten comprobar que llego entero sin
            // abrirlo: la huella tal como consta en la fila y en `audit_log`, y las
            // filas de datos que lleva.
            'X-Kronoqr-Export-Sha256' => $export->sha256 ?? '',
            'X-Kronoqr-Export-Rows' => (string) ($export->rowCount ?? 0),
        ]);

        /*
         * `Cache-Control` DESPUES de construir la respuesta, y no entre las
         * cabeceras de arriba.
         *
         * `BinaryFileResponse` reescribe su propio control de cache al prepararse y
         * deja `public`, que sobre un fichero con las horas nominales de la
         * plantilla es exactamente lo contrario de lo que hace falta: cualquier
         * proxy del hotel podria quedarse una copia, y ademas la URL lleva el token
         * dentro. Puesto aqui, es el ultimo valor y manda.
         */
        $response->headers->set('Cache-Control', 'no-store, private');

        return $response;
    }
}
