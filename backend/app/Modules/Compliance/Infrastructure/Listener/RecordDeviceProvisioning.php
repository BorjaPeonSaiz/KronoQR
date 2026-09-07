<?php

declare(strict_types=1);

namespace App\Modules\Compliance\Infrastructure\Listener;

use App\Modules\Compliance\Application\Command\RecordAuditEntryCommand;
use App\Modules\Compliance\Application\UseCase\RecordAuditEntry;
use App\Modules\Compliance\Domain\ValueObject\AuditAction;
use App\Modules\Compliance\Domain\ValueObject\AuditActor;
use App\Modules\Compliance\Domain\ValueObject\AuditPayload;
use App\Modules\Compliance\Domain\ValueObject\AuditSubject;
use App\Modules\Kiosk\Domain\Event\DeviceProvisioned;

/**
 * Sella en `audit_log` el alta —o la reactivacion— de un quiosco por codigo de
 * emparejamiento (**RF-PD-06**, regla dura 6, RL-04,
 * `/revision-cumplimiento` bloque D).
 *
 * ## Por que tiene relevancia legal
 *
 * Porque **dar de alta un quiosco es crear un origen de fichajes**. Todo lo que
 * despues entre por esa tablet acaba en el registro horario, y quien investigue
 * una discrepancia seis meses despues necesita poder ver de donde salio ese
 * origen, quien lo autorizo y cuando. Es la misma familia que un cambio de rol o
 * de umbral de calculo: «¿quien movio las reglas?».
 *
 * ## `device.provisioned`, que ya existia en el catalogo y nadie escribia
 *
 * No se estrena accion. {@see AuditAction::DeviceProvisioned} estaba declarada
 * desde el catalogo inicial precisamente para esto, y es distinta de
 * `device.paired` —que escribe `RecordCredentialLifecycle` cuando `Identity` emite
 * el token— a proposito: **son dos hechos**. «Se dio de alta el puesto» lo firma
 * una persona en el `confirm`; «se entrego la llave» ocurre despues, cuando la
 * tablet recoge su token, y es anonimo por construccion. Separarlos es lo que
 * permite ver que entre uno y otro pasaron ocho minutos, o que nunca llego a
 * pasar nada.
 *
 * ## Listener propio y no un metodo mas de `RecordCredentialLifecycle`
 *
 * Aquel traduce eventos de `Identity` —credenciales y tokens—; este traduce uno de
 * `Kiosk`. Meterlo alli habria hecho que una clase importara los eventos de dos
 * modulos por dos motivos distintos, y la frontera de Deptrac lo habria concedido
 * sin que nadie volviera a mirar por que.
 *
 * ## Sincrono y dentro de la transaccion de quien publica
 *
 * No implementa `ShouldQueue` y no debe hacerlo: si el asiento falla, el quiosco
 * **no queda dado de alta** (ADR-027). Un origen de fichajes creado sin traza es
 * peor que un alta que no llega a producirse, porque la segunda se repite y la
 * primera no se descubre.
 *
 * ## Actor `system` cuando no hay persona
 *
 * `kiosk:pairing-code` no tiene sesion. Atribuirle el alta a la ultima cuenta que
 * entro al panel seria falsificar el trail; `system` es la respuesta honesta y el
 * catalogo la contempla.
 *
 * ## Que va en el payload y que no
 *
 * El `uuid` publico del dispositivo, su nombre —que es el del **sitio**, no el de
 * nadie—, la solicitud que se confirmo y si hubo reactivacion. **Ni un dato
 * personal** (regla dura 21), ni el token, ni su hash.
 */
final readonly class RecordDeviceProvisioning
{
    public function __construct(private RecordAuditEntry $audit) {}

    public function handle(DeviceProvisioned $event): void
    {
        $this->audit->handle(new RecordAuditEntryCommand(
            actor: $event->actorUserId === null
                ? AuditActor::system()
                : AuditActor::user($event->actorUserId),
            action: AuditAction::DeviceProvisioned,
            subject: AuditSubject::of('device', $event->deviceId),
            payload: AuditPayload::of([
                'device_uuid' => $event->deviceUuid,
                // El nombre del puesto. Es lo que hace legible el asiento: sin el,
                // «se dio de alta el dispositivo 0199f3c9…» no le dice nada a
                // quien lee.
                'name' => $event->name,
                // Une los tres pasos del emparejamiento en el trail.
                'pairing_request_uuid' => $event->pairingRequestUuid,
                // Distingue «se instalo un puesto nuevo» de «se cambio el aparato
                // del puesto de siempre» (ADR-028), que es lo que explica un hueco
                // en los fichajes de ese quiosco.
                'reactivated' => $event->reactivated,
                'previous_status' => $event->previousStatus,
                // Por que via se dio de alta. Es el mismo recurso que usa la
                // rotacion de tokens para distinguirse dentro de `device.paired`.
                'via' => 'pairing_code',
            ]),
            occurredAt: $event->occurredAt(),
        ));
    }
}
