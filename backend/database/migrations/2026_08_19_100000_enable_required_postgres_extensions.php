<?php

declare(strict_types=1);

use App\Support\Database\LimitsMigrationLocks;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Las tres extensiones sin las cuales el esquema de la Fase 1 no se puede
 * crear (doc 02 §3.2).
 *
 * Ya las instala `infra/docker/postgres/initdb/00-extensions.sql`, pero ese
 * script solo se ejecuta **una vez, al inicializar el cluster, y solo sobre la
 * base de datos inicial**. Las extensiones son por base de datos: la base de
 * pruebas, la de un simulacro de restauracion o la de un cliente que cree la
 * base a mano no las tendrian. La migracion es el unico sitio que garantiza que
 * estan donde se van a usar.
 *
 * - `btree_gist` — RN-02. Sin ella no existe `shift_entries_no_overlap`: la
 *   restriccion de exclusion necesita combinar la igualdad de `employee_id`
 *   (btree) con el solape de `tstzrange` (gist).
 * - `pgcrypto` — hash del DNI (`employees.national_id_hash`, RL-08) y cadena de
 *   hash de la auditoria (RS-07, tarea 1.14).
 * - `citext` — `employees.employee_code` y los correos: un codigo de empleado
 *   no puede duplicarse por una diferencia de mayusculas.
 */
return new class extends Migration
{
    use LimitsMigrationLocks;

    public function up(): void
    {
        $this->limitLockWait();

        DB::statement('CREATE EXTENSION IF NOT EXISTS btree_gist');
        DB::statement('CREATE EXTENSION IF NOT EXISTS pgcrypto');
        DB::statement('CREATE EXTENSION IF NOT EXISTS citext');
    }

    /**
     * A proposito no hace nada, y esto **no** es un `down()` sin probar.
     *
     * Las extensiones no son del esquema de la aplicacion: las provisiona el
     * cluster en `initdb`, antes de que exista ninguna migracion. Un
     * `DROP EXTENSION` aqui desharia algo que esta migracion no creo en la
     * instalacion normal, y en una base compartida se llevaria por delante
     * objetos ajenos. Revertir el esquema deja las extensiones instaladas y
     * volver a migrar es idempotente: es exactamente lo que se quiere.
     */
    public function down(): void {}
};
