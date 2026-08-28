# ADR-037 — Qué lecturas de datos personales dejan asiento en `audit_log`

| Campo | Valor |
|---|---|
| **Estado** | Aceptada |
| **Fecha** | 27 de agosto de 2026 |
| **Decide** | `arquitecto-dominio` |
| **Afecta a** | `Shared/Application/Port/PersonalDataAccessLog` y sus llamantes · `Identity/Application/UseCase/CredentialStatusBoard` · `Workforce/Application/Query/EmployeeQueries` · `Compliance/Infrastructure/Persistence/DatabaseAuditTrail` |
| **Requisitos** | RS-05, RL-15, RL-05, RF-QR-08, RF-GP-01, RF-PA-03 |

## Contexto

RS-05 dice: *«Todo acceso a datos personales de terceros queda registrado en el trail de auditoría»*.
Es una frase sin matices y sin criterio operativo, y el resultado ha sido que **cada tarea de la Fase 1
decidió por su cuenta**:

- `GET /kiosk/roster` (1.7) audita. Divulga un hash y el nombre de pila con la inicial del apellido.
- `GET /employees/{uuid}/workdays` (1.16) audita. Divulga el registro horario de una persona.
- `GET /me/*` (1.11) no audita, con el argumento —correcto— de que no hay terceros.
- **`GET /credentials/status` (1.10) no audita.** Divulga nombre completo, código de empleado, centro
  y departamento de **toda la plantilla activa**, sin paginar.
- **`GET /employees` (1.6) no audita.** El mismo conjunto, paginado.

La incoherencia es difícil de defender ante un delegado de protección de datos: el endpoint que menos
divulga es el que deja constancia, y el que reparte el directorio completo del hotel no deja ninguna.
RL-15 exige *«capacidad técnica de determinar el alcance a partir de los logs de auditoría»* en 72
horas: para el conjunto de datos más completo que expone esta API, hoy no se puede. Un token
`credentials:*` comprometido —o una cuenta de RRHH que dejó de serlo— se lleva el directorio y no
queda una fila que lo diga.

Hay además una tensión real que impide resolverlo con «que audite todo»: `DatabaseAuditTrail` toma un
`pg_advisory_xact_lock` **global** y **cada fichaje pasa por ese mismo candado**. Auditar endpoints de
panel que se recargan a menudo mete escrituras en el camino del cambio de turno.

## Decisión

**Una lectura deja asiento cuando se cumplen las tres condiciones:**

1. Los datos son **de terceros** — no del propio sujeto que consulta.
2. **Salen del proceso** hacia una persona o un dispositivo. Calcular con ellos y no enseñarlos no es
   divulgar.
3. Lo leído es **un conjunto de personas**, o bien **el registro horario de una persona**.

Aplicada al producto:

| Lectura | ¿Asiento? | Por qué |
|---|---|---|
| `GET /kiosk/roster` | Sí | Conjunto, a un dispositivo |
| `GET /credentials/status` | **Sí (nuevo)** | Conjunto, y el más completo de la API |
| `GET /employees` (índice) | **Sí (nuevo)** | Conjunto |
| `GET /employees/{uuid}/workdays` | Sí | Registro horario de un tercero |
| `credentials:status` con tabla nominal | **Sí (nuevo)** | Conjunto, por una terminal |
| `credentials:status --quiet-table` (planificador) | No | Falla la condición 2: solo salen contadores |
| `GET /employees/{uuid}` (ficha) | No | Falla la condición 3 |
| `GET /me/*` | No | Falla la condición 1 |

**La ficha individual no audita, y no es un olvido.** Quien puede abrirla puede listar el índice, que
sí deja asiento: el trail ya dice que esa cuenta tuvo el directorio delante. Repetir el apunte por
cada ficha abierta llenaría `audit_log` con la operativa ordinaria de RRHH sin cambiar la respuesta a
la única pregunta que RL-15 obliga a poder contestar.

**`/me/*` no audita**, y esto zanja la duda que quedó abierta en la tarea 1.11. El literal de RS-05
dice «de **terceros**», y aquí no hay tercero: es el derecho del art. 34.9 ET y de RL-05. Un asiento
por cada consulta convertiría el ejercicio de un derecho en una traza de ese ejercicio, conservada
cuatro años y consultable por el empleador. Hay además una razón técnica que apunta igual: el catálogo
de actores de `audit_log` no tiene tipo para un empleado, así que el apunte saldría atribuido a
`system` —una entrada que miente en la tabla que se enseña en una inspección—.

**El asiento describe el alcance y jamás lo divulgado** (regla dura 21): dataset, número de registros
**entregados** —no los que casan con el filtro—, y los filtros aplicados. Nunca un nombre, ni un
código, ni la lista de `employee_uuid` de los afectados: enumerarlos sería una segunda copia de la
plantilla con cuatro años de retención, dentro de la tabla que más se protege.

