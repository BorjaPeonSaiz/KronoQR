<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application\UseCase;

use App\Modules\Identity\Application\Command\IssueDeviceTokenCommand;
use App\Modules\Identity\Application\Port\DeviceRepository;
use App\Modules\Identity\Application\Port\DeviceTokenIssuer;
use App\Modules\Identity\Application\Port\IdentityEventPublisher;
use App\Modules\Identity\Domain\Event\DeviceTokenIssued;
use App\Modules\Identity\Domain\Model\Device;
use App\Modules\Identity\Domain\ValueObject\IssuedAccessToken;
use App\Modules\Identity\Domain\ValueObject\TokenAbility;
use App\Modules\Shared\Application\Port\Clock;
use Illuminate\Database\ConnectionInterface;

/**
 * Emite el token de un quiosco (RF-ID-04, RS-04, doc 02 §7.3).
 *
 * **Tres ambitos y ninguno mas**: `scan:write`, `roster:read` y
 * `heartbeat:write`. Es lo que sostiene la promesa del §7.3 —*«un token de
 * quiosco comprometido no da acceso a la plantilla completa»*—: la tablet cuelga
 * de una pared en una zona de paso y su token es, de todos los del sistema, el
 * mas facil de robar.
 *
 * **90 dias y no una sesion.** Nadie va a escribir una contrasena en la tablet
 * cada manana, y un token corto convertiria cada caducidad en un turno sin
 * fichar. La contrapartida —una vida larga— se compensa con la rotacion
 * automatica al 80 % y con que el dispositivo se puede revocar en el acto.
 *
 * **Emitir retira el token anterior.** Una tablet tiene un token, no una
 * coleccion: si se emparejara dos veces y quedaran los dos vivos, revocar el
 * visible dejaria el otro funcionando.
 */
final readonly class IssueDeviceToken
{
    public function __construct(
        private DeviceRepository $devices,
        private DeviceTokenIssuer $tokens,
        private IdentityEventPublisher $events,
        private Clock $clock,
        private ConnectionInterface $connection,
        /**
         * Vida del token en dias (§7.3: 90).
         *
         * Llega **ya resuelta** desde `IdentityServiceProvider` y no se consulta
         * aqui: es la misma regla que con los umbrales legales (regla dura 14).
         * Un caso de uso que pregunta por la configuracion no se puede probar
         * con dos valores sin tocar la configuracion global.
         */
        private int $lifetimeDays,
    ) {}

    /**
     * Devuelve `null` si el dispositivo no existe o esta revocado. Un
     * dispositivo dado de baja no recupera su token emitiendo otro: primero hay
     * que reactivarlo, que es una decision con nombre y no un efecto colateral.
     */
    public function handle(IssueDeviceTokenCommand $command): ?IssuedAccessToken
    {
        $device = $this->devices->findByUuid($command->deviceUuid);

        if (! $device instanceof Device || ! $device->isActive()) {
            return null;
        }

        $issuedAt = $this->clock->now();
        $expiresAt = $issuedAt->modify('+'.max(1, $this->lifetimeDays).' days');

        return $this->connection->transaction(function () use (
            $command,
            $device,
            $issuedAt,
            $expiresAt,
        ): IssuedAccessToken {
            $token = $this->tokens->issueFor($device, $issuedAt, $expiresAt);

            $this->events->publish(new DeviceTokenIssued(
                deviceId: $device->id,
                deviceUuid: $device->uuid,
                abilities: implode(' ', array_map(
                    static fn (TokenAbility $ability): string => $ability->value,
                    TokenAbility::kioskAbilities(),
                )),
                expiresAt: $expiresAt,
                rotation: $command->rotation,
                actorUserId: $command->actorUserId,
                occurredAt: $issuedAt,
            ));

            return $token;
        });
    }
}
