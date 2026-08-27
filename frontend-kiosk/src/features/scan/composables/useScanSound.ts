// Feedback sonoro diferenciado (RF-AT-05, RF-KI-06).
//
// «En una cocina ruidosa hay que ver; en una recepcion a oscuras hay que oir.»
//
// POR QUE TONOS SINTETIZADOS Y NO FICHEROS DE AUDIO. Tres motivos, y los tres
// pesan:
//   - La CSP del §7.2 es estricta y `media-src` solo admite `'self'` y `blob:`.
//     Un `data:` URI con un WAV no pasaria.
//   - Cuatro sonidos en MP3 son entre 40 y 80 KB del presupuesto del Anexo A.
//     Esto son cero bytes.
//   - Un fichero de audio hay que precachearlo en el service worker y puede
//     faltar. Un oscilador no falla nunca por estar sin red.
//
// POR QUE ASCENDENTE Y DESCENDENTE. Entrada y salida tienen que ser
// inconfundibles a tres metros y con extractores encendidos. El contorno
// melodico —sube o baja— se reconoce sin prestar atencion y sin depender de
// timbres parecidos, y funciona igual para quien no distingue el verde del rojo
// en la pantalla.
//
// EL DESBLOQUEO. Los navegadores no dejan sonar nada hasta que hay un gesto del
// usuario. En el quiosco el gesto es conceder el permiso de camara al arrancar,
// pero no se puede dar por hecho: `unlock()` se engancha al primer toque de
// pantalla y, si el contexto sigue suspendido, se reporta `kiosk.audio.blocked`
// para que se vea en el diagnostico en lugar de quedarse mudo en silencio.

import { onUnmounted } from 'vue'
import type { FeedbackTone } from '../domain/scanOutcome'

interface ToneStep {
  readonly frequency: number
  /** Desplazamiento del inicio respecto al comienzo del sonido, en segundos. */
  readonly at: number
  readonly duration: number
  readonly type: OscillatorType
  readonly gain: number
}

/**
 * Las cinco firmas sonoras. `entry` sube, `exit` baja, `error` es un zumbido
 * grave y roto, `notice` es un unico toque neutro y `pending` un doble toque
 * apagado que no promete ni entrada ni salida.
 */
const TONES: Readonly<Record<FeedbackTone, readonly ToneStep[]>> = {
  entry: [
    { frequency: 784, at: 0, duration: 0.11, type: 'sine', gain: 0.28 },
    { frequency: 1175, at: 0.11, duration: 0.17, type: 'sine', gain: 0.28 },
  ],
  exit: [
    { frequency: 1175, at: 0, duration: 0.11, type: 'sine', gain: 0.28 },
    { frequency: 784, at: 0.11, duration: 0.17, type: 'sine', gain: 0.28 },
  ],
  pending: [
    { frequency: 587, at: 0, duration: 0.09, type: 'triangle', gain: 0.22 },
    { frequency: 587, at: 0.14, duration: 0.09, type: 'triangle', gain: 0.22 },
  ],
  notice: [{ frequency: 880, at: 0, duration: 0.28, type: 'triangle', gain: 0.22 }],
  error: [
    { frequency: 196, at: 0, duration: 0.16, type: 'square', gain: 0.2 },
    { frequency: 147, at: 0.2, duration: 0.28, type: 'square', gain: 0.2 },
  ],
}

export interface ScanSoundOptions {
  readonly onBlocked?: (context: Record<string, string | number | boolean>) => void
  /** Inyectable para pruebas: evita depender de `AudioContext` en jsdom. */
  readonly audioContextFactory?: () => AudioContext | null
}

export interface ScanSound {
  play(tone: FeedbackTone): void
  unlock(): void
  dispose(): void
}

function defaultFactory(): AudioContext | null {
  const Ctor = globalThis.AudioContext
  if (typeof Ctor !== 'function') return null
  try {
    return new Ctor()
  } catch {
    return null
  }
}

export function createScanSound(options: ScanSoundOptions = {}): ScanSound {
  const factory = options.audioContextFactory ?? defaultFactory
  // UN solo AudioContext para toda la vida de la aplicacion. Crear uno por
  // sonido es la fuga clasica: el navegador limita cuantos puede haber vivos y
  // no los recoge hasta que se cierran explicitamente.
  let context: AudioContext | null = null
  let blockedReported = false

  function ensureContext(): AudioContext | null {
    if (context === null) context = factory()
    return context
  }

  function unlock(): void {
    const ctx = ensureContext()
    if (ctx === null) return
    if (ctx.state === 'suspended') {
      void ctx.resume().catch(() => {
        /* se reintenta al siguiente gesto */
      })
    }
  }

  function play(tone: FeedbackTone): void {
    const ctx = ensureContext()
    if (ctx === null) return

    if (ctx.state === 'suspended') {
      void ctx.resume().catch(() => undefined)
      if (!blockedReported) {
        blockedReported = true
        options.onBlocked?.({ audio_state: ctx.state })
      }
    }

    const startedAt = ctx.currentTime
    for (const step of TONES[tone]) {
      try {
        const oscillator = ctx.createOscillator()
        const envelope = ctx.createGain()
        const from = startedAt + step.at
        const to = from + step.duration

        oscillator.type = step.type
        oscillator.frequency.setValueAtTime(step.frequency, from)

        // Rampas en lugar de cortes secos: un corte a media onda produce un
        // chasquido que en una sala silenciosa suena a fallo.
        envelope.gain.setValueAtTime(0.0001, from)
        envelope.gain.exponentialRampToValueAtTime(step.gain, from + 0.012)
        envelope.gain.exponentialRampToValueAtTime(0.0001, to)

        oscillator.connect(envelope)
        envelope.connect(ctx.destination)
        oscillator.start(from)
        oscillator.stop(to + 0.02)
        // Sin esto, el grafo de nodos crece un nodo por pitido durante ocho
        // horas. Con 400 fichajes al dia eso son 800 nodos colgando del destino.
        oscillator.onended = () => {
          oscillator.disconnect()
          envelope.disconnect()
        }
      } catch {
        // Un sonido que no suena no puede impedir un fichaje.
        return
      }
    }
  }

  function dispose(): void {
    const ctx = context
    context = null
    if (ctx === null) return
    void ctx.close().catch(() => undefined)
  }

  return { play, unlock, dispose }
}

/** Version para componentes: se desbloquea al primer toque y se cierra al desmontar. */
export function useScanSound(options: ScanSoundOptions = {}): ScanSound {
  const sound = createScanSound(options)

  const onFirstGesture = (): void => sound.unlock()

  if (typeof window !== 'undefined') {
    window.addEventListener('pointerdown', onFirstGesture, { passive: true })
    window.addEventListener('keydown', onFirstGesture)
  }

  onUnmounted(() => {
    if (typeof window !== 'undefined') {
      window.removeEventListener('pointerdown', onFirstGesture)
      window.removeEventListener('keydown', onFirstGesture)
    }
    sound.dispose()
  })

  return sound
}
