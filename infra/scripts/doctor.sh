#!/usr/bin/env bash
#
# KronoQR — diagnostico de una instalacion existente, sin entrar al contenedor
# (RF-PD-13, doc 02 §11.6.1).
#
# PROPOSITO. Que cualquier persona del equipo de soporte, o el IT del hotel a
# las seis y media de la manana, pueda diagnosticar un incidente con un solo
# comando: `./doctor.sh`, sin saber Laravel ni acordarse de la sintaxis de
# `docker compose exec`.
#
# LO QUE HACE, EN ORDEN:
#   1  Comprueba que Docker responde. Sin el no se puede mirar nada mas.
#   2  Localiza la instalacion (por las etiquetas del contenedor `app`, igual
#      que update.sh; `--current RUTA` la fija a mano).
#   3  Si el contenedor `app` esta en marcha y sano, DELEGA en el diagnostico
#      real del producto —`php artisan product:doctor`— y muestra su salida
#      TAL CUAL: dos diagnosticos distintos del mismo sistema es la forma
#      segura de que un dia digan cosas distintas.
#   4  Si `app` NO esta en marcha, es cuando este script gana su sitio: hace
#      desde fuera lo que se puede sin el (Docker, estado de cada servicio,
#      `.env`, espacio en disco, certificado, puertos) y dice como arrancarla.
#
# CODIGOS DE SALIDA (tabla UNICA de lib/exit-codes.sh y docs/cliente/operacion.md)
#   0  Correcto. Sin fallos. Puede haber avisos: se muestran y no bloquean.
#   1  Uso incorrecto. Un argumento que no existe, o falta un valor.
#   2  Docker no responde, o la version de Compose no es la soportada. No se
#      ha podido comprobar nada mas.
#   3  No hay instalacion que diagnosticar en este servidor. Si es un servidor
#      nuevo, lo que hace falta es install.sh.
#   4  NO LO USA ESTE SCRIPT: no escribe ni deshace nada.
#   5  NO LO USA ESTE SCRIPT: no escribe ni deshace nada.
#   6  El diagnostico ha encontrado al menos un fallo: bien `product:doctor`
#      (con la aplicacion en marcha), bien una de las comprobaciones externas
#      (con la aplicacion parada). El mensaje dice que hacer.
#
# USO
#   ./doctor.sh                    localiza la instalacion y diagnostica
#   ./doctor.sh --current RUTA     usa esta instalacion en vez de localizarla
#   ./doctor.sh --lang es|en       idioma del informe. Por defecto, el del sistema
#   ./doctor.sh --help             esta ayuda
#
# NINGUN SECRETO EN LA SALIDA. Del `.env` solo se leen rutas, puertos y
# nombres de fichero (regla dura 21, doc 02 §3.5): nunca el valor de una
# clave. `product:doctor`, al que este script delega cuando puede, cumple la
# misma regla por su cuenta.

set -Eeuo pipefail
IFS=$'\n\t'

SCRIPT_DIR="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"
readonly SCRIPT_DIR

# shellcheck source-path=SCRIPTDIR
# shellcheck source=lib/exit-codes.sh disable=SC1091
. "${SCRIPT_DIR}/lib/exit-codes.sh"
# shellcheck source=lib/messages.sh disable=SC1091
. "${SCRIPT_DIR}/lib/messages.sh"
# shellcheck source=lib/messages-doctor.sh disable=SC1091
. "${SCRIPT_DIR}/lib/messages-doctor.sh"
# shellcheck source=lib/checks.sh disable=SC1091
. "${SCRIPT_DIR}/lib/checks.sh"
# shellcheck source=lib/env-file.sh disable=SC1091
. "${SCRIPT_DIR}/lib/env-file.sh"
# shellcheck source=lib/fs.sh disable=SC1091
. "${SCRIPT_DIR}/lib/fs.sh"

