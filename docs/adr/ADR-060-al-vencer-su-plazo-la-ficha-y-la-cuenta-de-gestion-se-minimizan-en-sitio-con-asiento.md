# ADR-060 — Al vencer su plazo, la ficha y la cuenta de gestión se minimizan en sitio, con asiento

| Campo | Valor |
|---|---|
| **Estado** | **Aceptado** por el propietario el 21-10-2026 (las ocho decisiones del diseño de las tareas 11.3 y 11.4 de la 2.3.0). Ajustado el 22-10-2026 tras las revisiones del bloque: motivo de revocación de la credencial, `locale` y `teleworking`, `DELETE` sobre `employees` y `users`, tope de la ejecución, frase de confirmación y foto huérfana. Lo implementa `backend-laravel` en la tarea 11.3 con la skill `migracion-segura`; la comprobación de la función, los *triggers* y los privilegios en `update.sh` y `doctor` es de `devops-observabilidad` |
| **Fecha** | 21 de octubre de 2026 |
| **Decide** | `seguridad-cumplimiento` (diseño del bloque 11 de la 2.3.0) y `arquitecto-dominio` (redacción). Cierra el residuo 4 de [ADR-051](ADR-051-cuentas-de-gestion-desde-el-panel-con-contrasenas-temporales.md) y el riesgo A-27 de doc 07 §6 |
| **Afecta a** | Amplía `RetentionScope` (dos ámbitos nuevos, `personnel_records` y `management_accounts`) · Aplica a `employees` y `users` el patrón de [ADR-057](ADR-057-el-registro-horario-no-se-borra-ni-se-reescribe-con-la-credencial-del-runtime.md) §2 y §3 (nota en ese ADR) · Nota en [ADR-020](ADR-020-soporte-con-paquete-de-diagnostico.md) (plazo del motivo de soporte en la tabla) y en [ADR-051](ADR-051-cuentas-de-gestion-desde-el-panel-con-contrasenas-temporales.md) (la cuenta se conserva como autora mientras quede un asiento suyo) · Respeta [ADR-010](ADR-010-auditoria-solo-append-encadenada.md), [ADR-027](ADR-027-audit-log-particionado.md), [ADR-033](ADR-033-tres-roles-de-base-de-datos-no-dos.md), [ADR-041](ADR-041-descarga-de-ficheros-diferidos-con-enlace-de-un-solo-uso.md) (precedente de `report_exports`), [ADR-042](ADR-042-el-runtime-no-tiene-credencial-que-pueda-alterar-el-registro.md), [ADR-046](ADR-046-la-ficha-del-empleado-se-escribe-bajo-candado-y-por-columnas.md) y [ADR-052](ADR-052-sesiones-con-token-bearer-de-sanctum-sin-cookies.md) · `compliance:apply-retention` (`ApplyRetention`) · `compliance_profiles` · `employees`, `users`, `employment_contracts`, `absences`, `support_grants`, `personal_access_tokens` · `routes/console.php` · `infra/scripts/update.sh`, `product:doctor` · `docs/api/openapi.yaml` (perfil de cumplimiento y `SupportGrant`) |
| **Requisitos** | RL-11, RL-02, RL-04, RL-08, RL-10, RS-05, RF-PR-03, RF-PD-07, RF-ID-10, RF-PD-11 · reglas duras 5, 6, 13, 14 y 21 |

## Contexto

RL-11 pide una retención **por tipo de dato**, y hasta la 2.3.0 el producto solo la tenía para cuatro: registro de jornada, auditoría, log técnico e histórico de errores (`RetentionScope`). Todo lo demás se conservaba para siempre:

