# Fase 3 — Operación y refuerzo · Plan ejecutable

| Campo | Valor |
|---|---|
| **Fase** | 3 — Operación y refuerzo |
| **Horas** | **84–112 h** ([doc 02 §11](../docs/02-stack-tecnologico-y-plan-implementacion.md), tabla de Fase 3, con la tarea 3.5 reestimada a 8–10 h por ADR-024) |
| **Orden de ejecución** | **Última de las fases de desarrollo.** El orden real es **0 → 1 → 2 → 5 → 3 → 4** (doc 02 §11) |
| **Por qué este fichero es el `06`** | Los ficheros de este plan van en **orden de ejecución**, no de numeración de fase. La Fase 5 (Productización) se ejecuta antes que la 3, así que ocupa el `05` y la Fase 3 ocupa el `06`. El doc 02 §11 lo justifica: «La Fase 5 se numeró después pero se ejecuta antes que la 3, porque un producto instalable con registro legalmente defendible ya es vendible aunque la observabilidad avanzada llegue después.» El doc 03 §2.2 lo repite: «el orden de ejecución es 0 → 1 → 2 → 5 → 3 → 4, así que la Fase 3 y sus tareas 3.1 a 3.12 van al final» |
| **Tareas** | 13 (3.1 a 3.13) |
| **Documento origen** | [`../docs/02-stack-tecnologico-y-plan-implementacion.md`](../docs/02-stack-tecnologico-y-plan-implementacion.md) §11, tabla «Fase 3 — Operación y refuerzo · 84–112 h» |
| **Requisitos** | Anexo A de [`../docs/01-especificaciones-proyecto.md`](../docs/01-especificaciones-proyecto.md) |
| **Precondición de fase** | Fases 0, 1, 2 y **5** cerradas (doc 02 §11, orden de ejecución) |

**Consecuencia práctica de ejecutarse al final.** El perfil de cumplimiento existe ya (tarea 5.2), la configuración con ámbito existe ya (5.1), el instalador y el actualizador existen ya (5.4, 5.7) y `error_events` existe ya (5.12). Varias tareas de esta fase se apoyan en eso, y lo hacen explícito en sus precondiciones.

---

## Índice de tareas

