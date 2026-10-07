# HANDOFF

> **Resumido el 02-09-2026.** El diario completo de sesiones (Fase 1 → tarea 5.5, ~1600 líneas) vive en
> el historial de git: `git show 9b1593d:HANDOFF.md`. Este fichero conserva solo lo vigente. Al añadir
> sesiones nuevas, mantener la disciplina: estado, pendiente, trampas — sin volcados de verificación ni
> listados de ficheros que git ya sabe.

## Estado y objetivo actual

**Correcciones de la 2.2.0 por bloques** tras la verificación de la 2.1.0 (NO-GO) y la re-verificación sobre `main`. El plan, con
el detalle de cada bloque, vive en [docs/verificacion/2.2.0-plan-correcciones.md](docs/verificacion/2.2.0-plan-correcciones.md);
el método en la sección «Método» de ese plan y en «Método de trabajo acordado» más abajo. **Una rama por bloque desde `origin/main`,
CI manual completa en verde (⑧, ⑧b, cobertura y mutación), revisiones de `revisor-codigo` y `seguridad-cumplimiento`, PR y merge
commit.** Lo integra quien ejecuta el bloque si la CI está en verde.

**Integrados en `main`:** bloques 0 a 12, 12b y 15 a 20. El último, **bloque 12b «PIN de la importación masiva»**, es la PR #116
(`main` `06d6440d`, 07-10-2026): la importación masiva **no emite PIN**; la persona importada nace con el PIN pendiente («Sin emitir»
en listado y ficha) y RRHH lo emite con el flujo de una sola vez al entregar la tarjeta. `RegisterEmployeeCommand` lo expresa con el
enum `PinProvisioning` (`IssueNow` de serie; diferido solo con `viaImport`); la primera emisión sobre un pendiente deja `pin.issued` y
no cuenta en `pin_resets_total`; sin migración. Panel: «Emitir el PIN» en la ficha, aviso en el asistente, enlace al listado filtrado
desde el **resumen final** (dentro del asistente ningún enlace sale de `/setup`), y el diálogo de entrega del tablero de credenciales
avisa si el PIN está pendiente. Doc 07 fila **A-25**; guía de RRHH es/en §2.2 y §2.6. El anterior, el **bloque 12 «Portal abierto a
internet»** (PR #114, `76183531`, ADR-050): PIN de 6 u 8 cifras (`IDENTITY_PIN_LENGTH`), bloqueo por origen en `/api/v1/me/login` con
`identity:origin-unlock`, reserva del intento antes de comparar, 2FA para `responsable_departamento`, `ADMIN_INTERNAL_CIDR`, CORS a
`APP_URL`, filas A-19 a A-24.

**Siguiente acción:** abrir la rama del **bloque 12c** («Pantalla de cuentas de gestión», rama `feat/cuentas-de-gestion`) desde
`origin/main`, siguiendo el plan: **contrato primero** (listar, crear, dar de baja, restablecer contraseña y 2FA, cambio de contraseña
propio; policy solo `admin` con 403 por cada rol), reutilizando los handlers de `identity:deactivate-user` e `identity:reset-password`.
Orden que queda: **12c → 21 → 22 → 13 → 14 → final.** El bloque final repone las etiquetas `v2.1.0` (sobre `9282af6`) **y `v2.0.0`**,
que tampoco está en GitHub (en el remoto solo existe `v1.0.0`; `v2.0.0` sigue en local).

**Lo que destapó el cierre de los bloques 12 y 12b y conviene recordar** (detalle en Engram, temas
`correcciones-2.2.0/bloque-12-portal-internet`, `bloque-12b-pin-importacion` y `bloque-12b-revisiones`):

- **Los contenedores `node-*` del compose de desarrollo son Alpine (musl): el Chromium de Playwright no arranca ahí.** Los E2E se
  validan en el job ⑦ de la CI o con `make e2e` en el host; no instalar navegadores en los contenedores.
- Dentro del asistente de puesta en marcha **ningún `RouterLink` sale de `/setup`** (la guarda manda todo ahí hasta cerrarlo): un
  enlace a otra sección va al resumen final. Las pruebas de componente con router simulado no detectan guardas.
- El tablero de credenciales es de `Identity` y no conoce el `pin_status` de `Workforce`: cruzar módulos se evita leyendo la ficha
  desde el panel al abrir el diálogo.
- Una aserción `getByText('…', { exact: true })` no encuentra un texto que es parte de otro («Estado del PIN: Entregado»): asertar
  con `toContainText` sobre la sección. `make traceability` tarda unos diez minutos y escribe el fichero al terminar: sin agentes.
- La CI de Pest no tiene `APP_URL`: `config/cors.php` toma el mismo valor de serie que `config/app.php`. ⑧b compara con `origin/main`
  (ya cifra el WAL): la semilla de «WAL en claro» detecta si la versión anterior ya cifra. Cada palabra nueva de un mensaje de error
  entra en `ErrorVocabulary`; los clientes TypeScript se regeneran tras **cualquier** retoque de `openapi.yaml`.
- `TraceabilityMatrixFreshnessTest` falla en Arquitectura si se añaden pruebas sin regenerar la matriz. El log de un job en curso solo
  se lee con `gh api …/actions/jobs/{id}/logs`.

**Integrado también el 06-10-2026:** PR #110 (`laravel/reverb` 1.12.0) y #111 (menores de npm), de Dependabot; borradas las ramas
remotas y locales de bloques ya integrados: en los dos sitios queda solo `main` entre bloque y bloque.

> La sección «Pendiente» de más abajo es anterior al plan de correcciones de la 2.2.0 (fases 3 y 4) y no se ha revisado contra él:
> lo que siga vigente debería pasar al plan o a «Fuera de la 2.2.0, con motivo».

## Pendiente

### Del usuario

- **Decididas el 22-09-2026 (3.8):** pantalla de cuentas de gestión en el panel (sí; tarea ad hoc pendiente, reparto en el bloque
  «Fase 4» de «Por tarea»).
  La línea base del runner y la fila del modelo de amenazas (reloj del quiosco) ya se hicieron en `chore/restos-3.8`.
- **Decididas el 24-09-2026 (tras el cierre de la Fase 3), aplicadas en `docs/decisiones-post-cierre-3`:** (1) **un acceso de
  soporte `read_only` ya no alcanza la presencia en vivo ni el resumen de cumplimiento por persona** (policies con `isSupportActor()`,
  prueba por alcance, lista cerrada de `SupportScopeRoutesTest` sin esas dos rutas; sigue leyendo plantilla y el registro horario de
  una persona, auditado); (2) **la mutación completa del dominio sale del push**: por push se muta solo lo que el push toca en el
  dominio (`make mutate-changed`), y la completa con su umbral corre de noche y en el disparo manual previo a cada PR (doc 02 §10.1);
  (3) **los ayudantes, constantes y datasets de las pruebas pueden ir en el idioma del escenario**, como las descripciones; la regla
  del inglés queda atada a herramienta donde aplica (`IdentifierLanguageTest` sobre `backend/app/`, `id-match` de ESLint sobre los
  `src/` de las SPA y `web-kit`) y las constantes globales de un fichero Pest llevan prefijo del fichero (`TestGlobalConstantsTest`).
- **Condiciones del plan antes de la primera venta (decididas el 24-09-2026, tras el cierre de la Fase 3):** (1) **validación
  jurídica**: a la espera de la reunión con la asesoría laboral, con `docs/cliente/preguntas-asesoria.md` como guion; las preguntas
  1 y 2 (plazos de `employment_contracts` y `absences`) abren tarea de `RetentionScope` cuando se contesten; (2) **responsable de
  vigilancia normativa**: es un rol, no software; en el fabricante lo asume el usuario con la asesoría como fuente (primer repaso en
  esa misma reunión; después semestral y antes de cada versión mayor, añadido a la lista del plan 08), y en cada cliente lo designa
  el propio cliente en la puesta en marcha siguiendo `docs/runbooks/vigilancia-normativa.md`; (3) **prueba de campo de 12 h en
  tablet real**: más adelante, con la tarjeta impresa y la recalibración de R16 (bullet «Hardware» de abajo); (4) **costes de
  impresión: dejan de ser condición** — la impresión de las tarjetas es del cliente, en el formato que elija; el producto entrega el
  PDF de la hoja de credenciales y el euro por tarjeta del doc 04 §4.4 queda como orientación, no como precio publicado (doc 04
  «Pendiente de validar» y doc 02 §11 actualizados); (5) **instalación limpia por una persona ajena**: más adelante;
  (6) **k6 en hardware de referencia** (recomendación aceptada por el usuario a falta de fecha): alquilar un VPS Linux del perfil
  mínimo del doc 02 §11.6 (4 vCPU / 8 GB) un par de horas, instalar desde el paquete como hace `load-test.yml` y correr
  `make load-test INSTANCES=10 DURATION=120s` para obtener la cifra absoluta de RNF-P-06 (50 fichajes/s, p95 < 150 ms) que hoy
  nadie ha medido; tarea temprana de la Fase 4 (`devops-observabilidad` + usuario para la máquina), y se repite en cada versión mayor.
- **Generar el par ed25519 una vez** (`php tools/license-issuer/generate-keypair.php`), privada al
  gestor de secretos, pública como valor por defecto de `env('LICENSE_PUBLIC_KEY', '')` en
  `backend/config/license.php`. `make release-gate` lo exige en cada etiqueta `vX.Y.Z`.
- **Decidir si hace falta una señal persistente de «tarjeta entregada y PIN sin emitir»** (sugerencia de la revisión de seguridad
  del bloque 12b): un hallazgo de `product:doctor` o una cifra en el cuadro de impacto (doc 05 ya cuenta «gente sin tarjeta
  entregada»), solo recuento. Hoy el aviso vive en el listado filtrado, la ficha, el asistente y el diálogo de entrega del tablero.
- Hardware: tarjeta impresa y plastificada escaneada en quiosco real; resistencia 12 h en tablet;
  recalibración de estimación R16.
- Plazo de purga de `employment_contracts` **y de `absences`** con la asesoría laboral (hasta entonces se conservan; `absences`
  contiene dato de salud y `RetentionScope` es lista cerrada: doc 07 §6 **A-17**, runbook de derechos §1, `obligaciones-legales.md` §4).
- Techo del formato PDF (`docs/verificacion-manual.md`).
- **Engram (09-09-2026):** en funcionamiento (binario 1.20.0 en `~/.local/bin`, plugin `engram@engram` y MCP `engram` en ámbito
  usuario, protocolo `slim`, proyecto detectado `kronoqr`, nueve memorias sembradas: trampas del entorno, 5.12 y receta de
  orquestación). **Falta solo** añadir `"permissions": {"allow": ["mcp__engram"]}` a `~/.claude/settings.json` (o ejecutar
  `engram setup claude-code --protocol=slim` en una terminal) para que fuera del modo automático no pregunte por cada `mem_*`
  (comprobar; si ya está, borrar).

### Por tarea

- **Fase 4 (restos de la Fase 3 con dueño, 24-09-2026):** cola `reports` propia con configuración de Horizon publicada
  (`devops-observabilidad` + `backend-laravel`); telemetría (span y log) de los endpoints de informes en diferido (3.9) y de
  ausencias (3.10) y el `GenerateReportExportJob` colgando de `QueuedTraceContext` (`devops-observabilidad` + `backend-laravel`);
  partir `stubManagementApi` de `frontend-admin/tests/e2e/support/admin.ts` (~2450 líneas, siete banderas `*Outcome`) por *feature*
  antes de las pantallas de la Fase 4 (`qa-testing`); pantalla de cuentas de gestión + `identity:list-users` + cambio de contraseña
  propio, contrato primero (`backend-laravel` + `frontend-panel`); minimizar también las exportaciones `failed` vencidas de
  `report_exports` (hoy conservan `scope` y `employee_uuid` sin plazo; relajar el `CHECK`) (`backend-laravel` + `qa-testing`);
  `SECURITY.md` y acta de simulacro de brecha (Gestión de incidentes SAMM 1 → 2) (`devops-observabilidad` + `seguridad-cumplimiento`);
  los dos WARN del primer `make dast` (`docs/seguridad/evidencia/dast-2026-09-24.md`): restringir `allowed_origins` de CORS en
  `/api/v1/*` (hoy el valor por defecto de Laravel, `*`; ningún llamador legítimo de otro origen) (`backend-laravel` +
  `seguridad-cumplimiento`) y añadir `Cross-Origin-Resource-Policy: same-origin` en nginx con su fila en `QualityGatesTest`
  (`devops-observabilidad`);
  plazo de corrección por severidad escrito (Gestión de defectos 1 → 2) (`seguridad-cumplimiento`); DFD de las tres SPA y el quiosco
  físico (Modelado de amenazas 2 → 3) (`arquitecto-dominio` + `seguridad-cumplimiento`); prueba que enumere el router y exija
  autorización negativa por ruta (`seguridad-cumplimiento` + `qa-testing`); doc 02 §8.2 sin trece series reales del colector textfile
  (`credentials_coverage_*`, `kronoqr_backup_*`, `kronoqr_maintenance_*`, `presence_metrics_timestamp_seconds`, `retention_*`) y
  ampliar `textfileSeries()` de `MetricsCatalogueTest` al universo real (`devops-observabilidad`); `amtool check-config` real en Pest
  (`devops-observabilidad`); cabeceras de descarga unificadas (`X-Kronoqr-Report-Rows` vs `Export-Rows`, `Report-Digest` vs
  `Export-Sha256`) con `components.headers` en el contrato en la próxima versión (`arquitecto-dominio` decide, `backend-laravel`
  ejecuta); MSI de `Workforce` (`EmployeeCode`, `Employee`, `ImportColumnMap`, `AbsenceType`) por debajo del resto del dominio
  (`qa-testing`); concurrencia real por HTTP donde hoy se prueba el candado: dos `PATCH` de la misma ausencia, dos descargas del mismo
  enlace, dos `POST` de la misma cuenta, dos pasadas de `reporting:weekly-summary` (`qa-testing`); agotar la zona `report-download`
  (30 r/m por IP) (`qa-testing`); E2E de licencia (RF-PD-04/05 y regla dura 15: fichar con licencia caducada) (`qa-testing`); paridad
  ES/EN de los `locales/*.json` del portal (`frontend-portal-empleado`); las dos pruebas del menú lateral de `shell.spec.ts` sin
  etiqueta (`qa-testing`); `docs/runbooks/fallo-de-ci.md` arrastra referencias obsoletas anteriores al cierre (tareas 0.5/0.7/1.1/1.2,
  «la etapa ④ todavía no existe»): limpieza completa del runbook (`devops-observabilidad`); el alias de un `v-for` en la plantilla de
  un `.vue` escapa a `id-match` (si se quiere cerrar, `vue/no-restricted-syntax` sobre `VForExpression`) (`frontend-panel`);
  `ReportExport::withLifecycle()` lleva un `$completedAt` que ningún llamante pasa (`complete()` va por `withFile()`): código muerto
  que hace inmatable un mutante, borrar de la firma (`backend-laravel`); `isDownloadable()` solo se distingue con una fila `failed`
  con `file_path` que el `CHECK` prohíbe: si se quiere matar, `ReportExportFixtures::hydratedFromCorruptRow()` (`qa-testing`). Rector,
  `sanitizeContext`/`lib/checks.sh` y la señal del `409` de `POST /setup/administrator` siguen en «Deuda técnica anotada».
- **3.9 (restos, 23-09-2026):** **cola `reports` propia** para los informes en diferido (hoy todo va a `default`; exige publicar la
  configuración de Horizon y un proceso que la atienda); **`REPORTING_EXPORT_PATH` y `PRODUCT_DATA_EXPORT_PATH` no se validan contra
  `public/` ni `BACKUP_PATH`** (deuda compartida: una comprobación en `doctor` que avise); la instantánea de `scope` es hoy inerte (solo
  `rrhh+` pide exportaciones, alcance sin restricción): el día que el responsable reciba `reports:*`, decidir si un cambio de ámbito
  invalida una exportación en cola; **concurrencia real** de dos descargas del mismo enlace y de dos `POST` de la misma cuenta (hoy
  secuencial + `FOR UPDATE` + índice único parcial); agotar la zona `report-download` (30 r/m por IP) no está probado, solo declarado; las
  ocho situaciones de `/informe-nuevo` se prueban sobre el camino síncrono que el diferido reutiliza, no repetidas en diferido; 6 mutantes (`ReportExport` 135, 248, 364-365; `ReportExportStatus` 71)
  sin cubrir en `ReportExport`; `ComplianceSummary` emite `report-too-large` sin diferido al que remitir (acortar el rango); riesgo
  aceptado: el correo de aviso llega aunque la cuenta se desactive entre pedir y generar (sin enlace ni datos); exportación para la
  Inspección en diferido, plantillas libres de nómina, envío por correo/SFTP y centro de notificaciones: fuera de alcance (decisión 12); **presupuesto de la suite unitaria**: 2181 pruebas en 6,8 s en reposo en esta máquina frente a los 5 s de `make test-unit` (la CI la mide en Linux y la pasó con 2181 en el run 35831869626): si algún día la rechaza, medir qué ficheros pesan antes de subir el presupuesto.
- **3.13 (restos, 24-09-2026):** la columna «Estado» del fichero exportado
  («Fuera del objetivo» / «Sin dato») no se afirma en ningún formato para un indicador en el límite; el texto impreso del PDF no se
  lee (tipografía en subconjunto, sin extractor en el contenedor; mismo compromiso que `PeriodReportPdfSealTest`); `X-Kronoqr-Export-Criteria`
  mide ≈ 5,3 KB en base64 (dentro del `fastcgi_buffer_size` de 32 KB de la nginx del producto): si un proxy del cliente topa las
  cabeceras a 8 KB, pasar las claves de criterio en vez del texto o leerlas del JSON; la atribución de `error_events` al periodo es
  aproximada (`last_seen_at` + `occurrences`, poda de `product:errors:prune`) y así lo dice la línea de criterios; el presupuesto de
  base de datos de una petición es de 30 s en el peor caso (hechos + dos informes de horas con su propio `statement_timeout`): si
  molesta, un `statement_timeout` más corto para las dos llamadas de horas; la línea base de horas **no entra en el asistente de puesta
  en marcha** (enum cerrado de ocho pasos): solo en «Ajustes operativos»; la disponibilidad RNF-D-01 es una aproximación declarada (el latido no cuenta
  intentos); contraste visual con `impacto-adopcion.json` de Grafana en el cierre de fase; fuera de alcance (decisión 10): desglose por
  departamento o quiosco, tendencia de más de dos periodos, envío programado del cuadro, compartirlo anonimizado con el fabricante; la
  skill `dataviz` que cita el reparto de la ficha no existe en `.claude/skills` (se siguió doc 06). Aparte, un `git stash` huérfano
  (`wip-3.13-adoption-dashboard`, duplicado del árbol) quedó en la máquina de desarrollo: `git stash drop` a mano.
- **3.12 (restos, 23-09-2026):** **concurrencia real** de dos pasadas de `reporting:weekly-summary` contra el `UNIQUE` (hoy se prueba con
  una reclamación duplicada, no con dos procesos); una semana cuyo correo falló **no se recupera sola** el lunes siguiente (la pasada
  calcula la semana anterior): se reenvía con `--week`; si molesta, una pasada que revise las N semanas sin fila; `registration.update()`
  real contra un service worker de verdad no se prueba en CI (limitación del entorno): comprobar en una tablet que la comprobación
  horaria detecta una versión publicada y que la recarga no deja fichajes en vuelo; `weekly_summary_deliveries` conserva
  `manager_user_id` sin plazo (coherente con `report_exports`, documentado en `obligaciones-legales.md` §4); `KioskUpdateWindow` MSI
  87,5 % (dos mutantes de formato equivalentes); `npm run test:e2e` del quiosco reconstruye con `--mode test` en cada arranque (unos
  segundos más); fuera de alcance (decisión 12): baja individual del resumen por responsable, resumen para `rrhh`/`admin`, comparación
  entre semanas, ventana distinta por quiosco, forzar la actualización desde el panel, enlace al informe con la semana preseleccionada
  (`PeriodReportView` no lee `route.query`).

- **3.11 (restos, 23-09-2026):** **índice parcial sobre `scan_events(occurred_at)`** para la consulta nocturna de patrones (hoy recorre la
  tabla: aceptado por nocturno y fuera del fichaje; con ~1,2 M de filas a cuatro años en un hotel de 200 personas, si la pasada supera
  unos minutos, migración `CONCURRENTLY` con `/migracion-segura`); **prueba de volumen** de `EloquentCredentialScans` a 30 días ×
  plantilla grande; el desfase de reloj excluye solo RN-16, no las coincidencias (una línea si se quiere); `publishEach()`/`tally()`/
  `measure()` son ya la tercera copia (`DetectAttendanceAnomalies`, `DetectCredentialPatterns`): a la cuarta, extraer; el adaptador
  `EloquentAnomalousPatternHistory` lee `incidents` desde `Attendance/Infrastructure` (pendiente de que `arquitecto-dominio` lo
  ratifique o abra la arista en Deptrac); `turno-abierto-prolongado.md` §5 cita `OBSERVABILITY_METRICS_ENABLED`, que no existe (la real
  es `METRICS_TEXTFILE_ENABLED`); la guía de RRHH §4 bis dice «ocho tipos de incidencia» y la tabla tiene nueve; verificación visual de
  la fila nueva de `integridad-dato.json` en Grafana; casos sin prueba fija: coincidencia a caballo de medianoche civil (se atribuye al
  día del escaneo anterior), escaneo justo en el borde de la ventana, par repartido entre dos quioscos sin alcanzar el umbral en ninguno;
  `OperationalSettings` lleva seis enteros posicionales (un cambio de orden pasa PHPStan); fuera de alcance (decisión 12): detección en
  el momento del fichaje, coincidencias entre más de dos personas como hallazgo propio, umbrales por departamento o quiosco, acción
  automática, correo por hallazgo, patrón sobre fichajes manuales o importados.

- **3.10 (restos, 22-09-2026):** **la carrera se prueba por el candado y no con dos `PATCH` a la vez** (`AbsenceConcurrencyTest` usa `FOR UPDATE NOWAIT` desde una segunda conexión; el escenario HTTP real iría con
  `ParallelRequests`); `translate()` distingue `absences_chk_superseded_consistency` por el nombre de la restricción en el mensaje del
  driver; `PeriodReportVolumeTest` no siembra `absences` (comprobar con ~500 empleados que el `LEFT JOIN` usa el índice parcial);
  los 21 mutantes sin cubrir de `Absence`/`AbsenceType`/`AbsenteeismRule`; el respaldo inalcanzable de `ApplyAbsenceImport` salta la
  fila sin dejar rastro (coherente con los otros dos); sin captura nueva en la guía (se regenera con las demás, resto de 5.11);
  ausencias en el portal del empleado, aviso al responsable al registrar una de su gente y ausencias por horas: fuera de alcance
  (decisión 12); el derecho de acceso (art. 15) se atiende con `GET /absences?employee_uuid=…&status=all` desde `rrhh` (runbook §3),
  no hay exportación por interesado.
- **3.8 (restos, 22-09-2026):** **RS-11 sigue pendiente del tercero**: el informe interno, el paquete del revisor y la evidencia están,
  la revisión externa no; **deuda hermana de H-14**: `update.sh:1874`, `install.sh:1294` y `doctor.sh:266` siguen con `2>/dev/null || true`
  (sin SIGPIPE, pero un fallo real de `docker compose exec` se lee como «el paquete no trae el comando»): migrarlas a
  `kq_app_knows_command` es tarea propia con su prueba; **cuentas de gestión** (pantalla del panel, `identity:list-users`, cambio de
  contraseña por la propia persona; endpoints con policy de `admin` y autorización negativa por rol; las bajas por API colapsan
  «no existe»/«ya inactiva» en una sola respuesta, RS-03): decisión (a) del usuario del 22-09, con dueño en el bloque «Fase 4»;
  `identity:deactivate-user` identifica por correo (el docblock lo justifica) y `identity:2fa-reset` por UUID: unificar si
  `seguridad-cumplimiento` lo prefiere; sin métrica ni span nuevos para los comandos de consola (un contador de rehash publicaría
  cuántos hashes viejos quedan); `SaturacionDelBordeEnElFichaje` mide la capa de aplicación (no hay exportador de Nginx: los `429` de
  `limit_req` siguen sin métrica); umbral de `RechazoDeFirmaQr` (> 20 en 15 min) sin validar con tráfico real; `render-config.sh` de
  Prometheus sin prueba Pest propia (verificado a mano en los cuatro casos); DAST aplazado al cierre de la Fase 3 con dueño
  (`devops-observabilidad`, `make dast` manual); decisiones (b) línea base del runner regenerada con la primera pasada de `load-test.yml`
  y (c) fila «Manipulación del reloj del quiosco» en el modelo de amenazas (doc 01 §8.1, doc 07 §4, ATT&CK T1070.006; el repudio de
  la lectura de datos por un responsable no se añade, lo cubre RS-05): **hechas en `chore/restos-3.8` (22-09-2026)**;
  `SecurityReviewEvidenceTest` no exige que los `BLOQUEANTE` del informe estén cerrados (el §8 no tiene formato fijo); el escenario
  `reject-out-of-order` deja 50 turnos abiertos permanentes en empleados reservados de k6 (deliberado, regla 5);
  `docs/runbooks/brecha-de-seguridad.md` menciona el paquete del revisor sin enlazarlo (no viaja al cliente).
- **3.7 (restos, 18/22-09-2026):** la **licencia** (activación fuera del asistente, renovación, degradación honesta: RF-PD-04/05) no tiene
  recorrido E2E, solo una pasada de axe (fila «pendiente» de la tabla de recorridos de la ficha; la regla dura 15 tampoco tiene E2E:
  propuesta `admin/license.spec.ts` + un fichaje del quiosco con licencia caducada, `producto-licencia` + `qa-testing`); una tarjeta
  tan deteriorada que ZXing nunca decodifica deja el quiosco en `scan-idle` en silencio (cumple la regla 19 pero no dice nada:
  candidato a aviso pasivo «¿No te reconoce? Usa tu PIN» tras N s sin lectura, tarea ad hoc de `frontend-quiosco` + `ui-ux`); las
  dos pruebas del menú lateral de `shell.spec.ts` siguen sin etiqueta (no existe requisito con id para la navegación del panel: darlo
  de alta en doc 01 si se quiere trazar); `@sinonjs/fake-timers` va vendorizado dentro de `vitest` y ni `npm audit` ni Dependabot lo
  ven (control: el `integrity` del tarball y el grupo `vitest-mayor`; doc 07 A-15); las tres optimizaciones que sugiere Vitest 5
  (`fsModuleCache`, `vmThreads`, `isolate: false`) quedan sin evaluar a propósito (comparten estado entre ficheros); el `type-check`
  de quiosco y panel incluye `tsconfig.e2e.json`; el presupuesto de ⑦ sigue siendo aviso (300 s por app); cobertura y MSI oficiales
  se leen de la CI; comprobaciones en tablet real (ficha, decisión 12 ampliada por `ui-ux`: extractores de cocina, tarjeta de una
  temporada, contraluz) siguen siendo manuales; el doc 04/ADR-014 no dice nada del acabado de la tarjeta (mate/brillo) ni de cuándo
  reponerla por criterio visual: decisión del cliente, sin promesa; la regla ESLint `kronoqr/e2e-sin-esperas-por-reloj` va replicada
  byte a byte en los tres `eslint.config.js` (no hay config compartida de ESLint) y no ve un `setTimeout` envuelto en un helper;
  `config/identity.php` conserva un comentario largo sobre la redundancia del nivel Q (explicación, no promesa); `RS-11` con una sola
  prueba remite al bloque «3.8 (restos)» (decisión 13).
- **RN-18 (restos, 18-09-2026):** la salvaguarda de la carrera y el puerto `closedEntryEndingAfter` no tienen unitaria de Application
  (el handler exige un `ConnectionInterface` falso; lo determinista lo cubren dos Feature con `StaleOpenWorkDayRepository`); la
  E2E de la bandeja del panel no usa dobles por tipo (solo `OPEN_INCIDENT`); `verify-after-load.php` compara `filas >= contadas`
  porque el escaneo previo `batch_seed` puede producir su propia fila RN-18; el detalle de jornada no muestra escaneos ni la marca de
  revisión (la hora del fichaje irreconciliable solo se ve en la incidencia; si se quiere en el registro horario es cambio de contrato
  y de panel); `PeriodReportVolumeTest` (RNF-P-05, 5 s) cayó una vez en la CI de la #68 por ruido del runner (5,6 s) y pasó al
  repetir: candidata a intermitente si repite; `make test-unit` en local sigue por encima de los 5 s por el bind mount (6,05 s);
  `load-tests/` queda fuera del alcance de Pint (comillas dobles sin interpolación las detecta una revisión, no una herramienta); doc 05
  §9.2/§9.5 admite reforzar «nada se pierde» con RN-18 (decisión comercial, sin tocar).
- **3.6 (restos, 17-09-2026):** `load-test.yml` corrió el 22-09 (run 35724101995, rojo en RNF-P-02 contra la línea base anterior) y
  su `summary.json` se versionó como línea base nueva (`load-tests/k6/baseline.json`); en el cierre de la Fase 3 se lanzó otra pasada
  (run 35967821828) contra esa línea base; **un elemento de lote con `503` (`ClockOutBeforeClockIn`) se reintentaría para
  siempre** (el contrato dice «conservar en la cola»; candidato a incidencia RN-15); `DailyTotalsSnapshot` en `Domain/Event/` está
  pendiente de ratificar por `arquitecto-dominio`; el `[global]` del pool rendido solo repone `error_log`/`daemonize` frente al
  `php-fpm.conf.default` de la imagen base; `compliance:verify-audit-chain` no admite rango (recorre la cadena entera tras la
  carga); `/metrics` solo es legible desde el contenedor `prometheus` (si la pila del paquete no lo lleva, `summary.json` va sin
  `server_metrics`); `node --test load-tests/k6/` falla en Windows (usar la ruta del fichero); la base de desarrollo queda con ~5 800
  empleados `K6…` («Carga k6»), 81 quioscos `k6-*`, 145 600 escaneos de histórico y `projection_divergence_total` en 6 (tarjetas,
  tokens y cuenta revocados por el cierre); el residuo «fila ausente + fichaje simultáneo» de la reconciliación es A-12 (A-11 y A-12
  del doc 07 no vencen hasta el cierre de la Fase 4).
- **3.5 (restos, 17-09-2026):** **«pausa sin vuelta» sin incidencia** (candidata a tipo nuevo `open_break`/`missing_break_end` con migración y bandeja); la
  divergencia `intent`/`result` solo se ve en la base de datos (ninguna pantalla la muestra); probar en tablet real TalkBack del aviso armado y la banda con guantes;
  captura `quiosco-pausa-confirmada` para la hoja; k6 sobre `lastAcceptedScanOf()` con años de `scan_events` (3.6); la pantalla del
  perfil descarga el catálogo entero de ajustes (con `KIOSK_SERVICE_CODE` en claro para `admin`) para leer una clave; la mitad
  `break_end`-tras-`break_start` de la exención del anti-rebote solo la ejercita un cliente de API (el quiosco nunca envía
  `break_end`); `ScanTarget` es una clase nueva no prevista en el contrato del arquitecto; activar el ajuste en una prueba exige
  `app()->forgetScopedInstances()` (`OperationalSettingsProvider` es `scoped`).
- **3.4 (restos, 16-09-2026):** captura `rrhh-15-cumplimiento` para las dos guías cuando exista el generador en `tests/screenshots/hr-guide.screenshots.ts`;
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
  (BD compartida).
- **3.2 (restos, 10-09-2026):** `amtool check-config` solo corre en `make observability-check`, en la CI y al arrancar el contenedor
  (`AlertmanagerConfigTest` valida con el parser de Symfony, más laxo); `render-config.sh` sin prueba de sus `die` de plantilla
  ausente; las variables de plantilla de Grafana (`label_values`) y los `legendFormat` no se contrastan con el §8.2 ni con la regla
  dura 21; «incidencias por antigüedad» del cuadro de integridad es una aproximación (`incidents_open` en el tiempo: no hay serie por
  edad); «Negocio» no muestra horas contratadas ni impuntualidad (sin serie: 3.13); `kiosk_last_seen_seconds` vive
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
- **3.1 (restos, 10-09-2026):** comprobación de `doctor` que avise si `LOKI_URL` u `OTEL_EXPORTER_OTLP_ENDPOINT` apuntan a un
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
  panel emite siempre con `reissue: false`), tres tipos de incidencia del filtro sin productor hasta la Fase 3 (el apartado de
  ausencias de `guia-rrhh.md` y `en/hr-guide.md` ya está: 3.10); **catorce rutas de gestión siguen sin zona de límite de
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
- **Fase 3:** `holidayCalendar` ya lo consume el informe por periodo (3.10; ninguna regla de incumplimiento lo lee); RN-12 ya deriva del ajuste
  `ATTENDANCE_BREAK_CLOCKING` (3.5) y el descanso intra-día de RN-10 queda fuera por decisión (3.5, decisión 9); RNF-D-03 fallback de colas Redis→BD; pasada k6 en Linux para el p95
  (RNF-P-02/06); la puerta de cobertura (`make coverage`) no corre en CI.
