# ADR-037 — Qué lecturas de datos personales dejan asiento en `audit_log`

| Campo | Valor |
|---|---|
| **Estado** | Aceptada. Enmiendas del 18-10-2026 (búsqueda de la exportación legal) y del 21-10-2026 (presencia por Reverb, cuentas de gestión e informes diferidos; [ver al final](#enmienda-del-21-de-octubre-de-2026-bloque-11-de-la-230)) |
| **Fecha** | 27 de agosto de 2026 |
| **Decide** | `arquitecto-dominio` |
| **Afecta a** | `Shared/Application/Port/PersonalDataAccessLog` y sus llamantes · `Identity/Application/UseCase/CredentialStatusBoard` · `Workforce/Application/Query/EmployeeQueries` · `Compliance/Infrastructure/Persistence/DatabaseAuditTrail` |
| **Requisitos** | RS-05, RL-15, RL-05, RF-QR-08, RF-GP-01, RF-PA-03, RF-IN-05 (enmienda del 18-10-2026), RF-ID-10 y RF-IN-06 (enmienda del 21-10-2026) |
| **Enmiendas** | Matizada por [ADR-041](ADR-041-descarga-de-ficheros-diferidos-con-enlace-de-un-solo-uso.md): cada descarga de un fichero diferido deja su asiento `report_export.downloaded` (referencia de vuelta añadida el 27-10-2026) |

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
| Búsqueda de la persona de la exportación legal (`POST /reports/legal-export/employees/search`, enmienda del 18-10-2026) | Sí | Conjunto —hasta 10 personas—, y la hace un rol que no tiene el directorio |

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

**Enmienda (18 de octubre de 2026, V21-3-PA-1, RF-IN-05).** La exportación para la Inspección se
pide por persona eligiéndola por su nombre o su DNI, con una búsqueda que alcanza `reports:legal`.
Cumple las tres condiciones —terceros, sale hacia una persona, un conjunto de hasta 10— y **deja
asiento**, y aquí el argumento de la ficha individual no vale: el `auditor` no puede listar el índice,
así que ningún otro asiento dice que tuvo esas personas delante. El asiento es
`personal_data.accessed` con el dataset `legal_export_employee_search`, el número de personas
**devueltas**, el criterio (`name_or_code` o `national_id`), si la lista se recortó y
`employee_uuids`, los `uuid` de las como mucho diez personas devueltas separados por comas —como
`incident_digest`—, que se omite cuando no hay ninguna. Es un conjunto pequeño, así que es nominal
por el mismo criterio que `report_export.*`: sin los `uuid` el trail diría cuántas personas vio el
auditor, pero no cuáles, y esa es la pregunta que RL-15 obliga a contestar. **Nunca lleva el término
buscado**: un nombre o un DNI en `audit_log` serían el dato personal que esta decisión
prohíbe copiar (regla dura 21), y un DNI en claro, además, el documento que RL-08 no deja guardar.
Una búsqueda sin resultados también deja asiento, con recuento cero: probar documentos hasta acertar
es justo lo que el trail tiene que poder enseñar.

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

## Enmienda del 21 de octubre de 2026 (bloque 11 de la 2.3.0)

**Tarea 11.4; hallazgos R4-BE-05, V4-BE-4 y V4-BE-5.** Diseño de `seguridad-cumplimiento`, aprobado por el propietario el 21-10-2026. **El criterio de las tres condiciones no cambia**: se aplica a tres lecturas que lo cumplían y no dejaban asiento, y a una que lo dejaba a nombre de quien no era.

| Lectura | ¿Asiento? | Cómo |
|---|---|---|
| Suscripción al canal de presencia por Reverb (`broadcasting/auth`) | **Sí (nuevo)** | Conjunto, hacia una persona, y en tiempo real. `personal_data.accessed` con dataset `live_presence_stream`, `record_count = 0` y contexto `{channel: 'all'\|'department', department_id?, scope: 'all'\|'departments'}` |
| `GET /management-accounts` | **Sí (nuevo)** | Conjunto de personas con su correo, su rol y el estado de su segundo factor. Dataset `management_accounts`, `record_count` = filas **entregadas**, contexto `{status?, role?, search: bool, page, per_page}` |
| `identity:list-users` | **Sí (nuevo)** | La misma divulgación por una terminal, con actor `system`, como `credentials:status` |
| Informe generado en diferido (RF-IN-06) | Sí, **a nombre de quien lo pidió** | Antes lo escribía el *worker* con actor `system` |
| `GET /departments` | No | Ver abajo |
| `GET /support/grants` | No | Ver abajo |

**Presencia por Reverb.** Hasta ahora, `routes/channels.php` autorizaba con la *policy* de `PresenceBoard` y no llamaba a `PersonalDataAccessLog`: la vista que se sondea cada 15 s dejaba asiento (`live_presence`), y la misma información empujada por WebSocket no. Ahora:

- **Qué se audita.** La autorización se saca a un autorizador de `Reporting` que usa el puerto, y el asiento se escribe **antes** de devolver `true`. Si el asiento falla, la suscripción no se concede (regla dura 6).
- **Por qué `record_count = 0`.** Al suscribirse todavía no se ha entregado nada: el asiento dice quién tuvo el canal abierto y con qué alcance, no cuántos eventos recibió.
- **Agrupación.** El dataset entra en `compliance.disclosure_grouping.datasets`, con la misma ventana de 900 s que `live_presence`, porque cada reconexión del panel vuelve a autorizar. `GroupedPersonalDataAccessLog::keyFor` añade `channel` a la clave cuando viene en el contexto: dos canales distintos son dos alcances distintos y no se funden en un asiento.
- **Denegación.** Una suscripción denegada por el ámbito deja `access.denied`, agrupado por `GroupedAuthorizationJournal`, y nunca `personal_data.accessed`.

**`GET /management-accounts` y `identity:list-users`.**

- `ManagementAccountsQuery::page` escribe el asiento antes de devolver, sin agrupar.
- **Nunca lleva el término buscado**, solo si hubo búsqueda (`search: bool`), por la misma razón que la búsqueda de la exportación legal.
- **El `find()` que sigue a un `POST`** (alta, baja o restablecimiento) no deja asiento de lectura. Devuelve la cuenta sobre la que se acaba de actuar, y ese hecho ya tiene su asiento (`user.created`, `user.deactivated`…).

**Informes diferidos.** `GeneratePeriodReport::handle` escribía `personal_data.accessed` dentro del *worker*, con actor `system`. Es la «entrada que miente» que esta decisión ya descartó para `/me/*`: quien se lleva el informe es la persona que lo pidió. Ahora:

- **Cómo se calcula.** `GenerateReportExportHandler` usa `GeneratePeriodReport::compose()`, que calcula el asiento sin escribirlo, como `SendWeeklySummaries`.
- **Cómo viaja.** El evento `ReportExportGenerated` lleva `disclosureDataset`, `disclosureRecordCount` y `disclosureContext`.
- **Dónde se escribe.** `RecordReportExportLifecycle::generated` escribe dos asientos en la transacción de `completeAndPublish`: `report_export.generated` y `personal_data.accessed`, este con actor `user(requestedByUserId)` y contexto `{...disclosure, report_export_uuid, deferred: true}`.
- **Si la generación falla**, no hay asiento de lectura, porque no se ha divulgado nada.
- **El resumen semanal no cambia.**

**Lo que sigue sin asiento, y por qué.**

- **`GET /departments`.** Divulga la estructura organizativa: cada departamento y el nombre de su responsable. Lo leen los cuatro roles de gestión para trabajar (ADR-051, A-27 de doc 07). No es un conjunto de la plantilla, sino de cargos, y un asiento por cada carga del panel llenaría el trail con la operativa ordinaria, que es lo que la primera alternativa descartada evita.
- **`GET /support/grants`.** Solo lo ve `admin`. Lo que enseña (quién concedió, cuándo, con qué alcance y por qué) ya está en `audit_log`, en `support_grant.granted` y `support_grant.revoked`, visible para el cliente (ADR-020). El motivo, el único texto libre que podría nombrar a alguien, se vacía en la tabla a los 90 días ([ADR-060](ADR-060-al-vencer-su-plazo-la-ficha-y-la-cuenta-de-gestion-se-minimizan-en-sitio-con-asiento.md) §6). Un asiento por lectura no añadiría ninguna respuesta a RL-15.

**Verificación** (RS-05, RL-15):

- **Presencia:**
  - asiento con canal y alcance, sin nombres;
  - tres suscripciones en la ventana dan una fila, y dos canales dan dos;
  - una denegación da `access.denied` y no `accessed`;
  - con `audit_log` averiado no hay suscripción;
  - `403` por rol.
- **Cuentas:**
  - el listado deja asiento con el recuento y `search` booleano;
  - el `POST` no deja asiento de lectura;
  - la orden de consola sí lo deja;
  - quitar la llamada a `recordDisclosure` pone la prueba en rojo.
- **Diferido:**
  - el asiento va a nombre de quien lo pidió, con `report_export_uuid` y en la misma transacción;
  - ningún `accessed` con actor `system` en los diferidos que tienen solicitante;
  - un fallo no deja asiento;
  - el resumen semanal no cambia.

## Enmienda del 27 de octubre de 2026 (bloque 20 de la 2.3.0): los contratos son parte de la ficha

**Decisión D20 del plan de la 2.3.0, aprobada por el propietario con su recomendación.** La tabla de la Decisión decía que la ficha individual (`GET /employees/{uuid}`) no deja asiento, pero no decía qué pasa con los contratos de esa persona. **Los contratos forman parte de la ficha** y tampoco dejan asiento:

| Lectura | ¿Asiento? | Por qué |
|---|---|---|
| `GET /employees/{uuid}/contracts` | No | Falla la condición 3, como la ficha: es una persona y no su registro horario |

- **Por qué es la misma lectura.** Un contrato son las horas semanales y anuales, el tipo de jornada y su vigencia (RF-GP-01). Son datos laborales del mismo nivel que la ficha y no son una categoría especial. Los lee quien ya puede abrir la ficha (`rrhh+`), y quien puede abrir la ficha puede listar el índice, que sí deja asiento. El argumento de la Decisión vale igual.
- **Lo que no cambia.** Escribir un contrato sigue dejando su asiento. Un conjunto de contratos que salga del proceso, como el informe de horas trabajadas frente a las contratadas, sí deja asiento por la condición 3, con el informe del que forma parte.
- **Lo que no cubre.** Las **ausencias** no entran en esta enmienda. Pueden llevar un dato de salud (ADR-060), y si su lectura individual debe dejar asiento es otra pregunta.
- **Así está hoy el código.** `EmploymentContractController::index` no llama al puerto `PersonalDataAccessLog`. Esta enmienda escribe la decisión, no cambia el comportamiento.
