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
#      segura de que un dia digan cosas distintas. Despues mira desde fuera lo
#      que `product:doctor` no puede ver desde dentro de `app`: que `scheduler`,
#      `horizon` y `reverb` estan en marcha (V3-PL-07) y que se ejecuta desde el
#      directorio de la instalacion vigente (V7-SC-1).
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
# shellcheck source=lib/kqe.sh disable=SC1091
. "${SCRIPT_DIR}/lib/kqe.sh"

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

# Redis en bucle de reinicio (R0): al menos este numero de reinicios Y menos de
# estos segundos encendido. Un contenedor que reinicio tres veces el mes pasado
# y lleva semanas estable NO esta en bucle: el contador no se pone a cero solo.
readonly KQ_REDIS_LOOP_MIN_RESTARTS=3
readonly KQ_REDIS_LOOP_UPTIME_SECONDS=120

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

# Salida de la rama delegada: `product:doctor` ha ido bien o con avisos, pero
# una comprobacion externa de este script (el rol de las copias) puede haber
# fallado.
finish_delegated() {
  if [ "${CHECKS_FAILED}" -gt 0 ]; then
    die "${KQ_EXIT_VERIFY_FAILED}" "$(kq_text d_f_external_failed)"
  fi
  exit "${KQ_EXIT_OK}"
}

#------------------------------------------------------------------------------
# Los procesos de fondo, con `app` en marcha (V3-PL-07). `product:doctor` corre
# DENTRO de `app` y no ve los demas contenedores: con `scheduler` parado decia
# «nada esta roto» y este script salia con 0. Cada uno, con su peso:
#
#   scheduler  FALLO. Sin el no hay copia nocturna, ni verificacion de la cadena
#              de auditoria, ni conciliacion del registro, ni purgas, ni metricas
#              del WAL. Nada de eso se nota hasta el dia que hace falta.
#   horizon    FALLO. Sin el no termina ningun trabajo en cola: la exportacion
#              integra (la que se entrega a la Inspeccion o al empleado que
#              ejerce su derecho de acceso), los informes en diferido y los
#              avisos de incidencias a los responsables. El fichaje sigue, pero
#              hay obligaciones que dejan de cumplirse sin que nadie lo vea.
#   reverb     AVISO. Solo empuja los cambios en vivo al panel (presencia):
#              recargar la pagina da el dato correcto. No se pierde nada.
#
# `restart: unless-stopped` en los tres: si estan parados, o alguien los paro a
# mano o estan cayendose en bucle. En los dos casos, la orden es la misma.
#------------------------------------------------------------------------------
check_background_services() {
  local service

  read_service_states
  for service in scheduler horizon reverb; do
    report_service_state "${service}" "$(service_state_of "${service}")"
  done
}

# Una sola lectura de `docker compose ps` para las dos comprobaciones de
# servicios: «servicio estado» por linea.
SERVICE_STATES=""
read_service_states() {
  SERVICE_STATES="$(compose_current ps -a --format '{{.Service}} {{.State}}' 2>/dev/null || true)"
}

service_state_of() {
  awk -v s="$1" '$1 == s { print $2 }' <<<"${SERVICE_STATES}" || true
}

# La gravedad de cada servicio parado, la MISMA con `app` en marcha o parada, y la
# misma que da `product:doctor` a Horizon (`queue.worker`): scheduler y horizon,
# fallo; reverb y los demas, aviso (con `app` parada, `app` ya es un fallo aparte).
report_service_state() {
  local service="$1" state="$2"

  if [ "${state}" = "running" ]; then
    check_pass "$(kq_format d_c_service_state "${service}" "${state}")"
    return 0
  fi
  state="${state:-$(kq_text absent)}"
  case "${service}" in
  scheduler)
    check_fail "$(kq_format d_c_service_state "${service}" "${state}")" \
      "$(kq_format d_f_scheduler_down "${CURRENT_COMPOSE}" "${CURRENT_COMPOSE}")"
    ;;
  horizon)
    check_fail "$(kq_format d_c_service_state "${service}" "${state}")" \
      "$(kq_format d_f_horizon_down "${CURRENT_COMPOSE}" "${CURRENT_COMPOSE}")"
    ;;
  reverb)
    check_warn "$(kq_format d_c_service_state "${service}" "${state}")" \
      "$(kq_format d_w_reverb_down "${CURRENT_COMPOSE}" "${CURRENT_COMPOSE}")"
    ;;
  *)
    check_warn "$(kq_format d_c_service_state "${service}" "${state}")" \
      "$(kq_format d_f_service_down "${CURRENT_COMPOSE}" "${service}" "${CURRENT_COMPOSE}" "${service}")"
    ;;
  esac
}

