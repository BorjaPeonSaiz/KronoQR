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

Los diez apartados de esta guía, por si buscas uno concreto:

1. [El vocabulario, primero](#1-el-vocabulario-primero)
2. [Alta de una persona, de principio a fin](#2-alta-de-una-persona-de-principio-a-fin)
3. [Presencia en vivo y registro horario](#3-presencia-en-vivo-y-registro-horario)
4. [La bandeja de incidencias](#4-la-bandeja-de-incidencias)
    - [4 bis. La vista de cumplimiento](#4-bis-la-vista-de-cumplimiento)
5. [Correcciones: cambiar una hora sin romper el registro](#5-correcciones-cambiar-una-hora-sin-romper-el-registro)
    - [5 bis. Ausencias: vacaciones, bajas y permisos](#5-bis-ausencias-vacaciones-bajas-y-permisos)
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
| **Patrón anómalo de uso de la credencial** | Alta | Dos tarjetas de dos personas distintas se pasan **en la misma tablet con segundos de diferencia varios días** (3 de serie), o la **misma tarjeta** se pasa en **dos tablets distintas** antes de lo que se tarda en ir de una a otra | Casi siempre, dos compañeros que entran juntos, o dos tablets demasiado cerca. **Es un indicio para que lo mire una persona, no una conclusión.** No cambia ningún fichaje y no lo ve nadie fuera de la bandeja (§4.5) |

> **El filtro «Tipo» enseña los nueve, y cuántos se abren solos depende de un
> ajuste.** Siete lo hacen siempre —descanso insuficiente, turno abierto,
> jornada demasiado larga, jornada demasiado corta, desfase de reloj, fichaje
> fuera de orden y patrón anómalo de uso de la credencial—, y **«Sin pausa
> registrada» se suma a ellos en cuanto el hotel activa el fichaje de pausa**
> (Panel → «Ajustes operativos» → «Fichaje de pausa»; lo explica
> [`configuracion.md`](configuracion.md) §2.1). «Salida sin fichar» está en la
> lista porque el sistema tiene que poder registrarla sin cambiar nada cuando
> llegue su momento. No es un fallo de la instalación.
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

### 4.5 «Patrón anómalo de uso de la credencial»: qué verás y qué hacer

Es la única incidencia de la bandeja que **habla de dos personas a la vez**, y
por eso merece un apartado. La abre una revisión propia, cada madrugada, sobre
los fichajes hechos en las tablets durante los últimos 30 días. Hay dos
variantes, y la ventana **«Cerrar incidencia»** te dice cuál es y te enseña lo
que se observó:

| Variante | Qué se observó | Qué te enseña la pantalla |
| --- | --- | --- |
| **Coincidencia en la misma tablet** | Dos personas distintas pasaron su tarjeta o su PIN en **la misma tablet con menos de 10 segundos de diferencia** (ajustable), y eso ocurrió **varios días** (3 de serie; se cuenta como mucho un día aunque coincidieran dos veces la misma mañana) | La tablet; **la otra persona**, con un enlace a su bandeja, y «y N personas más» si hay varias; **cuántos días hubo coincidencia frente a los que hacen falta**; la ventana de segundos aplicada; y cuatro datos en lugar de una lista: la **primera** y la **última** coincidencia, el **hueco del último día** y el **hueco más pequeño** de toda la serie. Ese último es el que importa: 9 segundos todos los días es una cola; 1 segundo un día es una pregunta |
| **Secuencia imposible entre dos tablets** | La **misma tarjeta** (o PIN) se pasó en **dos tablets distintas** con menos tiempo del que se tarda en ir de una a otra (120 segundos de serie, ajustable). Basta una vez. Cuenta también la segunda pasada que la tablet no aceptó por venir demasiado seguida de la primera. **No se mira** ningún fichaje cuya hora esté en duda porque el reloj de esa tablet iba desviado | Las dos tablets, el momento de cada fichaje **con segundos**, el hueco entre ambos y el tránsito mínimo que se aplicó |

**En la coincidencia hay una incidencia por persona**, cada una en la bandeja
del responsable de **su** departamento, y cada una nombra a la persona con la
que más coincide y dice si hay más. Si son de departamentos distintos, cada
responsable ve la suya: hablad entre vosotros antes de hablar con nadie más,
y reconstruid el grupo entre todos, no desde una sola incidencia.

**Y no vuelve cada noche.** Mientras tengas una de estas abierta sobre una
persona, no se abre otra sobre ella. Cuando la cierres, harán falta **3 días
nuevos** con coincidencia (los de después del cierre) para que vuelva a
aparecer: si el patrón era «vienen juntos en coche» y sigue, la volverás a ver
dentro de unos días con datos nuevos, y la cerrarás igual.

**Lo que esta incidencia NO dice.** No dice quién prestó nada a quién, ni que
nadie haya prestado nada. Dos compañeros que llegan en el mismo coche y entran
juntos producen **exactamente** el mismo indicio todos los días, y dos tablets
en la misma puerta producen secuencias «imposibles» que son perfectamente
posibles. El sistema no puede distinguirlo desde la tablet; tú sí, porque
tienes el cuadrante y al jefe de turno.

**Lo que el sistema no hace, y no va a hacer:** no anula ni marca ningún
fichaje, no cambia ninguna hora, no sanciona, no bloquea ninguna tarjeta y no
avisa a nadie fuera de la bandeja. Pone el indicio delante de una persona y se
queda ahí. Es, a propósito, lo que hay en lugar de una máquina que decida
quién pasó la tarjeta.

**Qué hacer, en este orden:**

1. **Cuadrante.** ¿Las dos personas tenían el mismo turno esos días? Si sí, la
   coincidencia es lo esperable.
2. **Jefe de turno.** ¿La persona estaba en su puesto esos días? Si estaba, la
   tarjeta la pasó ella.
3. **En la secuencia imposible, mira primero las tablets.** Si de verdad se va
   de una a otra en menos de lo que marca el ajuste, el problema es el ajuste:
   pídele a IT que lo baje en Ajustes operativos
   ([`configuracion.md`](configuracion.md) §2.1) y cierra la incidencia
   diciendo eso.
4. **Solo si después de eso queda algo que aclarar**, se le pregunta a la
   persona, en abierto y describiendo lo observado («estos días tu tarjeta y la
   de X se pasaron con segundos de diferencia; ¿entráis juntos?»). Quien
   pregunta es la empresa según su procedimiento —tú, o RRHH—, **nunca «el
   sistema»**. Y nada se decide a partir de la incidencia sola.
5. **Cierra la incidencia** (§4.3), casi siempre como «Revisada: no había nada
   que corregir». **La nota describe lo que contrastaste, no lo que
   concluiste sobre nadie**: «mismo turno de mañana según cuadrante, entran
   juntas desde el aparcamiento, confirmado con jefa de sala» sirve dentro de
   dos años; un calificativo no. Esa nota la puede leer la propia persona si
   pide acceso a sus datos.

El procedimiento completo, con lo que se puede preguntar y lo que no, está en
[`../runbooks/patron-anomalo-credencial.md`](../runbooks/patron-anomalo-credencial.md).

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

## 5 bis. Ausencias: vacaciones, bajas y permisos

**Ausencias** es la pantalla donde queda anotado que una persona no estuvo y por
qué. Está en el menú justo detrás de «Empleados», porque una ausencia es de la
plantilla y no de un día suelto del registro.

Sirve para una cosa, y conviene decirla antes que ninguna otra: **que los
informes no cuenten como falta injustificada un día en el que la persona no
tenía que estar**. Sin ausencias registradas, el informe de horas por periodo
solo sabe que ese día no hubo ningún fichaje, y no puede distinguir unas
vacaciones de una falta.

### 5 bis.1 Qué es una ausencia y qué no

Una ausencia es **un hecho que se anota**, no una solicitud que se tramita.

**No hay flujo de aprobación.** No existe «pendiente de aprobar», ni «aprobada»,
ni un botón de aprobar, y no es un descuido: la decisión se toma fuera del
sistema —al cerrar el cuadrante, hablando con la persona, con el parte médico
delante— y aquí solo queda escrita. Quien registra una ausencia está diciendo
«esto ya está decidido», no «esto está pedido».

Hay cuatro tipos, y no se pueden añadir más:

| Tipo | Cuándo se usa |
| --- | --- |
| **Vacaciones** | Vacaciones ya concedidas, del periodo que sea |
| **Baja médica** | Incapacidad temporal, accidente, cualquier baja con parte |
| **Permiso** | Permisos retribuidos y no retribuidos: mudanza, examen, asuntos propios, cuidado de un familiar |
| **Otro** | Ninguno de los anteriores. **Obliga a escribir una nota** que diga de qué se trata |

El resto de lo que hay que saber cabe en una lista:

- **Solo días completos.** Se indica el primer día y el último, y **los dos
  cuentan**: una ausencia del 3 al 5 son tres días. No hay medias jornadas ni
  ausencias por horas. Si alguien falta media tarde, eso no es una ausencia: es
  una jornada más corta, y se ve en su registro.
- **Se registra hacia atrás y hacia delante.** Una baja médica casi siempre se
  conoce después de haber empezado, y unas vacaciones se anotan meses antes. Las
  dos cosas valen, y ninguna espera a que llegue el día.
- **Registrar una ausencia no impide fichar.** Si esa persona aparece y pasa la
  tarjeta, el fichaje se registra con normalidad: la ausencia no lo bloquea ni
  abre por sí sola ninguna incidencia. Lo que hay que aclarar entonces es el
  hecho, no el sistema — y si la ausencia estaba mal anotada, se corrige
  (§5 bis.2).
- **No lleva el saldo de vacaciones ni avisa a nadie.** El producto no calcula
  cuántos días le quedan a cada persona ni manda ningún aviso al responsable
  cuando se registra una ausencia de su gente.
- **El empleado no las ve en su portal.** El portal es su registro horario y
  nada más ([`guia-portal-empleado.md`](guia-portal-empleado.md)). Las
  ausencias las consultan RRHH y su responsable.

### 5 bis.2 Registrar, corregir y anular

Tres acciones, y ninguna de ellas borra nada:

| Acción | Cuándo |
| --- | --- |
| **«Registrar»** | Anotar una ausencia nueva: persona, tipo, primer día, último día y, si hace falta, una nota |
| **«Corregir»** | Las fechas o el tipo no son los que fueron: la baja duró dos días más, o se anotó como permiso lo que eran vacaciones |
| **«Anular»** | La ausencia no debería existir: la persona sí trabajó esos días, o se registró sobre la persona equivocada |

**Al registrar**, se busca a la persona por su nombre o por su código y se
indican los días. Si esas fechas se pisan con otra ausencia ya vigente de la
misma persona, **no se guarda y la pantalla lo dice**: dos ausencias solapadas
harían que un mismo día contara dos veces en el informe.

**Al corregir**, la pantalla enseña **de qué valor a cuál** antes de que
confirmes —el tipo de antes y el de ahora, las fechas de antes y las de ahora— y
**pide un motivo**. Eso no es burocracia: es lo que se lee seis meses después
para entender por qué el informe de marzo dice hoy algo distinto de lo que decía
en marzo.

**Nada se borra.** Corregir no reescribe la ausencia: crea una versión nueva y
conserva la anterior, con quién la hizo, cuándo y por qué. Al abrir una ausencia
se ve su historial completo, de la primera versión a la vigente, igual que el
historial de correcciones de una jornada (§5.3).

**Anular también pide motivo, y tampoco borra.** La ausencia se queda donde
estaba, marcada como anulada, con quién la anuló, cuándo y por qué. Deja de
contar en los informes desde ese momento, pero sigue consultable — que es
justamente lo que permite explicar por qué un informe de la semana pasada traía
un día de ausencia que el de hoy ya no trae.

> **Anular no es lo mismo que corregir.** Si lo que quieres es cambiar unas
> fechas o el tipo, **corrige**: la ausencia existió y sigue existiendo, solo
> que dice otra cosa. **Anula** solo lo que no debería haberse registrado nunca.

### 5 bis.3 Carga por fichero

Para una tanda grande —las vacaciones de todo el verano, el histórico del año
que llevabais en una hoja de cálculo— hay carga desde un fichero CSV o Excel,
con **los mismos dos pasos que la carga de plantilla**: primero se comprueba,
después se aplica.

1. **Comprobar.** Subes el fichero y el sistema lo lee entero **sin guardar
   nada**: te devuelve, línea a línea, cuáles entrarían y cuáles no y por qué.
2. **Aplicar.** Solo si lo confirmas. Se registran las líneas válidas y se
   rechazan las demás, con el mismo detalle.

La primera fila son los **nombres de las columnas**. El orden da igual y sobrar
columnas no molesta:

| Campo | Obligatorio | Nombres que se reconocen |
| --- | --- | --- |
| Código de empleado | **Sí** | `employee_code`, `codigo` |
| Tipo de ausencia | **Sí** | `type`, `tipo` |
| Primer día | **Sí** | `starts_on`, `desde` |
| Último día | **Sí** | `ends_on`, `hasta` |
| Nota | No, salvo en «Otro» | `note`, `nota` |

- **El código de empleado** es el que el sistema generó al dar de alta a la
  persona y que va impreso en su tarjeta. Lo tienes en la lista de empleados.
- **El tipo se escribe por su nombre**: `vacaciones`, `baja`, `permiso` u
  `otro`, o sus equivalentes en inglés `vacation`, `sick_leave`, `leave` y
  `other`.
- **Las fechas** se aceptan como `2026-07-01` o como `01/07/2026`. Nunca
  mes/día/año, por lo mismo que en la carga de plantilla.
- **El separador y la codificación se detectan solos**, igual que allí: lo
  explica [`configuracion.md`](configuracion.md) §3 ter.3.

**Volver a subir el mismo fichero es seguro.** Una línea idéntica a una ausencia
ya registrada —misma persona, mismo tipo, mismas fechas— **no da error y no la
duplica**: sale marcada como «sin cambios» y se pasa a la siguiente. Es lo que
permite arreglar tres líneas de un fichero de doscientas y volver a subirlo
entero sin pensárselo.

Una línea se rechaza cuando el código de empleado no existe, cuando las fechas
están al revés o no se entienden, cuando el tipo no es uno de los cuatro, cuando
la ausencia se pisa con otra ya registrada de esa persona **o con otra línea del
mismo fichero**, o cuando el tipo es «Otro» y no trae nota.

> **El fichero no se guarda en el servidor.** Se lee, se aplica y se descarta.
> Lo que queda es cada ausencia registrada, con su anotación (§5 bis.6).

### 5 bis.4 Qué cambia en el informe de horas por periodo

El informe de horas por periodo (§6.1) trae **tres columnas** que dependen de lo
que se haya registrado aquí:

| Columna | Qué cuenta |
| --- | --- |
| **Días de ausencia** | Días en que la persona estaba de alta y había una ausencia registrada, hubiera fichajes o no |
| **Festivos** | Días de alta que figuran en el calendario de festivos del perfil de cumplimiento (§7) y **no** están ya cubiertos por una ausencia |
| **Absentismo no justificado** | Días de alta **sin ningún fichaje, sin ausencia y sin festivo** |

Cada día se cuenta **en una sola columna**: si un festivo cae dentro de unas
vacaciones, ese día es día de ausencia y no festivo. Y solo cuentan los días en
que la persona estaba de alta, igual que en el resto del informe: lo anterior a
su fecha de alta o posterior a su baja no aparece en ninguna de las tres.

> **⚠️ El límite que hay que conocer antes de enseñarle este informe a nadie.**
> **El producto no conoce vuestro cuadrante.** Sabe cuándo se ha fichado, no qué
> días libra cada persona. Así que **los días de descanso semanal salen contados
> como absentismo no justificado**, exactamente igual que una falta: nadie fichó
> y no hay ausencia ni festivo que lo explique. No es un fallo: es lo único que
> el sistema puede saber con lo que tiene. Esa columna es **un punto de
> partida** —los días que nadie ha explicado—, y hay que contrastarla con el
> calendario de turnos antes de sacar ninguna conclusión sobre una persona. Si
> alguien la lee como «días de falta», el número estará mal siempre.

Dos cosas más:

- **El informe no desglosa por tipo, y es a propósito.** No hay columna de «días
  de baja médica» por departamento: sería un dato de salud agregado que nadie ha
  pedido y que acabaría en una hoja de cálculo compartida. El detalle por tipo
  se ve en la pantalla de **Ausencias**, con el alcance de cada quien
  (§5 bis.5).
- **Las tres columnas salen también en CSV, en Excel y en PDF**, y los criterios
  que el informe declara debajo de la tabla dicen cuántos festivos del perfil
  han caído en el periodo y repiten el aviso del cuadrante.

### 5 bis.5 Quién ve qué

| Quién | Qué puede hacer |
| --- | --- |
| **RRHH** y **administrador** | Ven todas las ausencias, con su nota. Registran, corrigen y anulan |
| **Responsable de departamento** | Ve las de su gente, con su tipo y sus fechas. **No ve la nota**, y no puede registrar, corregir ni anular |
| **Auditor** y **empleado** | No entran en esta pantalla |

El responsable **sí** ve el tipo, y es deliberado: quien organiza un turno tiene
que saber quién falta y por qué categoría, o no puede cubrirlo. La **nota** no
le llega —ni vacía ni tachada: el campo no existe para él, para que nadie
confunda «no me la enseñan» con «no hay nota».

> **La baja médica es un dato de salud. Escribe lo justo.**
> **No pongas el diagnóstico ni el motivo médico en la nota.** Con el tipo
> **Baja médica** basta: es lo que el informe necesita y lo único que hace falta
> para cubrir el turno. Lo mismo vale para el motivo de una corrección o de una
> anulación, que además **queda en el registro legal y puede leerlo una
> inspección**, igual que los motivos de corrección del §5.2: sin datos de salud
> y sin juicios de valor sobre la persona.
>
> El parte médico, el justificante y todo lo que os obligue a conservar la
> normativa se guardan donde guardéis el expediente de la persona, no aquí. Qué
> podéis guardar y durante cuánto lo explica
> [`obligaciones-legales.md`](obligaciones-legales.md).

### 5 bis.6 Qué queda anotado

**Cada alta, cada corrección y cada anulación deja asiento en el registro de
auditoría**, con quién lo hizo, cuándo, qué cambió y por qué. En una corrección
el asiento lleva **los valores de antes y los de después**, de modo que la
historia completa de una ausencia se puede reconstruir sin abrir la pantalla.

Una carga por fichero deja **un asiento por cada ausencia registrada**, no uno
de resumen: cien ausencias importadas son cien anotaciones.

**La nota nunca entra en el asiento.** Puede llevar información sobre la salud
de una persona, y la anotación no la necesita para explicar qué pasó: le basta
el tipo, las fechas, quién y cuándo. Por el mismo motivo, ni el tipo ni la nota
aparecen en los registros técnicos del sistema ni en el paquete de diagnóstico
que se le manda al fabricante.

Y lo de siempre: **nada se borra**. Una ausencia corregida conserva todas sus
versiones, y una ausencia anulada sigue estando, marcada como tal. Es la misma
regla que hace que el registro horario valga ante una inspección (§1).

---

## 6. Informes, exportaciones y la entrega a la Inspección

Son tres cosas distintas y se confunden con facilidad:

| | **Informe de horas por periodo** | **Exportación para la Inspección** | **Salida a nómina** |
| --- | --- | --- | --- |
| Para qué | Gestión: cuánto se ha trabajado, por quién, con qué desviación | Cumplir un requerimiento del art. 34.9 del Estatuto de los Trabajadores | Llevarle las horas del mes al programa de nómina del hotel |
| Dónde | Informes | Inspección | Informes → pestaña **Nómina** |
| Qué lleva | Totales agregados por persona, departamento o centro | **Todos los tramos, uno a uno, y todas las correcciones con su autor y su motivo** | Una fila por persona y periodo: horas trabajadas, contratadas, exceso y ausencias. **Ningún importe** |
| Formato | CSV, Excel o PDF | CSV normalizado, con sus criterios y su base legal declarados dentro | CSV o Excel, con las columnas, el separador y el formato de horas que pida tu programa de nómina |

El informe de horas y la salida a nómina se descargan al instante casi siempre;
cuando el periodo o la plantilla son grandes, **se generan en segundo plano**
(§6.3). La exportación para la Inspección no cambia: se genera siempre en el
acto.

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

El informe trae además **tres columnas que salen de las ausencias**: días de
ausencia, festivos y absentismo no justificado. Qué cuenta exactamente cada una
—y, sobre todo, **lo que el producto no puede saber** de los días de descanso—
está en el §5 bis.4. Léelo antes de enseñarle esa última columna a nadie.

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

### 6.3 Informes grandes: en segundo plano

**Cuándo pasa.** Al pedir un informe de horas por periodo o una salida a nómina,
el panel puede avisarte de que **ese periodo o esa plantilla no caben en el
acto**. No es un error ni un fallo de la instalación: un informe que se calcula
mientras esperas tiene un tiempo máximo, y por encima de él el sistema prefiere
decírtelo a dejarte la pantalla colgada. Pasa sobre todo con periodos de varios
meses y con plantillas grandes.

**Qué hacer.** El propio aviso trae el botón **«Generar en segundo plano»**, con
los mismos parámetros que ya habías elegido: no hay que volver a rellenar nada.
Al pulsarlo, la petición entra en el bloque **«Exportaciones en segundo plano»**
de esa misma pantalla.

**Cómo se sigue.** Esa lista se refresca sola mientras haya algo en curso y
enseña el estado de cada petición: «En cola», «Generando», **«Lista para
descargar»**, «Fallida» o «Caducada». Puedes cerrar la pantalla, salir del panel
y volver más tarde: el trabajo sigue en el servidor. Solo se procesa **una
petición tuya a la vez**; si pides otra sin que haya acabado la anterior, el
panel te enseña la que ya está en marcha en lugar de empezar una segunda.

**El aviso por correo, si tu instalación envía correo.** Cuando el fichero está
listo te llega un aviso a la dirección de tu cuenta de gestión. Ese mensaje **no
lleva el fichero ni el enlace de descarga**: lleva el enlace a la pantalla de
informes, y la descarga se pide desde allí. Si tu instalación no tiene salida de
correo, no pasa nada: la pantalla es la fuente, el correo solo te ahorra estar
mirando. Que haya o no correo lo decide IT al instalar.

**Descargar.** Cuando la fila diga «Lista para descargar», el botón
**«Descargar»** trae el fichero. Dos cosas que conviene saber antes de pulsarlo:

- **El enlace caduca a los 15 minutos y vale una sola vez.** En cuanto se usa,
  deja de servir: si vuelves a abrirlo, la respuesta dice que ese enlace ya se
  gastó y que pidas otro. No sirve de nada guardarlo, ni reenviarlo por correo,
  ni pegarlo en un chat: cuando la otra persona lo abra, ya no valdrá.
- **Si la descarga se corta** —se cae la wifi, cierras el portátil— no se pierde
  nada: vuelve a la pantalla y pulsa «Descargar» otra vez. Se emite un enlace
  nuevo sobre el mismo fichero, que no se ha vuelto a generar.

**Cuánto dura el fichero.** Se conserva **7 días** y después desaparece solo. La
fila sigue en la lista diciendo que caducó, con lo que se pidió y cuándo, para
que quede constancia; lo que ya no está es el fichero. Si lo necesitas más tarde,
vuelve a pedirlo: sale el mismo resultado, salvo que entre medias se haya
corregido algo del registro.

**Quién lo ve.** **Solo quien lo pidió**, incluso si quien mira es
administrador. No es una carpeta compartida: es tu petición y tu fichero. Si tu
compañera necesita ese mismo informe, lo pide ella, con su cuenta y con su
alcance —un responsable de departamento recibe siempre solo lo suyo—.

**Qué queda anotado.** Que lo pediste, qué periodo y qué alcance; que se generó,
con cuántas filas; y **cada descarga, una a una**, con quién y cuándo. Es lo que
permite responder «quién se llevó qué» si algún día hay que responderlo.

> Los plazos —los 15 minutos del enlace y los 7 días del fichero— los puede
> ajustar IT en el servidor. Están en
> [`configuracion.md`](configuracion.md) §6.25.

### 6.4 La salida a nómina

**Qué exporta.** Una fila por persona y periodo con las **horas trabajadas**, las
**horas contratadas**, el **exceso** sobre lo contratado y los **días de
ausencia**. Las horas son exactamente las mismas que enseña el informe de horas
por periodo (§6.1): no es un segundo cálculo que pueda dar otro número.

**Qué NO hace, y conviene decirlo en voz alta.** No calcula importes, ni pluses,
ni complementos, ni antigüedad, ni cotizaciones, ni nada que lleve un símbolo de
euro. Eso lo hace el programa de nómina del hotel; esto le entrega las horas de
partida. Si alguien espera de aquí una nómina, espera algo que este producto no
hace y nunca ha prometido hacer.

**Dónde está y quién puede.** Informes → pestaña **«Nómina»**. Se elige el
periodo y la granularidad, se ven las columnas configuradas antes de descargar
nada, y hay dos botones: **«Descargar»**, que la trae en el acto, y **«Generar en
segundo plano»** para los periodos grandes (§6.3). La pestaña la ven **RRHH y
administración**; un responsable de departamento no, aunque sí ve el informe de
horas de su gente.

**El formato lo configura IT una vez.** Qué columnas salen y en qué orden, cómo
se escriben las horas y las fechas, qué separador lleva el CSV y con qué
codificación se graba son ajustes de la instalación, no decisiones de cada mes:
se afinan el día que se conecta el programa de nómina y no se vuelven a tocar.
Están explicados uno a uno en [`configuracion.md`](configuracion.md) §2.5. Si tu
programa de nómina rechaza el fichero, eso es lo que hay que mirar.

**Los criterios no van dentro del fichero, y es a propósito.** El informe de
horas por periodo lleva sus criterios impresos debajo de la tabla; este no,
porque una línea de explicación en mitad de un CSV rompe la importación en el
programa de nómina. Los criterios se enseñan **en la pantalla, al lado del botón
de descarga**. Cópialos y guárdalos junto al fichero que envíes: son lo que
permite explicar un número seis meses después, y el fichero solo no lo explica.

**Qué contrastar antes de enviarla.** Cinco minutos aquí ahorran una corrección
de nómina después:

- **Días con turno abierto.** Una jornada sin salida fichada tiene un total que
  todavía va a cambiar. Míralos en la bandeja de incidencias (§4) y ciérralos
  antes de exportar.
- **Incidencias sin resolver** del periodo. Cada una es una hora que puede
  moverse cuando alguien la resuelva.
- **Días sin contrato registrado.** En esas filas las horas trabajadas son
  correctas, pero las contratadas y el exceso están incompletos (§2.3).
- **Ausencias al día.** Vacaciones, bajas y permisos del periodo tienen que estar
  registrados antes (§5 bis), o la columna de ausencias saldrá corta.

> **Es una funcionalidad accesoria.** Si la licencia caduca, la salida a nómina
> puede dejar de estar disponible hasta renovarla. El registro horario, el
> fichaje y la exportación para la Inspección **no se detienen nunca por eso**
> (§8, «hay un aviso de licencia en el panel»).

### 6.5 El resumen semanal por correo

**Qué es.** Un correo, **opcional**, que cada lunes por la mañana recibe cada
responsable de departamento con la semana anterior —de lunes a domingo— de su
equipo. Es un resumen para leer en dos minutos, no un informe: sirve para ver
pronto una desviación o una incidencia sin tener que abrir el panel cada lunes,
y para nada más.

**Quién lo recibe.** Los responsables de departamento con cuenta activa y
dirección de correo, y **cada uno solo lo suyo**: el de Cocina no ve a nadie de
Recepción, exactamente igual que en el panel. RRHH y administración no lo
reciben: tienen el panel entero, y un correo semanal con toda la plantilla
sería una copia periódica del registro fuera del sistema.

**Qué trae.**

- Una línea por persona con las **horas trabajadas**, las **contratadas** y la
  **desviación**, los **días con actividad**, las **ausencias** y los
  **festivos** de la semana. Son las mismas columnas del informe de horas por
  periodo (§6.1), calculadas igual y con las mismas salvedades (§5 bis.4): el
  correo no calcula nada nuevo.
- Los **totales** del departamento.
- El **número de incidencias abiertas** del departamento, sin detalle: el
  detalle ya llega en el aviso diario de incidencias y está en la bandeja (§4).
- **Dónde verlo entero**: «Panel → Informes, del <inicio> al <fin>». Es una
  indicación, no un enlace: el correo no lleva direcciones en las que pulsar.

Si el departamento tiene más de **50 personas**, el correo lleva las 50 primeras
y dice cuántas quedan: el resto está en el panel.

**Lo que no es.** No sustituye al panel ni al informe: los días con turno
abierto no cuentan, y una corrección o una incidencia resuelta después del
lunes cambia el informe, pero no el correo que ya salió. Ningún texto del correo
compara a las personas entre sí ni las califica: son los mismos números que la
pantalla, en la bandeja de entrada.

**Cómo se activa.** Desde **Ajustes operativos**, con cuenta de administración,
«Resumen semanal por correo» → «Activado». Se entrega apagado. Hace falta que la
instalación tenga salida de correo —lo decide IT al instalar, como con el aviso
de los informes en segundo plano (§6.3)— y que la licencia incluya esta
funcionalidad; si falta cualquiera de las dos, no pasa nada: no llega el correo
y todo lo demás sigue igual. Se activa para todos los responsables a la vez; en
esta versión no hay baja individual.

> **Ese correo lleva nombres y horas de personas, y sale del sistema.** Por eso
> cada envío queda registrado como un acceso a datos personales, igual que
> descargar un informe: a quién se envió, qué semana y qué personas incluía. Es
> una comunicación interna legítima por su finalidad, pero conviene tratarlo
> como lo que es: no lo reenvíes fuera del hotel ni lo imprimas para dejarlo en
> un tablón. Y una vez entregado **es una copia fuera del producto**: vive en
> tu buzón, y cuánto tiempo se guarda ahí lo decide el hotel con su asesoría,
> no la retención del sistema ([`obligaciones-legales.md`](obligaciones-legales.md)
> §4).

### 6.6 El cuadro de impacto: qué mide y qué no

**Qué es.** Una pantalla del panel —Informes → «Impacto y adopción»— que
responde a una sola pregunta: **¿está sirviendo el sistema?** No dice cuánto ha
trabajado nadie: dice si el registro se está haciendo completo, si la gente
ficha con la tarjeta o hay que arreglar a mano, cuánto tardan en resolverse las
incidencias y cuánta gente sigue sin tarjeta. La ven las cuentas de
administración y de RRHH; los responsables de departamento, no.

**El periodo, y con qué se compara.** Eliges un periodo cerrado —de serie, el
mes natural anterior completo— y cada indicador sale con su valor y con la
**variación frente al periodo anterior**, que es siempre **el mismo número de
días justo antes** del que has elegido: un mes de treinta días se compara con
los treinta días anteriores, una quincena con la quincena anterior. Si en ese
periodo anterior no hay nada con lo que comparar —porque el sistema aún no
estaba en marcha, o no hubo fichajes— **la variación queda en blanco, no a
cero**: un cero diría que nada cambió, y lo que pasa es que no hay con qué
medirlo. Dos indicadores (incidencias abiertas y personas sin tarjeta) son una
foto de hoy y no tienen variación.

**Los indicadores, uno a uno, con el objetivo que el producto se fija a los
tres meses de estar en marcha.** El objetivo sale junto al valor y el cuadro
dice con palabras, no solo con un color, si estás dentro o fuera.

- **Jornadas con registro completo — objetivo: 99 % o más.** De todas las
  jornadas del periodo en las que alguien fichó, cuántas tienen **todos sus
  tramos cerrados**: entrada y salida, sin turno olvidado. Es el indicador
  principal, porque es la función del producto: un registro horario completo.
  Una jornada con un turno abierto que se cierra después con una corrección
  pasa a contar como completa desde ese momento: el cuadro se calcula sobre lo
  que hay cuando lo abres.
- **Fichajes por tarjeta — objetivo: 98 % o más**, con el reparto por
  **tarjeta, PIN, corrección manual e importación**. Se cuentan solo los
  fichajes **aceptados** —un escaneo que no produjo tramo no reparte nada— y el
  reparto suma siempre el 100 %. Un porcentaje bajo de tarjeta no es un fallo
  del sistema: es gente sin tarjeta entregada o que usa el PIN por costumbre, y
  la solución está en el §2.4 y en el §2.6.
- **Correcciones sobre fichajes — objetivo: menos del 2 %.** Cuántas
  correcciones (§5) se han hecho en el periodo por cada cien fichajes aceptados
  del mismo periodo. Mide la confianza en el dato: si hay que arreglar a mano
  uno de cada diez, el registro se está haciendo a posteriori, y eso es
  justamente lo que el producto viene a evitar.
- **Incidencias abiertas hoy, y tiempo hasta cerrar un turno olvidado —
  objetivo: menos de 24 horas.** Lo primero es una foto del momento, sin
  variación. Lo segundo es el **tiempo medio** entre que el sistema detecta un
  turno sin cerrar (§4.1) y que alguien lo resuelve (§4.3), contando solo las
  resueltas dentro del periodo; al lado sale la **mediana**, que no se deja
  arrastrar por una incidencia que estuvo un mes olvidada. Mide si la bandeja
  se atiende pronto, no si hay pocas incidencias.
- **Personas sin tarjeta entregada, hoy.** Cuántas personas activas no tienen
  todavía una tarjeta con la entrega registrada (§2.6). Foto de hoy, sin
  variación. Mientras no sea cero, alguien está fichando con PIN o no está
  fichando.
- **Horas trabajadas frente a contratadas** en el periodo, del hotel entero:
  las mismas cifras y los mismos criterios del informe de horas por periodo
  (§6.1) sumados para toda la plantilla, sin días con turno abierto y con los
  festivos tratados igual. No tiene objetivo: es contexto, para leer los demás
  indicadores sabiendo cuánta actividad ha habido.
- **Disponibilidad del acto de fichar — objetivo: 99,9 % o más.** Léelo con
  cuidado, porque no mide lo que parece. **No es «el servidor estaba
  encendido»: es «la persona pudo fichar».** La tablet guarda el fichaje sin
  red y lo sube cuando vuelve la conexión (§8, «…alguien no puede fichar»), así
  que el servidor puede haber estado caído media mañana y la disponibilidad
  seguir en el 100 %: nadie se quedó sin fichar. Se calcula como los fichajes
  que la tablet **atendió** —incluidos los que guardó sin red y subió después,
  que el cuadro enseña aparte como **«resueltos sin servidor»**, y también los
  que una regla rechazó, porque fueron atendidos— frente a los intentos que la
  propia tablet **reportó como fallidos**: la cámara que no arranca, el lector
  que no carga, el almacenamiento que no deja guardar. Dos salvedades, y el
  cuadro las declara: es una **aproximación a favor de la fiabilidad**, porque
  un intento que ni siquiera llegó a producir un error en la tablet no lo ve
  nadie; y el historial de errores de las tablets **se recorta con el tiempo**,
  así que sobre periodos antiguos la cifra puede salir algo mejor que la
  realidad.
- **Horas al mes consolidando hojas de horas — referencia: un 80 % menos.** Es
  el único indicador que **el sistema no puede medir**, porque mide el trabajo
  que RRHH hacía **antes** de instalarlo, y ninguna aplicación observa lo que
  ocurría antes de existir. La cifra la declara el hotel en Ajustes operativos
  (ajuste `BASELINE_MANUAL_HOURS_PER_MONTH`): cuántas horas al mes se iban en
  juntar y cuadrar hojas de horas. El cuadro la enseña tal cual, con el
  objetivo de reducirla un 80 % como referencia para tu propia comparación. Si
  no se ha declarado, la casilla **queda vacía**: el producto no inventa una
  mejora que no ha medido.

**Lo que no es.** Los valores son **de la instalación entera y nunca por
persona**: no hay una columna de «quién corrige más» ni de «quién olvida
fichar», y no la habrá, porque el cuadro sirve para evaluar el sistema, no a la
plantilla. Un número fuera de objetivo señala un proceso que arreglar —tarjetas
sin entregar, una bandeja que nadie mira, una tablet mal colocada—, no a una
persona. Tampoco es una vista en tiempo real: es un periodo cerrado, y una
corrección hecha hoy sobre el mes pasado cambia el cuadro del mes pasado la
próxima vez que lo abras. Al pie, el cuadro declara sus criterios como hace el
informe de horas.

**Exportar.** Se descarga en CSV, Excel o PDF; el PDF lleva sello de tiempo,
emisor y huella del contenido, como los demás. Aunque no lleva nombres, **cada
exportación queda registrada** en la auditoría —quién, qué periodo y en qué
formato—, porque es un documento que sale del sistema y el que tu proveedor te
pedirá al hablar de la renovación. Cuando el documento vaya a salir del hotel
—al proveedor, por ejemplo—, **exporta periodos cerrados de un mes, no días
sueltos**: en una instalación pequeña, un periodo muy corto deja de ser un
agregado y puede leerse como la jornada de una persona concreta.

**Licencia.** Hace falta que el plan incluya el cuadro de impacto. Si no lo
incluye, o la licencia ha caducado, la pantalla lo dice y no enseña nada más;
el fichaje, el registro, las correcciones y la exportación para la Inspección
no se ven afectados (§8, «…hay un aviso de licencia en el panel»).

**Si IT te enseña otro cuadro con el mismo nombre.** El sistema lleva también
un cuadro de mando técnico llamado «Impacto y adopción» en la herramienta de
supervisión del servidor. Usa las mismas definiciones —qué es una jornada
completa, qué es un fichaje aceptado, qué es una corrección— pero **mira los
últimos siete días** de forma continua, mientras que este cuadro mira un
periodo cerrado que eliges tú. Por eso pueden no coincidir al céntimo, y no es
un error: el de la pantalla del panel es el que vale para hablar con tu
proveedor.

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
incidencia. El **calendario de festivos** lo aplica el **informe de horas por
periodo**: los días que figuren en él no se cuentan como absentismo no
justificado (§5 bis.4). **No abre ni cierra ninguna incidencia** y no cambia ni
una hora del registro; si lo dejas vacío, el informe sencillamente no descuenta
ningún festivo.

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
—por ejemplo, la marca propia vuelve a la del producto, o el cuadro de impacto
(§6.6) deja de mostrarse—. Dejarte sin registro
horario por una cuestión comercial te dejaría incumpliendo la ley, y eso no lo
hace este producto. Avisa a quien lleve la relación con el proveedor; el detalle
está en [`configuracion.md`](configuracion.md) §3 bis.3.
