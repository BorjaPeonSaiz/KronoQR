# ADR-051 — Las cuentas de gestión se administran desde el panel, con contraseñas temporales y sin quedarse nunca sin administrador

| Campo | Valor |
|---|---|
| **Estado** | Propuesto. Pasa a «Aceptado» tras la revisión de `seguridad-cumplimiento`, que corre sobre la nota de diseño del bloque 12c |
| **Fecha** | 7 de octubre de 2026 |
| **Decide** | `arquitecto-dominio` (bloque 12c de la 2.2.0, sobre la decisión de producto del propietario del 22-09-2026) · `seguridad-cumplimiento` (revisión) |
| **Afecta a** | Precisa [ADR-039](ADR-039-que-hechos-de-autenticacion-dejan-asiento.md) (un hecho de autenticación nuevo que **no** deja asiento: el fallo de la contraseña actual en el cambio propio) · Respeta [ADR-010](ADR-010-auditoria-solo-append-encadenada.md), [ADR-012](ADR-012-api-versionada-en-la-ruta.md), [ADR-015](ADR-015-portal-con-codigo-y-pin.md), [ADR-016](ADR-016-producto-licenciado-on-premise.md), [ADR-019](ADR-019-la-licencia-nunca-bloquea-el-registro.md), [ADR-020](ADR-020-soporte-con-paquete-de-diagnostico.md), [ADR-027](ADR-027-audit-log-particionado.md) y [ADR-050](ADR-050-portal-accesible-desde-internet.md) · `docs/01` RF-ID-10 (nuevo), RF-ID-01, RF-ID-02 y Anexo B · `docs/api/openapi.yaml` · `docs/02` §7.3 (nota 7) · `plan implementacion/07-fase-4-evolucion.md` tarea 4.1 |
| **Requisitos** | RF-ID-10, RF-ID-01, RF-ID-02, RS-03, RS-05, RS-06, RL-16, reglas duras 5, 6, 12, 16, 18 y 21 |
| **Hallazgos** | H-03 de la revisión interna ASVS de 2026-09 (doc 07, fila «El producto no tiene baja de cuentas de gestión») |

## Contexto

Hasta la 2.1, el ciclo de vida de una cuenta de gestión solo existía en consola: `identity:create-user`,
`identity:deactivate-user` e `identity:reset-password` (estos dos, desde la 3.8) e `identity:2fa-reset`. La razón quedó
escrita en los docblocks de los handlers y en la nota del Anexo B del doc 01: un «dale de baja» o un «quítale el segundo
factor» por API es, en manos de un `admin` comprometido, la vía más cómoda de preparar el acceso a la cuenta de otro.

**El coste real fue el contrario.** Un hotel no tiene por qué tener SSH ni a nadie que sepa usarlo (RF-PD-06). Mientras
la baja exigiera una consola, el jefe de recepción que se iba conservaba una cuenta válida, con acceso a la corrección
de jornadas, hasta que alguien abría un caso con el fabricante. El propietario decidió el 22-09-2026 que habría pantalla
de cuentas de gestión, y el 24-09-2026 le acotó el alcance.

Al diseñarla aparecieron cuatro problemas que la consola no tenía, o tenía sin que se notara:

1. **Una contraseña fijada por otra persona es una credencial compartida.** `identity:reset-password` genera una y la
   enseña una vez, pero nada obliga a cambiarla ni hace que caduque. En consola eso afectaba a pocas cuentas; con un
   botón en el panel, afecta a todas.
2. **El panel permite dejar la instalación sin administrador.** La consola no conoce a quien la ejecuta. El panel sí,
   y con él caben dos errores: darse de baja a uno mismo o dar de baja a la última cuenta `admin` activa. Cualquiera de
   los dos solo tiene arreglo desde SSH.
3. **`settings:*` no basta.** Las rutas solo de `admin` viajan bajo `settings:*` (doc 02 §7.3, notas 4 a 6), y un acceso
   de soporte con alcance `configuration` lleva ese ámbito (`SupportScope`). Crear una cuenta `admin` es justo como un
   acceso temporal de soporte se vuelve permanente.
4. **Los handlers de la consola toman los candados al revés.** Escriben la fila de `users` y después el listener de
   auditoría toma el candado de la cadena. Por HTTP y con concurrencia, eso es un abrazo mortal con cualquier camino
   que los tome en el otro orden (ADR-010).

## Decisión

