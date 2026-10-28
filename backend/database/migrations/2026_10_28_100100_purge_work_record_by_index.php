<?php

declare(strict_types=1);

use App\Support\Database\LimitsMigrationLocks;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * `work_record_purge_batch()` borra lo vencido sin recorrer tablas enteras
 * (RL-02, ADR-057 §3, RNF-P-02; hallazgos V21-5-BE-1 y V5-BD-1 de la 2.2.1).
 *
 * ## Lo que cambia
 *
 * Solo **como** encuentra las filas; ni que borra, ni en que orden de padres a
 * hijos, ni el corte maximo, ni los privilegios:
 *
 * - **Los hijos de un tramo vencido** (correcciones, incidencias y escaneos)
 *   se borran por tandas de tramos. Los tramos vencidos se leen una vez por
 *   `shift_entries_work_date_index` y cada tanda borra sus hijos por el indice
 *   de su clave ajena. Antes los escaneos salian de un `LEFT JOIN` con un `OR`
 *   que ningun indice servia: un `Seq Scan on scan_events` en cada lote, 558 ms
 *   por lote vacio con 950 000 escaneos.
 * - **Lo que envejece por su propia fecha** (incidencias por `work_date`,
 *   escaneos sin tramo y avisos descartados por `LEAST(occurred_at,
 *   recorded_at)`, totales por `work_date`) se lee una vez por su indice
 *   (`2026_10_28_100000`) y se borra por la clave primaria en tandas de
 *   `p_batch`. Un `DELETE ... IN (SELECT ... LIMIT p_batch)` repetido volvia a
 *   leer en cada lote lo que el anterior acababa de borrar, porque dentro de
 *   la misma transaccion esas filas siguen en el indice.
 * - Los tramos siguen por generaciones, como antes: cada vuelta tiene que ver
 *   lo que borro la anterior.
 *
 * Medido con un historico de 4,5 años (951 149 escaneos, 385 310 tramos):
 * la llamada sin nada vencido baja de 573 ms a 2 ms, y la que purga siete
 * meses (52 500 tramos y sus hijos) de 9,5 s a 4,2 s, que es sobre todo la
 * comprobacion fila a fila de las claves ajenas al borrar cada tramo.
 *
 * ## Despliegue y bloqueos
 *
 * Un solo paso. `CREATE OR REPLACE FUNCTION` conserva el OID, el propietario
 * y los privilegios, y no toma bloqueo sobre ninguna tabla. Sin los indices
 * de `2026_10_28_100000` la funcion es correcta, solo mas lenta. El codigo de
 * la aplicacion no cambia de forma: la llama igual y lee lo mismo.
 *
 * ## Reversible
 *
 * `down()` devuelve la funcion de `2026_10_14_100000` tal cual: misma firma,
 * mismo resultado. `MigrationsRoundTripTest` compara el catalogo.
 */
