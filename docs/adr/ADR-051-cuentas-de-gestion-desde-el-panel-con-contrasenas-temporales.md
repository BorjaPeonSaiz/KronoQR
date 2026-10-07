# ADR-051 — Las cuentas de gestión se administran desde el panel, con contraseñas temporales y sin quedarse nunca sin administrador

| Campo | Valor |
|---|---|
| **Estado** | Aceptado. Revisión de `seguridad-cumplimiento` del 07-10-2026: aprobado con cambios (los diez del informe de revisión del diseño del bloque 12c), incorporados |
| **Fecha** | 7 de octubre de 2026 |
| **Decide** | `arquitecto-dominio` (bloque 12c de la 2.2.0, sobre la decisión de producto del propietario del 22-09-2026) · `seguridad-cumplimiento` (revisión) |
| **Afecta a** | Precisa [ADR-039](ADR-039-que-hechos-de-autenticacion-dejan-asiento.md): un hecho nuevo de autenticación, el fallo de la contraseña actual en el cambio propio, que no deja asiento salvo cuando abre el bloqueo · Respeta [ADR-010](ADR-010-auditoria-solo-append-encadenada.md), [ADR-012](ADR-012-api-versionada-en-la-ruta.md), [ADR-015](ADR-015-portal-con-codigo-y-pin.md), [ADR-016](ADR-016-producto-licenciado-on-premise.md), [ADR-019](ADR-019-la-licencia-nunca-bloquea-el-registro.md), [ADR-020](ADR-020-soporte-con-paquete-de-diagnostico.md) y [ADR-050](ADR-050-portal-accesible-desde-internet.md) · `docs/01`: RF-ID-10 (nuevo), RF-ID-01, RF-ID-02, RF-ID-03, Anexo B y §8.1 · `docs/api/openapi.yaml` · `docs/02` §7.3, nota 7 · `plan implementacion/07-fase-4-evolucion.md`, tarea 4.1 |
| **Requisitos** | RF-ID-10, RF-ID-01, RF-ID-02, RF-ID-03, RS-03, RS-05, RS-06, RL-16 y reglas duras 5, 6, 12, 16, 18 y 21 |
| **Hallazgos** | H-03 de la revisión interna ASVS de 2026-09 (doc 07, fila «El producto no tiene baja de cuentas de gestión») · revisión del diseño del bloque 12c: A1 a A3, M1 a M6 y B1 a B7 |

## Contexto

Hasta la 2.1, el ciclo de vida de una cuenta de gestión solo existía en consola:

- `identity:create-user`;
- `identity:deactivate-user` e `identity:reset-password`, ambos desde la 3.8;
- `identity:2fa-reset`.

La razón estaba escrita en los docblocks de los handlers y en la nota del Anexo B del doc 01: un «dale de baja» o un
«quítale el segundo factor» por API sería, en manos de un `admin` comprometido, la vía más cómoda para preparar el
acceso a la cuenta de otro.

**El coste real fue el contrario.** Un hotel no tiene por qué tener SSH ni a nadie que sepa usarlo (RF-PD-06). Mientras
la baja exigiera una consola, el jefe de recepción que se iba conservaba una cuenta válida, con acceso a la corrección
de jornadas, hasta que alguien abría un caso con el fabricante. El propietario decidió el 22-09-2026 que habría pantalla
de cuentas de gestión y el 24-09-2026 acotó su alcance.

Llevar esto a HTTP hace visibles problemas que en la consola no se notaban:

1. **Una contraseña fijada por otra persona es una credencial compartida.** `identity:reset-password` la genera y la
   enseña una vez, pero nada obliga a cambiarla ni la hace caducar.
2. **Desde el panel se puede dejar la instalación sin administrador.** Basta con que alguien se dé de baja a sí mismo o
   dé de baja a la última `admin` activa. Las dos cosas solo se arreglan por SSH.
3. **`settings:*` lo lleva también el soporte** con alcance `configuration` (`SupportScope`). Crear una cuenta `admin`
   es exactamente cómo un acceso temporal de soporte se vuelve permanente.
4. **Una sesión de `admin` robada se convierte en persistencia.** Basta con dejarse una cuenta propia o rehacer las
   credenciales de otra (revisión del diseño, A2; ASVS V3.7.1).
