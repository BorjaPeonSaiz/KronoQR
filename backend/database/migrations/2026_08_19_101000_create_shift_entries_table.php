<?php

declare(strict_types=1);

use App\Support\Database\LimitsMigrationLocks;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * `shift_entries` — el **tramo**, la unidad del registro horario y la tabla con
 * valor probatorio del producto (doc 01 §5.5, RN-01..09).
 *
 * Aqui viven las tres restricciones declarativas del doc 01 §5.5 y del doc 02
 * §3.2, escritas **literalmente**. Son la ultima linea de defensa de RN-01,
 * RN-02 y RN-03: *«en un sistema con valor probatorio la integridad no puede
 * depender solo del codigo de aplicacion»* (§3.2). Un script de migracion, una
 * correccion manual mal hecha o una condicion de carrera bajo concurrencia
 * pueden introducir datos imposibles que el dominio jamas produciria.
 *
 * **Un turno que cruza la medianoche es una sola fila** (RN-05, ADR-006, regla
 * dura 4). No hay nada en este esquema que parta un tramo a las 23:59:
 * `work_date` es la fecha civil —en la zona del centro— del `clocked_in_at` del
 * tramo que abrio la jornada, y por eso es una columna propia y no una
 * expresion derivada de `clocked_in_at`.
 *
 * **`version` y `superseded_by_id` nacen ahora aunque las correcciones sean de
 * la tarea 1.15** (regla dura 5, RN-13, RL-04). Anadirlas despues seria una
 * migracion sobre la tabla con los fichajes reales de un cliente; anadirlas hoy
 * no cuesta nada porque la tabla nace vacia.
 *
 * **El enum de `status` nace con los cinco valores, `superseded` incluido**
 * (ADR-026). No es anticipacion gratuita: sin `superseded`, la primera
 * correccion de la Fase 2 —que conserva la fila anterior— haria solapar la
 * version vieja con la nueva, `shift_entries_no_overlap` **rechazaria la
 * correccion**, y si no lo hiciera el recalculo de `daily_totals` sumaria las
 * dos y duplicaria los minutos del dia.
 */
