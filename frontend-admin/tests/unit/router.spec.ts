import { describe, expect, it } from 'vitest'
import { createAppRouter, routes } from '@/router'

describe('rutas de la aplicacion', () => {
  it('resuelve la ruta raiz', async () => {
    const router = createAppRouter()
    await router.push('/')
    await router.isReady()

    expect(router.currentRoute.value.name).toBe('home')
  })

  it('declara todas sus rutas con nombre', () => {
    expect(routes.every((route) => route.name !== undefined)).toBe(true)
  })
})
