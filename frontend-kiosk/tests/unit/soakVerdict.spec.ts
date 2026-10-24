// El veredicto de la prueba de resistencia de 12 h (tarea 17.10, R5-KI-02; doc 02,
// Anexo A: «Memoria en marcha 12 h: <= 250 MB, sin crecimiento sostenido»).
//
// La prueba se hace una vez, en la tablet real y durante 12 h: un veredicto
// equivocado no se descubre hasta repetirla. Por eso cada criterio de
// `scripts/soak/verdict.mjs` tiene aqui su caso que pasa y su caso que no.

import { describe, expect, it } from 'vitest'
import type { SoakSample } from '../../scripts/soak/verdict.mjs'
import { slopePerHour, soakVerdict } from '../../scripts/soak/verdict.mjs'

const SOAK_HOUR_MS = 3_600_000

/** Una muestra sana: 60 MB de monton, 300 nodos, 40 escuchas, una camara viva que avanza. */
function healthySample(hour: number, overrides: Partial<SoakSample> = {}): SoakSample {
  return {
    elapsedMs: hour * SOAK_HOUR_MS,
    jsHeapUsedBytes: 60_000_000,
    processMemoryBytes: null,
    domNodes: 300,
    eventListeners: 40,
    liveVideoTracks: 1,
    videoTime: hour * 3600,
    visible: true,
    cameraFailureShown: false,
    reloads: 0,
    ...overrides,
  }
}

/** Doce horas y una muestra por hora, todas sanas. */
const SOAK_HEALTHY_RUN: readonly SoakSample[] = [0, 1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 11, 12].map(
  (hour) => healthySample(hour),
)

describe('veredicto de resistencia — una tablet sana pasa (R5-KI-02)', () => {
  it('doce horas estables pasan sin ningun fallo', () => {
    const verdict = soakVerdict(SOAK_HEALTHY_RUN)

    expect(verdict.failures).toEqual([])
    expect(verdict.passed).toBe(true)
  })

  it('sin muestras no pasa: no medir no es pasar', () => {
    expect(soakVerdict([]).passed).toBe(false)
  })
})

describe('veredicto de resistencia — memoria (doc 02, Anexo A)', () => {
  it.each([
    { bytes: 250_000_000, passed: true },
    { bytes: 250_000_001, passed: false },
  ])('una muestra de $bytes bytes: pasa = $passed', ({ bytes, passed }) => {
    const samples = [
      ...SOAK_HEALTHY_RUN.slice(0, 12),
      healthySample(12, { jsHeapUsedBytes: bytes }),
    ]

    expect(
      soakVerdict(samples).failures.some((failure) => failure.startsWith('memoria maxima')),
    ).toBe(!passed)
  })

  it('con la memoria del proceso leida por adb, se juzga esa y no el monton', () => {
    const samples = [healthySample(0, { processMemoryBytes: 260_000_000 })]

    expect(soakVerdict(samples).failures).toEqual(['memoria maxima 260 MB > 250 MB'])
  })

  it('crecer 3 MB por hora tras la primera hora es una fuga, aunque no llegue a 250 MB', () => {
    const leaking = SOAK_HEALTHY_RUN.map((_, hour) =>
      healthySample(hour, { jsHeapUsedBytes: 60_000_000 + hour * 3_000_000 }),
    )

    expect(soakVerdict(leaking).failures).toEqual([
      'la memoria crece 3.0 MB/h tras la primera hora',
    ])
  })

  it('lo que sube en la primera hora (calentamiento) no cuenta como crecimiento', () => {
    const warming = [
      healthySample(0, { jsHeapUsedBytes: 0 }),
      healthySample(0.25, { jsHeapUsedBytes: 15_000_000 }),
      healthySample(0.5, { jsHeapUsedBytes: 30_000_000 }),
      healthySample(0.75, { jsHeapUsedBytes: 45_000_000 }),
      ...SOAK_HEALTHY_RUN.slice(1),
    ]

    expect(soakVerdict(warming).passed).toBe(true)
  })

  it('la pendiente se calcula por minimos cuadrados, en unidades por hora', () => {
    const points = [
      { elapsedMs: 0, value: 10 },
      { elapsedMs: SOAK_HOUR_MS, value: 12 },
      { elapsedMs: 2 * SOAK_HOUR_MS, value: 14 },
    ]

    expect(slopePerHour(points)).toBeCloseTo(2, 9)
  })
})

