# Decisión de credencial: tarjeta física impresa
## Análisis comparado y justificación

| Campo | Valor |
|---|---|
| **Decisión** | La credencial QR es una **tarjeta física impresa**. Única modalidad del producto |
| **Registrada como** | ADR-014 |
| **Fecha** | 11 de agosto de 2026 |
| **Contexto** | Producto licenciado, desplegado en servidores del cliente, vendible a múltiples hoteles |
| **Documentos relacionados** | `01-especificaciones-proyecto.md` §3.2, `02-stack-tecnologico-y-plan-implementacion.md` §5 |

---

## 1. La decisión

> **La credencial QR se entrega en tarjeta física impresa y plastificada. El QR en dispositivo móvil no forma parte del producto.**

El criterio que decide es la **cobertura de plantilla**. La tarjeta funciona para el 100 % de las personas trabajadoras; el móvil no. Y en un sistema de registro horario, cada persona que no puede fichar por el canal previsto no genera una molestia: genera **una jornada que se registra a mano**. Las correcciones manuales son precisamente lo que erosiona el valor probatorio del registro que este sistema existe para producir.

Este documento recoge el análisis que sostiene la decisión, incluidas las ventajas reales de la alternativa descartada y las condiciones bajo las cuales merecería la pena reconsiderarla.

---

## 2. Por qué la cobertura manda sobre todo lo demás

El producto se vende a hoteles cuyo perfil de plantilla el fabricante no conoce de antemano. Un sistema a medida puede asumir cosas sobre sus usuarios; un producto tiene que funcionar en escenarios que su autor no ha visto.

En hostelería, la credencial en móvil deja fuera a más gente de la que se supone desde un despacho:

| Caso | Por qué ocurre |
|---|---|
| **Personal de temporada sin correo corporativo** | Contratos de dos o tres meses en temporada alta. Crear y gestionar cuentas de correo para esa rotación es trabajo administrativo que ningún cliente quiere |
| **Prohibición del móvil durante el servicio** | El más subestimado. En muchas cocinas el teléfono está prohibido por higiene, y en pisos por política interna. Un sistema que exige sacar el móvil para fichar **entra en conflicto directo con las normas del propio cliente** |
| **Uniformes sin bolsillos** | Prosaico y real: el personal de pisos y cocina a menudo no lleva el móvil encima durante el turno. Una tarjeta cabe en una funda de acreditación o colgada al cuello |
| **Perfiles sin smartphone o sin datos** | Menos frecuente que antes, pero no cero, y concentrado precisamente en los puestos con más plantilla |
| **Terminales antiguos** | Navegadores que no soportan las capacidades necesarias |

Un hotel boutique de 20 personas y un resort de 400 habitaciones con 150 personas en pisos son clientes distintos. **El segundo es el que más necesita el sistema, y es el que peor encaja con la credencial en móvil.**

---

## 3. Comparativa por dimensiones

Leyenda: ✅ ventaja clara · ⚠️ con reservas · ❌ desventaja clara

