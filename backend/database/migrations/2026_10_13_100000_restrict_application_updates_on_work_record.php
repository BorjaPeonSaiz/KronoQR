<?php

declare(strict_types=1);

use App\Modules\Compliance\Infrastructure\Persistence\AuditLogSchema;
use App\Support\Database\LimitsMigrationLocks;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * El rol de la aplicacion deja de poder reescribir el registro horario
 * (ADR-057 §1, bloque 6 de la 2.2.1; RL-04, RS-07, RN-13, reglas duras 5 y 6).
 *
 * ## El problema
 *
 * La provision (`2026_08_19_099000`) concede a `fichaje_app` las cuatro
 * operaciones sobre toda tabla nueva, y solo `audit_log` y
 * `audit_chain_anchors` lo recortaban. Con ejecucion de codigo en el runtime se
 * podia cambiar el `clocked_in_at` de un tramo, el `before`/`after` de una
 * correccion o el `result` de un escaneo, y la exportacion para Inspeccion
 * saldria manipulada con la cadena de auditoria en verde.
 *
 * ## Lo que queda, tabla a tabla
 *
 * | Tabla | `UPDATE` | `DELETE` |
 * |---|---|---|
 * | `shift_entries` | solo `clocked_out_at`, `duration_minutes`, `status`, `clock_out_source`, `superseded_by_id`, `updated_at` | si, solo para la purga (hasta B7) |
 * | `shift_corrections` | no | si, solo para la purga (hasta B7) |
 * | `scan_events` | no | si, solo para la purga (hasta B7) |
 * | `discarded_scan_reports` | no (ya no lo tenia desde su creacion) | si, solo para la purga (hasta B7) |
 * | `incidents` | solo `status`, `resolved_at`, `resolved_by_user_id`, `resolution_note`, `notified_at`, `updated_at` | si, solo para la purga (hasta B7) |
 * | `daily_totals` | si, entera: es una proyeccion reconstruible (ADR-007) | si, solo para la purga (hasta B7) |
 *
 * Las columnas son exactamente las que escriben `EloquentWorkDayRepository`
 * (el `upsert` por `uuid` y `linkSupersededEntries`), `DatabaseIncidentLedger`
 * (resolucion) y `DatabaseIncidentNotices::markNotified`. Un `INSERT … ON
 * CONFLICT DO UPDATE SET …` solo exige `UPDATE` sobre las columnas del `SET`,
 * y por eso el `upsert` de tramos dejo de incluir `version` (ADR-035) antes de
 * esta migracion.
 *
 * ## Por que el `DELETE` se queda, todavia
 *
 * `DatabaseWorkRecordArchive::purge` borra de **las seis** tablas con la
 * conexion de la aplicacion, en la misma transaccion que su asiento en
 * `audit_log`, que el rol de mantenimiento no puede escribir. Retirar aqui el
 * `DELETE` romperia la retencion legal (RL-02). El orden lo fija el plan de la
 * 2.2.1: el bloque 7 pasa la purga a la funcion `SECURITY DEFINER`
 * `work_record_purge_batch` (ADR-057 §3) y, en esa misma migracion, retira el
 * `DELETE` de las seis.
 *
 * ## `TRUNCATE`, `REFERENCES` y `TRIGGER`
 *
 * La provision nunca los concedio. Se revocan igual, de forma explicita: una
 * base restaurada de un cluster preparado a mano podria traerlos, y `TRUNCATE`
 * se salta los privilegios de fila y la purga. `down()` no los devuelve.
 *
 * ## Columna a columna, en este orden
 *
 * `REVOKE UPDATE ON TABLE` retira tambien los privilegios de columna que
 * hubiera; despues se conceden solo los de la lista. Al reves, el `GRANT` de
 * columna no serviria de nada mientras siguiera puesto el de tabla.
 *
 * ## Lo que esto no impide
 *
 * Cerrar un tramo abierto con una hora falsa o insertar uno inventado: son las
 * escrituras que el fichaje necesita. Lo detecta la conciliacion de ADR-057 §4.
 *
 * **Hasta el bloque 7 el residuo es mas amplio.** Un privilegio de columna no
 * distingue filas ni valores anteriores: eso lo hace el *trigger* de
 * transiciones de ADR-057 §2, que llega en el bloque 7. Hasta entonces, con la
 * credencial del runtime se puede ademas:
 *
 * - reescribir `clocked_out_at`, `duration_minutes` y `clock_out_source` de
 *   **cualquier** tramo ya cerrado, de cualquier antiguedad;
 * - pasar a `voided` o `superseded` un tramo vigente, que desaparece de la
 *   exportacion para la Inspeccion, y poner un `superseded_by_id` arbitrario;
 * - reescribir la resolucion de una incidencia ya cerrada (`status`,
 *   `resolved_at`, `resolved_by_user_id`, `resolution_note`): el
 *   `WHERE status = 'open'` de `DatabaseIncidentLedger` es del codigo, no del
 *   esquema.
 *
 * Lo de `shift_entries` lo detecta la conciliacion de ADR-057 §4 —la diaria
 * dentro de su ventana, la semanal sobre todo el registro—, porque compara
 * marcas, estado y `superseded_by` con el ultimo asiento `shift_entry.*`;
 * salvo que la escritura vaya acompañada de asientos falsificados. La
 * conciliacion no cubre `incidents`: de una resolucion reescrita solo queda el
 * asiento `incident.resolved` original en `audit_log`, que no se puede tocar.
 *
 * ## Sin filas, sin bloqueos largos
 *
 * Solo catalogo: no lee ni reescribe filas, y cada `GRANT`/`REVOKE` toca una
 * fila de `pg_class` o de `pg_attribute`. `lock_timeout` corto por si una
 * transaccion larga tuviera la tabla tomada en un modo incompatible.
 *
 * ## Reversible
 *
 * `down()` devuelve `UPDATE` de tabla a `shift_entries`, `shift_corrections`,
 * `scan_events` e `incidents`, que es lo que tenian. `discarded_scan_reports`
 * sigue sin `UPDATE` (su migracion de creacion ya se lo quitaba) y
 * `daily_totals` no cambia en ningun sentido.
 *
 * ## Una instalacion que vuelva a ejecutar la provision
 *
 * No hay forma de anticiparlo en la provision, que corre antes de que existan
 * estas tablas: su `GRANT … ON ALL TABLES` lo devolveria todo. Lo comprueba y
 * lo repara `update.sh` (tarea 6.4) con esta misma lista:
 * `work_record_privileges_drift` lo comprueba por conjunto exacto y
 * `repair_work_record_privileges` lo reaplica.
 */
