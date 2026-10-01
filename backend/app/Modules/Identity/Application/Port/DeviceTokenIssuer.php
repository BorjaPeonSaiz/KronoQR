<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application\Port;

use App\Modules\Identity\Domain\Model\Device;
use App\Modules\Identity\Domain\ValueObject\DeviceTokenRecord;
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
     * Emite un token para el dispositivo y **retira todos los anteriores**. Es
     * el emparejamiento: una tablet emparejada dos veces no conserva el token de
     * la primera.
     *
     * Devuelve el valor en claro, que es la unica vez que existe.
     */
    public function issueFor(Device $device, DateTimeImmutable $issuedAt, DateTimeImmutable $expiresAt): IssuedAccessToken;

    /**
     * Emite un token **sin retirar los que ya tiene** el dispositivo: el relevo
     * de una rotacion (ADR-044). Quien llama decide que pasa con los demas, con
     * {@see shortenExpiry()} y {@see retire()}, en la misma transaccion.
     *
     * Devuelve el valor en claro, que es la unica vez que existe.
     */
    public function issueAlongside(Device $device, DateTimeImmutable $issuedAt, DateTimeImmutable $expiresAt): IssuedAccessToken;

    /**
     * Retira todos los tokens del dispositivo. Es lo que hace efectiva una
     * revocacion sin esperar a los 90 dias, **sin solape** (RS-04).
     */
    public function revokeAllFor(Device $device): void;

    /**
     * Los tokens vivos del dispositivo, **con la fila del dispositivo bloqueada**
     * hasta el final de la transaccion en curso.
     *
     * El bloqueo es lo que impide que dos latidos simultaneos con el mismo
     * token emitan dos relevos: el segundo espera y ve ya el primero.
     *
     * @return list<DeviceTokenRecord>
     */
    public function lockedTokensOf(Device $device): array;

    /**
     * Adelanta la caducidad de un token del dispositivo. Nunca la retrasa: si
     * `until` es posterior a la que tiene, no cambia nada.
     */
    public function shortenExpiry(Device $device, int $tokenId, DateTimeImmutable $until): void;

    /**
     * Retira ya unos tokens concretos del dispositivo.
     *
     * @param  list<int>  $tokenIds
     */
    public function retire(Device $device, array $tokenIds): void;
}