| Dimensión | Tarjeta física | Móvil del empleado | Comentario |
|---|---|---|---|
| **Cobertura de plantilla** | ✅ 100 % | ❌ Variable e imprevisible | El factor decisivo. Ver §2 |
| **Velocidad en el quiosco** | ✅ ~3 s | ⚠️ ~8–12 s | Ver §4.1 |
| **Disponibilidad** | ✅ Sin batería, sin red | ❌ Depende de batería y estado del terminal | El móvil descargado es un caso diario, no excepcional |
| **Resistencia a la falsificación** | ✅ Idéntica | ✅ Idéntica | La firma HMAC protege igual en ambos soportes |
| **Resistencia al préstamo** | ⚠️ Autolimitado: el titular se queda sin la suya | ❌ Una captura se comparte y el titular conserva la suya | Ver §4.2 |
| **Antifraude avanzado (TOTP)** | ❌ Imposible | ✅ Única vía | Ver §6 |
| **Alta de un empleado nuevo** | ⚠️ Requiere imprimir | ✅ Instantánea | Con rotación estacional alta, pesa |
| **Rotación de la clave de firma** | ⚠️ Reimpresión progresiva | ✅ Transparente | Mitigado por `key_id` con solape |
| **Coste inicial** | ⚠️ Impresora y consumibles | ✅ Cero | Ver §5 |
| **Coste recurrente** | ⚠️ Reposición y horas de administración | ⚠️ Soporte y entregabilidad de correo | Comparables. Ver §5 |
| **Dependencia de terceros** | ✅ Ninguna | ❌ Proveedor de correo y navegador del empleado | Si el correo no llega, no hay credencial |
| **Uso de propiedad personal** | ✅ No aplica | ❌ Fricción laboral en cada venta | Ver §4.3 |
| **Entorno de trabajo** | ✅ Plastificada, sobrevive a una cocina | ⚠️ Grasa, reflejos, pantalla agrietada | Ver §4.4 |
| **Normas internas del hotel** | ✅ Compatible siempre | ❌ Incompatible donde el móvil está prohibido | Ver §2 |
| **Barrera idiomática y digital** | ✅ Ninguna: se enseña y ya está | ⚠️ Requiere activar cuenta y manejar una app | Plantilla internacional y de temporada |
| **Carga de soporte** | ⚠️ "He perdido la tarjeta" | ⚠️ "No me llega el correo", "he cambiado de móvil" | Distinta naturaleza, volumen parecido |
| **Impacto ambiental** | ⚠️ PVC o papel plastificado | ✅ Ninguno | Menor, pero algunas cadenas lo puntúan en licitación |
| **Complejidad del producto** | ✅ Una vía de emisión | ❌ Invitaciones, correo, caché offline, brillo de pantalla | Ver §5.3 |

---

## 4. Los cuatro factores secundarios que confirman la decisión

### 4.1 Velocidad en el cambio de turno

| Acción | Tarjeta | Móvil |
|---|---|---|
| Sacarla del bolsillo o la funda | ~1 s | ~2 s |
| Desbloquear | — | ~2 s |
| Abrir la aplicación | — | ~2 s |
| Esperar a que suba el brillo | — | ~1 s |
| Presentar al lector | ~2 s | ~3 s (buscar el ángulo sin reflejo) |
| **Total** | **~3 s** | **~8–12 s** |

Con 30 personas entrando a las 06:00, la diferencia son entre tres y cinco minutos de cola. En un cambio de turno de hotel, eso decide si el sistema se percibe como una herramienta o como un estorbo, y la percepción de la plantilla determina si el proyecto tiene éxito.

*(Cifras estimadas a partir de la secuencia de interacciones, no medidas. Conviene cronometrarlas en la prueba de campo.)*

### 4.2 Resistencia al fraude: el matiz contraintuitivo

Ambos soportes son **idénticos** frente a la falsificación: la firma HMAC impide generar una credencial válida sin la clave del servidor, esté impresa o en pantalla.

La diferencia está en el préstamo, el llamado *buddy punching*:

- **Tarjeta:** prestarla exige una entrega física y **deja al titular sin la suya**. Es un fraude autolimitado: solo funciona si el titular no piensa fichar, y requiere coordinación previa y devolución.
- **Móvil:** una captura de pantalla enviada por mensajería tarda dos segundos, **el titular conserva la suya**, y puede replicarse a varias personas a la vez sin coste ni coordinación.

Es decir: **la credencial en móvil agrava el riesgo de préstamo respecto a la tarjeta.** Suele pasarse por alto porque intuitivamente lo digital parece más seguro.

### 4.3 Uso del dispositivo personal

Exigir el móvil propio para una finalidad laboral genera fricción real: es una objeción previsible de la representación legal de los trabajadores, plantea si la empresa debe compensar el uso del terminal y de los datos, y obliga a comprometerse por escrito a que el sistema no accede al contenido del dispositivo.

Con tarjeta, la cuestión no se plantea. Para un producto B2B esto importa doblemente: es una objeción que el equipo comercial tendría que responder en **cada** venta, y que puede retrasar o bloquear la aprobación interna del cliente.

