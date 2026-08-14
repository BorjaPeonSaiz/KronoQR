# ADR-019 — La caducidad de la licencia nunca bloquea el registro ni su consulta

| Campo | Valor |
|---|---|
| **Estado** | Aceptada |
| **Fecha** | 11 de agosto de 2026 (redactada el 14 de agosto de 2026, tarea 0.6) |
| **Decide** | `producto-licencia` |
| **Afecta a** | Tarea 5.3 · acotada y aplicada por [ADR-023](ADR-023-frontera-registro-legal-y-funcionalidad-accesoria.md) y [ADR-028](ADR-028-limites-del-plan-no-bloquean.md) · **Regla dura 15** de `CLAUDE.md` |
| **Requisitos** | RF-PD-05, RF-PD-04, RL-01, RL-02, RL-03, RL-05, RL-06, RNF-D-01 |

> Procede de la primera tabla del [documento 02](../02-stack-tecnologico-y-plan-implementacion.md) §4, que ya fijaba decisión, contexto y consecuencias. Esta redacción los desarrolla y los enlaza con los requisitos y con las reglas duras; no cambia la decisión.

## Contexto

Un producto licenciado necesita una palanca para cobrar. La palanca por defecto de la industria es apagar el producto cuando la licencia vence.

Aquí esa palanca es inadmisible, y no por generosidad:

- **Bloquear el fichaje dejaría al cliente incumpliendo la ley por una acción del fabricante.** El art. 34.9 ET le obliga a llevar registro diario de la jornada de toda su plantilla (RL-01). Si el producto deja de registrar, la infracción —grave, sancionable por centro de trabajo— es del cliente, causada por una decisión comercial que no controla.
- **Impediría el acceso a datos que está obligado a conservar cuatro años** (RL-02) y a tener a disposición de la plantilla, de su representación legal y de la Inspección (RL-03, RL-05, RL-06). Negar el acceso al propio registro ante un requerimiento es inconcebible.
- **Comercialmente tampoco funciona.** Un proveedor que deja a un hotel sin poder fichar en temporada alta no cobra antes: pierde al cliente y se gana la referencia negativa.

Y hay una consideración que va más allá del contrato: **el registro de jornada nunca es rehén de la relación comercial**. Es de la plantilla del cliente, no del fabricante.

## Decisión

**Con la licencia caducada, revocada o ausente, el sistema sigue registrando fichajes y permitiendo el acceso íntegro al registro legal. Se muestran avisos y se degradan funcionalidades accesorias, nunca el registro.**

Lo que **nunca** se degrada: fichaje por QR y por PIN, sincronización de la cola offline, consulta de jornadas y tramos, portal del empleado, exportación legal para Inspección, registro y consulta de auditoría, correcciones trazadas, copias de seguridad y restauración, y sondas de salud. La lista cerrada y su contrapartida —qué **sí** es accesorio— las fija [ADR-023](ADR-023-frontera-registro-legal-y-funcionalidad-accesoria.md), porque este ADR decía qué no se toca y ningún documento decía qué sí.

**El principio se extiende a los límites del plan** ([ADR-028](ADR-028-limites-del-plan-no-bloquean.md)): superar `max_employees`, `max_sites` o `max_devices` tampoco bloquea nada. Bloquear el alta no bloquea el fichaje de quien ya está dado de alta: bloquea el de quien todavía no lo está, que es la misma consecuencia con un día de retraso.

**La palanca comercial son los avisos, la degradación de lo accesorio y la evidencia auditada** de desde cuándo se opera fuera de contrato.

## Alternativas descartadas

| Alternativa | Por qué se descarta |
|---|---|
| **Bloquear el fichaje al caducar** | Deja al cliente incumpliendo el art. 34.9 ET por acción del fabricante y le impide acceder a datos de conservación obligatoria. Inaceptable con independencia de lo que diga el contrato |
| **Bloquear solo el panel de gestión, dejando el fichaje vivo** | El panel contiene la consulta del registro, las correcciones trazadas y la exportación para Inspección: bloquearlo incumple RL-03 y RL-06 aunque los datos sigan entrando |
| **Modo solo lectura al caducar** | Impide fichar, que es el acto que la ley obliga a registrar, y además deja las jornadas abiertas sin poder cerrarse |
| **Periodo de gracia y después bloqueo** | Solo traslada el problema a una fecha en la que nadie recuerda por qué el sistema dejó de funcionar. El daño legal es idéntico, con peor diagnóstico |
| **Borrar o cifrar los datos al vencer** | Destruiría un registro de conservación obligatoria y convertiría un impago en una infracción de protección de datos y en un delito potencial |

## Consecuencias

- **Exige separar en el código lo que es registro legal de lo que es producto.** No es una separación conceptual: es una lista cerrada y un punto único de decisión, que es lo que ADR-023 implementa. Sin ella, la degradación honesta se convierte en `if (license.expired)` repartidos.
- **La palanca comercial es más débil y hay que asumirlo.** A cambio, es defendible ante el cliente, ante su asesoría laboral y ante una inspección, y es lo que permite prometer en la venta que el registro nunca depende de la relación comercial.
- **Los avisos tienen que ser honestos y visibles**, indicando qué se ha desactivado y que se recupera al renovar. Un aviso ambiguo produce llamadas de soporte y desconfianza.
- **El aviso de exceso de plan es persistente y queda en `audit_log`** (ADR-028): es la evidencia con fecha que sostiene una reclamación comercial.
- **La verificación de licencia no está en el camino del fichaje** ([ADR-018](ADR-018-licencia-firmada-con-verificacion-local.md)). Un fallo verificando no puede producir un error en `/scan`.
- **Este ADR es el que acota a todos los demás sobre licencia.** Ninguno de ellos puede reintroducir un bloqueo del registro por un rodeo, y ADR-028 existe precisamente porque una tarea lo hizo sin darse cuenta.

## Verificación

- Prueba de *feature*: con licencia caducada, `POST /api/v1/scan` responde 200 y el tramo se registra.
- Prueba de *feature*: con licencia caducada, la sincronización de un lote offline se acepta íntegramente.
- Prueba de *feature*: con licencia caducada, la exportación legal para Inspección, la consulta de jornadas y el portal del empleado siguen respondiendo (RL-03, RL-05, RL-06).
- Prueba de *feature*: con licencia caducada, la corrección trazada de una jornada sigue siendo posible (RN-13).
- Prueba de *feature*: cada funcionalidad accesoria responde con el aviso de licencia y no con un error genérico (ADR-023).
- Prueba de arquitectura: ninguna comprobación de estado de licencia fuera del punto único de decisión; en particular, ninguna en el camino de `/scan` ni en los casos de uso de alta (ADR-028).
