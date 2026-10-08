#!/usr/bin/env bash
#
# KronoQR — comprobaciones de requisitos comunes a install.sh y update.sh.
#
# NO SE EJECUTA SOLO: lo cargan los dos scripts despues de lib/messages.sh, del
# que toman los textos (`check_ok`, `check_fail`, `c_docker`, ...). Hasta la
# tarea 5.7 estas funciones vivian dentro de install.sh; el actualizador las
# necesita identicas —mismo umbral de Docker, mismo mensaje de "que hacer"— y
# dos copias son la forma segura de que un dia digan cosas distintas a la misma
# persona.
#
# CONTRATO CON EL SCRIPT QUE LO CARGA: declara los contadores CHECKS_RUN,
# CHECKS_FAILED y CHECKS_WARNED antes de llamar a nada de aqui. Cada `check_*`
# los incrementa; el script decide al final de su fase de requisitos si sigue
# o sale con 2.

set -euo pipefail
IFS=$'\n\t'

# Umbral publicado (doc 02 §11.6.2). Es el MINIMO, no el recomendado.
readonly KQ_MIN_DOCKER_MAJOR=24

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

# Docker Engine accesible y en version soportada, y el plugin de Compose v2.
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

# Las dos herramientas del sistema que usan los scripts ademas de Docker.
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

#------------------------------------------------------------------------------
# Redes del borde HTTP (PP-01, PP-03, I1). Las usan install.sh, update.sh y
# doctor.sh; el contrato es el mismo de arriba y ademas cada script define
# `env_value RUTA CLAVE` (lo que lee una clave del .env sin ejecutarlo).
#------------------------------------------------------------------------------

# IP fija de Prometheus en la red de observabilidad (`ipv4_address` del servicio
# `prometheus` en compose.prod.yaml, que una prueba de arquitectura mantiene
# igual que esto). /metrics solo admite a METRICS_ALLOW_CIDR, asi que si ese
# rango no la cubre, Prometheus recibe 403 y las alertas mueren en silencio.
readonly KQ_PROMETHEUS_IP="172.29.0.20"

# La MISMA sintaxis que valida el borde en 04-kronoqr-required-env.sh: UN rango
# IPv4 con prefijo, octetos 0-255 sin ceros a la izquierda, prefijo 0-32. Si aqui
# pasara algo que alli no, el instalador daria el visto bueno a una instalacion
# cuyo nginx se queda en bucle de reinicio.
readonly KQ_CIDR_OCTET='(25[0-5]|2[0-4][0-9]|1[0-9]{2}|[1-9]?[0-9])'
readonly KQ_CIDR_PREFIX='(3[0-2]|[12]?[0-9])'
readonly KQ_CIDR_IPV4_RE="^${KQ_CIDR_OCTET}\.${KQ_CIDR_OCTET}\.${KQ_CIDR_OCTET}\.${KQ_CIDR_OCTET}/${KQ_CIDR_PREFIX}\$"

# 0 si el argumento es un CIDR IPv4 valido.
kq_cidr_valid() {
  [[ "$1" =~ ${KQ_CIDR_IPV4_RE} ]]
}

# Direccion a.b.c.d -> entero de 32 bits. Solo se le pasa lo ya validado.
kq_ipv4_to_int() {
  local a b c d
  IFS=. read -r a b c d <<<"$1"
  printf '%s' "$(((a << 24) | (b << 16) | (c << 8) | d))"
}

# Extremos de un CIDR como "inicio fin" (enteros).
kq_cidr_bounds() {
  local base="${1%/*}" len="${1#*/}" ip size start

  ip="$(kq_ipv4_to_int "${base}")"
  size=$((1 << (32 - len)))
  start=$((ip & ~(size - 1) & 0xFFFFFFFF))
  printf '%s %s' "${start}" "$((start + size - 1))"
}

