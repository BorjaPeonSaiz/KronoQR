<?php

declare(strict_types=1);

use App\Support\Database\LimitsMigrationLocks;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * `incidents.type` admite `out_of_order_scan` (**RN-18**, doc 01 §5.5, tarea ad
 * hoc del 18-09-2026).
 *
 * ## Que problema resuelve
 *
 * El fichaje irreconciliable se registra y **se le pasa a una persona**: el
 * sistema no puede inventar a que hora entro o salio alguien, asi que lo unico
 * honesto es dejar constancia y abrir una incidencia que se cierra con una
 * correccion trazada (RN-13, RF-PA-04). La abre la revision diaria leyendo
 * `scan_events.result`, igual que hace con `clock_skew`, y sin esta migracion el
 * primer hallazgo chocaria contra `incidents_chk_type` y la pasada nocturna
 * contaria un fallo por cada uno.
 *
 * **Una por empleado y jornada.** La restriccion `one_incident_per_finding`
 * —`(employee_id, work_date, type, shift_entry_id)` con `NULLS NOT DISTINCT`— ya
 * lo garantiza sin cambio ninguno: estas incidencias llegan con
 * `shift_entry_id` nulo, y dos nulos son iguales para esa restriccion. Es lo que
 * hace que una cola offline con diez escaneos imposibles del mismo dia produzca
 * **una** fila, y que repetir la pasada no duplique nada.
 *
 * ## Patron `/migracion-segura`: expand puro, en tres pasos
 *
 * | Despliegue | Que se hace | Estado del codigo |
 * |---|---|---|
 * | **1 (expand)** | Esta migracion: el `CHECK` admite un tipo **mas**. Ninguna fila existente deja de cumplirlo. | La version anterior no abre incidencias de ese tipo: no nota nada. |
 * | **2 (migrate)** | *No aplica.* No hay filas que trasladar. | — |
 * | **3 (contract)** | *No aplica.* | — |
 *
 * El contrato ya lo admite —`IncidentType` gano el valor en el mismo cambio, y
 * ampliar un enum de respuesta es aditivo (ADR-012)—, asi que un panel de la
 * version anterior que reciba una incidencia de este tipo la enseña con su clave
 * sin traducir en vez de romperse.
 *
 * ## `DROP` + `ADD ... NOT VALID` + `VALIDATE`
 *
 * El mismo patron que en las dos migraciones que la acompañan: el `DROP` solo
 * toca el catalogo, `NOT VALID` evita recorrer la tabla con `ACCESS EXCLUSIVE` y
 * `VALIDATE` la recorre con `SHARE UPDATE EXCLUSIVE`, que no bloquea escrituras.
 * Se valida en la misma migracion porque el `CHECK` nuevo es mas permisivo y toda
 * fila existente lo cumple por construccion.
 *
 * ## `down()` verificado
 *
 * Vuelve al catalogo de ocho tipos, y **puede fallar a proposito**: si ya hay
 * incidencias `out_of_order_scan` abiertas o cerradas, la validacion las rechaza
 * y la reversion se detiene. Nada se borra (regla dura 5) y una incidencia
 * resuelta es parte del rastro de RN-13: que hacer con ellas es una decision
 * humana, no algo que se pueda dar por hecho en un `down()`.
 *
 * Se prueba en `tests/Integration/Schema/OutOfOrderScanMigrationsTest.php`,
 * ademas del ciclo completo de `MigrationsRoundTripTest`.
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
                    'out_of_order_scan'
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
                    'insufficient_rest', 'clock_skew', 'missing_clock_out', 'anomalous_pattern'
                ))
                NOT VALID
        SQL);

        // Se valida a proposito: con incidencias de RN-18 escritas, la reversion
        // se detiene en vez de dejar un catalogo que miente. Ver el docblock.
        DB::statement('ALTER TABLE incidents VALIDATE CONSTRAINT '.self::CONSTRAINT);
    }
};
