#!/usr/bin/env bash
#
# KronoQR — lectura de un fichero .env, UNA sola vez para todos los scripts.
#
# NO SE EJECUTA SOLO: lo cargan install.sh y lib/backup-common.sh (y por el,
# backup.sh, restore.sh y restore-drill.sh).
#
# POR QUE NO SE HACE `source .env`. Seria ejecucion de codigo arbitrario con
# los permisos de quien instala o de quien lanza la copia nocturna. Aqui se
# parsea linea a linea y no se ejecuta nada.
#
# POR QUE ESTA EN UN FICHERO PROPIO. Hasta la tarea 5.4 habia DOS lectores:
# `env_value` en install.sh y `load_env_file` en backup-common.sh, y no leian
# igual. Dos ejemplos medidos sobre el mismo fichero:
#
#   BACKUP_PATH="/srv/copias"       (comillas y espacio final)
#   BACKUP_PATH=/srv/copias#1       (almohadilla SIN espacio delante)
#
# El primero daba `/srv/copias` en uno y `/srv/copias"` en el otro; el segundo,
# `/srv/copias` en uno y `/srv/copias#1` en el otro. La consecuencia no es
# academica: el instalador crearia el arbol de copias en un sitio y la copia
# nocturna lo buscaria en otro, y el sintoma —«la copia no aparece»— no se
# parece a su causa.
#
# LA REGLA DE COMENTARIOS ES LA DE DOCKER COMPOSE, a proposito: el mismo .env
# lo lee Compose para levantar los servicios, y una tercera interpretacion
# distinta seria peor que las dos que habia. Compose solo trata `#` como
# comentario cuando va PRECEDIDO DE ESPACIO; `/srv/copias#1` es la ruta entera,
# almohadilla incluida. Comprobado contra `docker compose config`.

set -euo pipefail
IFS=$'\n\t'

# Valor de una clave, sin ejecutar el fichero. Cadena vacia si no esta.
#
#   kq_env_value RUTA CLAVE
kq_env_value() {
  local file="$1" key="$2" line value

  [ -f "${file}" ] || return 0

  while IFS= read -r line || [ -n "${line}" ]; do
    case "${line}" in
    '' | '#'*) continue ;;
    esac

    [[ "${line}" =~ ^[[:space:]]*(export[[:space:]]+)?"${key}"=(.*)$ ]] || continue

    printf '%s' "$(kq_env_unquote "${BASH_REMATCH[2]}")"
    return 0
  done <"${file}"

  return 0
}

# Quita comillas envolventes, comentario final y espacios sobrantes. Es la
# unica pieza que interpreta un valor, y por eso la comparten los dos usos.
kq_env_unquote() {
  local value="$1"

  # Entre comillas, el valor es literal: ni comentario ni recorte por dentro.
  if [[ "${value}" =~ ^[[:space:]]*\"(.*)\"[[:space:]]*$ ]] ||
    [[ "${value}" =~ ^[[:space:]]*\'(.*)\'[[:space:]]*$ ]]; then
    printf '%s' "${BASH_REMATCH[1]}"
    return 0
  fi

  # Sin comillas: comentario solo si la almohadilla va tras un espacio (regla
  # de Docker Compose). Despues, espacios de los dos extremos.
  value="${value%%[[:space:]]#*}"
  value="${value#"${value%%[![:space:]]*}"}"
  value="${value%"${value##*[![:space:]]}"}"

  printf '%s' "${value}"
}

# Define en el entorno lo que el fichero traiga y todavia no venga de fuera.
# Lo que ya esta en el entorno GANA: es lo que permite pasar configuracion por
# variables sin que el fichero la pise.
kq_env_load() {
  local file="$1" line key

  [ -f "${file}" ] || return 0

  while IFS= read -r line || [ -n "${line}" ]; do
    case "${line}" in
    '' | '#'*) continue ;;
    esac

    [[ "${line}" =~ ^[[:space:]]*(export[[:space:]]+)?([A-Za-z_][A-Za-z0-9_]*)=(.*)$ ]] || continue

    key="${BASH_REMATCH[2]}"
    [ -n "${!key-}" ] || printf -v "${key}" '%s' "$(kq_env_unquote "${BASH_REMATCH[3]}")"
  done <"${file}"
}