# 0 si los dos CIDR comparten alguna direccion. Acepta una direccion suelta
# como segundo argumento (se toma como /32).
kq_cidr_overlaps() {
  local a="$1" b="$2" a_start a_end b_start b_end

  [[ "${b}" == */* ]] || b="${b}/32"
  IFS=" " read -r a_start a_end <<<"$(kq_cidr_bounds "${a}")"
  IFS=" " read -r b_start b_end <<<"$(kq_cidr_bounds "${b}")"

  [ "${a_start}" -le "${b_end}" ] && [ "${b_start}" -le "${a_end}" ]
}

# 0 si TODO el rango cae dentro de una red no enrutable en internet: RFC 1918,
# loopback, enlace local y CGNAT (100.64/10, el que usan Tailscale y algunos
# operadores). Un rango que se sale de ahi incluye direcciones publicas.
kq_cidr_is_private() {
  local cidr="$1" start end private p_start p_end

  IFS=" " read -r start end <<<"$(kq_cidr_bounds "${cidr}")"

  for private in 10.0.0.0/8 172.16.0.0/12 192.168.0.0/16 127.0.0.0/8 169.254.0.0/16 100.64.0.0/10; do
    IFS=" " read -r p_start p_end <<<"$(kq_cidr_bounds "${private}")"
    if [ "${start}" -ge "${p_start}" ] && [ "${end}" -le "${p_end}" ]; then
      return 0
    fi
  done

  return 1
}

# Direcciones IPv4 de este servidor, una por linea. Vacio si no se puede saber.
kq_local_ipv4_addresses() {
  local listing

  if command -v ip >/dev/null 2>&1; then
    listing="$(ip -4 -o addr show 2>/dev/null || true)"
    if [ -n "${listing}" ]; then
      awk '{ split($4, a, "/"); print a[1] }' <<<"${listing}"
      return 0
    fi
  fi

  if command -v hostname >/dev/null 2>&1; then
    hostname -I 2>/dev/null | tr ' ' '\n' | grep -E '^[0-9]+\.[0-9]+\.[0-9]+\.[0-9]+$' || true
  fi

  return 0
}

# 0 si el .env pide levantar el perfil de observabilidad. COMPOSE_PROFILES es
# una lista separada por comas.
kq_observability_enabled() {
  local profiles
  profiles="$(env_value "$1" "COMPOSE_PROFILES")"
  profiles="${profiles//[[:space:]]/}"

  case ",${profiles}," in
  *,observability,* | *,\**) return 0 ;;
  esac
  return 1
}

# El valor de un .env ya saneado para ir dentro de un mensaje: sin caracteres de
# control ni nada raro, y acotado. Un valor mal escrito puede llevar de todo.
kq_printable_value() {
  local value="${1//[^0-9A-Za-z.\/: ,@_-]/?}"
  printf '%s' "${value:0:80}"
}

# Comprueba KIOSK_VLAN_CIDR, PORTAL_INTERNAL_CIDR, METRICS_ALLOW_CIDR y
# TRUSTED_PROXY_CIDR del .env.
#
#   check_network_cidrs ENV_FILE COMPOSE_FILE [skip-missing]
#
# FALLA (el borde no arranca): sintaxis invalida, o TRUSTED_PROXY_CIDR con
# 0.0.0.0/0, o una de las tres obligatorias vacia (salvo `skip-missing`: el
# instalador ya lo comprueba en `check_customer_values`).
#
# AVISA (el borde arranca, pero algo no cuadra):
#   · PORTAL_INTERNAL_CIDR=0.0.0.0/0, o con direcciones publicas. El propietario
#     puede abrir el portal a internet a proposito (RF-ID-08): es un aviso que lo
#     hace visible, nunca un error.
#   · PORTAL_INTERNAL_CIDR privado que no contiene ninguna red de este servidor
#     ni la de los quioscos: lo que paso en el primer despliegue real, donde el
#     valor de serie era la red de desarrollo y el portal dio 403 a todos.
#   · KIOSK_VLAN_CIDR o METRICS_ALLOW_CIDR = 0.0.0.0/0.
#   · METRICS_ALLOW_CIDR que no cubre a Prometheus con la observabilidad
#     encendida (I1).
check_network_cidrs() {
  local env_file="$1" compose_file="$2" skip_missing="${3:-}"
  local key value kiosk="" portal="" metrics="" valid_kiosk=0 addresses address covered=0

  for key in KIOSK_VLAN_CIDR PORTAL_INTERNAL_CIDR METRICS_ALLOW_CIDR; do
    value="$(env_value "${env_file}" "${key}")"

    if [ -z "${value}" ]; then
      if [ -z "${skip_missing}" ]; then
        check_fail "$(kq_format c_cidr_missing "${key}" "${env_file}")" \
          "$(kq_format f_cidr_missing "${key}" "${env_file}")"
      fi
      continue
    fi

    if ! kq_cidr_valid "${value}"; then
      check_fail "$(kq_format c_cidr_invalid "${key}" "$(kq_printable_value "${value}")")" \
        "$(kq_format f_cidr_invalid "${key}" "${env_file}")"
      continue
    fi

    check_pass "$(kq_format c_cidr_ok "${key}" "${value}")"
    case "${key}" in
    KIOSK_VLAN_CIDR) kiosk="${value}" ;;
    PORTAL_INTERNAL_CIDR) portal="${value}" ;;
    METRICS_ALLOW_CIDR) metrics="${value}" ;;
    esac
  done

  if [ -n "${kiosk}" ] && [ "${kiosk#*/}" = "0" ]; then
    check_warn "$(kq_text c_kiosk_open)" "$(kq_format w_kiosk_open "${env_file}" "${compose_file}")"
  fi
  [ -z "${kiosk}" ] || valid_kiosk=1

  if [ -n "${portal}" ]; then
    if [ "${portal#*/}" = "0" ]; then
      check_warn "$(kq_text c_portal_open)" "$(kq_format w_portal_open "${env_file}" "${compose_file}")"
    elif ! kq_cidr_is_private "${portal}"; then
      check_warn "$(kq_format c_portal_public "${portal}")" "$(kq_format w_portal_public "${env_file}" "${compose_file}")"
    else
      addresses="$(kq_local_ipv4_addresses)"
      if [ "${valid_kiosk}" -eq 1 ] && kq_cidr_overlaps "${portal}" "${kiosk}"; then
        covered=1
      fi
      while IFS= read -r address; do
        [ -n "${address}" ] || continue
        if kq_cidr_overlaps "${portal}" "${address}"; then
          covered=1
          break
        fi
      done <<<"${addresses}"

      # Sin direcciones locales ni VLAN de quioscos valida no hay con que
      # comparar, y callar es lo honesto: un aviso sin base seria ruido.
      if [ "${covered}" -eq 1 ]; then
        check_pass "$(kq_format c_portal_covers "${portal}")"
      elif [ -n "${addresses}" ] || [ "${valid_kiosk}" -eq 1 ]; then
        check_warn "$(kq_format c_portal_nobody "${portal}")" "$(kq_format w_portal_nobody "${env_file}" "${compose_file}")"
      fi
    fi
  fi

  if [ -n "${metrics}" ]; then
    if [ "${metrics#*/}" = "0" ]; then
      check_warn "$(kq_text c_metrics_open)" "$(kq_format w_metrics_open "${env_file}" "${KQ_PROMETHEUS_IP}" "${compose_file}")"
    elif kq_observability_enabled "${env_file}"; then
      if kq_cidr_overlaps "${metrics}" "${KQ_PROMETHEUS_IP}"; then
        check_pass "$(kq_format c_metrics_covers "${metrics}" "${KQ_PROMETHEUS_IP}")"
      else
        check_warn "$(kq_format c_metrics_not_prometheus "${metrics}" "${KQ_PROMETHEUS_IP}")" \
          "$(kq_format w_metrics_not_prometheus "${KQ_PROMETHEUS_IP}" "${KQ_PROMETHEUS_IP}" "${env_file}" "${compose_file}")"
      fi
    fi
  fi

  check_trusted_proxy_cidr "${env_file}"
}

# El portal, esta expuesto a internet? Imprime `true` si PORTAL_INTERNAL_CIDR es
# valido y es 0.0.0.0/0 o incluye direcciones que no son de una red privada, y
# nada en cualquier otro caso (privado, vacio o invalido). Es el dato que
# `update.sh` anota como `portal_exposed` en el asiento `system.updated`: la
# constancia de que el propietario abrio el portal a proposito (RF-ID-08).
kq_portal_exposed() {
  local env_file="$1" portal

  portal="$(env_value "${env_file}" "PORTAL_INTERNAL_CIDR")"
  kq_cidr_valid "${portal}" || return 0

  if [ "${portal#*/}" = "0" ] || ! kq_cidr_is_private "${portal}"; then
    printf 'true'
  fi
}

# IMAGE_DIGEST_* declaradas en el .env (A6-2). El paquete entregado fija cada
# imagen por digest; declarar la variable en el .env la anula (vacia: el IT sin
# salida a internet que carga las imagenes con `docker load`; con otro valor: un
# digest que no es el del paquete). Es una salida legitima pero INVISIBLE y
# persistente: este aviso la hace visible en install, update y doctor.
#
#   check_image_digest_overrides ENV_FILE PACKAGE_DIR
#
# Deja en KQ_DIGEST_OVERRIDES los nombres que difieren del `images.lock` del
# paquete (cadena vacia si ninguno), para que el informe de update lo anote. Si
# el paquete no trae `images.lock` (desarrollo y CI), lo esperado es no declarar
# nada. Devuelve siempre 0.
KQ_DIGEST_OVERRIDES=""
check_image_digest_overrides() {
  local env_file="$1" package_dir="$2" image var expected declared names=""

  KQ_DIGEST_OVERRIDES=""
  [ -f "${env_file}" ] || return 0

  for image in php nginx postgres; do
    var="IMAGE_DIGEST_$(printf '%s' "${image}" | tr '[:lower:]' '[:upper:]')"
    grep -qE "^[[:space:]]*(export[[:space:]]+)?${var}=" "${env_file}" || continue
    expected=""
    if [ -f "${package_dir}/images.lock" ]; then
      expected="$(awk -v image="${image}" '$1 == image { print $2 }' "${package_dir}/images.lock")"
      [ -z "${expected}" ] || expected="@${expected}"
    fi
    declared="$(env_value "${env_file}" "${var}")"
    [ "${declared}" = "${expected}" ] || names="${names:+${names}, }${var}"
  done

  [ -n "${names}" ] || return 0
  # shellcheck disable=SC2034  # lo lee update.sh (informe); install y doctor no.
  KQ_DIGEST_OVERRIDES="${names}"
  check_warn "$(kq_format c_digest_override "${names}")" "$(kq_text w_digest_override)"
}

# TRUSTED_PROXY_CIDR (PP-03): opcional; lista de CIDR IPv4 separados por comas.
check_trusted_proxy_cidr() {
  local env_file="$1" value proxy invalid=0 any=0
  local -a proxies=()

  value="$(env_value "${env_file}" "TRUSTED_PROXY_CIDR")"
  [ -n "${value}" ] || return 0

  IFS=',' read -r -a proxies <<<"${value}"
  for proxy in "${proxies[@]}"; do
    proxy="${proxy#"${proxy%%[![:space:]]*}"}"
    proxy="${proxy%"${proxy##*[![:space:]]}"}"
    if ! kq_cidr_valid "${proxy}"; then
      invalid=1
    elif [ "${proxy#*/}" = "0" ]; then
      any=1
    fi
  done

  if [ "${invalid}" -eq 1 ]; then
    check_fail "$(kq_format c_proxy_invalid "$(kq_printable_value "${value}")")" "$(kq_format f_proxy_invalid "${env_file}")"
  elif [ "${any}" -eq 1 ]; then
    check_fail "$(kq_text c_proxy_any)" "$(kq_format f_proxy_any "${env_file}")"
  else
    check_pass "$(kq_format c_proxy_ok "$(kq_printable_value "${value}")")"
  fi
}

#------------------------------------------------------------------------------
# Correo, alertas, perfil y licencia (I3). Solo las usa install.sh, en la fase 1.
#------------------------------------------------------------------------------
# El criterio: ERROR solo si el producto no puede funcionar sin ello; AVISO si
# lo que falla es una capacidad opcional que el cliente puede querer tener.
#
#   COMPLIANCE_PROFILE  ERROR si esta vacio. Es el perfil de cumplimiento con el
#                       que se marca la instalacion y no tiene sentido sin el.
#   LICENSE_KEY         AVISO si falta o tiene mal formato. La licencia jamas
#                       bloquea el fichaje ni el registro (ADR-019, regla 15):
#                       un sistema recien instalado tiene que poder fichar
#                       aunque la clave llegue una semana despues.
#   MAIL_*              AVISO si el SMTP es el de desarrollo (`mailpit`) o esta
#                       vacio. El producto no depende del correo (regla 12).
#   ALERT_*             AVISO si ningun destinatario de alertas, o uno mal
#                       escrito. Solo importa con la observabilidad encendida,
#                       que es la que tiene Alertmanager.
#
#   check_operational_settings ENV_FILE COMPOSE_FILE
check_operational_settings() {
  local env_file="$1" compose_file="$2" value mailer key set=0

  value="$(env_value "${env_file}" "COMPLIANCE_PROFILE")"
  if [ -n "${value}" ]; then
    check_pass "$(kq_format c_env_key "COMPLIANCE_PROFILE")"
  else
    check_fail "$(kq_format c_env_missing "COMPLIANCE_PROFILE")" "$(kq_format f_env_key "COMPLIANCE_PROFILE" "${env_file}")"
  fi

  value="$(env_value "${env_file}" "LICENSE_KEY")"
  if [ -z "${value}" ]; then
    check_warn "$(kq_text c_license_empty)" "$(kq_format w_license_empty "${compose_file}")"
  elif [[ "${value}" =~ ^KQL1\.[A-Za-z0-9_=-]+\.[A-Za-z0-9_=-]+$ ]]; then
    check_pass "$(kq_text c_license_ok)"
  else
    check_warn "$(kq_text c_license_format)" "$(kq_format w_license_format "${env_file}")"
  fi

  mailer="$(env_value "${env_file}" "MAIL_MAILER")"
  value="$(env_value "${env_file}" "MAIL_HOST")"
  case "${mailer}" in
  "" | smtp)
    case "${value,,}" in
    "" | mailpit)
      check_warn "$(kq_format c_mail_unset "${value:-$(kq_text empty_value)}")" "$(kq_format w_mail_unset "${env_file}")"
      ;;
    *)
      check_pass "$(kq_text c_mail_ok)"
      ;;
    esac
    ;;
  esac

  # Alertmanager solo existe en el perfil de observabilidad: sin el, estas
  # variables no las lee nadie y avisar seria ruido.
  kq_observability_enabled "${env_file}" || return 0

  for key in ALERT_EMAIL_IT ALERT_EMAIL_RRHH ALERT_EMAIL_SEGURIDAD; do
    [ -z "$(env_value "${env_file}" "${key}")" ] || set=1
  done

  if [ "${set}" -eq 0 ]; then
    check_warn "$(kq_text c_alert_none)" "$(kq_format w_alert_none "${env_file}")"
  else
    check_pass "$(kq_text c_alert_ok)"
    for key in ALERT_EMAIL_IT ALERT_EMAIL_RRHH ALERT_EMAIL_SEGURIDAD; do
      value="$(env_value "${env_file}" "${key}")"
      if [ -z "${value}" ]; then
        check_warn "$(kq_format c_alert_empty "${key}")" "$(kq_format w_alert_empty "${env_file}")"
      elif ! [[ "${value}" =~ ^[^[:space:]@,\;]+@[^[:space:]@,\;]+$ ]]; then
        check_warn "$(kq_format c_alert_bad_email "${key}")" "$(kq_format w_alert_bad_email "${env_file}")"
      fi
    done
  fi

  for key in ALERT_WEBHOOK_IT ALERT_WEBHOOK_RRHH ALERT_WEBHOOK_SEGURIDAD; do
    value="$(env_value "${env_file}" "${key}")"
    if [ -n "${value}" ] && ! [[ "${value}" =~ ^https?:// ]]; then
      check_warn "$(kq_format c_alert_bad_webhook "${key}")" "$(kq_format w_alert_bad_webhook "${env_file}")"
    fi
  done
}

# Repite una sonda hasta que responda o pasen KQ_READY_GRACE_SECONDS (12 s),
# consultando cada KQ_POLL_SECONDS. Uso: kq_retry_probe RUTA orden [args...]
#
# POR QUE NO BASTA UN INTENTO (revision del bloque 22). Desde la 2.2.0 la
# aplicacion tiene cortacircuitos hacia Redis y PostgreSQL: si al arrancar
# encuentra una de las dos un instante antes de que acepte conexiones, abre el
# circuito durante REDIS_/DB_CIRCUIT_BREAKER_SECONDS (10 s por defecto) y
# /api/v1/ready responde 503 hasta que se cierra. Un unico intento convertia
# ese parpadeo en una instalacion o una actualizacion deshechas. 12 s = el
# tiempo del circuito y dos de margen; no mas, para que un fallo de verdad se
# diga pronto.
#
# Las llamadas que capturan la salida (`body="$(kq_retry_probe ...)"`) reciben
# solo la de la sonda: el aviso de espera va a stderr. La orden se evalua como
# condicion, asi que su fallo no dispara el `trap ERR` de quien la llama.
kq_retry_probe() {
  local path="$1" deadline announced=0
  shift
  deadline=$((SECONDS + KQ_READY_GRACE_SECONDS))

  until "$@"; do
    [ "${SECONDS}" -lt "${deadline}" ] || return 1
    if [ "${announced}" -eq 0 ]; then
      printf '%s\n' "$(kq_format probe_waiting "${path}" "${KQ_READY_GRACE_SECONDS}")" >&2 || true
      announced=1
    fi
    sleep "${KQ_POLL_SECONDS}"
  done
}
