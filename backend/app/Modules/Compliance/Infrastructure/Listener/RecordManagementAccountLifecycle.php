<?php

declare(strict_types=1);

namespace App\Modules\Compliance\Infrastructure\Listener;

use App\Modules\Compliance\Application\Command\RecordAuditEntryCommand;
use App\Modules\Compliance\Application\UseCase\RecordAuditEntry;
use App\Modules\Compliance\Domain\ValueObject\AuditAction;
use App\Modules\Compliance\Domain\ValueObject\AuditActor;
use App\Modules\Compliance\Domain\ValueObject\AuditPayload;
use App\Modules\Compliance\Domain\ValueObject\AuditSubject;
use App\Modules\Compliance\Infrastructure\Audit\ManagementUserDirectory;
use App\Modules\Identity\Domain\Event\ManagementAccountDeactivated;
use App\Modules\Identity\Domain\Event\ManagementPasswordReset;
use App\Modules\Identity\Domain\Event\ManagementRoleAssigned;
use App\Modules\Identity\Domain\Event\TwoFactorEnabled;
use App\Modules\Identity\Domain\Event\TwoFactorReset;

/**
 * Sella en `audit_log` lo que le pasa a una **cuenta de gestion**: su rol y su
 * segundo factor (regla dura 6, RS-05, RS-06, `/revision-cumplimiento` bloque D).
 *
 * **Por que un listener aqui y no una llamada desde `Identity`.** Lo mismo que en
 * {@see RecordCredentialLifecycle}: el §1.6 no concede la arista `Identity ->
 * Compliance`, asi que `Identity` publica su evento y este listener lo traduce al
 * vocabulario cerrado de {@see AuditAction}.
 *
 * **Desde la tarea 3.8 sella tambien la baja de la cuenta y la sustitucion de su
 * contrasena** (`user.deactivated` y `user.password_reset`, hallazgo H-03 de la
 * revision interna ASVS de 2026-09). Son el resto del ciclo de vida que esta
 * clase contaba a medias: sabia decir quien recibio un rol y quien perdio su
 * segundo factor, pero no quien dejo de tener acceso, cuando ni por orden de
 * quien.
 *
 * **Por que un listener nuevo y no un metodo mas en el de credenciales.** Porque
 * las acciones de aqui **no son todas de la misma familia del bloque D**: el
 * segundo factor es ciclo de vida de una credencial y el rol es «cambia roles,
 * permisos o configuracion». Meterlas en la clase que se llama «credential
 * lifecycle» haria que su nombre mintiera sobre la mitad de lo que hace.
 *
 * **Los eventos viajan con UUID y aqui se traducen a `users.id`**, con el mismo
 * {@see ManagementUserDirectory} que usa el rastro de autenticacion. `Identity` no
 * puede publicar la clave interna sin sacarla antes de su capa de aplicacion, y
 * `audit_log.actor_id` la necesita: la traduccion es trabajo de este lado.
 *
 * **Sincrono y dentro de la transaccion de quien publica**, sin `ShouldQueue`: si
 * el asiento falla, la activacion del segundo factor o la asignacion del rol **no
 * se confirman** (ADR-027). Un rol concedido sin traza es peor que uno no
 * concedido, porque el segundo se repite y el primero no se descubre.
 *
 * **Lo que va en el payload y lo que no** (regla dura 21): el `uuid` publico de la
 * cuenta y el nombre del rol. Ni el correo, ni el nombre de la persona, ni —sobre
 * todo— el secreto TOTP ni ningun derivado suyo.
 */
final readonly class RecordManagementAccountLifecycle
{
    public function __construct(
        private RecordAuditEntry $audit,
        private ManagementUserDirectory $users,
    ) {}

    public function handleTwoFactorEnabled(TwoFactorEnabled $event): void
    {
        $userId = $this->users->idOf($event->userUuid);

        $this->audit->handle(new RecordAuditEntryCommand(
            // El actor es la propia cuenta: nadie mas puede activar el segundo
            // factor de alguien, porque hace falta el codigo de su telefono.
            actor: $userId === null ? AuditActor::system() : AuditActor::user($userId),
            action: AuditAction::TwoFactorEnabled,
            subject: AuditSubject::of('user', $userId),
            payload: AuditPayload::of([
                'user_uuid' => $event->userUuid,
            ]),
            occurredAt: $event->occurredAt(),
        ));
    }

    public function handleTwoFactorReset(TwoFactorReset $event): void
    {
        $this->audit->handle(new RecordAuditEntryCommand(
            actor: $this->actor($event->actorUuid),
            action: AuditAction::TwoFactorReset,
            subject: AuditSubject::of('user', $this->users->idOf($event->userUuid)),
            payload: AuditPayload::of([
                'user_uuid' => $event->userUuid,
                'reason' => $event->reason,
            ]),
            occurredAt: $event->occurredAt(),
        ));
    }

    public function handleAccountDeactivated(ManagementAccountDeactivated $event): void
    {
        $this->audit->handle(new RecordAuditEntryCommand(
            actor: $this->actor($event->actorUuid),
            action: AuditAction::ManagementAccountDeactivated,
            subject: AuditSubject::of('user', $this->users->idOf($event->userUuid)),
            payload: AuditPayload::of([
                'user_uuid' => $event->userUuid,
                'reason' => $event->reason,
            ]),
            occurredAt: $event->occurredAt(),
        ));
    }

    public function handlePasswordReset(ManagementPasswordReset $event): void
    {
        $this->audit->handle(new RecordAuditEntryCommand(
            actor: $this->actor($event->actorUuid),
            action: AuditAction::ManagementPasswordReset,
            subject: AuditSubject::of('user', $this->users->idOf($event->userUuid)),
            // Solo el uuid, y ni siquiera un motivo: el hecho auditable es que la
            // credencial se sustituyo. La contrasena, su longitud y cualquier
            // derivado suyo se quedan fuera del payload a proposito.
            payload: AuditPayload::of([
                'user_uuid' => $event->userUuid,
            ]),
            occurredAt: $event->occurredAt(),
        ));
    }

    public function handleRoleAssigned(ManagementRoleAssigned $event): void
    {
        $this->audit->handle(new RecordAuditEntryCommand(
            actor: $this->actor($event->actorUuid),
            action: AuditAction::RoleAssignmentChanged,
            subject: AuditSubject::of('user', $this->users->idOf($event->userUuid)),
            payload: AuditPayload::of([
                'user_uuid' => $event->userUuid,
                'role' => $event->role->value,
            ]),
            occurredAt: $event->occurredAt(),
        ));
    }

    /**
     * Persona si la hubo, sistema si no. Un comando de consola no tiene sesion, y
     * atribuirle la accion a la ultima persona que entro al panel seria
     * falsificar el trail.
     */
    private function actor(?string $actorUuid): AuditActor
    {
        $id = $this->users->idOf($actorUuid);

        return $id === null ? AuditActor::system() : AuditActor::user($id);
    }
}