return new class extends Migration
{
    use LimitsMigrationLocks;

    public function up(): void
    {
        $this->limitLockWait();

        Schema::create('shift_entries', function (Blueprint $table): void {
            $table->id();

            // Identificador publico del tramo: es el que sale por la API y el
            // que el dominio maneja (`ShiftEntry::uuid`).
            $table->uuid('uuid')->unique('shift_entries_uuid_unique');

            $table->foreignId('employee_id')
                ->constrained('employees')
                // Regla dura 5: el registro horario no desaparece en cascada.
                ->restrictOnDelete();

            // El centro donde se ficho, que resuelve la zona horaria de RN-05.
            // Se guarda en el tramo y no se deduce del empleado: un traslado de
            // centro no puede reescribir donde ocurrieron las jornadas
            // anteriores.
            $table->foreignId('site_id')
                ->constrained('sites')
                ->restrictOnDelete();

            // DATE sin hora (regla de tipos de `/migracion-segura`): la jornada
            // es una fecha civil, no un instante.
            $table->date('work_date');

            // Precision 6 y no la de serie de Laravel, que es 0: `timestamp(0)`
            // REDONDEA al segundo, asi que la hora leida no seria la escrita.
            // En un registro con valor legal eso no es aceptable.
            $table->timestampTz('clocked_in_at', 6);
            $table->timestampTz('clocked_out_at', 6)->nullable();

            // Duracion derivada, en minutos enteros (nunca coma flotante). Es
            // cache del calculo del dominio, no su fuente: quien manda son los
            // dos instantes.
            $table->integer('duration_minutes')->nullable();

            $table->string('status', 16)->default('open');

            $table->string('clock_in_source', 16);
            $table->string('clock_out_source', 16)->nullable();

            // RN-13: la primera version es la 1 y cada correccion crea una
            // nueva conservando la anterior.
            $table->integer('version')->default(1);

            $table->foreignId('superseded_by_id')
                ->nullable()
                ->constrained('shift_entries')
                ->restrictOnDelete();

            $table->timestampsTz(6);

            // El acceso normal: la jornada de un empleado en una fecha (RN-06,
            // panel de detalle de RF-PA-03, exportacion legal de RL-03).
            $table->index(['employee_id', 'work_date'], 'shift_entries_employee_id_work_date_index');

            // Presencia en vivo e informes por centro.
            $table->index(['site_id', 'work_date'], 'shift_entries_site_id_work_date_index');
        });

        DB::statement(<<<'SQL'
            ALTER TABLE shift_entries
                ADD CONSTRAINT shift_entries_chk_status
                CHECK (status IN ('open', 'closed', 'anomalous', 'voided', 'superseded'))
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE shift_entries
                ADD CONSTRAINT shift_entries_chk_sources
                CHECK (
                    clock_in_source IN ('qr_kiosk', 'pin_kiosk', 'manual_admin', 'import')
                    AND (clock_out_source IS NULL OR clock_out_source IN ('qr_kiosk', 'pin_kiosk', 'manual_admin', 'import'))
                )
        SQL);

        // `open` significa «entrada fichada, salida pendiente». Un tramo abierto
        // con hora de salida seria un estado que el dominio no sabe leer.
        DB::statement(<<<'SQL'
            ALTER TABLE shift_entries
                ADD CONSTRAINT shift_entries_chk_open_has_no_clock_out
                CHECK (status <> 'open' OR clocked_out_at IS NULL)
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE shift_entries
                ADD CONSTRAINT shift_entries_chk_version_positive
                CHECK (version >= 1)
        SQL);

        $this->addDomainInvariants();
    }

    public function down(): void
    {
        $this->limitLockWait();

        // Las tres restricciones y sus indices se van con la tabla: son objetos
        // dependientes de `shift_entries`, no globales.
        Schema::dropIfExists('shift_entries');
    }

    /**
     * RN-01, RN-02 y RN-03, con el SQL **literal** del doc 01 §5.5 y del doc 02
     * §3.2.
     *
     * Los dos predicados excluyen los **dos** estados no vigentes (ADR-026):
     * `voided` («este tramo no ocurrio») y `superseded` («ocurrio, se conserva,
     * y otra version lo sustituye»). El mismo predicado gobierna el recalculo
     * de `daily_totals` (RN-06) y lo expresa el dominio en
     * `ShiftEntryStatus::isCurrent()`.
     */
    private function addDomainInvariants(): void
    {
        // RN-01: como maximo un turno abierto por empleado.
        //
        // Indice unico PARCIAL: solo entran las filas vigentes sin salida, que
        // son unas pocas centenas aunque la tabla tenga millones. Ademas de
        // garantizar la invariante, es lo que hace que «quien esta dentro
        // ahora» se resuelva en O(log n) sin escanear el historico.
        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX one_open_shift_per_employee
                ON shift_entries (employee_id)
                WHERE clocked_out_at IS NULL AND status NOT IN ('voided', 'superseded')
        SQL);

        // RN-02: los tramos vigentes de un mismo empleado no pueden solaparse.
        //
        // `btree_gist` es lo que permite combinar la igualdad de `employee_id`
        // con el solape de `tstzrange` en una sola restriccion de exclusion.
        // `tstzrange(a, b)` usa limites `[)` por defecto: un tramo que termina
        // justo cuando empieza el siguiente NO solapa, que es la semantica
        // correcta. Con `clocked_out_at` NULL el rango queda abierto por
        // arriba, asi que un turno abierto bloquea cualquier tramo posterior.
        DB::statement(<<<'SQL'
            ALTER TABLE shift_entries ADD CONSTRAINT shift_entries_no_overlap
                EXCLUDE USING gist (
                    employee_id WITH =,
                    tstzrange(clocked_in_at, clocked_out_at) WITH &&
                ) WHERE (status NOT IN ('voided', 'superseded'))
        SQL);

        // RN-03: la salida es posterior a la entrada. Estrictamente: un tramo
        // de duracion cero no es un tramo.
        DB::statement(<<<'SQL'
            ALTER TABLE shift_entries ADD CONSTRAINT shift_entries_chk_order
                CHECK (clocked_out_at IS NULL OR clocked_out_at > clocked_in_at)
        SQL);
    }
};
