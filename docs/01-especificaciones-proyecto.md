# Especificaciones del Producto
## KronoQR — Sistema de Control de Presencia y Registro Horario por QR · Sector Hotelero

| Campo | Valor |
|---|---|
| **Producto** | **KronoQR** — fichaje de empleados mediante QR en quiosco (tablet) |
| **Modelo de negocio** | Producto licenciado, desplegado en servidores del cliente, vendible a múltiples hoteles |
| **Fecha** | 11 de agosto de 2026 |
| **Clasificación** | Documentación técnica interna |
| **Audiencia** | Product Owner, Arquitectura, Desarrollo, QA, DPO, Dirección de RRHH |
| **Documentos hermanos** | `02-stack-tecnologico-y-plan-implementacion.md`, `03-agentes-y-skills-ia.md`, `04-decision-credencial.md`, `05-presentacion-cliente.md` |

> **Nomenclatura.** *KronoQR* es el nombre comercial y el que ve el cliente (documento 05). Los identificadores técnicos internos —prefijo `FH1` del payload QR, nombres de servicios, rutas de copias— se mantienen tal cual: no son visibles para el usuario y renombrarlos rompería credenciales ya emitidas. El nombre de la aplicación que se muestra en pantalla es configuración de marca (RF-PD-08), no una constante.

---

## 0. Resumen ejecutivo

Aplicación web de control de presencia mediante lectura de código QR en tablet. Los empleados registran el inicio y el fin de sus turnos escaneando una credencial QR personal en un quiosco compartido. El sistema calcula automáticamente el tiempo trabajado, admite múltiples tramos por jornada y produce el registro horario que la legislación exige conservar.

Se comercializa como **producto licenciado**: el mismo software se vende a hoteles distintos y se despliega en los servidores de cada uno. No hay SaaS ni infraestructura operada por el fabricante.

### 0.1 Las tres premisas que gobiernan el diseño

**1. Esto no es un CRUD, es un registro con valor probatorio.** En España el registro de jornada es una obligación legal (art. 34.9 del Estatuto de los Trabajadores). El registro debe ser diario, fiable, **inalterable**, conservado **4 años** y accesible a la persona trabajadora, a la representación legal y a la Inspección de Trabajo. De ahí derivan la inmutabilidad, la trazabilidad de correcciones, la retención controlada y la exportación normalizada. No son mejoras de calidad: son el requisito.

**2. El fraude por préstamo de credencial es el modelo de amenaza real.** No lo es el atacante externo. El payload del QR va firmado criptográficamente para impedir que nadie fabrique la credencial de un compañero, y el préstamo físico se combate con supervisión y auditoría de patrones anómalos.

**3. El producto no puede presuponer nada sobre la plantilla del comprador.** Un hotel boutique de 20 personas y un resort con 150 personas en pisos son clientes distintos. Todo lo que difiera entre clientes —marca, umbrales de convenio, idiomas, funcionalidades— es configuración, nunca código.

### 0.2 Implicaciones del modelo licenciado

| Ámbito | Implicación |
|---|---|
| **Arquitectura** | Sin multi-tenencia. Cada cliente tiene su despliegue completo, así que el aislamiento entre clientes es físico y gratuito. |
| **Configurabilidad** | Nada específico de un cliente vive en el código. Vender a un cliente nuevo no puede exigir tocar el repositorio ni mantener una rama. |
| **Marco legal del fabricante** | El fabricante **no es encargado del tratamiento**: no aloja ni opera los datos. Solo los trata durante intervenciones de soporte, lo que exige un contrato de encargo acotado a ese supuesto. El cliente es responsable del tratamiento y operador del sistema. |
| **Operación** | El fabricante no puede entrar a arreglar una incidencia de producción. El sistema debe ser diagnosticable por el propio cliente: paquete de diagnóstico exportable, registros legibles y errores accionables. |
| **Ciclo de vida** | Habrá N instalaciones en M versiones distintas. Exige matriz de versiones soportadas, actualizaciones entre versiones no consecutivas y migraciones reversibles. |

### 0.3 La credencial es una tarjeta física

La credencial QR se entrega **impresa y plastificada**. Es la única modalidad del producto.

El motivo es la cobertura: la tarjeta funciona para el 100 % de la plantilla, y en hostelería el móvil deja fuera a demasiada gente —personal de temporada sin correo corporativo, cocinas y pisos donde el teléfono está prohibido durante el servicio, uniformes sin bolsillos—. Cada persona que no puede fichar por el canal previsto genera una corrección manual, y las correcciones manuales son justamente lo que erosiona el valor probatorio del registro.

El análisis completo, incluidas las ventajas reales de la alternativa móvil y las condiciones en que merecería la pena, está en [`04-decision-credencial.md`](04-decision-credencial.md). **La credencial en móvil no es funcionalidad prevista**: es un desarrollo a medida que se evaluaría si un cliente concreto lo solicita.

El **PIN de respaldo** cubre el fallo del soporte —tarjeta olvidada, perdida o deteriorada— para que nunca se traduzca en una jornada sin registro.

---

## 1. Contexto de negocio

### 1.1 El sector

Establecimientos hoteleros con plantilla en régimen de turnos rotatorios. Características que condicionan el diseño:

- **Turnos nocturnos** que cruzan la medianoche (recepción de noche, seguridad).
- **Jornada partida** habitual en restauración → varios tramos por día.
- **Alta rotación y personal eventual** en temporada alta.
- **Departamentos diferenciados** (Recepción, Pisos, Cocina, Sala, Mantenimiento, SPA) con responsables distintos.
- **Un centro por instalación** ([ADR-040](adr/ADR-040-un-centro-por-instalacion-y-por-licencia.md)): una licencia es un hotel. Una cadena opera una instalación —y una licencia— por hotel; el centro existe como entidad porque tiene zona horaria y convenio, pero nadie lo elige en ninguna operación.
- **Plantilla multilingüe** → la interfaz del quiosco debe ser multiidioma.
- **Convenio colectivo de hostelería** con cómputo anual de horas y pluses.

### 1.2 Actores del sistema

| Actor | Descripción | Canal |
|---|---|---|
| **Empleado** | Ficha entrada y salida presentando su tarjeta QR. Consulta sus propias horas. | Quiosco (tablet) + portal personal |
| **Responsable de departamento** | Ve presencia en vivo de su equipo, valida incidencias, corrige fichajes de su ámbito. | Panel de administración |
| **Administrador de RRHH** | Alta y baja de empleados, contratos, credenciales, informes globales, exportaciones legales. | Panel de administración |
| **Auditor / Inspección de Trabajo** | Acceso de solo lectura a registros y trazas. Exportación normalizada. | Panel (rol restringido) + exportación |
| **Administrador de la instalación** | Personal de IT del cliente. Instala, actualiza, configura y respalda el sistema. No es un rol de negocio. | Consola del servidor + panel de configuración |
| **Soporte del fabricante** | Diagnostica incidencias a partir del paquete exportado por el cliente. Solo accede a datos con autorización expresa, temporal y auditada. | Paquete de diagnóstico |
| **Quiosco (tablet)** | Actor no humano. Cliente autenticado por dispositivo. | API |
| **Sistema (scheduler)** | Consolidaciones, detección de incidencias, retención, copias de seguridad. | Interno |

### 1.3 Objetivos y métricas de éxito

Estas métricas **no son una hoja de cálculo aparte**: el propio sistema las calcula y las presenta (RF-IN-08). Un objetivo que nadie mide no se cumple, y pedirle a RRHH que lo mida a mano contradice el objetivo de reducir su carga administrativa.

| Objetivo | Métrica | Objetivo a 3 meses de producción |
|---|---|---|
| Cumplimiento legal del registro de jornada | % de jornadas con registro completo | ≥ 99 % |
| Eliminar el registro manual en papel | % de fichajes por QR sobre el total | ≥ 98 % |
| Reducir la carga administrativa de RRHH | Horas/mes consolidando hojas de horas | −80 % |
| Fiabilidad del quiosco | Disponibilidad del flujo de fichaje (incluye modo offline) | ≥ 99,9 % |
| Detección temprana de incidencias | Tiempo medio hasta resolver un turno sin cerrar | < 24 h |
| Confianza en el dato | Correcciones manuales / total de fichajes | < 2 % |

---

## 2. Alcance

### 2.1 Dentro del alcance

- Registro de entrada y salida por escaneo de QR en quiosco compartido, con PIN de respaldo.
- Múltiples tramos por jornada.
- Cálculo y consolidación de tiempo trabajado por jornada, semana, mes y periodo arbitrario.
- Gestión de empleados, departamentos, centros y contratos.
- Generación, impresión, entrega y revocación de credenciales QR en tarjeta.
- Panel de administración con vista de presencia en tiempo real.
- Corrección manual de fichajes con motivo obligatorio y traza de auditoría.
- Detección automática de incidencias.
- Informes semanales y mensuales exportables, y exportación normalizada para Inspección.
- Funcionamiento **offline-first** del quiosco con sincronización diferida.
- Portal personal del empleado para consultar su propio registro.
- Auditoría inmutable, retención de 4 años y purga controlada.
- Observabilidad, alertas y monitorización operativa.
- **Productización**: configuración sin código, licencia, instalación autónoma, actualización asistida, marca blanca y herramientas de diagnóstico.

### 2.2 Fuera del alcance

- **Credencial QR en dispositivo móvil.** Evaluada y descartada como funcionalidad del producto (ver [`04-decision-credencial.md`](04-decision-credencial.md)). Se estudiaría como desarrollo a medida si un cliente lo solicita.
- **QR dinámico TOTP.** Depende de la credencial en móvil, por lo que queda igualmente fuera.
- Cálculo de nómina, pluses y complementos salariales. Se **exporta** a la herramienta de nómina, no se calcula.
- Planificación y cuadrantes de turnos. Contemplado como evolución; el modelo de datos deja la puerta abierta.
- Gestión de vacaciones y permisos con flujo de aprobación. Se registran ausencias para no falsear informes.
- Geolocalización y fichaje en movilidad. El acto de fichar exige presencia física ante el quiosco, que es lo que da fiabilidad al registro.
- Reconocimiento facial o cualquier otro dato **biométrico**. Descartado explícitamente por proporcionalidad en control horario (ver §7.4).
- Integración con control de accesos físicos (tornos, cerraduras).

### 2.3 Supuestos

- Plantilla ≤ 500 empleados y ≤ 10 quioscos por instalación. El diseño escala a 5.000 sin cambio arquitectónico.
- Existe red interna en el centro, con posibilidad de cortes intermitentes.
- La tablet es propiedad de la empresa y está gestionada en **modo quiosco** (MDM o *device owner* de Android Enterprise): fijada a una sola aplicación, sin acceso al escritorio, a los ajustes ni a otras apps, con arranque automático de la PWA tras un reinicio o un corte de luz, y actualizaciones del sistema en ventana controlada. Es configuración del dispositivo, a cargo del cliente, no una funcionalidad del producto.
- El cliente dispone de un servidor propio (físico o VPS) con Docker, y de personal de IT capaz de seguir una guía de instalación. No se asume experiencia en Laravel ni en PostgreSQL.
- **No se presupone nada sobre el perfil de la plantilla del cliente.** Ni correo electrónico corporativo, ni smartphone, ni alfabetización digital.

---

## 3. Requisitos funcionales

Nomenclatura: `RF-<módulo>-<nº>`. Prioridad MoSCoW: **M**ust / **S**hould / **C**ould.

### 3.1 Módulo: Fichaje (Attendance)

| ID | Requisito | Prio |
|---|---|---|
| RF-AT-01 | El sistema registra un **evento de fichaje** al escanear un QR válido en un quiosco autenticado. | M |
| RF-AT-02 | Si el empleado **no tiene turno abierto**, el escaneo abre un turno (entrada). | M |
| RF-AT-03 | Si el empleado **tiene un turno abierto**, el escaneo lo cierra (salida) y calcula la duración. | M |
| RF-AT-04 | El sistema admite **N tramos por jornada** sin límite configurado (jornada partida). | M |
| RF-AT-05 | El quiosco muestra confirmación con nombre, acción realizada, hora y total acumulado del día. Feedback **visual y sonoro** diferenciado para entrada, salida y error. | M |
| RF-AT-06 | El sistema aplica un **periodo de gracia anti-rebote** configurable (por defecto 60 s): un segundo escaneo del mismo empleado dentro de la ventana no crea evento y muestra aviso informativo. | M |
| RF-AT-07 | Los fichajes son **idempotentes**: un mismo `scan_id` procesado dos veces produce un único evento y la misma respuesta. | M |
| RF-AT-08 | Un turno puede **cruzar la medianoche** sin ser dividido artificialmente. Se atribuye a la jornada laboral de su hora de inicio. | M |
| RF-AT-09 | El sistema registra siempre **dos marcas de tiempo**: `occurred_at` (momento real del escaneo, incluso offline) y `recorded_at` (momento de recepción en servidor). | M |
| RF-AT-10 | **Control de desfase de reloj.** Si el reloj del dispositivo diverge del servidor por encima del umbral configurado (por defecto 15 min), el fichaje **se acepta igualmente** y se registra con la hora del dispositivo, se marca como incidencia `clock_skew` para revisión del responsable y se avisa en el quiosco. **Nunca se rechaza un fichaje por desfase de reloj**: hacerlo dejaría una jornada sin registrar por un problema técnico ajeno al empleado. | S |
| RF-AT-11 | **Entrada alternativa por PIN de 6 dígitos** cuando el empleado no puede presentar su tarjeta. Misma traza, marcada como `origen = PIN` y señalada para revisión del responsable. | M |
| RF-AT-12 | El sistema soporta **fichaje de pausa** (inicio y fin de descanso) diferenciado del fin de turno, configurable por centro. | S |

