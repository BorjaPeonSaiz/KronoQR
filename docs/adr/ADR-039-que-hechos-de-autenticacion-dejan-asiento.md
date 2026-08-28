# ADR-039 — Qué hechos de autenticación dejan asiento en `audit_log`

| Campo | Valor |
|---|---|
| **Estado** | Aceptada |
| **Fecha** | 28 de agosto de 2026 |
| **Decide** | `arquitecto-dominio` |
| **Afecta a** | `Shared/Application/Port/AuthenticationJournal` y sus cuatro llamantes · `Compliance/Infrastructure/Adapter/AuditedAuthenticationJournal` · `Compliance/Infrastructure/Audit/DeferredAuditEntry` · `Compliance/Domain/ValueObject/AuditAction` · `Shared/Domain/ValueObject/AuthChannel` |
| **Requisitos** | RS-03, RS-05, RS-07, RS-12, RL-11, RNF-P-02, OWASP A09, `T1110.001` |

## Contexto

Hasta esta rama, `AuditAction` no tenía **ni un solo caso de autenticación**. Se podía probar
contraseñas contra `/auth/login` toda la noche, o PIN contra `/me/login`, y a la mañana siguiente no
había ninguna consulta que respondiera «¿pasó algo?». Es el hueco de OWASP A09 que `docs/07` §5
registra y que RS-13 enuncia (doc 01 §8, incorporado el 29 de agosto de 2026 con esta decisión ya
implementada y probada).

Cerrarlo con «que todo lo de autenticación se audite» no vale, y no por gusto:

1. **La cadena de ADR-010 tiene un candado global.** Cada asiento toma `pg_advisory_xact_lock` sobre
   un único par de enteros —tiene que ser uno, porque la cadena es *una* secuencia y cada eslabón
   lleva el hash del anterior (ADR-037 lo repite al descartar los candados por dataset)—, y ese mismo
   candado es por el que pasa **cada fichaje**. Un ataque de fuerza bruta es exactamente el tráfico
   que más fallos produce: auditar cada intento convertiría una intrusión en curso en una degradación
   del registro horario, que es lo único que nunca puede ceder (RNF-P-02, regla dura 19).
2. **`audit_log.actor_type` no tiene tipo para un empleado.** Una restricción de la tabla lo acota a
   `user`, `device`, `system` y `maintenance`. Un asiento de «esta persona abrió su portal» saldría
   atribuido a `system`: una entrada que miente en la tabla que se enseña en una inspección. ADR-037
   ya dejó escrito que ampliar el catálogo es un cambio de dominio y de esquema.
3. **Los rechazos son genéricos y de tiempo constante** (RS-03, regla dura 17). Cualquier rastro que
   se escriba en el camino del rechazo tiene que costar lo mismo exista o no exista el sujeto, o el
   rastro se convierte en el oráculo que la respuesta evita.

La decisión estaba tomada de hecho —el fallo no entra en `audit_log`— pero **repetida en diez
docblocks y en ningún ADR**. Una decisión con esa forma se «mejora» el día que alguien encuentra
raro que falte el asiento del fallo.

## Decisión

**Cuatro hechos, y la frontera entre los dos almacenes es la decisión:**

| Hecho | `audit_log` | Log técnico + `kronoqr_auth_attempts_total` | Por qué |
|---|---|---|---|
| **Éxito** (`auth.login_succeeded`) | **Sí, solo `management`** | Sí, en los tres canales (`info`, `outcome=success`) | Bajo volumen y relevancia legal: es la emisión de una credencial de acceso. En el portal y en el quiosco no cabe: no hay `actor_type` de empleado (§2 del contexto). En el quiosco, además, el `shift_entry.created` del fichaje que viene detrás ya deja constancia del mismo empleado y el mismo instante |
| **Cierre** (`auth.logout`) | **Sí, solo `management`** | No | Es la revocación de esa misma credencial (RS-05). El canal lo decide `AuthChannel::sessionEventsAreAudited()`, no el caso de uso |
| **Bloqueo abierto** (`auth.lockout_started`) | **Sí, en los tres canales**, y **después de responder** | Sí (`warning`, `outcome=lockout`) | Lo decide el servidor: el actor es `system` —o el `device` del quiosco— y ahí no miente. Es uno por bloqueo, no uno por intento |
| **Fallo** (`auth.login_failed`) | **Nunca** | Sí (`warning`, `outcome=failure`) | Es el hecho de volumen alto y el que un atacante controla. Su sitio son los 90 días del log operativo, no los cuatro años de la cadena (RL-11) |

### El asiento del bloqueo se escribe fuera del camino de la respuesta

