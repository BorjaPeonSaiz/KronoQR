# KronoQR — Control de presencia y registro horario por QR

### Documento de presentación para el cliente

---

## 1. Qué es, en una frase

**KronoQR es un sistema de fichaje optimizado para hostelería: cada empleado escanea su tarjeta con código QR en una tablet situada en el centro, y el sistema construye automáticamente el registro horario que la ley obliga a llevar.**

No es una hoja de cálculo mejorada ni una app de control. Es el registro oficial de la jornada de su plantilla, con el nivel de fiabilidad, trazabilidad y conservación que exige la normativa laboral española.

---

## 2. El problema que resuelve

Desde 2019, toda empresa está obligada a llevar un registro diario de la jornada de cada trabajador, conservarlo cuatro años y tenerlo a disposición del trabajador, de sus representantes y de la Inspección de Trabajo.

En hostelería eso es especialmente incómodo:

| La realidad del hotel | Lo que provoca |
|---|---|
| Turnos de noche que cruzan las 12 de la noche | Los sistemas genéricos parten el turno en dos y descuadran las horas |
| Jornada partida en cocina y sala | Varias entradas y salidas el mismo día |
| Alta rotación en temporada | Altas continuas, gente que empieza mañana y aún no puede fichar |
| Muchos empleados sin correo de empresa ni móvil de empresa | Cualquier sistema que dependa del email o del smartphone deja gente fuera |
| Red wifi que a veces falla | Justo en el cambio de turno, cuando fichan 30 personas a la vez |

El resultado habitual es una hoja de papel en recepción, horas apuntadas a mano y un registro que no aguanta una inspección.

**KronoQR ataca exactamente esos puntos.** Cada decisión del diseño responde a uno de ellos.

---

## 3. Cómo funciona el día a día

### 3.1 Para el empleado

1. Llega al centro y acerca su **tarjeta plastificada** a la tablet colocada en la entrada de personal.
2. La tablet lee el QR sin que tenga que tocar nada, y en menos de un segundo muestra:
   > **Buenos días, Lucía — Entrada 07:02**
   con un sonido de confirmación distinto para entrada, para salida y para error.
3. Al salir, vuelve a pasar la tarjeta:
   > **Hasta luego, Lucía — Salida 15:02 · Hoy: 8 h 0 min**

Si ese día tiene jornada partida, simplemente pasa la tarjeta cada vez que entra y cada vez que sale. El sistema cuenta todos los tramos.

**¿Y si se ha olvidado la tarjeta?** Introduce su código de empleado y un **PIN de 6 dígitos** en la misma tablet. El fichaje queda registrado igual, marcado como "por PIN" para que el responsable lo revise. Nunca se queda un día sin registrar por un olvido.

**¿Y si el empleado quiere ver sus horas?** Entra desde cualquier navegador a su **portal personal** con su código y su PIN, y consulta sus jornadas, sus tramos y sus totales, y se descarga su histórico. No necesita correo electrónico ni instalar nada.

### 3.2 Para el responsable de departamento

Desde el panel web, en su ordenador:

- Ve **en tiempo real** quién está fichado ahora mismo en su departamento, desde qué hora y cuánto tiempo lleva. Se actualiza solo, sin recargar la página.
- Revisa el **detalle de cualquier jornada**: todos los tramos, los totales y lo que haya pasado.
- Corrige un fichaje cuando hace falta (alguien olvidó fichar la salida), indicando **obligatoriamente el motivo**.
- Atiende la **bandeja de incidencias**: turnos que llevan 13 horas abiertos, fichajes muy cortos, descansos insuficientes entre jornadas.

### 3.3 Para Recursos Humanos

- Da de alta y de baja empleados, departamentos, centros y contratos.
- **Emite las tarjetas QR** y las imprime: en formato tarjeta de crédito, o en hojas A4 con varias por página para dar de alta a 40 personas de temporada en una tarde.
- Registra la **entrega** de cada tarjeta (fecha y quién la entregó).
- Consulta un **panel de estado de credenciales** que responde a la pregunta clave: *¿quién está de alta pero todavía no puede fichar?*
- Saca los **informes**: horas por empleado, por departamento, por centro, y la comparación entre horas trabajadas y horas contratadas.
- Genera la **exportación para Inspección de Trabajo** cuando hace falta.

### 3.4 Para el auditor o la Inspección

Un rol de **solo lectura** que puede consultar los registros y las trazas, y descargar la exportación normalizada del periodo requerido, con las correcciones y sus motivos incluidos.

### 3.5 Para el informático del hotel

