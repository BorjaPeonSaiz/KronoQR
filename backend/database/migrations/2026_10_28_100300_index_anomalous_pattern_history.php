<?php

declare(strict_types=1);

use App\Support\Database\LimitsMigrationLocks;
use Illuminate\Database\Migrations\Migration;

/**
 * El indice de la historia de patrones de la deteccion nocturna (RF-PR-06,
 * RNF-P-02; DB9 de la verificacion de la 2.1.0, aceptado en el doc 07 hasta la
 * 2.3.0).
 *
 * **`incidents_anomalous_pattern_index`**
 * `(employee_id, (context ->> 'pattern')) WHERE type = 'anomalous_pattern'`.
 * `EloquentAnomalousPatternHistory` pregunta cada noche, para las personas de
 * la ventana, que indicios de un patron tienen ya abiertos o cerrados. El filtro
 * por `context ->> 'pattern'` no tenia indice y la consulta leia `incidents`
 * entera. **Parcial**: solo las incidencias de patron escriben en el, y son una
 * fraccion minima de la bandeja. La consulta repite el predicado del tipo para
 * poder usarlo.
 *
 * La otra consulta del hallazgo, la ventana de `EloquentCredentialScans`, no
 * necesita indice nuevo: filtra por la expresion de
 * `scan_events_occurred_at_utc_index` (`2026_10_16_100000`).
 *
 * ## Patron `/migracion-segura`: expand puro
 *
 * | Despliegue | Que se hace | Estado del codigo |
 * |---|---|---|
 * | **1 (expand)** | Un indice nuevo. Ninguna fila cambia. | El codigo anterior no lo usa; el nuevo, si. |
 * | **2 (migrate)** | *No aplica.* | — |
 * | **3 (contract)** | *No aplica.* | — |
 *
 * `CONCURRENTLY` con `createIndexConcurrently()`. `down()` lo retira; lo
 * verifica `MigrationsRoundTripTest`. El uso lo ata
 * `NightlyDetectionIndexUsageTest`.
 */
return new class extends Migration
{
    use LimitsMigrationLocks;

    /** Ver el docblock: `CREATE INDEX CONCURRENTLY` no puede ir en una transaccion. */
    public $withinTransaction = false;

    private const string INDEX = 'incidents_anomalous_pattern_index';

    public function up(): void
    {
        $this->createIndexConcurrently(
            self::INDEX,
            "ON incidents (employee_id, (context ->> 'pattern')) WHERE type = 'anomalous_pattern'",
        );
    }

    public function down(): void
    {
        $this->dropIndexConcurrently(self::INDEX);
    }
};
