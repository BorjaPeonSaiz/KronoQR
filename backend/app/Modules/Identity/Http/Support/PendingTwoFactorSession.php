<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Support;

use App\Modules\Shared\Application\Port\ManagementActor;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;
use RuntimeException;

/**
 * Los tres datos que los endpoints de `/auth/2fa/*` leen **del token** y nunca del
 * cuerpo de la peticion: de quien es la sesion pendiente, con que nombre se pidio
 * y cual es el token que hay que consumir al completarla.
 *
 * ## Por que del token y no del cuerpo
 *
 * Es la mitad de la autorizacion que no se ve. Si el `uuid` viajara en el cuerpo,
 * cualquiera con un reto abierto podria presentar su codigo a nombre de otra
 * persona; si viajara el identificador del token, podria revocar el de otro. Aqui
 * no hay nada que manipular: el cliente solo envia seis digitos.
 *
 * Es el mismo criterio con el que el portal del empleado no acepta `uuid` en la
 * ruta y con el que el quiosco no acepta `site_id`: **lo que no se puede expresar
 * no se puede falsificar**.
 *
 * ## El nombre del dispositivo se hereda
 *
 * El token pendiente se emitio con el `device_name` que puso el cliente al pedir
 * entrar, y la sesion definitiva lo hereda de aqui. Asi la sesion se lista y se
 * revoca con el nombre que su dueño reconoce, en lugar de con uno inventado a
 * mitad de camino.
 */
final readonly class PendingTwoFactorSession
{
    private function __construct(
        public string $userUuid,
        public string $deviceName,
        public int|string $tokenId,
    ) {}

    public static function of(Request $request): self
    {
        $actor = $request->user();
        $token = $actor?->currentAccessToken();

        if (! $actor instanceof ManagementActor || ! $token instanceof PersonalAccessToken) {
            // La policy y el middleware `ability` ya han dejado fuera todo lo que
            // no sea una cuenta de gestion con reto abierto. Llegar aqui sin las
            // dos cosas seria una incoherencia del guard, no un caso de negocio.
            throw new RuntimeException('Los endpoints de segundo factor exigen una sesion pendiente.');
        }

        return new self($actor->actorUuid(), $token->name, $token->id);
    }
}