Instala el sistema en el servidor del hotel siguiendo una guía, lo configura con un asistente inicial, y a partir de ahí se ocupa de las copias de seguridad y de las actualizaciones, ambas asistidas por scripts que vienen incluidos.

---

## 4. Las siete decisiones que definen el producto

Antes del listado de funcionalidades, conviene entender siete decisiones de fondo. Explican por qué el sistema hace lo que hace.

### 4.1 La credencial es una tarjeta física, no el móvil

**Cubre al 100 % de la plantilla.** En hostelería el móvil deja fuera a demasiada gente: personal de temporada sin correo corporativo, cocinas y pisos donde el teléfono está prohibido durante el servicio, uniformes sin bolsillos. Cada persona que no puede fichar por el canal previsto genera una corrección manual, y las correcciones manuales son justamente lo que debilita el valor legal del registro.

Además, la empresa no pide a nadie que use su teléfono personal ni sus datos para una finalidad laboral, lo que elimina una fricción habitual con la representación de los trabajadores.

### 4.2 Sin biometría, en ningún caso

Nada de huella ni reconocimiento facial. Son datos de categoría especial y la autoridad de protección de datos ha sido restrictiva sobre su uso para control de presencia. Se ha descartado por decisión de diseño, no por limitación técnica.

### 4.3 Nada se borra ni se sobrescribe

Cuando un responsable corrige un fichaje, el sistema **no modifica el original**: crea una versión nueva y conserva la anterior con quién la cambió, cuándo y por qué. El registro original sigue siendo consultable siempre.

Esto es exactamente lo que la ley pide cuando exige que el registro sea "fiable e inalterable".

### 4.4 Un turno de noche es un solo turno

Un turno de 22:00 a 06:00 se registra como **un único tramo de 8 horas**, atribuido al día en que empezó. No se parte artificialmente a medianoche.

Puede parecer un detalle, pero cortar el turno fabrica dos registros de un hecho que ocurrió una sola vez: falsea el registro y estropea el cálculo del descanso entre jornadas.

Del mismo modo, los cambios de hora de marzo y octubre están contemplados: la noche del cambio, un turno dura las horas reales que duró, no las que aparenta el reloj.

### 4.5 La tablet nunca deja a nadie sin fichar

Si se cae la wifi, **la tablet sigue funcionando**. Guarda los fichajes en su propia memoria, confirma al empleado al instante, y cuando vuelve la conexión los envía al servidor conservando **la hora real a la que ocurrieron**, no la hora en que se sincronizaron.

Ambas horas quedan registradas y visibles, de modo que siempre se puede explicar la diferencia.

### 4.6 El QR va firmado criptográficamente

El código de la tarjeta no contiene el nombre ni el número de empleado: es un código opaco de 128 bits acompañado de una firma que solo el servidor sabe generar. Nadie puede fabricar la tarjeta de un compañero con un generador de QR de internet.

Lo que la firma no impide es que alguien **preste físicamente su tarjeta**. Es un fraude autolimitado —quien la presta se queda sin la suya y tiene que recuperarla— y se combate con supervisión presencial y con la detección automática de patrones anómalos.

### 4.7 La licencia nunca bloquea el fichaje

Si la licencia caduca, el sistema **sigue registrando fichajes y sigue permitiendo consultar y exportar el registro legal**. Se muestran avisos y se limitan funcionalidades accesorias, pero el registro obligatorio nunca queda secuestrado por la relación comercial.

Bloquearlo dejaría al hotel incumpliendo la ley por una acción del proveedor, e impediría el acceso a datos que está obligado a conservar cuatro años. Es inaceptable, con independencia de lo que diga cualquier contrato.

---

## 5. Funcionalidades, área por área

### 5.1 Fichaje

| Funcionalidad | Qué hace |
|---|---|
| Entrada y salida por QR | Un escaneo abre el turno; el siguiente lo cierra y calcula la duración |
| Jornada partida | Tantos tramos por día como haga falta, sin límite |
| Confirmación clara | Nombre, acción, hora y total acumulado del día, con aviso visual **y sonoro** |
| Protección anti-doble-escaneo | Un segundo escaneo en menos de 60 segundos (configurable) no duplica nada, solo avisa |
| Turnos nocturnos | Se registran completos, atribuidos al día de inicio |
| Doble marca de tiempo | Hora real del fichaje y hora de recepción en el servidor, siempre ambas |
| Control de reloj | Si la tablet tiene la hora desviada más allá del margen admitido, se avisa y se marca para revisión |
| PIN de respaldo | Fichaje con código de empleado y PIN de 6 dígitos cuando falta la tarjeta |
| Fichaje de pausa | Opcional y configurable por centro: marcar inicio y fin de descanso |
| Sin duplicados | Si la tablet reenvía un fichaje por un problema de red, el sistema reconoce que es el mismo y no lo duplica |

