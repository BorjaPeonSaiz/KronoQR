# ADR-006 — Los turnos no se parten a medianoche

| Campo | Valor |
|---|---|
| **Estado** | Aceptada |
| **Fecha** | 11 de agosto de 2026 (redactada el 14 de agosto de 2026, tarea 0.6) |
| **Decide** | `arquitecto-dominio` |
| **Afecta a** | Tareas 1.1, 1.2, 2.8, 3.4 y 3.5 · [ADR-024](ADR-024-la-pausa-son-dos-tramos.md) · **Regla dura 4** de `CLAUDE.md` |
| **Requisitos** | RF-AT-08, RN-05, RN-09, RN-10, RN-11, RL-01, RQ-02 |

> Procede de la primera tabla del [documento 02](../02-stack-tecnologico-y-plan-implementacion.md) §4, que ya fijaba decisión, contexto y consecuencias. Esta redacción los desarrolla y los enlaza con los requisitos y con las reglas duras; no cambia la decisión.

## Contexto

En un hotel, el turno de noche es ordinario, no excepcional: recepción, cocina, seguridad y limpieza nocturna. Un turno típico entra a las 22:00 y sale a las 06:00.

La solución intuitiva —y la que aparece sola si nadie decide— es **partir el tramo a las 23:59:59 y abrir otro a las 00:00**, para que cada día natural tenga sus horas. Tiene tres consecuencias, todas malas:

1. **Fabrica registros que no ocurrieron.** Nadie fichó a las 23:59:59 ni a las 00:00. Un registro horario con valor probatorio que contiene marcas inventadas por el sistema es exactamente lo que RL-01 y RL-04 existen para impedir; la existencia de esas dos filas ya es una alteración del hecho registrado.
2. **Rompe el cálculo del descanso entre jornadas.** RN-10 mide el hueco entre el fin de un turno y el inicio del siguiente. Con el corte artificial, el sistema ve un turno que termina a las 23:59 y otro que empieza a las 00:00: cero minutos de descanso, alerta falsa todas las noches. Y una alerta que suena siempre deja de leerse.
3. **Distorsiona la jornada diaria.** RN-11 alerta al superar las horas efectivas de la jornada ordinaria. Un turno de ocho horas partido en dos de dos y seis no supera nada, y el mismo turno tampoco produce una jornada evaluable.

## Decisión

**Un turno que cruza la medianoche es un único tramo, y pertenece íntegramente a la jornada de su hora de inicio.**

`work_date` es la fecha civil —en la zona del centro ([ADR-004](ADR-004-utc-en-almacenamiento.md))— del `clocked_in_at` del tramo **que abre la jornada** (RN-05). Un turno 22:00 → 06:00 son 480 minutos del día D, y cero del día D+1.

Dos precisiones que forman parte de la decisión y no de su implementación:

- **`work_date` es propiedad de la jornada, no de cada tramo.** Un tramo que **continúa** una jornada abierta —la vuelta de una pausa (RF-AT-12)— hereda su `work_date` aunque empiece en otro día natural. Sin esto, fichar una pausa a las 02:00 partiría la jornada por la puerta de atrás, que es lo que este ADR prohíbe ([ADR-024](ADR-024-la-pausa-son-dos-tramos.md)).
- **La duración se calcula sobre instantes UTC** (RN-09), así que el turno nocturno del día del cambio de hora mide lo que realmente duró.

**El prorrateo por día natural, cuando alguien lo necesite, se calcula en `Reporting` a partir del tramo íntegro.** Es una vista, no un hecho: se deriva, se documenta y no se escribe en `shift_entries`.

## Alternativas descartadas

| Alternativa | Por qué se descarta |
|---|---|
| **Partir el tramo a medianoche al registrarlo** | Inventa dos marcas de tiempo que nadie produjo, rompe RN-10 con una alerta falsa diaria y distorsiona RN-11. Es la opción por defecto de muchos sistemas de fichaje y la razón por la que sus informes de descanso son inservibles en hostelería |
| **Atribuir el turno al día de salida** | Simétrico y peor de explicar: el turno que se empieza el viernes por la noche aparecería en el sábado, y el cuadrante de la semana no cuadraría con lo que la gente recuerda haber trabajado |
| **Atribuirlo al día donde caiga la mayor parte de las horas** | Regla que cambia de resultado según cuánto se alargue el turno: dos personas del mismo turno podrían quedar en días distintos por diez minutos de diferencia. Impredecible para quien mira su registro |
| **Guardar el tramo íntegro y además su prorrateo por día** | Dos fuentes de verdad sobre el mismo hecho. El prorrateo es una vista de `Reporting`, y como vista se recalcula; como columna, se desincroniza con la primera corrección |
| **Definir la jornada por el cuadrante planificado** | El producto no tiene cuadrantes en el alcance del MVP (son Fase 4), y hacer depender el registro legal de una planificación que puede no existir dejaría sin `work_date` a quien no esté planificado |

## Consecuencias

- **El informe de horas por día natural requiere prorrateo explícito**, implementado en `Reporting` y documentado en su salida. Quien pida «horas del día 5» tiene que saber si le estamos dando jornadas que empiezan el día 5 o minutos ocurridos el día 5. Son cifras distintas y las dos son legítimas.
- **La exportación legal para Inspección (RL-06) expresa el registro por jornada**, con su hora de inicio y de fin reales. Es lo que exige RL-01: hora concreta de inicio y de fin, no un reparto contable.
- **`daily_totals` se indexa por `work_date` de la jornada**, y por eso el total del día D+1 de un turno nocturno es cero. Hay que anticiparlo a RRHH: no es un fallo de la proyección.
- **Es una de las pruebas unitarias ineludibles** del núcleo, y una de las que más barato sale escribir y más caro sale descubrir en producción.
- **El quiosco no decide nada de esto.** Ficha, y el servidor atribuye. Un dispositivo con el reloj desviado o un lote offline sincronizado horas después producen la misma atribución (RN-15).

## Verificación

- Prueba unitaria de dominio (RQ-02): turno 22:00 → 06:00 produce **un** `ShiftEntry` de 480 minutos con `work_date` del día D. El día D+1 tiene cero.
- Prueba unitaria: el mismo turno la noche del cambio de hora, en ambos sentidos, mide 420 y 540 minutos respectivamente, y sigue siendo un solo tramo del día D.
- Prueba unitaria: dos turnos nocturnos consecutivos separados por menos del descanso mínimo disparan RN-10 **una sola vez** y con el hueco real; ningún corte artificial genera alertas de cero minutos.
- Prueba unitaria: una pausa a las 02:00 dentro de un turno nocturno no abre jornada nueva; el segundo tramo hereda `work_date` (ADR-024).
- Prueba de integración: el informe por día natural con prorrateo suma lo mismo que el total por jornada en un periodo cerrado. Si no cuadra, uno de los dos está mal.
