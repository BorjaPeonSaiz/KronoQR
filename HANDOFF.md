# HANDOFF

> **Resumido el 23-11-2026.** El HANDOFF completo hasta el 23-11-2026 (publicaciones 2.2.0 a 2.3.1, bloques de correcciones,
> restos de las fases 3 a 5) está en `git show 663221ee:HANDOFF.md`; el diario anterior (Fase 1 → tarea 5.5) en
> `git show 9b1593d:HANDOFF.md`. Este fichero conserva solo lo vigente. Disciplina al añadir: estado, pendiente, trampas y
> siguiente acción; sin volcados de verificación, sin listados de ficheros que git ya sabe y sin historial de la conversación.
> El porqué de cada decisión va a Engram, no aquí.

## Estado y objetivo actual

**KronoQR 2.3.1 publicada el 05-11-2026** (<https://github.com/BorjaPeonSaiz/KronoQR/releases/tag/v2.3.1>): `main` `0bf869a4`,
etiqueta `v2.3.1`, `release.yml` 42101845628 en verde al segundo intento (el primero cayó por un 502 de la API de GitHub).
Versión de parche sin migraciones (`2.3.1 -` en `infra/versions.txt`). CI manual completa en verde (⑧, ⑧b desde `v2.2.1`,
cobertura y mutación completa). Paquete comprobado: `SHA256SUMS`, firma cosign e imágenes sin credenciales.

- Trae los 🟠 V23-1-RV-1 (`closed_by` en el portal), V23-1-QA-1, V23-2-QA-1/2, V23-2-KI-1, V23-2-PO-1/2 y V23-2-PA-1, `/auth/me`
  en la zona `api`, Dependabot #221 a #227, #235, #236, #238 y #241, `laravel/framework` 13.39 y Pest 5.3. V23-1-DV-2 va a la 2.4.0.
- **`rector-laravel` se queda en la 2.5:** la 2.7 trae un paquete abandonado que hace fallar `composer audit`; `dependabot.yml`
  lo ignora. **#149** (`vite-plugin-pwa` 2.0) sigue aparcada hasta la tablet real (bloque 31 de la 2.4.0).
- Después de la publicación se integró #243 (`main` `663221ee`): el simulacro de la fila borrada nunca elige la primera fila.

**Dependencias después de la 2.3.1 (24-11-2026):**

- **#255 integrada:** `laravel/framework` 13.43, que corrige CVE-2026-128083 («Task Output Injection», `<13.40`; `main` estaba
  afectado), y el grupo de Composer de #252. `endroid/qr-code` se queda en 6.1.x porque la 6.2 exige PHP 8.5.
  `config.platform.php = 8.4.25` en `composer.json`, para que no vuelva. Guzzle sigue en la 7.
- La imagen de postgres suma `krb5-libs`, `libxml2` y `libxslt` al `apk upgrade` acotado: 20 HIGH con arreglo publicado.
- **#247** (acciones) integrada. **#252 y #246** cerradas porque las sustituye #255. **#251** (Mailpit, Alertmanager y Loki) en
  integración.
- **#253 (npm):** `vue-router` 5.5 escribe `vue-router:scroll` en `sessionStorage`, siempre y sin opción. Choca con ADR-052 y
  rompe PinRevealDialog, TemporaryPasswordDialog y LanguageSelector. `dependabot.yml` lo ignora a partir de la 5.5, y se pidió
  `@dependabot recreate`: integrar el grupo regenerado si sale en verde.
  - **Decisión del propietario pendiente:** aceptar la clave como excepción de ADR-052 o quedarse en la 5.4. La recomendación es
    quedarse en la 5.4.
- **#149** sigue aparcada hasta la tablet real.

