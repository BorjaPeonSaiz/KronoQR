<?php

declare(strict_types=1);

use App\Support\Database\LimitsMigrationLocks;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * `discarded_scan_reports` distingue **quien** descarto: el quiosco o el
 * servidor (**RN-22**, ADR-061, doc 01 §5.5, tarea 17.9 de la 2.3.0).
 *
 * ## Que problema resuelve
 *
 * Hasta la 2.3.0 solo descartaba el quiosco, al recibir una peticion que el
 * servidor declaraba invalida. Desde ADR-061 el servidor descarta tambien el
 * fichaje que falla una y otra vez, y lo escribe en esta misma tabla para que la
 * revision diaria abra la misma incidencia `discarded_scan`. Tres columnas:
 *
 * - **`source`** (`kiosk` | `server`), `kiosk` por defecto: todo lo escrito
 *   antes de esta migracion lo aviso un quiosco.
 * - **`failed_attempts`** e **`first_failed_at`**, solo con `source = server`:
 *   cuantos intentos fallaron y cuando fallo el primero, que es la cota del
 *   retraso con el que la persona vera la incidencia.
 *
 * ## La invariante, en el esquema
 *
 * `discarded_scan_reports_chk_source`: un aviso del quiosco no lleva intentos;
 * un descarte del servidor los lleva —al menos uno—, lleva su primer fallo y
 * responde `422`. Que lo diga la base de datos y no solo el adaptador es lo que
 * impide que una fila del servidor se confunda con un aviso al leerla.
 *
 * ## Patron `/migracion-segura`: expand puro
 *
 * | Despliegue | Que se hace | Estado del codigo |
 * |---|---|---|
 * | **1 (expand)** | Tres columnas nuevas y un `CHECK`. `ADD COLUMN ... DEFAULT 'kiosk'` con un valor constante no reescribe la tabla en PostgreSQL 11+: solo toca el catalogo. Las dos nulas tampoco. | La version anterior no conoce las columnas e inserta sin ellas: `source` toma `kiosk`, que es exactamente lo que es. |
 * | **2 (migrate)** | *No aplica*: toda fila existente es de un quiosco, y el valor por defecto ya lo dice. | — |
 * | **3 (contract)** | *No aplica.* | — |
 *
 * Sin indice aqui: el del cupo por quiosco va en la migracion siguiente, con
 * `CREATE INDEX CONCURRENTLY`, que no puede ir en una transaccion.
 *
 * ## Permisos
 *
 * No cambian. El rol de la aplicacion ya inserta en la tabla y no tiene
 * `UPDATE` (F7 del bloque 18 de la 2.2.0): un privilegio de tabla cubre las
 * columnas nuevas, y la ausencia de `UPDATE` tambien.
 *
 * ## `down()` verificado
 *
 * **Se niega** si hay descartes del servidor escritos: son la unica constancia
 * de un fichaje que no se registro (regla dura 5), y quitar las columnas los
 * convertiria en avisos de un quiosco que nunca aviso. Sin ellos, quita la
 * restriccion y las tres columnas.
 */
return new class extends Migration
{
    use LimitsMigrationLocks;

    /** La validacion va fuera de la transaccion que crea la restriccion. */
    public $withinTransaction = false;

    private const string CHECK = 'discarded_scan_reports_chk_source';

    public function up(): void
    {
        DB::transaction(function (): void {
            $this->limitLockWait();

            // Idempotente: si el `VALIDATE` se interrumpe, el siguiente
            // `migrate` vuelve a entrar aqui.
            DB::statement(<<<'SQL'
                ALTER TABLE discarded_scan_reports
                    ADD COLUMN IF NOT EXISTS source varchar(8) NOT NULL DEFAULT 'kiosk',
                    ADD COLUMN IF NOT EXISTS failed_attempts integer NULL,
                    ADD COLUMN IF NOT EXISTS first_failed_at timestamptz(6) NULL
            SQL);

            DB::statement('ALTER TABLE discarded_scan_reports DROP CONSTRAINT IF EXISTS '.self::CHECK);
            DB::statement(<<<'SQL'
                ALTER TABLE discarded_scan_reports
                    ADD CONSTRAINT discarded_scan_reports_chk_source
                    CHECK (
                        (source = 'kiosk' AND failed_attempts IS NULL AND first_failed_at IS NULL)
                        OR (source = 'server' AND failed_attempts IS NOT NULL AND failed_attempts >= 1
                            AND first_failed_at IS NOT NULL AND http_status = 422)
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

            if (DB::table('discarded_scan_reports')->where('source', 'server')->exists()) {
                throw new RuntimeException(
                    'discarded_scan_reports tiene descartes del servidor (RN-22, ADR-061): son la unica constancia de un '
                    .'fichaje que no se registro y no se convierten en avisos de un quiosco al deshacer una migracion '
                    .'(regla dura 5).'
                );
            }

            DB::statement('ALTER TABLE discarded_scan_reports DROP CONSTRAINT IF EXISTS '.self::CHECK);
            DB::statement(<<<'SQL'
                ALTER TABLE discarded_scan_reports
                    DROP COLUMN IF EXISTS first_failed_at,
                    DROP COLUMN IF EXISTS failed_attempts,
                    DROP COLUMN IF EXISTS source
            SQL);
        });
    }
};
