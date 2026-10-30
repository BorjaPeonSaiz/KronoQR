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
# trigger y la funcion de purga `SECURITY DEFINER`, y sin DELETE de tabla para el rol) y, desde
# la 2.3.0, la 2026_10_18_120000 (RN-23, ADR-056: el trigger de `sites` y la funcion
# `SECURITY DEFINER` de la correccion de la zona) y la 2026_10_25_100000 (los dos triggers
# que exigen que la duracion de un tramo sea la de sus marcas) y la 2026_10_27_100100 (RN-19:
# la funcion de incidents_guard_transition que admite la reapertura, y UPDATE (context)) y
# la 2026_10_30_100000 con las 2026_10_30_110100 y 110200 (RN-27: los periodos de alta, su
# matriz de privilegios completa, la guarda de la ficha minimizada y la de la correccion).
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
# La zona del centro fija tambien en la base (RN-23, ADR-056, bloque 2 de la 2.3.0): el
# trigger de sites, la funcion SECURITY DEFINER de la correccion de consola y su traza
# solo-append `site_timezone_changes` (solo SELECT para el rol). Se exigen
# SOLO si su migracion esta aplicada, dentro del propio SQL (no cambia los estados b6/b7):
# una 2.2.1 como destino no los tiene.
readonly KQ_SITE_TIMEZONE_MIGRATION="'2026_10_18_120000_guard_site_timezone_in_database'"
readonly KQ_SITE_TIMEZONE_FUNCTION="site_correct_timezone"
# `zz_` para ejecutarse el ultimo de los BEFORE de `sites` (orden alfabetico).
readonly KQ_SITE_TIMEZONE_TRIGGER="zz_sites_guard_timezone"
readonly KQ_SITE_TIMEZONE_TRACE="site_timezone_changes"
# La linea base de la conciliacion (una fila, el ultimo asiento al migrar): mismo trato.
readonly KQ_SITE_TIMEZONE_BASELINE="site_timezone_audit_baseline"
# La minimizacion en sitio al vencer el plazo (ADR-060, bloque 11 de la 2.3.0): las
# funciones de lote `SECURITY DEFINER` y de candidatas, los triggers y los CHECK que
# congelan lo minimizado, y el motivo purgable de la concesion de soporte. Se exigen
# SOLO si su migracion esta aplicada, como la zona del centro.
readonly KQ_MINIMISE_MIGRATION_SUPPORT="'2026_10_21_100000_purgeable_support_grant_reason'"
readonly KQ_MINIMISE_MIGRATION_ACCOUNTS="'2026_10_21_100100_minimise_management_accounts_after_retention'"
readonly KQ_MINIMISE_MIGRATION_PROFILE="'2026_10_21_100200_add_personnel_record_retention_to_compliance_profiles'"
readonly KQ_MINIMISE_MIGRATION_PERSONNEL="'2026_10_21_100300_minimise_personnel_records_after_retention'"
# Con EXECUTE solo para el rol: de lote (DEFINER, search_path fijado) y de candidatas.
readonly KQ_MINIMISE_DEFINER_FUNCTIONS_ACCOUNTS="management_account_anonymize_batch"
readonly KQ_MINIMISE_DEFINER_FUNCTIONS_PERSONNEL="personnel_record_anonymize_batch"
readonly KQ_MINIMISE_CANDIDATES_ACCOUNTS="management_account_retention_candidates"
readonly KQ_MINIMISE_CANDIDATES_PERSONNEL="personnel_record_retention_candidates"
# Funciones de trigger: nadie tiene EXECUTE (las invoca el trigger, no un usuario).
# `effective_compliance_profile_ids` no es de trigger: solo la llaman las funciones DEFINER de lote.
readonly KQ_MINIMISE_GUARD_FUNCTIONS_ACCOUNTS="users_guard_anonymized users_stamp_deactivation effective_compliance_profile_ids"
readonly KQ_MINIMISE_GUARD_FUNCTIONS_PERSONNEL="employees_guard_anonymized employees_guard_anonymized_dependents"
# Triggers (tabla:nombre) de esas funciones; los dos de dependientes comparten funcion.
readonly KQ_MINIMISE_TRIGGERS_ACCOUNTS="users:users_guard_anonymized users:users_stamp_deactivation"
readonly KQ_MINIMISE_TRIGGERS_PERSONNEL="employees:employees_guard_anonymized employment_contracts:employment_contracts_guard_anonymized_employee absences:absences_guard_anonymized_employee"
# Tablas que pierden DELETE para el rol: se minimizan en sitio (RN-13: no se borra la ficha).
readonly KQ_MINIMISE_NO_DELETE_TABLES="employment_contracts absences employees users"
# Los periodos de alta (RN-27, ADR-062 §1 y §2, 2.3.0): el disparador de las transiciones de
# la ficha, la guarda de los periodos y la comprobacion diferida de la proyeccion. Se exigen
# SOLO si su migracion esta aplicada, como la minimizacion. Las funciones son de trigger:
# nadie tiene EXECUTE. El rol no borra periodos ni correcciones, ni reescribe correcciones.
readonly KQ_EMPLOYMENT_PERIODS_MIGRATION="'2026_10_30_100000_create_employment_periods'"
readonly KQ_EMPLOYMENT_PERIODS_GUARD_FUNCTIONS="employees_guard_employment_transition employment_periods_guard employees_assert_current_period"
readonly KQ_EMPLOYMENT_PERIODS_TRIGGERS="employees:employees_guard_employment_transition employment_periods:employment_periods_guard employees:employees_current_period_matches employment_periods:employment_periods_match_employee"
# Matriz completa de las dos tablas (ADR-062 §2, revision de la 25.3): SELECT e INSERT si;
# UPDATE de tabla, DELETE, TRUNCATE, REFERENCES, TRIGGER y MAINTAIN no. De columna, solo
# `UPDATE (terminated_on, offboarded_at)` en los periodos y ninguno en las correcciones.
# tabla:columnas con UPDATE (vacio, ninguna).
readonly KQ_EMPLOYMENT_PERIODS_TABLES="employment_periods:terminated_on,offboarded_at employment_period_corrections:"
# La ficha minimizada en el motor (2026_10_30_110100): la guarda de los periodos reutiliza
# employees_guard_anonymized_dependents; la de las correcciones es nueva. Solo con su migracion.
readonly KQ_EMPLOYMENT_PERIODS_MINIMISED_MIGRATION="'2026_10_30_110100_guard_minimised_employment_periods'"
readonly KQ_EMPLOYMENT_PERIODS_MINIMISED_FUNCTIONS="employment_period_corrections_guard_anonymized"
readonly KQ_EMPLOYMENT_PERIODS_MINIMISED_TRIGGERS="employment_periods:employment_periods_guard_anonymized_employee employment_period_corrections:employment_period_corrections_guard_anonymized"
# El cese de una baja efectiva solo con su correccion (2026_10_30_110200). Solo con su migracion.
readonly KQ_EMPLOYMENT_PERIODS_CORRECTED_MIGRATION="'2026_10_30_110200_require_correction_for_effective_termination_change'"
readonly KQ_EMPLOYMENT_PERIODS_CORRECTED_FUNCTIONS="employment_periods_assert_termination_corrected"
readonly KQ_EMPLOYMENT_PERIODS_CORRECTED_TRIGGERS="employment_periods:employment_periods_termination_corrected"

