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
