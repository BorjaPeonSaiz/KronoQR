#!/usr/bin/env bash
#
# KronoQR — actualizacion de una instalacion existente (RF-PD-10, RQ-11).
#
# PROPOSITO. El IT del hotel ejecuta este script desde el paquete de una version
# nueva y su instalacion pasa de la version que tenga a esta, encadenando las
# intermedias, con copia previa verificada como paso bloqueante y VUELTA ATRAS
# AUTOMATICA si algo falla. El fichaje no se detiene: los quioscos encolan.
#
# ES EL SCRIPT CON MAS RIESGO DE PERDIDA DE DATOS DEL PRODUCTO (doc 02 §11.2),
# y de ahi las cuatro decisiones que lo gobiernan:
#
#   · LA COPIA ES BLOQUEANTE Y NO TIENE BANDERA PARA OMITIRLA. Ni --skip-backup,
#     ni --force, ni una variable de entorno. El atajo que exista es el que se
#     usara en la actualizacion que salga mal.
#   · EL MANTENIMIENTO VA ANTES DE LA COPIA, y no al reves como enumera el
#     §11.6.4. Con la copia primero, un fichaje aceptado entre la copia y el
#     mantenimiento existiria en la base y no en la copia: la vuelta atras lo
#     borraria, y el quiosco ya lo habria sacado de su cola porque el servidor
#     lo confirmo. Con el mantenimiento primero, todo lo que ocurre durante la
#     ventana sigue en las colas de los quioscos y entra despues, gane o
#     pierda la actualizacion. Es la unica desviacion del orden literal.
#   · LA VERSION NUEVA NO RECIBE TRAFICO HASTA ESTAR VERIFICADA. Se arranca sin
#     borde ni procesos de fondo, se sonda por FastCGI desde dentro del
#     contenedor, se pone EN MANTENIMIENTO (su contenedor es nuevo y nace sin
#     el), se publica el borde, se sonda por loopback, y solo entonces se
#     retira el mantenimiento y arrancan horizon, scheduler y reverb. Un
#     fichaje aceptado por una version que despues se deshace seria el mismo
#     fallo de arriba por otro camino.
#   · LA VUELTA ATRAS ES SIEMPRE RESTAURAR LA COPIA, nunca `migrate:rollback`.
#     El `down()` de una migracion de contraccion no devuelve los datos que
#     quito, y la restauracion es lo unico que se ensaya cada trimestre
#     (restore-drill.sh). El PUNTO DE CONTROL entre versiones es una marca
#     persistida en el informe y un lote de la tabla `migrations` por version:
#     dice exactamente donde se paro, no pretende deshacer solo esa version.
#
# LOS SIETE PASOS (doc 02 §11.6.4; el 2 y el 3 intercambiados por lo de arriba):
#   1  Precondiciones. NO SE TOCA LA INSTALACION. Version de origen dentro de
#      la matriz (versions.txt), espacio, servicios sanos, cadena de auditoria
#      integra, clave de copia presente. Cada fallo dice QUE HACER.
#   2  Mantenimiento: la API de gestion responde 503; horizon y scheduler
#      parados. Los quioscos encolan (regla dura 19).
#   3  Copia logica cifrada y verificada, bloqueante, DE ESTA EJECUCION.
#   4  Migraciones version a version, con un punto de control entre cada una.
#   5  Arranque de la version nueva SIN borde, verificacion desde dentro
#      —incluido `product:doctor`, tarea 5.9, INFORMATIVO aqui: se muestra y se
#      resume, pero solo deshace si el comando falta en la imagen—,
#      mantenimiento, borde, verificacion por loopback, y solo despues el
#      resto de servicios.
#   6  Si algo falla en el 4 o en el 5: restauracion de la copia y relanzamiento
#      de la version anterior, sin intervencion humana.
#   7  Informe en BACKUP_PATH/reports/, siempre, tambien tras una vuelta atras.
#      DOS FICHEROS: el informe (resumen, sin secretos ni datos personales, para
#      el paquete de diagnostico) y el detalle tecnico (salida cruda de
#      migraciones, copia y logs; solo root; puede llevar datos personales).
#
# USO
#   ./update.sh                    actualiza a la version de este paquete
#   ./update.sh --check-only       solo el paso 1; no toca nada
#   ./update.sh --supported-sources
#   ./update.sh --chain 2.0.0
#   ./update.sh --help
#
# Ejecutalo dentro de `tmux` o `screen`, o con `nohup`: una sesion SSH que se
# corta a mitad dispara la vuelta atras (se atrapa SIGHUP), pero el mensaje
# final se iria con la conexion y solo quedaria el informe.
#
# CODIGOS DE SALIDA (tabla UNICA de lib/exit-codes.sh y docs/cliente/operacion.md)
#   0  Actualizado y verificado.
#   1  Uso incorrecto. Nada tocado.
#   2  Precondicion no cumplida, o COPIA PREVIA FALLIDA. La instalacion no se ha
#      tocado y sigue en su version; si estaba en mantenimiento, se retira.
#   3  Ya esta en la version de destino, o no hay instalacion que actualizar.
#      Nada tocado.
#   4  Fallo con VUELTA ATRAS COMPLETADA: copia restaurada y version anterior
#      en marcha y verificada. Se puede reintentar tras leer el informe.
#   5  Fallo con VUELTA ATRAS INCOMPLETA. Hace falta una persona; el mensaje
#      imprime las ordenes exactas (y distingue si solo queda retirar el
#      mantenimiento de si hay que restaurar la copia).
#   6  CASI NUNCA LO USA ESTE SCRIPT: toda verificacion fallida deshace
#      (RF-PD-10), con UNA excepcion documentada: un `product:doctor` con
#      fallos o avisos (tarea 5.9, segunda vuelta) es informativo y NO
#      deshace, porque la version nueva ya esta verificada por las sondas, la
#      cadena y los privilegios; solo deshace si el comando falta en la
#      imagen, que es un paquete roto.
#      LA OTRA EXCEPCION (tarea 5.7, cierre): la actualizacion termino de
#      verdad —version nueva en marcha, servicios sanos— pero el asiento
#      `system.updated` de `audit_log` (RF-PD-10, regla dura 6) no se pudo
#      escribir. El trabajo hecho no se deshace por eso: se deja escrito en el
#      informe («audit-entry» con el codigo) y se sale con `6` en vez de `0`.
#
# Que NO hace, a proposito:
#   · No exige licencia. Una licencia caducada no puede dejar a un cliente sin
#     correcciones de seguridad sobre su registro legal (regla dura 15).
#   · No regenera ningun secreto ni imprime ninguno (§7.7).
#   · No borra la version anterior: su directorio queda intacto para volver.
#   · No retrocede versiones.

set -Eeuo pipefail
IFS=$'\n\t'

SCRIPT_DIR="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"
readonly SCRIPT_DIR

# shellcheck source-path=SCRIPTDIR
# shellcheck source=lib/exit-codes.sh disable=SC1091
. "${SCRIPT_DIR}/lib/exit-codes.sh"
# shellcheck source=lib/messages.sh disable=SC1091
. "${SCRIPT_DIR}/lib/messages.sh"
# shellcheck source=lib/messages-update.sh disable=SC1091
. "${SCRIPT_DIR}/lib/messages-update.sh"
# shellcheck source=lib/checks.sh disable=SC1091
. "${SCRIPT_DIR}/lib/checks.sh"
# shellcheck source=lib/env-file.sh disable=SC1091
. "${SCRIPT_DIR}/lib/env-file.sh"
# shellcheck source=lib/fs.sh disable=SC1091
. "${SCRIPT_DIR}/lib/fs.sh"

# Las comparaciones de nombres de migracion y de fronteras usan `[[ a < b ]]`,
# cuyo orden depende de la colacion. Se fija a la de bytes para que no dependa
# del servidor; el idioma de los mensajes se decide con LC_MESSAGES/LANG y no
# se ve afectado.
export LC_COLLATE=C

#------------------------------------------------------------------------------
# Constantes
#------------------------------------------------------------------------------
readonly KQ_COMPOSE_PROJECT="kronoqr"
readonly KQ_POLL_SECONDS=2
readonly KQ_WAIT_DEPENDENCIES=180
readonly KQ_WAIT_APPLICATION=180
readonly KQ_WAIT_WORKERS=90
# Matriz de versiones soportadas (doc 02 §11.6.5): la menor vigente y las DOS
# anteriores.
readonly KQ_SUPPORT_WINDOW_MINORS=2
# uid con el que corre la aplicacion en su contenedor; es quien lee el informe
# al armar el paquete de diagnostico (tarea 5.9).
readonly KQ_APP_UID=1000
readonly KQ_CONTAINER_MIGRATIONS="/var/www/html/database/migrations"
readonly KQ_CONTAINER_SCRIPTS="/opt/kronoqr/scripts"
readonly KQ_MIN_DOCKER_FREE_GIB=2
readonly KQ_MAINTENANCE_RETRY_SECONDS=60
# Huella sha256 de la copia previa para el asiento de auditoria (tarea 5.7,
# cierre): OPCIONAL a proposito. Un volcado de varios GiB puede tardar mas de
# lo razonable, y un asiento sin huella vale infinitamente mas que ningun
# asiento: si no termina en este tiempo, se omite el campo.
readonly KQ_BACKUP_FINGERPRINT_TIMEOUT_SECONDS=20
# Restricciones de RN-01 y RN-02 que la verificacion posterior exige presentes
# y validas (doc 02 §9.4, invariantes de base de datos).
readonly KQ_RN01_INDEX="one_open_shift_per_employee"
readonly KQ_RN02_CONSTRAINT="shift_entries_no_overlap"
# Una ruta de gestion cualquiera: sin sesion responde 401. Es la que demuestra
# que el mantenimiento se ha retirado de verdad, porque las dos sondas quedan
# fuera del 503 a proposito y no lo distinguen.
readonly KQ_MANAGEMENT_PROBE="/api/v1/auth/me"

#------------------------------------------------------------------------------
# Estado
#------------------------------------------------------------------------------
OPT_CHECK_ONLY=0
OPT_LANG=""
OPT_CURRENT=""
OPT_COMPOSE_FILE=""
OPT_INFO=""
OPT_INFO_ARG=""

# El paquete NUEVO, desde el que se ejecuta este script.
PACKAGE_DIR=""
COMPOSE_FILE=""
ENV_FILE=""
ENV_TEMPLATE=""
VERSIONS_FILE=""
TARGET_VERSION=""
DOCS_DIR=""

# La instalacion ACTUAL.
CURRENT_DIR=""
CURRENT_COMPOSE=""
CURRENT_ENV=""
SOURCE_VERSION=""
IN_PLACE=0
# Con que se relanza la version anterior en una vuelta atras. Con el paquete al
# lado, es la instalacion actual tal cual; encima de ella, el compose nuevo con
# la copia previa del .env.
ROLLBACK_COMPOSE=""
ROLLBACK_ENV=""

# Valores leidos del .env de la instalacion. Nunca secretos.
CFG_HTTPS_PORT="443"
CFG_BACKUP_PATH="/var/backups/fichaje"
CFG_TLS_CERT_DIR=""
CFG_DB_DATABASE="fichaje"
CFG_DB_USERNAME="fichaje_app"
CFG_DB_MIGRATION_USERNAME="fichaje_migrator"

# Matriz de versiones (versions.txt).
declare -a KQ_VERSIONS=()
declare -A KQ_VERSION_BOUNDARY=()
KQ_VERSIONS_ERROR=""
declare -a UPGRADE_CHAIN=()

# Ejecucion.
DOCKER_OK=0
CHECKS_RUN=0
CHECKS_FAILED=0
CHECKS_WARNED=0
REPORT_FILE=""
DETAIL_FILE=""
REPORT_CLOSED=0
LOCK_DIR=""
LOCK_OWNED=0
STARTED_AT=0
STARTED_UTC=""
MAINTENANCE_SINCE=0
MAINTENANCE_SECONDS=0
BACKUP_FILE=""
BACKUP_RESULT=""
MIGRATIONS_IN_IMAGE=""
LAST_CHECKPOINT=""
STEP="1"
# 0 nada que deshacer · 1 mantenimiento puesto (retirar) · 2 esquema o
# contenedores tocados (restaurar la copia y relanzar la version anterior).
ROLLBACK_ARMED=0
ROLLBACK_SUMMARY=""
FINAL_STATE=""
# Segunda mitad del paso 5 (RF-PD-10, SystemUpdateStep): 0 mientras se arranca
# y se verifica sin exponer · 1 desde que el borde (nginx) se abre de verdad.
# Decide si un fallo de ahi en adelante es `start_and_verify` o `expose`.
STEP5_EXPOSED=0
# El asiento `system.updated`/`system.restored_from_backup` de audit_log
# (RF-PD-10, RL-04, RS-07, regla dura 6). Vacio = aun no se ha intentado.
MIGRATIONS_APPLIED=0
BACKUP_TAKEN_AT=""
BACKUP_SHA256=""
CHAIN_BEFORE=""
CHAIN_AFTER=""
CHAIN_DISCARDED=""
# 1 si la actualizacion termino de verdad pero su asiento `system.updated` no
# se pudo escribir: el trabajo no se deshace por eso, pero final_report()
# sale con KQ_EXIT_VERIFY_FAILED (6) en vez de KQ_EXIT_OK.
AUDIT_ENTRY_FAILED=0
declare -a CHECKPOINTS=()
declare -a REPORT_CHECKS=()

#------------------------------------------------------------------------------
# Salida. Todo lo que ve el operador va tambien al INFORME (paso 7): es lo que
# adjunta al paquete de diagnostico, y un informe que solo tuviera el resumen
# obligaria a una segunda ronda de preguntas. Los volcados largos —salida de
# migrate, de la copia, de restore.sh, logs de un contenedor— van al DETALLE,
# un segundo fichero solo de root: pueden llevar datos personales (regla dura
# 21: el `DETAIL: Failing row contains (...)` de PostgreSQL, por ejemplo) y no
# deben viajar al fabricante sin que alguien los lea antes.
#------------------------------------------------------------------------------
report_append() {
  [ -n "${REPORT_FILE}" ] && [ "${REPORT_CLOSED}" -eq 0 ] || return 0
  printf '%s\n' "$*" >>"${REPORT_FILE}" 2>/dev/null || true
}

# Donde van los volcados. Antes de abrir el informe, a ninguna parte.
detail_sink() {
  printf '%s' "${DETAIL_FILE:-/dev/null}"
}

say() {
  printf '%s\n' "$*"
  report_append "$*"
}

heading() {
  printf '\n%s\n' "$*"
  report_append ""
  report_append "$*"
}

err() {
  printf '%s\n' "$*" >&2
  report_append "$*"
}

# Solo al detalle: una linea de contexto entre volcados.
detail_note() {
  [ -n "${DETAIL_FILE}" ] || return 0
  printf '%s\n' "$*" >>"${DETAIL_FILE}" 2>/dev/null || true
}