# Triggers del registro (ADR-057 par.2, tarea 7.5), tabla:nombre. Se exige que existan estos y
# que TODOS los no internos de las dos tablas esten activos (`tgenabled = 'O'`), de modo que
# los AFTER INSERT que anada una migracion posterior se vigilan sin tocar esta lista.
# shellcheck disable=SC2034 # los usan update.sh y doctor.sh, que cargan este fichero.
readonly KQ_WORK_RECORD_TRIGGERS="shift_entries:shift_entries_guard_transition shift_entries:shift_entries_superseded_is_linked shift_entries:shift_entries_guard_insert incidents:incidents_guard_transition incidents:incidents_guard_insert"
# shellcheck disable=SC2034 # los usan update.sh y doctor.sh, que cargan este fichero.
readonly KQ_WORK_RECORD_TRIGGER_TABLES="shift_entries incidents"
# La duracion de un tramo, la de sus marcas, tambien en el motor (RN-06, RN-09, ADR-057 par.2,
# 2.3.0): dos triggers AFTER de shift_entries. Se exigen SOLO si su migracion esta aplicada,
# dentro del propio SQL como la zona del centro: una 2.2.x no los tiene hasta migrar. Que esten
# activos ya lo vigila la comprobacion de todos los triggers de la tabla. Misma lista que
# ServiceInspector::WORK_RECORD_DURATION_GUARD_TRIGGERS (WorkRecordGuardListsParityTest).
readonly KQ_WORK_RECORD_DURATION_MIGRATION="'2026_10_25_100000_guard_shift_entry_duration_in_database'"
readonly KQ_WORK_RECORD_DURATION_TRIGGERS="shift_entries:shift_entries_guard_duration_insert shift_entries:shift_entries_guard_duration_close"
# El sistema reabre la rejected_pin_scan que el sistema cerro (RN-19, enmienda del 27-10-2026
# de ADR-057 par.2): la funcion de incidents_guard_transition admite resolved -> open solo
# para ese caso, y el rol recibe UPDATE (context). Se exigen SOLO si su migracion esta
# aplicada, como la duracion: una version anterior no los tiene hasta migrar. La funcion
# se reconoce por la huella de su cuerpo: md5 de `prosrc` con los blancos normalizados
# (KQ_GUARD_FUNCTION_FINGERPRINT_SQL). Normalizar solo cambia sangria, retornos de carro
# y lineas en blanco; los saltos de linea se conservan, porque un comentario `--` termina
# en ellos y quitarlos cambiaria lo que ejecuta la funcion. Cualquier otro cuerpo, aunque
# nombre `rejected_pin_scan` (uno debilitado, o un comentario con el nombre), es otro.
# Misma migracion y mismo trigger que ServiceInspector::INCIDENT_REOPENING_MIGRATION y
# ::INCIDENT_REOPENING_TRIGGER (WorkRecordGuardListsParityTest).
readonly KQ_INCIDENT_REOPENING_MIGRATION="'2026_10_27_100100_allow_system_reopening_of_rejected_pin_scan'"
readonly KQ_INCIDENT_REOPENING_TRIGGER="incidents:incidents_guard_transition"
# La huella de la funcion que crea esa migracion. Si otra migracion la cambia, se cambia aqui
# y en ServiceInspector::INCIDENT_REOPENING_FINGERPRINT (WorkRecordGuardListsParityTest, y
# WorkRecordPrivilegesDoctorCheckTest la compara con la de la base migrada).
readonly KQ_INCIDENT_REOPENING_FINGERPRINT="c9e12ae8cfdd618e18589c257c6900b9"
# La expresion de la huella sobre `p.prosrc` (pg_proc). Sin barras invertidas, para que
# viaje igual por el shell y por PHP: la misma que ServiceInspector::GUARD_FUNCTION_FINGERPRINT_SQL.
readonly KQ_GUARD_FUNCTION_FINGERPRINT_SQL="md5(btrim(regexp_replace(regexp_replace(p.prosrc, '[[:space:]]*' || chr(10) || '[[:space:]]*', chr(10), 'g'), '[[:blank:]]+', ' ', 'g'), ' ' || chr(10)))"
# Privilegios de columna que solo se exigen con esa migracion: tabla:PRIVILEGIO:col1,...
readonly KQ_INCIDENT_REOPENING_COLUMNS="incidents:UPDATE:context"

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
readonly KQ_WORK_RECORD_COLUMNS="shift_entries:UPDATE:clocked_out_at,duration_minutes,status,clock_out_source,closed_by,superseded_by_id,updated_at incidents:UPDATE:status,notified_at,resolved_at,resolved_by_user_id,resolution_note,updated_at compliance_profiles:UPDATE:name,retention_years,personnel_record_retention_years,min_rest_hours,max_daily_hours,max_weekly_hours,break_required_after_hours,week_starts_on,holiday_calendar,updated_at,updated_by_user_id sites:INSERT:name,timezone sites:UPDATE:name,timezone"

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
# o desactivado, una funcion SECURITY DEFINER (la purga o la correccion de la zona) ausente
# o sin SECURITY DEFINER / search_path, o la traza o la linea base de la zona ausentes o de
# otro propietario, o con DELETE, UPDATE o TRUNCATE para el rol: con ellos se borra o se
# reescribe un cambio de zona (un INSERT de mas, o el SELECT que falte, si se reparan). Eso NO
# se repara solo: lo hace un superusuario o una migracion rota, y reactivarlo en silencio taparia
# justo lo que hay que investigar. update.sh deshace y doctor.sh avisa.
work_record_privileges_tampered() {
  case "$1" in
  *trigger:* | *check:* | *":DEFINER"* | *":AUSENTE"* | \
    *"${KQ_SITE_TIMEZONE_TRACE}:PROPIETARIO"* | \
    *"${KQ_SITE_TIMEZONE_BASELINE}:PROPIETARIO"* | \
    *"${KQ_SITE_TIMEZONE_TRACE}:DELETE"* | *"${KQ_SITE_TIMEZONE_TRACE}:UPDATE"* | *"${KQ_SITE_TIMEZONE_TRACE}:TRUNCATE"* | \
    *"${KQ_SITE_TIMEZONE_BASELINE}:DELETE"* | *"${KQ_SITE_TIMEZONE_BASELINE}:UPDATE"* | *"${KQ_SITE_TIMEZONE_BASELINE}:TRUNCATE"*) return 0 ;;
  esac
  return 1
}

