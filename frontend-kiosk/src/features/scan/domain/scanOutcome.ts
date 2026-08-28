// Lo que el quiosco ensena tras un escaneo, y con que tono lo dice.
//
// Seis desenlaces, no dos. La distincion importa porque cada uno es una cosa
// distinta para quien esta delante de la tablet:
//
//   accepted    el servidor creo o cerro un tramo. Sabemos si fue entrada o salida.
//   pending     encolado y confirmado en local. AUN NO SABEMOS si es entrada o
//               salida: eso lo decide el agregado `WorkDay` en el servidor. Decir
//               «Entrada» sin saberlo seria mentir en un registro legal.
//   verifying   SOLO en la via del PIN (RF-AT-11): el sobre sellado ya viaja
//               al servidor y hay red, pero el PIN no se puede validar en
//               local (llega sellado; solo el servidor lo abre). Ensenar una
//               confirmacion que parece un exito (`pending`) y luego un
//               rechazo seria enganoso, asi que mientras se espera la
//               respuesta (como mucho `PIN_VERIFY_TIMEOUT_MS`) la pantalla
//               dice, honestamente, que esta comprobando. Ver `pinPipeline.ts`.
//   debounced   valido, pero dentro de la ventana anti-rebote: no cambio nada
//               (RF-AT-06, ADR-031). NO es un error y no suena como tal.
//   rejected    el servidor no lo pudo registrar. Motivo generico SIEMPRE
//               (regla dura 17, RS-03).
//   unreadable  lo leido por la camara no tiene forma de tarjeta de KronoQR.
//               Mismo mensaje generico, por lo mismo.

import type { ScanAcceptedAction } from '@/shared/api/types'

/**
 * Tono sonoro. Entrada y salida son ASCENDENTE y DESCENDENTE, que es la unica
 * pareja que nadie confunde en una cocina con extractores a tope, y que ademas
 * distingue quien no ve bien el color.
 */
export type FeedbackTone = 'entry' | 'exit' | 'pending' | 'verifying' | 'notice' | 'error'

export interface ScanConfirmationBase {
  readonly scanId: string
  /** Momento real del escaneo, para pintar la hora. */
  readonly occurredAt: Date
}

export interface AcceptedConfirmation extends ScanConfirmationBase {
  readonly kind: 'accepted'
  readonly action: ScanAcceptedAction
  readonly displayName: string
  readonly workedMinutes: number
  readonly workDate: string
}

export interface PendingConfirmation extends ScanConfirmationBase {
  readonly kind: 'pending'
  /** Del padron cacheado; `null` si el padron no reconoce la tarjeta. */
  readonly displayName: string | null
}

/**
 * Solo la via del PIN la produce, y solo mientras hay red: nunca dice
 * entrada ni salida, ni siquiera «pendiente de validar» — eso ultimo
 * prometeria que ya esta encolado con certeza de que tardara, cuando en
 * realidad se espera contestacion inmediata.
 */
export interface VerifyingConfirmation extends ScanConfirmationBase {
  readonly kind: 'verifying'
}

export interface DebouncedConfirmation extends ScanConfirmationBase {
  readonly kind: 'debounced'
  readonly displayName: string
  readonly workedMinutes: number
  readonly lastAcceptedAt: Date
}

export interface RejectedConfirmation extends ScanConfirmationBase {
  readonly kind: 'rejected'
}

export interface UnreadableConfirmation extends ScanConfirmationBase {
  readonly kind: 'unreadable'
}

export type ScanConfirmation =
  | AcceptedConfirmation
  | PendingConfirmation
  | VerifyingConfirmation
  | DebouncedConfirmation
  | RejectedConfirmation
  | UnreadableConfirmation

/**
 * `clock_in` y `break_end` son «vuelve al puesto»; `clock_out` y `break_start`
 * son «se va». Es la agrupacion que decide color y sonido.
 */
export function isArrival(action: ScanAcceptedAction): boolean {
  return action === 'clock_in' || action === 'break_end'
}

export function toneFor(confirmation: ScanConfirmation): FeedbackTone {
  switch (confirmation.kind) {
    case 'accepted':
      return isArrival(confirmation.action) ? 'entry' : 'exit'
    case 'pending':
      // Ni entrada ni salida: todavia no se sabe. Un tono propio es lo honesto.
      return 'pending'
    case 'verifying':
      // Ni siquiera «pendiente»: se esta comprobando ahora mismo. Tono neutro
      // y corto, sin nada que suene a exito ni a error.
      return 'verifying'
    case 'debounced':
      return 'notice'
    case 'rejected':
    case 'unreadable':
      return 'error'
  }
}

/** Paleta del panel de confirmacion. Contrastes verificados en `main.css`. */
export type ConfirmationVariant = 'entry' | 'exit' | 'pending' | 'verifying' | 'notice' | 'error'

export function variantFor(confirmation: ScanConfirmation): ConfirmationVariant {
  return toneFor(confirmation)
}

/**
 * Cuanto tiempo espera la via del PIN una respuesta del servidor antes de
 * rendirse y pintar «pendiente» (RF-AT-11). Vive aqui, junto al resto de las
 * duraciones de pantalla, y no en `pinPipeline.ts`, porque tiene que ser
 * LITERALMENTE el mismo numero que `CONFIRMATION_DISPLAY_MS.verifying`: el
 * tiempo que la pantalla de «Comprobando…» se queda puesta ANTES de saber
 * nada mas es, por definicion, el tiempo que se espera. Un numero y una sola
 * fuente de verdad, no dos constantes que alguien puede desincronizar en un
 * cambio futuro.
 */
export const PIN_VERIFY_TIMEOUT_MS = 2_500

/**
 * Cuanto tiempo se queda la confirmacion en pantalla. La siguiente persona de la
 * cola no debe esperar a que se apague, pero tiene que dar tiempo a leerla.
 *
 * `verifying` es distinto de los demas: no es «cuanto tiempo se ensena un
 * resultado ya sabido», es el plazo de gracia mientras se espera el
 * resultado. Por eso vale `PIN_VERIFY_TIMEOUT_MS` y no un valor de lectura.
 */
export const CONFIRMATION_DISPLAY_MS: Readonly<Record<ScanConfirmation['kind'], number>> = {
  accepted: 4_000,
  pending: 4_000,
  verifying: PIN_VERIFY_TIMEOUT_MS,
  debounced: 3_500,
  rejected: 5_000,
  unreadable: 5_000,
}
