<?php

declare(strict_types=1);

use App\Support\Database\LimitsMigrationLocks;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * `daily_totals` — **proyeccion de lectura reconstruible**, no fuente de verdad
 * (doc 01 §5.5, RN-06, ADR-007, regla dura 7).
 *
 * Todo lo que hay aqui se puede volver a calcular a partir de `shift_entries`.
 * Si esta tabla se pierde entera, no se ha perdido registro horario: se
 * reconstruye. Esa es la razon de que el total **se recalcule** en la misma
 * transaccion que el tramo, como suma de los tramos vigentes de la jornada, y
 * **nunca se incremente** de forma acumulativa. Un acumulador seria correcto
 * hasta la primera correccion.
 *
 * El UNIQUE `(employee_id, work_date)` es lo que permite escribirla con un
 * `INSERT ... ON CONFLICT DO UPDATE` y que dos transacciones concurrentes del
 * mismo empleado no creen dos filas del mismo dia.
 *
 * `recalculated_at` no es decoracion: la reconciliacion nocturna de RF-PR-02
 * compara la proyeccion con los eventos origen y necesita saber de cuando es lo
 * que esta comparando.
 */
return new class extends Migration
{
    use LimitsMigrationLocks;

    public function up(): void
    {
        $this->limitLockWait();

        Schema::create('daily_totals', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('employee_id')
                ->constrained('employees')
                ->restrictOnDelete();

            $table->date('work_date');

            // Minutos enteros, nunca coma flotante: un total de jornada acaba
            // en una nomina.
            $table->integer('total_minutes')->default(0);
            $table->integer('shift_count')->default(0);

            $table->timestampTz('first_in_at', 6)->nullable();
            $table->timestampTz('last_out_at', 6)->nullable();

            $table->boolean('has_open_shift')->default(false);
            $table->boolean('has_incident')->default(false);

            $table->timestampTz('recalculated_at', 6);

            $table->unique(['employee_id', 'work_date'], 'daily_totals_employee_id_work_date_unique');
        });

        DB::statement(<<<'SQL'
            ALTER TABLE daily_totals
                ADD CONSTRAINT daily_totals_chk_counters_not_negative
                CHECK (total_minutes >= 0 AND shift_count >= 0)
        SQL);

        // El panel de presencia en vivo y los informes por centro preguntan
        // «este dia, quien»: el UNIQUE de arriba empieza por `employee_id` y no
        // sirve para esa consulta.
        DB::statement(<<<'SQL'
            CREATE INDEX daily_totals_work_date_index
                ON daily_totals (work_date)
        SQL);
    }

    public function down(): void
    {
        $this->limitLockWait();

        Schema::dropIfExists('daily_totals');
    }
};
