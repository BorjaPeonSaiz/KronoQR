# support

Paquete de diagnostico y accesos de soporte auditados (RF-PD-09, RF-PD-11,
tarea 5.9, ADR-020, regla dura 16).

Una unica pantalla, `SupportView.vue` (ruta `/support`), con dos bloques que
resuelven dos preguntas distintas:

- **«¿Que le mando a soporte?»** — el paquete de diagnostico
  (`generateDiagnosticsBundle` en `support.api.ts`). Va **anonimizado por
  defecto**: sin nombres, sin correos, sin registros de jornada. Incluir datos
  personales es una casilla aparte, **desmarcada**, que al marcarla enseña un
  aviso (`role="alert"`) con lo que va a llevar el paquete y con que queda
  registrado en auditoria — nunca es el valor por defecto ni el efecto
  secundario de otra opcion (RL-19).
- **«¿Como dejo entrar al fabricante si hace falta?»** — los accesos de
  soporte (`listSupportGrants`, `grantSupportAccess`, `revokeSupportAccess`).
  Cada concesion tiene motivo obligatorio, alcance y caducidad; el token que
  emite **se enseña una sola vez**, en el momento de concederla, con un aviso
  de que no se volvera a mostrar.

## Lo que esta pantalla NUNCA hace

**No interpreta el contenido del paquete.** Ni lo parsea, ni lo resume, ni
enseña una vista previa: lo descarga tal cual llega el JSON del servidor y lo
suelta con `downloadDocument` (`@kronoqr/web-kit`), exactamente igual que el
CSV de la exportacion legal o el PDF de una credencial. Interpretarlo aqui
seria darle al panel un motivo para guardarlo en memoria o en pantalla, y ese
fichero puede llevar, si se pide expresamente, la plantilla y los fichajes de
la instalacion.

**No decide el nombre del fichero.** Lo trae `Content-Disposition`
(`kronoqr-diagnostics-<version>-<UTC>.json`); esta pantalla solo lo enseña de
vuelta en el mensaje de exito para que se pueda contrastar con lo que se ha
guardado.

**No guarda el token de una concesion en ningun sitio mas alla del estado
efimero de esta vista.** Desaparece al salir de la pantalla, igual que el PIN
en claro de `credentials` o el secreto TOTP de `auth`.

## Quien la ve

Solo quien lleva `support:*` **o** `diagnostics:*` (`features/auth/abilities.ts`),
que en la practica es solo `admin` (doc 02 §7.3): ningun otro rol concede
soporte ni genera el paquete. El bloque de accesos, ademas, exige `support:*`
para su propio formulario -un token de soporte con alcance `diagnostics`
jamas ve ni toca este bloque (regla dura 16, ADR-020)-. La autorizacion real
esta en la policy de cada endpoint del servidor (regla dura 18); ocultar el
enlace de navegacion solo evita la frustracion de llegar a una pantalla que
devolveria `403`. Dentro de la propia pantalla, `canGenerateDiagnostics` y
`canManageSupportGrants` (`SupportView.vue`) ocultan cada bloque por
separado si a la sesion le falta el ambito correspondiente: hoy es un caso
teorico -solo `admin` llega aqui, con `['*']`-, pero la regla «lo que no se
puede usar no se enseña» se aplica igual.

Carpeta por _feature_, no por tipo de fichero (doc 02 §3.5).