### 5.2 Tarjetas QR

| Funcionalidad | Qué hace |
|---|---|
| Emisión | Cada empleado tiene una credencial firmada, sin datos personales en el código |
| Impresión | PDF en formato tarjeta de crédito (85,6 × 54 mm) y hoja A4 con varias por página |
| Diseño | Nombre, departamento, centro y QR. Con el logotipo y colores del hotel |
| Resistencia | Nivel de corrección de errores alto: la tarjeta sigue leyéndose con hasta un 25 % de deterioro, que es lo que permite que sobreviva una temporada en una cocina |
| Registro de entrega | Fecha y responsable de la entrega, con traza |
| Revocación | Pérdida, robo o baja: la tarjeta deja de funcionar de inmediato y se emite otra el mismo día |
| Panel de estado | Emitida / pendiente de imprimir / pendiente de entregar / revocada. Responde a "¿quién no puede fichar todavía?" |
| Cambio de clave de firma | Se puede renovar la clave de seguridad reimprimiendo las tarjetas poco a poco, sin dejar a nadie sin fichar ni un solo día |

### 5.3 Panel de gestión

| Funcionalidad | Qué hace |
|---|---|
| Presencia en vivo | Quién está dentro ahora mismo, desde qué hora y en qué quiosco fichó. Se actualiza solo |
| Filtros y búsqueda | Por centro, departamento, estado y nombre |
| Detalle de jornada | Todos los tramos de un empleado y un día, con totales, incidencias y correcciones |
| Correcciones trazadas | Crear, ajustar la hora, cerrar un turno abierto o anular, siempre con motivo de un catálogo más texto libre |
| Bandeja de incidencias | Lista de situaciones pendientes, asignadas al responsable que corresponde, con flujo de resolución |
| Vista de cumplimiento | Avisos de descanso insuficiente entre jornadas, jornada diaria excesiva, falta de pausa y exceso semanal |
| Salud de las tablets | Última señal de vida, versión, fichajes pendientes de enviar y batería de cada quiosco |
| Histórico de errores | Todo fallo del sistema queda guardado 90 días con su fecha, su origen y cuántas veces se ha repetido. Su informático lo consulta desde el panel, sin necesidad de saber leer registros técnicos, y viaja en el paquete de diagnóstico si hay que enviarlo al soporte. **Sin nombres ni datos de nadie** |

### 5.4 Informes y exportaciones

| Funcionalidad | Qué hace |
|---|---|
| Horas por empleado | Diario, semanal, mensual o rango libre de fechas |
| Agregados | Por departamento y por centro |
| Trabajadas vs. contratadas | Con la desviación y el exceso de jornada |
| Formatos | CSV, Excel y PDF. Los PDF llevan sello de tiempo, emisor y huella del contenido |
| **Exportación para Inspección** | Registro diario por trabajador y periodo, en formato tabular legible, con las correcciones y sus motivos |
| Informes grandes | Se generan en segundo plano y se avisa con un enlace de descarga cuando están listos |
| Salida a nómina | Exportación de horas en el formato que necesite la herramienta de nómina del hotel |
| **Cuadro de impacto** | Qué está consiguiendo el sistema, con comparación entre periodos: qué porcentaje de jornadas queda registrado completo, cuántos fichajes son por tarjeta, por PIN o por corrección manual, cuánto se tarda en resolver una incidencia y cuánta gente sigue sin tarjeta entregada. Es el cuadro que responde a *"¿esto está sirviendo?"* con datos y no con impresiones |

### 5.5 Gestión de personas

Alta y baja de empleados con los datos mínimos necesarios; departamentos, centros y contratos (horas semanales y anuales, tipo de jornada, vigencia), con histórico. Registro de ausencias (vacaciones, baja, permiso) para que los informes no las cuenten como absentismo injustificado. Importación inicial de plantilla desde CSV o Excel, con validación previa y modo simulación antes de aplicar nada.

**El correo electrónico del empleado es un campo opcional.** El producto no depende de él en ningún punto.

**La baja de un empleado nunca borra su historial**: se desactiva, su tarjeta se revoca y su registro se conserva los cuatro años que marca la ley.

### 5.6 Accesos y permisos

Seis perfiles: administrador, RRHH, responsable de departamento, auditor, empleado y quiosco.

