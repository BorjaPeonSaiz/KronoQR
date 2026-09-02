# HANDOFF

> **Resumido el 02-09-2026.** El diario completo de sesiones (Fase 1 → tarea 5.5, ~1600 líneas) vive en
> el historial de git: `git show 9b1593d:HANDOFF.md`. Este fichero conserva solo lo vigente. Al añadir
> sesiones nuevas, mantener la disciplina: estado, pendiente, trampas — sin volcados de verificación ni
> listados de ficheros que git ya sabe.

## Estado y objetivo actual

**Rama `feat/tarea-5.6-emparejamiento-quiosco`** (en `origin`, sincronizada), creada desde `main`
(`3990524`, merge de la PR #41: Fase 5 tareas 5.1–5.5 + Dependabot #36–40 con el lock reparado).
Contiene el hook de Pint (`.claude/settings.json`: PostToolUse que pasa Pint sobre cada `.php` de
`backend/` editado, dentro del contenedor `app`) y este resumen.

**Objetivo: tarea 5.6, «Vinculación de quiosco por código de emparejamiento»** (RF-PD-06,
`frontend-quiosco` + `backend-laravel`, detalle en `plan implementacion/05-fase-5-productizacion.md`).
**NO está empezada.** Siguiente acción: arrancarla con su prompt del doc 03.

Contexto ya preparado para la 5.6 por tareas anteriores:

- `POST /kiosk/pair` y `/kiosk/pair/confirm` **no existen** aún en contrato ni código.
- El paso 8 del asistente ya funciona como omitible (`PUT /setup/steps/kiosk`); el punto de enganche de
  la interfaz está documentado en un comentario de
  `frontend-admin/src/features/onboarding/steps/KioskStep.vue` (formulario del código +
  `setup.recordStep('kiosk', 'completed')`).
- Extender `PlanLimitsDoNotBlockTest` al emparejamiento por código (deuda anotada por la 5.3).
- Escribir `docs/runbooks/alta-nuevo-quiosco.md` (fila 7 del README de runbooks; el paquete de cliente
  lo nombra sin enlazar hasta que exista).
- `kiosk:pairing-code` sin argumento de centro (ADR-040: un centro por instalación).
- **Decisión abierta del usuario:** formato y caducidad del código de emparejamiento.

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

- **5.7:** completar `docs/runbooks/actualizacion-cliente.md` (hoy esqueleto; `install.sh` remite ahí al
  salir con 3) y añadir a la etapa ⑧ la mitad «actualización desde la versión anterior» (RQ-11).
- **5.8 (marca blanca):** migrar `BrowsershotCardRenderer` y `CsvLegalExportWriter` de
  `config('branding.*')` al puerto `BrandingProvider`; decidir `BRANDING_NAME`→`BRANDING_APP_NAME`;
  `APP_SUPPORTED_LOCALES`→`LOCALE_AVAILABLE`. Hoy marca e idiomas **se guardan y auditan pero no se
  aplican** (así lo dicen contrato y docs). Los tokens `--kq-*` de `web-kit` ya admiten sobreescritura
  en tiempo de ejecución.
- **5.9 (`product:doctor`):** punto de enganche documentado en `phase_verify` de `install.sh`; enseñar
  `meta.invalid_keys` de `GET /settings`; avisar si `.env` y BD difieren; comprobar que
  `BRANDING_LOGO_PATH` existe; estado de licencia vía `license:show` (salida 0/1) o campo `license` de
  `/health`; si el paquete de diagnóstico incluye asientos `license_lifecycle`, **redactar
  `customer_name`** (ADR-020); decidir el `scope` de `support_grants`.
- **5.11:** capturas del asistente en `instalacion.md` §1.7 (el texto ya las anticipa) y guía de
  endurecimiento.
- **5.12:** transporte del buffer de errores del cliente (`errorReporter` ya saneado en las tres SPA).
- **Fase 3:** 3.2 paso 9 (alertas de los comandos nocturnos: `onFailure()`, series
  `*_last_failures`, reglas Loki); 3.4 estrena `maximumWeeklyMinutes`/`weekStartsOn`/`holidayCalendar`
  de `CompliancePolicy`; 3.5 reactiva RN-12 (vaciar
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
- Cuarta implementación de base64url en el árbol — candidata a `Shared\Domain\ValueObject\Base64Url`;
  no unificada porque toca material criptográfico de tres tareas.
- La suite Feature depende del orden alfabético de directorios para EXPONER acoplamientos de estado;
  nada detecta una prueba que dependa del vaciado de tablas de trabajo confirmadas.
- `heading-order` (axe, impacto moderado) en `LicenseStep`/`ComplianceProfileStep` al incrustar
  pantallas con `<h2>` propios.
- E2E de la pantalla del perfil de cumplimiento (5.2) sin escribir.

## Trampas del entorno — leer antes de operar

- **`package-lock.json`: cualquier `npm install` en Windows con `node_modules/` presente** pierde las
  plataformas nativas de `@tailwindcss/oxide` y rompe la imagen de Nginx (npm/cli#4828, sufrido dos
  veces). Operar el lock **siempre desde Linux y sin `node_modules`**; receta en la cabecera de
  `infra/docker/nginx/Dockerfile`; guarda en `QualityGatesTest`.
- Los contenedores `node-*` están **estructuralmente rotos** (ADR-036) y se dejan parados: las tres SPA
  se sirven desde el host con `npm run dev` (kiosk 5173, admin 5174, portal 5175).
- La base `fichaje_test` es compartida: **no correr dos suites de backend a la vez** (fallos falsos
  «relation … does not exist»).
- `make trivy-fs` **no termina** sobre el bind mount NTFS; verificar con la CI o con una copia en disco
  Linux. Las pruebas de permisos de fichero (`chmod`) solo valen **dentro del contenedor**.
- Scripts de shell: el `printf` de bash **no admite especificadores posicionales**; y nunca una tubería
  que corte a su productor (`head` tras `tr </dev/urandom`) — con `set -E` + `trap ERR` + `pipefail` el
  SIGPIPE dispara la vuelta atrás dentro de una subshell (prueba que lo prohíbe en
  `tests/Integration/Install/`).
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
- **02-09** — Rama `feat/tarea-5.6-emparejamiento-quiosco` con el hook de Pint; la 5.6 sin empezar.
