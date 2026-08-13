# ADR-027 — `audit_log` particionado por año, con anclas de cadena

| Campo | Valor |
|---|---|
| **Estado** | Aceptada |
| **Fecha** | 13 de agosto de 2026 |
| **Decide** | `arquitecto-dominio` |
| **Afecta a** | Tareas 2.2 y 2.10 · §5.5 del documento 01 · Regla dura 6 de `CLAUDE.md` |
| **Requisitos** | RL-02, RL-04, RL-10, RS-07, RF-PD-13 |

## Contexto

Tres exigencias del producto se contradicen sobre la misma tabla:

1. **Regla dura 6 / ADR-010.** `audit_log` es solo-append y encadenado por hash. *«El usuario de base de datos de la aplicación no tiene `UPDATE` ni `DELETE` sobre esa tabla.»* Cada fila lleva `prev_hash` y `hash`, y `compliance:verify-audit-chain` recorre la cadena y alerta si se rompe (RS-07).
2. **RL-02.** El registro se conserva **cuatro años** y después se purga. La tarea 2.10 implementa la retención y prueba que *«la purga no alcanza `audit_log` antes de sus 4 años»* — es decir, que después sí lo alcanza.
3. **RL-10.** Las solicitudes de derechos del interesado pueden obligar a actuar sobre datos personales conservados.

La purga, tal como está planteada, es imposible y peligrosa a la vez. **Imposible** porque el usuario de aplicación no puede ejecutar `DELETE` sobre esa tabla, y darle el permiso destruiría la garantía que ADR-010 existe para dar. **Peligrosa** porque, aunque se borrase con otro rol, borrar las filas más antiguas rompe el eslabón: la primera fila superviviente apunta con su `prev_hash` a una fila que ya no existe, y el verificador denunciaría **rotura de cadena todos los días, de forma permanente**, disparando la alerta crítica de RS-07.

Una alerta crítica que suena siempre deja de ser una alerta. El resultado real sería que alguien la silencie, y entonces el sistema pierde la capacidad de detectar una manipulación auténtica — que es lo único que esta tabla aporta.

Añadir el particionado más tarde tampoco es una salida: convertir en particionada una tabla solo-append, con valor probatorio y millones de filas, no es una migración trivial, y exige mover datos que por definición no se deben mover.

## Decisión

**`audit_log` se crea particionada por rango de fecha, con una partición por año natural, desde la primera migración que la crea (tarea 2.2).** Y se añade una tabla de anclas que sella cada partición antes de soltarla.

```sql
CREATE TABLE audit_log (
    id           BIGSERIAL,
    occurred_at  TIMESTAMPTZ NOT NULL,
    actor_type   TEXT NOT NULL,
    actor_id     BIGINT NULL,
    action       TEXT NOT NULL,
    subject_type TEXT NULL,
    subject_id   BIGINT NULL,
    payload      JSONB NOT NULL DEFAULT '{}'::jsonb,
    prev_hash    TEXT NULL,
    hash         TEXT NOT NULL,
    ip           INET NULL,
    user_agent   TEXT NULL,
    PRIMARY KEY (id, occurred_at)
) PARTITION BY RANGE (occurred_at);

CREATE TABLE audit_log_2026 PARTITION OF audit_log
    FOR VALUES FROM ('2026-01-01Z') TO ('2027-01-01Z');

-- Sello de cada partición purgada. Es la nueva génesis de la cadena.
CREATE TABLE audit_chain_anchors (
    id             BIGSERIAL PRIMARY KEY,
    partition_year INT NOT NULL UNIQUE,
    first_hash     TEXT NOT NULL,
    last_hash      TEXT NOT NULL,
    row_count      BIGINT NOT NULL,
    sealed_at      TIMESTAMPTZ NOT NULL,
    sealed_by      TEXT NOT NULL
);
```

**La purga es `DROP PARTITION`, no `DELETE`, y la ejecuta un rol de mantenimiento distinto del usuario de la aplicación.** El usuario de aplicación conserva exactamente `INSERT` y `SELECT`, sin excepción, tal como manda la regla dura 6. El rol de mantenimiento no lo usa la aplicación web: lo usa el comando programado de retención.

El procedimiento de purga es, en una sola transacción:

1. Verificar la cadena completa de la partición que va a soltarse. Si no verifica, **abortar**: una partición con la cadena rota no se purga, se investiga.
2. Insertar en `audit_chain_anchors` el año, el primer y el último `hash` de la partición, el número de filas, el momento y el rol que sella.
3. `ALTER TABLE audit_log DETACH PARTITION audit_log_YYYY` y soltarla.

