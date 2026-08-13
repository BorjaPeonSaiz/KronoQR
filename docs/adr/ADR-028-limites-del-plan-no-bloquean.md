# ADR-028 — Los límites del plan nunca bloquean el alta ni el emparejamiento

| Campo | Valor |
|---|---|
| **Estado** | Aceptada |
| **Fecha** | 13 de agosto de 2026 |
| **Decide** | `arquitecto-dominio` |
| **Afecta a** | Tarea 5.3 · ADR-019 y ADR-023, que acota y aplica · Regla dura 15 de `CLAUDE.md` |
| **Requisitos** | RF-PD-08, RF-PD-09, RF-ID-04, RF-QR-06, RL-01, RL-05 |

## Contexto

`license` lleva tres cifras de plan: `max_sites`, `max_employees` y `max_devices`. Ningún documento decía qué ocurre al superarlas, y la tarea 5.3 lo resolvió por su cuenta con la salida intuitiva: *«el efecto es un aviso y el bloqueo de la operación de alta correspondiente»*.

**Esa salida produce, por un rodeo, exactamente el resultado que ADR-019 declara inaceptable.**

ADR-019 y la regla dura 15 prohíben que la licencia bloquee el fichaje o el acceso al registro legal, porque *«bloquear el fichaje dejaría al cliente incumpliendo la ley por acción del fabricante»*. Bloquear el **alta** no bloquea el fichaje de nadie que ya esté dado de alta — bloquea el de quien todavía no lo está:

- **`max_employees` superado.** Entra una persona nueva en temporada alta. RRHH no la puede dar de alta, no tiene tarjeta, no puede fichar. Esa persona **trabaja sin registro horario**, que es una infracción del art. 34.9 ET imputable al cliente y causada por el producto. RL-01 exige registrar la jornada de *toda* la plantilla, no de la plantilla que cabe en el plan.
- **`max_devices` superado.** Se avería el quiosco de recepción y se sustituye por otro. El emparejamiento del nuevo se rechaza porque el averiado sigue contando. **El centro se queda sin punto de fichaje** en el peor momento posible: el del incidente.
- **`max_sites` superado.** Mismo razonamiento, escalado a un centro entero.

En los tres casos, el mecanismo pensado como palanca comercial acaba impidiendo el registro legal. La diferencia con ADR-019 es solo de momento: no bloquea el fichaje de hoy, bloquea el de mañana. Es la misma consecuencia con un día de retraso.

Y comercialmente tampoco funciona: un cliente al que el producto le impide dar de alta a un camarero en plena temporada no compra más licencias, deja de usar el producto y lo cuenta.

## Decisión

**Superar `max_employees`, `max_sites` o `max_devices` nunca bloquea una operación. Produce evidencia.**

Tres efectos, los tres verificables en una auditoría de licencia:

1. **Aviso persistente en el panel**, visible para los roles de administración, con la cifra contratada, la cifra real y desde cuándo se supera. Persistente significa que no se descarta: desaparece cuando el exceso se corrige o la licencia se amplía.
2. **Entrada en `audit_log`** al cruzar el umbral y en cada alta posterior en exceso, con la acción, el límite, el valor contratado y el valor alcanzado. Es la prueba que sostiene la reclamación comercial: la fecha exacta desde la que el cliente opera por encima del plan.
3. **Cifra visible en `license:show`**, el comando que el fabricante pide ejecutar en una revisión. Muestra contratado frente a real para las tres magnitudes.

**Ninguna ruta del producto puede devolver un error de licencia al dar de alta a una persona ni al emparejar un dispositivo.** `POST /api/v1/employees`, la importación masiva de plantilla, el alta de centro y `/kiosk/pair/confirm` responden 2xx aunque el plan esté superado.

La comprobación de límites es **una funcionalidad accesoria** en el sentido de ADR-023: vive en el punto único de decisión del `FeatureGate`, no en `if`s dispersos por los casos de uso de `Workforce`, `Identity` y `Kiosk`. Los casos de uso de alta no conocen la licencia: emiten su evento y el observador de `Product` cuenta y avisa.