- Un responsable **solo ve a la gente de su departamento y su centro**. Si intenta acceder a otro, el sistema lo impide y lo deja registrado.
- Los perfiles con acceso a toda la plantilla (administrador, RRHH y auditor) tienen **verificación en dos pasos obligatoria**.
- La tablet tiene su propio acceso, limitado exclusivamente a fichar y sincronizar. Aunque alguien se llevara la tablet, **no obtendría el registro horario ni la ficha de nadie**: lo único que guarda es una lista cifrada con lo mínimo para saludar por su nombre a quien ficha —nombre de pila e inicial del apellido de la gente de ese centro—, y su acceso se revoca desde el panel en segundos.
- La sesión del portal del empleado solo alcanza sus propios datos. Nunca los de un tercero.

### 5.7 Procesos automáticos

| Proceso | Qué hace |
|---|---|
| Detección de turnos olvidados | Un turno abierto más de 12 horas (configurable) genera una incidencia y avisa al responsable. **Nunca se cierra solo**: un cierre automático sería inventarse una hora de salida |
| Consolidación nocturna | Recalcula los totales del día y los contrasta con los fichajes originales. Si algo no cuadra, avisa |
| Purga por retención | Al vencer el plazo legal, propone la eliminación, pide confirmación y emite informe de lo purgado |
| Copia de seguridad | Diaria, cifrada, verificada, con prueba de restauración periódica |
| Resumen semanal | Correo opcional al responsable de cada departamento |

---

## 6. Qué garantiza en materia legal

> Este apartado describe cómo el producto facilita el cumplimiento. No sustituye al asesoramiento jurídico ni a las obligaciones que corresponden al hotel como empresa.

### 6.1 Registro de jornada (art. 34.9 del Estatuto de los Trabajadores)

| La ley exige | Cómo lo cumple KronoQR |
|---|---|
| Registro **diario** con hora de inicio y fin | Cada tramo queda registrado con su hora exacta |
| Conservación **4 años** | Retención configurada y purga controlada al vencimiento, con informe |
| **A disposición** del trabajador, sus representantes y la Inspección | Portal personal, panel con rol auditor y exportación normalizada |
| **Fiable e inalterable** | Nada se borra ni se sobrescribe; toda corrección queda trazada con autor, momento, valor anterior y motivo |
| Acceso del trabajador a **su propio registro** | Portal personal con descarga de su histórico |
| Formato **legible y tratable** | CSV, Excel y PDF, formatos abiertos y no propietarios |

Sobre la inalterabilidad conviene ser preciso, porque es lo que diferencia este sistema de una hoja de cálculo: **cada acción con relevancia legal se anota en un registro de auditoría que solo admite añadir, nunca modificar ni borrar**, y cada anotación va encadenada criptográficamente con la anterior. Si alguien manipulase la base de datos por debajo, la cadena se rompería y el sistema lo detectaría y avisaría al día siguiente.

Eso permite afirmar ante una inspección no solo que "confiamos en que nadie lo tocó", sino que **cualquier manipulación sería detectable**.

### 6.2 Protección de datos

- **Base jurídica clara**: el registro horario responde a una obligación legal, no al consentimiento del trabajador.
- **Datos mínimos**: del DNI **no se guarda el número, ni siquiera cifrado**: solo una huella criptográfica irreversible que permite comprobar si un DNI ya está dado de alta, pero de la que nadie —tampoco el fabricante— puede recuperar el número original. El correo es opcional. La foto del empleado es opcional y viene desactivada.
- **Aviso de privacidad visible en la propia tablet**, con enlace o QR a la política completa.
- **Derechos del trabajador**: procedimientos para acceso, rectificación (mediante corrección trazada), limitación y portabilidad. La supresión queda condicionada al deber legal de conservar cuatro años.
- **Retención diferenciada**: registros de jornada y auditoría 4 años; registros técnicos 90 días.
- **Cifrado**: en tránsito siempre, y en reposo para las copias de seguridad y para los datos que se guardan en la tablet.
- **Los datos están en el servidor del hotel, en la Unión Europea.** No salen a ninguna nube del fabricante.

### 6.3 Reparto de responsabilidades sobre los datos

Este punto suele generar dudas, así que conviene dejarlo claro:

- **El hotel es el responsable del tratamiento.** Aloja los datos, controla los accesos y responde ante la Inspección y ante su plantilla.
- **El fabricante no es encargado del tratamiento en la operación normal**, porque no aloja ni accede a los datos. Solo actúa como tal, y de forma acotada, durante una intervención de soporte expresamente autorizada.
- **El fabricante no tiene acceso permanente.** Cuando hay una incidencia, el hotel genera un paquete de diagnóstico **anonimizado por defecto** y lo envía. Si en un caso concreto hiciera falta acceso directo, el hotel lo concede de forma expresa, con caducidad, con alcance limitado, revocable en cualquier momento y con cada acceso registrado y visible para el hotel.
- **El hotel puede exportar todos sus datos** en formato abierto, cuando quiera y sin intervención del fabricante. Es su garantía de no quedar atrapado en el producto.

