#!/usr/bin/env bash
#
# KronoQR — espacio en disco, en un solo sitio.
#
# NO SE EJECUTA SOLO: lo cargan install.sh y lib/backup-common.sh.
#
# Habia tres invocaciones distintas de `df -Pk` repartidas por los scripts —dos
# en la biblioteca de copia y una en el instalador—, cada una con su propio
# `awk` y su propia unidad. No es un problema de estetica: el dia que alguien
# corrija una (por ejemplo, para tolerar un `df` que parte la salida en dos
# lineas cuando el dispositivo es largo, cosa que hace) corregira una de tres y
# las otras dos seguiran mintiendo sobre el espacio libre justo antes de una
# copia.

set -euo pipefail
IFS=$'\n\t'

# El ancestro existente mas cercano de una ruta.
#
# Hace falta porque al instalar se pregunta por el espacio de un directorio que
# TODAVIA NO EXISTE: `df` sobre una ruta inexistente falla, y la respuesta util
# es la del sistema de ficheros donde acabara estando.
kq_existing_ancestor() {
  local probe="$1"

  while [ ! -d "${probe}" ] && [ "${probe}" != "/" ]; do
    probe="$(dirname -- "${probe}")"
  done

  printf '%s' "${probe}"
}

# Bytes libres en el sistema de ficheros que contiene la ruta.
kq_free_bytes() {
  df -Pk "$(kq_existing_ancestor "$1")" 2>/dev/null | awk 'NR==2 {print $4 * 1024}'
}

# Bytes totales del sistema de ficheros que contiene la ruta.
kq_total_bytes() {
  df -Pk "$(kq_existing_ancestor "$1")" 2>/dev/null | awk 'NR==2 {print $2 * 1024}'
}

# GiB libres, redondeados a la baja. Es la unidad en la que estan publicados los
# requisitos de servidor (doc 02 §11.6.2), asi que es la que se compara.
kq_free_gib() {
  local bytes
  bytes="$(kq_free_bytes "$1")"
  printf '%d' "$((${bytes:-0} / 1073741824))"
}
