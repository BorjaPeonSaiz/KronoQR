<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Resource;

use App\Modules\Shared\Domain\ValueObject\PortalSession;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Serializacion del esquema `PortalSession`: la sesion del portal del empleado
 * (RF-ID-05, RF-ID-07).
 *
 * Es, junto con `SessionResource`, uno de los dos unicos sitios del sistema
 * donde un token viaja en claro, y por eso no se registra en ningun log: `token`
 * no aparece en la traza, ni en el log de acceso, ni en un mensaje de error.
 *
 * **No enumera los ambitos, al contrario que `SessionResource`.** El panel los
 * necesita para decidir que menus enseña; el portal no tiene nada que decidir,
 * porque solo hay tres rutas y las tres son suyas. Publicar la lista no aportaria
 * nada y añadiria un sitio mas donde el ambito se escribe.
 *
 * **El nombre completo si sale, y solo aqui.** Es el de quien acaba de teclear su
 * propio PIN: la regla dura 21 prohibe nombres en **logs tecnicos**, no en la
 * respuesta que una persona recibe sobre si misma.
 *
 * @property-read PortalSession $resource
 */
final class PortalSessionResource extends JsonResource
{
    public static $wrap = null;

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var PortalSession $session */
        $session = $this->resource;

        return [
            'token' => $session->plainTextToken,
            'token_type' => 'Bearer',
            // Regla dura 3: en UTC con sufijo Z, como todo instante del contrato.
            'expires_at' => $session->expiresAt->format('Y-m-d\TH:i:s\Z'),
            'employee' => [
                'uuid' => $session->employeeUuid,
                'display_name' => $session->displayName,
                'employee_code' => $session->employeeCode,
                'locale' => $session->locale,
                // La zona del CENTRO, no la del navegador (regla dura 3, RN-04).
                // Que viaje aqui es lo que evita que la interfaz la adivine.
                'time_zone' => $session->timeZone,
            ],
        ];
    }
}
