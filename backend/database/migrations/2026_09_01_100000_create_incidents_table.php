<?php

declare(strict_types=1);

use App\Support\Database\LimitsMigrationLocks;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * `incidents` — lo que del registro horario tiene que mirar una persona
 * (doc 01 §5.5, RF-PR-01, tarea 2.6).
 *
 * Es la tabla que sostiene la promesa de RN-08: **el sistema nunca cierra un
 * turno por su cuenta**. Cuando la revision diaria encuentra algo, no toca el
 * tramo — escribe aqui, y aqui es de donde sale la bandeja de la tarea 2.5 y el
 * gauge `incidents_open{type,severity}` del doc 02 §8.2.
 *
 * ## La idempotencia es del esquema, no del codigo
 *
 * `one_open_incident_per_finding` es un indice unico **parcial** sobre
 * `(employee_id, work_date, type, shift_entry_id)` limitado a `status = 'open'`.
 * Con el, ejecutar el comando dos veces la misma noche no duplica nada aunque
 * dos procesos coincidan: la segunda insercion choca con la restriccion y se
 * ignora. Un `SELECT` previo en PHP habria tenido condicion de carrera con la
 * ejecucion manual mientras el planificador corre — que es exactamente lo que
 * hace alguien cuando esta probando algo.
 *
 * `NULLS NOT DISTINCT` (PostgreSQL 15+) es la mitad que suele faltar: sin el,
 * PostgreSQL considera **distintas** dos filas con `shift_entry_id` nulo, y las
 * incidencias de jornada entera —RN-11— se duplicarian cada noche. Con el, dos
 * nulos son iguales y la garantia cubre tambien esas.
 *
 * Solo cubre las **abiertas** a proposito: una incidencia resuelta no puede
 * impedir que el mismo hecho vuelva a detectarse mas adelante, porque entonces es
 * un hecho nuevo.
 *
 * ## El catalogo va en el esquema, no solo en PHP
 *
 * Los tres `CHECK` de `type`, `severity` y `status` repiten los enums del
 * dominio, igual que hacen `shift_entries` y `shift_corrections`. En un sistema
 * con valor probatorio la integridad no puede depender solo del codigo de
 * aplicacion (doc 02 §3.2): una importacion o un script de migracion de datos
 * entran por debajo. Las copias las ata una prueba, no la buena fe.
 *
 * ## Nada se borra
 *
 * Las tres claves ajenas al registro horario —`employee_id`, `shift_entry_id`—
 * son `RESTRICT` (regla dura 5); las dos que apuntan a `users` son `SET NULL`,
 * porque desactivar a un responsable no puede hacer desaparecer la incidencia que
 * tenia asignada. La incidencia se queda **sin asignar**, que es un estado
 * legitimo y visible, no un agujero.
 *
 * ## Permisos (ADR-033)
 *
 * No hay ningun `GRANT` aqui, y es lo correcto: la migracion `099000` declara
 * `ALTER DEFAULT PRIVILEGES ... GRANT SELECT, INSERT, UPDATE, DELETE ON TABLES`
 * para el rol de la aplicacion, asi que toda tabla creada despues nace con esos
 * permisos. El rol de mantenimiento no aparece porque no tiene nada que hacer
 * aqui: su unico trabajo es soltar particiones de `audit_log` (ADR-027).
 *
 * ## `down()` verificado
 *
 * Suelta la tabla, y con ella sus indices y restricciones, que son objetos
 * dependientes y no globales. Revertir esta migracion deja la instalacion sin
 * bandeja de incidencias y **sin perder ni un minuto del registro horario**: los
 * hechos siguen en `shift_entries` y en `scan_events`, y la revision diaria los
 * vuelve a encontrar en la siguiente pasada.
 */
