# ADR-003 — PostgreSQL 17 como motor de datos

| Campo | Valor |
|---|---|
| **Estado** | Aceptada |
| **Fecha** | 11 de agosto de 2026 (redactada el 14 de agosto de 2026, tarea 0.6) |
| **Decide** | `arquitecto-dominio` |
| **Afecta a** | Tareas 0.1, 1.3 y 2.2 · [documento 02](../02-stack-tecnologico-y-plan-implementacion.md) §3.2 y Anexo D · Reglas duras 3 y 6 de `CLAUDE.md` |
| **Requisitos** | RN-01, RN-02, RN-04, RN-09, RN-10, RL-04, RNF-D-02, RNF-P-04 |

> Procede de la primera tabla del [documento 02](../02-stack-tecnologico-y-plan-implementacion.md) §4, que ya fijaba decisión, contexto y consecuencias. Esta redacción los desarrolla y los enlaza con los requisitos y con las reglas duras; no cambia la decisión.

## Contexto

La elección convencional en el ecosistema Laravel es MySQL. Aquí no se elige por preferencia, sino porque **hay dos invariantes del dominio que PostgreSQL puede garantizar en la propia base de datos y MySQL no**:

- **RN-01**, como máximo un turno abierto por empleado.
- **RN-02**, los tramos vigentes de un mismo empleado nunca se solapan.

En un sistema con valor probatorio, la integridad no puede depender solo del código de aplicación. Un script de migración mal escrito, una corrección manual hecha por SQL directo o una condición de carrera en el cambio de turno —cuando 50 personas fichan en el mismo segundo— pueden introducir datos que el dominio considera imposibles. **La base de datos es la última línea de defensa, y aquí puede sostenerla declarativamente.**

A eso se suman tres necesidades que no son negociables y que el motor resuelve o no resuelve: semántica correcta de instantes con zona (RN-04, RN-09), aritmética de rangos temporales para el descanso entre jornadas (RN-10) y archivado de WAL para el RPO ≤ 15 min de RNF-D-02.

## Decisión

**PostgreSQL 17, con las invariantes críticas expresadas como restricciones declarativas.**

```sql
CREATE EXTENSION IF NOT EXISTS btree_gist;

-- RN-01 · Como máximo un turno abierto por empleado.
CREATE UNIQUE INDEX one_open_shift_per_employee
    ON shift_entries (employee_id)
    WHERE clocked_out_at IS NULL AND status NOT IN ('voided', 'superseded');

-- RN-02 · Los tramos vigentes de un mismo empleado nunca se solapan.
ALTER TABLE shift_entries ADD CONSTRAINT shift_entries_no_overlap
    EXCLUDE USING gist (
        employee_id WITH =,
        tstzrange(clocked_in_at, clocked_out_at) WITH &&
    ) WHERE (status NOT IN ('voided', 'superseded'));
```

El predicado excluye los dos estados no vigentes por [ADR-026](ADR-026-la-correccion-supersede.md). Las capacidades que el producto usa y que decantan la elección son, además de la exclusión: `TIMESTAMPTZ` con semántica correcta, `tstzrange` y sus operadores, índices parciales, `JSONB` con índices GIN, particionado nativo (`audit_log` por año, [ADR-027](ADR-027-audit-log-particionado.md); `scan_events` por mes cuando lo pida el volumen), `pgcrypto` para el hash del DNI y la cadena de auditoría, y funciones de ventana con `generate_series` para informes que incluyen días sin actividad.

**La invariante está en dos sitios a propósito:** en el agregado `WorkDay` y en la base de datos. No es duplicación: son dos líneas de defensa con modos de fallo distintos.

## Alternativas descartadas

| Alternativa | Por qué se descarta |
|---|---|
| **MySQL 8** | No tiene restricciones de exclusión ni índices parciales: RN-01 y RN-02 pasarían a depender exclusivamente del código de aplicación, y bajo el pico de RNF-P-06 eso significa aceptar dobles turnos abiertos como riesgo residual. El **Anexo D** documenta la equivalencia con `SELECT ... FOR UPDATE` y una columna generada, **y qué garantías se pierden**, por si la infraestructura de un cliente lo impusiera. No es la vía recomendada |
| **SQLite** | Suficiente para las pruebas, insuficiente para concurrencia real, sin `TIMESTAMPTZ` y sin exclusión. Además el producto se instala con Compose: el ahorro operativo no existe |
| **Validar los solapes solo en la aplicación** | Renuncia a la última línea de defensa justo en el dato que sostiene una nómina. Una condición de carrera en el cambio de turno no deja rastro y se descubre en la revisión de nómina del mes siguiente |
| **Motor documental o clave-valor** | El dominio es relacional y transaccional: empleados, tramos, jornadas y correcciones con integridad referencial. No hay nada aquí que pida otro modelo |

## Consecuencias

- **El equipo debe conocer PostgreSQL**, no solo el ORM. Las restricciones de exclusión, los índices parciales y el particionado se escriben a mano en las migraciones y hay que saber leerlos.
- **Las violaciones de invariante llegan como error de base de datos**, y la capa de aplicación tiene que traducirlas a una respuesta útil. Un 500 con un mensaje de PostgreSQL en el quiosco sería un fallo de producto.
- **La instalación del cliente incluye extensiones**: `btree_gist`, `citext` y `pgcrypto`. El instalador y `doctor.sh` deben comprobar que existen; sin `btree_gist` la migración de RN-02 falla.
- **El Anexo D existe pero es una salida de emergencia**, no una opción de igual valor. Si un cliente la exige, hay que decirle por escrito qué garantía pierde.
- **Se habilita el archivado de WAL** para el RPO ≤ 15 min (RNF-D-02) y la restauración a un punto en el tiempo, que es lo que hace verificable la conservación de cuatro años de RL-02.
- **El usuario de aplicación tiene permisos mínimos** (regla dura 6): sin DDL y sin `UPDATE` ni `DELETE` sobre `audit_log` ni sus particiones. Lo hace posible el modelo de roles del motor.

## Verificación

- Prueba de integración: insertar por **SQL directo** un tramo que solapa con otro vigente del mismo empleado es rechazado por `shift_entries_no_overlap`. Es la prueba que demuestra que la garantía no depende de la aplicación.
- Prueba de integración: abrir un segundo turno con uno ya abierto viola `one_open_shift_per_employee`.
- Prueba de integración: el usuario de aplicación recibe error de permisos al intentar `UPDATE` o `DELETE` sobre `audit_log`.
- Prueba de integración: cálculo de duración sobre el cambio de hora de `Europe/Madrid` en ambos sentidos, con `TIMESTAMPTZ`, coincidente con el resultado del dominio (RN-09).
- `doctor.sh` comprueba versión del motor y presencia de `btree_gist`, `citext` y `pgcrypto` (RF-PD-13).
