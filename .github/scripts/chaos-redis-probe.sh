#!/usr/bin/env bash
#
# KronoQR — etapa ⑧ de la CI: cuanto tarda la aplicacion en responder con
# Redis PARADO, medido en Linux (R3-CH-01, bloque 22 de la 2.2.0).
#
# NO ES UN ENTREGABLE: vive en .github/scripts/ y solo lo ejecuta la CI.
#
# POR QUE EXISTE. R3-CH-01 se midio en Docker Desktop: con Redis desaparecido
# del DNS cada operacion que lo toca (cache, cola, limitador, sesion, metricas)
# volvia a intentar la conexion (3,85 s de DNS en Docker Desktop, varias veces
# por peticion: 12-45 s y el pool de FPM saturado). El plan pide medirlo en
# Linux, y el unico Linux del proyecto es el runner de la CI. Esto lo hace
# sobre la instalacion que acaba de dejar el instalador, con el servicio
# `redis` PARADO (no eliminado: `docker compose stop` quita el nombre del DNS
# de la red del compose, que es el caso malo).
#
# QUE HACE.
#   0. Comprueba que la instalacion esta sana (/ready 200) y mide la referencia
#      con Redis arriba.
#   1. `docker compose stop redis`.
#   2. Mide DOS rondas de tres peticiones: GET /api/v1/health, GET
#      /api/v1/ready y POST /api/v1/scan/pin con cuerpo invalido (422 sin
#      tocar Redis). La primera ronda abre el cortacircuitos y puede ser lenta;
#      la segunda debe ser rapida.
#   3. `docker compose start redis` y espera a que /ready vuelva a 200.
#   4. Imprime la tabla y FALLA si alguna medida de la ultima ronda supera el
#      umbral, o si el fichaje por PIN responde 5xx con Redis parado (regla
#      dura 19: el fichaje no se bloquea).
#
# DEJA LA INSTALACION COMO ESTABA: un `trap` arranca `redis` de nuevo pase lo
# que pase, y si ya estaba parado al empezar, lo avisa y sale sin tocar nada.
#
# Uso:
#   chaos-redis-probe.sh DIRECTORIO_DEL_PAQUETE
#
# Variables:
#   KQ_E2E_BASE_URL            (https://kronoqr.ci.local) donde responde el borde.
#   KQ_E2E_SUDO                (sudo) prefijo para docker y el .env. Vacio si ya
#                              se es root.
#   KQ_PROBE_MAX_SECONDS       (3) tope de /health y de /scan/pin en la ultima ronda.
#   KQ_PROBE_READY_MAX_SECONDS (3) tope de /ready en la ultima ronda.
#   KQ_PROBE_ROUNDS            (2) rondas con Redis parado; se evalua la ultima.
#   KQ_PROBE_REQUEST_TIMEOUT   (30) tope duro por peticion, para no colgar la CI.
#   KQ_PROBE_RECOVERY_TIMEOUT  (120) segundos esperando a /ready 200 tras arrancar.
#
# Necesita: docker con el plugin compose, curl, awk.
# Codigos de salida: 0 dentro del umbral · 1 algo no se cumple · 2 uso incorrecto.

set -euo pipefail
IFS=$'\n\t'

[ "$#" -eq 1 ] || {
  printf 'uso: chaos-redis-probe.sh DIRECTORIO_DEL_PAQUETE\n' >&2
  exit 2
}

PKG="$(cd -- "$1" && pwd)"
BASE_URL="${KQ_E2E_BASE_URL:-https://kronoqr.ci.local}"
SUDO="${KQ_E2E_SUDO-sudo}"
MAX_SECONDS="${KQ_PROBE_MAX_SECONDS:-3}"
READY_MAX_SECONDS="${KQ_PROBE_READY_MAX_SECONDS:-3}"
ROUNDS="${KQ_PROBE_ROUNDS:-2}"
REQUEST_TIMEOUT="${KQ_PROBE_REQUEST_TIMEOUT:-30}"
RECOVERY_TIMEOUT="${KQ_PROBE_RECOVERY_TIMEOUT:-120}"

WORK="$(mktemp -d)"
REDIS_STOPPED_BY_US=0
TABLE="${WORK}/table"

# --- utilidades --------------------------------------------------------------

step() {
  printf '\n=== %s\n' "$1"
}

