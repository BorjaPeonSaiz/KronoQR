---
name: migracion-segura
description: Crea migraciones de PostgreSQL siguiendo el patrón expand/migrate/contract, sin bloqueos ni parada de servicio, con las restricciones de integridad declarativas del dominio y un plan de despliegue por pasos. Úsalo para cualquier cambio de esquema, especialmente sobre tablas con datos.
---

# Migración segura

Con 500 personas fichando en el cambio de turno, una migración bloqueante es una interrupción de negocio. Y sobre un registro con valor legal, una migración mal hecha puede corromper datos que hay que conservar cuatro años.

## Regla absoluta

**Nunca se renombra ni se elimina una columna en el mismo despliegue en que se deja de usar.** Durante un despliegue conviven la versión antigua y la nueva del código; ambas deben funcionar contra el mismo esquema.

## El patrón en tres despliegues

### Despliegue 1 — Expand

Añadir solo estructura nueva, siempre compatible hacia atrás:
- Columnas nuevas **nullable** o con valor por defecto
- Tablas e índices nuevos
- El código antiguo sigue funcionando sin enterarse

```php
Schema::table('shift_entries', function (Blueprint $table) {
    $table->string('clock_out_source')->nullable();
});
```

### Despliegue 2 — Migrate

- El código escribe en la estructura nueva **y** en la antigua, y lee de la nueva
- *Backfill* de los datos históricos **por lotes en cola**, nunca en la migración: un `UPDATE` sobre dos millones de filas bloquea la tabla
- Verificar que el relleno está completo antes de continuar

### Despliegue 3 — Contract

- Eliminar la estructura antigua
- Solo tras confirmar que ninguna versión desplegada la usa
- Añadir el `NOT NULL` si procede, en dos pasos (ver abajo)

## Especificidades de PostgreSQL

```sql
-- Índices sobre tablas con datos: sin bloqueo de escritura
CREATE INDEX CONCURRENTLY idx_nombre ON tabla (columna);
-- Requiere migración fuera de transacción:
-- public $withinTransaction = false;

-- NOT NULL en dos pasos, para no escanear la tabla bajo bloqueo
ALTER TABLE t ADD CONSTRAINT c CHECK (col IS NOT NULL) NOT VALID;
ALTER TABLE t VALIDATE CONSTRAINT c;   -- escaneo sin bloqueo exclusivo

-- Evitar esperas indefinidas en la cola de bloqueos
SET lock_timeout = '3s';
SET statement_timeout = '30s';
```

## Restricciones de integridad del dominio

Cuando la migración toque `shift_entries`, estas restricciones deben existir y seguir siendo válidas. Son la última línea de defensa de RN-01 y RN-02:

```sql
CREATE EXTENSION IF NOT EXISTS btree_gist;

CREATE UNIQUE INDEX one_open_shift_per_employee
    ON shift_entries (employee_id)
    WHERE clocked_out_at IS NULL AND status NOT IN ('voided', 'superseded');

ALTER TABLE shift_entries ADD CONSTRAINT shift_entries_no_overlap
    EXCLUDE USING gist (
        employee_id WITH =,
        tstzrange(clocked_in_at, clocked_out_at) WITH &&
    ) WHERE (status NOT IN ('voided', 'superseded'));
```

El predicado excluye los **dos** estados no vigentes (ADR-026): `voided` (el tramo no ocurrió) y `superseded` (ocurrió y otra versión lo sustituye, RN-13). Con solo `voided`, la corrección haría solapar la versión conservada con la nueva y la restricción la rechazaría. **Ningún literal `<> 'voided'` suelto**: el predicado vive en un solo sitio.

Si la migración las elimina temporalmente, **el plan debe indicar cómo se restauran y cómo se verifica que ningún dato las viola al volver a activarlas.**

`audit_log` está **particionada por año** (ADR-027) y su purga es `DROP PARTITION` con un rol de mantenimiento distinto, previo sellado de `audit_chain_anchors`. Ninguna migración le añade `UPDATE` ni `DELETE` al usuario de aplicación, ni sobre la tabla ni sobre sus particiones.

## Reglas de tipos y nombres

Nombres según las convenciones de Laravel recogidas en el documento 02 §3.5: tablas en plural `snake_case`, claves foráneas `{singular}_id`, índices y restricciones con nombre explícito y descriptivo (`one_open_shift_per_employee`, no el autogenerado).

- Todo instante es `TIMESTAMPTZ`. Nunca `TIMESTAMP` sin zona, nunca `DATETIME`.
- Fechas de jornada (`work_date`) son `DATE`, sin hora.
- Datos semiestructurados en `JSONB`, con índice GIN si se consultan.
- Identificadores públicos en `UUID`. Claves primarias internas en `BIGINT`.
- Dinero y duraciones en enteros. Nunca en coma flotante.

## `audit_log` es intocable

El usuario de aplicación tiene `INSERT` y `SELECT`, nunca `UPDATE` ni `DELETE`. Si una migración necesita alterar esa tabla, es una decisión de arquitectura: consulta con `arquitecto-dominio` y `seguridad-cumplimiento` antes de escribirla.

## Verificación antes de dar por buena

```bash
# 1. Sembrar volumen realista (no 10 filas: cientos de miles)
php artisan db:seed --class=VolumeSeeder

# 2. Ejecutar midiendo el tiempo
time php artisan migrate

# 3. Verificar que la vuelta atrás funciona de verdad
php artisan migrate:rollback && php artisan migrate

# 4. Comprobar que las restricciones siguen activas
php artisan test --filter=DatabaseConstraints
```

Una migración cuyo `down()` no se ha probado no tiene `down()`.

## Lista de comprobación de entrega

- [ ] Patrón expand/migrate/contract respetado; nada se renombra ni borra en el mismo paso
- [ ] Plan de despliegue escrito, indicando qué va en cada uno de los despliegues
- [ ] Sin `UPDATE` masivo dentro de la migración; el relleno va por cola en lotes
- [ ] `CREATE INDEX CONCURRENTLY` en tablas con datos
- [ ] `lock_timeout` establecido
- [ ] Tipos correctos: `TIMESTAMPTZ`, `JSONB`, `UUID`, enteros para duraciones
- [ ] Restricciones de RN-01 y RN-02 presentes y verificadas
- [ ] `down()` probado
- [ ] Medida sobre volumen realista, con el tiempo anotado
