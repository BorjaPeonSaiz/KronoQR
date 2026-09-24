# Runbook — solicitud de derechos RGPD de una persona trabajadora

**Esto no es una alerta: es un plazo.** El RGPD da **un mes** para responder
(art. 12.3), prorrogable dos meses más si la solicitud es compleja, avisando en
el primer mes. Empieza a contar el día que la solicitud entra, no el día que
alguien la lee.

**Requisito que cubre:** RL-10 (derechos del interesado) y RL-11 (retención por
tipo de dato). Lo aplica el **responsable del tratamiento**, que es el hotel —el
fabricante del software no accede a los datos (ADR-020) y no puede atender una
solicitud en nombre de nadie—.

---

## 0. La frase que hay que tener clara antes de empezar

> **El registro horario dentro de sus cuatro años no se suprime a petición.**

No es una decisión del producto: el art. 34.9 del Estatuto de los Trabajadores
obliga al empleador a **conservar** los registros de jornada cuatro años, y el
RGPD (art. 17.3.b) excluye expresamente del derecho de supresión los datos cuyo
tratamiento es necesario «para el cumplimiento de una obligación legal». La
persona puede pedir la supresión, y la respuesta correcta —motivada y por
escrito— es que **no procede** mientras dure el deber de conservación, indicando
la fecha a partir de la cual sí procederá.

Lo que sí procede siempre: **acceso**, **portabilidad**, **rectificación** y la
**limitación** del tratamiento mientras se resuelve una impugnación de exactitud.

---

## 1. Qué se conserva, dónde y hasta cuándo

| Dato | Dónde | Plazo | De dónde sale el plazo |
| --- | --- | --- | --- |
| Registro de jornada (tramos, totales, correcciones, escaneos, incidencias) | PostgreSQL | **4 años** | `compliance_profiles.retention_years` del centro (RF-PD-07) |
| Auditoría (`audit_log`) | PostgreSQL, particionada por año | **4 años** | El mismo perfil de cumplimiento |
| Ficha de la persona (`employees`), credenciales | PostgreSQL | Mientras haya relación laboral, y después lo que exija la normativa laboral y fiscal aplicable | Decisión del responsable del tratamiento |
| Contratos (`employment_contracts`: horas pactadas, tipo de jornada, vigencia) | PostgreSQL | **Duración de la relación laboral + 4 años**, orientativo (art. 21 LISOS, a validar) | **Pendiente de la asesoría laboral (tarea 5.2).** Hoy **no hay purga automática**: se conserva |
| Ausencias (`absences`: tipo, fechas, nota, versiones y anulaciones) | PostgreSQL | **Duración de la relación laboral + 4 años**, orientativo (art. 21 LISOS, a validar) | **Pendiente de la asesoría laboral (tarea 3.10).** Hoy **no hay purga automática**: se conserva. **Contiene dato de salud** |
| Log técnico | `storage/logs` | **90 días** | `TECHNICAL_LOG_RETENTION_DAYS` |
| Histórico de errores (`error_events`) | PostgreSQL | **90 días** | `ERROR_HISTORY_RETENTION_DAYS` |
| Copias de seguridad | `BACKUP_PATH` | `BACKUP_RETENTION_DAYS` (30 de serie) | Configuración de la instalación |

**Contratos y ausencias quedan fuera de la purga automática a propósito.** El
ámbito de retención que ejecuta la purga es una **lista cerrada** —registro de
jornada, auditoría, log técnico e histórico de errores— y ninguna de esas dos
tablas entra en ella. No es un olvido: el plazo no está validado, y purgar con un
plazo que luego resulte corto destruye datos que puede haber obligación de
conservar. Hasta que la asesoría laboral del cliente lo fije, las dos se
conservan y salen en cualquier respuesta a un derecho de acceso. En las
ausencias, además, `note` puede contener **dato de salud** (art. 9 RGPD): se
conserva y se entrega tal cual, y por eso la guía de RRHH pide que ahí no se
escriba el diagnóstico ([`../cliente/guia-rrhh.md`](../cliente/guia-rrhh.md)
§5 bis.5).

**Ni el log técnico ni el histórico de errores llevan nombres** (regla dura 21):
identifican con `employee_uuid`. No hace falta buscar ahí para atender un derecho
de acceso, y no se puede «suprimir un nombre» de donde no lo hay.

