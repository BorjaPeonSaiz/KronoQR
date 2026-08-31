<?php

declare(strict_types=1);

use App\Support\Database\LimitsMigrationLocks;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Contraccion del ambito de `installation_settings`: la tabla se queda con una
 * fila por clave (RF-PD-01, ADR-040, tarea 5.1).
 *
 * ## Por que
 *
 * La tabla nacio en la tarea 1.3 con `scope` (`installation`|`site`) y
 * `scope_id` para que un centro pudiera pisar el valor de la instalacion. Desde
 * [ADR-040] hay **exactamente un centro por instalacion**, asi que ese escalon
 * de la cascada resuelve siempre al mismo sitio: no es una cascada, es una
 * consulta con dos columnas de mas y con dos indices unicos parciales que hay
 * que razonar cada vez que alguien lee el codigo. ADR-040 §6 dejo la retirada
 * pendiente de esta tarea, que es la que implementa la cascada de verdad.
 *
 * La cascada queda en dos escalones: **fila de instalacion -> valor por defecto
 * del catalogo en codigo** (`SettingKey`).
 *
 * ## Patron `/migracion-segura`: esto es el paso CONTRACT
 *
 * No hay `expand` ni `migrate` que hacer, y eso hay que decirlo en voz alta en
 * lugar de dejarlo deducir: no se añade estructura nueva que rellenar, no hay
 * *backfill*, y las filas que existen —las cuatro `ATTENDANCE_*` que sembro la
 * tarea 1.3— **son todas de ambito `installation` y conservan su nombre de
 * clave**. Lo unico que desaparece son dos columnas cuyo valor es constante.
 *
 * ### Plan de despliegue
 *
 * | Despliegue | Que se hace | Estado del codigo |
 * |---|---|---|
 * | **1 (expand)** | *No aplica.* No hay estructura nueva: la clave sigue siendo `key` y el valor sigue siendo `value`. | — |
 * | **2 (migrate)** | Se retiran las cuatro filas `ATTENDANCE_*` que la 1.3 sembro **y que nadie ha tocado**. Ningun valor efectivo cambia: pasan a resolverse desde el catalogo en codigo. Ver «La siembra de la 1.3 se retira». | La version nueva ya no lee `scope` y trae los valores de serie en `SettingKey`. |
 * | **3 (contract)** | Esta migracion: se retiran el `CHECK`, los dos indices parciales, la clave ajena a `sites` y las dos columnas; y se crea el unico indice que queda, `one_setting_per_key`. | Ninguna version desplegada nombra `scope`. |
 *
 * El orden real de un despliegue es: **primero el codigo nuevo, despues esta
 * migracion**. Si se ejecutara al reves, la version antigua —que consulta
 * `WHERE scope = 'installation'`— se quedaria sin columna y dejaria de resolver
 * los umbrales del fichaje. Es la unica ventana peligrosa de este cambio y por
 * eso esta escrita aqui y no solo en el CHANGELOG.
 *
 * **Sin instalaciones desplegadas** (la Fase 5 es la que produce el instalador),
 * asi que en la practica esto se aplica sobre bases de desarrollo y de CI. La
 * secuencia se escribe igual: es la que hay que seguir el dia que las haya, y
 * una migracion cuyo plan se escribe «cuando haga falta» se escribe mal.
 *
 * ## La siembra de la tarea 1.3 se retira, y por que no es perder nada
 *
 * La 1.3 sembro los cuatro umbrales operativos como filas. **Existian por una
 * limitacion del adaptador de entonces**, no por decision de producto: aquel
 * `DbOperationalSettingsProvider` lanzaba si faltaba una clave, asi que sin filas
 * no se podia fichar. Desde la 5.1 el valor de serie vive en el catalogo
 * (`SettingKey`), en codigo, y una instalacion sin ninguna fila arranca y
 * funciona.
 *
 * Con las filas puestas, `GET /api/v1/settings` mentiria: devolveria
 * `source: installation` para cuatro claves que **nadie ha configurado**, y el
 * asiento de auditoria del primer cambio diria `was_product_default: false`
 * cuando lo cierto es que regia el valor del producto. Justo la distincion que
 * esos dos campos existen para hacer.
 *
 * Asi que se borran, **y solo las que siguen intactas**: se exige
 * `updated_by_user_id IS NULL` **y** que el valor guardado sea identico al de
 * serie. Una fila que alguien haya editado —aunque la haya dejado en el mismo
 * numero por otra via— no se toca.
 *
 * **La regla dura 5 no aplica y conviene decir por que.** Prohibe borrar o
 * sobrescribir el **registro** —fichajes, correcciones, auditoria—, que es lo que
 * tiene valor probatorio. Esto es configuracion que nadie eligio, cuyo valor
 * efectivo no cambia, y cuyo historico —si alguien la hubiera cambiado alguna
 * vez— vive en `audit_log`, que esta migracion no toca. `down()` las vuelve a
 * sembrar exactamente como hacia la 1.3.
 *
 * ## Se niega a migrar si hay filas de ambito `site`
 *
 * No las borra. Regla dura 5: nada se borra ni se sobrescribe, y menos una
 * configuracion que pudo estar gobernando el calculo de horas de alguien. Si
 * aparece alguna, la migracion para y dice que hacer. No puede haberlas —ningun
 * camino del producto las escribio nunca—, pero una edicion a mano si.
 *
 * ## Sin `CONCURRENTLY`
 *
 * `one_setting_per_key` se crea dentro de la transaccion de la migracion. La
 * tabla tiene unidades de filas —el catalogo son nueve claves— y el indice es
 * instantaneo: cambiar la atomicidad de la migracion por nada seria peor
 * negocio. El `lock_timeout` del trait sigue puesto, que es lo que evita que
 * esto se encole detras de una transaccion larga en un cambio de turno.
 *
 * [ADR-040]: docs/adr/ADR-040-un-centro-por-instalacion-y-por-licencia.md
 */
