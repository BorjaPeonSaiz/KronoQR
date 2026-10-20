#!/usr/bin/env bash
#
# KronoQR — etapa ⑧b de la CI: Ctrl+C, salida 5 y camino manual de update.sh, de
# extremo a extremo sobre una instalacion real (bloque 6 de la 2.3.0, tarea 6.8;
# V21-3-PL-01, V21-3-PL-02, V21-3-PL-03, V21-3-PL-04, V21-6-PL-02, y la decision del
# propietario del 20-10-2026: despues del 401 de la version nueva no se vuelve atras).
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
# version nueva (`kronoqr/php:<nueva>-sana`) y se le cambia UNA cosa. `docker create` +
# `docker cp` + `docker commit` y no `docker build`: setup-buildx deja un constructor
# cuyo almacen no ve las imagenes locales. Cuatro variantes:
#   fail            una migracion de prueba (`2099_12_31_000000_ci_update_fault`) que lanza
#                   una excepcion al instante. Ordena la ultima y, con la matriz abierta
#                   (`V *`), cae en la ultima version de la cadena.
#   slow            la misma, pero pone `application_name`, duerme 120 s EN POSTGRESQL
#                   (`pg_sleep`: la sesion SQL sobrevive a la muerte del cliente) y despues
#                   lanza la excepcion: nunca queda aplicada.
#   fail-norestore  `fail`, y ademas `restore.sh` de la imagen rechaza la restauracion
#                   AUTOMATICA (la que lleva `--audit-by-caller`, la de update.sh): sale 1.
#                   La manual, la de las ordenes impresas, funciona (el original queda
#                   como `restore.real.sh`).
#   nohorizon       sin migracion de prueba: el punto de entrada de la imagen sale con 1 si
#                   el rol es `horizon`. La actualizacion llega al 401 y falla DESPUES.
# El fallo de la version anterior para la salida 5 con la copia ya restaurada es otro: su
# imagen de nginx se sustituye por una que termina al arrancar, de modo que la vuelta
# atras no consigue relanzarla sana. Para entonces ya ha hecho `artisan up`: tras el paso
# 3 la API da 401. El 503 antes del 3b es de U3n (`fail-norestore`): la restauracion
# automatica falla antes de relanzar nada, y el `app` anterior, que solo se paro y no se
# recrea, conserva su fichero de mantenimiento.
#
# MODOS
#
#   chain-check
#     `update.sh --chain 2.1.0` sale con 2 y dice a que version ir PRIMERO; una version que
#     no se ha publicado nunca sale con 2 y lo dice; la cadena desde la version anterior
#     (la que esta etapa instala) sale con 0.
#
#   rollback-sigint            (escenario A, variante fail)
#     SIGINT al grupo de procesos TRES veces DURANTE la vuelta atras (al empezar, durante
#     la restauracion y al relanzar). Exige salida 4 (no 5), `VUELTA ATRAS COMPLETADA`,
#     que el motivo siga siendo la migracion (la senal no lo cambia: `trap '' INT`),
#     la migracion nombrada en pantalla y en el informe (con `rollback-reason`,
#     `rollback-failed-step`, `chain-discarded` y `report-id`), `resultado: correcto`, el
#     asiento `system.restored_from_backup` con `reason=migration_failed` (si la version
#     anterior conoce el comando), la API atendiendo (401, no 503), ningun contenedor de un
#     solo uso vivo y el aislamiento `setsid -w` del paso 1 en `[ok]`.
#
#   migration-sigint           (escenario B, variante slow + doctor.sh en el mantenimiento)
#     Con la migracion lenta en marcha (el mantenimiento esta puesto): `doctor.sh` sale 6
#     con `[FALLA] ... /api/v1/auth/me): responde 503`; despues SIGINT al grupo. Exige
#     salida 4 (no 5 por conexiones abiertas), `interrumpido por el operador (senal INT)`,
#     la migracion en curso nombrada, el informe y el asiento con `reason=interrupted`, y
#     que no quede ningun contenedor de un solo uso ni sesion `ci-migracion-lenta` en PostgreSQL.
#
#   manual-path                (escenario C, variante fail + nginx anterior roto)
#     La restauracion automatica TERMINA y la vuelta atras no consigue relanzar la version
#     anterior: salida 5. El bloque trae SOLO `1-2.` (copia ya restaurada) y los pasos 3, 3b,
#     4, 4b y 4c, sin 1, 1b, 1c ni 2. Se corrige la causa y se EJECUTAN LAS ORDENES IMPRESAS,
#     en orden y tal como salen. La vuelta atras automatica ya hizo `artisan up` (fallo despues,
#     en la sonda de /health): la API da 401 ya antes del 3b, que es idempotente; el 4c termina bien. El asiento de la vuelta
#     atras lo escribio update.sh (o se escribe con el paso 5 si sale impreso). Aparte, la
#     sonda `app.maintenance_mode` de `product:doctor` de la imagen nueva, en un contenedor
#     suelto con `artisan down`: sale 2 y la nombra.
#
#   manual-path-restore        (escenario C2, variante fail-norestore)
#     La restauracion automatica NO termina (restore.sh sale 1): salida 5 con el bloque
#     COMPLETO: 1, 1b, 1c y 2 (restaurar) ademas de 3, 3b, 4, 4b y 4c, y sin `1-2.` ni 5.
#     Se ejecutan todas, en orden. Aqui la vuelta atras automatica NO llego al `artisan up`:
#     el `app` anterior solo se paro, el paso 3 lo arranca sin recrearlo y conserva su mantenimiento,
#     asi que ANTES del 3b hay 503 y `doctor.sh` sale 6 con la FALLA; despues, 401 y `[ok]`.
#     La restauracion manual escribe su propio asiento.
#
#   forward-only               (escenario P, variante nohorizon)
#     Un fallo DESPUES del 401 (horizon no arranca): salida 5, `SIN VUELTA ATRAS` en pantalla,
#     `NO aplicada en el paso 5` en el informe, `NO RESTAURES NINGUNA COPIA`, ningun asiento
#     `system.restored_from_backup` nuevo, `/health` con la version NUEVA, `auth/me` en 401 y los
#     mismos recuentos antes y despues. Dos SIGINT al grupo durante la espera de horizon (ya
#     pasado el 401) no cambian nada. Despues se arregla la causa y se ejecuta la orden 3 impresa.
#     Deja la version NUEVA en servicio.
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
  printf 'uso: update-faults-e2e.sh (chain-check|rollback-sigint|migration-sigint|manual-path|manual-path-restore|forward-only|restore-images) VERSION_ANTERIOR VERSION_NUEVA [DIR_ANTERIOR [DIR_NUEVO]]\n' >&2
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
readonly COMPOSE_PROJECT="kronoqr"
readonly SETSID_CHECK='\[ok\].*Aislamiento de la vuelta atras frente a un Ctrl\+C \(setsid -w\)'

