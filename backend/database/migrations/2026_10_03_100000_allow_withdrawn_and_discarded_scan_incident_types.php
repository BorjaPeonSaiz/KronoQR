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
 * `ADD ... NOT VALID` + `VALIDATE`. El `CHECK` admite dos tipos **mas** y
 * ninguna fila existente deja de cumplirlo. El contrato ya los admite
 * (`IncidentType`, aditivo por ADR-012).
 *
 * ## `down()` verificado
 *
 * Vuelve al catalogo de diez tipos y **falla a proposito** si ya hay
 * incidencias de cualquiera de los dos tipos nuevos: nada se borra (regla dura
 * 5). Se prueba en `tests/Integration/Schema/WithdrawnAndDiscardedScanMigrationsTest.php`
 * ademas de `MigrationsRoundTripTest`.
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
                    'out_of_order_scan', 'rejected_pin_scan', 'scan_before_revocation', 'discarded_scan'
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
                    'out_of_order_scan', 'rejected_pin_scan'
                ))
                NOT VALID
        SQL);

        // Se valida a proposito: con incidencias de RN-20 o RN-22 escritas, la
        // reversion se detiene en vez de dejar un catalogo que miente.
        DB::statement('ALTER TABLE incidents VALIDATE CONSTRAINT '.self::CONSTRAINT);
    }
};