# SQL de una funcion SECURITY DEFINER con EXECUTE solo para el rol: `funcion:AUSENTE`,
# `funcion:EXECUTE` (el rol no la puede ejecutar), `funcion:PUBLIC` (PUBLIC si) y
# `funcion:DEFINER` (perdio SECURITY DEFINER o su search_path). `$3` es una condicion SQL
# que tiene que cumplirse para exigirla (`TRUE` si siempre).
work_record_definer_function_sql() {
  local role="$1" fn="$2" when="$3" definer="${4:-definer}"
  cat <<SQL
  UNION ALL
  SELECT '${fn}:' || CASE
      WHEN NOT EXISTS (SELECT 1 FROM pg_proc WHERE proname = '${fn}' AND pronamespace = 'public'::regnamespace) THEN 'AUSENTE'
      WHEN EXISTS (SELECT 1 FROM pg_proc WHERE proname = '${fn}' AND pronamespace = 'public'::regnamespace
                     AND NOT has_function_privilege('${role}', oid, 'EXECUTE')) THEN 'EXECUTE'
      ELSE 'PUBLIC' END
   WHERE (${when}) AND (
         NOT EXISTS (SELECT 1 FROM pg_proc WHERE proname = '${fn}' AND pronamespace = 'public'::regnamespace)
      OR EXISTS (SELECT 1 FROM pg_proc WHERE proname = '${fn}' AND pronamespace = 'public'::regnamespace
                   AND NOT has_function_privilege('${role}', oid, 'EXECUTE'))
      OR EXISTS (SELECT 1 FROM pg_proc p, aclexplode(coalesce(p.proacl, acldefault('f', p.proowner))) a
                  WHERE p.proname = '${fn}' AND p.pronamespace = 'public'::regnamespace
                    AND a.grantee = 0 AND a.privilege_type = 'EXECUTE'))
SQL
  if [ "${definer}" = "definer" ]; then
    cat <<SQL
  UNION ALL
  SELECT '${fn}:DEFINER'
   WHERE (${when})
     AND EXISTS (SELECT 1 FROM pg_proc p WHERE p.proname = '${fn}' AND p.pronamespace = 'public'::regnamespace
                   AND (NOT p.prosecdef OR NOT EXISTS (SELECT 1 FROM unnest(coalesce(p.proconfig, '{}'::text[])) AS c WHERE c LIKE 'search_path=%')))
SQL
  fi
}

