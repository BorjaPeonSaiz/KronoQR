# ADR-046 — La ficha del empleado se escribe bajo el candado de la cadena, con la fila bloqueada y solo por las columnas que cambian

| Campo | Valor |
|---|---|
| **Estado** | Aceptada — **aprobada con condiciones** por `seguridad-cumplimiento` el 2 de octubre de 2026. Las condiciones A-1 a A-5 y las invariantes 1-9 del dictamen están incorporadas a este texto. A-2 se cumple con un orden distinto del que proponía el dictamen (§1.3), **aprobado por `seguridad-cumplimiento` el 2 de octubre de 2026 en su segunda pasada**, tras comprobarlo en el código |
| **Fecha** | 2 de octubre de 2026 |
| **Decide** | `arquitecto-dominio` (Bloque 17 de la 2.2.0, hallazgos R7-RV-01 y R4-BE-01) · `seguridad-cumplimiento` (revisión y condiciones) |
| **Afecta a** | Precisa [ADR-010](ADR-010-auditoria-solo-append-encadenada.md) (orden de candados de la cadena, puerto `SerializedLedgerWrite`) y [ADR-002](ADR-002-arquitectura-hexagonal-en-el-nucleo.md) (variante ligera de `Workforce`) · `Workforce\Application\Port\EmployeeRepository`, `EloquentEmployeeRepository`, `EloquentEmployeePinRepository`, `HashedEmployeePinVerifier` · los casos de uso de §1.2 · listeners de `EmployeeOffboarded` (`Identity`, `Compliance`) |
| **Requisitos** | RN-14, RF-GP-01, RF-GP-03, RF-GP-05, RF-QR-*, RL-04, reglas duras 5, 6 y 19 |

## Contexto

`UpdateEmployeeHandler` y `OffboardEmployeeHandler` leen la ficha con `findByUuid()`, sin candado, y
`EmployeeRepository::save()` reescribe **la fila entera**, `status` y `terminated_at` incluidos. Si una
modificación lee la ficha, la baja confirma y después escribe la modificación, la persona vuelve a
estar `active`, con `terminated_at` nulo, sin tarjeta, y en `audit_log` queda `employee.offboarded`
seguido de un `employee.updated` que no menciona el estado. Reproducido: 15 de 30 intentos por HTTP y
1 de 1 con el intercalado forzado (R4-BE-01). La importación pasa por el mismo caso de uso, así que
está igual de expuesta. Es una acción con relevancia legal (RN-14) sin traza fiel: la base contradice
al asiento.

El arreglo obvio —`lockForUpdate()` en la lectura— tiene tres trampas en este esquema:

1. **El orden de candados.** Toda escritura auditada pasa por el candado consultivo global de la
   cadena de `audit_log` (ADR-010). Si dos caminos toman la cadena y una misma fila en orden
   distinto, hay abrazo mortal (`40P01`).
2. **El tipo de candado.** `employees` es la tabla más referenciada del esquema. Cada inserción en
   `scan_events`, `shift_entries`, `absences` o `credentials` comprueba su clave ajena con un
   `FOR KEY SHARE` sobre la ficha, y el fichaje lo hace **antes** de tomar la cadena
   (`RegisterScanHandler`: el tramo se guarda y el evento, con su asiento, se publica al final).
   `SELECT … FOR UPDATE` choca con ese candado.
3. **Las tablas padre.** La ficha referencia a `sites`, `departments` y `users`. Una escritura de la
   ficha que cambia `department_id` o `pin_delivered_by_user_id` toma `FOR KEY SHARE` sobre la fila
   padre, y PostgreSQL toma `FOR UPDATE` —que choca con él— en todo `UPDATE` que cambia una columna
   con índice único completo: renombrar un departamento (`departments_site_id_name_unique`) o el
   centro (`sites_name_unique`).

## Decisión

### 1. Un solo orden de candados

**Filas padre (`sites`, `departments`, `users`, `devices`) → cadena de auditoría → fila de `employees` → filas
de `credentials` → sesiones del portal.**

Es el orden que el fichaje ya sigue (claves ajenas primero, cadena al final) y el que siguen hoy los
escritores de las tablas padre. Lo que cambia es todo lo que está a la derecha de la cadena.

#### 1.1 Regla general

