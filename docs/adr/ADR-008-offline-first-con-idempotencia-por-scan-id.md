# ADR-008 — Offline-first con idempotencia por `scan_id`

| Campo | Valor |
|---|---|
| **Estado** | Aceptada |
| **Fecha** | 11 de agosto de 2026 (redactada el 14 de agosto de 2026, tarea 0.6) |
| **Decide** | `arquitecto-dominio` |
| **Afecta a** | Tareas 1.4, 1.7, 1.9 y 3.5 · [documento 02](../02-stack-tecnologico-y-plan-implementacion.md) §6 · **Reglas duras 8, 9 y 19** de `CLAUDE.md` |
| **Requisitos** | RF-AT-07, RF-AT-09, RF-AT-10, RF-KI-03, RF-KI-04, RN-15, RNF-D-01, RQ-03, RQ-05 |

> Procede de la primera tabla del [documento 02](../02-stack-tecnologico-y-plan-implementacion.md) §4, que ya fijaba decisión, contexto y consecuencias. Esta redacción los desarrolla y los enlaza con los requisitos y con las reglas duras; no cambia la decisión.

## Contexto

El wifi de un hotel se cae. Se cae en el sótano donde está el vestuario de cocina, se cae cuando el punto de acceso se satura a las 06:00 y se cae porque alguien desenchufó algo. **No es un caso excepcional: es una condición de trabajo.**

Y el acto de fichar no admite espera. Si el quiosco depende del servidor para confirmar, la caída de la red produce dos resultados y los dos son inaceptables: una cola de gente delante de la tablet en el cambio de turno, o una jornada sin registrar que acabará en una corrección manual. Las correcciones manuales son precisamente lo que erosiona el valor probatorio del registro que el producto existe para producir.

Pero un cliente que registra por su cuenta abre un problema nuevo: **el mismo fichaje puede llegar dos veces**. Un reintento tras un *timeout*, un lote reenviado, una tablet que se reinicia con la cola a medio sincronizar. Sin una garantía explícita, el resultado es un turno abierto y cerrado dos veces, o dos tramos solapados.

## Decisión

**El quiosco registra localmente y confirma al empleado sin esperar al servidor. El servidor garantiza «exactamente una vez» mediante `scan_id`.**

Cuatro piezas, ninguna opcional:

1. **`scan_id` es un UUID v7 generado en el cliente**, con `UNIQUE` en `scan_events`. Un reenvío **devuelve la respuesta original**, no un error y no un duplicado (regla dura 8, RF-AT-07). Viaja además como cabecera `Idempotency-Key`. Se elige v7 frente a v4 porque es ordenable temporalmente y mantiene la localidad del índice con millones de filas.
2. **Dos marcas de tiempo siempre** (regla dura 9, RF-AT-09): `occurred_at`, el momento real del escaneo, que viaja desde el dispositivo, y `recorded_at`, la recepción en el servidor. **El registro legal usa `occurred_at`**, y ambas quedan visibles en la auditoría.
3. **La cola vive en IndexedDB con transacciones** (Dexie), con reintentos y *backoff* exponencial, e indicador visible de pendientes. Un elemento solo se borra tras confirmación explícita del servidor. El lote se procesa **ordenado por `occurred_at`**, no por orden de llegada: entrada y salida offline deben aplicarse en secuencia.
4. **El quiosco nunca bloquea al empleado** (regla dura 19). Si no hay red, si el reloj diverge o si el padrón cacheado no reconoce la tarjeta, **encola igualmente**, confirma provisionalmente y, si algo no cuadra, genera una incidencia para revisión humana (RF-AT-10, RN-15). Rechazar un fichaje por un problema técnico deja a una persona sin registro por algo que no es suyo.

## Alternativas descartadas

