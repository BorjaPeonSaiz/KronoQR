#!/usr/bin/env bash
#
# KronoQR — etapa ⑧b de la CI: Ctrl+C y camino manual de la salida 5 de update.sh,
# de extremo a extremo sobre una instalacion real (bloque 6 de la 2.3.0, tarea 6.8;
# V21-3-PL-01, V21-3-PL-02, V21-3-PL-03, V21-3-PL-04, V21-6-PL-02).
#
# NO ES UN ENTREGABLE: vive en .github/scripts/ y solo lo ejecuta la CI.
#
# POR QUE EXISTE. Las pruebas de Pest ejercitan update.sh con un `docker` falso: ven
# el orden de las ordenes, no lo que hace Docker con una senal ni lo que deja en pie
# una vuelta atras a medias. Eso solo se ve contra contenedores de verdad, y con la
# senal que manda un terminal: a TODO el grupo de procesos en primer plano, no solo
# al `bash` del actualizador (lo que hacia el escenario anterior con `pkill`).
#
# COMO SE PROVOCA EL FALLO, SIN TOCAR EL PRODUCTO. Se parte de la imagen `php` de la
# version nueva (`kronoqr/php:<nueva>-sana`) y se le anade UNA migracion que ordena la
# ultima (`2099_12_31_000000_ci_update_fault`) y, con la matriz abierta (`V *`), cae
# en la ultima version de la cadena. `docker create` + `docker cp` + `docker commit`
# y no `docker build`: setup-buildx deja un constructor cuyo almacen no ve las
# imagenes locales. Dos variantes: `fail` (lanza una excepcion al instante) y `slow`
# (pone `application_name`, duerme 120 s en PHP y despues lanza la excepcion: nunca
# queda aplicada). El fallo de la version anterior para la salida 5 es otro: su
# imagen de nginx se sustituye por una que termina al arrancar, de modo que la vuelta
# atras no consigue relanzarla sana. Los contenedores de la version anterior no se
# recrean (su imagen no cambia), asi que el `app` que tenia `artisan down` conserva el
# fichero de mantenimiento: es exactamente el 503 que el runbook describe.
#
# MODOS
#
#   chain-check
#     `update.sh --chain 2.1.0` sale con 2 y dice a que version ir PRIMERO; la cadena
#     desde la version anterior (la que esta etapa instala) sale con 0.
#
#   rollback-sigint            (escenario A, migracion que falla)
#     SIGINT al grupo de procesos TRES veces DURANTE la vuelta atras (al empezar, durante
#     la restauracion y al relanzar). Exige salida 4 (no 5), `VUELTA ATRAS COMPLETADA`,
#     que el motivo siga siendo la migracion (la senal no lo cambia: `trap '' INT`),
#     la migracion nombrada en pantalla y en el informe, `resultado: correcto`, el
#     asiento `system.restored_from_backup` con `reason=migration_failed` (si la version
#     anterior conoce el comando) y la API atendiendo (401, no 503).
#
#   migration-sigint           (escenario B, migracion lenta + doctor.sh en el mantenimiento)
#     Con la migracion lenta en marcha (el mantenimiento esta puesto): `doctor.sh` sale 6
#     con `[FALLA] ... /api/v1/auth/me): responde 503`; despues SIGINT al grupo. Exige
#     salida 4, `interrumpido por el operador (senal INT)`, la migracion en curso
#     nombrada, el informe y el asiento con `reason=interrupted`.
#
#   manual-path                (escenario C, salida 5)
#     La vuelta atras no consigue relanzar la version anterior: salida 5 con el bloque
#     `VUELTA ATRAS INCOMPLETA` y sus pasos 1, 2, 3, 3b, 4 y 4b. Se corrige la causa y se
#     EJECUTAN LAS ORDENES IMPRESAS, en orden y tal como salen. Antes del 3b, `doctor.sh`
#     sale 6 con el 503; tras el 3b el curl da 401 y esa [FALLA] desaparece. Aparte, la
#     sonda `app.maintenance_mode` de `product:doctor` de la imagen nueva, en un
#     contenedor suelto con `artisan down`: sale 2 y la nombra.
#
#   restore-images
#     Devuelve las imagenes (y la matriz) a como estaban antes de los escenarios.
#
# Uso (desde la raiz del repositorio):
#   update-faults-e2e.sh MODO VERSION_ANTERIOR VERSION_NUEVA [DIR_ANTERIOR [DIR_NUEVO]]
#
# Variables:
#   KQ_E2E_SUDO   (sudo) prefijo para docker y los ficheros de root. Vacio si ya se es root.
#
# Necesita: docker con el plugin compose, perl, curl y los paquetes ya armados.
# Codigos de salida: 0 todo comprobado · 1 algo no se cumple · 2 uso incorrecto.

