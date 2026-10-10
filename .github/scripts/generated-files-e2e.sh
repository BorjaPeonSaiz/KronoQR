#!/usr/bin/env bash
#
# KronoQR — etapa ⑧ de la CI: los ficheros generados, de extremo a extremo
# sobre una instalacion real (ADR-045, R3-PL-01, bloque 16 de la 2.2.0).
#
# NO ES UN ENTREGABLE: vive en .github/scripts/ y solo lo ejecuta la CI.
#
# POR QUE EXISTE. `app`, `horizon` y `scheduler` son tres contenedores de la
# misma imagen. Hasta la 2.2.0 cada uno tenia su propio `storage/app`: la
# exportacion integra que generaba `horizon` devolvia 404 en `app` (R3-PL-01,
# bloqueante), las purgas de `scheduler` no veian lo que debian borrar y una
# actualizacion se llevaba todo por delante. Ninguna prueba de Pest puede verlo,
# porque corren en un solo proceso. Esto lo recorre en la instalacion que acaba
# de dejar el instalador, con la API como la usa el panel:
#
#   1. Primer administrador con su segundo factor (`/setup/administrator`,
#      `/auth/2fa/enrol`, `/auth/2fa/confirm`), exportacion integra pedida por
#      `POST /data-export` (cola -> `horizon`) y DESCARGADA desde `app`: 200 y
#      la huella del cuerpo igual a la de la cabecera y a la de la fila.
#   2. Recrear app, horizon y scheduler (`up -d --force-recreate`): la misma
#      exportacion se sigue descargando. De paso se acorta `stale_after` (paso 6).
#   3. `compliance:apply-retention --dry-run` desde `scheduler`: el informe se
#      lee en el ANFITRION, en BACKUP_PATH/reports/retention. Y la consulta de
#      `docs/cliente/operacion.md` §3.1 (`psql -U fichaje_app`), copiada del
#      paquete tal cual, funciona.
#   4. Un ZIP borrado a mano antes de caducar: la pasada de `scheduler` marca la
#      fila `purged`, deja el asiento `data_export.file_missing` (con el uuid y
#      sin ruta) y sube `generated_files_missing_total{class="data_export"}` en /metrics.
#   5. Caducidad: con `expires_at` vencido, la pasada de `scheduler` borra el ZIP
#      del volumen y la fila queda `purged`, SIN asiento file_missing. Y una fila
#      alterada para apuntar fuera de su raiz (F3) responde 404 sin entregar nada.
#   6. `docker compose kill horizon` a mitad de una exportacion deja su
#      `.work-<uuid>/` en el volumen; la pasada posterior a 2 x `stale_after` lo
#      retira y la fila queda `failed` (stale).
#   7. `restore.sh` de una copia tomada entre dos exportaciones, con el ZIP de
#      la primera borrado (como en un servidor nuevo): su informe anuncia los
#      `file_missing`, la fila restaurada queda `purged` con su asiento, el ZIP
#      de la segunda (sin fila) no se toca antes de su plazo, y una exportacion
#      nueva se pide y se descarga.
#   8. `doctor.sh` en verde con el volumen, y la imagen trae `storage/app` como
#      `app:app 700` (C6).
#
# LO QUE NO SE ESPERA DE VERDAD. Los plazos se acortan, no se esperan:
# `PRODUCT_DATA_EXPORT_STALE_AFTER` baja a 15 s por el `.env` (paso 2), y la
# caducidad de siete dias se adelanta escribiendo `expires_at` en la fila con el
# rol de migracion (paso 5): `PRODUCT_DATA_EXPORT_RETENTION_DAYS` no baja de 1.
# Para que la exportacion del paso 6 dure lo bastante como para matar a
# `horizon` en mitad, se siembran filas sinteticas en `error_events` (una tabla
# que la exportacion recorre y que no forma parte del registro legal) y se
# retiran al terminar.
#
# Uso:
#   generated-files-e2e.sh DIRECTORIO_DEL_PAQUETE
#
# Variables:
#   KQ_E2E_BASE_URL   (https://kronoqr.ci.local) donde responde el borde.
#   KQ_E2E_APP_IMAGE  (kronoqr/app:ci) la imagen cuyo storage/app se comprueba.
#   KQ_E2E_SUDO       (sudo) prefijo para docker y el .env (0600 de root). Vacio
#                     si ya se es root, como en docker-in-docker.
#   KQ_E2E_SEED_ROWS  (300000) filas sinteticas para alargar la exportacion.
#
# Necesita: docker con el plugin compose, curl, jq, sha256sum.
# Codigos de salida: 0 todo comprobado · 1 algo no se cumple · 2 uso incorrecto.

