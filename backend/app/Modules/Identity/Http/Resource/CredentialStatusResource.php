<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Resource;

use App\Modules\Identity\Application\UseCase\CredentialStatusReport;
use App\Modules\Identity\Application\UseCase\CredentialStatusRow;
use App\Modules\Identity\Application\UseCase\CredentialView;
use App\Modules\Identity\Domain\ValueObject\SiteCredentialCoverage;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Serializacion del esquema `CredentialStatusBoard` del contrato (RF-QR-08).
 *
 * **Envuelve el informe del caso de uso y nunca modelos Eloquent.** Es lo que
 * hace imposible que `secret_hash` acabe en una respuesta el dia que alguien
 * anada un campo: no esta en el objeto que se serializa.
 *
 * **`summary` no se recalcula aqui.** Llega hecho del caso de uso, con **todos**
 * los centros del alcance aunque el filtro haya dejado la tabla vacia. Contarlo
 * en el `Resource` daria el recuento de las filas devueltas, que es justo el
 * numero que no sirve: «faltan 3 de 3».
 *
 * @property-read CredentialStatusReport $resource
 */
final class CredentialStatusResource extends JsonResource
{
    public static $wrap = null;

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var CredentialStatusReport $report */
        $report = $this->resource;

        return [
            'data' => array_map(self::row(...), $report->rows),
            'summary' => array_map(self::coverage(...), $report->coverage),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function row(CredentialStatusRow $row): array
    {
        return [
            'employee_uuid' => $row->employee->employeeUuid,
            'employee_code' => $row->employee->employeeCode,
            'full_name' => $row->employee->fullName,
            'site_id' => $row->employee->siteId,
            'site_name' => $row->employee->siteName,
            'department_name' => $row->employee->departmentName,
            'status' => $row->status->value,
            // Se reutiliza `CredentialResource` en lugar de repetir el mapeo:
            // el esquema `Credential` del contrato se serializa en un solo sitio
            // y no puede divergir entre el panel y los demas endpoints.
            'credential' => $row->credential === null
                ? null
                : (new CredentialResource(new CredentialView($row->credential, $row->employee->employeeUuid)))
                    ->toArray(request()),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function coverage(SiteCredentialCoverage $site): array
    {
        return [
            'site_id' => $site->siteId,
            'site_name' => $site->siteName,
            'employees' => $site->employees,
            'pending_print' => $site->pendingPrint,
            'without_delivered_credential' => $site->withoutDeliveredCredential,
        ];
    }
}
