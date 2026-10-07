<?php

declare(strict_types=1);

use App\Support\Database\LimitsMigrationLocks;
use Illuminate\Database\Migrations\Migration;

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
 * —`LimitsMigrationLocks::createIndexConcurrently()` lo borra y lo reconstruye
 * en el reintento, y lanza si no queda valido; ya no hay que borrarlo a mano—.
 * Solo `lock_timeout` y sin tope de duracion: lo garantiza
 * `LimitsMigrationLocks::withLockWaitOnly()`, que fija `statement_timeout = 0`
 * mientras dura la construccion y devuelve la sesion a como estaba. Hasta la
 * 2.2.0 esta frase era falsa: el `SET statement_timeout = '30s'` de sesion de
 * la migracion anterior del mismo `artisan migrate` llegaba hasta aqui
 * (hallazgo DB4, R5-BD-02).
 *
 * `down()`: `DROP INDEX CONCURRENTLY IF EXISTS`
 * (`LimitsMigrationLocks::dropIndexConcurrently()`), verificado por
 * `PinClaimMigrationsTest` y `MigrationsRoundTripTest`.
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

    private const string INDEX = 'scan_events_pin_claims_recorded_at_index';

    public function up(): void
    {
        $this->createIndexConcurrently(self::INDEX, 'ON scan_events (recorded_at) WHERE claimed_employee_id IS NOT NULL');
    }

    public function down(): void
    {
        $this->dropIndexConcurrently(self::INDEX);
    }
};
