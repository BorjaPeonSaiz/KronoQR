# Runbook — requerimiento de la Inspección de Trabajo

**Cómo generar la exportación legal en menos de una hora**, con o sin panel.

Esto **no** es una alerta: nadie te avisa. Llega un requerimiento por escrito,
con un plazo, y hay que contestarlo. Este procedimiento existe para que la
respuesta no dependa de que ese día funcione el navegador de nadie.

**Impacto en el fichaje: ninguno.** La exportación solo lee. El quiosco sigue
funcionando mientras se genera, y generarla dos veces no rompe nada — deja dos
asientos en `audit_log`, que es lo correcto: se pidió dos veces.

**Requisitos que cumple:** RF-IN-05 (exportación normalizada), RL-03 (los
registros están a disposición de la Inspección con capacidad de entrega),
RL-06 (formato legible y tratable, no propietario), RL-04 y RN-13 (las
correcciones constan con autor, momento y motivo), art. 34.9 del Estatuto de los
Trabajadores.

---

## 0. Los cinco minutos que ahorran la hora

Antes de nada, tres datos del requerimiento:

| Dato | Dónde está en el requerimiento | Qué se le pasa al sistema |
| --- | --- | --- |
| **Periodo** | «del … al …» | `--from` y `--to`, en `YYYY-MM-DD` |
| **Alcance** | «de la totalidad de la plantilla» / «de D./Dña. …» | nada, o `--employee=<uuid>` |
| **Centro** | «del centro de trabajo sito en …» | *no se filtra: ver §5* |

**Los dos extremos del periodo son inclusivos** y se expresan por **fecha de
jornada**, no por fecha civil de la marca: un turno que entró el 31 a las 22:00
pertenece al 31 aunque saliera el 1 (RN-05). Si el requerimiento dice «enero», se
pide `--from=2026-01-01 --to=2026-01-31` y el turno de nochevieja no se parte.

---

## 1. La vía corta: por consola (5 minutos)

Es la que hay que usar por defecto. No necesita sesión, ni navegador, ni que el
panel esté desplegado.

```bash
# En el servidor del cliente, desde el directorio de la instalación
docker compose -f infra/compose.prod.yaml exec -T app \
  php artisan compliance:legal-export --from=2026-01-01 --to=2026-01-31
```

Salida esperada:

```
Exportacion legal generada.
+--------------+------------------------------------------------------------------------------------+
| Concepto     | Valor                                                                              |
+--------------+------------------------------------------------------------------------------------+
| Periodo      | 2026-01-01 → 2026-01-31                                                            |
| Alcance      | plantilla completa                                                                 |
| Tramos       | 1240                                                                               |
| Correcciones | 17                                                                                 |
| Trabajadores | 63                                                                                 |
| Fichero      | /var/www/html/storage/app/legal-exports/registro-horario-2026-01-01_2026-01-31.csv  |
+--------------+------------------------------------------------------------------------------------+
```

**Apunta las tres cifras.** Son las que quedan en `audit_log` y las que
permiten, meses después, demostrar que lo entregado es lo que se generó.

Variantes:

```bash
# Una sola persona (el UUID sale del panel o de la §4)
… php artisan compliance:legal-export --from=2026-01-01 --to=2026-01-31 --employee=0199f0c2-1f4a-7c3e-9b21-4d5e6f7a8b90

# A una ruta concreta, por ejemplo un directorio compartido
… php artisan compliance:legal-export --from=2026-01-01 --to=2026-01-31 --output=/var/backups/fichaje/requerimiento-2026-02.csv
```

Sacar el fichero del contenedor:

```bash
docker compose -f infra/compose.prod.yaml cp \
  app:/var/www/html/storage/app/legal-exports/registro-horario-2026-01-01_2026-01-31.csv .
```

---

## 2. La vía del panel

Para quien atiende el requerimiento desde RRHH y no tiene acceso al servidor.

1. Entrar en el panel con una cuenta **`rrhh`**, **`auditor`** o **`admin`**.
   Ningún otro rol puede: el endpoint entrega la lista nominal de la plantilla
   con sus horas (regla dura 18, RF-ID-03).
2. Sección **Inspección** de la barra de navegación (`/admin/reports/legal-export`).
   Si la cuenta no la ve, es que su token no lleva `reports:legal` ni `reports:*`:
   el panel esconde lo que el servidor devolvería como 403.
3. Periodo y, si procede, la persona. **Generar y descargar**.

Equivalente en `curl`, por si hace falta desde otra máquina:

```bash
curl -sS -D - -o registro-horario.csv \
  -H "Authorization: Bearer $TOKEN" \
  "https://<host>/api/v1/reports/legal-export?from=2026-01-01&to=2026-01-31"
```

