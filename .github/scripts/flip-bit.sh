#!/usr/bin/env bash
#
# KronoQR — cambia UN bit de un fichero, en una posicion elegida por una semilla (V5-DV-01).
#
# NO ES UN ENTREGABLE: vive en .github/scripts/ y solo lo ejecuta la CI.
#
# POR QUE EXISTE. Las pruebas de «un bit cambiado» (backup-drill.yml, backup-wal-e2e.sh)
# escribian una constante (`Z`) en una posicion fija. Si el byte original ya era `Z`, el
# fichero no cambiaba y la prueba esperaba salida 6 y recibia 0: dos fallos intermitentes en
# 40 ejecuciones, uno en `main`, con el mismo arbol que una rama verde. Aqui el byte se lee y
# se escribe su XOR con un bit: el fichero CAMBIA siempre.
#
# Y la posicion ya no es la misma en cada ejecucion: una alteracion en un sitio concreto que
# nadie ha probado es justo la que un MAC mal cubierto dejaria pasar. Se sortea dentro de la
# region pedida con una semilla que SE IMPRIME, para poder repetir un fallo.
#
# Uso:
#   flip-bit.sh FICHERO REGION [SEMILLA]
#
#   REGION   header (la linea de cabecera KQE1), body (entre la cabecera y los ultimos 32
#            bytes) o trailer (los ultimos 32 bytes)
#   SEMILLA  entero. Por omision KQ_BITFLIP_SEED y, sin ella, uno aleatorio
#
# Imprime una linea: `flip-bit: FICHERO region=R pos=P bit=B semilla=S`. Para repetir:
# KQ_BITFLIP_SEED=S con el mismo fichero (la posicion depende de la semilla y del tamano).
#
# Codigos de salida: 0 bit cambiado · 1 el fichero no sirve · 2 uso incorrecto.

set -euo pipefail
IFS=$'\n\t'

if [ "$#" -lt 2 ] || [ "$#" -gt 3 ]; then
  printf 'uso: flip-bit.sh FICHERO header|body|trailer [SEMILLA]\n' >&2
  exit 2
fi

fichero="$1"
region="$2"
semilla="${3:-${KQ_BITFLIP_SEED:-$((RANDOM * 32768 + RANDOM))}}"

if [ ! -f "$fichero" ]; then
  printf 'flip-bit: no existe %s\n' "$fichero" >&2
  exit 1
fi
if [[ ! "$semilla" =~ ^[0-9]+$ ]]; then
  printf 'flip-bit: la semilla debe ser un entero y es «%s»\n' "$semilla" >&2
  exit 2
fi

tamano="$(stat -c %s "$fichero")"
cabecera="$(head -n 1 "$fichero" | wc -c | tr -d '[:space:]')"
if [ "$tamano" -lt $((cabecera + 40)) ]; then
  printf 'flip-bit: %s es demasiado corto (%s bytes) para tener cabecera, cuerpo y cola\n' "$fichero" "$tamano" >&2
  exit 1
fi

case "$region" in
header)
  # Sin el salto de linea final: cambiarlo no es una alteracion «de contenido».
  min=0
  max=$((cabecera - 2))
  ;;
body)
  min="$cabecera"
  max=$((tamano - 33))
  ;;
trailer)
  min=$((tamano - 32))
  max=$((tamano - 1))
  ;;
*)
  printf 'flip-bit: region «%s» desconocida (header, body o trailer)\n' "$region" >&2
  exit 2
  ;;
esac

posicion="$(awk -v semilla="$semilla" -v min="$min" -v max="$max" 'BEGIN { srand(semilla); print min + int(rand() * (max - min + 1)) }')"
bit="$(awk -v semilla="$semilla" 'BEGIN { srand(semilla + 1); print int(rand() * 8) }')"

original="$(dd if="$fichero" bs=1 skip="$posicion" count=1 status=none | od -An -tu1 | tr -d '[:space:]')"
nuevo=$((original ^ (1 << bit)))
# shellcheck disable=SC2059 # el formato octal construido es lo que se quiere interpretar.
printf "$(printf '\%03o' "$nuevo")" | dd of="$fichero" bs=1 seek="$posicion" conv=notrunc status=none

printf 'flip-bit: %s region=%s pos=%s bit=%s semilla=%s\n' "$fichero" "$region" "$posicion" "$bit" "$semilla"
