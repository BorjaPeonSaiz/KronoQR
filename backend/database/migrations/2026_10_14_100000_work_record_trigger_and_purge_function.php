<?php

declare(strict_types=1);

use App\Modules\Compliance\Infrastructure\Persistence\AuditLogSchema;
use App\Support\Database\LimitsMigrationLocks;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * El registro horario solo admite las transiciones del dominio y solo se purga
 * por una funcion que calcula su propio corte (ADR-057 §2 y §3, bloque 7 de la
 * 2.2.1; RL-02, RL-04, RS-07, RN-13, reglas duras 5, 6 y 7).
 *
 * ## Lo que cierra
 *
 * El bloque 6 (`2026_10_13_100000`) dejo a `fichaje_app` con `UPDATE` solo
 * sobre las columnas que el dominio escribe, pero un privilegio de columna no
 * distingue filas ni valores anteriores, y el `DELETE` se quedaba para la
 * purga. Con la credencial del runtime se podia reescribir la salida de un
 * tramo ya cerrado, pasar a `voided` o `superseded` uno vigente fuera de las
 * transiciones del dominio, apuntar `superseded_by_id` a cualquier fila,
 * reescribir la resolucion de una incidencia cerrada y borrar filas de las seis
 * tablas. Y, como el `UPDATE` vigilado se puede esquivar insertando, insertar
 * un tramo ya sustituido o anulado o una incidencia ya resuelta. Esta migracion
 * lo impide en el motor.
 *
 * ## 1. Transiciones de `shift_entries` (ADR-057 §2)
 *
 * `shift_entries_guard_transition`, `BEFORE UPDATE … FOR EACH ROW`. Rechaza
 * con `42501`:
 *
 * - cualquier cambio fuera de `clocked_out_at`, `duration_minutes`, `status`,
 *   `clock_out_source`, `superseded_by_id` y `updated_at`. Se compara la fila
 *   entera menos esas seis (`to_jsonb`), asi que una columna que se añada
 *   mañana nace inmutable: quien la quiera escribir desde la aplicacion
 *   cambia este *trigger* y el privilegio en la misma migracion;
 * - escribir la salida (`clocked_out_at`, `duration_minutes`,
 *   `clock_out_source`) fuera del cierre: solo de `open` a `closed` o
 *   `anomalous`, desde una salida nula y con una salida no nula; y cerrar
 *   sin hora de salida tampoco;
 * - un `status` que el dominio no produce. Admitidas: `open` → `closed`,
 *   `anomalous`, `superseded` o `voided`; `closed` o `anomalous` →
 *   `superseded` o `voided`. `superseded` y `voided` son finales. Que el estado
 *   no cambie siempre se admite: el `upsert` de `EloquentWorkDayRepository`
 *   reescribe con los mismos valores los tramos de la jornada que no cambian;
 * - `superseded_by_id` que cambie si ya tenia valor, que se fije sin que el
 *   estado sea `superseded`, o que apunte a una fila que no sea **del mismo
 *   empleado, la misma `work_date` y `version + 1`** (ADR-035). No hay columna
 *   de linaje: el esquema no sabe si esa fila «continua» este tramo o es otro
 *   tramo del mismo dia con esa version. Que dos versiones no apunten a la
 *   misma lo garantiza el indice unico `shift_entries_superseded_by_id_unique`
 *   (`2026_10_14_100200`), no un `EXISTS` aqui, que no ve la fila sin
 *   confirmar de otra transaccion.
 *
 * `shift_entries_guard_insert`, `AFTER INSERT … FOR EACH ROW`: un tramo nace
 * vigente. Rechaza con `42501` insertar una fila `superseded` o `voided`, o con
 * `superseded_by_id`; sin esto, el `UPDATE` vigilado se esquivaba insertando
 * directamente el estado final. `AFTER` y no `BEFORE`: el `upsert` de
 * `EloquentWorkDayRepository` propone `superseded` para la version retirada, que
 * ya existe, y un `BEFORE INSERT` se dispara tambien cuando `ON CONFLICT` acaba
 * en `UPDATE`. Ningun codigo de `app/` inserta una fila terminal; las semillas
 * de desarrollo (`CorrectionSeeder`) llegan al estado final por las mismas
 * transiciones que el dominio.
 *
 * Y `shift_entries_superseded_is_linked`, `CONSTRAINT TRIGGER … DEFERRABLE
 * INITIALLY DEFERRED`: al confirmar, un tramo que una sentencia de la
 * transaccion paso a `superseded` tiene que tener ya su `superseded_by_id`. No
 * puede ser inmediato porque la correccion lo escribe en dos pasos
 * (`EloquentWorkDayRepository::save`: primero retira la version antigua, luego
 * inserta la nueva y solo entonces las encadena, porque es una clave ajena). Lo
 * que impide es sacar un tramo vigente de la exportacion marcandolo
 * `superseded` sin version que lo sustituya.
 *
 * ## 2. Transiciones de `incidents`
 *
 * `incidents_guard_transition`, `BEFORE UPDATE … FOR EACH ROW`, con el mismo
 * patron: fuera de `status`, `resolved_at`, `resolved_by_user_id`,
 * `resolution_note`, `notified_at` y `updated_at` no cambia nada; `status` solo
 * pasa de `open` a `resolved` o `dismissed` (el dominio,
 * `Incident::resolvedBy()`, lanza `IncidentAlreadyClosed` sobre una cerrada);
 * las tres columnas de la resolucion solo cambian en esa transicion; y
 * `notified_at` solo pasa de nulo a un instante (`DatabaseIncidentNotices`
 * sella el primer aviso y nunca lo vuelve a tocar).
 *
 * `incidents_guard_insert`, `AFTER INSERT … FOR EACH ROW`: una incidencia nace
 * `open`, sin resolucion (`resolved_at`, `resolved_by_user_id`,
 * `resolution_note`) y sin aviso (`notified_at`), que es como la inserta
 * `DatabaseIncidentLedger`. `AFTER` por lo mismo que en `shift_entries`.
 *
 * Las claves `assigned_to_user_id` y `resolved_by_user_id` hacia `users` eran
 * `ON DELETE SET NULL`, y esa accion referencial es un `UPDATE` que este
 * *trigger* rechaza con un `42501` que no habla de la cuenta. Pasan a
 * `RESTRICT` en `2026_10_14_100100` (`23503` honesto, RN-13).
 *
 * ## 3. La purga por funcion (ADR-057 §3)
 *
 * `public.work_record_purge_batch(p_cutoff date, p_batch integer)`, `SECURITY
 * DEFINER`, propiedad de este rol (el de migracion) y con `EXECUTE` solo para
 * `fichaje_app`. Vacia lo vencido de cada tabla en el orden de hijo a padre que
 * ya seguia `DatabaseWorkRecordArchive::purge`, en sentencias de `p_batch` filas
 * como mucho, y devuelve una fila por tabla con lo borrado. `p_batch` acota cada
 * sentencia, no la llamada: la primera version borraba un lote por tabla y por
 * llamada, y repetir en cada llamada las consultas de las tablas ya vacias la
 * hacia el doble de lenta que los `DELETE` de antes con un año de 500 personas
 * (750 000 filas). La transaccion es la misma en los dos casos: la del asiento.
 * Antes de borrar nada:
 *
 * - **calcula su corte maximo**: la fecha UTC del servidor de base de datos
 *   menos los años efectivos, con la misma aritmetica que
 *   `RetentionPolicy::workRecordCutoff()` (un 29 de febrero menos un año es el
 *   1 de marzo, como en PHP). Los años efectivos son, en cada centro, el mayor
 *   entre `retention_years` de su perfil (o del perfil por defecto si no tiene)
 *   y el **suelo legal de su jurisdiccion**; y entre centros, el mayor. Sin
 *   centro, el perfil por defecto. Sin perfil, no purga;
 * - **rechaza un corte posterior** con `42501`;
 * - `search_path = pg_catalog, pg_temp`, todo calificado con `public.`, y
 *   `lock_timeout` propio.
 *
 * ### El suelo legal en SQL
 *
 * El catalogo vive en `config/compliance.php` (`retention_floors`), que la
 * funcion no puede leer, y no tiene variable de entorno a proposito (ADR-057
 * §5). Aqui se escribe como `CASE` en la funcion inmutable
 * `public.work_record_retention_floor(text)`, desde {@see self::RETENTION_FLOORS}
 * y {@see self::DEFAULT_RETENTION_FLOOR}. Es el mismo patron que el `CHECK` del
 * bloque 5: el dato esta en dos sitios y una prueba los ata
 * (`WorkRecordPurgeFunctionTest`: recorre el catalogo y compara cada pais, una
 * subdivision y uno que no esta). Añadir un pais al catalogo sin una migracion
 * que reescriba esta funcion la pone en rojo. No es una tabla porque una tabla
 * que la aplicacion pueda leer tampoco la puede escribir nadie mas que el rol de
 * migracion, igual que la funcion, y añadiria una relacion que vaciar en cada
 * `CommittedDatabase` sin ganar nada.
 *
 * ## 4. Sin `DELETE` para la aplicacion
 *
 * Con la purga dentro de la funcion, `fichaje_app` pierde `DELETE` sobre
 * `shift_entries`, `shift_corrections`, `scan_events`,
 * `discarded_scan_reports`, `incidents` y `daily_totals`, y tambien sobre
 * `sites` (ningun codigo de la aplicacion borra un centro; revision del bloque
 * 6). Es el conjunto que `update.sh` exige desde esta migracion
 * (`infra/scripts/lib/work-record-privileges.sh`).
 *
 * ## Lo que esto no impide
 *
 * Lo mismo que ADR-057, «Lo que esto no impide, con §1–§3 en vigor». Con la
 * credencial del runtime todavia se puede:
 *
 * - **Insertar un tramo vigente falso o cerrar uno abierto con una hora
 *   falsa**: son las escrituras del fichaje. Lo detecta la conciliacion de §4.
 *   Un tramo ya **no** se puede insertar directamente `superseded` o `voided`
 *   ni con `superseded_by_id`, ni una incidencia ya resuelta o avisada.
 * - **Anular un tramo vigente** (`open`, `closed` o `anomalous` → `voided`) o
 *   sustituirlo por una version siguiente inventada: son transiciones del
 *   dominio (`WorkDay::voidEntry`, `correctEntry`) y el motor no sabe quien las
 *   pide. La conciliacion compara estado y `superseded_by` con el ultimo
 *   asiento `shift_entry.*`, salvo que vaya con asientos falsificados.
 * - **Cerrar una incidencia abierta con una resolucion falsa**: es la
 *   transicion legitima. La conciliacion no cubre `incidents`; queda la
 *   ausencia del asiento `incident.resolved` que la acompañaria.
 * - **Bajar `retention_years` hasta el suelo y purgar dentro de el.**
 * - **Purgar antes de tiempo con el reloj del servidor de base de datos
 *   adelantado**: la funcion confia en `now()` de PostgreSQL.
 * - **Llamar a `work_record_purge_batch` fuera de `compliance:apply-retention`**:
 *   la aplicacion tiene `EXECUTE` y la funcion no escribe asiento. Purga solo lo
 *   vencido, pero sin `retention.purge_executed`. En `shift_entries` lo ve la
 *   conciliacion de §4 como `audit_without_entry` (asientos de tramos que ya no
 *   estan); en `scan_events`, `discarded_scan_reports`, `incidents` y
 *   `daily_totals` no lo ve nadie.
 *
 * Quien tenga la credencial del rol de migracion es propietario de las tablas y
 * de los *triggers*: los puede desactivar (`ALTER TABLE … DISABLE TRIGGER`) y
 * borrar; saltarlos con `session_replication_role` exige superusuario. Esa
 * credencial no llega a ningun contenedor de runtime (ADR-042), y usarla es un
 * acto explicito, no un descuido. Una migracion futura que rellene una columna
 * nueva de `shift_entries` o `incidents` con `UPDATE` tiene que hacerlo asi.
 *
 * ## Despliegue y bloqueos
 *
 * Un solo paso. Ningun dato se lee ni se reescribe: funciones, cinco *triggers*
 * y privilegios. `CREATE TRIGGER` toma `SHARE ROW EXCLUSIVE` sobre la tabla
 * (bloquea escrituras, no lecturas) durante lo que tarda en escribir el
 * catalogo; `lock_timeout` lo hace rendirse en 3 s si una transaccion larga la
 * tuviera tomada. El codigo de esta version llama a la funcion; la anterior
 * borraba con `DELETE` y dejaria de poder purgar, pero la purga nunca es
 * automatica (RF-PR-03) y `update.sh` migra con la aplicacion parada.
 *
 * ## Reversible
 *
 * `down()` devuelve `DELETE` a las siete tablas y suelta *triggers* y
 * funciones. No toca filas.
 */
