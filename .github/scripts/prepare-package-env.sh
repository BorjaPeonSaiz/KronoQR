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
# DOS MODOS (I4, 2.2.0):
#   · Por defecto (el de los escenarios A-F y el del job de actualizacion): ademas
#     de lo de la guia, APAGA la observabilidad y pone `METRICS_ALLOW_CIDR` a otra
#     cosa. Sirve para instalar rapido y sin descargar cinco imagenes, pero NO es
#     el procedimiento de la guia: con el, el bloqueante I1 (un `METRICS_ALLOW_CIDR`
#     que dejaba a Prometheus con 403) pasaba la CI sin que nadie lo viera.
#   · `--literal`: SOLO lo que la guia manda poner en su bloque «Lo minimo que hay
#     que rellenar» (instalacion.md §1.2): APP_ENV, APP_URL, KIOSK_VLAN_CIDR,
#     PORTAL_INTERNAL_CIDR, TLS_ALLOW_SELF_SIGNED, BACKUP_PATH e IMAGE_REGISTRY. Lo
#     demas queda como lo entrega el fabricante: `METRICS_ALLOW_CIDR` con el valor
#     de serie (172.29.0.20/32) y `COMPOSE_PROFILES=observability` (encendida). Es
#     lo que usa la segunda pasada del ⑧ (escenario G).
#
# Uso:
#   prepare-package-env.sh DIRECTORIO [--literal] [--backup-path RUTA] [--http-port N] [--https-port N]
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
LITERAL=0

while [ "$#" -gt 0 ]; do
  case "$1" in
  --literal)
    LITERAL=1
    shift
    ;;
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
  -e 's|^TLS_ALLOW_SELF_SIGNED=.*|TLS_ALLOW_SELF_SIGNED=false|' \
  -e "s|^BACKUP_PATH=.*|BACKUP_PATH=${BACKUP_PATH}|" \
  -e "s|^HTTP_PORT=.*|HTTP_PORT=${HTTP_PORT}|" \
  -e "s|^HTTPS_PORT=.*|HTTPS_PORT=${HTTPS_PORT}|" \
  "${PACKAGE_DIR}/.env"
if [ "${LITERAL}" -eq 0 ]; then
  # Lo que la guia NO manda tocar y este modo si: otra red de metricas y la
  # observabilidad apagada (ver la cabecera: ese es el hueco de I4).
  sed -i \
    -e 's|^METRICS_ALLOW_CIDR=.*|METRICS_ALLOW_CIDR=10.91.0.5/32|' \
    -e 's|^COMPOSE_PROFILES=.*|COMPOSE_PROFILES=|' \
    "${PACKAGE_DIR}/.env"
fi
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

MODE_LABEL=""
[ "${LITERAL}" -eq 0 ] || MODE_LABEL=" (literal, como la guia)"
printf 'Paquete %s rellenado%s: APP_URL=https://kronoqr.ci.local, BACKUP_PATH=%s, puertos %s/%s\n' \
  "${PACKAGE_DIR}" "${MODE_LABEL}" "${BACKUP_PATH}" "${HTTP_PORT}" "${HTTPS_PORT}"
