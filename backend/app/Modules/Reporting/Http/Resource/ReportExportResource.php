<?php

declare(strict_types=1);

namespace App\Modules\Reporting\Http\Resource;

use App\Modules\Reporting\Application\Support\IssuedReportExportLink;
use App\Modules\Reporting\Domain\Model\ReportExport;
use App\Modules\Shared\Domain\ValueObject\UtcInstant;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Un informe en diferido, tal y como lo describe el contrato (**RF-IN-06**).
 *
 * ## Lo que NO sale
 *
 * - **`file_path`.** Una ruta del servidor no le sirve de nada a un navegador —la
 *   descarga va por `uuid`— y si le diria a quien no debe donde mirar.
 * - **`download_token_hash`.** Ni la huella: no sirve para nada fuera y publicar
 *   el material de una comparacion de secretos no tiene ninguna ventaja.
 * - **El identificador interno.** Fuera sale el `uuid` (doc 01 §5.5).
 * - **Ningun nombre de empleado.** Lo mas parecido es el `employee_uuid` del
 *   filtro, que es un identificador publico (regla dura 21).
 *
 * ## `download` es el enlace recien acuñado, o `null`
 *
 * Solo lo trae `GET /reports/exports/{uuid}`, que es quien lo emite: la **lista**
 * nunca lleva enlaces. Si los llevara, una sola peticion de lista acuñaria veinte
 * tokens a la vez y los dejaria todos vivos quince minutos — justo lo contrario
 * de lo que ADR-041 persigue.
 *
 * `null` cuando la exportacion no esta `completed`, cuando su fichero ya caduco o
 * cuando se purgo. La pantalla enseña el estado y sigue sondeando.
 *
 * ## Los criterios van dentro
 *
 * Porque el fichero de **nomina** no los lleva (decision 5): una fila de
 * comentario rompe la importacion del programa de nomina. Aqui es donde la
 * pantalla los puede enseñar junto a la descarga, que es lo que exige el paso 1
 * de `/informe-nuevo`: los criterios visibles para quien lo lee.
 */
final class ReportExportResource extends JsonResource
{
    /** El envoltorio `data` se escribe a mano en {@see self::toArray()}, como en el resto del producto. */
    public static $wrap = null;

    public function __construct(
        ReportExport $export,
        /** El enlace recien emitido, si lo hay. Ver el docblock. */
        private readonly ?IssuedReportExportLink $link = null,
    ) {
        parent::__construct($export);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var ReportExport $export */
        $export = $this->resource;

        return [
            'data' => [
                ...self::payload($export),
                'download' => $this->downloadPayload(),
            ],
        ];
    }

    /**
     * La fila sin el enlace.
     *
     * Publico y estatico porque lo reutilizan la coleccion y el cuerpo del `409`:
     * con tres serializaciones distintas de la misma fila, el dia que cambiara un
     * campo el panel veria tres formas del mismo objeto.
     *
     * @return array<string, mixed>
     */
    public static function payload(ReportExport $export): array
    {
        return [
            'uuid' => $export->uuid,
            'kind' => $export->kind->value,
            'format' => $export->format,
            'status' => $export->status->value,
            'parameters' => $export->parameters->toArray(),
            /*
             * `all` o `departments`, nunca la lista de identificadores: el panel
             * solo necesita saber si el informe abarcaba la instalacion entera.
             *
             * **Nulo en las purgadas** (RL-11): al borrarse el fichero, la fila
             * pierde el alcance junto con los dos filtros que señalan a personas.
             * El contrato lo declara nulable por eso.
             */
            'scope' => $export->scope === null
                ? null
                : ($export->scope->isUnrestricted() ? 'all' : 'departments'),
            'requested_by' => $export->requestedByUuid === null ? null : [
                'uuid' => $export->requestedByUuid,
                'name' => $export->requestedByName ?? '',
            ],
            'requested_at' => UtcInstant::format($export->requestedAt),
            'started_at' => UtcInstant::format($export->startedAt),
            'completed_at' => UtcInstant::format($export->completedAt),
            'failed_at' => UtcInstant::format($export->failedAt),
            'failure_reason' => $export->failureReason?->value,
            'file_name' => $export->fileName,
            'size_bytes' => $export->sizeBytes,
            'sha256' => $export->sha256,
            'row_count' => $export->rowCount,
            'criteria' => $export->criteria,
            'expires_at' => UtcInstant::format($export->expiresAt),
            'purged_at' => UtcInstant::format($export->purgedAt),
            'downloaded_at' => UtcInstant::format($export->downloadedAt),
            'download_count' => $export->downloadCount,
            'notified_at' => UtcInstant::format($export->notifiedAt),
            'notification_channel' => $export->notificationChannel?->value,
        ];
    }

    /**
     * @return array<string, string>|null
     */
    private function downloadPayload(): ?array
    {
        if ($this->link === null || $this->link->token === null || $this->link->expiresAt === null) {
            return null;
        }

        /** @var ReportExport $export */
        $export = $this->resource;

        return [
            // Relativa a la API y no absoluta: el panel de cada cliente vive en un
            // dominio distinto (ADR-016, ADR-017), y una URL absoluta compuesta
            // aqui seria la del servidor, no la que el navegador esta usando.
            'url' => '/api/v1/reports/exports/'.$export->uuid.'/download?token='.$this->link->token,
            'expires_at' => UtcInstant::format($this->link->expiresAt) ?? '',
        ];
    }
}