1. **Todo caso de uso que modifica una fila existente de `employees` o de `credentials` y deja
   asiento** envuelve su escritura en `SerializedLedgerWrite::withChainLock()` y, dentro, lee lo que
   va a escribir. Lo que se lee con la cadena tomada está confirmado y nadie más que siga esta regla
   puede cambiarlo hasta el commit.
2. **Si además necesita una fila padre distinta de la que ya referenciaba** —un `department_id`
   nuevo—, la toma con `SELECT … FOR KEY SHARE` **antes** de la cadena. Nunca toma una fila padre con
   la cadena en la mano.
3. **Un escritor de una tabla padre que cambia una columna con índice único completo** (renombrar)
   toma su fila **antes** de la cadena, que es lo que ya hacen `RenameDepartmentHandler` y
   `UpdateSiteHandler`. No entra en `withChainLock()`.
4. **Nada toma `FOR UPDATE` sobre `employees`**, ni explícito ni implícito: ningún `UPDATE` de la ficha
   escribe `id`, `uuid` ni `employee_code` (A-5). **Las tablas padre son `sites`, `departments`,
   `users` y `devices`** (esta última, por `scan_events.device_id`): la rotación del token del
   quiosco (`Identity/Infrastructure/Adapter/SanctumDeviceTokenIssuer.php:67`) toma `devices FOR
   UPDATE` y después la cadena, así que ya va filas padre → cadena. Sobre `users`, ningún caso de uso
   hace `UPDATE` de `uuid` o `email` ni `DELETE`, y de eso depende que nunca haya `FOR UPDATE` sobre
   `users` con la cadena tomada por otro. **La premisa la ata una prueba de arquitectura** (§6,
   punto 10): si alguien añade «cambiar el correo de una cuenta de gestión», reabriría el ciclo en
   silencio. Si llega a hacer falta, ese caso de uso sigue el punto 3.
5. **El trabajo caro va fuera de la cadena**: el hash del PIN (bcrypt, unos 160 ms en producción), la
   generación de secretos y el PDF de las tarjetas se calculan antes de `withChainLock()` (A-3).

#### 1.2 Casos de uso afectados

Todos en `backend/app/Modules/`. «Cambia» es lo que `backend-laravel` tiene que hacer.

| Caso de uso | Fichero | Toca | Cambia |
|---|---|---|---|
| Modificación de la ficha | `Workforce/Application/UseCase/UpdateEmployeeHandler.php` | `employees` | `withChainLock`, `findForUpdate`, `saveProfile`. Si cambia `department_id`, `FOR KEY SHARE` del departamento nuevo **antes** de la cadena |
| Baja | `Workforce/Application/UseCase/OffboardEmployeeHandler.php` | `employees` y, por su evento, `credentials` y sesiones | `withChainLock`, `findForUpdate`, `saveTermination` |
| Revocación por la baja | `Identity/Application/UseCase/RevokeCredentialsOfOffboardedEmployee.php` | `credentials` | Nada: corre dentro de la baja y hereda el orden |
| Importación | `Workforce/Application/UseCase/ApplyEmployeeImport.php` | `employees` | Al abrir su transacción, `FOR KEY SHARE` del centro y de **todos los departamentos del mapa `$departments`** con el que se resuelve cada fila (hoy se lee fuera de la transacción, `:83`; pasa a leerse y bloquearse dentro, una sola vez), ordenados por `id`, **antes** del primer asiento; después, cada fila por los casos de uso de esta tabla |
| Planificación de la importación | `Workforce/Application/UseCase/PlanEmployeeImport.php` | — | Rechaza la fila de una persona de baja (`employee_terminated`) |
| Alta | `Workforce/Application/UseCase/RegisterEmployeeHandler.php` | inserta en `employees` | El PIN se calcula **antes** de la transacción, como ya hace la importación (A-3). La inserción va antes de la cadena: la fila nueva no la ve nadie hasta el commit |
| Emisión del PIN | `Workforce/Application/UseCase/IssueEmployeePinHandler.php` | `employees` | Recibe el `PinMaterial` ya calculado; solo la escritura y el asiento van dentro de `withChainLock` (A-3) |
| Restablecimiento del PIN | `Workforce/Application/UseCase/ResetEmployeePinHandler.php` | `employees` | Calcula el `PinMaterial` **antes** de la cadena y se lo pasa a la emisión (A-3) |
| Entrega del PIN | `Workforce/Application/UseCase/RecordPinDeliveryHandler.php` | `employees` | `withChainLock`. Escribe `pin_delivered_by_user_id`: `FOR KEY SHARE` sobre `users`, compatible con todo lo que se escribe en `users` (punto 4) |
| Emisión y reemisión de tarjeta | `Identity/Application/UseCase/IssueCredential.php` | `credentials` | `withChainLock`; el estado laboral se **relee dentro** y la comprobación de RN-14 va dentro (A-1). Hoy se lee fuera y sin candado (`:79`) |
| Rotación de la clave de firma | `Identity/Application/UseCase/RotateSigningKey.php` | `credentials` | `withChainLock`; la lista de tarjetas a reemitir se calcula **dentro** y **omite a las personas de baja** (A-1). Hoy se calcula fuera y no mira el estado (`:91-97`) |
| Revocación manual | `Identity/Application/UseCase/RevokeCredential.php` | `credentials` | `withChainLock` (hoy fila → cadena, `:66-69`): sin esto, abrazo mortal con la revocación de la baja |
| Entrega de tarjeta | `Identity/Application/UseCase/DeliverCredential.php` | `credentials` | `withChainLock` (hoy fila → cadena, `:83-96`), por lo mismo |
| Impresión (por persona y por lote) | `Identity/Application/UseCase/MintCards.php`, que usan `PrintCredential.php` y `PrintCredentialBatch.php` | `credentials` | Solo `persist()` entra en `withChainLock`; los secretos y el PDF siguen fuera (`:136`). Una tarjeta revocada entre medias no se marca impresa (`markPrinted` ya devuelve `false`) |

