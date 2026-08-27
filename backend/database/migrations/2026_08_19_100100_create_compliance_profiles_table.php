<?php

declare(strict_types=1);

use App\Support\Database\LimitsMigrationLocks;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * `compliance_profiles` — los umbrales **legales** de la instalacion (RF-PD-07,
 * doc 01 §5.5).
 *
 * Es la primera tabla del esquema porque `sites` cuelga de ella y porque la
 * regla dura 14 no admite otra cosa: RN-10, RN-11 y RN-12 leen su umbral de
 * aqui desde el primer calculo, no desde la Fase 5. Un umbral escrito como
 * constante en PHP obligaria a tocar el repositorio para vender a un cliente
 * con otro convenio (ADR-017, regla dura 13).
 *
 * **La fila `ES-hosteleria` se siembra en la migracion y no en un seeder.** Un
 * seeder no se ejecuta en la instalacion de un cliente; sin el perfil de serie,
 * la primera jornada no tendria con que resolver el descanso minimo. Es dato de
 * producto, no dato de desarrollo.
 *
 * Los umbrales se expresan en **horas enteras**, que es como los nombra el
 * doc 01 §5.5 (`min_rest_hours`, `max_daily_hours`...). El dominio los recibe
 * en minutos a traves de `CompliancePolicy`; la conversion la hace el adaptador
 * del puerto.
 */
return new class extends Migration
{
    use LimitsMigrationLocks;

    public function up(): void
    {
        $this->limitLockWait();

        Schema::create('compliance_profiles', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 64)->unique('compliance_profiles_name_unique');
            $table->string('jurisdiction', 8);

            // RL-02: anos que se conserva el registro horario antes de la purga.
            $table->smallInteger('retention_years');
            // RN-10: descanso minimo entre el fin de un turno y el inicio del siguiente.
            $table->smallInteger('min_rest_hours');
            // RN-11: jornada diaria ordinaria por encima de la cual se alerta.
            $table->smallInteger('max_daily_hours');
            $table->smallInteger('max_weekly_hours');
            // RN-12: tramo continuo maximo sin pausa registrada.
            $table->smallInteger('break_required_after_hours');

            // Dia en que empieza la semana, en numeracion ISO-8601: 1 es lunes.
            // Importa para los informes semanales y para RN-11 agregada.
            $table->smallInteger('week_starts_on')->default(1);

            // Festivos de la jurisdiccion. JSONB y no una tabla porque se lee
            // entero y nunca se consulta por clave: sin consultas, no hay indice
            // GIN que anadir.
            $table->jsonb('holiday_calendar')->default(DB::raw("'[]'::jsonb"));

            $table->boolean('is_default')->default(false);
        });

        // Los cuatro umbrales son positivos por definicion. El dominio ya lo
        // comprueba en `CompliancePolicy`, pero el perfil se edita desde el
        // panel (tarea 5.1) y una jornada maxima de 0 h alertaria de todo:
        // la ultima linea de defensa tambien vale para la configuracion.
        DB::statement(<<<'SQL'
            ALTER TABLE compliance_profiles
                ADD CONSTRAINT compliance_profiles_chk_positive_thresholds
                CHECK (
                    retention_years > 0
                    AND min_rest_hours > 0
                    AND max_daily_hours > 0
                    AND max_weekly_hours > 0
                    AND break_required_after_hours > 0
                )
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE compliance_profiles
                ADD CONSTRAINT compliance_profiles_chk_week_starts_on
                CHECK (week_starts_on BETWEEN 1 AND 7)
        SQL);

        // Indice parcial: puede haber muchos perfiles, pero solo uno por
        // defecto. Expresarlo aqui evita que dos perfiles se disputen el
        // fallback de un centro sin perfil asignado.
        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX one_default_compliance_profile
                ON compliance_profiles ((true))
                WHERE is_default
        SQL);

        $this->seedSpanishHospitalityProfile();
    }

    public function down(): void
    {
        $this->limitLockWait();

        Schema::dropIfExists('compliance_profiles');
    }

    /**
     * El perfil espanol de hosteleria, «entregado de serie» (doc 01 §5.5).
     *
     * Los cuatro numeros salen del Estatuto de los Trabajadores tal y como los
     * cita el doc 01 §4: 12 h de descanso entre jornadas (art. 34.3), 9 h de
     * jornada diaria ordinaria, 6 h de tramo continuo antes de exigir pausa y
     * 4 anos de retencion (RL-02). Son **datos**: cambiarlos para un cliente es
     * editar una fila, no desplegar una version.
     *
     * `insertOrIgnore` para que la migracion sea idempotente si alguien la
     * reaplica sobre una base que ya tiene el perfil editado: la edicion del
     * cliente manda sobre el valor de serie.
     */
    private function seedSpanishHospitalityProfile(): void
    {
        DB::table('compliance_profiles')->insertOrIgnore([
            'name' => 'ES-hosteleria',
            'jurisdiction' => 'ES',
            'retention_years' => 4,
            'min_rest_hours' => 12,
            'max_daily_hours' => 9,
            'max_weekly_hours' => 40,
            'break_required_after_hours' => 6,
            'week_starts_on' => 1,
            'holiday_calendar' => '[]',
            'is_default' => true,
        ]);
    }
};
