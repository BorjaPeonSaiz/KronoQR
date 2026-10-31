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
import { addCivilDays } from './employmentDates'

export interface RehireDateWarnings {
  /** La fecha de alta es posterior a hoy: los fichajes anteriores se rechazan. */
  futureHire: boolean
  /** La baja es de hoy (o de ayer, aplicada hoy por la tarea): esperar a la sincronizacion. */
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
}

/** Aviso del alta con fecha futura (sirve tambien para el alta de una persona nueva). */
export function isFutureHire(hiredAt: string, today: string): boolean {
  return today !== '' && hiredAt !== '' && hiredAt > today
}

/**
 * La baja es «de hoy»: el cese es hoy, o fue ayer y la tarea que aplica las bajas
 * programadas la hizo efectiva hoy (corre el dia civil siguiente al cese). El
 * contrato no expone el instante en que la baja se hizo efectiva
 * (`employment_periods.offboarded_at`), asi que esto es la mejor aproximacion y
 * peca de aviso de mas, nunca de menos.
 */
export function isRecentTermination(terminatedAt: string | null, today: string): boolean {
  return (
    terminatedAt !== null &&
    today !== '' &&
    (terminatedAt === today || terminatedAt === addCivilDays(today, -1))
  )
}

export function rehireDateWarnings(input: RehireDateInput): RehireDateWarnings {
  return {
    futureHire: isFutureHire(input.hiredAt, input.today),
    recentTermination: isRecentTermination(input.terminatedAt, input.today),
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