5. **Los handlers de la consola toman los candados al revés** (fila → cadena). Por HTTP y con concurrencia, eso es un
   abrazo mortal con cualquier camino que los tome en el orden contrario (ADR-010).

## Decisión

### 1. Seis rutas, por `uuid`, bajo `/management-accounts`

Son dos grupos:

- **Para `admin`:**
  - `GET` y `POST /api/v1/management-accounts`;
  - `POST …/{uuid}/deactivate`;
  - `POST …/{uuid}/password/reset`;
  - `POST …/{uuid}/two-factor/reset`.
- **Para cualquier rol de gestión:** `POST /api/v1/auth/password`, el cambio de la contraseña **propia**.

El nombre `management-accounts` es el término del lenguaje ubicuo y el que ya usa el código (`ManagementAccount*`).

Las cuentas se identifican **por `uuid`, nunca por el correo**: el correo acabaría en el historial del navegador y en
los registros del servidor web.

**Nada se borra** (regla dura 5):

- La baja pone `is_active = false`. La cuenta sigue siendo la autora de lo que firmó y sigue contando para la guarda de
  `POST /setup/administrator`.
- **No hay reactivación.** Quien vuelve recibe una cuenta nueva.

La baja **responde lo mismo, `404`, a «no existe» y a «ya estaba de baja»**, sin asiento (RS-03). Además:

- Revoca todos los tokens de la cuenta: su petición siguiente recibe `401`, y una suscripción nueva o una reconexión al canal en tiempo real fallan. Una conexión WebSocket ya abierta **no** se corta en el acto (residuo 6).
- **Retira los accesos de soporte vigentes que esa cuenta concedió.** `Identity` publica la baja, `Product` revoca cada
  concesión y cada revocación deja su propio asiento. Un acceso temporal no sobrevive a quien respondía de él, aunque
  su tope sea `PRODUCT_SUPPORT_GRANT_MAX_HOURS`.

**Sin puerta de licencia** (ADR-019). Dar de baja la cuenta de quien se fue es una medida de seguridad, no una
funcionalidad accesoria.

### 2. Ámbito propio `accounts:*`, solo de `admin`, y una policy que rechaza a todo actor de soporte

Es la excepción explícita a las notas 4 a 6 del doc 02 §7.3 (nota 7). **Dos controles, no uno** (regla dura 18):

- ningún alcance de soporte concede `accounts:*`;
- además, `ManagementAccountPolicy` rechaza a todo actor de soporte.

`POST /auth/password` no lleva ámbito, pero **también responde `403` a cualquier actor de soporte**, con su prueba por
alcance. Quien firma un acceso de soporte no es una cuenta de gestión y no tiene contraseña que cambiar.

**Asignar responsable a un departamento** (`manager_user_uuid` en `PATCH /departments/{id}`) exige también
`accounts:*`. Elegir responsable es elegir entre cuentas de gestión y conceder alcance sobre personas.

Las abilities se fijan al emitir el token. Una sesión abierta antes de actualizar no tiene `accounts:*` hasta que el
`admin` vuelve a entrar.

### 3. Toda contraseña fijada por otra persona es temporal, y la sesión que abre falla cerrada

**Cómo se genera y se entrega:**

- **La genera el servidor.** Su longitud es la mayor entre 20 y `IDENTITY_PASSWORD_MIN_LENGTH`, siempre ASCII y nunca
  más de 72 bytes. Lleva las cuatro clases de la política de RF-ID-01, sin `l I O 0 1`, y sale de un generador
  criptográfico.
- **Se muestra una sola vez**, en la respuesta que la emite (`TemporaryPasswordIssued`, con `Cache-Control: no-store`).
- **Se entrega en mano** (regla dura 12). No va en `audit_log`, ni en un log, ni en ninguna otra respuesta.

**Caduca** a las `IDENTITY_TEMPORARY_PASSWORD_TTL_HOURS` (72 de serie, **de 1 a 168**). Caducada, el acceso responde lo
mismo que una contraseña incorrecta y en el mismo tiempo (RS-03). La caducidad se comprueba en todos los caminos que
emiten sesión, no solo en el acceso: `/auth/login`, `/auth/2fa/verify`, `/auth/2fa/confirm` y el cambio propio.

