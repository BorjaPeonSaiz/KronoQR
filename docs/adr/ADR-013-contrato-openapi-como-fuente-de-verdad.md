# ADR-013 — El contrato OpenAPI es la fuente de verdad de la API

| Campo | Valor |
|---|---|
| **Estado** | Aceptada |
| **Fecha** | 11 de agosto de 2026 (redactada el 14 de agosto de 2026, tarea 0.6) |
| **Decide** | `arquitecto-dominio` |
| **Afecta a** | Tareas 0.6, 1.7 y toda tarea que toque la API · [documento 02](../02-stack-tecnologico-y-plan-implementacion.md) §3.1, §3.3 y §9.2 · Orden de autoridad de `CLAUDE.md` |
| **Requisitos** | RQ-06, RQ-07, RNF-M-06, RF-KI-04 |

> Procede de la primera tabla del [documento 02](../02-stack-tecnologico-y-plan-implementacion.md) §4, que ya fijaba decisión, contexto y consecuencias. Esta redacción los desarrolla y los enlaza con los requisitos y con las reglas duras; no cambia la decisión.

## Contexto

Hay **tres aplicaciones cliente** —quiosco, panel y portal— y un backend, mantenidos por la misma persona pero en momentos distintos. La deriva entre ellos es el fallo más aburrido y más frecuente de este tipo de sistema: un campo que se renombra en el servidor y que el quiosco sigue enviando, un enum que gana un valor que el panel no sabe pintar, un error que el cliente espera como `message` y llega como `detail`.

En este producto ese fallo tiene un agravante: **el quiosco puede llevar días sin conectar** y sincronizar una cola construida contra la forma anterior del contrato (ADR-012). Descubrir la desviación en producción significa descubrirla con fichajes reales dentro.

La alternativa habitual —generar la documentación a partir del código— documenta lo que hay, no lo que se acordó. Sirve para leer; no sirve para detectar que lo que hay está mal.

## Decisión

**`docs/api/openapi.yaml`, en OpenAPI 3.1, es la fuente de verdad de la API. Se modifica antes que el código, no después.**

Cuatro consecuencias operativas que forman parte de la decisión:

1. **El contrato manda sobre la forma de cualquier endpoint.** El orden de autoridad de `CLAUDE.md` lo sitúa solo por debajo de los ADR: cuando el código y el contrato discrepan, el que está mal es el código.
2. **Las respuestas se validan contra el esquema en las pruebas** (RQ-06) con Spectator, en `backend/tests/Contract/`. Una respuesta que no valida es un fallo de la prueba, no un aviso.
3. **Los clientes TypeScript de las tres aplicaciones se generan del contrato** (`npm run api:generate`). Una desviación aparece como error de compilación de `vue-tsc`, antes de llegar a un dispositivo.
4. **El contrato describe lo que cada rol debe ver, no lo que el modelo tiene.** Esquema de petición con `date-time` en UTC y `uuid`, errores en `application/problem+json`, `security` con el ámbito requerido, `Idempotency-Key` en las escrituras del quiosco y ejemplos reales. Es el paso 1 de la skill `endpoint-api`, y es donde se decide que un token de quiosco no ve la plantilla completa.

## Alternativas descartadas

| Alternativa | Por qué se descarta |
|---|---|
| **Generar el contrato a partir de anotaciones del código** | El documento pasa a describir lo que hay, con lo que **nunca puede detectar que lo que hay es incorrecto**. Y el diseño del endpoint se toma escribiendo el controlador, que es el momento en que se filtran campos de más «porque el modelo ya los tiene» |
| **Sin contrato, con tipos escritos a mano en cada frontend** | Tres copias que divergen. El día que una se olvide, el fallo aparece en la tablet de un hotel |
| **Contrato como documentación no verificada** | Un fichero que nadie comprueba envejece en semanas y a partir de ahí engaña más que ayuda. La regla del §3.5 aplica igual aquí: lo que no verifica una herramienta es una sugerencia |
| **GraphQL** | Traslada al cliente la decisión de qué campos pedir, justo donde este producto necesita lo contrario: control estricto por rol y por ámbito de token (RS-04, RQ-07). Y complica el modo offline y la idempotencia por `scan_id` |
| **OpenAPI 3.0 en lugar de 3.1** | 3.1 alinea con JSON Schema, que es lo que hace fiable la validación en pruebas y la generación de tipos |

## Consecuencias

- **Trabajar sobre la API tiene un orden fijo:** contrato → prueba de contrato → implementación. Cuesta disciplina al principio y evita la conversación de «esto ya lo había hecho de otra forma».
- **Cambiar el contrato es un cambio revisable en el *pull request***, visible en el diff y evaluable contra ADR-012: ¿es aditivo o rompe v1?
- **Los tres frontends dependen de un artefacto generado.** Si la generación falla, la CI falla; es preferible a que compile con tipos obsoletos.
- **El contrato entra en la Definición de Terminado** (§10.3): ninguna funcionalidad con endpoint se cierra sin él actualizado.
- **La herramienta de validación estática del propio fichero es `@redocly/cli`**, fijada al ejecutar la tarea 0.6. El §3.1 solo fijaba `spectator` para pruebas, que valida respuestas contra el contrato pero no que el contrato en sí sea OpenAPI 3.1 correcto. Se ata al objetivo `make api-lint`, entra en `make quality` y bloquea en la etapa ① de la CI, porque *una convención que no verifica una herramienta es una sugerencia* (§3.5). Su versión se fija en el `Makefile`, junto a las de ShellCheck y shfmt, para que el resultado no dependa de quién ejecute.
- **El contrato no documenta lo interno.** Eventos de dominio, colas y canales de WebSocket no son API pública y no viven aquí.

## Verificación

- Prueba de contrato con Spectator (RQ-06): toda respuesta de toda prueba de *feature* valida contra el esquema.
- `npm run api:generate` produce el cliente en los tres frontends sin error de tipos (`vue-tsc` en 0).
- El fichero valida como OpenAPI 3.1 en la CI: `make api-lint` con `@redocly/cli`, **0 errores**. Comprobado rompiéndolo a propósito —quitando un `operationId` y poniendo un ejemplo que viola su propio esquema—, porque un validador que no puede fallar no valida nada.
- **El cliente TypeScript generado está versionado en los tres frontends**, y la etapa ① de la CI lo regenera y falla si difiere. Sin esa comprobación, el contrato podría cambiar y los clientes seguir compilando contra la forma antigua, que es exactamente la deriva que este ADR existe para impedir.
- Prueba de contrato: los errores se devuelven en `application/problem+json` y `security` declara el ámbito de cada operación (§7.3).
- Revisión en el *pull request*: si el diff toca un controlador de API y no toca `openapi.yaml`, se para. `docs:consistency` (tarea 0.7) lo verifica de forma automática.