# Los mensajes del catalogo tambien van al informe. Se redefine aqui la
# funcion de lib/messages.sh, que solo imprime: sin esto, cada [ok]/[FALLA] del
# paso 1 y cada comprobacion del paso 5 quedaria fuera del fichero, que es
# justo lo que la tarea 5.9 va a meter en el paquete de diagnostico.
kq_msg() {
  local key="$1" line
  shift
  line="$(kq_format "${key}" "$@")"
  printf '%s\n' "${line}"
  report_append "${line}"
}

timestamp_utc() {
  date -u +%Y%m%dT%H%M%SZ
}

now_epoch() {
  date +%s
}

remember_check() {
  REPORT_CHECKS+=("$1|$2")
}

# Termina con un codigo de la tabla comun, cerrando el informe.
die() {
  local code="$1"
  shift
  err ""
  err "ERROR: $*"
  err "$(kq_format exit_line "${code}" "$(kq_exit_name "${code}")")"
  close_report "${code}"
  exit "${code}"
}

cleanup_on_exit() {
  if [ "${LOCK_OWNED}" -eq 1 ] && [ -n "${LOCK_DIR}" ]; then
    rm -rf "${LOCK_DIR}" 2>/dev/null || true
  fi
  return 0
}

trap cleanup_on_exit EXIT

#------------------------------------------------------------------------------
# Versiones. Funciones PURAS: sin Docker, sin ficheros salvo versions.txt. Son
# las que ejercita la prueba de integracion cargando este script sin ejecutarlo.
#------------------------------------------------------------------------------

# SemVer 2.0.0: nucleo obligatorio, preliberacion y metadatos opcionales.
kq_semver_valid() {
  [[ "$1" =~ ^[0-9]+\.[0-9]+\.[0-9]+(-[0-9A-Za-z.-]+)?(\+[0-9A-Za-z.-]+)?$ ]]
}

kq_semver_major() {
  local core="${1%%-*}"
  core="${core%%+*}"
  printf '%s' "${core%%.*}"
}

kq_semver_minor() {
  local core="${1%%-*}"
  core="${core%%+*}"
  core="${core#*.}"
  printf '%s' "${core%%.*}"
}

kq_semver_patch() {
  local core="${1%%-*}"
  core="${core%%+*}"
  printf '%s' "${core##*.}"
}

# Compara dos identificadores de preliberacion segun la regla 11 de SemVer:
# numericos entre si por valor, numerico < alfanumerico, alfanumericos en orden
# ASCII. Imprime -1, 0 o 1.
kq_semver_compare_identifier() {
  local a="$1" b="$2"

  if [[ "${a}" =~ ^[0-9]+$ ]] && [[ "${b}" =~ ^[0-9]+$ ]]; then
    if [ "${a}" -lt "${b}" ]; then printf -- '-1'; elif [ "${a}" -gt "${b}" ]; then printf '1'; else printf '0'; fi
    return 0
  fi
  if [[ "${a}" =~ ^[0-9]+$ ]]; then
    printf -- '-1'
    return 0
  fi
  if [[ "${b}" =~ ^[0-9]+$ ]]; then
    printf '1'
    return 0
  fi
  if [[ "${a}" < "${b}" ]]; then printf -- '-1'; elif [[ "${a}" > "${b}" ]]; then printf '1'; else printf '0'; fi
}

# Imprime -1 si A < B, 0 si son la misma version, 1 si A > B. Los metadatos de
# construccion (+...) no cuentan. Una preliberacion es MENOR que su version
# final: 2.1.0-rc.1 < 2.1.0.
kq_semver_compare() {
  local a="${1%%+*}" b="${2%%+*}"
  local a_core="${a%%-*}" b_core="${b%%-*}"
  local a_pre="" b_pre=""
  [ "${a}" != "${a_core}" ] && a_pre="${a#*-}"
  [ "${b}" != "${b_core}" ] && b_pre="${b#*-}"

  local part
  for part in major minor patch; do
    local x y
    x="$("kq_semver_${part}" "${a_core}")"
    y="$("kq_semver_${part}" "${b_core}")"
    if [ "${x}" -lt "${y}" ]; then
      printf -- '-1'
      return 0
    fi
    if [ "${x}" -gt "${y}" ]; then
      printf '1'
      return 0
    fi
  done

  if [ -z "${a_pre}" ] && [ -z "${b_pre}" ]; then
    printf '0'
    return 0
  fi
  if [ -z "${a_pre}" ]; then
    printf '1'
    return 0
  fi
  if [ -z "${b_pre}" ]; then
    printf -- '-1'
    return 0
  fi

  local -a ids_a ids_b
  IFS='.' read -r -a ids_a <<<"${a_pre}"
  IFS='.' read -r -a ids_b <<<"${b_pre}"
  local i result
  for ((i = 0; i < ${#ids_a[@]} && i < ${#ids_b[@]}; i++)); do
    result="$(kq_semver_compare_identifier "${ids_a[i]}" "${ids_b[i]}")"
    if [ "${result}" != "0" ]; then
      printf '%s' "${result}"
      return 0
    fi
  done
  if [ "${#ids_a[@]}" -lt "${#ids_b[@]}" ]; then printf -- '-1'; elif [ "${#ids_a[@]}" -gt "${#ids_b[@]}" ]; then printf '1'; else printf '0'; fi
}

# Carga versions.txt en KQ_VERSIONS (orden del fichero) y KQ_VERSION_BOUNDARY.
# Devuelve 1 y deja el motivo en KQ_VERSIONS_ERROR si el fichero no cumple su
# contrato: una version por linea, ascendentes, fronteras que son nombres de
# migracion tambien ascendentes, `-` o `*`, y `*` solo en la ultima linea.
kq_versions_load() {
  local file="$1" line version boundary previous="" previous_boundary="" number=0 star_at=""

  KQ_VERSIONS=()
  KQ_VERSION_BOUNDARY=()
  KQ_VERSIONS_ERROR=""

  [ -f "${file}" ] || {
    KQ_VERSIONS_ERROR="missing"
    return 1
  }

  while IFS= read -r line || [ -n "${line}" ]; do
    number=$((number + 1))
    line="${line%%#*}"
    line="${line//$'\t'/ }"
    [ -n "${line// /}" ] || continue

    # IFS del script es tab+salto de linea: aqui se divide por espacios a
    # proposito, que es el formato del fichero.
    local extra=""
    IFS=' ' read -r version boundary extra <<<"${line}"
    if [ -z "${boundary}" ] || [ -n "${extra}" ]; then
      KQ_VERSIONS_ERROR="line ${number}: expected '<version> <boundary>'"
      return 1
    fi
    if ! kq_semver_valid "${version}"; then
      KQ_VERSIONS_ERROR="line ${number}: '${version}' is not a version"
      return 1
    fi
    if [ -n "${KQ_VERSION_BOUNDARY[${version}]+x}" ]; then
      KQ_VERSIONS_ERROR="line ${number}: '${version}' listed twice"
      return 1
    fi
    if [ -n "${previous}" ] && [ "$(kq_semver_compare "${previous}" "${version}")" != "-1" ]; then
      KQ_VERSIONS_ERROR="line ${number}: '${version}' is not later than '${previous}'"
      return 1
    fi
    case "${boundary}" in
    '*')
      star_at="${version}"
      ;;
    '-') ;;
    *)
      if ! [[ "${boundary}" =~ ^[0-9]{4}_[0-9]{2}_[0-9]{2}_[0-9]{6}_[a-z0-9_]+$ ]]; then
        KQ_VERSIONS_ERROR="line ${number}: '${boundary}' is not a migration name"
        return 1
      fi
      if [ -n "${previous_boundary}" ] && ! [[ "${previous_boundary}" < "${boundary}" ]]; then
        KQ_VERSIONS_ERROR="line ${number}: boundary '${boundary}' is not later than '${previous_boundary}'"
        return 1
      fi
      previous_boundary="${boundary}"
      ;;
    esac
    if [ -n "${star_at}" ] && [ "${star_at}" != "${version}" ]; then
      KQ_VERSIONS_ERROR="'*' is only allowed on the last line (found at ${star_at})"
      return 1
    fi

    KQ_VERSIONS+=("${version}")
    KQ_VERSION_BOUNDARY[${version}]="${boundary}"
    previous="${version}"
  done <"${file}"

  [ "${#KQ_VERSIONS[@]}" -gt 0 ] || {
    KQ_VERSIONS_ERROR="no versions listed"
    return 1
  }
  return 0
}

kq_version_listed() {
  [ -n "${KQ_VERSION_BOUNDARY[$1]+x}" ]
}

# Versiones publicadas desde las que se puede actualizar DIRECTAMENTE a la de
# destino: misma mayor, menor igual o hasta KQ_SUPPORT_WINDOW_MINORS por
# debajo, y anteriores a ella. Una por linea.
kq_supported_sources() {
  local target="$1" version t_major t_minor floor
  t_major="$(kq_semver_major "${target}")"
  t_minor="$(kq_semver_minor "${target}")"
  floor=$((t_minor - KQ_SUPPORT_WINDOW_MINORS))

  for version in ${KQ_VERSIONS+"${KQ_VERSIONS[@]}"}; do
    [ "$(kq_semver_compare "${version}" "${target}")" = "-1" ] || continue
    [ "$(kq_semver_major "${version}")" = "${t_major}" ] || continue
    [ "$(kq_semver_minor "${version}")" -ge "${floor}" ] || continue
    printf '%s\n' "${version}"
  done
}

kq_is_supported_source() {
  local from="$1" target="$2" version
  while IFS= read -r version; do
    [ "${version}" = "${from}" ] && return 0
  done < <(kq_supported_sources "${target}")
  return 1
}

# Versiones intermedias que se aplican en orden: las publicadas posteriores al
# origen y hasta el destino, inclusive. Una por linea.
kq_upgrade_chain() {
  local from="$1" target="$2" version
  for version in ${KQ_VERSIONS+"${KQ_VERSIONS[@]}"}; do
    [ "$(kq_semver_compare "${version}" "${from}")" = "1" ] || continue
    [ "$(kq_semver_compare "${version}" "${target}")" != "1" ] || continue
    printf '%s\n' "${version}"
  done
}

# A que version hay que ir PRIMERO cuando el salto directo no esta soportado.
# Imprime `major <ultima de la serie del origen>` si cambia la version mayor y
# el origen no es ya esa ultima, `major-last` si lo es, la version intermedia
# mas alta alcanzable desde el origen si la hay, o `none`.
kq_first_hop() {
  local from="$1" target="$2" version best="" f_major f_minor ceiling
  f_major="$(kq_semver_major "${from}")"
  f_minor="$(kq_semver_minor "${from}")"
  ceiling=$((f_minor + KQ_SUPPORT_WINDOW_MINORS))

  if [ "${f_major}" != "$(kq_semver_major "${target}")" ]; then
    for version in ${KQ_VERSIONS+"${KQ_VERSIONS[@]}"}; do
      [ "$(kq_semver_major "${version}")" = "${f_major}" ] && best="${version}"
    done
    if [ -z "${best}" ] || [ "$(kq_semver_compare "${best}" "${from}")" != "1" ]; then
      printf 'major-last\n'
    else
      printf 'major %s\n' "${best}"
    fi
    return 0
  fi

  for version in ${KQ_VERSIONS+"${KQ_VERSIONS[@]}"}; do
    [ "$(kq_semver_compare "${version}" "${from}")" = "1" ] || continue
    [ "$(kq_semver_major "${version}")" = "${f_major}" ] || continue
    [ "$(kq_semver_minor "${version}")" -le "${ceiling}" ] || continue
    best="${version}"
  done
  printf '%s\n' "${best:-none}"
}

# Frontera EFECTIVA de una version: la suya, o la de la anterior si esta no
# anadio migraciones (`-`). Imprime `*` para la version en desarrollo y la
# cadena vacia para «desde el principio».
kq_effective_boundary() {
  local wanted="$1" version boundary carried=""
  for version in ${KQ_VERSIONS+"${KQ_VERSIONS[@]}"}; do
    boundary="${KQ_VERSION_BOUNDARY[${version}]}"
    case "${boundary}" in
    '-') ;;
    *) carried="${boundary}" ;;
    esac
    if [ "${version}" = "${wanted}" ]; then
      printf '%s' "${carried}"
      return 0
    fi
  done
  return 1
}

# Frontera efectiva de la version listada JUSTO ANTES de la indicada.
kq_previous_boundary() {
  local wanted="$1" version previous=""
  for version in ${KQ_VERSIONS+"${KQ_VERSIONS[@]}"}; do
    if [ "${version}" = "${wanted}" ]; then
      [ -n "${previous}" ] && kq_effective_boundary "${previous}"
      return 0
    fi
    previous="${version}"
  done
  return 1
}

# Migraciones (nombres, una por linea, leidas de la entrada estandar) que
# pertenecen a la version indicada: posteriores a la frontera de la version
# anterior y hasta la suya, inclusive.
kq_migrations_for_version() {
  local version="$1" lower upper name
  lower="$(kq_previous_boundary "${version}")"
  upper="$(kq_effective_boundary "${version}")"

  while IFS= read -r name; do
    [ -n "${name}" ] || continue
    [ -z "${lower}" ] || [[ "${name}" > "${lower}" ]] || continue
    if [ "${upper}" != "*" ]; then
      [[ "${name}" < "${upper}" ]] || [ "${name}" = "${upper}" ] || continue
    fi
    printf '%s\n' "${name}"
  done
}

#------------------------------------------------------------------------------
# Argumentos
#------------------------------------------------------------------------------
parse_arguments() {
  while [ "$#" -gt 0 ]; do
    case "$1" in
    --check-only)
      OPT_CHECK_ONLY=1
      ;;
    --supported-sources)
      OPT_INFO="sources"
      ;;
    --chain)
      [ "$#" -ge 2 ] || die "${KQ_EXIT_USAGE}" "$(kq_format missing_value "--chain")"
      OPT_INFO="chain"
      OPT_INFO_ARG="$2"
      shift
      ;;
    --chain=*)
      OPT_INFO="chain"
      OPT_INFO_ARG="${1#--chain=}"
      ;;
    --current)
      [ "$#" -ge 2 ] || die "${KQ_EXIT_USAGE}" "$(kq_format missing_value "--current")"
      OPT_CURRENT="$2"
      shift
      ;;
    --current=*)
      OPT_CURRENT="${1#--current=}"
      ;;
    --compose-file)
      [ "$#" -ge 2 ] || die "${KQ_EXIT_USAGE}" "$(kq_format missing_value "--compose-file")"
      OPT_COMPOSE_FILE="$2"
      shift
      ;;
    --compose-file=*)
      OPT_COMPOSE_FILE="${1#--compose-file=}"
      ;;
    --lang)
      [ "$#" -ge 2 ] || die "${KQ_EXIT_USAGE}" "$(kq_format missing_value "--lang")"
      OPT_LANG="$2"
      shift
      ;;
    --lang=*)
      OPT_LANG="${1#--lang=}"
      ;;
    --help | -h)
      kq_msg_init "${OPT_LANG}"
      kq_msg u_usage
      exit "${KQ_EXIT_OK}"
      ;;
    --version)
      resolve_package_paths
      printf '%s\n' "${TARGET_VERSION:-$(kq_text version_unknown)}"
      exit "${KQ_EXIT_OK}"
      ;;
    *)
      kq_msg_init "${OPT_LANG}"
      die "${KQ_EXIT_USAGE}" "$(kq_format u_bad_option "$1")"
      ;;
    esac
    shift
  done

  case "${OPT_LANG}" in
  "" | es | en) ;;
  *)
    kq_msg_init ""
    die "${KQ_EXIT_USAGE}" "$(kq_format bad_lang "${OPT_LANG}")"
    ;;
  esac
}