set -euo pipefail
IFS=$'\n\t'

[ "$#" -ge 3 ] || {
  printf 'uso: update-faults-e2e.sh (chain-check|rollback-sigint|migration-sigint|manual-path|restore-images) VERSION_ANTERIOR VERSION_NUEVA [DIR_ANTERIOR [DIR_NUEVO]]\n' >&2
  exit 2
}

MODE="$1"
OLD="$2"
NEW="$3"
OLD_DIR="$(cd -- "${4:-paquete-anterior}" && pwd)"
NEW_DIR="$(cd -- "${5:-paquete-nuevo}" && pwd)"
REPO_ROOT="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/../.." && pwd)"
readonly MODE OLD NEW OLD_DIR NEW_DIR REPO_ROOT

declare -a SUDO=()
if [ -n "${KQ_E2E_SUDO-sudo}" ]; then
  SUDO=("${KQ_E2E_SUDO-sudo}")
fi

readonly FAULT_MIGRATION="2099_12_31_000000_ci_update_fault"
readonly SLOW_MARK="ci-migracion-lenta"
readonly REPORTS_DIR="/var/backups/fichaje/reports"
readonly LOG_DIR="/var/log/kronoqr"

STEP=""
LAUNCHER=""
EXIT_CODE=""
OUT=""
ERR=""

# shellcheck source=infra/scripts/lib/app-commands.sh
. "${REPO_ROOT}/infra/scripts/lib/app-commands.sh"

#------------------------------------------------------------------------------
# Salida
#------------------------------------------------------------------------------
note() {
  printf '== [%s] %s\n' "${STEP:-?}" "$*"
}

fail() {
  printf 'FALLO [%s]: %s\n' "${STEP:-?}" "$*" >&2
  exit 1
}

# Lo ultimo de las dos salidas del actualizador, para que el log de la CI cuente por que.
fail_with_output() {
  if [ -n "${OUT}" ] && [ -f "${OUT}" ]; then
    printf -- '--- stdout (ultimas 60 lineas) de %s\n' "${OUT}" >&2
    tail -n 60 "${OUT}" >&2 || true
  fi
  if [ -n "${ERR}" ] && [ -f "${ERR}" ]; then
    printf -- '--- stderr (ultimas 80 lineas) de %s\n' "${ERR}" >&2
    tail -n 80 "${ERR}" >&2 || true
  fi
  fail "$*"
}

#------------------------------------------------------------------------------
# Docker, base de datos y borde
#------------------------------------------------------------------------------
docker_root() {
  "${SUDO[@]}" docker "$@"
}

compose_old() {
  "${SUDO[@]}" docker compose --env-file "${OLD_DIR}/.env" -f "${OLD_DIR}/docker-compose.yml" "$@"
}

compose_new() {
  "${SUDO[@]}" docker compose --env-file "${NEW_DIR}/.env" -f "${NEW_DIR}/docker-compose.yml" "$@"
}

sql_old() {
  compose_old exec -T postgres psql -U fichaje_migrator -d fichaje -v ON_ERROR_STOP=1 -Atqc "$1"
}

# Codigo HTTP de una ruta por el borde (000 si no responde).
edge_status() {
  curl --silent --insecure --max-time 20 --output /dev/null --write-out '%{http_code}' \
    --header 'Accept: application/json' "https://127.0.0.1$1" || true
}

latest_report() {
  "${SUDO[@]}" sh -c "ls -t ${REPORTS_DIR}/update-*.log | head -n 1"
}

report_text() {
  "${SUDO[@]}" cat "$(latest_report)"
}

assert_report_has() {
  local pattern="$1" report
  report="$(report_text)"
  grep -qE -- "${pattern}" <<<"${report}" ||
    fail "el informe $(latest_report) no contiene /${pattern}/"
}

assert_no_lock() {
  local found
  found="$("${SUDO[@]}" sh -c "ls -d ${LOG_DIR}/update.lock 2>/dev/null || true")"
  [ -z "${found}" ] || fail "update.sh ha dejado el candado ${found}"
}

