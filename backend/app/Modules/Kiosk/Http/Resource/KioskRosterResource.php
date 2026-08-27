<?php

declare(strict_types=1);

namespace App\Modules\Kiosk\Http\Resource;

use App\Modules\Kiosk\Application\Query\KioskRoster;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Serializa el `200` de `GET /api/v1/kiosk/roster`: el esquema `KioskRoster`.
 *
 * **Dos campos por entrada y ni uno mas** (§7.3, regla dura 21). No hay
 * `employee_uuid`, ni codigo de empleado, ni departamento, ni situacion laboral,
 * ni fechas. La clave interna del empleado —que si viaja dentro del servidor, en
 * `RosterMember`— se quedo en el caso de uso: lo que sale de aqui no tiene ningun
 * identificador secuencial, porque un identificador secuencial en una respuesta
 * dice cuanta gente hay y en que orden entro.
 *
 * El contrato lo blinda con `additionalProperties: false` y hay una prueba de
 * contrato que enumera los campos permitidos: si alguien añade uno «solo para
 * depurar», falla la suite antes de que llegue a una tablet.
 *
 * @property-read KioskRoster $resource
 */
final class KioskRosterResource extends JsonResource
{
    public static $wrap = null;

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var KioskRoster $roster */
        $roster = $this->resource;

        $entries = [];

        foreach ($roster->entries as $entry) {
            $entries[] = [
                'token_hash' => $entry->tokenHash,
                'display_name' => $entry->displayName,
            ];
        }

        return [
            'generated_at' => $roster->generatedAt->format('Y-m-d\TH:i:s.v\Z'),
            'entries' => $entries,
        ];
    }
}
