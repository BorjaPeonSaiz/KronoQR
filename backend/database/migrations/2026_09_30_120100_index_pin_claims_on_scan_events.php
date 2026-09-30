<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * `scan_events_pin_claims_recorded_at_index` — la lectura nocturna de RN-19 en
 * O(log n) (ADR-043).
 *
 * ## Que problema resuelve
 *
 * La revision diaria pregunta «¿que fichajes por PIN con dueño llegaron en los
 * ultimos `lookbackDays` dias?», acotando por `recorded_at`. Sin indice, eso es
 * recorrer `scan_events` entera cada noche —millones de filas con cuatro años
 * de retencion (RL-02)— para encontrar una minoria diminuta.
 *
 * **Parcial**: solo entran las filas con `claimed_employee_id`, asi que el
 * indice pesa lo que pesan esos intentos y no el historico. La consulta de
 * subsanacion no lo necesita: va por persona y diez minutos, y la sirve el
 * indice existente `scan_events_employee_id_occurred_at_index`.
 *
 * ## `CONCURRENTLY`, con las consecuencias de siempre
 *
 * El mismo patron y las mismas advertencias que
 * `2026_08_31_100000_index_clock_in_scans_by_shift_entry`: `scan_events` tiene
 * datos en toda instalacion desplegada, un `CREATE INDEX` normal bloquearia los
 * fichajes (regla dura 19), la migracion deja de ser atomica
 * (`$withinTransaction = false`) y, si falla a mitad, el indice queda `INVALID`
 * y hay que borrarlo a mano antes de reintentar (`IF NOT EXISTS` evita el
 * choque). Solo `lock_timeout`, sin `statement_timeout`.
 *
 * `down()`: `DROP INDEX CONCURRENTLY IF EXISTS`, verificado por
 * `PinClaimMigrationsTest` y `MigrationsRoundTripTest`.
 */
return new class extends Migration
{
    /**
     * `CREATE INDEX CONCURRENTLY` no se ejecuta dentro de una transaccion.
     *
     * @var bool
     */
    public $withinTransaction = false;

    private const string INDEX = 'scan_events_pin_claims_recorded_at_index';

    public function up(): void
    {
        $this->limitLockWaitOnly();

        // El nombre del indice es una constante de esta clase y nunca entrada
        // externa: PostgreSQL no admite parametros enlazados en un identificador.
        DB::statement(
            'CREATE INDEX CONCURRENTLY IF NOT EXISTS '.self::INDEX
            .' ON scan_events (recorded_at) WHERE claimed_employee_id IS NOT NULL'
        );
    }

    public function down(): void
    {
        $this->limitLockWaitOnly();

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