- **Decisiones de producto abiertas:** **cuentas de gestión: DECIDIDO el 22-09-2026, habrá pantalla** (ver «3.8 (restos)»; por consola ya existen
  `identity:deactivate-user` e `identity:reset-password`); si el portal muestra incidencias (hoy `incidents: []` siempre; si
  se activa, solo resueltas) **y si muestra ausencias** (3.10: no, decisión 1; si se activa, sin la nota); si el
  `responsable_departamento` ve credenciales de su gente; códigos de
  recuperación de 2FA (hoy solo `identity:2fa-reset` por consola); si la baja revoca la credencial
  automáticamente; `POST /me/logout` (hoy el token del portal vive hasta caducar, máx. 2 h); la mitad de
  aplicación de RF-ID-08 (requisitos extra de contraseña al exponer el portal a internet).

### Deuda técnica anotada

- **Del cierre del bloque 12b (07-10-2026):** falta la prueba de dos «Emitir el PIN» simultáneos sobre un pendiente (por diseño uno
  deja `pin.issued` y el otro `pin.reset`); `PinIssuanceEndpointsTest` «no deja a nadie sin PIN en el alta individual» sigue con un
  `foreach` contra la convención de §3.5; averiguar qué crea `backend/storage/framework/legal-exports/` (apareció el 06-10-2026 a las
  14:12 y nada del código escribe ahí: las exportaciones van a `storage/app/legal-exports` y `storage/app/tmp/legal-exports`).
