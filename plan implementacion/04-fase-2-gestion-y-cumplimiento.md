# Fase 2 — Gestión y cumplimiento · Plan ejecutable

| Campo | Valor |
|---|---|
| **Fase** | 2 — Gestión y cumplimiento |
| **Horas** | **53–68 h** (literal del [doc 02 §11](../docs/02-stack-tecnologico-y-plan-implementacion.md), tabla de Fase 2, tras [ADR-032](../docs/adr/ADR-032-la-fase-1-entrega-un-sistema-legalmente-defendible.md)) |
| **Orden de ejecución** | Tercera. El orden real del plan es **0 → 1 → 2 → 5 → 3 → 4** (doc 02 §11) |
| **Tareas** | 9 (2.1, 2.4–2.10, 2.12). `2.2`, `2.3` y `2.11` se adelantaron a la Fase 1 como `1.14`, `1.15` y `1.18` |
| **Documento origen** | [`../docs/02-stack-tecnologico-y-plan-implementacion.md`](../docs/02-stack-tecnologico-y-plan-implementacion.md) §11, tabla «Fase 2 — Gestión y cumplimiento · 53–68 h» |
| **Requisitos** | Anexo A de [`../docs/01-especificaciones-proyecto.md`](../docs/01-especificaciones-proyecto.md) |
| **Precondición de fase** | Fase 0 y Fase 1 cerradas (doc 02 §11.3) |

**Entregable (literal del doc 02 §11, actualizado tras ADR-032):**

> **Entregable:** sistema operable con comodidad por RRHH y por cada responsable de departamento — presencia en vivo, detección automática de incidencias, 2FA obligatorio. La validez legal del registro ya la entregó la Fase 1; esta fase la hace agradable de operar a diario.

> **[ADR-032](../docs/adr/ADR-032-la-fase-1-entrega-un-sistema-legalmente-defendible.md).** Hasta el 15 de agosto de 2026 esta fase entregaba la validez legal del registro —auditoría inmutable, correcciones trazadas, exportación para Inspección, copias verificadas—, y sin ella cerrar solo `0+1` dejaba un «piloto interno controlado». Esas cuatro piezas se adelantaron a la Fase 1 (tareas `1.14`, `1.15`, `1.17`, `1.18`, más la mitad de `1.16`), y el esfuerzo no desapareció de esta tabla: está en la de la Fase 1. Lo que queda aquí es lo que hace la operación diaria **cómoda**, no lo que la hace **legal**.

---

## Índice de tareas

