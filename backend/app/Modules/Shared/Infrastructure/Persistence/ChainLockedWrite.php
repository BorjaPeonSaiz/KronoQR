<?php

declare(strict_types=1);

namespace App\Modules\Shared\Infrastructure\Persistence;

use App\Modules\Shared\Application\Port\SerializedLedgerWrite;
use Illuminate\Database\ConnectionInterface;

/**
 * Adaptador de {@see SerializedLedgerWrite}: una transaccion con el candado de
 * la cadena de auditoria ya tomado (ADR-010, ADR-027).
 *
 * **La transaccion se abre aqui y el candado es lo primero que ocurre dentro.**
 * Ese orden es todo el contenido de esta clase: el camino del fichaje toma el
 * candado global de la cadena y despues las filas de sus proyecciones, y
 * cualquiera que lo hiciera al reves cerraria un ciclo con el. El porque
 * completo esta en el docblock del puerto.
 *
 * `transaction()` sobre una transaccion ya abierta crea un `SAVEPOINT` y no una
 * segunda transaccion, asi que esto se puede envolver dentro de un caso de uso
 * que ya abrio la suya: el candado sigue siendo de la exterior y se suelta con
 * ella.
 *
 * Vive en `Shared/Infrastructure/Persistence` junto a la clave que usa, y no en
 * `Compliance`, porque lo consume un modulo que no puede importar `Compliance`
 * (doc 02 §1.6). Comparte la clave —no una copia— con el escritor de la cadena.
 */
final readonly class ChainLockedWrite implements SerializedLedgerWrite
{
    public function __construct(private ConnectionInterface $connection) {}

    public function withChainLock(callable $work): mixed
    {
        return $this->connection->transaction(function () use ($work): mixed {
            AuditChainLock::takeOn($this->connection);

            return $work();
        });
    }
}
