# Operación de KronoQR — lo que hay que atender, y cada cuánto

> **Estado.** Redactado en la **tarea 2.10** con la parte que ya existe y no
> puede esperar: **la retención y la purga**, que es la única operación del
> producto que borra datos. La **tarea 5.11** completa el resto de la guía de
> operación (arranque, actualización, quioscos, licencia) e integra este
> documento; no lo reescribe.

---

## 1. El calendario, en una tabla

Todo esto corre solo en el contenedor `scheduler`. Lo que aparece en la columna
«tú» es lo que **no** hace ninguna máquina.

| Cuándo | Qué ocurre | Tú |
| --- | --- | --- |
| 02:45 UTC, a diario | Se comprueba que existe la partición anual de auditoría | Nada, salvo que avise |
| 03:15 UTC, a diario | Copia de seguridad lógica | Sacarla del servidor |
| 04:05 UTC, a diario | Se verifica la cadena de hash de la auditoría | Atender la alerta si suena: es crítica |
| 04:30 UTC, a diario | Revisión del registro: turnos abiertos, descansos, jornadas anómalas | Resolver las incidencias en el panel |
| Lunes 05:10 UTC | **Propuesta de retención**: informe de lo que se purgaría | Leerlo cuando haya algo vencido |
| Cada hora | Métricas de credenciales y limpieza de temporales | Nada |
| Trimestral | — | **Simulacro de restauración** de la copia |

---

## 2. La propuesta de retención (semanal, no borra nada)

Cada lunes queda un informe en:

```
storage/app/retention-reports/retencion-propuesta-AAAAMMDD-HHMMSS.txt
```

Se puede pedir a mano en cualquier momento, y **es seguro**: no modifica ni una
fila.

```bash
docker compose --env-file .env -f infra/compose.dev.yaml exec app \
  php artisan compliance:apply-retention --dry-run
```

Lo que dice el informe:

- **La fecha de corte** del registro de jornada —«anterior a AAAA-MM-DD»— y los
  años de retención con los que se calculó.
- **Cuántos registros hay vencidos**, tabla por tabla, con su rango de fechas.
- **Qué particiones de auditoría** han vencido enteras.
- **La frase de confirmación** que haría falta para ejecutar **ese** informe.

**Mientras el total sea 0, no hay nada que hacer.** Es lo normal durante los
primeros cuatro años de la instalación.

**No contiene datos personales**: recuentos, tablas y fechas. Se puede archivar y
adjuntar sin más precaución.

---

## 3. Ejecutar la purga (manual, confirmada y auditada)

**Cuándo:** cuando la propuesta diga que hay registros vencidos y el responsable
lo autorice. Es una operación de una o dos veces al año.

**Antes de empezar:**

1. Comprueba que **la copia de anoche está hecha y fuera del servidor**. La purga
   es irreversible.
2. Comprueba que la **verificación de la cadena de auditoría** terminó en verde
   esta madrugada. Si no lo hizo, para: la purga abortará de todas formas y lo que
   toca es
   [`rotura-cadena-auditoria.md`](../runbooks/rotura-cadena-auditoria.md).
3. Ten a mano la contraseña del rol **`fichaje_maintenance`**. No está en el
   `.env` de la aplicación a propósito: es el único rol que puede soltar una
   partición de auditoría, y si viviera ahí, el reparto de permisos sería
   decorativo.

**La orden**, con la frase exacta que imprimió el informe:

```bash
docker compose --env-file .env -f infra/compose.dev.yaml run --rm \
  -e DB_MAINTENANCE_PASSWORD='<contraseña de fichaje_maintenance>' app \
  php artisan compliance:apply-retention \
    --confirm=PURGAR-AAAA-MM-DD-xxxxxx \
    --responsible=<id de la cuenta de gestión que autoriza>
```

**Qué pasa, en orden:**

1. Se comprueba la frase. Si no corresponde a lo que se purgaría **ahora**, no se
   sigue: un informe de hace tres meses no puede ejecutarse.
2. Se **verifica la cadena** de cada partición de auditoría que iba a soltarse. Si
   una no verifica, **aborta sin borrar nada**.
