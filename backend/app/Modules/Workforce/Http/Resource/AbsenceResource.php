<?php

declare(strict_types=1);

namespace App\Modules\Workforce\Http\Resource;

use App\Modules\Workforce\Application\Port\AbsenceView;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Serializacion del esquema `Absence` del contrato (**RF-GP-04**).
 *
 * ## La nota se OMITE, no se pone a `null`
 *
 * Para quien no tiene potestad de escribir ausencias —hoy el
 * `responsable_departamento`— el campo **no aparece en el objeto**. La diferencia
 * importa: `null` significa «no hay nota» y lo cierto es «no te corresponde».
 * Una baja medica es dato de salud (regla dura 21) y la nota puede llevar un
 * diagnostico que nadie deberia escribir ahi pero que alguien escribira.
 *
 * El contrato lo declara igual: `note` no esta en `required` de `Absence`.
 *
 * **La decision la toma la policy, no este fichero.** Aqui llega ya resuelta en
 * `$includesNote`, porque escrita como un `if` sobre roles seria invisible desde
 * la matriz de autorizacion y nadie la probaria en negativo (regla dura 18).
 *
 * ## `days` se deriva y no se guarda
 *
 * Sale de las dos fechas. Una columna aparte podria quedarse desincronizada, y
 * el numero que cuenta para el informe es el que se deriva.
 *
 * @property-read AbsenceView $resource
 */
final class AbsenceResource extends JsonResource
{
    public static $wrap = null;

    public function __construct(AbsenceView $view, private readonly bool $includesNote)
    {
        parent::__construct($view);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var AbsenceView $view */
        $view = $this->resource;
        $absence = $view->absence;

        return [
            'uuid' => $absence->uuid,
            'employee_uuid' => $absence->employeeUuid,
            'employee_code' => $view->employeeCode,
            // Solo para pintar la fila. No baja de aqui (regla dura 21).
            'employee_name' => $view->employeeName,
            'department_id' => $view->departmentId,
            'department_name' => $view->departmentName,
            'type' => $absence->type->value,
            'starts_on' => $absence->isoStartsOn(),
            'ends_on' => $absence->isoEndsOn(),
            'days' => $absence->days(),
            // La clave NO EXISTE cuando no corresponde. Ver el docblock.
            ...($this->includesNote ? ['note' => $absence->note] : []),
            'status' => $absence->status->value,
            'version' => $absence->version,
            'supersedes_uuid' => $absence->supersedesUuid,
            'superseded_by_uuid' => $absence->supersededByUuid,
            'change_reason' => $absence->changeReason,
            // En UTC con microsegundos, como el resto de instantes de la API
            // (regla dura 3). La conversion a la zona del centro es del cliente.
            'voided_at' => $absence->voidedAt?->format('Y-m-d\TH:i:s.u\Z'),
            'void_reason' => $absence->voidReason,
            'created_at' => $view->createdAt->format('Y-m-d\TH:i:s.u\Z'),
        ];
    }
}
