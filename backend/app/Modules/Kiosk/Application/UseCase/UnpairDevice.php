<?php

declare(strict_types=1);

namespace App\Modules\Kiosk\Application\UseCase;

use App\Modules\Identity\Application\Command\RevokeDeviceTokenCommand;
use App\Modules\Identity\Application\UseCase\RevokeDeviceToken;
use App\Modules\Kiosk\Application\Command\UnpairDeviceCommand;
use App\Modules\Kiosk\Application\Port\DeviceRegistry;
use App\Modules\Kiosk\Domain\ValueObject\DeviceSummary;

/**
 * Retira un quiosco de servicio (`POST /api/v1/devices/{uuid}/unpair`,
 * **RF-PD-06**).
 *
 * ## Que hace exactamente
 *
 * Le pide a `Identity` que revoque el token y desactive la fila
 * (`deactivate: true`, motivo `unpaired`). A partir de ahi, `/scan`,
 * `/kiosk/roster` y `/kiosk/heartbeat` responden `401` en la peticion siguiente
 * —no en 90 dias—, porque el guard comprueba el estado del dispositivo en cada
 * peticion autenticada. Es la respuesta a una tablet robada, y por eso no puede
 * depender de que caduque nada.
 *
 * ## Nada se borra (regla dura 5)
 *
 * La fila se queda con su nombre y su historial: los fichajes que ese quiosco
 * registro siguen existiendo y tienen que poder atribuirse a algo. Y el nombre
 * queda **reservado** para la tablet que lo sustituya, que reactivara esta misma
 * fila con este mismo `uuid` (ADR-028).
 *
 * ## Idempotente
 *
 * Desvincular un quiosco ya revocado devuelve el mismo cuerpo. `RevokeDeviceToken`
 * borra los tokens —que ya no hay— y vuelve a marcar el estado, y publica su
 * evento: el asiento repetido es el precio de no tener que consultar antes para
 * decidir, y dice la verdad —alguien volvio a pulsar el boton— en lugar de callar.
 * La segunda pulsacion de un boton no puede ser un error para quien la da.
 *
 * ## Llama a `Identity` por su caso de uso publico
 *
 * Igual que {@see ClaimPairing} y por lo mismo (doc 01 §5.5, doc 02 §1.6): la
 * arista `Kiosk/Application -> Identity/Application` esta concedida y no hace
 * falta invertir nada.
 *
 * ## La auditoria ya esta escrita, y por eso aqui no se publica nada
 *
 * `RevokeDeviceToken` publica `DeviceTokenRevoked` y `Compliance` lo sella como
 * `device.revoked` con el motivo `unpaired` y el actor. Publicar ademas un evento
 * propio daria dos asientos del mismo hecho, y quien lea el trail tendria que
 * saber que son el mismo (regla dura 6, sin ruido).
 *
 * ## La purga del padron cacheado ocurre en la tablet
 *
 * Doc 01 §8.1 y RL-12. El servidor no puede borrar nada de un dispositivo al que
 * ya no le habla: lo que hace es dejar de reconocerlo, y la PWA purga su padron y
 * su token al recibir el `401` persistente. **Lo que no purga nunca es la cola
 * offline pendiente**, porque ahi hay jornadas de personas (regla dura 19).
 */
final readonly class UnpairDevice
{
    public function __construct(
        private DeviceRegistry $devices,
        private RevokeDeviceToken $tokens,
    ) {}

    /**
     * @return DeviceSummary|null El quiosco **ya revocado**, para que el panel
     *                            repinte la fila sin volver a pedir la lista.
     *                            `null` si el `uuid` no existe: es el `404` del
     *                            contrato.
     */
    public function handle(UnpairDeviceCommand $command): ?DeviceSummary
    {
        if (! $this->devices->findByUuid($command->deviceUuid) instanceof DeviceSummary) {
            return null;
        }

        $this->tokens->handle(new RevokeDeviceTokenCommand(
            deviceUuid: $command->deviceUuid,
            reason: 'unpaired',
            deactivate: true,
            actorUserId: $command->actorUserId,
        ));

        // Se vuelve a leer para devolver el estado de DESPUES. Con la lectura de
        // arriba, la respuesta diria `active` sobre un quiosco que acaba de
        // dejar de serlo, y el panel pintaria la fila al reves.
        return $this->devices->findByUuid($command->deviceUuid);
    }
}
