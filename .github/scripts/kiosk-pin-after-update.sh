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
trap 'rm -rf "${WORK}"' EXIT

TOKEN=""
STEP=""

step() {
  STEP="$1"
  printf '\n=== %s\n' "${STEP}"
}

fail() {
  printf '\nFALLO en «%s»: %s\n' "${STEP}" "$*" >&2
  exit 1
}

ok() {
  printf '  ok · %s\n' "$*"
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

# api METODO RUTA [CUERPO_JSON] -> codigo HTTP; el cuerpo queda en ${WORK}/body.
api() {
  local method="$1" path="$2" data="${3:-}"
  local -a args=(-sS -k -o "${WORK}/body" -w '%{http_code}'
    -X "${method}" -H 'Accept: application/json')
  [ -z "${TOKEN}" ] || args+=(-H "Authorization: Bearer ${TOKEN}")
  [ -z "${data}" ] || args+=(-H 'Content-Type: application/json' --data "${data}")
  curl "${args[@]}" "${BASE_URL}${path}"
}

expect_status() {
  local expected="$1" actual="$2" what="$3"
  # El cuerpo de un alta lleva el PIN: solo se enseña si la respuesta no es la esperada.
  [ "${actual}" = "${expected}" ] ||
    fail "${what}: se esperaba ${expected} y fue ${actual}. Cuerpo: $(head -c 400 "${WORK}/body" 2>/dev/null)"
}

wait_ready() {
  local code=""
  for _ in $(seq 1 60); do
    code="$(curl -sk -o /dev/null -w '%{http_code}' "${BASE_URL}/api/v1/ready" || true)"
    [ "${code}" = "200" ] && return 0
    sleep 2
  done
  fail "la API no responde 200 en /api/v1/ready tras 120 s (ultimo: ${code}). Mira 'docker compose logs app nginx'."
}

totp() {
  # shellcheck disable=SC2016 # `$argv` es de PHP, no del shell.
  dc exec -T app php -r \
    'require "vendor/autoload.php"; echo (new PragmaRX\Google2FA\Google2FA())->getCurrentOtp($argv[1]);' \
    "$1"
}

run_browser() {
  KQ_KIOSK_STATE_DIR="${STATE_DIR}" KQ_KIOSK_BASE_URL="${BASE_URL}" node "${NODE_SCRIPT}" "$@"
}

seed_installation() {
  step "before · 1 · administrador con segundo factor y centro"
  wait_ready
  local password code secret
  password="Kq-pin-$(openssl rand -hex 12)-A1!"
  echo "::add-mask::${password}"
  code="$(api POST /api/v1/setup/administrator \
    "$(jq -nc --arg p "${password}" '{name: "Administracion CI", email: "admin@kronoqr.ci.local", password: $p, locale: "es", device_name: "e2e pin tras actualizar"}')")"
  expect_status 201 "${code}" "POST /api/v1/setup/administrator"
  TOKEN="$(jq -er '.challenge_token' "${WORK}/body")"
  code="$(api POST /api/v1/auth/2fa/enrol)"
  expect_status 200 "${code}" "POST /api/v1/auth/2fa/enrol"
  secret="$(jq -er '.secret // .data.secret' "${WORK}/body")"
  echo "::add-mask::${secret}"
  code="$(api POST /api/v1/auth/2fa/confirm "$(jq -nc --arg c "$(totp "${secret}")" '{code: $c}')")"
  expect_status 200 "${code}" "POST /api/v1/auth/2fa/confirm"
  TOKEN="$(jq -er '.token // .data.token' "${WORK}/body")"
  echo "::add-mask::${TOKEN}"
  code="$(api POST /api/v1/setup/site '{"name": "Hotel CI", "timezone": "Europe/Madrid"}')"
  expect_status 201 "${code}" "POST /api/v1/setup/site"
  ok "sesion de administracion y centro"

  step "before · 2 · tres empleados con su PIN (RF-ID-09)"
  install -d -m 0700 "${STATE_DIR}"
  local employees='[]' index employee_code pin
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
  local browser_pid=$! pairing=""
  # Por condicion y no por tiempo: el navegador escribe el fichero en cuanto la
  # tablet tiene su codigo; si muere antes, no hay nada que esperar.
  for _ in $(seq 1 90); do
    [ -s "${STATE_DIR}/pairing-code" ] && break
    kill -0 "${browser_pid}" 2>/dev/null || break
    sleep 1
  done
  if [ ! -s "${STATE_DIR}/pairing-code" ]; then
    wait "${browser_pid}" || true
    fail "el quiosco no llego a mostrar un codigo de emparejamiento. Mira ${STATE_DIR}/diagnostico/."
  fi
  pairing="$(tr -d '[:space:]' <"${STATE_DIR}/pairing-code")"
  dc exec -T app php artisan kiosk:pairing-code "${pairing}" --name="${KIOSK_NAME}" ||
    fail "kiosk:pairing-code no ha confirmado el codigo. Mira 'docker compose logs app'."
  wait "${browser_pid}" || fail "el navegador del quiosco ha fallado (fase before). Mira ${STATE_DIR}/diagnostico/."
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
