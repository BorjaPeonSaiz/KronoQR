// Ambitos del token (doc 02 §7.3).
//
// Sirven para NO ofrecer lo que despues seria un 403. **No son autorizacion**:
// la de verdad esta en la policy de cada endpoint, en el servidor (regla dura
// 18). Ocultar un boton evita una frustracion, no protege un dato.

/** Gestion de plantilla, departamentos y centros. */
export const EMPLOYEES_MANAGE = 'employees:*'

/** Emision, impresion, entrega y revocacion de credenciales. */
export const CREDENTIALS_MANAGE = 'credentials:*'

/**
 * Lectura del registro horario ya escrito: el detalle de jornada (RF-PA-03).
 *
 * Es de **solo lectura** a proposito y no cubre corregir, que exige
 * `attendance:correct`. Que la pantalla se abra con el ambito estrecho es lo que
 * permite que un rol sin capacidad de rectificar consulte el registro sin poder
 * tocarlo (doc 02 §7.3).
 */
export const ATTENDANCE_READ = 'attendance:read'

/**
 * Correccion del registro horario ya escrito: alta manual, rectificar las
 * marcas y anular un tramo (RF-PA-04, RN-13).
 *
 * Es el UNICO ambito que el contrato declara para las tres operaciones de
 * `POST /shift-entries`, `PATCH /shift-entries/{uuid}` y
 * `POST /shift-entries/{uuid}/void`: no hay un estrecho por operacion como en
 * `attendance:read`/`attendance:correct`. Lo llevan `manager+`
 * (`responsable_departamento`, `rrhh`, `admin`), pero **anular** exige ademas
 * el rol `rrhh+` en el servidor (`ShiftEntryPolicy::void`): un
 * `responsable_departamento` con este ambito puede añadir y corregir, y
 * recibiria `403` si intentara anular. El panel oculta tambien ese boton por
 * rol y no solo por ambito (`canVoidShiftEntry`, `CorrectionDialog.vue`): un
 * boton que siempre acaba en `403` no evita frustracion, la crea.
 */
export const ATTENDANCE_CORRECT = 'attendance:correct'

/**
 * Exportacion normalizada para la Inspeccion de Trabajo (RF-IN-05).
 *
 * Es el ambito ESTRECHO a proposito: el `auditor` lleva `reports:legal` y nada
 * mas, y RRHH lleva `reports:*`, que lo cubre por el comodin de familia. Exigir
 * `reports:*` habria escondido la pantalla justo al rol cuya funcion es esta.
 */
export const REPORTS_LEGAL = 'reports:legal'

/**
 * Informes de gestion: horas por periodo y sus agregados (RF-IN-01..03).
 *
 * Es la FAMILIA, no el estrecho, y la diferencia es exactamente el `auditor`:
 * lleva `reports:legal` y solo puede pedir la exportacion normalizada para un
 * requerimiento. El cuadro de horas trabajadas frente a contratadas es una
 * herramienta de gestion de personal, no de auditoria.
 *
 * El `responsable_departamento` tampoco lo lleva (doc 02 §7.3): no se le ofrece
 * la pantalla, y el servidor le responderia `403` de todos modos.
 */
export const REPORTS_MANAGE = 'reports:*'

/**
 * Bandeja de incidencias: consultarla y resolverla (RF-PA-05, RF-PR-01).
 *
 * Un solo ambito para leer y para resolver porque el contrato solo declara
 * `incidents:*` (doc 02 §7.3): no hay un estrecho de solo lectura como en
 * `attendance:read`/`attendance:correct`. Lo lleva el `responsable_departamento`
 * (el destinatario principal de la bandeja) y `rrhh`/`admin` por alcance
 * completo.
 */
export const INCIDENTS_MANAGE = 'incidents:*'

/**
 * Configuracion de la instalacion y perfil de cumplimiento (RF-PD-01,
 * RF-PD-07).
 *
 * Lo lleva **solo** el administrador de instalacion (doc 02 §7.3). No se parte
 * en un estrecho de lectura porque el contrato no lo declara: quien puede ver
 * los umbrales legales del centro es quien puede cambiarlos, y `rrhh` no es
 * ninguno de los dos.
 */
export const SETTINGS_MANAGE = 'settings:*'

/**
 * Licencia de la instalacion: consultarla y activar una clave (RF-PD-04).
 *
 * **Ambito propio y no `settings:*`**, porque el §7.3 lo declara aparte y hay
 * motivo: la configuracion y los umbrales legales los ajusta el hotel para su
 * operativa; la licencia dice **que se contrato**, y eso no es un ajuste. Lo
 * lleva solo el administrador de instalacion.
 *
 * Gobierna ademas quien ve el **aviso persistente** del marco: a un responsable
 * de departamento un aviso de licencia caducada solo le da ruido, porque no
 * puede renovar nada.
 */
export const LICENSE_MANAGE = 'license:*'

/**
 * Concesion y revocacion de accesos temporales de soporte (RF-PD-11,
 * ADR-020).
 *
 * Ambito propio y no `settings:*`: el §7.3 lo declara aparte porque decidir
 * que el fabricante entre en la instalacion no es un ajuste de la instalacion,
 * es una cesion puntual y auditada. Lo lleva solo el administrador; **el
 * propio token de soporte no lo lleva nunca** (regla dura 16): quien recibe el
 * acceso no puede verse a si mismo en la lista ni concederse mas tiempo.
 */
export const SUPPORT_MANAGE = 'support:*'

/**
 * Generacion del paquete de diagnostico (RF-PD-09, ADR-020).
 *
 * Ambito propio, distinto de `support:*`: un token de soporte con alcance
 * `diagnostics` SI lo lleva —puede generar el paquete anonimizado de la
 * instalacion en la que esta interviniendo—, pero nunca `support:*`. Por eso
 * la pantalla «Soporte» se ofrece con este ambito **o** con `support:*`: el
 * bloque de accesos exige `support:*` y el bloque del paquete, solo este.
 */
export const DIAGNOSTICS_MANAGE = 'diagnostics:*'

/**
 * Si los ambitos concedidos cubren el exigido.
 *
 * Reconoce el comodin de familia (`employees:*` cubre `employees:read`) porque
 * es como el contrato declara los ambitos, y el comodin total (`*`) por si la
 * instalacion lo emite para administracion.
 */
export function hasAbility(granted: readonly string[], required: string): boolean {
  if (granted.includes('*') || granted.includes(required)) {
    return true
  }

  const namespace = required.split(':')[0]

  return namespace !== undefined && namespace !== '' && granted.includes(`${namespace}:*`)
}

/**
 * Si el rol de la sesion puede anular un tramo (RF-PA-04, ADR-026).
 *
 * `attendance:correct` por si solo no basta: cubre las TRES operaciones de
 * correccion en el contrato, pero el servidor exige ademas `rrhh+`
 * (`ShiftEntryPolicy::void`) para anular en concreto — un
 * `responsable_departamento` puede añadir y corregir, y no anular. Este
 * segundo filtro es SOLO comodidad de interfaz (regla dura 18): la policy del
 * servidor es la que autoriza de verdad, y quien la sortee desde la consola de
 * red recibe el mismo `403`.
 */
export function canVoidShiftEntry(roles: readonly string[]): boolean {
  return roles.includes('admin') || roles.includes('rrhh')
}
