# Fase 4 — Evolución

| Campo | Valor |
|---|---|
| **Fase** | 4 — Evolución |
| **Orden de ejecución** | 6.º y último (0 → 1 → 2 → 5 → 3 → **4**) |
| **Horas** | 60–90 h |
| **Condición de arranque** | **A decidir con datos de uso reales** |
| **Documento origen** | [docs/02](../docs/02-stack-tecnologico-y-plan-implementacion.md) §11 · [docs/05](../docs/05-presentacion-cliente.md) §11 |
| **Requisitos** | **RF-ID-10** (tarea 4.1, adelantada a la 2.2.0). Fuera de ella, ninguno codificado: el Anexo A del [documento 01](../docs/01-especificaciones-proyecto.md) describe el resto de la fase con conceptos, no con códigos `RF-*` |

---

## Por qué este fichero es corto

**Salvo la tarea 4.1, adelantada a la 2.2.0 por decisión del propietario (ver «Tareas adelantadas a una versión anterior»), no hay tareas que desarrollar, y no se inventan.** El documento 02 §11 dedica a la Fase 4 un único párrafo, sin tabla de tareas, sin horas por tarea, sin requisitos y sin agente asignado. El Anexo A del documento 01 la describe con cuatro conceptos, no con códigos de requisito. Es deliberado: el alcance de esta fase **se decide con datos de uso reales**, y detallarla hoy sería planificar sobre una plantilla en blanco.

Desarrollar aquí tareas paso a paso significaría inventarlas. Este fichero, por tanto, hace tres cosas y ninguna más: enumera las líneas de trabajo contempladas, registra qué se le anunció al cliente sobre cada una, y fija qué hay que decidir antes de convertir cualquiera de ellas en tareas ejecutables.

---

## Líneas de trabajo contempladas

Las cinco del documento 02 §11, contrastadas con lo que el documento 05 §11 anuncia al cliente:

| # | Línea de trabajo | Qué dice el doc 02 §11 | Qué se le ha dicho al cliente (doc 05) |
|---|---|---|---|
| 4.a | **Cuadrantes** y comparación entre planificado y realmente trabajado | Contemplada | §11: «Planificación de cuadrantes y comparación entre lo planificado y lo realmente trabajado». §8, tabla de expectativas: «Contemplado como evolución futura. **El modelo de datos deja la puerta abierta**» |
| 4.b | **Vacaciones y permisos con flujo de aprobación** | Contemplada | §11 la anuncia. §8 acota el presente con precisión: «Se registran las ausencias para no falsear los informes, **pero sin flujo de aprobación**» |
| 4.c | **Integración directa con sistemas de nómina concretos** | Contemplada | El doc 05 no la anuncia en §11. Lo que sí está comprometido y **es de la Fase 3** (tarea 3.9) es la *exportación configurable para nómina* (RF-IN-07) |
| 4.d | **Informes avanzados** | Contemplada | §11: «Informes avanzados» |
| 4.e | **Consolidación multi-centro para cadenas** | **Retirada** (ADR-040, 29 de agosto de 2026) | Una licencia es un hotel y cada hotel es una instalación. El doc 05 §11 ya no la anuncia y no queda promesa estructural que mantener |

### Dos matices que conviene no perder

**4.a lleva una promesa estructural, no funcional.** Al cliente no se le ha prometido la funcionalidad, pero sí que *el modelo de datos deja la puerta abierta*. Eso no es una tarea de la Fase 4: es una **restricción sobre las Fases 1 y 2**. El diseño del dominio (tarea 1.1) y el esquema (tarea 1.3) no deben cerrar la puerta a un cuadrante planificado, porque esa afirmación ya está escrita en un documento comercial. Si al llegar aquí hay que rehacer el esquema, la promesa era falsa.

