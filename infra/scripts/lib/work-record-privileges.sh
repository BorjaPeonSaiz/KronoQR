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
# Es EXACTAMENTE lo que dejan las migraciones 2026_10_13_100000 y 2026_10_13_100100.
# La provision (`2026_08_19_099000`, `GRANT ... ON ALL TABLES`) lo devuelve todo si
# alguien la vuelve a ejecutar, y ninguna migracion posterior lo notaria.

set -euo pipefail
IFS=$'\n\t'

# Migraciones cuya presencia hace exigible el estado de abajo (con una 2.2.0 como
# destino el rol conserva los privilegios que esa version usa).
# shellcheck disable=SC2034 # los usan update.sh y doctor.sh, que cargan este fichero.
readonly KQ_WORK_RECORD_MIGRATIONS="'2026_10_13_100000_restrict_application_updates_on_work_record','2026_10_13_100100_restrict_application_writes_on_compliance_profiles'"

# Privilegios de TABLA enteros: tabla:INSERT:UPDATE:DELETE (t = concedido, f = no).
# SELECT siempre se concede; TRUNCATE, REFERENCES, TRIGGER y MAINTAIN, nunca.
# shellcheck disable=SC2034 # los usan update.sh y doctor.sh, que cargan este fichero.
readonly KQ_WORK_RECORD_TABLES="shift_entries:t:f:t shift_corrections:t:f:t scan_events:t:f:t discarded_scan_reports:t:f:t incidents:t:f:t daily_totals:t:t:t compliance_profiles:f:f:f sites:f:f:t"

# Privilegios de COLUMNA con tabla sin ellos: tabla:PRIVILEGIO:col1,col2,...
# shellcheck disable=SC2034 # los usan update.sh y doctor.sh, que cargan este fichero.
readonly KQ_WORK_RECORD_COLUMNS="shift_entries:UPDATE:clocked_out_at,duration_minutes,status,clock_out_source,superseded_by_id,updated_at incidents:UPDATE:status,notified_at,resolved_at,resolved_by_user_id,resolution_note,updated_at compliance_profiles:UPDATE:name,retention_years,min_rest_hours,max_daily_hours,max_weekly_hours,break_required_after_hours,week_starts_on,holiday_calendar,updated_at,updated_by_user_id sites:INSERT:name,timezone sites:UPDATE:name,timezone"

# SQL que devuelve `ok` si el rol cumple el estado esperado, o la lista de
# diferencias `tabla:PRIVILEGIO` / `tabla.columna:PRIVILEGIO` separadas por comas.
# `has_table_privilege` mira solo el privilegio de tabla; `has_column_privilege`,
# tabla o columna, por eso las columnas se comparan con «tabla O lista».
work_record_privileges_sql() {
  local role="$1" entry tbl ins upd del priv cols col sep=""
  local IFS=$' \n\t'
  local tables_values="" columns_values=""

  for entry in ${KQ_WORK_RECORD_TABLES}; do
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
         IS DISTINCT FROM (p.whole OR EXISTS (SELECT 1 FROM c WHERE c.tbl = t.tbl AND c.col = a.attname AND c.priv = p.priv))
) AS d
SQL
}

# Las sentencias que dejan el estado esperado, tras un REVOKE ALL por tabla (que
# retira tambien los privilegios de columna) para que sobre y falte se corrijan
# igual. `down()` de las migraciones no se usa: devuelve el UPDATE de tabla.
# Lee CFG_DB_USERNAME (el nombre ya validado por quien llama).
work_record_privileges_repair_sql() {
  local role="\"${CFG_DB_USERNAME}\"" entry tbl ins upd del priv cols grants
  local IFS=$' \n\t'

  for entry in ${KQ_WORK_RECORD_TABLES}; do
    printf 'REVOKE ALL PRIVILEGES ON TABLE %s FROM %s;\n' "${entry%%:*}" "${role}"
  done
  for entry in ${KQ_WORK_RECORD_TABLES}; do
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
}
