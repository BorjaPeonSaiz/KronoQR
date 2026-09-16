# HANDOFF

> **Resumido el 02-09-2026.** El diario completo de sesiones (Fase 1 → tarea 5.5, ~1600 líneas) vive en
> el historial de git: `git show 9b1593d:HANDOFF.md`. Este fichero conserva solo lo vigente. Al añadir
> sesiones nuevas, mantener la disciplina: estado, pendiente, trampas — sin volcados de verificación ni
> listados de ficheros que git ya sabe.

## Estado y objetivo actual

**Rama `feat/tarea-3.4-vista-cumplimiento` (desde `main` `b57a6e6`, con la 3.3 integrada por PR #64). Tarea 3.4 «Vista de
cumplimiento: descansos, jornada máxima, exceso semanal» (RF-PA-06, RN-10..12 y la nueva RN-17) IMPLEMENTADA, REVISADA (dos
vueltas) y PROBADA el 16-09-2026; ver «Siguiente acción» para commit, CI manual y PR.** Quince decisiones en la ficha (plan 06 →
«Tarea 3.4» → «Decisiones tomadas»; la 15 es «lo que corrigieron las revisiones»). Las que importan: **`GET /api/v1/compliance/summary`**
(tag `Reporting`, ámbito `attendance:read`, policy `{admin, rrhh, responsable_departamento}`, auditor 403, alcance en el `WHERE`
incluidos `meta.totals`, sin paginación, por omisión 28 días hasta hoy, techo `REPORTING_COMPLIANCE_MAX_RANGE_DAYS=92` y
`statement_timeout` de `REPORTING_COMPLIANCE_TIMEOUT_SECONDS=10` → `422`); **RN-17 «Jornada semanal ordinaria» nueva en doc 01 §4**
(informativa: el art. 34.1 ET es cómputo anual; no abre incidencia; `ComplianceRule::opensIncident()`), con Gherkin en §11 y Anexo A;
**los predicados de límite abierto viven en `Shared\...\CompliancePolicy`** y los comparten `AnomalyDetectionPolicy` (sin cambio de
comportamiento) y el evaluador puro `Reporting\Domain\Policy\ComplianceEvaluation`; el SQL trae hechos (`daily_totals`, tramos
vigentes, `lag()` de la jornada anterior partiendo una jornada antes de `from`, semanas del borde completas), no veredictos;
**cuenta lo mismo que la bandeja** (probado contra `DetectAttendanceAnomalies`, con la excepción escrita de RN-08) y **RN-12 no se
cuenta mientras `ComplianceRuleSuspension` la suspenda** (`meta.rules[].evaluated=false`); **la vista recalcula siempre con el
umbral vigente y la bandeja no se reprocesa** (doc 01 §4, contrato, guía §4 bis.6); asiento RS-05 `compliance_summary` sin agrupar y
en el runbook de brecha (`DisclosureDatasetsInventoryTest`); **no degradable** (ADR-023); series textfile
`compliance_findings_last_week{rule}`, `compliance_employees_affected_last_week` y `compliance_metrics_week_start_seconds` por
`reporting:compliance-metrics` (04:45 UTC) con dos paneles nuevos en «Negocio»; el cambio de `max_weekly_hours`/`week_starts_on`
deja `affects_compliance_view` en el asiento y `effect=compliance_view` en la métrica del perfil; solo `holiday_calendar` sigue sin
consumidor. **Panel:** `features/compliance/` en `/compliance` («Cumplimiento», tras «Incidencias»; el perfil pasa a «Perfil de
cumplimiento»), cuatro tarjetas con el umbral en palabras y el nombre del perfil, tabla agrupada por regla con `HH:MM`, enlace al
registro situado en la jornada/semana del aviso (`EmployeeWorkDaysView` lee `from`/`to` de la URL) y a la bandeja acotada a la
persona; **secciones del menú y de la guarda unificadas en `shared/ui/navigation.ts`** (deuda pagada). Guías: `guia-rrhh.md` §4 bis
/ `hr-guide.md` y `configuracion.md` ES/EN. Cifras de la segunda vuelta: backend Unit 1858, Feature 1682, Integration 591,
Contract 60, Architecture 527 (+ `SourceDiscoveryTest` conocido), PHPStan 9 sin errores, Deptrac 0/0, Redocly 0, mutación acotada a
`Reporting/Domain` + `CompliancePolicy` 85,23 %; panel type-check, lint, unit 534, E2E 125 (5 `@RF-PA-06`, axe 0);
`ClientDocumentationTest` 75; `GrafanaDashboardsTest` 37; `docs:consistency` sin divergencias. **Suites completas de backend sobre el
árbol final (Architecture + Unit + Feature + Integration + Contract): 4718 en verde, 20 810 aserciones, 992 s; único rojo el
conocido `SourceDiscoveryTest`.**

**Siguiente acción:** commit único `feat(cumplimiento): …`, CI manual en la rama (sin empujar nada después), PR con *merge commit*.
Sin migración: tras integrar basta `git pull` (y `make up` si se quiere el comando nocturno programado en el contenedor). Después,
la **3.5** (fichaje de pausa y validación de desfase de reloj: vaciar `ComplianceRuleSuspension`, RN-12 vuelve a abrir incidencias
y la vista empieza a contarla sin tocar nada; descanso intra-jornada de RN-10 con la pausa declarada; RF-AT-12).

**Tarea 3.3 (cerrada e integrada, PR #64 `b57a6e6`). Rama `feat/tarea-3.3-salud-quioscos` (desde `main` `931d3de`, con #58, #59 y #63 ya integradas). Tarea 3.3 «Panel de salud de
quioscos y pantalla de diagnóstico» (RF-PA-07, RF-KI-08) IMPLEMENTADA, REVISADA (dos vueltas) y PROBADA el 16-09-2026; ver
«Siguiente acción» para commit, CI manual y PR.** Dieciocho decisiones en la ficha (la 18 es «lo que corrigieron las revisiones»:
la exportación íntegra llevaba el código de servicio en claro → `value_redacted`; un actor de soporte lo leía y cambiaba →
`redacted: true` y 403, contrato aditivo de `InstallationSetting`; el bloqueo 5/60 s vivía en la vista → persistido; abrir el
diagnóstico desde `/pair` perdía `onDeviceRevoked` → suscripción; «servidor alcanzable» congelado y tablet sin latir con la pantalla
abierta → `onReachability` y latido propio; reloj-disparador sin teclado → `keydown`/`keyup`; insignia camuflada con el tinte de la
fila → variante sólida; doc 07 A-9/A-10 exactas). Diecisiete decisiones previas en la ficha (plan 06 → «Tarea 3.3» → «Decisiones tomadas»); las que importan: **el veredicto lo calcula el
servidor con la misma clase que `kiosk:health`** (`KioskHealthRow`; `GET /devices` devuelve `health{verdict,reason,
seconds_since_last_seen}` y `meta{generated_at,timezone,thresholds}`; umbrales siguen en `config/kiosk.php`, nuevo
`KIOSK_HEALTH_BATTERY_LOW_PERCENT=15`, razón nueva `battery_low` solo si no carga); **batería y `oldest_pending_at` persistidos**
(migración expand `2026_09_16_100000`, `--database=pgsql_migrator`); **código de servicio** `SettingKey::KIOSK_SERVICE_CODE`
(8–12 dígitos, vacío = sin código, `SettingDefinition::$confidential`: el asiento de auditoría lleva `value_redacted`, fuera del
paquete y de logs) entregado como `service_code_hash` = SHA-256 de `uuid:código` en cada latido y comprobado en local en la tablet
(5 fallos → 60 s); **sin huella conocida la pantalla abre sin código**; **apertura por pulsación larga de 3 s sobre un reloj NUEVO**
en la cabecera de `ScanView`/`PairingView` (`ClockDiagnosticsTrigger.vue`; las vistas no tenían reloj: corrección a la decisión 8),
ruta `/diagnostics` fuera del guard, retorno automático a 120 s; **panel sin virtualización** (contrato sin paginar, ADR-040), reloj
del servidor extrapolado, «qué hacer» por veredicto nombrando el runbook; **no degradable** (ADR-023). Cuatro agentes en paralelo
(`backend-laravel`, `frontend-quiosco`, `frontend-panel`, `producto-licencia` para `operacion.md` §16 ES/EN, runbooks y doc 07
A-9/A-10) tras ampliar y validar el contrato (Redocly 0) y regenerar los tres `schema.d.ts`. Cifras de los agentes: backend Unit
577, Feature+Contract 911, Architecture 467 (+ los rojos conocidos), PHPStan 9, Deptrac 0; quiosco unit 430, E2E 67, 104 KiB de
250; panel unit 504, E2E dirigidos 52; documentación 75. **Tras la segunda vuelta y QA:** quiosco unit 445 / E2E 61 (diagnóstico 8/8 con márgenes ampliados: 3,6 s de
pulsación, 25 sondeos y 40 s en la prueba de revocación), panel unit 509 / E2E 52, mutación 100 % sobre `KioskHealthRow`,
`KioskHealthThresholds`, `HeartbeatTelemetry` y `ServiceCodeFingerprint` (102 mutantes), `HeartbeatConcurrencyTest` (dos latidos
simultáneos y «cinco columnas en un solo UPDATE»), `LangParityTest` (17 pares `lang/{es,en}` sin exclusiones), documentación 131,
Pint 1842, PHPStan 9 sin errores, Deptrac 0/0, Redocly 0, `docs:consistency`, matriz regenerada (Pest 2894, Playwright 203);
**suites completas de backend sobre el árbol final: Architecture + Unit + Feature + Integration + Contract 4614 en verde**
(978 s; único rojo el conocido `SourceDiscoveryTest`).

**Siguiente acción:** commit único `feat(quioscos): …`, CI manual en la rama (sin empujar nada después), PR con *merge commit*, `make up` en `main` tras integrar
(migración `2026_09_16_100000` con `--database=pgsql_migrator`: el usuario `fichaje_app` no tiene DDL; `make up` ya lo hace).
Después, arrancar la **3.4** (vista de cumplimiento: descansos, jornada máxima, exceso semanal; estrena
`maximumWeeklyMinutes`/`weekStartsOn`/`holidayCalendar` de `CompliancePolicy`; `backend-laravel` + `frontend-panel`).

**Hotfix del 16-09-2026 INTEGRADO en `main`** (PR #62, *merge commit* `a90d163`, commit `3d7e22f`; CI manual 35076362124 en
verde con los 20 jobs, ⑧b y cobertura incluidos; rama borrada; `make up` hecho). **`main` estaba EN ROJO desde la nocturna del
13-09 sin ningún push de por medio** (verde el 11 y el 12; rojas 34747422819, 34825362023, 34948456610 y 35074773680, todas en
el job de cobertura, y las cuatro PRs de Dependabot #58–#61 por arrastre). Causa: `PlanLimitsDoNotBlockTest` instalaba
`FixedClock` de junio, emitía un token de quiosco con `IssueDeviceToken` (caducidad = junio + 90 días) y fichaba; Sanctum comparaba
`expires_at` con el reloj real → 401. **No es defecto de producto**: es una prueba que dependía del calendario (ver Trampas).
Arreglo: `tests/Support/Time/FrozenTime.php` (`FrozenTime::at('…')` instala el `FixedClock` en el contenedor Y congela Carbon en
el mismo instante; el `TestCase` de Laravel devuelve Carbon al reloj real en cada `tearDown`); las 92 vinculaciones manuales
`app()->instance(Clock::class, FixedClock::at(…))` de Feature, Integration y Contract (63 ficheros, incluida la variante por
variable de `RetentionTest`) sustituidas por el helper —`PairingTest` y `PairingAuditTest` eran las siguientes bombas, con reloj
del 07-09 y estallido el 06-12—; `tests/Architecture/FrozenTimeTest.php` prohíbe `->instance|bind|singleton|scoped(Clock::class`
en esas suites y exige que el helper tenga uso; `tests/Feature/Quality/FrozenTimeTest.php` es la regresión directa (token de
quiosco emitido bajo un reloj detenido el 01-01-2026 —la partición más antigua de `audit_log`— sigue valiendo para Sanctum) y
prueba que los dos relojes coinciden; regla nueva en `.claude/agents/qa-testing.md`. Los `FixedClock` pasados a mano a un servicio
(`CachePinAttempts`, `VerifyAuditChain`, `AuditLogTest`) se quedan como estaban: no tocan el reloj del framework.
**Verificado sobre el árbol final:** Architecture + Feature + Integration + Contract 2689 en verde (915 s; el único rojo es el
conocido `SourceDiscoveryTest`), Pint, PHPStan 9 sin errores, `qa:traceability --check` (matriz regenerada) y `docs:consistency`
en verde. **Ver «Siguiente acción».**

**Dos trabajos ad hoc del 10-09-2026, ambos INTEGRADOS en `main`:** (1) **cámara del quiosco «enfoca mal» en local** → no era el
código sino Windows Studio Effects más el foco fijo de la webcam; solo documentación (PR #56, *merge commit* `23b0c52`, CI 34521308593
en verde): runbook `alta-nuevo-quiosco.md` §6, plan 01 §C.2, ficha 3.3 paso 4 (`getSettings()` con `backgroundBlur` en la pantalla de
diagnóstico) y trampa nueva abajo. (2) **Menú lateral del panel** a petición del usuario (PR #57, *merge commit* `1d42373`, commit
`f8e5fa6`, CI manual 34524850028 en verde): `AppShellView.vue` en dos columnas desde `md` —la cabecera es una columna de 16 rem,
sigue siendo el landmark `banner`, activo calculado en el marco con `meta.section` y `aria-current`—, regla 11 en doc 06 §6,
`tests/e2e/shell.spec.ts` nuevo (aria-current desde la ficha; apilado a 700 px con las 13 secciones visibles y axe), 28 capturas
`rrhh-*` regeneradas. La revisión `ui-ux` corrigió dos bloqueantes de AA (anillo de foco sobre la activa; hover crema con texto
blanco); el porqué en Engram `panel/menu-lateral`.

**Rama `main`. FASE 3 EN CURSO. Tarea 3.2 «Los 5 cuadros de mando y el catálogo de alertas con runbooks» IMPLEMENTADA,
REVISADA (dos vueltas), PROBADA e INTEGRADA en `main` el 10-09-2026** (PR #55, *merge commit* `e429e48`; commits `824899e` y
`b7ff175`; CI manual 34509403514 en verde con los 20 jobs, ⑧ y ⑧b incluidos; la primera, 34507978469, cayó en ② porque el `sh` del
runner es `dash` y el renderizador llevaba `set -o pipefail` —ver Trampas—; rama borrada).** Diecisiete
decisiones en la ficha (plan 06 → «Tarea 3.2» → «Decisiones tomadas»); las que importan: **cinco cuadros, no cuatro** (corregido en
doc 02 §11, plan 05 y plan 06), como JSON en `infra/observability/grafana/dashboards/KronoQR/` (subcarpeta porque el provisionador
de Grafana 11.5 ignora `folder:` con `foldersFromFilesStructure`); **las once filas del doc 01 §9.3 tienen regla** (siete ya
existían; nuevas `kiosk.yml`, `api.yml`, `tls.yml`, `host.yml`, `maintenance.yml`, `alerting.yml` y tres más en `projection.yml` e
`incidents.yml`: 36 reglas en 12 ficheros, cada una con umbral, severidad `critical|high|warning|info`, `destinatario`
`it-cliente|rrhh|seguridad`, `component` y runbook que existe); **Alertmanager con destinatarios reales sin nada del cliente**:
`alertmanager.yml.template` + `render-config.sh` (POSIX, entrypoint del contenedor, escapa `'`, rechaza saltos de línea y valida su
salida con `amtool` antes del `exec`) a partir de nueve `ALERT_*` del `.env` (correo por el SMTP del cliente, webhook por `url_file`,
ventana semanal); **anti-fatiga**: ruta de quiosco agrupada por `alertname` (cinco tablets = un aviso), `mute_time_intervals` solo
en `kiosk|api|tls|host`, ventana automática de `update.sh` por `.prom` (`kronoqr_maintenance_active`, tope 2 h) que inhibe esos
cuatro componentes y nunca integridad, copia, auditoría, autenticación, incidencias ni la propia entrega; **paso 9 cerrado**:
`projection_reconciliation_last_failures`, `incident_detection_{last_run_timestamp_seconds,last_failures,last_findings,work_days_inspected}`
(adaptador `TextfileIncidentDetectionMetrics`), `->onFailure(LogScheduledCommandFailure::of(...))` en reconciliación, detección y
retención, y **con `runInBackground()` un código de salida ≠ 0 NO entra en `error_events`** (solo una excepción): la línea
`scheduler.command_failed` es su única traza; semconv `db.system.name`/`db.operation.name`; sonda de `doctor` que avisa por cada
papel sin correo ni webhook; job `kronoqr-backup` → `kronoqr-node`; `promtool test rules` (12 ficheros de umbral y límite) y
`amtool check-config` en `make observability-check` y en el job `architecture` de la CI (presupuesto 90 s). **Lo que corrigieron las
revisiones** (decisión 17): el `env_file: .env` heredado de la 1.18 exponía la clave HMAC del QR y todos los secretos al contenedor
de Alertmanager (ahora `environment:` explícito y prueba «sin env_file»); una comilla en un correo dejaba Alertmanager en
*crash-loop* mudo y un salto de línea inyectaba un receptor; nadie vigilaba a Alertmanager (`EnrutadoDeAlertasCaido`,
`EntregaDeAlertasFallando`, runbook `entrega-de-alertas.md`); la inhibición `critical → warning` con `equal: ["site"]` apagaba todas
las `warning` del producto (acotada a `component="kiosk"`); guía con `ALERT_MAINTENANCE_WEEKDAY=0` cuando solo vale `monday..sunday`.
Tres filas nuevas en doc 07 §6 (A-6, A-7, A-8). Cuatro runbooks nuevos más `entrega-de-alertas.md`; `operacion.md` §10.4 (ES/EN)
con la tabla de las 30 alertas atada por prueba a `rules/*.yml`. **Verificado sobre el árbol final:** Architecture 463 (+ el rojo conocido `SourceDiscoveryTest`, matriz de trazabilidad regenerada), Unit 1761, Integration 563 (`UpdateScriptTest` 26), Feature 1603, Pint 1818, PHPStan 9 sin errores, Deptrac 0/0, ShellCheck/shfmt 0, `promtool check/test rules` (36 reglas, 12 ficheros de prueba) y `amtool check-config` en verde, `docs:consistency`, gitleaks 0 sobre el diff. **En vivo:** 36 reglas cargadas sin error, `up{job="alertmanager"}=1`, Alertmanager arranca con la configuración renderizada, la inhibición acotada suprime la `warning` de quiosco y deja activa la de incidencias, los cinco cuadros cargan en la carpeta KronoQR y `VentanaDeMantenimientoActiva` inhibe `kiosk|api|tls|host` (verificado por el agente A antes del corte).

**Rama `main`. FASE 3 EN CURSO. Tarea 3.1 «OpenTelemetry extremo a extremo, Prometheus, Grafana, Loki» IMPLEMENTADA,
REVISADA (dos vueltas), PROBADA e INTEGRADA en `main` el 10-09-2026** (PR #54, *merge commit* `545d4e2`; CI manual 34475373363
en verde con los 13 jobs, ⑧ y ⑧b incluidos; el primer intento cayó en ⑧ por la lista fija de servicios del perfil `observability` en
`ci.yml`, corregida en `af99732`; rama borrada). Catorce decisiones en la ficha
(plan 06 → «Tarea 3.1» → «Decisiones tomadas»); las que importan: **la instrumentación ya existía en gran parte y faltaba la
costura** —doce series escritas en Redis que nadie exponía, el SDK de OTel instalado sin arrancar, `LOKI_URL` que nadie leía—, así
que `/metrics` es un **lector de exposición** sobre `kronoqr:metrics:*` con catálogo único (`MetricCatalogue`, prueba bidireccional
contra el doc 02 §8.2) y `promphp` solo para renderizar; **Tempo** como destino OTLP (los docs no decían a dónde iban las trazas);
SDK arrancado solo con `OTEL_EXPORTER_OTLP_ENDPOINT`; logs a Loki por un handler de Monolog propio con búfer tras la respuesta,
solo con `LOKI_URL` (vacía de serie); correlación global por `Context` (viaja a los jobs) con `extra` acotado a cinco claves;
`/health` sigue sin dependencias y `/ready` valida BD y Redis, sondeadas por **blackbox-exporter por TLS**; `traceparent` W3C
generado en `web-kit/http.ts` y `kiosk/client.ts` sin SDK web (+0,1 KiB). Las siete series sin emisor: `queue_*` (eventos del
worker y `Queue::size()` en el scrape), `db_query_duration_seconds` (acumulado en memoria, volcado en `terminating`),
`anomalous_patterns_detected_total` (puerto `AnomalyMetrics`), `scans_by_origin_total{qr|pin|manual}` y
`workdays_complete_ratio{site}` (`reporting:adoption-metrics`, `.prom`). **Lo que corrigieron las revisiones** (decisión 14): el
`TrustProxies` de Laravel 13 confiaba en `X-Forwarded-For` con `Host` `.on-forge.com` (IP de `audit_log` y guarda de `/metrics`
falsificables) → `TrustProxies` propio con `TRUSTED_PROXIES`; sondas retiradas del puerto 80; `phpunit.xml` neutraliza OTLP y Loki;
`SafeSpan` desaparece (capa Deptrac `SharedTracingSupport`, `TraceparentHeader` única copia); `autoFlush: false` con
`forceFlush` por job y comando. Seis filas nuevas en doc 07 §6 (A-1..A-5 y B-2). **Verificado sobre el árbol final:** Architecture
339 (+ el rojo conocido `SourceDiscoveryTest`), Unit 1752, Feature 1579, Integration 557, Contract 60, Playwright `traceparent.spec`
3, web-kit 205 / quiosco 380 unitarias, `qa:traceability --check`, `docs:consistency`, Pint 1802, PHPStan 9 sin errores, Deptrac
0/0, shellcheck/shfmt 0, Redocly, `nginx-smoke` 5/5, presupuesto del quiosco 100,4 KiB de 250. **En vivo:** Prometheus con los
cuatro jobs en UP, 24 series en `/metrics`, `probe_success=1`, y una petición con `traceparent` a `/ready` recuperada en Tempo con
`GET health.ready` → `postgresql select`. **Ver «Siguiente acción».**

**Siguiente acción:** `@dependabot rebase` pedido en #58 y #59 el 16-09 tras integrar el hotfix: integrarlas con *merge commit*
si su CI sale en verde; decidir #60/#61 (Vitest 5, ver «Pendiente»); comprobar que la nocturna siguiente de `main` sale en verde.
Después, arrancar la **3.3** (panel de salud de quioscos; no depende de la 3.2, decisión 14). Análisis de huecos de la 3.3 ya
hecho el 16-09: falta `battery_level` en el latido y en `GET /devices` (contrato primero, migración expand, tres `schema.d.ts`),
el resaltado por umbral en el panel (`elapsedSinceHeartbeat` mide contra el reloj del navegador y no hay umbral; decidir cómo
conoce `KIOSK_HEALTH_SILENT_AFTER_SECONDS`), la pantalla de diagnóstico del quiosco con código de servicio (no existe nada;
`frontend-kiosk/src/features/diagnostics/` es un README; el código de servicio es configuración, regla 13, y hoy no hay ninguna
variable), el enlace al runbook `quiosco-no-responde.md`, E2E `@RF-PA-07`/`@RF-KI-08`, autorización negativa y clasificación
ADR-023. Ya existen y no se rehacen: `frontend-admin/src/features/devices/`, `POST /kiosk/heartbeat`, `GET /devices`,
`kiosk:health`, la alerta `QuioscoSinLatido` y el runbook.

**Rama `chore/cierre-fase-5` (desde `main` `9d5ec6f`). FASE 5 CERRADA el 10-09-2026** (`current_phase => 5`, matriz de
trazabilidad regenerada: 2 782 pruebas etiquetadas, Fase 5 con 23 de 23). Los cuatro revisores del doc 03 §6.6 sobre `main`
encontraron **siete bloqueantes reales** y todos se corrigieron en esta rama antes de subir la fase; el detalle está en el plan 05 →
«Cierre de fase» → «Cierre ejecutado» y en doc 07 (SAMM 1,47 → 1,73). Lo que cambia el producto: `doctor.sh` viaja en el paquete;
asientos `system.updated`/`system.restored_from_backup` escritos por `update.sh` (comandos `compliance:record-system-event` y
`compliance:audit-chain-head`); pantalla «Ajustes operativos» (`/settings`) para las seis claves sin vía de cambio;
`completed_at` fuera de la respuesta pública del asistente; `throttle:management` ya en `/credentials`. Lo que cambia la
verificación: la CI gana ④ Integración, ⑥ unitarias de los cuatro paquetes y ⑦ E2E de las tres SPA (el portal estrena 27
E2E con `axe`), cobertura RNF-M-01 como job nocturno (`make coverage` sin OOM), `SettingsSurfaceTest`,
`TraceabilityMatrixFreshnessTest`, `SourceDiscoveryTest` (testigo del *bind mount*, rojo en local a propósito) y las pruebas de
arquitectura recorren `app/` con `scandir`. **Verificado sobre el árbol final:** Architecture 325/326 (el rojo es
`SourceDiscoveryTest`), Unit 1662, Integration 92 de Compliance + 5 de volumen, Contract 60, `AuthorizationNegative` 225,
Pint/PHPStan 9/Deptrac 0/Redocly, `qa:traceability --check` con fase 5, `docs:consistency`, panel type-check/lint/unit 469/E2E 113,
portal unit 79/E2E 27, paquete con 407 enlaces y `doctor.sh`, shellcheck/shfmt, `ci.yml` con 13 jobs válido, gitleaks 0. **Las
suites completas de backend (4 010) y el MSI (82,83 %) son de la revisión de QA sobre `9d5ec6f`**; la CI manual de esta rama
los repite. **La 5.11b está INTEGRADA en `main`** (PR #52, *merge commit* `9d5ec6f`, CI manual 34400365952 y CI de `main`
34402932670 en verde con ⑧ y ⑧b; `make up` hecho; ramas borradas, también las 14 locales ya fusionadas).

**Tarea 5.11b «Guía de RRHH, guía del portal y hoja del empleado» (RL-05, RF-PA-*, RF-IN-*)**, commit `7fb784d` (más `57c5079`
con Engram). Catorce decisiones en la ficha (plan 05 → «Tarea 5.11b»); las que importan: **la hoja la produce el producto**
(`GET /api/v1/credentials/instructions-sheet?locale=`, PDF A4 de una cara con marca, dirección del portal e idiomas activos; fila 17
de «Puntos no cubiertos»), **el panel no podía corregir tramos (RF-PA-04) y se construyó `CorrectionDialog`** (decisión 13), y las
revisiones fijaron `type` propio para los tres `409` y el `422` de la corrección y `throttle:management` en `/credentials`
(decisión 14). Seis agentes en paralelo + tres traducciones + dos revisores + segunda vuelta con tres agentes; prompt en doc 03
§6.5.8. **Verificado sobre el árbol final:** Architecture 291 (73 de `ClientDocumentationTest`), Contract 60, Identity+Attendance
312, AuthorizationNegative 225, InstructionsSheet 29 (6 con Chromium real), Pint/PHPStan 9/Deptrac/Redocly limpios, `qa:traceability`
y `docs:consistency` en verde, panel type-check/lint/unit 468/E2E 103, portal unit 79, quiosco type-check/lint, paquete con 407
enlaces resueltos, gitleaks 0 sobre los ficheros cambiados. **Ver «Siguiente acción»** para CI manual y PR.

**Rama `feat/tarea-5.12-historico-errores`** (desde `main` `4f3f97b`). **Tarea 5.12 «Histórico de errores en base de
datos» (RF-PD-15) IMPLEMENTADA, REVISADA (dos vueltas), PROBADA e INTEGRADA en `main` el 09-09-2026** (PR #51, *merge
commit* `4c8e52d`; la PR #50 quedó cerrada sin integrar y GitHub no dejó reabrirla; CI manual completa con ⑧ y ⑧b en verde
antes de integrar, ejecución 34369140085). Rama borrada; `make up` hecho sobre `main` (migración `error_events` aplicada).
**La 5.11b (guía de RRHH, portal y hoja de la tarjeta) NO se ha ejecutado**: es la última tarea de la Fase 5.

**Cómo se hizo (receta de la 5.11, con una diferencia).** Antes de lanzar nada: catorce decisiones en la ficha (plan 05 →
«Tarea 5.12», «Decisiones tomadas»), fila 16 de «Puntos no cubiertos», contrato (`GET /diagnostics/errors`,
`POST /diagnostics/errors/{id}/resolve`, `POST /client-errors`, `client_errors` en el latido y `client_errors_accepted` en su
respuesta), los tres `schema.d.ts` regenerados, lista de rutas de `OpenApiContractTest`, **piezas compartidas en `Shared`**
(`ErrorSource`, `ErrorLevel`, `ErrorReport`, `ClientErrorCode`, puerto `ErrorEventSink`) y dos métodos reservados en
`ProductServiceProvider` (`registerErrorHistory()` A, `registerErrorCapture()` B). Cinco agentes en paralelo:
`producto-licencia` (A), `backend-laravel` (B), `frontend-panel` (C: panel + `web-kit`), `frontend-quiosco` (D),
`devops-observabilidad` (F: alerta, runbook y docs). **La diferencia:** `revisor-codigo` y `seguridad-cumplimiento` encontraron
tres bloqueantes reales y hubo **segunda vuelta con los cinco**, reanudados por `SendMessage` (los cinco se cortaron a la
vez por el límite de sesión y se reanudaron sin perder contexto). Prompt en doc 03 §6.5.7.

**Lo construido.** Backend: tabla `error_events` (migración `2026_09_13_100000`, `fingerprint` UNIQUE, CHECK de `level` y
`source`), `ErrorFingerprint`/`ErrorMessageNormalizer`/`ErrorMessageSanitizer`/`ErrorContextAllowlist` (23 claves derivadas de
lo que emiten los clientes y el servidor; `message` se eleva a columna), `RecordErrorEvent` (implementa el sink; nunca lanza;
`INSERT … ON CONFLICT` con `GREATEST`/`LEAST`, reapertura solo con ocurrencia posterior a `resolved_at`, `RETURNING xmax = 0`
para la métrica de grupos, por la conexión propia `error_events`), techo de grupos por origen con grupo `overflow`,
`ListErrorEvents`, `ResolveErrorEvent` (idempotente, sin asiento), `PruneErrorEvents` (lotes), `product:errors` (exit 0/1/2)
y `product:errors:prune` (03:35 UTC), `RedisErrorMetrics` (`application_errors_total` y
`application_error_groups_opened_total`), `ErrorEventsCollector` real y `error_events` en la exportación íntegra, sonda
`app.error_history` de `doctor`, `ErrorEventPolicy` (soporte lee, no resuelve; `resolved_by` nulo para soporte),
`throttle:client-errors` (`PRODUCT_CLIENT_ERRORS_RATE_LIMIT=12`). Captación: `Product/Infrastructure/Capture`
(`ExecutionContext` con oyentes de cola/planificador/consola, `ServerErrorReporter` con `reportable`: es error lo que el
manejador respondería con `5xx`; `QueryException` compuesta con `getSql()`, `Failing row` cortado; `trace_id` de OTel o
`traceparent`). Kiosk: `client_errors` en el latido validado contra `ClientErrorCode`. Frontends: `web-kit/clientErrorTransport`
(drena al autenticarse vía `notifyAuthenticated()`, cada 60 s con pendientes y en `pagehide` con `keepalive`),
`frontend-admin/src/features/errors` (ruta `/errors`, filtros, «qué hacer» por origen × nivel es/en, resolver con
confirmación), quiosco con reporter único por tablet, `client_errors` en el latido, `acknowledge(accepted)` y `400` distinguido
por campo. Observabilidad y docs: `errors.yml` (`ErroresCriticosNuevos` sobre grupos nuevos), runbook `errores-en-el-panel.md`,
`operacion.md` §15 ES/EN, `configuracion.md` (dos variables nuevas), `endurecimiento.md` (holgura de `max_connections`), doc 02
§8.2 y Anexo B, doc 07 §5/§6 (tres riesgos abiertos y cerrados en la misma tarea, dos aceptados), ficha 5.12 con 15 decisiones.

**Lo que corrigieron las revisiones (aplicado):** claves de contexto de los clientes ausentes de la lista de permitidos (los
errores llegaban sin mensaje y todos los `web.vue_error` colapsaban en una fila); `last_seen_at` que retrocedía con un reloj
de tablet atrasado; mensaje de `QueryException` con los valores enlazados en claro (nombres, bcrypt de un PIN) más la segunda
fuga `DETAIL: Failing row contains (…)`; exclusión por espacio de nombres que dejaba 50 excepciones sin traducción fuera del
histórico; cualquier sesión de portal fabricaba `critical` con un código de quiosco y disparaba la alerta; sin techo de grupos;
alerta sobre ocurrencias en vez de grupos nuevos; purga sin lotes; `DiscardingErrorEventSink` inalcanzable; puente de
transacción en pruebas fichero a fichero (ahora global, con `DB::purge` en `afterEach` para convivir con `CommittedDatabase`);
`resolved_by.name` servido a soporte; `code`/`exception_class`/`file` sin truncar; `product:errors` con exit code de la
primera página; sondeo de 1 s en el transporte web; `400` del latido que vaciaba el buffer por cualquier campo.

**Hallazgo grave del entorno, corregido:** sobre el *bind mount* de Docker Desktop, `RecursiveDirectoryIterator` recorriendo
`tests/Feature` perdía 38 de los 41 ficheros de `tests/Feature/Product` sin avisar: **~430 pruebas de 5.3–5.12 no se
ejecutaban en local** (la CI en Linux sí) y las cifras «suite completa en verde» de sesiones anteriores eran incompletas.
`phpunit.xml` declara ahora la suite Feature por subdirectorio y `tests/Architecture/TestDiscoveryTest.php` compara lo
descubierto con `scandir` (ver «Trampas»).

**Verificado el 09-09 tras la segunda vuelta:** suite completa **3927 en verde** (17 312 aserciones, 686 s), `make quality`
(contrato 0, Pint, PHPStan 9, Deptrac 0), `qa:traceability --check`, `docs:consistency --check`, `ClientDocumentationTest` 30,
gitleaks 0 sobre los ficheros cambiados, promtool sobre `errors.yml`, `check-package-links.sh` (305 enlaces), `type-check` y
`lint` de los cuatro paquetes, unitarias web-kit 199 / panel 433 / quiosco 379 / portal 79, E2E panel 88 y quiosco 50. **CI manual 34369140085 en verde**: MSI 82,83 % (2 440 mutantes, 10 min en paralelo).

**CI manual completa EN VERDE al octavo intento (34424860230: los 13 jobs, ④/⑥/⑦/cobertura y ⑧b incluidos).** Los siete anteriores destaparon
lo que solo destapa la **primera ejecución real** de ④/⑥/⑦/cobertura y de ⑧b con los asientos, y todo se corrigió en la rama
(commits `19c16f0` cierre, `test(panel)`, `ci(cierre-fase-5)`, `ci(cobertura)`): una unitaria frágil del alta de TOTP (`vi.waitFor`);
«Cannot find module 'puppeteer'» en los PDF con motor real —el `chromium-browser` del runner es un envoltorio de snap: puppeteer trae
su Chrome y `LARAVEL_PDF_CHROME_PATH`/`LARAVEL_PDF_NODE_MODULES_PATH` apuntan a él en ④ y en cobertura; los `hayChromium()` respetan
la variable—; U1/U3 de ⑧b esperaban `audit_log` idéntico y ahora esperan exactamente un asiento `system.*` más; y las dos E2E del
PIN del quiosco con 400 ms de retraso que el runner no llegaba a ver (ahora 1200 ms); `QualityGatesTest` exigía el `cmp` de conteos
que U1 ya no usa; y el E2E del asistente buscaba «Recepción» con `getByText` y en modo estricto coincidía también con la pista
«Cocina, recepción, pisos…» durante un instante (ahora dentro de `department-list`); y en U3 el asiento `system.restored_from_backup`
se intentaba escribir con la imagen ANTERIOR, que no tiene el comando: lo escribe ahora la imagen nueva con
`compose_new run --rm --no-deps` contra la base restaurada (el esquema de `audit_log` es el de la 1.14); y el verificador de la
versión ANTERIOR (2.1.0) reventaba con `AuditAction::from()` ante la acción nueva: desde esta versión la lectura de `audit_log`
tolera acciones desconocidas (`AuditActionName`, aviso y gauge `audit_chain_unknown_actions`, `AuditChainReadPathTest`), y
`update.sh` NO escribe el asiento de la vuelta atrás si la versión restaurada no conoce la acción (lo deja en el informe; U3 de
⑧b exige una rama u otra según la versión anterior). El séptimo intento dejó en verde todo salvo ⑧b, cobertura incluida. **El job de cobertura corre en cada disparo
manual** (además del nocturno): ~15 min más por CI manual. **Siguiente acción:** PR #53 integrada con *merge commit*, `make up` en `main`, rama borrada; **CI de `main` tras el merge en verde** (CI 34426908040: los 13 jobs, ③ 24 min, ⑧b 7,6 min; simulacro de restauración 34426907845 en verde). Empieza la
**Fase 3** (plan 06: 3.1 observabilidad, 3.2 alertas y cuadros, 3.3 quioscos, 3.4/3.5 cumplimiento, 3.6 carga, 3.7 pruebas de
abuso, 3.8 pentest, 3.10 ausencias) con los restos de «Pendiente» → «Cierre de la Fase 5» y las filas del doc 07 §6 fechadas
«Fase 3».

**Rama `feat/tarea-5.11-documentacion-cliente`** (desde `main` `e2860be`). **Tarea 5.11 «Documentación de instalación,
operación, configuración y obligaciones legales» (RL-16..RL-21, RF-PD-02) IMPLEMENTADA, REVISADA, PROBADA e **INTEGRADA en
`main` el 09-09-2026** (PR #49, *merge commit* `4f3f97b`; CI manual completa con ⑧ y ⑧b en verde antes de integrar,
ejecución 34291785329). Rama borrada; `make up` hecho sobre `main` (sin migraciones nuevas).

**Cómo se hizo (receta de la 5.10):** siete decisiones escritas en la ficha ANTES de nada, y cinco agentes en paralelo con
ficheros disjuntos: `producto-licencia` ×2 (guía de endurecimiento + huecos de «qué hacer si…»; referencia completa del
`.env` en `configuracion.md`), `qa-testing` (`ClientDocumentationTest` + `check-package-links.sh` con imágenes),
`frontend-panel` y `frontend-quiosco` (generadores de capturas sobre los dobles del E2E). Después el orquestador insertó
las capturas en `instalacion.md` §1.7 y en el runbook del quiosco, y lanzó **cinco traducciones al inglés en paralelo**
(`general-purpose`, una por documento) sobre los textos ya cerrados; por último `revisor-codigo` y
`seguridad-cumplimiento` (con `/revision-cumplimiento`, que además actualiza el doc 07). Prompt en doc 03 §6.5.6.

**Lo construido.** `docs/cliente/endurecimiento.md` (645 líneas: exposición de red por ruta, TLS, anfitrión, secretos,
copias, tablets, correo, cuentas, observabilidad, qué revela el borde, lista de comprobación trimestral de 22 filas; cada
control con «Cierra/Dueño»); `configuracion.md` §6 (las 162 variables de `.env.example` por familia, con marca, valor de
serie, cuándo cambiarla y «¿afecta al cálculo de horas?», más las 9 claves de `installation_settings`); `instalacion.md`
(§0 «El punto de fichaje», §1.7 con 13 capturas, §5 con Docker ausente/antiguo, disco insuficiente y remisión al
runbook para cámara/servidor/código, §9 enlaza el endurecimiento, cabecera sin promesas); runbook `alta-nuevo-quiosco.md`
§6 (cámara: `Permissions-Policy: camera=(self)`, origen `https`; la tablet no encuentra el servidor) y captura de la
tablet; `obligaciones-legales.md` cita RL-19; `operacion.md` fila trimestral de endurecimiento. **Inglés:**
`docs/cliente/en/{installation,operation,configuration,legal-obligations,hardening}.md` (los runbooks siguen solo en
español, enlazados con «(in Spanish)»). **Capturas:** `docs/cliente/img/{es,en}/` (12 del asistente + 2 de la tablet por
idioma, 2,3 MB, sin PII: «Hotel Marina», «Youssef Amrani») y sello `img/VERSION`; se regeneran con
`npm run docs:screenshots` en `frontend-admin` y `frontend-kiosk` (`playwright.screenshots.config.ts`, `tests/screenshots/`;
el E2E normal no los recoge; `stubOnboardingApi` gana `locale`, `stubPairing` vive en `support/pairing.ts`).
**Pruebas:** `tests/Architecture/ClientDocumentationTest.php` + `Support/ClientDocs.php` (27 casos: `.env.example` ↔
`configuracion.md` en las dos lenguas y en las dos direcciones, `SettingKey` ↔ guía, RL-16..21, pares ES/EN con los mismos
comandos `bash` sin comentarios y el mismo número de apartados, imágenes que existen y ≥ 8 por guía, sello `img/VERSION`
= `VERSION` mayor.menor, endurecimiento enlazado, enlaces de la guía inglesa, sin secretos de aspecto real);
`check-package-links.sh` comprueba también imágenes. **Docs:** ficha 5.11 (siete decisiones), doc 02 §11.6.1 (árbol con
`cliente/`, `en/`, `img/`), doc 03 §6.5.6, doc 07 (por `seguridad-cumplimiento`).

**Defectos de producto encontrados al documentar (los dos primeros corregidos):** (1) `config/security.php` leía
`SECURITY_REJECTION_FLOOR_MS` y `.env.example` documentaba `IDENTITY_CREDENTIAL_REJECTION_FLOOR_MS` → el suelo de
tiempo constante (RS-03) no era configurable; alineado al nombre publicado. (2) El texto del panel del alcance de
soporte `configuration` (`locales/es,en.json`) prometía cambiar el perfil de cumplimiento cuando
`ComplianceProfilePolicy` lo prohíbe; corregido. (3) **No hay baja de cuentas de gestión** (`users.is_active` existe y
nada lo pone a `false`; tampoco cambio de contraseña por consola): la lista de comprobación de endurecimiento lo declara
y remite al fabricante — decisión de producto pendiente (abajo). (4) `ATTENDANCE_PATTERN_WINDOW_SECONDS` y
`ATTENDANCE_PATTERN_MIN_REPEATS` no las lee nada hasta la 3.11 (documentadas como reservadas); `LOKI_URL` es informativa
(el origen de Grafana está fijo en el aprovisionamiento).

**Lo que corrigieron las revisiones (aplicado):** `revisor-codigo`: `kiosk:health` citado en tres runbooks, en la lista de
endurecimiento y en el Anexo C **sin existir** → implementado (`KioskInfrastructureConsoleKioskHealthCommand`, caso de
uso `CheckKioskHealth`, veredicto por quiosco con umbrales de latido) y prueba nueva que contrasta todo `artisan x:y` citado
en `docs/cliente` y `docs/runbooks` con las `$signature` del árbol; `cd /opt/kronoqr` → `/opt/kronoqr-2.1.0` (ocho bloques);
`operacion.md` §14 «Lo que no se toca nunca» (SQL directo, `audit_log`, `daily_totals` → `attendance:reconcile`, secretos
generados, `migrate:rollback`); tabla de la tablet solo en el runbook (referencia única); `quiosco-emparejado.png` enlazada;
`vite build` antes de las capturas del quiosco; `history -c` retirado; gravedad en el resumen nocturno; regex de claves del
`.env.example` que ya no toma prosa por declaración (`PORTAL_INTERNAL_ONLY` fantasma fuera, 161 variables); comprobación
directa contra la tabla del §6 y en las dos lenguas; identificadores en inglés en las pruebas; `check-package-links.sh` cuenta
`.Png`. `seguridad-cumplimiento`: fila 13 de la lista → `backup:verify` (`doctor` no mira la copia); fila 8 y §3 →
`doctor.sh` solo comprueba el `.env` con la aplicación parada; fila 17 → `psql` con `fichaje_app`, nunca `fichaje_migrator`;
`obligaciones-legales.md` sin cabecera de proceso interno, con descargo global y sin remitir al doc 07 (no viaja);
`KIOSK_BATCH_MAX_SIZE` con su consecuencia (422 y cola que no drena); doc 07 actualizado (cuatro riesgos siguen aceptados
con la guía como control, «Gestión del entorno» se queda en 2 con el motivo escrito, riesgo nuevo de baja de cuentas,
defecto RS-03 cerrado).

**Verificado el 08-09:** suite `Architecture` 63 en verde (27 de documentación), `docs:consistency --check`,
`qa:traceability --check`, Pint y PHPStan 9 sobre las pruebas nuevas, `make sh-lint` 0, paquete armado con `package.sh` y
`check-package-links.sh` (267 enlaces, 27 imágenes, todos dentro), gitleaks sobre los ficheros de la tarea (0), panel y
quiosco `lint`/`type-check`, E2E `pairing` 3/3, `--list` del E2E sin los generadores. **suite completa del backend 3639 en verde** (16 454 aserciones, 600 s; `--parallel` no vale: una prueba antigua
redefine la constante `AHORA`).

**`kiosk:health` (nuevo, por el hallazgo):** `KioskDomainValueObjectKioskHealth*` + `CheckKioskHealth` + `KioskHealthCommand`
(`--json`, `--lang`; exit 0/1/2 como `product:doctor`); umbrales `KIOSK_HEALTH_FRESH_WITHIN_SECONDS=120` (lo que el runbook
prometía) y `KIOSK_HEALTH_SILENT_AFTER_SECONDS=600` (el «quiosco sin latido > 10 min» del doc 01 §9.3, para que consola y
observabilidad digan lo mismo); sin ningún quiosco activo sale 1; recién emparejado sin latido = aviso; revocado no cuenta.
21 unitarias + 11 feature. **La regla de Prometheus «quiosco sin latido» sigue sin escribir (3.2)**: hoy este comando es la
única detección.

**Siguiente acción:** comprobar que la CI de `main` tras el merge (con ⑧ y ⑧b) termina en verde y arrancar la **5.12**
(histórico de errores en el panel y transporte del `errorReporter`; el punto 6 de la ficha 5.9 y el 5.12 de «Pendiente»
ya lo anticipan). Commits de la 5.11: `c7cee70` (tarea), `e3ca363` (lock: aviso nuevo de `js-yaml`, ver «Trampas») y la
primera CI manual, 34288448585, cayó solo en `npm audit` por ese aviso.

**Rama `feat/tarea-5.10-exportacion-telemetria`** (desde `main` `2f7f2cc`). **Tarea 5.10 «Exportación íntegra de
datos y telemetría opcional desactivada por defecto» (RF-PD-12, RF-PD-14, RL-20) IMPLEMENTADA, REVISADA, PROBADA e
**INTEGRADA en `main` el 08-09-2026** (PR #48, *merge commit* `e2860be`; CI manual completa con ⑧ y ⑧b en verde
antes de integrar, ejecución 34268942894 —la primera, 34268124784, falló en ① porque solo se había regenerado el
`schema.d.ts` del panel—; CI de `main` tras el merge, ejecución 34271875959). Rama borrada; `make up` hecho sobre `main`
(migración `data_exports` aplicada).

**Cómo se hizo (receta de la 5.9):** diez decisiones escritas en la ficha ANTES de nada (punto 14 de «no
cubiertos» resuelto), contrato (`/api/v1/data-export` GET/POST, `/api/v1/data-export/{uuid}/download`), tipos
regenerados, bloques `RESERVADO 5.10-A/B` en siete ficheros compartidos, y tres agentes en paralelo:
`producto-licencia` (exportación), `backend-laravel` (telemetría), `frontend-panel` (sección «Tus datos son
tuyos» en Licencia). **Los tres se cortaron por el límite de sesión antes de escribir nada y se reanudaron con
`SendMessage` sin perder contexto.** Después `revisor-codigo` y `seguridad-cumplimiento`
(`/revision-cumplimiento`) en paralelo con la suite completa, y segunda vuelta de los tres con los hallazgos.
Prompt en doc 03 §6.5.5.

**Lo construido.** Backend: tabla `data_exports` (índice único parcial «una sola en curso», CHECK de estados y
de `failure_reason`), `DataExportCatalog` (18 conjuntos por `FieldAllowlist`, sin ningún hash/secreto ni
`BIGINT` salvo `audit_log`, cuya huella los necesita), cursor de servidor en `REPEATABLE READ`, ZIP con CSV
(`CsvDialect`) + JSON + `manifest.json` + `README.md` en el idioma de la instalación (`lang/*/data-export.php`,
cada columna descrita y atado por prueba), `product:export-all [--purge]` (síncrono; `--purge` horario borra ZIP
caducados y libera filas atascadas `stale`), `GenerateDataExportJob` (asíncrono desde el panel, `failed()`),
`DataExportPolicy` (admin y nunca actor de soporte), `throttle:data-export` (30/min por cuenta, ×4 por IP),
auditoría `data_export.requested|generated|downloaded` en familia `legal_export` con actor = quien la pidió
aunque escriba la cola, `ByteSize::human()` en `Shared`. Telemetría: `TelemetryReport` (19 campos cerrados,
atados por prueba a `configuracion.md` §3 quinquies), cuatro condiciones (`TELEMETRY_ENABLED`,
`TELEMETRY_ENDPOINT` **https**, `telemetry` en la licencia), `HttpTelemetrySender` (3 s/10 s, sin
redirecciones, un reintento), `FileTelemetryStateStore` (`installation_id` aleatorio; `provisional()` para la
vista previa sin escribir), `product:telemetry [--send] [--json] [--lang]`, lunes 05:40 UTC,
`Feature::Telemetry` implementada, `OutboundChannelsTest` (único cliente HTTP saliente). Panel:
`DataExportPanel.vue` en `LicenseView` (sondeo 5 s solo con una en curso, aviso `role="alert"`, 409 normalizado,
códigos de fallo traducidos, unidades binarias). Docs: contrato, ficha 5.10 (11 puntos), doc 01 §5.5, doc 02
(Anexo B/C, §7.3 nota 6, §11.6.7), doc 03 §6.5.5, doc 07 §6 (cinco filas), `operacion.md` §1 y §13,
`obligaciones-legales.md` §2 (telemetría), §7 quater y §8, `configuracion.md` §3 quater y §3 quinquies.

**Decisiones que hay que conocer** (ficha, «Decisiones tomadas»): asíncrona desde el panel porque
`fastcgi_read_timeout` es 60 s; una sola en curso; fichero que caduca a 7 días, fila que nunca se borra;
`settings:*` + policy; nunca degradada; `failure_reason` es un código (`write_failed|database_error|stale|
unexpected`); `TELEMETRY_ENDPOINT` no viaja en el paquete de diagnóstico a propósito; `usage_7d` es la
diferencia desde el último envío correcto (las series Redis son acumuladas).

**Lo que corrigieron las revisiones (aplicado):** fila atascada bloqueaba RL-20 (tres redes: `failed()`,
`complete()` dentro del `try` borrando el ZIP huérfano, obsolescencia `PRODUCT_DATA_EXPORT_STALE_AFTER`);
instantánea única (`REPEATABLE READ`, solo con `transactionLevel() === 0`; `DataExportSnapshotTest` con
`CommittedDatabase` y segunda conexión); prueba de volumen que mide el incremento (48 MiB) y no el pico absoluto
(dentro de la suite completa el proceso ya arranca con 138 MiB); `https` obligatorio; vista previa sin
persistir; `notice` si el estado no se puede guardar; descarga probada para quiosco y portal.

**Verificado el 08-09:** suite completa **3612 en verde** (16 413 aserciones, 627 s), `make quality` (ShellCheck
0, contrato 0, Pint, PHPStan 9, Deptrac 0), `qa:traceability --check`, `docs:consistency --check`, gitleaks
sobre el árbol (0), enlaces del paquete (98, todos dentro), panel `type-check`/`lint`, 410 unitarias y E2E
completa 80/80 (más 32 tras la segunda vuelta). A mano en el contenedor: `product:export-all` sobre la BD de
dev (ZIP inspeccionado, permisos 0600/0700), fila `running` de 3 h liberada por `--purge`, `product:telemetry`
con destino `http://` rechazado y `storage/app/telemetry` vacío.

**Siguiente acción:** la CI de `main` tras el merge (34271875959, con ⑧ y ⑧b) terminó en verde. Arrancar la **5.11** (documentación de
instalación, operación, configuración y obligaciones; capturas del asistente; guía de endurecimiento). Recordatorio: **la ⑧b solo corre en `main`, etiquetas o a mano**.

**Rama `feat/tarea-5.9-diagnostico-doctor-soporte`**, creada desde `main` (`d2fe595`: PR #44, #45 y #46
integradas el 08-09-2026 con la CI de `main` completa en verde, ⑧ y ⑧b incluidas, y Node 25 en la imagen de
nginx). **Tarea 5.9 «Paquete de diagnóstico anonimizado, `product:doctor`, accesos de soporte auditados»
(RF-PD-09, RF-PD-11, RF-PD-13, RL-18, RL-19) IMPLEMENTADA, REVISADA, PROBADA e **INTEGRADA en `main` el 08-09-2026** (PR #47, *merge commit* `2f7f2cc`;
CI manual completa con ⑧ y ⑧b en verde antes de integrar, ejecución 34232924735; CI de `main` tras el merge,
ejecución 34242209054). Rama borrada; `make up` hecho sobre `main`.

**Cómo se hizo (misma receta que la 5.8, con una diferencia):** contrato primero (cuatro rutas y ocho esquemas),
tipos regenerados, **bloques reservados con comentario** en `ProductServiceProvider` y `routes/api_v1.php`
para que dos agentes de backend editaran los mismos ficheros sin pisarse, y cuatro agentes en paralelo
(`producto-licencia`: doctor y paquete; `backend-laravel`: `support_grants`, tokenable en Identity, familia
de auditoría; `devops-observabilidad`: `doctor.sh` y enganches; `frontend-panel`: pantalla «Soporte»). Después
`revisor-codigo` y `seguridad-cumplimiento` (con `/revision-cumplimiento`), y segunda vuelta de A, B y C con
los hallazgos. Prompt en doc 03 §6.5.4. **Dos agentes se cortaron por el límite de sesión a media
verificación y se reanudaron con `SendMessage` sin perder contexto.** La BD de pruebas compartida entre
agentes produce fallos esporádicos (`relation does not exist`, deadlocks de `migrate:fresh`): no son defectos,
se repite el filtro.

**Lo construido.** Backend: `product:doctor` (22 comprobaciones en 8 familias, `--json`, `--lang`, códigos
0/1/2, idioma único resuelto `--lang` → `LOCALE_DEFAULT` → `APP_LOCALE`; la licencia nunca pasa de aviso;
con la BD caída informa y sigue), `product:diagnostics` (`--with-personal-data`, `--period-days`, `--output`,
`--verify=RUTA`; purga los paquetes de más de `PRODUCT_DIAGNOSTICS_RETENTION_DAYS` al arrancar), `POST
/api/v1/diagnostics/bundle` (`diagnostics:*`, `throttle:diagnostics` 3/min, descarga como fichero), paquete
en un único JSON por **lista de permitidos** sección a sección (`FieldAllowlist`,
`DiagnosticsConfigurationAllowlist` con guarda de forma de secreto, `UpdateReportAllowlist` por líneas del
formato de `update.sh`, `SettingsDrift` compartido por sonda y recolector, `MetricsCollector` con `SCAN`),
`manifest.sha256` sobre JSON canónico (`CanonicalJson`), `UtcInstant` en `Shared/Domain` como único formato
de instante. Accesos de soporte: `support_grants` (migración + expand del CHECK `actor_type`),
`SupportGrant` como cuarto *tokenable* (implementa `ManagementActor`, `Authenticatable`, `Authorizable` y
**no** `HasRoles`), `SupportScope` (`diagnostics` | `read_only` | `configuration`, todos actúan como
`admin`; los ámbitos limitan la escritura y las policies cierran lo que el ámbito alcanza: `LicensePolicy`,
`SetupPolicy`, `ComplianceProfilePolicy::update`, `SupportGrantPolicy`, `DiagnosticsPolicy::includePersonalData`
rechazan `isSupportActor()`), `SanctumSupportTokenIssuer` en Product, middleware global `RecordSupportAccess`
(resuelve el caso de uso **tarde** para que `/health` no dependa de la BD; ventana de 900 s), `support:grant
--as=` y `support:revoke {uuid}|--all`, `GET/POST /support/grants`, `DELETE /support/grants/{uuid}`
(idempotente, un solo asiento en carrera), `LogoutController` ignora tokens de soporte. Compliance: familia
`support_access` (décima), actor `support_grant`, acciones `support_grant.granted|used|revoked`,
`diagnostics.bundle_generated`, `diagnostics.personal_data_included`. Scripts: `doctor.sh` (0/2/3/6; delega en
`product:doctor` si `app` está en pie —presencia por `artisan list --raw`—; desde fuera: Docker, `compose ps`,
`.env` 0600, disco por proporción como `DiskProbe`, certificados, puertos), `install.sh` (2 → 6, 1 aviso) y
`update.sh` (doctor **informativo**: va al informe, nunca deshace; solo aborta si el comando no existe).
Panel: `features/support` (`/support`, nav «Soporte», `SUPPORT_MANAGE`/`DIAGNOSTICS_MANAGE`), descarga del
paquete con casilla de datos personales y `role="alert"`, concesión con token mostrado una vez y copia,
lista y revocación con confirmación; `web-kit/http` admite `DELETE`. Docs: contrato, ficha 5.9 (diez
decisiones; puntos no cubiertos 12 y 13 resueltos), doc 01 §5, doc 02 Anexo C y §12, doc 03 §6.5.4, doc 07
§5 y §6 (dos riesgos cerrados, cuatro nuevos), runbook `incidencia-sin-acceso.md`, `operacion.md` §12,
`configuracion.md` 3 quater, `instalacion.md`, `obligaciones-legales.md` §8.

**Decisiones que hay que conocer** (todas en la ficha 5.9): (1) tres alcances, todos como `admin` ante las
policies; **ninguno** activa licencias, concede accesos, toca credenciales, corrige fichajes, modifica el
perfil de cumplimiento, completa el asistente ni incluye datos personales; (2) el token de soporte se enseña
**una vez** y solo se guarda su hash; caducidad efectiva por `expires_at` del token; (3) `support_grant.used`
como máximo uno por concesión cada 900 s; (4) paquete **sin cifrar** para que el cliente lo inspeccione;
`--verify` recalcula la huella; (5) `personal_data.employees` solo con actividad en el periodo o incidencia
abierta; (6) `error_events` → `not_installed` hasta la 5.12; (7) las rutas del cliente (`BACKUP_PATH`,
`BRANDING_LOGO_ROOT`) sí viajan: identifican a la organización, no a una persona (doc 07 §6).

**Lo que corrigieron las revisiones (todo aplicado y re-verificado):** `KEYS` → `SCAN` en Redis; drift
`.env`/BD unificado; informe de actualización filtrado por líneas; purga de paquetes en disco; guarda de
secretos por forma (cazó cuatro magnitudes `IDENTITY_*_TOKEN_*`, excepcionadas una a una); plantilla
minimizada; `LicensePolicy` y `ComplianceProfilePolicy` cierran al actor de soporte; `SupportScopeTest`
ata el catálogo de ámbitos; revocación en carrera con un solo asiento; `/auth/logout` con token de soporte
→ 204 sin efecto; `whereUuid`; CHECK de `scope` atado a `SupportScope::names()`; doctor informativo en
`update.sh`; disco de `doctor.sh` por proporción; `/health` sin BD (el middleware resolvía el caso de uso por
constructor: 3 fallos de la suite completa, corregido).

**Dos fallos de la primera CI manual, corregidos:** (a) la ⑧ exige que ninguna guía entregada enlace fuera del paquete y el runbook nuevo enlazaba al ADR-020 (los ADR no viajan): ahora lo cita en texto; (b) la ⑧b **falló de verdad** en «U1 · Actualizar»: en el escenario sintético `ci.yml` congelaba la frontera de la versión anterior con la última migración del árbol **nuevo**, así que las dos migraciones de la 5.9 quedaban atribuidas a la 2.1.0 ya instalada y `update.sh` se negaba —bien— a adivinar. Había pasado desapercibido porque 5.7 y 5.8 no traían migraciones. La frontera sale ahora del árbol `anterior/`. Trampa aprendida: un `perl -i` sobre `ci.yml` dejó un comentario a columna 0 dentro de un bloque `run: |` y el YAML entero dejó de parsear (GitHub responde «Workflow does not have workflow_dispatch trigger»): validar el YAML antes de empujar. (c) Segunda CI manual: U1 en verde y U3 deshaciendo «sin `product:doctor`» con la misma imagen: la comprobación de presencia era `artisan list --raw | grep -q` y, con `pipefail`, `grep -q` cierra el tubo al encontrar la línea, PHP recibe SIGPIPE y la tubería falla aunque el comando exista. Ahora la salida se captura en una variable antes de buscar, en los tres scripts.

**Verificado el 08-09:** Pint, PHPStan 9, Deptrac 0 violaciones, **suite completa del backend 3458 en
verde**, mutación sobre las cuatro clases de dominio nuevas (`SupportGrant`, `SupportScope`, `FieldAllowlist`, `SettingsDrift`) **MSI 90,16 %** (61 mutantes, 6 sin cubrir), prueba de la ventana de auditoría con ocho procesos concurrentes contra Redis (un solo registro; los hijos mueren con SIGKILL para no cerrar el socket de PDO del padre), `qa:traceability --check`, `docs:consistency --check`, gitleaks (186 commits, 0), contrato Redocly 0
problemas, `make sh-lint` 0, panel 397 unitarias + 76 E2E (5 nuevas de `support.spec.ts` y 3 de
accesibilidad), `web-kit` 187, quiosco y portal `type-check`. A mano en el contenedor: `product:doctor`
(exit 1, tres avisos coherentes), `product:diagnostics` (71 KB, `grep -c "hotel\|@"` = 0), `--verify`
íntegro/alterado, `support:grant`/`support:revoke`, `doctor.sh` con `app` en pie y parado.

**Siguiente acción (5.9):** ninguna; integrada. La CI de `main` tras el merge (34242209054) terminó en verde.

## Pendiente

### Del usuario

- **Generar el par ed25519 una vez** (`php tools/license-issuer/generate-keypair.php`), privada al
  gestor de secretos, pública como valor por defecto de `env('LICENSE_PUBLIC_KEY', '')` en
  `backend/config/license.php`. `make release-gate` lo exige en cada etiqueta `vX.Y.Z`.
- **Los PIN de la importación masiva se emiten y nadie los conoce** (regla dura 5 rozada): elegir entre
  (a) devolverlos en el informe de aplicación, (b) no emitir PIN al importar y dejarlo pendiente y
  visible, (c) restablecimiento masivo — y documentarla en `configuracion.md` §3 ter.
- Hardware: tarjeta impresa y plastificada escaneada en quiosco real; resistencia 12 h en tablet;
  recalibración de estimación R16.
- Plazo de purga de `employment_contracts` con la asesoría laboral (hasta entonces se conservan).
- Techo del formato PDF (`docs/verificacion-manual.md`).
- **Engram (09-09-2026):** en funcionamiento (binario 1.20.0 en `~/.local/bin`, plugin `engram@engram` y MCP `engram` en ámbito
  usuario, protocolo `slim`, proyecto detectado `kronoqr`, nueve memorias sembradas: trampas del entorno, 5.12 y receta de
  orquestación). **Falta solo** añadir `"permissions": {"allow": ["mcp__engram"]}` a `~/.claude/settings.json` (o ejecutar
  `engram setup claude-code --protocol=slim` en una terminal) para que fuera del modo automático no pregunte por cada `mem_*`.

### Por tarea

- **3.4 (restos, 16-09-2026):** **decisión del usuario sobre el doc 05** (no editado): la fila «Vista de cumplimiento» (~l. 167)
  dice «falta de pausa» sin matiz y se entrega visible pero no evaluada hasta la 3.5, y §10.3 (~l. 395) dice que los tres campos del
  perfil los aplica la vista «cuando entra en servicio» cuando dos ya se aplican (frases propuestas en Engram `fase-3/tarea-3.4`);
  captura `rrhh-15-cumplimiento` para las dos guías cuando exista el generador en `tests/screenshots/hr-guide.screenshots.ts`;
  `REPORTING_PERIOD_MAX_RANGE_DAYS`/`_MAX_ROWS`/`_TIMEOUT_SECONDS` nunca estuvieron en `.env.example` ni en `configuracion.md`
  (hueco previo); medir en la 3.6 (k6) el endpoint de cumplimiento, que alcanza también al responsable y deja asiento bajo el
  candado de ADR-010; en el cierre de la Fase 3 anotar en doc 07 que `compliance_summary` se dejó fuera de `disclosure_grouping`
  a propósito y es el conjunto que más revela por fila; «Ver incidencia» lleva a la bandeja acotada a la persona (`?employee=`), no
  a la incidencia por `id`; dos tramos cerrados con el mismo máximo (desempate por `se.id`) sin prueba hasta que la 3.5 reactive
  RN-12; la fábrica `missingBreak()` está probada pero la rama de emisión no se ejercita mientras dure la suspensión;
  `ComplianceProfileView` incrusta avisos por efecto (`compliance_view`) que la 3.5 deberá revisar al vaciar la suspensión;
  cobertura y MSI oficiales se leen de la CI; el panel «Qué falta» del cuadro Negocio atribuye horas contratadas e impuntualidad a
  la 3.13 (previsión, no compromiso); los `.spec` del portal siguen sin prueba de paridad ES/EN (de la 3.3).
- **3.3 (restos, 16-09-2026):** verificar en una tablet Android real que Chrome expone `focusMode`, `zoom` y `backgroundBlur` en
  `getSettings()` y que el aviso de desenfoque dispara (la cámara falsa de Chromium no los trae: el E2E afirma filas y avisos, no
  valores); `CheckKioskHealthTest` y `KioskHealthRowTest` fijan las mismas reglas desde dos ficheros (fusionar al tocar el dominio de
  salud); panel de batería en el cuadro Grafana «Operación de quioscos» (`kiosk_battery_level{device}` ya se expone); la paridad
  ES/EN de los `locales/*.json` la atan `i18n.spec.ts` del quiosco y del panel, el portal no tiene esa prueba (`LangParityTest` solo
  cubre `backend/lang/`); `diagnostics.spec.ts` tiene una prueba con tiempo de gesto (3,6 s para los 3 s de pulsación larga); **la primera
  CI manual (35107182531) cayó en ⑦ quiosco** en «dos 401 seguidos vuelve a `/pair`»: las peticiones de montaje de `ScanView`
  salían antes de instalar las rutas 401 y el siguiente latido tardaba 60 s; ahora la prueba recarga la página tras instalar las
  rutas (dos 401 deterministas); una tablet sin ningún latido posterior a la
  configuración del código abre el diagnóstico sin él (decisión 7, documentado en A-10 y §16.5); mover los umbrales de salud a
  `installation_settings` sigue descartado (decisión 2); `make test-unit` en local ~8 s con la máquina cargada frente a los 5 s del
  presupuesto (medir en reposo y leer la CI); `HeartbeatConcurrencyTest` dio un falso positivo con `migrate:fresh` de otro agente
  (BD compartida); el `settings/README.md` del panel ya no dice que ningún umbral tiene pantalla.
- **Dependabot (16-09-2026):** cuatro PRs abiertas. #58 (composer menores) y #59 (npm menores) caían solo por la bomba de tiempo
  de `main`: `@dependabot rebase` tras integrar el hotfix e integrarlas si pasan. **#60 (`@vitest/coverage-v8` 5.0) y #61 (`vitest`
  5.0) son un cambio mayor** (Node 22, `sequential` retirado, `toHaveTextContent` estricto, entradas obsoletas) que rompe ① y ⑥
  en los cuatro paquetes. **Decisión del usuario (16-09): APLAZAR.** `dependabot.yml` ignora las mayores de `vitest` y `@vitest/*`
  y las agrupa (`vitest-mayor`) para que, al levantar el `ignore`, lleguen en una sola PR coherente; #60 y #61 cerradas. La
  migración a Vitest 5 se aborda a propósito en la 3.7 o al cierre de la Fase 3 (`qa-testing`, rama única con los dos paquetes,
  lock regenerado desde Linux). Recordar la trampa del lock (`npm install` solo desde Linux y sin `node_modules`).
- **3.2 (restos, 10-09-2026):** `amtool check-config` solo corre en `make observability-check`, en la CI y al arrancar el contenedor
  (`AlertmanagerConfigTest` valida con el parser de Symfony, más laxo); `render-config.sh` sin prueba de sus `die` de plantilla
  ausente; las variables de plantilla de Grafana (`label_values`) y los `legendFormat` no se contrastan con el §8.2 ni con la regla
  dura 21; «incidencias por antigüedad» del cuadro de integridad es una aproximación (`incidents_open` en el tiempo: no hay serie por
  edad); «Negocio» no muestra horas contratadas, absentismo ni impuntualidad (sin serie: 3.10/3.13); `kiosk_last_seen_seconds` vive
  en Redis y un quiosco callado ANTES de un `FLUSHALL` desaparece de la serie (segunda red: `kiosk:health`); `QuioscoSinLatido`
  lleva 600 s literal atados por prueba al valor por defecto de `KIOSK_HEALTH_SILENT_AFTER_SECONDS`, pero cambiar la variable en una
  instalación no mueve la regla; las alertas de TLS no ven validez ni cadena ni CN (`insecure_skip_verify`; doc 07 A-8, candidata a
  la 3.8: segundo módulo de blackbox contra `APP_URL` con verificación); `EntregaDeAlertasFallando` no llega por correo si lo roto es
  el correo; falta prueba de que un fallo de tarea en segundo plano NO llega a `error_events` y `onFailure` de extremo a extremo con
  `schedule:run`; `ParticionDeAuditoriaDelProximoAnoSinPreparar` no se puede disparar con `promtool` (reloj fijo en 1970); una
  prueba de arquitectura que ate el nombre de la serie de cada adaptador textfile con el de su regla; `AlertmanagerConfigTest`
  arranca ocho `sh` (~11 s de la etapa ②); `LogScheduledCommandFailure` y `AlertRecipientsProbe` son lógica pura fuera de `Domain/`
  y `make mutate` no las cubre; en Docker Desktop `MetricasDelAnfitrionAusentes` queda encendida en dev (ver Trampas) y con
  Alertmanager en dev intentará entregarse a buzones vacíos; comprobar en tablet real que un `.prom` truncado por disco lleno no
  inhibe nada (fail-safe verificado solo por lectura); ficha 3.1 «Verificación final» sigue citando `kronoqr-backup` (histórico).
- **3.1 (restos, 10-09-2026):** `MetricsCollector` del paquete de diagnóstico llama `command('SCAN', [...])` con cinco argumentos y
  phpredis lanza `ArgumentCountError` tragado en su `try`: `installation_setting_changes_total`, `compliance_profile_changes_total` y
  `license_limit_exceeded_total` **nunca han viajado en el paquete** (desde la 5.5; usar `RedisMetricReader` o `scan($cursor, $opts)`
  con cursor `null` y prefijo a mano); comprobación de `doctor` que avise si `LOKI_URL` u `OTEL_EXPORTER_OTLP_ENDPOINT` apuntan a un
  contenedor que no existe (`Probe` nueva en `Product/Infrastructure/Diagnostics/Probe/`); **doc 07 §6 A-4: validar con el DPO del
  cliente** que el log técnico con `employee_uuid` + instante de fichaje en Loki 90 días sin borrado selectivo encaja con el derecho
  de supresión (no es asesoramiento jurídico); `ScanBatchTelemetry` escribe `oldest_occurred_at` (hora real de fichaje) en el log
  técnico; logs de Nginx, PostgreSQL y Redis no van a Loki (Promtail EOL 03-2026; Alloy y el driver exigen `docker.sock`); el SDK de
  OTel escribe sus fallos de exportación con `error_log()` fuera de Monolog (acotar con `OTEL_LOG_LEVEL`, variable no introducida);
  atributos `db.system`/`db.operation` según la ficha y no semconv 1.38 (`db.system.name`, `db.operation.name`): se cambia en la 3.2
  con los cuadros o nunca; el span del planificador ya no se activa (las consultas de una tarea en primer plano no cuelgan de él;
  casi todo `console.php` usa `runInBackground()`); `overrides` de Tempo (`max_traces_per_user: 100000`) sin datos reales; sin
  `mem_limit` en Tempo ni blackbox (ningún servicio de observabilidad lo declara); `QueuedTraceContext` probado con `sync`, falta
  `queue:work --once` real sobre Redis; falta la gemela concurrente «Loki inalcanzable» de `ScanIdempotencyWithTracingTest`;
  `TraceparentHeader` y `LogChannelStack` son reglas puras fuera de `Domain/` y `make mutate` no las cubre; `db_query_duration_seconds`
  acumula las consultas del *setup* de la propia prueba (se afirma presencia e invariante, no cifras); doble ejecución de
  `attendance:detect-incidents` la misma noche sumaría dos veces `anomalous_patterns_detected_total`; `scan_batch_size` y
  `pin_resets_total` siguen en Redis y en el paquete pero no en `/metrics` (no están en el §8.2); el `.env` local de dev sigue con
  `LOG_CHANNEL=stderr` (para probar Loki en local: `LOG_CHANNEL=stack`, `LOKI_URL=http://loki:3100`); la traza «fetch del quiosco →
  SQL en Grafana» se verificó con `curl` + Tempo API, no navegando en Grafana; la comprobación de memoria del quiosco a 12 h sigue
  siendo manual; `Unit` tarda 4,5 s en el contenedor frente al techo de 2 s del §9 (ya lo hacía antes).
- **Cierre de la Fase 5 (restos, 10-09-2026):** la instalación limpia y los cuatro recorridos de las guías por una persona ajena
  (humano); ⑧b desde **cada** versión soportada y salto no consecutivo real al publicar 2.2.0; **MSI por módulo** con el global
  en 82,83 %: `Workforce` 66,67 % (`Employee` 23, `ImportColumnMap` 22, `EmploymentContract` 12, `EmployeeCode` 11) y `Kiosk`
  77,70 % (`KioskHealthReport` 22, `KioskHealthThresholds` 4); `Product` real 81,77 % (el 74,85 % era de media plantilla);
  `make test-unit` en local 5,54 s > 5 s (CI 1,62 s: mirar si esas pruebas son unitarias, no subir el techo); prueba que
  enumere el *router* y exija autorización negativa por ruta (hoy `AuthorizationNegativeTest` es una lista a mano de 35 pares);
  `ClientErrorContextKeysTest` y `Support/ClientDocs.php` siguen con `RecursiveDirectoryIterator` sobre `frontend-*/src` y
  `docs/`; catorce rutas de gestión sin zona de límite (doc 07 §6, Fase 3); `plan_exceeded` por fila en la importación (doc 07
  §6, Fase 3); `audit:read` en el alcance `read_only` sin consumidor (doc 07 §6); spans OTel ausentes en los controladores de
  5.9/5.10/5.12 (los de 5.1–5.8 tienen `*Telemetry`); métricas de exportación íntegra, telemetría, concesiones y paquetes sin
  emitir; presupuesto de ①–③ de la CI (~20 min frente a los 4 del doc 02 §10.1); primer `run` real de los jobs ④/⑥/⑦/`coverage`
  puede destapar detalles del runner; el `409` de `POST /setup/administrator` sigue sin señal. **Del verificador tolerante:** una fila manipulada con acción `system.*` y `actor_type` cambiado hace que
  `AuditEntryDraft` lance `AuditActorNotAllowedForAction` AL LEER, y el verificador muere con excepción en vez de reportar
  `content_altered` (ruidoso, no silencioso; exige una vía de construcción de solo lectura: `arquitecto-dominio`); mutación de
  `AuditActionName` en la CI; al publicar 2.2.0, U3 debe probar la vuelta atrás DESDE 2.2.0 y el aviso «acción desconocida».
- **5.7 (restos):** menores:
  extraer `compose()`/`wait_for_healthy`/`edge_probe` de `install.sh` y `update.sh` a `lib/checks.sh` (ya divergen);
  el `503` de mantenimiento no se enumera por endpoint en el contrato (solo el párrafo de `info`);
  `update.sh` no escribe métricas `.prom` (una vuelta atrás no llega a Prometheus); modo **in-place**
  tolerado con aviso (doc 07 §6); **salto de mayor de PostgreSQL** no cubierto (runbook §7);
  `backup.sh`/`restore.sh` con identificadores en español (punto 8 de «no cubiertos»); si `update.sh`
  coincide con la copia nocturna, sale 2 y el mensaje ya dice que espere; la ⑧b corrió en verde (34152296162).
- **5.8 (restos):** **verificar en tablet real** que el logotipo sobrevive a una recarga sin red desde la
  caché del SW (Playwright no puede afirmarlo: el SW no controla la primera carga que lo instala; el nombre y
  el color sí están probados sin red); `PairingView`/`PinView` del quiosco siguen con el nombre del producto
  (la ficha solo pedía espera y confirmación); **segundo logotipo para el fondo oscuro del quiosco**
  (documentado como límite en `configuracion.md` §2.2); el asistente de puesta en marcha (5.5) debería avisar
  en su paso de marca de que sin licencia activada se ve color y logotipo del producto; `manifest.name` de
  la PWA es de compilación; regla de ESLint/Pest Arch que prohíba `v-html`/`{!! !!}` con `logoUrl`/`dataUri`
  (riesgo aceptado en doc 07 §6); unificar el estado de carga de `ComplianceProfileView`/`LicenseView` con
  `LoadingPanel` como ya hace `BrandingView`; cada `GET /branding` lee y hashea el logotipo (≤ 512 KiB,
  aceptado con aviso en `.env.example`). (la 5.9 ya lo comprueba: `permissions.branding_logo` y `license.white_label_without_plan`).
- **5.9 (restos):** `updated_by_user_id` queda `null` cuando escribe un actor de soporte (el actor consta en `audit_log`; si hace falta, `updated_by_support_grant_id`); los `Redis*Metrics` de otros módulos podrían exponer sus claves para que `MetricsCollector` no adivine la forma; ampliar `error_events` en el paquete llega con la 5.12; si el paquete incluyera algún día asientos de `audit_log`, redactar `customer_name` (hoy solo recuentos).
- **5.11 (restos):** **instalación limpia por una persona ajena siguiendo solo la guía** (criterio del doc 03 §6.5;
  ningún script la sustituye); regenerar las capturas (`npm run docs:screenshots` en los dos frontends) en cada
  versión menor —la prueba del sello `img/VERSION` lo recuerda—; los runbooks siguen solo en español; la salida de
  `compliance:apply-retention` y `verify-audit-chain` está cableada en español (la guía inglesa la glosa); la
  cabecera de `instalacion.md` §1.2 y §1.3 quedó sin el bloque duplicado de `--check-only`.
- **5.11b (restos):** los cuatro recorridos por una persona ajena siguiendo solo las guías (decisión 11); **deuda de producto que
  la guía destapó** (decisión 13): no hay pantalla de contratos en el panel (endpoints sí), `reissue` en un acto es solo de API (el
  panel emite siempre con `reissue: false`), tres tipos de incidencia del filtro sin productor hasta la Fase 3; al cerrar la 3.10,
  apartado de ausencias en `guia-rrhh.md` y `en/hr-guide.md`; **catorce rutas de gestión siguen sin zona de límite de
  aplicación** (`POST/PATCH /employees`, `/contracts`, `/offboard`, `/pin/deliver`, `/pin/reset`, `/departments`, `/site`,
  `GET /reports/legal-export`, `/auth/logout`, `/auth/me`): solo las frena Nginx por IP; una prueba que exija zona por ruta hoy
  fallaría en ellas; autorización negativa del `429` de credenciales con token de quiosco/portal; prueba de «una cara» con nombre de marca de 60 caracteres y
  logotipo de 11 mm; un fallo de Chromium en la hoja sale como `500` (la impresión de tarjetas hace lo mismo; `Reporting` da `503`).
- **5.12 (restos):** inspección manual de `error_events` tras un día de uso con la semilla realista (la automática,
  `ErrorEventsHaveNoPersonalDataTest`, está en verde); autorización negativa del latido **con** `client_errors` (token de
  gestión con `heartbeat:write` → 403) y la variante de agrupación concurrente que entra **por el latido**; el `Employee` y el
  `Device` no son `Authenticatable` y `tokenKey()` lo esquiva con `instanceof Model` (deuda de Identity); la regla
  `severity: high` es la primera del repositorio y Alertmanager la enruta por defecto: al montar las alertas «Alta» de la 3.2
  unificar el valor; `ErrorLevel` critical de servidor no distingue un `5xx` puntual de una tormenta (la alerta cuenta grupos
  nuevos y basta por ahora); el saneado convierte los identificadores SQL entrecomillados en `'…'` (decisión: seguridad sobre
  detalle); dos comprobaciones de `doctor` más de las que citan las guías si enumeran su número.
- **Fase 3:** `holidayCalendar` de `CompliancePolicy` sigue sin consumidor (3.10); 3.5 reactiva RN-12 (vaciar
  `Shared\Domain\ValueObject\ComplianceRuleSuspension::SUSPENDED`: la detección, el asiento del perfil, la pantalla del perfil y la
  vista de cumplimiento derivan de ahí) y el descanso intra-día de RN-10 con la pausa declarada (RF-AT-12); RNF-D-03 fallback de colas Redis→BD; pasada k6 en Linux para el p95
  (RNF-P-02/06); la puerta de cobertura (`make coverage`) no corre en CI.
- **Decisiones de producto abiertas:** **baja de cuentas de gestión** (no existe ni pantalla ni comando; `users.is_active` nunca pasa a
  `false`; la guía de endurecimiento lo declara como límite de la 2.1 y remite al fabricante — hace falta
  `identity:deactivate-user` o una pantalla, y el cambio de contraseña por consola); si el portal muestra incidencias (hoy `incidents: []` siempre; si
  se activa, solo resueltas); si el `responsable_departamento` ve credenciales de su gente; códigos de
  recuperación de 2FA (hoy solo `identity:2fa-reset` por consola); si la baja revoca la credencial
  automáticamente; `POST /me/logout` (hoy el token del portal vive hasta caducar, máx. 2 h); la mitad de
  aplicación de RF-ID-08 (requisitos extra de contraseña al exponer el portal a internet).

### Deuda técnica anotada

- Falta una captura de referencia del panel con `LicenseNotice` activo y las 14 secciones (hallazgo opcional de la revisión del
  menú lateral del 10-09-2026; la duplicación `guards.ts`/`AppShellView.vue` quedó pagada en la 3.4 con `shared/ui/navigation.ts`).
- **Rector: 227 ficheros en rojo e ignorado** en `make quality` — aplicar esas reglas o retirarlas del
  conjunto; un paso siempre rojo y siempre ignorado acaba sin leerse.
- XLSX se lee sin cota de descompresión más allá de `max_rows` y los 4 MB (riesgo bajo, consciente).
- El 409 de `POST /setup/administrator` (intento de segundo admin) no deja señal; registrar sin PII.
- La suite Feature depende del orden alfabético de directorios para EXPONER acoplamientos de estado;
  nada detecta una prueba que dependa del vaciado de tablas de trabajo confirmadas.
- `heading-order` (axe, impacto moderado) en `LicenseStep`/`ComplianceProfileStep` al incrustar
  pantallas con `<h2>` propios.
- El contrato OpenAPI no enumera el `503` de mantenimiento por endpoint (solo `/scan` y `/scan/batch` lo
  tenían ya); está descrito en `MaintenanceModeTest` y en `ProblemDetails::maintenance`. Decidir si va en
  `info.description` o como respuesta reutilizable en cada ruta.
- `release.yml` sigue siendo un marcador: publicar imágenes etiquetadas, el paquete y el SBOM en una *release* es del plan de
  implementación 08 (la etapa ⑧ ya vive en `ci.yml`).
- **Del cierre de la Fase 5 (`revisor-codigo`, con horas):** unificar `sanitizeContext` y el buffer de errores de cliente de
  `web-kit/clientErrors.ts` y `frontend-kiosk/.../errorReporter.ts` (copia literal, 3–4 h); generar los `urn:kronoqr:problem:*`
  del contrato y atar los ocho literales de las SPA (3–4 h); `PdfDocument::builder()` con `dontCache()` + regla Pest Arch que
  prohíba `new PdfBuilder` fuera (2 h); subir `compose`/`service_state`/`wait_for_healthy`/`edge_probe` a `lib/checks.sh`
  (2–3 h); tres `minutesBetween` ya aplicados; README de `pairing`/`offline` del quiosco (1 h); un fallo de Chromium en la hoja
  sale como `500` (`Reporting` da `503`); `SourceDiscoveryTest` rojo en local por diseño: si estorba, degradarlo a aviso y
  asumir que vuelve a ser invisible.

## Trampas del entorno — leer antes de operar

- **Reloj fijo + token Sanctum = bomba de tiempo** (16-09-2026): una prueba con framework tiene dos relojes, el puerto `Clock` del
  dominio y Carbon (Sanctum, Eloquent, limitador). `PlanLimitsDoNotBlockTest` instalaba un `FixedClock` de junio, emitía con él un
  token de quiosco (caducidad = junio + 90 días de `IDENTITY_DEVICE_TOKEN_DAYS`) y fichaba; Sanctum comparaba la caducidad con el
  reloj REAL y desde el 13-09 respondía 401: `main` en rojo tres días sin que nadie tocara nada. **En Feature, Integration y Contract
  el reloj se detiene con `FrozenTime::at('…')`** (`tests/Support/Time`), que instala el `FixedClock` y congela Carbon en el mismo
  instante; `FrozenTimeTest` (Architecture) prohíbe `->instance(Clock::class, …)` en esas suites. `audit_log` está particionado por
  año: un reloj detenido antes de 2026 rompe cualquier asiento. La CI nocturna (`schedule`) es la única que corre sin un push
  delante: si `main` pasa a rojo sin commits, mirar primero fechas fijas.
- **La webcam del portátil no vale para juzgar el enfoque del quiosco** (10-09-2026): «Windows Studio Effects» (encuadre
  automático + desenfoque de fondo, equipos con NPU) se aplica antes de que Chrome vea la imagen y el QR llega recortado y borroso
  sin que la PWA pueda evitarlo; se apaga en Configuración › Cámaras. Y una webcam no tiene autoenfoque: tarjeta a 30-50 cm.
  Documentado en `docs/runbooks/alta-nuevo-quiosco.md` §6 y plan 01 §C.2; la **3.3** debe mostrar `getSettings()` (incluido
  `backgroundBlur`) en la pantalla de diagnóstico (nota en su ficha, paso 4).
- **Docker Desktop y `node-exporter` (3.2):** el montaje `/:/host:ro,rslave` de producción no arranca en Windows/macOS («path / is
  mounted on / but it is not a shared or slave mount»); `compose.dev.yaml` lo lleva sin `rslave`. Aun así la VM no publica ninguna
  serie con `mountpoint="/"` (su raíz es `overlay`), así que en local `EspacioEnDiscoBajo` no se ejercita con datos reales y
  `MetricasDelAnfitrionAusentes` queda encendida: las dos se prueban con `promtool` y en Linux. `docker compose -f
  infra/compose.prod.yaml config` exige un `.env` junto al compose (`cp .env infra/.env` temporal, y borrarlo: está ignorado).
- **Ningún contenedor de terceros recibe `env_file: .env`** (3.2, doc 07 A-6): Alertmanager lo heredaba de la 1.18 y exponía la clave
  HMAC del QR en `docker inspect`. Variables nombradas una a una con `environment:`; `AlertmanagerConfigTest` lo vigila. Y todo
  renderizado de YAML desde el entorno escapa `'` y se valida con la herramienta real antes de arrancar.
- **El `sh` del runner de la CI es `dash`** (3.2): un `#!/bin/sh` con `set -o pipefail` o `IFS=$'
	'` pasa en Git Bash (bash) y en BusyBox (ash) y cae en la CI («Illegal option»). Los scripts POSIX llevan `set -eu` e `IFS="$(printf '
	')"`, `make sh-lint` lo exige por shebang, y se prueban con `docker run debian:stable-slim sh …`.
- **`promtool test rules` fija el reloj en 1970**: una regla con `month()` no se puede disparar en pruebas; `expect($output)->not->toContain('serie 1')`
  casa también con un `# HELP` que empiece por «serie 1 mientras…» (pasó con `kronoqr_maintenance_active`).
- **La mutación va en `--parallel` desde la 5.12** (830 s → 85 s en el dominio de `Product`; en serie la CI tardaba 37 min
  y la 5.12 la sacó del tope de 45 del job ③, ahora 60). En paralelo, un `use DateTimeImmutable;` (clase global) en un
  fichero de prueba **sin namespace** rompe el arranque de los hijos como `ErrorException` («use statement with
  non-compound name has no effect»): no dejar ninguno. Los mutantes sin prueba de `Product/Domain` son de 5.1–5.10
  (`InvalidSettingValue`, `SettingKey`, `SettingDefinition`, `InvalidComplianceProfileValue`, `InvalidLicenseKey`…); el MSI
  real de `Product` es 81,77 % (el 74,85 % local era de media plantilla, ver el *bind mount* más abajo). **Toda cifra de
  mutación o cobertura se lee de la CI**, nunca del portátil.
- **`docs/` va montado `:ro` en el contenedor `app`** (`infra/compose.dev.yaml`): `qa:traceability` en modo escritura falla ahí;
  la matriz se regenera con `make traceability` (usa `--output=-` y escribe desde el anfitrión). `TraceabilityMatrixFreshnessTest`
  cae si la matriz versionada no coincide con lo que generaría el comando: **regenerar antes de cada commit que toque etiquetas**.
- **`make test-unit` en local tarda 5,5 s y el presupuesto es 5 s** (doc 02 §9.2; en la CI 1,6 s): la puerta sale roja en Windows
  por el *bind mount*, no por las pruebas. No subir el techo; leer el tiempo de la CI.
- **La CI cancela la ejecución en curso de la misma rama con cada push** (`concurrency: ci-${{ github.ref }}`,
  `cancel-in-progress`). Una CI manual (`gh workflow run ci.yml --ref rama`, la única que corre ⑧b fuera de `main`) se
  lanza **después** del último push, y no se empuja nada más —ni el HANDOFF— hasta que termine.
- **El *bind mount* de Docker Desktop pierde ficheros al recorrer directorios**: `RecursiveDirectoryIterator` (PHPUnit/Pest)
  sobre `tests/Feature` devolvía 78 ficheros de 116 y ninguna suite avisaba. `phpunit.xml` va por subdirectorio y
  `TestDiscoveryTest` (Architecture) falla si vuelve a faltar uno: si falla en local, declarar el directorio afectado aparte.
  **Un directorio nuevo bajo `tests/Feature` hay que añadirlo a `phpunit.xml`** (la misma prueba lo exige). No dar por buena
  una cifra local de pruebas sin esa guarda en verde.
  **También afecta a `backend/app/` (09-09-2026, cierre de Fase 5):** el iterador ve 1.189 de los 1.234 `.php` y pierde los
  **45 de `Product/Domain/ValueObject`**, siempre los mismos. Consecuencias: la **mutación y la cobertura locales de `Product`
  medían 52 de 97 ficheros** (el MSI del 74,85 % es de media plantilla), y las pruebas de arquitectura que recorrían con el
  iterador daban **verde falso** —no hay nada que denunciar en un fichero que no se ve—. Ya recorren con `scandir`
  (`ModuleTree::phpFilesUnder()`, usado por `AggregateBoundaryTest`, `OutboundChannelsTest`, `DataProtectionGuaranteesTest` y
  `Support/SettingsSurface.php`); con los 45 dentro **no aparece ninguna violación nueva**. `SourceDiscoveryTest`
  (Architecture) es el testigo: **en local sale en rojo a propósito** —es el síntoma del sistema de ficheros, no un defecto
  del producto— y en la CI (Linux, sin bind mount) está en verde. Cobertura, mutación y Deptrac se leen **solo de la CI**.
- **`package-lock.json`: cualquier `npm install` en Windows con `node_modules/` presente** pierde las
  plataformas nativas de `@tailwindcss/oxide` y rompe la imagen de Nginx (npm/cli#4828, sufrido dos
  veces). Operar el lock **siempre desde Linux y sin `node_modules`**; receta en la cabecera de
  `infra/docker/nginx/Dockerfile`; guarda en `QualityGatesTest`.
- Los contenedores `node-*` están **estructuralmente rotos** (ADR-036) y se dejan parados: las tres SPA
  se sirven desde el host con `npm run dev` (kiosk 5173, admin 5174, portal 5175).
- La base `fichaje_test` es compartida: **no correr dos suites de backend a la vez** (fallos falsos
  «relation … does not exist»). `MigrationsRoundTripTest` deshace y reaplica **todas** las migraciones
  (usa `CommittedDatabase`): jamás en paralelo con otra suite.
- `make trivy-fs` **no termina** sobre el bind mount NTFS; verificar con la CI o con una copia en disco
  Linux. Las pruebas de permisos de fichero (`chmod`) solo valen **dentro del contenedor**.
- Scripts de shell: el `printf` de bash **no admite especificadores posicionales**; nunca una tubería
  que corte a su productor (`head` tras `tr </dev/urandom`) — con `set -E` + `trap ERR` + `pipefail` el
  SIGPIPE dispara la vuelta atrás dentro de una subshell (prueba que lo prohíbe en
  `tests/Integration/Install/`). **`IFS=$'\n\t'` de los scripts anula la división por espacios**: un
  `set -- ${line}` o un `read a b` sin `IFS=' '` local deja todo en el primer campo (pasó en
  `kq_versions_load`).
- **Las series del colector *textfile* se declaran en `textfileSeries()` de `tests/Architecture/MetricsCatalogueTest.php` y en el
  doc 02 §8.2**, no en `MetricCatalogue.php` (que solo cataloga lo que sale por `/metrics`): una serie `.prom` nueva sin esas dos
  entradas rompe `MetricsCatalogueTest` o `GrafanaDashboardsTest` (3.4).
- **`wrapper.find(...)` de `@vue/test-utils` nunca es falsy** (devuelve un envoltorio también cuando no encuentra nada): una
  aserción `expect(wrapper.find(sel)).toBeTruthy()` no puede fallar; usar `findAll(sel).length` o `.exists()` (3.4).
- **Una regla suspendida por `ComplianceRuleSuspension` deja su rama de emisión sin ejercitar en todos los niveles** (la constante es
  privada y no se puede levantar desde una prueba): fijar al menos la fábrica del hallazgo (`ComplianceFinding::missingBreak()`).
- **Pest: `expect($array)->toContain($x, $mensaje)` trata el mensaje como otro elemento a buscar**; para
  un mensaje propio usar `expect(in_array(...))->toBeTrue($mensaje)`. PHPStan 9 rechaza `(int) $mixed`:
  con `DB::scalar()`/`selectOne()` usar el query builder (`count()`, `value()`).
- `Request::create()` de Symfony añade `Accept-Language: en-us…` por defecto; el helper `Api` de
  pruebas lo anula para que el caso neutro sea «sin cabecera».
- `CommittedDatabase` restaura los catálogos de producto vía `ProductCatalogBaseline` en cada vaciado —
  no escribir `afterEach` a mano para eso.
- El nombre corto de la migración `2026_08_30_100100_grant_read_ability.php` es **a propósito** (con el
  largo, PHPStan/Larastan rompía en ficheros ajenos; causa no encontrada, anotado en su docblock).
- Los `.env` locales acumulan desfase con `.env.example`: ante un fallo «inesperado», comparar los dos
  antes de buscar en el código.
- Hook de Pint activo: tras editar un `.php` de backend, el fichero puede quedar reformateado al
  instante.
- **Al tocar `docs/api/openapi.yaml`, regenerar los TRES clientes** (`npm run api:generate` en `frontend-admin`, `frontend-kiosk` y `frontend-portal`): la etapa ① de la CI compara cada `schema.d.ts` versionado con el contrato y falla si uno no se regeneró, aunque esa SPA no use las rutas nuevas (pasó en la primera CI de la 5.10).
- gitleaks (job `security`) marca como clave cualquier literal `NOMBRE_KEY=valor` aunque sea un ejemplo
  de prueba: en las aserciones, comprobar el valor sin el nombre de la variable delante.
- **`npm audit` de la CI (job `security`) cae por avisos nuevos ajenos al cambio** (08-09-2026: `js-yaml` 4.3.1, fijado
  en exacto por `@redocly/openapi-core` ← `openapi-typescript`). Se resuelve con `overrides` en el `package.json` raíz y
  regenerando el lock **desde un contenedor Linux con solo los manifiestos** (`node:24-alpine`, `npm audit fix
  --package-lock-only --ignore-scripts`); nunca `npm install` en Windows. Comprobar después el guarda de `QualityGatesTest`
  («mantiene en el lock los binarios nativos»).

- **`packages/web-kit` no compila `.vue` en Vitest** (sin `@vitejs/plugin-vue`, a propósito: los componentes
  los prueban las SPA). Añadir el plugin exigiría `npm install` en Windows, que es la trampa del lock de
  arriba. Un `.spec.ts` de web-kit que importe un `.vue` falla al cargar.
- **Empalmar texto en un `.md` con `perl -0pi` leyendo el reemplazo con `:encoding(UTF-8)` recodifica el
  resto del fichero** (mojibake en todas las tildes). Leer el reemplazo con `:raw` para que todo sean bytes.

## Método de trabajo acordado

Una rama por fase o tarea, un commit por tarea con CI en cada push, PR al cierre con *merge commit*
(nunca squash: el CHANGELOG se genera de los commits convencionales y **no se edita a mano**). Cada
cierre de fase pasa por los cuatro revisores (`seguridad-cumplimiento`, `revisor-codigo`, `qa-testing`,
`devops-observabilidad`) y sube `current_phase` en `backend/config/quality.php` solo al cerrar.

Desde el 09-09-2026 hay además **Engram** (memoria local buscable, herramientas MCP `mem_*`); el reparto con este fichero
está en `CLAUDE.md` → «Engram: memoria de búsqueda, no sustituto de `HANDOFF.md`». En resumen: este fichero manda y lleva
la línea; Engram guarda el porqué y el detalle. Al cerrar tarea o sesión: primero `HANDOFF.md`, luego `mem_session_summary`.

## Histórico condensado

Detalle de cada hito: mensajes de commit, PRs y `git show 9b1593d:HANDOFF.md`.

- **27-08** — Fase 1 cerrada (18 tareas, 4 revisores) y auditoría independiente con 7 correcciones;
  v1.0.0 → v1.1.0.
- **28-08** — Sistema visual compartido (`web-kit`, tokens `--kq-*`, doc 06) y v1.2.0; SSDLC: job
  `security` (gitleaks/Semgrep/Trivy/SBOM), rastro de autenticación (ADR-039), doc 07 (SAMM/ATT&CK).
- **29-08** — **ADR-040: un centro por instalación y licencia** → v2.0.0 (contrato de gestión
  incompatible); Trivy a bloqueante; toolchain frontend + E2E del panel + 2FA/RBAC (PRs #28–#30).
- **30/31-08** — Fase 2 completa y cerrada (presencia en vivo con Reverb, bandeja, detección de
  incidencias RN-10/11 — RN-12 suspendida hasta la 3.5 —, reconciliación, informes, exportaciones RGPD,
  retención con purga sellada, rotación de clave QR); PR #35.
- **31-08 → 02-09** — **Fase 5, tareas 5.1–5.5** en `feat/fase-5-productizacion`: 5.1
  `installation_settings` + auditoría (manda la BD sobre el `.env`); 5.2 perfiles de cumplimiento
  parametrizados sin retroactividad; 5.3 licencia ed25519 con verificación local y degradación honesta
  (ADR-023: el conjunto legal no tiene caso en el enum); 5.4 instalador de cinco fases con vuelta
  atrás, Compose de producción, etapa ⑧ de la CI (6 escenarios, verde en el run 33573780721); 5.5
  asistente de puesta en marcha + importación masiva (backend, contrato, `frontend-admin`, revisiones y
  arreglo del fallo intermitente de la suite). Todo en `main` vía PR #41 (`3990524`).
- **02-09** — Rama `feat/tarea-5.6-emparejamiento-quiosco` con el hook de Pint.
- **07-09** — **Tarea 5.6** implementada (contrato, backend Kiosk, PWA, panel, runbook, 4 revisiones);
  commit `a1836cf`, PR #42 integrada (`d9a9a91`). Hallazgo del arnés: `ParallelRequests` con
  `DB::purge()` revertía en silencio la transacción del hijo; corregido a `DB::disconnect()`.
- **07-09** — **Tarea 5.7** implementada en `feat/tarea-5.7-actualizador` (sin commit al cerrar la
  sesión): `update.sh`, `versions.txt`, `package.sh`, etapa ⑧b, modo mantenimiento, runbook y docs.
  `VERSION` → 2.1.0.
- **07/08-09** — **Tarea 5.8** (marca blanca) en `feat/tarea-5.8-marca-blanca`: contrato público de marca,
  `web-kit/branding.ts` + `brandingState.ts` + `BrandMark.vue`, gating por licencia (`white_label`) sin
  degradar el nombre, logotipo validado al guardar, idiomas unificados, pantalla «Marca» del panel; cuatro
  agentes en paralelo y tres revisiones. PR #46, CI manual 34197180554.
- **08/09-09** — **Tareas 5.9** (diagnóstico, `doctor`, accesos de soporte), **5.10** (exportación íntegra y telemetría),
  **5.11** (cinco guías de cliente ES/EN con capturas sobre los dobles) y **5.12** (histórico de errores sin PII, `client_errors`
  por el latido); PRs #47–#51. Hallazgo del *bind mount* sobre `tests/Feature` (`TestDiscoveryTest`).
- **09-09** — **Engram** como memoria buscable junto a `HANDOFF.md` (reparto en `CLAUDE.md`). **Tarea 5.11b**: guía de RRHH,
  guía del portal y hoja del empleado **generada por el producto** (`GET /credentials/instructions-sheet`), `CorrectionDialog`
  (RF-PA-04: el panel no podía corregir), Playwright del portal desde cero, `ClientDocumentationTest` a ocho pares; PR #52.
- **09/10-09** — **Fase 5 cerrada** (`current_phase => 5`): siete bloqueantes corregidos en `chore/cierre-fase-5` (`doctor.sh` en
  el paquete, asientos `system.*` del actualizador, pantalla «Ajustes operativos», doc 05 ↔ ADR-023, matriz y etiquetas de
  trazabilidad, CI con ④/⑥/⑦ y cobertura nocturna, `scandir` frente al *bind mount* en `app/`); doc 07 SAMM 1,47 → 1,73.
