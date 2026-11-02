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
2. **El alcance es una lista cerrada de tres** (`Product\Domain\ValueObject\SupportScope`), que se traduce en las *abilities* del token: `diagnostics` (`diagnostics:*`); `read_only` (`diagnostics:*`, `attendance:read` y `employees:read`; hasta la 2.3.0 llevaba también `audit:read`, ver la enmienda del 01-11-2026); y `configuration` (`diagnostics:*` y `settings:*`). Ningún alcance lleva `license:*`, `support:*`, `employees:*`, `credentials:*`, `attendance:correct`, `reports:legal` ni `accounts:*`, y las policies cierran además lo que el ámbito permitiría: el perfil de cumplimiento, los umbrales reservados al cliente y la generación del paquete con datos personales. `SupportScopeRoutesTest` lo comprueba recorriendo las rutas registradas.
3. **El uso deja un asiento por ventana, no uno por petición.** `support_grant.used` se escribe como mucho una vez cada `PRODUCT_SUPPORT_USE_AUDIT_WINDOW_SECONDS` (900 s de serie) por concesión, porque cada asiento pasa por el candado global de [ADR-010](ADR-010-auditoria-solo-append-encadenada.md), el mismo que cada fichaje. `support_grants.accessed_at` sí se actualiza en cada petición. Una sesión de una hora deja cuatro asientos, suficientes para reconstruir cuándo estuvo dentro. Con `0` hay un asiento por petición, y solo tiene sentido mientras se investiga un incidente. Lo que el soporte **cambia** sí deja un asiento por cambio, con `actor_type = support_grant`.
4. **El paquete es un único JSON sin cifrar, también cuando lleva datos personales** (`Product\Domain\ValueObject\DiagnosticsBundle`). No se cifra a propósito: el cliente tiene que poder inspeccionarlo antes de enviarlo, y el canal de envío es el de su contrato de soporte, fuera del producto. La integridad la da la huella del manifiesto. La sección `personal_data` solo la pide un `admin` (un token de soporte recibe `403`), deja su propio asiento (`diagnostics.personal_data_included`), pone `manifest.anonymized` a `false` y está acotada a `PRODUCT_DIAGNOSTICS_PERSONAL_DATA_MAX_PERIOD_DAYS` (31 días), 2000 fichas y 5000 filas por colección. `national_id_hash`, `email`, `pin_hash`, `photo_path` y `client_meta` no salen nunca. El fichero se borra a los `PRODUCT_DIAGNOSTICS_RETENTION_DAYS` (7 días de serie) o al generar el siguiente. Vive en el volumen de ficheros generados ([ADR-045](ADR-045-los-ficheros-generados-viven-en-un-volumen-compartido.md)).

**Riesgo residual que esta enmienda hace explícito:** mientras está en el disco y durante su envío, un paquete con `personal_data` es una copia legible de la plantilla y de un mes de fichajes. Lo protegen los permisos del volumen, la caducidad de 7 días y el canal que elija el cliente, no el producto. Cifrarlo para el fabricante, con una clave pública del fabricante en el paquete de entrega, es la evolución posible, pero obliga a decidir antes si el cliente renuncia a inspeccionarlo. Lo valora `seguridad-cumplimiento` en doc 07 §6. Mientras tanto, la guía del cliente tiene que decir que ese fichero se envía por un canal cifrado y se borra al enviarlo. **Cerrado por la enmienda del 01-11-2026 (tarea 33.8): desde la 2.3.0 ese paquete sale cifrado para el fabricante; el punto 4 vale solo para el anonimizado.**

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

## Nota del 21-10-2026 (2.3.0, bloque 11): el motivo de la concesión tiene plazo en la tabla, y la copia de auditoría se queda

[ADR-060](ADR-060-al-vencer-su-plazo-la-ficha-y-la-cuenta-de-gestion-se-minimizan-en-sitio-con-asiento.md) §6 da plazo al motivo de la concesión **en la tabla `support_grants`**. Es texto libre, y a veces nombra a una persona («revisar el fichaje de …»).