#------------------------------------------------------------------------------
# Rutas del paquete NUEVO. Funciona dentro del repositorio (infra/scripts/,
# compose en infra/, VERSION y versions.txt en la raiz e infra/) y dentro del
# paquete de entrega (todo al lado del script).
#------------------------------------------------------------------------------
resolve_package_paths() {
  local candidate

  if [ -n "${OPT_COMPOSE_FILE}" ]; then
    COMPOSE_FILE="${OPT_COMPOSE_FILE}"
  else
    for candidate in \
      "${SCRIPT_DIR}/docker-compose.yml" \
      "${SCRIPT_DIR}/compose.prod.yaml" \
      "${SCRIPT_DIR}/../compose.prod.yaml"; do
      if [ -f "${candidate}" ]; then
        COMPOSE_FILE="$(cd -- "$(dirname -- "${candidate}")" && pwd)/$(basename -- "${candidate}")"
        break
      fi
    done
  fi

  PACKAGE_DIR="${SCRIPT_DIR}"
  [ -n "${COMPOSE_FILE}" ] && PACKAGE_DIR="$(cd -- "$(dirname -- "${COMPOSE_FILE}")" && pwd)"
  ENV_FILE="${PACKAGE_DIR}/.env"

  for candidate in "${PACKAGE_DIR}/.env.example" "${SCRIPT_DIR}/.env.example" "${PACKAGE_DIR}/../.env.example"; do
    [ -f "${candidate}" ] && ENV_TEMPLATE="${candidate}" && break
  done

  for candidate in "${PACKAGE_DIR}/VERSION" "${SCRIPT_DIR}/VERSION" "${PACKAGE_DIR}/../VERSION"; do
    if [ -f "${candidate}" ]; then
      TARGET_VERSION="$(head -n 1 "${candidate}" | tr -d '[:space:]')"
      break
    fi
  done

  for candidate in "${PACKAGE_DIR}/versions.txt" "${SCRIPT_DIR}/versions.txt" "${PACKAGE_DIR}/../infra/versions.txt" "${SCRIPT_DIR}/../versions.txt"; do
    [ -f "${candidate}" ] && VERSIONS_FILE="${candidate}" && break
  done
  [ -n "${VERSIONS_FILE}" ] || VERSIONS_FILE="${PACKAGE_DIR}/versions.txt"

  for candidate in "${PACKAGE_DIR}/docs" "${PACKAGE_DIR}/../docs/cliente"; do
    [ -d "${candidate}" ] && DOCS_DIR="${candidate}" && break
  done
  [ -n "${DOCS_DIR}" ] || DOCS_DIR="${PACKAGE_DIR}/docs"
}

env_value() {
  kq_env_value "$1" "$2"
}

#------------------------------------------------------------------------------
# Docker Compose: dos proyectos que son el MISMO (`name: kronoqr`), descritos
# desde dos directorios. Las tres funciones existen para que ninguna orden se
# escriba con el fichero equivocado.
#------------------------------------------------------------------------------
compose_current() {
  docker compose --env-file "${CURRENT_ENV}" -f "${CURRENT_COMPOSE}" "$@"
}

compose_new() {
  docker compose --env-file "${ENV_FILE}" -f "${COMPOSE_FILE}" "$@"
}

compose_rollback() {
  docker compose --env-file "${ROLLBACK_ENV}" -f "${ROLLBACK_COMPOSE}" "$@"
}

# Estado de un servicio: `healthy`, `running` (sin sonda) u otra cosa. awk
# colapsa los blancos, asi que un servicio sin sonda sale como "running" a
# secas y uno con sonda como "healthy running".
service_state() {
  local compose_fn="$1" service="$2"
  "${compose_fn}" ps --format '{{.Service}} {{.Health}} {{.State}}' 2>/dev/null |
    awk -v s="${service}" '$1 == s { print $2 " " $3 }' || true
}

service_is_healthy() {
  local state="$1"
  case "${state}" in
  "healthy "* | "running" | "running ") return 0 ;;
  esac
  return 1
}

# Espera por CONDICION, nunca por `sleep` a ciegas (misma regla que install.sh).
wait_for_healthy() {
  local compose_fn="$1" service="$2" timeout="$3" waited=0 state

  kq_msg waiting "${service}" "${timeout}"
  while [ "${waited}" -lt "${timeout}" ]; do
    state="$(service_state "${compose_fn}" "${service}")"
    if service_is_healthy "${state}"; then
      kq_msg waiting_ok "${service}"
      return 0
    fi
    sleep "${KQ_POLL_SECONDS}"
    waited=$((waited + KQ_POLL_SECONDS))
  done
  return 1
}

# Sonda por loopback a traves del borde. `--insecure` por el mismo motivo que
# en install.sh: se comprueba que la aplicacion responde, no que el nombre del
# certificado resuelva desde este servidor.
# `Accept: application/json` porque es lo que mandan las tres SPA y porque asi
# la respuesta a una ruta protegida sin sesion es 401 en cualquier version.
edge_probe() {
  local path="$1"
  curl --fail --silent --show-error --insecure --max-time 15 \
    --header 'Accept: application/json' \
    "https://127.0.0.1:${CFG_HTTPS_PORT}${path}" 2>/dev/null
}

# Codigo HTTP de una ruta por loopback, sin exigir exito.
edge_status() {
  local path="$1"
  curl --silent --insecure --max-time 15 --output /dev/null --write-out '%{http_code}' \
    --header 'Accept: application/json' \
    "https://127.0.0.1:${CFG_HTTPS_PORT}${path}" 2>/dev/null || printf '000'
}

# Sonda DESDE DENTRO del contenedor de la aplicacion, por FastCGI, sin borde.
# Es lo que permite verificar la version nueva antes de exponerla: con nginx a
# escala 0 no hay puerto publicado que pedir. `cgi-fcgi` viaja en la imagen
# (la usa la sonda de Docker) y toma los parametros FastCGI del entorno.
# Deja el codigo en PROBE_STATUS y el cuerpo en PROBE_BODY. PHP-FPM solo emite
# la cabecera `Status:` cuando NO es 200; SIN RESPUESTA es 0, nunca 200.
PROBE_STATUS=""
PROBE_BODY=""
app_probe() {
  local path="$1" raw

  PROBE_STATUS="0"
  PROBE_BODY=""
  raw="$(compose_new exec -T app sh -c "env -i \
    REQUEST_METHOD=GET SCRIPT_FILENAME=/var/www/html/public/index.php SCRIPT_NAME=/index.php \
    REQUEST_URI='${path}' DOCUMENT_URI='${path}' DOCUMENT_ROOT=/var/www/html/public QUERY_STRING= \
    SERVER_PROTOCOL=HTTP/1.1 GATEWAY_INTERFACE=CGI/1.1 REQUEST_SCHEME=https HTTPS=on \
    HTTP_HOST=127.0.0.1 SERVER_NAME=127.0.0.1 SERVER_PORT=443 REMOTE_ADDR=127.0.0.1 \
    /usr/bin/cgi-fcgi -bind -connect 127.0.0.1:9000" 2>/dev/null | tr -d '\r' || true)"
  [ -n "${raw}" ] || return 1

  PROBE_STATUS="$(printf '%s\n' "${raw}" | awk 'BEGIN{s=200} /^[Ss]tatus: [0-9]+/{s=$2} /^$/{exit} END{print s}')"
  PROBE_BODY="$(printf '%s\n' "${raw}" | awk 'body{print} /^$/{body=1}')"
  return 0
}

json_field() {
  local json="$1" field="$2"
  printf '%s' "${json}" | sed -n "s/.*\"${field}\"[[:space:]]*:[[:space:]]*\"\([^\"]*\)\".*/\1/p" | head -n 1
}

#------------------------------------------------------------------------------
# Asiento de auditoria del instalador (RF-PD-10, RL-04, RS-07, regla dura 6,
# tarea 5.7 cierre). El payload lo valida y lo cierra `SystemEventPayload`
# (dominio); aqui solo se construye el JSON y se distingue una clave OPCIONAL
# vacia —que se OMITE, nunca como cadena ""— de una con valor.
#------------------------------------------------------------------------------

# Escapa lo minimo que un valor de este payload puede necesitar. Los valores
# que llegan aqui son versiones, huellas sha256, nombres de fichero e
# instantes UTC: nunca texto libre ni lo que un empleado escribio.
audit_json_escape() {
  local value="$1"
  value="${value//\\/\\\\}"
  value="${value//\"/\\\"}"
  printf '%s' "${value}"
}

# `clave=valor ...`. Una clave con valor vacio se OMITE del objeto (nunca una
# cadena vacia): es exactamente lo que pide el payload del dominio para sus
# campos opcionales (`chain_before`, `chain_after`, `backup_fingerprint`,
# `report_id`). `migrations_applied` es el UNICO campo numerico y se escribe
# sin comillas cuando su valor es un entero no negativo.
audit_json_object() {
  local pair key value out="{" first=1
  for pair in "$@"; do
    key="${pair%%=*}"
    value="${pair#*=}"
    [ -n "${value}" ] || continue
    [ "${first}" -eq 1 ] || out+=","
    first=0
    if [ "${key}" = "migrations_applied" ] && [[ "${value}" =~ ^[0-9]+$ ]]; then
      out+="\"${key}\":${value}"
    else
      out+="\"${key}\":\"$(audit_json_escape "${value}")\""
    fi
  done
  out+="}"
  printf '%s' "${out}"
}

# El paso de `SystemUpdateStep` en el que esta la actualizacion AHORA MISMO,
# para el asiento `system.restored_from_backup` de una vuelta atras. `STEP` no
# distingue las dos mitades del paso 5 (arranque sin exponer / apertura del
# borde y salida de mantenimiento): lo hace `STEP5_EXPOSED`.
resolve_failed_step() {
  case "${STEP}" in
  1) printf 'preflight' ;;
  2) printf 'maintenance' ;;
  3) printf 'backup' ;;
  4) printf 'migrations' ;;
  5)
    if [ "${STEP5_EXPOSED}" -eq 1 ]; then
      printf 'expose'
    else
      printf 'start_and_verify'
    fi
    ;;
  *) printf 'unknown' ;;
  esac
}

#------------------------------------------------------------------------------
# Informe (paso 7). Se abre en cuanto se conoce BACKUP_PATH y se cierra SIEMPRE,
# tambien tras una vuelta atras: el fabricante no tiene acceso al servidor
# (ADR-016), asi que si el informe no queda aqui no queda en ninguna parte.
#------------------------------------------------------------------------------
open_report() {
  local dir="${CFG_BACKUP_PATH}/reports"

  if [ ! -d "${dir}" ]; then
    install -d -o "${KQ_APP_UID}" -g "${KQ_APP_UID}" -m 0750 "${dir}" 2>/dev/null ||
      install -d -m 0750 "${dir}" 2>/dev/null || return 1
  fi
  REPORT_FILE="${dir}/update-${STARTED_UTC}.log"
  DETAIL_FILE="${dir}/update-${STARTED_UTC}.detalle.log"
  : >"${REPORT_FILE}" || return 1
  : >"${DETAIL_FILE}" || return 1
  # El informe lo lee la aplicacion (paquete de diagnostico); el detalle, solo
  # root: puede llevar datos personales y no viaja sin que alguien lo lea.
  chmod 0640 "${REPORT_FILE}" 2>/dev/null || true
  chown "${KQ_APP_UID}:${KQ_APP_UID}" "${REPORT_FILE}" 2>/dev/null || true
  chmod 0600 "${DETAIL_FILE}" 2>/dev/null || true

  report_append "$(kq_text u_report_title)"
  report_append "$(kq_format u_report_started "${STARTED_UTC}")"
  report_append "$(kq_format u_report_versions "${SOURCE_VERSION:-?}" "${TARGET_VERSION}")"
  report_append "$(kq_format u_report_dirs "${CURRENT_DIR:-?}" "${PACKAGE_DIR}")"
  report_append ""
  detail_note "$(kq_format u_detail_title "${REPORT_FILE}")"
  return 0
}

