# Obligaciones legales del hotel al usar KronoQR

> **Estado.** Redactado en la **tarea 2.10**, que es la que decide la política de
> retención por tipo de dato, y ampliado en la **5.2** con la sección 7 (el
> perfil de cumplimiento es responsabilidad del cliente, RL-16 y RL-21). La
> **tarea 5.11** lo revisa e integra en el paquete de documentación final; no lo
> reescribe.

**Quién responde de qué, dicho en una línea:** el hotel es el **responsable del
tratamiento** y del registro horario; el fabricante entrega un producto que hace
posible cumplir, y **no accede a los datos** (ADR-020). Ninguna obligación de
este documento se puede delegar en el proveedor.

---

## 1. Registrar la jornada, y conservarla cuatro años

El art. 34.9 del Estatuto de los Trabajadores obliga a **registrar diariamente**
la jornada de cada persona —hora de inicio y de fin— y a **conservar** esos
registros **cuatro años**, a disposición de la plantilla, de sus representantes y
de la Inspección de Trabajo.

Lo que eso significa en la práctica, con este producto:

| Obligación | Lo que hace el sistema | Lo que tienes que hacer tú |
| --- | --- | --- |
| Registrar cada jornada | Registra cada fichaje con su momento real y su momento de recepción, y nunca rechaza un fichaje por un fallo técnico | Asegurarte de que **todo el mundo tiene su tarjeta** antes de su primer turno, y atender las incidencias que la revisión diaria abre |
| Conservar cuatro años | Ninguna acción borra datos, salvo la purga por retención, que es manual y confirmada | **No purgar antes de tiempo** y autorizar la purga cuando venza (§4) |
| Tener el registro a disposición | `compliance:legal-export` y el panel producen el fichero normalizado en minutos | Saber quién lo genera y tener probado el procedimiento (`docs/runbooks/requerimiento-inspeccion.md`) |
| No alterar el registro | Las correcciones crean versión nueva con autor, momento y motivo | **Corregir siempre con motivo**, nunca «arreglar» por base de datos |

**El plazo de cuatro años es configuración, no código** (`compliance_profiles`,
RF-PD-07). Si tu convenio o tu jurisdicción exige otro, se cambia el perfil del
centro: el sistema recalcula el corte solo y el informe de retención lo dice.

---

## 2. Proteger los datos personales que el sistema trata

**Qué trata este producto**, y conviene que aparezca así de corto en tu registro
de actividades de tratamiento (art. 30 RGPD):

- Identificación mínima de plantilla: nombre, apellidos, código de empleado,
  centro, departamento, fechas de alta y baja. El correo es **opcional**.
- Marcas de tiempo de entrada y salida, su origen (tarjeta QR o PIN) y el
  dispositivo desde el que se registraron.
- Registro de accesos y de acciones con relevancia legal (`audit_log`).

**Qué NO trata, y no puede tratar:**

- **Nada biométrico** (ADR-009). Ni huella, ni cara, ni voz. No es una opción
  desactivada: no existe.
- **Nada de geolocalización** por persona.
- **Ningún dato de categoría especial** del art. 9 RGPD.

**Base jurídica**: cumplimiento de una obligación legal del empleador
(art. 6.1.c RGPD, en relación con el art. 34.9 ET). **No es consentimiento**, y
esto importa: nadie puede negarse a fichar, y tampoco hace falta pedir permiso
para registrar la jornada.

### Lo único que sale del servidor por su cuenta: el correo de incidencias

Todo lo demás se queda en tu instalación. El sistema envía **cada noche** un
resumen de las incidencias pendientes al **responsable de cada departamento**, y
ese correo lleva **la fecha, el nombre de la persona y el tipo de incidencia** de
cada línea. Nada más: ni horas concretas, ni datos de contrato, ni el registro
horario completo.

Tres consecuencias que son tuyas, no del fabricante:

1. **Si el correo lo entrega un servidor de un tercero** —Microsoft 365, Google
   Workspace, el SMTP de tu proveedor de hosting—, ese tercero es un **encargado
   del tratamiento** (art. 28 RGPD). Necesitas el contrato de encargo firmado y
   debe aparecer en tu registro de actividades. Si usas tu propio servidor de
   correo interno, no hay encargado nuevo.
2. **Configura el canal cifrado.** En el fichero de configuración,
   `MAIL_SCHEME=smtps` fuerza TLS y **falla** si el servidor de correo no lo
   soporta, que es lo que quieres. Dejarlo vacío negocia el cifrado «si se
   puede», y con un relevo que no lo ofrezca el correo viaja legible por la red.
3. **Cada envío queda registrado** en el trail de auditoría, con quién lo recibió
   y de qué personas iba. Es lo que te permite responder si un día hay que
   reconstruir por dónde salieron unos datos.

El aviso es una comodidad, no el registro: **ninguna incidencia se pierde ni
cambia de estado porque el correo no salga**. Siguen todas en la bandeja del
panel, que es donde se trabajan, y el resumen de la noche siguiente vuelve a
incluirlas. Si decides no usar el canal de correo, coméntalo con quien te instale
el sistema: es una decisión de configuración de tu instalación.

### La evaluación de impacto (EIPD): recomendable, y la decisión hay que escribirla

El art. 35 RGPD obliga a hacer una **evaluación de impacto** (EIPD) cuando el
tratamiento entraña un riesgo alto para los derechos de las personas, y cita
expresamente la **observación sistemática** de personas. Un registro de jornada
observa a toda tu plantilla todos los días, así que la pregunta hay que
hacérsela.

**Con este producto, la respuesta habitual es que no es obligatoria, pero sí
recomendable.** Los factores que disparan el riesgo alto no están: no hay
biometría (ADR-009: no es una opción desactivada, no existe), no hay
geolocalización por persona, no hay elaboración de perfiles ni decisiones
automatizadas, y los datos no salen de tu infraestructura —con la única
excepción del correo de incidencias descrito arriba—. Lo que queda es el dato
mínimo —quién ficha, cuándo y en qué dispositivo— tratado con la base jurídica
del art. 6.1.c.

**Lo que sí tienes que hacer**, y es tuyo porque eres el responsable del
tratamiento:

- **Deja constancia escrita del análisis**, aunque concluyas que no procede. Una
  EIPD que no se hizo y que nadie razonó es indistinguible de un olvido; media
  hoja fechada con el motivo vale.
- **Rehaz el análisis si combinas KronoQR con otra cosa**: videovigilancia,
  control de accesos por puerta, geolocalización de flotas o cualquier sistema
  que, cruzado con las marcas horarias, permita reconstruir el movimiento de una
  persona por el centro. El riesgo no lo crea este producto, lo crea la
  combinación, y entonces la EIPD sí suele ser exigible.
- **Consulta a la representación legal** de las personas trabajadoras: el propio
  art. 34.9 ET lo pide para la organización del registro, y su parecer forma
  parte del análisis.
- Si al hacerla te sale riesgo alto que no puedas mitigar, hay **consulta previa**
  a la AEPD (art. 36 RGPD) antes de empezar a tratar.

El fabricante **no puede hacer esta evaluación por ti**: depende de tu centro, de
tu plantilla y de qué otros sistemas tengas (ADR-020). Lo que sí te entrega es el
material para hacerla: qué datos trata el producto (arriba), cuánto los conserva
(§4), quién accede y con qué registro (§5) y qué medidas de seguridad hay
(`docs/07-seguridad-madurez-y-amenazas.md`).

---

## 3. Informar y dar acceso a las personas trabajadoras

- **Informa** a la plantilla, antes de arrancar, de qué se registra, con qué
  finalidad, cuánto se conserva y ante quién ejercer sus derechos (arts. 13 y 14
  RGPD). Es una comunicación tuya, no del producto.
