# Runbooks de KronoQR

Procedimientos de operación interna. Uno por cada modo de fallo que tenga una
alerta asociada.

**La norma que gobierna esta carpeta** (doc 02 §8.4): *cada alerta lleva
destinatario, umbral y enlace a su runbook. Una alerta sin procedimiento
asociado es ruido y se elimina.* Y por el otro lado, la Definición de Terminado
del §10.3: *runbook o documentación de cliente actualizada si añade un modo de
fallo o un parámetro.*

## Por qué esta carpeta está casi vacía en la Fase 0

Los 20 runbooks del doc 02 §12 describen **la respuesta a una alerta o a un
procedimiento que todavía no existe**. Escribirlos ahora produciría documentos
que nadie puede seguir —no hay comando que ejecutar, ni métrica que mirar, ni
pantalla que abrir— y que envejecerían antes de usarse por primera vez.

La regla que se aplica: **cada runbook se escribe en la tarea que crea su
alerta o su procedimiento**, con el sistema delante. Quien introduce el modo de
fallo es quien sabe qué hay que hacer cuando ocurra.

El escrutinio de la Fase 0 sobre esta regla es sencillo: la tarea 0.1 no añade
ninguna alerta —el catálogo es de la tarea 3.2— y el único modo de fallo nuevo
que introduce, `KIOSK_VLAN_CIDR` mal configurado, es un **parámetro de
instalación**, así que se documenta donde le corresponde:
[`docs/cliente/instalacion.md`](../cliente/instalacion.md).

La Fase 1 aplica la misma regla en la otra dirección: la tarea 1.18 **sí** crea
alertas —seis, sobre la copia de seguridad— y por eso trae escrito
[`restaurar-backup.md`](restaurar-backup.md) en el mismo cambio. Lo mismo hace la
tarea 1.14 con las cuatro alertas de la cadena de auditoría y
[`rotura-cadena-auditoria.md`](rotura-cadena-auditoria.md). Ninguna alerta llegó
antes que su procedimiento, y una prueba de arquitectura
(`backend/tests/Architecture/BackupAndAlertingTest.php`) falla si alguien añade
una regla cuyo `runbook_url` no existe.

## Los 20 runbooks y quién escribe cada uno

Asignación literal del plan de implementación
(`plan implementacion/08-entrega-despliegue-y-actualizacion.md` §9).

