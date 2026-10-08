# ADR-054 — La vuelta atrás de una actualización es restaurar la copia, nunca `migrate:rollback`

| Campo | Valor |
|---|---|
| **Estado** | Aceptada. Describe lo que `update.sh` hace desde la tarea 5.7 (07-09-2026) y lo que añadió el bloque 13 de la 2.2.0; no cambia el comportamiento |
| **Fecha** | 8 de octubre de 2026 |
| **Decide** | `arquitecto-dominio` (bloque 14 de la 2.2.0, hallazgo A6-3 de la [tanda 6 de la 2.1.0](../verificacion/2.1.0-tanda-6.md), abierto en la [re-verificación](../verificacion/2.2.0-reverificacion-tandas-5-6-7.md)) · implementado por `devops-observabilidad` |
| **Afecta a** | [ADR-008](ADR-008-offline-first-con-idempotencia-por-scan-id.md) (la cola del quiosco cubre la ventana) · [ADR-010](ADR-010-auditoria-solo-append-encadenada.md) y [ADR-042](ADR-042-el-runtime-no-tiene-credencial-que-pueda-alterar-el-registro.md) (consecuencia 5, que ya dependía de esta decisión) · [ADR-049](ADR-049-formato-autenticado-kqe1-de-las-copias-y-subclave-del-wal.md) (la copia que se restaura) · [ADR-053](ADR-053-una-version-publicada-es-inmutable-y-el-paquete-fija-sus-imagenes-por-digest.md) (la versión a la que se vuelve) · `infra/scripts/update.sh` (`rollback_and_die`, `phase_maintenance`, `phase_backup`, `phase_migrations`, `verify_privileges`) · `infra/scripts/restore.sh` · `backend/app/Support/Database/LimitsMigrationLocks.php` · `backend/tests/Architecture/MigrationSafetyTest.php` · `backend/tests/Integration/Schema/MigrationsRoundTripTest.php` · doc 02 §11.6.4 · `docs/cliente/operacion.md` |
| **Requisitos** | RF-PD-10, RQ-11, RL-04, RS-07 · reglas duras 5, 6, 8 y 19 |

## Contexto

La decisión que gobierna toda actualización solo estaba escrita en un comentario de `update.sh`: «LA VUELTA ATRÁS ES SIEMPRE RESTAURAR LA COPIA, nunca `migrate:rollback`». ADR-042 (consecuencia 5) ya se apoyaba en ella, y doc 02 §11.6.4 seguía listando los pasos en un orden que el propio script contradice: la copia antes que el mantenimiento. La verificación de la 2.1.0 lo registró como A6-3, y la re-verificación de la 2.2.0 lo confirmó abierto.

Desde el bloque 13 de la 2.2.0 hay además migraciones que **no son atómicas**: las que construyen índices con `CREATE INDEX CONCURRENTLY` o validan restricciones con `VALIDATE CONSTRAINT` fuera de la transacción que las creó (`App\Support\Database\LimitsMigrationLocks`). Una migración así puede fallar a medias con parte de su efecto ya confirmado. Eso hace todavía menos defendible deshacer una actualización migración a migración.

## Decisión

**Una actualización que falla se deshace restaurando la copia que la propia actualización acaba de hacer y relanzando la versión anterior. Nunca se deshace ejecutando el `down()` de las migraciones. El `down()` existe y se prueba, pero no es un mecanismo de operación en producción.**

### 1. El orden de los pasos

`update.sh` aplica los siete pasos de doc 02 §11.6.4 con el 2 y el 3 intercambiados respecto a la redacción original:

1. **Precondiciones**, sin tocar nada: versión de origen en `versions.txt`, espacio, servicios sanos, cadena de auditoría íntegra, clave de copia presente.
2. **Mantenimiento** (`phase_maintenance`): la API de gestión responde `503` y se paran `horizon` y `scheduler`. Los quioscos encolan (regla dura 19, ADR-008).
3. **Copia lógica cifrada y verificada** de esta ejecución (`phase_backup`). Es bloqueante y no tiene ninguna bandera para omitirla.
4. **Migraciones versión a versión** (`phase_migrations`), con el servicio `migrate` (ADR-042) y un punto de control entre versiones: una marca en el informe y un lote de `migrations` por versión.
5. **Arranque de la versión nueva sin borde**, verificación desde dentro (salud, versión, cadena de auditoría, restricciones, privilegios y `product:doctor`), y solo después el borde y los procesos de fondo.
6. **Si algo falla en el 4 o en el 5: `rollback_and_die`.**
7. **Informe**, siempre, también tras una vuelta atrás.

**El mantenimiento va antes de la copia** por una razón de registro, no de comodidad. Con la copia primero, un fichaje aceptado entre la copia y el mantenimiento existiría en la base y no en la copia. La vuelta atrás lo borraría, y el quiosco ya lo habría sacado de su cola porque el servidor lo confirmó. Con el mantenimiento primero, todo lo que ocurre durante la ventana sigue en las colas de los quioscos y entra después, gane o pierda la actualización, con la idempotencia por `scan_id` (regla dura 8) cubriendo los reenvíos.

### 2. Qué hace `rollback_and_die`

- Con solo el mantenimiento puesto, lo retira y la instalación queda como estaba. **No restaura nada**, y el mensaje lo dice para que nadie restaure la copia de anoche.
- Con el mantenimiento y algo más: para la versión nueva, lee la punta de la cadena de auditoría que se va a descartar (`compliance:audit-chain-head`) y restaura **la copia de esta ejecución** con `restore.sh --file … --yes --audit-by-caller --expect-sha256 <huella que calculó él mismo>`. Después comprueba los privilegios (`verify_privileges`), relanza la versión anterior con su `docker-compose.yml`, la sonda por el borde (`/api/v1/health`, `/api/v1/ready`, `401` en la ruta de gestión), verifica la cadena y escribe el asiento `system.restored_from_backup` con la copia usada, el paso que falló y la punta descartada (`chain_before`).
- `restore.sh` no restaura encima de la base viva. Restaura en una base nueva, intercambia los nombres cuando la nueva supera sus comprobaciones y **conserva la anterior** con su marca temporal (`--keep-previous`, 7 días de serie). La base migrada que falló queda disponible para examinarla.
- Si cualquiera de esos pasos falla, la salida es `5` (vuelta atrás incompleta) y hace falta una persona. El mensaje dice exactamente qué queda por hacer.

### 3. Por qué no `migrate:rollback`

- **El `down()` de una contracción no devuelve los datos que quitó.** Una columna retirada o un catálogo reducido no se reconstruyen desde el esquema.
- **Las migraciones no transaccionales pueden quedar a medias.** Un `VALIDATE` interrumpido deja la restricción `NOT VALID`, y un `CREATE INDEX CONCURRENTLY` interrumpido deja un índice `INVALID` (`LimitsMigrationLocks` lo borra y lo reconstruye en el reintento). Encadenar `down()` sobre ese estado intermedio produciría un esquema que ninguna versión publicada tuvo.
- **La restauración es lo único que se ensaya**: cada trimestre con `restore-drill.sh`, y en la etapa ⑧ de la CI. Un camino de vuelta que solo existe el día del fallo es el que falla.
- **Nada se borra ni se sobrescribe** (regla dura 5). Los `down()` del producto se niegan a quitar algo que ya tiene datos (por ejemplo, `2026_10_01_100000_add_teleworking_to_employees_table`, `2026_10_03_100000_allow_withdrawn_and_discarded_scan_incident_types`). En producción, ese `down()` no podría completar la vuelta atrás justo cuando más falta haría.

### 4. Para qué sirve entonces el `down()`

