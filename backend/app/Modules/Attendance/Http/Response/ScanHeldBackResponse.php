<?php

declare(strict_types=1);

namespace App\Modules\Attendance\Http\Response;

use Illuminate\Http\JsonResponse;

/**
 * **El escaneo no se ha mirado porque uno anterior del lote quedo sin procesar**
 * (esquema `ScanHeldBack`, RN-21, ADR-047).
 *
 * Tampoco es un rechazo: el servidor no ha decidido nada sobre el, a proposito,
 * para no registrarlo antes que otro anterior que todavia no tiene desenlace.
 * Para el quiosco es lo mismo que {@see ScanNotProcessedResponse} —`503`,
 * conservar, reintentar—; es un esquema aparte para que el log, el panel y las
 * pruebas distingan el elemento que fallo de los que se arrastraron.
 *
 * **Texto fijo**: no dice cual de los anteriores fallo ni por que.
 */
final class ScanHeldBackResponse
{
    public const string TYPE = 'urn:kronoqr:problem:scan-held-back';

    public const string TITLE = 'Escaneo aplazado';

    public const string DETAIL = 'El escaneo no se ha procesado porque uno anterior del lote sigue pendiente. Reintenta mas tarde.';

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
