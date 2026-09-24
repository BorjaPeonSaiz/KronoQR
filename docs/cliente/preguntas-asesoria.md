# Preguntas para la asesoría laboral — lo que el producto no puede contestar

**Ocho preguntas cerradas.** Cada una lleva una línea de contexto y dice qué se
hace con la respuesta. El producto no puede contestarlas: dependen del convenio,
del centro y del criterio de quien responde del tratamiento (RL-16). Llévaselas
a tu asesoría laboral y a tu delegado de protección de datos, si lo tienes,
**antes** de cerrar la evaluación de impacto
([`obligaciones-legales.md`](obligaciones-legales.md) §2) y como primer repaso
de la vigilancia normativa
([`../runbooks/vigilancia-normativa.md`](../runbooks/vigilancia-normativa.md)).

Este documento no es asesoramiento jurídico: es la lista de lo que hay que
preguntar.

| # | Pregunta | Contexto en una línea | Dónde aterriza la respuesta |
| --- | --- | --- | --- |
| 1 | ¿Cuántos años, exactamente, hay que conservar los **datos de contrato** (horas pactadas, tipo de jornada, vigencia) desde el fin de la relación laboral? | Hoy el sistema **no los purga nunca**; el plazo que manejamos —relación laboral + 4 años, por referencia al art. 21 LISOS— **no está validado por nadie**. | Un número. Obliga a **tocar el producto**: un ámbito de purga nuevo. Hasta entonces, `obligaciones-legales.md` §4 (fila de datos de contrato). |
| 2 | ¿Cuántos años hay que conservar las **ausencias**, y el plazo es el mismo para el tipo «Baja médica» y su nota que para unas vacaciones? | Mismo caso que la anterior con un agravante: «Baja médica» y la nota son **datos relativos a la salud**, categoría especial del art. 9 RGPD, y el art. 5.1.e pide no conservar más de lo necesario. Hoy **se conservan indefinidamente**. | Uno o dos números. Igual que la 1: obliga a tocar el producto. `obligaciones-legales.md` §4 (fila de ausencias). |
| 3 | ¿La base jurídica del tratamiento de las ausencias, y en particular de la **baja médica**, es la del art. 9.2.b RGPD (obligaciones en materia de derecho laboral), o hace falta otra? | El registro horario se trata con el art. 6.1.c en relación con el art. 34.9 ET, y eso está escrito; para el dato de salud el producto **no afirma ninguna base** y no le corresponde. | Una frase para el registro de actividades del art. 30 y para la EIPD. `obligaciones-legales.md` §1 y §2. |
| 4 | ¿Procede **evaluación de impacto** (EIPD, art. 35 RGPD) en este centro: sí o no? | Hay observación sistemática de toda la plantilla todos los días, no hay biometría ni geolocalización ni decisiones automatizadas, y **sí hay una categoría especial** desde que se registran ausencias. La conclusión hay que escribirla aunque sea «no procede». | Media hoja fechada, del hotel. `obligaciones-legales.md` §2, apartado de la EIPD. |
| 5 | ¿La **detección automática de patrones de uso de credencial** es admisible dentro de la facultad de vigilancia del art. 20.3 ET y de los arts. 87 y 91 LOPDGDD, y con qué información previa a la plantilla y a su representación legal? | Cada madrugada el sistema abre una incidencia cuando dos personas fichan en la misma tablet con segundos de diferencia varios días, o cuando una tarjeta aparece en dos tablets sin tiempo material para el traslado. **No anula ningún fichaje, no sanciona y no avisa a nadie fuera de la bandeja y del correo del responsable**; pone un indicio delante de una persona. | Sí/no + qué hay que informar y a quién, antes de dejarlo encendido. `configuracion.md` §2.1 y `obligaciones-legales.md` §3. |
| 6 | ¿Puede un indicio de esa detección usarse en un **procedimiento disciplinario**, y con qué garantías del convenio? | El producto no lo impide ni lo puede impedir: el indicio llega a un responsable de departamento, con nombre, y nada en el sistema lo califica de conclusión. Existe un procedimiento escrito que prohíbe tratarlo como prueba ([`../runbooks/patron-anomalo-credencial.md`](../runbooks/patron-anomalo-credencial.md)), pero es un procedimiento del producto, no una garantía legal. | Sí/no + condiciones. Si la respuesta es no, hay que decírselo por escrito a los responsables de departamento. |
| 7 | ¿Es admisible que el **aviso diario de incidencias** salga por correo con el nombre de la persona y el tipo de incidencia —incluido «Patrón anómalo de uso de la credencial»— al buzón de su responsable de departamento? | Es el único camino por el que datos personales salen del servidor sin que nadie pulse nada, y desde la última versión ese aviso puede llevar una sospecha de fraude junto a un nombre. El resumen semanal de horas es el segundo canal y **viene apagado**. | Sí/no, y si es no, hay que decidir si se apaga la notificación o se cambia lo que lleva (petición al fabricante). `obligaciones-legales.md` §2, «Lo que sale del servidor por su cuenta». |
| 8 | ¿Confirmas que la purga del registro horario a los **4 años** es correcta para este centro, y qué hacemos con un periodo afectado por una **reclamación o inspección abierta**? | El plazo sale del perfil de cumplimiento y la purga **nunca es automática**: el sistema propone y una persona confirma. Lo que el producto **no** tiene es una retención forzada por litigio: hoy la salvaguarda es que alguien no confirme. | Un número + una instrucción operativa para quien confirma la purga (`operacion.md`). Si hace falta retención forzada, es petición al fabricante. |

**Las preguntas 1 y 2 son las únicas que obligan a cambiar el producto**; hasta
que se contesten, esos datos se conservan y aparecen en cualquier respuesta a un
derecho de acceso. Las demás se responden por escrito y se archivan con la EIPD.
Mientras una respuesta falte, el riesgo consta aceptado, con dueño y fecha, en
la revisión de seguridad del fabricante, que es donde se vuelve a mirar en cada
cierre de fase y en cada revisión de seguridad.

---

← [Obligaciones legales](obligaciones-legales.md) · [Configuración](configuracion.md) · [Operación](operacion.md)
