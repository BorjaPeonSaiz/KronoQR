<?php

declare(strict_types=1);

use App\Support\Database\LimitsMigrationLocks;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `devices.paired_at` — cuando se vinculo el quiosco (**RF-PD-06**, tarea 5.6,
 * doc 01 §5.5).
 *
 * ## Por que no vale `created_at`
 *
 * Porque la fila **no es la tablet, es el puesto**. Sustituir el aparato de
 * Recepcion reactiva la misma fila y conserva el mismo `uuid`
 * ([ADR-028](../../docs/adr/ADR-028-limites-del-plan-no-bloquean.md)): asi los
 * fichajes de antes y los de despues siguen hablando del mismo quiosco y su
 * historial no se parte en dos. Con esa decision, `created_at` dice cuando se
 * dio de alta el **puesto** y no desde cuando esta el aparato que hay hoy, que es
 * la primera pregunta cuando algo deja de funcionar.
 *
 * Se reescribe en cada emparejamiento, incluida la reactivacion. El registro
 * legal de quien vinculo que y cuando sigue estando en `audit_log`, que es
 * solo-append y encadenado por hash (regla dura 6); esta columna esta para que el
 * panel pinte la lista sin cruzar el trail.
 *
 * ## `NULL` significa algo, y por eso no lleva valor de serie
 *
 * Los dispositivos dados de alta **antes** de que existiera el emparejamiento por
 * codigo —por consola, en la tarea 1.5, o por el asistente— no tienen fecha de
 * vinculacion y no se les inventa una. Rellenar con `created_at` habria dado una
 * cifra plausible y falsa, que es peor que un hueco: `GET /api/v1/devices` la
 * declara `nullable` en el contrato justo por esto.
 *
 * ## Plan de despliegue
 *
 * Expand puro, en un solo paso y sin fase de contract:
 *
 * 1. **Esta migracion** añade la columna `NULL` y sin valor de serie. `devices`
 *    tiene decenas de filas en la instalacion mas grande, asi que la sentencia
 *    es instantanea; aun asi es `ADD COLUMN` nullable, que en PostgreSQL 11+ no
 *    reescribe la tabla ni con millones de filas.
 * 2. **El codigo de esta misma version** empieza a escribirla (`DbDeviceRegistry`
 *    en el `confirm`) y a leerla (`GET /api/v1/devices`). Es seguro en ese orden:
 *    la version anterior no la nombra, y la nueva tolera `NULL`.
 *
 * `down()` la retira. Es la unica direccion en la que un `dropColumn` es
 * correcto: deshacer un expand que nadie llego a usar.
 */
return new class extends Migration
{
    use LimitsMigrationLocks;

    public function up(): void
    {
        $this->limitLockWait();

        Schema::table('devices', function (Blueprint $table): void {
            // Regla dura 3: instante en UTC con microsegundos, como todos.
            $table->timestampTz('paired_at', 6)->nullable()->after('status');
        });
    }

    public function down(): void
    {
        $this->limitLockWait();

        Schema::table('devices', function (Blueprint $table): void {
            $table->dropColumn('paired_at');
        });
    }
};
