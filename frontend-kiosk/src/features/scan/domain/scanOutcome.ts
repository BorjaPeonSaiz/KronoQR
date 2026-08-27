// Lo que el quiosco ensena tras un escaneo, y con que tono lo dice.
//
// Cinco desenlaces, no dos. La distincion importa porque cada uno es una cosa
// distinta para quien esta delante de la tablet:
//
//   accepted    el servidor creo o cerro un tramo. Sabemos si fue entrada o salida.
//   pending     encolado y confirmado en local. AUN NO SABEMOS si es entrada o
//               salida: eso lo decide el agregado `WorkDay` en el servidor. Decir
//               «Entrada» sin saberlo seria mentir en un registro legal.
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
export type FeedbackTone = 'entry' | 'exit' | 'pending' | 'notice' | 'error'

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
    case 'debounced':
      return 'notice'
    case 'rejected':
    case 'unreadable':
      return 'error'
  }
}

/** Paleta del panel de confirmacion. Contrastes verificados en `main.css`. */
export type ConfirmationVariant = 'entry' | 'exit' | 'pending' | 'notice' | 'error'

export function variantFor(confirmation: ScanConfirmation): ConfirmationVariant {
  return toneFor(confirmation)
}

/**
 * Cuanto tiempo se queda la confirmacion en pantalla. La siguiente persona de la
 * cola no debe esperar a que se apague, pero tiene que dar tiempo a leerla.
 */
export const CONFIRMATION_DISPLAY_MS: Readonly<Record<ScanConfirmation['kind'], number>> = {
  accepted: 4_000,
  pending: 4_000,
  debounced: 3_500,
  rejected: 5_000,
  unreadable: 5_000,
}
