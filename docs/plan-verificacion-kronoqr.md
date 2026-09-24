# Plan de verificación pre-release — KronoQR

> **Stack:** Laravel 13 hexagonal (módulos Attendance, Compliance, Reporting, Product) · PostgreSQL · Redis · Sanctum · Reverb · tres SPA en Vue 3 (quiosco PWA, panel, portal) · `packages/web-kit`.
>
> **Herramientas:** Pest, Pest Arch, Deptrac, PHPStan 9, Rector, Pint, Spectator, Playwright, k6.
>
> **Observabilidad:** OpenTelemetry, Prometheus, Grafana, Loki, Alertmanager.
>
> **Cómo leer cada tarea:** `🤖 agente · Modelo`. Cuando una tarea la detecta un agente de solo lectura, se indica también quién corrige: `→ corrige: agente`.
>
> **Severidades:**
> - `revisor-codigo`: 🔴 BLOQUEANTE · 🟠 IMPORTANTE · 🟡 MENOR
> - `seguridad-cumplimiento`: 🔴 CRÍTICO · 🟠 ALTO · 🟡 MEDIO · ⚪ BAJO

---

## Reparto de agentes y modelos

| Agente | Papel en la verificación | Modelo | Modo |
|---|---|---|---|
| `arquitecto-dominio` | Reglas de negocio, pureza hexagonal, ADR | **Opus 5.5** | Escritura |
| `backend-laravel` | Endpoints, casos de uso, migraciones, instrumentación | **Opus 5.5** | Escritura |
| `qa-testing` | Pirámide de pruebas, trazabilidad, E2E, carga | **Opus 5.5** | Escritura |
| `producto-licencia` | Instalador, actualizador, licencia, diagnóstico, docs cliente | **Opus 5.5** | Escritura |
| `revisor-codigo` | Revisión final, duplicación, Definición de Terminado | **Opus 5.5** | Solo lectura |
| `seguridad-cumplimiento` | STRIDE, RGPD, art. 34.9 ET | **Opus 5.5** | Solo lectura |
| `devops-observabilidad` | CI, infra, métricas, alertas, backups, runbooks | **Sonnet 5** | Escritura |
| `frontend-quiosco` | PWA del quiosco | **Sonnet 5** | Escritura |
| `frontend-panel` | SPA del panel | **Sonnet 5** | Escritura |
| `frontend-portal-empleado` | Portal del empleado | **Sonnet 5** | Escritura |
| `ui-ux` | Tokens, contraste, estados, coherencia visual | **Sonnet 5** | Escritura |
| 👤 **Tú** | Lo que exige dispositivo real, criterio de negocio o validación legal | — | — |

### Criterio de modelos

- **Opus 5.5 para el criterio.** Donde un error de razonamiento acaba en una nómina, en un registro legal alterable o en una instalación rota: dominio, seguridad, pruebas, revisión y productización.
- **Sonnet 5 para la ejecución.** Donde el feedback es inmediato y verificable (el build pasa, el bundle cabe, la alerta salta): frontends, infraestructura y diseño visual.
- **Fable 5.1 no se asigna por defecto a ninguna tarea.** Es solo un escalado puntual, y solo en dos casos:
  - un fallo intermitente de concurrencia o idempotencia que `qa-testing` no consigue reproducir tras dos iteraciones;
  - un error de cálculo con DST o con turnos nocturnos que Opus no resuelve.

  Para escalar, cambia temporalmente el campo `model:` del agente o el modelo de la sesión.
- **Fable no se usa en la Fase 6.** Sus salvaguardas adicionales de ciberseguridad lo hacen menos adecuado que Opus para esa revisión.
- **Los modelos coinciden con el `model:` de tu frontmatter.** No hace falta cambiar ninguna definición de agente.

### Reglas de flujo

1. El hilo principal de Claude Code orquesta y va marcando casillas. Cada tarea se delega al agente indicado.
2. Los agentes de solo lectura (`revisor-codigo`, `seguridad-cumplimiento`) **no corrigen**. Cada hallazgo va al registro final y lo corrige el agente dueño; después se repite la revisión.
3. Las SPA son independientes entre sí. En cada fase, los tres agentes frontend pueden trabajar **en paralelo**.

---

## Orden recomendado

