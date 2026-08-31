<?php

declare(strict_types=1);

use App\Support\Database\LimitsMigrationLocks;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Topes superiores y coherencia entre los umbrales de `compliance_profiles`
 * (RF-PD-07, tarea 5.2).
 *
 * ## Por que ahora y no en la tarea 1.3
 *
 * Hasta esta tarea la tabla la escribia **solo la migracion que la creo**: los
 * cinco umbrales eran los del perfil `ES-hosteleria` y no habia forma de
 * cambiarlos que no fuera `psql`. La 5.2 abre el camino que faltaba —`PATCH
 * /api/v1/compliance-profile` y la pantalla del panel— y con el aparece la
 * unica fuente realista de un valor absurdo: **una persona con prisa
 * tecleando**.
 *
 * El `CHECK` que ya existia solo exige que sean positivos, y eso deja pasar el
 * error mas peligroso de todos, que es **silencioso**: `max_daily_hours = 90`
 * —el 9 con un cero de mas— no rompe nada, no da error y **apaga RN-11**. Nadie
 * lo descubre hasta que alguien compara una nomina con el convenio, meses
 * despues. Un umbral legal que no alerta es indistinguible de una plantilla que
 * cumple.
 *
 * Los topes de aqui no sustituyen a la validacion del dominio ni a la del
 * formulario: son la **ultima linea de defensa**, la misma que el docblock de la
 * migracion de la tarea 1.3 ya invocaba para los positivos. Valen igual si
 * alguien edita la fila a mano.
 *
 * ## Los numeros, y por que estos
 *
 * | Umbral | Tope | Por que |
 * |---|---|---|
 * | `min_rest_hours` | 24 | Es el descanso **entre dos jornadas** (art. 34.3 ET). Mas de un dia entero no describe un descanso: describe que la persona no trabaja. |
 * | `max_daily_hours` | 24 | Una jornada no cabe en mas horas de las que tiene un dia. |
 * | `break_required_after_hours` | 24 | Idem: es un tramo continuo dentro de una jornada. |
 * | `max_weekly_hours` | 168 | Las horas de una semana. |
 * | `retention_years` | 50 | Cuatro en Espana (art. 34.9 ET, RL-02) y hasta diez en otras jurisdicciones. Cincuenta es holgura, no una prevision. |
 *
 * **Y una coherencia entre dos de ellos**: `max_weekly_hours >= max_daily_hours`.
 * No se puede trabajar mas en un dia que en una semana, y un perfil que lo
 * afirme no describe ningun convenio. Es el tipo de invariante que solo se puede
 * comprobar sobre la fila entera, asi que su sitio es el esquema y no una regla
 * por campo.
 *
 * **No hay tope INFERIOR nuevo.** El `CHECK` de positivos sigue siendo el unico,
 * y es deliberado: `retention_years = 1` es un error tipografico peligroso —purga
 * tres años de registro que hay obligacion de conservar— pero **tambien es un
 * valor legitimo** en otra jurisdiccion, y el esquema no puede distinguirlos. Lo
 * que protege ese caso no es una restriccion sino el procedimiento:
 * `compliance:apply-retention` propone en simulacion, exige una confirmacion
 * derivada del propio informe y deja asiento (tarea 2.10, ADR-027). La pantalla
 * del panel lo advierte al lado del campo.
 *
 * ## Patron `/migracion-segura`: esto es un paso EXPAND puro
 *
 * | Despliegue | Que se hace | Estado del codigo |
 * |---|---|---|
 * | **1 (expand)** | Esta migracion: dos `CHECK` nuevos, `NOT VALID` y validados despues. Nada se renombra, nada se borra, ninguna columna cambia de tipo. | La version anterior sigue funcionando: los valores que escribia ya cumplian los topes. |
 * | **2 (migrate)** | *No aplica.* No hay estructura nueva que rellenar ni columna vieja que dejar de usar. | — |
 * | **3 (contract)** | *No aplica.* | — |
 *
 * El orden respecto al codigo **no importa**, que es lo que distingue a un paso
 * expand: una version antigua que escribiera una fila valida sigue escribiendola
 * igual, y una que intentara escribir `max_daily_hours = 90` recibiria un error
 * de base de datos — que es exactamente lo que se pretende.
 *
 * ## `NOT VALID` y luego `VALIDATE`
 *
 * `ADD CONSTRAINT ... CHECK` a secas recorre la tabla entera bajo `ACCESS
 * EXCLUSIVE`. Aqui la tabla tiene una fila y daria igual, pero la puerta
 * `MigrationSafetyTest` (RNF-D-04) no distingue tamaños y hace bien: la
 * plantilla correcta es esta, y la siguiente migracion que alguien copie de aqui
 * quiza caiga sobre una tabla grande. `VALIDATE CONSTRAINT` recorre despues, con
 * un bloqueo que **no** impide leer ni escribir.
 *
 * Si alguna fila editada a mano violara los topes, `VALIDATE` falla y la
 * migracion aborta **sin haber cambiado ningun dato**: el mensaje de PostgreSQL
 * nombra la restriccion y la fila. Es el fallo correcto — dice que hay que
 * corregir el perfil antes de actualizar, y deja la instalacion como estaba.
 *
 * ## `down()`
 *
 * Suelta las dos restricciones y deja la tabla exactamente como la dejo la 1.3:
 * positivos y `week_starts_on` entre 1 y 7. Ningun dato se toca, asi que la
 * vuelta atras es total.
 */
return new class extends Migration
{
    use LimitsMigrationLocks;

    public function up(): void
    {
        $this->limitLockWait();

        DB::statement(<<<'SQL'
            ALTER TABLE compliance_profiles
                ADD CONSTRAINT compliance_profiles_chk_threshold_bounds
                CHECK (
                    min_rest_hours <= 24
                    AND max_daily_hours <= 24
                    AND break_required_after_hours <= 24
                    AND max_weekly_hours <= 168
                    AND retention_years <= 50
                ) NOT VALID
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE compliance_profiles
                ADD CONSTRAINT compliance_profiles_chk_weekly_covers_daily
                CHECK (max_weekly_hours >= max_daily_hours) NOT VALID
        SQL);

        DB::statement('ALTER TABLE compliance_profiles VALIDATE CONSTRAINT compliance_profiles_chk_threshold_bounds');
        DB::statement('ALTER TABLE compliance_profiles VALIDATE CONSTRAINT compliance_profiles_chk_weekly_covers_daily');
    }

    public function down(): void
    {
        $this->limitLockWait();

        DB::statement('ALTER TABLE compliance_profiles DROP CONSTRAINT IF EXISTS compliance_profiles_chk_weekly_covers_daily');
        DB::statement('ALTER TABLE compliance_profiles DROP CONSTRAINT IF EXISTS compliance_profiles_chk_threshold_bounds');
    }
};
