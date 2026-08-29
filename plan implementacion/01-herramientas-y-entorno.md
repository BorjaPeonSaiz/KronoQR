# 01 · Herramientas y entorno de trabajo

| Campo | Valor |
|---|---|
| **Fase** | Previo a la Fase 0. **Ampliación**, no forma parte del plan del doc 02 §11 |
| **Horas** | ⚠️ No cubierto por los documentos — decidir |
| **Orden de ejecución** | Antes de la tarea 0.1. Nada de la Fase 0 arranca sin el Bloque B cerrado |
| **Documento origen** | Bloque A: ninguno (inventario de máquina). Bloque B: derivado de [`../docs/02-stack-tecnologico-y-plan-implementacion.md`](../docs/02-stack-tecnologico-y-plan-implementacion.md) §3.4, §3.5 y §9.2. Bloque C: doc 02 §3.1, §3.3, §9.2 y Anexo B |

> **Aviso sobre el alcance de este fichero.** Es el único de esta carpeta cuyo contenido **no está en los documentos del proyecto**.
>
> El doc 02 §3.4 describe la infraestructura de **contenedores** (desarrollo y producción) y el §11.6.2 describe el **servidor del cliente**. Ninguno de los dos describe **la estación de trabajo del desarrollador**: qué debe estar instalado en el Windows desde el que se ejecuta `make up`.
>
> Este apartado cubre ese hueco porque el usuario lo pidió expresamente, y se marca como **ampliación**: los Bloques A y B son criterio operativo, no requisito del producto. El Bloque C sí es literal de los documentos y solo se reordena para hacerlo accionable.

---

## Índice

