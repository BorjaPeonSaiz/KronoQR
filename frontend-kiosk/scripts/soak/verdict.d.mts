// Tipos de `verdict.mjs`, para que la prueba de Vitest y `soak-monitor.mjs` los compartan.

export interface SoakSample {
  /** Milisegundos desde el inicio de la prueba. */
  readonly elapsedMs: number
  /** `JSHeapUsedSize` de `Performance.getMetrics` (CDP). */
  readonly jsHeapUsedBytes: number
  /** Memoria del proceso de la pestaña (PSS por `adb`), si se pudo leer. Manda sobre el montón. */
  readonly processMemoryBytes: number | null
  readonly domNodes: number
  readonly eventListeners: number
  /** Pistas de video vivas de TODAS las llamadas a `getUserMedia` de la pagina. */
  readonly liveVideoTracks: number
  /** `currentTime` del `<video>` del escaner, o `null` si no esta en pantalla. */
  readonly videoTime: number | null
  readonly visible: boolean
  /** El aviso de camara (`camera-failure`) esta en pantalla. */
  readonly cameraFailureShown: boolean
  /** Recargas de la pagina desde el inicio de la prueba. */
  readonly reloads: number
}

export interface SoakSummary {
  readonly samples: number
  readonly hours: number
  readonly peakMemoryBytes: number
  readonly memorySlopeBytesPerHour: number
  readonly nodesSlopePerHour: number
  readonly listenerSpread: number
  readonly reloads: number
}

export interface SoakVerdict {
  readonly passed: boolean
  readonly failures: readonly string[]
  readonly summary: SoakSummary | null
}

export declare const SOAK_MAX_MEMORY_BYTES: number
export declare const SOAK_WARM_UP_MS: number
export declare const SOAK_MAX_MEMORY_SLOPE_BYTES_PER_HOUR: number
export declare const SOAK_MAX_NODES_SLOPE_PER_HOUR: number
export declare const SOAK_MAX_LISTENER_SPREAD: number
export declare const SOAK_MAX_RELOADS: number

export declare function slopePerHour(
  points: readonly { readonly elapsedMs: number; readonly value: number }[],
): number

export declare function soakVerdict(samples: readonly SoakSample[]): SoakVerdict