**4.b tiene una frontera explícita que hoy se cumple y hay que seguir cumpliendo.** El registro de ausencias existe desde la tarea 3.10 (RF-GP-04), pero **sin flujo de aprobación**. Esa distinción está escrita en el documento 05 §8 y es lo que separa esta fase de lo ya entregado. Añadir un botón de "aprobar" en cualquier momento anterior desdibuja la frontera y convierte en incierto lo que hoy es una expectativa acotada.

---

## Qué hay que decidir antes de convertir esto en tareas

El documento 02 condiciona la fase a «datos de uso reales». Estos son los datos que la deciden, y todos salen de instrumentación que ya existe al llegar aquí:

| Pregunta a responder | Con qué dato se responde | De dónde sale |
|---|---|---|
| ¿Se usan los informes existentes lo suficiente como para que "avanzados" signifique algo? | Cuadro de impacto y adopción (RF-IN-08, tarea 3.13) | doc 02 §8.3 |
| ¿Cuánto esfuerzo manual consume hoy la salida a nómina? | Uso real de `GET /api/v1/reports/payroll-export` (tarea 3.9) | doc 01 Anexo B |
| ¿El registro de ausencias sin aprobación genera fricción real? | Correcciones con motivo `AJUSTE_ACORDADO_CON_RRHH` y `ALTA_RETROACTIVA`, más `manual_corrections_total{reason_code}` | doc 01 Anexo C · doc 02 §8.2 |
| ¿Qué pide de verdad el cliente frente a lo que suponemos? | La primera instalación en casa de un cliente. El doc 03 §7 lo pone entre lo que sigue necesitando una persona | doc 03 §7 |

---

## Cómo se convierte una línea en tareas ejecutables

Cuando se decida abordar cualquiera de las cinco, **no se improvisa**: se recorre el mismo camino que las fases anteriores, y el andamiaje ya existe para ello.

1. **Requisitos primero.** La línea se traduce a códigos `RF-*` / `RN-*` en el documento 01 §3 y §4, con criterios de aceptación en Gherkin (§11). Sin código de requisito no hay tarea: lo exige la Definición de Preparado del [doc 02](../docs/02-stack-tecnologico-y-plan-implementacion.md) §10.3.
2. **Anexo A actualizado.** El requisito nuevo se asigna a la Fase 4 en el Anexo A del documento 01, que es el alcance que consulta `qa:traceability --check` (doc 02 §9.6).
3. **Contrato antes que código.** Impacto en [docs/api/openapi.yaml](../docs/api/openapi.yaml) evaluado y, si aplica, actualizado. Es la fuente de verdad (ADR-013, regla dura del orden de autoridad).
4. **Diseño antes de implementación.** `arquitecto-dominio` decide módulo y capa. Los cuadrantes, en particular, son un concepto de dominio nuevo: no es obvio que vivan en `Attendance`, y esa decisión merece un ADR.
5. **Impacto legal y de privacidad evaluado**, con `/revision-cumplimiento`. Un flujo de aprobación de vacaciones introduce decisiones sobre personas: es tratamiento de datos con consecuencias.
6. **Tareas con la plantilla de este plan**, con su agente, sus horas y sus pruebas según la tabla del §9.5.
7. **Cierre de fase** como las demás (doc 03 §6.6).

---

## Tareas adelantadas a una versión anterior

Una línea de esta fase puede adelantarse cuando el propietario lo decide con un motivo que no puede esperar a los datos de uso. Se adelanta **por el mismo camino** de «Cómo se convierte una línea en tareas ejecutables»: requisito, Anexo A, contrato, diseño y tarea con su plantilla. Hoy hay una.

### Tarea 4.1 — Cuentas de gestión desde el panel