close_report() {
  local code="$1" entry finished state_text

  [ -n "${REPORT_FILE}" ] && [ "${REPORT_CLOSED}" -eq 0 ] || return 0

  finished="$(timestamp_utc)"
  report_append ""
  report_append "=================================================================="
  report_append "$(kq_text u_report_title)"
  report_append "$(kq_format u_report_started "${STARTED_UTC}")"
  report_append "$(kq_format u_report_finished "${finished}" "$(($(now_epoch) - STARTED_AT))")"
  report_append "$(kq_format u_report_versions "${SOURCE_VERSION:-?}" "${TARGET_VERSION}")"
  report_append "$(kq_format u_report_dirs "${CURRENT_DIR:-?}" "${PACKAGE_DIR}")"
  if [ "${#UPGRADE_CHAIN[@]}" -gt 0 ]; then
    report_append "$(kq_format u_report_chain "$(
      IFS=' '
      printf '%s' "${UPGRADE_CHAIN[*]}"
    )")"
  fi
  report_append "$(kq_format u_report_backup "${BACKUP_FILE:-$(kq_text u_report_none)}" "${BACKUP_RESULT:-$(kq_text u_report_not_run)}")"
  report_append "$(kq_format u_report_maintenance "${MAINTENANCE_SECONDS}")"
  for entry in ${CHECKPOINTS+"${CHECKPOINTS[@]}"}; do
    IFS='|' read -r c_version c_count c_seconds c_batch <<<"${entry}"
    report_append "$(kq_format u_report_checkpoint "${c_version}" "$(kq_text u_report_ok)" "${c_count}" "${c_seconds}" "${c_batch}")"
  done
  for entry in ${REPORT_CHECKS+"${REPORT_CHECKS[@]}"}; do
    report_append "$(kq_format u_report_check "${entry%%|*}" "${entry#*|}")"
  done
  if [ -n "${ROLLBACK_SUMMARY}" ]; then
    report_append "${ROLLBACK_SUMMARY}"
  fi
  if [ "${code}" = "${KQ_EXIT_ROLLBACK_INCOMPLETE}" ]; then
    state_text="$(kq_text u_report_needs_person)"
  else
    state_text="$(kq_text u_report_operational)"
  fi
  report_append "$(kq_format u_report_final "${FINAL_STATE:-${SOURCE_VERSION:-?}}" "${state_text}")"
  report_append "$(kq_format u_report_exit "${code}" "$(kq_exit_name "${code}")")"
  report_append "$(kq_format u_report_detail "${DETAIL_FILE}")"
  REPORT_CLOSED=1
}

#------------------------------------------------------------------------------
# Paso 1 — precondiciones. NO SE TOCA LA INSTALACION.
#
# La lista es un dato a proposito: la prueba de integracion la lee y afirma que
# la licencia NO esta en ella (regla dura 15). Un `check_license` que alguien
# anadiera "por completar" apareceria aqui y la prueba caeria.
#------------------------------------------------------------------------------
readonly -a PRECONDITION_CHECKS=(
  check_package_files
  check_versions_matrix
  check_docker_available
  check_tools
  check_privileges
  check_installation
  check_source_version
  check_backup_config
  check_space
  check_images
  check_services
  check_audit_chain
  check_env_new_keys
)

check_package_files() {
  local file
  for file in "${COMPOSE_FILE}" "${ENV_TEMPLATE}" "${PACKAGE_DIR}/VERSION"; do
    if [ -n "${file}" ] && [ -f "${file}" ]; then
      check_pass "$(kq_format u_c_package_file "${file}")"
    else
      check_fail "$(kq_format u_c_package_file "${file:-$(kq_text not_found)}")" \
        "$(kq_format u_f_package_file "${file:-docker-compose.yml}")"
    fi
  done

  if [ -n "${TARGET_VERSION}" ] && kq_semver_valid "${TARGET_VERSION}"; then
    check_pass "$(kq_format u_c_target_version "${TARGET_VERSION}")"
  else
    check_fail "$(kq_format u_c_target_version "${TARGET_VERSION:-$(kq_text unknown_value)}")" \
      "$(kq_format u_f_package_file "VERSION")"
  fi
}

check_versions_matrix() {
  if ! kq_versions_load "${VERSIONS_FILE}"; then
    check_fail "$(kq_format u_c_versions_file "${VERSIONS_FILE}" 0)" \
      "$(kq_format u_f_versions_file "${VERSIONS_FILE}" "${KQ_VERSIONS_ERROR}")"
    return 0
  fi
  check_pass "$(kq_format u_c_versions_file "${VERSIONS_FILE}" "${#KQ_VERSIONS[@]}")"

  if [ -n "${TARGET_VERSION}" ] && ! kq_version_listed "${TARGET_VERSION}"; then
    check_fail "$(kq_format u_c_target_version "${TARGET_VERSION}")" \
      "$(kq_format u_f_target_unlisted "${TARGET_VERSION}" "${VERSIONS_FILE}")"
  fi
}

check_docker_available() {
  check_docker
  if docker version --format '{{.Server.Version}}' >/dev/null 2>&1 &&
    docker compose version --short >/dev/null 2>&1; then
    DOCKER_OK=1
  fi
}

# Root de verdad: hay que copiar el certificado conservando su propietario
# (uid 101), escribir el .env en 0600 y dejar el informe legible por la
# aplicacion. No hay excepcion como en install.sh: aqui se tocan las tres cosas.
check_privileges() {
  if [ "$(id -u)" = "0" ]; then
    check_pass "$(kq_text u_c_root)"
  else
    check_fail "$(kq_text u_c_root)" "$(kq_text u_f_root)"
  fi
}

# Donde esta la instalacion actual. Se pregunta a Docker por las ETIQUETAS de
# los contenedores PERSISTENTES del proyecto (los de `run --rm` que hubieran
# quedado de una ejecucion interrumpida no cuentan), que llevan la ruta del
# fichero de compose con el que se crearon: es lo unico que no depende de que
# el operador recuerde un directorio. `--current` existe para el caso en que
# los contenedores ya no esten.
check_installation() {
  local configs config candidate missing=""

  if [ -n "${OPT_CURRENT}" ]; then
    CURRENT_DIR="$(cd -- "${OPT_CURRENT}" 2>/dev/null && pwd || printf '%s' "${OPT_CURRENT}")"
    for candidate in "${CURRENT_DIR}/docker-compose.yml" "${CURRENT_DIR}/compose.prod.yaml"; do
      [ -f "${candidate}" ] && CURRENT_COMPOSE="${candidate}" && break
    done
  elif [ "${DOCKER_OK}" -eq 1 ]; then
    # SOLO el contenedor del servicio `app`. Compose no recrea un contenedor
    # cuya configuracion no cambia entre versiones (redis lleva imagen fija),
    # y ese conserva el `config_files` del directorio que lo creo: tras una
    # actualizacion habria dos directorios y ninguno seria un error. La imagen
    # de `app` lleva la version, asi que se recrea siempre y su etiqueta dice
    # de donde se levanto la instalacion que esta sirviendo.
    configs="$(docker ps -a --filter "label=com.docker.compose.project=${KQ_COMPOSE_PROJECT}" \
      --filter "label=com.docker.compose.service=app" \
      --filter "label=com.docker.compose.oneoff=False" \
      --format '{{.Label "com.docker.compose.project.config_files"}}' |
      awk -F, 'NF { print $1 }' | sort -u || true)"
    case "$(printf '%s\n' "${configs}" | grep -c . || true)" in
    0)
      check_fail "$(kq_format u_c_installation "$(kq_text not_found)")" \
        "$(kq_format u_f_installation_missing "${KQ_COMPOSE_PROJECT}")"
      return 0
      ;;
    1)
      config="$(printf '%s\n' "${configs}" | sed -n '1p')"
      CURRENT_COMPOSE="${config}"
      CURRENT_DIR="$(dirname -- "${config}")"
      ;;
    *)
      check_fail "$(kq_format u_c_installation "$(kq_text unknown_value)")" \
        "$(kq_format u_f_installation_ambiguous "${KQ_COMPOSE_PROJECT}" "$(printf '%s' "${configs}" | tr '\n' ' ')")"
      return 0
      ;;
    esac
  else
    # Sin Docker no hay forma de localizarla; ya ha fallado check_docker.
    return 0
  fi

  CURRENT_ENV="${CURRENT_DIR}/.env"
  [ -n "${CURRENT_COMPOSE}" ] && [ -f "${CURRENT_COMPOSE}" ] || missing="docker-compose.yml"
  [ -f "${CURRENT_ENV}" ] || missing=".env"
  [ -n "${missing}" ] || [ -n "$(env_value "${CURRENT_ENV}" "APP_KEY")" ] || missing=".env (APP_KEY)"
  if [ -n "${missing}" ]; then
    check_fail "$(kq_format u_c_installation "${CURRENT_DIR}")" \
      "$(kq_format u_f_installation_files "${CURRENT_DIR}" "${missing}")"
    CURRENT_ENV=""
    return 0
  fi
  check_pass "$(kq_format u_c_installation "${CURRENT_DIR}")"

  if [ "$(cd -- "${CURRENT_DIR}" && pwd -P)" = "$(cd -- "${PACKAGE_DIR}" && pwd -P)" ]; then
    IN_PLACE=1
    check_warn "$(kq_format u_c_in_place "${CURRENT_DIR}")" "$(kq_text u_f_in_place)"
  elif [ -f "${ENV_FILE}" ] && [ -n "$(env_value "${ENV_FILE}" "APP_KEY")" ] && ! same_installation_env "${ENV_FILE}" "${CURRENT_ENV}"; then
    # Un .env con secretos de OTRA instalacion. El que dejo un intento anterior
    # de este mismo paquete (misma APP_KEY, solo IMAGE_TAG cambiado) no lo es:
    # el codigo 4 promete que se puede reintentar.
    check_fail "$(kq_format u_c_installation "${CURRENT_DIR}")" \
      "$(kq_format u_f_env_conflict "${ENV_FILE}" "${CURRENT_ENV}")"
    CURRENT_ENV=""
    return 0
  fi

  CFG_HTTPS_PORT="$(env_value "${CURRENT_ENV}" "HTTPS_PORT")"
  CFG_BACKUP_PATH="$(env_value "${CURRENT_ENV}" "BACKUP_PATH")"
  CFG_TLS_CERT_DIR="$(env_value "${CURRENT_ENV}" "TLS_CERT_DIR")"
  CFG_DB_DATABASE="$(env_value "${CURRENT_ENV}" "DB_DATABASE")"
  CFG_DB_USERNAME="$(env_value "${CURRENT_ENV}" "DB_USERNAME")"
  CFG_DB_MIGRATION_USERNAME="$(env_value "${CURRENT_ENV}" "DB_MIGRATION_USERNAME")"
  [ -n "${CFG_HTTPS_PORT}" ] || CFG_HTTPS_PORT="443"
  [ -n "${CFG_BACKUP_PATH}" ] || CFG_BACKUP_PATH="/var/backups/fichaje"
  [ -n "${CFG_DB_DATABASE}" ] || CFG_DB_DATABASE="fichaje"
  [ -n "${CFG_DB_USERNAME}" ] || CFG_DB_USERNAME="fichaje_app"
  [ -n "${CFG_DB_MIGRATION_USERNAME}" ] || CFG_DB_MIGRATION_USERNAME="fichaje_migrator"
}

# Dos .env son de la misma instalacion si comparten los secretos que nacen con
# ella y no cambian nunca solos. No se comparan byte a byte: el actualizador
# cambia IMAGE_TAG a proposito.
same_installation_env() {
  local a="$1" b="$2" key
  for key in APP_KEY BACKUP_ENCRYPTION_KEY; do
    [ "$(env_value "${a}" "${key}")" = "$(env_value "${b}" "${key}")" ] || return 1
  done
  return 0
}

# La version de origen es la que dice IMAGE_TAG del .env: es lo que install.sh
# escribio y lo que Compose levanto. La sonda se contrasta despues, en
# check_services, y si discrepa se avisa.
check_source_version() {
  [ -n "${CURRENT_ENV}" ] && [ "${#KQ_VERSIONS[@]}" -gt 0 ] && [ -n "${TARGET_VERSION}" ] || return 0

  SOURCE_VERSION="$(env_value "${CURRENT_ENV}" "IMAGE_TAG")"
  if [ -z "${SOURCE_VERSION}" ] || ! kq_semver_valid "${SOURCE_VERSION}"; then
    check_fail "$(kq_format u_c_source_version "${SOURCE_VERSION:-$(kq_text empty_value)}" "${CURRENT_ENV}")" \
      "$(kq_format u_f_source_version_missing "${CURRENT_ENV}" "${CFG_HTTPS_PORT}")"
    SOURCE_VERSION=""
    return 0
  fi
  check_pass "$(kq_format u_c_source_version "${SOURCE_VERSION}" "${CURRENT_ENV}")"

  case "$(kq_semver_compare "${SOURCE_VERSION}" "${TARGET_VERSION}")" in
  0)
    # Idempotencia (§3.5): no hace nada y lo dice. Es un resultado, no un fallo.
    say ""
    kq_msg u_c_already_target "${TARGET_VERSION}"
    say "$(kq_format exit_line "${KQ_EXIT_STATE_CONFLICT}" "$(kq_exit_name "${KQ_EXIT_STATE_CONFLICT}")")"
    exit "${KQ_EXIT_STATE_CONFLICT}"
    ;;
  1)
    check_fail "$(kq_format u_c_supported "${SOURCE_VERSION}" "${TARGET_VERSION}")" \
      "$(kq_format u_f_downgrade "${SOURCE_VERSION}" "${TARGET_VERSION}")"
    return 0
    ;;
  esac

  if ! kq_version_listed "${SOURCE_VERSION}"; then
    check_fail "$(kq_format u_c_supported "${SOURCE_VERSION}" "${TARGET_VERSION}")" \
      "$(kq_format u_f_source_unlisted "${SOURCE_VERSION}" "${VERSIONS_FILE}" "${CURRENT_ENV}")"
    return 0
  fi

  if ! kq_is_supported_source "${SOURCE_VERSION}" "${TARGET_VERSION}"; then
    local hop supported
    hop="$(kq_first_hop "${SOURCE_VERSION}" "${TARGET_VERSION}")"
    supported="$(kq_supported_sources "${TARGET_VERSION}" | tr '\n' ' ')"
    case "${hop}" in
    major-last)
      check_fail "$(kq_format u_c_supported "${SOURCE_VERSION}" "${TARGET_VERSION}")" \
        "$(kq_format u_f_unsupported_major_last "${SOURCE_VERSION}" "$(kq_semver_major "${SOURCE_VERSION}")" \
          "$(kq_semver_major "${TARGET_VERSION}")")"
      ;;
    major\ *)
      check_fail "$(kq_format u_c_supported "${SOURCE_VERSION}" "${TARGET_VERSION}")" \
        "$(kq_format u_f_unsupported_major "${SOURCE_VERSION}" "$(kq_semver_major "${SOURCE_VERSION}")" \
          "$(kq_semver_major "${TARGET_VERSION}")" "${hop#major }")"
      ;;
    none)
      check_fail "$(kq_format u_c_supported "${SOURCE_VERSION}" "${TARGET_VERSION}")" \
        "$(kq_format u_f_unsupported_none "${SOURCE_VERSION}" "${TARGET_VERSION}")"
      ;;
    *)
      check_fail "$(kq_format u_c_supported "${SOURCE_VERSION}" "${TARGET_VERSION}")" \
        "$(kq_format u_f_unsupported_hop "${SOURCE_VERSION}" "${TARGET_VERSION}" "${supported% }" "${hop}")"
      ;;
    esac
    return 0
  fi
  check_pass "$(kq_format u_c_supported "${SOURCE_VERSION}" "${TARGET_VERSION}")"

  UPGRADE_CHAIN=()
  local version
  while IFS= read -r version; do
    [ -n "${version}" ] && UPGRADE_CHAIN+=("${version}")
  done < <(kq_upgrade_chain "${SOURCE_VERSION}" "${TARGET_VERSION}")
  check_pass "$(kq_format u_c_chain "$(
    IFS=' '
    printf '%s' "${UPGRADE_CHAIN[*]}"
  )")"
}

# Copia previa POSIBLE: clave presente y destino escribible. Aqui se toma el
# candado y se abre el informe, porque es el primer momento en que se sabe
# donde escribirlos. Sin candado no se sigue comprobando nada: la instalacion
# la esta tocando otro proceso y cada comprobacion de mas es carga sobre el.
check_backup_config() {
  [ -n "${CURRENT_ENV}" ] || return 0

  if [ -n "$(env_value "${CURRENT_ENV}" "BACKUP_ENCRYPTION_KEY")" ]; then
    check_pass "$(kq_text u_c_backup_key)"
  else
    check_fail "$(kq_text u_c_backup_key)" "$(kq_format u_f_backup_key "${CURRENT_ENV}")"
  fi

  if [ -d "${CFG_BACKUP_PATH}" ] && [ -w "${CFG_BACKUP_PATH}" ]; then
    check_pass "$(kq_format u_c_backup_path "${CFG_BACKUP_PATH}")"
  else
    check_fail "$(kq_format u_c_backup_path "${CFG_BACKUP_PATH}")" \
      "$(kq_format u_f_backup_path "${CFG_BACKUP_PATH}" "${CFG_BACKUP_PATH}")"
    return 0
  fi

  LOCK_DIR="${CFG_BACKUP_PATH}/update.lock"
  if mkdir "${LOCK_DIR}" 2>/dev/null; then
    LOCK_OWNED=1
    printf '%s\n' "$$" >"${LOCK_DIR}/pid" 2>/dev/null || true
    check_pass "$(kq_text u_c_lock)"
  else
    local age="?"
    if command -v stat >/dev/null 2>&1; then
      age="$(($(now_epoch) - $(stat -c '%Y' "${LOCK_DIR}" 2>/dev/null || now_epoch)))s"
    fi
    check_fail "$(kq_text u_c_lock)" \
      "$(kq_format u_f_lock "${LOCK_DIR}" "${age}" "${CFG_BACKUP_PATH}/reports" "${LOCK_DIR}")"
    say ""
    kq_msg u_req_summary_fail "${CHECKS_FAILED}" "${SOURCE_VERSION:-?}"
    err "$(kq_format exit_line "${KQ_EXIT_REQUIREMENTS}" "$(kq_exit_name "${KQ_EXIT_REQUIREMENTS}")")"
    exit "${KQ_EXIT_REQUIREMENTS}"
  fi

  if open_report; then
    check_pass "$(kq_format u_c_report_dir "${CFG_BACKUP_PATH}/reports")"
  else
    check_fail "$(kq_format u_c_report_dir "${CFG_BACKUP_PATH}/reports")" \
      "$(kq_format u_f_backup_path "${CFG_BACKUP_PATH}/reports" "${CFG_BACKUP_PATH}/reports")"
  fi
}

