#!/usr/bin/env bash
#
# KronoQR — job ⑧b de la CI: el rescate de los informes de retencion al
# actualizar (ADR-045 §«Actualizacion desde la 2.1.0», condicion C8, y F1 de la
# revision del bloque 16).
#
# NO ES UN ENTREGABLE: vive en .github/scripts/ y solo lo ejecuta la CI.
#
# Hasta la 2.1.0 los informes de retencion se escribian DENTRO de cada
# contenedor (`storage/app/retention-reports`) y una actualizacion los borraba.
# `update.sh` los pone a salvo en BACKUP_PATH/reports/retention entre la parada
# de los trabajadores y la recreacion. Las pruebas de Pest lo ejercitan con un
# `docker compose cp` simulado; aqui se hace con el de verdad, sobre el
# `scheduler` PARADO de la version anterior, y con lo que el runtime podria
# haber dejado para enganar a un proceso de root:
#
#   seed  (antes de U1, con la version anterior en pie)
#     · en `scheduler`, dentro de la carpeta de la 2.1.0: dos informes validos,
#       un enlace con nombre de informe que apunta a /etc/passwd, un fichero
#       con otro nombre y un informe dentro de un subdirectorio;
#     · en BACKUP_PATH/reports/retention, plantado desde `app` (uid 1000), un
#       enlace con el nombre de uno de esos informes que apunta a un fichero de
#       root del anfitrion (la «victima», 0600).
#   check (despues de U1)
#     · el informe valido sin enlace en destino se ha rescatado: regular,
#       1000:1000, 0640 y con su contenido;
#     · el enlace plantado sigue siendo un enlace y la victima sigue root:root
#       0600 con su contenido (F1: ni chmod ni chown por ruta como root);
#     · no se ha rescatado ni el enlace del contenedor, ni el otro nombre, ni
#       el informe del subdirectorio.
#
# Y el historico de errores anterior a la 2.2.0 (ADR-048 decision 9, H6 del
# dictamen de seguridad del bloque 19; RF-PD-15, RL-19, regla dura 21). Hasta
# la 2.2.0 `error_events` solo pasaba por patrones y un nombre sin comillas se
# quedaba; la migracion `resanitize_error_history` lo vuelve a sanear al
# actualizar. Las pruebas de Pest la ejercitan sobre una tabla sembrada; aqui se
# comprueba sobre la base de datos de una instalacion anterior de verdad:
#
#   seed  · una fila en `error_events`, escrita con SQL saltandose el sumidero
#           (como la habria dejado la version anterior), con un nombre ficticio
#           en `message` y en `context`. Si la version anterior ya trae la
#           migracion, no se siembra: no hay filas antiguas que rescatar.
#   check · el nombre no esta en `error_events`, el grupo sigue ahi (saneado, no
#           borrado), el detalle de la actualizacion lleva la linea
#           `product.error_history_resanitized`, y el paquete de diagnostico
#           anonimizado, generado con el comando real, no lleva el nombre y si
#           el grupo (por su `trace_id`).
#
# Uso:
#   retention-rescue-e2e.sh seed  DIRECTORIO_DEL_PAQUETE_ANTERIOR
#   retention-rescue-e2e.sh check DIRECTORIO_DEL_PAQUETE_NUEVO
#
# Variables: KQ_E2E_SUDO (sudo), vacio si ya se es root.
# Codigos de salida: 0 comprobado · 1 algo no se cumple · 2 uso incorrecto.

set -euo pipefail
IFS=$'\n\t'

[ "$#" -eq 2 ] || {
  printf 'uso: retention-rescue-e2e.sh seed|check DIRECTORIO_DEL_PAQUETE\n' >&2
  exit 2
}

MODE="$1"
PKG="$(cd -- "$2" && pwd)"
SUDO="${KQ_E2E_SUDO-sudo}"

readonly OLD_DIR="/var/www/html/storage/app/retention-reports"
readonly RESCUED="retencion-propuesta-20260105-054000.txt"
readonly PLANTED="retencion-purga-20260301-101500.txt"
readonly CONTAINER_LINK="retencion-purga-20260302-101500.txt"
readonly NESTED="retencion-propuesta-20260106-054000.txt"
readonly OTHER="notas-del-responsable.txt"
readonly VICTIM="/etc/kronoqr-ci-victima"
readonly VICTIM_TEXT="contenido de la victima"
# Historico de errores (H6). Nombre y apellido inventados y evidentes; el
# apellido no puede estar en el vocabulario tecnico de ADR-048.
readonly ERROR_NAME="Rosaura Ficticiana"
readonly ERROR_SURNAME="Ficticiana"
# 32 hexadecimales con letras y cifras: un `trace_id` que el saneado protege y el
# paquete anonimizado conserva. Es lo que identifica el grupo sembrado.
readonly ERROR_TRACE="b19ce0000000000000000000000c1a01"
readonly RESANITIZE_MIGRATION="2026_10_03_100300_resanitize_error_history"
readonly RESANITIZED_EVENT="product.error_history_resanitized"
readonly UPDATE_LOG_DIR="${KRONOQR_LOG_DIR:-/var/log/kronoqr}"
readonly DIAGNOSTICS_FILE="/tmp/kq-ci-diagnostico-b19.json"

