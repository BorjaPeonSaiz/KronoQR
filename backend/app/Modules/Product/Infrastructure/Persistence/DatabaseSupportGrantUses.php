<?php

declare(strict_types=1);

namespace App\Modules\Product\Infrastructure\Persistence;

use App\Modules\Product\Application\Port\SupportGrantUse;
use App\Modules\Product\Application\Port\SupportGrantUsePage;
use App\Modules\Product\Application\Port\SupportGrantUses;
use App\Modules\Shared\Infrastructure\Persistence\Row;
use Illuminate\Database\ConnectionInterface;

/**
 * Los asientos `support_grant.used` de una concesion, leidos de `audit_log`
 * (**RF-PD-11**, RL-15, RL-18, ADR-020 §3).
 *
 * ## Lectura, nunca escritura
 *
 * `audit_log` se escribe solo por `Compliance\Application\Port\AuditTrail`. Esto
 * es una lectura acotada, como la de `DatabaseDataExportSource`, y por la misma
 * razon vive en `Product`: el modulo no puede importar `Compliance` (doc 02
 * §1.6), asi que la accion va **literal**. Si `Compliance` la renombrara, esta
 * lista quedaria vacia; lo vigila `SupportGrantUsesEndpointTest`, que produce
 * los asientos por el camino real.
 *
 * ## Que filtra, y por que por el `payload`
 *
 * La accion y el `grant_id` del `payload`, que pone el propio evento a partir de
 * la concesion. El actor (`support_grant`, `actor_id`) lo resuelve el contexto
 * de la peticion y es un segundo camino que podria no coincidir; el `payload`
 * es el dato. `audit_log_action_index` acota primero por accion, y los usos del
 * soporte de una instalacion son pocos.
 *
 * ## Que sale
 *
 * El instante y `method` y `route` del `payload`. Nada mas del asiento: ni
 * `ip`, ni `user_agent`, ni hashes, ni la clave interna de la fila.
 */
final readonly class DatabaseSupportGrantUses implements SupportGrantUses
{
    /** `AuditAction::SupportGrantUsed` de `Compliance`, literal: ver el docblock. */
    private const string ACTION = 'support_grant.used';

    public function __construct(private ConnectionInterface $connection) {}

    public function page(int $grantId, int $page, int $perPage): SupportGrantUsePage
    {
        $page = max(1, $page);
        $perPage = max(1, min(100, $perPage));

        $where = "action = ? AND payload->>'grant_id' = ?";
        $bindings = [self::ACTION, (string) $grantId];

        /** @var object{total: int|string}|null $count */
        $count = $this->connection->selectOne('SELECT count(*) AS total FROM audit_log WHERE '.$where, $bindings);

        $rows = $this->connection->select(
            "SELECT occurred_at, payload->>'method' AS method, payload->>'route' AS route "
            .'FROM audit_log WHERE '.$where.' ORDER BY occurred_at DESC, id DESC LIMIT ? OFFSET ?',
            [...$bindings, $perPage, ($page - 1) * $perPage],
        );

        $uses = [];

        foreach ($rows as $row) {
            if (! \is_object($row)) {
                continue;
            }

            $fields = Row::of($row);

            $uses[] = new SupportGrantUse(
                startedAt: $fields->instant('occurred_at'),
                method: $fields->nullableString('method'),
                route: $fields->nullableString('route'),
            );
        }

        return new SupportGrantUsePage(
            rows: $uses,
            total: $count === null ? 0 : (int) $count->total,
            page: $page,
            perPage: $perPage,
        );
    }
}