#------------------------------------------------------------------------------
# Umbrales.
#------------------------------------------------------------------------------
# El disco NO usa los 40 GiB absolutos de install.sh a proposito (revision de
# codigo, segunda vuelta): esos 40 GiB son un REQUISITO DE ENTRADA —hay
# espacio de sobra para EMPEZAR una instalacion nueva—, no un criterio de
# diagnostico continuo. Una instalacion con anos de fichajes y de copias
# puede estar perfectamente sana con menos de 40 GiB libres, y una maquina
# enorme puede estar a un dia de llenarse aunque el numero absoluto siga
# pareciendo grande. El criterio de aqui es el MISMO que el de la sonda
# `DiskProbe` de `product:doctor` (decision 7 del brief de la tarea 5.9, doc
# 02 §11.6.1): proporcion de espacio libre, con un suelo absoluto para que un
# disco minusculo no pase por "10% libre" cuando ese 10% son unos pocos MiB.
readonly KQ_DISK_WARN_PCT=10
readonly KQ_DISK_FAIL_PCT=5
readonly KQ_DISK_FAIL_FLOOR_BYTES=1073741824

# Dias de margen antes de la caducidad de un certificado a partir de los
# cuales este script empieza a avisar. Mismo umbral que la comprobacion
# `tls.certificate` de `product:doctor` (decision 7 del brief de la tarea
# 5.9): un aviso que cambia de numero segun quien lo mire deja de ser un aviso.
readonly KQ_CERT_WARN_DAYS=30

readonly KQ_COMPOSE_PROJECT="kronoqr"

#------------------------------------------------------------------------------
# Estado
#------------------------------------------------------------------------------
OPT_CURRENT=""
OPT_LANG=""

CURRENT_DIR=""
CURRENT_COMPOSE=""
CURRENT_ENV=""
APP_UP=0

CHECKS_RUN=0
CHECKS_FAILED=0
CHECKS_WARNED=0

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

env_value() {
  kq_env_value "$1" "$2"
}

#------------------------------------------------------------------------------
# Argumentos
#------------------------------------------------------------------------------
parse_arguments() {
  while [ "$#" -gt 0 ]; do
    case "$1" in
    --current)
      [ "$#" -ge 2 ] || die "${KQ_EXIT_USAGE}" "$(kq_format missing_value "--current")"
      OPT_CURRENT="$2"
      shift
      ;;
    --current=*)
      OPT_CURRENT="${1#--current=}"
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
      kq_msg d_usage
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
# Paso 2 — localizar la instalacion. MISMA tecnica que update.sh
# (check_installation): se pregunta a Docker por las etiquetas del contenedor
# PERSISTENTE del servicio `app`, que llevan la ruta del fichero de compose con
# el que se creo. `--current` existe para cuando esos contenedores ya no estan.
#------------------------------------------------------------------------------
locate_installation() {
  local configs config candidate missing=""

  if [ -n "${OPT_CURRENT}" ]; then
    CURRENT_DIR="$(cd -- "${OPT_CURRENT}" 2>/dev/null && pwd || printf '%s' "${OPT_CURRENT}")"
    for candidate in "${CURRENT_DIR}/docker-compose.yml" "${CURRENT_DIR}/compose.prod.yaml"; do
      [ -f "${candidate}" ] && CURRENT_COMPOSE="${candidate}" && break
    done
  else
    configs="$(docker ps -a --filter "label=com.docker.compose.project=${KQ_COMPOSE_PROJECT}" \
      --filter "label=com.docker.compose.service=app" \
      --filter "label=com.docker.compose.oneoff=False" \
      --format '{{.Label "com.docker.compose.project.config_files"}}' |
      awk -F, 'NF { print $1 }' | sort -u || true)"

    case "$(printf '%s\n' "${configs}" | grep -c . || true)" in
    0)
      die "${KQ_EXIT_STATE_CONFLICT}" "$(kq_format d_f_installation_missing "${KQ_COMPOSE_PROJECT}")"
      ;;
    1)
      config="$(printf '%s\n' "${configs}" | sed -n '1p')"
      CURRENT_COMPOSE="${config}"
      CURRENT_DIR="$(dirname -- "${config}")"
      ;;
    *)
      die "${KQ_EXIT_STATE_CONFLICT}" \
        "$(kq_format d_f_installation_ambiguous "${KQ_COMPOSE_PROJECT}" "$(printf '%s' "${configs}" | tr '\n' ' ')")"
      ;;
    esac
  fi

  CURRENT_ENV="${CURRENT_DIR}/.env"
  [ -n "${CURRENT_COMPOSE}" ] && [ -f "${CURRENT_COMPOSE}" ] || missing="docker-compose.yml"
  [ -f "${CURRENT_ENV}" ] || missing=".env"
  if [ -n "${missing}" ]; then
    die "${KQ_EXIT_STATE_CONFLICT}" "$(kq_format d_f_installation_files "${CURRENT_DIR}" "${missing}")"
  fi

  check_pass "$(kq_format d_c_installation "${CURRENT_DIR}")"
}

