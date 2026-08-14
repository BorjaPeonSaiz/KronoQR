#!/usr/bin/env bash
#
# KronoQR — arranque del servidor de desarrollo de un frontend.
#
# Instala dependencias si faltan y levanta Vite. Es idempotente: si
# node_modules ya esta, no reinstala.
#
# Codigos de salida:
#   0  parada limpia
#   1  npm fallo al instalar o Vite no arranco
#
# Nota de la Fase 0: los tres frontends son esqueletos de la tarea 0.5.
# Mientras no exista package.json, el contenedor queda a la espera en lugar de
# reiniciarse en bucle.

set -euo pipefail
IFS=$'\n\t'

readonly APP_PATH="/app"
readonly APP_NAME="${FRONTEND_NAME:-frontend}"

log() {
  printf '{"level":"%s","service":"%s","message":"%s"}\n' "$1" "${APP_NAME}" "$2" >&2
}

if [[ ! -f "${APP_PATH}/package.json" ]]; then
  log "warning" "Sin ${APP_PATH}/package.json: los frontends llegan en la tarea 0.5. El contenedor queda a la espera. Ejecuta make up de nuevo cuando exista."
  trap 'exit 0' TERM INT
  while true; do
    sleep 5
  done
fi

if [[ ! -d "${APP_PATH}/node_modules" ]]; then
  log "info" "Instalando dependencias con npm ci."
  if ! npm ci --prefix "${APP_PATH}"; then
    log "error" "npm ci fallo. Que hacer: borra ${APP_PATH}/node_modules y package-lock.json en el host, ejecuta make build y vuelve a intentarlo."
    exit 1
  fi
fi

log "info" "Arrancando Vite en 0.0.0.0:5173."
exec npm --prefix "${APP_PATH}" run dev -- --host 0.0.0.0 --port 5173