| | |
|---|---|
| **Horas** | 14–20 |
| **Agente / Skill** | `arquitecto-dominio` (contrato y diseño) → `backend-laravel` (`/endpoint-api`, `/crear-caso-de-uso`, `/migracion-segura`) + `frontend-panel` → `qa-testing` + `producto-licencia` → `revisor-codigo` + `seguridad-cumplimiento` (`/revision-cumplimiento`) |
| **Requisitos** | **RF-ID-10** (doc 01 §6, Anexo A fase 4, §11), con RF-ID-01, RF-ID-02, RS-03, RS-05, RS-06 y RL-16 |
| **Decisión** | [ADR-051](../docs/adr/ADR-051-cuentas-de-gestion-desde-el-panel-con-contrasenas-temporales.md) |
| **Precondiciones** | **2.1** (2FA y alcance por departamento), **3.8** (`identity:deactivate-user` e `identity:reset-password`, hallazgo H-03) y **5.5** (primer administrador) |
| **Bloquea a** | La guía de RRHH y `docs/cliente/configuracion.md` sin `psql` para las cuentas; los runbooks de alta y baja de cuentas |
| **Ejecución** | **Adelantada a la 2.2.0**, bloque 12c de `docs/verificacion/2.2.0-plan-correcciones.md` (decisión del propietario de 22-09-2026; alcance acotado el 24-09-2026) |

**Por qué se adelanta.** Hasta la 2.1, dar de baja la cuenta de gestión de quien deja el hotel exigía una consola en el servidor (doc 07, fila «El producto no tiene baja de cuentas de gestión»). Un cliente sin SSH podía mantener con acceso a la corrección de jornadas a un jefe de recepción que ya no trabajaba allí. Es un riesgo de seguridad sobre el registro legal, no una mejora de comodidad, y por eso no espera a los datos de uso de la fase.

**Objetivo.** Un `admin` lista, crea y da de baja cuentas de gestión y restablece su contraseña o su segundo factor desde el panel; cualquier cuenta cambia su propia contraseña. Sin consola y sin correo.

**Reglas duras aplicables.**

- **5**: la baja pone `is_active = false` y nunca borra. La cuenta sigue siendo la autora de lo que firmó y cuenta para la guarda del primer administrador.
- **6**: alta, baja, restablecimientos y cambio propio escriben en `audit_log` en la misma transacción (ADR-010), con el orden único de candados de ADR-051.
- **12**: ninguna contraseña viaja por correo. La temporal se muestra una vez y se entrega en mano.
- **16**: ningún acceso de soporte del fabricante gestiona cuentas, con ningún alcance.
- **18**: cada ruta con su policy (solo `admin`) y su prueba de `403` por cada rol, por soporte, por quiosco y por portal.
- **21**: ni nombre, ni correo, ni contraseña en logs técnicos ni en `audit_log`. Solo `user_uuid` y `actor_uuid`.

**Pasos.**

1. **Contrato**, hecho antes que el código (ADR-051). Incluye:
   - las rutas `GET`/`POST /api/v1/management-accounts`, `POST …/{uuid}/deactivate`, `…/password/reset`, `…/two-factor/reset` y `POST /api/v1/auth/password`;
   - los ámbitos `accounts:*` y `password:change`, y el problema `urn:kronoqr:problem:password-change-required`;
   - `ManagementUser.password_change_required`;
   - la reautenticación del actor (`actor_totp_code` o `actor_current_password`, con la respuesta `ActorReauthenticationFailed`);
   - el motivo obligatorio en `…/password/reset`;
   - `Cache-Control: no-store` en las respuestas que llevan la temporal;
   - `manager_user_uuid` en `Department` y `UpdateDepartmentRequest`.

   Los clientes TS ya están regenerados.
2. **Esquema** (`/migracion-segura`): `users.temporary_password_expires_at` (expansión, reversible) y el permiso `accounts:*` con su pivote de `admin`, con las cadenas escritas literalmente. `password:change` es un ámbito de token que no pertenece a ningún rol, igual que `2fa:pending`.
3. **Dominio de `Identity`**:
   - `PasswordStatus`, `TemporaryPasswordLifetime` (de 1 a 168 h) y `TemporaryPassword`;
   - `ManagementAccountDeactivationGuard`, que rechaza la baja de la propia cuenta y la de la última admin activa;
   - eventos `ManagementAccountCreated` y `ManagementPasswordChanged`.
