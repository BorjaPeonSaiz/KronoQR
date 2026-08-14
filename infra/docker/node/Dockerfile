# syntax=docker/dockerfile:1.7
#
# Imagen de desarrollo de los tres frontends. Solo se usa en compose.dev.yaml:
# en produccion se entregan los ficheros ya construidos y no hay Node.
#
# La imagen oficial trae el usuario "node" (uid 1000): no se corre como root.

FROM node:24-alpine

RUN apk add --no-cache bash

COPY infra/docker/node/entrypoint.sh /usr/local/bin/kronoqr-frontend
RUN chmod +x /usr/local/bin/kronoqr-frontend

USER node
WORKDIR /app

EXPOSE 5173

ENTRYPOINT ["/usr/local/bin/kronoqr-frontend"]
