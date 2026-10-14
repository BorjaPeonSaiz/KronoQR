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

> **Nota de precisión (2.2.0, Bloque 19).** «Anonimizado por defecto», aplicado al texto libre del histórico de errores (el mensaje y los valores de texto del contexto), se cumple con una **lista blanca de palabras técnicas**, no con patrones: cualquier palabra que no esté en el vocabulario cerrado del producto se sustituye por `…`. Los patrones se mantienen para los identificadores numéricos. Ver [ADR-048](ADR-048-el-texto-libre-del-historico-de-errores-pasa-por-una-lista-blanca-de-palabras.md), que define también la prueba de volumen sembrada que lo verifica. Esta decisión no cambia: la precisa.

## Enmienda 08-10-2026 (bloque 14 de la 2.2.0, hallazgo A6-5): cómo quedó implementado el acceso de soporte y el paquete

**Motivo.** La verificación de la 2.1.0 ([tanda 6](../verificacion/2.1.0-tanda-6.md), A6-5) y la re-verificación de la 2.2.0 encontraron que este ADR no recoge cómo se concretaron, en las tareas 5.9 y 5.12, el acceso puntual y el paquete. Lo implementado es coherente con la decisión, pero tres de sus piezas cambian lo que el cliente concede y lo que sale de su servidor, y no estaban escritas aquí. Esta enmienda las recoge. **La decisión no cambia.**

1. **La concesión es un token de Sanctum cuyo titular es la propia concesión** (`support_grants`, `Product\Infrastructure\Adapter\SanctumSupportTokenIssuer`). El fabricante no tiene cuenta en la instalación, que es la alternativa que este ADR descarta. El `admin` la concede con un motivo y entrega el token en mano al fabricante. El token en claro solo existe en la respuesta `201` o en la salida del comando. La caducidad del token es la de la concesión (`PRODUCT_SUPPORT_GRANT_DEFAULT_HOURS`, 24 h de serie; `PRODUCT_SUPPORT_GRANT_MAX_HOURS`, 72 h como máximo). La revocación vale en la petición siguiente porque `IdentityServiceProvider` comprueba la concesión en cada petición ([ADR-052](ADR-052-sesiones-con-token-bearer-de-sanctum-sin-cookies.md)). Desde [ADR-051](ADR-051-cuentas-de-gestion-desde-el-panel-con-contrasenas-temporales.md), dar de baja la cuenta que concedió retira sus concesiones.
2. **El alcance es una lista cerrada de tres** (`Product\Domain\ValueObject\SupportScope`), que se traduce en las *abilities* del token: `diagnostics` (`diagnostics:*`); `read_only` (`diagnostics:*`, `attendance:read`, `employees:read` y `audit:read`); y `configuration` (`diagnostics:*` y `settings:*`). Ningún alcance lleva `license:*`, `support:*`, `employees:*`, `credentials:*`, `attendance:correct`, `reports:legal` ni `accounts:*`, y las policies cierran además lo que el ámbito permitiría: el perfil de cumplimiento, los umbrales reservados al cliente y la generación del paquete con datos personales. `SupportScopeRoutesTest` lo comprueba recorriendo las rutas registradas.
3. **El uso deja un asiento por ventana, no uno por petición.** `support_grant.used` se escribe como mucho una vez cada `PRODUCT_SUPPORT_USE_AUDIT_WINDOW_SECONDS` (900 s de serie) por concesión, porque cada asiento pasa por el candado global de [ADR-010](ADR-010-auditoria-solo-append-encadenada.md), el mismo que cada fichaje. `support_grants.accessed_at` sí se actualiza en cada petición. Una sesión de una hora deja cuatro asientos, suficientes para reconstruir cuándo estuvo dentro. Con `0` hay un asiento por petición, y solo tiene sentido mientras se investiga un incidente. Lo que el soporte **cambia** sí deja un asiento por cambio, con `actor_type = support_grant`.
4. **El paquete es un único JSON sin cifrar, también cuando lleva datos personales** (`Product\Domain\ValueObject\DiagnosticsBundle`). No se cifra a propósito: el cliente tiene que poder inspeccionarlo antes de enviarlo, y el canal de envío es el de su contrato de soporte, fuera del producto. La integridad la da la huella del manifiesto. La sección `personal_data` solo la pide un `admin` (un token de soporte recibe `403`), deja su propio asiento (`diagnostics.personal_data_included`), pone `manifest.anonymized` a `false` y está acotada a `PRODUCT_DIAGNOSTICS_PERSONAL_DATA_MAX_PERIOD_DAYS` (31 días), 2000 fichas y 5000 filas por colección. `national_id_hash`, `email`, `pin_hash`, `photo_path` y `client_meta` no salen nunca. El fichero se borra a los `PRODUCT_DIAGNOSTICS_RETENTION_DAYS` (7 días de serie) o al generar el siguiente. Vive en el volumen de ficheros generados ([ADR-045](ADR-045-los-ficheros-generados-viven-en-un-volumen-compartido.md)).