psql_migrator() {
  local compose_fn="$1" sql="$2"
  "${compose_fn}" exec -T postgres psql -U "${CFG_DB_MIGRATION_USERNAME}" -d "${CFG_DB_DATABASE}" \
    -Atqc "${sql}" 2>/dev/null | tr -d '[:space:]' || true
}

database_size_bytes() {
  psql_migrator compose_current "SELECT pg_database_size(current_database())"
}

# Espacio para la copia Y para la migracion: una migracion que anade una
# columna a una tabla grande puede duplicarla temporalmente (doc 08 §3).
check_space() {
  [ "${DOCKER_OK}" -eq 1 ] && [ -n "${CURRENT_ENV}" ] || return 0

  local bytes db_gib free required docker_root
  bytes="$(database_size_bytes)"
  [[ "${bytes}" =~ ^[0-9]+$ ]] || bytes=0
  db_gib=$(((bytes + 1073741823) / 1073741824))

  required=$((db_gib * 2 + 1))
  free="$(kq_free_gib "${CFG_BACKUP_PATH}")"
  if [ "${free}" -ge "${required}" ]; then
    check_pass "$(kq_format u_c_space_backup "${CFG_BACKUP_PATH}" "${free}" "${db_gib}" "${required}")"
  else
    check_fail "$(kq_format u_c_space_backup "${CFG_BACKUP_PATH}" "${free}" "${db_gib}" "${required}")" \
      "$(kq_format u_f_space_backup "${required}" "${CFG_BACKUP_PATH}" "${free}")"
  fi

  docker_root="$(docker info --format '{{.DockerRootDir}}' 2>/dev/null || true)"
  [ -n "${docker_root}" ] || docker_root="/var/lib/docker"
  required=$((db_gib + KQ_MIN_DOCKER_FREE_GIB))
  free="$(kq_free_gib "${docker_root}")"
  if [ "${free}" -ge "${required}" ]; then
    check_pass "$(kq_format u_c_space_docker "${docker_root}" "${free}" "${required}")"
  else
    check_fail "$(kq_format u_c_space_docker "${docker_root}" "${free}" "${required}")" \
      "$(kq_format u_f_space_docker "${required}" "${docker_root}" "${free}")"
  fi
}

# Descarga una a una solo las imagenes que falten, como install.sh: en una
# instalacion sin salida a internet el IT las carga antes con `docker load`.
# Es lo unico del paso 1 que escribe en el servidor, y escribe en el almacen de
# imagenes de Docker, no en la instalacion.
check_images() {
  [ "${DOCKER_OK}" -eq 1 ] && [ -n "${CURRENT_ENV}" ] && [ -n "${TARGET_VERSION}" ] || return 0

  local image registry announced=0
  declare -a missing=()

  while IFS= read -r image; do
    [ -n "${image}" ] || continue
    docker image inspect "${image}" >/dev/null 2>&1 || missing+=("${image}")
  done < <(IMAGE_TAG="${TARGET_VERSION}" docker compose --env-file "${CURRENT_ENV}" -f "${COMPOSE_FILE}" config --images 2>/dev/null || true)

  registry="$(env_value "${CURRENT_ENV}" "IMAGE_REGISTRY")"
  for image in ${missing+"${missing[@]}"}; do
    if [ "${announced}" -eq 0 ]; then
      kq_msg u_images_pull "${TARGET_VERSION}"
      announced=1
    fi
    if ! docker pull --quiet "${image}" >/dev/null 2>&1; then
      check_fail "$(kq_format u_c_images "${TARGET_VERSION}")" \
        "$(kq_format u_f_images "${TARGET_VERSION}" "${registry:-?}" "${TARGET_VERSION}")"
      return 0
    fi
  done
  check_pass "$(kq_format u_c_images "${TARGET_VERSION}")"
}

# Servicios sanos y sondas respondiendo. Actualizar sobre un sistema ya roto
# convierte dos problemas en uno indistinguible.
check_services() {
  [ "${DOCKER_OK}" -eq 1 ] && [ -n "${CURRENT_ENV}" ] || return 0

  local service state path body reported
  for service in postgres redis app nginx; do
    state="$(service_state compose_current "${service}")"
    if service_is_healthy "${state}"; then
      check_pass "$(kq_format u_c_service "${service}")"
    else
      check_fail "$(kq_format u_c_service "${service}")" \
        "$(kq_format u_f_service "${service}" "${state:-$(kq_text absent)}" "${CURRENT_COMPOSE}" "${service}")"
    fi
  done

  for path in /api/v1/health /api/v1/ready; do
    if body="$(edge_probe "${path}")"; then
      check_pass "$(kq_format u_c_probe "${path}")"
      if [ "${path}" = "/api/v1/health" ] && [ -n "${SOURCE_VERSION}" ]; then
        reported="$(json_field "${body}" version)"
        if [ -n "${reported}" ] && [ "${reported}" != "${SOURCE_VERSION}" ]; then
          check_warn "$(kq_format u_c_version_mismatch "${reported}" "${SOURCE_VERSION}")" \
            "$(kq_format u_f_version_mismatch "${SOURCE_VERSION}" "${CURRENT_ENV}")"
        fi
      fi
    else
      check_fail "$(kq_format u_c_probe "${path}")" \
        "$(kq_format u_f_probe "${path}" "${CFG_HTTPS_PORT}" "${CURRENT_COMPOSE}")"
    fi
  done

  # La ruta de gestion que el paso 5 y la vuelta atras exigen que responda 401
  # tiene que responder 401 YA: si no, la vuelta atras no podria verificarse
  # nunca y un fallo de la version instalada se confundiria con uno de la nueva.
  state="$(edge_status "${KQ_MANAGEMENT_PROBE}")"
  if [ "${state}" = "401" ]; then
    check_pass "$(kq_format u_c_probe_management "${KQ_MANAGEMENT_PROBE}")"
  else
    check_fail "$(kq_format u_c_probe_management "${KQ_MANAGEMENT_PROBE}")" \
      "$(kq_format u_f_probe_management "${KQ_MANAGEMENT_PROBE}" "${state}" "${CURRENT_COMPOSE}")"
  fi
}

# Cadena de auditoria integra ANTES de tocar nada (doc 02 §7.4, regla dura 6).
# Si ya estaba rota, hay que saberlo ahora: despues nadie podria distinguir si
# la rompio la actualizacion.
check_audit_chain() {
  [ "${DOCKER_OK}" -eq 1 ] && [ -n "${CURRENT_ENV}" ] || return 0

  detail_note "--- compliance:verify-audit-chain (antes) ---"
  if compose_current exec -T app php artisan compliance:verify-audit-chain >>"$(detail_sink)" 2>&1; then
    check_pass "$(kq_text u_c_audit_chain)"
    remember_check "audit-chain-before" "$(kq_text u_report_ok)"
    CHAIN_BEFORE="$(json_field "$(compose_current exec -T app php artisan compliance:audit-chain-head 2>/dev/null || true)" hash)"
  else
    check_fail "$(kq_text u_c_audit_chain)" "$(kq_text u_f_audit_chain)"
    remember_check "audit-chain-before" "$(kq_text u_report_failed)"
  fi
}

# Claves que trae el .env.example nuevo y el .env del cliente no tiene. No es un
# fallo —cada una usa su valor de serie—, pero decirlo aqui evita descubrirlo
# leyendo configuracion.md meses despues.
check_env_new_keys() {
  [ -n "${CURRENT_ENV}" ] && [ -n "${ENV_TEMPLATE}" ] || return 0

  local key count=0 shown=""
  while IFS= read -r key; do
    [ -n "${key}" ] || continue
    grep -qE "^[[:space:]]*(export[[:space:]]+)?${key}=" "${CURRENT_ENV}" && continue
    count=$((count + 1))
    [ "${count}" -le 8 ] && shown="${shown}${shown:+ }${key}"
  done < <(sed -nE 's/^[[:space:]]*(export[[:space:]]+)?([A-Z_][A-Z0-9_]*)=.*/\2/p' "${ENV_TEMPLATE}" | sort -u)

  if [ "${count}" -gt 0 ]; then
    [ "${count}" -gt 8 ] && shown="${shown} ..."
    check_warn "$(kq_format u_c_env_new_keys "${count}" "${shown}")" "$(kq_format u_f_env_new_keys "${ENV_FILE}")"
  fi
}

phase_preconditions() {
  local check
  heading "$(kq_text u_phase_1)"

  for check in "${PRECONDITION_CHECKS[@]}"; do
    "${check}"
  done

  say ""
  if [ "${CHECKS_FAILED}" -gt 0 ]; then
    kq_msg u_req_summary_fail "${CHECKS_FAILED}" "${SOURCE_VERSION:-?}"
    err "$(kq_format exit_line "${KQ_EXIT_REQUIREMENTS}" "$(kq_exit_name "${KQ_EXIT_REQUIREMENTS}")")"
    close_report "${KQ_EXIT_REQUIREMENTS}"
    exit "${KQ_EXIT_REQUIREMENTS}"
  fi
  say "$(kq_format u_req_summary_ok "${CHECKS_RUN}" "${CHECKS_WARNED}")"
  say "$(kq_format u_c_changelog "${SOURCE_VERSION}" "${TARGET_VERSION}")"
}

