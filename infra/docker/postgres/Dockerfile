# syntax=docker/dockerfile:1.7
#
# PostgreSQL 17 — el registro legal y sus invariantes declarativas (doc 02 §3.2).
#
# La imagen oficial ya corre como el usuario postgres. Lo unico que anadimos es
# el arranque de extensiones: btree_gist (RN-02, restriccion de exclusion),
# pgcrypto (hash del DNI y cadena de auditoria) y citext (employee_code).

FROM postgres:17-alpine

# Regla dura 3: la base de datos nace en UTC. Si nace en Europe/Madrid, todo lo
# que venga despues arrastra el error y no se nota hasta el cambio de hora.
ENV TZ=UTC \
  PGTZ=UTC \
  LANG=C.UTF-8

# bash para el archivado de WAL: `set -o pipefail` no existe en el `sh` de
# BusyBox, y el doc 02 §3.5 lo exige en todo script. Es lo unico que se añade.
RUN apk add --no-cache bash

COPY infra/docker/postgres/initdb/ /docker-entrypoint-initdb.d/
# El script de roles se EJECUTA, no se carga con `.`: el entrypoint oficial hace
# `source` de los .sh que no son ejecutables, y su `set -euo pipefail` acabaria
# aplicandose al propio entrypoint. Ademas asi se puede lanzar a mano contra un
# cluster ya inicializado, que es la via de actualizacion de una instalacion
# existente (regla dura 6, tarea 1.14).
RUN chmod 0755 /docker-entrypoint-initdb.d/02-application-roles.sh

# Archivado de WAL (RNF-D-02, RPO <= 15 min). El `archive_command` de los
# ficheros de compose apunta aqui. El script decide si un segmento esta a salvo
# y PostgreSQL le cree: por eso no es una linea de `cp` en el compose.
COPY infra/docker/postgres/bin/archive-wal.sh /usr/local/bin/kronoqr-archive-wal
RUN set -eux; \
  chmod 0755 /usr/local/bin/kronoqr-archive-wal; \
  # Punto de montaje del archivo de WAL, con el propietario correcto. Docker
  # copia la propiedad del directorio de la IMAGEN al inicializar un volumen
  # vacio: sin esta linea el volumen nace de root, el proceso de PostgreSQL
  # —que corre como 'postgres'— no puede archivar, y el sintoma es que el disco
  # de datos crece sin parar. En produccion es un bind mount y lo prepara
  # install.sh con el mismo uid.
  install -d -o postgres -g postgres -m 0750 /var/backups/fichaje/wal

# Reglas de acceso declarativas, iguales en todas las instalaciones. Se activan
# con `-c hba_file=/etc/kronoqr/pg_hba.conf`; sin ellas, pg_basebackup no puede
# conectarse desde otro contenedor y no hay copia fisica.
COPY infra/docker/postgres/conf/pg_hba.conf /etc/kronoqr/pg_hba.conf
RUN chown postgres:postgres /etc/kronoqr/pg_hba.conf && chmod 0600 /etc/kronoqr/pg_hba.conf

# Los scripts de initdb SOLO se ejecutan la primera vez, sobre un volumen
# vacio. Si cambias algo aqui, hay que recrear el volumen: make clean.
