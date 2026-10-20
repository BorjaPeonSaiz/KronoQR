// La URL de la politica de privacidad, lista para un enlace o un QR: una sola
// funcion para el quiosco y el portal.
import { describe, expect, it } from 'vitest'
import { safePolicyUrl } from '../../src/branding'

describe('safePolicyUrl', () => {
  it('deja pasar una URL https normal', () => {
    expect(safePolicyUrl('https://hotel.example/privacidad?x=1#a')).toBe(
      'https://hotel.example/privacidad?x=1#a',
    )
  })

  it.each([
    ['http', 'http://hotel.example/p'],
    ['javascript', 'javascript:alert(1)'],
    ['con espacio', 'https://hotel.example/mi politica'],
    ['con tabulador', 'https://hotel.example/\tp'],
    ['con salto de linea', 'https://hotel.example/\np'],
    ['con barra invertida', 'https://hotel.example\\@evil.example/'],
    ['con caracteres no ASCII', 'https://hotel.example/política'],
    ['vacia', ''],
    ['sin esquema', '//hotel.example/p'],
  ])('rechaza una URL %s', (_name, url) => {
    expect(safePolicyUrl(url)).toBeNull()
  })

  it('rechaza lo que no es texto', () => {
    expect(safePolicyUrl(null)).toBeNull()
    expect(safePolicyUrl(undefined)).toBeNull()
  })
})
