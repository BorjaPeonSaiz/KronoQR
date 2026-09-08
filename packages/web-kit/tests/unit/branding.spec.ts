// Marca blanca en tiempo de ejecucion (RF-PD-08, tarea 5.8): el tema se
// aplica desde la configuracion recibida y, sin configuracion, rige el
// producto. Los tokens base se leen de theme.css, igual que en theme.spec.ts:
// asi la prueba mide contra la paleta real y no contra una copia.

import { readFileSync } from 'node:fs'
import { dirname, resolve } from 'node:path'
import { fileURLToPath } from 'node:url'
import { describe, expect, it } from 'vitest'
import {
  accentOverrides,
  applyBranding,
  contrastWarnings,
  MANAGED_TOKENS,
  offeredLocales,
  parseBranding,
  PRODUCT_BRANDING,
  type Branding,
} from '../../src/branding'
import { contrastRatio, WCAG_AA_MINIMUM } from '../../src/contrast'

const themeCss = readFileSync(
  resolve(dirname(fileURLToPath(import.meta.url)), '../../src/theme.css'),
  'utf8',
)

function rootTokens(): Map<string, string> {
  const css = themeCss.replace(/\/\*[\s\S]*?\*\//g, '')
  const start = css.search(/:root\s*\{/)
  const body = css.slice(css.indexOf('{', start) + 1, css.indexOf('}', start))
  const out = new Map<string, string>()
  for (const match of body.matchAll(/(--[\w-]+)\s*:\s*([^;]+);/g)) {
    out.set(match[1] ?? '', (match[2] ?? '').trim())
  }
  return out
}

const tokens = rootTokens()
const themeToken = (token: string): string => {
  const value = tokens.get(token)
  if (value === undefined) throw new Error(`Token ${token} not in theme.css`)
  return value
}

const PAYLOAD = {
  application_name: 'Hotel Marina',
  accent_color: '#0F5C8C',
  logo_url: '/api/v1/branding/logo?v=3f9a1c2b7e4d',
  locales: { default: 'es', available: ['es'] },
}

describe('parseBranding (RF-PD-08)', () => {
  it('traduce la respuesta del contrato y normaliza el color a minusculas', () => {
    expect(parseBranding(PAYLOAD)).toEqual<Branding>({
      applicationName: 'Hotel Marina',
      accentColor: '#0f5c8c',
      logoUrl: '/api/v1/branding/logo?v=3f9a1c2b7e4d',
      locales: { default: 'es', available: ['es'] },
    })
  })

  it('acepta la marca del producto: sin color y sin logotipo', () => {
    expect(
      parseBranding({
        application_name: 'KronoQR',
        accent_color: null,
        logo_url: null,
        locales: { default: 'es', available: ['es', 'en'] },
      }),
    ).toEqual(PRODUCT_BRANDING)
  })

  it.each([
    ['sin nombre', { ...PAYLOAD, application_name: '' }],
    ['color que no es #rrggbb', { ...PAYLOAD, accent_color: 'azul' }],
    ['logotipo vacio', { ...PAYLOAD, logo_url: '' }],
    ['logotipo de otro origen', { ...PAYLOAD, logo_url: 'https://evil.example/pixel.png' }],
    ['logotipo con esquema de datos', { ...PAYLOAD, logo_url: 'data:image/png;base64,AAAA' }],
    ['logotipo fuera de la ruta del contrato', { ...PAYLOAD, logo_url: '/storage/logo.png' }],
    ['sin idiomas', { ...PAYLOAD, locales: { default: 'es', available: [] } }],
    ['idioma por defecto vacio', { ...PAYLOAD, locales: { default: '', available: ['es'] } }],
    ['no es un objeto', 'KronoQR'],
    ['nulo', null],
  ])('rechaza una respuesta %s sin lanzar', (_case, value) => {
    expect(parseBranding(value)).toBeNull()
  })
})

describe('accentOverrides: los tonos derivados se leen', () => {
  const accents = ['#0f5c8c', '#b8542a', '#111827', '#f5e663', '#8b0000', '#7fd1ae', '#ffffff']

  it.each(accents)('%s: el texto sobre el acento del panel alcanza 4,5:1', (accent) => {
    const overrides = accentOverrides(accent, 'light', themeToken)

    expect(overrides['--kq-color-primary-strong']).toBe(accent)
    expect(
      contrastRatio(
        overrides['--kq-color-on-primary'] ?? '',
        overrides['--kq-color-primary-strong'] ?? '',
      ),
    ).toBeGreaterThanOrEqual(WCAG_AA_MINIMUM.text)
    expect(
      contrastRatio(
        overrides['--kq-color-on-primary-soft'] ?? '',
        overrides['--kq-color-primary-soft'] ?? '',
      ),
    ).toBeGreaterThanOrEqual(WCAG_AA_MINIMUM.text)
    // El anillo de foco se ve siempre, tambien con un acento casi blanco
    // (WCAG 2.4.11): no es marca, es saber donde esta el teclado.
    expect(
      contrastRatio(overrides['--kq-color-focus'] ?? '', themeToken('--kq-color-surface-raised')),
    ).toBeGreaterThanOrEqual(WCAG_AA_MINIMUM.large)
  })

  it.each(accents)('%s: el boton solido del quiosco se lee sobre el fondo oscuro', (accent) => {
    const overrides = accentOverrides(accent, 'kiosk', themeToken)
    const strong = overrides['--kq-color-kiosk-primary-strong'] ?? ''

    // El acento grande se aclara hasta 3:1 sobre el panel, como el del producto.
    expect(
      contrastRatio(
        overrides['--kq-color-kiosk-primary'] ?? '',
        themeToken('--kq-color-kiosk-surface-raised'),
      ),
    ).toBeGreaterThanOrEqual(WCAG_AA_MINIMUM.large)
    expect(
      contrastRatio(strong, themeToken('--kq-color-kiosk-surface-raised')),
    ).toBeGreaterThanOrEqual(WCAG_AA_MINIMUM.text)
    expect(contrastRatio(strong, themeToken('--kq-color-kiosk-surface'))).toBeGreaterThanOrEqual(
      WCAG_AA_MINIMUM.text,
    )
    expect(
      contrastRatio(overrides['--kq-color-kiosk-on-primary'] ?? '', strong),
    ).toBeGreaterThanOrEqual(WCAG_AA_MINIMUM.text)
  })

  it('solo toca los tokens gestionados de cada contexto', () => {
    expect(Object.keys(accentOverrides('#0f5c8c', 'light', themeToken)).sort()).toEqual(
      [...MANAGED_TOKENS.light].sort(),
    )
    expect(Object.keys(accentOverrides('#0f5c8c', 'kiosk', themeToken)).sort()).toEqual(
      [...MANAGED_TOKENS.kiosk].sort(),
    )
  })

  it('todo token gestionado existe en theme.css', () => {
    for (const token of [...MANAGED_TOKENS.light, ...MANAGED_TOKENS.kiosk]) {
      expect(tokens.has(token), token).toBe(true)
    }
  })
})

describe('contrastWarnings: se avisa, no se impone (doc 06 §7)', () => {
  it('el acento del producto no produce ningun aviso', () => {
    expect(contrastWarnings(themeToken('--kq-color-primary-strong'), themeToken)).toEqual([])
  })

  it('un acento bien contrastado tampoco', () => {
    expect(contrastWarnings('#0f5c8c', themeToken)).toEqual([])
  })

  it('un amarillo palido avisa de que no sirve como acento grande sobre las superficies claras', () => {
    const warnings = contrastWarnings('#f5e663', themeToken, 'light')

    expect(warnings.length).toBeGreaterThan(0)
    // Lo que falla es siempre el acento como PRIMER PLANO sobre las superficies
    // claras del producto (grande como `primary`, texto como `primary-strong`, anillo de foco):
    // los tonos derivados —texto sobre el acento, fondo tintado— siempre llegan.
    for (const warning of warnings) {
      expect(warning.ratio).toBeLessThan(warning.minimum)
      expect(MANAGED_TOKENS.light).toContain(warning.pair.foreground)
    }
  })

  it('evalua un solo contexto cuando se le pide', () => {
    const all = contrastWarnings('#f5e663', themeToken, 'all')
    const light = contrastWarnings('#f5e663', themeToken, 'light')
    const kiosk = contrastWarnings('#f5e663', themeToken, 'kiosk')

    expect(all).toHaveLength(light.length + kiosk.length)
  })
})

describe('applyBranding sobre el documento', () => {
  const branded: Branding = {
    applicationName: 'Hotel Marina',
    accentColor: '#0f5c8c',
    logoUrl: '/api/v1/branding/logo?v=3f9a1c2b7e4d',
    locales: { default: 'es', available: ['es'] },
  }

  it('aplica el acento, el titulo y el favicon del cliente', () => {
    applyBranding(branded, { mode: 'light', document, tokens: themeToken })

    const root = document.documentElement
    expect(root.style.getPropertyValue('--kq-color-primary-strong')).toBe('#0f5c8c')
    expect(root.style.getPropertyValue('--kq-color-on-primary')).not.toBe('')
    expect(root.getAttribute('data-kq-branded')).toBe('accent')
    expect(document.title).toBe('Hotel Marina')
    expect(document.head.querySelector('link[rel="icon"]')?.getAttribute('href')).toBe(
      branded.logoUrl,
    )
  })

  it('con la marca del producto retira todo lo anterior: el tema vuelve a ser el de serie', () => {
    applyBranding(branded, { mode: 'light', document, tokens: themeToken })
    applyBranding(PRODUCT_BRANDING, { mode: 'light', document, tokens: themeToken })

    const root = document.documentElement
    for (const token of MANAGED_TOKENS.light) {
      expect(root.style.getPropertyValue(token), token).toBe('')
    }
    expect(root.getAttribute('data-kq-branded')).toBe('product')
    expect(document.title).toBe('KronoQR')
    expect(document.head.querySelector('link[rel="icon"]')).toBeNull()
  })

  it('no toca los tokens del otro contexto', () => {
    applyBranding(branded, { mode: 'kiosk', document, tokens: themeToken })

    const root = document.documentElement
    expect(root.style.getPropertyValue('--kq-color-kiosk-primary')).not.toBe('')
    for (const token of MANAGED_TOKENS.light) {
      expect(root.style.getPropertyValue(token), token).toBe('')
    }
    applyBranding(PRODUCT_BRANDING, { mode: 'kiosk', document, tokens: themeToken })
  })

  it('un acento corrupto se trata como ausencia de acento, sin lanzar', () => {
    const corrupt = { ...branded, accentColor: 'rojo' }

    expect(() =>
      applyBranding(corrupt, { mode: 'light', document, tokens: themeToken }),
    ).not.toThrow()
    expect(document.documentElement.getAttribute('data-kq-branded')).toBe('product')
    expect(document.title).toBe('Hotel Marina')
    applyBranding(PRODUCT_BRANDING, { mode: 'light', document, tokens: themeToken })
  })
})

describe('offeredLocales', () => {
  const shipped = ['es', 'en'] as const

  it('ofrece los idiomas de la instalacion que la aplicacion trae traducidos, en su orden', () => {
    expect(offeredLocales({ default: 'en', available: ['en', 'es'] }, shipped)).toEqual([
      'es',
      'en',
    ])
    expect(offeredLocales({ default: 'es', available: ['es'] }, shipped)).toEqual(['es'])
  })

  it('si ninguno esta traducido, ofrece todos los traducidos: nunca cero idiomas', () => {
    expect(offeredLocales({ default: 'ro', available: ['ro'] }, shipped)).toEqual(['es', 'en'])
  })
})
