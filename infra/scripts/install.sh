#!/usr/bin/env bash
#
# KronoQR — instalacion en el servidor del cliente (RF-PD-02, RQ-11).
#
# PROPOSITO. Dejar KronoQR en marcha y VERIFICADO en un servidor Linux virgen,
# ejecutado por el IT del hotel, que no conoce Laravel ni tiene por que.
#
# EL PRINCIPIO QUE LO GOBIERNA (doc 02 §3.5): un instalador que falla a medias
# es peor que uno que no arranca. De ahi el orden estricto —comprobar todo,
# decidir, actuar— y de ahi que cualquier fallo despues de empezar a escribir
# deshaga lo que ESTA ejecucion hizo y lo diga.
#
# LAS CINCO FASES, en este orden y sin saltarse ninguna:
#   1  Requisitos. NO SE ESCRIBE NADA. Docker, Compose, CPU, RAM, disco,
#      puertos, permisos, APP_URL, certificado y los valores que rellena el
#      cliente. Cada fallo dice QUE HACER, no solo que falta.
#   2  Instalacion previa. Si la hay, se informa y se sale con codigo 3: este
#      script no reinstala encima de un registro horario que hay que conservar
#      cuatro anos. Para actualizar esta update.sh.
#   3  Secretos. Se generan AQUI, con openssl, y no se transmiten a nadie
#      (doc 02 §7.7, RS-08). El .env queda en 0600. NINGUN SECRETO SE IMPRIME
#      NI SE ESCRIBE EN EL LOG.
#   4  Arranque y esquema. Espera por CONDICION, nunca por `sleep`. Migraciones
#      con el rol de migracion. Cero datos de demostracion: el perfil de
#      convenio y los catalogos los siembran las propias migraciones.
#   5  Verificacion. /api/v1/health, /api/v1/ready y `license:show`. Sin
#      verificar, la instalacion NO se declara correcta.
#
# USO
#   ./install.sh                 instala
#   ./install.sh --check-only    solo la fase 1; no escribe nada
#   ./install.sh --help          ayuda completa, en el idioma del sistema
#
# CODIGOS DE SALIDA (identicos en install.sh, update.sh, doctor.sh, backup.sh y
# restore.sh; la tabla vive en lib/exit-codes.sh y en docs/cliente/operacion.md)
#   0  Correcto.
#   1  Uso incorrecto. Nada tocado.
#   2  Requisitos no cumplidos. NADA ESCRITO; el servidor esta como estaba.
#   3  Hay una instalacion previa. NADA ESCRITO. Para actualizar: update.sh.
#   4  Fallo con VUELTA ATRAS COMPLETADA. Se puede reintentar tras corregir.
#   5  Fallo con VUELTA ATRAS INCOMPLETA. Hay que intervenir a mano; el mensaje
#      dice exactamente que ha quedado y que orden lo retira.
#   6  Verificacion posterior fallida, con los servicios en pie. No se deshace
#      nada: la instalacion y sus datos existen.
#
# NO EXISTE install.ps1 (ADR-022). Los requisitos publicados son Linux con
# Docker; un cliente con solo infraestructura Windows instala sobre una maquina
# virtual Linux, y eso se dice en docs/cliente/instalacion.md antes de empezar.
#
# Que NO hace este script, a proposito:
#   · No crea usuarios. La primera cuenta de gestion la crea el asistente de
#     puesta en marcha, desde el panel, y su alta queda auditada (regla dura 6).
#   · No siembra datos de demostracion. Ni uno.
#   · No exige licencia. Un sistema recien instalado tiene que poder fichar
#     aunque la clave llegue una semana despues (regla dura 15, ADR-019).
#   · No borra nada previo. Si encuentra una instalacion, se aparta.

set -Eeuo pipefail
IFS=$'\n\t'

SCRIPT_DIR="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"
readonly SCRIPT_DIR

# shellcheck source-path=SCRIPTDIR
# shellcheck source=lib/exit-codes.sh disable=SC1091
. "${SCRIPT_DIR}/lib/exit-codes.sh"
# shellcheck source=lib/messages.sh disable=SC1091
. "${SCRIPT_DIR}/lib/messages.sh"
# shellcheck source=lib/env-file.sh disable=SC1091
. "${SCRIPT_DIR}/lib/env-file.sh"
# shellcheck source=lib/fs.sh disable=SC1091
. "${SCRIPT_DIR}/lib/fs.sh"

#------------------------------------------------------------------------------
# Umbrales publicados (doc 02 §11.6.2). Son los MINIMOS, no los recomendados.
#------------------------------------------------------------------------------
readonly KQ_MIN_CPU_CORES=2
# 3700 y no 4096 midiendo la realidad: una maquina virtual de 4 GB declara
# entre 3800 y 3950 MiB de RAM total porque el propio nucleo se reserva una
# parte. Exigir 4096 haria que TODA maquina de 4 GB —el minimo publicado—
# fallara la comprobacion. El umbral efectivo esta documentado en
# docs/cliente/instalacion.md junto a esta explicacion.
readonly KQ_MIN_RAM_MIB=3700
readonly KQ_MIN_DISK_GIB=40
readonly KQ_MIN_DOCKER_MAJOR=24

# Esperas por condicion. No son `sleep` a ciegas: se consulta el estado cada
# KQ_POLL_SECONDS y se abandona al llegar al techo, diciendo donde mirar.
readonly KQ_POLL_SECONDS=2
readonly KQ_WAIT_DEPENDENCIES=180
readonly KQ_WAIT_APPLICATION=180

readonly KQ_COMPOSE_PROJECT="kronoqr"

#------------------------------------------------------------------------------
# Estado
#------------------------------------------------------------------------------
OPT_CHECK_ONLY=0
OPT_LANG=""
OPT_ENV_FILE=""
OPT_COMPOSE_FILE=""

COMPOSE_FILE=""
ENV_FILE=""
ENV_TEMPLATE=""
PRODUCT_VERSION=""
DOCS_DIR=""

CHECKS_RUN=0
CHECKS_FAILED=0
CHECKS_WARNED=0

# Acciones que deshacen lo que ESTA ejecucion ha hecho, en orden de ejecucion.
# Se recorren en orden inverso. Cada entrada es "etiqueta|orden".
declare -a ROLLBACK_STACK=()
declare -a TEMP_FILES=()

# Valores leidos del .env del cliente. Se rellenan en la fase 1.
CFG_APP_URL=""
CFG_HTTP_PORT="80"
CFG_HTTPS_PORT="443"
CFG_BACKUP_PATH="/var/backups/fichaje"
CFG_TLS_CERT_DIR=""
CFG_TLS_ALLOW_SELF_SIGNED="false"

#------------------------------------------------------------------------------
# Salida
#------------------------------------------------------------------------------
say() {
  printf '%s\n' "$*"
}

heading() {
  printf '\n%s\n' "$*"
}

