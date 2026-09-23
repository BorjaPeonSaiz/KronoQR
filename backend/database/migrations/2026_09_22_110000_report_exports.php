<?php

declare(strict_types=1);

use App\Modules\Reporting\Domain\ValueObject\ReportExportFailure;
use App\Modules\Reporting\Domain\ValueObject\ReportExportKind;
use App\Modules\Reporting\Domain\ValueObject\ReportExportNotificationChannel;
use App\Modules\Reporting\Domain\ValueObject\ReportExportStatus;
use App\Support\Database\LimitsMigrationLocks;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * La tabla `report_exports` (doc 01 §5.5, **RF-IN-06**, RF-IN-07, ADR-041,
 * decision 1 de la ficha 3.9).
 *
 * ## Que registra y por que hace falta una tabla
 *
 * Un informe de horas de tres meses de la plantilla entera no cabe en una
 * respuesta HTTP: `GET /reports/period` responde `422` por encima de sus techos
 * y remite a la generacion en diferido. Diferir significa que el fichero existe
 * **sin nadie delante**, y un fichero con las horas nominales de quinientas
 * personas en el disco del cliente no puede generarse y olvidarse: hay que saber
 * quien lo pidio, con que alcance, en que estado esta, cuanto ocupa, que huella
 * tiene, quien se lo llevo y cuando caduca. Eso es esta tabla.
 *
 * Sin ella, la unica forma de responder «¿que informes con datos de mi plantilla
 * andan sueltos por el servidor?» seria mirar el directorio con `ls`, que no
 * dice ni quien los pidio ni si alguien se los descargo.
 *
 * ## Patron `/migracion-segura`: creacion pura
 *
 * | Despliegue | Que se hace | Estado del codigo |
 * |---|---|---|
 * | **1 (expand)** | Esta migracion: una tabla nueva. Nada se renombra, nada se borra, ninguna tabla existente se toca. | La version anterior no la nombra y sigue funcionando. |
 * | **2 (migrate)** | *No aplica.* No habia informes en diferido antes. | — |
 * | **3 (contract)** | *No aplica.* | — |
 *
 * Al nacer vacia, las columnas obligatorias y los `CHECK` se declaran ya en
 * vigor: no hay filas que recorrer ni nadie leyendo, asi que ninguna de estas
 * sentencias bloquea nada (RNF-D-04). Por eso tampoco hace falta
 * `CREATE INDEX CONCURRENTLY`: no hay concurrencia sobre una tabla que se acaba
 * de crear, y `CONCURRENTLY` no puede ejecutarse dentro de la transaccion de la
 * migracion.
 *
 * ## Las invariantes que declara el esquema, y no solo PHP
 *
 * 1. **Cinco catalogos cerrados con `CHECK`**: `status`, `kind`, `format`,
 *    `failure_reason` y `notification_channel`. Los cinco se componen **desde
 *    los enumerados del dominio**, no escritos a mano: dos listas que tienen que
 *    decir lo mismo acaban divergiendo, y el dia que lo hicieran la base de datos
 *    rechazaria una fila que el codigo considera valida — un fallo que solo
 *    aparece en la instalacion del cliente.
 * 2. **UNA SOLA EN CURSO POR SOLICITANTE.** Indice unico parcial sobre
 *    `requested_by_user_id` restringido a `pending` y `running`. **Por persona y
 *    no por instalacion**, al contrario que `data_exports`: aquella recorre todas
 *    las tablas y dos a la vez competirian por la base de datos por la que pasa
 *    cada fichaje (ADR-010); esta es una consulta acotada por alcance, y que RRHH
 *    este generando el cierre de mes no puede impedirle a un responsable pedir el
 *    suyo. Lo que si impide es que una misma persona llene la cola pulsando el
 *    boton diez veces porque la pantalla tarda en refrescar. El endpoint responde
 *    `409` antes de llegar aqui; **esto es la red que atrapa la carrera de dos
 *    pestañas simultaneas**, que ninguna comprobacion en PHP puede cerrar.
 * 3. **`completed` implica fichero.** Una fila terminada sin nombre, sin huella,
 *    sin tamaño, sin recuento o sin caducidad seria un informe que el panel
 *    ofrece descargar y que no existe.
 * 4. **`payroll` no admite PDF.** Un programa de nomina no importa un PDF
 *    (decision 5), y `ReportExportKind::allows()` ya lo deja fuera en PHP. El
 *    `CHECK` es la mitad que garantiza que no entre por otra via.
 *
 * ## Lo que NO esta aqui
 *
 * - **El token de descarga.** Solo su `sha256` (ADR-041). Quien lea la base de
 *   datos ve una huella, no una llave: mismo criterio que `credentials.signed_key`
 *   y que `employees.pin_hash`.
 * - **Ningun nombre de persona.** `parameters` lleva como mucho un
 *   `employee_uuid`, que es un identificador publico; `criteria` lleva frases
 *   sobre el informe, no sobre nadie (regla dura 21).
 * - **El contenido del fichero.** Vive en `REPORTING_EXPORT_PATH`, **fuera de
 *   `public/`**; aqui solo esta su ruta, y esa ruta no sale nunca en la API.
 * - **`deleted_at` ni nada que borre.** Purgar por caducidad **marca**
 *   `purged_at`, limpia `file_path` y borra el fichero; la fila se queda para
 *   siempre (regla dura 5).
 * - **Ningun dato personal despues de purgar** (RL-11). Al perder el fichero, la
 *   fila pierde tambien los tres campos que señalan a personas: `scope` —la
 *   lista de departamentos que alcanzaba quien lo pidio— y los filtros
 *   `employee_uuid` y `department_id` de `parameters`. Lo declara el `CHECK`
 *   `report_exports_chk_purged_is_minimised`, no solo el modelo.
 *
 *   **Por eso `report_exports` NO entra en `RetentionScope`**, y no es un olvido:
 *   una tabla necesita plazo de retencion cuando conserva datos personales, y
 *   esta deja de conservarlos en cuanto vence el suyo —`REPORTING_EXPORT_RETENTION_DAYS`,
 *   siete dias—. Guardar la fila indefinidamente es entonces gratis y sigue
 *   contestando «¿salio de aqui un informe, cuando y a peticion de quien?», que
 *   es lo que la regla dura 5 exige. El hecho completo, con sus parametros y su
 *   alcance, esta en `audit_log` (`report_export.requested`), que si tiene plazo:
 *   cuatro años (RL-02).
 * - **`failure_reason` con el mensaje del fallo.** Uno de los cinco codigos de
 *   {@see ReportExportFailure}, con su `CHECK`: un mensaje de PostgreSQL puede
 *   llevar dentro el valor de una fila (regla dura 21) y esta columna **se
 *   serializa en la API**.
 *
 * ## Los indices, y por que solo tres
 *
 * `GET /api/v1/reports/exports` devuelve las 20 mas recientes **de quien
 * pregunta**, y la purga diaria busca las vencidas que aun tienen fichero. Esas
 * son las dos consultas del producto sobre esta tabla, que tendra unos cientos
 * de filas en la vida de una instalacion: cualquier indice de mas seria
 * mantenimiento sin lectura que lo justifique. El tercero es el unico parcial de
 * la invariante 2. La busqueda por `uuid` la sirve el unico de la columna.
 *
 * ## `requested_by_user_id` es NOT NULL, y con `RESTRICT`
 *
 * Al contrario que en `data_exports`, donde la consola puede pedir una sin
 * sesion. Aqui siempre hay alguien: **la exportacion es suya** —solo el
 * solicitante la ve y la descarga (decision 2)— y una fila sin dueño no tendria
 * quien la consultara ni a quien avisar.
 *
 * `restrictOnDelete` y no `nullOnDelete` por lo mismo: una fila huerfana seria un
 * fichero con horas de la plantilla que nadie puede ver ni descargar y que
 * tampoco se purgaria por el camino normal. El producto **no borra cuentas de
 * gestion** —las desactiva con `identity:deactivate-user`, regla dura 5—, asi
 * que esta restriccion no estorba a ningun camino real; lo que hace es obligar a
 * que, si algun dia se borrara una, alguien decida antes que pasa con sus
 * ficheros en lugar de descubrirlo despues.
 *
 * ## Sin ningun `GRANT` aqui
 *
 * La migracion `099000` declara `ALTER DEFAULT PRIVILEGES` para el rol de la
 * aplicacion (ADR-033), asi que la tabla nace con sus permisos puestos. El rol si
 * tiene `UPDATE` sobre ella —marcar `running`, `completed`, `purged` o consumir
 * un enlace lo son—, y lo que protege la evidencia no es el permiso sobre esta
 * tabla sino que pedir, generar y descargar dejan ademas su asiento en
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

        Schema::create('report_exports', function (Blueprint $table): void {
            $table->id();

            /*
             * El identificador PUBLICO: el de la URL de estado y el de la de
             * descarga. UUID v7, ordenado en el tiempo —el indice no se
             * fragmenta— y sin decir cuantos informes se han pedido.
             *
             * Aqui cumple ademas una segunda funcion (ADR-041): junto al token,
             * **es la mitad del secreto de la ruta de descarga sin sesion**. De
             * sus 122 bits, 48 son marca de tiempo y por tanto adivinables: lo que
             * aporta son ~74 bits aleatorios, y la otra mitad son los 256 bits del
             * token de un solo uso.
             */
            $table->uuid('uuid')->unique();

            /** `period` o `payroll`. `CHECK` mas abajo: el catalogo es cerrado. */
            $table->string('kind', 16);

            /** `csv`, `xlsx` o `pdf`. `CHECK` mas abajo, y otro que cruza con `kind`. */
            $table->string('format', 8);

            /** `pending`, `running`, `completed`, `failed` o `purged`. */
            $table->string('status', 16);

            /*
             * Lo que se pidio, con las claves del contrato: `from`, `to`,
             * `granularity`, `group_by`, `include_open_shifts`, `department_id`,
             * `employee_uuid`.
             *
             * `jsonb` y no siete columnas: son los parametros de UNA consulta
             * concreta, no atributos de la exportacion, y el dia que el informe
             * gane un filtro no se puede exigir una migracion para poder pedirlo
             * en diferido. No se indexa ni se consulta por dentro: se lee entera
             * al generar y se enseña entera en la API.
             */
            $table->jsonb('parameters');

            /*
             * EL ALCANCE DEL SOLICITANTE EN EL MOMENTO DE PEDIRLO (RF-ID-03).
             *
             * `{"unrestricted": true}` o `{"departments": [3, 7]}`. Es una
             * INSTANTANEA y el trabajo la aplica tal cual: si se recalculara al
             * ejecutar, un responsable al que le quitan un departamento entre la
             * peticion y la generacion recibiria un fichero distinto del que
             * pidio, y el asiento de `audit_log` describiria otra cosa.
             *
             * **NULABLE porque se borra al purgar** (RL-11): la lista de
             * departamentos que alguien alcanzaba es un dato sobre esa persona, y
             * en cuanto el fichero desaparece deja de tener uso operativo. Ver el
             * `CHECK` de minimizacion mas abajo.
             */
            $table->jsonb('scope')->nullable();

            /*
             * Quien lo pidio. NOT NULL y con `RESTRICT`: ver el docblock de la
             * clase.
             */
            $table->foreignId('requested_by_user_id')->constrained('users')->restrictOnDelete();

            $table->timestampTz('requested_at', 6);
            $table->timestampTz('started_at', 6)->nullable();
            $table->timestampTz('completed_at', 6)->nullable();
            $table->timestampTz('failed_at', 6)->nullable();

            /*
             * Uno de los cinco codigos de {@see ReportExportFailure}, nunca la
             * clase ni el mensaje de la excepcion. `CHECK` mas abajo.
             */
            $table->string('failure_reason', 32)->nullable();

            /*
             * La ruta absoluta del fichero en el servidor. **No sale en la API**:
             * la descarga va por `uuid` y una ruta del sistema de ficheros no le
             * sirve de nada a un navegador — y si le diria a quien no debe donde
             * mirar. Esta aqui para que la purga sepa que borrar.
             */
            $table->string('file_path', 1024)->nullable();

            /**
             * `kronoqr-horas-<desde>_<hasta>.<ext>`, que es lo que ve quien
             * descarga. **Sin ningun nombre de persona** (regla dura 21): viaja en
             * `Content-Disposition` y acaba en el historial de descargas de un
             * navegador que puede ser compartido.
             */
            $table->string('file_name', 255)->nullable();

            $table->bigInteger('size_bytes')->nullable();

            /** SHA-256 del fichero en hexadecimal: 64 caracteres. */
            $table->string('sha256', 64)->nullable();

            /**
             * Filas de datos del fichero. Permite comprobar que la descarga esta
             * completa sin abrirla, y que el informe no salio vacio por un filtro
             * mal puesto.
             */
            $table->integer('row_count')->nullable();

            /*
             * Los criterios de inclusion **ya traducidos**, como lista de cadenas.
             *
             * Viajan en la fila porque el fichero de NOMINA no los lleva dentro
             * (decision 5): una fila de comentario al principio del CSV rompe la
             * importacion de la herramienta de nomina. Y un informe de horas sin
             * sus criterios es una tabla de numeros que cada persona interpreta a
             * su manera — el paso 1 de `/informe-nuevo` lo pide por escrito.
             */
            $table->jsonb('criteria')->default(DB::raw("'[]'::jsonb"));

            /*
             * Hasta cuando existe el fichero. Vencido,
             * `reporting:purge-expired-exports` lo borra y marca `purged_at`.
             * Un informe con las horas de la plantilla criando polvo en el disco
             * del cliente no puede depender de que alguien se acuerde de borrarlo.
             */
            $table->timestampTz('expires_at', 6)->nullable();
            $table->timestampTz('purged_at', 6)->nullable();

            /*
             * EL ENLACE DE DESCARGA, del que aqui solo vive la huella (ADR-041).
             *
             * El token son 32 bytes aleatorios que existen en la URL y en ningun
             * sitio mas. Cada consulta del estado acuña uno nuevo e invalida el
             * anterior; la descarga lo consume y lo pone a `NULL`, que es lo que
             * convierte «un solo uso» en una propiedad de la base de datos y no en
             * una promesa del codigo.
             */
            $table->string('download_token_hash', 64)->nullable();
            $table->timestampTz('download_token_expires_at', 6)->nullable();

            /** Ultima descarga, y cuantas van. Cada una deja su asiento (RS-05). */
            $table->timestampTz('downloaded_at', 6)->nullable();
            $table->integer('download_count')->default(0);

            /*
             * Por donde se aviso: `panel` —la pantalla, que es el canal de serie y
             * siempre existe— o `mail`. Guardarlo es lo que permite responder «¿por
             * que no me llego el correo?» sin mirar los logs.
             */
            $table->timestampTz('notified_at', 6)->nullable();
            $table->string('notification_channel', 16)->nullable();

            $table->timestampsTz(6);
        });

        $this->declareClosedCatalogues();
        $this->declareCompletenessInvariants();
        $this->declareIndexes();
    }

    public function down(): void
    {
        $this->limitLockWait();

        Schema::dropIfExists('report_exports');
    }

    /**
     * Los cinco catalogos cerrados, compuestos **desde los enumerados del
     * dominio**.
     */
    private function declareClosedCatalogues(): void
    {
        $statuses = self::quotedList(ReportExportStatus::names());
        $kinds = self::quotedList(ReportExportKind::names());
        $failures = self::quotedList(ReportExportFailure::names());
        $channels = self::quotedList(ReportExportNotificationChannel::names());

        // Los formatos salen del propio catalogo de clases de informe: la union de
        // lo que cada una admite. Escribirlos a mano aqui daria una tercera lista.
        $formats = self::quotedList(array_values(array_unique(array_merge(
            ReportExportKind::Period->allows(),
            ReportExportKind::Payroll->allows(),
        ))));

        DB::statement(
            'ALTER TABLE report_exports ADD CONSTRAINT report_exports_chk_status '
            .'CHECK (status IN ('.$statuses.'))'
        );

        DB::statement(
            'ALTER TABLE report_exports ADD CONSTRAINT report_exports_chk_kind '
            .'CHECK (kind IN ('.$kinds.'))'
        );

        DB::statement(
            'ALTER TABLE report_exports ADD CONSTRAINT report_exports_chk_format '
            .'CHECK (format IN ('.$formats.'))'
        );

        DB::statement(
            'ALTER TABLE report_exports ADD CONSTRAINT report_exports_chk_failure_reason '
            .'CHECK (failure_reason IS NULL OR failure_reason IN ('.$failures.'))'
        );

        DB::statement(
            'ALTER TABLE report_exports ADD CONSTRAINT report_exports_chk_notification_channel '
            .'CHECK (notification_channel IS NULL OR notification_channel IN ('.$channels.'))'
        );

        /*
         * LA NOMINA NO SALE EN PDF (decision 5). Un programa de nomina no importa
         * un PDF, y ofrecerlo produciria descargas inservibles cuyo unico
         * desenlace es una llamada de soporte. Se declara aqui ademas de en
         * `ReportExportKind::allows()` para que no pueda entrar por ninguna otra
         * via.
         */
        DB::statement(
            'ALTER TABLE report_exports ADD CONSTRAINT report_exports_chk_payroll_format '
            .'CHECK (kind <> '.self::quotedList([ReportExportKind::Payroll->value])
            .' OR format IN ('.self::quotedList(ReportExportKind::Payroll->allows()).'))'
        );
    }

    /**
     * Una exportacion terminada tiene fichero, huella, tamaño, recuento y
     * caducidad.
     *
     * Sin esto, un fallo a medias podria dejar una fila `completed` que el panel
     * ofrece descargar y que devuelve `404`, o una sin `expires_at` que la purga
     * no mirara nunca — un fichero con las horas de la plantilla inmortal en el
     * disco del cliente.
     */
    private function declareCompletenessInvariants(): void
    {
        DB::statement(<<<'SQL'
            ALTER TABLE report_exports
                ADD CONSTRAINT report_exports_chk_completed_is_complete
                CHECK (
                    status <> 'completed'
                    OR (
                        file_name IS NOT NULL
                        AND sha256 IS NOT NULL
                        AND size_bytes IS NOT NULL
                        AND row_count IS NOT NULL
                        AND completed_at IS NOT NULL
                        AND expires_at IS NOT NULL
                    )
                )
            SQL);

        /*
         * LA MINIMIZACION AL PURGAR, DECLARADA EN EL ESQUEMA (RL-11).
         *
         * Mientras la fila tiene fichero, `scope` es obligatorio: sin el no se
         * puede explicar por que el fichero contiene lo que contiene. En cuanto se
         * purga, los tres campos que señalan a personas —el alcance y los dos
         * filtros de `parameters`— **tienen que estar vacios**.
         *
         * Se declara aqui y no solo en `ReportExport::purge()` porque es una
         * promesa de proteccion de datos y no una comodidad: un camino nuevo que
         * marcara `purged` sin minimizar dejaria datos personales conservados sin
         * plazo, y nadie lo notaria. Con el `CHECK`, ese camino no existe.
         */
        DB::statement(<<<'SQL'
            ALTER TABLE report_exports
                ADD CONSTRAINT report_exports_chk_purged_is_minimised
                CHECK (
                    CASE WHEN status = 'purged'
                        THEN scope IS NULL
                            AND parameters->>'employee_uuid' IS NULL
                            AND parameters->>'department_id' IS NULL
                        ELSE scope IS NOT NULL
                    END
                )
            SQL);

        /*
         * Un enlace vivo tiene las dos mitades: huella y caducidad. Una huella sin
         * fecha seria un enlace que no caduca nunca, que es exactamente lo que
         * ADR-041 existe para impedir; una fecha sin huella, un enlace que no
         * abre nada.
         */
        DB::statement(<<<'SQL'
            ALTER TABLE report_exports
                ADD CONSTRAINT report_exports_chk_download_link_is_whole
                CHECK (
                    (download_token_hash IS NULL AND download_token_expires_at IS NULL)
                    OR (download_token_hash IS NOT NULL AND download_token_expires_at IS NOT NULL)
                )
            SQL);
    }

    private function declareIndexes(): void
    {
        /*
         * UNA SOLA EN CURSO POR SOLICITANTE. Indice unico parcial: las filas
         * terminadas, fallidas y purgadas no entran, asi que se acumulan sin
         * estorbar. Ver la invariante 2 del docblock.
         */
        DB::statement(<<<'SQL'
            CREATE UNIQUE INDEX report_exports_single_in_progress_uidx
                ON report_exports (requested_by_user_id)
                WHERE status IN ('pending', 'running')
            SQL);

        // El listado: las 20 mas recientes DE QUIEN PREGUNTA, de la mas nueva a la
        // mas antigua. La columna del dueño va primera porque es la igualdad.
        DB::statement(<<<'SQL'
            CREATE INDEX report_exports_recent_index
                ON report_exports (requested_by_user_id, requested_at DESC)
            SQL);

        /*
         * La consulta de la purga diaria: las que ya vencieron y todavia tienen
         * fichero. Parcial, porque en cuanto se purgan dejan de interesar y son,
         * con el tiempo, casi todas.
         */
        DB::statement(<<<'SQL'
            CREATE INDEX report_exports_expired_index
                ON report_exports (expires_at)
                WHERE purged_at IS NULL AND status = 'completed'
            SQL);
    }

    /**
     * `'a', 'b'` a partir de un catalogo del dominio.
     *
     * Se compone del enumerado a proposito: escribir los valores a mano aqui
     * daria dos listas que tienen que decir lo mismo, y el dia que divergieran la
     * base de datos rechazaria una fila que el codigo considera valida — un fallo
     * que solo aparece en la instalacion del cliente.
     *
     * Los valores son identificadores de un enumerado de PHP, sin comillas
     * posibles; aun asi se escapan, porque componer SQL por concatenacion
     * confiando en la forma del dato es exactamente como se escriben las
     * inyecciones que nadie ve venir.
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
