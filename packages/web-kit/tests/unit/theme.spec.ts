// El contraste se mide, no se estima: esta prueba lee `theme.css` tal como lo
// consumiran las SPA y verifica cada pareja de `themePairs.ts` con la formula
// WCAG 2.2 (doc 01 §6.5; "una convencion que no verifica una herramienta es
// una sugerencia").
import { readFileSync } from 'node:fs'
import { dirname, resolve } from 'node:path'
import { fileURLToPath } from 'node:url'
import { describe, expect, it } from 'vitest'
import { contrastRatio, WCAG_AA_MINIMUM } from '../../src/contrast'
import { themePairs } from '../../src/themePairs'

// Ruta por `node:path` y no por `new URL(..., import.meta.url)`: bajo jsdom el
// `URL` global es el del navegador simulado y `readFileSync` no lo acepta.
const srcDir = resolve(dirname(fileURLToPath(import.meta.url)), '../../src')
const themeCss = readFileSync(resolve(srcDir, 'theme.css'), 'utf8')
const fontsCss = readFileSync(resolve(srcDir, 'fonts.css'), 'utf8')

/** Extrae `--name: value;` del primer bloque cuyo selector casa, ignorando comentarios. */
function declarationsOf(rawCss: string, selector: RegExp): Map<string, string> {
  const css = rawCss.replace(/\/\*[\s\S]*?\*\//g, '')
  const start = css.search(selector)
  if (start < 0) throw new Error(`Block ${selector} not found in theme.css`)
  const open = css.indexOf('{', start)
  const close = css.indexOf('}', open)
  const body = css.slice(open + 1, close)

  const out = new Map<string, string>()
  for (const match of body.matchAll(/(--[\w-]+)\s*:\s*([^;]+);/g)) {
    out.set(match[1] ?? '', (match[2] ?? '').replace(/\s+/g, ' ').trim())
  }
  return out
}

const tokens = declarationsOf(themeCss, /:root\s*\{/)
const theme = declarationsOf(themeCss, /@theme inline\s*\{/)
const colorTokens = [...tokens.keys()].filter((name) => name.startsWith('--kq-color-'))

function valueOf(token: string): string {
  const value = tokens.get(token)
  if (value === undefined) throw new Error(`Token ${token} is not declared in theme.css :root`)
  return value
}

describe('theme.css: contraste WCAG 2.2 AA de cada pareja declarada', () => {
  it.each(themePairs.map((p) => [p.use, p.foreground, p.background, p.requirement] as const))(
    '%s: %s sobre %s (%s)',
    (_use, foreground, background, requirement) => {
      const ratio = contrastRatio(valueOf(foreground), valueOf(background))

      expect(ratio).toBeGreaterThanOrEqual(WCAG_AA_MINIMUM[requirement])
    },
  )

  it('todo token de color esta medido en al menos una pareja', () => {
    const measured = new Set(themePairs.flatMap((p) => [p.foreground, p.background]))
    const unmeasured = colorTokens.filter((token) => !measured.has(token))

    expect(unmeasured).toEqual([])
  })

  it('toda pareja apunta a tokens que existen', () => {
    const missing = themePairs
      .flatMap((p) => [p.foreground, p.background])
      .filter((token) => !tokens.has(token))

    expect(missing).toEqual([])
  })

  it('no repite parejas', () => {
    const keys = themePairs.map((p) => `${p.foreground}/${p.background}`)

    expect(new Set(keys).size).toBe(keys.length)
  })
})

describe('theme.css: exposicion a Tailwind sin romper la marca blanca', () => {
  it('el bloque @theme es `inline`, para que las utilidades lean la variable en tiempo de ejecucion', () => {
    expect(themeCss).toMatch(/@theme inline\s*\{/)
    expect(themeCss).not.toMatch(/@theme\s*\{/)
  })

  it('cada token de color --kq-color-X tiene su utilidad --color-kq-X apuntando a el', () => {
    const unexposed = colorTokens.filter((token) => {
      const utility = token.replace(/^--kq-color-/, '--color-kq-')
      return theme.get(utility) !== `var(${token})`
    })

    expect(unexposed).toEqual([])
  })

  it('las utilidades solo referencian tokens declarados, y ningun token se queda sin exponer', () => {
    const referenced = new Set(
      [...theme.values()].flatMap((value) =>
        [...value.matchAll(/var\((--kq-[\w-]+)\)/g)].map((m) => m[1] ?? ''),
      ),
    )
    const dangling = [...referenced].filter((token) => !tokens.has(token))
    const unexposed = [...tokens.keys()].filter((token) => !referenced.has(token))

    expect(dangling).toEqual([])
    expect(unexposed).toEqual([])
  })

  it('los tokens de color son hexadecimales: sobreescribibles por 5.8 y medibles aqui', () => {
    const nonHex = colorTokens.filter((token) => !/^#[0-9a-f]{6}$/i.test(valueOf(token)))

    expect(nonHex).toEqual([])
  })
})

describe('fonts.css: fuentes autoalojadas', () => {
  it('no carga nada de un tercero: ni Google Fonts ni ningun CDN', () => {
    expect(fontsCss).not.toMatch(/https?:\/\//)
    expect(themeCss).not.toMatch(/https?:\/\//)
    expect(fontsCss).not.toMatch(/fonts\.googleapis|fonts\.gstatic/)
  })

  it('importa solo paquetes @fontsource', () => {
    const imports = [...fontsCss.matchAll(/@import\s+'([^']+)'/g)].map((m) => m[1] ?? '')

    expect(imports.length).toBeGreaterThan(0)
    expect(imports.every((spec) => spec.startsWith('@fontsource'))).toBe(true)
  })

  it('las pilas tipograficas terminan en fuentes del sistema', () => {
    expect(valueOf('--kq-font-heading')).toMatch(/system-ui.*sans-serif$/)
    expect(valueOf('--kq-font-body')).toMatch(/system-ui.*sans-serif$/)
  })
})
