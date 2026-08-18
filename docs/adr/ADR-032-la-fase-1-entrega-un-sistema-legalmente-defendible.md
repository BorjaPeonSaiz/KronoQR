# ADR-032 — La Fase 1 entrega un sistema legalmente defendible, no un piloto

| Campo | Valor |
|---|---|
| **Estado** | Aceptada |
| **Fecha** | 15 de agosto de 2026 |
| **Decide** | `arquitecto-dominio` |
| **Afecta a** | El plan de implementación completo (Fase 1 y Fase 2) · `docs/01-especificaciones-proyecto.md` Anexo A · `docs/requisitos.yaml` · **Regla dura 6** de `CLAUDE.md` |
| **Requisitos** | RN-13, RN-15, RS-07, RF-PA-03, RF-PA-04, RL-03, RL-04, RL-06, RF-IN-05, RF-PR-04, RNF-D-02, RNF-D-05, RQ-09, RL-09, RL-12 |

## Contexto

El plan de implementación describía la Fase 1 como *«corte MVP mínimo defendible»* y cerraba su
propio documento con una advertencia: al terminarla, el estado de venta es **«Piloto interno
controlado, no vendible»**. El doc 02 §11.2 explica por qué con una frase sin margen de
interpretación:

> **Fase 2 completa** → *Incumplimiento legal. Sin auditoría inmutable, retención y exportación
> para Inspección, el registro no satisface el art. 34.9 ET. Es el recorte que no se debe hacer.*

Un sistema que se anuncia como registro horario con valor legal y que, en su primer corte
funcional, no satisface la ley que le da sentido, no es un MVP: es un prototipo con etiqueta de
producto. Revisando el plan aparecen además contradicciones internas que agravan el problema, no
solo de alcance sino de coherencia:

- La tarea 1.4 (`RegisterScan`), la 1.5 (credenciales) y la 1.13 (PIN) **ya afirman escribir en
  `audit_log`**, cuya tabla y cadena por hash son de la tarea 2.2. La línea 526 del plan de Fase 1
  lo deja como decisión abierta: *«decidir si en la Fase 1 se escribe la entrada sin cadena y 2.2 la
  encadena, o si el registro de auditoría espera a la Fase 2»*. Es la **regla dura 6**, y hoy la
  lista de Terminado de la fase exige algo que ninguna tarea completa.
- `RN-15` (*«el horario de un fichaje offline es el `occurred_at` del dispositivo»*) está asignado
  a la Fase 2 en el Anexo A, pero gobierna la cola offline, que es la tarea **1.9**. El doc 05 §4.5
  ya se lo promete al cliente como comportamiento de la tablet.
- `RL-09` (aviso de privacidad en capas en el quiosco) y `RL-12` (cifrado del padrón cacheado en la
  tablet) están en la Fase 2, pero el quiosco y su caché se construyen en la Fase 1. Desplegar la
  tablet sin aviso y con el padrón en claro es un incumplimiento del RGPD desde el primer día de
  piloto, no solo del producto vendible.
- El doc 02 §11.2 nombra tres pilares como el recorte que no se debe hacer —*«auditoría inmutable,
  retención y exportación para Inspección»*—, y ninguno de los tres tiene tarea en la Fase 1 tal
  como está hoy.

## Decisión

**Cinco tareas de la Fase 2 se mueven a la Fase 1**, numeradas 1.14–1.18 para no romper las
referencias cruzadas de 1.1–1.13. La asignación de requisitos sigue la que ya tenía cada tarea en
el doc 02 §11, no una reinterpretación:

| Tarea | Viene de | Requisitos que trae | Qué cierra |
|---|---|---|---|
| **1.14** `audit_log` encadenado, comando de verificación y permisos | 2.2 entera | `RS-07` | Regla dura 6. Sin esto, las tareas 1.4/1.5/1.13 no pueden cumplir lo que ya afirman hacer |
| **1.15** Correcciones trazadas: versionado, motivos, anulación | 2.3 entera | `RN-13`, `RL-04`, `RF-PA-04` | La mitad «fiable e inalterable» del art. 34.9. Sin esto, el primer olvido de fichaje queda sin forma de corregirse hasta la Fase 2 |
| **1.16** Panel: detalle de jornada | parte de 2.5 | `RF-PA-03` | Hoy nadie salvo el propio empleado puede ver ningún registro. La presencia en vivo con Reverb (2.4) y la bandeja de incidencias (resto de 2.5, que depende de la detección automática de 2.6) se quedan en la Fase 2 |
| **1.17** Exportación legal para Inspección | parte de 2.9 | `RL-03`, `RL-06`, `RF-IN-05` | El segundo pilar del doc 02 §11.2. Las exportaciones ofimáticas de conveniencia (CSV/XLSX/PDF sin el formato normalizado) se quedan en la Fase 2 |
| **1.18** Copias cifradas, verificadas, con prueba de restauración | 2.11 entera | `RF-PR-04`, `RNF-D-02`, `RNF-D-05`, `RQ-09` | El registro con valor legal no puede depender de un disco sin copia desde el primer fichaje. `RQ-09` y `RNF-D-05` describen la misma prueba de restauración trimestral con dos numeraciones distintas del catálogo |

Y **tres requisitos se reasignan sin mover tarea**, porque la tarea que los construye ya es de la
Fase 1: `RN-15`, `RL-09`, `RL-12` (tareas 1.8 y 1.9, el quiosco y su cola offline).

**Dos candidatos se descartaron tras revisar qué tarea los satisface de verdad:**