| Paso | Fases | Agentes principales |
|---|---|---|
| 1 | 0 · Preparación | devops, qa-testing, backend, producto |
| 2 | 2 · Calidad de código | backend, arquitecto, frontends ∥, ui-ux |
| 3 | 3 · Pruebas | qa-testing, frontends ∥ |
| 4 | 6 · Seguridad (automatizado) | devops |
| 5 | 1 · Funcionalidad vs. especificación | arquitecto, backend, frontends ∥, producto, qa-testing |
| 6 | 4 · Control de errores | backend, frontends ∥, qa-testing, devops |
| 7 | 6 · Seguridad (revisión STRIDE / RGPD) | seguridad-cumplimiento |
| 8 | 5 · Observabilidad | backend, devops, producto |
| 9 | 7 · Rendimiento | qa-testing, backend, frontends ∥, devops |
| 10 | 8 · Datos y BD | backend, devops |
| 11 | 9 · Despliegue y productización | devops, producto |
| 12 | 10 · Documentación | producto, devops, arquitecto |
| 13 | 11 · Accesibilidad y UX | ui-ux, frontends ∥, qa-testing, 👤 |
| 14 | 12 · Revisión final | revisor-codigo, seguridad-cumplimiento |

---

## Fase 0 — Preparación

- [ ] **Congelar el código:** rama `release/x.y` o tag.
  - 🤖 hilo principal · Sonnet 5
- [ ] **Levantar el entorno** con `make up`, con datos de ejemplo que incluyan los casos límite: turnos nocturnos, DST, olvidos y correcciones.
  - 🤖 `devops-observabilidad` · Sonnet 5
- [ ] **Generar la matriz de trazabilidad** con `php artisan qa:traceability --check` y cruzarla con los `RF-*` y `RN-*` del doc. 01.
  - 🤖 `qa-testing` · Opus 5.5
- [ ] **Inventariar los endpoints:** cruzar `php artisan route:list --except-vendor` con `docs/api/openapi.yaml`. Una ruta sin contrato, o un contrato sin ruta, es un hallazgo.
  - 🤖 `backend-laravel` · Opus 5.5
- [ ] **Hacer una instalación limpia** en un contenedor vacío siguiendo **solo** `docs/cliente/instalacion.md`, y terminar con `doctor.sh` en verde.
  - 🤖 `producto-licencia` · Opus 5.5

---

## Fase 1 — Funcionalidad vs. especificación

- [ ] **Reglas de negocio:** cada regla `RN-*` del doc. 01 §4 está implementada en el dominio. Hay que comprobar que RN-01, RN-02, RN-06, RN-07 y RN-08 las protege el agregado `WorkDay`, y detectar reglas que vivan en el código y no estén en la especificación.
  - 🤖 `arquitecto-dominio` · Opus 5.5
- [ ] **ADR:** ninguna implementación contradice un ADR vigente.
  - 🤖 `arquitecto-dominio` · Opus 5.5
- [ ] **Backend:** los requisitos de API y casos de uso están cubiertos, sin *scope creep* sin documentar.
  - 🤖 `backend-laravel` · Opus 5.5
- [ ] **Quiosco:** cubre los requisitos `RF-KI-*`.
  - 🤖 `frontend-quiosco` · Sonnet 5
- [ ] **Panel:** cubre los requisitos `RF-PA-*` y `RF-IN-*`, cada rol ve solo lo suyo y las correcciones muestran el valor anterior y el nuevo antes de confirmar.
  - 🤖 `frontend-panel` · Sonnet 5
- [ ] **Portal:** cubre los requisitos `RF-ID-*`: acceso con código y PIN, mi registro y descarga del histórico.
  - 🤖 `frontend-portal-empleado` · Sonnet 5
- [ ] **Producto:** cubre los requisitos `RF-PD-*`: configuración sin código, perfiles de cumplimiento, licencia y marca blanca.
  - 🤖 `producto-licencia` · Opus 5.5
- [ ] **Escenarios Gherkin:** los del doc. 01 §11 están automatizados y en verde.
  - 🤖 `qa-testing` · Opus 5.5

---

## Fase 2 — Calidad de código

### Automatizado

- [ ] **Backend:** `make quality` en verde (Pint, PHPStan 9, Deptrac, Rector dry-run), y cada `@phpstan-ignore` lleva su justificación.
  - 🤖 `backend-laravel` · Opus 5.5
