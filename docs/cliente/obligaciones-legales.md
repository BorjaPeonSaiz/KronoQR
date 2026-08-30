# Obligaciones legales del hotel al usar KronoQR

> **Estado.** Redactado en la **tarea 2.10**, que es la que decide la política de
> retención por tipo de dato. La **tarea 5.11** lo revisa e integra en el paquete
> de documentación final; no lo reescribe.

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

## 7. Lo que el fabricante no puede hacer por ti

| No puede | Por qué |
| --- | --- |
| Entrar a mirar tus datos | El sistema corre en tu servidor y no hay acceso remoto por defecto (ADR-016, ADR-020) |
| Atender una solicitud de derechos | Eres el responsable del tratamiento |
| Responder un requerimiento de la Inspección | Lo firma el hotel |
| Recuperar un dato ya purgado | La purga es irreversible; para eso está la confirmación |

Si necesitas soporte sobre una incidencia, el paquete de diagnóstico va
**anonimizado por defecto** y cualquier acceso ampliado es expreso, temporal y
queda auditado.