- **Consulta a la representación legal** de las personas trabajadoras sobre la
  organización y documentación del registro, como exige el propio art. 34.9.
- **Da acceso al propio registro**: el portal del empleado existe para eso
  (código de empleado y PIN, ADR-015). Que exista no sustituye a informar de que
  existe.
- **Atiende las solicitudes de derechos** con el procedimiento de
  [`docs/runbooks/solicitud-derechos-rgpd.md`](../runbooks/solicitud-derechos-rgpd.md).
  Tienes **un mes** para responder.

---

## 4. Conservar, y borrar cuando toca

**Conservar de más también es un incumplimiento.** El RGPD (art. 5.1.e) exige no
guardar datos personales más tiempo del necesario, y pasados los cuatro años el
registro horario ya no tiene finalidad que lo sostenga.

Política por tipo de dato, que es la que aplica el sistema:

| Dato | Plazo | Quién lo fija |
| --- | --- | --- |
| Registro de jornada y `audit_log` | **4 años** | El perfil de cumplimiento del centro (jurisdicción) |
| Log técnico | **90 días** | Tu instalación |
| Histórico de errores | **90 días** | Tu instalación |
| Copias de seguridad | 30 días de serie | Tu instalación (`BACKUP_RETENTION_DAYS`) |
| Datos de contrato (horas pactadas, tipo de jornada, vigencia) | **Relación laboral + 4 años**, orientativo | **Pendiente de confirmar con tu asesoría laboral.** Hoy **se conservan**: el sistema no los purga |

**Los datos de contrato todavía no tienen purga automática, y es deliberado.** El
plazo orientativo —la duración de la relación laboral más cuatro años, por
referencia al art. 21 de la LISOS— **no está validado**, y borrar por un plazo
que luego resulte corto es peor que conservar de más un tiempo acotado.
Confírmalo con tu asesoría laboral; hasta entonces esos datos permanecen y
aparecen en cualquier respuesta a un derecho de acceso.

**La purga nunca es automática** (RF-PR-03). El sistema **propone** cada semana y
deja un informe; borrar exige una confirmación explícita del responsable y una
credencial de base de datos que **no está en la configuración de la aplicación**.
El procedimiento completo está en
[`operacion.md`](operacion.md) §3.

**Lo que tienes que hacer tú:**

1. Decidir **quién** autoriza una purga. Debe ser una persona, con nombre y
   cargo, no «el departamento de IT».
2. **Leer el informe antes de autorizar.** Dice qué tablas, qué rango de fechas y
   cuántos registros.
3. **Archivar el informe de purga** junto a la autorización. Es lo que acredita,
   dos años después, que se borró lo que había que borrar y solo eso.
4. **Custodiar la contraseña del rol `fichaje_maintenance`** fuera del servidor de
   aplicación —gestor de contraseñas, sobre sellado, lo que uséis para el resto de
   credenciales críticas—.

---

## 5. Guardar la prueba de que el registro no se ha manipulado

El sistema encadena por hash toda acción con relevancia legal y **verifica la
cadena a diario**. Si esa verificación falla, no es una avería: es un incidente
de seguridad, y tiene su procedimiento
([`rotura-cadena-auditoria.md`](../runbooks/rotura-cadena-auditoria.md)).

Lo que te corresponde:

- Que **alguien reciba** la alerta y sepa que es crítica.
- **No dar a la aplicación permisos de base de datos que no necesita.** El
  producto se instala con tres roles separados por este motivo; si alguien
  «simplifica» dándole a la aplicación el rol propietario, la garantía deja de
  existir sin que nada falle a la vista.
- **No editar la base de datos a mano.** Ninguna corrección legítima necesita
  hacerlo, y cualquiera que se haga así aparecerá al día siguiente como una
  rotura de la cadena.

---

## 6. Copias de seguridad y continuidad

