<?php

declare(strict_types=1);

namespace App\Support\Database;

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Los dos topes que toda migracion de este producto establece antes de tocar el
 * esquema (skill `/migracion-segura`, doc 02 §10.4).
 *
 * **Por que existe.** Con 500 personas fichando en un cambio de turno, una
 * migracion que se queda esperando un bloqueo no es lenta: es una interrupcion
 * del registro horario. `lock_timeout` hace que la migracion se rinda en 3 s en
 * lugar de encolarse detras de una transaccion larga y bloquear a su vez a todo
 * el que llegue despues —una cola de bloqueos en PostgreSQL se propaga hacia
 * atras—. `statement_timeout` acota la otra mitad: una sentencia que ya obtuvo
 * el bloqueo pero tarda mas de lo previsto.
 *
 * **Fallar rapido es el comportamiento deseado.** Una migracion que aborta se
 * reintenta en una ventana mas tranquila; una que bloquea el fichaje media hora
 * no se puede deshacer.
 *
 * En la migracion inicial las tablas nacen vacias y ningun tope llega a
 * activarse. Se establecen igual porque la plantilla de migraciones tiene que
 * ser la correcta desde la primera: la segunda migracion del producto ya cae
 * sobre datos de un cliente.
 *
 * **Indices sobre tablas con datos.** Este trait no los cubre y no puede
 * cubrirlos: `CREATE INDEX CONCURRENTLY` no se ejecuta dentro de una
 * transaccion, asi que la migracion que lo use debe declarar ademas
 * `public $withinTransaction = false;` y asumir que deja de ser atomica —si
 * falla, el indice queda `INVALID` y hay que borrarlo a mano—. Por eso **no se
 * usa en el esquema inicial**, donde toda tabla nace vacia y el indice es
 * instantaneo: seria cambiar atomicidad por nada.
 *
 * @phpstan-require-extends Migration
 */
trait LimitsMigrationLocks
{
    /** Espera maxima en la cola de bloqueos antes de rendirse (skill `/migracion-segura`). */
    private const string LOCK_TIMEOUT = '3s';

    /** Duracion maxima de una sentencia de la migracion. */
    private const string STATEMENT_TIMEOUT = '30s';

    /**
     * Se llama al principio de `up()` y de `down()`, sobre la conexion de la
     * propia migracion y no sobre la conexion por defecto: un `SET` aplicado a
     * otra sesion no protege nada.
     */
    protected function limitLockWait(): void
    {
        $connection = DB::connection($this->getConnection());

        // Los valores no van como parametro enlazado a proposito: `SET` no
        // admite parametros en PostgreSQL. Son constantes de esta clase, nunca
        // entrada externa.
        $connection->statement("SET lock_timeout = '".self::LOCK_TIMEOUT."'");
        $connection->statement("SET statement_timeout = '".self::STATEMENT_TIMEOUT."'");
    }
}