### 1. Seis rutas, por `uuid`, bajo `/management-accounts`

`GET` y `POST /api/v1/management-accounts`, `POST …/{uuid}/deactivate`, `POST …/{uuid}/password/reset` y
`POST …/{uuid}/two-factor/reset`, de `admin`; y `POST /api/v1/auth/password`, el cambio de la contraseña **propia**,
para cualquier rol de gestión. El nombre es el del lenguaje ubicuo («cuenta de gestión») y el que ya usa el código
(`ManagementAccount*`). `/users` sería ambiguo en un producto donde también usan el sistema el empleado en su portal y
el soporte del fabricante.

Las cuentas se identifican **por `uuid` y nunca por el correo**, que acabaría en el historial del navegador y en los
registros del servidor web.

**Nada se borra** (regla dura 5). La baja es `is_active = false`: la cuenta sigue siendo la autora de lo que firmó y
sigue contando para la guarda de `POST /setup/administrator`. **No hay reactivación** en esta versión: quien vuelve
recibe una cuenta nueva.

La baja **responde lo mismo, `404`, a «no existe» y a «ya estaba de baja»**, sin cambiar nada y sin asiento (RS-03). La
consola sigue distinguiéndolos, porque no es una superficie HTTP.

**Sin puerta de licencia** (ADR-019): dar de baja la cuenta de quien se fue es una medida de seguridad, no una función
accesoria.

### 2. Ámbito propio `accounts:*`, solo de `admin`, y una policy que rechaza a todo actor de soporte

Es la excepción explícita a las notas 4 a 6 del doc 02 §7.3, recogida en la nota 7. **Dos controles y no uno** (regla
dura 18): ningún alcance de soporte concede `accounts:*`, y además `ManagementAccountPolicy` rechaza a todo actor de
soporte, como ya hace `DataExportPolicy` (regla dura 16, ADR-020).

El cambio de la contraseña propia **no lleva ámbito**: basta una sesión completa sobre la propia cuenta. Una sesión
`2fa:pending` recibe `401`, como en `GET /auth/me`.

Las abilities se fijan al emitir el token. Una sesión abierta antes de actualizar no lleva `accounts:*` hasta que el
`admin` vuelve a entrar, como mucho tras la duración de una sesión.

### 3. Toda contraseña fijada por otra persona es temporal

- **La genera el servidor.** Su longitud es la mayor entre 20 y `IDENTITY_PASSWORD_MIN_LENGTH`, y lleva las cuatro
  clases que exige la política de RF-ID-01, así que la cumple por construcción. No usa los caracteres que se confunden
  al leerlos y teclearlos (`l I O 0 1`). El generador es criptográfico.
- **Se muestra una sola vez**, en la respuesta que la emite (`TemporaryPasswordIssued`), y **se entrega en mano**
  (regla dura 12). No va en `audit_log`, ni en un log, ni en ninguna otra respuesta. Si se pierde, se restablece.
- **Caduca** a las `IDENTITY_TEMPORARY_PASSWORD_TTL_HOURS` (72 de serie, de 1 a 720; regla dura 13). Una vez caducada,
  el acceso responde lo mismo que una contraseña incorrecta y en el mismo tiempo (RS-03). El listado lo muestra como
  `password_status: temporary_expired`, y el `admin` emite otra.
- **Obliga a cambiarla.** Mientras la cuenta tenga una (`users.temporary_password_expires_at` no nulo), toda ruta de
  gestión salvo `GET /auth/me`, `POST /auth/logout` y `POST /auth/password` responde `403` con
  `urn:kronoqr:problem:password-change-required`. El panel distingue ese `type` y lleva a la pantalla de cambio.
  `ManagementUser` gana `password_change_required`, opcional para que el cambio sea aditivo (ADR-012).

El 2FA no cambia. El titular lo da de alta en su primer acceso con el reto que ya devuelve `POST /auth/login`, y
generarlo en el alta haría pasar el secreto de una persona por la pantalla de otra.

El **cambio propio** exige la contraseña actual, también con la sesión abierta. Si no coincide, responde `422`, no
`401`, porque la sesión sigue siendo válida. Cada fallo cuenta en el mismo bloqueo por cuenta que `POST /auth/login`, y
con el bloqueo abierto responde `429`. El fallo **no** deja asiento, solo log técnico (ADR-039). Un cambio correcto
cierra las demás sesiones de la cuenta y conserva la actual.