- **Cuándo se vacía.** `reason` pasa a `NULL` y `reason_purged_at` toma el instante cuando han pasado `PRODUCT_SUPPORT_GRANT_REASON_RETENTION_DAYS` desde `COALESCE(revoked_at, expires_at)`. Vale 90 de serie, con un mínimo de 30.
- **Cómo.** Lo hace una tarea diaria, sin asiento. Dos `CHECK` atan las dos columnas e impiden vaciar el motivo antes de que la concesión termine.
- **Lo que no cambia: el asiento `support_grant.granted` conserva su copia del motivo**, que es la prueba de por qué se concedió. Esa copia vive lo que vive la auditoría (4 años, ADR-027) y no se toca. Lo que se acorta es la copia operativa, la que enseña el panel. Por eso no hace falta asiento al vaciarla: no se pierde ningún hecho.
- **Para que el motivo no lleve datos personales desde el principio**, el formulario y `support:grant` avisan de que se escriba el uuid y no el nombre, y un motivo con forma de correo, DNI/NIE o teléfono se rechaza con `422`.
- **Contrato.** `SupportGrant.reason` pasa a admitir nulos y aparece `reason_purged_at`.

## Nota del 22-10-2026 (2.3.0, bloque 14): la exportación legal en diferido no es para el soporte

Decisión del propietario: una concesión de soporte **no** puede pedir exportaciones en diferido
(`POST /reports/exports`, ninguna clase, tampoco `legal`). Tampoco llega a la descarga síncrona: ningún
alcance de soporte concede `reports:legal` ni `reports:*` (`SupportScope`). Ver la enmienda
2.3.0 de ADR-041; lo aplica `ReportExportPolicy`.

## Enmienda 01-11-2026 (2.3.0, tarea 33.3, aprobada por el propietario): `read_only` no lleva `audit:read`

`read_only` no lleva `audit:read`; conceder a un acceso de soporte la lectura de `audit_log` exige ADR propio.

Es la misma línea de minimización de este ADR. El ámbito estaba en el alcance porque la tabla del doc 02 §7.3 lo contemplaba, sin ninguna ruta que lo consumiera: la traza de correcciones —quién cambió qué hora, cuándo y por qué— es el conjunto más sensible del producto, y que el token del fabricante llegara a ella por arrastre de una línea escrita antes de que existiera el visor no sería una decisión. `read_only` queda en `diagnostics:*`, `attendance:read` y `employees:read`, y `SupportScopeTest` falla si algún alcance concede `audit:read`. Los tokens emitidos antes de la actualización lo conservan hasta caducar (72 h como máximo), sin ninguna ruta que lo lea; no hay migración.

## Enmienda 01-11-2026 (2.3.0, tarea 33.8, diseño aprobado por `seguridad-cumplimiento`): el paquete con datos personales sale cifrado para el fabricante, y el anonimizado no lleva rutas del servidor ni el nombre del hotel

**Motivo.** Dos filas del [doc 07](../07-seguridad-madurez-y-amenazas.md) §6 aceptadas hasta la 2.2.1: «el paquete con datos personales es un JSON sin cifrar, también al salir hacia el fabricante» (el riesgo residual de la enmienda del 08-10-2026) y «el paquete anonimizado lleva rutas del servidor del cliente». La primera dejaba la protección de la plantilla y de un mes de fichajes en manos del canal que eligiera el hotel. La segunda dejaba salir `BACKUP_PATH=/srv/<hotel>/…`, el directorio de la instalación y el nombre público de `APP_URL`, que dicen a quien recibe el paquete quién es el cliente y cómo está montado su servidor, sin aportar a soporte nada que no diga una etiqueta. **La decisión de este ADR no cambia**: lo que cambia es la forma en que sale el paquete. El punto 4 de la enmienda del 08-10-2026 queda en pie solo para el anonimizado.

### 1. El paquete con datos personales sale en un sobre híbrido

