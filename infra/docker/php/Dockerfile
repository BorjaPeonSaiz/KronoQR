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
  # postgresql17-client: pg_dump, pg_restore, psql y pg_basebackup. Son las
  # herramientas de backup.sh y de restore.sh (tarea 1.18), que se ejecutan
  # DENTRO de este contenedor —`php artisan backup:run`— para no exigirle al
  # servidor del cliente ni cliente de PostgreSQL ni acceso al socket de
  # Docker. La version mayor debe coincidir con la del servidor: un pg_dump 16
  # se niega a volcar un servidor 17.
  apk add --no-cache bash fcgi tzdata postgresql17-client openssl; \
  # Node y Chromium: los necesita Browsershot, que es lo que `spatie/laravel-pdf`
  # usa para dibujar los PDF (doc 02 §3.1). Hoy los usa la impresion de tarjetas
  # (RF-QR-04, tarea 1.10) y despues los usaran la exportacion legal en PDF
  # (tarea 2.9) y los informes sellados.
  #
  # POR QUE EL CHROMIUM DE ALPINE Y NO EL QUE SE DESCARGA PUPPETEER. El de
  # Alpine viene compilado contra musl —el que descarga Puppeteer es glibc y no
  # arranca aqui—, lo actualiza el gestor de paquetes de la distribucion, y Trivy
  # lo ve como lo que es: un paquete del sistema con su CVE rastreable. Un
  # binario descargado en tiempo de construccion no lo audita nadie.
  #
  # PUPPETEER_SKIP_DOWNLOAD es obligatorio: sin el, `npm i puppeteer` se baja
  # 170 MB de un Chromium que en esta imagen no puede ejecutarse.
  apk add --no-cache nodejs npm chromium nss freetype harfbuzz ttf-freefont; \
  # Verificacion explicita: si una de las cuatro extensiones que el stack exige
  # no esta, la imagen no debe construirse. Es mejor fallar aqui que en la
  # primera consulta a PostgreSQL de la Fase 1.
  php -m | grep -qx pdo_pgsql; \
  php -m | grep -qx pgsql; \
  php -m | grep -qx redis; \
  php -m | grep -qx sodium; \
  # El binario de Chromium tiene que estar donde `LARAVEL_PDF_CHROME_PATH`
  # apunta. Si no esta, la impresion de tarjetas falla en la primera llamada y
  # el mensaje de Browsershot no dice de donde viene.
  test -x /usr/bin/chromium-browser || test -x /usr/bin/chromium

# Puppeteer, que es lo que `browsershot.js` carga. Se instala GLOBAL y en una
# capa propia para que no la invalide ningun cambio del codigo de la aplicacion.
ENV PUPPETEER_SKIP_DOWNLOAD=true \
    PUPPETEER_EXECUTABLE_PATH=/usr/bin/chromium-browser
RUN set -eux; \
  npm install --global --omit=dev puppeteer@24; \
  npm cache clean --force

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
  install -d -o app -g app /var/www/html/vendor; \
  # Punto de montaje del destino de copias. Existe en la imagen con el
  # propietario correcto por el mismo motivo que vendor/: cuando Docker
  # inicializa un volumen vacio copia la propiedad del directorio de la IMAGEN,
  # y si el directorio no existe el volumen nace de root y la copia diaria
  # falla al escribir, en un contenedor que corre como uid 1000.
  install -d -o app -g app -m 0750 /var/backups/fichaje

# Scripts de operacion (tarea 1.18 y §11.6.1). Viajan DENTRO de la imagen, no
# montados desde el servidor: asi la version de backup.sh es siempre la de la
# version desplegada del producto, y `php artisan backup:run` no depende de que
# alguien haya copiado un fichero en el sitio correcto.
# Se enumeran uno a uno y no se copia el directorio entero a proposito: en
# infra/scripts/ tambien viven herramientas del repositorio (changelog.sh) que
# no pintan nada en el servidor de un cliente.
COPY infra/scripts/backup.sh infra/scripts/restore.sh infra/scripts/restore-drill.sh /opt/kronoqr/scripts/
COPY infra/scripts/lib/ /opt/kronoqr/scripts/lib/
RUN chmod 0755 /opt/kronoqr/scripts/*.sh

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

# La version del repositorio viaja DENTRO de la imagen, junto a la aplicacion.
# Es el respaldo de /api/v1/health cuando el despliegue no fija APP_VERSION ni
# IMAGE_TAG con un SemVer valido: sin este fichero, esa instalacion informaria
# 0.0.0 y una incidencia no se podria correlacionar con ninguna version
# (doc 02 §10.5, App\Support\Version\DeployedVersion).
COPY --chown=app:app VERSION ./VERSION

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
