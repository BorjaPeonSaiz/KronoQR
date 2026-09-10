import { afterEach, describe, expect, it, vi } from 'vitest'
import { createTraceparent } from '../../src/traceparent'

const TRACEPARENT_PATTERN = /^00-[0-9a-f]{32}-[0-9a-f]{16}-01$/

afterEach(() => {
  vi.restoreAllMocks()
})

describe('createTraceparent', () => {
  it('tiene el formato W3C: version, trace-id de 32 hex, parent-id de 16 hex y flags', () => {
    const traceparent = createTraceparent()

    expect(traceparent).toMatch(TRACEPARENT_PATTERN)
  })

  it('el trace-id y el parent-id tienen la longitud exacta que manda la especificacion', () => {
    const [version, traceId, parentId, flags] = createTraceparent().split('-')

    expect(version).toBe('00')
    expect(traceId).toHaveLength(32)
    expect(parentId).toHaveLength(16)
    expect(flags).toBe('01')
  })

  it('nunca genera un trace-id ni un parent-id a todo ceros', () => {
    // Se fuerza la primera tirada de cada identificador a cero: la funcion
    // tiene que detectarlo y volver a pedir bytes, no aceptar el cero.
    let call = 0
    vi.spyOn(crypto, 'getRandomValues').mockImplementation(((array: Uint8Array) => {
      call += 1
      // Las dos primeras llamadas (trace-id y parent-id) salen a cero; a
      // partir de la tercera, valores reales para poder terminar.
      if (call > 2) {
        for (let i = 0; i < array.length; i += 1) {
          array[i] = (i + call) % 256
        }
      }
      return array
    }) as typeof crypto.getRandomValues)

    const traceparent = createTraceparent()
    const [, traceId, parentId] = traceparent.split('-')

    expect(traceId).not.toMatch(/^0+$/)
    expect(parentId).not.toMatch(/^0+$/)
    expect(call).toBeGreaterThan(2)
  })

  it('genera un identificador distinto en cada llamada', () => {
    const seen = new Set<string>()

    for (let i = 0; i < 200; i += 1) {
      seen.add(createTraceparent())
    }

    expect(seen.size).toBe(200)
  })
})
