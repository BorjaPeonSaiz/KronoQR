<?php

declare(strict_types=1);

namespace App\Modules\Identity\Application\Support;

use App\Modules\Identity\Application\Port\ManagementAccountChange;
use App\Modules\Identity\Application\Port\ManagementAccountMetrics;
use App\Modules\Shared\Application\Support\SpanScope;
use App\Modules\Shared\Application\Support\UseCaseInstrumentation;
use App\Modules\Shared\Domain\ValueObject\UserRole;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Log y metrica de los casos de uso del ciclo de vida de las cuentas de
 * gestion (RF-ID-10, doc 02 §8).
 *
 * - **El span y la duracion** los pone el caso de uso con
 *   {@see UseCaseInstrumentation}, que es la medida comun: aqui solo
 *   se le añaden `user_uuid`, `actor_uuid` y `account.outcome`.
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

        // Al span del caso de uso, que esta activo (`UseCaseInstrumentation`).
        SpanScope::annotateCurrent($attributes);

        try {
            $result = $act();
        } catch (Throwable $failure) {
            // La clase y no el mensaje: el de una excepcion de infraestructura
            // puede llevar una consulta entera, con un correo dentro.
            $this->logger->warning($name.'_failed', [
                ...$attributes,
                'trace_id' => SpanScope::currentTraceId(),
                'failure' => $failure::class,
            ]);

            throw $failure;
        }

        $outcome = $outcomeOf($result);

        SpanScope::annotateCurrent(['account.outcome' => $outcome]);

        $this->logger->info($name, [...$attributes, 'outcome' => $outcome, 'trace_id' => SpanScope::currentTraceId()]);

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
