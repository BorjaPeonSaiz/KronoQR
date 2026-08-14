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

# Como se ejecutan las herramientas del backend (doc 02 §10.1).
#
# En la maquina de desarrollo, dentro del contenedor `app`: es donde estan PHP
# 8.4 y sus extensiones, y donde vendor/ vive fuera del bind mount. El motivo,
# medido, esta junto al montaje en infra/compose.dev.yaml.
#
# En la CI, NO. El runner es Linux, el checkout esta en disco local y el
# problema del bind mount de NTFS no existe: medido en este repositorio, PHPStan
# tarda 46,8 s desde el bind mount de Windows y 3,9 s desde disco local. Levantar
# Compose en el runner solo anadiria minutos y piezas que se pueden romper, asi
# que la CI instala PHP y ejecuta las mismas ordenes directamente sobre backend/.
#
# Lo que importa: la orden y el umbral se escriben UNA vez, aqui o en los
# ficheros de configuracion (phpstan.neon, deptrac.yaml, pint.json), nunca
# tambien en el workflow. Un umbral escrito en dos sitios acaba divergiendo.
#
# GitHub Actions define CI=true por si mismo: el workflow no pasa ninguna
# bandera. Para reproducir en local lo que hace la CI:  make php-lint CI=true
ifeq ($(CI),true)
RUN_APP        := cd backend &&
RUN_APP_XDEBUG := cd backend && XDEBUG_MODE=coverage
else
RUN_APP        := $(COMPOSE_DEV) exec -T app
# Xdebug esta instalado pero con xdebug.mode=off, porque encenderlo cuesta
# rendimiento en cada peticion (infra/docker/php/Dockerfile). Cobertura y
# mutacion son las dos unicas cosas que lo necesitan, asi que lo encienden ellas
# y solo para su propio proceso, en vez de penalizar todo el entorno.
RUN_APP_XDEBUG := $(COMPOSE_DEV) exec -T -e XDEBUG_MODE=coverage app
endif

# Los pasos que hoy no pueden ejecutarse —mutacion sin dominio, ESLint sin
# frontends— tienen que decirlo. Un paso que pasa por vacio sin avisar es peor
# que no tenerlo: figura en verde como si hubiera comprobado algo. En la CI el
# aviso se emite como anotacion de GitHub para que salga en el resumen de la
# ejecucion, no enterrado en el log.
ifeq ($(CI),true)
notice = @echo "::notice title=KronoQR - paso omitido::$(1)"
else
notice = @echo "[make] $(1)"
endif