# Tras una actualizacion lado a lado hay dos directorios (V7-SC-1). Este script
# localiza la instalacion por las etiquetas de `app`, asi que diagnostica la
# vigente aunque se lance desde el anterior; pero quien lo lanza desde ahi es
# quien despues hara `docker compose up` desde ahi. Si el directorio de este
# script es un paquete (tiene VERSION) y no es el de la instalacion, se avisa.
check_running_from_current_dir() {
  local here there mine
  [ -f "${SCRIPT_DIR}/VERSION" ] || return 0
  here="$(cd -- "${SCRIPT_DIR}" && pwd -P)"
  there="$(cd -- "${CURRENT_DIR}" 2>/dev/null && pwd -P || printf '%s' "${CURRENT_DIR}")"
  [ "${here}" != "${there}" ] || return 0
  mine="$(head -n 1 "${SCRIPT_DIR}/VERSION" 2>/dev/null | tr -d '[:space:]' || true)"
  check_warn "$(kq_format d_c_other_dir "${here}" "${mine:-?}")" \
    "$(kq_format d_w_other_dir "${there}" "$(env_value "${CURRENT_ENV}" IMAGE_TAG)" "${there}")"
}

#------------------------------------------------------------------------------
# Paso 4a — `app` en marcha: delegar en el diagnostico real del producto.
#------------------------------------------------------------------------------
run_delegated_doctor() {
  local output status=0

  say "$(kq_text d_delegating)"
  say ""

  # Comprobaciones que `product:doctor` no puede hacer desde dentro (necesitan
  # a PostgreSQL con el superusuario, que el contenedor `app` no tiene): su
  # fallo tambien cuenta para el codigo de salida.
  check_backup_role
  check_backup_wal
  check_backup_mounts
  check_update_logs
  check_edge_networks
  check_redis_restart_loop
  check_app_storage
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
  if ! grep -q '^product:doctor' <<<"${available_commands}"; then
    die "${KQ_EXIT_VERIFY_FAILED}" "$(kq_text d_f_doctor_missing_command)"
  fi

  output="$(compose_current exec -T app php artisan product:doctor --lang="${KQ_LANG}" 2>&1)" || status=$?
  printf '%s\n' "${output}"
  say ""

  # DESPUES del informe de `product:doctor` y no antes: desde dentro de `app` no
  # se ve si los otros contenedores estan en marcha, y su resumen puede decir
  # «nada esta roto» con el planificador parado (V3-PL-07). Lo ultimo que se lee
  # tiene que ser lo que lo corrige.
  check_background_services
  check_running_from_current_dir
  say ""

  case "${status}" in
  0)
    check_pass "$(kq_text d_doctor_ok)"
    finish_delegated
    ;;
  1)
    check_warn "$(kq_text d_doctor_warn)" "$(kq_text d_doctor_warn_fix)"
    finish_delegated
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
  check_running_from_current_dir
  check_env_permissions
  check_image_digest_overrides "${CURRENT_ENV}" "$(dirname -- "${CURRENT_COMPOSE}")"
  check_backup_role
  check_backup_wal
  check_backup_mounts
  check_update_logs
  check_edge_networks
  check_redis_restart_loop
  check_app_storage
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
#
# Con la aplicacion parada se listan TODOS; los tres procesos de fondo se juzgan
# con la misma gravedad que con ella en marcha (report_service_state), y si su
# contenedor ni existe tambien se dice.
check_services_state() {
  local service state

  read_service_states
  # El tercer campo (salud) no se pide: `state` ya distingue "running" de
  # cualquier otra cosa. `_` descarta lo que sobre sin dejar una variable sin usar.
  while IFS=' ' read -r service state _; do
    [ -n "${service}" ] || continue
    report_service_state "${service}" "${state}"
  done <<<"${SERVICE_STATES}"
  for service in scheduler horizon reverb; do
    [ -n "$(service_state_of "${service}")" ] || report_service_state "${service}" ""
  done
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

# A3-03. El rol con el que se hacen las copias no puede ser privilegiado. Se
# pregunta a PostgreSQL por el NOMBRE que declara el `.env` (un nombre no es un
# secreto), con el socket local del propio contenedor `postgres` (pg_hba: local
# trust), asi que no hace falta ninguna contraseña. Es la sonda gemela de la
# comprobacion de `backup.sh`: una cubre la instalacion en reposo y la otra, el
# instante de copiar.
# Clave del WAL y archivo cifrado (ADR-049). Se mira desde fuera porque es lo que
# nadie ve hasta el dia de la recuperacion:
#   · BACKUP_WAL_KEY del .env es la DERIVADA de BACKUP_ENCRYPTION_KEY (una rotacion sin
#     recalcularla, o una edicion a mano, dejaria segmentos que la restauracion no sabria
#     abrir). No se imprime ningun valor.
#   · El `kid` de la cabecera del segmento cifrado mas reciente es el de esa derivada.
#   · Quedan segmentos heredados en claro (aviso: la migracion los cifra sola).
#   · KRONOQR_ACCEPT_UNAUTHENTICATED no esta en el .env ni en una tabla de cron (C12): la
#     bandera de copias heredadas se pasa por invocacion, nunca se deja puesta.
check_backup_wal() {
  local master actual derived header have_kid want_kid legacy table

  if grep -qE '^[[:space:]]*(export[[:space:]]+)?KRONOQR_ACCEPT_UNAUTHENTICATED=' "${CURRENT_ENV}" 2>/dev/null; then
    check_fail "$(kq_text d_c_accept_unauth)" "$(kq_format d_f_accept_unauth "${CURRENT_ENV}")"
  fi
  # Tampoco en una tabla de cron (el simulacro programado): root la lee y la variable se
  # heredaria en cada ejecucion.
  for table in /etc/crontab /etc/cron.d/* /var/spool/cron/crontabs/root /var/spool/cron/root; do
    [ -f "${table}" ] && [ -r "${table}" ] || continue
    if grep -qE '^[^#]*KRONOQR_ACCEPT_UNAUTHENTICATED' "${table}" 2>/dev/null; then
      check_fail "$(kq_text d_c_accept_unauth_cron)" "$(kq_format d_f_accept_unauth_cron "${table}")"
    fi
  done

  master="$(env_value "${CURRENT_ENV}" BACKUP_ENCRYPTION_KEY)"
  [ -n "${master}" ] || return 0
  if ! kqe_require; then
    check_warn "$(kq_text d_c_wal_key)" "$(kq_text d_w_wal_key_openssl)"
    return 0
  fi
  derived="$(kqe_derive_wal_key "${master}")" || derived=""
  actual="$(env_value "${CURRENT_ENV}" BACKUP_WAL_KEY)"
  if ! kqe_wal_key_valid "${derived}"; then
    check_warn "$(kq_text d_c_wal_key)" "$(kq_text d_w_wal_key_openssl)"
    return 0
  fi
  if [ "${actual}" != "${derived}" ]; then
    check_fail "$(kq_text d_c_wal_key)" "$(kq_format d_f_wal_key_mismatch "${CURRENT_ENV}" "${CURRENT_DIR}")"
    return 0
  fi

  # shellcheck disable=SC2016 # lo expande el shell DEL CONTENEDOR.
  header="$(compose_current exec -T postgres sh -c 'd="${KRONOQR_WAL_ARCHIVE_DIR:-/var/backups/fichaje/wal}"; f="$(ls -t "$d" 2>/dev/null | grep "\.gz\.enc$" | head -n 1)"; [ -n "$f" ] && head -c 300 "$d/$f" | head -n 1' 2>/dev/null || true)"
  have_kid="$(printf '%s' "${header}" | sed -n 's/^KQE1 kind=wal kid=\([0-9a-f]\{8\}\) .*/\1/p')"
  if [ -n "${have_kid}" ]; then
    want_kid="$(kqe_wal_kid "${derived}")"
    if [ "${have_kid}" != "${want_kid}" ]; then
      check_fail "$(kq_text d_c_wal_key)" "$(kq_format d_f_wal_key_kid "${CURRENT_DIR}")"
      return 0
    fi
  fi
  check_pass "$(kq_text d_c_wal_key)"

  # shellcheck disable=SC2016
  legacy="$(compose_current exec -T postgres sh -c 'ls "${KRONOQR_WAL_ARCHIVE_DIR:-/var/backups/fichaje/wal}"/*.gz 2>/dev/null | wc -l' 2>/dev/null | tr -d '[:space:]' || true)"
  if [[ "${legacy}" =~ ^[0-9]+$ ]] && [ "${legacy}" -gt 0 ]; then
    check_warn "$(kq_format d_c_wal_legacy "${legacy}")" "$(kq_text d_w_wal_legacy)"
  fi
}

# La raiz de BACKUP_PATH es de SOLO LECTURA para el runtime (A3-R2): `horizon` no puede
# escribir ahi. Si puede, el compose es el de la 2.1.0 (o alguien lo ha editado) y las
# copias vuelven a estar al alcance de quien ejecute codigo en la aplicacion.
check_backup_mounts() {
  local state
  # shellcheck disable=SC2016 # lo expande el shell DEL CONTENEDOR.
  state="$(compose_current exec -T horizon sh -c 'p="${BACKUP_PATH:-/var/backups/fichaje}"; if touch "$p/.doctor-probe" 2>/dev/null; then rm -f "$p/.doctor-probe"; echo writable; else echo readonly; fi' 2>/dev/null || true)"
  state="$(printf '%s' "${state}" | tr -d '[:space:]')"
  case "${state}" in
  readonly) check_pass "$(kq_text d_c_backup_root)" ;;
  writable) check_fail "$(kq_text d_c_backup_root)" "$(kq_format d_f_backup_root_writable "${CURRENT_COMPOSE}")" ;;
  esac
  return 0
}

