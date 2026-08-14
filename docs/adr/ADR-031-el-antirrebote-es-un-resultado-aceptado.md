# ADR-031 — El anti-rebote es un resultado aceptado, no un rechazo

| Campo | Valor |
|---|---|
| **Estado** | Aceptada |
| **Fecha** | 14 de agosto de 2026 |
| **Decide** | `arquitecto-dominio` |
| **Afecta a** | Tareas 1.4, 1.7, 1.8 y 1.9 · `docs/api/openapi.yaml` · **Reglas duras 8, 17 y 19** de `CLAUDE.md` |
| **Requisitos** | RF-AT-06, RF-AT-05, RF-KI-04, RS-03 |

## Contexto

`RF-AT-06` es **Must** de la Fase 1: *«un segundo escaneo del mismo empleado dentro de la ventana no crea evento y muestra aviso informativo»*. El escenario de aceptación del documento 01 §11 lo concreta: no se crea ni se cierra ningún tramo, el quiosco muestra *«Ya has fichado hace unos segundos»* y el escaneo queda registrado con `result = rejected_debounce`.

El dominio y el esquema lo tenían resuelto. **El contrato no.** `POST /api/v1/scan` describía dos desenlaces —`200 ScanAccepted` y `422 ScanRejected`— y el anti-rebote no cabe en ninguno:

- Con **`200 ScanAccepted`** habría que devolver un `action` de entre `clock_in`, `clock_out`, `break_start` o `break_end`. Los cuatro son mentira: no ocurrió ninguno. El quiosco enseñaría *«Entrada 07:02»* por un fichaje que no existe, y el empleado se iría convencido de haber fichado dos veces.
- Con **`422 ScanRejected`** se confundiría con el rechazo de credencial, que por diseño es **genérico y de tiempo constante** (RS-03, regla dura 17). El quiosco no podría distinguir *«ya has fichado»* de *«tu tarjeta no vale»*, que son mensajes opuestos para la persona que está delante.

Detectado al cerrar la tarea 0.6, antes de que la 1.7 tuviera que resolverlo improvisando.

## Decisión

**El anti-rebote es un desenlace aceptado del escaneo, y se expresa con `200` y `action: debounced`.**

La respuesta de `200` pasa a ser un `oneOf` discriminado por `action`:

| `action` | Esquema | Qué ocurrió |
|---|---|---|
| `clock_in`, `clock_out`, `break_start`, `break_end` | `ScanAccepted` | Se creó o cerró un tramo |
| `debounced` | `ScanDebounced` | El escaneo se procesó y **deliberadamente no cambió nada** |

`ScanDebounced` lleva lo que el quiosco necesita para el aviso de RF-AT-06 y nada más: el nombre en su forma mínima, el acumulado del día, y **`last_accepted_at`**, el momento del escaneo que sí se registró. Sin ese último campo el quiosco no puede decir *«hace unos segundos»* sin inventárselo.

**Tres razones sostienen que sea `2xx` y no un error.**

1. **La cola offline reintenta con *backoff* exponencial** (RF-KI-04, tarea 1.9). Un `4xx` haría que un escaneo encolado se reintentara indefinidamente contra una ventana que ya pasó. La regla dura 19 dice que el quiosco nunca bloquea al empleado; una cola que no drena es exactamente eso, con retraso.
2. **La petición se entendió y se procesó.** Lo que el servidor decidió es que el estado correcto ya era el que había. Eso es un éxito, no un fallo del cliente.
3. **No hay nada que filtrar.** RS-03 protege de revelar si una credencial existe, está revocada o tiene mala firma. En el anti-rebote la credencial es **válida y ya resuelta** —acaba de funcionar hace segundos—, así que devolver el nombre y el acumulado no añade información que el quiosco no tuviera. La regla dura 17 no aplica aquí, y aplicarla por analogía habría empeorado el producto sin ganar seguridad.

## Lo que esto NO es

**No es idempotencia** (regla dura 8, ADR-008), y confundirlos produce dos fallos distintos:

| | Idempotencia | Anti-rebote |
|---|---|---|
| Qué lo dispara | El **mismo** `scan_id` reenviado | Un `scan_id` **nuevo**, escaneo físico distinto |
| Causa | Reintento de red, sincronización de la cola | La persona pasó la tarjeta dos veces |
| Respuesta | **La original, íntegra**, incluida su `action` | `debounced` |

