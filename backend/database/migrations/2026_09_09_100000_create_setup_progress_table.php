<?php

declare(strict_types=1);

use App\Support\Database\LimitsMigrationLocks;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * `setup_progress` — lo que el asistente de puesta en marcha ya ha resuelto
 * (RF-PD-03, tarea 5.5).
 *
 * ## Por que una tabla y no una clave de `installation_settings`
 *
 * Aquel catalogo es **la configuracion documentada del cliente** (RF-PD-01): lo
 * que se le explica en `docs/cliente/configuracion.md`, lo que sale en
 * `GET /api/v1/settings` y lo que se audita como cambio de parametro. El
 * progreso de un asistente que se ejecuta una sola vez no es nada de eso, y
 * meterlo ahi obligaria a explicarle al cliente ocho claves que no debe tocar.
 *
 * ## Solo se guarda lo que no se puede deducir
 *
 * Los pasos `administrator` y `site` **no tienen fila nunca**: su estado se
 * calcula del dato real —una cuenta de gestion con segundo factor confirmado, y
 * un centro creado—. Es lo que impide que una restauracion parcial, un `PUT`
 * mal dirigido o una fila perdida hagan que el asistente mienta sobre si hay
 * administrador. Lo derivable se deriva; lo que es una decision («omito la
 * licencia por ahora») se guarda, porque no hay dato del que deducirla.
 *
 * ## El cierre del asistente es una fila mas, con la clave `completion`
 *
 * Y no una segunda tabla de una fila. `completion` **no es un paso** —no
 * aparece en el enum `SetupStep` ni viaja en el contrato— sino la constancia de
 * que el asistente se cerro, con su instante y su autor. Una tabla aparte para
 * tres columnas habria duplicado el repositorio, la migracion y la prueba para
 * responder a la misma pregunta.
 *
 * ## Que se conserva del actor
 *
 * `recorded_by_user_id` es nullable y se anula si la cuenta desaparece. **No es
 * el registro legal de quien hizo que** —eso es `audit_log`, que es solo-append
 * y encadenado por hash (regla dura 6)—: aqui esta para que la pantalla del
 * asistente pueda decir «esto lo dejaste tu» sin consultar el trail.
 */
return new class extends Migration
{
    use LimitsMigrationLocks;

    /**
     * Los ocho pasos del contrato mas la marca de cierre.
     *
     * Se escriben en el `CHECK` a proposito, y no solo en el enum de PHP: es la
     * ultima linea de defensa del §3.2 aplicada tambien aqui. Una fila con un
     * `step` inventado —por una restauracion antigua o por una version que ya no
     * existe— dejaria el asistente calculando su estado a partir de algo que no
     * sabe interpretar.
     *
     * @var list<string>
     */
    private const array STEPS = [
        'administrator',
        'organisation',
        'site',
        'departments',
        'compliance_profile',
        'employees',
        'license',
        'kiosk',
        'completion',
    ];

    public function up(): void
    {
        $this->limitLockWait();

        Schema::create('setup_progress', function (Blueprint $table): void {
            // La clave primaria es el paso: un paso tiene un estado y solo uno,
            // y esa unicidad es la que hace que el `PUT` del contrato sea
            // idempotente sin ninguna comprobacion previa.
            $table->string('step', 32)->primary();

            $table->string('state', 16);

            // Regla dura 3: instante en UTC, como todos.
            $table->timestampTz('recorded_at', 6);

            $table->foreignId('recorded_by_user_id')
                ->nullable()
                ->constrained('users')
                // La cuenta que puso en marcha la instalacion puede darse de
                // baja despues; el hecho de que el paso se resolvio no
                // desaparece con ella.
                ->nullOnDelete();
        });

        DB::statement(sprintf(
            'ALTER TABLE setup_progress ADD CONSTRAINT setup_progress_chk_step CHECK (step IN (%s))',
            implode(', ', array_map(static fn (string $step): string => "'".$step."'", self::STEPS)),
        ));

        // `pending` NO es un estado que se guarde: es la ausencia de fila. Con
        // `pending` en la tabla habria dos formas de decir lo mismo y la
        // consulta tendria que tratarlas igual para siempre.
        DB::statement(<<<'SQL'
            ALTER TABLE setup_progress
                ADD CONSTRAINT setup_progress_chk_state
                CHECK (state IN ('completed', 'skipped'))
        SQL);

        // El cierre del asistente no se «omite»: se completa o no existe.
        DB::statement(<<<'SQL'
            ALTER TABLE setup_progress
                ADD CONSTRAINT setup_progress_chk_completion_is_completed
                CHECK (step <> 'completion' OR state = 'completed')
        SQL);
    }

    public function down(): void
    {
        $this->limitLockWait();

        Schema::dropIfExists('setup_progress');
    }
};
