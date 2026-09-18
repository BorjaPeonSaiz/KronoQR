#!/usr/bin/env bash
#
# Prueba de carga del pico del cambio de turno (RNF-P-06, RNF-P-02, RQ-08).
#
# UNA INSTANCIA DE K6 ES UN ORIGEN (una IP) y el pico se compone sumando
# origenes, como en un hotel de verdad. El presupuesto por origen sale del cubo
# con fuga del borde —un permiso cada 100 ms con rafaga de 50, no una ventana
# fija de 600 por minuto—, asi que se deja un 20 % de margen: ~8 peticiones/s
# por instancia. Con INSTANCES=10 y SCAN_RATE=6 salen 60 fichajes validos/s.
#
# Este script lanza las instancias, agrega las muestras crudas de todas y,
# DESPUES, comprueba que lo que quedo escrito es correcto. Una medida de
# latencia sobre un registro roto no vale nada. Al terminar —tambien si se
# interrumpe— apaga las credenciales que emitio.
#
# Uso:
#   load-tests/k6/run.sh
#   INSTANCES=2 DURATION=30s load-tests/k6/run.sh
#   K6_COMPOSE_ARGS="-f /opt/kronoqr/compose.yaml" load-tests/k6/run.sh
#
# Requisitos: la pila levantada (`make up`), `QR_SIGNING_KEY_CURRENT` en el
# `.env` y Node en el anfitrion para el agregado.
#
# Codigos de salida:
#   0  veredicto verde
#   1  veredicto rojo (algun requisito fuera de presupuesto o registro incorrecto)
#   2  la medida no es fiable (no hubo muestras, algo no se pudo leer, o algun
#      requisito se quedo sin datos suficientes para juzgarlo)
#
# JAMAS CONTRA PRODUCCION: crea empleados, credenciales y quioscos sinteticos.
# El aprovisionamiento lo rechaza por su cuenta y ademas exige
# K6_ACKNOWLEDGE_TEST_DATABASE=yes, que este script pone; esto es el recordatorio.

set -Eeuo pipefail
IFS=$'\n\t'

INSTANCES="${INSTANCES:-10}"
DURATION="${DURATION:-120s}"
DEVICES_PER_INSTANCE="${DEVICES_PER_INSTANCE:-8}"
SCAN_RATE="${SCAN_RATE:-6}"
NETWORK="${NETWORK:-kronoqr-app}"
BASE_URL="${BASE_URL:-https://nginx:8443}"

# Version Y digest: la etiqueta se puede reescribir en el registro y entonces
# dos pasadas «con la misma imagen» miden con generadores distintos. La version
# viaja al `summary.json` para que la linea base diga con que se tomo.
K6_IMAGE="${K6_IMAGE:-grafana/k6:2.2.0@sha256:9bd01d6941fca969cb61bb57d2da5ee9b385fe2aa8881df3798c196564d6ace6}"

K6_APP_SERVICE="${K6_APP_SERVICE:-app}"
K6_EDGE_SERVICE="${K6_EDGE_SERVICE:-nginx}"
# Quien puede leer `/metrics`: esta restringido por METRICS_ALLOW_CIDR y el
# unico contenedor dentro de ese rango es el que lo raspa.
K6_METRICS_SERVICE="${K6_METRICS_SERVICE:-prometheus}"
K6_LOG_SERVICES="${K6_LOG_SERVICES:-app horizon scheduler nginx}"

# Consentimiento explicito de que esto escribe en la base a la que apunte el
# contenedor. Lo lee `support.php` y sin el no se aprovisiona nada. Se puede
# poner a cualquier otra cosa para abortar a proposito.
K6_ACKNOWLEDGE_TEST_DATABASE="${K6_ACKNOWLEDGE_TEST_DATABASE:-yes}"

K6_HISTORY_DAYS="${K6_HISTORY_DAYS:-365}"
K6_HISTORY_EMPLOYEES="${K6_HISTORY_EMPLOYEES:-200}"
K6_REJECT_PAYLOADS="${K6_REJECT_PAYLOADS:-200}"

