import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { announcement, clearAnnouncement } from '@kronoqr/web-kit/announcer'
import AccountsView from '@/features/accounts/AccountsView.vue'
import { useSessionStore } from '@/features/auth/session.store'
import es from '@/shared/i18n/locales/es.json'
import {
  ACCOUNT_UUID,
  SITE,
  managementAccount,
  managementAccountCollection,
  managementUser,
  temporaryPassword,
} from './support/fixtures'
import {
  createTestPinia,
  jsonResponse,
  mountView,
  problemResponse,
  settle,
  stubFetch,
} from './support/harness'

const OWN_UUID = '0199f0aa-1111-7000-8000-0123456789ab'

const OWN = managementAccount({
  uuid: OWN_UUID,
  name: 'Administración',
  email: 'admin@hotel.example',
  roles: ['admin'],
  scope: { kind: 'all', department_ids: [] },
})
const COOK = managementAccount({ password_status: 'temporary', last_login_at: null })
const GONE = managementAccount({
  uuid: '0199f0c2-1f4a-7c3e-9b21-4d5e6f7a8b99',
  name: 'Ex Recepción',
  email: 'ex@hotel.example',
  status: 'deactivated',
})

function mountAsAdmin() {
  const pinia = createTestPinia()
  const session = useSessionStore(pinia)

  session.user = managementUser({ uuid: OWN_UUID, roles: ['admin'], abilities: ['accounts:*'] })
  session.token = 'un-token'
  session.status = 'authenticated'

  return mountView(AccountsView, { pinia })
}

function routes(
  list: unknown,
  extra: (url: string, init?: RequestInit) => Response | null = () => null,
) {
  return (url: string, init?: RequestInit) => {
    const custom = extra(url, init)

    if (custom !== null) {
      return custom
    }

    if (url.startsWith('/api/v1/site')) {
      return jsonResponse(SITE)
    }

    return jsonResponse(list)
  }
}

beforeEach(() => {
  window.sessionStorage.clear()
  window.localStorage.clear()
  clearAnnouncement()
})

afterEach(() => {
  vi.unstubAllGlobals()
})

