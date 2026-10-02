# Runbook — alertas de los ficheros que genera el producto

**Alertas que llevan aquí**, definidas en
[`infra/observability/prometheus/rules/generated-files.yml`](../../infra/observability/prometheus/rules/generated-files.yml)
(decisión: ADR-045, «los ficheros generados viven en un volumen compartido»):

| Alerta | Umbral | Severidad | Destinatario |
| --- | --- | --- | --- |
| `FicheroGeneradoDesaparecidoAntesDeCaducar` | `generated_files_missing_total` sube (`increase` en 30 min, o serie nueva), `for: 1m` | Alta | Responsable de seguridad |
| `FicheroGeneradoSinRetirarPasadoSuPlazo` | `generated_files_overdue > 0`, `for: 1h` | Media | IT del cliente |
| `PurgaDeFicherosGeneradosSeHaNegadoATocarAlgo` | `generated_files_refused_total` sube (`increase` en 1 h, o serie nueva), `for: 1m` | Media | IT del cliente |
| `PurgaDeFicherosGeneradosNoPuedeBorrar` | `generated_files_remove_failed_total` sube (`increase` en 1 h, o serie nueva), `for: 1m` | Media | IT del cliente |

**A las 06:30, quien la reciba hace esto:** mira la etiqueta `class` de la alerta
(§1), lee el asiento de `audit_log` si es la de seguridad (§3) y decide con la
tabla de cada apartado. **Ninguna toca el fichaje**: son ficheros de exportación,
informes y paquetes de diagnóstico, no el registro horario.

---

## 1. Qué son estos ficheros y qué dice `class`

`app`, `horizon` y `scheduler` comparten el volumen `app-storage`
(`/var/www/html/storage/app`): la exportación íntegra del panel, los informes en
diferido, el temporal de la exportación legal, el paquete de diagnóstico y la
exportación legal por consola. Caducan a los 7 días (la de consola, no: la
custodia es humana). Las purgas corren en `scheduler` y concilian cada fila con
su fichero.

`class` es una de: `data_export`, `data_export_work`, `report_export`,
`legal_export_tmp`, `legal_export_console`, `diagnostics`. Ninguna métrica lleva
`uuid`, nombre ni ruta: para saber **cuál** hay que ir al asiento de auditoría o
a la fila (§3).

## 2. `FicheroGeneradoDesaparecidoAntesDeCaducar` (seguridad)

Una exportación o informe figuraba como disponible y su fichero ya no existe
**antes** de su fecha de caducidad. La fila pasa a `purged` y la purga deja un
asiento `data_export.file_missing` o `report_export.file_missing` (con el `uuid`,
sin ruta).

**Primero descarta lo esperado.** Es lo normal, y no una brecha, si:

- se acaba de **restaurar una copia en un servidor nuevo** (o tras `down -v`)
  ([`restaurar-backup.md`](restaurar-backup.md)): el volumen no entra en la copia, y
  las exportaciones que figuraban en ella como disponibles ya no tienen fichero.
  El informe de `restore.sh` lo anuncia. En el mismo servidor, restaurar no toca
  el volumen y esta alerta no debería aparecer. Las exportaciones hechas
  *después* de la copia no tienen fila en la base restaurada: sus ficheros quedan
  sin fila y se borran al cumplir su plazo, sin asiento ni alerta;
- se acaba de **actualizar desde la 2.1.0** ([`actualizacion-cliente.md`](actualizacion-cliente.md)):
  el volumen es nuevo y las filas antiguas no tienen fichero;
- alguien recreó el volumen (`docker compose down -v`).

En esos casos no hay nada que arreglar: se vuelve a pedir la exportación desde el
panel. Deja constancia en el parte del cambio y cierra la alerta.

**Si no hay ninguna de esas explicaciones**, alguien ha borrado el fichero o lo ha
sacado con `mv`, y RL-15 pide acotar el alcance:

1. Lee los asientos (§3): cuántos, de qué exportaciones y a qué hora.
2. Cruza la hora con quién tenía acceso a Docker en el servidor (pertenecer al
   grupo `docker` equivale a acceso a todos los datos) y con el registro de
   acceso de la máquina.
