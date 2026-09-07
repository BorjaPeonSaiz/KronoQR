#!/usr/bin/env bash
#
# KronoQR — etapa ⑧ de la CI: ningun secreto del .env aparece en las salidas.
#
# NO ES UN ENTREGABLE: vive en .github/scripts/ y solo lo ejecuta la CI.
#
# Es la unica forma honesta de comprobar «cero secretos en la salida» (§3.5,
# RS-08): se extrae cada valor del .env que el instalador acaba de escribir y
# se busca LITERALMENTE en cada fichero de salida o informe. Buscar «algo que
# parezca una clave» daria verde con cualquier fuga real.
#
# Hasta la tarea 5.7 el bucle vivia inline en ci.yml y se ejecutaba solo tras
# el camino feliz de la instalacion. El actualizador tiene un camino de vuelta
# atras que vuelca `compose ps`, logs y la salida de restore.sh a su fichero de
# detalle, y ese camino es justo el que no se revisaba.
#
# Uso:
#   assert-no-secrets.sh RUTA_ENV FICHERO...    (los ficheros se leen con sudo)
#
# Codigos de salida: 0 sin fugas · 1 fuga o fichero ausente · 2 uso incorrecto.

set -euo pipefail
IFS=$'\n\t'

[ "$#" -ge 2 ] || {
  printf 'uso: assert-no-secrets.sh RUTA_ENV FICHERO...\n' >&2
  exit 2
}

ENV_FILE="$1"
shift

readonly -a KEYS=(
  APP_KEY
  QR_SIGNING_KEY_CURRENT
  BACKUP_ENCRYPTION_KEY
  REVERB_APP_SECRET
  DB_PASSWORD
  DB_MIGRATION_PASSWORD
  IDENTITY_PIN_SEALING_SECRET_KEY
  GRAFANA_ADMIN_PASSWORD
)

status=0
for file in "$@"; do
  if ! sudo test -f "${file}"; then
    printf 'assert-no-secrets: no existe %s\n' "${file}" >&2
    status=1
    continue
  fi
  for key in "${KEYS[@]}"; do
    value="$(sudo sed -n "s/^${key}=//p" "${ENV_FILE}" | head -1)"
    if [ -z "${value}" ]; then
      printf 'assert-no-secrets: %s esta vacio en %s\n' "${key}" "${ENV_FILE}" >&2
      status=1
      continue
    fi
    if sudo grep -qF -- "${value}" "${file}"; then
      printf 'FUGA: el valor de %s aparece en %s\n' "${key}" "${file}" >&2
      status=1
    fi
  done
  printf 'assert-no-secrets: %s revisado (%d claves)\n' "${file}" "${#KEYS[@]}"
done

exit "${status}"