- **La ficha de una persona que se fue** (`employees`): nombre, apellidos, correo, huella del DNI, PIN, foto y código. Ninguna purga la tocaba. Tampoco sus contratos ni sus ausencias, que incluyen dato de salud (art. 9 RGPD); `obligaciones-legales.md` §4 lo declaraba «pendiente de la asesoría».
- **La cuenta de gestión dada de baja** (`users`): nombre, correo y secretos. ADR-051 lo aceptó como residuo 4 («seudonimizarlas cuando ningún registro vivo las cite es una tarea de retención pendiente») y doc 07 lo lleva como A-27.
- **El motivo de una concesión de soporte** (`support_grants.reason`): texto libre que a veces nombra a una persona.
- **Los tokens de Sanctum caducados**: `routes/console.php` no programaba `sanctum:prune-expired`.

Con eso el producto incumple el art. 5.1.e del RGPD (limitación del plazo de conservación) por omisión.

**Borrar las filas no es posible.** Todas las dependencias de `employees` son `RESTRICT`: `credentials`, `shift_entries`, `scan_events`, `daily_totals`, `incidents`, `employment_contracts` y `absences`. Las de `users` también: `shift_corrections`, `credentials`, `absences`, `employment_contracts`, `support_grants.granted_by`, `incidents` y `employees.pin_delivered_by`. Son `RESTRICT` a propósito ([ADR-057](ADR-057-el-registro-horario-no-se-borra-ni-se-reescribe-con-la-credencial-del-runtime.md), migración `2026_10_14_100100`): que desaparezca quién fichó o quién firmó es un borrado de traza por la puerta de atrás.

**El precedente existe.** `report_exports` no se borra al vencer: pasa a `purged`, pierde lo que identificaba a personas y lo declara un `CHECK` (`report_exports_chk_purged_is_minimised`, [ADR-041](ADR-041-descarga-de-ficheros-diferidos-con-enlace-de-un-solo-uso.md)). La fila sigue contestando «¿existió?», y el dato personal ya no está.

## Decisión

**Al vencer su plazo, la ficha de la persona y la cuenta de gestión se minimizan en sitio: la fila se queda y pierde todo lo que identifica.** Lo hace una función `SECURITY DEFINER` que comprueba ella misma la elegibilidad, dentro de `compliance:apply-retention`, con un asiento por fila en la misma transacción. Un *trigger* congela la fila minimizada.

### Por qué no contradice la regla dura 5

La regla dura 5 («nada se borra ni se sobrescribe») protege la **verdad del registro** frente a la corrección: una corrección no puede hacer desaparecer la versión anterior. No se refiere a lo que pasa cuando **vence el deber de conservar**. Ese vencimiento ya tiene dos aplicaciones en el producto, las dos aceptadas:

- la purga del registro horario y de la auditoría (RF-PR-03, ADR-027, ADR-057 §3);
- la minimización de `report_exports`.

Esta es la tercera, y cumple las mismas condiciones:

- **Ocurre después del deber de conservar, nunca antes.** La ficha solo se minimiza cuando ya no queda ningún registro de jornada de la persona ni la partición de auditoría del año de su baja. La cuenta, cuando ya no queda ningún asiento ni ninguna corrección o resolución que la cite.
- **No sustituye una verdad por otra.** No se escribe un nombre distinto: se retira el dato. Lo que se conserva (uuid, centro, departamento, estado y fechas) sigue siendo cierto.
- **Ocurre una sola vez y es final.** Lo impone el *trigger*.
- **Deja constancia en `audit_log`** (regla dura 6), firmada por quien autoriza la purga.
- **Nunca es automática** (RF-PR-03).

### 1. Expediente de personal: ámbito `personnel_records`

**Es elegible si se cumplen las cuatro condiciones**, que comprueba la función SQL y no quien la llama:

