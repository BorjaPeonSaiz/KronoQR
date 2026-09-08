<?php

declare(strict_types=1);

namespace App\Modules\Product\Http\Resource;

use App\Modules\Product\Domain\Model\SupportGrant;
use App\Modules\Shared\Domain\ValueObject\UtcInstant;
use DateTimeImmutable;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Serializa una concesion: el esquema `SupportGrant` del contrato (**RF-PD-11**).
 *
 * ## `status` se calcula con UN instante para toda la lista
 *
 * Se lo pasa quien construye el recurso, y no lo pide cada fila por su cuenta:
 * en una lista de cien concesiones, dos instantes distintos podrian enseñar una
 * como activa y otra como caducada cuando la frontera cae justo entre las dos.
 *
 * ## Lo que NUNCA sale de aqui
 *
 * **El token, ni entero ni su hash.** El token viaja una sola vez y por
 * {@see IssuedSupportGrantResource}; el hash no sirve para nada fuera del
 * servidor y publicarlo solo daria material a quien quiera comprobar si acerto
 * con uno. Y **no sale ningun dato de empleado**: aqui no los hay.
 *
 * `granted_by.name` si sale, y es la unica excepcion aparente a la regla dura
 * 21: es un nombre de una **cuenta de gestion del cliente**, en una respuesta que
 * solo lee el cliente, y sin el la mitad «visible para el cliente» de RF-PD-11
 * no se cumple —un UUID no responde «¿quien autorizo esto?»—. Al asiento de
 * auditoria y al paquete de diagnostico no va.
 *
 * @property-read SupportGrant $resource
 */
final class SupportGrantResource extends JsonResource
{
    public static $wrap = null;

    public function __construct(SupportGrant $resource, private readonly DateTimeImmutable $asOf)
    {
        parent::__construct($resource);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var SupportGrant $grant */
        $grant = $this->resource;

        return [
            'uuid' => $grant->uuid,
            'status' => $grant->statusAt($this->asOf)->value,
            'scope' => $grant->scope->value,
            'reason' => $grant->reason,
            'granted_by' => [
                'uuid' => $grant->grantedBy->uuid,
                'name' => $grant->grantedBy->name,
            ],
            'granted_at' => self::utc($grant->grantedAt),
            'expires_at' => self::utc($grant->expiresAt),
            'revoked_at' => self::utc($grant->revokedAt()),
            'accessed_at' => self::utc($grant->accessedAt()),
        ];
    }

    /**
     * El formato `UtcTimestamp` del contrato: siempre `Z` (regla dura 3).
     *
     * Se delega en {@see UtcInstant}, que es el unico sitio del producto
     * donde vive esa cadena de formato. Repetirla aqui la habria puesto en dos
     * sitios que tienen que decir lo mismo —y `DateTimeInterface::ATOM`, que es
     * lo que sale por descuido, produce `+00:00` y el validador del contrato lo
     * rechaza con razon—.
     */
    private static function utc(?DateTimeImmutable $instant): ?string
    {
        return UtcInstant::format($instant);
    }
}
