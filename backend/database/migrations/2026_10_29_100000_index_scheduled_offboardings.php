<?php

declare(strict_types=1);

use App\Support\Database\LimitsMigrationLocks;
use Illuminate\Database\Migrations\Migration;

/**
 * `scheduled_offboardings`: el indice parcial de las **bajas programadas**
 * (RN-14, RF-GP-03, ADR-062 §3 y §4).
 *
 * La baja programada no es un valor de `status`: es una ficha que no esta de
 * baja y ya tiene fecha de cese. La tarea `workforce:apply-scheduled-offboardings`
 * pregunta cada hora por las vencidas (`terminated_at < hoy`), y
 * `product:doctor` y la metrica de la alerta `ScheduledOffboardingOverdue`, por
 * las que siguen pendientes pasado su tope. Con el indice parcial la pregunta
 * recorre solo esas filas —casi siempre ninguna o unas pocas—, no la plantilla
 * con todo su historico de bajas.
 *
 * **No hace falta ninguna restriccion nueva para el par estado–fecha**: la base
 * ya admitia una ficha `active` con `terminated_at` (`employees_chk_terminated_has_date`
 * solo exige la fecha a `terminated`) y la de `employees_chk_terminated_after_hired`
 * sigue valiendo. La tabla `employment_periods` y los disparadores de ADR-062 §1
 * y §2 llegan con la readmision, que es el primer camino que saca a alguien de
 * `terminated`: la baja programada no lo hace.
 *
 * ## Patron `/migracion-segura`: expand puro
 *
 * | Despliegue | Que se hace | Estado del codigo |
 * |---|---|---|
 * | **1 (expand)** | Un indice parcial. Ninguna fila cambia. | El codigo anterior no lo usa y no le estorba. |
 * | **2 (migrate)** | *No aplica.* | — |
 * | **3 (contract)** | *No aplica.* | — |
 *
 * `employees` tiene datos en toda instalacion: `CONCURRENTLY`, sin bloquear el
 * fichaje, que lee la ficha con `FOR KEY SHARE`. `down()` lo retira, y lo
 * verifica `MigrationsRoundTripTest`.
 */
return new class extends Migration
{
    use LimitsMigrationLocks;

    /** `CREATE INDEX CONCURRENTLY` no puede ir en una transaccion. */
    public $withinTransaction = false;

    private const string INDEX = 'scheduled_offboardings';

    public function up(): void
    {
        $this->createIndexConcurrently(
            self::INDEX,
            "ON employees (terminated_at) WHERE status <> 'terminated' AND terminated_at IS NOT NULL",
        );
    }

    public function down(): void
    {
        $this->dropIndexConcurrently(self::INDEX);
    }
};
