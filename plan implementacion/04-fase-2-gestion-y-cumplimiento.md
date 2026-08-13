# Fase 2 — Gestión y cumplimiento · Plan ejecutable

| Campo | Valor |
|---|---|
| **Fase** | 2 — Gestión y cumplimiento |
| **Horas** | **86–109 h** (literal del [doc 02 §11](../docs/02-stack-tecnologico-y-plan-implementacion.md), tabla de Fase 2) |
| **Orden de ejecución** | Tercera. El orden real del plan es **0 → 1 → 2 → 5 → 3 → 4** (doc 02 §11) |
| **Tareas** | 12 (2.1 a 2.12) |
| **Documento origen** | [`../docs/02-stack-tecnologico-y-plan-implementacion.md`](../docs/02-stack-tecnologico-y-plan-implementacion.md) §11, tabla «Fase 2 — Gestión y cumplimiento · 86–109 h» |
| **Requisitos** | Anexo A de [`../docs/01-especificaciones-proyecto.md`](../docs/01-especificaciones-proyecto.md) |
| **Precondición de fase** | Fase 0 y Fase 1 cerradas (doc 02 §11.3) |

**Entregable (literal del doc 02 §11):**

> **Entregable:** sistema **legalmente defendible** y operable por RRHH. Es aquí, y no antes, donde se puede poner en producción con tranquilidad.

---

## Índice de tareas