> **RF-AT-11 no es un extra.** Es lo que impide que una tarjeta olvidada se convierta en una jornada sin registro y en una corrección manual.

### 3.2 Módulo: Credenciales QR

| ID | Requisito | Prio |
|---|---|---|
| RF-QR-01 | Cada empleado tiene una credencial QR con **payload opaco y firmado criptográficamente** (HMAC), sin PII ni identificadores secuenciales. | M |
| RF-QR-02 | El sistema **valida la firma** del payload antes de resolver el empleado. Firma inválida → rechazo genérico sin distinguir causa. | M |
| RF-QR-03 | Las credenciales son **revocables y reemitibles** (pérdida, robo, deterioro, baja) con invalidación inmediata de la anterior. | M |
| RF-QR-04 | Generación de **tarjetas imprimibles en PDF**: formato tarjeta de crédito (85,6 × 54 mm) y hoja A4 con varias por página. Incluyen nombre, departamento, centro y QR. Emisión individual y masiva. | M |
| RF-QR-05 | El QR se genera con **corrección de errores nivel Q** y tamaño mínimo garantizado, para tolerar el desgaste de una tarjeta en uso diario durante una temporada. | M |
| RF-QR-06 | **Registro de entrega**: RRHH marca la tarjeta como entregada, con fecha y responsable. Queda auditado. | M |
| RF-QR-07 | Soporte de **rotación de la clave de firma** con periodo de solape (`key_id` en el payload), que permite reimprimir progresivamente sin invalidar toda la plantilla de golpe. | S |
| RF-QR-08 | **Panel de estado de credenciales**: quién la tiene emitida, pendiente de imprimir, pendiente de entregar o revocada. Sin esto, RRHH no sabe quién puede fichar el primer día. | M |

### 3.3 Módulo: Presencia y panel de administración

| ID | Requisito | Prio |
|---|---|---|
| RF-PA-01 | Vista **en tiempo real** de empleados actualmente fichados: nombre, departamento, hora de entrada, tiempo transcurrido, quiosco de origen. Actualización push (WebSocket), no sondeo. | M |
| RF-PA-02 | Filtrado por departamento y estado. Búsqueda por nombre. | M |
| RF-PA-03 | Detalle de jornada por empleado y día: todos los tramos, totales, incidencias y correcciones. | M |
| RF-PA-04 | **Corrección manual** de un fichaje (crear, modificar hora, cerrar turno abierto, anular) con **motivo obligatorio** de un catálogo más texto libre. Nunca sobrescribe: genera versión nueva y entrada de auditoría. | M |
| RF-PA-05 | Bandeja de **incidencias** pendientes con flujo de resolución, asignada al responsable del departamento. | M |
| RF-PA-06 | Vista de **cumplimiento**: alertas de descanso insuficiente entre jornadas, jornada diaria excesiva, ausencia de pausa en jornadas largas y exceso de horas semanales. | S |
| RF-PA-07 | Panel de **salud de quioscos**: último latido, versión de la app, tamaño de la cola offline, nivel de batería. | S |

### 3.4 Módulo: Informes y exportación

| ID | Requisito | Prio |
|---|---|---|
| RF-IN-01 | Informe de horas por empleado con granularidad diaria, semanal, mensual y rango libre. | M |
| RF-IN-02 | Informe agregado por departamento. | M |
| RF-IN-03 | Comparativa **horas trabajadas frente a horas contratadas**, con desviación y exceso de jornada. | M |
| RF-IN-04 | Exportación a **CSV, XLSX y PDF**. Los PDF incluyen sello temporal, identificación del emisor y hash del contenido. | M |
| RF-IN-05 | **Exportación normalizada para Inspección de Trabajo**: registro diario por trabajador y periodo, en formato tabular legible, con las correcciones y sus motivos. | M |
| RF-IN-06 | Generación asíncrona (cola) de informes de gran volumen, con notificación y enlace de descarga caducable. | S |
| RF-IN-07 | Exportación de datos para el sistema de nómina en formato configurable. | S |
| RF-IN-08 | **Cuadro de impacto y adopción.** El sistema calcula y presenta, por periodo y con comparación contra el periodo anterior, los indicadores del §1.3: porcentaje de jornadas con registro completo, reparto de fichajes por origen (QR, PIN, corrección manual), ratio de correcciones sobre el total, incidencias abiertas y tiempo medio hasta resolverlas, empleados sin credencial entregada, y horas trabajadas frente a contratadas. Exportable y accesible por rol `admin` y `rrhh`. | S |

### 3.5 Módulo: Gestión de personas

| ID | Requisito | Prio |
|---|---|---|
| RF-GP-01 | CRUD de empleados: datos identificativos mínimos, departamento, fecha de alta y baja. El centro es el de la instalación y no se elige (ADR-040). **El correo electrónico es opcional**: el producto no depende de él. | M |
| RF-GP-02 | Registro de **contrato**: horas semanales y anuales contratadas, tipo de jornada, vigencia. Historizado. | M |
| RF-GP-03 | Baja de empleado: **desactivación lógica**, nunca borrado. El registro histórico debe conservarse 4 años. | M |
| RF-GP-04 | Registro de **ausencias** (vacaciones, baja médica, permiso) para no contabilizar como absentismo no justificado. Carga manual o CSV. | S |
| RF-GP-05 | Importación masiva inicial de plantilla desde CSV o XLSX, con validación previa y modo simulación. | S |

### 3.6 Módulo: Identidad y acceso

| ID | Requisito | Prio |
|---|---|---|
| RF-ID-01 | Autenticación de usuarios de gestión con contraseña, política de robustez, bloqueo por intentos y **2FA (TOTP) obligatorio para roles con acceso a datos de toda la plantilla**. | M |
| RF-ID-02 | Modelo **RBAC** con roles: `admin`, `rrhh`, `responsable_departamento`, `auditor`, `empleado`, `kiosk`. | M |
| RF-ID-03 | Autorización a nivel de recurso: un responsable solo accede a los empleados de su departamento. | M |
| RF-ID-04 | El quiosco se autentica con **token de dispositivo** de ámbito restringido (solo endpoints de fichaje y sincronización), revocable individualmente y rotable. | M |
| RF-ID-05 | **Portal personal del empleado**: consulta de su propio registro y descarga de su histórico. Es una **exigencia legal** (RL-05), no una funcionalidad opcional. | M |
| RF-ID-06 | El empleado accede al portal con su **código de empleado y su PIN**, el mismo del respaldo del quiosco. No requiere correo electrónico. Rate limiting agresivo y bloqueo temporal por intentos fallidos. | M |
| RF-ID-07 | La sesión del portal tiene ámbito `self:read`: solo permite **leer** los datos del propio empleado. Nunca datos de terceros y nunca escritura sobre el registro. | M |
| RF-ID-08 | El portal es accesible **desde la red interna por defecto**. Exponerlo a internet es una decisión explícita del cliente que activa requisitos adicionales de contraseña. | M |
| RF-ID-09 | **Provisión y restablecimiento del PIN.** El sistema genera un PIN aleatorio de 6 dígitos al dar de alta al empleado, permite a RRHH restablecerlo y registra la entrega —fecha y responsable— igual que la credencial. El PIN **nunca se almacena en claro** ni se muestra dos veces: se enseña una sola vez para su entrega y después solo puede restablecerse. Emisión, entrega y restablecimiento quedan en `audit_log`. | M |

> **Por qué RF-ID-09 existe y por qué el canal de entrega importa.** El PIN sostiene dos cosas de las que el producto no puede prescindir: el fichaje de respaldo cuando falta la tarjeta (RF-AT-11) y el acceso al portal personal, que es **exigencia legal** (RL-05). Sin un requisito que lo provea, ambas quedan sin puerta de entrada.
>
> **No se imprime en la tarjeta.** Es la solución que se elige sola si nadie decide, y anula el respaldo: quien pierde la tarjeta perdería a la vez la credencial y su alternativa, que es justo el escenario que RF-AT-11 existe para cubrir. Se entrega en documento aparte, con su propio registro de entrega.

> **Por qué código y PIN en lugar de correo y contraseña (RF-ID-06).** El producto no puede exigir correo electrónico a toda la plantilla. El PIN ya existe como respaldo del quiosco, así que reutilizarlo elimina una credencial que gestionar. El riesgo de un PIN de 6 dígitos se compensa con bloqueo por intentos, acceso restringido a la red interna y un ámbito que solo alcanza los datos propios del empleado.

### 3.7 Módulo: Quiosco (PWA)

| ID | Requisito | Prio |
|---|---|---|
| RF-KI-01 | PWA instalable, a pantalla completa, con *wake lock* para evitar suspensión. | M |
| RF-KI-02 | Escaneo continuo por cámara sin interacción del usuario. | M |
| RF-KI-03 | **Modo offline**: cola local persistente (IndexedDB) de escaneos, con resolución local del empleado contra un padrón cacheado y cifrado, y confirmación provisional al usuario. | M |
| RF-KI-04 | Sincronización automática al recuperar conexión, con reintentos y *backoff* exponencial. Indicador visible de estado de conexión y elementos pendientes. | M |
| RF-KI-05 | Multiidioma (mínimo español e inglés, extensible) con selector persistente y detección automática. | M |
| RF-KI-06 | Accesibilidad: contraste AA, tipografía ≥ 24 px en mensajes de confirmación, mensajes también sonoros. | M |
| RF-KI-07 | Actualización de la app controlada: no se actualiza durante un cambio de turno; ventana de actualización configurable. | S |
| RF-KI-08 | Pantalla de diagnóstico accesible con código de servicio: estado de cámara, red, cola, token y versión. | S |
| RF-KI-09 | Aviso de privacidad visible en la pantalla del quiosco (información del art. 13 RGPD en capa 1). | M |

### 3.8 Módulo: Procesos automáticos

| ID | Requisito | Prio |
|---|---|---|
| RF-PR-01 | Detección de **turnos abiertos anómalos** superadas N horas (por defecto 12). **No se cierran silenciosamente**: se marcan como incidencia y se notifica al responsable. | M |
| RF-PR-02 | Consolidación nocturna y **reconciliación** de los agregados diarios contra los eventos origen, con alerta si hay divergencia. | M |
| RF-PR-03 | Purga de datos superado el periodo de retención legal, **con confirmación del responsable** e informe de lo purgado. | M |
| RF-PR-04 | Copia de seguridad diaria cifrada, verificada y con prueba de restauración periódica. | M |
| RF-PR-05 | Resumen semanal por correo al responsable de cada departamento. | S |
| RF-PR-06 | **Detección de patrones anómalos de uso de credencial**: dos fichajes consecutivos en el mismo quiosco separados por segundos, coincidencias sistemáticas entre dos empleados y secuencias imposibles. Genera incidencia de tipo `anomalous_pattern` para revisión humana. **Nunca decide por sí misma que ha habido fraude**: aporta el indicio y lo pone sobre la mesa del responsable. | S |

> **Por qué RF-PR-06 es un requisito y no una nota de la sección de amenazas.** El préstamo físico de la tarjeta es el único fraude que la firma HMAC no impide (§8.1), y la detección de patrones es la contrapartida explícita del descarte de la biometría (§7.4, ADR-009). Sin un requisito con dueño, umbral y bandeja donde aterrizar, esa mitigación no existe en el producto.

### 3.9 Módulo: Producto, licencia y soporte

| ID | Requisito | Prio |
|---|---|---|
| RF-PD-01 | **Cero configuración en código.** Marca, textos, umbrales, reglas de convenio, idiomas y funcionalidades activas son datos. Vender a un cliente nuevo no puede requerir tocar el repositorio ni mantener una rama. | M |
| RF-PD-02 | **Instalación autónoma**: el personal de IT del cliente despliega el sistema siguiendo una guía, sin intervención del fabricante. Instalador con comprobación previa de requisitos. | M |
| RF-PD-03 | **Asistente de puesta en marcha**: datos de la organización, el centro de trabajo y su zona horaria, departamentos, perfil de convenio, primer administrador y vinculación del primer quiosco. | M |
| RF-PD-04 | **Licencia con clave firmada** que codifica cliente, plan, límites (empleados, quioscos) y vigencia del soporte. Una licencia es un centro (ADR-040). Verificación **local, sin llamada a internet**. | M |
| RF-PD-05 | **Degradación honesta al expirar la licencia.** El sistema **sigue registrando fichajes y permitiendo el acceso a los registros legales**. Se muestran avisos y se bloquean funcionalidades accesorias, nunca el registro. | M |
| RF-PD-06 | **Vinculación de quiosco por código de emparejamiento** mostrado en la tablet e introducido en el panel. El cliente no tiene por qué usar SSH. | M |
| RF-PD-07 | **Perfiles de cumplimiento configurables**: jurisdicción, años de retención, descanso mínimo entre jornadas, jornada máxima diaria y semanal, pausas obligatorias, inicio de semana y calendario de festivos. Se entrega el perfil español de serie. | M |
| RF-PD-08 | **Marca blanca**: logotipo, colores y nombre de la aplicación configurables, aplicados al quiosco, al panel, al portal y a las tarjetas y documentos PDF. | S |
| RF-PD-09 | **Paquete de diagnóstico exportable** por el administrador del cliente: versión, configuración sin secretos, estado de servicios, últimos errores, salud de quioscos y comprobaciones internas. **Anonimizado por defecto.** | M |
| RF-PD-10 | **Actualización asistida** con copia de seguridad previa automática y verificada —si la copia falla, la actualización no continúa—, migraciones reversibles, comprobación posterior de salud y **vuelta atrás automática a la copia previa si la comprobación falla**. Debe soportar el salto entre versiones **no consecutivas**. | M |
| RF-PD-11 | **Acceso de soporte del fabricante**: solo con concesión expresa del cliente, con caducidad, alcance limitado y registro en auditoría visible para el cliente. Revocable en cualquier momento. | M |
| RF-PD-12 | **Telemetría opcional y desactivada por defecto**: versión y métricas técnicas agregadas, jamás datos personales ni de jornada. El sistema funciona idénticamente sin ella. | S |
| RF-PD-13 | **Comprobación de salud posinstalación**: un comando que valida base de datos, colas, correo, certificados, permisos y espacio en disco, y devuelve un informe accionable. | S |
| RF-PD-14 | **Exportación íntegra de los datos del cliente** en formato abierto, ejecutable por el propio cliente sin intervención del fabricante. Es su garantía de no quedar atrapado. | M |
| RF-PD-15 | **Histórico de errores en base de datos.** Todo error de aplicación, trabajo de cola, tarea programada o cliente (quiosco, panel, portal) se persiste en la tabla `error_events`, **agrupado por huella** para no multiplicar filas ante un fallo repetido, consultable desde el panel por el administrador de la instalación, filtrable por origen, severidad y periodo, marcable como resuelto, y volcado al paquete de diagnóstico. **Sin datos personales**: `employee_uuid` y `device_id`, nunca nombres, correos ni horas de nadie. Retención 90 días (RL-11). | M |

