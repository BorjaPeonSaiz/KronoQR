<?php

declare(strict_types=1);

namespace App\Modules\Compliance\Http\Controller;

use App\Http\Controllers\Controller;
use App\Modules\Compliance\Application\UseCase\GenerateLegalExport;
use App\Modules\Compliance\Application\UseCase\LegalExport;
use App\Modules\Compliance\Http\Request\GenerateLegalExportRequest;
use App\Modules\Compliance\Http\Support\LegalExportTelemetry;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * `GET /api/v1/reports/legal-export` — la exportacion normalizada para la
 * Inspeccion de Trabajo (RF-IN-05, RL-03, RL-06, art. 34.9 ET).
 *
 * ## Devuelve un fichero, no JSON
 *
 * Es lo que exige RL-06: «formato tabular legible y tratable, no propietario».
 * El controlador no elige el formato —lo hace el escritor, y el requisito que lo
 * gobierna esta escrito ahi— pero si publica en cabeceras lo que el fichero
 * contiene, para que quien lo descarga pueda comprobar que llego entero sin
 * abrirlo.
 *
 * ## Se genera a fichero y despues se envia
 *
 * Deliberado, y el contrato de `LegalExportWriter` lo explica: si la exportacion
 * se transmitiera mientras se genera, las cabeceras saldrian antes de saber si
 * termino y el asiento de `audit_log` se escribiria **despues** de haber
 * entregado los datos —o no se escribiria—. Con el fichero cerrado antes de
 * responder, la garantia de la regla dura 6 se mantiene: si la traza falla, la
 * descarga no ocurre. El coste es un temporal, que se borra al enviarlo, no
 * memoria: el origen recorre un cursor de servidor y el escritor va volcando.
 *
 * ## `no-store`, siempre
 *
 * El cuerpo es una lista nominal de la plantilla con sus horas. Un proxy o un
 * navegador que lo guarde deja una copia del registro horario fuera de todo
 * control de acceso y de retencion.
 *
 * ## GET y no POST
 *
 * Solo lee. Que quede en `audit_log` no lo convierte en una escritura: lo que
 * cambia el registro horario son las correcciones, y aquellas si son `POST`. Un
 * `POST` aqui impediria ademas que el panel enlace la descarga.
 */
final class LegalExportController extends Controller
{
    public function __invoke(
        GenerateLegalExportRequest $request,
        GenerateLegalExport $generate,
        LegalExportTelemetry $telemetry,
    ): BinaryFileResponse {
        $destination = $this->temporaryPath($request->temporaryFileSuffix());

        $export = $telemetry->measure(
            static fn (): LegalExport => $generate->handle($request->toCommand($destination)),
        );

        return response()
            ->download($export->path, $export->filename(), $this->headers($export))
            // `setPrivate()` y no solo la cabecera del array: `BinaryFileResponse`
            // nace **publica** —Laravel la construye con `$public = true`— y
            // `setPublic()` borra el `private` que se le pase. Sin esta linea, el
            // registro horario nominal de la plantilla sale con
            // `Cache-Control: no-store, public`.
            ->setPrivate()
            ->deleteFileAfterSend();
    }

    /**
     * @return array<string, string>
     */
    private function headers(LegalExport $export): array
    {
        return [
            // `charset=utf-8` ademas del BOM: el BOM es para el programa que abre
            // el fichero descargado y esto para el que lo consuma por HTTP.
            'Content-Type' => 'text/csv; charset=utf-8',
            'Cache-Control' => 'no-store, private',
            // Las cifras que afirma el asiento de `audit_log`, tambien aqui: es
            // lo que permite comprobar que la descarga esta completa sin abrir
            // el fichero, y lo que cuadra el adjunto de un requerimiento con el
            // trail meses despues.
            'X-Kronoqr-Export-Shift-Rows' => (string) $export->tally->shiftEntries,
            'X-Kronoqr-Export-Correction-Rows' => (string) $export->tally->corrections,
        ];
    }

    /**
     * Un temporal por peticion.
     *
     * **El nombre no lleva ningun dato personal** (regla dura 21): el periodo y
     * un aleatorio. El que ve quien descarga es otro —el del manifiesto— y
     * tampoco lo lleva. Vive bajo `storage/framework`, que es del proceso y no
     * se sirve por HTTP: un directorio publico con exportaciones a medio escribir
     * seria una fuga esperando a que alguien adivine un nombre.
     */
    private function temporaryPath(string $suffix): string
    {
        return storage_path(
            'framework/legal-exports/registro-horario-'.$suffix.'-'.Str::random(12).'.csv',
        );
    }
}
