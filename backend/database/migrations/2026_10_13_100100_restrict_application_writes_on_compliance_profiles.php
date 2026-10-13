<?php

declare(strict_types=1);

use App\Modules\Compliance\Infrastructure\Persistence\AuditLogSchema;
use App\Support\Database\LimitsMigrationLocks;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * El suelo legal de conservacion no se esquiva desde el runtime (tarea 6.5 del
 * bloque 6 de la 2.2.1, hallazgo ALTO de `seguridad-cumplimiento` en la
 * revision del bloque 5; ADR-057 enmienda del 10-10-2026 y la del 13-10-2026;
 * RL-02, RS-07).
 *
 * ## El problema
 *
 * El suelo de `retention_years` es por jurisdiccion (4 años en España) y lo
 * hacen cumplir la edicion del perfil, la purga, la conciliacion y un `CHECK`
 * que mira `jurisdiction`. Pero `fichaje_app` tenia las cuatro operaciones sobre
 * `compliance_profiles` y sobre `sites`, asi que con ejecucion de codigo podia:
 *
 * - cambiar `jurisdiction` de `ES` a otro pais, salir del `CHECK` español y
 *   bajar `retention_years` a 1;
 * - crear un perfil nuevo con otra jurisdiccion, o marcar otro `is_default`;
 * - reasignar `sites.compliance_profile_id` a ese perfil, o borrar y volver a
 *   crear el centro con el.
 *
 * La purga y la conciliacion resuelven el suelo por esas filas y habrian
 * juzgado con el suelo de la fila manipulada.
 *
 * ## Lo que la aplicacion de verdad escribe
 *
 * - `compliance_profiles`: solo `DatabaseComplianceProfileRepository::save`
 *   (`PUT /compliance-profile` del panel y el paso del asistente), que
 *   actualiza `name`, los cuatro umbrales, `week_starts_on`,
 *   `holiday_calendar`, `retention_years`, `updated_at` y
 *   `updated_by_user_id`. **Nunca inserta ni borra**: el perfil por defecto lo
 *   crea la migracion de la tabla con el rol de migracion, y ni el asistente
 *   ni `install.sh` crean otro.
 * - `sites`: `EloquentSiteRepository` inserta y actualiza `name` y `timezone`
 *   (`POST`/`PATCH /site`). `compliance_profile_id` y `settings` no los escribe
 *   ningun codigo de la aplicacion; el centro nace sin perfil y cae en el de
 *   `is_default`.
 *
 * ## Lo que queda
 *
 * | Tabla | `SELECT` | `INSERT` | `UPDATE` | `DELETE` |
 * |---|---|---|---|---|
 * | `compliance_profiles` | si | **no** | solo las columnas de arriba | **no** |
 * | `sites` | si | solo `name`, `timezone` | solo `name`, `timezone` | si (sin cambio) |
 *
 * El `INSERT` de `sites` se limita tambien por columna porque, si no, borrar el
 * centro y volver a crearlo con `compliance_profile_id` seria el mismo atajo.
 * `DELETE` sobre `sites` no se toca: las claves ajenas `RESTRICT` lo impiden en
 * cuanto hay plantilla, y una fila nueva ya no puede traer perfil.
 *
 * `SELECT … FOR KEY SHARE` sobre `sites` (`DatabaseParentRowLocks`) exige
 * `UPDATE` sobre al menos una columna: lo da el de `name`.
 *
 * ## Columna a columna, en este orden
 *
 * `REVOKE … ON TABLE` retira tambien los privilegios de columna que hubiera;
 * despues se conceden solo los de la lista.
 *
 * ## Sin filas, sin bloqueos largos
 *
 * Solo catalogo. `lock_timeout` corto.
 *
 * ## Reversible
 *
 * `down()` devuelve `INSERT`, `UPDATE` y `DELETE` de tabla sobre
 * `compliance_profiles` e `INSERT` y `UPDATE` de tabla sobre `sites`, que es lo
 * que tenian. `TRUNCATE`, `REFERENCES` y `TRIGGER` no se devuelven: la
 * provision nunca los concedio.
 *
 * ## Una instalacion que vuelva a ejecutar la provision
 *
 * Su `GRANT … ON ALL TABLES` lo devolveria todo. Lo comprueba y lo repara
 * `update.sh` (tarea 6.4), con esta misma lista: `work_record_privileges_drift`
 * lo comprueba por conjunto exacto y `repair_work_record_privileges` lo
 * reaplica.
 */
return new class extends Migration
{
    use LimitsMigrationLocks;

    /**
     * Lo que edita el panel y nada mas: ni `jurisdiction`, ni `is_default`,
     * ni `id`.
     *
     * @var list<string>
     */
    private const array PROFILE_EDITABLE_COLUMNS = [
        'name',
        'retention_years',
        'min_rest_hours',
        'max_daily_hours',
        'max_weekly_hours',
        'break_required_after_hours',
        'week_starts_on',
        'holiday_calendar',
        'updated_at',
        'updated_by_user_id',
    ];

    /**
     * Lo que escribe `EloquentSiteRepository`, al crear y al editar. Sin
     * `compliance_profile_id` ni `settings`.
     *
     * @var list<string>
     */
    private const array SITE_WRITABLE_COLUMNS = [
        'name',
        'timezone',
    ];

    public function up(): void
    {
        $this->limitLockWait();

        $application = $this->applicationRole();

        DB::statement('REVOKE INSERT, UPDATE, DELETE, TRUNCATE, REFERENCES, TRIGGER ON TABLE compliance_profiles FROM '.$application);
        DB::statement(
            'GRANT UPDATE ('.implode(', ', self::PROFILE_EDITABLE_COLUMNS).') ON TABLE compliance_profiles TO '.$application
        );

        DB::statement('REVOKE INSERT, UPDATE, TRUNCATE, REFERENCES, TRIGGER ON TABLE sites FROM '.$application);
        DB::statement('GRANT INSERT ('.implode(', ', self::SITE_WRITABLE_COLUMNS).') ON TABLE sites TO '.$application);
        DB::statement('GRANT UPDATE ('.implode(', ', self::SITE_WRITABLE_COLUMNS).') ON TABLE sites TO '.$application);
    }

    public function down(): void
    {
        $this->limitLockWait();

        $application = $this->applicationRole();

        // Primero se limpian los de columna, para no dejar privilegios sueltos
        // que sobrevivan a un `REVOKE` de tabla posterior.
        DB::statement('REVOKE UPDATE ON TABLE compliance_profiles FROM '.$application);
        DB::statement('GRANT INSERT, UPDATE, DELETE ON TABLE compliance_profiles TO '.$application);

        DB::statement('REVOKE INSERT, UPDATE ON TABLE sites FROM '.$application);
        DB::statement('GRANT INSERT, UPDATE ON TABLE sites TO '.$application);
    }

    private function applicationRole(): string
    {
        return '"'.AuditLogSchema::applicationRole().'"';
    }
};