STEP=""
LAUNCHER=""
EXIT_CODE=""
OUT=""
ERR=""
declare -A ORDERS=()

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

# Tras una actualizacion que se queda en la version nueva, el compose anterior esta retirado
# (sustituido por uno que falla): se pregunta por el nuevo.
sql_new() {
  compose_new exec -T postgres psql -U fichaje_migrator -d fichaje -v ON_ERROR_STOP=1 -Atqc "$1"
}

# Codigo HTTP de una ruta por el borde (000 si no responde).
edge_status() {
  curl --silent --insecure --max-time 20 --output /dev/null --write-out '%{http_code}' \
    --header 'Accept: application/json' "https://127.0.0.1$1" || true
}

latest_report() {
  "${SUDO[@]}" sh -c "ls -t ${REPORTS_DIR}/update-*.log | head -n 1"
}

latest_detail() {
  "${SUDO[@]}" sh -c "ls -t ${LOG_DIR}/update-*.detalle.log | head -n 1"
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

# Lo que el informe lleva SIEMPRE tras restaurar (RS-07): el motivo, el paso, la punta de la
# cadena que se descarto y el id del informe, aunque la vuelta atras se quede a medias.
assert_rollback_report_fields() {
  local reason="$1"
  assert_report_has "Comprobacion · rollback-reason: ${reason}"
  assert_report_has 'Comprobacion · rollback-failed-step: [A-Za-z_]+'
  assert_report_has 'Comprobacion · chain-discarded: .+'
  assert_report_has 'Comprobacion · report-id: update-[0-9TZ]+'
}

assert_no_lock() {
  local found
  found="$("${SUDO[@]}" sh -c "ls -d ${LOG_DIR}/update.lock 2>/dev/null || true")"
  [ -z "${found}" ] || fail "update.sh ha dejado el candado ${found}"
}

assert_no_oneoff() {
  local ids
  ids="$(docker_root ps -q --filter "label=com.docker.compose.project=${COMPOSE_PROJECT}" \
    --filter 'label=com.docker.compose.oneoff=True')"
  [ -z "${ids}" ] || fail "quedan contenedores de un solo uso de esta actualizacion: ${ids}"
}

assert_no_secrets() {
  local -a reports=()
  mapfile -t reports < <("${SUDO[@]}" sh -c "ls ${REPORTS_DIR}/update-*.log ${LOG_DIR}/update-*.log")
  bash "${REPO_ROOT}/.github/scripts/assert-no-secrets.sh" "${SECRETS_ENV:-${OLD_DIR}/.env}" "$@" "${reports[@]}"
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

# ¿Conoce la version anterior el comando que escribe el asiento de la vuelta atras? 0 si, 1 no.
# La misma pregunta y la misma funcion que update.sh y el escenario U3.
old_knows_audit_command() {
  local known=0
  kq_app_knows_command compose_old compliance:record-system-event || known=$?
  case "${known}" in
  0 | 1) return "${known}" ;;
  *) fail "no se ha podido preguntar a la version ${OLD} por compliance:record-system-event: ${KQ_APP_COMMAND_ERROR}" ;;
  esac
}

