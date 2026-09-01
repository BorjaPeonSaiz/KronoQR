# ADR-018 — Licencia firmada con verificación local, sin llamada a internet

| Campo | Valor |
|---|---|
| **Estado** | Aceptada |
| **Fecha** | 11 de agosto de 2026 (redactada el 14 de agosto de 2026, tarea 0.6) |
| **Decide** | `producto-licencia` con revisión de `seguridad-cumplimiento` |
| **Afecta a** | Tarea 5.3 · [ADR-019](ADR-019-la-licencia-nunca-bloquea-el-registro.md), [ADR-023](ADR-023-frontera-registro-legal-y-funcionalidad-accesoria.md) y [ADR-028](ADR-028-limites-del-plan-no-bloquean.md) · Regla dura 15 de `CLAUDE.md` |
| **Requisitos** | RF-PD-04, RF-PD-05, RNF-D-01, RL-14 |

> Procede de la primera tabla del [documento 02](../02-stack-tecnologico-y-plan-implementacion.md) §4, que ya fijaba decisión, contexto y consecuencias. Esta redacción los desarrolla y los enlaza con los requisitos y con las reglas duras; no cambia la decisión.

## Contexto

El producto necesita una forma de expresar qué ha contratado cada cliente: plan, límites, vigencia del soporte y funcionalidades habilitadas (RF-PD-04). La forma habitual en el mercado es la activación en línea contra un servidor del fabricante.

Aquí es inviable y además peligrosa:

- **El servidor del cliente puede estar en una red aislada.** El §11.6.2 declara la salida a internet como **opcional**, y el §6.7 exige que el sistema funcione íntegramente sin ella. Una licencia que necesita conectar convierte ese «opcional» en mentira.
- **Convertiría la conectividad del fabricante en punto único de fallo del registro horario de todos sus clientes.** Si el servicio de activación cae —o el fabricante desaparece—, decenas de hoteles se quedan sin registrar jornada por un problema comercial ajeno a ellos. Eso es exactamente el resultado que ADR-019 declara inaceptable.
- **Es un control comercial, no de seguridad.** El modelo de amenazas lo dice explícitamente (§8.1): la alteración de la clave de licencia **no debe protegerse a costa de bloquear el registro legal**. Un cliente decidido a manipularla puede hacerlo; la consecuencia es contractual, no técnica.

## Decisión

**La clave de licencia va firmada asimétricamente (ed25519, con `sodium` nativo de PHP) y se verifica en local. Nunca hay llamada a internet.**

- La clave codifica cliente, plan, límites (`max_sites`, `max_employees`, `max_devices`), `features` y vigencia, y se guarda en la tabla `license`.

  > **Enmienda 01-09-2026 (tarea 5.3): `max_sites` no existe.** [ADR-040](ADR-040-un-centro-por-instalacion-y-por-licencia.md), punto 5, lo retiró: una licencia es un centro, y una cadena de tres hoteles compra tres licencias. Los límites implementados son **dos**, `max_employees` y `max_devices`. Una clave que traiga `max_sites` **verifica igual y el campo se ignora**: la lectura tolera campos desconocidos —es lo que permite que una instalación en la 1.2 active la renovación emitida con la 1.6— y ese límite no llega al dominio ni se puede consultar. Modelarlo «por compatibilidad» lo dejaría a la vista de la primera persona que quisiera comprobarlo.
- **La verificación es local y no requiere red**: solo la clave pública del fabricante, incluida en el producto.
- **La licencia no se puede revocar a distancia**, y se asume. La palanca es la caducidad de la clave y el contrato.
- **La verificación no está en el camino del fichaje.** Un fallo al verificar la licencia no puede impedir que se registre una jornada ([ADR-019](ADR-019-la-licencia-nunca-bloquea-el-registro.md)), ni que se dé de alta a una persona o se empareje un quiosco ([ADR-028](ADR-028-limites-del-plan-no-bloquean.md)).

Se elige **asimétrica** y no HMAC porque aquí sí hay dos partes: el fabricante firma y la instalación del cliente verifica. Con clave simétrica, el secreto para verificar sería el mismo que para emitir, y viajaría en cada instalación. Es la diferencia con [ADR-005](ADR-005-payload-qr-firmado-con-hmac.md), donde firma y verificación ocurren en el mismo servidor.

## Alternativas descartadas

| Alternativa | Por qué se descarta |
|---|---|
| **Activación y comprobación periódica en línea** | Incompatible con la instalación aislada, y convierte la infraestructura del fabricante en punto único de fallo del registro horario de sus clientes. Además cada comprobación es un canal por el que podrían viajar datos, lo que complica el discurso de RL-14 y ADR-020 |
| **Licencia con HMAC (clave simétrica)** | La clave para verificar sería la clave para emitir, presente en cada instalación. Cualquier cliente podría fabricar licencias, incluida la de otro plan |
| **Sin licencia técnica, solo contrato** | El producto pierde toda evidencia de qué se contrató y de cuándo se supera el plan. `license:show` y el aviso persistente de ADR-028 son la prueba que sostiene una reclamación comercial |
| **Ofuscación o comprobaciones antimanipulación agresivas** | Coste real y falsa sensación de seguridad: quien controla el servidor gana siempre. Y el riesgo de que una comprobación mal hecha bloquee el fichaje es mucho mayor que el de que alguien altere su plan |
| **Licencia vinculada al hardware** | Rompe la sustitución de servidor, la restauración de copia y la migración, que son operaciones legítimas y frecuentes. Convertiría un incidente en dos |