---

## 7. Seguridad, sin jerga

| Riesgo | Qué hace el sistema |
|---|---|
| Alguien fabrica la tarjeta de un compañero | Imposible sin la clave del servidor: el código va firmado criptográficamente |
| Alguien prueba códigos al azar hasta acertar | El espacio de códigos es astronómico y hay límite de intentos por tablet, por tarjeta y por origen |
| El sistema revela si un código existe o está revocado | No lo hace: todos los rechazos dan el mismo mensaje y tardan lo mismo. El detalle solo va al registro interno |
| Alguien adivina un PIN por fuerza bruta | Bloqueo temporal creciente tras 3, 5 y 10 intentos, límite por IP, y portal restringido a la red interna salvo decisión expresa del hotel |
| Roban la tablet | Su acceso solo sirve para fichar y sincronizar; se revoca desde el panel y los datos que guarda están cifrados y son mínimos |
| Alguien modifica horas directamente en la base de datos | La cadena de auditoría lo detecta y se dispara una alerta. Además, el usuario de la aplicación no tiene permiso para modificar ni borrar el registro de auditoría |
| Un empleado niega haber fichado, o niega una corrección | Todo escaneo queda registrado, aceptado o no, con quién, cuándo y desde dónde |
| Alguien intenta ver datos que no le corresponden | Cada operación comprueba permisos, el intento se rechaza y queda anotado |
| Saturación del sistema en el cambio de turno | Límites de tasa, colas de proceso, y el modo offline que mantiene el fichaje operativo pase lo que pase |

A esto se añaden: cifrado obligatorio de las comunicaciones y análisis automático de vulnerabilidades en cada cambio de código.

---

## 8. Qué NO hace el sistema

Ser explícito aquí evita malentendidos:

| Fuera del alcance | Motivo |
|---|---|
| **Calcular la nómina**, pluses y complementos | Se **exporta** a la herramienta de nómina del hotel; no se calcula aquí |
| **Planificar cuadrantes de turnos** | Contemplado como evolución futura. El modelo de datos deja la puerta abierta |
| **Aprobar vacaciones y permisos** | Se registran las ausencias para no falsear los informes, pero sin flujo de aprobación |
| **Fichaje desde el móvil o desde casa** | El acto de fichar exige presencia física ante la tablet: es lo que da fiabilidad al registro |
| **Geolocalización** | No se rastrea la ubicación de nadie |
| **Reconocimiento facial o huella** | Descartado por decisión de diseño |
| **Abrir puertas y tornos** | No hay integración con control de accesos físicos |
| **QR en el teléfono del empleado** | No es funcionalidad del producto. Se estudiaría como desarrollo a medida si un cliente lo pidiera expresamente |

---

## 9. La tecnología, explicada

### 9.1 En qué consiste la instalación

```
        En el hotel                              En el servidor del hotel
  ┌──────────────────────┐              ┌──────────────────────────────────┐
  │  Tablet en la        │              │   Aplicación (fichajes, reglas)  │
  │  entrada de personal │─── wifi ────►│   Base de datos (el registro)    │
  │  (la app de fichaje) │              │   Procesos automáticos           │
  └──────────────────────┘              │   Informes y exportaciones       │
                                        │   Panel de monitorización        │
  ┌──────────────────────┐              └──────────────────────────────────┘
  │  Navegador de RRHH   │───────────────────────────▲
  │  y de responsables   │                           │
  └──────────────────────┘                           │
                                                     │
  ┌──────────────────────┐                           │
  │  Navegador o móvil   │───────────────────────────┘
  │  del empleado        │
  └──────────────────────┘
```

**Todo corre en el servidor del hotel.** No hay ningún componente alojado por el fabricante, y el sistema **funciona íntegramente sin conexión a internet**.

Sin internet solo se pierden tres cosas accesorias: los certificados de seguridad automáticos (se usa uno propio del hotel), el envío de correos si el servidor de correo es externo, y la telemetría, que de todas formas viene desactivada.

### 9.2 Las piezas y por qué se eligieron

