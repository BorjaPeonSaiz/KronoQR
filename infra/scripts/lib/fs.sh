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
#
# SI SE EJECUTA COMO ROOT, SE ESCRIBE COMO EL UID DE LA APLICACION (ADR-045,
# hallazgo F1 de la revision del bloque 16). El directorio de metricas es 1000
# y el runtime lo escribe: un `cat >tmp` o un `chmod` como root sobre un nombre
# predecible (`<fichero>.<pid>.tmp`) siguen un enlace simbolico plantado desde
# el contenedor y truncan o cambian el modo de cualquier fichero del anfitrion.
# Como uid 1000 un enlace plantado no da nada que ese uid no pudiera ya hacer.
# Sin `setpriv` no se escribe nada (la metrica es de cortesia y el llamador
# ignora el fallo) en vez de escribir como root.
kq_write_metrics_atomic() {
  local file="$1" tmp
  tmp="${file}.$$.tmp"
  if [ "$(id -u)" = "0" ]; then
    if ! command -v setpriv >/dev/null 2>&1; then
      cat >/dev/null
      return 1
    fi
    # shellcheck disable=SC2016 # `$1` y `$2` son del `sh -c`, no de este script.
    setpriv --reuid=1000 --regid=1000 --clear-groups \
      sh -c 'umask 022 && cat >"$1" && mv -f "$1" "$2"' sh "$tmp" "$file"
    return
  fi
  cat >"$tmp"
  chmod 0644 "$tmp"
  mv -f "$tmp" "$file"
}

# Copia el contenido de un fichero local (que solo root puede leer) a un
# destino que escribe el runtime, COMO EL UID DE LA APLICACION y SIN
# SOBRESCRIBIR. `kq_publish_as_app ORIGEN DESTINO UMASK`. Es la operacion que
# sustituye a `cp` + `chmod` + `chown` por ruta como root: el dueño sale del uid
# con el que se escribe, el modo de la mascara (027 = 0640, 077 = 0600), y no
# hay ninguna operacion posterior por ruta. `set -C` crea con O_EXCL: si el
# destino existe, sea un fichero o un enlace, la copia falla en vez de seguirlo.
# Devuelve 2 si hay que ser root y no hay `setpriv`.
kq_publish_as_app() {
  local source_file="$1" dest="$2" mask="$3"
  if [ "$(id -u)" = "0" ]; then
    command -v setpriv >/dev/null 2>&1 || return 2
    # shellcheck disable=SC2016 # `$1` y `$2` son del `sh -c`, no de este script.
    setpriv --reuid=1000 --regid=1000 --clear-groups \
      sh -c 'set -C && umask "$2" && cat >"$1"' sh "$dest" "$mask" <"$source_file"
    return
  fi
  (
    set -C
    umask "$mask"
    cat >"$dest"
  ) <"$source_file"
}

# Crea UN directorio de la aplicacion (0750, dueño 1000:1000) bajo un arbol que
# escribe el runtime —BACKUP_PATH—, COMO EL UID DE LA APLICACION y sin `-p`.
# `kq_ensure_app_dir DIR`; si lo crea, deja `KQ_DIR_CREATED=1`.
#
# POR QUE NO `install -d -o 1000` COMO ROOT (ronda 4 del bloque 16). El runtime
# es dueño de BACKUP_PATH y puede dejar `reports` como enlace simbolico a donde
# quiera: `[ -d reports ]` lo da por bueno y `install -d .../reports/retention`
# como root creaba un directorio 1000:1000 en el destino del enlace. Ahora root
# no crea nada ahi: lo crea el uid 1000, de modo que un enlace plantado solo le
# da lo que ese uid ya podia hacer, y un enlace que YA existe se rechaza.
#
# Devuelve: 0 existe o creado · 1 no se pudo crear · 2 hace falta `setpriv`
# (somos root y no esta) · 3 existe y es un enlace o no es un directorio.
# shellcheck disable=SC2034 # lo leen los llamadores.
KQ_DIR_CREATED=0
kq_ensure_app_dir() {
  local dir="$1"
  KQ_DIR_CREATED=0
  if [ -L "${dir}" ]; then
    return 3
  fi
  if [ -d "${dir}" ]; then
    return 0
  fi
  [ ! -e "${dir}" ] || return 3
  if [ "$(id -u)" = "0" ]; then
    command -v setpriv >/dev/null 2>&1 || return 2
    setpriv --reuid=1000 --regid=1000 --clear-groups mkdir -m 0750 -- "${dir}" 2>/dev/null || return 1
  else
    mkdir -m 0750 -- "${dir}" 2>/dev/null || return 1
  fi
  # Lo creado tiene que ser un directorio real, no un enlace colocado en medio.
  if [ -L "${dir}" ] || [ ! -d "${dir}" ]; then
    return 3
  fi
  # shellcheck disable=SC2034
  KQ_DIR_CREATED=1
  return 0
}
