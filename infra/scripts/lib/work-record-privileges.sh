#!/usr/bin/env bash
#
# KronoQR — privilegios esperados del rol de la aplicacion sobre el registro
# horario, `compliance_profiles` y `sites` (tarea 6.4, ADR-057 §1).
#
# NO SE EJECUTA SOLO: lo cargan update.sh (comprueba y repara tras actualizar) y
# doctor.sh (avisa de la deriva en reposo). El estado esperado vive AQUI, escrito
# una sola vez: la consulta de deriva y las sentencias de reparacion se generan
# de la misma lista, asi que no pueden divergir.
#
# Es EXACTAMENTE lo que dejan las migraciones del bloque 6 (2026_10_13_100000 y
# 2026_10_13_100100) y, desde el bloque 7, la 2026_10_14_100000 (ADR-057 §2/§3: el
# trigger y la funcion de purga `SECURITY DEFINER`, y sin DELETE de tabla para el rol).
# La provision (`2026_08_19_099000`, `GRANT ... ON ALL TABLES`) lo devuelve todo si
# alguien la vuelve a ejecutar, y ninguna migracion posterior lo notaria.

set -euo pipefail
IFS=$'\n\t'

# Migraciones cuya presencia hace exigible cada estado (con una 2.2.0 como destino el
# rol conserva los privilegios que esa version usa). Hay DOS estados esperados:
#   b6: las dos del bloque 6 aplicadas, la del bloque 7 no (DELETE en el registro, como
#       entonces; no existe en una 2.2.1 publicada, pero la logica debe ser coherente);
#   b7: ademas la de la funcion de purga (sin DELETE y con EXECUTE sobre la funcion).
# shellcheck disable=SC2034 # los usan update.sh y doctor.sh, que cargan este fichero.
readonly KQ_WORK_RECORD_MIGRATIONS_B6="'2026_10_13_100000_restrict_application_updates_on_work_record','2026_10_13_100100_restrict_application_writes_on_compliance_profiles'"
# shellcheck disable=SC2034 # los usan update.sh y doctor.sh, que cargan este fichero.
readonly KQ_WORK_RECORD_MIGRATION_PURGE="'2026_10_14_100000_work_record_trigger_and_purge_function'"
# La funcion de purga del bloque 7 (se busca por nombre: no depende de su firma).
readonly KQ_WORK_RECORD_PURGE_FUNCTION="work_record_purge_batch"
# Triggers del registro (ADR-057 par.2, tarea 7.5), tabla:nombre. Se exige que existan estos y
# que TODOS los no internos de las dos tablas esten activos (`tgenabled = 'O'`), de modo que
# los AFTER INSERT que anada una migracion posterior se vigilan sin tocar esta lista.
# shellcheck disable=SC2034 # los usan update.sh y doctor.sh, que cargan este fichero.
readonly KQ_WORK_RECORD_TRIGGERS="shift_entries:shift_entries_guard_transition shift_entries:shift_entries_superseded_is_linked incidents:incidents_guard_transition"
# shellcheck disable=SC2034 # los usan update.sh y doctor.sh, que cargan este fichero.
readonly KQ_WORK_RECORD_TRIGGER_TABLES="shift_entries incidents"

# Privilegios de TABLA enteros: tabla:INSERT:UPDATE:DELETE (t = concedido, f = no).
# SELECT siempre se concede; TRUNCATE, REFERENCES, TRIGGER y MAINTAIN, nunca.
# shellcheck disable=SC2034 # los usan update.sh y doctor.sh, que cargan este fichero.
readonly KQ_WORK_RECORD_TABLES_B6="shift_entries:t:f:t shift_corrections:t:f:t scan_events:t:f:t discarded_scan_reports:t:f:t incidents:t:f:t daily_totals:t:t:t compliance_profiles:f:f:f sites:f:f:t"
# Estado b7 (ADR-057 §3): ninguna tabla del registro admite DELETE; la retencion borra
# solo por la funcion de purga.
# shellcheck disable=SC2034 # los usan update.sh y doctor.sh, que cargan este fichero.
readonly KQ_WORK_RECORD_TABLES_B7="shift_entries:t:f:f shift_corrections:t:f:f scan_events:t:f:f discarded_scan_reports:t:f:f incidents:t:f:f daily_totals:t:t:f compliance_profiles:f:f:f sites:f:f:f"