**Las copias de seguridad no se editan.** Una supresión aplicada hoy no reescribe
las copias de los treinta días anteriores; se aplica al sistema vivo y las copias
caducan solas. Esto es doctrina consolidada de la AEPD y conviene decirlo en la
respuesta: *«la supresión se ha aplicado al sistema; las copias de seguridad que
aún la contengan expiran el DD/MM/AAAA»*.

---

## 2. Identificar a quien solicita — antes de responder nada

Una respuesta de acceso entregada a quien no es el interesado **es una brecha**.
Comprueba la identidad con el mismo criterio con el que se entrega una nómina
(documento de identidad, o solicitud desde el canal interno acreditado). Si la
solicitud llega por un tercero, exige la representación por escrito.

Anota en el registro interno de solicitudes: fecha de entrada, identidad
comprobada, derecho ejercido, fecha de respuesta.

---

## 3. Derecho de ACCESO y de PORTABILIDAD

Lo que hay que entregar es el registro horario de esa persona. El producto ya lo
produce en el formato normalizado que la Inspección acepta (RF-IN-05, RL-06):

```bash
docker compose --env-file .env -f infra/compose.dev.yaml exec app \
  php artisan compliance:legal-export --from=2023-01-01 --to=2026-12-31 --employee=<employee_uuid>
```

- El `employee_uuid` se ve en la ficha del panel. **Nunca** uses el nombre para
  acotar la exportación: el UUID es el identificador estable.
- El fichero cae en `storage/app/legal-exports/`. **Su custodia y su borrado son
  tuyos**: no lo limpia ningún cron (ver `requerimiento-inspeccion.md` §6).
- La generación **queda auditada** (`legal_export.generated`), que es lo que
  permite acreditar después que se atendió la solicitud.
- Si la persona pide además sus datos de ficha —departamento, código de empleado,
  fechas de alta y baja—, se exportan desde el panel; no llevan más de lo que
  ella ya conoce.

**Las ausencias no salen en esa exportación, y hay que añadirlas a mano.**
`compliance:legal-export` produce el registro horario del art. 34.9 ET, que no
incluye las ausencias; y el portal del empleado **tampoco las muestra**, así que
el interesado no tiene forma de obtenerlas por su cuenta. Si la solicitud es de
acceso completo —y el art. 15 lo es—, hay que sacarlas aparte:

- Desde una cuenta con rol `rrhh` (o `admin`), pide
  `GET /api/v1/absences?employee_uuid=<uuid>&status=all`. El `status=all` es
  necesario: sin él no salen las versiones supersedidas ni las anuladas, y el
  derecho de acceso alcanza a **todo** lo que se conserva sobre la persona,
  también a lo que se corrigió y a lo que se anuló.
- La lectura **queda auditada** (`personal_data.accessed`, conjunto
  `absence_register`), igual que cualquier otro acceso a datos de un tercero
  (RS-05). El asiento registra quién miró y con qué alcance, nunca el dato.
- **La nota se entrega tal como esté escrita**, y puede contener dato de salud.
  Revísala antes de enviar: no para censurarla —el interesado tiene derecho a
  ver lo que hay escrito sobre él—, sino para saber qué estás entregando y por
  qué canal.
- **No uses una cuenta de `responsable_departamento` para esto.** A ese rol el
  campo de la nota no le llega, y la respuesta saldría incompleta sin que nada
  lo advierta.

**Las incidencias tampoco salen en esa exportación, y también hay que añadirlas
a mano.** La bandeja guarda sobre cada persona lo que la detección encontró y
lo que alguien escribió al cerrarlo, y el derecho de acceso alcanza a las dos
cosas: el contexto (`context`) y la nota de resolución (`resolution_note`), con
su estado, su desenlace y sus fechas. Se extraen así:

```bash
docker compose --env-file .env -f infra/compose.dev.yaml exec -T app php artisan tinker --execute="
  DB::table('incidents')
    ->join('employees', 'employees.id', '=', 'incidents.employee_id')
    ->where('employees.uuid', '<employee_uuid>')
    ->orderBy('incidents.work_date')
    ->get(['incidents.type', 'incidents.work_date', 'incidents.severity', 'incidents.status',
           'incidents.detected_at', 'incidents.resolved_at', 'incidents.context',
           'incidents.resolution_note'])
    ->each(fn(\$i) => print_r(\$i));
"
```

