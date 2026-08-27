<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application\UseCase;

use App\Modules\Identity\Application\Command\IssueDeviceTokenCommand;
use App\Modules\Identity\Application\Port\DeviceRepository;
use App\Modules\Identity\Application\Port\DeviceTokenIssuer;
use App\Modules\Identity\Domain\Model\Device;
use App\Modules\Identity\Domain\ValueObject\DeviceTokenLifetime;
use App\Modules\Identity\Domain\ValueObject\IssuedAccessToken;
use App\Modules\Shared\Application\Port\Clock;

/**
 * Rotacion automatica del token de quiosco al 80 % de su vida (RF-ID-04,
 * doc 02 §7.3).
 *
 * **Lo llama el latido, no una tarea programada.** La decision de renovar la
 * toma el servidor cuando la tablet aparece: un `cron` que rotara por su cuenta
 * emitiria un token que el quiosco desconectado nunca llegaria a recibir, y le
 * habria retirado el que si tenia. Con esto, el token nuevo viaja en la misma
 * respuesta que confirma el latido, que es la unica forma de garantizar que
 * llega (RF-KI-04, tarea 1.13).
 *
 * Devuelve `null` cuando **no** toca rotar, que es el caso normal: el llamante
 * simplemente no incluye token nuevo en la respuesta.
 */
final readonly class RotateDeviceTokenIfDue
{
    public function __construct(
        private DeviceRepository $devices,
        private DeviceTokenIssuer $tokens,
        private IssueDeviceToken $issue,
        private Clock $clock,
        /** Fraccion de vida a partir de la cual se rota (§7.3: 0,8). */
        private float $rotationThreshold,
    ) {}

    public function handle(string $deviceUuid): ?IssuedAccessToken
    {
        $device = $this->devices->findByUuid($deviceUuid);

        if (! $device instanceof Device || ! $device->isActive()) {
            return null;
        }

        $lifetime = $this->tokens->currentLifetime($device, $this->rotationThreshold);

        // Sin token vigente no hay nada que rotar: eso es un emparejamiento y lo
        // decide una persona, no el latido.
        if (! $lifetime instanceof DeviceTokenLifetime || ! $lifetime->isRotationDue($this->clock->now())) {
            return null;
        }

        return $this->issue->handle(new IssueDeviceTokenCommand(
            deviceUuid: $deviceUuid,
            rotation: true,
        ));
    }
}