describe('AccountsView', () => {
  it('anuncia que esta cargando antes de tener datos', async () => {
    stubFetch(() => new Promise<Response>(() => {}))

    const wrapper = await mountAsAdmin()

    expect(wrapper.find('[role="status"]').text()).toContain(es.accounts.loading)
  })

  it('pinta las siete columnas con encabezados asociados y la zona horaria del centro', async () => {
    stubFetch(routes(managementAccountCollection([OWN, COOK, GONE])))

    const wrapper = await mountAsAdmin()

    await settle()

    expect(wrapper.find('caption').text()).toContain('Europe/Madrid')
    const headings = wrapper.findAll('thead th[scope="col"]').map((th) => th.text())

    for (const key of ['name', 'email', 'role', 'status', 'twoFactor', 'password', 'lastLogin']) {
      expect(headings.some((text) => text.includes(es.accounts.table[key as 'name']))).toBe(true)
    }

    expect(wrapper.findAll('tbody th[scope="row"]')).toHaveLength(3)
    expect(wrapper.text()).toContain(es.accounts.password.temporary)
    expect(wrapper.text()).toContain(es.accounts.table.neverLoggedIn)
    expect(wrapper.text()).toContain(es.accounts.status.deactivated)
    // El ultimo acceso se pinta en la zona del centro, con la zona escrita.
    expect(wrapper.text()).toMatch(/9:42|09:42/)
  })

  it('no enseña ningun secreto en la lista', async () => {
    stubFetch(routes(managementAccountCollection([COOK])))

    const wrapper = await mountAsAdmin()

    await settle()

    expect(wrapper.text()).not.toMatch(/password_hash|secret|Kd2p/i)
  })

  it('una cuenta de baja no ofrece acciones', async () => {
    stubFetch(routes(managementAccountCollection([GONE])))

    const wrapper = await mountAsAdmin()

    await settle()

    expect(wrapper.find(`[data-test="deactivate-${GONE.uuid}"]`).exists()).toBe(false)
    expect(wrapper.text()).toContain(es.accounts.table.noActions)
  })

  it('sobre la propia cuenta las acciones llevan explicacion y no abren el dialogo', async () => {
    stubFetch(routes(managementAccountCollection([OWN])))

    const wrapper = await mountAsAdmin()

    await settle()

    const button = wrapper.find(`[data-test="deactivate-${OWN_UUID}"]`)

    // Sigue en el orden de tabulacion (no es `disabled`) y apunta a su explicacion.
    expect(button.attributes('disabled')).toBeUndefined()
    expect(button.attributes('aria-disabled')).toBe('true')

    const describedBy = button.attributes('aria-describedby') ?? ''

    expect(wrapper.find(`#${describedBy}`).text()).toBe(es.accounts.blocked.own)

    await button.trigger('click')

    expect(wrapper.find('[role="dialog"]').exists()).toBe(false)

    await wrapper.find(`[data-test="resetPassword-${OWN_UUID}"]`).trigger('click')

    expect(wrapper.find('[role="dialog"]').exists()).toBe(false)
    expect(wrapper.text()).toContain(es.accounts.blocked.ownPassword)
  })

  it('no ofrece retirar un segundo factor que no existe', async () => {
    stubFetch(
      routes(managementAccountCollection([managementAccount({ two_factor_enabled: false })])),
    )

    const wrapper = await mountAsAdmin()

    await settle()
    await wrapper.find(`[data-test="resetTwoFactor-${ACCOUNT_UUID}"]`).trigger('click')

    expect(wrapper.text()).toContain(es.accounts.blocked.noTwoFactor)
    expect(wrapper.find('[role="dialog"]').exists()).toBe(false)
  })

  it('pide al servidor la busqueda y los filtros y reinicia la pagina', async () => {
    const spy = stubFetch(routes(managementAccountCollection([COOK], 60)))

    const wrapper = await mountAsAdmin()

    await settle()
    await wrapper.find('#accounts-status-filter').setValue('active')
    await wrapper.find('#accounts-role-filter').setValue('admin')
    await settle()

    const urls = spy.mock.calls.map((call) => String(call[0]))

    expect(urls.some((url) => url.includes('status=active') && url.includes('role=admin'))).toBe(
      true,
    )
    expect(urls.every((url) => !url.includes('per_page=1000'))).toBe(true)
  })

  it('explica un vacio con filtros distinto de un vacio de verdad y anuncia el total', async () => {
    stubFetch(routes(managementAccountCollection([])))

    const wrapper = await mountAsAdmin()

    await settle()
    expect(wrapper.text()).toContain(es.accounts.empty.description)
    expect(announcement.value).toContain('0')

    await wrapper.find('#accounts-role-filter').setValue('auditor')
    await settle()

    expect(wrapper.text()).toContain(es.accounts.empty.filtered)
  })

  it('un fallo de red dice que ha pasado y que hacer', async () => {
    stubFetch(() => {
      throw new TypeError('fallo de red')
    })

    const wrapper = await mountAsAdmin()

    await settle()

    expect(wrapper.find('[role="alert"]').text()).toContain(es.errors.network.title)
    expect(wrapper.find('[role="alert"]').text()).toContain(es.errors.network.advice)
  })

  it('el alta ensena la contrasena temporal una vez, sin guardarla, y al cerrar desaparece', async () => {
    const created = {
      account: managementAccount({
        uuid: '0199f0c2-1f4a-7c3e-9b21-4d5e6f7a8b92',
        name: 'Dirección RRHH',
      }),
      temporary_password: temporaryPassword(),
    }
    const spy = stubFetch(
      routes(managementAccountCollection([OWN]), (url, init) =>
        url.endsWith('/management-accounts') && init?.method === 'POST'
          ? jsonResponse(created, 201)
          : null,
      ),
    )

    const wrapper = await mountAsAdmin()

    await settle()
    await wrapper.find('[data-test="create-account"]').trigger('click')
    await wrapper.find('[data-test="account-name"]').setValue('Dirección RRHH')
    await wrapper.find('[data-test="account-email"]').setValue('rrhh@hotel.example')
    await wrapper.find('[data-test="account-role"]').setValue('rrhh')
    await wrapper.find('[data-test="actor-reauth"]').setValue('123456')
    await wrapper.find('#account-create-form').trigger('submit')
    await settle()

    const post = spy.mock.calls.find(
      (call) => (call[1] as RequestInit | undefined)?.method === 'POST',
    )

    expect(JSON.parse(String((post?.[1] as RequestInit).body))).toEqual({
      name: 'Dirección RRHH',
      email: 'rrhh@hotel.example',
      role: 'rrhh',
      locale: 'es',
      actor_totp_code: '123456',
    })
    expect(wrapper.find('[data-test="password-value"]').text()).toBe('Kd2pQ9vLmN4tZbYc#F1w')
    expect(wrapper.text()).toContain(es.accounts.reveal.onlyOnce)
    expect(announcement.value).toContain('Dirección RRHH')

    // Ni en el almacenamiento del navegador ni a la vista tras cerrar.
    const stored = [
      ...Object.values(window.sessionStorage),
      ...Object.values(window.localStorage),
    ].join(' ')

    expect(stored).not.toContain('Kd2pQ9vLmN4tZbYc')

    await wrapper.find('[data-test="handed-over"]').setValue(true)
    await wrapper.find('[data-test="acknowledge"]').trigger('click')
    await settle()

    expect(wrapper.find('[data-test="password-value"]').exists()).toBe(false)
    expect(wrapper.html()).not.toContain('Kd2pQ9vLmN4tZbYc')
  })

  it('un correo repetido (409) se explica junto al campo y no cierra el alta', async () => {
    stubFetch(
      routes(managementAccountCollection([OWN]), (url, init) =>
        url.endsWith('/management-accounts') && init?.method === 'POST'
          ? problemResponse(409, 'urn:kronoqr:problem:conflict')
          : null,
      ),
    )

    const wrapper = await mountAsAdmin()

    await settle()
    await wrapper.find('[data-test="create-account"]').trigger('click')
    await wrapper.find('[data-test="account-name"]').setValue('Otra')
    await wrapper.find('[data-test="account-email"]').setValue('admin@hotel.example')
    await wrapper.find('[data-test="actor-reauth"]').setValue('123456')
    await wrapper.find('#account-create-form').trigger('submit')
    await settle()

    expect(wrapper.find('[role="dialog"]').text()).toContain(es.accounts.create.emailTaken)
    expect(wrapper.find('[data-test="password-value"]').exists()).toBe(false)
  })

  it('la baja pide motivo, enseña el antes y el despues y manda el motivo', async () => {
    const spy = stubFetch(
      routes(managementAccountCollection([OWN, COOK]), (url, init) =>
        url.endsWith(`/${ACCOUNT_UUID}/deactivate`) && init?.method === 'POST'
          ? jsonResponse({ ...COOK, status: 'deactivated' })
          : null,
      ),
    )

    const wrapper = await mountAsAdmin()

    await settle()
    await wrapper.find(`[data-test="deactivate-${ACCOUNT_UUID}"]`).trigger('click')

    const dialog = wrapper.find('[role="dialog"]')
    const rowTexts = dialog.findAll('tbody tr').map((row) => row.text())

    expect(rowTexts[0]).toContain(es.accounts.status.active)
    expect(rowTexts[0]).toContain(es.accounts.status.deactivated)

    const confirm = dialog
      .findAll('button')
      .find((b) => b.text() === es.accounts.actions.deactivate.confirm)

    expect(confirm?.attributes('disabled')).toBeDefined()

    await dialog.find('[data-test="account-reason"]').setValue('Deja el hotel')
    expect(confirm?.attributes('disabled')).toBeUndefined()
    await confirm?.trigger('click')
    await settle()

    const post = spy.mock.calls.find((call) => String(call[0]).endsWith('/deactivate'))

    expect(JSON.parse(String((post?.[1] as RequestInit).body))).toEqual({ reason: 'Deja el hotel' })
    expect(wrapper.find('[role="dialog"]').exists()).toBe(false)
    expect(announcement.value).toContain('Jefatura de Cocina')
  })

  it('un 409 de la baja (ultima admin) se queda en el dialogo y lo explica', async () => {
    stubFetch(
      routes(managementAccountCollection([OWN, COOK]), (url, init) =>
        url.endsWith('/deactivate') && init?.method === 'POST'
          ? problemResponse(409, 'urn:kronoqr:problem:conflict')
          : null,
      ),
    )

    const wrapper = await mountAsAdmin()

    await settle()
    await wrapper.find(`[data-test="deactivate-${ACCOUNT_UUID}"]`).trigger('click')
    await wrapper.find('[data-test="account-reason"]').setValue('Prueba')
    await wrapper
      .find('[role="dialog"]')
      .findAll('button')
      .find((b) => b.text() === es.accounts.actions.deactivate.confirm)
      ?.trigger('click')
    await settle()

    const dialog = wrapper.find('[role="dialog"]')

    expect(dialog.exists()).toBe(true)
    expect(dialog.find('[role="alert"]').text()).toContain(es.errors.conflict.title)
    expect(dialog.find('[data-test="conflict-hint"]').text()).toBe(
      es.accounts.actions.deactivate.conflictHint,
    )
  })

  it('un 404 (ya de baja) cierra el dialogo, relee la lista y lo anuncia', async () => {
    const spy = stubFetch(
      routes(managementAccountCollection([OWN, COOK]), (url, init) =>
        url.endsWith('/deactivate') && init?.method === 'POST'
          ? problemResponse(404, 'urn:kronoqr:problem:not-found')
          : null,
      ),
    )

    const wrapper = await mountAsAdmin()

    await settle()

    const before = spy.mock.calls.length

    await wrapper.find(`[data-test="deactivate-${ACCOUNT_UUID}"]`).trigger('click')
    await wrapper.find('[data-test="account-reason"]').setValue('Prueba')
    await wrapper
      .find('[role="dialog"]')
      .findAll('button')
      .find((b) => b.text() === es.accounts.actions.deactivate.confirm)
      ?.trigger('click')
    await settle()

    expect(wrapper.find('[role="dialog"]').exists()).toBe(false)
    expect(announcement.value).toBe(es.accounts.announce.gone)
    expect(spy.mock.calls.length).toBeGreaterThan(before + 1)
  })

  it('restablecer la contrasena pide motivo y reautenticacion y enseña la nueva una sola vez', async () => {
    stubFetch(
      routes(managementAccountCollection([OWN, COOK]), (url, init) =>
        url.endsWith('/password/reset') && init?.method === 'POST'
          ? jsonResponse(temporaryPassword({ password: 'Ts7#hQx4WmPa9eRz=Kn2' }))
          : null,
      ),
    )

    const wrapper = await mountAsAdmin()

    await settle()
    await wrapper.find(`[data-test="resetPassword-${ACCOUNT_UUID}"]`).trigger('click')

    const reasonField = wrapper.find('[data-test="account-reason"]')

    expect(reasonField.exists()).toBe(true)
    await reasonField.setValue('Olvido')
    await wrapper.find('[data-test="actor-reauth"]').setValue('123456')

    await wrapper
      .find('[role="dialog"]')
      .findAll('button')
      .find((b) => b.text() === es.accounts.actions.resetPassword.confirm)
      ?.trigger('click')
    await settle()

    expect(wrapper.find('[data-test="password-value"]').text()).toBe('Ts7#hQx4WmPa9eRz=Kn2')
  })

  it('el segundo factor se retira con motivo obligatorio', async () => {
    const spy = stubFetch(
      routes(managementAccountCollection([OWN, COOK]), (url, init) =>
        url.endsWith('/two-factor/reset') && init?.method === 'POST'
          ? jsonResponse({ ...COOK, two_factor_enabled: false })
          : null,
      ),
    )

    const wrapper = await mountAsAdmin()

    await settle()
    await wrapper.find(`[data-test="resetTwoFactor-${ACCOUNT_UUID}"]`).trigger('click')

    const confirm = wrapper
      .find('[role="dialog"]')
      .findAll('button')
      .find((b) => b.text() === es.accounts.actions.resetTwoFactor.confirm)

    expect(confirm?.attributes('disabled')).toBeDefined()

    await wrapper.find('[data-test="account-reason"]').setValue('Teléfono extraviado')
    await wrapper.find('[data-test="actor-reauth"]').setValue('654321')
    await confirm?.trigger('click')
    await settle()

    const post = spy.mock.calls.find((call) => String(call[0]).endsWith('/two-factor/reset'))

    expect(JSON.parse(String((post?.[1] as RequestInit).body))).toEqual({
      reason: 'Teléfono extraviado',
      actor_totp_code: '654321',
    })
    expect(announcement.value).toContain('Jefatura de Cocina')
  })
})

