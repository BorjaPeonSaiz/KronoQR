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
            $(wildcard infra/scripts/lib/*.sh) \
            $(wildcard infra/docker/*/*.sh) \
            $(wildcard infra/docker/*/*/*.sh) \
            $(wildcard .github/scripts/*.sh) \
            $(wildcard load-tests/k6/*.sh)

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

# gitleaks y Trivy invocan `git` internamente (gitleaks para leer el historico,
# Trivy fs para resolver el estado del repositorio) y las dos imagenes corren
# como root. Sobre el checkout de un runner de GitHub Actions -- propiedad de
# un uid que no es root, tipicamente 1001 -- git desde 2.35.2 se niega a operar
# con "detected dubious ownership in repository at '/mnt'" (mitigacion de
# CVE-2022-24765) y el paso aborta antes de escanear nada. En local, en
# cambio, el uid del contenedor y el del bind mount suelen coincidir y el
# problema no aparece: por eso no se detecto hasta ejecutar en el runner
# (BLOQUEANTE 2 de la auditoria). `safe.directory` marca `/mnt` como excepcion
# de confianza SOLO dentro de este contenedor de usar y tirar, nunca en la
# configuracion de git de quien ejecuta `make`.
GIT_SAFE_DIRECTORY_ENV := -e GIT_CONFIG_COUNT=1 -e GIT_CONFIG_KEY_0=safe.directory -e GIT_CONFIG_VALUE_0=/mnt

DOCKER_RUN := MSYS_NO_PATHCONV=1 docker run --rm $(GIT_SAFE_DIRECTORY_ENV) -v "$(HOST_PWD):/mnt" -w /mnt

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
#
# Fijada por version Y por digest (MENOR 14 de la auditoria: el comentario de
# mas abajo ya prometia una version fijada y la imagen seguia en `:latest`,
# que cambia de contenido sin que este fichero cambie de una linea). El digest
# hace immutable el TAG mismo -- sin el, `semgrep/semgrep:1.175.0` podria
# volver a publicarse con otro contenido, algo que Docker Hub permite.
SEMGREP_VERSION := 1.175.0
SEMGREP := $(shell command -v semgrep 2>/dev/null)
ifeq ($(SEMGREP),)
SEMGREP := MSYS_NO_PATHCONV=1 docker run --rm -v "$(HOST_PWD):/src" -w /src semgrep/semgrep:$(SEMGREP_VERSION)@sha256:b94b53d02fd4a022f9eac4e2af1380f5c3c4c21400e79d3336bdff1d1db5e796 semgrep
endif

# Trivy y gitleaks, mismo patron que ShellCheck/shfmt/Semgrep: binario del PATH
# si esta, contenedor fijado por version si no. Fijados porque son nuevos aqui
# (tarea de SSDLC, doc 02 §9.2): sin version, el umbral "0 hallazgos" de
# gitleaks dependeria de que base de datos de reglas trajera la imagen `latest`
# el dia que alguien la ejecute.
#
# CAVEAT que la version NO cubre: `--config p/php` y compania en
# `sast-community` resuelven el contenido de esas reglas del registro de
# Semgrep EN CADA EJECUCION, fijar la version del binario de Semgrep no fija el
# contenido de esos paquetes comunitarios. Es una limitacion conocida de usar
# alias del registro en lugar de un fichero de reglas propio, y por eso ese
# objetivo es informativo (ver el TODO fechado en ci.yml) hasta que se decida
# si conviene vendorizarlas.
TRIVY_VERSION    := 0.74.0
GITLEAKS_VERSION := v8.30.1

TRIVY := $(shell command -v trivy 2>/dev/null)
ifeq ($(TRIVY),)
TRIVY := $(DOCKER_RUN) aquasec/trivy:$(TRIVY_VERSION)
endif

# `trivy image` inspecciona una imagen YA CONSTRUIDA hablando con el demonio de
# Docker: necesita el socket, que $(DOCKER_RUN) no monta (los demas usos de esa
# variable solo leen ficheros del repositorio).
TRIVY_IMAGE_CMD := $(shell command -v trivy 2>/dev/null)
ifeq ($(TRIVY_IMAGE_CMD),)
TRIVY_IMAGE_CMD := MSYS_NO_PATHCONV=1 docker run --rm -v /var/run/docker.sock:/var/run/docker.sock -v "$(HOST_PWD):/mnt" -w /mnt aquasec/trivy:$(TRIVY_VERSION)
endif

GITLEAKS := $(shell command -v gitleaks 2>/dev/null)
ifeq ($(GITLEAKS),)
GITLEAKS := $(DOCKER_RUN) zricethezav/gitleaks:$(GITLEAKS_VERSION)
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
        test-arch test-contract quality tools-ready php-lint deptrac rector sh-lint api-lint sast \
        sast-community trivy-fs trivy-image secrets-scan sbom build-ci-images release-gate \
        traceability traceability-check docs-consistency deps-audit-php deps-audit-js coverage coverage-now mutate e2e clean changelog changelog-check tool-versions \
        backup backup-verify restore-drill

help: ## Muestra esta ayuda
	@echo KronoQR - objetivos disponibles:
	@echo   make up               Levanta el entorno completo de desarrollo
	@echo   make down             Para el entorno y conserva los volumenes
	@echo   make restart          Reinicia los servicios
	@echo   make build            Reconstruye las imagenes (tras tocar un Dockerfile)
	@echo   make ps               Estado de los 15 servicios
	@echo   make logs             Sigue los logs de todos los servicios
	@echo   make shell            Abre una shell en el contenedor app
	@echo   make migrate          Migra con el rol de migracion (regla dura 6)
	@echo   make migrate-fresh    Recrea el esquema desde cero
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
	@echo   make sast             Semgrep: reglas propias de .semgrep (bloqueante)
	@echo   make sast-community   Semgrep: reglas comunitarias PHP/JS/TS/OWASP (bloqueante)
	@echo   make trivy-fs         Trivy: dependencias, Dockerfiles y secretos del repo (informe)
	@echo   make trivy-image      Trivy: postgres:ci y app:ci ya construidas (informe)
	@echo   make secrets-scan     gitleaks sobre el historico completo (bloqueante)
	@echo   make sbom             SBOM CycloneDX en sbom/kronoqr-VERSION.cdx.json
	@echo   make build-ci-images  Construye kronoqr/{postgres,app,nginx}:ci (IMAGES=postgres|app|nginx)
	@echo   make release-gate     Falla si la entrega saldria sin clave publica del fabricante
	@echo   make traceability     Matriz requisito - prueba (RQ-13)
	@echo   make traceability-check  Falla si un requisito no tiene prueba
	@echo   make docs-consistency  Coherencia documental (RQ-12, RNF-M-04)
	@echo   make coverage         Cobertura: dominio 90, global 75 por ciento
	@echo   make coverage-now     Cobertura actual, sin umbral
	@echo   make mutate           Mutacion sobre el dominio, MSI 80 por ciento
	@echo   make e2e              Playwright con camara simulada
	@echo   make changelog        Genera el CHANGELOG desde los commits convencionales
	@echo   make changelog-check  Comprueba que una version tiene entrada (VERSION=1.2.3)
	@echo   make backup           Copia cifrada y verificada del entorno de desarrollo
	@echo   make backup-verify    Verifica la ultima copia (huella, descifrado, indice)
	@echo   make restore-drill    Simulacro: restaura en contenedor limpio y valida
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

# Las migraciones NO corren con el usuario de la aplicacion, y no es un detalle
# de configuracion (tarea 1.14, regla dura 6). `fichaje_app` no tiene DDL y no es
# propietario de nada: sobre un superusuario PostgreSQL ni comprueba los GRANT, y
# un propietario puede volver a otorgarse lo que se le revoque, asi que con un
# solo rol el "sin UPDATE ni DELETE sobre audit_log" no seria una garantia.
#
# La conexion `pgsql_migrator` esta declarada en backend/config/database.php y
# usa DB_MIGRATION_USERNAME/DB_MIGRATION_PASSWORD. La primera migracion se niega
# a correr si detecta que la lanza el rol de la aplicacion.
MIGRATE_DB = --database=pgsql_migrator

migrate: ## Aplica las migraciones con el rol de migracion (regla dura 6)
ifeq ($(wildcard backend/artisan),)
	@echo [make] No hay artisan que ejecutar.
else
	$(COMPOSE_DEV) exec -T app php artisan migrate $(MIGRATE_DB) --force
endif

migrate-fresh: ## Recrea el esquema desde cero con el rol de migracion
ifeq ($(wildcard backend/artisan),)
	@echo [make] No hay artisan que ejecutar.
else
	$(COMPOSE_DEV) exec -T app php artisan migrate:fresh $(MIGRATE_DB) --force
endif

seed: ## Semilla de desarrollo (doc 02 §10.2)
ifeq ($(wildcard backend/artisan),)
	@echo [make] La aplicacion Laravel llega en la tarea 0.2: todavia no hay artisan que ejecutar.
	@echo [make] El esqueleto de la semilla ya existe en backend/database/seeders/DatabaseSeeder.php
else
	$(COMPOSE_DEV) exec -T app php artisan migrate $(MIGRATE_DB) --force
	$(COMPOSE_DEV) exec -T app php artisan db:seed --force
endif

test: ## Toda la suite
ifeq ($(wildcard backend/artisan),)
	@echo [make] La aplicacion Laravel llega en la tarea 0.2: todavia no hay suite que ejecutar.
else
	$(RUN_APP) php artisan test
endif

# El presupuesto de la suite unitaria (doc 02 §9.2, doc 03). El plan lo cita
# tres veces como bloqueante del camino critico y era el unico umbral de la
# tabla sin herramienta que lo verificase — y ya estaba superado (2,6-2,7 s
# medidos en el cierre de la Fase 1) sin que nada avisara. El valor es mas
# holgado que los "2 s" literales del plan porque se mide donde de verdad se
# ejecuta: el contenedor sobre Docker Desktop/NTFS paga arranque y E/S que un
# runner Linux no paga. Se puede apretar por entorno: make test-unit
# UNIT_SUITE_MAX_SECONDS=2
#
# 01-09-2026 (tarea 5.3): de 4 a 5 s. La suite paso de 1136 a 1240 pruebas —el
# dominio de licencia y la frontera de ADR-023— y midio 4,2 s. La subida NO es
# para acallar la puerta: el proposito del presupuesto es que la suite siga
# siendo lo bastante rapida como para ejecutarse en cada cambio, y a 4,2 s lo
# es. Lo que hay que vigilar es la PENDIENTE, no el numero: el coste marginal de
# esas 104 pruebas fue de 0,3 s (medido fichero a fichero, ~0,05 s cada uno
# sobre el arranque). Si una tarea futura vuelve a rozarlo, la pregunta correcta
# es si esas pruebas deberian ser unitarias, no si el techo puede subir otra
# vez.
UNIT_SUITE_MAX_SECONDS ?= 5

# La duracion se lee de la linea "Duration:" de Pest —el tiempo de la suite, no
# el del arranque de artisan ni el de docker exec— y se compara con awk porque
# sh no sabe de decimales. Si el formato de salida de Pest cambiara y la linea
# no apareciera, el gate avisa en vez de aprobar en silencio.
test-unit: ## Dominio puro, sin base de datos, con presupuesto de duracion
ifeq ($(wildcard backend/artisan),)
	@echo [make] La aplicacion Laravel llega en la tarea 0.2: todavia no hay suite que ejecutar.
else
	@$(RUN_APP) php artisan test --testsuite=Unit > .unit-suite.log 2>&1; \
	status=$$?; \
	cat .unit-suite.log; \
	dur=$$(grep 'Duration:' .unit-suite.log | grep -oE '[0-9]+\.[0-9]+' | tail -1); \
	rm -f .unit-suite.log; \
	if [ $$status -ne 0 ]; then exit $$status; fi; \
	if [ -z "$$dur" ]; then \
		echo "[make] AVISO: no se pudo leer la duracion de la suite; el presupuesto de $(UNIT_SUITE_MAX_SECONDS) s no se ha comprobado."; \
	elif awk "BEGIN { exit !($$dur > $(UNIT_SUITE_MAX_SECONDS)) }"; then \
		echo "[make] La suite unitaria ha tardado $$dur s y el presupuesto es $(UNIT_SUITE_MAX_SECONDS) s (doc 02 §9.2)."; \
		echo "[make] Una suite unitaria lenta deja de ejecutarse en cada cambio, que es su unica razon de ser."; \
		exit 1; \
	else \
		echo "[make] Suite unitaria en $$dur s, dentro del presupuesto de $(UNIT_SUITE_MAX_SECONDS) s."; \
	fi
endif

test-integration: ## Repositorios contra PostgreSQL real
ifeq ($(wildcard backend/artisan),)
	@echo [make] La aplicacion Laravel llega en la tarea 0.2: todavia no hay suite que ejecutar.
else
	$(RUN_APP) php artisan test --testsuite=Integration
endif

# Contract y Feature juntas y en cada push desde el cierre de la Fase 0.
#
# Hasta entonces su sitio era la etapa (4), que se ejecuta en pull request, y la
# etapa (4) no existia: ninguna de las dos corria en ningun push. Eso dejaba dos
# agujeros a la vez. El evidente, que una regresion en una prueba de contrato
# —OpenAPI es la autoridad #2— podia vivir meses sin que nadie la viera. Y el
# que no se ve: `qa:traceability` SI cuenta sus etiquetas, asi que un requisito
# podia figurar cubierto en la matriz por una prueba que la CI no ejecutaba
# nunca. La matriz es la evidencia de que cada obligacion legal esta verificada
# en cada cambio; con una suite parada, era una lista de intenciones.
#
# Cuestan segundos y no necesitan base de datos. Cuando la etapa (4) exista con
# Integration y E2E, esto se queda igual: son las baratas, y las baratas van en
# cada push.
test-contract: ## Contrato OpenAPI y feature, las dos suites que la CI ejecuta en cada push
ifeq ($(wildcard backend/artisan),)
	@echo [make] La aplicacion Laravel llega en la tarea 0.2: todavia no hay suite que ejecutar.
else
	$(RUN_APP) php artisan test --testsuite=Contract,Feature
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
#
# `set -Eeuo pipefail` tambien vale, y es lo que usa install.sh: la `-E` hace
# que un `trap ... ERR` se herede en funciones y subshells, sin la cual el
# manejador de vuelta atras no se dispararia desde dentro de una fase. Es
# estrictamente mas estricto que `-euo`, no una excepcion.
sh-lint: ## ShellCheck y shfmt sobre los scripts (umbral: 0 hallazgos)
ifeq ($(SH_FILES),)
	@echo [make] No hay scripts de shell que analizar.
else
	# -x: sigue los ficheros que los scripts cargan con `.` (infra/scripts/lib/).
	# Sin esta bandera ShellCheck no entra en el fichero cargado y avisa por no
	# poder seguirlo, que era el unico hallazgo del umbral desde la tarea 1.18:
	# la biblioteca comun quedaria fuera del analisis justo donde vive el
	# cifrado de las copias.
	$(SHELLCHECK) -x $(SH_FILES)
	$(SHFMT) -i 2 -d $(SH_FILES)
	@fallos=0; \
	for f in $(SH_FILES); do \
	  grep -qE '^[[:space:]]*set -E?euo pipefail[[:space:]]*$$' "$$f" || { \
	    echo "$$f: falta 'set -euo pipefail' o 'set -Eeuo pipefail'. Anadelo tras la cabecera del script (doc 02 seccion 3.5)."; \
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
# En la raiz y no por aplicacion: desde ADR-036 el repositorio es un workspace
# de npm con UN package-lock.json, que es el arbol que de verdad se instala. La
# version anterior iteraba las tres SPA buscando lockfiles propios y, como
# frontend-admin ya no tenia, se lo saltaba sin avisar e imprimia igualmente
# "0 vulnerabilidades" — una puerta que afirma lo que no ha comprobado
# (hallazgo de la auditoria de cierre de la Fase 1). El audit de raiz cubre las
# tres SPA y packages/web-kit de una vez.
deps-audit-js: ## npm audit del workspace completo (RS-10, 0 criticas ni altas)
	@if [ ! -f package-lock.json ]; then \
		echo "[make] No hay package-lock.json en la raiz: el workspace llega en la tarea 0.5."; \
	else \
		npm audit --audit-level=high || exit 1; \
		echo "[make] npm audit (workspace): 0 vulnerabilidades criticas ni altas."; \
	fi

sast: ## Semgrep sobre las reglas de .semgrep (umbral: 0 hallazgos ERROR)
	$(SEMGREP) --config .semgrep --error --metrics=off --quiet
	@echo [make] Semgrep: 0 hallazgos de severidad alta.

# Reglas COMUNITARIAS de Semgrep, distintas de las propias de .semgrep/ (que
# siguen siendo `make sast`, bloqueante). No incluye `p/secrets`: solapa con
# gitleaks (mismo tipo de hallazgo, patrones de claves y tokens en el
# contenido) y las dos herramientas a la vez sobre lo mismo solo duplicarian el
# ruido sin anadir cobertura -- gitleaks queda como la autoridad de secretos
# porque ademas recorre el HISTORICO, que Semgrep no toca.
#
# Triado el 29-08-2026 (docs/runbooks/triaje-hallazgos-seguridad.md): los 9
# hallazgos que daba —4 `dependabot-missing-cooldown` (corregidos con un
# enfriamiento de 7 dias) y 5 en infra/docker/nginx (3 `dynamic-proxy-host`
# de las rutas de desarrollo y 2 `request-host-used`, justificados uno a uno
# con `# nosemgrep` y su motivo)— quedan a 0, y el paso es BLOQUEANTE en la CI:
# un hallazgo nuevo rompe el job. La CI distingue «hay hallazgos» (el JSON los
# trae) de «Semgrep no pudo terminar» (codigo 2+) — IMPORTANTE 5 de la
# auditoria.
#
# --metrics=off: no se telemetria a Semgrep App lo que este repositorio analiza.
# Excluye lo que .semgrep/kronoqr-php.yaml ya excluye de facto por su `paths`,
# mas los directorios de dependencias y de artefactos de build.
#
# SEMGREP_FORMAT=json cambia la salida a JSON (para que la CI cuente hallazgos
# por severidad con `jq` sin volver a escanear); vacio por defecto, que es la
# salida legible que quiere quien lo ejecuta en su maquina.
# La receta va con `@` y traduce el codigo de salida: Semgrep devuelve 1 CON
# hallazgos y make convertiria ese 1 en un 2 --el mismo codigo con el que
# Semgrep dice "no pude terminar"--, y ademas haria eco de la orden al principio
# del JSON que la CI parsea con `jq`. Asi, 0 = hallazgos o no (los cuenta el
# JSON), 2+ = la herramienta fallo, que es lo unico que rompe el job.
sast-community: ## Semgrep: reglas comunitarias PHP/JS/TS/OWASP (umbral: 0 hallazgos; ver docs/runbooks/triaje-hallazgos-seguridad.md)
	@$(SEMGREP) \
	  --config p/php --config p/owasp-top-ten --config p/javascript --config p/typescript \
	  --error --metrics=off --quiet $(if $(filter json,$(SEMGREP_FORMAT)),--json,) \
	  --exclude node_modules --exclude vendor --exclude dist --exclude storage \
	  --exclude 'tests/fixtures' --exclude '*.fixture.*'; code=$$?; [ $$code -le 1 ] || { echo "[make] Semgrep no pudo terminar (codigo $$code)." >&2; exit $$code; }

# Las imagenes que la suite de pruebas y de seguridad necesitan CONTRA
# SOFTWARE REAL: PostgreSQL del producto (roles, extensiones, archivado de
# WAL) y la aplicacion. Un solo objetivo, en vez de la orden de build suelta
# en el job `unit`, en el job `security` y en backup-drill.yml: las tres
# podian divergir sin que nadie lo notase (IMPORTANTE 10 de la auditoria).
#
# IMAGES elige que imagenes construir (por defecto solo postgres, que es lo
# unico que necesitan el job `unit` y backup-drill.yml): IMAGES=app o
# IMAGES="postgres app" (el job `security`, que necesita las dos para Trivy).
#
# BUILDX_CACHE=gha activa `docker buildx build --cache-from/--cache-to
# type=gha`, que solo funciona con `crazy-max/ghaction-github-runtime` ya
# ejecutado en el job (exporta ACTIONS_RUNTIME_TOKEN/ACTIONS_CACHE_URL; ver
# ci.yml, BLOQUEANTE 2 de la auditoria: sin ese paso, `docker buildx build
# --cache-to type=gha` desde un `run:` suelto aborta con "failed to configure
# gha cache exporter"). Vacio por defecto: el job `unit` y backup-drill.yml
# construyen una imagen cada uno, una vez por ejecucion, y levantar un builder
# buildx ahi cuesta mas de lo que ahorra.
IMAGES        ?= postgres
BUILDX_CACHE  ?=
# APK_INDEX_STAMP invalida una vez por semana (semana ISO) la capa de paquetes
# de las tres imagenes. Sin el, la cache de Actions reutilizaba la capa del
# `apk add` de forma indefinida y `trivy image` acababa marcando CVE con el
# parche ya publicado en Alpine (libexpat 2.8.3-r0 → 2.8.4-r0, septiembre de
# 2026). Explicado en infra/docker/php/Dockerfile. Para forzar un refresco
# fuera de ciclo: `make build-ci-images APK_INDEX_STAMP=$$(date -u +%s)`.
APK_INDEX_STAMP ?= $(shell date -u +%G-W%V)

# La version que viaja DENTRO de la imagen de la aplicacion, y que publica
# `GET /api/v1/health` y la pantalla de diagnostico del quiosco (doc 02 §10.5).
#
# Sale del fichero VERSION, que es la fuente de verdad versionada y la que se
# etiqueta al publicar. Sin esto, el Dockerfile cae a su respaldo
# `ARG APP_VERSION=0.0.0-dev` y la instalacion informa una version FALSA: pasó
# en la tercera ejecucion de la etapa ⑧, donde /health decia `0.0.0-dev` en una
# instalacion de la 2.0.0. En una entrega real eso rompe justo lo que ese campo
# existe para sostener —correlacionar un incidente con la version que lo
# produjo— y de paso la matriz de versiones soportadas (§11.6.5).
#
# SE PASA TAMBIEN A LAS IMAGENES :ci de `unit` y `security`, no solo a la etapa
# ⑧, y es deliberado: `build-ci-images` es la UNICA orden de construccion del
# repositorio y no puede haber dos comportamientos segun quien la llame. Ademas
# es mas veraz —una imagen de prueba que dice su version real vale mas que una
# que miente— y el coste en cache es una capa trivial, porque el ARG vive al
# final de la etapa `prod`.
#
# Solo la consume la imagen de PHP, asi que solo a ella se le pasa: dárselo
# tambien a postgres y a nginx haria que BuildKit avisara de un build-arg sin
# usar en cada construccion, y un aviso que siempre sale es un aviso que nadie
# lee.
APP_VERSION ?= $(shell cat VERSION 2>/dev/null || echo 0.0.0-dev)

# ---------------------------------------------------------------------------
# Puerta de RELEASE: una imagen de entrega no puede salir sin clave publica
# ---------------------------------------------------------------------------
#
# POR QUE EXISTE. `config/license.php` trae la clave publica del fabricante
# VACIA de serie, y eso es correcto en desarrollo: significa "esta compilacion no
# puede verificar ninguna clave", el producto arranca, se ficha y `license:show`
# lo dice con esas palabras (ADR-018, ADR-019). Lo que NO puede ocurrir es que
# esa compilacion se entregue a un cliente: le entregariamos un producto que
# rechaza su licencia recien pagada con el motivo `no_public_key`, y el sintoma
# -"mi licencia no se activa"- apunta al sitio equivocado.
#
# El fabricante genera el par UNA VEZ con
# `php tools/license-issuer/generate-keypair.php` y pega la publica en el valor
# por defecto de `env('LICENSE_PUBLIC_KEY', '')` de `backend/config/license.php`.
#
# ESTA COMPROBACION ES DEL EMPAQUETADO, NO DE LA CI. La CI construye imagenes de
# PRUEBA (`build-ci-images`), que deben poder llevarla vacia: la suite genera su
# propio par en cada ejecucion y jamas usa uno fijo del repositorio (§7.7, RS-08).
# Por eso es un objetivo aparte, y la tarea 5.4 lo invocara desde el paso que
# construye la imagen de entrega.
release-gate: ## Falla si la imagen de entrega saldria sin clave publica del fabricante
	@if grep -qE "env\('LICENSE_PUBLIC_KEY', '[0-9a-fA-F]{64}'\)" backend/config/license.php; then \
	  echo "[make] Clave publica del fabricante presente. Puerta de release en verde."; \
	else \
	  echo "[make] La clave publica del fabricante NO esta puesta en backend/config/license.php."; \
	  echo "[make] Una imagen de entrega construida asi RECHAZA la licencia del cliente con"; \
	  echo "[make] motivo no_public_key, y el sintoma apunta al sitio equivocado."; \
	  echo "[make]"; \
	  echo "[make] Genera el par UNA VEZ:  php tools/license-issuer/generate-keypair.php"; \
	  echo "[make] y pega la PUBLICA (64 caracteres hexadecimales) como valor por defecto de"; \
	  echo "[make] env('LICENSE_PUBLIC_KEY', '') en backend/config/license.php."; \
	  echo "[make] La PRIVADA va al gestor de secretos del fabricante, nunca al repositorio."; \
	  exit 1; \
	fi

build-ci-images: ## Construye kronoqr/{postgres,app,nginx}:ci (IMAGES=postgres|app|nginx|"postgres app nginx", BUILDX_CACHE=gha)
	@for imagen in $(IMAGES); do \
	  case "$$imagen" in \
	    postgres) dockerfile=infra/docker/postgres/Dockerfile; tag=kronoqr/postgres:ci; target=; scope=postgres-ci; propios= ;; \
	    app) dockerfile=infra/docker/php/Dockerfile; tag=kronoqr/app:ci; target="--target prod"; scope=app-ci; propios="--build-arg APP_VERSION=$(APP_VERSION)" ;; \
	    nginx) dockerfile=infra/docker/nginx/Dockerfile; tag=kronoqr/nginx:ci; target=; scope=nginx-ci; propios= ;; \
	    *) echo "[make] IMAGES desconocido: '$$imagen' (valores validos: postgres, app, nginx)"; exit 1 ;; \
	  esac; \
	  if [ "$(BUILDX_CACHE)" = "gha" ]; then \
	    echo "[make] docker buildx build --cache type=gha -f $$dockerfile -t $$tag . (APK_INDEX_STAMP=$(APK_INDEX_STAMP))"; \
	    docker buildx build --load \
	      --cache-from "type=gha,scope=$$scope" --cache-to "type=gha,mode=max,scope=$$scope" \
	      --build-arg APK_INDEX_STAMP="$(APK_INDEX_STAMP)" $$propios \
	      -f "$$dockerfile" $$target -t "$$tag" . || exit $$?; \
	  else \
	    echo "[make] docker build -f $$dockerfile -t $$tag . (APK_INDEX_STAMP=$(APK_INDEX_STAMP))"; \
	    docker build --build-arg APK_INDEX_STAMP="$(APK_INDEX_STAMP)" $$propios \
	      -f "$$dockerfile" $$target -t "$$tag" . || exit $$?; \
	  fi; \
	done

# Trivy sobre el arbol de fuentes: dependencias de composer.lock y
# package-lock.json, misconfig de los Dockerfiles y secretos residuales (el
# barrido completo del HISTORICO lo hace gitleaks, no este objetivo).
#
# --skip-dirs evita que Trivy entre en arboles que no aportan nada al analisis
# y que, en un bind mount de Windows, multiplican el tiempo de recorrido (mismo
# motivo que la nota de RUN_APP mas arriba). Aun con --skip-dirs, medido en ese
# mismo bind mount: el recorrido del resto del arbol agota el --timeout de 5 m
# por defecto de Trivy (FATAL "context deadline exceeded" a mitad de analisis,
# no un hallazgo). --timeout 10m da margen de sobra en un disco Linux nativo
# —donde la CI corre en segundos— y en el bind mount lento a la vez.
#
# Estado verificado (2026-08-29): 0 hallazgos HIGH/CRITICAL, y BLOQUEANTE en
# la CI. El unico que hubo, DS-0002 ("Image user should not be 'root'") en
# infra/docker/postgres/Dockerfile, se corrigio en la imagen -- `USER postgres`
# y sin `gosu` -- en vez de excepcionarlo en infra/docker/.trivyignore.yaml.
#
# TRIVY_EXIT_CODE=1 por defecto: Trivy devuelve 1 con hallazgos. Con 0
# devuelve 0 tenga o no hallazgos, y es lo que usa un paso en MODO INFORME
# para distinguir «la herramienta no pudo terminar» de «encontro algo»
# (IMPORTANTE 5 de la auditoria: con `continue-on-error: true` un Trivy que no
# arrancaba tambien quedaba en verde). Hoy ningun paso de la CI lo necesita.
# TRIVY_FORMAT=json es lo que usa la CI para contar hallazgos por severidad con
# `jq` sin volver a escanear.
TRIVY_EXIT_CODE ?= 1
TRIVY_FORMAT    ?= table

trivy-fs: ## Trivy sobre el repositorio: dependencias, Dockerfiles y secretos (bloqueante, ver docs/runbooks/triaje-hallazgos-seguridad.md)
	$(TRIVY) fs --scanners vuln,misconfig,secret --severity HIGH,CRITICAL --ignore-unfixed \
	  --timeout 10m --exit-code $(TRIVY_EXIT_CODE) --format $(TRIVY_FORMAT) \
	  --skip-dirs backend/vendor,node_modules,dist,storage,.git \
	  .
ifeq ($(TRIVY_EXIT_CODE),1)
	@echo "[make] Trivy fs: 0 hallazgos HIGH/CRITICAL."
endif

# Trivy sobre las imagenes YA CONSTRUIDAS. No las construye este objetivo:
# hazlo antes con `make build-ci-images` (en la CI lo hace el propio job, con
# o sin cache segun corresponda).
#
# Estado verificado (2026-08-29): 0 hallazgos HIGH/CRITICAL en las dos, y
# BLOQUEANTE en la CI. kronoqr/postgres:ci llego a tener 21 HIGH, los 21 en
# usr/local/bin/gosu (stdlib del Go con el que la imagen oficial compila
# `gosu`); ese binario ya no esta en la imagen porque arranca como `postgres`
# y no tiene privilegios que bajar (infra/docker/postgres/Dockerfile). Si al
# subir el digest de la base aparece algo nuevo, el camino es el runbook, no
# volver a poner este objetivo en informe.
trivy-image: ## Trivy sobre las imagenes ya construidas kronoqr/postgres:ci y kronoqr/app:ci (bloqueante, ver docs/runbooks/triaje-hallazgos-seguridad.md)
	@docker image inspect kronoqr/postgres:ci >/dev/null 2>&1 || { \
	  echo "[make] Falta la imagen kronoqr/postgres:ci. Construyela con: make build-ci-images"; \
	  exit 1; \
	}
	@docker image inspect kronoqr/app:ci >/dev/null 2>&1 || { \
	  echo "[make] Falta la imagen kronoqr/app:ci. Construyela con: make build-ci-images IMAGES=\"postgres app\""; \
	  exit 1; \
	}
	$(TRIVY_IMAGE_CMD) image --severity HIGH,CRITICAL --ignore-unfixed --exit-code $(TRIVY_EXIT_CODE) --format $(TRIVY_FORMAT) \
	  --ignorefile infra/docker/.trivyignore.yaml kronoqr/postgres:ci
	$(TRIVY_IMAGE_CMD) image --severity HIGH,CRITICAL --ignore-unfixed --exit-code $(TRIVY_EXIT_CODE) --format $(TRIVY_FORMAT) kronoqr/app:ci
ifeq ($(TRIVY_EXIT_CODE),1)
	@echo "[make] Trivy image: 0 hallazgos HIGH/CRITICAL en las dos imagenes."
endif

# gitleaks sobre el HISTORICO COMPLETO, no solo el arbol de trabajo: un secreto
# que alguien borro en el commit siguiente sigue legible con `git log -p`.
#
# Estado verificado en este repositorio (75 commits): 0 hallazgos, con la
# allowlist minima y justificada de .gitleaks.toml (17 falsos positivos
# analizados uno a uno: UUID e identificadores de prueba en fixtures, hashes de
# ejemplo en docs/api/openapi.yaml y una variable vacia de .env.example mal
# delimitada por el propio escaneo). Al pasar limpio HOY, este objetivo es
# BLOQUEANTE desde el primer dia (doc 02 §9.2): no lleva `continue-on-error` en
# la CI.
secrets-scan: ## gitleaks sobre el historico completo (umbral: 0 hallazgos, bloqueante)
	$(GITLEAKS) git --config .gitleaks.toml --exit-code 1 -v .
	@echo "[make] gitleaks: 0 hallazgos en el historico."

# SBOM CycloneDX del arbol de fuentes (composer.lock y package-lock.json): un
# inventario de que trae cada version publicada, independiente de cualquier
# vulnerabilidad conocida HOY -- eso es lo que distingue un SBOM de un informe
# de Trivy. No se versiona (esta en .gitignore): es un artefacto que se genera
# y se sube a cada ejecucion de la CI, igual que la matriz de trazabilidad no
# se versiona su version de CI.
#
# SIN `--scanners vuln` a proposito (IMPORTANTE 7 de la auditoria): un SBOM es
# un inventario, no un analisis de vulnerabilidades, y no necesita la base de
# datos de CVE para enumerar paquetes. Verificado con este mismo Trivy: sin
# `--scanners`, `--format cyclonedx` avisa "disables security scanning.
# Specify --scanners vuln explicitly if you want to include vulnerabilities in
# the cyclonedx report" y termina sin descargar la base de datos -- justo lo
# que hace falta aqui, y bastante mas rapido.
#
# Lee VERSION para el nombre del fichero, la misma fuente de verdad que usa
# infra/docker/php/Dockerfile para APP_VERSION.
sbom: ## SBOM CycloneDX en sbom/kronoqr-<version>.cdx.json
	@mkdir -p sbom
	$(TRIVY) fs --format cyclonedx --timeout 10m \
	  --skip-dirs backend/vendor,node_modules,dist,storage,.git \
	  --output "sbom/kronoqr-$$(cat VERSION).cdx.json" \
	  .
	@echo "[make] SBOM escrito en sbom/kronoqr-$$(cat VERSION).cdx.json"

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

# Cobertura y mutacion se activan SOLAS en cuanto exista el primer modelo de
# dominio, en el modulo que sea.
#
# La primera version miraba solo Attendance, porque es el modulo que escribe la
# tarea 1.1. Pero si el primer modelo aterrizara en Compliance —o en cualquier
# otro—, las dos puertas habrian seguido apagadas sin decir nada, y el dominio
# habria crecido sin umbral de cobertura ni MSI hasta que alguien lo notara a
# ojo. La condicion tiene que ser la que de verdad importa: «¿hay ya dominio que
# medir?», no «¿hay dominio en el modulo que yo esperaba?».
DOMAIN_MODELS := $(wildcard backend/app/Modules/*/Domain/Model/*.php)

# Las capas de dominio que existen hoy, en la forma que espera Pest: rutas
# relativas a backend/ y separadas por comas.
#
# Se derivan del arbol y no se escriben a mano por lo mismo que DOMAIN_MODELS:
# un modulo nuevo con dominio tiene que entrar solo en la mutacion. Una lista
# escrita a mano se queda corta el dia que alguien añade Compliance/Domain, y el
# MSI seguiria en verde midiendo la mitad.
comma := ,
empty :=
space := $(empty) $(empty)
DOMAIN_PATHS := $(patsubst backend/%,%,$(wildcard backend/app/Modules/*/Domain))
MUTATE_PATHS := $(subst $(space),$(comma),$(strip $(DOMAIN_PATHS)))

