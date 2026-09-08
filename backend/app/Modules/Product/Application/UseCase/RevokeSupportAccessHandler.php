<?php

declare(strict_types=1);

namespace App\Modules\Product\Application\UseCase;

use App\Modules\Product\Application\Command\RevokeSupportAccessCommand;
use App\Modules\Product\Application\Port\ProductEventPublisher;
use App\Modules\Product\Application\Port\SupportGrantRepository;
use App\Modules\Product\Application\Port\SupportTokenIssuer;
use App\Modules\Product\Domain\Event\SupportAccessRevoked;
use App\Modules\Product\Domain\Exception\SupportGrantAlreadyClosed;
use App\Modules\Product\Domain\Model\SupportGrant;
use App\Modules\Product\Domain\ValueObject\SupportRevocationOutcome;
use App\Modules\Shared\Application\Port\Clock;
use Illuminate\Database\ConnectionInterface;

/**
 * Retira un acceso de soporte **en el acto** (**RF-PD-11**, ADR-020, regla dura
 * 5 y 6).
 *
 * ## Dos efectos y los dos son necesarios
 *
 * 1. **Borra los tokens de la fila.** Es lo que hace que el acceso muera en la
 *    peticion siguiente y no cuando expire. Sin esto, «revocar» seria un cambio
 *    cosmetico en una pantalla mientras el token sigue autenticando durante
 *    horas.
 * 2. **Marca `revoked_at`.** La fila se conserva entera (regla dura 5): el
 *    historico de accesos del fabricante es lo que el cliente enseña si alguien
 *    le pregunta.
 *
 * `Identity` comprueba ademas `revoked_at IS NULL` en cada peticion, asi que el
 * acceso muere por dos caminos independientes. Es a proposito: un token borrado
 * que quedara por copiar en algun sitio seguiria sin valer.
 *
 * ## Idempotente, y sin `SELECT` previo que decida
 *
 * Revocar una ya revocada devuelve `204` y **no vuelve a auditar**. Quien decide
 * si el hecho ocurrio es el dominio —{@see SupportGrant::revoke()} lanza si ya
 * estaba— y no una lectura previa: con dos pestañas abiertas, un `SELECT` que
 * mirara antes dejaria pasar las dos y escribiria dos asientos del mismo hecho.
 *
 * ## Nunca lo bloquea la licencia
 *
 * Regla dura 15. Revocar es la accion de seguridad de esta tarea: si dependiera
 * de una fecha de vencimiento comercial, un cliente con la licencia caducada no
 * podria cortarle el acceso al fabricante.
 */
final readonly class RevokeSupportAccessHandler
{
    public function __construct(
        private SupportGrantRepository $grants,
        private SupportTokenIssuer $tokens,
        private ProductEventPublisher $events,
        private Clock $clock,
        private ConnectionInterface $connection,
    ) {}

    public function handle(RevokeSupportAccessCommand $command): SupportRevocationOutcome
    {
        $grant = $this->grants->findByUuid($command->grantUuid);

        if (! $grant instanceof SupportGrant || $grant->id === null) {
            // `404` en la API: el UUID no existe. Es la unica respuesta distinta
            // que da este endpoint, y no revela nada — quien pregunta ya es
            // `admin` de esta instalacion.
            return SupportRevocationOutcome::NotFound;
        }

        $now = $this->clock->now();
        $wasActive = $grant->isActiveAt($now);
        $grantId = $grant->id;

        try {
            $grant->revoke($now, $command->revokedByUserId);
        } catch (SupportGrantAlreadyClosed) {
            // Segunda pulsacion del boton: ni asiento ni escritura. Sus tokens
            // se borraron en la primera, asi que tampoco hay nada que retirar.
            return SupportRevocationOutcome::AlreadyRevoked;
        }

        /** @var SupportRevocationOutcome $outcome */
        $outcome = $this->connection->transaction(
            function () use ($grant, $grantId, $command, $now, $wasActive): SupportRevocationOutcome {
                /*
                 * QUIEN DECIDE SI EL HECHO OCURRIO ES LA BASE DE DATOS, NO LA
                 * ENTIDAD.
                 *
                 * La escritura lleva `WHERE revoked_at IS NULL`, asi que de dos
                 * revocaciones simultaneas de la misma concesion solo una cambia
                 * una fila. La comprobacion del dominio de mas arriba no puede
                 * verlo: las dos peticiones leyeron la fila **antes** de que
                 * ninguna escribiera, las dos vieron `revoked_at` a nulo y las
                 * dos creen haber revocado.
                 *
                 * Sin este recuento se escribirian **dos asientos del mismo
                 * hecho** en una cadena encadenada por hash que se conserva
                 * cuatro años, y la pregunta «¿cuando se corto ese acceso?»
                 * tendria dos respuestas. Mismo criterio que la idempotencia del
                 * fichaje: la resuelve una restriccion, no un `SELECT` previo.
                 */
                if ($this->grants->markRevoked($grantId, $now, $command->revokedByUserId) !== 1) {
                    return SupportRevocationOutcome::AlreadyRevoked;
                }

                // Dentro de la transaccion, por lo mismo que la emision: un token
                // borrado con la marca sin escribir dejaria una concesion que consta
                // activa y no funciona, que es la peor de las dos incoherencias.
                $this->tokens->revokeAllFor($grant);

                $this->events->publish(new SupportAccessRevoked(
                    grantId: $grantId,
                    grantUuid: $grant->uuid,
                    scope: $grant->scope->value,
                    revokedByUserId: $command->revokedByUserId,
                    wasActive: $wasActive,
                    occurredAt: $now,
                ));

                return SupportRevocationOutcome::Revoked;
            }
        );

        return $outcome;
    }
}