**La sesión falla cerrada.** Una sesión abierta con una contraseña temporal emite un token con el **único** ámbito
`password:change`, el mismo patrón que `2fa:pending`. Con ese token:

- Solo alcanza `POST /auth/password`, `GET /auth/me` y `POST /auth/logout`.
- Toda ruta que exige un ámbito lo rechaza sin comprobación nueva en cada sitio, incluida la autorización del canal en
  tiempo real (`/broadcasting/auth`).
- El rechazo se presenta como `403` `urn:kronoqr:problem:password-change-required`.
- Al cambiar la contraseña, el mismo token recibe en la misma transacción los ámbitos de su rol.

**Por qué un ámbito y no un middleware de grupo.** Un middleware falla abierto en toda ruta que alguien olvide meter en
el grupo, y la revisión contó unas cuarenta (A1). La prueba recorre `Router::getRoutes()`, como `RouteRateLimitZonesTest`.

**El cambio propio:**

- **Exige la contraseña actual.** Si no coincide responde `422`, nunca `401`.
- **Tiene un contador propio por cuenta**, `password-change|<uuid>`, distinto del de `/auth/login`, que se lleva por
  correo y origen.
- **Al abrir el bloqueo**, la petición responde `429` con `Retry-After`, **revoca el token con el que se hizo** y deja
  `auth.lockout_started`. Quien prueba contraseñas con una sesión robada la pierde. Los fallos sueltos solo van al log
  técnico (ADR-039).
- **La nueva** cumple la política de RF-ID-01, no pasa de 72 bytes y no repite la actual.
- **La escritura es condicionada** (M1). Dentro del candado se comprueba que el hash leído al comparar sigue vigente y
  que el token de la sesión sigue existiendo. Si el hash cambió, `409`; si el token desapareció, `401`.
- **Cierra las demás sesiones de la cuenta** y conserva la actual.

**El 2FA no se genera en el alta.** Lo da de alta su titular en su primer acceso.

**Una sola manera de fijar la contraseña de otra persona.** `identity:create-user` deja de pedir la contraseña y entrega
una temporal. El asistente (`POST /setup/administrator`) sigue fijando una contraseña **propia**, porque quien la teclea
es su titular.

### 4. La instalación nunca se queda sin administrador, y nadie se da de baja a sí mismo

Son invariantes del conjunto de cuentas. Viven en el dominio (`ManagementAccountDeactivationGuard`) y se aplican
**también en la consola**. Todos estos casos responden `409`:

- la baja de la propia cuenta;
- la baja de la última `admin` activa, desde el panel o desde `identity:deactivate-user`;
- el restablecimiento de la contraseña o del 2FA de la propia cuenta;
- el restablecimiento del 2FA de una cuenta sin 2FA confirmado.

Las dos primeras se comprueban **bajo el candado del padrón de cuentas**. Es el mismo `pg_advisory_xact_lock` del primer
administrador, movido a una constante compartida de `Identity`.

### 5. El alta no asigna departamentos; el departamento asigna su responsable

Un `responsable_departamento` nace con `scope.kind: departments` y la lista vacía. El responsable es un atributo **del
departamento** (`departments.manager_user_id`, módulo `Workforce`) y se fija con `manager_user_uuid` en
`PATCH /departments/{id}`:

- **Solo `admin`**, porque exige `accounts:*` además de `employees:*`.
- **Deja un asiento `role_assignment.changed` por cada cuenta afectada**: la que deja de ser responsable y la que pasa
  a serlo, con el `department_id` y si se concede o se retira.
- **Solo admite una cuenta activa con ese rol.** Si no lo es, responde `422` en el campo, con un solo mensaje para las
  tres causas (no existe, está de baja, tiene otro rol). Es `422` y no `404` porque el recurso de la ruta existe.

Así `Identity` no escribe en `Workforce`, y el desplazamiento del responsable anterior tiene su propio asiento.

### 6. Un único orden de candados para todo lo que audita una cuenta

**El orden es: cadena de `audit_log` (`withChainLock`, ADR-010) → candado del padrón de cuentas (solo la baja y el primer
administrador) → fila de `users` (`FOR NO KEY UPDATE`, que no choca con el `FOR KEY SHARE` de los escritores con clave ajena a `users`; ADR-046 §1.1 punto 4) → `personal_access_tokens` → asiento (reentrante).**