# El ultimo asiento de audit_log es system.restored_from_backup (y, si se pide, con ese motivo).
# SOLO si la version que queda en pie conoce el comando que lo escribe.
assert_restored_entry() {
  local reason="${1:-}" last
  if ! old_knows_audit_command; then
    note "la version ${OLD} no conoce compliance:record-system-event: no se exige el asiento"
    return 0
  fi
  last="$(sql_old "SELECT action || ' ' || payload::text FROM audit_log ORDER BY id DESC LIMIT 1")"
  grep -q '^system\.restored_from_backup ' <<<"${last}" ||
    fail "el ultimo asiento de audit_log no es system.restored_from_backup: ${last}"
  if [ -n "${reason}" ]; then
    grep -qE "\"reason\": ?\"${reason}\"" <<<"${last}" ||
      fail "el asiento no lleva reason=${reason}: ${last}"
  fi
  note "asiento system.restored_from_backup${reason:+ con reason=${reason}}"
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
        DB::statement('SELECT pg_sleep(120)');
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

# Un restore.sh que rechaza SOLO la restauracion automatica de update.sh (la que lleva
# `--audit-by-caller`): sale 1 antes de tocar nada. Las ordenes impresas no la llevan.
write_restore_wrapper() {
  cat <<'SH'
#!/usr/bin/env bash
# Etapa 8b de la CI: fallo simulado de la restauracion AUTOMATICA; la manual funciona.
for arg in "$@"; do
  if [ "${arg}" = "--audit-by-caller" ]; then
    echo "restore.sh de prueba (CI 8b): restauracion automatica rechazada a proposito." >&2
    exit 1
  fi
done
exec bash "$(dirname -- "${BASH_SOURCE[0]}")/restore.real.sh" "$@"
SH
}

# Un punto de entrada que no arranca `horizon`. Cualquier otro rol (fpm, reverb, scheduler, y
# los `php artisan ...` de migrate y restore) pasa al original.
write_entrypoint_wrapper() {
  cat <<'SH'
#!/usr/bin/env bash
# Etapa 8b de la CI: horizon no arranca.
if [ "${1:-fpm}" = "horizon" ]; then
  echo '{"level":"error","service":"entrypoint","message":"horizon roto a proposito (etapa 8b de la CI)"}' >&2
  exit 1
fi
exec /usr/local/bin/kronoqr-entrypoint.real "$@"
SH
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

# Construye `kronoqr/php:<nueva>` como la sana mas la variante pedida (fail, slow, fail-norestore,
# nohorizon).
build_fault_image() {
  local kind="$1" tmp cid scripts="/opt/kronoqr/scripts" bin="/usr/local/bin"
  ensure_sane_tag php "${NEW}"
  tmp="$(mktemp -d)"
  cid="$(docker_root create "kronoqr/php:${NEW}-sana")"
  case "${kind}" in
  fail | slow | fail-norestore)
    write_migration "${kind%-norestore}" >"${tmp}/${FAULT_MIGRATION}.php"
    docker_root cp "${tmp}/${FAULT_MIGRATION}.php" "${cid}:/var/www/html/database/migrations/${FAULT_MIGRATION}.php"
    open_matrix
    ;;
  esac
  case "${kind}" in
  fail-norestore)
    docker_root cp "${cid}:${scripts}/restore.sh" "${tmp}/restore.real.sh"
    write_restore_wrapper >"${tmp}/restore.sh"
    # Solo el envoltorio: restore.real.sh sale del contenedor como fichero de root
    # (docker_root), con el modo del original ya conservado por `docker cp`.
    chmod 0755 "${tmp}/restore.sh"
    docker_root cp "${tmp}/restore.real.sh" "${cid}:${scripts}/restore.real.sh"
    docker_root cp "${tmp}/restore.sh" "${cid}:${scripts}/restore.sh"
    ;;
  nohorizon)
    docker_root cp "${cid}:${bin}/kronoqr-entrypoint" "${tmp}/kronoqr-entrypoint.real"
    write_entrypoint_wrapper >"${tmp}/kronoqr-entrypoint"
    # Solo el envoltorio, como en fail-norestore: el .real es de root y conserva su modo.
    chmod 0755 "${tmp}/kronoqr-entrypoint"
    docker_root cp "${tmp}/kronoqr-entrypoint.real" "${cid}:${bin}/kronoqr-entrypoint.real"
    docker_root cp "${tmp}/kronoqr-entrypoint" "${cid}:${bin}/kronoqr-entrypoint"
    ;;
  esac
  docker_root commit "${cid}" "kronoqr/php:${NEW}" >/dev/null
  docker_root rm "${cid}" >/dev/null
  rm -rf "${tmp}"
  case "${kind}" in
  fail | slow | fail-norestore)
    docker_root run --rm --entrypoint ls "kronoqr/php:${NEW}" /var/www/html/database/migrations |
      grep -qx "${FAULT_MIGRATION}.php" ||
      fail "la imagen kronoqr/php:${NEW} no lleva la migracion de prueba"
    ;;
  esac
  note "kronoqr/php:${NEW} lleva la variante ${kind}"
}