- **Del cierre del bloque 12 (06-10-2026):** `PinScanTest.php:266` lleva una aserción de tiempo constante que no puede fallar
  (umbral demasiado holgado): afinarla o sustituirla por la comparación estructural de `PinScanOpenPortalTest`. Y falta un E2E que
  cruce las tres aplicaciones: ajuste a 8 cifras en el panel → restablecer el PIN → fichaje por PIN en el quiosco → acceso al portal.
- Falta una captura de referencia del panel con `LicenseNotice` activo y las 14 secciones (hallazgo opcional de la revisión del
  menú lateral del 10-09-2026; la duplicación `guards.ts`/`AppShellView.vue` quedó pagada en la 3.4 con `shared/ui/navigation.ts`).
- **Rector: 227 ficheros en rojo e ignorado** en `make quality` — aplicar esas reglas o retirarlas del
  conjunto; un paso siempre rojo y siempre ignorado acaba sin leerse.
- XLSX se lee sin cota de descompresión más allá de `max_rows` y los 4 MB (riesgo bajo, consciente).
- El 409 de `POST /setup/administrator` (intento de segundo admin) no deja señal; registrar sin PII (doc 07, fila del 2.º factor,
  abierta en el tercer cierre; `backend-laravel`).
- La suite Feature depende del orden alfabético de directorios para EXPONER acoplamientos de estado;
  nada detecta una prueba que dependa del vaciado de tablas de trabajo confirmadas.
