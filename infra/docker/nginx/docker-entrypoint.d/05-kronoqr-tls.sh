#!/usr/bin/env bash
#
# KronoQR — certificado TLS del borde.
#
# Se ejecuta antes de arrancar Nginx. Comprueba que hay certificado y, SOLO si
# TLS_ALLOW_SELF_SIGNED es true (desarrollo), genera uno autofirmado.
#
# En produccion no genera nada: si falta el certificado, para el arranque y
# dice donde colocarlo. Un certificado autofirmado en el servidor de un cliente
# haria que los quioscos avisaran de sitio no seguro cada manana, y alguien
# acabaria desactivando la comprobacion.
#
# Idempotente: si los ficheros ya existen, no los toca.
#
# Codigos de salida:
#   0  hay certificado utilizable
#   1  falta el certificado y no esta permitido generarlo

set -euo pipefail
IFS=$'\n\t'

readonly CERT_FILE="${TLS_CERT_FILE:-/etc/nginx/certs/tls.crt}"
readonly KEY_FILE="${TLS_KEY_FILE:-/etc/nginx/certs/tls.key}"
readonly ALLOW_SELF_SIGNED="${TLS_ALLOW_SELF_SIGNED:-false}"

log() {
  printf '{"level":"%s","service":"nginx","step":"tls","message":"%s"}\n' "$1" "$2" >&2
}

if [[ -s "${CERT_FILE}" && -s "${KEY_FILE}" ]]; then
  log "info" "Certificado presente en ${CERT_FILE}. No se toca nada."
  exit 0
fi

if [[ "${ALLOW_SELF_SIGNED}" != "true" ]]; then
  log "error" "Falta el certificado TLS en ${CERT_FILE} y ${KEY_FILE}, y TLS_ALLOW_SELF_SIGNED no es true."
  log "error" "Que hacer: copia el certificado y su clave privada al directorio indicado por TLS_CERT_DIR en el .env (por defecto ./certs, con los nombres tls.crt y tls.key) y vuelve a levantar el servicio. Si es una instalacion de prueba sin certificado real, pon TLS_ALLOW_SELF_SIGNED=true en el .env, asumiendo que los navegadores avisaran de sitio no seguro."
  exit 1
fi

log "warning" "Sin certificado: se genera uno autofirmado para desarrollo. curl necesitara -k y el navegador avisara. NO uses esto en el servidor de un cliente."

mkdir -p "$(dirname "${CERT_FILE}")" "$(dirname "${KEY_FILE}")"

openssl req -x509 -nodes \
  -newkey rsa:2048 \
  -days 825 \
  -subj "/C=ES/O=KronoQR desarrollo/CN=localhost" \
  -addext "subjectAltName=DNS:localhost,DNS:kronoqr.local,IP:127.0.0.1" \
  -keyout "${KEY_FILE}" \
  -out "${CERT_FILE}" 2>/dev/null

chmod 600 "${KEY_FILE}"
log "info" "Certificado autofirmado generado en ${CERT_FILE}, valido 825 dias."
