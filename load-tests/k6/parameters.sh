#!/usr/bin/env bash
#
# Lectura de los parametros de la prueba de carga, compartida por `run.sh` y
# por `.github/workflows/load-test.yml` (bloque 15 de la 2.2.1).
#
# POR QUE UN SOLO ANALIZADOR. DURATION e INSTANCES entran desde fuera —un
# input del workflow, una variable de entorno— y acaban en aritmetica de bash
# (`$(( ))`), que EVALUA lo que recibe: `a[$(orden)]` dentro de una expansion
# aritmetica ejecuta la orden. Validar el formato antes de cualquier cuenta
# cierra esa puerta, y hacerlo en un solo sitio evita que el aviso previo del
# workflow y el lanzador lean «2m» de dos maneras distintas.
#
# Se carga con `.` y no define nada fuera de estas dos funciones.
#
# PREAMBULO COMO EN `infra/scripts/lib/`, Y NO EXCEPCION COMO LOS `.envsh`. Un
# fichero cargado impone su `set` y su `IFS` a quien lo carga. Los `.envsh` se
# eximen porque los carga el punto de entrada de la imagen de Nginx, que no es
# codigo nuestro. Aqui los dos cargadores SI lo son, y los dos corren ya con
# estas mismas garantias: `run.sh` declara exactamente este preambulo, y el
# paso del workflow corre con `set -euo pipefail` y solo itera sobre palabras
# literales y cadenas entrecomilladas, que no dependen de `IFS`. Repetirlo aqui
# no cambia nada a quien carga y deja el fichero dentro de `make sh-lint`.
set -euo pipefail
IFS=$'\n\t'

# Un entero sin signo, en decimal. Devuelve 1 si no lo es.
k6_is_count() {
  case "$1" in
  '' | *[!0-9]*) return 1 ;;
  *) return 0 ;;
  esac
}

# Segundos de una duracion de k6 (`120`, `120s`, `2m`, `1h`, `500ms`), por la
# salida estandar. Devuelve 1, sin escribir nada, si el formato no es ese.
k6_seconds_of() {
  local value="$1" number unit

  number="${value%%[!0-9]*}"
  unit="${value#"${number}"}"

  if ! k6_is_count "${number}"; then
    return 1
  fi

  # Base 10 explicita: «08» no es un octal invalido, es ocho.
  number=$((10#${number}))

  case "${unit}" in
  '' | s) echo "${number}" ;;
  ms) echo $((number / 1000)) ;;
  m) echo $((number * 60)) ;;
  h) echo $((number * 3600)) ;;
  *) return 1 ;;
  esac
}