# SQL de una tabla de la zona que la aplicacion solo lee (la traza y la linea base):
# `tabla:AUSENTE` y `tabla:PROPIETARIO` (distinto del de `sites`) son manipulacion;
# `tabla:<PRIVILEGIO>` (de mas, o SELECT que falta) es deriva que se repara. `$3` es la
# condicion SQL de que su migracion este aplicada.
site_timezone_readonly_table_sql() {
  local role="$1" tbl="$2" when="$3"
  cat <<SQL
  UNION ALL
  SELECT '${tbl}:AUSENTE'
   WHERE (${when}) AND to_regclass('public.${tbl}') IS NULL
  UNION ALL
  SELECT '${tbl}:PROPIETARIO'
   WHERE (${when}) AND to_regclass('public.${tbl}') IS NOT NULL
     AND (SELECT c.relowner FROM pg_class c WHERE c.oid = to_regclass('public.${tbl}'))
         IS DISTINCT FROM (SELECT c.relowner FROM pg_class c WHERE c.oid = 'public.sites'::regclass)
  UNION ALL
  SELECT '${tbl}:' || p.priv
    FROM (VALUES ('SELECT', true), ('INSERT', false), ('UPDATE', false), ('DELETE', false), ('TRUNCATE', false),
                 ('REFERENCES', false), ('TRIGGER', false), ('MAINTAIN', false)) AS p(priv, want)
   WHERE (${when}) AND to_regclass('public.${tbl}') IS NOT NULL
     AND has_table_privilege('${role}', to_regclass('public.${tbl}'), p.priv) IS DISTINCT FROM p.want
SQL
}

# SQL de una funcion de trigger: nadie (ni el rol, ni PUBLIC) la puede ejecutar. Ausente es
# manipulacion; ejecutable es deriva que se repara. `$3`: condicion de migracion aplicada.
minimise_guard_function_sql() {
  local role="$1" fn="$2" when="$3"
  cat <<SQL
  UNION ALL
  SELECT '${fn}:AUSENTE'
   WHERE (${when}) AND NOT EXISTS (SELECT 1 FROM pg_proc WHERE proname = '${fn}' AND pronamespace = 'public'::regnamespace)
  UNION ALL
  SELECT '${fn}:EJECUTABLE'
   WHERE (${when}) AND EXISTS (SELECT 1 FROM pg_proc WHERE proname = '${fn}' AND pronamespace = 'public'::regnamespace
                                 AND has_function_privilege('${role}', oid, 'EXECUTE'))
SQL
}

# SQL de un trigger concreto: ausente o desactivado (`trigger:<nombre>:AUSENTE|DESACTIVADO`).
# Nunca se repara solo, como los del registro. `$1` tabla, `$2` nombre, `$3` condicion.
minimise_trigger_sql() {
  local tbl="$1" name="$2" when="$3"
  cat <<SQL
  UNION ALL
  SELECT 'trigger:${name}:' || CASE WHEN g.tgname IS NULL THEN 'AUSENTE' ELSE 'DESACTIVADO' END
    FROM (SELECT 1) AS one LEFT JOIN pg_trigger g
      ON g.tgrelid = '${tbl}'::regclass AND g.tgname = '${name}' AND NOT g.tgisinternal
   WHERE (${when}) AND (g.tgname IS NULL OR g.tgenabled <> 'O')
SQL
}

# SQL de un CHECK concreto: ausente o sin validar (`check:<nombre>:AUSENTE|SIN_VALIDAR`).
# `$1` tabla, `$2` nombre, `$3` condicion. Tampoco se repara solo.
minimise_check_sql() {
  local tbl="$1" name="$2" when="$3"
  cat <<SQL
  UNION ALL
  SELECT 'check:${name}:' || CASE WHEN c.conname IS NULL THEN 'AUSENTE' ELSE 'SIN_VALIDAR' END
    FROM (SELECT 1) AS one LEFT JOIN pg_constraint c ON c.conrelid = '${tbl}'::regclass AND c.conname = '${name}'
   WHERE (${when}) AND (c.conname IS NULL OR NOT c.convalidated)
SQL
}

