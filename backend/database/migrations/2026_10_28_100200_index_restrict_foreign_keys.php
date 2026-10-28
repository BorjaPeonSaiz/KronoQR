<?php

declare(strict_types=1);

use App\Support\Database\LimitsMigrationLocks;
use Illuminate\Database\Migrations\Migration;

/**
 * Cada clave ajena `RESTRICT` tiene un indice que empieza por su columna
 * (RNF-P-02, regla dura 5; hallazgo V21-5-BE-4 de la 2.2.1).
 *
 * Al borrar una fila referenciada —o al cambiar su clave— PostgreSQL comprueba
 * cada clave ajena que la apunta con
 * `SELECT 1 FROM ONLY hija x WHERE columna = $1 FOR KEY SHARE OF x`. Sin indice
 * por esa columna, es un `Seq Scan` de la hija **por cada fila borrada**: lo
 * que costaba `incidents.shift_entry_id` en la purga hasta `2026_10_16_100000`.
 * Hoy casi nadie borra un padre —empleados y cuentas se minimizan en sitio—,
 * pero la minimizacion de personal si borra ausencias, y las ausencias se
 * apuntan entre si (`supersedes_id`, `superseded_by_id`). Y una clave ajena
 * nueva no deberia poder llegar sin su indice: lo vigila
 * `RestrictForeignKeyIndexesTest`, que falla si queda alguna.
 *
 * ## Parciales donde la columna admite `NULL`
 *
 * Casi todas son «quien hizo esto» o «a quien se atribuye», nulas en la mayoria
 * de filas. El indice parcial `WHERE columna IS NOT NULL` sirve igual a la
 * comprobacion —`columna = $1` implica que no es nula— y no cuesta escritura en
 * la fila que no la lleva: en `scan_events`, solo el intento de PIN rechazado
 * (`claimed_employee_id`) escribe en el suyo. Las dos `NOT NULL`
 * (`support_grants.granted_by_user_id` y `site_timezone_changes.site_id`) van
 * completas.
 *
 * ## Cuatro que parecian tenerlo
 *
 * `employees.site_id`, `credentials.employee_id`, `incidents.assigned_to_user_id`
 * y `discarded_scan_reports.device_id` ya encabezan un indice, pero **parcial
 * con otro predicado** (`status = 'active'`, la credencial pendiente o activa,
 * la incidencia abierta, el aviso del servidor). La comprobacion busca todas
 * las filas de esa columna y no puede usarlos. Llevan el suyo.
 *
 * ## Patron `/migracion-segura`: expand puro
 *
 * | Despliegue | Que se hace | Estado del codigo |
 * |---|---|---|
 * | **1 (expand)** | Diecinueve indices nuevos. Ninguna fila cambia. | Ningun codigo los nombra. |
 * | **2 (migrate)** | *No aplica.* | — |
 * | **3 (contract)** | *No aplica.* | — |
 *
 * `CONCURRENTLY` con `createIndexConcurrently()`, tambien en las tablas
 * pequeñas: `scan_events` e `incidents` crecen y las demas no justifican otro
 * camino. `down()` los retira en orden inverso; lo verifica
 * `MigrationsRoundTripTest`.
 */
return new class extends Migration
{
    use LimitsMigrationLocks;

    /** Ver el docblock: `CREATE INDEX CONCURRENTLY` no puede ir en una transaccion. */
    public $withinTransaction = false;

    /** @var array<string, string> */
    private const array INDEXES = [
        'sites_compliance_profile_id_index' => 'ON sites (compliance_profile_id) WHERE compliance_profile_id IS NOT NULL',
        'employees_site_id_index' => 'ON employees (site_id)',
        'credentials_employee_id_index' => 'ON credentials (employee_id)',
        'incidents_assigned_to_user_id_index' => 'ON incidents (assigned_to_user_id) WHERE assigned_to_user_id IS NOT NULL',
        'discarded_scan_reports_device_id_index' => 'ON discarded_scan_reports (device_id)',
        'employees_pin_delivered_by_user_id_index' => 'ON employees (pin_delivered_by_user_id) WHERE pin_delivered_by_user_id IS NOT NULL',
        'credentials_delivered_by_user_id_index' => 'ON credentials (delivered_by_user_id) WHERE delivered_by_user_id IS NOT NULL',
        'scan_events_claimed_employee_id_index' => 'ON scan_events (claimed_employee_id) WHERE claimed_employee_id IS NOT NULL',
        'installation_settings_updated_by_user_id_index' => 'ON installation_settings (updated_by_user_id) WHERE updated_by_user_id IS NOT NULL',
        'incidents_device_id_index' => 'ON incidents (device_id) WHERE device_id IS NOT NULL',
        'incidents_resolved_by_user_id_index' => 'ON incidents (resolved_by_user_id) WHERE resolved_by_user_id IS NOT NULL',
        'employment_contracts_created_by_user_id_index' => 'ON employment_contracts (created_by_user_id) WHERE created_by_user_id IS NOT NULL',
        'support_grants_granted_by_user_id_index' => 'ON support_grants (granted_by_user_id)',
        'absences_created_by_user_id_index' => 'ON absences (created_by_user_id) WHERE created_by_user_id IS NOT NULL',
        'absences_superseded_by_id_index' => 'ON absences (superseded_by_id) WHERE superseded_by_id IS NOT NULL',
        'absences_supersedes_id_index' => 'ON absences (supersedes_id) WHERE supersedes_id IS NOT NULL',
        'absences_voided_by_user_id_index' => 'ON absences (voided_by_user_id) WHERE voided_by_user_id IS NOT NULL',
        'discarded_scan_reports_owner_employee_id_index' => 'ON discarded_scan_reports (owner_employee_id) WHERE owner_employee_id IS NOT NULL',
        'site_timezone_changes_site_id_index' => 'ON site_timezone_changes (site_id)',
    ];

    public function up(): void
    {
        foreach (self::INDEXES as $name => $definition) {
            $this->createIndexConcurrently($name, $definition);
        }
    }

    public function down(): void
    {
        foreach (array_reverse(array_keys(self::INDEXES)) as $name) {
            $this->dropIndexConcurrently($name);
        }
    }
};
