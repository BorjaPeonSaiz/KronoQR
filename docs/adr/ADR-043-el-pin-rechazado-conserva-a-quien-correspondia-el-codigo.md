# ADR-043 — El PIN rechazado conserva a quién correspondía el código, solo en la base de datos

| Campo | Valor |
|---|---|
| **Estado** | Aceptada. **Enmendada el 29-10-2026** (D15 del propietario, bloque 25 de la 2.3.0): el PIN correcto de una persona de baja con fecha hasta el cese se atribuye y abre `scan_before_revocation`, sin cambio de respuesta (ver la enmienda al final; la implementa la tarea 25.5) |
| **Fecha** | 30 de septiembre de 2026 |
| **Decide** | `arquitecto-dominio` (Bloque 5 de la 2.2.0, hallazgos PIN-06 y SC7-02). Revisión pendiente de `seguridad-cumplimiento` |
| **Afecta a** | Precisa [ADR-039](ADR-039-que-hechos-de-autenticacion-dejan-asiento.md) (§«Un solo motivo de fallo donde la respuesta es una sola») · Complementa [ADR-038](ADR-038-limite-de-tasa-por-dispositivo-y-por-ip-no-por-credencial.md) y [ADR-020](ADR-020-soporte-con-paquete-de-diagnostico.md) · `Shared\Domain\ValueObject\PinVerification`, `CredentialResolution` · `scan_events` · Especificación `docs/verificacion/2.2.0-bloque5-especificacion-pin-incidencias.md` |
| **Requisitos** | RN-19 (nueva), RF-AT-11, RS-03, RS-12, regla dura 17, 19 y 21 |

## Contexto

Hasta la 2.1.0 el rechazo de un fichaje por PIN era indistinguible **también dentro del servidor**:
`PinVerification::rejected()` no llevaba a nadie, `scan_events` guardaba `employee_id` nulo y
`rejected_unknown`, y el log técnico no separaba los rechazos (ADR-039). Era la forma barata de
garantizar RS-03: el servidor no tenía el dato a mano en el camino de la respuesta.

La verificación de la 2.1.0 encontró el precio (PIN-06, SC7-02). Un PIN encolado sin red que se
rechaza al sincronizar, o un acierto que llega detrás de tres fallos y encuentra el bloqueo de RS-12,
dejan a una persona real creyendo que ha fichado y **una jornada sin registro que nadie ve**: la
regla dura 19 rota sin rastro. Para que alguien lo revise (RN-19) el servidor tiene que saber, después
del hecho, a quién correspondía el código.

## Decisión

**El servidor conserva a quién correspondía el código tecleado en un PIN rechazado, y lo conserva
solo en la base de datos, en una columna que no es `employee_id`.**

1. `PinVerification` lleva, en los rechazos y en el bloqueo, un `PinClaim` opcional —el `employee_uuid`
   del dueño del código y si el intento abrió o encontró el bloqueo— **solo cuando el código
   corresponde a una persona que puede fichar** (RN-14). `employeeUuid()` sigue devolviendo `null`
   en todo rechazo: el tipo no permite confundir «a quién correspondía» con «quién se autenticó».
2. `CredentialResolution` transporta ese `PinClaim` del caso de uso del PIN a la inserción de
   `scan_events`, y **ahí termina**: no llega a `RegisterScanResult`, ni al evento `ScanRejected`, ni
   al reenvío por `scan_id`, ni al log técnico, ni al paquete de diagnóstico.
3. `scan_events` gana `claimed_employee_id` y `pin_lockout`, atados por un `CHECK` a la única
   situación en la que pueden existir: `origin = 'pin_kiosk'`, `result = 'rejected_unknown'`,
   `employee_id` nulo.
4. La inserción tiene **la misma forma** con y sin dueño (el identificador se resuelve con una
   subconsulta sobre un parámetro que puede ser nulo), para que la presencia del dato no cambie el
   trabajo que se hace en el camino de la respuesta.
5. La incidencia `rejected_pin_scan` la abre **la revisión diaria**, no la petición.

Lo que ADR-039 sigue garantizando sin cambio: la respuesta es una sola, el apunte del log técnico es
`invalid_credentials` en los cinco rechazos y el fallo nunca entra en `audit_log` como tal. Lo que
este ADR precisa es que **«el servidor tampoco tiene el dato a mano» deja de ser cierto para la base
de datos**; sigue siéndolo para todo lo que sale de ella hacia el log, el paquete o el cliente.

## Alternativas descartadas

| Alternativa | Por qué se descarta |
|---|---|
| **Escribir `employee_id` en la fila del rechazo** | `employee_id` significa «este escaneo se atribuyó a esta persona» (lo usan RN-14, RN-18, el portal y los informes). Un PIN equivocado no atribuye nada: cualquier consulta «escaneos de X» incluiría los intentos de un tercero con su código |
| **Un resultado nuevo (`rejected_pin`, `rejected_locked`)** | `result` es lo que reconstruye la respuesta del reenvío (regla dura 8) y lo que ven RN-16 y los informes; separarlo ahí es separar hacia fuera |
| **Que el quiosco declare «esto viene de la cola»** | Campo controlado por el cliente, cambio de contrato y de la PWA, y no cubre la respuesta perdida por el camino; la pregunta que importa —¿acabó fichando?— la responde el servidor sin ayuda |
| **Abrir la incidencia en la propia petición, o desde `lockoutStarted`** | Mete trabajo condicionado a que el código exista en el camino del rechazo (el oráculo de tiempo que ADR-039 sacó de ahí) y un fallo de la bandeja podría convertir un rechazo en `500` |
| **No abrir nada y confiar en la alerta de fuerza bruta** | La alerta ve volumen, no personas: un único PIN encolado que se rechaza no la dispara nunca, y es el caso que deja la jornada sin registro |