# Todo lo de la minimizacion en sitio (ADR-060), cada grupo con su migracion como condicion.
minimise_retention_sql() {
  local role="$1" fn tbl
  local accounts="EXISTS (SELECT 1 FROM migrations WHERE migration = ${KQ_MINIMISE_MIGRATION_ACCOUNTS})"
  local personnel="EXISTS (SELECT 1 FROM migrations WHERE migration = ${KQ_MINIMISE_MIGRATION_PERSONNEL})"
  local profile="EXISTS (SELECT 1 FROM migrations WHERE migration = ${KQ_MINIMISE_MIGRATION_PROFILE})"
  local support="EXISTS (SELECT 1 FROM migrations WHERE migration = ${KQ_MINIMISE_MIGRATION_SUPPORT})"

  work_record_definer_function_sql "${role}" "${KQ_MINIMISE_DEFINER_FUNCTIONS_ACCOUNTS}" "${accounts}"
  work_record_definer_function_sql "${role}" "${KQ_MINIMISE_DEFINER_FUNCTIONS_PERSONNEL}" "${personnel}"
  work_record_definer_function_sql "${role}" "${KQ_MINIMISE_CANDIDATES_ACCOUNTS}" "${accounts}" plain
  work_record_definer_function_sql "${role}" "${KQ_MINIMISE_CANDIDATES_PERSONNEL}" "${personnel}" plain
  for fn in ${KQ_MINIMISE_GUARD_FUNCTIONS_ACCOUNTS}; do
    minimise_guard_function_sql "${role}" "${fn}" "${accounts}"
  done
  for fn in ${KQ_MINIMISE_GUARD_FUNCTIONS_PERSONNEL}; do
    minimise_guard_function_sql "${role}" "${fn}" "${personnel}"
  done
  for fn in ${KQ_MINIMISE_TRIGGERS_ACCOUNTS}; do
    minimise_trigger_sql "${fn%%:*}" "${fn#*:}" "${accounts}"
  done
  for fn in ${KQ_MINIMISE_TRIGGERS_PERSONNEL}; do
    minimise_trigger_sql "${fn%%:*}" "${fn#*:}" "${personnel}"
  done
  minimise_check_sql users users_chk_deactivated_has_date "${accounts}"
  minimise_check_sql users users_chk_anonymized_is_minimised "${accounts}"
  minimise_check_sql employees employees_chk_anonymized_is_minimised "${personnel}"
  minimise_check_sql compliance_profiles compliance_profiles_chk_personnel_record_retention_years "${profile}"
  minimise_check_sql support_grants support_grants_chk_reason_purged "${support}"
  minimise_check_sql support_grants support_grants_chk_reason_purged_after_close "${support}"
  for tbl in ${KQ_MINIMISE_NO_DELETE_TABLES}; do
    cat <<SQL
  UNION ALL
  SELECT '${tbl}:DELETE'
   WHERE (${personnel}) AND to_regclass('public.${tbl}') IS NOT NULL
     AND has_table_privilege('${role}', to_regclass('public.${tbl}'), 'DELETE')
SQL
  done
}

# SQL de un trigger de los periodos (tabla:nombre), ausente o desactivado, con `to_regclass`:
# sin la migracion la tabla no existe, y el cast de un literal se resuelve al planificar
# aunque el WHERE sea falso. `$2` es la condicion de su migracion.
employment_periods_trigger_sql() {
  local entry="$1" when="$2"
  cat <<SQL
  UNION ALL
  SELECT 'trigger:${entry#*:}:' || CASE WHEN g.tgname IS NULL THEN 'AUSENTE' ELSE 'DESACTIVADO' END
    FROM (SELECT 1) AS one LEFT JOIN pg_trigger g
      ON g.tgrelid = to_regclass('public.${entry%%:*}') AND g.tgname = '${entry#*:}' AND NOT g.tgisinternal
   WHERE (${when}) AND (g.tgname IS NULL OR g.tgenabled <> 'O')
SQL
}

