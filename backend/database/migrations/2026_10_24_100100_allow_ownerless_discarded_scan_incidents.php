<?php

declare(strict_types=1);

use App\Support\Database\LimitsMigrationLocks;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Una incidencia `discarded_scan` **sin persona**, una por quiosco y jornada
 * (**RN-22**, ADR-061 A2 del 24-10-2026, doc 01 §5.5).
 *
 * Un descarte del servidor de una tarjeta que no se pudo atribuir ni por la
 * firma ni por la huella del contenido abre una incidencia para el
 * administrador, con el quiosco y la hora. Hasta ahora `incidents.employee_id`
 * era obligatorio.
 *
 * - **`employee_id` admite nulo** (`DROP NOT NULL` solo toca el catalogo).
 * - **`device_id`**, nueva y nula, con su clave ajena a `devices`: el quiosco de
 *   la incidencia sin persona, que es lo que la hace unica.
 * - **`incidents_chk_subject`**: o persona y ningun quiosco, o quiosco sin persona
 *   y entonces solo `discarded_scan`. Ningun otro hallazgo puede quedar sin
 *   persona.
 * - **`one_incident_per_finding` gana `device_id`**: con dos quioscos el mismo
 *   dia, las dos sin persona chocarian en el `UNIQUE` de siempre (con `NULLS NOT
 *   DISTINCT`). El indice nuevo se crea `CONCURRENTLY` y la restriccion se cambia
 *   con `USING INDEX`, conservando el nombre que usa el `ON CONFLICT` de la
 *   aplicacion: el cambio de restriccion es un instante de catalogo.
 *
 * ## Patron `/migracion-segura`: expand
 *
 * | Despliegue | Que se hace | Estado del codigo |
 * |---|---|---|
 * | **1 (expand)** | Esta migracion. Ninguna fila cambia: todas tienen persona y ningun quiosco, que es exactamente lo que la restriccion nueva admite. | La version anterior siempre escribe persona y nunca `device_id`; su `ON CONFLICT ON CONSTRAINT one_incident_per_finding` sigue valiendo con la clave ampliada. |
 * | **2 (migrate)** | *No aplica.* | — |
 * | **3 (contract)** | *No aplica.* | — |
 *
 * Permisos: el rol de la aplicacion ya inserta en `incidents` y no actualiza
 * mas que las columnas de la resolucion; `device_id` hereda el `INSERT` de tabla
 * y no entra en las columnas actualizables.
 *
 * ## `down()` verificado
 *
 * **Se niega** si hay incidencias sin persona: son la constancia de un fichaje
 * que no se registro (regla dura 5). Sin ellas, deshace todo y deja la
 * restriccion con su clave anterior.
 */
return new class extends Migration
{
    use LimitsMigrationLocks;

    /** El indice nuevo se crea `CONCURRENTLY` y las validaciones van fuera de la transaccion. */
    public $withinTransaction = false;

    private const string UNIQUE = 'one_incident_per_finding';

    private const string NEW_INDEX = 'incidents_one_finding_with_device';

    private const string OLD_INDEX = 'incidents_one_finding_without_device';

    public function up(): void
    {
        DB::transaction(function (): void {
            $this->limitLockWait();

            DB::statement('ALTER TABLE incidents ALTER COLUMN employee_id DROP NOT NULL');
            DB::statement('ALTER TABLE incidents ADD COLUMN IF NOT EXISTS device_id bigint NULL');

            DB::statement('ALTER TABLE incidents DROP CONSTRAINT IF EXISTS incidents_device_id_foreign');
            DB::statement(<<<'SQL'
                ALTER TABLE incidents
                    ADD CONSTRAINT incidents_device_id_foreign
                    FOREIGN KEY (device_id) REFERENCES devices (id) ON DELETE RESTRICT
                    NOT VALID
            SQL);

            DB::statement('ALTER TABLE incidents DROP CONSTRAINT IF EXISTS incidents_chk_subject');
            DB::statement(<<<'SQL'
                ALTER TABLE incidents
                    ADD CONSTRAINT incidents_chk_subject
                    CHECK (
                        (employee_id IS NOT NULL AND device_id IS NULL)
                        OR (employee_id IS NULL AND device_id IS NOT NULL AND type = 'discarded_scan')
                    )
                    NOT VALID
            SQL);
        });

        $this->createIndexConcurrently(
            self::NEW_INDEX,
            'ON incidents (employee_id, device_id, work_date, type, shift_entry_id) NULLS NOT DISTINCT',
            unique: true,
        );

        DB::transaction(function (): void {
            $this->limitLockWait();

            DB::statement('ALTER TABLE incidents DROP CONSTRAINT '.self::UNIQUE);
            DB::statement('ALTER TABLE incidents ADD CONSTRAINT '.self::UNIQUE.' UNIQUE USING INDEX '.self::NEW_INDEX);
        });

        $this->validateConstraint('incidents', 'incidents_device_id_foreign');
        $this->validateConstraint('incidents', 'incidents_chk_subject');
    }

    public function down(): void
    {
        if (DB::table('incidents')->whereNull('employee_id')->exists()) {
            throw new RuntimeException(
                'incidents tiene incidencias de fichaje descartado sin persona (RN-22, ADR-061): son la constancia '
                .'de un fichaje que no se registro y no se borran al deshacer una migracion (regla dura 5).'
            );
        }

        $this->createIndexConcurrently(
            self::OLD_INDEX,
            'ON incidents (employee_id, work_date, type, shift_entry_id) NULLS NOT DISTINCT',
            unique: true,
        );

        DB::transaction(function (): void {
            $this->limitLockWait();

            DB::statement('ALTER TABLE incidents DROP CONSTRAINT '.self::UNIQUE);
            DB::statement('ALTER TABLE incidents ADD CONSTRAINT '.self::UNIQUE.' UNIQUE USING INDEX '.self::OLD_INDEX);
            DB::statement('ALTER TABLE incidents DROP CONSTRAINT IF EXISTS incidents_chk_subject');
            DB::statement('ALTER TABLE incidents DROP CONSTRAINT IF EXISTS incidents_device_id_foreign');
            DB::statement('ALTER TABLE incidents DROP COLUMN IF EXISTS device_id');
            DB::statement('ALTER TABLE incidents ALTER COLUMN employee_id SET NOT NULL');
        });
    }
};