**Revisados y sin cambio**, porque ya siguen el orden:

| Caso de uso | Fichero | Por qué no cambia |
|---|---|---|
| Renombrar departamento | `Workforce/Application/UseCase/RenameDepartmentHandler.php` | Toma `FOR UPDATE` sobre su fila y después la cadena: punto 3 |
| Crear departamento | `Workforce/Application/UseCase/CreateDepartmentHandler.php` | Inserta (clave ajena a `sites`) y después la cadena: filas padre → cadena |
| Crear y modificar el centro | `Workforce/Application/UseCase/CreateSiteHandler.php`, `UpdateSiteHandler.php` | Igual que el renombrado de departamento. El renombrado del centro hace esperar unos milisegundos a los fichajes, como hoy |
| Cuentas de gestión (`users`) | `Identity/Application/UseCase/AuthenticateUserHandler.php`, `ConfirmTwoFactorHandler.php`, `EnrolTwoFactorHandler.php`, `VerifyTwoFactorHandler.php`, `ResetTwoFactorHandler.php`, `ResetManagementPasswordHandler.php`, `DeactivateManagementAccountHandler.php`, `CreateFirstAdministratorHandler.php`; `Identity/Infrastructure/Console/CreateManagementUserCommand.php` | Insertan o cambian columnas sin índice único (`is_active`, `last_login_at`, contraseña, 2FA): toman `FOR NO KEY UPDATE`, que no choca con el `FOR KEY SHARE` de las claves ajenas que apuntan a `users` |
| Fichaje | `Attendance/Application/UseCase/RegisterScanHandler.php` | `FOR KEY SHARE` sobre `sites` y `employees` y la cadena al final: es el orden de §1. `FOR NO KEY UPDATE` sobre la ficha no le hace esperar |
| Rehash del PIN | `Workforce/Infrastructure/Adapter/HashedEmployeePinVerifier.php` | Fuera del orden, por diseño: §4 |

#### 1.3 Por qué las tablas padre van antes de la cadena y no después

El dictamen proponía lo contrario para A-2: que el renombrado de un departamento tome la cadena antes
que su fila. Eso cierra el ciclo con la modificación de la ficha, pero abre otros dos:

- **Con el alta.** `RegisterEmployeeHandler` inserta la ficha —`FOR KEY SHARE` sobre su departamento
  y sobre el centro— y después escribe el asiento. Un renombrado que tuviera la cadena y esperase la
  fila del departamento formaría ciclo con ella, y habría que pasar también el alta a cadena primero.