fail() {
  printf '\nFALLO: %s\n' "$*" >&2
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

# Pase lo que pase, la instalacion se queda con Redis arriba.
cleanup() {
  local code=$?
  if [ "${REDIS_STOPPED_BY_US}" = 1 ]; then
    printf '\nRestaurando: arrancando redis de nuevo.\n' >&2
    dc start redis >&2 || printf 'No se pudo arrancar redis: ejecuta docker compose start redis a mano.\n' >&2
  fi
  rm -rf "${WORK}"
  exit "${code}"
}
trap cleanup EXIT

# measure METODO RUTA [CUERPO_JSON] -> "CODIGO SEGUNDOS"
measure() {
  local method="$1" path="$2" data="${3:-}"
  local -a args=(-sS -k -o /dev/null --max-time "${REQUEST_TIMEOUT}" -w '%{http_code} %{time_total}'
    -X "${method}" -H 'Accept: application/json')
  [ -z "${data}" ] || args+=(-H 'Content-Type: application/json' --data "${data}")
  curl "${args[@]}" "${BASE_URL}${path}" 2>/dev/null || true
}

ready_code() {
  curl -sS -k -o /dev/null --max-time 10 -w '%{http_code}' "${BASE_URL}/api/v1/ready" 2>/dev/null || true
}

# Una ronda: anota tres lineas "RONDA ENDPOINT CODIGO SEGUNDOS" en la tabla.
run_round() {
  local label="$1" out
  out="$(measure GET /api/v1/health)"
  printf '%s|GET /api/v1/health|%s\n' "${label}" "${out// /|}" >>"${TABLE}"
  out="$(measure GET /api/v1/ready)"
  printf '%s|GET /api/v1/ready|%s\n' "${label}" "${out// /|}" >>"${TABLE}"
  out="$(measure POST /api/v1/scan/pin '{}')"
  printf '%s|POST /api/v1/scan/pin|%s\n' "${label}" "${out// /|}" >>"${TABLE}"
}

# --- precondiciones ----------------------------------------------------------

step 'Precondiciones: la instalacion esta sana y redis arriba'
[ -f "${PKG}/.env" ] || fail "No hay ${PKG}/.env: pasa el directorio del paquete ya instalado."
dc ps --status running --services | grep -qx redis ||
  fail 'El servicio redis no esta en marcha antes de empezar. Arranca la instalacion (docker compose up -d) y repite.'
[ "$(ready_code)" = 200 ] ||
  fail '/api/v1/ready no responde 200 antes de empezar: la instalacion no esta sana, y esta medida no tendria sentido.'
printf '  ok · /ready 200 y redis en marcha\n'

# --- medida ------------------------------------------------------------------

step 'Referencia con Redis arriba'
run_round 'base'

step 'Parando redis (sin eliminarlo)'
REDIS_STOPPED_BY_US=1
dc stop redis

round=1
while [ "${round}" -le "${ROUNDS}" ]; do
  step "Ronda ${round} de ${ROUNDS} con Redis parado"
  run_round "${round}"
  round=$((round + 1))
done

step 'Arrancando redis y esperando a /ready 200'
dc start redis
REDIS_STOPPED_BY_US=0
deadline=$((SECONDS + RECOVERY_TIMEOUT))
recovered=0
while [ "${SECONDS}" -lt "${deadline}" ]; do
  if [ "$(ready_code)" = 200 ]; then
    recovered=1
    break
  fi
  sleep 2
done

# --- informe -----------------------------------------------------------------

step 'Resultado'
printf '%-6s  %-24s  %-6s  %s\n' 'ronda' 'peticion' 'http' 'segundos'
awk -F'|' '{ printf "%-6s  %-24s  %-6s  %s\n", $1, $2, $3, $4 }' "${TABLE}"

problems=0

if [ "${recovered}" != 1 ]; then
  printf 'FALLO: /ready no vuelve a 200 en %s s tras arrancar redis. Mira docker compose logs redis app.\n' "${RECOVERY_TIMEOUT}" >&2
  problems=$((problems + 1))
fi

# Solo se evalua la ultima ronda: la primera abre el cortacircuitos.
while IFS='|' read -r label endpoint code seconds; do
  [ "${label}" = "${ROUNDS}" ] || continue
  limit="${MAX_SECONDS}"
  case "${endpoint}" in *ready) limit="${READY_MAX_SECONDS}" ;; esac
  if awk -v t="${seconds:-999}" -v m="${limit}" 'BEGIN { exit !(t > m) }'; then
    printf 'FALLO: %s tarda %s s con Redis parado (maximo %s s, ronda %s). Falta el cortacircuitos, o no se aplica en esta ruta.\n' \
      "${endpoint}" "${seconds}" "${limit}" "${label}" >&2
    problems=$((problems + 1))
  fi
  case "${endpoint}" in
  *scan/pin)
    case "${code}" in
    5* | 000)
      printf 'FALLO: el fichaje por PIN responde %s con Redis parado: el fichaje no puede bloquearse por Redis (regla dura 19).\n' "${code}" >&2
      problems=$((problems + 1))
      ;;
    esac
    ;;
  esac
done <"${TABLE}"

if [ "${problems}" -gt 0 ]; then
  fail "${problems} medida(s) fuera de lo esperado (umbral: ${MAX_SECONDS} s, /ready ${READY_MAX_SECONDS} s)."
fi

printf '\nok · la ultima ronda queda dentro de %s s (/ready %s s) y la instalacion vuelve a 200.\n' \
  "${MAX_SECONDS}" "${READY_MAX_SECONDS}"
