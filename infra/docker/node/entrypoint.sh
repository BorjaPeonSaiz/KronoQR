#!/usr/bin/env bash
#
# KronoQR — arranque del servidor de desarrollo de un frontend, dentro del
# workspace de npm (ADR-036).
#
# Los tres frontends (kiosk, admin, portal) y el paquete compartido
# packages/web-kit viven en UN SOLO arbol de dependencias, gobernado por el
# package-lock.json de la raiz del repositorio (npm workspaces). Este
# contenedor monta la raiz completa en /app y arranca Vite desde
# /app/frontend-<nombre> (el `working_dir` que fija infra/compose.dev.yaml
# por servicio), nunca con la opcion de npm que apunta a un frontend suelto:
# en un workspace los binarios -vite incluido- se hozan en
# /app/node_modules/.bin, no en /app/frontend-<nombre>/node_modules/.bin, y
# apuntar ahi a mano los buscaba donde ya no estan. De ahi "sh: vite: not
# found" y el bucle de reinicio que motiva este fichero.
#
# INSTALACION: SOLO UN CONTENEDOR EJECUTA `npm ci`. Los tres servicios
# comparten el MISMO arbol de node_modules (los volumenes con nombre de
# infra/compose.dev.yaml apuntan a las mismas rutas en los tres), asi que tres
# `npm ci` en paralelo instalarian tres veces sobre el mismo volumen: en el
# mejor caso, trabajo triplicado; en el peor, un arbol a medio escribir por un
# proceso que otro ya dio por terminado.
#
# Se resuelve con un ROL DESIGNADO dentro de este mismo entrypoint -no con un
# cuarto servicio de un solo disparo-, exactamente el patron que ya usa
# infra/docker/php/entrypoint.sh con vendor/: el rol "fpm" instala,
# horizon/reverb/scheduler esperan sondeando el mismo fichero. Aqui,
# NODE_WORKSPACE_INSTALLER=true -que infra/compose.dev.yaml solo pone en
# node-kiosk- hace de "fpm"; node-admin y node-portal sondean el binario de
# Vite hozado en la raiz. Un patron nuevo para un problema identico habria
# sido la unica diferencia sin motivo.
#
# Idempotente: si /app/node_modules/.bin/vite ya existe, no reinstala.
#
# Codigos de salida:
#   0  parada limpia
#   1  falta el montaje de la raiz o de la SPA, o `npm ci` fallo

set -euo pipefail
IFS=$'\n\t'

readonly WORKSPACE_ROOT="/app"
readonly APP_NAME="${FRONTEND_NAME:-frontend}"
readonly VITE_BIN="${WORKSPACE_ROOT}/node_modules/.bin/vite"

log() {
  printf '{"level":"%s","service":"%s","message":"%s"}\n' "$1" "${APP_NAME}" "$2" >&2
}

if [[ ! -f "${WORKSPACE_ROOT}/package.json" ]]; then
  log "error" "No se encuentra ${WORKSPACE_ROOT}/package.json. Que hacer: comprueba que el servicio node-${APP_NAME} monta la RAIZ del repositorio en ${WORKSPACE_ROOT} (infra/compose.dev.yaml), no solo frontend-${APP_NAME} suelto."
  exit 1
fi

if [[ ! -f "package.json" ]]; then
  log "error" "No se encuentra package.json en $(pwd). Que hacer: revisa el working_dir del servicio node-${APP_NAME} en infra/compose.dev.yaml -- debe ser ${WORKSPACE_ROOT}/frontend-${APP_NAME}."
  exit 1
fi

if [[ "${NODE_WORKSPACE_INSTALLER:-false}" == "true" ]]; then
  if [[ ! -x "${VITE_BIN}" ]]; then
    log "info" "Instalando el workspace completo con npm ci (frontend-kiosk, frontend-admin, frontend-portal, packages/web-kit). La primera vez tarda varios minutos."
    if ! (cd "${WORKSPACE_ROOT}" && npm ci); then
      log "error" "npm ci del workspace fallo. Que hacer: revisa package.json/package-lock.json en la raiz del repositorio. Si el volumen quedo a medias, borra los volumenes node-modules-* (docker compose -f infra/compose.dev.yaml down; docker volume rm kronoqr_node-modules-root kronoqr_node-modules-kiosk kronoqr_node-modules-admin kronoqr_node-modules-portal kronoqr_node-modules-web-kit) y repite make up."
      exit 1
    fi
    log "info" "Workspace instalado."
  else
    log "info" "El workspace ya esta instalado (se encontro ${VITE_BIN})."
  fi
else
  if [[ ! -x "${VITE_BIN}" ]]; then
    log "warning" "Esperando a que node-kiosk instale el workspace en el volumen compartido."
    while [[ ! -x "${VITE_BIN}" ]]; do
      sleep 3
    done
  fi
fi

log "info" "Arrancando Vite en 0.0.0.0:5173."
exec npm run dev -- --host 0.0.0.0 --port 5173