# El certificado de la pila de desarrollo y el del paquete recien instalado son
# autofirmados. Contra un entorno de pruebas con certificado real se lanza con
# K6_INSECURE_TLS=0 y k6 valida la cadena como cualquier cliente.
K6_INSECURE_TLS="${K6_INSECURE_TLS:-1}"

# Depuracion: conserva el fichero de fixtures, que lleva tarjetas firmadas y
# sesiones vivas. Fuera de una depuracion no se usa.
K6_KEEP_FIXTURES="${K6_KEEP_FIXTURES:-0}"

# Contra que se juzgan RNF-P-02 y RNF-P-06 (decision 19 de la ficha 3.6):
#
#   threshold  contra el requisito. Es el de serie y el que vale en hardware de
#              referencia, que es donde el umbral significa algo.
#   baseline   contra `baseline.json`, la pasada anterior de la MISMA maquina.
#              Es lo que usa el workflow: en el runner de GitHub el servidor, la
#              base de datos y los once generadores comparten 4 vCPU, y a 60
#              fichajes/s ofrecidos el servidor sostiene 29 tramos/s con un p95
#              de 26 s. Ahi el umbral solo puede dar un rojo permanente que se
#              aprende a ignorar; lo que si se puede vigilar es la REGRESION.
K6_LATENCY_VERDICT="${K6_LATENCY_VERDICT:-threshold}"

script_dir="$(cd "$(dirname "$0")" && pwd)"
root="$(cd "${script_dir}/../.." && pwd)"

# Docker Desktop en Windows quiere la ruta con letra de unidad; en Linux y macOS
# `pwd -W` no existe y se queda la ruta normal.
root_mount="$(cd "${root}" && { pwd -W 2>/dev/null || pwd; })"

results="${script_dir}/.results"
fixtures="${script_dir}/.fixtures"

# Las mismas rutas tal y como las entiende Docker. `docker compose` se invoca con
# MSYS_NO_PATHCONV —sin el, Git Bash convierte `app:/tmp/...` en una ruta de
# Windows y `cp` no encuentra el destino—, y eso deja tambien sin convertir las
# rutas del anfitrion: hay que darselas ya convertidas. En Linux y macOS
# `root_mount` es `root` y esto no cambia nada.
script_mount="${root_mount}/load-tests/k6"

# El compose contra el que se mide. Por omision, el de desarrollo; el workflow de
# carga pasa los del PAQUETE INSTALADO, que es la pila que usa el cliente y la
# unica cuya cifra significa algo.
K6_COMPOSE_ARGS="${K6_COMPOSE_ARGS:---env-file ${root_mount}/.env -f ${root_mount}/infra/compose.dev.yaml}"

# `IFS` esta en salto y tabulador para el resto del script, asi que la division
# por espacios hay que pedirla: sin este `IFS=' '` los argumentos del compose se
# quedarian todos en el primer campo.
compose_args=()
IFS=' ' read -r -a compose_args <<<"${K6_COMPOSE_ARGS}"

log_services=()
IFS=' ' read -r -a log_services <<<"${K6_LOG_SERVICES}"

log() { printf '[k6] %s\n' "$1"; }

verdict=0

# Todo lo que no sea «verde» o «rojo» es «no fiable». Sin esta normalizacion, un
# `node` que falta sale con 127 y quien lea el codigo de salida se inventara que
# significa.
normalise_exit() {
  case "$1" in
  0 | 1) echo "$1" ;;
  *) echo 2 ;;
  esac
}

fail_unreliable() {
  log "MEDIDA NO FIABLE: $1"
  exit 2
}

# MSYS_NO_PATHCONV: Git Bash convierte `app:/tmp/...` en una ruta de Windows y
# `docker compose cp` recibe un destino que no existe.
compose() { MSYS_NO_PATHCONV=1 docker compose "${compose_args[@]}" "$@"; }

tinker() {
  local script="$1"
  shift

  compose exec -T "$@" "${K6_APP_SERVICE}" sh -c \
    "php artisan tinker --execute=\"include '/tmp/k6/${script}';\""
}