compose_current() {
  docker compose --env-file "${CURRENT_ENV}" -f "${CURRENT_COMPOSE}" "$@"
}

#------------------------------------------------------------------------------
# Paso 3 — esta `app` en marcha y sana? Sin esperar: doctor.sh diagnostica el
# instante, no espera a que algo mejore. El patron de comparacion es el mismo
# de `wait_for_healthy` en install.sh: un servicio SIN sonda de salud sale
# como "running" a secas (awk colapsa los espacios), y eso tambien cuenta.
#------------------------------------------------------------------------------
check_app_running() {
  local state

  state="$(compose_current ps --format '{{.Service}} {{.Health}} {{.State}}' 2>/dev/null |
    awk '$1 == "app" { $1 = ""; print substr($0, 2) }')"

  case "${state}" in
  "healthy "* | "running" | "running ") APP_UP=1 ;;
  *) APP_UP=0 ;;
  esac
}

#------------------------------------------------------------------------------
# Paso 4a — `app` en marcha: delegar en el diagnostico real del producto.
#------------------------------------------------------------------------------
run_delegated_doctor() {
  local output status=0

  say "$(kq_text d_delegating)"
  say ""

  # Comprobacion de PRESENCIA, no de texto: `list --raw` enumera los comandos
  # tal cual los conoce la aplicacion, uno por linea. Preguntarle a Symfony
  # Console si CONOCE el comando no depende de en que idioma ni con que
  # redaccion diria "no existe" si se le pidiera ejecutarlo: el mensaje de
  # error es del framework, y cambia entre versiones e idiomas sin avisar.
  # Sin tuberia: con `pipefail`, `grep -q` cierra el tubo en cuanto encuentra la
  # linea, PHP recibe SIGPIPE y la tuberia falla AUNQUE el comando exista (paso
  # en la 8b de la 5.9: U1 en verde y U3 «sin product:doctor» con la misma imagen).
  available_commands="$(compose_current exec -T app php artisan list --raw 2>/dev/null || true)"
  if ! printf '%s
' "${available_commands}" | grep -q '^product:doctor'; then
    die "${KQ_EXIT_VERIFY_FAILED}" "$(kq_text d_f_doctor_missing_command)"
  fi

  output="$(compose_current exec -T app php artisan product:doctor --lang="${KQ_LANG}" 2>&1)" || status=$?
  printf '%s\n' "${output}"
  say ""

  case "${status}" in
  0)
    check_pass "$(kq_text d_doctor_ok)"
    exit "${KQ_EXIT_OK}"
    ;;
  1)
    check_warn "$(kq_text d_doctor_warn)" "$(kq_text d_doctor_warn_fix)"
    exit "${KQ_EXIT_OK}"
    ;;
  2)
    die "${KQ_EXIT_VERIFY_FAILED}" "$(kq_text d_f_doctor_failed)"
    ;;
  *)
    die "${KQ_EXIT_VERIFY_FAILED}" "$(kq_format d_f_doctor_unexpected "${status}" "${CURRENT_COMPOSE}")"
    ;;
  esac
}