El bloqueo es el único hecho auditable que **provoca quien ataca**. Escribirlo en línea metía en el
camino de un rechazo —incluido el `/scan/pin` del quiosco— una transacción y el candado global de
ADR-010: dos consecuencias, las dos inaceptables.

- **Un oráculo de tiempo.** Solo el flanco pagaba la transacción, así que el intento que abre el
  bloqueo tardaba decenas de milisegundos más que los demás. Medible desde fuera (RS-03).
- **Un rechazo convertido en `500`.** Si `audit_log` fallaba, la excepción subía y `/scan/pin`
  devolvía un error de servidor: la regla dura 19 rota por un problema de auditoría.

**El asiento se prepara en línea y se escribe al terminar la petición** (`app()->terminating()`,
`Compliance/Infrastructure/Audit/DeferredAuditEntry`). El actor, la IP, el `User-Agent` y el instante
se resuelven **antes** de responder —después ya no existen— y solo la escritura se aplaza. Si esa
escritura falla, se registra un `audit.deferred_entry_failed` de nivel `error` y nada más: el rechazo
ya se envió y no puede volverse un `500`.

**Después de responder y no en cola**, aunque haya Horizon: una cola introduce un trabajador del que
depende que el asiento llegue a existir, y un asiento que se pierde porque nadie levantó el *worker*
es peor que uno escrito 5 ms tarde. El aplazamiento cabe entero detrás de `DeferredAuditEntry`: el día
que haga falta encolarlo, no cambia ningún llamante.

**El éxito y el cierre del panel siguen siendo síncronos y dentro de la transacción de quien llama.**
Ahí la garantía de la regla dura 6 sí se quiere: si el asiento no se puede escribir, no hay sesión.
No es tráfico que un atacante amplifique.

### El origen va en la columna `ip`, en claro, como en los otros cinco escritores

`audit_log` tiene sus columnas `ip` y `user_agent` y **un solo criterio para toda la tabla**: quien
audita las rellena desde `CurrentAuditContext`. Los asientos `auth.*` hacen lo mismo. Un seudónimo en
el payload de tres acciones, y la dirección en claro en las otras veinte, obligaría a quien investiga
—y a quien exporta— a saber de qué acción viene cada fila para saber qué significa su origen.

**`ip_hash` se queda solo en el log técnico**, y ahí sí es imprescindible: ese log viaja al fabricante
dentro del paquete de diagnóstico (ADR-020), que va anonimizado por defecto, y una IP de la red
interna de un hotel junto a la hora dice desde qué puesto se trabajó. Sirve además como clave de
correlación —«¿los 400 fallos y el acceso correcto de después vienen del mismo sitio?»—, que es la
pregunta que A09 obliga a poder contestar. `docs/runbooks/ataque-a-credenciales.md` §4.3 explica cómo
recalcularlo con `APP_KEY`.

**Consecuencia que hay que respetar en la tarea 5.9: el paquete de diagnóstico no puede incluir jamás
`APP_KEY`** —ni el `.env`, ni un volcado de configuración que la contenga—. Con la clave dentro, el
seudónimo deja de serlo: el espacio IPv4 son 2^32 valores y se invierte por fuerza bruta en minutos.

### Un solo motivo de fallo donde la respuesta es una sola

`AuthFailureReason::LOCKED` se emite **únicamente donde la respuesta ya distingue el bloqueo**: el
panel, que devuelve `429` con `Retry-After`. En el PIN —portal y quiosco— los cinco rechazos son el
mismo `401`/`422` y el apunte es `invalid_credentials` en todos, bloqueo incluido. Que el log separase
«existe y está bloqueado» de «no existe» reconstruiría dentro del servidor —y dentro del paquete de
diagnóstico— justo el oráculo que RS-03 evita hacia fuera. El bloqueo se ve donde tiene que verse: en
el asiento `auth.lockout_started`, que lleva el `employee_uuid` y los segundos.

## Alternativas descartadas

