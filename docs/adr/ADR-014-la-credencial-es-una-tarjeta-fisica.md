# ADR-014 — La credencial es una tarjeta física impresa, única modalidad del producto

| Campo | Valor |
|---|---|
| **Estado** | Aceptada |
| **Fecha** | 11 de agosto de 2026 (redactada el 14 de agosto de 2026, tarea 0.6) |
| **Decide** | `producto-licencia` · análisis completo en el [documento 04](../04-decision-credencial.md) |
| **Afecta a** | Tareas 1.10, 1.12, 1.13 y 2.12 · **Regla dura 11** de `CLAUDE.md`, y la 12 |
| **Requisitos** | RF-QR-01, RF-QR-04, RF-QR-05, RF-QR-06, RF-QR-08, RF-AT-11, RF-ID-09, RF-GP-01 |

> Procede de la primera tabla del [documento 02](../02-stack-tecnologico-y-plan-implementacion.md) §4, que ya fijaba decisión, contexto y consecuencias. Esta redacción los desarrolla y los enlaza con los requisitos y con las reglas duras; no cambia la decisión. El análisis comparado completo está en el [documento 04](../04-decision-credencial.md).

## Contexto

La credencial en el móvil del empleado es más barata, instantánea al dar de alta y transparente ante una rotación de clave. Sobre el papel gana. **Y no cubre a toda la plantilla de un hotel**, que es el criterio que decide.

El producto se vende a hoteles cuyo perfil de plantilla el fabricante no conoce de antemano. En hostelería, la credencial en móvil deja fuera a más gente de la que se supone desde un despacho (documento 04 §2):

- **Personal de temporada sin correo corporativo**, con contratos de dos o tres meses y rotación alta.
- **Prohibición del móvil durante el servicio**, el caso más subestimado: en muchas cocinas está prohibido por higiene y en pisos por política interna. Un sistema que exige sacar el teléfono para fichar **entra en conflicto con las normas del propio cliente**.
- **Uniformes sin bolsillos.** El personal de pisos y cocina a menudo no lleva el móvil encima durante el turno.
- **Perfiles sin smartphone o sin datos**, y terminales antiguos, concentrados justo en los puestos con más plantilla.

Y en un sistema de registro horario, cada persona que no puede fichar por el canal previsto no genera una molestia: **genera una jornada que se registra a mano**. Las correcciones manuales son precisamente lo que erosiona el valor probatorio del registro que este producto existe para producir.

El cliente que más necesita el sistema —un resort con 150 personas en pisos— es el que peor encaja con la credencial en móvil.

## Decisión

**La credencial QR se entrega en tarjeta física impresa y plastificada. El QR en dispositivo móvil no forma parte del producto.**

Con dos piezas que la acompañan y sin las cuales la decisión no se sostiene:

- **Respaldo obligatorio por PIN de 6 dígitos** (RF-AT-11), provisionado, entregado y restablecible con su propio registro (RF-ID-09). Es lo que impide que una tarjeta olvidada se convierta en una jornada sin registro. **El PIN no se imprime en la tarjeta**: quien la pierde perdería a la vez la credencial y su alternativa.
- **Logística viable**: impresión individual y masiva en hoja A4 (RF-QR-04), corrección de errores nivel Q para sobrevivir a una temporada de uso (RF-QR-05), registro de entrega con fecha y responsable (RF-QR-06) y panel de estado que dice **quién no puede fichar todavía** (RF-QR-08).

La rotación de la clave de firma se reparte en semanas gracias al `key_id` del payload ([ADR-005](ADR-005-payload-qr-firmado-con-hmac.md)), en lugar de exigir reimprimir la plantilla entera en un día.

**Si una tarea, una petición o un documento sugiere credencial en móvil, invitación por correo o TOTP, se para y se pregunta** (regla dura 11).

## Alternativas descartadas

