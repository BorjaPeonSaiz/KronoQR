import type { RouteRecordRaw } from 'vue-router'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { useSessionStore } from '@/features/auth/session.store'
import { routes } from '@/router'
import { registerAuthGuard } from '@/router/guards'
import { managementUser } from './support/fixtures'
import { createTestPinia, createTestRouter } from './support/harness'

function leafNames(records: readonly RouteRecordRaw[]): (string | symbol | undefined)[] {
  return records.flatMap((record) =>
    'children' in record && record.children !== undefined
      ? leafNames(record.children)
      : [record.name],
  )
}

beforeEach(() => {
  window.sessionStorage.clear()
  createTestPinia()
})

afterEach(() => {
  vi.unstubAllGlobals()
})

describe('rutas de la aplicacion', () => {
  it('declara con nombre todas las rutas que se pueden visitar', () => {
    expect(leafNames(routes).every((name) => name !== undefined)).toBe(true)
  })

  it('manda al acceso a quien no tiene sesion, y recuerda a donde iba', async () => {
    const router = createTestRouter()

    registerAuthGuard(router)
    await router.push('/employees')
    await router.isReady()

    expect(router.currentRoute.value.name).toBe('login')
    expect(router.currentRoute.value.query['redirect']).toBe('/employees')
  })

  it('deja pasar al acceso sin sesion', async () => {
    const router = createTestRouter()

    registerAuthGuard(router)
    await router.push('/login')

    expect(router.currentRoute.value.name).toBe('login')
  })

  it('con sesion valida, la raiz lleva a la plantilla', async () => {
    const session = useSessionStore()

    session.user = managementUser()
    session.token = 'un-token'
    session.status = 'authenticated'

    const router = createTestRouter()

    registerAuthGuard(router)
    await router.push('/')

    expect(router.currentRoute.value.name).toBe('employees')
  })

  it('lleva a la seccion que si puede usar cuando le falta el ambito de la pedida', async () => {
    const session = useSessionStore()

    session.user = managementUser({ abilities: ['credentials:*'] })
    session.token = 'un-token'
    session.status = 'authenticated'

    const router = createTestRouter()

    registerAuthGuard(router)
    await router.push('/employees')

    expect(router.currentRoute.value.name).toBe('credentials')
  })

  it('enseña «sin permiso» cuando no hay ninguna seccion a su alcance', async () => {
    const session = useSessionStore()

    // `attendance:read` ya no vale como ejemplo: desde la 2.4 alcanza la presencia.
    session.user = managementUser({ abilities: ['incidents:*'] })
    session.token = 'un-token'
    session.status = 'authenticated'

    const router = createTestRouter()

    registerAuthGuard(router)
    await router.push('/employees')

    expect(router.currentRoute.value.name).toBe('forbidden')
  })

  it('lleva a un auditor a la exportacion para la Inspeccion, que es lo unico que alcanza', async () => {
    // El `auditor` no tiene ni plantilla ni credenciales: su token lleva
    // `attendance:read`, `audit:read` y `reports:legal` (doc 02 §7.3). Antes de
    // que existiera esta seccion, entrar con ese rol acababa en «sin permiso»
    // teniendo permiso para algo.
    const session = useSessionStore()

    session.user = managementUser({
      roles: ['auditor'],
      abilities: ['attendance:read', 'reports:legal'],
    })
    session.token = 'un-token'
    session.status = 'authenticated'

    const router = createTestRouter()

    registerAuthGuard(router)
    await router.push('/employees')

    expect(router.currentRoute.value.name).toBe('legal-export')
  })

  it('deja entrar con reports:* por el comodin de familia', async () => {
    // RRHH lleva la familia entera. Si la pantalla exigiera `reports:*` en vez
    // del ambito estrecho, quedaria fuera el auditor; exigiendo el estrecho,
    // entran los dos.
    const session = useSessionStore()

    session.user = managementUser({ abilities: ['reports:*'] })
    session.token = 'un-token'
    session.status = 'authenticated'

    const router = createTestRouter()

    registerAuthGuard(router)
    await router.push('/reports/legal-export')

    expect(router.currentRoute.value.name).toBe('legal-export')
  })

  it('abre el detalle de jornada con el ambito de solo lectura del registro', async () => {
    // El detalle de jornada (RF-PA-03) exige `attendance:read`, que es lo que
    // declara el contrato para el endpoint: el ambito estrecho de LEER. Corregir
    // es otro ambito y otra pantalla.
    const session = useSessionStore()

    session.user = managementUser({ abilities: ['attendance:read'] })
    session.token = 'un-token'
    session.status = 'authenticated'

    const router = createTestRouter()

    registerAuthGuard(router)
    await router.push('/employees/0199f0c2-1f4a-7c3e-9b21-4d5e6f7a8b90/workdays')

    expect(router.currentRoute.value.name).toBe('employee-workdays')
    expect(router.currentRoute.value.params['uuid']).toBe('0199f0c2-1f4a-7c3e-9b21-4d5e6f7a8b90')
  })

  it('no lleva al detalle de jornada a quien no puede leer el registro', async () => {
    const session = useSessionStore()

    session.user = managementUser({ abilities: ['credentials:*'] })
    session.token = 'un-token'
    session.status = 'authenticated'

    const router = createTestRouter()

    registerAuthGuard(router)
    await router.push('/employees/0199f0c2-1f4a-7c3e-9b21-4d5e6f7a8b90/workdays')

    expect(router.currentRoute.value.name).toBe('credentials')
  })

  it('resuelve una direccion desconocida sin romperse', async () => {
    const session = useSessionStore()

    session.user = managementUser()
    session.token = 'un-token'
    session.status = 'authenticated'

    const router = createTestRouter()

    registerAuthGuard(router)
    await router.push('/no-existe')

    expect(router.currentRoute.value.name).toBe('not-found')
  })
})
