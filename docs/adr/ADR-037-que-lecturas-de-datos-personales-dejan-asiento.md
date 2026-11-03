# ADR-037 — Qué lecturas de datos personales dejan asiento en `audit_log`

| Campo | Valor |
|---|---|
| **Estado** | Aceptada. Enmiendas del 18-10-2026 (búsqueda de la exportación legal), del 21-10-2026 (presencia por Reverb, cuentas de gestión e informes diferidos; [ver al final](#enmienda-del-21-de-octubre-de-2026-bloque-11-de-la-230)), del 27-10-2026 (contratos) y del 03-11-2026 (detalle de una baja médica y comprobación de una carga de ausencias) |
| **Fecha** | 27 de agosto de 2026 |
| **Decide** | `arquitecto-dominio` |
| **Afecta a** | `Shared/Application/Port/PersonalDataAccessLog` y sus llamantes · `Identity/Application/UseCase/CredentialStatusBoard` · `Workforce/Application/Query/EmployeeQueries` · `Workforce/Application/Query/AbsenceQueries` · `Compliance/Infrastructure/Persistence/DatabaseAuditTrail` |
| **Requisitos** | RS-05, RL-15, RL-05, RF-QR-08, RF-GP-01, RF-PA-03, RF-IN-05 (enmienda del 18-10-2026), RF-ID-10 y RF-IN-06 (enmienda del 21-10-2026), RF-GP-04 (enmienda del 03-11-2026) |
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

## Enmienda del 3 de noviembre de 2026 (bloque 34 de la 2.3.0): el detalle de una baja médica deja asiento

**Tarea 34.1; hallazgos V21-7-SC-2 y R4-QA-05.** El propietario activó la corrección el 18-10-2026 («hazlo como consideres mejor»). Sustituye a la aceptación anterior, que dejaba el detalle sin asiento a la espera de que el DPO del cliente lo pidiera.

**El problema.** `GET /absences/{uuid}` entrega la ausencia, su historial y, a `rrhh` y `admin`, la nota. Si es una baja médica, todo eso es dato de salud del art. 9 RGPD. El asiento del índice (`absence_register`) dice «miró el cuadro de marzo», pero no «abrió la baja de esta persona». Además, el detalle se puede pedir directamente por su `uuid` sin pasar por el índice. Ante la queja de un empleado no se podía contestar quién abrió su baja. Había además una vía sin ningún asiento: la comprobación de una carga de ausencias (`POST /absences/import` en modo `validate`) confirma, línea a línea, el tipo y las fechas de una baja ya guardada (revisión de seguridad de la 34.1).

**El argumento de la ficha no vale aquí.** La Decisión deja la ficha sin asiento porque quien la abre puede listar el índice, que ya lo deja. Con una baja médica, el índice no responde la pregunta que importa (art. 9 y 32 RGPD): de quién y qué. Una categoría especial pide saber quién la vio, no solo quién tuvo el conjunto delante.

| Lectura | ¿Asiento? | Cómo |
|---|---|---|
| `GET /absences/{uuid}` cuando la ausencia **o alguna de sus versiones anteriores** es `sick_leave` | **Sí (nuevo)** | `personal_data.accessed` con dataset `sick_leave_detail`, `record_count = 1` y contexto `{absence_uuid, employee_uuid, versions, has_note}` |
| `GET /absences/{uuid}` de cualquier otro tipo, sin bajas en su historial | No | Como la ficha: falla la condición 3 y el índice ya deja asiento |
| La relectura que sigue a un alta, una corrección o una anulación | No | Como el `find()` tras un `POST` de la enmienda del 21-10: ese hecho ya tiene su asiento (`absence.registered`, `absence.corrected`, `absence.voided`), que lleva el tipo |
| Una lectura denegada por el alcance | No | Deja `access.denied`, como hasta ahora |
| `POST /absences/import` (`validate` o `apply`) con alguna línea `unchanged` u `overlapping_absence` frente a una baja médica guardada | **Sí (nuevo)** | `personal_data.accessed` con dataset `sick_leave_import_match`, `record_count` = personas y contexto `{employee_uuids, file_sha256}`: los `uuid` ordenados y separados por comas, como `legal_export_employee_search`. Una línea igual a una baja confirma su tipo y sus fechas, y una que la pisa confirma que hay algo en esas fechas: probar combinaciones contestaría «cuándo estuvo de baja» sin rastro |

**El contenido del asiento.**

- **Es nominal:** lleva el `employee_uuid` y el `absence_uuid`. Es una persona, y sin su identificador el asiento no contesta «quién abrió mi baja». Nunca lleva el nombre de la persona.
- **`versions`** cuenta las versiones que salieron: la pedida más su historial.
- **`has_note`** dice si la respuesta llevó alguna nota. Es falso para el `responsable_departamento`, que no la recibe. La nota puede llevar un diagnóstico, así que el asiento dice si salió y nunca lo que decía (regla dura 21).
- **Cuenta todo el historial, no solo la versión pedida.** Una baja corregida a vacaciones sigue enseñando en su historial que fue una baja, y eso es lo que sale por la respuesta.

**Sin agrupación.** No entra en `compliance.disclosure_grouping`. Abrir una baja es un acto deliberado y poco frecuente, no un sondeo, y cada apertura es la respuesta que se busca. Por la misma razón el candado global de la cadena no preocupa: son lecturas de RRHH contadas por día.

**Dónde vive.** `AbsenceQueries::historyForDetail()` lee el historial y escribe el asiento antes de devolver: si `audit_log` falla, la baja no se enseña (regla dura 6). El controlador la llama **después** de comprobar el alcance, así que una denegación nunca deja `personal_data.accessed`. El de la carga lo escribe `PlanAbsenceImport` al terminar de planificar, antes de devolver el informe; nunca lleva las fechas.

**Lo que no cubre.**

- **El listado.** `GET /absences` también enseña el tipo de cada fila y, a `rrhh` y `admin`, la nota. Esta enmienda no lo cambia: su asiento (`absence_register`) es por bloque y solo nombra a la persona si se filtró por ella. La respuesta completa a «quién vio mi baja» es, por tanto, `sick_leave_detail`, `sick_leave_import_match` y los asientos `absence.*` de quien la escribió (exactos, consulta (a) del §4.1 del runbook de brecha), más los `absence_register` cuya ventana y alcance la contenían (consulta (b)). Si el propietario quisiera la garantía completa por persona, la decisión sería otra (la nota fuera del listado, o un asiento nominal por cada fila `sick_leave` entregada) y pediría otra enmienda.
- **La nota de una ausencia que nunca fue `sick_leave`** (un permiso `other` con texto libre) no dispara el asiento. El tipo es el marcador objetivo de la categoría especial. Una nota que cuente un dato de salud fuera de una baja es un uso del campo que la guía de RRHH ya desaconseja. Un asiento por contenido obligaría a leer la nota, que es justo lo que el asiento no debe hacer.

**Verificación** (RS-05, RL-15): `tests/Feature/Workforce/SickLeaveDetailDisclosureTest.php` y `SickLeaveImportMatchDisclosureTest.php`.

- La baja abierta por `rrhh` deja un asiento con la ausencia, la persona, `versions = 1` y `has_note = true`, y sin el texto de la nota.
- Unas vacaciones no dejan ninguno.
- Una ausencia que hoy es otro tipo pero fue una baja deja asiento con `versions = 2`, y con `has_note = true` si la nota solo estaba en la versión anterior.
- El responsable deja asiento con `has_note = false`.
- La lectura fuera de alcance deja `access.denied` y ningún `sick_leave_detail`.
- Con `audit_log` averiado, la baja no sale.
- La comprobación de una carga que confirma una baja, o que la pisa, deja `sick_leave_import_match` con la persona y la huella, sin fechas; si confirma unas vacaciones, no deja ninguno; con `audit_log` averiado, el informe no sale.
- `DisclosureDatasetsInventoryTest` obliga a tener la fila en el runbook de brecha, y `auditNotice.spec.ts` obliga a que la pantalla avise.
