#!/usr/bin/env bash
#
# KronoQR — ¿las copias salen del servidor? (V5-DV-05, RF-PD-13, RL-12, RL-14).
#
# NO SE EJECUTA SOLO: lo cargan backup.sh (a traves de lib/backup-common.sh), que
# publica el resultado como metrica, y doctor.sh, que lo comprueba desde el
# anfitrion.
#
# POR QUE. La promesa de «perder como mucho 15 minutos» (doc 05) vale SIEMPRE QUE
# LAS COPIAS Y EL WAL SALGAN DEL SERVIDOR. Si BACKUP_PATH vive en el mismo disco
# que los datos de PostgreSQL y de Docker, el dia que ese disco se pierde se
# pierden la base y todas las copias juntas. Nada lo detecta solo: las copias se
# hacen, se verifican y se restauran —en el mismo disco— hasta que el disco muere.
#
# QUE SE CONSIDERA «FUERA»:
#   · un sistema de ficheros de RED (NFS, SMB/CIFS, Ceph...): otra maquina;
#   · un DISPOSITIVO DISTINTO del de los datos (`stat -c %d`): otro disco. Protege
#     de la averia del disco, no del robo ni del incendio: la guia lo dice.
# Lo que NO sale es un destino en el MISMO dispositivo que los datos.
#
# Es un AVISO, nunca un fallo: el cliente puede sacar las copias por su cuenta
# (rsync a otra maquina, cinta, nube europea) y este script no puede saberlo.
#
# Estados, que son tambien el valor de la metrica kronoqr_backup_*_offsite:
#   offsite   1   red, o dispositivo distinto de todas las referencias
#   local     0   el mismo dispositivo que alguna referencia
#   unknown  -1   no se pudo medir (ruta ilegible o sin referencia)

set -euo pipefail
IFS=$'\n\t'

# Tipos de `stat -f -c %T` que son de otra maquina.
readonly KQ_OFFSITE_NETWORK_FS=" nfs nfs4 cifs smb smb2 smb3 smbfs ceph ceph-fuse glusterfs afs coda ncpfs lustre beegfs gpfs "

# Dispositivo (st_dev) del sistema de ficheros que contiene la ruta. Vacio si no
# se puede leer. Sube al ancestro existente, como kq_free_bytes: el destino puede
# no existir todavia.
kq_fs_device() {
  local path
  path="$(kq_existing_ancestor "$1")"
  stat -c %d -- "${path}" 2>/dev/null || true
}

# Tipo de sistema de ficheros de la ruta (`nfs`, `ext2/ext3`, `xfs`, `overlayfs`...).
kq_fs_type() {
  local path
  path="$(kq_existing_ancestor "$1")"
  stat -f -c %T -- "${path}" 2>/dev/null || true
}

kq_fs_is_network() {
  local type="$1"
  [ -n "${type}" ] || return 1
  case "${KQ_OFFSITE_NETWORK_FS}" in
  *" ${type} "*) return 0 ;;
  esac
  return 1
}

# kq_offsite_state DESTINO [REFERENCIA...] → offsite | local | unknown
#
# Una referencia que no existe o no se puede leer (p. ej. /var/lib/docker sin ser
# root) se ignora; si no queda ninguna y el destino no es de red, «unknown»: no se
# adivina.
kq_offsite_state() {
  local dest="$1" dest_dev ref ref_dev measured=0
  shift

  if kq_fs_is_network "$(kq_fs_type "${dest}")"; then
    printf 'offsite'
    return 0
  fi

  dest_dev="$(kq_fs_device "${dest}")"
  if [ -z "${dest_dev}" ]; then
    printf 'unknown'
    return 0
  fi

  for ref in "$@"; do
    [ -n "${ref}" ] && [ -e "${ref}" ] || continue
    ref_dev="$(kq_fs_device "${ref}")"
    [ -n "${ref_dev}" ] || continue
    measured=1
    if [ "${ref_dev}" = "${dest_dev}" ]; then
      printf 'local'
      return 0
    fi
  done

  if [ "${measured}" -eq 1 ]; then
    printf 'offsite'
  else
    printf 'unknown'
  fi
}

# Estado → valor de la metrica.
kq_offsite_value() {
  case "$1" in
  offsite) printf '1' ;;
  local) printf '0' ;;
  *) printf -- '-1' ;;
  esac
}