set -euo pipefail
IFS=$'\n\t'

[ "$#" -eq 1 ] || {
  printf 'uso: generated-files-e2e.sh DIRECTORIO_DEL_PAQUETE\n' >&2
  exit 2
}

PKG="$(cd -- "$1" && pwd)"
BASE_URL="${KQ_E2E_BASE_URL:-https://kronoqr.ci.local}"
APP_IMAGE="${KQ_E2E_APP_IMAGE:-kronoqr/app:ci}"
SUDO="${KQ_E2E_SUDO-sudo}"
SEED_ROWS="${KQ_E2E_SEED_ROWS:-300000}"
STALE_AFTER=15

readonly STORAGE="/var/www/html/storage/app"
readonly EXPORTS="${STORAGE}/exports"
readonly SEED_MARK="ci-e2e-seed"

WORK="$(mktemp -d)"
trap 'rm -rf "${WORK}"' EXIT

TOKEN=""
STEP=""

# --- utilidades --------------------------------------------------------------

# step, fail, ok, as_root, dc, api, expect_status, wait_ready, totp y seed_admin_and_site.
# shellcheck source=.github/scripts/lib/ci-api.sh
. "$(dirname -- "${BASH_SOURCE[0]}")/lib/ci-api.sh"

# Valor de una clave del .env (0600 de root: por eso con sudo).
env_value() {
  as_root sed -n "s/^$1=//p" "${PKG}/.env" | tail -n 1
}

sql() {
  dc exec -T postgres psql -U fichaje_migrator -d fichaje -Atqc "$1"
}

export_status() {
  sql "SELECT status FROM data_exports WHERE uuid = '$1'"
}

export_file() {
  sql "SELECT file_path FROM data_exports WHERE uuid = '$1'"
}

# Espera a que una exportacion salga de pending/running. Por condicion, no por tiempo.
wait_export() {
  local uuid="$1" status=""
  for _ in $(seq 1 90); do
    status="$(export_status "${uuid}")"
    case "${status}" in
    completed | failed) break ;;
    esac
    sleep 2
  done
  [ "${status}" = "completed" ] || fail "la exportacion ${uuid} quedo en '${status}' (se esperaba completed)"
}

request_export() {
  local code
  code="$(api POST /api/v1/data-export)"
  expect_status 202 "${code}" "POST /api/v1/data-export"
  jq -er '.data.uuid' "${WORK}/body"
}

# Descarga y compara las tres huellas: cuerpo, cabecera y fila.
download_matches_row() {
  local uuid="$1" code body_sha header_sha row_sha
  code="$(api GET "/api/v1/data-export/${uuid}/download")"
  expect_status 200 "${code}" "GET /api/v1/data-export/${uuid}/download"
  body_sha="$(sha256sum "${WORK}/body" | cut -d' ' -f1)"
  header_sha="$(sed -n 's/^[Xx]-[Kk]ronoqr-[Ee]xport-[Ss]ha256: *\([0-9a-f]\{64\}\).*/\1/p' "${WORK}/headers")"
  row_sha="$(sql "SELECT sha256 FROM data_exports WHERE uuid = '${uuid}'")"
  [ -n "${row_sha}" ] || fail "la fila ${uuid} no tiene sha256"
  [ "${body_sha}" = "${row_sha}" ] || fail "la huella del ZIP descargado (${body_sha}) no es la de la fila (${row_sha})"
  [ "${header_sha}" = "${row_sha}" ] || fail "la cabecera X-Kronoqr-Export-Sha256 (${header_sha}) no es la de la fila"
  ok "descarga 200 de ${uuid}: $(wc -c <"${WORK}/body") bytes, sha256 ${row_sha:0:12}… igual en cuerpo, cabecera y fila"
}

