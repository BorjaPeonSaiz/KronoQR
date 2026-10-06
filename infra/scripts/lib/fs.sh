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

# Un directorio en el que root puede ESCRIBIR POR RUTA sin que el runtime pueda
# plantarle nada (A3-R2): cada tramo de la ruta, hasta `/`, es un directorio REAL
# (ni enlaces simbolicos en el camino), del que ejecuta (o de root), SIN permiso
# de escritura para otros —sin excepciones para el bit `sticky`: un `/tmp` no es
# de fiar para un registro con datos personales— y, si tiene escritura de GRUPO,
# solo para un grupo del sistema (gid < 1000) al que no pertenezcan ni el
# runtime (gid 1000) ni la cuenta uid 1000 del anfitrion. `kq_path_trusted DIR`
# acepta una ruta que todavia no existe: se valida el ancestro existente mas
# cercano. Devuelve 0 si es de fiar, 1 si no, y deja en `KQ_PATH_UNTRUSTED` el
# tramo que no acepto (vacio si acepto todos) para que el mensaje lo nombre.
#
# POR QUE EXISTE. `ensure_update_log_dir` solo comprobaba el propio directorio: un
# `KRONOQR_LOG_DIR` bajo un padre que escribe otro usuario reabria el vector
# (el otro cambia el directorio por un enlace) aunque el directorio fuera de root.
#
# POR QUE LA ESCRITURA DE GRUPO SE ACEPTA EN UN GRUPO DEL SISTEMA. En Ubuntu
# `/var/log` es `root:syslog 0775` (en Debian, `root:root 0755`): con la regla
# «nunca escritura de grupo», `update.sh` se negaba a actualizar en todo Ubuntu
# con el valor por defecto `/var/log/kronoqr`, y `doctor.sh` nunca purgaba el
# detalle (C19). A3-R2 protege de lo que pueda hacer el RUNTIME (uid/gid 1000 o
# lo que corra en su contenedor): un tramo en el que solo escribe un grupo del
# sistema ajeno a ese uid no le da ninguna forma de cambiar el directorio por un
# enlace. Residuo aceptado (dictamen de seguridad del bloque 20, doc 07 §6
# A3-R2): un miembro de ese grupo del sistema —en Ubuntu, el demonio rsyslog—
# comprometido podria hacerlo; es otro servicio del anfitrion y queda fuera de
# la amenaza. Lo que no se acepta nunca: escritura para otros, un grupo con
# gid >= 1000, o un grupo del sistema (`adm`, por ejemplo) al que el instalador
# del servidor haya metido a la cuenta uid 1000.
# shellcheck disable=SC2034  # la lee update.sh para nombrar el tramo rechazado
KQ_PATH_UNTRUSTED=""

# shellcheck disable=SC2034
kq_path_trusted() {
  local path="$1" real up_to me
  me="$(id -u)"
  KQ_PATH_UNTRUSTED=""

  # Normaliza: sin barra final (salvo `/`), y absoluta.
  case "${path}" in
  /*) ;;
  *)
    KQ_PATH_UNTRUSTED="${path}"
    return 1
    ;;
  esac
  [ "${path}" = "/" ] || path="${path%/}"

  up_to="$(kq_existing_ancestor "${path}")"
  # Ningun tramo existente puede ser (ni pasar por) un enlace simbolico.
  real="$(cd -- "${up_to}" 2>/dev/null && pwd -P)" || real=""
  if [ "${real}" != "${up_to}" ]; then
    KQ_PATH_UNTRUSTED="${up_to}"
    return 1
  fi

  while :; do
    if ! kq_path_segment_trusted "${up_to}" "${me}"; then
      KQ_PATH_UNTRUSTED="${up_to}"
      return 1
    fi
    [ "${up_to}" != "/" ] || break
    up_to="$(dirname -- "${up_to}")"
  done
  return 0
}

# Un tramo de `kq_path_trusted`: directorio real, de root o de quien ejecuta,
# sin escritura para otros y, con escritura de grupo, solo un grupo del sistema
# ajeno al uid 1000.
kq_path_segment_trusted() {
  local dir="$1" me="$2" owner group mode

  [ -d "${dir}" ] && [ ! -L "${dir}" ] || return 1
  owner="$(stat -c '%u' -- "${dir}" 2>/dev/null)" || return 1
  group="$(stat -c '%g' -- "${dir}" 2>/dev/null)" || return 1
  mode="$(stat -c '%a' -- "${dir}" 2>/dev/null)" || return 1
  [[ "${owner}" =~ ^[0-9]+$ ]] && [[ "${group}" =~ ^[0-9]+$ ]] && [[ "${mode}" =~ ^[0-7]+$ ]] || return 1
  [ "${owner}" = "0" ] || [ "${owner}" = "${me}" ] || return 1
  # Nunca escritura para otros (tampoco `1777`: el sticky no protege de un rename
  # del propio dueño del enlace que se planta).
  [ "$((8#${mode} & 8#002))" -eq 0 ] || return 1
  if [ "$((8#${mode} & 8#020))" -ne 0 ]; then
    kq_system_group_without_app "${group}" || return 1
  fi
  return 0
}

# Un grupo del sistema (gid < 1000) al que NO pertenece la cuenta uid 1000 del
# anfitrion. El gid 1000 (el del runtime) queda fuera por la primera condicion.
# Si no hay cuenta con uid 1000, no pertenece a ninguno.
kq_system_group_without_app() {
  local gid="$1" grupos g
  [[ "${gid}" =~ ^[0-9]+$ ]] && [ "${gid}" -lt 1000 ] || return 1
  # `id -G` separa por espacios y el IFS de esta biblioteca no los incluye.
  local IFS=' '
  read -r -a grupos <<<"$(id -G 1000 2>/dev/null || true)"
  for g in "${grupos[@]}"; do
    [ "${g}" != "${gid}" ] || return 1
  done
  return 0
}