err() {
  printf '%s\n' "$*" >&2
}

# Termina con un codigo de la tabla comun y un mensaje que dice que hacer.
die() {
  local code="$1"
  shift
  err ""
  err "ERROR: $*"
  err "$(kq_format exit_line "${code}" "$(kq_exit_name "${code}")")"
  exit "${code}"
}

cleanup_temp_files() {
  local file
  for file in ${TEMP_FILES+"${TEMP_FILES[@]}"}; do
    [ -f "${file}" ] && rm -f "${file}"
  done
  return 0
}

trap cleanup_temp_files EXIT

#------------------------------------------------------------------------------
# Vuelta atras
#------------------------------------------------------------------------------

# Registra como deshacer algo ANTES de hacerlo. El orden importa: se deshace en
# orden inverso al de creacion, igual que se desmonta un andamio.
register_undo() {
  ROLLBACK_STACK+=("$1|$2")
}

# Retira una accion de la pila porque ya no aplica: lo que deshacia ha dejado
# de existir a proposito. Sin esto, borrar la copia previa del .env dejaria en
# la pila un `mv` de un fichero ausente, y un fallo posterior convertiria una
# vuelta atras limpia en una incompleta.
discard_undo() {
  local etiqueta="$1" index
  declare -a resto=()

  for ((index = 0; index < ${#ROLLBACK_STACK[@]}; index++)); do
    [ "${ROLLBACK_STACK[index]%%|*}" = "${etiqueta}" ] && continue
    resto+=("${ROLLBACK_STACK[index]}")
  done

  ROLLBACK_STACK=(${resto+"${resto[@]}"})
}

# Deshace lo hecho en esta ejecucion y sale con 4 (todo deshecho) o con 5 (algo
# quedo a medias). Nunca toca nada que existiera antes: la fase 2 ya garantizo
# que no habia instalacion previa, y el .env del cliente se restaura desde la
# copia que la fase 3 hizo de el.
rollback_and_die() {
  local reason="$1"
  local index label command incomplete=0

  err ""
  err "ERROR: ${reason}"
  err ""
  err "$(kq_text rollback_start)"

  for ((index = ${#ROLLBACK_STACK[@]} - 1; index >= 0; index--)); do
    label="${ROLLBACK_STACK[index]%%|*}"
    command="${ROLLBACK_STACK[index]#*|}"

    if eval "${command}" >/dev/null 2>&1; then
      err "$(kq_format rollback_item "${label}")"
    else
      err "$(kq_format rollback_failed_item "${label}")"
      incomplete=1
    fi
  done

  err ""

  if [ "${incomplete}" -eq 0 ]; then
    err "$(kq_text rollback_done)"
    err "$(kq_format exit_line "${KQ_EXIT_ROLLED_BACK}" "$(kq_exit_name "${KQ_EXIT_ROLLED_BACK}")")"
    exit "${KQ_EXIT_ROLLED_BACK}"
  fi

  err "$(kq_format rollback_incomplete "${COMPOSE_FILE}" "${ENV_FILE}")"
  err "$(kq_format exit_line "${KQ_EXIT_ROLLBACK_INCOMPLETE}" "$(kq_exit_name "${KQ_EXIT_ROLLBACK_INCOMPLETE}")")"
  exit "${KQ_EXIT_ROLLBACK_INCOMPLETE}"
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
    --lang)
      [ "$#" -ge 2 ] || die "${KQ_EXIT_USAGE}" "$(kq_format missing_value "--lang")"
      OPT_LANG="$2"
      shift
      ;;
    --lang=*)
      OPT_LANG="${1#--lang=}"
      ;;
    --env-file)
      [ "$#" -ge 2 ] || die "${KQ_EXIT_USAGE}" "$(kq_format missing_value "--env-file")"
      OPT_ENV_FILE="$2"
      shift
      ;;
    --env-file=*)
      OPT_ENV_FILE="${1#--env-file=}"
      ;;
    --compose-file)
      [ "$#" -ge 2 ] || die "${KQ_EXIT_USAGE}" "$(kq_format missing_value "--compose-file")"
      OPT_COMPOSE_FILE="$2"
      shift
      ;;
    --compose-file=*)
      OPT_COMPOSE_FILE="${1#--compose-file=}"
      ;;
    --help | -h)
      kq_msg_init "${OPT_LANG}"
      kq_msg usage
      exit "${KQ_EXIT_OK}"
      ;;
    --version)
      resolve_paths_quietly
      printf '%s\n' "${PRODUCT_VERSION:-$(kq_text version_unknown)}"
      exit "${KQ_EXIT_OK}"
      ;;
    *)
      kq_msg_init "${OPT_LANG}"
      die "${KQ_EXIT_USAGE}" "$(kq_format bad_option "$1")"
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
# Rutas
#------------------------------------------------------------------------------

# El instalador funciona en los dos sitios donde vive: dentro del repositorio
# (infra/scripts/install.sh, con el compose en infra/) y dentro del paquete de
# entrega (install.sh con docker-compose.yml al lado). Se busca en ese orden y
# no se adivina nada mas: si no aparece, se dice donde tiene que estar.
resolve_paths_quietly() {
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

  local package_dir="${SCRIPT_DIR}"
  [ -n "${COMPOSE_FILE}" ] && package_dir="$(dirname -- "${COMPOSE_FILE}")"

  if [ -n "${OPT_ENV_FILE}" ]; then
    ENV_FILE="${OPT_ENV_FILE}"
  else
    ENV_FILE="${package_dir}/.env"
  fi

  for candidate in \
    "${package_dir}/.env.example" \
    "${SCRIPT_DIR}/.env.example" \
    "${package_dir}/../.env.example"; do
    if [ -f "${candidate}" ]; then
      ENV_TEMPLATE="${candidate}"
      break
    fi
  done

  for candidate in \
    "${package_dir}/VERSION" \
    "${SCRIPT_DIR}/VERSION" \
    "${package_dir}/../VERSION"; do
    if [ -f "${candidate}" ]; then
      PRODUCT_VERSION="$(tr -d '[:space:]' <"${candidate}")"
      break
    fi
  done

  for candidate in \
    "${package_dir}/docs" \
    "${package_dir}/../docs/cliente"; do
    if [ -d "${candidate}" ]; then
      DOCS_DIR="${candidate}"
      break
    fi
  done
  [ -n "${DOCS_DIR}" ] || DOCS_DIR="${package_dir}/docs"
}

#------------------------------------------------------------------------------
# Lectura del .env SIN ejecutarlo
#------------------------------------------------------------------------------
# Un solo lector para todo el arbol, en lib/env-file.sh. Hasta la tarea 5.4
# habia dos, y no leian igual: el porque y los dos casos que diferian estan
# escritos alli.
env_value() {
  kq_env_value "$1" "$2"
}

