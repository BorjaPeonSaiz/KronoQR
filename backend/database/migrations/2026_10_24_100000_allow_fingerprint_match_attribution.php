<?php

declare(strict_types=1);

use App\Support\Database\LimitsMigrationLocks;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * `discarded_scan_reports.attribution` admite `fingerprint_match` (**RN-22**,
 * ADR-061 A2, doc 01 §5.5, revision del 24-10-2026).
 *
 * Un descarte del servidor de una tarjeta que no se pudo atribuir por su firma
 * busca a su titular por la huella del contenido en fichajes aceptados
 * anteriores. La restriccion nueva lo admite **solo** con `source = server`, por
 * tarjeta, con dueño y sin emision —la cota de la revision es el alta, como por
 * codigo—.
 *
 * - **La columna crece de `varchar(16)` a `varchar(24)`**: `fingerprint_match`
 *   tiene 17 letras. Ampliar un `varchar` en PostgreSQL solo toca el catalogo,
 *   sin reescribir la tabla.
 * - **El `CHECK`**: se suelta ANTES de ampliar el tipo —con una restriccion
 *   sobre la columna, `ALTER COLUMN TYPE` la comprobaria fila a fila con `ACCESS
 *   EXCLUSIVE`—, se vuelve a crear `NOT VALID` en la misma transaccion y se
 *   valida fuera, que no bloquea los avisos.
 *
 * ## Patron `/migracion-segura`: expand puro
 *
 * Ninguna fila existente cambia de veredicto: nadie ha escrito todavia el valor
 * nuevo. La version anterior no lo escribe.
 *
 * ## `down()` verificado
 *
 * Vuelve al `CHECK` anterior y **falla a proposito** si hay filas
 * `fingerprint_match`: son constancia de un fichaje que no se registro (regla
 * dura 5). La columna no se estrecha: estrecharla reescribe la tabla y no
 * aporta nada.
 */
return new class extends Migration
{
    use LimitsMigrationLocks;

    /** La validacion va fuera de la transaccion que crea la restriccion. */
    public $withinTransaction = false;

    private const string CHECK = 'discarded_scan_reports_chk_attribution';

    public function up(): void
    {
        DB::transaction(function (): void {
            $this->limitLockWait();

            // El CHECK fuera ANTES de ampliar el tipo: con una restriccion sobre la
            // columna, `ALTER COLUMN TYPE` la vuelve a comprobar fila a fila con
            // ACCESS EXCLUSIVE. Sin ella, ampliar un varchar solo toca el catalogo.
            DB::statement('ALTER TABLE discarded_scan_reports DROP CONSTRAINT IF EXISTS '.self::CHECK);
            DB::statement('ALTER TABLE discarded_scan_reports ALTER COLUMN attribution TYPE varchar(24)');
            DB::statement(<<<'SQL'
                ALTER TABLE discarded_scan_reports
                    ADD CONSTRAINT discarded_scan_reports_chk_attribution
                    CHECK (
                        (attribution = 'none' AND owner_employee_id IS NULL AND credential_issued_at IS NULL)
                        OR (attribution = 'credential' AND origin = 'qr_kiosk'
                            AND owner_employee_id IS NOT NULL AND credential_issued_at IS NOT NULL)
                        OR (attribution = 'employee_code' AND origin = 'pin_kiosk'
                            AND owner_employee_id IS NOT NULL AND credential_issued_at IS NULL)
                        OR (attribution = 'fingerprint_match' AND origin = 'qr_kiosk' AND source = 'server'
                            AND owner_employee_id IS NOT NULL AND credential_issued_at IS NULL)
                    )
                    NOT VALID
            SQL);
        });

        $this->validateConstraint('discarded_scan_reports', self::CHECK);
    }

    public function down(): void
    {
        DB::transaction(function (): void {
            $this->limitLockWait();

            DB::statement('ALTER TABLE discarded_scan_reports DROP CONSTRAINT IF EXISTS '.self::CHECK);
            DB::statement(<<<'SQL'
                ALTER TABLE discarded_scan_reports
                    ADD CONSTRAINT discarded_scan_reports_chk_attribution
                    CHECK (
                        (attribution = 'none' AND owner_employee_id IS NULL AND credential_issued_at IS NULL)
                        OR (attribution = 'credential' AND origin = 'qr_kiosk'
                            AND owner_employee_id IS NOT NULL AND credential_issued_at IS NOT NULL)
                        OR (attribution = 'employee_code' AND origin = 'pin_kiosk'
                            AND owner_employee_id IS NOT NULL AND credential_issued_at IS NULL)
                    )
                    NOT VALID
            SQL);
        });

        // Con filas `fingerprint_match` esto falla y la reversion se detiene.
        $this->validateConstraint('discarded_scan_reports', self::CHECK);

        // Y la columna vuelve a su anchura. Ya validada la restriccion, ningun
        // valor pasa de los 13 caracteres de `employee_code`: estrecharla no
        // puede fallar. Reescribe una tabla pequeña, y solo al deshacer.
        DB::transaction(function (): void {
            $this->limitLockWait();

            DB::statement('ALTER TABLE discarded_scan_reports ALTER COLUMN attribution TYPE varchar(16)');
        });
    }
};
