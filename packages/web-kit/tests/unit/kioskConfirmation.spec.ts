// Los cinco colores de confirmacion del quiosco no estan en `theme.css` (son
// semanticos, no marca), pero el texto blanco encima tiene que medirse igual:
// esta prueba lee `frontend-kiosk/src/assets/main.css` y verifica cada pareja
// de `kioskConfirmationPairs` (UX7-01).
import { readFileSync } from 'node:fs'
import { dirname, resolve } from 'node:path'
import { fileURLToPath } from 'node:url'
import { describe, expect, it } from 'vitest'
import { contrastRatio, WCAG_AA_MINIMUM } from '../../src/contrast'
import { KIOSK_CONFIRMATION_FOREGROUND, kioskConfirmationPairs } from '../../src/themePairs'

const repoRoot = resolve(dirname(fileURLToPath(import.meta.url)), '../../../..')
const rawCss = readFileSync(resolve(repoRoot, 'frontend-kiosk/src/assets/main.css'), 'utf8')
const css = rawCss.replace(/\/\*[\s\S]*?\*\//g, '')

const declared = new Map<string, string>()
for (const match of css.matchAll(/(--color-kiosk-[\w-]+)\s*:\s*([^;]+);/g)) {
  declared.set(match[1] ?? '', (match[2] ?? '').trim())
}

describe('main.css del quiosco: contraste de los colores de confirmacion', () => {
  it.each(kioskConfirmationPairs.map((p) => [p.use, p.background, p.requirement] as const))(
    '%s: blanco sobre %s (%s)',
    (_use, background, requirement) => {
      const value = declared.get(background)
      if (value === undefined) throw new Error(`${background} is not declared in main.css`)

      expect(contrastRatio(KIOSK_CONFIRMATION_FOREGROUND, value)).toBeGreaterThanOrEqual(
        WCAG_AA_MINIMUM[requirement],
      )
    },
  )

  it('todo color de confirmacion declarado en main.css tiene su pareja medida', () => {
    const measured = new Set(kioskConfirmationPairs.map((p) => p.background))

    expect([...declared.keys()].filter((name) => !measured.has(name))).toEqual([])
  })

  it('toda pareja apunta a un color declarado en main.css', () => {
    const missing = kioskConfirmationPairs.map((p) => p.background).filter((t) => !declared.has(t))

    expect(missing).toEqual([])
  })

  it('son exactamente los cinco estados de confirmacion', () => {
    expect([...declared.keys()].sort()).toEqual([
      '--color-kiosk-entry',
      '--color-kiosk-error',
      '--color-kiosk-exit',
      '--color-kiosk-notice',
      '--color-kiosk-pending',
    ])
  })
})