# La pasada horaria de la purga, en scheduler. Su salida queda en el fichero que se pase.
purge_in_scheduler() {
  dc exec -T scheduler php artisan product:export-all --purge >"$1" 2>&1 ||
    fail "product:export-all --purge sale con error en scheduler: $(cat "$1")"
  cat "$1"
}

missing_entries() {
  sql "SELECT count(*) FROM audit_log WHERE action = 'data_export.file_missing' AND payload->>'data_export_uuid' = '$1'"
}

set_env_key() {
  local key="$1" value="$2"
  if as_root grep -q "^${key}=" "${PKG}/.env"; then
    as_root sed -i "s|^${key}=.*|${key}=${value}|" "${PKG}/.env"
  else
    printf '%s=%s\n' "${key}" "${value}" | as_root tee -a "${PKG}/.env" >/dev/null
  fi
}

# --- 1 ------------------------------------------------------------------------

step "1 · Exportacion integra pedida por la API: genera horizon, descarga app"

wait_ready
seed_admin_and_site "e2e ficheros generados"

first="$(request_export)"
wait_export "${first}"
first_file="$(export_file "${first}")"
dc exec -T horizon test -f "${first_file}" || fail "horizon no ve el ZIP que acaba de escribir"
dc exec -T app test -f "${first_file}" || fail "app no ve el ZIP que escribio horizon (R3-PL-01)"
dc exec -T scheduler test -f "${first_file}" || fail "scheduler no ve el ZIP: su purga no podria borrarlo"
mode="$(dc exec -T app stat -c '%U:%G %a' "${first_file}")"
[ "${mode}" = "app:app 600" ] || fail "el ZIP es ${mode}, y debe ser app:app 600"
ok "el ZIP esta en el volumen, visible en los tres servicios, app:app 600"
download_matches_row "${first}"

# --- 2 ------------------------------------------------------------------------

step "2 · Sobrevive a recrear app, horizon y scheduler"

set_env_key PRODUCT_DATA_EXPORT_STALE_AFTER "${STALE_AFTER}"
before="$(dc ps -q app)"
dc up -d --force-recreate app horizon scheduler
[ "$(dc ps -q app)" != "${before}" ] || fail "app no se ha recreado"
wait_ready
[ "$(dc exec -T scheduler printenv PRODUCT_DATA_EXPORT_STALE_AFTER)" = "${STALE_AFTER}" ] ||
  fail "scheduler no recibe PRODUCT_DATA_EXPORT_STALE_AFTER=${STALE_AFTER}"
download_matches_row "${first}"

# --- 3 ------------------------------------------------------------------------

step "3 · La propuesta de retencion se lee en el anfitrion, y la consulta de la guia funciona"

backup_path="$(env_value BACKUP_PATH)"
reports="${backup_path}/reports/retention"
[ "$(as_root stat -c '%u:%g %a' "${reports}")" = "1000:1000 750" ] ||
  fail "${reports} es $(as_root stat -c '%u:%g %a' "${reports}"), y debe ser 1000:1000 750"
dc exec -T scheduler php artisan compliance:apply-retention --dry-run >"${WORK}/retencion.out" 2>&1 ||
  fail "compliance:apply-retention --dry-run sale con error en scheduler: $(cat "${WORK}/retencion.out")"
