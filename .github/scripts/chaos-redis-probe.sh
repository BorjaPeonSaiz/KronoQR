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
#   0. Comprueba que la instalacion esta sana (/ready 200), aprovisiona UN
#      quiosco y unas pocas tarjetas sinteticas con el aprovisionador de la
#      prueba de carga (`load-tests/k6/provision-fixtures.php`, en tamano
#      minimo) y mide la referencia con Redis arriba.
#   1. `docker compose stop redis`.
#   2. Mide DOS rondas de cuatro peticiones: GET /api/v1/health, GET
#      /api/v1/ready y, AUTENTICADAS con el token de un quiosco sintetico (con
#      `scan:write`) y con la `Idempotency-Key` igual al `scan_id` del cuerpo
#      (el contrato lo exige; si no, 400 antes de llegar a nada), dos POST
#      /api/v1/scan: una tarjeta VALIDA de un empleado sintetico (200: escribe
#      un fichaje en el runner, que se tira; una tarjeta distinta por ronda
#      para no caer en el anti-rebote) y una bien formada y firmada pero
#      DESCONOCIDA (rechazo generico 422, RS-03). Asi la peticion atraviesa
#      `auth:sanctum`, `ThrottleScanFailOpen`, la cache, la cola, el caso de
#      uso y la base de datos: sin token se quedaria en un 401 que no toca
#      nada de eso. /scan/pin no se mide: exige un PIN sellado que el
#      aprovisionador no entrega. La primera ronda abre el cortacircuitos y
#      puede ser lenta; la segunda debe ser rapida.
#   3. `docker compose start redis` y espera a que /ready vuelva a 200.
#   4. Imprime la tabla y FALLA si alguna medida de la ultima ronda supera el
#      umbral, o si el fichaje autenticado responde otra cosa que lo esperado
#      (200 la tarjeta valida, 422 la desconocida; nunca 401, 5xx o sin
#      respuesta) en cualquier ronda (regla dura 19: el fichaje no se bloquea).
#
# DEJA LA INSTALACION COMO ESTABA, SALVO UNA COSA: un `trap` arranca `redis` de
# nuevo pase lo que pase y revoca el token y las tarjetas sinteticas
# (`cleanup-after-load.php`); si redis ya estaba parado al empezar, lo avisa y
# sale sin tocar nada. Lo que el aprovisionador no borra —por la regla dura 5—
# son el centro, el departamento y los empleados sinteticos «k6»: esto es solo
# para el runner de la CI, que se tira al terminar.
#
# SOLO CI. Con `sudo` y `KQ_E2E_BASE_URL` apuntando a una instalacion real
# pararia su Redis y sembraria datos: se niega a correr fuera de GitHub Actions.
#
# Uso:
#   chaos-redis-probe.sh DIRECTORIO_DEL_PAQUETE
#
# Variables:
#   KQ_E2E_BASE_URL            (https://kronoqr.ci.local) donde responde el borde.
#   KQ_E2E_SUDO                (sudo) prefijo para docker y el .env. Vacio si ya
#                              se es root.
#   KQ_PROBE_MAX_SECONDS       (3) tope de /health y de los dos fichajes en la ultima ronda.
#   KQ_PROBE_READY_MAX_SECONDS (3) tope de /ready en la ultima ronda.
#   KQ_PROBE_ROUNDS            (2) rondas con Redis parado; se evalua la ultima.
#   KQ_PROBE_REQUEST_TIMEOUT   (30) tope duro por peticion, para no colgar la CI.
#   KQ_PROBE_RECOVERY_TIMEOUT  (120) segundos esperando a /ready 200 tras arrancar.
#
# Necesita: docker con el plugin compose, curl, awk, jq, od, y el repositorio
# (usa `load-tests/k6/`).
# Codigos de salida: 0 dentro del umbral · 1 algo no se cumple · 2 uso incorrecto.

set -euo pipefail
IFS=$'\n\t'

[ "${GITHUB_ACTIONS:-}" = true ] || {
  printf 'chaos-redis-probe.sh para un servicio y siembra datos: solo se ejecuta en la CI (GITHUB_ACTIONS=true). No lo lances contra una instalacion real.\n' >&2
  exit 2
}

[ "$#" -eq 1 ] || {
  printf 'uso: chaos-redis-probe.sh DIRECTORIO_DEL_PAQUETE\n' >&2
  exit 2
}

PKG="$(cd -- "$1" && pwd)"
K6_DIR="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")/../../load-tests/k6" && pwd)"
BASE_URL="${KQ_E2E_BASE_URL:-https://kronoqr.ci.local}"
SUDO="${KQ_E2E_SUDO-sudo}"
MAX_SECONDS="${KQ_PROBE_MAX_SECONDS:-3}"
READY_MAX_SECONDS="${KQ_PROBE_READY_MAX_SECONDS:-3}"
ROUNDS="${KQ_PROBE_ROUNDS:-2}"
REQUEST_TIMEOUT="${KQ_PROBE_REQUEST_TIMEOUT:-30}"
RECOVERY_TIMEOUT="${KQ_PROBE_RECOVERY_TIMEOUT:-120}"