| Pieza | Qué es | Por qué esta y no otra |
|---|---|---|
| **PHP 8.4 + Laravel 12** | El lenguaje y el marco de trabajo del servidor | Tecnología madura y muy extendida: el hotel puede encontrar quien la mantenga sin depender de un único proveedor |
| **PostgreSQL 17** | La base de datos donde vive el registro | Es la única opción capaz de garantizar **en la propia base de datos** que un empleado no pueda tener dos turnos abiertos ni dos tramos solapados. No depende de que el programa esté bien escrito: la base de datos lo impide |
| **Redis** | Memoria rápida auxiliar | Gestiona las tareas en segundo plano (informes, PDF, envíos) y los límites de tasa sin cargar la base de datos |
| **Vue 3 + TypeScript** | Las tres aplicaciones de pantalla | Ligero y rápido en tablets modestas. TypeScript detecta errores antes de que lleguen a producción, algo que importa cuando se manipulan horas |
| **Lector QR por cámara** | La lectura de la tarjeta | Decodifica en menos de 0,3 segundos y da control sobre el enfoque y la linterna de la tablet |
| **Base de datos local en la tablet** | El modo sin conexión | Guarda los fichajes con garantías transaccionales: nada se pierde aunque la tablet se apague de golpe |
| **WebSocket** | El "en tiempo real" del panel | La pantalla de presencia se actualiza al instante. Si el canal cae, pasa automáticamente a consultar cada 15 segundos |
| **Docker** | El empaquetado | Todo el sistema se instala con un único comando, sin montar servidores pieza a pieza |
| **Prometheus + Grafana** | Monitorización | Cuadros de mando del estado del sistema y de las tablets, alertas con destinatario y procedimiento asociado |

### 9.3 Tres aplicaciones distintas

1. **La app del quiosco** — la que corre en la tablet. Instalable, a pantalla completa, con la pantalla siempre encendida, funciona sin conexión, con textos grandes, botones amplios, alto contraste y avisos sonoros. Operable con una mano, con guantes y con poca luz. En español e inglés de serie, ampliable a otros idiomas.
2. **El panel de gestión** — para responsables, RRHH, auditores y administración.
3. **El portal del empleado** — una web sencilla y responsive para consultar el registro propio.

### 9.4 Objetivos de rendimiento comprometidos

| Aspecto | Objetivo |
|---|---|
| De pasar la tarjeta a ver la confirmación | Menos de 0,8 segundos en el 95 % de los casos |
| Fichajes simultáneos soportados | 50 por segundo (el pico del cambio de turno) |
| Disponibilidad del sistema | 99,5 % mensual |
| **Disponibilidad del acto de fichar** | **99,9 %**, gracias al modo sin conexión |
| Pérdida máxima de datos ante un desastre | 15 minutos |
| Tiempo máximo de recuperación | 4 horas |
| Carga del panel de presencia con 500 empleados | Menos de 1,5 segundos |

### 9.5 Cómo se garantiza que el cálculo de horas es correcto

Es la pregunta que más debería importar, porque de esos números salen las nóminas:

- **Más de 600 pruebas automáticas** se ejecutan cada vez que se toca una línea de código. Cerca de 400 de ellas se dedican en exclusiva a las reglas de cálculo del tiempo trabajado.
- Hay pruebas específicas para los casos que rompen a los sistemas genéricos: **turnos que cruzan medianoche**, **los dos cambios de hora del año** en ambos sentidos, tramos de duración extrema y fichajes que llegan desordenados desde la cola sin conexión.
- Se prueba que **treinta personas fichando a la vez** no produzcan duplicados ni descuadres.
- Se prueba el ciclo completo **sin red → reconexión → sincronización → totales**, con una tablet real y una cámara simulada que lee un QR de verdad.
- Se prueba que la base de datos **rechaza por sí misma** un solape o un segundo turno abierto, aunque se intente forzar por SQL directo.
- Se prueba que **restaurar una copia de seguridad funciona**, no que se hizo.

Nada se publica sin que todas estas comprobaciones estén en verde.

---

## 10. Instalación, licencia y soporte

### 10.1 Qué hace falta

| Recurso | Mínimo (hasta 100 empleados) | Recomendado (hasta 500) |
|---|---|---|
| CPU | 2 núcleos | 4 núcleos |
| Memoria | 4 GB | 8 GB |
| Disco | 40 GB SSD | 100 GB SSD |
| Sistema | Linux con Docker | Íd. |
| Red | Acceso desde la red interna del hotel. Salida a internet **opcional** | Íd. |

Y por cada punto de fichaje: una **tablet Android** con soporte de pared o mesa, gestionada en modo quiosco, y wifi con cobertura razonable en esa zona.

