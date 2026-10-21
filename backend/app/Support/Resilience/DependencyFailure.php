<?php

declare(strict_types=1);

namespace App\Support\Resilience;

use App\Support\Database\DatabaseUnavailable;
use App\Support\Redis\RedisCircuitOpen;
use Illuminate\Database\QueryException;
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
 *   mitad de una sentencia, del `COMMIT` o del `ROLLBACK`: SQLSTATE `08000`,
 *   `08003`, `08006` o `57P01`-`57P03`, o los mensajes de libpq de conexion
 *   perdida. Una conexion perdida no abre el circuito por si sola: lo abre el
 *   intento de conexion siguiente si el servidor no esta (un
 *   `pg_terminate_backend` no puede dejar diez segundos de `503` en toda la
 *   instalacion).
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

    /**
     * Los SQLSTATE con los que pdo_pgsql entrega un fallo **sin** codigo del
     * servidor: `HY000` («General error: 7 …») es como llega una conexion que se
     * cae. Con cualquier otro SQLSTATE el servidor contesto, asi que la conexion
     * seguia viva y el mensaje no se mira.
     */
    private const array GENERIC_SQLSTATES = ['HY000', '00000'];

    /**
     * Lo que escribe libpq cuando la conexion se pierde a mitad de camino.
     *
     * **Lista propia, y no el `LostConnectionDetector` de Laravel**, por dos
     * motivos. El de Laravel es para decidir si **reconectar**, y mezcla cosas
     * que aqui no son una dependencia caida: `Access denied for user` (una
     * contraseña mal puesta es un defecto de configuracion que tiene que seguir
     * diciendolo, no un `503` mudo), `Resource deadlock avoided`, `Read only sql
     * transaction`, `No such file or directory`, y los de MySQL, SQL Server,
     * Oracle o Vitess, que esta instalacion no tiene. Y casa contra
     * `getMessage()` de lo que le den, que en una `QueryException` es el SQL con
     * los valores ya sustituidos. Esta lista es solo libpq, y solo se busca en el
     * mensaje del driver (ver {@see self::isLostConnection()}).
     */
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

    /**
     * Si la averia **ya consta** por su cortacircuitos y no hay que informarla
     * otra vez (ADR-059 §2): PostgreSQL inalcanzable o perdido, que no se puede
     * escribir en `error_events` y consta como `database.circuit_opened`, y el
     * circuito de Redis abierto, que consta como `redis.circuit_opened`. La
     * primera `RedisException`, la que abre el circuito, **no**: su fila es la
     * de la averia.
     *
     * Es el unico predicado: lo usan el manejador de excepciones
     * (`dontReportWhen` en `bootstrap/app.php`) y el historico de los fallos
     * capturados (`CaughtFailureHistory`). Dos copias acabarian discrepando, y el
     * lote de escaneos escribiria una fila por elemento durante una caida.
     */
    public static function isOnRecordThroughItsCircuit(Throwable $failure): bool
    {
        return self::dependencyOf($failure) === self::DATABASE || RedisCircuitOpen::isCauseOf($failure);
    }

    /**
     * La conexion perdida. El SQLSTATE vale en cualquier eslabon; el mensaje,
     * **solo en la `PDOException` del driver** y con un SQLSTATE generico.
     *
     * Una `QueryException` es una `PDOException` cuyo mensaje es el del driver
     * mas el SQL **con los valores enlazados sustituidos**: un nombre de
     * departamento que dijera «no connection to the server» convertiria un `409`
     * en un `503`. Su SQLSTATE es el del driver, asi que se lee; su mensaje no.
     * El driver va detras, en `getPrevious()`, y el bucle de
     * {@see self::dependencyOf()} llega a el.
     */
    private static function isLostConnection(PDOException $failure): bool
    {
        $sqlState = \is_array($failure->errorInfo) && \is_string($failure->errorInfo[0] ?? null)
            ? $failure->errorInfo[0]
            : (string) $failure->getCode();

        if (\in_array($sqlState, self::LOST_SQLSTATES, true)) {
            return true;
        }

        // Un SQLSTATE de cinco caracteres que no es generico: el servidor
        // contesto, y la conexion seguia viva. Un fallo al conectar trae el
        // codigo de libpq (`7`) y no un SQLSTATE: ese si se mira por el mensaje.
        $serverAnswered = preg_match('/^[0-9A-Z]{5}$/', $sqlState) === 1
            && ! \in_array($sqlState, self::GENERIC_SQLSTATES, true);

        if ($failure instanceof QueryException || $serverAnswered) {
            return false;
        }

        $message = $failure->getMessage();

        return DatabaseUnavailable::isUnreachable($failure)
            || array_any(self::LOST_MESSAGES, static fn (string $fragment): bool => str_contains($message, $fragment));
    }
}
