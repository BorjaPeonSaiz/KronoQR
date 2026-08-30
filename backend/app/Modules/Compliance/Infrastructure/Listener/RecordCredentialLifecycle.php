<?php

declare(strict_types=1);

namespace App\Modules\Compliance\Infrastructure\Listener;

use App\Modules\Compliance\Application\Command\RecordAuditEntryCommand;
use App\Modules\Compliance\Application\UseCase\RecordAuditEntry;
use App\Modules\Compliance\Domain\ValueObject\AuditAction;
use App\Modules\Compliance\Domain\ValueObject\AuditActor;
use App\Modules\Compliance\Domain\ValueObject\AuditPayload;
use App\Modules\Compliance\Domain\ValueObject\AuditSubject;
use App\Modules\Identity\Domain\Event\CredentialDelivered;
use App\Modules\Identity\Domain\Event\CredentialIssued;
use App\Modules\Identity\Domain\Event\CredentialPrinted;
use App\Modules\Identity\Domain\Event\CredentialRevoked;
use App\Modules\Identity\Domain\Event\DeviceTokenIssued;
use App\Modules\Identity\Domain\Event\DeviceTokenRevoked;
use App\Modules\Identity\Domain\Event\SigningKeyRetired;
use App\Modules\Identity\Domain\Event\SigningKeyRotated;

/**
 * Sella en `audit_log` los hechos del ciclo de vida de credenciales y
 * dispositivos (regla dura 6, ADR-010, `/revision-cumplimiento` bloque D).
 *
 * **Por que un listener aqui y no una llamada desde `Identity`.** El §1.6 no
 * concede la arista `Identity -> Compliance`, y con razon: el modulo que
 * produce un hecho no tiene que saber quien lo apunta. `Identity` publica su
 * evento de dominio; este listener —que si puede conocer los eventos de otros
 * modulos, porque esa es exactamente su funcion— lo traduce al vocabulario
 * cerrado de {@see AuditAction}. Es la via que el propio puerto `AuditTrail`
 * nombra para las tareas 1.5 y 1.13.
 *
 * **Sincrono y dentro de la transaccion de quien publica.** No implementa
 * `ShouldQueue` y no debe hacerlo: si el asiento falla, la emision o la
 * revocacion **no se confirman** (ADR-027). Una credencial emitida sin traza es
 * peor que una credencial no emitida, porque la segunda se repite y la primera
 * no se descubre.
 *
 * **Lo que va en el payload y lo que no** (regla dura 21, RGPD): `employee_uuid`,
 * `device_id` publico, `key_id` y el motivo de una revocacion. Ni nombres, ni
 * correos, ni el token, ni su hash. Con lo que hay se reconstruye cualquier
 * investigacion; con lo que no hay se fabricaria una tarjeta.
 */
