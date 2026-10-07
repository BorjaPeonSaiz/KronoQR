<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application\Support;

use App\Modules\Identity\Application\Port\ManagementAccountChange;
use App\Modules\Identity\Application\Port\ManagementAccountMetrics;
use App\Modules\Shared\Application\Support\SpanScope;
use App\Modules\Shared\Domain\ValueObject\UserRole;
use OpenTelemetry\API\Trace\SpanKind;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Traza, log y metrica de los casos de uso del ciclo de vida de las cuentas de
 * gestion (RF-ID-10, doc 02 §8).
 *
 * - **Span** `identity.account.<accion>` en el ambito `kronoqr.identity`.
 * - **Log** `identity.account.<accion>` con `user_uuid`, `actor_uuid`,
 *   `outcome` y `trace_id`. **Nunca el nombre ni el correo** (regla dura 21):
 *   el log viaja al fabricante en el paquete de diagnostico.
 * - **Metrica** `kronoqr_management_account_changes_total{action,role}`, solo
 *   cuando el hecho ocurrio: un `404` o un `409` no cambian ninguna cuenta.
 */
final readonly class ManagementAccountTelemetry
{
    public function __construct(
        private LoggerInterface $logger,
        private ManagementAccountMetrics $metrics,
    ) {}

    /**
     * @template T
     *
     * @param  callable(): T  $act
     * @param  callable(T): string  $outcomeOf  Desenlace en una palabra (`ok`, `not_found`, `conflict`...).
     * @return T
     */
    public function measure(
        ManagementAccountChange $action,
        ?string $userUuid,
        ?string $actorUuid,
        callable $act,
        callable $outcomeOf,
    ): mixed {
        $name = 'identity.account.'.$action->value;
        $attributes = ['user_uuid' => $userUuid, 'actor_uuid' => $actorUuid];

        $span = SpanScope::start('kronoqr.identity', $name, SpanKind::KIND_INTERNAL, $attributes);

        try {
            $result = $act();
        } catch (Throwable $failure) {
            $span->end(['outcome' => 'error']);

            // La clase y no el mensaje: el de una excepcion de infraestructura
            // puede llevar una consulta entera, con un correo dentro.
            $this->logger->warning($name.'_failed', [
                ...$attributes,
                'trace_id' => $span->traceId(),
                'failure' => $failure::class,
            ]);

            throw $failure;
        }

        $outcome = $outcomeOf($result);

        $span->end(['outcome' => $outcome]);

        $this->logger->info($name, [...$attributes, 'outcome' => $outcome, 'trace_id' => $span->traceId()]);

        return $result;
    }

    /**
     * Un hecho consumado: suma uno a la metrica con el rol de la cuenta afectada.
     *
     * @param  list<UserRole>  $roles
     */
    public function count(ManagementAccountChange $action, array $roles): void
    {
        foreach ($roles as $role) {
            $this->metrics->changed($action, $role);
        }
    }
}
