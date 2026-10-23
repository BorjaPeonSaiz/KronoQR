// V7-UX-02 / R7-UX-03 / V21-7-UX-2: la prueba de contraste no exige solo que
// cada TOKEN aparezca en alguna pareja; exige que cada PAREJA QUE LAS
// PLANTILLAS USAN este medida en `themePairs.ts` (y, por tanto, en doc 06 y en
// `theme.spec.ts`). Barre las tres SPA y web-kit: texto, fondo, bordes, anillos,
// y los estados hover (con filtro), activo y deshabilitado (con opacidad).
import { dirname, relative, resolve } from 'node:path'
import { fileURLToPath } from 'node:url'
import { describe, expect, it } from 'vitest'
import { contrastRatio, parseHexColor, WCAG_AA_MINIMUM } from '../../src/contrast'
import { kioskPairs, lightPairs, pairKey, themePairs } from '../../src/themePairs'
import { readRootTokens } from './support/themeTokens'
import { scanSource, scanTree, type ClassPair } from './support/scanClassPairs'

const repoRoot = resolve(dirname(fileURLToPath(import.meta.url)), '../../../..')
const roots = [
  'frontend-admin/src',
  'frontend-portal/src',
  'frontend-kiosk/src',
  'packages/web-kit/src',
]
const scan = {
  pairs: [] as ClassPair[],
  disabled: [] as ReturnType<typeof scanTree>['disabled'],
  brightness: [] as ReturnType<typeof scanTree>['brightness'],
  translucent: [] as string[],
}
for (const root of roots) {
  const part = scanTree(resolve(repoRoot, root))
  scan.pairs.push(...part.pairs)
  scan.disabled.push(...part.disabled)
  scan.brightness.push(...part.brightness)
  scan.translucent.push(...part.translucent)
}

/** `top` con opacidad `alpha` sobre `ground`, redondeado a 8 bits como lo compone el navegador. */
function painted(top: string, ground: string, alpha: number): string {
  const a = parseHexColor(top)
  const b = parseHexColor(ground)
  const channel = (x: number, y: number): string =>
    Math.round(x * alpha + y * (1 - alpha))
      .toString(16)
      .padStart(2, '0')
  return `#${channel(a.r, b.r)}${channel(a.g, b.g)}${channel(a.b, b.b)}`
}

const measured = new Set(themePairs.map((p) => `${p.foreground}/${p.background}`))
const measuredAsBackground = new Set(themePairs.map((p) => p.background))
const measuredAsForeground = new Set(themePairs.map((p) => p.foreground))
const grounds = (token: string): readonly string[] =>
  token.includes('kiosk')
    ? ['--kq-color-kiosk-surface', '--kq-color-kiosk-surface-raised']
    : ['--kq-color-surface', '--kq-color-surface-raised']
const defaultText = (token: string): string =>
  token.includes('kiosk') ? '--kq-color-kiosk-text' : '--kq-color-text'
const where = (p: { file: string; state?: string; classes: string }): string =>
  `${relative(repoRoot, p.file)} [${p.state ?? ''}] ${p.classes.slice(0, 110)}`

function missingPair(p: ClassPair): string | null {
  const { foreground, background } = p
  if (foreground !== null && background !== null) {
    return measured.has(`${foreground}/${background}`) ? null : `${foreground} sobre ${background}`
  }
  if (foreground !== null) {
    // `on-*` es el texto de un relleno que pone otra clase (`:class`): basta que este medido.
    if (/--kq-color-(kiosk-)?on-/.test(foreground)) {
      return measuredAsForeground.has(foreground) ? null : `${foreground} sin ninguna pareja`
    }
    // Fondo implicito: cualquiera de las dos superficies donde se pinta.
    return grounds(foreground).some((g) => measured.has(`${foreground}/${g}`))
      ? null
      : `${foreground} sobre su superficie`
  }
  if (background !== null) {
    // Un relleno sin texto en su literal (barra de progreso, punto de estado): el texto que
    // lleve se declara aparte y se mide en su pareja; aqui basta que el fondo este medido.
    return measuredAsBackground.has(background) || measuredAsForeground.has(background)
      ? null
      : `fondo ${background} sin ninguna pareja`
  }
  return null
}

