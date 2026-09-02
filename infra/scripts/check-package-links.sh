#!/usr/bin/env bash
#
# KronoQR — comprueba que la documentacion entregada no enlaza fuera del paquete.
#
# PROPOSITO. Las cuatro guias de cliente se enlazan entre si y enlazan a los
# runbooks. El cliente NO tiene el repositorio: un enlace relativo que apunte a
# algo que no viaja en el paquete es un 404 en el peor momento posible, que es
# cuando alguien sigue la guia con una incidencia delante. La primera version
# del paquete dejaba seis asi (restaurar-backup, rotacion-secretos,
# rotura-cadena-auditoria, alta-nuevo-quiosco, solicitud-derechos-rgpd y
# actualizacion-cliente) y nadie lo habria visto hasta casa del hotel.
#
# Uso:  check-package-links.sh RUTA_DEL_PAQUETE
#
# Codigos de salida (tabla comun de lib/exit-codes.sh):
#   0  todos los enlaces resuelven dentro del paquete
#   1  uso incorrecto
#   2  el directorio indicado no existe
#   6  hay enlaces rotos; se enumeran uno a uno
#
# Solo mira enlaces RELATIVOS a ficheros .md. Los absolutos y los http quedan
# fuera a proposito: un enlace a la web del fabricante es legitimo.

set -euo pipefail
IFS=$'\n\t'

SCRIPT_DIR="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"
readonly SCRIPT_DIR
# shellcheck source-path=SCRIPTDIR
# shellcheck source=lib/exit-codes.sh disable=SC1091
. "${SCRIPT_DIR}/lib/exit-codes.sh"

main() {
  local paquete="${1:-}" doc destino base rotos=0 total=0

  if [ -z "${paquete}" ]; then
    printf 'Uso: check-package-links.sh RUTA_DEL_PAQUETE\n' >&2
    exit "${KQ_EXIT_USAGE}"
  fi

  if [ ! -d "${paquete}" ]; then
    printf 'No existe el directorio %s.\n' "${paquete}" >&2
    exit "${KQ_EXIT_REQUIREMENTS}"
  fi

  while IFS= read -r doc; do
    base="$(dirname -- "${doc}")"

    while IFS= read -r destino; do
      [ -n "${destino}" ] || continue
      case "${destino}" in
      http* | /*) continue ;;
      esac

      total=$((total + 1))

      if [ ! -e "${base}/${destino}" ]; then
        printf 'ROTO: %s -> %s\n' "${doc}" "${destino}" >&2
        rotos=$((rotos + 1))
      fi
    done < <(grep -oE '\]\([^)]+\.md[^)]*\)' "${doc}" 2>/dev/null |
      sed -E 's/^\]\(//; s/\)$//; s/#.*$//' || true)
  done < <(find "${paquete}" -name '*.md' -type f)

  if [ "${rotos}" -gt 0 ]; then
    printf '\n%d enlace(s) roto(s) de %d comprobados. El cliente no tiene el repositorio: lo que no viaje en el paquete no existe para el.\n' \
      "${rotos}" "${total}" >&2
    exit "${KQ_EXIT_VERIFY_FAILED}"
  fi

  printf '%d enlaces comprobados, todos resuelven dentro del paquete.\n' "${total}"
}

if [ "${BASH_SOURCE[0]}" = "$0" ]; then
  main "$@"
fi