Tener el registro cuatro años implica poder **recuperarlo**. El producto hace
copia diaria y semanal y verifica que se pueden restaurar, pero:

- **Sácalas del servidor.** Una copia en el mismo disco que la base de datos no
  es una copia.
- **Haz el simulacro trimestral** de restauración
  ([`restaurar-backup.md`](../runbooks/restaurar-backup.md)). Una copia que nunca
  se ha restaurado es una hipótesis.
- **Alinea la caducidad**: las copias caducan a los 30 días de serie, así que una
  supresión aplicada hoy desaparece por completo en un mes. Dilo así cuando
  respondas a una solicitud de supresión.

---

## 7. Ajustar el perfil de cumplimiento a tu convenio es tuyo

El sistema se entrega con el perfil **`ES-hosteleria`**, cuyos umbrales salen del
Estatuto de los Trabajadores: **12 h** de descanso entre jornadas (art. 34.3),
**9 h** de jornada diaria ordinaria, **6 h** de tramo continuo antes de exigir
pausa (art. 34.4), **40 h** de jornada semanal (art. 34.1) y **4 años** de
conservación (art. 34.9).

**Ese perfil es un punto de partida legal, no tu convenio.** Los convenios
provinciales y de empresa fijan a menudo jornadas, descansos y cómputos distintos
—y más favorables— que el mínimo legal. Comprobar cuáles te aplican y dejarlos
escritos en el perfil es **responsabilidad tuya**, no del fabricante: es quien
tiene el convenio delante y quien responde ante la Inspección y ante su plantilla.

| Obligación | Lo que hace el sistema | Lo que tienes que hacer tú |
| --- | --- | --- |
| Aplicar los umbrales de tu convenio | Los lee de una fila editable, nunca de código, y los aplica desde el cambio | **Contrastar el perfil con tu convenio** al poner en marcha el sistema y cada vez que se renueve |
| Cargar los festivos del centro | Guarda el calendario y lo audita | Cargarlo cada año: los festivos son del municipio y del año, y el producto se entrega **sin ninguno** |
| Justificar por qué una jornada no generó alerta | Guarda cada cambio de umbral con su valor anterior, su autor y su momento | Saber dónde está ese registro y poder enseñarlo |
| Conservar el plazo correcto | Toma los años del perfil y nunca purga sin confirmación explícita | **No bajar `retention_years` sin que lo diga tu asesoría**: por debajo del plazo legal estarías destruyendo prueba |

**Qué NO hace el fabricante, y conviene que quede escrito:** no valida tu
convenio, no te avisa de que un umbral es más laxo de lo que te corresponde y no
puede saber qué convenio te aplica. El producto hace posible cumplir; la decisión
de qué número poner es del hotel, con su asesoría laboral (RL-16, RL-21).

> **Si cambias un umbral, el cambio rige desde ese momento.** No se recalcula el
> histórico ni se cierran las incidencias ya abiertas. El procedimiento y el
> porqué están en [Configuración](configuracion.md), sección 2.4.

### El asistente de puesta en marcha te obliga a mirarlo, y ese es el único paso que no se puede omitir

De los ocho pasos del asistente, la licencia se puede omitir, el quiosco se puede
omitir y la carga de plantilla se puede omitir. **El perfil de convenio no.**

No es rigidez: es que ese paso es el único momento garantizado en el que alguien
de tu organización tiene esos cinco números delante antes de que el sistema
empiece a calcular horas con ellos. Confirmarlo no significa «los he validado con
mi asesoría» —eso sigue siendo tuyo— sino «los he visto y sé que existen».

Queda registrado quién lo confirmó y cuándo, igual que cualquier cambio posterior.

---

## 7 ter. Cargar la plantilla desde un fichero no es publicar datos

Si usas la carga masiva (CSV o Excel), tres cosas que conviene tener claras
porque afectan a lo que puedes afirmar ante una auditoría de protección de datos:

