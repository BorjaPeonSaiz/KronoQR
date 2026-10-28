<?php

declare(strict_types=1);

use App\Support\Database\LimitsMigrationLocks;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Los indices con los que la purga de retencion deja de recorrer tablas
 * enteras (RL-02, RNF-P-02; hallazgos V21-5-BE-1 y V5-BD-1 de la 2.2.1).
 *
 * `work_record_purge_batch()` y `DatabaseWorkRecordArchive::inspect()` filtran
 * tres cosas que ningun indice servia:
 *
 * - **`scan_events_unattached_age_index`**
 *   `((LEAST(occurred_at, recorded_at))) WHERE shift_entry_id IS NULL`. Los
 *   escaneos que no produjeron tramo envejecen por la menor de sus dos marcas
 *   (`occurred_at` la pone la tablet y uno de 2099 no se purgaria nunca). Sin
 *   este indice, cada lote de la purga y cada `inspect` semanal eran un
 *   `Seq Scan on scan_events` con un *hash* de `shift_entries` entera: 558 ms
 *   por lote vacio con 950 000 escaneos. **Parcial a proposito**: los escaneos
 *   con tramo envejecen por el tramo, y solo los rechazos y los rebotes pagan
 *   la escritura de este indice. Y es de expresion: no compite con los
 *   `ORDER BY occurred_at` del fichaje (ver `2026_10_16_100000`).
 * - **`scan_events_unattached_age_statistics`**: estadisticas de esa misma
 *   expresion. PostgreSQL no usa las de un indice parcial para estimar, y sin
 *   ellas daba por buenas un tercio de las filas: con un `LIMIT` preferia leer
 *   la tabla desde el principio. Se rellenan con el siguiente `ANALYZE` del
 *   autovacuum; hasta entonces el plan es el de antes, no uno peor.
 * - **`discarded_scan_reports_age_index`** `((LEAST(occurred_at, recorded_at)))`.
 *   Los avisos de fichaje descartado (RN-22) envejecen igual; su unico indice
 *   por fecha era el parcial de los atribuidos. Completo: la tabla es pequeña
 *   y asi el planificador tiene las estadisticas de la expresion.
 * - **`incidents_work_date_index`** `(work_date)`. La incidencia de una
 *   jornada vencida se purga por su fecha. El compuesto
 *   `(employee_id, work_date)` no sirve un rango sin persona.
 *
 * ## Patron `/migracion-segura`: expand puro
 *
 * | Despliegue | Que se hace | Estado del codigo |
 * |---|---|---|
 * | **1 (expand)** | Tres indices y unas estadisticas. Ninguna fila cambia. | La funcion anterior no los usa y no le estorban; la nueva (`2026_10_28_100100`) los aprovecha. |
 * | **2 (migrate)** | *No aplica.* | — |
 * | **3 (contract)** | *No aplica.* | — |
 *
 * Las tres tablas tienen datos en toda instalacion: `CONCURRENTLY` con
 * `createIndexConcurrently()`, sin bloquear el fichaje. `CREATE STATISTICS`
 * toma `SHARE UPDATE EXCLUSIVE`, que no estorba a lecturas ni escrituras, en
 * una transaccion corta con `lock_timeout`. `down()` lo retira todo, en orden
 * inverso, y lo verifica `MigrationsRoundTripTest`. El uso lo ata
 * `WorkRecordPurgeIndexUsageTest`.
 */
return new class extends Migration
{
    use LimitsMigrationLocks;

    /** Ver el docblock: `CREATE INDEX CONCURRENTLY` no puede ir en una transaccion. */
    public $withinTransaction = false;

    /** @var array<string, string> */
    private const array INDEXES = [
        'scan_events_unattached_age_index' => 'ON scan_events ((LEAST(occurred_at, recorded_at))) WHERE shift_entry_id IS NULL',
        'discarded_scan_reports_age_index' => 'ON discarded_scan_reports ((LEAST(occurred_at, recorded_at)))',
        'incidents_work_date_index' => 'ON incidents (work_date)',
    ];

    private const string STATISTICS = 'scan_events_unattached_age_statistics';

    public function up(): void
    {
        foreach (self::INDEXES as $name => $definition) {
            $this->createIndexConcurrently($name, $definition);
        }

        DB::transaction(function (): void {
            $this->limitLockWait();

            DB::statement('CREATE STATISTICS IF NOT EXISTS public.'.self::STATISTICS
                .' ON (LEAST(occurred_at, recorded_at)) FROM scan_events');
        });
    }

    public function down(): void
    {
        DB::transaction(function (): void {
            $this->limitLockWait();

            DB::statement('DROP STATISTICS IF EXISTS public.'.self::STATISTICS);
        });

        foreach (array_reverse(array_keys(self::INDEXES)) as $name) {
            $this->dropIndexConcurrently($name);
        }
    }
};
