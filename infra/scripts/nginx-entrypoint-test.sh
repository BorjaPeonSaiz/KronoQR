#!/usr/bin/env bash
#
# KronoQR — prueba del punto de entrada 04-kronoqr-required-env.sh del borde.
#
# Ejecuta el script real, sin Docker, con distintas combinaciones de las tres
# redes, y comprueba codigo de salida y mensaje (PP-06). Es barata: corre en
# menos de un segundo, y por eso la lanza tambien nginx-smoke.sh.
#
# Uso:  nginx-entrypoint-test.sh
#
# Codigos de salida (tabla comun de lib/exit-codes.sh):
#   0  todos los casos se comportan como se espera
#   6  algun caso no; se dice cual y que salio

set -Eeuo pipefail
IFS=$'\n\t'

SCRIPT_DIR="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"
readonly SCRIPT_DIR
# shellcheck source-path=SCRIPTDIR
# shellcheck source=lib/exit-codes.sh disable=SC1091
. "${SCRIPT_DIR}/lib/exit-codes.sh"

readonly ENTRYPOINT="${SCRIPT_DIR}/../docker/nginx/docker-entrypoint.d/04-kronoqr-required-env.sh"

readonly REAL_IP_ENVSH="${SCRIPT_DIR}/../docker/nginx/docker-entrypoint.d/06-kronoqr-real-ip.envsh"

readonly ADMIN_NET_ENVSH="${SCRIPT_DIR}/../docker/nginx/docker-entrypoint.d/07-kronoqr-admin-net.envsh"

fallo=0

# TRUSTED_PROXY_CIDR de los casos (PP-03). Vacia salvo en los que la prueban.
PROXIES=""

# ADMIN_INTERNAL_CIDR de los casos (PP-10). Vacia salvo en los que la prueban.
ADMIN_NET=""

# caso DESCRIPCION SALIDA_ESPERADA TEXTO_ESPERADO KIOSK PORTAL METRICS
caso() {
  local descripcion="$1" esperada="$2" texto="$3" kiosk="$4" portal="$5" metricas="$6" salida codigo=0

  salida="$(env TRUSTED_PROXY_CIDR="${PROXIES}" ADMIN_INTERNAL_CIDR="${ADMIN_NET}" KIOSK_VLAN_CIDR="${kiosk}" PORTAL_INTERNAL_CIDR="${portal}" \
    METRICS_ALLOW_CIDR="${metricas}" bash "${ENTRYPOINT}" 2>&1)" || codigo=$?

  if [ "${codigo}" != "${esperada}" ]; then
    printf '  [FALLA] %s: salio con %s, se esperaba %s\n%s\n' "${descripcion}" "${codigo}" "${esperada}" "${salida}" >&2
    fallo=1
    return 0
  fi

  if [ -n "${texto}" ] && ! grep -qF -- "${texto}" <<<"${salida}"; then
    printf '  [FALLA] %s: la salida no contiene "%s"\n%s\n' "${descripcion}" "${texto}" "${salida}" >&2
    fallo=1
    return 0
  fi

  printf '  [ok]    %s\n' "${descripcion}"
}

# real_ip DESCRIPCION LISTA ESPERADO: lo que rinde 06-kronoqr-real-ip.envsh,
# cargado con `.` como hace el punto de entrada de la imagen, en `sh`.
real_ip() {
  local descripcion="$1" lista="$2" esperado="$3" salida

  salida="$(TRUSTED_PROXY_CIDR="${lista}" sh -c '. "$1"; printf "%s" "${KRONOQR_REAL_IP_DIRECTIVES}"' sh "${REAL_IP_ENVSH}" 2>&1)" || true

  if [ "${salida}" != "${esperado}" ]; then
    printf '  [FALLA] %s: rindio "%s", se esperaba "%s"\n' "${descripcion}" "${salida}" "${esperado}" >&2
    fallo=1
    return 0
  fi

  printf '  [ok]    %s\n' "${descripcion}"
}

