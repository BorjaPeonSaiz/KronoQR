// Recuperacion de un trozo perdido tras una actualizacion (compartida por el
// panel y el portal). La marca anti-bucle la pone cada aplicacion.
import { afterEach, describe, expect, it, vi } from 'vitest'
import { createMemoryHistory, createRouter } from 'vue-router'
import {
  CHUNK_RELOAD_PARAM,
  createQueryReloadMark,
  installChunkLoadRecovery,
  isChunkLoadError,
  type ChunkReloadMark,
} from '../../src/chunkLoadRecovery'

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

/** Una marca en memoria: lo que hace el panel con el almacenamiento de su pestana. */
function memoryMark(): ChunkReloadMark & { set: boolean } {
  const mark = {
    set: false,
    claim: (target: string) => {
      if (mark.set) {
        return null
      }

      mark.set = true

      return target
    },
    clear: () => {
      mark.set = false
    },
  }

  return mark
}

afterEach(() => {
  vi.restoreAllMocks()
})

describe('isChunkLoadError', () => {
  it('reconoce los errores de carga de trozo y no otros', () => {
    expect(isChunkLoadError(new TypeError('Failed to fetch dynamically imported module: x'))).toBe(
      true,
    )
    expect(isChunkLoadError(new Error('Importing a module script failed.'))).toBe(true)
    expect(isChunkLoadError(new Error('otra cosa'))).toBe(false)
    expect(isChunkLoadError('texto')).toBe(false)
  })
})

describe('installChunkLoadRecovery', () => {
  it('recarga la primera vez que falla el import() y la segunda no', async () => {
    const router = makeRouter({ value: true })
    const reload = vi.fn()

    installChunkLoadRecovery(router, { mark: memoryMark(), reload })
    await router.push('/')

    await router.push('/lazy').catch(() => undefined)
    expect(reload).toHaveBeenCalledTimes(1)
    expect(reload).toHaveBeenCalledWith('/lazy')

    await router.push('/lazy').catch(() => undefined)
    expect(reload).toHaveBeenCalledTimes(1)
  })

  it('retira la marca tras una navegacion correcta', async () => {
    const router = makeRouter({ value: false })
    const mark = memoryMark()

    mark.set = true
    installChunkLoadRecovery(router, { mark, reload: vi.fn() })
    await router.push('/lazy')

    expect(mark.set).toBe(false)
  })

  it('no recarga si la marca no se puede garantizar', async () => {
    const router = makeRouter({ value: true })
    const reload = vi.fn()

    installChunkLoadRecovery(router, { mark: { claim: () => null, clear: () => {} }, reload })
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

    installChunkLoadRecovery(router, { mark: memoryMark(), reload })
    await router.push('/')
    await router.push('/boom').catch(() => undefined)

    expect(reload).not.toHaveBeenCalled()
  })

  it('un fallo de precarga de Vite recarga una vez y evita el error por omision', async () => {
    const router = makeRouter({ value: false })
    const reload = vi.fn()

    installChunkLoadRecovery(router, { mark: memoryMark(), reload })
    await router.push('/lazy')

    const first = new Event('vite:preloadError', { cancelable: true })

    window.dispatchEvent(first)
    expect(reload).toHaveBeenCalledWith('/lazy')
    expect(first.defaultPrevented).toBe(true)

    const second = new Event('vite:preloadError', { cancelable: true })

    window.dispatchEvent(second)
    expect(reload).toHaveBeenCalledTimes(1)
    expect(second.defaultPrevented).toBe(false)
  })
})

describe('createQueryReloadMark (sin almacenamiento)', () => {
  function fakeWindow(search: string): Window {
    return { location: { search, origin: 'https://hotel.example' } } as unknown as Window
  }

  it('la recarga lleva la marca en la URL y conserva ruta, consulta y ancla', () => {
    const mark = createQueryReloadMark(makeRouter({ value: false }), fakeWindow(''))

    expect(mark.claim('/export?from=2026-03-01#top')).toBe(
      `/export?from=2026-03-01&${CHUNK_RELOAD_PARAM}=1#top`,
    )
  })

  it('si la URL ya trae la marca no recarga otra vez: sin bucle', () => {
    const mark = createQueryReloadMark(
      makeRouter({ value: false }),
      fakeWindow(`?${CHUNK_RELOAD_PARAM}=1`),
    )

    expect(mark.claim('/records')).toBeNull()
  })

  it('al navegar con exito retira la marca de la ruta, sin tocar el almacenamiento', async () => {
    const router = makeRouter({ value: false })
    const mark = createQueryReloadMark(router, fakeWindow(''))
    const setItem = vi.spyOn(Storage.prototype, 'setItem')

    installChunkLoadRecovery(router, { mark, reload: vi.fn() })
    await router.push(`/lazy?${CHUNK_RELOAD_PARAM}=1&otra=1`)
    await vi.waitFor(() => expect(router.currentRoute.value.query).toEqual({ otra: '1' }))

    expect(setItem).not.toHaveBeenCalled()
  })
})