restore_images() {
  retag_sane php "${NEW}"
  retag_sane nginx "${NEW}"
  retag_sane nginx "${OLD}"
  if [ -f "${NEW_DIR}/versions.txt.orig" ]; then
    mv -f "${NEW_DIR}/versions.txt.orig" "${NEW_DIR}/versions.txt"
  fi
}

# El nginx de la version anterior, sustituido por una imagen que termina al arrancar.
break_old_nginx() {
  ensure_sane_tag nginx "${OLD}"
  docker_root pull --quiet alpine:3.20 >/dev/null
  docker_root tag alpine:3.20 "kronoqr/nginx:${OLD}"
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
  code=0
  bash "${NEW_DIR}/update.sh" --chain 1.0.0 --lang es >/dev/null 2>"${err}" || code=$?
  cat "${err}"
  [ "${code}" -eq 2 ] || fail "--chain 1.0.0 (nunca publicada) tenia que salir con 2 y salio con ${code}"
  grep -q 'no es ninguna version publicada' "${err}" ||
    fail "--chain 1.0.0 no dice que no es ninguna version publicada"
  note "--chain 1.0.0 sale con 2 y dice que no es ninguna version publicada"
  rm -f "${err}"
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
  build_fault_image fail
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
  grep -qE "${SETSID_CHECK}" "${OUT}" ||
    fail_with_output "el paso 1 no da [ok] el aislamiento de la vuelta atras frente a un Ctrl+C (setsid -w)"
  assert_report_has "Comprobacion · failed-migration: ${FAULT_MIGRATION}"
  assert_report_has 'Vuelta atras: .*resultado: correcto'
  assert_rollback_report_fields migration_failed
  "${SUDO[@]}" grep -q 'pg_terminate_backend' "$(latest_detail)" ||
    fail "el detalle tecnico no recoge el cierre de sesiones previo a restaurar"
  assert_no_oneoff
  assert_old_in_service
  assert_restored_entry migration_failed
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
  local waited=0 running=0 doctor_log="${NEW_DIR}/doctor-u1b.log" doctor_code=0 detail left
  build_fault_image slow
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
    fail_with_output "se esperaba salida 4 (vuelta atras completada) y fue ${EXIT_CODE}: la sesion de la migracion, abierta, no debe dejar la vuelta atras en 5"
  grep -q 'interrumpido por el operador (senal INT)' "${ERR}" ||
    fail_with_output "la salida de error no dice que se interrumpio por SIGINT"
  grep -q "MIGRACION QUE FALLABA (o en curso al pararse): ${FAULT_MIGRATION}" "${ERR}" ||
    fail_with_output "la pantalla no nombra la migracion en curso (${FAULT_MIGRATION})"
  grep -q 'VUELTA ATRAS COMPLETADA' "${ERR}" || fail_with_output "la salida de error no dice VUELTA ATRAS COMPLETADA"
  assert_report_has "Comprobacion · failed-migration: ${FAULT_MIGRATION}"
  assert_report_has 'Vuelta atras: .*interrumpido por el operador.*resultado: correcto'
  assert_rollback_report_fields interrupted

  # La sesion SQL de la migracion sobrevive a la muerte del cliente (PHP es el PID 1 del contenedor
  # y ignora SIGINT): update.sh tiene que haber matado el contenedor y cerrado la sesion ANTES de restaurar.
  detail="$(latest_detail)"
  "${SUDO[@]}" grep -q 'pg_terminate_backend' "${detail}" ||
    fail "el detalle tecnico no recoge el cierre de sesiones previo a restaurar"
  left="$("${SUDO[@]}" grep -E 'docker kill: [0-9]+ contenedor' "${detail}" || true)"
  note "detalle: ${left:-sin contenedores de un solo uso que matar (el cliente ya los habia parado)}"
  assert_no_oneoff
  left="$(sql_old "SELECT count(*) FROM pg_stat_activity WHERE application_name = '${SLOW_MARK}'")"
  [ "${left}" = "0" ] || fail "quedan ${left} sesion(es) ${SLOW_MARK} en pg_stat_activity tras la vuelta atras"
  assert_old_in_service
  assert_restored_entry interrupted
  compose_old exec -T app php artisan compliance:verify-audit-chain
  assert_no_lock
  assert_no_secrets "${OUT}" "${ERR}" "${doctor_log}"
  note "SIGINT al grupo durante las migraciones: salida 4, motivo interrupted, sin restos"
}

