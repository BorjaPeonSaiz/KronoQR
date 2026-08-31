<?php

declare(strict_types=1);

namespace App\Modules\Product\Http\Resource;

use App\Modules\Product\Domain\ValueObject\ComplianceProfileSnapshot;
use DateTimeInterface;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Serializa el `200` de `GET` y de `PATCH /api/v1/compliance-profile`: el
 * esquema `ComplianceProfile` del contrato (RF-PD-07).
 *
 * ## Los dos endpoints devuelven lo mismo, y entero
 *
 * El `PATCH` responde el perfil completo y no solo lo que cambio, con el mismo
 * criterio que la configuracion de instalacion: asi el panel no recompone el
 * estado a partir de lo que envio —que es donde aparecen las pantallas que
 * enseñan un valor y guardan otro— y lo que se pinta es exactamente lo que quedo
 * escrito.
 *
 * ## `source` viaja aunque no sea editable
 *
 * Dice si el perfil es el del centro o el de la instalacion. No es decorativo:
 * un centro sin perfil asignado hereda los cambios del perfil por defecto y uno
 * con perfil propio no, y quien edita necesita saber cual de las dos cosas esta
 * tocando.
 *
 * ## Horas enteras, como en el contrato
 *
 * La conversion a minutos es cosa del adaptador que sirve al nucleo. Aqui se
 * responde en la unidad en la que se escribe el convenio.
 *
 * @property-read ComplianceProfileSnapshot $resource
 */
final class ComplianceProfileResource extends JsonResource
{
    public static $wrap = null;

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $profile = $this->resource;

        return [
            'data' => [
                'id' => $profile->id,
                'name' => $profile->name,
                'jurisdiction' => $profile->jurisdiction,
                'min_rest_hours' => $profile->minRestHours,
                'max_daily_hours' => $profile->maxDailyHours,
                'max_weekly_hours' => $profile->maxWeeklyHours,
                'break_required_after_hours' => $profile->breakRequiredAfterHours,
                'week_starts_on' => $profile->weekStartsOn,
                'holiday_calendar' => $profile->holidayCalendar,
                'retention_years' => $profile->retentionYears,
                'is_default' => $profile->isDefault,
                'source' => $profile->source->value,
                // `null` significa «tal como se instalo»: nadie lo ha tocado.
                // El «quien» esta en `audit_log`, que es donde tiene valor
                // probatorio; aqui solo interesa si el perfil sigue siendo el de
                // serie.
                'updated_at' => $profile->updatedAt?->format(DateTimeInterface::RFC3339_EXTENDED),
            ],
        ];
    }
}