La palanca comercial es el contrato, no el software. Un producto que se vende a hoteles y les impide operar en temporada alta no tiene palanca: tiene un fallo.

## Alternativas descartadas

| Alternativa | Por qué se descarta |
|---|---|
| **Bloquear el alta al superar el límite** | Es la redacción original de la tarea 5.3. Deja a una persona trabajando sin registro horario y a un centro sin quiosco. Contradice ADR-019 en su efecto, aunque no en su letra |
| **Bloquear solo `max_sites`, que no afecta a nadie que ya trabaje** | Sí afecta: un centro nuevo se abre con plantilla que empieza a trabajar ese día. Y una excepción a esta regla la convierte en negociable, que es como vuelven las otras dos |
| **Periodo de gracia de N días y después bloquear** | Solo mueve el bloqueo a una fecha en la que nadie recuerda por qué. El síntoma pasa a ser «el producto dejó de funcionar sin motivo» y el diagnóstico cuesta una llamada de soporte |
| **Degradar funcionalidades accesorias al superar el límite** (aplicar la degradación de ADR-023 al exceso de plan) | Confunde dos ejes: ADR-023 degrada por **caducidad**, que es falta de contrato vigente. El exceso de plan ocurre con contrato vigente y pagado, y castigar al cliente que crece con menos informes es una reacción desproporcionada y difícil de explicar |
| **No hacer nada y contar los excesos solo en `license:show`** | Sin aviso persistente y sin `audit_log` el fabricante no tiene fecha desde la que reclamar, y el cliente puede alegar con razón que nadie se lo dijo |

## Consecuencias

- **La tarea 5.3 cambia de signo:** deja de implementar un bloqueo y pasa a implementar aviso, auditoría y contador. Es menos trabajo, no más.
- **Hacen falta dos pruebas negativas explícitas**, porque el fallo que este ADR previene es de omisión y volvería sin ellas: con `max_employees` superado, `POST /api/v1/employees` responde 2xx; con `max_devices` superado, `/kiosk/pair/confirm` vincula.
- **El conteo es un observador, no un guardián.** Escucha los eventos de alta de `Workforce`, `Identity` y `Kiosk`; ninguno de esos módulos consulta la licencia, con lo que la frontera del §1.6 se respeta sin aristas nuevas.
- **`license:show` gana tres líneas** de contratado frente a real, y es lo que se pide en la revisión comercial y en el paquete de diagnóstico (ADR-020, anonimizado: cifras sí, nombres no).
- **Acota ADR-019 sin anularlo.** ADR-019 hablaba de caducidad; este extiende su principio a los límites de plan, que son la otra forma de que la licencia toque el registro legal. Ninguno de los dos se cambia sin otro ADR.
- **La misma prueba de arquitectura de ADR-023 cubre esto:** ninguna comprobación de licencia fuera del punto único. Si aparece una en un caso de uso de alta, falla el test antes que el cliente.

## Verificación

- **Prueba de *feature*:** con `max_employees` alcanzado, `POST /api/v1/employees` responde 2xx y el empleado queda dado de alta y capaz de fichar.
- **Prueba de *feature*:** con `max_devices` alcanzado, `/kiosk/pair/confirm` vincula el dispositivo y el quiosco queda operativo.
- **Prueba de *feature*:** con `max_sites` alcanzado, el alta de centro responde 2xx.
- **Prueba de integración:** cruzar cada umbral escribe una entrada en `audit_log` con límite, valor contratado y valor alcanzado.
- **Prueba de *feature*:** el aviso de exceso aparece en el panel para el rol de administración y no es descartable mientras el exceso persista.
- **Prueba de consola:** `license:show` imprime contratado y real para las tres magnitudes.
- **Prueba de arquitectura (compartida con ADR-023):** ninguna lectura de `license` ni comprobación de `features` fuera del punto único de decisión.