seconds_of() {
  case "$1" in
  *ms) echo $((${1%ms} / 1000)) ;;
  *s) echo "${1%s}" ;;
  *m) echo $((${1%m} * 60)) ;;
  *h) echo $((${1%h} * 3600)) ;;
  *) echo "$1" ;;
  esac
}

# --- Cierre de la pasada, pase lo que pase -----------------------------------

run_id="k6-$$"

# Se ejecuta tambien con Ctrl-C y con un fallo a mitad, que es cuando de verdad
# hace falta: lo que queda encendido si nadie limpia son TARJETAS Y SESIONES
# VIVAS con las que se puede fichar y leer datos de personas.
# ShellCheck no sigue la invocacion indirecta del `trap` de mas abajo y la
# marcaria como funcion muerta (SC2329).
# shellcheck disable=SC2329
close_out() {
  local leftovers

  leftovers="$(docker ps -q --filter "label=kronoqr-k6-run=${run_id}" 2>/dev/null || true)"

  if [ -n "${leftovers}" ]; then
    log "Cierre: parando contenedores de k6 que seguian vivos."
    # shellcheck disable=SC2086
    docker rm -f ${leftovers} >/dev/null 2>&1 || true
  fi

  if compose exec -T "${K6_APP_SERVICE}" test -f /tmp/k6/cleanup-after-load.php >/dev/null 2>&1; then
    tinker cleanup-after-load.php -e K6_ACKNOWLEDGE_TEST_DATABASE="${K6_ACKNOWLEDGE_TEST_DATABASE}" ||
      log "Cierre: [aviso] el apagado de credenciales fallo; revisa la salida. El veredicto no cambia."
  fi

  if [ "${K6_KEEP_FIXTURES}" = "1" ]; then
    log "Cierre: se conservan los fixtures en ${fixtures} (K6_KEEP_FIXTURES=1)."
  else
    rm -rf "${fixtures}"
  fi
}

trap close_out EXIT INT TERM

# --- Geometria de la pasada --------------------------------------------------

duration_seconds="$(seconds_of "${DURATION}")"

log "Pila: docker compose ${K6_COMPOSE_ARGS}"

compose exec -T "${K6_APP_SERVICE}" sh -c \
  'mkdir -p /tmp/k6 && rm -f /tmp/k6/k6-fixtures.json /tmp/k6/verify-ok.json /tmp/k6/summary.json' ||
  fail_unreliable "no se puede ejecutar nada en el servicio '${K6_APP_SERVICE}'. ¿Esta levantada la pila?"

for tool in support.php provision-fixtures.php verify-after-load.php cleanup-after-load.php; do
  compose cp "${script_mount}/${tool}" "${K6_APP_SERVICE}:/tmp/k6/${tool}" ||
    fail_unreliable "no se pudo copiar ${tool} al contenedor."
done

# LA VENTANA DEL ANTI-REBOTE SE PREGUNTA, NO SE SUPONE. Es configuracion de la
# instalacion (RF-AT-06, regla dura 13) y de ella depende cuantas tarjetas hacen
# falta para que nadie repita dentro de la ventana. Con un 60 escrito a mano,
# una instalacion con 120 s mediria sobre todo anti-rebote.
debounce_query="echo app('App\\Modules\\Shared\\Application\\Port\\OperationalSettingsProvider')->forSite((int) DB::table('sites')->orderBy('id')->value('id'))->debounceSeconds;"
debounce_seconds="$(compose exec -T "${K6_APP_SERVICE}" php artisan tinker --execute="${debounce_query}" 2>/dev/null | tr -cd '0-9')" || debounce_seconds=""

if [ -z "${debounce_seconds}" ] || [ "${debounce_seconds}" -le 0 ]; then
  # Lo normal es que sea una instalacion recien hecha: todavia no hay centro al
  # que preguntarle, y el aprovisionamiento lo creara enseguida. Lo unico que
  # depende de este numero es el TAMANO de las rebanadas; el valor real viaja
  # despues en los fixtures y es el que usa el veredicto.
  log "AVISO: no se pudo leer la ventana anti-rebote de la instalacion (¿sin centro todavia?);"
  log "       las rebanadas se dimensionan con 60 s."
  debounce_seconds=60
fi

