<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application\UseCase;

use App\Modules\Identity\Application\Command\RotateDeviceTokenCommand;
use App\Modules\Identity\Application\Port\DeviceRepository;
use App\Modules\Identity\Application\Port\DeviceTokenIssuer;
use App\Modules\Identity\Application\Port\IdentityEventPublisher;
use App\Modules\Identity\Domain\Event\DeviceTokenIssued;
use App\Modules\Identity\Domain\Model\Device;
use App\Modules\Identity\Domain\Policy\DeviceTokenRotationPolicy;
use App\Modules\Identity\Domain\ValueObject\DeviceTokenRotationOutcome;
use App\Modules\Identity\Domain\ValueObject\IssuedAccessToken;
use App\Modules\Identity\Domain\ValueObject\TokenAbility;
use App\Modules\Shared\Application\Port\Clock;
use Illuminate\Database\ConnectionInterface;

/**
 * Rotacion automatica del token de quiosco al 80 % de su vida, con solape
 * (RF-ID-04, doc 02 §7.3, ADR-044).
 *
 * **Lo llama el latido, no una tarea programada.** La decision de renovar la
 * toma el servidor cuando la tablet aparece: un `cron` que rotara por su cuenta
 * emitiria un token que el quiosco desconectado nunca llegaria a recibir. Con
 * esto, el token nuevo viaja en la misma respuesta que confirma el latido.
 *
 * **Y el anterior no se retira en el acto** —esa era la mitad que faltaba
 * (F1-1)—: entra en solape hasta que el nuevo se use por primera vez o venza el
 * plazo, lo que ocurra antes. Si la respuesta se pierde, la tablet sigue fichando
 * con el viejo y el latido siguiente le reentrega otro relevo. La regla completa
 * vive en {@see DeviceTokenRotationPolicy}; aqui solo se ejecuta.
 *
 * **Una transaccion.** Leer los tokens con la fila del dispositivo bloqueada,
 * decidir, retirar, acortar, emitir y publicar el asiento ocurren juntos: dos
 * latidos simultaneos no emiten dos relevos, y un relevo sin su asiento en
 * `audit_log` no puede existir.
 *
 * Devuelve `null` cuando **no** toca emitir, que es el caso normal: el llamante
 * simplemente no incluye token nuevo en la respuesta.
 */
final readonly class RotateDeviceTokenIfDue
{
    public function __construct(
        private DeviceRepository $devices,
        private DeviceTokenIssuer $tokens,
        private IdentityEventPublisher $events,
        private Clock $clock,
        private ConnectionInterface $connection,
        /** Umbral y solape, ya resueltos de la configuracion (regla dura 14). */
        private DeviceTokenRotationPolicy $policy,
        /** Vida del relevo en dias (§7.3: 90), la misma que la de un emparejamiento. */
        private int $lifetimeDays,
    ) {}

    public function handle(RotateDeviceTokenCommand $command): ?IssuedAccessToken
    {
        $device = $this->devices->findByUuid($command->deviceUuid);

        // Un dispositivo revocado no rota: su token ya no autentica, y si uno
        // llegara hasta aqui entre la autenticacion y la revocacion, la
        // revocacion gana.
        if (! $device instanceof Device || ! $device->isActive()) {
            return null;
        }

        return $this->connection->transaction(function () use ($command, $device): ?IssuedAccessToken {
            $now = $this->clock->now();
            $decision = $this->policy->decide(
                $this->tokens->lockedTokensOf($device),
                $command->presentedTokenId,
                $now,
            );

            if ($decision->retire !== []) {
                $this->tokens->retire($device, $decision->retire);
            }

            if (! $decision->issuesToken()) {
                return null;
            }

            if ($decision->shortensSuperseded() && $decision->supersededTokenId !== null && $decision->supersededUntil !== null) {
                $this->tokens->shortenExpiry($device, $decision->supersededTokenId, $decision->supersededUntil);
            }

            $expiresAt = $now->modify('+'.max(1, $this->lifetimeDays).' days');
            $token = $this->tokens->issueAlongside($device, $now, $expiresAt);

            $this->events->publish(new DeviceTokenIssued(
                deviceId: $device->id,
                deviceUuid: $device->uuid,
                abilities: implode(' ', array_map(
                    static fn (TokenAbility $ability): string => $ability->value,
                    TokenAbility::kioskAbilities(),
                )),
                expiresAt: $expiresAt,
                rotation: true,
                // Nadie la pide: la decide el servidor en un latido.
                actorUserId: null,
                occurredAt: $now,
                supersededUntil: $decision->supersededUntil,
                redelivery: $decision->outcome === DeviceTokenRotationOutcome::REDELIVER,
            ));

            return $token;
        });
    }
}