#------------------------------------------------------------------------------
# Paso 4b — `app` parada: lo que se puede saber desde fuera. Cada comprobacion
# usa check_pass/check_warn/check_fail (lib/checks.sh), asi que cuenta para el
# resumen final igual que en install.sh.
#------------------------------------------------------------------------------
run_external_checks() {
  say "$(kq_text d_app_down_diagnosing)"
  say ""

  check_fail "$(kq_text d_c_app_down)" "$(kq_format d_f_app_down "${CURRENT_COMPOSE}" "${CURRENT_COMPOSE}")"

  check_services_state
  check_env_permissions
  check_disk_space
  check_certificates
  check_listening_ports

  say ""
  if [ "${CHECKS_FAILED}" -gt 0 ]; then
    err "$(kq_format d_summary_fail "${CHECKS_RUN}" "${CHECKS_FAILED}" "${CHECKS_WARNED}")"
    say ""
    say "$(kq_format d_how_to_start "${CURRENT_COMPOSE}")"
    err "$(kq_format exit_line "${KQ_EXIT_VERIFY_FAILED}" "$(kq_exit_name "${KQ_EXIT_VERIFY_FAILED}")")"
    exit "${KQ_EXIT_VERIFY_FAILED}"
  fi

  say "$(kq_format d_summary_ok "${CHECKS_RUN}" "${CHECKS_WARNED}")"
  say ""
  say "$(kq_format d_how_to_start "${CURRENT_COMPOSE}")"
  exit "${KQ_EXIT_OK}"
}

# Estado de CADA servicio del compose, no solo de `app`: un quiosco encola
# igual si lo que falta es `app`, `nginx` o `redis`, y el mensaje tiene que
# decir cual.
check_services_state() {
  local service state

  # El tercer campo (salud) no se usa: `state` ya distingue "running" de
  # cualquier otra cosa, y un servicio sin sonda de salud no rellena ese
  # campo. `_` descarta lo que no hace falta sin dejar una variable sin usar.
  while IFS=' ' read -r service state _; do
    [ -n "${service}" ] || continue
    if [ "${state}" = "running" ]; then
      check_pass "$(kq_format d_c_service_state "${service}" "${state}")"
    else
      check_warn "$(kq_format d_c_service_state "${service}" "${state:-$(kq_text unknown_value)}")" \
        "$(kq_format d_f_service_down "${CURRENT_COMPOSE}" "${service}" "${CURRENT_COMPOSE}" "${service}")"
    fi
  done < <(compose_current ps -a --format '{{.Service}} {{.State}} {{.Health}}' 2>/dev/null)
}

# El `.env` guarda secretos (regla dura 21): SOLO se comprueba que existe y
# que sus permisos son 0600. Nunca se lee ni se imprime una clave.
check_env_permissions() {
  local mode

  mode="$(stat -c '%a' "${CURRENT_ENV}" 2>/dev/null)" || true
  mode="${mode: -3}"

  if [ -z "${mode}" ]; then
    check_warn "$(kq_format d_c_env_present "${CURRENT_ENV}")" "$(kq_format d_w_env_mode_unknown "${CURRENT_ENV}")"
  elif [ "${mode}" = "600" ]; then
    check_pass "$(kq_format d_c_env_mode "${CURRENT_ENV}")"
  else
    check_warn "$(kq_format d_c_env_present "${CURRENT_ENV}")" \
      "$(kq_format d_f_env_mode "${CURRENT_ENV}" "${mode}" "${CURRENT_ENV}")"
  fi
}