describe('veredicto de resistencia — DOM y escuchas (doc 03 §4.3)', () => {
  it('101 nodos mas por hora es una fuga del DOM', () => {
    const growing = SOAK_HEALTHY_RUN.map((_, hour) =>
      healthySample(hour, { domNodes: 300 + hour * 101 }),
    )

    expect(soakVerdict(growing).failures).toEqual([
      'los nodos del DOM crecen 101/h tras la primera hora',
    ])
  })

  it.each([
    { spread: 20, passed: true },
    { spread: 21, passed: false },
  ])(
    'las escuchas varian en $spread tras la primera hora: pasa = $passed',
    ({ spread, passed }) => {
      const samples = [
        ...SOAK_HEALTHY_RUN.slice(0, 12),
        healthySample(12, { eventListeners: 40 + spread }),
      ]

      expect(soakVerdict(samples).passed).toBe(passed)
    },
  )
})

describe('veredicto de resistencia — la camara (RF-KI-02)', () => {
  it('dos pistas vivas a la vez son una camara que no se solto', () => {
    const samples = [...SOAK_HEALTHY_RUN.slice(0, 12), healthySample(12, { liveVideoTracks: 2 })]

    expect(soakVerdict(samples).failures).toEqual(['1 muestras con mas de una pista de video viva'])
  })

  it('con la pagina oculta, sin camara abierta es lo correcto', () => {
    const samples = [
      ...SOAK_HEALTHY_RUN.slice(0, 12),
      healthySample(12, { visible: false, liveVideoTracks: 0, videoTime: null }),
    ]

    expect(soakVerdict(samples).passed).toBe(true)
  })

  it('visible y sin camara abierta no pasa', () => {
    const samples = [
      ...SOAK_HEALTHY_RUN.slice(0, 12),
      healthySample(12, { liveVideoTracks: 0, videoTime: null }),
    ]

    expect(soakVerdict(samples).failures).toEqual(['1 muestras visibles sin la camara abierta'])
  })

  it('el aviso de camara en pantalla no pasa', () => {
    const samples = [
      ...SOAK_HEALTHY_RUN.slice(0, 12),
      healthySample(12, { cameraFailureShown: true, liveVideoTracks: 0, videoTime: null }),
    ]

    expect(soakVerdict(samples).failures).toEqual(['1 muestras con el aviso de camara en pantalla'])
  })

  it('un video que no avanza entre dos muestras visibles es una camara congelada', () => {
    const samples = [...SOAK_HEALTHY_RUN.slice(0, 12), healthySample(12, { videoTime: 11 * 3600 })]

    expect(soakVerdict(samples).failures).toEqual(['la camara se quedo congelada en 1 intervalos'])
  })

  it('tras una recarga el video vuelve a empezar y no cuenta como congelado', () => {
    const samples = [
      ...SOAK_HEALTHY_RUN.slice(0, 12),
      healthySample(12, { videoTime: 5, reloads: 1 }),
    ]

    expect(soakVerdict(samples).passed).toBe(true)
  })
})

describe('veredicto de resistencia — recargas (RF-KI-07)', () => {
  it.each([
    { reloads: 3, passed: true },
    { reloads: 4, passed: false },
  ])('$reloads recargas en 12 h: pasa = $passed', ({ reloads, passed }) => {
    const samples = [...SOAK_HEALTHY_RUN.slice(0, 12), healthySample(12, { reloads, videoTime: 5 })]

    expect(soakVerdict(samples).passed).toBe(passed)
  })
})