# Un 10 % de margen sobre la ventana: con `SCAN_RATE × (ventana + margen)`
# tarjetas, una vuelta completa de la rebanada tarda mas que el anti-rebote y
# ninguna respuesta sale `debounced` por culpa del guion.
scan_cards=$((SCAN_RATE * (debounce_seconds + debounce_seconds / 10 + 1)))
resend_cards=60
# 25 pares por lote y un lote por minuto, con dos minutos de colchon: asi una
# pasada larga no vuelve a la misma tarjeta dentro de la ventana. El suelo de
# cuatro ventanas es para que DOS PASADAS SEGUIDAS no empiecen por las mismas
# tarjetas —el guion desplaza el origen con el minuto de arranque—: los
# `occurred_at` de un lote son de los diez minutos anteriores y repetir tarjeta
# entre pasadas caeria dentro del anti-rebote.
batch_windows=$((duration_seconds / 60 + 2))

if [ "${batch_windows}" -lt 4 ]; then
  batch_windows=4
fi

batch_cards=$((batch_windows * 25))
cards_per_instance=$((scan_cards + resend_cards + batch_cards))

employees=$((INSTANCES * cards_per_instance))
devices=$((INSTANCES * DEVICES_PER_INSTANCE))
offered_rate=$((INSTANCES * SCAN_RATE))

yesterday="$(date -u -d '1 day ago' +%F 2>/dev/null || date -u -v-1d +%F)"
today="$(date -u +%F)"
started_at="$(date -u +%Y-%m-%dT%H:%M:%SZ)"
# En la CI los pone el workflow, que sabe el commit real del paquete que se
# instalo y la etiqueta del runner; en local salen de git y del anfitrion. Los
# dos viajan al `summary.json`, que es lo que convierte una pasada en linea base
# comparable y no en un numero suelto.
git_sha="${K6_GIT_SHA:-$(git -C "${root}" rev-parse HEAD 2>/dev/null || echo desconocido)}"
runner="${K6_RUNNER:-$(hostname 2>/dev/null || uname -n)}"

log "Carga: ${INSTANCES} instancias x ${SCAN_RATE} fichajes/s = ${offered_rate}/s durante ${DURATION}."
log "Anti-rebote de la instalacion: ${debounce_seconds} s -> ${cards_per_instance} tarjetas por instancia."
log "Aprovisionamiento: ${employees} empleados, ${devices} quioscos, ${K6_HISTORY_DAYS} dias de historico."

# --- Aprovisionamiento -------------------------------------------------------

# Tinker devuelve 0 aunque el include lance una excepcion, asi que el exito no se
# puede leer del codigo de salida: se exige el ARTEFACTO.
tinker provision-fixtures.php \
  -e K6_ACKNOWLEDGE_TEST_DATABASE="${K6_ACKNOWLEDGE_TEST_DATABASE}" \
  -e K6_EMPLOYEES="${employees}" \
  -e K6_DEVICES="${devices}" \
  -e K6_INSTANCES="${INSTANCES}" \
  -e K6_SCAN_CARDS="${scan_cards}" \
  -e K6_RESEND_CARDS="${resend_cards}" \
  -e K6_BATCH_CARDS="${batch_cards}" \
  -e K6_HISTORY_DAYS="${K6_HISTORY_DAYS}" \
  -e K6_HISTORY_EMPLOYEES="${K6_HISTORY_EMPLOYEES}" \
  -e K6_REJECT_PAYLOADS="${K6_REJECT_PAYLOADS}" || true

compose exec -T "${K6_APP_SERVICE}" test -f /tmp/k6/k6-fixtures.json ||
  fail_unreliable "el aprovisionamiento no dejo fixtures; revisa la salida de arriba."

# Los fixtures llevan tarjetas firmadas y tokens vivos: van a su propio
# directorio, con permisos de solo el dueno, y NO a `.results/`, que el workflow
# sube como artefacto.
rm -rf "${fixtures}"
mkdir -p "${fixtures}"
chmod 700 "${fixtures}"
compose cp "${K6_APP_SERVICE}:/tmp/k6/k6-fixtures.json" "${script_mount}/.fixtures/k6-fixtures.json" ||
  fail_unreliable "no se pudieron recoger los fixtures del contenedor."
