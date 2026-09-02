#!/usr/bin/env bash
#
# KronoQR — tabla UNICA de codigos de salida de los scripts de operacion.
#
# NO SE EJECUTA SOLO: lo cargan install.sh, update.sh, doctor.sh, backup.sh y
# restore.sh. Existe porque los cinco los ejecuta la MISMA persona —el IT del
# hotel, a veces con una incidencia en marcha— y un `3` que significa "hay una
# instalacion previa" en uno y "falta una herramienta" en otro es una trampa
# para quien encadena estos scripts en un cron o en un runbook.
#
# LA REGLA QUE LO GOBIERNA: el codigo dice EN QUE FASE se paro y QUE QUEDO
# ESCRITO. Nada mas. El detalle va en el mensaje, que es lo que se lee.
#
#   0  Correcto.
#
#   1  USO INCORRECTO. Argumento desconocido, combinacion imposible, falta un
#      valor obligatorio en la linea de ordenes. NADA se ha tocado.
#
#   2  REQUISITOS NO CUMPLIDOS. La comprobacion previa ha fallado: falta una
#      herramienta, no hay espacio, un puerto esta ocupado, falta una clave.
#      NADA se ha escrito. La maquina esta como estaba.
#
#   3  ESTADO PREVIO INCOMPATIBLE. Lo que se iba a hacer ya esta hecho, o hay
#      algo que impide hacerlo sin destruir. NADA se ha escrito.
#        · install.sh  ya hay una instalacion: para actualizar, update.sh
#        · update.sh   la version instalada ya es la de destino
#        · backup.sh   ya hay una copia en curso (candado tomado)
#        · restore.sh  la base de destino existe, o quedan conexiones abiertas
#        · doctor.sh   no hay instalacion que diagnosticar
#
#   4  FALLO CON VUELTA ATRAS COMPLETADA. Algo salio mal a mitad y el script
#      DESHIZO lo que habia hecho en esta ejecucion. La maquina vuelve a estar
#      como antes de ejecutarlo. Se puede reintentar tras corregir la causa.
#
#   5  FALLO CON VUELTA ATRAS INCOMPLETA. Algo salio mal y el script NO pudo
#      deshacerlo todo. HAY QUE INTERVENIR A MANO, y el mensaje dice
#      exactamente que ha quedado a medias y que orden lo arregla. Es el unico
#      codigo que exige una persona delante.
#
#   6  VERIFICACION POSTERIOR FALLIDA. El trabajo se hizo, los servicios estan
#      en pie, pero la comprobacion final no paso. NO se deshace nada: deshacer
#      una instalacion que quiza solo tiene el certificado mal seria peor. El
#      mensaje dice que comprobar.
#
# Por que 4 y 5 son dos codigos y no uno: son dos llamadas de telefono
# distintas. Con un 4 el IT reintenta; con un 5 alguien tiene que mirar el
# servidor antes de volver a ejecutar nada. Colapsarlos obligaria a leerse el
# log entero para saber cual de las dos cosas paso.
#
# Estos codigos son parte del CONTRATO del producto: estan publicados en
# docs/cliente/operacion.md y en docs/cliente/instalacion.md. Cambiar el
# significado de uno rompe los cron y los runbooks del cliente.

# Las siete constantes las consumen los scripts que CARGAN este fichero, no el
# fichero en si: ShellCheck no lo puede ver y las daria por muertas una a una.
# La supresion es de FICHERO porque aqui solo viven esas constantes y una
# funcion, asi que no puede tapar una variable muerta de verdad.
# shellcheck disable=SC2034

set -euo pipefail
IFS=$'\n\t'

readonly KQ_EXIT_OK=0
readonly KQ_EXIT_USAGE=1
readonly KQ_EXIT_REQUIREMENTS=2
readonly KQ_EXIT_STATE_CONFLICT=3
readonly KQ_EXIT_ROLLED_BACK=4
readonly KQ_EXIT_ROLLBACK_INCOMPLETE=5
readonly KQ_EXIT_VERIFY_FAILED=6

# Idioma de los nombres de codigo.
#
# Se resuelve AQUI y no en lib/messages.sh a proposito: este fichero lo cargan
# tambien backup.sh, restore.sh y restore-drill.sh, que no cargan el catalogo
# del instalador. Si el nombre del codigo dependiera de messages.sh, los tres
# scripts de copia se quedarian sin el o habria que arrastrarles un catalogo
# entero para siete cadenas.
#
# Si install.sh ya ha fijado KQ_LANG (por --lang o por el entorno), manda ese;
# si no, se mira la configuracion regional del sistema. Un idioma desconocido
# cae a espanol: un servidor con LANG=fr_FR no debe quedarse sin mensaje.
kq_exit_lang() {
  local requested="${KQ_LANG:-}"

  [ -n "${requested}" ] || requested="${KRONOQR_LANG:-}"

  if [ -z "${requested}" ]; then
    requested="${LC_ALL:-${LC_MESSAGES:-${LANG:-es}}}"
    requested="${requested%%_*}"
    requested="${requested%%.*}"
  fi

  case "${requested}" in
  en) printf 'en' ;;
  *) printf 'es' ;;
  esac
}

# Nombre legible de un codigo, para los informes y para los mensajes finales.
# Se usa en la salida de los cinco scripts: quien lea "salida 4 (fallo con
# vuelta atras completada)" no tiene que buscar la tabla.
kq_exit_name() {
  if [ "$(kq_exit_lang)" = "en" ]; then
    case "${1}" in
    0) printf 'success' ;;
    1) printf 'wrong usage' ;;
    2) printf 'requirements not met, nothing written' ;;
    3) printf 'incompatible previous state, nothing written' ;;
    4) printf 'failure, rolled back' ;;
    5) printf 'failure, rollback INCOMPLETE, needs intervention' ;;
    6) printf 'post-run verification failed, services up' ;;
    *) printf 'undocumented code' ;;
    esac
    return 0
  fi

  case "${1}" in
  0) printf 'correcto' ;;
  1) printf 'uso incorrecto' ;;
  2) printf 'requisitos no cumplidos, nada escrito' ;;
  3) printf 'estado previo incompatible, nada escrito' ;;
  4) printf 'fallo con vuelta atras completada' ;;
  5) printf 'fallo con vuelta atras INCOMPLETA, requiere intervencion' ;;
  6) printf 'verificacion posterior fallida, servicios en pie' ;;
  *) printf 'codigo no documentado' ;;
  esac
}