# Privilegios de COLUMNA con tabla sin ellos: tabla:PRIVILEGIO:col1,col2,...
# shellcheck disable=SC2034 # los usan update.sh y doctor.sh, que cargan este fichero.
readonly KQ_WORK_RECORD_COLUMNS="shift_entries:UPDATE:clocked_out_at,duration_minutes,status,clock_out_source,superseded_by_id,updated_at incidents:UPDATE:status,notified_at,resolved_at,resolved_by_user_id,resolution_note,updated_at compliance_profiles:UPDATE:name,retention_years,min_rest_hours,max_daily_hours,max_weekly_hours,break_required_after_hours,week_starts_on,holiday_calendar,updated_at,updated_by_user_id sites:INSERT:name,timezone sites:UPDATE:name,timezone"

# Cuenta las migraciones del registro horario aplicadas: `<bloque6>,<bloque7>` (2,0 =
# solo el bloque 6; 2,1 = tambien el 7). Lo ejecutan update.sh y doctor.sh.
work_record_privileges_stage_sql() {
  printf "SELECT count(*) FILTER (WHERE migration IN (%s)) || ',' || count(*) FILTER (WHERE migration = %s) FROM migrations" \
    "${KQ_WORK_RECORD_MIGRATIONS_B6}" "${KQ_WORK_RECORD_MIGRATION_PURGE}"
}

# Traduce la salida de la consulta anterior en el estado exigible y lo deja en
# KQ_WORK_RECORD_STAGE (`b6` o `b7`). Devuelve 0 si hay estado que exigir; 1 si no
# (faltan migraciones del bloque 6: destino 2.2.0, el rol conserva lo que esa version
# usa); 2 si la salida no es valida o es incoherente (la del bloque 7 sin las del 6, o
# el migrador no respondio): quien llama falla cerrado.
work_record_privileges_stage() {
  KQ_WORK_RECORD_STAGE=""
  case "$1" in
  2,1) KQ_WORK_RECORD_STAGE="b7" ;;
  2,0) KQ_WORK_RECORD_STAGE="b6" ;;
  0,0 | 1,0) return 1 ;;
  *) return 2 ;;
  esac
  return 0
}

# Devuelve 0 si la deriva (salida de work_record_privileges_sql) incluye un trigger ausente
# o desactivado, o la funcion de purga sin SECURITY DEFINER / search_path. Eso NO se repara
# solo: lo hace un superusuario o una migracion rota, y reactivarlo en silencio taparia
# justo lo que hay que investigar. update.sh deshace y doctor.sh avisa.
work_record_privileges_tampered() {
  case "$1" in
  *trigger:* | *"${KQ_WORK_RECORD_PURGE_FUNCTION}:DEFINER"*) return 0 ;;
  esac
  return 1
}

# Tabla:INSERT:UPDATE:DELETE del estado exigible (b7 si aun no se ha decidido: es el
# estado de la version que se publica).
work_record_privileges_tables() {
  case "${KQ_WORK_RECORD_STAGE:-b7}" in
  b6) printf '%s' "${KQ_WORK_RECORD_TABLES_B6}" ;;
  *) printf '%s' "${KQ_WORK_RECORD_TABLES_B7}" ;;
  esac
}

