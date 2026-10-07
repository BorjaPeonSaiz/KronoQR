<?php

declare(strict_types=1);

namespace App\Support\Database;

use Closure;
use Illuminate\Database\Connection;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use LogicException;
use RuntimeException;

/**
 * Los topes de espera y de duracion que toda migracion de este producto
 * establece antes de tocar el esquema (skill `/migracion-segura`, doc 02
 * §10.4).
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
 * ## Los ayudantes, uno por forma de migracion
 *
 * - {@see limitLockWait()} — la migracion **transaccional** de siempre. Los dos
 *   topes van con `SET LOCAL` y mueren con la transaccion. Hasta la 2.2.0 eran
 *   `SET` de sesion: sobrevivian al `COMMIT` y los heredaba la siguiente
 *   migracion del mismo `artisan migrate` —incluida la de un `CREATE INDEX
 *   CONCURRENTLY`, que a los 30 s quedaba `INVALID`— (hallazgo DB4, R5-BD-02).
 *   `SET LOCAL` fuera de transaccion no hace nada salvo avisar, asi que el
 *   ayudante **lanza** si no la hay en lugar de dejar la migracion sin topes.
 * - {@see withLockWaitOnly()} — la migracion **no transaccional**
 *   (`public $withinTransaction = false;`): solo `lock_timeout`, **sin tope de
 *   duracion**, y la sesion vuelve a sus valores al terminar aunque el closure
 *   lance. Es lo que necesita `CREATE INDEX CONCURRENTLY`: una construccion
 *   concurrente sobre millones de filas tarda mas de 30 s, y abortarla es
 *   exactamente como se produce un indice `INVALID`; en cambio, al final espera
 *   a que terminen las transacciones abiertas, y ahi si conviene rendirse
 *   pronto.
 * - {@see validateConstraint()} — el `VALIDATE CONSTRAINT` de una restriccion
 *   que entro `NOT VALID`, **fuera de la transaccion** que la creo (hallazgo
 *   DB3, R5-BD-01). Dentro de ella, el `ACCESS EXCLUSIVE` que tomo el `ADD
 *   CONSTRAINT` se mantiene durante todo el recorrido del `VALIDATE` y el patron
 *   en dos pasos no sirve de nada: la tabla queda bloqueada igual que con un
 *   `ADD CONSTRAINT` de golpe. Fuera, el `VALIDATE` toma solo `SHARE UPDATE
 *   EXCLUSIVE`, que no bloquea lecturas ni escrituras.
 * - {@see createIndexConcurrently()} y {@see dropIndexConcurrently()} — el
 *   `CREATE INDEX CONCURRENTLY` y su vuelta atras, sobre
 *   {@see withLockWaitOnly()}. Una construccion concurrente interrumpida deja
 *   el indice `INVALID`, y `IF NOT EXISTS` lo da por bueno en el reintento: la
 *   migracion quedaria anotada con un indice que se mantiene en cada escritura
 *   y no sirve a ninguna lectura (revision del bloque 13 de la 2.2.0). El
 *   ayudante borra el `INVALID` antes de construir y comprueba `indisvalid`
 *   despues. Una migracion no escribe `CREATE INDEX CONCURRENTLY` a mano: lo
 *   impide `tests/Architecture/MigrationSafetyTest.php`.
 *
 * ## La forma de una migracion con `VALIDATE`
 *
 * ```php
 * public $withinTransaction = false;
 *
 * public function up(): void
 * {
 *     DB::transaction(function (): void {
 *         $this->limitLockWait();
 *         // ADD COLUMN, DROP CONSTRAINT, ADD CONSTRAINT ... NOT VALID
 *     });
 *
 *     $this->validateConstraint('tabla', 'restriccion');
 * }
 * ```
 *
 * **Lo que se acepta a cambio.** La migracion deja de ser atomica: si el
 * `VALIDATE` falla o se interrumpe, el cambio de la transaccion ya esta
 * confirmado y la restriccion queda `NOT VALID` —aplicada a toda escritura
 * nueva, sin comprobar sobre las filas antiguas—. No hay nada que borrar a mano:
 * repetir el `VALIDATE` (o la migracion, si se escribe idempotente) la termina.
 * Por lo mismo, un `down()` que confiaba en que el `VALIDATE` fallara para
 * «no hacer nada» tiene que **comprobar antes de tocar el esquema** que ninguna
 * fila viola el catalogo al que vuelve, y detenerse si la hay (regla dura 5).
 * La prueba `tests/Architecture/MigrationSafetyTest.php` falla si una migracion
 * nueva escribe un `VALIDATE CONSTRAINT` literal o usa {@see validateConstraint()}
 * sin declararse no transaccional.
 *
 * ## Lo que no se toca
 *
 * Las migraciones publicadas hasta la 2.1.0 con `NOT VALID` + `VALIDATE` en la
 * misma transaccion **se quedan como estan**: ya se aplicaron en toda
 * instalacion desplegada —reescribirlas no cambiaria nada en ninguna— y en una
 * instalacion nueva corren sobre tablas vacias, donde el `VALIDATE` es
 * instantaneo. La lista cerrada vive en la prueba citada arriba.
 *
 * En la migracion inicial las tablas nacen vacias y ningun tope llega a
 * activarse. Se establecen igual porque la plantilla de migraciones tiene que
 * ser la correcta desde la primera: la segunda migracion del producto ya cae
 * sobre datos de un cliente.
 *
 * @phpstan-require-extends Migration
 */