| # | Tarea | h | Agente / Skill |
|---|---|---|---|
| [3.1](#tarea-31--opentelemetry-extremo-a-extremo-prometheus-grafana-loki) | OpenTelemetry extremo a extremo, Prometheus, Grafana, Loki | 12–16 | `devops-observabilidad` |
| [3.2](#tarea-32--los-4-cuadros-de-mando-y-el-catálogo-de-alertas-con-runbooks) | Los 4 cuadros de mando y el catálogo de alertas con runbooks | 8–10 | `devops-observabilidad` |
| [3.3](#tarea-33--panel-de-salud-de-quioscos-y-pantalla-de-diagnóstico) | Panel de salud de quioscos y pantalla de diagnóstico | 6–8 | `frontend-panel` + `frontend-quiosco` |
| [3.4](#tarea-34--vista-de-cumplimiento-descansos-jornada-máxima-exceso-semanal) | Vista de cumplimiento: descansos, jornada máxima, exceso semanal | 8–10 | `backend-laravel` + `frontend-panel` |
| [3.5](#tarea-35--fichaje-de-pausa-y-validación-de-desfase-de-reloj) | Fichaje de pausa y validación de desfase de reloj | 8–10 | `arquitecto-dominio` → `backend-laravel` |
| [3.6](#tarea-36--pruebas-de-carga-k6-y-ajuste-de-rendimiento) | Pruebas de carga k6 y ajuste de rendimiento | 4–6 | `qa-testing` + `devops-observabilidad` |
| [3.7](#tarea-37--e2e-con-cámara-simulada-y-suite-de-accesibilidad) | E2E con cámara simulada y suite de accesibilidad | 6–8 | `qa-testing` |
| [3.8](#tarea-38--revisión-de-seguridad-externa-y-corrección-de-hallazgos) | Revisión de seguridad externa y corrección de hallazgos | 8–12 | `seguridad-cumplimiento` (preparación y corrección) |
| [3.9](#tarea-39--informes-asíncronos-con-enlace-de-descarga-caducable-y-exportación-configurable-para-nómina) | Informes asíncronos con enlace de descarga caducable y exportación configurable para nómina | 6–8 | `backend-laravel` + `/informe-nuevo` |
| [3.10](#tarea-310--registro-de-ausencias) | Registro de ausencias | 3–4 | `backend-laravel` + `frontend-panel` |
| [3.11](#tarea-311--detección-de-patrones-anómalos-de-uso-de-credencial-con-incidencia-y-bandeja) | Detección de patrones anómalos de uso de credencial, con incidencia y bandeja | 5–7 | `backend-laravel`, revisión de `seguridad-cumplimiento` |
| [3.12](#tarea-312--resumen-semanal-por-correo-y-ventana-controlada-de-actualización-del-quiosco) | Resumen semanal por correo y ventana controlada de actualización del quiosco | 4–5 | `backend-laravel` + `frontend-quiosco` |
| [3.13](#tarea-313--cuadro-de-impacto-y-adopción-proyección-de-los-indicadores-del-13-comparación-entre-periodos-pantalla-y-exportación) | Cuadro de impacto y adopción: proyección de los indicadores del §1.3, comparación entre periodos, pantalla y exportación | 6–8 | `backend-laravel` + `frontend-panel` + `/informe-nuevo` |

---

## Requisitos que cubre la fase

Del **Anexo A del doc 01**, literal:

> **Fase 3 — Operación y refuerzo** | RF-PA-06..07, RF-KI-07..08, RF-AT-10, RF-AT-12, RF-IN-06..08, **RF-GP-04**, RF-PR-05..06, **RN-16**, **§9 completo**, RS-11

«§9 completo» son las cuatro subsecciones de observabilidad del doc 01: §9.1 métricas técnicas, §9.2 métricas de negocio, §9.3 alertas mínimas y §9.4 trazabilidad y logs.

> **Dos correcciones sobre la cita del Anexo A**, para que el encabezado —que es lo primero que lee un agente— coincida con la cobertura real de las tareas:
>
> - **`RF-GP-04`, no `RF-GP-04..05`.** `RF-GP-05` (importación masiva de plantilla) **se movió a la tarea 5.5** por la resolución C-1: la Fase 5 se ejecuta antes que la 3 y el doc 05 §10.2 promete la carga de plantilla como paso de la puesta en marcha. Aquí queda solo el registro de ausencias (tarea 3.10).
> - **`RN-16` sí es de esta fase.** El Anexo A se la asigna y la implementa la tarea **3.11** (secuencia imposible de credencial, umbral `ATTENDANCE_MIN_TRANSIT_SECONDS`), pero no aparecía en la cita.

## Agentes protagonistas

Del **doc 03 §2.2**, literal:

> **Fase 3 — Operación y refuerzo** | `devops-observabilidad` y `qa-testing` en la instrumentación y las pruebas; `backend-laravel` y `frontend-panel` en las tareas 3.9 a 3.12 (informes asíncronos, ausencias e importación, patrones anómalos, resumen semanal)

## Nota sobre las tareas 3.9 a 3.12 — no son recortables sin corregir antes el documento 05

Del **doc 02 §11**, nota tras la tabla de Fase 3, literal:

> **Las tareas 3.9 a 3.12 estaban comprometidas en el documento 05 y no tenían tarea asignada.** Son funcionalidades que el documento comercial presenta como parte del producto —informes en segundo plano, salida a nómina, importación de plantilla, registro de ausencias, resumen semanal y detección de patrones anómalos—, así que o tienen fase o no se pueden vender. La 3.11 es además la contrapartida explícita de haber descartado la biometría (ADR-009).

Y del **doc 02 §11.2**, fila de recorte de la Fase 3:

> **Aviso:** las tareas 3.9 a 3.12 están comprometidas en el documento de presentación al cliente; recortarlas obliga a corregir antes ese documento, no a callarlo

---

## Añadido a la Definición de Terminado de **todas** las tareas de esta fase

[ADR-023](../docs/adr/ADR-023-frontera-registro-legal-y-funcionalidad-accesoria.md) obliga a que **toda funcionalidad nueva de la Fase 3 o posterior se clasifique** como degradable o no degradable y se cablee al punto único de decisión. La casilla siguiente se añade al «Terminado cuando» de cada una de las 13 tareas de este fichero, sin excepción:

- [ ] **Funcionalidad clasificada como degradable o no degradable según [ADR-023](../docs/adr/ADR-023-frontera-registro-legal-y-funcionalidad-accesoria.md) y cableada al `FeatureGate` único**, con la prueba de arquitectura que el ADR exige: **ninguna comprobación de licencia fuera de ese punto**.

Tres reglas que gobiernan la casilla:

1. **Lo no clasificado es no degradable por defecto.** Ante la duda gana el registro. No clasificar no es una omisión inocua: es una decisión implícita.
2. **El ADR ya nombra explícitamente a 3.9, 3.12 y 3.13** en su lista de degradables —informes avanzados, exportación para nómina, resumen semanal por correo y cuadro de impacto—, así que en esas tres la clasificación no está abierta a discusión, solo el cableado.
3. **El punto único de decisión existe desde la tarea 5.3**, que se ejecuta antes que esta fase y que además retrofita el `FeatureGate` sobre 2.4 y 2.8. Aquí no hay que construirlo: hay que usarlo.

> **Y una que no es negociable:** nada del registro legal se clasifica como degradable, ni siquiera «solo un poco». El primer conjunto de la lista de ADR-023 **no aparece en el campo `features`**, de modo que no existe forma de expresar su desactivación. Si una tarea de esta fase necesita añadir una clave nueva a `features`, esa clave no puede tocar el fichaje, su consulta ni su exportación.

---

## Las tareas, desarrolladas

### Tarea 3.1 — OpenTelemetry extremo a extremo, Prometheus, Grafana, Loki

| | |
|---|---|
| **Horas** | 12–16 |
| **Agente / Skill** | `devops-observabilidad` |
| **Requisitos** | §8 del doc 02 (columna literal). Por el Anexo A del doc 01, el **§9 completo** del doc 01: §9.1 métricas técnicas, §9.2 métricas de negocio, §9.4 trazabilidad y logs. Alimenta RF-PD-15 (histórico de errores, ya implementado en 5.12) |
| **Precondiciones** | Fases 0, 1, 2 y 5 cerradas (orden de ejecución del §11). El *stack* de Compose con `prometheus`, `grafana` y `loki` existe desde **0.1** (doc 02 §3.4, lista de servicios de desarrollo) |
| **Bloquea a** | **3.2** (sin métricas expuestas no hay cuadro de mando ni alerta que evaluar: el §8.4 implementa el catálogo sobre las métricas del §8.2) y **3.13** (las métricas de adopción del §8.2 alimentan RF-IN-08) |

**Objetivo.** Toda la instrumentación del §8.1 está en marcha: métricas de Prometheus en `/metrics` restringido a red interna, trazas OTLP que van desde el `fetch` del navegador del quiosco hasta la consulta SQL, logs JSON en Loki con correlación, y los tres registros del sistema con su retención diferenciada.

**Reglas duras aplicables.**

- **21** — **nunca nombres de empleados en logs técnicos ni en `error_events`**: se usa `employee_uuid`. El histórico de errores viaja al fabricante dentro del paquete de diagnóstico: si lleva PII, se ha filtrado.
- **16** — nada de esta instrumentación abre acceso del fabricante a los datos del cliente (ADR-020). Loki y Grafana viven en el servidor del cliente (doc 02 §1.4: «Observabilidad (en el mismo servidor)»).
- **6** — `audit_log` es un registro **aparte** de los logs técnicos: distinta retención, distinto propósito, valor probatorio (§8.1). No se mezclan.
- **13** — el *endpoint* OTLP, la retención y los destinos son configuración (`OTEL_EXPORTER_OTLP_ENDPOINT` del Anexo B), nunca código.
- **15** — la instrumentación no puede introducir un camino en el que el fichaje falle porque el exportador no responda.

**Restricción de exposición.** `/metrics` va **restringido a red interna** (§8.1 y Anexo B del doc 01: `GET /metrics  Métricas Prometheus  [red interna]`). Grafana no se expone a internet sin autenticación. Es una regla de conducta de `devops-observabilidad`, no una recomendación.

**Métricas del §8.2 del doc 02, literales y con sus tipos.**

```
# Técnicas
http_request_duration_seconds{route,method,status}       histogram
http_requests_total{route,method,status}                 counter
queue_jobs_pending{queue}                                gauge
queue_job_duration_seconds{job}                          histogram
queue_jobs_failed_total{job}                             counter
db_query_duration_seconds{operation}                     histogram
websocket_connections_active                             gauge

# De negocio — las que de verdad importan aquí
scans_total{device,result}                               counter
scan_processing_duration_seconds                         histogram
open_shifts_current{site,department}                     gauge
kiosk_last_seen_seconds{device}                          gauge
kiosk_offline_queue_size{device}                         gauge
sync_delay_seconds{device}                               histogram
incidents_open{type,severity}                            gauge
manual_corrections_total{reason_code}                    counter
anomalous_patterns_detected_total{pattern}               counter

# Impacto y adopción — alimentan RF-IN-08
scans_by_origin_total{origin}                            counter
workdays_complete_ratio{site}                            gauge
incident_resolution_seconds{type}                        histogram
application_errors_total{source,level}                   counter
projection_divergence_total                              counter
audit_chain_verification_failures_total                  counter
worked_minutes_total{site,department}                     counter

# Credenciales y respaldo
employees_without_delivered_credential{site}             gauge
credentials_pending_print{site}                          gauge
pin_fallback_scans_total{site}                           counter
```

Con las tres lecturas que el propio §8.2 añade y que hay que respetar:

- `projection_divergence_total` y `audit_chain_verification_failures_total` **deben permanecer siempre en cero**. Cualquier incremento es un incidente de integridad, no una métrica de tendencia.
- `employees_without_delivered_credential` es la métrica operativa de la entrega: cuenta a quienes están de alta pero **todavía no pueden fichar**. Debe llegar a cero antes del primer día de cada incorporación.
- Una subida de `pin_fallback_scans_total` indica un problema con la emisión, el estado de las tarjetas o la disciplina de la plantilla. Es un termómetro barato.

**Los tres registros del sistema (§8.2.1 del doc 02), y por qué son tres.**

| Registro | Dónde | Retención | Para qué | Quién lo lee |
|---|---|---|---|---|
| **Log técnico** | Monolog JSON → Loki | **90 días** | Depurar con detalle y contexto de una petición concreta | Desarrollo, con el stack de observabilidad delante |
| **`error_events`** | PostgreSQL (RF-PD-15) | **90 días** | Que el cliente vea **qué está fallando** y desde cuándo, sin conocer el sistema | IT del cliente, desde el panel |
| **`audit_log`** | PostgreSQL, solo-append encadenado | **4 años** | Valor probatorio ante una inspección | Auditor, Inspección, RRHH |

- **Por qué `error_events` no es redundante con Loki:** Loki es opcional en la instalación de un cliente —puede desactivarlo, puede no tener quien lo mire, puede perderlo al reinstalar— y el fabricante no puede entrar a consultarlo (ADR-020). La tabla vive en la misma base de datos que se respalda a diario y viaja en el paquete de diagnóstico.
- **Por qué se agrupa por huella:** un fallo en el endpoint de fichaje durante un cambio de turno genera cientos de errores idénticos; sin agrupación el error importante queda enterrado. La huella es el hash de clase de excepción, punto de fallo y mensaje normalizado —sin identificadores variables—, y cada repetición incrementa `occurrences` y actualiza `last_seen_at`.
- **Qué no puede contener:** nombres, correos, DNI ni horas de fichaje de nadie. El contexto se limita a `trace_id`, `employee_uuid`, `device_id` y datos técnicos.

**Pasos.**

1. Exponer `/metrics` con `promphp/prometheus_client_php` (§8.1) y restringirlo a red interna en Nginx. Verificar que sigue en pie la cabecera `Permissions-Policy: camera=(self)` del §7.2, sin la cual la PWA del quiosco no puede escanear.
2. Registrar **todas** las métricas del §8.2, con su tipo, y cablearlas a los puntos que ya las producen: fichaje (1.4, 1.7), correcciones (2.3), incidencias (2.6), reconciliación (2.7), cadena de auditoría (2.2), credenciales (1.10, 2.12), colas de Horizon y consultas de base de datos.
3. Trazas con **OpenTelemetry PHP** y exportador OTLP (§3.1), propagando el `trace_id` en cabecera **desde el `fetch` del navegador del quiosco hasta la consulta SQL** (§8.1). Instrumentar también el worker de colas y el Scheduler.
4. Correlación del `scan_id` del cliente en toda la traza (doc 01 §9.4), «para poder responder a "el empleado dice que fichó a las 07:02"».
5. Logs con **Monolog en JSON → Loki**, con `trace_id`, `scan_id`, `device_id` y `employee_uuid`, **nunca nombres en claro** (§8.1, doc 01 §9.4, regla dura 21).
6. Sonda interna de *uptime* sobre `/api/v1/health` (§8.1). Comprobar que `/api/v1/health` y `/api/v1/ready` (Anexo B) validan **base de datos y Redis**, no solo que el proceso vive, y que exponen la versión desplegada (§10.5: «La versión desplegada es visible en `/api/v1/health` y en la pantalla de diagnóstico del quiosco, para poder correlacionar un incidente con una versión concreta»).
7. Retención en Loki a 90 días y verificación de que la purga de `error_events` a 90 días de 5.12 sigue operativa (`ERROR_HISTORY_RETENTION_DAYS=90`).
8. Presupuesto de coste en el quiosco: la instrumentación del navegador no puede comerse el presupuesto del Anexo A del doc 02 (JS crítico ≤ 250 KB gzip, memoria ≤ 250 MB en 12 h sin crecimiento sostenido).
9. Configuración versionada en `infra/observability/` con los *scrape configs* y la retención.

**Artefactos.**

- `infra/observability/` — Prometheus, Loki, Grafana, Alertmanager.
- `infra/docker/nginx/` — restricción de `/metrics` a red interna y cabeceras del §7.2.
- `infra/compose.dev.yaml`, `infra/compose.prod.yaml`.
- `backend/app/Modules/Shared/…` — registro de métricas y contexto de log. **⚠️ No cubierto por los documentos — decidir** la ubicación exacta: el árbol del §2 no asigna carpeta a la instrumentación transversal.
- `frontend-kiosk/src/shared/api/` — propagación del `trace_id` en el `fetch`.
- `.env.example` — `OTEL_EXPORTER_OTLP_ENDPOINT`.

**Pruebas exigidas.** §9.5: `/metrics` es un **endpoint** → **Feature + Contrato** y **autorización negativa**. El resto no tiene fila propia: se verifica con los umbrales del §9.2 y con pruebas de no-regresión de privacidad.

- Feature: `/metrics` responde desde la red interna y **no** desde fuera → `->group('RS-09')`.
- Feature: `/api/v1/health` devuelve fallo si la base de datos o Redis no responden, y expone la versión → `->group('RF-PD-13')`.
- **Prueba de privacidad, obligatoria:** ningún log técnico ni fila de `error_events` contiene nombre, correo, DNI ni hora de fichaje; solo `employee_uuid`, `device_id` y contexto técnico → `->group('RF-PD-15', 'RL-08')`. Es el bloque C de `/revision-cumplimiento` y la regla dura 21.
- Feature: el `trace_id` recibido en la cabecera aparece en el log de la petición y en el `error_events` generado → `->group('RF-PD-15')`.
- Contrato: si `/metrics` y `/health` entran en `openapi.yaml`, validación con Spectator.

**Verificación.**

```bash
make up
curl -s http://localhost/metrics | grep -c '^[a-z]'          # todas las métricas del §8.2 presentes
curl -s -o /dev/null -w '%{http_code}' https://<host>/metrics # desde fuera de la red interna: 403/404
curl -s http://localhost/api/v1/health                        # BD y Redis comprobados, versión visible
php artisan test --group=RF-PD-15
# Traza completa: fichar en el quiosco y seguir el mismo trace_id en Grafana desde el fetch hasta el SQL
```

Esperado: las 27 series del §8.2 presentes; `projection_divergence_total` y `audit_chain_verification_failures_total` en 0; una consulta en Loki por `scan_id` devuelve la petición completa y **ningún nombre**.

**Terminado cuando** (§10.3, subconjunto aplicable): **instrumentación añadida: métrica, traza y log donde corresponda** · pruebas Feature y de autorización negativa de `/metrics` · trazabilidad en verde para los requisitos del §9 del doc 01 · PHPStan 9 limpio · scripts y configuración conformes al §3.5 · ningún secreto en el repositorio ni en los logs del pipeline · nada específico de un cliente · documentación de operación actualizada con los tres registros y su retención.

---

### Tarea 3.2 — Los 4 cuadros de mando y el catálogo de alertas con runbooks

| | |
|---|---|
| **Horas** | 8–10 |
| **Agente / Skill** | `devops-observabilidad` |
| **Requisitos** | §8.3 y §8.4 del doc 02 (columna literal). Por el Anexo A del doc 01, **§9.3 «Alertas mínimas»** completo |
| **Precondiciones** | **3.1** (las métricas del §8.2 tienen que existir antes de graficarlas y de evaluarlas en una regla). Los runbooks de las fases anteriores ya escritos: `rotura-cadena-auditoria.md`, `divergencia-proyeccion.md`, `restaurar-backup.md`, `rotacion-clave-qr.md`, `requerimiento-inspeccion.md` |
| **Bloquea a** | No figura dependencia en los documentos. **⚠️ No cubierto por los documentos — decidir** si 3.3 (panel de salud de quioscos) debe esperar a que exista el cuadro de operación de quioscos o puede ir en paralelo |

**Objetivo.** Existen los cuadros de mando del §8.3 versionados como código y el catálogo de alertas del §9.3 implementado, con **destinatario, umbral y enlace a runbook en cada alerta**, más las reglas anti-fatiga del §8.4.

> **Contradicción del documento origen que hay que resolver como parte de la tarea.** El nombre de la tarea en el doc 02 §11 dice «Los **4** cuadros de mando», pero la tabla del **§8.3 del mismo documento lista cinco**: Operación de quioscos, Salud de la API, Integridad del dato, Negocio, e Impacto y adopción. El quinto se añadió con RF-IN-08 y el propio §8.3 lo describe como «el cuadro que responde a *"¿esto está sirviendo para algo?"* y el que sostiene la renovación de la licencia». **Se implementan los cinco** y se corrige el nombre de la tarea en el doc 02 §11, porque arreglar la contradicción forma parte de la tarea (CLAUDE.md, orden de autoridad).

**Los cinco cuadros de mando (§8.3, literal).**

| Dashboard | Audiencia | Contenido |
|---|---|---|
| **Operación de quioscos** | Soporte / IT del cliente | Estado por dispositivo, último latido, cola pendiente, versión, escaneos por hora |
| **Salud de la API** | Desarrollo | RED por endpoint, colas, base de datos, errores |
| **Integridad del dato** | Desarrollo y cumplimiento | Divergencias, verificación de cadena, correcciones manuales, incidencias por antigüedad |
| **Negocio** | RRHH y dirección | Horas por departamento, trabajadas frente a contratadas, absentismo, impuntualidad, alertas de cumplimiento |
| **Impacto y adopción** | Dirección y el propio fabricante en la venta | Jornadas con registro completo, reparto de fichajes por origen, ratio de correcciones, tiempo hasta resolver incidencias, credenciales pendientes (RF-IN-08) |

**El catálogo de alertas (doc 01 §9.3, literal).**

| Alerta | Umbral | Severidad |
|---|---|---|
| Quiosco sin latido | > 10 min | Crítica (operaciones) |
| Tasa de error 5xx en el endpoint de fichaje | > 1 % en 5 min | Crítica |
| Latencia p95 del endpoint de fichaje | > 500 ms en 10 min | Alta |
| Cola offline de un dispositivo | > 50 elementos o > 2 h | Alta |
| Turnos abiertos > 12 h | cualquiera | Media (RRHH) |
| Divergencia en reconciliación nocturna | cualquiera | Crítica |
| Rotura de la cadena de hash de auditoría | cualquiera | Crítica (seguridad) |
| Copia de seguridad fallida o no verificada | cualquiera | Crítica |
| Certificado TLS próximo a expirar | < 21 días | Alta |
| Espacio en disco | < 20 % | Alta |
| Errores nuevos de severidad crítica en `error_events` | cualquiera en 5 min | Alta (IT del cliente) |

**Norma de diseño (§8.4, literal).** «Se implementa el catálogo del documento 01, §9.3. Norma de diseño: **cada alerta lleva destinatario, umbral y enlace a su runbook**. Una alerta sin procedimiento asociado es ruido y se elimina.»

**Reglas anti-fatiga (§8.4, literal).** «Agrupación por dispositivo, silenciamiento durante ventanas de mantenimiento declaradas, y escalado solo tras confirmar persistencia (`for: 5m`). Un único quiosco reiniciándose no debe despertar a nadie.»

**Reglas duras aplicables.**

- **21** — ninguna anotación de alerta ni panel muestra nombres de empleados; se identifica por `employee_uuid` y `device_id`.
- **16** — los cuadros viven en el servidor del cliente; el fabricante no los consulta salvo concesión expresa.
- **13** — umbrales y destinatarios son configuración de la instalación, no constantes en el repositorio.
- **7** y **6** — el cuadro de Integridad del dato es el que vigila que `projection_divergence_total` y `audit_chain_verification_failures_total` sigan en cero.

**Pasos.**

1. Provisionar Grafana **como código**, con los cinco cuadros versionados en `infra/observability/`. Regla del agente: los cuadros de mando se versionan, no se hacen a mano en la interfaz.
2. Construir cada cuadro sobre las métricas del §8.2 y las señales de negocio del doc 01 §9.2. Priorizar negocio sobre técnica: «que la CPU esté al 30 % no dice nada; que el quiosco de Recepción lleve 12 minutos sin latido a las 06:00 lo dice todo».
3. Implementar las **once reglas de alerta** del §9.3 en Prometheus/Alertmanager, cada una con: umbral literal de la tabla, severidad literal, **destinatario** y **enlace al runbook**. El §9.3 del doc 01 ya declara los once destinatarios y los once runbooks: la tabla se copia, no se decide. El criterio con el que se asignaron los cinco que faltaban:
   - **Crítica (seguridad)** → responsable de seguridad. Es un incidente, no una avería: exige preservar evidencia antes que restablecer el servicio.
   - **Crítica (operaciones) y Alta** → IT del cliente. Es quien opera la instalación y el único que puede actuar sobre servidor, red o dispositivos.
   - **Media** → RRHH. No es un fallo técnico: es trabajo de gestión sobre el registro, y avisar a IT de un turno sin cerrar sería ruido para quien no puede resolverlo.
   - **El fabricante no es destinatario de ninguna alerta**: no tiene acceso a la instalación (ADR-020) y no puede intervenir.
4. Aplicar anti-fatiga: agrupación por `device`, `for: 5m` para confirmar persistencia, y silenciamiento en las ventanas de mantenimiento declaradas — que existen porque el actualizador de 5.7 declara una.
5. Cerrar el mapa alerta → runbook con los runbooks del §12 del doc 02. Cobertura mínima que hay que garantizar:
   - Quiosco sin latido → `quiosco-no-responde.md`
   - Cola offline por encima del umbral → `cola-offline-atascada.md`
   - Divergencia en reconciliación → `divergencia-proyeccion.md` (2.7)
   - Rotura de cadena de auditoría → `rotura-cadena-auditoria.md` (2.2)
   - Copia fallida o no verificada → `restaurar-backup.md` (2.11)
   - Errores nuevos críticos en `error_events` → `errores-en-el-panel.md` (**se escribe en 5.12**, que es la tarea que crea `error_events` y su pantalla; aquí solo se enlaza desde la alerta)
   - Patrón anómalo de credencial → `patron-anomalo-credencial.md` (3.11)
   - Turnos abiertos > 12 h → `turno-abierto-prolongado.md` (se escribe en **2.6**, que es la tarea que crea la alerta)
   - Certificado TLS próximo a expirar → `renovacion-certificado-tls.md` (se escribe aquí)
   - Espacio en disco por debajo del 20 % → `espacio-en-disco.md` (se escribe aquí)

   Los tres faltaban en el §12 del doc 02 y **ya están añadidos**: el §8.4 no admite alerta sin procedimiento, así que la alternativa era eliminar tres alertas del catálogo mínimo, y ninguna de las tres es prescindible.
6. Escribir los runbooks que falten de la lista del §12 y que esta fase activa: `quiosco-no-responde.md`, `cola-offline-atascada.md`, **`renovacion-certificado-tls.md`** y **`espacio-en-disco.md`**. **`errores-en-el-panel.md` no se escribe aquí**: es de la tarea **5.12**, que se ejecuta antes que esta fase y que es la que sabe cómo se lee `error_events`. Aquí basta con que la alerta lo enlace.
7. No exponer Grafana a internet sin autenticación. Documentar el acceso en `docs/cliente/operacion.md`.
8. Prueba de fuego del diseño: para cada alerta, escribir en una frase qué haría a las 06:30 quien la reciba. Si no hay respuesta, la alerta se elimina (§8.4).

**Artefactos.**

- `infra/observability/` — cuadros de Grafana como código, reglas de Prometheus, configuración de Alertmanager con rutas por destinatario y silencios.
- `docs/runbooks/quiosco-no-responde.md`, `cola-offline-atascada.md`, `renovacion-certificado-tls.md` y `espacio-en-disco.md`. `turno-abierto-prolongado.md` es de la tarea 2.6 y `errores-en-el-panel.md` de la 5.12: aquí se enlazan desde su alerta, no se escriben.
- `docs/cliente/operacion.md` — cómo se leen los cuadros y qué hacer con cada alerta.

**Pruebas exigidas.** §9.5 no tiene fila para configuración de observabilidad. Lo verificable:

- Cada una de las once reglas dispara con datos sintéticos que cruzan su umbral, y **no** dispara justo por debajo (valores límite explícitos, doc 02 §3.5).
- Cada regla tiene anotación con destinatario y URL de runbook, y el fichero de runbook **existe**. Comprobación automatizable: un test que recorra las reglas y verifique que el fichero enlazado existe en `docs/runbooks/`.
- Anti-fatiga: un quiosco que reinicia y vuelve en menos de `for: 5m` **no** genera notificación; cinco a la vez sí.
- Silenciamiento efectivo durante una ventana de mantenimiento declarada.
- **⚠️ No cubierto por los documentos — decidir** la herramienta de validación de reglas y cuadros: el §9.2 no incluye ninguna para Prometheus/Grafana.

**Verificación.**

```bash
make up
# Reglas cargadas y sin errores de sintaxis en Prometheus; Alertmanager con las rutas por destinatario
# Forzar cada condición y comprobar la notificación:
docker compose -f infra/compose.dev.yaml stop <servicio-del-quiosco-simulado>   # > 10 min sin latido
php artisan attendance:reconcile --from=<f> --to=<f>                            # divergencia forzada
# Comprobar que cada notificación recibida enlaza a un runbook existente
```

Esperado: once alertas cargadas, cada una con destinatario y runbook existente; ninguna notificación por un reinicio aislado de un quiosco; los cinco cuadros cargan desde el repositorio sin configuración manual.

**Terminado cuando** (§10.3, subconjunto aplicable): **instrumentación y alertas añadidas, cada una con su runbook** · configuración versionada y conforme al §3.5 · documentación de cliente actualizada (operación) · Grafana no expuesto sin autenticación · nada específico de un cliente en el código · contradicción «4 vs 5 cuadros» del doc 02 §11 corregida.

---

### Tarea 3.3 — Panel de salud de quioscos y pantalla de diagnóstico

| | |
|---|---|
| **Horas** | 6–8 |
| **Agente / Skill** | `frontend-panel` + `frontend-quiosco` |
| **Requisitos** | RF-PA-07, RF-KI-08 (doc 02 §11 y Anexo A) |
| **Precondiciones** | Derivado: el latido del quiosco (`POST /api/v1/kiosk/heartbeat`, Anexo B) y la tabla `devices` con `app_version`, `last_seen_at`, `pending_queue_size`, `status` existen desde **1.7** y **1.9**; el emparejamiento por código, desde **5.6**. Las métricas `kiosk_last_seen_seconds` y `kiosk_offline_queue_size` se exponen en **3.1** |
| **Bloquea a** | No figura dependencia en los documentos |

**Objetivo.** El panel muestra la salud de cada quiosco —último latido, versión de la app, tamaño de la cola offline y nivel de batería— y la tablet tiene una pantalla de diagnóstico accesible con código de servicio que muestra estado de cámara, red, cola, token y versión.

**Reglas duras aplicables.**

- **19** — la pantalla de diagnóstico no puede convertirse en un obstáculo: se abre con código de servicio y el fichaje sigue disponible.
- **21** — el diagnóstico del quiosco no muestra ni envía nombres; identifica por `device_id` y, si hace falta, `employee_uuid`.
- **16** — lo que la pantalla de diagnóstico revela es del cliente; nada se envía al fabricante salvo dentro del paquete de diagnóstico anonimizado (5.9).
- **13** — el código de servicio y los umbrales de aviso son configuración.

**Pasos.**

1. Confirmar en `docs/api/openapi.yaml` el contrato del latido y de la consulta de dispositivos (Anexo B: `POST /api/v1/kiosk/heartbeat` y el CRUD de `/devices`). Añadir lo que falte antes del código.
2. Panel: vista por dispositivo con último latido (con la antigüedad calculada y **la zona horaria mostrada**), versión de la app, tamaño de la cola pendiente, nivel de batería y estado. Virtualización si hay muchos dispositivos.
3. Panel: resaltado del dispositivo cuyo latido supera el umbral de la alerta del §9.3 («Quiosco sin latido > 10 min, Crítica»), de modo que el panel y la alerta cuenten lo mismo.
4. Quiosco: pantalla de diagnóstico con código de servicio (RF-KI-08) que muestra estado de cámara, red, cola, token y **versión desplegada** (doc 02 §10.5).
5. Reutilizar `php artisan kiosk:health` (Anexo C: «Estado de todos los quioscos») como equivalente de consola para el IT del cliente.
6. Enlazar la vista con el runbook `quiosco-no-responde.md` (3.2): el panel es el primer paso del procedimiento.
7. i18n ES/EN y accesibilidad AA (doc 01 §6.5, y en el quiosco además: objetivos táctiles ≥ 48 px, texto ≥ 24 px en confirmaciones, contraste ≥ 4.5:1).

**Artefactos.**

- `frontend-admin/src/features/devices/`.
- `frontend-kiosk/src/features/diagnostics/`.
- `backend/app/Modules/Kiosk/Http/`, `backend/app/Modules/Kiosk/Application/Query/`.
- `docs/api/openapi.yaml`.
- `docs/runbooks/quiosco-no-responde.md` (enlace desde la vista).

**Pruebas exigidas.** §9.5: expone/consume **endpoint** → **Feature + Contrato** y **autorización negativa por rol**; tiene **recorrido de usuario** en panel y quiosco → **E2E**.

- Feature + Contrato de la consulta de dispositivos y del latido → `->group('RF-PA-07')`.
- Autorización negativa: el rol `empleado` no accede a la salud de quioscos; un token de quiosco no puede leer la lista de dispositivos → `->group('RS-04', 'RF-ID-03')`.
- E2E panel: un quiosco sin latido aparece marcado → `tag: ['@RF-PA-07']`.
- E2E quiosco: la pantalla de diagnóstico se abre con el código de servicio y muestra cámara, red, cola, token y versión → `tag: ['@RF-KI-08']`.
- Accesibilidad con `@axe-core/playwright`: 0 violaciones críticas o graves.
- Vitest de los componentes (≥ 70 %, §9.2).

**Verificación.**

```bash
php artisan kiosk:health
php artisan test tests/Feature/Kiosk tests/Contract
make e2e -- --grep "@RF-PA-07|@RF-KI-08"
npx vue-tsc --noEmit -p frontend-admin && npx vue-tsc --noEmit -p frontend-kiosk
```

Esperado: la antigüedad del latido que muestra el panel coincide con `kiosk_last_seen_seconds`; la pantalla de diagnóstico funciona sin red y no revela el token en claro.

**Terminado cuando** (§10.3): pruebas Feature, Contrato, autorización negativa y E2E en verde · trazabilidad en verde · convenciones del §3.5 verificadas por ESLint y `vue-tsc` · contrato OpenAPI actualizado · autorización probada en negativo · instrumentación coherente con las métricas de 3.1 · textos en ES y EN · **accesibilidad verificada** · nada específico de un cliente.

---

### Tarea 3.4 — Vista de cumplimiento: descansos, jornada máxima, exceso semanal

| | |
|---|---|
| **Horas** | 8–10 |
| **Agente / Skill** | `backend-laravel` + `frontend-panel` |
| **Requisitos** | RF-PA-06, RN-10..12 (doc 02 §11 y Anexo A). El Anexo A del doc 01 asigna RN-10..15 a la Fase 2 y RF-PA-06 a la Fase 3: las reglas existen desde la 2.6, la **vista** llega aquí |
| **Precondiciones** | **5.2** — «Perfiles de cumplimiento; extraer RN-10/11/12 a parámetros; perfil `ES-hosteleria`» (doc 02 §11, Fase 5). Al ejecutarse la Fase 5 antes de la 3, el perfil **ya existe** y los umbrales ya no son constantes. También **2.6** (detección) y **2.5** (bandeja donde aterrizan las incidencias) |
| **Bloquea a** | No figura dependencia en los documentos |

**Objetivo.** El responsable ve, para su ámbito, las alertas de cumplimiento: descanso insuficiente entre jornadas, jornada diaria excesiva, ausencia de pausa en jornadas largas y exceso de horas semanales. Los umbrales vienen del perfil de cumplimiento del cliente, no del código.

**Reglas duras aplicables.**

- **14** — **los umbrales legales se leen del perfil de cumplimiento**: descanso mínimo, jornada máxima, pausas y retención (RN-10/11/12). **El dominio recibe el umbral ya resuelto por un puerto; nunca consulta la configuración.** Con la Fase 5 ya ejecutada, el puerto `CompliancePolicy` se sirve de `compliance_profiles` (`min_rest_hours`, `max_daily_hours`, `max_weekly_hours`, `break_required_after_hours`, `week_starts_on`, doc 01 §5.5).
- **13** — un cliente con otro convenio cambia el perfil, no el código (ADR-017). El Gherkin del doc 01 §11 «Perfil de cumplimiento distinto» es la prueba de que esto funciona.
- **4** — el descanso entre jornadas se calcula entre el fin de un turno y el inicio del siguiente, con los turnos **sin partir** (ADR-006): partirlos rompería el cálculo, que es justamente el motivo del ADR.
- **3** — la semana empieza donde diga `week_starts_on` y se resuelve en la zona del centro.
- **18** — el endpoint de la vista lleva policy y prueba negativa por rol.
- **1** y **2** — la aritmética de descansos vive en el dominio con el reloj inyectado.

**Pasos.**

1. **Contrato primero.** El endpoint es **`GET /api/v1/compliance/summary`**, con filtros de periodo y ámbito y rol `manager+`. No existía en el Anexo B del doc 01 y **ya está añadido allí**; se refleja en `docs/api/openapi.yaml` antes de escribir código (ADR-013: el contrato se modifica antes que el código, porque de él se generan los clientes de los tres frontends).
2. Consulta de lectura en `Reporting` (doc 01 §5.1 sitúa `ComplianceView` en `Reporting`) que resuelve, por empleado y periodo: descanso entre jornadas consecutivas con `tstzrange` y sus operadores (doc 02 §3.2: «Solapes, huecos entre turnos y descanso entre jornadas (RN-10)»), jornada diaria efectiva, tramos continuos sin pausa y total semanal.
3. Umbrales recibidos por el puerto `CompliancePolicy`, con el perfil resuelto por centro (`sites.compliance_profile_id`, doc 01 §5.5).
4. Coherencia con la detección de 2.6: la vista y la bandeja de incidencias deben contar lo mismo. Los tipos `insufficient_rest` y `long_shift` ya existen en `incidents.type`.
5. Panel: vista por departamento y periodo, con el umbral aplicado **visible** («descanso mínimo 12 h según perfil ES-hosteleria»), porque un aviso cuyo criterio no se ve es un aviso que nadie defiende ante un empleado.
6. Sin redondeos que hagan que las partes no sumen el total; zonas horarias mostradas, no adivinadas (principios de `frontend-panel`).
7. Alimentar el cuadro de mando de **Negocio** del §8.3 con las alertas de cumplimiento.
8. i18n ES/EN y accesibilidad AA.

**Artefactos.**

- `backend/app/Modules/Reporting/Application/Query/` — consulta de cumplimiento.
- `backend/app/Modules/Attendance/Domain/Policy/` — políticas de RN-10/11/12 (si no quedaron cerradas en 2.6).
- `backend/app/Modules/Attendance/Infrastructure/Adapter/DbCompliancePolicyProvider` (doc 02 §1.5).
- `frontend-admin/src/features/…` — vista de cumplimiento. **⚠️ No cubierto por los documentos — decidir** el nombre de la carpeta: el §2 lista `{live,workdays,incidents,reports,employees,credentials,devices,settings}` y no incluye una para cumplimiento.
- `docs/api/openapi.yaml` y Anexo B del doc 01.

**Pruebas exigidas.** §9.5: **regla de negocio** (RN-10/11/12) → **Unitaria obligatoria**; consulta con volumen → **Integración**; **endpoint** → **Feature + Contrato** y **autorización negativa por rol**; **recorrido de usuario** en el panel → **E2E**.

- Unitaria: descanso de 11 h 59 min alerta y de 12 h 00 min no, con el umbral recibido por puerto (límites explícitos) → `->group('RN-10', 'RF-PA-06')`.
- Unitaria: jornada de 9 h 01 min alerta (RN-11) y tramo continuo de 6 h 01 min sin pausa alerta (RN-12) → `->group('RN-11', 'RN-12')`.
- Unitaria: **Gherkin «Perfil de cumplimiento distinto»** del doc 01 §11 — con perfil de 10 h, dos turnos separados por 11 h no alertan; con el perfil español de 12 h, sí → `->group('RF-PD-07', 'RN-10')`.
- Unitaria: turno nocturno 22:00→06:00 seguido de otro a las 18:00 — el descanso se mide desde el fin real, sin partir el turno → `->group('RN-05', 'RN-10')`.
- Unitaria: semana con cambio de hora, con el total semanal correcto (RN-09) → `->group('RN-09')`.
- Integración: la consulta con `tstzrange` devuelve los huecos correctos con volumen realista → `->group('RN-10')`.
- Feature + Contrato del endpoint de la vista → `->group('RF-PA-06')`.
- Autorización negativa: un responsable no ve el cumplimiento de otro departamento; `empleado` recibe 403 → `->group('RF-ID-03')`.
- E2E: la vista muestra el umbral aplicado y el origen del perfil → `tag: ['@RF-PA-06']`.

**Verificación.**

```bash
make test-unit && make mutate                 # MSI ≥ 80 % en las políticas nuevas
php artisan test --group=RN-10 --group=RN-11 --group=RN-12
php artisan test tests/Feature/Reporting tests/Contract
make e2e -- --grep @RF-PA-06
```

Esperado: cambiar `min_rest_hours` en el perfil de cumplimiento altera los avisos **sin tocar una línea de código** ni reiniciar nada más que la caché de configuración; ningún literal `12` en el dominio.

**Terminado cuando** (§10.3): Deptrac en verde · unitarias, integración, feature, contrato, autorización negativa y E2E · MSI dentro de umbral · trazabilidad en verde · PHPStan 9 limpio · contrato OpenAPI actualizado (y Anexo B corregido) · autorización probada en negativo · instrumentación añadida · textos en ES y EN · **accesibilidad verificada** · **umbrales fuera del código** · nada específico de un cliente.

---

### Tarea 3.5 — Fichaje de pausa y validación de desfase de reloj

| | |
|---|---|
| **Horas** | **8–10** (reestimada desde las 4–5 del doc 02 §11) |
| **Agente / Skill** | `arquitecto-dominio` → `backend-laravel`, **con `frontend-quiosco`** (el relevo del doc 02 §11, más el cliente, que es donde está el trabajo que ADR-024 añade) |
| **Requisitos** | RF-AT-10, RF-AT-12 (doc 02 §11 y Anexo A). Regla RN-15 (el horario de un fichaje offline es el `occurred_at` del dispositivo, marcado con su retraso) y RN-12 (descanso en jornada continuada, enunciada en la tarea 2.6) |
| **Precondiciones** | Derivado: el dominio de `Attendance` (**1.1–1.2**), `RegisterScan` con idempotencia (**1.4**), los endpoints de fichaje (**1.7**), la cola offline del quiosco (**1.9**), la bandeja de incidencias (**2.5**) y la configuración con ámbito por centro (**5.1**), porque RF-AT-12 es «configurable por centro» |
| **Bloquea a** | No figura dependencia en los documentos |

**Objetivo.** El sistema admite fichaje de pausa —inicio y fin de descanso, diferenciado del fin de turno y configurable por centro— y valida el desfase de reloj del dispositivo generando incidencia `clock_skew` **sin rechazar jamás el fichaje**.

> **Por qué 8–10 h y no las 4–5 del doc 02 §11.** El propio [ADR-024](../docs/adr/ADR-024-la-pausa-son-dos-tramos.md) señala que **la intención declarada por el cliente es «la consecuencia que más trabajo añade»**, y esa consecuencia no está en el backend: está en el quiosco. La pausa y el fin de jornada son **estructuralmente idénticos** —los dos cierran el tramo abierto—, de modo que el servidor no puede deducir cuál es cuál: el quiosco tiene que ofrecer la elección, enviarla en la petición y **persistirla en la cola offline**, con todo lo que eso implica en interfaz táctil, i18n, accesibilidad y sincronización por lotes. Las 4–5 h originales cubrían el lado servidor de un cambio de enumerado. El campo `intent` ya existe en el esquema (tarea 1.3) y en el registro Dexie (tarea 1.9) precisamente para que aquí no haya que migrar una cola cargada.

**Reglas duras aplicables.**

- **19** — **el quiosco nunca bloquea al empleado**: ni por falta de red, ni por desfase de reloj, ni porque el padrón cacheado no reconozca la tarjeta. Encola siempre, confirma localmente y genera incidencia para revisión humana (RF-AT-10, RN-15). RF-AT-10 lo dice sin ambigüedad: «**Nunca se rechaza un fichaje por desfase de reloj**: hacerlo dejaría una jornada sin registrar por un problema técnico ajeno al empleado.»
- **9** — se registran ambas marcas: `occurred_at` (hora del dispositivo, la que tiene valor legal) y `recorded_at` (recepción en servidor).
- **8** — el fichaje de pausa es idempotente por `scan_id`, igual que cualquier otro.
- **1** y **2** — la decisión de qué es pausa y qué es fin de turno vive en el dominio, con el reloj inyectado.
- **7** — el total del día se recalcula tras la pausa; una pausa mal contada es una hora de más o de menos en una nómina.
- **14** — el umbral de desfase es configuración (`ATTENDANCE_MAX_CLOCK_SKEW_MINUTES=15` del Anexo B del doc 02, con la anotación literal «RF-AT-10 · genera incidencia, nunca rechaza el fichaje»), servido al dominio por puerto.

**Pasos.** Relevo `arquitecto-dominio` → `backend-laravel`, con el método del arquitecto: módulo → capa → invariantes con su `RN-*` → objetos de valor → puertos → firmas y casos de prueba → implementación.

1. **La pausa son dos tramos**, no un intervalo dentro de uno ([ADR-024](../docs/adr/ADR-024-la-pausa-son-dos-tramos.md)). Fichar la pausa cierra el `ShiftEntry` abierto; fichar la vuelta abre otro. **Cero conceptos nuevos en el dominio y ninguna columna nueva en `shift_entries`**: lo que distingue una pausa de un fin de jornada es el motivo del escaneo, no la estructura. El modelo ya lo soportaba —RN-12 enuncia la regla sobre *tramos continuos* y «jornada partida de 4 tramos» ya es escenario obligatorio del prompt de arranque del dominio (doc 03 §6.2)—, y modelarla como intervalo interno obligaría a revisar RN-01, RN-02 y la restricción `EXCLUDE USING gist`, que razona sobre `tstzrange(clocked_in_at, clocked_out_at)` y no sabe de huecos internos. Se toca la última línea de defensa del sistema para no ganar nada.
2. `arquitecto-dominio`: enunciar el efecto sobre RN-12 (descanso en jornada continuada: se alerta si un tramo continuo supera 6 h sin **pausa registrada**), que es la regla que da sentido funcional a RF-AT-12 y que la vista de 3.4 consume.
3. `arquitecto-dominio`: modelar el desfase de reloj como marca sobre el evento, no como validación que rechaza. Objeto de valor con el desfase medido, e incidencia `clock_skew` (tipo ya existente en `incidents.type`, doc 01 §5.5).
4. `qa-testing`/`backend-laravel`: pruebas que fallan antes de implementar, con los valores límite del umbral.
5. `backend-laravel`: actualizar `docs/api/openapi.yaml`. **No hay endpoint nuevo**: la pausa se resuelve en `POST /api/v1/scan`. El campo `action` de la respuesta amplía su enumerado a `clock_in`, `clock_out`, **`break_start`** y **`break_end`**, y `scan_events.result` lo refleja —su enumerado del doc 01 §5.5 (`clock_in`|`clock_out`|`rejected_unknown`|`rejected_revoked`|`rejected_debounce`|`rejected_signature`) se amplía con los dos valores nuevos, con `/migracion-segura`—. **Es un cambio aditivo y no rompe la v1**, que es lo que ADR-012 exige mientras haya quioscos en esa versión: un cliente antiguo que reciba `break_start` lo trata como valor desconocido, nunca como error. Registrado en las notas de contrato del Anexo B del doc 01.
6. `backend-laravel`: implementar la comparación de reloj contra la hora del servidor en la recepción del escaneo, con el umbral recibido por puerto, y crear la incidencia si se supera. El fichaje se acepta y **se registra con la hora del dispositivo** (RF-AT-10).
7. `backend-laravel`: aviso en el quiosco de que hay desfase (RF-AT-10: «se avisa en el quiosco»), sin bloquear ni asustar. Coordinar con `frontend-quiosco`.
8. Instrumentar: reutilizar `scans_total{device,result}` con el resultado de pausa e `incidents_open{type,severity}` para `clock_skew`. La métrica `sync_delay_seconds{device}` ya cubre el retraso de sincronización de RN-15.
9. Activación por centro mediante la configuración con ámbito de 5.1 (RF-AT-12: «configurable por centro»), con el cambio auditado si afecta al cálculo de horas (`/revision-cumplimiento`, bloque C bis).
10. **La intención viaja en la petición y la cola la persiste** ([ADR-024](../docs/adr/ADR-024-la-pausa-son-dos-tramos.md), consecuencia no opcional). `intent` (`auto`|`break_start`|`break_end`) **ya existe** en `scan_events` desde la tarea 1.3 y en el registro Dexie desde la 1.9, con `auto` por defecto. Lo que se hace aquí:
    - **El quiosco ofrece la elección** cuando hay un tramo abierto y el centro tiene la pausa activada, con los mismos criterios de accesibilidad que el resto del quiosco (objetivos ≥ 48 px, texto ≥ 24 px, operable con una mano y con guantes) y sin añadir un paso a quien solo quiere fichar la salida.
    - **`intent` se escribe al encolar, no al enviar**, junto a `occurred_at`: un fichaje de pausa encolado sin red sigue siendo de pausa cuando se sincroniza dos horas después.
    - **`/scan/batch` transporta `intent` por elemento** y el servidor lo respeta al procesar el lote ordenado por `occurred_at`.
    - **`intent` frente a `result`**: `intent` es lo que el quiosco **pide** y `result` lo que el servidor **decidió**. Son dos campos y no uno porque el servidor no puede deducir el primero (doc 01 §5.5).
11. **Revisar `ATTENDANCE_DEBOUNCE_SECONDS`** (Anexo B del doc 02), que es lo que ADR-024 pide explícitamente al introducir la pausa: el anti-rebote de RF-AT-06 existe para descartar el doble escaneo accidental, pero **una pausa corta legítima puede parecerse a un rebote**. Hay que fijar y documentar el valor a la luz del caso nuevo, y **el anti-rebote nunca puede tragarse una vuelta de pausa real**: si hay duda, se registra y se marca, nunca se descarta (regla dura 19). Como todo umbral operativo, se sirve por `OperationalSettingsProvider` y no es constante.

**Artefactos.**

- `backend/app/Modules/Attendance/Domain/Model/`, `Domain/ValueObject/`, `Domain/Policy/`.
- `backend/app/Modules/Attendance/Application/UseCase/` — ampliación de `RegisterScan`.
- `backend/database/migrations/` — cambios de esquema con `/migracion-segura` (expand / migrate / contract). **`scan_events.intent` no se crea aquí**: existe desde la tarea 1.3.
- `docs/api/openapi.yaml` — `intent` en la petición de `/scan` y de `/scan/batch`; `action` ampliado en la respuesta.
- `frontend-kiosk/src/features/scan/` — aviso de desfase, elección de pausa y feedback.
- **`frontend-kiosk/src/features/offline/`** — la cola persiste y transporta `intent`, y lo conserva a través de reintentos y lotes. Es el artefacto que ADR-024 hace inevitable y que la estimación original no contemplaba.

**Pruebas exigidas.** §9.5, fila «Es una **escritura del quiosco**»: **los cinco niveles** —Unitaria, Integración, Feature + Contrato, Autorización negativa y E2E— **más idempotencia concurrente**.

- Unitaria: umbral de 15 min → 14:59 no genera incidencia, 15:01 sí, y **en ningún caso se rechaza** → `->group('RF-AT-10', 'RN-15')`.
- Unitaria: la duración del tramo con pausa registrada es la esperada y el total del día cuadra → `->group('RF-AT-12', 'RN-06')`.
- Unitaria: un tramo continuo de 6 h 01 min **sin** pausa registrada alerta; con pausa, no (RN-12) → `->group('RN-12', 'RF-AT-12')`.
- Integración: las invariantes RN-01 y RN-02 siguen garantizadas por la base de datos con pausas de por medio → `->group('RN-01', 'RN-02')`.
- Feature + Contrato del escaneo con pausa y con desfase → `->group('RF-AT-10', 'RF-AT-12')`.
- Autorización negativa: solo un token con `scan:write` puede fichar; cualquier otro rol recibe 403 → `->group('RS-04')`.
- **Idempotencia concurrente:** 10 peticiones paralelas con el mismo `scan_id` → exactamente un evento y diez respuestas idénticas (§9.4) → `->group('RF-AT-07', 'RQ-03')`.
- **Escenario ineludible del §9.4 «Desfase de reloj»:** «Dispositivo con el reloj adelantado por encima del umbral → el fichaje **se acepta**, se registra la incidencia `clock_skew` y no se pierde ninguna jornada (RF-AT-10)» → `->group('RF-AT-10')`.
- Gherkin del doc 01 §11 «Reloj del quiosco desviado» (reloj 40 min adelantado): el fichaje se registra, se crea la incidencia y **en ningún caso se rechaza** → `->group('RF-AT-10')`.
- **Unitaria (ADR-024): turno 22:00→02:00, pausa, 02:30→06:00 = 450 min el día D y 0 min el día D+1**, con los dos tramos compartiendo `work_date`. La escribió ya la tarea 1.2 sobre el dominio; **aquí debe seguir en verde con la intención declarada de por medio** → `->group('RN-05', 'RN-12', 'RF-AT-12')`.
- **Integración: `intent` sobrevive a la cola offline.** Un `break_end` encolado sin red y sincronizado dos horas después llega al servidor como `break_end`, no como `auto` → `->group('RF-AT-12', 'RF-KI-03')`.
- **Feature: `/scan/batch` respeta el `intent` de cada elemento** del lote, procesado en orden de `occurred_at` → `->group('RF-AT-12', 'RF-AT-07')`.
- **Unitaria: el anti-rebote no descarta una vuelta de pausa legítima** con el valor de `ATTENDANCE_DEBOUNCE_SECONDS` fijado, en sus valores límite → `->group('RF-AT-06', 'RF-AT-12')`.
- **Contrato: un cliente que no envía `intent` sigue funcionando** (`auto` por defecto), que es lo que hace el cambio aditivo y compatible con la v1 (ADR-012) → `->group('RF-AT-12')`.
- E2E con cámara simulada: fichar pausa y fin de pausa, y comprobar el total → `tag: ['@RF-AT-12']`.

**Verificación.**

```bash
make test-unit && make mutate
php artisan test --group=RF-AT-10 --group=RF-AT-12
php artisan test tests/Integration/Attendance tests/Feature/Attendance tests/Contract
make e2e -- --grep "@RF-AT-12"
php artisan qa:traceability --check
```

Esperado: con el reloj del dispositivo adelantado 40 minutos, la respuesta del escaneo es de éxito, `scan_events.occurred_at` es la hora del dispositivo, existe una incidencia `clock_skew` asignada al responsable y ninguna jornada queda sin registrar.

**Terminado cuando** (§10.3): Deptrac en verde · **los cinco niveles de prueba más idempotencia concurrente** · MSI dentro de umbral · trazabilidad en verde · PHPStan 9 limpio · contrato OpenAPI actualizado · autorización probada en negativo · instrumentación añadida · `audit_log` escrito donde corresponda · **migración reversible y verificada con volumen realista** · textos en ES y EN · el modelado de la pausa ya tiene su ADR ([ADR-024](../docs/adr/ADR-024-la-pausa-son-dos-tramos.md)), y esta tarea lo **aplica**, no lo decide · `ATTENDANCE_DEBOUNCE_SECONDS` revisado y documentado a la luz de la pausa · umbral fuera del código · nada específico de un cliente · **funcionalidad clasificada según ADR-023** (no degradable: es fichaje).

---

### Tarea 3.6 — Pruebas de carga k6 y ajuste de rendimiento

| | |
|---|---|
| **Horas** | 4–6 |
| **Agente / Skill** | `qa-testing` + `devops-observabilidad` |
| **Requisitos** | RNF-P-06 (doc 02 §11). RQ-08: «Prueba de carga que valida RNF-P-06 antes de cada versión mayor» |
| **Precondiciones** | Derivado: el endpoint de fichaje con *rate limiting* (**1.7**) y la instrumentación de **3.1**, sin la cual el ajuste se hace a ciegas |
| **Bloquea a** | Publicación de versión mayor (RQ-08, §9.2: umbral bloqueante «RNF-P-06: 50 fichajes/s con p95 < 150 ms») |

**Objetivo.** Existe una prueba de carga reproducible que valida el pico del cambio de turno —**50 fichajes/segundo con p95 < 150 ms**— y los ajustes de configuración necesarios para sostenerlo están aplicados y documentados.

**Umbral, literal (§9.2 y doc 01 §6.1).** RNF-P-06: pico de concurrencia soportado **50 fichajes/segundo (cambio de turno)**; RNF-P-02: latencia del endpoint de fichaje en servidor p95 < 150 ms, p99 < 400 ms.

**Reglas duras aplicables.**

- **8** — la prueba de carga debe usar `scan_id` distintos por petición y, además, incluir reenvíos con el mismo `scan_id` para comprobar que la idempotencia no se degrada bajo carga.
- **17** — los rechazos siguen siendo genéricos y **de tiempo constante** bajo carga: si la latencia de un rechazo se separa de la de un acierto, se ha abierto un canal de enumeración (RS-03).
- **21** — la carga sintética no introduce nombres reales en los logs.
- **7** — el recálculo de `daily_totals` ocurre dentro de la transacción del fichaje: es el punto que más probable es que se convierta en el cuello de botella. La reconciliación posterior debe cuadrar (§9.4, «Cambio de turno real»).
- **19** — si el servidor se satura, el quiosco encola; la prueba debe confirmar que la degradación es esa y no un rechazo al empleado.

**Pasos.**

1. Escribir el escenario en **`load-tests/k6/scan-peak.js`** (ruta literal del árbol del §2 del doc 02).
2. Modelar el pico real, no un caso de laboratorio: el §9.4 lo describe como «30 empleados distintos fichando simultáneamente en el mismo quiosco → un tramo por persona, sin duplicados y con `daily_totals` cuadrando con los eventos origen. Es el pico que ocurre a diario».
3. Incluir en el escenario: escaneos válidos, rechazos por firma, reenvíos idempotentes y sincronización de lotes (`POST /api/v1/scan/batch`, lotes de 50 según §6).
4. Medir con las métricas de 3.1: `scan_processing_duration_seconds`, `http_request_duration_seconds{route}`, `db_query_duration_seconds{operation}`, `queue_jobs_pending{queue}`.
5. **Verificar que la reconciliación entre RNF-P-06 y el *rate limiting* del §7.1 está efectivamente aplicada** — el que fue el conflicto más serio de esta fase, y que **ya está resuelto en los documentos y en las tareas que configuran Nginx**. RNF-P-06 exige 50 fichajes/s con p95 < 150 ms, y unos 30 r/m por IP indiscriminados frenarían la prueba tres órdenes de magnitud antes de que la aplicación llegase a sudar, porque **todos los quioscos de un hotel salen por la misma IP**.

   **La resolución no cambia ningún requisito**, porque el §7.1 ya distingue las dos capas: *«Aplicación: throttling por `device_id`, por credencial y por empleado»*. Ahí está el control real del camino de fichaje.
   - En **Nginx**, la zona de fichaje son **dos**: **600 r/m con ráfaga de 50 desde `KIOSK_VLAN_CIDR`** y 30 r/m con ráfaga de 10 desde cualquier otro origen. Lo configuran ya las tareas **0.1** (paso 5) y **1.7**; aquí solo se comprueba que sigue así y se mide contra ella. Los 30 r/m por IP quedan para orígenes no internos, que es el tráfico para el que ese control se pensó: internet, no la red del hotel. **El límite interno no se elimina, se eleva** (RS-02 exige limitar también por IP).
   - En la **aplicación**, el límite efectivo es por `device_id`, por credencial y por empleado, que es además el que tiene sentido semántico — un quiosco legítimo genera muchas peticiones, una credencial legítima no.
   - La prueba k6 corre contra esa configuración, y se documenta en `docs/cliente/instalacion.md` que **la VLAN de quioscos debe declararse**: si el cliente pone los quioscos fuera de ese rango, el rate limiting de borde los estrangulará y el síntoma será «el quiosco va lento a las 06:00».

   Ajustar además y documentar: pool de PHP-FPM (doc 02 §3.4: «Nginx + PHP-FPM con pool ajustado»), índices y `lock_timeout`.
6. Verificar la reconciliación después de la carga: `attendance:reconcile` sin divergencias y `projection_divergence_total` en 0.
7. Integrar la ejecución en el ciclo de publicación de versión mayor (RQ-08), no en cada PR: el pipeline del §10.1 no incluye etapa de carga y las etapas 1–3 deben seguir por debajo de 4 minutos.
8. Registrar los resultados como línea base para detectar regresiones en versiones futuras.

**Artefactos.**

- `load-tests/k6/scan-peak.js`.
- `.github/workflows/` — invocación en el ciclo de versión. **⚠️ No cubierto por los documentos — decidir** si va en `release.yml` o en un *workflow* propio; el §2 lista `ci.yml`, `e2e.yml` y `release.yml`.
- `infra/docker/php/`, `infra/docker/nginx/` — ajustes de pool y de zonas de *rate limiting*.
- `docs/runbooks/` o `docs/cliente/operacion.md` — dimensionado y ajustes recomendados, coherentes con los requisitos de servidor publicados del §11.6.2.

**Pruebas exigidas.** §9.5 no tiene fila para carga; el umbral bloqueante lo fija el §9.2 («Carga | k6 | RNF-P-06: 50 fichajes/s con p95 < 150 ms»).

- k6: 50 fichajes/s sostenidos con **p95 < 150 ms** y p99 < 400 ms → etiqueta `RNF-P-06` en el informe y en la matriz de trazabilidad.
- Tras la carga: un tramo por persona, sin duplicados, y `daily_totals` cuadrando con los eventos origen (§9.4, «Cambio de turno real») → `->group('RN-06', 'RF-AT-07')`.
- Bajo carga, los rechazos mantienen tiempo constante → `->group('RS-03')`.
- Idempotencia bajo concurrencia sigue en verde (§9.4) → `->group('RQ-03')`.

**Verificación.**

```bash
k6 run load-tests/k6/scan-peak.js
php artisan attendance:reconcile --from=<hoy> --to=<hoy>     # sin divergencias
curl -s http://localhost/metrics | grep -E 'scan_processing_duration_seconds|projection_divergence_total'
```

Esperado: p95 por debajo de 150 ms a 50 fichajes/s; ningún duplicado; `projection_divergence_total` en 0 después de la prueba; los ajustes aplicados quedan escritos y no en la memoria de quien los hizo.

**Terminado cuando** (§10.3, subconjunto aplicable): umbral de RNF-P-06 alcanzado y registrado como línea base · pruebas de idempotencia y de tiempo constante en verde bajo carga · trazabilidad en verde para RNF-P-06 y RQ-08 · instrumentación suficiente para diagnosticar el cuello de botella · configuración de infraestructura versionada y conforme al §3.5 · documentación de dimensionado actualizada · nada específico de un cliente.

---

### Tarea 3.7 — E2E con cámara simulada y suite de accesibilidad

| | |
|---|---|
| **Horas** | 6–8 |
| **Agente / Skill** | `qa-testing` |
| **Requisitos** | RQ-04 (doc 02 §11). Relacionados: RQ-05 (ciclo offline completo), RF-QR-05 (corrección de errores nivel Q) y doc 01 §6.5 (accesibilidad) |
| **Precondiciones** | Derivado: la PWA del quiosco (**1.8**), la cola offline (**1.9**), el PIN de respaldo (**1.12**) y las credenciales impresas (**1.5**, **1.10**) para poder generar el vídeo con un QR real |
| **Bloquea a** | Publicación de versión: la etapa ⑦ del pipeline (§10.1) es E2E con cámara simulada y axe, y el §9.2 exige «todos los escenarios críticos en verde» y «0 violaciones críticas o graves» |

**Objetivo.** La suite E2E cubre el flujo de quiosco con cámara simulada alimentada por vídeo con un QR real, incluido el escenario de QR degradado que valida el nivel de corrección Q, y la suite de accesibilidad no admite violaciones críticas o graves.

**Reglas duras aplicables.**

- **19** — el escenario del ciclo offline debe demostrar que el empleado **no queda bloqueado** en ningún punto.
- **9** — la consolidación tras reconectar usa el `occurred_at` original, no la hora de llegada.
- **8** — la reanudación de la cola no duplica: idempotencia por `scan_id`.
- **17** — un QR inválido produce el mismo mensaje genérico que un revocado.
- **11** — el vídeo de prueba se genera a partir de una **tarjeta física** real de prueba; no se introduce ninguna variante de credencial en móvil.

**Banderas de Chromium, literales del §9.4 del doc 02.**

```
--use-fake-device-for-media-stream --use-file-for-fake-video-capture=e2e/fixtures/qr-video.y4m
```

Alimentando «un vídeo generado a partir de un QR real de prueba». El fichero vive en `frontend-kiosk/e2e/fixtures/qr-video.y4m` (árbol del §2: «`e2e/fixtures/qr-video.y4m` — Vídeo con QR real para cámara simulada»).

**Pasos.**

1. Generar el vídeo `qr-video.y4m` a partir de un QR real emitido por el sistema, con corrección de errores nivel Q (RF-QR-05, `QR_ERROR_CORRECTION=Q` del Anexo B).
2. Configurar Playwright con las banderas literales del §9.4 y dejar la configuración en el proyecto del quiosco, no en la máquina de nadie.
3. Escenario **QR degradado**: vídeo con el QR **parcialmente ocluido**, «para verificar que el nivel de corrección de errores Q cumple lo prometido» (§9.4). El doc 05 §5.2 se lo promete al cliente en estos términos: «la tarjeta sigue leyéndose con hasta un 25 % de deterioro». Esta prueba es la que sostiene esa frase.
4. Escenario **ciclo offline completo** (§9.4, RQ-05): «Playwright con red desconectada: fichar, verificar cola en IndexedDB, reconectar, verificar consolidación con el `occurred_at` original».
5. Escenarios de PIN de respaldo y de bloqueo por intentos (§9.4, «Bloqueo del PIN»; RS-12).
6. Cobertura de los ~25 escenarios de la pirámide del §9.1: flujo de quiosco, panel, portal y offline→sync.
7. Accesibilidad con **`@axe-core/playwright`**: **0 violaciones críticas o graves** (§9.2), en las tres aplicaciones. En el quiosco, comprobar además lo específico del doc 01 §6.5: objetivos táctiles ≥ 48 px, texto de confirmación ≥ 24 px, contraste ≥ 4.5:1 y doble canal visual y sonoro.
8. Etiquetar cada test con sus requisitos en el formato de Playwright del §9.6: `test('…', { tag: ['@RF-KI-03', '@RF-KI-04'] }, …)`.
9. Integrar en `.github/workflows/e2e.yml` (etapa ⑦ del §10.1, ~5 min).
10. Aplicar las reglas de código de pruebas del §3.5: nombre que describe el comportamiento, un concepto por prueba, sin condicionales ni bucles con lógica, **sin `sleep()`** (se espera por condición), y valores límite escritos explícitos.

**Artefactos.**

- `frontend-kiosk/e2e/fixtures/qr-video.y4m` y el vídeo degradado.
- `frontend-kiosk/tests/e2e/`, `frontend-admin/…`, `frontend-portal/…`.
- Configuración de Playwright con las banderas del §9.4.
- `.github/workflows/e2e.yml`.

**Pruebas exigidas.** Esta tarea **son** las pruebas. Lo que el §9.4 exige que quede cubierto y etiquetado:

- Cámara simulada con QR real → `tag: ['@RQ-04', '@RF-KI-02']`.
- QR degradado con oclusión parcial → `tag: ['@RF-QR-05']`.
- Ciclo offline completo → `tag: ['@RQ-05', '@RF-KI-03', '@RF-KI-04']`.
- Bloqueo del PIN → `tag: ['@RS-12', '@RF-AT-11']`.
- Recorridos de panel y portal ya cubiertos en 2.4, 2.5 y 1.11, consolidados aquí en una suite única.
- Accesibilidad: 0 violaciones críticas o graves en las tres aplicaciones → `tag: ['@RF-KI-06']`.

**Verificación.**

```bash
make e2e                                   # todos los escenarios críticos en verde
npx playwright test --grep @RF-QR-05       # QR degradado decodifica
npx playwright test --grep @RQ-05          # offline → reconexión → consolidación
# axe: 0 violaciones críticas o graves en quiosco, panel y portal
php artisan qa:traceability --check
```

Esperado: la etapa E2E completa en torno a los 5 minutos (§10.1); el escenario offline demuestra que el tramo se consolida con el `occurred_at` original y no con la hora de sincronización; y romper a propósito la decodificación hace fallar la prueba de QR degradado (criterio de `qa-testing`: una prueba que no podría fallar no vale nada).

**Terminado cuando** (§10.3, subconjunto aplicable): E2E en verde en CI · **accesibilidad verificada en las pantallas nuevas y existentes** · pruebas etiquetadas con sus requisitos y `qa:traceability --check` en verde · convenciones de código de pruebas del §3.5 respetadas · sin `sleep()` ni condicionales con lógica en los tests · nada específico de un cliente.

---

### Tarea 3.8 — Revisión de seguridad externa y corrección de hallazgos

| | |
|---|---|
| **Horas** | 8–12 |
| **Agente / Skill** | `seguridad-cumplimiento` (preparación y corrección) — literal del doc 02 §11 |
| **Requisitos** | RS-11: «Revisión de seguridad externa antes de la primera versión comercial y con periodicidad anual» |
| **Precondiciones** | Derivado: todo lo que se revisa tiene que existir. En el orden real, eso significa Fases 0, 1, 2 y 5 cerradas, y dentro de esta fase conviene que 3.1 esté hecha para poder observar lo que se prueba |
| **Bloquea a** | **La primera versión comercial** (RS-11) |

**Objetivo.** Se ha preparado y superado una revisión de seguridad externa con nivel objetivo **ASVS 2**, y los hallazgos están corregidos y verificados con prueba de no-regresión.

> **Contradicción entre documentos que hay que anotar.** La columna del doc 02 §11 dice «`seguridad-cumplimiento` (preparación y corrección)», pero el doc 03 §4.1 marca a ese agente como **que no escribe**, y el §1.2 explica por qué: «Dos agentes son de solo lectura a propósito: `seguridad-cumplimiento` y `revisor-codigo`. Quien encuentra un problema no debería ser quien lo arregla en el mismo paso: separar el hallazgo de la corrección obliga a que el problema se enuncie con claridad y quede visible para una persona». Lectura operativa: **`seguridad-cumplimiento` prepara la revisión, enuncia los hallazgos y verifica el cierre, pero la corrección la ejecuta el agente que corresponda a cada hallazgo** —`backend-laravel`, `frontend-quiosco`, `frontend-panel`, `devops-observabilidad` o `producto-licencia`—, que es precisamente lo que su formato de hallazgo prevé al incluir el campo «agente responsable de corregir». Corregir el texto del doc 02 §11 forma parte de la tarea.

**Alcance de la verificación (§7.6 del doc 02, literal).**

> Nivel objetivo: **ASVS 2 (estándar)**, con controles de nivel 3 en el registro de auditoría. Verificación en cada versión publicada: V1 (arquitectura), V2 (autenticación), V3 (sesión), V4 (control de acceso), V5 (validación), V7 (logs y errores), V8 (protección de datos), V9 (comunicaciones), V12 (ficheros), V13 (API), V14 (configuración).

**Reglas duras aplicables.** Todas las que la revisión verifica, con foco en: **6** (`audit_log` solo-append y sin `UPDATE`/`DELETE` para la aplicación), **10** y **17** (firma HMAC, rechazos genéricos de tiempo constante), **18** (policy y prueba negativa por endpoint), **21** (sin PII en logs ni en `error_events`), **16** (el fabricante no accede a los datos), **12** (sin dependencia del correo del empleado), **20** (cero biometría), y **15** (la licencia no bloquea el registro).

**Pasos.**

1. `seguridad-cumplimiento`: revisión interna previa por las seis categorías **STRIDE**, contrastada con el modelo de amenazas del doc 01 §8.1 (las doce filas: suplantación por QR falso, préstamo de tarjeta, fuerza bruta de PIN, manipulación en base de datos, alteración de licencia, repudio, filtración del padrón cacheado, PII en el paquete de diagnóstico, denegación de servicio, elevación con token de quiosco, y acceso de soporte usado fuera del incidente).
2. `seguridad-cumplimiento`: recorrer los seis bloques de `/revision-cumplimiento` sobre todo lo implementado, y emitir el informe con su formato: severidad `BLOQUEANTE | REVISAR | OBSERVACIÓN`, sección, ubicación, problema, **consecuencia**, corrección y requisito afectado.
3. Preparar el paquete para el revisor externo: arquitectura (doc 02 §1), diseño de seguridad (§7), contrato OpenAPI, matriz de trazabilidad de pruebas y ADRs. Sin secretos y **sin datos personales reales**.
4. Ejecutar y adjuntar la evidencia automática que el §9.2 ya exige: `composer audit`, `npm audit`, **Semgrep** (0 hallazgos de severidad alta), **Trivy** (0 CVE críticos en la imagen final).
5. Recibir los hallazgos externos y **enrutar cada uno al agente que corresponda**, con severidad y requisito.
6. Corregir, y por cada hallazgo añadir **una prueba que falla antes de la corrección** en el nivel que marque el §9.5. Un hallazgo cerrado sin prueba vuelve.
7. `seguridad-cumplimiento`: verificar el cierre y dejar constancia. Límites del agente: no inventar hallazgos y **no dar asesoramiento jurídico** — señala requisitos y riesgos y remite la validación a la asesoría (doc 03 §4.3).
8. Programar la repetición **anual** (RS-11) y anotarla en la matriz de versiones soportadas del §11.6.5.

**Artefactos.**

- Informe de revisión y su cierre. **⚠️ No cubierto por los documentos — decidir** dónde vive: ni el §2 ni el §12 asignan ubicación a los informes de seguridad. Candidato natural: `docs/` con acceso restringido, nunca en el paquete que se entrega al cliente si contiene detalles explotables.
- Correcciones repartidas por los módulos y aplicaciones afectados.
- Pruebas de no-regresión en el nivel que marque el §9.5 para cada hallazgo.
- `docs/runbooks/brecha-de-seguridad.md` — procedimiento de 72 h (§12, RL-15), si no existía.

**Pruebas exigidas.** Por cada hallazgo, el nivel que marque el §9.5 según su naturaleza. Además, los escenarios del §9.4 que son de seguridad y deben quedar demostrados:

- Autorización negativa por endpoint y rol, con registro en auditoría → `->group('RS-05', 'RF-ID-03')`.
- Respuestas de tiempo constante en el camino de fichaje → `->group('RS-03')`.
- Bloqueo del PIN creciente, por empleado y por IP → `->group('RS-12')`.
- Invariantes de base de datos ante intento directo por SQL → `->group('RN-01', 'RN-02')`.
- Cadena de auditoría: manipulación detectada → `->group('RS-07')`.
- Sin CVE críticos ni hallazgos altos de SAST (§9.2, umbrales bloqueantes).

**Verificación.**

```bash
composer audit && npm audit --audit-level=high
semgrep --config <reglas PHP/Laravel>          # 0 hallazgos de severidad alta
trivy image <imagen final>                     # 0 CVE críticos
php artisan test --group=RS-03 --group=RS-05 --group=RS-07 --group=RS-12
php artisan qa:traceability --check
```

Esperado: cero hallazgos bloqueantes abiertos; cada hallazgo cerrado tiene su prueba, y esa prueba falla si se revierte la corrección.

**Terminado cuando** (§10.3, subconjunto aplicable): hallazgos bloqueantes cerrados con prueba de no-regresión · autorización probada en negativo · PHPStan 9 limpio · dependencias, SAST y contenedores sin hallazgos por encima del umbral · trazabilidad en verde · ningún secreto en el repositorio, en las imágenes ni en los logs del pipeline · runbook de brecha de seguridad existente · revisión anual programada.

---

### Tarea 3.9 — Informes asíncronos con enlace de descarga caducable y exportación configurable para nómina

| | |
|---|---|
| **Horas** | 6–8 |
| **Agente / Skill** | `backend-laravel` + `/informe-nuevo` |
| **Requisitos** | RF-IN-06..07 (doc 02 §11 y Anexo A) |
| **Precondiciones** | **2.8** y **2.9** (los informes y las exportaciones que ahora se ejecutan en cola), y la configuración con ámbito de **5.1**, porque el formato de nómina es «configurable» (RF-IN-07) |
| **Bloquea a** | No figura dependencia en los documentos |

**Compromiso comercial (doc 05 §5.4), literal.**

> | Informes grandes | Se generan en segundo plano y se avisa con un enlace de descarga cuando están listos |
> | Salida a nómina | Exportación de horas en el formato que necesite la herramienta de nómina del hotel |

Y el doc 05 §8 acota el alcance para que no haya malentendido: «**Calcular la nómina**, pluses y complementos → Se **exporta** a la herramienta de nómina del hotel; no se calcula aquí».

**Objetivo.** Los informes de gran volumen se generan en cola, con notificación y **enlace de descarga caducable**, y existe la exportación de horas para nómina en formato configurable.

**Reglas duras aplicables.**

- **13** — el formato de nómina es **configuración** (RF-IN-07, ADR-017). Un cliente nuevo con otro programa de nómina no puede obligar a tocar el repositorio ni a mantener una rama.
- **6** — la generación de una exportación con datos de terceros se audita: quién, qué periodo, qué empleados (`/informe-nuevo`, paso 7).
- **18** — `POST /api/v1/reports/exports` y `GET /api/v1/reports/exports/{id}` son rol manager+; `GET /api/v1/reports/payroll-export` es rol **rrhh** (Anexo B). Prueba negativa por cada rol no autorizado.
- **21** — el fichero contiene datos personales por su finalidad; el log de la generación y el nombre del fichero, no.
- **15** — la exportación legal nunca se degrada por licencia (ADR-019); la de nómina es accesoria y sí puede degradarse.
- **3** — las horas se presentan en la zona del centro, y el fichero indica cuál.

**Pasos.** Tercera pasada de `/informe-nuevo` (**8 pasos**), con el foco en los pasos 5, 6 y 7:

1. **Definir la pregunta exacta** de la exportación de nómina, con granularidad y criterios de inclusión, y dejarlos visibles en el propio fichero.
2. **Elegir la fuente**: `daily_totals` para agregados por empleado y día; `shift_entries` si la nómina necesita el detalle de tramos. Nunca recalcular desde `scan_events`.
3. **Consulta** con agrupación por `work_date` (no `date_trunc` en UTC) y ámbito del rol dentro de la consulta.
4. **Índices** con `EXPLAIN ANALYZE` sobre el volumen realista.
5. **Asíncrono**: «> 10 s, o más de 3 meses de datos: job en cola, notificación al terminar y enlace de descarga con caducidad (RF-IN-06)». **El enlace lleva token de un solo uso y expiración: contiene datos personales de la plantilla** (paso 5 de la skill). Implementar con Horizon (doc 02 §1.4: el worker se ocupa de «Proyecciones, informes, exportaciones, PDF»).
6. **Formatos** en streaming con `spatie/simple-excel`; horas como texto `HH:MM`, nunca decimal; CSV en UTF-8 con BOM. El formato de nómina, con su mapa de columnas y separador **configurable**.
7. **Autorización** en la consulta y **auditoría** de la generación.
8. **Pruebas**: las ocho de la skill más contrato y autorización.

Además:

9. Actualizar `docs/api/openapi.yaml` con los endpoints del Anexo B: `POST /api/v1/reports/exports` (generar exportación async, manager+), `GET /api/v1/reports/exports/{id}` (estado y **enlace de descarga**, manager+, **caducable**), `GET /api/v1/reports/payroll-export` (salida para nómina, rol rrhh).
10. Notificación al terminar. El producto **no depende del correo del empleado** (regla dura 12), pero los usuarios de gestión sí tienen correo; el aviso en el panel debe existir igualmente para el caso de una instalación sin SMTP (doc 02 §11.6.2: sin salida a internet solo se pierde «el envío de correo si el SMTP es externo»).
11. Instrumentar `queue_jobs_pending{queue}`, `queue_job_duration_seconds{job}` y `queue_jobs_failed_total{job}` (§8.2) para las colas de informes.

**Artefactos.**

- `backend/app/Modules/Reporting/Application/UseCase/`, `.../Infrastructure/` — job de exportación y almacenamiento del fichero.
- `backend/app/Modules/Reporting/Http/` — endpoints de exportación.
- `frontend-admin/src/features/reports/` — solicitud, estado y descarga.
- `docs/api/openapi.yaml`.
- `docs/cliente/configuracion.md` — parámetros del formato de nómina (RF-IN-07).

**Pruebas exigidas.** §9.5, fila «Genera un **informe o exportación**»: **Unitaria del cálculo** + **Integración con volumen** + **Feature + Contrato** + **Autorización negativa**.

- Integración con volumen: exportación de 3 meses de 500 empleados en cola, sin agotar memoria → `->group('RF-IN-06')`.
- Feature + Contrato de los tres endpoints → `->group('RF-IN-06', 'RF-IN-07')`.
- Feature: el enlace de descarga **caduca** y es de un solo uso; reutilizarlo devuelve error → `->group('RF-IN-06')`.
- Autorización negativa: un responsable no descarga la exportación de otro ámbito; `empleado` y `auditor` no acceden a `payroll-export` (Anexo B: rol rrhh) → `->group('RF-ID-03', 'RF-IN-07')`.
- Unitaria: el mapa de columnas configurable produce el formato esperado sin código específico de cliente → `->group('RF-IN-07', 'RF-PD-01')`.
- Unitaria: horas como `HH:MM` → `->group('RF-IN-04')`.
- Integración: la generación queda auditada con periodo y empleados → `->group('RS-05')`.
- Las ocho pruebas de la lista de `/informe-nuevo`, incluidas turnos nocturnos, semana con cambio de hora, empleado de baja a mitad de periodo y días sin actividad.

**Verificación.**

```bash
php artisan test tests/Feature/Reporting tests/Contract tests/Integration/Reporting
php artisan test --group=RF-IN-06 --group=RF-IN-07
php artisan horizon:status
php artisan qa:traceability --check
```

Esperado: el enlace expira y no se puede reutilizar; cambiar el formato de nómina por configuración produce otro fichero **sin desplegar código**; la cola no se atasca con una exportación grande.

**Terminado cuando** (§10.3): Deptrac en verde · unitarias, integración con volumen, feature, contrato y autorización negativa · trazabilidad en verde · PHPStan 9 limpio · contrato OpenAPI actualizado · autorización probada en negativo · instrumentación de colas añadida · **generación auditada** · textos en ES y EN · **documentación de configuración actualizada** (parámetro nuevo) · nada específico de un cliente en el código.

---

### Tarea 3.10 — Registro de ausencias

| | |
|---|---|
| **Horas** | 3–4 |
| **Agente / Skill** | `backend-laravel` + `frontend-panel` |
| **Requisitos** | RF-GP-04 (doc 02 §11 y Anexo A del doc 01) |
| **Precondiciones** | **1.6** (`Workforce` básico: empleados, departamentos, centros) y **2.1** (ámbito y rol `rrhh`) |
| **Bloquea a** | Nada en los documentos. Pero **modifica el resultado de los informes de 2.8 y del cuadro de 3.13**, así que si se implementa después de ellos hay que revisar allí las pruebas de cálculo |

> **La importación masiva de plantilla ya no está en esta tarea.** RF-GP-05 pasó a la **tarea 5.5**, dentro del asistente de puesta en marcha. Motivo: el doc 05 §10.2 la promete como paso 4 de la puesta en marcha, y esta fase se ejecuta **después** de la Fase 5 en el orden real (0 → 1 → 2 → 5 → 3 → 4). Un asistente que obliga a teclear a mano la plantilla de un hotel no es un producto instalable, que es el criterio con el que se juzga la Fase 5. Son **3–4 h que cambiaron de fase, no que se sumaron**. Registrado en el Anexo A del [doc 01](../docs/01-especificaciones-proyecto.md) y en el §11 del [doc 02](../docs/02-stack-tecnologico-y-plan-implementacion.md).

**Compromiso comercial (doc 05 §5.5), literal en lo que corresponde a esta tarea.**

> Registro de ausencias (vacaciones, baja, permiso) para que los informes no las cuenten como absentismo injustificado.

Y el doc 05 §8 acota el alcance con precisión: las ausencias se registran «**pero sin flujo de aprobación**». El flujo de aprobación es Fase 4, y esa frontera está anunciada al cliente: añadir un botón de «aprobar» aquí desdibuja una expectativa que hoy está acotada.

**Objetivo.** RRHH registra ausencias —vacaciones, baja médica, permiso— manualmente o por CSV, y los informes dejan de contarlas como absentismo injustificado.

**Reglas duras aplicables.**

- **5** — una ausencia corregida no sobrescribe: crea versión nueva conservando la anterior con autor, momento y motivo (RN-13).
- **6** — cada ausencia registrada, modificada o eliminada escribe en `audit_log`: cambia el resultado de un informe de absentismo, que tiene consecuencias laborales.
- **14** — si el perfil de cumplimiento define calendario de festivos (RF-PD-07), la ausencia se interpreta contra él y no contra un calendario codificado.
- **21** — el registro de una baja médica es dato de salud: **jamás en logs técnicos ni en `error_events`**. Ni el tipo de ausencia ni el `employee_id` en claro.

**Pasos.** Sin skill asignada. Orden derivado del método de `backend-laravel` (doc 03 §4.3): contrato primero, dirección de dependencias, auditoría e instrumentación.

1. Actualizar `docs/api/openapi.yaml` con el CRUD de `/absences` (Anexo B del doc 01: `CRUD /api/v1/employees, /departments, /sites, /contracts, /devices, /absences`).
2. Tabla `absences` con el esquema del doc 01 §5.5: `id`, `employee_id`, `type`, `starts_on`, `ends_on`, `note`. Migración reversible con `down()` probado.
3. Casos de uso de alta, modificación y baja de ausencia en `Workforce`, con validación de fechas coherentes y de solape entre ausencias del mismo empleado.
4. Carga manual y por CSV, en streaming con `spatie/simple-excel` (§3.1).
5. **Efecto en informes:** las ausencias registradas no cuentan como absentismo no justificado (RF-GP-04). Toca el cálculo de los informes de **2.8** y del cuadro de **3.13**.
6. Panel: pantalla de ausencias. Principio de `frontend-panel` (doc 03 §4.3): mostrar **de qué valor a cuál** antes de confirmar un cambio.
7. Auditoría de cada operación sobre ausencias.
8. Instrumentación: el efecto en los indicadores de absentismo del §8.2 y del cuadro de 3.13.
9. i18n ES/EN y accesibilidad AA.

**Artefactos.**

- `backend/app/Modules/Workforce/Application/UseCase/` — altas, modificaciones y bajas de ausencia.
- `backend/app/Modules/Workforce/Http/`.
- `backend/database/migrations/` — `create_absences_table`.
- `frontend-admin/src/features/employees/`.
- `docs/api/openapi.yaml`.
- `docs/cliente/configuracion.md` — tipos de ausencia y su efecto en los informes.

**Pruebas exigidas.** §9.5: expone **endpoints** → **Feature + Contrato** y **autorización negativa por cada rol no autorizado**; toca **esquema** → **Integración**; tiene **recorrido de usuario** en el panel → **E2E**. Y como **cambia el resultado de un informe**, la fila «Genera un informe o exportación» obliga a la **unitaria del cálculo**.

- Unitaria: un día cubierto por una ausencia registrada **no** cuenta como absentismo no justificado → `->group('RF-GP-04')`.
- Unitaria: límites exactos de la ausencia — el primer día y el último día están cubiertos, el anterior y el posterior no → `->group('RF-GP-04')`.
- Integración: dos ausencias solapadas del mismo empleado se rechazan → `->group('RF-GP-04')`.
- Integración: modificar una ausencia conserva la versión anterior con autor y motivo → `->group('RN-13')`.
- Integración: cada operación sobre ausencias queda en `audit_log` → `->group('RS-05')`.
- Feature + Contrato del CRUD de `/absences` → `->group('RF-GP-04')`.
- Autorización negativa: `responsable_departamento` solo ve las de su departamento; `auditor` y `empleado` no pueden escribir → 403 → `->group('RF-ID-03')`.
- E2E: registrar una baja y comprobar que el informe de absentismo del periodo cambia → `tag: ['@RF-GP-04']`.

**Verificación.**

```bash
php artisan test tests/Feature/Workforce tests/Integration/Workforce tests/Contract
php artisan test --group=RF-GP-04
make e2e -- --grep @RF-GP-04
```

Esperado: un empleado con una baja de tres días no aparece como ausente injustificado ningún día de esos tres, ni el anterior ni el siguiente cambian de estado, y el informe del periodo cuadra antes y después con la única diferencia de esos tres días.

**Terminado cuando** (§10.3): Deptrac en verde · unitaria del cálculo, integración, feature, contrato, autorización negativa y E2E · trazabilidad en verde · PHPStan 9 limpio · contrato OpenAPI actualizado · autorización probada en negativo · instrumentación añadida · **`audit_log` escrito** · migración reversible con `down()` probado · textos en ES y EN · **accesibilidad verificada** · documentación de configuración actualizada · nada específico de un cliente.

---

### Tarea 3.11 — Detección de patrones anómalos de uso de credencial, con incidencia y bandeja

| | |
|---|---|
| **Horas** | 5–7 |
| **Agente / Skill** | `backend-laravel`, revisión de `seguridad-cumplimiento` |
| **Requisitos** | RF-PR-06 (doc 02 §11 y Anexo A) |
| **Precondiciones** | Derivado: `scan_events` con `device_id` y `occurred_at` (**1.4**), la bandeja de incidencias (**2.5**), la detección programada (**2.6**) y la configuración con ámbito para los parámetros (**5.1**) |
| **Bloquea a** | No figura dependencia en los documentos |

**Compromiso comercial (doc 05).** §4.6: «Lo que la firma no impide es que alguien **preste físicamente su tarjeta**. Es un fraude autolimitado […] y se combate con supervisión presencial y con la detección automática de patrones anómalos». §12, preguntas frecuentes: «El sistema detecta patrones anómalos (dos fichajes seguidos en el mismo quiosco, coincidencias sistemáticas entre dos personas) y los pone sobre la mesa».

**Es la contrapartida explícita de haber descartado la biometría.** Doc 02 §11, nota de Fase 3: «La 3.11 es además la contrapartida explícita de haber descartado la biometría (ADR-009)». Doc 01 §3.8, nota de RF-PR-06: «El préstamo físico de la tarjeta es el único fraude que la firma HMAC no impide (§8.1), y la detección de patrones es la contrapartida explícita del descarte de la biometría (§7.4, ADR-009). Sin un requisito con dueño, umbral y bandeja donde aterrizar, esa mitigación no existe en el producto». Y **regla dura 20**: cero biometría, decisión firme; si una tarea la sugiere, parar y preguntar. Esta tarea es lo que hace defendible esa decisión.

**Objetivo.** Un proceso detecta patrones anómalos de uso de credencial —fichajes consecutivos en el mismo quiosco separados por segundos, coincidencias sistemáticas entre dos empleados y secuencias imposibles— y genera una incidencia `anomalous_pattern` asignada al responsable. **Nunca decide que ha habido fraude**: aporta el indicio.

**Reglas duras aplicables.**

- **20** — cero biometría. Esta detección es la alternativa, no un paso hacia ella.
- **19** — la detección **no bloquea ni anula**: el fichaje sigue siendo válido y el empleado no queda sin registro.
- **5** — no se anula ni se marca como fraudulento ningún fichaje (RF-PR-06, §9.4).
- **14** — los umbrales son parámetros: `ATTENDANCE_PATTERN_WINDOW_SECONDS=10` («RF-PR-06 · fichajes consecutivos en el mismo quiosco») y `ATTENDANCE_PATTERN_MIN_REPEATS=3` («RF-PR-06 · coincidencias antes de generar incidencia»), Anexo B del doc 02. Con la Fase 5 hecha, la fuente de verdad es `installation_settings` y la variable solo fija el valor por defecto.
- **21** — la incidencia identifica por `employee_uuid`; el log técnico no lleva nombres. Un indicio de posible préstamo de credencial es información sensible sobre dos personas concretas.
- **6** — la creación y la resolución de la incidencia quedan en auditoría.
- **1** y **2** — la regla de detección vive en el dominio con el reloj inyectado.

**Pasos.**

1. Definir la regla con precisión sobre los tres patrones de RF-PR-06: (a) dos fichajes consecutivos en el mismo quiosco separados por menos de `ATTENDANCE_PATTERN_WINDOW_SECONDS`; (b) coincidencias sistemáticas entre dos empleados, a partir de `ATTENDANCE_PATTERN_MIN_REPEATS` repeticiones; (c) secuencias imposibles.
2. La «secuencia imposible» **está definida ahora como `RN-16`** en el doc 01 §4: *dos escaneos de la misma credencial en **dispositivos distintos** separados por menos del tiempo mínimo de tránsito entre ellos*, con el umbral en `ATTENDANCE_MIN_TRANSIT_SECONDS` (Anexo B del doc 02, 120 s por defecto). Se documentó con `/nueva-regla-de-negocio` porque RF-PR-06 la nombraba sin definirla, y sin enunciado no hay umbral, no hay prueba y —sobre todo— **no hay forma de sostener el indicio ante la persona señalada**, que es exactamente lo que el runbook `patron-anomalo-credencial.md` existe para evitar.
   > ✅ **120 s confirmado como valor de serie, decisión de producto** (13 de agosto de 2026), no una medición de un hotel concreto: razonable como punto de partida, absurdo si se generalizara a un resort de distancias grandes. Por eso es configuración por instalación (`installation_settings`) y no una constante — el asistente de puesta en marcha debe preguntarlo, y cada cliente puede ajustarlo a la distancia real entre sus quioscos.
3. Implementar la detección en el dominio, con los umbrales recibidos por puerto, y el comando `php artisan attendance:detect-patterns` (doc 02 Anexo C: «Patrones anómalos de uso de credencial (RF-PR-06)») en el Scheduler.
4. Generar la incidencia de tipo `anomalous_pattern` (tipo ya presente en `incidents.type`, doc 01 §5.5), asignada al responsable del departamento, con la severidad que corresponda.
5. Bandeja: la incidencia aterriza en la bandeja de 2.5 con el contexto mínimo necesario para revisarla: qué quiosco, qué momentos, cuántas repeticiones. **Sin conclusión.**
6. Instrumentar `anomalous_patterns_detected_total{pattern}` (counter, §8.2) y la métrica de negocio del doc 01 §9.2 «Patrones anómalos detectados por tipo — Indicio de préstamo de credencial (RF-PR-06). **Se revisa, no se sanciona automáticamente**».
7. Escribir el runbook **`docs/runbooks/patron-anomalo-credencial.md`** — §12 del doc 02: «Cómo revisar una incidencia `anomalous_pattern` **sin convertir un indicio en una acusación**». Contenido mínimo derivable de los documentos: qué dice y qué no dice el indicio; que el fichaje no se anula; que se contrasta con supervisión presencial (doc 01 §8.1); que la conversación con la persona la conduce quien corresponda y no el sistema; y que el registro de la revisión queda en la incidencia.
8. Alerta: **⚠️ No cubierto por los documentos — decidir** si esta detección genera alerta y con qué destinatario. El catálogo del doc 01 §9.3 **no la incluye**, y el §8.4 no admite alerta sin runbook —que aquí sí existiría—. Si se decide no alertar, la vía es la bandeja de incidencias, y conviene decirlo explícitamente.
9. **Revisión de `seguridad-cumplimiento`** (doc 02 §11), con foco en el bloque B de `/revision-cumplimiento`: minimización, finalidad que **no se amplía** —el dato se usa para la gestión de presencia, no para elaborar perfiles— y retención definida para la incidencia.

**Artefactos.**

- `backend/app/Modules/Attendance/Domain/Policy/` o `backend/app/Modules/Compliance/Domain/` — regla de detección.
- Comando de consola `attendance:detect-patterns` y su registro en el Scheduler.
- `frontend-admin/src/features/incidents/` — presentación del contexto del indicio.
- `infra/observability/` — métrica y, si se decide, regla de alerta.
- `docs/runbooks/patron-anomalo-credencial.md`.
- `.env.example` y `docs/cliente/configuracion.md` — `ATTENDANCE_PATTERN_WINDOW_SECONDS`, `ATTENDANCE_PATTERN_MIN_REPEATS`.

**Pruebas exigidas.** §9.5: **regla de negocio** → **Unitaria obligatoria**; crea filas y consulta con volumen → **Integración**. La bandeja y su endpoint ya se probaron en 2.5; si se añade contexto nuevo a la respuesta, **Feature + Contrato + autorización negativa**.

- **Escenario ineludible del §9.4 «Patrones anómalos»:** «Serie de fichajes consecutivos en el mismo quiosco separados por segundos y coincidencias repetidas entre dos empleados → incidencia `anomalous_pattern`, **sin anular ni marcar como fraude ningún fichaje** (RF-PR-06)» → `->group('RF-PR-06')`.
- Gherkin del doc 01 §11 «Patrón anómalo de uso de credencial», al pie de la letra: dos fichajes de empleados distintos en el mismo quiosco separados por **4 segundos**, repetidos en las mismas dos personas durante **cinco días** → incidencia creada, asignada al responsable, y «el sistema no marca el fichaje como fraudulento ni lo anula» → `->group('RF-PR-06')`.
- Unitaria de límites exactos: con ventana de 10 s, 9 s cuenta y 11 s no; con `MIN_REPEATS=3`, dos coincidencias no generan incidencia y tres sí → `->group('RF-PR-06')`.
- Unitaria: cambiar los umbrales por configuración cambia el resultado sin tocar código → `->group('RF-PD-01')`.
- Integración: la incidencia se asigna al responsable del departamento y queda auditada → `->group('RF-PA-05', 'RS-05')`.
- Integración: ejecutar el comando dos veces no duplica incidencias.
- Prueba de privacidad: ni el log técnico ni `error_events` contienen nombres del caso → `->group('RF-PD-15')`.

**Verificación.**

```bash
make test-unit && make mutate
php artisan attendance:detect-patterns
php artisan test --group=RF-PR-06
curl -s http://localhost/metrics | grep anomalous_patterns_detected_total
```

Esperado: sobre la semilla con casos límite, el comando genera incidencias del tipo esperado, **ningún `shift_entry` cambia de estado** y ninguna respuesta del sistema califica nada como fraude. El runbook permite a un responsable revisar el caso sin acusar a nadie.

**Terminado cuando** (§10.3): Deptrac en verde · unitarias con límites explícitos e integración · MSI dentro de umbral · trazabilidad en verde · PHPStan 9 limpio · instrumentación añadida · **`audit_log` escrito** · textos en ES y EN · **runbook `patron-anomalo-credencial.md` escrito** · umbrales fuera del código y documentados en configuración · revisión de `seguridad-cumplimiento` sin bloqueantes · nada específico de un cliente.

---

### Tarea 3.12 — Resumen semanal por correo y ventana controlada de actualización del quiosco

| | |
|---|---|
| **Horas** | 4–5 |
| **Agente / Skill** | `backend-laravel` + `frontend-quiosco` |
| **Requisitos** | RF-PR-05, RF-KI-07 (doc 02 §11 y Anexo A) |
| **Precondiciones** | Derivado: informes por periodo (**2.8**) para el contenido del resumen; el service worker del quiosco (**1.8**) para la ventana de actualización; y la configuración con ámbito (**5.1**), porque la ventana es «configurable» |
| **Bloquea a** | No figura dependencia en los documentos |

**Compromiso comercial (doc 05 §5.7), literal.**

> | Resumen semanal | Correo opcional al responsable de cada departamento |

Y el doc 05 §10.4 promete el otro lado de la moneda: «**Durante la actualización el fichaje no se detiene**: la tablet sigue registrando y encolando, y sincroniza cuando el servidor vuelve.»

**Objetivo.** El responsable de cada departamento recibe un resumen semanal por correo —opcional— y la app del quiosco **no se actualiza durante un cambio de turno**: la actualización ocurre en una ventana configurable.

**Reglas duras aplicables.**

- **19** — el quiosco nunca bloquea al empleado. Una actualización a las 06:00 con treinta personas fichando es exactamente el fallo que RF-KI-07 evita.
- **12** — el resumen va a usuarios de gestión, que sí tienen correo. **El producto no depende del correo del empleado**: no puede haber ningún camino en que el resumen sea obligatorio ni que dependa de correos de empleados.
- **21** — un correo con nombres de empleados es una comunicación de datos personales dentro de la organización del cliente, legítima por su finalidad, pero **el log del envío no lleva nombres**.
- **13** — la ventana de actualización y la activación del resumen son configuración por centro, no código.
- **15** — la degradación por licencia puede afectar al resumen (accesorio), nunca al fichaje.
- **16** — el correo lo envía el SMTP **del cliente** (doc 02 §3.4); el fabricante no interviene.

**Pasos.**

1. Definir el contenido del resumen semanal a partir de los informes de 2.8, con el ámbito del responsable aplicado en la consulta. **⚠️ No cubierto por los documentos — decidir** el contenido exacto: RF-PR-05 dice «Resumen semanal por correo al responsable de cada departamento» sin especificar qué incluye.
2. Implementar el envío en el Scheduler, con Mailpit en desarrollo y el SMTP del cliente en producción (`MAIL_MAILER=smtp`, Anexo B). Si no hay SMTP configurado, **el sistema funciona igual**: el resumen es opcional (doc 05 §5.7 dice «Correo opcional»).
3. Activación por centro o por departamento mediante configuración, con el cambio auditado si tiene efecto sobre datos personales.
4. Auditar el envío como acceso a datos personales de terceros si el resumen incluye datos individuales (RS-05, bloque D de `/revision-cumplimiento`).
5. Quiosco: ventana de actualización configurable (RF-KI-07). El service worker no aplica una versión nueva fuera de ella, y **nunca** durante un cambio de turno.
6. **El quiosco no detecta el cambio de turno: la ventana es configuración.** RF-KI-07 lo dice literalmente —«no se actualiza durante un cambio de turno; **ventana de actualización configurable**»—, así que la franja se declara por centro en `installation_settings` (RF-PD-01) y no se infiere. Sobre esa ventana, **dos guardas locales** que son más fiables que adivinar el horario:
   - No actualizar si **la cola offline no está vacía**: una actualización con fichajes pendientes de subir es la única forma de perder un registro.
   - No actualizar si **hubo un escaneo en los últimos N minutos**, aunque la ventana esté abierta. Cubre el turno que empieza antes de lo previsto sin necesidad de saber cuándo empieza.

   Intentar inferir la franja a partir del histórico de escaneos sería adivinar un dato que el cliente puede declarar, y equivocarse significa actualizar el quiosco con cola de gente delante.
7. Verificar que una actualización en curso no pierde la cola de IndexedDB: el §6 garantiza que «la cola persiste en IndexedDB con transacciones» y que solo se borra un elemento «tras confirmación explícita del servidor».
8. Mostrar la versión en la pantalla de diagnóstico (3.3, §10.5) para poder correlacionar un incidente con una versión.
9. i18n ES/EN del correo y de los avisos del quiosco.

**Artefactos.**

- `backend/app/Modules/Reporting/…` o `Compliance/…` — generación del resumen. **⚠️ No cubierto por los documentos — decidir** el módulo: el §1.6 asigna informes a `Reporting` y procesos de cumplimiento a `Compliance`, y el resumen semanal encaja en ambos.
- Plantillas de correo con textos en `i18n`.
- `frontend-kiosk/src/sw/` — service worker con la ventana de actualización.
- `docs/cliente/configuracion.md` — ventana de actualización y activación del resumen.

**Pruebas exigidas.** §9.5: el resumen **genera un informe** → **Unitaria del cálculo** + **Integración con volumen**; la ventana del quiosco tiene **recorrido de usuario** → **E2E**. Si se añade endpoint para configurar la ventana, **Feature + Contrato + autorización negativa** (la configuración va por `PATCH /api/v1/settings`, Anexo B, rol admin y **auditado**).

- Unitaria: el resumen de un responsable contiene solo su ámbito → `->group('RF-PR-05', 'RF-ID-03')`.
- Integración: sin SMTP configurado, el sistema no falla y el resumen se omite con registro → `->group('RF-PR-05')`.
- Integración: el envío queda auditado → `->group('RS-05')`.
- E2E: con una versión nueva disponible **fuera** de la ventana, el quiosco no se actualiza y sigue fichando; dentro de la ventana, se actualiza → `tag: ['@RF-KI-07']`.
- E2E: una actualización con elementos en la cola no pierde ninguno → `tag: ['@RF-KI-04', '@RQ-05']`.
- Autorización negativa del cambio de configuración: solo `admin` (Anexo B) → `->group('RF-ID-02')`.

**Verificación.**

```bash
php artisan test --group=RF-PR-05 --group=RF-KI-07
make e2e -- --grep "@RF-KI-07"
# Mailpit: el resumen llega con el ámbito correcto y sin datos de otros departamentos
```

Esperado: el quiosco no cambia de versión fuera de la ventana; la cola sobrevive a la actualización; el resumen de un responsable de Cocina no menciona a nadie de Recepción.

**Terminado cuando** (§10.3): Deptrac en verde · unitarias, integración y E2E · trazabilidad en verde · PHPStan 9 limpio · autorización probada en negativo donde aplique · instrumentación añadida · **`audit_log` escrito** en el envío con datos de terceros · textos del correo y del quiosco en ES y EN · **documentación de configuración actualizada** · nada específico de un cliente.

---

### Tarea 3.13 — Cuadro de impacto y adopción: proyección de los indicadores del §1.3, comparación entre periodos, pantalla y exportación

| | |
|---|---|
| **Horas** | 6–8 |
| **Agente / Skill** | `backend-laravel` + `frontend-panel` + `/informe-nuevo` |
| **Requisitos** | RF-IN-08 (doc 02 §11 y Anexo A). Y **RNF-D-01** en la fila de fiabilidad del cuadro: *«disponibilidad del acto de fichar: 99,9 %»* es un requisito no funcional que **esta tarea es la única que mide y presenta**, así que sus pruebas lo etiquetan y la matriz de trazabilidad lo recoge |
| **Precondiciones** | **3.1** (las métricas de impacto y adopción del §8.2), **2.8** y **2.9** (base de informes), **2.3** (correcciones, para el ratio), **2.5/2.6** (incidencias y su tiempo de resolución) y **1.10** (credenciales entregadas) |
| **Bloquea a** | No figura dependencia en los documentos. Es, según el §8.3, «el que sostiene la renovación de la licencia» |

**Compromiso comercial (doc 05 §5.4), literal.**

> | **Cuadro de impacto** | Qué está consiguiendo el sistema, con comparación entre periodos: qué porcentaje de jornadas queda registrado completo, cuántos fichajes son por tarjeta, por PIN o por corrección manual, cuánto se tarda en resolver una incidencia y cuánta gente sigue sin tarjeta entregada. Es el cuadro que responde a *"¿esto está sirviendo?"* con datos y no con impresiones |

**Objetivo.** El sistema calcula y presenta, por periodo y **con comparación contra el periodo anterior**, los indicadores del §1.3 del doc 01, con pantalla en el panel y exportación, accesible por rol `admin` y `rrhh`.

**Los indicadores, del doc 01 §1.3 (literal) y de RF-IN-08.**

| Objetivo | Métrica | Objetivo a 3 meses de producción |
|---|---|---|
| Cumplimiento legal del registro de jornada | % de jornadas con registro completo | ≥ 99 % |
| Eliminar el registro manual en papel | % de fichajes por QR sobre el total | ≥ 98 % |
| Reducir la carga administrativa de RRHH | Horas/mes consolidando hojas de horas | −80 % |
| Fiabilidad del quiosco | Disponibilidad del flujo de fichaje (incluye modo offline) | ≥ 99,9 % |
| Detección temprana de incidencias | Tiempo medio hasta resolver un turno sin cerrar | < 24 h |
| Confianza en el dato | Correcciones manuales / total de fichajes | < 2 % |

Y de RF-IN-08, la lista de lo que el cuadro presenta: «porcentaje de jornadas con registro completo, reparto de fichajes por origen (QR, PIN, corrección manual), ratio de correcciones sobre el total, incidencias abiertas y tiempo medio hasta resolverlas, empleados sin credencial entregada, y horas trabajadas frente a contratadas. Exportable y accesible por rol `admin` y `rrhh`».

**Métricas del §8.2 que lo alimentan** (bloque «Impacto y adopción»): `scans_by_origin_total{origin}`, `workdays_complete_ratio{site}`, `incident_resolution_seconds{type}`, `worked_minutes_total{site,department}`; más `employees_without_delivered_credential{site}`, `credentials_pending_print{site}` y `pin_fallback_scans_total{site}` del bloque de credenciales, y `manual_corrections_total{reason_code}` e `incidents_open{type,severity}` del bloque de negocio.

**Nota de coherencia.** Dos de los seis indicadores del §1.3 no salen de las métricas del §8.2, y cada uno se resuelve de forma distinta:

- **«Horas/mes consolidando hojas de horas (−80 %)»** es una cifra **anterior al sistema**: mide el proceso manual que KronoQR viene a sustituir, y ninguna métrica de una aplicación puede observar el trabajo que se hacía antes de instalarla. Se resuelve capturándola como **dato que el cliente declara en el asistente de puesta en marcha** (tarea 5.5), y el cuadro muestra la variación contra esa línea base. Sin ese dato el indicador queda vacío, y eso es correcto: es honesto no inventar un porcentaje de mejora.
- **«Disponibilidad del flujo de fichaje ≥ 99,9 %»** **no es tiempo de servicio de la API**, y el propio §1.3 lo aclara entre paréntesis: «(incluye modo offline)». Lo que mide es si el empleado **pudo fichar**, no si el servidor respondía — y con la cola offline pudo fichar aunque el servidor estuviera caído, que es toda la razón de ser del ADR-008. Se calcula como **fichajes confirmados sobre fichajes intentados** según la telemetría que el quiosco envía en su latido, no desde la sonda de `/api/v1/health`. Medirlo como *uptime* de la API daría una cifra peor que la realidad y contaría como caída precisamente el escenario que el diseño resuelve.

**Reglas duras aplicables.**

- **7** — el cuadro **lee** proyecciones; no recalcula en paralelo lo que ya está en `daily_totals` (`/informe-nuevo`, paso 2).
- **3** y **4** — comparación entre periodos con `work_date` y en la zona del centro; nunca `date_trunc` en UTC.
- **18** — accesible solo por `admin` y `rrhh` (RF-IN-08 y Anexo B: `GET /api/v1/reports/adoption [rol: admin|rrhh]`), con prueba negativa por cada otro rol.
- **6** — la generación con datos de terceros se audita.
- **21** — el cuadro es agregado; no expone nombres donde baste un agregado.
- **16** — el §8.3 dice que la audiencia incluye «el propio fabricante en la venta»: eso solo puede ocurrir con datos que el cliente entregue voluntariamente y anonimizados (ADR-020, RL-19). Nada de acceso del fabricante al cuadro del cliente.
- **15** — el cuadro sostiene la renovación de licencia, pero su ausencia no puede bloquear el registro.

**Pasos.** Cuarta pasada de `/informe-nuevo` (**8 pasos**):

1. **Definir la pregunta exacta**: «¿está sirviendo el sistema?», con granularidad de periodo y comparación contra el anterior. Criterios de inclusión visibles en el propio cuadro: qué es una «jornada con registro completo», qué cuenta como corrección, si se incluyen turnos abiertos y anulados.
2. **Elegir la fuente**: `daily_totals` para jornadas y horas; `scan_events` para el reparto por origen (`origin` de `ScanOrigin`: `QR_KIOSK` | `PIN_KIOSK` | `MANUAL_ADMIN` | `IMPORT`, doc 01 §5.3); `shift_corrections` para el ratio de correcciones; `incidents` para abiertas y tiempo de resolución; `credentials` para entregas pendientes.
3. **Consulta** con funciones de ventana «para acumulados y comparativas con el periodo anterior» (paso 3 de la skill), `generate_series` para periodos sin actividad y `AT TIME ZONE` para la zona del centro.
4. **Índices** con `EXPLAIN ANALYZE` sobre volumen realista (500 empleados × 2 años ≈ 400.000 filas en `daily_totals`).
5. **Síncrono o asíncrono** según el presupuesto de RNF-P-05; si supera el umbral, cola con enlace caducable, reutilizando lo de 3.9.
6. **Formatos** de exportación: CSV/XLSX con horas `HH:MM`, PDF con sello, emisor y hash si se sella.
7. **Autorización** en la consulta (`admin` y `rrhh`) y generación auditada.
8. **Pruebas**: las ocho de la skill más contrato y autorización.

Además:

9. Actualizar `docs/api/openapi.yaml` con `GET /api/v1/reports/adoption` (Anexo B, rol `admin|rrhh`).
10. Panel: pantalla con los indicadores, su objetivo del §1.3 y la variación contra el periodo anterior, con gráficos (ECharts) **y tabla de datos alternativa** (doc 02 §3.3), que es además lo que hace la pantalla accesible.
11. Alinear los números del cuadro con el cuadro de mando de Grafana «Impacto y adopción» de 3.2: si el panel y Grafana dicen cosas distintas, el cuadro pierde su función.
12. i18n ES/EN y accesibilidad AA.

**Artefactos.**

- `backend/app/Modules/Reporting/Application/Query/` — consulta de adopción.
- `backend/app/Modules/Reporting/Http/` — endpoint y policy.
- `frontend-admin/src/features/reports/` — pantalla del cuadro.
- `docs/api/openapi.yaml`.
- `infra/observability/` — coherencia con el cuadro de Grafana de 3.2.

**Pruebas exigidas.** §9.5, fila «Genera un **informe o exportación**»: **Unitaria del cálculo** + **Integración con volumen** + **Feature + Contrato** + **Autorización negativa**. Tiene además **recorrido de usuario** → **E2E**.

- Unitaria: «% de jornadas con registro completo» con un conjunto conocido y resultado verificado a mano → `->group('RF-IN-08')`.
- Unitaria: reparto por origen (QR, PIN, corrección manual) que suma 100 % → `->group('RF-IN-08')`.
- Unitaria: ratio de correcciones sobre el total y tiempo medio de resolución → `->group('RF-IN-08')`.
- Unitaria: comparación contra el periodo anterior, incluido el caso de periodo anterior vacío → `->group('RF-IN-08')`.
- **Unitaria: la disponibilidad del acto de fichar se calcula como fichajes confirmados sobre intentados** —incluidos los resueltos por la cola offline con el servidor caído—, no como *uptime* de la API → **`->group('RF-IN-08', 'RNF-D-01')`**. Es la única prueba del proyecto que etiqueta RNF-D-01, y sin ella el requisito está cubierto en sustancia pero **no sale en la matriz**, que es la evidencia ante auditoría.
- Integración con volumen: `EXPLAIN ANALYZE` dentro del presupuesto de RNF-P-05 → `->group('RNF-P-05')`.
- Integración: turnos nocturnos y semana con cambio de hora no distorsionan los porcentajes → `->group('RN-05', 'RN-09')`.
- Feature + Contrato de `GET /api/v1/reports/adoption` → `->group('RF-IN-08')`.
- Autorización negativa: `responsable_departamento`, `auditor` y `empleado` reciben 403 → `->group('RF-ID-03', 'RF-IN-08')`.
- E2E: la pantalla muestra los indicadores con su objetivo y la variación → `tag: ['@RF-IN-08']`.
- Accesibilidad: gráficos con tabla de datos alternativa, 0 violaciones críticas o graves.

**Verificación.**

```bash
php artisan test --group=RF-IN-08
php artisan test tests/Feature/Reporting tests/Contract tests/Integration/Reporting
make e2e -- --grep @RF-IN-08
# Contraste: los valores del cuadro coinciden con el dashboard "Impacto y adopción" de Grafana
```

Esperado: los porcentajes del panel coinciden con las métricas `workdays_complete_ratio` y `scans_by_origin_total`; el cuadro se exporta y las horas se leen `HH:MM`; con un periodo anterior sin datos, la comparación se muestra vacía y no como 0 %.

**Terminado cuando** (§10.3): Deptrac en verde · unitarias, integración con volumen, feature, contrato, autorización negativa y E2E · trazabilidad en verde · PHPStan 9 limpio · contrato OpenAPI actualizado · autorización probada en negativo · instrumentación coherente con 3.1 y 3.2 · **generación auditada** · textos en ES y EN · **accesibilidad verificada** · nada específico de un cliente en el código.

---

## Cierre de la Fase 3

Procedimiento del **doc 03 §6.6**, aplicado a esta fase:

```
Cierra la Fase 3 del plan.

1. seguridad-cumplimiento: revisa todo lo implementado contra STRIDE,
   RGPD y art. 34.9 ET. Informe por severidad.
2. revisor-codigo: revisión final buscando duplicación, complejidad
   innecesaria e incumplimientos de la Definición de Terminado.
3. qa-testing: verifica cobertura, MSI y que cada requisito de la fase
   (Anexo A del documento 01) tiene prueba que lo cubre.
4. devops-observabilidad: comprueba que lo nuevo está instrumentado y
   que cada alerta añadida tiene su runbook.
```

**Comprobaciones concretas del cierre.**

```bash
make quality && make test && make mutate && make e2e
php artisan qa:traceability --check     # RF-PA-06..07, RF-KI-07..08, RF-AT-10, RF-AT-12,
                                         # RF-IN-06..08, RF-GP-04, RF-PR-05..06, RN-16,
                                         # RNF-D-01, §9, RS-11
k6 run load-tests/k6/scan-peak.js       # RNF-P-06: 50 fichajes/s, p95 < 150 ms
curl -s http://localhost/metrics | grep -E 'projection_divergence_total|audit_chain_verification_failures_total'   # ambas en 0
# Cada regla de alerta: destinatario asignado y fichero de runbook existente en docs/runbooks/
```

Como esta es la **última fase de desarrollo del producto vendible y operable** (doc 02 §11.1: «Producto vendible y operable · 0 + 1 + 2 + 5 + 3 · 420–554 h»), el cierre incluye además las dos verificaciones que el doc 02 deja fuera de su alcance en la Nota final y que deben cerrarse antes de la primera venta: **validación jurídica** por una asesoría laboral y designación de un **responsable de vigilancia normativa**. Y las tres del §11.0 que no están en las horas: aprender el dominio no, pero sí la **prueba de campo del hardware** —incluida la de resistencia de 12 h en el dispositivo real que exige el Anexo A del doc 02— y el contraste de costes de impresión.

---

## Si hay que recortar esta fase

Del **doc 02 §11.2**, fila literal:

> Fase 3 completa | Aceptable a corto plazo **si** se implementan como mínimo: sonda de salud, alerta de quiosco sin latido y alerta de copia fallida. Sin eso, los fallos los descubre RRHH a fin de mes. **Aviso:** las tareas 3.9 a 3.12 están comprometidas en el documento de presentación al cliente; recortarlas obliga a corregir antes ese documento, no a callarlo

Traducido a tareas de este fichero, el **mínimo irrenunciable** si se recorta:

| Mínimo exigido por §11.2 | De dónde sale |
|---|---|
| **Sonda de salud** | Parte de la tarea **3.1** (§8.1: «Uptime — Sonda interna sobre `/api/v1/health`»), con la comprobación real de base de datos y Redis |
| **Alerta de quiosco sin latido** | Parte de la tarea **3.2** (doc 01 §9.3: «> 10 min, Crítica (operaciones)»), con su runbook `quiosco-no-responde.md` |
| **Alerta de copia fallida** | La regla se implementa en **3.2**, pero la copia verificada y su métrica son de la tarea **2.11** de la Fase 2, que no es recortable |

Y el recordatorio que cierra el asunto: las tareas **3.9, 3.10, 3.11 y 3.12** están prometidas en el doc 05 §5.4, §5.5, §5.7, §4.6 y §12. Recortarlas exige corregir antes el documento comercial, porque el orden de autoridad de `CLAUDE.md` es explícito: el doc 05 «no manda, pero **obliga**: si promete algo que no existe como requisito, o el 01 dice algo distinto de lo que se le contó al cliente, hay que resolverlo antes de seguir, no después».

---

← Anterior: [Fase 5 — Productización](05-fase-5-productizacion.md) · Siguiente: [Fase 4 — Evolución](07-fase-4-evolucion.md) · [Índice](README.md)