- **El documento de identidad no se almacena.** Se guarda su huella criptográfica,
  que sirve para reconocer a la misma persona entre dos importaciones y para
  cruzar con la nómina, y **no se puede volver a leer el número**. Si una copia de
  seguridad acaba donde no debe, ahí no hay documentos de identidad (RL-08).
- **El fichero no se queda en el servidor.** Se lee durante la petición y
  desaparece con ella. Por eso hay que volver a subirlo para confirmar: el
  producto no guarda un fichero con los nombres y los documentos de tu plantilla
  esperando a que alguien pulse un botón.
- **Nadie recibe ningún correo.** Ni las personas importadas, ni sus
  responsables. La credencial es una tarjeta física que hay que imprimir y
  entregar en mano, y el producto no envía invitaciones a nadie.

**Lo que sigue siendo tuyo:** informar a la plantilla del tratamiento antes de
empezar (sección 3) y borrar de tus propios equipos el fichero desde el que
importaste, que sí lleva los documentos en claro.

---

## 7 bis. Tu obligación de registrar no depende de la licencia

Conviene que lo sepas antes de que te haga falta, porque es lo primero que se
teme cuando llega un aviso de caducidad.

**El art. 34.9 ET te obliga a llevar el registro diario de jornada de toda tu
plantilla, y ese registro es tuyo, no del proveedor.** Este producto está
construido para que ninguna decisión comercial pueda dejarte incumpliendo:

- Con la licencia **caducada, ausente o ilegible**, se sigue fichando, se sigue
  consultando el registro, se sigue exportando para la Inspección de Trabajo, el
  portal del empleado sigue abierto (RL-05) y las copias siguen haciéndose.
- **Superar los límites del plan tampoco bloquea nada.** Puedes dar de alta a la
  persona que entra hoy aunque estés por encima de lo contratado, y puede fichar
  desde el primer día. Si el producto te lo impidiera, esa persona trabajaría sin
  registro y la infracción sería **tuya**.
- Lo que sí ocurre son avisos, un recorte de funcionalidades **accesorias** —los
  informes por periodo y la actualización en tiempo real de la presencia— y un
  apunte en el registro de auditoría con la fecha desde la que estás fuera de
  contrato.

**Nada de esto te exime de la parte que sigue siendo tuya**: pagar la licencia si
la has contratado, y conservar el registro cuatro años aunque termine la relación
comercial. Para lo segundo, el producto incluye una exportación íntegra que
puedes ejecutar en cualquier momento y llevarte.

> Si alguna vez encuentras que **no puedes fichar o no puedes acceder al
> registro** y la causa es la licencia, **no es lo previsto**: es una avería.
> Avisa al proveedor adjuntando la salida de `php artisan license:show` y de
> `GET /api/v1/health`.

---

## 8. Lo que el fabricante no puede hacer por ti

| No puede | Por qué |
| --- | --- |
| Entrar a mirar tus datos | El sistema corre en tu servidor y no hay acceso remoto por defecto (ADR-016, ADR-020) |
| Atender una solicitud de derechos | Eres el responsable del tratamiento |
| Responder un requerimiento de la Inspección | Lo firma el hotel |
| Recuperar un dato ya purgado | La purga es irreversible; para eso está la confirmación |
| Decirte qué umbrales fija tu convenio | No lo conoce; el perfil de cumplimiento es tuyo (§7) |
| Apagarte el fichaje por una licencia impagada | No existe el mecanismo: no hay forma de expresar la desactivación del registro legal, ni por error ni a propósito (§7 bis) |
| Revocar tu licencia a distancia | La verificación es local y sin internet: tu instalación no consulta a nadie. La palanca es la caducidad de la clave y el contrato |

Si necesitas soporte sobre una incidencia, el paquete de diagnóstico va
**anonimizado por defecto** y cualquier acceso ampliado es expreso, temporal y
queda auditado.
