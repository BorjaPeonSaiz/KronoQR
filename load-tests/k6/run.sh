#!/usr/bin/env bash
#
# Prueba de carga del fichaje (RNF-P-02, RNF-P-06) contra el entorno de
# desarrollo de Compose. Ver el porqué del diseño en scan-p95.js: una instancia
# de k6 es una IP y el producto limita por IP a ~10 fichajes/s, asi que los
# 50/s sostenidos de RNF-P-06 se generan con INSTANCES origenes en paralelo y
# el veredicto lo da aggregate.js sobre las muestras crudas de todos.
#
# Uso:  load-tests/k6/run.sh
#       INSTANCES=5 RATE=10 DURATION=120s load-tests/k6/run.sh
#
# Requisitos: entorno levantado (make up), datos sembrados (make seed) y
# QR_SIGNING_KEY_CURRENT configurada en .env.

set -euo pipefail
IFS=$'\n\t'

INSTANCES="${INSTANCES:-5}"
RATE="${RATE:-10}"
DURATION="${DURATION:-120s}"
NETWORK="${NETWORK:-kronoqr-app}"

script_dir="$(cd "$(dirname "$0")" && pwd)"
root="$(cd "${script_dir}/../.." && pwd)"
# Docker Desktop en Windows quiere la ruta con letra de unidad; en Linux/macOS
# pwd -W no existe y se queda la ruta normal.
root_mount="$(cd "${root}" && { pwd -W 2>/dev/null || pwd; })"
results="${script_dir}/.results"

log() { printf '[k6] %s\n' "$1"; }

log "Aprovisionando credenciales y dispositivos de la prueba (provision-fixtures.php)..."
# Tinker devuelve 0 aunque el include lance una excepcion, asi que el exito no
# se puede leer del codigo de salida: se borra el fichero de fixtures antes y
# se exige que exista despues.
docker compose --env-file "${root}/.env" -f "${root}/infra/compose.dev.yaml" exec -T app \
  sh -c "rm -f storage/framework/k6-fixtures.json && php artisan tinker --execute=\"include '/var/www/repo/load-tests/k6/provision-fixtures.php';\" && test -f storage/framework/k6-fixtures.json" || {
  log "ERROR: el aprovisionamiento no dejo fixtures; revisa la salida de arriba."
  exit 1
}

rm -rf "${results}"
mkdir -p "${results}"

total_rate=$((INSTANCES * RATE))
log "Lanzando ${INSTANCES} instancias x ${RATE} fichajes/s = ${total_rate}/s durante ${DURATION}..."

pids=()
for i in $(seq 0 $((INSTANCES - 1))); do
  MSYS_NO_PATHCONV=1 docker run --rm --network "${NETWORK}" \
    -v "${root_mount}/load-tests/k6:/scripts:ro" \
    -v "${root_mount}/backend/storage/framework:/fixtures:ro" \
    -v "${root_mount}/load-tests/k6/.results:/results" \
    -e RATE="${RATE}" -e DURATION="${DURATION}" \
    -e INSTANCE="${i}" -e INSTANCES="${INSTANCES}" \
    grafana/k6:latest run --quiet --out "csv=/results/instance-${i}.csv" /scripts/scan-p95.js \
    >"${results}/instance-${i}.log" 2>&1 &
  pids+=("$!")
done

# Los umbrales por instancia pueden fallar (exit != 0) sin invalidar la medida:
# el veredicto real es el agregado. Aqui solo se recoge si ALGUNA instancia no
# llego ni a ejecutar (log vacio o sin CSV), que aggregate.js detecta igualmente
# al no encontrar muestras.
for pid in "${pids[@]}"; do
  wait "${pid}" || true
done

log "Instancias terminadas; agregando muestras..."
node "${script_dir}/aggregate.js" "${results}"