# Los DOS umbrales del §9.2 (RNF-M-01), y hacen falta los dos.
#
# `--min` de Pest es uno solo y GLOBAL. Con solo el 75 puesto, el 90 % del
# dominio lo podia pagar cualquier otra parte del arbol: la puerta figuraba en
# la CI sin comprobar lo que dice comprobar. Por eso la misma ejecucion escribe
# el informe Clover y una segunda pasada acotada a Modules/*/Domain le aplica su
# propio minimo (tools/coverage-gate.php). No se ejecuta la suite dos veces.
#
# El patron es Modules/*/Domain y no un modulo concreto, por lo mismo que
# DOMAIN_MODELS: el umbral tiene que seguir al dominio, este donde este.
DOMAIN_COVERAGE_MIN  := 90
GLOBAL_COVERAGE_MIN  := 75
CLOVER               := storage/framework/cache/clover.xml

coverage: ## Cobertura: dominio >= 90 por ciento, global >= 75 (doc 02 seccion 9.2)
ifeq ($(DOMAIN_MODELS),)
	@echo "[make] El dominio llega en la tarea 1.1: todavia no hay cobertura exigible."
	@echo "[make] Para ver la cobertura actual sin umbral: make coverage-now"
else
	$(RUN_APP_XDEBUG) $(PEST) --coverage --min=$(GLOBAL_COVERAGE_MIN) --coverage-clover=$(CLOVER)
	$(RUN_APP) php tools/coverage-gate.php $(CLOVER) $(DOMAIN_COVERAGE_MIN) 'app/Modules/*/Domain/*'