return new class extends Migration
{
    use LimitsMigrationLocks;

    /**
     * Doc 01 §5.5, mas `missing_break`, que anade la tarea 2.6 para RN-12.
     *
     * Se escriben aqui literalmente y no se importan de `IncidentType`: una
     * migracion describe el esquema en el momento en que se escribio, y si leyera
     * una clase de la aplicacion, editar esa clase cambiaria lo que hace una
     * migracion ya ejecutada en el servidor de un cliente.
     *
     * @var list<string>
     */
    private const array TYPES = [
        'open_shift_expired',
        'short_shift',
        'long_shift',
        'missing_break',
        'insufficient_rest',
        'clock_skew',
        'missing_clock_out',
        'anomalous_pattern',
    ];

    /** @var list<string> */
    private const array SEVERITIES = ['low', 'medium', 'high'];

    /** @var list<string> */
    private const array STATUSES = ['open', 'resolved', 'dismissed'];

    public function up(): void
    {
        $this->limitLockWait();

        Schema::create('incidents', function (Blueprint $table): void {
            $table->id();

            $table->foreignId('employee_id')
                ->constrained('employees')
                // Regla dura 5: nada que cuelgue del registro horario desaparece
                // en cascada.
                ->restrictOnDelete();

            // DATE sin hora: la jornada es una fecha civil (RN-05), y es la que
            // decidio quien podia decidirla —el tramo, en la zona del centro—.
            $table->date('work_date');

            // El tramo que la explica. NULO cuando la incidencia es de la jornada
            // entera: RN-11 mira la suma del dia y ningun tramo por si solo la
            // explica.
            $table->foreignId('shift_entry_id')
                ->nullable()
                ->constrained('shift_entries')
                ->restrictOnDelete();

            $table->string('type', 32);
            $table->string('severity', 8);
            $table->string('status', 16)->default('open');

            // El responsable del departamento (`departments.manager_user_id`).
            // Nulo es legitimo: un departamento sin responsable deja la
            // incidencia SIN ASIGNAR, nunca descartada.
            $table->foreignId('assigned_to_user_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            // Cuando lo vio el sistema, que no es cuando ocurrio: eso es
            // `work_date`. Precision 6 como en el resto del registro; la de serie
            // de Laravel redondea al segundo.
            $table->timestampTz('detected_at', 6);

            // Los numeros que sostienen la incidencia: minutos medidos y umbral
            // aplicado. **Sin datos personales** (regla dura 21). JSONB y sin
            // indice GIN porque se lee entero con la fila y nadie consulta por
            // clave dentro.
            $table->jsonb('context')->default(DB::raw("'{}'::jsonb"));

            // Cuando salio el aviso al responsable (RF-PR-01). Nulo significa
            // «esa persona todavia no sabe que existe», y es lo que evita a la
            // vez el aviso duplicado y el aviso perdido.
            $table->timestampTz('notified_at', 6)->nullable();

            $table->timestampTz('resolved_at', 6)->nullable();

            // Quien la dio por trabajada (tarea 2.5). Es la mitad de la traza que
            // RN-13 exige de cualquier intervencion humana sobre el registro.
            $table->foreignId('resolved_by_user_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->text('resolution_note')->nullable();

            $table->timestampsTz(6);

            // El detalle de una jornada (RF-PA-03) y la exportacion legal
            // preguntan por empleado y fecha.
            $table->index(['employee_id', 'work_date'], 'incidents_employee_id_work_date_index');
        });

        $this->addCatalogueConstraints();
        $this->addShapeConstraints();
        $this->addTrayIndexes();
    }

    public function down(): void
    {
        $this->limitLockWait();

        Schema::dropIfExists('incidents');
    }

    /**
     * Los tres catalogos cerrados.
     *
     * `CHECK ... IN (...)` y no un tipo `ENUM` de PostgreSQL, por lo mismo que en
     * `shift_corrections`: anadir un valor a un `ENUM` no se puede deshacer y su
     * `ALTER TYPE` no admite `IF NOT EXISTS` en toda version soportada. Con un
     * `CHECK`, ampliar el catalogo es soltar y volver a crear la restriccion, que
     * es reversible y con `down()` verificable.
     */
    private function addCatalogueConstraints(): void
    {
        DB::statement(
            'ALTER TABLE incidents ADD CONSTRAINT incidents_chk_type '
            .'CHECK (type IN ('.$this->quotedList(self::TYPES).'))'
        );

        DB::statement(
            'ALTER TABLE incidents ADD CONSTRAINT incidents_chk_severity '
            .'CHECK (severity IN ('.$this->quotedList(self::SEVERITIES).'))'
        );

        DB::statement(
            'ALTER TABLE incidents ADD CONSTRAINT incidents_chk_status '
            .'CHECK (status IN ('.$this->quotedList(self::STATUSES).'))'
        );
    }

    /**
     * Que forma puede tener una fila segun su estado.
     *
     * Son las dos afirmaciones que una inspeccion daria por supuestas: una
     * incidencia cerrada dice **cuando** se cerro y **quien** la cerro, y una
     * abierta no finge estar cerrada. Sin esto dependerian de que nadie escriba
     * nunca en la tabla desde fuera de la aplicacion.
     */
    private function addShapeConstraints(): void
    {
        DB::statement(<<<'SQL'
            ALTER TABLE incidents
                ADD CONSTRAINT incidents_chk_resolution_is_complete
                CHECK (
                    (status = 'open' AND resolved_at IS NULL AND resolved_by_user_id IS NULL)
                    OR (status <> 'open' AND resolved_at IS NOT NULL)
                )
        SQL);

        // `detected_at` no puede ser posterior a la resolucion: una incidencia no
        // se resuelve antes de detectarse.
        DB::statement(<<<'SQL'
            ALTER TABLE incidents
                ADD CONSTRAINT incidents_chk_resolved_after_detected
                CHECK (resolved_at IS NULL OR resolved_at >= detected_at)
        SQL);
    }

    /**
     * Los tres indices que sostienen la operacion.
     *
     * Los tres son **parciales**: las incidencias abiertas son una minoria
     * diminuta frente al historico de cuatro años (RL-02), y ninguna de las tres
     * preguntas se hace nunca sobre una cerrada.
     */
    private function addTrayIndexes(): void
    {
        // La garantia de idempotencia. Ver el docblock de la clase.
        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX one_open_incident_per_finding
                ON incidents (employee_id, work_date, type, shift_entry_id)
                NULLS NOT DISTINCT
                WHERE status = 'open'
        SQL);

        // La bandeja de la tarea 2.5: «que tengo yo pendiente, lo mas grave y lo
        // mas reciente primero».
        DB::statement(<<<'SQL'
            CREATE INDEX incidents_open_by_assignee
                ON incidents (assigned_to_user_id, severity, detected_at DESC)
                WHERE status = 'open'
        SQL);

        // El resumen por responsable de RF-PR-01: lo abierto, asignado y sin
        // avisar. Es la consulta que corre en cada pasada de la deteccion.
        DB::statement(<<<'SQL'
            CREATE INDEX incidents_pending_notification
                ON incidents (assigned_to_user_id)
                WHERE status = 'open' AND notified_at IS NULL AND assigned_to_user_id IS NOT NULL
        SQL);
    }

    /**
     * @param  list<string>  $values
     */
    private function quotedList(array $values): string
    {
        // Los valores son constantes de esta clase y no entrada externa; aun asi
        // se escapan, porque un literal SQL construido por concatenacion sin
        // escapar es una costumbre que acaba aplicandose a algo que si lo es.
        return implode(', ', array_map(
            static fn (string $value): string => "'".str_replace("'", "''", $value)."'",
            $values,
        ));
    }
};
