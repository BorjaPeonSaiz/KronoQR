<?php

declare(strict_types=1);

namespace App\Modules\Attendance\Http\Response;

use Illuminate\Http\JsonResponse;

/**
 * **El escaneo no se ha podido procesar y sigue pendiente** (esquema
 * `ScanNotProcessed`, tarea 1.7).
 *
 * No es un rechazo y la diferencia es la de perder o no perder una jornada. Un
 * rechazo dice «esta tarjeta no vale» y el quiosco saca el elemento de la cola
 * para siempre; esto dice «no he llegado a mirarla», y el quiosco **tiene que
 * conservarlo y reintentarlo** (RF-KI-04, regla dura 19).
 *
 * Solo aparece dentro de `POST /api/v1/scan/batch`. En el endpoint individual un
 * fallo asi es un `500` y el quiosco reintenta la peticion entera; en un lote no
 * se puede, porque los otros cuarenta y nueve elementos si se procesaron y
 * repetirlos seria pedirle al servidor que vuelva a decidir sobre fichajes que ya
 * estan escritos.
 *
 * **Texto fijo, como el rechazo y por lo mismo**: la causa concreta —que
 * excepcion, de que tabla, en que consulta— vive en el log del servidor y en
 * `error_events`, nunca en una respuesta que sale por la red.
 */
final class ScanNotProcessedResponse
{
    public const string TYPE = 'urn:kronoqr:problem:scan-not-processed';

    public const string TITLE = 'Escaneo no procesado';

    public const string DETAIL = 'El escaneo no se ha podido procesar. Reintenta mas tarde.';

    /**
     * @return array{type: string, title: string, status: int, detail: string, scan_id: string}
     */
    public static function body(string $scanId): array
    {
        return [
            'type' => self::TYPE,
            'title' => self::TITLE,
            'status' => JsonResponse::HTTP_SERVICE_UNAVAILABLE,
            'detail' => self::DETAIL,
            'scan_id' => $scanId,
        ];
    }
}