# Plazo de conservacion del detalle de update.sh (C19): 30 dias (KRONOQR_LOG_RETENTION_DAYS,
# minimo 7) para `update-*.detalle.log` y la huella de la copia previa, 90 para el resumen
# local. Como root se purga; sin serlo, se avisa si hay algo caducado.
check_update_logs() {
  local dir="${KRONOQR_LOG_DIR:-/var/log/kronoqr}" days="${KRONOQR_LOG_RETENTION_DAYS:-30}" old
  [[ "${days}" =~ ^[0-9]+$ ]] && [ "${days}" -ge 7 ] || days=30
  [ -d "${dir}" ] || return 0

  if [ "$(id -u)" = "0" ] && kq_path_trusted "${dir}"; then
    find "${dir}" -maxdepth 1 -type f \( -name 'update-*.detalle.log' -o -name 'update-*.copia.sha256' \) -mtime +"${days}" -delete 2>/dev/null || true
    find "${dir}" -maxdepth 1 -type f -name 'update-*.log' ! -name 'update-*.detalle.log' -mtime +90 -delete 2>/dev/null || true
    check_pass "$(kq_format d_c_update_logs "${dir}" "${days}")"
    return 0
  fi
  old="$(find "${dir}" -maxdepth 1 -type f -name 'update-*.detalle.log' -mtime +"${days}" 2>/dev/null | wc -l | tr -d '[:space:]')"
  if [[ "${old}" =~ ^[0-9]+$ ]] && [ "${old}" -gt 0 ]; then
    check_warn "$(kq_format d_c_update_logs_old "${dir}" "${old}" "${days}")" "$(kq_format d_w_update_logs_old "${dir}")"
  fi
  return 0
}