`identity:create-user` deja de pedir la contraseña con eco apagado y entrega una temporal, como `identity:reset-password`:
hay un solo camino para fijar una contraseña ajena. El asistente de puesta en marcha (`POST /setup/administrator`) sigue
fijando una contraseña **propia**, porque quien la escribe es su titular.

### 4. La instalación nunca se queda sin administrador, y nadie se da de baja a sí mismo

Son invariantes del conjunto de cuentas, y por eso viven en el dominio (`ManagementAccountDeactivationGuard`) y no en
un controlador. Se aplican **también en la consola**: la regla no depende de por dónde se pida la baja.

- La baja de la propia cuenta responde `409`. La baja de un `admin` la decide otro `admin`.
- La baja de la última cuenta `admin` activa responde `409`, en el panel y en `identity:deactivate-user`. Primero se
  crea otra.
- Restablecer la contraseña o el 2FA de la **propia** cuenta responde `409`. La contraseña propia se cambia con
  `POST /auth/password`, que exige la actual.
- Restablecer el 2FA de una cuenta que no lo tiene confirmado responde `409`: no hay nada que retirar, y no deja un
  asiento que no cuenta nada.

Las dos primeras se comprueban **bajo un candado del padrón de cuentas**. Es el mismo `pg_advisory_xact_lock` que ya
serializa la creación del primer administrador, movido a una constante compartida de `Identity`. Así, dos `admin` que
se dan de baja el uno al otro a la vez no dejan la instalación sin ninguno.

### 5. El alta no asigna departamentos

Un `responsable_departamento` nace con `scope.kind: departments` y la lista vacía: no alcanza a nadie. El responsable es
un atributo **del departamento** (`departments.manager_user_id`, módulo `Workforce`). `Identity` no tiene arista hacia
`Workforce` (doc 02 §1.6, Deptrac), y asignar un responsable **desplaza al anterior**, que pierde su alcance. Ese
desplazamiento es un cambio de permisos de otra persona y merece su propio asiento; no puede ser un efecto secundario
del alta de una cuenta.

**Esto deja un hueco**, en «Residuos»: hoy el producto no tiene ningún camino para asignar responsable sin SQL.

### 6. Un único orden de candados para todo lo que audita una cuenta

1. **Cadena de `audit_log`** (`SerializedLedgerWrite::withChainLock`, ADR-010).
2. **Candado del padrón de cuentas** (solo la baja y el primer administrador).
3. **Fila de `users`** (`SELECT … FOR UPDATE`).
4. **`personal_access_tokens`**.
5. **Asiento** (reentrante).

**Fuera de todo candado** van generar la contraseña, hashearla, comparar un hash y limpiar el contador de intentos.
bcrypt o argon cuestan decenas de milisegundos, y dentro de la cadena congelarían cada fichaje del hotel, como ya
documenta la emisión del PIN.

Este orden obliga a corregir tres caminos existentes que hoy lo toman al revés (fila → cadena):

- `DeactivateManagementAccountHandler`;
- `ResetManagementPasswordHandler`;
- `ConfirmTwoFactorHandler`. Este es el que hace real el ciclo: el titular confirma su TOTP mientras un `admin` se lo
  retira.

`CreateFirstAdministratorHandler` se alinea también. No cierra un ciclo real hoy, pero con un solo orden no hay que
razonar por qué uno no hace daño.

### Auditoría

| Hecho | Acción | Novedad |
|---|---|---|
| Alta | `user.created` + `role_assignment.changed` | `user.created` es nueva |
| Baja | `user.deactivated` (actor, motivo) | ya existía (3.8) |
| Restablecer contraseña | `user.password_reset` (actor) | ya existía (3.8) |
| Restablecer 2FA | `auth.two_factor_reset` (actor, motivo) | ya existía (2.1) |
| Cambio propio | `user.password_changed` | nueva |

Todos los asientos son síncronos y van en la misma transacción (ADR-027). Llevan el `uuid` de la cuenta y del actor, y
nunca el nombre, el correo ni nada derivado de la contraseña (regla dura 21).

## Alternativas descartadas

