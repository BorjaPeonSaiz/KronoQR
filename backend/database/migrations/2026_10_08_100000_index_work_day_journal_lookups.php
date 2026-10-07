<?php

declare(strict_types=1);

use App\Support\Database\LimitsMigrationLocks;
use Illuminate\Database\Migrations\Migration;

/**
 * Los dos indices que sirven el diario de jornadas por indices de punta a punta
 * (RF-PA-03, RNF-P-02; hallazgos DB1 y DB2 de la verificacion de la 2.1.0).
 *
 * ## Que problema resuelve
 *
 * El detalle de un mes —la pantalla del panel y la del portal—
 * (`DatabaseWorkDayJournalReader::journalFor()`) hace dos preguntas a
 * `scan_events` por cada tramo y una a la cadena de versiones:
 *
 * - **`scan_events_shift_entry_id_occurred_at_index`** `(shift_entry_id,
 *   occurred_at)`. Las marcas `recorded_at` de entrada y salida salen de «el
 *   escaneo de ESTE tramo en ESTE instante». Sin el, PostgreSQL usaba
 *   `scan_events_device_id_occurred_at_index` con `Index Cond` solo sobre
 *   `occurred_at` y filtraba `shift_entry_id` a mano —unos 270 buffers por
 *   tramo con el volumen de la verificacion; un `Seq Scan` por tramo con un
 *   solo quiosco—. Sirve tambien el `JOIN scan_events ON shift_entry_id` de
 *   `clockingMarks()`, que hacia `Seq Scan` de la tabla entera.
 *   **Completo y no parcial**: casi toda fila aceptada tiene `shift_entry_id`,
 *   asi que un parcial ahorraria poco y complicaria que el planificador lo
 *   eligiera en el `JOIN` de la consulta recursiva. No sustituye a
 *   `scan_events_clock_in_shift_entry_index`, que es parcial (solo `clock_in`)
 *   y ordenado `DESC` para el `LIMIT 1` del panel de presencia.
 * - **`shift_entries_superseded_by_id_index`** `(superseded_by_id) WHERE
 *   superseded_by_id IS NOT NULL`. El paso recursivo de `clockingMarks()` sube de
 *   cada version a la que sustituyo (`previous.superseded_by_id = descendiente`)
 *   y sin indice recorria `shift_entries` entera en cada iteracion. **Parcial**:
 *   la inmensa mayoria de los tramos nunca se corrige, y el indice pesa lo que
 *   pesan las correcciones. De paso, cubre la clave ajena autorreferente: un
 *   `DELETE` (que no ocurre, regla dura 5) o un cambio de clave ya no tendria
 *   que recorrer la tabla para comprobarla.
 *
 * ## Patron `/migracion-segura`: expand puro
 *
 * | Despliegue | Que se hace | Estado del codigo |
 * |---|---|---|
 * | **1 (expand)** | Dos indices nuevos. Ninguna fila cambia. | La version anterior ejecuta las mismas consultas y se beneficia igual. |
 * | **2 (migrate)** | *No aplica.* | — |
 * | **3 (contract)** | *No aplica.* | — |
 *
 * ## `CONCURRENTLY`, con las consecuencias de siempre
 *
 * Las de `2026_08_31_100000_index_clock_in_scans_by_shift_entry`: las dos
 * tablas tienen datos en toda instalacion desplegada y un `CREATE INDEX` normal
 * bloquearia los fichajes (regla dura 19, RNF-D-04). Asi que la migracion deja
 * de ser atomica (`$withinTransaction = false`), y si una construccion falla a
 * mitad su indice queda `INVALID`. No hay que borrarlo a mano:
 * `LimitsMigrationLocks::createIndexConcurrently()` borra el `INVALID` antes de
 * construir, y lanza —sin anotar la migracion— si el indice no queda valido;
 * basta con repetir `migrate`. Su `IF NOT EXISTS` hace que repetirla tras un
 * fallo en el segundo indice no choque con el primero, ya valido. Solo
 * `lock_timeout` y sin tope de duracion (`withLockWaitOnly()`, por debajo).
 *
 * `down()`: `DROP INDEX CONCURRENTLY IF EXISTS` de los dos
 * (`dropIndexConcurrently()`), verificado por
 * `MigrationsRoundTripTest`; el uso de los indices, por
 * `tests/Integration/Reporting/WorkDayJournalIndexUsageTest.php`.
 */
return new class extends Migration
{
    use LimitsMigrationLocks;

    /**
     * `CREATE INDEX CONCURRENTLY` no se ejecuta dentro de una transaccion.
     *
     * @var bool
     */
    public $withinTransaction = false;

    /**
     * Nombre => definicion. Los nombres son constantes de esta clase y nunca
     * entrada externa: PostgreSQL no admite parametros enlazados en un
     * identificador.
     *
     * @var array<string, string>
     */
    private const array INDEXES = [
        'scan_events_shift_entry_id_occurred_at_index' => 'ON scan_events (shift_entry_id, occurred_at)',
        'shift_entries_superseded_by_id_index' => 'ON shift_entries (superseded_by_id) WHERE superseded_by_id IS NOT NULL',
    ];

    public function up(): void
    {
        foreach (self::INDEXES as $name => $definition) {
            $this->createIndexConcurrently($name, $definition);
        }
    }

    public function down(): void
    {
        foreach (array_reverse(array_keys(self::INDEXES)) as $name) {
            $this->dropIndexConcurrently($name);
        }
    }
};