check_backup_role() {
  local role state

  role="$(env_value "${CURRENT_ENV}" BACKUP_DB_USERNAME)"
  [ -n "${role}" ] || role="fichaje_backup"

  if ! [[ "${role}" =~ ^[a-z_][a-z0-9_]{0,62}$ ]]; then
    check_warn "$(kq_format d_c_backup_role_check "${role}")" "$(kq_format d_w_backup_role_name "${role}")"
    return 0
  fi

  # `sh -c` dentro del contenedor: POSTGRES_USER y POSTGRES_DB son de SU entorno.
  # El nombre del rol ya esta validado arriba, asi que va tal cual en el SQL.
  state="$(compose_current exec -T postgres sh -c "exec psql -U \"\$POSTGRES_USER\" -d \"\$POSTGRES_DB\" -Atq -c \"SELECT CASE WHEN rolsuper OR rolcreaterole OR rolcreatedb OR rolbypassrls THEN 'privileged' ELSE 'readonly' END FROM pg_roles WHERE rolname = '${role}'\"" 2>/dev/null)" || state="unknown"
  state="$(printf '%s' "${state}" | tr -d '[:space:]')"

  case "${state}" in
  readonly) check_pass "$(kq_format d_c_backup_role "${role}")" ;;
  privileged) check_fail "$(kq_format d_c_backup_role_check "${role}")" "$(kq_format d_f_backup_role_privileged "${role}")" ;;
  "") check_warn "$(kq_format d_c_backup_role_check "${role}")" "$(kq_format d_w_backup_role_missing "${role}")" ;;
  *) check_warn "$(kq_format d_c_backup_role_check "${role}")" "$(kq_format d_w_backup_role_unknown "${role}")" ;;
  esac
}