- `heading-order` (axe, impacto moderado) en `LicenseStep`/`ComplianceProfileStep` al incrustar
  pantallas con `<h2>` propios.
- El contrato OpenAPI no enumera el `503` de mantenimiento por endpoint (solo `/scan` y `/scan/batch` lo
  tenían ya); está descrito en `MaintenanceModeTest` y en `ProblemDetails::maintenance`. Decidir si va en
  `info.description` o como respuesta reutilizable en cada ruta.
- `release.yml` publica desde el 24-09-2026 (rama `chore/release-2.1.0`): con la etiqueta `vX.Y.Z` espera a la CI de esa etiqueta,
  construye y escanea las tres imágenes, las empuja al registro (`vars.IMAGE_REGISTRY` o `ghcr.io/<owner>/kronoqr`), arma el paquete,
  el SBOM y `SHA256SUMS` y crea la *release* con las notas del `CHANGELOG`. Sin ejecución real todavía: la primera etiqueta es la prueba.
- **Del cierre de la Fase 5 (`revisor-codigo`, con horas):** unificar `sanitizeContext` y el buffer de errores de cliente de
  `web-kit/clientErrors.ts` y `frontend-kiosk/.../errorReporter.ts` (copia literal, 3–4 h); generar los `urn:kronoqr:problem:*`
  del contrato y atar los ocho literales de las SPA (3–4 h); `PdfDocument::builder()` con `dontCache()` + regla Pest Arch que
  prohíba `new PdfBuilder` fuera (2 h); subir `compose`/`service_state`/`wait_for_healthy`/`edge_probe` a `lib/checks.sh`
  (2–3 h); tres `minutesBetween` ya aplicados; README de `pairing`/`offline` del quiosco (1 h); un fallo de Chromium en la hoja
  sale como `500` (`Reporting` da `503`); `SourceDiscoveryTest` rojo en local por diseño: si estorba, degradarlo a aviso y
  asumir que vuelve a ser invisible.

