# ADR-043 — El PIN rechazado conserva a quién correspondía el código, solo en la base de datos

| Campo | Valor |
|---|---|
| **Estado** | Aceptada |
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