trait LimitsMigrationLocks
{
    /** Espera maxima en la cola de bloqueos antes de rendirse (skill `/migracion-segura`). */
    private const string LOCK_TIMEOUT = '3s';

    /** Duracion maxima de una sentencia de una migracion transaccional. */
    private const string STATEMENT_TIMEOUT = '30s';

    /**
     * Los dos topes, **solo para la transaccion en curso** (`SET LOCAL`).
     *
     * Se llama al principio de `up()` y de `down()` —o del `DB::transaction()`
     * de una migracion no transaccional—, sobre la conexion de la propia
     * migracion y no sobre la conexion por defecto: un `SET` aplicado a otra
     * sesion no protege nada.
     *
     * @throws LogicException si no hay transaccion abierta: `SET LOCAL` no haria
     *                        nada y la migracion correria sin topes.
     */
    protected function limitLockWait(): void
    {
        $connection = $this->lockLimitedConnection();

        if ($connection->transactionLevel() === 0) {
            throw new LogicException(
                'limitLockWait() usa SET LOCAL y necesita una transaccion abierta. En una migracion con '
                .'$withinTransaction = false, llamalo dentro de DB::transaction(), o usa withLockWaitOnly() '
                .'para un CREATE INDEX CONCURRENTLY y validateConstraint() para un VALIDATE CONSTRAINT.'
            );
        }

        // Los valores no van como parametro enlazado a proposito: `SET` no
        // admite parametros en PostgreSQL. Son constantes de esta clase, nunca
        // entrada externa.
        $connection->statement("SET LOCAL lock_timeout = '".self::LOCK_TIMEOUT."'");
        $connection->statement("SET LOCAL statement_timeout = '".self::STATEMENT_TIMEOUT."'");
    }

