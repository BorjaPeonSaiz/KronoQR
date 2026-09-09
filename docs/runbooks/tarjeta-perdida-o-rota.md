# Runbook — tarjeta perdida, rota o no impresa

**Esto no es una avería. Es trabajo de gestión sobre las credenciales**, y por
eso el destinatario es **RRHH** y no el IT del hotel: todo se hace desde el
panel, en el tablero de **Credenciales**, y no hace falta entrar al servidor ni
ejecutar nada.

**Cuándo se usa este procedimiento.** Los cuatro casos se resuelven igual:

| Caso | Motivo de revocación que se elige |
| --- | --- |
| La persona ha perdido la tarjeta | **Tarjeta extraviada** |
| Se la han sustraído | **Tarjeta sustraída** |
| La tarjeta está rota, ilegible o el QR no se lee | **Tarjeta deteriorada** |
| El PDF de la impresión se perdió y la tarjeta nunca llegó a salir | **Impresión fallida: el PDF no llegó a salir** |

**Impacto en el fichaje, que es lo primero que hay que saber: ninguno.** Quien
se ha quedado sin tarjeta **puede fichar hoy mismo** con su código de empleado y
su PIN, en la propia tablet, y ese fichaje cuenta exactamente igual que uno con
tarjeta. Nadie se queda fuera del registro por haber perdido un trozo de
plástico.

---

## 1. Lo que hay que tener claro antes de tocar nada

**No existe la reimpresión, y no es una limitación pendiente de resolver.** El
código QR de una tarjeta **nace en el momento de imprimirla**, dentro del propio
PDF, y no se guarda en ningún sitio del que se pueda volver a sacar. «Volver a
imprimir» solo podría significar acuñar un código nuevo, y eso mataría en
silencio la tarjeta que quizá sigue en un bolsillo o en el suelo de un pasillo.

De ahí sale el procedimiento entero: **para reponer una tarjeta hay que
revocarla y emitir otra**. Son cuatro actos, y los cuatro quedan registrados.

**Revocar es urgente; imprimir puede esperar a mañana.** Mientras la tarjeta
perdida no se revoque, **sigue sirviendo para fichar**: quien la encuentre puede
fichar en nombre de su titular. Revócala en cuanto te lo digan, aunque no vayas
a imprimir la nueva hasta el día siguiente.

---

## 2. El procedimiento, paso a paso

Todo en **Credenciales**, buscando a la persona por nombre o por código.

### Paso 1 — Revocar, con su motivo

Botón **«Revocar»** en su fila, y se elige el motivo de la tabla de arriba. El
motivo es obligatorio.

> **El motivo importa más de lo que parece.** Es lo que distingue «se perdió
> antes de entregarla» de «la perdió el empleado», y lo que explica meses
> después por qué esa persona no pudo fichar un martes. Si eliges «Otro motivo»,
> descríbelo: sin datos de salud y sin juicios de valor sobre la persona.

Al confirmar, **la tarjeta deja de servir en el quiosco en ese mismo momento**.
No se borra nada: la credencial conserva su historia y sus escaneos.

### Paso 2 — Emitir la nueva

Con la fila ya en estado **Revocada**, aparece el botón **«Emitir credencial»**.
Al confirmarlo, la persona vuelve a tener credencial en estado **Pendiente de
imprimir**, que **todavía no permite fichar**: no hay código hasta que se
imprime.

### Paso 3 — Imprimir

Botón **«Imprimir la tarjeta»** → «Generar el PDF». En ese acto se acuña el
código QR y la credencial pasa a **Pendiente de entregar**: ya puede fichar en
cuanto la reciba.

> **Imprime solo con la impresora lista y el PDF a la vista.** Si el PDF se
> pierde ahora, vuelves al paso 1 con el motivo «Impresión fallida» (§3). El
> PDF es un documento al portador: quien lo tenga puede fabricar la tarjeta de
> otra persona. No se guarda en el servidor, no se envía por correo y conviene
> borrarlo del equipo en cuanto esté impresa.

### Paso 4 — Entregar, con su hoja, y registrarlo

Se entrega la tarjeta nueva **junto con una hoja de instrucciones** —el botón
«Hoja de instrucciones» del mismo tablero, en el idioma de la persona— y se
pulsa **«Registrar la entrega»**.

**El PIN no cambia.** Reponer una tarjeta no toca el PIN de nadie: sigue siendo
el mismo para el portal y para el fichaje sin tarjeta. Solo se restablece si la
persona también lo ha olvidado, y entonces se entrega uno nuevo en mano.

**Recoge la tarjeta antigua si aparece.** Ya no sirve para fichar, pero es
plástico con el nombre de una persona: destrúyela en lugar de tirarla entera a
la papelera.

---

## 3. El caso «impresión fallida»

Es el caso raro y el que más desconcierta: **se pulsó «Generar el PDF», el
sistema lo dio por impreso y el PDF nunca llegó a las manos de nadie**. Se cerró
la ventana, falló la descarga, se guardó en un equipo al que ya no se tiene
acceso.

