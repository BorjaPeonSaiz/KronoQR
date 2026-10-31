// Que pasa con una baja segun su fecha de cese (RN-14, ADR-062 §3).
//
// Es la tabla de `POST /employees/{uuid}/offboard` del contrato, escrita una
// sola vez para que el dialogo pueda decir ANTES de confirmar lo que va a ocurrir:
// si la persona sigue fichando hasta su ultimo dia (programada), si deja de
// poder fichar en el acto o si la contratacion nunca llego a empezar. El
// servidor decide de verdad; esto solo evita enseñar una promesa falsa.
//
// «Hoy» es la fecha civil del CENTRO, que calcula quien llama (nunca la del
// navegador). Sin ella (`today === ''`) no se adivina nada: el resultado es
// `unknown` y el servidor resuelve.

export type OffboardOutcome =
  /** Baja efectiva al confirmar: sin fichaje desde ese momento. */
  | 'immediate'
  /** Baja programada: ficha hasta su ultimo dia y la tarea la aplica el dia siguiente. */
  | 'scheduled'
  /** La contratacion que no llego a empezar: baja en el acto, sin dias trabajados. */
  | 'noEffect'
  /** Se rechaza: fecha de cese anterior al alta. */
  | 'beforeHire'
  /** Se rechaza: «Aplicar ya» con un cese posterior a hoy. */
  | 'applyNowInFuture'
  /** Sin la zona del centro todavia: no se puede saber. */
  | 'unknown'

export interface OffboardPlanInput {
  /** Fecha de cese tecleada, `AAAA-MM-DD`; vacia mientras no se haya elegido. */
  terminatedAt: string
  /** Fecha de alta de la ficha. */
  hiredAt: string
  /** Hoy en la zona del centro, o vacio si aun no se conoce. */
  today: string
  applyNow: boolean
}

export function planOffboard(input: OffboardPlanInput): OffboardOutcome {
  const { terminatedAt, hiredAt, today, applyNow } = input

  if (today === '' || terminatedAt === '') {
    return 'unknown'
  }

  if (terminatedAt < hiredAt) {
    return 'beforeHire'
  }

  if (hiredAt > today && terminatedAt === hiredAt) {
    // El extra de un solo dia: ficha ese dia, salvo que se quiera deshacer ya.
    return applyNow ? 'noEffect' : 'scheduled'
  }

  if (terminatedAt < today) {
    return 'immediate'
  }

  if (terminatedAt === today) {
    return applyNow ? 'immediate' : 'scheduled'
  }

  return applyNow ? 'applyNowInFuture' : 'scheduled'
}

/**
 * Si «Aplicar ya» tiene algun efecto con esa fecha. Solo lo tiene con un cese de
 * hoy o con el cese en un alta que aun no ha llegado; en cualquier otro caso la
 * casilla no se ofrece (un cese pasado ya es en el acto, y uno futuro se rechaza).
 */
export function applyNowApplies(input: Omit<OffboardPlanInput, 'applyNow'>): boolean {
  const { terminatedAt, hiredAt, today } = input

  if (today === '' || terminatedAt === '') {
    return false
  }

  return terminatedAt === today || (hiredAt > today && terminatedAt === hiredAt)
}
