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
#       Las pasadas por fichero de `make mutate-changed` (entre
#       «[mutate-file] >>>» y «[mutate-file] <<<») no cuentan para la nota
#       ni para los vivos del conjunto: salen en una tabla propia.
#
#   mutation-log.sh total MINIMO FICHERO...
#       La nota GLOBAL de la mutacion completa nocturna, que se reparte en
#       partes (`make mutate-part`, una matriz de jobs en ci.yml). Cada
#       FICHERO es el log de una parte; suma sus mutantes (muertos = tested +
#       timeout, la formula de Pest), escribe el resumen en Markdown y sale con
#       1 si el conjunto queda por debajo de MINIMO, si ninguna parte genero
#       mutantes o si falta el log de alguna parte o no llego al final. Es el
#       mismo umbral de RQ-10 sobre los mismos mutantes, no uno por parte.
#
# FICHERO es la salida de Pest ya capturada, con o sin colores. Con
# `--parallel` Pest no imprime una linea por mutante, solo los vivos con su
# diff; por eso el resumen cuenta vivos por fichero y no da la nota de cada
# uno.
#
# POSIX sh y no bash: lo ejecuta `make`, cuyo sh en la CI es dash.
#
# Codigos de salida: 0 bien · 1 el canario no dio lo esperado, o no se
# encontro el reparto (en `total`, tambien: MSI global bajo el minimo) · 2 uso incorrecto.

set -eu
# El IFS se compone con printf: dash no entiende $'\n\t'.
IFS="$(printf '\n\t')"

