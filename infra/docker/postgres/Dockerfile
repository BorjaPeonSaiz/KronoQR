# syntax=docker/dockerfile:1.7
#
# PostgreSQL 17 — el registro legal y sus invariantes declarativas (doc 02 §3.2).
#
# La imagen oficial ya corre como el usuario postgres. Lo unico que anadimos es
# el arranque de extensiones: btree_gist (RN-02, restriccion de exclusion),
# pgcrypto (hash del DNI y cadena de auditoria) y citext (employee_code).

FROM postgres:17-alpine@sha256:18cfe3ef5e6815560c98237d6216d1e5119702fb0f3894c8785dd58b8bbe5d73

# Regla dura 3: la base de datos nace en UTC. Si nace en Europe/Madrid, todo lo
# que venga despues arrastra el error y no se nota hasta el cambio de hora.
ENV TZ=UTC \
  PGTZ=UTC \
  LANG=C.UTF-8

# `apk upgrade` ACOTADO a las dos librerias que motivan la linea, no a ciegas
# (IMPORTANTE 7 de la auditoria). La imagen base fija los paquetes de Alpine
# al dia de su propia publicacion, y basta que pase una semana para que
# `libssl3`/`libcrypto3` tengan una version parcheada disponible en el mismo
# indice de paquetes sin que la imagen base se haya reconstruido todavia:
# verificado con `trivy image` (doc 02 §9.2), sin esta linea aparecia
# CVE-2026-14456 en las dos con arreglo ya publicado. Pero un `apk upgrade
# --no-cache` SIN paquetes tambien puede subir `postgresql17` en si mismo si
# el indice de Alpine publica un parche antes de que la imagen base se
# reconstruya, y eso es justo lo que no puede pasar aqui: la version del
# SERVIDOR tiene que ser la que dice el tag de la imagen, no la que resuelva
# `apk` el dia del build. Acotar a los dos paquetes cierra el CVE sin abrir
# esa puerta.
#
# La imagen base esta fijada por DIGEST y no solo por tag (mismo motivo):
# `postgres:17-alpine` es un tag movil que Docker y Alpine reconstruyen sin
# avisar, y dos `docker build` el mismo dia en maquinas distintas podrian
# partir de contenidos distintos. Con el digest, quien decide CUANDO se sube
# de base es un PR de Dependabot (.github/dependabot.yml, ecosistema
# `docker`), no una reconstruccion silenciosa. Para actualizarlo a mano:
# `docker pull postgres:17-alpine && docker inspect --format='{{index
# .RepoDigests 0}}' postgres:17-alpine`.
#
# bash para el archivado de WAL: `set -o pipefail` no existe en el `sh` de
# BusyBox, y el doc 02 §3.5 lo exige en todo script. Es lo unico que se añade
# ademas de la actualizacion.
RUN apk upgrade --no-cache libssl3 libcrypto3 && apk add --no-cache bash

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