chmod 600 "${fixtures}/k6-fixtures.json"

rejection_floor_ms="$(node -e \
  "process.stdout.write(String(require('${script_dir}/.fixtures/k6-fixtures.json').rejection_floor_ms ?? 25))" \
  2>/dev/null || echo 25)"

# --- Series del servidor antes de la carga (instrumentacion de la 3.1) -------

server_metrics_before="${fixtures}/metrics-before.txt"
server_metrics_after="${fixtures}/metrics-after.txt"
server_metrics_json="${fixtures}/server-metrics.json"

scrape_metrics() {
  compose exec -T "${K6_METRICS_SERVICE}" wget -q -O - http://nginx:8080/metrics 2>/dev/null
}

if scrape_metrics >"${server_metrics_before}"; then
  log "Series del servidor leidas de /metrics a traves de '${K6_METRICS_SERVICE}'."
else
  log "AVISO: /metrics no es accesible desde '${K6_METRICS_SERVICE}' (METRICS_ALLOW_CIDR). Se seguira sin el delta."
  : >"${server_metrics_before}"
fi

# --- La carga ----------------------------------------------------------------

rm -rf "${results}"
mkdir -p "${results}"

# El uid del invocante: `grafana/k6` corre como 12345 y en un runner de Linux no
# puede escribir el CSV en un directorio creado por otro usuario. El sintoma sin
# esto es una pasada entera sin muestras.
k6_user="$(id -u 2>/dev/null):$(id -g 2>/dev/null)" || k6_user=""

k6_instance() {
  local name="$1" instance="$2" instances="$3" role="$4"
  local user_flag=()

  if [ -n "${k6_user}" ]; then
    user_flag=(--user "${k6_user}")
  fi

  MSYS_NO_PATHCONV=1 docker run --rm --network "${NETWORK}" \
    --label "kronoqr-k6-run=${run_id}" \
    "${user_flag[@]}" \
    -v "${root_mount}/load-tests/k6:/scripts:ro" \
    -v "${root_mount}/load-tests/k6/.fixtures:/fixtures:ro" \
    -v "${root_mount}/load-tests/k6/.results:/results" \
    -e DURATION="${DURATION}" \
    -e SCAN_RATE="${SCAN_RATE}" \
    -e INSTANCE="${instance}" \
    -e INSTANCES="${instances}" \
    -e ROLE="${role}" \
    -e BASE_URL="${BASE_URL}" \
    -e K6_INSECURE_TLS="${K6_INSECURE_TLS}" \
    "${K6_IMAGE}" run --quiet --out "csv=/results/instance-${name}.csv" /scripts/scan-peak.js \
    >"${results}/instance-${name}.log" 2>&1
}

pids=()
launched=()

for i in $(seq 0 $((INSTANCES - 1))); do
  k6_instance "${i}" "${i}" "${INSTANCES}" kiosk &
  pids+=("$!")
  launched+=("${i}")
done

# El panel va en su propio contenedor —otra IP, zona `api` del borde— para que su
# lectura no consuma la cuota del fichaje y para poder medir el fichaje CON esa
# lectura en curso, que es lo que interesa (ADR-010, ADR-027).
k6_instance panel 0 1 panel &
pids+=("$!")
launched+=("panel")

log "Instancias lanzadas (${INSTANCES} quiosco + 1 panel); esperando a que terminen..."

# Los umbrales por instancia pueden fallar sin invalidar la medida: el veredicto
# real lo da el agregado sobre las muestras de todas, y los `check()` viajan en
# el CSV. Por eso se ignora el codigo de salida de k6... y por eso hay que mirar
# aparte si la instancia llego a ESCRIBIR algo: «k6 no pudo arrancar» y «el
# servidor no respondio» dan los dos cero muestras y no son lo mismo.
for pid in "${pids[@]}"; do
  wait "${pid}" || true
done

missing=()

for name in "${launched[@]}"; do
  if [ ! -s "${results}/instance-${name}.csv" ]; then
    missing+=("${name}")
  fi
done

