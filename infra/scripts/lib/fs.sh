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

# Escritura ATOMICA de un fichero de metricas para el colector textfile de
# node-exporter (doc 02 §8.2): temporal en el mismo directorio + `mv`, mismo
# criterio que `write_metrics` de `lib/backup-common.sh`. Vive aqui y no alli
# porque la llama tambien `update.sh` (tarea 3.2, ventana de mantenimiento),
# que no carga `backup-common.sh` -esa biblioteca asume la configuracion de
# copia cargada, y update.sh solo necesita escribir dos lineas de texto-.
#
# 0644: legible por CUALQUIER uid, no solo por el propietario. Hace falta
# porque quien escribe no es siempre el mismo: `backup.sh`/`restore-drill.sh`
# corren como el uid 1000 del contenedor `app`, pero `update.sh` corre en el
# ANFITRION, normalmente como root; node-exporter siempre lee como uid 1000.
# Sin el permiso de "otros", un fichero escrito por root no lo veria nadie mas.
kq_write_metrics_atomic() {
  local file="$1" tmp
  tmp="${file}.$$.tmp"
  cat >"$tmp"
  chmod 0644 "$tmp"
  mv -f "$tmp" "$file"
}
