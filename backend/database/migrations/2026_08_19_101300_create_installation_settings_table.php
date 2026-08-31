<?php

declare(strict_types=1);

use App\Support\Database\LimitsMigrationLocks;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * `installation_settings` — configuracion de la instalacion (doc 01 §5.5,
 * RF-PD-01).
 *
 * Es la tabla que sostiene la regla dura 13: *«nada especifico de un cliente
 * vive en el codigo»*. Marca, umbrales operativos, idiomas y funcionalidades
 * activas son **datos**. Si algo obliga a tocar el repositorio para vender a un
 * cliente nuevo, esta mal disenado (ADR-017).
 *
 * Se distingue de `compliance_profiles` y la distincion importa: **un umbral
 * legal lo fija la jurisdiccion, uno operativo lo fija el hotel** (doc 01 §4,
 * nota sobre RN-08 y RN-16). Por eso llegan al dominio por dos puertos
 * distintos, `CompliancePolicyProvider` y `OperationalSettingsProvider`.
 *
 * `scope` permite que un centro pise el valor de la instalacion —dos quioscos
 * contiguos necesitan otro `ATTENDANCE_MIN_TRANSIT_SECONDS` que un hotel con
 * dos edificios—. La **cascada** que resuelve cual gana es de la tarea 5.1;
 * aqui se establece la forma para que no haya que migrar la tabla entonces.
 *
 * > **Enmienda 31-08-2026 (tarea 5.1).** El parrafo de arriba describe el
 * > esquema tal como nacio y se conserva por eso, pero **ya no es cierto**:
 * > ADR-040 fijo un centro por instalacion, asi que el ambito `site` nunca llego
 * > a usarse y la migracion
 * > `2026_09_05_100000_contract_installation_settings_scope` retira `scope`,
 * > `scope_id`, su `CHECK`, su clave ajena y los dos indices parciales. La
 * > cascada real es **fila de instalacion -> valor por defecto del catalogo en
 * > codigo** (`SettingKey`), y la unicidad la garantiza `one_setting_per_key`.
 * > Las cuatro filas que siembra `seedOperationalDefaults()` **conservan su
 * > nombre de clave** y siguen siendo el valor visible y editable desde el
 * > panel.
 *
 * Los cuatro valores de serie del Anexo B del doc 02 se siembran en la
 * migracion y no en un seeder, por lo mismo que el perfil `ES-hosteleria`: un
 * seeder no se ejecuta en el servidor del cliente, y sin ellos el primer
 * fichaje no tendria umbral que aplicar.
 */
return new class extends Migration
{
    use LimitsMigrationLocks;

    public function up(): void
    {
        $this->limitLockWait();

        Schema::create('installation_settings', function (Blueprint $table): void {
            $table->id();

            $table->string('key', 128);

            // JSONB y no texto: el valor puede ser numero, booleano, cadena o
            // lista —idiomas activos, funcionalidades— y el tipo forma parte
            // del dato, no del codigo que lo lee.
            $table->jsonb('value');

            $table->string('scope', 16)->default('installation');
            $table->unsignedBigInteger('scope_id')->nullable();

            $table->foreignId('updated_by_user_id')
                ->nullable()
                ->constrained('users')
                ->restrictOnDelete();

            $table->timestampTz('updated_at', 6)->useCurrent();

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

        // Dos indices unicos parciales y no uno compuesto: `scope_id` es NULL
        // en el ambito de instalacion, y en un UNIQUE normal dos NULL no chocan
        // entre si. Sin esto, la misma clave podria estar dos veces a nivel de
        // instalacion y ganaria la que devolviera el planificador.
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

        $this->seedOperationalDefaults();
    }

    public function down(): void
    {
        $this->limitLockWait();

        Schema::dropIfExists('installation_settings');
    }

    /**
     * Los umbrales operativos de serie, literales del Anexo B del doc 02.
     *
     * Son los cuatro que consume `OperationalSettings`: la duracion anomala de
     * tramo (RN-08), la ventana anti-rebote (RF-AT-06), la tolerancia de
     * desfase de reloj (RF-AT-10) y el transito minimo entre quioscos (RN-16).
     * Ninguno tiene valor por defecto en el codigo (regla dura 14): el valor de
     * serie esta **aqui**, donde el cliente puede cambiarlo sin desplegar nada.
     */
    private function seedOperationalDefaults(): void
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