4. **Puertos y casos de uso** (`/crear-caso-de-uso`). Generar, hashear y comparar contraseñas ocurre siempre **fuera** de `withChainLock`.
   - Puertos nuevos: `TemporaryPasswordGenerator` (ASCII, sin `l I O 0 1`, como mucho 72 bytes), `PasswordHasher` y `ManagementAccountDirectory`.
   - `CreateManagementAccountHandler` es nuevo, y `identity:create-user` pasa a usarlo y a mostrar la temporal una sola vez.
   - `DeactivateManagementAccountHandler` y `ResetManagementPasswordHandler` pasan a identificar por UUID. El segundo lleva motivo, que entra en `user.password_reset`.
   - `ResetTwoFactorHandler` rechaza la propia cuenta y las cuentas sin 2FA confirmado.
   - `ChangeOwnPasswordHandler` es nuevo:
     - lleva su propio contador, `password-change|<uuid>`;
     - al bloquear, revoca el token actual y escribe `auth.lockout_started`;
     - la escritura es condicionada: devuelve `409` si el hash cambió entretanto y `401` si el token ya no existe;
     - en la misma transacción, el token pasa de `password:change` a los ámbitos de su rol.
   - `ListManagementAccounts` y el comando `identity:list-users` son nuevos.
5. **Reautenticación del actor.** Alta, `password/reset` y `two-factor/reset` verifican `actor_totp_code` con el contador `2fa|<actor>` y la misma protección contra la reutilización de franja que `/auth/2fa/verify`. Si la cuenta que actúa no tiene 2FA confirmado, verifican `actor_current_password`. Un fallo devuelve `422` en el campo; con el bloqueo abierto, `429`.
6. **Sesión con contraseña temporal.** El acceso emite el token con el **único** ámbito `password:change`. Esto vale para `/auth/login`, `/auth/2fa/verify` y `/auth/2fa/confirm`, y los tres comprueban también la caducidad. Cuando falta `MissingAbility` con ese ámbito, la respuesta es `403` `password-change-required`, también en `/broadcasting/auth`.
7. **Orden de candados.** Siempre cadena → padrón de cuentas → fila `users`. Pasan a `withChainLock` `DeactivateManagementAccountHandler`, `ResetManagementPasswordHandler`, `ConfirmTwoFactorHandler` y `CreateFirstAdministratorHandler`.
8. **La baja retira los accesos de soporte** vigentes que concedió esa cuenta. `Identity` publica un evento y `Product` revoca cada acceso con su asiento. La baja también corta la suscripción Reverb.
9. **Responsable de departamento** (`Workforce`): `manager_user_uuid` en `PATCH /departments/{id}`.
   - Exige además `accounts:*`. Si `rrhh` envía el campo, recibe `403` y no cambia nada.
   - Responde `422` único si la cuenta no existe, está de baja o tiene otro rol que `responsable_departamento`.
   - Escribe un `role_assignment.changed` por cada cuenta afectada (la que sale y la que entra).
10. **HTTP** (`/endpoint-api`):
    - controladores finos, FormRequests y Resources que envuelven vistas;
    - `ManagementAccountPolicy`: solo `admin` y nunca soporte;
    - `POST /auth/password` con `403` explícito para todo actor de soporte;
    - zona `management` y sin puerta de licencia (ADR-019);
    - `new_password` y `current_password` en el `dontFlash`.
11. **Auditoría (`Compliance`)**: `user.created` y `user.password_changed` son nuevas en `AuditAction`. `RecordManagementAccountLifecycle` las sella junto con las existentes.
12. **Métrica y alerta** (`devops-observabilidad`):
    - `kronoqr_management_account_changes_total{action, role}`, sin `uuid`;
    - alerta al receptor de seguridad en **cada** `two_factor_reset` y en **cada** alta con rol `admin`;
    - runbook en `docs/runbooks/ataque-a-credenciales.md`.