## Trampas del entorno — leer antes de operar

- **Hay una sola base de datos de pruebas en el contenedor `app`, y las suites con `RefreshDatabase` se pisan entre procesos**: una
  pasada `--testsuite=Integration|Feature` lanzada mientras un agente (o un revisor) ejecuta pruebas filtradas da decenas de fallos
  falsos (retención, licencia, informe de volumen). La pasada completa se ejecuta en solitario; la mutación (`--testsuite=Unit`,
  sin BD) sí puede ir en paralelo, y `make mutate MUTATE_PATHS="a.php,b.php"` la acota a ficheros concretos (3.10).
- **`perl -0pi` con `open "<:encoding(UTF-8)"` sobre un fichero leído en bytes duplica la codificación del fichero entero**
  (803 líneas cambiadas en el plan): el inserto se lee con `<:raw` y el patrón se escribe con escapes `\xc3\xb3`. Y un *heredoc*
  entrecomillado con comillas simples dentro no pasa por la herramienta Bash de esta sesión: para textos largos, el fichero se
  escribe con `Write` y se empalma con perl (3.10).
- **Una tubería `cmd | grep -q` bajo `pipefail` responde NO cuando la respuesta es SÍ**: `grep -q` cierra la entrada al primer acierto, el
  productor (el cliente de Docker volcando `artisan list --raw`) muere de EPIPE y sale 1; medido 5/40 en local. La pregunta «¿existe
  este comando?» se hace con `kq_app_knows_command` (`infra/scripts/lib/app-commands.sh`), nunca con una tubería y nunca con
  `2>/dev/null`. Y dos copias de la misma comprobación en `update.sh` y en `ci.yml` no son una verificación: ⑧b salía verde o rojo
  según a cuál le tocara equivocarse (3.8, H-14).