WORK="$(mktemp -d)"
REDIS_STOPPED_BY_US=0
SEEDED=0
DEVICE_TOKEN=""
UNKNOWN_CARD=""
declare -a VALID_CARDS=()
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

# Pase lo que pase, la instalacion se queda con Redis arriba y sin tokens vivos.
cleanup() {
  local code=$?
  if [ "${REDIS_STOPPED_BY_US}" = 1 ]; then
    printf '\nRestaurando: arrancando redis de nuevo.\n' >&2
    dc start redis >&2 || printf 'No se pudo arrancar redis: ejecuta docker compose start redis a mano.\n' >&2
  fi
  if [ "${SEEDED}" = 1 ]; then
    dc exec -T -e APP_ENV=staging -e K6_ACKNOWLEDGE_TEST_DATABASE=yes app sh -c \
      "php artisan tinker --execute=\"include '/tmp/k6/cleanup-after-load.php';\"" >&2 ||
      printf 'No se pudieron revocar los tokens sinteticos: revisa load-tests/k6/cleanup-after-load.php.\n' >&2
    dc exec -T app rm -rf /tmp/k6 >&2 || true
  fi
  rm -rf "${WORK}"
  exit "${code}"
}
trap cleanup EXIT

# UUID v7 (48 bits de milisegundos + aleatorio): la Idempotency-Key y el scan_id.
uuid7() {
  local ms rnd
  ms="$(printf '%012x' "$(date +%s%3N)")"
  rnd="$(od -An -N10 -tx1 /dev/urandom | tr -d ' \n')"
  printf '%s-%s-7%s-%x%s-%s\n' "${ms:0:8}" "${ms:8:4}" "${rnd:0:3}" $((8 + 0x${rnd:3:1} % 4)) "${rnd:4:3}" "${rnd:7:12}"
}

# Aprovisiona un quiosco y tarjetas sinteticas minimas con el aprovisionador de
# la prueba de carga y deja en DEVICE_TOKEN y UNKNOWN_CARD lo que hace falta
# para fichar de verdad (autenticado) sin escribir ningun fichaje.
provision() {
  local tool fixtures
  dc exec -T app sh -c 'mkdir -p /tmp/k6 && rm -f /tmp/k6/k6-fixtures.json'
  for tool in support.php provision-fixtures.php cleanup-after-load.php; do
    dc cp "${K6_DIR}/${tool}" "app:/tmp/k6/${tool}"
  done
  SEEDED=1
  # APP_ENV=staging SOLO en el proceso de tinker: el aprovisionador se niega a
  # correr con app()->isProduction() y la instalacion del paquete es production.
  # La imagen no cachea la configuracion, asi que la variable del proceso basta
  # y la pila instalada no cambia (load-test.yml si cambia el .env de toda la
  # pila, porque mide la carga entera). Esto solo corre en la CI.

  dc exec -T \
    -e APP_ENV=staging -e K6_ACKNOWLEDGE_TEST_DATABASE=yes -e K6_EMPLOYEES=4 -e K6_DEVICES=1 -e K6_INSTANCES=1 \
    -e K6_SCAN_CARDS=1 -e K6_RESEND_CARDS=1 -e K6_BATCH_CARDS=2 -e K6_REJECT_PAYLOADS=1 \
    -e K6_OUT_OF_ORDER_PAYLOADS=1 -e K6_HISTORY_DAYS=0 -e K6_HISTORY_EMPLOYEES=0 app sh -c \
    "php artisan tinker --execute=\"include '/tmp/k6/provision-fixtures.php';\"" >"${WORK}/provision.log" 2>&1 || true
  # Tinker sale 0 aunque el include falle: el exito se lee del artefacto.
  dc exec -T app test -f /tmp/k6/k6-fixtures.json ||
    fail "el aprovisionamiento no dejo fixtures. Salida: $(tail -n 15 "${WORK}/provision.log")"
  fixtures="$(dc exec -T app cat /tmp/k6/k6-fixtures.json)"
  DEVICE_TOKEN="$(jq -r '.device_tokens[0] // empty' <<<"${fixtures}")"
  UNKNOWN_CARD="$(jq -r '.unknown_payloads[0] // empty' <<<"${fixtures}")"
  mapfile -t VALID_CARDS < <(jq -r '.payloads[]? // empty' <<<"${fixtures}")
  [ -n "${DEVICE_TOKEN}" ] && [ -n "${UNKNOWN_CARD}" ] && [ "${#VALID_CARDS[@]}" -gt 0 ] ||
    fail 'los fixtures no traen token de quiosco, tarjeta desconocida o tarjetas validas.'
}

# measure METODO RUTA [CUERPO_JSON] [SCAN_ID] -> "CODIGO SEGUNDOS"
# Con SCAN_ID: token del quiosco sintetico y la Idempotency-Key, que el contrato
# exige IGUAL al `scan_id` del cuerpo (si no, 400 antes de llegar a nada).
measure() {
  local method="$1" path="$2" data="${3:-}" scan_id="${4:-}"
  local -a args=(-sS -k -o /dev/null --max-time "${REQUEST_TIMEOUT}" -w '%{http_code} %{time_total}'
    -X "${method}" -H 'Accept: application/json')
  [ -z "${data}" ] || args+=(-H 'Content-Type: application/json' --data "${data}")
  [ -z "${scan_id}" ] || args+=(-H "Authorization: Bearer ${DEVICE_TOKEN}" -H "Idempotency-Key: ${scan_id}")
  curl "${args[@]}" "${BASE_URL}${path}" 2>/dev/null || true
}