> **Qué es el "modo quiosco".** Es una función estándar de Android, no algo propio de KronoQR: la tablet queda **fijada en una sola aplicación**. No muestra escritorio, no deja salir a los ajustes ni a otras apps, y vuelve sola a la pantalla de fichaje si se reinicia o se va la luz. Se configura una vez, al montar la tablet, y es lo que evita que el dispositivo acabe usado para ver vídeos o que alguien salga de la aplicación sin querer y el siguiente empleado no encuentre dónde fichar.

Un servidor de estas características cubre 500 empleados y 10 tablets con holgura. El diseño soporta diez veces ese volumen sin cambios.

### 10.2 Puesta en marcha

1. **Instalación**: un script comprueba que el servidor cumple los requisitos, genera las claves de seguridad **en el propio servidor del hotel** (nunca se transmiten), arranca el sistema y verifica que todo funciona.
2. **Asistente inicial**: datos de la organización, centros, departamentos, zona horaria, perfil de convenio, primer administrador y vinculación de la primera tablet.
3. **Vinculación de tablets**: la tablet muestra un código, se introduce en el panel, y queda emparejada. Sin comandos ni configuración manual.
4. **Carga de plantilla**: importación desde CSV o Excel, con validación previa.
5. **Emisión e impresión de tarjetas**, y entrega registrada.

> **Recomendación de proceso:** emitir e imprimir las tarjetas **con días de antelación** al primer día de trabajo de cada incorporación. El panel de estado de credenciales existe precisamente para que nadie descubra el problema delante de la tablet a las 06:00.

### 10.3 Configuración sin tocar el código

Todo lo que puede variar entre hoteles es **configuración**, no programación:

- **Marca blanca**: logotipo, colores y nombre de la aplicación, aplicados a la tablet, al panel, al portal y a las tarjetas y documentos PDF.
- **Perfil de cumplimiento**: años de retención, descanso mínimo entre jornadas, jornada máxima diaria y semanal, pausas obligatorias, inicio de semana y calendario de festivos. **Se entrega el perfil español de hostelería ya configurado**, y se puede ajustar si el convenio aplicable difiere.
- **Umbrales operativos**: margen anti-doble-escaneo, horas a partir de las cuales un turno se considera anómalo, tolerancia de desviación de reloj, intentos de PIN antes del bloqueo.
- **Idiomas** activos y funcionalidades habilitadas.

Esto significa que una petición de ajuste del hotel no requiere una versión especial del producto, y que las actualizaciones siempre le llegan al mismo tiempo que a los demás.

### 10.4 Actualizaciones

Un script se encarga de: verificar precondiciones → **hacer copia de seguridad completa y verificarla** (si esto falla, no continúa) → aplicar los cambios → comprobar que todo funciona → y **volver atrás automáticamente si algo sale mal**.

Se admite el salto entre versiones no consecutivas: si el hotel lleva tiempo sin actualizar, el sistema encadena los pasos intermedios en orden.

**Durante la actualización el fichaje no se detiene**: la tablet sigue registrando y encolando, y sincroniza cuando el servidor vuelve. La parada de mantenimiento es invisible para la plantilla.

### 10.5 Licencia

Una clave firmada que codifica el cliente, el plan contratado, los límites (centros, empleados y tablets) y la vigencia del soporte. **Se verifica localmente, sin llamar a internet**, porque el servidor del hotel puede estar en una red aislada y la conectividad del fabricante no puede convertirse en un punto único de fallo del registro horario.

Y, como se dijo en el punto 4.7: **al caducar, el fichaje y el acceso al registro legal siguen funcionando**.

### 10.6 Soporte y diagnóstico

Cuando hay una incidencia, el administrador del hotel genera con un clic un **paquete de diagnóstico**: versión, configuración sin contraseñas, estado de los servicios, últimos errores, salud de las tablets y comprobaciones internas. **Sin datos personales, sin nombres, sin registros de jornada.** Se envía al soporte y este lo analiza.

Hay además un comando de **revisión de salud** que valida base de datos, colas, correo, certificados, permisos y espacio en disco, y devuelve un informe con lo que hay que corregir.

### 10.7 Quién hace qué

| Tarea | Hotel | Fabricante |
|---|---|---|
| Servidor, red y certificados | ✅ | Guía y requisitos publicados |
| Instalación y actualización | ✅ | Scripts y documentación |
| Copias de seguridad y su verificación | ✅ | Herramientas y alerta automática si fallan |
| Configuración y perfil de convenio | ✅ | Perfil español entregado de serie |
| Empleados, impresión y entrega de tarjetas | ✅ | Generador de PDF |
| Responsable del tratamiento de datos | ✅ | — |
| Corrección de defectos del producto | — | ✅ |
| Diagnóstico de incidencias | Genera el paquete | Lo analiza |
| Acceso a los datos del hotel | — | Solo con autorización expresa y temporal |

