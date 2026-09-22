# syntax=docker/dockerfile:1.7
#
# Imagen de desarrollo de los tres frontends. Solo se usa en compose.dev.yaml:
# en produccion se entregan los ficheros ya construidos y no hay Node.
#
# La imagen oficial trae el usuario "node" (uid 1000): no se corre como root.

FROM node:25-alpine

RUN apk add --no-cache bash

COPY infra/docker/node/entrypoint.sh /usr/local/bin/kronoqr-frontend
RUN chmod +x /usr/local/bin/kronoqr-frontend

# Puntos de montaje de los CINCO volumenes con nombre del workspace de npm
# (infra/compose.dev.yaml, tarea de correccion de node-kiosk/admin/portal),
# creados con el propietario correcto ANTES de que Compose los monte.
#
# Mismo motivo que vendor/ en infra/docker/php/Dockerfile: si una ruta no
# existe ya en la imagen, Docker Desktop inicializa el volumen nombrado vacio
# y CON PROPIETARIO ROOT, y el proceso -que corre como "node", uid 1000- no
# puede escribir ahi. Ninguna de estas rutas existe en la imagen (node_modules
# solo llega por el bind mount del repositorio o por `npm ci` dentro del
# contenedor), asi que sin este paso el primer `npm ci` fallaria con "EACCES".
RUN mkdir -p \
  /app/node_modules \
  /app/frontend-kiosk/node_modules \
  /app/frontend-admin/node_modules \
  /app/frontend-portal/node_modules \
  /app/packages/web-kit/node_modules \
  && chown -R node:node /app

USER node
WORKDIR /app

EXPOSE 5173

ENTRYPOINT ["/usr/local/bin/kronoqr-frontend"]