- **En las de tipo `anomalous_pattern` hay un dato de un tercero, y no se
  entrega.** El contexto de una coincidencia lleva `counterpart_employee_uuid`
  —la otra persona— y `counterpart_count`. El art. 15.4 RGPD limita el acceso
  donde afecte a los derechos de otros: **quita el identificador de la
  contraparte antes de entregar, y no lo traduzcas a un nombre**. Se entrega el
  resto —quiosco, días, momentos, huecos, umbrales, estado, nota— y, si hace
  falta, se dice que existe una contraparte sin identificarla. La nota de
  resolución se entrega tal como esté escrita: es la razón por la que el
  runbook [`patron-anomalo-credencial.md`](patron-anomalo-credencial.md) §4
  exige que describa lo contrastado y no califique a nadie.
- **`context` no lleva nombres de nadie** (regla dura 21): quioscos por su
  rótulo, momentos, números y, en el caso anterior, el identificador que se
  retira. No hay nada más que revisar.
- Como con las ausencias, hazlo con `admin` o `rrhh`: el
  `responsable_departamento` solo alcanza su departamento y la respuesta
  saldría incompleta.

**Portabilidad**: el mismo CSV sirve. Es un formato estructurado, de uso común y
lectura mecánica (art. 20.1 RGPD). Las ausencias y las incidencias se adjuntan
en el mismo envío, en el formato en que las devuelvan las consultas anteriores.

### 3 bis. «A quién se comunicaron sus datos» (art. 15.1.c)

El derecho de acceso incluye **los destinatarios** a los que se han comunicado
los datos. En este producto son tres caminos, y los tres dejan asiento en
`audit_log`: el **resumen semanal por correo** al responsable de departamento
(`weekly_summary`), la **salida a nómina** hacia el programa de nómina del hotel
(`payroll_export`) y los **informes generados en segundo plano** que alguien
descargó con su enlace de un solo uso (`report_export.generated`). La consulta
es por `employee_uuid`, nunca por nombre:

```bash
docker compose --env-file .env -f infra/compose.prod.yaml exec -T postgres \
  psql -U fichaje_app -d fichaje -c "
  -- (a) Asientos que NOMBRAN a la persona: el resumen semanal cuando el
  --     alcance tenía 50 personas o menos.
  SELECT occurred_at, action, actor_type, actor_id,
         payload->>'dataset'         AS conjunto,
         payload->>'manager_user_id' AS responsable,
         payload->>'week_start'      AS semana
    FROM audit_log
   WHERE action = 'personal_data.accessed'
     AND payload->>'dataset' = 'weekly_summary'
     AND payload->'employee_uuids' ? '<employee_uuid>'
   ORDER BY occurred_at;"

docker compose --env-file .env -f infra/compose.prod.yaml exec -T postgres \
  psql -U fichaje_app -d fichaje -c "
  -- (b) Asientos EN BLOQUE que pudieron contenerla: se resuelven por alcance,
  --     periodo y departamento, no por identificador.
  SELECT occurred_at, action, actor_type, actor_id,
         payload->>'dataset'        AS conjunto,
         payload->>'kind'           AS tipo,
         payload->>'format'         AS formato,
         payload->>'scope'          AS alcance,
         payload->>'department_id'  AS departamento,
         payload->>'manager_user_id' AS responsable,
         payload->>'week_start'     AS semana,
         payload->>'employees'      AS personas
    FROM audit_log
   WHERE (action = 'personal_data.accessed'
          AND payload->>'dataset' IN ('weekly_summary','payroll_export'))
      OR action = 'report_export.generated'
   ORDER BY occurred_at;"
```

- **En `weekly_summary` la lista nominal solo está cuando el alcance tenía 50
  personas o menos.** Por encima, el asiento lleva `employees` (recuento),
  `scope`, `manager_user_id` y `week_start`, y ni un identificador: la persona
  iba dentro si estaba de alta en el departamento de ese responsable esa
  semana. No concluyas «no se comunicó» porque la consulta (a) no devuelva
  filas: pasa siempre por la (b).
