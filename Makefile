# KronoQR — interfaz oficial del repositorio.
#
# CLAUDE.md documenta estos comandos: up, test, test-unit, quality, mutate, e2e.
# Se ejecutan SIEMPRE desde la raiz del repositorio.
#
# Por que --env-file: el fichero de compose vive en infra/, asi que Docker
# Compose buscaria .env en infra/. El .env canonico esta en la raiz, junto a
# .env.example, y se lo pasamos explicitamente. Si invocas docker compose a
# mano, anade la misma bandera.

# make up tiene que ser idempotente: repetirlo sobre un entorno sano no debe
# tocar nada. Por defecto BuildKit adjunta metadatos de procedencia a cada
# construccion, lo que cambia el identificador de la imagen aunque el contenido
# sea identico, y Compose recrea los 9 contenedores en cada invocacion. Con
# esto, una construccion sin cambios devuelve la misma imagen y Compose deja el
# entorno en paz. Las atestaciones se generan en la publicacion (tarea 5.x),
# que es donde sirven para algo.
export BUILDX_NO_DEFAULT_ATTESTATIONS := 1

COMPOSE_DEV  := docker compose --env-file .env -f infra/compose.dev.yaml
COMPOSE_PROD := docker compose --env-file .env -f infra/compose.prod.yaml

# Ficheros de shell del repositorio. Umbral del doc 02 §9.2: 0 hallazgos.
SH_FILES := $(wildcard infra/scripts/*.sh) \
            $(wildcard infra/docker/*/*.sh) \
            $(wildcard infra/docker/*/*/*.sh)

# Ruta del repositorio tal y como la entiende el demonio de Docker. En Git Bash
# hace falta `pwd -W` (D:/...); en Linux y macOS `pwd` ya vale.
HOST_PWD := $(shell pwd -W 2>/dev/null || pwd)
DOCKER_RUN := MSYS_NO_PATHCONV=1 docker run --rm -v "$(HOST_PWD):/mnt" -w /mnt

# ShellCheck, shfmt y Semgrep no estan instalados en la maquina de quien
# desarrolla —en Windows casi nunca—, asi que se usan del PATH si estan y en
# contenedor si no. El umbral del §9.2 no puede depender de lo que cada uno
# tenga instalado: la misma orden tiene que dar el mismo resultado en el
# portatil y en la CI.
SHELLCHECK := $(shell command -v shellcheck 2>/dev/null)
ifeq ($(SHELLCHECK),)
SHELLCHECK := $(DOCKER_RUN) koalaman/shellcheck:stable
endif

SHFMT := $(shell command -v shfmt 2>/dev/null)
ifeq ($(SHFMT),)
SHFMT := $(DOCKER_RUN) mvdan/shfmt:v3
endif

# La imagen de Semgrep exige el codigo en /src y se niega a analizar otra ruta.
SEMGREP := $(shell command -v semgrep 2>/dev/null)
ifeq ($(SEMGREP),)
SEMGREP := MSYS_NO_PATHCONV=1 docker run --rm -v "$(HOST_PWD):/src" -w /src semgrep/semgrep:latest semgrep
endif

# El .env se crea la primera vez a partir de .env.example, sin sobrescribir
# nunca uno existente. Se hace con funciones de make, no con cp, para que
# funcione igual en Windows, Linux y macOS.
ifeq ($(wildcard .env),)
$(info [make] No habia .env: se crea a partir de .env.example. Revisalo antes de ir a produccion.)
$(file >.env,$(file <.env.example))
endif

.DEFAULT_GOAL := help
.PHONY: help up down restart build ps logs shell seed test test-unit test-integration \
        quality sh-lint sast coverage coverage-now mutate e2e clean

