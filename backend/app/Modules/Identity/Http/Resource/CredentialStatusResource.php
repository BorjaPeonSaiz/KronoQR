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
 * **`summary` no se recalcula aqui.** Llega hecho del caso de uso, aunque el
 * filtro haya dejado la tabla vacia. Contarlo en el `Resource` daria el
 * recuento de las filas devueltas, que es justo el numero que no sirve:
 * «faltan 3 de 3».
 *
 * **El centro no sale** (ADR-040): es el de la instalacion y el cliente ya lo
 * conoce por `GET /api/v1/site`. El recuento interno lo lleva para etiquetar
 * las metricas, y se queda ahi.
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
            'summary' => self::coverage($report->coverage),
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
     * @return array<string, int|string|null>
     */
    private static function coverage(SiteCredentialCoverage $coverage): array
    {
        return [
            'employees' => $coverage->employees,
            'pending_print' => $coverage->pendingPrint,
            'without_delivered_credential' => $coverage->withoutDeliveredCredential,
            // El avance de una rotacion de clave (RF-QR-07). `retiring_key_id`
            // sale del llavero y no de la peticion, y **no es un secreto**: va
            // impreso en el QR de cada tarjeta (ADR-005). Lo que no sale por
            // aqui —ni por ningun sitio— es la clave.
            'retiring_key_id' => $coverage->retiringKeyId,
            'pending_reprint' => $coverage->pendingReprint,
            // **El unico numero de este resumen que describe una averia** y el
            // unico que NO se acota con `employee_uuid`: es de la instalacion
            // entera, a proposito. Acotarlo lo pondria a cero justo cuando
            // alguien abre la ficha de la persona que no puede fichar, que es
            // el momento en el que hace falta verlo (revision de seguridad de
            // la 2.12). El desglose por clave se queda en la metrica y en la
            // consola: el panel solo tiene que dar la voz de alarma.
            'active_unknown_key' => $coverage->unknownKeyCardsTotal(),
        ];
    }
}