if [ "${#missing[@]}" -gt 0 ]; then
  log "Instancias sin CSV: ${missing[*]}"

  for name in "${missing[@]}"; do
    log "  --- instancia ${name}, ultimas lineas de su registro ---"
    tail -n 5 "${results}/instance-${name}.log" 2>/dev/null | sed 's/^/      /' || true
  done

  fail_unreliable "al menos una instancia de k6 no escribio su CSV: no arranco o no pudo escribir en ${results}."
fi

# --- Series del servidor despues de la carga ---------------------------------

if [ -s "${server_metrics_before}" ] && scrape_metrics >"${server_metrics_after}"; then
  awk '
    function total(file, prefix,   line, value, sum) { return 0 }
    BEGIN { phase = 1 }
    FNR == 1 { phase++ }
    /^scan_processing_duration_seconds_count/ { acc["scan_count", phase] += $2 }
    /^scan_processing_duration_seconds_sum/   { acc["scan_sum", phase]   += $2 }
    /^http_request_duration_seconds_count/    { acc["http_count", phase] += $2 }
    /^http_request_duration_seconds_sum/      { acc["http_sum", phase]   += $2 }
    /^db_query_duration_seconds_count/        { acc["db_count", phase]   += $2 }
    /^db_query_duration_seconds_sum/          { acc["db_sum", phase]     += $2 }
    /^queue_jobs_pending/                     { acc["queue", phase]      += $2 }
    END {
      printf "{\n"
      printf "  \"scan_processing_duration_seconds\": {\"count\": %d, \"seconds\": %.3f},\n",
        acc["scan_count", 3] - acc["scan_count", 2], acc["scan_sum", 3] - acc["scan_sum", 2]
      printf "  \"http_request_duration_seconds\": {\"count\": %d, \"seconds\": %.3f},\n",
        acc["http_count", 3] - acc["http_count", 2], acc["http_sum", 3] - acc["http_sum", 2]
      printf "  \"db_query_duration_seconds\": {\"count\": %d, \"seconds\": %.3f},\n",
        acc["db_count", 3] - acc["db_count", 2], acc["db_sum", 3] - acc["db_sum", 2]
      printf "  \"queue_jobs_pending\": {\"before\": %d, \"after\": %d}\n",
        acc["queue", 2], acc["queue", 3]
      printf "}\n"
    }
  ' "${server_metrics_before}" "${server_metrics_after}" >"${server_metrics_json}" || : >"${server_metrics_json}"
else
  : >"${server_metrics_json}"
fi

# --- Que capa freno, y cuanto ------------------------------------------------

# Sin esto, un rojo por 429 no dice si lo freno el cubo con fuga de peticiones o
# el limite de conexiones simultaneas, que son dos diagnosticos distintos y dos
# ajustes distintos.
edge_json="${results}/edge-limits.json"
edge_log="${fixtures}/edge.log"

if compose logs --since "${started_at}" "${K6_EDGE_SERVICE}" >"${edge_log}" 2>&1; then
  limiting_requests="$(grep -ac 'limiting requests' "${edge_log}" || true)"
  limiting_connections="$(grep -ac 'limiting connections' "${edge_log}" || true)"

  printf '{"service": "%s", "limiting_requests": %d, "limiting_connections": %d}\n' \
    "${K6_EDGE_SERVICE}" "${limiting_requests:-0}" "${limiting_connections:-0}" >"${edge_json}"

  log "Borde: ${limiting_requests:-0} «limiting requests» y ${limiting_connections:-0} «limiting connections»."
else
  log "AVISO: no se pudieron leer los registros de '${K6_EDGE_SERVICE}'."
  : >"${edge_json}"
fi

# --- Agregado y veredicto ----------------------------------------------------

aggregate_args=(
  "${results}"
  "--duration=${duration_seconds}"
  "--instances=${INSTANCES}"
  "--scan-rate=${SCAN_RATE}"
  "--offered-rate=${offered_rate}"
  "--rejection-floor-ms=${rejection_floor_ms}"
  "--debounce-seconds=${debounce_seconds}"
  "--git-sha=${git_sha}"
  "--runner=${runner}"
  "--k6-image=${K6_IMAGE}"
  "--latency-verdict=${K6_LATENCY_VERDICT}"
)