#------------------------------------------------------------------------------
# Fase 1 — requisitos. NO SE ESCRIBE NADA.
#------------------------------------------------------------------------------
check_pass() {
  CHECKS_RUN=$((CHECKS_RUN + 1))
  kq_msg check_ok "$1"
}

check_warn() {
  CHECKS_RUN=$((CHECKS_RUN + 1))
  CHECKS_WARNED=$((CHECKS_WARNED + 1))
  kq_msg check_warn "$1"
  kq_msg fix "$2"
}

check_fail() {
  CHECKS_RUN=$((CHECKS_RUN + 1))
  CHECKS_FAILED=$((CHECKS_FAILED + 1))
  kq_msg check_fail "$1"
  kq_msg fix "$2"
}

# Tres respuestas, no dos: 0 ocupado · 1 libre · 2 NO SE HA PODIDO AVERIGUAR.
#
# El tercer caso existe porque la alternativa es peor. Con solo «ocupado» y
# «libre», un servidor sin `ss` ni `netstat` daria «libre» siempre, la fase 1
# pasaria en verde y el fallo saldria en la fase 4 como un "port is already
# allocated" de Docker, con el .env ya escrito. Aqui se avisa de que no se pudo
# comprobar y se sigue: el que decide de verdad es Docker al publicar el puerto.
port_in_use() {
  local port="$1"

  if command -v ss >/dev/null 2>&1; then
    [ -n "$(ss -ltnH "sport = :${port}" 2>/dev/null)" ] && return 0
    return 1
  fi

  # `netstat -ltn` es de net-tools (GNU). Si la orden existe pero no acepta
  # esas banderas, no responde a la pregunta y no se puede tomar su silencio
  # por un "libre".
  if command -v netstat >/dev/null 2>&1 && netstat -ltn >/dev/null 2>&1; then
    netstat -ltn 2>/dev/null | grep -qE "[:.]${port}[[:space:]]" && return 0
    return 1
  fi

  # Si algo acepta una conexion en ese puerto, esta ocupado. Lo contrario no
  # demuestra nada: solo ve lo que escucha en loopback.
  # El descriptor se abre DENTRO de un subshell —los parentesis— y muere con
  # el. No hay nada que cerrar aqui: un `exec 3<&-` en este proceso cerraria
  # un descriptor que nunca se abrio.
  if (exec 3<>"/dev/tcp/127.0.0.1/${port}") 2>/dev/null; then
    return 0
  fi

  return 2
}

# GiB libres. La implementacion vive en lib/fs.sh, compartida con la biblioteca
# de copia: tres invocaciones distintas de `df` eran tres sitios donde corregir
# el mismo fallo.
free_gib() {
  kq_free_gib "$1"
}

check_package_files() {
  if [ -n "${COMPOSE_FILE}" ] && [ -f "${COMPOSE_FILE}" ]; then
    check_pass "$(kq_format c_compose_file "${COMPOSE_FILE}")"
  else
    check_fail "$(kq_format c_compose_file "$(kq_text not_found)")" "$(kq_text f_compose_file)"
  fi

  if [ -n "${PRODUCT_VERSION}" ]; then
    check_pass "$(kq_format c_version "${PRODUCT_VERSION}")"
  else
    check_fail "$(kq_format c_version "$(kq_text unknown_value)")" "$(kq_text f_version)"
  fi

  if [ -f "${ENV_FILE}" ]; then
    check_pass "$(kq_format c_template "${ENV_FILE}")"
  elif [ -n "${ENV_TEMPLATE}" ]; then
    check_fail "$(kq_format c_template_missing "${ENV_FILE}")" \
      "$(kq_format f_template_copy "${ENV_TEMPLATE}" "${ENV_FILE}")"
  else
    check_fail "$(kq_format c_template_missing "${ENV_FILE}")" "$(kq_text f_template)"
  fi
}

check_docker() {
  local version major compose_version

  if ! command -v docker >/dev/null 2>&1; then
    check_fail "$(kq_format c_docker "$(kq_text absent)")" "$(kq_text f_docker_missing)"
    return 0
  fi

  version="$(docker version --format '{{.Server.Version}}' 2>/dev/null || true)"
  if [ -z "${version}" ]; then
    check_fail "$(kq_text c_docker_access)" "$(kq_text f_docker_access)"
    return 0
  fi
  check_pass "$(kq_text c_docker_access)"

  major="${version%%.*}"
  if [ -n "${major}" ] && [ "${major}" -ge "${KQ_MIN_DOCKER_MAJOR}" ] 2>/dev/null; then
    check_pass "$(kq_format c_docker "${version}")"
  else
    check_fail "$(kq_format c_docker "${version}")" \
      "$(kq_format f_docker_old "${version}")"
  fi

  compose_version="$(docker compose version --short 2>/dev/null || true)"
  if [ -n "${compose_version}" ]; then
    check_pass "$(kq_format c_compose "${compose_version}")"
  else
    check_fail "$(kq_format c_compose "$(kq_text absent)")" "$(kq_text f_compose)"
  fi
}

check_tools() {
  if command -v openssl >/dev/null 2>&1; then
    check_pass "$(kq_text c_openssl)"
  else
    check_fail "$(kq_text c_openssl)" "$(kq_text f_openssl)"
  fi

  if command -v curl >/dev/null 2>&1; then
    check_pass "$(kq_text c_curl)"
  else
    check_fail "$(kq_text c_curl)" "$(kq_text f_curl)"
  fi
}

check_resources() {
  local cores ram_mib disk_gib docker_root

  cores="$(getconf _NPROCESSORS_ONLN 2>/dev/null || echo 0)"
  if [ "${cores}" -ge "${KQ_MIN_CPU_CORES}" ] 2>/dev/null; then
    check_pass "$(kq_format c_cpu "${cores}" "${KQ_MIN_CPU_CORES}")"
  else
    check_fail "$(kq_format c_cpu "${cores}" "${KQ_MIN_CPU_CORES}")" \
      "$(kq_format f_cpu "${KQ_MIN_CPU_CORES}")"
  fi

  ram_mib="$(awk '/^MemTotal:/ {printf "%d", $2 / 1024}' /proc/meminfo 2>/dev/null || echo 0)"
  if [ "${ram_mib}" -ge "${KQ_MIN_RAM_MIB}" ] 2>/dev/null; then
    check_pass "$(kq_format c_ram "${ram_mib}" "${KQ_MIN_RAM_MIB}")"
  else
    check_fail "$(kq_format c_ram "${ram_mib}" "${KQ_MIN_RAM_MIB}")" \
      "$(kq_format f_ram "${KQ_MIN_RAM_MIB}")"
  fi

  # El disco que importa es donde Docker guarda volumenes e imagenes, no el
  # directorio desde el que se ejecuta el script: son distintos con frecuencia y
  # el que se llena es el primero.
  docker_root="$(docker info --format '{{.DockerRootDir}}' 2>/dev/null || true)"
  [ -n "${docker_root}" ] || docker_root="/var/lib/docker"

  disk_gib="$(free_gib "${docker_root}")"
  if [ -n "${disk_gib}" ] && [ "${disk_gib}" -ge "${KQ_MIN_DISK_GIB}" ] 2>/dev/null; then
    check_pass "$(kq_format c_disk "${docker_root}" "${disk_gib}" "${KQ_MIN_DISK_GIB}")"
  else
    check_fail "$(kq_format c_disk "${docker_root}" "${disk_gib:-0}" "${KQ_MIN_DISK_GIB}")" \
      "$(kq_format f_disk "${docker_root}")"
  fi
}

