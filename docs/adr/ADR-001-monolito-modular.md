# ADR-001 — Monolito modular, no microservicios

| Campo | Valor |
|---|---|
| **Estado** | Aceptada |
| **Fecha** | 11 de agosto de 2026 (redactada el 14 de agosto de 2026, tarea 0.6) |
| **Decide** | `arquitecto-dominio` |
| **Afecta a** | Tareas 0.2 y 0.3 · [documento 02](../02-stack-tecnologico-y-plan-implementacion.md) §1.1, §1.2 y §1.6 · Regla dura 1 de `CLAUDE.md` |
| **Requisitos** | RN-01, RN-02, RNF-M-03, RNF-P-06, RF-PD-02 |

> Procede de la primera tabla del [documento 02](../02-stack-tecnologico-y-plan-implementacion.md) §4, que ya fijaba decisión, contexto y consecuencias. Esta redacción los desarrolla y los enlaza con los requisitos y con las reglas duras; no cambia la decisión.

## Contexto

El sistema tiene que resolver a la vez cinco fuerzas que tiran en direcciones distintas (§1.1):

- **El dato tiene valor probatorio legal.** Un registro horario que se contradice a sí mismo no vale ante la Inspección.
- **Las invariantes son transaccionales.** RN-01 (un solo turno abierto) y RN-02 (sin solapes) se comprueban y se escriben en el mismo instante. Con consistencia eventual, dos fichajes simultáneos producen dos turnos abiertos y un total diario erróneo, que es un dato de nómina equivocado.
- **El dominio es pequeño pero denso.** Cambio de hora, turnos que cruzan medianoche, descansos, idempotencia. Poca superficie, mucha regla.
- **El equipo es pequeño y el despliegue es del cliente.** El IT de un hotel instala el producto siguiendo una guía (RF-PD-02). Cualquier topología distribuida convierte esa instalación en un proyecto de ingeniería.
- **La escala es modesta.** 500 empleados, 10 quioscos, ~6.000 eventos al día, ~2 M registros al año (documento 01 §6.3). El pico real es el cambio de turno: 50 fichajes por segundo (RNF-P-06), que un solo proceso absorbe sin despeinarse.

La pregunta no es si los microservicios escalan mejor —aquí no hay nada que escalar—, sino **qué frontera protege el dominio sin cobrar peaje operativo al cliente**.

## Decisión

**Un único artefacto desplegable, dividido en ocho módulos con fronteras reales verificadas por herramienta.**

`Attendance` (núcleo), `Compliance`, `Workforce`, `Identity`, `Reporting`, `Kiosk`, `Product` y `Shared`, cada uno con sus cuatro capas, y una tabla de dependencias admitidas (§1.6) que Deptrac comprueba en cada *pull request*.

**Modularidad sin distribución** es la formulación exacta: la frontera existe y se verifica, pero no atraviesa la red. Si algún día un módulo necesitara desplegarse aparte, la separación ya está hecha y la extracción es mecánica. Se paga el diseño; no se paga la operación.

## Alternativas descartadas

| Alternativa | Por qué se descarta |
|---|---|
| **N-capas clásico** (controlador → servicio → Eloquent) | Lo más rápido de escribir y lo más caro de mantener. Las reglas acaban repartidas entre controladores, modelos y *observers*; probar el cálculo de DST exige levantar base de datos; y el día que cambie un umbral legal nadie sabe cuántos sitios hay que tocar. Con este dominio, la deuda aparece en el mes tres |
| **Microservicios** | Rompe RN-01 y RN-02, que pasarían a resolverse con sagas y compensaciones: consistencia eventual sobre el dato que sostiene una nómina. Añade latencia en el camino crítico de 800 ms del quiosco (RNF-P-01) y multiplica por diez la dificultad de que un hotel lo instale |
| **Serverless** | Arranques en frío incompatibles con RNF-P-01, conexiones a base de datos relacional problemáticas y radicalmente incompatible con el despliegue en el servidor del cliente (ADR-016) |
| **Event sourcing completo** | Tentador por el requisito de inmutabilidad, pero la ceremonia completa no compensa. **Se adopta la parte útil**: `scan_events` es un log inmutable y `daily_totals` una proyección reconstruible (ADR-007). Eso es CQRS-lite, no event sourcing |
| **Arquitectura multi-inquilino** | Cada cliente tiene su instalación completa (ADR-016): el aislamiento es físico y gratuito. Añadirla sería pagar complejidad por una capacidad que el modelo de negocio no pide |

## Consecuencias

- **Un despliegue, un `docker compose up`.** Es lo que hace posible RF-PD-02: instalación autónoma por el IT del cliente, sin intervención del fabricante.
- **Las fronteras se mantienen por disciplina, no por la red**, y la disciplina se delega en una herramienta: Deptrac y las pruebas de arquitectura bloquean en la CI (regla dura 1, RNF-M-03). Sin esa verificación automática, el monolito modular degenera en monolito a secas en cuestión de semanas. **Es la única consecuencia de este ADR que puede matarlo.**
- **Todo comparte proceso y base de datos.** Una consulta lenta de `Reporting` puede afectar al camino de fichaje. Se compensa con colas para lo pesado (informes, PDF, exportaciones) y con el presupuesto de latencia de RNF-P-02.
- **Los módulos se comunican por tres vías y solo tres** (§1.6, ADR-025): caso de uso público, evento de dominio, o implementar un puerto declarado por el consumidor. Nunca por acceso directo a los modelos Eloquent de otro módulo.
- **El coste de equivocarse es reversible.** Extraer un módulo con la frontera ya trazada es trabajo mecánico; volver de microservicios a monolito con datos repartidos, no.

## Verificación

- Deptrac en verde con la tabla del §1.6 declarada como reglas, y la CI falla si aparece una arista nueva.
- Prueba de arquitectura: `Modules/*/Domain` no importa `Illuminate\*`, `App\Models\*` ni otro módulo. La verificación de la Fase 0 es explícita: **añadir a propósito un `use Illuminate\...` dentro de `Domain/` debe hacer fallar la CI**.
- `docker compose` de producción levanta **un** servicio de aplicación con sus procesos auxiliares (colas, WebSocket, scheduler), no N servicios de negocio.
- Prueba de carga (RNF-P-06): 50 fichajes por segundo sostenidos en el proceso único, antes de cada versión mayor.
