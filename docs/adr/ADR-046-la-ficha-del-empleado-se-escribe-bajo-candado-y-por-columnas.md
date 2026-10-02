# ADR-046 — La ficha del empleado se escribe bajo el candado de la cadena, con la fila bloqueada y solo por las columnas que cambian

| Campo | Valor |
|---|---|
| **Estado** | Aceptada |
| **Fecha** | 2 de octubre de 2026 |
| **Decide** | `arquitecto-dominio` (Bloque 17 de la 2.2.0, hallazgos R7-RV-01 y R4-BE-01) |
| **Afecta a** | Precisa [ADR-010](ADR-010-auditoria-solo-append-encadenada.md) (orden de candados de la cadena, puerto `SerializedLedgerWrite`) y [ADR-002](ADR-002-arquitectura-hexagonal-en-el-nucleo.md) (variante ligera de `Workforce`) · `Workforce\Application\Port\EmployeeRepository`, `EloquentEmployeeRepository` · `UpdateEmployeeHandler`, `OffboardEmployeeHandler`, `ApplyEmployeeImport`, `PlanEmployeeImport`, `IssueEmployeePinHandler`, `ResetEmployeePinHandler`, `RecordPinDeliveryHandler` · listeners de `EmployeeOffboarded` (`Identity`, `Compliance`) |
| **Requisitos** | RN-14, RF-GP-01, RF-GP-03, RF-GP-05, RL-04, reglas duras 5 y 6 |

## Contexto

`UpdateEmployeeHandler` y `OffboardEmployeeHandler` leen la ficha con `findByUuid()`, sin candado, y
`EmployeeRepository::save()` reescribe **la fila entera**, `status` y `terminated_at` incluidos. Si una
modificación lee la ficha, la baja confirma y después escribe la modificación, la persona vuelve a
estar `active`, con `terminated_at` nulo, sin tarjeta, y en `audit_log` queda `employee.offboarded`
seguido de un `employee.updated` que no menciona el estado. Reproducido: 15 de 30 intentos por HTTP y
1 de 1 con el intercalado forzado (R4-BE-01). La importación pasa por el mismo caso de uso, así que
está igual de expuesta. Es una acción con relevancia legal (RN-14) sin traza fiel: la base contradice
al asiento.

El arreglo obvio —`lockForUpdate()` en la lectura— tiene dos trampas en este esquema:

1. **El orden de candados.** Toda escritura auditada pasa por el candado consultivo global de la
   cadena de `audit_log` (ADR-010), y el producto ya fija que se toma **antes** que las filas
   (`SerializedLedgerWrite`). Hoy la ficha se escribe primero y el asiento después: fila → cadena. Si
   la baja pasara a tomar la cadena primero y las operaciones del PIN siguieran tomando la fila
   primero, una baja y un restablecimiento de PIN simultáneos de la misma persona serían un abrazo
   mortal.
2. **El tipo de candado.** `employees` es la tabla más referenciada del esquema. Cada inserción en
   `scan_events`, `shift_entries`, `absences` o `credentials` comprueba su clave ajena con un
   `FOR KEY SHARE` sobre la ficha. `SELECT … FOR UPDATE` choca con ese candado: dejaría a los
   fichajes de esa persona esperando a la baja, y como el registro de una ausencia toma la clave
   ajena **antes** que la cadena, cerraría un ciclo con cualquier escritor de la ficha que tome la
   cadena primero.

## Decisión

### 1. Un solo orden de candados para la ficha

**Cadena de auditoría → fila de `employees` → filas de `credentials` → sesiones del portal.**

Todo caso de uso que **modifica una ficha que ya existe** y deja asiento envuelve su trabajo en
`SerializedLedgerWrite::withChainLock()` y, dentro, lee la ficha con bloqueo. Son estos, y ninguno
queda fuera:

| Caso de uso | Asiento |
|---|---|
| `UpdateEmployeeHandler` (y con él cada fila `update` de `ApplyEmployeeImport`) | `employee.updated` |
| `OffboardEmployeeHandler` (y, por su evento, la revocación de credenciales de `Identity`) | `employee.offboarded`, `credential.revoked` |
| `IssueEmployeePinHandler`, `ResetEmployeePinHandler`, `RecordPinDeliveryHandler` | `pin.issued`, `pin.delivered` |

El alta (`RegisterEmployeeHandler`) no lo necesita: inserta una fila que nadie más ve hasta el commit.
Los listeners de `EmployeeOffboarded` corren dentro de la misma transacción y heredan el orden: la
revocación toca `credentials` después de que la baja tenga la cadena y la ficha. El candado de la
cadena es reentrante (ADR-010), así que el asiento que se escribe después no espera.

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

Las dos llevan `WHERE uuid = ? AND status <> 'terminated'` y comprueban las filas afectadas: **cero
filas es `EmployeeAlreadyTerminated`** (`409`). El candado es lo que impide la carrera; el predicado es
lo que la convierte en un `409` honesto si algún día se escribe un camino que se salte la lectura
bloqueante. Es el mismo criterio que `EloquentAbsenceRepository` (`WHERE status = 'active'` y
recuento) tras el bloqueante de la 3.10.

Sin un método que escriba la fila entera, «una modificación de nombre reescribe el estado» deja de
poder escribirse.

