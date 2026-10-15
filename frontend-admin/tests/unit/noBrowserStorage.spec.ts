// Prueba de la regla de lint contra el almacenamiento del navegador: una regla
// sin prueba puede dejar de vigilar sin que nadie se entere. Se lanza ESLint de
// verdad con la configuracion de la aplicacion.
import { ESLint } from 'eslint'
import { describe, expect, it } from 'vitest'

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
  "window['localStorage'].clear()",
  "sessionStorage.setItem('k', 'v')",
  "window.sessionStorage.getItem('k')",
  "globalThis.sessionStorage?.getItem('k')",
]

describe('regla contra localStorage y sessionStorage', () => {
  it.each(FORBIDDEN_SNIPPETS)('rechaza %s en un fichero cualquiera de src/', async (code) => {
    const messages = await messagesFor(`${code}\n`, 'src/features/example/example.ts')
    expect(messages).not.toEqual([])
    expect(messages.join('\n')).toContain('no se guardan en el navegador')
  })

  it('no se queja de codigo que no toca el almacenamiento', async () => {
    expect(
      await messagesFor('export const a = window.location.href\n', 'src/features/example/a.ts'),
    ).toEqual([])
  })

  it('no vigila las pruebas, que lo leen para comprobar que no queda nada', async () => {
    expect(await messagesFor("window.localStorage.getItem('k')\n", 'tests/unit/x.spec.ts')).toEqual(
      [],
    )
  })

  it('la cache de marca (brandingState.ts) puede usar localStorage, pero no sessionStorage', async () => {
    const file = 'src/shared/brandingState.ts'
    expect(await messagesFor("globalThis.localStorage?.getItem('k')\n", file)).toEqual([])
    expect(await messagesFor("window.localStorage.getItem('k')\n", file)).toEqual([])
    expect(await messagesFor("window.sessionStorage.getItem('k')\n", file)).not.toEqual([])
  })

  it('el token de sesion puede usar sessionStorage, pero no localStorage', async () => {
    const file = 'src/features/auth/session.store.ts'
    expect(await messagesFor("globalThis.sessionStorage?.getItem('k')\n", file)).toEqual([])
    expect(await messagesFor("globalThis.localStorage?.getItem('k')\n", file)).not.toEqual([])
  })

  it('la marca contra el bucle de recarga puede usar sessionStorage, pero no localStorage', async () => {
    const file = 'src/router/chunkLoadRecovery.ts'
    expect(await messagesFor("window.sessionStorage.getItem('k')\n", file)).toEqual([])
    expect(await messagesFor("window.localStorage.getItem('k')\n", file)).not.toEqual([])
  })
})
