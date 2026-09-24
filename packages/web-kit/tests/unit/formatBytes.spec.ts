// Cubre RF-IN-06 (exportaciones de informes) y RF-PD-05 (paquete de
// exportacion de datos): las dos pantallas que compartian esta funcion byte a
// byte antes de moverse aqui (ADR-036).
import { describe, expect, it } from 'vitest'
import { formatBytes } from '../../src/formatBytes'

describe('tamaño de fichero en unidades binarias', () => {
  it('0 bytes se enseñan sin decimales', () => {
    expect(formatBytes(0, 'es')).toBe('0 B')
  })

  it('1023 bytes siguen en B: el corte a KiB es en 1024, no antes', () => {
    expect(formatBytes(1023, 'es')).toBe('1023 B')
  })

  it('1024 bytes son 1 KiB con un decimal', () => {
    expect(formatBytes(1024, 'es')).toBe('1,0 KiB')
    expect(formatBytes(1024, 'en')).toBe('1.0 KiB')
  })

  it('1 MiB exacto', () => {
    expect(formatBytes(1024 * 1024, 'es')).toBe('1,0 MiB')
    expect(formatBytes(1024 * 1024, 'en')).toBe('1.0 MiB')
  })

  it('1 TiB exacto, y no sigue subiendo de unidad', () => {
    const oneTiB = 1024 * 1024 * 1024 * 1024

    expect(formatBytes(oneTiB, 'es')).toBe('1,0 TiB')
    expect(formatBytes(oneTiB, 'en')).toBe('1.0 TiB')
  })

  it('por encima de un TiB se queda en TiB: no hay una unidad mayor en el catalogo', () => {
    expect(formatBytes(1024 * 1024 * 1024 * 1024 * 5, 'en')).toBe('5.0 TiB')
  })

  it('el separador decimal depende del locale que decide quien llama, nunca del navegador', () => {
    expect(formatBytes(1536, 'es')).toBe('1,5 KiB')
    expect(formatBytes(1536, 'en')).toBe('1.5 KiB')
  })
})