k6_version="$(MSYS_NO_PATHCONV=1 docker run --rm "${K6_IMAGE}" version 2>/dev/null | head -n 1 || true)"

if [ -n "${k6_version}" ]; then
  aggregate_args+=("--k6-version=${k6_version}")
fi

if [ -s "${edge_json}" ]; then
  aggregate_args+=("--edge-limits=${edge_json}")
fi

if [ -s "${server_metrics_json}" ]; then
  aggregate_args+=("--server-metrics=${server_metrics_json}")
fi

if [ -f "${script_dir}/baseline.json" ]; then
  aggregate_args+=("--baseline=${script_dir}/baseline.json")
elif [ "${K6_LATENCY_VERDICT}" = "baseline" ]; then
  # No se inventa un veredicto: el agregado lo marcara «no evaluable» y saldra
  # con 2. Esto es solo para que quien lo vea sepa que hacer con el.
  log "AVISO: K6_LATENCY_VERDICT=baseline y no hay ${script_dir}/baseline.json."
  log "       Versiona el summary.json de esta pasada como baseline.json y vuelve a medir."
fi

aggregate_exit=0
node "${script_dir}/aggregate.js" "${aggregate_args[@]}" || aggregate_exit=$?
aggregate_exit="$(normalise_exit "${aggregate_exit}")"

if [ "${aggregate_exit}" -eq 2 ] && [ ! -f "${results}/summary.json" ]; then
  fail_unreliable "el agregado no encontro muestras utiles."
fi

verdict="${aggregate_exit}"

# --- Verificacion posterior --------------------------------------------------

# QUE K6 HAYA TERMINADO NO SIGNIFICA QUE EL SERVIDOR HAYA TERMINADO. Con la pila
# saturada, las peticiones cuyo cliente se canso de esperar siguen escribiendo
# durante segundos. `attendance:reconcile` lanzado en ese hueco lee la proyeccion
# y los tramos en consultas distintas, ve divergencias que no existen y las
# «corrige» con el estado viejo. Aqui se espera POR CONDICION —que el numero de
# escaneos deje de crecer— y no por un plazo fijo; los dos segundos son el
# intervalo del sondeo, no la sincronizacion.
wait_for_write_path_to_drain() {
  local previous="" current="" stable=0 attempt=0

  while [ "${attempt}" -lt 30 ]; do
    current="$(compose exec -T "${K6_APP_SERVICE}" \
      php artisan tinker --execute="echo DB::table('scan_events')->count();" 2>/dev/null |
      tr -cd '0-9')" || current=""

    if [ -n "${current}" ] && [ "${current}" = "${previous}" ]; then
      stable=$((stable + 1))
    else
      stable=0
    fi

    if [ "${stable}" -ge 2 ]; then
      log "El camino de escritura esta en reposo (${current} escaneos registrados)."
      return 0
    fi

    previous="${current}"
    attempt=$((attempt + 1))
    sleep 2
  done

  log "AVISO: el camino de escritura seguia activo tras 60 s; la reconciliacion puede ver escrituras a medias."
}

log "Esperando a que el servidor termine de escribir lo que ya tenia aceptado..."
wait_for_write_path_to_drain

log "Reconciliando la proyeccion de ${yesterday} a ${today} (RN-06, regla dura 7)..."

if ! compose exec -T "${K6_APP_SERVICE}" php artisan attendance:reconcile --from="${yesterday}" --to="${today}"; then
  log "FALLA: attendance:reconcile termino con error."
  verdict=1
fi

# RS-07: la cadena de `audit_log` es solo-append y encadenada por hash, y la
# carga escribe cientos de asientos en paralelo. Si la concurrencia rompiera el
# encadenamiento, el registro legal dejaria de ser verificable — y eso no se ve
# en ninguna latencia. El comando no admite rango (solo `--chunk`), asi que
# recorre la cadena entera.
log "Verificando la cadena de audit_log (RS-07)..."