13. **Panel**:
    - sección «Cuentas» solo para `admin`, en la feature nueva `frontend-admin/src/features/accounts/`;
    - listado, y alta con la temporal en un diálogo de una sola vez (patrón `PinRevealDialog`);
    - campo del código del autenticador en el alta y en los restablecimientos;
    - baja y restablecimientos con `ConfirmDialog` + `ChangePreview`;
    - cambio propio en el perfil, con redirección ante `password-change-required`;
    - elección del responsable en la pantalla de departamentos, solo para `admin`;
    - i18n ES/EN.
14. **Documentación del cliente**:
    - la guía de RRHH y `configuracion.md`, sin `psql` para las cuentas ni para el responsable de departamento;
    - runbooks de alta y baja de cuentas;
    - `IDENTITY_TEMPORARY_PASSWORD_TTL_HOURS`;
    - nota de la versión: el `admin` vuelve a entrar tras actualizar para recibir `accounts:*`.

**Artefactos.**

- `backend/app/Modules/Identity/Domain/`, `Application/` (`Port/`, `UseCase/`, `Query/`), `Infrastructure/` (`Adapter/`, `Persistence/`, `Console/`) y `Http/`.
- `backend/app/Modules/Workforce/` (el responsable de departamento) y `backend/app/Modules/Product/` (la retirada de los accesos de soporte).
- `backend/app/Modules/Compliance/Domain/ValueObject/AuditAction.php` y `Infrastructure/Listener/RecordManagementAccountLifecycle.php`.
- `backend/database/migrations/`: la columna de la contraseña temporal y el permiso `accounts:*`.
- `backend/config/identity.php` y `.env.example`.
- `infra/observability/`: la regla de alerta y su prueba.
- `frontend-admin/src/features/accounts/` (nuevo), la pantalla de departamentos, `navigation.ts`, `router/index.ts` y el perfil.
- `docs/api/openapi.yaml`, `docs/adr/ADR-051-…`, `docs/cliente/guia-rrhh.md`, `docs/cliente/en/hr-guide.md`, `docs/cliente/configuracion.md` y `docs/runbooks/`.

**Pruebas exigidas.** Según §9.5: una regla de negocio exige **Unitaria**; un esquema, **Integración**; un endpoint, **Feature + Contrato** y **autorización negativa por cada rol**; un recorrido de usuario, **E2E + axe**.

- **Unitaria:** `PasswordStatus` en la frontera exacta de la caducidad, con `Clock` fijo → `->group('RF-ID-10')`.
- **Unitaria:** `ManagementAccountDeactivationGuard` con cuatro casos → `->group('RF-ID-10', 'RS-05')`:
  - la propia cuenta;
  - la última admin activa;
  - una admin que tiene otra admin activa;
  - el actor nulo de la consola.
- **Unitaria:** el generador → `->group('RF-ID-10', 'RF-ID-01')`:
  - la longitud es max(20, mínimo configurado) y nunca pasa de 72 bytes;
  - incluye las cuatro clases y ningún carácter ambiguo;
  - cumple la política.
- **Unitaria:** los handlers con dobles → `->group('RF-ID-10')`:
  - no hashean ni comparan dentro del candado;
  - los desenlaces rechazados no escriben ni publican;
  - un doble de `SerializedLedgerWrite` registra el orden de los candados.