**KronoQR 2.3.0 publicada el 04-11-2026** (PR #233 → `main` `bf36c298`). Plan de correcciones de la 2.3.0 integrado entero
(bloques 0-23, 25, 32-35); lo recortado el 28-10-2026 tiene destino 2.4.0 (ver «Pendiente → 2.4.0»). Doc 07 cerrado para la
2.3.0 con SAMM 1,87. Clave de soporte `156a123c31a9a153` de serie en `config/product.php`.

**Objetivo actual (orden del propietario del 05-11-2026):** llevar la 2.3.1 a la demo y cerrar la verificación final de la 2.3.0.

**Verificación final de la 2.3.0 (solo registrar, en curso):** pasos 1 a 4 de 14; registros en
`docs/verificacion/2.3.0-verificacion-final-tanda-{1,2,3}.md` (la tanda 3 solo tiene la cabecera); método en
[docs/verificacion/plan-verificacion.md](docs/verificacion/plan-verificacion.md). Van 56 hallazgos: 0 🔴, 8 🟠, 51 sin destino.

- Los 🟠 que quedan: V23-1-DV-2 (2.4.0) y los intermitentes no resueltos (ver «CI y pruebas»).
- **Criterio de salida sin cumplir:** los hallazgos sin destino, que reparte el propietario entre 2.3.x y 2.4.0.
- **Doc 07:** Gestión de defectos baja de 2 a 1 si la verificación deja un hallazgo sin destino o se publicó con un 🔴/🟠
  abierto. Decidirlo en el cierre.

**Demo `kronoqr.kodigolab.es`:** sigue en la 2.2.1 (el README §3.1 aún dice 2.2.0). La actualiza el propietario. Los datos de
acceso (usuario, contraseña, teléfono del 2FA) y el enlace al vídeo del TFM van en el formulario de entrega del máster, **nunca
en el repositorio**. Al cambiar la versión desplegada, actualizar README §3.1 y §6.

**Puesta a punto local (05-11-2026, PR #234):** las tres SPA por `https://localhost/{admin,portal,kiosk}/`; `doctor` sin fallos
(7 avisos de desarrollo); `make restore-drill` en verde; `.env` local con `BACKUP_DB_USERNAME=fichaje_backup` (rol creado con
`03-backup-role.sh`); asistente cerrado en la base local, sin licencia ni quiosco. Sin licencia no hay Reverb en `/live`
(`license_absent`, sondeo cada 15 s): la presencia en tiempo real con licencia no se ha probado en local.

**Limpieza de documentación (23-11-2026, PR #254, `main` `05d74e1a`):**

- registros de verificación de la 2.1.0 a la 2.2.1 retirados del árbol (siguen en la etiqueta `v2.3.1`; índice en
  [docs/verificacion/README.md](docs/verificacion/README.md));
- `docs/plan-verificacion-kronoqr.md` y `docs/verificacion-manual.md` movidos a `docs/verificacion/plan-verificacion.md` y
  `docs/verificacion/verificacion-manual.md`;
- doc 03 sin los prompts §6.1-6.5 (los literales viven en `plan implementacion/`); doc 02 §12 reducido a enlace; README del plan
  reducido a índice;
- notas «Al actualizar desde la 2.1.0» y «a la 2.2.0» retiradas de `docs/cliente/operacion.md` y `en/operation.md` (el paquete
  no actualiza desde antes de la 2.2.0), salvo la de las bajas con cese futuro, que siguen citando RRHH y el doc 07;
- enlace roto de `lang/*/console.php` (alta-nuevo-empleado) corregido; este HANDOFF reducido de 1 202 líneas a lo vigente.
- **Material comercial fuera del repositorio** (decisión del propietario, 23-11-2026: el repositorio es público): la guía
  comercial y las publicaciones para redes viven en la carpeta privada de datos del propietario, no en `docs/`. Con la
  limpieza entran el índice del manual de uso (`docs/cliente/README.md`) y el doc 05 §1/§6 («garantiza» → «facilita»).

## Siguiente acción

1. Integrar `docs/fuente-unica-guias-cliente`: una sola fuente para secretos, copias, licencia y códigos de salida en las
   guías de cliente (lo repetido pasa a resumen y enlace). La limpieza se integró en #254 (`05d74e1a`).
2. Cuando el propietario actualice la demo a la 2.3.1: README §3.1 y §6.
3. **Abrir ciclo** en `VERSION` e `infra/versions.txt` (`2.3.2 *` o `2.4.0 *`) **antes** de añadir cualquier migración.
4. Seguir la verificación final de la 2.3.0 desde el paso 5 (tanda 3) y llevar al propietario el reparto de los hallazgos sin
   destino. Otra versión, solo con orden expresa.

## Pendiente

### 2.4.0: lo aplazado del plan de la 2.3.0

Los bloques ⏭️ **24** (entorno de desarrollo), **26** (módulos por puertos y purga con puerto), **27** (Rector bloqueante: hoy
informativo, ~227 ficheros en rojo), **28** (E2E real contra la API y PostgreSQL), **29** (copias fuera del runtime), **30** (red
interna endurecida) y **31** (`vite-plugin-pwa` 2.0, tras la tablet), más las tareas 22.7, 23.1 (resto: `aggregate.test.js` en la
CI, una sola IP con NAT, `INSTANCES` igual en `Makefile` y `run.sh`, margen de RNF-P-08), 23.2, 33.1, 33.7, 33.9, 33.10, 33.11
(resto: plazo propio de `absences`, Reverb `allowed_origins` desde `APP_URL`, filtro del SVG), 33.13 y 34.2. Detalle y motivo en
[docs/verificacion/2.3.0-plan-correcciones.md](docs/verificacion/2.3.0-plan-correcciones.md) («Recorte del 28-10-2026» y «Fuera de
la 2.3.0, con motivo»). También en la 2.4.0: retirar los alias de `RenamedQueuedClasses` (`RenamedQueuedClassesRetirementTest`
lo obliga) y la incidencia del fichaje anterior al alta.

### Código (backend)

- **Emisor de licencias:** `parseOptions()` de `tools/license-issuer/issue.php` se queda con la última aparición de una opción
  repetida (`--features=all --features=a,b`) sin avisar; debe rechazarse antes de firmar. Le costó al propietario una licencia de
  la demo con 3 funcionalidades.
- **`compliance:reconcile-work-record --days`** convierte con `(int)` sin validar (`ReconcileWorkRecordCommand.php:425`);
  `--acknowledge` y `--responsible` ya usan `ctype_digit`. Falta también la prueba de integración con un id de revisión en cadena.
- **`InstallationSiteMissing`** se lanza con `new` sin mensaje en `ReadIncidentBoard.php:86`, `ReadComplianceSummary`,
  `ReadLivePresence`, `ConfirmPairing`, `GeneratePeriodReport`, `ReadAdoptionReport`, `SendWeeklySummaries`,
  `PayrollExportController` y `ConfigurablePayrollDocumentWriter`: el 409 sale con `detail: ""` y se registra como ERROR. Arreglo:
  `::make()` en todos y `dontReport`. Cambia comportamiento.
- **Log `workforce.employees_imported`** (`EmployeeImportController.php:58`) no cuenta `rehire`; el asiento `employee.imported` sí.
- **Código muerto** en `WorkRecordReconciliation::auditPurgedAlongTheYear` (`:286-291`): la expresión admite el 2 de enero pero
  `daysApart <= 1` nunca lo deja pasar (`$daysApart >= 0` es mutante equivalente).
- **Readmisión (bloque 25):** el plan de la importación de ausencias no comprueba el periodo (una fila con solape parcial sale
  `create` y se salta al aplicar); corregir un cese anterior no mueve el `valid_to` del contrato cerrado al readmitir.
- **PIN de una persona suspendida** sigue como `rejected_unknown` (toca el verificador de PIN y el CHECK de ADR-043); carrera de
  microsegundos suspensión/resolución en `RegisterScanHandler::process`.
- **Funciones guarda:** solo `incidents_guard_transition` se vigila por huella; las demás (`shift_entries_guard_*`,
  `incidents_guard_insert`, `zz_sites_guard_timezone`, `*_guard_anonymized*`, las `SECURITY DEFINER`) solo por existencia: un
  `CREATE OR REPLACE` que las vacíe pasa. `incident_context_differs_from_audit` en ADR-057 §4: residuo aceptado, diseño en el ADR.
- **Conciliación:** no detecta una ficha minimizada sin su asiento ni al revés. No hay orden que corte solo los tokens antiguos
  (el `fix` de `access.tokens_without_expiry` remite a `identity:reset-password`).
- **Sesiones:** la baja y los restablecimientos revocan tokens pero no cortan una conexión Reverb abierta (ADR-051, residuo 6).
- `system.updated` no anota si `update.sh` reparó privilegios (`SystemEventPayload` tiene lista cerrada de claves).
- `make seed` tarda ~90 s porque la semilla escribe asientos uno a uno bajo el candado; bajarlo exige asientos en bloque en
  `DatabaseAuditTrail`.
- El 409 de `POST /setup/administrator` (segundo admin) no deja señal; registrarlo sin PII.
- Un fallo de Chromium en la hoja de instrucciones o en la impresión de tarjetas sale como 500 (`Reporting` da 503).
- `EntryDoesNotContinueWorkDay::$concernsNextEntry` y `CorrectionWouldChangeWorkDate::$byVoiding`: o el panel los usa o se quitan.
- `UpdateDepartmentRequest` comprueba `accounts:*` con un `tokenCan()` a mano (lo limpio: puerto `ManagementActor::tokenGrants()`).
- Unos 25 docblocks citan ADR-027 donde quieren decir ADR-010; ~200 líneas de `lang/es` (console, data-export, doctor…) sin tildes
  (algunas las citan pruebas o scripts: cambiarlas a la vez).
- FK `SET NULL`/`CASCADE` sin índice a propósito (`*_by_user_id`, `employees.department_id`…): revisar si alguna empieza a
  borrarse en lote.
- `lang/*/doctor.php` y `infra/scripts/lib/messages-doctor.sh` remiten a `operacion.md` «Las copias tienen que salir del servidor»,
  que desde el 24-11-2026 es un resumen: apuntarlos a `instalacion.md` §6 («`BACKUP_PATH`»), con `BackupOffsiteDoctorCheckTest`.
- **Por comprobar si siguen abiertos** (restos de septiembre que el recorte del 23-11-2026 no pudo verificar; detalle en
  `git show 663221ee:HANDOFF.md`): pausa sin vuelta sin incidencia (3.5); elemento de lote con 503 `ClockOutBeforeClockIn`
  que se reintentaría siempre (3.6, quizá resuelto por ADR-061); semana de resumen semanal fallida que no se recupera sola
  (3.12); índice parcial de `scan_events(occurred_at)` (3.11); señales de `update.sh` durante su propia vuelta atrás;
  R3-CH-03 (500 frente a 503 con Redis caído); `LOG_RETENTION_HOURS` en `product:doctor`; alcance `manager_user_uuid` del
  responsable.

### Contrato y documentación

- `docs/api/openapi.yaml` cita «bloque N» en 4 descripciones (líneas ~361, 12543, 20374, 24029; llegan a los `schema.d.ts`).
- El docblock de `Attendance/Domain/Event/EmployeeClockedOut.php:24` dice «6 h 0 min» (deben ser dos cifras). Cambiar solo un
  comentario de `Domain/` tumba `mutate-changed` (0 mutantes): va con el primer cambio con lógica probada en esa clase.
- Quedan ids de verificación en dos cadenas `message`/`reason` de los `eslint.config.js`.
- Unas noventa operaciones con cuerpo declaran el 415 de D6 pero no su 400 (`UnreadableBody`). Para notas de versión: el 400 del
  quiosco y de `me/login` puede llegar como `bad-request` sin `errors`.
- `PrintCredentialBatchRequest` dice «solo el centro, y es opcional» pero declara `properties: {}`.
- Cabeceras de descarga sin unificar (`X-Kronoqr-Report-Rows` frente a `X-Kronoqr-Export-Rows`); el 503 de mantenimiento no se
  enumera por endpoint.
- Doc 02: párrafo duplicado y corrupto sobre la mutación nocturna cerca de la antigua línea ~946 (comprobar tras la limpieza).
- Techo del formato PDF sin fijar (`docs/verificacion/verificacion-manual.md`).

### Frontend

- `PairingView` del quiosco no usa `useLocalePolicy()`; desvincular no vacía la cola (regla 19).
- `@axe-core/playwright` y `@playwright/test` resuelven en web-kit por el `node_modules` raíz: declararlos en
  `packages/web-kit/package.json` como dependencias de desarrollo.
- Los tres `tsconfig.json` usan `baseUrl`, que TypeScript 7 retira: quitarlo y pasar `vue-tsc`.
- `sanitizeContext` y el búfer de errores están duplicados en `web-kit/clientErrors.ts` y `frontend-kiosk/.../errorReporter.ts`.
- ~50 botones primarios sueltos del panel a `BaseButton`; idiomas en trozos también en el portal (como 14.6 en el panel).
- axe del quiosco solo en inglés; sin dobles para las incidencias `discarded_scan`/`scan_before_revocation`.
- Una tarjeta que ZXing nunca decodifica deja el quiosco en `scan-idle` en silencio (candidato a aviso pasivo «usa tu PIN»).

### CI y pruebas

- **Intermitentes y abiertos:** `AppShellView.spec.ts` y `roleCatalog.spec.ts` del panel (1 de 6 con la máquina cargada);
  `AdministratorStep.spec.ts` en local (importación dinámica del QR); escenario J de ⑧ (traza en `salida-j.trace`); volumen del
  informe por periodo bajo xdebug; V23-1-QA-2 y V23-2-KI-2.
- **Prueba del PIN de ⑧b:** incidencia sin abrir (la herramienta deniega crearlas; detalle en `0b0f161d`). Tampoco se abrieron
  las del 502 de `release.yml` ni la de la fórmula del simulacro (corregida en #243).
- **`TagScanner`:** un `tag:` comentado en Playwright y `foo()->group()` dentro de una prueba aún cuentan como cobertura.
- **Mutación:** vivos en `WorkDay::gapAt` (`WorkDay.php:1078`) y `:1175`. La nocturna por módulo (5 partes + `mutation-total`)
  aún no se ha visto en una noche real: mirar la duración de cada parte; si una pasa de 60 min, partirla.
- **Rendimiento del panel:** la «primera fila» de `perf-large-tables` pasa a `expect` tras dos nocturnas de `main` con margen;
  confirmar que el `::warning` de tablas grandes llega al resumen; `perf-live-presence` no es en frío del todo.
- **Pruebas que faltan:** exportación legal a una FIFO o `/dev/stdout`; carrera real panel/consola y baja concurrente con la
  cadena tomada en otro proceso (hoy dobles); 409 de dos correcciones simultáneas del mismo tramo; dos «Emitir el PIN»
  simultáneos; E2E de cancelar una exportación; E2E de licencia caducada (regla dura 15); E2E de la importación con readmisión y
  mutación de `Absence::fallsWithin`/`rehireDigest`; camino `popen` de `ContainerLogChannel`; V6-BE-4 (ramas de esquema que
  ninguna prueba produce).
- `PinScanTest.php:266`: aserción de tiempo constante que no puede fallar. `ClockTampering` no ve `Pest\Laravel\mock()` ni
  `swap`; `WallClockAssertionsTest` no prohíbe `toBeLessThan` en el fichero que avisa.
- `make alerts-drill`: si Prometheus no contesta, `disparadas_en_prometheus` sale vacío en silencio. `GuideMetricsHavePanelTest`:
  22 series sin panel (trinquete). `kronoqr_backup_*_offsite` sin alerta.
- `GeneratedFilesInventoryTest` falla si corre en el mismo proceso que Feature; la suite Integration completa en local se aborta
  por memoria (256 MB) en `Row.php`.
- Valorar sacar Rector de ① (tope subido de 6 a 10 min en #220).
- Ventana de 503 de una actualización sin medir con una base con volumen (V6-DV-9); `--mode pitr` del simulacro no verifica la
  cadena; `limit_conn` de la VLAN del quiosco diferido (R3-DV-05); imágenes de observabilidad por versión, sin digest.

### Entorno local y demo

- Demo: recrear Loki para que use `retention-guard`.
- Las pruebas dejan cientos de `/tmp/kq-doctor-am-*` en `kronoqr-app`: buscar qué prueba no los borra.
- La BD de desarrollo `fichaje` arrastra ~19 700 `entry_without_audit` de la semilla antigua (`make fresh` + `make seed`, borra
  datos; decisión del propietario).
- El host tiene `frontend-admin/node_modules` desfasado (5 pruebas de virtualización fallan solo ahí): `npm ci` en el host.

### Personas, hardware y decisiones del propietario

- **Validación jurídica (asesoría y DPO del cliente),** guion en `docs/cliente/preguntas-asesoria.md`: plazos de
  `employment_contracts`, `absences` y de la ficha (ADR-060, A-17); cuasi-identificadores residuales y plazos de minimización;
  log técnico con `employee_uuid` en Loki 90 días (A-4); avisos de privacidad del portal y del quiosco; motivo libre de las
  correcciones; retención de periodos de un readmitido y atribución de fichajes rechazados tras la baja.
- **Tablet real:** resistencia de 12 h (`alta-nuevo-quiosco.md` §4.5) y primer fichaje en frío; enfoque y `getSettings()`
  (`focusMode`, `zoom`, `backgroundBlur`); logotipo tras recarga sin red desde la caché del SW; TalkBack y guantes; aviso de
  privacidad largo; `registration.update()` real; tarjeta impresa con nombre largo. Después, bloque 31 (#149).
- **Instalación limpia y los cuatro recorridos de las guías por una persona ajena**, solo con la documentación.
- **k6 en hardware de referencia:** VPS de 4 vCPU / 8 GB unas horas, instalar desde el paquete y
  `make load-test INSTANCES=10 DURATION=120s` (RNF-P-06 absoluto; nunca contra la demo: k6 se niega en `APP_ENV=production`).
- **Revisión externa RS-11:** informe interno y paquete del revisor listos; falta el tercero.
- **Decidir:** si el auditor lee el detalle de jornada y la presencia en vivo (contradice doc 05 §3.4, exige ADR); si
  `--acknowledge` de la conciliación exige rol (hoy cualquier cuenta de gestión, D7); señal persistente de «tarjeta entregada y
  PIN sin emitir»; asiento nominal por fila en el listado de ausencias (`sick_leave`); portal con incidencias o ausencias,
  credenciales visibles para el responsable, códigos de recuperación del 2FA, RF-ID-08 con el portal en internet.
- **Licencia de la demo:** reemitirla con solo `--features=all`.
- Ver un correo real del producto en un cliente de correo.
- **Engram:** falta `"permissions": {"allow": ["mcp__engram"]}` en `~/.claude/settings.json` (comprobado el 23-11: no está).
- Vigilancia normativa: semestral y antes de cada versión mayor (`docs/runbooks/vigilancia-normativa.md`).

## Trampas del entorno — leer antes de operar

### Base de datos y suites de backend

- **Una sola BD de pruebas (`fichaje_test`) en el contenedor `app`:** dos suites con BD a la vez se pisan (`migrate:fresh` por
  proceso; fallos falsos «relation … does not exist»). Con varios agentes, oleadas: primero Architecture/Unit/`node --test`,
  después uno solo con Feature/Integration. `MigrationsRoundTripTest` (todas las migraciones, `CommittedDatabase`) jamás en
  paralelo. La mutación de Unit sí puede ir en paralelo; `make mutate MUTATE_PATHS="a.php,b.php"` la acota.
- `fichaje_test` puede guardar funciones antiguas que `migrate:fresh` no borra: borrarlas a mano si sale «Restos tras deshacer».
- `php artisan test --filter=X` no encuentra Architecture sin `--testsuite=Architecture`.
- **Con `failOnWarning`, un aviso PHP al cargar un fichero de pruebas deja la suite en 1 sin que Pest imprima nada.**
  Diagnóstico: `--log-events-text /tmp/e.txt` y `grep "Triggered PHP Warning"`. Causa típica: `const` global repetida entre
  ficheros (prefijo por fichero, `TestGlobalConstantsTest`).
- **Reloj fijo + token Sanctum:** en Feature, Integration y Contract el reloj se detiene con `FrozenTime::at('…')` (fija `Clock`
  y Carbon a la vez; `FrozenTimeTest` lo vigila). `audit_log` está particionado por año. Si `main` pasa a rojo sin commits, mirar
  primero fechas fijas.
- Pruebas de plan con `CommittedDatabase` + `QueryPlans::analyze()`; una CTE recursiva se estima a ciegas; un índice de expresión
  exige el `ORDER BY` textual; `SET LOCAL` fuera de transacción solo avisa. `CommittedDatabase` restaura los catálogos de
  producto (no escribir `afterEach` para eso).
- Un proveedor `scoped()` (`OperationalSettingsProvider`) memoriza por petición: `app()->forgetScopedInstances()` al cambiar ajuste.
- Pest: `toContain($x, $mensaje)` trata el mensaje como otro elemento; PHPStan 9 rechaza `(int) $mixed` (usar el query builder).
  `Request::create()` añade `Accept-Language: en-us` (el helper `Api` lo anula).
- Tocar una excepción de `Domain/` la mete en `mutate-changed`: necesita pruebas de clave, parámetros y mensaje
  (`Tests\Support\Shared\RejectionTranslations`). Un cambio solo de comentario en `Domain/` da 0 mutantes y tumba la puerta.
  `AggregateBoundaryTest` lee comentarios (citar `ShiftEntry::nextVersion()` cuenta como llamada).
- Mutación en `--parallel`: un `use DateTimeImmutable;` en un fichero de prueba sin namespace rompe el arranque de los hijos.
- Alpine/musl: no existe `GLOB_BRACE`. `Finder::contains()` une varias llamadas con O. Hook de Pint activo: el fichero se
  reformatea al guardar, y un `use` añadido en un Edit anterior al que lo usa desaparece.
- La migración `2026_08_30_100100_grant_read_ability.php` tiene nombre corto a propósito (Larastan).
- Ante un fallo «inesperado», comparar `.env` local con `.env.example`; `composer install` en el contenedor si `vendor` local
  difiere del lock (`RuntimeEnvironmentTest` lo destapa).

### Windows, Docker Desktop y bind mount

- **El bind mount pierde ficheros al recorrer directorios** (`tests/Feature` y `backend/app/`). `phpunit.xml` va por
  subdirectorio y `TestDiscoveryTest` exige declarar cada directorio nuevo de `tests/Feature`; `SourceDiscoveryTest` sale en
  rojo en local a propósito. Cobertura, mutación y Deptrac locales mienten: **toda cifra se lee de la CI**, o se mide en una copia
  dentro del contenedor (`rm -rf /tmp/kq && cp -a . /tmp/kq && cd /tmp/kq && …`, Deptrac con `--no-cache`).
- `make test-unit` en local supera el techo de 6 s por el bind mount; leer el tiempo de la CI, no subir el techo.
- `make trivy-fs` no termina sobre NTFS: vale la CI (a mano: `--timeout 40m` y saltar `backend/storage`, `.claude`,
  `backend/.deptrac.cache`). Las pruebas de `chmod` solo valen dentro del contenedor.
- **`docs/` va `:ro` en `app`:** la matriz se regenera con `make traceability` (~10 min con los E2E, sin agentes activos). El
  fichero está a 0 bytes mientras corre: no hacer `git checkout --` sobre él; comprobar tamaño (~900 KB) antes del commit.
  `TraceabilityMatrixFreshnessTest` falla hasta regenerar. Desde un worktree, `make traceability` lee el checkout principal.
- **`Makefile` bloqueado** («Device or resource busy», «unable to unlink»): un `make.exe` huérfano o la extensión Makefile Tools
  de VS Code. Matar por PID con `Stop-Process` o recargar VS Code; mientras, trabajar en un worktree o
  `git show rev:Makefile > Makefile`.
- Un agente que corre cobertura con ruta de Windows deja `backend/C：/…` (U+F03A): `rm -rf "$(printf 'backend/C\357\200\272')"`
  antes de `git add`. Hay un directorio `C:` sin seguimiento en `backend/` con restos antiguos. Añadir siempre rutas explícitas.
- **Redis local con AOF corrupto** tras apagar el portátil con Docker en marcha: `make down` antes de apagar; reparar con
  `redis-check-aof --fix` como indica `doctor.sh`.
- `main` local se queda atrás: actualizarlo antes de cambiar a él (llegó a ir 1 044 commits atrasado).
- La webcam del portátil no vale para juzgar el enfoque (Windows Studio Effects recorta y desenfoca; sin autoenfoque: 30-50 cm).
- `node-exporter` en Docker Desktop sin `rslave`; `EspacioEnDiscoBajo` y `MetricasDelAnfitrionAusentes` solo en Linux.
  `docker compose -f infra/compose.prod.yaml config` exige un `.env` junto al compose (copiarlo y borrarlo).
- Los contenedores `node-*` son Alpine/musl: Chromium de Playwright no arranca; los E2E van en la CI (job ⑦) o en el host
  (`npm --prefix frontend-x run test:e2e`; navegadores en `~/AppData/Local/ms-playwright`, binario en el `node_modules` raíz).
  Si `npm ci` de los `node-*` queda a medias: `docker volume rm` de los cinco `node-modules-*` y `make up`.
- `node --test load-tests/k6/` falla en Windows: pasar la ruta del fichero.
- **Carga en local:** los 429 salen de `limit_conn`, no de `limit_req`; el veredicto solo vale en el runner o en Linux. El runner
  de GitHub no alcanza RNF-P-06: allí RNF-P-02/06/08 se juzgan contra `baseline.json` (mediana de tres pasadas; rehacer con
  `baseline.js`).

### npm y lock

- **`package-lock.json` solo desde Linux y sin `node_modules`** (`node:24-alpine`, `npm install --package-lock-only
  --ignore-scripts` sobre una copia con solo manifiestos): `npm install` en Windows pierde los binarios nativos de
  `@tailwindcss/oxide` y rompe la imagen de nginx (guarda en `QualityGatesTest`). Avisos nuevos de `npm audit`: `overrides` en el
  `package.json` raíz y `npm audit fix --package-lock-only` igual. `npm audit` bloquea solo producción (`--omit=dev`).
- Una PR de Dependabot de npm integrada mientras otra rama lleva el lock regenerado deja conflicto: tomar los manifiestos de
  `main`, reaplicar y regenerar. Integrar o aplazar las de npm antes de abrir una rama que toque el lock.
- `npm ci` de otro agente reinstala `node_modules` y tumba cualquier `vitest`/`playwright` en vuelo.
- `packages/web-kit` no compila `.vue` en Vitest (a propósito). Vitest corre en `node` por defecto: las specs con DOM llevan
  `// @vitest-environment jsdom`, y un `[Vue warn]` hace fallar la prueba.
- `wrapper.find()` de `@vue/test-utils` nunca es falsy (usar `.exists()`). Con `page.clock`, un `throw` en `setTimeout` no da
  evento `error` (usar `dispatchEvent(new ErrorEvent(…))`), `install()` no pausa el reloj y la cámara falsa no pasa por él.

### Shell, git y herramientas

- **`sh` de la CI es `dash`:** `#!/bin/sh` sin `pipefail` ni `$'\n\t'` (`set -eu`, `IFS="$(printf …)"`); probar con
  `docker run debian:stable-slim sh …`. Una biblioteca nueva en `infra/scripts/lib/` lleva `set -euo pipefail` e `IFS=$'\n\t'`
  (`make sh-lint`), y ese `IFS` anula la división por espacios (`read a b` necesita `IFS=' '` local).
- **`cmd | grep -q` bajo `pipefail` responde NO cuando es SÍ** (EPIPE del productor): «¿existe el comando?» con
  `kq_app_knows_command`, nunca con tubería ni `2>/dev/null`. Nada de tuberías que corten a su productor con `trap ERR`. El
  `printf` de bash no admite posicionales.
- **Herramienta Bash:** el heredoc con comillas simples falla y se come las barras invertidas; textos largos con Write y empalme
  con perl. `perl -0pi` debe leer con `<:raw` (con `:encoding(UTF-8)` recodifica el fichero entero); en `s{}{}` sin `/e`,
  `` $` ``, `$&` y `$'` se interpolan: escapar y comprobar `git diff --stat`. Sin python en la máquina.
- Un commit cuyo mensaje empieza por `#` queda vacío al reaplicarlo en un rebase (`git -c core.commentChar=';'`).
- Ficheros sacados con `docker cp` bajo `sudo` son de root; `cp -a` sobre un directorio ya instalado falla (`certs/`, `branding/`
  de root). Una referencia por digest no deja la etiqueta local: `docker tag` aparte. `install.sh` deja `paquete/.env` en
  `root:root 0600`; `grafana/k6` corre con uid 12345.

### CI y GitHub

- **La CI cancela la ejecución en curso de la misma rama con cada push:** la CI manual (`gh workflow run ci.yml --ref rama`, la
  única que corre ⑧b fuera de `main`) se lanza después del último push y no se empuja nada más hasta que termine.
- Un `workflow_dispatch` nuevo no se lanza desde una rama hasta que existe en `main`. `upload-artifact` v4 excluye directorios
  ocultos salvo `include-hidden-files: true`. El log de un job: `gh api …/actions/jobs/{id}/logs`.
- **Protección de `main`:** 20 comprobaciones obligatorias con `strict` (⑧, ⑧b y cobertura en `gates.yml`); renombrar un job
  obliga a actualizar la protección o las PR quedan bloqueadas.
- Una PR de Dependabot de imágenes necesita CI manual completa antes de integrar (la del PR no corre ⑧; Loki 3.7.8 sin `/bin/sh`
  rompió ⑧).
- gitleaks marca cualquier literal `NOMBRE_KEY=valor`, también en pruebas: afirmar el valor sin el nombre delante.
- Al tocar `docs/api/openapi.yaml`, regenerar los **tres** clientes (`npm run api:generate` en cada SPA).
- Una clave nueva de `installation_settings` va en cinco sitios (`SettingKey`, `propertyNames` de `UpdateSettingsRequest`,
  `SettingsSurface::SCREENS`, pantalla del panel, `configuracion.md` ES/EN y `.env.example`).
- Una serie `.prom` nueva va en `textfileSeries()` de `MetricsCatalogueTest` y en doc 02 §8.2. Toda variable nueva del `.env` que
  no sea de la app va en EXCLUIDAS de `assert-runtime-env.sh` y en `RuntimeEnvironmentTest`.
- Las alertas sobre contadores de Redis que nacen en 1 usan las reglas de grabación de `api.yml`. `promtool` fija el reloj en
  1970. `prometheus.yml` es plantilla (`.template` + `render-config.sh`). Ningún contenedor de terceros recibe `env_file: .env`.
- `check-package-links.sh` rompe ⑧ si un documento que viaja enlaza `../../infra`, `.github` o los runbooks internos
  (`fallo-de-ci.md`, `triaje-hallazgos-seguridad.md`). La hoja del empleado vive en `lang/*/instructions-sheet.php`.
- Ninguna prueba arranca Chromium salvo las de maquetación: inyectar `FakeCardRenderer` y `FakeInstructionsSheetRenderer`.

### Dominio y base de datos

- `shift_entries_no_overlap` cruza jornadas y `WorkDay` solo ve la suya: en la apertura, preguntar por empleado
  (`closedEntryEndingAfter`). Una detección nocturna de hechos que llegan tarde acota por `recorded_at`.
- **Orden de candados:** primero el consultivo de la cadena, después la fila de `daily_totals`
  (`SerializedLedgerWrite::withChainLock`), o hay abrazo mortal con el fichaje.
- Una regla de continuación de estado necesita una cota temporal del perfil; una marca derivada de `scan_events` sigue la cadena
  de versiones de RN-13. Una regla suspendida deja su emisión sin ejercitar: fijar al menos la fábrica del hallazgo.
- Los *triggers* de `shift_entries` e `incidents` frenan también al propietario: escribir a mano exige desactivarlos en la misma
  transacción (runbook `discrepancia-registro-auditoria.md` §3.2). La cadena de auditoría no autentica a quien escribe (aceptado).
- `QRCodeWriter.encode(…, 0, 0)` devuelve la matriz con zona tranquila; el 25 % del nivel Q es redundancia, no tolerancia.
- Un script de datos sintéticos decide la pertenencia por lo que crea él mismo, nunca por convención de nombre.

## Método de trabajo acordado

- **Una rama por bloque o tarea desde `origin/main`** recién actualizado (nada de ramas apiladas), commits convencionales, CI
  manual completa en verde (⑧, ⑧b, cobertura y mutación), revisiones de `revisor-codigo` y `seguridad-cumplimiento` (no escriben),
  PR y **merge commit** (nunca squash: el CHANGELOG sale de los commits y no se edita a mano). Lo integra quien ejecuta el bloque
  si la CI está en verde; integrado y con la CI de `main` en verde, se empieza el siguiente sin preguntar.
- `gh pr merge N --merge` siempre sola; `git push` y `gh workflow run` cada uno en su orden, sin encadenar (el clasificador
  deniega lo encadenado).
- Casillas del plan: las tareas ✅ en el PR del bloque; el título del bloque, en el primer commit del bloque siguiente.
- Cada bloque que cambie algo visible para el hotel añade su párrafo a `docs/cliente/operacion.md` §11 (es/en). Una versión menor
  obliga a regenerar las capturas (`npm run docs:screenshots`); una de parche, no.
- **Publicar:** etiqueta → `ci.yml` → `release.yml`; **nunca empujar etiquetas antiguas con `release.yml` activo** (ejecuta el
  `release.yml` del commit etiquetado, sin la guarda de ADR-053). `load-test.yml` no se dispara con una versión de parche: lanzarlo
  a mano. Detalle en Engram («publicar una versión»).
- Varios agentes en paralelo: el que toque web-kit avisa; la matriz de trazabilidad la regenera quien coordina, al final.
- **Engram** guarda el porqué y el detalle (reparto en `CLAUDE.md`); este fichero manda. Al cerrar tarea o sesión: primero
  `HANDOFF.md`, luego `mem_session_summary`.

## Histórico condensado

- **Agosto-septiembre 2026:** fases 1, 2, 3 y 5 cerradas; v1.0.0 → v2.1.0 (ADR-040: un centro por instalación). Diario en
  `git show 9b1593d:HANDOFF.md`.
- **Octubre-noviembre 2026:** verificación de la 2.1.0 (NO-GO) y correcciones por bloques → 2.2.0 (09-10), 2.2.1 (17-10),
  2.3.0 (04-11) y 2.3.1 (05-11). Detalle en `CHANGELOG.md`, en las PR y en `git show 663221ee:HANDOFF.md`.
- Los registros de verificación de la 2.1.0 a la 2.2.1 viven en la etiqueta `v2.3.1` (índice en `docs/verificacion/README.md`).
