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
| Log técnico | `storage/logs` | **90 días** | `TECHNICAL_LOG_RETENTION_DAYS` |
| Histórico de errores (`error_events`) | PostgreSQL | **90 días** | `ERROR_HISTORY_RETENTION_DAYS` |
| Copias de seguridad | `BACKUP_PATH` | `BACKUP_RETENTION_DAYS` (30 de serie) | Configuración de la instalación |

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

**Portabilidad**: el mismo CSV sirve. Es un formato estructurado, de uso común y
lectura mecánica (art. 20.1 RGPD).

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