check_ports() {
  local port status
  for port in "${CFG_HTTP_PORT}" "${CFG_HTTPS_PORT}"; do
    status=0
    port_in_use "${port}" || status=$?

    case "${status}" in
    0)
      check_fail "$(kq_format c_port_busy "${port}")" \
        "$(kq_format f_port "${port}" "${port}")"
      ;;
    1) check_pass "$(kq_format c_port "${port}")" ;;
    *)
      check_warn "$(kq_format c_port_unknown "${port}")" \
        "$(kq_format f_port_unknown "${port}")"
      ;;
    esac
  done
}

check_writable_paths() {
  local path probe

  for path in "${CFG_BACKUP_PATH}" "$(dirname -- "${ENV_FILE}")"; do
    probe="${path}"
    while [ ! -d "${probe}" ] && [ "${probe}" != "/" ]; do
      probe="$(dirname -- "${probe}")"
    done

    if [ -w "${probe}" ]; then
      check_pass "$(kq_format c_writable "${path}")"
    else
      check_fail "$(kq_format c_not_writable "${path}")" \
        "$(kq_format f_writable "${path}")"
    fi
  done
}

check_app_url() {
  local host

  if [[ "${CFG_APP_URL}" =~ ^https://[A-Za-z0-9._~:/?#@!$\&\'()*+,\;=%-]+$ ]]; then
    check_pass "$(kq_format c_appurl "${CFG_APP_URL}")"
  else
    check_fail "$(kq_format c_appurl "${CFG_APP_URL:-$(kq_text empty_value)}")" "$(kq_text f_appurl)"
    return 0
  fi

  host="${CFG_APP_URL#https://}"
  host="${host%%/*}"
  host="${host%%:*}"

  # Resolver el nombre NO es bloqueante, y es deliberado: el DNS partido es
  # habitual —el servidor se llama de una forma dentro y de otra fuera— y
  # bloquear por esto impediria instalaciones perfectamente correctas. Lo que
  # si hace falta es que resuelva DESDE LOS QUIOSCOS, y eso solo se comprueba
  # desde un quiosco.
  if [[ "${host}" =~ ^[0-9.]+$ ]] || [ "${host}" = "localhost" ]; then
    check_pass "$(kq_format c_appurl_dns "${host}")"
    return 0
  fi

  if ! command -v getent >/dev/null 2>&1; then
    # Sin resolutor no se calla la comprobacion: una comprobacion que
    # desaparece en silencio es peor que una que avisa de que no pudo hacerse.
    check_warn "$(kq_format c_appurl_dns_unknown "${host}")" \
      "$(kq_format w_appurl_no_resolver "${host}")"
    return 0
  fi

  if getent hosts "${host}" >/dev/null 2>&1; then
    check_pass "$(kq_format c_appurl_dns "${host}")"
  else
    check_warn "$(kq_format c_appurl_dns_no "${host}")" \
      "$(kq_format w_appurl_dns "${host}")"
  fi
}

check_tls() {
  local dir="${CFG_TLS_CERT_DIR}"

  if [ "${CFG_TLS_ALLOW_SELF_SIGNED}" = "true" ]; then
    # En PRODUCCION es un fallo, no un aviso, y es el valor por defecto de la
    # plantilla: dejarlo puesto hace que el borde se genere un autofirmado, que
    # los quioscos avisen de sitio no seguro cada manana y que alguien acabe
    # desactivando la comprobacion de certificado en las tablets. A partir de
    # ese dia, el canal por el que viajan los fichajes ya no lo protege nadie.
    if [ "$(env_value "${ENV_FILE}" "APP_ENV")" = "production" ]; then
      check_fail "$(kq_format c_tls_self_signed "${dir}")" "$(kq_text f_tls_self_signed)"
      return 0
    fi

    check_warn "$(kq_format c_tls_self_signed "${dir}")" "$(kq_text w_tls_self_signed)"
    return 0
  fi

  if [ -f "${dir}/tls.crt" ] && [ -f "${dir}/tls.key" ]; then
    check_pass "$(kq_format c_tls "${dir}")"
  else
    check_fail "$(kq_format c_tls_missing "${dir}")" "$(kq_format f_tls "${dir}")"
  fi
}

# Los valores que rellena el cliente.
#
# NO BASTA CON QUE NO ESTEN VACIOS. Un `.env` recien copiado los trae con el
# valor de DESARROLLO —`APP_URL=https://localhost`, la VLAN de ejemplo, la red
# de Docker Compose— y todos son cadenas no vacias. Con la comprobacion anterior
# pasaban la fase 1, y la fase 5 los daba por buenos porque sondea 127.0.0.1
# con `--insecure`: la instalacion se declaraba correcta y ningun quiosco podia
# llegar a ella. Por eso estas cuatro se comparan contra la PLANTILLA: si siguen
# valiendo lo mismo que en `.env.example`, nadie las ha decidido.
#
# Las otras dos (`BACKUP_PATH`, `TLS_CERT_DIR`) SI pueden coincidir con la
# plantilla legitimamente: `/var/backups/fichaje` y `./certs` son destinos
# perfectamente validos en produccion. Ahi solo se exige que no esten vacias.
check_customer_values() {
  local key value plantilla

  for key in APP_URL KIOSK_VLAN_CIDR PORTAL_INTERNAL_CIDR METRICS_ALLOW_CIDR; do
    value="$(env_value "${ENV_FILE}" "${key}")"
    plantilla="$(env_value "${ENV_TEMPLATE}" "${key}")"

    if [ -z "${value}" ]; then
      check_fail "$(kq_format c_env_missing "${key}")" \
        "$(kq_format f_env_key "${key}" "${ENV_FILE}")"
    elif [ -n "${plantilla}" ] && [ "${value}" = "${plantilla}" ]; then
      check_fail "$(kq_format c_env_template "${key}")" \
        "$(kq_format f_env_template "${key}" "${value}" "${ENV_FILE}")"
    else
      check_pass "$(kq_format c_env_key "${key}")"
    fi
  done

  for key in BACKUP_PATH TLS_CERT_DIR; do
    value="$(env_value "${ENV_FILE}" "${key}")"
    if [ -n "${value}" ]; then
      check_pass "$(kq_format c_env_key "${key}")"
    else
      check_fail "$(kq_format c_env_missing "${key}")" \
        "$(kq_format f_env_key "${key}" "${ENV_FILE}")"
    fi
  done

  value="$(env_value "${ENV_FILE}" "APP_ENV")"
  if [ "${value}" = "production" ]; then
    check_pass "APP_ENV=production"
  else
    check_fail "APP_ENV=${value:-$(kq_text empty_value)}" "$(kq_format f_app_env "${ENV_FILE}")"
  fi

  value="$(env_value "${ENV_FILE}" "APP_DEBUG")"
  if [ "${value}" = "false" ]; then
    check_pass "APP_DEBUG=false"
  else
    check_fail "APP_DEBUG=${value:-$(kq_text empty_value)}" "$(kq_format f_app_debug "${ENV_FILE}")"
  fi
}

# Privilegios.
#
# La fase 4 hace `install -d -o 70 -g 70` sobre el directorio de WAL, y asignar
# propietario exige root. Antes, esta comprobacion no existia y el mensaje de
# «no puedo hablar con Docker» ofrecia el grupo `docker` como alternativa
# EQUIVALENTE: quien lo siguiera pasaba la fase 1 entera y moria en la 4, con el
# .env ya escrito. Son dos cosas distintas —hablar con Docker y asignar
# propietarios— y ahora se comprueban por separado.
check_privileges() {
  local wal="${CFG_BACKUP_PATH}/wal"

  if [ "$(id -u)" = "0" ]; then
    check_pass "$(kq_text c_root)"
    return 0
  fi

  # Excepcion legitima: si el directorio de WAL YA existe con el propietario
  # correcto —lo dejo puesto el IT a mano, como dice la guia—, la fase 4 no
  # tiene que crearlo y no hace falta root.
  if [ -d "${wal}" ] && [ "$(stat -c '%u' "${wal}" 2>/dev/null || echo x)" = "70" ]; then
    check_pass "$(kq_format c_root_not_needed "${wal}")"
    return 0
  fi

  check_fail "$(kq_text c_root)" "$(kq_format f_root "${wal}" "${wal}")"
}

phase_requirements() {
  heading "$(kq_text phase_1)"

  check_package_files
  check_docker
  check_tools
  check_resources

  # Lo que sigue necesita los valores del cliente. Si el .env no esta, se
  # comprueba lo que se pueda y se falla por lo que falta, sin inventar valores.
  if [ -f "${ENV_FILE}" ]; then
    CFG_APP_URL="$(env_value "${ENV_FILE}" "APP_URL")"
    CFG_HTTP_PORT="$(env_value "${ENV_FILE}" "HTTP_PORT")"
    CFG_HTTPS_PORT="$(env_value "${ENV_FILE}" "HTTPS_PORT")"
    CFG_BACKUP_PATH="$(env_value "${ENV_FILE}" "BACKUP_PATH")"
    CFG_TLS_CERT_DIR="$(env_value "${ENV_FILE}" "TLS_CERT_DIR")"
    CFG_TLS_ALLOW_SELF_SIGNED="$(env_value "${ENV_FILE}" "TLS_ALLOW_SELF_SIGNED")"

    [ -n "${CFG_HTTP_PORT}" ] || CFG_HTTP_PORT="80"
    [ -n "${CFG_HTTPS_PORT}" ] || CFG_HTTPS_PORT="443"
    [ -n "${CFG_BACKUP_PATH}" ] || CFG_BACKUP_PATH="/var/backups/fichaje"

    # TLS_CERT_DIR suele ser relativo al fichero de compose, que es desde donde
    # lo interpreta Docker.
    case "${CFG_TLS_CERT_DIR}" in
    /*) ;;
    *) CFG_TLS_CERT_DIR="$(dirname -- "${COMPOSE_FILE}")/${CFG_TLS_CERT_DIR#./}" ;;
    esac

    check_customer_values
    check_app_url
    check_tls
    check_ports
    check_writable_paths
    check_privileges
  fi

  say ""
  if [ "${CHECKS_FAILED}" -gt 0 ]; then
    kq_msg req_summary_fail "${CHECKS_FAILED}"
    exit "${KQ_EXIT_REQUIREMENTS}"
  fi

  kq_msg req_summary_ok "${CHECKS_RUN}" "${CHECKS_WARNED}"
}

#------------------------------------------------------------------------------
# Fase 2 — instalacion previa
#------------------------------------------------------------------------------
# Tres senales, y basta con una. La tercera —un .env con APP_KEY ya puesta— es
# la que salva el caso peligroso: alguien que borro los contenedores pero
# conserva los volumenes con el registro horario dentro. Reinstalar rotaria
# APP_KEY y dejaria esos datos ilegibles.
phase_existing_installation() {
  local containers volumes app_key
  declare -a found=()

  heading "$(kq_text phase_2)"

  containers="$(docker ps -a --filter "label=com.docker.compose.project=${KQ_COMPOSE_PROJECT}" --format '{{.Names}}' 2>/dev/null | tr '\n' ' ' || true)"
  if [ -n "${containers// /}" ]; then
    found+=("contenedores del proyecto ${KQ_COMPOSE_PROJECT}: ${containers}")
  fi

  volumes="$(docker volume ls --filter "name=^${KQ_COMPOSE_PROJECT}_" --format '{{.Name}}' 2>/dev/null | tr '\n' ' ' || true)"
  if [ -n "${volumes// /}" ]; then
    found+=("volumenes con datos: ${volumes}")
  fi

  app_key="$(env_value "${ENV_FILE}" "APP_KEY")"
  if [ -n "${app_key}" ]; then
    found+=("${ENV_FILE} ya tiene secretos generados (APP_KEY)")
  fi

  if [ "${#found[@]}" -eq 0 ]; then
    kq_msg check_ok "$(kq_text no_previous_install)"
    return 0
  fi

  err ""
  err "$(kq_text existing_found)"
  local item
  for item in "${found[@]}"; do
    err "$(kq_format existing_item "${item}")"
  done
  err ""
  err "$(kq_text existing_stop)"
  err ""
  err "$(kq_format exit_line "${KQ_EXIT_STATE_CONFLICT}" "$(kq_exit_name "${KQ_EXIT_STATE_CONFLICT}")")"
  exit "${KQ_EXIT_STATE_CONFLICT}"
}

#------------------------------------------------------------------------------
# Fase 3 — secretos
#------------------------------------------------------------------------------

# 32 bytes aleatorios en base64. Es lo que piden APP_KEY (AES-256), la clave
# HMAC del QR (§5.1), la clave de las copias y la privada X25519 del sobre del
# PIN: libsodium genera esa privada exactamente asi, 32 bytes al azar, y deriva
# la publica de ella.
random_base64_32() {
  openssl rand -base64 32
}

# Contrasena para un rol de base de datos: alfanumerica a proposito. Los
# caracteres especiales viajarian por una cadena de conexion y por un fichero
# de entorno, y ahi cada capa los escapa a su manera; el sintoma seria un
# "password authentication failed" que no se parece a su causa. 32 caracteres
# alfanumericos son ~190 bits: de sobra.
random_password() {
  LC_ALL=C tr -dc 'A-Za-z0-9' </dev/urandom | head -c 32
}

random_hex() {
  openssl rand -hex "$1"
}

# Escribe (o sustituye) una variable en el .env sin tocar el resto del fichero:
# el cliente ha escrito ahi sus valores y sus comentarios, y perderlos seria
# perder la unica documentacion de esa instalacion.
#
# El valor se pasa por variable de entorno a awk, NUNCA por la linea de ordenes:
# argv es legible en `ps` para cualquier usuario de la maquina (§7.7).
set_env_value() {
  local file="$1" key="$2" value="$3" temp

  temp="$(mktemp)"
  TEMP_FILES+=("${temp}")
  chmod 0600 "${temp}"

  KQ_VALUE="${value}" awk -v key="${key}" '
    BEGIN { written = 0 }
    {
      if ($0 ~ "^[[:space:]]*(export[[:space:]]+)?" key "=") {
        if (!written) { printf "%s=%s\n", key, ENVIRON["KQ_VALUE"]; written = 1 }
      } else {
        print
      }
    }
    END { if (!written) printf "%s=%s\n", key, ENVIRON["KQ_VALUE"] }
  ' "${file}" >"${temp}"

  cat "${temp}" >"${file}"
  rm -f "${temp}"
}

# Escribe un secreto RECIEN GENERADO, comprobando antes que existe y que tiene
# la longitud que le toca.
#
# POR QUE LA COMPROBACION. `set_env_value ... "$(random_base64_32)"` evalua la
# sustitucion ANTES de llamar a la funcion: si openssl fallara —binario roto,
# /dev/urandom inaccesible, disco lleno—, el argumento seria la cadena vacia y
# el fichero acabaria con `APP_KEY=base64:` sin que nada se quejara. La
# instalacion arrancaria y el fallo aparecería mucho despues, como sesiones que
# no se pueden descifrar.
#
# NO IMPRIME EL VALOR NI SU CONTENIDO: solo su longitud, y solo si falla.
set_generated_secret() {
  local key="$1" value="$2" minimo="${3:-16}"

  if [ -z "${value}" ] || [ "${#value}" -lt "${minimo}" ]; then
    rollback_and_die "$(kq_format f_secret_generation "${key}" "${minimo}" "${#value}")"
  fi

  set_env_value "${ENV_FILE}" "${key}" "${value}"
}

phase_secrets() {
  local backup_env

  heading "$(kq_format phase_3 "${ENV_FILE}")"
  say "$(kq_text secrets_note)"

  # A PARTIR DE AQUI, CUALQUIER FALLO DESHACE.
  #
  # Sin esto, un `cp`, un `chmod` o uno de los catorce `set_env_value` que
  # fallara mataria el script con `set -e` y su codigo crudo: sin vuelta atras,
  # con el .env a medias y con `.env.kronoqr-pre-install` huerfano. Y el
  # reintento saldria 3 —«ya hay secretos generados»— sin un solo contenedor
  # levantado: un callejon sin salida en el servidor de un cliente.
  #
  # Se arma AQUI y no antes a proposito: durante la fase 1 no hay nada que
  # deshacer y un fallo tiene que seguir saliendo 2 («nada escrito»). `set -E`
  # es lo que hace que el manejador se herede dentro de las funciones.
  trap 'rollback_and_die "$(kq_format f_unexpected "${LINENO}")"' ERR

  # Copia del .env del cliente ANTES de tocarlo. Es lo que restaura la vuelta
  # atras: sus valores y sus comentarios no son nuestros para perderlos.
  #
  # 0600 de inmediato: `cp -p` hereda los permisos del original, que suele ser
  # 0644 recien copiado de la plantilla, y este fichero puede llevar ya el
  # MAIL_PASSWORD que el cliente rellenó.
  backup_env="${ENV_FILE}.kronoqr-pre-install"
  cp -p "${ENV_FILE}" "${backup_env}"
  chmod 0600 "${backup_env}"
  register_undo "${ENV_FILE} devuelto a como estaba antes de instalar" \
    "mv -f '${backup_env}' '${ENV_FILE}'"

  chmod 0600 "${ENV_FILE}"

  # 44 caracteres para 32 bytes en base64; 32 para las contrasenas; 2 para el
  # identificador de clave. Los minimos son holgados a proposito: lo que se
  # detecta es un generador que devuelve NADA, no uno que devuelve poco.
  set_generated_secret "APP_KEY" "base64:$(random_base64_32)" 40
  set_generated_secret "QR_SIGNING_KEY_CURRENT" "$(random_base64_32)" 40
  set_generated_secret "QR_SIGNING_KEY_CURRENT_ID" "$(random_hex 1)" 2

  set_generated_secret "DB_PASSWORD" "$(random_password)" 32
  set_generated_secret "DB_MIGRATION_PASSWORD" "$(random_password)" 32
  # El TERCER rol, fichaje_maintenance, NO recibe credencial aqui y no es un
  # olvido: ADR-027 exige que no viva en el .env de la aplicacion, porque es el
  # unico que puede soltar una particion vencida de audit_log. Nace sin
  # contrasena —existe y no se puede usar por TCP— y se le asigna una en el
  # momento de la purga anual, con el procedimiento de
  # docs/cliente/operacion.md, «Custodia de secretos».
  #
  # El volcado de la copia lo hace el rol de migracion (lib/backup-common.sh),
  # asi que su credencial es la misma. Escribirla aparte con otro valor dejaria
  # la copia diaria fallando desde el primer dia.
  set_generated_secret "BACKUP_DB_PASSWORD" "$(env_value "${ENV_FILE}" "DB_MIGRATION_PASSWORD")" 32

  set_generated_secret "REVERB_APP_ID" "$(random_hex 8)" 16
  set_generated_secret "REVERB_APP_KEY" "$(random_hex 16)" 32
  set_generated_secret "REVERB_APP_SECRET" "$(random_base64_32)" 40

  set_generated_secret "BACKUP_ENCRYPTION_KEY" "$(random_base64_32)" 40
  set_generated_secret "IDENTITY_PIN_SEALING_SECRET_KEY" "$(random_base64_32)" 40
  set_generated_secret "GRAFANA_ADMIN_PASSWORD" "$(random_password)" 32

  # La etiqueta de imagen la fija el fichero VERSION del paquete, no una
  # variable que alguien pueda dejar en `latest`. Nada de `latest` en
  # produccion (doc 02 §11.6.1). No es un secreto: no pasa por la validacion.
  set_env_value "${ENV_FILE}" "IMAGE_TAG" "${PRODUCT_VERSION}"

  chmod 0600 "${ENV_FILE}"

  kq_msg secrets_written "${ENV_FILE}"
  say ""
  say "$(kq_text secrets_custody)"
}

#------------------------------------------------------------------------------
# Fase 4 — arranque y esquema
#------------------------------------------------------------------------------
compose() {
  docker compose --env-file "${ENV_FILE}" -f "${COMPOSE_FILE}" "$@"
}

# Espera a que un servicio declare estar sano. POR CONDICION: se pregunta por el
# estado cada KQ_POLL_SECONDS y se abandona al llegar al techo. Un `sleep 30`
# seria a la vez demasiado en una maquina rapida y poco en una lenta, y el
# fallo aparecería como "las migraciones no encuentran la base de datos".
wait_for_healthy() {
  local service="$1" timeout="$2" waited=0 state

  kq_msg waiting "${service}" "${timeout}"

  while [ "${waited}" -lt "${timeout}" ]; do
    state="$(compose ps --format '{{.Service}} {{.Health}} {{.State}}' 2>/dev/null |
      awk -v s="${service}" '$1 == s { print $2 " " $3 }' || true)"

    case "${state}" in
    "healthy "*) kq_msg waiting_ok "${service}" && return 0 ;;
    *" running") [ -z "${state%% *}" ] && kq_msg waiting_ok "${service}" && return 0 ;;
    esac

    sleep "${KQ_POLL_SECONDS}"
    waited=$((waited + KQ_POLL_SECONDS))
  done

  return 1
}

# Crea el arbol de copias con los propietarios que cada pieza necesita.
#
# POR QUE LAS ACCIONES DE DESHACER SON `rm -rf` Y NO `rmdir`. `${BACKUP_PATH}/wal`
# es un bind mount y PostgreSQL empieza a archivar segmentos desde el primer
# segundo: para cuando falla una migracion ya hay ficheros dentro, `down -v` no
# los toca —son del anfitrion, no del volumen— y un `rmdir` sobre un directorio
# no vacio falla. El efecto medido era que TODO fallo de la fase 4 acababa en
# codigo 5 («vuelta atras incompleta, intervencion manual») en vez de 4,
# contradiciendo al propio mensaje que decia «el instalador la retira al
# deshacer».
#
# `rm -rf` es seguro AQUI y solo aqui: la accion se registra dentro del
# `if [ ! -d ... ]`, asi que solo se borra un directorio que ha creado ESTA
# ejecucion. Un destino de copias que ya existia no se registra y no se toca.
ensure_backup_directories() {
  local wal="${CFG_BACKUP_PATH}/wal"
  local metrics="${CFG_BACKUP_PATH}/metrics"
  local created=0

  # El destino de copias lo escribe la aplicacion, que corre como uid 1000.
  if [ ! -d "${CFG_BACKUP_PATH}" ]; then
    if ! install -d -o 1000 -g 1000 -m 0750 "${CFG_BACKUP_PATH}" 2>/dev/null; then
      # Sin privilegios para asignar el propietario. Se crea igualmente y SE
      # AVISA: antes se caia a esta rama en silencio y la copia diaria fallaba
      # semanas despues, escribiendo en un directorio que no es suyo.
      install -d -m 0750 "${CFG_BACKUP_PATH}" ||
        rollback_and_die "$(kq_format f_writable "${CFG_BACKUP_PATH}")"
      kq_msg check_warn "$(kq_format c_owner_fallback "${CFG_BACKUP_PATH}" "1000:1000")"
      kq_msg fix "$(kq_format f_owner_fallback "1000" "1000" "${CFG_BACKUP_PATH}")"
    fi
    created=1
    register_undo "$(kq_format undo_backup_dir "${CFG_BACKUP_PATH}")" "rm -rf '${CFG_BACKUP_PATH}'"
  fi

  # El WAL lo archiva PostgreSQL, que en la imagen Alpine corre como uid 70. Si
  # no puede escribir aqui, el archivado falla, el WAL se acumula en el volumen
  # de datos hasta llenar el disco y PostgreSQL acaba parandose. Nadie mas va a
  # crear este directorio: la imagen arranca como `postgres`, no como root.
  # Aqui NO hay rama de respaldo: sin el propietario correcto el archivado no
  # funciona, y fingir que si es peor que parar.
  if [ ! -d "${wal}" ]; then
    install -d -o 70 -g 70 -m 0750 "${wal}" 2>/dev/null ||
      rollback_and_die "$(kq_format f_wal_dir "${wal}" "${wal}")"
    register_undo "$(kq_format undo_wal_dir "${wal}")" "rm -rf '${wal}'"
    created=1
  fi

  # Lo lee node-exporter para publicar el resultado de la copia como metrica.
  #
  # La accion de deshacer se registra SOLO si la creacion ha salido bien. Antes
  # se registraba siempre, por el `|| true`, y un directorio que no llego a
  # existir hacia fallar su propio `rmdir`: convertia una vuelta atras limpia
  # (4) en una incompleta (5), que es la que exige a alguien mirar el servidor.
  if [ ! -d "${metrics}" ]; then
    if install -d -o 1000 -g 1000 -m 0750 "${metrics}" 2>/dev/null ||
      install -d -m 0750 "${metrics}" 2>/dev/null; then
      register_undo "$(kq_format undo_metrics_dir "${metrics}")" "rm -rf '${metrics}'"
    else
      # No es bloqueante: sin el, se pierde la METRICA del resultado de la
      # copia, no la copia. Pero se dice, porque si nadie lo arregla nadie se
      # entera de que la copia de anoche fallo.
      kq_msg check_warn "$(kq_format c_metrics_dir "${metrics}")"
      kq_msg fix "$(kq_format f_metrics_dir "${metrics}")"
    fi
  fi

  [ "${created}" -eq 1 ] && kq_msg wal_dir "${wal}"
  return 0
}

# Descarga UNA A UNA solo las imagenes que falten.
#
# No es `docker compose pull` a secas, y la diferencia importa: esa orden
# intenta descargar TODAS, incluidas las que ya estan en el disco. En una
# instalacion sin salida a internet —caso soportado, §11.6.2: la salida a
# internet es OPCIONAL— el IT carga antes las imagenes de KronoQR con
# `docker load`, y un `pull` global fallaria por ellas aunque estuvieran
# perfectamente presentes. Aqui, lo que esta no se toca.
pull_images_if_needed() {
  local image registry announced=0
  declare -a missing=()

  while IFS= read -r image; do
    [ -n "${image}" ] || continue
    docker image inspect "${image}" >/dev/null 2>&1 || missing+=("${image}")
  done < <(compose config --images 2>/dev/null || true)

  [ "${#missing[@]}" -eq 0 ] && return 0

  registry="$(env_value "${ENV_FILE}" "IMAGE_REGISTRY")"

  for image in "${missing[@]}"; do
    if [ "${announced}" -eq 0 ]; then
      kq_msg images_pull "${PRODUCT_VERSION}"
      announced=1
    fi

    if ! docker pull --quiet "${image}" >/dev/null; then
      rollback_and_die "$(kq_format f_images \
        "${PRODUCT_VERSION}" "${registry}" "${registry}" "${PRODUCT_VERSION}")"
    fi
  done
}

phase_bootstrap() {
  heading "$(kq_text phase_4)"

  ensure_backup_directories
  pull_images_if_needed

  say "$(kq_text services_up)"
  register_undo "$(kq_text undo_services)" \
    "docker compose --env-file '${ENV_FILE}' -f '${COMPOSE_FILE}' down -v --remove-orphans"

  if ! compose up -d; then
    rollback_and_die "$(kq_format f_services_up "${COMPOSE_FILE}")"
  fi

  local service
  for service in postgres redis; do
    if ! wait_for_healthy "${service}" "${KQ_WAIT_DEPENDENCIES}"; then
      rollback_and_die "$(kq_format f_waiting \
        "${service}" "${KQ_WAIT_DEPENDENCIES}" "${COMPOSE_FILE}" "${service}")"
    fi
  done

  if ! wait_for_healthy app "${KQ_WAIT_APPLICATION}"; then
    rollback_and_die "$(kq_format f_waiting \
      "app" "${KQ_WAIT_APPLICATION}" "${COMPOSE_FILE}" "app")"
  fi

  say ""
  say "$(kq_text migrating)"
  # Con el rol de MIGRACION, no con el de la aplicacion: el de la aplicacion no
  # tiene DDL y no puede tener UPDATE ni DELETE sobre audit_log (regla dura 6).
  if ! compose exec -T app php artisan migrate --database=pgsql_migrator --force; then
    rollback_and_die "$(kq_format f_migrating "${COMPOSE_FILE}")"
  fi

  say "$(kq_text seed_note)"
}

#------------------------------------------------------------------------------
# Fase 5 — verificacion
#------------------------------------------------------------------------------
# Se comprueba contra el propio servidor, por loopback. `--insecure` aqui NO es
# relajar la seguridad del producto: es evitar que la verificacion dependa de
# que el nombre del certificado resuelva DESDE ESTE SERVIDOR, cosa que el DNS
# partido rompe a menudo. Lo que se comprueba es que la aplicacion responde;
# que el certificado es el correcto se comprueba desde un quiosco, y la guia lo
# dice con esas palabras.
probe() {
  local path="$1"

  curl --fail --silent --show-error --insecure --max-time 15 \
    "https://127.0.0.1:${CFG_HTTPS_PORT}${path}" >/dev/null 2>&1
}

# PUNTO DE ENGANCHE PARA `doctor` (tarea 5.9).
#
# La ficha de esta tarea pedia verificar con `php artisan product:doctor`. Ese
# comando NO EXISTE todavia: llega en la 5.9. Escribir aqui media comprobacion
# de salud propia habria producido dos diagnosticos distintos en el mismo
# producto, y el dia que difirieran nadie sabria cual creer.
#
# Mientras tanto se verifica con lo que SI existe y ya esta bajo contrato: las
# dos sondas del §10.5 y el estado de la licencia. Cuando la 5.9 aterrice, la
# fase 5 gana una linea —`compose exec -T app php artisan product:doctor`— y su
# codigo de salida se traduce al 6 de la tabla comun. No hay nada mas que
# cambiar aqui.

phase_verify() {
  local path

  heading "$(kq_text phase_5)"

  for path in /api/v1/health /api/v1/ready; do
    if probe "${path}"; then
      kq_msg check_ok "$(kq_format verify_probe "${path}")"
    else
      err ""
      err "ERROR: $(kq_format f_verify_probe \
        "${path}" "sin respuesta correcta" "${COMPOSE_FILE}" "${CFG_APP_URL}")"
      err "$(kq_format exit_line "${KQ_EXIT_VERIFY_FAILED}" "$(kq_exit_name "${KQ_EXIT_VERIFY_FAILED}")")"
      exit "${KQ_EXIT_VERIFY_FAILED}"
    fi
  done

  # `license:show` termina con 0 tanto con licencia como sin ella: sin licencia
  # el producto funciona y lo dice. Un codigo distinto de 0 significa que la
  # consulta en si ha fallado, no que falte la clave. Y aunque falle, NO se
  # deshace nada ni se degrada el registro (regla dura 15, ADR-019).
  if compose exec -T app php artisan license:show >/dev/null 2>&1; then
    kq_msg check_ok "$(kq_text verify_license)"
  else
    err ""
    err "$(kq_text prefix_warning): $(kq_format f_verify_license "${COMPOSE_FILE}")"
  fi
}

#------------------------------------------------------------------------------
# Informe final
#------------------------------------------------------------------------------
final_report() {
  heading "$(kq_format done_title "${PRODUCT_VERSION}")"
  say ""
  kq_msg done_admin "${CFG_APP_URL}"
  kq_msg done_kiosk "${CFG_APP_URL}"
  kq_msg done_portal "${CFG_APP_URL}"
  say ""
  say "$(kq_text done_next)"
  say ""
  kq_msg done_docs "${DOCS_DIR}"
  say ""
  say "$(kq_text done_custody)"
}

#------------------------------------------------------------------------------
main() {
  parse_arguments "$@"
  kq_msg_init "${OPT_LANG}"
  resolve_paths_quietly

  phase_requirements

  if [ "${OPT_CHECK_ONLY}" -eq 1 ]; then
    say ""
    say "$(kq_text check_only_done)"
    exit "${KQ_EXIT_OK}"
  fi

  phase_existing_installation

  # phase_secrets arma el manejador de vuelta atras; desde ahi hasta aqui,
  # cualquier fallo deshace lo hecho y sale con 4 o con 5.
  phase_secrets
  phase_bootstrap
  phase_verify

  # Desarmado. A partir de este punto la instalacion EXISTE y sus datos
  # tambien: deshacerla porque falle al imprimir el informe final seria
  # exactamente el fallo que este manejador existe para evitar.
  trap - ERR

  # La copia previa del .env del cliente ya no hace falta y puede llevar su
  # MAIL_PASSWORD. Se retira de la pila de deshacer ANTES de borrarla: si no,
  # un fallo posterior intentaria restaurar un fichero que ya no esta.
  discard_undo "$(kq_format undo_env "${ENV_FILE}")"
  rm -f "${ENV_FILE}.kronoqr-pre-install"

  final_report
}

# La guarda permite CARGAR este fichero sin ejecutarlo, que es lo que hace la
# prueba de integracion para ejercitar `env_value` y `set_env_value` aislados.
# Sin ella, cualquier `source install.sh` instalaria.
if [ "${BASH_SOURCE[0]}" = "$0" ]; then
  main "$@"
fi