- **`php artisan test --filter=X` sin `--testsuite=Architecture` no encuentra las pruebas de Architecture** en el contenedor local:
  usar `--testsuite=Architecture --filter=…` (3.8).
- **Trivy en Windows**: `make trivy-fs` cae por el tope de 10 min del recorrido sobre el *bind mount* y por cachés que otros procesos
  reescriben durante el escaneo (`backend/storage/framework/cache/phpstan`, `.claude/`, `backend/.deptrac.cache`): repetir a mano con
  `--timeout 40m --skip-dirs …,backend/storage,.claude --skip-files backend/.deptrac.cache`; el veredicto que vale es el de la CI (3.8).
- **Dos suites Pest con base de datos a la vez se pisan** (`RefreshDatabase` hace `migrate:fresh` por proceso): con varios agentes,
  oleadas: primero los que solo ejecutan Architecture/Unit o `node --test`, después uno solo con Feature/Integration (3.8).
- **`prometheus.yml` ya es plantilla** (`prometheus.yml.template` + `render-config.sh` propio): el job verificado contra `APP_URL` se
  omite cuando `TLS_ALLOW_SELF_SIGNED=true` o `APP_URL` está vacía; editar la plantilla, no el fichero rendido del volumen (3.8).

- **Una PR de Dependabot de npm integrada en `main` mientras otra rama lleva el lock regenerado deja esa PR en conflicto** sobre
  los `package.json` y `package-lock.json`, y el lock no se resuelve a mano: fusionar `main`, tomar los manifiestos de `main`,
  reaplicar lo propio y regenerar el lock desde Linux (`node:24-alpine`, `npm install --package-lock-only --ignore-scripts` sobre una
  copia con solo `package.json` raíz, lock y los cuatro manifiestos; sin `node_modules`). Si una tarea va a tocar el lock, integrar
  o aplazar las PRs de Dependabot de npm antes de abrir la suya (3.7, 22-09).