Este reparto se incluye en el contrato y en la documentación entregada. La mayoría de los conflictos de soporte en este tipo de productos nacen de que nunca se puso por escrito.

### 10.8 Documentación entregada

Cuatro manuales: **instalación** (para el informático), **operación** (copias, actualizaciones, incidencias frecuentes), **configuración** (cada parámetro y qué hace) y **obligaciones legales** (qué le corresponde al hotel como empresa).

---

## 11. Evolución prevista

El sistema entra en producción cubriendo todo lo anterior. Están contemplados como evolución posterior, a decidir con datos de uso reales:

- Planificación de cuadrantes y comparación entre lo planificado y lo realmente trabajado.
- Gestión de vacaciones y permisos con flujo de aprobación.
- Informes avanzados y consolidación entre varios centros de una cadena.

El modelo de datos ya contempla varios centros desde el primer día, aunque se despliegue con uno solo.

---

## 12. Preguntas frecuentes

**¿Qué pasa si un empleado pierde la tarjeta?**
Ficha con su PIN mientras tanto, RRHH revoca la anterior —que deja de funcionar al instante— e imprime una nueva el mismo día.

**¿Qué pasa si se cae la wifi en pleno cambio de turno?**
No se nota. La tablet sigue confirmando fichajes y los envía cuando vuelve la conexión, conservando la hora real.

**¿Qué pasa si alguien se olvida de fichar la salida?**
A las 12 horas se genera una incidencia y se avisa al responsable, que la corrige indicando el motivo. **El sistema nunca inventa una hora de salida por su cuenta.**

**¿Puede un empleado fichar por otro?**
No puede *falsificar* una tarjeta: es criptográficamente inviable. Sí puede prestarle la suya físicamente, pero entonces se queda sin ella y no puede fichar él. El sistema detecta patrones anómalos (dos fichajes seguidos en el mismo quiosco, coincidencias sistemáticas entre dos personas) y los pone sobre la mesa.

**¿Los datos salen del hotel?**
No. Todo está en el servidor del hotel. El fabricante no aloja ni accede a nada salvo autorización expresa, temporal y registrada.

**¿Se puede consultar sin conexión a internet?**
Sí. El sistema completo funciona en la red interna del hotel sin salida a internet.

**¿Qué pasa si dejamos de trabajar con el proveedor?**
El registro sigue funcionando y el hotel puede exportar la totalidad de sus datos en formato abierto, por sí mismo, para seguir cumpliendo su obligación de conservarlos cuatro años.

**¿Puedo cambiar los umbrales de mi convenio?**
Sí, son configuración: descanso mínimo, jornada máxima, pausas y retención se ajustan desde el panel, sin tocar el programa.

**¿Cuántas tablets necesito?**
Una por punto de acceso de personal. El sistema admite hasta 10 por centro en el dimensionado estándar.

**¿Y si mi hotel tiene una infraestructura que exige otra base de datos?**
Existe una variante documentada, pero conviene saber que con ella una garantía de integridad deja de estar en la base de datos y pasa a depender del programa. La recomendación es mantener PostgreSQL.

---

## 13. Diccionario rápido

| Término | Significado |
|---|---|
| **Quiosco** | La tablet donde se ficha |
| **Modo quiosco** | Ajuste estándar de Android que fija la tablet en una sola aplicación: sin escritorio, sin ajustes y con arranque automático en la pantalla de fichaje |
| **Fichaje / escaneo** | El acto de pasar la tarjeta. Queda registrado siempre, se acepte o no |
| **Tramo** | Un par entrada-salida. La unidad de tiempo trabajado |
| **Jornada** | El conjunto de tramos de un día para un empleado |
| **Incidencia** | Una situación detectada que necesita que alguien decida qué hacer |
| **Corrección** | Un cambio hecho por una persona autorizada, siempre motivado y trazado |
| **Credencial** | La tarjeta QR de un empleado. Revocable y reemitible |
| **Portal personal** | La web donde cada empleado consulta sus propias horas |
| **Traza de auditoría** | El registro inalterable de todo lo que ha pasado y quién lo hizo |
| **Perfil de cumplimiento** | El conjunto de umbrales legales configurables del hotel |

---

*Documento de presentación comercial. Los aspectos legales aquí descritos reflejan cómo el producto facilita el cumplimiento normativo y deben ser validados por la asesoría laboral del cliente junto con el convenio colectivo aplicable.*
