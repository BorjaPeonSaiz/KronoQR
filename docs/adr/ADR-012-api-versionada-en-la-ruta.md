# ADR-012 — API versionada en la ruta (`/api/v1`)

| Campo | Valor |
|---|---|
| **Estado** | Aceptada |
| **Fecha** | 11 de agosto de 2026 (redactada el 14 de agosto de 2026, tarea 0.6) |
| **Decide** | `arquitecto-dominio` |
| **Afecta a** | Tareas 0.2, 0.6, 1.7 y 5.7 · [ADR-013](ADR-013-contrato-openapi-como-fuente-de-verdad.md) y [ADR-024](ADR-024-la-pausa-son-dos-tramos.md) |
| **Requisitos** | RF-KI-07, RF-PD-10, RQ-06, RNF-D-04 |

> Procede de la primera tabla del [documento 02](../02-stack-tecnologico-y-plan-implementacion.md) §4, que ya fijaba decisión, contexto y consecuencias. Esta redacción los desarrolla y los enlaza con los requisitos y con las reglas duras; no cambia la decisión.

## Contexto

Los clientes de esta API no se actualizan a la vez ni cuando uno quiere. Un quiosco es una tablet fijada en modo quiosco en la entrada de personal, cuya actualización está sujeta a ventana controlada para no interrumpir un cambio de turno (RF-KI-07), y que puede llevar días sin conexión con la cola llena. **Durante una actualización del servidor, los quioscos siguen encolando** (§11.6.4).

Eso significa que, en cualquier instante, el servidor recibe peticiones de clientes en versiones distintas de la que acaba de desplegarse, incluidas peticiones **generadas antes** del despliegue y sincronizadas después.

Sin una versión explícita en el contrato, la única forma de saber qué esperaba el cliente que envió un lote de hace tres días es adivinarlo.

## Decisión

**La versión va en la ruta: `/api/v1/...`. Se mantiene v1 mientras haya dispositivos en esa versión.**

Reglas de evolución, que son la parte útil de esta decisión:

- **Dentro de v1 solo caben cambios aditivos.** Campos nuevos opcionales, valores nuevos en un enum de respuesta con un valor por defecto que preserva el comportamiento anterior, endpoints nuevos. Es lo que permitió a [ADR-024](ADR-024-la-pausa-son-dos-tramos.md) ampliar el enum `action` de `/scan` sin romper a nadie.
- **Quitar un campo, cambiar su tipo, endurecer una validación o cambiar el significado de un valor existente exige v2.** No hay excepción «pequeña»: un quiosco con la cola llena es un cliente antiguo con datos que no se pueden perder.
- **La versión la fija la ruta, no una cabecera ni un parámetro.** Es visible en los logs, en las trazas, en la configuración de Nginx y en un `curl` de diagnóstico.
- **El contrato es la fuente de verdad de la forma de cada endpoint** ([ADR-013](ADR-013-contrato-openapi-como-fuente-de-verdad.md)), y toda ruta cuelga de `/api/v1` desde la tarea 0.2, cuando `routes/api_v1.php` se crea vacío.

## Alternativas descartadas

| Alternativa | Por qué se descarta |
|---|---|
| **Versión en cabecera** (`Accept` o `X-API-Version`) | Más purista y peor de operar: invisible en un log, en un `curl` y en la configuración del proxy. Diagnosticar «un quiosco falla y otro no» pasa a exigir inspeccionar cabeceras. En un producto que instala y depura el IT del cliente, la ruta gana |
| **Sin versión, manteniendo compatibilidad por disciplina** | La disciplina sin marca acaba en `if` por forma del payload. Y el día que haya que romper de verdad, no habrá dónde |
| **Versión por endpoint** (`/scan/v2`) | La superficie se fragmenta y el cliente tiene que recordar qué endpoint va en qué versión. La versión es del contrato, no de la ruta individual |
| **Versión por fecha** (estilo `2026-08-14`) | Excelente cuando hay muchos clientes externos y un equipo dedicado a mantener N contratos vivos. Aquí los clientes son tres aplicaciones propias y un parque de tablets: el coste de gestión no compensa |
| **Forzar la actualización del quiosco antes de aceptar peticiones** | Contradice la regla dura 19: dejaría a un centro sin poder fichar por una versión de aplicación. El quiosco nunca bloquea al empleado |

## Consecuencias

- **Compromiso explícito de mantener v1 mientras haya dispositivos en esa versión**, y el panel de salud de quioscos (RF-PA-07) es lo que permite saber cuándo ya no queda ninguno. Sin ese dato, la promesa sería indefinida.
- **Convivencia de dos versiones cuando llegue v2**, con el coste de mantener dos conjuntos de rutas, dos secciones de contrato y dos suites de pruebas de contrato. Se aplaza mientras los cambios sean aditivos, que es el motivo de la regla anterior.
- **La actualización del servidor no puede romper a un cliente antiguo** (RF-PD-10). El actualizador encadena migraciones y la API sigue aceptando lo que los quioscos ya tenían en la cola.
- **Toda ruta nueva nace bajo `/api/v1`**, incluidas las sondas de salud del Anexo B y `/scan`. No hay rutas «sueltas» fuera del contrato.
- **El cliente TypeScript generado lleva la versión incorporada**, así que una desviación entre frontend y backend aparece como error de tipos en compilación y no como fallo en producción (§3.3).
- **Las migraciones acompañan la misma disciplina**: patrón *expand / migrate / contract* (RNF-D-04). Un contrato aditivo con un esquema que rompe no sirve de nada.

## Verificación

- Prueba de contrato (Spectator, RQ-06): toda respuesta valida contra `docs/api/openapi.yaml`, y toda ruta del contrato cuelga de `/api/v1`.
- Prueba de *feature*: una petición a `/scan` sin los campos añadidos después (por ejemplo, sin `intent`) sigue siendo válida y produce el comportamiento anterior (aditividad, ADR-024).
- Prueba de contrato: ningún cambio del contrato elimina campos ni endurece validaciones dentro de v1. Se revisa en el *pull request* que toca `openapi.yaml`.
- Prueba de integración del actualizador (RQ-11): tras actualizar el servidor, un lote offline generado con el cliente de la versión anterior se sincroniza sin error.
- Comprobación de rutas: `routes/api_v1.php` es el único origen de rutas de API públicas.