# admin_net DESCRIPCION VALOR ESPERADO: lo que rinde 07-kronoqr-admin-net.envsh en
# KRONOQR_ADMIN_ALLOWED_CIDR, cargado con `.` en `sh`. Con VALOR vacio la variable
# ni siquiera esta definida, como cuando Compose no la recibe.
admin_net() {
  local descripcion="$1" valor="$2" esperado="$3" salida

  if [ -n "${valor}" ]; then
    salida="$(ADMIN_INTERNAL_CIDR="${valor}" sh -c '. "$1"; printf "%s" "${KRONOQR_ADMIN_ALLOWED_CIDR}"' sh "${ADMIN_NET_ENVSH}" 2>&1)" || true
  else
    # shellcheck disable=SC2016
    salida="$(env -u ADMIN_INTERNAL_CIDR sh -c '. "$1"; printf "%s" "${KRONOQR_ADMIN_ALLOWED_CIDR}"' sh "${ADMIN_NET_ENVSH}" 2>&1)" || true
  fi

  if [ "${salida}" != "${esperado}" ]; then
    printf '  [FALLA] %s: rindio "%s", se esperaba "%s"
' "${descripcion}" "${salida}" "${esperado}" >&2
    fallo=1
    return 0
  fi

  printf '  [ok]    %s
' "${descripcion}"
}