# Proporcion de espacio libre, no GiB absolutos (ver el comentario de los
# umbrales, arriba): el disco de la instalacion y el destino de copias, que
# casi nunca son el mismo sistema de ficheros.
check_disk_space() {
  check_disk_threshold "${CURRENT_DIR}"

  local backup_path
  backup_path="$(env_value "${CURRENT_ENV}" "BACKUP_PATH")"
  [ -n "${backup_path}" ] || backup_path="/var/backups/fichaje"

  check_disk_threshold "${backup_path}"
}

# Mismo criterio que `DiskProbe` de `product:doctor`: aviso por debajo de
# KQ_DISK_WARN_PCT libre, fallo por debajo de KQ_DISK_FAIL_PCT O por debajo
# del suelo absoluto KQ_DISK_FAIL_FLOOR_BYTES (1 GiB) — lo que salte antes.
# El suelo existe para que un disco diminuto no pase por "con margen" solo
# porque el porcentaje todavia no ha bajado del umbral.
check_disk_threshold() {
  local path="$1" free_bytes total_bytes pct gib

  free_bytes="$(kq_free_bytes "${path}")"
  total_bytes="$(kq_total_bytes "${path}")"

  if [ -z "${free_bytes}" ] || [ -z "${total_bytes}" ] || [ "${total_bytes}" -le 0 ] 2>/dev/null; then
    check_warn "$(kq_format d_c_disk_unknown "${path}")" "$(kq_format d_w_disk_unknown "${path}" "${path}")"
    return 0
  fi

  pct=$((free_bytes * 100 / total_bytes))
  gib="$(kq_free_gib "${path}")"

  if [ "${pct}" -lt "${KQ_DISK_FAIL_PCT}" ] || [ "${free_bytes}" -lt "${KQ_DISK_FAIL_FLOOR_BYTES}" ]; then
    check_fail "$(kq_format d_c_disk "${path}" "${pct}" "${gib:-0}")" "$(kq_format d_f_disk_critical "${path}")"
  elif [ "${pct}" -lt "${KQ_DISK_WARN_PCT}" ]; then
    check_warn "$(kq_format d_c_disk "${path}" "${pct}" "${gib:-0}")" "$(kq_format d_f_disk_low "${path}")"
  else
    check_pass "$(kq_format d_c_disk "${path}" "${pct}" "${gib:-0}")"
  fi
}