proposal="$(as_root find "${reports}" -maxdepth 1 -type f -name 'retencion-propuesta-*.txt' | sort | tail -n 1)"
[ -n "${proposal}" ] || fail "no hay ninguna retencion-propuesta-*.txt en ${reports} del anfitrion"
as_root grep -q 'PURGAR-' "${proposal}" || fail "la propuesta no trae la frase de confirmacion"
ok "$(basename "${proposal}") en ${reports}, $(as_root stat -c '%U:%G %a' "${proposal}")"
if dc exec -T scheduler test -e "${STORAGE}/retention-reports"; then
  fail "scheduler sigue escribiendo informes en storage/app/retention-reports"
fi

# La orden de operacion.md §3.1, copiada del paquete que recibe el cliente.
guide_query="$(awk '/^docker compose exec -T postgres psql -U fichaje_app/ { cmd = $0; getline; print cmd "\n" $0; exit }' \
  "${PKG}/docs/cliente/operacion.md")"
[ -n "${guide_query}" ] || fail "operacion.md ya no trae la consulta 'psql -U fichaje_app' de §3.1"
printf '%s\n' "${guide_query}" >"${WORK}/guia.sh"
(cd "${PKG}" && as_root bash "${WORK}/guia.sh") >"${WORK}/guia.out" ||
  fail "la consulta de operacion.md §3.1 falla tal cual: $(cat "${WORK}/guia.out")"
grep -q 'confirmacion' "${WORK}/guia.out" || fail "la consulta de §3.1 no devuelve sus columnas"
ok "la consulta de operacion.md §3.1 responde con fichaje_app"

# --- 4 ------------------------------------------------------------------------

step "4 · Un ZIP borrado a mano antes de caducar deja asiento y metrica"

second="$(request_export)"
wait_export "${second}"
second_file="$(export_file "${second}")"
dc exec -T app rm -f -- "${second_file}"
purge_in_scheduler "${WORK}/purge-4.out"
grep -q 'ATENCION: 1 exportaciones han perdido su fichero' "${WORK}/purge-4.out" ||
  fail "la pasada no avisa del fichero perdido"