*(Describe una fricción práctica y de relaciones laborales, no un dictamen jurídico.)*

### 4.4 Entorno físico

Una tarjeta PVC plastificada aguanta una cocina, humedad, grasa y caídas durante toda una temporada. Cuesta un euro reponerla, y el QR se genera con **corrección de errores nivel Q**, que tolera hasta un 25 % de degradación.

Un móvil en el mismo entorno presenta tres problemas de lectura: reflejos en la pantalla bajo la iluminación del vestíbulo de servicio, grasa y suciedad en el cristal, y **pantallas agrietadas**, que en hostelería son la norma y no la excepción.

---

## 5. Coste

Cifras de referencia para un hotel de 150 empleados con rotación estacional del 60 % anual (≈ 240 credenciales al año). **Son órdenes de magnitud para estructurar la decisión, no un presupuesto: deben validarse con proveedores reales.**

### 5.1 Tarjeta

| Concepto | Coste estimado |
|---|---|
| Impresora de tarjetas PVC (opcional) | 400–900 € una vez |
| Alternativa: impresión en papel más plastificadora | 60–150 € una vez |
| Consumible por tarjeta | 0,30–1,00 € |
| 240 tarjetas al año | 70–240 €/año |
| Tiempo de administración (≈ 4 min por credencial) | ~16 h/año |
| **Primer año** | **~500–1.150 € más 16 h** |
| **Años siguientes** | **~70–240 € más 16 h** |

Estos costes los asume el cliente, no el fabricante.

### 5.2 Móvil

| Concepto | Coste estimado |
|---|---|
| Consumible por credencial | 0 € |
| Configuración de SPF, DKIM y DMARC del dominio del cliente | 2–4 h una vez, **por instalación** |
| Soporte de activación (≈ 15 % de incidencias) | ~12–20 h/año |
| **Primer año** | **~0 € más 14–24 h** |
| **Años siguientes** | **~0 € más 12–20 h** |

### 5.3 La lectura correcta

El coste económico del móvil es prácticamente cero, pero **el coste en horas de administración es comparable**, solo que desplazado: de imprimir y entregar, a perseguir correos que rebotan y reactivar cuentas.

Y hay un coste que no aparece en las tablas anteriores: **el de construir y mantener la funcionalidad**. La credencial en móvil añade al producto invitaciones de un solo uso, envío y entregabilidad de correo, seguimiento de rebotes, caché cifrada de la credencial, service worker, control de brillo de pantalla y toda una segunda familia de modos de fallo que documentar, probar y soportar en cada instalación.

**La diferencia económica para el cliente no es lo bastante grande como para decidir por ella. Decide la cobertura de plantilla, y el coste de producto la confirma.**

---

## 6. Lo que se pierde y cuándo importaría

Descartar la credencial en móvil tiene tres costes reales que conviene tener presentes:

**1. Se cierra la puerta al QR rotatorio TOTP.** Es la **única** mitigación efectiva del préstamo de credenciales: ningún QR estático, en cartón o en pantalla, resiste una copia. Sin móvil no hay TOTP.

*Mitigación adoptada:* el préstamo de tarjeta es autolimitado (§4.2), y se combate con supervisión presencial más **auditoría de patrones anómalos**: dos fichajes en el mismo quiosco separados por segundos, un empleado que ficha sistemáticamente a la misma hora que otro, o entradas imposibles según la planificación. Es un control barato y funciona con cualquier soporte.

**2. Se mantiene la logística física.** Imprimir, plastificar, distribuir y reponer, con una plantilla de alta rotación estacional.

*Mitigación adoptada:* impresión masiva en hoja A4 (que es lo que hace viable dar de alta a 40 personas de temporada en una tarde), panel de estado de credenciales que anticipa las emisiones pendientes, y reposición en el día.

**3. La rotación de la clave de firma obliga a reimprimir.** Sería instantánea en modo digital.