# Presencia de tls.crt y tls.key, y caducidad de tls.crt. TLS_CERT_DIR suele
# ser relativo al fichero de compose, que es desde donde lo interpreta Docker
# (mismo criterio que install.sh).
check_certificates() {
  local dir file

  dir="$(env_value "${CURRENT_ENV}" "TLS_CERT_DIR")"
  [ -n "${dir}" ] || dir="./certs"
  case "${dir}" in
  /*) ;;
  *) dir="$(dirname -- "${CURRENT_COMPOSE}")/${dir#./}" ;;
  esac

  for file in tls.crt tls.key; do
    if [ -f "${dir}/${file}" ]; then
      check_pass "$(kq_format d_c_cert_present "${dir}/${file}")"
    else
      check_fail "$(kq_format d_c_cert_missing "${dir}/${file}")" "$(kq_format d_f_cert_missing "${dir}/${file}")"
    fi
  done

  [ -f "${dir}/tls.crt" ] && check_certificate_expiry "${dir}/tls.crt"
  return 0
}

check_certificate_expiry() {
  local file="$1" enddate epoch now days

  if ! command -v openssl >/dev/null 2>&1; then
    check_warn "$(kq_format d_c_cert_expiry_unknown "${file}")" "$(kq_format d_w_cert_unknown "${file}")"
    return 0
  fi

  enddate="$(openssl x509 -enddate -noout -in "${file}" 2>/dev/null | sed 's/^notAfter=//')"
  epoch=""
  [ -z "${enddate}" ] || epoch="$(date -d "${enddate}" +%s 2>/dev/null || true)"

  if [ -z "${enddate}" ] || [ -z "${epoch}" ]; then
    check_warn "$(kq_format d_c_cert_expiry_unknown "${file}")" "$(kq_format d_w_cert_unknown "${file}")"
    return 0
  fi

  now="$(date +%s)"
  days=$(((epoch - now) / 86400))

  if [ "${epoch}" -lt "${now}" ]; then
    check_fail "$(kq_format d_c_cert_expiry "${file}" "${enddate}")" "$(kq_format d_f_cert_expired "${file}" "${enddate}")"
  elif [ "${days}" -lt "${KQ_CERT_WARN_DAYS}" ]; then
    check_warn "$(kq_format d_c_cert_expiry "${file}" "${enddate}")" "$(kq_format d_w_cert_expiring "${file}" "${enddate}" "${days}")"
  else
    check_pass "$(kq_format d_c_cert_expiry "${file}" "${enddate}")"
  fi
}

# Puerto ESCUCHANDO, no puerto LIBRE: aqui es una buena senal, al reves que en
# la fase 1 del instalador. Misma tecnica que `port_in_use` de install.sh
# (0 ocupado · 1 libre · 2 no se ha podido averiguar); duplicada a proposito,
# porque no vive en una biblioteca comun y doctor.sh se ejecuta solo.
port_listening() {
  local port="$1"

  if command -v ss >/dev/null 2>&1; then
    [ -n "$(ss -ltnH "sport = :${port}" 2>/dev/null)" ] && return 0
    return 1
  fi

  if command -v netstat >/dev/null 2>&1 && netstat -ltn >/dev/null 2>&1; then
    netstat -ltn 2>/dev/null | grep -qE "[:.]${port}[[:space:]]" && return 0
    return 1
  fi

  # El descriptor se abre DENTRO de un subshell y muere con el; nada que
  # cerrar aqui (mismo razonamiento que install.sh).
  if (exec 3<>"/dev/tcp/127.0.0.1/${port}") 2>/dev/null; then
    return 0
  fi

  return 2
}

check_listening_ports() {
  local http_port https_port port status

  http_port="$(env_value "${CURRENT_ENV}" "HTTP_PORT")"
  https_port="$(env_value "${CURRENT_ENV}" "HTTPS_PORT")"
  [ -n "${http_port}" ] || http_port="80"
  [ -n "${https_port}" ] || https_port="443"

  for port in "${http_port}" "${https_port}"; do
    status=0
    port_listening "${port}" || status=$?

    case "${status}" in
    0) check_pass "$(kq_format d_c_port_listening "${port}")" ;;
    1) check_warn "$(kq_format d_c_port_not_listening "${port}")" "$(kq_format d_w_port_not_listening "${port}" "${CURRENT_COMPOSE}")" ;;
    *) check_warn "$(kq_format d_c_port_unknown "${port}")" "$(kq_format d_w_port_unknown "${port}")" ;;
    esac
  done
}

#------------------------------------------------------------------------------
main() {
  parse_arguments "$@"
  kq_msg_init "${OPT_LANG}"

  heading "$(kq_text d_title)"

  check_docker
  say ""
  if [ "${CHECKS_FAILED}" -gt 0 ]; then
    err "$(kq_format req_summary_fail "${CHECKS_FAILED}")"
    err "$(kq_format exit_line "${KQ_EXIT_REQUIREMENTS}" "$(kq_exit_name "${KQ_EXIT_REQUIREMENTS}")")"
    exit "${KQ_EXIT_REQUIREMENTS}"
  fi

  locate_installation
  check_app_running

  if [ "${APP_UP}" -eq 1 ]; then
    run_delegated_doctor
  else
    run_external_checks
  fi
}

# La guarda permite CARGAR este fichero sin ejecutarlo: es lo que usa la
# prueba de arquitectura para comprobar su cabecera sin invocar Docker.
if [ "${BASH_SOURCE[0]}" = "$0" ]; then
  main "$@"
fi
