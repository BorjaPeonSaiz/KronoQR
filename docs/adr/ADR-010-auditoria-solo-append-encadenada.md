# ADR-010 — Auditoría solo-append encadenada por hash

| Campo | Valor |
|---|---|
| **Estado** | Aceptada |
| **Fecha** | 11 de agosto de 2026 (redactada el 14 de agosto de 2026, tarea 0.6) |
| **Decide** | `seguridad-cumplimiento` con `arquitecto-dominio` |
| **Afecta a** | Tareas 1.3, 2.2 y 2.10 · [ADR-027](ADR-027-audit-log-particionado.md) · **Regla dura 6** de `CLAUDE.md`, y la 5 |
| **Requisitos** | RL-04, RL-11, RS-05, RS-07, RN-13, RF-PA-04 |

> Procede de la primera tabla del [documento 02](../02-stack-tecnologico-y-plan-implementacion.md) §4, que ya fijaba decisión, contexto y consecuencias. Esta redacción los desarrolla y los enlaza con los requisitos y con las reglas duras; no cambia la decisión.

## Contexto

RL-04 exige que el registro sea **fiable e inalterable**: cualquier modificación posterior queda trazada con autor, momento, valor anterior y motivo. RS-07 va un paso más allá y pide que la manipulación sea **detectable**.

La diferencia entre ambos es la que decide este ADR. Un registro de auditoría convencional —una tabla donde la aplicación inserta filas— documenta lo que la aplicación hizo, pero no prueba nada frente a quien tiene acceso a la base de datos: el administrador del sistema del cliente puede editar una hora y editar también la entrada de auditoría que lo delata. Ante una inspección, la afirmación *«confiamos en que nadie lo tocó»* no vale.

Y el escenario no es hipotético: el sistema se instala **en el servidor del cliente**, cuyo IT tiene acceso completo al motor. Quien puede manipular el registro es exactamente quien podría querer hacerlo.

## Decisión

**`audit_log` es solo-append y cada entrada encadena el hash de la anterior.**

```
hash_n = SHA256( prev_hash || occurred_at || actor || action || subject || canonical_json(payload) )
```

La entrada génesis usa `prev_hash = SHA256("FICHAJE-HOTEL-GENESIS")`.

Cuatro condiciones lo sostienen, y las cuatro son parte de la decisión:

1. **El usuario de base de datos de la aplicación tiene `INSERT` y `SELECT`, y nada más, sobre esa tabla y sobre todas sus particiones** (regla dura 6). Sin `UPDATE`, sin `DELETE`, sin DDL. La garantía no es que la aplicación no lo haga: es que **no puede**.
2. **Toda acción con relevancia legal escribe una entrada**: correcciones y anulaciones de jornada (RN-13, RF-PA-04), emisión, entrega y revocación de credencial y de PIN, cambios de configuración que afecten al cálculo de horas, accesos a datos personales de terceros (RS-05), concesiones de soporte y purgas de retención.
3. **La cadena se verifica a diario** con `compliance:verify-audit-chain`, y cualquier rotura dispara **alerta crítica de seguridad** dirigida al responsable de seguridad —no al IT—, con su runbook (`rotura-cadena-auditoria.md`) y menos de 24 h de detección (RS-07).
4. **La purga de retención no rompe la cadena.** Se resuelve con particionado por año y anclas selladas ([ADR-027](ADR-027-audit-log-particionado.md)), ejecutada por un rol de mantenimiento distinto del de la aplicación.

**Refuerzo opcional:** publicar semanalmente el último hash en un medio externo —correo firmado a la asesoría, servicio de sellado de tiempo—. Ancla la cadena y evita que alguien con acceso total la reescriba entera.

## Alternativas descartadas

| Alternativa | Por qué se descarta |
|---|---|
| **Tabla de auditoría normal, sin encadenar** | Documenta, pero no prueba. Quien pueda editar una hora puede editar su rastro, y la tabla no lo delata. No satisface RS-07 |
| **Log de auditoría en fichero o en el stack de observabilidad** | Loki es opcional en la instalación del cliente y se puede perder al reinstalar (§8.2.1). El registro probatorio debe vivir en la base de datos que se respalda a diario y se conserva cuatro años |
| **Firmar cada entrada con clave asimétrica** | Aporta autoría criptográfica, pero la clave privada viviría en el mismo servidor que la tabla, así que quien puede reescribir la tabla puede refirmar. Coste sin garantía adicional. El encadenamiento, en cambio, obliga a reescribir **toda** la cadena desde el punto tocado, lo que el ancla externa hace detectable |
| **Blockchain o registro distribuido** | Requiere terceros y salida a internet, y el producto funciona íntegramente en red aislada (§6.7, RNF de portabilidad). Complejidad enorme para una garantía que el hash encadenado más un ancla externa ya ofrece |
| **Permitir `UPDATE` a la aplicación para «corregir errores de auditoría»** | Una entrada de auditoría equivocada se corrige **añadiendo otra**, nunca editándola. Conceder el permiso destruye la única garantía que la tabla aporta |

## Consecuencias

- **La instalación provisiona dos roles de base de datos**, no uno: el de la aplicación, sin `UPDATE` ni `DELETE` en `audit_log`, y el de mantenimiento que ejecuta la purga por partición (ADR-027). El segundo no aparece en el `.env` de la aplicación.
- **Un fallo al escribir en `audit_log` bloquea la acción auditada.** Es deliberado: una corrección de jornada que no deja rastro no puede confirmarse. Obliga a que la escritura de auditoría esté en la misma transacción que el hecho.
- **La cadena impone orden.** Dos escrituras concurrentes no pueden calcular `prev_hash` a la vez sin coordinación, así que la inserción se serializa. Al volumen de esta instalación —unos miles de entradas al día— es asumible; a otro volumen sería un cuello de botella que habría que rediseñar.
- **El `payload` se serializa de forma canónica.** Si el orden de las claves del JSON cambiara entre versiones, la cadena dejaría de verificar sin que nadie la haya tocado. Es el modo de fallo silencioso de este diseño y hay que probarlo.
- **La verificación diaria es parte del producto**, con destinatario, umbral y runbook (§9.3). Una alerta que suena por una purga legítima haría que alguien la silencie; por eso ADR-027 enseña al verificador a distinguirlas.
- **`audit_log` se conserva cuatro años** (RL-11), separado del log técnico, que vive 90 días y puede contener errores. Son dos registros distintos con propósitos incompatibles (§8.2.1).

## Verificación

- Prueba de integración: el usuario de aplicación recibe error de permisos al intentar `UPDATE` o `DELETE` sobre `audit_log` y sobre cualquiera de sus particiones.
- Prueba de integración: alterada una fila por fuera de la aplicación, `compliance:verify-audit-chain` denuncia la rotura e identifica la entrada.
- Prueba de integración: la cadena verifica en verde tras una purga sellada por ancla (ADR-027).
- Prueba unitaria: el cálculo del hash es estable ante reordenación de claves del `payload` (serialización canónica).
- Prueba de integración: una corrección de jornada cuya escritura de auditoría falla **no se confirma**; la transacción entera revierte.
- Prueba de *feature*: cada acción con relevancia legal del catálogo deja su entrada con actor, momento, sujeto y motivo (RL-04, RN-13).
- Prueba de autorización: el acceso a datos personales de terceros queda registrado (RS-05).