*Mitigación adoptada:* el `key_id` en el payload permite mantener dos claves activas en solape, de modo que la reimpresión se reparte en semanas sin dejar a nadie sin poder fichar.

---

## 7. Cuándo reconsiderar esta decisión

La credencial en móvil **no es funcionalidad prevista del producto**. Se evaluaría como desarrollo a medida si un cliente concreto lo solicita y se dan estas condiciones:

| Condición | Por qué importa |
|---|---|
| El cliente confirma que **toda** su plantilla tiene correo corporativo y smartphone apto | Sin esto, se reintroduce el problema de cobertura que motivó la decisión |
| Su política interna **permite el móvil** en todas las áreas de trabajo | Cocinas y pisos son el caso crítico |
| Tiene un problema declarado de fichajes fraudulentos que justifique el TOTP | Es la única ventaja que la tarjeta no puede replicar |
| Acepta que la tarjeta siga disponible para quien la necesite | El modo dual, no la sustitución |
| Asume el coste del desarrollo a medida | ~20–26 h: invitaciones, correo, portal ampliado, caché offline |

**Punto a favor de que sea viable:** el portal del empleado ya existe en el producto, porque el acceso de la persona trabajadora a su propio registro es una exigencia legal (RL-05, art. 34.9 ET). Mostrar allí una credencial es una pantalla más, no una aplicación nueva. Eso mantiene el desarrollo a medida en un tamaño razonable si algún día se pide.

---

## 8. Cómo se implementa la decisión

| Aspecto | Resolución |
|---|---|
| **Formato del payload** | `FH1.<key_id>.<token>.<sig>`, opaco y firmado con HMAC-SHA256. Sin PII, no enumerable |
| **Corrección de errores** | Nivel Q: tolera un 25 % de degradación, que es lo que permite a la tarjeta sobrevivir una temporada de uso diario |
| **Formatos de impresión** | Tarjeta de crédito (85,6 × 54 mm) para impresora PVC, y hoja A4 con varias por página para impresión convencional |
| **Contenido de la tarjeta** | Nombre, departamento, centro y QR. Marca del cliente configurable |
| **Registro de entrega** | Fecha y responsable, auditado. Distingue "se perdió antes de entregarla" de "el empleado la perdió", que son incidencias distintas |
| **Panel de estado** | Quién la tiene emitida, pendiente de imprimir, pendiente de entregar o revocada. Evita descubrir a las 06:00 que alguien no puede fichar |
| **Reposición** | Revocación y reimpresión en el día. Una tarjeta rota que tarda una semana son cinco días de fichajes por PIN |
| **Respaldo obligatorio** | PIN de 6 dígitos en el quiosco (RF-AT-11). Es lo que impide que una tarjeta olvidada se convierta en una jornada sin registro |

---

## 9. Conclusión

**Tarjeta física impresa, con PIN de respaldo obligatorio.**

El criterio que decide es la cobertura de plantilla, porque en un sistema de registro horario cada persona que no puede fichar por el canal previsto genera una corrección manual, y las correcciones manuales degradan el valor probatorio del registro.

Los factores económicos no deciden: el coste de ambas opciones es del mismo orden para el cliente, solo que uno se paga en euros y el otro en horas de soporte. Lo que sí desequilibra la balanza en el lado del producto es que la credencial en móvil añade una familia entera de funcionalidad —invitaciones, correo, entregabilidad, caché offline, brillo— que hay que construir, documentar, probar y soportar en cada una de las instalaciones vendidas.

Se acepta a cambio un residuo de riesgo de préstamo de credencial, mitigado por su carácter autolimitado, por la supervisión presencial y por la auditoría de patrones anómalos.

### Pendiente de validar

1. **Cronometrar de verdad** los tiempos de §4.1 en la prueba de campo, con el hardware real y en el cambio de turno.
2. **Validar la durabilidad de la tarjeta** en un entorno de cocina durante una temporada completa, y confirmar que el nivel Q de corrección de errores basta.
3. **Contrastar el coste de impresión** con proveedores reales antes de publicarlo en la documentación comercial.
