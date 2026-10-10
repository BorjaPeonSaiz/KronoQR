#!/usr/bin/env bash
#
# KronoQR — etapa ⑧b de la CI: un quiosco abierto ANTES de `update.sh` ficha
# por PIN DESPUES (tarea 3.2 del bloque 3 de la 2.2.1; RF-AT-11, RF-KI-07,
# RF-PD-10).
#
# NO ES UN ENTREGABLE: vive en .github/scripts/ y solo lo ejecuta la CI.
#
# POR QUE EXISTE. En la demo, una tablet con la PWA de la 2.1.0 en cache
# siguio con su Service Worker tras instalar la 2.2.0: su CSP no tenia
# `wasm-unsafe-eval`, el WebAssembly de libsodium no compilaba y el PIN daba
# «Código no válido». El detalle del navegador esta en la cabecera de
# `kiosk-pin-after-update.mjs`; aqui se prepara la instalacion que lo necesita.
#
# DOS MODOS, porque el navegador tiene que haberse usado ANTES de actualizar:
#
#   before DIRECTORIO   Sobre la instalacion de la version anterior (recien
#                       instalada, sin datos): crea el primer administrador con
#                       su segundo factor y el centro, da de alta TRES empleados
#                       (cada alta emite su PIN, RF-ID-09), abre el quiosco en
#                       Chromium, lo empareja por consola (`kiosk:pairing-code`)
#                       y ficha con el primero. Deja el perfil del navegador y
#                       los PIN en KQ_KIOSK_STATE_DIR.
#   after DIRECTORIO    Con la instalacion ya actualizada: reabre ese perfil y
#                       ficha con los otros dos (desde la cache, y tras el plan
#                       B del runbook).
#
# DIRECTORIO es el de la instalacion (su .env y su docker-compose.yml).
#
# Variables:
#   KQ_KIOSK_STATE_DIR  (kiosk-pin-estado) perfil, state.json (0600, lleva los
#                       PIN) y diagnostico/ (capturas y consola, sin PIN).
#   KQ_KIOSK_BASE_URL   (https://kronoqr.ci.local) donde responde el borde.
#   KQ_E2E_SUDO         (sudo) prefijo para docker. Vacio si ya se es root.
#
# Necesita: docker con el plugin compose, node 24 con las dependencias del
# repositorio y Chromium de Playwright instalados, curl, jq, openssl.
# Codigos de salida: 0 todo comprobado · 1 algo no se cumple · 2 uso incorrecto.

set -euo pipefail
IFS=$'\n\t'

[ "$#" -eq 2 ] || {
  printf 'uso: kiosk-pin-after-update.sh before|after DIRECTORIO_DE_LA_INSTALACION\n' >&2
  exit 2
}

MODE="$1"
case "${MODE}" in
before | after) ;;
*)
  printf 'modo desconocido «%s»: usa before o after\n' "${MODE}" >&2
  exit 2
  ;;
esac

PKG="$(cd -- "$2" && pwd)"
HERE="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"
BASE_URL="${KQ_KIOSK_BASE_URL:-https://kronoqr.ci.local}"
SUDO="${KQ_E2E_SUDO-sudo}"
STATE_DIR="${KQ_KIOSK_STATE_DIR:-${PWD}/kiosk-pin-estado}"
STATE_FILE="${STATE_DIR}/state.json"
NODE_SCRIPT="${HERE}/kiosk-pin-after-update.mjs"
KIOSK_NAME="Recepcion CI"

WORK="$(mktemp -d)"
BROWSER_PID=""
TOKEN=""
STEP=""

# step, fail, ok, as_root, dc, api, expect_status, wait_ready, totp y seed_admin_and_site.
# shellcheck source=.github/scripts/lib/ci-api.sh
. "${HERE}/lib/ci-api.sh"