**Sobre la contención: se mantiene un solo candado, y no es una decisión afinable.** La cadena de
ADR-010 es *una* secuencia y cada eslabón lleva el hash del anterior. Un candado por dataset dejaría a
dos escritores leer el mismo `prev_hash`, la cadena nacería bifurcada y la alerta crítica de RS-07
sonaría a diario por una rotura que nadie causó. Candados por dataset exigirían **cadenas** por
dataset: otro esquema y otro ADR. La contención se acepta con números: la sección crítica es un
`SELECT` por índice más un `INSERT` —milisegundos—, el tráfico de gestión de un hotel se cuenta en
peticiones por minuto y el pico de fichaje son 300 escaneos repartidos en un cuarto de hora. Si algún
día amenazara al p95 de RNF-P-02, la palanca es la **frecuencia** —agrupar lecturas idénticas del
mismo actor en una ventana— y cabe entera detrás del puerto `PersonalDataAccessLog`, sin tocar a
ninguno de sus llamantes.

## Alternativas descartadas

| Alternativa | Por qué se descarta |
|---|---|
| **Auditar toda lectura de datos personales, ficha incluida** | Llena el trail de operativa ordinaria y empeora justo lo que RL-15 quiere: acotar una brecha entre miles de apuntes rutinarios. No añade ninguna respuesta que el asiento del índice no dé ya |
| **Auditar también `/me/*`** | Convierte el ejercicio de un derecho legal en una traza de ese ejercicio, y obliga a inventar un tipo de actor o a atribuirlo a `system`, que sería falso |
| **Un candado consultivo por dataset** | Bifurca la cadena de hash de ADR-010 y convierte la verificación diaria de RS-07 en un falso positivo permanente. No es una granularidad menor: es otra estructura de datos |
| **Escribir el asiento de lectura fuera de la transacción, o en cola** | Rompe la garantía de la regla dura 6 —si la auditoría falla, la divulgación no ocurre— por un ahorro que las cifras no piden todavía |
| **Auditar solo el endpoint HTTP y no el comando de consola** | Sacar el directorio nominal por una terminal es la misma divulgación. Que el actor sea `system` es una limitación conocida del catálogo, no un motivo para no dejar constancia del hecho |

## Consecuencias

- `CredentialStatusBoard` y `EmployeeQueries::page()` reciben el puerto `PersonalDataAccessLog`. Los
  datasets nuevos son `credential_status` y `employee_directory`, en el vocabulario estable de la tabla.
- `CredentialStatusQuery` gana `unattended`, con valor por omisión `false`: **el caso que se asume es
  el que audita**, y quien quiera una lectura sin constancia tiene que pedirla explícitamente. Lo hace
  el planificador —`--quiet-table`— desde la misma variable que decide que no se pinte ningún nombre,
  para que las dos decisiones no puedan divergir.
- El apunte se escribe **antes** de devolver: si la escritura de auditoría falla, la divulgación no
  ocurre (regla dura 6, ADR-027). Consecuencia asumida y ya vigente para el padrón del quiosco: un
  `audit_log` averiado deja el panel sin abrir, que es preferible a repartir el directorio sin dejar
  constancia.
- El aviso que la pantalla de jornadas muestra al usuario —«esta consulta queda registrada»— pasa a ser
  cierto también en el panel de credenciales y en el listado de plantilla.
- Queda anotado para la Fase 2, cuando RS-05 es requisito de fase: si el producto decidiera dejar
  constancia de los accesos al portal, es un cambio del dominio de auditoría y de la restricción de su
  esquema —un tipo de actor nuevo—, no de un `if`.
  **Matiz (29 de agosto de 2026, ADR-039 / RS-13):** el rastro de la autenticación no es ese motivo.
  El acceso al portal es el ejercicio del derecho que esta decisión ya excluye de la traza, y el
  fichaje por PIN deja el `shift_entry.created` que lo prueba. El tipo `employee` se crea con la
  **primera acción de autoría del empleado con relevancia legal** —una solicitud de corrección desde
  el portal, una conformidad sobre el registro—, que es cuando `system` mentiría; nunca para sesiones.

## Verificación

- `tests/Feature/Identity/CredentialStatusDisclosureTest.php`: el endpoint deja asiento con dataset,
  recuento y filtros; el planificador con `--quiet-table` no deja ninguno; el comando con tabla
  nominal sí; el payload no contiene ni un nombre.
- `tests/Feature/Workforce/EmployeeDirectoryDisclosureTest.php`: el índice deja asiento y cuenta lo
  **entregado** (`per_page`), no lo que casa con el filtro; la ficha individual no deja ninguno.
- Sabotaje: quitar la llamada a `recordDisclosure` en cualquiera de los dos casos de uso debe hacer
  fallar su prueba de asiento, no solo la de contenido.