assert_no_secrets() {
  local -a reports=()
  mapfile -t reports < <("${SUDO[@]}" sh -c "ls ${REPORTS_DIR}/update-*.log ${LOG_DIR}/update-*.log")
  bash "${REPO_ROOT}/.github/scripts/assert-no-secrets.sh" "${OLD_DIR}/.env" "$@" "${reports[@]}"
}

# La version anterior atiende de verdad: health con su version, ready y la API de gestion en
# 401 (sin sesion). El 503 es el mantenimiento puesto.
assert_old_in_service() {
  local body status
  body="$(curl --silent --show-error --fail --insecure --max-time 20 https://127.0.0.1/api/v1/health)" ||
    fail "/api/v1/health no responde tras la vuelta atras"
  grep -qF "\"version\":\"${OLD}\"" <<<"${body}" ||
    fail "la version en pie no es la ${OLD}: ${body}"
  curl --silent --show-error --fail --insecure --max-time 20 https://127.0.0.1/api/v1/ready >/dev/null ||
    fail "/api/v1/ready no responde tras la vuelta atras"
  status="$(edge_status /api/v1/auth/me)"
  [ "${status}" = "401" ] || fail "/api/v1/auth/me responde ${status} y se esperaba 401 (503 = sigue en mantenimiento)"
}

# El asiento de la vuelta atras en audit_log, SOLO si la version que queda en pie conoce el comando
# que lo escribe (la misma pregunta y la misma funcion que update.sh y el escenario U3).
assert_rollback_audit() {
  local reason="$1" known=0 last
  kq_app_knows_command compose_old compliance:record-system-event || known=$?
  case "${known}" in
  0)
    last="$(sql_old "SELECT action || ' ' || payload::text FROM audit_log ORDER BY id DESC LIMIT 1")"
    grep -q '^system\.restored_from_backup ' <<<"${last}" ||
      fail "el ultimo asiento de audit_log no es system.restored_from_backup: ${last}"
    grep -qE "\"reason\": ?\"${reason}\"" <<<"${last}" ||
      fail "el asiento no lleva reason=${reason}: ${last}"
    note "asiento system.restored_from_backup con reason=${reason}"
    ;;
  1)
    note "la version ${OLD} no conoce compliance:record-system-event: no se exige el asiento"
    ;;
  *)
    fail "no se ha podido preguntar a la version ${OLD} por compliance:record-system-event: ${KQ_APP_COMMAND_ERROR}"
    ;;
  esac
}

#------------------------------------------------------------------------------
# Imagenes y matriz
#------------------------------------------------------------------------------
# `<imagen>-sana`: la etiqueta con la que se guarda la imagen buena. Solo se crea si no existe,
# para que un segundo escenario parta SIEMPRE de la buena y no de la rota del anterior.
ensure_sane_tag() {
  local ref="kronoqr/$1:$2"
  docker_root image inspect "${ref}-sana" >/dev/null 2>&1 || docker_root tag "${ref}" "${ref}-sana"
}

retag_sane() {
  local ref="kronoqr/$1:$2"
  if docker_root image inspect "${ref}-sana" >/dev/null 2>&1; then
    docker_root tag "${ref}-sana" "${ref}"
  fi
}

write_migration() {
  case "$1" in
  fail)
    cat <<'PHP'
<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    public function up(): void
    {
        throw new RuntimeException('Etapa 8b de la CI: migracion de prueba que falla a proposito.');
    }
};
PHP
    ;;
  slow)
    cat <<'PHP'
<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement("SET application_name = 'ci-migracion-lenta'");
        sleep(120);
        throw new RuntimeException('Etapa 8b de la CI: migracion lenta de prueba que falla a proposito.');
    }
};
PHP
    ;;
  *)
    fail "variante de migracion desconocida: $1"
    ;;
  esac
}

# La migracion de prueba tiene que caer en la ULTIMA version de la cadena. Con `V *` ya cae; si la
# matriz del paquete esta cerrada (`V frontera`), su frontera pasa a `*` en una copia de trabajo
# (la original queda en versions.txt.orig hasta `restore-images`).
open_matrix() {
  local file="${NEW_DIR}/versions.txt" line
  line="$(awk '!/^[[:space:]]*#/ && NF >= 2 { l = NR } END { print l }' "${file}")"
  [ -n "${line}" ] || fail "${file} no tiene ninguna version"
  if awk -v n="${line}" 'NR == n && $2 == "*" { found = 1 } END { exit found ? 0 : 1 }' "${file}"; then
    return 0
  fi
  [ -f "${file}.orig" ] || cp -p "${file}" "${file}.orig"
  awk -v n="${line}" 'NR == n { print $1, "*"; next } { print }' "${file}" >"${file}.tmp"
  mv -f "${file}.tmp" "${file}"
  note "matriz abierta en la ultima version para que la migracion de prueba caiga en ella"
}