    /**
     * Ejecuta `$statements` con `lock_timeout` y **sin** `statement_timeout`, y
     * deja la sesion como estaba al terminar, tambien si lanza.
     *
     * Para migraciones con `$withinTransaction = false`. Lo que se restaura es
     * el valor que tenia la sesion antes —no el de serie—, con `set_config`, que
     * si admite parametros enlazados.
     *
     * @template TResult
     *
     * @param  Closure(): TResult  $statements
     * @return TResult
     *
     * @throws LogicException si hay una transaccion abierta: el `SET` de sesion
     *                        se confundiria con ella y lo que este ayudante
     *                        sirve —`CONCURRENTLY`, `VALIDATE`— no se puede
     *                        ejecutar ahi.
     */
    protected function withLockWaitOnly(Closure $statements): mixed
    {
        $connection = $this->lockLimitedConnection();

        $this->assertOutsideTransaction($connection, 'withLockWaitOnly()');

        $previous = [
            'lock_timeout' => $this->currentSetting($connection, 'lock_timeout'),
            'statement_timeout' => $this->currentSetting($connection, 'statement_timeout'),
        ];

        try {
            $connection->statement("SET lock_timeout = '".self::LOCK_TIMEOUT."'");
            // Explicito y no por omision: si la sesion trae un tope de duracion
            // —del rol, de la base o de un ayudante anterior—, una construccion
            // concurrente lo heredaria y quedaria `INVALID` al cumplirse.
            $connection->statement('SET statement_timeout = 0');

            return $statements();
        } finally {
            foreach ($previous as $setting => $value) {
                $connection->select('SELECT set_config(?, ?, false)', [$setting, $value]);
            }
        }
    }

    /**
     * `CREATE INDEX CONCURRENTLY IF NOT EXISTS`, que nunca deja anotada la
     * migracion con un indice `INVALID`.
     *
     * 1. Si ya existe un indice con ese nombre y `NOT indisvalid` —una
     *    construccion anterior interrumpida: el `lock_timeout` de la espera
     *    final a las transacciones abiertas, una cancelacion, una caida—, se
     *    borra con `DROP INDEX CONCURRENTLY`. Sin esto, `IF NOT EXISTS` lo
     *    saltaria y el reintento daria por terminado un indice que el
     *    planificador nunca usa y que cada escritura sigue manteniendo.
     * 2. Se construye con `IF NOT EXISTS`: repetir la migracion tras un fallo
     *    en el segundo indice no choca con el primero, que ya es valido.
     * 3. Se comprueba `indisvalid` y, si no lo es, se lanza: la migracion no
     *    queda anotada y el siguiente `migrate` vuelve a entrar por el paso 1.
     *
     * Todo con {@see withLockWaitOnly()}: `lock_timeout` y sin tope de duracion.
     *
     * @param  string  $index  identificador simple; constante de la migracion
     * @param  string  $definition  lo que va despues del nombre —`ON tabla (...)
     *                              [WHERE ...]`—; constante de la migracion,
     *                              nunca entrada externa
     *
     * @throws LogicException si hay una transaccion abierta o el identificador
     *                        no es simple
     * @throws RuntimeException si el indice no queda valido
     */
    protected function createIndexConcurrently(string $index, string $definition): void
    {
        $connection = $this->lockLimitedConnection();

        $this->assertOutsideTransaction($connection, 'createIndexConcurrently()');
        $this->assertSimpleIdentifier($index, 'createIndexConcurrently()');

        if (preg_match('/\AON\s/', $definition) !== 1) {
            throw new LogicException('La definicion de '.$index.' tiene que empezar por «ON tabla».');
        }

        $this->withLockWaitOnly(function () use ($connection, $index, $definition): void {
            if ($this->indexValidity($connection, $index) === false) {
                $connection->statement('DROP INDEX CONCURRENTLY IF EXISTS '.$index);
            }

            $connection->statement('CREATE INDEX CONCURRENTLY IF NOT EXISTS '.$index.' '.$definition);

            if ($this->indexValidity($connection, $index) !== true) {
                throw new RuntimeException(
                    'El indice '.$index.' no ha quedado valido tras CREATE INDEX CONCURRENTLY. La migracion no se ha '
                    .'anotado: vuelve a ejecutar `php artisan migrate`, que borra el indice invalido y lo reconstruye, '
                    .'preferiblemente fuera de un cambio de turno.'
                );
            }
        });
    }

