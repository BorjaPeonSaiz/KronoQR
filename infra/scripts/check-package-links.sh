#!/usr/bin/env bash
#
# KronoQR — comprueba que la documentacion entregada no enlaza fuera del paquete.
#
# PROPOSITO. Las guias de cliente se enlazan entre si, enlazan a los runbooks y
# —desde la tarea 5.11— muestran las capturas del asistente. El cliente NO tiene
# el repositorio: un enlace relativo que apunte a algo que no viaja en el paquete
# es un 404 en el peor momento posible, que es cuando alguien sigue la guia con
# una incidencia delante. La primera version del paquete dejaba seis asi
# (restaurar-backup, rotacion-secretos, rotura-cadena-auditoria,
# alta-nuevo-quiosco, solicitud-derechos-rgpd y actualizacion-cliente) y nadie lo
# habria visto hasta casa del hotel.
#
# LAS IMAGENES CUENTAN IGUAL, y fallan peor. Un enlace roto a un .md se ve: la
# pagina no abre. Una captura que no viaja se ve como un hueco, y quien lee la
# guia no sabe si es que falta la imagen o si es que su pantalla no es la que la
# guia describe. Las capturas se generan aparte —`npm run docs:screenshots`,
# nunca en la CI— asi que es perfectamente posible entregar una guia que las
# nombra y un paquete que no las lleva.
#
# Uso:  check-package-links.sh RUTA_DEL_PAQUETE
#
# Codigos de salida (tabla comun de lib/exit-codes.sh):
#   0  todos los enlaces resuelven dentro del paquete
#   1  uso incorrecto
#   2  el directorio indicado no existe
#   6  hay enlaces rotos; se enumeran uno a uno
#
# Mira CUALQUIER destino relativo, sea cual sea su extension (V6-DV-2, 2.3.0).
# Hasta la 2.3.0 solo miraba .md y las imagenes, y diecisiete runbooks
# enlazaban a reglas de Prometheus, workflows y ficheros de configuracion del
# repositorio (`../../infra/...`, `.github/...`, `.gitleaks.toml`) que no viajan:
# este script los daba por buenos. Quedan fuera a proposito los absolutos, los
# que llevan esquema (`https:`, `mailto:`: un enlace a la web del fabricante es
# legitimo) y los que solo son un ancla dentro del mismo documento (`#seccion`).
# Las anclas no se comprueban: solo que el fichero exista.

set -euo pipefail
IFS=$'\n\t'

SCRIPT_DIR="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"
readonly SCRIPT_DIR
# shellcheck source-path=SCRIPTDIR
# shellcheck source=lib/exit-codes.sh disable=SC1091
. "${SCRIPT_DIR}/lib/exit-codes.sh"

# Los destinos enlazados de un documento, uno por linea y sin ancla ni consulta.
#
# Un solo patron para los dos casos: `[texto](destino)` y `![alt](destino)`
# terminan igual, asi que la imagen se reconoce por su EXTENSION y no por el
# signo de admiracion. Asi tambien se cazan las capturas enlazadas como texto
# —«ver la captura»— que tambien tienen que viajar. El destino termina en el
# primer espacio: de `[texto](destino "titulo")` se queda `destino`.
#
# El `|| true` final es obligatorio: `grep` sale 1 cuando no encuentra nada, y un
# documento sin un solo enlace es normal. Con `set -e` seria un fallo.
destinos_de() {
  local doc="$1"

  grep -oE '\]\([^)[:space:]]+' "${doc}" 2>/dev/null |
    sed -E 's/^\]\(//; s/[#?].*$//' || true
}

main() {
  local paquete="${1:-}" doc destino base rotos=0 total=0 imagenes=0

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
      # Absoluto o con esquema (`https:`, `mailto:`): fuera del paquete a
      # proposito. Un destino vacio era solo un ancla y ya lo salta el `-n`.
      case "${destino}" in
      /* | [A-Za-z]*:*) continue ;;
      esac

      total=$((total + 1))

      # En minusculas para contar tambien un `.Png`: se comprueba igual que
      # el resto, y el recuento tiene que decir lo mismo que la comprobacion.
      case "${destino,,}" in
      *.png | *.svg | *.jpg | *.jpeg)
        imagenes=$((imagenes + 1))
        ;;
      esac

      if [ ! -e "${base}/${destino}" ]; then
        printf 'ROTO: %s -> %s\n' "${doc}" "${destino}" >&2
        rotos=$((rotos + 1))
      fi
    done < <(destinos_de "${doc}")
  done < <(find "${paquete}" -name '*.md' -type f)

  if [ "${rotos}" -gt 0 ]; then
    printf '\n%d enlace(s) roto(s). Comprobados %d enlaces, %d de ellos imagenes. El cliente no tiene el repositorio: lo que no viaje en el paquete no existe para el.\n' \
      "${rotos}" "${total}" "${imagenes}" >&2
    exit "${KQ_EXIT_VERIFY_FAILED}"
  fi

  printf '%d enlaces comprobados, %d de ellos imagenes; todos resuelven dentro del paquete.\n' "${total}" "${imagenes}"
}

if [ "${BASH_SOURCE[0]}" = "$0" ]; then
  main "$@"
fi
