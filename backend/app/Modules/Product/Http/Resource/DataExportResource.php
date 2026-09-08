<?php

declare(strict_types=1);

namespace App\Modules\Product\Http\Resource;

use App\Modules\Product\Domain\Model\DataExport;
use App\Modules\Shared\Domain\ValueObject\UtcInstant;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Serializa una exportacion integra: el esquema `DataExportResource` del
 * contrato, que envuelve un `DataExport` en `data` (**RF-PD-14**, RL-20).
 *
 * ## Una clase y un metodo estatico, y no dos clases
 *
 * La misma fila aparece en tres sitios con dos formas: envuelta en `data` en el
 * `202` del `POST`, y **desnuda** dentro de `DataExportCollection` y dentro del
 * `export` del `409`. {@see self::payload()} es la forma desnuda y
 * {@see self::toArray()} la envuelve. Con dos clases habria dos sitios que
 * tienen que decir lo mismo, y el dia que divergieran el panel recibiria una
 * fila con un campo de mas en un sitio y de menos en otro.
 *
 * ## Lo que NO sale de aqui
 *
 * **`file_path`.** La ruta absoluta del ZIP en el servidor no le sirve de nada a
 * un navegador —descarga por `uuid`— y describe la topologia de la maquina del
 * cliente. Que no este es ademas lo que impide que el panel intente componer una
 * URL con ella.
 *
 * **El identificador interno.** Como en el resto de la API (doc 01 §5.5).
 *
 * ## `requested_by` lleva nombre, y es la misma excepcion que la concesion de soporte
 *
 * Es el nombre de una **cuenta de gestion del cliente**, en una respuesta que
 * solo lee el cliente, y sin el la pantalla no responde «¿quien se llevo una
 * copia de todo?» — que es justo lo que RS-05 obliga a poder contestar. Al
 * asiento de `audit_log` y al paquete de diagnostico no va (regla dura 21).
 *
 * `null` cuando la pidio la consola, que es lo que el contrato declara.
 *
 * @property-read DataExport $resource
 */
final class DataExportResource extends JsonResource
{
    public static $wrap = null;

    /**
     * La fila **desnuda**: el esquema `DataExport` del contrato.
     *
     * @return array<string, mixed>
     */
    public static function payload(DataExport $export): array
    {
        return [
            'uuid' => $export->uuid,
            'status' => $export->status->value,
            'requested_via' => $export->requestedVia->value,
            'requested_by' => $export->requestedByUuid === null ? null : [
                'uuid' => $export->requestedByUuid,
                'name' => $export->requestedByName ?? '',
            ],
            'requested_at' => UtcInstant::of($export->requestedAt),
            'started_at' => UtcInstant::format($export->startedAt),
            'completed_at' => UtcInstant::format($export->completedAt),
            'failed_at' => UtcInstant::format($export->failedAt),
            'failure_reason' => $export->failureReason?->value,
            'file_name' => $export->fileName,
            'size_bytes' => $export->sizeBytes,
            'sha256' => $export->sha256,
            /*
             * `(object)` y no el array: `row_counts` es un MAPA, y un array
             * vacio de PHP se serializa como `[]`, que el esquema —`type:
             * object`— rechaza. Sin esto, la respuesta dejaria de cumplir el
             * contrato exactamente en la primera exportacion de una instalacion
             * nueva, que es la que nadie prueba a mano.
             */
            'row_counts' => (object) $export->rowCounts,
            'expires_at' => UtcInstant::format($export->expiresAt),
            'purged_at' => UtcInstant::format($export->purgedAt),
            'downloaded_at' => UtcInstant::format($export->downloadedAt),
            'download_count' => $export->downloadCount,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var DataExport $export */
        $export = $this->resource;

        return ['data' => self::payload($export)];
    }
}