- **Integración:** las migraciones son reversibles y las tres copias de `accounts:*` (enum, contrato y migración) coinciden → `->group('RF-ID-10', 'RS-04')`.
- **Integración:** dos conexiones dan de baja a la vez a las dos únicas admin activas, y queda exactamente una → `->group('RF-ID-10', 'RS-05')`.
- **Integración:** `ConfirmTwoFactorHandler` y `two-factor/reset` concurrentes sobre la misma cuenta, sin `deadlock_detected` → `->group('RF-ID-10')`.
- **Integración:** los asientos no llevan nombre, correo ni contraseña, y `user.password_reset` lleva el motivo → `->group('RS-05')`.
- **Recorrido de `Router::getRoutes()`** (modelo `RouteRateLimitZonesTest`): toda ruta que exige un ámbito rechaza un token `password:change` con `403` `password-change-required`, incluida `/broadcasting/auth`. Solo `POST /auth/password`, `GET /auth/me` y `POST /auth/logout` lo admiten → `->group('RF-ID-10', 'RS-06')`.
- **Feature + Contrato** de las seis rutas y de `PATCH /departments/{id}` → `->group('RF-ID-10', 'RS-03')`:
  - `404` idéntico para «no existe» y «ya de baja», sin asiento;
  - `409` por la propia cuenta, por la última admin, por un correo ya usado y por un 2FA no confirmado;
  - `Cache-Control: no-store` en las respuestas con temporal.
- **Feature: reautenticación del actor** en el alta y en los dos restablecimientos → `->group('RF-ID-10', 'RS-06')`:
  - sin código, con un código erróneo, con un código ya usado en su franja, o con contraseña desde una cuenta con 2FA: `422`;
  - tras el umbral: `429` con `Retry-After`.
- **Feature: recorrido completo de la temporal** → `->group('RF-ID-10', 'RS-06')`:
  - la secuencia es alta, `202`, alta del TOTP, sesión `password:change`, `403` en `/employees`, `POST /auth/password` y `200` en `/employees` con el mismo token;
  - una temporal caducada da el mismo `401` que una contraseña errónea, en `login`, `2fa/verify` y `2fa/confirm`.
- **Feature: cambio propio** → `->group('RF-ID-10', 'RF-ID-01', 'RS-03')`:
  - con la contraseña actual errónea, `422`;
  - con el contador `password-change|<uuid>` agotado, `429`, el token revocado y `auth.lockout_started`;
  - escritura condicionada: `409` y `401`;
  - más de 72 bytes, `422`.
- **Feature: baja** → `->group('RF-ID-10', 'RS-05')`:
  - los tokens anteriores dan `401`;
  - la suscripción Reverb se corta;
  - se revocan los accesos de soporte que concedió esa cuenta.
- **Feature: responsable de departamento** → `->group('RF-ID-10', 'RF-ID-03')`:
  - `manager_user_uuid` enviado por `rrhh` da `403` y no cambia nada;
  - el mismo `422` si la cuenta no existe, está de baja o tiene otro rol;
  - quedan dos asientos `role_assignment.changed`;
  - la cuenta desplazada pierde el alcance en su siguiente petición.
- **Autorización negativa en cada ruta de `accounts:*`** → `->group('RF-ID-10', 'RF-ID-02')`:
  - `rrhh`, `responsable_departamento` y `auditor` reciben `403`;
  - el soporte con **cada** alcance, incluido `configuration`, recibe `403`;
  - los tokens de quiosco y de portal reciben `403`;
  - sin token y con `2fa:pending` la respuesta es `401`;
  - `SupportScopeRoutesTest` no incluye las rutas nuevas.
- **Autorización negativa en `POST /auth/password`** → `->group('RF-ID-10', 'RF-ID-02')`:
  - el soporte con cada alcance recibe `403`;
  - los tokens de quiosco y de portal se rechazan;
  - cada rol de gestión recibe `204` sobre sí mismo.
- **Alerta:** `promtool test rules` comprueba que dispara en cada `two_factor_reset` y en cada alta `admin`, y que la métrica no lleva etiqueta de `uuid` → `->group('RF-ID-10')`.
- **E2E + axe** → `tag: ['@RF-ID-10']`:
  - alta con el código del autenticador y el diálogo de una sola vez;
  - baja con confirmación;
  - cambio propio forzado;
  - asignación de responsable.

**Verificación.**

