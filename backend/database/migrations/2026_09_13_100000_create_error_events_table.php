<?php

declare(strict_types=1);

use App\Modules\Shared\Domain\ValueObject\ErrorLevel;
use App\Modules\Shared\Domain\ValueObject\ErrorSource;
use App\Support\Database\LimitsMigrationLocks;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * La tabla `error_events` (doc 01 §5, **RF-PD-15**, tarea 5.12).
 *
 * ## Que registra y por que hace falta una tabla
 *
 * Todo error de la API, de la cola, del planificador, de la consola y de las
 * tres aplicaciones cliente, **agrupado por huella**. Existe porque el unico
 * otro rastro de un error vive en Loki, y Loki es opcional en la instalacion de
 * un cliente: puede desactivarlo, puede no tener quien lo mire y puede perderlo
 * al reinstalar (doc 02 §8.2.1). Si el unico rastro de un fallo vive en un stack
 * que el cliente quiza no conserve, la primera pregunta de cada incidencia sera
 * «¿puedes mirar los logs?» y la respuesta sera que no.
 *
 * Esta tabla, en cambio, vive en la base de datos que se respalda a diario y
 * viaja dentro del paquete de diagnostico (§11.6.6), que es lo unico que sale
 * hacia el fabricante (ADR-020).
 *
 * ## Una fila por FALLO, no por repeticion
 *
 * Un error en el endpoint de fichaje durante un cambio de turno genera cientos
 * de errores identicos. Sin agrupacion, la tabla se llena de ruido y el error
 * importante queda enterrado. La huella —`fingerprint`, 64 hexadecimales— es el
 * hash de origen, clase de excepcion, punto de fallo y **mensaje normalizado sin
 * identificadores variables**; cada repeticion incrementa `occurrences` y mueve
 * `last_seen_at`. Mil repeticiones son una fila con `occurrences = 1000`.
 *
 * El `UNIQUE` sobre `fingerprint` **no es un adorno del modelo**: es lo que hace
 * correcto el `INSERT … ON CONFLICT` bajo concurrencia. Sin el, cientos de
 * procesos escribiendo el mismo fallo a la vez dejarian cientos de filas, que es
 * exactamente el ruido que la agrupacion evita.
 *
 * ## Patron `/migracion-segura`: creacion pura
 *
 * | Despliegue | Que se hace | Estado del codigo |
 * |---|---|---|
 * | **1 (expand)** | Esta migracion: una tabla nueva. Nada se renombra, nada se borra, ninguna tabla existente se toca. | La version anterior no la nombra y sigue funcionando. |
 * | **2 (migrate)** | *No aplica.* No habia historico antes. | — |
 * | **3 (contract)** | *No aplica.* | — |
 *
 * Al nacer vacia, las columnas obligatorias y los `CHECK` se declaran ya en
 * vigor: no hay filas que recorrer ni nadie leyendo, asi que ninguna de estas
 * sentencias bloquea nada (RNF-D-04).
 *
 * ## Lo que esta tabla NO puede contener, y lo declara el esquema
 *
 * **Ni un nombre, ni un correo, ni un DNI, ni una hora de fichaje de nadie**
 * (regla dura 21, RL-19). Las personas aparecen **solo** como `employee_uuid` y
 * los quioscos solo como `device_id`, los dos identificadores publicos. Por eso
 * no hay ninguna clave ajena hacia `employees` ni hacia `devices`: un `JOIN`
 * comodo hacia la plantilla seria la primera via por la que un nombre acabaria
 * en el paquete que sale hacia el fabricante.
 *
 * El resto de la garantia no la puede dar el esquema y la dan el saneado de
 * servidor (`ErrorMessageSanitizer`) y la lista cerrada de claves de `context`
 * (`ErrorContextAllowlist`), con `ErrorEventsHaveNoPersonalDataTest` detras.
 *
 * ## Esto NO es `audit_log` (regla dura 6, al reves)
 *
 * `audit_log` es solo-apendice, encadenado por hash, con cuatro anos de
 * retencion y valor probatorio; el rol de la aplicacion **no puede** actualizarlo
 * ni borrarlo. Aqui es al contrario y a proposito: son datos **tecnicos**, con 90
 * dias de vida (`ERROR_HISTORY_RETENTION_DAYS`, RL-11), y el rol de la aplicacion
 * si tiene `DELETE` —lo necesita `product:errors:prune`— y `UPDATE` —lo necesita
 * el `ON CONFLICT` que incrementa el recuento—.
 *
 * **Resolver no deja asiento, y esta tabla tampoco conserva el historial de
 * quien lo hizo.** `resolved_at` y `resolved_by_user_id` describen el estado
 * ACTUAL, no una traza: cuando un grupo resuelto vuelve a ocurrir, la escritura
 * los vacia a proposito —el contrato declara que un grupo abierto los lleva
 * nulos— y con ellos se va el rastro de la resolucion anterior. Es la
 * consecuencia aceptada de que «resuelto» signifique «ya no pasa» y no «ya no se
 * ve»: lo que importa de un fallo que reaparece es que esta abierto otra vez, no
 * quien creyo haberlo arreglado. Quien necesite ese historial tiene el log
 * tecnico; `audit_log` no es el sitio, porque esto no tiene relevancia legal.
 *
 * ## Los indices, y por que exactamente tres
 *
 * Son las tres preguntas que se le hacen a esta tabla, y ninguna mas:
 *
 * 1. `(last_seen_at DESC)` — el orden del listado del panel y del comando, y
 *    ademas la columna por la que purga el ciclo corto de retencion.
 * 2. `(level, resolved_at)` — «¿que hay abierto, y hay algo critico?», que es la
 *    cabecera de la pantalla (`open_errors` y `open_critical` de `meta`).
 * 3. `(source)` — el filtro por origen de RF-PD-15.
 *
 * El `UNIQUE` de `fingerprint` cuenta aparte: no esta para consultar sino para
 * que el `ON CONFLICT` exista.
 *
 * ## Sin ningun `GRANT` aqui
 *
 * La migracion `099000` declara `ALTER DEFAULT PRIVILEGES` para el rol de la
 * aplicacion (ADR-033), asi que la tabla nace con `SELECT, INSERT, UPDATE,
 * DELETE` puestos, que es justo lo que este historico necesita.
 *
 * ## `down()`
 *
 * Suelta la tabla. Es legitimo y no destruye ninguna evidencia con obligacion de
 * conservacion: aqui no hay ni un dato del registro horario ni un asiento legal,
 * solo diagnostico tecnico con 90 dias de vida. Es lo que ejercita
 * `MigrationsRoundTripTest`.
 */