Lo que ha pasado por debajo: el código quedó acuñado y la credencial consta
**Pendiente de entregar**, pero la tarjeta física no existe y su código es
irrecuperable. Es un riesgo conocido y aceptado del diseño, precisamente porque
la alternativa —guardar el código en el servidor para poder reimprimirlo— es
mucho peor.

**Se resuelve con el mismo procedimiento del §2**, eligiendo en el paso 1 el
motivo **«Impresión fallida: el PDF no llegó a salir»**. Ese motivo existe para
que el historial de la persona no diga que perdió una tarjeta que nunca tuvo.

Vale igual para el **lote**: si se pierde el PDF de «Imprimir las pendientes»,
hay que revocar y reemitir **cada una** de las tarjetas incluidas. Por eso el
aviso del botón insiste en generar el lote solo con la impresora lista.

---

## 4. Qué ve y qué hace la persona mientras tanto

Díselo tú, en el momento, y ahórrate la incidencia de mañana:

- **Puede fichar desde ya** con «Ficha con tu código y PIN» en la tablet:
  su código de empleado y su PIN de seis dígitos. El fichaje cuenta igual.
- **Su registro no se ve afectado**: los tramos por PIN aparecen en su jornada
  igual que los de tarjeta, marcados con su origen.
- **Su tarjeta antigua ya no vale**, aunque aparezca. Si la encuentra, que la
  devuelva.
- **Si tampoco recuerda el PIN**, se le restablece desde su ficha y se le
  entrega el nuevo en mano, en ese momento: se muestra una sola vez.

Si al día siguiente hay tramos que no cuadran porque no pudo fichar de ninguna
manera —no tenía tarjeta y había olvidado el PIN—, se corrigen desde su registro
horario con el motivo **«Tarjeta no disponible»**
([`../cliente/guia-rrhh.md`](../cliente/guia-rrhh.md) §5).

---

## 5. Qué queda registrado

Los cuatro actos dejan su apunte propio en el registro de auditoría, con quién
lo hizo y cuándo:

| Acto | Qué queda anotado |
| --- | --- |
| Revocación | El motivo elegido, y el momento exacto desde el que la tarjeta dejó de servir |
| Emisión | Que se emitió una credencial nueva **en sustitución de otra** |
| Impresión | El momento en que se acuñó el código y con qué clave de firma se firmó |
| Entrega | Que la persona la recibió, con la fecha y quién se la entregó |

**Ningún apunte lleva nombres de personas**: el titular viaja como identificador
interno. Y ninguno contiene el código QR, ni siquiera el de la tarjeta revocada.

Esa cadena es la respuesta escrita a la pregunta incómoda: *«¿por qué el día 14
esta persona fichó con PIN y no con su tarjeta?»*. Sin ella, la única respuesta
posible sería la memoria de quien estuviera de turno.

---

## 6. Si algo falla

### El botón «Emitir credencial» no aparece

Mira el estado de la fila. Solo aparece cuando la persona **no tiene ninguna
credencial** o cuando la última está **Revocada**. Si consta «Pendiente de
imprimir», ya está emitida: lo que falta es imprimirla. Si consta «Pendiente de
entregar» o «Entregada», **primero hay que revocar** (paso 1).

### La persona está de baja y no aparece en el tablero

El tablero solo asigna fila a quien está de alta. Una tarjeta de alguien de baja
**ya está revocada** por la propia baja y no hay nada que reponer. Si la baja es
un error, se corrige la situación laboral en su ficha y después se emite la
tarjeta.

### Se ha revocado por error la tarjeta de otra persona

No se puede «des-revocar», y no hay atajo: hay que **emitir e imprimir otra**
para esa persona (pasos 2 a 4). Anota el error en el motivo de la nueva
emisión. Mientras tanto, esa persona ficha con su código y su PIN.

### Nadie puede fichar con tarjeta, no solo una persona

Entonces no es este runbook. Si el tablero avisa de que hay tarjetas firmadas
con una clave que el servidor ya no reconoce, es un asunto de la clave de firma
y lo atiende quien administra el sistema:
[`rotacion-clave-qr.md`](rotacion-clave-qr.md). Mientras se resuelve, **toda la
plantilla puede fichar con código y PIN**.

### Hay que reponer muchas tarjetas a la vez

El procedimiento no cambia —una a una, revocar y emitir—, pero la impresión sí:
el botón **«Imprimir las pendientes»** saca en una sola hoja A4 todas las que
estén pendientes de imprimir. Repasa antes que la lista es exactamente la que
esperas: se acuñan todos los códigos en ese momento.

---

## 7. A quién se escala

**A nadie fuera del hotel.** Este procedimiento se resuelve entero en el panel,
por RRHH. Solo hay dos motivos para escalar al IT del hotel:

1. **El tablero de credenciales no carga** o las acciones devuelven error: es un
   problema del sistema, no de las tarjetas ([`../cliente/operacion.md`](../cliente/operacion.md)).
2. **Falla el mismo paso para varias personas** —por ejemplo, ninguna impresión
   genera PDF—: mirad el histórico de errores del panel antes de repetir la
   operación, porque cada intento de impresión que sí llegue a escribir acuña un
   código que ya no se puede recuperar
   ([`errores-en-el-panel.md`](errores-en-el-panel.md)).