| # | Tarea | h | Agente / Skill |
|---|---|---|---|
| [2.1](#tarea-21--autenticación-de-gestión-completa-2fa-obligatorio-y-rbac-con-ámbito-por-departamento-sobre-la-base-mínima-de-16) | Autenticación de gestión **completa**: 2FA obligatorio y RBAC con ámbito por departamento sobre la base mínima de 1.6 | 8–10 | `backend-laravel`, revisión de `seguridad-cumplimiento` |
| [2.4](#tarea-24--panel-presencia-en-vivo-con-reverb-y-fallback) | Panel: presencia en vivo con Reverb y *fallback* | 10–12 | `frontend-panel` + `backend-laravel` |
| [2.5](#tarea-25--panel-bandeja-de-incidencias-y-resolución) | Panel: bandeja de incidencias y resolución | 4–5 | `frontend-panel` |
| [2.6](#tarea-26--detección-automática-de-incidencias-scheduler) | Detección automática de incidencias (scheduler) | 6–8 | `backend-laravel` + `/nueva-regla-de-negocio` |
| [2.7](#tarea-27--reconciliación-nocturna-con-alerta-de-divergencia) | Reconciliación nocturna con alerta de divergencia | 4–6 | `backend-laravel` |
| [2.8](#tarea-28--informes-por-periodo-contratos-trabajadas-frente-a-contratadas) | Informes por periodo, contratos, trabajadas frente a contratadas | 10–12 | `backend-laravel` + `/informe-nuevo` |
| [2.9](#tarea-29--exportaciones-csvxlsxpdf-de-conveniencia) | Exportaciones CSV/XLSX/PDF de conveniencia | 3–4 | `backend-laravel` + `/informe-nuevo` |
| [2.10](#tarea-210--retención-con-confirmación-y-purga-documentada) | Retención con confirmación y purga documentada | 4–6 | `backend-laravel` + `/revision-cumplimiento` |
| [2.12](#tarea-212--rotación-de-clave-de-firma-con-solape-y-reimpresión-progresiva) | Rotación de clave de firma con solape y reimpresión progresiva | 4–5 | `backend-laravel`, revisión de `seguridad-cumplimiento` |

---

## Requisitos que cubre la fase

Del **Anexo A del doc 01** («Trazabilidad requisito → fase»), literal tras ADR-032:

> **Fase 2 — Gestión y cumplimiento** | RF-PA-01..02, RF-PA-05, RF-IN-01..04, RF-GP-02, RF-PR-01..03, RF-QR-07, RF-ID-01..03 (**completos: 2FA y ámbito por departamento**), RN-10..12, RN-14, RL-02, RL-07..08, RL-10..11, RL-13..15, RS-05..06, **RNF-P-04..05**, **RNF-D-03**

**Aviso de alcance.** RN-10, RN-11 y RN-12 entran en esta fase como reglas del dominio (Anexo A), pero su **vista de cumplimiento** en el panel es la tarea 3.4 (doc 02 §11, Fase 3: «Vista de cumplimiento: descansos, jornada máxima, exceso semanal | RF-PA-06, RN-10..12»). Y sus umbrales son parámetros del perfil de cumplimiento (doc 01 §4, nota introductoria; RF-PD-07), que se construye en la tarea 5.2 — tensión detallada en la [tarea 2.10](#tarea-210--retención-con-confirmación-y-purga-documentada).

## Agentes protagonistas

Del **doc 03 §2.2**, literal:

> **Fase 2 — Gestión y cumplimiento** | `backend-laravel` y `frontend-panel`, con revisión obligatoria de `seguridad-cumplimiento` en auditoría y rotación de claves

`arquitecto-dominio` y `devops-observabilidad` ya no abren tarea en esta fase: la auditoría encadenada, las correcciones versionadas y las copias verificadas —donde intervenían— se adelantaron a la Fase 1 por [ADR-032](../docs/adr/ADR-032-la-fase-1-entrega-un-sistema-legalmente-defendible.md).

---

## Las tareas, desarrolladas

### Tarea 2.1 — Autenticación de gestión **completa**: 2FA obligatorio y RBAC con ámbito por departamento sobre la base mínima de 1.6

| | |
|---|---|
| **Horas** | 8–10 |
| **Agente / Skill** | `backend-laravel`, revisión de `seguridad-cumplimiento` |
| **Requisitos** | RF-ID-01..03 (doc 02 §11). Anexo A del doc 01: `RF-ID-01..03` **completos: 2FA y ámbito por departamento**. Añade RS-06 (2FA obligatorio para `admin`, `rrhh` y `auditor`) y RS-05 (todo acceso a datos personales de terceros queda en auditoría) |
| **Precondiciones** | 1.1→1.2 cerradas (dominio; camino crítico §11.3) y **1.6** — «autenticación de gestión mínima (login, roles `admin`/`rrhh`, sin 2FA)», doc 02 §11, nota tras la tabla de Fase 1 |
| **Bloquea a** | **2.4** (necesita el ámbito por departamento para la difusión por canal privado) y, en cascada, **2.5** (bandeja con ámbito de responsable) |

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
7. Escribir en `audit_log` el acceso denegado y el acceso a datos personales de terceros (RS-05; skill `/revision-cumplimiento` bloque D: «Accede a datos personales de terceros», «Cambia roles, permisos o configuración»). La cadena por hash ya existe desde **1.14**: esta tarea solo añade el punto de escritura, no la tabla.
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

### Tarea 2.4 — Panel: presencia en vivo con Reverb y *fallback*

| | |
|---|---|
| **Horas** | 10–12 |
| **Agente / Skill** | `frontend-panel` + `backend-laravel` |
| **Requisitos** | RF-PA-01..02 (doc 02 §11 y Anexo A). Aplica RNF-P-04 (carga con 500 empleados < 1,5 s LCP) y RNF-D-03 (*fallback* a sondeo cada 15 s) |
| **Precondiciones** | No figura en el camino crítico del §11.3. Derivado del Anexo B del doc 01: `GET /api/v1/attendance/live` es `[rol: manager+]`, luego necesita el rol y el ámbito de **2.1**; y necesita los eventos `EmployeeClockedIn`/`EmployeeClockedOut` de **1.4**. No depende de `audit_log` (1.14) ni de correcciones (1.15): es una consulta de lectura sin escritura propia |
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
- Rendimiento: carga con 500 empleados por debajo de 1,5 s de LCP (RNF-P-04). Medido con **Lighthouse CI** (`@lhci/cli`) contra la build de producción del panel con datos de semilla de 500 empleados: es la herramienta estándar para LCP en CI y no exige infraestructura nueva, a diferencia de k6 (que el §9.2 reserva para carga de API, no de renderizado de frontend).

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

### Tarea 2.5 — Panel: bandeja de incidencias y resolución

| | |
|---|---|
| **Horas** | 4–5 |
| **Agente / Skill** | `frontend-panel` |
| **Requisitos** | RF-PA-05 (doc 02 §11 y Anexo A). El detalle de jornada (RF-PA-03) se adelantó a la **1.16** por [ADR-032](../docs/adr/ADR-032-la-fase-1-entrega-un-sistema-legalmente-defendible.md); esta tarea reutiliza sus componentes de tramos y totales para incrustar las marcas de incidencia |
| **Precondiciones** | **2.6**, que produce los tipos de incidencia que llenan la bandeja (doc 01 RF-PA-05 y §5.5, tabla `incidents`). Y **1.16**, cuyos componentes de tramos y totales se reutilizan aquí |
| **Bloquea a** | No figura como bloqueante de ninguna otra tarea en §11.3 |

**Objetivo.** El responsable trabaja la bandeja de incidencias pendientes asignadas a su departamento, con un flujo de resolución y nota, y ve las marcas de incidencia incrustadas en el detalle de jornada que ya construyó la **1.16**.

**Reglas duras aplicables.**

- **18** — la interfaz refleja permisos, pero la autorización real está en el servidor.
- **21** — nada de nombres en los logs de cliente que se envíen a `error_events` (RF-PD-15, regla dura 21).
- **3** — zonas horarias mostradas, no adivinadas, en la antigüedad de cada incidencia.

**Principios de `frontend-panel` (doc 03 §4.3) que aquí aplican.**

1. **Volumen real con virtualización.**
2. **La autorización se refleja en la interfaz pero no se confía en ella.**

**Pasos.**

1. Confirmar en `docs/api/openapi.yaml` `GET /api/v1/incidents` (manager+) y `POST /api/v1/incidents/{id}/resolve` (manager+). Añadir lo que falte **antes** de escribir el componente (ADR-013).
2. Backend, si falta: la bandeja filtrada por ámbito de departamento dentro de la consulta.
3. Bandeja de incidencias: tipos del doc 01 §5.5 (`open_shift_expired`, `short_shift`, `long_shift`, `insufficient_rest`, `clock_skew`, `missing_clock_out`, `anomalous_pattern`), severidad, antigüedad, asignación al responsable del departamento y flujo de resolución con nota.
4. Incrustar la marca de incidencia en el detalle de jornada de la **1.16**, reutilizando sus componentes de tramos y totales en vez de duplicarlos.
5. Instrumentar `incidents_open{type,severity}` (gauge) e `incident_resolution_seconds{type}` (histogram) del §8.2. La segunda alimenta el objetivo «< 24 h» del doc 01 §1.3 y el cuadro de 3.13.
6. i18n ES/EN y accesibilidad AA (doc 01 §6.5).

**Artefactos.**

- `frontend-admin/src/features/incidents/`.
- `backend/app/Modules/Compliance/Http/` — bandeja y resolución de incidencias.
- `docs/api/openapi.yaml`.

**Pruebas exigidas.** §9.5: **recorrido de usuario** en el panel → **E2E**; expone/consume **endpoints** → **Feature + Contrato** y **autorización negativa por rol**.

- Feature + Contrato de `GET /incidents` y `POST /incidents/{id}/resolve` → `->group('RF-PA-05')`.
- Autorización negativa: bandeja fuera del departamento → 403 y registro en auditoría; Gherkin «Aislamiento por departamento» del doc 01 §11 → `->group('RF-ID-03', 'RS-05')`.
- E2E: resolver una incidencia de la bandeja → `tag: ['@RF-PA-05']`.
- Accesibilidad con `@axe-core/playwright`: 0 violaciones críticas o graves (§9.2).

**Verificación.**

```bash
make e2e -- --grep "@RF-PA-05"
npm --prefix frontend-admin run test:unit
npx vue-tsc --noEmit -p frontend-admin
php artisan test tests/Feature/Compliance/IncidentsTest.php
```

Esperado: 0 violaciones de accesibilidad críticas o graves; resolver una incidencia la retira de la bandeja y deja nota.

**Terminado cuando** (§10.3): pruebas Feature, Contrato, autorización negativa y E2E en verde · convenciones del §3.5 verificadas · contrato OpenAPI actualizado · autorización probada en negativo · instrumentación añadida · textos en ES y EN · **accesibilidad verificada** · nada específico de un cliente en el código.

---

### Tarea 2.6 — Detección automática de incidencias (scheduler)

| | |
|---|---|
| **Horas** | 6–8 |
| **Agente / Skill** | `backend-laravel` + `/nueva-regla-de-negocio` |
| **Requisitos** | RF-PR-01 (doc 02 §11). Reglas implicadas: RN-07 (duración mínima computable), RN-08 (máxima antes de considerarse anómala, **nunca cierre automático**), RN-10 (descanso entre jornadas), **RN-11** (jornada diaria ordinaria) y **RN-12** (descanso en jornada continuada), más RN-15 (retraso de sincronización). El Anexo A asigna RN-10..15 a esta fase |
| **Precondiciones** | No figura en el §11.3. Derivado: necesita el dominio de 1.1–1.2, el esquema de 1.3 y el `audit_log` de **1.14** para dejar traza. Precede a **2.5**, que trabaja las incidencias que esta tarea genera |
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

   El runbook tiene que dejar claro lo que RN-08 impone: **el sistema nunca cierra el turno por su cuenta**. El procedimiento es contactar con la persona o su responsable, y corregir con el mecanismo trazado de la tarea **1.15** indicando motivo del catálogo (Anexo C del doc 01: `OLVIDO_FICHAJE_SALIDA`).

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
| **Precondiciones** | No figura en el §11.3. Derivado: necesita la proyección `daily_totals` y su recálculo transaccional de **1.4**, y el `audit_log` de **1.14** para dejar traza de la corrección de proyección. Va después de **1.15** en el orden real: las correcciones son la fuente típica de divergencia que esta tarea reconcilia |
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
6. Alerta del doc 01 §9.3: **«Divergencia en reconciliación nocturna | cualquiera | Crítica»**, con enlace al runbook `docs/runbooks/divergencia-proyeccion.md` (doc 02 §12: «La reconciliación detecta discrepancia»). Destinatario: **`admin`** (el mismo que recibe la alerta de rotura de cadena de auditoría de la tarea 1.14, que es de la misma familia — integridad del registro, no operación del día a día — y ya tiene canal de notificación configurado).
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
- Integración: tras una corrección de 1.15 y una anulación, la reconciliación cuadra con los eventos origen → `->group('RN-06', 'RN-13')`.
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
| **Precondiciones** | **1.15** (correcciones trazadas), de la que este informe lee la trazabilidad |
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
- `frontend-admin/src/features/reports/` — pantalla de consulta de informes. Decidido: el doc 02 §11 asigna esta tarea a `backend-laravel`, así que aquí se entrega **el endpoint y una pantalla mínima de consulta** (formulario de periodo, tabla de resultados). El cuadro de impacto y las comparaciones visuales avanzadas son de **3.13**, que ya tiene agente `frontend-panel` dedicado y depende de indicadores que esta tarea todavía no calcula.

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

### Tarea 2.9 — Exportaciones CSV/XLSX/PDF de conveniencia

| | |
|---|---|
| **Horas** | 3–4 |
| **Agente / Skill** | `backend-laravel` + `/informe-nuevo` |
| **Requisitos** | RF-IN-04 (doc 02 §11 y Anexo A). La exportación normalizada para Inspección (RF-IN-05, RL-03, RL-06) se adelantó a la **1.17** por [ADR-032](../docs/adr/ADR-032-la-fase-1-entrega-un-sistema-legalmente-defendible.md) |
| **Precondiciones** | **2.8** (§11.3: `2.8→2.9`) |
| **Bloquea a** | **5.1→5.2** (§11.3 encadena la rama de productización bajo `2.8→2.9`) |

**Objetivo.** El sistema exporta los informes de la 2.8 a CSV, XLSX y PDF, con los PDF sellados (fecha, emisor y hash del contenido), para el uso diario de RRHH y de cada responsable. **No es** la exportación legal para Inspección —esa es la **1.17**, con su propio formato normalizado y su propio comando de consola—: esta tarea es la comodidad de descargar lo que ya se consulta en pantalla.

**Reglas duras aplicables.**

- **9** — se exportan ambas marcas donde corresponda; el registro legal usa `occurred_at`.
- **4** — un turno nocturno aparece como un tramo, en su jornada de inicio; si el cliente pide horas por día natural, el prorrateo es explícito y va etiquetado como tal (ADR-006).
- **18** — cada endpoint de exportación lleva policy y prueba negativa por rol.
- **6** — toda generación con datos de terceros se registra en `audit_log`.
- **21** — el fichero exportado contiene datos personales por su finalidad; el **log** de la generación no.

**Pasos.** Segunda pasada de `/informe-nuevo` (8 pasos), centrada en el formato, no en el contenido —la pregunta y la fuente ya las resolvió la 2.8—:

1. **Formatos** con `spatie/simple-excel` en **streaming** (doc 02 §3.1: «no carga en memoria un mes de 500 empleados»):
   - **CSV** — UTF-8 **con BOM** para que Excel no rompa los acentos; separador según *locale*.
   - **XLSX** — streaming, cabeceras congeladas, columnas con ancho, **horas como texto `HH:MM`, nunca decimal**.
   - **PDF** — `spatie/laravel-pdf`, con pie de página que incluya fecha de generación, usuario emisor, periodo y **hash del contenido** (RF-IN-04).
2. **Autorización y auditoría**: mismo ámbito que la consulta de origen de la 2.8, y registro en `audit_log` de quién exportó, qué periodo y qué empleados.
3. Descarga desde el panel, sobre los informes ya construidos en **2.8**.
4. **Pruebas**: integración con volumen, sin agotar memoria.

**Artefactos.**

- `backend/app/Modules/Reporting/Application/Query/`, `.../Infrastructure/` — escritores de CSV/XLSX/PDF en streaming.
- `frontend-admin/src/features/reports/` — descarga desde el panel.

**Pruebas exigidas.** §9.5, fila «Genera un **informe o exportación**»: **Integración con volumen** + **Feature + Contrato** + **Autorización negativa**.

- Integración con volumen: exportación de un mes de 500 empleados en streaming sin agotar memoria → `->group('RF-IN-04')`.
- Unitaria: formato de horas `HH:MM`, nunca decimal → `->group('RF-IN-04')`.
- Integración: el PDF lleva sello temporal, emisor y hash del contenido, y el hash cambia si cambia una hora → `->group('RF-IN-04')`.
- Autorización negativa: exportar fuera del ámbito del rol → 403 → `->group('RF-ID-03')`.
- Apertura correcta en Excel y LibreOffice, con acentos → verificación manual documentada.

**Verificación.**

```bash
php artisan test --group=RF-IN-04
php artisan test tests/Feature/Reporting tests/Contract
php artisan qa:traceability --check
```

Esperado: el fichero abre en Excel y LibreOffice con acentos correctos y las horas se leen `HH:MM`.

**Terminado cuando** (§10.3): Deptrac en verde · integración con volumen, feature, contrato y autorización negativa · trazabilidad en verde · PHPStan 9 limpio · contrato OpenAPI actualizado · autorización probada en negativo · instrumentación añadida · textos en ES y EN · nada específico de un cliente.

---

### Tarea 2.10 — Retención con confirmación y purga documentada

| | |
|---|---|
| **Horas** | 4–6 |
| **Agente / Skill** | `backend-laravel` + `/revision-cumplimiento` |
| **Requisitos** | RL-02, RL-11, RF-PR-03 (doc 02 §11 y Anexo A). Relacionado: RL-10 (la supresión queda condicionada al deber legal de conservación) |
| **Precondiciones** | Necesita `audit_log` (**1.14**) para registrar la purga —bloque D de `/revision-cumplimiento`: «Ejecuta una purga por retención»— y el esquema completo del registro, ya cerrado desde la Fase 1. No depende de ninguna otra tarea de esta fase: se ejecuta en cualquier orden dentro de ella |
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

   Lo ejecuta el **rol de mantenimiento** provisionado en la tarea 1.14, **no el usuario de aplicación**, que sigue teniendo solo `INSERT` y `SELECT` sobre la tabla y sobre cada partición (regla dura 6, sin excepción). El sellado previo es lo que permite a `compliance:verify-audit-chain` distinguir una purga legítima de una manipulación: sin ancla, el verificador denunciaría rotura **todos los días de forma permanente** tras la primera purga, y una alerta crítica que suena siempre acaba silenciada — que es perder la única capacidad que esta tabla aporta.

8. Registro en el Scheduler de la propuesta periódica; la ejecución destructiva **nunca** es automática sin confirmación (RF-PR-03).
9. Documentar en `docs/cliente/obligaciones-legales.md` y `docs/cliente/operacion.md` qué le corresponde al cliente en materia de conservación (RL-21). **Se redacta aquí, no en la 5.11**: la 5.11 escribe la documentación de instalación y operación general del producto terminado, tres fases después de que esta tarea decida la política de retención por tipo de dato; redactarla aquí, con el diseño delante, evita que la 5.11 reconstruya de memoria una decisión ajena. La 5.11 la revisa e integra en el paquete final, no la origina.
10. **Runbook `solicitud-derechos-rgpd.md`** (RL-10), que es de esta tarea: es la que reúne el conocimiento de qué se conserva, dónde y con qué plazo. El procedimiento cubre acceso, rectificación —que **no** es borrado, sino corrección trazada de la tarea 1.15— y supresión de lo que ya no está bajo deber de conservación, con la advertencia explícita de que **el registro de jornada dentro de sus 4 años no se suprime a petición**, porque su conservación es una obligación legal del empleador y no un interés del responsable.
11. Pasar `/revision-cumplimiento` completo, con atención al bloque E: si la retención se aplica con efecto retroactivo, valorar que puede alterar registros ya entregados, y auditar el cambio de umbral con su valor anterior y su fecha de efecto.

**Artefactos.**

- `backend/app/Modules/Compliance/Domain/` — `RetentionPolicy` (doc 01 §5.1 la sitúa en `Compliance`).
- `backend/app/Modules/Compliance/Application/Port/` — puerto del umbral de retención.
- Comando de consola `compliance:apply-retention`.
- `backend/app/Modules/Compliance/Infrastructure/` — ejecutor de purga por lotes, y el sellado de `audit_chain_anchors` + `DROP PARTITION` con el rol de mantenimiento (ADR-027).
- `docs/cliente/obligaciones-legales.md`, `docs/cliente/operacion.md` (si se decide redactar aquí).
- `docs/runbooks/solicitud-derechos-rgpd.md` (RL-10).

**Pruebas exigidas.** §9.5: toca **esquema** y borra filas → **Integración**. La decisión de qué está vencido es una regla con umbral: **Unitaria** del cálculo de la fecha de corte con el reloj inyectado. Queda como **política de `Compliance` derivada de RL-02**, no como `RN-*` nueva del doc 01 §4: las `RN-*` son las reglas de cálculo de tiempo del núcleo (`Attendance`), listadas junto a RN-01..16 con su propia numeración cerrada, y esta regla vive en `Compliance` sobre un umbral de configuración (regla dura 14), no sobre una invariante del dominio de fichaje. No activa `/nueva-regla-de-negocio`, que es para el núcleo.

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

### Tarea 2.12 — Rotación de clave de firma con solape y reimpresión progresiva

| | |
|---|---|
| **Horas** | 4–5 |
| **Agente / Skill** | `backend-laravel`, revisión de `seguridad-cumplimiento` |
| **Requisitos** | RF-QR-07 (doc 02 §11 y Anexo A). Sostiene RS-01 y RS-08 (gestión de secretos con rotación documentada). Mitiga R10 del doc 01 §12 (pérdida de la clave de firma) |
| **Precondiciones** | No figura en el §11.3. Derivado del doc 02 §11, Fase 1: **1.5** implementa «credenciales HMAC, `key_id`, revocación», y **1.10** la generación de tarjetas y el panel de estado, que es lo que permite reimprimir progresivamente. Sin más dependencias dentro de esta fase, se ejecuta al cierre: es la única tarea de la Fase 2 que toca material criptográfico ya emitido, y conviene aislarla del resto para no repetir la revisión de `seguridad-cumplimiento` a mitad de fase |
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
php artisan qa:traceability --check        # RF-PA-01..02, RF-PA-05, RF-IN-01..04, RF-GP-02,
                                            # RF-PR-01..03, RF-QR-07, RF-ID-01..03, RN-10..12,
                                            # RN-14, RL-02, RL-07..08, RL-10..11, RL-13..15,
                                            # RS-05..06, RNF-P-04..05, RNF-D-03 con prueba que
                                            # los referencia
php artisan compliance:verify-audit-chain  # cadena íntegra, construida en 1.14
php artisan attendance:reconcile --from=<inicio> --to=<fin>   # sin divergencias
php artisan backup:run && php artisan backup:verify           # copias verificadas desde 1.18
```

**Runbooks que deben existir al cerrar la fase** (doc 02 §12). Tres se heredan **ya escritos** de la Fase 1 por [ADR-032](../docs/adr/ADR-032-la-fase-1-entrega-un-sistema-legalmente-defendible.md) — este cierre solo verifica que siguen ahí, no los crea: `rotura-cadena-auditoria.md` (1.14), `requerimiento-inspeccion.md` (1.17), `restaurar-backup.md` (1.18). Dos son genuinamente nuevos de esta fase: `divergencia-proyeccion.md` (2.7), `rotacion-clave-qr.md` y `rotacion-secretos.md` (2.12). El §8.4 lo dice sin matices: una alerta sin procedimiento asociado es ruido y se elimina.

**La siguiente fase en el orden real es la 5 — Productización**, no la 3 (doc 02 §11: orden **0 → 1 → 2 → 5 → 3 → 4**). Dos deudas de esta fase se cierran allí: el perfil de cumplimiento que sirve los umbrales de retención y de RN-10/11/12 (tarea 5.2) y la configuración con ámbito que sustituye a los adaptadores provisionales (tarea 5.1).

---

## Advertencia: esto ya no es la fase que no se puede recortar

Hasta el 15 de agosto de 2026 esta sección advertía de que la Fase 2 entera era el recorte que no se debe hacer, citando el doc 02 §11.2: *«Sin auditoría inmutable, retención y exportación para Inspección, el registro no satisface el art. 34.9 ET»*.

**[ADR-032](../docs/adr/ADR-032-la-fase-1-entrega-un-sistema-legalmente-defendible.md) movió esa advertencia a otro sitio.** La auditoría inmutable (1.14), las correcciones trazadas (1.15) y la exportación para Inspección (1.17) son ahora de la Fase 1, y **son las que no se pueden recortar** — el doc 02 §11.2 lo dice ahora sobre ellas, no sobre esta fase. Recortar esta Fase 2 entera pierde comodidad de operación —2FA obligatorio, presencia en vivo, detección automática de incidencias, purga por retención automatizada—, no validez legal.

El contexto que sigue sosteniendo la afirmación —doc 01 §7.1: *«la falta de registro o su falseamiento se tipifica como infracción grave... La inmutabilidad y la trazabilidad son el requisito, no un extra»*; doc 05 §6.1: *«cada acción con relevancia legal se anota en un registro de auditoría que solo admite añadir, nunca modificar ni borrar»*— sigue siendo cierto porque la Fase 1 ya lo construyó. **Recortar 1.14, 1.15 o 1.17 es lo que convertiría esa frase en falsa**, no recortar nada de esta fase.

---

← Anterior: [Fase 1 — MVP de fichaje](03-fase-1-mvp-fichaje.md) · Siguiente: [Fase 5 — Productización](05-fase-5-productizacion.md) · [Índice](README.md)