help: ## Muestra esta ayuda
	@echo KronoQR - objetivos disponibles:
	@echo   make up               Levanta el entorno completo de desarrollo
	@echo   make down             Para el entorno y conserva los volumenes
	@echo   make restart          Reinicia los servicios
	@echo   make build            Reconstruye las imagenes (tras tocar un Dockerfile)
	@echo   make ps               Estado de los 14 servicios
	@echo   make logs             Sigue los logs de todos los servicios
	@echo   make shell            Abre una shell en el contenedor app
	@echo   make seed             Carga la semilla de desarrollo
	@echo   make test             Toda la suite
	@echo   make test-unit        Dominio, sin base de datos
	@echo   make quality          Pint + PHPStan 9 + Deptrac + Rector + ShellCheck + shfmt
	@echo   make sh-lint          Solo ShellCheck y shfmt
	@echo   make sast             Semgrep: reglas propias de .semgrep
	@echo   make coverage         Cobertura: dominio 90, global 75 por ciento
	@echo   make coverage-now     Cobertura actual, sin umbral
	@echo   make mutate           Mutacion sobre el dominio, MSI 80 por ciento
	@echo   make e2e              Playwright con camara simulada
	@echo   make clean            Para el entorno y BORRA los volumenes

up: ## Levanta el entorno completo
	# Sin --build a proposito. Compose construye solo lo que falte, asi que en
	# una maquina limpia esto levanta todo, y repetirlo sobre un entorno sano no
	# recrea ningun contenedor. Si tocas un Dockerfile: make build.
	$(COMPOSE_DEV) up -d --remove-orphans
	@echo [make] ----------------------------------------------------------
	@echo [make] Entorno levantado. Comprueba el estado con: make ps
	@echo [make]   Panel de gestion   https://localhost/admin/
	@echo [make]   Quiosco            https://localhost/kiosk/
	@echo [make]   Portal empleado    https://localhost/portal/
	@echo [make]   Mailpit            http://localhost:8025
	@echo [make]   Prometheus         http://localhost:9090
	@echo [make]   Grafana            http://localhost:3000
	@echo [make] El certificado TLS de desarrollo es autofirmado: usa curl -k.

down: ## Para el entorno conservando los datos
	$(COMPOSE_DEV) down --remove-orphans

restart: ## Reinicia los servicios
	$(COMPOSE_DEV) restart

build: ## Reconstruye las imagenes
	$(COMPOSE_DEV) build --pull

ps: ## Estado de los servicios
	$(COMPOSE_DEV) ps

logs: ## Sigue los logs
	$(COMPOSE_DEV) logs -f --tail=100

shell: ## Shell en el contenedor de la aplicacion
	$(COMPOSE_DEV) exec app sh

seed: ## Semilla de desarrollo (doc 02 §10.2)
ifeq ($(wildcard backend/artisan),)
	@echo [make] La aplicacion Laravel llega en la tarea 0.2: todavia no hay artisan que ejecutar.
	@echo [make] El esqueleto de la semilla ya existe en backend/database/seeders/DatabaseSeeder.php
else
	$(COMPOSE_DEV) exec -T app php artisan migrate --force
	$(COMPOSE_DEV) exec -T app php artisan db:seed --force
endif

test: ## Toda la suite
ifeq ($(wildcard backend/artisan),)
	@echo [make] La aplicacion Laravel llega en la tarea 0.2: todavia no hay suite que ejecutar.
else
	$(COMPOSE_DEV) exec -T app php artisan test
endif

test-unit: ## Dominio puro, sin base de datos, menos de 2 s
ifeq ($(wildcard backend/artisan),)
	@echo [make] La aplicacion Laravel llega en la tarea 0.2: todavia no hay suite que ejecutar.
else
	$(COMPOSE_DEV) exec -T app php artisan test --testsuite=Unit
endif

test-integration: ## Repositorios contra PostgreSQL real
ifeq ($(wildcard backend/artisan),)
	@echo [make] La aplicacion Laravel llega en la tarea 0.2: todavia no hay suite que ejecutar.
else
	$(COMPOSE_DEV) exec -T app php artisan test --testsuite=Integration
endif

# Orden deliberado: primero lo barato y lo que mas veces falla (estilo), luego
# tipos, luego fronteras. Rector va el ultimo porque no bloquea.
quality: sh-lint ## Cadena de calidad completa (doc 02 §9.2)
ifeq ($(wildcard backend/vendor/bin/pint),)
	@echo [make] Faltan las herramientas en backend/vendor: no hay nada que ejecutar.
	@echo [make] Levanta el entorno e instala dependencias con: make up
	@exit 1
