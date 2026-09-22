#!/usr/bin/env bash
#
# KronoQR — ¿conoce la aplicacion instalada un comando de artisan?
#
# NO SE EJECUTA SOLO: lo cargan update.sh y el paso U3 de la etapa ⑧b de la CI
# (.github/workflows/ci.yml). Existe porque la MISMA pregunta —«la version que
# esta en pie, ¿sabe ejecutar este comando?»— se hacia en dos sitios con dos
# copias de la misma linea, y las dos eran la misma trampa.
#
# LA TRAMPA. La forma evidente de preguntarlo es
#
#     compose exec -T app php artisan list --raw | grep -q '^comando'
#
# y esa tuberia MIENTE. `grep -q` cierra su entrada en cuanto encuentra la
# linea; el cliente de Docker, que todavia esta volcando el resto del catalogo
# (16 KiB, ~194 lineas, y los comandos van ordenados: `compliance:*` sale por
# la linea 35), recibe EPIPE, escribe «write /dev/stdout: broken pipe» en su
# error estandar y termina con 1. Con `set -o pipefail` la tuberia entera vale
# 1 y la respuesta es NO justo cuando la respuesta era SI. Medido en esta
# maquina: 5 de 40 invocaciones seguidas, y 2 de 30 con el error a la vista.
# Ademas el `2>/dev/null` con el que se solia acompañar borra la unica pista de
# que ha pasado eso, y deja «no lo conoce» y «no he podido preguntar» con la
# misma cara.
#
# POR ESO, aqui: la salida se captura ENTERA en una variable —nadie cierra
# ningun tubo—, la comparacion es por campo exacto (no una expresion regular
# sobre un nombre con `:`), el error estandar viaja de vuelta en
# `KQ_APP_COMMAND_ERROR` en lugar de irse a /dev/null, y «no he podido
# preguntar» tiene un codigo propio: quien llama decide, y nunca lo confunde
# con «no lo conoce».
#
# CONTRATO
#
#   kq_app_knows_command <funcion_compose> <comando>
#
#     0  la instalacion CONOCE el comando.
#     1  la instalacion NO lo conoce: artisan respondio y el nombre no esta.
#     2  NO SE HA PODIDO PREGUNTAR tras `KQ_APP_COMMAND_ATTEMPTS` intentos.
#        El motivo queda en `KQ_APP_COMMAND_ERROR`. NUNCA se traduce a 1: un
#        contenedor que todavia no responde no es una version sin el comando.
#
#   La funcion de compose se pasa por NOMBRE (`compose_rollback`, `compose`) y
#   se invoca aqui, para que la pregunta se haga siempre contra la pila que
#   quien llama tiene en pie.

set -euo pipefail
IFS=$'\n\t'

# Reintentos: `artisan list` se pregunta justo despues de levantar una pila, y
# el contenedor puede estar sano para Compose y todavia no aceptar `exec`. Se
# espera por CONDICION —que responda—, no por `sleep` a ciegas.
: "${KQ_APP_COMMAND_ATTEMPTS:=5}"
: "${KQ_APP_COMMAND_RETRY_SECONDS:=3}"

# Ultimo error de `kq_app_knows_command`. Vacio si la pregunta se pudo hacer.
# Lo lee el script que carga esta biblioteca, no esta biblioteca.
# shellcheck disable=SC2034
KQ_APP_COMMAND_ERROR=""

# shellcheck disable=SC2034  # KQ_APP_COMMAND_ERROR lo consume quien carga la biblioteca.
kq_app_knows_command() {
  local compose_fn="$1" command_name="$2"
  local attempt=1 status output

  KQ_APP_COMMAND_ERROR=""

  while [ "${attempt}" -le "${KQ_APP_COMMAND_ATTEMPTS}" ]; do
    status=0
    # Sin tuberia y sin 2>/dev/null: ver la cabecera. El error estandar se une
    # a la salida a proposito —asi se puede contar QUE fallo sin ficheros
    # temporales— y no contamina la respuesta porque la comparacion de abajo
    # es por campo exacto.
    output="$("${compose_fn}" exec -T app php artisan list --raw 2>&1)" || status=$?

    if [ "${status}" -eq 0 ]; then
      if printf '%s\n' "${output}" |
        awk -v wanted="${command_name}" '$1 == wanted { found = 1 } END { exit found ? 0 : 1 }'; then
        return 0
      fi
      return 1
    fi

    KQ_APP_COMMAND_ERROR="$(printf '%s' "${output}" | tr '\n' ' ' | cut -c 1-400)"
    attempt=$((attempt + 1))
    # `if` explicito y no `[ ... ] && sleep`: esa forma deja el cuerpo del
    # bucle terminando en 1 en el ultimo intento, y con `set -e` eso depende
    # de una exencion sutil de bash que no conviene en un script que corre en
    # el servidor de un cliente.
    if [ "${attempt}" -le "${KQ_APP_COMMAND_ATTEMPTS}" ]; then
      sleep "${KQ_APP_COMMAND_RETRY_SECONDS}"
    fi
  done

  return 2
}
