<?php

declare(strict_types=1);

use App\Support\Database\LimitsMigrationLocks;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `departments` — departamentos de cada centro (doc 01 §5.5, RF-GP-01).
 *
 * `manager_user_id` es quien recibe las incidencias del departamento y quien,
 * a partir de la tarea 2.1, solo ve a los empleados de su departamento y su
 * centro (RF-ID-03). Es nullable porque un departamento puede existir antes de
 * tener responsable asignado, y porque desactivar a un usuario no puede dejar
 * un departamento sin fila.
 *
 * El nombre es unico **dentro del centro**, no en la instalacion: dos hoteles
 * del mismo cliente tienen los dos una «Recepcion».
 */
return new class extends Migration
{
    use LimitsMigrationLocks;

    public function up(): void
    {
        $this->limitLockWait();

        Schema::create('departments', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('site_id')
                ->constrained('sites')
                // Un centro con departamentos no se borra: primero se vacia.
                // Nada que cuelgue del registro horario desaparece en cascada
                // (regla dura 5).
                ->restrictOnDelete();

            $table->string('name', 120);

            $table->foreignId('manager_user_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->unique(['site_id', 'name'], 'departments_site_id_name_unique');
        });
    }

    public function down(): void
    {
        $this->limitLockWait();

        Schema::dropIfExists('departments');
    }
};
