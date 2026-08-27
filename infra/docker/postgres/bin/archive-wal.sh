#!/usr/bin/env bash
#
# KronoQR — archivado de un segmento de WAL (RNF-D-02: RPO <= 15 min).
#
# Lo invoca PostgreSQL, no una persona: es el `archive_command` que declara
# infra/compose.prod.yaml. Se ejecuta una vez por segmento de WAL y, con
# `archive_timeout=900`, al menos una vez cada 15 minutos aunque no haya
# trafico. Eso es lo que acota la perdida maxima de datos a 15 minutos.
#
#   archive-wal.sh <ruta_del_segmento (%p)> <nombre_del_segmento (%f)>
#
# Contrato de `archive_command`, y por que cada linea de este script existe:
#
#   · Devolver 0 SOLO si el segmento esta a salvo. PostgreSQL borra el original
#     en cuanto este script dice que si; mentir aqui es perder datos en
#     silencio hasta el dia de la restauracion.
#   · NUNCA sobrescribir un segmento ya archivado. Si existe y es identico
#     —reintento tras un corte— se acepta; si existe y es distinto, se rechaza
#     y se avisa: dos segmentos con el mismo nombre y contenido distinto
#     significan que dos servidores estan archivando en el mismo destino.
#   · Escribir de forma atomica: temporal + `mv` en el mismo sistema de
#     ficheros. Un segmento a medias es peor que un segmento que falta, porque
#     no se nota hasta que se reproduce.
#
# Los segmentos se guardan comprimidos (`.gz`). Un WAL recien rotado es en su
# mayor parte relleno: comprimir divide por diez el espacio y el disco lleno es
# el modo de fallo mas frecuente del archivado. Al restaurar se descomprime, y
# el `restore_command` esta en docs/runbooks/restaurar-backup.md.
#
# Configuracion (variables de entorno del servicio postgres):
#   KRONOQR_WAL_ARCHIVE_DIR      destino. Por defecto /var/backups/fichaje/wal
#   KRONOQR_WAL_RETENTION_DAYS   dias de WAL conservado. Por defecto 8
#
# La retencion de WAL DEBE ser mayor que el intervalo entre copias fisicas
# (`backup.sh run --mode base`): sin la copia fisica anterior, el WAL archivado
# no reconstruye nada.
#
# Codigos de salida: 0 archivado (o ya estaba, identico) · 1 no se ha podido
# archivar; PostgreSQL reintentara y conservara el segmento.
#
# NINGUN SECRETO EN LA SALIDA: lo que imprime va al log del servidor de base de
# datos, que lee el IT del cliente.

set -euo pipefail
IFS=$'\n\t'

readonly DESTINO="${KRONOQR_WAL_ARCHIVE_DIR:-/var/backups/fichaje/wal}"
readonly RETENCION="${KRONOQR_WAL_RETENTION_DAYS:-8}"
readonly MARCA_PURGA="${DESTINO}/.last-prune"

err() {
  printf 'kronoqr-archive-wal: %s\n' "$*" >&2
}

if [ "$#" -ne 2 ]; then
  err "uso: archive-wal.sh <ruta %p> <nombre %f>. Lo invoca PostgreSQL con archive_command; no se ejecuta a mano."
  exit 1
fi

origen="$1"
nombre="$2"
final="${DESTINO}/${nombre}.gz"
temporal="${DESTINO}/.${nombre}.$$.part"

if [ ! -d "$DESTINO" ]; then
  err "el destino '${DESTINO}' no existe o no esta montado. Crealo en el servidor y dale propiedad al uid de postgres, o corrige BACKUP_PATH en el .env. Mientras tanto PostgreSQL conserva el WAL y el disco de datos crecera."
  exit 1
fi

if [ ! -w "$DESTINO" ]; then
  err "no se puede escribir en '${DESTINO}'. Comprueba el propietario: el proceso de PostgreSQL corre como el usuario 'postgres' del contenedor. Ver docs/runbooks/restaurar-backup.md."
  exit 1
fi

# Reintento tras un corte: si el segmento ya esta y es el mismo, se acepta.
if [ -f "$final" ]; then
  if gzip -dc "$final" 2>/dev/null | cmp -s - "$origen"; then
    exit 0
  fi
  err "'${nombre}' ya esta archivado con un contenido DISTINTO. No se sobrescribe. Casi siempre significa que dos servidores archivan en el mismo destino: separa los destinos antes de seguir. Ver docs/runbooks/restaurar-backup.md."
  exit 1
fi

# El temporal no sobrevive a este proceso pase lo que pase: un `.part` olvidado
# en el destino confunde a quien mire el archivo buscando un segmento.
trap 'rm -f "$temporal" 2>/dev/null || true' EXIT

if ! gzip -c "$origen" >"$temporal"; then
  err "no se ha podido comprimir '${nombre}' en '${DESTINO}'. Lo mas probable es que el disco este lleno: libera espacio ya, porque PostgreSQL acumulara WAL hasta parar. Ver docs/runbooks/restaurar-backup.md."
  exit 1
fi

# Durabilidad antes de decirle a PostgreSQL que puede reciclar el segmento.
sync

chmod 0640 "$temporal" 2>/dev/null || true
if ! mv -f "$temporal" "$final"; then
  err "no se ha podido publicar '${nombre}' en '${DESTINO}'. El segmento NO esta archivado; PostgreSQL lo reintentara."
  exit 1
fi

# Purga de segmentos caducados, como mucho una vez por hora: el archivado corre
# en el camino critico del servidor y no debe recorrer el directorio en cada
# segmento. Nunca borra nada mas nuevo que la retencion configurada.
if [ ! -f "$MARCA_PURGA" ] || [ -z "$(find "$MARCA_PURGA" -mmin -60 2>/dev/null)" ]; then
  : >"$MARCA_PURGA" 2>/dev/null || true
  find "$DESTINO" -maxdepth 1 -type f -name '*.gz' -mtime +"$RETENCION" -delete 2>/dev/null || true
fi

exit 0
