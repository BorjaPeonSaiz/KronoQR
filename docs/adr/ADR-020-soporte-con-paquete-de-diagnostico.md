# ADR-020 — El soporte se presta con paquete de diagnóstico, no con acceso permanente

| Campo | Valor |
|---|---|
| **Estado** | Aceptada |
| **Fecha** | 11 de agosto de 2026 (redactada el 14 de agosto de 2026, tarea 0.6) |
| **Decide** | `producto-licencia` con revisión de `seguridad-cumplimiento` |
| **Afecta a** | Tareas 5.9 y 5.12 · [documento 02](../02-stack-tecnologico-y-plan-implementacion.md) §11.6.6 · **Regla dura 16** de `CLAUDE.md`, y la 21 |
| **Requisitos** | RF-PD-09, RF-PD-11, RF-PD-15, RL-17, RL-18, RL-19, RS-05, RL-15 |

> Procede de la primera tabla del [documento 02](../02-stack-tecnologico-y-plan-implementacion.md) §4, que ya fijaba decisión, contexto y consecuencias. Esta redacción los desarrolla y los enlaza con los requisitos y con las reglas duras; no cambia la decisión.

## Contexto

El producto se instala en el servidor del cliente ([ADR-016](ADR-016-producto-licenciado-on-premise.md)) y contiene los datos de jornada de toda su plantilla: dónde estaba cada persona a cada hora, cada día, durante cuatro años. Es uno de los conjuntos de datos laborales más sensibles que existen.

La forma cómoda de dar soporte —una cuenta de administrador permanente del fabricante en cada instalación— tiene tres problemas encadenados:

1. **Convierte al fabricante en encargado del tratamiento de forma continuada**, con contrato de encargo, instrucciones documentadas y responsabilidad sobre datos que no necesita para arreglar un error. RL-17 dice justo lo contrario: en la operación ordinaria no accede y no es encargado.
2. **Es un objetivo.** Un fabricante con acceso permanente a N instalaciones es una llave maestra: comprometerlo compromete a todos sus clientes a la vez.
3. **No es auditable para el cliente.** Un acceso que existe siempre no deja rastro distinguible entre «entró a arreglar el incidente» y «entró a mirar».

Y el escenario en el que ese acceso se usa mal no es el del atacante externo, sino el más prosaico: **usar la sesión abierta para un incidente distinto del que la motivó** (§8.1, elevación de privilegios).

## Decisión

**El soporte se presta con un paquete de diagnóstico que genera el cliente y envía. El acceso a datos del cliente es excepcional, expreso, temporal, limitado y auditado.**

- **Paquete de diagnóstico** (RF-PD-09, §11.6.6): lo genera el administrador del cliente con un clic o un comando. Contiene versión, configuración **sin secretos**, estado de los servicios, salud de quioscos, tamaño de las colas, resultado de `doctor`, métricas agregadas y el **histórico de `error_events`** del periodo con su agrupación por huella y su `trace_id` (RF-PD-15).
- **Anonimizado por defecto** (RL-19): sin nombres, sin correos y sin registros de jornada. Los empleados aparecen como `employee_uuid`. Incluir datos personales es una acción distinta, explícita, avisada en la interfaz y auditada.
- **Acceso puntual** (RF-PD-11): solo con concesión expresa del cliente, con caducidad, alcance limitado, revocable en cualquier momento y registrado en auditoría **visible para el cliente**. La tabla `support_grants` guarda quién lo concedió, por qué, con qué alcance, cuándo caduca, cuándo se revocó y cuándo se usó. Durante esa intervención el fabricante actúa como encargado **para ese supuesto concreto** (RL-18), con su contrato de encargo del art. 28 RGPD.
- **Consecuencia obligatoria: los errores tienen que ser autoexplicativos.** Si diagnosticar exige mirar los datos, el paquete no sirve para nada. Por eso `error_events` persiste en base de datos, agrupado por huella y consultable por el propio cliente desde el panel.