> **Sobre RF-PD-05.** Bloquear el fichaje por una licencia caducada dejaría al cliente incumpliendo su obligación legal por una acción del fabricante, y le impediría acceder a registros que la ley le obliga a conservar cuatro años. Es inaceptable con independencia de lo que diga el contrato. La palanca comercial son los avisos y las funcionalidades accesorias; **el registro de jornada nunca es rehén de la relación comercial**.

---

## 4. Reglas de negocio del dominio

Estas reglas viven en el **núcleo de dominio** y deben estar cubiertas por pruebas unitarias puras.

> Los umbrales de RN-10, RN-11 y RN-12 provienen del Estatuto de los Trabajadores español, pero **son parámetros del perfil de cumplimiento** (RF-PD-07), no constantes. Lo invariable es la forma de la regla; configurable es el número. RN-01 a RN-09 y RN-13 a RN-15 son estructurales y no se configuran.
>
> **RN-08 y RN-16 son un caso intermedio:** su forma es estructural, pero llevan un umbral configurable que **no proviene del marco normativo** sino de la operación de cada instalación —la duración anómala de un tramo y el tiempo de tránsito entre dos quioscos—. Viven en `installation_settings` (RF-PD-01), no en el perfil de cumplimiento (RF-PD-07). La distinción importa: un umbral legal lo fija la jurisdicción, uno operativo lo fija el hotel.

| ID | Regla |
|---|---|
| RN-01 | Un empleado no puede tener **más de un turno abierto simultáneamente**. Garantizado por invariante de dominio **y** por restricción en base de datos. |
| RN-02 | Los tramos de un mismo empleado **no pueden solaparse** en el tiempo. Garantizado por restricción de exclusión en base de datos. |
| RN-03 | `clocked_out_at` debe ser estrictamente posterior a `clocked_in_at`. |
| RN-04 | Todas las marcas de tiempo se almacenan en **UTC**. El cálculo de jornada y los informes se resuelven en la **zona horaria del centro**. |
| RN-05 | La **jornada laboral** (`work_date`) es la fecha civil, en la zona del centro, del `clocked_in_at` del tramo **que abre la jornada**. Un turno 22:00→06:00 pertenece íntegramente al día de inicio. Los tramos que **continúan** una jornada abierta —la vuelta de una pausa (RF-AT-12)— **heredan su `work_date`** y no abren jornada nueva, aunque empiecen en otro día natural. |
| RN-06 | El total diario **se recalcula** como suma de los tramos de esa jornada dentro de la misma transacción; **nunca se incrementa** de forma acumulativa. Esto lo hace idempotente y correcto ante correcciones. |
| RN-07 | Duración mínima de tramo computable: 1 minuto. Por debajo se registra el evento pero se marca como incidencia. |
| RN-08 | Duración máxima de tramo antes de considerarse anómalo: 12 h (configurable). **Nunca se cierra automáticamente** sin intervención humana. |
| RN-09 | El cálculo de duración usa aritmética sobre instantes UTC, por lo que es **inmune a los cambios de hora (DST)**. El día del cambio de hora una jornada natural puede tener 23 o 25 horas y los informes deben reflejarlo. |
| RN-10 | **Descanso entre jornadas**: se alerta si entre el fin de un turno y el inicio del siguiente median menos de 12 h (art. 34.3 ET). |
| RN-11 | **Jornada diaria ordinaria**: se alerta si un empleado supera 9 h efectivas en una jornada. |
| RN-12 | **Descanso en jornada continuada**: se alerta si un tramo continuo supera 6 h sin pausa registrada. |
| RN-13 | Ningún registro de fichaje se **borra ni se sobrescribe**. Las correcciones generan una nueva versión y conservan la anterior con su autor, momento y motivo. |
| RN-14 | Un empleado dado de baja conserva su historial; su credencial queda revocada y sus escaneos son rechazados. |
| RN-15 | El horario de un fichaje offline es el `occurred_at` del dispositivo, marcado con su retraso de sincronización. Si supera el umbral, requiere validación del responsable. |
| RN-16 | **Secuencia imposible de credencial**: dos escaneos de la misma credencial en **dispositivos distintos** separados por menos del tiempo mínimo de tránsito entre ellos (configurable, `ATTENDANCE_MIN_TRANSIT_SECONDS`). Genera incidencia `anomalous_pattern` para revisión humana. **Nunca anula el fichaje ni concluye que ha habido fraude** (RF-PR-06). **No se evalúa** si alguno de los dos escaneos tiene incidencia `clock_skew` (RF-AT-10) o llegó en un lote cuyo retraso de sincronización supera el umbral (RN-15). |

> **Sobre RN-16.** RF-PR-06 enumera tres patrones anómalos y define dos por sus parámetros —fichajes consecutivos en el mismo quiosco (`ATTENDANCE_PATTERN_WINDOW_SECONDS`) y coincidencias sistemáticas entre dos empleados (`ATTENDANCE_PATTERN_MIN_REPEATS`)—, pero nombra el tercero, «secuencias imposibles», sin definirlo. Sin enunciado no hay umbral, no hay prueba y, sobre todo, **no hay forma de sostener el indicio ante la persona señalada**, que es precisamente lo que el runbook `patron-anomalo-credencial.md` existe para evitar. Es una regla estructural en su forma —dos escaneos, dos dispositivos, un umbral— y **configurable en su número**, porque la distancia entre dos quioscos depende del hotel.
>
> **Por qué la exclusión final no es un detalle.** La regla mide sobre `occurred_at`, que es la hora **del dispositivo** (RN-15). Dos quioscos con relojes desviados producen deltas ficticios —incluso negativos— entre dos escaneos perfectamente legítimos, y RF-AT-10 tolera desviaciones de 15 minutos. Sin la exclusión, la regla acusaría a personas concretas de un patrón que solo existe porque una tablet va adelantada. Un indicio que se dispara por un fallo técnico destruye la confianza en todos los demás.
>
> **Dos quioscos contiguos son un caso real, no un borde.** Una entrada de personal con dos tablets —una con la cámara sucia y el empleado probando en la otra— tiene un tránsito real de segundos. El umbral es **por instalación** y el asistente de puesta en marcha debe preguntarlo; el valor de serie no puede asumir distancias.

---

## 5. Modelo de dominio

### 5.1 Contextos delimitados

```
┌─────────────────────────────────────────────────────────────────┐
│                        SISTEMA DE FICHAJE                        │
│                                                                  │
│  ┌────────────────┐   ┌────────────────┐   ┌─────────────────┐  │
│  │   Workforce    │   │   Attendance   │   │   Compliance    │  │
│  │  (Plantilla)   │◄──│    (NÚCLEO)    │──►│  (Cumplimiento) │  │
│  │                │   │                │   │                 │  │
│  │ Employee       │   │ ShiftEntry     │   │ AuditRecord     │  │
│  │ Department     │   │ WorkDay        │   │ Incident        │  │
│  │ Site           │   │ ScanEvent      │   │ RetentionPolicy │  │
│  │ Contract       │   │ Correction     │   │ LegalExport     │  │
│  │ Absence        │   │                │   │                 │  │
│  └────────────────┘   └───────┬────────┘   └─────────────────┘  │
│                               │                                  │
│  ┌────────────────┐   ┌───────▼────────┐   ┌─────────────────┐  │
│  │   Identity     │   │   Reporting    │   │     Kiosk       │  │
│  │  (Acceso)      │   │   (Lectura)    │   │  (Dispositivos) │  │
│  │                │   │                │   │                 │  │
│  │ User / Role    │   │ DailyTotal     │   │ Device          │  │
│  │ Credential(QR) │   │ PeriodSummary  │   │ SyncBatch       │  │
│  │ DeviceToken    │   │ ComplianceView │   │ Heartbeat       │  │
│  └────────────────┘   └────────────────┘   └─────────────────┘  │
│                                                                  │
│  ┌──────────────────────────────────────────────────────────┐   │
│  │  Product — configuración, perfiles, licencia, soporte     │   │
│  └──────────────────────────────────────────────────────────┘   │
└─────────────────────────────────────────────────────────────────┘
```

**Núcleo de negocio:** `Attendance` y `Compliance`. Ahí reside la ventaja competitiva y el riesgo legal; se implementan con dominio rico y aislado del framework.
**Subdominios de soporte:** `Workforce`, `Kiosk`, `Reporting`, `Product`.
**Subdominio genérico:** `Identity`.

### 5.2 Agregados y entidades principales

| Agregado | Raíz | Invariantes que protege |
|---|---|---|
| **WorkDay** | Jornada de un empleado en una fecha | RN-01, RN-02, RN-06, RN-07, RN-08. Es la frontera transaccional del fichaje. |
| **Employee** | Empleado | Unicidad de código, estado activo o baja, vigencia de contrato. |
| **Credential** | Credencial QR | Una credencial activa por empleado; firma válida; revocación. |
| **Device** | Quiosco | Token válido, ámbito limitado, vinculación a centro. |
| **AuditTrail** | Registro de auditoría | Solo-append, encadenamiento por hash. |

> **`Device` es raíz de agregado en `Kiosk`, y `Identity` emite y revoca su token.** El agregado protege lo que es del dispositivo —identidad, centro al que está vinculado, estado y latido— y esas invariantes son propiedad de `Kiosk`, que es el módulo del punto de fichaje. **El token no lo es:** su emisión, su ámbito y su revocación son de `Identity`, igual que las credenciales, y se exponen a `Kiosk` **por caso de uso público explícito**, nunca por acceso directo a la tabla. `Device` no valida su propio token: recibe el resultado de esa validación. Queda escrito aquí, y no se decide al ejecutar la tarea que lo construye.

### 5.3 Objetos de valor

`EmployeeCode`, `QrPayload`, `TimeRange` (instantes de inicio y fin en UTC), `WorkedDuration` (minutos, no negativo), `WorkDate` (fecha más zona), `ScanOrigin` (`QR_KIOSK` | `PIN_KIOSK` | `MANUAL_ADMIN` | `IMPORT`), `CorrectionReason`.

### 5.4 Eventos de dominio

`EmployeeClockedIn`, `EmployeeClockedOut`, `ScanRejected`, `ShiftCorrected`, `IncidentRaised`, `IncidentResolved`, `CredentialIssued`, `CredentialPrinted`, `CredentialDelivered`, `CredentialRevoked`, `OfflineBatchSynced`, `DailyTotalsRecalculated`.

> **`CredentialPrinted` y `CredentialDelivered` no son eventos nuevos del producto**, sino los dos actos que RF-QR-04 y RF-QR-06 ya exigían registrar y que faltaban en esta lista. `CredentialPrinted` es además el hecho con relevancia legal del ciclo: es el momento en que se acuña el QR y la tarjeta pasa a poder fichar ([ADR-034](adr/ADR-034-el-token-nace-al-imprimir-no-al-emitir.md)), y por eso es el que lleva el `key_id` — `CredentialIssued` no puede llevarlo, porque en la emisión todavía no hay clave elegida.

Alimentan las proyecciones de lectura, el trail de auditoría, las notificaciones push al panel en vivo y las métricas de negocio.

### 5.5 Esquema de datos

Motor: **PostgreSQL 17**. Los tipos se expresan en su nomenclatura. El Anexo D del documento 02 recoge la equivalencia para MySQL 8 si la infraestructura de un cliente lo impusiera.

**`sites`** — el centro de trabajo de la instalación
`id`, `name`, `timezone` (por defecto `Europe/Madrid`), `compliance_profile_id`, `settings` (JSONB), `created_at`. **Una sola fila** (índice único `sites_single_row_uidx`, ADR-040): `site_id` se conserva en las demás tablas y apunta siempre a ella.

**`departments`** — `id`, `site_id`, `name`, `manager_user_id`

**`employees`**
`id` (BIGINT PK), `uuid` (UUID v7, identificador público), `site_id`, `department_id`, `first_name`, `last_name`, `employee_code` (CITEXT UNIQUE, **opaco y aleatorio**), `national_id_hash` (hash, no el DNI en claro), `email` (CITEXT NULL, **opcional**), `pin_hash` (RF-AT-11, RF-ID-06), `photo_path` (NULL; funcionalidad **desactivada por defecto**, RL-08), `status` (`active`|`suspended`|`terminated`), `hired_at`, `terminated_at`, `locale`, `created_at`, `updated_at`