fail() {
  printf 'FALLO (%s): %s\n' "${MODE}" "$*" >&2
  exit 1
}

as_root() {
  if [ -n "${SUDO}" ]; then
    "${SUDO}" "$@"
  else
    "$@"
  fi
}

dc() {
  as_root docker compose --env-file "${PKG}/.env" -f "${PKG}/docker-compose.yml" "$@"
}

backup_path() {
  as_root sed -n 's/^BACKUP_PATH=//p' "${PKG}/.env" | tail -n 1
}

# Valor de una variable del .env del paquete, o el de serie si no esta.
env_value() {
  local value
  value="$(as_root sed -n "s/^$1=//p" "${PKG}/.env" | tail -n 1)"
  printf '%s\n' "${value:-$2}"
}

# Una consulta con el rol de migracion, como las etapas de conteo de ci.yml.
sql() {
  dc exec -T postgres psql -v ON_ERROR_STOP=1 \
    -U "$(env_value DB_MIGRATION_USERNAME fichaje_migrator)" \
    -d "$(env_value DB_DATABASE fichaje)" -Atqc "$1"
}

# Cuantas filas de `error_events` llevan el apellido sembrado en algun texto.
error_rows_with_name() {
  sql "SELECT count(*) FROM error_events
       WHERE concat_ws(' ', message, context::text, code, exception_class, file, app_version)
             ILIKE '%${ERROR_SURNAME}%'"
}

# ¿Alguno de los detalles de la actualizacion contiene el texto? Sin tuberia:
# con pipefail, un `grep -q` que corta antes da un falso fallo.
detail_has() {
  # shellcheck disable=SC2016 # `$1` y `$2` son del `sh -c`; el comodin lo expande root.
  as_root sh -c 'grep -qF -- "$1" "$2"/update-*.detalle.log' sh "$1" "${UPDATE_LOG_DIR}"
}

seed_error_history() {
  local applied
  applied="$(sql "SELECT count(*) FROM migrations WHERE migration = '${RESANITIZE_MIGRATION}'")" ||
    fail "no se ha podido consultar la tabla migrations de la version anterior"
  if [ "${applied}" != "0" ]; then
    printf 'La version anterior ya trae %s (ADR-048): no hay historico anterior que volver a sanear.\n' \
      "${RESANITIZE_MIGRATION}"
    return 0
  fi

  sql "INSERT INTO error_events (fingerprint, level, source, module, message, exception_class, context,
         trace_id, app_version, occurrences, first_seen_at, last_seen_at, created_at, updated_at)
       VALUES (encode(sha256('kronoqr-ci-b19'::bytea), 'hex'), 'error', 'api', 'attendance',
         'no se pudo cerrar el turno de ${ERROR_NAME}', 'RuntimeException',
         jsonb_build_object('reason', 'la credencial de ${ERROR_NAME} no resuelve', 'route', 'api/v1/scan'),
         '${ERROR_TRACE}', '2.1.0', 3, now(), now(), now(), now())" >/dev/null ||
    fail "no se ha podido sembrar error_events en la version anterior"
  [ "$(error_rows_with_name)" = "1" ] ||
    fail "la fila sembrada en error_events no lleva el nombre ${ERROR_NAME}"
  printf 'Sembrado: un grupo de error_events con %s en message y en context (trace_id %s)\n' \
    "${ERROR_NAME}" "${ERROR_TRACE}"
}

