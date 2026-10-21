<?php

declare(strict_types=1);

namespace App\Support\Resilience;

use App\Support\Database\DatabaseUnavailable;
use PDOException;
use RedisException;
use Throwable;

/**
 * Reconoce en una excepcion que lo que falta es una DEPENDENCIA —PostgreSQL o
 * Redis— y no el producto (ADR-059 §1).
 *
 * ## Que cuenta como dependencia caida
 *
 * - **PostgreSQL:** `DatabaseUnavailable` en la cadena `previous` (el conector
 *   no llego a conectar, o el circuito esta abierto), **o** la conexion perdida a
 *   mitad de una sentencia, del `COMMIT` o del `ROLLBACK`: SQLSTATE de la clase
 *   `08` o `57P01`-`57P03`, o los mensajes de libpq de conexion perdida. Una
 *   conexion perdida no abre el circuito por si sola: lo abre el intento de
 *   conexion siguiente si el servidor no esta (un `pg_terminate_backend` no
 *   puede dejar diez segundos de `503` en toda la instalacion).
 * - **Redis:** cualquier `RedisException` —al conectar o a mitad de una
 *   orden—, `RedisCircuitOpen` incluida, y la de conexion de Predis.
 *
 * Solo clasifica: no escribe, no informa y no lee el mensaje fuera de aqui. El
 * mensaje del driver lleva host y puerto, y no sale de este metodo.
 */
final class DependencyFailure
{
    public const string DATABASE = 'database';

    public const string REDIS = 'redis';

    /** Conexion perdida o inexistente, y servidor apagandose o arrancando. */
    private const array LOST_SQLSTATES = ['08000', '08003', '08006', '57P01', '57P02', '57P03'];

    /** Lo que escribe libpq cuando la conexion se pierde a mitad de camino. */
    private const array LOST_MESSAGES = [
        'server closed the connection unexpectedly',
        'no connection to the server',
        'SSL connection has been closed unexpectedly',
        'terminating connection due to administrator command',
        'could not receive data from server',
        'could not send data to server',
        'connection to server was lost',
    ];

    private function __construct() {}

    /**
     * `database`, `redis` o `null` si la excepcion no es de una dependencia.
     */
    public static function dependencyOf(Throwable $failure): ?string
    {
        for ($current = $failure; $current instanceof Throwable; $current = $current->getPrevious()) {
            if ($current instanceof RedisException || is_a($current, 'Predis\Connection\ConnectionException')) {
                return self::REDIS;
            }

            if ($current instanceof DatabaseUnavailable || ($current instanceof PDOException && self::isLostConnection($current))) {
                return self::DATABASE;
            }
        }

        return null;
    }

    private static function isLostConnection(PDOException $failure): bool
    {
        $sqlState = \is_array($failure->errorInfo) && \is_string($failure->errorInfo[0] ?? null)
            ? $failure->errorInfo[0]
            : (string) $failure->getCode();

        if (\in_array($sqlState, self::LOST_SQLSTATES, true)) {
            return true;
        }

        $message = $failure->getMessage();

        return DatabaseUnavailable::isUnreachable($failure)
            || array_any(self::LOST_MESSAGES, static fn (string $fragment): bool => str_contains($message, $fragment));
    }
}
