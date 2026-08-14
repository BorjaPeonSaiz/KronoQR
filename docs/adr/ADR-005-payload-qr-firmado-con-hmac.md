# ADR-005 — Payload QR firmado con HMAC-SHA256 y `key_id`

| Campo | Valor |
|---|---|
| **Estado** | Aceptada |
| **Fecha** | 11 de agosto de 2026 (redactada el 14 de agosto de 2026, tarea 0.6) |
| **Decide** | `seguridad-cumplimiento` con `arquitecto-dominio` |
| **Afecta a** | Tareas 1.5, 1.7, 1.10 y 2.12 · [documento 02](../02-stack-tecnologico-y-plan-implementacion.md) §5 · **Regla dura 10** de `CLAUDE.md`, y la 17 |
| **Requisitos** | RF-QR-01, RF-QR-02, RF-QR-05, RF-QR-07, RS-01, RS-02, RS-03, RL-08 |

> Procede de la primera tabla del [documento 02](../02-stack-tecnologico-y-plan-implementacion.md) §4, que ya fijaba decisión, contexto y consecuencias. Esta redacción los desarrolla y los enlaza con los requisitos y con las reglas duras; no cambia la decisión.

## Contexto

La credencial es una tarjeta impresa que circula por un hotel ([ADR-014](ADR-014-la-credencial-es-una-tarjeta-fisica.md)): se fotografía sin querer, se deja sobre una barra, se ve en el cuello de un compañero. El contenido del QR hay que darlo por público.

Con esa premisa, un payload ingenuo falla de tres maneras distintas:

- **Legible o adivinable.** `EMP-000147` permite a cualquiera fabricar la credencial del compañero con un generador de QR de una web y fichar por él. Es suplantación pura, y deja el registro horario sin valor.
- **Secuencial.** Permite enumerar la plantilla: cuántos empleados hay, cuándo entró cada uno, quién ya no está.
- **Con datos personales.** Nombre o DNI impresos en el propio código son datos personales publicados en un objeto que se pierde (RL-08, principio de minimización).

Y hay un cuarto problema que no es de diseño sino de operación: **la clave de firma habrá que rotarla alguna vez**, y con 500 tarjetas impresas, una rotación sin solape significa reimprimir toda la plantilla en un solo día o dejar a la gente sin poder fichar.

## Decisión

**El payload es opaco, va firmado con HMAC-SHA256 y lleva el identificador de la clave que lo firmó.**

```
FH1.<key_id>.<token>.<sig>

FH1      Prefijo y versión del esquema
key_id   Identificador de la clave de firma (2 caracteres)
token    22 caracteres base64url = 128 bits de aleatoriedad, opaco y no enumerable
sig      16 caracteres base64url de HMAC-SHA256(key[key_id], "FH1." + key_id + "." + token)

Ejemplo: FH1.a3.7QK2mXpR9vLdN4tZbYcF1w.k9Xm2pQrT5vN8wLa
```

Unos 50 caracteres, que caben en un QR versión 3 con **corrección de errores nivel Q** (RF-QR-05): tolera un 25 % de degradación, que es lo que permite a una tarjeta sobrevivir una temporada en una cocina.

**La verificación en el servidor es una secuencia fija de seis pasos** (§5.2): prefijo, resolución de la clave por `key_id`, recálculo del HMAC comparado en tiempo constante con `hash_equals`, búsqueda de la credencial por **hash** del token —nunca se almacena el token en claro—, comprobación de revocación y de estado del empleado, y **respuesta idéntica y de igual duración para todos los rechazos** (RS-03, regla dura 17). El detalle solo va al log del servidor y a `scan_events.result`.

**La rotación mantiene dos claves activas en solape** (`current` y `previous`). Se emite un `key_id` nuevo, las tarjetas se reimprimen progresivamente y la clave anterior se retira cuando el panel confirma que no queda ninguna credencial activa con ese identificador (RF-QR-07).

