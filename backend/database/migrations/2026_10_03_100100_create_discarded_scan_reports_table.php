<?php

declare(strict_types=1);

use App\Modules\Compliance\Infrastructure\Persistence\AuditLogSchema;
use App\Support\Database\LimitsMigrationLocks;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * `discarded_scan_reports` — los fichajes que un quiosco saco de su cola porque
 * el servidor declaro invalida la peticion (**RN-22**, ADR-047, doc 01 §5.5,
 * 2.2.0).
 *
 * ## Que guarda y que no
 *
 * Lo que una persona necesita para encontrar el fichaje —`scan_id`,
 * `occurred_at`, la via, el quiosco, la respuesta que recibio— y **el
 * resultado de atribuirlo**: `owner_employee_id` y `attribution`. **Nunca el
 * contenido del QR ni el codigo tecleado** (RS-03, regla dura 21): el servidor
 * los usa para atribuir y los tira. `credential_issued_at` es la emision de la
 * tarjeta que atribuyo, la cota inferior con la que la revision diaria descarta
 * un `occurred_at` anterior a que la tarjeta existiera (F6 del dictamen del
 * bloque 18): sin ella habria que volver a resolver un payload que no se guarda.
 *
 * ## Las invariantes, en el esquema
 *
 * - **`scan_id` UNIQUE**: la idempotencia del aviso (regla dura 8). Diez avisos
 *   simultaneos del mismo `scan_id` dejan una fila; la decide el indice, no un
 *   `SELECT` previo.
 * - **`http_status` entre 400 y 499**: un aviso es de una peticion que el
 *   servidor declaro invalida, nunca de un fallo suyo.
 * - **`attribution` coherente con el dueño**: `none` sin dueño; `credential`
 *   solo por tarjeta y con la emision; `employee_code` solo por PIN.
 *
 * ## Permisos: sin `UPDATE` (F7 del dictamen del bloque 18)
 *
 * Un aviso no se corrige: se revoca `UPDATE` al rol de la aplicacion. **`DELETE`
 * se conserva**, y no por descuido: la purga del registro de jornada (RL-02)
 * la ejecuta ese mismo rol en la transaccion que escribe su asiento
 * (`DatabaseWorkRecordArchive`, migracion `retention_purge_grants`), y esta
 * tabla envejece con `scan_events`. Es exactamente lo que tiene `scan_events`.
 *
 * ## Patron `/migracion-segura`: expand puro
 *
 * Tabla nueva: no toca ninguna fila existente. La version anterior no la
 * conoce y no la necesita.
 *
 * ## `down()` verificado
 *
 * **Se niega** a borrar la tabla si tiene avisos: son la unica constancia de un
 * fichaje que no se registro (regla dura 5). Vacia, se borra sin mas.
 */
return new class extends Migration
{
    use LimitsMigrationLocks;

    private const string TABLE = 'discarded_scan_reports';

    public function up(): void
    {
        $this->limitLockWait();

        DB::statement(<<<'SQL'
            CREATE TABLE discarded_scan_reports (
                id bigserial PRIMARY KEY,
                scan_id uuid NOT NULL,
                device_id bigint NOT NULL,
                origin varchar(16) NOT NULL,
                occurred_at timestamptz(6) NOT NULL,
                discarded_at timestamptz(6) NOT NULL,
                recorded_at timestamptz(6) NOT NULL,
                http_status smallint NOT NULL,
                problem_type varchar(200) NULL,
                owner_employee_id bigint NULL,
                attribution varchar(16) NOT NULL,
                credential_issued_at timestamptz(6) NULL,
                already_recorded boolean NOT NULL,
                CONSTRAINT discarded_scan_reports_scan_id_unique UNIQUE (scan_id),
                CONSTRAINT discarded_scan_reports_device_id_foreign
                    FOREIGN KEY (device_id) REFERENCES devices (id) ON DELETE RESTRICT,
                CONSTRAINT discarded_scan_reports_owner_employee_id_foreign
                    FOREIGN KEY (owner_employee_id) REFERENCES employees (id) ON DELETE RESTRICT,
                CONSTRAINT discarded_scan_reports_chk_origin
                    CHECK (origin IN ('qr_kiosk', 'pin_kiosk')),
                CONSTRAINT discarded_scan_reports_chk_http_status
                    CHECK (http_status BETWEEN 400 AND 499),
                CONSTRAINT discarded_scan_reports_chk_attribution
                    CHECK (
                        (attribution = 'none' AND owner_employee_id IS NULL AND credential_issued_at IS NULL)
                        OR (attribution = 'credential' AND origin = 'qr_kiosk'
                            AND owner_employee_id IS NOT NULL AND credential_issued_at IS NOT NULL)
                        OR (attribution = 'employee_code' AND origin = 'pin_kiosk'
                            AND owner_employee_id IS NOT NULL AND credential_issued_at IS NULL)
                    )
            )
        SQL);

        // La revision diaria lee por `recorded_at` solo los avisos con dueño que
        // no estaban ya registrados: una minoria diminuta. Parcial, como el de
        // los PIN con dueño en `scan_events`.
        DB::statement(<<<'SQL'
            CREATE INDEX discarded_scan_reports_attributed_recorded_at_index
                ON discarded_scan_reports (recorded_at)
                WHERE owner_employee_id IS NOT NULL AND NOT already_recorded
        SQL);

        // F7: sin `UPDATE` para la aplicacion; `DELETE` se queda para la purga.
        DB::statement('REVOKE UPDATE ON TABLE '.self::TABLE.' FROM '.$this->applicationRole());
    }

    public function down(): void
    {
        $this->limitLockWait();

        if (Schema::hasTable(self::TABLE) && DB::table(self::TABLE)->exists()) {
            throw new RuntimeException(
                'discarded_scan_reports tiene avisos de fichajes descartados: son la unica constancia de un '
                .'fichaje que no se registro y no se borran al deshacer una migracion (regla dura 5).'
            );
        }

        Schema::dropIfExists(self::TABLE);
    }

    private function applicationRole(): string
    {
        return '"'.AuditLogSchema::applicationRole().'"';
    }
};
