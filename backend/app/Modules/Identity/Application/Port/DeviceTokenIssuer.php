<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application\Port;

use App\Modules\Identity\Domain\Model\Device;
use App\Modules\Identity\Domain\ValueObject\DeviceTokenLifetime;
use App\Modules\Identity\Domain\ValueObject\IssuedAccessToken;
use DateTimeImmutable;

/**
 * Emision y retirada del token de un quiosco (RF-ID-04, RS-04, doc 02 §7.3).
 *
 * **Es un puerto distinto del de las sesiones de gestion** ({@see AccessTokenIssuer})
 * y no por simetria: son dos poblaciones con reglas opuestas. La sesion de panel
 * cuelga de una persona, dura horas y se cierra al salir; el token de quiosco
 * cuelga de una tablet, dura 90 dias, se renueva solo al 80 % de vida y lleva
 * exactamente tres ambitos —`scan:write`, `roster:read`, `heartbeat:write`— y
 * ninguno mas. Un solo emisor obligaria a elegir cual de las dos reglas se
 * rompe.
 *
 * *«Un token de quiosco comprometido no da acceso a la plantilla completa»*
 * (§7.3): esa promesa la sostiene la lista de ambitos que este emisor concede, y
 * es lo que comprueba la prueba de RS-04.
 */
interface DeviceTokenIssuer
{
    /**
     * Emite un token para el dispositivo y **retira el anterior**: una tablet
     * tiene un token, no una coleccion.
     *
     * Devuelve el valor en claro, que es la unica vez que existe.
     */
    public function issueFor(Device $device, DateTimeImmutable $issuedAt, DateTimeImmutable $expiresAt): IssuedAccessToken;

    /**
     * Retira todos los tokens del dispositivo. Es lo que hace efectiva una
     * revocacion sin esperar a los 90 dias.
     */
    public function revokeAllFor(Device $device): void;

    /**
     * Vida del token vigente del dispositivo, o `null` si no tiene ninguno.
     *
     * Es lo que la tarea 1.13 consultara en cada latido para decidir si toca
     * rotar (§7.3).
     */
    public function currentLifetime(Device $device, float $rotationThreshold): ?DeviceTokenLifetime;
}
