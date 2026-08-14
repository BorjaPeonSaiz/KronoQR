# Trazabilidad requisito → prueba

<!-- Generado por `php artisan qa:traceability` (doc 02 §9.6, RQ-13). No se edita a mano. -->

Cada prueba declara qué requisitos cubre con `->group(...)` en Pest o `{ tag: [...] }` en
Playwright. Esta matriz es la evidencia documental de que cada obligación —y en particular
cada `RL-*`— tiene una prueba automática que la verifica en cada cambio.

No lleva fecha a propósito: dos ejecuciones sobre el mismo árbol producen el mismo fichero,
así que un `git diff` sobre esta matriz solo enseña cambios reales de cobertura.

## Alcance

- Catálogo: `docs/requisitos.yaml`, **160 requisitos**.
- Fase en curso (`CURRENT_PHASE`): **0**. Orden real de ejecución: 0 -> 1 -> 2 -> 5 -> 3 -> 4.
- Pruebas etiquetadas: **41** (Pest 41, Playwright 0).
- Bloquean solo las fases ya ejecutadas: un requisito de la Fase 3 no bloquea mientras se trabaja en la Fase 1.

| Fase | ¿Ejecutada? | Requisitos | Con prueba | Sin prueba |
|---|---|---|---|---|
| 0 | sí | 15 | 10 | 5 |
| 1 | no | 57 | 9 | 48 |
| 2 | no | 45 | 0 | 45 |
| 5 | no | 23 | 0 | 23 |
| 3 | no | 20 | 2 | 18 |
| 4 | no | 0 | 0 | 0 |

## Requisitos en alcance sin prueba

Estos **bloquean** `qa:traceability --check` y, con él, la etapa ③b de la CI.

| Requisito | Fase | Enunciado |
|---|---|---|
| `RNF-M-04` | 0 | Toda decisión arquitectónica relevante queda registrada como ADR versionado en el repositorio |
| `RQ-01` | 0 | Toda regla de negocio del §4 tiene al menos una prueba unitaria en el dominio, sin base de datos ni framework |
| `RS-10` | 0 | Análisis de dependencias y de código en cada pull request; ninguna vulnerabilidad crítica o alta puede llegar a una versión publicada |

## Matriz

