// Traduce un desfase en segundos (mismo signo que `clockSkewSeconds` del
// latido: positivo = tablet adelantada) a lo que se lee en pantalla: minutos
// enteros y direccion. Compartido por la banda persistente de `ScanView` y
// por la linea de aviso de `ScanConfirmationPanel` (RF-AT-10, decision 6 de
// la tarea 3.5): las dos dicen la MISMA frase, solo cambia donde se pinta.

export type ClockSkewDirection = 'ahead' | 'behind'

export interface ClockSkewMinutes {
  /** Redondeado, nunca cero: un desfase que ya superó el umbral (>= 60 s) siempre vale la pena en minutos. */
  readonly minutes: number
  readonly direction: ClockSkewDirection
}

export function clockSkewMinutesFrom(skewSeconds: number): ClockSkewMinutes {
  return {
    minutes: Math.max(1, Math.round(Math.abs(skewSeconds) / 60)),
    direction: skewSeconds >= 0 ? 'ahead' : 'behind',
  }
}

/** `true` si el desfase medido supera la tolerancia de la instalacion. `null` = sin umbral todavia (decision 6: sin banda, no un valor inventado). */
export function exceedsClockSkewTolerance(
  skewSeconds: number,
  toleranceSeconds: number | null,
): boolean {
  if (toleranceSeconds === null) return false
  return Math.abs(skewSeconds) > toleranceSeconds
}