**`employment_contracts`** — `id`, `employee_id`, `weekly_hours`, `annual_hours`, `schedule_type` (`continua`|`partida`|`turnos`), `valid_from`, `valid_to`

**`credentials`** — `id`, `uuid`, `employee_id`, `key_id`, `secret_hash`, `issued_at`, `printed_at`, `delivered_at`, `delivered_by_user_id`, `revoked_at`, `revoked_reason`

> `uuid` es el identificador **público** y se añadió en la tarea 1.5. Faltaba aquí, pero el Anexo B ya nombraba la credencial por UUID en la ruta (`POST /api/v1/credentials/{uuid}/revoke`): sin él, el contrato solo podía cumplirse exponiendo la clave interna, que revela cuántas tarjetas se han emitido y en qué orden. Es la misma decisión que ya tomaron `employees.uuid`, `users.uuid` y `devices.uuid`: el `BIGINT` no sale nunca de la base de datos.
>
> **`key_id` y `secret_hash` son NULL hasta que la tarjeta se imprime** ([ADR-034](adr/ADR-034-el-token-nace-al-imprimir-no-al-emitir.md)). El token en claro no se almacena nunca, así que se acuña en el mismo acto que dibuja el PDF: las tres columnas —`key_id`, `secret_hash` y `printed_at`— se escriben juntas o no se escribe ninguna. Una credencial pendiente de imprimir existe, cuenta en el panel de RF-QR-08 y **no puede fichar**, porque no hay hash por el que resolverla. La entrega, a su vez, lleva siempre `delivered_at` y `delivered_by_user_id`, y no puede preceder a la impresión.

**`devices`** — `id`, `site_id`, `name`, `token_hash`, `app_version`, `last_seen_at`, `pending_queue_size`, `status`

**`compliance_profiles`** — perfil de cumplimiento (RF-PD-07)
`id`, `name`, `jurisdiction`, `retention_years`, `min_rest_hours`, `max_daily_hours`, `max_weekly_hours`, `break_required_after_hours`, `week_starts_on`, `holiday_calendar` (JSONB), `is_default`
*Alimenta RN-10, RN-11 y RN-12. Se entrega el perfil `ES-hosteleria` de serie.*

**`installation_settings`** — configuración de la instalación (RF-PD-01)
`key`, `value` (JSONB), `scope` (`installation`|`site`), `scope_id`, `updated_by_user_id`, `updated_at`
*Cubre marca, umbrales, idiomas y funcionalidades activas. Todo cambio queda auditado, porque algunos afectan al cálculo de horas.*

**`license`** — `id`, `signed_key`, `customer_name`, `plan`, `max_employees`, `max_devices`, `features` (JSONB), `valid_until`, `activated_at`, `last_verified_at`

**`support_grants`** — `id`, `granted_by_user_id`, `reason`, `scope`, `granted_at`, `expires_at`, `revoked_at`, `accessed_at`

**`scan_events`** — log inmutable de todo escaneo, aceptado o no
`id`, `scan_id` (UUID v7 generado en cliente, UNIQUE → idempotencia), `device_id`, `employee_id` (nullable si no resuelve), `occurred_at` (TIMESTAMPTZ), `recorded_at` (TIMESTAMPTZ), `origin`, `intent` (`auto`|`break_start`|`break_end`; **lo declara el cliente**, `auto` por defecto), `result` (`clock_in`|`clock_out`|`break_start`|`break_end`|`rejected_unknown`|`rejected_revoked`|`rejected_debounce`|`rejected_signature`), `shift_entry_id`, `payload_fingerprint`, `client_meta` (JSONB)

> **`intent` frente a `result`.** `intent` es lo que el quiosco **pide** y viaja en la petición; `result` es lo que el servidor **decidió** y viaja en la respuesta. Son dos campos porque con la pausa modelada como dos tramos (ADR-024) el servidor no puede deducir si un cierre de tramo es una pausa o un fin de jornada: son estructuralmente idénticos. `auto` preserva el comportamiento de un cliente que no declara intención, de modo que ampliar el enum es aditivo y no rompe la v1 (ADR-012).

**`shift_entries`**
`id`, `uuid`, `employee_id`, `site_id`, `work_date` (DATE, jornada según RN-05), `clocked_in_at` (TIMESTAMPTZ), `clocked_out_at` (TIMESTAMPTZ NULL), `duration_minutes` (INT NULL, derivada), `status` (`open`|`closed`|`anomalous`|`voided`|`superseded`), `clock_in_source`, `clock_out_source`, `version`, `superseded_by_id`, `created_at`, `updated_at`

*Restricciones garantizadas por la propia base de datos, no solo por la aplicación:*

```sql
CREATE EXTENSION IF NOT EXISTS btree_gist;

-- RN-01: como máximo un turno abierto por empleado
CREATE UNIQUE INDEX one_open_shift_per_employee
    ON shift_entries (employee_id)
    WHERE clocked_out_at IS NULL AND status NOT IN ('voided', 'superseded');

-- RN-02: los tramos vigentes de un mismo empleado no pueden solaparse
ALTER TABLE shift_entries ADD CONSTRAINT shift_entries_no_overlap
    EXCLUDE USING gist (
        employee_id WITH =,
        tstzrange(clocked_in_at, clocked_out_at) WITH &&
    ) WHERE (status NOT IN ('voided', 'superseded'));

-- RN-03: la salida es posterior a la entrada
ALTER TABLE shift_entries ADD CONSTRAINT shift_entries_chk_order
    CHECK (clocked_out_at IS NULL OR clocked_out_at > clocked_in_at);
```

> **Los dos estados no vigentes (ADR-026).** `voided` significa «este tramo no ocurrió» (anulación); `superseded` significa «ocurrió, se conserva, y otra versión lo sustituye» (corrección, RN-13). Las dos garantías declarativas y **el recálculo de `daily_totals` (RN-06) operan solo sobre el conjunto vigente**, es decir, `status NOT IN ('voided', 'superseded')`. Sin `superseded`, la fila anterior y la corregida se solaparían, la restricción de exclusión rechazaría la corrección y el recálculo duplicaría los minutos del día. El histórico íntegro se recorre por `version` y `superseded_by_id` (RL-04).

**`daily_totals`** — **proyección de lectura reconstruible**, no fuente de verdad
`id`, `employee_id`, `work_date`, `total_minutes`, `shift_count`, `first_in_at`, `last_out_at`, `has_open_shift`, `has_incident`, `recalculated_at`. UNIQUE `(employee_id, work_date)`.

**`shift_corrections`** — `id`, `shift_entry_id`, `performed_by_user_id`, `action`, `before` (JSONB), `after` (JSONB), `reason_code`, `reason_text`, `created_at`

**`incidents`** — `id`, `employee_id`, `work_date`, `type` (`open_shift_expired`|`short_shift`|`long_shift`|`insufficient_rest`|`clock_skew`|`missing_clock_out`|`anomalous_pattern`), `severity`, `status`, `assigned_to_user_id`, `resolved_at`, `resolution_note`

**`absences`** — `id`, `employee_id`, `type`, `starts_on`, `ends_on`, `note`

**`error_events`** — histórico de errores de aplicación (RF-PD-15)
`id`, `fingerprint` (hash de clase + punto de fallo + mensaje normalizado, UNIQUE), `level` (`error`|`critical`), `source` (`api`|`worker`|`scheduler`|`console`|`kiosk`|`admin`|`portal`), `module`, `code`, `message`, `exception_class`, `file`, `line`, `context` (JSONB, **sin PII**), `trace_id`, `device_id` (NULL), `employee_uuid` (NULL, nunca el nombre), `app_version`, `occurrences` (INT), `first_seen_at`, `last_seen_at`, `resolved_at`, `resolved_by_user_id`
*Un fallo que se repite mil veces es una fila con `occurrences = 1000`, no mil filas. Retención 90 días, igual que el log técnico (RL-11). No sustituye al log estructurado: lo complementa para que el cliente pueda diagnosticar desde el panel sin depender de que conserve el stack de observabilidad.*

**`audit_log`** — solo-append, con encadenamiento por hash, **particionada por año** (ADR-027)
`id`, `occurred_at` (TIMESTAMPTZ), `actor_type`, `actor_id`, `action`, `subject_type`, `subject_id`, `payload` (JSONB), `prev_hash`, `hash`, `ip` (INET), `user_agent`. Clave primaria `(id, occurred_at)`, porque la clave de partición debe formar parte de toda restricción única.
*Permisos:* el usuario de aplicación tiene `INSERT` y `SELECT`, **nunca** `UPDATE` ni `DELETE` sobre esta tabla ni sobre ninguna de sus particiones.
*Particionado:* `PARTITION BY RANGE (occurred_at)`, una partición por año natural, creada desde la primera migración. Una tarea programada crea la partición del año siguiente antes de que llegue y alerta si falta la del año en curso.

**`audit_chain_anchors`** — sello de cada partición purgada (ADR-027)
`id`, `partition_year` (INT UNIQUE), `first_hash`, `last_hash`, `row_count`, `sealed_at` (TIMESTAMPTZ), `sealed_by`
*La purga de retención de RL-02 es `DROP PARTITION` ejecutada por un **rol de mantenimiento distinto** del usuario de aplicación, previa verificación de la cadena y previo sellado del ancla. `compliance:verify-audit-chain` resuelve un `prev_hash` huérfano contra el `last_hash` de un ancla: si encaja es una purga legítima, y si no encaja es manipulación y salta la alerta de RS-07.*

**`users`, `roles`, `permissions`, `personal_access_tokens`** — identidad y acceso.

---

## 6. Requisitos no funcionales

### 6.1 Rendimiento

| ID | Requisito | Objetivo |
|---|---|---|
| RNF-P-01 | Latencia percibida del fichaje (de escaneo a confirmación en pantalla) | p95 < 800 ms; p99 < 1,5 s |
| RNF-P-02 | Latencia del endpoint de fichaje en servidor | p95 < 150 ms; p99 < 400 ms |
| RNF-P-03 | Tiempo de decodificación del QR en tablet de gama media | < 300 ms |
| RNF-P-04 | Carga del panel de presencia en vivo (500 empleados) | < 1,5 s (LCP) |
| RNF-P-05 | Generación de informe mensual de 500 empleados | < 5 s síncrono; asíncrono si supera 10 s |
| RNF-P-06 | Pico de concurrencia soportado | 50 fichajes/segundo (cambio de turno) |
| RNF-P-07 | Presupuesto de bundle del quiosco | < 250 KB gzip (JS crítico) |

### 6.2 Disponibilidad y resiliencia

| ID | Requisito |
|---|---|
| RNF-D-01 | Disponibilidad del servicio: 99,5 % mensual. **Disponibilidad del acto de fichar: 99,9 %**, gracias al modo offline. |
| RNF-D-02 | RPO ≤ 15 min (copias más WAL). RTO ≤ 4 h. |
| RNF-D-03 | Degradación elegante: si cae el WebSocket, el panel hace *fallback* a sondeo cada 15 s. Si cae Redis, las colas caen a driver de base de datos. |
| RNF-D-04 | Ninguna migración puede requerir parada de servicio (patrón *expand / migrate / contract*). |
| RNF-D-05 | Prueba de restauración de copia documentada y ejecutada trimestralmente. |

### 6.3 Escalabilidad

Dimensionado objetivo por instalación: 500 empleados, 10 quioscos, ~6.000 eventos/día, ~2 M registros/año. El diseño soporta 10× sin rediseño (particionado por rango de fecha en `scan_events` a partir de 10 M filas).

### 6.4 Mantenibilidad

| ID | Requisito |
|---|---|
| RNF-M-01 | Cobertura de pruebas: **≥ 90 % en la capa de dominio**, ≥ 75 % global. |
| RNF-M-02 | Análisis estático en nivel máximo (PHPStan/Larastan nivel 9) sin errores suprimidos sin justificar. |
| RNF-M-03 | Las dependencias entre módulos se verifican automáticamente. El dominio no importa nada del framework. |
| RNF-M-04 | Toda decisión arquitectónica relevante queda registrada como **ADR** versionado en el repositorio. |
| RNF-M-05 | Deuda técnica visible: presupuesto máximo del 15 % de cada iteración dedicado a su reducción. |
| RNF-M-06 | **El código sigue las convenciones publicadas de cada stack** (documento 02, §3.5): PSR-12/PER para PHP, convenciones de Laravel, guía de estilo oficial de Vue 3 y TypeScript estricto. **Se verifican por herramienta en la CI, no por revisión humana**: una convención que no comprueba una herramienta es una sugerencia. |

### 6.5 Usabilidad y accesibilidad

WCAG 2.2 nivel AA en el panel y el portal. En el quiosco, además: objetivos táctiles ≥ 48 px, texto de confirmación ≥ 24 px, contraste ≥ 4.5:1, doble canal de feedback visual y sonoro, operable con una sola mano y con guantes, y funcional bajo iluminación baja.

### 6.6 Internacionalización

Todos los textos externalizados. Idiomas iniciales: español e inglés. Preparado para catalán, rumano y árabe, incluido soporte RTL. Formatos de fecha, hora y primer día de semana según *locale*.

### 6.7 Portabilidad

Desplegable en un VPS o servidor propio con Docker, sin dependencias de servicios propietarios de ningún proveedor cloud. **El sistema funciona íntegramente sin salida a internet.**

---

## 7. Requisitos legales, de privacidad y cumplimiento

> Esta sección recoge requisitos de producto derivados del marco normativo; no constituye asesoramiento jurídico. Debe ser validada por la asesoría laboral y el DPO, y confirmado el convenio colectivo aplicable a cada cliente.

### 7.1 Registro de jornada (art. 34.9 ET)

