// Una pantalla que ya no existe tras `update.sh` (8.8): el portal recarga UNA vez
// y la marca anti-bucle viaja en la URL, nunca en el almacenamiento del navegador.
import { createQueryReloadMark, installChunkLoadRecovery } from '@kronoqr/web-kit/chunkLoadRecovery'
import { describe, expect, it, vi } from 'vitest'
import { createMemoryHistory, createRouter } from 'vue-router'

const Stub = { render: () => null }

function routerWithMissingChunk() {
  return createRouter({
    history: createMemoryHistory(),
    routes: [
      { path: '/records', component: Stub },
      {
        path: '/export',
        component: () =>
          Promise.reject(new TypeError('Failed to fetch dynamically imported module: /x.js')),
      },
    ],
  })
}

describe('recuperacion de pantalla perdida en el portal', () => {
  it('recarga la ruta pedida con la marca en la URL y sin escribir en el navegador', async () => {
    const router = routerWithMissingChunk()
    const reload = vi.fn()
    const setLocal = vi.spyOn(Storage.prototype, 'setItem')

    installChunkLoadRecovery(router, { mark: createQueryReloadMark(router), reload })
    await router.push('/records')
    await router.push('/export').catch(() => undefined)

    expect(reload).toHaveBeenCalledWith('/export?kq_reloaded=1')
    expect(setLocal).not.toHaveBeenCalled()
    setLocal.mockRestore()
  })
})
