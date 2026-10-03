<?php

declare(strict_types=1);

namespace App\Modules\Attendance\Http\Resource;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * El acuse de `POST /api/v1/scan/discarded` (esquema `DiscardedScanReceipt`,
 * RN-22, ADR-047).
 *
 * **Solo los `scan_id`**: ni si se atribuyo cada aviso ni si abrira incidencia.
 * Es lo que impide que el aviso sea un oraculo de validez de tarjetas o de
 * codigos de empleado (RS-03).
 *
 * @property-read list<string> $resource
 */
final class DiscardedScanReceiptResource extends JsonResource
{
    public static $wrap = null;

    /**
     * @return array{acknowledged: list<string>}
     */
    public function toArray(Request $request): array
    {
        /** @var list<string> $acknowledged */
        $acknowledged = $this->resource;

        return ['acknowledged' => $acknowledged];
    }
}