# SQL que devuelve `ok` si el rol cumple el estado esperado, o la lista de
# diferencias `tabla:PRIVILEGIO` / `tabla.columna:PRIVILEGIO` separadas por comas.
# `has_table_privilege` mira solo el privilegio de tabla; `has_column_privilege`,
# tabla o columna, por eso las columnas se comparan con «tabla O lista». En el estado
# b7 se comprueba ademas la funcion de purga: el rol tiene EXECUTE y PUBLIC no
# (`funcion:EXECUTE`, `funcion:PUBLIC` o `funcion:AUSENTE`).
work_record_privileges_sql() {
  local role="$1" entry tbl ins upd del priv cols col sep=""
  local IFS=$' \n\t'
  local tables_values="" columns_values="" purge_sql="" trg_names="" trg_tables="" sep2=""

  for entry in $(work_record_privileges_tables); do
    IFS=: read -r tbl ins upd del <<<"${entry}"
    [ "${ins}" = "t" ] && ins=true || ins=false
    [ "${upd}" = "t" ] && upd=true || upd=false
    [ "${del}" = "t" ] && del=true || del=false
    tables_values="${tables_values}${sep}('${tbl}', ${ins}, ${upd}, ${del})"
    sep=","$'\n'"  "
  done
  sep=""
  for entry in ${KQ_WORK_RECORD_COLUMNS}; do
    IFS=: read -r tbl priv cols <<<"${entry}"
    for col in ${cols//,/ }; do
      columns_values="${columns_values}${sep}('${tbl}', '${col}', '${priv}')"
      sep=","$'\n'"  "
    done
  done
  for entry in ${KQ_WORK_RECORD_TRIGGERS}; do
    trg_names="${trg_names}${sep2}('${entry%%:*}', '${entry#*:}')"
    sep2=", "
  done
  sep2=""
  for entry in ${KQ_WORK_RECORD_TRIGGER_TABLES}; do
    trg_tables="${trg_tables}${sep2}'${entry}'"
    sep2=", "
  done
  if [ "${KQ_WORK_RECORD_STAGE:-b7}" = "b7" ]; then
    purge_sql="
  UNION ALL
  SELECT '${KQ_WORK_RECORD_PURGE_FUNCTION}:' || CASE
      WHEN NOT EXISTS (SELECT 1 FROM pg_proc WHERE proname = '${KQ_WORK_RECORD_PURGE_FUNCTION}' AND pronamespace = 'public'::regnamespace) THEN 'AUSENTE'
      WHEN EXISTS (SELECT 1 FROM pg_proc WHERE proname = '${KQ_WORK_RECORD_PURGE_FUNCTION}' AND pronamespace = 'public'::regnamespace
                     AND NOT has_function_privilege('${role}', oid, 'EXECUTE')) THEN 'EXECUTE'
      ELSE 'PUBLIC' END
   WHERE NOT EXISTS (SELECT 1 FROM pg_proc WHERE proname = '${KQ_WORK_RECORD_PURGE_FUNCTION}' AND pronamespace = 'public'::regnamespace)
      OR EXISTS (SELECT 1 FROM pg_proc WHERE proname = '${KQ_WORK_RECORD_PURGE_FUNCTION}' AND pronamespace = 'public'::regnamespace
                   AND NOT has_function_privilege('${role}', oid, 'EXECUTE'))
      OR EXISTS (SELECT 1 FROM pg_proc p, aclexplode(coalesce(p.proacl, acldefault('f', p.proowner))) a
                  WHERE p.proname = '${KQ_WORK_RECORD_PURGE_FUNCTION}' AND p.pronamespace = 'public'::regnamespace
                    AND a.grantee = 0 AND a.privilege_type = 'EXECUTE')"
    # Triggers (ausente / desactivado) y funcion de purga (que siga SECURITY DEFINER con su
    # search_path fijado). NUNCA se reparan solos: un trigger quitado lo quita un superusuario.
    purge_sql="${purge_sql}
  UNION ALL
  SELECT 'trigger:' || k.name || ':AUSENTE'
    FROM (VALUES ${trg_names}) AS k(tbl, name)
   WHERE NOT EXISTS (SELECT 1 FROM pg_trigger g WHERE g.tgrelid = k.tbl::regclass AND g.tgname = k.name AND NOT g.tgisinternal)
  UNION ALL
  SELECT 'trigger:' || g.tgname || ':DESACTIVADO'
    FROM pg_trigger g
   WHERE g.tgrelid IN (SELECT tn::regclass FROM unnest(ARRAY[${trg_tables}]) AS tn) AND NOT g.tgisinternal AND g.tgenabled <> 'O'
  UNION ALL
  SELECT '${KQ_WORK_RECORD_PURGE_FUNCTION}:DEFINER'
   WHERE EXISTS (SELECT 1 FROM pg_proc p WHERE p.proname = '${KQ_WORK_RECORD_PURGE_FUNCTION}' AND p.pronamespace = 'public'::regnamespace
                   AND (NOT p.prosecdef OR NOT EXISTS (SELECT 1 FROM unnest(coalesce(p.proconfig, '{}'::text[])) AS c WHERE c LIKE 'search_path=%')))"
  fi

  cat <<SQL
WITH t(tbl, ins, upd, del) AS (VALUES
  ${tables_values}),
c(tbl, col, priv) AS (VALUES
  ${columns_values})
SELECT coalesce(string_agg(d.x, ',' ORDER BY d.x), 'ok') FROM (
  SELECT t.tbl || ':' || p.priv AS x
    FROM t CROSS JOIN LATERAL (VALUES ('SELECT', true), ('INSERT', t.ins), ('UPDATE', t.upd), ('DELETE', t.del),
      ('TRUNCATE', false), ('REFERENCES', false), ('TRIGGER', false), ('MAINTAIN', false)) AS p(priv, want)
   WHERE has_table_privilege('${role}', t.tbl, p.priv) IS DISTINCT FROM p.want
  UNION ALL
  SELECT t.tbl || '.' || a.attname || ':' || p.priv
    FROM t JOIN pg_attribute a ON a.attrelid = t.tbl::regclass AND a.attnum > 0 AND NOT a.attisdropped
    CROSS JOIN LATERAL (VALUES ('INSERT', t.ins), ('UPDATE', t.upd)) AS p(priv, whole)
   WHERE has_column_privilege('${role}', a.attrelid, a.attnum, p.priv)
         IS DISTINCT FROM (p.whole OR EXISTS (SELECT 1 FROM c WHERE c.tbl = t.tbl AND c.col = a.attname AND c.priv = p.priv))${purge_sql}
) AS d
SQL
}

# Las sentencias que dejan el estado esperado, tras un REVOKE ALL por tabla (que
# retira tambien los privilegios de columna) para que sobre y falte se corrijan
# igual. `down()` de las migraciones no se usa: devuelve el UPDATE de tabla. En el
# estado b7 incluye la funcion de purga: nada para PUBLIC, EXECUTE para el rol. NUNCA
# concede DELETE de tabla en b7: sale de la misma lista que la consulta de deriva.
# Lee CFG_DB_USERNAME (el nombre ya validado por quien llama).
work_record_privileges_repair_sql() {
  local role="\"${CFG_DB_USERNAME}\"" entry tbl ins upd del priv cols grants
  local IFS=$' \n\t'

  for entry in $(work_record_privileges_tables); do
    printf 'REVOKE ALL PRIVILEGES ON TABLE %s FROM %s;\n' "${entry%%:*}" "${role}"
  done
  for entry in $(work_record_privileges_tables); do
    IFS=: read -r tbl ins upd del <<<"${entry}"
    grants="SELECT"
    [ "${ins}" = "t" ] && grants="${grants}, INSERT"
    [ "${upd}" = "t" ] && grants="${grants}, UPDATE"
    [ "${del}" = "t" ] && grants="${grants}, DELETE"
    printf 'GRANT %s ON TABLE %s TO %s;\n' "${grants}" "${tbl}" "${role}"
  done
  for entry in ${KQ_WORK_RECORD_COLUMNS}; do
    IFS=: read -r tbl priv cols <<<"${entry}"
    printf 'GRANT %s (%s) ON TABLE %s TO %s;\n' "${priv}" "${cols//,/, }" "${tbl}" "${role}"
  done
  if [ "${KQ_WORK_RECORD_STAGE:-b7}" = "b7" ]; then
    # Por nombre, sin firma. Si la funcion no existe no hace nada y la deriva persiste.
    printf '%s\n' "DO \$kq\$ DECLARE f regprocedure; BEGIN FOR f IN SELECT oid::regprocedure FROM pg_proc WHERE proname = '${KQ_WORK_RECORD_PURGE_FUNCTION}' AND pronamespace = 'public'::regnamespace LOOP EXECUTE format('REVOKE ALL ON FUNCTION %s FROM PUBLIC', f); EXECUTE format('GRANT EXECUTE ON FUNCTION %s TO %I', f, '${CFG_DB_USERNAME}'); END LOOP; END \$kq\$;"
  fi
}