**Fuera de todo candado** van generar la contraseña, hashearla, comparar un hash y limpiar contadores.

Hay que corregir los caminos que hoy toman los candados al revés:

- `DeactivateManagementAccountHandler`;
- `ResetManagementPasswordHandler`;
- `ConfirmTwoFactorHandler`. Con este el ciclo es **real** con `two-factor/reset`.

`CreateFirstAdministratorHandler` se alinea también.

### 7. Reautenticación del `admin` que actúa

`POST /management-accounts`, `…/password/reset` y `…/two-factor/reset` exigen en la misma petición una de estas dos
pruebas de quien actúa:

- el código vigente de su autenticador, `actor_totp_code`;
- si su cuenta no tiene 2FA confirmado, su contraseña, `actor_current_password`.

El código comparte el contador `2fa|<actor>` y la protección contra la reutilización de su franja con
`/auth/2fa/verify`. Un fallo responde `422` en el campo; con el bloqueo abierto, `429` con `Retry-After`.

Una sesión robada, sin el teléfono de quien la abrió, ya no sirve para dejarse una cuenta propia ni para rehacer las
credenciales de otra (A2).

**Motivo obligatorio.** `…/password/reset` exige motivo, igual que la baja y el 2FA, y el motivo entra en el payload de
`user.password_reset`. Ningún texto libre de estas rutas debe llevar datos de salud ni juicios de valor.

### Auditoría, métrica y alerta

| Hecho | Acción | Novedad |
|---|---|---|
| Alta | `user.created` + `role_assignment.changed` | `user.created` es nueva |
| Baja | `user.deactivated` (actor, motivo) + `support_grant.revoked` por cada concesión retirada | la retirada de concesiones es nueva |
| Restablecer contraseña | `user.password_reset` (actor, **motivo**) | el motivo es nuevo |
| Restablecer 2FA | `auth.two_factor_reset` (actor, motivo) | ya existía |
| Cambio propio | `user.password_changed` | nueva |
| Bloqueo del cambio propio | `auth.lockout_started` | canal nuevo del hecho existente |
| Responsable de departamento | `role_assignment.changed` × 2 | por cuenta afectada |

Todos son síncronos y van en la misma transacción que el hecho (ADR-010: un fallo al auditar bloquea la acción).
Llevan los `uuid` de la cuenta y del actor; nunca nombre, correo ni nada derivado de una contraseña (regla dura 21).

**Métrica `kronoqr_management_account_changes_total{action, role}`, sin `uuid`.** `action` es uno de `created`,
`deactivated`, `password_reset`, `two_factor_reset` o `password_changed`, y `role` es el rol de la cuenta afectada.

**Alerta al receptor de seguridad** en **cada** `two_factor_reset` y en **cada alta con rol `admin`**, con runbook en
`docs/runbooks/ataque-a-credenciales.md`.

**La métrica, la alerta y el runbook son condición de cierre del bloque 12c**, no una mejora posterior. Le tocan a
`devops-observabilidad`.

## Alternativas descartadas

| Alternativa | Por qué se descarta |
|---|---|
| **Seguir solo por consola** | Es el estado que produjo H-03, y RF-PD-06 dice por escrito que la consola no puede hacer falta |
| **Gestionar cuentas bajo `settings:*`** | Deja un solo control frente al soporte con alcance `configuration` |
| **Marcar la contraseña temporal con un middleware de grupo** | Falla abierto en cada ruta que se olvide meter en el grupo (A1). Un ámbito único falla cerrado |
| **Contraseña generada sin caducidad ni cambio obligatorio** | Es una credencial compartida a largo plazo (ASVS 2.3.1) |
| **Invitación o enlace de activación por correo** | Lo prohíben la regla dura 12, ADR-015 y ADR-016 |
| **Que la sesión del `admin` baste para crear cuentas y rehacer credenciales** | Una sesión robada se convierte en persistencia (A2) |
| **Contar el fallo del cambio propio en el contador de `/auth/login`** | Ese contador se lleva por correo y origen: una sesión robada prueba contraseñas sin acercarse a él (M2) |
| **Asignar departamentos en el alta** | `Identity` escribiría en `Workforce` y desplazaría al responsable anterior sin dejar su asiento |
| **Permitir la baja de la última `admin` con un aviso** | El aviso se acepta con un clic, y la salida es SSH |
| **Restablecer contraseña y 2FA en una sola acción** | Convierte «dame la cuenta entera de esta persona» en un solo clic |
| **Prohibir el restablecimiento del 2FA de otra `admin`, o dejarlo solo en consola** | La revisión no lo recomienda: el teléfono perdido de la única otra `admin` volvería a exigir SSH |