1. `status = 'terminated'`.
2. `terminated_at ≤ hoy − personnel_record_retention_years`. El plazo es un **campo nuevo del perfil de cumplimiento** (regla dura 14): `smallint NOT NULL DEFAULT 4`, entre 1 y 30. «Hoy» es la fecha UTC del servidor de base de datos. La aritmética es la de `RetentionPolicy::workRecordCutoff`, con el 29 de febrero menos un año en el 1 de marzo.
3. **No queda ninguna fila suya en el registro horario**: ni en `shift_entries`, `scan_events`, `daily_totals`, `incidents` o `discarded_scan_reports`, ni en `shift_corrections` a través de sus tramos.
4. **Ya se ha soltado la partición de `audit_log` del año de `terminated_at`**, según el catálogo o `audit_chain_anchors`.

Con las condiciones 3 y 4, el plazo efectivo nunca es menor que el del registro (`retention_years`). Aunque alguien configure `personnel_record_retention_years` por debajo, la ficha sobrevive al registro y a la auditoría que la necesitan para ser legibles. La condición 4 se apoya en que ADR-027 suelta las particiones de la más antigua a la más reciente: soltada la del año de la baja, ya no queda ninguna de los años en que la persona trabajó.

**Qué se minimiza.** Lo declara el `CHECK employees_chk_anonymized_is_minimised`, que se cumple siempre que `anonymized_at` no es nulo:

| Columna | Queda |
|---|---|
| `first_name`, `last_name` | `''` |
| `email`, `national_id_hash`, `pin_hash`, `photo_path` y las columnas de provisión del PIN | `NULL` |
| `employee_code` | `replace(uuid::text, '-', '')`: sigue siendo único y ya no es el código impreso |
| `uuid`, `site_id`, `department_id`, `status`, `hired_at`, `terminated_at`, `locale`, `teleworking` | **Sin cambios** |

En la misma transacción:

- **se borran sus `personal_access_tokens` del portal**;
- **se borran sus contratos (`employment_contracts`) y sus ausencias (`absences`)**, por decisión del propietario. Su plazo termina con el de la ficha: son datos de la relación laboral y no registro horario (RL-02). Las ausencias llevan además el dato de salud, que es el que menos se puede conservar de más.

**`credentials` conserva su fila, pero pierde el motivo libre.** Su clave hacia `employees` es `RESTRICT`, la tarjeta está revocada desde la baja y la fila no lleva nombre; lo único que podía nombrar a alguien es `revoked_reason`, texto libre escrito al revocar, y en la misma transacción se sustituye por una constante del producto (decisión del propietario del 22-10-2026). **El fichero de la foto se borra después del `COMMIT`**: un fichero no entra en la transacción, y borrarlo antes dejaría una ficha con foto apuntando a nada si el lote se deshace.

Se conservan las fechas, el departamento y el centro porque el esquema los exige: `hired_at` no es nulo, y un `CHECK` obliga a que `terminated_at` exista con `status = 'terminated'` y no sea anterior a `hired_at`. Además, sin ellos la fila no diría nada de lo que se conserva. `locale` y `teleworking` también se conservan: no identifican por sí solos y la ficha los necesita para seguir siendo coherente (decisión del propietario del 22-10-2026). Todos ellos son **cuasi-identificadores**: en un hotel pequeño, «alguien de cocina que entró en marzo de 2019 y se fue en junio de 2022» se reconoce. Es un riesgo residual aceptado (doc 07 §6) y lo valida el DPO del cliente (§5).

### 2. Cuentas de gestión: ámbito `management_accounts`

**Una cuenta dada de baja es elegible si se cumplen las cuatro condiciones**, que comprueba la función:

1. `is_active = false`;
2. `deactivated_at ≤ hoy − retention_years`, con el mismo plazo que el registro, porque la cuenta es la autora de lo que firmó en él;
3. no queda ningún asiento de `audit_log` que la cite: ni como actor (`actor_type = 'user' AND actor_id = id`) ni como sujeto `user`;
4. no queda ninguna fila suya en `shift_corrections.performed_by_user_id` ni en `incidents.resolved_by_user_id`.