- [ ] **Cadena de calidad en CI:** está en la etapa ① del pipeline y las etapas 1–3 tardan menos de 4 minutos.
  - 🤖 `devops-observabilidad` · Sonnet 5
- [ ] **Scripts de infraestructura:** ShellCheck sin hallazgos y `shfmt -i 2` en `infra/scripts/`.
  - 🤖 `devops-observabilidad` · Sonnet 5
- [ ] **Scripts del cliente:** lo mismo en `install.sh`, `update.sh`, `doctor.sh` y `backup.sh`.
  - 🤖 `producto-licencia` · Opus 5.5
- [ ] **Quiosco:** `npm run type-check && npm run lint && npm run test:unit && npm run build` en verde.
  - 🤖 `frontend-quiosco` · Sonnet 5
- [ ] **Panel:** la misma cadena en verde.
  - 🤖 `frontend-panel` · Sonnet 5
- [ ] **Portal:** la misma cadena en verde.
  - 🤖 `frontend-portal-empleado` · Sonnet 5
- [ ] **`packages/web-kit`:** la misma cadena en verde.
  - 🤖 `ui-ux` · Sonnet 5

### Revisión de arquitectura

- [ ] **Pureza del dominio:** `Modules/*/Domain/` no importa `Illuminate\*`, Eloquent ni otros módulos.
  - 🤖 `arquitecto-dominio` · Opus 5.5
- [ ] **Reloj inyectado:** ninguna llamada a `now()`, `time()` ni `Carbon::now()` en `Domain/` ni en `Application/`.
  - 🤖 `arquitecto-dominio` · Opus 5.5
- [ ] **Comunicación entre módulos** solo por eventos de dominio o por un caso de uso público explícito.
  - 🤖 `arquitecto-dominio` · Opus 5.5
- [ ] **Objetos de valor:** son `readonly` y `final`, y no pueden representar estados imposibles.
  - 🤖 `arquitecto-dominio` · Opus 5.5

### Revisión de lo que no ven las herramientas

- [ ] **Duplicación:** busca un segundo cálculo de duración, un segundo formateador de horas o una segunda conversión de zona horaria. Cubre tanto el backend como las SPA frente a `packages/web-kit` (ADR-036).
  - 🤖 `revisor-codigo` · Opus 5.5 → corrige: el agente dueño del código
- [ ] **Estilo que ninguna herramienta comprueba:**
  - lenguaje ubicuo según el glosario del doc. 01 §13 (`ShiftEntry`, no `Tramo`);
  - nombres del dominio, no del patrón;
  - comentarios que explican el porqué;
  - abstracciones que no ganan nada.
  - 🤖 `revisor-codigo` · Opus 5.5
- [ ] **Sistema visual:** ningún hexadecimal suelto en las SPA y todos los tokens en `packages/web-kit`.
  - 🤖 `ui-ux` · Sonnet 5

---

## Fase 3 — Pruebas

### Umbrales

- [ ] **Umbrales de cobertura y rendimiento:**
  - cobertura de `Domain` ≥ 90 %;
  - cobertura del backend ≥ 75 %;
  - cobertura del frontend ≥ 70 %;
  - suite unitaria < 2 s.
  - 🤖 `qa-testing` · Opus 5.5
- [ ] **Mutación sobre el dominio:** MSI ≥ 80 % con `pest --mutate`.
  - 🤖 `qa-testing` · Opus 5.5
- [ ] **Trazabilidad:** todas las pruebas llevan etiqueta de requisito y `qa:traceability --check` bloquea la CI si falta.
  - 🤖 `qa-testing` · Opus 5.5
- [ ] **Relojes:** `FrozenTime::at()` en Feature, Integration y Contract, con `FrozenTimeTest` en verde.
  - 🤖 `qa-testing` · Opus 5.5
- [ ] **Contrato:** Spectator valida todas las respuestas contra `openapi.yaml`.
  - 🤖 `qa-testing` · Opus 5.5
- [ ] **Cero pruebas intermitentes.** Si alguna se resiste, se escala a Fable 5.1.
  - 🤖 `qa-testing` · Opus 5.5

### Escenarios obligatorios

Todos los de este bloque: 🤖 `qa-testing` · Opus 5.5.

