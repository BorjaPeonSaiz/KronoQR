// La capa base (`body`, encabezados, foco visible, `prefers-reduced-motion`)
// vivia repetida, caracter por caracter, en `main.css` del panel y del
// portal, y el quiosco repetia ademas su propia regla de `:focus-visible`
// (ADR-036: "antes de escribir en una SPA una utilidad que ya exista en otra,
// se comprueba `packages/web-kit` primero"). Esta prueba comprueba que
// `base.css` existe, que no cuela un hexadecimal suelto (solo referencias a
// tokens `--kq-*`, para que la marca blanca de la tarea 5.8 los sobrescriba) y
// que ninguna de las tres SPA vuelve a declarar `:focus-visible` con un color
// literal en vez de un token.
import { readFileSync } from 'node:fs'
import { dirname, resolve } from 'node:path'
import { fileURLToPath } from 'node:url'
import { describe, expect, it } from 'vitest'

const srcDir = resolve(dirname(fileURLToPath(import.meta.url)), '../../src')
const repoRoot = resolve(dirname(fileURLToPath(import.meta.url)), '../../../..')

const baseCss = readFileSync(resolve(srcDir, 'base.css'), 'utf8')
const themeCss = readFileSync(resolve(srcDir, 'theme.css'), 'utf8')

/** Quita los comentarios de bloque, igual que `theme.spec.ts`. */
function withoutComments(rawCss: string): string {
  return rawCss.replace(/\/\*[\s\S]*?\*\//g, '')
}

const spaMainCss: Readonly<Record<string, string>> = {
  'frontend-admin': readFileSync(resolve(repoRoot, 'frontend-admin/src/assets/main.css'), 'utf8'),
  'frontend-portal': readFileSync(resolve(repoRoot, 'frontend-portal/src/assets/main.css'), 'utf8'),
  'frontend-kiosk': readFileSync(resolve(repoRoot, 'frontend-kiosk/src/assets/main.css'), 'utf8'),
}

describe('base.css: capa base compartida (body, encabezados, foco, reduced-motion)', () => {
  it('existe y declara la capa base de la que dependen las tres SPA', () => {
    expect(withoutComments(baseCss)).toMatch(/\bbody\s*\{/)
    expect(withoutComments(baseCss)).toMatch(/:focus-visible\s*\{/)
    expect(withoutComments(baseCss)).toMatch(/@media \(prefers-reduced-motion: reduce\)/)
  })

  it('theme.css importa base.css: basta con una SPA important theme.css', () => {
    expect(withoutComments(themeCss)).toMatch(/@import\s+'\.\/base\.css'/)
  })

  it('no contiene hexadecimales sueltos: todo color es var(--kq-*)', () => {
    const withoutVarReferences = withoutComments(baseCss).replace(/var\(--kq-[\w-]+\)/g, '')

    expect(withoutVarReferences).not.toMatch(/#[0-9a-f]{3,8}/i)
  })

  it('los tokens de pagina (--kq-page-*) resuelven, por defecto, a tokens ya medidos en theme.css', () => {
    const tokens = new Map<string, string>()
    for (const match of withoutComments(baseCss).matchAll(/(--kq-page-[\w-]+)\s*:\s*([^;]+);/g)) {
      tokens.set(match[1] ?? '', (match[2] ?? '').trim())
    }

    expect(tokens.get('--kq-page-bg')).toBe('var(--kq-color-surface)')
    expect(tokens.get('--kq-page-text')).toBe('var(--kq-color-text)')
    expect(tokens.get('--kq-page-focus')).toBe('var(--kq-color-focus)')
  })
})

describe('main.css de cada SPA: nada de la capa base repetido, ningun :focus-visible con color literal', () => {
  it.each(Object.entries(spaMainCss))(
    '%s importa theme.css, no repite la capa base',
    (_app, css) => {
      const clean = withoutComments(css)

      expect(clean).toMatch(/@import\s+'@kronoqr\/web-kit\/theme\.css'/)
      // Ni body{...}, ni encabezados, ni reduced-motion: eso ya lo trae
      // `@kronoqr/web-kit/theme.css` a traves de `base.css`.
      expect(clean).not.toMatch(/@media \(prefers-reduced-motion: reduce\)/)
    },
  )

  it.each(Object.entries(spaMainCss))(
    '%s no declara :focus-visible con un color hexadecimal literal',
    (_app, css) => {
      const clean = withoutComments(css)
      const match = clean.match(/:focus-visible\s*\{([^}]*)\}/)

      // El quiosco no declara la regla en absoluto: solo redefine
      // --kq-page-focus. Si alguna SPA la declarase, no puede llevar un
      // hexadecimal suelto, solo un token --kq-*.
      if (match) {
        expect(match[1] ?? '').not.toMatch(/#[0-9a-f]{3,8}/i)
      }
    },
  )
})
