// Cero avisos de Vue en las pruebas (V21-1-KI-1, R1-KI-01).
//
// POR QUE FALLA Y NO SOLO AVISA. En la 2.2.0 se limpiaron los avisos y en la
// 2.2.1 habian vuelto 34 (`injection "Symbol(router)" not found`): nadie los
// veia porque Vitest no ensena la consola de las pruebas que pasan. Un aviso de
// Vue es casi siempre un montaje incompleto -un router o un plugin que falta-,
// y una prueba montada a medias comprueba una pantalla que no existe.
//
// COMO. Vue escribe sus avisos con `console.warn('[Vue warn]: …')` cuando la
// aplicacion no declara `warnHandler`, y ninguna prueba lo declara. Se envuelve
// `console.warn` (no se espia: un `vi.restoreAllMocks()` de una prueba lo
// quitaria en silencio), se anotan los avisos de Vue y, al terminar cada
// prueba, se exige que no haya ninguno. El resto de `console.warn` pasa tal cual.

import { afterEach, expect } from 'vitest'

const VUE_WARNING_PREFIX = '[Vue warn]'
const vueWarnings: string[] = []
const originalWarn = console.warn.bind(console)

console.warn = (...args: unknown[]): void => {
  const first = args[0]
  if (typeof first === 'string' && first.startsWith(VUE_WARNING_PREFIX)) {
    vueWarnings.push(first)
  }
  originalWarn(...args)
}

afterEach(() => {
  const found = vueWarnings.splice(0)
  expect(found, 'la prueba ha emitido avisos de Vue (V21-1-KI-1)').toEqual([])
})