# Un navegador huerfano seguiria sondeando el servidor despues de salir.
cleanup() {
  if [ -n "${BROWSER_PID}" ] && kill -0 "${BROWSER_PID}" 2>/dev/null; then
    kill "${BROWSER_PID}" 2>/dev/null || true
    wait "${BROWSER_PID}" 2>/dev/null || true
  fi
  rm -rf "${WORK}"
}
trap cleanup EXIT

run_browser() {
  KQ_KIOSK_STATE_DIR="${STATE_DIR}" KQ_KIOSK_BASE_URL="${BASE_URL}" node "${NODE_SCRIPT}" "$@"
}

seed_installation() {
  step "before · 1 · administrador con segundo factor y centro"
  wait_ready
  seed_admin_and_site "e2e pin tras actualizar"

  step "before · 2 · tres empleados con su PIN (RF-ID-09)"
  install -d -m 0700 "${STATE_DIR}"
  local employees='[]' index employee_code pin code
  for index in 0 1 2; do
    code="$(api POST /api/v1/employees \
      "$(jq -nc --arg l "Pin-${index}" '{first_name: "Empleado", last_name: $l, hired_at: "2026-01-05"}')")"
    expect_status 201 "${code}" "POST /api/v1/employees (${index})"
    employee_code="$(jq -er '.employee.employee_code // .data.employee.employee_code' "${WORK}/body")"
    pin="$(jq -er '.pin.pin // .data.pin.pin' "${WORK}/body")"
    echo "::add-mask::${pin}"
    employees="$(jq -c --arg c "${employee_code}" --arg p "${pin}" '. + [{code: $c, pin: $p}]' <<<"${employees}")"
  done
  (
    umask 077
    jq -n --argjson e "${employees}" '{employees: $e}' >"${STATE_FILE}"
  )
  ok "3 empleados con PIN emitido (los PIN no se imprimen)"
}

pair_and_clock_in() {
  step "before · 3 · abrir el quiosco, emparejarlo por consola y fichar con la version anterior"
  rm -f "${STATE_DIR}/pairing-code"
  run_browser before &
  BROWSER_PID=$!
  local pairing=""
  # Por condicion y no por tiempo: el navegador escribe el fichero en cuanto la
  # tablet tiene su codigo; si muere antes, no hay nada que esperar.
  for _ in $(seq 1 90); do
    [ -s "${STATE_DIR}/pairing-code" ] && break
    kill -0 "${BROWSER_PID}" 2>/dev/null || break
    sleep 1
  done
  if [ ! -s "${STATE_DIR}/pairing-code" ]; then
    wait "${BROWSER_PID}" || true
    fail "el quiosco no llego a mostrar un codigo de emparejamiento. Mira ${STATE_DIR}/diagnostico/."
  fi
  pairing="$(tr -d '[:space:]' <"${STATE_DIR}/pairing-code")"
  dc exec -T app php artisan kiosk:pairing-code "${pairing}" --name="${KIOSK_NAME}" ||
    fail "kiosk:pairing-code no ha confirmado el codigo. Mira 'docker compose logs app' (el navegador se mata al salir)."
  wait "${BROWSER_PID}" || fail "el navegador del quiosco ha fallado (fase before). Mira ${STATE_DIR}/diagnostico/."
  ok "quiosco emparejado y primer fichaje por PIN aceptado en la version anterior"
}

case "${MODE}" in
before)
  rm -rf "${STATE_DIR}"
  seed_installation
  pair_and_clock_in
  ;;
after)
  step "after · quiosco abierto antes de actualizar, ahora sobre la version nueva"
  [ -f "${STATE_FILE}" ] && [ -d "${STATE_DIR}/profile" ] ||
    fail "falta el estado de la fase before en ${STATE_DIR}: ejecuta antes 'kiosk-pin-after-update.sh before'."
  wait_ready
  run_browser after || fail "el quiosco abierto antes de actualizar no ficha por PIN despues. Mira ${STATE_DIR}/diagnostico/."
  ;;
esac