**`users` gana `deactivated_at`.** Hasta ahora la fecha de la baja solo constaba en el asiento `user.deactivated`. La migración la rellena desde el último `user.deactivated`, o desde `updated_at` si no hay asiento, y un `CHECK (is_active OR deactivated_at IS NOT NULL)` la exige. La baja la escribe. No hay reactivación (ADR-051 §1): si algún día la hubiera, tendría que devolver `deactivated_at` a `NULL`.

**Qué se minimiza** (con su `CHECK`):

| Columna | Queda |
|---|---|
| `name` | `''` |
| `email` | `'anonymized+' \|\| uuid \|\| '@invalid'`: única y no entregable (dominio reservado) |
| `password` | `'!'`: ningún hash casa con ella |
| `two_factor_*`, `remember_token`, `last_login_at`, `temporary_password_expires_at` | `NULL` |

En la misma transacción se borran sus tokens. Las claves que apuntan a la cuenta (`credentials`, `absences`, `employment_contracts`, `support_grants.granted_by`, `employees.pin_delivered_by`) se quedan apuntando a una fila que ya no identifica a nadie.

**Las cuentas activas sin uso no se minimizan.** Una cuenta activa es una decisión del cliente, y desactivarla por inactividad podría dejar la instalación sin administrador (ADR-051 §4). Para que alguien lo vea:

- se publica la métrica `management_accounts_dormant{role}`;
- `product:doctor` avisa cuando `last_login_at` (o `created_at`, si nunca entró) supera `IDENTITY_DORMANT_ACCOUNT_DAYS`, que vale 90 de serie y se configura en el `.env`.

### 3. Ejecución, dentro de `compliance:apply-retention`

- **La simulación semanal** cuenta las personas y las cuentas elegibles, sin uuids y sin nombres.
- **La ejecución** usa la misma frase de confirmación y la misma firma que la purga: `--responsible`, una cuenta activa con rol `admin` o `rrhh`. Nunca es automática (RF-PR-03).
- **El orden** es: después de soltar las particiones (`dropPartitions`), porque la condición 4 depende de ello, y antes del ciclo corto. Va en lotes, con una transacción por lote, bajo `withChainLock` y con la fila bloqueada ([ADR-046](ADR-046-la-ficha-del-empleado-se-escribe-bajo-candado-y-por-columnas.md)).
- **Cada lote lleva sus asientos.** Es lo que ADR-057 §3 exige a una purga partida en varias transacciones: si el asiento falla, el lote se deshace y no se minimiza nada.
- **La ejecución no minimiza más de lo que contó la simulación aprobada** (decisión del propietario del 22-10-2026). Soltar particiones en la misma pasada puede volver elegibles a más personas o cuentas (condición 4 y su equivalente en las cuentas); esas no se minimizan ahora, porque nadie las vio en el informe que se firmó, sino en la pasada siguiente, con su propia simulación. El recuento del plan es el tope de cada ámbito.
- **La frase de confirmación incluye el plazo de la ficha** (`personnel_record_retention_years` y su corte), además de lo que ya incluía. Si alguien cambia ese campo del perfil entre la simulación y la ejecución, la frase de ayer deja de valer.

### 4. La barrera está en la base de datos

Es el patrón de ADR-057 §2 y §3, aplicado a dos tablas más:

- **Funciones** `public.personnel_record_anonymize_batch(p_cutoff date, p_batch int) RETURNS TABLE(employee_uuid uuid, terminated_on date, contracts bigint, absences bigint, tokens bigint, photo_path text)` y `public.management_account_anonymize_batch(…)`. Son `SECURITY DEFINER`, propiedad del rol de migración, con `SET search_path = pg_catalog, pg_temp` y `lock_timeout`, y `EXECUTE` solo para `fichaje_app`. **Cada una calcula su corte máximo** a partir del perfil y rechaza uno posterior con `42501`. Aplican las cuatro condiciones de su ámbito: quien la llama no puede pedir que se minimice a una persona activa, a una que conserva tramos o a una cuenta que todavía firma asientos vivos.
- ***Triggers*** `employees_guard_anonymized` y `users_guard_anonymized`, `BEFORE UPDATE`. Responden `42501` a cualquier cambio en una fila con `anonymized_at`, y a cualquier cambio de `anonymized_at` si `current_user` no es el propietario. La aplicación no puede minimizar por fuera de la función ni deshacer una minimización.
- **Privilegios.** `fichaje_app` pierde `DELETE` sobre `employment_contracts` y `absences`: solo la función los borra. Antes hay que comprobar con `grep` que la aplicación no borra en esas tablas. **Tampoco tiene `DELETE` sobre `employees` ni `users`** (decisión del propietario del 22-10-2026): sin esa barrera, un `DELETE` seguido de un `INSERT` con el mismo uuid «desminimizaría» una fila sin pasar por el *trigger* de `UPDATE`.
- **A una ficha minimizada no se le añaden contratos ni ausencias.** Lo rechaza el dominio y lo rechaza un *trigger* `BEFORE INSERT` sobre `employment_contracts` y `absences`: la relación laboral terminó y sus datos ya se borraron con la ficha.
- **`update.sh` y `product:doctor`** verifican la función, los *triggers* y los privilegios, igual que con `work_record_purge_batch`.
- **Los `down()`** de las migraciones se niegan a ejecutarse si hay alguna fila con `anonymized_at`: deshacer el esquema no devuelve los datos y dejaría filas minimizadas sin el `CHECK` que lo declara.

### 5. Asientos

| Ámbito | Acción | Actor | Sujeto | Payload |
|---|---|---|---|---|
| `personnel_records` | `retention.personnel_record_anonymized` (familia `RetentionPurge`) | El firmante (`user`) | `employee`, sin id; el uuid va en el payload, como en todos los `employee.*` | `employee_uuid`, `terminated_on`, `cutoff_date`, `personnel_record_retention_years`, `retention_years`, `retention_floor_years`, `jurisdiction`, `site_id`, `fields`, `contracts_deleted`, `absences_deleted`, `tokens_deleted` y `confirmation` |
| `management_accounts` | `retention.management_account_anonymized` (familia `RetentionPurge`) | El firmante (`user`) | `user`, por id | `user_uuid`, `deactivated_on`, `cutoff_date`, `retention_years`, `fields`, `tokens_deleted` y `confirmation` |

**Ninguno lleva nombre, código ni correo** (regla dura 21): un asiento de minimización con el dato minimizado sería una copia con cuatro años de vida en la tabla que no se puede tocar.

### 6. El motivo de soporte y los tokens caducados

- **`support_grants.reason`** se vacía cuando han pasado `PRODUCT_SUPPORT_GRANT_REASON_RETENTION_DAYS` desde `COALESCE(revoked_at, expires_at)`. Vale 90 de serie, con un mínimo de 30. Es automático y diario, por `ScheduledTaskOutput::toLog`, y **sin asiento**: la copia con valor probatorio, la del asiento `support_grant.granted`, no se toca y vive lo que vive la auditoría (nota en [ADR-020](ADR-020-soporte-con-paquete-de-diagnostico.md)). La columna pasa a admitir nulos y gana `reason_purged_at`, con dos `CHECK`: `(reason IS NULL) = (reason_purged_at IS NOT NULL)` y `reason_purged_at > COALESCE(revoked_at, expires_at)`. Para que el motivo no lleve datos personales desde el principio, el formulario y `support:grant` avisan de que no se escriban nombres, sino uuids, y un motivo con forma de correo, DNI/NIE o teléfono se rechaza con `422`. El contrato cambia antes que el código: `SupportGrant.reason` admite nulos y aparece `reason_purged_at`.
- **Los tokens de Sanctum caducados** se borran con `sanctum:prune-expired --hours=24` cada día a las 03:40 UTC, con `withoutOverlapping()->runInBackground()`, sin asiento. Las 24 h de margen respetan el solape de rotación del token del quiosco ([ADR-044](ADR-044-el-token-del-quiosco-rota-en-el-latido-con-solape.md)). Los tokens antiguos con `expires_at` nulo no caducan nunca: se cuentan, y o una migración les pone caducidad o `doctor` avisa.

