# HANDOFF

> **Resumido el 02-09-2026.** El diario completo de sesiones (Fase 1 → tarea 5.5, ~1600 líneas) vive en
> el historial de git: `git show 9b1593d:HANDOFF.md`. Este fichero conserva solo lo vigente. Al añadir
> sesiones nuevas, mantener la disciplina: estado, pendiente, trampas — sin volcados de verificación ni
> listados de ficheros que git ya sabe.

## Estado y objetivo actual

**Rama `feat/tarea-5.9-diagnostico-doctor-soporte`**, creada desde `main` (`d2fe595`: PR #44, #45 y #46
integradas el 08-09-2026 con la CI de `main` completa en verde, ⑧ y ⑧b incluidas, y Node 25 en la imagen de
nginx). **Tarea 5.9 «Paquete de diagnóstico anonimizado, `product:doctor`, accesos de soporte auditados»
(RF-PD-09, RF-PD-11, RF-PD-13, RL-18, RL-19) IMPLEMENTADA, REVISADA y PROBADA el 08-09-2026**: PR contra
`main` pendiente de abrir/vigilar (ver «Siguiente acción»).

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

**Verificado el 08-09:** Pint, PHPStan 9, Deptrac 0 violaciones, **suite completa del backend 3458 en
verde**, `qa:traceability --check`, `docs:consistency --check`, gitleaks (186 commits, 0), contrato Redocly 0
problemas, `make sh-lint` 0, panel 397 unitarias + 76 E2E (5 nuevas de `support.spec.ts` y 3 de
accesibilidad), `web-kit` 187, quiosco y portal `type-check`. A mano en el contenedor: `product:doctor`
(exit 1, tres avisos coherentes), `product:diagnostics` (71 KB, `grep -c "hotel\|@"` = 0), `--verify`
íntegro/alterado, `support:grant`/`support:revoke`, `doctor.sh` con `app` en pie y parado.

**Siguiente acción:** abrir la PR contra `main` con *merge commit* (nunca squash), lanzar a mano la CI
completa con ⑧b sobre la rama (`gh workflow run ci.yml --ref feat/tarea-5.9-diagnostico-doctor-soporte`;
**cuidado: el grupo de concurrencia cancela la ejecución en curso de la misma rama, así que hacerlo cuando no
haya un push reciente corriendo**), integrar cuando esté todo en verde, ejecutar `make up` (migraciones
nuevas de `support_grants`), y arrancar la **5.10** (exportación íntegra y telemetría desactivada) en rama
nueva. Recordatorio: **la ⑧b solo corre en `main`, etiquetas o a mano**.

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

### Por tarea

- **5.7 (restos):** **asientos `system.updated` y `system.restored_from_backup` en `audit_log`** (hallazgo
  importante de `seguridad-cumplimiento`, anotado en doc 07 §6 como pendiente): tras una vuelta atrás la
  cadena es la de la copia y el intervalo descartado es invisible desde el registro; es un cambio del
  catálogo `AuditAction` + caso de uso, para `arquitecto-dominio`, antes del cierre de la Fase 5. Menores:
  extraer `compose()`/`wait_for_healthy`/`edge_probe` de `install.sh` y `update.sh` a `lib/checks.sh`;
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
- **5.9 (restos):** prueba **concurrente** de la ventana de `support_grant.used` (hoy secuencial; la atomicidad la da `Cache::add()`); mutación (`make mutate`) sobre `SupportGrant`, `SupportScope`, `FieldAllowlist` y `SettingsDrift`; `updated_by_user_id` queda `null` cuando escribe un actor de soporte (el actor consta en `audit_log`; si hace falta, `updated_by_support_grant_id`); el campo «motivo» del panel podría sugerir el número de incidencia (el texto libre va a `audit_log`); `SetupStatusResource` formatea `Z` sin `setTimezone(UTC)` (misma trampa que arregló `UtcInstant`, no es de la 5.9); los `Redis*Metrics` de otros módulos podrían exponer sus claves para que `MetricsCollector` no adivine la forma; ampliar `error_events` en el paquete llega con la 5.12; si el paquete incluyera algún día asientos de `audit_log`, redactar `customer_name` (hoy solo recuentos).
- **5.11:** capturas del asistente en `instalacion.md` §1.7 (el texto ya las anticipa), guía de
  endurecimiento, y **`doctor.sh`** (la nota de `instalacion.md` §1.1 lo promete «en una versión posterior
  de la serie 2.x»).
- **5.12:** transporte del buffer de errores del cliente (`errorReporter` ya saneado en las tres SPA).
- **Fase 3:** 3.2 paso 9 (alertas de los comandos nocturnos: `onFailure()`, series
  `*_last_failures`, reglas Loki) **y declarar la ventana de mantenimiento de `update.sh` en la
  observabilidad** (§8.4: silenciar «quiosco sin latido» mientras dura); 3.4 estrena
  `maximumWeeklyMinutes`/`weekStartsOn`/`holidayCalendar` de `CompliancePolicy`; 3.5 reactiva RN-12 (vaciar
  `DetectAttendanceAnomalies::SUSPENDED_UNTIL_DECLARED_BREAK`) y el descanso intra-día de RN-10 con la
  pausa declarada (RF-AT-12); RNF-D-03 fallback de colas Redis→BD; pasada k6 en Linux para el p95
  (RNF-P-02/06); la puerta de cobertura (`make coverage`) no corre en CI.
- **Decisiones de producto abiertas:** si el portal muestra incidencias (hoy `incidents: []` siempre; si
  se activa, solo resueltas); si el `responsable_departamento` ve credenciales de su gente; códigos de
  recuperación de 2FA (hoy solo `identity:2fa-reset` por consola); si la baja revoca la credencial
  automáticamente; `POST /me/logout` (hoy el token del portal vive hasta caducar, máx. 2 h); la mitad de
  aplicación de RF-ID-08 (requisitos extra de contraseña al exponer el portal a internet).

### Deuda técnica anotada

- **Rector: 227 ficheros en rojo e ignorado** en `make quality` — aplicar esas reglas o retirarlas del
  conjunto; un paso siempre rojo y siempre ignorado acaba sin leerse.
- XLSX se lee sin cota de descompresión más allá de `max_rows` y los 4 MB (riesgo bajo, consciente).
- El 409 de `POST /setup/administrator` (intento de segundo admin) no deja señal; registrar sin PII.
- La suite Feature depende del orden alfabético de directorios para EXPONER acoplamientos de estado;
  nada detecta una prueba que dependa del vaciado de tablas de trabajo confirmadas.
- `heading-order` (axe, impacto moderado) en `LicenseStep`/`ComplianceProfileStep` al incrustar
  pantallas con `<h2>` propios.
- E2E de la pantalla del perfil de cumplimiento (5.2) sin escribir.
- El contrato OpenAPI no enumera el `503` de mantenimiento por endpoint (solo `/scan` y `/scan/batch` lo
  tenían ya); está descrito en `MaintenanceModeTest` y en `ProblemDetails::maintenance`. Decidir si va en
  `info.description` o como respuesta reutilizable en cada ruta.
- `release.yml` sigue siendo un marcador: publicar imágenes etiquetadas y el paquete (`package.sh` ya
  existe) es de una tarea 5.x posterior.

## Trampas del entorno — leer antes de operar

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
- gitleaks (job `security`) marca como clave cualquier literal `NOMBRE_KEY=valor` aunque sea un ejemplo
  de prueba: en las aserciones, comprobar el valor sin el nombre de la variable delante.

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