inject_migration() {
  local kind="$1" tmp cid
  ensure_sane_tag php "${NEW}"
  tmp="$(mktemp -d)"
  write_migration "${kind}" >"${tmp}/${FAULT_MIGRATION}.php"
  cid="$(docker_root create "kronoqr/php:${NEW}-sana")"
  docker_root cp "${tmp}/${FAULT_MIGRATION}.php" "${cid}:/var/www/html/database/migrations/${FAULT_MIGRATION}.php"
  docker_root commit "${cid}" "kronoqr/php:${NEW}" >/dev/null
  docker_root rm "${cid}" >/dev/null
  rm -rf "${tmp}"
  open_matrix
  docker_root run --rm --entrypoint ls "kronoqr/php:${NEW}" /var/www/html/database/migrations |
    grep -qx "${FAULT_MIGRATION}.php" ||
    fail "la imagen kronoqr/php:${NEW} no lleva la migracion de prueba"
  note "kronoqr/php:${NEW} lleva la migracion de prueba (${kind}): ${FAULT_MIGRATION}"
}

restore_images() {
  retag_sane php "${NEW}"
  retag_sane nginx "${NEW}"
  retag_sane nginx "${OLD}"
  if [ -f "${NEW_DIR}/versions.txt.orig" ]; then
    mv -f "${NEW_DIR}/versions.txt.orig" "${NEW_DIR}/versions.txt"
  fi
}

#------------------------------------------------------------------------------
# update.sh en su PROPIA SESION, para mandarle la senal que manda un terminal
#------------------------------------------------------------------------------
# Un job de CI no tiene terminal, y bash deja los procesos en segundo plano con SIGINT IGNORADO
# (una disposicion ignorada al arrancar no se puede atrapar: el `trap` de update.sh no serviria).
# `perl` abre una sesion (su pid es el del grupo), devuelve SIGINT/SIGTERM/SIGHUP a su valor por
# defecto y sustituye el proceso por `sudo ./update.sh`, de modo que sudo, el bash del actualizador
# y todo lo que lance quedan en ESE grupo: `kill -INT -- -PGID` es lo que hace Ctrl+C.
launch_update() {
  OUT="$1"
  ERR="$2"
  : >"${OUT}"
  : >"${ERR}"
  (
    cd -- "${NEW_DIR}"
    exec perl -e 'use POSIX qw(setsid); setsid() or die "setsid: $!"; $SIG{INT} = "DEFAULT"; $SIG{TERM} = "DEFAULT"; $SIG{HUP} = "DEFAULT"; exec @ARGV or die "exec: $!"' \
      "${SUDO[@]}" ./update.sh --lang es >"${OUT}" 2>"${ERR}"
  ) &
  LAUNCHER=$!
  note "update.sh lanzado en su propia sesion (grupo de procesos ${LAUNCHER})"
}

launcher_alive() {
  local state
  state="$(ps -o stat= -p "${LAUNCHER}" 2>/dev/null || true)"
  case "${state}" in
  '' | Z*) return 1 ;;
  esac
  return 0
}

# Espera a que aparezca un texto en un fichero. 1 si update.sh termina antes o se acaba el plazo.
wait_for_text() {
  local file="$1" pattern="$2" budget="$3" waited=0
  while [ "${waited}" -lt "${budget}" ]; do
    if grep -qE -- "${pattern}" "${file}" 2>/dev/null; then
      return 0
    fi
    launcher_alive || break
    sleep 1
    waited=$((waited + 1))
  done
  grep -qE -- "${pattern}" "${file}" 2>/dev/null
}

send_int() {
  launcher_alive || fail_with_output "update.sh ya habia terminado cuando se iba a mandar la senal (${1})"
  note "SIGINT a TODO el grupo de procesos (${1})"
  "${SUDO[@]}" kill -INT -- "-${LAUNCHER}" || fail "no se ha podido mandar SIGINT al grupo ${LAUNCHER}"
}

finish_update() {
  local status=0
  wait "${LAUNCHER}" || status=$?
  EXIT_CODE="${status}"
  note "update.sh salio con ${EXIT_CODE}"
}