final readonly class RecordCredentialLifecycle
{
    public function __construct(private RecordAuditEntry $audit) {}

    public function handleCredentialIssued(CredentialIssued $event): void
    {
        $this->audit->handle(new RecordAuditEntryCommand(
            actor: $this->actor($event->actorUserId),
            // Reemitir y emitir son dos hechos distintos para quien despues
            // tenga que explicar por que alguien tuvo tres tarjetas en un año.
            action: $event->reissue ? AuditAction::CredentialReissued : AuditAction::CredentialIssued,
            subject: AuditSubject::of('credential', $event->credentialId),
            payload: AuditPayload::of([
                'credential_uuid' => $event->credentialUuid,
                'employee_uuid' => $event->employeeUuid,
                // Sin `key_id`: en la emision todavia no hay ninguno. El token y
                // su firma se acuñan al imprimir (ADR-034), y es el asiento de
                // `credential.printed` —tarea 1.10— el que lo lleva.
            ]),
            occurredAt: $event->occurredAt(),
        ));
    }

    /**
     * **El asiento del momento en que una persona pasa a poder fichar**
     * (RF-QR-04, ADR-034).
     *
     * Antes de esto la credencial existia como derecho administrativo y ningun
     * escaneo la alcanzaba. A partir de aqui hay una tarjeta capaz de registrar
     * jornada en nombre de alguien, y si mañana se discute un fichaje, este
     * asiento es el que dice desde cuando existia y con que clave se firmo.
     *
     * **Este si lleva `key_id` y el de la emision no**: en la emision no habia
     * ninguna clave elegida. Durante una rotacion con solape (§5.3), es lo que
     * permite reconstruir que tarjetas quedan por reimprimir antes de retirar la
     * clave anterior.
     *
     * **Lo que no lleva**: ni el token, ni su firma, ni su hash. El token existe
     * los milisegundos que se tarda en dibujar el PDF; escribirlo aqui lo
     * devolveria a un sitio del que ADR-034 lo saco a proposito.
     */
    public function handleCredentialPrinted(CredentialPrinted $event): void
    {
        $this->audit->handle(new RecordAuditEntryCommand(
            actor: $this->actor($event->actorUserId),
            action: AuditAction::CredentialPrinted,
            subject: AuditSubject::of('credential', $event->credentialId),
            payload: AuditPayload::of([
                'credential_uuid' => $event->credentialUuid,
                'employee_uuid' => $event->employeeUuid,
                'key_id' => $event->keyId,
                // Sesenta asientos seguidos con `batch: true` son una tarde de
                // preparacion de temporada; sesenta sueltos, otra cosa. No cambia
                // lo que ocurrio y cambia mucho lo que se puede reconstruir.
                'batch' => $event->batch,
            ]),
            occurredAt: $event->occurredAt(),
        ));
    }

    /**
     * El asiento de la entrega (RF-QR-06).
     *
     * El doc 02 §5.5 dice por que no es burocracia: *«es lo que distingue "la
     * tarjeta se perdio antes de darsela" de "el empleado la perdio", que son
     * incidencias distintas»*.
     *
     * **`delivered_by_user_id` va en el payload aunque el actor ya este en su
     * columna, y no es redundante.** Por consola el actor es `system` —no hay
     * sesion— y el responsable es la persona que se declara con `--by=`. Son dos
     * hechos distintos: quien manejo el sistema y quien firma que entrego la
     * tarjeta. Confundirlos dejaria asientos sin responsable.
     */
    public function handleCredentialDelivered(CredentialDelivered $event): void
    {
        $this->audit->handle(new RecordAuditEntryCommand(
            actor: $this->actor($event->actorUserId),
            action: AuditAction::CredentialDelivered,
            subject: AuditSubject::of('credential', $event->credentialId),
            payload: AuditPayload::of([
                'credential_uuid' => $event->credentialUuid,
                'employee_uuid' => $event->employeeUuid,
                'delivered_by_user_id' => $event->deliveredByUserId,
            ]),
            occurredAt: $event->occurredAt(),
        ));
    }

    public function handleCredentialRevoked(CredentialRevoked $event): void
    {
        $this->audit->handle(new RecordAuditEntryCommand(
            actor: $this->actor($event->actorUserId),
            action: AuditAction::CredentialRevoked,
            subject: AuditSubject::of('credential', $event->credentialId),
            payload: AuditPayload::of([
                'credential_uuid' => $event->credentialUuid,
                'employee_uuid' => $event->employeeUuid,
                // El motivo lo escribe quien revoca y es lo que distingue «se
                // perdio antes de entregarla» de «el empleado la perdio». Es
                // texto libre: quien lo redacta no debe poner nombres ahi.
                'reason' => $event->reason,
            ]),
            occurredAt: $event->occurredAt(),
        ));
    }

    /**
     * **La apertura de una rotacion de clave** (RF-QR-07, tarea 2.12).
     *
     * Sujeto `signing_key` sin identificador: no recae sobre ninguna fila, recae
     * sobre el material con el que se firman todas las tarjetas. La reemision de
     * cada una deja ademas su propio `credential.reissued`.
     *
     * **Los `key_id` si van en el payload y las claves no.** El identificador va
     * impreso en cada tarjeta (ADR-005): no es un secreto, y sin el este asiento
     * no explicaria nada.
     */
    public function handleSigningKeyRotated(SigningKeyRotated $event): void
    {
        $this->audit->handle(new RecordAuditEntryCommand(
            actor: $this->actor($event->actorUserId),
            action: AuditAction::SigningKeyRotated,
            subject: AuditSubject::of('signing_key'),
            payload: AuditPayload::of([
                'retiring_key_id' => $event->retiringKeyId,
                'current_key_id' => $event->currentKeyId,
                'reissued' => $event->reissued,
                'already_pending' => $event->alreadyPending,
            ]),
            occurredAt: $event->occurredAt(),
        ));
    }

    /**
     * **El cierre del solape** (RF-QR-07).
     *
     * Es el asiento que fecha la muerte de un lote de tarjetas. Sin el, la unica
     * explicacion de por que un QR de hace dos años dejo de verificar seria
     * «alguien cambio una variable de entorno», y eso no consta en ninguna parte.
     */
    public function handleSigningKeyRetired(SigningKeyRetired $event): void
    {
        $this->audit->handle(new RecordAuditEntryCommand(
            actor: $this->actor($event->actorUserId),
            action: AuditAction::SigningKeyRetired,
            subject: AuditSubject::of('signing_key'),
            payload: AuditPayload::of([
                'key_id' => $event->keyId,
                'signed_credentials' => $event->signedCredentials,
            ]),
            occurredAt: $event->occurredAt(),
        ));
    }

    public function handleDeviceTokenIssued(DeviceTokenIssued $event): void
    {
        $this->audit->handle(new RecordAuditEntryCommand(
            actor: $this->actor($event->actorUserId),
            // `device.paired` en los dos casos: el catalogo de `AuditAction` no
            // distingue la rotacion, y el payload si. Inventar un valor nuevo
            // seria decidir por mi cuenta el vocabulario del bloque D.
            action: AuditAction::DevicePaired,
            subject: AuditSubject::of('device', $event->deviceId),
            payload: AuditPayload::of([
                'device_uuid' => $event->deviceUuid,
                'abilities' => $event->abilities,
                'expires_at' => $event->expiresAt->format('Y-m-d\TH:i:sP'),
                'rotation' => $event->rotation,
            ]),
            occurredAt: $event->occurredAt(),
        ));
    }

    public function handleDeviceTokenRevoked(DeviceTokenRevoked $event): void
    {
        $this->audit->handle(new RecordAuditEntryCommand(
            actor: $this->actor($event->actorUserId),
            action: AuditAction::DeviceRevoked,
            subject: AuditSubject::of('device', $event->deviceId),
            payload: AuditPayload::of([
                'device_uuid' => $event->deviceUuid,
                'reason' => $event->reason,
            ]),
            occurredAt: $event->occurredAt(),
        ));
    }

    /**
     * Persona si la hubo, sistema si no.
     *
     * Un comando de consola no tiene sesion, y atribuirle la accion a la ultima
     * persona que entro al panel seria falsificar el trail. `system` es la
     * respuesta honesta y el catalogo de `AuditActorType` la contempla.
     */
    private function actor(?int $actorUserId): AuditActor
    {
        return $actorUserId === null ? AuditActor::system() : AuditActor::user($actorUserId);
    }
}
