# HANDOFF

> **Resumido el 02-09-2026.** El diario completo de sesiones (Fase 1 → tarea 5.5, ~1600 líneas) vive en
> el historial de git: `git show 9b1593d:HANDOFF.md`. Este fichero conserva solo lo vigente. Al añadir
> sesiones nuevas, mantener la disciplina: estado, pendiente, trampas — sin volcados de verificación ni
> listados de ficheros que git ya sabe.

## Estado y objetivo actual

**Rama `feat/tarea-5.8-marca-blanca`**, creada desde `main` (`d83e55b`, PR #43 integrada con *merge commit*).
**Tarea 5.8 «Marca blanca en las tres aplicaciones y en los PDF» (RF-PD-08) IMPLEMENTADA, REVISADA y PROBADA
el 07/08-09-2026**: un commit, PR abierta contra `main` y la etapa ⑧b lanzada a mano (toca
`compose.prod.yaml`; ver «Siguiente acción»).

**Cómo se hizo (vale como receta para la 5.9 y siguientes):** contrato primero (`GET /api/v1/branding`
público y `GET /api/v1/branding/logo`, esquema `Branding`, validación del logotipo en el `PATCH` de ajustes),
tipos regenerados en las tres SPA, módulo compartido `packages/web-kit/src/branding.ts` con su prueba, y
**cuatro agentes en paralelo con fronteras de ficheros disjuntas** (`producto-licencia`: backend, compose,
`.env.example`, docs de cliente; `frontend-quiosco`; `frontend-panel`; `frontend-portal-empleado`) sobre un
brief único; después tres revisores (`revisor-codigo`, `seguridad-cumplimiento`, `ui-ux`) y una segunda
vuelta de los mismos agentes con los hallazgos. Prompt en doc 03 §6.5.3. **No es TDD estricto**: contrato y
pruebas obligatorias por nivel, escritas junto al código (el usuario preguntó; si quiere TDD puro para la
5.9, imponerlo en el brief).

**Lo construido.** Backend: puertos `BrandingLogoReader` y `LocalePolicyProvider` en `Shared` (`LogoImage`,
`LogoFormat`, `LocalePolicy`), `LogoFileInspector` tras el puerto `Product/Application/Port/LogoInspector`
(Deptrac: `ProductHttp` no alcanza `ProductInfrastructure`), `BrandingController` + `BrandingResource` +
`GetBrandingHandler` (nunca 500: con la configuración ilegible devuelve el producto), `LicensedBrandingProvider`
(decorador único del gating por licencia, ADR-023), `DbLocalePolicyProvider` + `App\Support\Locale\NegotiableLocales`
(mismo precedente que `LicenseStateProbe`: `AppFramework` no alcanza `SharedApplicationPort`),
`throttle:branding`, `ETag` con `304`, los tres consumidores migrados (`BrowsershotCardRenderer`,
`CsvLegalExportWriter`, `PeriodReportPdf` con cabecera de marca), `NegotiateLocale` leyendo `LOCALE_*` con
respaldo a config y sin tocar nada en `/health` y `/ready`, `config/branding.php` reescrito (`logo_root`,
límites sin variable de entorno), excepción acotada a `ConvertEmptyStringsToNull` en el `PATCH /settings`
(defecto de la 5.1: no se podía quitar un logotipo), `Feature::WhiteLabel` en `implemented()`. Compose de
producción: volumen `${BRANDING_PATH:-./branding}:/var/kronoqr/branding:ro` en los cuatro servicios de la app;
el de desarrollo monta `backend/storage/app/branding` en la misma ruta (**exige recrear los contenedores con
`make up`** para probar la marca a mano). `web-kit`: `branding.ts` (`parseBranding` —solo acepta `logo_url`
del propio contrato—, `accentOverrides` claro y quiosco —en el quiosco el acento se aclara; el anillo de foco
se oscurece hasta 3:1—, `applyBranding`, `contrastWarnings`, `offeredLocales`, `PRODUCT_ACCENT_COLOR`),
`brandingState.ts` (`createBrandingState`, el estado que envuelven las stores del panel y del portal) y
`components/BrandMark.vue` (logotipo-o-nombre con `alt`, tope de ancho y truncado). Quiosco: `useBranding`
(localStorage + reintento al volver la red), cabecera y confirmación con marca, `LanguageSelector` filtrado y
oculto con un solo idioma, `runtimeCaching` del SW para las dos rutas de marca (única excepción a «la API no
se cachea»). Panel: pantalla **«Marca»** (`/branding`, `settings:*`) con previsualización, avisos de contraste
en lenguaje llano (el del botón principal con `role="alert"`), 422 del logotipo y aviso de plan sin
`white_label`; dobles E2E de `/settings`, `/branding`, `/branding/logo` y `/license`. Portal: `BrandMark` en
cabecera y acceso; idioma inicial anónimo desde `locales.default`. Docs: contrato, doc 02 Anexo B, doc 03
§6.5.3, doc 06 §7, doc 07 §5/§6 (dos riesgos aceptados), ficha 5.8 (nueve «Decisiones tomadas», punto 1 de
«no cubiertos» resuelto), `configuracion.md` §1/§2.2/§2.3/§4, `instalacion.md` §1.8.

**Decisiones que hay que conocer** (todas en la ficha 5.8): (1) manda la BD; `BRANDING_NAME` y
`BRANDING_ACCENT_COLOR` **retiradas** del `.env`; (2) el acento de serie pasa de `#111827` a **`#b8542a`**
(tarjeta e informe con el filete del producto); (3) **`accent_color` público es `null`** cuando rige el valor
de serie —también si el cliente escribe `#b8542a` en cualquier caja— y las SPA no tocan ningún token; (4) el
logotipo es un fichero dentro de `BRANDING_LOGO_ROOT` (solo lectura) validado **al guardar** (`realpath`,
`..` por segmento, PNG/SVG por contenido, 512 KiB, 2048 px, sin `<script`/`on*=`/`<foreignObject`/`<!ENTITY`),
lectura tolerante; (5) `LOCALE_*` gobiernan la negociación de idioma y el selector del quiosco,
`APP_LOCALE`/`APP_SUPPORTED_LOCALES` solo de respaldo; (6) la marca propia es funcionalidad del plan
(`white_label`): sin ella se degradan **color y logotipo, nunca el nombre** (la exportación legal y el sello
del informe identifican al obligado, RL-03/RL-06), las filas se conservan y se siguen editando; (7)
`manifest.name` del quiosco sigue siendo el del producto (compilación). **Nota de versión obligatoria**:
quien tuviera `BRANDING_NAME`/`BRANDING_ACCENT_COLOR` solo en el `.env` verá el valor del catálogo al
actualizar.

**Lo que corrigieron las revisiones (todo aplicado y re-verificado):** (a) *bloqueante ui-ux*: los avisos de
contraste del panel enseñaban los nombres en inglés de `themePairs.ts` → diccionario de tokens a textos
llanos; (b) *bloqueante código*: `ScanView` no pasaba la marca a `ScanConfirmationPanel` → la confirmación
salía siempre con «KronoQR» (prueba unitaria y E2E añadidas); (c) *importante seguridad*: el gating por
licencia degradaba también el nombre en el CSV de la Inspección y en el informe sellado → el nombre no se
degrada nunca; (d) `parseBranding` aceptaba cualquier `logo_url` (la copia del quiosco vive en localStorage)
→ solo `/api/v1/branding/logo[?v=…]`; (e) foco derivado hasta 3:1 (un acento casi blanco dejaba sin anillo
de foco al panel entero); (f) panel y portal duplicaban el módulo de marca y ya divergían → `brandingState.ts`
+ `BrandMark.vue` en `web-kit` (ADR-036); (g) `.env.example` fijaba `BRANDING_LOGO_ROOT=/var/kronoqr/branding`
y en desarrollo nada lo montaba (todo logotipo daba 422) → montaje en `compose.dev.yaml`; (h) `GET /branding`
podía dar 500 con la configuración ilegible; (i) `ETag` prometido y no honrado → `304`; (j) SVG: filtro
ampliado y `<!DOCTYPE svg` reconocido (antes se rechazaba con un mensaje falso); (k) `..` por segmento;
(l) etiqueta `RL-15` mal puesta en dos pruebas (era la regla dura 15); (m) comparación del acento en
minúsculas; (n) pruebas reales de los `catch (Throwable)` del respaldo de idiomas; (o) selector de idioma
oculto con un solo idioma; (p) truncado de nombres de 60 caracteres y tope de ancho de logotipos en las tres
SPA; (q) comentario de rutas y docblock de `SettingKey` alineados; (r) declinada la caché de la huella del
logotipo (una huella obsoleta con `immutable` dejaría tablets con el logotipo viejo para siempre; aviso en
`.env.example` en su lugar).

**Verificado el 08-09:** `make quality` en verde (Rector ignorado como siempre); gitleaks sobre los ficheros
cambiados sin hallazgos; backend dentro del contenedor: Pint, PHPStan 9, Deptrac 0 violaciones, Feature
Product 217 / Compliance 47 / Reporting 176 / Identity 173, Contract 59, Unit 1351, Http+Health+Architecture
181, Integration 419, `qa:traceability --check` y `docs:consistency` OK; `web-kit` 187; quiosco 369
unitarias + 46 E2E + bundle 99,9/250 KiB JS y 4,9/40 KiB CSS; panel 384 unitarias + 68 E2E; portal 79
unitarias. Pruebas nuevas: `BrandingEndpointTest` (público, sin filtrar otras claves, logo por contenido,
404/304/429, autorización negativa con tokens de quiosco y portal, gating por licencia, degradación sin 500),
`LogoFileInspectorTest`, `LogoImageTest`, `LocalePolicyTest`, `branding.spec.ts` y `brandingState.spec.ts`
(web-kit), `useBranding.spec.ts`, `BrandingView.spec.ts`, `branding.store.spec.ts` (panel y portal), E2E
`branding.spec.ts` del quiosco y del panel, casos con marca en los dos `accessibility.spec.ts`.

**Siguiente acción:** vigilar la ejecución manual de la ⑧b sobre la rama y las etapas normales de la PR;
integrar la PR con *merge commit* (nunca squash) cuando todo esté en verde; recrear los contenedores de
desarrollo (`make up`) por el montaje nuevo; y arrancar la **5.9** (`product:doctor` y paquete de
diagnóstico) en rama nueva. Recordatorio: **la ⑧b solo corre en `main`, etiquetas o a mano**.

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
  aceptado con aviso en `.env.example`). Para la 5.9: `doctor` debe comprobar `BRANDING_LOGO_PATH` con el
  mismo `LogoInspector` y avisar si `white_label` no está en el plan pero hay marca configurada.
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
  agentes en paralelo y tres revisiones. PR #44.
