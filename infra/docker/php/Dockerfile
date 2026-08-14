# syntax=docker/dockerfile:1.7
#
# Imagen de la aplicacion: PHP 8.4-FPM sobre Alpine, multi-etapa y sin root.
# Doc 02 §3.1 (stack), §3.2 (PostgreSQL), §9.2 (Trivy: 0 CVE criticos).
#
# Etapas:
#   base   extensiones y usuario sin privilegios. Comun a todo.
#   dev    + Composer y Xdebug opcional. El codigo llega por bind mount.
#   vendor + dependencias de produccion resueltas con Composer.
#   prod   imagen final que se entrega al cliente. Codigo dentro, sin Composer.
#
# El contexto de build es la raiz del repositorio.

ARG PHP_VERSION=8.4

#------------------------------------------------------------------------------
FROM php:${PHP_VERSION}-fpm-alpine AS base

# install-php-extensions resuelve por si solo las dependencias de compilacion y
# las de runtime, y borra las primeras. Es la via mas fiable de mantener la
# imagen pequena sin dejar toolchain dentro (Trivy lo agradece).
COPY --from=mlocati/php-extension-installer:2 /usr/bin/install-php-extensions /usr/local/bin/

RUN set -eux; \
  install-php-extensions \
  pdo_pgsql \
  pgsql \
  redis \
  sodium \
  intl \
  zip \
  bcmath \
  pcntl \
  gd \
  opcache; \
  apk add --no-cache bash fcgi tzdata; \
  # Verificacion explicita: si una de las cuatro extensiones que el stack exige
  # no esta, la imagen no debe construirse. Es mejor fallar aqui que en la
  # primera consulta a PostgreSQL de la Fase 1.
  php -m | grep -qx pdo_pgsql; \
  php -m | grep -qx pgsql; \
  php -m | grep -qx redis; \
  php -m | grep -qx sodium

# Usuario sin privilegios. El uid 1000 coincide con el del desarrollador tipico
# en Linux, asi que los ficheros creados en el bind mount no salen de root.
ARG APP_UID=1000
ARG APP_GID=1000
RUN set -eux; \
  addgroup -g "${APP_GID}" -S app; \
  adduser -u "${APP_UID}" -S -G app -h /var/www -s /bin/bash app; \
  mkdir -p /var/www/html; \
  chown -R app:app /var/www; \
  # Cache en disco de OPcache (conf.d/zz-kronoqr.ini). PHP no crea el
  # directorio: si no existe, la cache se descarta en silencio y cada
  # invocacion de CLI vuelve a compilar el autoload entero.
  install -d -o app -g app -m 0700 /var/www/.opcache; \
  # Punto de montaje de vendor/, que en desarrollo es un volumen nombrado
  # (infra/compose.dev.yaml). Docker copia la propiedad del directorio de la
  # IMAGEN al inicializar un volumen vacio: si el directorio no existe aqui, el
  # volumen nace de root y el proceso, que corre como app, no puede escribir en
  # el. El sintoma es un composer install que falla sin decir por que.
  install -d -o app -g app /var/www/html/vendor

COPY infra/docker/php/conf.d/ /usr/local/etc/php/conf.d/
COPY infra/docker/php/fpm/www.conf /usr/local/etc/php-fpm.d/www.conf
COPY infra/docker/php/entrypoint.sh /usr/local/bin/kronoqr-entrypoint
RUN chmod +x /usr/local/bin/kronoqr-entrypoint

WORKDIR /var/www/html
USER app
EXPOSE 9000

ENTRYPOINT ["/usr/local/bin/kronoqr-entrypoint"]
CMD ["fpm"]

#------------------------------------------------------------------------------
FROM base AS dev

USER root
COPY --from=composer/composer:2-bin /composer /usr/local/bin/composer
RUN set -eux; \
  install-php-extensions xdebug; \
  # Xdebug instalado pero APAGADO: encenderlo cuesta rendimiento en cada
  # peticion. Se activa con XDEBUG_MODE=debug en el entorno del contenedor.
  echo "xdebug.mode=off" > /usr/local/etc/php/conf.d/zz-xdebug-off.ini
USER app

#------------------------------------------------------------------------------
FROM base AS vendor

USER root
COPY --from=composer/composer:2-bin /composer /usr/local/bin/composer
USER app

# Requiere backend/composer.json, que crea la tarea 0.2. Hasta entonces esta
# etapa no se construye: la imagen de desarrollo usa el target dev.
COPY --chown=app:app backend/composer.json backend/composer.lock ./
RUN composer install \
  --no-dev \
  --no-scripts \
  --no-autoloader \
  --prefer-dist \
  --no-interaction

COPY --chown=app:app backend/ ./
RUN composer dump-autoload --no-dev --optimize --classmap-authoritative

#------------------------------------------------------------------------------
FROM base AS prod

# La version desplegada es visible en /api/v1/health y en la pantalla de
# diagnostico del quiosco, para poder correlacionar un incidente con una
# version concreta (doc 02 §10.5).
ARG APP_VERSION=0.0.0-dev
ENV APP_VERSION=${APP_VERSION}

# Composer no viaja en la imagen final: es superficie de ataque innecesaria.
COPY --chown=app:app --from=vendor /var/www/html/ ./

USER root
RUN install -d -o app -g app /var/www/html/storage /var/www/html/bootstrap/cache
USER app

# Sin HEALTHCHECK en la imagen a proposito: la misma imagen sirve a fpm, a
# horizon, a reverb y al scheduler, y cada uno se comprueba de forma distinta.
# La sonda se declara por servicio en los ficheros de compose.
