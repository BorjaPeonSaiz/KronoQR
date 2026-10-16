// La regla vive en web-kit (con su prueba completa). Aqui solo se comprueba que
// la configuracion de esta aplicacion la aplica y declara sus excepciones.
import { ESLint } from 'eslint'
import { describe, expect, it } from 'vitest'

const eslint = new ESLint({ cwd: process.cwd() })

async function hasStorageError(code: string, filePath: string): Promise<boolean> {
  const [result] = await eslint.lintText(code, { filePath })
  return (result?.messages ?? []).some(
    (message) => message.ruleId?.startsWith('no-restricted-') === true,
  )
}

describe('regla contra el almacenamiento del navegador', () => {
  it('prohibe localStorage y sessionStorage en un fichero cualquiera de src/', async () => {
    expect(await hasStorageError("window.localStorage.getItem('k')\n", 'src/example.ts')).toBe(true)
    expect(await hasStorageError("window.sessionStorage.getItem('k')\n", 'src/example.ts')).toBe(
      true,
    )
  })

  it('el token de sesion puede usar sessionStorage, pero no localStorage', async () => {
    const file = 'src/features/login/session.store.ts'
    expect(await hasStorageError("globalThis.sessionStorage?.getItem('k')\n", file)).toBe(false)
    expect(await hasStorageError("globalThis.localStorage?.getItem('k')\n", file)).toBe(true)
  })
})
