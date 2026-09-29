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

fallo=0

# caso DESCRIPCION SALIDA_ESPERADA TEXTO_ESPERADO KIOSK PORTAL METRICS
caso() {
  local descripcion="$1" esperada="$2" texto="$3" kiosk="$4" portal="$5" metricas="$6" salida codigo=0

  salida="$(env KIOSK_VLAN_CIDR="${kiosk}" PORTAL_INTERNAL_CIDR="${portal}" \
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

  if [ "${fallo}" -ne 0 ]; then
    printf '\nEl punto de entrada del borde no se comporta como debe.\n' >&2
    exit "${KQ_EXIT_VERIFY_FAILED}"
  fi

  printf 'La validacion de las tres redes del borde responde como debe.\n'
}

if [ "${BASH_SOURCE[0]}" = "$0" ]; then
  main "$@"
fi
