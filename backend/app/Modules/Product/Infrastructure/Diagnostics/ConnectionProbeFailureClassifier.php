<?php

declare(strict_types=1);

namespace App\Modules\Product\Infrastructure\Diagnostics;

use App\Modules\Product\Application\Port\ProbeFailureClassifier;
use PDOException;
use RedisException;
use Throwable;

/**
 * Reconoce las excepciones de CONEXION de los dos servicios de los que dependen
 * las sondas (PR2).
 *
 * - **Redis**: `RedisException` de `phpredis`, el cliente del producto. La de
 *   Predis se nombra como texto para no depender de un paquete que no se instala.
 * - **PostgreSQL**: un `PDOException` —`QueryException` lo es— con un SQLSTATE de
 *   la clase `08`, que es la de «connection exception» del estandar SQL. Un
 *   error de sintaxis o de permisos NO entra: ese si es un fallo del producto.
 *
 * Se recorre la cadena de `previous` porque el framework envuelve a menudo la
 * excepcion del cliente en la suya.
 */
final readonly class ConnectionProbeFailureClassifier implements ProbeFailureClassifier
{
    #[\Override]
    public function unavailableService(Throwable $failure): ?string
    {
        for ($current = $failure; $current instanceof Throwable; $current = $current->getPrevious()) {
            if ($current instanceof RedisException || is_a($current, 'Predis\Connection\ConnectionException')) {
                return self::REDIS;
            }

            if ($current instanceof PDOException && str_starts_with(self::sqlState($current), '08')) {
                return self::DATABASE;
            }
        }

        return null;
    }

    private static function sqlState(PDOException $failure): string
    {
        $state = $failure->errorInfo[0] ?? $failure->getCode();

        return \is_scalar($state) ? (string) $state : '';
    }
}