- **Cifrado.** El paquete en claro —el mismo texto, byte a byte, que se escribiría sin cifrar— se cifra con XChaCha20-Poly1305-IETF bajo una clave aleatoria de un solo uso. Esa clave va en un sobre sellado X25519 (`crypto_box_seal`) para la clave pública **de soporte** del fabricante y se borra de memoria (`Product\Infrastructure\Diagnostics\SodiumDiagnosticsBundleSealer`).
- **La cabecera va en claro y atada al contenido.** Formato `kronoqr-diagnostics-sealed`, versión del sobre `1`, algoritmo, identificador de la clave, el `manifest` del paquete y `contents`: qué secciones lleva y, de `personal_data`, cuántas filas y qué columnas tiene cada colección, nunca un valor. Su forma canónica son los **datos asociados** del cifrado: cambiar `anonymized`, una sección o un recuento hace que el sobre no abra. Con un único `crypto_box_seal` del texto entero la cabecera no estaría atada a nada (`Product\Domain\ValueObject\SealedDiagnosticsBundle`).
- **Cómo sale.** Por HTTP, `200` con `application/vnd.kronoqr.diagnostics-sealed+json` (contrato `SealedDiagnosticsBundle`); por consola, `kronoqr-diagnostics-<versión>-<UTC>.sealed.json`, `0600`. El anonimizado sigue siendo `application/json` y `.json`. `DiagnosticsBundle::toJson()` y el escritor **se niegan** a entregar en claro un paquete con `anonymized: false`: solo el sellador pide su texto (`DiagnosticsSealingTest`).
- **Tope de tamaño.** `PRODUCT_DIAGNOSTICS_MAX_BYTES` vale para el fichero que sale. El recorte se hace contra unos tres cuartos del tope, por el base64, y si el sobre aún no cabe se vuelve a recortar sin volver a recolectar. Las seis secciones fijas no se sacrifican nunca.
- **Los asientos lo dicen.** `diagnostics.bundle_generated` y `diagnostics.personal_data_included` añaden `sealed`, `recipient_key_id` y `sealed_sha256`, la huella de los bytes entregados; `sha256` sigue siendo la del texto en claro, la del `manifest`. El anonimizado lleva `sealed: false` y nada más del sobre. Este paquete nunca se aplaza: sin base no se genera (enmienda del 14-10-2026).

### 2. Un par propio de soporte, compilado con cada versión

- **No es el par de licencias.** Con la privada de soporte se abren paquetes; con la de licencias se emiten licencias. Separarlas evita que quien atiende el soporte pueda emitir licencias, deja que roten con ciclos distintos y hace que el compromiso de una no arrastre a la otra.
- **La pública va compilada** en `backend/config/product.php`, como valor por defecto de `env('DIAGNOSTICS_RECIPIENT_PUBLIC_KEY', …)`: es la misma en todas las instalaciones de una versión y el cliente no la configura. La variable existe para dos usos y solo dos: las pruebas, que generan su propio par en cada ejecución, y una rotación de urgencia sin esperar a la versión siguiente. **Sin prefijo `PRODUCT_`** a propósito: los `PRODUCT_*` viajan en `configuration.env` por la regla de prefijos de `DiagnosticsConfigurationAllowlist`.
- **`make release-gate` falla** si la pública no está puesta: una imagen sin ella no generaría el paquete con datos personales en ninguna instalación de esa versión. El par lo genera el propietario con `tools/diagnostics-opener/generate-keypair.php`; **ninguna clave real entra en el repositorio**.
- **La privada vive en el llavero de soporte**, un fichero `<key_id>.key` por clave. `tools/diagnostics-opener/open.php` elige la que pide el sobre por su `recipient_key_id` (los 16 primeros hexadecimales del SHA-256 de la pública), comprueba la huella y que la cabecera describe el contenido, y escribe el paquete en claro `0600` sin sobrescribir nada. El abridor no entra en ninguna imagen (`.dockerignore` excluye `tools/`) y nada en el producto abre un sobre.
- **Rotar** es generar un par nuevo, publicar una versión con la pública nueva y conservar la privada anterior en el llavero mientras queden paquetes cifrados con ella por abrir.

### 3. Sin clave válida, el paquete con datos personales no se genera

Una clave vacía, mal formada o de ceros es una clave que no hay. Se comprueba **antes de recoger nada**: no se recolecta, no queda ningún asiento y nunca sale en claro.

