<?php

declare(strict_types=1);

use App\Support\Database\LimitsMigrationLocks;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * `absences` — **por que alguien no estaba, dicho antes de que el informe lo
 * cuente como absentismo** (doc 01 §5.5, RF-GP-04, tarea 3.10).
 *
 * ## Que hace esta tabla y que NO hace
 *
 * Registra un hecho: esta persona no vino entre estos dos dias y esta es la
 * categoria. **No hay flujo de aprobacion** —ni estado «pendiente», ni
 * «aprobada»— porque el doc 05 §8 acota el alcance con esas palabras y la
 * aprobacion es Fase 4. Un estado de aprobacion en el esquema seria una promesa
 * escrita en la base de datos que el producto no cumple.
 *
 * **Solo dias completos.** `starts_on` y `ends_on` son fechas civiles
 * **inclusivas**, igual que la vigencia de `employment_contracts`: una ausencia
 * del 1 al 3 cubre el 1, el 2 y el 3. Medias jornadas no existen en esta
 * version, y por eso las columnas son `date` y no `TIMESTAMPTZ`: un instante
 * obligaria a decidir a que hora empieza una baja medica, que no significa nada.
 *
 * ## El solape lo impide PostgreSQL, no PHP
 *
 * `absences_no_overlap` —`EXCLUDE USING gist`, la misma tecnica que
 * `employment_contracts_no_overlap` y que `shift_entries_no_overlap` de RN-02—
 * impide que una persona tenga dos ausencias **activas** el mismo dia. Sin ella,
 * «¿cuantos dias de ausencia tuvo en marzo?» podria contar dos veces el mismo
 * dia, y ese numero acaba en un informe de absentismo con consecuencias
 * laborales.
 *
 * La comprobacion equivalente existe tambien en `Absence::overlaps()`, y no es
 * duplicacion inutil: aquella permite probar el dominio sin base de datos y
 * explicar el choque con un mensaje que tiene significado. La que **manda** es
 * esta, porque una consulta previa desde PHP es una condicion de carrera con
 * aspecto de comprobacion: dos altas simultaneas la pasan las dos.
 *
 * El `WHERE (status = 'active')` es lo que hace que la restriccion sea
 * compatible con la regla dura 5: las versiones anteriores y las anuladas se
 * quedan en la tabla —cubriendo exactamente los mismos dias— y no estorban.
 *
 * `btree_gist` es lo que permite combinar la igualdad de `employee_id` (btree)
 * con el solape de `daterange` (gist) en la misma restriccion. La extension ya
 * la instala la migracion de extensiones de la Fase 1.
 *
 * ## Las columnas que el doc 01 §5.5 no enumeraba, y por que
 *
 * El §5.5 lista seis: `id`, `employee_id`, `type`, `starts_on`, `ends_on` y
 * `note`. Las demas estan aqui porque **la regla dura 5 no se puede cumplir con
 * seis columnas**: corregir una ausencia no sobrescribe, crea una version nueva
 * y conserva la anterior con autor, momento y motivo (RN-13, RL-04).
 *
 * - `uuid` — identificador publico, UUID v7. La clave interna no sale del
 *   producto (doc 01 §5.5) y **identifica una version, no una ausencia a lo
 *   largo del tiempo**, igual que `shift_entries.uuid` (ADR-035).
 * - `status`, `version`, `supersedes_id`, `superseded_by_id`, `change_reason` —
 *   el encadenado de versiones. El unico `UPDATE` sobre una fila anterior son
 *   `status` y `superseded_by_id`; el tipo, las fechas, la nota, el autor y el
 *   momento no se tocan nunca.
 * - `voided_at`, `voided_by_user_id`, `void_reason` — anular es un hecho con
 *   nombre y no un `DELETE`, igual que `POST /shift-entries/{uuid}/void`
 *   (ADR-026). **No crea version**: no hay una version posterior de un hecho que
 *   no paso.
 * - `created_at`, `created_by_user_id` — quien lo registro y cuando. El asiento
 *   de `audit_log` lo cuenta con su cadena de hash; estas dos dejan el dato
 *   legible en la propia tabla sin cruzar el trail para pintar la pantalla.
 *
 * `created_by_user_id` es NULLABLE a proposito, con el mismo criterio que en
 * `employment_contracts`: una importacion y la semilla de desarrollo no tienen
 * ninguna persona detras, y forzar un autor obligaria a inventar una cuenta de
 * sistema en la tabla de usuarios.
 *
 * ## `note` es dato de salud en potencia, y el esquema no lo sabe
 *
 * Una baja medica **es** dato de salud (regla dura 21). El esquema no puede
 * impedir que alguien escriba un diagnostico ahi, asi que lo que se hace es
 * acotarlo —500 caracteres— y no dejarlo salir: el asiento de `audit_log` lleva
 * `has_note` y nunca la nota; el `Resource` la omite para el
 * `responsable_departamento`; y ningun log tecnico ni `error_events` la ve. La
 * guia de RRHH dice con todas las letras que no se escriba el diagnostico.
 *
 * ## Los dos indices, y para que es cada uno
 *
 * `(employee_id, starts_on)` sirve a la ficha y al listado filtrado por persona.
 * El indice de la restriccion de exclusion es GiST y no ordena por fecha.
 *
 * El parcial `(starts_on, ends_on) WHERE status = 'active'` sirve al informe por
 * periodo, que pregunta «que ausencias activas tocan este rango» sobre la
 * plantilla entera. Es parcial porque el informe nunca mira las supersedidas ni
 * las anuladas, y un indice que las incluyera creceria con el historico sin que
 * ninguna consulta lo usara.
 *
 * ## El nombre del fichero es corto a proposito
 *
 * Mismo precedente que `2026_09_02_100000_employment_contracts.php`, donde queda
 * la medicion escrita: el nombre no era la causa de aquel sintoma de Larastan,
 * pero es gratis y evita añadir una variable mas. Que nadie lo renombre «para
 * que se lea mejor» sin volver a medirlo.
 *
 * ## Permisos
 *
 * Ninguno aqui. `ALTER DEFAULT PRIVILEGES` de la migracion de privilegios
 * (ADR-033) ya concede al rol de aplicacion `SELECT, INSERT, UPDATE, DELETE`
 * sobre toda tabla creada despues de ella. Esta tabla no es una de las
 * excepciones: el `UPDATE` es legitimo —marcar una fila como `superseded` o como
 * `voided`— y el `DELETE` no lo ejecuta nadie, porque ningun caso de uso ni
 * ningun repositorio de este producto lo ofrece.
 */
