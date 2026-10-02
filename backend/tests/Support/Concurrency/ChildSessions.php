<?php

declare(strict_types=1);

namespace Tests\Support\Concurrency;

use Illuminate\Support\Facades\DB;
use RuntimeException;
use Throwable;

/**
 * Las sesiones de PostgreSQL que abren los procesos hijos de las pruebas de
 * concurrencia: que se cierran, y que el padre no sigue hasta que no queda
 * ninguna (bloque 17 de la 2.2.0; fallo de la etapa ④ de la CI, run
 * 37067003596).
 *
 * ## El fallo que existe para evitar
 *
 * Las tandas de `OffboardUpdateRaceTest` lanzan cientos de procesos hijos en
 * pocos segundos. Cada hijo abre su sesion; si al terminar no la cierra —
 * `ParallelRequests` lo mata con `SIGKILL` y el servidor solo se entera cuando
 * nota el socket cerrado, que a traves del proxy de puertos de Docker del
 * runner puede tardar—, las sesiones se acumulan hasta llenar
 * `max_connections` (100, con 3 reservadas para superusuarios). La prueba que
 * rompe entonces es **la siguiente**, con `remaining connection slots are
 * reserved for roles with the SUPERUSER attribute`, y nada en su mensaje apunta
 * a la causa.
 *
 * ## Dos mitades
 *
 * - **El hijo cierra lo suyo** ({@see self::closeAll()}) antes de salir: el
 *   cliente manda el mensaje de terminacion y el servidor libera la sesion en el
 *   acto, sin depender de cuando note el socket.
 * - **El padre espera** ({@see self::waitUntilGone()}) a que no quede en la base
 *   de pruebas ninguna sesion que no sea suya; pasado el tope, termina las que
 *   queden con `pg_terminate_backend` y, si ni asi, falla diciendo cuantas y en
 *   que estado, en vez de dejar que rompan la prueba siguiente.
 */
final class ChildSessions
{
    /** Tope de la espera, en segundos. Una sesion cerrada desaparece en milisegundos. */
    private const float WAIT_SECONDS = 5.0;

    private const int POLL_MICROSECONDS = 20_000;

    /**
     * Cierra todas las conexiones de este proceso. La llama el hijo justo antes
     * de salir; si una ya estaba rota, no importa.
     */
    public static function closeAll(): void
    {
        foreach (array_keys(DB::getConnections()) as $name) {
            try {
                DB::disconnect((string) $name);
            } catch (Throwable) {
                // Ya cerrada: es lo que se queria.
            }
        }
    }

    /**
     * Espera a que no quede en la base de pruebas ninguna sesion de otro proceso.
     * Las conexiones abiertas de este proceso no cuentan.
     */
    public static function waitUntilGone(): void
    {
        $limit = microtime(true) + self::WAIT_SECONDS;

        while (self::foreign() !== []) {
            if (microtime(true) >= $limit) {
                self::terminateLeftovers();

                return;
            }

            usleep(self::POLL_MICROSECONDS);
        }
    }

    /**
     * Las sesiones ajenas que quedan en la base de pruebas: pid y estado.
     *
     * @return list<array{pid: int, state: string}>
     */
    public static function foreign(): array
    {
        $own = self::ownPids();

        /** @var list<object{pid: int|string, state: string|null}> $rows */
        $rows = DB::select(
            "SELECT pid, state FROM pg_stat_activity
              WHERE datname = current_database()
                AND backend_type = 'client backend'",
        );

        $foreign = [];

        foreach ($rows as $row) {
            if (! \in_array((int) $row->pid, $own, true)) {
                $foreign[] = ['pid' => (int) $row->pid, 'state' => $row->state ?? 'desconocido'];
            }
        }

        return $foreign;
    }

    /**
     * Los pid de servidor de las conexiones que este proceso tiene abiertas.
     *
     * @return list<int>
     */
    private static function ownPids(): array
    {
        $pids = [];

        foreach (DB::getConnections() as $connection) {
            try {
                $pid = $connection->selectOne('SELECT pg_backend_pid() AS pid');
                $pids[] = (int) (\is_object($pid) && isset($pid->pid) ? $pid->pid : 0);
            } catch (Throwable) {
                // Una conexion rota no tiene sesion que proteger.
            }
        }

        return $pids;
    }

    /**
     * Termina las sesiones que siguen vivas pasado el tope. El rol de pruebas
     * puede terminar las de su mismo rol; si alguna no se deja, se falla con el
     * detalle.
     */
    private static function terminateLeftovers(): void
    {
        foreach (self::foreign() as $session) {
            try {
                DB::select('SELECT pg_terminate_backend(?)', [$session['pid']]);
            } catch (Throwable) {
                // Sin permiso sobre esa sesion: lo dice el mensaje de abajo.
            }
        }

        $deadline = microtime(true) + 1.0;

        while (($left = self::foreign()) !== []) {
            if (microtime(true) >= $deadline) {
                $states = array_count_values(array_column($left, 'state'));
                ksort($states);

                throw new RuntimeException(sprintf(
                    'Quedan %d sesion(es) de procesos hijos en la base de pruebas tras %.0f s y pg_terminate_backend: %s. '
                    .'Romperian la prueba siguiente por max_connections.',
                    \count($left),
                    self::WAIT_SECONDS,
                    json_encode($states, JSON_THROW_ON_ERROR),
                ));
            }

            usleep(self::POLL_MICROSECONDS);
        }
    }
}
