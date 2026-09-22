<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application\UseCase;

use App\Modules\Identity\Application\Port\AccessTokenIssuer;
use App\Modules\Identity\Application\Port\IdentityEventPublisher;
use App\Modules\Identity\Application\Port\ManagementAccountLifecycle;
use App\Modules\Identity\Domain\Event\ManagementAccountDeactivated;
use App\Modules\Shared\Application\Port\Clock;
use Illuminate\Database\ConnectionInterface;

/**
 * Da de baja una cuenta de gestion (**RS-05**, **RS-06**, **RL-16**;
 * `identity:deactivate-user`, hallazgo H-03 de la revision interna ASVS de
 * 2026-09).
 *
 * ## Por que existe
 *
 * `users.is_active` se consultaba al autenticar desde la Fase 1, pero **las dos
 * unicas escrituras del campo lo ponian a `true`**: el alta por consola y el alta
 * del primer administrador. No habia endpoint, ni comando, ni pantalla, asi que
 * retirarle el acceso a quien deja el hotel exigia editar la fila a mano en
 * PostgreSQL — fuera del producto y, por tanto, fuera del trail. Mientras eso no
 * se hacia, un jefe de recepcion que se fue conservaba una cuenta plenamente
 * valida, con su contrasena y su TOTP, con acceso a los datos de toda la
 * plantilla **y a la correccion de jornadas**, que es donde se falsea un registro
 * horario.
 *
 * ## Por que es un comando y no un endpoint
 *
 * Lo mismo que {@see ResetTwoFactorHandler}: el Anexo B del doc 01 no tiene
 * ninguna ruta de gestion de usuarios, y un «da de baja a esta persona» por API
 * seria, en manos de un `admin` comprometido, la forma mas comoda de dejar a la
 * instalacion sin nadie que pueda revisar lo que hizo. La pantalla del panel
 * queda como decision de producto (ficha 3.8, decision 16).
 *
 * ## Un caso de uso, una transaccion
 *
 * La baja, la revocacion de los tokens y el asiento van juntos (ADR-027). Si la
 * auditoria falla, la cuenta sigue activa: una baja sin traza es justo el hecho
 * que alguien querria que no constara.
 *
 * ## Y con la baja se van las sesiones, por dos caminos
 *
 * Se revocan **todos** los tokens de la cuenta —sesiones y retos a medias—
 * porque una baja que deja vivas las sesiones abiertas no da de baja a nadie
 * durante las doce horas siguientes. Ademas, el callback de Sanctum consulta
 * `is_active` en cada peticion, asi que aunque un token sobreviviera no valdria:
 * son dos puertas cerradas y a proposito, porque la primera es la que se olvida.
 *
 * ## Lo que esta baja NO hace, y es lo que la hace segura
 *
 * **No borra la cuenta** (regla dura 5). La fila se queda, con su historial y
 * con sus asientos, y sigue contando para la guarda de
 * {@see CreateFirstAdministratorHandler}: si contara solo las activas, dar de
 * baja a la unica persona con acceso **reabriria la creacion publica de un
 * administrador**, y una tarea rutinaria de RRHH se convertiria en una via de
 * escalada.
 */
final readonly class DeactivateManagementAccountHandler
{
    public function __construct(
        private ManagementAccountLifecycle $accounts,
        private AccessTokenIssuer $tokens,
        private IdentityEventPublisher $events,
        private Clock $clock,
        private ConnectionInterface $connection,
    ) {}

    /**
     * @param  string|null  $actorUuid  Quien la da de baja, si se sabe. En consola es
     *                                  `null` y el asiento sale a nombre del sistema,
     *                                  que es la respuesta honesta.
     */
    public function handle(string $email, string $reason, ?string $actorUuid = null): AccountDeactivationOutcome
    {
        $uuid = $this->accounts->uuidOfActiveAccount($email);

        if ($uuid === null) {
            // Se distinguen los dos «no» porque el comando tiene que decir cosas
            // distintas, y ninguno de los dos se puede alcanzar por HTTP: esto es
            // consola del servidor del cliente, no un oraculo de enumeracion.
            return $this->accounts->accountExists($email)
                ? AccountDeactivationOutcome::AlreadyInactive
                : AccountDeactivationOutcome::NotFound;
        }

        $now = $this->clock->now();

        $this->connection->transaction(function () use ($uuid, $reason, $actorUuid, $now): void {
            $this->accounts->deactivate($uuid);

            $this->tokens->revokeAllFor($uuid);

            $this->events->publish(new ManagementAccountDeactivated($uuid, $reason, $actorUuid, $now));
        });

        return AccountDeactivationOutcome::Deactivated;
    }
}
