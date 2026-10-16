// Prueba de la regla compartida contra el almacenamiento del navegador: una
// regla de lint sin prueba puede dejar de vigilar sin que nadie se entere. Se
// lanza ESLint de verdad con la configuracion de web-kit.
import { ESLint } from 'eslint'
import { describe, expect, it } from 'vitest'

import { noBrowserStorage } from '../../eslint/no-browser-storage.js'

const eslint = new ESLint({ cwd: process.cwd() })

async function messagesFor(code: string, filePath: string): Promise<string[]> {
  const [result] = await eslint.lintText(code, { filePath })
  return (result?.messages ?? [])
    .filter((message) => message.ruleId?.startsWith('no-restricted-') === true)
    .map((message) => `${message.ruleId}: ${message.message}`)
}

const FORBIDDEN_SNIPPETS = [
  "localStorage.setItem('k', 'v')",
  "window.localStorage.getItem('k')",
  "globalThis.localStorage?.getItem('k')",
  'self.localStorage.clear()',
  "window['localStorage'].clear()",
  "sessionStorage.setItem('k', 'v')",
  "window.sessionStorage.getItem('k')",
  "globalThis.sessionStorage?.getItem('k')",
]

describe('regla contra localStorage y sessionStorage', () => {
  it.each(FORBIDDEN_SNIPPETS)('rechaza %s en un fichero cualquiera de src/', async (code) => {
    const messages = await messagesFor(`${code}\n`, 'src/example.ts')
    expect(messages).not.toEqual([])
    expect(messages.join('\n')).toContain('no se guardan en el navegador')
  })

  it('no se queja de codigo que no toca el almacenamiento', async () => {
    expect(await messagesFor('export const a = window.location.href\n', 'src/a.ts')).toEqual([])
  })

  it('no vigila las pruebas, que lo leen para comprobar que no queda nada', async () => {
    expect(await messagesFor("window.localStorage.getItem('k')\n", 'tests/unit/x.spec.ts')).toEqual(
      [],
    )
  })

  it('la cache de marca (brandingState.ts) puede usar localStorage, pero no sessionStorage', async () => {
    const file = 'src/brandingState.ts'
    expect(await messagesFor("globalThis.localStorage?.getItem('k')\n", file)).toEqual([])
    expect(await messagesFor("window.localStorage.getItem('k')\n", file)).toEqual([])
    expect(await messagesFor("window.sessionStorage.getItem('k')\n", file)).not.toEqual([])
  })

  it('un fichero con el mismo nombre en otra ruta no hereda la excepcion', async () => {
    expect(
      await messagesFor("window.localStorage.getItem('k')\n", 'src/copy/brandingState.ts'),
    ).not.toEqual([])
  })
})

describe('noBrowserStorage()', () => {
  it('cada excepcion permite solo lo declarado y exige un motivo', () => {
    const [rule, exception] = noBrowserStorage({
      exceptions: [{ file: 'src/a.ts', allow: ['sessionStorage'], reason: 'motivo' }],
    })
    expect(rule?.files).toEqual(['src/**/*.{ts,mts,tsx,vue,js,mjs}'])
    expect(exception?.files).toEqual(['src/a.ts'])
    const globals = exception?.rules?.['no-restricted-globals'] as unknown[]
    expect(JSON.stringify(globals)).toContain('localStorage')
    expect(JSON.stringify(globals)).not.toContain('"sessionStorage"')
    expect(() =>
      noBrowserStorage({ exceptions: [{ file: 'src/b.ts', allow: [], reason: '' }] }),
    ).toThrow(/motivo/)
  })
})