main() {
  [ -f "${ENTRYPOINT}" ] || {
    printf 'No encuentro %s. Ejecuta el script desde un checkout completo.\n' "${ENTRYPOINT}" >&2
    exit "${KQ_EXIT_REQUIREMENTS}"
  }

  caso "valores de .env.example" 0 "CIDR IPv4 validos" 10.0.20.0/24 172.28.0.0/16 172.29.0.20/32
  caso "limites validos: /0 y /32, 255.255.255.255" 0 "" 0.0.0.0/0 255.255.255.255/32 10.0.0.0/8
  caso "falta una variable" 1 "Faltan variables obligatorias del borde HTTP: PORTAL_INTERNAL_CIDR" 10.0.20.0/24 "" 172.29.0.20/32
  caso "prefijo /33" 1 "KIOSK_VLAN_CIDR='10.0.20.0/33'" 10.0.20.0/33 172.28.0.0/16 172.29.0.20/32
  caso "octeto 256" 1 "METRICS_ALLOW_CIDR='10.256.0.1/32'" 10.0.20.0/24 172.28.0.0/16 10.256.0.1/32
  caso "sin prefijo" 1 "Una direccion suelta se escribe con /32" 10.0.20.0/24 172.28.0.5 172.29.0.20/32
  caso "dos rangos separados por coma" 1 "PORTAL_INTERNAL_CIDR=" 10.0.20.0/24 "10.0.0.0/8,172.16.0.0/12" 172.29.0.20/32
  caso "IPv6 no se admite" 1 "IPv6 no se admite" 10.0.20.0/24 172.28.0.0/16 fd00::/8
  caso "octeto con ceros a la izquierda" 1 "KIOSK_VLAN_CIDR=" 010.0.20.0/24 172.28.0.0/16 172.29.0.20/32
  caso "basura con comillas no rompe el registro" 1 "no es un CIDR IPv4 valido" '10.0.0.0/8"}' 172.28.0.0/16 172.29.0.20/32
  caso "se nombran todas las invalidas" 1 "PORTAL_INTERNAL_CIDR='x'" 10.0.20.0/33 x 172.29.0.20/32
  caso "portal abierto a internet: solo aviso" 0 "portal abierto a internet" 10.0.20.0/24 0.0.0.0/0 172.29.0.20/32

  # PP-03: TRUSTED_PROXY_CIDR es opcional y se valida solo si viene.
  PROXIES="10.0.0.5/32"
  caso "proxy de confianza valido" 0 "TRUSTED_PROXY_CIDR definida" 10.0.20.0/24 172.28.0.0/16 172.29.0.20/32
  PROXIES="10.0.0.5/32, 10.0.1.0/24"
  caso "lista de proxies con espacio tras la coma" 0 "TRUSTED_PROXY_CIDR definida" 10.0.20.0/24 172.28.0.0/16 172.29.0.20/32
  PROXIES="0.0.0.0/0"
  caso "proxy de confianza 0.0.0.0/0 se rechaza" 1 "incluye 0.0.0.0/0" 10.0.20.0/24 172.28.0.0/16 172.29.0.20/32
  PROXIES="10.0.0.5/32,0.0.0.0/0"
  caso "0.0.0.0/0 escondido en una lista se rechaza" 1 "incluye 0.0.0.0/0" 10.0.20.0/24 172.28.0.0/16 172.29.0.20/32
  PROXIES="10.0.0.5"
  caso "proxy sin prefijo" 1 "TRUSTED_PROXY_CIDR='10.0.0.5'" 10.0.20.0/24 172.28.0.0/16 172.29.0.20/32
  PROXIES="10.0.0.0/33"
  caso "proxy con prefijo /33" 1 "no es un CIDR IPv4 valido" 10.0.20.0/24 172.28.0.0/16 172.29.0.20/32
  PROXIES='10.0.0.5/32";evil'
  caso "basura en el proxy no rompe el registro" 1 "no es un CIDR IPv4 valido" 10.0.20.0/24 172.28.0.0/16 172.29.0.20/32
  PROXIES=""
  caso "sin proxy no se dice nada de proxies" 0 "CIDR IPv4 validos" 10.0.20.0/24 172.28.0.0/16 172.29.0.20/32

  # PP-10: ADMIN_INTERNAL_CIDR es opcional y se valida solo si viene.
  ADMIN_NET="10.20.0.0/24"
  caso "red del panel valida" 0 "ADMIN_INTERNAL_CIDR definida" 10.0.20.0/24 172.28.0.0/16 172.29.0.20/32
  ADMIN_NET="10.20.0.0/33"
  caso "red del panel con prefijo /33" 1 "ADMIN_INTERNAL_CIDR='10.20.0.0/33'" 10.0.20.0/24 172.28.0.0/16 172.29.0.20/32
  ADMIN_NET="10.0.0.0/8,172.16.0.0/12"
  caso "dos rangos en la red del panel" 1 "no es un CIDR IPv4 valido" 10.0.20.0/24 172.28.0.0/16 172.29.0.20/32
  ADMIN_NET='10.0.0.0/8"}'
  caso "basura en la red del panel no rompe el registro" 1 "ADMIN_INTERNAL_CIDR=" 10.0.20.0/24 172.28.0.0/16 172.29.0.20/32
  ADMIN_NET=""
  caso "red del panel vacia: abierta, y lo dice" 0 "ADMIN_INTERNAL_CIDR vacia" 10.0.20.0/24 172.28.0.0/16 172.29.0.20/32

  admin_net "red del panel vacia rinde 0.0.0.0/0 (sin filtro)" "" "0.0.0.0/0"
  admin_net "red del panel con valor se rinde tal cual" "10.20.0.0/24" "10.20.0.0/24"

  real_ip "sin proxy no rinde directivas" "" "# TRUSTED_PROXY_CIDR vacio: no hay proxy de confianza; se usa la IP del socket."
  real_ip "un proxy" "10.0.0.5/32" "set_real_ip_from 10.0.0.5/32;
real_ip_header X-Forwarded-For;
real_ip_recursive on;"
  real_ip "dos proxies, una linea cada uno" "10.0.0.5/32, 10.0.1.0/24" "set_real_ip_from 10.0.0.5/32;
set_real_ip_from 10.0.1.0/24;
real_ip_header X-Forwarded-For;
real_ip_recursive on;"

  if [ "${fallo}" -ne 0 ]; then
    printf '\nEl punto de entrada del borde no se comporta como debe.\n' >&2
    exit "${KQ_EXIT_VERIFY_FAILED}"
  fi

  printf 'La validacion de las tres redes del borde responde como debe.\n'
}

if [ "${BASH_SOURCE[0]}" = "$0" ]; then
  main "$@"
fi