#------------------------------------------------------------------------------
# C y C2 · Salida 5 y camino manual
#------------------------------------------------------------------------------
# El bloque «Que hay que hacer, en este orden» de la salida 5, completo.
extract_block() {
  perl -0ne '
    s/\\\n\s+/ /g;
    print "$1\n" if /(Que hay que hacer, en este orden:\n.*?)\nProcedimiento completo/s;
  ' "$1"
}

# Las ordenes de ese bloque, tal como las imprime update.sh: una por linea, `<paso><TAB><orden>`.
# La del paso 2 ocupa dos lineas (termina en `\`); `1-2.` y las lineas sin orden no casan.
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

load_orders() {
  local key command
  ORDERS=()
  while IFS=$'\t' read -r key command; do
    ORDERS["${key}"]="${command}"
  done < <(extract_orders "$1")
}

assert_orders_present() {
  local key
  for key in "$@"; do
    [ -n "${ORDERS[$key]:-}" ] || fail_with_output "el bloque de la salida 5 no trae la orden del paso ${key}"
  done
}

assert_orders_absent() {
  local key
  for key in "$@"; do
    [ -z "${ORDERS[$key]:-}" ] || fail_with_output "el bloque de la salida 5 trae la orden del paso ${key} y no debia: ${ORDERS[$key]}"
  done
}

run_printed_order() {
  local key="$1" command="${ORDERS[$1]}"
  command="${command//<correo>/ci-8b@kronoqr.invalid}"
  # Un operador tiene terminal; el job de la CI no. Sin -T, `compose run` intentaria reservar uno.
  case "${command}" in
  *" -T "*) ;;
  *) command="${command/ run --rm / run --rm -T }" ;;
  esac
  note "orden ${key}: ${command}"
  "${SUDO[@]}" bash -c "${command}" || fail "la orden ${key} impresa por update.sh ha fallado"
}

wait_edge_health() {
  local status=""
  for _ in $(seq 1 60); do
    status="$(edge_status /api/v1/health)"
    [ "${status}" = "200" ] && return 0
    sleep 2
  done
  fail "/api/v1/health responde ${status} tras relanzar la version anterior"
}

# Del 3 al 4c, tal cual salen impresas.
run_relaunch_orders() {
  local status
  run_printed_order 3
  wait_edge_health
  run_printed_order 3b
  run_printed_order 4
  status="$("${SUDO[@]}" bash -c "${ORDERS[4b]}" 2>/dev/null || true)"
  [ "${status}" = "401" ] || fail "la orden 4b impresa da '${status}' y tenia que dar 401"
  run_printed_order 4c
  assert_old_in_service
}