return new class extends Migration
{
    use LimitsMigrationLocks;

    public function up(): void
    {
        $this->limitLockWait();

        DB::statement($this->indexedFunction());

        // `CREATE OR REPLACE` conserva los privilegios de la funcion, pero se
        // repite por si alguien la hubiera recreado: solo la aplicacion la
        // ejecuta (ADR-057 §3).
        DB::statement('REVOKE ALL ON FUNCTION public.work_record_purge_batch(date, integer) FROM PUBLIC');
    }

    public function down(): void
    {
        $this->limitLockWait();

        DB::statement($this->previousFunction());

        DB::statement('REVOKE ALL ON FUNCTION public.work_record_purge_batch(date, integer) FROM PUBLIC');
    }

    private function indexedFunction(): string
    {
        return <<<'SQL'
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
            v_ids         bigint[];
            v_corrections bigint := 0;
            v_incidents   bigint := 0;
            v_scans       bigint := 0;
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
            -- son RESTRICT. Lo vencido de cada tabla se lee UNA vez, por
            -- un indice, y se parte en tandas de `p_batch` identificadores
            -- que se borran por la clave primaria o por la clave ajena.
            -- Ningun lote recorre una tabla entera ni vuelve a leer lo que
            -- borro el anterior: un `DELETE ... IN (SELECT ... LIMIT)`
            -- repetido si, y con un año de atraso el planificador preferia
            -- leer `scan_events` entera.

            -- 1-3. Lo que cuelga de los tramos vencidos: correcciones
            -- (RN-13), incidencias y escaneos. Los tramos vencidos salen
            -- de `shift_entries_work_date_index` y los hijos de cada tanda,
            -- del indice de su clave ajena, en sentencias de `p_batch`
            -- filas: una tanda de tramos tiene varios escaneos por tramo.
            FOR v_ids IN
                SELECT pg_catalog.array_agg(t.id)
                  FROM (
                        SELECT se.id, (pg_catalog.row_number() OVER () - 1) / p_batch AS tanda
                          FROM public.shift_entries se
                         WHERE se.work_date < p_cutoff
                       ) t
                 GROUP BY t.tanda
            LOOP
                LOOP
                    DELETE FROM public.shift_corrections WHERE id IN (
                        SELECT sc.id FROM public.shift_corrections sc WHERE sc.shift_entry_id = ANY (v_ids) LIMIT p_batch
                    );
                    GET DIAGNOSTICS v_count = ROW_COUNT;
                    v_corrections := v_corrections + v_count;
                    EXIT WHEN v_count < p_batch;
                END LOOP;

                LOOP
                    DELETE FROM public.incidents WHERE id IN (
                        SELECT i.id FROM public.incidents i WHERE i.shift_entry_id = ANY (v_ids) LIMIT p_batch
                    );
                    GET DIAGNOSTICS v_count = ROW_COUNT;
                    v_incidents := v_incidents + v_count;
                    EXIT WHEN v_count < p_batch;
                END LOOP;

                LOOP
                    DELETE FROM public.scan_events WHERE id IN (
                        SELECT s.id FROM public.scan_events s WHERE s.shift_entry_id = ANY (v_ids) LIMIT p_batch
                    );
                    GET DIAGNOSTICS v_count = ROW_COUNT;
                    v_scans := v_scans + v_count;
                    EXIT WHEN v_count < p_batch;
                END LOOP;
            END LOOP;

            dataset := 'shift_corrections'; deleted := v_corrections; RETURN NEXT;

            -- 2. Incidencias de una jornada vencida que no colgaban de un
            -- tramo vencido, por `incidents_work_date_index`. Tambien en
            -- tandas leidas una sola vez, y borradas por su clave primaria.
            FOR v_ids IN
                SELECT pg_catalog.array_agg(t.id)
                  FROM (
                        SELECT i.id, (pg_catalog.row_number() OVER () - 1) / p_batch AS tanda
                          FROM public.incidents i
                         WHERE i.work_date < p_cutoff
                       ) t
                 GROUP BY t.tanda
            LOOP
                DELETE FROM public.incidents WHERE id = ANY (v_ids);
                GET DIAGNOSTICS v_count = ROW_COUNT;
                v_incidents := v_incidents + v_count;
            END LOOP;
            dataset := 'incidents'; deleted := v_incidents; RETURN NEXT;

            -- 3. Escaneos que no produjeron tramo, por
            -- LEAST(occurred_at, recorded_at): `scan_events_unattached_age_index`
            -- y sus estadisticas, en tandas como las de arriba.
            FOR v_ids IN
                SELECT pg_catalog.array_agg(t.id)
                  FROM (
                        SELECT s.id, (pg_catalog.row_number() OVER () - 1) / p_batch AS tanda
                          FROM public.scan_events s
                         WHERE s.shift_entry_id IS NULL
                           AND LEAST(s.occurred_at, s.recorded_at) < v_instant
                       ) t
                 GROUP BY t.tanda
            LOOP
                DELETE FROM public.scan_events WHERE id = ANY (v_ids);
                GET DIAGNOSTICS v_count = ROW_COUNT;
                v_scans := v_scans + v_count;
            END LOOP;
            dataset := 'scan_events'; deleted := v_scans; RETURN NEXT;

            -- 3b. Avisos de fichaje descartado (RN-22): no cuelgan de nada.
            -- Por `discarded_scan_reports_age_index`, en tandas.
            deleted := 0;
            FOR v_ids IN
                SELECT pg_catalog.array_agg(t.id)
                  FROM (
                        SELECT d.id, (pg_catalog.row_number() OVER () - 1) / p_batch AS tanda
                          FROM public.discarded_scan_reports d
                         WHERE LEAST(d.occurred_at, d.recorded_at) < v_instant
                       ) t
                 GROUP BY t.tanda
            LOOP
                DELETE FROM public.discarded_scan_reports WHERE id = ANY (v_ids);
                GET DIAGNOSTICS v_count = ROW_COUNT;
                deleted := deleted + v_count;
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

            -- 5. La proyeccion, que no tiene padre. En tandas.
            deleted := 0;
            FOR v_ids IN
                SELECT pg_catalog.array_agg(t.id)
                  FROM (
                        SELECT dt.id, (pg_catalog.row_number() OVER () - 1) / p_batch AS tanda
                          FROM public.daily_totals dt
                         WHERE dt.work_date < p_cutoff
                       ) t
                 GROUP BY t.tanda
            LOOP
                DELETE FROM public.daily_totals WHERE id = ANY (v_ids);
                GET DIAGNOSTICS v_count = ROW_COUNT;
                deleted := deleted + v_count;
            END LOOP;
            dataset := 'daily_totals'; RETURN NEXT;
        END;
        $kronoqr$
        SQL;
    }

    /** La de `2026_10_14_100000`, tal cual. */
    private function previousFunction(): string
    {
        return <<<'SQL'
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
        SQL;
    }
};
