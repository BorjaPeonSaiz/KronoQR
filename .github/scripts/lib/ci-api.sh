#!/usr/bin/env bash
#
# KronoQR — utilidades comunes de los scripts E2E de la etapa ⑧ de la CI que
# hablan con una instalacion real por su API (generated-files-e2e.sh y
# kiosk-pin-after-update.sh).
#
# NO SE EJECUTA SOLO: se carga con `.`. NO ES UN ENTREGABLE.
#
# Quien la carga define ANTES, y la biblioteca solo las usa:
#   PKG       directorio de la instalacion (su .env y su docker-compose.yml).
#   BASE_URL  donde responde el borde.
#   SUDO      prefijo para docker y el .env (vacio si ya se es root).
#   WORK      directorio temporal: el cuerpo de la ultima respuesta queda en
#             ${WORK}/body y sus cabeceras en ${WORK}/headers.
#   TOKEN     token Bearer en uso (vacio hasta `seed_admin_and_site`).
#   STEP      nombre del paso en curso (lo escribe `step`).
#
# Los secretos que genera (contrasena, secreto 2FA, token) se enmascaran en el
# log de GitHub con `::add-mask::` en cuanto existen.

set -euo pipefail
IFS=$'\n\t'

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

# api METODO RUTA [CUERPO_JSON] -> codigo HTTP; cuerpo en ${WORK}/body y
# cabeceras en ${WORK}/headers.
api() {
  local method="$1" path="$2" data="${3:-}"
  local -a args=(-sS -k -o "${WORK}/body" -D "${WORK}/headers" -w '%{http_code}'
    -X "${method}" -H 'Accept: application/json')
  [ -z "${TOKEN}" ] || args+=(-H "Authorization: Bearer ${TOKEN}")
  [ -z "${data}" ] || args+=(-H 'Content-Type: application/json' --data "${data}")
  curl "${args[@]}" "${BASE_URL}${path}"
}

# El cuerpo solo se enseña cuando la respuesta NO es la esperada: el de un alta
# de empleado lleva su PIN.
expect_status() {
  local expected="$1" actual="$2" what="$3"
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

# Primer administrador con su segundo factor y el centro, como los dos primeros
# pasos del asistente. Deja el token de la sesion en TOKEN. $1: nombre del
# dispositivo de la sesion.
seed_admin_and_site() {
  local device_name="$1" password code secret
  password="Kq-e2e-$(openssl rand -hex 12)-A1!"
  echo "::add-mask::${password}"
  code="$(api POST /api/v1/setup/administrator \
    "$(jq -nc --arg p "${password}" --arg d "${device_name}" '{name: "Administracion CI", email: "admin@kronoqr.ci.local", password: $p, locale: "es", device_name: $d}')")"
  expect_status 201 "${code}" "POST /api/v1/setup/administrator"
  TOKEN="$(jq -er '.challenge_token' "${WORK}/body")"
  echo "::add-mask::${TOKEN}"
  code="$(api POST /api/v1/auth/2fa/enrol)"
  expect_status 200 "${code}" "POST /api/v1/auth/2fa/enrol"
  secret="$(jq -er '.secret // .data.secret' "${WORK}/body")"
  echo "::add-mask::${secret}"
  code="$(api POST /api/v1/auth/2fa/confirm "$(jq -nc --arg c "$(totp "${secret}")" '{code: $c}')")"
  expect_status 200 "${code}" "POST /api/v1/auth/2fa/confirm"
  TOKEN="$(jq -er '.token // .data.token' "${WORK}/body")"
  echo "::add-mask::${TOKEN}"
  ok "sesion de admin con segundo factor"
  # El centro: sin el no hay zona horaria (retencion, fichajes).
  code="$(api POST /api/v1/setup/site '{"name": "Hotel CI", "timezone": "Europe/Madrid"}')"
  expect_status 201 "${code}" "POST /api/v1/setup/site"
}
