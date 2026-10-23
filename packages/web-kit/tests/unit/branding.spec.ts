// Marca blanca en tiempo de ejecucion (RF-PD-08, tarea 5.8): el tema se
// aplica desde la configuracion recibida y, sin configuracion, rige el
// producto. Los tokens base se leen de theme.css, igual que en theme.spec.ts:
// asi la prueba mide contra la paleta real y no contra una copia.

import { readFileSync } from 'node:fs'
import { dirname, resolve } from 'node:path'
import { fileURLToPath } from 'node:url'
import { describe, expect, it } from 'vitest'
import {
  ACCENT_ADJUST_STEPS,
  accentContrast,
  accentTextOn,
  accentOverrides,
  applyBranding,
  contrastWarnings,
  MANAGED_TOKENS,
  offeredLocales,
  parseBranding,
  PRODUCT_BRANDING,
  resolveUiLocale,
  type Branding,
} from '../../src/branding'
import { paintedColors } from '../../src/pairContrast'
import { contrastRatio, WCAG_AA_MINIMUM } from '../../src/contrast'
import { kioskPairs, lightPairs } from '../../src/themePairs'

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
      privacyNotice: { controllerName: null, policyUrl: null, retentionYears: null },
    })
  })

  it('de serie solo ofrece espanol (LOCALE_AVAILABLE = [es], RF-KI-05)', () => {
    expect(PRODUCT_BRANDING.locales).toEqual({ default: 'es', available: ['es'] })
  })

  it('acepta la marca del producto: sin color y sin logotipo', () => {
    expect(
      parseBranding({
        application_name: 'KronoQR',
        accent_color: null,
        logo_url: null,
        locales: { default: 'es', available: ['es'] },
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

describe('parseBranding: aviso de privacidad (RF-KI-09, RL-09)', () => {
  const NOTICE = {
    controller_name: 'Hotel Marina S.L.',
    policy_url: 'https://marina.example/privacidad',
  }

  it('una copia anterior a la 2.2.0, sin privacy_notice, es «sin configurar»', () => {
    expect(parseBranding(PAYLOAD)?.privacyNotice).toEqual({
      controllerName: null,
      policyUrl: null,
      retentionYears: null,
    })
  })

  it('lee el responsable y la politica', () => {
    expect(parseBranding({ ...PAYLOAD, privacy_notice: NOTICE })?.privacyNotice).toEqual({
      controllerName: 'Hotel Marina S.L.',
      policyUrl: 'https://marina.example/privacidad',
      retentionYears: null,
    })
  })

  it('los dos campos a null son «sin configurar», no un error', () => {
    const parsed = parseBranding({
      ...PAYLOAD,
      privacy_notice: { controller_name: null, policy_url: null },
    })
    expect(parsed?.privacyNotice).toEqual({
      controllerName: null,
      policyUrl: null,
      retentionYears: null,
    })
  })

  it.each([
    ['javascript:', 'javascript:alert(1)'],
    ['data:', 'data:text/html,<script>1</script>'],
    ['ftp:', 'ftp://marina.example/privacidad'],
    ['sin host', 'https://'],
    ['con espacios', 'https://marina.example/a b'],
    ['http (sin TLS)', 'http://marina.example/privacidad'],
    ['con userinfo', 'https://marina.example@evil.example/p'],
    ['con barra invertida', 'https://marina.example\@evil.example/p'],
    ['con caracteres no ASCII', 'https://marina.example/privacidad/mariña'],
    ['de mas de 512 caracteres', 'https://marina.example/' + 'a'.repeat(512)],
    ['no es una cadena', 42],
  ])('una URL %s se descarta (sin enlace), pero la marca sigue valiendo', (_case, url) => {
    const parsed = parseBranding({
      ...PAYLOAD,
      privacy_notice: { controller_name: 'Hotel Marina S.L.', policy_url: url },
    })
    expect(parsed).not.toBeNull()
    expect(parsed?.privacyNotice.policyUrl).toBeNull()
    expect(parsed?.privacyNotice.controllerName).toBe('Hotel Marina S.L.')
  })

  it.each([
    ['vacio', '   '],
    ['con caracter de formato (ancho cero)', 'Hotel' + String.fromCharCode(0x200b) + 'Marina'],
    ['con salto de linea', 'Hotel' + String.fromCharCode(10) + 'Marina'],
    ['de mas de 160 caracteres', 'x'.repeat(161)],
    ['no es una cadena', { name: 'x' }],
  ])('un responsable %s se descarta', (_case, name) => {
    const parsed = parseBranding({
      ...PAYLOAD,
      privacy_notice: { controller_name: name, policy_url: null },
    })
    expect(parsed?.privacyNotice.controllerName).toBeNull()
  })

  it.each([
    [5, 5],
    [1, 1],
    [0, null],
    [-3, null],
    [2.5, null],
    ['4', null],
    [null, null],
    [1000, null],
  ])('retention_years %j se lee como %j', (raw, expected) => {
    const parsed = parseBranding({
      ...PAYLOAD,
      privacy_notice: { controller_name: null, policy_url: null, retention_years: raw },
    })
    expect(parsed?.privacyNotice.retentionYears).toBe(expected)
  })

  it('un privacy_notice con la forma rota (no es un objeto) es «sin configurar»', () => {
    expect(parseBranding({ ...PAYLOAD, privacy_notice: 'x' })?.privacyNotice).toEqual({
      controllerName: null,
      policyUrl: null,
      retentionYears: null,
    })
  })
})

// Superficies casi negras: ni el negro llega a 4,5:1 sobre ellas.
const darkSurfaces = (token: string): string =>
  token.includes('surface') && !token.includes('kiosk') ? '#050505' : themeToken(token)

// Acentos reales que un cliente puede elegir: claros (amarillo, verde lima, el
// blanco de partida), medios, oscuros y el de serie. Tras la derivacion, TODA
// pareja declarada en `themePairs` donde interviene el acento llega a su
// minimo (MB1): las parejas salen de la tabla, no de una lista escrita a mano.
const REAL_ACCENTS: ReadonlyArray<readonly [string, string]> = [
  ['amarillo', '#ffe14d'],
  ['amarillo palido', '#f5e663'],
  ['verde lima', '#a4de02'],
  ['verde menta', '#7fd1ae'],
  ['naranja', '#ff9900'],
  ['gris medio', '#808080'],
  ['rojo vivo', '#ff0000'],
  ['casi blanco', '#fafafa'],
  ['blanco', '#ffffff'],
  ['azul corporativo', '#0f5c8c'],
  ['granate', '#8b0000'],
  ['azul noche', '#111827'],
  ['negro', '#000000'],
  ['el de serie', '#b8542a'],
]

const LIGHT_SURFACES = [
  '--kq-color-surface',
  '--kq-color-surface-raised',
  '--kq-color-surface-alt',
] as const

describe('accentOverrides: toda pareja declarada cumple tras la derivacion (MB1)', () => {
  it.each(REAL_ACCENTS)('%s (%s): las parejas del panel y del quiosco llegan', (_name, accent) => {
    for (const [mode, pairs] of [
      ['light', lightPairs],
      ['kiosk', kioskPairs],
    ] as const) {
      const overrides = accentOverrides(accent, mode, themeToken)
      const resolve = (token: string): string => overrides[token] ?? themeToken(token)

      for (const pair of pairs) {
        if (!(pair.foreground in overrides) && !(pair.background in overrides)) continue
        expect(
          contrastRatio(resolve(pair.foreground), resolve(pair.background)),
          `${mode}: ${pair.use}`,
        ).toBeGreaterThanOrEqual(WCAG_AA_MINIMUM[pair.requirement])
      }
    }
  })

  it.each(REAL_ACCENTS)('%s (%s): primary y primary-strong se leen como texto', (_name, accent) => {
    const overrides = accentOverrides(accent, 'light', themeToken)

    for (const token of ['--kq-color-primary', '--kq-color-primary-strong']) {
      for (const surface of LIGHT_SURFACES) {
        // Texto sobre pagina y tarjeta; solo elemento grande (3:1) sobre filas alternas.
        const minimum =
          surface === '--kq-color-surface-alt' ? WCAG_AA_MINIMUM.large : WCAG_AA_MINIMUM.text
        expect(
          contrastRatio(overrides[token] ?? '', themeToken(surface)),
          `${token} sobre ${surface}`,
        ).toBeGreaterThanOrEqual(minimum)
      }
    }
  })

  it('un acento que ya contrasta no se toca', () => {
    for (const accent of ['#a8461f', '#0f5c8c', '#111827']) {
      const overrides = accentOverrides(accent, 'light', themeToken)

      expect(overrides['--kq-color-primary-strong']).toBe(accent)
      expect(overrides['--kq-color-primary']).toBe(accent)
    }
  })

  it('un acento claro se oscurece conservando el matiz: el amarillo sigue siendo calido', () => {
    const strong =
      accentOverrides('#ffe14d', 'light', themeToken)['--kq-color-primary-strong'] ?? ''

    expect(strong).not.toBe('#ffe14d')
    expect(parseInt(strong.slice(1, 3), 16)).toBeGreaterThan(parseInt(strong.slice(5, 7), 16))
  })
})

// R6-UX-02: el candidato se redondea a 8 bits ANTES de medirlo. Medido en coma flotante y
// redondeado despues, 240 de 4512 acentos dejaban alguna pareja entre 2,98 y 4,48:1.
// Estos son los peores de ese barrido, y una rejilla que recorre todo el espacio.
const ROUNDING_WORST_ACCENTS = [
  '#d1dce0',
  '#b3ff80',
  '#c653c6',
  '#b8b494',
  '#144508',
  '#5d90e0',
  '#165d65',
  '#956d04',
  '#56f792',
] as const

function failingPairs(accent: string): string[] {
  const failures: string[] = []
  for (const [mode, pairs] of [
    ['light', lightPairs],
    ['kiosk', kioskPairs],
  ] as const) {
    const overrides = accentOverrides(accent, mode, themeToken)
    const resolve = (token: string): string => overrides[token] ?? themeToken(token)
    for (const item of pairs) {
      if (!(item.foreground in overrides) && !(item.background in overrides)) continue
      const painted = paintedColors(item.foreground, item.background, item.state, resolve)
      const ratio = contrastRatio(painted.foreground, painted.background)
      if (ratio < WCAG_AA_MINIMUM[item.requirement]) {
        failures.push(`${accent} ${mode}: ${item.use} ${ratio.toFixed(3)}`)
      }
    }
  }
  return failures
}

describe('accentOverrides: el valor que se mide es el que se pinta (R6-UX-02)', () => {
  it.each(ROUNDING_WORST_ACCENTS)(
    '%s: ninguna pareja baja de su minimo tras redondear',
    (accent) => {
      expect(failingPairs(accent)).toEqual([])
    },
  )

  it('cada token escrito es un #rrggbb de 8 bits y la derivacion es estable', () => {
    for (const accent of ROUNDING_WORST_ACCENTS) {
      for (const mode of ['light', 'kiosk'] as const) {
        const overrides = accentOverrides(accent, mode, themeToken)
        for (const value of Object.values(overrides)) expect(value).toMatch(/^#[0-9a-f]{6}$/)
        // Medir el valor escrito da lo mismo que medir el candidato: no hay segundo redondeo.
        expect(accentOverrides(accent, mode, themeToken)).toEqual(overrides)
      }
    }
  })

  it('una rejilla de 1 331 acentos del espacio RGB no deja ninguna pareja bajo su minimo', () => {
    const steps = [0, 26, 51, 77, 102, 128, 153, 179, 204, 230, 255]
    const failures: string[] = []
    for (const r of steps) {
      for (const g of steps) {
        for (const b of steps) {
          const hex = `#${[r, g, b].map((v) => v.toString(16).padStart(2, '0')).join('')}`
          failures.push(...failingPairs(hex))
        }
      }
    }

    expect(failures).toEqual([])
  })
})

// Los mismos casos que el backend (`AccentTextOnWhiteFixtureTest`): los dos lados del
// ajuste «acento oscurecido hasta 4,5:1 sobre blanco» tienen que dar el mismo tono.
interface AccentTextCase {
  readonly label: string
  readonly accent: string
  readonly text: string
}
const accentTextFixture = JSON.parse(
  readFileSync(
    resolve(
      dirname(fileURLToPath(import.meta.url)),
      '../../../../backend/tests/Fixtures/accent-text-on-white.json',
    ),
    'utf8',
  ),
) as {
  readonly background: string
  readonly minimum: number
  readonly steps: number
  readonly cases: AccentTextCase[]
}

describe('accentTextOn: el mismo tono que el backend (accentForTextOnWhite)', () => {
  it('usa los mismos pasos de busqueda que el fixture compartido', () => {
    expect(ACCENT_ADJUST_STEPS).toBe(accentTextFixture.steps)
  })

  it.each(accentTextFixture.cases.map((item) => [item.label, item.accent, item.text] as const))(
    '%s: %s -> %s',
    (_label, accent, expected) => {
      expect(accentTextOn(accent, accentTextFixture.background, accentTextFixture.minimum)).toBe(
        expected,
      )
    },
  )

  it('el tono resultante llega al minimo medido sobre el valor ya redondeado', () => {
    for (const item of accentTextFixture.cases) {
      expect(
        contrastRatio(item.text, accentTextFixture.background),
        item.label,
      ).toBeGreaterThanOrEqual(accentTextFixture.minimum)
    }
  })
})

describe('accentContrast: lo que enseña el panel al elegir el color (MB2)', () => {
  it('el acento de serie cumple; se aplica un punto mas oscuro para leerse tambien sobre las filas alternas', () => {
    const result = accentContrast('#b8542a', themeToken)

    expect(result.meets).toBe(true)
    expect(result.minimum).toBe(WCAG_AA_MINIMUM.text)
    expect(result.applied).not.toBe('#b8542a')
    // Es el primary-strong de serie de theme.css, a un punto de distancia como mucho.
    expect(contrastRatio(result.applied, themeToken('--kq-color-primary-strong'))).toBeLessThan(
      1.05,
    )
    expect(
      contrastRatio(result.applied, themeToken('--kq-color-surface-alt')),
    ).toBeGreaterThanOrEqual(WCAG_AA_MINIMUM.text)
  })

  it('un amarillo no cumple y dice con que tono se pintara', () => {
    const result = accentContrast('#ffe14d', themeToken)

    expect(result.meets).toBe(false)
    expect(result.ratio).toBeLessThan(2)
    expect(result.applied).not.toBe('#ffe14d')
    expect(contrastRatio(result.applied, themeToken('--kq-color-surface'))).toBeGreaterThanOrEqual(
      WCAG_AA_MINIMUM.text,
    )
  })

  it('mide contra la peor de las superficies de texto', () => {
    const worst = Math.min(
      ...LIGHT_SURFACES.slice(0, 2).map((token) => contrastRatio('#c0602f', themeToken(token))),
    )

    expect(accentContrast('#c0602f', themeToken).ratio).toBeCloseTo(worst, 10)
  })
})

describe('accentOverrides: los tonos derivados se leen', () => {
  const accents = ['#0f5c8c', '#b8542a', '#111827', '#f5e663', '#8b0000', '#7fd1ae', '#ffffff']

  it.each(accents)('%s: el texto sobre el acento del panel alcanza 4,5:1', (accent) => {
    const overrides = accentOverrides(accent, 'light', themeToken)

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

  it('un amarillo palido ya no deja ninguna pareja por debajo: la derivacion lo corrige (MB1)', () => {
    expect(contrastWarnings('#f5e663', themeToken)).toEqual([])
  })

  it('con una paleta base que ni el negro salva, el aviso nombra la pareja', () => {
    const warnings = contrastWarnings('#f5e663', darkSurfaces, 'light')

    expect(warnings.length).toBeGreaterThan(0)
    for (const warning of warnings) {
      expect(warning.ratio).toBeLessThan(warning.minimum)
    }
  })

  it('evalua un solo contexto cuando se le pide', () => {
    const all = contrastWarnings('#f5e663', darkSurfaces, 'all')
    const light = contrastWarnings('#f5e663', darkSurfaces, 'light')
    const kiosk = contrastWarnings('#f5e663', darkSurfaces, 'kiosk')

    expect(all).toHaveLength(light.length + kiosk.length)
  })
})

describe('applyBranding sobre el documento', () => {
  const branded: Branding = {
    applicationName: 'Hotel Marina',
    accentColor: '#0f5c8c',
    logoUrl: '/api/v1/branding/logo?v=3f9a1c2b7e4d',
    locales: { default: 'es', available: ['es'] },
    privacyNotice: { controllerName: null, policyUrl: null, retentionYears: null },
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

describe('resolveUiLocale (RF-KI-05, doc 01 §6.6)', () => {
  const shipped = ['es', 'en'] as const

  describe('con un unico idioma activo, el de serie', () => {
    const policy = { default: 'es', available: ['es'] }

    it('es ese idioma aunque el navegador pida otro', () => {
      expect(resolveUiLocale({ policy, shipped, browser: ['en-GB', 'en'] })).toBe('es')
    })

    it('ni la eleccion guardada ni el idioma de la persona lo cambian', () => {
      expect(
        resolveUiLocale({ policy, shipped, stored: 'en', personal: 'en', browser: ['en-US'] }),
      ).toBe('es')
    })

    it('si el unico activo es el ingles, todo sale en ingles', () => {
      expect(
        resolveUiLocale({
          policy: { default: 'en', available: ['en'] },
          shipped,
          stored: 'es',
          browser: ['es-ES'],
        }),
      ).toBe('en')
    })

    it('un idioma activo que no esta traducido no deja la interfaz sin idioma', () => {
      // `ro` no viene en esta version: se ofrecen los traducidos y decide el navegador.
      expect(
        resolveUiLocale({ policy: { default: 'ro', available: ['ro'] }, shipped, browser: [] }),
      ).toBe('es')
    })
  })

  describe('con dos idiomas activos', () => {
    const policy = { default: 'es', available: ['es', 'en'] }

    it('manda la eleccion guardada en el dispositivo', () => {
      expect(
        resolveUiLocale({ policy, shipped, stored: 'en', personal: 'es', browser: ['es-ES'] }),
      ).toBe('en')
    })

    it('sin eleccion guardada, el idioma de la persona', () => {
      expect(resolveUiLocale({ policy, shipped, personal: 'en', browser: ['es-ES'] })).toBe('en')
    })

    it('una eleccion o un idioma personal fuera de la lista se ignoran', () => {
      expect(
        resolveUiLocale({ policy, shipped, stored: 'fr', personal: 'ro', browser: ['en-US'] }),
      ).toBe('en')
    })

    it('sin eleccion, el primer idioma del navegador que coincide por prefijo', () => {
      expect(resolveUiLocale({ policy, shipped, browser: ['fr-FR', 'en-GB', 'es'] })).toBe('en')
      expect(resolveUiLocale({ policy, shipped, browser: ['EN_us'] })).toBe('en')
    })

    it('si el navegador no coincide en nada, el idioma por defecto de la instalacion', () => {
      expect(
        resolveUiLocale({
          policy: { default: 'en', available: ['es', 'en'] },
          shipped,
          browser: ['de-DE'],
        }),
      ).toBe('en')
      expect(resolveUiLocale({ policy, shipped, browser: [] })).toBe('es')
    })

    it('un idioma activo pero no traducido no se elige nunca', () => {
      expect(
        resolveUiLocale({
          policy: { default: 'ro', available: ['ro', 'en'] },
          shipped,
          stored: 'ro',
          browser: ['ro-RO', 'es-ES'],
        }),
      ).toBe('en')
    })
  })
})