endif

coverage-now: ## Cobertura actual sin umbral, util antes de que exista el dominio
	$(RUN_APP_XDEBUG) $(PEST) --coverage

mutate: ## Mutacion sobre el dominio, MSI mayor o igual a 80 por ciento
ifeq ($(DOMAIN_MODELS),)
	$(call notice,Mutacion NO ejecutada: Modules/*/Domain no existe todavia.)
	@echo "[make] La mutacion se ejecuta sobre Modules/*/Domain, que se escribe en la"
	@echo "[make] tarea 1.1. Sin dominio no hay mutantes: el umbral MSI >= 80 por ciento"
	@echo "[make] (doc 02 seccion 9.2, RQ-10) empieza a exigirse en la tarea 1.2."
	@echo "[make] Este paso se activa solo en cuanto exista el primer modelo de dominio."
else
# --path acotado a Modules/*/Domain, y no la raiz de app/, porque el umbral del
# §9.2 es "MSI >= 80 % sobre Modules/*/Domain". Sin acotar, el MSI mezclaria el
# dominio con los comandos del repositorio y con la infraestructura, y bastaria
# con que las otras partes compensaran para dar el umbral por bueno.
#
# --testsuite=Unit porque los mutantes del dominio los mata la suite Unit y solo
# ella: incluir Feature y Contract multiplicaria por N el tiempo de cada mutante
# sin cambiar el veredicto de ninguno.
#
# --no-cache NO es una precaucion de mas: el cache de resultados de Pest se
# indexa por la RUTA del fichero mutado (Pest\Mutate\Support\ResultCache::key),
# no por el contenido de las pruebas. Un mutante que sobrevivio antes de que
# existieran sus pruebas se queda marcado como «untested» para siempre, y el MSI
# EMPEORA al escribir la prueba que lo mata.
#
# PHP_INI_SCAN_DIR aditivo (el «:» inicial conserva el directorio por defecto)
# apaga OPcache solo para esta orden. El motivo, medido, esta escrito en
# backend/tools/mutation/zzz-no-opcache.ini: con OPcache encendido el mutante se
# compila una vez y luego se sirve el original, asi que el MSI mide el contenido
# de una cache y no la calidad de las pruebas.
#
# --except: las tres mutaciones de concatenacion solo reordenan o parten el
# TEXTO de un mensaje de excepcion. Matarlas exigiria afirmar el mensaje literal
# en cada prueba, que acopla la suite a una prosa que no es contrato de nada
# —CLAUDE.md regla 21 gobierna QUE lleva ese mensaje, no como se redacta— y que
# cambiaria con cada retoque de estilo. El §9.3 justifica el MSI con «un > en
# lugar de un >= produce minutos incorrectos en la nomina de alguien»: eso es lo
# que este umbral tiene que vigilar. Los mutadores de comparacion, aritmetica,
# condicional y retorno siguen TODOS activos.
	$(RUN_APP_XDEBUG) sh -c 'PHP_INI_SCAN_DIR=":$$(pwd)/tools/mutation" $(PEST) --mutate --path=$(MUTATE_PATHS) --testsuite=Unit --covered-only --no-cache --except=StringConcatRemoveLeft,StringConcatRemoveRight,StringConcatSwitchSides --min=80'
endif

e2e: ## Playwright: quiosco con camara simulada y panel de gestion
ifeq ($(wildcard frontend-kiosk/package.json),)
	@echo [make] Los frontends llegan en la tarea 0.5: todavia no hay E2E que ejecutar.
else
	npm --prefix frontend-kiosk run test:e2e
	npm --prefix frontend-admin run test:e2e
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
	@echo TRIVY_VERSION=$(TRIVY_VERSION)
	@echo GITLEAKS_VERSION=$(GITLEAKS_VERSION)
	@echo SEMGREP_VERSION=$(SEMGREP_VERSION)

# Copias de seguridad (tarea 1.18). Los tres objetivos ejecutan EXACTAMENTE lo
# mismo que el servidor de un cliente: los scripts de infra/scripts, sin una
# ruta alternativa "para desarrollo". Un procedimiento de recuperacion que solo
# se ensaya en produccion no se ensaya.
backup: ## Copia cifrada y verificada del entorno de desarrollo (RF-PR-04)
	$(COMPOSE_DEV) exec -T app php artisan backup:run

backup-verify: ## Verifica la ultima copia: huella, descifrado y lectura del indice
	$(COMPOSE_DEV) exec -T app php artisan backup:verify

# El simulacro se lanza DESDE EL ANFITRION y no dentro del contenedor: levanta
# un contenedor de PostgreSQL limpio, y para eso necesita hablar con Docker.
# Necesita ver el destino de copias, que en desarrollo es un volumen: se copia
# a un directorio temporal, se ensaya alli y no se toca nada del entorno.
restore-drill: ## Simulacro de restauracion en contenedor limpio (RNF-D-05, RQ-09)
	@destino=$${TMPDIR:-/tmp}/kronoqr-drill; \
	rm -rf "$$destino"; mkdir -p "$$destino"; \
	destino_docker="$$(cygpath -w "$$destino" 2>/dev/null || echo "$$destino")"; \
	MSYS_NO_PATHCONV=1 docker run --rm -v kronoqr_backup-data:/from -v "$$destino_docker:/to" alpine \
	  sh -c 'cp -r /from/. /to/' >/dev/null; \
	clave="$$($(COMPOSE_DEV) exec -T app printenv BACKUP_ENCRYPTION_KEY | tr -d '\r')"; \
	BACKUP_PATH="$$destino" BACKUP_ENCRYPTION_KEY="$$clave" bash infra/scripts/restore-drill.sh; \
	resultado=$$?; \
	MSYS_NO_PATHCONV=1 docker run --rm -v kronoqr_backup-data:/to -v "$$destino_docker:/from:ro" alpine \
	  sh -c 'cp -f /from/metrics/kronoqr_backup_drill.prom /to/metrics/ 2>/dev/null || true' >/dev/null; \
	exit $$resultado
	@echo [make] Simulacro terminado. El informe esta en el directorio temporal que indica la ultima linea.

clean: ## Para el entorno y BORRA los volumenes de datos
	$(COMPOSE_DEV) down -v --remove-orphans