# Ficheros de shell del repositorio. Umbral del doc 02 §9.2: 0 hallazgos.
# Incluye los scripts de la propia CI: tambien son codigo (doc 02 §3.5).
SH_FILES := $(wildcard infra/scripts/*.sh) \
            $(wildcard infra/docker/*/*.sh) \
            $(wildcard infra/docker/*/*/*.sh) \
            $(wildcard .github/scripts/*.sh)

# Versiones fijadas de las herramientas de shell. Se declaran aqui, y no en el
# workflow, porque el workflow las LEE de aqui (objetivo `tool-versions`): la
# maquina de desarrollo y la CI tienen que comprobar con la misma version, o el
# umbral de 0 hallazgos depende de quien ejecute.
SHELLCHECK_VERSION := v0.11.0
SHFMT_VERSION      := v3.13.1
# @redocly/cli valida el contrato OpenAPI (docs/api/redocly.yaml). Fijada por el
# mismo motivo que las dos de arriba: entre versiones cambian las reglas del
# preset `recommended`, y un umbral que depende de que version resuelva npx hoy
# no es un umbral. Comprobado: 2.19.0 no avisa de esquemas de seguridad sin usar
# y 2.46.1 si.
REDOCLY_VERSION    := 2.46.1

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
SHELLCHECK := $(DOCKER_RUN) koalaman/shellcheck:$(SHELLCHECK_VERSION)
endif

SHFMT := $(shell command -v shfmt 2>/dev/null)
ifeq ($(SHFMT),)
SHFMT := $(DOCKER_RUN) mvdan/shfmt:$(SHFMT_VERSION)
endif

# @redocly/cli no se instala en el repositorio: no es una dependencia de ninguna
# de las tres aplicaciones —el contrato no es de nadie en particular—, asi que
# meterlo en el package.json de una de ellas seria arbitrario. Se usa del PATH si
# esta y con `npx` si no, que es el equivalente del contenedor para una
# herramienta de Node. Node ya hace falta para los tres frontends, asi que no
# añade ningun requisito nuevo a la maquina.
REDOCLY := $(shell command -v redocly 2>/dev/null)
ifeq ($(REDOCLY),)
REDOCLY := npx --yes @redocly/cli@$(REDOCLY_VERSION)
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
# El aviso solo se imprime fuera de la CI, y no por ahorrar ruido: `$(info ...)`
# escribe en la SALIDA ESTANDAR, asi que contamina la de cualquier objetivo que
# alguien consuma. La CI hace `eval "$(make -s tool-versions)"` en un checkout
# limpio —donde .env nunca existe— y se comia esta linea como si fuera una orden.
ifneq ($(CI),true)
$(info [make] No habia .env: se crea a partir de .env.example. Revisalo antes de ir a produccion.)
endif
$(file >.env,$(file <.env.example))
endif

.DEFAULT_GOAL := help
.PHONY: help up down restart build ps logs shell seed test test-unit test-integration \
        test-arch quality tools-ready php-lint deptrac rector sh-lint api-lint sast \
        traceability traceability-check docs-consistency deps-audit-php deps-audit-js coverage coverage-now mutate e2e clean changelog changelog-check tool-versions

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
	@echo   make test-arch        Solo las pruebas de arquitectura (Pest Arch)
	@echo   make quality          Pint + PHPStan 9 + Deptrac + Rector + ShellCheck + shfmt
	@echo   make php-lint         Solo Pint y PHPStan       (etapa 1 de la CI)
	@echo   make deptrac          Solo Deptrac              (etapa 2 de la CI)
	@echo   make rector           Solo Rector, informativo  (etapa 1 de la CI)
	@echo   make sh-lint          Solo ShellCheck y shfmt   (etapa 1 de la CI)
	@echo   make api-lint         Contrato OpenAPI 3.1      (etapa 1 de la CI)
	@echo   make sast             Semgrep: reglas propias de .semgrep
	@echo   make traceability     Matriz requisito - prueba (RQ-13)
	@echo   make traceability-check  Falla si un requisito no tiene prueba
	@echo   make docs-consistency  Coherencia documental (RQ-12, RNF-M-04)
	@echo   make coverage         Cobertura: dominio 90, global 75 por ciento
	@echo   make coverage-now     Cobertura actual, sin umbral
	@echo   make mutate           Mutacion sobre el dominio, MSI 80 por ciento
	@echo   make e2e              Playwright con camara simulada
	@echo   make changelog        Genera el CHANGELOG desde los commits convencionales
	@echo   make changelog-check  Comprueba que una version tiene entrada (VERSION=1.2.3)
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
	$(RUN_APP) php artisan test
endif

test-unit: ## Dominio puro, sin base de datos, menos de 2 s
ifeq ($(wildcard backend/artisan),)
	@echo [make] La aplicacion Laravel llega en la tarea 0.2: todavia no hay suite que ejecutar.
else
	$(RUN_APP) php artisan test --testsuite=Unit
endif

test-integration: ## Repositorios contra PostgreSQL real
ifeq ($(wildcard backend/artisan),)
	@echo [make] La aplicacion Laravel llega en la tarea 0.2: todavia no hay suite que ejecutar.
else
	$(RUN_APP) php artisan test --testsuite=Integration
endif

# Etapa 2 de la CI junto a Deptrac. Son las dos mitades de la misma frontera:
# Deptrac razona sobre imports y Pest Arch ve lo que no aparece en ningun `use`
# —now(), time(), strtotime()—, que es la regla dura 2.
test-arch: ## Pruebas de arquitectura (doc 02 §9.2, etapa 2 de la CI)
ifeq ($(wildcard backend/artisan),)
	@echo [make] La aplicacion Laravel llega en la tarea 0.2: todavia no hay suite que ejecutar.
else
	$(RUN_APP) php artisan test --testsuite=Architecture
endif

# Orden deliberado: primero lo barato y lo que mas veces falla (estilo), luego
# tipos, luego fronteras. Rector va el ultimo porque no bloquea.
#
# Esta descompuesto en objetivos por herramienta desde la tarea 0.4 y no por
# gusto: la CI reparte las mismas ordenes en etapas distintas —Pint y PHPStan en
# la (1), Deptrac en la (2)— y necesita invocarlas por separado. La alternativa
# era escribir las ordenes otra vez dentro del workflow, que es exactamente como
# los umbrales acaban divergiendo entre el portatil y el runner.
quality: sh-lint api-lint php-lint deptrac rector ## Cadena de calidad completa (doc 02 §9.2)
	@echo [make] Calidad: contrato OpenAPI, Pint, PHPStan 9 y Deptrac en verde.

# Guarda comun de las herramientas de PHP. Sin vendor/ no hay nada que ejecutar,
# y es mejor decirlo que fallar con "command not found".
tools-ready:
ifeq ($(wildcard backend/vendor/bin/pint),)
	@echo [make] Faltan las herramientas en backend/vendor: no hay nada que ejecutar.
	@echo [make] En tu maquina:  make up
	@echo [make] En la CI:       composer install --working-dir=backend
	@exit 1
endif

php-lint: tools-ready ## Estilo y tipos: Pint + PHPStan 9 (etapa 1 de la CI)
	$(RUN_APP) vendor/bin/pint --test
	$(RUN_APP) vendor/bin/phpstan analyse --memory-limit=1G --no-progress

deptrac: tools-ready ## Fronteras entre capas y modulos (etapa 2 de la CI)
	$(RUN_APP) vendor/bin/deptrac analyse --fail-on-uncovered --no-progress

rector: tools-ready ## Modernizacion: informativo, NO bloquea (doc 02 §9.2)
	@echo "[make] Rector: umbral informativo (doc 02 seccion 9.2). Sus sugerencias no bloquean."
	-$(RUN_APP) vendor/bin/rector process --dry-run --no-progress-bar

# La tercera comprobacion de sh-lint no sobra: ShellCheck NO verifica que un
# script empiece por `set -euo pipefail`. Comprobado sobre este repositorio, un
# script sin esa linea pasa ShellCheck y shfmt sin un solo hallazgo. El doc 02
# §3.5 exige la linea y atribuye su verificacion a ShellCheck; sin esto, la fila
# de "Robustez" seria una sugerencia con aspecto de regla. Y la diferencia es
# real: un backup.sh sin `set -e` sigue adelante despues de fallar y termina
# anunciando una copia que no existe.
sh-lint: ## ShellCheck y shfmt sobre los scripts (umbral: 0 hallazgos)
ifeq ($(SH_FILES),)
	@echo [make] No hay scripts de shell que analizar.
else
	$(SHELLCHECK) $(SH_FILES)
	$(SHFMT) -i 2 -d $(SH_FILES)
	@fallos=0; \
	for f in $(SH_FILES); do \
	  grep -qE '^[[:space:]]*set -euo pipefail[[:space:]]*$$' "$$f" || { \
	    echo "$$f: falta 'set -euo pipefail'. Anadelo tras la cabecera del script (doc 02 seccion 3.5)."; \
	    fallos=1; }; \
	  grep -qE "^[[:space:]]*IFS=" "$$f" || { \
	    echo "$$f: falta IFS. Anade IFS=\$$'\\\\n\\\\t' junto al set -euo pipefail (doc 02 seccion 3.5)."; \
	    fallos=1; }; \
	done; \
	if [ "$$fallos" -ne 0 ]; then \
	  echo "[make] Robustez de scripts: hallazgos. Umbral del doc 02 seccion 9.2: 0."; \
	  exit 1; \
	fi
	@echo [make] ShellCheck, shfmt y robustez: 0 hallazgos.
endif

# El contrato es la fuente de verdad de la API (ADR-013) y se modifica antes que
# el codigo. Si nadie lo comprueba, el fichero que manda sobre la forma de cada
# endpoint es el unico artefacto del repositorio sin herramienta detras.
#
# Umbral: 0 problemas, avisos incluidos. Las reglas del preset `recommended` que
# de verdad importan estan elevadas a `error` en docs/api/redocly.yaml, y las
# excepciones estan enumeradas una a una, con motivo, en
# docs/api/.redocly.lint-ignore.yaml.
api-lint: ## Valida docs/api/openapi.yaml como OpenAPI 3.1 (umbral: 0 problemas)
ifeq ($(wildcard docs/api/openapi.yaml),)
	@echo [make] No existe docs/api/openapi.yaml: no hay contrato que validar.
	@exit 1
else
	$(REDOCLY) lint --config docs/api/redocly.yaml
	@echo [make] Contrato OpenAPI 3.1: 0 problemas.
endif

deps-audit-php: tools-ready ## composer audit (RS-10, umbral: 0 vulnerabilidades)
	$(RUN_APP) composer audit --no-interaction
	@echo "[make] composer audit: sin avisos de seguridad."

# --audit-level=high: el umbral del §9.2 es "0 vulnerabilidades criticas o
# altas". Las moderadas y bajas se ven en el informe pero no bloquean, porque
# una puerta que salta por un aviso informativo se acaba desactivando entera.
deps-audit-js: ## npm audit en los tres frontends (RS-10, 0 criticas ni altas)
	@for app in frontend-kiosk frontend-admin frontend-portal; do \
		if [ -f "$$app/package-lock.json" ]; then \
			echo "[make] npm audit en $$app"; \
			npm audit --prefix "$$app" --audit-level=high || exit 1; \
		fi; \
	done
	@echo "[make] npm audit: 0 vulnerabilidades criticas ni altas."

sast: ## Semgrep sobre las reglas de .semgrep (umbral: 0 hallazgos ERROR)
	$(SEMGREP) --config .semgrep --error --metrics=off --quiet
	@echo [make] Semgrep: 0 hallazgos de severidad alta.

# La matriz se escribe DESDE FUERA del contenedor. docs/ se monta de solo
# lectura (por lo mismo que la raiz del repositorio: nada de lo que corre
# dentro tiene por que escribir en el arbol de fuentes), asi que el comando
# emite la matriz por la salida estandar con --output=- y es make quien la
# guarda. Sus avisos van a la salida de error y no contaminan el fichero.
traceability: ## Genera docs/trazabilidad-pruebas.md (RQ-13, doc 02 seccion 9.6)
	( $(RUN_APP) php artisan qa:traceability --output=- ) >docs/trazabilidad-pruebas.md
	@echo "[make] Matriz escrita en docs/trazabilidad-pruebas.md"

traceability-check: ## Falla si un requisito ya implementado no tiene prueba (RQ-13)
	$(RUN_APP) php artisan qa:traceability --check

docs-consistency: ## Coherencia entre los documentos y los ficheros que los ejecutan
	$(RUN_APP) php artisan docs:consistency --check

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
	$(RUN_APP_XDEBUG) $(PEST) --coverage --min=75
endif

coverage-now: ## Cobertura actual sin umbral, util antes de que exista el dominio
	$(RUN_APP_XDEBUG) $(PEST) --coverage

mutate: ## Mutacion sobre el dominio, MSI mayor o igual a 80 por ciento
ifeq ($(wildcard backend/app/Modules/Attendance/Domain/Model/*.php),)
	$(call notice,Mutacion NO ejecutada: Modules/*/Domain no existe todavia.)
	@echo "[make] La mutacion se ejecuta sobre Modules/*/Domain, que se escribe en la"
	@echo "[make] tarea 1.1. Sin dominio no hay mutantes: el umbral MSI >= 80 por ciento"
	@echo "[make] (doc 02 seccion 9.2, RQ-10) empieza a exigirse en la tarea 1.2."
	@echo "[make] Este paso se activa solo en cuanto exista el primer modelo de dominio."