| Alternativa | Por qué se descarta |
|---|---|
| **Solo en línea, con reintento y pantalla de error** | Convierte cada corte de red en jornadas sin registrar y en correcciones manuales. Y hace imposible el 99,9 % de disponibilidad del **acto de fichar** de RNF-D-01, que es distinto —y más exigente— que la disponibilidad del servicio |
| **Offline como mejora posterior a la v1** | La cola offline atraviesa el cliente, el contrato, el esquema y el dominio (doble marca temporal, idempotencia, incidencias por retraso). Añadirla después obliga a rehacer las cuatro cosas. Por eso está en el MVP |
| **Idempotencia por hash del contenido** (empleado + minuto) | Dos escaneos legítimos del mismo empleado en el mismo minuto son distinguibles y a veces reales; y el anti-rebote de RF-AT-06 ya cubre el caso del doble escaneo accidental, que es otro problema. El identificador lo genera quien conoce el acto: el cliente |
| **Identificador de idempotencia generado por el servidor** | Inútil sin red, que es cuando se necesita |
| **UUID v4 en lugar de v7** | Aleatorio puro: fragmenta el índice de `scan_events` con millones de filas. v7 conserva el orden temporal y la localidad de páginas |
| **Rechazar el fichaje si el reloj del dispositivo diverge** | Deja una jornada sin registrar por un fallo técnico ajeno al empleado. RF-AT-10 lo prohíbe explícitamente: se acepta, se marca `clock_skew` y lo revisa una persona |

## Consecuencias

- **Complejidad real en el cliente**: cola persistente, reintentos con *backoff*, padrón cacheado y cifrado, resolución local del nombre y estado de sincronización visible. Es la parte más delicada del quiosco y donde se concentran sus pruebas (RQ-05).
- **La doble marca temporal se propaga a todo el sistema**: esquema, contrato OpenAPI, auditoría, informes y exportación legal. Cualquier consulta que use `recorded_at` como hora del fichaje está mal.
- **Un fichaje puede llegar horas después de ocurrir.** Los informes y la reconciliación nocturna tienen que tolerar que un día ya calculado cambie, y `daily_totals` lo soporta porque se recalcula ([ADR-007](ADR-007-daily-totals-proyeccion-reconstruible.md)).
- **La confirmación al empleado es provisional.** El quiosco dice «fichaje registrado» y actualiza el total real cuando sincroniza. Ese matiz tiene que estar en la interfaz sin asustar a nadie.
- **Aparece una categoría de incidencias que RRHH debe gestionar**: retraso de sincronización por encima del umbral, desfase de reloj y tarjetas no reconocidas por el padrón cacheado. Sin bandeja donde aterrizar (RF-PA-05), la degradación honesta se convierte en datos sucios.
- **La actualización del servidor deja de ser una parada visible.** Mientras dura, los quioscos encolan. Es una ventaja inesperada del modo offline (§11.6.4).

## Verificación

- Prueba de integración concurrente (RQ-03): N envíos simultáneos del mismo `scan_id` producen **un** `scan_event` y todas las respuestas son idénticas.
- Prueba de integración: reenviar un `scan_id` ya procesado devuelve la respuesta original, con 2xx y sin duplicar el tramo ni alterar `daily_totals`.
- Prueba de integración: un lote con elementos desordenados se aplica por `occurred_at` y produce la misma secuencia de entrada y salida que si hubieran llegado en línea.
- Prueba de ciclo completo (RQ-05): offline → reconexión → sincronización → consolidación, con la cola persistida entre recargas de la aplicación.
- Prueba de *feature*: un fichaje con `occurred_at` desviado por encima del umbral se **acepta**, se registra con la hora del dispositivo y genera incidencia `clock_skew` (RF-AT-10, RN-15).
- E2E de quiosco: con la red cortada, el empleado recibe confirmación en menos de 300 ms y el indicador muestra los pendientes (RNF-P-03).
- Prueba de contrato: `occurred_at` y `recorded_at` son `date-time` en UTC con `Z`, y `scan_id` es `uuid`.