| # | Tarea | h | Agente / Skill |
|---|---|---|---|
| [2.1](#tarea-21--autenticación-de-gestión-completa-2fa-obligatorio-y-rbac-con-ámbito-por-departamento-sobre-la-base-mínima-de-16) | Autenticación de gestión **completa**: 2FA obligatorio y RBAC con ámbito por departamento sobre la base mínima de 1.6 | 8–10 | `backend-laravel`, revisión de `seguridad-cumplimiento` |
| [2.2](#tarea-22--audit_log-encadenado-comando-de-verificación-y-alerta) | `audit_log` encadenado, comando de verificación y alerta | 8–10 | `backend-laravel` + `/revision-cumplimiento` |
| [2.3](#tarea-23--correcciones-trazadas-versionado-catálogo-de-motivos-anulación) | Correcciones trazadas: versionado, catálogo de motivos, anulación | 10–12 | `arquitecto-dominio` → `backend-laravel` |
| [2.4](#tarea-24--panel-presencia-en-vivo-con-reverb-y-fallback) | Panel: presencia en vivo con Reverb y *fallback* | 10–12 | `frontend-panel` + `backend-laravel` |
| [2.5](#tarea-25--panel-detalle-de-jornada-bandeja-de-incidencias-resolución) | Panel: detalle de jornada, bandeja de incidencias, resolución | 10–12 | `frontend-panel` |
| [2.6](#tarea-26--detección-automática-de-incidencias-scheduler) | Detección automática de incidencias (scheduler) | 6–8 | `backend-laravel` + `/nueva-regla-de-negocio` |
| [2.7](#tarea-27--reconciliación-nocturna-con-alerta-de-divergencia) | Reconciliación nocturna con alerta de divergencia | 4–6 | `backend-laravel` |
| [2.8](#tarea-28--informes-por-periodo-contratos-trabajadas-frente-a-contratadas) | Informes por periodo, contratos, trabajadas frente a contratadas | 10–12 | `backend-laravel` + `/informe-nuevo` |
| [2.9](#tarea-29--exportaciones-csvxlsxpdf-y-exportación-legal-para-inspección) | Exportaciones CSV/XLSX/PDF y **exportación legal para Inspección** | 8–10 | `backend-laravel` + `/informe-nuevo` |
| [2.10](#tarea-210--retención-con-confirmación-y-purga-documentada) | Retención con confirmación y purga documentada | 4–6 | `backend-laravel` + `/revision-cumplimiento` |
| [2.11](#tarea-211--copias-cifradas-verificadas-con-prueba-de-restauración) | Copias cifradas, verificadas, con prueba de restauración | 4–6 | `devops-observabilidad` |
| [2.12](#tarea-212--rotación-de-clave-de-firma-con-solape-y-reimpresión-progresiva) | Rotación de clave de firma con solape y reimpresión progresiva | 4–5 | `backend-laravel`, revisión de `seguridad-cumplimiento` |

---

## Requisitos que cubre la fase

Del **Anexo A del doc 01** («Trazabilidad requisito → fase»), literal:

> **Fase 2 — Gestión y cumplimiento** | RF-PA-01..05, RF-IN-01..05, RF-GP-02, RF-PR-01..04, RF-QR-07, RF-ID-01..03 (**completos: 2FA y ámbito por departamento**), RN-10..15, RL-02..04, RL-06..15, RS-05..07

Y la nota del mismo Anexo A sobre el reparto entre fases, literal:

> **Sobre el reparto de `RF-ID-*` y `RL-04`.** La Fase 1 necesita una autenticación de gestión mínima —sin ella, RRHH no puede emitir tarjetas ni ver el panel de estado de credenciales (tarea 1.10)—, pero el 2FA obligatorio y el ámbito por departamento llegan con la tarea 2.1. `RL-04` (fiabilidad e inalterabilidad) se completa en la Fase 2: es donde aterrizan la auditoría encadenada (2.2) y las correcciones versionadas (2.3), que son lo que materializa el requisito.

**Aviso de alcance.** RN-10, RN-11 y RN-12 entran en esta fase como reglas del dominio (Anexo A), pero su **vista de cumplimiento** en el panel es la tarea 3.4 (doc 02 §11, Fase 3: «Vista de cumplimiento: descansos, jornada máxima, exceso semanal | RF-PA-06, RN-10..12»). Y sus umbrales son parámetros del perfil de cumplimiento (doc 01 §4, nota introductoria; RF-PD-07), que se construye en la tarea 5.2 — tensión detallada en la [tarea 2.10](#tarea-210--retención-con-confirmación-y-purga-documentada).

## Agentes protagonistas

Del **doc 03 §2.2**, literal:

> **Fase 2 — Gestión y cumplimiento** | `backend-laravel` y `frontend-panel`, con revisión obligatoria de `seguridad-cumplimiento` en auditoría y rotación de claves

Con `arquitecto-dominio` abriendo la tarea 2.3 (doc 02 §11: `arquitecto-dominio` → `backend-laravel`) y `devops-observabilidad` como dueño de la 2.11.

---

## Las tareas, desarrolladas

### Tarea 2.1 — Autenticación de gestión **completa**: 2FA obligatorio y RBAC con ámbito por departamento sobre la base mínima de 1.6

| | |
|---|---|
| **Horas** | 8–10 |
| **Agente / Skill** | `backend-laravel`, revisión de `seguridad-cumplimiento` |
| **Requisitos** | RF-ID-01..03 (doc 02 §11). Anexo A del doc 01: `RF-ID-01..03` **completos: 2FA y ámbito por departamento**. Añade RS-06 (2FA obligatorio para `admin`, `rrhh` y `auditor`) y RS-05 (todo acceso a datos personales de terceros queda en auditoría) |
| **Precondiciones** | 1.1→1.2 cerradas (dominio; camino crítico §11.3) y **1.6** — «autenticación de gestión mínima (login, roles `admin`/`rrhh`, sin 2FA)», doc 02 §11, nota tras la tabla de Fase 1 |
| **Bloquea a** | **2.2** (§11.3: `2.1→2.2`) y, en cascada, 2.3 → 2.5 y 2.8 → 2.9 |

**Objetivo.** Al terminar, un usuario de gestión entra al panel con contraseña más segundo factor TOTP obligatorio si su rol alcanza a toda la plantilla, y un responsable de departamento no puede ver ni corregir nada fuera de su departamento y su centro, con el intento denegado registrado en auditoría.

**Reglas duras aplicables.**

- **18** — cada endpoint tiene policy y prueba de autorización negativa: esta tarea es la que hace que esa prueba tenga sentido, porque introduce el ámbito por departamento.
- **11** — la credencial es una tarjeta física y **no hay TOTP** para el empleado. El TOTP de esta tarea es **solo** el segundo factor de los usuarios de gestión (RF-ID-01, RS-06); no toca la credencial ni el portal del empleado.
- **12** — el producto no depende del correo del empleado: el 2FA es de los usuarios de gestión (tabla `users`), no de `employees`. Nada de invitaciones por correo.
- **21** — el log de un intento de acceso denegado lleva `employee_uuid`, nunca nombres.
- **15** — la caducidad de la licencia no puede bloquear el acceso al registro legal; el control de acceso no debe acoplarse al estado de licencia.

**Pasos.** Sin skill asignada: se deriva del método de `backend-laravel` (doc 03 §4.3: contrato OpenAPI primero, dirección de dependencias, autorización siempre, auditoría de todo lo relevante e instrumentación).

1. Actualizar `docs/api/openapi.yaml` **antes del código** (ADR-013) con los endpoints del Anexo B del doc 01: `POST /api/v1/auth/login` (público, *throttle* 5 r/m), `POST /api/v1/auth/2fa/verify` (sesión pendiente de 2FA), `POST /api/v1/auth/logout`, `GET /api/v1/auth/me` (usuario, rol y ámbito).
2. Trasladar la tabla de **ámbitos de token** del doc 02 §7.3 a configuración de Sanctum: quiosco (`scan:write`, `roster:read`, `heartbeat:write`, 90 días, rotación automática al 80 % de vida), empleado del portal (`self:read`, sesión corta), responsable (`attendance:read`, `attendance:correct`, `incidents:*`, ámbito departamento, sesión + 2FA), RRHH (`+ employees:*`, `reports:*`, `credentials:*`, sesión + 2FA), auditor (`attendance:read`, `audit:read`, `reports:legal`, solo lectura, ámbito completo, sesión + 2FA), administrador de instalación (`+ settings:*`, `license:*`, `support:*`, `diagnostics:*`, sesión + 2FA).
3. Instalar y configurar `pragmarx/google2fa` (doc 02 §3.1) e imponer 2FA a `admin`, `rrhh` y `auditor` (RS-06). Un usuario de esos roles sin segundo factor configurado no obtiene token con ámbito, solo la sesión pendiente de 2FA.
4. Configurar `spatie/laravel-permission` (doc 02 §3.1: «RBAC con ámbito por departamento») con los seis roles de RF-ID-02: `admin`, `rrhh`, `responsable_departamento`, `auditor`, `empleado`, `kiosk`.
5. Implementar el **ámbito por recurso** de RF-ID-03 como filtro en la consulta, no como comprobación posterior: un responsable solo alcanza empleados de su departamento y centro (`departments.manager_user_id`, doc 01 §5.5).
6. Política de contraseña, bloqueo por intentos y *rate limiting* de `auth` a 5 r/m en la zona de Nginx (doc 02 §7.1, borde) — coordinar con la configuración existente de 1.7.
7. Escribir en `audit_log` el acceso denegado y el acceso a datos personales de terceros (RS-05; skill `/revision-cumplimiento` bloque D: «Accede a datos personales de terceros», «Cambia roles, permisos o configuración»). Depende de 2.2 para el encadenado; hasta entonces, dejar el punto de escritura en su sitio y cerrarlo en 2.2.
8. Instrumentar: `http_requests_total{route,method,status}` ya cubre los 401/403 (doc 02 §8.2); no se crea métrica nueva.
9. **Revisión obligatoria de `seguridad-cumplimiento`** (doc 02 §11, columna Agente / Skill) sobre bloques C (seguridad) y D (auditoría) de `/revision-cumplimiento`. Es un agente de solo lectura: corrige `backend-laravel`.

**Artefactos.**

- `backend/app/Modules/Identity/Http/` — controladores de `auth`, FormRequests, Resources, Policies.
- `backend/app/Modules/Identity/Application/UseCase/`, `.../Port/`.
- `backend/app/Modules/Identity/Infrastructure/Persistence/` — usuarios, roles, permisos, `personal_access_tokens` (doc 01 §5.5).
- `backend/database/migrations/` — columnas de 2FA sobre `users` y tablas de `spatie/laravel-permission`.
- `backend/routes/api_v1.php`.
- `docs/api/openapi.yaml`.

**Pruebas exigidas.** Tabla del §9.5: expone endpoints → **Feature + Contrato** y **autorización negativa por cada rol no autorizado**; tiene recorrido de usuario en el panel → **E2E**.

- Feature + Contrato (Spectator) de `login`, `2fa/verify`, `logout`, `me` → `->group('RF-ID-01', 'RF-ID-02')`.
- Autorización negativa por rol, obligatoria y con verificación de que el intento queda en auditoría (§9.4 «Autorización negativa») → `->group('RF-ID-03', 'RS-05')`.
- Escenario Gherkin del doc 01 §11 «Aislamiento por departamento»: un responsable de *Cocina* pide el detalle de un empleado de *Recepción* → 403 y registro en el trail → `->group('RF-ID-03')`.
- E2E del acceso con segundo factor en el panel → `tag: ['@RF-ID-01', '@RS-06']`.
- Token de quiosco contra endpoints de gestión → 403 (doc 01 §8.1, fila «Elevación») → `->group('RS-04')`.

**Verificación.**

```bash
make quality                       # Pint + PHPStan 9 + Deptrac + Rector dry-run, sin errores
php artisan test --group=RF-ID-03   # Ámbito por departamento en verde
php artisan test tests/Feature/Identity tests/Contract/Identity
php artisan qa:traceability --check  # RF-ID-01..03 con prueba que los referencia
```

Esperado: 403 en todas las pruebas negativas, 200 con 2FA verificado, y `GET /api/v1/auth/me` devolviendo rol y ámbito conforme al esquema de `openapi.yaml`.

**Terminado cuando** (subconjunto aplicable del §10.3): Deptrac en verde · pruebas de los niveles Feature, Contrato, autorización negativa y E2E · trazabilidad en verde · PHPStan 9 sin errores nuevos · contrato OpenAPI actualizado y validado · **autorización probada en negativo por rol** · eventos con relevancia legal (acceso denegado, cambio de rol) escriben en `audit_log` · migración reversible · textos en ES y EN · accesibilidad de la pantalla de segundo factor · nada específico de un cliente en el código.

---

### Tarea 2.2 — `audit_log` encadenado, comando de verificación y alerta

| | |
|---|---|
| **Horas** | 8–10 |
| **Agente / Skill** | `backend-laravel` + `/revision-cumplimiento` |
| **Requisitos** | RL-04, RS-07 (doc 02 §11). Anexo A del doc 01 sitúa `RL-04` completo en esta fase, y la nota aclara: «es donde aterrizan la auditoría encadenada (2.2) y las correcciones versionadas (2.3)». Añade RL-15 (capacidad técnica de determinar el alcance de una brecha a partir de los logs de auditoría) |
| **Precondiciones** | **2.1** (§11.3: `2.1→2.2`) — sin actor autenticado con rol y ámbito no hay `actor_type`/`actor_id` que encadenar |
| **Bloquea a** | **2.3** (§11.3: `2.2 ──► 2.3`) y, en cascada, 2.5 y 2.8 → 2.9 |

**Objetivo.** Existe un `audit_log` solo-append encadenado por hash, verificable a diario por comando, con alerta crítica de seguridad ante cualquier rotura y una métrica que debe permanecer siempre en cero. A partir de aquí se puede afirmar ante una inspección que el registro es **detectablemente inalterable** (doc 02 §7.4).

**Reglas duras aplicables.**

- **6** — toda acción con relevancia legal escribe en `audit_log`, que es solo-append y encadenado por hash, y **el usuario de base de datos de la aplicación no tiene `UPDATE` ni `DELETE` sobre esa tabla**. Es el corazón de la tarea.
- **5** — la auditoría es el soporte de «nada se borra ni se sobrescribe»; la 2.3 se apoya en esto.
- **21** — el `payload` de auditoría no lleva nombres en claro donde baste `employee_uuid`.
- **3** — `occurred_at` en `TIMESTAMPTZ` UTC; la cadena se calcula sobre el valor UTC, no sobre una representación local.
- **16** — `audit_log` tiene 4 años de retención (doc 02 §8.2.1) y viaja a Inspección, no al fabricante.

**Fórmula de la cadena (literal, doc 02 §7.4).**

```
hash_n = SHA256( prev_hash || occurred_at || actor || action || subject || canonical_json(payload) )
```

> La entrada génesis usa `prev_hash = SHA256("FICHAJE-HOTEL-GENESIS")`. Un comando `compliance:verify-audit-chain` recorre la cadena a diario; cualquier rotura dispara alerta crítica de seguridad. Es lo que permite afirmar ante una inspección que el registro **es detectablemente inalterable** (RL-04, RS-07), no solo que "confiamos en que nadie lo tocó".

**Pasos.** Con skill `/revision-cumplimiento` como filtro de cierre (6 bloques: A registro horario, B privacidad, C seguridad, C bis producto licenciado, D trazabilidad de auditoría, E cambios retroactivos). La implementación sigue el método de `backend-laravel`.

1. Migración de `audit_log` con el esquema literal del doc 01 §5.5 —`id`, `occurred_at` (TIMESTAMPTZ), `actor_type`, `actor_id`, `action`, `subject_type`, `subject_id`, `payload` (JSONB), `prev_hash`, `hash`, `ip` (INET), `user_agent`— **creada ya particionada por año** y acompañada de `audit_chain_anchors` ([ADR-027](../docs/adr/ADR-027-audit-log-particionado.md)):

   ```sql
   CREATE TABLE audit_log ( … , PRIMARY KEY (id, occurred_at) )
       PARTITION BY RANGE (occurred_at);

   CREATE TABLE audit_log_2026 PARTITION OF audit_log
       FOR VALUES FROM ('2026-01-01Z') TO ('2027-01-01Z');

   CREATE TABLE audit_chain_anchors (
       id BIGSERIAL PRIMARY KEY,
       partition_year INT NOT NULL UNIQUE,
       first_hash TEXT NOT NULL, last_hash TEXT NOT NULL,
       row_count BIGINT NOT NULL,
       sealed_at TIMESTAMPTZ NOT NULL, sealed_by TEXT NOT NULL
   );
   ```

   > **Por qué particionada desde el minuto cero, y no plana.** La retención de RL-02 (tarea 2.10) tiene que purgar esta tabla a los 4 años, y la regla dura 6 no da `DELETE` al usuario de aplicación sobre ella. Aunque se borrase con otro rol, **borrar filas rompe el eslabón**: la primera fila superviviente apuntaría con su `prev_hash` a una fila inexistente y `compliance:verify-audit-chain` denunciaría rotura **todos los días de forma permanente**, disparando la alerta crítica de RS-07 hasta que alguien la silenciara. La purga tiene que ser `DROP PARTITION`, y **convertir después en particionada una tabla solo-append con valor probatorio y millones de filas no es una migración trivial.** La clave primaria es `(id, occurred_at)` porque PostgreSQL exige que la clave de partición forme parte de toda restricción única; ninguna tabla referencia a `audit_log`, así que no arrastra cambios.

2. Otorgar al usuario de aplicación **solo `INSERT` y `SELECT`** sobre la tabla **y sobre cada una de sus particiones** (doc 01 §5.5, «Permisos»; doc 02 Anexo B: `DB_USERNAME=fichaje_app # Sin DDL. Sin UPDATE/DELETE sobre audit_log`). El `GRANT`/`REVOKE` es parte de la migración o del provisionado, y se verifica con una prueba de integración. **Se provisiona además un segundo rol, el de mantenimiento**, que es el único con permiso de `DROP PARTITION` y el único que ejecuta la purga de la tarea 2.10. No aparece en el `.env` de la aplicación.

   Y con la tabla particionada aparece una obligación operativa nueva: una **tarea programada de creación de particiones**, que crea la del año `N+1` en noviembre y **alerta si falta la del año en curso**. Un `INSERT` sin partición de destino falla, y un fallo de escritura en `audit_log` bloquea la acción auditada: no puede quedarse en silencio, pero tampoco puede llegar a ocurrir.
3. Implementar la **serialización canónica y determinista** del `payload` (`/revision-cumplimiento`, bloque C: «La cadena de hash se calcula sobre una serialización canónica y determinista»). Orden de claves estable, sin espacios variables, UTF-8.
4. Implementar el cálculo de `hash_n` con la fórmula literal del §7.4 y la entrada génesis `prev_hash = SHA256("FICHAJE-HOTEL-GENESIS")`. Sin facades en `Domain/`/`Application/` (doc 02 §3.5).
5. Escritor de auditoría como puerto del módulo `Compliance`, consumido por los demás módulos vía caso de uso o evento de dominio (doc 02 §1.6: la comunicación entre módulos ocurre solo por esas dos vías).
6. Cablear el bloque D de `/revision-cumplimiento` — la lista de qué **debe** escribir en `audit_log`: crear, modificar, anular o cerrar un fichaje; emitir, imprimir, entregar, revocar o reemitir una credencial; provisionar, emparejar o revocar un dispositivo; acceder a datos personales de terceros; generar una exportación legal; cambiar roles, permisos o configuración con efecto en el cálculo de horas; ejecutar una purga por retención. *Ante la duda, sí.*
7. Comando `php artisan compliance:verify-audit-chain` (doc 02 Anexo C) y su registro en el Scheduler con ejecución **diaria** (RS-07: «la cadena se verifica a diario y cualquier rotura dispara alerta crítica de seguridad en menos de 24 h»). **El verificador entiende las anclas desde el primer día** (ADR-027): cuando encuentra una fila cuyo `prev_hash` no existe en la tabla, lo busca como `last_hash` en `audit_chain_anchors`; si encaja, la cadena continúa legítimamente desde ahí e informa de una purga sellada; **si no encaja, es manipulación** y salta la alerta. Sin esta distinción, la primera purga de retención convierte la alerta crítica de RS-07 en ruido diario permanente.
8. Instrumentar `audit_chain_verification_failures_total` (counter, doc 02 §8.2) y dejarla en cero. Doc 02 §8.2: «`projection_divergence_total` y `audit_chain_verification_failures_total` deben permanecer **siempre en cero**. Cualquier incremento es un incidente de integridad, no una métrica de tendencia».
9. Regla de alerta y runbook. Del catálogo del doc 01 §9.3: **«Rotura de la cadena de hash de auditoría | cualquiera | Crítica (seguridad)»**. Runbook `docs/runbooks/rotura-cadena-auditoria.md` (doc 02 §12: «**Incidente de seguridad.** Incluye preservación de evidencia»). Doc 02 §8.4: cada alerta lleva destinatario, umbral y enlace a su runbook; una alerta sin procedimiento es ruido.
10. Refuerzo opcional del §7.4, a decidir con el cliente: publicar semanalmente el último hash en un medio externo (correo firmado a la asesoría, servicio de sellado de tiempo) para anclar la cadena.
11. Pasar `/revision-cumplimiento` completo y adjuntar el informe con su formato de hallazgo (severidad `BLOQUEANTE | REVISAR | OBSERVACIÓN`, sección, ubicación, problema, consecuencia, corrección y requisito).

**Artefactos.**

- `backend/app/Modules/Compliance/Domain/` — cálculo del hash y objeto de valor de la entrada de auditoría (dominio puro).
- `backend/app/Modules/Compliance/Application/Port/` — puerto del escritor de auditoría.
- `backend/app/Modules/Compliance/Infrastructure/Persistence/` — modelo solo-append y repositorio.
- `backend/database/migrations/` — `create_audit_log_table` **particionada por año**, `create_audit_chain_anchors_table`, la partición del año en curso, y los `GRANT`/`REVOKE` de los **dos** roles (aplicación y mantenimiento).
- `backend/app/Modules/Compliance/…` — tarea programada de creación de la partición del año siguiente, con su alerta si falta la del año en curso.
- `backend/app/Modules/Compliance/…` comando de consola `compliance:verify-audit-chain`.
- `infra/observability/` — regla de alerta de rotura de cadena.
- `docs/runbooks/rotura-cadena-auditoria.md`.

**Pruebas exigidas.** Tabla del §9.5: toca **esquema y restricción** de base de datos → **Integración**. No expone endpoint del Anexo B, así que Feature/Contrato/autorización negativa **no aplican en esta tarea** (§9.5: «las casillas vacías significan que ese nivel no aplica»); el `audit:read` del auditor llega con las pantallas de consulta.

- Integración: la fila génesis usa `SHA256("FICHAJE-HOTEL-GENESIS")` como `prev_hash` → `->group('RL-04')`.
- Integración: el usuario de aplicación recibe error al intentar `UPDATE` y `DELETE` sobre `audit_log` → `->group('RS-07', 'RL-04')`.
- **Escenario ineludible del §9.4 «Cadena de auditoría»:** «Modificar una fila por SQL directo, verificar que `verify-audit-chain` la detecta» → `->group('RS-07')`. Debe comprobarse además que la métrica `audit_chain_verification_failures_total` se incrementa y que la alerta se dispararía.
- Integración: `canonical_json` produce el mismo hash con las claves del `payload` en distinto orden de inserción → `->group('RL-04')`.
- Unitaria del cálculo de hash con vectores fijos, sin base de datos → `->group('RL-04', 'RS-07')`.
- Integración (**ADR-027**): tras sellar el ancla y soltar la partición más antigua, `verify-audit-chain` termina **en verde** e informa de la purga con su ancla → `->group('RL-02', 'RS-07')`. **Es el par de la prueba de manipulación y las dos hacen falta**: una purga legítima que dispara la alerta y una manipulación que no la dispara son el mismo fallo visto desde los dos lados.
- Integración: el usuario de aplicación recibe error de permisos al intentar `UPDATE` o `DELETE` **sobre una partición directamente**, no solo sobre la tabla padre → `->group('RS-07')`.
- Integración: insertar con `occurred_at` de un año sin partición **falla de forma visible** y la acción auditada no se confirma → `->group('RL-04')`.

**Verificación.**

```bash
php artisan migrate:fresh
php artisan compliance:verify-audit-chain          # Cadena íntegra: salida OK, código 0
psql -U fichaje_app -d fichaje -c "UPDATE audit_log SET action='x' WHERE id=1;"   # debe fallar por permisos
php artisan test --group=RS-07                     # Detección de manipulación en verde
curl -s http://localhost/metrics | grep audit_chain_verification_failures_total    # valor 0
```

Esperado: el `UPDATE` directo con el usuario de aplicación es rechazado por PostgreSQL; forzando la manipulación con un usuario privilegiado, `verify-audit-chain` termina con código distinto de cero, señala la fila y la métrica pasa de 0 a 1.

**Terminado cuando** (§10.3): Deptrac en verde · pruebas de integración y unitarias en verde con el escenario del §9.4 cubierto · trazabilidad en verde · PHPStan 9 limpio · **instrumentación añadida** (métrica y alerta) · **eventos con relevancia legal escriben en `audit_log`** · migración reversible y verificada con volumen realista · **runbook `rotura-cadena-auditoria.md` escrito** (§10.3: «Runbook o documentación de cliente actualizada si añade un modo de fallo») · informe de `/revision-cumplimiento` sin bloqueantes.

---

### Tarea 2.3 — Correcciones trazadas: versionado, catálogo de motivos, anulación

| | |
|---|---|
| **Horas** | 10–12 |
| **Agente / Skill** | `arquitecto-dominio` → `backend-laravel` (en ese orden, literal del doc 02 §11) |
| **Requisitos** | RF-PA-04, RN-13 (doc 02 §11). Anexo A: RL-04 se completa aquí junto con 2.2. Aplica también RN-06 (el total se recalcula, nunca se incrementa) y RL-10 (rectificación mediante corrección trazada) |
| **Precondiciones** | **2.2** (§11.3: `2.2 ──► 2.3`). Y el dominio y el esquema de Fase 1: 1.1, 1.2, 1.3, 1.4 |
| **Bloquea a** | **2.5** (§11.3: `2.3 ──► 2.5`) y **2.8** (§11.3: `2.3 └─► 2.8→2.9`) |

**Objetivo.** Una persona autorizada puede crear, modificar, cerrar o anular un tramo indicando un motivo del catálogo, y el sistema conserva la versión anterior con su autor, momento, valor previo y motivo, recalcula el total del día y deja la traza en `audit_log`. El registro original permanece consultable.

**Reglas duras aplicables.**

- **5** — nada se borra ni se sobrescribe: las correcciones crean una versión nueva y conservan la anterior con autor, momento y motivo (RN-13, RL-04). Es la razón de ser de la tarea.
- **7** — `daily_totals` se **recalcula** en la misma transacción, nunca se incrementa (RN-06, ADR-007). Una corrección que incrementase el acumulado produciría horas falsas.
- **6** — cada corrección y cada anulación escriben en `audit_log`.
- **4** — corregir la hora de un turno 22:00→06:00 no puede partirlo ni cambiar su `work_date` salvo que cambie la hora de inicio (RN-05, ADR-006).
- **2** — el momento de la corrección llega por el puerto `Clock`; nada de `now()` en el dominio.
- **9** — `occurred_at` y `recorded_at` se conservan ambos y no se sobrescriben entre sí (`/revision-cumplimiento`, bloque A).
- **1** — la regla de versionado vive en `Domain/`, no en el controlador ni en el modelo Eloquent.
- **18** — `PATCH` y `void` llevan policy y prueba negativa por rol.

**Catálogo de motivos — Anexo C del doc 01, literal.**

`OLVIDO_FICHAJE_ENTRADA`, `OLVIDO_FICHAJE_SALIDA`, `FALLO_TECNICO_QUIOSCO`, `TARJETA_NO_DISPONIBLE` (olvidada, perdida o deteriorada), `CREDENCIAL_NO_ENTREGADA` (pendiente el primer día), `ERROR_DE_ESCANEO_DUPLICADO`, `AJUSTE_ACORDADO_CON_RRHH`, `ALTA_RETROACTIVA`, `OTROS` (**obliga a texto libre de al menos 20 caracteres**).

**Pasos.** El doc 02 §11 fija el relevo `arquitecto-dominio` → `backend-laravel`. El método de `arquitecto-dominio` (doc 03 §4.3) es: módulo → capa → invariantes con su `RN-*` → objetos de valor → puertos → firmas y casos de prueba → implementación.

1. `arquitecto-dominio`: situar la corrección en el módulo `Attendance` y en la capa de dominio, con `WorkDay` como frontera transaccional (doc 01 §5.2). Nadie toca `ShiftEntry` por fuera del agregado.
2. `arquitecto-dominio`: enunciar las invariantes con su regla — RN-13 (versión nueva, nunca sobrescritura), RN-03 (`clocked_out_at > clocked_in_at`), RN-02 (sin solapes tras la corrección), RN-01 (no puede quedar un segundo turno abierto), RN-06 (recálculo del total).
3. `arquitecto-dominio`: objeto de valor `CorrectionReason` (doc 01 §5.3) que hace **inconstruible** el estado inválido: `OTROS` sin texto de ≥ 20 caracteres no compila un objeto válido. Es preferible que el tipo lo impida a que una validación lo detecte después.
4. `arquitecto-dominio`: puertos necesarios (`Clock`, repositorio de `WorkDay`, publicador de eventos, escritor de auditoría) y firma del caso de uso `CorrectShiftHandler` (doc 02 §2, árbol: `Application/UseCase/`). Evento de dominio `ShiftCorrected` (doc 01 §5.4).
5. `qa-testing`/`backend-laravel`: pruebas unitarias que fallan antes de implementar, con los límites explícitos.
6. `backend-laravel`: actualizar `docs/api/openapi.yaml` con los endpoints del Anexo B: `POST /api/v1/shift-entries` (alta manual, rol manager+), **`PATCH /api/v1/shift-entries/{uuid}`** (corrección trazada, rol manager+) y **`POST /api/v1/shift-entries/{uuid}/void`** (anulación trazada, rol **rrhh+**).
7. `backend-laravel`: migración de `shift_corrections` con el esquema del doc 01 §5.5 — `id`, `shift_entry_id`, `performed_by_user_id`, `action`, `before` (JSONB), `after` (JSONB), `reason_code`, `reason_text`, `created_at` — y uso de `version` y `superseded_by_id` en `shift_entries`. Aplicar `/migracion-segura` si la columna se añade sobre datos existentes.
8. `backend-laravel`: una transacción por caso de uso con el **recálculo de `daily_totals` dentro** (doc 03 §4.3, `backend-laravel`; ADR-007). Los estados **`voided` y `superseded`** están fuera del índice parcial y de la restricción de exclusión (doc 01 §5.5: `WHERE status NOT IN ('voided','superseded')`), así que anular libera el hueco y **corregir no lo ocupa dos veces**, sin violar RN-02.

   > **Los dos estados no vigentes y por qué son dos ([ADR-026](../docs/adr/ADR-026-la-correccion-supersede.md)).** `voided` significa «este tramo no ocurrió» —lo escribe la anulación— y `superseded` significa «ocurrió, se conserva y otra versión lo sustituye» —lo escribe **esta** corrección, en la misma transacción que inserta la versión nueva y rellena `superseded_by_id`—. Reutilizar `voided` para el histórico de una corrección haría indistinguibles ante Inspección dos hechos legalmente distintos. Y sin `superseded`, la regla dura 5 y la 7 chocan de frente: la fila conservada solaparía con la nueva, `shift_entries_no_overlap` **rechazaría la corrección**, y si no la rechazara el recálculo sumaría las dos y **duplicaría los minutos del día**. El enum y los predicados ya nacen así en la tarea 1.3; aquí solo hay que escribir el estado correcto.
   >
   > El mismo predicado gobierna el recálculo de `daily_totals` y **cualquier consulta de `Reporting`** (tareas 2.8 y 2.9): se resuelve con un único *scope* o *criteria* compartido, nunca repitiendo el literal, que es la fuente de error más probable a futuro.
9. `backend-laravel`: policies por endpoint con el ámbito por departamento de 2.1, y escritura en `audit_log` de la corrección y de la anulación (bloque D de `/revision-cumplimiento`).
10. Instrumentar `manual_corrections_total{reason_code}` (counter, doc 02 §8.2). Alimenta el indicador «Correcciones manuales / total de fichajes < 2 %» del doc 01 §1.3 y el cuadro de impacto de 3.13.
11. Textos del catálogo de motivos en `i18n`, ES y EN (§10.3). Los códigos son identificadores, no textos de usuario.

**Artefactos.**

- `backend/app/Modules/Attendance/Domain/Model/` (`WorkDay`, `ShiftEntry`), `Domain/ValueObject/CorrectionReason.php`, `Domain/Event/ShiftCorrected.php`, `Domain/Exception/`.
- `backend/app/Modules/Attendance/Application/UseCase/CorrectShiftHandler.php`, `Application/Command/`.
- `backend/app/Modules/Attendance/Infrastructure/Persistence/`, `Infrastructure/Projection/` (recálculo de `daily_totals`).
- `backend/app/Modules/Attendance/Http/` — controladores, FormRequests, Resources, Policies.
- `backend/database/migrations/` — `create_shift_corrections_table`.
- `docs/api/openapi.yaml`.
- `backend/database/seeders/` — **último tramo de la semilla del §10.2**, que la tarea 0.1 dejó en esqueleto: correcciones con su motivo y tramos en estado `superseded`. Cierra la exigencia de *«olvidos y correcciones»* del §10.2, que hasta esta tarea no tenía tabla donde escribirse.

**Pruebas exigidas.** §9.5: introduce/modifica **regla de negocio** (RN-13) → **Unitaria obligatoria**; toca **esquema** → **Integración**; expone **endpoints** → **Feature + Contrato** y **autorización negativa por cada rol no autorizado**. El recorrido de usuario de la corrección se cubre en E2E dentro de la tarea 2.5, donde vive la pantalla.

- Unitaria: corregir no muta la versión anterior; `version` incrementa y `superseded_by_id` apunta correctamente → `->group('RN-13', 'RF-PA-04')`.
- Unitaria: `OTROS` con 19 caracteres es inválido, con 20 es válido (valores límite explícitos, doc 02 §3.5) → `->group('RF-PA-04')`.
- Unitaria: tras corregir la hora de salida, el total del día se **recalcula** y no se incrementa → `->group('RN-06', 'RN-13')`.
- Unitaria: corregir un turno 22:00→06:00 no genera tramo artificial ni cambia la jornada atribuida → `->group('RN-05', 'RF-AT-08')`.
- Integración: anular deja el hueco libre y una entrada nueva en el mismo intervalo no viola `shift_entries_no_overlap` → `->group('RN-02')`.
- **Integración (ADR-026), la que hoy fallaría:** corregir un tramo cerrado **no viola `shift_entries_no_overlap`** —la versión anterior queda en `superseded` y sale del predicado— y **el recálculo de `daily_totals` no duplica minutos**: corregir un tramo de 480 min a 450 deja el día en 450, no en 930 → `->group('RN-02', 'RN-06', 'RN-13')`.
- Integración: con un tramo abierto en `superseded`, `one_open_shift_per_employee` no impide abrir el turno vigente → `->group('RN-01')`.
- Integración: la fila anterior **sigue en la tabla** con su `version` y su `superseded_by_id`, y el agregado `WorkDay` reconstruido **no la incluye** entre sus tramos → `->group('RN-13', 'RL-04')`.
- Integración: la corrección deja fila en `shift_corrections` con `before`/`after` y entrada en `audit_log` → `->group('RL-04', 'RN-13')`.
- Feature + Contrato de `PATCH /shift-entries/{uuid}` y `POST /shift-entries/{uuid}/void` → `->group('RF-PA-04')`.
- Autorización negativa: `responsable_departamento` no puede anular (Anexo B: `void` es rol **rrhh+**); ningún rol puede corregir fuera de su ámbito → `->group('RF-ID-03', 'RF-PA-04')`.
- Gherkin del doc 01 §11 «Corrección manual trazada» al pie de la letra: tramo abierto de *Ana*, cierre a las 15:00 con motivo «olvido de fichaje», valor anterior conservado, original consultable y total **recalculado** → `->group('RN-13', 'RN-06', 'RF-PA-04')`.

**Verificación.**

```bash
make test-unit                       # Dominio en verde, < 2 s
make mutate                          # MSI del dominio ≥ 80 %
php artisan test --group=RN-13
php artisan test tests/Integration/Attendance tests/Feature/Attendance tests/Contract
php artisan qa:traceability --check
```

Esperado: MSI ≥ 80 % sobre las clases nuevas del dominio; ninguna prueba pasa si se elimina la creación de la versión anterior (romper la implementación a propósito para comprobar que la prueba puede fallar, doc 03 §4.3 `qa-testing`).

**Terminado cuando** (§10.3): Deptrac en verde · pruebas unitarias, de integración, feature, contrato y autorización negativa · MSI dentro de umbral · trazabilidad en verde · PHPStan 9 limpio · contrato OpenAPI actualizado y validado · autorización probada en negativo por rol · instrumentación (`manual_corrections_total`) · **`audit_log` escrito** · migración reversible · textos en ES y EN · nada específico de un cliente en el código.

---

### Tarea 2.4 — Panel: presencia en vivo con Reverb y *fallback*

| | |
|---|---|
| **Horas** | 10–12 |
| **Agente / Skill** | `frontend-panel` + `backend-laravel` |
| **Requisitos** | RF-PA-01..02 (doc 02 §11 y Anexo A). Aplica RNF-P-04 (carga con 500 empleados < 1,5 s LCP) y RNF-D-03 (*fallback* a sondeo cada 15 s) |
| **Precondiciones** | No figura en el camino crítico del §11.3. Derivado del Anexo B del doc 01: `GET /api/v1/attendance/live` es `[rol: manager+]`, luego necesita el rol y el ámbito de **2.1**; y necesita los eventos `EmployeeClockedIn`/`EmployeeClockedOut` de **1.4**. **⚠️ No cubierto por los documentos — decidir** su posición exacta respecto a 2.2 y 2.3 |
| **Bloquea a** | No figura como bloqueante de ninguna otra tarea en §11.3 |

**Objetivo.** El panel muestra en tiempo real quién está fichado ahora mismo —nombre, departamento, hora de entrada, tiempo transcurrido y quiosco de origen— con actualización push por WebSocket, filtros por centro, departamento y estado, búsqueda por nombre, y degradación anunciada a sondeo cada 15 s si el canal cae.

**Reglas duras aplicables.**

- **3** — las horas llegan en UTC y se presentan en la zona del centro; la conversión ocurre solo en presentación. `frontend-panel` (doc 03 §4.3): «las zonas horarias se muestran y no se adivinan».
- **18** — `GET /api/v1/attendance/live` lleva policy y prueba negativa. Y la autorización «se refleja en la interfaz pero no se confía en ella» (doc 03 §4.3).
- **21** — los logs de la difusión no llevan nombres; el nombre viaja en el mensaje al panel autorizado, no al log técnico.
- **13** — los umbrales y la marca son configuración; nada específico de un cliente en el componente.
- **15** — la presencia en vivo es funcionalidad accesoria; puede degradarse por licencia, pero nunca el registro (ADR-019). No introducir acoplamientos que bloqueen el fichaje.

**Pasos.** Sin skill asignada. Se derivan de los seis principios de `frontend-panel` (doc 03 §4.3) y de las reglas de `backend-laravel`.

1. Actualizar `docs/api/openapi.yaml` con `GET /api/v1/attendance/live` (Anexo B, rol manager+), incluidos los parámetros de filtro de RF-PA-02.
2. Backend: consulta de lectura en `Reporting` o `Attendance` según ámbito del rol **dentro de la consulta**, aprovechando el índice parcial de turnos abiertos (doc 02 §3.2: «Consulta de turnos abiertos en O(log n) sin escanear el histórico»).
3. Backend: difusión con **Reverb** (doc 02 §3.1, ADR-011) de los eventos de dominio `EmployeeClockedIn` y `EmployeeClockedOut` (doc 01 §5.4) hacia canales privados por centro y departamento, autorizados con el ámbito de 2.1.
4. Frontend: *store* de Pinia por dominio funcional, con la lista virtualizada (TanStack Table) para 500 empleados (doc 02 §3.3).
5. Frontend: **degradación honesta** — si el WebSocket cae, sondeo cada 15 s (ADR-011, RNF-D-03) y **aviso visible** de que el tiempo real está degradado. Principio de `frontend-panel`: «el tiempo real degrada bien y lo anuncia».
6. Frontend: presentación de la hora y del tiempo transcurrido en la zona del centro, con la zona indicada en pantalla. Sin redondeos que hagan que las partes no sumen el total.
7. Instrumentar `websocket_connections_active` (gauge) y `open_shifts_current{site,department}` (gauge) del §8.2. La segunda es además la métrica de negocio «Turnos abiertos en este momento» del doc 01 §9.2.
8. Accesibilidad WCAG 2.2 AA en el panel (doc 01 §6.5) e i18n ES/EN.

**Artefactos.**

- `frontend-admin/src/features/live/`.
- `backend/app/Modules/Attendance/Http/` o `backend/app/Modules/Reporting/Application/Query/` — consulta de presencia.
- `backend/app/Modules/Attendance/Infrastructure/Adapter/` — difusión de eventos.
- `docs/api/openapi.yaml`.

**Pruebas exigidas.** §9.5: expone **endpoint** → **Feature + Contrato** y **autorización negativa por rol**; tiene **recorrido de usuario** en el panel → **E2E**.

- Feature + Contrato de `GET /api/v1/attendance/live` con filtros → `->group('RF-PA-01', 'RF-PA-02')`.
- Autorización negativa: un responsable de un departamento no obtiene presencia de otro; el rol `empleado` y el token de quiosco reciben 403 → `->group('RF-ID-03', 'RS-04')`.
- E2E: dos pestañas, un fichaje, la lista se actualiza sin recargar → `tag: ['@RF-PA-01']`.
- E2E de degradación: con el WebSocket cortado, la vista sigue actualizándose por sondeo y lo anuncia → `tag: ['@RF-PA-01']` (RNF-D-03).
- Vitest de los componentes y del *store* (§9.2: frontend unit ≥ 70 %).
- Rendimiento: carga con 500 empleados por debajo de 1,5 s de LCP (RNF-P-04). **⚠️ No cubierto por los documentos — decidir** con qué herramienta se mide el LCP del panel en CI; el §9.2 solo fija k6 para carga de API y `@axe-core/playwright` para accesibilidad.

**Verificación.**

```bash
make up
php artisan test tests/Feature/Attendance/LiveTest.php tests/Contract
npm --prefix frontend-admin run test:unit
make e2e -- --grep @RF-PA-01
npx vue-tsc --noEmit -p frontend-admin    # 0 errores, TS estricto
```

Esperado: con Reverb detenido, el E2E sigue en verde por la vía de sondeo y la interfaz muestra el aviso de degradación.

**Terminado cuando** (§10.3): pruebas Feature, Contrato, autorización negativa y E2E en verde · trazabilidad en verde · convenciones del §3.5 verificadas por ESLint, Prettier y `vue-tsc` · contrato OpenAPI actualizado · autorización probada en negativo · instrumentación añadida · textos en ES y EN · **accesibilidad verificada en las pantallas nuevas** · nada específico de un cliente en el código.

---

### Tarea 2.5 — Panel: detalle de jornada, bandeja de incidencias, resolución

| | |
|---|---|
| **Horas** | 10–12 |
| **Agente / Skill** | `frontend-panel` |
| **Requisitos** | RF-PA-03, RF-PA-05 (doc 02 §11 y Anexo A). Consume RF-PA-04 (correcciones, tarea 2.3) |
| **Precondiciones** | **2.3** (§11.3: `2.3 ──► 2.5`). Los tipos de incidencia que llenan la bandeja los produce **2.6** (doc 01 RF-PA-05 y §5.5, tabla `incidents`); **⚠️ No cubierto por los documentos — decidir** si 2.6 precede a 2.5 o se integran en paralelo con datos de semilla |
| **Bloquea a** | No figura como bloqueante de ninguna otra tarea en §11.3 |

**Objetivo.** El responsable ve el detalle de una jornada con todos sus tramos, totales, incidencias y correcciones; corrige desde ahí con confirmación explícita del cambio; y trabaja la bandeja de incidencias pendientes asignadas a su departamento con un flujo de resolución.

**Reglas duras aplicables.**

- **5** — la interfaz muestra el historial de versiones y el original sigue visible; nunca presenta la corrección como si fuera el dato primigenio.
- **9** — se muestran **ambas** marcas: `occurred_at` y `recorded_at`, y se explica la diferencia cuando el fichaje llegó de la cola offline.
- **3** — zonas horarias mostradas, no adivinadas.
- **4** — un turno nocturno se muestra como un solo tramo, atribuido a su jornada de inicio.
- **18** — la interfaz refleja permisos, pero la autorización real está en el servidor.
- **21** — nada de nombres en los logs de cliente que se envíen a `error_events` (RF-PD-15, regla dura 21).

**Los seis principios de `frontend-panel` (doc 03 §4.3), que aquí aplican de lleno.**

1. **El dato tiene consecuencias:** nunca redondear de forma que las partes no sumen el total. Si los tramos son 2 h 7 min y 3 h 8 min, el total no puede mostrar 5 h 14 min.
2. **Las correcciones son actos serios:** mostrar **de qué valor a cuál** antes de confirmar, junto con el motivo elegido.
3. **Las zonas horarias se muestran y no se adivinan.**
4. **El tiempo real degrada bien y lo anuncia.**
5. **Volumen real con virtualización.**
6. **La autorización se refleja en la interfaz pero no se confía en ella.**

**Pasos.**

1. Confirmar en `docs/api/openapi.yaml` los endpoints del Anexo B que consume la pantalla: `GET /api/v1/employees/{uuid}/workdays` (rol manager+ | self), `GET /api/v1/incidents` (manager+), `POST /api/v1/incidents/{id}/resolve` (manager+), más `PATCH`/`void` de 2.3. Añadir lo que falte **antes** de escribir el componente (ADR-013).
2. Backend, si falta: consulta de detalle de jornada que devuelve tramos, totales, incidencias y **correcciones con autor, momento y motivo**; y la bandeja filtrada por ámbito dentro de la consulta.
3. Vista de detalle de jornada: tramos con hora de entrada y salida en la zona del centro, duración por tramo, total del día que **cuadra con la suma**, marcas de incidencia y bloque de historial de versiones.
4. Diálogo de corrección: selector del catálogo del Anexo C del doc 01, texto libre obligatorio de ≥ 20 caracteres para `OTROS`, y **resumen "de → a" antes de confirmar**.
5. Bandeja de incidencias: tipos del doc 01 §5.5 (`open_shift_expired`, `short_shift`, `long_shift`, `insufficient_rest`, `clock_skew`, `missing_clock_out`, `anomalous_pattern`), severidad, antigüedad, asignación al responsable del departamento y flujo de resolución con nota.
6. Instrumentar `incidents_open{type,severity}` (gauge) e `incident_resolution_seconds{type}` (histogram) del §8.2. La segunda alimenta el objetivo «< 24 h» del doc 01 §1.3 y el cuadro de 3.13.
7. i18n ES/EN y accesibilidad AA (doc 01 §6.5).

**Artefactos.**

- `frontend-admin/src/features/workdays/`, `frontend-admin/src/features/incidents/`.
- `backend/app/Modules/Reporting/Application/Query/` — detalle de jornada.
- `backend/app/Modules/Compliance/Http/` — bandeja y resolución de incidencias.
- `docs/api/openapi.yaml`.

**Pruebas exigidas.** §9.5: **recorrido de usuario** en el panel → **E2E**; expone/consume **endpoints** → **Feature + Contrato** y **autorización negativa por rol**.

- Feature + Contrato de `GET /incidents` y `POST /incidents/{id}/resolve` → `->group('RF-PA-05')`.
- Feature + Contrato de `GET /employees/{uuid}/workdays` → `->group('RF-PA-03')`.
- Autorización negativa: bandeja y detalle fuera del departamento → 403 y registro en auditoría; Gherkin «Aislamiento por departamento» del doc 01 §11 → `->group('RF-ID-03', 'RS-05')`.
- E2E: corregir un turno abierto desde el detalle de jornada, ver el resumen "de → a", confirmar, y comprobar que el total se recalcula y el original sigue visible → `tag: ['@RF-PA-04', '@RN-13']`.
- E2E: resolver una incidencia de la bandeja → `tag: ['@RF-PA-05']`.
- Vitest de los componentes de suma de tramos, con el caso de que las partes sumen el total → `->group('RF-PA-03')`.
- Accesibilidad con `@axe-core/playwright`: 0 violaciones críticas o graves (§9.2).

**Verificación.**

```bash
make e2e -- --grep "@RF-PA-04|@RF-PA-05"
npm --prefix frontend-admin run test:unit
npx vue-tsc --noEmit -p frontend-admin
php artisan test tests/Feature/Compliance/IncidentsTest.php
```

Esperado: 0 violaciones de accesibilidad críticas o graves; el diálogo de corrección no permite confirmar sin motivo; con `OTROS` y 19 caracteres el botón sigue deshabilitado.

**Terminado cuando** (§10.3): pruebas Feature, Contrato, autorización negativa y E2E en verde · convenciones del §3.5 verificadas · contrato OpenAPI actualizado · autorización probada en negativo · instrumentación añadida · textos en ES y EN · **accesibilidad verificada** · nada específico de un cliente en el código.

---

### Tarea 2.6 — Detección automática de incidencias (scheduler)

| | |
|---|---|
| **Horas** | 6–8 |
| **Agente / Skill** | `backend-laravel` + `/nueva-regla-de-negocio` |
| **Requisitos** | RF-PR-01 (doc 02 §11). Reglas implicadas: RN-07 (duración mínima computable), RN-08 (máxima antes de considerarse anómala, **nunca cierre automático**), RN-10 (descanso entre jornadas), **RN-11** (jornada diaria ordinaria) y **RN-12** (descanso en jornada continuada), más RN-15 (retraso de sincronización). El Anexo A asigna RN-10..15 a esta fase |
| **Precondiciones** | No figura en el §11.3. Derivado: necesita el dominio de 1.1–1.2, el esquema de 1.3 y el `audit_log` de 2.2 para dejar traza. **⚠️ No cubierto por los documentos — decidir** su posición respecto a 2.5 |
| **Bloquea a** | No figura en el §11.3. De hecho **alimenta** la bandeja de 2.5 (RF-PA-05) |

**Objetivo.** Una tarea programada detecta a diario turnos abiertos anómalos y el resto de situaciones que requieren intervención humana, crea la incidencia del tipo correspondiente, la asigna al responsable del departamento y **no cierra nada silenciosamente**.

**Reglas duras aplicables.**

- **19** — el sistema no penaliza al empleado por un problema técnico: genera incidencia para revisión humana.
- **14** — los umbrales legales se leen del perfil de cumplimiento, no son constantes; el dominio los recibe resueltos por el puerto `CompliancePolicyProvider`, en `Shared/Application/Port/` ([ADR-025](../docs/adr/ADR-025-frontera-de-dependencias-del-nucleo.md)). RN-10 (12 h de descanso) es uno de ellos.
- **1** y **2** — la regla vive en `Domain/` con el reloj inyectado; sin ello no se puede probar el caso de medianoche ni el de DST.
- **6** — la creación y la resolución de una incidencia con relevancia legal dejan traza.
- **13** — nada específico de un cliente: los umbrales son configuración.

> **Tensión de orden, resuelta adelantando la tabla y no la funcionalidad.** El paso 4 de `/nueva-regla-de-negocio` exige que los umbrales legales vivan en `compliance_profiles` (RF-PD-07) y los operativos en `installation_settings` (RF-PD-01), pero la gestión de ambas es de las tareas **5.1 y 5.2**, que en el orden real (0 → 1 → 2 → 5 → 3 → 4) van **después** de esta fase.
>
> **No hay adaptador provisional.** La tabla `compliance_profiles` y su fila semilla `ES-hosteleria` se crean en la **tarea 1.3**, y el puerto `CompliancePolicyProvider` en la **1.1** (ver la nota de esa tarea). Cuando esta fase llega, el puerto ya existe y lee de la base de datos: los umbrales de RN-10, RN-11 y RN-12 son los que RN-* ya fija —12 h, 9 h, 6 h—, así que la semilla no inventa nada. Las tareas 5.1 y 5.2 añaden después lo caro: edición desde el panel, resolución en cascada por ámbito y auditoría del cambio.
>
> Lo que se evita con esto es un adaptador desechable con los números dentro, que es el sitio donde un literal se queda para siempre. `ATTENDANCE_MAX_SHIFT_HOURS=12` del Anexo B sigue siendo **solo el valor por defecto de la instalación**, no la fuente de verdad en ejecución (regla dura 14).
>
> **RN-11 y RN-12 se enuncian aquí, aunque su explotación completa llegue después.** El Anexo A del doc 01 las asigna a la Fase 2 junto con RN-10, pero la vista de cumplimiento es de la tarea 3.4 y RN-12 habla de *«pausa registrada»*, que no existe hasta RF-AT-12 en la 3.5. Dejarlas para entonces cerraría la Fase 2 con **dos reglas legales del Anexo A sin ninguna prueba**, y `qa:traceability --check` lo bloquearía con razón al cerrar la fase.
>
> Lo que se adelanta es **el enunciado de dominio y su prueba unitaria**, que es lo barato y lo que fija la semántica:
>
> - **RN-11** — *«se alerta si un empleado supera 9 h efectivas en una jornada»*. Se evalúa sobre la **suma de los tramos vigentes de la jornada** (no sobre un tramo), con el umbral `max_daily_hours` recibido por `CompliancePolicyProvider`. Incidencia `long_shift`. Es completamente implementable hoy.
> - **RN-12** — *«se alerta si un tramo continuo supera 6 h sin pausa registrada»*. Con [ADR-024](../docs/adr/ADR-024-la-pausa-son-dos-tramos.md) la pausa **son dos tramos**, así que «sin pausa registrada» significa exactamente **un tramo continuo de más de `break_required_after_hours`**, y eso también es evaluable hoy sobre los tramos existentes. Lo que la tarea 3.5 añade no es la regla: es la **intención declarada** por el quiosco, que permite distinguir en la interfaz una pausa de un fin de jornada.
>
> Lo que **no** se adelanta es la pantalla de cumplimiento (3.4) ni el fichaje de pausa (3.5). La regla se enuncia, se prueba en unitaria y genera incidencia; su presentación llega con la Fase 3.

**Pasos.** Según `/nueva-regla-de-negocio`, **6 pasos**, en orden documentar → probar → implementar:

1. **Documentar la regla** en `docs/01-especificaciones-proyecto.md` §4, si la detección introduce alguna regla que no esté ya en RN-07/08/10/15. Enunciado comprensible para alguien de RRHH, con origen normativo citado y marcado para validación por la asesoría laboral si procede.
2. **Escenario Gherkin** en el doc 01 §11 con números concretos. Ya existe el escenario «Turno olvidado» (tramo abierto desde hace 13 horas → **NO se cierra automáticamente**, incidencia `open_shift_expired`, notificación al responsable): reutilizarlo como criterio de aceptación y añadir los que falten para descanso insuficiente y tramo corto.
3. **Escribir la prueba unitaria que falla** en `backend/tests/Unit/Attendance/` (o `Compliance/`), pura, con reloj inyectado y fijo. Cubrir el caso nominal, **el límite exacto** (para «más de 12 h»: 11:59, 12:00 y 12:01), turno que cruza medianoche, los dos cambios de hora de `Europe/Madrid` y duración cero o negativa como inconstruible. Ejecutarla y comprobar que falla.
4. **Implementar en el dominio**: `Policy` para las decisiones parametrizables (umbral recibido por puerto), agregado para las invariantes. Nombres en inglés (`WorkDay`, `Incident`), enunciado en español en el documento (doc 02 §3.5 y glosario del doc 01 §13).
5. **Mutación**: `make mutate`, MSI del dominio ≥ 80 %. Si un mutante sobrevive dentro de la regla nueva, la prueba no la cubre de verdad.
6. **Propagar**: tipo nuevo en `incidents.type` y en la bandeja del panel (2.5); métrica y regla de alerta en `infra/observability/`; textos ES y EN; y **decisión documentada sobre retroactividad** — por defecto **no** se aplica a datos históricos, porque recalcular el pasado puede alterar registros ya entregados a la plantilla o a la Inspección.

Además, en la parte de `backend-laravel`:

7. Comando `php artisan attendance:detect-incidents` (doc 02 Anexo C: «Turnos abiertos, duraciones anómalas, descansos») registrado en el Scheduler.
8. Asignación de la incidencia al responsable del departamento (`departments.manager_user_id`, doc 01 §5.5) y notificación (RF-PR-01).
9. Instrumentar `incidents_open{type,severity}` (gauge, §8.2) y la alerta del doc 01 §9.3: **«Turnos abiertos > 12 h | cualquiera | Media | RRHH | `turno-abierto-prolongado.md`»**.

   **El runbook se escribe en esta tarea**, que es la que crea la alerta. No existía en el §12 del doc 02 y **ya está añadido allí**: el §8.4 no admite alerta sin procedimiento («una alerta sin procedimiento asociado es ruido y se elimina»), y la alternativa era eliminar del catálogo mínimo una alerta que detecta precisamente el caso que RN-08 prohíbe resolver automáticamente. El destinatario es **RRHH** y no IT, porque no es una avería: es trabajo de gestión sobre el registro, y avisar a IT de un turno sin cerrar sería ruido para quien no puede resolverlo.

   El runbook tiene que dejar claro lo que RN-08 impone: **el sistema nunca cierra el turno por su cuenta**. El procedimiento es contactar con la persona o su responsable, y corregir con el mecanismo trazado de la tarea 2.3 indicando motivo del catálogo (Anexo C del doc 01: `OLVIDO_FICHAJE_SALIDA`).

**Artefactos.**

- `backend/app/Modules/Attendance/Domain/Policy/` — políticas de detección con umbral recibido.
- `backend/app/Modules/Compliance/Domain/` — modelo de `Incident` (doc 01 §5.1 sitúa `Incident` en `Compliance`).
- `backend/app/Modules/Shared/Application/Port/CompliancePolicyProvider` — **ya declarado en la tarea 1.1**, que fija esta nomenclatura como «válida para todo el proyecto»; aquí solo se consume. Su adaptador es `Product/Infrastructure/Adapter/DbCompliancePolicyProvider`, porque `compliance_profiles` es tabla de `Product` ([ADR-025](../docs/adr/ADR-025-frontera-de-dependencias-del-nucleo.md)). **Ni el puerto está en `Attendance` ni el adaptador en su `Infrastructure/`**, y `CompliancePolicy` es el objeto de valor que el puerto **devuelve**, no el puerto: confundirlos rompe el lenguaje ubicuo y duplica una declaración que debe ser única (prueba de arquitectura *d* de la tarea 0.3).
- Comando de consola `attendance:detect-incidents` y su registro en el Scheduler.
- `infra/observability/` — regla de alerta.
- `docs/01-especificaciones-proyecto.md` §4 y §11 (documentación de la regla y su Gherkin).

**Pruebas exigidas.** §9.5: introduce/modifica **regla de negocio** → **Unitaria obligatoria**. Al crear filas de `incidents` y consultarlas con volumen, **Integración**. No expone endpoint propio (la bandeja es de 2.5), así que Feature/Contrato/autorización negativa se cubren allí.

- Unitaria de los límites exactos 11:59 / 12:00 / 12:01 → `->group('RN-08', 'RF-PR-01')`.
- Unitaria de descanso insuficiente con el umbral recibido por puerto, incluido el caso del Gherkin del doc 01 §11 «Perfil de cumplimiento distinto» (perfil de 10 h: dos turnos separados por 11 h no alertan; con el perfil español de 12 h, sí) → `->group('RN-10', 'RF-PD-07')`.
- Unitaria de tramo corto (RN-07: por debajo de 1 minuto se registra el evento pero se marca como incidencia) → `->group('RN-07')`.
- **Unitaria de RN-11**: la suma de los tramos vigentes de la jornada supera `max_daily_hours` recibido por puerto. Límites exactos con el perfil `ES-hosteleria`: **8:59, 9:00 y 9:01** → `->group('RN-11', 'RF-PD-07')`.
- **Unitaria de RN-12**: un tramo continuo supera `break_required_after_hours` sin pausa registrada, que con [ADR-024](../docs/adr/ADR-024-la-pausa-son-dos-tramos.md) es «un solo tramo de más de N horas». Límites **5:59, 6:00 y 6:01**, y el caso negativo: 4 h + pausa + 4 h **no** alerta, porque son dos tramos → `->group('RN-12', 'RF-PD-07')`.
- Unitaria de retraso de sincronización por encima del umbral → validación del responsable (RN-15) → `->group('RN-15')`.
- Unitaria de medianoche y de los dos cambios de hora (§9.4) → `->group('RN-05', 'RN-09')`.
- Integración: el comando crea la incidencia, la asigna y **no cierra el tramo** → `->group('RF-PR-01')`.
- Escenario del doc 01 §11 «Turno olvidado», con la aserción explícita de que el tramo **sigue abierto** → `->group('RF-PR-01', 'RN-08')`.

**Verificación.**

```bash
make test-unit
make mutate                                  # MSI ≥ 80 %
php artisan attendance:detect-incidents      # sobre la semilla con casos límite (doc 02 §10.2)
php artisan test --group=RN-08 --group=RN-10
php artisan qa:traceability --check
```

Esperado: sobre la semilla de desarrollo —que incluye turnos nocturnos, DST, olvidos y correcciones (doc 02 §10.2)— el comando produce incidencias de los tipos esperados, ningún tramo cambia de estado a `closed`, y la ejecución es idempotente: repetirla no duplica incidencias.

**Terminado cuando** (§10.3): Deptrac en verde · unitarias e integración en verde con límites explícitos · MSI ≥ 80 % · trazabilidad en verde · PHPStan 9 limpio · instrumentación y alerta con runbook · `audit_log` escrito donde corresponda · textos en ES y EN · **decisión de retroactividad documentada** · umbrales fuera del código (regla dura 14) · nada específico de un cliente.

---

### Tarea 2.7 — Reconciliación nocturna con alerta de divergencia

| | |
|---|---|
| **Horas** | 4–6 |
| **Agente / Skill** | `backend-laravel` |
| **Requisitos** | RF-PR-02 (doc 02 §11). ADR-007. Regla RN-06 |
| **Precondiciones** | No figura en el §11.3. Derivado: necesita la proyección `daily_totals` y su recálculo transaccional de **1.4**, y el `audit_log` de **2.2** para dejar traza de la corrección de proyección. **⚠️ No cubierto por los documentos — decidir** su posición respecto a 2.3, aunque las correcciones de 2.3 son precisamente la fuente típica de divergencia |
| **Bloquea a** | No figura en el §11.3 |

**Objetivo.** Un proceso nocturno recalcula los agregados diarios, los contrasta con los eventos origen y **alerta** si hay divergencia. Existe el comando para ejecutarlo a mano sobre un rango, la métrica que cuenta divergencias y el runbook de qué hacer cuando salta.

**Reglas duras aplicables.**

- **7** — `daily_totals` es una proyección reconstruible: se **recalcula** en la misma transacción, nunca se incrementa acumulativamente (RN-06, ADR-007). La reconciliación es la red de seguridad de esta regla, no su sustituto.
- **9** — el recálculo usa `occurred_at`, que es el que tiene valor legal.
- **4** — la atribución a `work_date` sigue RN-05 al reconstruir: un turno nocturno cuenta entero en su jornada de inicio.
- **6** — si la reconciliación corrige un agregado, la corrección queda trazada.
- **1** — la aritmética del total vive en el dominio, no en SQL disperso por la aplicación.

**Pasos.**

1. Comando `php artisan attendance:reconcile --from= --to=` (doc 02 Anexo C: «Recalcula proyecciones y alerta si divergen»; ADR-007 lo nombra explícitamente).
2. Recálculo por lotes: para cada `(employee_id, work_date)` del rango, recomponer el total desde `shift_entries` —**excluyendo `voided` y `superseded`**, el conjunto vigente de ADR-026, con el mismo *scope* compartido que usa la proyección— **en una transacción**, comparar con `daily_totals` y registrar la diferencia antes de escribir.
3. Escritura de `daily_totals` con sus campos del doc 01 §5.5: `total_minutes`, `shift_count`, `first_in_at`, `last_out_at`, `has_open_shift`, `has_incident`, `recalculated_at`, con `UNIQUE (employee_id, work_date)`.
4. Registro en el Scheduler de la ejecución nocturna (doc 02 §1.4: el Scheduler se ocupa de «Reconciliación, incidencias, retención, copias»).
5. Instrumentar **`projection_divergence_total`** (counter, §8.2). Doc 02 §8.2: debe permanecer **siempre en cero**; cualquier incremento es un incidente de integridad, no una tendencia.
6. Alerta del doc 01 §9.3: **«Divergencia en reconciliación nocturna | cualquiera | Crítica»**, con enlace al runbook `docs/runbooks/divergencia-proyeccion.md` (doc 02 §12: «La reconciliación detecta discrepancia»). Destinatario: **⚠️ No cubierto por los documentos — decidir**; el §9.3 marca la severidad como «Crítica» sin nombrar destinatario, a diferencia de otras filas de la misma tabla.
7. Escribir el runbook `divergencia-proyeccion.md`: cómo identificar el rango afectado, cómo relanzar `attendance:reconcile`, qué evidencia conservar y cuándo escalar. El §8.4 lo exige antes de que la alerta exista.
8. Publicar el evento de dominio `DailyTotalsRecalculated` (doc 01 §5.4) para que las proyecciones dependientes se enteren.

**Artefactos.**

- `backend/app/Modules/Attendance/Application/UseCase/` — caso de uso de reconciliación.
- `backend/app/Modules/Attendance/Infrastructure/Projection/` — recálculo de `daily_totals`.
- Comando de consola `attendance:reconcile` y su entrada en el Scheduler.
- `infra/observability/` — regla de alerta de divergencia.
- `docs/runbooks/divergencia-proyeccion.md`.

**Pruebas exigidas.** §9.5: toca la **proyección y el esquema** → **Integración**. La unitaria de RN-06 ya existe desde 1.2; aquí se refuerza con el caso del recálculo completo. No expone endpoint.

- **Escenario ineludible del §9.4 «Reconciliación»:** «Corromper deliberadamente `daily_totals`, ejecutar `attendance:reconcile`, verificar corrección y alerta» → `->group('RF-PR-02', 'RN-06')`.
- Integración: reconciliar un rango sin divergencias **no incrementa** `projection_divergence_total` y no reescribe filas innecesariamente → `->group('RF-PR-02')`.
- Integración: tras una corrección de 2.3 y una anulación, la reconciliación cuadra con los eventos origen → `->group('RN-06', 'RN-13')`.
- Integración: turno nocturno atribuido a la jornada correcta al reconstruir → `->group('RN-05')`.
- Integración: día con cambio de hora, cuyo total refleja las horas reales (RN-09) → `->group('RN-09')`.

**Verificación.**

```bash
php artisan attendance:reconcile --from=2026-01-01 --to=2026-01-31
php artisan test --group=RF-PR-02
curl -s http://localhost/metrics | grep projection_divergence_total     # 0 en condiciones normales
```

Esperado: sin divergencias, código de salida 0, métrica en 0 y ninguna alerta. Con una fila corrompida a mano, el comando la corrige, la métrica pasa a 1, la alerta se dispara y el runbook explica el siguiente paso.

**Terminado cuando** (§10.3): Deptrac en verde · integración en verde con el escenario del §9.4 · trazabilidad en verde · PHPStan 9 limpio · **instrumentación añadida** con la métrica en cero · `audit_log` escrito si se corrige un agregado · migración reversible si toca esquema · **runbook `divergencia-proyeccion.md` escrito** · nada específico de un cliente.

---

### Tarea 2.8 — Informes por periodo, contratos, trabajadas frente a contratadas

| | |
|---|---|
| **Horas** | 10–12 |
| **Agente / Skill** | `backend-laravel` + `/informe-nuevo` |
| **Requisitos** | RF-IN-01..03, RF-GP-02 (doc 02 §11 y Anexo A). Presupuesto RNF-P-05 (informe mensual de 500 empleados < 5 s síncrono; asíncrono si supera 10 s) |
| **Precondiciones** | **2.3** (§11.3: `2.3 └─► 2.8→2.9`) |
| **Bloquea a** | **2.9** (§11.3: `2.8→2.9`) y, tras ella, **5.1→5.2** (§11.3 encadena la rama de productización bajo `2.8→2.9`) |

**Objetivo.** RRHH obtiene el informe de horas por empleado con granularidad diaria, semanal, mensual y de rango libre; los agregados por departamento y centro; y la comparativa de horas trabajadas frente a contratadas con su desviación y su exceso de jornada. Los criterios de inclusión están visibles en el propio informe.

**Reglas duras aplicables.**

- **4** — los turnos no se parten a medianoche: la agrupación es por `work_date` (RN-05). El **prorrateo por día natural** que exige ADR-006 vive en `Reporting`, y solo donde el informe lo requiera explícitamente: «El informe de horas por día natural requiere prorrateo explícito, implementado en `Reporting`» (doc 02 §4, ADR-006).
- **7** — el informe **lee** la proyección; no recalcula en paralelo. Si los números no cuadran, el problema es la proyección: se ejecuta `attendance:reconcile` (`/informe-nuevo`, paso 2).
- **3** — datos en UTC, presentación en la zona del centro.
- **18** — el ámbito se aplica **en la consulta**, no después en PHP, y cada endpoint lleva su prueba negativa.
- **6** — «toda generación de informe con datos de terceros se registra en `audit_log`: quién, qué periodo, qué empleados» (`/informe-nuevo`, paso 7).
- **21** — el log técnico de la generación no lleva nombres.

**Advertencias de la skill que hay que recoger (doc 03 §5.1 y `/informe-nuevo`).**

- **No agrupar por `date_trunc('day', clocked_in_at)` en UTC**: rompe los turnos nocturnos. Se usa `work_date`, que ya está calculado según RN-05.
- **No exportar horas como decimal**: «nadie interpreta bien 7,75». Horas como texto `HH:MM`.
- **Los criterios de inclusión van visibles en el propio informe**: ¿se incluyen los turnos abiertos? ¿los tramos anulados? ¿los que tienen incidencia sin resolver? ¿los días sin actividad aparecen con cero o se omiten?
- Para un informe de absentismo, **omitir los días sin actividad es un error**: se usa `generate_series`.

**Pasos.** Según `/informe-nuevo`, **8 pasos**:

1. **Definir la pregunta exacta** de cada uno de los tres informes, con granularidad, zona horaria, qué cuenta y qué no, y tratamiento de días sin actividad. Documentarlo y dejarlo **visible en la salida**.
2. **Elegir la fuente** con la tabla de la skill: agregados por empleado y día → `daily_totals`; detalle de tramos → `shift_entries`; trazabilidad de correcciones → `shift_corrections` + `audit_log`. Nunca recalcular desde `scan_events` lo que ya está en `daily_totals`.
3. **Consulta** en `Modules/Reporting/Application/Query/`, aprovechando PostgreSQL: `generate_series` para días sin actividad, funciones de ventana para acumulados y comparativas con el periodo anterior, `AT TIME ZONE` para agrupar en la zona del centro, y filtro por ámbito del rol **dentro** de la consulta.
4. **Índices**: `EXPLAIN ANALYZE` con volumen realista (500 empleados × 2 años ≈ 400.000 filas en `daily_totals`). Si aparece un *sequential scan* sobre tabla grande, falta índice: se añade con `CREATE INDEX CONCURRENTLY` mediante `/migracion-segura`.
5. **Síncrono o asíncrono**: < 5 s medidos con volumen real → respuesta directa; > 10 s o más de 3 meses de datos → cola con enlace caducable (que es RF-IN-06, **tarea 3.9**); entre 5 y 10 s → optimizar antes de aceptarlo como síncrono.
6. **Formatos**: en esta tarea la salida del endpoint; los ficheros son la tarea 2.9.
7. **Autorización**: ámbito en la consulta —un responsable de Cocina no obtiene datos de Recepción ni agregados— y generación **auditada**.
8. **Pruebas**: las ocho de la lista de la skill (ver más abajo).

Además, en la parte de `backend-laravel`:

9. Actualizar `docs/api/openapi.yaml` con `GET /api/v1/reports/period` (Anexo B, rol manager+) y sus parámetros de granularidad y rango.
10. Completar **RF-GP-02** — `employment_contracts` historizado con `weekly_hours`, `annual_hours`, `schedule_type` (`continua`|`partida`|`turnos`), `valid_from`, `valid_to` (doc 01 §5.5) — porque sin contrato vigente no hay comparativa de trabajadas frente a contratadas (RF-IN-03).
11. Instrumentar `worked_minutes_total{site,department}` (counter, §8.2), que alimenta el cuadro de Negocio del §8.3 y el indicador «Horas trabajadas frente a contratadas por departamento» del doc 01 §9.2.

**Artefactos.**

- `backend/app/Modules/Reporting/Application/Query/`.
- `backend/app/Modules/Reporting/Http/` — controlador, FormRequest, Resource, Policy.
- `backend/app/Modules/Workforce/…` — contratos (RF-GP-02) y su migración historizada.
- `backend/database/migrations/` — índices con `CREATE INDEX CONCURRENTLY`.
- `docs/api/openapi.yaml`.
- `frontend-admin/src/features/reports/` — pantalla de consulta (si se cubre aquí; el doc 02 §11 asigna la tarea a `backend-laravel`, así que **⚠️ No cubierto por los documentos — decidir** cuánto de la pantalla de informes entra en 2.8 y cuánto en 2.5/3.13).

**Pruebas exigidas.** §9.5, fila «Genera un **informe o exportación**»: **Unitaria del cálculo** + **Integración con volumen** + **Feature + Contrato** + **Autorización negativa**.

Las ocho pruebas del paso 8 de `/informe-nuevo`, etiquetadas:

- Corrección del cálculo con un conjunto de datos conocido y resultado verificado a mano → `->group('RF-IN-01')`.
- Turnos nocturnos agrupados en la jornada correcta → `->group('RN-05', 'RF-AT-08')`.
- Semana con cambio de hora: el total refleja las 23 o 25 horas reales → `->group('RN-09')`.
- Empleado dado de baja a mitad de periodo (RN-14: conserva historial) → `->group('RN-14', 'RF-GP-03')`.
- Días sin actividad tratados según lo decidido → `->group('RF-IN-01')`.
- Autorización: un rol sin ámbito no obtiene datos ajenos → `->group('RF-ID-03')`.
- Rendimiento: `EXPLAIN ANALYZE` con volumen real dentro del presupuesto de RNF-P-05 → `->group('RNF-P-05')`.
- Exportación: se verifica en 2.9.

Más: Feature + Contrato de `GET /api/v1/reports/period` → `->group('RF-IN-01', 'RF-IN-02')`; comparativa trabajadas/contratadas con contrato historizado y cambio de contrato a mitad de periodo → `->group('RF-IN-03', 'RF-GP-02')`.

**Verificación.**

```bash
php artisan test tests/Feature/Reporting tests/Contract tests/Integration/Reporting
php artisan test --group=RF-IN-03
psql -d fichaje -c "EXPLAIN ANALYZE <consulta del informe mensual>"   # sin sequential scan sobre daily_totals
php artisan qa:traceability --check
```

Esperado: informe mensual de 500 empleados por debajo de 5 s con volumen realista (RNF-P-05); el resultado de un mes con turno nocturno el día 1 atribuye esas horas al día de inicio; los criterios de inclusión aparecen en la respuesta.

**Terminado cuando** (§10.3): Deptrac en verde · unitarias, integración con volumen, feature, contrato y autorización negativa · trazabilidad en verde · PHPStan 9 limpio · contrato OpenAPI actualizado · autorización probada en negativo · instrumentación añadida · **generación de informe auditada** · migración de índices reversible y verificada con volumen realista · textos en ES y EN · nada específico de un cliente.

---

### Tarea 2.9 — Exportaciones CSV/XLSX/PDF y **exportación legal para Inspección**

| | |
|---|---|
| **Horas** | 8–10 |
| **Agente / Skill** | `backend-laravel` + `/informe-nuevo` |
| **Requisitos** | RF-IN-04..05, RL-06 (doc 02 §11 y Anexo A). Sostiene RL-03 (entrega inmediata) y RL-04 (las correcciones y sus motivos, visibles) |
| **Precondiciones** | **2.8** (§11.3: `2.8→2.9`). Y **2.3**, porque la exportación legal debe incluir las correcciones con su autor y motivo |
| **Bloquea a** | **5.1→5.2** (§11.3 encadena la rama de productización bajo `2.8→2.9`) |

**Objetivo.** El sistema exporta a CSV, XLSX y PDF, con los PDF sellados (fecha, emisor y hash del contenido), y genera la **exportación normalizada para Inspección de Trabajo**: registro diario por trabajador y periodo, en formato tabular legible y tratable, **con las correcciones y sus motivos**. Y existe el runbook para hacerlo en menos de una hora.

**Reglas duras aplicables.**

- **5** y **6** — la exportación legal muestra las correcciones con autor, momento y motivo. «Un informe que oculte las correcciones no cumple» (`/informe-nuevo`, paso 6).
- **9** — se exportan ambas marcas donde corresponda; el registro legal usa `occurred_at`.
- **4** — un turno nocturno aparece como un tramo, en su jornada de inicio; si el cliente pide horas por día natural, el prorrateo es explícito y va etiquetado como tal (ADR-006).
- **18** — `GET /api/v1/reports/legal-export` es de rol `auditor|rrhh` (Anexo B) y lleva prueba negativa por cada otro rol.
- **6** — «Genera una exportación legal» está en la lista del bloque D de `/revision-cumplimiento`: se audita siempre.
- **15** — la exportación legal **nunca** se degrada por licencia caducada (ADR-019, RF-PD-05). El Gherkin del doc 01 §11 «Licencia caducada» lo exige: «los informes y la exportación legal siguen siendo accesibles».
- **21** — el fichero exportado contiene datos personales por su finalidad legal; el **log** de la generación no.

**Pasos.** Segunda pasada de `/informe-nuevo` (8 pasos), centrada en los pasos 6, 7 y 8:

1. **Pregunta exacta** de la exportación legal: registro diario por trabajador y periodo para un requerimiento de Inspección (RF-IN-05, RL-06). Criterios de inclusión visibles en el propio fichero.
2. **Fuente**: `shift_entries` para el detalle de tramos y `shift_corrections` + `audit_log` para la trazabilidad de correcciones (tabla del paso 2 de la skill).
3. **Consulta** con agrupación por `work_date` y `AT TIME ZONE` de la zona del centro; ámbito del rol en la consulta.
4. **Índices**: `EXPLAIN ANALYZE` sobre el periodo máximo previsible (retención de 4 años, RL-02).
5. **Síncrono o asíncrono**: por encima de 3 meses de datos, cola con enlace caducable — que es RF-IN-06 y llega en **3.9**. Hasta entonces, respetar el presupuesto de RNF-P-05 y documentar el límite.
6. **Formatos** con `spatie/simple-excel` en **streaming** (doc 02 §3.1: «no carga en memoria un mes de 500 empleados»):
   - **CSV** — UTF-8 **con BOM** para que Excel no rompa los acentos; separador según *locale*.
   - **XLSX** — streaming, cabeceras congeladas, columnas con ancho, **horas como texto `HH:MM`, nunca decimal**.
   - **PDF** — `spatie/laravel-pdf`, con pie de página que incluya fecha de generación, usuario emisor, periodo y **hash del contenido** (RF-IN-04).
7. **Autorización y auditoría**: ámbito en la consulta y registro en `audit_log` de quién exportó, qué periodo y qué empleados.
8. **Pruebas**: las ocho de la skill, más las de contrato y autorización.

Además:

9. Actualizar `docs/api/openapi.yaml` con `GET /api/v1/reports/legal-export` (Anexo B, rol `auditor|rrhh`).
10. Comando `php artisan compliance:legal-export --from= --to= --employee=` (doc 02 Anexo C), para poder atender un requerimiento desde la consola sin depender del panel.
11. Escribir el runbook `docs/runbooks/requerimiento-inspeccion.md` — doc 02 §12: «**Cómo generar la exportación legal en menos de 1 hora.** El más importante y el que nadie escribe hasta que hace falta».
12. Verificar con `/revision-cumplimiento` bloque A: «La exportación para Inspección sigue siendo completa y coherente tras el cambio, incluidas las correcciones y sus motivos».

**Artefactos.**

- `backend/app/Modules/Reporting/Application/Query/`, `.../Infrastructure/` — escritores de CSV/XLSX/PDF en streaming.
- `backend/app/Modules/Compliance/…` — exportación legal (doc 01 §5.1 sitúa `LegalExport` en `Compliance`).
- Comando de consola `compliance:legal-export`.
- `backend/app/Modules/Reporting/Http/`.
- `docs/api/openapi.yaml`.
- `docs/runbooks/requerimiento-inspeccion.md`.
- `frontend-admin/src/features/reports/` — descarga desde el panel.

**Pruebas exigidas.** §9.5, fila «Genera un **informe o exportación**»: **Unitaria del cálculo** + **Integración con volumen** + **Feature + Contrato** + **Autorización negativa**.

- Integración con volumen: exportación de un mes de 500 empleados en streaming sin agotar memoria → `->group('RF-IN-04')`.
- Integración: la exportación legal incluye, por trabajador y día, hora de inicio y fin de cada tramo, total y **las correcciones con su autor, fecha y motivo** → `->group('RF-IN-05', 'RL-06', 'RL-04')`.
- Unitaria: formato de horas `HH:MM`, nunca decimal → `->group('RF-IN-04')`.
- Integración: el PDF lleva sello temporal, emisor y hash del contenido, y el hash cambia si cambia una hora → `->group('RF-IN-04')`.
- Feature + Contrato de `GET /api/v1/reports/legal-export` → `->group('RF-IN-05')`.
- Autorización negativa: `responsable_departamento` y `empleado` no acceden a la exportación legal completa (Anexo B: `auditor|rrhh`) → `->group('RF-ID-03', 'RL-06')`.
- Integración: la generación queda en `audit_log` con periodo y empleados → `->group('RS-05', 'RL-04')`.
- Prueba del Gherkin «Licencia caducada»: la exportación legal sigue accesible → `->group('RF-PD-05')`. *(La licencia se implementa en 5.3; anotar la prueba como pendiente de habilitar entonces si aún no existe el módulo.)*
- Apertura correcta en Excel y LibreOffice, con acentos (paso 8 de la skill) → verificación manual documentada.

**Verificación.**

```bash
php artisan compliance:legal-export --from=2026-01-01 --to=2026-01-31 --employee=<uuid>
php artisan test --group=RF-IN-05
php artisan test tests/Feature/Reporting tests/Contract
php artisan qa:traceability --check
```

Esperado: el fichero abre en Excel y LibreOffice con acentos correctos, las horas se leen `HH:MM`, las correcciones aparecen con autor y motivo, y el runbook `requerimiento-inspeccion.md` permite reproducir el proceso completo en menos de una hora sin conocimiento previo.

**Terminado cuando** (§10.3): Deptrac en verde · unitarias, integración con volumen, feature, contrato y autorización negativa · trazabilidad en verde · PHPStan 9 limpio · contrato OpenAPI actualizado · autorización probada en negativo · instrumentación añadida · **generación de exportación legal auditada** · textos en ES y EN · **runbook `requerimiento-inspeccion.md` escrito** · nada específico de un cliente.

---

### Tarea 2.10 — Retención con confirmación y purga documentada

| | |
|---|---|
| **Horas** | 4–6 |
| **Agente / Skill** | `backend-laravel` + `/revision-cumplimiento` |
| **Requisitos** | RL-02, RL-11, RF-PR-03 (doc 02 §11 y Anexo A). Relacionado: RL-10 (la supresión queda condicionada al deber legal de conservación) |
| **Precondiciones** | No figura en el §11.3. Derivado: necesita `audit_log` (**2.2**) para registrar la purga —bloque D de `/revision-cumplimiento`: «Ejecuta una purga por retención»— y el esquema completo del registro. **⚠️ No cubierto por los documentos — decidir** su posición en la fase |
| **Bloquea a** | No figura en el §11.3 |

**Objetivo.** Al vencer el periodo de retención, el sistema **propone** la eliminación, exige confirmación del responsable y emite informe de lo purgado. Existe el modo simulación (`--dry-run`) y la política de retención está diferenciada por tipo de dato.

**Reglas duras aplicables.**

- **14** — **el umbral de retención se lee del perfil de cumplimiento, no es constante** (`compliance_profiles.retention_years`, doc 01 §5.5; RF-PD-07). El dominio lo recibe resuelto por un puerto.
- **5** — la purga por retención es la **única** eliminación legítima de datos del sistema, y va precedida de confirmación humana e informe. No es una excepción a «nada se borra»: es el vencimiento del deber de conservación.
- **6** — la purga escribe en `audit_log` (bloque D de la skill).
- **16** — el informe de purga se queda en la instalación del cliente; el fabricante no accede a los datos.
- **13** — los años de retención son configuración por cliente y jurisdicción, nunca código.

> **Tensión de orden, resuelta.** Es la misma que en la tarea 2.6 y se resuelve igual: la tabla `compliance_profiles` y su fila semilla `ES-hosteleria` se crean en la **tarea 1.3**, y el puerto `CompliancePolicyProvider` en la **1.1**. Cuando esta tarea llega, el umbral de retención se lee del perfil, con los **4 años de RL-02** en la semilla, y no hay adaptador provisional que sustituir en 5.2 — lo que la 5.2 añade es la edición, la cascada y la auditoría del cambio, no la fuente de verdad.
>
> `COMPLIANCE_PROFILE=ES-hosteleria` del Anexo B del doc 02 sigue siendo solo el valor por defecto de la instalación. Lo que no admite discusión: los 4 años de RL-02 no pueden quedar cableados como constante en el dominio (regla dura 14).

**Pasos.** Con `/revision-cumplimiento` como filtro de cierre, con foco en los bloques A (registro horario), B (privacidad) y E (cambios retroactivos):

1. Definir la **política de retención por tipo de dato** conforme a RL-11: registros de jornada **4 años**; `audit_log` **4 años**; log técnico **90 días**; `error_events` **90 días** (doc 02 §8.2.1, `ERROR_HISTORY_RETENTION_DAYS=90` del Anexo B); copias con caducidad alineada.
2. Introducir el puerto que sirve el umbral de retención al dominio de `Compliance` y su adaptador provisional (ver tensión anterior).
3. Comando `php artisan compliance:apply-retention --dry-run` (doc 02 Anexo C) que **no borra nada** y produce el informe de lo que se purgaría: tablas, rangos, conteos.
4. Modo de ejecución real **con confirmación explícita del responsable** (RF-PR-03) y emisión del **informe de lo purgado**, guardado en el servidor del cliente.
5. Salvaguarda dura: «la purga no puede alcanzar registros aún vigentes» (`/revision-cumplimiento`, bloque A). Una prueba debe demostrarlo con un registro a un día de cumplir el plazo.
6. Escritura en `audit_log` de la ejecución de la purga, con actor, alcance y conteos.
7. **La purga de `audit_log` es `DROP PARTITION`, nunca `DELETE`** ([ADR-027](../docs/adr/ADR-027-audit-log-particionado.md)). Procedimiento, en este orden y en una sola transacción:

   1. **Verificar la cadena completa de la partición** que va a soltarse. Si no verifica, **abortar**: una partición con la cadena rota no se purga, se investiga.
   2. **Sellar el ancla**: insertar en `audit_chain_anchors` el año, el `first_hash`, el `last_hash`, el número de filas, el momento y el rol que sella.
   3. `ALTER TABLE audit_log DETACH PARTITION audit_log_YYYY` y soltarla.

   Lo ejecuta el **rol de mantenimiento** provisionado en la tarea 2.2, **no el usuario de aplicación**, que sigue teniendo solo `INSERT` y `SELECT` sobre la tabla y sobre cada partición (regla dura 6, sin excepción). El sellado previo es lo que permite a `compliance:verify-audit-chain` distinguir una purga legítima de una manipulación: sin ancla, el verificador denunciaría rotura **todos los días de forma permanente** tras la primera purga, y una alerta crítica que suena siempre acaba silenciada — que es perder la única capacidad que esta tabla aporta.

8. Registro en el Scheduler de la propuesta periódica; la ejecución destructiva **nunca** es automática sin confirmación (RF-PR-03).
9. Documentar en `docs/cliente/obligaciones-legales.md` y `docs/cliente/operacion.md` qué le corresponde al cliente en materia de conservación (RL-21). **⚠️ No cubierto por los documentos — decidir** si esa redacción se hace aquí o se acumula a la tarea 5.11, que es la dueña de la documentación de cliente.
10. **Runbook `solicitud-derechos-rgpd.md`** (RL-10), que es de esta tarea: es la que reúne el conocimiento de qué se conserva, dónde y con qué plazo. El procedimiento cubre acceso, rectificación —que **no** es borrado, sino corrección trazada de la tarea 2.3— y supresión de lo que ya no está bajo deber de conservación, con la advertencia explícita de que **el registro de jornada dentro de sus 4 años no se suprime a petición**, porque su conservación es una obligación legal del empleador y no un interés del responsable.
11. Pasar `/revision-cumplimiento` completo, con atención al bloque E: si la retención se aplica con efecto retroactivo, valorar que puede alterar registros ya entregados, y auditar el cambio de umbral con su valor anterior y su fecha de efecto.

**Artefactos.**

- `backend/app/Modules/Compliance/Domain/` — `RetentionPolicy` (doc 01 §5.1 la sitúa en `Compliance`).
- `backend/app/Modules/Compliance/Application/Port/` — puerto del umbral de retención.
- Comando de consola `compliance:apply-retention`.
- `backend/app/Modules/Compliance/Infrastructure/` — ejecutor de purga por lotes, y el sellado de `audit_chain_anchors` + `DROP PARTITION` con el rol de mantenimiento (ADR-027).
- `docs/cliente/obligaciones-legales.md`, `docs/cliente/operacion.md` (si se decide redactar aquí).
- `docs/runbooks/solicitud-derechos-rgpd.md` (RL-10).

**Pruebas exigidas.** §9.5: toca **esquema** y borra filas → **Integración**. La decisión de qué está vencido es una regla con umbral: **Unitaria** del cálculo de la fecha de corte con el reloj inyectado. **⚠️ No cubierto por los documentos — decidir** si esa decisión se documenta como una regla `RN-*` nueva en el doc 01 §4 —lo que activaría `/nueva-regla-de-negocio`— o queda como política de `Compliance` derivada de RL-02.

- Unitaria: con umbral de 4 años y reloj fijo, un registro de hace 4 años menos un día **no** es purgable; de hace 4 años y un día, sí (valores límite explícitos) → `->group('RL-02', 'RF-PR-03')`.
- Unitaria: con un perfil de retención distinto, el corte cambia sin tocar el código (regla dura 14) → `->group('RF-PD-07', 'RL-11')`.
- Integración: `--dry-run` no modifica ninguna fila y produce los conteos correctos → `->group('RF-PR-03')`.
- Integración: la purga real deja entrada en `audit_log` con alcance y conteos → `->group('RL-04', 'RF-PR-03')`.
- Integración: la purga **no** alcanza `audit_log` antes de sus 4 años ni registros de jornada vigentes → `->group('RL-02', 'RL-11')`.
- **Integración (ADR-027), la prueba que decide si la alerta de RS-07 sirve para algo:** tras purgar la partición más antigua por `DROP PARTITION` con su ancla sellada, `compliance:verify-audit-chain` termina **en verde** e informa de la purga; alterada en cambio una fila de una partición viva, **denuncia rotura**. El verificador distingue purga legítima de manipulación → `->group('RL-02', 'RS-07')`.
- Integración: una partición cuya cadena **no verifica no se purga**; el comando aborta y la deja en su sitio → `->group('RS-07')`.
- Integración: el usuario de aplicación **no puede** ejecutar `DROP PARTITION`; solo el rol de mantenimiento → `->group('RS-07', 'RL-04')`.
- Integración: el log técnico y `error_events` se purgan a 90 días, en su propio ciclo → `->group('RL-11')`.

**Verificación.**

```bash
php artisan compliance:apply-retention --dry-run     # informe, cero filas modificadas
php artisan test --group=RL-02 --group=RF-PR-03
psql -d fichaje -c "SELECT count(*) FROM audit_log WHERE occurred_at < now() - interval '4 years';"
```

Esperado: `--dry-run` es idempotente y no destructivo; sin confirmación explícita, el comando no elimina nada; tras la ejecución real, el informe de purga coincide con los conteos previos y queda una entrada en `audit_log`.

**Terminado cuando** (§10.3): Deptrac en verde · unitarias e integración en verde con límites explícitos · trazabilidad en verde · PHPStan 9 limpio · **`audit_log` escrito** · migración reversible si aplica · umbral fuera del código · **documentación de cliente actualizada** (§10.3: «si añade un modo de fallo o un parámetro») · informe de `/revision-cumplimiento` sin bloqueantes.

---

### Tarea 2.11 — Copias cifradas, verificadas, con prueba de restauración

| | |
|---|---|
| **Horas** | 4–6 |
| **Agente / Skill** | `devops-observabilidad` |
| **Requisitos** | RF-PR-04, RNF-D-05 (doc 02 §11 y Anexo A). Objetivos: **RPO ≤ 15 min y RTO ≤ 4 h** (RNF-D-02, doc 01 §6.2), cifrado en reposo de copias (RL-12), datos en la UE en la infraestructura del cliente (RL-14) |
| **Precondiciones** | No figura en el §11.3. Derivado: necesita el entorno de Compose y los servicios de **0.1**, y un esquema estable para poder validar integridad referencial y conteos. **⚠️ No cubierto por los documentos — decidir** su posición en la fase |
| **Bloquea a** | No figura en el §11.3. Nota de dependencia real: RF-PD-10 (actualizador, tarea 5.7) exige «copia de seguridad previa automática y verificada — si la copia falla, la actualización no continúa», luego se apoya en esta tarea |

**Objetivo.** Existe una copia diaria cifrada, **verificada automáticamente**, con archivado de WAL que sostiene el RPO de 15 minutos, y un simulacro de restauración automatizado que levanta la última copia en un contenedor limpio y valida integridad referencial y conteos. Y existe la alerta de copia fallida con su runbook.

**Principio que gobierna la tarea** (doc 03 §4.3, `devops-observabilidad`): **una copia no verificada no es una copia.**

**Reglas duras aplicables.**

- **16** — la copia se queda en la infraestructura del cliente; el fabricante no la recibe ni la custodia.
- **21** — la salida de los scripts no imprime datos personales.
- **13** — rutas, destinos y retención son configuración (`BACKUP_PATH`, `BACKUP_ENCRYPTION_KEY` del Anexo B), no código.
- **6** — la restauración de una copia en producción es una acción con relevancia operativa: queda registrada en el informe y en el runbook. **⚠️ No cubierto por los documentos — decidir** si además escribe en `audit_log`; el bloque D de `/revision-cumplimiento` no la lista.

**Convenciones obligatorias de los scripts** (doc 02 §3.5, «Scripts de instalación y operación»): `set -euo pipefail` e `IFS=$'\n\t'`; guía de estilo de Shell de Google y formato con `shfmt -i 2`; **idempotencia** (comprobar el estado antes de actuar); **fallo seguro** (requisitos verificados antes de tocar nada; si algo falla, el sistema queda como estaba); mensajes de error que dicen **qué hacer**, con códigos de salida documentados en la cabecera; y **ningún secreto en el script ni en su salida** — se generan en el servidor del cliente (§7.7).

**Pasos.**

1. Configurar **WAL archiving** en PostgreSQL 17 (doc 02 §3.2: «WAL archiving → RPO ≤ 15 min (RNF-D-02)») en `infra/docker/postgres/` y en el Compose de producción.
2. Implementar `infra/scripts/backup.sh`: volcado completo, **cifrado** con `BACKUP_ENCRYPTION_KEY` (clave separada, doc 02 §7.1, capa «Datos»), destino `BACKUP_PATH`, rotación con caducidad alineada a RL-11.
3. Implementar `infra/scripts/restore.sh`: restauración con verificación previa de precondiciones y mensajes que digan qué hacer si falta espacio o la clave no descifra.
4. Comandos `php artisan backup:run && php artisan backup:verify` (doc 02 Anexo C) que envuelvan los scripts o los complementen, para que la verificación sea parte del ciclo y no un paso opcional.
5. **Simulacro de restauración automatizado**: script que restaura la última copia en un **contenedor limpio** y valida integridad referencial y conteos (§9.4, «Restauración de copia»). Ejecución **trimestral** (RNF-D-05, RQ-09).
6. Instrumentar el resultado de la copia y de su verificación. **⚠️ No cubierto por los documentos — decidir** el nombre de la métrica: el §8.2 del doc 02 no incluye ninguna de respaldo pese al encabezado «Credenciales y respaldo», que solo lista `employees_without_delivered_credential`, `credentials_pending_print` y `pin_fallback_scans_total`. Añadirla al §8.2 forma parte de la tarea.
7. Alerta del doc 01 §9.3: **«Copia de seguridad fallida o no verificada | cualquiera | Crítica»**, con enlace a `docs/runbooks/restaurar-backup.md` (doc 02 §12: «Recuperación y simulacro trimestral»). Añadir también «Espacio en disco < 20 % | Alta», que es la causa habitual de una copia fallida.
8. Escribir `docs/runbooks/restaurar-backup.md` con el procedimiento de recuperación dentro del **RTO de 4 h** y el guion del simulacro trimestral.
9. Documentar el reparto: doc 02 §11.6.3 asigna «Copias de seguridad y su verificación» al **cliente**, y al fabricante «Herramientas y alerta si fallan». La alerta no es un extra: es lo que el fabricante promete.

**Artefactos.**

- `infra/scripts/backup.sh`, `infra/scripts/restore.sh` (árbol del doc 02 §2).
- `infra/docker/postgres/` — configuración de WAL archiving.
- `infra/compose.prod.yaml` — el que se entrega al cliente.
- `infra/observability/` — reglas de alerta de copia fallida y de disco.
- `docs/runbooks/restaurar-backup.md`.
- Script del simulacro de restauración y su ejecución programada. **⚠️ No cubierto por los documentos — decidir** si vive en `infra/scripts/` o como *workflow* en `.github/workflows/`; el §2 no lo ubica.

**Pruebas exigidas.** §9.5 no tiene fila para infraestructura, así que lo aplicable son los umbrales del §9.2 y el escenario del §9.4.

- **Escenario ineludible del §9.4 «Restauración de copia»:** «Script automatizado que restaura la última copia en un contenedor limpio y valida integridad referencial y conteos» → `->group('RF-PR-04', 'RNF-D-05')` en la prueba que lo orquesta, o etiqueta equivalente en el *workflow*.
- **ShellCheck + `shfmt -i 2 -d`: 0 hallazgos** sobre `infra/scripts/` y los scripts entregados al cliente (§9.2).
- Verificación de que la copia está **cifrada**: el fichero no es legible sin la clave → `->group('RL-12')`.
- Idempotencia: ejecutar `backup.sh` dos veces no corrompe la copia anterior ni deja trabajo a medias.
- Fallo seguro: simular disco lleno y comprobar que el sistema queda como estaba y el mensaje dice qué hacer.
- Ausencia de secretos en la salida del script y en los logs del pipeline (§7.7, Semgrep).

**Verificación.**

```bash
shellcheck infra/scripts/*.sh
shfmt -i 2 -d infra/scripts/
php artisan backup:run && php artisan backup:verify
bash infra/scripts/restore.sh --dry-run
# Simulacro completo en contenedor limpio: conteos e integridad referencial validados
```

Esperado: `backup:verify` falla ruidosamente si la copia está truncada o no descifra; el simulacro termina con los conteos por tabla coincidiendo con el origen y sin violaciones de clave foránea; ShellCheck y shfmt sin hallazgos.

**Terminado cuando** (§10.3, subconjunto aplicable): scripts conformes al §3.5 y verificados por ShellCheck y shfmt · **instrumentación añadida** (métrica de resultado de copia) · **alerta con runbook** (`restaurar-backup.md`) · simulacro de restauración automatizado y ejecutable · documentación de cliente actualizada (operación: copias) · ningún secreto en el repositorio, en las imágenes ni en los logs del pipeline · nada específico de un cliente.

---

### Tarea 2.12 — Rotación de clave de firma con solape y reimpresión progresiva

| | |
|---|---|
| **Horas** | 4–5 |
| **Agente / Skill** | `backend-laravel`, revisión de `seguridad-cumplimiento` |
| **Requisitos** | RF-QR-07 (doc 02 §11 y Anexo A). Sostiene RS-01 y RS-08 (gestión de secretos con rotación documentada). Mitiga R10 del doc 01 §12 (pérdida de la clave de firma) |
| **Precondiciones** | No figura en el §11.3. Derivado del doc 02 §11, Fase 1: **1.5** implementa «credenciales HMAC, `key_id`, revocación», y **1.10** la generación de tarjetas y el panel de estado, que es lo que permite reimprimir progresivamente. **⚠️ No cubierto por los documentos — decidir** su posición dentro de la fase |
| **Bloquea a** | No figura en el §11.3 |

**Objetivo.** Se puede emitir una clave de firma nueva y retirar la anterior **sin dejar a nadie sin poder fichar**: dos claves activas en solape identificadas por `key_id`, reimpresión progresiva de tarjetas, y retirada de la clave antigua solo cuando el panel confirma que no queda ninguna credencial activa con ese `key_id`. Con su runbook.

**Reglas duras aplicables.**

- **10** — el payload va firmado con HMAC (`FH1.<key_id>.<token>.<sig>`) y nunca lleva PII ni identificadores secuenciales. La rotación no puede cambiar el prefijo `FH1`: renombrarlo invalidaría credenciales ya impresas (doc 02, nota de nomenclatura).
- **11** — la reimpresión es **física**: tarjeta impresa y plastificada. No hay envío por correo ni credencial en móvil (ADR-014).
- **17** — durante el solape, una firma hecha con una clave ya retirada produce **rechazo genérico y de tiempo constante**, indistinguible de «no existe» o «revocada» (RS-03).
- **6** — emisión, reimpresión, entrega y revocación quedan en `audit_log`.
- **19** — el quiosco no bloquea: si el padrón cacheado no reconoce el token nuevo, encola igualmente y genera incidencia.
- **13** — los `key_id` y las claves son configuración del cliente (`QR_SIGNING_KEY_*` del Anexo B), nunca código.

**Diseño de referencia (doc 02 §5.3, literal).**

> Dos claves activas simultáneamente (`current` y `previous`) en el gestor de secretos. Al rotar: se emite `key_id` nuevo, las tarjetas se reimprimen progresivamente, y la clave anterior se retira cuando el panel confirma que no queda ninguna credencial activa con ese `key_id`.
>
> **Sin `key_id` habría que reimprimir toda la plantilla en un solo día.** Con él, la operación se reparte en semanas sin dejar a nadie sin poder fichar.

**Pasos.**

1. Verificar que la resolución de clave por `key_id` del §5.2 admite **dos** claves activas (`QR_SIGNING_KEY_CURRENT_ID` / `QR_SIGNING_KEY_CURRENT` y `QR_SIGNING_KEY_PREVIOUS_ID` / `QR_SIGNING_KEY_PREVIOUS`, Anexo B) y que la comparación sigue siendo en tiempo constante con `hash_equals`.
2. Comando `php artisan credentials:rotate-key` (doc 02 Anexo C: «Rotación con solape»): emite `key_id` nuevo, reemite credenciales sin invalidar las vigentes y las deja **pendientes de imprimir**.
3. Reimpresión progresiva apoyada en los comandos existentes de 1.10: `credentials:print`, `credentials:print-batch --site= --pending`, `credentials:deliver`, y en `credentials:status --pending` («Quién no puede fichar todavía»).
4. Condición de retirada: la clave anterior se retira **solo** cuando no queda ninguna credencial activa con ese `key_id`. El panel de estado de credenciales (RF-QR-08) es la fuente de esa confirmación.
5. Instrumentar `credentials_pending_print{site}` y `employees_without_delivered_credential{site}` (gauges, §8.2). La segunda «debe llegar a cero antes del primer día de cada incorporación» y durante una rotación es el indicador de avance. Vigilar también `pin_fallback_scans_total{site}`: una subida durante la rotación indica que la reimpresión va por detrás de la retirada.
6. Escribir en `audit_log` la rotación, la reemisión y la retirada de la clave.
7. Escribir el runbook `docs/runbooks/rotacion-clave-qr.md` (doc 02 §12: «Reimpresión progresiva sin dejar a nadie sin fichar») y enlazarlo desde `docs/runbooks/rotacion-secretos.md` (§7.7: rotación documentada para `APP_KEY`, claves HMAC de QR, credenciales de base de datos, tokens de dispositivo y claves de copia).
8. **Revisión obligatoria de `seguridad-cumplimiento`** (doc 02 §11), bloque C de `/revision-cumplimiento`: `hash_equals`, rechazos indistinguibles, token almacenado solo hasheado, ningún secreto en el repositorio.

**Artefactos.**

- `backend/app/Modules/Identity/Domain/`, `.../Application/UseCase/` — rotación y reemisión.
- `backend/app/Modules/Identity/Infrastructure/Adapter/` — `HmacSignatureVerifier` con resolución por `key_id` (doc 02 §1.5).
- Comando de consola `credentials:rotate-key`.
- `frontend-admin/src/features/credentials/` — avance de la reimpresión.
- `docs/runbooks/rotacion-clave-qr.md`, `docs/runbooks/rotacion-secretos.md`.
- `.env.example` — documentación de `QR_SIGNING_KEY_PREVIOUS_ID` y `QR_SIGNING_KEY_PREVIOUS`.

> **Decisión: la rotación no tiene endpoint.** El Anexo B no recogía ninguno (sí `POST /api/v1/credentials`, `/print`, `/print-batch`, `/deliver`, `/revoke`, `GET /credentials/status`), y no se añade. Rotar la clave de firma **no es una acción de panel**: es un acto operativo con semanas de logística de reimpresión detrás (§5.3), que empieza tocando el gestor de secretos del servidor y termina cuando el último empleado recibe su tarjeta nueva. Un botón que lo dispare invita a pulsarlo.
>
> Se queda en `php artisan credentials:rotate-key`, y el panel solo necesita **leer**: `GET /api/v1/credentials/status` admite ahora filtro **`?key_id=`** (añadido al Anexo B del doc 01) para ver a quién le falta reimprimir con la clave antigua. Eso es lo que permite retirar la clave anterior cuando el panel confirma que no queda ninguna credencial activa con ese `key_id`, que es literalmente el procedimiento del §5.3.

**Pruebas exigidas.** §9.5: introduce lógica de verificación de firma → **Unitaria**; toca el esquema de `credentials` (`key_id`) → **Integración**; el filtro `?key_id=` modifica un endpoint existente → **Feature + Contrato + autorización negativa por rol**.

- Unitaria: una firma válida con `key_id` **previous** se acepta durante el solape; con un `key_id` desconocido o retirado, **rechazo genérico** → `->group('RF-QR-07', 'RS-03')`.
- Unitaria: los tres rechazos —código inexistente, credencial revocada, firma inválida— son indistinguibles en respuesta y en tiempo → `->group('RS-03', 'RS-02')`.
- Integración: `credentials:rotate-key` no invalida ninguna credencial vigente y deja las nuevas pendientes de imprimir → `->group('RF-QR-07')`.
- Integración: la retirada de la clave anterior se rechaza mientras exista una credencial activa con ese `key_id` → `->group('RF-QR-07')`.
- Integración: rotación, reemisión y retirada quedan en `audit_log` → `->group('RL-04')`.
- E2E o prueba de campo: una tarjeta reimpresa con el `key_id` nuevo se escanea correctamente en el quiosco (doc 03 §6.3, criterio de terminado de credenciales) → `tag: ['@RF-QR-07']`.

**Verificación.**

```bash
php artisan credentials:rotate-key
php artisan credentials:status --pending          # avance de la reimpresión
php artisan test --group=RF-QR-07 --group=RS-03
curl -s http://localhost/metrics | grep -E 'credentials_pending_print|employees_without_delivered_credential'
```

Esperado: durante el solape, tarjetas antiguas y nuevas fichan igual; al intentar retirar la clave anterior con credenciales activas pendientes, el comando se niega y explica cuántas quedan y en qué centro.

**Terminado cuando** (§10.3): Deptrac en verde · unitarias e integración en verde · trazabilidad en verde · PHPStan 9 limpio · contrato OpenAPI actualizado si se añade endpoint · autorización probada en negativo si lo hay · instrumentación añadida · **`audit_log` escrito** · migración reversible si aplica · **runbook `rotacion-clave-qr.md` escrito** · ningún secreto en el repositorio · revisión de `seguridad-cumplimiento` sin bloqueantes · nada específico de un cliente.

---

## Cierre de la Fase 2

Procedimiento del **doc 03 §6.6**, aplicado a esta fase:

```
Cierra la Fase 2 del plan.

1. seguridad-cumplimiento: revisa todo lo implementado contra STRIDE,
   RGPD y art. 34.9 ET. Informe por severidad.
2. revisor-codigo: revisión final buscando duplicación, complejidad
   innecesaria e incumplimientos de la Definición de Terminado.
3. qa-testing: verifica cobertura, MSI y que cada requisito de la fase
   (Anexo A del documento 01) tiene prueba que lo cubre.
4. devops-observabilidad: comprueba que lo nuevo está instrumentado y
   que cada alerta añadida tiene su runbook.

Entrégame: los hallazgos bloqueantes, los requisitos de la fase sin
cobertura de prueba, y qué queda pendiente para pasar a la siguiente.
```

**Comprobaciones concretas del cierre.**

```bash
make quality && make test && make mutate
php artisan qa:traceability --check        # RF-PA-01..05, RF-IN-01..05, RF-GP-02, RF-PR-01..04,
                                            # RF-QR-07, RF-ID-01..03, RN-10..15, RL-02..04,
                                            # RL-06..15, RS-05..07 con prueba que los referencia
php artisan compliance:verify-audit-chain  # cadena íntegra
php artisan attendance:reconcile --from=<inicio> --to=<fin>   # sin divergencias
php artisan backup:run && php artisan backup:verify
```

**Runbooks que deben existir al cerrar la fase** (doc 02 §12): `rotura-cadena-auditoria.md` (2.2), `divergencia-proyeccion.md` (2.7), `requerimiento-inspeccion.md` (2.9), `restaurar-backup.md` (2.11), `rotacion-clave-qr.md` y `rotacion-secretos.md` (2.12). El §8.4 lo dice sin matices: una alerta sin procedimiento asociado es ruido y se elimina.

**La siguiente fase en el orden real es la 5 — Productización**, no la 3 (doc 02 §11: orden **0 → 1 → 2 → 5 → 3 → 4**). Dos deudas de esta fase se cierran allí: el perfil de cumplimiento que sirve los umbrales de retención y de RN-10/11/12 (tarea 5.2) y la configuración con ámbito que sustituye a los adaptadores provisionales (tarea 5.1).

---

## Advertencia: esta es la fase que no se recorta

Del **doc 02 §11.2**, tabla «Qué se sacrifica al recortar», fila literal:

> **Fase 2 completa** | **Incumplimiento legal.** Sin auditoría inmutable, retención y exportación para Inspección, el registro no satisface el art. 34.9 ET. Es el recorte que no se debe hacer

Y el contexto que lo sostiene, del doc 01 §7.1: «la falta de registro o su falseamiento se tipifica como infracción grave en materia de relaciones laborales, sancionable por cada centro de trabajo. La inmutabilidad y la trazabilidad son el requisito, no un extra». El doc 05 §6.1 ya se lo ha contado al cliente en estos términos: «cada acción con relevancia legal se anota en un registro de auditoría que solo admite añadir, nunca modificar ni borrar», y «si alguien manipulase la base de datos por debajo, la cadena se rompería y el sistema lo detectaría y avisaría al día siguiente». Recortar 2.2 o 2.9 convierte esa frase en falsa.

---

← Anterior: [Fase 1 — MVP de fichaje](03-fase-1-mvp-fichaje.md) · Siguiente: [Fase 5 — Productización](05-fase-5-productizacion.md) · [Índice](README.md)
