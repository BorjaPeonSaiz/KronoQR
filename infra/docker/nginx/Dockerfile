# syntax=docker/dockerfile:1.7
#
# Borde HTTP de KronoQR: TLS, cabeceras de seguridad (§7.2), rate limiting por
# zona (§7.1) y /metrics restringido a red interna (§8.1).
#
# Base sin root: nginx-unprivileged corre como uid 101 y escucha en 8080/8443.
# El mapeo a 80/443 lo hace el fichero de compose.

FROM nginxinc/nginx-unprivileged:1.31-alpine

USER root

# Mismo sello semanal que infra/docker/php/Dockerfile, y por el mismo motivo:
# con cache de Actions, esta capa se reutilizaria para siempre con los paquetes
# del dia en que se construyo por primera vez. Lo pone `make build-ci-images`.
ARG APK_INDEX_STAMP=local
RUN set -eux; \
  echo "apk index stamp: ${APK_INDEX_STAMP}"; \
  apk upgrade --no-cache; \
  apk add --no-cache bash openssl; \
  # La imagen trae un default.conf de ejemplo que serviria el "Welcome to
  # nginx" en el puerto 8080. Fuera: nuestra configuracion llega por plantilla.
  rm -f /etc/nginx/conf.d/default.conf; \
  install -d -o 101 -g 101 /etc/nginx/certs /etc/nginx/extra

COPY infra/docker/nginx/templates/ /etc/nginx/templates/
COPY infra/docker/nginx/snippets/ /etc/nginx/snippets/
COPY infra/docker/nginx/docker-entrypoint.d/ /docker-entrypoint.d/

RUN chmod +x /docker-entrypoint.d/*.sh

USER 101

EXPOSE 8080 8443

HEALTHCHECK --interval=15s --timeout=5s --start-period=10s --retries=3 \
  CMD wget --quiet --tries=1 --spider http://127.0.0.1:8080/healthz || exit 1