| ID | Requisito |
|---|---|
| RL-01 | Registro **diario** con hora concreta de inicio y fin de la jornada de cada persona trabajadora. |
| RL-02 | **Conservación durante 4 años**. Purga controlada y documentada al vencimiento. |
| RL-03 | Los registros permanecen **a disposición** de la persona trabajadora, de la representación legal y de la Inspección de Trabajo, con capacidad de entrega inmediata. |
| RL-04 | El registro debe ser **fiable e inalterable**: cualquier modificación posterior queda trazada con autor, momento, valor anterior y motivo. |
| RL-05 | La persona trabajadora puede **acceder a su propio registro** en cualquier momento y obtener copia. |
| RL-06 | Exportación en formato legible y tratable, no propietario, para requerimientos de Inspección. |

**Riesgo asociado:** la falta de registro o su falseamiento se tipifica como infracción grave en materia de relaciones laborales, sancionable por cada centro de trabajo. La inmutabilidad y la trazabilidad son el requisito, no un extra.

**Vigilancia normativa:** existe una corriente regulatoria orientada a exigir registro digital, interoperable y con acceso remoto para la Inspección. La arquitectura lo cubre por diseño. Debe designarse un responsable de seguimiento antes de cada versión mayor.

### 7.2 Protección de datos (RGPD / LOPDGDD)

| ID | Requisito |
|---|---|
| RL-07 | **Base jurídica**: cumplimiento de obligación legal (art. 6.1.c RGPD) para el registro horario. Documentada en el Registro de Actividades de Tratamiento del cliente. |
| RL-08 | **Minimización**: el sistema no almacena más datos de los necesarios. El DNI se guarda hasheado. El correo electrónico es opcional. La foto del empleado es opcional y está desactivada por defecto. |
| RL-09 | **Información en capas**: aviso visible en el quiosco con enlace o QR a la política completa. |
| RL-10 | **Derechos ARSULIPO**: procedimientos para acceso, rectificación mediante corrección trazada, limitación y portabilidad. La supresión queda condicionada al deber legal de conservación. |
| RL-11 | **Retención**: política por tipo de dato. Registros de jornada 4 años; logs técnicos 90 días; logs de auditoría 4 años; copias con caducidad alineada. |
| RL-12 | **Cifrado**: TLS 1.3 en tránsito; cifrado en reposo de copias de seguridad y del padrón cacheado en la tablet. |
| RL-13 | **EIPD**: se recomienda evaluación de impacto por tratarse de control sistemático de personal trabajadora. |
| RL-14 | **Datos alojados en la UE**, en la infraestructura del propio cliente. |
| RL-15 | **Notificación de brechas**: procedimiento documentado con plazo de 72 h y capacidad técnica de determinar el alcance a partir de los logs de auditoría. |

### 7.3 Reparto de roles en el modelo licenciado

| ID | Requisito |
|---|---|
| RL-16 | El **cliente es responsable del tratamiento** y operador del sistema: aloja los datos, controla los accesos y responde ante la Inspección y ante su plantilla. |
| RL-17 | El **fabricante no es encargado del tratamiento** en la operación ordinaria, porque no aloja ni accede a los datos. |
| RL-18 | **Encargo acotado a soporte.** Cuando el fabricante accede a datos durante una intervención (RF-PD-11), actúa como encargado **para ese supuesto concreto**. Requiere contrato de encargo (art. 28 RGPD) limitado a soporte, con instrucciones documentadas, confidencialidad y prohibición de conservar datos al terminar. |
| RL-19 | **El paquete de diagnóstico no contiene datos personales** por defecto (RF-PD-09). Incluirlos debe ser una acción explícita del cliente, avisada en la interfaz y registrada en auditoría. |
| RL-20 | **Continuidad e independencia del cliente.** El cliente debe poder exportar la totalidad de sus datos en formato abierto y sin intervención del fabricante (RF-PD-14), para seguir cumpliendo su obligación de conservación aunque la relación comercial termine. |
| RL-21 | **La documentación entregada debe indicar con claridad qué obligaciones asume el cliente**: registro de actividades, información a la plantilla y a su representación, evaluación de impacto si procede, y custodia y copia de los datos. El producto facilita el cumplimiento; no lo sustituye. |

### 7.4 Control empresarial y decisiones explícitas

Los arts. 20.3 ET y 87-91 LOPDGDD exigen informar previamente a la plantilla y a su representación legal del sistema de control. El sistema genera la evidencia de esa información.

**Sin biometría.** Se descarta el reconocimiento facial, la huella y cualquier dato biométrico. Motivos: son datos de categoría especial (art. 9 RGPD), el criterio de la autoridad de control ha sido restrictivo respecto a su proporcionalidad en control de presencia, y existen alternativas menos invasivas. Registrado como ADR-009.

**Sin dependencia del dispositivo personal.** La credencial es una tarjeta que entrega la empresa. El sistema no requiere que el empleado use su propio teléfono ni su plan de datos para una finalidad laboral, lo que elimina una fricción habitual con la representación de los trabajadores.

---

## 8. Seguridad — requisitos de producto

| ID | Requisito |
|---|---|
| RS-01 | El payload del QR está **firmado** y no permite generar credenciales válidas de terceros sin la clave del servidor. |
| RS-02 | El sistema **limita la tasa** del camino de fichaje **por dispositivo y por IP**, con respuestas de tiempo constante para evitar enumeración. El límite **por sujeto** se aplica donde el secreto es adivinable —el PIN, por empleado y por origen (RS-12)— y **no** al escaneo de tarjeta, por el motivo que se explica bajo esta tabla. |
| RS-03 | Las respuestas de error **no revelan** si un código existe, está revocado o es inválido: mensaje genérico al usuario, detalle solo en el log del servidor. |
| RS-04 | El token del quiosco tiene ámbito mínimo, caducidad y rotación automática; su compromiso no da acceso a datos de plantilla. |
| RS-05 | Todo acceso a datos personales de terceros queda registrado en el trail de auditoría. |
| RS-06 | 2FA obligatorio para `admin`, `rrhh` y `auditor`. |
| RS-07 | El trail de auditoría es **detectablemente manipulable**: cada entrada encadena el hash de la anterior; la cadena **se verifica a diario** y cualquier rotura dispara alerta crítica de seguridad en menos de 24 h. |
| RS-08 | Gestión de secretos fuera del repositorio, con rotación documentada. |
| RS-09 | Cabeceras de seguridad completas (HSTS, CSP estricta, X-Content-Type-Options, Referrer-Policy, Permissions-Policy limitando cámara al origen propio). |
| RS-10 | Análisis de dependencias y de código en cada *pull request*; ninguna vulnerabilidad crítica o alta puede llegar a una versión publicada. |
| RS-11 | Revisión de seguridad externa antes de la primera versión comercial y con periodicidad anual. |
| RS-12 | El PIN de acceso al portal está protegido con bloqueo temporal por intentos fallidos y limitación de tasa por empleado y por IP. |
| RS-13 | **Toda autenticación deja rastro consultable** en los tres canales —gestión, portal y PIN de quiosco— con el reparto que fija [ADR-039](adr/ADR-039-que-hechos-de-autenticacion-dejan-asiento.md): el acceso y el cierre de sesión de **gestión** y la **apertura de un bloqueo** en cualquier canal dejan asiento en `audit_log`; cada **fallo** deja apunte estructurado en el log técnico y alimenta `kronoqr_auth_attempts_total`, sobre la que se declaran las alertas de fuerza bruta. El acceso del empleado a su portal y el fichaje por PIN **no** dejan asiento de sesión, por el motivo que se explica bajo esta tabla. |

> **Por qué RS-02 ya no pide un límite por credencial en el escaneo de tarjeta (ADR-036).** La redacción anterior enumeraba tres ejes —dispositivo, credencial e IP— y solo se implementaron dos. Al revisarlo se vio que el tercero no protege de lo que la propia frase dice proteger, y que hacerlo tendría coste: **(a)** contra la enumeración no sirve, porque quien enumera prueba credenciales *distintas* y un contador por credencial nunca llega a dispararse —el eje que sí frena la enumeración es el de dispositivo y el de IP—; **(b)** la repetición de una misma tarjeta ya está resuelta por el periodo de gracia de RF-AT-06, que es un **desenlace aceptado** (ADR-031) y no un rechazo; **(c)** un `429` por credencial sería la única forma en que este producto puede dejar a una persona concreta sin fichar —basta con que alguien inunde con su tarjeta—, y eso contradice la regla que sostiene el registro legal: *el quiosco nunca bloquea al empleado*. El límite por sujeto se mantiene donde de verdad hace falta, que es donde el secreto se puede adivinar: el PIN (RS-12), con bloqueo escalonado por empleado y origen.

> **Por qué RS-13 no audita la sesión del empleado (ADR-037, ADR-039).** El fallo nunca entra en `audit_log`: es el hecho de volumen alto y el que un atacante controla, y cada asiento pasa por el candado global de la cadena (ADR-010), por el que pasa también cada fichaje; su sitio son los 90 días del log operativo (RL-11). El acceso del empleado al portal tampoco: es el ejercicio del derecho del art. 34.9 ET (RL-05), y ADR-037 ya excluye de la traza ese ejercicio —un asiento por sesión, conservado cuatro años y consultable por el empleador, sería una traza de quién consulta su registro y cuándo—. El fichaje por PIN deja el `shift_entry.created` que ya prueba al mismo empleado y el mismo instante. El catálogo de actores de `audit_log` no tiene tipo para el empleado, y **no se crea para sesiones**: se creará con la primera acción de autoría del empleado con relevancia legal (una solicitud de corrección desde el portal, una conformidad sobre el registro), que es cuando `system` mentiría.

### 8.1 Modelo de amenazas (STRIDE)

| Amenaza | Vector | Mitigación | ATT&CK |
|---|---|---|---|
| **Suplantación** | Generación de un QR falso para un compañero | **Resuelto**: firma HMAC del payload (RS-01). | `T1606 Forjado de credenciales web` |
| **Suplantación** | Préstamo de la tarjeta a un compañero (*buddy punching*) | Fraude **autolimitado**: prestarla deja al titular sin la suya, exige entrega y devolución, y solo funciona si el titular no piensa fichar. Mitigación: supervisión presencial y detección automática de patrones anómalos (RF-PR-06): dos fichajes en el mismo quiosco separados por segundos, coincidencias sistemáticas entre dos empleados. | — |
| **Suplantación** | Fuerza bruta del PIN de portal o de quiosco | Bloqueo por intentos, limitación de tasa, portal restringido a red interna por defecto (RS-12, RF-ID-08). | `T1110.001 Fuerza bruta: adivinación de contraseña` |
| **Manipulación** | Alterar horas en base de datos | Auditoría encadenada, versionado de registros, usuario de base de datos con permisos mínimos, copias verificadas. | `T1565.001 Manipulación de datos almacenados` |
| **Manipulación** | Alteración de la clave de licencia | Clave firmada y verificada localmente. **Es un control comercial, no de seguridad de datos**: no debe protegerse a costa de bloquear el registro legal. | `T1553 Subvertir controles de confianza` |
| **Repudio** | "Yo sí fiché" / "esa corrección no la hice yo" | `scan_events` inmutable, auditoría con actor y doble marca temporal. | `T1070 Eliminación de indicadores` |
| **Divulgación** | Filtración del padrón cacheado en la tablet | Cifrado en reposo, solo datos mínimos, purga al desvincular el dispositivo. | `T1005 Datos del sistema local` |
| **Divulgación** | El paquete de diagnóstico contiene datos personales | Anonimizado por defecto (RF-PD-09, RL-19). | — |
| **Denegación de servicio** | Inundación del endpoint de fichaje | Rate limiting en Nginx y aplicación, colas, y **modo offline que mantiene operativo el fichaje**. | `T1499.002 DoS de punto final: inundación de servicio` |
| **Elevación** | Token de quiosco usado contra endpoints de gestión | Ámbitos estrictos, autorización por política en cada endpoint y pruebas de autorización obligatorias. | `T1550.001 Material de autenticación alternativo: token de aplicación` |
| **Elevación** | Acceso de soporte usado fuera del incidente que lo motivó | Concesión expresa, temporal, de alcance limitado, revocable y auditada de forma visible para el cliente (RF-PD-11). | `T1199 Relación de confianza` |

> **Para qué sirve la columna ATT&CK.** STRIDE dice qué se mitiga; MITRE ATT&CK (matriz Enterprise; el identificador es el canónico, el nombre corto va traducido) nombra la técnica tal como la buscaría un analista en un SIEM o en un informe de incidente, y obliga a mirar el otro lado del control: la **detección**. Cada técnica cuya mitigación figura como resuelta debería tener además una señal observable, porque un control sin señal no distingue «nadie lo intentó» de «lo intentaron y falló». Hoy la tienen `T1565.001` y `T1070` sobre `audit_log` —verificación diaria de la cadena y alerta `RoturaDeCadenaDeAuditoria` (RS-07)— y `T1110.001` la está recibiendo con la auditoría de autenticación y las alertas `KronoqrAuth*` que se incorporan en `infra/observability` (doc 07 §5; hasta que queden en verde, la señal es solo el bloqueo por intentos y la telemetría del portal, sin alerta). **Sin detección hoy:** `T1606` (los rechazos de firma se cuentan como métrica, sin alerta), `T1499.002` (el borde responde `429` pero la alerta de saturación llega con la tarea 3.2), `T1550.001` (un `403` no deja asiento ni alerta), `T1199` (la concesión auditada llega con la tarea 5.9) y `T1005` (por naturaleza solo es mitigable: cifrado y purga). Las dos filas con «—» no describen a un adversario informático sino a una persona con acceso legítimo o a un proceso propio: el préstamo de tarjeta es un fraude físico que ATT&CK no modela y cuya señal es el patrón anómalo (RF-PR-06); el paquete de diagnóstico con datos personales es un fallo de minimización, no un ataque. Cuatro técnicas relevantes no tienen fila porque su mitigación vive fuera de este modelo: `T1195.002 Cadena de suministro: software` (RS-10), `T1557 Intermediario` (TLS 1.3 obligatorio, doc 02 §7.1), `T1552 Credenciales no protegidas en ficheros` (RS-08) y `T1200 Hardware adicional` en la VLAN de quioscos (segmentación por `KIOSK_VLAN_CIDR`). La madurez y la cobertura técnica → detección se siguen en `docs/07-seguridad-madurez-y-amenazas.md`.

