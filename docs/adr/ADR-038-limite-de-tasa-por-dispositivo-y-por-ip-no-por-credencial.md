# ADR-038 — Límite de tasa por dispositivo y por IP, no por credencial

| Campo | Valor |
|---|---|
| **Estado** | Aceptada |
| **Fecha** | 27 de agosto de 2026 |
| **Decide** | `arquitecto-dominio` |
| **Afecta a** | `docs/01-especificaciones-proyecto.md` §8 (RS-02) · `docs/requisitos.yaml` · `docs/02-stack-tecnologico-y-plan-implementacion.md` §7.1 · `backend/app/Http/RateLimiting/KioskRateLimit.php` |
| **Requisitos** | RS-02, RS-03, RS-12, RF-AT-06 |

## Contexto

RS-02 decía, literalmente: *«El sistema limita la tasa de escaneos **por dispositivo, por credencial y
por IP**, con respuestas de tiempo constante para evitar enumeración»*.

La revisión de seguridad del cierre de la Fase 1 encontró que solo existen dos de los tres ejes:
`KioskRateLimit` limita por dispositivo (clave derivada del token autenticado) y por IP. **No hay
límite por credencial en ninguna capa**, ni en Nginx ni en la aplicación. Y `docs/trazabilidad-pruebas.md`
daba RS-02 por cubierto con pruebas que verifican el PIN, el bloqueo de cuenta y el límite por origen
del login: ninguna de ellas toca el limitador de `/scan`.

Es decir: la matriz afirmaba más de lo que hay, y el requisito afirmaba más de lo que se implementó.
Lo que no puede quedarse es la afirmación sin respaldo. Quedan dos vías: implementar el tercer eje o
enmendar el requisito con la justificación.

## Decisión

**Se enmienda RS-02.** El límite de tasa del camino de fichaje se aplica por dispositivo y por IP. El
límite **por sujeto** se aplica donde el secreto es adivinable —el PIN, por empleado y por origen
(RS-12)— y **no** al escaneo de tarjeta.

Tres razones, en orden de peso:

**1. Contra la enumeración, un contador por credencial no sirve.** Es la finalidad que la propia frase
de RS-02 declara. Quien enumera prueba credenciales **distintas**: por definición nunca repite la
misma y nunca llega a disparar un contador asociado a ninguna. Los ejes que sí frenan la enumeración
son el de dispositivo y el de IP, que son justamente los que existen. Un eje que no puede activarse
en el escenario para el que se pide no es una defensa: es una casilla marcada.

**2. La repetición de una misma tarjeta ya está resuelta, y mejor.** Es RF-AT-06: dos lecturas de la
misma credencial dentro de la ventana de gracia son **un solo gesto de una persona**, y el sistema lo
resuelve como un desenlace **aceptado** (ADR-031), con `200 action: debounced`, sin crear tramo y sin
que el empleado vea un error. Un limitador que devolviera `429` haría lo mismo peor: reintentos de la
cola offline contra una ventana ya pasada, y una pantalla de error donde hoy hay un aviso.

**3. Un `429` por credencial es la única forma de dejar sin fichar a una persona concreta.** Todos los
demás límites del producto se agotan por dispositivo o por origen, y ante ellos la respuesta operativa
existe: usar otro quiosco, esperar el minuto, teclear el PIN. Un contador atado a la tarjeta viaja
**con la persona**: quien inunde con la credencial de otro —basta una foto del QR— la deja sin poder
fichar en ningún quiosco del hotel hasta que el contador expire. Eso contradice frontalmente la regla
que sostiene el registro legal: *el quiosco nunca bloquea al empleado* (regla dura 19, RF-AT-10).
Convertiría un control antiabuso en un vector de denegación de servicio dirigido, contra la persona
que menos culpa tiene y con consecuencias legales para el cliente, no para el atacante.

Hay además una consecuencia técnica que agrava las tres: para limitar por credencial hay que
identificarla, y hacerlo **después** de resolver el HMAC significa que el trabajo caro ya se pagó
—el limitador no protege nada—, mientras que hacerlo **antes**, sobre la huella del payload en claro,
mete material derivado del secreto de la tarjeta en claves de Redis y añade un camino donde la
respuesta puede diferir entre un payload conocido y uno inventado, que es exactamente el oráculo que
RS-03 y la regla dura 17 existen para cerrar.

## Alternativas descartadas

| Alternativa | Por qué se descarta |
|---|---|
| **Implementar el eje por credencial con clave `payload_fingerprint`** | Es la corrección que propuso la revisión. No frena la enumeración (§1), duplica peor lo que RF-AT-06 ya hace (§2) y crea un vector de denegación de servicio contra una persona concreta (§3) |
| **Implementarlo solo para credenciales que resuelvan** | El limitador correría después del trabajo que debía evitar, así que no limita nada útil; y seguiría dejando sin fichar al titular |
| **Dejar RS-02 como estaba y anotar la deuda** | Un requisito de seguridad que afirma un control inexistente es peor que no tenerlo: la matriz de trazabilidad lo da por cubierto y nadie vuelve a mirarlo. Es justo el hallazgo que abrió este ADR |
| **Convertir el eje por credencial en detección, no en bloqueo** | Ya existe y con mejor forma: RN-16 (secuencia imposible de credencial entre dos quioscos) genera **incidencia**, nunca rechazo, y es la Fase 3. Duplicarlo aquí como límite de tasa sería el mismo dato con peor consecuencia |

## Consecuencias

- **`docs/01-especificaciones-proyecto.md` §8** reescribe RS-02 y añade bajo la tabla la nota que
  explica el porqué. `docs/requisitos.yaml` actualiza el título, que es lo que imprime la matriz de
  trazabilidad.
- **`docs/02` §7.1** corrige la fila de la capa de Aplicación —decía «por credencial»— y la nota que
  citaba los tres ejes para justificar el techo de la VLAN interna.
- **Los dos ejes que sí existen ganan prueba propia sobre `/scan`**: que la cuota de una tablet se
  agota sin tocar la de las demás del mismo hotel —que es la razón de ser del eje de dispositivo— y
  que repetir la misma tarjeta no agota ninguna cuota. Antes RS-02 se apoyaba en pruebas de otros
  endpoints.
- **RS-12 no se toca y sigue siendo el eje por sujeto del producto**: bloqueo escalonado por empleado
  y origen (3/5/10), que es donde un espacio de 10⁶ combinaciones sí se puede recorrer a fuerza bruta.
- Si algún día apareciera un abuso real por credencial, la respuesta correcta no es un `429`: es una
  **incidencia** por el camino de RN-16, que hace visible el patrón sin dejar a nadie sin fichar.

## Verificación

- `tests/Feature/Attendance/ScanAuthorizationTest.php`: la cuota por dispositivo se agota en una
  tablet y la otra sigue fichando; seis escaneos de la misma tarjeta no producen ningún `429`.
- `php artisan qa:traceability --check`: RS-02 queda cubierto por pruebas que verifican lo que su
  enunciado dice, y no por las del PIN y el login.
- Sabotaje: subir `kiosk.rate_limits.scan_per_device` en la prueba del eje de dispositivo debe hacer
  que deje de aparecer el `429`.
