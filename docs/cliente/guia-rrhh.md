# Guía de uso para RRHH — el registro horario, día a día

Esta guía es para quien **opera el producto a diario**: dar de alta a una
persona, entregarle su tarjeta, mirar la bandeja de incidencias, corregir un
olvido de fichaje y responder a un requerimiento de la Inspección. **No hace
falta saber nada de sistemas.**

> **Lo que NO está aquí, a propósito.** Instalar, copias de seguridad,
> actualizaciones y diagnóstico son del personal de IT y viven en
> [`operacion.md`](operacion.md). Los parámetros y sus consecuencias, en
> [`configuracion.md`](configuracion.md). Lo que la ley obliga al hotel, en
> [`obligaciones-legales.md`](obligaciones-legales.md). Cada cosa se explica en
> un solo sitio; aquí se enlaza.

Los nueve apartados de esta guía, por si buscas uno concreto:

1. [El vocabulario, primero](#1-el-vocabulario-primero)
2. [Alta de una persona, de principio a fin](#2-alta-de-una-persona-de-principio-a-fin)
3. [Presencia en vivo y registro horario](#3-presencia-en-vivo-y-registro-horario)
4. [La bandeja de incidencias](#4-la-bandeja-de-incidencias)
    - [4 bis. La vista de cumplimiento](#4-bis-la-vista-de-cumplimiento)
5. [Correcciones: cambiar una hora sin romper el registro](#5-correcciones-cambiar-una-hora-sin-romper-el-registro)
6. [Informes, exportaciones y la entrega a la Inspección](#6-informes-exportaciones-y-la-entrega-a-la-inspección)
7. [El perfil de cumplimiento](#7-el-perfil-de-cumplimiento)
8. [Qué hacer si…](#8-qué-hacer-si)

---

## 1. El vocabulario, primero

Son nueve palabras. Sin ellas, el resto de la guía se lee dos veces.

| Palabra | Qué es | Dónde la ves |
| --- | --- | --- |
| **Fichaje** | El gesto de acercar la tarjeta a la tablet. Queda registrado siempre, se acepte o no | Quiosco |
| **Tramo** | Un par entrada/salida. Es la unidad mínima del registro: lo que se corrige, se anula o se añade es un tramo | Registro horario |
| **Jornada** | El conjunto de tramos de un día. **Un turno de noche que entra a las 22:00 y sale a las 06:00 es un solo tramo y pertenece al día en que empezó**, no se parte en dos | Registro horario |
| **Total del día** | La suma de los tramos de una jornada. Se recalcula solo; no hay que tocarlo nunca | Registro horario |
| **Incidencia** | Algo del registro que **una persona tiene que mirar**. No es un error del sistema | Bandeja de incidencias |
| **Corrección** | El cambio de un tramo hecho por una persona autorizada, con motivo y con firma. Nunca borra lo anterior | Registro horario |
| **Credencial (tarjeta)** | El vínculo entre una persona y su tarjeta física con código QR. Se emite, se imprime, se entrega y se puede revocar | Credenciales |
| **Quiosco** | La tablet fijada en la pared donde se ficha | — |
| **Portal del empleado** | La web donde cada persona consulta y descarga **su propio** registro. Es una obligación legal, no una cortesía | [`guia-portal-empleado.md`](guia-portal-empleado.md) |

**Dos ideas que explican casi todo el comportamiento del producto:**

- **Nada se borra y nada se sobrescribe.** Corregir crea una versión nueva y
  conserva la anterior, con quién, cuándo y por qué. Es lo que hace que el
  registro valga ante una inspección.
- **El sistema nunca decide por una persona.** No cierra turnos, no inventa
  horas de salida y no resuelve incidencias solo. Cuando algo no cuadra, lo
  pone encima de la mesa y espera.

---

## 2. Alta de una persona, de principio a fin

El recorrido entero, en el orden en que se hace. Al final, la persona puede
fichar y consultar su registro.

> **Empieza con días de margen.** Entre emitir la tarjeta y tenerla impresa y
> en la mano pasa tiempo real: hay que imprimirla y plastificarla. Quien empieza
> a trabajar sin tarjeta ficha igual —con su código y su PIN— pero eso son
> tramos que alguien tendrá que revisar después.

### 2.1 La ficha

**Plantilla → «Dar de alta».**

![Pantalla de plantilla con el botón de dar de alta](img/es/rrhh-01-empleados.png)

Se rellenan nombre, apellidos y fecha de alta. Lo demás es opcional y conviene
saber por qué:

| Campo | Obligatorio | Qué conviene saber |
| --- | --- | --- |
| Nombre y apellidos | Sí | — |
| Fecha de alta | Sí | Desde ella cuenta la conservación legal de su registro. Cambiarla después es a conciencia, no de pasada |
| Departamento | No | Sirve para filtrar la bandeja y los informes, y para el alcance de los responsables de departamento |
| Correo electrónico | **No** | **Ninguna función del producto lo necesita.** No se envía nada por correo: ni la tarjeta, ni el PIN, ni el acceso al portal |
| Documento de identidad | No | **No se guarda tal cual**: el sistema conserva solo una marca calculada a partir de él, que sirve para no dar de alta dos veces a la misma persona y no para leerlo |
| Idioma | No | El idioma en el que verá el portal y en el que se le imprime la hoja de instrucciones |

**El código de empleado lo genera el sistema**, y es opaco a propósito: va
impreso en la tarjeta, así que no puede ser el número de nómina ni nada que
tenga significado. No se puede elegir.

![Formulario de alta de empleado](img/es/rrhh-02-alta-empleado.png)

### 2.2 El PIN: se ve una sola vez

Al completar el alta, el sistema emite el **PIN de seis dígitos** del portal y
**lo muestra una sola vez, en ese momento**. No se puede volver a consultar
después: si se pierde, la única salida es restablecerlo, que genera otro
distinto y anula el anterior en el acto.

![Diálogo con el PIN, visible una sola vez](img/es/rrhh-03-pin-una-vez.png)

Ten a mano dónde anotarlo **antes** de pulsar «Dar de alta». El PIN sirve para
dos cosas: entrar al portal y fichar en la tablet cuando no se tiene la tarjeta
encima.

**El PIN se entrega en mano, en persona.** No hay ningún envío electrónico: ni
mensaje, ni enlace de recuperación. No es un descuido: una clave que viaja por
un buzón acaba fichando por su titular sin que nadie se entere.

### 2.3 Las horas contratadas

El informe de horas por periodo compara lo trabajado con **lo que estaba
pactado ese día**, y para eso necesita el contrato de la persona. Hoy el
**panel no tiene pantalla de contratos**: quien administra el sistema puede
registrarlos, pero desde el panel no se hace. Mientras no haya contrato
registrado, el informe del periodo lo dice con claridad —«hay X días-persona
sin contrato registrado»— y esas filas salen con la desviación incompleta. Las
horas trabajadas y el registro legal **no se ven afectados**.

### 2.4 Emitir, imprimir y entregar la tarjeta

**Credenciales.** Es el tablero donde se ve quién puede fichar y quién todavía
no.

![Tablero de credenciales](img/es/rrhh-05-credenciales.png)

Son tres actos, en este orden, y cada uno cambia el estado:

| Acto | Botón | Estado al terminar | ¿Puede fichar? |
| --- | --- | --- | --- |
| **Emitir** | «Emitir credencial» | Pendiente de imprimir | **No** |
| **Imprimir** | «Imprimir la tarjeta» | Pendiente de entregar | **Sí, en cuanto la reciba** |
| **Entregar** | «Registrar la entrega» | Entregada | Sí |

> **Imprimir es lo que activa la tarjeta, y no hay reimpresión.** El código QR
> no existe hasta que se pulsa «Generar el PDF»: se acuña en ese momento, dentro
> del PDF, y **no se guarda en ningún sitio del que se pueda volver a sacar**.
> Por eso el botón avisa antes: *«Al imprimir se acuña el QR y no hay vuelta
> atrás: no existe la reimpresión.»* Si el PDF se pierde —se cierra la ventana,
> falla la impresora, se descarga a un equipo que no es el tuyo—, la única
> salida es **revocar esa credencial y emitir otra**:
> [`../runbooks/tarjeta-perdida-o-rota.md`](../runbooks/tarjeta-perdida-o-rota.md).
> **Imprime solo con la impresora lista.**

**El PDF de la tarjeta es un documento al portador**: quien lo tenga puede
fabricar la tarjeta de otra persona. No se guarda en el servidor, no se envía
por correo y conviene borrarlo del equipo en cuanto esté impreso.

Para un alta de temporada, el botón **«Imprimir las pendientes»** genera en una
sola hoja A4 todas las tarjetas pendientes de imprimir. Vale el mismo aviso,
multiplicado por el número de tarjetas.

### 2.5 La hoja de instrucciones

En el mismo tablero de credenciales está **«Hoja de instrucciones»**, con un
botón **«Descargar en …» por cada idioma activo** de la instalación. Es un PDF
de una cara, igual para toda la plantilla, con la marca del hotel y la dirección
de **este** portal. Se imprime y se entrega con la tarjeta.

Qué dice exactamente, y por qué conviene leerla una vez antes de repartirla:
[`hoja-empleado.md`](hoja-empleado.md).

### 2.6 La entrega: un solo acto

**La tarjeta, el PIN y la hoja se entregan juntos, en persona, en el mismo
momento.** El diálogo de entrega lo recuerda en pantalla. Después se registran
las dos entregas —«Registrar la entrega» de la tarjeta y «Registrar la entrega
del PIN»—, que quedan anotadas con la fecha y contigo como responsable.

![Ficha de empleado con el estado de su tarjeta y su PIN](img/es/rrhh-04-ficha-empleado.png)

**No es burocracia.** Ese apunte es lo que distingue «la tarjeta se perdió antes
de dársela» de «la perdió el empleado», y es lo que responde meses después a por
qué una persona no pudo fichar un martes. No se puede repetir ni deshacer:
márcalo solo cuando la entrega ya haya ocurrido.

### 2.7 Qué verá esa persona al fichar

Conviene haberlo visto una vez para poder explicarlo sin la tablet delante.

Fichaje correcto: la pantalla dice «Entrada» o «Salida» con la hora.

![Quiosco con un fichaje confirmado](img/es/quiosco-fichaje-confirmado.png)

La tablet estaba sin red: dice «Pendiente de validar». **El fichaje está
guardado y se enviará solo.** No hay que repetirlo, y el registro legal usa la
hora real del fichaje, no la de la llegada al servidor.

![Quiosco con un fichaje pendiente de validar](img/es/quiosco-fichaje-pendiente.png)

Sin tarjeta encima: «Ficha con tu código y PIN» en la propia tablet.

![Quiosco con el teclado de código y PIN](img/es/quiosco-pin-respaldo.png)

---

## 3. Presencia en vivo y registro horario

### 3.1 Presencia: quién está dentro ahora

**Presencia** muestra quién tiene un turno abierto en este momento, desde qué
hora y por qué quiosco fichó. Se actualiza sola.

![Pantalla de presencia en vivo](img/es/rrhh-06-presencia.png)

**Qué significa «Dentro ahora»:** que esa persona fichó una entrada y todavía no
ha fichado la salida.

**Qué NO significa:**

- No significa que esté físicamente en el hotel. Significa que su último fichaje
  fue una entrada. Alguien que se fue sin fichar la salida sigue apareciendo
  dentro hasta que alguien lo corrija.
- No significa que las horas ya cuenten. Un turno abierto sigue creciendo: su
  total **no sirve para una nómina** hasta que se cierre.
- **No es la pantalla para arreglar nada.** Presencia es solo lectura. Rectificar
  un tramo se hace desde el registro horario de la persona.

### 3.2 El registro horario de una persona

Desde su ficha, **«Ver el registro horario de esta persona»**. Es la pantalla
donde se ve —y se corrige— la verdad de cada día.

![Detalle de una jornada con sus tramos](img/es/rrhh-07-jornada.png)

Qué mirar:

- **Las horas están en la zona horaria del centro**, no en la del ordenador
  desde el que miras.
- Cada tramo trae su **origen**: escaneo de la tarjeta, PIN en el quiosco o
  registrado a mano. Un tramo escrito a mano vale exactamente igual que uno
  escaneado; lo que cambia es que lleva una corrección detrás que lo explica.
- **«Turno abierto»**: falta la salida. El total del día va a subir.
- **«Incidencia»**: algún tramo quedó marcado para revisión. No es un error del
  sistema: es que alguien tiene que mirarlo.
- **Consultar el registro de otra persona queda anotado** en el registro de
  auditoría, con quién ha mirado, de quién y qué periodo. Es normal y es
  intencionado.

### 3.3 La pausa en el registro

Si el hotel tiene activado el **fichaje de pausa**, una jornada con descanso no
se ve como un tramo con un agujero dentro: se ve como **dos tramos con la marca
«Pausa» entre ellos**. Eso es todo lo que cambia, y es lo que hay que saber para
explicárselo a alguien:

- **La pausa no cuenta como tiempo trabajado.** No hay ninguna resta: el tiempo
  del descanso sencillamente no está dentro de ningún tramo, así que el total
  del día ya sale bien sin tocar nada.
- **Un descanso de madrugada no parte la jornada.** Quien entra a las 22:00,
  descansa a las 02:00 y vuelve a las 02:30 sigue en la jornada del día
  anterior, y el día siguiente le sale a cero. Es la misma regla de siempre: la
  jornada pertenece al día en que empezó.
- **Pero una pausa no se queda abierta para siempre.** La vuelta continúa la
  jornada anterior solo si llega **antes** del descanso mínimo entre jornadas que
  tengas puesto en el perfil (`min_rest_hours`, 12 h de serie —§7—). Pasado ese
  tiempo, el siguiente fichaje **abre una jornada nueva**, que es lo correcto:
  quien pulsó «Pausa» a las 15:00 y ficha al día siguiente a las 08:00 está
  empezando su turno, no volviendo de un descanso de diecisiete horas.
- **Una pausa sin vuelta no abre incidencia.** Si alguien pulsa «Pausa», pasa la
  tarjeta y se va a casa, la jornada queda **cerrada en ese momento**, con la
  marca «Pausa» y sin tramo de vuelta. El sistema no se inventa una hora de
  salida ni te avisa: **se corrige a mano como cualquier otro tramo** (§5), y el
  motivo habitual es «Olvido de fichaje de salida». Míralo cuando un total del
  día salga más corto de lo esperado.
- **Se distingue de un hueco sin explicar.** Dos tramos seguidos sin la marca
  «Pausa» son otra cosa —una salida y una entrada—, y eso es exactamente lo que
  el fichaje de pausa viene a aclarar.
- **Una pausa mal fichada se corrige como cualquier otro tramo**, desde la misma
  pantalla y con su motivo (§5). No hay un procedimiento aparte: lo que hay que
  corregir es una hora de entrada o de salida, como siempre.
- **Si el hotel no tiene activado el fichaje de pausa, aquí no cambia nada** y
  no verás ninguna marca.

---

## 4. La bandeja de incidencias

**Incidencias** es la lista de lo que el sistema ha encontrado y no puede
resolver solo. Se llena sola cada madrugada, al revisar el registro.

![Bandeja de incidencias](img/es/rrhh-10-incidencias.png)

### 4.1 Qué genera cada tipo, y cuál corre prisa

| Tipo | Severidad | Qué lo genera | Qué suele significar |
| --- | --- | --- | --- |
| **Descanso insuficiente** | **Alta** | Entre el fin de un turno y el inicio del siguiente median menos horas que el mínimo del perfil (12 h de serie) | Un cierre y una apertura seguidos, o un olvido de fichaje que junta dos jornadas. **Tiene consecuencia sancionadora: mírala el mismo día** |
| **Turno abierto sin cerrar** | Media | Un turno lleva abierto más del máximo (12 h de serie) | Casi siempre, un olvido de fichar la salida |
| **Jornada demasiado larga** | Media | La suma del día, o un solo tramo, supera la jornada máxima del perfil | Horas de más reales, o dos tramos que en realidad eran uno |
| **Jornada demasiado corta** | Baja | Un tramo por debajo de la duración mínima computable | Un doble escaneo, o una entrada y una salida seguidas por error |
| **Desfase de reloj** | Baja | La tablet tenía la hora desviada al fichar | **El fichaje se registró igual.** Es un aviso para IT sobre esa tablet, no un problema de la persona |
| **Salida sin fichar** | Media | Describe un olvido de salida **ya cerrado a mano** | **No la abre nadie automáticamente.** Mientras el turno sigue abierto, lo que hay es «Turno abierto sin cerrar» |
| **Sin pausa registrada** | Media | Un tramo continuo por encima del umbral del convenio | **Se abre solo si el hotel tiene activado el fichaje de pausa.** Sin él, el sistema no puede distinguir «no descansó» de «descansó y no lo fichó», y no avisa de ninguna |
| **Fichaje fuera de orden** | Media | Llegó un fichaje que no cabe en el registro de esa persona: una **salida** con hora anterior a la entrada que ya estaba abierta, o una **entrada** que caería dentro o antes de un tramo ya cerrado —aunque el tramo sea de otra jornada, como después de un turno de noche— | Casi siempre, una tablet que estuvo sin red: su cola llegó con retraso y desordenada. **El fichaje queda guardado y señalado para revisión, y la jornada no cambia sola** (§4.4) |
| **Patrón anómalo de uso de la credencial** | Alta | — | **Hoy no se abre ninguna.** El detector llega en una versión posterior |

> **El filtro «Tipo» enseña los nueve, y cuántos se abren solos depende de un
> ajuste.** Seis lo hacen siempre —descanso insuficiente, turno abierto,
> jornada demasiado larga, jornada demasiado corta, desfase de reloj y fichaje
> fuera de orden—, y **«Sin pausa registrada» se suma a ellos en cuanto el hotel
> activa el fichaje de pausa** (Panel → «Ajustes operativos» → «Fichaje de
> pausa»; lo explica
> [`configuracion.md`](configuracion.md) §2.1). Los otros dos están en la lista
> porque el sistema tiene que poder registrarlos sin cambiar nada cuando llegue
> su momento. No es un fallo de la instalación.
>
> **Activar el fichaje de pausa no llena la bandeja de golpe.** La revisión
> empieza a abrir «Sin pausa registrada» en su pasada siguiente y solo sobre los
> últimos días; no vuelve atrás sobre el histórico. Y desactivarlo **no cierra**
> las que ya estén abiertas: se dejan de abrir nuevas y las que hay se resuelven
> como cualquier otra.
>
> **Dos cosas que la bandeja NO te va a decir, y conviene saberlas para no
> esperarlas:** el hueco entre dos tramos de una misma jornada —la jornada
> partida— **no se mira** (§4 bis.2), y una **pausa sin vuelta** —alguien pulsa
> «Pausa» y se va a casa— **no abre ninguna incidencia**: la jornada queda
> cerrada ahí y se corrige a mano (§3.3).

### 4.2 El sistema nunca cierra un turno por su cuenta

Es la pregunta que siempre aparece: *«si lleva 14 horas abierto, ¿por qué no lo
cierra el sistema?»*.

**Porque cerrarlo sería inventarse una hora de salida.** Si el sistema cerrara el
turno a las 12 h, el registro diría que esa persona trabajó 12 horas cuando
probablemente trabajó 8 y se olvidó de fichar. Un registro horario que se
inventa horas no es defendible ante la Inspección, y además paga de más o de
menos en la nómina.

Lo que el sistema hace es abrir la incidencia y esperar a que **una persona
firme** la hora correcta. Es una garantía, no una carencia: cada hora del
registro, o la fichó alguien, o la escribió alguien con su nombre al lado.

Mientras tanto, **nadie se queda sin poder fichar**: quien tiene el turno
abierto sigue pasando su tarjeta con normalidad, y su siguiente escaneo cerrará
ese turno.

### 4.3 Cómo se resuelve una

Una incidencia se cierra en dos pasos, y el orden importa:

1. **Primero se arregla el registro**, si hay algo que arreglar: se corrige el
   tramo desde el registro horario de la persona (§5).
2. **Después se cierra la incidencia**: botón «Resolver», y se elige qué ha
   pasado.

![Diálogo de resolución de una incidencia](img/es/rrhh-11-resolver-incidencia.png)

Hay dos desenlaces y son distintos:

- **«Se ha corregido»** — había algo mal y se ha rectificado.
- **«Revisada: no había nada que corregir»** — el dato era raro pero cierto. Un
  turno de 11 horas puede ser verdad.

**La nota es obligatoria** y queda en el historial de la incidencia. Escribe qué
se hizo o por qué no hacía falta hacer nada: dentro de seis meses, «revisado» no
explica nada y «cambió el turno con la compañera de tarde, confirmado con el
jefe de sala» sí.

> **Resolver una incidencia no cambia ninguna hora.** Son dos acciones
> distintas a propósito: cerrar la bandeja sin corregir el registro deja la
> bandeja limpia y el registro mal.

Si dos personas la resuelven a la vez, el sistema avisa de quién la cerró antes
y con qué desenlace, en lugar de pisar el trabajo de nadie.

### 4.4 «Fichaje fuera de orden»: un fichaje que no cabe en la jornada

Es el único tipo de la tabla que no describe un exceso ni un olvido, sino un
fichaje que **llegó tarde y desordenado**, así que merece su propio apartado.

**Qué ha pasado.** Cuando una tablet se queda sin red no deja de fichar: guarda
cada escaneo con su **hora real** y los envía en cuanto la recupera. Casi
siempre encajan sin más. De vez en cuando llega uno que **no cabe en ninguna
jornada**, y pasa de dos maneras:

- **Una salida anterior a la entrada que ya estaba abierta.** La persona tiene
  un turno abierto desde las 14:00 y, desde la cola de una tablet que estuvo sin
  red, llega su salida de las 13:40. Sería un tramo que termina antes de
  empezar.
- **Una entrada que cae dentro o antes de un tramo ya cerrado.** La persona
  fichó de 09:00 a 13:00 en la tablet de recepción y, por la tarde, la tablet de
  cocina vacía su cola con una entrada de las 08:00. Serían dos tramos pisándose
  el mismo rato.

En los dos casos el resultado sería un registro imposible, y **no hay ninguna
hora que el sistema pueda calcular** a partir de ese fichaje. Así que no la
inventa.

**Qué hace el sistema con él.** Tres cosas, y conviene saber las tres:

- **Lo guarda**, con su hora tal y como llegó, y lo **señala para revisión**. No
  se descarta en silencio ni se ajusta para que cuadre. Esa hora te llega en la
  incidencia, no en la jornada: la razón, un poco más abajo.
- **No toca la jornada.** Los tramos y los totales de ese día siguen siendo
  exactamente los que eran. Nada se cierra, nada se inventa.
- **No lo reintenta.** La tablet deja de insistir con ese fichaje y su cola se
  vacía con normalidad; el resto de fichajes de esa misma cola se registran sin
  problema.

A la madrugada siguiente, la revisión abre la incidencia **«Fichaje fuera de
orden»** sobre esa persona y esa jornada: una sola, aunque hayan llegado varios.

**Dónde está la hora: en la incidencia, no en el registro horario.** Es lo
primero que hay que saber, porque ahorra buscar donde no está. El registro
horario de una persona enseña **los tramos de su jornada**, no los escaneos: ese
fichaje **no aparece ahí**, y la jornada se ve exactamente igual que antes de
que llegara.

Lo que sí lleva el dato es la **propia incidencia**. Pulsa «Resolver» sobre su
fila y, encima del formulario, la ventana **«Cerrar incidencia»** muestra:

| Lo que dice | Qué es |
| --- | --- |
| **Hora del fichaje** | La hora real con la que llegó, **en el horario del centro**. Es la pista de lo que de verdad pasó |
| **Identificador del escaneo** | El código de ese fichaje concreto. Cópialo si vas a escribir un parte o preguntar a IT |
| **Escaneos fuera de orden** | Cuántos llegaron así en esa jornada. La incidencia es una sola aunque fueran varios, y la hora que se muestra es la del primero |

**Abrir esa ventana no resuelve nada**: puedes leer los datos y cerrarla sin
elegir desenlace ni escribir nota. Resolver es pulsar el botón de confirmar, no
abrir el diálogo.

**Cómo se resuelve.** Cuatro pasos, y el orden ahorra trabajo:

1. **Abre «Resolver» y apunta la hora del fichaje.** Cierra la ventana sin
   confirmar.
2. **Abre el registro horario de la persona** en esa jornada y compara esa hora
   con los tramos del día. Si hace falta, pregunta a la persona o a su
   responsable.
3. **Corrige el registro** (§5) con la acción que corresponda: **«Añadir un
   tramo»** si faltaba una entrada más temprana, **«Corregir las horas»** si el
   tramo existente no tiene las horas reales, o **«Anular el tramo»** si el que
   sobra es ese. El motivo suele ser **«Fallo técnico del quiosco»**, **«Olvido
   de fichaje de entrada»** o **«Escaneo duplicado»**.
4. **Vuelve a «Resolver» y cierra la incidencia** con su nota (§4.3). Si al
   final el registro era correcto, «Revisada: no había nada que corregir» es un
   desenlace legítimo y queda explicado.

> **El registro legal no se cambia solo, tampoco aquí.** El sistema registra lo
> que llegó y avisa; la hora la firma una persona, con su nombre y su motivo, y
> el valor anterior se conserva (§5.3).

**Si se repite siempre en la misma tablet** no es un problema de la plantilla,
es de red o de la hora de ese punto: pásaselo a IT con el nombre del quiosco y
la fecha. El procedimiento es
[`../runbooks/cola-offline-atascada.md`](../runbooks/cola-offline-atascada.md).

> **Lo que ocurrió antes de esta versión no está en el registro.** Hasta ahora,
> un fichaje así no llegaba a guardarse: la tablet lo reintentaba una y otra vez
> y el servidor no lo aceptaba nunca. **No hay nada anterior que revisar ni que
> recuperar**, y no se reprocesa ningún día pasado.
>
> **Pero sí vas a ver incidencias con fecha antigua los primeros días, y no es
> un error.** Lo que decide si un fichaje se revisa es **cuándo llega al
> servidor**, no de cuándo es su hora. Las tablets que llevaban semanas con uno
> atascado lo entregan en cuanto se actualiza el servidor, así que **entran en la
> revisión de esa misma madrugada** — y la incidencia se abre sobre **la jornada
> a la que pertenece esa hora real**, que puede ser la de hace dos semanas. Se
> resuelven igual que las del día: ábrelas, compara y corrige. Cuando las
> tablets terminen de vaciarse, este tipo vuelve a aparecer solo de vez en
> cuando.

---

## 4 bis. La vista de cumplimiento

**Cumplimiento** revisa el registro contra los umbrales legales del perfil del
centro y enseña, para tu ámbito, las jornadas y las semanas que se salen de
ellos. La bandeja (§4) te dice **qué hay pendiente de resolver**; esta pantalla
te dice **si lo registrado cumple la norma**, lo haya abierto alguien o no.

No son la misma lista: la bandeja tiene ocho tipos de incidencia y esta pantalla
cuatro reglas legales. Coinciden en las dos primeras, y eso es a propósito.

### 4 bis.1 Los cuatro avisos, y con qué umbral

Arriba hay cuatro tarjetas, una por regla, con el recuento del periodo y el
umbral que se ha aplicado:

| Aviso | Qué mira | Campo del perfil | De serie en `ES-hosteleria` |
| --- | --- | --- | --- |
| **Descanso mínimo entre jornadas** | Las horas entre la última salida de una jornada y la primera entrada de la siguiente | `min_rest_hours` | 12 h |
| **Jornada diaria ordinaria** | La suma de los tramos de un mismo día | `max_daily_hours` | 9 h |
| **Tramo continuo máximo sin pausa** | El tramo cerrado más largo del día | `break_required_after_hours` | 6 h |
| **Jornada semanal ordinaria** | La suma de las horas de la semana | `max_weekly_hours` | 40 h |

Cada tarjeta se llama igual que el campo del perfil del que sale, para que no
haya que traducir nada entre las dos pantallas. Y el umbral no está escondido:
debajo del nombre, la tarjeta lo escribe con todas las letras —«**12 h 00 min
según el perfil ES-hosteleria**»—. Es deliberado: **un aviso cuyo criterio no se
ve es un aviso que nadie puede defender delante de un empleado**. Si ajustas un
umbral en el perfil de cumplimiento (§7), esta pantalla cambia con él desde el
momento en que se guarda.

Los filtros son periodo, departamento y regla. **Sin fechas se enseñan los
últimos 28 días** —cuatro semanas, que es lo que se revisa—. El periodo tiene un
tope, 92 días de serie; si pides más, la pantalla lo dice y no consulta. Cuando
no hay nada que avisar lo dice también, y con los criterios aplicados a la
vista: «Sin alertas en el periodo» sin decir de qué periodo no valdría de nada.

### 4 bis.2 Qué cuenta y qué no

Esto es lo que más preguntas genera, y conviene tenerlo claro **antes** de
comentar un aviso con nadie:

- **El descanso se mide entre jornadas, no dentro del día.** Es el hueco entre
  la última salida de una jornada y la primera entrada de la siguiente. El rato
  que alguien pasa fuera a media mañana no es descanso entre jornadas y no se
  cuenta aquí. Y sin jornada anterior no hay nada que medir: el primer día del
  registro de una persona nunca avisa.
- **⚠️ El hueco DENTRO de una jornada no se mira, y eso el sistema no te lo va a
  avisar nunca.** Una jornada partida —salir a las 15:00 y volver a las 23:00 del
  mismo día— son dos tramos del mismo día con ocho horas de por medio, y **no
  aparece ni aquí ni en la bandeja**. Si esa vuelta cayera a las 00:30 ya sería
  otra jornada y sí avisaría. No es un fallo: dentro de una jornada partida ese
  hueco es la pausa, y avisar de todos convertiría cada turno partido del hotel
  en una alerta. **Pero es un caso que el producto no cubre**, así que si tu
  convenio dice algo sobre los descansos dentro de la jornada partida, eso se
  revisa a mano: **el sistema aplica el umbral que tengas puesto, no dictamina si
  una jornada cumple el Estatuto ni tu convenio**. Contrastarlo es de tu asesoría
  laboral, no del fabricante —
  [`obligaciones-legales.md`](obligaciones-legales.md) §7.
- **Los turnos de noche no se parten.** Un turno de 22:00 a 06:00 es uno solo y
  pertenece al día en que empezó. El descanso siguiente se mide desde su fin
  real, las 06:00, no desde medianoche.
- **Solo cuentan los tramos cerrados.** Un turno todavía abierto vale cero horas
  y la fila sale marcada **«Turno abierto»**. No significa que ese día cumpla:
  significa que aún no se puede saber. Cuando alguien cierre el turno, el total
  sube y el aviso puede aparecer.
- **Los totales son los mismos que ves en el registro de la persona.** La
  pantalla no recalcula las horas por su cuenta: las lee.
- **«Tramo continuo máximo sin pausa» se evalúa solo si el hotel tiene activado
  el fichaje de pausa.** Mientras no lo esté, su tarjeta aparece marcada **«No
  se evalúa»**, con el motivo escrito —«No se evalúa mientras el fichaje de
  pausa esté desactivado en Ajustes operativos»— y no produce ninguna fila. El motivo es el mismo que en la
  bandeja (§4.1): si el quiosco no registra la pausa, el sistema no puede
  distinguir «no descansó» de «descansó y no lo fichó», y avisar en esas
  condiciones sería avisar de casi todo el mundo casi todos los días. El umbral
  se guarda y se audita igual, y la regla empieza a contar sola en cuanto se
  activa el ajuste, sin que haya que tocar nada más. Se enseña en lugar de
  ocultarse para que sepas que la regla existe y con qué umbral se aplicará. Lo
  activa tu IT en Panel → «Ajustes operativos» → «Fichaje de pausa»
  ([`configuracion.md`](configuracion.md) §2.1), y conviene hablarlo antes: la
  bandeja empieza a recibir avisos que hoy no recibe.
- **Las dos primeras cuentan igual que la bandeja.** El descanso mínimo entre
  jornadas y la jornada diaria ordinaria se miden con el mismo criterio que la
  revisión de cada madrugada —la que abre «Descanso insuficiente» y «Jornada
  demasiado larga» en la bandeja (§4.1)—, así
  que cuando ya hay una incidencia abierta para esa persona, ese día y esa
  regla, la fila la enlaza. La semanal **no abre incidencia nunca**
  (§4 bis.3).

**La semana es la del perfil, y siempre entera.** Empieza el día que diga el
campo `week_starts_on` —lunes de serie— y son siete fechas. Si el periodo que
has pedido corta una semana por la mitad, **esa semana se evalúa completa de
todos modos**, con los días de fuera del periodo incluidos. Lo contrario daría
un total semanal que no coincide con el que la persona ve en su propio registro,
y esa diferencia no hay manera de explicarla.

### 4 bis.3 Qué significa el aviso semanal

El aviso de **jornada semanal ordinaria es informativo**, y es el único de los
cuatro que **no abre incidencia**. No es un descuido: el
Estatuto de los Trabajadores fija las cuarenta horas semanales **en cómputo
anual** (art. 34.1), así que una semana de cuarenta y cuatro horas no es por sí
sola un incumplimiento —puede quedar compensada con otra de treinta y seis—.

Lo que hace la pantalla es señalártela **para que la mires con el convenio
delante**. Muchos convenios de hostelería fijan reglas propias de distribución
irregular, de máximo semanal o de descanso compensatorio, y esas sí se pueden
incumplir con una semana así. Esa lectura es del hotel: el sistema no conoce tu
convenio y no la puede hacer por ti.

### 4 bis.4 Cómo se lee una fila

Cada fila es un empleado y una jornada —o un empleado y una semana, en el aviso
semanal—, con las horas en la zona horaria del centro, como en todo el panel.
Las columnas son estas, y los tres números van siempre en horas y minutos:

| Columna | Qué es |
| --- | --- |
| **Empleado** | Quién |
| **Jornada o semana** | El día del aviso, o el lunes a domingo de la semana |
| **Medido** | Lo que dice el registro: el descanso que hubo, las horas que se trabajaron |
| **Umbral** | Lo que pide el perfil |
| **Diferencia** | Lo que separa a los dos: «**Faltan** 2 h 00 min» cuando el descanso se queda corto, «**Sobran** 0 h 40 min» cuando la jornada se pasa |
| **Incidencia** | El enlace a la de la bandeja, si existe |

Leída del tirón, una fila de descanso dice: *Medido 10 h 00 min · Umbral
12 h 00 min · Faltan 2 h 00 min*. Esa resta es la que hay que poder explicar, y
por eso los tres números están a la vista y no solo el último.

Y dos enlaces:

- **El nombre del empleado** lleva a su registro horario, situado en la jornada
  —o en la semana— del aviso, que es donde se mira y donde se corrige (§5).
- **«Ver incidencia»** aparece solo cuando la bandeja ya tiene una abierta para
  ese mismo caso, y lleva a **la bandeja de incidencias acotada a esa persona**,
  que es donde se resuelve (§4.3). No la resuelve por ti: ningún enlace de esta
  pantalla escribe nada.

### 4 bis.5 Quién la ve, y qué queda anotado

- Un **responsable de departamento** ve a la gente de su departamento y nada
  más; también los recuentos de las tarjetas son solo de su gente.
- **RRHH** y **administrador** lo ven todo.
- El **auditor** no entra en esta pantalla. Auditar es revisar lo que quedó
  escrito, no gestionar el día a día.

**Cada consulta queda anotada en el registro de auditoría**, igual que consultar
el registro horario de una persona (§3.2): quién ha mirado, qué periodo y con
qué filtros. Se anota el alcance de la consulta, nunca los nombres de quienes
aparecieron en ella. Es normal y es intencionado.

**Esta pantalla no depende de la licencia.** Es una lectura del registro legal
contra los umbrales legales: aunque la licencia esté caducada, sigue
funcionando igual (§8, «hay un aviso de licencia en el panel»).

### 4 bis.6 Qué hacer con un aviso

Un aviso no es una sanción ni un fallo del sistema: es una jornada que alguien
tiene que mirar. El orden que funciona:

1. **Contrástalo con el registro de la persona.** Entra por su nombre y mira el
   día. Muchas veces la explicación está a la vista: una salida sin fichar que
   junta dos jornadas, un doble escaneo, un turno que se cerró al día siguiente.
2. **Si el registro está mal, corrígelo** desde ahí, con su motivo (§5). El
   aviso desaparece la próxima vez que se abra la pantalla, porque se calcula
   sobre el registro y no sobre una lista guardada.
3. **Si el registro está bien, habla con la persona y con su responsable** y
   contrasta el caso con el convenio que os aplique. Un descanso de once horas
   puede ser cierto y aun así ser un problema; una semana de cuarenta y cuatro
   horas puede ser cierta y estar perfectamente compensada.
4. **Si además hay una incidencia abierta**, ciérrala al terminar, con la nota
   de qué pasó (§4.3). Resolver la incidencia no cambia ninguna hora: son dos
   acciones distintas.

> **La pantalla no corrige nada y no guarda ningún veredicto.** Se recalcula
> cada vez que se abre, sobre el registro y los umbrales de ese momento. Si
> mañana cambias un umbral del perfil, lo que se vea mañana será lo que diga el
> umbral nuevo, también para las jornadas de la semana pasada.

**La bandeja y esta pantalla se comportan distinto ante un cambio de umbral, y
hay que saber explicarlo.** No es una incoherencia: son dos cosas con dos
propósitos.

| | La bandeja de incidencias (§4) | La vista de cumplimiento (§4 bis) |
| --- | --- | --- |
| Qué es | Una lista de trabajo: cada incidencia se abrió un día concreto y alguien la tiene que cerrar | Una consulta que se calcula en el momento |
| Al cambiar un umbral | **No se reprocesa el histórico.** Las incidencias ya abiertas se quedan con el criterio con el que se abrieron, y ninguna se cierra ni se reabre sola | **Siempre recalcula con el umbral vigente** en el momento de consultar |
| Cómo se sabe con qué criterio | Por el registro de auditoría del perfil: quién cambió qué valor y cuándo (§7) | Por la propia pantalla, que enseña el perfil y el umbral de cada regla |

Por eso esta pantalla enseña siempre el nombre del perfil y los umbrales con los
que ha calculado: es lo que permite decir, delante de un empleado o de un
inspector, **con qué criterio se avisó y desde cuándo rige ese criterio**. Y por
eso la bandeja no se reprocesa: reabrir hoy incidencias de jornadas ya
entregadas a la plantilla o a la Inspección, con un umbral que entonces no
existía, no ayudaría a nadie.

---

## 5. Correcciones: cambiar una hora sin romper el registro

### 5.1 Cuándo se corrige

Se corrige cuando el registro **no dice lo que pasó**: un olvido de fichaje, un
doble escaneo, un día trabajado antes de tener la tarjeta. No se corrige para
«cuadrar» un total ni para ajustar una nómina: eso tiene otro nombre y otra
consecuencia.

Desde el registro horario de la persona hay tres acciones:

| Acción | Cuándo |
| --- | --- |
| **«Añadir un tramo»** | La persona trabajó y no hay ningún fichaje: no fichó la entrada, no tenía tarjeta, o la jornada es anterior a la puesta en marcha |
| **«Corregir las horas»** | El tramo existe pero la entrada o la salida no son las reales |
| **«Anular el tramo»** | El tramo no debería existir: un doble escaneo, un fichaje de otra persona |

![Formulario de corrección de un tramo](img/es/rrhh-08-correccion.png)

### 5.2 Los nueve motivos, con un ejemplo de cada uno

El motivo es obligatorio, **queda en el registro legal y puede leerlo una
inspección**. Sin datos de salud y sin juicios de valor sobre la persona.

| Motivo (tal cual aparece en el panel) | Ejemplo real |
| --- | --- |
| **Olvido de fichaje de entrada** | Entró a las 07:00 en cocina, no pasó la tarjeta y su primer fichaje es la salida de las 15:00 |
| **Olvido de fichaje de salida** | Terminó a las 22:00 y se fue sin fichar; el turno aparece abierto a la mañana siguiente |
| **Fallo técnico del quiosco** | La tablet de la entrada de personal estuvo sin corriente toda la mañana y ese turno se anota a mano |
| **Tarjeta no disponible** | Se dejó la tarjeta en la taquilla y no pudo fichar la entrada |
| **Tarjeta todavía no entregada** | Primer día de trabajo: la tarjeta estaba impresa pero se le entregó al final del turno |
| **Escaneo duplicado** | Pasó la tarjeta dos veces seguidas y salieron dos tramos donde solo hubo uno; se anula el sobrante |
| **Ajuste acordado con RRHH** | Rectificación pactada con la persona tras revisar el cuadrante: no es un error del sistema |
| **Alta retroactiva** | Jornadas de la semana anterior a la puesta en marcha del sistema, que se cargan a mano |
| **Otro motivo** | Ninguno de los anteriores. **Obliga a escribir al menos 20 caracteres**: «error» y «ajuste» no explican nada ante una inspección |

### 5.3 Lo que pasa por debajo, y que hay que saber explicar

**El valor anterior se conserva siempre.** La corrección no reescribe el tramo:
crea una versión nueva y deja la anterior visible, con quién la hizo, cuándo y
por qué. En el «Historial de correcciones» de la jornada se ve el **antes** y el
**después**, uno al lado del otro.

![Historial de correcciones de una jornada](img/es/rrhh-09-historial-correcciones.png)

Ante una inspección, la frase es esta: *«el registro conserva todas las
versiones; esta hora se corrigió el día tal, por esta persona, por este
motivo, y aquí está lo que decía antes»*. Un registro sin ese historial es un
registro que se puede haber cambiado la víspera de la visita, y así lo lee quien
lo revisa.

Dos avisos que verás y qué significan:

- **«Este tramo ya no es la versión vigente»** — otra persona lo corrigió o lo
  anuló mientras tenías la pantalla abierta. Vuelve a cargar la jornada y mira
  cómo está ahora.
- **«Esa hora de entrada llevaría la jornada a otro día»** — mover horas de un
  día a otro son **dos acciones**: anular el tramo en el día en que está y darlo
  de alta en el día que corresponde, cada una con su motivo. El sistema no lo
  hace en un solo paso porque cambiar de día una jornada cambia qué se paga en
  qué mes.

**El empleado no corrige su propio registro**, y por eso el portal es solo de
consulta. Un registro que la persona interesada puede editar no prueba nada. Lo
que sí puede hacer es avisar, y RRHH corrige con su firma.

---

## 6. Informes, exportaciones y la entrega a la Inspección

Son dos cosas distintas y se confunden con facilidad:

| | **Informe de horas por periodo** | **Exportación para la Inspección** |
| --- | --- | --- |
| Para qué | Gestión: cuánto se ha trabajado, por quién, con qué desviación | Cumplir un requerimiento del art. 34.9 del Estatuto de los Trabajadores |
| Dónde | Informes | Inspección |
| Qué lleva | Totales agregados por persona, departamento o centro | **Todos los tramos, uno a uno, y todas las correcciones con su autor y su motivo** |
| Formato | CSV, Excel o PDF | CSV normalizado, con sus criterios y su base legal declarados dentro |

### 6.1 El informe de horas por periodo

![Informe de horas por periodo](img/es/rrhh-12-informe-periodo.png)

Se elige el periodo, la granularidad (día, semana, mes o todo el periodo) y la
agrupación (empleado, departamento o centro). Debajo de la tabla, el propio
informe declara **con qué criterios se ha calculado**: es lo que permite
defender un número seis meses después.

Dos avisos que conviene leer:

- **«Contar los días con turno abierto»**: si lo activas, entran días cuyo total
  todavía va a cambiar. Para una nómina, ciérralos antes.
- **«Días-persona sin contrato registrado»**: esas filas tienen las horas
  trabajadas bien y la desviación incompleta (§2.3).

### 6.2 La exportación para la Inspección

![Pantalla de exportación para la Inspección](img/es/rrhh-13-exportacion-legal.png)

Se eligen las fechas y, si el requerimiento nombra a una persona, esa persona.
En blanco, sale la plantilla completa. Qué contiene el fichero:

- Una fila por cada **tramo**, con entrada, salida, duración y total de la
  jornada. Un turno de noche es un único tramo, en la jornada en la que empezó.
- Una fila por cada **corrección**, con su autor, su momento y su motivo.
- **Los tramos anulados se incluyen**, marcados como tales, y no suman horas.
  Nada se oculta: ocultarlos sería exactamente lo que la Inspección busca.
- Las horas van en la zona horaria del centro **y además** en UTC, que es como
  están almacenadas.
- Las duraciones se escriben HH:MM, nunca en decimal.

**Cada generación queda registrada** con quién exportó, qué periodo y qué
alcance.

> **El procedimiento completo, con los plazos y qué hacer con el fichero
> después de entregarlo, está en
> [`../runbooks/requerimiento-inspeccion.md`](../runbooks/requerimiento-inspeccion.md).**
> Léelo **antes** de que llegue el requerimiento, no cuando llegue: son cinco
> minutos que ahorran la hora.

---

## 7. El perfil de cumplimiento

**Perfil de cumplimiento** son los umbrales legales con los que se revisa el
registro: descanso mínimo entre jornadas, jornada diaria y semanal ordinaria,
tramo máximo sin pausa, día de inicio de semana, festivos y años de
conservación. No lo confundas con **Cumplimiento** (§4 bis), que es la pantalla
que *aplica* estos umbrales al registro: aquí se deciden, allí se ven las
consecuencias.

![Pantalla del perfil de cumplimiento](img/es/rrhh-14-perfil-cumplimiento.png)

**Cambiar un umbral cambia qué se considera incidencia.** Bajar el descanso
mínimo de 12 h a 10 h no cambia ninguna hora del registro: cambia **qué
jornadas se marcan para revisión**. Es un cambio con efecto legal, y por eso:

- Rige **desde el momento en que se guarda**. No se recalcula el histórico ni se
  cierra ni se reabre ninguna incidencia ya registrada.
- La revisión diaria vuelve a mirar los últimos días, así que **endurecer** un
  umbral puede abrir incidencias de jornadas recientes ya pasadas.
- Queda anotado en el registro de auditoría con el valor anterior, el nuevo,
  quién lo cambió y cuándo. Sin eso no se puede explicar por qué una jornada de
  hace tres meses no generó ninguna alerta.

**Dónde se nota cada umbral.** El descanso mínimo entre jornadas y la jornada
diaria máxima mueven las dos cosas: la bandeja de incidencias (§4) y la vista de
cumplimiento (§4 bis). La **jornada semanal ordinaria** y el **día de inicio de
semana** los aplica solo la vista de cumplimiento, que avisa pero no abre
incidencia. El **calendario de festivos** se guarda y se audita desde hoy, pero
**todavía no lo aplica ninguna regla**: lo estrenará la gestión de ausencias de
una versión posterior, y la pantalla lo indica al lado del campo.

**`break_required_after_hours` —el tramo máximo sin pausa— depende además de un
ajuste que no está en esta pantalla.** Solo se aplica si el hotel tiene activado
el **fichaje de pausa** (Panel → «Ajustes operativos» → «Fichaje de pausa»; lo
explica [`configuracion.md`](configuracion.md) §2.1). Sin él, el umbral se
guarda y se audita igual, pero **no abre ninguna incidencia** y la vista de
cumplimiento lo enseña marcado «No se evalúa». Es deliberado: sin pausas
fichadas nadie puede distinguir «no descansó» de «descansó y no lo fichó», y una
bandeja con un aviso por cada turno largo deja de leerse. En cuanto se activa, la
regla empieza a contar sola con el umbral que tengas guardado aquí, así que
revisa el número **antes** de pedir que lo enciendan.

El producto se entrega con el perfil español de hostelería. **Ajustarlo al
convenio que os aplique es responsabilidad del hotel**, no del fabricante: lo
explica [`obligaciones-legales.md`](obligaciones-legales.md) §7. El detalle de
cada umbral, valor a valor, está en [`configuracion.md`](configuracion.md) §2.4.

> **Los años de conservación son el único umbral que puede destruir datos.**
> Bajarlos amplía lo que la purga considera vencido, sobre datos que hay
> obligación legal de conservar cuatro años. Ninguna purga se ejecuta sola —se
> propone primero y hay que confirmarla—, pero no toques ese número sin leer
> [`obligaciones-legales.md`](obligaciones-legales.md) §4.

---

## 8. Qué hacer si…

### …una persona dice que su registro está mal

1. Abre **su registro horario** y mira el día concreto. Comprueba primero el
   **origen** de los tramos: un tramo «Registrado a mano» ya tiene una
   corrección detrás que explica de dónde salió.
2. Si falta o sobra algo, corrígelo con el motivo que corresponda (§5) y
   explícale que su versión anterior se conserva.
3. Dile que puede comprobarlo él mismo en el portal: verá el cambio y el
   historial de la corrección, con el motivo.

**Nunca corrijas «por si acaso».** Si no está claro qué pasó, pregunta al
responsable del turno antes de firmar una hora.

### …alguien olvidó fichar la salida

Aparecerá como incidencia **«Turno abierto sin cerrar»**. El sistema no lo cierra
solo (§4.2).

1. Averigua la hora real de salida: pregunta al responsable del turno; no la
   deduzcas del cuadrante.
2. En el registro horario de esa persona, **«Corregir las horas»** y escribe la
   salida, con el motivo **«Olvido de fichaje de salida»**.
3. Cierra la incidencia como **«Se ha corregido»**, con la nota de quién
   confirmó la hora.

Si el olvido es de hace semanas, el procedimiento es el mismo: los turnos
abiertos se revisan siempre, sin límite de antigüedad, y no desaparecen solos.

### …se pierde o se rompe una tarjeta

Es el mismo procedimiento en los dos casos, y también cuando el PDF de la
impresión se pierde antes de imprimirla:
**[`../runbooks/tarjeta-perdida-o-rota.md`](../runbooks/tarjeta-perdida-o-rota.md)**.

En resumen: **revocar con motivo → emitir otra → imprimir → entregar con su
hoja**. Y avisa a la persona de que **mientras tanto puede fichar con su código
y su PIN** en la propia tablet: nadie se queda sin poder fichar por haber
perdido una tarjeta.

### …una persona pide su registro horario

La vía ordinaria es **el portal**: entra con su código y su PIN y se descarga su
historial cuando quiera, sin pedírselo a nadie. Es lo que exige la ley y lo que
evita convertir cada consulta en una gestión.
Entrégale [`guia-portal-empleado.md`](guia-portal-empleado.md).

Si la petición llega **por escrito como ejercicio de un derecho** —acceso,
rectificación, portabilidad, supresión—, tiene plazos y forma:
[`../runbooks/solicitud-derechos-rgpd.md`](../runbooks/solicitud-derechos-rgpd.md).
Ojo con una en concreto: **la supresión no procede** mientras dure el deber de
conservación de cuatro años, y el runbook explica cómo se responde eso sin
negarle el derecho.

### …llega un requerimiento de la Inspección de Trabajo

No improvises: hay un procedimiento escrito y probado, pensado para resolverse
en menos de una hora:
**[`../runbooks/requerimiento-inspeccion.md`](../runbooks/requerimiento-inspeccion.md)**.

Lo esencial: la exportación se genera desde **Inspección** (§6.2), incluye
tramos, correcciones y anulados, y declara sus propios criterios. Antes de
entregar el fichero, el runbook dice qué comprobar y qué hacer con él después.

### …una persona causa baja

Desde su ficha, apartado **«Baja»** (no se hace cambiando el campo de situación:
tiene su propio apartado porque lleva fecha de cese y consecuencias). Se elige
la fecha de cese y el motivo, que queda en el registro de auditoría —sin datos
de salud y sin juicios de valor.

Qué ocurre al confirmarla:

- **Su tarjeta queda revocada** y deja de servir en el quiosco.
- **Desde la fecha de cese no puede fichar.**
- **No se borra nada.** Su ficha, sus tramos y sus jornadas se conservan cuatro
  años, porque una inspección puede pedir el registro de alguien que ya no
  trabaja aquí, y seguirán apareciendo en los informes del periodo en que
  trabajó.

Recoge la tarjeta física si puedes; si no aparece, revócala igualmente —ya lo
está por la baja— y anótalo.

### …alguien no puede fichar

Antes de mover nada, mira su fila en **Credenciales**:

| Estado de la tarjeta | Qué significa | Qué hacer |
| --- | --- | --- |
| Sin credencial | No se le ha emitido | Emitir, imprimir y entregar (§2.4) |
| Pendiente de imprimir | Existe el derecho, no la tarjeta | Imprimirla |
| Pendiente de entregar | Está impresa y no la tiene en la mano | Entregársela y registrar la entrega |
| Entregada | Debería poder fichar | Ver abajo |
| Revocada | Se revocó, por pérdida o por baja | Emitir otra (§2.4) o comprobar si la baja es correcta |

Si consta **Entregada** y aun así no ficha, **puede fichar con su código y su
PIN en la tablet** mientras se averigua qué pasa: eso no espera. Si le ocurre a
varias personas a la vez, o el tablero avisa de que hay tarjetas firmadas con
una clave que el servidor ya no reconoce, **es cosa de IT**:
[`operacion.md`](operacion.md).

### …la bandeja se llena de incidencias iguales

Suele ser una de dos cosas, y ninguna se arregla resolviéndolas una a una:

- **Un umbral que no encaja con vuestro convenio.** Si toda la plantilla genera
  «Jornada demasiado larga», el umbral está mal ajustado, no la plantilla (§7).
- **Una tablet con la hora desviada** genera «Desfase de reloj» en cadena. Los
  fichajes están registrados; lo que hay que arreglar es la tablet, y eso es de
  IT ([`operacion.md`](operacion.md)).

### …hay un aviso de licencia en el panel

**Sigue fichando todo y sigues teniendo acceso a todo el registro.** Una licencia
caducada nunca detiene el fichaje, ni la consulta, ni la corrección, ni la
exportación para la Inspección: lo que se degrada son funcionalidades accesorias
—por ejemplo, la marca propia vuelve a la del producto—. Dejarte sin registro
horario por una cuestión comercial te dejaría incumpliendo la ley, y eso no lo
hace este producto. Avisa a quien lleve la relación con el proveedor; el detalle
está en [`configuracion.md`](configuracion.md) §3 bis.3.
