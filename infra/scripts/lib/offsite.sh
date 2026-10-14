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
# FISICO que los datos de PostgreSQL y de Docker, el dia que ese disco se pierde
# se pierden la base y todas las copias juntas. Nada lo detecta solo: las copias
# se hacen, se verifican y se restauran —en el mismo disco— hasta que muere.
#
# DOS MEDIDAS, CON DOS NIVELES DE CERTEZA (no se mezclan):
#
#  1. Desde el CONTENEDOR (backup.sh, kq_offsite_state): solo se ve el sistema de
#     ficheros. `stat -c %d` distingue sistemas de ficheros, NO discos: una
#     particion, un volumen logico de LVM, un subvolumen de btrfs o un dataset de
#     ZFS del mismo disco fisico tienen dispositivos distintos. Por eso:
#       network   2   sistema de ficheros de RED (NFS, SMB...): otra maquina. Fuera.
#       other_fs  1   otro sistema de ficheros que los datos. NO se sabe si es otro
#                     disco: se remite a doctor.sh.
#       local     0   el mismo sistema de ficheros que los datos. No sale.
#       unknown  -1   no se pudo medir.
#  2. Desde el ANFITRION (doctor.sh, kq_offsite_state_host): se resuelve el disco
#     fisico de origen (findmnt + lsblk -s, que atraviesa particiones, LVM y md) y
#     se comparan discos, no sistemas de ficheros:
#       offsite   red, o discos fisicos distintos de todas las referencias
#       local     comparte algun disco fisico con los datos
#       unknown   ZFS, btrfs sin dispositivo, sin findmnt/lsblk o sin root: no se
#                 adivina
#
# Es un AVISO, nunca un fallo: el cliente puede sacar las copias por su cuenta
# (rsync a otra maquina, cinta, nube europea) y este script no puede saberlo.

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

# Medida 1, desde el contenedor: network | other_fs | local | unknown.
#
# kq_offsite_state DESTINO [REFERENCIA...]
kq_offsite_state() {
  local dest="$1" dest_dev ref ref_dev measured=0
  shift

  if kq_fs_is_network "$(kq_fs_type "${dest}")"; then
    printf 'network'
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
    printf 'other_fs'
  else
    printf 'unknown'
  fi
}

# Estado → valor de la metrica.
kq_offsite_value() {
  case "$1" in
  network) printf '2' ;;
  other_fs) printf '1' ;;
  local) printf '0' ;;
  *) printf -- '-1' ;;
  esac
}

#------------------------------------------------------------------------------
# Medida 2, desde el anfitrion: discos fisicos.
#------------------------------------------------------------------------------

# Origen del montaje que contiene la ruta (`/dev/mapper/vg-lv`, `/dev/sda2`,
# `pool/dataset`, `tmpfs`...), sin el `[/subvolumen]` que findmnt anade en btrfs.
# Funcion aparte para poder sustituirla en las pruebas.
kq_mount_source() {
  local path source
  path="$(kq_existing_ancestor "$1")"
  command -v findmnt >/dev/null 2>&1 || return 0
  source="$(findmnt -no SOURCE -T "${path}" 2>/dev/null | head -n 1 || true)"
  printf '%s' "${source%%\[*}"
}

# Discos fisicos (uno por linea) bajo un dispositivo de bloques: `lsblk -s` sube
# por particiones, LVM y md hasta el disco. Funcion aparte por lo mismo.
kq_block_disks() {
  command -v lsblk >/dev/null 2>&1 || return 0
  lsblk -nrs -o NAME,TYPE "$1" 2>/dev/null | awk '$2 == "disk" || $2 == "loop" {print $1}' || true
}

# Discos fisicos de la ruta, ordenados y sin repetidos, uno por linea. Vacio si no
# se pueden determinar (ZFS, tmpfs, sin permisos, herramientas ausentes).
kq_physical_disks() {
  local source
  source="$(kq_mount_source "$1")"
  case "${source}" in
  /dev/*) kq_block_disks "${source}" | sort -u ;;
  esac
}

# kq_offsite_state_host DESTINO [REFERENCIA...] → offsite | local | unknown
#
# Red = fuera. Si ALGUN disco del destino coincide con alguno de una referencia:
# local, aunque otra referencia no se haya podido resolver. Si no coincide ninguno
# pero alguna referencia (o el destino) no existe o no se pudo resolver: unknown.
kq_offsite_state_host() {
  local dest="$1" dest_disks ref ref_disks unresolved=0 disk other
  shift

  if kq_fs_is_network "$(kq_fs_type "${dest}")"; then
    printf 'offsite'
    return 0
  fi

  dest_disks="$(kq_physical_disks "${dest}")"
  if [ -z "${dest_disks}" ]; then
    printf 'unknown'
    return 0
  fi

  for ref in "$@"; do
    [ -n "${ref}" ] || continue
    ref_disks=""
    [ ! -e "${ref}" ] || ref_disks="$(kq_physical_disks "${ref}")"
    if [ -z "${ref_disks}" ]; then
      unresolved=1
      continue
    fi
    while IFS= read -r disk; do
      while IFS= read -r other; do
        if [ "${disk}" = "${other}" ]; then
          printf 'local'
          return 0
        fi
      done <<<"${ref_disks}"
    done <<<"${dest_disks}"
  done

  if [ "${unresolved}" -eq 1 ] || [ "$#" -eq 0 ]; then
    printf 'unknown'
  else
    printf 'offsite'
  fi
}
