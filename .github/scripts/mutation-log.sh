#!/bin/sh
#
# KronoQR — CI y `make`: lee la salida de Pest Mutate (RQ-10, doc 02 §9.2).
#
# NO ES UN ENTREGABLE: vive en .github/scripts/ y solo lo usan la CI y el Makefile.
#
# Dos modos:
#
#   mutation-log.sh canary ESPERADO FICHERO
#       Comprueba que la pasada del canario (`make mutate-canary`) dio
#       exactamente el reparto ESPERADO, p. ej. «4 untested, 3 tested».
#       R2-QB-06: Pest cuenta como muerto cualquier fallo del proceso hijo,
#       tambien los del entorno, y un MSI del 100 % por un entorno roto no se
#       distingue de uno bueno. Si los mutantes que tienen que sobrevivir
#       mueren, el hijo esta fallando por su cuenta; si los que tienen que
#       morir sobreviven, el mutante no llega al hijo. En los dos casos la
#       mutacion del dominio no mide nada y no se ejecuta.
#
#   mutation-log.sh summary FICHERO [TITULO]
#       Escribe en la salida un resumen en Markdown para GITHUB_STEP_SUMMARY:
#       la puntuacion, el reparto de mutantes y los ficheros con mutantes
#       vivos, de mas a menos (M8: `gh run view --log` corta el log de la
#       mutacion antes de la puntuacion, y el resumen del job era un texto
#       fijo). Informativo: no falla por la puntuacion, eso lo hace `--min`.
#
# FICHERO es la salida de Pest ya capturada, con o sin colores. Con
# `--parallel` Pest no imprime una linea por mutante, solo los vivos con su
# diff; por eso el resumen cuenta vivos por fichero y no da la nota de cada
# uno.
#
# POSIX sh y no bash: lo ejecuta `make`, cuyo sh en la CI es dash.
#
# Codigos de salida: 0 bien · 1 el canario no dio lo esperado, o no se
# encontro el reparto · 2 uso incorrecto.

set -eu
# El IFS se compone con printf: dash no entiende $'\n\t'.
IFS="$(printf '\n\t')"

uso() {
  printf 'uso: mutation-log.sh canary ESPERADO FICHERO | summary FICHERO [TITULO]\n' >&2
  exit 2
}

# La salida sin colores y sin espacios repetidos.
limpia() {
  esc=$(printf '\033')
  sed "s/${esc}\[[0-9;]*[A-Za-z]//g" "$1" | tr -s ' '
}

# La ultima linea «Mutations: ...» de Pest, sin el rotulo; vacia si no esta.
reparto() {
  limpia "$1" | grep -E '^ ?Mutations: ' | tail -n 1 | sed 's/^ \{0,1\}Mutations: //; s/ *$//' || true
}

[ "$#" -ge 2 ] || uso
modo="$1"

case "$modo" in
canary)
  [ "$#" -eq 3 ] || uso
  esperado="$2"
  fichero="$3"
  [ -r "$fichero" ] || {
    printf '[mutate-canary] ERROR: no se puede leer %s\n' "$fichero" >&2
    exit 2
  }
  obtenido=$(reparto "$fichero")
  if [ -z "$obtenido" ]; then
    printf '[mutate-canary] ERROR: la salida no tiene la linea «Mutations:» de Pest; no se puede comprobar el canario.\n' >&2
    printf '[mutate-canary] Si el formato de Pest ha cambiado, adapta .github/scripts/mutation-log.sh; no se aprueba a ciegas.\n' >&2
    exit 1
  fi
  if [ "$obtenido" != "$esperado" ]; then
    printf '[mutate-canary] ERROR: el canario ha dado «%s» y tiene que dar «%s».\n' "$obtenido" "$esperado" >&2
    printf '[mutate-canary] Con mas «tested» de lo esperado, el proceso hijo falla por su cuenta (memoria, una extension, una opcion de Pest) y cada mutante del dominio contaria como muerto sin serlo (R2-QB-06).\n' >&2
    printf '[mutate-canary] Con mas «untested», el mutante no llega al hijo (OPcache, tools/mutation/zzz-no-opcache.ini) y el MSI mediria el codigo sin mutar.\n' >&2
    printf '[mutate-canary] Si has cambiado a proposito tests/MutationCanary o los mutadores de Pest, ajusta MUTATION_CANARY_EXPECTED en el Makefile.\n' >&2
    exit 1
  fi
  printf '[mutate-canary] Canario correcto: %s. La pasada distingue un mutante muerto de uno vivo.\n' "$obtenido"
  ;;
summary)
  [ "$#" -ge 2 ] && [ "$#" -le 3 ] || uso
  fichero="$2"
  titulo="${3:-Mutacion (RQ-10)}"
  [ -r "$fichero" ] || {
    printf '[mutation-log] ERROR: no se puede leer %s\n' "$fichero" >&2
    exit 2
  }
  nota=$(limpia "$fichero" | grep -E '^ ?Score: ' | tail -n 1 | sed 's/^ \{0,1\}Score: //; s/ *$//' || true)
  generados=$(limpia "$fichero" | grep -E 'Mutations for [0-9]+ Files created' | tail -n 1 | sed 's/^ *//; s/ *$//' || true)
  mutantes=$(reparto "$fichero")
  canario=$(limpia "$fichero" | sed -n 's/^\[mutate-canary\] Canario correcto: \([^.]*\)\..*/\1/p' | tail -n 1)
  printf '### %s\n\n' "$titulo"
  # Si el canario falla, el log lleva SU salida, y su puntuacion no es la del
  # dominio: se dice eso y nada mas.
  if limpia "$fichero" | grep -q '^\[mutate-canary\] ERROR'; then
    printf '**El canario ha fallado**: la mutacion del dominio no se ha ejecutado. El motivo esta en el log (artefacto del job).\n'
    exit 0
  fi
  if limpia "$fichero" | grep -q 'No mutations created'; then
    printf 'Pest no ha creado ningun mutante (%sNo mutations created%s).\n' '`' '`'
    exit 0
  fi
  if [ -z "$generados" ]; then
    motivo=$(limpia "$fichero" | grep -E '^\[make\]' | tail -n 1 || true)
    printf 'No se ha mutado nada en esta pasada. %s\n' "${motivo:-El log completo va en el artefacto del job.}"
    exit 0
  fi
  if [ -z "$nota" ]; then
    printf '**Sin puntuacion**: la pasada no llego al final. El log completo va en el artefacto del job.\n'
    exit 0
  fi
  printf '| | |\n|---|---|\n'
  printf '| Puntuacion (MSI) | **%s** |\n' "$nota"
  printf '| Canario | %s |\n' "${canario:-no consta en el log}"
  printf '| Mutantes | %s |\n' "${mutantes:-?}"
  printf '| Generados | %s |\n\n' "${generados:-?}"
  vivos=$(limpia "$fichero" | sed -n 's/^ \{0,1\}UNTESTED \([^ ]*\) .*/\1/p' | sort | uniq -c | sort -k1,1nr -k2,2 || true)
  if [ -z "$vivos" ]; then
    printf 'Ningun mutante vivo.\n'
    exit 0
  fi
  printf 'Ficheros con mutantes vivos, de mas a menos. El diff de cada uno esta en el artefacto del job.\n\n'
  printf '| Vivos | Fichero |\n|---:|---|\n'
  printf '%s\n' "$vivos" | awk '{ printf "| %s | `%s` |\n", $1, $2 }'
  ;;
*) uso ;;
esac