3. Si nadie lo reconoce, **trátalo como brecha**: sigue
   [`brecha-de-seguridad.md`](brecha-de-seguridad.md) (72 h, art. 33 RGPD). Una
   exportación íntegra contiene todo el registro y la plantilla.

## 3. Diagnóstico común

```bash
# Qué ficheros ha perdido la purga y cuándo (sin rutas: solo el uuid)
docker compose exec -T postgres sh -c 'psql -U "$POSTGRES_USER" -d "$POSTGRES_DB" -c "
  SELECT occurred_at, action, payload
    FROM audit_log
   WHERE action IN ('"'"'data_export.file_missing'"'"', '"'"'report_export.file_missing'"'"')
   ORDER BY id DESC LIMIT 20"'

# Diagnóstico oficial del producto: raíces solapadas, montaje, exportaciones viejas
docker compose exec -T app php artisan product:doctor

# Desde fuera: el volumen existe, los tres servicios lo montan y se ven entre sí
./doctor.sh
```

`product:doctor` falla si dos raíces de clase coinciden o se solapan, y avisa de
exportaciones legales de consola de más de 30 días. `doctor.sh` falla si a
`app`, `horizon` o `scheduler` les falta el montaje, o si la raíz del volumen no
es `app:app 0700`.

## 4. `FicheroGeneradoSinRetirarPasadoSuPlazo` (IT)

`generated_files_overdue > 0` con `class="legal_export_console"`: hay una
exportación legal hecha por consola con más de 30 días en el servidor. **No se
borra sola**: es la copia que se entregó a la Inspección y su custodia es de
quien la generó ([`requerimiento-inspeccion.md`](requerimiento-inspeccion.md) §7).

1. Pregunta a quien la generó si ya se entregó.
2. Si sí, bórrala (el procedimiento está en ese runbook, §7) y la métrica vuelve a
   0 en la siguiente pasada horaria.
3. Si sigue haciendo falta, anota quién la custodia y por qué: el aviso no se
   apaga hasta que el fichero desaparece.

Contiene datos personales y está en claro: cuanto menos tiempo, mejor (art. 5.1.e
RGPD).

## 5. `PurgaDeFicherosGeneradosSeHaNegadoATocarAlgo` (IT)

La purga encontró algo que **no** borra: un enlace simbólico, un subdirectorio,
un nombre que no es de ese producto, o una fila que apunta fuera de su raíz. No
ha borrado nada de eso (por diseño), pero no debería haberlo.

1. `docker compose exec -T app php artisan product:doctor`: ¿hay raíces
   solapadas (`PRODUCT_DATA_EXPORT_PATH`, `REPORTING_EXPORT_PATH`,
   `PRODUCT_DIAGNOSTICS_PATH`, `TELEMETRY_STATE_PATH`)? Es la causa más probable
   tras tocar el `.env`. Corrígelas y recrea los servicios.
2. Busca lo que no es suyo:
   `docker compose exec -T app find /var/www/html/storage/app -maxdepth 3 \( -type l -o -type d \) -newer /var/www/html/VERSION`.
3. Si alguien dejó ahí ficheros a mano, retíralos. Si aparece un **enlace
   simbólico** que nadie reconoce, es manipulación del volumen: trátalo como el
   caso de seguridad de §2.
4. **Si no se ha cambiado ningún `*_PATH` del `.env`**, una fila que apunta fuera
   de su raíz no es una mala configuración: es manipulación de la base de datos
   (alguien con acceso de escritura a `data_exports` o `report_exports` ha
   cambiado una ruta). Trátalo como §2 (seguridad) y sigue
   [`brecha-de-seguridad.md`](brecha-de-seguridad.md).
5. **Tras cambiar una raíz, vacía la carpeta anterior**: lo que quedó en ella
   queda fuera de toda purga y sin plazo, con datos personales dentro. Cuando
   hayas comprobado que no hace falta, bórrala.