---

## 9. Observabilidad y métricas — requisitos de producto

### 9.1 Métricas técnicas

Rate, errores y duración por endpoint, con foco en el de fichaje. Latencia p50/p95/p99. Profundidad y latencia de colas. Conexiones y latencia de base de datos, consultas lentas, ratio de aciertos de caché. Saturación de CPU, memoria, disco y descriptores.

### 9.2 Métricas de negocio

| Métrica | Uso |
|---|---|
| Fichajes por minuto y por quiosco | Detectar quiosco caído en cambio de turno |
| Turnos abiertos en este momento | Contraste con la ocupación esperada |
| Escaneos rechazados por causa | Detectar credenciales deterioradas o intentos de fraude |
| Antigüedad de la cola offline por dispositivo | Detectar tablets desconectadas |
| Retraso de sincronización (p95) | Calidad del dato |
| Empleados sin credencial entregada | **Quién no puede fichar todavía.** Debe ser cero antes de cada incorporación |
| Fichajes por PIN de respaldo | Una subida indica un problema con la emisión o el estado de las tarjetas |
| Incidencias abiertas por tipo y antigüedad | Salud del proceso de RRHH |
| Ratio de correcciones manuales | Calidad del dato y posible mal uso |
| Patrones anómalos detectados por tipo | Indicio de préstamo de credencial (RF-PR-06). Se revisa, no se sanciona automáticamente |
| Divergencia entre proyección y eventos origen | Integridad del sistema |
| Horas trabajadas frente a contratadas por departamento | Control de costes laborales |
| Tasa de absentismo e impuntualidad | Indicador de gestión |
| **Jornadas con registro completo** | El indicador de impacto principal: mide si el sistema está cumpliendo su función (RF-IN-08) |
| **Reparto de fichajes por origen** (QR, PIN, corrección manual) | Adopción real. Si las correcciones manuales suben, el registro se está degradando |
| **Tiempo medio hasta resolver una incidencia** | Salud del proceso y contraste con el objetivo de < 24 h del §1.3 |
| Errores de aplicación por origen y severidad | Estabilidad de la instalación (RF-PD-15). Alimenta el histórico de `error_events` |

### 9.3 Alertas mínimas

Cada alerta lleva **umbral, severidad, destinatario y enlace a su runbook**. Una alerta sin procedimiento asociado es ruido y se elimina (doc 02 §8.4).

| Alerta | Umbral | Severidad | Destinatario | Runbook |
|---|---|---|---|---|
| Quiosco sin latido | > 10 min | Crítica (operaciones) | IT del cliente | `quiosco-no-responde.md` |
| Tasa de error 5xx en el endpoint de fichaje | > 1 % en 5 min | Crítica | IT del cliente | `errores-en-el-panel.md` |
| Latencia p95 del endpoint de fichaje | > 500 ms en 10 min | Alta | IT del cliente | `errores-en-el-panel.md` |
| Cola offline de un dispositivo | > 50 elementos o > 2 h | Alta | IT del cliente | `cola-offline-atascada.md` |
| Turnos abiertos > 12 h | cualquiera | Media | RRHH | `turno-abierto-prolongado.md` |
| Divergencia en reconciliación nocturna | cualquiera | Crítica | IT del cliente | `divergencia-proyeccion.md` |
| Rotura de la cadena de hash de auditoría | cualquiera | Crítica (seguridad) | Responsable de seguridad | `rotura-cadena-auditoria.md` |
| Copia de seguridad fallida o no verificada | cualquiera | Crítica | IT del cliente | `restaurar-backup.md` |
| Certificado TLS próximo a expirar | < 21 días | Alta | IT del cliente | `renovacion-certificado-tls.md` |
| Espacio en disco | < 20 % | Alta | IT del cliente | `espacio-en-disco.md` |
| Errores nuevos de severidad crítica en `error_events` | cualquiera en 5 min | Alta | IT del cliente | `errores-en-el-panel.md` |

**Criterio de asignación del destinatario.** Cinco alertas no lo declaraban y se han asignado con esta regla, para que «crítica» no signifique cosas distintas según quién la lea:

- **Crítica (seguridad)** → responsable de seguridad. Es un incidente, no una avería: exige preservación de evidencia antes que restablecimiento del servicio.
- **Crítica (operaciones) y Alta** → IT del cliente. Es quien opera la instalación y el único que puede actuar sobre el servidor, la red o los dispositivos.
- **Media** → RRHH. No es un fallo técnico: es trabajo de gestión sobre el registro, y avisar a IT de un turno sin cerrar sería ruido para quien no puede resolverlo.

El fabricante **no es destinatario de ninguna alerta**: no tiene acceso a la instalación (ADR-020) y no puede intervenir.

### 9.4 Trazabilidad y logs

- Log **estructurado en JSON**, con `trace_id`, `scan_id`, `device_id` y `employee_uuid`. **Nunca nombres en claro.**
- Trazas distribuidas con OpenTelemetry desde el navegador del quiosco hasta la base de datos.
- Separación estricta entre **log técnico** (90 días, puede contener errores) y **trail de auditoría** (4 años, inmutable, valor probatorio).
- Correlación del `scan_id` del cliente en toda la traza, para poder responder a "el empleado dice que fichó a las 07:02".

---

## 10. Estrategia de calidad — requisitos

| ID | Requisito |
|---|---|
| RQ-01 | Toda regla de negocio del §4 tiene al menos una prueba unitaria en el dominio, sin base de datos ni framework. |
| RQ-02 | El cálculo de duraciones se prueba con **pruebas basadas en propiedades**, incluyendo los días de cambio de hora y turnos que cruzan medianoche. |
| RQ-03 | La idempotencia del fichaje se prueba con envíos concurrentes del mismo `scan_id`. |
| RQ-04 | Existen pruebas E2E del flujo de quiosco con **cámara simulada** alimentada por vídeo con un QR real. |
| RQ-05 | Existen pruebas del ciclo completo offline → reconexión → sincronización → consolidación. |
| RQ-06 | La API está descrita por un contrato **OpenAPI** y las respuestas se validan contra el esquema en las pruebas. |
| RQ-07 | Existen pruebas de autorización que verifican que cada rol **no** puede acceder a lo que no le corresponde. Test negativo obligatorio por endpoint. |
| RQ-08 | Prueba de carga que valida RNF-P-06 antes de cada versión mayor. |
| RQ-09 | Prueba de restauración de copia automatizada y ejecutada al menos trimestralmente. |
| RQ-10 | Pruebas de mutación sobre el dominio con MSI mínimo del 80 %. |
| RQ-11 | Prueba de **instalación limpia y de actualización desde la versión anterior** antes de cada publicación. |
| RQ-12 | Ninguna funcionalidad se considera terminada sin cumplir la Definición de Terminado del documento 02, §10.3. |
| RQ-13 | **Trazabilidad requisito ↔ prueba, verificada por la CI.** Cada prueba declara qué requisitos cubre mediante una etiqueta (`RF-*`, `RN-*`, `RL-*`, `RS-*`). Un comando genera la matriz `requisito → pruebas` y **la CI falla si un requisito ya implementado no tiene ninguna prueba que lo referencie**. Sin esto, "está probado" es una afirmación que nadie comprueba. |
| RQ-14 | **Cobertura por niveles obligatoria según la naturaleza de la funcionalidad**, no a criterio de quien la implementa. La tabla que decide qué niveles aplican está en el documento 02, §9.5: toda funcionalidad con regla de negocio lleva prueba unitaria; toda la que toque la base de datos, de integración; toda la que exponga endpoint, de feature, contrato y **autorización negativa**; y toda la que tenga recorrido de usuario, E2E. |

---

## 11. Criterios de aceptación de referencia

El conjunto completo vive junto al código como pruebas ejecutables.

```gherkin
Escenario: Alta de empleado y emisión de credencial
  Dado que RRHH da de alta al empleado "Youssef", sin dirección de correo
  Cuando se confirma el alta
  Entonces el alta se completa sin error
  Y se emite su credencial QR
  Y queda disponible para imprimir en PDF, en formato tarjeta y en hoja A4
  Y el panel de estado la muestra como "pendiente de imprimir"

Escenario: Entrega registrada de la tarjeta
  Dada una credencial impresa y pendiente de entregar
  Cuando RRHH la marca como entregada al empleado
  Entonces se registra la fecha, el empleado y el responsable de la entrega
  Y la acción queda en el trail de auditoría

Escenario: Primer fichaje de la jornada
  Dado un empleado activo "Lucía" sin turnos abiertos
  Cuando escanea su tarjeta en el quiosco "Recepción-01" a las 07:02
  Entonces se crea un tramo con entrada a las 07:02 y sin salida
  Y el quiosco muestra "Buenos días, Lucía — Entrada 07:02"
  Y se emite el evento EmployeeClockedIn

Escenario: Cierre de turno con acumulado
  Dado un empleado "Lucía" con un tramo abierto desde las 07:02
  Y un tramo previo cerrado de 120 minutos ese mismo día
  Cuando escanea su tarjeta a las 11:02
  Entonces el tramo se cierra con 240 minutos
  Y el total diario consolidado es de 360 minutos
  Y el quiosco muestra "Hasta luego, Lucía — Salida 11:02 · Hoy: 6 h 0 min"

Escenario: Turno nocturno que cruza la medianoche
  Dado un empleado "Marc" que ficha entrada el día 14 a las 22:00
  Cuando ficha salida el día 15 a las 06:00
  Entonces el tramo dura 480 minutos
  Y el tramo se atribuye a la jornada del día 14
  Y no se ha creado ningún tramo artificial a las 23:59

Escenario: Cambio de hora de otoño
  Dado un centro con zona horaria "Europe/Madrid"
  Y un empleado que ficha entrada el 25 de octubre a las 01:30 CEST
  Cuando ficha salida ese mismo día a las 03:00 CET
  Entonces la duración calculada es de 150 minutos

Escenario: Anti-rebote
  Dado un empleado que acaba de fichar entrada hace 20 segundos
  Cuando vuelve a escanear su tarjeta
  Entonces no se crea ni se cierra ningún tramo
  Y el quiosco muestra un aviso "Ya has fichado hace unos segundos"
  Y el escaneo queda registrado con resultado rejected_debounce

Escenario: QR falsificado
  Dado un payload de QR con firma inválida
  Cuando se envía al endpoint de fichaje
  Entonces la respuesta es un error genérico sin indicar la causa
  Y el intento queda registrado con resultado rejected_signature
  Y se incrementa el contador de escaneos rechazados por firma

Escenario: Tarjeta no disponible
  Dado un empleado que llega al centro sin su tarjeta
  Cuando introduce su PIN de 6 dígitos en el quiosco
  Entonces se registra el fichaje con origen "PIN"
  Y queda marcado para revisión del responsable

Escenario: Tarjeta deteriorada
  Dada una tarjeta con el QR parcialmente dañado
  Cuando el empleado la presenta al quiosco
  Y el nivel de corrección de errores permite decodificarla
  Entonces el fichaje se procesa con normalidad

Escenario: Reemisión por pérdida
  Dada una credencial declarada como perdida
  Cuando RRHH la revoca y emite una nueva
  Entonces la credencial anterior deja de ser aceptada por el quiosco
  Y la revocación y la nueva emisión quedan auditadas

Escenario: Fichaje offline y sincronización posterior
  Dado un quiosco sin conexión a internet
  Cuando un empleado ficha a las 08:00
  Entonces el quiosco confirma el fichaje localmente
  Y encola el evento con su scan_id y occurred_at 08:00
  Cuando se recupera la conexión a las 09:30
  Entonces el evento se sincroniza con occurred_at 08:00 y recorded_at 09:30
  Y el tramo resultante refleja la entrada a las 08:00

Escenario: Idempotencia ante reintento
  Dado un escaneo con scan_id "018f...c3" ya procesado
  Cuando el quiosco reenvía el mismo scan_id por un reintento
  Entonces no se crea un segundo tramo
  Y la respuesta es idéntica a la original

Escenario: Reloj del quiosco desviado
  Dado un quiosco cuyo reloj adelanta 40 minutos respecto al servidor
  Cuando un empleado ficha en él
  Entonces el fichaje se registra igualmente
  Y se crea una incidencia de tipo clock_skew para revisión del responsable
  Y en ningún caso se rechaza el fichaje

Escenario: Patrón anómalo de uso de credencial
  Dados dos fichajes de entrada de empleados distintos en el mismo quiosco separados por 4 segundos
  Y repetidos en las mismas dos personas durante cinco días
  Cuando se ejecuta la detección de patrones anómalos
  Entonces se crea una incidencia de tipo anomalous_pattern
  Y se asigna al responsable del departamento
  Y el sistema no marca el fichaje como fraudulento ni lo anula

Escenario: Turno olvidado
  Dado un tramo abierto desde hace 13 horas
  Cuando se ejecuta el proceso de detección de anomalías
  Entonces el tramo NO se cierra automáticamente
  Y se crea una incidencia de tipo open_shift_expired
  Y se notifica al responsable del departamento

Escenario: Corrección manual trazada
  Dado un tramo abierto de la empleada "Ana"
  Cuando el responsable lo cierra a las 15:00 con motivo "olvido de fichaje"
  Entonces el tramo queda cerrado a las 15:00
  Y existe un registro de corrección con el valor anterior, el autor y el motivo
  Y el registro original permanece consultable
  Y el total diario se recalcula, no se incrementa

Escenario: Aislamiento por departamento
  Dado un responsable del departamento "Cocina"
  Cuando solicita el detalle de un empleado del departamento "Recepción"
  Entonces recibe un error de autorización
  Y el intento queda registrado en el trail de auditoría

Escenario: El empleado consulta su propio registro
  Dado un empleado con su código y su PIN
  Cuando accede al portal personal desde la red interna
  Entonces ve sus jornadas, sus tramos y sus totales
  Y puede descargar su histórico
  Y no puede acceder a datos de ningún otro empleado

Escenario: Licencia caducada
  Dada una instalación cuya licencia expiró hace tres días
  Cuando un empleado ficha en el quiosco
  Entonces el fichaje se registra con normalidad
  Y el panel muestra un aviso de licencia caducada
  Y los informes y la exportación legal siguen siendo accesibles

Escenario: Acceso de soporte del fabricante
  Dado que el administrador del cliente concede acceso de soporte por 24 horas
  Cuando el soporte del fabricante consulta datos de la instalación
  Entonces cada acceso queda registrado en el trail de auditoría
  Y el registro es visible para el administrador del cliente
  Y transcurridas 24 horas el acceso deja de funcionar sin intervención

Escenario: Perfil de cumplimiento distinto
  Dada una instalación con un perfil de 10 horas de descanso mínimo
  Cuando un empleado encadena dos turnos separados por 11 horas
  Entonces no se genera alerta de descanso insuficiente
  Y con el perfil español por defecto, de 12 horas, sí se habría generado
```

