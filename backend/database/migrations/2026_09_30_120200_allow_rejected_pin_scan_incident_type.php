<?php

declare(strict_types=1);

use App\Support\Database\LimitsMigrationLocks;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * `incidents.type` admite `rejected_pin_scan` (**RN-19**, ADR-043, doc 01 §5.5,
 * hallazgos PIN-06 y SC7-02).
 *
 * ## Que problema resuelve
 *
 * Un fichaje por PIN rechazado de alguien que puede fichar, sin fichaje suyo
 * que lo subsane en 10 minutos, **se le pasa a una persona**: el sistema no
 * puede saber si trabajo, asi que deja constancia y abre una incidencia que se
 * cierra con una correccion trazada (RN-13) o con un descarte. La abre la
 * revision diaria leyendo `scan_events.claimed_employee_id`; sin esta migracion
 * el primer hallazgo chocaria contra `incidents_chk_type` y la pasada contaria
 * un fallo por cada uno.
 *
 * **Una por persona y jornada.** `one_incident_per_finding` —`(employee_id,
 * work_date, type, shift_entry_id)` con `NULLS NOT DISTINCT`— ya lo garantiza
 * sin cambios: estas incidencias llegan sin `shift_entry_id`. Es tambien lo que
 * hace que repetir la pasada no duplique nada y que una incidencia descartada
 * no se reabra.
 *
 * ## Patron `/migracion-segura`: expand puro
 *
 * `DROP` + `ADD ... NOT VALID` en una transaccion corta y `VALIDATE` despues de
 * confirmarla (`LimitsMigrationLocks::validateConstraint()`, hallazgo DB3): el
 * `CHECK` admite un tipo **mas** y ninguna fila existente deja de cumplirlo. El
 * contrato ya lo admite (`IncidentType`, aditivo por ADR-012).
 *
 * ## `down()` verificado
 *
 * Vuelve al catalogo de nueve tipos y **se detiene a proposito** si ya hay
 * incidencias `rejected_pin_scan`: nada se borra (regla dura 5). Lo comprueba
 * dentro de la transaccion, con la tabla bloqueada por el `ADD`, y lanza antes
 * del `COMMIT`; ya no confia en que el `VALIDATE` falle, porque fuera de la
 * transaccion dejaria el catalogo reducido a medias. Se prueba en
 * `tests/Integration/Schema/PinClaimMigrationsTest.php` ademas de
 * `MigrationsRoundTripTest`.
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

    /** El catalogo al que vuelve `down()`: el de `2026_09_18_100200`. */
    private const array PREVIOUS_TYPES = [
        'open_shift_expired', 'short_shift', 'long_shift', 'missing_break',
        'insufficient_rest', 'clock_skew', 'missing_clock_out', 'anomalous_pattern',
        'out_of_order_scan',
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
                        'out_of_order_scan', 'rejected_pin_scan'
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
                        'out_of_order_scan'
                    ))
                    NOT VALID
            SQL);

            // Se comprueba DESPUES del `ADD`, dentro de la transaccion: el `ACCESS
            // EXCLUSIVE` que tomo impide que entre una fila nueva entre la
            // comprobacion y el `COMMIT`. Si hay alguna, la excepcion deshace el
            // `DROP` y el `ADD`: nada se ha tocado (regla dura 5).
            if (DB::table('incidents')->whereNotIn('type', self::PREVIOUS_TYPES)->exists()) {
                throw new RuntimeException(
                    'incidents tiene incidencias rejected_pin_scan (RN-19): volver al catalogo anterior de '
                    .self::CONSTRAINT.' dejaria un catalogo que miente. Nada se ha tocado; decide que hacer con '
                    .'esas incidencias antes de revertir.'
                );
            }
        });

        // Toda fila cumple: se acaba de comprobar con la tabla bloqueada.
        $this->validateConstraint('incidents', self::CONSTRAINT);
    }
};
