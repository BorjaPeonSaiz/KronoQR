# ADR-055 — El veredicto de cumplimiento lo da la vista; la bandeja de incidencias no puede contradecirlo

| Campo | Valor |
|---|---|
| **Estado** | Aceptada. La regla de admisión en `Shared/Domain` y el reparto describen el código actual. **N5 corregida en la 2.2.1**, con la prueba que vigila el invariante y `ElapsedMinutes` añadida a la lista del punto 3 ([enmienda del 15-10-2026](#enmienda-del-15-10-2026-bloque-19-de-la-221-n5-corregida-y-una-regla-más-en-la-lista-del-punto-3)). Pendiente: la prueba de arquitectura de esa lista (`qa-testing`) |
| **Fecha** | 8 de octubre de 2026 |
| **Decide** | `arquitecto-dominio` (bloque 14 de la 2.2.0, hallazgo A6-6 de la [tanda 6 de la 2.1.0](../verificacion/2.1.0-tanda-6.md); divergencia N5 de la [tanda 3](../verificacion/2.1.0-tanda-3.md), abierta en la [re-verificación](../verificacion/2.2.0-reverificacion-tanda-3.md)) |
| **Afecta a** | Precisa [ADR-021](ADR-021-clock-en-shared.md) (criterio de admisión en `Shared`) y [ADR-025](ADR-025-frontera-de-dependencias-del-nucleo.md) · Respeta [ADR-017](ADR-017-toda-diferencia-entre-clientes-es-configuracion.md) y [ADR-024](ADR-024-la-pausa-son-dos-tramos.md) · `backend/app/Modules/Shared/Domain/ValueObject/CompliancePolicy.php`, `ComplianceRule.php`, `ComplianceRuleSuspension.php`, `Shared/Domain/Policy/PinLockoutPolicy.php` · `Attendance/Domain/Policy/AnomalyDetectionPolicy.php`, `Attendance/Application/UseCase/DetectAttendanceAnomalies.php`, `Attendance/Infrastructure/Persistence/EloquentWorkDayLedger.php` · `Reporting/Domain/Policy/ComplianceEvaluation.php`, `Reporting/Domain/ValueObject/ComplianceFacts.php`, `Reporting/Infrastructure/Persistence/DatabaseComplianceFactsReader.php` |
| **Requisitos** | RN-07, RN-08, RN-10, RN-11, RN-12, RN-15, RN-17, RF-PA-06, RF-PR-01, RS-12 · reglas duras 1, 2 y 14 |

## Contexto

Dos evaluadores de dominio opinan sobre las mismas reglas legales:

- **`Attendance\Domain\Policy\AnomalyDetectionPolicy`** decide qué abre una **incidencia** en la bandeja de un responsable (RF-PR-01). Evalúa RN-07, RN-08, RN-10, RN-11, RN-12 y RN-15 sobre el agregado `WorkDay`, cada noche y sobre una ventana reciente (`DetectAttendanceAnomalies`).
- **`Reporting\Domain\Policy\ComplianceEvaluation`** decide qué enseña la **vista de cumplimiento** de un periodo (RF-PA-06). Evalúa RN-10, RN-11, RN-12 y RN-17 sobre `ComplianceFacts`, que se leen de la proyección `daily_totals`, y recalcula en cada consulta con el perfil vigente.

Las dos comparan con los mismos predicados, que viven en `Shared\Domain\ValueObject\CompliancePolicy` (`restIsInsufficient`, `dailyTimeIsExcessive`, `continuousShiftNeedsBreak` y `weeklyTimeIsExcessive`). Así, un `<` y un `<=` no pueden divergir entre pantallas. Pero la verificación de la 2.1.0 (A6-6) señaló dos huecos de decisión:

1. **Ningún ADR dice cuál manda** cuando las dos opinan distinto sobre la misma jornada. N5 ya demostró que pasa: para RN-10, la bandeja toma como fin de la jornada anterior la última salida vigente anterior (`EloquentWorkDayLedger::lastClockOutBefore`) y salta las jornadas anuladas. La vista toma el `last_out_at` de la fila anterior de `daily_totals` (`lag` en `DatabaseComplianceFactsReader`), que es `NULL` si esa jornada se anuló entera o tiene un tramo abierto. En esos dos bordes, una evalúa y la otra no.
2. **`Shared/Domain` contiene reglas de negocio** (los predicados de RN-10/11/12/17 y `PinLockoutPolicy` de RS-12), contra el criterio de ADR-021: «más de un módulo lo necesite y **no represente una regla de negocio de ninguno**».

## Decisión

### 1. La vista es la que afirma; la bandeja solo decide a quién interrumpir

**El veredicto «la jornada J de la persona P incumple la regla R con el perfil vigente» lo da `ComplianceEvaluation`.** Es lo que el producto afirma ante RRHH, ante el trabajador y en los informes. Es reproducible: con el mismo registro y el mismo perfil sale siempre lo mismo, porque se recalcula en cada lectura y se apoya en la proyección reconstruible (ADR-007).

**`AnomalyDetectionPolicy` no da veredictos: decide qué merece abrir una incidencia**, que es una tarea para una persona. Por eso puede ser **más selectiva** que la vista, y lo es a propósito:

- no emite RN-11 cuando un tramo de la misma jornada ya disparó RN-08, porque esa incidencia es más precisa y dice cuál es el tramo;
- cuelga RN-12 de cada tramo cerrado que lo supera, mientras que la vista señala la jornada por su tramo más largo;
- solo evalúa la ventana reciente que revisa cada noche;
- respeta la misma `ComplianceRuleSuspension` (RN-12 suspendida sin fichaje de pausa).

**El invariante que une las dos:** para RN-10, RN-11 y RN-12, toda incidencia sobre (persona, jornada, regla) corresponde a un hallazgo de la vista sobre esa misma persona, jornada y regla, evaluada con el perfil vigente en el momento de la detección. La bandeja puede callar algo que la vista señala, pero **no puede afirmar algo que la vista niega**. Si lo hace, el fallo está en la bandeja, o en los hechos que lee, y se corrige allí.

**Un cambio posterior del perfil no es una contradicción.** La incidencia conserva en su contexto el umbral con el que se abrió (`threshold_minutes`) y sigue siendo un hecho de la bandeja. La vista recalcula con el umbral nuevo. Las dos dicen la verdad sobre momentos distintos.

RN-07, RN-08 y RN-15 solo existen en la bandeja (umbrales operativos, no legales). RN-17 solo existe en la vista (informativa, no abre incidencia, doc 01 §4).

### 2. Lo que manda sobre los dos son los hechos, y los hechos tienen una sola definición

Los dos evaluadores comparan con los mismos predicados, pero **cada uno lee sus hechos con su propia consulta**. N5 está ahí y no en la comparación. Para que el invariante del punto 1 se cumpla, estas son las definiciones de los hechos, con las que se corrige cualquier lector que se aparte:

| Hecho | Definición |
|---|---|
| **Fin de la jornada anterior** (RN-10) | La última `clocked_out_at` de un tramo **vigente** (ni `superseded` ni `voided`) de la misma persona, anterior a la primera entrada vigente de esta jornada. Una jornada anulada entera **no existe** a estos efectos, y se mira la anterior. Si entre esa salida y la primera entrada de esta jornada hay un tramo vigente **abierto**, RN-10 **no se evalúa**: el problema es el turno sin cerrar, y de eso responde RN-08 |
| **Total de la jornada** (RN-11) | La suma de los tramos vigentes y cerrados de la jornada (RN-06). En `Attendance` es `WorkDay::totalWorked()`, y en `Reporting` es `daily_totals.total_minutes`, su proyección. Si difieren, la proyección está mal y la corrige `attendance:reconcile` (ADR-007) |
| **Tramo continuo** (RN-12) | Cada tramo vigente y cerrado, medido restando instantes UTC (RN-09) |

### 3. Qué reglas de negocio admite `Shared/Domain`

ADR-021 sigue valiendo para los puertos y los tipos base. **Se añade una excepción cerrada:** una regla de negocio puede vivir en `Shared/Domain` si se cumplen las cuatro condiciones siguientes:

1. dos o más módulos tienen que aplicarla **de forma idéntica**;
2. ninguno de esos módulos puede depender del otro según el doc 02 §1.6;
3. es pura: no tiene puertos, ni reloj, ni configuración, y recibe los umbrales ya resueltos (regla dura 14);
4. **está en la lista de este ADR.**

La lista, a 08-10-2026:

| Regla | Clase | Por qué cumple |
|---|---|---|
| Comparaciones de RN-10, RN-11, RN-12 y RN-17 contra el perfil de cumplimiento, y suspensión de reglas | `Shared\Domain\ValueObject\CompliancePolicy`, `ComplianceRule`, `ComplianceRuleSuspension` | `Attendance` (bandeja) y `Reporting` (vista) tienen que contar exactamente igual, y ninguno puede importar el dominio del otro |
| Bloqueo del PIN por intentos (RS-12) | `Shared\Domain\Policy\PinLockoutPolicy` | `Identity` (acceso al portal) y `Workforce` (verificación del PIN del quiosco) tienen que bloquear con la misma regla, y ninguno puede importar el dominio del otro. La consumen a través del puerto `Shared\Application\Port\PinAttempts` |

Una regla nueva en `Shared/Domain` exige una enmienda de este ADR que la añada a la lista.

## Alternativas descartadas

| Alternativa | Por qué se descarta |
|---|---|
| **Que mande la bandeja** | Su resultado depende de cuándo se ejecutó y de la ventana que revisó. Además, deduplica a propósito: no puede ser la fuente de un veredicto que hay que poder reproducir para cualquier periodo |
| **Un único evaluador para las dos pantallas** | Necesitan hechos distintos: la bandeja trabaja sobre el agregado vivo, con tramos abiertos y el instante actual, y la vista sobre periodos largos de la proyección, con semanas completas. Un evaluador común obligaría a uno de los dos módulos a depender del otro o a leer hechos que no le corresponden |
| **Llevar `CompliancePolicy` a `Compliance`** | `Attendance` y `Reporting` tendrían que importar el dominio de `Compliance`, y el doc 02 §1.6 no lo admite. El perfil lo resuelve el puerto `CompliancePolicyProvider` (ADR-025); lo que se comparte es la comparación |
| **Duplicar los predicados en cada módulo** | Es exactamente el fallo que `CompliancePolicy` existe para impedir: dos `<` que divergen al tocar uno |

## Consecuencias

- **Antes de tocar código, doc 01 §4 tiene que recoger la definición de RN-10 del punto 2.** RN-10 dice hoy «entre el fin de un turno y el inicio del siguiente» y no resuelve ni la jornada anulada entera ni el tramo anterior que sigue abierto. Esas dos precisiones son regla de negocio, y una regla que solo vive en un ADR o en el código se pierde. La redacción es de quien mantiene el doc 01.
- **N5 se corrige después con la definición del punto 2, en los dos lectores.** La vista (`DatabaseComplianceFactsReader`) tiene que saltar las jornadas anuladas enteras en lugar de tomar su `last_out_at` nulo. La bandeja (`EloquentWorkDayLedger::lastClockOutBefore`) tiene que dejar de medir desde una salida anterior a un tramo vigente que sigue abierto. **Pendiente: `backend-laravel`.**
- **Pendiente, sin guarda que vigile el invariante del punto 1:** hoy lo sostiene el diseño, no una prueba. **Propuesta para `qa-testing`:** un conjunto común de escenarios (jornada anulada entera, tramo abierto en la jornada anterior, cambio de hora, jornada partida, RN-08 que explica RN-11), evaluado por las dos políticas. Para RN-10/11/12, el conjunto de (persona, jornada, regla) de las incidencias tiene que estar contenido en el de los hallazgos de la vista.
- **Pendiente, sin guarda que vigile la lista del punto 3:** una prueba de arquitectura que falle si aparece en `Shared/Domain/Policy/` una clase que no está en la lista, o un método público nuevo en `CompliancePolicy` que no sea un predicado de las cuatro reglas. Propuesta para `qa-testing`.
- **ADR-021 queda precisado**, no sustituido: su criterio sigue valiendo para los puertos y los tipos, y este ADR añade la excepción cerrada para las reglas.

## Verificación

- Unitarias: `AnomalyDetectionPolicyTest` y `ComplianceEvaluationTest`, cada una sobre su evaluador, con los bordes de los límites abiertos.
- Pendiente: la prueba de escenarios comunes y la prueba de arquitectura de la lista, descritas en Consecuencias.

## Nota 09-10-2026 (2.2.0 publicada): estado

**Sin cambios: la decisión está aceptada y la corrección de N5 sigue pendiente, ahora para la 2.2.1.** La 2.2.0 se publicó sin ella.

- **Por qué no entró:** la primera consecuencia exige que el doc 01 §4 recoja antes la definición de RN-10 del punto 2 (jornada anulada entera y tramo anterior abierto), y RN-10 sigue diciendo solo «entre el fin de un turno y el inicio del siguiente». La verificación final lo registra como conocido y diferido ([2.2.0-verificacion-final-tanda-3.md](../verificacion/2.2.0-verificacion-final-tanda-3.md), fila N5). Ni `DatabaseComplianceFactsReader` ni `EloquentWorkDayLedger` han cambiado desde el 17-09-2026.
- **Siguen pendientes**, también para la 2.2.1: las dos guardas de `qa-testing` (escenarios comunes a los dos evaluadores y la prueba de arquitectura de la lista del punto 3).
- **Dónde consta:** [`2.2.1-lista-cambios.md`](../verificacion/2.2.1-lista-cambios.md) §3, «N5 de ADR-055, que necesita RN-10 en el doc 01».

## Enmienda del 15-10-2026 (bloque 19 de la 2.2.1): N5 corregida y una regla más en la lista del punto 3

**N5 corregida en la 2.2.1.** El doc 01 §4 recoge en RN-10 la definición del punto 2 (jornada anulada entera y tramo anterior abierto), y los dos lectores la aplican:

- **La vista** (`Reporting/Infrastructure/Persistence/DatabaseComplianceFactsReader`) descarta las jornadas sin tramos vigentes (`daily_totals.shift_count = 0`) antes de buscar la anterior, también la que precede a la ventana, y no mide desde una jornada anterior con un tramo abierto (`lag(has_open_shift)`).
- **La bandeja** (`Attendance/Infrastructure/Persistence/EloquentWorkDayLedger`, puerto `WorkDayLedger`) toma el último tramo vigente que **empieza** antes de la primera entrada: si sigue abierto devuelve nulo y RN-10 no se evalúa, y si terminó en el mismo instante cuenta cero minutos. Antes comparaba la salida con `<` y, en ese borde, medía desde una salida más antigua: callaba un descanso de cero minutos que la vista sí señalaba.
- **El tramo anterior abierto no puede persistir** con RN-01 y RN-02 en el esquema (`one_open_shift_per_employee` y `shift_entries_no_overlap`, cuyo rango sin salida queda abierto por arriba). Las dos ramas son defensa, no un caso que hoy ocurra.
- **Corrección del Contexto:** `daily_totals.last_out_at` no es nulo en una jornada con un tramo abierto si tiene otro cerrado: es la última salida cerrada (`WorkDay::lastClockOutAt()`). En los datos reales, el borde que de verdad separaba a la vista de la bandeja era la jornada anulada entera.

**El invariante del punto 1 tiene guarda.** `tests/Integration/Reporting/ComplianceSummaryMatchesDetectionTest.php` (`escenarioN5`) siembra la jornada anulada entera —en medio y justo antes de la ventana—, el descanso de cero minutos, el cambio de hora y la jornada partida, junto al caso ya existente de RN-08 que explica RN-11, y comprueba que las incidencias de RN-10, RN-11 y RN-12 están contenidas en los hallazgos de la vista y que las de RN-10 coinciden. Responde a la primera pendiente de Consecuencias.

**La lista del punto 3 se amplía.** La resta de instantes en minutos enteros estaba escrita cinco veces en tres módulos que no pueden verse —tres para la duración de un tramo (`TimeRange`, `ComplianceShiftSegment`, `WorkRecordReconciliation`, atadas por una prueba) y dos para el hueco de RN-10 (`AnomalyDetectionPolicy`, `ComplianceFacts`)—. Cumple las cuatro condiciones:

| Regla | Clase | Por qué cumple |
|---|---|---|
| Minutos enteros entre dos instantes (RN-09) y hueco de descanso de RN-10, nulo si es un solape | `Shared\Domain\ValueObject\ElapsedMinutes` | `Attendance` (registro y bandeja), `Reporting` (vista) y `Compliance` (conciliación de [ADR-057](ADR-057-el-registro-horario-no-se-borra-ni-se-reescribe-con-la-credencial-del-runtime.md) §4) tienen que contar igual el mismo tramo, y ninguno puede importar el dominio de otro. Es pura y no recibe umbrales |

**La semántica no cambia:** se restan segundos enteros (`getTimestamp()`) y se trunca al minuto, como hasta ahora. Medir con microsegundos (C3 de la [re-verificación de la 2.2.0, tanda 1](../verificacion/2.2.0-reverificacion-tanda-1.md)) cambiaría `shift_entries.duration_minutes` respecto a lo ya guardado: la conciliación de ADR-057 §4 denunciaría como `entry_differs_from_audit` cada tramo histórico que cruzara un minuto por fracciones de segundo, y `attendance:reconcile` vería divergencias en `daily_totals`. Es una precisión de RN-09 que decide el propietario, con su plan de transición, y no entra aquí.

**Sigue pendiente** la prueba de arquitectura de la lista del punto 3 (`qa-testing`): que falle si aparece en `Shared/Domain` una regla que no está en la lista.