## 6. `PurgaDeFicherosGeneradosNoPuedeBorrar` (IT)

El sistema de ficheros ha **negado un borrado que tocaba**: el fichero sigue en el
volumen pasado su plazo (contiene datos personales) y su fila **no** se marca
`purged` hasta que se borre. La purga lo reintenta cada hora, así que la métrica
sigue subiendo y la alerta no se apaga sola mientras la causa persista. El log
técnico lleva `generated_files.remove_failed` con la clase y un motivo, sin ruta
ni `uuid`:

- `directory_not_writable`: el usuario de la aplicación no puede borrar dentro de
  la carpeta de esa clase (dueño o modo incorrectos);
- `unlink_failed`: el borrado del fichero lo ha rechazado el sistema de ficheros.

**La causa habitual es de permisos**, no de seguridad: algo se creó con otro
usuario, típicamente una exportación lanzada con `docker compose exec -u root`,
que deja una carpeta o un fichero de `root` dentro de un volumen que es de `app`.

1. Mira el dueño y el modo de la raíz del volumen y de la carpeta de la clase
   (`exports`, `reports`, `diagnostics`, `tmp/legal-exports`):

   ```bash
   docker compose exec -T app ls -la /var/www/html/storage/app
   docker compose exec -T app ls -la /var/www/html/storage/app/exports
   ./doctor.sh            # la raíz del volumen debe ser app:app 0700
   docker compose exec -T app php artisan product:doctor
   ```

2. Arregla **solo lo que no es de `app`**, sin borrar a mano más de lo debido:

   ```bash
   docker compose exec -u root app chown -R app:app /var/www/html/storage/app/exports
   docker compose exec -u root app chmod 0700 /var/www/html/storage/app/exports
   ```

   (cambia `exports` por la clase de la alerta). No borres a mano los ficheros que
   han caducado: la siguiente pasada horaria los retira y **marca la fila como
   `purged`**, que es lo que deja constancia.
3. Si el motivo sigue siendo `unlink_failed` con los permisos bien, el sistema de
   ficheros del servidor está fallando (disco en solo lectura, atributos
   inmutables): [`espacio-en-disco.md`](espacio-en-disco.md) y el log del
   servidor. Mientras no se borre, el fichero con datos personales sigue vivo: no
   lo dejes semanas.
4. Evita la causa: lanza las exportaciones por consola como `app` (sin
   `-u root`).

## 6 bis. Dos avisos del log y de `product:doctor` que explican lo mismo

- **`generated_files.root_unavailable`** (log del `scheduler`): la raíz de una
  clase no existe, típicamente porque el volumen `app-storage` no está montado.
  **Sin raíz las purgas ya no concilian** (no marcan nada como `purged` ni
  `file_missing` por un volumen vacío por error). `./doctor.sh` dice qué servicio
  no monta el volumen; recréalo con el `compose` del paquete.
- **`product:doctor`**: la sonda `files.class_roots` **falla en producción** si una
  raíz de clase queda fuera del volumen (un `*_PATH` del `.env` mal puesto), y
  `files.stray_entries` avisa de ficheros de una clase que están **fuera** de las
  raíces configuradas (restos de una raíz anterior: vacíalos cuando compruebes que
  no hacen falta, ver §5 punto 5).

## 7. Lo que no alerta, y un límite conocido

- `generated_files_orphans_removed_total` es informativa y **no tiene alerta**: un
  resto borrado de vez en cuando es la conciliación trabajando. Si sube todos los
  días, los trabajos de generación están muriendo: mira
  [`errores-en-el-panel.md`](errores-en-el-panel.md).
- **Con Redis caído estas métricas desaparecen en vez de saltar** (R4-DV-01): la
  ausencia de series no significa que todo esté bien. Con Redis en pie, la alerta
  de la propia Redis y `/api/v1/ready` te lo dicen antes que estas.
- Estas alertas **no se silencian** en la ventana de mantenimiento declarada: un
  fichero que desaparece durante una actualización es justo lo que hay que saber.
