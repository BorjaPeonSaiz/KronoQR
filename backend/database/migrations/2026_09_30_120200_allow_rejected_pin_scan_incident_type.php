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
 * Copia de `2026_09_18_100200_allow_out_of_order_scan_incident_type`: `DROP` +
 * `ADD ... NOT VALID` + `VALIDATE`, el `CHECK` admite un tipo **mas** y ninguna
 * fila existente deja de cumplirlo. El contrato ya lo admite (`IncidentType`,
 * aditivo por ADR-012).
 *
 * ## `down()` verificado
 *
 * Vuelve al catalogo de nueve tipos y **falla a proposito** si ya hay
 * incidencias `rejected_pin_scan`: nada se borra (regla dura 5). Se prueba en
 * `tests/Integration/Schema/PinClaimMigrationsTest.php` ademas de
 * `MigrationsRoundTripTest`.
 */
return new class extends Migration
{
    use LimitsMigrationLocks;

    private const string CONSTRAINT = 'incidents_chk_type';

    public function up(): void
    {
        $this->limitLockWait();

        DB::statement('ALTER TABLE incidents DROP CONSTRAINT '.self::CONSTRAINT);

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

        DB::statement('ALTER TABLE incidents VALIDATE CONSTRAINT '.self::CONSTRAINT);
    }

    public function down(): void
    {
        $this->limitLockWait();

        DB::statement('ALTER TABLE incidents DROP CONSTRAINT '.self::CONSTRAINT);

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

        // Se valida a proposito: con incidencias de RN-19 escritas, la reversion
        // se detiene en vez de dejar un catalogo que miente.
        DB::statement('ALTER TABLE incidents VALIDATE CONSTRAINT '.self::CONSTRAINT);
    }
};