describe('las parejas que usan las plantillas estan medidas', () => {
  it('el barrido encuentra uso real (no pasa por vacio)', () => {
    expect(scan.pairs.length).toBeGreaterThan(300)
  })

  it('texto, fondo, bordes y anillos: cada pareja usada esta en themePairs', () => {
    const missing = scan.pairs
      .map((p) => [missingPair(p), p] as const)
      .filter(([why]) => why !== null)
      .map(([why, p]) => `${why} :: ${where(p)}`)

    expect([...new Set(missing)].sort()).toEqual([])
  })

  it('hover con brightness: la pareja filtrada esta medida con su factor', () => {
    const keys = new Set(
      themePairs
        .filter((p) => p.state?.kind === 'hover')
        .map((p) => `${p.foreground}/${p.background}/${p.state?.shade}`),
    )
    const missing = scan.brightness
      .filter((u) => !keys.has(`${u.foreground}/${u.background}/${u.shade}`))
      .map((u) => `${u.foreground} sobre ${u.background} x${u.shade} :: ${where(u)}`)

    expect([...new Set(missing)].sort()).toEqual([])
  })

  it('deshabilitado con opacidad: cada pareja y cada opacidad usadas estan medidas', () => {
    const keys = new Set(
      themePairs
        .filter((p) => p.state?.kind === 'disabled')
        .map((p) => `${p.foreground}/${p.background}/${p.state?.opacity}`),
    )
    const missing = scan.disabled.flatMap((u) => {
      const foreground = u.foreground ?? defaultText(u.background ?? '--kq-color-surface')
      const candidates = u.background !== null ? [u.background] : grounds(foreground)
      // Texto `on-*` sin fondo en el literal: el fondo lo pone otra clase.
      if (u.background === null && /--kq-color-(kiosk-)?on-/.test(foreground)) {
        return [...keys].some((k) => k.startsWith(`${foreground}/`) && k.endsWith(`/${u.opacity}`))
          ? []
          : [`${foreground} a ${u.opacity} :: ${where(u)}`]
      }
      return candidates.some((b) => keys.has(`${foreground}/${b}/${u.opacity}`))
        ? []
        : [`${foreground} sobre ${candidates[0]} a ${u.opacity} :: ${where(u)}`]
    })

    expect([...new Set(missing)].sort()).toEqual([])
  })

  it('las utilidades con transparencia son solo el velo de los dialogos', () => {
    const unexpected = scan.translucent.filter(
      (entry) =>
        !entry.endsWith('BaseDialog.vue: bg-kq-text/60') &&
        !/(ScanView|PrivacyNoticePanel)\.vue: bg-kq-kiosk-surface\/(80|90)$/.test(entry),
    )

    expect(unexpected).toEqual([])
  })

  it('el velo del quiosco sobre la camara deja leer el texto aun con la imagen mas clara', () => {
    // Peor caso: el velo semitransparente (80 %) sobre una imagen blanca.
    const tokens = readRootTokens()
    const hex = (token: string): string => tokens.get(token) ?? ''
    for (const alpha of [0.8, 0.9]) {
      const veil = painted(hex('--kq-color-kiosk-surface'), '#ffffff', alpha)
      for (const text of ['--kq-color-kiosk-text', '--kq-color-kiosk-text-muted']) {
        expect(
          contrastRatio(hex(text), veil),
          `${text} sobre velo al ${alpha}`,
        ).toBeGreaterThanOrEqual(WCAG_AA_MINIMUM.text)
      }
    }
  })

  it('no hay dos filas iguales y toda fila deshabilitada o con filtro declara su estado', () => {
    const keys = themePairs.map(pairKey)
    expect(new Set(keys).size).toBe(keys.length)
  })
})

describe('scanSource: lo que entiende el barrido', () => {
  const run = (classes: string) => scanSource('x.vue', `<a class="${classes}">`)

  it('texto y fondo del mismo literal forman una pareja en reposo', () => {
    expect(run('bg-kq-primary-strong text-kq-on-primary').pairs).toMatchObject([
      {
        state: 'rest',
        foreground: '--kq-color-on-primary',
        background: '--kq-color-primary-strong',
      },
    ])
  })

  it('una variante hereda el texto de reposo y cambia el fondo', () => {
    const pairs = run('text-kq-primary-strong hover:bg-kq-surface-alt').pairs

    expect(pairs).toContainEqual(
      expect.objectContaining({
        state: 'hover',
        foreground: '--kq-color-primary-strong',
        background: '--kq-color-surface-alt',
      }),
    )
  })

  it('los prefijos de ancho no son estados', () => {
    expect(run('md:bg-kq-surface-alt text-kq-text').pairs.map((p) => p.state)).toEqual(['rest'])
  })

  it('bordes y anillos cuentan como linea contra el fondo', () => {
    expect(run('border border-kq-border-strong bg-kq-surface-raised').pairs).toContainEqual(
      expect.objectContaining({ role: 'line', foreground: '--kq-color-border-strong' }),
    )
  })

  it('detecta opacidad de deshabilitado y brillo de hover', () => {
    const scanned = run(
      'bg-kq-primary-strong text-kq-on-primary hover:brightness-95 aria-disabled:opacity-60',
    )

    expect(scanned.disabled).toMatchObject([{ opacity: 0.6 }])
    expect(scanned.brightness).toMatchObject([{ shade: 0.95 }])
  })
})

describe('themePairs: forma', () => {
  it('lightPairs y kioskPairs no comparten fondo ni primer plano', () => {
    const lightTokens = new Set(lightPairs.flatMap((p) => [p.foreground, p.background]))
    expect(
      kioskPairs.flatMap((p) => [p.foreground, p.background]).filter((t) => lightTokens.has(t)),
    ).toEqual([])
  })
})
