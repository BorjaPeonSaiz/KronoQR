#!/usr/bin/env bash
#
# KronoQR — archivado de un segmento de WAL (RNF-D-02: RPO <= 15 min; RL-12).
#
# Lo invoca PostgreSQL, no una persona: es el `archive_command` que declara
# infra/compose.prod.yaml. Se ejecuta una vez por segmento de WAL y, con
# `archive_timeout=900`, al menos una vez cada 15 minutos si hubo escrituras.
# Eso es lo que acota la perdida maxima de datos a 15 minutos.
#
#   archive-wal.sh <ruta_del_segmento (%p)> <nombre_del_segmento (%f)>
#
# Contrato de `archive_command`, y por que cada bloque de este script existe:
#
#   · Devolver 0 SOLO si el segmento esta a salvo. PostgreSQL borra el original
#     en cuanto este script dice que si; mentir aqui es perder datos en
#     silencio hasta el dia de la restauracion. Por eso lo escrito se LEE DE
#     VUELTA (se verifica el MAC y se compara con el original) antes de anunciarlo.
#   · NUNCA sobrescribir un segmento ya archivado. Si existe y es identico
#     —reintento tras un corte— se acepta; si existe y es distinto, se rechaza
#     y se avisa: dos segmentos con el mismo nombre y contenido distinto
#     significan que dos servidores estan archivando en el mismo destino.
#   · Escribir de forma atomica: temporal + `mv` en el mismo sistema de
#     ficheros. Un segmento a medias es peor que un segmento que falta.
#   · NUNCA archivar en claro (R5-DV-02, RL-12). Cada segmento se comprime y se
#     CIFRA Y AUTENTICA en formato KQE1 (ADR-049, lib/kqe.sh) con BACKUP_WAL_KEY,
#     una subclave derivada de BACKUP_ENCRYPTION_KEY que es lo unico que recibe
#     este contenedor. Sin una clave valida el archivado FALLA (exit 1): PostgreSQL
#     conserva el WAL y la alerta `ArchivadoDeWalFallando` avisa. Es preferible a
#     escribir un solo segmento legible.
#   · Cualquier fallo se normaliza a exit 1: PostgreSQL trata un 126 o un 127 (por
#     ejemplo, falta de `openssl`) como fatal del archivador, no como reintento.
#
# El segmento cifrado es `<segmento>.gz.enc`. Los `.gz` heredados de la 2.1.0 (en
# claro) se CIFRAN EN SITIO con kronoqr-wal-migrate, que este script lanza con
# un presupuesto acotado tras archivar el segmento actual; los lanza ademas
# update.sh de una vez. Al restaurar, kronoqr-restore-wal lee los dos formatos.
#
# Configuracion (variables de entorno del servicio postgres):
#   KRONOQR_WAL_ARCHIVE_DIR      destino. Por defecto /var/backups/fichaje/wal
#   KRONOQR_WAL_RETENTION_DAYS   dias de WAL conservado. Por defecto 8
#   BACKUP_WAL_KEY               subclave del WAL (64 hex). Obligatoria
#   KRONOQR_WAL_ALLOW_DEV_KEY    solo en desarrollo: admite la clave de relleno
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
# La subclave de la clave de relleno de desarrollo (kronoqr_local_dev_only_backup_key).
readonly CLAVE_DE_DESARROLLO="4deec0e109388ff8c0ed3ca348abba952eef5a59c6e87906f052e8453eff69ec"
readonly KQE_LIB="${KQE_LIB:-/usr/local/lib/kronoqr/kqe.sh}"
readonly MIGRADOR="${KRONOQR_WAL_MIGRATE:-/usr/local/bin/kronoqr-wal-migrate}"

err() {
  printf 'kronoqr-archive-wal: %s\n' "$*" >&2
}

temporal=""
trabajo=""