3. Se purga el registro de jornada vencido, con su asiento en `audit_log` en la
   misma transacción.
4. Se **sella** cada partición vencida en `audit_chain_anchors` y se **suelta
   entera**. La auditoría nunca se borra fila a fila.
5. Se limpian el log técnico y el histórico de errores de más de 90 días.
6. Queda el informe de lo purgado en
   `storage/app/retention-reports/retencion-purga-*.txt`.

**Después:**

- **Archiva el informe** junto a la autorización escrita.
- Lanza `php artisan compliance:verify-audit-chain`. Tiene que terminar en verde
  y decir «Purga sellada reconocida: particion AAAA». Si dijera otra cosa, es un
  incidente de seguridad.

---

## 4. Si algo sale mal

| Síntoma | Qué significa | Qué hacer |
| --- | --- | --- |
| «La frase de confirmación no corresponde…» | El informe caducó, o cambió el perfil de cumplimiento | Vuelve a lanzar `--dry-run` y usa la frase nueva |
| «La cadena de la partición audit_log_AAAA NO verifica» | Alguien tocó la auditoría | **Incidente de seguridad.** `rotura-cadena-auditoria.md`. No repitas la purga |
| «La purga no ha podido completarse contra la base de datos» | Falta la credencial de `fichaje_maintenance`, o el rol no la tiene puesta | Provisiónala con `infra/docker/postgres/initdb/02-application-roles.sh` y repite |
| «La instalación no tiene centro de trabajo» | La puesta en marcha no se ha completado | Termina el asistente; sin centro no hay perfil de cumplimiento y no hay plazo |
| La propuesta semanal deja de aparecer | El planificador no está corriendo | Revisa el contenedor `scheduler`; la métrica `retention_last_run_timestamp_seconds` lo delata |

---

## 5. Qué mirar sin esperar a que suene nada

Métricas publicadas para el colector de `node-exporter`
(`kronoqr_retention.prom`):

| Serie | Qué dice | Cuándo preocuparse |
| --- | --- | --- |
| `retention_pending_rows{scope}` | Registros vencidos que siguen ahí | Si crece y se queda: hay una purga pendiente de autorizar |
| `retention_purged_rows{scope}` | Lo que se llevó la última purga real | Compáralo con el informe |
| `retention_last_run_timestamp_seconds{mode}` | Cuándo corrió la última pasada | Si la de `simulation` tiene más de una semana, el planificador no está corriendo |
| `retention_cutoff_timestamp_seconds` | Fecha de corte vigente | Si salta hacia atrás o hacia delante, alguien cambió el perfil de cumplimiento |

---

## 6. Parámetros que gobiernan todo esto

| Variable | De serie | Qué hace |
| --- | --- | --- |
| *(perfil del centro)* `retention_years` | 4 | Años del registro de jornada y de la auditoría. **No es una variable de entorno**: es una fila de `compliance_profiles`, porque lo fija la jurisdicción |
| `TECHNICAL_LOG_RETENTION_DAYS` | 90 | Días de log técnico |
| `ERROR_HISTORY_RETENTION_DAYS` | 90 | Días del histórico de errores |
| `COMPLIANCE_RETENTION_BATCH_SIZE` | 1000 | Filas por sentencia de borrado. Súbelo solo si la purga tarda demasiado |
| `COMPLIANCE_RETENTION_REPORT_PATH` | `storage/app/retention-reports` | Dónde quedan los informes. **No se limpian solos**: son la constancia de la purga |
| `DB_MAINTENANCE_USERNAME` | `fichaje_maintenance` | Rol que ejecuta la purga de auditoría |
| `DB_MAINTENANCE_PASSWORD` | *(vacía)* | **No se pone en el `.env`.** Se aporta al ejecutar la purga |

Las obligaciones que van con todo esto —qué informar, qué archivar, quién
autoriza— están en
[`obligaciones-legales.md`](obligaciones-legales.md).

Lo que se puede **cambiar** —umbrales operativos, marca e idiomas— y qué
consecuencias tiene cada cambio está en
[`configuracion.md`](configuracion.md).