- **`QRCodeWriter.encode(…, 0, 0)` de `@zxing/library` devuelve la matriz CON zona tranquila (4 módulos por lado)**: un guion que
  derive la versión del símbolo del ancho de esa matriz se equivoca dos escalones (45 → versión 7 cuando el símbolo real de 37 es
  versión 5) y «daña» celdas del margen. Restar la zona tranquila y afirmar que cada celda tocada cae dentro del símbolo (3.7).
- **El 25 % del nivel Q es redundancia, no tolerancia**: ante errores de posición desconocida (un módulo sucio no es un borrado
  conocido) Reed-Solomon corrige como máximo la mitad (medido: 20/134 palabras decodifica siempre, 24 nunca). No prometer
  porcentajes de desgaste; el producto repone la tarjeta y mientras tanto se ficha con PIN (3.7).
- **Con `page.clock` instalado, `window.setTimeout(() => { throw … }, 0)` no produce evento `error` de `window`** (el reloj falso
  ejecuta el callback por llamada directa): provocar errores globales con `window.dispatchEvent(new ErrorEvent('error', …))`;
  `page.clock.install()` no pausa el reloj, así que `runFor(INTERVALO - 1)` no demuestra nada (usar `pauseAt` o afirmar un reintento
  por intervalo); la cámara falsa NO pasa por temporizadores de página, así que `page.clock` no sirve para esperar fotogramas (3.7).
- **`npm ci` de otro agente en el mismo árbol reinstala `node_modules` entero** y tumba cualquier `vitest`/`playwright` en vuelo:
  coordinar la migración de dependencias antes de lanzar E2E en paralelo (3.7).
- **Un commit provisional cuyo mensaje empieza por `#` se vuelve vacío al reaplicarlo en un rebase** (git lo trata como comentario):
  `git -c core.commentChar=';' rebase --continue` o un mensaje que no empiece por `#` (3.7).
- **Playwright local en Windows: los navegadores viven en `~/AppData/Local/ms-playwright` y el binario en el `node_modules` de la raíz**
  (workspace de npm): `npm --prefix frontend-x run test:e2e` funciona; buscar `frontend-x/node_modules/.bin/playwright` no (22-09).

- **`shift_entries_no_overlap` cruza jornadas y el agregado `WorkDay` solo ve la suya**: una regla enunciada sobre «el turno abierto»
  o sobre «los tramos de la jornada» deja fuera el turno de noche cerrado de la jornada anterior; en el camino de apertura hay que
  preguntar por empleado (`WorkDayRepository::closedEntryEndingAfter`, servida por el índice GiST de la propia restricción) (RN-18).
- **Una detección nocturna que acota por `occurred_at` no sirve para hechos que llegan tarde a propósito** (elementos atascados en
  colas): acotar por `recorded_at` y derivar la jornada del `occurred_at` (RN-18).
- **Un `workflow_dispatch` nuevo no se puede lanzar desde una rama hasta que el fichero existe en la rama por defecto** (404 en la API): la primera ejecución de un workflow nuevo es tras integrar; después sí se lanza con `--ref rama` (3.6, restos).
- **El paquete instala con `APP_ENV=production` (lo exige `install.sh`) y el aprovisionamiento de k6 se niega contra producción**: el workflow declara la pila `staging` DESPUÉS de instalar y recrea los roles PHP; ningún camino del producto consulta `isProduction()` (3.6, restos).
- **El runner de GitHub no alcanza RNF-P-06** (4 vCPU compartidas entre servidor y once generadores: ~29 fichajes/s con p95 de decenas de segundos, igual con el pool doblado): allí RNF-P-02/06 se juzgan contra `baseline.json` (`K6_LATENCY_VERDICT=baseline`), nunca contra el umbral (decisión 19).
- **Prueba de carga en Docker Desktop: los `429` salen de `limit_conn conn_per_ip 64`, no de `limit_req`** (3.6): con p95 de 44 s
  cada origen acumula rate × latencia ≈ 400 conexiones abiertas; `summary.json.edge_limits` distingue las dos causas. Un rojo por
  429 en local no dice nada del presupuesto de tasa; el veredicto vale solo en el runner o en Linux.