### 7. Lo que valida el cliente

**Los plazos los fija el cliente, no el fabricante.** Hay dos:

- `personnel_record_retention_years`, que vale 4 de serie. Es orientativo, por referencia al art. 21 de la LISOS.
- `PRODUCT_SUPPORT_GRANT_REASON_RETENTION_DAYS`.

**Los cuasi-identificadores que quedan en la ficha minimizada** (fechas de alta y baja, departamento y centro) también los valida el cliente. Las dos cosas las deciden su asesoría laboral o su DPO, y así lo dicen `obligaciones-legales.md` §4 y doc 07 §6.

Contratos y ausencias tienen **un solo plazo**, el de la ficha. Si la asesoría fija uno distinto (por ejemplo, más corto para la baja médica), el producto no lo distingue hoy: es una petición al fabricante.

## Alternativas descartadas

| Alternativa | Por qué se descarta |
|---|---|
| **Borrar las filas** de `employees` y `users` | Las claves `RESTRICT` lo impiden, y están ahí a propósito (ADR-057): que desaparezca quién fichó o quién firmó es perder traza. Cambiarlas a `CASCADE` o `SET NULL` borraría `credentials` o vaciaría autorías que el registro necesita |
| **No hacer nada** y conservar la ficha y la cuenta para siempre | Choca con el art. 5.1.e del RGPD. Conservar de más también es incumplir, y `obligaciones-legales.md` §4 ya se lo dice al cliente. Además, el producto no puede pedirle al cliente que purgue lo que él mismo no sabe purgar |
| **Seudonimizar con una tabla de correspondencia** (uuid → nombre) aparte | La tabla es otra copia del dato con otro nombre. Si el deber de conservar ha vencido, ya no hay nada que poder reidentificar |
| **Minimizar al dar de baja**, o cuando la persona lo pide | El registro y la auditoría de esos cuatro años la nombran, y sin la ficha dejarían de ser legibles ante la Inspección (RL-02, art. 17.3.b RGPD). La supresión a petición se contesta con la fecha a partir de la cual procede (runbook `solicitud-derechos-rgpd.md`) |
| **Minimizar automáticamente**, sin confirmación | RF-PR-03: la purga nunca es automática. Un plazo mal puesto se descubriría después de haber destruido datos |
| **Usar `retention_years` también para la ficha**, sin campo nuevo | El plazo de la ficha no tiene por qué ser el del registro: depende de la normativa laboral y fiscal, y lo decide la asesoría (regla dura 14: es un umbral legal y vive en el perfil). Con las condiciones 3 y 4 nunca puede quedar por debajo del registro |
| **Un `UPDATE` de la aplicación, sin función ni *trigger*** | Con la credencial del runtime se podría minimizar a cualquiera antes de tiempo, o «desminimizar» una fila, sin que nada lo impidiera. Es el mismo argumento que llevó a ADR-057 §3 |
| **Minimizar las cuentas activas sin uso** | Es una decisión del cliente, y podría dejar la instalación sin `admin` (ADR-051 §4). Se avisa, pero no se actúa |

## Consecuencias