#------------------------------------------------------------------------------
# chain-check
#------------------------------------------------------------------------------
mode_chain_check() {
  STEP="chain-check"
  local sources code=0 err
  err="$(mktemp)"
  sources="$(bash "${NEW_DIR}/update.sh" --supported-sources --lang es)"
  if grep -qx '2.1.0' <<<"${sources}"; then
    note "la 2.1.0 es un origen admitido de la ${NEW}: no hay rechazo que comprobar"
    return 0
  fi
  bash "${NEW_DIR}/update.sh" --chain 2.1.0 --lang es >/dev/null 2>"${err}" || code=$?
  cat "${err}"
  [ "${code}" -eq 2 ] || fail "--chain 2.1.0 tenia que salir con 2 (origen no admitido) y salio con ${code}"
  if grep -q 'PRIMERO' "${err}"; then
    note "--chain 2.1.0 sale con 2 y dice a que version ir PRIMERO"
  elif grep -q 'no hay camino de actualizacion' "${err}"; then
    note "--chain 2.1.0 sale con 2: no hay camino publicado (no hay version intermedia que nombrar)"
  else
    fail "--chain 2.1.0 sale con 2 pero no dice a que version ir primero ni que no hay camino"
  fi
  bash "${NEW_DIR}/update.sh" --chain "${OLD}" --lang es >/dev/null ||
    fail "--chain ${OLD} (la version anterior que instala esta etapa) tenia que salir con 0"
  note "--chain ${OLD} sale con 0"
}

#------------------------------------------------------------------------------
# A · SIGINT al grupo durante la vuelta atras
#------------------------------------------------------------------------------
mode_rollback_sigint() {
  STEP="U3s-vuelta-atras"
  local users_before users_after
  users_before="$(sql_old 'SELECT count(*) FROM users')"
  inject_migration fail
  launch_update "${NEW_DIR}/salida-u3r.log" "${NEW_DIR}/err-u3r.log"

  wait_for_text "${ERR}" 'VUELTA ATRAS EN CURSO\. NO LA INTERRUMPAS' 1500 ||
    fail_with_output "la vuelta atras no ha llegado a empezar (la migracion de prueba no ha fallado)"
  send_int "al empezar la vuelta atras"

  wait_for_text "${OUT}" 'Restaurando la copia previa' 600 ||
    fail_with_output "la vuelta atras no ha llegado a la restauracion tras la primera senal"
  sleep 3
  send_int "durante la restauracion de la copia"

  wait_for_text "${OUT}" 'Relanzando la version' 900 ||
    fail_with_output "la vuelta atras no ha llegado a relanzar la version anterior tras la segunda senal"
  sleep 2
  send_int "al relanzar la version anterior"

  finish_update
  tail -n 40 "${OUT}" || true
  cat "${ERR}"

  [ "${EXIT_CODE}" -eq 4 ] ||
    fail_with_output "se esperaba salida 4 (vuelta atras completada) y fue ${EXIT_CODE}: un Ctrl+C dejo la vuelta atras a medias"
  grep -q 'VUELTA ATRAS COMPLETADA' "${ERR}" || fail_with_output "la salida de error no dice VUELTA ATRAS COMPLETADA"
  if grep -q 'VUELTA ATRAS INCOMPLETA' "${ERR}"; then
    fail_with_output "la salida de error dice VUELTA ATRAS INCOMPLETA"
  fi
  grep -q "MIGRACION QUE FALLABA (o en curso al pararse): ${FAULT_MIGRATION}" "${ERR}" ||
    fail_with_output "la pantalla no nombra la migracion que fallaba (${FAULT_MIGRATION})"
  if grep -q 'interrumpido por el operador' "${ERR}"; then
    fail_with_output "las senales DURANTE la vuelta atras se han registrado como motivo: tenian que ignorarse"
  fi
  assert_report_has "Comprobacion · failed-migration: ${FAULT_MIGRATION}"
  assert_report_has 'Vuelta atras: .*resultado: correcto'
  assert_old_in_service
  assert_rollback_audit migration_failed
  compose_old exec -T app php artisan compliance:verify-audit-chain
  users_after="$(sql_old 'SELECT count(*) FROM users')"
  [ "${users_before}" = "${users_after}" ] || fail "users paso de ${users_before} a ${users_after}"
  assert_no_lock
  assert_no_secrets "${OUT}" "${ERR}"
  note "tres SIGINT al grupo durante la vuelta atras: salida 4, nada a medias"
}

