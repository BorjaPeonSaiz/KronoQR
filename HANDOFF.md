# HANDOFF

> **Resumido el 02-09-2026.** El diario completo de sesiones (Fase 1 → tarea 5.5, ~1600 líneas) vive en
> el historial de git: `git show 9b1593d:HANDOFF.md`. Este fichero conserva solo lo vigente. Al añadir
> sesiones nuevas, mantener la disciplina: estado, pendiente, trampas — sin volcados de verificación ni
> listados de ficheros que git ya sabe.

## Estado y objetivo actual

**Rama `feat/tarea-5.7-actualizador`**, creada desde `main` (`d9a9a91`, PR #42 integrada con *merge
commit*). **Tarea 5.7 «Actualizador: copia previa, migraciones encadenadas, verificación, vuelta atrás»
(RF-PD-10, RQ-11) IMPLEMENTADA y REVISADA el 07-09-2026, sin commit todavía**: el árbol de trabajo
tiene todo el cambio (`git status`). Pasó por `revisor-codigo` y `seguridad-cumplimiento`; **todos los
bloqueantes e importantes están aplicados** (ver «Lo que corrigieron las revisiones»), salvo el asiento de
auditoría de la actualización, que es un cambio del dominio de Compliance y queda en «Pendiente».

**Lo construido:** `infra/scripts/update.sh` (los siete pasos del §11.6.4, ~1100 líneas, catálogo ES/EN en
`lib/messages-update.sh`), `infra/versions.txt` (matriz de versiones publicadas con la última migración de
cada una; `*` = versión en desarrollo), `infra/scripts/package.sh` (arma el paquete de entrega; lo usan los
dos jobs de la etapa ⑧), `lib/checks.sh` (contadores, Docker y herramientas, extraídos de `install.sh`) y
`kq_env_set` en `lib/env-file.sh` (el instalador delega ahí su `set_env_value`). Backend: modo mantenimiento
con `/health` y `/ready` exentos y el 503 como `problem+json` (`urn:kronoqr:problem:maintenance`,
`Retry-After`). CI: job **`update` (⑧b)** en `ci.yml` — instala la versión anterior, siembra una cuenta,
actualiza (U1), idempotencia (U2), **vuelta atrás con fallo inyectado** al arrancar la versión nueva y
reintento (U3), restauración de la copia previa en limpio (U4); con `LICENSE_KEY` inválida a propósito. Docs:
runbook `actualizacion-cliente.md` completo (con la vuelta atrás a mano para la salida 5), `operacion.md`
§11, `instalacion.md` (árbol y nota), `restaurar-backup.md` (§2 tabla común y §6.5), README de runbooks,
ficha 5.7 del plan («Decisiones tomadas», puntos 6 y 11 resueltos), doc 08 §2.2/§3, doc 02 §11.6.4 (nota),
doc 03 §6.5.2 (prompt), doc 07 §5 (fila). **`VERSION` sube a 2.1.0.**

**Las decisiones que hay que conocer** (todas escritas en la ficha 5.7 y en la cabecera del script):
(1) el **mantenimiento va antes de la copia** — al revés, un fichaje aceptado entre ambas se perdería en la
vuelta atrás; (2) la **copia es bloqueante sin bandera** para omitirla; (3) la versión nueva arranca con
`--scale nginx=0 --scale horizon=0 --scale scheduler=0 --scale reverb=0` y se sonda por **FastCGI desde
dentro** (`cgi-fcgi`) antes de exponerla; (4) el **punto de control** es una marca en el informe + un lote de
`migrations` por versión (`migrate --path` con la lista de `versions.txt`), y la **vuelta atrás es siempre
restaurar la copia** con `restore.sh --yes` y relanzar la versión anterior desde su directorio, nunca
`migrate:rollback`; (5) tabla común de salidas: `update.sh` **nunca sale con 6**, el `2` cubre «copia previa
fallida» (mantenimiento retirado, instalación intacta); (6) la etiqueta `v2.0.0` es **anterior al
instalador**, así que la etapa ⑧b actualiza **desde el `main` previo** (`github.event.before` en push,
`origin/main` si no; versión sintética `X.Y.(Z+1)-ci` cuando `VERSION` no cambió), y **decidido el 07-09: no se corta
etiqueta 2.0.x, la primera versión instalable y publicada es `v2.1.0`**; la matriz por etiquetas del §11.6.5
empieza con el salto 2.1.0 → 2.2.0.

**Verificado el 07-09:** ShellCheck + shfmt + `set -euo pipefail` en todos los scripts; Pint, **PHPStan 9
completo** y Deptrac limpios; `qa:traceability --check` y `docs:consistency` OK; gitleaks sobre los ficheros
cambiados sin hallazgos; pruebas nuevas: `MaintenanceModeTest`, `UpdateScriptTest` (16 casos: SemVer,
ventana, cadena, reparto de migraciones, matriz inválida, sin licencia como precondición, salida 2 sin
escribir, inglés sin cadenas en español, catálogo), `MigrationsRoundTripTest` (**19 680 tramos sembrados en
8 s, 39 migraciones deshechas en 0,4 s y reaplicadas en 0,6 s**, RN-01/RN-02 válidas, cadena verifica),
tres puertas nuevas en `QualityGatesTest` (paquete + etapa ⑧b, contrato de `versions.txt`, docs);
`tests/Integration/Install` (44) y las suites Contract+Feature+Architecture (1358) en verde con el modo
mantenimiento nuevo.