La respuesta trae dos cabeceras que hay que mirar **antes** de enviar nada:

```
X-Kronoqr-Export-Shift-Rows: 1240
X-Kronoqr-Export-Correction-Rows: 17
```

Son las mismas cifras del asiento de auditoría. Si el fichero descargado tiene
menos filas que eso, la descarga se cortó: repítela.

---

## 3. Comprobar el fichero antes de entregarlo

Cuatro comprobaciones, dos minutos.

```bash
# 1. Lleva marca de orden de bytes (BOM). Sin ella, Excel en español rompe los acentos.
head -c 3 registro-horario.csv | xxd
# esperado: 00000000: efbb bf   ...

# 2. Declara sus criterios y su base legal.
head -15 registro-horario.csv

# 3. Las filas de datos son las que dijo el comando.
grep -c '^TRAMO;' registro-horario.csv
grep -c '^CORRECCION;' registro-horario.csv

# 4. No hay ni una hora en decimal. Esta orden NO debe devolver nada.
grep -E ';[0-9]+[.,][0-9]+;' registro-horario.csv
```

**Abrirlo con la hoja de cálculo** (verificación manual, una vez por versión del
producto):

- **LibreOffice Calc**: abrir → juego de caracteres *Unicode (UTF-8)*, separador
  *punto y coma*. Los acentos y las comillas españolas (`«…»`) se ven bien y cada
  columna cae en su sitio.
- **Excel** (configuración regional española): doble clic. El BOM y el punto y
  coma son justo lo que hace que no haga falta el asistente de importación.

**Qué tiene que verse**, y es lo que hay que saber explicar si preguntan:

| Fila | Qué es |
| --- | --- |
| `TRAMO` | Un periodo de trabajo. Un turno de noche es **una sola fila**, en la jornada en que empezó |
| `CORRECCION` | Una rectificación del registro, con **autor, momento y motivo**, y las marcas de antes y después |

- Las horas van **dos veces**: en la zona horaria del centro (la que el
  trabajador vivió) y en UTC (la almacenada).
- Las duraciones son `HH:MM`. **Nunca decimales.**
- Los tramos **anulados aparecen**, con su estado, y no suman horas al total del
  día. Nada se oculta y nada se cuenta dos veces.
- Las versiones **sustituidas** por una corrección no aparecen como tramo: lo que
  decían está en la columna «Correccion: antes». No se ha borrado nada.

---

## 4. Cuando el requerimiento nombra a una persona

Necesitas su `employee_uuid`. Si no lo tienes a mano:

```bash
docker compose -f infra/compose.prod.yaml exec -T postgres \
  psql -U fichaje_app -d fichaje -c \
  "SELECT uuid, employee_code FROM employees WHERE employee_code = 'E0042';"
```

Búscala por **código de empleado**, no por nombre: dos personas pueden llamarse
igual, y equivocarse de persona en un requerimiento es una brecha de datos
personales, no una errata.

La exportación de una sola persona deja su `employee_uuid` en `audit_log`. Es
deliberado: permite responder «¿quién pidió el registro de esta persona?» si
algún día lo pregunta ella.

---

## 5. Lo que este procedimiento **no** hace todavía

Dilo antes de que lo pregunten:

- **No filtra por centro ni por departamento.** El endpoint tiene dos alcances:
  la plantilla completa o una persona. Si el requerimiento se limita a un centro
  y la instalación tiene varios, se entrega la exportación completa y se acota
  con la columna «Centro», que va en cada fila. Un filtro por centro es trabajo
  pendiente, no un descuido: cada alcance nuevo tiene que poder describirse en el
  asiento de `audit_log` y en la cabecera del propio fichero.
- **No genera PDF ni hoja de cálculo.** RL-06 pide formato *no propietario*; los
  formatos ofimáticos de conveniencia son otra funcionalidad (tarea 2.9).
- **No firma el fichero.** Lo que sostiene su integridad es `audit_log`, que es
  solo-append y está encadenado por hash (ADR-010): si alguien discute lo
  entregado, se contrasta contra el registro, no contra una firma del adjunto.

---

## 6. Qué queda registrado, y qué decir si lo preguntan

Toda generación —por consola o por panel— escribe en `audit_log` (regla dura 6,
RS-05):

```bash
docker compose -f infra/compose.prod.yaml exec -T postgres \
  psql -U fichaje_app -d fichaje -c \
  "SELECT occurred_at, actor_type, actor_id, payload
     FROM audit_log
    WHERE action = 'legal_export.generated'
    ORDER BY id DESC LIMIT 5;"
```