- **`grafana/k6` corre con uid 12345** y en un runner Linux no escribe en un `.results/` creado por el invocante: `run.sh` lanza con
  `--user "$(id -u):$(id -g)"`. Docker Desktop lo esconde (ignora el uid del bind mount). Y `install.sh` deja `paquete/.env` a
  `root:root 0600`: `docker compose --env-file` desde el usuario del runner necesita el `chown` previo (3.6).
- **`actions/upload-artifact` v4 excluye los directorios ocultos** (`.results/`) salvo `include-hidden-files: true` (3.6).
- **`GLOB_BRACE` no existe en la imagen Alpine/musl del contenedor `app`**: «Undefined constant»; usar `scandir()`/`Finder` (3.6).
- **En una cadena de reemplazo de `s{}{}` de Perl sin `/e`, `$\``, `$&` y `$'` se interpolan**: un reemplazo con `?$\`` (regex escrita
  dentro de código Markdown) insertó 720 líneas del propio documento. Escapar `\$` y comprobar `git diff --stat` contra las líneas
  esperadas (3.6).
- **`Finder::contains()` de Symfony une varias llamadas con O, no con Y** (`MultiplePcreFilterIterator`): dos condiciones van en un
  solo patrón con anticipaciones (3.6).
- **Un script de datos sintéticos que selecciona por convención de nombre (`K6%`) es peligroso sobre un clon de producción**: la
  pertenencia se decide por lo que el propio script crea (departamento propio), con confirmación explícita, y abortando ante
  cualquier coincidencia ajena (3.6, bloqueante de seguridad).
- **Orden de candados entre el fichaje y cualquier escritura que audite**: el fichaje toma el candado consultivo de la cadena
  (`EmployeeClockedIn/Out` se graba antes que `DailyTotalsRecalculated`) y DESPUÉS la fila de `daily_totals`. Todo camino que
  escriba la proyección y deje asiento toma los candados en ese orden (`SerializedLedgerWrite::withChainLock`) o hay abrazo mortal
  y la víctima puede ser el fichaje (3.6, la prueba lo reproduce: 500 antes, 200 después).
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
- **El bind mount también ciega la cobertura y la mutación locales** (24-09-2026, cierre de la Fase 3): los ~90 ficheros de `app/`
  que `SourceDiscoveryTest` lista como perdidos no entran en el informe de cobertura de PHPUnit, Pest los da por no cubiertos y
  `make mutate --covered-only` **no crea ningún mutante** para ellos («0 Mutations for 0 Files» en `AdoptionTarget`; sin la opción,
  todos «uncovered»). No es un hueco de pruebas ni del plugin. Para medir en esta máquina: copiar el backend dentro del contenedor
  y mutar allí: `docker compose --env-file .env -f infra/compose.dev.yaml exec -T -e XDEBUG_MODE=coverage app sh -c 'rm -rf /tmp/kq
  && cp -a . /tmp/kq && cd /tmp/kq && PHP_INI_SCAN_DIR=":$(pwd)/tools/mutation" vendor/bin/pest --mutate --parallel --path=…
  --testsuite=Unit --covered-only --no-cache'` (el dominio entero: 12 min, mismo MSI que la CI). La cifra oficial sigue siendo la de la CI.
- **`docs/trazabilidad-pruebas.md` está a 0 bytes mientras `make traceability` corre** (>3 min; más bajo carga): make trunca el
  fichero al abrir la redirección y el contenedor tarda en generar. Un agente que lo vea vacío y haga `git checkout --` pisa la
  regeneración (pasó en el cierre de la Fase 3). Regenerar solo sin agentes activos y comprobar el tamaño (~900 KB) y la línea «Fase
  en curso» antes del commit.
- **Con `failOnWarning` (desde el cierre de la Fase 3), un aviso PHP en la CARGA de un fichero de pruebas pone la suite en 1 con todo
  en verde y sin que Pest imprima nada** (ni con `--display-all-issues`): PHPUnit lo registra como «Test Runner Triggered PHP
  Warning». Diagnóstico: `vendor/bin/pest --testsuite=X --log-events-text /tmp/e.txt` y `grep "Triggered PHP Warning" /tmp/e.txt`,
  que da fichero y línea. La causa típica es una `const` global repetida entre dos ficheros de la misma suite
  (`AHORA_DEL_FICHAJE` en `RegisterScanTest` y en `TracingDoesNotBlockClockingTest`): prefijo por fichero.
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
- Los contenedores `node-*` **funcionan desde `chore/restos-3.8`** (raíz del workspace en `/app`, `node_modules` en los cinco
  volúmenes `node-modules-*`, solo `node-kiosk` instala; plan 01 §B.7). Si `npm ci` queda a medias: `docker volume rm` de esos
  cinco y `make up`. Servir desde el host con `npm run dev` (5173/5174/5175) sigue valiendo como alternativa.
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
- **Una clave nueva de `installation_settings` va en DOS listas del contrato** (`SettingKey` y el `propertyNames` de
  `UpdateSettingsRequest`), en `SettingsSurface::SCREENS` (Architecture), en la pantalla del panel con su `data-test`, en
  `configuracion.md` ES/EN con la ruta por la que se cambia y en `.env.example`: cinco sitios, tres pruebas (3.5).
- **La hoja impresa del empleado vive en `lang/{es,en}/instructions-sheet.php` y `ClientDocumentationTest` solo comprueba guía ⊇
  código**: añadir texto a la guía no avisa de que el PDF no lo imprime; y tres párrafos más la sacaron de una cara A4
  (`InstructionsSheetLayoutTest` con Chromium real; margen 14 → 12 mm) (3.5).
- **Un proveedor `scoped()` (p. ej. `OperationalSettingsProvider`) memoriza por petición: en una prueba que cambia el ajuste hay
  que `app()->forgetScopedInstances()`** antes de volver a leerlo (3.5).
- **Una regla de continuación de estado («sigue en pausa») necesita SIEMPRE una cota temporal derivada de un umbral del perfil**, o
  el estado se vuelve eterno y absorbe la jornada siguiente; y una marca derivada de `scan_events` (`closed_by`) debe seguir la
  cadena de versiones de RN-13 o la corrección la borra (3.5, bloqueantes de la revisión).
- **Un agente que corre cobertura dentro del contenedor con una ruta de Windows deja `backend/C：/…/unit-clover.xml`** (dos puntos de
  ancho completo U+F03A): borrar con `rm -rf "$(printf 'backend/C\357\200\272')"` antes de `git add -A` (3.5).
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
- **22-09** — **3.7** cerrada (PR #72) y **3.8** completa (PR #74: revisión interna ASVS 2, paquete del revisor, 14 hallazgos
  cerrados con prueba, H-14 del `grep -q` bajo `pipefail`); restos de la 3.8 (`node-*` desde el workspace, línea base del runner,
  fila 12 del modelo de amenazas) en `chore/restos-3.8`.