- **`payroll_export` y `report_export.generated` no nombran a nadie** (recuento,
  `format`, `scope` y, en el segundo, `kind`, `sha256` y `row_count`). La
  persona iba dentro si el alcance de quien lo pidió la incluía en ese periodo;
  y en el informe en diferido el destinatario real es quien descargó con el
  enlace (`report_export.downloaded`, mismo `report_export_uuid`), que no
  tiene por qué ser quien lo pidió. Si el fichero ya se purgó, la fila
  perdió los identificadores: queda el asiento, no la lista.
- **Lo que se responde** es la categoría de destinatario y la fecha: «su
  responsable de departamento, por el resumen semanal de la semana X», «el
  programa de nómina del hotel, exportación del periodo Y», «informe de horas
  por periodo descargado el día Z». No se entregan identificadores de terceros
  (art. 15.4). El aviso diario de incidencias (`incident_digest`) sigue el mismo
  camino que la consulta (a) del runbook de brecha
  ([`brecha-de-seguridad.md`](brecha-de-seguridad.md) §4.1).

---

## 4. Derecho de RECTIFICACIÓN — que no es un borrado

Si la persona dice que una jornada está mal registrada, **no se edita la fila**.
Se corrige creando una versión nueva que conserva la anterior con autor, momento
y motivo (RN-13, RL-04, regla dura 5):

1. Panel → jornada → **Corregir**, con el motivo del catálogo
   (`OLVIDO_FICHAJE_SALIDA`, `AJUSTE_ACORDADO_CON_RRHH`…).
2. La corrección deja asiento en `audit_log` (`shift_entry.modified`) y una fila
   en `shift_corrections` con el antes y el después.
3. Si el motivo es «OTROS», el texto es obligatorio y de al menos 20 caracteres:
   una corrección sin explicación no es defendible ante la Inspección.

**Por qué así.** Un registro horario que se puede editar sin dejar rastro no vale
como prueba —ni a favor del hotel ni a favor de la persona—. La rectificación del
RGPD se satisface igual: el dato vigente pasa a ser el correcto y queda constancia
de que se corrigió.

Si lo que la persona impugna está **fuera** de los cuatro años y ya se purgó, no
hay nada que rectificar; se responde diciendo eso, con la fecha de la purga y el
número de informe (ver §5).

---

## 5. Derecho de SUPRESIÓN — solo de lo que ya no está bajo deber de conservación

1. **Comprueba el plazo.** Lanza la propuesta de retención, que no borra nada:

   ```bash
   docker compose --env-file .env -f infra/compose.dev.yaml exec app \
     php artisan compliance:apply-retention --dry-run
   ```

   El informe dice la **fecha de corte** —«anterior a AAAA-MM-DD»— y cuántos
   registros hay vencidos. Todo lo posterior a esa fecha está bajo deber legal de
   conservación y **no se suprime**.

2. **Responde por escrito lo que no procede.** Modelo de párrafo:

   > *Su solicitud de supresión no puede atenderse respecto del registro de
   > jornada comprendido entre el DD/MM/AAAA y la fecha actual, por ser su
   > conservación una obligación legal del empleador (art. 34.9 ET) durante
   > cuatro años, conforme al art. 17.3.b) del RGPD. Dicha información se
   > suprimirá al vencer ese plazo, a partir del DD/MM/AAAA.*

3. **Lo que sí puede suprimirse** en el acto: datos de contacto opcionales que la
   instalación no necesita —el correo del empleado es opcional por diseño
   (ADR-015)— y cualquier dato aportado voluntariamente que no sostenga el
   registro. Se hace desde el panel, y queda auditado.

4. **La purga por vencimiento no es un derecho ejercido, es un vencimiento**, y
   se ejecuta con el procedimiento del §6 —no persona a persona—. Purgar la
   jornada de una sola persona porque lo ha pedido dejaría el registro del centro
   incompleto para el mismo periodo, que es precisamente lo que la Inspección
   mira.

---

## 6. Ejecutar la purga cuando vence el plazo

**Dos personas y dos credenciales**, a propósito: es la única operación del
producto que borra datos (regla dura 5).

