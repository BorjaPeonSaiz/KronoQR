// Los tokens `--kq-*` de `theme.css` tal como los declara el fichero, para las
// pruebas que miden sin navegador.
import { readFileSync } from 'node:fs'
import { dirname, resolve } from 'node:path'
import { fileURLToPath } from 'node:url'

const themePath = resolve(dirname(fileURLToPath(import.meta.url)), '../../../src/theme.css')

export function readRootTokens(): Map<string, string> {
  const css = readFileSync(themePath, 'utf8').replace(/\/\*[\s\S]*?\*\//g, '')
  const start = css.search(/:root\s*\{/)
  const open = css.indexOf('{', start)
  const body = css.slice(open + 1, css.indexOf('}', open))
  const out = new Map<string, string>()
  for (const match of body.matchAll(/(--[\w-]+)\s*:\s*([^;]+);/g)) {
    out.set(match[1] ?? '', (match[2] ?? '').replace(/\s+/g, ' ').trim())
  }
  return out
}