return new class extends Migration
{
    use LimitsMigrationLocks;

    public function up(): void
    {
        $this->limitLockWait();

        Schema::create('error_events', function (Blueprint $table): void {
            $table->id();

            /*
             * LA COLUMNA CENTRAL. `sha256` en hexadecimal: 64 caracteres
             * exactos, de ahi `char` y no `string`.
             *
             * El `UNIQUE` es lo que hace posible `INSERT … ON CONFLICT
             * (fingerprint) DO UPDATE`, que es como se agrupa sin condicion de
             * carrera. Comprobar antes con un `SELECT` dejaria pasar las
             * escrituras simultaneas de un cambio de turno, que es el caso
             * normal y no el excepcional.
             */
            $table->char('fingerprint', 64)->unique();

            /** `error` o `critical`. `CHECK` mas abajo, compuesto del enum. */
            $table->string('level', 16);

            /** `api`, `worker`, `scheduler`, `console`, `kiosk`, `admin` o `portal`. */
            $table->string('source', 16);

            /*
             * Modulo del monolito al que pertenece el punto de fallo
             * (`attendance`, `kiosk`, `product`…), deducido del espacio de
             * nombres. Nulo cuando el fallo esta fuera de los modulos o viene de
             * un cliente.
             */
            $table->string('module', 40)->nullable();

            /*
             * Codigo estable del error. En los clientes es el del catalogo
             * cerrado de su reporter (`kiosk.camera.unavailable`,
             * `web.vue_error`); en el servidor, nulo salvo que la excepcion
             * declare uno.
             */
            $table->string('code', 80)->nullable();

            /*
             * El mensaje **saneado** de la PRIMERA aparicion, no el normalizado.
             *
             * El normalizado sirve para agrupar y es ilegible (`<uuid>`, `<n>`,
             * `<path>`); quien abre el panel necesita leer una frase. Mil
             * caracteres es el techo del contrato: por encima, un volcado de
             * PostgreSQL con una fila entera dentro dejaria de ser un mensaje.
             */
            $table->string('message', 1000);

            /** Clase de la excepcion en el servidor; nula en un error de cliente. */
            $table->string('exception_class', 255)->nullable();

            /*
             * Punto de fallo, relativo a la raiz de la aplicacion. Nulo en un
             * error de cliente: el `stack` del navegador **nunca** viaja, porque
             * una URL con un uuid dentro correlaciona a una persona.
             */
            $table->string('file', 255)->nullable();
            $table->integer('line')->nullable();

            /*
             * Datos tecnicos por LISTA DE PERMITIDOS (`route`, `method`,
             * `status`, `job`, `queue`, `attempts`, `command`, `component`,
             * `hook`, `cause`, `http_status`, `code`, `skew_seconds`,
             * `queue_size`, `outcome`, `reason`), escalares y truncados a 200.
             *
             * `jsonb` y no `json`: se consulta por clave desde el paquete de
             * diagnostico y se compara entre filas.
             */
            $table->jsonb('context')->default(DB::raw("'{}'::jsonb"));

            /*
             * Traza W3C de la peticion en la que ocurrio la ULTIMA vez, para
             * correlacionar con el log tecnico cuando el cliente si conserva
             * Loki. 32 hexadecimales; nula fuera de una peticion.
             */
            $table->char('trace_id', 32)->nullable();

            /*
             * Los dos unicos identificadores de «quien» admitidos (regla dura
             * 21). Sin clave ajena a proposito: ver el docblock de la clase.
             * Son los de la ULTIMA aparicion.
             */
            $table->uuid('device_id')->nullable();
            $table->uuid('employee_uuid')->nullable();

            /**
             * Version del servidor o del cliente en la ultima aparicion (§10.5).
             * Es lo que permite saber si un fallo desaparecio al actualizar o si
             * llego con la actualizacion.
             */
            $table->string('app_version', 32);

            /*
             * Cuantas veces se ha visto esta huella. Nunca cero: la fila nace
             * con la primera aparicion. `CHECK` mas abajo.
             */
            $table->integer('occurrences')->default(1);

            $table->timestampTz('first_seen_at', 6);
            $table->timestampTz('last_seen_at', 6);

            /*
             * Quien lo dio por atendido y cuando. **Se vacian solos si el error
             * vuelve a ocurrir**: que un fallo dado por arreglado reaparezca es
             * exactamente lo que IT tiene que ver, y por eso «resuelto» significa
             * «ya no pasa» y no «ya no se ve».
             *
             * `nullOnDelete` y no `restrict`: lo que no puede pasar es que el
             * historico deje de poder leerse porque se borro una cuenta.
             */
            $table->timestampTz('resolved_at', 6)->nullable();
            $table->foreignId('resolved_by_user_id')->nullable()->constrained('users')->nullOnDelete();

            $table->timestampsTz(6);
        });

        $levels = self::quotedList(ErrorLevel::names());
        $sources = self::quotedList(ErrorSource::names());

        /*
         * Los dos catalogos se componen del enum y no se escriben a mano.
         *
         * Dos listas que tienen que decir lo mismo acaban divergiendo, y el dia
         * que lo hicieran la base de datos rechazaria una fila que el codigo
         * considera valida — un fallo que solo aparece en la instalacion del
         * cliente y justo cuando algo ya iba mal.
         */
        DB::statement(
            'ALTER TABLE error_events ADD CONSTRAINT error_events_chk_level CHECK (level IN ('.$levels.'))'
        );

        DB::statement(
            'ALTER TABLE error_events ADD CONSTRAINT error_events_chk_source CHECK (source IN ('.$sources.'))'
        );

        /*
         * Un grupo existe porque paso al menos una vez. Un `occurrences = 0`
         * seria una fila que afirma un error que nunca ocurrio, y el panel
         * pintaria «visto 0 veces».
         */
        DB::statement(
            'ALTER TABLE error_events ADD CONSTRAINT error_events_chk_occurrences CHECK (occurrences >= 1)'
        );

        /*
         * Resuelto es un estado con dos mitades: el instante y quien lo decidio.
         * Una fila con `resolved_at` y sin autor —o al reves— seria un «resuelto
         * por nadie» que nadie sabria interpretar seis meses despues.
         *
         * La cuenta borrada es la excepcion admitida: `nullOnDelete` puede dejar
         * `resolved_by_user_id` a nulo conservando `resolved_at`, y eso sigue
         * siendo cierto —se resolvio, la persona ya no esta—. De ahi que el
         * `CHECK` mire solo el sentido contrario.
         */
        DB::statement(<<<'SQL'
            ALTER TABLE error_events
                ADD CONSTRAINT error_events_chk_resolution
                CHECK (resolved_by_user_id IS NULL OR resolved_at IS NOT NULL)
            SQL);

        /*
         * El orden del listado —`last_seen_at` descendente— y la columna por la
         * que envejece el ciclo corto de retencion (`ERROR_HISTORY_RETENTION_DAYS`,
         * RL-11). `DESC` explicito porque es como se lee siempre.
         */
        DB::statement('CREATE INDEX error_events_last_seen_index ON error_events (last_seen_at DESC)');

        /*
         * «¿Que tengo abierto, y hay algo critico?»: los dos recuentos de la
         * cabecera del panel y el filtro por defecto (`status=open`).
         */
        DB::statement('CREATE INDEX error_events_level_status_index ON error_events (level, resolved_at)');

        /* El filtro por origen de RF-PD-15. */
        DB::statement('CREATE INDEX error_events_source_index ON error_events (source)');
    }

    public function down(): void
    {
        $this->limitLockWait();

        Schema::dropIfExists('error_events');
    }

    /**
     * `'a', 'b'` a partir de un catalogo del dominio.
     *
     * Los valores son identificadores de un enum de PHP, sin comillas posibles;
     * aun asi se escapan, porque componer SQL por concatenacion confiando en la
     * forma del dato es exactamente como se escriben las inyecciones que nadie
     * ve venir.
     *
     * @param  list<string>  $values
     */
    private static function quotedList(array $values): string
    {
        return implode(', ', array_map(
            static fn (string $value): string => "'".str_replace("'", "''", $value)."'",
            $values,
        ));
    }
};
