#!/usr/bin/env bash
#
# KronoQR — lleva al directorio nuevo los cambios que el cliente hizo en
# `observability/` (umbrales, destinatarios de Alertmanager, cuadros de mando).
#
# NO SE EJECUTA SOLO: lo carga update.sh (y package.sh, para la huella). Hasta la
# 2.2.0 el paquete nuevo traia su propio `observability/` y `update.sh` no copiaba
# el del cliente: cada actualizacion le devolvia los umbrales de serie sin
# avisar, y la guia le pedia volver a aplicarlos a mano (operacion.md §9).
#
# EL PROBLEMA ES DE TRES VIAS. Para saber si un fichero lo toco el cliente o
# lo cambio el paquete hace falta el fichero tal como lo entrego el paquete
# ANTERIOR, y ese no esta en ninguna parte: el directorio anterior es justo el que
# el cliente edito. Por eso package.sh deja junto al paquete `observability.sha256`
# (la huella de cada fichero tal como se entrega) y esta biblioteca decide, por
# cada fichero del cliente:
#
#   B = su huella en el paquete anterior   C = la que tiene ahora en el cliente
#   N = la que trae el paquete nuevo
#
#   C = B            lo cambio nadie: manda el paquete nuevo (no se hace nada).
#   C = N            ya es igual: nada.
#   C != B, N = B    SOLO lo cambio el cliente: se lleva al directorio nuevo.
#   C != B, N != B   lo cambiaron los dos (o el paquete lo ha retirado): GANA EL
#                    PAQUETE, se avisa, y la copia del cliente sigue, intacta, en
#                    el directorio anterior.
#   sin B, sin N     un fichero que el cliente anadio (B ausente): se lleva.
#
# Sin `observability.sha256` en el paquete anterior (hasta la 2.2.0) no hay B: no se
# puede saber quien toco que. Se lleva NADA, gana el paquete en todo lo que difiere
# y se avisa una vez, sin falsa precision: «puede que lo cambiaras tu».
#
# NUNCA PIERDE NADA EN SILENCIO: lo que no se lleva, queda donde estaba y el aviso
# dice donde. Y NUNCA aborta una actualizacion: la configuracion de observabilidad
# no vale una vuelta atras.
#
# CONTRATO
#
#   kq_obs_manifest DIR              imprime `<sha256>  <ruta relativa>` por fichero.
#   kq_obs_merge ANTERIOR NUEVO HUELLA
#       ANTERIOR  `observability/` del cliente (directorio anterior)
#       NUEVO     `observability/` del paquete nuevo
#       HUELLA    `observability.sha256` del paquete anterior, o vacio
#     Rellena KQ_OBS_BASELINE (1 si hay huella), KQ_OBS_CARRIED (llevados) y
#     KQ_OBS_CONFLICTS (no llevados). Devuelve 0 salvo fallo de copia (1).

set -euo pipefail
IFS=$'\n\t'

KQ_OBS_BASELINE=0
KQ_OBS_CARRIED=()
KQ_OBS_CONFLICTS=()

kq_obs_sha256() {
  sha256sum -- "$1" | cut -d' ' -f1
}

kq_obs_manifest() {
  local dir="$1" file
  [ -d "${dir}" ] || return 0
  while IFS= read -r -d '' file; do
    printf '%s  %s\n' "$(kq_obs_sha256 "${dir}/${file#./}")" "${file#./}"
  done < <(cd -- "${dir}" && find . -type f -print0 | LC_ALL=C sort -z)
}

kq_obs_merge() {
  local old_dir="$1" new_dir="$2" baseline_file="${3:-}"
  local -A baseline=()
  local line hash path file current theirs before status=0

  KQ_OBS_BASELINE=0
  KQ_OBS_CARRIED=()
  KQ_OBS_CONFLICTS=()

  [ -d "${old_dir}" ] && [ -d "${new_dir}" ] || return 0

  if [ -n "${baseline_file}" ] && [ -f "${baseline_file}" ]; then
    KQ_OBS_BASELINE=1
    while IFS= read -r line; do
      hash="${line%%  *}"
      path="${line#*  }"
      [ -n "${path}" ] && baseline["${path}"]="${hash}"
    done <"${baseline_file}"
  fi

  while IFS= read -r -d '' file; do
    file="${file#./}"
    current="$(kq_obs_sha256 "${old_dir}/${file}")"
    theirs=""
    [ ! -f "${new_dir}/${file}" ] || theirs="$(kq_obs_sha256 "${new_dir}/${file}")"

    # Ya es igual: no hay nada que llevar ni que avisar.
    [ "${current}" != "${theirs}" ] || continue

    if [ "${KQ_OBS_BASELINE}" -eq 0 ]; then
      KQ_OBS_CONFLICTS+=("${file}")
      continue
    fi

    before="${baseline[${file}]:-}"
    # Sin cambios del cliente: manda el paquete nuevo.
    [ "${current}" != "${before}" ] || continue

    # El cliente lo cambio (o lo anadio). Si el paquete no lo ha tocado, gana el
    # cliente; si lo ha tocado o lo ha retirado, gana el paquete.
    if { [ -z "${before}" ] && [ -z "${theirs}" ]; } || { [ -n "${theirs}" ] && [ "${theirs}" = "${before}" ]; }; then
      if install -d "$(dirname -- "${new_dir}/${file}")" && cp -p -- "${old_dir}/${file}" "${new_dir}/${file}"; then
        KQ_OBS_CARRIED+=("${file}")
      else
        KQ_OBS_CONFLICTS+=("${file}")
        status=1
      fi
    else
      KQ_OBS_CONFLICTS+=("${file}")
    fi
  done < <(cd -- "${old_dir}" && find . -type f -print0 | LC_ALL=C sort -z)

  return "${status}"
}

# «a, b, c ...» con un tope, para que el aviso quepa en pantalla.
kq_obs_list() {
  local max="$1" shown="" count=0 item
  shift
  for item in "$@"; do
    count=$((count + 1))
    [ "${count}" -le "${max}" ] || continue
    shown="${shown}${shown:+, }${item}"
  done
  [ "${count}" -le "${max}" ] || shown="${shown}, ..."
  printf '%s' "${shown}"
}
