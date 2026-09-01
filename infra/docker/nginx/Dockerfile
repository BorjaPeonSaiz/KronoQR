# syntax=docker/dockerfile:1.7
#
# Borde HTTP de KronoQR: TLS, cabeceras de seguridad (§7.2), rate limiting por
# zona (§7.1) y /metrics restringido a red interna (§8.1).
#
# Base sin root: nginx-unprivileged corre como uid 101 y escucha en 8080/8443.
# El mapeo a 80/443 lo hace el fichero de compose.
#
# DOS ETAPAS. La primera construye las tres SPA; la segunda es la imagen que se
# entrega. El contexto de build es la raiz del repositorio.

#------------------------------------------------------------------------------
# Etapa 1 — los tres frontends
#------------------------------------------------------------------------------
# SE CONSTRUYEN AQUI Y NO FUERA a proposito. La alternativa —un `npm run build`
# previo en el anfitrion y un COPY de los `dist/`— haria que `docker build`
# produjera una imagen distinta segun lo que hubiera en el disco de quien la
# construye, y una imagen de entrega tiene que salir identica de un checkout
# limpio. `dist/` esta ademas en .dockerignore, asi que un build local no puede
# colarse en la capa.
FROM node:24-alpine AS frontends

WORKDIR /build

# Manifiestos primero: asi la capa de dependencias —la cara— no se invalida
# cada vez que cambia una linea de codigo de una SPA.
COPY package.json package-lock.json ./
COPY packages/web-kit/package.json ./packages/web-kit/
COPY frontend-admin/package.json ./frontend-admin/
COPY frontend-kiosk/package.json ./frontend-kiosk/
COPY frontend-portal/package.json ./frontend-portal/

# SI ESTO FALLA CON «Cannot find module '@tailwindcss/oxide-linux-x64-musl'»,
# el problema es el `package-lock.json`, no esta imagen.
#
# Que pasa: los paquetes con binario nativo se publican como un paquete por
# plataforma y se declaran `optionalDependencies`. Cuando alguien ejecuta
# `npm install` EN WINDOWS y con `node_modules/` ya presente, npm reescribe el
# lock quedandose solo con la plataforma de esa maquina (npm/cli#4828). El
# resultado pasa desapercibido —en Windows todo sigue funcionando— y estalla
# aqui, que es la primera vez que alguien instala en Linux musl.
#
# Como se repara SIN mover ninguna version (probado el 01-09-2026: 0 paquetes
# cambian de version, 19 entradas nuevas, 6 duplicados por workspace
# retirados). Se ejecuta DESDE UN CONTENEDOR LINUX y sobre un arbol que
# contenga SOLO los package.json y el lock; con `node_modules/` delante, npm
# vuelve a hacer lo mismo:
#
#   1. npm install --package-lock-only --ignore-scripts --no-audit --no-fund \
#        -w frontend-admin -w frontend-kiosk -w frontend-portal \
#        @tailwindcss/oxide@<version del lock>
#   2. restaurar los package.json de los tres workspaces: el paso 1 les deja
#      `@tailwindcss/oxide` como dependencia DIRECTA, que no es lo que se quiere
#   3. npm install --package-lock-only --ignore-scripts --no-audit --no-fund
#      (reconcilia: las plataformas se quedan y los manifiestos vuelven a ser
#      los originales)
#
# La regresion la vigila `QualityGatesTest` («mantiene en el lock los binarios
# nativos de la plataforma en la que se construye la imagen»), que afirma que
# el lock trae la variante linux-x64-musl de oxide y de rolldown.
RUN npm ci

COPY packages/ ./packages/
COPY frontend-admin/ ./frontend-admin/
COPY frontend-kiosk/ ./frontend-kiosk/
COPY frontend-portal/ ./frontend-portal/