- **Con el fichaje, en el centro.** Con el alta pasada a cadena primero, el renombrado del centro
  tendría que ir igual, y entonces formaría ciclo con cada fichaje, que toma `FOR KEY SHARE` sobre el
  centro al guardar el tramo y la cadena al final. La víctima de `40P01` podría ser el fichaje (regla
  dura 19). Evitarlo exigiría borrar `sites_name_unique` con una migración.

Poniendo las filas padre a la izquierda de la cadena, ninguno de los escritores de las tablas padre
cambia y el fichaje tampoco. Lo que se exige a cambio es lo del punto 2: quien tiene la cadena no pide
una fila padre. Hay dos caminos que lo harían, y los dos toman la fila antes: la modificación con
cambio de departamento y la importación.

### 2. `FOR NO KEY UPDATE`, sobre `employees` y nada más

La lectura bloqueante es `EmployeeRepository::findForUpdate(string $uuid): ?Employee`, que toma la
fila con `SELECT … FOR NO KEY UPDATE` (en el adaptador, `->lock('for no key update')`), **solo sobre
`employees`**, sin `JOIN`. Es el candado que toma un `UPDATE` que no cambia la clave: serializa a los
escritores de la ficha entre sí y **no choca** con el `FOR KEY SHARE` de las claves ajenas, así que un
fichaje, una ausencia o una tarjeta de esa persona no esperan a la baja. `FOR UPDATE` queda prohibido
en esta tabla.

`findByUuid()` sigue existiendo para leer. Un caso de uso que escribe la ficha no lo usa.

### 3. Se escriben las columnas que cambian, con predicado de estado

`EmployeeRepository::save()` desaparece del puerto. En su lugar:

- **`saveProfile(Employee $employee, bool $statusChanged)`** escribe `first_name`, `last_name`,
  `email`, `department_id`, `locale` y `teleworking`, y `status` **solo** si el caso de uso lo ha
  cambiado (`suspend()`/`reinstate()`). Nunca escribe `terminated_at`.
- **`saveTermination(Employee $employee)`** escribe `status` y `terminated_at`, y nada más.
- **Ninguna de las dos escribe `id`, `uuid` ni `employee_code`** (A-5): los tres tienen índice único
  completo y escribirlos, aunque fuera con el mismo valor que no cambia, es el camino por el que un
  cambio futuro acabaría tomando `FOR UPDATE`.

Las dos llevan `WHERE uuid = ? AND status <> 'terminated'` y comprueban las filas afectadas: **cero
filas es `EmployeeAlreadyTerminated`** (`409`). El candado es lo que impide la carrera; el predicado es
lo que la convierte en un `409` honesto si algún día se escribe un camino que se salte la lectura
bloqueante. Es el mismo criterio que `EloquentAbsenceRepository` (`WHERE status = 'active'` y
recuento) tras el bloqueante de la 3.10.

Sin un método que escriba la fila entera, «una modificación de nombre reescribe el estado» deja de
poder escribirse.

### 4. El rehash del PIN no entra en el orden (A-4)

`HashedEmployeePinVerifier::rehashIfStale()` está en el camino del fichaje por PIN y del acceso al
portal, y hoy hace `UPDATE employees SET pin_hash = ? WHERE uuid = ?` sin condición (`:248-250`). Tiene
dos defectos: puede pisar el hash nuevo de un restablecimiento con el del PIN viejo, y esperaría a una
operación de gestión que tenga la ficha. Pasa a ser **oportunista**:

- `UPDATE … SET pin_hash = ? WHERE uuid = ? AND pin_hash = <el hash que leyó>`;
- precedido de `SELECT … FOR NO KEY UPDATE NOWAIT` (o `SKIP LOCKED`) sobre esa fila;
- si la fila está ocupada o el hash ya no es el que leyó, **no hace nada y no lo dice**: el rehash se
  repetirá en el siguiente acceso;
- **nunca toma la cadena** y nunca hace esperar a un fichaje.

### 5. La importación

