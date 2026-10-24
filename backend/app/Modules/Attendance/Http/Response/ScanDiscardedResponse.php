<?php

declare(strict_types=1);

namespace App\Modules\Attendance\Http\Response;

use App\Modules\Attendance\Application\UseCase\UnprocessableScanGuard;
use Illuminate\Http\JsonResponse;

/**
 * **El servidor ha descartado este fichaje tras no conseguir procesarlo**
 * (esquema `ScanDiscarded`, RN-22, ADR-061).
 *
 * Es un desenlace terminal y **no es un rechazo**: el servidor no llego a
 * decidir sobre la credencial. Por eso tiene su propio `type` —el log, el panel,
 * las pruebas y el quiosco nuevo tienen que distinguirlo de `scan-rejected`— y
 * por eso es `422`: el quiosco de la 2.2.x saca de la cola todo `200` o `422` y
 * reintentaria para siempre cualquier otro codigo (ADR-061 §4).
 *
 * **Texto fijo, como el rechazo y por lo mismo**: no dice por que fallo, ni si
 * la tarjeta era de alguien (RS-03). El quiosco muestra su texto de i18n a
 * partir de `type`. Sale por las tres rutas de escritura del fichaje, siempre
 * con este mismo cuerpo, tambien en cada reenvio (regla dura 8).
 */
final class ScanDiscardedResponse
{
    public const string TYPE = UnprocessableScanGuard::PROBLEM_TYPE;

    public const string TITLE = 'Escaneo descartado';

    public const string DETAIL = 'El escaneo no se ha podido procesar y queda pendiente de revision.';

    /**
     * @return array{type: string, title: string, status: int, detail: string, scan_id: string}
     */
    public static function body(string $scanId): array
    {
        return [
            'type' => self::TYPE,
            'title' => self::TITLE,
            'status' => UnprocessableScanGuard::HTTP_STATUS,
            'detail' => self::DETAIL,
            'scan_id' => $scanId,
        ];
    }

    public static function for(string $scanId): JsonResponse
    {
        return new JsonResponse(
            self::body($scanId),
            UnprocessableScanGuard::HTTP_STATUS,
            // El tipo de medio es parte del contrato, como en el rechazo.
            ['Content-Type' => 'application/problem+json'],
        );
    }
}
