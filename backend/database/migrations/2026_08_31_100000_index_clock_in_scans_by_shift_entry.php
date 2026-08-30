<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * `scan_events_clock_in_shift_entry_index` — el quiosco de origen de un turno
 * abierto, en O(log n) (RF-PA-01, tarea 2.4).
 *
 * ## Que problema resuelve
 *
 * El panel de presencia enseña **donde** ficho cada persona que esta dentro, y
 * ese dato no vive en `shift_entries`: vive en el `scan_events` que abrio el
 * tramo (`shift_entry_id`, `result = 'clock_in'`). PostgreSQL **no crea indice
 * para una clave ajena**, asi que sin esto la consulta de presencia recorre
 * `scan_events` entera —millones de filas tras un par de años— para pintar una
 * columna de una pantalla que se mira en el cambio de turno. Es el momento del
 * dia en el que esa misma base de datos atiende el camino critico del fichaje
 * (RNF-P-02).
 *
 * ## Parcial y con la marca dentro
 *
 * Solo entran las filas `clock_in`, que son una fraccion de la tabla: los
 * rechazos, los cierres y los eventos de pausa no responden nunca a esta
 * pregunta. `occurred_at DESC` como segunda columna deja el `LATERAL ... ORDER
 * BY occurred_at DESC LIMIT 1` resuelto por el propio indice, sin ordenacion
 * posterior.
 *
 * Sirve ademas para lo de siempre con una clave ajena sin indice: al borrar o
 * actualizar una fila de `shift_entries`, PostgreSQL ya no tiene que escanear
 * `scan_events` para comprobar la integridad referencial.
 *
 * ## `CONCURRENTLY`, y con las consecuencias asumidas
 *
 * `scan_events` es la tabla que mas crece del producto y **tiene datos en toda
 * instalacion ya desplegada**: un `CREATE INDEX` normal toma `SHARE` y bloquea
 * las escrituras, es decir, deja de aceptar fichajes mientras dura (regla dura
 * 19, RNF-D-04). Con `CONCURRENTLY` no bloquea, a cambio de dos cosas que hay
 * que aceptar por escrito:
 *
 *   - **La migracion deja de ser atomica** (`$withinTransaction = false`):
 *     `CREATE INDEX CONCURRENTLY` no puede ejecutarse dentro de una transaccion.
 *   - **Si falla a mitad, el indice queda `INVALID`** y hay que borrarlo a mano
 *     antes de reintentar. Por eso el `up()` usa `IF NOT EXISTS`: un reintento
 *     tras un `DROP INDEX` no choca, y uno sin borrar tampoco rompe el
 *     despliegue. El sintoma se ve con
 *     `SELECT indexrelid::regclass FROM pg_index WHERE NOT indisvalid`.
 *
 * **Sin `statement_timeout`, y es deliberado.** El trait
 * `App\Support\Database\LimitsMigrationLocks` acota toda sentencia a 30 s, que
 * es lo correcto para un `ALTER TABLE` y lo peor posible para una construccion
 * concurrente: un indice sobre millones de filas tarda mas, y abortarlo a los 30
 * s es exactamente como se produce el indice `INVALID` que se acaba de describir.
 * `lock_timeout` si se establece —y bajo—: la construccion concurrente espera al
 * final a que terminen las transacciones abiertas, y ahi si conviene rendirse
 * pronto en lugar de encolar bloqueos detras.
 */
return new class extends Migration
{
    /**
     * `CREATE INDEX CONCURRENTLY` no se ejecuta dentro de una transaccion.
     *
     * @var bool
     */
    public $withinTransaction = false;

    private const string INDEX = 'scan_events_clock_in_shift_entry_index';

    public function up(): void
    {
        $this->limitLockWaitOnly();

        // El nombre del indice es una constante de esta clase y nunca entrada
        // externa: PostgreSQL no admite parametros enlazados en un identificador.
        DB::statement(
            'CREATE INDEX CONCURRENTLY IF NOT EXISTS '.self::INDEX
            ." ON scan_events (shift_entry_id, occurred_at DESC) WHERE result = 'clock_in'"
        );
    }

    public function down(): void
    {
        $this->limitLockWaitOnly();

        // Tambien `CONCURRENTLY`: borrar un indice toma `ACCESS EXCLUSIVE` sobre
        // la tabla, y una vuelta atras no puede parar los fichajes mas de lo que
        // los paro la ida.
        DB::statement('DROP INDEX CONCURRENTLY IF EXISTS '.self::INDEX);
    }

    /**
     * Solo `lock_timeout`. Ver el porque en el docblock de la clase.
     */
    private function limitLockWaitOnly(): void
    {
        DB::connection($this->getConnection())->statement("SET lock_timeout = '3s'");
    }
};
