<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application\UseCase;

use App\Modules\Identity\Application\Port\AccessTokenIssuer;
use App\Modules\Identity\Application\Port\IdentityEventPublisher;
use App\Modules\Identity\Application\Port\ManagementAccountLifecycle;
use App\Modules\Identity\Domain\Event\ManagementPasswordReset;
use App\Modules\Shared\Application\Port\Clock;
use Illuminate\Database\ConnectionInterface;
use SensitiveParameter;

/**
 * Sustituye la contrasena de una cuenta de gestion (**RS-06**, OWASP A07;
 * `identity:reset-password`, hallazgo H-03 de la revision interna ASVS de
 * 2026-09).
 *
 * ## Por que existe
 *
 * El producto no tenia ninguna forma de rotar la contrasena de una cuenta ya
 * creada: ni pantalla, ni endpoint, ni comando. La unica salida ante una
 * contrasena comprometida —o simplemente olvidada— era crear **otra cuenta**,
 * que es lo peor que puede hacerse con un registro horario: dos identidades para
 * la misma persona parten en dos la respuesta a «¿quien corrigio esta jornada?».
 *
 * **Y no hay recuperacion por correo** (regla dura 12, ADR-015). El producto no
 * depende del correo de nadie, la instalacion puede no tener salida a internet
 * (ADR-016) y un enlace de restablecimiento es otra credencial que emitir y
 * custodiar. La contrasena nueva la genera el comando y se entrega **en mano**,
 * igual que la tarjeta.
 *
 * ## Un caso de uso, una transaccion
 *
 * La contrasena nueva, la revocacion de los tokens y el asiento van juntos
 * (ADR-027). Si el asiento falla, la contrasena anterior sigue siendo la buena:
 * una sustitucion de credencial sin traza es exactamente el hecho que un
 * administrador comprometido querria que no constara.
 *
 * ## Y con la contrasena se van las sesiones
 *
 * Por la misma razon que en {@see ResetTwoFactorHandler}: de los dos motivos por
 * los que se ejecuta esto —olvido y sospecha—, en el que importa quien esta
 * dentro lleva una sesion viva de hasta doce horas. Cambiarle la contrasena sin
 * cerrarla le retiraria una credencial que ya no necesita.
 *
 * ## Lo que NO llega hasta aqui
 *
 * La politica de robustez de RF-ID-01 se aplica **donde se fija** la contrasena,
 * que es el comando: es el mismo sitio donde la aplica `identity:create-user` y
 * la misma razon —quien la genera es quien tiene que garantizar que cumple—.
 * Aqui la contrasena solo pasa de largo, marcada como sensible para que no
 * aparezca en un volcado de pila.
 */
final readonly class ResetManagementPasswordHandler
{
    public function __construct(
        private ManagementAccountLifecycle $accounts,
        private AccessTokenIssuer $tokens,
        private IdentityEventPublisher $events,
        private Clock $clock,
        private ConnectionInterface $connection,
    ) {}

    /**
     * @param  string|null  $actorUuid  Quien la restablece, si se sabe. En consola es
     *                                  `null` y el asiento sale a nombre del sistema.
     * @return bool `false` si no hay ninguna cuenta **activa** con ese correo. No se
     *              lanza excepcion: quien llama es un comando que ya sabe decirlo
     *              mejor que un `500`. Una cuenta dada de baja no recupera el acceso
     *              por cambiarle la contrasena, asi que restablecersela seria dar a
     *              entender lo contrario.
     */
    public function handle(string $email, #[SensitiveParameter] string $password, ?string $actorUuid = null): bool
    {
        $uuid = $this->accounts->uuidOfActiveAccount($email);

        if ($uuid === null) {
            return false;
        }

        $now = $this->clock->now();

        $this->connection->transaction(function () use ($uuid, $password, $actorUuid, $now): void {
            $this->accounts->replacePassword($uuid, $password);

            $this->tokens->revokeAllFor($uuid);

            $this->events->publish(new ManagementPasswordReset($uuid, $actorUuid, $now));
        });

        return true;
    }
}