    /**
     * `DROP INDEX CONCURRENTLY IF EXISTS`, para el `down()` de una migracion de
     * {@see createIndexConcurrently()}.
     *
     * Tambien concurrente: un `DROP INDEX` normal toma `ACCESS EXCLUSIVE` sobre
     * la tabla, y una vuelta atras no puede parar los fichajes mas de lo que
     * los paro la ida.
     *
     * @throws LogicException si hay una transaccion abierta o el identificador
     *                        no es simple
     */
    protected function dropIndexConcurrently(string $index): void
    {
        $connection = $this->lockLimitedConnection();

        $this->assertOutsideTransaction($connection, 'dropIndexConcurrently()');
        $this->assertSimpleIdentifier($index, 'dropIndexConcurrently()');

        $this->withLockWaitOnly(
            static fn (): bool => $connection->statement('DROP INDEX CONCURRENTLY IF EXISTS '.$index)
        );
    }

    /**
     * `ALTER TABLE ... VALIDATE CONSTRAINT`, fuera de transaccion y solo con
     * `lock_timeout` (ver el docblock del trait).
     *
     * Sin tope de duracion a proposito: el `VALIDATE` de una tabla con millones
     * de filas tarda lo que tarda, y no bloquea a nadie mientras tanto. Si se
     * interrumpe, la restriccion queda `NOT VALID` y basta con repetirlo.
     *
     * @throws LogicException si hay una transaccion abierta: dentro de ella, el
     *                        `ACCESS EXCLUSIVE` del `ADD CONSTRAINT` duraria
     *                        todo el recorrido.
     */
    protected function validateConstraint(string $table, string $constraint): void
    {
        $connection = $this->lockLimitedConnection();

        $this->assertOutsideTransaction($connection, 'validateConstraint()');

        foreach ([$table, $constraint] as $identifier) {
            $this->assertSimpleIdentifier($identifier, 'validateConstraint()');
        }

        $this->withLockWaitOnly(
            static fn (): bool => $connection->statement('ALTER TABLE '.$table.' VALIDATE CONSTRAINT '.$constraint)
        );
    }

    private function lockLimitedConnection(): Connection
    {
        return DB::connection($this->getConnection());
    }

    private function assertOutsideTransaction(Connection $connection, string $helper): void
    {
        if ($connection->transactionLevel() !== 0) {
            throw new LogicException(
                $helper.' se ejecuta fuera de transaccion: la migracion tiene que declarar '
                .'$withinTransaction = false y llamarlo fuera de DB::transaction().'
            );
        }
    }

    /**
     * PostgreSQL no admite parametros enlazados en un identificador; los de una
     * migracion son constantes suyas, y se comprueba igual.
     */
    private function assertSimpleIdentifier(string $identifier, string $helper): void
    {
        if (preg_match('/\A[a-z_][a-z0-9_]*\z/', $identifier) !== 1) {
            throw new LogicException('Identificador no admitido en '.$helper.': '.$identifier);
        }
    }

    /**
     * `indisvalid` del indice `$index` del esquema en curso, o `null` si no
     * existe.
     */
    private function indexValidity(Connection $connection, string $index): ?bool
    {
        /** @var list<object{valid: bool}> $rows */
        $rows = $connection->select(<<<'SQL'
            SELECT i.indisvalid AS valid
              FROM pg_index i
              JOIN pg_class c ON c.oid = i.indexrelid
              JOIN pg_namespace n ON n.oid = c.relnamespace
             WHERE c.relname = ?
               AND n.nspname = current_schema()
            SQL, [$index]);

        return isset($rows[0]) ? $rows[0]->valid : null;
    }

    private function currentSetting(Connection $connection, string $setting): string
    {
        /** @var list<object{value: string}> $rows */
        $rows = $connection->select('SELECT current_setting(?) AS value', [$setting]);

        return $rows[0]->value ?? throw new LogicException('PostgreSQL no devolvio '.$setting.'.');
    }
}