---

## 12. Riesgos

| # | Riesgo | Prob. | Impacto | Mitigación |
|---|---|---|---|---|
| R1 | *Buddy punching* por préstamo de tarjeta | Media | Alto | Firma HMAC (evita falsificación), supervisión presencial y detección automática de patrones anómalos (RF-PR-06). El préstamo físico es autolimitado |
| R2 | Incumplimiento del deber de inalterabilidad del registro | Media | **Muy alto** (sanción) | Diseño solo-append, auditoría encadenada y revisión legal antes de la primera versión comercial |
| R3 | Cambio normativo hacia registro digital interoperable | Media | Medio | Arquitectura ya digital con API y exportación normalizada; responsable de vigilancia normativa |
| R4 | Cortes de red durante el cambio de turno | Alta | Alto | Modo offline obligatorio en el MVP, no como mejora posterior |
| R5 | Rechazo o desconfianza de la plantilla | Media | Medio | Comunicación previa, aviso de privacidad claro, acceso del empleado a sus propios datos, sin biometría y sin uso del dispositivo personal |
| R6 | Cámara de tablet de gama baja con mal rendimiento | Media | Medio | Validar hardware con prueba de concepto; PIN como alternativa |
| R7 | Deriva entre agregados e histórico | Media | Alto | Proyección reconstruible y reconciliación nocturna con alerta |
| R8 | Empleado sin tarjeta: olvido, pérdida o deterioro | Alta | Medio | PIN de respaldo obligatorio (RF-AT-11), reimpresión en el día, panel de estado de credenciales |
| R9 | Logística de impresión con alta rotación estacional | Media | Medio | Impresión masiva en A4, panel de estado que anticipa las emisiones pendientes, y alta con antelación al primer día |
| R10 | Pérdida de la clave de firma de QR | Baja | Alto | Custodia en gestor de secretos, respaldo cifrado, `key_id` que permite rotación con solape y reimpresión progresiva |
| R11 | **N instalaciones en M versiones** hacen inmanejable el soporte | Alta | Alto | Matriz de versiones soportadas publicada, actualización asistida entre versiones no consecutivas, telemetría opcional de versión y política de fin de soporte |
| R12 | **Una incidencia no es diagnosticable a distancia** | Alta | Alto | Paquete de diagnóstico exportable, comprobación de salud, errores accionables y registros legibles. Es la diferencia entre resolver en una hora o en una semana |
| R13 | **Instalación mal configurada por el cliente** (zona horaria, perfil de convenio, copias sin verificar) | Alta | **Muy alto** | Asistente de puesta en marcha, valores por defecto seguros, comprobación de salud que alerta de configuraciones peligrosas y guía de operación |
| R14 | **El cliente no hace copias** y pierde un registro que debe conservar 4 años | Media | **Muy alto** | Copia automática activada por defecto, alerta visible si falla o no se verifica, y responsabilidad explicitada en la documentación |
| R15 | Personalizaciones para un cliente **bifurcan el producto** | Alta | Alto | RF-PD-01 como regla dura. Toda petición que no quepa en configuración se evalúa como funcionalidad del producto, nunca como parche |
| R16 | Subestimación del esfuerzo | Alta | Alto | Planificación por fases con estimación desglosada (documento 02, §11) |

---

## 13. Glosario

| Término | Definición |
|---|---|
| **Fichaje / Escaneo** | Acción de presentar la credencial al quiosco. Genera un `ScanEvent` siempre, se acepte o no. |
| **Tramo** | Par entrada/salida. Unidad mínima de tiempo trabajado. |
| **Jornada** | Conjunto de tramos atribuidos a una fecha civil según RN-05. |
| **Total diario** | Suma de las duraciones de los tramos de una jornada. Proyección reconstruible. |
| **Incidencia** | Situación detectada que requiere intervención humana. |
| **Corrección** | Modificación de un registro por parte de una persona autorizada, siempre trazada y motivada. |
| **Credencial** | Vínculo entre un empleado y un payload QR firmado, materializado en una tarjeta física. Revocable. |
| **Quiosco** | Tablet en modo bloqueado que ejecuta la PWA de fichaje. |
| **Modo quiosco** | Configuración del dispositivo (MDM o *device owner*) que lo fija a una sola aplicación, sin escritorio ni ajustes accesibles y con arranque automático de la PWA. Responsabilidad del cliente, no del producto. |
| **Portal personal** | Interfaz web donde el empleado consulta su propio registro horario. Exigencia legal. |
| **`scan_id`** | Identificador único generado en el cliente que garantiza la idempotencia. |
| **`occurred_at` / `recorded_at`** | Momento real del fichaje / momento de registro en servidor. Difieren en modo offline. |
| **Trail de auditoría** | Registro inmutable y encadenado de toda acción con relevancia legal. |
| **Proyección** | Vista de lectura derivada de los eventos, reconstruible en cualquier momento. |
| **Perfil de cumplimiento** | Conjunto configurable de umbrales legales: descanso mínimo, jornada máxima, pausas, retención. |
| **Instalación** | Despliegue del producto en el servidor de un cliente, con su base de datos, licencia y configuración. |
| **Paquete de diagnóstico** | Exportación anonimizada del estado del sistema que el cliente envía al soporte. |
| **ADR** | Architecture Decision Record: decisión arquitectónica documentada con contexto y consecuencias. |
| **MSI** | Mutation Score Indicator: porcentaje de mutantes detectados por las pruebas. |
| **RPO / RTO** | Pérdida de datos máxima tolerable / tiempo máximo de recuperación. |

---

## Anexo A — Trazabilidad requisito → fase

Orden de ejecución: **0 → 1 → 2 → 5 → 3 → 4**.

| Fase | Requisitos incluidos |
|---|---|
| **Fase 0 — Cimientos** | RNF-M-01..06, **RNF-P-07**, RQ-06, **RQ-12**, RQ-13..14, RS-08, RS-09, RS-10 |
| **Fase 1 — MVP de fichaje** | RF-AT-01..09, RF-AT-11, RF-QR-01..06, RF-QR-08, RF-ID-01..02 (**autenticación de gestión básica, sin 2FA**), RF-ID-04..09, RF-KI-01..06, RF-KI-09, RF-GP-01, RF-GP-03, RF-PA-03..04, RF-IN-05, RF-PR-04, RN-01..09, RN-13, RN-15, RL-01, RL-03..06, RL-09, RL-12, RS-01..04, RS-07, RS-12, **RS-13**, RNF-D-02, **RNF-P-01**, **RNF-P-03**, **RNF-D-04**, **RNF-D-05**, **RQ-01..03**, **RQ-05**, **RQ-07**, **RQ-09**, **RQ-10** |
| **Fase 2 — Gestión y cumplimiento** | RF-PA-01..02, RF-PA-05, RF-IN-01..04, RF-GP-02, RF-PR-01..03, RF-QR-07, RF-ID-01..03 (**completos: 2FA y ámbito por departamento**), RN-10..12, RN-14, RL-02, RL-07..08, RL-10..11, RL-13..15, RS-05..06, **RNF-P-04..05**, **RNF-D-03** |
| **Fase 5 — Productización** | RF-PD-01..15, RL-16..21, RQ-11, **RF-GP-05** |
| **Fase 3 — Operación y refuerzo** | RF-PA-06..07, RF-KI-07..08, RF-AT-10, RF-AT-12, RF-IN-06..08, RF-GP-04, RF-PR-05..06, **RN-16**, §9 completo, RS-11, **RNF-P-02**, **RNF-P-06**, **RNF-D-01**, **RQ-04**, **RQ-08** |
| **Fase 4 — Evolución** | Cuadrantes, vacaciones con aprobación, integración de nómina |

> **Los 21 requisitos en negrita se añadieron el 14 de agosto de 2026, y no son requisitos nuevos.** Existían desde la primera redacción, con su enunciado en las secciones §6.1, §6.2 y §10, pero **este anexo no los repartía a ninguna fase**. Lo detectó `qa:traceability` al construirse en la tarea 0.7, y no como una curiosidad: el comando avisó de que había pruebas ya escritas citando `RNF-D-01` y `RQ-07` que el catálogo no reconocía.
>
> **Por qué importaba.** Este anexo es la fuente de `docs/requisitos.yaml`, y ese fichero es el alcance de lo que `qa:traceability --check` bloquea. Un requisito que no aparece aquí **no lo exige nadie, nunca**. Entre los 21 estaban `RQ-07` —la autorización negativa por endpoint, que es la regla dura 18 de `CLAUDE.md`— y `RQ-10`, el MSI del 80 % que `make mutate` ya ejecuta. Se estaban implementando; simplemente ninguna herramienta habría notado su ausencia.
>
> **`RQ-01` se movió de la Fase 0 a la Fase 1 por el mismo criterio, y esta vez lo señaló la CI.** Dice *«toda regla de negocio del §4 tiene al menos una prueba unitaria en el dominio»*, y las reglas del §4 son `RN-01..16`, que pertenecen a las Fases 1 y 2. Es **inverificable en la Fase 0 por construcción**: no hay ninguna regla que probar. Estaba ahí porque la tarea 0.3 monta la infraestructura que lo hace posible, pero montar la infraestructura no es cumplir el requisito. Lo cierra la tarea **1.2**.
>
> **Criterio de asignación:** cada uno va a la fase de la tarea que lo hace verificable, no a la que lo menciona. `RNF-P-07` (presupuesto de bundle del quiosco) va a la Fase 0 porque la tarea 0.5 ya lo fija y lo comprueba en el build; `RQ-02` y `RQ-10` a la Fase 1 porque los cierra la tarea 1.2; `RQ-07` a la Fase 1 porque la 1.7 es la que expone los primeros endpoints; `RNF-P-06` y `RQ-08` a la Fase 3, con la prueba de carga de la 3.6.
>
> **Dos son de proceso y no los verifica una herramienta:** `RNF-M-05` (presupuesto del 15 % de deuda técnica por iteración) y `RQ-12` (nada se da por terminado sin la Definición de Terminado). Se declaran con `verificacion: revision` en el catálogo y pertenecen a la lista de `revisor-codigo`, no a la cadena de calidad. Fingir que los comprueba una máquina sería peor que admitir que no.

> **Por qué RF-GP-05 se movió de la Fase 3 a la Fase 5.** La importación masiva de plantilla estaba asignada a la Fase 3, que en el orden real de ejecución (0 → 1 → 2 → 5 → 3 → 4) va **después** de la productización. Pero el asistente de puesta en marcha (RF-PD-03) es el primer contacto del cliente con el producto, y un asistente que obliga a teclear a mano la plantilla de un hotel no es un producto instalable — que es el criterio con el que se juzga la Fase 5 entera. Además, el documento 05 §10.2 ya se lo anuncia al cliente como paso de la puesta en marcha. Son 3–4 h que **cambian de fase, no que se suman**: el esfuerzo total del proyecto no varía.

> **Sobre el reparto de `RF-ID-*`.** La Fase 1 necesita una autenticación de gestión mínima —sin ella, RRHH no puede emitir tarjetas ni ver el panel de estado de credenciales (tarea 1.10)—, pero el 2FA obligatorio y el ámbito por departamento llegan con la tarea 2.1.

