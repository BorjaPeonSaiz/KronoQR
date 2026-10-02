#!/usr/bin/env bash
#
# KronoQR — CI y `make`: falla si una suite de Pest omite mas pruebas de las
# esperadas (R6-DV-07, R2-QA-01).
#
# NO ES UN ENTREGABLE: vive en .github/scripts/ y solo lo usan la CI y el Makefile.
#
# Por que existe. Una prueba `skipped` ni falla ni avisa, y `qa:traceability` la
# cuenta como cobertura. 24 pruebas de Integracion salieron omitidas en cada run
# de la CI durante semanas sin que nadie lo viera. La puerta es un MAXIMO, no un
# «cero»: hay omisiones conocidas y declaradas (`FpmPoolRenderTest`), y lo que se
# quiere impedir es que aparezca una nueva, o que Chrome deje de arrancar, sin
# que nadie lo note.
#
# Dos modos, para no repetir la logica en cada objetivo del Makefile:
#
#   pest-max-skipped.sh run   MAXIMO ORDEN   ejecuta ORDEN (con `bash -c`, asi que
#                                            puede llevar `cd x && ...`), deja su
#                                            salida en vivo y comprueba el maximo
#   pest-max-skipped.sh check MAXIMO FICHERO comprueba el maximo sobre una salida
#                                            de Pest ya capturada
#
# Se lee la linea final de Pest («Tests:  24 skipped, 6737 passed ...»). Si no
# aparece, la puerta FALLA: no se aprueba a ciegas si el formato cambia.
#
# Codigos de salida: 0 dentro del maximo · 1 se omiten mas de lo esperado, o no
# se encontro el resumen · 2 uso incorrecto · otro: el de la propia suite.

set -euo pipefail
IFS=$'\n\t'

uso() {
  printf 'uso: pest-max-skipped.sh run MAXIMO ORDEN | check MAXIMO FICHERO\n' >&2
  exit 2
}

[ "$#" -eq 3 ] || uso
modo="$1"
maximo="$2"
objetivo="$3"
case "${maximo}" in
'' | *[!0-9]*) uso ;;
esac

comprobar() {
  local fichero="$1" resumen omitidas
  # Sin colores: en la CI no hay terminal, pero en local si puede haberla.
  resumen="$(sed 's/\x1b\[[0-9;]*[A-Za-z]//g' "${fichero}" | grep -E '^ *Tests:' | tail -n 1 || true)"
  if [ -z "${resumen}" ]; then
    printf '[pest-max-skipped] ERROR: no se encontro la linea «Tests:» de Pest; no se puede contar las pruebas omitidas.\n' >&2
    printf '[pest-max-skipped] Si el formato de salida de Pest ha cambiado, adapta este script; no se aprueba a ciegas.\n' >&2
    exit 1
  fi
  omitidas="$(printf '%s\n' "${resumen}" | grep -oE '[0-9]+ skipped' | grep -oE '[0-9]+' || true)"
  omitidas="${omitidas:-0}"
  if [ "${omitidas}" -gt "${maximo}" ]; then
    printf '[pest-max-skipped] ERROR: la suite ha omitido %s pruebas y lo esperado son como mucho %s.\n' "${omitidas}" "${maximo}" >&2
    printf '[pest-max-skipped] Una prueba omitida no falla, pero tampoco prueba nada. Las nuevas se ven con: php artisan test --display-skipped\n' >&2
    printf '[pest-max-skipped] Causas habituales: Chrome que no arranca (accion .github/actions/chrome-for-pdf) o un ->skip() nuevo.\n' >&2
    exit 1
  fi
  printf '[pest-max-skipped] Pruebas omitidas: %s (maximo permitido %s).\n' "${omitidas}" "${maximo}"
}

case "${modo}" in
check)
  [ -f "${objetivo}" ] || {
    printf '[pest-max-skipped] ERROR: no existe %s\n' "${objetivo}" >&2
    exit 2
  }
  comprobar "${objetivo}"
  ;;
run)
  log="$(mktemp)"
  trap 'rm -f "${log}"' EXIT
  # `tee` para ver la salida en vivo: la suite tarda minutos y, si el job
  # muere por tiempo, el log tiene que haber llegado hasta donde llego.
  set +e
  bash -c "${objetivo}" 2>&1 | tee "${log}"
  estado="${PIPESTATUS[0]}"
  set -e
  [ "${estado}" -eq 0 ] || exit "${estado}"
  comprobar "${log}"
  ;;
*) uso ;;
esac
