import { afterEach, beforeEach, describe, expect, it } from 'vitest'
import { createMemoryHistory, createRouter } from 'vue-router'
import { createAppRouter, routes } from '@/router'
import { registerAuthGuard } from '@/router/guards'
import { portalEmployee } from './support/fixtures'
import { createTestPinia } from './support/harness'

beforeEach(() => {
  window.sessionStorage.clear()
})

afterEach(() => {
  window.sessionStorage.clear()
})

function flatten(list: typeof routes): typeof routes {
  return list.flatMap((route) => [route, ...flatten(route.children ?? [])])
}

/**
 * Solo las paginas de verdad. El marco autenticado ('/') no tiene nombre a
 * proposito (ver `router/index.ts`): es un contenedor de layout, no una
 * pantalla que se pueda pedir.
 */
function pages(list: typeof routes): typeof routes {
  return flatten(list).filter((route) => route.children === undefined)
}

describe('rutas del portal', () => {
  it('resuelve la ruta raiz redirigiendo a mi registro, que es la pantalla de entrada', async () => {
    const router = createAppRouter()

    await router.push('/')
    await router.isReady()

    expect(router.currentRoute.value.name).toBe('my-records')
  })

  it('declara todas sus pantallas navegables con nombre', () => {
    expect(pages(routes).every((route) => route.name !== undefined)).toBe(true)
  })

  it('no declara ningun parametro de ruta: no hay ningun identificador de empleado que manipular', () => {
    // RF-ID-07: el empleado se resuelve del token, nunca de la URL. Si algun
    // dia una ruta de este portal lleva un `:uuid` o similar, esta prueba tiene
    // que fallar antes que un cliente pueda pedir el registro de otra persona.
    const paths = flatten(routes).map((route) => String(route.path))

    expect(paths.some((path) => path.includes(':') && !path.includes(':pathMatch'))).toBe(false)
  })

  it('el portal tiene exactamente tres pantallas: acceso, mi registro y mi exportacion', () => {
    const names = flatten(routes)
      .map((route) => route.name)
      .filter((name): name is string => typeof name === 'string')

    expect(names).toEqual(expect.arrayContaining(['login', 'my-records', 'my-export']))
  })
})

describe('guarda de acceso', () => {
  it('manda al acceso a quien no tiene sesion, y recuerda a donde iba', async () => {
    createTestPinia()
    const router = createRouter({ history: createMemoryHistory(), routes })

    registerAuthGuard(router)

    await router.push('/records')
    await router.isReady()

    expect(router.currentRoute.value.name).toBe('login')
    expect(router.currentRoute.value.query['redirect']).toBe('/records')
  })

  it('deja pasar a quien tiene sesion vigente', async () => {
    createTestPinia()
    window.sessionStorage.setItem(
      'kronoqr.portal.session',
      JSON.stringify({
        token: 'un-token',
        expiresAt: '2099-01-01T00:00:00Z',
        employee: portalEmployee(),
      }),
    )

    const router = createRouter({ history: createMemoryHistory(), routes })

    registerAuthGuard(router)

    await router.push('/export')
    await router.isReady()

    expect(router.currentRoute.value.name).toBe('my-export')
  })

  it('el acceso es publico incluso sin sesion', async () => {
    createTestPinia()
    const router = createRouter({ history: createMemoryHistory(), routes })

    registerAuthGuard(router)

    await router.push('/login')
    await router.isReady()

    expect(router.currentRoute.value.name).toBe('login')
  })
})