`ApplyEmployeeImport` sigue siendo todo o nada en una transacción y sigue usando
`RegisterEmployeeHandler` y `UpdateEmployeeHandler` por fila. Al abrir su transacción toma
`FOR KEY SHARE` del centro y de **todos los departamentos del mapa `$departments`** con el que se
resuelve cada fila, ordenados por `id` (§1.1, punto 2). El mapa se lee **una sola vez, dentro de la
transacción y con ese candado**; hoy se lee fuera (`ApplyEmployeeImport.php:83`). No vale bloquear
«los del informe» ni hacer una segunda lectura: si una fila resolviera un `id` que no está en el
conjunto bloqueado, la modificación pediría ese departamento con la cadena ya tomada y reabriría el
ciclo con un renombrado. La cadena la toma el primer asiento y la piden otra vez, de forma reentrante, los
`withChainLock` de cada fila. **No se bloquean de antemano las fichas**: la cadena ya serializa a
todos sus escritores.

Una fila que corresponde a una persona **dada de baja**:

- **Al comprobar** (`PlanEmployeeImport`), se rechaza con el código nuevo `employee_terminated`. La
  importación no modifica la ficha de una baja ni la da de alta otra vez (RN-14). Hoy esa fila llega a
  `update`, `updateProfile()` lanza `EmployeeAlreadyTerminated` y tumba la importación entera con un
  `409`: es un defecto que existía antes de la carrera.
- **Si la baja se registra entre la comprobación y la aplicación** del mismo fichero, la fila llega
  como `update`, la lectura bloqueante ve `terminated` y la aplicación entera responde `409` sin
  escribir nada. Es coherente con `ImportFileChanged`: se aplica **exactamente lo que se revisó**, y si
  el estado cambió, hay que volver a comprobar.

### 6. Invariantes y pruebas

**Nunca queda en `audit_log` un `employee.offboarded` de una persona que no está `terminated`, ni una
persona `terminated` con una credencial activa, ni un `employee.updated` cuyo `changed_fields` no
describa el cambio que se confirmó.** La invariante se comprueba siempre con consultas sobre
`audit_log`, `employees` y `credentials`, nunca por las respuestas HTTP.

Concurrencia y base de datos (`tests/Integration/Workforce/`, con `CommittedDatabase`; dueño
`qa-testing`):

1. **`OffboardUpdateRaceTest`**, 30 rondas por HTTP (`ParallelRequests::runTasks`) en cada tanda:
   modificación contra baja, restablecimiento de PIN contra baja, **emisión de tarjeta contra baja**,
   **rotación de la clave contra baja**, **renombrado de departamento contra modificación con cambio
   de departamento** y, para demostrar §1.3, **renombrado del centro contra fichajes y contra un
   alta**. Criterio: cero `40P01` y ninguna persona `terminated` con credencial sin revocar.
2. **`EmployeeWriteLockTest`**, determinista con dos sesiones: con la ficha tomada por
   `findForUpdate()`, **y también** con `saveProfile()` o `saveTermination()` ejecutados sin
   confirmar, otra sesión inserta en `scan_events`, `absences` y `credentials` sin esperar
   (`lock_timeout` corto), y no puede tomar la ficha con `NOWAIT` (`55P03`).
3. **Orden de candados**: con la cadena tomada por otra sesión, cada caso de uso de la tabla de §1.2
   que la toma (incluidos los de A-1) **no retiene la fila** de `employees` ni de `credentials`
   mientras espera: otra sesión la toma con `NOWAIT`.
4. **El PIN se calcula fuera de la cadena**: con un `PinHasher` falso que consulta `pg_locks` al
   calcular, la sesión no tiene el candado consultivo de la cadena, en la emisión, el restablecimiento
   y el alta.
5. **`OffboardUpdateInterleavingTest`**, determinista, con un decorador del repositorio
   (`tests/Support/Workforce/InterleavingEmployeeRepository.php`, como
   `InterleavingDataExportRepository` del bloque 16) que, justo después de la lectura de una
   escritura, ejecuta la otra en una segunda conexión con `SET lock_timeout`. Con el código de hoy la
   segunda confirma y la persona termina `active`: **la prueba falla hoy**. Cubre modificación contra
   baja, baja contra modificación e importación contra baja.

Funcionales (dueños `backend-laravel` para las unitarias y `qa-testing` para el resto):

6. **Baja con fecha futura**: `422` con `employees`, `credentials`, `audit_log` y sesiones del portal
   sin cambios. Con reloj falso (`FrozenTime`), en Atlantic/Canary y en Europe/Madrid a las 23:30 y a
   las 00:30, y en los dos días de cambio de hora: cese = hoy se admite, mañana se rechaza; cese = alta
   se admite, el día anterior al alta se rechaza; con el alta en el futuro, cese = alta se admite y
   cualquier otra fecha se rechaza.