return new class extends Migration
{
    use LimitsMigrationLocks;

    /**
     * El catalogo cerrado de tipos de ausencia, en ingles.
     *
     * **En ingles y no en castellano, al contrario que `schedule_type`**: aquel
     * es el catalogo literal que el doc 01 §5.5 fija para la columna; este no lo
     * fija ningun documento, asi que vale la regla general del §3.5 —el codigo y
     * los identificadores en ingles— y el castellano vive en `i18n` y en los
     * alias de la importacion.
     *
     * **No es configurable** (decision 2 de la ficha 3.10) y no contradice
     * ADR-017: un catalogo cerrado es lo que hace comparables los informes de
     * dos clientes, exactamente igual que el catalogo de tipos de incidencia.
     *
     * Se escribe aqui ademas de en `AbsenceType` por lo mismo que los motivos de
     * correccion y los roles: el esquema de una instalacion no puede depender de
     * una clase de la aplicacion. Las dos copias las ata una prueba, no la buena
     * fe.
     *
     * @var list<string>
     */
    private const array TYPES = ['vacation', 'sick_leave', 'leave', 'other'];

    /**
     * Situacion de una fila.
     *
     * `active` es la vigente, `superseded` la sustituida por una correccion y
     * `voided` la anulada. Las tres viven en la tabla para siempre (regla dura
     * 5): lo unico que cambia es cual entra en el conjunto vigente.
     *
     * @var list<string>
     */
    private const array STATUSES = ['active', 'superseded', 'voided'];

    /**
     * Techo de la nota y de los dos motivos.
     *
     * **Se repite aqui a proposito**, con el mismo criterio que el catalogo de
     * tipos: el esquema de una instalacion no puede depender de una clase de la
     * aplicacion. La copia que manda en PHP es `Absence::MAX_NOTE_LENGTH`, y las
     * dos las ata una prueba —no la buena fe—.
     */
    private const int MAX_TEXT = 500;

    /** Suelo de los dos motivos: «ok» no explica nada. */
    private const int MIN_REASON = 3;

    public function up(): void
    {
        $this->limitLockWait();

        Schema::create('absences', function (Blueprint $table): void {
            $table->id();

            // Identificador PUBLICO, UUID v7. Identifica una VERSION: corregir
            // crea una fila nueva con `uuid` propio (ADR-035).
            $table->uuid('uuid')->unique();

            $table->foreignId('employee_id')
                ->constrained('employees')
                // Regla dura 5: dar de baja a alguien no borra su ficha, y sus
                // ausencias tampoco desaparecen en cascada. El informe de un
                // periodo pasado sigue necesitandolas (RN-14).
                ->restrictOnDelete();

            $table->string('type', 16);

            // Fechas civiles y no instantes: una ausencia es calendario, igual
            // que la vigencia de un contrato y que `daily_totals.work_date`.
            $table->date('starts_on');
            $table->date('ends_on');

            // Texto libre de quien la registra. Nullable porque la mayoria de
            // las ausencias no necesita ninguna; obligatoria solo para `other`,
            // que sin ella no describe nada — y eso lo hace cumplir el dominio,
            // no un `CHECK`, porque el catalogo puede crecer y la regla es de
            // negocio.
            $table->text('note')->nullable();

            $table->string('status', 16)->default('active');

            // Empieza en 1 y sube de uno en uno con cada correccion.
            $table->unsignedSmallInteger('version')->default(1);

            // El encadenado: a quien sustituye esta fila y quien la sustituyo a
            // ella. Las dos nulas en la version 1 sin correcciones.
            $table->foreignId('supersedes_id')
                ->nullable()
                ->constrained('absences')
                ->restrictOnDelete();

            $table->foreignId('superseded_by_id')
                ->nullable()
                ->constrained('absences')
                ->restrictOnDelete();

            // Por que se corrigio. Texto libre y no un catalogo como en las
            // correcciones del registro horario: alli hay nueve causas
            // tipificadas que defender ante Inspeccion (Anexo C), aqui no hay
            // ninguna.
            $table->text('change_reason')->nullable();

            $table->timestampTz('voided_at', 6)->nullable();

            $table->foreignId('voided_by_user_id')
                ->nullable()
                ->constrained('users')
                ->restrictOnDelete();

            $table->text('void_reason')->nullable();

            $table->timestampTz('created_at', 6);

            $table->foreignId('created_by_user_id')
                ->nullable()
                ->constrained('users')
                ->restrictOnDelete();

            // «Las ausencias de esta persona, de la mas reciente a la mas
            // antigua»: la ficha y el filtro por empleado del listado. El indice
            // de la restriccion de exclusion es GiST y no ordena por fecha.
            $table->index(['employee_id', 'starts_on'], 'absences_employee_id_starts_on_index');
        });

        $this->addValueConstraints();
        $this->addConsistencyConstraints();
        $this->addNoOverlapConstraint();
        $this->addReportingIndex();
    }

    public function down(): void
    {
        $this->limitLockWait();

        // Restricciones e indices se van con la tabla: todos son objetos
        // dependientes de `absences`, ninguno global. Las dos claves ajenas a si
        // misma tampoco estorban, porque se van con ella.
        Schema::dropIfExists('absences');
    }

    /**
     * Lo que puede valer una fila, declarado donde no se puede rodear.
     *
     * Las mismas afirmaciones viven en `Absence` y en `AbsenceType`, que es
     * donde dan un mensaje con significado a quien rellena el formulario. Estas
     * cubren lo que no pasa por el dominio: una importacion mal hecha, un `psql`
     * a las tres de la mañana.
     */
    private function addValueConstraints(): void
    {
        DB::statement(
            'ALTER TABLE absences ADD CONSTRAINT absences_chk_type '
            .'CHECK (type IN ('.$this->quotedList(self::TYPES).'))'
        );

        DB::statement(
            'ALTER TABLE absences ADD CONSTRAINT absences_chk_status '
            .'CHECK (status IN ('.$this->quotedList(self::STATUSES).'))'
        );

        // Los dos extremos son inclusivos, asi que un solo dia es
        // `starts_on = ends_on`. Invertidos no describen ningun periodo.
        DB::statement(<<<'SQL'
            ALTER TABLE absences
                ADD CONSTRAINT absences_chk_period
                CHECK (ends_on >= starts_on)
        SQL);

        DB::statement(
            'ALTER TABLE absences ADD CONSTRAINT absences_chk_note_length '
            .'CHECK (note IS NULL OR char_length(note) <= '.self::MAX_TEXT.')'
        );

        DB::statement(
            'ALTER TABLE absences ADD CONSTRAINT absences_chk_change_reason_length '
            .'CHECK (change_reason IS NULL OR char_length(change_reason) BETWEEN '
            .self::MIN_REASON.' AND '.self::MAX_TEXT.')'
        );

        DB::statement(
            'ALTER TABLE absences ADD CONSTRAINT absences_chk_void_reason_length '
            .'CHECK (void_reason IS NULL OR char_length(void_reason) BETWEEN '
            .self::MIN_REASON.' AND '.self::MAX_TEXT.')'
        );

        DB::statement(<<<'SQL'
            ALTER TABLE absences
                ADD CONSTRAINT absences_chk_version
                CHECK (version >= 1)
        SQL);
    }

    /**
     * Las equivalencias que impiden un estado que nadie sabria leer.
     *
     * Son `=` entre dos condiciones y no dos `CHECK` sueltos a proposito: lo que
     * se quiere prohibir son **los dos sentidos**. Una fila `voided` sin
     * `voided_at` no dice cuando dejo de valer; una fila con `voided_at` que
     * sigue `active` cuenta en el informe despues de haberse anulado. Las dos
     * son igual de malas y una sola desigualdad las caza juntas.
     */
    private function addConsistencyConstraints(): void
    {
        // La version 1 es original y no tiene motivo de cambio; de la 2 en
        // adelante, el motivo es obligatorio. Sin esto, una correccion sin
        // motivo seria indistinguible de un alta, que es justo lo que RN-13
        // exige poder distinguir.
        DB::statement(<<<'SQL'
            ALTER TABLE absences
                ADD CONSTRAINT absences_chk_change_reason_requires_version
                CHECK ((version = 1) = (change_reason IS NULL))
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE absences
                ADD CONSTRAINT absences_chk_voided_consistency
                CHECK ((status = 'voided') = (voided_at IS NOT NULL))
        SQL);

        DB::statement(<<<'SQL'
            ALTER TABLE absences
                ADD CONSTRAINT absences_chk_superseded_consistency
                CHECK ((status = 'superseded') = (superseded_by_id IS NOT NULL))
        SQL);

        // Anular deja siempre motivo, y solo lo deja al anular. El autor puede
        // faltar —una anulacion desde un comando no tiene cuenta detras— pero el
        // motivo no: es lo que explica por que esos dias dejaron de contar.
        DB::statement(<<<'SQL'
            ALTER TABLE absences
                ADD CONSTRAINT absences_chk_void_reason_requires_void
                CHECK ((voided_at IS NOT NULL) = (void_reason IS NOT NULL))
        SQL);

        // Una fila no puede sustituirse a si misma. Es imposible por
        // construccion —`supersedes_id` apunta a una fila anterior— y aun asi se
        // declara: un bucle de un solo nodo dejaria el historial de la pantalla
        // dando vueltas para siempre.
        DB::statement(<<<'SQL'
            ALTER TABLE absences
                ADD CONSTRAINT absences_chk_no_self_reference
                CHECK (supersedes_id IS DISTINCT FROM id AND superseded_by_id IS DISTINCT FROM id)
        SQL);
    }

    /**
     * Una persona, una ausencia activa cada dia (**RF-GP-04**).
     *
     * `'[]'` hace inclusivos los dos extremos —PostgreSQL normaliza el rango a
     * `[starts_on, ends_on + 1)` por dentro—, de modo que dos ausencias
     * consecutivas no se solapan si la segunda empieza el dia siguiente al
     * ultimo de la primera.
     *
     * El `WHERE (status = 'active')` es lo que la hace compatible con la regla
     * dura 5: las versiones supersedidas cubren los mismos dias que la vigente
     * —por definicion, si solo cambio el tipo— y las anuladas siguen en la
     * tabla. Sin el predicado, corregir una ausencia seria imposible.
     *
     * ## `DEFERRABLE INITIALLY IMMEDIATE`, y por que hace falta
     *
     * Una correccion es **una version nueva mas el cierre de la anterior**, y
     * las dos mitades no caben en una sola sentencia: la fila vieja necesita
     * apuntar a la nueva —`absences_chk_superseded_consistency` no admite
     * «sustituida por nadie»— y la nueva no tiene clave interna hasta que se
     * inserta. Cualquiera de los dos ordenes choca con una restriccion:
     *
     *   - Cerrar primero: no se puede, no hay a que apuntar todavia.
     *   - Insertar primero: las dos filas estan `active` sobre los mismos dias
     *     durante un instante, y esta restriccion lo rechaza.
     *
     * Con `DEFERRABLE` se puede pedir `SET CONSTRAINTS absences_no_overlap
     * DEFERRED` **dentro de esa transaccion concreta**, insertar, cerrar la
     * anterior y volver a `IMMEDIATE` para que se compruebe ahi mismo — que es
     * lo que hace `EloquentAbsenceRepository::supersedeWith()`.
     *
     * **`INITIALLY IMMEDIATE` y no `DEFERRED`**, que es la mitad que importa: el
     * alta normal sigue chocando **en la propia sentencia**, donde el repositorio
     * puede traducir el `SQLSTATE 23P01` a un `409` con significado. Con
     * `INITIALLY DEFERRED`, toda violacion aparecería al confirmar, lejos de la
     * sentencia que la causo y sin forma de decir cual fue.
     *
     * El `CHECK` no se puede diferir —PostgreSQL solo admite `DEFERRABLE` en
     * `UNIQUE`, `PRIMARY KEY`, `EXCLUDE` y claves ajenas—, asi que la unica
     * pieza que puede ceder es esta.
     */
    private function addNoOverlapConstraint(): void
    {
        DB::statement(<<<'SQL'
            ALTER TABLE absences
                ADD CONSTRAINT absences_no_overlap
                EXCLUDE USING gist (
                    employee_id WITH =,
                    daterange(starts_on, ends_on, '[]') WITH &&
                ) WHERE (status = 'active')
                DEFERRABLE INITIALLY IMMEDIATE
        SQL);
    }

    /**
     * «Que ausencias activas tocan este rango», que es lo unico que el informe
     * por periodo pregunta (RF-GP-04, decision 7 de la ficha 3.10).
     *
     * **Parcial**, porque el informe nunca mira las supersedidas ni las
     * anuladas: un indice que las incluyera creceria con el historico sin que
     * ninguna consulta lo usara.
     *
     * **Sin `CONCURRENTLY`**: la tabla se acaba de crear en esta misma migracion
     * y esta vacia, asi que no hay nada que bloquear, y `CREATE INDEX
     * CONCURRENTLY` no puede ejecutarse dentro de una transaccion — que es donde
     * corre una migracion de Laravel.
     */
    private function addReportingIndex(): void
    {
        DB::statement(<<<'SQL'
            CREATE INDEX absences_active_period_index
                ON absences (starts_on, ends_on)
                WHERE status = 'active'
        SQL);
    }

    /**
     * @param  list<string>  $values
     */
    private function quotedList(array $values): string
    {
        // Constantes de esta clase, nunca entrada externa; aun asi se escapan,
        // porque un literal SQL construido por concatenacion sin escapar es una
        // costumbre que acaba aplicandose a algo que si lo es.
        return implode(', ', array_map(
            static fn (string $value): string => "'".str_replace("'", "''", $value)."'",
            $values,
        ));
    }
};
