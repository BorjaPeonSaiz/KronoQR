# Runbook — turno abierto prolongado

**Esto no es una avería. Es trabajo de gestión sobre el registro horario**, y por
eso el destinatario es **RRHH** y no el IT del cliente: nadie del equipo técnico
puede resolverlo, porque lo que falta es que una persona decida a qué hora salió
otra persona.

**Alertas que llevan aquí** (doc 01 §9.3, fila *«Turnos abiertos > 12 h |
cualquiera | Media | RRHH»*), definidas en
[`infra/observability/prometheus/rules/incidents.yml`](../../infra/observability/prometheus/rules/incidents.yml):

| Alerta | Umbral | Severidad | Destinatario | Sección |
| --- | --- | --- | --- | --- |
| `TurnoAbiertoProlongado` | `incidents_open{type="open_shift_expired"} > 0`, `for: 15m` | Media | RRHH | [§3](#3-turno-abierto-por-encima-del-máximo) |
| `DescansoEntreJornadasInsuficiente` | `incidents_open{type="insufficient_rest"} > 0`, `for: 15m` | Media | RRHH | [§4](#4-descanso-entre-jornadas-por-debajo-del-mínimo) |
| `MetricaDeIncidenciasAusente` | > 30 min sin recuento, `for: 30m` | Media | IT del cliente | [§5](#5-nadie-está-contando-el-silencio) |

**Impacto en el fichaje, que es lo primero que hay que saber: ninguno.** Nadie se
ha quedado sin poder fichar. Quien tiene el turno abierto puede seguir pasando su
tarjeta con normalidad — de hecho, su próximo escaneo cerrará ese turno. La regla
dura 19 sigue intacta: el quiosco nunca bloquea al empleado.

---

## 1. Lo que hay que tener claro antes de abrir nada

**El sistema NUNCA cierra un turno por su cuenta.** Es RN-08, literal: *«duración
máxima de tramo antes de considerarse anómalo: 12 h (configurable). **Nunca se
cierra automáticamente** sin intervención humana»*. No es una limitación técnica
pendiente de resolver: si el sistema cerrara el turno a las 12 h, el registro
diría que esa persona trabajó 12 h cuando probablemente trabajó 8 y se olvidó de
fichar. Un registro horario que se inventa una hora de salida no es defendible
ante la Inspección, y además puede pagar de menos o de más en la nómina.

Lo que el sistema hace es **abrir una incidencia** `open_shift_expired`,
asignarla al responsable del departamento y avisarle. La corrección la firma una
persona.

| Pieza | Qué hace | Dónde |
| --- | --- | --- |
| `attendance:detect-incidents` | Revisa el registro y abre incidencias, 04:30 UTC | Contenedor `scheduler` |
| `compliance:incident-metrics` | Recalcula `incidents_open`, cada 5 min | Contenedor `scheduler` |
| `incidents` | La bandeja: una fila por hallazgo, con su tipo y su responsable | PostgreSQL |
| `incidents_open{type,severity}` | Gauge. **Baja al resolver**, no solo sube | `BACKUP_PATH/metrics/kronoqr_incidents.prom` |
| `ATTENDANCE_MAX_SHIFT_HOURS` | El umbral, 12 h de serie. Es **configuración**, no una constante | `installation_settings` |

**Los tramos abiertos se revisan siempre, sin ventana.** El resto de reglas mira
solo los últimos `COMPLIANCE_INCIDENT_LOOKBACK_DAYS` días (7 de serie), pero un
turno sin cerrar no es histórico: sigue creciendo, y sigue apareciendo hasta que
alguien lo corrija. Por eso un olvido de hace tres meses no desaparece solo.

---

## 2. Diagnóstico en un minuto

Quién tiene el turno abierto y desde cuándo:

```bash
docker compose -f infra/compose.prod.yaml exec -T app php artisan tinker --execute="
  DB::table('incidents')
    ->join('employees', 'employees.id', '=', 'incidents.employee_id')
    ->where('incidents.type', 'open_shift_expired')
    ->where('incidents.status', 'open')
    ->orderBy('incidents.work_date')
    ->get(['employees.employee_code', 'employees.first_name', 'employees.last_name',
           'incidents.work_date', 'incidents.context'])
    ->each(fn(\$i) => print_r(\$i));
"
```

Salida esperada: una fila por persona, con la jornada afectada y un `context` que
dice cuántos minutos lleva abierto el tramo y con qué umbral se comparó.

Si la lista está vacía y la alerta sigue sonando, la incidencia ya se resolvió y
el gauge todavía no se ha refrescado: espera un ciclo de cinco minutos.

---

## 3. Turno abierto por encima del máximo

**Lo que significa:** alguien fichó la entrada y no fichó la salida. Las causas
reales, por frecuencia:

1. **Olvido de fichaje de salida.** Es la inmensa mayoría.
2. **La tablet estaba apagada o sin red al salir** y el escaneo no llegó. Mira si
   hay alertas de quiosco (`quiosco-no-responde.md`) de esa franja.
3. **Turno partido mal fichado**: salió a comer sin fichar y volvió fichando
   entrada, que el sistema rechaza por RN-01. En ese caso habrá además un
   escaneo rechazado en `scan_events`.
4. **Trabajó de verdad más de 12 h.** Ocurre en temporada alta. Sigue siendo
   correcto abrir la incidencia: es exactamente la jornada que hay que mirar.

**Lo que NO significa:** que la persona esté todavía dentro del hotel, ni que
haya hecho nada mal.

### Procedimiento

1. **Contacta con la persona o con su responsable** y pregunta a qué hora salió.
   No supongas la hora: la que se escriba es la que irá en su nómina y en el
   registro que ve la Inspección.
2. **Corrige el tramo desde el panel** (RF-PA-04): jornada de la persona → el
   tramo abierto → *Cerrar con hora*.
3. **Motivo obligatorio del catálogo** (doc 01, Anexo C):
   `OLVIDO_FICHAJE_SALIDA`. Si el caso es otro —fallo del quiosco—, usa
   `FALLO_TECNICO_QUIOSCO`. `OTROS` exige texto libre de al menos 20 caracteres.
4. **La corrección queda trazada sola**: crea una versión nueva del tramo,
   conserva la anterior con autor, momento y motivo (RN-13, RL-04) y deja asiento
   en `audit_log`. No hay nada que anotar aparte.
5. **Resuelve la incidencia** en la bandeja, indicando qué se hizo. Si la jornada
   era correcta —trabajó de verdad esas horas—, **descártala** en vez de
   resolverla: son dos estados distintos a propósito, y esa diferencia es la que
   permite ajustar el umbral con datos.

El gauge baja en el siguiente ciclo de cinco minutos y la alerta se apaga sola.
**Una incidencia cerrada no vuelve a abrirse**: la revisión de la noche siguiente
no la repite aunque la jornada siga dentro de su ventana. Si te aparece otra
sobre la misma persona y el mismo día, es porque el tramo se corrigió y hay un
tramo nuevo que mirar (ADR-035).

### Lo que no hay que hacer

- **No** cerrar el turno a la hora del umbral «para que deje de sonar». Eso
  escribe una hora que nadie ha verificado.
- **No** anular el tramo. La entrada ocurrió; anularla borra del registro una
  jornada que la persona sí trabajó (RN-13, regla dura 5).
- **No** subir `ATTENDANCE_MAX_SHIFT_HOURS` para silenciar la alerta. Si de
  verdad los turnos de ese centro llegan a 13 h, súbelo como decisión consciente
  y documentada, no como forma de callar el aviso.

---

## 4. Descanso entre jornadas por debajo del mínimo

**Lo que significa:** entre el fin de una jornada y el inicio de la siguiente han
mediado menos horas que el mínimo del perfil de cumplimiento (RN-10; 12 h en el
perfil `ES-hosteleria`, art. 34.3 ET). El `context` de la incidencia dice el
descanso real en minutos y el umbral aplicado.

**Es un hecho ya ocurrido y no se puede deshacer.** Lo que se hace con él:

1. **Comprueba que las horas son correctas.** Un descanso corto suele ser en
   realidad un olvido de fichaje del día anterior: si la salida se registró a las
   23:00 porque nadie fichó a las 21:00, el descanso real era mayor. Corrige el
   tramo antes de sacar ninguna conclusión.
2. **Si las horas son correctas**, es una incidencia de planificación: revisa el
   cuadrante con quien lo hace. Un cierre y una apertura seguidos —el clásico
   *turno partido de cierre y apertura*— es el caso típico.
3. **Resuelve la incidencia dejando escrito qué se hizo.** Si se repite en la
   misma persona o en el mismo puesto, es material para la revisión del convenio,
   no para cerrar y olvidar.

**No cambies el perfil de cumplimiento para que deje de saltar.** Los umbrales de
`compliance_profiles` los fija la jurisdicción, no la operación del hotel
(regla dura 14).

---

## 5. Nadie está contando (el silencio)

`MetricaDeIncidenciasAusente` significa que el fichero del colector *textfile*
lleva más de media hora sin refrescarse. **Las dos alertas de arriba están
ciegas**: no distinguen «no hay ninguna incidencia» de «nadie está contando».

Esta sí es para el IT del cliente, porque lo que falla es el planificador y no el
registro. El fichaje y la detección siguen funcionando: lo que falta es la
publicación de la métrica.

```bash
# ¿Corre el planificador?
docker compose -f infra/compose.prod.yaml ps scheduler

# ¿Escribe el fichero?
docker compose -f infra/compose.prod.yaml exec -T app php artisan compliance:incident-metrics
docker compose -f infra/compose.prod.yaml exec -T app sh -c 'ls -l "$BACKUP_PATH/metrics/kronoqr_incidents.prom"'
```

Causas por frecuencia: el contenedor `scheduler` parado, `BACKUP_PATH` sin
permisos de escritura para el usuario de la aplicación, o
`OBSERVABILITY_METRICS_ENABLED` en `false`.

---

## 6. Cuándo escalar

| Situación | A quién | En cuánto |
| --- | --- | --- |
| La misma persona acumula olvidos cada semana | Responsable del departamento | Siguiente revisión de equipo |
| Turnos abiertos en varias personas del mismo quiosco el mismo día | IT del cliente — mira `quiosco-no-responde.md` | El mismo día |
| `insufficient_rest` recurrente en un puesto | Quien planifica el cuadrante, y RRHH | El mismo día |
| Incidencias sin responsable asignado (`assigned_to_user_id` a nulo) | Administrador: falta responsable en el departamento | El mismo día |

**Una incidencia sin responsable no se pierde**: sigue abierta y visible en la
bandeja, pero **nadie recibe el aviso por correo**. Si aparecen varias, lo que
falta es asignar el responsable del departamento en el panel.