| Alternativa | Por qué se descarta |
|---|---|
| **Seguir solo por consola** | Es el estado que produjo H-03: el cliente sin SSH no da de baja a nadie, y RF-PD-06 dice por escrito que la consola no puede hacer falta |
| **Bajo `settings:*`, como los quioscos y la exportación de datos** | Un solo control (la policy) frente al soporte con alcance `configuration`, que lleva ese ámbito. Crear una cuenta `admin` es como un acceso temporal se vuelve permanente |
| **Contraseña generada sin caducidad ni cambio obligatorio** | Credencial compartida a largo plazo (ASVS 2.3.1): el `admin` que la entregó la conoce mientras nadie la cambie, que puede ser siempre |
| **Invitación o enlace de activación por correo** | Regla dura 12 y ADR-015: el producto no depende del correo de nadie, y la instalación puede no tener salida a internet (ADR-016) |
| **Asignar departamentos en el alta** | Es una escritura de `Workforce` hecha desde `Identity`, y desplaza en silencio al responsable anterior, sin su asiento |
| **Permitir la baja de la última `admin` con un aviso** | El aviso se acepta con un clic, y la salida es SSH |
| **Distinguir «no existe» de «ya de baja» en la API** | Por la consola no hay oráculo que proteger; por HTTP sí, y la segunda pulsación de un botón no es un hecho nuevo |
| **Restablecer contraseña y 2FA en una sola acción** | Convierte «dame la cuenta entera de esta persona» en un clic. Son dos hechos con dos asientos, a propósito |

## Residuos y riesgos que se aceptan (doc 07 §6)

1. **Suplantación en dos pasos por un `admin` comprometido.** `two-factor/reset` por API cambia lo que la nota del
   Anexo B daba por cerrado. Con la contraseña y el 2FA de otra cuenta restablecidos, quien controle una sesión `admin`
   entra como esa persona y firma correcciones con su nombre. Es la amenaza de repudio sobre el registro legal. Ya
   podía hacerlo sin este ADR —un `admin` crea una cuenta nueva con el rol que quiera—, pero a su propio nombre. Lo
   nuevo es **hacerlo bajo la identidad de otro**.

   **Mitigación propuesta, para que la confirme `seguridad-cumplimiento`:**

   - **Dos acciones, dos asientos, nunca una.** `user.password_reset` y `auth.two_factor_reset` llevan siempre el
     **actor**, y el 2FA además un **motivo obligatorio**. La secuencia «restablecer contraseña y 2FA de la misma cuenta
     por el mismo actor en poco tiempo» queda en la cadena, sin poder borrarse ni repudiarse.
   - **Nunca sobre la propia cuenta.** Un `admin` no puede usar estas rutas para rehacer sus propias credenciales y
     borrar su rastro de acceso.
   - **Se cierran las sesiones del afectado.** Las sesiones abiertas de la persona suplantada caen en la misma
     transacción. La víctima lo nota en su siguiente petición, en vez de compartir la cuenta en silencio.
   - **Alerta al receptor de seguridad.** Se propone una regla en el catálogo de alertas (tarea 3.2) sobre
     `kronoqr_management_account_changes_total{action="two_factor_reset"}`. Disparo: un restablecimiento de 2FA
     precedido en menos de 24 h por un `password_reset` de la misma cuenta, o más de N restablecimientos por día. Va al
     receptor de seguridad que la instalación ya tiene configurado para el bloqueo por origen (ADR-050), con runbook en
     `docs/runbooks/ataque-a-credenciales.md`. **La métrica y la regla no existen todavía**; son trabajo de
     `devops-observabilidad` en este bloque o en el 22.
   - **La consola sigue existiendo** y sigue siendo el camino de recuperación cuando el panel está comprometido.

   Riesgo residual: un `admin` comprometido que actúa una sola vez, fuera de horas y sin repetir el patrón, no dispara
   la alerta. Queda **detectable y no repudiable**, que no es lo mismo que impedido.

2. **Ventana de auto-alta del 2FA a petición de un `admin`.** Tras `two-factor/reset`, quien conozca la contraseña de esa
   cuenta puede activar su propio TOTP antes que el titular. Es la ventana ya aceptada en el doc 07 §6 (fila 1), pero
   ahora se abre por API. Se mantienen los mismos controles: `auth.two_factor_enabled` con momento e IP, y el reto dura
   minutos.

3. **La temporal viaja por la pantalla del `admin`.** Se muestra una vez y no se guarda, pero un `admin` malintencionado
   la anota. El control es el cambio obligatorio en el primer acceso y la caducidad. Si el titular no entra a tiempo,
   la temporal caduca sin haber servido.