## Alternativas descartadas

| Alternativa | Por qué se descarta |
|---|---|
| **Identificador plano o secuencial en el QR** | Falsificable con un generador de QR gratuito y enumerable. Es el fallo que este ADR existe para cerrar (RS-01) |
| **UUID aleatorio sin firma** | No enumerable, pero el servidor tendría que consultar la base de datos para saber si un código cualquiera es suyo, lo que abre un oráculo por tiempo de respuesta y un vector de agotamiento bajo escaneo masivo. La firma permite descartar basura **antes** de tocar la base de datos |
| **Firma asimétrica (ed25519) del payload** | La verificación ocurre en el mismo servidor que emite: no hay tercero que verifique sin poder firmar, que es lo único que justificaría la asimetría. Y la firma no cabría en un QR legible desde 20 cm con nivel Q. Sí se usa asimétrica donde sí hay dos partes: la licencia ([ADR-018](ADR-018-licencia-firmada-con-verificacion-local.md)) |
| **Cifrar el identificador en lugar de firmarlo** | Resuelve la opacidad pero no la integridad, y obliga a custodiar una clave con las mismas exigencias para ganar menos |
| **QR rotatorio tipo TOTP** | Es la única mitigación real del préstamo de credencial, y **exige pantalla**: incompatible con la tarjeta impresa (ADR-014, documento 04 §6). Se acepta el riesgo residual y se compensa con RF-PR-06 |
| **Sin `key_id`, una sola clave** | Ahorra dos caracteres y convierte la rotación en una reimpresión total en un día. El `key_id` es lo que hace la rotación una operación de semanas y no una crisis |

## Consecuencias

- **Hay una clave que custodiar y rotar.** Vive en el gestor de secretos del cliente, nunca en el repositorio (§7.7), y su rotación tiene runbook propio. Comprometerla obliga a reemitir toda la plantilla.
- **No impide el préstamo físico de la tarjeta**, y el documento lo dice sin adornos (§5.4). Es un fraude autolimitado —quien la presta se queda sin fichar— que se combate con supervisión y con la detección de patrones anómalos (RF-PR-06, RN-16), no con criptografía.
- **Todos los rechazos son iguales y cuestan lo mismo.** Es incómodo para el soporte —«no funciona mi tarjeta» no dice por qué— y por eso el panel de estado de credenciales (RF-QR-08) existe: la causa se consulta ahí, autenticado, no se deduce del quiosco.
- **El prefijo `FH1` no se renombra.** Cambiarlo invalidaría credenciales ya impresas; es un identificador técnico interno y no lo ve el usuario (nota de nomenclatura del documento 02).
- **El quiosco puede comprobar el formato sin red**, pero **no la firma**: la clave no sale del servidor. Por eso el modo offline encola y confirma provisionalmente (regla dura 19), en lugar de validar en el dispositivo.
- **El token no se almacena en claro.** Una filtración de la tabla `credentials` no permite fabricar tarjetas.

## Verificación

- Prueba unitaria: un payload con firma alterada en un solo carácter se rechaza.
- Prueba unitaria: la verificación usa `hash_equals`; no hay comparación con `==` en el camino de firma.
- Prueba de *feature*: los cuatro motivos de rechazo —formato inválido, `key_id` desconocido, firma incorrecta, credencial revocada— producen **la misma respuesta HTTP y el mismo cuerpo**, y `scan_events.result` sí los distingue (RS-03).
- Prueba de tiempo constante: la diferencia de latencia entre rechazos no permite distinguir la causa por encima del ruido de medición.
- Prueba de integración: con dos `key_id` activos, una credencial de la clave anterior sigue fichando; retirada esa clave, deja de hacerlo (RF-QR-07).
- Prueba de integración: en la base de datos no existe ningún token en claro, solo su hash.
- Prueba de generación: el PDF de tarjeta produce un QR con corrección de errores nivel Q (RF-QR-05).
