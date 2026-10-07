import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import ChangePasswordView from '@/features/auth/ChangePasswordView.vue'
import { useSessionStore } from '@/features/auth/session.store'
import { registerAuthGuard } from '@/router/guards'
import { installPasswordChangeInterceptor } from '@/shared/api/passwordChangeInterceptor'
import es from '@/shared/i18n/locales/es.json'
import AppShellView from '@/shared/ui/AppShellView.vue'
import { managementUser, setupStatus } from './support/fixtures'
import {
  createTestPinia,
  createTestRouter,
  jsonResponse,
  mountView,
  problemResponse,
  settle,
  stubFetch,
} from './support/harness'

const PASSWORD_CHANGE_TYPE = 'urn:kronoqr:problem:password-change-required'

function sessionWith(passwordChangeRequired: boolean) {
  const pinia = createTestPinia()
  const session = useSessionStore(pinia)

  session.user = managementUser({ password_change_required: passwordChangeRequired })
  session.token = 'un-token'
  session.status = 'authenticated'

  return { pinia, session }
}

beforeEach(() => {
  window.sessionStorage.clear()
})

afterEach(() => {
  vi.unstubAllGlobals()
})

describe('flujo obligado de cambio de contraseña (RF-ID-10)', () => {
  it('con password_change_required, cualquier ruta lleva al cambio de contraseña', async () => {
    stubFetch(() => jsonResponse(setupStatus({ available: false })))
    sessionWith(true)

    const router = createTestRouter()

    registerAuthGuard(router)
    await router.push('/employees')
    await router.isReady()

    expect(router.currentRoute.value.name).toBe('change-password')

    await router.push('/credentials')

    expect(router.currentRoute.value.name).toBe('change-password')
  })

  it('sin la marca, la ruta del cambio sigue siendo accesible (cambio voluntario)', async () => {
    stubFetch(() => jsonResponse(setupStatus({ available: false })))
    sessionWith(false)

    const router = createTestRouter()

    registerAuthGuard(router)
    await router.push('/account/password')
    await router.isReady()

    expect(router.currentRoute.value.name).toBe('change-password')
  })

  it('un 403 password-change-required en cualquier peticion avisa una vez y no altera la respuesta', async () => {
    const onRequired = vi.fn()
    const original = globalThis.fetch

    stubFetch(() => problemResponse(403, PASSWORD_CHANGE_TYPE))

    const stubbed = globalThis.fetch
    const uninstall = installPasswordChangeInterceptor(onRequired)

    try {
      const response = await fetch('/api/v1/employees')

      expect(response.status).toBe(403)
      expect((await response.json()).type).toBe(PASSWORD_CHANGE_TYPE)
      expect(onRequired).toHaveBeenCalledTimes(1)
    } finally {
      uninstall()
    }

    expect(globalThis.fetch).toBe(stubbed)
    expect(original).toBeDefined()
  })

  it('otro 403 u otro estado no avisan', async () => {
    const onRequired = vi.fn()

    stubFetch((url) =>
      url.endsWith('/a') ? problemResponse(403, 'urn:kronoqr:problem:forbidden') : jsonResponse({}),
    )

    const uninstall = installPasswordChangeInterceptor(onRequired)

    try {
      await fetch('/a')
      await fetch('/b')
    } finally {
      uninstall()
    }

    expect(onRequired).not.toHaveBeenCalled()
  })

  it('el marco no ofrece secciones mientras la contraseña sea temporal, solo cerrar sesión', async () => {
    const { pinia } = sessionWith(true)
    const router = createTestRouter()

    await router.push('/forbidden')

    const wrapper = await mountView(AppShellView, { pinia, router })

    expect(wrapper.find('nav').exists()).toBe(false)
    expect(wrapper.text()).toContain(es.app.signOut)
    expect(wrapper.text()).not.toContain(es.app.changePassword)
  })

  it('con sesion normal el marco ofrece el enlace al cambio de contraseña', async () => {
    const { pinia } = sessionWith(false)
    const router = createTestRouter()

    await router.push('/forbidden')

    const wrapper = await mountView(AppShellView, { pinia, router })

    expect(wrapper.find('a[href="/account/password"]').text()).toBe(es.app.changePassword)
  })
})