describe('AccountsView: reautenticacion de quien actua', () => {
  async function openResetTwoFactor(wrapper: Awaited<ReturnType<typeof mountAsAdmin>>) {
    await settle()
    await wrapper.find(`[data-test="resetTwoFactor-${ACCOUNT_UUID}"]`).trigger('click')
    await wrapper.find('[data-test="account-reason"]').setValue('Teléfono extraviado')
  }

  function confirmButton(wrapper: Awaited<ReturnType<typeof mountAsAdmin>>) {
    return wrapper
      .find('[role="dialog"]')
      .findAll('button')
      .find((b) => b.text() === es.accounts.actions.resetTwoFactor.confirm)
  }

  it('no deja confirmar sin el codigo de seis cifras', async () => {
    stubFetch(routes(managementAccountCollection([OWN, COOK])))

    const wrapper = await mountAsAdmin()

    await openResetTwoFactor(wrapper)
    expect(confirmButton(wrapper)?.attributes('disabled')).toBeDefined()

    await wrapper.find('[data-test="actor-reauth"]').setValue('12ab')
    expect(confirmButton(wrapper)?.attributes('disabled')).toBeDefined()
  })

  it('si la cuenta de quien actua no tiene segundo factor, pide su contraseña actual', async () => {
    const pinia = createTestPinia()
    const session = useSessionStore(pinia)

    session.user = managementUser({
      uuid: OWN_UUID,
      roles: ['admin'],
      abilities: ['accounts:*'],
      two_factor_enabled: false,
    })
    session.token = 'un-token'
    session.status = 'authenticated'

    const spy = stubFetch(
      routes(managementAccountCollection([OWN, COOK]), (url, init) =>
        url.endsWith('/two-factor/reset') && init?.method === 'POST'
          ? jsonResponse({ ...COOK, two_factor_enabled: false })
          : null,
      ),
    )
    const wrapper = await mountView(AccountsView, { pinia })

    await openResetTwoFactor(wrapper)
    expect(wrapper.find('[role="dialog"]').text()).toContain(es.accounts.reauth.passwordLabel)

    await wrapper.find('[data-test="actor-reauth"]').setValue('mi-clave-actual')
    await confirmButton(wrapper)?.trigger('click')
    await settle()

    const post = spy.mock.calls.find((call) => String(call[0]).endsWith('/two-factor/reset'))

    expect(JSON.parse(String((post?.[1] as RequestInit).body))).toEqual({
      reason: 'Teléfono extraviado',
      actor_current_password: 'mi-clave-actual',
    })
  })

  it('un 422 en el codigo se pinta junto al campo, que se vacia', async () => {
    stubFetch(
      routes(managementAccountCollection([OWN, COOK]), (url, init) =>
        url.endsWith('/two-factor/reset') && init?.method === 'POST'
          ? problemResponse(422, 'urn:kronoqr:problem:validation-failed', {
              errors: { actor_totp_code: ['El código no es correcto.'] },
            })
          : null,
      ),
    )

    const wrapper = await mountAsAdmin()

    await openResetTwoFactor(wrapper)
    await wrapper.find('[data-test="actor-reauth"]').setValue('123456')
    await confirmButton(wrapper)?.trigger('click')
    await settle()

    const dialog = wrapper.find('[role="dialog"]')

    expect(dialog.text()).toContain('El código no es correcto.')
    expect(dialog.text()).not.toContain('actor_totp_code')
    expect((dialog.find('[data-test="actor-reauth"]').element as HTMLInputElement).value).toBe('')
  })

  it('un 429 enseña la cuenta atras y bloquea el reenvio', async () => {
    stubFetch(
      routes(managementAccountCollection([OWN, COOK]), (url, init) =>
        url.endsWith('/two-factor/reset') && init?.method === 'POST'
          ? new Response(
              JSON.stringify({ type: 'urn:kronoqr:problem:rate-limited', title: 'x', status: 429 }),
              {
                status: 429,
                headers: { 'Content-Type': 'application/problem+json', 'Retry-After': '90' },
              },
            )
          : null,
      ),
    )

    const wrapper = await mountAsAdmin()

    await openResetTwoFactor(wrapper)
    await wrapper.find('[data-test="actor-reauth"]').setValue('123456')
    await confirmButton(wrapper)?.trigger('click')
    await settle()
    await wrapper.find('[data-test="actor-reauth"]').setValue('654321')

    expect(wrapper.find('[data-test="retry-countdown"]').text()).toContain('90')
    expect(confirmButton(wrapper)?.attributes('disabled')).toBeDefined()
  })

  it('la baja no pide reautenticacion', async () => {
    stubFetch(routes(managementAccountCollection([OWN, COOK])))

    const wrapper = await mountAsAdmin()

    await settle()
    await wrapper.find(`[data-test="deactivate-${ACCOUNT_UUID}"]`).trigger('click')

    expect(wrapper.find('[data-test="actor-reauth"]').exists()).toBe(false)
  })
})

describe('AccountsView: responsable sin departamento', () => {
  function withDepartments(managerUuid: string | null) {
    stubFetch((url) =>
      url.startsWith('/api/v1/departments')
        ? jsonResponse({ data: [{ id: 3, name: 'Cocina', manager_user_uuid: managerUuid }] })
        : url.startsWith('/api/v1/site')
          ? jsonResponse(SITE)
          : jsonResponse(managementAccountCollection([OWN, COOK])),
    )
  }

  it('avisa del responsable activo que no dirige ningun departamento', async () => {
    withDepartments(null)

    const wrapper = await mountAsAdmin()

    await settle()

    expect(wrapper.findAll('[data-test="reaches-nobody"]')).toHaveLength(1)
    expect(wrapper.find(`[data-test="account-${ACCOUNT_UUID}"]`).text()).toContain(
      es.accounts.table.reachesNobody,
    )
  })

  it('no avisa si ya dirige un departamento', async () => {
    withDepartments(ACCOUNT_UUID)

    const wrapper = await mountAsAdmin()

    await settle()

    expect(wrapper.find('[data-test="reaches-nobody"]').exists()).toBe(false)
  })
})