### 4. La importación

`ApplyEmployeeImport` sigue siendo todo o nada en una transacción y sigue usando
`UpdateEmployeeHandler` por fila. Cada fila toma su ficha con `FOR NO KEY UPDATE` dentro de la
transacción del lote; la cadena ya la tiene el lote desde su primer asiento (y la pide `withChainLock`
de forma reentrante si la primera fila es una modificación). **No se bloquea por lotes**: tomar de
antemano cientos de filas exigiría un orden de adquisición propio, y la cadena ya serializa a todos los
escritores de la ficha.

Una fila que corresponde a una persona **dada de baja**:

- **Al comprobar** (`PlanEmployeeImport`), se rechaza con el código nuevo `employee_terminated`. La
  importación no modifica la ficha de una baja ni la da de alta otra vez (RN-14). Hoy esa fila llega a
  `update`, `updateProfile()` lanza `EmployeeAlreadyTerminated` y tumba la importación entera con un
  `409`: es un defecto que existía antes de la carrera.
- **Si la baja se registra entre la comprobación y la aplicación** del mismo fichero, la fila llega
  como `update`, la lectura bloqueante ve `terminated` y la aplicación entera responde `409` sin
  escribir nada. Es coherente con `ImportFileChanged`: se aplica **exactamente lo que se revisó**, y si
  el estado cambió, hay que volver a comprobar.

### 5. La invariante y su prueba

**Nunca queda en `audit_log` un `employee.offboarded` de una persona que no está `terminated`, ni una
persona `terminated` con una credencial activa, ni un `employee.updated` cuyo `changed_fields` no
describa el cambio que se confirmó.**

La atan tres pruebas de integración con `CommittedDatabase`, todas en `tests/Integration/Workforce/`:

1. **`EmployeeWriteLockTest`**, determinista, con dos sesiones y `NOWAIT`, como
   `AbsenceConcurrencyTest`: con la ficha tomada por `findForUpdate()`, otra sesión no puede tomarla
   (`55P03`) pero **sí** puede insertar una fila que la referencia (clave ajena). Esto fija el tipo de
   candado.
2. **`OffboardUpdateInterleavingTest`**, determinista, con un decorador del repositorio
   (`tests/Support/Workforce/InterleavingEmployeeRepository`, como `InterleavingDataExportRepository`
   del bloque 16) que, justo después de la lectura de la modificación (y, en el caso inverso, de la
   baja), ejecuta la otra escritura en una segunda conexión con `SET lock_timeout`. Con el código de
   hoy la segunda confirma y la persona termina `active`: **la prueba falla hoy**. Con la corrección,
   la segunda espera y caduca. Después se repite sin interferencia y se comprueba el estado final y
   los asientos. Cubre modificación contra baja, baja contra modificación e importación contra baja.
3. **`OffboardUpdateRaceTest`**, la de 30 rondas por HTTP (`ParallelRequests::runTasks`) que reprodujo
   el fallo: `PATCH` y `POST …/offboard` simultáneos, y una tercera tanda con restablecimiento de PIN
   contra baja. Las 30 terminan en `terminated`, sin credencial activa, con un solo
   `employee.offboarded` y **sin ningún `40P01`** (abrazo mortal). La invariante se comprueba con una
   consulta sobre `audit_log` y `employees`, no por las respuestas.

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
- **Un disparador que impida salir de `terminated`.** No hay ningún camino legítimo que lo haga (no
  existe la readmisión) y los dos únicos `UPDATE` que escriben `status` llevan el predicado. Se
  reconsidera si la 2.3.0 diseña la readmisión, que tendrá que decidir qué pasa con esa ficha.
- **Mantener fila → cadena para la ficha.** Funcionaría hoy, porque ningún camino que tiene la cadena
  bloquea después una ficha. Pero contradice el orden único que fija `SerializedLedgerWrite`, y el
  primer proceso de mantenimiento que tocara fichas bajo la cadena cerraría el ciclo.

## Consecuencias

- La cadena se toma unos milisegundos antes en cada escritura de la ficha: la lectura y un `UPDATE` de
  una fila. El candado ya se retenía desde el primer asiento hasta el commit, así que el tiempo que
  bloquea a los fichajes del hotel no cambia de orden de magnitud. La importación ya lo retenía entero.
- **Los tres casos de uso del PIN cambian en el mismo bloque**, aunque nadie los señaló: si la baja
  pasa a cadena → fila y el PIN sigue en fila → cadena, se introduce el abrazo mortal que este ADR
  existe para evitar.
- `save()` desaparece del puerto. Cualquier caso de uso nuevo que escriba la ficha tiene que elegir
  qué columnas escribe.
- Un `PATCH` sobre una ficha que se está dando de baja responde `409` en lugar de deshacer la baja.

## Verificación

- Las tres pruebas de §5, en verde en la CI (etapa de integración).
- Prueba de arquitectura: ningún fichero de `Modules/Workforce` llama a `lockForUpdate()` sobre
  `employees`, y `EmployeeRepository` no declara `save()`.
- `make test-unit`: del dominio, este ADR solo añade el caso `employee_terminated` a
  `ImportMessageCode` (un error, no un aviso), con su prueba unitaria.
