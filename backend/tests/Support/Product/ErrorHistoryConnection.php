<?php

declare(strict_types=1);

namespace Tests\Support\Product;

use Illuminate\Support\Facades\DB;

/**
 * Hace que la conexion `error_events` comparta la **sesion** de la conexion por
 * defecto mientras dura una prueba (RF-PD-15, tarea 5.12).
 *
 * ## Por que hace falta
 *
 * En produccion, el historico de errores se escribe por una conexion propia:
 * otra sesion de PostgreSQL contra la misma base y con el mismo rol, para que el
 * `INSERT` del error no viva dentro de la transaccion que acaba de fallar
 * —donde se revertiria con ella— ni choque con un `25P02` si esa transaccion ya
 * esta abortada (decision 6 de la ficha, comentario largo en
 * `config/database.php`).
 *
 * Esa propiedad, que es justamente la que se quiere, choca con `RefreshDatabase`:
 * el framework envuelve **solo la conexion por defecto** en una transaccion que
 * revierte al terminar. Sin este puente pasarian las dos cosas malas a la vez:
 *
 *  1. Una prueba que escribe un error por el endpoint y lo busca con
 *     `DB::table('error_events')` **no lo veria**, porque estaria confirmado en
 *     otra sesion mientras la suya sigue abierta — o al reves, segun el orden.
 *  2. Lo que si quedara confirmado **sobrevive a la prueba** y contamina a las
 *     siguientes, que es la peor forma de fallar: la suite pasa sola y falla
 *     encadenada.
 *
 * ## Que hace exactamente, y que NO hace
 *
 * Apunta el PDO de la conexion `error_events` al de la conexion por defecto. El
 * **grafo del contenedor no se toca**: el repositorio se sigue construyendo con
 * dos objetos `Connection` distintos, resueltos por el proveedor como en
 * produccion, y lo unico compartido es el socket. Sustituir el enlace por uno de
 * una sola conexion habria dejado sin probar precisamente el cableado que la
 * decision 6 introduce.
 *
 * ## Donde NO se usa
 *
 * En las pruebas de integracion que corren con `CommittedDatabase` —la de
 * concurrencia y la de purga—, que no abren transaccion envolvente y usan las
 * dos sesiones de verdad, que es como corre el producto.
 */
final class ErrorHistoryConnection
{
    /** El nombre de la conexion de escritura, el mismo de `config/database.php`. */
    public const string NAME = 'error_events';

    /**
     * Se llama en el `beforeEach`, **despues** de que `RefreshDatabase` haya
     * abierto su transaccion: a partir de ahi todo lo que escriba el historico
     * entra en ella y se revierte con la prueba.
     *
     * ## Y ademas vacia la tabla, que no es un detalle
     *
     * Cualquier prueba de la suite —una de captacion, una que provoque un `500`
     * a proposito— escribe en `error_events` **por la conexion propia**, o sea
     * fuera de la transaccion de su prueba, y esas filas se quedan. Es la
     * consecuencia inevitable de la decision 6: el historico existe justamente
     * para sobrevivir a la transaccion que fallo.
     *
     * El `DELETE` va **dentro** de la transaccion de la prueba, asi que no borra
     * nada de forma permanente —al revertir, lo que hubiera vuelve— y cada
     * prueba que cuenta filas parte de cero. Sin esto, una afirmacion como «el
     * paquete lleva un grupo» pasa sola y falla cuando la suite se ejecuta
     * entera, que es la peor forma de fallar que hay.
     */
    public static function shareTestTransaction(): void
    {
        /*
         * SOLO CON `RefreshDatabase` DELANTE, y de ahi la guarda.
         *
         * Se llama desde el `beforeEach` global de `tests/Pest.php` para toda la
         * suite `Feature`, y ahi hay pruebas que no abren transaccion —las de
         * arranque, las sondas de salud— y alguna que ni toca la base de datos.
         * Sin la guarda, este metodo forzaria una conexion en cada una de ellas
         * y, peor, el `DELETE` seria **permanente** en las que no envuelven.
         *
         * Con `transactionLevel() > 0` la condicion dice exactamente lo que hace
         * falta: hay una transaccion de prueba que va a revertir, asi que
         * compartir la sesion es seguro y el vaciado es temporal.
         */
        if (DB::connection()->transactionLevel() < 1) {
            return;
        }

        DB::connection(self::NAME)->setPdo(DB::connection()->getPdo());

        DB::table('error_events')->delete();
    }

    /**
     * Deshace el puente al terminar la prueba. **Se llama SIEMPRE**, tambien
     * cuando `shareTestTransaction()` no llego a hacer nada.
     *
     * ## Por que hace falta, y que rompia sin esto
     *
     * `setPdo()` deja la conexion `error_events` **agarrada a un PDO que no es
     * suyo**: el de la conexion por defecto de esa prueba. En cuanto esa prueba
     * termina, `RefreshDatabase` revierte su transaccion y desconecta la
     * conexion por defecto — y el objeto `error_events`, que sigue vivo dentro
     * del `DatabaseManager`, se queda con un descriptor muerto para el resto del
     * proceso.
     *
     * El sintoma no aparecia donde estaba la causa: la prueba siguiente que
     * usara `CommittedDatabase` —que recrea el esquema y no envuelve en
     * transaccion— fallaba con `SQLSTATE[42P01] relation "error_events" does not
     * exist`, y arrastraba a las que venian detras. En aislado pasaban todas.
     * Es la peor forma de fallar que hay: una prueba verde sola y roja en la
     * suite, con el error a varios ficheros de distancia de quien lo provoco.
     *
     * `purge()` y no `disconnect()`: el segundo cierra el PDO pero **deja el
     * objeto en el gestor**, asi que la siguiente prueba seguiria trabajando
     * sobre la misma instancia contaminada. `purge()` la saca, y la proxima
     * resolucion construye una limpia desde `config/database.php`.
     */
    public static function release(): void
    {
        DB::purge(self::NAME);
    }
}
