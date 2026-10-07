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

1. **Contrato** (hecho en `36ae8677`): `GET`/`POST /api/v1/management-accounts`, `POST …/{uuid}/deactivate`, `…/password/reset`, `…/two-factor/reset` y `POST /api/v1/auth/password`; ámbito `accounts:*`; problema `urn:kronoqr:problem:password-change-required`; `ManagementUser.password_change_required`. Clientes TS regenerados.
2. **Esquema** (`/migracion-segura`): `users.temporary_password_expires_at` (expansión, reversible) y el permiso `accounts:*` con su pivote de `admin`, con las cadenas escritas literalmente.
3. **Dominio de `Identity`**: `PasswordStatus`, `TemporaryPasswordLifetime`, `TemporaryPassword` y `ManagementAccountDeactivationGuard` (propia cuenta, última admin activa); eventos `ManagementAccountCreated` y `ManagementPasswordChanged`.
4. **Puertos y casos de uso** (`/crear-caso-de-uso`). La contraseña se genera y se hashea **fuera** de `withChainLock`.
   - Puertos nuevos: `TemporaryPasswordGenerator`, `PasswordHasher` y `ManagementAccountDirectory`.
   - `CreateManagementAccountHandler`, nuevo; `identity:create-user` pasa a usarlo y muestra la temporal una vez.
   - `DeactivateManagementAccountHandler` y `ResetManagementPasswordHandler` pasan a identificar por UUID.
   - `ResetTwoFactorHandler` se ajusta.
   - `ChangeOwnPasswordHandler` y `ListManagementAccounts`, nuevos, más el comando `identity:list-users`.
5. **Orden de candados**: cadena → candado del padrón de cuentas → fila `users`. `ConfirmTwoFactorHandler` y `CreateFirstAdministratorHandler` pasan también a `withChainLock`.
6. **HTTP** (`/endpoint-api`):
   - controladores finos, FormRequests y Resources que envuelven vistas, nunca el modelo;
   - `ManagementAccountPolicy`: `admin` y nunca soporte;
   - middleware `RequireOwnPassword` con sus tres exenciones;
   - zona `management` y sin puerta de licencia (ADR-019).
7. **Auditoría (`Compliance`)**: `user.created` y `user.password_changed`, nuevos en `AuditAction`, sellados por `RecordManagementAccountLifecycle`.
8. **Panel**:
   - sección «Cuentas» solo para `admin` (`navigation.ts`, `router/index.ts`), en la feature nueva `frontend-admin/src/features/accounts/`;
   - listado y alta, con la temporal en un diálogo de una sola vez (patrón `PinRevealDialog`);
   - baja y restablecimientos con `ConfirmDialog` + `ChangePreview`;
   - cambio de contraseña propio en el perfil, al que se redirige ante `password-change-required`;
   - i18n ES/EN.
9. **Documentación del cliente**: guía de RRHH y `configuracion.md` sin `psql` para las cuentas, runbooks de alta y baja de cuentas y la variable `IDENTITY_TEMPORARY_PASSWORD_TTL_HOURS`. En las notas de la versión: el `admin` vuelve a entrar tras actualizar para recibir `accounts:*`.

**Artefactos.**

- `backend/app/Modules/Identity/Domain/`, `Application/` (`Port/`, `UseCase/`, `Query/`), `Infrastructure/` (`Adapter/`, `Persistence/`, `Console/`) y `Http/`.
- `backend/app/Modules/Compliance/Domain/ValueObject/AuditAction.php` y `Infrastructure/Listener/RecordManagementAccountLifecycle.php`.
- `backend/database/migrations/`: columna de la contraseña temporal y permiso `accounts:*`.
- `backend/config/identity.php` y `.env.example`.
- `frontend-admin/src/features/accounts/` (nuevo), `navigation.ts`, `router/index.ts` y el perfil.
- `docs/api/openapi.yaml`, `docs/adr/ADR-051-…`, `docs/cliente/guia-rrhh.md`, `docs/cliente/en/hr-guide.md`, `docs/cliente/configuracion.md` y `docs/runbooks/`.

**Pruebas exigidas.** Por §9.5: regla de negocio → **Unitaria**; esquema → **Integración**; endpoints → **Feature + Contrato** y **autorización negativa por cada rol**; recorrido de usuario → **E2E + axe**.

- Unitaria: `PasswordStatus` en la frontera exacta de la caducidad, con `Clock` fijo → `->group('RF-ID-10')`.
- Unitaria: `ManagementAccountDeactivationGuard` con la propia cuenta, la última admin activa, una admin con otra activa y el actor nulo de la consola → `->group('RF-ID-10', 'RS-05')`.
- Unitaria: el generador produce longitud max(20, mínimo configurado), con las cuatro clases y sin ningún carácter ambiguo, y cumple la política → `->group('RF-ID-10', 'RF-ID-01')`.
- Unitaria: los handlers no hashean dentro del candado, y los desenlaces rechazados no escriben ni publican → `->group('RF-ID-10')`.
- Integración: las migraciones son reversibles y las tres copias de `accounts:*` (enum, contrato y migración) coinciden → `->group('RF-ID-10', 'RS-04')`.
- Integración: dos conexiones dan de baja a la vez a las dos únicas admin activas y queda exactamente una → `->group('RF-ID-10', 'RS-05')`.
- Integración: los asientos no llevan nombre, correo ni contraseña → `->group('RS-05')`.
- Feature + Contrato de las seis rutas → `->group('RF-ID-10', 'RS-03')`. Incluye:
  - el `404` idéntico para «no existe» y «ya de baja», sin asiento;
  - el `409` por la propia cuenta, la última admin, un correo ya usado y el 2FA no confirmado.
- Feature: recorrido completo de la temporal: alta, `202`, alta del TOTP, `403 password-change-required`, `POST /auth/password` y acceso → `->group('RF-ID-10', 'RS-06')`.
- Feature: la temporal caducada responde igual que una contraseña errónea; en el cambio propio, una contraseña actual errónea da `422`, y `429` tras el bloqueo → `->group('RF-ID-10', 'RS-03', 'RF-ID-01')`.
- Autorización negativa en cada ruta de `accounts:*` → `->group('RF-ID-10', 'RF-ID-02')`:
  - `rrhh`, `responsable_departamento` y `auditor` → `403`;
  - soporte con cada uno de sus alcances → `403`;
  - tokens de quiosco y de portal → `403`;
  - sin token y con `2fa:pending` → `401`.
- E2E + axe: alta con el diálogo de una sola vez, baja con confirmación y cambio propio forzado → `tag: ['@RF-ID-10']`.

**Verificación.**

```bash
php artisan test tests/Unit/Identity tests/Feature/Identity tests/Integration/Identity tests/Contract
php artisan test --group=RF-ID-10
make e2e -- --grep @RF-ID-10
php artisan docs:consistency --check
```

Esperado:
- una cuenta dada de baja deja de entrar en la petición siguiente y sigue en la lista;
- la instalación no puede quedarse sin `admin` activa, ni por el panel ni por la consola;
- ninguna contraseña aparece en `audit_log`, en un log ni en otra respuesta que la que la emite.

**Terminado cuando** (§10.3) se cumple todo esto:
- Deptrac en verde y PHPStan 9 limpio;
- pruebas unitarias, de integración, feature, contrato, autorización negativa y E2E;
- trazabilidad de RF-ID-10 en verde;
- contrato actualizado, migración reversible, auditoría escrita, instrumentación sin PII y textos en ES y EN;
- ADR-051 aceptado tras la revisión de `seguridad-cumplimiento`, con las filas afectadas del doc 07 actualizadas.

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