Un reenvío de un `clock_in` ya procesado devuelve `clock_in`, no `debounced`: la respuesta original se conserva tal cual. Un escaneo nuevo dentro de la ventana devuelve `debounced` aunque el anterior fuera un `clock_in`.

## Alternativas descartadas

| Alternativa | Por qué se descarta |
|---|---|
| **`409 Conflict`** | Semánticamente tentador —hay un conflicto de estado— pero no hay nada que resolver ni que reintentar, y la cola offline lo trataría como fallo. Un `409` obligaría a la tarea 1.9 a mantener una lista de códigos «que no se reintentan», que es el tipo de conocimiento duplicado que acaba divergiendo entre cliente y servidor |
| **`422` con el `ScanRejected` genérico** | Deja al quiosco sin poder distinguir *«ya has fichado»* de *«tu tarjeta no vale»*. Y ensancharía `ScanRejected` con un motivo, que es justo lo que RS-03 prohíbe: hoy ese esquema tiene `additionalProperties: false` y sus campos clavados con `enum` de un solo valor, para que **no exista sitio** donde alojar la causa |
| **`200 ScanAccepted` con un booleano `debounced: true`** | Un campo que invalida el significado de otro campo obligatorio. El cliente que no lo lea mostrará una acción que no ocurrió, y **fallará en silencio**: el modo de fallo más caro. El `oneOf` discriminado obliga a ramificar, y el cliente TypeScript generado no compila si no se hace |
| **`204 No Content`** | Correcto en que no hubo cambio, y el quiosco se queda sin nombre ni acumulado, así que no puede cumplir RF-AT-06 ni RF-AT-05 |
| **Resolverlo solo en el quiosco, sin llamar al servidor** | El cliente no sabe si la ventana ya pasó cuando viene de la cola offline, ni conoce `ATTENDANCE_DEBOUNCE_SECONDS`, que es configuración de instalación. Y el escaneo debe quedar en `scan_events` con `rejected_debounce` (§11 del doc 01): si el quiosco lo descarta, desaparece del registro y nadie puede investigar un fichaje discutido |

## Consecuencias

- **El enum `action` gana `debounced`.** Es aditivo y no rompe la v1 (ADR-012), igual que la ampliación de ADR-024: un cliente antiguo lo trata como valor desconocido, nunca como error.
- **`scan_events.result` conserva `rejected_debounce`.** El nombre interno no cambia: describe lo que el dominio decidió —no se registró tramo— y el §11 lo exige literalmente. Que el transporte lo devuelva como `2xx` no lo convierte en aceptado *dentro*: son dos capas y dos vocabularios, y mezclarlos obligaría a migrar un enum de base de datos por una decisión de HTTP.
- **La tarea 1.8 tiene que ramificar** por `action` antes de pintar la confirmación. El cliente generado es una unión discriminada, así que `vue-tsc` lo exige: no se puede olvidar.
- **La tarea 1.9 no necesita lista de códigos que no se reintentan.** Todo `2xx` drena la cola; todo `5xx` se reintenta. La regla se mantiene simple porque el desenlace se eligió para que lo fuera.
- **`ATTENDANCE_DEBOUNCE_SECONDS` es umbral operativo**, no legal: viene de `OperationalSettingsProvider` (ADR-025), no del perfil de cumplimiento. La tarea 3.5 debe revisarlo al introducir la pausa, porque una vuelta de pausa legítima no puede caer dentro de la ventana (ADR-024).

## Verificación

- Prueba de contrato: la respuesta `200` es un `oneOf` discriminado por `action`, y `debounced` mapea a `ScanDebounced`.
- Prueba de contrato: `ScanDebounced` **no** tiene `work_date` ni ningún campo que afirme un tramo que no se creó.
- Prueba de contrato: `ScanRejected` sigue sin sitio donde alojar un motivo. El anti-rebote no lo ha ensanchado.
- Prueba unitaria de dominio (tarea 1.4): un segundo escaneo dentro de la ventana no crea ni cierra tramo, y el acumulado no varía.
- Prueba unitaria (tarea 1.4): un reenvío del **mismo** `scan_id` devuelve la respuesta original con su `action`, no `debounced`.
- Prueba unitaria (tarea 3.5): con la pausa activa, la vuelta de pausa **no** cae en la ventana anti-rebote.
- E2E (tarea 1.8): dos escaneos seguidos muestran *«Entrada 07:02»* y después el aviso de RF-AT-06, nunca dos entradas.
