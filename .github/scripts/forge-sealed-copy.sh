#!/usr/bin/env bash
#
# KronoQR — etapa de simulacro (backup-drill.yml): sella como una copia VALIDA un
# volcado manipulado (ADR-049, A3-01 / A3-R1).
#
# NO ES UN ENTREGABLE: vive en .github/scripts/ y solo lo ejecuta la CI.
#
# El atacante de A3-01 es quien ejecuta codigo en el `scheduler`, que TIENE la clave
# de cifrado: puede dejar en BACKUP_PATH una copia manipulada y bien sellada. Desde la
# 2.2.0 el MAC de KQE1 detiene a quien NO tiene la clave, pero no a este; lo que tiene
# que pararlo es la guarda de roles de restore.sh (salida 7). Para ensayarlo hace falta
# una copia que pase TODA la comprobacion de integridad: KQE1 con la cabecera y el
# nombre correctos, su `.sha256` y un manifiesto con su MAC.
#
# Uso:
#   forge-sealed-copy.sh VOLCADO_EN_CLARO BACKUP_PATH NOMBRE
#
# Escribe BACKUP_PATH/daily/NOMBRE.dump.enc (+ .sha256, .manifest.json y .manifest.mac).
# Necesita BACKUP_ENCRYPTION_KEY en el entorno y un manifiesto previo (el de cualquier
# copia real) del que copiar los conteos.
#
# Codigos de salida: 0 sellada · 1 falta algo · 2 uso incorrecto.

set -euo pipefail
IFS=$'\n\t'

[ "$#" -eq 3 ] || {
  printf 'uso: forge-sealed-copy.sh VOLCADO_EN_CLARO BACKUP_PATH NOMBRE\n' >&2
  exit 2
}

DUMP="$1"
BACKUP_PATH="$2"
NAME="$3"

SCRIPT_DIR="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)"
REPO_DIR="$(cd -- "${SCRIPT_DIR}/../.." && pwd)"
# shellcheck source=../../infra/scripts/lib/kqe.sh disable=SC1091
. "${REPO_DIR}/infra/scripts/lib/kqe.sh"

[ -f "${DUMP}" ] || {
  printf 'forge-sealed-copy: no existe %s\n' "${DUMP}" >&2
  exit 1
}
[ -n "${BACKUP_ENCRYPTION_KEY:-}" ] || {
  printf 'forge-sealed-copy: falta BACKUP_ENCRYPTION_KEY en el entorno\n' >&2
  exit 1
}

daily="${BACKUP_PATH}/daily"
destino="${daily}/${NAME}.dump.enc"
modelo="$(find "${daily}" -maxdepth 1 -type f -name 'kronoqr-*.manifest.json' | sort | tail -n 1)"
[ -n "${modelo}" ] || {
  printf 'forge-sealed-copy: no hay ningun manifiesto en %s del que copiar\n' "${daily}" >&2
  exit 1
}

kqe_encrypt dump "${NAME}" "${destino}" <"${DUMP}"
printf '%s  %s\n' "$(sha256sum "${destino}" | cut -d' ' -f1)" "${NAME}.dump.enc" >"${destino}.sha256"
cp -- "${modelo}" "${daily}/${NAME}.manifest.json"
kqe_manifest_seal "${NAME}" "${daily}/${NAME}.manifest.json" "${daily}/${NAME}.manifest.mac"
printf 'forge-sealed-copy: %s sellada (KQE1, .sha256 y manifiesto autenticado)\n' "${destino}"