mode_manual_path() {
  STEP="U3m-salida-5"
  local doctor_code=0 status block
  local after="${NEW_DIR}/doctor-u3m-despues.log"

  build_fault_image fail
  # Que la version anterior no arranque sana tras restaurar: su nginx termina al arrancar. Un
  # contenedor ya creado conserva su imagen; es la recreacion de la vuelta atras la que la usa.
  break_old_nginx

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
  assert_rollback_report_fields migration_failed
  assert_no_lock
  assert_no_oneoff

  # La restauracion automatica termino: el bloque NO manda restaurar otra vez.
  load_orders "${ERR}"
  block="$(extract_block "${ERR}")"
  assert_orders_present 3 3b 4 4b 4c
  assert_orders_absent 1 1b 1c 2
  grep -qF '1-2.' <<<"${block}" || fail_with_output "el bloque no dice que la copia ya esta restaurada (1-2.)"
  if grep -q 'restore.sh --file' <<<"${block}"; then
    fail_with_output "el bloque manda restaurar con restore.sh y la copia ya estaba restaurada"
  fi
  note "el bloque trae 1-2 (ya restaurada) y los pasos 3, 3b, 4, 4b y 4c"

  # El asiento de la vuelta atras: update.sh lo escribio solo (y lo dice el informe), o el bloque trae
  # la orden 5 para escribirlo a mano.
  if [ -z "${ORDERS[5]:-}" ] && old_knows_audit_command; then
    assert_report_has 'Comprobacion · audit-entry-rollback: correcto'
  fi

  run_printed_order 3
  wait_edge_health
  # La vuelta atras automatica llego a relanzar la version anterior (app y nginx sanos) y a retirar su
  # mantenimiento con `artisan up`; solo despues fallo, en la sonda de /health por el nginx roto. Asi
  # que ANTES del 3b la API de gestion ya atiende: 401, no 503. (El 3b es idempotente.)
  grep -q 'Esperando a que nginx este listo' "${OUT}" ||
    fail_with_output "la vuelta atras no llego a relanzar la version anterior"
  grep -q 'no ha arrancado sana tras restaurar' "${ERR}" ||
    fail_with_output "la vuelta atras no fallo en la sonda posterior al relanzamiento"
  status="$(edge_status /api/v1/auth/me)"
  [ "${status}" = "401" ] ||
    fail "tras el paso 3 y antes del 3b, /api/v1/auth/me responde ${status} y se esperaba 401 (la vuelta atras ya retiro el mantenimiento con artisan up)"

  run_printed_order 3b
  run_printed_order 4
  status="$("${SUDO[@]}" bash -c "${ORDERS[4b]}" 2>/dev/null || true)"
  [ "${status}" = "401" ] || fail "la orden 4b impresa da '${status}' y tenia que dar 401"
  run_printed_order 4c
  if [ -n "${ORDERS[5]:-}" ]; then
    run_printed_order 5
  fi
  assert_old_in_service
  assert_restored_entry migration_failed

  doctor_code=0
  "${SUDO[@]}" bash "${NEW_DIR}/doctor.sh" --lang es --current "${OLD_DIR}" >"${after}" 2>&1 || doctor_code=$?
  cat "${after}"
  if grep -qE '\[FALLA\].*/api/v1/auth/me\)' "${after}"; then
    fail "tras el 3b doctor.sh sigue marcando [FALLA] la API de gestion"
  fi
  grep -qE '\[ok\].*/api/v1/auth/me\): responde 401' "${after}" ||
    fail "tras el 3b doctor.sh no da [ok] la API de gestion en 401"

  product_doctor_new_image
  assert_no_secrets "${OUT}" "${ERR}" "${after}" "${NEW_DIR}/product-doctor-u3m.log"
  note "camino manual de la salida 5 (copia ya restaurada) completo: 401 desde el paso 3 (la vuelta atras ya retiro el mantenimiento)"
}

