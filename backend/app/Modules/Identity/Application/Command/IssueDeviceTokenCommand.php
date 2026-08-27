<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application\Command;

/**
 * La orden de emitir el token de un quiosco (RF-ID-04).
 *
 * `rotation` no cambia lo que se hace —se emite un token y se retira el
 * anterior— sino **como se lee despues en el trail**: el emparejamiento inicial
 * lo hace una persona y ocurre una vez; la rotacion al 80 % de vida ocurre sola
 * y muchas veces. Confundirlas convertiria `audit_log` en ruido.
 */
final readonly class IssueDeviceTokenCommand
{
    public function __construct(
        public string $deviceUuid,
        public bool $rotation = false,
        public ?int $actorUserId = null,
    ) {}
}