**El verificador entiende las anclas.** Cuando `compliance:verify-audit-chain` encuentra una fila cuyo `prev_hash` no existe en la tabla, no denuncia rotura: busca ese `prev_hash` como `last_hash` de un ancla. Si lo encuentra, la cadena continúa legítimamente desde ahí y el verificador informa de una purga sellada. Si no lo encuentra, **entonces sí** es manipulación y salta la alerta de RS-07.

Es la diferencia entre *«faltan filas»* y *«faltan filas que alguien registró que iba a quitar, y encajan»*.

## Alternativas descartadas

| Alternativa | Por qué se descarta |
|---|---|
| **No purgar nunca `audit_log`** | Incumple RL-02 y el principio de limitación del plazo de conservación. La tabla contiene datos personales (actor, sujeto, IP) y conservarlos indefinidamente es una infracción por sí misma, no un exceso de celo |
| **`DELETE` con un rol de mantenimiento, sin particionar** | Sigue rompiendo la cadena, que es el problema real; el permiso solo era la mitad. Además `DELETE` masivo sobre millones de filas con `VACUUM` posterior es una operación cara y no atómica en la práctica |
| **Reencadenar tras el borrado (recalcular `prev_hash` de la primera superviviente)** | Reescribe filas de una tabla solo-append. Convierte el mecanismo de purga en la herramienta perfecta para manipular el registro sin dejar rastro: es exactamente el ataque contra el que ADR-010 protege |
| **Archivar a fichero firmado y borrar** | No es incompatible con esto y puede añadirse después como export de la partición antes de soltarla, pero por sí solo no resuelve la continuidad de la cadena en la tabla viva, que es lo que el verificador recorre a diario |
| **Particionar por mes** | 48 particiones vivas para una tabla con volumen modesto (~cientos de miles de filas/año en la instalación objetivo). La unidad de retención de RL-02 es el año: particionar por la unidad de purga es lo que hace la purga trivial |

## Consecuencias

- **La tarea 2.2 crea la tabla ya particionada**, no plana, y crea `audit_chain_anchors` en la misma migración. El coste ahora es una cláusula `PARTITION BY`; después sería una migración de datos sobre el registro probatorio.
- **Hace falta crear la partición del año siguiente antes de que llegue.** Se resuelve con una tarea programada que crea la partición `N+1` en noviembre y alerta si no existe la del año en curso. Un `INSERT` sin partición de destino falla, y un fallo de escritura en `audit_log` bloquea la acción auditada: no puede ocurrir en silencio.
- **`docker-compose` y el instalador provisionan dos roles de base de datos**, no uno. El de mantenimiento no aparece en el `.env` de la aplicación.
- **`compliance:verify-audit-chain` gana un caso de prueba nuevo y obligatorio:** distinguir purga sellada de manipulación. Sin él, la alerta de RS-07 no vale.
- **La clave primaria pasa a ser `(id, occurred_at)`**, porque PostgreSQL exige que la clave de partición forme parte de toda restricción única. Ninguna referencia externa apunta a `audit_log`, así que no arrastra cambios.
- **`scan_events` puede seguir el mismo patrón** cuando lo pida el volumen (docs/01 §6.3 ya lo anticipa a partir de 10 M filas), pero eso no es esta decisión: `scan_events` no está encadenado por hash y su purga no tiene este problema.

## Verificación

- **Prueba de integración:** el usuario de aplicación recibe error de permisos al intentar `UPDATE` o `DELETE` sobre `audit_log`, y también sobre cualquier partición directamente.
- **Prueba de integración:** insertar con `occurred_at` de un año sin partición falla de forma visible, y la acción auditada no se confirma.
- **Prueba de integración:** tras sellar y soltar la partición del año más antiguo, `compliance:verify-audit-chain` termina **en verde** e informa de la purga con su ancla.
- **Prueba de integración:** alterada una fila de una partición viva por fuera de la aplicación, el verificador denuncia rotura. Es el negativo de la anterior y las dos hacen falta.
- **Prueba de integración:** una partición cuya cadena no verifica **no se purga**; el comando aborta y deja la partición en su sitio.
- **Prueba de retención (tarea 2.10):** la purga no alcanza la partición del año en curso ni ninguna dentro de los 4 años de RL-02.