| # | Runbook | Cuándo se usa | Lo escribe |
|---|---|---|---|
| 1 | `quiosco-no-responde.md` | Alerta de latido perdido | Fase 3 · tarea 3.2 |
| 2 | `cola-offline-atascada.md` | Cola de un dispositivo por encima del umbral | Fase 3 · tarea 3.2 |
| 3 | [`divergencia-proyeccion.md`](divergencia-proyeccion.md) | La reconciliación nocturna detecta discrepancia | ✅ Fase 2 · tarea 2.7 → destinatarios reales en 3.2 |
| 4 | [`rotura-cadena-auditoria.md`](rotura-cadena-auditoria.md) | **Incidente de seguridad.** Incluye preservación de evidencia | ✅ Fase 1 · tarea 1.14 (era 2.2, adelantada por ADR-032) |
| 5 | [`restaurar-backup.md`](restaurar-backup.md) | Recuperación y simulacro trimestral | ✅ Fase 1 · tarea 1.18 (era 2.11, adelantada por ADR-032) → usado por 5.7 |
| 6 | [`rotacion-secretos.md`](rotacion-secretos.md) | Rotación programada o compromiso | ✅ §7.7 · escrito en la tarea 2.12 con la rotación del QR · ampliado en 5.4 |
| 7 | `alta-nuevo-quiosco.md` | Emparejamiento por código y vinculación | Fase 5 · tarea 5.6 |
| 8 | `alta-nuevo-empleado.md` | Alta, emisión, impresión y entrega con la antelación necesaria | Fase 1 · tarea 1.10 |
| 9 | `tarjeta-perdida-o-rota.md` | Revocación, reemisión y reimpresión en el día | Fase 1 · tarea 1.10 |
| 10 | [`rotacion-clave-qr.md`](rotacion-clave-qr.md) | Reimpresión progresiva sin dejar a nadie sin fichar | ✅ Fase 2 · tarea 2.12 |
| 11 | [`requerimiento-inspeccion.md`](requerimiento-inspeccion.md) | **Cómo generar la exportación legal en menos de 1 hora** | ✅ Fase 1 · tarea 1.17 (era 2.9, adelantada por ADR-032) |
| 12 | `patron-anomalo-credencial.md` | Revisar una incidencia `anomalous_pattern` sin convertir un indicio en una acusación | Fase 3 · tarea 3.11 |
| 13 | [`solicitud-derechos-rgpd.md`](solicitud-derechos-rgpd.md) | Acceso, rectificación, portabilidad — y la supresión que **no procede** mientras dure el deber de conservación | ✅ Fase 2 · tarea 2.10 |
| 14 | `brecha-de-seguridad.md` | Procedimiento de 72 h | Fase 2 (RL-15) · revisado en 3.10 |
| 15 | `actualizacion-cliente.md` | Procedimiento y vuelta atrás | Fase 5 · tarea 5.7 |
| 16 | `incidencia-sin-acceso.md` | Diagnosticar con el paquete que envía el cliente | Fase 5 · tarea 5.9 |
| 17 | `errores-en-el-panel.md` | Cómo lee el IT del cliente `error_events` y qué hacer con cada severidad | Fase 5 · tarea 5.12 |
| 18 | [`turno-abierto-prolongado.md`](turno-abierto-prolongado.md) | Turno abierto más de 12 h. **El sistema nunca lo cierra solo** (RN-08). Destinatario RRHH: no es una avería | ✅ Fase 2 · tarea 2.6 |
| 19 | `renovacion-certificado-tls.md` | Certificado a menos de 21 días de expirar | Fase 3 · tarea 3.2 |
| 20 | `espacio-en-disco.md` | Espacio libre por debajo del 20 % | Fase 3 · tarea 3.2 |

## Runbooks fuera de esa lista

Los 20 de arriba responden a **una alerta en el servidor de un cliente**. Hay
modos de fallo internos que no encajan ahí y que aun así merecen procedimiento,
por la misma razón del §10.3: *runbook actualizado si el cambio añade un modo de
fallo*. Se escriben en la tarea que los introduce.

| Runbook | Cuándo se usa | Lo escribió |
|---|---|---|
| [`fallo-de-ci.md`](fallo-de-ci.md) | Una etapa del pipeline está en rojo, o la puerta de versión bloquea una etiqueta | Fase 0 · tarea 0.4 |
| [`ataque-a-credenciales.md`](ataque-a-credenciales.md) | Alertas `KronoqrAuthFailureBurst`/`KronoqrAuthLockouts`/`KronoqrAuthFailureSpike` (OWASP A09) | SSDLC · pipeline de seguridad |
| [`triaje-hallazgos-seguridad.md`](triaje-hallazgos-seguridad.md) | Un hallazgo de Semgrep comunitario o Trivy en modo informe del job `security` | SSDLC · pipeline de seguridad |

## Qué debe contener un runbook

Que una persona del equipo pueda diagnosticar el incidente a las 06:30 sin
haber tocado nunca esa parte del sistema:

1. **Síntoma y alerta que lo dispara**, con su umbral.
2. **Qué significa y qué no significa** — sobre todo en los que señalan a una
   persona.
3. **Impacto en el fichaje.** Lo primero que hay que saber es si alguien se ha
   quedado sin poder fichar (regla dura 19: el quiosco nunca bloquea al
   empleado).
4. **Diagnóstico**: comandos concretos, copiables, con la salida esperada.
5. **Resolución**, con la vuelta atrás si la hay.
6. **Qué preservar antes de tocar nada** en los de seguridad.
7. **A quién se escala** y en cuánto tiempo.