return new class extends Migration
{
    use LimitsMigrationLocks;

    /**
     * Las columnas que el dominio escribe en una fila ya existente.
     *
     * @var array<string, list<string>>
     */
    private const array UPDATABLE_COLUMNS = [
        'shift_entries' => [
            'clocked_out_at',
            'duration_minutes',
            'status',
            'clock_out_source',
            'superseded_by_id',
            'updated_at',
        ],
        'incidents' => [
            'status',
            'resolved_at',
            'resolved_by_user_id',
            'resolution_note',
            'notified_at',
            'updated_at',
        ],
    ];

    /**
     * Las que no admiten ningun `UPDATE` de la aplicacion.
     *
     * @var list<string>
     */
    private const array APPEND_ONLY = [
        'shift_corrections',
        'scan_events',
        'discarded_scan_reports',
    ];

    /**
     * Las seis tablas del registro horario de ADR-057 §1.
     *
     * @var list<string>
     */
    private const array WORK_RECORD = [
        'shift_entries',
        'shift_corrections',
        'scan_events',
        'discarded_scan_reports',
        'incidents',
        'daily_totals',
    ];

    /**
     * Las que tenian `UPDATE` de tabla antes de esta migracion.
     *
     * @var list<string>
     */
    private const array HAD_TABLE_UPDATE = [
        'shift_entries',
        'shift_corrections',
        'scan_events',
        'incidents',
    ];

    public function up(): void
    {
        $this->limitLockWait();

        $application = $this->applicationRole();

        foreach (self::WORK_RECORD as $table) {
            DB::statement('REVOKE TRUNCATE, REFERENCES, TRIGGER ON TABLE '.$table.' FROM '.$application);
        }

        foreach (self::APPEND_ONLY as $table) {
            DB::statement('REVOKE UPDATE ON TABLE '.$table.' FROM '.$application);
        }

        foreach (self::UPDATABLE_COLUMNS as $table => $columns) {
            DB::statement('REVOKE UPDATE ON TABLE '.$table.' FROM '.$application);
            DB::statement('GRANT UPDATE ('.implode(', ', $columns).') ON TABLE '.$table.' TO '.$application);
        }
    }

    public function down(): void
    {
        $this->limitLockWait();

        $application = $this->applicationRole();

        foreach (self::HAD_TABLE_UPDATE as $table) {
            // Primero se limpian los de columna, para no dejar privilegios
            // sueltos que sobrevivan a un `REVOKE` de tabla posterior.
            DB::statement('REVOKE UPDATE ON TABLE '.$table.' FROM '.$application);
            DB::statement('GRANT UPDATE ON TABLE '.$table.' TO '.$application);
        }
    }

    private function applicationRole(): string
    {
        return '"'.AuditLogSchema::applicationRole().'"';
    }
};