- **Por HTTP**, `503` con `type` `urn:kronoqr:problem:diagnostics-sealing-unavailable` y **sin `Retry-After`**, porque esperar no lo arregla (respuesta `DiagnosticsBundleUnavailable`). El `detail` remite al anonimizado y no lleva ningún valor de la clave.
- **Por consola**, código `1`, antes de preguntar quién firma, con un mensaje que remite al anonimizado y a `product:doctor`.
- **`product:doctor`** lo dice en `diagnostics.recipient_key`: `ok` con el identificador de la clave, o **aviso, nunca fallo**. Fichar, el registro legal y el anonimizado no dependen de esta clave, y un fallo abortaría una actualización por algo que no impide trabajar.
- El anonimizado no depende de la clave en ningún caso.

### 4. El cliente renuncia a leer el contenido, y a cambio tiene la cabecera y el asiento

La enmienda del 08-10-2026 dejaba el paquete sin cifrar para que el cliente pudiera inspeccionarlo. Con el sobre, el cliente ya no puede abrirlo: la instalación no tiene la privada. Lo compensan tres cosas:

- la cabecera en claro, que dice qué secciones, cuántas filas y qué columnas salen;
- los dos asientos con las dos huellas, que permiten comprobar que el fichero que se envía es el que se generó;
- que esos datos los ve ya en su panel.

`product:diagnostics --verify` sobre un `.sealed.json` enseña la clave, las dos huellas y el asiento con la misma huella. Sale con `2` si la cabecera no tiene la forma de un sobre de esta versión o si la huella del fichero no es la del asiento. Dice además que el contenido no se puede comprobar sin la privada del fabricante.

### 5. El anonimizado no lleva rutas del servidor ni el nombre de `APP_URL`

Se compara el valor configurado y su `realpath`, y gana la raíz más larga.

- **Hallazgos de `doctor` dentro del paquete.** Cada clave de `params` y `details` pasa por una **lista cerrada** (`Product\Infrastructure\Diagnostics\BundleDoctorFindingFilter`), antes de redactarse.
  - Las cifras, los estados y los enumerados pasan tal cual.
  - Las ubicaciones salen como la etiqueta de su papel (`<backup_path>`, `<backup_path>/daily`) o como `[path]`.
  - El host sale como `[app_host]`.
  - **Una clave sin clasificar se descarta.** El filtro falla cerrado, y `DiagnosticsSealingTest` falla mientras una sonda emita una clave sin clasificar.
- **Ubicaciones.** `configuration.locations` dice de cada uno de los diez papeles (`Product\Infrastructure\Diagnostics\DiagnosticsLocations`) si es la de serie, si existe y si se puede escribir, **sin el valor**.
- **Informe de actualización.** `updates.location` es `<backup_path>/reports`, y las rutas de sus líneas conservadas salen etiquetadas.
- **`.env`.** `BRANDING_LOGO_ROOT` sale de la lista exacta de `configuration.env`, y lo que entra por prefijo no se emite si su valor lleva una barra.
- **El `product:doctor` local no cambia**: quien administra el servidor necesita la ruta real para arreglar lo que falla.

### Lo que no hace

- **No autentica a quien genera el sobre.** Cualquiera con la clave pública puede fabricar uno. La prueba de que salió de esta instalación es su asiento en `audit_log`, no el sobre.
- **No protege el paquete dentro del proceso que lo genera.** El texto en claro existe en la memoria de `app` mientras se cifra, como existen los datos en la base de la que se leen.

### Riesgo residual

- **Quien tenga la privada de soporte** puede leer todos los paquetes cifrados con ella que un cliente haya enviado. Lo acotan el llavero separado del de licencias, la rotación y la obligación del encargo de borrar los datos al terminar la incidencia (RL-18).
- **Compatibilidad.** Los paquetes con datos personales generados con una versión anterior a la 2.3.0 son JSON en claro y siguen las reglas de su versión. `--verify` los comprueba como siempre.

**Pruebas:** `SealedDiagnosticsBundleTest`, `BundleDoctorFindingFilterTest`, `DiagnosticsResponsibleTest`, `DeliverDiagnosticsBundleTest` (unitarias), `DiagnosticsSealingTest` (arquitectura), `DiagnosticsPathsAndSealingTest` (integración: ida y vuelta con el abridor y un par generado al vuelo, cabecera alterada que no abre, anonimizado sin rutas ni host sobre una instalación sembrada), `DiagnosticsBundleContractTest` (los dos `200` y el `503`), `DiagnosticsConsoleTest` y `DiagnosticsRecipientKeyDoctorCheckTest`.
