#!/bin/sh
#
# KronoQR — CI y `make test-unit`: falla si una suite de Pest tarda mas que su
# presupuesto (doc 02 §9.2), o si no se puede leer cuanto ha tardado.
#
# NO ES UN ENTREGABLE: vive en .github/scripts/ y solo lo usan la CI y el Makefile.
#
# Por que existe. La comprobacion vivia en linea dentro de `test-unit`, y si no
# encontraba la linea «Duration:» de Pest imprimia un AVISO y aprobaba
# (R2-QA-04): bastaba con que Pest cambiara el formato para que el presupuesto
# dejara de vigilarse con la CI en verde. Ahora no leer la duracion es un
# fallo, igual que en pest-max-skipped.sh. Y como script se puede probar:
# UnitSuiteBudgetTest lo ejecuta con salidas de Pest fabricadas.
#
# POSIX sh y no bash: lo ejecuta `make`, cuyo sh en la CI es dash.
#
#   pest-duration-budget.sh MAXIMO FICHERO
#
# MAXIMO en segundos (admite decimales, con punto). FICHERO, la salida de Pest
# ya capturada, con o sin colores.
#
# Codigos de salida: 0 dentro del presupuesto · 1 por encima, o duracion
# ilegible · 2 uso incorrecto.

set -eu
# El IFS se compone con printf: dash no entiende $'\n\t'.
IFS="$(printf '\n\t')"

if [ "$#" -ne 2 ]; then
  printf 'uso: pest-duration-budget.sh MAXIMO FICHERO\n' >&2
  exit 2
fi

maximo="$1"
salida="$2"

if [ ! -r "$salida" ]; then
  printf '[make] No se puede leer la salida de la suite: %s\n' "$salida" >&2
  exit 2
fi

# Los colores de Pest van en secuencias ESC[...m entre «Duration:» y la cifra:
# se quitan antes de buscarla. Se toma la ultima linea por si la suite imprime
# mas de un resumen.
esc=$(printf '\033')
dur=$(sed "s/${esc}\[[0-9;]*m//g" "$salida" | grep 'Duration:' | tail -n 1 |
  sed -n 's/.*Duration:[[:space:]]*\([0-9][0-9]*\(\.[0-9][0-9]*\)\{0,1\}\)s.*/\1/p')

if [ -z "$dur" ]; then
  echo "[make] No se ha podido leer la duracion de la suite (la linea «Duration:» de Pest no aparece o ha cambiado de formato)."
  echo "[make] El presupuesto de $maximo s no se puede comprobar, y una puerta que no comprueba no aprueba."
  exit 1
fi

if awk -v dur="$dur" -v max="$maximo" 'BEGIN { exit !(dur + 0 > max + 0) }'; then
  echo "[make] La suite unitaria ha tardado $dur s y el presupuesto es $maximo s (doc 02 §9.2)."
  echo "[make] Una suite unitaria lenta deja de ejecutarse en cada cambio, que es su unica razon de ser."
  exit 1
fi

echo "[make] Suite unitaria en $dur s, dentro del presupuesto de $maximo s."