Para desarrollo y para la CI. `MigrationsRoundTripTest` comprueba que cada migración sube, baja y vuelve a subir, y eso es lo que mantiene las migraciones honestas: reversibles mientras no hay datos y explícitas cuando los hay. La Definición de Terminado sigue pidiendo una «migración reversible» (doc 02 §10.3) en ese sentido, no como mecanismo de operación.

## Alternativas descartadas

| Alternativa | Por qué se descarta |
|---|---|
| **`migrate:rollback --step=N` hasta el punto de control** | No devuelve los datos de una contracción. Sobre una migración no transaccional fallida, parte de un estado que ninguna versión conoce. Nunca se ensaya |
| **Copia antes del mantenimiento**, como decía doc 02 §11.6.4 | Un fichaje confirmado entre los dos pasos se perdería en la vuelta atrás sin que el quiosco lo reenviara |
| **Restaurar encima de la base viva** | Si la restauración falla a medias, no queda ninguna base buena. Restaurar en una base nueva y cambiar los nombres convierte el peor caso en «la base anterior sigue ahí» |
| **Instantánea del volumen de PostgreSQL** | Depende del sistema de ficheros del servidor del cliente (ADR-016), y la copia lógica cifrada y autenticada (ADR-049) ya existe, se verifica y se ensaya |
| **Una bandera para omitir la copia** en actualizaciones «pequeñas» | El atajo que exista es el que se usará en la actualización que salga mal |

## Consecuencias

- **Una actualización terminada con éxito no tiene vuelta atrás que conserve los datos posteriores.** Si días después aparece un fallo de la versión nueva, la salida es un parche (ADR-053), no restaurar la copia previa, que descartaría todo lo fichado desde entonces. **Pendiente:** `docs/cliente/operacion.md` no lo dice de forma explícita. Lo añade `producto-licencia`.
- **Restaurar descarta un intervalo del registro**, y por eso queda dentro del propio registro: el asiento `system.restored_from_backup` con `chain_before` es la única prueba de que hubo un intervalo que ya no está (RL-04, RS-07).
- **Volver a la 2.1.0 reabre AUD-1** hasta volver a actualizar (ADR-042, consecuencia 5), y la vuelta atrás lo avisa.
- **Las migraciones no transaccionales** (`public $withinTransaction = false`) son admisibles **porque** la vuelta atrás es la copia: si fallan a medias, el estado intermedio se descarta entero. Un reintento de la actualización las vuelve a ejecutar, y los ayudantes de `LimitsMigrationLocks` son idempotentes (borran el índice `INVALID` y repiten el `VALIDATE`).
- **Doc 02 §11.6.4** pasa a listar los pasos en el orden real, mantenimiento antes que copia, y remite a este ADR.
- **`verify_privileges`** (paso 5 y vuelta atrás) comprueba hoy que el rol de la aplicación puede insertar en `shift_entries` y no puede modificar `audit_log`. Cuando se implemente [ADR-057](ADR-057-el-registro-horario-no-se-borra-ni-se-reescribe-con-la-credencial-del-runtime.md), tiene que comprobar también los privilegios que ese ADR retira. Ese cambio es de `devops-observabilidad`.

## Verificación

- Etapa ⑧ de la CI (`ci.yml`, escenario E): un fallo inyectado dentro de la fase 4 tiene que acabar en la salida `4`, con la vuelta atrás completada, la versión anterior en marcha y verificada y la cadena íntegra. No hay escenario para un fallo en la fase 5, que recorre el mismo `rollback_and_die`.
- `restore-drill.sh`: ensayo trimestral de la restauración.
- Arquitectura: `MigrationSafetyTest` falla si una migración nueva escribe un `CREATE INDEX CONCURRENTLY` o un `VALIDATE CONSTRAINT` a mano, o si valida dentro de la transacción que crea la restricción.
- Integración: `MigrationsRoundTripTest` sube, baja y vuelve a subir cada migración en una base de pruebas.
