<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application\UseCase;

use App\Modules\Identity\Application\Port\AccessTokenIssuer;
use App\Modules\Identity\Application\Port\IdentityEventPublisher;
use App\Modules\Identity\Application\Port\TwoFactorSecrets;
use App\Modules\Identity\Application\Port\UserAccounts;
use App\Modules\Identity\Domain\Event\TwoFactorReset;
use App\Modules\Shared\Application\Port\Clock;
use Illuminate\Database\ConnectionInterface;

/**
 * Retira el segundo factor de una cuenta de gestion (**RS-06**,
 * `identity:2fa-reset`).
 *
 * ## Por que existe y por que es de consola
 *
 * Sin esto, perder el telefono deja a alguien fuera de su cuenta para siempre, y
 * a una instalacion con un solo administrador **sin panel**. Con codigos de
 * recuperacion habria una segunda salida, pero esos codigos son otra credencial
 * que emitir, entregar y custodiar —el mismo problema que ADR-014 resolvio para
 * la tarjeta— y en la primera version se deja fuera a proposito (deuda anotada).
 *
 * **Es un comando y no un endpoint** porque no hay ninguna ruta de gestion de
 * usuarios en el Anexo B: crear cuentas ya se hace con `identity:create-user`, y
 * la puesta en marcha de la Fase 5 llamara a los dos. Un endpoint de «quitale el
 * segundo factor a esta persona» tambien seria, en manos de un `admin`
 * comprometido, la forma mas comoda de preparar el acceso a la cuenta de otro; en
 * consola queda restringido a quien tiene el servidor y **siempre** deja asiento.
 *
 * ## Un caso de uso, una transaccion
 *
 * Retirar el secreto y publicar `auth.two_factor_reset` van juntos (ADR-027): si
 * el asiento falla, el segundo factor sigue en su sitio. Una credencial retirada
 * sin traza es justo el hecho que alguien querria que no constara.
 *
 * ## Y con el secreto se van las sesiones
 *
 * **Retirar el segundo factor sin cerrar lo que ya esta abierto no retira nada.**
 * Los dos motivos por los que se ejecuta este comando son el telefono perdido y la
 * sospecha de que la cuenta esta en manos de otro, y en el segundo —el que
 * importa— quien esta dentro lleva una sesion de gestion viva de hasta doce horas:
 * el comando le quitaria una credencial que ya no necesita y le dejaria el acceso
 * intacto. Se revocan **todos** los tokens de la cuenta, sesiones y retos, y en la
 * **misma transaccion** que el asiento: si la auditoria falla, ni se retira el
 * secreto ni se echa a nadie.
 *
 * Es la excepcion a la regla de {@see AccessTokenIssuer::revoke()} —cerrar sesion
 * en un sitio no echa a nadie de otro—, y por eso el puerto tiene dos metodos y no
 * un parametro: aqui se echa a todo el mundo a proposito.
 */
final readonly class ResetTwoFactorHandler
{
    public function __construct(
        private UserAccounts $accounts,
        private TwoFactorSecrets $secrets,
        private AccessTokenIssuer $tokens,
        private IdentityEventPublisher $events,
        private Clock $clock,
        private ConnectionInterface $connection,
    ) {}

    /**
     * @param  string|null  $actorUuid  Quien lo ejecuta, si se sabe. En consola es `null`
     *                                  y el asiento sale a nombre del sistema, que es la
     *                                  respuesta honesta.
     * @return bool `false` si esa cuenta no existe. No se lanza excepcion: quien llama es
     *              un comando que ya sabe decirlo mejor que un `500`.
     */
    public function handle(string $userUuid, string $reason, ?string $actorUuid = null): bool
    {
        if ($this->accounts->findByUuid($userUuid) === null) {
            return false;
        }

        $now = $this->clock->now();

        $this->connection->transaction(function () use ($userUuid, $reason, $actorUuid, $now): void {
            $this->secrets->forget($userUuid);

            // Dentro de la transaccion, con el secreto y el asiento: las tres
            // cosas ocurren o no ocurre ninguna. Una cuenta que perdio su segundo
            // factor y conserva su sesion abierta es el peor de los tres estados
            // intermedios posibles.
            $this->tokens->revokeAllFor($userUuid);

            $this->events->publish(new TwoFactorReset($userUuid, $reason, $actorUuid, $now));
        });

        return true;
    }
}
