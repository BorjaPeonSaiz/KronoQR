<?php

declare(strict_types=1);

use App\Support\Database\LimitsMigrationLocks;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * Las instalaciones que ya estaban en uso conservan sus dos idiomas (**RF-KI-05**,
 * bloque 26 de la 2.2.1).
 *
 * **Aceptada por el propietario el 16-10-2026.** El español de serie es
 * decision suya del 13-10; esta migracion hace que actualizar no cambie nada a
 * quien ya estaba en uso.
 *
 * ## Por que hace falta
 *
 * Desde la 2.2.1 el valor de serie de `LOCALE_AVAILABLE` es `["es"]`: una
 * instalacion nueva sale solo en español, sin selector de idioma y sin seguir al
 * navegador. Ese valor de serie vive en el catalogo (`SettingKey`), no en una
 * fila, asi que una instalacion que nunca toco los idiomas **no tiene fila**: sin
 * esta migracion, al actualizar perderia el ingles de un dia para otro, el
 * quiosco dejaria de enseñar el selector y una persona que leia el portal en
 * ingles lo veria en español sin haber cambiado nada. Una actualizacion no
 * cambia lo que el cliente ve sin que lo decida.
 *
 * ## Que hace
 *
 * Si la instalacion **esta en uso** —hay filas en `setup_progress` o en
 * `users`— y **no tiene fila** `LOCALE_AVAILABLE`, escribe `["es","en"]`, que es
 * exactamente lo que estaba viendo. Si ya tiene fila, la eleccion del cliente
 * manda y no se toca. En una instalacion recien creada —`install.sh` migra antes
 * de que exista ninguna cuenta— no escribe nada y rige el valor de serie nuevo.
 *
 * ## El centinela de `down()`
 *
 * La fila se escribe sin autor (`updated_by_user_id` nulo) y con un `updated_at`
 * fijo, {@see self::SENTINEL_AT}. `down()` solo la borra si conserva las tres
 * marcas —valor, autor nulo y ese instante—: si alguien la ha cambiado desde el
 * panel despues de actualizar, ya es una decision del cliente y volver atras no
 * puede borrarla.
 *
 * ## La cache de la configuracion
 *
 * `CachedSettingsRepository` guarda las filas cinco minutos. Sin vaciarla, los
 * primeros minutos tras la migracion se seguiria resolviendo «sin fila», es
 * decir, el valor de serie nuevo. Se vacia **si se puede**: una migracion no
 * puede depender de que Redis responda, y si no responde el TTL lo cura solo.
 *
 * Sin asiento en `audit_log`, como el resto de migraciones de datos
 * (`2026_08_30_100100_grant_read_ability.php`): no cambia lo que la instalacion
 * ofrece, deja escrito lo que ya ofrecia, y lo responde el registro de
 * migraciones.
 */
return new class extends Migration
{
    use LimitsMigrationLocks;

    private const string KEY = 'LOCALE_AVAILABLE';

    private const string PRESERVED = '["es","en"]';

    /** Instante fijo que distingue la fila de esta migracion de una del panel. */
    private const string SENTINEL_AT = '2026-10-16 10:04:00+00';

    /** La de `CachedSettingsRepository::KEY`, escrita literalmente como todo en una migracion. */
    private const string CACHE_KEY = 'kronoqr:product:installation_settings:v1';

    public function up(): void
    {
        $this->limitLockWait();

        $inUse = DB::table('setup_progress')->exists() || DB::table('users')->exists();

        if (! $inUse || DB::table('installation_settings')->where('key', self::KEY)->exists()) {
            return;
        }

        DB::table('installation_settings')->insertOrIgnore([
            'key' => self::KEY,
            'value' => self::PRESERVED,
            'updated_by_user_id' => null,
            'updated_at' => self::SENTINEL_AT,
        ]);

        $this->forgetCachedSettings();
    }

    public function down(): void
    {
        $this->limitLockWait();

        $deleted = DB::table('installation_settings')
            ->where('key', self::KEY)
            ->whereRaw('value = ?::jsonb', [self::PRESERVED])
            ->whereNull('updated_by_user_id')
            ->where('updated_at', self::SENTINEL_AT)
            ->delete();

        if ($deleted > 0) {
            $this->forgetCachedSettings();
        }
    }

    /**
     * Al confirmar, no antes: `migrate` envuelve la migracion en una transaccion,
     * y vaciar la cache dentro de ella dejaria a otra peticion repoblarla con lo
     * de antes del `COMMIT` (la misma carrera que cierra
     * `CachedSettingsRepository`). Fuera de una transaccion se ejecuta ya.
     */
    private function forgetCachedSettings(): void
    {
        DB::afterCommit(static function (): void {
            try {
                Cache::forget(self::CACHE_KEY);
            } catch (Throwable) {
                // Sin cache disponible: el TTL de cinco minutos lo cura solo.
            }
        });
    }
};