# Cualquier salida distinta de 0 y de 1 pasa a 1 (ver cabecera); el temporal y el
# directorio de trabajo no sobreviven a este proceso pase lo que pase.
# shellcheck disable=SC2329 # la invoca el trap EXIT.
al_salir() {
  local codigo="$?"
  [ -z "$temporal" ] || rm -f -- "$temporal" 2>/dev/null || true
  [ -z "$trabajo" ] || rm -rf -- "$trabajo" 2>/dev/null || true
  if [ "$codigo" -ne 0 ] && [ "$codigo" -ne 1 ]; then
    exit 1
  fi
}
trap al_salir EXIT

if [ "$#" -ne 2 ]; then
  err "uso: archive-wal.sh <ruta %p> <nombre %f>. Lo invoca PostgreSQL con archive_command; no se ejecuta a mano."
  exit 1
fi

origen="$1"
nombre="$2"

# shellcheck source=/dev/null
. "$KQE_LIB" || {
  err "no se puede cargar ${KQE_LIB}: la imagen de PostgreSQL esta incompleta. Reconstruyela o actualiza el paquete."
  exit 1
}
if ! command -v openssl >/dev/null 2>&1 || ! kqe_require; then
  err "falta 'openssl' con SHA3-256 en la imagen de PostgreSQL: imagen incompleta. Actualiza el paquete (update.sh). Mientras tanto PostgreSQL conserva el WAL."
  exit 1
fi

if [[ ! "$nombre" =~ $KQE_WAL_NAME_RE ]]; then
  err "el nombre '${nombre}' no es el de un segmento de WAL ni de un fichero de historia: no se archiva."
  exit 1
fi

if [ ! -d "$DESTINO" ]; then
  err "el destino '${DESTINO}' no existe o no esta montado. Crealo en el servidor y dale propiedad al uid de postgres, o corrige BACKUP_PATH en el .env. Mientras tanto PostgreSQL conserva el WAL y el disco de datos crecera. Ver docs/runbooks/restaurar-backup.md §4."
  exit 1
fi

if [ ! -w "$DESTINO" ]; then
  err "no se puede escribir en '${DESTINO}'. Comprueba el propietario: el proceso de PostgreSQL corre como el usuario 'postgres' del contenedor. Ver docs/runbooks/restaurar-backup.md §4."
  exit 1
fi

# La clave: sin ella, o con la de desarrollo en un servidor real, NO se archiva.
if [ -z "${BACKUP_WAL_KEY:-}" ]; then
  err "falta BACKUP_WAL_KEY: el WAL no se archiva en claro. PostgreSQL conserva los segmentos y el disco de datos crecera. Calculala con 'backup.sh derive-wal-key --write-env .env' y recrea postgres. Ver docs/runbooks/restaurar-backup.md §4.2."
  exit 1
fi
if ! kqe_wal_key_valid "$BACKUP_WAL_KEY"; then
  err "BACKUP_WAL_KEY no es valida (deben ser 64 caracteres hexadecimales). Calculala con 'backup.sh derive-wal-key --write-env .env' y recrea postgres. Ver docs/runbooks/restaurar-backup.md §4.2."
  exit 1
fi
if [ "$BACKUP_WAL_KEY" = "$CLAVE_DE_DESARROLLO" ] && [ "${KRONOQR_WAL_ALLOW_DEV_KEY:-}" != "1" ]; then
  err "BACKUP_WAL_KEY es la clave de desarrollo: no se archiva con ella en un servidor real. Calcula la tuya con 'backup.sh derive-wal-key --write-env .env' y recrea postgres. Ver docs/runbooks/restaurar-backup.md §4.2."
  exit 1
fi

final="${DESTINO}/${nombre}.gz.enc"
heredado="${DESTINO}/${nombre}.gz"
temporal="${DESTINO}/.${nombre}.$$.part"
trabajo="$(mktemp -d "${TMPDIR:-/tmp}/kronoqr-archive.XXXXXX")"
chmod 0700 "$trabajo"