if ! compose exec -T "${K6_APP_SERVICE}" php artisan compliance:verify-audit-chain; then
  log "FALLA RS-07: la cadena de audit_log no verifica tras la carga."
  verdict=1
fi

compose cp "${script_mount}/.results/summary.json" "${K6_APP_SERVICE}:/tmp/k6/summary.json" || true

if ! tinker verify-after-load.php -e K6_ACKNOWLEDGE_TEST_DATABASE="${K6_ACKNOWLEDGE_TEST_DATABASE}" ||
  ! compose exec -T "${K6_APP_SERVICE}" test -f /tmp/k6/verify-ok.json; then
  log "FALLA: la verificacion posterior encontro problemas (ver la salida de arriba)."
  verdict=1
fi

# --- Regla dura 21: ningun nombre de la carga en los logs ni en error_events --

# Se buscan las DOS formas en las que un nombre puede escaparse: el completo que
# guarda `employees` («Carga k6 0001») y el abreviado con el que la API contesta
# al quiosco («Carga K.»). El identificador que si puede salir es
# `employee_uuid`.
#
# LEER Y FILTRAR SON DOS PASOS, y no una tuberia. Con `pipefail`, un
# `compose logs` que falle deja la tuberia en no-cero, `grep` no encuentra nada y
# el resultado se lee como «todo bien»: exactamente el modo en que una
# comprobacion de privacidad deja de comprobar sin avisar.
name_pattern='Carga k6 [0-9]{4}|Carga K\.'
hits_file="${fixtures}/pii-hits.txt"
: >"${hits_file}"
log_read_failed=0

log "Buscando nombres de la carga en los registros de: ${K6_LOG_SERVICES}..."

for service in "${log_services[@]}"; do
  service_log="${fixtures}/logs-${service}.txt"

  if ! compose logs --since "${started_at}" "${service}" >"${service_log}" 2>&1; then
    log "AVISO: no se pudieron leer los registros de '${service}'."
    log_read_failed=1
    continue
  fi

  grep -aE "${name_pattern}" "${service_log}" | sed "s/^/${service}: /" >>"${hits_file}" || true
done

pii_hits="$(wc -l <"${hits_file}" | tr -d ' ')"

# `error_events` viaja al fabricante dentro del paquete de diagnostico: si lleva
# un nombre, se ha filtrado fuera de la instalacion del cliente (regla dura 21).
error_query="echo DB::table('error_events')->where(function (\$q) { \$q->where('message', 'like', '%Carga k6 %')->orWhere('message', 'like', '%Carga K.%')->orWhereRaw(\"context::text like '%Carga k6 %'\"); })->count();"
error_event_hits="$(compose exec -T "${K6_APP_SERVICE}" php artisan tinker --execute="${error_query}" 2>/dev/null | tr -cd '0-9')" || error_event_hits=""

printf '{"log_matches": %d, "error_event_matches": %s, "services": "%s"}\n' \
  "${pii_hits}" "${error_event_hits:-null}" "${K6_LOG_SERVICES}" >"${results}/privacy.json"

if [ "${log_read_failed}" -eq 1 ] || [ -z "${error_event_hits}" ]; then
  log "MEDIDA NO FIABLE: la comprobacion de la regla dura 21 no pudo leer todas sus fuentes."
  verdict=2
elif [ "${pii_hits}" -gt 0 ] || [ "${error_event_hits}" -gt 0 ]; then
  log "FALLA regla dura 21: ${pii_hits} lineas de registro y ${error_event_hits} filas de error_events"
  log "         con un nombre de la carga. El detalle esta en ${hits_file}, que NO se sube como artefacto."
  verdict=1
else
  log "OK regla dura 21: ningun nombre de la carga en los registros ni en error_events."
fi

# --- Cierre ------------------------------------------------------------------

log "Resultados en ${results} (summary.json, edge-limits.json, privacy.json, instance-*.csv, instance-*.log)."

case "${verdict}" in
0) log "VEREDICTO: verde." ;;
1) log "VEREDICTO: rojo." ;;
*) log "VEREDICTO: no fiable; hay requisitos sin datos suficientes para juzgarlos." ;;
esac

exit "$(normalise_exit "${verdict}")"