## Consecuencias

- **La emisión de licencias es un proceso del fabricante** con la clave privada custodiada fuera del repositorio (§7.7), con su rotación documentada. Comprometerla permitiría emitir licencias válidas.
- **No hay revocación a distancia**, y es aceptable: la caducidad de la clave y el contrato son las palancas. Quien manipula su licencia incumple el contrato, no burla un control de seguridad de datos.
- **La instalación funciona sin internet de principio a fin**, incluida la verificación. Es un argumento de venta y una condición de RNF-D-01.
- **La clave pública viaja en el producto** y su rotación exige actualizar el producto, no la instalación de cada cliente. Hay que preverlo antes de necesitarlo.
- **La verificación se ejecuta fuera del camino crítico**, con su resultado cacheado y `last_verified_at` en la tabla. Un fallo de verificación produce aviso, nunca error en `/scan`.
- **El reloj del servidor determina la caducidad.** Atrasarlo la pospone, y es otro control que no se refuerza a costa de bloquear el registro: se registra la anomalía y se avisa.

## Verificación

- Prueba unitaria: una clave con un solo byte alterado no verifica.
- Prueba unitaria: una clave firmada con otra clave privada no verifica.
- Prueba de integración: la verificación se completa **sin ninguna conexión saliente**. La prueba corre con la red cortada.
- Prueba de *feature*: con licencia caducada, ausente o corrupta, `POST /api/v1/scan` sigue respondiendo 200 y el tramo se registra (ADR-019, regla dura 15).
- Prueba de consola: `license:show` muestra cliente, plan, límites contratados frente a reales y vigencia (ADR-028).
- Prueba de arquitectura: ninguna comprobación de licencia fuera del punto único de decisión (compartida con ADR-023 y ADR-028).

## Enmienda 01-09-2026 (tarea 5.3): cómo quedó implementado

La decisión no cambia. Se registra lo que fijó la implementación, para que quien
lo lea dentro de dos años no tenga que deducirlo del código.

- **Formato de la clave: `KQL1.<carga útil>.<firma>`**, las dos partes en
  base64url sin relleno. `KQL1` es la versión del **formato**, no del producto:
  permite cambiar de formato algún día sin reemitir las licencias vivas. Se
  firma sobre **el texto codificado tal y como viaja**, no sobre el JSON
  reserializado, porque dos codificadores JSON ordenan las claves distinto y eso
  produciría firmas que verifican en la máquina del fabricante y no en la del
  cliente.
- **Campos de la carga útil**: `license_id`, `customer_name`, `plan`,
  `max_employees`, `max_devices`, `features`, `valid_from`, `valid_until` e
  `issued_at`. Los instantes van en UTC canónico con sufijo `Z` (regla dura 3).
- **La lectura tolera lo desconocido.** Un campo o una funcionalidad que esta
  versión no conozca se ignoran y la clave verifica igual. Rechazar lo
  desconocido significaría que un hotel que sigue en la 1.2 no puede activar la
  renovación emitida con la 1.6, y el efecto sería una instalación degradada por
  un cambio aditivo del emisor. No hay riesgo de inyección: el contenido ya pasó
  la firma, y lo que no se lee no se aplica.
- **La emisión vive fuera del producto**, en `tools/license-issuer/` de la raíz
  del repositorio, que **no se copia a ninguna imagen** (`COPY backend/ ./`). La
  clave privada llega por variable de entorno y no aparece en el repositorio en
  ninguna forma. `tests/Integration/Product/LicenseIssuerRoundTripTest.php`
  ejecuta ese CLI como subproceso y verifica su salida con el verificador del
  producto: es lo único que ata las dos piezas, que no se despliegan juntas.
- **La clave pública viaja vacía de serie** y la rellena el fabricante antes de
  construir la primera imagen de *release*. Vacía significa «esta compilación no
  puede verificar ninguna clave»: se traduce al motivo `no_public_key`, el
  producto entero sigue funcionando y `license:show` lo dice con esas palabras,
  remitiendo a revisar el despliegue y no a pedir otra clave.
- **`last_verified_at` solo lo escriben los caminos que verifican a propósito**:
  la activación, `GET /api/v1/license` y `license:show`. El punto único de
  decisión no lo escribe: si lo hiciera, cada pantalla del panel sería una
  escritura sobre la tabla de licencia.
- **Se puede activar una clave ya caducada**, y queda constancia del estado
  resultante en `audit_log`. Un hotel que renueva con retraso recibe una clave
  cuya vigencia empezó el día 1; rechazarla le obligaría a pedir otra por una
  diferencia de calendario.
