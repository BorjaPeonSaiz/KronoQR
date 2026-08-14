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

COPY infra/docker/postgres/initdb/ /docker-entrypoint-initdb.d/

# Los scripts de initdb SOLO se ejecutan la primera vez, sobre un volumen
# vacio. Si cambias algo aqui, hay que recrear el volumen: make clean.