# KRONOQR_BASE es lo que hace que cada SPA cuelgue de su prefijo: lo leen los
# tres vite.config.ts y de ahi sale `import.meta.env.BASE_URL`, que es lo que
# usa el router de cada una. En el quiosco gobierna ademas `start_url`, `scope`
# y el respaldo de navegacion del service worker.
#
# Se invoca `vite build` y no `npm run build` porque el script de npm encadena
# `type-check` y, en el quiosco, el presupuesto de bundle: las dos son puertas
# de la CI (etapas ① y ⑥) y repetirlas aqui alargaria cada construccion de
# imagen sin anadir garantia. Si el type-check falla, la CI no llega a este
# paso.
RUN set -eux; \
  cd /build/frontend-admin && KRONOQR_BASE=/admin/ npx vite build; \
  cd /build/frontend-kiosk && KRONOQR_BASE=/kiosk/ npx vite build; \
  cd /build/frontend-portal && KRONOQR_BASE=/portal/ npx vite build; \
  test -f /build/frontend-admin/dist/index.html; \
  test -f /build/frontend-kiosk/dist/index.html; \
  test -f /build/frontend-portal/dist/index.html

#------------------------------------------------------------------------------
# Etapa 2 — el borde HTTP que se entrega
#------------------------------------------------------------------------------
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
  install -d -o 101 -g 101 /etc/nginx/certs /etc/nginx/extra /var/www/spa

# Los tres frontends ya construidos. Solo lectura para el proceso de Nginx: no
# hay ninguna razon para que el borde pueda escribir en lo que sirve.
COPY --from=frontends --chown=root:root /build/frontend-admin/dist/  /var/www/spa/admin/
COPY --from=frontends --chown=root:root /build/frontend-kiosk/dist/  /var/www/spa/kiosk/
COPY --from=frontends --chown=root:root /build/frontend-portal/dist/ /var/www/spa/portal/

COPY infra/docker/nginx/templates/ /etc/nginx/templates/
COPY infra/docker/nginx/snippets/ /etc/nginx/snippets/
# En desarrollo, compose.dev.yaml monta dev-extra/ ENCIMA de este directorio y
# lo sustituye por los proxys a los servidores de Vite.
COPY infra/docker/nginx/extra/ /etc/nginx/extra/
COPY infra/docker/nginx/docker-entrypoint.d/ /docker-entrypoint.d/

RUN chmod +x /docker-entrypoint.d/*.sh

USER 101
# VALOR POR DEFECTO DE LO QUE SI PUEDE TENERLO.
#
# `envsubst` sustituye una variable ausente por la cadena vacia y sigue: el
# resultado es una directiva rota y un arranque que muere con
# «"client_max_body_size" directive invalid value», un mensaje que no nombra la
# variable que falta ni dice de donde sale. Con este ENV la variable siempre
# esta definida, y el .env de la instalacion lo sobreescribe sin esfuerzo: el
# entorno del contenedor gana sobre el ENV de la imagen.
#
# LAS RUTAS DEL CERTIFICADO NO ESTAN AQUI, y estuvieron: Trivy las marcaba con
# DS-0031 CRITICAL porque la regla mira el NOMBRE y ve «KEY» en TLS_KEY_FILE.
# No son secretos —son rutas— pero la correccion es limpia y esta hecha, no
# excepcionada: viven en docker-entrypoint.d/03-kronoqr-tls-paths.envsh, junto
# a la logica que las usa.
#
# LAS TRES REDES tampoco, y por un motivo distinto: un valor por defecto para
# PORTAL_INTERNAL_CIDR publicaria el portal a internet o dejaria a la plantilla
# sin consultar su jornada, y para KIOSK_VLAN_CIDR frenaria el fichaje en el
# cambio de turno. Esas se EXIGEN, y quien las comprueba es
# docker-entrypoint.d/04-kronoqr-required-env.sh.
ENV NGINX_CLIENT_MAX_BODY_SIZE=8m

EXPOSE 8080 8443

HEALTHCHECK --interval=15s --timeout=5s --start-period=10s --retries=3 \
  CMD wget --quiet --tries=1 --spider http://127.0.0.1:8080/healthz || exit 1