ready_code() {
  curl -sS -k -o /dev/null --max-time 10 -w '%{http_code}' "${BASE_URL}/api/v1/ready" 2>/dev/null || true
}

# scan_body TARJETA -> cuerpo JSON de un fichaje; deja el scan_id en SCAN_ID.
scan_body() {
  SCAN_ID="$(uuid7)"
  printf '{"scan_id":"%s","occurred_at":"%s","qr_payload":"%s","intent":"auto"}' \
    "${SCAN_ID}" "$(date -u +%Y-%m-%dT%H:%M:%S.000000Z)" "$1"
}

# Una ronda: anota cuatro lineas "RONDA|ENDPOINT|CODIGO|SEGUNDOS" en la tabla.
# INDICE elige la tarjeta valida de la ronda (una distinta por ronda: el
# anti-rebote de la instalacion ignoraria un segundo pase de la misma tarjeta).
run_round() {
  local label="$1" index="$2" out body card
  out="$(measure GET /api/v1/health)"
  printf '%s|GET /api/v1/health|%s\n' "${label}" "${out// /|}" >>"${TABLE}"
  out="$(measure GET /api/v1/ready)"
  printf '%s|GET /api/v1/ready|%s\n' "${label}" "${out// /|}" >>"${TABLE}"
  card="${VALID_CARDS[$((index % ${#VALID_CARDS[@]}))]}"
  body="$(scan_body "${card}")"
  out="$(measure POST /api/v1/scan "${body}" "${SCAN_ID}")"
  printf '%s|POST /api/v1/scan (valida)|%s\n' "${label}" "${out// /|}" >>"${TABLE}"
  body="$(scan_body "${UNKNOWN_CARD}")"
  out="$(measure POST /api/v1/scan "${body}" "${SCAN_ID}")"
  printf '%s|POST /api/v1/scan (desconocida)|%s\n' "${label}" "${out// /|}" >>"${TABLE}"
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

step 'Aprovisionando un quiosco sintetico'
provision
printf '  ok · token de quiosco y tarjeta desconocida listos\n'

step 'Referencia con Redis arriba'
run_round 'base' 0

step 'Parando redis (sin eliminarlo)'
REDIS_STOPPED_BY_US=1
dc stop redis

round=1
while [ "${round}" -le "${ROUNDS}" ]; do
  step "Ronda ${round} de ${ROUNDS} con Redis parado"
  run_round "${round}" "${round}"
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

# El fichaje autenticado tiene que dar lo esperado en TODAS las rondas, la de
# referencia incluida: 200 la tarjeta valida y 422 la desconocida. Un 400 o un
# 401 es que la medida no atraviesa lo que dice medir; un 5xx o 000 es un
# fichaje bloqueado (regla dura 19).
while IFS='|' read -r label endpoint code _; do
  expected=""
  case "${endpoint}" in
  *'(valida)') expected=200 ;;
  *'(desconocida)') expected=422 ;;
  esac
  [ -n "${expected}" ] || continue
  if [ "${code}" != "${expected}" ]; then
    printf 'FALLO: %s responde %s en la ronda %s: se esperaba %s. Un 400 o 401 es que la medida no llega al fichaje; un 5xx o 000, que el fichaje se bloquea con Redis parado (regla dura 19).\n' \
      "${endpoint}" "${code}" "${label}" "${expected}" >&2
    problems=$((problems + 1))
  fi
done <"${TABLE}"

# En el tiempo solo se evalua la ultima ronda: la primera abre el cortacircuitos.
while IFS='|' read -r label endpoint _ seconds; do
  [ "${label}" = "${ROUNDS}" ] || continue
  limit="${MAX_SECONDS}"
  case "${endpoint}" in *ready) limit="${READY_MAX_SECONDS}" ;; esac
  if awk -v t="${seconds:-999}" -v m="${limit}" 'BEGIN { exit !(t > m) }'; then
    printf 'FALLO: %s tarda %s s con Redis parado (maximo %s s, ronda %s). Falta el cortacircuitos, o no se aplica en esta ruta.\n' \
      "${endpoint}" "${seconds}" "${limit}" "${label}" >&2
    problems=$((problems + 1))
  fi
done <"${TABLE}"

if [ "${problems}" -gt 0 ]; then
  fail "${problems} medida(s) fuera de lo esperado (umbral: ${MAX_SECONDS} s, /ready ${READY_MAX_SECONDS} s)."
fi

printf '\nok · la ultima ronda queda dentro de %s s (/ready %s s), el fichaje responde lo esperado (200 la tarjeta valida, 422 la desconocida) y la instalacion vuelve a 200.\n' \
  "${MAX_SECONDS}" "${READY_MAX_SECONDS}"
