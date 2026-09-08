<?php

declare(strict_types=1);

use App\Modules\Product\Domain\ValueObject\DataExportFailure;
use App\Modules\Product\Domain\ValueObject\DataExportOrigin;
use App\Modules\Product\Domain\ValueObject\DataExportStatus;
use App\Support\Database\LimitsMigrationLocks;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * La tabla `data_exports` (doc 01 §5, **RF-PD-14**, RL-20, tarea 5.10).
 *
 * ## Que registra y por que hace falta una tabla
 *
 * La exportacion integra es la garantia del cliente de no quedarse atrapado
 * (RL-20): puede llevarse **todos** sus datos, cuando quiera y sin pedir permiso.
 * Lo que se lleva es un fichero, y un fichero que contiene la plantilla entera y
 * cuatro años de fichajes no puede generarse y olvidarse: hay que saber quien lo
 * pidio, cuando, cuanto ocupa, que huella tiene, quien se lo llevo y cuando
 * caduca. Eso es esta tabla.
 *
 * Sin ella, la unica forma de responder «¿que copia completa de mis datos anda
 * suelta por ahi?» seria mirar el directorio con `ls`, que no dice ni quien la
 * pidio ni si alguien se la descargo.
 *
 * ## Patron `/migracion-segura`: creacion pura
 *
 * | Despliegue | Que se hace | Estado del codigo |
 * |---|---|---|
 * | **1 (expand)** | Esta migracion: una tabla nueva. Nada se renombra, nada se borra, ninguna tabla existente se toca. | La version anterior no la nombra y sigue funcionando. |
 * | **2 (migrate)** | *No aplica.* No habia exportaciones antes. | — |
 * | **3 (contract)** | *No aplica.* | — |
 *
 * Al nacer vacia, las columnas obligatorias y los `CHECK` se declaran ya en
 * vigor: no hay filas que recorrer ni nadie leyendo, asi que ninguna de estas
 * sentencias bloquea nada (RNF-D-04).
 *
 * ## Las invariantes que declara el esquema, y no solo PHP
 *
 * 1. **`status` es uno de cinco** y **`requested_via` uno de dos**. Los dos
 *    catalogos viven en {@see DataExportStatus} y {@see DataExportOrigin} y el
 *    `CHECK` se compone de ellos: no hay forma de que el enum y la base de datos
 *    se separen sin que se vea.
 * 2. **UNA SOLA EXPORTACION EN CURSO POR INSTALACION.** Indice unico parcial
 *    sobre una expresion constante —la misma tecnica que `sites_single_row_uidx`
 *    de ADR-040— restringido a `pending` y `running`. Dos recorridos simultaneos
 *    de todas las tablas competirian por la misma base de datos por la que pasa
 *    cada fichaje (ADR-010), y no hay ninguna razon para generar dos copias
 *    completas a la vez. El endpoint responde `409` antes de llegar aqui; **esto
 *    es la red que atrapa la carrera de dos pulsaciones simultaneas**, que
 *    ninguna comprobacion en PHP puede cerrar.
 * 3. **`completed` implica fichero.** Una fila terminada sin nombre, sin huella
 *    y sin tamaño seria una exportacion que el panel ofrece descargar y que no
 *    existe: el cliente creeria tener su copia sin tenerla.
 *
 * ## Lo que NO esta aqui
 *
 * - **Ningun dato de empleado.** Lo que hay son recuentos por fichero
 *   (`row_counts`), y un recuento no identifica a nadie (regla dura 21).
 * - **El contenido del fichero.** Vive en `PRODUCT_DATA_EXPORT_PATH`, con el
 *   directorio a `0700` y el fichero a `0600`; aqui solo esta su ruta.
 * - **`deleted_at` ni nada que borre.** Purgar por caducidad **marca**
 *   `purged_at` y borra el fichero; la fila se queda para siempre (regla dura
 *   5), porque «¿salio de aqui una copia completa en marzo?» hay que poder
 *   contestarlo años despues. El historico completo, ademas, esta en `audit_log`
 *   (`data_export.requested|generated|downloaded`).
 * - **`failure_reason` con el mensaje del fallo, ni con la clase de la
 *   excepcion.** Uno de los cuatro codigos de {@see DataExportFailure}, con su
 *   `CHECK`: un mensaje de PostgreSQL puede llevar dentro el valor de una fila
 *   (regla dura 21), y el nombre de una clase de PHP no le dice nada a quien lee
 *   el panel. La clase real va al log tecnico junto al `uuid`.
 *
 * ## Los indices, y por que solo tres
 *
 * `GET /api/v1/data-export` devuelve las 20 mas recientes por `requested_at
 * DESC`, y la purga horaria busca las vencidas que aun tienen fichero. Esas son
 * las dos consultas del producto sobre esta tabla, que tendra decenas de filas
 * en la vida de una instalacion: cualquier indice de mas seria mantenimiento sin
 * lectura que lo justifique. El tercero es el unico parcial de la invariante 2.
 *
 * ## Sin ningun `GRANT` aqui
 *
 * La migracion `099000` declara `ALTER DEFAULT PRIVILEGES` para el rol de la
 * aplicacion (ADR-033), asi que la tabla nace con sus permisos puestos. El rol
 * si tiene `UPDATE` sobre ella —marcar `running`, `completed` o `purged` lo
 * son—, y lo que protege la evidencia no es el permiso sobre esta tabla sino que
 * cada peticion, cada generacion y cada descarga dejan ademas su asiento en
 * `audit_log`, donde ese mismo rol no puede ni actualizar ni borrar (regla dura
 * 6).
 *
 * ## `down()`
 *
 * Suelta la tabla. Es legitimo: **no se pierde ninguna evidencia con obligacion
 * de conservacion**, porque cada peticion, generacion y descarga constan tambien
 * en `audit_log`, que es solo-apendice y se conserva cuatro años (RL-02). Lo que
 * desaparece al revertir es el registro operativo de los ficheros, no el rastro
 * legal de que se generaron. Los ficheros del disco no los borra `down()` a
 * proposito: revertir una migracion no puede destruir datos del cliente.
 */
