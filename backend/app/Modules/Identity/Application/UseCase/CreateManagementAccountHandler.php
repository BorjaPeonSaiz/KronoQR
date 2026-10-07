<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application\UseCase;

use App\Modules\Identity\Application\Command\ActorProof;
use App\Modules\Identity\Application\Command\CreateManagementAccountCommand;
use App\Modules\Identity\Application\Exception\AccountTemporarilyLocked;
use App\Modules\Identity\Application\Exception\ActorReauthenticationFailed;
use App\Modules\Identity\Application\Exception\ManagementAccountEmailTaken;
use App\Modules\Identity\Application\Port\IdentityEventPublisher;
use App\Modules\Identity\Application\Port\ManagementAccountChange;
use App\Modules\Identity\Application\Port\ManagementAccountRegistry;
use App\Modules\Identity\Application\Port\PasswordHasher;
use App\Modules\Identity\Application\Port\TemporaryPasswordGenerator;
use App\Modules\Identity\Application\Support\ActorReauthentication;
use App\Modules\Identity\Application\Support\ManagementAccountTelemetry;
use App\Modules\Identity\Application\Support\TemporaryPasswordSettings;
use App\Modules\Identity\Domain\Event\ManagementAccountCreated;
use App\Modules\Identity\Domain\Event\ManagementRoleAssigned;
use App\Modules\Identity\Domain\ValueObject\AuthenticatedUser;
use App\Modules\Identity\Domain\ValueObject\TemporaryPassword;
use App\Modules\Shared\Application\Port\Clock;
use App\Modules\Shared\Application\Port\SerializedLedgerWrite;

/**
 * Alta de una cuenta de gestion con **contrasena temporal** (**RF-ID-10**,
 * RF-ID-02; `POST /api/v1/management-accounts` e `identity:create-user`).
 *
 * ## Un solo camino para el panel y la consola
 *
 * Hasta la 2.2.0 el alta vivia entera dentro del comando de consola, que pedia
 * la contrasena con eco apagado. Ahora las dos entradas pasan por aqui y las dos
 * entregan lo mismo: una contrasena **generada**, que se enseña una vez, se
 * entrega en mano (regla dura 12) y caduca. Quien la recibe entra con ella,
 * activa su segundo factor si su rol lo exige y fija la suya antes de poder
 * hacer nada mas.
 *
 * ## Lo caro, fuera del candado
 *
 * Generar y hashear la contrasena cuesta decenas de milisegundos (`bcrypt`), y
 * todo lo que se audita pasa por el candado global de la cadena (ADR-010), el
 * mismo por el que pasa cada fichaje. Se calcula **antes** de tomarlo, y la
 * reautenticacion del `admin` que actua tambien.
 *
 * ## Un caso de uso, una transaccion
 *
 * Comprobar el correo, crear la cuenta con su rol y publicar `user.created` y
 * `role_assignment.changed` ocurren dentro de `withChainLock` (ADR-010): si un
 * asiento falla, la cuenta no se crea. El `UNIQUE (users.email)` sigue siendo la
 * red en una carrera: el adaptador lo traduce a {@see ManagementAccountEmailTaken}.
 */
final readonly class CreateManagementAccountHandler
{
    public function __construct(
        private ManagementAccountRegistry $accounts,
        private TemporaryPasswordGenerator $generator,
        private PasswordHasher $hasher,
        private TemporaryPasswordSettings $settings,
        private ActorReauthentication $reauthentication,
        private IdentityEventPublisher $events,
        private Clock $clock,
        private SerializedLedgerWrite $serialized,
        private ManagementAccountTelemetry $telemetry,
    ) {}

    /**
     * @throws ManagementAccountEmailTaken si el correo ya es de otra cuenta, activa o no
     * @throws ActorReauthenticationFailed si quien actua no confirma su identidad
     * @throws AccountTemporarilyLocked con el bloqueo de intentos de codigo de quien actua abierto
     */
    public function handle(CreateManagementAccountCommand $command): ManagementAccountProvisioned
    {
        return $this->telemetry->measure(
            ManagementAccountChange::Created,
            null,
            $command->actorUuid,
            fn (): ManagementAccountProvisioned => $this->create($command),
            static fn (): string => 'created',
        );
    }

    private function create(CreateManagementAccountCommand $command): ManagementAccountProvisioned
    {
        if ($command->actorUuid !== null) {
            $this->reauthentication->confirm($command->actorUuid, $command->proof ?? new ActorProof(null, null));
        }

        $plain = $this->generator->generate($this->settings->minLength);
        $password = new TemporaryPassword($plain, $this->hasher->hash($plain));
        $issuedAt = $this->clock->now();
        $expiresAt = $this->settings->lifetime->expiresAt($issuedAt);

        $account = $this->serialized->withChainLock(
            function () use ($command, $password, $issuedAt, $expiresAt): AuthenticatedUser {
                if ($this->accounts->emailTaken($command->email)) {
                    throw new ManagementAccountEmailTaken;
                }

                $account = $this->accounts->create(
                    $command->name,
                    $command->email,
                    $password->hash,
                    $command->locale,
                    $command->role,
                    $expiresAt,
                );

                $this->events->publish(
                    new ManagementAccountCreated($account->uuid, $command->actorUuid, $issuedAt),
                    new ManagementRoleAssigned(
                        userUuid: $account->uuid,
                        role: $command->role,
                        actorUuid: $command->actorUuid,
                        occurredAt: $issuedAt,
                    ),
                );

                return $account;
            },
        );

        $this->telemetry->count(ManagementAccountChange::Created, [$command->role]);

        return new ManagementAccountProvisioned($account, $password, $issuedAt, $expiresAt);
    }
}