**Nunca nombres de empleados en logs técnicos ni en `error_events`** (regla dura 21). Se usa `employee_uuid`. Ese histórico viaja al fabricante: si lleva datos personales, se ha filtrado.

## Alternativas descartadas

| Alternativa | Por qué se descarta |
|---|---|
| **Cuenta de soporte permanente en cada instalación** | Encargo de tratamiento continuado sin necesidad, llave maestra sobre N clientes y sin rastro distinguible de por qué se entró. Es lo que este ADR existe para evitar |
| **Túnel inverso o acceso remoto siempre disponible** | El mismo problema con peor visibilidad para el cliente, y añade una dependencia de conectividad en un producto que debe funcionar aislado (§6.7) |
| **Telemetría continua con datos de uso detallados** | Convierte el flujo ordinario en una salida constante de datos hacia el fabricante. La telemetría existe (RF-PD-12) **opcional, agregada y desactivada por defecto**, y jamás con datos personales ni de jornada |
| **Paquete de diagnóstico con datos personales de serie**, anonimizando al analizar | Invierte la carga: el dato ya salió. La anonimización tiene que ocurrir en origen, en la instalación del cliente |
| **Depender del stack de observabilidad del cliente (Loki)** | Es opcional en su instalación, puede no tener quien lo mire y puede perderlo al reinstalar. Si el único rastro del error vive ahí, la primera pregunta de cada incidencia será «¿puedes mirar los logs?» y la respuesta será que no (§8.2.1) |

## Consecuencias

- **Diagnosticar es más difícil, y esa dificultad se convierte en requisito de producto.** Los mensajes de error, los códigos y el contexto técnico tienen que bastar sin ver los datos. `error_events` con agrupación por huella (RF-PD-15) es la respuesta, y por eso es un requisito **Must**.
- **El cliente participa en su propio soporte**: genera el paquete, lo envía y concede acceso si hace falta. Va en el reparto de responsabilidades del §11.6.3 y en el contrato.
- **La anonimización hay que probarla, no confiarla.** El paquete es un artefacto que sale de la instalación: una prueba automática debe verificar que no contiene nombres, correos, DNI ni horas de fichaje.
- **El fabricante no es destinatario de ninguna alerta** (§9.3): no tiene acceso y no puede intervenir. Las alertas van al IT del cliente o a su responsable de seguridad.
- **La concesión de soporte es un objeto de dominio con ciclo de vida**, no una casilla: caduca sola, se revoca en cualquier momento y su uso queda registrado. Sin caducidad automática, una concesión olvidada es una cuenta permanente con otro nombre.
- **Simplifica el contrato de cada venta** (RL-17): el fabricante no es encargado en la operación ordinaria. El contrato de encargo se activa solo para el supuesto de intervención (RL-18).
- **En caso de brecha, la capacidad de determinar el alcance es del cliente** (RL-15), a partir de su propio `audit_log`. El producto se lo tiene que dar hecho.

## Verificación

- Prueba automática sobre el paquete generado: **cero nombres, correos, DNI y horas de fichaje**. Solo `employee_uuid` y `device_id` (RL-19, regla dura 21).
- Prueba automática: el paquete no contiene secretos —claves, tokens, contraseñas, cadenas de conexión— (RF-PD-09).
- Prueba de *feature*: incluir datos personales en el paquete exige acción explícita, muestra aviso y deja entrada en `audit_log`.
- Prueba de *feature*: una concesión de soporte caducada no da acceso; una revocada tampoco; y todo uso queda en `audit_log` visible para el cliente (RF-PD-11).
- Prueba de integración: `error_events` no almacena nombres ni horas de nadie; el contexto se limita a datos técnicos, `trace_id`, `employee_uuid` y `device_id`.
- Prueba de arquitectura: ningún canal del producto envía datos al fabricante fuera del paquete de diagnóstico y de la telemetría opcional desactivada por defecto.
