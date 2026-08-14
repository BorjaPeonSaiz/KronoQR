# 02 · Fase 0 — Cimientos

| Campo | Valor |
|---|---|
| **Fase** | 0 — Cimientos |
| **Horas** | **31–42 h** (doc 02 §11, tabla de la Fase 0, con la tarea 0.7 ampliada a 7–10 h por `docs/requisitos.yaml` y `docs:consistency`) |
| **Orden de ejecución** | **Primera.** El orden global de fases es **0 → 1 → 2 → 5 → 3 → 4** (doc 02 §11, doc 01 Anexo A) |
| **Documento origen** | [`../docs/02-stack-tecnologico-y-plan-implementacion.md`](../docs/02-stack-tecnologico-y-plan-implementacion.md) §11 (plan), §1.6 (fronteras), §2 (árbol), §3 (stack), §9 (pruebas), §10 (CI/CD) · [`../docs/01-especificaciones-proyecto.md`](../docs/01-especificaciones-proyecto.md) Anexo A · [`../docs/03-agentes-y-skills-ia.md`](../docs/03-agentes-y-skills-ia.md) §2.2, §6.1, §6.6 |

**Entregable (literal del doc 02 §11).**

> **Entregable:** `make up` levanta el entorno completo; la CI está en verde; las fronteras arquitectónicas se verifican solas. **Verificación:** añadir a propósito un `use Illuminate\...` dentro de `Domain/` debe hacer fallar la CI.

**Requisitos que cubre la fase** (doc 01, Anexo A — Trazabilidad requisito → fase):

`RNF-M-01..06`, `RQ-01`, `RQ-06`, `RQ-13..14`, `RS-08`, `RS-09`, `RS-10`

> **Nota sobre la columna «Requisitos» de las tareas de esta fase.** La tabla de la Fase 0 del doc 02 §11 **no tiene columna de requisitos**, salvo en la tarea 0.7 (`RNF-M-06, RQ-13..14`). Para las demás, la atribución por tarea no es literal: se indica el requisito de la fase y la sección del documento que establece el vínculo. Donde ni siquiera hay vínculo documental, se marca.

**Agentes protagonistas** (doc 03 §2.2):

| Agente | Ámbito en esta fase |
|---|---|
| `devops-observabilidad` | Entorno y CI (tareas 0.1, 0.3, 0.4, 0.7) |
| `arquitecto-dominio` | Estructura de módulos y ADRs (tareas 0.2, 0.6) |
| `qa-testing` | Cadena de pruebas y trazabilidad (tareas 0.3, 0.7) |
| `frontend-quiosco` | Esqueleto de los tres frontends (tarea 0.5) |
| `revisor-codigo` + `seguridad-cumplimiento` | Cierre de fase (doc 03 §2.2 y §6.6) |

**Prompt de arranque de la fase** (doc 03 §6.1, literal):

```
Arranca el proyecto siguiendo la Fase 0 del plan (docs/02, §11).

Usa el agente indicado en la columna "Agente / Skill" de cada tarea.

Entregable esperado:
- `make up` levanta el entorno completo: PHP 8.4, Laravel 12, PostgreSQL 17,
  Redis, Horizon, Reverb, Nginx, los tres frontends con Vite, Mailpit
  y el stack de observabilidad
- Los 8 módulos creados con su estructura hexagonal y sus service providers
- Cadena de calidad: Pint, PHPStan nivel 9, Deptrac con las reglas de
  dependencia del documento 02 §1.6 y las tres aristas de ADR-025, Pest, Rector
- Pipeline de CI con las etapas 1 a 3 en verde y por debajo de 4 minutos
- Los tres frontends con TypeScript estricto, Tailwind 4 y Vitest
- ADR-001 a ADR-020 escritos en docs/adr/ a partir de la tabla del documento
  02 §4; ADR-021 a ADR-028 ya existen y solo se revisan. Al terminar,
  docs/adr/ tiene 28 ficheros
- openapi.yaml inicial con /health y /scan
- docs/requisitos.yaml y los comandos qa:traceability y docs:consistency

Criterio de terminado: `make quality` y `make test` en verde con un módulo
de ejemplo, y Deptrac fallando si añado a propósito un import de Illuminate
dentro de Domain/. Verifícalo.
```

---

## Índice de tareas

