#!/usr/bin/env bash
#
# KronoQR — etapa ⑧ de la CI: rellena un paquete de entrega como lo haria el
# IT del hotel, para poder instalarlo o actualizarlo en un runner.
#
# NO ES UN ENTREGABLE: vive en .github/scripts/ y solo lo ejecuta la CI. Lo
# que hace es lo que la guia de instalacion manda hacer a mano (instalacion.md
# §1.2): un certificado en certs/ con el propietario que exige el borde, y un
# .env con VALORES PROPIOS en lo marcado [CLIENTE]. Con los valores de la
# plantilla, la fase 1 del instalador se niega —y con razon: con
# APP_URL=https://localhost el sistema arranca, todo pasa y ningun quiosco
# puede llegar a el—.
#
# Hasta la tarea 5.7 este relleno estaba inline en ci.yml, y dos veces (el
# escenario de instalacion y el de fallo seguro, que difieren en tres valores).
# Al necesitarlo tambien el job de actualizacion —dos paquetes mas— se saco
# aqui. Las diferencias entre escenarios van por argumentos, no por copias.
#
# Uso:
#   prepare-package-env.sh DIRECTORIO [--backup-path RUTA] [--http-port N] [--https-port N]
#
# Necesita sudo: el certificado se entrega al uid 101 (nginx sin privilegios).
# Codigos de salida: 0 correcto · 1 fallo · 2 uso incorrecto.

set -euo pipefail
IFS=$'\n\t'

usage() {
  sed -n '2,/^$/p' "${BASH_SOURCE[0]}" | sed 's/^# \{0,1\}//'
}

[ "$#" -ge 1 ] || {
  usage >&2
  exit 2
}

PACKAGE_DIR="$1"
shift
BACKUP_PATH="/var/backups/fichaje"
HTTP_PORT="80"
HTTPS_PORT="443"

while [ "$#" -gt 0 ]; do
  case "$1" in
  --backup-path)
    BACKUP_PATH="$2"
    shift 2
    ;;
  --http-port)
    HTTP_PORT="$2"
    shift 2
    ;;
  --https-port)
    HTTPS_PORT="$2"
    shift 2
    ;;
  -h | --help)
    usage
    exit 0
    ;;
  *)
    printf 'prepare-package-env.sh: argumento desconocido %s\n' "$1" >&2
    exit 2
    ;;
  esac
done

[ -f "${PACKAGE_DIR}/.env.example" ] || {
  printf 'prepare-package-env.sh: %s no es un paquete de entrega (falta .env.example)\n' "${PACKAGE_DIR}" >&2
  exit 1
}

# El nombre con el que el runner se llama a si mismo. Idempotente: no se
# duplica la linea si otro escenario ya la puso.
grep -q 'kronoqr.ci.local' /etc/hosts || echo "127.0.0.1 kronoqr.ci.local" | sudo tee -a /etc/hosts >/dev/null

# Un certificado de verdad: el borde se niega a arrancar sin el y ese camino
# tiene que quedar ejercitado. Se genera una sola vez por paquete.
if [ ! -f "${PACKAGE_DIR}/certs/tls.key" ]; then
  mkdir -p "${PACKAGE_DIR}/certs"
  openssl req -x509 -nodes -newkey rsa:2048 -days 2 \
    -subj "/C=ES/O=KronoQR CI/CN=kronoqr.ci.local" \
    -addext "subjectAltName=DNS:kronoqr.ci.local,DNS:localhost,IP:127.0.0.1" \
    -keyout "${PACKAGE_DIR}/certs/tls.key" -out "${PACKAGE_DIR}/certs/tls.crt" 2>/dev/null
fi
# Con el propietario que exige el producto (instalacion.md §1.2): el borde
# corre como uid 101 y no puede abrir un fichero de root. openssl escribe las
# claves 0600, asi que sin esto nginx entra en bucle de reinicio. El instalador
# NO lo corrige solo, a proposito: TLS_CERT_DIR puede ser un directorio
# compartido con otro servicio del hotel.
sudo chown 101:101 "${PACKAGE_DIR}/certs/tls.key" "${PACKAGE_DIR}/certs/tls.crt"
sudo chmod 0400 "${PACKAGE_DIR}/certs/tls.key"
sudo chmod 0444 "${PACKAGE_DIR}/certs/tls.crt"

cp "${PACKAGE_DIR}/.env.example" "${PACKAGE_DIR}/.env"
sed -i \
  -e 's|^APP_ENV=.*|APP_ENV=production|' \
  -e 's|^APP_URL=.*|APP_URL=https://kronoqr.ci.local|' \
  -e 's|^IMAGE_REGISTRY=.*|IMAGE_REGISTRY=kronoqr|' \
  -e 's|^KIOSK_VLAN_CIDR=.*|KIOSK_VLAN_CIDR=10.92.0.0/24|' \
  -e 's|^METRICS_ALLOW_CIDR=.*|METRICS_ALLOW_CIDR=10.91.0.5/32|' \
  -e 's|^TLS_ALLOW_SELF_SIGNED=.*|TLS_ALLOW_SELF_SIGNED=false|' \
  -e 's|^COMPOSE_PROFILES=.*|COMPOSE_PROFILES=|' \
  -e "s|^BACKUP_PATH=.*|BACKUP_PATH=${BACKUP_PATH}|" \
  -e "s|^HTTP_PORT=.*|HTTP_PORT=${HTTP_PORT}|" \
  -e "s|^HTTPS_PORT=.*|HTTPS_PORT=${HTTPS_PORT}|" \
  "${PACKAGE_DIR}/.env"
# El portal, a una red en la que el anfitrion NO esta: es lo que hace que la
# comprobacion de RF-ID-08 espere un 403 y no un 200.
sed -i -e 's|^PORTAL_INTERNAL_CIDR=.*|PORTAL_INTERNAL_CIDR=10.90.0.0/24|' "${PACKAGE_DIR}/.env"

# Lo que NO se toca queda como lo entrega el fabricante: APP_DEBUG,
# APP_TIMEZONE, IMAGE_TAG y los secretos. Si el instalador no los resolviera,
# la etapa fallaria mas abajo.
grep -q '^APP_DEBUG=false$' "${PACKAGE_DIR}/.env"
grep -q '^APP_TIMEZONE=UTC$' "${PACKAGE_DIR}/.env"
grep -q '^IMAGE_TAG=$' "${PACKAGE_DIR}/.env"
grep -q '^APP_KEY=$' "${PACKAGE_DIR}/.env"

printf 'Paquete %s rellenado: APP_URL=https://kronoqr.ci.local, BACKUP_PATH=%s, puertos %s/%s\n' \
  "${PACKAGE_DIR}" "${BACKUP_PATH}" "${HTTP_PORT}" "${HTTPS_PORT}"
