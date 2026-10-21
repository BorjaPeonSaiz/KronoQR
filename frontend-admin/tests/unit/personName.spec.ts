// Nombre visible de una persona (ADR-060): una ficha minimizada no se pinta como un hueco.
import { describe, expect, it } from 'vitest'
import { isMinimisedPerson, visibleFullName, visibleName } from '@/shared/people/personName'

const PLACEHOLDER = 'Persona minimizada'

describe('personName', () => {
  it('compone nombre y apellidos cuando hay ficha', () => {
    expect(visibleName('Youssef', 'Amrani', PLACEHOLDER)).toBe('Youssef Amrani')
    expect(isMinimisedPerson('Youssef', 'Amrani')).toBe(false)
  })

  it('sustituye una ficha sin nombre ni apellidos, tambien si solo hay espacios', () => {
    expect(visibleName('', '', PLACEHOLDER)).toBe(PLACEHOLDER)
    expect(visibleName(' ', '  ', PLACEHOLDER)).toBe(PLACEHOLDER)
    expect(isMinimisedPerson('', '')).toBe(true)
  })

  it('no sustituye si falta solo una de las dos partes', () => {
    expect(visibleName('Youssef', '', PLACEHOLDER)).toBe('Youssef')
    expect(isMinimisedPerson('', 'Amrani')).toBe(false)
  })

  it('sustituye un full_name vacio o ausente y respeta el que existe', () => {
    expect(visibleFullName('', PLACEHOLDER)).toBe(PLACEHOLDER)
    expect(visibleFullName(null, PLACEHOLDER)).toBe(PLACEHOLDER)
    expect(visibleFullName(undefined, PLACEHOLDER)).toBe(PLACEHOLDER)
    expect(visibleFullName('Youssef Amrani', PLACEHOLDER)).toBe('Youssef Amrani')
  })
})

describe('personName con anonymized_at', () => {
  it('manda anonymized_at cuando el recurso lo trae', () => {
    expect(isMinimisedPerson('', '', null)).toBe(false)
    expect(isMinimisedPerson('Youssef', 'Amrani', '2026-10-01T08:30:00.000000Z')).toBe(true)
    expect(visibleName('', '', PLACEHOLDER, '2026-10-01T08:30:00.000000Z')).toBe(PLACEHOLDER)
  })
})
