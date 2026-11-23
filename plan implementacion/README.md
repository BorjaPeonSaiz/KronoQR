# Plan de implementación de KronoQR — índice

El plan del [documento 02](../docs/02-stack-tecnologico-y-plan-implementacion.md) §11, desarrollado tarea por tarea y
ordenado por orden real de ejecución: **0 → 1 → 2 → 5 → 3 → 4**. Cada ficha de tarea indica requisitos,
precondiciones, agente y skill, pasos, artefactos y pruebas exigidas.

**Estado:** las fases 0, 1, 2, 5 y 3 están cerradas; de la 4 solo se adelantó la tarea 4.1. El estado por fase, con su
fecha de cierre y la versión que la entregó, está en el doc 02 §11. El trabajo posterior a la 2.1.0 se planifica por
bloques de corrección en [`docs/verificacion/`](../docs/verificacion/), no aquí.

**El plan no es solo historia:** `php artisan docs:consistency` lee las fichas (`### Tarea N.M — …`) de los ficheros
de fase para comprobar que cada requisito de `docs/requisitos.yaml` lo cita alguna tarea en la fase que le asigna el
Anexo A del doc 01 (`quality.plan_path` en `backend/config/quality.php`). Un requisito nuevo necesita su tarea en el
fichero de su fase.

| Fichero | Contenido |
|---|---|
| [01-herramientas-y-entorno](01-herramientas-y-entorno.md) | Herramientas y entorno previos a la Fase 0 |
| [02-fase-0-cimientos](02-fase-0-cimientos.md) | Fase 0 — Cimientos |
| [03-fase-1-mvp-fichaje](03-fase-1-mvp-fichaje.md) | Fase 1 — MVP de fichaje, con los prompts de arranque del dominio, las credenciales y el quiosco |
| [04-fase-2-gestion-y-cumplimiento](04-fase-2-gestion-y-cumplimiento.md) | Fase 2 — Gestión y cumplimiento |
| [05-fase-5-productizacion](05-fase-5-productizacion.md) | Fase 5 — Productización |
| [06-fase-3-operacion-y-refuerzo](06-fase-3-operacion-y-refuerzo.md) | Fase 3 — Operación y refuerzo |
| [07-fase-4-evolucion](07-fase-4-evolucion.md) | Fase 4 — Evolución y tareas adelantadas a una versión anterior |
| [08-entrega-despliegue-y-actualizacion](08-entrega-despliegue-y-actualizacion.md) | Entrega al cliente, despliegue y actualización |

Las versiones anteriores de este índice (camino crítico, recortes, convenciones de rama y decisiones resueltas antes
de la Fase 0) están en el historial de git: `git log -- "plan implementacion/README.md"`.
