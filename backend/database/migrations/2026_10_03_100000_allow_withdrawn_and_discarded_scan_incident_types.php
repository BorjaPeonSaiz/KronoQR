<?php

declare(strict_types=1);

use App\Support\Database\LimitsMigrationLocks;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * `incidents.type` admite `scan_before_revocation` (**RN-20**) y
 * `discarded_scan` (**RN-22**) — ADR-047, doc 01 §5.5, 2.2.0.
 *
 * ## Que problema resuelve
 *
 * Dos fichajes reales que hasta la 2.2.0 desaparecian sin que nadie los viera:
 * el de una tarjeta autentica usada antes de su retirada que llego despues
 * (la salida del ultimo dia, encolada sin red), y el que el quiosco saco de su
 * cola porque el servidor declaro invalida la peticion. Los dos los abre la
 * revision diaria; sin esta migracion el primer hallazgo chocaria contra
 * `incidents_chk_type` y la pasada contaria un fallo por cada uno.
 *
 * **Una por persona y jornada.** `one_incident_per_finding` —`(employee_id,
 * work_date, type, shift_entry_id)` con `NULLS NOT DISTINCT`— ya lo garantiza
 * sin cambios: estas incidencias llegan sin `shift_entry_id`.
 *
 * ## Patron `/migracion-segura`: expand puro
 *
 * Copia de `2026_09_30_120200_allow_rejected_pin_scan_incident_type`: `DROP` +
 * `ADD ... NOT VALID` en una transaccion corta y `VALIDATE` despues de
 * confirmarla (`LimitsMigrationLocks::validateConstraint()`, hallazgo DB3). El
 * `CHECK` admite dos tipos **mas** y ninguna fila existente deja de cumplirlo.
 * El contrato ya los admite (`IncidentType`, aditivo por ADR-012).
 *
 * ## `down()` verificado
 *
 * Vuelve al catalogo de diez tipos y **se detiene a proposito** si ya hay
 * incidencias de cualquiera de los dos tipos nuevos: nada se borra (regla dura
 * 5). Lo comprueba dentro de la transaccion, con la tabla bloqueada por el
 * `ADD`, y lanza antes del `COMMIT`. Se prueba en `tests/Integration/Schema/WithdrawnAndDiscardedScanMigrationsTest.php`
 * ademas de `MigrationsRoundTripTest`.
 */
return new class extends Migration
{
    use LimitsMigrationLocks;

    /**
     * El `VALIDATE` va fuera de la transaccion que cambia el `CHECK`: ver
     * {@see LimitsMigrationLocks}.
     *
     * @var bool
     */
    public $withinTransaction = false;

    private const string CONSTRAINT = 'incidents_chk_type';

    /** El catalogo al que vuelve `down()`: el de `2026_09_30_120200`. */
    private const array PREVIOUS_TYPES = [
        'open_shift_expired', 'short_shift', 'long_shift', 'missing_break',
        'insufficient_rest', 'clock_skew', 'missing_clock_out', 'anomalous_pattern',
        'out_of_order_scan', 'rejected_pin_scan',
    ];

    public function up(): void
    {
        DB::transaction(function (): void {
            $this->limitLockWait();

            DB::statement('ALTER TABLE incidents DROP CONSTRAINT IF EXISTS '.self::CONSTRAINT);

            DB::statement(<<<'SQL'
                ALTER TABLE incidents
                    ADD CONSTRAINT incidents_chk_type
                    CHECK (type IN (
                        'open_shift_expired', 'short_shift', 'long_shift', 'missing_break',
                        'insufficient_rest', 'clock_skew', 'missing_clock_out', 'anomalous_pattern',
                        'out_of_order_scan', 'rejected_pin_scan', 'scan_before_revocation', 'discarded_scan'
                    ))
                    NOT VALID
            SQL);
        });

        $this->validateConstraint('incidents', self::CONSTRAINT);
    }

    public function down(): void
    {
        DB::transaction(function (): void {
            $this->limitLockWait();

            DB::statement('ALTER TABLE incidents DROP CONSTRAINT IF EXISTS '.self::CONSTRAINT);

            DB::statement(<<<'SQL'
                ALTER TABLE incidents
                    ADD CONSTRAINT incidents_chk_type
                    CHECK (type IN (
                        'open_shift_expired', 'short_shift', 'long_shift', 'missing_break',
                        'insufficient_rest', 'clock_skew', 'missing_clock_out', 'anomalous_pattern',
                        'out_of_order_scan', 'rejected_pin_scan'
                    ))
                    NOT VALID
            SQL);

            // Se comprueba DESPUES del `ADD`, dentro de la transaccion: el `ACCESS
            // EXCLUSIVE` que tomo impide que entre una fila nueva entre la
            // comprobacion y el `COMMIT`. Si hay alguna, la excepcion deshace el
            // `DROP` y el `ADD`: nada se ha tocado (regla dura 5).
            if (DB::table('incidents')->whereNotIn('type', self::PREVIOUS_TYPES)->exists()) {
                throw new RuntimeException(
                    'incidents tiene incidencias scan_before_revocation o discarded_scan (RN-20, RN-22): volver al '
                    .'catalogo anterior de '.self::CONSTRAINT.' dejaria un catalogo que miente. Nada se ha tocado; '
                    .'decide que hacer con esas incidencias antes de revertir.'
                );
            }
        });

        // Toda fila cumple: se acaba de comprobar con la tabla bloqueada.
        $this->validateConstraint('incidents', self::CONSTRAINT);
    }
};