- [ ] **DST:** cambio de hora de marzo y de octubre, con turnos que atraviesan el salto y comparación contra el intervalo UTC real.
- [ ] **Turno nocturno:** de 22:00 a 06:00, atribuido a la jornada de inicio y sin tramos artificiales.
- [ ] **Duraciones límite:** tramos de 0 y de 1 minuto, tramo de 13 h y jornada partida en 4 tramos.
- [ ] **Idempotencia:** 10 peticiones paralelas con el mismo `scan_id` producen un solo tramo y 10 respuestas idénticas.
- [ ] **Concurrencia:** dos fichajes simultáneos del mismo empleado no generan un segundo turno abierto.
- [ ] **Invariantes en la base de datos:** un solape o un segundo turno abierto insertados **por SQL directo** los rechaza PostgreSQL.
- [ ] **Offline:** con Playwright y la red cortada, se ficha, se reconecta y se consolida con el `occurred_at` original.
- [ ] **Lote desordenado:** entrada y salida enviadas en orden inverso se procesan por `occurred_at`.
- [ ] **Divergencia de totales:** se corrompe `daily_totals` a mano, se ejecuta `attendance:reconcile` y se comprueba la corrección y la alerta.
- [ ] **Cadena de auditoría:** se altera `audit_log` por SQL y `verify-audit-chain` lo detecta.
- [ ] **Cámara simulada:** E2E con `--use-fake-device-for-media-stream`.

### Pruebas unitarias de cada SPA

- [ ] **Quiosco:** cola Dexie, sincronización ordenada, liberación de la cámara y Wake Lock.
  - 🤖 `frontend-quiosco` · Sonnet 5
- [ ] **Panel:** correcciones, degradación del tiempo real y visibilidad por rol.
  - 🤖 `frontend-panel` · Sonnet 5
- [ ] **Portal:** la suma de tramos coincide con el total, y se usa la zona horaria del centro, no la del navegador.
  - 🤖 `frontend-portal-empleado` · Sonnet 5

---

## Fase 4 — Control de errores

- [ ] **Formato de error:** respuestas `application/problem+json` coherentes en toda la API.
  - 🤖 `backend-laravel` · Opus 5.5
- [ ] **Rechazos en el camino de fichaje:** genéricos y de tiempo constante; el detalle solo va al log y a `scan_events.result`.
  - 🤖 `backend-laravel` · Opus 5.5
- [ ] **Registro de errores:** los errores no controlados se guardan en `error_events` agrupados por huella y **sin datos personales** (RF-PD-15).
  - 🤖 `backend-laravel` · Opus 5.5
- [ ] **Transacciones:** un caso de uso es una transacción, y la proyección de `daily_totals` va dentro de ella.
  - 🤖 `backend-laravel` · Opus 5.5
- [ ] **Errores del quiosco:** se reportan en el latido, sin datos personales.
  - 🤖 `frontend-quiosco` · Sonnet 5
- [ ] **Tiempo real del panel:** reconexión WebSocket, respaldo a sondeo cada 15 s y marca de última actualización siempre visible.
  - 🤖 `frontend-panel` · Sonnet 5
- [ ] **Estados de las tres SPA:** vacío, carga y error diseñados, diciendo qué ha pasado y qué hacer.
  - 🤖 `ui-ux` · Sonnet 5 (diseño) + los agentes frontend (implementación)
- [ ] **Scripts e instalador:** los mensajes de error dicen qué hacer, y un fallo a medias deja el sistema como estaba.
  - 🤖 `producto-licencia` · Opus 5.5

### Pruebas de caos

- [ ] **Caídas de infraestructura:**
  - PostgreSQL parado a mitad de una operación;
  - Redis caído;
  - worker de colas parado.
  - 🤖 `devops-observabilidad` · Sonnet 5 (provocar el fallo) + `qa-testing` · Opus 5.5 (verificar el comportamiento)
- [ ] **Peor escenario del quiosco:** 40 fichajes encolados, WiFi intermitente y reconexión.
  - 🤖 `qa-testing` · Opus 5.5

---

## Fase 5 — Observabilidad y métricas

- [ ] **Instrumentación:** cada caso de uso tiene su métrica y su span de traza.
  - 🤖 `backend-laravel` · Opus 5.5
- [ ] **Logs:** JSON con `trace_id`, `scan_id`, `device_id` y `employee_uuid`, y **nunca nombres de empleados**.
  - 🤖 `backend-laravel` · Opus 5.5
- [ ] **Métricas de negocio en Grafana (como código):** latido de quioscos, cola offline y escaneos rechazados.
  - 🤖 `devops-observabilidad` · Sonnet 5