check_error_history() {
  local left
  left="$(error_rows_with_name)" || fail "no se ha podido consultar error_events tras actualizar"
  [ "${left}" = "0" ] ||
    fail "H6: ${left} fila(s) de error_events siguen llevando ${ERROR_SURNAME} despues de actualizar"

  if ! detail_has "${RESANITIZE_MIGRATION}"; then
    printf 'Historico de errores: %s no se ha ejecutado en esta actualizacion (la version anterior ya la traia).\n' \
      "${RESANITIZE_MIGRATION}"
    return 0
  fi

  detail_has "${RESANITIZED_EVENT}" ||
    fail "H6: el detalle de la actualizacion en ${UPDATE_LOG_DIR} no lleva ${RESANITIZED_EVENT} (¿LOG_LEVEL por encima de info?)"
  [ "$(sql "SELECT count(*) FROM error_events WHERE trace_id = '${ERROR_TRACE}'")" = "1" ] ||
    fail "H6: el grupo sembrado (trace_id ${ERROR_TRACE}) ha desaparecido: volver a sanear no borra"

  dc exec -T app php artisan product:diagnostics --output="${DIAGNOSTICS_FILE}" >/dev/null ||
    fail "no se ha podido generar el paquete de diagnostico anonimizado"
  if dc exec -T app grep -qiF -- "${ERROR_SURNAME}" "${DIAGNOSTICS_FILE}"; then
    fail "H6: el paquete de diagnostico anonimizado lleva ${ERROR_SURNAME}"
  fi
  dc exec -T app grep -qF -- "${ERROR_TRACE}" "${DIAGNOSTICS_FILE}" ||
    fail "el paquete de diagnostico no lleva el grupo sembrado (trace_id ${ERROR_TRACE}): la ausencia del nombre no prueba nada"
  dc exec -T app rm -f "${DIAGNOSTICS_FILE}"

  printf 'Historico de errores comprobado: %s saneado en error_events, %s en el detalle, y fuera del paquete anonimizado.\n' \
    "${ERROR_NAME}" "${RESANITIZED_EVENT}"
}

seed() {
  local reports
  reports="$(backup_path)/reports/retention"

  # shellcheck disable=SC2016 # `$1`..`$6` son del `sh -c` del contenedor.
  dc exec -T scheduler sh -c '
    set -eu
    mkdir -p "$1/sub"
    printf "propuesta de la 2.1.0\n" >"$1/$2"
    printf "purga de la 2.1.0\n" >"$1/$3"
    ln -s /etc/passwd "$1/$4"
    printf "no es un informe\n" >"$1/$5"
    printf "anidado\n" >"$1/sub/$6"
  ' sh "${OLD_DIR}" "${RESCUED}" "${PLANTED}" "${CONTAINER_LINK}" "${OTHER}" "${NESTED}" ||
    fail "no se ha podido sembrar ${OLD_DIR} en scheduler"

  printf '%s\n' "${VICTIM_TEXT}" | as_root tee "${VICTIM}" >/dev/null
  as_root chown root:root "${VICTIM}"
  as_root chmod 0600 "${VICTIM}"

  # El runtime escribe en BACKUP_PATH: el enlace lo planta `app`, como uid 1000.
  # shellcheck disable=SC2016 # `$1`..`$3` son del `sh -c` del contenedor.
  dc exec -T app sh -c 'set -eu; mkdir -p "$1"; ln -s "$2" "$1/$3"' \
    sh "${reports}" "${VICTIM}" "${PLANTED}" ||
    fail "no se ha podido plantar el enlace en ${reports} desde app"
  printf 'Sembrado: %s en scheduler y un enlace a %s en %s\n' "${OLD_DIR}" "${VICTIM}" "${reports}"

  seed_error_history
}

check() {
  local reports
  reports="$(backup_path)/reports/retention"

  as_root ls -la "${reports}"

  as_root test -f "${reports}/${RESCUED}" -a ! -L "${reports}/${RESCUED}" ||
    fail "${RESCUED} no se ha rescatado como fichero regular"
  [ "$(as_root stat -c '%u:%g %a' "${reports}/${RESCUED}")" = "1000:1000 640" ] ||
    fail "${RESCUED} es $(as_root stat -c '%u:%g %a' "${reports}/${RESCUED}"), y debe ser 1000:1000 640"
  [ "$(as_root cat "${reports}/${RESCUED}")" = "propuesta de la 2.1.0" ] ||
    fail "${RESCUED} no tiene el contenido del contenedor"

  as_root test -L "${reports}/${PLANTED}" || fail "el enlace plantado ${PLANTED} se ha sustituido"
  [ "$(as_root stat -c '%U:%G %a' "${VICTIM}")" = "root:root 600" ] ||
    fail "F1: la victima ${VICTIM} ha cambiado a $(as_root stat -c '%U:%G %a' "${VICTIM}")"
  [ "$(as_root cat "${VICTIM}")" = "${VICTIM_TEXT}" ] || fail "F1: se ha escrito en la victima ${VICTIM}"

  local name
  for name in "${CONTAINER_LINK}" "${OTHER}" "${NESTED}"; do
    if as_root test -e "${reports}/${name}" || as_root test -L "${reports}/${name}"; then
      fail "se ha rescatado ${name}, que no es un informe regular de un nivel"
    fi
  done

  as_root rm -f "${VICTIM}"
  printf 'Rescate comprobado: %s 1000:1000 0640; enlace plantado intacto; victima intacta; nada mas copiado.\n' "${RESCUED}"

  check_error_history
}

case "${MODE}" in
seed) seed ;;
check) check ;;
*)
  printf 'retention-rescue-e2e.sh: modo desconocido %s (seed|check)\n' "${MODE}" >&2
  exit 2
  ;;
esac
