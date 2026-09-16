<?php

declare(strict_types=1);

use App\Support\Database\LimitsMigrationLocks;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * `devices.battery_level`, `devices.battery_charging` y `devices.oldest_pending_at`
 * — la telemetria de salud del quiosco (**RF-PA-07**, tarea 3.3, doc 01 §5.5).
 *
 * ## Que aporta cada columna, y por que no bastaba con lo que habia
 *
 * - **`oldest_pending_at`.** El latido ya declaraba el instante del fichaje mas
 *   antiguo de su cola —el contrato lo acepta desde la 5.12— y el servidor lo
 *   tiraba: se validaba y no se guardaba en ningun sitio. Es lo que convierte
 *   «37 pendientes» en «el mas antiguo es de hace tres horas», que es la
 *   diferencia entre una sincronizacion en curso y un quiosco que lleva media
 *   jornada incomunicado (runbook `alta-nuevo-quiosco.md` §5.1).
 * - **`battery_level` y `battery_charging`.** Una tablet colgada de la pared que
 *   se descarga es una tablet a la que alguien ha quitado el cargador, y morira
 *   durante el turno. Los dos juntos son lo que distingue «al 15 % cargando»,
 *   que es normal, de «al 15 % descargandose», que es un aviso (decision 5 de la
 *   ficha 3.3). Por separado no dicen nada.
 *
 * **Las tres son informacion operativa y no autoridad**: las declara el propio
 * dispositivo y nadie las comprueba. Ninguna influye en el registro horario; un
 * quiosco que mienta sobre su bateria ensucia el panel de salud y no cambia ni
 * un fichaje.
 *
 * ## `NULL` significa algo en las tres, y por eso no llevan valor de serie
 *
 * `battery_level` y `battery_charging` son `NULL` cuando el navegador no informa:
 * la Battery Status API solo la ofrece Chrome en Android, y **una tablet que no
 * informa no es una tablet averiada** — no puede provocar un aviso. Un `0` de
 * serie habria sido una cifra plausible y falsa, que es peor que un hueco, y
 * habria puesto en aviso a la flota entera el dia del despliegue.
 * `oldest_pending_at` es `NULL` con la cola vacia o sin ningun latido.
 *
 * El `CHECK` del nivel es la ultima linea de defensa del rango que ya declaran
 * el contrato y el `FormRequest`: `SMALLINT` admite hasta 32 767, y un `-1` o un
 * `300` escritos desde `psql` saldrian en el panel y en la metrica
 * `kiosk_battery_level` sin que nada los parase.
 *
 * ## Patron `/migracion-segura`: expand puro, en un solo despliegue
 *
 * | Despliegue | Que se hace | Estado del codigo |
 * |---|---|---|
 * | **1 (expand)** | Esta migracion: tres columnas `NULL`, sin valor de serie y sin relleno. `ADD COLUMN` nullable no reescribe la tabla en PostgreSQL 11+, y `devices` tiene decenas de filas en la instalacion mas grande: la sentencia es instantanea. | La version anterior no nombra ninguna de las tres, asi que no se entera. La nueva las escribe (`DbDeviceFleet` en el latido) y las lee (`GET /api/v1/devices`), y tolera `NULL`. |
 * | **2 (migrate)** | *No aplica.* No hay historico que trasladar: nadie ha declarado nunca su bateria, y un relleno inventado seria una cifra falsa. | — |
 * | **3 (contract)** | *No aplica.* No se retira ni se renombra nada: las tres columnas son nuevas. | — |
 *
 * `down()` las retira con su restriccion. Es la unica direccion en la que un
 * `dropColumn` es correcto: deshacer un expand que nadie llego a usar.
 */
return new class extends Migration
{
    use LimitsMigrationLocks;

    public function up(): void
    {
        $this->limitLockWait();

        Schema::table('devices', function (Blueprint $table): void {
            // `SMALLINT` y no `INTEGER`: el rango es 0..100 y el `CHECK` lo
            // acota. Dos bytes por fila no importan aqui; la senal que manda es
            // que este numero es un porcentaje y no un contador.
            $table->smallInteger('battery_level')->nullable()->after('pending_queue_size');
            $table->boolean('battery_charging')->nullable()->after('battery_level');
            // Regla dura 3: instante en UTC con microsegundos, como todos.
            $table->timestampTz('oldest_pending_at', 6)->nullable()->after('battery_charging');
        });

        // Nombre explicito, como el resto de los `CHECK` de esta tabla: un
        // nombre generado por el motor no se puede nombrar en un `down()` ni
        // reconocer en el mensaje de un fallo.
        //
        // `NOT VALID` y despues `VALIDATE`: aqui la columna acaba de nacer y
        // todas sus filas son `NULL`, asi que el recorrido seria instantaneo,
        // pero la plantilla correcta es esta y la siguiente migracion que
        // alguien copie de aqui quiza caiga sobre una tabla grande. `ADD
        // CONSTRAINT ... CHECK` a secas recorre la tabla entera bajo `ACCESS
        // EXCLUSIVE`; `VALIDATE CONSTRAINT` lo hace despues con `SHARE UPDATE
        // EXCLUSIVE`, que no bloquea las escrituras del latido.
        DB::statement(<<<'SQL'
            ALTER TABLE devices
                ADD CONSTRAINT devices_chk_battery_level_range
                CHECK (battery_level IS NULL OR (battery_level BETWEEN 0 AND 100)) NOT VALID
        SQL);

        DB::statement('ALTER TABLE devices VALIDATE CONSTRAINT devices_chk_battery_level_range');
    }

    public function down(): void
    {
        $this->limitLockWait();

        DB::statement('ALTER TABLE devices DROP CONSTRAINT IF EXISTS devices_chk_battery_level_range');

        Schema::table('devices', function (Blueprint $table): void {
            $table->dropColumn(['battery_level', 'battery_charging', 'oldest_pending_at']);
        });
    }
};