#------------------------------------------------------------------------------
# B · SIGINT al grupo durante las migraciones, y doctor.sh en el mantenimiento
#------------------------------------------------------------------------------
mode_migration_sigint() {
  STEP="U3i-migraciones"
  local waited=0 running=0 doctor_log="${NEW_DIR}/doctor-u1b.log" doctor_code=0
  inject_migration slow
  launch_update "${NEW_DIR}/salida-u3i.log" "${NEW_DIR}/err-u3i.log"

  wait_for_text "${OUT}" 'Paso 4 de 7' 1500 ||
    fail_with_output "update.sh no ha llegado al paso 4 de migraciones"
  # La migracion lenta esta en marcha cuando su sesion de PostgreSQL lleva su application_name.
  while [ "${waited}" -lt 600 ]; do
    running="$(sql_old "SELECT count(*) FROM pg_stat_activity WHERE application_name = '${SLOW_MARK}'" 2>/dev/null || echo 0)"
    running="${running:-0}"
    [ "${running}" -ge 1 ] && break
    launcher_alive || break
    sleep 2
    waited=$((waited + 2))
  done
  [ "${running}" -ge 1 ] || fail_with_output "la migracion lenta no ha llegado a ejecutarse"

  # U1b: con el mantenimiento puesto, doctor.sh lo dice. Es el estado del servidor cuando alguien
  # lo ejecuta a media actualizacion, o tras una vuelta atras que dejo el `artisan up` sin hacer.
  STEP="U1b-doctor-en-mantenimiento"
  "${SUDO[@]}" bash "${NEW_DIR}/doctor.sh" --lang es --current "${OLD_DIR}" >"${doctor_log}" 2>&1 || doctor_code=$?
  cat "${doctor_log}"
  [ "${doctor_code}" -eq 6 ] ||
    fail "doctor.sh con el mantenimiento puesto tenia que salir con 6 y salio con ${doctor_code}"
  grep -qE '\[FALLA\].*/api/v1/auth/me\): responde 503' "${doctor_log}" ||
    fail "doctor.sh no marca [FALLA] la API de gestion en 503"
  note "doctor.sh con el mantenimiento puesto: sale 6 y marca la API en 503"

  STEP="U3i-migraciones"
  send_int "durante la migracion lenta"
  finish_update
  tail -n 40 "${OUT}" || true
  cat "${ERR}"

  [ "${EXIT_CODE}" -eq 4 ] ||
    fail_with_output "se esperaba salida 4 (vuelta atras completada) y fue ${EXIT_CODE}"
  grep -q 'interrumpido por el operador (senal INT)' "${ERR}" ||
    fail_with_output "la salida de error no dice que se interrumpio por SIGINT"
  grep -q "MIGRACION QUE FALLABA (o en curso al pararse): ${FAULT_MIGRATION}" "${ERR}" ||
    fail_with_output "la pantalla no nombra la migracion en curso (${FAULT_MIGRATION})"
  grep -q 'VUELTA ATRAS COMPLETADA' "${ERR}" || fail_with_output "la salida de error no dice VUELTA ATRAS COMPLETADA"
  assert_report_has "Comprobacion · failed-migration: ${FAULT_MIGRATION}"
  assert_report_has 'Vuelta atras: .*interrumpido por el operador.*resultado: correcto'
  assert_old_in_service
  assert_rollback_audit interrupted
  compose_old exec -T app php artisan compliance:verify-audit-chain
  note "sesiones de la migracion lenta que quedan en PostgreSQL: $(sql_old "SELECT count(*) FROM pg_stat_activity WHERE application_name = '${SLOW_MARK}'")"
  assert_no_lock
  assert_no_secrets "${OUT}" "${ERR}" "${doctor_log}"
  note "SIGINT al grupo durante las migraciones: salida 4, motivo interrupted"
}