```bash
php artisan test tests/Unit/Identity tests/Feature/Identity tests/Integration/Identity tests/Feature/Workforce tests/Contract
php artisan test --group=RF-ID-10
make e2e -- --grep @RF-ID-10
php artisan docs:consistency --check
```

Esperado:

- Una cuenta dada de baja deja de entrar en la petición siguiente y sigue en la lista.
- La instalación no puede quedarse sin `admin` activa, ni por el panel ni por la consola.
- Una sesión con contraseña temporal no alcanza nada más que el cambio de contraseña.
- Una sesión de `admin` sin su autenticador no crea cuentas ni rehace credenciales.
- Ninguna contraseña aparece en `audit_log`, en un log ni en una respuesta distinta de la que la emite.

**Terminado cuando** (§10.3) se cumple todo esto:

- Deptrac en verde y PHPStan 9 limpio.
- Pruebas unitarias, de integración, feature, contrato, autorización negativa (por rol, por soporte con cada alcance, por quiosco y por portal) y E2E.
- La prueba de `Router::getRoutes()` sobre `password:change` está en verde.
- La trazabilidad de RF-ID-10 está en verde.
- **La métrica `kronoqr_management_account_changes_total{action, role}`, la alerta al receptor de seguridad y su runbook existen y están probados.** Es condición de cierre del bloque 12c (ADR-051), no una mejora posterior.
- Contrato actualizado, migración reversible, auditoría escrita, instrumentación sin PII y textos en ES y EN.
- Las filas afectadas del doc 07 están actualizadas con la evidencia final.

---

## Reglas duras que esta fase no puede relajar

Ninguna de las 21 reglas de [CLAUDE.md](../CLAUDE.md) se suspende porque el trabajo sea "evolución". Tres son especialmente frágiles aquí:

- **Regla 13 — nada específico de un cliente en el código.** Esta fase es la que más presión va a recibir en contra: la integración con "un sistema de nómina concreto" (4.c) es exactamente la petición que tienta a meter un cliente en el repositorio. Sale por configuración o por un adaptador genérico, nunca por una rama.
- **Regla 5 — nada se borra ni se sobrescribe.** Un flujo de aprobación (4.b) tiene estados que cambian. Cada cambio es una versión nueva con autor, momento y motivo.
- **Regla 4 y ADR-006 — los turnos no se parten a medianoche.** La comparación entre planificado y trabajado (4.a) es un caldo de cultivo para prorrateos mal hechos. El prorrateo por día natural vive en `Reporting` y es explícito, nunca implícito.

---

## Aviso sobre las horas

Las **60–90 h** del documento 02 son una banda de referencia para cinco líneas de trabajo sin desglosar, no una estimación de tareas. Con el criterio del §11.0 —horas de una persona desarrollando con el andamiaje de agentes, revisión humana incluida—, y sin alcance definido, esa cifra sirve para reservar capacidad, no para comprometerse. Se recalibra cuando la fase tenga requisitos, y el §11.0 recomienda contrastar la estimación con los datos reales de la Fase 1, que es la primera oportunidad de medir.

---

## Estado de la fase en el resumen de esfuerzo

Del [doc 02](../docs/02-stack-tecnologico-y-plan-implementacion.md) §11.1:

| Alcance | Fases | Horas | ¿Vendible? |
|---|---|---|---|
| Producto vendible y operable | 0 + 1 + 2 + 5 + 3 | 420–554 | ✅ Con observabilidad completa |
| **Con evolución** | **Todas** | **480–644** | ✅ |

El producto **ya es vendible y operable sin esta fase**. Eso es lo que la convierte en evolución y no en alcance: es la única fase del plan cuyo recorte no aparece en la tabla de riesgos del §11.2, porque no hay riesgo en no hacerla todavía.

---

← Anterior: [Fase 3 — Operación y refuerzo](06-fase-3-operacion-y-refuerzo.md) · Siguiente: [Entrega, despliegue y actualización](08-entrega-despliegue-y-actualizacion.md) · [Índice](README.md)