- [ ] **Métricas de integridad:** `projection_divergence_total` y `audit_chain_verification_failures_total` están a cero, con alerta crítica inmediata.
  - 🤖 `devops-observabilidad` · Sonnet 5
- [ ] **Alertas:** cada alerta tiene su runbook en `docs/runbooks/` y **se ha disparado al menos una vez** a propósito.
  - 🤖 `devops-observabilidad` · Sonnet 5
- [ ] **Anti-fatiga de alertas:** agrupación por dispositivo, `for:` y silencios en las ventanas de mantenimiento.
  - 🤖 `devops-observabilidad` · Sonnet 5
- [ ] **Health checks reales:** comprueban la base de datos y Redis, no solo que el proceso vive.
  - 🤖 `devops-observabilidad` · Sonnet 5
- [ ] **Paquete de diagnóstico:** anonimizado por defecto y suficiente para resolver sin pedir una segunda ronda de información.
  - 🤖 `producto-licencia` · Opus 5.5

---

## Fase 6 — Seguridad y cumplimiento

### Automatizado

- [ ] **Dependencias:** `composer audit` y `npm audit` en las tres SPA y en `web-kit`, sin vulnerabilidades altas ni críticas.
  - 🤖 `devops-observabilidad` · Sonnet 5
- [ ] **Imágenes:** Trivy sin CVE críticos, y las imágenes corren sin root.
  - 🤖 `devops-observabilidad` · Sonnet 5
- [ ] **Secretos:** `gitleaks` sobre todo el historial, sin secretos en imágenes ni en los logs del pipeline.
  - 🤖 `devops-observabilidad` · Sonnet 5
- [ ] **SAST y DAST:** Semgrep, y ZAP baseline contra staging.
  - 🤖 `devops-observabilidad` · Sonnet 5

### Revisión STRIDE

Todos los de este bloque: 🤖 `seguridad-cumplimiento` · Opus 5.5 → corrige: el agente dueño del código.

- [ ] **Suplantación:** HMAC verificado con `hash_equals`, token de quiosco vinculado a su centro y ámbitos de Sanctum comprobados **además** del rol.
- [ ] **Manipulación:** el usuario de BD no tiene `UPDATE` ni `DELETE` sobre `audit_log`, y la cadena de hash se calcula sobre JSON canónico.
- [ ] **Repudio:** consta el actor, el momento, el valor anterior y el motivo, y `scan_events` registra también los intentos rechazados.
- [ ] **Divulgación:**
  - el padrón de la tablet es mínimo y va cifrado;
  - los errores no distinguen «no existe» de «revocado»;
  - no se envían campos de más a cada rol.
- [ ] **Denegación:**
  - rate limiting en tres capas;
  - dos zonas de Nginx para `/scan*` (600 r/m desde `KIOSK_VLAN_CIDR` y 30 r/m desde el resto);
  - el modo offline cubre la caída del servidor.
- [ ] **Configuración:**
  - cabeceras completas;
  - CSP sin `unsafe-inline`;
  - `Permissions-Policy: camera=(self)`;
  - `APP_DEBUG=false`;
  - `/metrics` y Grafana no expuestos.

### Autorización

- [ ] **Pruebas negativas:** cada endpoint tiene una por cada rol no autorizado (403 y entrada en auditoría), y el token de quiosco se rechaza en los endpoints de gestión.
  - 🤖 `qa-testing` · Opus 5.5
- [ ] **Portal:**
  - bloqueo creciente del PIN;
  - mensajes que no distinguen código inexistente de PIN incorrecto;
  - **IDOR probado**: manipulando la URL no se llega a datos de otro empleado.
  - 🤖 `frontend-portal-empleado` · Sonnet 5 + `qa-testing` · Opus 5.5
- [ ] **Accesos a datos de terceros desde el panel:** quedan registrados en auditoría.
  - 🤖 `frontend-panel` · Sonnet 5 + `backend-laravel` · Opus 5.5

### RGPD y registro horario (art. 34.9 ET)

Todos los de este bloque: 🤖 `seguridad-cumplimiento` · Opus 5.5.

- [ ] **Minimización:** cada campo es necesario y ningún tratamiento amplía la finalidad.
- [ ] **Retención y conservación:**
  - cada dato tiene su política de purga;
  - la purga respeta los **4 años** de conservación;
  - la exportación para Inspección es completa y coherente.