| Alternativa | Por qué se descarta |
|---|---|
| **Auditar también cada fallo** | Mete el tráfico que un atacante controla dentro del candado global de ADR-010, por el que pasa cada fichaje. Convierte un intento de intrusión en degradación del registro horario y llena cuatro años de retención con ruido de minutos |
| **Auditar el éxito del portal y del quiosco** | Obliga a atribuirlo a `system`: una entrada falsa en la tabla que se enseña en una inspección. El catálogo de actores se amplía con un cambio de dominio y de esquema, no con un `if` (ADR-037) |
| **Escribir el asiento del bloqueo en línea, dentro de la respuesta** | Es el defecto que este ADR corrige: oráculo de tiempo en el flanco (RS-03) y un fallo de auditoría convertido en `500` en el camino de fichaje (regla dura 19) |
| **Encolarlo con Horizon en vez de aplazarlo a *after response*** | Hace que la existencia del asiento dependa de que haya un *worker* vivo. Un asiento perdido no se recupera; 5 ms de retraso no le importan a nadie. La palanca queda detrás de `DeferredAuditEntry` por si algún día las cifras la piden |
| **Guardar el origen como `ip_hash` en el payload de los asientos `auth.*`** | Dos representaciones del mismo dato en la misma tabla. Quien investiga tendría que saber de qué acción viene cada fila para saber si su origen es legible; quien exporta, lo mismo. El seudónimo tiene un sitio —el log que viaja al fabricante— y en `audit_log` no aporta: esa tabla no sale de la instalación |
| **Una etiqueta `reason` en `kronoqr_auth_attempts_total`** | En un panel, separar «no existe» de «no coincide» reconstruye el oráculo de RS-03 desde fuera, y multiplica la cardinalidad de nueve series a treinta y seis |
| **Un contador y un log por canal, cada uno en su módulo** | Tres implementaciones del mismo hecho con tres criterios: el día que se añada una cuarta puerta, la alerta agrupa por un vocabulario y el contador cuenta por otro. Por eso el puerto vive en `Shared` y lo implementa `Compliance` |

## Consecuencias

- `AuditAction` gana tres casos —`auth.login_succeeded`, `auth.logout`, `auth.lockout_started`— dentro
  de la familia `CredentialLifecycle` del bloque D: la sesión es otro soporte de la misma potestad que
  la tarjeta y el PIN. No abre familia nueva.
- El reparto entre almacenes lo decide **el adaptador**, no quien llama. Ningún caso de uso puede
  elegir auditar un fallo aunque le parezca más completo.
- `auth.lockout_started` aparece en `audit_log` unos milisegundos **después** de la respuesta que lo
  provocó. Una prueba que lo asierte tiene que pasar por HTTP —donde el kernel de pruebas llama a
  `terminate()`—; una que llame al verificador en línea no verá el asiento, y eso es correcto.
- Un `audit_log` averiado **no** deja al quiosco sin fichar ni al portal sin abrir. Se pierde el
  asiento del bloqueo y queda su `warning` en el log técnico más un `error` de escritura fallida. Es
  el único punto del producto donde la regla dura 6 cede, y cede a propósito: el hecho que se pierde
  lo provoca quien ataca, y conservarlo a costa de un `500` en el camino de fichaje sería exactamente
  el intercambio que la regla dura 19 prohíbe.
- El camino del PIN paga el mismo trabajo exista o no exista el código de empleado: una consulta, una
  comparación `bcrypt` contra el hash real o contra el señuelo, y la **misma secuencia** de llamadas a
  `PinAttempts` contra el sujeto real o contra un UUID señuelo. Cuesta una entrada de caché
  compartida por todos los códigos inexistentes, acotada y con TTL.
- Tarea 5.9 (paquete de diagnóstico): `APP_KEY` no entra, ni directamente ni por un volcado de
  configuración. Con ella dentro, `ip_hash` deja de ser un seudónimo.

## Verificación

- `tests/Feature/Identity/AuthenticationTrailTest.php`: el acceso y el cierre del panel dejan asiento
  con la cuenta y sin su correo; el fallo deja **cero** filas en `audit_log`; el bloqueo deja uno solo
  y con actor `system`; la cadena de hash sigue verificable con los asientos dentro; el origen va en
  la columna `ip` y el payload no lleva `ip_hash`.
- `tests/Feature/Identity/PortalAuthenticationTrailTest.php` y
  `tests/Feature/Attendance/PinScanAuthenticationTrailTest.php`: entrar por el portal y fichar por PIN
  no dejan asiento propio; el bloqueo sí, con `system` y con `device#id` respectivamente.
- `tests/Integration/Workforce/PinRejectionSymmetryTest.php`: el código inexistente y el PIN
  equivocado ejecutan la **misma secuencia** de llamadas a `PinAttempts` y el mismo número de
  consultas, y el contexto de sus dos apuntes de log es idéntico byte a byte.
- `tests/Feature/Identity/PortalAuthenticationTrailTest.php`, último caso: con un `AuditTrail` que
  lanza, los tres rechazos siguen siendo `401`, `audit_log` queda vacío y aparece **un** `error`
  `audit.deferred_entry_failed` con la acción y la clase de la excepción, sin su mensaje.
- `tests/Support/Http/Api.php` llama a `$kernel->terminate()`: sin eso, el cliente de pruebas dejaba
  sin ejecutar todo lo que ocurre después de responder y una prueba de asiento pasaría en verde con la
  escritura sin correr.
- Sabotaje: volver a escribir el asiento del bloqueo en línea tiene que hacer fallar la prueba de
  `500`; añadir el asiento del fallo tiene que hacer fallar las tres pruebas de «cero filas».