**Lo que corrigieron las revisiones (todo aplicado y re-verificado):** (a) *bloqueante de seguridad*: la
versión nueva recibía tráfico entre que Nginx aceptaba conexiones y la sonda por loopback — ahora el
contenedor nuevo se pone en mantenimiento antes de publicar el borde, se sonda, se hace `artisan up`, se
comprueba que una ruta de gestión responde 401 y solo entonces arrancan horizon/scheduler/reverb; (b) el
informe se divide en **informe** (0640, uid 1000, sin PII) y **detalle** (`update-<marca>.detalle.log`, 0600
root: salida cruda de migrate/backup/restore/logs); `license:show` ya no se vuelca a fichero; (c) el reintento
tras una vuelta atrás salía 2 por el `.env` que el propio script escribió — ahora se compara por APP_KEY y
clave de copia, no byte a byte; (d) `PUNTO DE CONTROL` se imprime también cuando la versión no trae
migraciones (es el caso de la CI con versión sintética); (e) **`restore.sh` restauraba sin privilegios
(`--no-privileges`) y dejaba al rol de la aplicación sin permisos — ahora conserva los del volcado si el rol
existe y comprueba `has_table_privilege` (escribe `shift_entries`, no altera `audit_log`); `update.sh` lo
verifica tras migrar y tras restaurar; (f) la copia previa se exige `--mode dump` y con `mtime` posterior al
arranque; (g) mensaje propio para la salida 5 cuando solo quedó el mantenimiento puesto («no restaures
ninguna copia»); (h) `kq_env_set` atómico en el mismo directorio (`mktemp file.XXXXXX` + `mv`), sin copia
del `.env` en `/tmp`; (i) `SIGHUP` atrapado; (j) candado ocupado → salida 2 inmediata; (k) `.env.kronoqr-pre-update`
se retira al terminar bien; (l) `attendance:reconcile` con ventana holgada (−2 días…+1, UTC); (m)
`assert-no-secrets.sh` en `.github/scripts/` y ejecutado tras U1 **y** tras U3 sobre salida, informe y detalle;
(n) `app_probe` distingue «sin respuesta» de 200; (o) patrón `running` de `service_is_healthy` (también en
`install.sh`); (p) `LC_COLLATE=C`; (q) contrato: párrafo de mantenimiento en `info.description`; doc 07 §6:
tres riesgos aceptados y uno pendiente (asiento de auditoría). (r) la prueba de la matriz de
versiones lee las migraciones con `dirname(__DIR__, 2)`, no con `base_path()`: las pruebas de arquitectura no
arrancan Laravel y solo pasaba cuando otra prueba lo había arrancado antes.

**Lo que encontró la primera ejecución real de la ⑧b (07-09, PR #43):** (1) `docker ps --format` con
`index .Labels` falla porque `.Labels` es una cadena, y el `2>/dev/null` lo convertía en «no hay
instalación» → `.Label "clave"` y sin silenciar; (2) **`/api/v1/auth/me` sin `Accept: application/json`
respondía 500** («Route [login] not defined»: Laravel redirige a un login que no existe) en **todas** las
versiones, así que la sonda del paso 5 disparaba la vuelta atrás y la vuelta atrás no podía verificarse
(salida 5 con la 2.0.0 sana). Tres arreglos: `redirectGuestsTo(null)` en `bootstrap/app.php` con prueba
en `AuthenticationTest`; las sondas de `update.sh` y la comprobación de la CI mandan `Accept:
application/json`; y la ruta de gestión se exige en las **precondiciones** (`u_c_probe_management`), de
modo que la vuelta atrás solo pide lo que ya era verdad antes de tocar nada.

**Siguiente acción:** (1) un solo commit convencional
(`feat(product): actualizador con copia previa, cadena de versiones y vuelta atras (tarea 5.7)`), push y
PR contra `main`; (2) **la etapa ⑧b solo corre en `main`, etiquetas o a mano**: lanzarla con «Run workflow»
sobre la rama antes de integrar, porque es la única prueba real de `update.sh` con Docker (en local no hay
Linux con root); esperar fallos de primera ejecución en `app_probe` (parseo FastCGI), en `docker compose up
--scale`, en el 401 de `/api/v1/auth/me` tras `artisan up`, o en el U3 (la espera de 180 s a un nginx que
nunca arranca).

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
  coincide con la copia nocturna, sale 2 y el mensaje ya dice que espere; CI ⑧b no ejecutada aún.
- **5.8 (marca blanca):** migrar `BrowsershotCardRenderer` y `CsvLegalExportWriter` de
  `config('branding.*')` al puerto `BrandingProvider`; decidir `BRANDING_NAME`→`BRANDING_APP_NAME`;
  `APP_SUPPORTED_LOCALES`→`LOCALE_AVAILABLE`. Hoy marca e idiomas **se guardan y auditan pero no se
  aplican** (así lo dicen contrato y docs). Los tokens `--kq-*` de `web-kit` ya admiten sobreescritura
  en tiempo de ejecución.
- **5.9 (`product:doctor`):** puntos de enganche documentados en `phase_verify` de `install.sh` **y en
  `phase_start_and_verify` de `update.sh`** (sustituir/añadir a las sondas por FastCGI); enseñar
  `meta.invalid_keys` de `GET /settings`; avisar si `.env` y BD difieren; comprobar que
  `BRANDING_LOGO_PATH` existe; estado de licencia vía `license:show` (salida 0/1) o campo `license` de
  `/health`; si el paquete de diagnóstico incluye asientos `license_lifecycle`, **redactar
  `customer_name`** (ADR-020); decidir el `scope` de `support_grants`; **incluir el último
  `BACKUP_PATH/reports/update-*.log`** en el paquete de diagnóstico (§11.6.6 lo pide).
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
