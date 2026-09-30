# Runbook — hay un slot de replicación parado que retiene WAL

**Alerta que lleva aquí:** `SlotDeReplicacionParado`, definida en
[`infra/observability/prometheus/rules/backup.yml`](../../infra/observability/prometheus/rules/backup.yml).

| Alerta | Umbral | Severidad | Destinatario |
| --- | --- | --- | --- |
| `SlotDeReplicacionParado` | `kronoqr_backup_replication_slots_inactive > 0`, `for: 15m` | Crítica | IT del cliente |

**A las 06:30, quien la reciba hace esto:** lista los slots (§2), retira los que
no reconozcas (§3) y averigua cómo nacieron (§4). **KronoQR no usa ningún slot de
replicación**: uno solo ya es anómalo.

---

## 1. Qué significa y qué no

Un *slot de replicación* obliga a PostgreSQL a conservar todo el registro de
transacciones (WAL) que su consumidor aún no ha leído. Si el consumidor no
aparece, el WAL se acumula **sin límite** y acaba llenando el disco de datos, con
lo que PostgreSQL se para y con él el registro (los quioscos siguen encolando en
local, regla dura 19, pero nada sincroniza).

- **No es una avería del producto.** Ningún componente de KronoQR crea slots:
  `pg_basebackup` (la copia física) usa `--wal-method=fetch`, sin slot.
- **Quién puede crear uno:** el rol de copias `fichaje_backup` (tiene
  `REPLICATION`, que exige `pg_basebackup`) y el superusuario. Un slot inesperado
  es por tanto una herramienta ajena que alguien lanzó con esas credenciales, o
  un indicio de que la credencial de copias está en manos que no debe.
- **Impacto en el fichaje: ninguno hoy.** Y hay un tope: `max_slot_wal_keep_size`
  (`DB_MAX_SLOT_WAL_KEEP_GB`, 5 GiB de serie) invalida el slot cuando el WAL que
  retiene lo supera, y PostgreSQL vuelve a reciclar. Sirve de red, no de
  permiso para dejarlo estar.

## 2. Diagnóstico

```bash
docker compose exec -T postgres sh -c 'psql -U "$POSTGRES_USER" -d "$POSTGRES_DB" -c "
  SELECT slot_name, slot_type, active, wal_status,
         pg_size_pretty(pg_wal_lsn_diff(pg_current_wal_lsn(), restart_lsn)) AS retenido
  FROM pg_replication_slots ORDER BY 1"'
```

- `active = f` y `retenido` creciendo: es el slot de la alerta.
- `wal_status = lost`: ya superó el tope y PostgreSQL lo ha invalidado; aun así
  hay que retirarlo.
- Mira también el disco: [`espacio-en-disco.md`](espacio-en-disco.md) §2.

## 3. Resolución

Si nadie del equipo lo creó a propósito (y no lo hizo el fabricante en una
sesión de soporte concedida y auditada, ADR-020), retíralo:

```bash
docker compose exec -T postgres sh -c 'psql -U "$POSTGRES_USER" -d "$POSTGRES_DB" \
  -c "SELECT pg_drop_replication_slot('"'"'NOMBRE_DEL_SLOT'"'"')"'
```

La métrica se refresca con la siguiente copia (`backup.sh` la escribe en cada
ejecución): para apagar la alerta sin esperar a la noche,
`docker compose exec scheduler php artisan backup:run`.

## 4. Averiguar cómo nació (es una señal de seguridad)

1. ¿Alguien del equipo probó una réplica o una herramienta de CDC? Si sí, no es
   más que un descuido: documéntalo y no lo repitas.
2. Si nadie lo reconoce, trátalo como uso indebido de una credencial:
   - rota `fichaje_backup` y `BACKUP_ENCRYPTION_KEY`
     ([`rotacion-secretos.md`](rotacion-secretos.md) §3 y §5);
   - comprueba que `BACKUP_DB_USERNAME` es de verdad `fichaje_backup` y que el rol
     no tiene atributos de más (`backup.sh` sale con `7` si los tiene);
   - sigue [`brecha-de-seguridad.md`](brecha-de-seguridad.md).

## 5. A quién se escala

Si el disco de datos está ya por debajo del 10 % libre: es
[`espacio-en-disco.md`](espacio-en-disco.md), **urgente**. Si el slot no se puede
retirar o reaparece, al responsable de seguridad del cliente y al fabricante con
el paquete de diagnóstico (`incidencia-sin-acceso.md`).