mode_manual_path_restore() {
  STEP="U3n-salida-5-sin-restaurar"
  local block status doctor_code=0
  local before="${NEW_DIR}/doctor-u3n-antes.log" after="${NEW_DIR}/doctor-u3n-despues.log"

  build_fault_image fail-norestore
  launch_update "${NEW_DIR}/salida-u3n.log" "${NEW_DIR}/err-u3n.log"
  finish_update
  tail -n 40 "${OUT}" || true
  cat "${ERR}"

  [ "${EXIT_CODE}" -eq 5 ] ||
    fail_with_output "se esperaba salida 5 (la restauracion automatica no termino) y fue ${EXIT_CODE}"
  grep -q 'VUELTA ATRAS INCOMPLETA' "${ERR}" || fail_with_output "la salida de error no dice VUELTA ATRAS INCOMPLETA"
  assert_report_has 'Vuelta atras: .*resultado: REQUIERE INTERVENCION'
  assert_rollback_report_fields migration_failed
  assert_no_lock

  # La copia NO se restauro: el bloque manda parar, matar lo que quede, cerrar sesiones y restaurar.
  load_orders "${ERR}"
  block="$(extract_block "${ERR}")"
  assert_orders_present 1 1b 1c 2 3 3b 4 4b 4c
  assert_orders_absent 5
  if grep -qF '1-2.' <<<"${block}"; then
    fail_with_output "el bloque dice que la copia ya esta restaurada y no lo esta"
  fi
  grep -q 'restore.sh --file' <<<"${block}" || fail_with_output "el bloque no trae la orden de restaurar (paso 2)"
  note "el bloque trae los pasos 1, 1b, 1c, 2, 3, 3b, 4, 4b y 4c"

  run_printed_order 1
  run_printed_order 1b
  run_printed_order 1c
  run_printed_order 2
  run_printed_order 3
  wait_edge_health
  # Aqui la restauracion automatica NO llego al `artisan up`: el `app` anterior solo se paro
  # (`compose stop`) y el paso 3 impreso lo ARRANCA sin recrearlo, asi que conserva el fichero de
  # mantenimiento de su contenedor. La API responde a las sondas y da 503 a todo lo demas.
  status="$(edge_status /api/v1/auth/me)"
  [ "${status}" = "503" ] ||
    fail "tras el paso 3 y antes del 3b, /api/v1/auth/me responde ${status} y se esperaba 503 (el app anterior solo se paro: tenia que conservar su mantenimiento)"
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
  run_printed_order 4c
  assert_old_in_service
  doctor_code=0
  "${SUDO[@]}" bash "${NEW_DIR}/doctor.sh" --lang es --current "${OLD_DIR}" >"${after}" 2>&1 || doctor_code=$?
  cat "${after}"
  if grep -qE '\[FALLA\].*/api/v1/auth/me\)' "${after}"; then
    fail "tras el 3b doctor.sh sigue marcando [FALLA] la API de gestion"
  fi
  grep -qE '\[ok\].*/api/v1/auth/me\): responde 401' "${after}" ||
    fail "tras el 3b doctor.sh no da [ok] la API de gestion en 401"
  assert_restored_entry
  assert_no_secrets "${OUT}" "${ERR}" "${before}" "${after}"
  note "camino manual de la salida 5 (copia sin restaurar) completo, de la orden 1 al 4c"
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

#------------------------------------------------------------------------------
# P · Un fallo DESPUES del 401: salida 5 sin vuelta atras
#------------------------------------------------------------------------------
count_of() {
  sql_old "SELECT count(*) FROM $1"
}

mode_forward_only() {
  STEP="U3p-tras-el-401"
  local restored_before users_before entries_before restored_after users_after entries_after
  local body status last recover
  SECRETS_ENV="${NEW_DIR}/.env"

  # El nginx nuevo lo dejo roto el escenario U3 de la CI (alpine): aqui tiene que atender.
  retag_sane nginx "${NEW}"
  build_fault_image nohorizon
  restored_before="$(count_of "audit_log WHERE action = 'system.restored_from_backup'")"
  users_before="$(count_of users)"
  entries_before="$(count_of shift_entries)"

  launch_update "${NEW_DIR}/salida-u3p.log" "${NEW_DIR}/err-u3p.log"
  # «Arrancando horizon, scheduler y reverb» se dice YA despues del 401: desde ahi las senales se
  # ignoran. Horizon no arranca y update.sh espera 90 s antes de rendirse: ahi van dos SIGINT.
  wait_for_text "${OUT}" 'Arrancando horizon, scheduler y reverb' 1500 ||
    fail_with_output "update.sh no ha llegado a arrancar los procesos de fondo"
  sleep 5
  send_int "tras el 401, esperando a horizon"
  sleep 5
  send_int "tras el 401, esperando a horizon (segunda)"
  finish_update
  tail -n 40 "${OUT}" || true
  cat "${ERR}"

  [ "${EXIT_CODE}" -eq 5 ] ||
    fail_with_output "se esperaba salida 5 (fallo tras el 401, sin vuelta atras) y fue ${EXIT_CODE}"
  grep -q 'SIN VUELTA ATRAS' "${OUT}" || fail_with_output "la pantalla no dice SIN VUELTA ATRAS"
  grep -q 'ACTUALIZACION HECHA, PERO INCOMPLETA' "${ERR}" || fail_with_output "la salida de error no dice ACTUALIZACION HECHA, PERO INCOMPLETA"
  grep -q 'NO RESTAURES NINGUNA COPIA' "${ERR}" || fail_with_output "la salida de error no dice NO RESTAURES NINGUNA COPIA"
  if grep -q 'VUELTA ATRAS COMPLETADA\|Restaurando la copia previa' "${OUT}" "${ERR}"; then
    fail_with_output "update.sh ha restaurado la copia despues del 401"
  fi
  if grep -q 'interrumpido por el operador' "${ERR}"; then
    fail_with_output "una senal despues del 401 ha cambiado el motivo: tenia que ignorarse"
  fi
  assert_report_has 'Vuelta atras: NO aplicada en el paso 5'
  assert_no_lock

  # La version NUEVA sigue en servicio y atendiendo.
  body="$(curl --silent --show-error --fail --insecure --max-time 20 https://127.0.0.1/api/v1/health)" ||
    fail "/api/v1/health no responde tras el fallo posterior al 401"
  grep -qF "\"version\":\"${NEW}\"" <<<"${body}" || fail "la version en pie no es la nueva (${NEW}): ${body}"
  status="$(edge_status /api/v1/auth/me)"
  [ "${status}" = "401" ] || fail "/api/v1/auth/me responde ${status} y se esperaba 401"

  # Nada borrado y ningun asiento de vuelta atras.
  restored_after="$(sql_new "SELECT count(*) FROM audit_log WHERE action = 'system.restored_from_backup'")"
  users_after="$(sql_new 'SELECT count(*) FROM users')"
  entries_after="$(sql_new 'SELECT count(*) FROM shift_entries')"
  [ "${restored_before}" = "${restored_after}" ] ||
    fail "audit_log tiene ${restored_after} asientos system.restored_from_backup y antes ${restored_before}: no se debio restaurar"
  [ "${users_before}" = "${users_after}" ] || fail "users paso de ${users_before} a ${users_after}"
  [ "${entries_before}" = "${entries_after}" ] || fail "shift_entries paso de ${entries_before} a ${entries_after}"
  last="$(sql_new 'SELECT action FROM audit_log ORDER BY id DESC LIMIT 1')"
  [ "${last}" = "system.updated" ] || fail "el ultimo asiento de audit_log es '${last}' y se esperaba system.updated"
  note "version nueva en servicio, 401, sin asiento de vuelta atras y recuentos intactos"

  # Arreglada la causa, la orden 3 impresa arranca lo que fallo.
  retag_sane php "${NEW}"
  recover="$(sed -n 's/^  3\. Arrancarlos cuando este resuelto: *//p' "${ERR}")"
  [ -n "${recover}" ] || fail_with_output "el mensaje no trae la orden 3 (arrancar horizon, scheduler y reverb)"
  note "orden 3: ${recover}"
  "${SUDO[@]}" bash -c "${recover}" || fail "la orden 3 impresa ha fallado"
  for _ in $(seq 1 60); do
    if compose_new exec -T app php artisan horizon:status >/dev/null 2>&1; then
      assert_no_secrets "${OUT}" "${ERR}"
      note "tras la orden 3, horizon responde: la version nueva queda completa"
      return 0
    fi
    sleep 2
  done
  fail "horizon no responde tras ejecutar la orden 3 impresa"
}

case "${MODE}" in
chain-check) mode_chain_check ;;
rollback-sigint) mode_rollback_sigint ;;
migration-sigint) mode_migration_sigint ;;
manual-path) mode_manual_path ;;
manual-path-restore) mode_manual_path_restore ;;
forward-only) mode_forward_only ;;
restore-images)
  STEP="restore-images"
  restore_images
  ;;
*)
  printf 'modo desconocido: %s\n' "${MODE}" >&2
  exit 2
  ;;
esac
