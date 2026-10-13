// Arquitectura: Tailwind 4 solo genera las clases de los ficheros que escanea.
// Una SPA que importa componentes `.vue` de web-kit sin declarar el `@source`
// del paquete los pinta sin las clases (el icono de pausa salia sin caja).
import { existsSync, readFileSync, readdirSync, statSync } from 'node:fs'
import { join, resolve } from 'node:path'
import { describe, expect, it } from 'vitest'

const ROOT = resolve(__dirname, '../../..')
const SPAS = ['frontend-admin', 'frontend-portal', 'frontend-kiosk']

function sourceFiles(dir: string): string[] {
  return readdirSync(dir).flatMap((name) => {
    const path = join(dir, name)
    if (statSync(path).isDirectory()) return sourceFiles(path)
    return /\.(vue|ts)$/.test(name) ? [path] : []
  })
}

describe('@source de web-kit en el CSS de cada SPA', () => {
  it.each(SPAS)('%s declara el @source si importa componentes de web-kit', (spa) => {
    const src = join(ROOT, spa, 'src')
    // Una SPA de la lista que no aparece es una lista vieja, no un caso aprobado.
    expect(existsSync(src)).toBe(true)
    const imports = sourceFiles(src).some((file) =>
      /from\s+['"]@kronoqr\/web-kit\/components\/[^'"]+\.vue['"]/.test(readFileSync(file, 'utf8')),
    )
    if (!imports) return

    const css = readFileSync(join(src, 'assets/main.css'), 'utf8')
    const match = /@source\s+['"]([^'"]+)['"]/g
    const targets = [...css.matchAll(match)].map((m) => resolve(src, 'assets', m[1] ?? ''))
    expect(targets).toContain(join(ROOT, 'packages/web-kit/src'))
  })
})