return new class extends Migration
{
    use LimitsMigrationLocks;

    public function up(): void
    {
        $this->limitLockWait();

        $scoped = DB::table('installation_settings')->where('scope', '!=', 'installation')->count();

        if ($scoped > 0) {
            throw new RuntimeException(sprintf(
                'Hay %d filas de `installation_settings` con ambito distinto de `installation`, y el producto '
                .'tiene un centro por instalacion (ADR-040). Esta migracion no las borra (regla dura 5): '
                .'revisa que valor deben tener a nivel de instalacion, escribelo con ese ambito, retira las '
                .'filas de ambito `site` a mano y vuelve a ejecutar `php artisan migrate`.',
                $scoped,
            ));
        }

        DB::statement('ALTER TABLE installation_settings DROP CONSTRAINT installation_settings_chk_scope');
        DB::statement('DROP INDEX one_installation_setting_per_key');
        DB::statement('DROP INDEX one_site_setting_per_key');
        DB::statement('ALTER TABLE installation_settings DROP CONSTRAINT installation_settings_scope_id_foreign');

        Schema::table('installation_settings', function (Blueprint $table): void {
            $table->dropColumn(['scope', 'scope_id']);
        });

        // El unico indice que queda, y ahora si un UNIQUE normal: sin `scope`,
        // no hay NULL que compare y no hacen falta indices parciales.
        DB::statement('CREATE UNIQUE INDEX one_setting_per_key ON installation_settings (key)');

        $this->dropUntouchedSeed();
    }

    /**
     * Retira las filas sembradas por la tarea 1.3 que **nadie ha tocado**.
     *
     * Cuatro filas como mucho, buscadas por clave primaria logica: ni un
     * `UPDATE` masivo ni un barrido de tabla. La condicion es doble —sin autor
     * **y** con el valor de serie— para que una fila que alguien haya guardado
     * desde el panel se quede donde esta aunque coincida el numero.
     *
     * Los valores estan escritos aqui y no importados de `SettingKey`, por lo
     * mismo que en la migracion de la 1.3: el esquema de una instalacion no puede
     * depender de una clase de la aplicacion, que puede cambiar entre versiones
     * mientras la migracion ya se ejecuto. Que coincidan lo ata una prueba.
     */
    private function dropUntouchedSeed(): void
    {
        $seeded = [
            'ATTENDANCE_MAX_SHIFT_HOURS' => 12,
            'ATTENDANCE_DEBOUNCE_SECONDS' => 60,
            'ATTENDANCE_MAX_CLOCK_SKEW_MINUTES' => 15,
            'ATTENDANCE_MIN_TRANSIT_SECONDS' => 120,
        ];

        foreach ($seeded as $key => $value) {
            DB::table('installation_settings')
                ->where('key', $key)
                ->whereNull('updated_by_user_id')
                ->whereRaw('value = ?::jsonb', [json_encode($value, JSON_THROW_ON_ERROR)])
                ->delete();
        }
    }

    /**
     * Deshace la contraccion dejando el esquema **equivalente** al que creo la
     * tarea 1.3: mismas columnas con los mismos tipos y valores por defecto,
     * mismo `CHECK`, misma clave ajena y los dos indices parciales con sus
     * nombres originales, y las cuatro filas de serie de vuelta.
     *
     * **Equivalente y no identico**: las columnas reañadidas quedan al final de
     * la tabla en lugar de en su posicion original. PostgreSQL no permite
     * insertar una columna en medio, y no importa — nada del producto depende del
     * orden ordinal: las consultas nombran las columnas y `SELECT *` no aparece
     * en ninguna.
     *
     * Las filas que hubiera recuperan `scope = 'installation'` por el valor por
     * defecto de la columna, que es el ambito que tenian: no hace falta ningun
     * `UPDATE` y por tanto no hay barrido de tabla bajo bloqueo.
     */
    public function down(): void
    {
        $this->limitLockWait();

        DB::statement('DROP INDEX one_setting_per_key');

        Schema::table('installation_settings', function (Blueprint $table): void {
            $table->string('scope', 16)->default('installation');
            $table->unsignedBigInteger('scope_id')->nullable();

            $table->foreign('scope_id')
                ->references('id')->on('sites')
                ->restrictOnDelete();
        });

        DB::statement(<<<'SQL'
            ALTER TABLE installation_settings
                ADD CONSTRAINT installation_settings_chk_scope
                CHECK (
                    (scope = 'installation' AND scope_id IS NULL)
                    OR (scope = 'site' AND scope_id IS NOT NULL)
                )
        SQL);

        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX one_installation_setting_per_key
                ON installation_settings (key)
                WHERE scope = 'installation'
        SQL);

        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX one_site_setting_per_key
                ON installation_settings (key, scope_id)
                WHERE scope = 'site'
        SQL);

        $this->reseedOperationalDefaults();
    }

    /**
     * Vuelve a sembrar los cuatro umbrales operativos, como hacia la tarea 1.3.
     *
     * Es la otra mitad de {@see self::dropUntouchedSeed()}: la version anterior
     * del adaptador **exige** que existan —lanza si falta una clave—, asi que
     * revertir sin volver a sembrarlas dejaria una instalacion incapaz de fichar.
     * Es exactamente el fallo que un `down()` existe para evitar.
     *
     * `insertOrIgnore`: si alguien ya configuro esa clave desde el panel, su
     * fila sigue ahi y no se pisa.
     */
    private function reseedOperationalDefaults(): void
    {
        $defaults = [
            'ATTENDANCE_MAX_SHIFT_HOURS' => 12,
            'ATTENDANCE_DEBOUNCE_SECONDS' => 60,
            'ATTENDANCE_MAX_CLOCK_SKEW_MINUTES' => 15,
            'ATTENDANCE_MIN_TRANSIT_SECONDS' => 120,
        ];

        foreach ($defaults as $key => $value) {
            DB::table('installation_settings')->insertOrIgnore([
                'key' => $key,
                'value' => json_encode($value, JSON_THROW_ON_ERROR),
                'scope' => 'installation',
                'scope_id' => null,
            ]);
        }
    }
};
