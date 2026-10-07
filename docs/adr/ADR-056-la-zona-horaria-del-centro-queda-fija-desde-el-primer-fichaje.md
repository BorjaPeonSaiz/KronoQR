# ADR-056 — La zona horaria del centro queda fija desde el primer fichaje, y es la del histórico

| Campo | Valor |
|---|---|
| **Estado** | **Propuesta, pendiente de decisión del propietario** (07-10-2026): cambia el comportamiento del producto (`409` en `PATCH /site`) y no entra en la 2.2.0 sin su visto bueno. **Implementación bloqueada hasta que doc 01 §4 recoja la regla** (ver Consecuencias): fijar la zona es una regla de negocio nueva y tiene que estar escrita en el doc 01 antes de estar en el código |
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
- *Feature*: `PATCH /site` con otra zona tras el primer tramo devuelve `409` sin asiento; sin tramos devuelve `200` con `site.updated`. Autorización negativa por cada rol que no sea `admin`.
- Integración: un primer fichaje concurrente con el cambio de zona no deja la zona cambiada con tramos atribuidos a la anterior.
- Consola: el comando exige motivo, deja `site.updated` con el motivo y el recuento, y no modifica ninguna fila de `shift_entries`.