# Reintento tras un corte: si el segmento ya esta y es el mismo, se acepta.
if [ -f "$final" ]; then
  if kqe_open "$final" "$trabajo" wal "$nombre" && kqe_decrypt_copy | gzip -dc | cmp -s - "$origen"; then
    exit 0
  fi
  err "'${nombre}' ya esta archivado con un contenido DISTINTO, o no se puede verificar (${KQE_REASON:-contenido distinto}). No se sobrescribe. Casi siempre significa que dos servidores archivan en el mismo destino, o que el segmento archivado esta danado: separa los destinos antes de seguir. Ver docs/runbooks/restaurar-backup.md §4."
  exit 1
fi
if [ -f "$heredado" ]; then
  if gzip -dc "$heredado" 2>/dev/null | cmp -s - "$origen"; then
    exit 0
  fi
  err "'${nombre}' ya esta archivado (formato heredado .gz) con un contenido DISTINTO. No se sobrescribe. Casi siempre significa que dos servidores archivan en el mismo destino: separa los destinos antes de seguir. Ver docs/runbooks/restaurar-backup.md §4."
  exit 1
fi

if ! gzip -c "$origen" | kqe_encrypt wal "$nombre" "$temporal"; then
  err "no se ha podido comprimir y cifrar '${nombre}' en '${DESTINO}'. Lo mas probable es que el disco este lleno: libera espacio ya, porque PostgreSQL acumulara WAL hasta parar. Ver docs/runbooks/restaurar-backup.md §4 y §5."
  exit 1
fi

# Lo escrito se LEE DE VUELTA: autentica y descifra a lo mismo que el original.
# Un disco o un recurso de red que miente se detecta antes de decir «a salvo».
if ! { kqe_open "$temporal" "$trabajo" wal "$nombre" && kqe_decrypt_copy | gzip -dc | cmp -s - "$origen"; }; then
  err "el segmento '${nombre}' recien escrito no se lee de vuelta igual (${KQE_REASON:-contenido distinto}). NO esta archivado; PostgreSQL lo reintentara. Si se repite, revisa el disco o el recurso de red del destino."
  exit 1
fi

# Durabilidad antes de decirle a PostgreSQL que puede reciclar el segmento.
sync

chmod 0640 "$temporal" 2>/dev/null || true
if ! mv -f "$temporal" "$final"; then
  err "no se ha podido publicar '${nombre}' en '${DESTINO}'. El segmento NO esta archivado; PostgreSQL lo reintentara."
  exit 1
fi
temporal=""

# A partir de aqui el segmento esta a salvo: nada de lo que sigue puede cambiar el
# resultado (de ahi los `|| true`).

# Purga de segmentos caducados, como mucho una vez por hora: el archivado corre
# en el camino critico del servidor y no debe recorrer el directorio en cada
# segmento. Nunca borra nada mas nuevo que la retencion configurada. Recorre los
# DOS formatos: un `*.gz` solo no vería jamas un `.gz.enc` (disco lleno).
if [ ! -f "$MARCA_PURGA" ] || [ -z "$(find "$MARCA_PURGA" -mmin -60 2>/dev/null)" ]; then
  : >"$MARCA_PURGA" 2>/dev/null || true
  find "$DESTINO" -maxdepth 1 -type f \( -name '*.gz' -o -name '*.gz.enc' \) -mtime +"$RETENCION" -delete 2>/dev/null || true
  find "$DESTINO" -maxdepth 1 -type f -name '.*.part' -mmin +60 -delete 2>/dev/null || true
fi

# Migracion oportunista de los segmentos heredados en claro (si quedan): acotada,
# para no alargar el camino critico del archivado.
if [ -x "$MIGRADOR" ] && [ -n "$(find "$DESTINO" -maxdepth 1 -type f -name '*.gz' -print -quit 2>/dev/null)" ]; then
  "$MIGRADOR" --max 25 --seconds 20 >&2 || true
fi

exit 0
