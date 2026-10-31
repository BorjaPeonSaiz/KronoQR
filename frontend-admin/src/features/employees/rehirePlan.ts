// Avisos de fecha de un alta o una readmision (RN-27, ADR-062 §6), decididos por
// el propietario el 31-10-2026.
//
// Dos cosas que RRHH debe saber ANTES de confirmar, porque el efecto llega a la
// tablet y no se ve desde el panel:
//  - con una fecha de alta FUTURA, los fichajes anteriores a esa fecha se rechazan
//    (la persona todavia no esta de alta; igual que uno posterior al cese);
//  - al readmitir a quien se dio de baja hoy, hay que esperar a que los quioscos
//    esten sincronizados: hasta entonces pueden conservar el estado de la baja o
//    la tarjeta y el PIN del periodo anterior (riesgo residual aceptado, doc 07 §6).
//
// «Hoy» es la fecha civil del CENTRO (ADR-040), que calcula quien llama; sin ella
// (`''`) no se adivina nada y no hay avisos. Son avisos, no bloqueos: el servidor
// decide y la tablet nunca bloquea al empleado (regla dura 19).
import { todayInZone } from '@kronoqr/web-kit/datetime'

export interface RehireDateWarnings {
  /** La fecha de alta es posterior a hoy: los fichajes anteriores se rechazan. */
  futureHire: boolean
  /** La baja se hizo efectiva hoy (en la zona del centro): esperar a la sincronizacion. */
  recentTermination: boolean
  /** La fecha de alta no es posterior al cese: el servidor responde `422`. */
  notAfterTermination: boolean
}

export interface RehireDateInput {
  /** Fecha de alta elegida, `AAAA-MM-DD`, o vacia. */
  hiredAt: string
  /** Fecha de cese del periodo anterior. */
  terminatedAt: string | null
  /** Hoy en la zona del centro, o vacio si aun no se conoce. */
  today: string
  timezone: string | undefined
  /** Cuando se hizo efectiva la baja (ver `isRecentTermination`). */
  offboardedAt: string | null
}

/** Aviso del alta con fecha futura (sirve tambien para el alta de una persona nueva). */
export function isFutureHire(hiredAt: string, today: string): boolean {
  return today !== '' && hiredAt !== '' && hiredAt > today
}

export interface RecentTerminationInput {
  /**
   * Instante UTC en que la baja se hizo efectiva: `Employee.offboarding.offboarded_at`, que
   * viaja tambien en el listado de Plantilla. `null` si la baja no es efectiva (o la ficha no la
   * trae): entonces no hay nada que esperar.
   */
  offboardedAt: string | null
  /** Hoy en la zona del centro, o vacio si aun no se conoce. */
  today: string
  timezone: string | undefined
}

/**
 * La baja «es de hoy»: se hizo efectiva hoy, en la fecha civil del CENTRO (ADR-040), sea
 * cual sea su fecha de cese (una baja retroactiva registrada esta manana tambien cuenta, y
 * una programada con cese ayer la hace efectiva la tarea hoy). Hasta ese momento los
 * quioscos pueden seguir tratando a la persona como de alta.
 */
export function isRecentTermination(input: RecentTerminationInput): boolean {
  const { offboardedAt, today, timezone } = input

  return (
    today !== '' &&
    offboardedAt !== null &&
    timezone !== undefined &&
    todayInZone(timezone, new Date(offboardedAt)) === today
  )
}

export function rehireDateWarnings(input: RehireDateInput): RehireDateWarnings {
  return {
    futureHire: isFutureHire(input.hiredAt, input.today),
    recentTermination: isRecentTermination(input),
    notAfterTermination:
      input.hiredAt !== '' && input.terminatedAt !== null && input.hiredAt <= input.terminatedAt,
  }
}

/** Una tarjeta nueva de una readmision, lista para imprimir. */
export interface RehireCardItem {
  employeeUuid: string
  credentialUuid: string
  name: string
}

/** Un PIN nuevo de una readmision en lote, para enseñarlo una sola vez. */
export interface RehirePinRow {
  employeeUuid: string
  name: string
  code: string
  pin: string
}