- [ ] **Inalterabilidad y fiabilidad:**
  - toda corrección está versionada y trazada;
  - ningún camino registra una hora que no ocurrió.
- [ ] **Cero biometría** (ADR-009).
- [ ] **Sin comunicaciones a terceros:** ni fuentes desde CDN ni telemetría en el portal.
- [ ] **Licencia caducada** (ADR-019): **no bloquea** el fichaje ni el acceso a los registros.
  - verifica: 🤖 `seguridad-cumplimiento` · Opus 5.5
  - prueba: 🤖 `producto-licencia` · Opus 5.5
- [ ] 👤 **Validación jurídica** con la asesoría o el DPO del cliente. Queda fuera del alcance de los agentes.

---

## Fase 7 — Rendimiento

- [ ] **Carga del cambio de turno:** prueba con k6 simulando el turno de las 06:00 con 500 empleados, cumpliendo RNF-P-06.
  - 🤖 `qa-testing` · Opus 5.5
- [ ] **Rate limiting bajo carga:** el límite de Nginx no frena a los quioscos que salen por la misma IP.
  - 🤖 `devops-observabilidad` · Sonnet 5
- [ ] **PostgreSQL:**
  - `EXPLAIN` sobre las consultas críticas;
  - índices parciales y de exclusión;
  - ninguna consulta sin índice sobre tablas que crecerán.
  - 🤖 `backend-laravel` · Opus 5.5
- [ ] **Quiosco:**
  - ≤ 250 KB de JS crítico gzip;
  - LCP ≤ 2 s;
  - confirmación en < 300 ms;
  - **prueba de resistencia de 8 h** sin fuga de cámara ni de memoria.
  - 🤖 `frontend-quiosco` · Sonnet 5
- [ ] **Panel:** tablas virtualizadas con 500 empleados e informes pesados asíncronos.
  - 🤖 `frontend-panel` · Sonnet 5
- [ ] **Portal:** bundle pequeño, pensado para datos móviles.
  - 🤖 `frontend-portal-empleado` · Sonnet 5

---

## Fase 8 — Datos y base de datos

- [ ] **Migraciones:**
  - todas tienen su `down()` verificado;
  - siguen el patrón expand/contract;
  - usan `CREATE INDEX CONCURRENTLY` y un `lock_timeout` bajo.
  - 🤖 `backend-laravel` · Opus 5.5
- [ ] **Invariantes en el esquema:** RN-01 y RN-02 están declaradas en la migración (`btree_gist`), no solo en PHP.
  - 🤖 `backend-laravel` · Opus 5.5
- [ ] **Backups:**
  - cifrados y con destino en la UE;
  - WAL archiving con RPO ≤ 15 min;
  - verificación automática.
  - 🤖 `devops-observabilidad` · Sonnet 5
- [ ] **Simulacro de restauración** en un contenedor limpio, con RTO ≤ 4 h.
  - 🤖 `devops-observabilidad` · Sonnet 5

---

## Fase 9 — Despliegue y productización

- [ ] **Pipeline:** por etapas según el doc. 02 §10.1, y falla si algo falla.
  - 🤖 `devops-observabilidad` · Sonnet 5
- [ ] **Despliegue sin parada:** health checks reales y vuelta atrás probada.
  - 🤖 `devops-observabilidad` · Sonnet 5
- [ ] **`install.sh`:**
  - es idempotente;
  - comprueba los requisitos antes de tocar nada;
  - sus códigos de salida están documentados.
  - 🤖 `producto-licencia` · Opus 5.5
- [ ] **`update.sh`:**
  - la copia previa verificada es un paso bloqueante;
  - soporta el salto entre versiones **no consecutivas**;
  - vuelve atrás automáticamente si algo falla.
  - 🤖 `producto-licencia` · Opus 5.5
- [ ] **Licencia:** se verifica en local, sin internet.
  - 🤖 `producto-licencia` · Opus 5.5
- [ ] **Marca blanca:**
  - aplicada en las tres apps y en los PDF, sin tocar código por cliente;
  - los tokens se pueden sobreescribir en tiempo de ejecución.
  - 🤖 `producto-licencia` · Opus 5.5 + `ui-ux` · Sonnet 5
- [ ] **Quiosco en producción:**
  - la vinculación se hace por código;
  - el service worker aplaza las actualizaciones fuera del cambio de turno.
  - 🤖 `frontend-quiosco` · Sonnet 5