> **[ADR-032](adr/ADR-032-la-fase-1-entrega-un-sistema-legalmente-defendible.md) — la Fase 1 pasa de «piloto interno» a «legalmente defendible».** Cerrada solo con lo que este anexo asignaba antes del 15 de agosto de 2026, la Fase 1 no satisfacía el art. 34.9 ET: sin auditoría inmutable, sin correcciones trazadas y sin exportación para Inspección, un registro erróneo el primer día quedaba sin forma de arreglarse y sin forma de entregarse a un requerimiento. Cinco tareas de la Fase 2 se adelantan a la Fase 1 (`1.14`–`1.18` del plan de implementación) y con ellas trece requisitos:
>
> - **`RS-07`** — cadena de auditoría por hash y su verificación (tarea 1.14). `RL-01` (registro con hora concreta) ya era de la Fase 1; sin la cadena, ese registro es alterable sin dejar rastro durante toda la fase.
> - **`RN-13`, `RL-04`, `RF-PA-04`** — correcciones trazadas (tarea 1.15). Un olvido de fichaje el primer día no puede quedar sin corrección hasta la Fase 2.
> - **`RF-PA-03`** — detalle de jornada (tarea 1.16). Hoy solo el propio empleado puede ver su registro (`RL-05`); nadie con responsabilidad de gestión puede consultar nada.
> - **`RL-03`, `RL-06`, `RF-IN-05`** — exportación normalizada para Inspección (tarea 1.17). Uno de los tres pilares que el doc 02 §11.2 nombra como el recorte que no se debe hacer.
> - **`RF-PR-04`, `RNF-D-05`** — copia de seguridad diaria cifrada, verificada, con prueba de restauración (tarea 1.18). El registro con valor legal no puede depender de un disco sin copia desde el primer fichaje.
> - **`RN-15`, `RL-09`, `RL-12`** — no cambian de tarea, solo de fase: ya los construyen 1.8 y 1.9 (el quiosco y su cola offline), y el Anexo A simplemente no lo reflejaba.
>
> **Lo que NO se movió, a propósito:** `RL-02` (retención de 4 años) sigue en la Fase 2 porque una instalación de Fase 1 no tiene datos de 4 años que purgar — se satisface pasivamente por no construir todavía el purgado, no exige trabajo activo. `RN-14` (empleado de baja) sigue en la Fase 2 tal como ya anotaban las tareas 1.5 y 1.6: en la Fase 1 la revocación funciona; la conservación completa del historial en informes por periodo es de la 2.8.

> **`RS-13` entra en la Fase 1 (29 de agosto de 2026, ADR-039).** No lo construye una tarea del plan sino la rama SSDLC que cerró el hueco de OWASP A09 sobre lo ya entregado por 1.6, 1.11 y 1.12. Se añade al anexo con la implementación y sus pruebas ya en el repositorio, no antes: un requisito de seguridad que se enuncia sin evidencia es una intención, y `qa:traceability --check` lo bloquearía igual.

## Anexo B — Endpoints (contrato de referencia)

```
POST   /api/v1/scan                        Registrar escaneo (quiosco)  [scope: kiosk]
POST   /api/v1/scan/batch                  Sincronizar cola offline     [scope: kiosk]
POST   /api/v1/scan/pin                    Fichaje por PIN de respaldo  [scope: kiosk]
GET    /api/v1/kiosk/roster                Padrón mínimo cacheable      [scope: kiosk]
POST   /api/v1/kiosk/heartbeat             Latido y telemetría          [scope: kiosk]
POST   /api/v1/kiosk/pair                  Solicitar emparejamiento     [público, un solo uso]
POST   /api/v1/kiosk/pair/confirm          Confirmar código y vincular  [rol: admin]

POST   /api/v1/auth/login                  Acceso al panel de gestión   [público, throttle 5 r/m]
POST   /api/v1/auth/2fa/verify             Segundo factor TOTP          [sesión pendiente de 2FA]
POST   /api/v1/auth/2fa/enrol              Alta del segundo factor      [sesión pendiente de 2FA]
POST   /api/v1/auth/2fa/confirm            Activación del segundo factor [sesión pendiente de 2FA]
POST   /api/v1/auth/logout                 Cierre de sesión             [autenticado]
GET    /api/v1/auth/me                     Usuario, rol y ámbito        [autenticado]

GET    /api/v1/attendance/live             Presencia en tiempo real     [rol: manager+]
GET    /api/v1/employees/{uuid}/workdays   Jornadas de un empleado      [rol: manager+ | self]
POST   /api/v1/shift-entries               Alta manual                  [rol: manager+]
PATCH  /api/v1/shift-entries/{uuid}        Corrección trazada           [rol: manager+]
POST   /api/v1/shift-entries/{uuid}/void   Anulación trazada            [rol: rrhh+]

GET    /api/v1/incidents                   Bandeja de incidencias       [rol: manager+]
POST   /api/v1/incidents/{id}/resolve      Resolver incidencia          [rol: manager+]

GET    /api/v1/compliance/summary          Vista de cumplimiento        [rol: manager+]

POST   /api/v1/credentials                 Emitir credencial            [rol: rrhh]
POST   /api/v1/credentials/{uuid}/print    Generar PDF de tarjeta       [rol: rrhh]
POST   /api/v1/credentials/print-batch     Impresión masiva en A4       [rol: rrhh]
POST   /api/v1/credentials/{uuid}/deliver  Registrar entrega            [rol: rrhh]
POST   /api/v1/credentials/{uuid}/revoke   Revocar                      [rol: rrhh]
GET    /api/v1/credentials/status          Estado de credenciales       [rol: rrhh, filtro ?key_id=]

POST   /api/v1/employees/{uuid}/pin/reset  Generar o restablecer PIN    [rol: rrhh, auditado]
POST   /api/v1/employees/{uuid}/pin/deliver Registrar entrega del PIN   [rol: rrhh, auditado]

GET    /api/v1/reports/period              Informe por periodo          [rol: manager+]
POST   /api/v1/reports/exports             Generar exportación async    [rol: manager+]
GET    /api/v1/reports/exports/{id}        Estado y enlace de descarga  [rol: manager+, caducable]
GET    /api/v1/reports/legal-export        Exportación para Inspección  [rol: auditor|rrhh]
GET    /api/v1/reports/payroll-export      Salida para nómina           [rol: rrhh]
GET    /api/v1/reports/adoption            Cuadro de impacto y adopción [rol: admin|rrhh]

POST   /api/v1/me/login                    Acceso con código y PIN      [público, con throttle]
GET    /api/v1/me/workdays                 Mi propio registro           [scope: self:read]
GET    /api/v1/me/export                   Descarga de mi histórico     [scope: self:read, ?format=csv|pdf]

GET    /api/v1/settings                    Configuración de instalación [rol: admin]
PATCH  /api/v1/settings                    Modificar configuración      [rol: admin, auditado]
GET    /api/v1/license                     Estado de la licencia        [rol: admin]
POST   /api/v1/license/activate            Activar clave                [rol: admin]
POST   /api/v1/support/grants              Conceder acceso de soporte   [rol: admin, auditado]
DELETE /api/v1/support/grants/{id}         Revocar acceso de soporte    [rol: admin]
POST   /api/v1/diagnostics/bundle          Generar paquete diagnóstico  [rol: admin]
GET    /api/v1/diagnostics/errors          Histórico de errores         [rol: admin]
POST   /api/v1/diagnostics/errors/{id}/resolve  Marcar error resuelto   [rol: admin]

POST   /api/v1/employees/import            Importación de plantilla     [rol: rrhh, modo simulación]
GET    /api/v1/employees, /departments, /site, /contracts, /devices, /absences
                                            Consulta y listado            [rol: manager+]
POST/PATCH /api/v1/employees, /departments, /contracts, /devices, /absences
PATCH  /api/v1/site                         El centro de la instalación (ADR-040): sin alta ni lista  [rol: rrhh+]
                                            Alta y modificación           [rol: rrhh+]
POST   /api/v1/employees/{uuid}/offboard   Baja (RN-14)                  [rol: rrhh+]
GET    /api/v1/health  /api/v1/ready       Sondas de salud
GET    /metrics                            Métricas Prometheus          [red interna]
```

**Notas de contrato añadidas al planificar la implementación.** El `openapi.yaml` es la fuente de verdad (ADR-013) y estas decisiones se reflejan en él:

| Endpoint | Decisión | Motivo |
|---|---|---|
| `POST /api/v1/scan` y `/scan/batch` | **La petición** gana el campo `intent` (`auto` \| `break_start` \| `break_end`, opcional, `auto` por defecto), y **la respuesta** amplía `action` a `clock_in`, `clock_out`, `break_start`, `break_end` | RF-AT-12 se resuelve en el endpoint existente, sin ruta nueva. **Los dos sentidos son necesarios**: con la pausa como dos tramos (ADR-024), `break_start` y `clock_out` son idénticos para el servidor, que no puede deducir cuál es. Sin `intent` en la petición, `work_date` se atribuye mal cuando la pausa cruza medianoche. Ambos cambios son aditivos y no rompen la v1 (ADR-012) |
| `POST /api/v1/scan` | **La respuesta `200` es un `oneOf` discriminado por `action`**: `ScanAccepted` para los cuatro que crean o cierran tramo, y **`ScanDebounced` con `action: debounced`** para el anti-rebote | RF-AT-06 es **Must** de la Fase 1 y no cabía en el contrato. Con `ScanAccepted` habría que devolver una acción que no ocurrió; con `ScanRejected` se confundiría con el rechazo de credencial, que es genérico por diseño (RS-03). Es `2xx` porque la cola offline reintenta ante fallo (RF-KI-04): un `4xx` la dejaría reintentando contra una ventana ya pasada ([ADR-031](adr/ADR-031-el-antirrebote-es-un-resultado-aceptado.md)) |
| `POST /api/v1/employees/{uuid}/pin/reset` y `/pin/deliver` | **Endpoints nuevos** para generar, restablecer y registrar la entrega del PIN | RF-ID-09. El PIN sostiene RF-AT-11 y el acceso al portal (RL-05), y ninguna tarea lo proveía. Se muestra una sola vez y nunca se almacena en claro |
| `POST /api/v1/kiosk/pair` + `/pair/confirm` | **Dos pasos.** La tablet sin vincular llama a `/pair` y recibe el código de un solo uso que muestra en pantalla; el administrador lo teclea en el panel, que llama a `/pair/confirm` para vincularla al centro y emitir su token | Reconcilia RF-PD-06 («código mostrado en la tablet e introducido en el panel») con el carácter público y de un solo uso de `/pair`. `kiosk:pairing-code` se conserva como vía alternativa de consola, coherente con «el cliente no tiene por qué usar SSH» |
| `GET /api/v1/me/export` | **CSV y PDF**, seleccionables por `?format=`. CSV disponible desde la tarea 1.11; PDF se añade en la 2.9, cuando existe la maquinaria de exportación. **Sin XLSX** | CSV cubre la portabilidad del RGPD; el PDF es lo que una persona presenta. XLSX no aporta nada sobre CSV para un histórico personal |
| `GET /api/v1/credentials/status` | Admite filtro `?key_id=` | Permite ver a quién le falta reimprimir durante una rotación de clave con solape (RF-QR-07, §5.3) sin añadir un endpoint |
| `GET /api/v1/compliance/summary` | **Endpoint nuevo.** Sirve la vista de cumplimiento con filtros de periodo y ámbito | RF-PA-06 y RN-10..12 exigen la vista, y el contrato no tenía ruta para ella |
| `POST /api/v1/auth/2fa/enrol` y `/2fa/confirm` | **Dos endpoints nuevos** (tarea 2.1), los dos con la sesión pendiente que devuelve el `202` de `/auth/login`. El primero entrega el secreto TOTP y su URI `otpauth://` **una sola vez**; el segundo lo activa con un código y emite ya la sesión | Con los cuatro que este anexo lista, RS-06 es inaplicable: una cuenta nueva de `rrhh` no tendría forma de obtener su segundo factor y por tanto ninguna de entrar. La alternativa —repartir secretos por consola— obligaría al cliente a usar SSH para dar de alta a una persona. Son dos y no uno porque generar el secreto y activarlo son hechos distintos: entre ellos la cuenta tiene un secreto **sin confirmar** que no autoriza nada, así que un QR mal escaneado se repite sin dejar a nadie fuera de su cuenta. **Retirar** un segundo factor no tiene endpoint y sí comando (`identity:2fa-reset`, auditado): un «quítaselo a esta persona» por API sería, en manos de un administrador comprometido, la vía más cómoda de preparar el acceso a la cuenta de otro |
| `POST /api/v1/auth/login` | **Gana un `202`** con la sesión **pendiente** de segundo factor (`challenge_token`), además del `200` con la sesión | RS-06. Dos códigos y dos nombres de campo distintos, y no un `oneOf` sobre el `200`: un cliente que leyera `token` sin mirar nada más guardaría el token pendiente como si fuera una sesión —y ese token no autoriza nada, así que el síntoma sería un `403` en cada pantalla—. Es aditivo sobre la v1 (ADR-012) |
| `GET /api/v1/employees` y `GET /employees/{uuid}` | Pasan a exigir el ámbito **`employees:read`** en lugar de `employees:*` | RF-ID-03. «manager+» incluye al responsable de departamento, y el §7.3 del documento 02 no le daba ningún ámbito de plantilla: con la familia sin partir, dejarle leer su departamento era darle también la escritura sobre toda ella |
| Rotación de clave de firma | **No hay endpoint.** Se ejecuta con `credentials:rotate-key` | Es un acto operativo con logística de reimpresión detrás (§5.3), no una acción de panel. El panel solo necesita leer, y `/credentials/status` ya lo cubre |

## Anexo C — Catálogo de motivos de corrección

`OLVIDO_FICHAJE_ENTRADA`, `OLVIDO_FICHAJE_SALIDA`, `FALLO_TECNICO_QUIOSCO`, `TARJETA_NO_DISPONIBLE` (olvidada, perdida o deteriorada), `CREDENCIAL_NO_ENTREGADA` (pendiente el primer día), `ERROR_DE_ESCANEO_DUPLICADO`, `AJUSTE_ACORDADO_CON_RRHH`, `ALTA_RETROACTIVA`, `OTROS` (obliga a texto libre de al menos 20 caracteres).