7. **Unitaria de `Employee::offboard($terminatedOn, $today)`**, con mutación por encima del 80 % sobre
   el fichero entero.
8. **Importación**: la fila de una persona de baja sale `employee_terminated` y el resto se aplica.
   Comprobar → baja → aplicar da `409`, cero filas escritas y ningún asiento nuevo.
9. **Alta manual de tramo** para una persona de baja: jornada = fecha de cese se admite, con su
   `shift_corrections` y su asiento; el día siguiente da `422` en `work_date`. La autorización
   negativa por rol ya existe. Y **arquitectura**: el puerto no declara `save()`; nada en `Workforce`
   usa `lockForUpdate()` sobre `employees`; ningún `UPDATE` de `EloquentEmployeeRepository` escribe
   `id`, `uuid` ni `employee_code`.
10. **Arquitectura, premisa de `users`** (dueño `qa-testing`): ningún fichero de `backend/app` hace
    `UPDATE` de `users.uuid` o `users.email` ni `DELETE` sobre `users` (§1.1, punto 4). Si falla, el
    caso de uso nuevo tiene que tomar su fila antes de la cadena y esta prueba se actualiza con él.

## Alternativas descartadas

- **Versión optimista** (columna `version` y `If-Match`). Cambia el contrato, la pantalla y la
  importación, y convierte en `409` dos ediciones legítimas a la vez de campos distintos. El fallo no
  es una edición perdida sino un orden de escritura, y el producto ya resuelve los órdenes con candado
  pesimista (`daily_totals`, ausencias).
- **Solo el predicado `status <> 'terminated'`, sin candado.** Impide la reactivación, pero no que
  `changed_fields` se calcule sobre una lectura vieja: el asiento podría describir un cambio que no es
  el que se confirmó.
- **`SELECT … FOR UPDATE`.** Bloquea las claves ajenas: cada fichaje de esa persona esperaría a la
  baja, y abre un ciclo con el registro de ausencias.
- **Las tablas padre a la derecha de la cadena** (el renombrado toma la cadena primero). Ver §1.3:
  obliga a pasar el alta a cadena primero y deja el renombrado del centro en ciclo con el fichaje o
  pendiente de una migración.
- **Un disparador que impida salir de `terminated`.** No hay ningún camino legítimo que lo haga (no
  existe la readmisión) y los dos únicos `UPDATE` que escriben `status` llevan el predicado. Se
  reconsidera si la 2.3.0 diseña la readmisión, que tendrá que decidir qué pasa con esa ficha.

## Consecuencias

- La cadena se toma unos milisegundos antes en cada escritura de la ficha o de una tarjeta: la lectura
  y un `UPDATE` de una fila. El candado ya se retenía desde el primer asiento hasta el commit, así que
  el tiempo que bloquea a los fichajes del hotel no cambia de orden de magnitud. La importación y la
  rotación de la clave ya lo retenían durante todo su bucle. El bcrypt, los secretos y el PDF quedan
  fuera.
- **Los casos de uso del PIN y de las tarjetas cambian en el mismo bloque**, aunque los hallazgos no
  los nombraran: si la baja pasa a cadena → ficha → tarjeta y ellos siguen en fila → cadena, se
  introduce el abrazo mortal que este ADR existe para evitar.
- `save()` desaparece del puerto. Cualquier caso de uso nuevo que escriba la ficha tiene que elegir
  qué columnas escribe.
- Un `PATCH` sobre una ficha que se está dando de baja responde `409` en lugar de deshacer la baja.
- **Para quien escriba un caso de uso nuevo**: si toca `employees` o `credentials` y audita, va en
  `withChainLock`; si necesita una fila padre distinta, la toma antes; si cambia una columna con
  índice único de una tabla padre, toma su fila antes de la cadena.

## Verificación

- Las pruebas 1-5 y 8-9 de §6, en verde en la CI (etapa de integración). Las 6 y 7, en las etapas de
  feature y unitaria, con la mutación por fichero de la etapa ③.
- Las de arquitectura de §6, puntos 9 y 10.
- Del dominio, este ADR solo añade el caso `employee_terminated` a `ImportMessageCode` (un error, no un
  aviso), con su prueba unitaria.
