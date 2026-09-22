// Doble de `AudioContext` para comprobar el canal sonoro sin altavoz (doc 01
// §6.5, RF-KI-06, tarea 3.7).
//
// `useScanSound.ts` sintetiza los pitidos con osciladores reales
// (`createOscillator`/`createGain`, sin ficheros de audio: ver el comentario
// de cabecera de ese fichero). Este doble implementa solo lo que esa
// composable usa, y registra CADA paso de frecuencia (`frequency.setValueAtTime`)
// que se programa: como `oscillator.type` se fija ANTES de programar la
// frecuencia (mismo orden que `useScanSound.ts`), cada entrada registrada ya
// lleva el tipo de onda correcto.
//
// Se instala con `page.addInitScript`, ANTES de `page.goto('/')`, para que
// `window.AudioContext` este sustituido desde el primer script de la pagina
// -incluida la instanciacion de `useScanSound` al montar la pantalla-.

import type { Page } from '@playwright/test'

export interface RecordedTone {
  readonly frequency: number
  readonly type: OscillatorType
  /** Desplazamiento, en segundos, respecto al inicio del sonido completo. */
  readonly at: number
}

export interface AudioProbe {
  /** Lo que ha sonado desde la instalacion, o desde el ultimo `reset()`. */
  readTones(): Promise<RecordedTone[]>
  /**
   * Vacia el registro. Hace falta cuando, en la MISMA pagina, algo ajeno a lo
   * que se esta probando puede sonar de fondo -el escaneo de tarjeta que
   * decodifica solo en las pruebas de PIN (`support/pin.ts`)- y podria
   * colarse ENTRE el intento que se está midiendo y la lectura, sobre todo
   * con `retries: 0`: sin `reset()`, un ultimo elemento del registro que en
   * realidad es ese fichaje de fondo produciria un fallo intermitente.
   */
  reset(): Promise<void>
}

/** Instala el doble y devuelve como leerlo y vaciarlo. */
export async function installAudioProbe(page: Page): Promise<AudioProbe> {
  await page.addInitScript(() => {
    interface RecordedToneInternal {
      frequency: number
      type: string
      at: number
    }

    const w = window as unknown as { __kioskToneLog: RecordedToneInternal[] }
    w.__kioskToneLog = []

    class FakeAudioParam {
      value = 0
      setValueAtTime(value: number, at: number): FakeAudioParam {
        this.value = value
        void at
        return this
      }
      exponentialRampToValueAtTime(): FakeAudioParam {
        return this
      }
      linearRampToValueAtTime(): FakeAudioParam {
        return this
      }
    }

    class FakeAudioNode {
      connect(): FakeAudioNode {
        return this
      }
      disconnect(): void {
        /* nada que liberar en el doble */
      }
    }

    class FakeOscillatorNode extends FakeAudioNode {
      type: OscillatorType = 'sine'
      readonly frequency = new FakeAudioParam()
      onended: (() => void) | null = null
      start(): void {
        /* el doble no reproduce nada, solo registra */
      }
      stop(): void {
        this.onended?.()
      }
    }

    class FakeGainNode extends FakeAudioNode {
      readonly gain = new FakeAudioParam()
    }

    class FakeAudioContext {
      state: AudioContextState = 'running'
      currentTime = 0
      readonly destination = new FakeAudioNode()

      createOscillator(): FakeOscillatorNode {
        const oscillator = new FakeOscillatorNode()
        const originalSetValueAtTime = oscillator.frequency.setValueAtTime.bind(
          oscillator.frequency,
        )
        oscillator.frequency.setValueAtTime = (value: number, at: number) => {
          w.__kioskToneLog.push({
            frequency: value,
            type: oscillator.type,
            at: at - this.currentTime,
          })
          return originalSetValueAtTime(value, at)
        }
        return oscillator
      }

      createGain(): FakeGainNode {
        return new FakeGainNode()
      }

      resume(): Promise<void> {
        this.state = 'running'
        return Promise.resolve()
      }

      close(): Promise<void> {
        return Promise.resolve()
      }
    }

    // @ts-expect-error -- doble deliberado para la prueba, no la API real.
    // `useScanSound.ts` (`defaultFactory`) solo mira `globalThis.AudioContext`.
    window.AudioContext = FakeAudioContext
  })

  return {
    readTones: () =>
      page.evaluate(() => (window as unknown as { __kioskToneLog: RecordedTone[] }).__kioskToneLog),
    reset: () =>
      page.evaluate(() => {
        ;(window as unknown as { __kioskToneLog: RecordedTone[] }).__kioskToneLog = []
      }),
  }
}