---

## Fase 10 — Documentación

- [ ] **Documentación del cliente** (instalación, operación, configuración y obligaciones legales):
  - comandos copiables;
  - sección «qué hacer si…» para cada fallo previsible.
  - 🤖 `producto-licencia` · Opus 5.5
- [ ] **Runbooks:** uno por cada alerta.
  - 🤖 `devops-observabilidad` · Sonnet 5
- [ ] **`KIOSK_VLAN_CIDR`:** documentado en `.env.example` y en `docs/cliente/instalacion.md`.
  - 🤖 `devops-observabilidad` · Sonnet 5
- [ ] **ADR al día:** ninguna decisión estructural sin su ADR.
  - 🤖 `arquitecto-dominio` · Opus 5.5
- [ ] **Contrato:** `openapi.yaml` coincide exactamente con la implementación.
  - 🤖 `backend-laravel` · Opus 5.5

---

## Fase 11 — Accesibilidad y UX

- [ ] **Contraste:** medido en todas las parejas de tokens, con una **prueba automatizada** en `web-kit`.
  - 🤖 `ui-ux` · Sonnet 5
- [ ] **axe:** `@axe-core/playwright` sin violaciones críticas ni graves en las tres apps.
  - 🤖 `qa-testing` · Opus 5.5
- [ ] **Quiosco:**
  - objetivos táctiles ≥ 48 px y texto ≥ 24 px en las confirmaciones;
  - feedback por color, texto **y** sonido;
  - aviso de privacidad visible (RF-KI-09).
  - 🤖 `frontend-quiosco` · Sonnet 5
- [ ] **Panel:**
  - navegación completa por teclado;
  - tablas con encabezados asociados y regiones vivas;
  - gráficos con tabla de datos alternativa.
  - 🤖 `frontend-panel` · Sonnet 5
- [ ] **Portal:** diseño priorizando el móvil, objetivos ≥ 48 px y resumen legible arriba.
  - 🤖 `frontend-portal-empleado` · Sonnet 5
- [ ] **Idiomas:** i18n completo en ES y EN, sin literales en los componentes.
  - 🤖 cada agente frontend · Sonnet 5
- [ ] **Coherencia visual** entre las tres SPA y `prefers-reduced-motion` respetado.
  - 🤖 `ui-ux` · Sonnet 5
- [ ] 👤 **Prueba en dispositivo real:**
  - tablet Android montada en la pared;
  - uso con guantes;
  - cocina ruidosa y recepción a oscuras;
  - batería baja.

---

## Fase 12 — Revisión final

- [ ] **Revisión del diff completo de la release** contra la Definición de Terminado (doc. 02 §10.3).
  - 🤖 `revisor-codigo` · Opus 5.5 → corrige: el agente dueño del código
- [ ] **Pasada final de seguridad y cumplimiento** sobre lo corregido en las fases anteriores.
  - 🤖 `seguridad-cumplimiento` · Opus 5.5
- [ ] **Re-revisión** de cada hallazgo corregido por el mismo agente que lo detectó.

---

## ✅ Criterios de salida

- [ ] `qa:traceability --check` en verde: ningún requisito implementado sin prueba.
- [ ] `make quality` y `make test` en verde, y las tres SPA con build correcto.
- [ ] Umbrales cumplidos: cobertura (90 / 75 / 70 %), MSI ≥ 80 % y unitarias < 2 s.
- [ ] **0 hallazgos 🔴 CRÍTICO ni 🟠 ALTO** de `seguridad-cumplimiento`.
- [ ] **0 hallazgos 🔴 BLOQUEANTE** de `revisor-codigo`.
- [ ] `projection_divergence_total` y `audit_chain_verification_failures_total` a cero en staging.
- [ ] Backup restaurado y rollback de despliegue probados.
- [ ] Instalación limpia, actualización desde la versión anterior y `doctor.sh` en verde.
- [ ] 👤 Prueba en tablet real superada.
- [ ] 👤 Validación legal del cliente o de su DPO (fuera del alcance técnico).

---

## Registro de hallazgos

| # | Fase | Descripción | Severidad | Detecta | Corrige | Estado |
|---|------|-------------|-----------|---------|---------|--------|
| 1 |  |  |  | `agente` | `agente` | Abierto / Corregido / Re-revisado |