Qué lleva el asiento: periodo, alcance y **cuántas** filas y personas. Qué **no**
lleva: la lista de personas exportadas ni el contenido del fichero. `audit_log`
se conserva cuatro años y no puede acabar siendo una segunda copia del registro
horario (regla dura 21, minimización del RGPD).

Desde consola el actor es `system`: no hay sesión detrás. Es la verdad —lo lanzó
alguien con acceso al servidor— y se cruza con el registro de acceso de la
máquina. Desde el panel el actor es la cuenta que descargó.

La métrica `legal_exports_total{scope}` sube en los dos casos
(`BACKUP_PATH/metrics/kronoqr_legal_exports.prom`). Una instalación que empieza a
exportar la plantilla completa todas las semanas está haciendo otra cosa distinta
de contestar a un requerimiento.

---

## 7. Después de entregar: custodia y borrado del fichero

El fichero que generó `compliance:legal-export` sigue en
`storage/app/legal-exports/` dentro del contenedor. **Nadie lo borra
automáticamente** (a propósito: es la única copia que se entrega a un
tercero, y un cron que la hiciera desaparecer sin que nadie lo decidiera
convertiría una limpieza en una pérdida de prueba). La custodia es
responsabilidad de quien la generó, con el mismo criterio que un documento en
papel:

1. **Mientras dura el procedimiento con Inspección**, consérvalo donde lo
   dejaste (dentro del contenedor, o la copia que sacaste con `docker compose
   cp` a la máquina desde la que se entregó). No hace falta guardarlo en dos
   sitios: `audit_log` ya prueba qué se generó y cuándo.
2. **Cuando el procedimiento se cierra** (resolución, archivo, o simplemente
   pasado el plazo de alegaciones sin novedad), bórralo:

   ```bash
   docker compose -f infra/compose.prod.yaml exec -T app \
     rm -f storage/app/legal-exports/registro-horario-2026-01-01_2026-01-31.csv
   ```

   Borra también cualquier copia que hayas sacado a un disco personal o a un
   recurso compartido fuera de la instalación: el registro horario nominal no
   debe acumularse en más sitios de los necesarios (minimización del RGPD,
   regla dura 21).
3. **No hace falta borrar el asiento de `audit_log`.** Al contrario: es la
   prueba de que la exportación existió, se pidió y se entregó, y se conserva
   con la retención general de auditoría. Lo que se borra es el fichero, no
   su rastro.
4. **Si el requerimiento fue recurrente** (una exportación por mes, por
   ejemplo), no dejes acumularse un fichero por mes indefinidamente: aplica el
   mismo criterio de los puntos 1-2 a cada uno según se cierre su propio
   procedimiento.

**El temporal de la descarga por panel (`storage/framework/legal-exports/`,
§2) es distinto y no necesita este procedimiento**: `compliance:purge-legal-export-temp`
lo borra solo, cada hora, pasada una ventana de
`COMPLIANCE_LEGAL_EXPORT_TEMP_RETENTION_HOURS` (6 horas por defecto). Esa
limpieza automática existe **solo** para el huérfano de una descarga
abortada a medias — nunca toca la copia de este apartado.

---

## 8. Si algo falla

| Síntoma | Causa probable | Qué hacer |
| --- | --- | --- |
| `El campo «from» … no es una fecha en forma YYYY-MM-DD` | Fecha en formato español o con hora | `2026-01-01`, sin hora. El periodo es por fecha de jornada |
| `El periodo … termina antes de empezar` | `--from` y `--to` cambiados | Corrígelos. **El sistema no los da la vuelta solo**: el fichero llevaría escrito un periodo que nadie pidió |
| El comando termina sin fichero y con un error de escritura | Ruta de `--output` inexistente o sin permisos | Usa la ruta por defecto y saca el fichero con `docker compose cp` |
| El comando falla en el asiento de auditoría | `audit_log` sin partición del año, o permisos | La exportación **no se da por hecha**: es lo correcto. Ve a [`rotura-cadena-auditoria.md`](rotura-cadena-auditoria.md) §5 |
| El panel devuelve 403 | El rol no es `rrhh`, `auditor` ni `admin`, o el token no tiene ámbito | Usa una cuenta de RRHH, o la vía de consola de la §1 |
| Tarda más de unos minutos | Periodo muy largo sobre una instalación grande | Déjalo terminar: escribe en streaming y no carga el periodo en memoria. Si hay plazo, parte el periodo por meses |

**Escalado:** si a los 30 minutos no hay fichero, avisa al responsable de la
instalación y contesta al requerimiento pidiendo ampliación de plazo con lo que
haya. No entregues un fichero incompleto: es peor que entregarlo tarde.
