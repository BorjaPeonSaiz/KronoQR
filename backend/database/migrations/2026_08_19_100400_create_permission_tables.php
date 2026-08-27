<?php

declare(strict_types=1);

use App\Support\Database\LimitsMigrationLocks;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `roles`, `permissions` y sus tres pivotes — el RBAC de RF-ID-02.
 *
 * Los nombres de tabla y de columna son los **valores por defecto de
 * `spatie/laravel-permission`** (`vendor/spatie/laravel-permission/config/permission.php`),
 * escritos aqui literalmente en lugar de leidos de `config('permission.*')`.
 * Dos motivos: el esquema de una instalacion no puede depender de un fichero de
 * configuracion que se cachea —una migracion que lee configuracion falla en el
 * servidor del cliente por un `config:cache` viejo—, y con los nombres a la
 * vista se puede razonar sobre las claves foraneas sin abrir el paquete.
 *
 * La funcionalidad de *teams* del paquete esta **desactivada** y no se
 * provisiona su columna: este producto no es multi-tenencia (CLAUDE.md). El
 * ambito por departamento de RF-ID-03 no es un *team* de Spatie, es una policy
 * sobre el recurso, y llega en la tarea 2.1.
 *
 * Los seis roles del catalogo —`admin`, `rrhh`, `responsable_departamento`,
 * `auditor`, `empleado`, `kiosk`— y su reparto de permisos **no se siembran
 * aqui**: son de la tarea 1.6, que es la que sabe que permisos existen.
 */
return new class extends Migration
{
    use LimitsMigrationLocks;

    public function up(): void
    {
        $this->limitLockWait();

        Schema::create('permissions', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 190);
            $table->string('guard_name', 64);
            $table->timestampsTz(6);

            $table->unique(['name', 'guard_name'], 'permissions_name_guard_name_unique');
        });

        Schema::create('roles', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 190);
            $table->string('guard_name', 64);
            $table->timestampsTz(6);

            $table->unique(['name', 'guard_name'], 'roles_name_guard_name_unique');
        });

        Schema::create('model_has_permissions', function (Blueprint $table): void {
            $table->unsignedBigInteger('permission_id');
            $table->string('model_type', 190);
            $table->unsignedBigInteger('model_id');

            $table->index(['model_id', 'model_type'], 'model_has_permissions_model_id_model_type_index');

            $table->foreign('permission_id')
                ->references('id')->on('permissions')
                ->cascadeOnDelete();

            $table->primary(
                ['permission_id', 'model_id', 'model_type'],
                'model_has_permissions_permission_model_type_primary',
            );
        });

        Schema::create('model_has_roles', function (Blueprint $table): void {
            $table->unsignedBigInteger('role_id');
            $table->string('model_type', 190);
            $table->unsignedBigInteger('model_id');

            $table->index(['model_id', 'model_type'], 'model_has_roles_model_id_model_type_index');

            $table->foreign('role_id')
                ->references('id')->on('roles')
                ->cascadeOnDelete();

            $table->primary(
                ['role_id', 'model_id', 'model_type'],
                'model_has_roles_role_model_type_primary',
            );
        });

        Schema::create('role_has_permissions', function (Blueprint $table): void {
            $table->unsignedBigInteger('permission_id');
            $table->unsignedBigInteger('role_id');

            $table->foreign('permission_id')
                ->references('id')->on('permissions')
                ->cascadeOnDelete();

            $table->foreign('role_id')
                ->references('id')->on('roles')
                ->cascadeOnDelete();

            $table->primary(
                ['permission_id', 'role_id'],
                'role_has_permissions_permission_id_role_id_primary',
            );
        });
    }

    public function down(): void
    {
        $this->limitLockWait();

        // Orden inverso al de creacion: los pivotes referencian a las dos
        // tablas base.
        Schema::dropIfExists('role_has_permissions');
        Schema::dropIfExists('model_has_roles');
        Schema::dropIfExists('model_has_permissions');
        Schema::dropIfExists('roles');
        Schema::dropIfExists('permissions');
    }
};