## Residuos y riesgos que se aceptan (doc 07 §6)

1. **Suplantación por un `admin` con la contraseña y el TOTP comprometidos.** Con las dos cosas, el atacante supera la
   reautenticación del §7. Puede restablecer la contraseña y el 2FA de otra cuenta, entrar como esa persona y firmar
   correcciones con su nombre: repudio sobre el registro legal (`T1098`). Un `admin` ya podía crear una cuenta con el
   rol que quisiera, pero a su propio nombre; lo nuevo es hacerlo **bajo la identidad de otro**. Controles:
   - dos acciones con dos asientos, cada uno con actor y motivo;
   - nunca sobre la propia cuenta;
   - las sesiones de la persona suplantada caen, así que lo nota en su siguiente petición;
   - cada `two_factor_reset` avisa al receptor de seguridad;
   - la consola sigue siendo la vía de recuperación.

   **Riesgo residual:** queda detectable y no repudiable, pero no impedido.

2. **Ventana de auto-alta del 2FA tras un restablecimiento.** Quien conozca la contraseña de esa cuenta puede activar su
   propio TOTP antes que el titular. En las altas, la ventana la acota la caducidad de la temporal; tras un
   `two-factor/reset` con la contraseña de siempre, no tiene caducidad. Controles:
   - `auth.two_factor_enabled` deja momento e IP;
   - la alerta del restablecimiento;
   - el reto dura minutos.

3. **La temporal pasa por la pantalla del `admin`**, que puede anotarla. Controles: el cambio obligatorio en el primer
   acceso, la caducidad y que solo se muestra una vez.

4. **Las cuentas dadas de baja conservan nombre, correo y los motivos libres** mientras sigan siendo actores de asientos
   y correcciones, porque los necesita la prueba legal. Seudonimizarlas cuando ningún registro vivo las cite es una
   tarea de retención pendiente (M6), no de este bloque.

5. **Las sesiones abiertas antes de actualizar no ven «Cuentas»** hasta que el `admin` vuelve a entrar. Se avisa en las
   notas de la versión.

6. **Una conexión en vivo ya abierta sobrevive a la baja** (hallazgo B7 de la revisión, confirmado por `backend-laravel`).
   La baja revoca los tokens de Sanctum, de modo que cualquier petición, suscripción nueva o reconexión falla. Pero una
   conexión WebSocket de Reverb **ya abierta** sigue recibiendo los eventos `presence.updated` del canal de presencia
   hasta que el navegador la cierra o se reconecta, y la reconexión ya falla. Hoy no hay ningún mecanismo para cerrarla
   desde el servidor.
   - **Alcance:** solo lectura de la presencia en tiempo real (quién está fichado ahora), dentro del alcance que tenía esa
     cuenta. No escribe nada y no da acceso a ninguna otra ruta.
   - **Lo mismo ocurre** con los restablecimientos de contraseña y de segundo factor y con el cambio propio, que también
     revocan tokens.
   - **Palanca futura:** cerrar desde Reverb las conexiones del usuario al revocar sus tokens, con un evento de revocación
     que el servidor de WebSocket atienda. Candidato: bloque 22 (operación y resiliencia) u otro posterior, con dueño
     `backend-laravel`.

**Cerrado y no residual:** la asignación de responsable por SQL. Lo resuelve `manager_user_uuid` en
`PATCH /departments/{id}` (§5).

## Consecuencias

- **Contrato** (antes que el código):
  - las seis rutas;
  - `manager_user_uuid` en `Department` y `UpdateDepartmentRequest`;
  - los ámbitos `accounts:*` y `password:change`;
  - `ManagementAccount*`, `TemporaryPasswordIssued` y `PasswordStatus`;
  - `ActorTotpCode` y `ActorCurrentPassword`;
  - la respuesta `ActorReauthenticationFailed`;
  - el tipo `urn:kronoqr:problem:password-change-required`;
  - `ManagementUser.password_change_required`.

  Todo es aditivo en la v1.
