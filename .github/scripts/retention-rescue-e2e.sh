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
}

case "${MODE}" in
seed) seed ;;
check) check ;;
*)
  printf 'retention-rescue-e2e.sh: modo desconocido %s (seed|check)\n' "${MODE}" >&2
  exit 2
  ;;
esac