#------------------------------------------------------------------------------
# Preparacion del paquete nuevo: su .env y su certificado son los de la
# instalacion actual. Se escribe SOLO en el directorio del paquete nuevo; la
# instalacion sigue intacta hasta el paso 4. Cualquier fallo aqui es un 2: la
# instalacion no se ha tocado, y el mensaje dice que quedo escrito.
#------------------------------------------------------------------------------
prepare_package() {
  local certs_from certs_to

  if [ "${IN_PLACE}" -eq 1 ]; then
    ROLLBACK_COMPOSE="${COMPOSE_FILE}"
    ROLLBACK_ENV="${ENV_FILE}.kronoqr-pre-update"
    if ! cp -p "${ENV_FILE}" "${ROLLBACK_ENV}" || ! chmod 0600 "${ROLLBACK_ENV}"; then
      die "${KQ_EXIT_REQUIREMENTS}" "$(kq_format u_f_prepare_env "${ROLLBACK_ENV}")"
    fi
    return 0
  fi

  ROLLBACK_COMPOSE="${CURRENT_COMPOSE}"
  ROLLBACK_ENV="${CURRENT_ENV}"

  say "$(kq_format u_prepare_env "${ENV_FILE}")"
  if ! cp -p "${CURRENT_ENV}" "${ENV_FILE}" || ! chmod 0600 "${ENV_FILE}" ||
    ! kq_env_set "${ENV_FILE}" "IMAGE_TAG" "${TARGET_VERSION}" || ! chmod 0600 "${ENV_FILE}"; then
    die "${KQ_EXIT_REQUIREMENTS}" "$(kq_format u_f_prepare_env "${ENV_FILE}")"
  fi

  case "${CFG_TLS_CERT_DIR}" in
  "" | /*) ;;
  *)
    certs_from="${CURRENT_DIR}/${CFG_TLS_CERT_DIR#./}"
    certs_to="${PACKAGE_DIR}/${CFG_TLS_CERT_DIR#./}"
    if [ -d "${certs_from}" ] && [ ! -f "${certs_to}/tls.key" ]; then
      say "$(kq_format u_prepare_certs "${certs_from}" "${certs_to}")"
      if ! install -d -m 0755 "${certs_to}" || ! cp -a "${certs_from}/." "${certs_to}/"; then
        die "${KQ_EXIT_REQUIREMENTS}" "$(kq_format u_f_prepare_certs "${certs_from}" "${certs_to}" "${certs_from}/." "${certs_to}/")"
      fi
    fi
    ;;
  esac
}

#------------------------------------------------------------------------------
# Ventana de mantenimiento para Prometheus/Alertmanager (tarea 3.2, decision
# 6c): escribe kronoqr_maintenance.prom en BACKUP_PATH/metrics, el mismo
# directorio y el mismo colector textfile de node-exporter que ya sirve el
# resultado de la copia. `VentanaDeMantenimientoActiva` (rules/maintenance.yml)
# lo lee para INHIBIR quiosco, API, TLS y disco mientras dura la actualizacion
# -sintomas esperados de un reinicio de servicios, no una averia-, con un tope
# de 4 h por si este script muriera sin llegar a poner active=0.
#
# SI EL DIRECTORIO NO EXISTE, NO ES UN ERROR: perfil `observability` apagado,
# o una instalacion que todavia no ha hecho su primera copia (que es quien crea
# el arbol de BACKUP_PATH). Sencillamente no hay a quien avisar. Y NUNCA deshace
# la actualizacion por no poder escribir la metrica: `|| true` en la escritura
# atomica es a proposito, con el mismo criterio que el resto de instrumentacion
# de este script (doc 02 §3.5: fallo seguro, nada a medias).
write_maintenance_metric() {
  local active="$1" since="$2" dir="${CFG_BACKUP_PATH}/metrics"
  [ -d "${dir}" ] || return 0
  {
    printf '# HELP kronoqr_maintenance_active Vale 1 mientras update.sh tiene el mantenimiento puesto (artisan down) y 0 el resto del tiempo.\n'
    printf '# TYPE kronoqr_maintenance_active gauge\n'
    printf 'kronoqr_maintenance_active %s\n' "${active}"
    printf '# HELP kronoqr_maintenance_since_timestamp_seconds Marca de tiempo UNIX de cuando empezo el mantenimiento en curso, o el ultimo.\n'
    printf '# TYPE kronoqr_maintenance_since_timestamp_seconds gauge\n'
    printf 'kronoqr_maintenance_since_timestamp_seconds %s\n' "${since}"
  } | kq_write_metrics_atomic "${dir}/kronoqr_maintenance.prom" || true
}

#------------------------------------------------------------------------------
# Paso 2 — mantenimiento. Desde aqui, cualquier fallo deshace.
#------------------------------------------------------------------------------
lift_maintenance() {
  detail_note "--- retirando el mantenimiento de la version ${SOURCE_VERSION} ---"
  compose_current exec -T app php artisan up >>"$(detail_sink)" 2>&1 || return 1
  write_maintenance_metric 0 "${MAINTENANCE_SINCE}"
  compose_current up -d horizon scheduler >>"$(detail_sink)" 2>&1 || return 1
  MAINTENANCE_SECONDS=$(($(now_epoch) - MAINTENANCE_SINCE))
  say "$(kq_format u_maintenance_off "${SOURCE_VERSION}")"
  return 0
}

arm_rollback_traps() {
  trap 'rollback_and_die "$(kq_format u_f_unexpected "${LINENO}")" unexpected_error' ERR
  trap 'rollback_and_die "$(kq_format u_interrupted INT)" interrupted' INT
  trap 'rollback_and_die "$(kq_format u_interrupted TERM)" interrupted' TERM
  trap 'rollback_and_die "$(kq_format u_interrupted HUP)" interrupted' HUP
}

disarm_rollback_traps() {
  trap - ERR INT TERM HUP
}

phase_maintenance() {
  STEP="2"
  heading "$(kq_text u_phase_2)"

  arm_rollback_traps
  ROLLBACK_ARMED=1
  MAINTENANCE_SINCE="$(now_epoch)"

  detail_note "--- artisan down (${SOURCE_VERSION}) ---"
  if ! compose_current exec -T app php artisan down --retry="${KQ_MAINTENANCE_RETRY_SECONDS}" >>"$(detail_sink)" 2>&1; then
    rollback_and_die "$(kq_format u_f_maintenance_on "${CURRENT_COMPOSE}")" maintenance_failed
  fi
  write_maintenance_metric 1 "${MAINTENANCE_SINCE}"
  say "$(kq_text u_maintenance_on)"

  say "$(kq_format u_stop_workers "${SOURCE_VERSION}")"
  if ! compose_current stop horizon scheduler >>"$(detail_sink)" 2>&1; then
    rollback_and_die "$(kq_format u_f_stop_workers "${CURRENT_COMPOSE}")" workers_failed
  fi
}

#------------------------------------------------------------------------------
# Paso 3 — copia verificada, bloqueante. Con la version ACTUAL: es la que sabe
# leer su propio esquema y la que ya hace la copia diaria. Y DE ESTA EJECUCION:
# `backup:run --mode dump` deja su nombre en daily/LATEST, y ese fichero tiene
# que ser posterior al arranque del script; si no, la vuelta atras restauraria
# la copia de anoche y se llevaria el dia entero.
#------------------------------------------------------------------------------
backup_failed() {
  local message="$1"
  BACKUP_RESULT="$(kq_text u_report_failed)"
  disarm_rollback_traps
  if ! lift_maintenance; then
    rollback_incomplete_maintenance "${message}"
  fi
  ROLLBACK_ARMED=0
  die "${KQ_EXIT_REQUIREMENTS}" "${message}"
}

phase_backup() {
  local code=0 name mtime

  STEP="3"
  heading "$(kq_text u_phase_3)"
  say "$(kq_format u_backup_start "${SOURCE_VERSION}")"

  detail_note "--- backup:run --mode dump ---"
  compose_current exec -T app php artisan backup:run --mode=dump >>"$(detail_sink)" 2>&1 || code=$?
  if [ "${code}" -ne 0 ]; then
    backup_failed "$(kq_format u_f_backup "${code}" "$(kq_exit_name "${code}")" "${SOURCE_VERSION}")"
  fi

  name="$(head -n 1 "${CFG_BACKUP_PATH}/daily/LATEST" 2>/dev/null | tr -d '[:space:]' || true)"
  if [ -z "${name}" ] || [ ! -f "${CFG_BACKUP_PATH}/daily/${name}" ]; then
    backup_failed "$(kq_format u_f_backup_pointer "${CFG_BACKUP_PATH}/daily/LATEST")"
  fi
  mtime="$(stat -c '%Y' "${CFG_BACKUP_PATH}/daily/${name}" 2>/dev/null || printf '0')"
  if [ "${mtime}" -lt "${STARTED_AT}" ]; then
    backup_failed "$(kq_format u_f_backup_stale "${CFG_BACKUP_PATH}/daily/${name}")"
  fi
  BACKUP_FILE="${CFG_BACKUP_PATH}/daily/${name}"

  # `mtime` ya se comprobo mas nuevo que el arranque: es el instante de esta
  # copia, no el de otra. La huella es OPCIONAL (comentario junto a
  # KQ_BACKUP_FINGERPRINT_TIMEOUT_SECONDS): si sha256sum tarda o no esta, se
  # omite y el asiento se escribe igual sin ella.
  BACKUP_TAKEN_AT="$(date -u -d "@${mtime}" +%Y-%m-%dT%H:%M:%SZ 2>/dev/null || true)"
  BACKUP_SHA256=""
  if command -v sha256sum >/dev/null 2>&1 && command -v timeout >/dev/null 2>&1; then
    BACKUP_SHA256="$(timeout "${KQ_BACKUP_FINGERPRINT_TIMEOUT_SECONDS}" sha256sum "${BACKUP_FILE}" 2>/dev/null | cut -d' ' -f1 || true)"
  fi
  [[ "${BACKUP_SHA256}" =~ ^[0-9a-f]{64}$ ]] || BACKUP_SHA256=""

  BACKUP_RESULT="$(kq_text u_report_ok)"
  say "$(kq_format u_backup_done "${BACKUP_FILE}")"
}

#------------------------------------------------------------------------------
# Paso 4 — migraciones version a version, con punto de control.
#------------------------------------------------------------------------------
applied_migrations() {
  compose_new exec -T postgres psql -U "${CFG_DB_MIGRATION_USERNAME}" -d "${CFG_DB_DATABASE}" \
    -Atqc "SELECT migration FROM migrations" 2>/dev/null || true
}

last_migration_batch() {
  local batch
  batch="$(psql_migrator compose_new "SELECT coalesce(max(batch), 0) FROM migrations")"
  printf '%s' "${batch:-?}"
}

phase_migrations() {
  local version total pending_total applied name started seconds count batch
  declare -a files=()

  STEP="4"
  heading "$(kq_text u_phase_4)"
  ROLLBACK_ARMED=2

  if [ "${IN_PLACE}" -eq 1 ]; then
    if ! kq_env_set "${ENV_FILE}" "IMAGE_TAG" "${TARGET_VERSION}" || ! chmod 0600 "${ENV_FILE}"; then
      rollback_and_die "$(kq_format u_f_prepare_env "${ENV_FILE}")" env_prepare_failed
    fi
  fi

  # Infraestructura primero: una version puede traer una extension nueva en la
  # imagen de PostgreSQL que sus migraciones necesiten. Los datos viven en
  # volumenes y no se tocan.
  say "$(kq_format u_infra_up "${TARGET_VERSION}")"
  detail_note "--- up -d postgres redis (${TARGET_VERSION}) ---"
  compose_new up -d postgres redis >>"$(detail_sink)" 2>&1 ||
    rollback_and_die "$(kq_format u_f_infra_up "${TARGET_VERSION}")" service_start_failed
  wait_for_healthy compose_new postgres "${KQ_WAIT_DEPENDENCIES}" ||
    rollback_and_die "$(kq_format u_f_infra_up "${TARGET_VERSION}")" service_start_failed
  wait_for_healthy compose_new redis "${KQ_WAIT_DEPENDENCIES}" ||
    rollback_and_die "$(kq_format u_f_infra_up "${TARGET_VERSION}")" service_start_failed

  MIGRATIONS_IN_IMAGE="$(compose_new run --rm --no-deps -T app sh -c "ls -1 ${KQ_CONTAINER_MIGRATIONS}" 2>/dev/null |
    sed -n 's/\.php$//p' | sort || true)"
  [ -n "${MIGRATIONS_IN_IMAGE}" ] || rollback_and_die "$(kq_format u_f_migrations_list "${TARGET_VERSION}")" migration_failed
  applied="$(applied_migrations)"

  total="$(printf '%s\n' "${MIGRATIONS_IN_IMAGE}" | grep -c . || true)"
  pending_total="$(printf '%s\n' "${MIGRATIONS_IN_IMAGE}" | grep -vxF -f <(printf '%s\n' "${applied}") | grep -c . || true)"
  say "$(kq_format u_migrations_list "${TARGET_VERSION}" "${total}" "${pending_total}")"
  # Para el asiento `system.updated` (RF-PD-10): cuantas migraciones trae esta
  # cadena de actualizacion, no cuantas tiene la imagen en total.
  MIGRATIONS_APPLIED="${pending_total}"

  for version in "${UPGRADE_CHAIN[@]}"; do
    files=()
    while IFS= read -r name; do
      [ -n "${name}" ] || continue
      printf '%s\n' "${applied}" | grep -qxF "${name}" && continue
      files+=("${KQ_CONTAINER_MIGRATIONS}/${name}.php")
    done < <(printf '%s\n' "${MIGRATIONS_IN_IMAGE}" | kq_migrations_for_version "${version}")

    count="${#files[@]}"
    if [ "${count}" -eq 0 ]; then
      # Punto de control igualmente: la version esta alcanzada aunque no
      # trajera nada, y el informe y el operador tienen que verlo con la misma
      # marca que las demas.
      say "$(kq_format u_checkpoint_none "${version}")"
      CHECKPOINTS+=("${version}|0|0|-")
      LAST_CHECKPOINT="${version}"
      continue
    fi

    say "$(kq_format u_migrating_version "${version}" "${count}")"
    started="$(now_epoch)"
    declare -a args=()
    for name in "${files[@]}"; do
      args+=("--path=${name}")
    done
    detail_note "--- migrate (${version}) ---"
    if ! compose_new run --rm --no-deps -T app php artisan migrate --force --database=pgsql_migrator --realpath "${args[@]}" \
      >>"$(detail_sink)" 2>&1; then
      rollback_and_die "$(kq_format u_f_migrating_version "${version}" "${LAST_CHECKPOINT:-$(kq_text u_report_none)}")" migration_failed
    fi
    seconds=$(($(now_epoch) - started))
    batch="$(last_migration_batch)"
    CHECKPOINTS+=("${version}|${count}|${seconds}|${batch}")
    LAST_CHECKPOINT="${version}"
    say "$(kq_format u_checkpoint "${version}" "${count}" "${seconds}" "${batch}")"
    applied="$(applied_migrations)"
  done

  # Nada puede quedar pendiente: una migracion que la matriz no atribuye a
  # ninguna version es un error de empaquetado, y el actualizador no adivina.
  local pending
  pending="$(printf '%s\n' "${MIGRATIONS_IN_IMAGE}" | grep -vxF -f <(printf '%s\n' "${applied}") | tr '\n' ' ' || true)"
  if [ -n "${pending// /}" ]; then
    rollback_and_die "$(kq_format u_f_pending_left "${pending% }")" migration_failed
  fi
  say "$(kq_format u_no_pending "${TARGET_VERSION}")"
}

#------------------------------------------------------------------------------
# Paso 5 — arranque y verificacion SIN exponer la version nueva.
#------------------------------------------------------------------------------
verify_constraints() {
  local valid count
  count="$(psql_migrator compose_new "SELECT count(*) FROM pg_indexes WHERE tablename = 'shift_entries' AND indexname = '${KQ_RN01_INDEX}'")"
  [ "${count}" = "1" ] || {
    printf '%s' "${KQ_RN01_INDEX}"
    return 1
  }
  valid="$(psql_migrator compose_new "SELECT convalidated FROM pg_constraint WHERE conname = '${KQ_RN02_CONSTRAINT}'")"
  [ "${valid}" = "t" ] || {
    printf '%s' "${KQ_RN02_CONSTRAINT}"
    return 1
  }
  return 0
}

# El rol de la aplicacion puede escribir fichajes y NO puede tocar audit_log
# (regla dura 6). Se comprueba tras migrar y tras restaurar: pg_restore sin
# los privilegios del volcado dejaria una base en la que las sondas dicen
# «operativo» y ningun fichaje se puede escribir.
verify_privileges() {
  local compose_fn="$1" can_write cannot_touch
  can_write="$(psql_migrator "${compose_fn}" "SELECT has_table_privilege('${CFG_DB_USERNAME}', 'shift_entries', 'INSERT')")"
  cannot_touch="$(psql_migrator "${compose_fn}" "SELECT has_table_privilege('${CFG_DB_USERNAME}', 'audit_log', 'UPDATE') OR has_table_privilege('${CFG_DB_USERNAME}', 'audit_log', 'DELETE')")"
  [ "${can_write}" = "t" ] && [ "${cannot_touch}" = "f" ]
}

phase_start_and_verify() {
  local reported failed path body status service doctor_status doctor_output
  local audit_json audit_status audit_output

  STEP="5"
  heading "$(kq_format u_phase_5 "${TARGET_VERSION}")"

  say "$(kq_format u_app_up "${TARGET_VERSION}")"
  detail_note "--- up -d app, sin borde ni procesos de fondo (${TARGET_VERSION}) ---"
  compose_new up -d --remove-orphans --scale nginx=0 --scale horizon=0 --scale scheduler=0 --scale reverb=0 \
    >>"$(detail_sink)" 2>&1 || rollback_and_die "$(kq_format u_f_app_up "${TARGET_VERSION}")" service_start_failed
  wait_for_healthy compose_new app "${KQ_WAIT_APPLICATION}" ||
    rollback_and_die "$(kq_format u_f_app_up "${TARGET_VERSION}")" service_start_failed

  for path in /api/v1/health /api/v1/ready; do
    if app_probe "${path}" && [ "${PROBE_STATUS}" = "200" ]; then
      kq_msg check_ok "$(kq_format u_verify_probe_ok "${path}" "${PROBE_STATUS}")"
      remember_check "${path}" "$(kq_text u_report_ok)"
    else
      remember_check "${path}" "$(kq_text u_report_failed) (${PROBE_STATUS:-0})"
      detail_note "--- ${path}: ${PROBE_STATUS:-0} ---"
      detail_note "${PROBE_BODY}"
      rollback_and_die "$(kq_format u_f_verify_probe "${path}" "${PROBE_STATUS:-0}" 200)" health_probe_failed
    fi
    if [ "${path}" = "/api/v1/health" ]; then
      reported="$(json_field "${PROBE_BODY}" version)"
      if [ "${reported}" = "${TARGET_VERSION}" ]; then
        kq_msg check_ok "$(kq_format u_verify_version_ok "${reported}")"
        remember_check "version" "${reported}"
      else
        remember_check "version" "$(kq_text u_report_failed) (${reported:-?})"
        rollback_and_die "$(kq_format u_f_verify_version "${reported}" "${TARGET_VERSION}")" version_mismatch
      fi
    fi
  done

  detail_note "--- compliance:verify-audit-chain (despues) ---"
  if compose_new exec -T app php artisan compliance:verify-audit-chain >>"$(detail_sink)" 2>&1; then
    kq_msg check_ok "$(kq_text u_verify_chain_ok)"
    remember_check "audit-chain-after" "$(kq_text u_report_ok)"
  else
    remember_check "audit-chain-after" "$(kq_text u_report_failed)"
    rollback_and_die "$(kq_text u_f_verify_chain)" audit_chain_broken
  fi

  if failed="$(verify_constraints)"; then
    kq_msg check_ok "$(kq_text u_verify_constraints_ok)"
    remember_check "RN-01/RN-02" "$(kq_text u_report_ok)"
  else
    remember_check "RN-01/RN-02" "$(kq_text u_report_failed) (${failed})"
    rollback_and_die "$(kq_format u_f_verify_constraints "${failed}")" constraint_violation
  fi

  if verify_privileges compose_new; then
    kq_msg check_ok "$(kq_format u_verify_privileges_ok "${CFG_DB_USERNAME}")"
    remember_check "privileges" "$(kq_text u_report_ok)"
  else
    remember_check "privileges" "$(kq_text u_report_failed)"
    rollback_and_die "$(kq_format u_f_verify_privileges "${CFG_DB_USERNAME}")" privileges_check_failed
  fi

  # Punta de la cadena DESPUES de migrar y de verificar (RF-PD-10): con la
  # de `check_audit_chain` (CHAIN_BEFORE), es lo que el asiento `system.updated`
  # necesita para decir por donde iba el trail antes y despues de tocar nada.
  CHAIN_AFTER="$(json_field "$(compose_new exec -T app php artisan compliance:audit-chain-head 2>/dev/null || true)" hash)"

  audit_json="$(audit_json_object \
    "from_version=${SOURCE_VERSION}" \
    "to_version=${TARGET_VERSION}" \
    "migrations_applied=${MIGRATIONS_APPLIED}" \
    "chain_before=${CHAIN_BEFORE}" \
    "chain_after=${CHAIN_AFTER}" \
    "backup_fingerprint=${BACKUP_SHA256}" \
    "report_id=update-${STARTED_UTC}")"

  detail_note "--- compliance:record-system-event system.updated ---"
  audit_status=0
  audit_output="$(compose_new exec -T app php artisan compliance:record-system-event system.updated --data="${audit_json}" 2>&1)" ||
    audit_status=$?
  detail_note "${audit_output}"

  if [ "${audit_status}" -eq 0 ]; then
    kq_msg check_ok "$(kq_text u_verify_audit_entry_ok)"
    remember_check "audit-entry" "$(kq_text u_report_ok)"
  else
    # El trabajo YA ESTA HECHO: no se deshace una actualizacion correcta
    # porque su asiento no se pudo escribir (regla dura 6, doc del script,
    # tarea 5.7 cierre). Se marca para que final_report() salga con
    # KQ_EXIT_VERIFY_FAILED en vez de KQ_EXIT_OK.
    kq_msg check_warn "$(kq_format u_verify_audit_entry_warn "${audit_status}")" "$(kq_text u_verify_audit_entry_warn_fix)"
    remember_check "audit-entry" "$(kq_text u_report_failed) (${audit_status})"
    AUDIT_ENTRY_FAILED=1
  fi

  # `product:doctor` (tarea 5.9) es el diagnostico oficial del producto, pero
  # AQUI ES INFORMATIVO (revision de codigo, segunda vuelta): la version nueva
  # ya esta verificada por las sondas, la cadena de auditoria, las
  # restricciones RN-01/RN-02 y los privilegios de base de datos, y buena
  # parte de los fallos de product:doctor son AMBIENTALES —disco por debajo
  # del umbral, certificado a punto de caducar, APP_DEBUG— y no dicen nada
  # del esquema ni del codigo que se acaba de desplegar. Deshacer una
  # actualizacion correcta por eso seria desproporcionado. Por eso, a partir
  # de aqui, NINGUN codigo de `product:doctor` deshace: se muestra completo
  # en el detalle, se resume en el informe (correcto / con avisos / con
  # fallos) y en pantalla se avisa de que hacer si sale 1 o 2, pero la
  # actualizacion sigue.
  #
  # La UNICA razon para deshacer aqui es que el comando NO EXISTA en la
  # imagen: eso es un paquete roto, no un hallazgo ambiental del servidor.
  # Comprobacion de PRESENCIA, no de texto: `list --raw` enumera los comandos
  # tal cual los conoce la aplicacion, uno por linea. El mensaje de error de
  # Symfony Console ante un comando que no existe depende del idioma y de la
  # version del framework; preguntarle que comandos tiene no depende de
  # ninguno de los dos (mismo razonamiento que doctor.sh).
  # Sin tuberia: con `pipefail`, `grep -q` cierra el tubo en cuanto encuentra la
  # linea, PHP recibe SIGPIPE y la tuberia falla AUNQUE el comando exista (paso
  # en la 8b de la 5.9: U1 en verde y U3 «sin product:doctor» con la misma imagen).
  available_commands="$(compose_new exec -T app php artisan list --raw 2>/dev/null || true)"
  if ! printf '%s
' "${available_commands}" | grep -q '^product:doctor'; then
    remember_check "doctor" "$(kq_text u_report_failed)"
    rollback_and_die "$(kq_text u_f_verify_doctor_missing_command)" doctor_failed
  fi

  detail_note "--- product:doctor (${TARGET_VERSION}) ---"
  doctor_status=0
  doctor_output="$(compose_new exec -T app php artisan product:doctor --lang="${KQ_LANG}" 2>&1)" || doctor_status=$?
  detail_note "${doctor_output}"

  case "${doctor_status}" in
  0)
    kq_msg check_ok "$(kq_text u_verify_doctor_ok)"
    remember_check "doctor" "$(kq_text u_report_ok)"
    ;;
  1)
    kq_msg check_warn "$(kq_text u_verify_doctor_warn)" "$(kq_text u_verify_doctor_warn_fix)"
    remember_check "doctor" "$(kq_text u_report_warned)"
    ;;
  *)
    kq_msg check_warn "$(kq_format u_verify_doctor_failed_warn "${doctor_status}")" "$(kq_text u_verify_doctor_failed_fix)"
    remember_check "doctor" "$(kq_text u_report_doctor_failed)"
    ;;
  esac

  # La licencia NO bloquea (regla dura 15): se consulta para el informe, y solo
  # su resultado. Su salida lleva el nombre del cliente y no va a ningun
  # fichero (ADR-020).
  if compose_new exec -T app php artisan license:show >/dev/null 2>&1; then
    kq_msg check_ok "$(kq_text u_verify_license)"
    remember_check "license:show" "$(kq_text u_report_ok)"
  else
    kq_msg check_warn "$(kq_format u_verify_license_warn "${COMPOSE_FILE}")"
    remember_check "license:show" "$(kq_text u_report_failed)"
  fi

  # EL CONTENEDOR NUEVO NACE SIN MANTENIMIENTO (el fichero vive en el
  # contenedor anterior, ya retirado). Se pone AQUI, antes de publicar el
  # borde: entre que nginx acepta conexiones y que la sonda por loopback lo
  # confirma, los quioscos vaciarian sus colas contra una version que aun se
  # puede deshacer, y la vuelta atras se llevaria esos fichajes.
  say "$(kq_format u_maintenance_new "${TARGET_VERSION}")"
  detail_note "--- artisan down (${TARGET_VERSION}) ---"
  compose_new exec -T app php artisan down --retry="${KQ_MAINTENANCE_RETRY_SECONDS}" >>"$(detail_sink)" 2>&1 ||
    rollback_and_die "$(kq_format u_f_maintenance_on "${COMPOSE_FILE}")" maintenance_failed

  say "$(kq_format u_edge_up "${TARGET_VERSION}")"
  detail_note "--- up -d nginx (${TARGET_VERSION}) ---"
  compose_new up -d --no-deps nginx >>"$(detail_sink)" 2>&1 ||
    rollback_and_die "$(kq_format u_f_edge_up "${TARGET_VERSION}")" service_start_failed
  wait_for_healthy compose_new nginx "${KQ_WAIT_APPLICATION}" ||
    rollback_and_die "$(kq_format u_f_edge_up "${TARGET_VERSION}")" service_start_failed

  # A partir de aqui el borde esta abierto de verdad (SystemUpdateStep::Expose):
  # un fallo desde este punto ya no es «arranque sin exponer».
  STEP5_EXPOSED=1

  for path in /api/v1/health /api/v1/ready; do
    if body="$(edge_probe "${path}")"; then
      kq_msg check_ok "$(kq_format u_edge_probe_ok "${path}" "${TARGET_VERSION}")"
      remember_check "edge ${path}" "$(kq_text u_report_ok)"
    else
      remember_check "edge ${path}" "$(kq_text u_report_failed)"
      rollback_and_die "$(kq_format u_f_edge_probe "${path}" "${CFG_HTTPS_PORT}" "${COMPOSE_FILE}")" health_probe_failed
    fi
  done
  reported="$(json_field "$(edge_probe /api/v1/health || true)" version)"
  [ "${reported}" = "${TARGET_VERSION}" ] ||
    rollback_and_die "$(kq_format u_f_verify_version "${reported}" "${TARGET_VERSION}")" version_mismatch

  # Verificado: se abre. Y se comprueba que se ha abierto de verdad, porque
  # las dos sondas no lo distinguen: una ruta de gestion sin sesion responde
  # 401 si la aplicacion atiende y 503 si sigue en mantenimiento.
  detail_note "--- artisan up (${TARGET_VERSION}) ---"
  compose_new exec -T app php artisan up >>"$(detail_sink)" 2>&1 ||
    rollback_and_die "$(kq_format u_f_maintenance_off "${COMPOSE_FILE}")" maintenance_failed
  write_maintenance_metric 0 "${MAINTENANCE_SINCE}"
  status="$(edge_status "${KQ_MANAGEMENT_PROBE}")"
  if [ "${status}" = "401" ]; then
    kq_msg check_ok "$(kq_format u_verify_lifted_ok "${KQ_MANAGEMENT_PROBE}")"
    remember_check "maintenance-lifted" "$(kq_text u_report_ok)"
  else
    remember_check "maintenance-lifted" "$(kq_text u_report_failed) (${status})"
    rollback_and_die "$(kq_format u_f_verify_lifted "${KQ_MANAGEMENT_PROBE}" "${status}")" health_probe_failed
  fi
  MAINTENANCE_SECONDS=$(($(now_epoch) - MAINTENANCE_SINCE))

  say "$(kq_format u_workers_up "${TARGET_VERSION}")"
  detail_note "--- up -d (resto de servicios, ${TARGET_VERSION}) ---"
  compose_new up -d --remove-orphans >>"$(detail_sink)" 2>&1 ||
    rollback_and_die "$(kq_format u_f_workers_up "${TARGET_VERSION}")" workers_failed
  for service in horizon scheduler reverb; do
    wait_for_healthy compose_new "${service}" "${KQ_WAIT_WORKERS}" ||
      rollback_and_die "$(kq_format u_f_workers_up "${TARGET_VERSION}")" workers_failed
  done
}

#------------------------------------------------------------------------------
# Paso 6 — vuelta atras automatica.
#------------------------------------------------------------------------------
preserve_failed_state() {
  detail_note ""
  detail_note "--- $(kq_text u_rollback_evidence) ---"
  compose_new ps -a >>"$(detail_sink)" 2>&1 || true
  compose_new logs --tail 80 app >>"$(detail_sink)" 2>&1 || true
  detail_note "--- fin ---"
  say "$(kq_format u_rollback_evidence_at "${DETAIL_FILE:-?}")"
}

# Solo estaba puesto el mantenimiento y no se ha podido retirar: la instalacion
# sigue en su version con sus datos; lo unico que hay que hacer a mano es
# `artisan up`. NO hay ninguna copia que restaurar, y decirlo es lo que impide
# que alguien restaure la de anoche.
rollback_incomplete_maintenance() {
  ROLLBACK_SUMMARY="$(kq_format u_report_rollback "${STEP}" "$1" "$(kq_text u_report_needs_person)")"
  FINAL_STATE="${SOURCE_VERSION}"
  err ""
  err "$(kq_format u_rollback_incomplete_maintenance "${SOURCE_VERSION}" "${CURRENT_ENV}" "${CURRENT_COMPOSE}" "${CURRENT_ENV}" "${CURRENT_COMPOSE}")"
  err "$(kq_format exit_line "${KQ_EXIT_ROLLBACK_INCOMPLETE}" "$(kq_exit_name "${KQ_EXIT_ROLLBACK_INCOMPLETE}")")"
  close_report "${KQ_EXIT_ROLLBACK_INCOMPLETE}"
  exit "${KQ_EXIT_ROLLBACK_INCOMPLETE}"
}

rollback_incomplete() {
  ROLLBACK_SUMMARY="$(kq_format u_report_rollback "${STEP}" "$1" "$(kq_text u_report_needs_person)")"
  FINAL_STATE="?"
  err ""
  err "$(kq_format u_rollback_incomplete \
    "${ENV_FILE}" "${COMPOSE_FILE}" \
    "${ENV_FILE}" "${COMPOSE_FILE}" "${BACKUP_FILE}" \
    "${SOURCE_VERSION}" "${ROLLBACK_ENV}" "${ROLLBACK_COMPOSE}" \
    "${CFG_HTTPS_PORT}" "${SOURCE_VERSION}")"
  err "$(kq_format exit_line "${KQ_EXIT_ROLLBACK_INCOMPLETE}" "$(kq_exit_name "${KQ_EXIT_ROLLBACK_INCOMPLETE}")")"
  close_report "${KQ_EXIT_ROLLBACK_INCOMPLETE}"
  exit "${KQ_EXIT_ROLLBACK_INCOMPLETE}"
}

rollback_and_die() {
  local reason="$1" reason_code="${2:-unexpected_error}" code=0 body reported from_date to_date
  local failed_step audit_json audit_status audit_output

  # SOLO EN EL PROCESO PRINCIPAL. Con `set -E` el trap se hereda en las
  # subshells de `$(...)`; deshacer desde ahi restauraria la base y el padre
  # seguiria adelante sin saberlo (es el fallo que rompio la etapa ⑧ del
  # instalador en su primera ejecucion).
  if [ "${BASHPID}" != "$$" ]; then
    return 1
  fi
  disarm_rollback_traps
  set +e

  # Se resuelve AQUI, con el STEP de cuando se disparo la vuelta atras: nada
  # de lo que sigue en esta funcion cambia de paso (RF-PD-10, SystemUpdateStep).
  failed_step="$(resolve_failed_step)"

  heading "$(kq_text u_phase_6)"
  err "$(kq_format u_rollback_reason "${reason}")"

  if [ "${ROLLBACK_ARMED}" -le 1 ]; then
    # Solo el mantenimiento estaba puesto: retirarlo deja todo como estaba.
    if lift_maintenance; then
      ROLLBACK_SUMMARY="$(kq_format u_report_rollback "${STEP}" "${reason}" "$(kq_text u_report_ok)")"
      FINAL_STATE="${SOURCE_VERSION}"
      err "$(kq_format exit_line "${KQ_EXIT_ROLLED_BACK}" "$(kq_exit_name "${KQ_EXIT_ROLLED_BACK}")")"
      close_report "${KQ_EXIT_ROLLED_BACK}"
      exit "${KQ_EXIT_ROLLED_BACK}"
    fi
    rollback_incomplete_maintenance "${reason}"
  fi

  preserve_failed_state

  say "$(kq_format u_rollback_stop "${TARGET_VERSION}")"
  detail_note "--- stop (${TARGET_VERSION}) ---"
  compose_new stop app horizon scheduler reverb nginx >>"$(detail_sink)" 2>&1
  compose_new up -d postgres redis >>"$(detail_sink)" 2>&1
  wait_for_healthy compose_new postgres "${KQ_WAIT_DEPENDENCIES}" || rollback_incomplete "${reason}"

  # La punta de la cadena que se va a DESCARTAR, leida en el ultimo instante
  # en que la base todavia es la migrada (RF-PD-10, RS-07): es la unica prueba
  # de que hubo un intervalo que ya no esta. `app` esta parado (arriba); se
  # levanta un contenedor suelto solo para leerla, como hace restore.sh.
  detail_note "--- compliance:audit-chain-head (punta a punto de descartarse) ---"
  CHAIN_DISCARDED="$(json_field "$(compose_new run --rm --no-deps -T app php artisan compliance:audit-chain-head 2>/dev/null || true)" hash)"

  say "$(kq_format u_rollback_restore "${BACKUP_FILE}")"
  detail_note "--- restore.sh --file ${BACKUP_FILE} --yes ---"
  compose_new run --rm --no-deps -T app bash "${KQ_CONTAINER_SCRIPTS}/restore.sh" --file "${BACKUP_FILE}" --yes \
    >>"$(detail_sink)" 2>&1
  code=$?
  if [ "${code}" -ne 0 ]; then
    err "$(kq_format u_f_rollback_restore "${code}" "$(kq_exit_name "${code}")")"
    rollback_incomplete "${reason}"
  fi
  if ! verify_privileges compose_new; then
    err "$(kq_format u_f_verify_privileges "${CFG_DB_USERNAME}")"
    rollback_incomplete "${reason}"
  fi
  say "$(kq_text u_rollback_restore_ok)"

  say "$(kq_format u_rollback_relaunch "${SOURCE_VERSION}" "${ROLLBACK_COMPOSE}")"
  detail_note "--- up -d (${SOURCE_VERSION}) ---"
  compose_new stop postgres redis >>"$(detail_sink)" 2>&1
  compose_rollback up -d --remove-orphans >>"$(detail_sink)" 2>&1
  if ! wait_for_healthy compose_rollback postgres "${KQ_WAIT_DEPENDENCIES}" ||
    ! wait_for_healthy compose_rollback app "${KQ_WAIT_APPLICATION}" ||
    ! wait_for_healthy compose_rollback nginx "${KQ_WAIT_APPLICATION}"; then
    err "$(kq_format u_f_rollback_relaunch "${SOURCE_VERSION}")"
    rollback_incomplete "${reason}"
  fi
  # La version anterior arranca en un contenedor nuevo, sin el fichero de
  # mantenimiento: `up` por si acaso, y no importa que no hubiera nada.
  compose_rollback exec -T app php artisan up >>"$(detail_sink)" 2>&1
  write_maintenance_metric 0 "${MAINTENANCE_SINCE}"

  body="$(edge_probe /api/v1/health)" || {
    err "$(kq_format u_f_rollback_relaunch "${SOURCE_VERSION}")"
    rollback_incomplete "${reason}"
  }
  reported="$(json_field "${body}" version)"
  [ "${reported}" = "${SOURCE_VERSION}" ] || {
    err "$(kq_format u_f_verify_version "${reported}" "${SOURCE_VERSION}")"
    rollback_incomplete "${reason}"
  }
  edge_probe /api/v1/ready >/dev/null || {
    err "$(kq_format u_f_rollback_relaunch "${SOURCE_VERSION}")"
    rollback_incomplete "${reason}"
  }
  [ "$(edge_status "${KQ_MANAGEMENT_PROBE}")" = "401" ] || {
    err "$(kq_format u_f_rollback_management "${SOURCE_VERSION}" "${KQ_MANAGEMENT_PROBE}" "$(edge_status "${KQ_MANAGEMENT_PROBE}")")"
    rollback_incomplete "${reason}"
  }
  say "$(kq_format u_rollback_verify_ok "${SOURCE_VERSION}" "/api/v1/health /api/v1/ready ${KQ_MANAGEMENT_PROBE}")"

  detail_note "--- compliance:verify-audit-chain (tras la vuelta atras) ---"
  if compose_rollback exec -T app php artisan compliance:verify-audit-chain >>"$(detail_sink)" 2>&1; then
    remember_check "audit-chain-after-rollback" "$(kq_text u_report_ok)"
  else
    remember_check "audit-chain-after-rollback" "$(kq_text u_report_failed)"
    rollback_incomplete "${reason}"
  fi

  # El asiento `system.restored_from_backup` (RF-PD-10, RL-04, RS-07, regla
  # dura 6): con `CHAIN_DISCARDED` es lo unico que deja, dentro del propio
  # registro, la prueba de que un intervalo de fichajes reales quedo fuera de
  # la base que sirve ahora. Si falla, la vuelta atras YA ESTA HECHA y
  # verificada: no se reintenta (instruccion (e)); se queda escrito en el
  # informe y la salida sigue siendo KQ_EXIT_ROLLED_BACK.
  audit_json="$(audit_json_object \
    "backup_file=$(basename -- "${BACKUP_FILE}")" \
    "backup_taken_at=${BACKUP_TAKEN_AT}" \
    "failed_step=${failed_step}" \
    "reason=${reason_code}" \
    "from_version=${SOURCE_VERSION}" \
    "to_version=${TARGET_VERSION}" \
    "backup_fingerprint=${BACKUP_SHA256}" \
    "chain_before=${CHAIN_DISCARDED}" \
    "report_id=update-${STARTED_UTC}")"

  # LO ESCRIBE LA IMAGEN NUEVA, no la restaurada. Tras la vuelta atras corre la
  # version anterior, y la version anterior puede no conocer todavia el comando
  # (la primera que lo lleva vuelve a una que no lo tiene: asi se vio en la
  # etapa 8b del cierre de la Fase 5). Los dos proyectos son el MISMO (`name:
  # kronoqr`), asi que un contenedor efimero de la imagen nueva llega al
  # PostgreSQL que ya sirve la base restaurada; `--no-deps` para no levantar
  # nada de la pila nueva, que esta parada. El esquema de `audit_log` que el
  # asiento necesita (actor `system`, cadena por hash) es el de la tarea 1.14 y
  # no ha cambiado desde entonces, asi que el codigo nuevo escribe en una base
  # antigua sin tocar nada mas.
  detail_note "--- compliance:record-system-event system.restored_from_backup (imagen nueva, base restaurada) ---"
  # ...PERO SOLO SI LA VERSION RESTAURADA CONOCE LA ACCION. Un verificador
  # anterior a la accion (`compliance:verify-audit-chain` de la 2.1.0 y antes)
  # hace `AuditAction::from()` sobre cada fila y revienta con una que no esta en
  # su catalogo: el asiento que documenta la discontinuidad dejaria a la
  # instalacion restaurada sin verificacion nocturna hasta la siguiente
  # actualizacion, que es peor que no tenerlo. Se comprueba si la version que
  # queda en pie tiene el comando (lo tiene desde la misma version que la
  # accion); si no, no se escribe, y el informe dice que hay que escribirlo a
  # mano tras la proxima actualizacion, con los datos de este mismo informe.
  if compose_rollback exec -T app php artisan list --raw 2>/dev/null | grep -q '^compliance:record-system-event'; then
    audit_status=0
    audit_output="$(compose_new run --rm --no-deps -T app php artisan compliance:record-system-event system.restored_from_backup --data="${audit_json}" 2>&1)" ||
      audit_status=$?
    detail_note "${audit_output}"

    if [ "${audit_status}" -eq 0 ]; then
      say "$(kq_text u_rollback_audit_entry_ok)"
      remember_check "audit-entry-rollback" "$(kq_text u_report_ok)"
    else
      err "$(kq_format u_rollback_audit_entry_failed "${audit_status}")"
      remember_check "audit-entry-rollback" "$(kq_text u_report_failed) (${audit_status})"
    fi
  else
    detail_note "${audit_json}"
    err "$(kq_format u_rollback_audit_entry_skipped "${SOURCE_VERSION}")"
    remember_check "audit-entry-rollback" "$(kq_format u_rollback_audit_entry_skipped "${SOURCE_VERSION}")"
  fi

  # daily_totals es una proyeccion reconstruible (regla dura 7): se reconcilian
  # las jornadas de la ventana. El rango va holgado a proposito —dos dias antes
  # del arranque y hasta manana, en UTC—: cubre la jornada anterior, a la que
  # pertenece un turno de noche que cruzo la medianoche (regla dura 4), y
  # cualquier desfase entre UTC y la zona del centro. Reconciliar de mas es
  # barato; reconciliar de menos deja una divergencia sin contar. No bloquea:
  # la proyeccion no es el registro.
  say "$(kq_text u_reconcile)"
  from_date="$(date -u -d "@$((MAINTENANCE_SINCE - 172800))" +%Y-%m-%d 2>/dev/null || date -u +%Y-%m-%d)"
  to_date="$(date -u -d "+1 day" +%Y-%m-%d 2>/dev/null || date -u +%Y-%m-%d)"
  detail_note "--- attendance:reconcile --from=${from_date} --to=${to_date} ---"
  compose_rollback exec -T app php artisan attendance:reconcile --from="${from_date}" --to="${to_date}" \
    >>"$(detail_sink)" 2>&1 || true

  MAINTENANCE_SECONDS=$(($(now_epoch) - MAINTENANCE_SINCE))
  ROLLBACK_SUMMARY="$(kq_format u_report_rollback "${STEP}" "${reason}" "$(kq_text u_report_ok)")"
  FINAL_STATE="${SOURCE_VERSION}"
  err ""
  err "$(kq_format u_rollback_done "${SOURCE_VERSION}" "${REPORT_FILE:-?}")"
  err "$(kq_format exit_line "${KQ_EXIT_ROLLED_BACK}" "$(kq_exit_name "${KQ_EXIT_ROLLED_BACK}")")"
  close_report "${KQ_EXIT_ROLLED_BACK}"
  exit "${KQ_EXIT_ROLLED_BACK}"
}

#------------------------------------------------------------------------------
# Paso 7 — informe y despedida.
#------------------------------------------------------------------------------
final_report() {
  local exit_code="${KQ_EXIT_OK}"

  STEP="7"
  heading "$(kq_text u_phase_7)"
  FINAL_STATE="${TARGET_VERSION}"

  # La copia previa del .env del modo in-place ya no hace falta: es una segunda
  # copia de todos los secretos y no se deja por ahi.
  if [ "${IN_PLACE}" -eq 1 ] && [ -f "${ROLLBACK_ENV}" ]; then
    rm -f "${ROLLBACK_ENV}"
  fi

  # El trabajo se hizo: no se deshace nada por esto (instruccion (e)). Se sale
  # con KQ_EXIT_VERIFY_FAILED en vez de KQ_EXIT_OK para que quien automatiza
  # la actualizacion note que el asiento de auditoria no quedo escrito.
  if [ "${AUDIT_ENTRY_FAILED}" -eq 1 ]; then
    exit_code="${KQ_EXIT_VERIFY_FAILED}"
  fi

  close_report "${exit_code}"

  heading "$(kq_format u_done_title "${SOURCE_VERSION}" "${TARGET_VERSION}")"
  say ""
  say "$(kq_format u_done_report "${REPORT_FILE:-?}" "${DETAIL_FILE:-?}")"
  say "$(kq_format u_done_backup "${BACKUP_FILE}")"
  say ""
  say "$(kq_text u_done_queue)"
  if [ "${IN_PLACE}" -eq 0 ]; then
    say ""
    say "$(kq_format u_done_old_dir "${CURRENT_DIR}")"
  fi

  if [ "${exit_code}" -ne "${KQ_EXIT_OK}" ]; then
    err ""
    err "$(kq_format exit_line "${exit_code}" "$(kq_exit_name "${exit_code}")")"
  fi
  exit "${exit_code}"
}

#------------------------------------------------------------------------------
# Modos informativos: sin Docker, sin instalacion. Los usa la CI para construir
# la matriz de la etapa ⑧ y el runbook para explicar la ventana.
#------------------------------------------------------------------------------
print_info() {
  kq_versions_load "${VERSIONS_FILE}" ||
    die "${KQ_EXIT_REQUIREMENTS}" "$(kq_format u_f_versions_file "${VERSIONS_FILE}" "${KQ_VERSIONS_ERROR}")"
  if [ -z "${TARGET_VERSION}" ] || ! kq_semver_valid "${TARGET_VERSION}"; then
    die "${KQ_EXIT_REQUIREMENTS}" "$(kq_format u_f_package_file "VERSION")"
  fi

  case "${OPT_INFO}" in
  sources)
    local out
    out="$(kq_supported_sources "${TARGET_VERSION}")"
    if [ -n "${out}" ]; then
      printf '%s\n' "${out}"
    else
      printf '%s\n' "$(kq_format u_supported_none "${TARGET_VERSION}")"
    fi
    ;;
  chain)
    kq_semver_valid "${OPT_INFO_ARG}" || die "${KQ_EXIT_USAGE}" "$(kq_format u_bad_option "--chain ${OPT_INFO_ARG}")"
    local out
    out="$(kq_upgrade_chain "${OPT_INFO_ARG}" "${TARGET_VERSION}")"
    if [ -n "${out}" ]; then
      printf '%s\n' "${out}"
    else
      printf '%s\n' "$(kq_format u_chain_none "${OPT_INFO_ARG}" "${TARGET_VERSION}")"
    fi
    ;;
  esac
}

#------------------------------------------------------------------------------
main() {
  parse_arguments "$@"
  kq_msg_init "${OPT_LANG}"
  resolve_package_paths
  STARTED_AT="$(now_epoch)"
  STARTED_UTC="$(timestamp_utc)"

  if [ -n "${OPT_INFO}" ]; then
    print_info
    exit "${KQ_EXIT_OK}"
  fi

  phase_preconditions

  if [ "${OPT_CHECK_ONLY}" -eq 1 ]; then
    say ""
    say "$(kq_text u_check_only_done)"
    close_report "${KQ_EXIT_OK}"
    exit "${KQ_EXIT_OK}"
  fi

  prepare_package
  phase_maintenance
  phase_backup
  phase_migrations
  phase_start_and_verify

  disarm_rollback_traps
  final_report
}

# La guarda permite CARGAR este fichero sin ejecutarlo: es lo que hace la prueba
# de integracion para ejercitar la matriz de versiones y la cadena aisladas.
if [ "${BASH_SOURCE[0]}" = "$0" ]; then
  main "$@"
fi
