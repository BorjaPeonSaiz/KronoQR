#!/usr/bin/env bash
#
# KronoQR — arma el PAQUETE DE ENTREGA (doc 02 §11.6.1) en un directorio.
#
# Es lo que recibe el IT del hotel: compose de produccion, plantilla de
# configuracion, los scripts de operacion con su biblioteca, la observabilidad,
# la matriz de versiones y la documentacion de cliente con sus runbooks. Lo usa
# la etapa ⑧ de la CI para instalar y actualizar DESDE EL PAQUETE y no desde el
# arbol del repositorio: si el instalador dependiera de algo que solo existe
# aqui, es la unica forma de verlo.
#
# Hasta la tarea 5.7 esta lista vivia inline en .github/workflows/ci.yml. Al
# necesitarla dos jobs —instalacion limpia y actualizacion, y este ultimo dos
# veces, para la version anterior y para la nueva— se saco a un script: dos
# copias de la lista son la forma segura de que el paquete que se prueba y el
# que se entrega difieran sin que nadie lo note.
#
# Uso:
#   infra/scripts/package.sh DESTINO      crea DESTINO (debe no existir o estar vacio)
#
# Se ejecuta desde la raiz del repositorio o desde cualquier sitio: las rutas
# se resuelven desde la ubicacion de este fichero.
#
# Codigos de salida (tabla PROPIA, como changelog.sh: es una herramienta del
# repositorio, no un entregable, y la CI espera «1 = ha fallado»):
#   0  paquete armado
#   1  falta un fichero obligatorio del paquete, o el destino no esta vacio
#   2  error de uso
#
# LO QUE NUNCA ENTRA, y se comprueba al final: `tools/` (el emisor de licencias
# firma con la clave privada del fabricante y no tiene nada que hacer en el
# servidor de un hotel; §7.7, RS-08), ningun `.env` con valores, y ningun
# fichero del repositorio que no este en la lista.

set -euo pipefail
IFS=$'\n\t'

SCRIPT_DIR="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"
readonly SCRIPT_DIR
REPO_ROOT="$(cd -- "${SCRIPT_DIR}/../.." && pwd)"
readonly REPO_ROOT

err() {
  printf 'package.sh: %s\n' "$*" >&2
}

die() {
  local code="$1"
  shift
  err "$@"
  exit "${code}"
}

[ "$#" -eq 1 ] || die 2 "uso: package.sh DESTINO"
case "$1" in
-h | --help)
  sed -n '2,/^$/p' "${BASH_SOURCE[0]}" | sed 's/^# \{0,1\}//'
  exit 0
  ;;
esac

DEST="$1"
if [ -e "${DEST}" ] && [ -n "$(ls -A "${DEST}" 2>/dev/null)" ]; then
  die 1 "el destino '${DEST}' existe y no esta vacio. Elige otro o vacialo: este script no borra nada."
fi

# Lo obligatorio: si falta, el paquete no vale y se dice cual.
required=(
  infra/compose.prod.yaml
  .env.example
  VERSION
  infra/versions.txt
  infra/scripts/install.sh
  infra/scripts/update.sh
  infra/scripts/backup.sh
  infra/scripts/restore.sh
  infra/scripts/restore-drill.sh
  infra/scripts/lib
  infra/observability
  docs/cliente
  docs/runbooks
  CHANGELOG.md
)
for item in "${required[@]}"; do
  [ -e "${REPO_ROOT}/${item}" ] || die 1 "falta '${item}' en el repositorio: sin el, el paquete esta incompleto."
done

mkdir -p "${DEST}/certs" "${DEST}/docs"

cp "${REPO_ROOT}/infra/compose.prod.yaml" "${DEST}/docker-compose.yml"
cp "${REPO_ROOT}/.env.example" "${DEST}/.env.example"
cp "${REPO_ROOT}/VERSION" "${DEST}/VERSION"
cp "${REPO_ROOT}/infra/versions.txt" "${DEST}/versions.txt"
for script in install update backup restore restore-drill; do
  cp "${REPO_ROOT}/infra/scripts/${script}.sh" "${DEST}/${script}.sh"
  chmod +x "${DEST}/${script}.sh"
done
cp -r "${REPO_ROOT}/infra/scripts/lib" "${DEST}/lib"
cp -r "${REPO_ROOT}/infra/observability" "${DEST}/observability"
# LA DOCUMENTACION MANTIENE EL LAYOUT DEL REPOSITORIO —docs/cliente/ y
# docs/runbooks/— y no se aplana: las guias enlazan a los runbooks con rutas
# `../runbooks/...`, y check-package-links.sh comprueba que ninguna sale del
# paquete.
cp -r "${REPO_ROOT}/docs/cliente" "${DEST}/docs/cliente"
cp -r "${REPO_ROOT}/docs/runbooks" "${DEST}/docs/runbooks"
# El registro de cambios viaja con el paquete: update.sh remite a el para
# saber que cambia ANTES de tocar el servidor (doc 02 §10.5).
cp "${REPO_ROOT}/CHANGELOG.md" "${DEST}/docs/CHANGELOG.md"
# LICENCIA.txt: su texto lo valida una asesoria antes de la primera venta
# (doc 08 §1.4). Mientras no exista en el repositorio, no se inventa.
if [ -f "${REPO_ROOT}/LICENCIA.txt" ]; then
  cp "${REPO_ROOT}/LICENCIA.txt" "${DEST}/LICENCIA.txt"
fi

# Lo que no puede estar, comprobado y no supuesto.
[ ! -e "${DEST}/tools" ] || die 1 "'tools/' ha acabado dentro del paquete: el emisor de licencias es del fabricante (§7.7, RS-08)."
[ ! -e "${DEST}/.env" ] || die 1 "hay un '.env' dentro del paquete: el paquete se entrega SIN configuracion rellenada."

printf 'Paquete de entrega %s armado en %s\n' "$(tr -d '[:space:]' <"${DEST}/VERSION")" "${DEST}"