## Consecuencias

- `scan_events` pasa a contener un dato personal más —«alguien tecleó el código de X»—, con la misma
  retención que la fila. Entra en la exportación íntegra del cliente (RF-PD-14) y **no** en el
  paquete de diagnóstico (ADR-020); una prueba lo fija.
- Quien tenga acceso de lectura a `scan_events` puede saber qué códigos rechazados existían. Son los
  roles de gestión y el rol de base de datos de la aplicación, que ya conocen la plantilla; el
  quiosco, que es la superficie de RS-03, no tiene ningún camino hasta esa columna.
- El `docblock` de `PinVerification` («el servidor tampoco tenga el dato a mano») y el de
  `HashedEmployeePinVerifier` («no hay ninguna rama que los distinga hacia arriba») se reescriben
  con este ADR como referencia.

## Verificación

- Unitaria: `PinVerification` y `CredentialResolution` con `PinClaim` devuelven `employeeUuid() === null`.
- Feature: un PIN erróneo con código existente y uno con código inexistente producen respuestas
  idénticas byte a byte, también en el reenvío; solo la fila del primero tiene `claimed_employee_id`.
- Integración: el `CHECK scan_events_chk_pin_claim` rechaza la columna fuera de su situación.
- Arquitectura: `PersonalDataCollector` no selecciona `claimed_employee_id` ni `pin_lockout`.

## Enmienda del 29-10-2026: fichaje por PIN después de la baja (D15)

Decisión D15 del propietario (18-10-2026), sobre la propuesta de `seguridad-cumplimiento` del bloque 18
de la 2.2.0. La implementa la tarea 25.5 de la 2.3.0, y la relación con los periodos de alta está en
[ADR-062](ADR-062-la-baja-se-programa-y-la-readmision-abre-un-periodo-de-alta-en-la-misma-ficha.md) §8.

**El problema.** El punto 1 limita `PinClaim` a quien puede fichar. Un PIN tecleado en un quiosco sin
red el último día, que llega después de que RRHH registre la baja, se rechaza sin persona y sin
incidencia. Es el mismo hecho que RN-20 cubre para la tarjeta, una jornada que nadie ve, y para el PIN
seguía abierto (doc 07 §6).

**Lo que cambia.** Un PIN **correcto** de una persona **de baja** cuya fecha civil del `occurred_at`,
en la zona del centro, cae entre la fecha de alta y la de cese del periodo que cerró la baja (las dos
incluidas):

1. **Se atribuye**: `scan_events.employee_id` es la persona y `flagged_for_review` queda marcado, como
   la tarjeta auténtica de RN-20. **No es un `PinClaim`**: el PIN acertó, así que sí identifica, y
   `claimed_employee_id` sigue nulo. El `CHECK scan_events_chk_pin_claim` no cambia.
2. **`result` sigue siendo `rejected_unknown`.** La respuesta, el cuerpo del reenvío y el apunte del
   log técnico (`invalid_credentials`) no cambian. El tiempo tampoco: el verificador ya compara el
   hash real de una persona de baja (la baja se evalúa después de la comparación, `isRejected()`),
   la fecha de alta y la de cese salen de la misma fila que ya lee y la inserción tiene la misma forma
   con y sin persona (punto 4).
3. **La incidencia** es `scan_before_revocation`, la de RN-20, una por persona y jornada. Para el PIN,
   «la retirada» es la baja. La abre la revisión diaria (punto 5), que lee las filas marcadas por
   `recorded_at` sin mirar el origen. Un mismo día con tarjeta y con PIN da una sola incidencia.
4. **Fuera de esas fechas** (posterior al cese, anterior al alta o en el hueco de una readmisión), un
   PIN correcto de una persona de baja sigue como hasta ahora: sin persona y sin incidencia. Es la
   misma razón por la que RN-20 no abre nada después de la retirada. Una fecha que la tablet pone
   donde quiere no puede sembrar incidencias.
5. **Un PIN incorrecto** de una persona de baja sigue sin `PinClaim`. Solo el acierto atribuye.
6. **Nada de lo que hace el acierto** en una persona que puede fichar ocurre aquí: ni se borra el
   contador de intentos ni hay *rehash*. La persona de baja no se autentica, se identifica para la
   revisión.

**Validación jurídica.** Atribuir un fichaje rechazado a una persona de baja trata un dato suyo
posterior a la relación laboral. Lo tienen que validar la asesoría y el DPO del cliente, y está en la
lista de validación jurídica del plan de la 2.3.0.

**Verificación añadida.**

- Feature: el PIN correcto de una persona de baja dentro y fuera de las fechas, y el PIN incorrecto,
  producen respuestas idénticas byte a byte, también al reenviarlas. Solo la primera fila tiene
  `employee_id` y `flagged_for_review`.
- `ConstantTimeRejectionTest` cubre el caso nuevo.
- La revisión diaria abre una sola `scan_before_revocation` para un día con tarjeta y PIN.