# Volumen `app-storage` (ADR-045, R3-PL-01). `app`, `horizon` y `scheduler` son
# tres contenedores de la misma imagen y solo ven los mismos ficheros si montan
# el MISMO volumen en `storage/app`: sin el, la exportacion integra que genera
# `horizon` devuelve 404 en `app` y las purgas de `scheduler` no ven nada. Es el
# fallo que ninguna prueba en un solo proceso puede detectar, asi que se mira
# aqui, en la instalacion real.
#
# SIEMPRE (tambien con `app` parada, `docker inspect` lee contenedores
# detenidos): que el volumen existe y que los tres servicios lo montan en
# lectura y escritura. SOLO CON `app` EN MARCHA: que un fichero escrito desde
# `horizon` se lee desde `app`, que la raiz es `app:app 0700` (C6: un fichero
# con todos los datos personales no puede quedar legible por otro usuario) y
# el tamano. El fichero de la prueba empieza por `.doctor-probe-`, no casa con
# el patron de ninguna purga y se borra siempre.
readonly KQ_STORAGE_MOUNT="/var/www/html/storage/app"

check_app_storage() {
  local service container mount token size owner mode horizon_state
  local missing=0

  if [ -n "$(docker volume ls -q \
    --filter "label=com.docker.compose.project=${KQ_COMPOSE_PROJECT}" \
    --filter "label=com.docker.compose.volume=app-storage" 2>/dev/null || true)" ]; then
    check_pass "$(kq_text d_c_storage_volume)"
  else
    check_fail "$(kq_text d_c_storage_volume_missing)" \
      "$(kq_format d_f_storage_volume_missing "${CURRENT_COMPOSE}" "${CURRENT_COMPOSE}")"
    missing=1
  fi

  for service in app horizon scheduler; do
    container="$(compose_current ps -a -q "${service}" 2>/dev/null || true)"
    container="${container%%$'\n'*}"
    [ -n "${container}" ] || continue

    # Tipo|nombre|escritura del montaje que cae en storage/app.
    mount="$(docker inspect -f \
      '{{range .Mounts}}{{if eq .Destination "'"${KQ_STORAGE_MOUNT}"'"}}{{.Type}}|{{.Name}}|{{.RW}}{{end}}{{end}}' \
      "${container}" 2>/dev/null || true)"

    case "${mount}" in
    volume'|'*_app-storage'|true')
      check_pass "$(kq_format d_c_storage_mounted "${service}")"
      ;;
    *)
      check_fail "$(kq_format d_c_storage_not_mounted "${service}")" \
        "$(kq_format d_f_storage_not_mounted "${CURRENT_COMPOSE}" "${service}")"
      missing=1
      ;;
    esac
  done

  # Lo que sigue necesita contenedores en marcha y un montaje que valga.
  [ "${APP_UP}" -eq 1 ] && [ "${missing}" -eq 0 ] || return 0

  horizon_state="$(compose_current ps --format '{{.Service}} {{.State}}' 2>/dev/null |
    awk '$1 == "horizon" { print $2 }' || true)"
  # Restos de una ejecucion interrumpida (Ctrl-C entre escribir y borrar): el
  # nombre `.doctor-probe-*` no casa con ninguna purga y se quedaria para siempre
  # en la raiz del volumen. Se barren al empezar, y tambien los de esta pasada
  # al terminar.
  # shellcheck disable=SC2016 # `$1` es del `sh -c` del contenedor.
  compose_current exec -T horizon sh -c 'rm -f -- "$1"/.doctor-probe-*' sh "${KQ_STORAGE_MOUNT}" >/dev/null 2>&1 || true
  token=".doctor-probe-$$-$(date +%s)"
  if [ "${horizon_state}" = "running" ] &&
    compose_current exec -T horizon sh -c "umask 077 && : > '${KQ_STORAGE_MOUNT}/${token}'" >/dev/null 2>&1; then
    if compose_current exec -T app test -f "${KQ_STORAGE_MOUNT}/${token}" >/dev/null 2>&1; then
      check_pass "$(kq_text d_c_storage_shared)"
    else
      check_fail "$(kq_text d_c_storage_not_shared)" \
        "$(kq_format d_f_storage_not_shared "${CURRENT_COMPOSE}" "${CURRENT_COMPOSE}" "${CURRENT_COMPOSE}")"
    fi
    compose_current exec -T horizon rm -f -- "${KQ_STORAGE_MOUNT}/${token}" >/dev/null 2>&1 || true
  elif [ "${horizon_state}" = "running" ]; then
    check_fail "$(kq_text d_c_storage_not_shared)" \
      "$(kq_format d_f_storage_not_shared "${CURRENT_COMPOSE}" "${CURRENT_COMPOSE}" "${CURRENT_COMPOSE}")"
  else
    check_warn "$(kq_text d_c_storage_probe_skipped)" "$(kq_format d_w_storage_probe_skipped "${CURRENT_COMPOSE}")"
  fi

  owner="$(compose_current exec -T app stat -c '%U:%G' "${KQ_STORAGE_MOUNT}" 2>/dev/null || true)"
  mode="$(compose_current exec -T app stat -c '%a' "${KQ_STORAGE_MOUNT}" 2>/dev/null || true)"
  owner="${owner//[$'\r\n']/}"
  mode="${mode//[$'\r\n']/}"
  if [ "${owner}" = "app:app" ] && [ "${mode}" = "700" ]; then
    check_pass "$(kq_text d_c_storage_root)"
  else
    check_fail "$(kq_format d_c_storage_root_bad "${owner:-?}" "${mode:-?}")" \
      "$(kq_format d_f_storage_root "${CURRENT_COMPOSE}")"
  fi

  size="$(compose_current exec -T app du -sh "${KQ_STORAGE_MOUNT}" 2>/dev/null | awk '{ print $1 }' || true)"
  [ -z "${size}" ] || check_pass "$(kq_format d_c_storage_size "${size}")"
  return 0
}

