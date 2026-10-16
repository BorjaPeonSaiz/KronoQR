<?php

declare(strict_types=1);

use App\Support\Database\LimitsMigrationLocks;
use Illuminate\Database\Migrations\Migration;

/**
 * El indice del cuadro de adopcion y el de la purga de tramos (RF-IN-08,
 * RNF-P-02, RNF-P-05; hallazgo DB5 de la verificacion y B7 de la 2.2.1).
 *
 * - **`scan_events_occurred_at_index`** `(occurred_at)`. El cuadro de adopcion
 *   (`DatabaseAdoptionFactsReader`) convierte los dos periodos a instantes UTC
 *   y acota `scan_events` por `occurred_at >= desde AND occurred_at < hasta`.
 *   Ningun indice empezaba por `occurred_at`: el plan entraba de refilon por
 *   `(device_id, occurred_at)` recorriendo el indice entero, o hacia `Seq Scan`
 *   de cuatro años de historico (RL-02).
 * - **`incidents_shift_entry_id_index`** `(shift_entry_id)`. La clave ajena
 *   `incidents.shift_entry_id` es `RESTRICT`: al borrar un tramo, PostgreSQL
 *   comprueba que ninguna incidencia lo nombra con
 *   `SELECT 1 FROM ONLY incidents WHERE shift_entry_id = $1 FOR KEY SHARE`, y
 *   sin indice eso es un `Seq Scan on incidents` por cada tramo que borra
 *   `work_record_purge_batch()`. Las otras dos claves ajenas hacia el tramo
 *   (`scan_events`, `shift_corrections`) ya tenian el suyo.
 *
 * ## Patron `/migracion-segura`: expand puro
 *
 * | Despliegue | Que se hace | Estado del codigo |
 * |---|---|---|
 * | **1 (expand)** | Dos indices nuevos. Ninguna fila cambia. | La version anterior no los usa y no le estorban. |
 * | **2 (migrate)** | *No aplica.* | — |
 * | **3 (contract)** | *No aplica.* | — |
 *
 * Las dos tablas tienen datos en toda instalacion desplegada: `CONCURRENTLY`
 * con `createIndexConcurrently()` (solo `lock_timeout`, sin tope de duracion,
 * y un indice `INVALID` de un intento anterior se reconstruye). `down()` los
 * retira con `dropIndexConcurrently()`, verificado por `MigrationsRoundTripTest`.
 * El uso lo atan `AdoptionReportVolumeTest` y `IncidentShiftEntryIndexTest`.
 */
return new class extends Migration
{
    use LimitsMigrationLocks;

    /** Ver el docblock: `CREATE INDEX CONCURRENTLY` no puede ir en una transaccion. */
    public $withinTransaction = false;

    /** @var array<string, string> */
    private const array INDEXES = [
        'scan_events_occurred_at_index' => 'ON scan_events (occurred_at)',
        'incidents_shift_entry_id_index' => 'ON incidents (shift_entry_id)',
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
