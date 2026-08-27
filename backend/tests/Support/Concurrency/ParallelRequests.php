<?php

declare(strict_types=1);

namespace Tests\Support\Concurrency;

use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Ejecuta N peticiones HTTP **en procesos distintos y a la vez**.
 *
 * ## Por que hacen falta procesos de verdad
 *
 * Los dos escenarios ineludibles del doc 02 §9.4 —diez peticiones paralelas con
 * el mismo `scan_id` y treinta empleados fichando a la vez— no comprueban lo
 * que dicen si se ejecutan en secuencia dentro de un proceso. Lo que se quiere
 * demostrar es que la garantia de idempotencia la da **el UNIQUE de
 * `scan_events.scan_id`** y no el codigo (regla dura 8): diez llamadas seguidas
 * pasarian igual con un `SELECT` previo, que es la implementacion prohibida
 * precisamente por tener condicion de carrera. Con procesos concurrentes, cada
 * uno abre su transaccion y es PostgreSQL quien arbitra.
 *
 * ## La conexion se cierra ANTES de bifurcar
 *
 * `fork()` duplica los descriptores, asi que padre e hijos compartirian el mismo
 * socket a PostgreSQL: dos procesos escribiendo en el mismo flujo del protocolo
 * lo corrompen, y el primero que termine enviaria el `Terminate` de todos. Por
 * eso el padre **se desconecta antes de bifurcar** y cada hijo abre la suya al
 * primer consulta. Laravel reconecta solo, tambien en el padre.
 *
 * ## Como vuelve el resultado
 *
 * Por fichero, uno por hijo, y no por memoria compartida ni por tuberia: un
 * fichero es sincrono, no tiene tamano maximo practico y se puede inspeccionar a
 * mano cuando una de estas pruebas falla, que es cuando mas se agradece.
 */
final class ParallelRequests
{
    /**
     * @param  int  $count  Cuantos procesos.
     * @param  callable(int): TestResponse<Response>  $request
     *                                                          Lo que hace cada hijo, con su indice.
     * @return list<array{status: int, body: mixed}> Resultados en el orden de lanzamiento.
     */
    public static function run(int $count, callable $request): array
    {
        if (! \function_exists('pcntl_fork')) {
            throw new RuntimeException(
                'Las pruebas de concurrencia del doc 02 §9.4 necesitan la extension pcntl. '
                .'Esta instalada en la imagen de desarrollo y en la de la CI.',
            );
        }

        $directory = sys_get_temp_dir().'/kronoqr-parallel-'.bin2hex(random_bytes(6));

        if (! mkdir($directory, 0o700, true) && ! is_dir($directory)) {
            throw new RuntimeException('No se ha podido crear el directorio de resultados '.$directory.'.');
        }

        // Ver el docblock: nadie puede heredar un socket a PostgreSQL.
        //
        // **Todas** las conexiones abiertas, no solo la de por defecto:
        // `DB::purge()` sin argumento cierra unicamente esa, y la de
        // **migracion** —que la suite abre para `migrate:fresh`— se quedaria
        // viva y duplicada en los diez hijos. El sintoma de olvidarla es una
        // prueba posterior que falla con «relation "migrations" does not
        // exist», a varios ficheros de distancia de la causa.
        foreach (array_keys(DB::getConnections()) as $name) {
            DB::purge((string) $name);
        }

        $children = self::spawn($count, $request, $directory);

        foreach ($children as $pid) {
            pcntl_waitpid($pid, $status);
        }

        return self::collect($count, $directory);
    }

    /**
     * @param  callable(int): TestResponse<Response>  $request
     * @return list<int>
     */
    private static function spawn(int $count, callable $request, string $directory): array
    {
        $children = [];

        for ($index = 0; $index < $count; $index++) {
            $pid = pcntl_fork();

            if ($pid === -1) {
                throw new RuntimeException('No se ha podido bifurcar el proceso '.$index.'.');
            }

            if ($pid === 0) {
                self::child($index, $request, $directory);
            }

            $children[] = $pid;
        }

        return $children;
    }

    /**
     * El trabajo de un hijo. **Nunca vuelve.**
     *
     * @param  callable(int): TestResponse<Response>  $request
     */
    private static function child(int $index, callable $request, string $directory): never
    {
        $payload = ['status' => 0, 'body' => null, 'error' => null];

        try {
            $response = $request($index);

            $payload['status'] = $response->getStatusCode();
            $payload['body'] = $response->json();
        } catch (Throwable $failure) {
            // El fallo viaja al padre en lugar de perderse en la salida de un
            // proceso que nadie lee: sin esto, una prueba de concurrencia que
            // falla solo dice «esperaba 10, hubo 3».
            $payload['error'] = $failure::class.': '.$failure->getMessage();
        }

        file_put_contents(
            $directory.'/'.$index.'.json',
            json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        );

        // SIGKILL y no `exit(0)`, y esto no es brusquedad gratuita.
        //
        // El hijo hereda el runner de pruebas entero: los manejadores de apagado
        // de PHP, los de PHPUnit y los `beforeApplicationDestroyed` que el
        // framework tiene registrados para la prueba en curso. Con `exit`, cada
        // uno de los diez hijos ejecuta ese apagado sobre la MISMA base de
        // datos que el padre esta usando —incluido el ciclo de vida de
        // `RefreshDatabase`—, y el resultado es una suite que pasa cuando se
        // ejecuta sola y falla cuando se encadena con otra, que es la peor
        // forma de fallar que existe.
        //
        // El hijo ya ha hecho su unico trabajo —su peticion esta confirmada y su
        // resultado escrito en disco— asi que no queda nada que cerrar
        // limpiamente. PostgreSQL trata la desconexion abrupta como lo que es:
        // una sesion que se va, sin efecto sobre lo ya confirmado.
        posix_kill(posix_getpid(), SIGKILL);

        exit(0);
    }

    /**
     * @return list<array{status: int, body: mixed}>
     */
    private static function collect(int $count, string $directory): array
    {
        $results = [];

        for ($index = 0; $index < $count; $index++) {
            $file = $directory.'/'.$index.'.json';
            $raw = is_file($file) ? file_get_contents($file) : false;

            if ($raw === false) {
                throw new RuntimeException('El proceso '.$index.' no ha dejado resultado.');
            }

            /** @var array{status: int, body: mixed, error: string|null} $decoded */
            $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);

            if ($decoded['error'] !== null) {
                throw new RuntimeException('El proceso '.$index.' fallo con '.$decoded['error']);
            }

            $results[] = ['status' => $decoded['status'], 'body' => $decoded['body']];

            unlink($file);
        }

        rmdir($directory);

        return $results;
    }
}