- **Esquema:**
  - `users.temporary_password_expires_at`, una expansión reversible;
  - el permiso `accounts:*` del rol `admin`; `TokenAbility` gana `accounts:*` y `password:change` (este, como `2fa:pending`, no es permiso de ningún rol);
  - `AuditAction` gana `user.created` y `user.password_changed`.
- **Código:**
  - puertos `TemporaryPasswordGenerator`, `PasswordHasher` y `ManagementAccountDirectory`;
  - los handlers de baja, restablecimiento y confirmación del 2FA pasan a `withChainLock`;
  - caso de uso nuevo de alta, usado también por la consola;
  - comando nuevo `identity:list-users`;
  - evento de baja que escucha `Product` para retirar las concesiones de soporte;
  - `new_password` y `current_password` fuera del *flash* de sesión;
  - `IDENTITY_PASSWORD_MIN_LENGTH` no puede pasar de 72.
- **Docblocks que dejan de ser ciertos y se reescriben:** «por qué es un comando y no un endpoint» y «esto no se alcanza
  por HTTP».
- **Documentación:**
  - Doc 01: RF-ID-10, el Anexo A, el Anexo B, §11 y §8.1 con `T1098`, `T1136` y `T1531`.
  - Doc 02: §7.3, la tabla de ADR y la nota 7.
  - Doc 07: lo actualiza `producto-licencia` con la evidencia final.
  - Plan: tarea 4.1.
  - Cliente: la guía de RRHH y `configuracion.md` sin `psql`, y los runbooks de alta y baja de cuentas.

## Verificación

| Punto | Prueba que lo demuestra |
|---|---|
| 1 | Feature y contrato de las seis rutas. `404` idéntico para «no existe» y «ya de baja», sin asiento. Los tokens anteriores de la cuenta responden `401` tras la baja. Una suscripción nueva y una reconexión a Reverb fallan (la conexión ya abierta no se corta: residuo 6). Las concesiones de soporte vigentes del actor dado de baja quedan revocadas |
| 2 | Autorización negativa en cada ruta de `accounts:*`: `403` para `rrhh`, `responsable_departamento` y `auditor`, para el soporte con **cada** alcance y para los tokens de quiosco y de portal; `401` sin token y con `2fa:pending`. `POST /auth/password`: `403` para el soporte con cada alcance. Las tres copias de `accounts:*` coinciden. `SupportScopeRoutesTest` sin las rutas nuevas |
| 3 | `PasswordStatus` en la frontera exacta de la caducidad, con `Clock` fijo. Una temporal caducada da el mismo `401` en `login`, `2fa/verify` y `2fa/confirm`. Prueba sobre `Router::getRoutes()`: toda ruta con ámbito rechaza `password:change` con `password-change-required`, incluido `/broadcasting/auth`. Recorrido completo de la temporal. Generador: longitud, cuatro clases, sin ambiguos y como mucho 72 bytes. Cambio propio: `422`; el contador propio abre el bloqueo con `429`, el token revocado y `auth.lockout_started`; escritura condicionada con `409` y `401` |
| 4 | `ManagementAccountDeactivationGuard` en unitaria. Integración con dos conexiones sobre las dos únicas `admin`: prospera una. `identity:deactivate-user` rechaza la última |
| 5 | `department_ids` en el alta responde `422`. `PATCH /departments/{id}`: `manager_user_uuid` de `rrhh` responde `403` y no cambia nada; una cuenta inexistente, de baja o con otro rol responde el mismo `422`; quedan dos asientos `role_assignment.changed` y la cuenta desplazada pierde el alcance en su siguiente petición |
| 6 | Doble de `SerializedLedgerWrite` que registra el orden de los candados. `ConfirmTwoFactorHandler` y `two-factor/reset` concurrentes sin `deadlock_detected` |
| 7 | Las tres rutas sin código, con un código erróneo y con un código ya usado en su franja responden `422`; tras el umbral, `429`; con contraseña en lugar de código desde una cuenta con 2FA, `422` |
| Alerta | `promtool test rules`: dispara en cada `two_factor_reset` y en cada alta `admin`. La métrica no lleva etiqueta de `uuid` |