return new class extends Migration
{
    use LimitsMigrationLocks;

    public function up(): void
    {
        $this->limitLockWait();

        Schema::create('data_exports', function (Blueprint $table): void {
            $table->id();

            /*
             * El identificador PUBLICO: el que viaja en la URL de descarga y en
             * la respuesta. Por lo mismo que el del quiosco y el de la concesion
             * de soporte: la clave interna no sale de la base de datos, y un
             * numero secuencial en la ruta diria cuantas veces se ha llevado el
             * cliente una copia de todo.
             */
            $table->uuid('uuid')->unique();

            /*
             * Quien la pidio. **Nulo cuando la pidio la consola**
             * (`product:export-all`): ahi no hay sesion que atribuir, y decir
             * «usuario desconocido» seria peor que decir la verdad. Eso tambien
             * es informacion —distingue «la pidio Marta desde el panel» de «la
             * pidio alguien por SSH»— y por eso `requested_via` va aparte.
             *
             * `nullOnDelete` y no `restrict`: lo que no puede pasar es que la
             * lista de exportaciones deje de poder leerse porque se borro una
             * cuenta. Quien la pidio sigue estando en `audit_log`.
             */
            $table->foreignId('requested_by_user_id')->nullable()->constrained('users')->nullOnDelete();

            /** `panel` o `console`. `CHECK` mas abajo: el catalogo es cerrado. */
            $table->string('requested_via', 16);

            /** `pending`, `running`, `completed`, `failed` o `purged`. */
            $table->string('status', 16);

            $table->timestampTz('requested_at', 6);
            $table->timestampTz('started_at', 6)->nullable();
            $table->timestampTz('completed_at', 6)->nullable();
            $table->timestampTz('failed_at', 6)->nullable();

            /*
             * Uno de los cuatro codigos de {@see DataExportFailure}, nunca la
             * clase ni el mensaje de la excepcion. Ver el docblock de la clase.
             * `CHECK` mas abajo: el catalogo es cerrado.
             */
            $table->string('failure_reason', 32)->nullable();

            /*
             * La ruta absoluta del ZIP en el servidor. **No sale en la API**: el
             * panel descarga por `uuid` y una ruta del sistema de ficheros no le
             * sirve de nada a un navegador. Esta aqui para que la purga sepa que
             * borrar sin tener que recomponer el nombre.
             */
            $table->string('file_path', 1024)->nullable();

            /** `kronoqr-export-<version>-<UTC>.zip`, que es lo que ve quien descarga. */
            $table->string('file_name', 255)->nullable();

            $table->bigInteger('size_bytes')->nullable();

            /** SHA-256 del ZIP en hexadecimal: 64 caracteres. */
            $table->string('sha256', 64)->nullable();

            /*
             * Filas de datos por fichero del ZIP: `{"employees": 500,
             * "shift_entries": 45000, ...}`. Es lo que permite comprobar de un
             * vistazo que la copia esta completa sin abrirla, y lo que el panel
             * enseña. **Recuentos, nunca datos.**
             */
            $table->jsonb('row_counts')->default(DB::raw("'{}'::jsonb"));

            /*
             * Hasta cuando se puede descargar. Vencida, `product:export-all
             * --purge` borra el fichero y marca `purged_at`. Una copia completa
             * de la plantilla criando polvo en el disco del cliente no puede
             * depender de que alguien se acuerde de borrarla.
             */
            $table->timestampTz('expires_at', 6)->nullable();
            $table->timestampTz('purged_at', 6)->nullable();

            /** Ultima descarga, y cuantas van. Cada una deja su asiento (RS-05). */
            $table->timestampTz('downloaded_at', 6)->nullable();
            $table->integer('download_count')->default(0);

            $table->timestampsTz(6);
        });

        $statuses = self::quotedList(DataExportStatus::names());
        $origins = self::quotedList(DataExportOrigin::names());
        $failures = self::quotedList(DataExportFailure::names());

        DB::statement(
            'ALTER TABLE data_exports ADD CONSTRAINT data_exports_chk_status CHECK (status IN ('.$statuses.'))'
        );

        DB::statement(
            'ALTER TABLE data_exports ADD CONSTRAINT data_exports_chk_requested_via '
            .'CHECK (requested_via IN ('.$origins.'))'
        );

        /*
         * El motivo del fallo es un CODIGO del catalogo, no texto libre.
         *
         * Es lo que impide que vuelva a colarse ahi el nombre de una clase de PHP
         * —o, peor, el mensaje de un error de base de datos con el valor de una
         * fila dentro (regla dura 21)—, que es exactamente lo que esta columna
         * llevaba antes de la revision. Nulo mientras no haya fallado.
         */
        DB::statement(
            'ALTER TABLE data_exports ADD CONSTRAINT data_exports_chk_failure_reason '
            .'CHECK (failure_reason IS NULL OR failure_reason IN ('.$failures.'))'
        );

        /*
         * Una exportacion terminada tiene fichero, huella y tamaño. Sin esto, un
         * fallo a medias podria dejar una fila `completed` que el panel ofrece
         * descargar y que devuelve `404`.
         */
        DB::statement(<<<'SQL'
            ALTER TABLE data_exports
                ADD CONSTRAINT data_exports_chk_completed_is_complete
                CHECK (
                    status <> 'completed'
                    OR (
                        file_name IS NOT NULL
                        AND sha256 IS NOT NULL
                        AND size_bytes IS NOT NULL
                        AND completed_at IS NOT NULL
                    )
                )
            SQL);

        /*
         * UNA SOLA EN CURSO. Indice unico parcial sobre una expresion constante:
         * la misma tecnica con la que ADR-040 impide un segundo centro
         * (`sites_single_row_uidx`). Las filas terminadas, fallidas y purgadas no
         * entran en el indice, asi que se acumulan sin estorbar.
         */
        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX data_exports_single_in_progress_uidx
                ON data_exports ((true))
                WHERE status IN ('pending', 'running')
            SQL);

        // El orden del listado: las 20 mas recientes, de la mas nueva a la mas
        // antigua.
        DB::statement('CREATE INDEX data_exports_recent_index ON data_exports (requested_at DESC)');

        /*
         * La consulta de la purga horaria: las que ya vencieron y todavia tienen
         * fichero. Parcial, porque en cuanto se purgan dejan de interesar y son,
         * con el tiempo, casi todas.
         */
        DB::statement(<<<'SQL'
            CREATE INDEX data_exports_expired_index
                ON data_exports (expires_at)
                WHERE purged_at IS NULL AND status = 'completed'
            SQL);
    }

    public function down(): void
    {
        $this->limitLockWait();

        Schema::dropIfExists('data_exports');
    }

    /**
     * `'a', 'b'` a partir de un catalogo del dominio.
     *
     * Se compone del enum a proposito: escribir los valores a mano aqui daria
     * dos listas que tienen que decir lo mismo, y el dia que divergieran la base
     * de datos rechazaria una fila que el codigo considera valida — un fallo que
     * solo aparece en la instalacion del cliente.
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