- **`RetentionScope` deja de ser de cuatro ámbitos.** El informe de retención, la simulación y `AuditActionCatalogTest` (familia `RetentionPurge`) recogen los dos nuevos. El docblock que decía «`employment_contracts` no está aquí» queda superado: los contratos se van con la ficha.
- **Una ficha minimizada sigue apareciendo** en el directorio y en la exportación íntegra, sin nombre y con el código sustituido. El portal da el rechazo genérico (RS-03) a quien pruebe el código antiguo.
- **Pueden quedar asientos posteriores al año de la baja que citen el uuid de una persona minimizada.** Por ejemplo, una búsqueda de la exportación legal (ADR-037), que lleva `employee_uuids`. Desde la minimización, ese uuid ya no lleva a ningún nombre. Es lo que se busca: el asiento sigue diciendo cuántas personas y cuáles por uuid, y la persona ha dejado de ser identificable por el producto.
- **La conciliación no ve todavía una minimización hecha por fuera.** Se recomienda, en otro bloque, que ADR-057 §4 cuente como discrepancia una ficha con `anonymized_at` sin su asiento, o un asiento sin su ficha minimizada. Mientras tanto, el *trigger* y la función impiden la minimización fuera de plazo, pero no una llamada a la función fuera de `compliance:apply-retention`, que no deja asiento. Es el mismo residuo que `work_record_purge_batch` (ADR-057, «Lo que esto no impide»).
- **Si el borrado del fichero de la foto falla después del `COMMIT`**, queda un fichero que ninguna fila cita. La función devuelve la ruta para que la aplicación borre el fichero; si el borrado falla, queda visible en el evento de log técnico `retention.personnel_photo_orphaned`, con el `employee_uuid` y sin la ruta ni el nombre, y en una observación del informe de la purga. No hay alerta propia: hoy el producto no escribe fotos, y la ruta solo existiría en instalaciones que las hubieran cargado por fuera.
- **Las copias de seguridad conservan lo minimizado** hasta que caducan (`BACKUP_RETENTION_DAYS`). Es la misma doctrina que para la purga del registro (runbook `solicitud-derechos-rgpd.md` §1).
- **Riesgos de doc 07 §6.** A-27 se cierra con este ADR. Se abre una fila para los cuasi-identificadores residuales, que valida el DPO del cliente.

## Verificación

Pruebas etiquetadas con RL-11, RL-02, RL-04, RS-05, RL-08 y RL-15.

- **Unitarias:**
  - el corte de `personnel_records` con 29 de febrero, con la misma aritmética que `RetentionPolicy::workRecordCutoff`;
  - el corte de las cuentas es el del registro (la elegibilidad la decide la función SQL y se prueba en integración);
  - la frase de confirmación cambia si cambia el plazo de la ficha;
  - `RetentionScope` y `AuditActionCatalogTest` (familia `RetentionPurge`).
- **Integración:**
  - los `CHECK` de minimización;
  - la fila congelada (`42501`) y `fichaje_app` sin poder escribir `anonymized_at`;
  - la función rechaza un corte posterior y se salta a quien conserva tramos, a quien está activo o suspendido y a quien tiene viva la partición del año de su baja;
  - `EXECUTE` solo para `fichaje_app`, y una tabla temporal no desvía la función;
  - la función de cuentas se salta las que tienen asientos vivos;
  - `DELETE` en contratos y ausencias da `42501` con `fichaje_app`;
  - `DELETE` en `employees` y `users` da `42501` con `fichaje_app`, y un contrato o una ausencia nuevos sobre una ficha minimizada se rechazan;
  - `down()` se niega a ejecutarse con filas minimizadas;
  - el relleno de `deactivated_at`.
- **Feature:**
  - `compliance:apply-retention`: la simulación cuenta sin nombres; la ejecución no minimiza más de lo que contó la simulación; la ejecución minimiza y escribe un asiento por persona; si el asiento falla, no se minimiza nada; solo firman `admin` o `rrhh`;
  - el directorio y la exportación íntegra muestran la ficha minimizada, y el portal da el rechazo genérico con el código antiguo;
  - motivo de soporte: se purga solo pasado el plazo, da `422` con los patrones, y tiene contrato y autorización negativa;
  - `sanctum:prune-expired` (`ScheduledTaskOutputTest`) borra lo caducado hace más de 24 h y conserva los tokens en solape y los vivos.