else
	$(RUN_APP_XDEBUG) $(PEST) --mutate --covered-only --min=80
endif

e2e: ## Playwright con camara simulada
ifeq ($(wildcard frontend-kiosk/package.json),)
	@echo [make] Los frontends llegan en la tarea 0.5: todavia no hay E2E que ejecutar.
else
	npm --prefix frontend-kiosk run test:e2e
endif

#--- Versionado (doc 02 §10.5) ------------------------------------------------
# El CHANGELOG se GENERA de los mensajes de commit convencionales, no se escribe
# a mano, y ninguna version se publica sin su entrada. Es lo que permite que el
# actualizador de la tarea 5.7 diga al cliente que cambia antes de aplicar nada.

changelog: ## Regenera la seccion [Unreleased] del CHANGELOG desde los commits
	bash infra/scripts/changelog.sh generate --write

changelog-check: ## Comprueba el CHANGELOG. Con VERSION=1.2.3, exige su entrada
	bash infra/scripts/changelog.sh check $(VERSION)

# Lo lee .github/workflows/ci.yml con `eval "$$(make -s tool-versions)"`, para
# instalar en el runner exactamente las mismas versiones que se usan aqui.
tool-versions: ## Imprime las versiones fijadas de las herramientas externas
	@echo SHELLCHECK_VERSION=$(SHELLCHECK_VERSION)
	@echo SHFMT_VERSION=$(SHFMT_VERSION)
	@echo REDOCLY_VERSION=$(REDOCLY_VERSION)

clean: ## Para el entorno y BORRA los volumenes de datos
	$(COMPOSE_DEV) down -v --remove-orphans