return new class extends Migration
{
    use LimitsMigrationLocks;

    /**
     * El suelo de `retention_years` por pais (ISO 3166-1 alfa-2), copia de
     * `compliance.retention_floors.jurisdictions` a la fecha de esta migracion.
     * Una prueba lo ata al catalogo.
     *
     * @var array<string, int>
     */
    private const array RETENTION_FLOORS = ['ES' => 4];

    /** `compliance.retention_floors.default`. */
    private const int DEFAULT_RETENTION_FLOOR = 1;

    /**
     * Las siete tablas sin `DELETE` para la aplicacion.
     *
     * @var list<string>
     */
    private const array NO_DELETE = [
        'shift_entries',
        'shift_corrections',
        'scan_events',
        'discarded_scan_reports',
        'incidents',
        'daily_totals',
        'sites',
    ];

    public function up(): void
    {
        $this->limitLockWait();

        $application = $this->applicationRole();

        foreach ($this->functionStatements() as $statement) {
            DB::statement($statement);
        }

        foreach ($this->triggerStatements() as $statement) {
            DB::statement($statement);
        }

        foreach ([
            'work_record_retention_floor(text)',
            'work_record_purge_max_cutoff(date, integer)',
            'work_record_purge_batch(date, integer)',
            'shift_entries_guard_transition()',
            'shift_entries_guard_insert()',
            'shift_entries_assert_superseded_is_linked()',
            'incidents_guard_transition()',
            'incidents_guard_insert()',
        ] as $function) {
            // `PUBLIC` recibe `EXECUTE` sobre toda funcion nueva si no se le
            // retira. Solo la purga se concede, y solo a la aplicacion.
            DB::statement('REVOKE ALL ON FUNCTION public.'.$function.' FROM PUBLIC');
        }

        DB::statement('GRANT EXECUTE ON FUNCTION public.work_record_purge_batch(date, integer) TO '.$application);

        foreach (self::NO_DELETE as $table) {
            DB::statement('REVOKE DELETE ON TABLE '.$table.' FROM '.$application);
        }
    }

    public function down(): void
    {
        $this->limitLockWait();

        $application = $this->applicationRole();

        foreach (self::NO_DELETE as $table) {
            DB::statement('GRANT DELETE ON TABLE '.$table.' TO '.$application);
        }

        DB::statement('DROP TRIGGER IF EXISTS incidents_guard_insert ON incidents');
        DB::statement('DROP TRIGGER IF EXISTS incidents_guard_transition ON incidents');
        DB::statement('DROP TRIGGER IF EXISTS shift_entries_superseded_is_linked ON shift_entries');
        DB::statement('DROP TRIGGER IF EXISTS shift_entries_guard_transition ON shift_entries');
        DB::statement('DROP TRIGGER IF EXISTS shift_entries_guard_insert ON shift_entries');

        DB::statement('DROP FUNCTION IF EXISTS public.incidents_guard_insert()');
        DB::statement('DROP FUNCTION IF EXISTS public.incidents_guard_transition()');
        DB::statement('DROP FUNCTION IF EXISTS public.shift_entries_assert_superseded_is_linked()');
        DB::statement('DROP FUNCTION IF EXISTS public.shift_entries_guard_transition()');
        DB::statement('DROP FUNCTION IF EXISTS public.shift_entries_guard_insert()');
        DB::statement('DROP FUNCTION IF EXISTS public.work_record_purge_batch(date, integer)');
        DB::statement('DROP FUNCTION IF EXISTS public.work_record_purge_max_cutoff(date, integer)');
        DB::statement('DROP FUNCTION IF EXISTS public.work_record_retention_floor(text)');
    }

    /**
     * @return list<string>
     */
    private function functionStatements(): array
    {
        return [
            $this->retentionFloorFunction(),
            // Lo mismo que `RetentionPolicy::workRecordCutoff()`: medianoche UTC
            // menos N años con el desbordamiento de PHP (`DateInterval`): el 29
            // de febrero de un año que no es bisiesto pasa al 1 de marzo. Con
            // `- interval 'N years'` PostgreSQL daria el 28 y rechazaria el
            // corte que la aplicacion calcula ese dia.
            <<<'SQL'
                CREATE OR REPLACE FUNCTION public.work_record_purge_max_cutoff(p_today date, p_years integer)
                RETURNS date
                LANGUAGE sql
                IMMUTABLE
                STRICT
                SET search_path = pg_catalog, pg_temp
                AS $kronoqr$
                    SELECT pg_catalog.make_date(
                               pg_catalog.date_part('year', p_today)::integer - p_years,
                               pg_catalog.date_part('month', p_today)::integer,
                               1
                           ) + (pg_catalog.date_part('day', p_today)::integer - 1)
                $kronoqr$
                SQL,
            <<<'SQL'
                CREATE OR REPLACE FUNCTION public.work_record_purge_batch(p_cutoff date, p_batch integer)
                RETURNS TABLE (dataset text, deleted bigint)
                LANGUAGE plpgsql
                SECURITY DEFINER
                SET search_path = pg_catalog, pg_temp
                SET lock_timeout = '5s'
                AS $kronoqr$
                DECLARE
                    v_today       date := (pg_catalog.now() AT TIME ZONE 'UTC')::date;
                    v_years       integer;
                    v_maximum     date;
                    v_instant     timestamptz;
                    v_count       bigint;
                BEGIN
                    IF p_cutoff IS NULL OR p_batch IS NULL OR p_batch < 1 OR p_batch > 100000 THEN
                        RAISE EXCEPTION 'work_record_purge_batch: corte % o lote % fuera de rango', p_cutoff, p_batch
                            USING ERRCODE = '22023';
                    END IF;

                    -- Los años efectivos de cada centro: su perfil, o el de por
                    -- defecto, sin bajar del suelo de su jurisdiccion. Entre
                    -- centros, el mas largo. Sin centro, el perfil por defecto.
                    SELECT max(GREATEST(p.retention_years, public.work_record_retention_floor(p.jurisdiction)))
                      INTO v_years
                      FROM (
                            SELECT COALESCE(
                                       s.compliance_profile_id,
                                       (SELECT d.id FROM public.compliance_profiles d WHERE d.is_default ORDER BY d.id LIMIT 1)
                                   ) AS profile_id
                              FROM public.sites s
                            UNION ALL
                            SELECT d.id
                              FROM public.compliance_profiles d
                             WHERE d.is_default
                               AND NOT EXISTS (SELECT 1 FROM public.sites)
                           ) effective
                      JOIN public.compliance_profiles p ON p.id = effective.profile_id;

                    IF v_years IS NULL THEN
                        RAISE EXCEPTION 'work_record_purge_batch: no hay perfil de cumplimiento con el que calcular el corte'
                            USING ERRCODE = 'P0002';
                    END IF;

                    v_maximum := public.work_record_purge_max_cutoff(v_today, v_years);

                    IF p_cutoff > v_maximum THEN
                        RAISE EXCEPTION 'work_record_purge_batch: el corte % es posterior al admisible % (% años desde el % UTC del servidor; ADR-057 §3)',
                            p_cutoff, v_maximum, v_years, v_today
                            USING ERRCODE = '42501';
                    END IF;

                    v_instant := p_cutoff::timestamp AT TIME ZONE 'UTC';

                    -- Cada tabla hasta agotarla, en sentencias de `p_batch`
                    -- filas como mucho, y de hijo a padre: las claves ajenas
                    -- son RESTRICT. Un lote que no llena `p_batch` es el ultimo.

                    -- 1. Correcciones: cuelgan del tramo (RN-13).
                    deleted := 0;
                    LOOP
                        DELETE FROM public.shift_corrections WHERE id IN (
                            SELECT sc.id
                              FROM public.shift_corrections sc
                              JOIN public.shift_entries se ON se.id = sc.shift_entry_id
                             WHERE se.work_date < p_cutoff
                             LIMIT p_batch
                        );
                        GET DIAGNOSTICS v_count = ROW_COUNT;
                        deleted := deleted + v_count;
                        EXIT WHEN v_count < p_batch;
                    END LOOP;
                    dataset := 'shift_corrections'; RETURN NEXT;

                    -- 2. Incidencias: cuelgan del tramo y de la jornada.
                    deleted := 0;
                    LOOP
                        DELETE FROM public.incidents WHERE id IN (
                            SELECT i.id
                              FROM public.incidents i
                              LEFT JOIN public.shift_entries se ON se.id = i.shift_entry_id
                             WHERE i.work_date < p_cutoff OR se.work_date < p_cutoff
                             LIMIT p_batch
                        );
                        GET DIAGNOSTICS v_count = ROW_COUNT;
                        deleted := deleted + v_count;
                        EXIT WHEN v_count < p_batch;
                    END LOOP;
                    dataset := 'incidents'; RETURN NEXT;

                    -- 3. Escaneos: los de un tramo vencido, y los que no
                    -- produjeron ninguno por LEAST(occurred_at, recorded_at).
                    deleted := 0;
                    LOOP
                        DELETE FROM public.scan_events WHERE id IN (
                            SELECT s.id
                              FROM public.scan_events s
                              LEFT JOIN public.shift_entries se ON se.id = s.shift_entry_id
                             WHERE (se.id IS NOT NULL AND se.work_date < p_cutoff)
                                OR (se.id IS NULL AND LEAST(s.occurred_at, s.recorded_at) < v_instant)
                             LIMIT p_batch
                        );
                        GET DIAGNOSTICS v_count = ROW_COUNT;
                        deleted := deleted + v_count;
                        EXIT WHEN v_count < p_batch;
                    END LOOP;
                    dataset := 'scan_events'; RETURN NEXT;

                    -- 3b. Avisos de fichaje descartado (RN-22): no cuelgan de nada.
                    deleted := 0;
                    LOOP
                        DELETE FROM public.discarded_scan_reports WHERE id IN (
                            SELECT d.id
                              FROM public.discarded_scan_reports d
                             WHERE LEAST(d.occurred_at, d.recorded_at) < v_instant
                             LIMIT p_batch
                        );
                        GET DIAGNOSTICS v_count = ROW_COUNT;
                        deleted := deleted + v_count;
                        EXIT WHEN v_count < p_batch;
                    END LOOP;
                    dataset := 'discarded_scan_reports'; RETURN NEXT;

                    -- 4. Tramos, ya sin hijos. Por generaciones: solo las
                    -- versiones a las que ya no apunta nadie, hasta que una
                    -- vuelta no borra ninguna (una generacion corta no es la
                    -- ultima).
                    deleted := 0;
                    LOOP
                        DELETE FROM public.shift_entries WHERE id IN (
                            SELECT se.id
                              FROM public.shift_entries se
                             WHERE se.work_date < p_cutoff
                               AND NOT EXISTS (
                                   SELECT 1 FROM public.shift_entries newer WHERE newer.superseded_by_id = se.id
                               )
                             LIMIT p_batch
                        );
                        GET DIAGNOSTICS v_count = ROW_COUNT;
                        deleted := deleted + v_count;
                        EXIT WHEN v_count = 0;
                    END LOOP;
                    dataset := 'shift_entries'; RETURN NEXT;

                    -- 5. La proyeccion, que no tiene padre.
                    deleted := 0;
                    LOOP
                        DELETE FROM public.daily_totals WHERE id IN (
                            SELECT t.id FROM public.daily_totals t WHERE t.work_date < p_cutoff LIMIT p_batch
                        );
                        GET DIAGNOSTICS v_count = ROW_COUNT;
                        deleted := deleted + v_count;
                        EXIT WHEN v_count < p_batch;
                    END LOOP;
                    dataset := 'daily_totals'; RETURN NEXT;
                END;
                $kronoqr$
                SQL,
            <<<'SQL'
                CREATE OR REPLACE FUNCTION public.shift_entries_guard_transition()
                RETURNS trigger
                LANGUAGE plpgsql
                SET search_path = pg_catalog, pg_temp
                AS $kronoqr$
                DECLARE
                    mutable text[] := ARRAY['clocked_out_at', 'duration_minutes', 'status', 'clock_out_source', 'superseded_by_id', 'updated_at'];
                BEGIN
                    IF (pg_catalog.to_jsonb(NEW) - mutable) IS DISTINCT FROM (pg_catalog.to_jsonb(OLD) - mutable) THEN
                        RAISE EXCEPTION 'shift_entries %: solo cambian la salida, el estado y el encadenamiento de un tramo (ADR-057 §2)', OLD.uuid
                            USING ERRCODE = '42501';
                    END IF;

                    IF NEW.status IS DISTINCT FROM OLD.status AND NOT (
                           (OLD.status = 'open' AND NEW.status IN ('closed', 'anomalous', 'superseded', 'voided'))
                        OR (OLD.status IN ('closed', 'anomalous') AND NEW.status IN ('superseded', 'voided'))
                    ) THEN
                        RAISE EXCEPTION 'shift_entries %: transicion % -> % no admitida (ADR-057 §2)', OLD.uuid, OLD.status, NEW.status
                            USING ERRCODE = '42501';
                    END IF;

                    IF OLD.status = 'open' AND NEW.status IN ('closed', 'anomalous') AND NEW.clocked_out_at IS NULL THEN
                        RAISE EXCEPTION 'shift_entries %: un tramo se cierra con su hora de salida (ADR-057 §2)', OLD.uuid
                            USING ERRCODE = '42501';
                    END IF;

                    IF ROW(NEW.clocked_out_at, NEW.duration_minutes, NEW.clock_out_source)
                           IS DISTINCT FROM ROW(OLD.clocked_out_at, OLD.duration_minutes, OLD.clock_out_source)
                       AND NOT (
                           OLD.status = 'open'
                           AND OLD.clocked_out_at IS NULL
                           AND NEW.clocked_out_at IS NOT NULL
                           AND NEW.status IN ('closed', 'anomalous')
                       ) THEN
                        RAISE EXCEPTION 'shift_entries %: la salida solo se escribe al cerrar un tramo abierto (ADR-057 §2)', OLD.uuid
                            USING ERRCODE = '42501';
                    END IF;

                    IF NEW.superseded_by_id IS DISTINCT FROM OLD.superseded_by_id THEN
                        IF OLD.superseded_by_id IS NOT NULL OR NEW.status IS DISTINCT FROM 'superseded' THEN
                            RAISE EXCEPTION 'shift_entries %: superseded_by_id solo se fija una vez y en un tramo sustituido (ADR-057 §2)', OLD.uuid
                                USING ERRCODE = '42501';
                        END IF;

                        -- Que no la reclame otra version lo garantiza el
                        -- indice unico `shift_entries_superseded_by_id_unique`
                        -- (migracion `2026_10_14_100200`): un `EXISTS` aqui
                        -- no ve la fila sin confirmar de otra transaccion y
                        -- dos encadenados simultaneos pasaban los dos.
                        IF NOT EXISTS (
                               SELECT 1
                                 FROM public.shift_entries replacement
                                WHERE replacement.id = NEW.superseded_by_id
                                  AND replacement.employee_id = OLD.employee_id
                                  AND replacement.work_date = OLD.work_date
                                  AND replacement.version = OLD.version + 1
                           ) THEN
                            RAISE EXCEPTION 'shift_entries %: superseded_by_id tiene que ser una fila del mismo empleado, la misma jornada y la version siguiente (ADR-035, ADR-057 §2)', OLD.uuid
                                USING ERRCODE = '42501';
                        END IF;
                    END IF;

                    RETURN NEW;
                END;
                $kronoqr$
                SQL,
            // `AFTER INSERT` y no `BEFORE`: el `upsert` de
            // `EloquentWorkDayRepository` propone `superseded` para la version
            // retirada, que ya existe, y un `BEFORE INSERT` se dispara tambien
            // en el camino de `ON CONFLICT DO UPDATE`. El `AFTER INSERT` solo
            // ve las filas que de verdad se insertaron.
            <<<'SQL'
                CREATE OR REPLACE FUNCTION public.shift_entries_guard_insert()
                RETURNS trigger
                LANGUAGE plpgsql
                SET search_path = pg_catalog, pg_temp
                AS $kronoqr$
                BEGIN
                    IF NEW.status IN ('superseded', 'voided') OR NEW.superseded_by_id IS NOT NULL THEN
                        RAISE EXCEPTION 'shift_entries %: un tramo nace vigente; se sustituye o se anula despues (ADR-057 §2)', NEW.uuid
                            USING ERRCODE = '42501';
                    END IF;

                    RETURN NULL;
                END;
                $kronoqr$
                SQL,
            <<<'SQL'
                CREATE OR REPLACE FUNCTION public.incidents_guard_insert()
                RETURNS trigger
                LANGUAGE plpgsql
                SET search_path = pg_catalog, pg_temp
                AS $kronoqr$
                BEGIN
                    IF NEW.status IS DISTINCT FROM 'open'
                       OR NEW.resolved_at IS NOT NULL
                       OR NEW.resolved_by_user_id IS NOT NULL
                       OR NEW.resolution_note IS NOT NULL
                       OR NEW.notified_at IS NOT NULL THEN
                        RAISE EXCEPTION 'incidents %: una incidencia nace abierta y sin aviso; se resuelve despues (ADR-057 §2)', NEW.id
                            USING ERRCODE = '42501';
                    END IF;

                    RETURN NULL;
                END;
                $kronoqr$
                SQL,
            <<<'SQL'
                CREATE OR REPLACE FUNCTION public.shift_entries_assert_superseded_is_linked()
                RETURNS trigger
                LANGUAGE plpgsql
                SET search_path = pg_catalog, pg_temp
                AS $kronoqr$
                BEGIN
                    -- Diferido: se relee la fila tal y como queda al confirmar, no
                    -- como la dejo la sentencia que encolo la comprobacion.
                    IF EXISTS (
                        SELECT 1
                          FROM public.shift_entries s
                         WHERE s.id = NEW.id
                           AND s.status = 'superseded'
                           AND s.superseded_by_id IS NULL
                    ) THEN
                        RAISE EXCEPTION 'shift_entries %: un tramo sustituido tiene que apuntar a su version nueva antes de confirmar (ADR-026, ADR-057 §2)', NEW.uuid
                            USING ERRCODE = '42501';
                    END IF;

                    RETURN NULL;
                END;
                $kronoqr$
                SQL,
            <<<'SQL'
                CREATE OR REPLACE FUNCTION public.incidents_guard_transition()
                RETURNS trigger
                LANGUAGE plpgsql
                SET search_path = pg_catalog, pg_temp
                AS $kronoqr$
                DECLARE
                    mutable text[] := ARRAY['status', 'resolved_at', 'resolved_by_user_id', 'resolution_note', 'notified_at', 'updated_at'];
                BEGIN
                    IF (pg_catalog.to_jsonb(NEW) - mutable) IS DISTINCT FROM (pg_catalog.to_jsonb(OLD) - mutable) THEN
                        RAISE EXCEPTION 'incidents %: solo cambian la resolucion y el aviso de una incidencia (ADR-057 §2)', OLD.id
                            USING ERRCODE = '42501';
                    END IF;

                    IF ROW(NEW.status, NEW.resolved_at, NEW.resolved_by_user_id, NEW.resolution_note)
                           IS DISTINCT FROM ROW(OLD.status, OLD.resolved_at, OLD.resolved_by_user_id, OLD.resolution_note)
                       AND NOT (OLD.status = 'open' AND NEW.status IN ('resolved', 'dismissed')) THEN
                        RAISE EXCEPTION 'incidents %: la resolucion solo se escribe al cerrar una incidencia abierta (ADR-057 §2)', OLD.id
                            USING ERRCODE = '42501';
                    END IF;

                    IF NEW.notified_at IS DISTINCT FROM OLD.notified_at AND OLD.notified_at IS NOT NULL THEN
                        RAISE EXCEPTION 'incidents %: el primer aviso no se reescribe (ADR-057 §2)', OLD.id
                            USING ERRCODE = '42501';
                    END IF;

                    RETURN NEW;
                END;
                $kronoqr$
                SQL,
        ];
    }

    /**
     * `CASE` sobre el pais (la parte antes del guion: `ES-CN` hereda de `ES`),
     * con el suelo de cada uno y `DEFAULT_RETENTION_FLOOR` para los demas.
     */
    private function retentionFloorFunction(): string
    {
        $branches = '';

        foreach (self::RETENTION_FLOORS as $country => $years) {
            // Constantes de esta clase: dos mayusculas y un entero.
            $branches .= " WHEN '".$country."' THEN ".$years;
        }

        return strtr(<<<'SQL'
            CREATE OR REPLACE FUNCTION public.work_record_retention_floor(p_jurisdiction text)
            RETURNS integer
            LANGUAGE sql
            IMMUTABLE
            SET search_path = pg_catalog, pg_temp
            AS $kronoqr$
                SELECT CASE pg_catalog.split_part(COALESCE(p_jurisdiction, ''), '-', 1):branches: ELSE :default: END
            $kronoqr$
            SQL, [
            ':branches:' => $branches,
            ':default:' => (string) self::DEFAULT_RETENTION_FLOOR,
        ]);
    }

    /**
     * @return list<string>
     */
    private function triggerStatements(): array
    {
        return [
            'DROP TRIGGER IF EXISTS shift_entries_guard_transition ON shift_entries',
            'CREATE TRIGGER shift_entries_guard_transition BEFORE UPDATE ON shift_entries '
                .'FOR EACH ROW EXECUTE FUNCTION public.shift_entries_guard_transition()',
            'DROP TRIGGER IF EXISTS shift_entries_guard_insert ON shift_entries',
            'CREATE TRIGGER shift_entries_guard_insert AFTER INSERT ON shift_entries '
                .'FOR EACH ROW EXECUTE FUNCTION public.shift_entries_guard_insert()',
            'DROP TRIGGER IF EXISTS shift_entries_superseded_is_linked ON shift_entries',
            'CREATE CONSTRAINT TRIGGER shift_entries_superseded_is_linked AFTER UPDATE ON shift_entries '
                .'DEFERRABLE INITIALLY DEFERRED FOR EACH ROW '
                ."WHEN (NEW.status = 'superseded' AND NEW.superseded_by_id IS NULL) "
                .'EXECUTE FUNCTION public.shift_entries_assert_superseded_is_linked()',
            'DROP TRIGGER IF EXISTS incidents_guard_transition ON incidents',
            'CREATE TRIGGER incidents_guard_transition BEFORE UPDATE ON incidents '
                .'FOR EACH ROW EXECUTE FUNCTION public.incidents_guard_transition()',
            'DROP TRIGGER IF EXISTS incidents_guard_insert ON incidents',
            'CREATE TRIGGER incidents_guard_insert AFTER INSERT ON incidents '
                .'FOR EACH ROW EXECUTE FUNCTION public.incidents_guard_insert()',
        ];
    }

    private function applicationRole(): string
    {
        return '"'.AuditLogSchema::applicationRole().'"';
    }
};