| # | Tarea | h | Agente / Skill |
|---|---|---|---|
| [0.1](#tarea-01--repositorio-docker-compose-completo-make-de-arranque) | Repositorio, Docker Compose completo, `make` de arranque | 6–8 | `devops-observabilidad` |
| [0.2](#tarea-02--esqueleto-laravel-12-con-los-8-módulos-y-sus-service-providers) | Esqueleto Laravel 12 con los 8 módulos y sus service providers | 4–5 | `arquitecto-dominio` |
| [0.3](#tarea-03--cadena-de-calidad-pint-phpstan-9-deptrac-pest-rector) | Cadena de calidad: Pint, PHPStan 9, Deptrac, Pest, Rector | 4–5 | `devops-observabilidad` + `qa-testing` |
| [0.4](#tarea-04--pipeline-de-ci-con-las-etapas-13) | Pipeline de CI con las etapas 1–3 | 3–4 | `devops-observabilidad` |
| [0.5](#tarea-05--esqueleto-de-los-tres-frontends-con-ts-estricto-tailwind-y-vitest) | Esqueleto de los tres frontends con TS estricto, Tailwind y Vitest | 4–6 | `frontend-quiosco` |
| [0.6](#tarea-06--adr-001-a-adr-028-escritos-y-openapiyaml-inicial) | ADR-001 a ADR-028 escritos y `openapi.yaml` inicial | 3–4 | `arquitecto-dominio` |
| [0.7](#tarea-07--convenciones-del-35-configuradas-y-comandos-qatraceability-y-docsconsistency-con-su-etapa-de-ci) | Convenciones del §3.5 configuradas y comandos `qa:traceability` y `docs:consistency` con su etapa de CI | 7–10 | `devops-observabilidad` + `qa-testing` |
| [—](#cierre-de-fase-doc-03-66) | Cierre de fase | — | `revisor-codigo` + `seguridad-cumplimiento` + `qa-testing` + `devops-observabilidad` |

---

## Las tareas, desarrolladas

### Tarea 0.1 — Repositorio, Docker Compose completo, `make` de arranque

| | |
|---|---|
| **Horas** | 6–8 |
| **Agente / Skill** | `devops-observabilidad` |
| **Requisitos** | La tabla de Fase 0 del doc 02 §11 no asigna requisitos a esta tarea. De la fase (doc 01 Anexo A) le corresponden por vínculo documental: **RS-08** (gestión de secretos fuera del repositorio, doc 02 §7.7) y **RS-09** (cabeceras de seguridad completas, doc 02 §7.2, servidas por Nginx) |
| **Precondiciones** | Ninguna. Es el primer nodo del camino crítico del §11.3: `0.1→0.2→0.3` |
| **Bloquea a** | **0.2** (§11.3). En la práctica, toda la fase: sin entorno no se ejecuta nada |

**Objetivo.** El repositorio existe con el árbol del §2, y un solo `make up` deja funcionando los 14 servicios del §3.4 con datos de ejemplo que incluyen casos límite.

**Reglas duras aplicables.**

- **3** (`APP_TIMEZONE=UTC` siempre): el `.env.example` y el contenedor `postgres` se configuran en UTC desde el primer arranque. Si el entorno nace en `Europe/Madrid`, todo lo que venga después arrastra el error.
- **13** (nada específico de un cliente en el código): `compose.prod.yaml` es el mismo para todos los clientes; lo que cambia va en `.env`.
- **21** (nunca nombres de empleados en logs técnicos): la configuración de Monolog en JSON del §8.1 se fija aquí, con `trace_id`, `scan_id`, `device_id` y `employee_uuid` como campos de contexto.

**Pasos.** Sin skill asignada. Orden derivado del ámbito de trabajo del agente `devops-observabilidad` y del árbol del §2.

1. Crear el árbol del repositorio del §2: `docs/` (con `adr/`, `api/`, `cliente/`, `runbooks/`), `.claude/` (ya existe), `.github/workflows/`, `backend/`, `frontend-kiosk/`, `frontend-admin/`, `frontend-portal/`, `infra/`, `load-tests/k6/`.
2. Fijar los finales de línea antes del primer script: `.gitattributes` con `*.sh text eol=lf` y `core.autocrlf input`. Los scripts de `infra/scripts/` son entregables del producto (§3.5) y se ejecutan en Linux.
3. Construir las imágenes de `infra/docker/`: multi-etapa, **sin root**, mínimas. `php/` con PHP 8.4-FPM y las extensiones que exige el stack (`pdo_pgsql`, `pgsql`, `redis`, `sodium`, §3.1 y §3.2); `nginx/`; `postgres/` con `btree_gist` y `pgcrypto` disponibles (§3.2).
4. Escribir `infra/compose.dev.yaml` con los **14 servicios** del §3.4:

   | # | Servicio | Papel (§3.4, §1.4) |
   |---|---|---|
   | 1 | `app` | API Laravel 12 sobre PHP 8.4-FPM |
   | 2 | `nginx` | TLS, assets estáticos, rate limiting de borde |
   | 3 | `postgres` | PostgreSQL 17 · registro legal e invariantes declarativas |
   | 4 | `redis` | Redis 7 · colas, caché, rate limiting, sesiones |
   | 5 | `horizon` | Worker de colas |
   | 6 | `reverb` | WebSocket de presencia en vivo |
   | 7 | `scheduler` | Reconciliación, incidencias, retención, copias |
   | 8 | `node-kiosk` | Vite del quiosco |
   | 9 | `node-admin` | Vite del panel |
   | 10 | `node-portal` | Vite del portal |
   | 11 | `mailpit` | Correo de desarrollo |
   | 12 | `prometheus` | Métricas |
   | 13 | `grafana` | Cuadros de mando |
   | 14 | `loki` | Logs estructurados |

5. Configurar Nginx con las cabeceras completas del §7.2 —incluido `Permissions-Policy: camera=(self)`, **sin el cual la PWA del quiosco no puede acceder al dispositivo de vídeo**—, las zonas de rate limiting del §7.1, límite de tamaño de cuerpo, y `/metrics` restringido a red interna. **La zona de fichaje son dos, no una:**

   | Zona | Límite | Origen |
   |---|---|---|
   | Fichaje **desde `KIOSK_VLAN_CIDR`** | **600 r/m con ráfaga de 50** | §7.1 |
   | Fichaje **desde cualquier otro origen** | **30 r/m con ráfaga de 10** | §7.1 |
   | Autenticación | 5 r/m | §7.1 |
   | Portal del empleado | 10 r/m | §7.1 |
   | Resto de la API | 120 r/m | §7.1 |

   > **Por qué se distingue el origen, y por qué no basta con 30 r/m.** *«Los 30 r/m por IP son un control pensado para internet, y todos los quioscos de un hotel salen por la misma IP»* (§7.1). Aplicado sin distinción, el propio Nginx frenaría el fichaje **tres órdenes de magnitud por debajo de RNF-P-06** —50 fichajes por segundo—, y el síntoma en producción sería «el quiosco va lento a las 06:00», que es el pico que el producto existe para absorber. **El límite interno no se elimina, se eleva:** RS-02 exige limitar por IP también, y 600 r/m con ráfaga de 50 sostienen RNF-P-06 con margen sin dejar la VLAN sin techo.

6. Escribir `infra/compose.prod.yaml` (el que se entrega al cliente, §2) y `.env.example` **comentado, con los valores que el cliente debe rellenar** (§11.6.1) y sin un solo secreto real (§7.7). Incluye **`KIOSK_VLAN_CIDR`**, que es un **parámetro de instalación y no una constante** (§7.1): si el cliente coloca los quioscos fuera de ese rango caen bajo los 30 r/m, y **el fallo es silencioso** —se manifiesta como lentitud, no como error—, así que va comentado en el fichero y explicado en la documentación de instalación (tarea 5.11).

   ```dotenv
   KIOSK_VLAN_CIDR=10.0.20.0/24   # §7.1 · zona de fichaje elevada para este rango.
                                  # Fuera de él, los quioscos caen al límite de 30 r/m
   ```

7. Escribir el `Makefile` con los objetivos que `CLAUDE.md` documenta: `up`, `test`, `test-unit`, `quality`, `mutate`, `e2e`.
8. Escribir el **esqueleto** de la semilla de desarrollo del §10.2: el objetivo `make seed`, el `DatabaseSeeder` con su orden de ejecución, y **solo los datos que no dependen del esquema de fichaje** — los 3 centros con su zona horaria y los departamentos base. Cada centro nace ya con la zona horaria explícita, porque es de lo que depende RN-05.

   > **Por qué la semilla se parte, y quién completa cada trozo.** El §10.2 pide *«3 centros, 60 empleados, 90 días de fichajes con casos límite —turnos nocturnos, DST, olvidos, correcciones—»* y lo justifica: *«un dataset de datos "bonitos" oculta exactamente los errores que este dominio produce»*. **Pero nada de eso es construible aquí:** no hay esquema hasta la tarea 1.3, ni dominio hasta la 1.1, ni tabla `shift_corrections` hasta la 2.3. Escribirlo en la Fase 0 sería una dependencia rota hacia dos fases posteriores en la primera tarea del proyecto. El reparto:
   >
   > | Trozo de la semilla | Tarea que lo escribe | Por qué ahí |
   > |---|---|---|
   > | Esqueleto, `make seed`, centros con zona horaria, departamentos | **0.1** (esta) | No dependen del esquema de fichaje |
   > | Empleados, credenciales, dispositivos y **90 días de tramos** | **1.3** | Es la tarea que crea `employees`, `credentials` y `shift_entries`, y necesita volumen realista para su propia verificación (`VolumeSeeder`) |
   > | **Casos límite**: turnos nocturnos, los dos cambios de hora, olvido de salida | **1.4** | Es la tarea que implementa el fichaje y la que sabe qué caso límite produce cada error |
   > | **Correcciones** y tramos `superseded` | **2.3** | Es la tarea que crea `shift_corrections` |
   >
   > La exigencia del §10.2 no se relaja: se cumple al cerrar la Fase 2, y cada trozo lo escribe quien tiene el esquema delante.
9. Añadir `infra/observability/` con la configuración base de Prometheus, Grafana, Loki y Alertmanager. **Los cuadros de mando y el catálogo de alertas son de la tarea 3.2**, no de aquí.
10. Escribir los runbooks que este cambio hace necesarios. El §12 lista 20; aquí solo aplican los que describen un modo de fallo ya existente. ⚠️ No cubierto por los documentos — decidir: cuáles del §12 se escriben en la Fase 0 y cuáles esperan a la fase que introduce su alerta.

**Artefactos.** Rutas del árbol del §2:

- `infra/docker/php/`, `infra/docker/nginx/`, `infra/docker/postgres/`
- `infra/compose.dev.yaml`, `infra/compose.prod.yaml`
- `infra/observability/`
- `infra/scripts/` — se crea la carpeta; `install.sh`, `update.sh`, `backup.sh`, `restore.sh` y `doctor.sh` son de las tareas 5.4, 5.7 y 2.11
- `.env.example`
- `Makefile` — ⚠️ No cubierto por los documentos: el árbol del §2 no incluye el `Makefile`, aunque `CLAUDE.md` exige sus comandos. Ubicación en la raíz del repositorio: decidir. Incluye el objetivo `seed`, que `CLAUDE.md` tampoco lista y que la semilla del §10.2 necesita
- `backend/database/seeders/DatabaseSeeder.php` — el esqueleto y los datos independientes del esquema de fichaje. Las tareas 1.3, 1.4 y 2.3 lo amplían
- `.gitattributes` — ⚠️ No cubierto por los documentos. Se propone en el fichero [`01-herramientas-y-entorno.md`](01-herramientas-y-entorno.md) §B.0 como ampliación

**Pruebas exigidas.** La tabla del §9.5 clasifica funcionalidad (regla de negocio, esquema, endpoint, recorrido de usuario, escritura de quiosco, informe, configuración con efecto en horas). **Esta tarea no encaja en ninguna de esas naturalezas**, así que el §9.5 no le asigna niveles. Lo que sí exigen los documentos:

- §9.2, fila «Scripts de shell»: ShellCheck + `shfmt -i 2 -d`, **0 hallazgos**, sobre todo lo que haya en `infra/scripts/`.
- §9.2, fila «Contenedores»: Trivy, **0 CVE críticos en la imagen final**. Se ejecuta en la etapa ⑤ (tarea 0.4 solo cubre 1–3), pero la imagen debe construirse ya sin CVE críticos.
- La verificación de fase del §11: el `use Illuminate\...` en `Domain/` debe romper la CI (se materializa en 0.3 y 0.4).

**Verificación.**

```bash
make up
docker compose -f infra/compose.dev.yaml ps          # los 14 servicios en estado healthy/running
curl -sk https://localhost/api/v1/health             # sonda de salud (Anexo B doc 01)
curl -sI https://localhost/ | grep -i permissions-policy
#   se espera: Permissions-Policy: camera=(self), microphone=(), geolocation=(), payment=()
curl -s http://localhost/metrics                     # debe fallar desde fuera de la red interna
shellcheck infra/scripts/*.sh                        # sin salida
shfmt -i 2 -d infra/scripts/                         # sin diferencias
git ls-files --eol infra/scripts/                    # i/lf  w/lf en todos los .sh
```

Resultado esperado: los 14 servicios arriba, `/api/v1/health` respondiendo, las cabeceras del §7.2 presentes, `/metrics` inaccesible desde fuera, y `make seed` cargando sin error los 3 centros con su zona horaria y los departamentos base. **Los turnos nocturnos y los días de cambio de hora no son consultables todavía**: llegan con las tareas 1.3 y 1.4, que es donde existe la tabla `shift_entries`.

**Terminado cuando** (subconjunto aplicable de la Definición de Terminado, §10.3):

- [ ] Convenciones del §3.5 respetadas en los scripts: `set -euo pipefail`, `IFS=$'\n\t'`, `shfmt -i 2`, idempotencia, mensajes de error que dicen qué hacer.
- [ ] Nada específico de un cliente ha entrado en el código: `compose.prod.yaml` es idéntico para cualquier cliente.
- [ ] Runbook o documentación de cliente actualizada si añade un modo de fallo o un parámetro.
- [ ] Ningún secreto en el repositorio ni en la imagen (§7.7).

---

### Tarea 0.2 — Esqueleto Laravel 12 con los 8 módulos y sus service providers

| | |
|---|---|
| **Horas** | 4–5 |
| **Agente / Skill** | `arquitecto-dominio` |
| **Requisitos** | Sin asignación literal en el doc 02 §11. De la fase: **RNF-M-03** (las dependencias entre módulos se verifican automáticamente; el dominio no importa nada del framework) es el requisito que esta tarea hace posible |
| **Precondiciones** | **0.1** (§11.3) |
| **Bloquea a** | **0.3** (§11.3) y, por tanto, `1.1` |

**Objetivo.** Existen los 8 módulos con las cuatro capas de cada uno, sus service providers registrados, y una frontera física que Deptrac podrá verificar en 0.3.

**Reglas duras aplicables.**

- **1** (`Domain/` es puro): la estructura de carpetas es lo que hace la regla verificable. Sin `Domain/`, `Application/`, `Infrastructure/`, `Http/` separados, no hay frontera que comprobar.
- **2** (nunca `now()` en el dominio): el puerto `Clock` se declara en **`Shared/Application/Port/Clock.php`**, y su adaptador en `Shared/Infrastructure/Adapter/SystemClock.php` ([ADR-021](../docs/adr/ADR-021-clock-en-shared.md)). `Compliance`, `Kiosk`, `Reporting` y el scheduler lo necesitan igual que `Attendance`, y el §1.6 admite `Shared` como dependencia de los ocho módulos, así que Deptrac queda en verde sin excepciones. **En `Application`, no en `Domain`**: el ADR rechaza `Domain/Port/` explícitamente, porque el dominio recibe instantes en lugar de pedirlos, y porque la regla de Deptrac que prohíbe a `*/Domain` depender del `Domain` de otro módulo habría tumbado el reloj el primer día de la Fase 1. El diagrama del §1.5 lo sitúa en `Attendance` y en ese detalle está desactualizado.
- **13** (nada específico de un cliente): el módulo `Product` existe precisamente para que la diferencia entre clientes sea dato.

**Pasos.** Sin skill asignada. Orden derivado del método del agente `arquitecto-dominio` (doc 03 §4.3): módulo → capa → invariantes → objetos de valor → puertos.

1. Instalar Laravel 12 en `backend/` (§3.1). Verificar la versión mayor vigente al arrancar y **actualizar el ADR si procede** (§3.1, nota literal).
2. Crear los 8 módulos en `backend/app/Modules/` con las fronteras del §1.6:

   | Módulo | Responsabilidad | Puede depender de |
   |---|---|---|
   | `Attendance` | **Núcleo.** Fichajes, tramos, jornadas, correcciones | `Shared` |
   | `Compliance` | Auditoría, incidencias, retención, exportación legal | `Shared`, eventos de `Attendance` |
   | `Workforce` | Empleados, departamentos, centros, contratos, ausencias | `Shared` |
   | `Identity` | Usuarios, roles, permisos, credenciales QR, tokens de dispositivo | `Shared` |
   | `Reporting` | Proyecciones y consultas de lectura, exportaciones | `Shared`, eventos de otros módulos |
   | `Kiosk` | Dispositivos, emparejamiento, sincronización de lotes, telemetría | `Shared`, `Attendance` (vía caso de uso) |
   | `Product` | Configuración de instalación, perfiles de cumplimiento, marca, licencia, diagnóstico, soporte | `Shared` |
   | `Shared` | Objetos de valor comunes, tipos base, contratos de eventos | — |

3. Crear en cada módulo las cuatro capas con la estructura interna del §2: `Domain/{Model,ValueObject,Event,Policy,Exception}/`, `Application/{UseCase,Port,Command,Query}/`, `Infrastructure/{Persistence,Adapter,Projection}/`, `Http/`.
4. Escribir un `{Módulo}ServiceProvider.php` por módulo y registrarlos en `backend/app/Providers/`. Es donde se enlazan puertos con adaptadores (skill `crear-caso-de-uso`, paso 4).
5. Configurar el autoload PSR-4 del `composer.json` para `App\Modules\*` (§3.5, fila «Autoload y estructura»).
6. Declarar el `composer.json` con las 11 dependencias del §3.1 y sus restricciones de versión (ver [`01-herramientas-y-entorno.md`](01-herramientas-y-entorno.md) §C.1).
7. Crear la estructura de pruebas del §2: `backend/tests/{Unit,Integration,Feature,Contract,Architecture}/`.
8. Crear `routes/api_v1.php` (§2) con la versión en la ruta (`/api/v1`, ADR-012) y vacío de endpoints: los primeros llegan en 0.6 y 1.7.
9. Fijar `APP_TIMEZONE=UTC` en la configuración de Laravel (regla dura 3, Anexo B) y comprobar que no queda ninguna zona local heredada.
10. Añadir **un módulo de ejemplo mínimo** que permita cerrar el criterio del prompt §6.1: *«`make quality` y `make test` en verde con un módulo de ejemplo»*.

**Artefactos.**

- `backend/app/Modules/{Attendance,Compliance,Workforce,Identity,Reporting,Kiosk,Product,Shared}/` con sus cuatro capas
- `backend/app/Modules/*/{Módulo}ServiceProvider.php`
- `backend/app/Providers/`
- `backend/composer.json`
- `backend/routes/api_v1.php`
- `backend/tests/{Unit,Integration,Feature,Contract,Architecture}/`

**Pruebas exigidas.** El §9.5 no cubre esta naturaleza de cambio (andamiaje estructural). Lo que sí exige el §9.2:

- Nivel **Arquitectura**: Pest Arch + Deptrac, **0 violaciones de frontera**. Las pruebas de arquitectura viven en `backend/tests/Architecture/` (§2) y se escriben materialmente en 0.3.

**Verificación.**

```bash
docker compose -f infra/compose.dev.yaml exec app composer dump-autoload
docker compose -f infra/compose.dev.yaml exec app php artisan about   # Laravel 12, timezone UTC
make test                                                             # verde con el módulo de ejemplo
```

Resultado esperado: `php artisan about` muestra Laravel 12 y zona horaria UTC; los 8 service providers cargados; `make test` en verde.

**Terminado cuando** (subconjunto de §10.3):

- [ ] Código conforme a la arquitectura (Deptrac en verde — se cierra al terminar 0.3).
- [ ] Convenciones del §3.5 respetadas: `declare(strict_types=1)` en todo fichero, PSR-4, nombres en inglés según el glosario del doc 01 §13.
- [ ] PHPStan nivel 9 sin errores nuevos (se cierra al terminar 0.3).
- [ ] ADR escrito si la decisión es estructural — la ubicación del puerto `Clock` lo es.

---

### Tarea 0.3 — Cadena de calidad: Pint, PHPStan 9, Deptrac, Pest, Rector

| | |
|---|---|
| **Horas** | 4–5 |
| **Agente / Skill** | `devops-observabilidad` + `qa-testing` |
| **Requisitos** | Sin asignación literal en el doc 02 §11. De la fase: **RNF-M-02** (análisis estático en nivel máximo sin errores suprimidos sin justificar), **RNF-M-03** (dependencias verificadas automáticamente), **RQ-01** (toda regla de negocio del §4 tiene prueba unitaria en el dominio, sin BD ni framework — aquí se monta la infraestructura que lo hace posible) |
| **Precondiciones** | **0.2** (§11.3) |
| **Bloquea a** | **1.1** (§11.3: `0.1→0.2→0.3 ──► 1.1→1.2`) |

**Objetivo.** `make quality` ejecuta Pint, PHPStan 9, Deptrac y Rector en dry-run, y falla ante cualquier violación. `make test` y `make test-unit` ejecutan Pest con la separación de suites del §2.

**Reglas duras aplicables.**

- **1** (`Domain/` es puro): *«Deptrac lo verifica y la CI falla»* — es literalmente esta tarea. Sin ella la regla 1 es una sugerencia.
- **2** (nunca `now()`, `time()`, `Carbon::now()` ni `new DateTime()` en el dominio): Deptrac ve *imports*, no llamadas a funciones globales, así que **Deptrac no puede cubrir esta regla**. Se ata a dos herramientas, y ambas son bloqueantes:
  - **Pest Arch** — prohíbe en `Modules/*/Domain` las funciones `now`, `time`, `date`, `mktime`, `microtime` y `strtotime`, y la clase `Carbon\Carbon`. El §9.2 empareja Pest Arch con Deptrac en el nivel Arquitectura, así que la puerta ya existe.
  - **Semgrep** — detecta `new DateTime()` y `new DateTimeImmutable()` **sin argumentos o con `'now'`**, que es la forma que ninguna regla de *imports* ni de nombres de función encuentra: la clase se importa legítimamente para tipar, y lo que delata la infracción es la construcción sin instante. El §9.2 ya exige Semgrep con 0 hallazgos de severidad alta.

**Pasos.** Sin skill asignada. Orden derivado del principio del agente `devops-observabilidad`: *«si alguien propone una convención nueva, o se ata a una herramienta que la comprueba, o no entra en el §3.5»*.

1. **Pint** con preset `laravel` (§3.5). Verifica PSR-12 y PER Coding Style 2.0.
2. **PHPStan/Larastan nivel 9** en `backend/phpstan.neon` (§2, §9.2). Umbral: **0 errores**, y cada `@phpstan-ignore` con su justificación en el propio comentario. Incluir la regla de complejidad ciclomática ≤ 10 por método (§3.5).
3. **Deptrac** en `backend/deptrac.yaml`. Definir capas y reglas a partir de la tabla de fronteras del §1.6 y de las reglas duras 1 y 2:

   | Regla de Deptrac | Origen |
   |---|---|
   | `*/Domain` no puede depender de `Illuminate\*` | Regla dura 1, §1.5 («Regla de oro, verificada por test automático») |
   | `*/Domain` no puede depender de `App\Models\*` ni de Eloquent | Regla dura 1, §1.5 |
   | `*/Domain` no puede depender de `Domain`, `Application`, `Infrastructure` ni `Http` de **otro módulo** | Regla dura 1, §1.6 |
   | `*/Domain` no puede depender de ninguna librería de infraestructura | Regla dura 1 |
   | Facades de Laravel prohibidas en `*/Domain` **y** en `*/Application` | §3.5, fila «Facades» |
   | `Http/` → `Application/` → `Domain/`; `Infrastructure/` implementa puertos de `Application/`; nunca al revés | Agente `backend-laravel`, «Dirección de las dependencias» |
   | `Attendance` → solo `Shared` | §1.6. **El núcleo no depende de ningún satélite**, y esta fila es la que lo garantiza |
   | `Compliance` → `Shared` y eventos de `Attendance` | §1.6 |
   | `Workforce` → `Shared`, y **`Workforce/Infrastructure` → `Attendance/Application/Port`** | §1.6 + [ADR-025](../docs/adr/ADR-025-frontera-de-dependencias-del-nucleo.md) |
   | `Identity` → `Shared`, y **`Identity/Infrastructure` → `Attendance/Application/Port`** | §1.6 + [ADR-025](../docs/adr/ADR-025-frontera-de-dependencias-del-nucleo.md) |
   | `Reporting` → `Shared` y eventos de otros módulos | §1.6 |
   | `Kiosk` → `Shared` y `Attendance` **vía caso de uso** | §1.6 |
   | `Product` → `Shared`, y **`Product/Infrastructure` → `Shared/Application/Port`** | §1.6 + [ADR-025](../docs/adr/ADR-025-frontera-de-dependencias-del-nucleo.md) |
   | `Shared` → nada | §1.6 |
   | Ningún módulo accede a los modelos Eloquent de otro | §1.6: *«La comunicación entre módulos ocurre solo por tres vías: casos de uso públicos con interfaz explícita, eventos de dominio, o implementar un puerto declarado por el módulo consumidor»* (ADR-025) |
   | Ningún módulo lee la configuración de `Product` directamente; recibe valores resueltos o un puerto tipado | §1.6, nota sobre `Product` |

   > **Las tres aristas de ADR-025 son reglas, no excepciones**, y la diferencia importa: *«una excepción es un agujero con nombre; estas son aristas declaradas con su capa de origen y de destino, verificables»*. `Identity/Infrastructure → Attendance/Application/Port` es una regla; `Identity → Attendance` no lo sería. Cada una nombra **la capa de origen y la de destino**, de modo que `Identity/Application` o `Identity/Domain` siguen sin poder tocar `Attendance`, y ninguna de las tres alcanza el `Domain/` del núcleo ni sus casos de uso.
   >
   > **Sin estas tres filas, la Fase 1 falla el primer día**: el `HmacSignatureVerifier` de la tarea 1.5 y el `EloquentEmployeeDirectory` de la 1.6 son exactamente los adaptadores que las recorren. Esta tarea es precondición de la 1.1, así que el error aparecería con el núcleo ya escrito y la salida bajo presión sería leer Eloquent de otro módulo — lo que la regla dura 1 prohíbe y ADR-025 existe para cerrar.

4. **Pest Arch** en `backend/tests/Architecture/`: las fronteras que Deptrac no cubre y las prohibiciones de llamada (`now()`, `time()`, `Carbon::now()`, `new DateTime()` en `Domain/`, regla dura 2). Además, **las cuatro pruebas que ADR-021 y ADR-025 enuncian en su sección «Verificación»**, bloqueantes desde esta tarea aunque los módulos estén vacíos —una prueba de arquitectura sobre un árbol vacío pasa, y empieza a proteger en cuanto haya código—:

   | # | Prueba | Qué protege | Origen |
   |---|---|---|---|
   | a | `Modules/Attendance` **no importa nada** de `Identity`, `Workforce`, `Product` ni `Compliance` | Que el núcleo siga siendo núcleo. Es la que falla primero si alguien invierte una flecha | ADR-025 |
   | b | `Identity/Infrastructure` y `Workforce/Infrastructure` importan de `Attendance\Application\Port` **y de nada más de `Attendance`** | Que la arista concedida no se ensanche hacia el dominio ni hacia los casos de uso del núcleo | ADR-025 |
   | c | Ningún puerto de `Attendance/Application/Port/` tiene en su firma un tipo de `Identity`, `Workforce` ni `Illuminate\*` | La restricción 2 de ADR-025 —los puertos hablan en tipos de `Shared` o escalares—, que es la que se erosiona sin darse cuenta | ADR-025 |
   | d | **Una sola declaración** de `Clock`, `CompliancePolicyProvider` y `OperationalSettingsProvider`, y una sola de cada adaptador (`SystemClock`, `DbCompliancePolicyProvider`, `DbOperationalSettingsProvider`) en todo el árbol | Que no reaparezca la duplicación de puerto que ADR-025 vino a resolver | ADR-021 + ADR-025 |

   Etiquetas: `->group('RNF-M-03')` en las cuatro.
5. **Pest** con las cinco suites del §2: `Unit`, `Integration`, `Feature`, `Contract`, `Architecture`. `test-unit` ejecuta **solo `Unit`**, sin base de datos, y debe terminar en **menos de 2 s** (`CLAUDE.md`, §9.1).
6. **Rector** en `backend/rector.php` con los sets del §3.5: **PHP 8.4 + Laravel + code quality + dead code**. En CI va en **dry-run** y su umbral es **informativo** (§9.2).
7. Configurar la cobertura con los umbrales de RNF-M-01 y del §9.2: dominio **≥ 90 %**, global backend **≥ 75 %**.
8. Configurar la mutación: Pest `--mutate` (o Infection) **solo sobre `Modules/*/Domain`**, con **MSI ≥ 80 %** (§9.2, §9.3, RQ-10). Objetivo `make mutate`.
9. Enlazar todo en los objetivos del `Makefile`: `make quality` = Pint + PHPStan 9 + Deptrac + Rector dry-run (`CLAUDE.md`).
10. **Probar que la cadena puede fallar**, siguiendo la instrucción del agente `qa-testing`: romper a propósito y comprobar que se detecta. Es también la verificación de fase del §11.

**Artefactos.**

- `backend/phpstan.neon` (nivel 9), `backend/deptrac.yaml`, `backend/rector.php` (§2)
- `backend/pint.json` — ⚠️ No cubierto por los documentos: el árbol del §2 no lo lista. El §3.5 sí exige el preset `laravel`
- `backend/tests/Architecture/` con las pruebas de Pest Arch
- Objetivos `quality`, `test`, `test-unit`, `mutate` del `Makefile`

**Pruebas exigidas.** El §9.5 no cubre esta naturaleza de cambio. Lo que exige el §9.2:

- **Arquitectura**: Pest Arch + Deptrac, **0 violaciones de frontera**.
- **Unitarias**: Pest, cobertura de dominio ≥ 90 % (verificable cuando exista dominio, en 1.1 y 1.2).
- **Mutación**: MSI ≥ 80 % sobre `Modules/*/Domain` (íd.).
- Las pruebas de arquitectura se etiquetan con **RNF-M-03**, que es el requisito que materializan (§9.6).

**Verificación.**

```bash
make quality        # Pint + PHPStan 9 + Deptrac + Rector dry-run, todo en verde
make test-unit      # verde en menos de 2 s
make test           # verde

# Verificación de fase (§11), a propósito:
#   añadir  use Illuminate\Support\Facades\DB;  en un fichero de Modules/*/Domain/
vendor/bin/deptrac  # debe fallar con violación de frontera
#   añadir  now()  en un método de Modules/*/Domain/
make test           # Pest Arch debe fallar
```

Resultado esperado: `make quality` en verde en el estado limpio, y **rojo** en ambos casos de sabotaje. Si no falla, la cadena no sirve.

**Terminado cuando** (subconjunto de §10.3):

- [ ] Código conforme a la arquitectura (Deptrac en verde).
- [ ] Convenciones del §3.5 respetadas, verificadas por Pint, PHPStan, ESLint y `vue-tsc`.
- [ ] PHPStan nivel 9 sin errores.
- [ ] Revisado por otra persona, o por el agente `revisor-codigo` y validado por una persona.

---

### Tarea 0.4 — Pipeline de CI con las etapas 1–3

| | |
|---|---|
| **Horas** | 3–4 |
| **Agente / Skill** | `devops-observabilidad` |
| **Requisitos** | Sin asignación literal en el doc 02 §11. De la fase: **RS-10** (análisis de dependencias y de código en cada *pull request*; ninguna vulnerabilidad crítica o alta puede llegar a una versión publicada) y **RNF-M-06** (las convenciones *«se verifican por herramienta en la CI, no por revisión humana»*) |
| **Precondiciones** | Derivada, no literal (§11.3 no incluye 0.4): **0.3**, porque la etapa ① ejecuta las herramientas que 0.3 configura |
| **Bloquea a** | No figura en el camino crítico del §11.3. Derivado: **0.7**, cuya etapa ③b se añade a este pipeline |

**Objetivo.** Cada *push* dispara las etapas ① Lint + Tipos, ② Arquitectura y ③ Unitarias + Mutación, y devuelve resultado en **menos de 4 minutos**.

**Reglas duras aplicables.**

- **1** y **2**: la CI es el mecanismo que las hace vinculantes. El entregable de la fase se enuncia así: *«las fronteras arquitectónicas se verifican solas»*.
- La regla de conducta del agente `devops-observabilidad`: **ningún secreto en el repositorio, en las imágenes ni en los logs del pipeline**.

**Pasos.** Sin skill asignada. Orden derivado del pipeline por etapas del §10.1.

1. Crear `.github/workflows/ci.yml` (§2) con las tres etapas y sus presupuestos de tiempo literales del §10.1:

   | Etapa | Contenido | Presupuesto (§10.1) |
   |---|---|---|
   | ① Lint + Tipos | Pint · PHPStan 9 · ESLint · `vue-tsc` · ShellCheck | ~1 min |
   | ② Arquitectura | Deptrac · Pest Arch | ~30 s |
   | ③ Unitarias + Mutación | Pest · MSI ≥ 80 % | ~2 min |

2. Configurar el disparo: **etapas 1–3 en cada *push***; las 4–7 en cada PR y la 8 antes de publicar versión (§10.1) — **fuera del alcance de esta tarea**, que solo cubre 1–3.
3. Añadir `shfmt -i 2 -d` junto a ShellCheck en la etapa ① (§3.5, §9.2: 0 hallazgos).
4. Cachear dependencias de Composer y npm para respetar el presupuesto de 4 minutos. El §10.1 razona el límite y el agente `devops-observabilidad` lo remata: *«una CI lenta se acaba ignorando»*.
5. Garantizar que ningún secreto aparece en la salida del pipeline (§7.7 y regla de conducta del agente).
6. Dejar preparados, sin implementar, `.github/workflows/e2e.yml` y `.github/workflows/release.yml`, que el árbol del §2 sí lista. Su contenido corresponde a las etapas ⑦ y ⑧ (tareas 3.7 y 5.x).
7. **Crear el `CHANGELOG.md` y atarlo a la cadena.** El §10.5 lo exige —*«la versión desplegada es visible en `/api/v1/health`»* y el producto se versiona con **SemVer**—, y ninguna tarea lo producía. Se **genera** a partir de los mensajes de *commit* con formato convencional, no se escribe a mano, y su generación forma parte del pipeline de publicación. Aquí se crea el fichero, se fija el formato y se añade la comprobación de que **una versión publicada sin entrada en el `CHANGELOG` falla**. Es lo que permite que el actualizador de la tarea 5.7 diga al cliente qué cambia antes de aplicar nada.

**Artefactos.**

- `.github/workflows/ci.yml`
- `.github/workflows/e2e.yml` y `.github/workflows/release.yml` — creados como marcadores; su contenido no es de esta fase
- **`CHANGELOG.md`** — generado, SemVer, con formato convencional de *commit* como fuente (§10.5). ⚠️ No cubierto por el árbol del §2, que no lo lista, aunque el §10.5 lo exige

**Pruebas exigidas.** El §9.5 no cubre esta naturaleza de cambio. Lo que se verifica es el propio pipeline, con la verificación de fase del §11.

**Verificación.**

```bash
git switch -c ci/verificacion-fase-0
# 1. Sabotaje de arquitectura: use Illuminate\... dentro de Domain/
git commit -am "test: violación deliberada de frontera" && git push
#    la CI debe fallar en la etapa ②
# 2. Sabotaje de estilo: un fichero sin declare(strict_types=1)
#    la CI debe fallar en la etapa ①
# 3. Sabotaje de shell: un script sin set -euo pipefail
#    la CI debe fallar en la etapa ① (ShellCheck)
```

Resultado esperado: las tres etapas en verde sobre `main` limpio, en menos de 4 minutos acumulados, y rojo en cada sabotaje **en la etapa que le corresponde**. Si un sabotaje de arquitectura falla en la etapa ①, las etapas están mal repartidas.

**Terminado cuando** (subconjunto de §10.3):

- [ ] Código conforme a la arquitectura (Deptrac en verde en la CI, no solo en local).
- [ ] Convenciones del §3.5 verificadas por herramienta en la CI.
- [ ] Ningún secreto en los logs del pipeline.
- [ ] Runbook o documentación actualizada si añade un modo de fallo — un fallo de CI lo es para quien lo recibe.

---

### Tarea 0.5 — Esqueleto de los tres frontends con TS estricto, Tailwind y Vitest

| | |
|---|---|
| **Horas** | 4–6 |
| **Agente / Skill** | `frontend-quiosco` |
| **Requisitos** | Sin asignación literal en el doc 02 §11. De la fase: **RNF-M-06** (convenciones publicadas de cada stack, incluida la guía de estilo oficial de Vue 3 y TypeScript estricto) |
| **Precondiciones** | Derivada, no literal (§11.3 no incluye 0.5): **0.1**, porque `node-kiosk`, `node-admin` y `node-portal` son servicios de Compose |
| **Bloquea a** | No figura en el camino crítico del §11.3. Derivado: **1.8** (PWA del quiosco) y **1.11** (portal) |

**Objetivo.** Los tres frontends arrancan con Vite 6, TypeScript estricto sin `any`, Tailwind 4 y Vitest, y `vue-tsc` da 0 errores en los tres.

**Reglas duras aplicables.**

- **11** (la credencial es una tarjeta física impresa) y **12** (el producto no depende del correo del empleado): condicionan qué **no** se monta. El portal no lleva `vite-plugin-pwa`, ni Dexie, ni `@zxing/*`.
- **13** (nada específico de un cliente): la marca es configuración (RF-PD-08, tarea 5.8). Ningún logotipo ni color de cliente entra en el código.

**Pasos.** Sin skill asignada. Orden derivado del §3.3, del §2 y de las restricciones técnicas del agente `frontend-quiosco`.

1. Crear los tres proyectos con Vite 6 y Vue 3.5+ (§3.3): `frontend-kiosk/`, `frontend-admin/`, `frontend-portal/`.
2. Configurar **TypeScript 5.6+ en modo estricto**, con `noUncheckedIndexedAccess` incluido y **sin `any`**: lo desconocido es `unknown` y se estrecha (§3.5).
3. Configurar Tailwind CSS 4, Pinia 2, Vue Router 4 y `vue-i18n` 10 en los tres (§3.3).
4. Instalar las dependencias exclusivas de cada aplicación (§3.3): quiosco → `@zxing/browser`, `@zxing/library`, Dexie 4, `vite-plugin-pwa` + Workbox, Screen Wake Lock; panel → TanStack Table, TanStack Query, ECharts; portal → **nada adicional**, porque **no es una PWA** (§3.3, ADR-015).
5. Crear la estructura de carpetas del §2, **por *feature*, no por tipo de fichero** (§3.5):
   - `frontend-kiosk/src/features/{scan,pin,offline,pairing,diagnostics}/`, `src/shared/{api,i18n}/`, `src/sw/`
   - `frontend-admin/src/features/{live,workdays,incidents,reports,employees,credentials,devices,settings}/`
   - `frontend-portal/src/features/{login,my-records,my-export}/`
6. Configurar ESLint con `eslint-plugin-vue` en `flat/recommended` y `@typescript-eslint` en modo estricto, más Prettier (§3.5). La configuración definitiva es de la tarea 0.7.
7. Configurar Vitest + Vue Test Utils con umbral **≥ 70 %** (§9.2), y el guion `npm run api:generate` que generará el cliente HTTP del contrato (§3.3, skill `endpoint-api` paso 7). Sin `openapi.yaml` (tarea 0.6) el guion existe pero no produce nada útil todavía.
8. Fijar el presupuesto de bundle del quiosco en el build: **JS crítico ≤ 250 KB gzip** y **CSS ≤ 40 KB gzip** (Anexo A del doc 02, RNF-P-07). La etapa ⑥ de la CI comprueba el presupuesto (§10.1).
9. Preparar `frontend-kiosk/e2e/fixtures/` para el `qr-video.y4m` del §2 y §9.4. **El vídeo se genera cuando exista una credencial real de prueba** (tarea 1.5).
10. Comprobar en los tres: `npm run type-check && npm run lint && npm run test:unit && npm run build` (comando de verificación de los tres agentes de frontend).

**Artefactos.**

- `frontend-kiosk/`, `frontend-admin/`, `frontend-portal/` con la estructura de `src/features/` del §2
- `frontend-kiosk/tests/{unit,e2e}/` (§2)
- Configuración de ESLint, Prettier, `vue-tsc` y Vitest en los tres

**Pruebas exigidas.** El §9.5 no cubre esta naturaleza de cambio. Lo que exige el §9.2:

- **Tipos frontend**: `vue-tsc` en modo estricto, **0 errores**.
- **Estilo**: ESLint + Prettier, **sin desviaciones**.
- **Frontend unit**: Vitest + Vue Test Utils, **≥ 70 %** (el umbral se hace exigible cuando haya código de negocio).

**Verificación.**

```bash
# En cada uno de los tres proyectos
npm run type-check        # 0 errores de vue-tsc
npm run lint             # sin desviaciones
npm run test:unit        # verde
npm run build            # el quiosco, dentro del presupuesto de 250 KB gzip

# Comprobación de que el portal no es una PWA
grep -r "vite-plugin-pwa\|workbox\|serviceWorker" frontend-portal/   # sin resultados
```

Resultado esperado: los tres builds en verde, el bundle del quiosco por debajo de 250 KB gzip, y **cero rastro de service worker en el portal**.

**Terminado cuando** (subconjunto de §10.3):

- [ ] Convenciones del §3.5 respetadas, verificadas por ESLint y `vue-tsc`.
- [ ] Textos externalizados en español e inglés (la infraestructura de `vue-i18n` con ambos *locales* creada).
- [ ] Accesibilidad verificada en las pantallas nuevas — aplicable cuando existan pantallas.
- [ ] Nada específico de un cliente ha entrado en el código.

---

### Tarea 0.6 — ADR-001 a ADR-028 escritos y `openapi.yaml` inicial

| | |
|---|---|
| **Horas** | 3–4 |
| **Agente / Skill** | `arquitecto-dominio` |
| **Requisitos** | Sin asignación literal en el doc 02 §11. De la fase: **RNF-M-04** (toda decisión arquitectónica relevante queda registrada como ADR versionado) y **RQ-06** (la API está descrita por un contrato OpenAPI y las respuestas se validan contra el esquema en las pruebas) |
| **Precondiciones** | Derivada, no literal (§11.3 no incluye 0.6): **0.2**, porque el contrato se apoya en la estructura de módulos y rutas |
| **Bloquea a** | No figura en el camino crítico del §11.3. Derivado: **1.7** y toda tarea que toque la API, porque *«el contrato se modifica antes que el código»* (ADR-013) |

**Objetivo.** Los **28** ADR existen en `docs/adr/` con contexto y consecuencias, y `docs/api/openapi.yaml` describe ya `/health` y `/scan` como fuente de verdad.

> **Son 28 y no 20, y ocho ya están escritos.** El §4 del doc 02 lista **28**: los 20 originales, más los ocho —**ADR-021 a ADR-028**— que nacieron al desarrollar este plan tarea por tarea, al aparecer decisiones que ningún documento determinaba. **Los ocho existen ya en `docs/adr/` desde antes de empezar la Fase 0**, porque el plan no se puede ejecutar sin ellos: ADR-025 gobierna la configuración de Deptrac de la tarea 0.3, ADR-026 el esquema de la 1.3 y ADR-027 el de la 2.2. Lo que esta tarea escribe son los **20 primeros**, que hoy solo existen como fila de la tabla del §4; los ocho últimos se **revisan**, no se redactan.

**Reglas duras aplicables.**

- Todas las que derivan de un ADR quedan aquí documentadas con su porqué. En particular **4** (ADR-006, los turnos no se parten a medianoche), **7** (ADR-007, proyección reconstruible), **8** (ADR-008, idempotencia por `scan_id`), **10** (ADR-005, HMAC), **11** (ADR-014, tarjeta física), **12** (ADR-015, código y PIN), **13** (ADR-017, configuración no código), **15** (ADR-019, la licencia no bloquea el fichaje), **16** (ADR-020, sin acceso del fabricante), **20** (ADR-009, cero biometría).
- Regla de conducta del agente `arquitecto-dominio`: si algo contradice un ADR, **no se implementa**; se explica el conflicto.

**Pasos.** Sin skill asignada. Orden derivado del §4 y de la skill `endpoint-api` (paso 1: contrato primero).

1. Escribir los **20 primeros** ADR en `docs/adr/` a partir de la primera tabla del §4, uno por fichero, con las tres secciones que la tabla ya da: **decisión**, **contexto y motivo**, **consecuencias**. Los **ADR-021 a ADR-028** ya existen como fichero completo: se revisan, se comprueba que su estado y sus referencias siguen siendo correctos, y **no se reescriben**.
2. Enlazar cada ADR con las reglas duras de `CLAUDE.md` y los requisitos del doc 01 que lo sostienen. `CLAUDE.md` fija el orden de autoridad: `docs/adr/` manda sobre todo lo demás.
3. Verificar contra ADR-001 la nota literal del §3.1: *«Verificar la versión mayor vigente al arrancar y actualizar el ADR si procede»*. Si Laravel ha cambiado de versión mayor, se actualiza ADR-001, no el código a escondidas.
4. Crear `docs/api/openapi.yaml` en **OpenAPI 3.1** (§3.1), con la ruta versionada `/api/v1` (ADR-012).
5. Describir las **sondas de salud** del Anexo B del doc 01: `GET /api/v1/health` y `GET /api/v1/ready`. La versión desplegada es visible en `/api/v1/health` (§10.5).
6. Describir `POST /api/v1/scan` (Anexo B: *Registrar escaneo (quiosco)*, `[scope: kiosk]`) con lo que exige la skill `endpoint-api` paso 1: esquema de petición con `date-time` en UTC con `Z` y `uuid`, respuesta con **solo los campos que el rol autorizado debe ver**, errores en `application/problem+json`, `security` con el ámbito requerido, cabecera **`Idempotency-Key`** por ser escritura de quiosco, y ejemplos reales en petición y respuesta.
7. Declarar los ámbitos de token del §7.3 en el `securitySchemes`: quiosco `scan:write`, `roster:read`, `heartbeat:write`; empleado `self:read`; y los de gestión.
8. Configurar Spectator en `backend/tests/Contract/` para validar respuestas contra el contrato (§3.1, §9.2, RQ-06).
9. Comprobar que `npm run api:generate` produce el cliente TypeScript en los tres frontends a partir de este contrato (§3.3).

**Artefactos.**

- `docs/adr/ADR-001…ADR-028` (§2). Los 20 primeros se escriben aquí; `ADR-021…ADR-028` ya existen y solo se revisan
- `docs/api/openapi.yaml` (§2) — **fuente de verdad de la API** (`CLAUDE.md`, ADR-013)
- `backend/tests/Contract/` con la configuración de Spectator

**Pruebas exigidas.** El §9.5 sí aplica en un punto: describir un **endpoint** exige *Feature + Contrato* y *autorización negativa por cada rol*. Aquí solo se escribe el contrato, no el endpoint, así que lo exigible ahora es:

- **Contrato** (§9.2): Spectator contra `openapi.yaml`; toda respuesta valida el esquema. La prueba real llega con el endpoint (1.7).
- Etiquetas de trazabilidad: las pruebas de contrato de `/scan` se etiquetan con `->group('RF-AT-01')` y las de salud con lo que corresponda (§9.6).

**Verificación.**

```bash
# El contrato es válido OpenAPI 3.1
npx @redocly/cli lint docs/api/openapi.yaml       # ⚠️ herramienta no fijada por los documentos

# El cliente se genera en los tres frontends
cd frontend-kiosk && npm run api:generate
cd ../frontend-admin && npm run api:generate
cd ../frontend-portal && npm run api:generate

ls docs/adr/ | wc -l                              # 28 ficheros
php artisan docs:consistency --check              # toda fila del §4 tiene su fichero (0.7)
```

Resultado esperado: el contrato pasa la validación, los tres clientes se generan sin error de tipos, y `docs/adr/` contiene **28** ADR, uno por cada fila de las dos tablas del §4. ⚠️ No cubierto por los documentos — decidir: la herramienta concreta de validación del `openapi.yaml` (el §3.1 solo fija `spectator` en pruebas).

**Terminado cuando** (subconjunto de §10.3):

- [ ] Contrato OpenAPI actualizado y validado en las pruebas.
- [ ] ADR escrito si la decisión es estructural — aquí se escriben los 20 primeros y se revisan los ocho ya existentes, hasta que `docs/adr/` tenga **28 ficheros** y ninguna fila del §4 se quede sin el suyo.
- [ ] Convenciones del §3.5 respetadas.
- [ ] Revisado por otra persona, o por `revisor-codigo` y validado por una persona.

---

### Tarea 0.7 — Convenciones del §3.5 configuradas y comandos `qa:traceability` y `docs:consistency` con su etapa de CI

| | |
|---|---|
| **Horas** | **7–10** (3–4 originales, más 4–6 de `docs/requisitos.yaml` y `docs:consistency`) |
| **Agente / Skill** | `devops-observabilidad` + `qa-testing` |
| **Requisitos** | **RNF-M-06, RQ-13..14** (literal de la tabla del doc 02 §11). Y **RQ-12**, que esta tarea materializa: la trazabilidad requisito → prueba solo es evidencia si la genera una herramienta |
| **Precondiciones** | Derivadas, no literales (§11.3 no incluye 0.7): **0.3** (herramientas de backend), **0.4** (el pipeline al que se añade la etapa ③b), **0.5** (herramientas de frontend) |
| **Bloquea a** | No figura en el camino crítico del §11.3. Derivado: **toda la Fase 1**, porque a partir de aquí un requisito implementado sin prueba no puede integrarse (RQ-13) |

**Objetivo.** Las convenciones del §3.5 están todas atadas a una herramienta que bloquea en la CI, y `php artisan qa:traceability --check` falla si un requisito ya implementado no tiene ninguna prueba que lo referencie.

**Reglas duras aplicables.**

- **1** y **2**: Deptrac y Pest Arch quedan aquí en su forma definitiva.
- La regla que gobierna el §3.5 y que el agente `devops-observabilidad` aplica sin excepción: **si alguien propone una convención nueva, o se ata a una herramienta que la comprueba, o no entra en el §3.5.** Lo que solo puede verificar una persona pertenece a la lista de `revisor-codigo`.

**Pasos.** Sin skill asignada. Orden derivado del §3.5 (una fila por convención, con su verificador) y del §9.6.

1. Repasar el §3.5 fila por fila y comprobar que **cada convención tiene su herramienta**:

   | Ámbito | Convención | Quién la verifica |
   |---|---|---|
   | Estilo PHP | PSR-12 y PER Coding Style 2.0, preset `laravel` | Laravel Pint |
   | Autoload y estructura | PSR-4 | Composer, Deptrac |
   | Tipado PHP | `declare(strict_types=1)` en todo fichero, tipos en propiedades, parámetros y retornos, sin `mixed` sin justificar, genéricos en PHPDoc | PHPStan nivel 9 |
   | Inmutabilidad | Objetos de valor y DTO con `readonly`; DTO `final` | PHPStan, revisión |
   | Modernización | Sintaxis PHP 8.4, `enum` en lugar de constantes de clase, `match`, *property hooks* | Rector (PHP 8.4 + Laravel + code quality + dead code) |
   | Nombres de Laravel | Modelos singular, tablas plural `snake_case`, FK `{singular}_id`, `{Recurso}Controller`, migraciones con verbo | Pint, revisión |
   | Laravel idiomático | FormRequest, Resource, Policy, comandos con firma explícita. **Sin lógica de negocio en controladores ni en modelos Eloquent** | Deptrac, `revisor-codigo` |
   | Facades | Prohibidas en `Domain/` y `Application/` | Deptrac |
   | Complejidad | Ciclomática ≤ 10 por método | PHPStan |
   | Estilo de Vue | Guía oficial de Vue 3, prioridades A y B | `eslint-plugin-vue` con `flat/recommended` |
   | API de componente | Composition API con `<script setup lang="ts">`, *composables* `useAlgo()` | ESLint, revisión |
   | Tipado TS | Estricto, `noUncheckedIndexedAccess`, **sin `any`**, tipos de la API generados | `vue-tsc`, `@typescript-eslint` estricto |
   | Formato frontend | Prettier | Prettier + ESLint |
   | Robustez de scripts | `set -euo pipefail` e `IFS=$'\n\t'` | ShellCheck |
   | Estilo de scripts | Guía de Shell de Google, `shfmt -i 2` | ShellCheck + shfmt |
   | Secretos en scripts | Nunca en el script ni en su salida | Semgrep |

2. Anotar explícitamente las convenciones del §3.5 cuyo verificador es «revisión»: inmutabilidad más allá de lo que ve PHPStan, idempotencia y fallo seguro de los scripts, mensajes de error accionables, *stores* de Pinia, carpeta por *feature*, y las siete del código de pruebas. **Esas van a la lista de `revisor-codigo`, no a la cadena de calidad.**
3. Configurar **ShellCheck** y **shfmt -i 2 -d** sobre `infra/scripts/` y sobre los scripts entregados al cliente, con umbral **0 hallazgos** (§9.2), en la etapa ①.
4. Configurar **Semgrep** con reglas PHP/Laravel, umbral **0 hallazgos de severidad alta** (§9.2). Vive en la etapa ⑤, fuera del alcance de 0.4, pero se configura aquí.
5. Implementar el comando `php artisan qa:traceability` (Anexo C del doc 02): recorre la suite, extrae las etiquetas y genera `docs/trazabilidad-pruebas.md` (§9.6).
6. **Crear `docs/requisitos.yaml`, la fuente legible por máquina del Anexo A**, y una variable de configuración `CURRENT_PHASE`. Sin esto, `--check` no tiene de dónde leer su alcance:

   ```yaml
   # docs/requisitos.yaml — una entrada por requisito, expandido, sin rangos
   - { id: RF-ID-04, fase: 1, titulo: "Provisionar y vincular un dispositivo de quiosco" }
   - { id: RF-ID-05, fase: 1, titulo: "Acceso del empleado al portal con código y PIN" }
   - { id: RF-ID-09, fase: 1, titulo: "Provisión, entrega y restablecimiento del PIN" }
   ```

   > **Por qué hace falta y cuál es el modo de fallo peligroso.** El §9.6 define el alcance del bloqueo como *«los requisitos de las fases ya ejecutadas, tomados del Anexo A del documento 01»*, pero **el Anexo A es una tabla en prosa markdown**, con notación de rango (`RF-ID-04..09`), anotaciones entre paréntesis y entradas no codificadas; y nada dice de dónde sale el estado «fase ya ejecutada». El modo de fallo peligroso **no es que el comando reviente: es que dé verde por no saber expandir un rango**, que es exactamente cómo `RF-ID-09` pasó desapercibido y se quedó sin tarea hasta que lo encontró una auditoría manual. Un comando de trazabilidad que falla en silencio es peor que no tenerlo, porque da una garantía que no presta.

   Dos pruebas propias, ambas bloqueantes:
   - **El YAML no diverge del Anexo A**: mismo conjunto de identificadores y misma fase, comparando contra la tabla del doc 01. Es el mismo patrón que la tarea 3.2 aplica a los runbooks —comprobar que el fichero referenciado existe y coincide—, aplicado aquí a los requisitos.
   - **El expansor de rangos funciona**: `RF-ID-04..09` produce **seis** identificadores, no dos ni una cadena literal.

7. Implementar `php artisan qa:traceability --check`: **falla si un requisito implementado no tiene prueba**. Lee el alcance de `docs/requisitos.yaml` filtrando por `fase <= CURRENT_PHASE` en el orden real de ejecución (0 → 1 → 2 → 5 → 3 → 4), de modo que *«un requisito de la Fase 3 no bloquea mientras se trabaja en la Fase 1»* (§9.6).
8. Soportar los dos formatos de etiqueta del §9.6: Pest con `->group('RN-05', 'RF-AT-08')` y Playwright con `{ tag: ['@RF-KI-03', '@RF-KI-04'] }`.
9. **Implementar `php artisan docs:consistency --check`**, que verifica la coherencia entre los documentos que mandan y los ficheros que los ejecutan. Falla si:

   | Comprobación | Qué habría evitado |
   |---|---|
   | **Un ADR citado en la tabla del doc 02 §4 no tiene fichero en `docs/adr/`** | Que ADR-026, 027 y 028 vivieran solo como fila de tabla —autoridad #4— siendo autoridad #1 |
   | **Un requisito de `docs/requisitos.yaml` no aparece en ninguna tarea del plan** | Que `RF-ID-09` no lo construyera nadie |
   | **La fase de un requisito no coincide con el fichero de plan que contiene su tarea** | Que `RN-11` y `RN-12` estuvieran en la Fase 2 del Anexo A y se implementaran en la 3, y que el encabezado de la Fase 3 citara `RF-GP-05` después de moverlo a la 5.5 |

   > **Los seis bloqueantes de la auditoría previa fueron el mismo fallo repetido** —una decisión se registra en un documento y no se propaga a los otros— **y ese fallo es mecánicamente detectable.** El plan ya aplica ese principio al código: *«una convención que no verifica una herramienta es una sugerencia»* (§3.5). Le faltaba aplicárselo a sí mismo. Es la corrección más barata del plan y la que más caro sale no tener.

10. Añadir la **etapa ③b** al pipeline de 0.4: `qa:traceability --check` **y `docs:consistency --check`**, ~15 s, **ambos bloqueantes** (§10.1, §9.6: *«La etapa 3 de la CI ejecuta `--check` y bloquea»*).
11. Verificar los comandos contra el Anexo A del doc 01: los requisitos de la Fase 0 (`RNF-M-01..06`, `RQ-01`, `RQ-06`, `RQ-12`, `RQ-13..14`, `RS-08..10`) deben aparecer resueltos o justificados, y los de fases posteriores no deben bloquear.

**Artefactos.**

- Configuración de Pint, PHPStan, Rector, Deptrac, ESLint + `eslint-plugin-vue`, Prettier, `vue-tsc`, ShellCheck, shfmt y Semgrep
- `backend/app/Modules/Product/…` o comando de consola equivalente para `qa:traceability` y `docs:consistency` — ⚠️ No cubierto por los documentos — decidir: el módulo en el que viven los comandos. El Anexo C los agruparía bajo «Calidad y trazabilidad», sin asignarles módulo
- **`docs/requisitos.yaml`** — fuente legible por máquina del Anexo A, con `id`, `fase` y `titulo` por requisito, sin rangos
- **`CURRENT_PHASE`** en configuración (Anexo B) — ⚠️ No cubierto por los documentos: es una variable nueva. Su valor por defecto es la fase en curso del plan y **se actualiza al cerrar cada fase**, como parte del procedimiento de cierre
- `docs/trazabilidad-pruebas.md` (§9.6), generado
- Etapa ③b en `.github/workflows/ci.yml`, con los **dos** comandos

**Pruebas exigidas.** El §9.5 no cubre esta naturaleza de cambio. Lo exigible es que el propio comando se demuestre capaz de fallar:

- Una prueba del comando que verifique que **detecta un requisito implementado sin etiqueta** y devuelve código de salida distinto de cero. Instrucción del agente `qa-testing`: *«si escribes la prueba después de la implementación y pasa a la primera, rompe la implementación a propósito para verificar que la prueba realmente puede fallar»*.
- Etiquetar esa prueba con `->group('RQ-13')`.
- **`docs/requisitos.yaml` no diverge del Anexo A**: mismo conjunto de identificadores y misma fase → `->group('RQ-12', 'RQ-13')`.
- **El expansor de rangos convierte `RF-ID-04..09` en seis identificadores** → `->group('RQ-13')`. Es la prueba que evita el fallo silencioso.
- **`docs:consistency --check` falla** si se borra un fichero de `docs/adr/` citado en el §4, si se quita un requisito de toda tarea del plan, y si la fase de un requisito no coincide con el fichero de plan de su tarea. Las tres, con sabotaje deliberado → `->group('RQ-12', 'RNF-M-04')`.

**Verificación.**

```bash
php artisan qa:traceability                 # genera docs/trazabilidad-pruebas.md
php artisan qa:traceability --check         # verde
#   quitar la etiqueta ->group('RNF-M-03') de la prueba de arquitectura
php artisan qa:traceability --check         # debe fallar con código ≠ 0

php artisan docs:consistency --check        # verde
#   mv docs/adr/ADR-026-la-correccion-supersede.md /tmp/
php artisan docs:consistency --check        # debe fallar: el §4 lo cita y no existe

make quality                                # todas las herramientas del §3.5 en verde
shellcheck infra/scripts/*.sh && shfmt -i 2 -d infra/scripts/
```

Resultado esperado: la matriz se genera, `--check` está en verde en el estado limpio y **rojo** en cuanto se quita una etiqueta o se descuadra un documento, y la etapa ③b de la CI bloquea el *merge* con cualquiera de los dos comandos en rojo.

**Terminado cuando** (subconjunto de §10.3):

- [ ] Pruebas etiquetadas con los requisitos que cubren, y `qa:traceability --check` en verde (§9.6).
- [ ] **`docs/requisitos.yaml` existe, cuadra con el Anexo A y el expansor de rangos tiene prueba.**
- [ ] **`docs:consistency --check` en verde, en la etapa ③b y demostrado capaz de fallar en las tres comprobaciones.**
- [ ] Convenciones del §3.5 respetadas, verificadas por Pint, PHPStan, ESLint y `vue-tsc`.
- [ ] PHPStan nivel 9 sin errores nuevos.
- [ ] Runbook o documentación actualizada si añade un modo de fallo o un parámetro — un `--check` en rojo lo es, y `CURRENT_PHASE` es un parámetro nuevo del Anexo B.
- [ ] Revisado por otra persona, o por `revisor-codigo` y validado por una persona.

---

## Cierre de fase (doc 03 §6.6)

La fase no está cerrada porque las siete tareas estén hechas. Se cierra ejecutando este procedimiento, literal del doc 03 §6.6:

```
Cierra la Fase 0 del plan.

1. seguridad-cumplimiento: revisa todo lo implementado contra STRIDE,
   RGPD y art. 34.9 ET. Informe por severidad.
2. revisor-codigo: revisión final buscando duplicación, complejidad
   innecesaria e incumplimientos de la Definición de Terminado.
3. qa-testing: verifica cobertura, MSI y que cada requisito de la fase
   (Anexo A del documento 01) tiene prueba que lo cubre.
4. devops-observabilidad: comprueba que lo nuevo está instrumentado y
   que cada alerta añadida tiene su runbook.

Entrégame: los hallazgos bloqueantes, los requisitos de la fase sin
cobertura de prueba, y qué queda pendiente para pasar a la siguiente.
```

**Comprobación final de la fase, literal del doc 02 §11:**

> **Verificación:** añadir a propósito un `use Illuminate\...` dentro de `Domain/` debe hacer fallar la CI.

**Requisitos de la fase que `qa:traceability --check` debe encontrar cubiertos** (doc 01, Anexo A): `RNF-M-01..06`, `RQ-01`, `RQ-06`, `RQ-13..14`, `RS-08`, `RS-09`, `RS-10`.

**Puerta de entrada a la Fase 1.** El §11.3 lo dice sin margen: `0.1→0.2→0.3 ──► 1.1→1.2`. Hasta que 0.3 esté cerrada, la tarea 1.1 no arranca; y el aviso del §11 sobre el camino crítico se aplica desde el primer día de la Fase 1.

---

← Anterior: [Herramientas y entorno](01-herramientas-y-entorno.md) · Siguiente: [Fase 1 — MVP de fichaje](03-fase-1-mvp-fichaje.md) · [Índice](README.md)