else
	$(COMPOSE_DEV) exec -T app vendor/bin/pint --test
	$(COMPOSE_DEV) exec -T app vendor/bin/phpstan analyse --memory-limit=1G --no-progress
	$(COMPOSE_DEV) exec -T app vendor/bin/deptrac analyse --fail-on-uncovered --no-progress
	@echo "[make] Rector: umbral informativo (doc 02 seccion 9.2). Sus sugerencias no bloquean."
	-$(COMPOSE_DEV) exec -T app vendor/bin/rector process --dry-run --no-progress-bar
	@echo [make] Calidad: Pint, PHPStan 9 y Deptrac en verde.
endif

sh-lint: ## ShellCheck y shfmt sobre los scripts (umbral: 0 hallazgos)
ifeq ($(SH_FILES),)
	@echo [make] No hay scripts de shell que analizar.
else
	$(SHELLCHECK) $(SH_FILES)
	$(SHFMT) -i 2 -d $(SH_FILES)
	@echo [make] ShellCheck y shfmt: 0 hallazgos.
endif

sast: ## Semgrep sobre las reglas de .semgrep (umbral: 0 hallazgos ERROR)
	$(SEMGREP) --config .semgrep --error --metrics=off --quiet
	@echo [make] Semgrep: 0 hallazgos de severidad alta.

# Xdebug esta instalado pero con xdebug.mode=off, porque encenderlo cuesta
# rendimiento en cada peticion (infra/docker/php/Dockerfile). Cobertura y
# mutacion son las dos unicas cosas que lo necesitan, asi que lo encienden ellas
# y solo para su propio proceso, en vez de penalizar todo el entorno.
XDEBUG_COVERAGE := -e XDEBUG_MODE=coverage

# Estos dos objetivos llaman a vendor/bin/pest y no a `php artisan test`, que es
# lo que usa el resto del fichero. No es un descuido: `artisan test --coverage`
# termina en verde sin emitir informe y sin aplicar --min, asi que el umbral
# pasaria SIEMPRE. Un umbral que no puede fallar es peor que no tenerlo, porque
# figura en la CI como si protegiera algo. Comprobado: con pest el informe sale
# y --min bloquea; con artisan, ni una cosa ni la otra.
PEST := vendor/bin/pest

coverage: ## Cobertura: dominio >= 90 por ciento, global >= 75 (doc 02 seccion 9.2)
ifeq ($(wildcard backend/app/Modules/Attendance/Domain/Model/*.php),)
	@echo "[make] El dominio llega en la tarea 1.1: todavia no hay cobertura exigible."
	@echo "[make] Para ver la cobertura actual sin umbral: make coverage-now"
else
	$(COMPOSE_DEV) exec -T $(XDEBUG_COVERAGE) app $(PEST) --coverage --min=75
endif

coverage-now: ## Cobertura actual sin umbral, util antes de que exista el dominio
	$(COMPOSE_DEV) exec -T $(XDEBUG_COVERAGE) app $(PEST) --coverage

mutate: ## Mutacion sobre el dominio, MSI mayor o igual a 80 por ciento
ifeq ($(wildcard backend/app/Modules/Attendance/Domain/Model/*.php),)
	@echo "[make] La mutacion se ejecuta sobre Modules/*/Domain, que se escribe en la"
	@echo "[make] tarea 1.1. Sin dominio no hay mutantes: el umbral MSI >= 80 por ciento"
	@echo "[make] (doc 02 seccion 9.2, RQ-10) empieza a exigirse en la tarea 1.2."
else
	$(COMPOSE_DEV) exec -T $(XDEBUG_COVERAGE) app $(PEST) --mutate --covered-only --min=80
endif

e2e: ## Playwright con camara simulada
ifeq ($(wildcard frontend-kiosk/package.json),)
	@echo [make] Los frontends llegan en la tarea 0.5: todavia no hay E2E que ejecutar.
else
	npm --prefix frontend-kiosk run test:e2e
endif

clean: ## Para el entorno y BORRA los volumenes de datos
	$(COMPOSE_DEV) down -v --remove-orphans