- [Bloque A — Inventario real de la máquina](#bloque-a--inventario-real-de-la-máquina)
- [Bloque B — Instalación de lo que falta, en orden de dependencia](#bloque-b--instalación-de-lo-que-falta-en-orden-de-dependencia)
  - [B.0 Notas previas: la carpeta con espacio y los finales de línea](#b0-notas-previas-la-carpeta-con-espacio-y-los-finales-de-línea)
  - [B.1 Arrancar el daemon de Docker](#b1-arrancar-el-daemon-de-docker--bloqueante)
  - [B.2 GNU Make](#b2-gnu-make--desbloquea-los-comandos-de-claudemd)
  - [B.3 Composer](#b3-composer--solo-para-el-tooling-del-editor)
  - [B.4 ShellCheck y shfmt](#b4-shellcheck-y-shfmt--puertas-bloqueantes-de-la-ci)
  - [B.5 PHP 8.4 en el host — decisión pendiente](#b5-php-84-en-el-host--decisión-del-usuario)
- [Bloque C — Dependencias del proyecto](#bloque-c--dependencias-del-proyecto)
  - [C.1 Composer (doc 02 §3.1)](#c1-composer--doc-02-31)
  - [C.2 npm por aplicación (doc 02 §3.3)](#c2-npm-por-aplicación--doc-02-33)
  - [C.3 Cadena de calidad y pruebas (doc 02 §9.2)](#c3-cadena-de-calidad-y-pruebas--doc-02-92)
- [Cierre — Variables de entorno a rellenar (doc 02, Anexo B)](#cierre--variables-de-entorno-a-rellenar-doc-02-anexo-b)

---

## Bloque A — Inventario real de la máquina

Verificado ejecutando comandos en la máquina, no supuesto.

| Herramienta | Estado | Detalle |
|---|---|---|
| Windows 11 Pro 10.0.26200 | — | Shell: PowerShell 5.1 y Git Bash |
| Git 2.54.0.windows.1 | ✅ instalado | — |
| Docker 29.5.3 + Docker Compose v5.1.4 | ⚠️ instalado, daemon PARADO | `docker info` falla: `npipe:////./pipe/dockerDesktopLinuxEngine` no responde |
| WSL 2 | ⚠️ presente | Única distro: `docker-desktop` (Stopped). Sin distro Linux de usuario |
| Node 24.17.0 + npm 11.13.0 | ✅ instalado | Cubre Vite 8, Vitest 4, Playwright; `vue-i18n` 11 exige Node ≥ 22 |
| PHP 8.3.31 CLI (ZTS, VC++ 2019 x64, WinGet) | ⚠️ versión inferior | El doc 02 §3.1 pide 8.4 (mínimo 8.3). Extensiones presentes: bcmath, curl, gd, intl, mbstring, openssl, zip. **Faltan: pdo_pgsql, pgsql, redis, sodium** |
| Composer | ❌ no instalado | — |
| GNU Make | ❌ no instalado | **Bloquea los comandos `make up`, `make test`, `make quality`, `make mutate`, `make e2e` de `CLAUDE.md`** |
| ShellCheck | ❌ no instalado | Exigido por doc 02 §3.5 y §9.2, umbral bloqueante 0 hallazgos |
| shfmt | ❌ no instalado | Íd., formato `shfmt -i 2` |
| gh, psql, redis-cli, pnpm, python | ❌ no instalados | Opcionales: psql y redis-cli viven en los contenedores |

**Lectura del inventario.** Lo único que impide arrancar hoy son cuatro cosas: el daemon de Docker parado, la ausencia de Make, la ausencia de Composer y la ausencia de ShellCheck/shfmt. Todo lo demás —incluida la versión de PHP y las extensiones que faltan— vive dentro de los contenedores del §3.4 y no condiciona el arranque.

---

## Bloque B — Instalación de lo que falta, en orden de dependencia

El orden no es arbitrario: cada punto depende del anterior.

> Los identificadores de paquete de `winget` que aparecen abajo se comprueban con `winget search <nombre>` antes de ejecutar. Si un identificador ha cambiado de nombre en el repositorio de WinGet, se usa la vía oficial indicada como alternativa.

### B.0 Notas previas: la carpeta con espacio y los finales de línea

**La carpeta `plan implementacion` tiene un espacio en el nombre.** Toda ruta que la mencione va entre comillas, en PowerShell y en Bash:

```powershell
# PowerShell
Get-ChildItem "D:\Trabajo\Trabajos\KronoQR\plan implementacion"
```

```bash
# Git Bash
ls "/d/Trabajo/Trabajos/KronoQR/plan implementacion"
```

**Finales de línea: riesgo real de trabajar en Windows.** El doc 02 §3.5 establece que `install.sh`, `update.sh`, `backup.sh`, `restore.sh` y `doctor.sh` **son entregables del producto** y se ejecutan en el servidor Linux del cliente. Un script `.sh` con finales de línea CRLF **no arranca en Linux**: el intérprete lee `#!/usr/bin/env bash\r` y falla con un error que no dice nada útil. Es exactamente el modo de fallo que el §3.5 quiere evitar cuando exige que los mensajes de error digan qué hacer.

Medidas, en la tarea 0.1 y antes de escribir el primer script:

```bash
# Comprobar la configuración actual
git config --get core.autocrlf

# Recomendado en este repositorio: no convertir en el checkout,
# y dejar que .gitattributes decida por tipo de fichero
git config core.autocrlf input
```

Y un `.gitattributes` en la raíz que fije LF para lo que se ejecuta en Linux (se crea en la tarea 0.1, junto con la estructura del repositorio):

```gitattributes
*.sh    text eol=lf
Makefile text eol=lf
*.yaml  text eol=lf
*.yml   text eol=lf
```

Comprobación posterior, con el fichero ya en el índice:

```bash
git ls-files --eol infra/scripts/
# Se espera: i/lf  w/lf  attr/text eol=lf
```

Si aparece `w/crlf` en un `.sh`, ese script no funcionará en el servidor del cliente.

### B.1 Arrancar el daemon de Docker — bloqueante

**Por qué lo exige el proyecto.** El doc 02 §3.4 monta **todo** el stack sobre Docker Compose: `app`, `nginx`, `postgres`, `redis`, `horizon`, `reverb`, `scheduler`, `node-kiosk`, `node-admin`, `node-portal`, `mailpit`, `prometheus`, `grafana`, `loki`. Sin daemon Linux no hay PostgreSQL 17, ni Redis 7, ni PHP 8.4: no arranca nada de la Fase 0.

Docker Desktop y Compose **ya están instalados** (Bloque A). Lo que falta es arrancar el motor.

```powershell
# Arrancar Docker Desktop
Start-Process "$env:ProgramFiles\Docker\Docker\Docker Desktop.exe"
```

Comprobación posterior:

```powershell
docker info --format '{{.ServerVersion}} {{.OSType}}'
docker run --rm hello-world
```

Resultado esperado: `docker info` devuelve versión de servidor y `linux` como `OSType` (no `windows`: las imágenes del §3.4 son Linux), y `hello-world` termina sin error. Si `docker info` sigue fallando con `npipe:////./pipe/dockerDesktopLinuxEngine`, el motor está en modo contenedores Windows o WSL 2 no ha arrancado; se comprueba con:

```powershell
wsl --list --verbose      # docker-desktop debe pasar a Running
wsl --status
```

### B.2 GNU Make — desbloquea los comandos de `CLAUDE.md`

**Por qué lo exige el proyecto.** `CLAUDE.md` documenta cinco comandos como interfaz oficial del repositorio: `make up`, `make test`, `make test-unit`, `make quality`, `make mutate`, `make e2e`. El doc 02 §3.4 remata: *«Un `make up` debe dejar el entorno completo funcionando con datos de ejemplo»*, y el criterio de terminado de la Fase 0 (doc 03 §6.1) se enuncia con `make quality` y `make test`. Sin Make, esos comandos no existen y cada agente tendría que reinventar la invocación.

```powershell
winget install ezwinports.make
# Alternativa si el identificador no resuelve:
winget search make
# Alternativa oficial: GnuWin32 / MSYS2 (pacman -S make) o Chocolatey (choco install make)
```

Comprobación posterior:

```powershell
make --version
```

Resultado esperado: `GNU Make 4.x`.

**Alternativa si no se quiere instalar Make.** Los objetivos del `Makefile` son envoltorios de `docker compose`. Se pueden ejecutar a mano, con la penalización de que ningún documento los cita y hay que recordarlos:

| Objetivo de `CLAUDE.md` | Equivalente manual |
|---|---|
| `make up` | `docker compose -f infra/compose.dev.yaml up -d` |
| `make test` | `docker compose -f infra/compose.dev.yaml exec app php artisan test` |
| `make test-unit` | `docker compose -f infra/compose.dev.yaml exec app php artisan test --testsuite=Unit` |
| `make quality` | `docker compose … exec app vendor/bin/pint --test` + `vendor/bin/phpstan` + `vendor/bin/deptrac` + `vendor/bin/rector --dry-run` (los cuatro del §9.2) |
| `make mutate` | `docker compose … exec app php artisan test --mutate` (Pest `--mutate` sobre `Modules/*/Domain`, §9.2) |
| `make e2e` | `npx playwright test` con las banderas de cámara simulada del §9.4 |

La correspondencia exacta de cada objetivo se fija en la tarea 0.1; aquí solo se documenta la vía de escape.

### B.3 Composer — solo para el tooling del editor

**Por qué lo exige el proyecto.** Las 11 dependencias PHP del §3.1 (Bloque C.1) se instalan **dentro del contenedor `app`**, que es donde vive PHP 8.4 con sus extensiones. El `composer install` de producción también ocurre en la imagen (doc 02 §2, `infra/docker/php/`). Composer en el host **no es necesario para que el proyecto funcione**.

Se instala por una razón concreta y limitada: que el editor resuelva el autoload PSR-4 y que PHPStan/Pint del editor encuentren `vendor/`. Sin ello, el IDE marca en rojo código correcto y se pierde tiempo en falsos positivos.

```powershell
winget install Composer.Composer
```

Comprobación posterior:

```powershell
composer --version
composer diagnose
```

Resultado esperado: versión 2.x y `composer diagnose` sin errores de red ni de permisos. Advertencias sobre extensiones ausentes del PHP del host son esperables mientras no se resuelva B.5, y no impiden usarlo para autoload.

> **Regla que no se rompe:** la fuente de verdad de las versiones es el `composer.lock` del repositorio, generado y usado dentro del contenedor. Nunca se resuelven dependencias con el PHP del host: produciría un `lock` resuelto contra 8.3 y sin `pdo_pgsql`, `redis` ni `sodium`.

### B.4 ShellCheck y shfmt — puertas bloqueantes de la CI

**Por qué lo exige el proyecto.** El doc 02 §9.2 los pone en la tabla de umbrales con **0 hallazgos** como umbral bloqueante, aplicado a `infra/scripts/` y a los scripts entregados al cliente. El §3.5 los designa verificadores de cuatro convenciones: `set -euo pipefail` e `IFS=$'\n\t'`, guía de estilo de Shell de Google, formato `shfmt -i 2`, y estilo general. Y el §10.1 los coloca en la **etapa ①** del pipeline, que corre en cada *push*.

Tenerlos en local no es comodidad: es la diferencia entre descubrir el fallo en dos segundos y descubrirlo en la CI cuatro minutos después, con el contexto ya perdido.

```powershell
winget install koalaman.shellcheck
winget install mvdan.shfmt
```

Comprobación posterior:

```powershell
shellcheck --version
shfmt --version

# Sobre un script real, una vez exista infra/scripts/
shellcheck infra/scripts/backup.sh
shfmt -i 2 -d infra/scripts/backup.sh
```

Resultado esperado: `shellcheck` sin salida (0 hallazgos) y `shfmt -i 2 -d` sin diferencias. Cualquier salida es un fallo bloqueante según el §9.2.

### B.5 PHP 8.4 en el host — decisión del usuario

**El hecho.** El doc 02 §3.1 fija PHP **8.4** (mínimo 8.3) y justifica el 8.4 por *property hooks*, `#[\Override]` y rendimiento. El §3.5 exige a Rector aplicar los sets de **PHP 8.4**. El host tiene 8.3.31 y le faltan `pdo_pgsql`, `pgsql`, `redis` y `sodium` — las cuatro necesarias para PostgreSQL 17 (§3.2), Redis 7 (§3.1), y la firma de licencia ed25519 con `sodium` (§3.1, ADR-018).

**La disyuntiva, planteada sin resolverla:**

| Opción | Qué implica | A favor | En contra |
|---|---|---|---|
| **A. No tocar el host** | El PHP 8.3 del host queda solo para invocaciones triviales. Todo —`artisan`, Pest, PHPStan, Pint, Deptrac, Rector— se ejecuta dentro del contenedor `app` | Una sola versión de PHP en el proyecto, la de la imagen. Cero deriva entre local y CI. Cero trabajo | El análisis del editor (PHPStan, Intelephense) corre contra 8.3 sin extensiones: falsos positivos en sintaxis 8.4 y en funciones de `sodium`, `pdo_pgsql` y `redis` |
| **B. Instalar PHP 8.4 en el host** | `winget install PHP.PHP.8.4` y habilitar `pdo_pgsql`, `pgsql`, `redis` y `sodium` en el `php.ini` del host | El editor analiza con la misma versión que la imagen | Dos instalaciones de PHP que hay que mantener sincronizadas con la imagen. `redis` en Windows exige un `.dll` compatible con la build ZTS/VC++, que es la parte que suele fallar |

**Esto no lo decido yo.** Ningún documento del proyecto se pronuncia sobre la estación de trabajo, así que la elección es del usuario. Lo único que los documentos sí imponen y que ninguna de las dos opciones puede romper:

- **La imagen del contenedor lleva PHP 8.4 con todas las extensiones** (§3.1, §3.2). Eso es no negociable y es lo que la CI ejecuta.
- **Lo que decide si algo pasa o no es el contenedor**, nunca el host. Si `make quality` pasa dentro y falla en el editor, el problema es el editor.

Si se elige B, la comprobación posterior es:

```powershell
php --version                      # se espera 8.4.x
php -m | Select-String "pdo_pgsql|pgsql|redis|sodium"
```

Resultado esperado: las cuatro extensiones listadas. Si `redis` no carga, se deja fuera: no es necesaria para el análisis estático.

### B.6 El bind mount de Windows y por qué `vendor/` no viaja por él

**Resuelto el 14 de agosto de 2026 al ejecutar la tarea 0.3.** Es la restricción de entorno con más impacto de todo este fichero, y no estaba prevista.

**El hecho, medido en esta máquina.** El código vive en `D:\Trabajo\Trabajos\KronoQR` (NTFS) y Docker Desktop lo expone a los contenedores Linux por bind mount. Cada lectura cruza esa frontera:

| Operación | Bind mount (NTFS) | Disco del contenedor |
|---|---|---|
| Leer 2.000 ficheros de `vendor/` | **15.294 ms** | **30 ms** |

Son **500 veces más lento**. Y `vendor/` son **16.919 de los 17.779 ficheros** del backend.

**Consecuencia sobre el presupuesto de `CLAUDE.md`.** La suite unitaria debe terminar por debajo de 2 s (§9.1). Con `vendor/` en el mount tardaba **3,84 s con solo tres pruebas triviales**: el presupuesto estaba agotado antes de que existiera el dominio, y las tareas 1.1 y 1.2 son las más iterativas del proyecto.

**Qué se descartó antes de dar con la causa**, porque la lista ahorra el trabajo a quien lo revise:

| Sospecha | Medición | Veredicto |
|---|---|---|
| Coste del `docker exec` | 0,59 s | No era |
| Xdebug cargado | 4,08 s con la extensión descargada del todo | No era |
| OPcache apagado en CLI | Activado con `file_cache`; sin cambio apreciable | No era |
| **Latencia del bind mount** | 3,84 s desde el mount · **0,10 s desde disco local** | **Era esto** |

**La solución.** `vendor/` se monta como **volumen nombrado** (`backend-vendor` en `infra/compose.dev.yaml`), no por el bind mount. El código fuente sigue en bind mount, que es lo que hace que editar un fichero se vea al instante; lo que sale del mount es lo que nadie edita a mano y que `composer.lock` gobierna.

| Medida | Antes | Después |
|---|---|---|
| `make test-unit` | 3,84 s | **0,18 s** |
| `make test` (suite completa) | 12,9 s | 4,5 s |

**Dos detalles que cuestan una tarde si no se saben:**

- **El volumen nace propiedad de `root`** si el directorio no existe en la imagen. El proceso corre como `app` (uid 1000) y `composer install` falla sin decir por qué. Por eso el `Dockerfile` crea `/var/www/html/vendor` con propietario `app`: Docker copia la propiedad del directorio de la imagen al inicializar un volumen vacío.
- **Un volumen nuevo está vacío**, así que las dependencias se instalan dentro. Lo hace el entrypoint de forma idempotente cuando falta `vendor/autoload.php`; los roles `horizon`, `reverb` y `scheduler` esperan a que `fpm` termine, para que cuatro contenedores no instalen a la vez sobre el mismo volumen.

**El `backend/vendor` del host se conserva** y ya no lo usa ningún contenedor: queda solo para que el IDE resuelva el autoload, que es exactamente para lo que el §B.3 puso Composer en el host. Las dos copias las gobierna el mismo `composer.lock`.

> **Si se repone el entorno desde cero**, `make up` basta: el entrypoint puebla el volumen. Si `composer install` falla, el volumen quedó a medias y se borra con `docker volume rm kronoqr_backend-vendor` antes de repetir.

---

## Bloque C — Dependencias del proyecto

**Nada de esto se instala en el host.** Las dependencias PHP viven en la imagen `infra/docker/php/` y en el `composer.json` del `backend/`; las de JavaScript, en el `package.json` de cada uno de los tres frontends. Este bloque es el inventario de lo que hay que declarar, con la restricción de versión literal del documento origen.

### C.1 Composer — doc 02 §3.1

La tabla del §3.1 tiene 13 filas. **Once son paquetes de Composer**; PHP es el lenguaje, Laravel Scheduler forma parte del framework y `sodium` es una extensión nativa de PHP.

| Componente | Elección | Versión (literal del §3.1) | Para qué sirve aquí |
|---|---|---|---|
| Lenguaje | PHP | **8.4** (mínimo 8.3) | *Property hooks*, `#[\Override]` y mejor rendimiento. **No es un paquete** |
| Framework | Laravel | **12.x** | Base del monolito modular. Verificar la versión mayor vigente al arrancar y actualizar el ADR si procede |
| Autenticación | Laravel Sanctum + `pragmarx/google2fa` | ^4.0 / ^8.0 | Tokens con ámbitos (§7.3) y 2FA obligatorio para roles con acceso global (RS-06). El 2FA es de la tarea 2.1 |
| Colas | Redis + **Laravel Horizon** | ^5.0 | Visibilidad de trabajos. Redis se necesita igualmente para caché y rate limiting |
| Tiempo real | **Laravel Reverb** | ^1.0 | Presencia en vivo del panel, autoalojado. *Fallback* a sondeo cada 15 s si el WebSocket cae |
| Programación | Laravel Scheduler | — | Consolidaciones, incidencias, retención, copias. **Parte del framework** |
| Autorización | Policies + `spatie/laravel-permission` | ^6.0 | RBAC con ámbito por departamento (RF-ID-02, RF-ID-03) |
| Generación QR | `endroid/qr-code` | ^5.0 | Control sobre el nivel de corrección de errores, que aquí importa (RF-QR-05, nivel Q) |
| PDF | `spatie/laravel-pdf` (Browsershot) | ^2.12 | **Tarjetas de credencial** e informes sellados (RF-QR-04, RF-IN-04) |
| Exportaciones | `spatie/simple-excel` | ^3.0 | Streaming: no carga en memoria un mes de 500 empleados |
| Contrato API | `spectator` en pruebas + OpenAPI 3.1 | — | Contrato como fuente de verdad (ADR-013, RQ-06) |
| Firma de licencia | `sodium` de PHP (ed25519) | nativo | Verificación local sin dependencias externas (ADR-018). **Extensión, no paquete** |
| Trazas | OpenTelemetry PHP | ^1.0 | Instrumentación extremo a extremo (§8.1) |

> Los identificadores exactos `vendor/paquete` de `spectator` y de OpenTelemetry PHP no aparecen en los documentos: el §3.1 solo da el nombre del producto y la restricción de versión. Se resuelven al declarar el `composer.json` en la tarea 0.2; **la restricción de versión de la tabla es la que manda**.

### C.2 npm por aplicación — doc 02 §3.3

**Común a los tres frontends** (`frontend-kiosk/`, `frontend-admin/`, `frontend-portal/`):

| Componente | Elección (literal del §3.3) | Nota |
|---|---|---|
| Framework | Vue 3.5+ (Composition API, `<script setup>`) | Curva suave, buen rendimiento en hardware de tablet modesto |
| Lenguaje | **TypeScript 5.6+ en modo estricto** | En clientes que manipulan horas, colas offline e idempotencia, el tipado no es opcional |
| Build | Vite 8 | — |
| CSS | Tailwind CSS 4 | — |
| Estado | Pinia 4 | — |
| Rutas | Vue Router 5 | — |
| HTTP | Cliente generado desde OpenAPI | Sin desviaciones entre backend y frontends. `npm run api:generate` (skill `endpoint-api`, paso 7) |
| i18n | `vue-i18n` 11 | Español e inglés de serie, extensible (RF-KI-05, §6.6) |

**Exclusivo del quiosco** (`frontend-kiosk/`):

| Componente | Elección | Nota |
|---|---|---|
| **Escaneo QR** | **`@zxing/browser` + `@zxing/library`** | Decodifica más rápido y da control sobre `MediaStream` (enfoque, torch, resolución) |
| **PWA** | `vite-plugin-pwa` + Workbox | **Solo el quiosco** necesita instalación y service worker |
| **Cola offline** | **Dexie 4 (IndexedDB)** | Transaccional. `localStorage` es síncrono, con 5 MB y sin transacciones: inadecuado para una cola con garantías |
| Wake lock | Screen Wake Lock API con *fallback* | Evita que la tablet se suspenda (RF-KI-01) |

**Exclusivo del panel** (`frontend-admin/`):

| Componente | Elección | Nota |
|---|---|---|
| Tablas y datos | TanStack Table + TanStack Query | Virtualización para 500 empleados y caché de consultas |
| Gráficos | ECharts | Informes y tendencias, con tabla de datos alternativa |

**El portal NO es PWA.** El §3.3 lo dice sin ambigüedad: *«El portal del empleado es una web sencilla, no una PWA. No hay credencial que mostrar sin conexión, así que no necesita service worker, caché cifrada ni instalación»*. Concuerda con ADR-015. Su `package.json` lleva **solo** la lista común: nada de `vite-plugin-pwa`, nada de Dexie, nada de `@zxing/*`.

### C.3 Cadena de calidad y pruebas — doc 02 §9.2

Umbral bloqueante literal del §9.2. Todo esto lo configura la tarea 0.7 y vive en el pipeline del §10.1.

| Nivel | Herramienta | Umbral bloqueante |
|---|---|---|
| Estilo | Laravel Pint, ESLint + Prettier | Sin desviaciones |
| **Scripts de shell** | ShellCheck + `shfmt -i 2 -d` | 0 hallazgos. Se aplica a `infra/scripts/` y a los scripts entregados al cliente |
| Tipos backend | PHPStan/Larastan **nivel 9** | 0 errores; cada `@phpstan-ignore` requiere justificación en el propio comentario |
| Tipos frontend | `vue-tsc` en modo estricto | 0 errores |
| Modernización | Rector (dry-run en CI) | Informativo |
| **Arquitectura** | Pest Arch + **Deptrac** | 0 violaciones de frontera |
| Unitarias | Pest | Cobertura de dominio ≥ 90 % |
| **Mutación** | Pest `--mutate` (o Infection) sobre `Modules/*/Domain` | **MSI ≥ 80 %** |
| Propiedades | Generación dirigida | Duraciones, DST, medianoche |
| Integración | Pest + PostgreSQL real en contenedor | — |
| Contrato | Spectator contra `openapi.yaml` | Toda respuesta valida el esquema |
| Frontend unit | Vitest + Vue Test Utils | ≥ 70 % |
| E2E | Playwright | Todos los escenarios críticos en verde |
| Accesibilidad | `@axe-core/playwright` | 0 violaciones críticas o graves |
| Carga | k6 | RNF-P-06: 50 fichajes/s con p95 < 150 ms |
| Dependencias | `composer audit`, `npm audit`, Dependabot | 0 vulnerabilidades críticas o altas |
| SAST | Semgrep con reglas PHP/Laravel | 0 hallazgos de severidad alta |
| Contenedores | Trivy | 0 CVE críticos en la imagen final |
| **Trazabilidad** | `qa:traceability --check` (§9.6) | 0 requisitos implementados sin prueba que los referencie (RQ-13) |
| **Instalación** | Script en CI: instalación limpia + actualización desde versión anterior | Verde antes de publicar (RQ-11) |

**Cobertura, del §9.2 y de RNF-M-01:** dominio ≥ 90 %, global backend ≥ 75 %, frontend ≥ 70 %. Suite unitaria completa por debajo de 2 s (`CLAUDE.md`).

---

## Cierre — Variables de entorno a rellenar (doc 02, Anexo B)

La regla que gobierna esta lista es el §7.7: **nada de secretos en el repositorio.** En desarrollo, `.env` local a partir de `.env.example`. En producción, **el instalador genera los secretos en el servidor del cliente y nunca los transmite**. La rotación de `APP_KEY`, claves HMAC de QR, credenciales de base de datos, tokens de dispositivo y claves de copia se documenta en `docs/runbooks/rotacion-secretos.md`.

### Se generan en el servidor del cliente — nunca en el repositorio (§7.7)

| Variable | Qué es |
|---|---|
| `APP_KEY` | Clave de aplicación. En la lista de rotación del §7.7 |
| `DB_USERNAME` / contraseña de `fichaje_app` | Usuario **sin DDL y sin `UPDATE`/`DELETE` sobre `audit_log`** (Anexo B, regla dura 6). En la lista de rotación |
| `QR_SIGNING_KEY_CURRENT` | 32 bytes, base64. Clave HMAC activa. En la lista de rotación |
| `QR_SIGNING_KEY_PREVIOUS` | Solape durante la rotación (§5.3, RF-QR-07) |
| `REVERB_APP_ID` / `REVERB_APP_KEY` / `REVERB_APP_SECRET` | Credenciales del WebSocket. ⚠️ No cubierto por los documentos — decidir: no figuran en la lista de rotación del §7.7 |
| `BACKUP_ENCRYPTION_KEY` | Clave de cifrado de copias, **separada** de la de base de datos (§7.1, capa Datos). En la lista de rotación |
| Tokens de dispositivo de quiosco | No son variable de entorno: se emiten por emparejamiento (RF-PD-06, tarea 5.6). En la lista de rotación |

### Configuración de desarrollo y de instalación — sin secretos

| Variable | Valor literal del Anexo B | Comentario del documento |
|---|---|---|
| `APP_ENV` | `production` | En desarrollo, `local` |
| `APP_DEBUG` | `false` | — |
| `APP_TIMEZONE` | `UTC` | **Siempre UTC.** La zona de presentación va por centro (regla dura 3, RN-04) |
| `APP_URL` | `https://fichaje.hotel.example` | — |
| `DB_CONNECTION` | `pgsql` | — |
| `DB_HOST` | `postgres` | Nombre del servicio de Compose (§3.4) |
| `DB_DATABASE` | `fichaje` | Identificador técnico interno, se mantiene (nota de nomenclatura del doc 02) |
| `REDIS_HOST` | `redis` | — |
| `QUEUE_CONNECTION` | `redis` | *Fallback* a driver de base de datos si Redis cae (RNF-D-03) |
| `CACHE_STORE` | `redis` | — |
| `BROADCAST_CONNECTION` | `reverb` | — |
| `QR_SIGNING_KEY_CURRENT_ID` | `a3` | Clave activa. 2 caracteres (§5.1) |
| `QR_SIGNING_KEY_PREVIOUS_ID` | `a2` | Solape durante la rotación |
| `QR_ERROR_CORRECTION` | `Q` | Tolerancia al desgaste de la tarjeta (RF-QR-05) |
| `ATTENDANCE_DEBOUNCE_SECONDS` | `60` | RF-AT-06 |
| `ATTENDANCE_MAX_SHIFT_HOURS` | `12` | RN-08 |
| `ATTENDANCE_MAX_CLOCK_SKEW_MINUTES` | `15` | RF-AT-10 · **genera incidencia, nunca rechaza el fichaje** |
| `ATTENDANCE_PATTERN_WINDOW_SECONDS` | `10` | RF-PR-06 · fichajes consecutivos en el mismo quiosco |
| `ATTENDANCE_PATTERN_MIN_REPEATS` | `3` | RF-PR-06 · coincidencias antes de generar incidencia |
| `PIN_MAX_ATTEMPTS` | `3` | RS-12 |
| `PIN_LOCKOUT_SECONDS` | `300` | — |
| `PORTAL_INTERNAL_CIDR` | `172.28.0.0/16` (dev) | RF-ID-08 · lo aplica Nginx (`geo`+403), no la aplicación |
| `COMPLIANCE_PROFILE` | `ES-hosteleria` | RF-PD-07 |
| `LICENSE_KEY` | — | Clave firmada, verificación local (ADR-018). La emite el fabricante |
| `TELEMETRY_ENABLED` | `false` | Desactivada por defecto (RF-PD-12) |
| `ERROR_HISTORY_RETENTION_DAYS` | `90` | RF-PD-15 · igual que el log técnico (RL-11) |
| `BRANDING_APP_NAME` | — | RF-PD-08 |
| `BRANDING_LOGO_PATH` | — | RF-PD-08 |
| `MAIL_MAILER` | `smtp` | **Lo configura el cliente.** En desarrollo, Mailpit (§3.4) |
| `OTEL_EXPORTER_OTLP_ENDPOINT` | — | §8.1 |
| `BACKUP_PATH` | `/var/backups/fichaje` | Identificador técnico interno, se mantiene |

**Lo que hay que producir en la Fase 0 a partir de esta lista:** un `.env.example` **comentado, con los valores que el cliente debe rellenar** (§11.6.1), y ni un secreto real dentro. La tarea 0.4 debe además garantizar que ningún secreto aparece en los logs del pipeline, y Semgrep (§3.5, fila «Secretos») es quien lo verifica.

---

Siguiente: **[Fase 0 — Cimientos](02-fase-0-cimientos.md)** · [Índice](README.md)