[ "$(export_status "${second}")" = "purged" ] || fail "la fila sin fichero no ha pasado a purged"
[ "$(missing_entries "${second}")" = "1" ] || fail "no hay exactamente un asiento data_export.file_missing para ${second}"
payload="$(sql "SELECT payload::text FROM audit_log WHERE action = 'data_export.file_missing' AND payload->>'data_export_uuid' = '${second}'")"
case "${payload}" in
*/var/* | *storage* | *.zip*) fail "el asiento file_missing lleva una ruta: ${payload}" ;;
esac
code="$(api GET "/api/v1/data-export/${second}/download")"
expect_status 404 "${code}" "descarga de una exportacion purgada"
metric=""
for _ in $(seq 1 15); do
  metric="$(dc exec -T prometheus wget -qO- http://nginx:8080/metrics |
    grep -E '^generated_files_missing_total\{class="data_export"\} ' || true)"
  [ -n "${metric}" ] && break
  sleep 2
done
case "${metric}" in
*' 1' | *' 1.0') ;;
*) fail "la metrica de ficheros perdidos no vale 1: '${metric}'" ;;
esac
ok "fila purged, un asiento sin ruta, descarga 404 y ${metric}"

# --- 5 ------------------------------------------------------------------------

step "5 · Al caducar, la pasada de scheduler borra el ZIP del volumen"

sql "UPDATE data_exports SET expires_at = now() - interval '1 minute' WHERE uuid = '${first}'" >/dev/null
purge_in_scheduler "${WORK}/purge-5.out"
if dc exec -T app test -e "${first_file}"; then
  fail "el ZIP caducado sigue en el volumen (R4-SC-02)"
fi
[ "$(export_status "${first}")" = "purged" ] || fail "la fila caducada no ha pasado a purged"
[ "$(missing_entries "${first}")" = "0" ] || fail "una caducidad normal ha dejado asiento file_missing"
code="$(api GET "/api/v1/data-export/${first}/download")"
expect_status 404 "${code}" "descarga de una exportacion caducada"
ok "ZIP borrado, fila purged, sin asiento file_missing, descarga 404"

# F3: la descarga solo entrega un fichero confinado a su raiz. Una fila
# `completed` alterada para apuntar fuera (aqui, a un fichero que app SI puede
# leer) responde 404 y no entrega su contenido.
outside="$(request_export)"
wait_export "${outside}"
sql "UPDATE data_exports SET file_path = '/etc/passwd' WHERE uuid = '${outside}'" >/dev/null
code="$(api GET "/api/v1/data-export/${outside}/download")"
expect_status 404 "${code}" "descarga de una fila que apunta fuera de su raiz (F3)"
if grep -q 'root:' "${WORK}/body"; then
  fail "F3: la descarga ha entregado el fichero de fuera de la raiz"
fi
ok "una fila que apunta fuera de su raiz responde 404 sin entregar nada"

# --- 6 ------------------------------------------------------------------------

step "6 · kill de horizon a mitad: el .work-<uuid> queda y la purga lo retira"

sql "INSERT INTO error_events (fingerprint, level, source, message, app_version, first_seen_at, last_seen_at, created_at, updated_at)
     SELECT md5('${SEED_MARK}' || g) || md5(g::text || '${SEED_MARK}'), 'error', 'api', repeat('relleno sintetico ', 40),
            '${SEED_MARK}', now(), now(), now(), now()
     FROM generate_series(1, ${SEED_ROWS}) AS g" >/dev/null
third="$(request_export)"
seen=0
for _ in $(seq 1 300); do
  if dc exec -T app test -d "${EXPORTS}/.work-${third}"; then
    seen=1
    break
  fi
  [ "$(export_status "${third}")" != "completed" ] || break
  sleep 0.2
done
[ "${seen}" -eq 1 ] || fail "no se ha visto el .work-${third} antes de que terminara; sube KQ_E2E_SEED_ROWS"
dc kill horizon
[ "$(export_status "${third}")" = "running" ] || fail "tras matar horizon la fila es '$(export_status "${third}")'"
dc exec -T app test -d "${EXPORTS}/.work-${third}" || fail "el .work-${third} no ha sobrevivido al kill"
ok ".work-${third} en el volumen con la fila en running"

gone=0
for _ in $(seq 1 30); do
  purge_in_scheduler "${WORK}/purge-6.out" >/dev/null
  if ! dc exec -T app test -e "${EXPORTS}/.work-${third}"; then
    gone=1
    break
  fi
  sleep 3
done
cat "${WORK}/purge-6.out"
[ "${gone}" -eq 1 ] || fail "la purga no ha retirado .work-${third} en 90 s (2 x stale_after = $((2 * STALE_AFTER)) s)"
[ "$(sql "SELECT status || ':' || coalesce(failure_reason, '') FROM data_exports WHERE uuid = '${third}'")" = "failed:stale" ] ||
  fail "la fila de la exportacion interrumpida no es failed:stale"
leftovers="$(dc exec -T app find "${EXPORTS}" -mindepth 1 -maxdepth 1 -name "kronoqr-export-*.zip.*")"
[ -z "${leftovers}" ] || fail "quedan temporales de ZipArchive: ${leftovers}"
dc up -d horizon
sql "DELETE FROM error_events WHERE app_version = '${SEED_MARK}'" >/dev/null
ok ".work-${third} retirado, fila failed:stale, horizon de nuevo en marcha"

# --- 7 ------------------------------------------------------------------------

step "7 · Restaurar una copia: el informe anuncia los file_missing y la conciliacion los cierra"

# K: completada ANTES de la copia; su fila vuelve con la base. Su ZIP se borra,
# como en un servidor nuevo o tras un `down -v`: el volumen no se repone.
# L: completada DESPUES de la copia; su fila no vuelve y su ZIP queda huerfano.
kept="$(request_export)"
wait_export "${kept}"
kept_file="$(export_file "${kept}")"
dc exec -T scheduler php artisan backup:run --mode=dump >"${WORK}/backup.out" 2>&1 ||
  fail "backup:run falla en scheduler: $(tail -n 20 "${WORK}/backup.out")"
later="$(request_export)"
wait_export "${later}"
later_file="$(export_file "${later}")"
dc exec -T app rm -f -- "${kept_file}"

dc stop app horizon scheduler reverb
dc run --rm --no-deps -T restore bash /opt/kronoqr/scripts/restore.sh --yes >"${WORK}/restore.out" 2>&1 ||
  fail "restore.sh falla: $(tail -n 30 "${WORK}/restore.out")"
restore_report="$(as_root find "${backup_path}/reports" -maxdepth 1 -type f -name 'restore-*.log' | sort | tail -n 1)"
[ -n "${restore_report}" ] || fail "restore.sh no ha dejado su informe en ${backup_path}/reports"
as_root grep -q 'data_export.file_missing' "${restore_report}" ||
  fail "el informe de restore.sh no anuncia los file_missing esperados"
dc up -d app horizon scheduler reverb
wait_ready

purge_in_scheduler "${WORK}/purge-8.out" >/dev/null
[ "$(export_status "${kept}")" = "purged" ] || fail "la fila restaurada sin ZIP no ha pasado a purged"
[ "$(missing_entries "${kept}")" = "1" ] || fail "la fila restaurada sin ZIP no ha dejado su asiento file_missing"
[ "$(sql "SELECT count(*) FROM data_exports WHERE uuid = '${later}'")" = "0" ] ||
  fail "la exportacion posterior a la copia sigue teniendo fila tras restaurar"
# Huerfano, pero mas joven que su plazo: la purga no lo toca todavia. Que se
# borre al cumplirlo (edad = max(mtime, ctime), no se puede envejecer desde
# fuera) lo prueban las pruebas Feature de la conciliacion.
dc exec -T app test -f "${later_file}" || fail "el ZIP huerfano se ha borrado antes de cumplir su plazo"
again="$(request_export)"
wait_export "${again}"
download_matches_row "${again}"
ok "informe con el aviso, fila restaurada purged con su asiento, huerfano respetado y exportacion nueva descargada"

# --- 8 ------------------------------------------------------------------------

step "8 · doctor.sh en verde con el volumen, y la imagen trae storage/app app:app 700"

# El paso 7 recreo app: /ready responde antes de que Docker la marque sana, y
# doctor.sh mira el estado de salud. Se espera por condicion.
health=""
for _ in $(seq 1 60); do
  health="$(as_root docker inspect -f '{{.State.Health.Status}}' "$(dc ps -q app)" 2>/dev/null || true)"
  [ "${health}" = "healthy" ] && break
  sleep 2
done
[ "${health}" = "healthy" ] || fail "app no llega a healthy en 120 s (ultimo estado: ${health})"

if ! (cd "${PKG}" && as_root ./doctor.sh) >"${WORK}/doctor.out" 2>&1; then
  cat "${WORK}/doctor.out"
  fail "doctor.sh sale con fallos"
fi
cat "${WORK}/doctor.out"
grep -q 'La raiz del volumen (storage/app) es app:app 0700' "${WORK}/doctor.out" ||
  fail "doctor.sh no da por buena la raiz del volumen"
grep -q 'Un fichero escrito desde horizon se lee desde app' "${WORK}/doctor.out" ||
  fail "doctor.sh no comprueba que horizon y app comparten el volumen"
image_root="$(as_root docker run --rm --entrypoint stat "${APP_IMAGE}" -c '%U:%G %a' "${STORAGE}")"
[ "${image_root}" = "app:app 700" ] || fail "${APP_IMAGE} trae storage/app como ${image_root}"
ok "doctor.sh sin fallos; ${APP_IMAGE}: storage/app ${image_root}"

printf '\nFicheros generados: los ocho pasos comprobados.\n'