**Riesgo residual que esta enmienda hace explícito:** mientras está en el disco y durante su envío, un paquete con `personal_data` es una copia legible de la plantilla y de un mes de fichajes. Lo protegen los permisos del volumen, la caducidad de 7 días y el canal que elija el cliente, no el producto. Cifrarlo para el fabricante, con una clave pública del fabricante en el paquete de entrega, es la evolución posible, pero obliga a decidir antes si el cliente renuncia a inspeccionarlo. Lo valora `seguridad-cumplimiento` en doc 07 §6. Mientras tanto, la guía del cliente tiene que decir que ese fichero se envía por un canal cifrado y se borra al enviarlo.

## Enmienda 14-10-2026 (bloque 12 de la 2.2.1, hallazgo V4-PL-1): el paquete se genera con PostgreSQL caído y su asiento se escribe al volver la base

**Motivo.** El paquete se necesita sobre todo cuando algo va mal, y lo que más a menudo va mal es la base de datos. Hasta la 2.2.0 el asiento `diagnostics.bundle_generated` era condición previa de la entrega: con PostgreSQL caído el asiento no se podía escribir, la excepción subía por `product:diagnostics` y **no se generaba ningún fichero**. El cliente se quedaba sin el paquete justo en la incidencia que más lo necesitaba, y soporte tenía que pedirle a mano lo que el paquete habría contado. Además, dos recolectores (`installation` y `configuration`) leían primero los ajustes guardados y, sin base, perdían la sección entera, incluidas la versión del producto y la configuración del `.env`, que no dependen de la base.

**Decisión** (de `seguridad-cumplimiento`, aprobada por el propietario el 14-10-2026). **En este único caso, el paquete puede entregarse antes de que exista su asiento.** No es una excepción a la regla dura 6: el asiento no se pierde, se aplaza, y el producto lo escribe solo en cuanto la base vuelve. Las condiciones son todas acumulativas:

1. **Solo el paquete anonimizado, y solo por consola** (`product:diagnostics`, actor `console`). El que lleva datos personales (`--with-personal-data`) no se genera si no se puede auditar: sale con código `1`, sin fichero y sin pendiente, y lo dice. Sacar datos de la plantilla sin dejar rastro es justo lo que RL-19 prohíbe. La ruta HTTP no cambia: sin base no hay sesión que autentique la petición.
2. **Solo si el asiento falla porque la base no está disponible.** Lo decide el puerto `ProbeFailureClassifier` de siempre (SQLSTATE de la clase `08` o `DatabaseUnavailable` del cortacircuitos). Cualquier otro fallo del asiento con la base arriba (una partición que falta, un payload que no valida, un listener que revienta) sigue cerrando: sin fichero y sin pendiente. Esos fallos son defectos que hay que ver, no caídas que haya que sortear.
3. **El orden es fijo:** se sella el paquete, se intenta el asiento y, si falla por base no disponible, se **vuelve a sellar** con la constancia dentro, se escribe el paquete entero en un temporal junto a su destino, se guarda el pendiente y solo entonces se publica el paquete renombrando el temporal, que es atómico. Si el pendiente no se puede guardar, el temporal se borra y el paquete no sale; si el renombrado falla, se borra el pendiente. La secuencia vive entera en el caso de uso `DeliverDiagnosticsBundle`, y no depende de que quien llama la respete: el escritor se niega a publicar un paquete pendiente sin el comprobante `DeferredAuditReceipt` de su pendiente guardado, y `DiagnosticsDeliveryTest` exige que solo ese caso de uso escriba, guarde pendientes y emita comprobantes.
4. **La constancia viaja dentro del paquete y dentro de su huella:** `audit.generation_entry = {"status": "pending", "reason": "database_unavailable"}`. Quien lo recibe sabe que el asiento aún no existe. Quitarla sin recalcular la huella hace fallar `--verify`; pero la huella no lleva clave, así que no protege contra quien la recalcula: la referencia fiable es el `sha256` del asiento `diagnostics.bundle_generated` en `audit_log`. No va en `manifest`, cuyo esquema es cerrado.
5. **El pendiente es un fichero duradero de claves cerradas** —`sha256`, `sections`, `size_bytes`, `anonymized`, `generated_by`, `generated_at` (UTC) y `reason`—, `0600`, en `storage/app/diagnostics-audit-pending`: dentro del volumen de ficheros generados ([ADR-045](ADR-045-los-ficheros-generados-viven-en-un-volumen-compartido.md)), para que sobreviva a la recreación del contenedor, y fuera de `storage/app/diagnostics`, para que la purga de paquetes caducados no lo borre. Sin mensaje de excepción, sin host, sin usuario y sin texto libre.
6. **El asiento lo escribe el producto, no una persona:** la tarea programada `product:diagnostics:record-pending` (cada cinco minutos, sin solaparse) y, además, cada `product:diagnostics` al arrancar. `Product` vuelve a publicar `DiagnosticsBundleGenerated` con `deferred = true` y el instante original; el listener de `Compliance` escribe `diagnostics.bundle_generated` con `payload.deferred = true` y `payload.generated_at` = el instante de la generación. Sin arista nueva entre módulos.
7. **`occurred_at` es el instante en que se escribe el asiento, nunca el de la generación.** La cadena de [ADR-010](ADR-010-auditoria-solo-append-encadenada.md) y el sellado anual de [ADR-027](ADR-027-audit-log-particionado.md) suponen `id` y `occurred_at` crecientes; un asiento fechado en el pasado los rompería. La generación se lee en `payload.generated_at`.
8. **Idempotente por huella.** Antes de escribir, el listener comprueba, dentro del candado de la cadena, que no existe ya un `diagnostics.bundle_generated` con ese `sha256`. Dos pasadas simultáneas o repetidas dejan una sola fila. El pendiente se borra después de confirmarse el asiento.
9. **`product:doctor` avisa** (`diagnostics.pending_audit`) si hay un pendiente de más de una hora: la base volvió y el asiento sigue sin escribirse, o la base lleva una hora caída.

**Riesgo residual que esta enmienda hace explícito.** Entre la generación y el regreso de la base, existe un paquete entregado sin asiento. Lo acotan tres cosas: el paquete es el anonimizado, que no lleva datos personales; el pendiente vive en el volumen del cliente y se escribe solo; y el propio paquete declara, dentro de su huella, que su asiento está pendiente. Si el volumen se perdiera antes de que vuelva la base, el asiento se perdería con él; ese es el único escenario en que el aplazamiento se convierte en pérdida, y exige perder a la vez la base y el volumen de ficheros.

**Ventana residual de un asiento sin paquete.** Si el proceso muere entre guardar el pendiente y renombrar el temporal, el asiento se escribirá al volver la base y el paquete se quedará con su nombre temporal (`kronoqr-diagnostics-staging-*.json`), entero, sin que nadie lo haya recibido. El asiento dice entonces que se generó un paquete que existe en el disco pero no se entregó: no afirma una salida que no ocurrió, porque generar no es enviar. La purga por antigüedad barre el temporal como a cualquier paquete.

**Lo que no cambia.** Con la base arriba, el asiento sigue siendo condición previa de la entrega. Ningún dato personal, ningún secreto y ningún log en bruto (ni de PostgreSQL ni de nginx) entra en el paquete por este camino: las secciones que dependen de la base dicen `{"status": "unavailable", "reason": "<clase>"}` con la clase de la excepción, nunca su mensaje.
