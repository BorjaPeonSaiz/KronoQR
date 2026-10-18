# ADR-056 — La zona horaria del centro queda fija desde el primer fichaje, y es la del histórico

| Campo | Valor |
|---|---|
| **Estado** | **Implementada en la 2.2.1** (bloque 20). Aceptada por el propietario el 08-10-2026. La regla es RN-23 del doc 01 §4. `PATCH /site` responde `409` (`urn:kronoqr:problem:site-time-zone-locked`) en cuanto hay registro horario, y `Site.timezone_locked` lo anuncia. La corrección es la orden `workforce:correct-site-timezone`, con motivo, cuenta responsable, confirmación y asiento. El portal ya no tiene la rama `otherTimeZone`. Dos precisiones a lo decidido, en la [nota de la implementación](#nota-15-10-2026-221-bloque-20-implementada). **Desde la 2.3.0 (bloque 2) la regla también vive en el motor**: *trigger* `zz_sites_guard_timezone` y función `SECURITY DEFINER` `site_correct_timezone`, con una traza solo-append de cada cambio y la conciliación cotejando la zona y la traza con la auditoría ([enmienda del 18-10-2026](#enmienda-del-18-10-2026-230-bloque-2-la-regla-también-en-el-motor)). **Desde la 2.3.0 (bloque 3) firma una cuenta `admin` o `rrhh` nombrada por su `uuid` o su correo**, que la orden enseña antes de confirmar ([nota del 18-10-2026](#nota-18-10-2026-230-bloque-3-quién-firma-la-corrección)) |
| **Fecha** | 8 de octubre de 2026 |
| **Decide** | `arquitecto-dominio` (bloque 14 de la 2.2.0, hallazgo R6-AR-02 de la [re-verificación](../verificacion/2.2.0-reverificacion-tandas-5-6-7.md), que registra como decisión pendiente el F3 de la tanda 4) |
| **Afecta a** | Enmienda [ADR-040](ADR-040-un-centro-por-instalacion-y-por-licencia.md) (punto 3: el `PATCH /site` ya no cambia la zona en cualquier momento) · Precisa [ADR-004](ADR-004-utc-en-almacenamiento.md) (de dónde sale la zona de presentación de un instante pasado) · Respeta [ADR-006](ADR-006-los-turnos-no-se-parten-a-medianoche.md), [ADR-012](ADR-012-api-versionada-en-la-ruta.md) y [ADR-035](ADR-035-la-correccion-estrena-identificador-y-no-cambia-de-jornada.md) · `backend/app/Modules/Workforce/Application/UseCase/UpdateSiteHandler.php`, `Workforce/Domain/Model/Site.php` · `Reporting/Infrastructure/Persistence/DatabaseWorkDayJournalReader.php`, `Reporting/Http/Resource/EmployeeWorkDaysResource.php` · `Compliance/Infrastructure/Persistence/DatabaseLegalExportSource.php` · `frontend-portal/src/features/my-records/ShiftEntryTable.vue` · `docs/api/openapi.yaml` (`PATCH /site`, `time_zone` por jornada y por tramo) |
| **Requisitos** | RN-04, RN-05, RN-09, RF-PD-03 · reglas duras 3, 4 y 5 |

## Contexto

Un tramo guarda sus instantes en UTC (regla dura 3) y su `work_date`, que se calculó con la zona que tenía el centro **al fichar** (RN-05). **No guarda esa zona.** Toda lectura (diario de jornada, portal, informes y exportación para Inspección) la toma de `sites.timezone` **en el momento de leer** (`DatabaseWorkDayJournalReader`, con un `JOIN sites`).

ADR-040 deja cambiar la zona del centro con `PATCH /site`, auditado como `site.updated`, y `UpdateSiteHandler` no lo impide aunque ya haya fichajes. Si se cambia, el histórico se repinta con la zona nueva mientras `work_date` conserva la atribución antigua. Un turno nocturno puede salir con una entrada en un día civil que no es el de su jornada, y la exportación legal sale incoherente con RN-05. ADR-004 rechaza guardar la hora local para no tener «dos fuentes de verdad», pero no decide qué zona representa un tramo del pasado. La re-verificación de la 2.2.0 lo registró como R6-AR-02, una decisión que falta.

Además, la API devuelve una `time_zone` por jornada y por tramo, y el portal tiene una rama para el «tramo fichado en otra zona» (`otherTimeZone` en `ShiftEntryTable.vue`). Son residuos del modelo multicentro, anterior a ADR-040: las dos zonas salen del mismo `JOIN` a la única fila de `sites`, así que esa rama no se ejecuta nunca.

## Decisión

### 1. La zona de todo el histórico es la del centro, y hay una sola

Con un centro por instalación (ADR-040), **la zona con la que se atribuye, se presenta y se exporta cualquier tramo, pasado o presente, es `sites.timezone`**. No se guarda una zona por tramo: sería la segunda fuente de verdad que ADR-004 rechaza, y con un único centro siempre valdría lo mismo.

### 2. La zona queda fija en cuanto existe el primer tramo

**Mientras no haya ningún tramo en `shift_entries`, en cualquier estado, la zona se cambia libremente** desde el asistente de puesta en marcha o con `PATCH /site`. **En cuanto exista uno, `PATCH /site` con una zona distinta de la actual responde `409`** con un código propio, y no deja asiento porque no cambia nada. El nombre del centro sigue siendo editable. Un hotel no cambia de zona horaria: cambiarla con fichajes hechos solo puede ser corregir un error de la puesta en marcha o un error de manos.

La comprobación vive en el caso de uso (`UpdateSiteHandler`), que pregunta a un puerto si ya hay registro horario, **dentro de la transacción y con la fila del centro bloqueada**. Así, un primer fichaje concurrente no la burla. El dominio (`Site::relocateTo`) recibe ese hecho ya resuelto, igual que recibe los umbrales (regla dura 14).

### 3. Corregir un error de puesta en marcha es una acción de consola, explícita y con consecuencias escritas

Si la instalación se puso en marcha con una zona equivocada y ya hay fichajes, la zona se corrige **solo por consola**, en el servidor, con motivo obligatorio y asiento `site.updated` con ese motivo y el número de tramos existentes. **No reescribe nada** (regla dura 5): `work_date` conserva la atribución con la que se fichó, y el histórico pasa a presentarse con la zona nueva. Si la zona antigua era la equivocada, eso es lo correcto para los instantes. Lo que se acepta es que un tramo fichado cerca de medianoche con la zona mala puede quedar en una jornada que, con la zona buena, habría sido otra. Corregirlo, si alguien lo necesita, es una corrección de tramo (RN-13) con su motivo, no un efecto colateral del cambio de zona.

### 4. La API conserva `time_zone` por jornada y por tramo; el portal pierde la rama muerta

Quitar un campo de una respuesta de `v1` es un cambio incompatible (ADR-012), y el campo no miente: siempre vale la zona del centro. **Se conserva**, y el contrato lo documenta así. La rama `otherTimeZone` del portal se retira, porque es código que no se ejecuta y sugiere un modelo que el producto no tiene.

## Alternativas descartadas

| Alternativa | Por qué se descarta |
|---|---|
| **Guardar la zona en cada tramo** (`shift_entries.time_zone`) | Con un único centro, todos los tramos tendrían el mismo valor que `sites.timezone` salvo después de un cambio, que es justo lo que este ADR impide. Es una columna nueva en la tabla del registro legal, más una migración de relleno, para un caso que se resuelve prohibiéndolo. Y es la «columna paralela» que ADR-004 rechaza |
| **Aceptar el repintado y escribirlo como riesgo** | La exportación para Inspección saldría incoherente con RN-05 tras un cambio de zona, y nadie lo notaría hasta que alguien comparara dos exportaciones |
| **Recalcular `work_date` de todo el histórico al cambiar la zona** | Reescribe el registro legal (regla dura 5) y mueve jornadas de día, que ADR-035 prohíbe incluso en una corrección expresa |
| **Prohibir el cambio sin ninguna salida** | Una instalación puesta en marcha con la zona equivocada quedaría presentando mal todos sus fichajes para siempre, y arreglarlo exigiría tocar la base a mano, que es lo que la regla dura 13 dice que nunca debe hacer falta |

## Consecuencias

- **ADR-040, punto 3, queda enmendado** (enmienda al final de ese ADR): el `PATCH /site` cambia la zona solo mientras no hay registro horario.
- **Bloqueante para la implementación: doc 01 §4 tiene que recoger la regla** «la zona horaria del centro se fija con el primer fichaje; después solo se corrige por consola, con motivo, sin recalcular la jornada de lo ya fichado». Hoy ningún `RN-*` la contiene. Además, doc 01 dice de `site.updated` que cambiar la zona «las mueve de un día a otro sin tocar un solo fichaje» (§ de acciones del catálogo de auditoría), y eso es inexacto para lo ya fichado, porque `work_date` está guardado. Las dos cosas las corrige quien mantiene el doc 01.
- **Pendiente para `api-contrato`:** el `409` de `PATCH /site` con su código, y la descripción de `time_zone` por jornada y por tramo («siempre la zona del centro»).
- **Pendiente para `backend-laravel`:** la guarda en `UpdateSiteHandler` con su puerto y su bloqueo, el comando de consola del punto 3 (nombre, confirmación y asiento) y sus pruebas: unitaria de la regla, *feature* con el `409` y autorización negativa, y de integración con un primer fichaje concurrente.
- **Pendiente para `frontend-portal-empleado`:** retirar `otherTimeZone` de `ShiftEntryTable.vue`. **Pendiente para `frontend-panel`:** que la pantalla del centro explique el `409` y no ofrezca cambiar la zona cuando ya hay fichajes.
- **El quiosco no se ve afectado:** encola instantes UTC (regla dura 9), y la zona solo interviene en el servidor.

## Verificación

- Unitaria: con registro horario existente, el centro rechaza el cambio de zona y acepta el de nombre.
- *Feature*: `PATCH /site` con otra zona tras el primer tramo devuelve `409` sin asiento; sin tramos devuelve `200` con `site.updated`. Autorización negativa por cada rol que no sea `admin` ni `rrhh`, que son los dos que hoy admite `PATCH /site`.
- Integración: un primer fichaje concurrente con el cambio de zona no deja la zona cambiada con tramos atribuidos a la anterior.
- Consola: el comando exige motivo, deja `site.updated` con el motivo y el recuento, y no modifica ninguna fila de `shift_entries`.

## Nota 09-10-2026 (2.2.0 publicada): estado

**Sin cambios: aceptada para la 2.2.x y sin implementar, ahora para la 2.2.1.** La implementación sigue bloqueada hasta que el doc 01 §4 recoja la regla, y hoy no la recoge.

- **En el código de la 2.2.0**, `PATCH /api/v1/site` sigue cambiando la zona con fichajes hechos: `UpdateSiteHandler` no comprueba si hay tramos. La verificación final lo registra como riesgo conocido y diferido, 🟡 MEDIO ([2.2.0-verificacion-final-tanda-3.md](../verificacion/2.2.0-verificacion-final-tanda-3.md) y [2.2.0-verificacion-final-tanda-4.md](../verificacion/2.2.0-verificacion-final-tanda-4.md)). Lo atenúa que el cambio se audita y que la exportación para la Inspección lleva también la hora UTC. Mientras tanto, la guía del cliente avisa de no cambiar la zona con fichajes hechos (`docs/cliente/configuracion.md`).
- **Dónde consta:** [`2.2.1-lista-cambios.md`](../verificacion/2.2.1-lista-cambios.md) §3, «ADR-056: zona horaria fija desde el primer fichaje».

## Nota 14-10-2026 (2.2.1, bloque 9): el quiosco sí presenta en la zona del centro

**Precisa una consecuencia, no cambia la decisión.** Donde dice «el quiosco no se ve afectado [...] la zona solo interviene en el servidor», vale para lo que el quiosco **registra**: sigue encolando instantes UTC (reglas duras 3 y 9). Para lo que **pinta** ya no es así. Desde V2-KI-01 el latido publica `site_time_zone` (el nombre IANA de `sites.timezone`), y el quiosco lo usa para la hora de la confirmación (RF-AT-05) y para la ventana de actualización (RF-KI-07), que hasta ahora se leían en la zona de la tablet. Es la consecuencia de [ADR-004](ADR-004-utc-en-almacenamiento.md): toda la presentación formatea a la zona del centro, también la del quiosco. Este ADR es lo que hace seguro guardarla en la tablet, porque con fichajes hechos no cambia. Las reglas de uso (qué zona se usa hasta el primer latido y qué pasa el día del cambio de hora) están en la nota del doc 01 §3.7.

## Nota 15-10-2026 (2.2.1, bloque 20): implementada

El doc 01 §4 recoge la regla como **RN-23** (Anexo A, fase 5, con la tarea 5.5 del asistente que elige la zona). **Dos precisiones a lo decidido**; ninguna cambia la decisión:

1. **La carrera con el primer fichaje la cierra un par de bloqueos, no solo la fila del centro.** El punto 2 decía «dentro de la transacción y con la fila del centro bloqueada». No basta: el fichaje leía la zona con un `SELECT` sin bloqueo, así que, si el `PATCH` tomaba la fila primero, el fichaje calculaba su jornada con la zona vieja y la guardaba cuando la nueva ya estaba confirmada. Por eso `UpdateSiteHandler` toma `sites` `FOR UPDATE` y el fichaje lee la zona con `FOR KEY SHARE` (`SiteCalendar::timezoneForRecording`, en el escaneo y en el alta manual de tramo, este último dentro de su transacción). `FOR KEY SHARE` no choca con otros fichajes ni con un `UPDATE` corriente, solo con el `FOR UPDATE` del cambio de zona. Si el cambio va primero, el fichaje espera y usa la zona nueva; si el fichaje va primero, el cambio espera, ve el tramo y responde `409`. Es un método aparte y no el `timezoneOf` de siempre porque un bloqueo de fila no se admite en las transacciones de solo lectura de los lectores.
2. **El punto 3 decía que un tramo de cerca de medianoche se corrige «con una corrección de tramo».** [ADR-035](ADR-035-la-correccion-estrena-identificador-y-no-cambia-de-jornada.md) no deja que una corrección cambie de jornada (`CorrectionWouldChangeWorkDate`). Si hace falta mover uno, se anula y se da de alta en la jornada correcta, cada paso con su motivo (RF-PA-04). RN-23 lo dice así.

**Contrato.** Además del `409`, `Site.timezone_locked` (aditivo, compatible con `v1`) dice al panel si la zona ya está fija, para que no ofrezca un cambio que el servidor va a rechazar. El panel deja cambiarla antes del primer fichaje y, después, la muestra sin edición y explica por qué.

**Sin *trigger* de defensa en `sites`, y por tanto sin migración** (enmendado el 18-10-2026: desde la 2.3.0 sí lo hay, ver la [enmienda](#enmienda-del-18-10-2026-230-bloque-2-la-regla-también-en-el-motor)). No cerraría la carrera, porque un fichaje sin confirmar no es visible para el *trigger*; la consola tendría que saltárselo con un ajuste de sesión que el runtime también puede poner, así que tampoco sería una barrera de seguridad; y retirar a la aplicación el `UPDATE` de la columna, como hace [ADR-057](ADR-057-el-registro-horario-no-se-borra-ni-se-reescribe-con-la-credencial-del-runtime.md) §1 con el registro, no se puede: el cambio de antes del primer fichaje lo necesita. El cambio queda vigilado por su asiento `site.updated` y por la prueba de concurrencia.

**La enmienda de [ADR-040](ADR-040-un-centro-por-instalacion-y-por-licencia.md) pasa a vigente.**

## Enmienda del 18-10-2026 (2.3.0, bloque 2): la regla también en el motor

**Enmienda la nota del 15-10-2026 en un punto: ahora sí hay *trigger* de defensa en `sites`.** La decisión (§1–§4) no cambia. Lo decide el plan de la 2.3.0 aprobado por el propietario (D2, residuo de la tanda 4 de la verificación final de la 2.2.1): con la regla solo en la aplicación, la credencial del runtime cambiaba `sites.timezone` con fichajes hechos con un `UPDATE` directo, sin `409` y sin asiento.

### Por qué el argumento de la nota del 15-10-2026 ya no vale

La nota daba tres razones para no poner *trigger*. Se revisan una a una:

1. **«No cerraría la carrera con el primer fichaje.»** Sigue siendo cierto, y no es lo que hace. La carrera la siguen cerrando `FOR UPDATE` frente a `FOR KEY SHARE`. El *trigger* es para la escritura que **no pasa por el caso de uso**, la de D2, y para esa sí es una barrera.
2. **«La consola tendría que saltárselo con un ajuste de sesión que el runtime también puede poner.»** Ya no hay ajuste de sesión. El *trigger* deja pasar solo al **propietario de la tabla**: compara `current_user` con `pg_class.relowner`, sin nombres de rol en el SQL. La consola no se lo salta: pasa por la función `site_correct_timezone(p_site_id, p_from, p_to)`, `SECURITY DEFINER` y propiedad del rol de migración, que es el propietario. Es el patrón de ADR-057 §2 y §3, y la credencial de ese rol no llega a ningún contenedor de runtime (ADR-042).
3. **«Retirar a la aplicación el `UPDATE` de la columna no se puede.»** Sigue siendo cierto: el cambio de antes del primer fichaje lo necesita. Por eso el *trigger* no retira el privilegio: condiciona el cambio a que el centro no tenga tramos.

### Lo que se ha implementado

Migración `2026_10_18_120000_guard_site_timezone_in_database`, reversible, sin expand/contract (una tabla nueva y vacía, funciones y un *trigger*; no toca filas existentes):

- **`zz_sites_guard_timezone`, `BEFORE UPDATE ON sites FOR EACH ROW`.** Rechaza con `42501` si a la vez la zona cambia (`IS DISTINCT FROM`), el centro tiene algún tramo en cualquier estado y quien escribe no es el propietario de la tabla. No lleva `OF timezone`: la condición ya filtra. El prefijo `zz_` lo hace el último de los `BEFORE` de `sites` (PostgreSQL los ordena por nombre), así que ve la fila como la dejan los demás. La función del *trigger* no es `SECURITY DEFINER`: si lo fuera, `current_user` sería siempre el propietario. La misma zona y el nombre se siguen guardando con fichajes hechos.
- **`site_correct_timezone`**, `SECURITY DEFINER`, `SET search_path = pg_catalog, pg_temp`, `EXECUTE` retirado a `PUBLIC` y concedido solo a la aplicación. Bloquea la fila del centro y responde `P0002` si el centro no existe o su zona no es `p_from`, `42501` si no tiene tramos (entonces la zona va por el camino normal, con su asiento) y `22023` si falta un argumento. **Cada cambio que hace lo apunta en la traza y devuelve el `id` de la fila**, que la orden guarda en su asiento.
- **`site_timezone_changes`, la traza solo-append** (`id`, `site_id`, `from_tz`, `to_tz`, `changed_at`, `txid`, `audit_log_id`: el último asiento al cambiar), propiedad del rol de migración. Solo la escribe la función; la aplicación tiene `SELECT` y ningún otro privilegio, tampoco sobre su secuencia. `changed_at` y `txid` los pone la base.
- **`site_timezone_audit_baseline`, la línea base**: una fila del rol de migración, solo lectura para la aplicación, con el último `audit_log.id` al aplicar la migración (ver «Qué garantiza ahora»). `down()` se niega si la traza tiene filas, como `2026_10_03_100100` con sus avisos: son la única constancia de que la zona cambió.
- **En la aplicación**, `SiteRepository::saveTimezoneCorrection()` llama a la función, y solo la usa `CorrectSiteTimezoneHandler` (`SiteTimezoneLockBoundaryTest`). `save()` traduce el `42501` del *trigger* a `SiteTimezoneLocked`, así que el `PATCH /site` sigue respondiendo `409` aunque la comprobación del caso de uso faltara.
- **`Site::correctTimezone(SiteTimezone $to, SiteTimezoneCorrection $correction)`** (V21-1-AR-5): la firma de la corrección va en la firma del método. El compilador obliga a tenerla, y el método rechaza una firma sin tramos.
- **Vigilancia.** Con la migración aplicada, `update.sh`, `doctor.sh` y `product:doctor` comprueban tres cosas (`infra/scripts/lib/work-record-privileges.sh`). Que el *trigger* existe y está activo. Que la función sigue `SECURITY DEFINER` con `search_path` y sin `EXECUTE` para `PUBLIC`. Y que la traza y la línea base existen, son del mismo propietario que `sites` y la aplicación solo las lee. Un *trigger* o una función ausentes o alterados, o una traza o una línea base ausentes o de otro propietario, son manipulación: no se reparan solos; `update.sh` deshace y `doctor.sh` avisa, como con los de ADR-057 §2. `DELETE`, `UPDATE` o `TRUNCATE` de la aplicación sobre la traza o la línea base también es manipulación, porque con ellos se borra o se reescribe un cambio de zona. Un `INSERT` de más o el `SELECT` que falte es deriva y `update.sh` lo repara, como en el resto de tablas. `product:doctor` avisa además si la aplicación es miembro del rol propietario (`owner_membership`).

### Qué garantiza ahora, y qué no

**Garantiza**, frente a quien solo tiene la credencial del runtime:

1. **No puede cambiar la zona con fichajes hechos sin pasar por `site_correct_timezone`.** Un `UPDATE` directo da `42501`.
2. **Todo cambio por esa función deja una fila en la traza que no puede borrar ni reescribir.** Un A→B→A entre dos conciliaciones deja dos filas.
3. **La conciliación de ADR-057 §4 lo coteja con la auditoría** y saca `site_timezone_differs_from_audit` en tres casos, cada uno con su campo:
   - `timezone`: la zona de `sites` no es la de su último asiento `site.*`;
   - `site_timezone_changes #<id>`: una fila de la traza no tiene **su** `site.updated` firmado. La función devuelve el `id` de la fila, la orden lo guarda en el asiento (`site_timezone_change_id`) y la conciliación empareja fila y asiento **por identidad**, además de por el mismo antes y después y la firma (motivo, recuento y cuenta). Así una fila revisada no se come el asiento de una corrección legítima posterior, y una corrección legítima no tapa la fila de un ataque con el mismo antes y después;
   - `correction`: un `site.updated` cambia la zona después del primer tramo sin esa firma. «Después» se decide por el orden de la cadena (ADR-010), no por relojes. Desde la 2.2.1 el panel no puede escribir un asiento así (`409`); antes sí podía.

   **La línea base.** La migración fija en `site_timezone_audit_baseline` (una fila del rol de migración, solo lectura para la aplicación) el último asiento de `audit_log` al aplicarse. Un `site.updated` sin firma hasta ella es de una instalación que viene de la 2.1.0 o la 2.2.0, cuando el panel cambiaba la zona con fichajes legítimamente: cuenta como no verificable, no como discrepancia. Una corrección firmada hasta ella (las de la 2.2.1, que no dejaban fila) no sirve para justificar una fila de la traza.
4. **Una discrepancia de la traza o de un asiento sin firma solo deja de contarse si alguien la firma como revisada.** Lo hace `compliance:reconcile-work-record --acknowledge-site-change=change:<id>|audit:<id>`, con motivo y una cuenta activa con rol admin o rrhh, y escribe el asiento `site.timezone_discrepancy_reviewed`. La revisión de una fila solo cuenta si su asiento es posterior al último asiento que había al escribirse la fila (`site_timezone_changes.audit_log_id`), así que no se puede revisar de antemano. Lo revisado pasa a la métrica de revisadas, separada por tipo: la de una fila (`target="change"`) dispara `CambioDeZonaRevisado`, crítica y de integridad, porque es un cambio de zona que ocurrió de verdad; la de un asiento sin firma (`target="audit"`), `DiscrepanciaDeZonaRevisada`, de aviso. La de la zona actual no se revisa: se devuelve la zona por el producto.

**No garantiza:**

- **Que la función solo la llame la orden.** La aplicación tiene `EXECUTE`, y la función no escribe asiento porque no puede firmar por nadie. Con la credencial del runtime se puede llamar sin pasar por la orden: la zona cambia. Lo que queda es la fila en la traza, y que la conciliación la denuncia **en su siguiente pasada** (diaria). No lo impide, lo detecta. Es el mismo residuo que `work_record_purge_batch` (ADR-057, «Lo que esto no impide»).
- **Detectar una fila acompañada de un asiento firmado falsificado.** La aplicación puede insertar en `audit_log`, y un `site.updated` con `via: console`, motivo, recuento y una cuenta existente con el mismo antes y después hace que la fila cuadre. Es el límite general de §4 («una escritura acompañada de un asiento falsificado»). La cadena de hash no lo impide, porque el asiento se encadena como cualquier otro.
- **Verificar lo que ya no tiene auditoría o se escribió con las reglas de antes.** Hay tres casos:
  - un centro sin ningún asiento `site.*`: una instalación anterior a la tarea 5.5, o un asiento que se fue con la partición purgada de su año (ADR-027);
  - una fila de la traza de un año cuya partición de `audit_log` ya se purgó;
  - un `site.updated` sin firma anterior a la línea base.

  No se denuncian. Se cuentan como **no verificables**, con una línea informativa en la orden y la métrica `work_record_reconciliation_unverifiable`, para que la comprobación no quede en cero en silencio. La alerta `ZonaDelCentroNoVerificable` es informativa, no notifica y solo salta cuando la cifra sube respecto a hace un día. No son una alerta de integridad.
- **Lo que haga el rol de migración.** Es el propietario: cambia la zona sin pasar por el *trigger* y sin dejar fila, y puede soltar las guardas. Esa credencial no llega al runtime (ADR-042). Si al conciliar la zona ya no es la de su último asiento, lo ve la comparación de `timezone`; un cambio que deshizo antes de la pasada, no.
- **Que una revisión sea legítima.** Con la credencial del runtime también se puede insertar un `site.timezone_discrepancy_reviewed`, como un `retention.purge_reviewed`. Por eso una revisión nunca es silenciosa: la de una fila dispara `CambioDeZonaRevisado` (crítica) y la de un asiento, `DiscrepanciaDeZonaRevisada`, para que alguien compruebe quién firmó y por qué. Sin revisión, la discrepancia se repite en cada pasada.

**Verificación:**

- `tests/Integration/Schema/SiteTimezoneGuardTest.php`, con SQL directo:
  - `42501` con tramos; pasa sin ellos; el nombre y la misma zona pasan siempre; el propietario pasa;
  - la función con y sin tramos, `P0002` y su traza, A→B→A incluido;
  - la aplicación no escribe, borra ni vacía la traza, ni usa su secuencia;
  - la forma del *trigger*, los privilegios y la forma de la función; la aplicación no puede desactivar ni reescribir la guarda;
  - la línea base;
  - `down()`/`up()`, y `down()` se niega con filas en la traza.
- `Feature/Workforce/CorrectSiteTimezoneCommandTest.php`: la orden de punta a punta, ya por la función.
- `Unit/Workforce/Domain/SiteTimezoneLockTest.php`.
- `Unit/Compliance/Domain/AuditedSiteTimezoneTest.php`: las tres comprobaciones y lo no verificable, con y sin año sellado.
- `Integration/Compliance/WorkRecordReconciliationTest.php`:
  - la función fuera de la orden, la ida y vuelta, el asiento sin firma y la zona no verificable;
  - el cambio por el panel antes del primer tramo y el caso anterior a la línea base;
  - la revisión por fila y por asiento, con la negativa de rol y los rechazos.
- `Integration/Product/WorkRecordPrivilegesDoctorCheckTest.php`, `Integration/Schema/WorkRecordPrivilegesUpdateScriptTest.php` e `Integration/Install/DoctorScriptTest.php`.

## Nota 18-10-2026 (2.3.0, bloque 3): quién firma la corrección

**Precisa el punto 3, no cambia la decisión.** El punto 3 pedía una corrección «explícita»; la 2.2.1 la firmaba con `--responsible=<id>`, el id interno de la cuenta, que nadie ve en el panel y que, mal tecleado, firmaba con otra persona sin que nada lo delatara (V21-7-SC-1). Desde la 2.3.0, por decisión del propietario del 18-10-2026 (doc 01 RN-23 y §7.2, «Firmas por consola»):

- **Firma una cuenta de gestión activa con rol `admin` o `rrhh`**, los mismos que pueden modificar el centro con `PATCH /site` (`SiteMaintainers::ROLES`): la consola no es la vía por la que alguien hace lo que el panel le niega. Lo mismo vale para `--acknowledge-site-change` de la conciliación.
- **`--responsible` es el `uuid` o el correo de la cuenta**; un valor numérico se rechaza con salida `2` y un mensaje que explica el cambio.
- **Antes de confirmar, la orden enseña quién firma** (`Firma: nombre <correo> · rol · uuid`). Con `--force` hace falta además `--responsible-confirm=<correo>`, que tiene que ser el de esa cuenta.
- El caso de uso vuelve a comprobar la cuenta, por su id y con la cadena de `audit_log` tomada, antes de escribir `site.updated`: lo que enseña la consola sirve para preguntar, no para autorizar.

Pruebas: `Feature/Workforce/CorrectSiteTimezoneCommandTest.php` (uuid, correo, id numérico, cuenta de otro rol, cuenta inactiva, `--force` sin `--responsible-confirm` o con el de otra cuenta) y `Unit/Shared/Domain/ResponsibleReferenceTest.php`.