1. **Propuesta.** El planificador la deja cada lunes en
   `storage/app/retention-reports/retencion-propuesta-*.txt`, y se puede pedir a
   mano con `--dry-run`. Léela: dice qué tablas, qué rangos de fecha y cuántas
   filas.

2. **Autorización.** El responsable del tratamiento (o quien tenga delegada la
   decisión) aprueba **ese informe**, no «la purga» en abstracto. La frase de
   confirmación que el informe imprime —`PURGAR-AAAA-MM-DD-xxxxxx`— cambia cuando
   cambia el corte o el perfil de cumplimiento: **un informe caducado no se puede
   ejecutar**.

3. **Ejecución**, con la credencial del rol de mantenimiento, que no vive en el
   `.env` de la aplicación (ADR-033):

   ```bash
   docker compose --env-file .env -f infra/compose.dev.yaml run --rm \
     -e DB_MAINTENANCE_PASSWORD='<la del rol fichaje_maintenance>' app \
     php artisan compliance:apply-retention \
       --confirm=PURGAR-AAAA-MM-DD-xxxxxx \
       --responsible=<id de la cuenta de gestión que autoriza>
   ```

4. **Archiva el informe de purga** (`retencion-purga-*.txt`) con la autorización.
   Es lo que acredita, si alguien pregunta dentro de dos años, que se borró lo que
   había que borrar y solo eso.

**Qué ocurre con la auditoría.** `audit_log` no se borra con `DELETE` nunca
(ADR-027): la partición del año vencido se **verifica**, se **sella** en
`audit_chain_anchors` y se **suelta entera**. Si la cadena de esa partición no
verifica, el comando **aborta y no toca nada**: eso es un incidente de seguridad
y se atiende con
[`rotura-cadena-auditoria.md`](rotura-cadena-auditoria.md), no insistiendo con la
purga.

---

## 7. Derecho de OPOSICIÓN y de LIMITACIÓN

- **Oposición al registro horario: no procede.** No se trata sobre la base del
  interés legítimo, sino de una obligación legal; no hay nada a lo que oponerse
  (art. 21 RGPD, en relación con el 6.1.c).
- **Limitación mientras se discute la exactitud** (art. 18.1.a): el mecanismo es
  la **corrección trazada** del §4 (RF-PA-04), no una marca aparte. Panel →
  detalle de la jornada → **Corregir**, con el motivo del catálogo que
  corresponda; si ninguno encaja, `OTROS` con el texto que explique que la
  exactitud está impugnada y por quién.

  Eso deja **una versión nueva conservando la anterior** (`shift_corrections`,
  con el antes y el después) y su asiento en `audit_log`
  (`shift_entry.modified`), que es exactamente la constancia que el art. 18.1.a
  pide: consta que el dato está en revisión, quién lo dijo, cuándo y por qué, y
  el original **no se pierde** (RN-13, RL-04, regla dura 5).

  **No hay apertura manual de incidencias en el panel** y este runbook no la
  supone: las incidencias las abre la detección automática (RF-PR-01) y el
  producto no ofrece crearlas a mano. Si al resolver la impugnación resulta que
  el dato era correcto, se documenta en el registro interno de solicitudes; si
  era incorrecto, la corrección que ya se hizo es la rectificación.

---

## 8. Lo que hay que dejar por escrito al cerrar

| Qué | Dónde |
| --- | --- |
| Solicitud, identidad comprobada y fecha | Registro interno de solicitudes del hotel |
| Exportación entregada | `audit_log`, acción `legal_export.generated` |
| Correcciones hechas | `audit_log` y `shift_corrections`, con motivo |
| Purga ejecutada, si la hubo | Informe en `storage/app/retention-reports/` y asiento en `audit_log` |
| Respuesta enviada y fecha | Registro interno de solicitudes del hotel |

---

## 9. Si la solicitud llega mezclada con una brecha

Si al atender la solicitud aparece un acceso indebido —alguien consultó datos que
no le correspondían—, **eso es otro procedimiento y tiene 72 horas**:
`brecha-de-seguridad.md`. El `audit_log` responde a la pregunta que la AEPD hace
primero: *qué cuentas accedieron a los datos de esa persona y cuándo* (RL-15).
