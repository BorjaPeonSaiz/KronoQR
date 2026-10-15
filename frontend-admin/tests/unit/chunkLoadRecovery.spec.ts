import { afterEach, describe, expect, it, vi } from 'vitest'
import { createMemoryHistory, createRouter } from 'vue-router'
import {
  CHUNK_RELOAD_MARK,
  installChunkLoadRecovery,
  isChunkLoadError,
  type MarkStorage,
} from '@/router/chunkLoadRecovery'

function memoryStorage(): MarkStorage & { data: Map<string, string> } {
  const data = new Map<string, string>()

  return {
    data,
    getItem: (key) => data.get(key) ?? null,
    setItem: (key, value) => void data.set(key, value),
    removeItem: (key) => void data.delete(key),
  }
}

const Stub = { render: () => null }

function makeRouter(failing: { value: boolean }) {
  return createRouter({
    history: createMemoryHistory(),
    routes: [
      { path: '/', component: Stub },
      {
        path: '/lazy',
        component: () =>
          failing.value
            ? Promise.reject(
                new TypeError('Failed to fetch dynamically imported module: /assets/X-old.js'),
              )
            : Promise.resolve(Stub),
      },
    ],
  })
}

afterEach(() => {
  vi.restoreAllMocks()
})

describe('chunkLoadRecovery', () => {
  it('reconoce los errores de carga de trozo y no otros', () => {
    expect(isChunkLoadError(new TypeError('Failed to fetch dynamically imported module: x'))).toBe(
      true,
    )
    expect(isChunkLoadError(new Error('Importing a module script failed.'))).toBe(true)
    expect(isChunkLoadError(new Error('otra cosa'))).toBe(false)
    expect(isChunkLoadError('texto')).toBe(false)
  })

  it('recarga la primera vez que falla el import() y la segunda no', async () => {
    const failing = { value: true }
    const router = makeRouter(failing)
    const storage = memoryStorage()
    const reload = vi.fn()

    installChunkLoadRecovery(router, { storage, reload })
    await router.push('/')

    await router.push('/lazy').catch(() => undefined)
    expect(reload).toHaveBeenCalledTimes(1)
    expect(reload).toHaveBeenCalledWith('/lazy')
    expect(storage.data.has(CHUNK_RELOAD_MARK)).toBe(true)

    await router.push('/lazy').catch(() => undefined)
    expect(reload).toHaveBeenCalledTimes(1)
  })

  it('limpia la marca tras una navegacion correcta', async () => {
    const failing = { value: false }
    const router = makeRouter(failing)
    const storage = memoryStorage()

    storage.setItem(CHUNK_RELOAD_MARK, '1')
    installChunkLoadRecovery(router, { storage, reload: vi.fn() })
    await router.push('/lazy')

    expect(storage.data.has(CHUNK_RELOAD_MARK)).toBe(false)
  })

  it('no recarga si sessionStorage lanza: sin marca no se descarta el bucle', async () => {
    const failing = { value: true }
    const router = makeRouter(failing)
    const throwing: MarkStorage = {
      getItem: () => {
        throw new Error('SecurityError')
      },
      setItem: () => {
        throw new Error('SecurityError')
      },
      removeItem: () => {
        throw new Error('SecurityError')
      },
    }
    const reload = vi.fn()

    installChunkLoadRecovery(router, { storage: throwing, reload })
    await router.push('/')
    await router.push('/lazy').catch(() => undefined)

    expect(reload).not.toHaveBeenCalled()
  })

  it('no recarga ante un error que no es de carga de trozo', async () => {
    const router = createRouter({
      history: createMemoryHistory(),
      routes: [
        { path: '/', component: Stub },
        { path: '/boom', component: () => Promise.reject(new Error('fallo de la vista')) },
      ],
    })
    const reload = vi.fn()

    installChunkLoadRecovery(router, { storage: memoryStorage(), reload })
    await router.push('/')
    await router.push('/boom').catch(() => undefined)

    expect(reload).not.toHaveBeenCalled()
  })
})