- **`RL-02`** (conservación durante 4 años) no se mueve. La purga activa después de 4 años es la
  tarea 2.10 (`RF-PR-03`), y no tiene sentido en la Fase 1: una instalación recién nacida no tiene
  datos de 4 años que purgar. El requisito se cumple *pasivamente* mientras no exista ningún
  mecanismo de borrado, que es exactamente el estado de la Fase 1.
- **`RN-14`** (empleado de baja conserva historial, credencial revocada, escaneos rechazados) no se
  mueve. Las tareas 1.5 y 1.6 ya anotan la división correcta: *«RN-14 es de la Fase 2 según el
  Anexo A; en la Fase 1 lo exigible es que la revocación funcione»*. La conservación completa del
  historial en informes por periodo es de la tarea 2.8, que sigue en la Fase 2.

Lo que **no** se mueve, deliberadamente: 2.1 (2FA y ámbito por departamento), 2.4 (presencia en
vivo con Reverb), 2.6 (detección automática de incidencias), 2.7 (reconciliación nocturna), 2.8
(informes por periodo), 2.10 (purga por retención), 2.12 (rotación de clave), el resto de 2.5
(bandeja de incidencias) y el resto de 2.9 (exportaciones ofimáticas de conveniencia).

El criterio de corte: **lo que el art. 34.9 ET exige para que el registro sea válido entra en la
Fase 1; lo que hace la operación más cómoda para RRHH y para el responsable de departamento se
queda en la Fase 2.** Presencia en vivo, detección automática de incidencias y 2FA obligatorio son
mejoras de operación, no condiciones de validez legal del registro.

**El esfuerzo total no cambia; cambia de lado:**

| | Antes | Después |
|---|---|---|
| Fase 1 | 102–130 h | 135–172 h |
| Fase 2 | 86–109 h | 53–68 h |
| `0 + 1` | 133–172 h · piloto interno | **166–214 h · instalable y legalmente defendible** |

## Alternativas descartadas

| Alternativa | Por qué se descarta |
|---|---|
| **Dejar la Fase 1 como estaba y vender el piloto con reservas** | El propio doc 02 lo prohíbe: *«es el recorte que no se debe hacer»*. Vender un registro horario que no satisface la ley que lo justifica expone al cliente, no solo al proveedor |
| **Mover la Fase 2 entera a la Fase 1** | La 2.1 (2FA), la 2.4 (presencia en vivo) y la 2.6 (detección automática) son mejoras de operación, no condiciones de validez legal. Fusionar las fases duplicaría el tamaño de la Fase 1 sin necesidad y perdería la señal de qué es indispensable frente a qué es cómodo |
| **Escribir `audit_log` sin cadena en la Fase 1 y encadenarla después (opción citada en la línea 526 del plan)** | Media auditoría no es inmutable. Un registro que se puede alterar durante toda la Fase 1 y se sella retroactivamente en la Fase 2 dejaría una ventana de meses sin la garantía que la regla dura 6 exige desde el primer fichaje |
| **Mover también `RL-02` y `RN-14` por estar en la misma familia temática** | Ninguno de los dos exige trabajo en la Fase 1: `RL-02` se satisface pasivamente sin purgado, y `RN-14` ya tiene su división explícita y correcta en 1.5/1.6. Moverlos habría sido ruido, no una necesidad |
| **Resolver caso a caso sobre la marcha, en cada tarea, cuando aparezca la dependencia** | Es lo que ya estaba pasando —de ahí las tres tareas que afirman escribir en una tabla que no existe— y es exactamente el modo de fallo que ADR-031 cerró para RF-AT-06 antes de que la tarea 1.7 tuviera que improvisarlo |

## Consecuencias

- **`docs/01-especificaciones-proyecto.md` Anexo A** y **`docs/requisitos.yaml`** reasignan los
  quince requisitos a la Fase 1. No es cosmético: `docs:consistency --check` falla si la fase de un
  requisito no coincide con la de la tarea del plan que lo construye, y `qa:traceability --check`
  usa ese alcance para decidir qué bloquea. La herramienta escrita en la tarea 0.7 verifica este
  refactor entero.
- **`plan implementacion/03-fase-1-mvp-fichaje.md`** gana las tareas 1.14–1.18, con su orden de
  ejecución real documentado en la cabecera (1.14 entre 1.3 y 1.4; 1.15 tras 1.4; 1.16 tras 1.7;
  1.17 tras 1.15; 1.18 al cierre).
- **`plan implementacion/04-fase-2-gestion-y-cumplimiento.md`** retira lo movido y ajusta sus horas.
- **El estado de venta al cerrar `0 + 1` pasa de «piloto interno controlado» a «instalable y
  legalmente defendible»**. Sigue sin ser «producto vendible a escala»: eso sigue siendo la Fase 5,
  sin la cual —doc 02 §11.2— *«cada cliente nuevo consume tiempo del equipo de desarrollo»*.
- **RF-AT-10** (el desfase de reloj nunca rechaza el fichaje) sigue construyendo su incidencia en la
  tarea 3.5, pero la 1.3 añade `clock_skew_seconds` a `scan_events` y la 1.4 lo rellena, para que
  cuando llegue la 3.5 exista el dato con el que construir la incidencia hacia atrás.

## Verificación

- `make docs-consistency --check`: sin divergencias entre la fase de cada requisito reasignado y la
  tarea que lo construye.
- Sabotaje de contrato: devolver `RL-04` a fase 2 en `docs/requisitos.yaml` dejando la tarea 1.15
  donde está debe hacer que `docs:consistency --check` falle citando `RL-04`.
- `make traceability-check`: el alcance de la Fase 1 incluye los quince requisitos reasignados desde
  el cierre de la Fase 1.