# Los periodos de alta (ADR-062 §2): funciones de trigger sin EXECUTE, sus triggers activos y
# la matriz completa de privilegios de las dos tablas (KQ_EMPLOYMENT_PERIODS_TABLES), de tabla
# y de columna. Cada grupo solo con su migracion.
employment_periods_sql() {
  local role="$1" fn entry tbl cols
  local applied="EXISTS (SELECT 1 FROM migrations WHERE migration = ${KQ_EMPLOYMENT_PERIODS_MIGRATION})"
  local minimised="EXISTS (SELECT 1 FROM migrations WHERE migration = ${KQ_EMPLOYMENT_PERIODS_MINIMISED_MIGRATION})"
  local corrected="EXISTS (SELECT 1 FROM migrations WHERE migration = ${KQ_EMPLOYMENT_PERIODS_CORRECTED_MIGRATION})"

  for fn in ${KQ_EMPLOYMENT_PERIODS_GUARD_FUNCTIONS}; do
    minimise_guard_function_sql "${role}" "${fn}" "${applied}"
  done
  for fn in ${KQ_EMPLOYMENT_PERIODS_MINIMISED_FUNCTIONS}; do
    minimise_guard_function_sql "${role}" "${fn}" "${minimised}"
  done
  for fn in ${KQ_EMPLOYMENT_PERIODS_CORRECTED_FUNCTIONS}; do
    minimise_guard_function_sql "${role}" "${fn}" "${corrected}"
  done
  for entry in ${KQ_EMPLOYMENT_PERIODS_TRIGGERS}; do
    employment_periods_trigger_sql "${entry}" "${applied}"
  done
  for entry in ${KQ_EMPLOYMENT_PERIODS_MINIMISED_TRIGGERS}; do
    employment_periods_trigger_sql "${entry}" "${minimised}"
  done
  for entry in ${KQ_EMPLOYMENT_PERIODS_CORRECTED_TRIGGERS}; do
    employment_periods_trigger_sql "${entry}" "${corrected}"
  done
  for entry in ${KQ_EMPLOYMENT_PERIODS_TABLES}; do
    tbl="${entry%%:*}"
    cols="${entry#*:}"
    cat <<SQL
  UNION ALL
  SELECT '${tbl}:' || p.priv
    FROM (VALUES ('SELECT', true), ('INSERT', true), ('UPDATE', false), ('DELETE', false), ('TRUNCATE', false),
                 ('REFERENCES', false), ('TRIGGER', false), ('MAINTAIN', false)) AS p(priv, want)
   WHERE (${applied}) AND to_regclass('public.${tbl}') IS NOT NULL
     AND has_table_privilege('${role}', to_regclass('public.${tbl}'), p.priv) IS DISTINCT FROM p.want
  UNION ALL
  SELECT '${tbl}.' || a.attname || ':UPDATE'
    FROM pg_attribute a
   WHERE (${applied}) AND a.attrelid = to_regclass('public.${tbl}') AND a.attnum > 0 AND NOT a.attisdropped
     AND has_column_privilege('${role}', a.attrelid, a.attnum, 'UPDATE')
         IS DISTINCT FROM (a.attname = ANY (string_to_array('${cols}', ',')))
SQL
  done
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
      columns_values="${columns_values}${sep}('${tbl}', '${col}', '${priv}', TRUE)"
      sep=","$'\n'"  "
    done
  done
  # Las columnas de la reapertura de RN-19: concedidas solo con su migracion aplicada.
  local reopening_applied="EXISTS (SELECT 1 FROM migrations WHERE migration = ${KQ_INCIDENT_REOPENING_MIGRATION})"
  for entry in ${KQ_INCIDENT_REOPENING_COLUMNS}; do
    IFS=: read -r tbl priv cols <<<"${entry}"
    for col in ${cols//,/ }; do
      columns_values="${columns_values}${sep}('${tbl}', '${col}', '${priv}', ${reopening_applied})"
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
    # La funcion de purga: EXECUTE solo para el rol, y que siga SECURITY DEFINER con su
    # search_path fijado.
    purge_sql="
$(work_record_definer_function_sql "${role}" "${KQ_WORK_RECORD_PURGE_FUNCTION}" TRUE)"
    # Triggers (ausente / desactivado). NUNCA se reparan solos: un trigger quitado lo
    # quita un superusuario.
    purge_sql="${purge_sql}
  UNION ALL
  SELECT 'trigger:' || k.name || ':AUSENTE'
    FROM (VALUES ${trg_names}) AS k(tbl, name)
   WHERE NOT EXISTS (SELECT 1 FROM pg_trigger g WHERE g.tgrelid = k.tbl::regclass AND g.tgname = k.name AND NOT g.tgisinternal)
  UNION ALL
  SELECT 'trigger:' || g.tgname || ':DESACTIVADO'
    FROM pg_trigger g
   WHERE g.tgrelid IN (SELECT tn::regclass FROM unnest(ARRAY[${trg_tables}]) AS tn) AND NOT g.tgisinternal AND g.tgenabled <> 'O'"
    local duration_applied="EXISTS (SELECT 1 FROM migrations WHERE migration = ${KQ_WORK_RECORD_DURATION_MIGRATION})"
    for entry in ${KQ_WORK_RECORD_DURATION_TRIGGERS}; do
      purge_sql="${purge_sql}
  UNION ALL
  SELECT 'trigger:${entry#*:}:AUSENTE'
   WHERE ${duration_applied}
     AND NOT EXISTS (SELECT 1 FROM pg_trigger g WHERE g.tgrelid = '${entry%%:*}'::regclass AND g.tgname = '${entry#*:}' AND NOT g.tgisinternal)"
    done
    # La reapertura de RN-19: con su migracion aplicada, el trigger de transiciones de
    # incidents tiene que ejecutar la funcion que crea esa migracion, con su huella. Una
    # funcion distinta (o debilitada) la ha puesto el rol de migracion a mano: es
    # manipulacion y no se repara sola.
    purge_sql="${purge_sql}
  UNION ALL
  SELECT 'trigger:${KQ_INCIDENT_REOPENING_TRIGGER#*:}:SIN_REAPERTURA'
   WHERE ${reopening_applied}
     AND EXISTS (SELECT 1 FROM pg_trigger g WHERE g.tgrelid = '${KQ_INCIDENT_REOPENING_TRIGGER%%:*}'::regclass AND g.tgname = '${KQ_INCIDENT_REOPENING_TRIGGER#*:}' AND NOT g.tgisinternal)
     AND NOT EXISTS (SELECT 1 FROM pg_trigger g JOIN pg_proc p ON p.oid = g.tgfoid
                      WHERE g.tgrelid = '${KQ_INCIDENT_REOPENING_TRIGGER%%:*}'::regclass AND g.tgname = '${KQ_INCIDENT_REOPENING_TRIGGER#*:}'
                        AND NOT g.tgisinternal AND ${KQ_GUARD_FUNCTION_FINGERPRINT_SQL} = '${KQ_INCIDENT_REOPENING_FINGERPRINT}')"
    # La zona del centro (RN-23, ADR-056): con su migracion aplicada, el trigger de
    # sites (y ninguno de sites desactivado), la funcion de la correccion de consola y su traza
    # solo-append (solo SELECT para el rol, propiedad del mismo rol que sites).
    local site_applied="EXISTS (SELECT 1 FROM migrations WHERE migration = ${KQ_SITE_TIMEZONE_MIGRATION})"
    purge_sql="${purge_sql}
  UNION ALL
  SELECT 'trigger:${KQ_SITE_TIMEZONE_TRIGGER}:AUSENTE'
   WHERE ${site_applied}
     AND NOT EXISTS (SELECT 1 FROM pg_trigger g WHERE g.tgrelid = 'sites'::regclass AND g.tgname = '${KQ_SITE_TIMEZONE_TRIGGER}' AND NOT g.tgisinternal)
  UNION ALL
  SELECT 'trigger:' || g.tgname || ':DESACTIVADO'
    FROM pg_trigger g
   WHERE ${site_applied} AND g.tgrelid = 'sites'::regclass AND NOT g.tgisinternal AND g.tgenabled <> 'O'
$(work_record_definer_function_sql "${role}" "${KQ_SITE_TIMEZONE_FUNCTION}" "${site_applied}")
$(site_timezone_readonly_table_sql "${role}" "${KQ_SITE_TIMEZONE_TRACE}" "${site_applied}")
$(site_timezone_readonly_table_sql "${role}" "${KQ_SITE_TIMEZONE_BASELINE}" "${site_applied}")
  UNION ALL
  SELECT '${KQ_SITE_TIMEZONE_TRACE}:SECUENCIA'
   WHERE ${site_applied} AND to_regclass('public.${KQ_SITE_TIMEZONE_TRACE}_id_seq') IS NOT NULL
     AND has_sequence_privilege('${role}', to_regclass('public.${KQ_SITE_TIMEZONE_TRACE}_id_seq'), 'USAGE')"
    purge_sql="${purge_sql}
$(minimise_retention_sql "${role}")"
    purge_sql="${purge_sql}
$(employment_periods_sql "${role}")"

  fi

  cat <<SQL
WITH t(tbl, ins, upd, del) AS (VALUES
  ${tables_values}),
c(tbl, col, priv, want) AS (VALUES
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
         IS DISTINCT FROM (p.whole OR EXISTS (SELECT 1 FROM c WHERE c.tbl = t.tbl AND c.col = a.attname AND c.priv = p.priv AND c.want))${purge_sql}
) AS d
SQL
}

# Las sentencias que dejan el estado esperado, tras un REVOKE ALL por tabla (que
# retira tambien los privilegios de columna) para que sobre y falte se corrijan
# igual. `down()` de las migraciones no se usa: devuelve el UPDATE de tabla. En el
# estado b7 incluye las funciones `SECURITY DEFINER` (la purga y, si existe, la correccion de la
# zona del centro): nada para PUBLIC, EXECUTE para el rol. NUNCA
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
  # Las de la reapertura de RN-19, solo con su migracion aplicada (sin ella, el REVOKE ALL
  # de arriba ya las deja retiradas).
  for entry in ${KQ_INCIDENT_REOPENING_COLUMNS}; do
    IFS=: read -r tbl priv cols <<<"${entry}"
    printf '%s\n' "DO \$kq\$ BEGIN IF EXISTS (SELECT 1 FROM migrations WHERE migration = ${KQ_INCIDENT_REOPENING_MIGRATION}) THEN EXECUTE format('GRANT ${priv} (${cols//,/, }) ON TABLE public.%I TO %I', '${tbl}', '${CFG_DB_USERNAME}'); END IF; END \$kq\$;"
  done
  if [ "${KQ_WORK_RECORD_STAGE:-b7}" = "b7" ]; then
    # Por nombre, sin firma. Si la funcion no existe no hace nada y la deriva persiste.
    printf '%s\n' "DO \$kq\$ DECLARE f regprocedure; BEGIN FOR f IN SELECT oid::regprocedure FROM pg_proc WHERE proname IN ('${KQ_WORK_RECORD_PURGE_FUNCTION}', '${KQ_SITE_TIMEZONE_FUNCTION}', '${KQ_MINIMISE_DEFINER_FUNCTIONS_ACCOUNTS}', '${KQ_MINIMISE_DEFINER_FUNCTIONS_PERSONNEL}', '${KQ_MINIMISE_CANDIDATES_ACCOUNTS}', '${KQ_MINIMISE_CANDIDATES_PERSONNEL}') AND pronamespace = 'public'::regnamespace LOOP EXECUTE format('REVOKE ALL ON FUNCTION %s FROM PUBLIC', f); EXECUTE format('GRANT EXECUTE ON FUNCTION %s TO %I', f, '${CFG_DB_USERNAME}'); END LOOP; END \$kq\$;"
    # La traza y la linea base de la zona: solo SELECT, y nada sobre la secuencia de la
    # traza. Si no existen (o no son del migrador, que es manipulacion) no se tocan.
    printf '%s\n' "DO \$kq\$ DECLARE t text; BEGIN FOREACH t IN ARRAY ARRAY['${KQ_SITE_TIMEZONE_TRACE}', '${KQ_SITE_TIMEZONE_BASELINE}'] LOOP IF to_regclass('public.' || t) IS NOT NULL THEN EXECUTE format('REVOKE ALL ON TABLE public.%I FROM %I', t, '${CFG_DB_USERNAME}'); EXECUTE format('GRANT SELECT ON TABLE public.%I TO %I', t, '${CFG_DB_USERNAME}'); END IF; END LOOP; IF to_regclass('public.${KQ_SITE_TIMEZONE_TRACE}_id_seq') IS NOT NULL THEN EXECUTE format('REVOKE ALL ON SEQUENCE public.%I FROM %I', '${KQ_SITE_TIMEZONE_TRACE}_id_seq', '${CFG_DB_USERNAME}'); END IF; END \$kq\$;"
    # La minimizacion en sitio: las funciones de trigger sin EXECUTE para nadie, y sin
    # DELETE sobre las dos tablas que se minimizan en vez de borrarse. Solo con su
    # migracion aplicada.
    local guards="" guard
    for guard in ${KQ_MINIMISE_GUARD_FUNCTIONS_ACCOUNTS} ${KQ_MINIMISE_GUARD_FUNCTIONS_PERSONNEL} ${KQ_EMPLOYMENT_PERIODS_GUARD_FUNCTIONS} ${KQ_EMPLOYMENT_PERIODS_MINIMISED_FUNCTIONS} ${KQ_EMPLOYMENT_PERIODS_CORRECTED_FUNCTIONS}; do
      guards="${guards:+${guards}, }'${guard}'"
    done
    printf '%s\n' "DO \$kq\$ DECLARE f regprocedure; BEGIN FOR f IN SELECT oid::regprocedure FROM pg_proc WHERE proname IN (${guards}) AND pronamespace = 'public'::regnamespace LOOP EXECUTE format('REVOKE ALL ON FUNCTION %s FROM PUBLIC', f); EXECUTE format('REVOKE ALL ON FUNCTION %s FROM %I', f, '${CFG_DB_USERNAME}'); END LOOP; END \$kq\$;"
    local no_delete="" tbl
    for tbl in ${KQ_MINIMISE_NO_DELETE_TABLES}; do
      no_delete="${no_delete:+${no_delete}, }'${tbl}'"
    done
    printf '%s\n' "DO \$kq\$ DECLARE t text; BEGIN IF EXISTS (SELECT 1 FROM migrations WHERE migration = ${KQ_MINIMISE_MIGRATION_PERSONNEL}) THEN FOREACH t IN ARRAY ARRAY[${no_delete}] LOOP EXECUTE format('REVOKE DELETE ON TABLE public.%I FROM %I', t, '${CFG_DB_USERNAME}'); END LOOP; END IF; END \$kq\$;"
    # Los periodos de alta (ADR-062 §2): la misma matriz que vigila employment_periods_sql,
    # de la misma lista. REVOKE ALL por tabla (retira tambien lo de columna) y despues
    # SELECT, INSERT y el UPDATE de columna de los periodos. La secuencia no se toca.
    local periods=""
    for entry in ${KQ_EMPLOYMENT_PERIODS_TABLES}; do
      tbl="${entry%%:*}"
      cols="${entry#*:}"
      periods="${periods} EXECUTE format('REVOKE ALL PRIVILEGES ON TABLE public.%I FROM %I', '${tbl}', '${CFG_DB_USERNAME}');"
      periods="${periods} EXECUTE format('GRANT SELECT, INSERT ON TABLE public.%I TO %I', '${tbl}', '${CFG_DB_USERNAME}');"
      if [ -n "${cols}" ]; then
        periods="${periods} EXECUTE format('GRANT UPDATE (${cols//,/, }) ON TABLE public.%I TO %I', '${tbl}', '${CFG_DB_USERNAME}');"
      fi
    done
    printf '%s\n' "DO \$kq\$ BEGIN IF EXISTS (SELECT 1 FROM migrations WHERE migration = ${KQ_EMPLOYMENT_PERIODS_MIGRATION}) THEN${periods} END IF; END \$kq\$;"
  fi
}