#------------------------------------------------------------------------------
# C · Salida 5 y camino manual
#------------------------------------------------------------------------------
# Las ordenes del bloque «Que hay que hacer, en este orden» de la salida 5, tal como las imprime
# update.sh: una por linea, `<paso><TAB><orden>`. La del paso 2 ocupa dos lineas (termina en `\`).
extract_orders() {
  perl -0ne '
    s/\\\n\s+/ /g;
    if (/Que hay que hacer, en este orden:\n(.*?)\nProcedimiento completo/s) {
      for my $line (split /\n/, $1) {
        next unless $line =~ /^\s+(\w+)\.\s+[^:]*:\s{2,}(.*)$/;
        my ($step, $command) = ($1, $2);
        $command =~ s/\s+\(debe .*$//;
        print "$step\t$command\n";
      }
    }' "$1"
}

run_printed_order() {
  local key="$1" command="${ORDERS[$1]}"
  command="${command//<correo>/ci-8b@kronoqr.invalid}"
  # Un operador tiene terminal; el job de la CI no. Sin -T, `compose run` intentaria reservar uno.
  command="${command/ run --rm / run --rm -T }"
  note "orden ${key}: ${command}"
  "${SUDO[@]}" bash -c "${command}" || fail "la orden ${key} impresa por update.sh ha fallado"
}

mode_manual_path() {
  STEP="U3m-salida-5"
  local doctor_code=0 key command status known
  local before="${NEW_DIR}/doctor-u3m-antes.log" after="${NEW_DIR}/doctor-u3m-despues.log"
  declare -gA ORDERS=()

  inject_migration fail
  # Que la version anterior no arranque sana tras restaurar: su nginx termina al arrancar. Un
  # contenedor ya creado conserva su imagen; es la recreacion de la vuelta atras la que la usa.
  ensure_sane_tag nginx "${OLD}"
  docker_root pull --quiet alpine:3.20 >/dev/null
  docker_root tag alpine:3.20 "kronoqr/nginx:${OLD}"

  launch_update "${NEW_DIR}/salida-u3m.log" "${NEW_DIR}/err-u3m.log"
  finish_update
  tail -n 40 "${OUT}" || true
  cat "${ERR}"
  # La causa, corregida: es lo que hace quien sigue el runbook antes de las ordenes manuales.
  retag_sane nginx "${OLD}"

  [ "${EXIT_CODE}" -eq 5 ] ||
    fail_with_output "se esperaba salida 5 (vuelta atras incompleta) y fue ${EXIT_CODE}"
  grep -q 'VUELTA ATRAS INCOMPLETA' "${ERR}" || fail_with_output "la salida de error no dice VUELTA ATRAS INCOMPLETA"
  grep -q 'Salida 5' "${ERR}" || fail_with_output "la salida de error no termina con la linea de la salida 5"
  grep -q "MIGRACION QUE FALLABA (o en curso al pararse): ${FAULT_MIGRATION}" "${ERR}" ||
    fail_with_output "la pantalla no nombra la migracion que fallaba"
  assert_report_has 'Vuelta atras: .*resultado: REQUIERE INTERVENCION'
  assert_no_lock

  while IFS=$'\t' read -r key command; do
    ORDERS["${key}"]="${command}"
  done < <(extract_orders "${ERR}")
  for key in 1 2 3 3b 4 4b; do
    [ -n "${ORDERS[$key]:-}" ] || fail_with_output "el bloque de la salida 5 no trae la orden del paso ${key}"
  done
  note "el bloque de la salida 5 trae los pasos 1, 2, 3, 3b, 4 y 4b"

  run_printed_order 1
  run_printed_order 2
  run_printed_order 3

  # La version anterior responde a las sondas pero sigue en mantenimiento: el estado que deja la
  # salida 5 si alguien se salta el `artisan up`. Es lo que doctor.sh tiene que decir.
  status=""
  for _ in $(seq 1 60); do
    status="$(edge_status /api/v1/health)"
    [ "${status}" = "200" ] && break
    sleep 2
  done
  [ "${status}" = "200" ] || fail "tras el paso 3, /api/v1/health responde ${status}"
  status="$(edge_status /api/v1/auth/me)"
  [ "${status}" = "503" ] ||
    fail "tras el paso 3 y antes del 3b, /api/v1/auth/me responde ${status} y se esperaba 503 (el mantenimiento de la version anterior tenia que seguir puesto)"
  "${SUDO[@]}" bash "${NEW_DIR}/doctor.sh" --lang es --current "${OLD_DIR}" >"${before}" 2>&1 || doctor_code=$?
  cat "${before}"
  [ "${doctor_code}" -eq 6 ] || fail "antes del 3b doctor.sh tenia que salir con 6 y salio con ${doctor_code}"
  grep -qE '\[FALLA\].*/api/v1/auth/me\): responde 503' "${before}" ||
    fail "antes del 3b doctor.sh no marca [FALLA] la API de gestion en 503"
  product_doctor_if_supported

  run_printed_order 3b
  run_printed_order 4
  status="$("${SUDO[@]}" bash -c "${ORDERS[4b]}" 2>/dev/null || true)"
  [ "${status}" = "401" ] || fail "la orden 4b impresa da '${status}' y tenia que dar 401"
  assert_old_in_service

  doctor_code=0
  "${SUDO[@]}" bash "${NEW_DIR}/doctor.sh" --lang es --current "${OLD_DIR}" >"${after}" 2>&1 || doctor_code=$?
  cat "${after}"
  if grep -qE '\[FALLA\].*/api/v1/auth/me\)' "${after}"; then
    fail "tras el 3b doctor.sh sigue marcando [FALLA] la API de gestion"
  fi
  grep -qE '\[ok\].*/api/v1/auth/me\): responde 401' "${after}" ||
    fail "tras el 3b doctor.sh no da [ok] la API de gestion en 401"

  known=0
  kq_app_knows_command compose_old compliance:record-system-event || known=$?
  if [ "${known}" -eq 0 ]; then
    compose_old exec -T app php artisan compliance:verify-audit-chain
  fi
  product_doctor_new_image
  assert_no_secrets "${OUT}" "${ERR}" "${before}" "${after}" "${NEW_DIR}/product-doctor-u3m.log" "${NEW_DIR}/product-doctor-u3m-antes.log"
  note "camino manual de la salida 5 completo: 503 antes del 3b, 401 despues"
}

# `product:doctor` de la version que esta en pie, solo si ya lleva la sonda (la 2.3.0 en adelante): con
# la anterior sin ella no hay nada que exigir y el escenario lo dice.
product_doctor_if_supported() {
  local out code=0 log="${NEW_DIR}/product-doctor-u3m-antes.log"
  out="$(compose_old exec -T app php artisan product:doctor --lang=es 2>&1)" || code=$?
  printf '%s\n' "${out}" >"${log}"
  if grep -q 'app.maintenance_mode' <<<"${out}"; then
    [ "${code}" -eq 2 ] || fail "product:doctor de la version en pie debia salir con 2 con el mantenimiento puesto y salio con ${code}"
    grep -q 'MODO MANTENIMIENTO' <<<"${out}" || fail "product:doctor no dice MODO MANTENIMIENTO"
    note "product:doctor de la version en pie sale 2 y nombra app.maintenance_mode"
  else
    note "la version ${OLD} en pie no lleva la sonda app.maintenance_mode: se comprueba con la imagen nueva, mas abajo"
  fi
}

# La sonda `app.maintenance_mode` de la imagen NUEVA, en un contenedor suelto con `artisan down` (el
# estado de mantenimiento vive en el sistema de ficheros de cada contenedor): sin el, sale sin nombrarla;
# con el, sale 2 y la nombra.
product_doctor_new_image() {
  local out log="${NEW_DIR}/product-doctor-u3m.log" before after
  out="$(compose_new run --rm --no-deps -T app sh -c \
    'php artisan product:doctor --lang=es; echo "KQ-SIN-MANTENIMIENTO=$?"; php artisan down >/dev/null 2>&1; php artisan product:doctor --lang=es; echo "KQ-CON-MANTENIMIENTO=$?"' 2>&1)" ||
    fail "no se ha podido ejecutar product:doctor en un contenedor de la imagen nueva"
  printf '%s\n' "${out}" >"${log}"
  before="$(sed -n '1,/^KQ-SIN-MANTENIMIENTO=/p' "${log}")"
  after="$(sed -n '/^KQ-SIN-MANTENIMIENTO=/,$p' "${log}")"
  if grep -q 'MODO MANTENIMIENTO' <<<"${before}"; then
    fail "product:doctor de la imagen nueva dice MODO MANTENIMIENTO sin haberlo puesto"
  fi
  grep -q 'app.maintenance_mode' <<<"${after}" ||
    fail "product:doctor de la imagen nueva no nombra app.maintenance_mode con el mantenimiento puesto"
  grep -q 'MODO MANTENIMIENTO' <<<"${after}" ||
    fail "product:doctor de la imagen nueva no dice MODO MANTENIMIENTO con el mantenimiento puesto"
  grep -qx 'KQ-CON-MANTENIMIENTO=2' <<<"${after}" ||
    fail "product:doctor de la imagen nueva no sale con 2 con el mantenimiento puesto"
  note "product:doctor de la imagen nueva: sale 2 y nombra app.maintenance_mode con el mantenimiento puesto"
}

case "${MODE}" in
chain-check) mode_chain_check ;;
rollback-sigint) mode_rollback_sigint ;;
migration-sigint) mode_migration_sigint ;;
manual-path) mode_manual_path ;;
restore-images)
  STEP="restore-images"
  restore_images
  ;;
*)
  printf 'modo desconocido: %s\n' "${MODE}" >&2
  exit 2
  ;;
esac