uso() {
  printf 'uso: mutation-log.sh canary ESPERADO FICHERO | summary FICHERO [TITULO] | total MINIMO FICHERO...\n' >&2
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

# El resumen de la pasada del conjunto: FICHERO ya limpio y sin las pasadas
# por fichero.
resumen_conjunto() {
  nota=$(grep -E '^ ?Score: ' "$1" | tail -n 1 | sed 's/^ \{0,1\}Score: //; s/ *$//' || true)
  generados=$(grep -E 'Mutations for [0-9]+ Files created' "$1" | tail -n 1 | sed 's/^ *//; s/ *$//' || true)
  mutantes=$(reparto "$1")
  canario=$(sed -n 's/^\[mutate-canary\] Canario correcto: \([^.]*\)\..*/\1/p' "$1" | tail -n 1)
  if grep -q 'No mutations created' "$1"; then
    printf 'Pest no ha creado ningun mutante (%sNo mutations created%s).\n' '`' '`'
    motivo=$(grep -E '^\[make\]' "$1" | tail -n 1 || true)
    [ -z "$motivo" ] || printf '%s\n' "$motivo"
    return 0
  fi
  if [ -z "$generados" ]; then
    motivo=$(grep -E '^\[make\]' "$1" | tail -n 1 || true)
    printf 'No se ha mutado nada en esta pasada. %s\n' "${motivo:-El log completo va en el artefacto del job.}"
    return 0
  fi
  if [ -z "$nota" ]; then
    printf '**Sin puntuacion**: la pasada no llego al final. El log completo va en el artefacto del job.\n'
    return 0
  fi
  printf '| | |\n|---|---|\n'
  printf '| Puntuacion (MSI) | **%s** |\n' "$nota"
  printf '| Canario | %s |\n' "${canario:-no consta en el log}"
  printf '| Mutantes | %s |\n' "${mutantes:-?}"
  printf '| Generados | %s |\n\n' "${generados:-?}"
  vivos=$(sed -n 's/^ \{0,1\}UNTESTED \([^ ]*\) .*/\1/p' "$1" | sort | uniq -c | sort -k1,1nr -k2,2 || true)
  if [ -z "$vivos" ]; then
    printf 'Ningun mutante vivo.\n'
    return 0
  fi
  printf 'Ficheros con mutantes vivos, de mas a menos. El diff de cada uno esta en el artefacto del job.\n\n'
  printf '| Vivos | Fichero |\n|---:|---|\n'
  printf '%s\n' "$vivos" | awk '{ printf "| %s | `%s` |\n", $1, $2 }'
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
  limpio=$(mktemp)
  conjunto=$(mktemp)
  trap 'rm -f "$limpio" "$conjunto"' EXIT
  limpia "$fichero" >"$limpio"
  # Las pasadas por fichero de `make mutate-changed` van entre
  # «[mutate-file] >>>» y «[mutate-file] <<<». Fuera de ellas queda la pasada
  # del conjunto, que es la que da la nota, el reparto y los vivos; cada
  # fichero sale despues en su propia tabla.
  sed '/^\[mutate-file\] >>> /,/^\[mutate-file\] <<< /d' "$limpio" >"$conjunto"
  printf '### %s\n\n' "$titulo"
  # Si el canario falla, el log lleva SU salida, y su puntuacion no es la del
  # dominio: se dice eso y nada mas.
  if grep -q '^\[mutate-canary\] ERROR' "$conjunto"; then
    printf '**El canario ha fallado**: la mutacion del dominio no se ha ejecutado. El motivo esta en el log (artefacto del job).\n'
    exit 0
  fi
  resumen_conjunto "$conjunto"
  aviso=$(sed -n 's/^\[mutate-file\] AVISO: //p' "$limpio" | tail -n 1)
  if [ -n "$aviso" ]; then
    printf '\n**Sin pasadas por fichero.** %s\n' "$aviso"
  fi
  por_fichero=$(awk '
    function fila(f, n, e) { printf "| `%s` | %s | %s |\n", f, n, e }
    /^\[mutate-file\] >>> / { if (f != "") fila(f, nota, "sin terminar"); f = $3; nota = "-"; next }
    f != "" && /^ ?Score: / { nota = $0; sub(/^ ?Score: /, "", nota); sub(/ *$/, "", nota); next }
    /^\[mutate-file\] <<< / {
      linea = $0; sub(/^\[mutate-file\] <<< /, "", linea); i = index(linea, ": ")
      fila(substr(linea, 1, i - 1), nota, substr(linea, i + 2)); f = ""; next
    }
    END { if (f != "") fila(f, nota, "sin terminar") }
  ' "$limpio")
  if [ -n "$por_fichero" ]; then
    printf '\nCada fichero por separado (minimo propio, %sMUTATE_FILE_MIN%s):\n\n' '`' '`'
    printf '| Fichero | Puntuacion | Estado |\n|---|---:|---|\n'
    printf '%s\n' "$por_fichero"
  fi
  ;;
total)
  # La nota GLOBAL de la mutacion completa nocturna, repartida en partes
  # (`make mutate-part`): suma los mutantes de cada parte y aplica el minimo al
  # conjunto, con la formula de Pest: (tested + timeout) / todos. Es el mismo
  # umbral de RQ-10 sobre los mismos mutantes, no uno por parte.
  [ "$#" -ge 3 ] || uso
  minimo="$2"
  shift 2
  case "$minimo" in '' | *[!0-9]*) uso ;; esac
  suma_total=0
  suma_muertos=0
  fallo=0
  filas=""
  vivos=$(mktemp)
  trap 'rm -f "$vivos"' EXIT
  for fichero in "$@"; do
    nombre=$(basename "$fichero" .log)
    if [ ! -r "$fichero" ]; then
      printf '[mutation-log] ERROR: falta el log de la parte %s (%s): la parte no termino o no subio su artefacto.\n' "$nombre" "$fichero" >&2
      fallo=1
      continue
    fi
    linea=$(reparto "$fichero")
    if [ -z "$linea" ]; then
      printf '[mutation-log] ERROR: la parte %s no tiene la linea «Mutations:» de Pest: no llego al final. Mira su log en el artefacto.\n' "$nombre" >&2
      fallo=1
      continue
    fi
    cuenta=$(printf '%s\n' "$linea" | awk -F', ' '
      { for (i = 1; i <= NF; i++) {
          split($i, p, " "); n = p[1] + 0; etiqueta = p[2]
          if (etiqueta == "tested" || etiqueta == "timeout") { muertos += n; total += n }
          else if (etiqueta == "untested" || etiqueta == "uncovered" || etiqueta == "pending") { total += n }
          else { desconocida = 1 }
      } }
      END { if (desconocida) print "ERR"; else print total + 0, muertos + 0 }')
    if [ "$cuenta" = "ERR" ]; then
      printf '[mutation-log] ERROR: la parte %s tiene un estado de mutante desconocido en «%s». Si Pest ha cambiado el formato, adapta .github/scripts/mutation-log.sh; no se aprueba a ciegas.\n' "$nombre" "$linea" >&2
      fallo=1
      continue
    fi
    parte_total=${cuenta% *}
    parte_muertos=${cuenta#* }
    suma_total=$((suma_total + parte_total))
    suma_muertos=$((suma_muertos + parte_muertos))
    filas="${filas}$(printf '| %s | %s | %s |' "$nombre" "$parte_muertos" "$parte_total")
"
    limpia "$fichero" | sed -n 's/^ \{0,1\}UNTESTED \([^ ]*\) .*/\1/p' >>"$vivos"
  done
  printf '### Mutacion completa (RQ-10)\n\n'
  if [ -n "$filas" ]; then
    printf '| Parte | Muertos (tested + timeout) | Mutantes |\n|---|---:|---:|\n'
    printf '%s' "$filas"
    printf '\n'
  fi
  if [ "$fallo" -ne 0 ]; then
    printf '**Sin puntuacion global**: alguna parte no llego al final (detalle en el log del job y en el artefacto de esa parte).\n'
    exit 1
  fi
  if [ "$suma_total" -eq 0 ]; then
    printf '**Sin puntuacion global**: ninguna parte ha generado mutantes. Pest daria la pasada por suspensa; aqui tambien.\n'
    exit 1
  fi
  nota=$(awk -v m="$suma_muertos" -v t="$suma_total" 'BEGIN { printf "%.2f", m * 100 / t }')
  printf '| | |\n|---|---|\n'
  printf '| Puntuacion global (MSI) | **%s %%** |\n' "$nota"
  printf '| Minimo exigido | %s %% |\n' "$minimo"
  printf '| Mutantes (todas las partes) | %s, de ellos %s muertos |\n\n' "$suma_total" "$suma_muertos"
  if [ -s "$vivos" ]; then
    printf 'Ficheros con mutantes vivos, de mas a menos. El diff de cada uno esta en el artefacto de su parte.\n\n'
    printf '| Vivos | Fichero |\n|---:|---|\n'
    sort "$vivos" | uniq -c | sort -k1,1nr -k2,2 | awk '{ printf "| %s | `%s` |\n", $1, $2 }'
  else
    printf 'Ningun mutante vivo.\n'
  fi
  if [ $((suma_muertos * 100)) -lt $((minimo * suma_total)) ]; then
    printf '\n**Por debajo del minimo**: Mutation score below expected: %s %%. Minimum: %s %%.\n' "$nota" "$minimo"
    printf '[mutation-log] ERROR: MSI global %s %% por debajo del %s %% (RQ-10). Se escribe la prueba que mata los mutantes vivos de arriba; no se baja el umbral.\n' "$nota" "$minimo" >&2
    exit 1
  fi
  ;;
*) uso ;;
esac