| Requisito | Fase | Pruebas | Enunciado |
|---|---|---|---|
| `RNF-M-01` | 0 | `html/tests/Architecture/QualityGatesTest.php:80` — exige los umbrales de cobertura del dominio y del backend | Cobertura de pruebas: ≥ 90 % en la capa de dominio, ≥ 75 % global |
| `RNF-M-02` | 0 | `html/tests/Architecture/QualityGatesTest.php:57` — exige PHPStan en el nivel maximo<br>`html/tests/Architecture/QualityGatesTest.php:70` — exige justificacion en cada supresion de PHPStan | Análisis estático en nivel máximo (PHPStan/Larastan nivel 9) sin errores suprimidos sin justificar |
| `RNF-M-03` | 0 | `html/tests/Architecture/CoreBoundariesTest.php:37` — mantiene el nucleo Attendance sin importar nada de los satelites<br>`html/tests/Architecture/CoreBoundariesTest.php:63` — mantiene la arista de ADR-025 acotada a Attendance\\Application\\Port<br>`html/tests/Architecture/CoreBoundariesTest.php:92` — mantiene los puertos de Attendance libres de tipos de satelites y del framework<br>`html/tests/Architecture/CoreBoundariesTest.php:127` — no permite dos declaraciones del mismo puerto transversal ni de su adaptador<br>`html/tests/Architecture/CoreBoundariesTest.php:138` — mantiene el puerto Clock en la capa de aplicacion de Shared<br>`html/tests/Architecture/DomainPurityTest.php:50` — no llama al reloj del sistema desde el dominio<br>`html/tests/Architecture/DomainPurityTest.php:64` — no importa Carbon en el dominio<br>`html/tests/Architecture/DomainPurityTest.php:81` — no importa el framework ni Eloquent en el dominio<br>`html/tests/Architecture/DomainPurityTest.php:97` — no conoce el puerto Clock desde el dominio<br>`html/tests/Feature/ModuleServiceProvidersTest.php:38` — carga el proveedor de servicios del modulo<br>`html/tests/Feature/ModuleServiceProvidersTest.php:44` — resuelve el puerto Clock al adaptador SystemClock<br>`html/tests/Feature/ModuleServiceProvidersTest.php:51` — sirve siempre la misma instancia del reloj<br>`html/tests/Feature/ModuleServiceProvidersTest.php:57` — arranca la aplicacion con la zona horaria en UTC<br>`html/tests/Unit/Shared/SystemClockTest.php:17` — implementa el puerto Clock que declara Shared<br>`html/tests/Unit/Shared/SystemClockTest.php:29` — devuelve el instante en UTC<br>`html/tests/Unit/Shared/SystemClockTest.php:45` — devuelve el instante actual aunque la zona del proceso no sea UTC | Las dependencias entre módulos se verifican automáticamente |
| `RNF-M-04` | 0 | — | Toda decisión arquitectónica relevante queda registrada como ADR versionado en el repositorio |
| `RNF-M-05` | 0 | — | Deuda técnica visible: presupuesto máximo del 15 % de cada iteración dedicado a su reducción |
| `RNF-M-06` | 0 | `html/tests/Architecture/QualityGatesTest.php:115` — ata cada convencion del stack a una herramienta que la verifica | El código sigue las convenciones publicadas de cada stack (documento 02, §3.5): PSR-12/PER para PHP, convenciones de Laravel, guía de estilo oficia... |
| `RNF-P-07` | 0 | `html/tests/Architecture/QualityGatesTest.php:191` — comprueba el presupuesto de bundle del quiosco en el propio build | Presupuesto de bundle del quiosco |
| `RQ-01` | 0 | — | Toda regla de negocio del §4 tiene al menos una prueba unitaria en el dominio, sin base de datos ni framework |
| `RQ-06` | 0 | `html/tests/Contract/OpenApiContractTest.php:39` — lo carga Spectator como documento OpenAPI 3.1<br>`html/tests/Contract/OpenApiContractTest.php:51` — describe solo los endpoints cuya tarea existe, y todos bajo /api/v1 | La API está descrita por un contrato OpenAPI y las respuestas se validan contra el esquema en las pruebas |
| `RQ-12` | 0 | — | Ninguna funcionalidad se considera terminada sin cumplir la Definición de Terminado del documento 02, §10.3 |
| `RQ-13` | 0 | `html/tests/Architecture/QualityGatesTest.php:202` — bloquea la integracion si un requisito implementado no tiene prueba | Trazabilidad requisito ↔ prueba, verificada por la CI. Cada prueba declara qué requisitos cubre mediante una etiqueta (RF-, RN-, RL-, RS-) |
| `RQ-14` | 0 | `html/tests/Architecture/QualityGatesTest.php:91` — declara las cinco suites de la piramide de pruebas | Cobertura por niveles obligatoria según la naturaleza de la funcionalidad, no a criterio de quien la implementa |
| `RS-08` | 0 | `html/tests/Architecture/QualityGatesTest.php:122` — mantiene los secretos fuera del control de versiones<br>`html/tests/Architecture/QualityGatesTest.php:144` — no deja ningun secreto real en el fichero de ejemplo | Gestión de secretos fuera del repositorio, con rotación documentada |
| `RS-09` | 0 | `html/tests/Architecture/QualityGatesTest.php:163` — sirve las cabeceras de seguridad completas | Cabeceras de seguridad completas (HSTS, CSP estricta, X-Content-Type-Options, Referrer-Policy, Permissions-Policy limitando cámara al origen propio) |
| `RS-10` | 0 | — | Análisis de dependencias y de código en cada pull request; ninguna vulnerabilidad crítica o alta puede llegar a una versión publicada |
| `RF-AT-01` | 1 | `html/tests/Contract/OpenApiContractTest.php:128` — exige el ambito scan:write para registrar un escaneo | El sistema registra un evento de fichaje al escanear un QR válido en un quiosco autenticado |
| `RF-AT-02` | 1 | — | Si el empleado no tiene turno abierto, el escaneo abre un turno (entrada) |
| `RF-AT-03` | 1 | — | Si el empleado tiene un turno abierto, el escaneo lo cierra (salida) y calcula la duración |
| `RF-AT-04` | 1 | — | El sistema admite N tramos por jornada sin límite configurado (jornada partida) |
| `RF-AT-05` | 1 | `html/tests/Contract/OpenApiContractTest.php:184` — devuelve al quiosco lo que enseña en pantalla y nada mas | El quiosco muestra confirmación con nombre, acción realizada, hora y total acumulado del día |
| `RF-AT-06` | 1 | — | El sistema aplica un periodo de gracia anti-rebote configurable (por defecto 60 s): un segundo escaneo del mismo empleado dentro de la ventana no c... |
| `RF-AT-07` | 1 | `html/tests/Contract/OpenApiContractTest.php:138` — exige la cabecera Idempotency-Key en la escritura del quiosco<br>`html/tests/Contract/OpenApiContractTest.php:146` — obliga a que el identificador del escaneo sea un UUID v7 del cliente | Los fichajes son idempotentes: un mismo scan_id procesado dos veces produce un único evento y la misma respuesta |
| `RF-AT-08` | 1 | — | Un turno puede cruzar la medianoche sin ser dividido artificialmente |
| `RF-AT-09` | 1 | `html/tests/Contract/OpenApiContractTest.php:166` — solo admite instantes en UTC con sufijo Z | El sistema registra siempre dos marcas de tiempo: occurred_at (momento real del escaneo, incluso offline) y recorded_at (momento de recepción en se... |
| `RF-AT-11` | 1 | — | Entrada alternativa por PIN de 6 dígitos cuando el empleado no puede presentar su tarjeta |
| `RF-GP-01` | 1 | — | CRUD de empleados: datos identificativos mínimos, departamento, centro, fecha de alta y baja |
| `RF-GP-03` | 1 | — | Baja de empleado: desactivación lógica, nunca borrado |
| `RF-ID-01` | 1 | — | Autenticación de usuarios de gestión con contraseña, política de robustez, bloqueo por intentos y 2FA (TOTP) obligatorio para roles con acceso a da... |
| `RF-ID-02` | 1 | — | Modelo RBAC con roles: admin, rrhh, responsable_departamento, auditor, empleado, kiosk |
| `RF-ID-04` | 1 | — | El quiosco se autentica con token de dispositivo de ámbito restringido (solo endpoints de fichaje y sincronización), revocable individualmente y ro... |
| `RF-ID-05` | 1 | — | Portal personal del empleado: consulta de su propio registro y descarga de su histórico |
| `RF-ID-06` | 1 | — | El empleado accede al portal con su código de empleado y su PIN, el mismo del respaldo del quiosco |
| `RF-ID-07` | 1 | — | La sesión del portal tiene ámbito self:read: solo permite leer los datos del propio empleado |
| `RF-ID-08` | 1 | — | El portal es accesible desde la red interna por defecto |
| `RF-ID-09` | 1 | — | Provisión y restablecimiento del PIN. El sistema genera un PIN aleatorio de 6 dígitos al dar de alta al empleado, permite a RRHH restablecerlo y re... |
| `RF-KI-01` | 1 | — | PWA instalable, a pantalla completa, con wake lock para evitar suspensión |
| `RF-KI-02` | 1 | — | Escaneo continuo por cámara sin interacción del usuario |
| `RF-KI-03` | 1 | — | Modo offline: cola local persistente (IndexedDB) de escaneos, con resolución local del empleado contra un padrón cacheado y cifrado, y confirmación... |
| `RF-KI-04` | 1 | — | Sincronización automática al recuperar conexión, con reintentos y backoff exponencial |
| `RF-KI-05` | 1 | — | Multiidioma (mínimo español e inglés, extensible) con selector persistente y detección automática |
| `RF-KI-06` | 1 | — | Accesibilidad: contraste AA, tipografía ≥ 24 px en mensajes de confirmación, mensajes también sonoros |
| `RF-KI-09` | 1 | — | Aviso de privacidad visible en la pantalla del quiosco (información del art |
| `RF-QR-01` | 1 | — | Cada empleado tiene una credencial QR con payload opaco y firmado criptográficamente (HMAC), sin PII ni identificadores secuenciales |
| `RF-QR-02` | 1 | `html/tests/Contract/OpenApiContractTest.php:212` — no impone un patron al payload del QR<br>`html/tests/Contract/OpenApiContractTest.php:224` — tiene una unica respuesta de rechazo de escaneo<br>`html/tests/Contract/OpenApiContractTest.php:246` — hace imposible que el rechazo describa su causa | El sistema valida la firma del payload antes de resolver el empleado |
| `RF-QR-03` | 1 | — | Las credenciales son revocables y reemitibles (pérdida, robo, deterioro, baja) con invalidación inmediata de la anterior |
| `RF-QR-04` | 1 | — | Generación de tarjetas imprimibles en PDF: formato tarjeta de crédito (85,6 × 54 mm) y hoja A4 con varias por página |
| `RF-QR-05` | 1 | — | El QR se genera con corrección de errores nivel Q y tamaño mínimo garantizado, para tolerar el desgaste de una tarjeta en uso diario durante una te... |
| `RF-QR-06` | 1 | — | Registro de entrega: RRHH marca la tarjeta como entregada, con fecha y responsable |
| `RF-QR-08` | 1 | — | Panel de estado de credenciales: quién la tiene emitida, pendiente de imprimir, pendiente de entregar o revocada |
| `RL-01` | 1 | — | Registro diario con hora concreta de inicio y fin de la jornada de cada persona trabajadora |
| `RL-05` | 1 | — | La persona trabajadora puede acceder a su propio registro en cualquier momento y obtener copia |
| `RN-01` | 1 | — | Un empleado no puede tener más de un turno abierto simultáneamente |
| `RN-02` | 1 | — | Los tramos de un mismo empleado no pueden solaparse en el tiempo |
| `RN-03` | 1 | — | clocked_out_at debe ser estrictamente posterior a clocked_in_at |
| `RN-04` | 1 | `html/tests/Contract/OpenApiContractTest.php:166` — solo admite instantes en UTC con sufijo Z | Todas las marcas de tiempo se almacenan en UTC. El cálculo de jornada y los informes se resuelven en la zona horaria del centro |
| `RN-05` | 1 | — | La jornada laboral (work_date) es la fecha civil, en la zona del centro, del clocked_in_at del tramo que abre la jornada |
| `RN-06` | 1 | — | El total diario se recalcula como suma de los tramos de esa jornada dentro de la misma transacción; nunca se incrementa de forma acumulativa |
| `RN-07` | 1 | — | Duración mínima de tramo computable: 1 minuto |
| `RN-08` | 1 | — | Duración máxima de tramo antes de considerarse anómalo: 12 h (configurable) |
| `RN-09` | 1 | — | El cálculo de duración usa aritmética sobre instantes UTC, por lo que es inmune a los cambios de hora (DST) |
| `RNF-D-04` | 1 | — | Ninguna migración puede requerir parada de servicio (patrón expand / migrate / contract) |
| `RNF-P-03` | 1 | — | Tiempo de decodificación del QR en tablet de gama media |
| `RQ-02` | 1 | — | El cálculo de duraciones se prueba con pruebas basadas en propiedades, incluyendo los días de cambio de hora y turnos que cruzan medianoche |
| `RQ-03` | 1 | — | La idempotencia del fichaje se prueba con envíos concurrentes del mismo scan_id |
| `RQ-05` | 1 | — | Existen pruebas del ciclo completo offline → reconexión → sincronización → consolidación |
| `RQ-07` | 1 | `html/tests/Contract/OpenApiContractTest.php:67` — declara la seguridad de cada operacion de forma explicita | Existen pruebas de autorización que verifican que cada rol no puede acceder a lo que no le corresponde |
| `RQ-10` | 1 | — | Pruebas de mutación sobre el dominio con MSI mínimo del 80 % |
| `RS-01` | 1 | — | El payload del QR está firmado y no permite generar credenciales válidas de terceros sin la clave del servidor |
| `RS-02` | 1 | — | El sistema limita la tasa de escaneos por dispositivo, por credencial y por IP, con respuestas de tiempo constante para evitar enumeración |
| `RS-03` | 1 | `html/tests/Contract/OpenApiContractTest.php:212` — no impone un patron al payload del QR<br>`html/tests/Contract/OpenApiContractTest.php:224` — tiene una unica respuesta de rechazo de escaneo<br>`html/tests/Contract/OpenApiContractTest.php:246` — hace imposible que el rechazo describa su causa | Las respuestas de error no revelan si un código existe, está revocado o es inválido: mensaje genérico al usuario, detalle solo en el log del servidor |
| `RS-04` | 1 | `html/tests/Contract/OpenApiContractTest.php:67` — declara la seguridad de cada operacion de forma explicita<br>`html/tests/Contract/OpenApiContractTest.php:104` — declara todos los ambitos de token del documento 02 §7.3<br>`html/tests/Contract/OpenApiContractTest.php:128` — exige el ambito scan:write para registrar un escaneo<br>`html/tests/Contract/OpenApiContractTest.php:184` — devuelve al quiosco lo que enseña en pantalla y nada mas | El token del quiosco tiene ámbito mínimo, caducidad y rotación automática; su compromiso no da acceso a datos de plantilla |
| `RS-12` | 1 | — | El PIN de acceso al portal está protegido con bloqueo temporal por intentos fallidos y limitación de tasa por empleado y por IP |
| `RF-GP-02` | 2 | — | Registro de contrato: horas semanales y anuales contratadas, tipo de jornada, vigencia |
| `RF-ID-03` | 2 | — | Autorización a nivel de recurso: un responsable solo accede a los empleados de su departamento y centro |
| `RF-IN-01` | 2 | — | Informe de horas por empleado con granularidad diaria, semanal, mensual y rango libre |
| `RF-IN-02` | 2 | — | Informe agregado por departamento y centro |
| `RF-IN-03` | 2 | — | Comparativa horas trabajadas frente a horas contratadas, con desviación y exceso de jornada |
| `RF-IN-04` | 2 | — | Exportación a CSV, XLSX y PDF. Los PDF incluyen sello temporal, identificación del emisor y hash del contenido |
| `RF-IN-05` | 2 | — | Exportación normalizada para Inspección de Trabajo: registro diario por trabajador y periodo, en formato tabular legible, con las correcciones y su... |
| `RF-PA-01` | 2 | — | Vista en tiempo real de empleados actualmente fichados: nombre, departamento, hora de entrada, tiempo transcurrido, quiosco de origen |
| `RF-PA-02` | 2 | — | Filtrado por centro, departamento y estado |
| `RF-PA-03` | 2 | — | Detalle de jornada por empleado y día: todos los tramos, totales, incidencias y correcciones |
| `RF-PA-04` | 2 | — | Corrección manual de un fichaje (crear, modificar hora, cerrar turno abierto, anular) con motivo obligatorio de un catálogo más texto libre |
| `RF-PA-05` | 2 | — | Bandeja de incidencias pendientes con flujo de resolución, asignada al responsable del departamento |
| `RF-PR-01` | 2 | — | Detección de turnos abiertos anómalos superadas N horas (por defecto 12) |
| `RF-PR-02` | 2 | — | Consolidación nocturna y reconciliación de los agregados diarios contra los eventos origen, con alerta si hay divergencia |
| `RF-PR-03` | 2 | — | Purga de datos superado el periodo de retención legal, con confirmación del responsable e informe de lo purgado |
| `RF-PR-04` | 2 | — | Copia de seguridad diaria cifrada, verificada y con prueba de restauración periódica |
| `RF-QR-07` | 2 | — | Soporte de rotación de la clave de firma con periodo de solape (key_id en el payload), que permite reimprimir progresivamente sin invalidar toda la... |
| `RL-02` | 2 | — | Conservación durante 4 años |
| `RL-03` | 2 | — | Los registros permanecen a disposición de la persona trabajadora, de la representación legal y de la Inspección de Trabajo, con capacidad de entreg... |
| `RL-04` | 2 | — | El registro debe ser fiable e inalterable: cualquier modificación posterior queda trazada con autor, momento, valor anterior y motivo |
| `RL-06` | 2 | — | Exportación en formato legible y tratable, no propietario, para requerimientos de Inspección |
| `RL-07` | 2 | — | Base jurídica: cumplimiento de obligación legal (art |
| `RL-08` | 2 | — | Minimización: el sistema no almacena más datos de los necesarios |
| `RL-09` | 2 | — | Información en capas: aviso visible en el quiosco con enlace o QR a la política completa |
| `RL-10` | 2 | — | Derechos ARSULIPO: procedimientos para acceso, rectificación mediante corrección trazada, limitación y portabilidad |
| `RL-11` | 2 | — | Retención: política por tipo de dato |
| `RL-12` | 2 | — | Cifrado: TLS 1.3 en tránsito; cifrado en reposo de copias de seguridad y del padrón cacheado en la tablet |
| `RL-13` | 2 | — | EIPD: se recomienda evaluación de impacto por tratarse de control sistemático de personal trabajadora |
| `RL-14` | 2 | — | Datos alojados en la UE, en la infraestructura del propio cliente |
| `RL-15` | 2 | — | Notificación de brechas: procedimiento documentado con plazo de 72 h y capacidad técnica de determinar el alcance a partir de los logs de auditoría |
| `RN-10` | 2 | — | Descanso entre jornadas: se alerta si entre el fin de un turno y el inicio del siguiente median menos de 12 h (art |
| `RN-11` | 2 | — | Jornada diaria ordinaria: se alerta si un empleado supera 9 h efectivas en una jornada |
| `RN-12` | 2 | — | Descanso en jornada continuada: se alerta si un tramo continuo supera 6 h sin pausa registrada |
| `RN-13` | 2 | — | Ningún registro de fichaje se borra ni se sobrescribe |
| `RN-14` | 2 | — | Un empleado dado de baja conserva su historial; su credencial queda revocada y sus escaneos son rechazados |
| `RN-15` | 2 | — | El horario de un fichaje offline es el occurred_at del dispositivo, marcado con su retraso de sincronización |
| `RNF-D-02` | 2 | — | RPO ≤ 15 min (copias más WAL) |
| `RNF-D-03` | 2 | — | Degradación elegante: si cae el WebSocket, el panel hace fallback a sondeo cada 15 s |
| `RNF-D-05` | 2 | — | Prueba de restauración de copia documentada y ejecutada trimestralmente |
| `RNF-P-04` | 2 | — | Carga del panel de presencia en vivo (500 empleados) |
| `RNF-P-05` | 2 | — | Generación de informe mensual de 500 empleados |
| `RQ-09` | 2 | — | Prueba de restauración de copia automatizada y ejecutada al menos trimestralmente |
| `RS-05` | 2 | — | Todo acceso a datos personales de terceros queda registrado en el trail de auditoría |
| `RS-06` | 2 | — | 2FA obligatorio para admin, rrhh y auditor |
| `RS-07` | 2 | — | El trail de auditoría es detectablemente manipulable: cada entrada encadena el hash de la anterior; la cadena se verifica a diario y cualquier rotu... |
| `RF-AT-10` | 3 | — | Control de desfase de reloj |
| `RF-AT-12` | 3 | `html/tests/Contract/OpenApiContractTest.php:197` — distingue la pausa del fin de turno en los dos sentidos | El sistema soporta fichaje de pausa (inicio y fin de descanso) diferenciado del fin de turno, configurable por centro |
| `RF-GP-04` | 3 | — | Registro de ausencias (vacaciones, baja médica, permiso) para no contabilizar como absentismo no justificado |
| `RF-IN-06` | 3 | — | Generación asíncrona (cola) de informes de gran volumen, con notificación y enlace de descarga caducable |
| `RF-IN-07` | 3 | — | Exportación de datos para el sistema de nómina en formato configurable |
| `RF-IN-08` | 3 | — | Cuadro de impacto y adopción |
| `RF-KI-07` | 3 | — | Actualización de la app controlada: no se actualiza durante un cambio de turno; ventana de actualización configurable |
| `RF-KI-08` | 3 | — | Pantalla de diagnóstico accesible con código de servicio: estado de cámara, red, cola, token y versión |
| `RF-PA-06` | 3 | — | Vista de cumplimiento: alertas de descanso insuficiente entre jornadas, jornada diaria excesiva, ausencia de pausa en jornadas largas y exceso de h... |
| `RF-PA-07` | 3 | — | Panel de salud de quioscos: último latido, versión de la app, tamaño de la cola offline, nivel de batería |
| `RF-PR-05` | 3 | — | Resumen semanal por correo al responsable de cada departamento |
| `RF-PR-06` | 3 | — | Detección de patrones anómalos de uso de credencial: dos fichajes consecutivos en el mismo quiosco separados por segundos, coincidencias sistemátic... |
| `RN-16` | 3 | — | Secuencia imposible de credencial: dos escaneos de la misma credencial en dispositivos distintos separados por menos del tiempo mínimo de tránsito... |
| `RNF-D-01` | 3 | `html/tests/Contract/OpenApiContractTest.php:114` — publica la version desplegada en la sonda de vida<br>`html/tests/Contract/OpenApiContractTest.php:123` — separa la sonda de vida de la de disponibilidad | Disponibilidad del servicio: 99,5 % mensual |
| `RNF-P-01` | 3 | — | Latencia percibida del fichaje (de escaneo a confirmación en pantalla) |
| `RNF-P-02` | 3 | — | Latencia del endpoint de fichaje en servidor |
| `RNF-P-06` | 3 | — | Pico de concurrencia soportado |
| `RQ-04` | 3 | — | Existen pruebas E2E del flujo de quiosco con cámara simulada alimentada por vídeo con un QR real |
| `RQ-08` | 3 | — | Prueba de carga que valida RNF-P-06 antes de cada versión mayor |
| `RS-11` | 3 | — | Revisión de seguridad externa antes de la primera versión comercial y con periodicidad anual |
| `RF-GP-05` | 5 | — | Importación masiva inicial de plantilla desde CSV o XLSX, con validación previa y modo simulación |
| `RF-PD-01` | 5 | — | Cero configuración en código |
| `RF-PD-02` | 5 | — | Instalación autónoma: el personal de IT del cliente despliega el sistema siguiendo una guía, sin intervención del fabricante |
| `RF-PD-03` | 5 | — | Asistente de puesta en marcha: datos de la organización, centros, departamentos, zona horaria, perfil de convenio, primer administrador y vinculaci... |
| `RF-PD-04` | 5 | — | Licencia con clave firmada que codifica cliente, plan, límites (centros, empleados, quioscos) y vigencia del soporte |
| `RF-PD-05` | 5 | — | Degradación honesta al expirar la licencia |
| `RF-PD-06` | 5 | — | Vinculación de quiosco por código de emparejamiento mostrado en la tablet e introducido en el panel |
| `RF-PD-07` | 5 | — | Perfiles de cumplimiento configurables: jurisdicción, años de retención, descanso mínimo entre jornadas, jornada máxima diaria y semanal, pausas ob... |
| `RF-PD-08` | 5 | — | Marca blanca: logotipo, colores y nombre de la aplicación configurables, aplicados al quiosco, al panel, al portal y a las tarjetas y documentos PDF |
| `RF-PD-09` | 5 | — | Paquete de diagnóstico exportable por el administrador del cliente: versión, configuración sin secretos, estado de servicios, últimos errores, salu... |
| `RF-PD-10` | 5 | — | Actualización asistida con copia de seguridad previa automática y verificada —si la copia falla, la actualización no continúa—, migraciones reversi... |
| `RF-PD-11` | 5 | — | Acceso de soporte del fabricante: solo con concesión expresa del cliente, con caducidad, alcance limitado y registro en auditoría visible para el c... |
| `RF-PD-12` | 5 | — | Telemetría opcional y desactivada por defecto: versión y métricas técnicas agregadas, jamás datos personales ni de jornada |
| `RF-PD-13` | 5 | — | Comprobación de salud posinstalación: un comando que valida base de datos, colas, correo, certificados, permisos y espacio en disco, y devuelve un... |
| `RF-PD-14` | 5 | — | Exportación íntegra de los datos del cliente en formato abierto, ejecutable por el propio cliente sin intervención del fabricante |
| `RF-PD-15` | 5 | — | Histórico de errores en base de datos |
| `RL-16` | 5 | — | El cliente es responsable del tratamiento y operador del sistema: aloja los datos, controla los accesos y responde ante la Inspección y ante su pla... |
| `RL-17` | 5 | — | El fabricante no es encargado del tratamiento en la operación ordinaria, porque no aloja ni accede a los datos |
| `RL-18` | 5 | — | Encargo acotado a soporte |
| `RL-19` | 5 | — | El paquete de diagnóstico no contiene datos personales por defecto (RF-PD-09) |
| `RL-20` | 5 | — | Continuidad e independencia del cliente |
| `RL-21` | 5 | — | La documentación entregada debe indicar con claridad qué obligaciones asume el cliente: registro de actividades, información a la plantilla y a su... |
| `RQ-11` | 5 | — | Prueba de instalación limpia y de actualización desde la versión anterior antes de cada publicación |

## Avisos

- No existe el directorio de pruebas de playwright: `frontend-kiosk/tests/e2e`.
- No existe el directorio de pruebas de playwright: `frontend-admin/tests/e2e`.
- No existe el directorio de pruebas de playwright: `frontend-portal/tests/e2e`.