# Las redes del borde del .env (PP-01, PP-03, I1): sintaxis, cobertura del portal
# y de Prometheus, y TRUSTED_PROXY_CIDR. Es la MISMA comprobacion que hacen
# install.sh y update.sh (lib/checks.sh). `product:doctor` la repite desde
# dentro con su sonda de redes; aqui se hace sin depender de que `app` este en
# pie, que es cuando mas falta hace: con un CIDR invalido, nginx no arranca.
check_edge_networks() {
  check_network_cidrs "${CURRENT_ENV}" "${CURRENT_COMPOSE}"
}

# R0. Un corte de luz puede dejar el AOF de Redis con una escritura a medias y
# Redis entra en bucle de reinicio («Bad file format reading the append only
# file»). Es traicionero porque el fichaje NO se cae (CH1: los quioscos encolan
# y /scan responde) y el sintoma aparece lejos: el panel y el portal no dejan
# entrar (las sesiones viven en Redis), las colas se paran y `/ready` da 503.
# `docker compose ps` solo diria «restarting» y nada sobre como salir de ahi.
check_redis_restart_loop() {
  local container info status restarts started started_epoch uptime logs loop=0

  container="$(compose_current ps -a -q redis 2>/dev/null || true)"
  container="${container%%$'\n'*}"
  [ -n "${container}" ] || return 0

  info="$(docker inspect -f '{{.State.Status}}|{{.RestartCount}}|{{.State.StartedAt}}' "${container}" 2>/dev/null || true)"
  [ -n "${info}" ] || return 0
  IFS='|' read -r status restarts started <<<"${info}"
  [[ "${restarts}" =~ ^[0-9]+$ ]] || restarts=0

  case "${status}" in
  restarting) loop=1 ;;
  running)
    # Docker da `2026-09-30T13:05:27.123456789Z`. Sin fracciones ni la `T`, que es
    # lo que entienden tanto `date` de GNU como el de BusyBox.
    started="${started%%.*}"
    started="${started%Z}"
    started="${started/T/ }"
    started_epoch="$(date -u -d "${started}" +%s 2>/dev/null || true)"
    if [[ "${started_epoch}" =~ ^[0-9]+$ ]]; then
      uptime=$(($(date +%s) - started_epoch))
      if [ "${restarts}" -ge "${KQ_REDIS_LOOP_MIN_RESTARTS}" ] && [ "${uptime}" -lt "${KQ_REDIS_LOOP_UPTIME_SECONDS}" ]; then
        loop=1
      fi
    fi
    ;;
  esac

  if [ "${loop}" -eq 0 ]; then
    check_pass "$(kq_format d_c_redis_stable "${status}" "${restarts}")"
    return 0
  fi

  # Sin tuberia: `grep -q` sobre una tuberia con pipefail da falsos «no».
  logs="$(compose_current logs --no-color --tail 50 redis 2>&1 || true)"
  if grep -qiE 'append only file|appendonly' <<<"${logs}"; then
    check_fail "$(kq_format d_c_redis_loop "${restarts}")" \
      "$(kq_format d_f_redis_loop_aof "${CURRENT_COMPOSE}" "${CURRENT_COMPOSE}" "${CURRENT_COMPOSE}")"
  else
    check_fail "$(kq_format d_c_redis_loop "${restarts}")" "$(kq_format d_f_redis_loop_other "${CURRENT_COMPOSE}")"
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
    check_warn "$(kq_format d_c_cert_expiry_unknown "${file}")" "$(kq_format d_w_cert_unknown "${file}" "${file}")"
    return 0
  fi

  enddate="$(openssl x509 -enddate -noout -in "${file}" 2>/dev/null | sed 's/^notAfter=//')"
  epoch=""
  [ -z "${enddate}" ] || epoch="$(date -d "${enddate}" +%s 2>/dev/null || true)"

  if [ -z "${enddate}" ] || [ -z "${epoch}" ]; then
    check_warn "$(kq_format d_c_cert_expiry_unknown "${file}")" "$(kq_format d_w_cert_unknown "${file}" "${file}")"
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
  local port="$1" listing

  if command -v ss >/dev/null 2>&1; then
    [ -n "$(ss -ltnH "sport = :${port}" 2>/dev/null)" ] && return 0
    return 1
  fi

  if command -v netstat >/dev/null 2>&1 && netstat -ltn >/dev/null 2>&1; then
    # Capturado y no en tuberia: con `pipefail`, `grep -q` cierra el tubo al
    # primer acierto y el productor puede morir por SIGPIPE, dando «libre»
    # donde habia un proceso escuchando.
    listing="$(netstat -ltn 2>/dev/null || true)"
    grep -qE "[:.]${port}[[:space:]]" <<<"${listing}" && return 0
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
