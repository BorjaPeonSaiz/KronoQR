# ADR-016 — Producto licenciado on-premise, sin multi-tenencia

| Campo | Valor |
|---|---|
| **Estado** | Aceptada |
| **Fecha** | 11 de agosto de 2026 (redactada el 14 de agosto de 2026, tarea 0.6) |
| **Decide** | `producto-licencia` |
| **Afecta a** | Todo el producto · Tareas 5.4, 5.7, 5.9, 5.11 · [documento 02](../02-stack-tecnologico-y-plan-implementacion.md) §11.6 · Reglas duras 13 y 16 de `CLAUDE.md` |
| **Requisitos** | RF-PD-02, RF-PD-10, RF-PD-14, RL-14, RL-16, RL-17, RL-20, RNF-D-02, RQ-11 |

> Procede de la primera tabla del [documento 02](../02-stack-tecnologico-y-plan-implementacion.md) §4, que ya fijaba decisión, contexto y consecuencias. Esta redacción los desarrolla y los enlaza con los requisitos y con las reglas duras; no cambia la decisión.

## Contexto

El modelo de negocio estaba decidido antes que la arquitectura: **el producto se vende licenciado y se despliega en el servidor de cada cliente**. No hay SaaS, no hay instancia compartida, no hay datos de la plantilla de un hotel en infraestructura del fabricante.

No es una restricción técnica arbitraria. En un producto de registro horario, cambia quién responde ante quién: los datos son de la plantilla del cliente, el responsable del tratamiento es el cliente (RL-16) y quien responde ante la Inspección es el cliente. Con los datos alojados en su propio servidor, el fabricante **no es encargado del tratamiento** en la operación ordinaria (RL-17), lo que simplifica el contrato de cada venta y elimina toda una categoría de riesgo.

Lo que este ADR fija no es esa decisión comercial, sino **lo que implica para la arquitectura**, que es mucho y no siempre evidente.

## Decisión

**Cada cliente tiene su instalación completa e independiente: su base de datos, su Redis, sus contenedores, sus copias y su clave de licencia. No existe multi-tenencia en el código.**

Consecuencias directas que se adoptan como reglas:

- **El aislamiento es físico.** Ninguna tabla lleva `tenant_id`, ninguna consulta se filtra por cliente, ninguna caché se segmenta. No hay forma de que los datos de un cliente aparezcan en la instalación de otro, porque no comparten nada.
- **El sistema funciona íntegramente sin salida a internet** (§6.7, §11.6.2). La verificación de licencia es local ([ADR-018](ADR-018-licencia-firmada-con-verificacion-local.md)), no hay telemetría obligatoria y los certificados pueden ser propios del cliente.
- **El fabricante no puede intervenir en producción.** No hay consola de administración remota, ni acceso permanente, ni despliegues del fabricante sobre la instalación del cliente. El soporte se presta con paquete de diagnóstico ([ADR-020](ADR-020-soporte-con-paquete-de-diagnostico.md)).
- **La instalación y la actualización son autónomas** y ejecutables por el IT del cliente (RF-PD-02, RF-PD-10), incluidas actualizaciones entre versiones **no consecutivas**.
- **El cliente puede exportar todos sus datos en formato abierto y sin intervención del fabricante** (RF-PD-14, RL-20). Es su garantía de no quedar atrapado, y en un producto de conservación obligatoria a cuatro años no es un detalle contractual: es la condición para que pueda seguir cumpliendo si la relación comercial termina.

Y una que gobierna todo lo demás: **nada específico de un cliente vive en el código** ([ADR-017](ADR-017-toda-diferencia-entre-clientes-es-configuracion.md)).

## Alternativas descartadas

| Alternativa | Por qué se descarta |
|---|---|
| **SaaS multi-inquilino** | Convierte al fabricante en encargado del tratamiento de los datos de jornada de N plantillas, con la carga contractual y de seguridad que eso arrastra. Y la fuga entre inquilinos —un filtro olvidado en una consulta— sería una brecha con datos laborales de otro cliente. El aislamiento físico es gratis aquí |
| **SaaS con base de datos por cliente** | Reduce la fuga de datos, no la responsabilidad ni la operación. Sigue siendo el fabricante quien aloja, respalda y responde de la disponibilidad de un registro con obligación legal |
| **Instalación en el servidor del cliente, pero con panel central del fabricante** | Reintroduce el acceso permanente que ADR-020 descarta y crea una dependencia de conectividad para un sistema que debe funcionar aislado |
| **Una rama de código por cliente** | Destruye la economía del producto en el tercer cliente y hace imposible publicar una corrección de seguridad. Prohibido explícitamente por la regla dura 13 |

## Consecuencias

- **Hay que soportar N instalaciones en M versiones.** Se acota con la matriz de versiones soportadas del §11.6.5 —la menor vigente y las dos anteriores— y con actualizaciones encadenadas. Sin esa disciplina, con veinte clientes se acaban manteniendo veinte productos.
- **La instalación es un entregable de primera clase**, no un anexo: `install.sh`, `update.sh`, `backup.sh`, `doctor.sh` y cuatro manuales (§11.6.1). La tarea 5.11 es la más subestimada del plan, y una documentación mediocre se paga en soporte con cada cliente, indefinidamente.
- **Instalación limpia y actualización se prueban antes de cada publicación** (RQ-11), en Linux, que es la única vía soportada ([ADR-022](ADR-022-sin-instalador-de-windows.md)).
- **Las copias, la restauración y la retención las opera el cliente**, con herramientas del producto y con alerta si fallan (§11.6.3). El RPO ≤ 15 min de RNF-D-02 depende de que él las configure, y eso va en el contrato y en la documentación.
- **El fabricante no es destinatario de ninguna alerta** (§9.3): no tiene acceso a la instalación y no puede actuar sobre ella.
- **El diagnóstico tiene que ser autoexplicativo.** Nadie del fabricante va a mirar los logs en vivo: los errores se persisten en `error_events` (RF-PD-15) y viajan anonimizados en el paquete de diagnóstico.
- **Se pierde la telemetría de uso por defecto**, y con ella la posibilidad de saber qué se usa y qué no. Es opcional y desactivada de serie (RF-PD-12): a cambio, ninguna venta se complica por explicar qué se envía al fabricante.

## Verificación

- Prueba de arquitectura: ninguna tabla tiene columna de inquilino y ninguna consulta filtra por cliente.
- Prueba de instalación limpia y de actualización desde cada versión soportada, en la etapa 8 de la CI, antes de publicar (RQ-11).
- Prueba de instalación **sin salida a internet**: el sistema arranca, ficha, verifica su licencia y responde a las sondas de salud.
- Prueba de *feature*: la exportación íntegra de datos en formato abierto la ejecuta el propio cliente y no requiere intervención del fabricante (RF-PD-14).
- `doctor.sh` devuelve un informe accionable sobre base de datos, colas, correo, certificados, permisos y espacio (RF-PD-13).
- Búsqueda en el repositorio: ninguna rama ni fichero con nombre de cliente (regla dura 13, verificado por `docs:consistency` y revisión).
