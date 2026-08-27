<?php

declare(strict_types=1);

namespace App\Modules\Kiosk\Http\Resource;

use App\Modules\Kiosk\Application\Query\KioskRoster;
use App\Modules\Shared\Application\Port\SealedPinOpener;
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
 * ## Por que la clave publica del PIN sale por aqui
 *
 * `pin_sealing_public_key` no es un dato de nadie: es la mitad publica del par de
 * claves de la instalacion, y con ella el quiosco **cierra** el PIN que teclea el
 * empleado antes de encolarlo (RF-AT-11, RL-12). Va en esta respuesta y no en un
 * endpoint propio porque es exactamente lo mismo que el padron —lo que la tablet
 * necesita para poder trabajar **sin red**— y porque asi se refresca en el mismo
 * ciclo: una rotacion de la clave llega al quiosco con la siguiente descarga del
 * padron, sin un segundo mecanismo que mantener.
 *
 * **Nula significa «esta instalacion no ofrece fichaje por PIN»** (ADR-017). No
 * es un error: el quiosco oculta el teclado en vez de ofrecer una puerta que
 * rechaza siempre.
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
            // Se resuelve del contenedor y no se inyecta en el constructor
            // porque un `JsonResource` lo construye Laravel con el recurso como
            // unico argumento; anadirle dependencias obligaria a fabricarlo a
            // mano en cada controlador.
            'pin_sealing_public_key' => app(SealedPinOpener::class)->publicKey(),
        ];
    }
}
