// La formula de contraste se comprueba contra valores conocidos y publicados:
// si se equivoca, la prueba de `theme.css` que la usa daria un verde falso.
import { describe, expect, it } from 'vitest'
import {
  contrastRatio,
  meetsWcagAa,
  parseHexColor,
  relativeLuminance,
  WCAG_AA_MINIMUM,
} from '../../src/contrast'

describe('parseHexColor', () => {
  it('lee #rrggbb y #rgb, con o sin mayusculas', () => {
    expect(parseHexColor('#d66c3a')).toEqual({ r: 214, g: 108, b: 58 })
    expect(parseHexColor('#FFF')).toEqual({ r: 255, g: 255, b: 255 })
    expect(parseHexColor(' #000 ')).toEqual({ r: 0, g: 0, b: 0 })
  })

  it('rechaza lo que no es un color hexadecimal', () => {
    expect(() => parseHexColor('rgb(0, 0, 0)')).toThrow(/hexadecimal/)
    expect(() => parseHexColor('#12345')).toThrow(/hexadecimal/)
    expect(() => parseHexColor('d66c3a')).toThrow(/hexadecimal/)
  })
})

describe('relativeLuminance', () => {
  it('va de 0 en negro a 1 en blanco', () => {
    expect(relativeLuminance('#000000')).toBe(0)
    expect(relativeLuminance('#ffffff')).toBeCloseTo(1, 10)
  })

  it('pondera los canales como la recomendacion: el verde pesa mas que el rojo y este mas que el azul', () => {
    expect(relativeLuminance('#00ff00')).toBeCloseTo(0.7152, 4)
    expect(relativeLuminance('#ff0000')).toBeCloseTo(0.2126, 4)
    expect(relativeLuminance('#0000ff')).toBeCloseTo(0.0722, 4)
  })
})

describe('contrastRatio', () => {
  it('negro sobre blanco es 21:1 y un color sobre si mismo 1:1', () => {
    expect(contrastRatio('#000000', '#ffffff')).toBeCloseTo(21, 5)
    expect(contrastRatio('#d66c3a', '#d66c3a')).toBe(1)
  })

  it('es simetrico', () => {
    expect(contrastRatio('#3a2e28', '#fff7ed')).toBeCloseTo(contrastRatio('#fff7ed', '#3a2e28'), 10)
  })

  it('reproduce valores publicados: #767676 sobre blanco pasa AA y #777777 no', () => {
    expect(contrastRatio('#767676', '#ffffff')).toBeCloseTo(4.54, 2)
    expect(contrastRatio('#777777', '#ffffff')).toBeCloseTo(4.48, 2)
  })

  it('acepta objetos Rgb ademas de cadenas', () => {
    expect(contrastRatio({ r: 0, g: 0, b: 0 }, { r: 255, g: 255, b: 255 })).toBeCloseTo(21, 5)
  })
})

describe('meetsWcagAa', () => {
  it('exige 4.5:1 al texto normal y 3:1 al grande o a un componente', () => {
    expect(WCAG_AA_MINIMUM.text).toBe(4.5)
    expect(WCAG_AA_MINIMUM.large).toBe(3)

    // Blanco sobre el terracota claro del fabricante: 3.46:1.
    expect(meetsWcagAa('#ffffff', '#d66c3a', 'text')).toBe(false)
    expect(meetsWcagAa('#ffffff', '#d66c3a', 'large')).toBe(true)
    // Y sobre el terracota oscuro: 4.84:1.
    expect(meetsWcagAa('#ffffff', '#b8542a', 'text')).toBe(true)
  })

  it('lo decorativo no exige nada', () => {
    expect(meetsWcagAa('#fff7ed', '#fbeee0', 'decorative')).toBe(true)
  })
})
