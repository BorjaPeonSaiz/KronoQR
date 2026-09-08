# HANDOFF

> **Resumido el 02-09-2026.** El diario completo de sesiones (Fase 1 → tarea 5.5, ~1600 líneas) vive en
> el historial de git: `git show 9b1593d:HANDOFF.md`. Este fichero conserva solo lo vigente. Al añadir
> sesiones nuevas, mantener la disciplina: estado, pendiente, trampas — sin volcados de verificación ni
> listados de ficheros que git ya sabe.

## Estado y objetivo actual

**Rama `feat/tarea-5.11-documentacion-cliente`** (desde `main` `e2860be`). **Tarea 5.11 «Documentación de instalación,
operación, configuración y obligaciones legales» (RL-16..RL-21, RF-PD-02) IMPLEMENTADA, REVISADA y PROBADA el
08-09-2026**; ver «Siguiente acción» para el estado del commit, la CI y la PR.

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

**Siguiente acción (5.11):** commit hecho en la rama; empujar, lanzar la CI manual completa (`gh workflow run ci.yml --ref
feat/tarea-5.11-documentacion-cliente`, con ⑧ y ⑧b; **no empujar nada mientras corra: el grupo de concurrencia la
cancelaría**), abrir la PR contra `main` e integrar con *merge commit* cuando esté en verde; después `make up` en `main`
(sin migraciones nuevas).

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
- **5.9 (restos):** `updated_by_user_id` queda `null` cuando escribe un actor de soporte (el actor consta en `audit_log`; si hace falta, `updated_by_support_grant_id`); los `Redis*Metrics` de otros módulos podrían exponer sus claves para que `MetricsCollector` no adivine la forma; ampliar `error_events` en el paquete llega con la 5.12; si el paquete incluyera algún día asientos de `audit_log`, redactar `customer_name` (hoy solo recuentos).
- **5.11 (restos):** **instalación limpia por una persona ajena siguiendo solo la guía** (criterio del doc 03 §6.5;
  ningún script la sustituye); regenerar las capturas (`npm run docs:screenshots` en los dos frontends) en cada
  versión menor —la prueba del sello `img/VERSION` lo recuerda—; los runbooks siguen solo en español; la salida de
  `compliance:apply-retention` y `verify-audit-chain` está cableada en español (la guía inglesa la glosa); la
  cabecera de `instalacion.md` §1.2 y §1.3 quedó sin el bloque duplicado de `--check-only`.
- **5.12:** transporte del buffer de errores del cliente (`errorReporter` ya saneado en las tres SPA).
- **Fase 3:** 3.2 paso 9 (alertas de los comandos nocturnos: `onFailure()`, series
  `*_last_failures`, reglas Loki) **y la regla «quiosco sin latido > 10 min» del doc 01 §9.3 (hoy solo la
  detecta `kiosk:health`; atar `KIOSK_HEALTH_SILENT_AFTER_SECONDS` a la regla por prueba)** **y declarar la ventana de mantenimiento de `update.sh` en la
  observabilidad** (§8.4: silenciar «quiosco sin latido» mientras dura); 3.4 estrena
  `maximumWeeklyMinutes`/`weekStartsOn`/`holidayCalendar` de `CompliancePolicy`; 3.5 reactiva RN-12 (vaciar
  `DetectAttendanceAnomalies::SUSPENDED_UNTIL_DECLARED_BREAK`) y el descanso intra-día de RN-10 con la
  pausa declarada (RF-AT-12); RNF-D-03 fallback de colas Redis→BD; pasada k6 en Linux para el p95
  (RNF-P-02/06); la puerta de cobertura (`make coverage`) no corre en CI.
- **Decisiones de producto abiertas:** **baja de cuentas de gestión** (no existe ni pantalla ni comando; `users.is_active` nunca pasa a
  `false`; la guía de endurecimiento lo declara como límite de la 2.1 y remite al fabricante — hace falta
  `identity:deactivate-user` o una pantalla, y el cambio de contraseña por consola); si el portal muestra incidencias (hoy `incidents: []` siempre; si
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