4. **Responsable sin departamento.** Hasta que exista un camino en el producto para fijar `departments.manager_user_id`,
   una cuenta `responsable_departamento` no alcanza a nadie salvo editando esa columna por SQL. Se propone añadir
   `manager_user_uuid` a `PATCH /departments/{id}`, con su propio asiento. **Pendiente de decidir** si entra en el
   bloque 12c o en el 21.

5. **Las sesiones abiertas antes de actualizar no ven «Cuentas»** hasta que el `admin` vuelve a entrar. Se avisa en las
   notas de la versión.

## Consecuencias

- **Contrato** (antes que el código, `36ae8677`): las seis rutas, `accounts:*` en `managementToken`,
  `ManagementAccount*`, `TemporaryPasswordIssued`, `PasswordStatus`, el tipo
  `urn:kronoqr:problem:password-change-required` y `ManagementUser.password_change_required`. Todo es aditivo en la v1.
- **Esquema:** `users.temporary_password_expires_at` (expansión, reversible) y el permiso `accounts:*` con su pivote de
  `admin`. **`AuditAction`** gana `user.created` y `user.password_changed`.
- **Código:** puertos `TemporaryPasswordGenerator`, `PasswordHasher` y `ManagementAccountDirectory`.
  `ManagementAccountLifecycle` pasa a trabajar por `uuid` y con hash. Los handlers de baja y restablecimiento pasan a
  `withChainLock`, y también `ConfirmTwoFactorHandler`. Hay un caso de uso nuevo de alta, que también usa la consola, y
  un comando nuevo, `identity:list-users`.
- **Docblocks que dejan de ser ciertos y se reescriben:** «por qué es un comando y no un endpoint», en
  `DeactivateManagementAccountHandler` y `ResetTwoFactorHandler`, y «esto no se alcanza por HTTP», en
  `ManagementAccountLifecycle`.
- **Doc 01:** RF-ID-10, Anexo A (fase 4), Anexo B y su nota sobre el 2FA, y cuatro escenarios en §11. **Doc 02:** §7.3,
  fila de `admin` y nota 7. **Doc 07:** las filas «El producto no tiene baja de cuentas de gestión» y la de la ventana
  de auto-alta, a cargo de `seguridad-cumplimiento`. **Plan:** tarea 4.1.
- **Cliente:** la guía de RRHH y `configuracion.md` dejan de remitir a la consola o a `psql` para las cuentas. Hay
  runbooks nuevos de alta y baja de cuentas y una variable nueva, `IDENTITY_TEMPORARY_PASSWORD_TTL_HOURS`.

## Verificación

| Punto | Prueba que lo demuestra |
|---|---|
| 1 | Feature + contrato de las seis rutas. `404` idéntico para «no existe» y «ya de baja», sin asiento. Los tokens anteriores de la cuenta responden `401` tras la baja. Ninguna ruta lleva `feature-not-licensed` |
| 2 | Autorización negativa en cada ruta de `accounts:*`: `rrhh`, `responsable_departamento` y `auditor` reciben `403`. El soporte recibe `403` **con cada alcance, incluido `configuration`**, y también los tokens de quiosco y de portal. Sin token y con `2fa:pending`, `401`. Tres copias de `accounts:*` (enum, contrato y migración) que no divergen |
| 3 | Unitaria de `PasswordStatus` en la frontera exacta de la caducidad, con `Clock` fijo. Unitaria del generador (longitud, cuatro clases, sin ambiguos, cumple la política). Feature del recorrido alta → `202` → TOTP → `403 password-change-required` → `POST /auth/password` → acceso. Una temporal caducada da el mismo `401` que una contraseña errónea. En el cambio propio, una actual errónea da `422` y, tras el bloqueo, `429` |
| 4 | Unitaria de `ManagementAccountDeactivationGuard`. Integración con dos conexiones que dan de baja a la vez a las dos únicas `admin` activas: una sola prospera. `identity:deactivate-user` rechaza la última `admin` |
| 5 | `POST /management-accounts` con `department_ids` responde `422` (`additionalProperties: false`). Un responsable recién creado tiene `scope.department_ids` vacío |
| 6 | Unitaria con un `SerializedLedgerWrite` de prueba que registra el orden: no se hashea dentro del candado y la fila se toma después de la cadena. Integración de `ConfirmTwoFactorHandler` y `two-factor/reset` concurrentes sobre la misma cuenta sin `deadlock_detected` |
| Residuo 1 | Cuando exista la regla: prueba de la regla de alertas con `promtool test rules` (patrón de la tarea 3.2) |