| Alternativa | Por qué se descarta |
|---|---|
| **QR en el móvil del empleado** | Cobertura variable e imprevisible (§2 del documento 04), dependencia de batería y de la entregabilidad del correo, incompatible con las normas internas de cocinas y pisos, y uso de propiedad personal para una finalidad laboral, que es fricción real en cada venta ante la representación de los trabajadores |
| **Modo dual: tarjeta y móvil** | Suma toda la familia de funcionalidad del móvil —invitaciones, correo, portal ampliado, caché offline, brillo de pantalla— sin eliminar nada de la logística de tarjetas. Se paga dos veces por cubrir a la misma gente. Queda como **desarrollo a medida** con las condiciones del §7 del documento 04, nunca como funcionalidad de serie |
| **Tarjeta NFC o RFID** | Mejor experiencia de lectura y peor encaje en el producto: exige lector por punto de fichaje, encarece el quiosco y elimina la ventaja de que cualquier tablet con cámara sirva. Además la tarjeta impresa se repone en minutos con una impresora normal |
| **Solo PIN, sin credencial física** | Seis dígitos tecleados por cada empleado en el cambio de turno son lentos y observables por quien está detrás en la cola. El PIN funciona como respaldo puntual, no como canal principal |
| **QR rotatorio TOTP** | La única mitigación real del préstamo de credencial, y exige pantalla. Se pierde a conciencia (documento 04 §6) a cambio de cobertura completa |

## Consecuencias

- **Logística de impresión y distribución a cargo del cliente**, y hay que decirlo en la venta y en la documentación: emisión con días de margen respecto a la incorporación, plastificado, entrega registrada y reposición en el día. Una tarjeta rota que tarda una semana son cinco días de fichajes por PIN.
- **La rotación de clave obliga a reimprimir**, progresivamente, con dos `key_id` activos en solape (RF-QR-07, tarea 2.12).
- **Se acepta el riesgo de préstamo de tarjeta**, autolimitado —quien la presta se queda sin fichar— y compensado con supervisión y detección de patrones anómalos (RF-PR-06), que es la contrapartida explícita de [ADR-009](ADR-009-sin-biometria.md).
- **El producto no depende del correo electrónico del empleado** (regla dura 12, [ADR-015](ADR-015-portal-con-codigo-y-pin.md)): el campo es opcional (RF-GP-01) y no hay invitaciones que entregar.
- **El PIN pasa a ser infraestructura crítica**, no un extra: sostiene el respaldo de fichaje y el acceso al portal, que es exigencia legal (RL-05). Por eso RF-ID-09 y la tarea 1.13 existen.
- **El panel de estado de credenciales es imprescindible**, no cosmético. Sin él, que alguien no puede fichar se descubre delante del quiosco a las 06:00.
- **Queda pendiente de validar en campo** lo que el documento 04 §9 enumera: cronometrar los tiempos reales de escaneo, comprobar la durabilidad de la tarjeta una temporada completa en cocina y contrastar el coste de impresión con proveedores reales antes de publicarlo.

## Verificación

- Prueba de *feature*: dar de alta a un empleado **sin dirección de correo** se completa sin error y emite su credencial (criterio de aceptación del documento 01 §11).
- Prueba de generación: el PDF produce tarjeta de 85,6 × 54 mm y hoja A4 múltiple, con QR de corrección de errores nivel Q (RF-QR-04, RF-QR-05).
- Prueba de *feature*: el registro de entrega guarda fecha y responsable, y queda en `audit_log` (RF-QR-06).
- Prueba de *feature*: el panel de estado distingue emitida, pendiente de imprimir, pendiente de entregar y revocada (RF-QR-08).
- Prueba E2E: un empleado sin tarjeta ficha con su PIN, el tramo queda marcado con origen `PIN_KIOSK` y señalado para revisión (RF-AT-11).
- Búsqueda en el árbol: ninguna funcionalidad de invitación por correo, credencial en pantalla ni TOTP.