describe('ChangePasswordView', () => {
  async function fill(
    wrapper: Awaited<ReturnType<typeof mountView>>,
    values: { current?: string; next?: string; confirm?: string },
  ) {
    await wrapper.find('[data-test="current-password"]').setValue(values.current ?? 'Temporal#1')
    await wrapper.find('[data-test="new-password"]').setValue(values.next ?? 'Una-propia-larga-9!')
    await wrapper
      .find('[data-test="confirm-password"]')
      .setValue(values.confirm ?? values.next ?? 'Una-propia-larga-9!')
  }

  it('no deja enviar con la confirmacion distinta y lo dice junto al campo', async () => {
    const { pinia } = sessionWith(true)
    const wrapper = await mountView(ChangePasswordView, { pinia })

    await fill(wrapper, { confirm: 'otra-cosa' })

    expect(wrapper.text()).toContain(es.changePassword.mismatch)
    expect(wrapper.find('button[type="submit"]').attributes('disabled')).toBeDefined()
  })

  it('no deja una contraseña nueva igual a la actual', async () => {
    const { pinia } = sessionWith(true)
    const wrapper = await mountView(ChangePasswordView, { pinia })

    await fill(wrapper, { current: 'misma-Clave-1!', next: 'misma-Clave-1!' })

    expect(wrapper.text()).toContain(es.changePassword.sameAsCurrent)
    expect(wrapper.find('button[type="submit"]').attributes('disabled')).toBeDefined()
  })

  it('al cambiarla relee /auth/me, quita la marca y sale hacia el panel', async () => {
    const { pinia, session } = sessionWith(true)
    const router = createTestRouter()

    await router.push('/account/password')

    let changed = false
    const spy = stubFetch((url, init) => {
      if (url.endsWith('/auth/password') && init?.method === 'POST') {
        changed = true

        return new Response(null, { status: 204 })
      }

      if (url.endsWith('/auth/me')) {
        return jsonResponse(managementUser({ password_change_required: !changed }))
      }

      return jsonResponse({ data: [], meta: { page: 1, per_page: 30, total: 0, total_pages: 1 } })
    })

    const wrapper = await mountView(ChangePasswordView, { pinia, router })

    await fill(wrapper, {})
    await wrapper.find('form').trigger('submit')
    await settle()

    const post = spy.mock.calls.find((call) => String(call[0]).endsWith('/auth/password'))

    expect(JSON.parse(String((post?.[1] as RequestInit).body))).toEqual({
      current_password: 'Temporal#1',
      new_password: 'Una-propia-larga-9!',
    })
    expect(session.passwordChangeRequired).toBe(false)
    expect(router.currentRoute.value.name).not.toBe('change-password')
  })

  it('una contraseña actual incorrecta es un 422 junto al campo y no cierra la sesión', async () => {
    const { pinia, session } = sessionWith(false)

    stubFetch(() =>
      problemResponse(422, 'urn:kronoqr:problem:validation-failed', {
        errors: { current_password: ['La contraseña actual no es correcta.'] },
      }),
    )

    const wrapper = await mountView(ChangePasswordView, { pinia })

    await fill(wrapper, {})
    await wrapper.find('form').trigger('submit')
    await settle()

    expect(wrapper.text()).toContain('La contraseña actual no es correcta.')
    expect(session.isAuthenticated).toBe(true)
  })

  it('una regla de la politica (422) se enseña junto a la contraseña nueva', async () => {
    const { pinia } = sessionWith(false)

    stubFetch(() =>
      problemResponse(422, 'urn:kronoqr:problem:validation-failed', {
        errors: { new_password: ['La contraseña debe tener al menos 12 caracteres.'] },
      }),
    )

    const wrapper = await mountView(ChangePasswordView, { pinia })

    await fill(wrapper, { next: 'Corta-1!' })
    await wrapper.find('form').trigger('submit')
    await settle()

    expect(wrapper.text()).toContain('al menos 12 caracteres')
  })

  it('un bloqueo por intentos (429) dice cuanto esperar', async () => {
    const { pinia } = sessionWith(false)

    stubFetch(
      () =>
        new Response(
          JSON.stringify({ type: 'urn:kronoqr:problem:rate-limited', title: 'x', status: 429 }),
          {
            status: 429,
            headers: { 'Content-Type': 'application/problem+json', 'Retry-After': '90' },
          },
        ),
    )

    const wrapper = await mountView(ChangePasswordView, { pinia })

    await fill(wrapper, {})
    await wrapper.find('form').trigger('submit')
    await settle()

    expect(wrapper.find('[role="alert"]').text()).toContain(es.errors.rateLimited.title)
    expect(wrapper.find('[role="alert"]').text()).toContain('90')
  })
})

describe('ChangePasswordView: casos del servidor', () => {
  it('no deja enviar una contraseña de mas de 72 bytes y lo explica', async () => {
    const { pinia } = sessionWith(false)
    const wrapper = await mountView(ChangePasswordView, { pinia })
    const long = 'ñ'.repeat(40) + 'Aa1!'

    await wrapper.find('[data-test="current-password"]').setValue('Temporal#1')
    await wrapper.find('[data-test="new-password"]').setValue(long)
    await wrapper.find('[data-test="confirm-password"]').setValue(long)

    expect(wrapper.text()).toContain(es.changePassword.tooLong)
    expect(wrapper.find('button[type="submit"]').attributes('disabled')).toBeDefined()
  })

  it('un 409 (la contraseña cambio entretanto) tiene su propio mensaje', async () => {
    const { pinia } = sessionWith(false)

    stubFetch(() => problemResponse(409, 'urn:kronoqr:problem:conflict'))

    const wrapper = await mountView(ChangePasswordView, { pinia })

    await wrapper.find('[data-test="current-password"]').setValue('Temporal#1')
    await wrapper.find('[data-test="new-password"]').setValue('Una-propia-larga-9!')
    await wrapper.find('[data-test="confirm-password"]').setValue('Una-propia-larga-9!')
    await wrapper.find('form').trigger('submit')
    await settle()

    expect(wrapper.find('[data-test="password-conflict"]').text()).toBe(es.changePassword.conflict)
  })
})
