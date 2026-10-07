import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { announcement, clearAnnouncement } from '@kronoqr/web-kit/announcer'
import DepartmentsView from '@/features/departments/DepartmentsView.vue'
import { useSessionStore } from '@/features/auth/session.store'
import es from '@/shared/i18n/locales/es.json'
import {
  ACCOUNT_UUID,
  managementAccount,
  managementAccountCollection,
  managementUser,
} from './support/fixtures'
import {
  buttonWith,
  createTestPinia,
  jsonResponse,
  mountView,
  problemResponse,
  settle,
  stubFetch,
} from './support/harness'

const COOK = managementAccount()
const GONE = managementAccount({
  uuid: '0199f0c2-1f4a-7c3e-9b21-4d5e6f7a8b99',
  name: 'Ex Jefatura',
  status: 'deactivated',
})
const OTHER = managementAccount({
  uuid: '0199f0c2-1f4a-7c3e-9b21-4d5e6f7a8b77',
  name: 'Jefatura de Sala',
})

function departments(managerUuid: string | null = null, managerName: string | null = null) {
  return {
    data: [
      { id: 3, name: 'Cocina', manager_user_uuid: managerUuid, manager_name: managerName },
      { id: 4, name: 'Recepción' },
    ],
  }
}

function mountAs(abilities: string[]) {
  const pinia = createTestPinia()
  const session = useSessionStore(pinia)

  session.user = managementUser({ abilities })
  session.token = 'un-token'
  session.status = 'authenticated'

  return mountView(DepartmentsView, { pinia })
}

function api(
  deps: unknown,
  patch: (init: RequestInit | undefined) => Response = () => jsonResponse({}),
) {
  return (url: string, init?: RequestInit) => {
    if (url.startsWith('/api/v1/management-accounts')) {
      // Como el servidor: respeta `status`.
      return jsonResponse(
        managementAccountCollection(
          [COOK, GONE, OTHER].filter(
            (account) => !url.includes('status=active') || account.status === 'active',
          ),
        ),
      )
    }

    if (init?.method === 'PATCH') {
      return patch(init)
    }

    return jsonResponse(deps)
  }
}

function patches(spy: ReturnType<typeof stubFetch>) {
  return spy.mock.calls.filter((call) => (call[1] as RequestInit | undefined)?.method === 'PATCH')
}

beforeEach(() => {
  window.sessionStorage.clear()
  clearAnnouncement()
})

afterEach(() => {
  vi.unstubAllGlobals()
})

describe('DepartmentsView', () => {
  it('admin: muestra el responsable actual por su nombre y ofrece solo cuentas activas', async () => {
    stubFetch(api(departments(ACCOUNT_UUID, 'Jefatura de Cocina')))

    const wrapper = await mountAs(['accounts:*', 'employees:*'])

    await settle()

    expect(
      wrapper.find('[data-test="department-3"] [data-test="department-manager"]').text(),
    ).toContain('Jefatura de Cocina')
    expect(wrapper.find('[data-test="department-4"]').text()).toContain(es.departments.noManager)

    const options = wrapper.findAll('#manager-3 option').map((option) => option.text())

    expect(options).toEqual([es.departments.noManager, 'Jefatura de Cocina', 'Jefatura de Sala'])
  })

  it('admin: avisa si el responsable actual esta de baja', async () => {
    stubFetch(api(departments(GONE.uuid)))

    const wrapper = await mountAs(['accounts:*', 'employees:*'])

    await settle()

    expect(wrapper.find('[data-test="department-manager-deactivated"]').text()).toBe(
      es.departments.deactivatedHint,
    )
  })

  it('guarda el cambio con manager_user_uuid y lo anuncia; «sin responsable» manda null', async () => {
    const spy = stubFetch(api(departments(ACCOUNT_UUID)))

    const wrapper = await mountAs(['accounts:*', 'employees:*'])

    await settle()
    expect(wrapper.find('[data-test="save-manager-3"]').attributes('disabled')).toBeDefined()

    await wrapper.find('#manager-3').setValue(OTHER.uuid)
    expect(wrapper.text()).toContain(es.departments.displaceNotice)
    await wrapper.find('[data-test="save-manager-3"]').trigger('click')
    await settle()

    const first = patches(spy)[0]

    expect(String(first?.[0])).toBe('/api/v1/departments/3')
    expect(JSON.parse(String((first?.[1] as RequestInit).body))).toEqual({
      manager_user_uuid: OTHER.uuid,
    })
    expect(announcement.value).toContain('Cocina')

    await wrapper.find('#manager-3').setValue('')
    await wrapper.find('[data-test="save-manager-3"]').trigger('click')
    await settle()

    const last = patches(spy).at(-1)

    expect(JSON.parse(String((last?.[1] as RequestInit).body))).toEqual({
      manager_user_uuid: null,
    })
  })

  it('un 422 en manager_user_uuid se pinta junto al selector', async () => {
    stubFetch(
      api(departments(), () =>
        problemResponse(422, 'urn:kronoqr:problem:validation-failed', {
          errors: { manager_user_uuid: ['La cuenta no puede dirigir un departamento.'] },
        }),
      ),
    )

    const wrapper = await mountAs(['accounts:*', 'employees:*'])

    await settle()
    await wrapper.find('#manager-3').setValue(OTHER.uuid)
    await wrapper.find('[data-test="save-manager-3"]').trigger('click')
    await settle()

    expect(wrapper.find('#manager-error-3').text()).toBe(
      'La cuenta no puede dirigir un departamento.',
    )
    expect(wrapper.find('#manager-3').attributes('aria-describedby')).toBe('manager-error-3')
  })

  it('pide solo cuentas activas con rol de responsable', async () => {
    const spy = stubFetch(api(departments(ACCOUNT_UUID, 'Jefatura de Cocina')))

    await mountAs(['accounts:*', 'employees:*'])
    await settle()

    const url = String(
      spy.mock.calls.find((call) => String(call[0]).includes('management-accounts'))?.[0],
    )

    expect(url).toContain('status=active')
    expect(url).toContain('role=responsable_departamento')
  })

  it('si hay mas de una pagina de responsables, pide las siguientes y las ofrece todas', async () => {
    const second = managementAccount({
      uuid: '0199f0c2-1f4a-7c3e-9b21-4d5e6f7a8b55',
      name: 'Jefatura de Pisos',
    })
    const spy = stubFetch((url, init) => {
      if (url.startsWith('/api/v1/management-accounts')) {
        const page = new URL(url, 'http://localhost').searchParams.get('page')

        return jsonResponse({
          data: page === '2' ? [second] : [COOK],
          meta: { page: Number(page), per_page: 100, total: 101, total_pages: 2 },
        })
      }

      return api(departments())(url, init)
    })

    const wrapper = await mountAs(['accounts:*', 'employees:*'])

    await settle()

    expect(spy.mock.calls.filter((call) => String(call[0]).includes('page=2'))).toHaveLength(1)
    expect(wrapper.findAll('#manager-3 option').map((option) => option.text())).toEqual([
      es.departments.noManager,
      'Jefatura de Cocina',
      'Jefatura de Pisos',
    ])
  })

  it('rrhh: solo lectura con el nombre del responsable, sin selector ni peticion de cuentas', async () => {
    const spy = stubFetch(api(departments(ACCOUNT_UUID, 'Jefatura de Cocina')))

    const wrapper = await mountAs(['employees:*'])

    await settle()

    expect(wrapper.find('select').exists()).toBe(false)
    expect(wrapper.find('[data-test="save-manager-3"]').exists()).toBe(false)
    expect(wrapper.find('[data-test="department-3"]').text()).toContain('Jefatura de Cocina')
    expect(spy.mock.calls.some((call) => String(call[0]).includes('management-accounts'))).toBe(
      false,
    )
  })
})

const SITE_BODY = { id: 1, name: 'Hotel Marina', timezone: 'Europe/Madrid' }

function writeApi(
  deps: unknown,
  write: (url: string, init: RequestInit) => Response = () => jsonResponse({}),
) {
  return (url: string, init?: RequestInit) => {
    if (url === '/api/v1/site' && (init?.method ?? 'GET') === 'GET') {
      return jsonResponse(SITE_BODY)
    }

    if (init?.method === 'POST' || init?.method === 'PATCH') {
      return write(url, init)
    }

    return api(deps)(url, init)
  }
}

function writes(spy: ReturnType<typeof stubFetch>) {
  return spy.mock.calls.filter((call) =>
    ['POST', 'PATCH'].includes(String((call[1] as RequestInit | undefined)?.method)),
  )
}

const VALIDATION_DUPLICATE = () =>
  problemResponse(422, 'urn:kronoqr:problem:validation-failed', {
    errors: { name: ['Ya existe un departamento con ese nombre.'] },
  })

describe('DepartmentsView: crear, renombrar y centro', () => {
  it('rrhh: crea un departamento con su nombre recortado, lo anuncia y vacia el campo', async () => {
    const spy = stubFetch(writeApi(departments()))

    const wrapper = await mountAs(['employees:*'])

    await settle()
    await wrapper.find('[data-test="department-create-name"]').setValue('  Mantenimiento ')
    await wrapper.find('[data-test="department-create"]').trigger('submit')
    await settle()

    const post = writes(spy)[0]

    expect(String(post?.[0])).toBe('/api/v1/departments')
    expect((post?.[1] as RequestInit).method).toBe('POST')
    expect(JSON.parse(String((post?.[1] as RequestInit).body))).toEqual({ name: 'Mantenimiento' })
    expect(announcement.value).toContain('Mantenimiento')
    expect(
      (wrapper.find('[data-test="department-create-name"]').element as HTMLInputElement).value,
    ).toBe('')
  })

  it('un 422 por nombre repetido se pinta junto al campo y no vacia lo escrito', async () => {
    stubFetch(writeApi(departments(), VALIDATION_DUPLICATE))

    const wrapper = await mountAs(['employees:*'])

    await settle()
    await wrapper.find('[data-test="department-create-name"]').setValue('Cocina')
    await wrapper.find('[data-test="department-create"]').trigger('submit')
    await settle()

    expect(wrapper.find('[data-test="department-create"]').text()).toContain(
      'Ya existe un departamento con ese nombre.',
    )
    expect(
      (wrapper.find('[data-test="department-create-name"]').element as HTMLInputElement).value,
    ).toBe('Cocina')
    expect(wrapper.find('[data-test="department-create-name"]').attributes('aria-invalid')).toBe(
      'true',
    )
  })

  it('renombra mostrando antes y despues, y solo envia el nombre', async () => {
    const spy = stubFetch(writeApi(departments()))

    const wrapper = await mountAs(['employees:*'])

    await settle()
    await wrapper.find('[data-test="rename-3"]').trigger('click')

    const dialog = wrapper.find('[role="dialog"]')
    const confirm = buttonWith(dialog, es.departments.rename.confirm)

    // Sin cambio no se puede confirmar.
    expect(confirm.attributes('disabled')).toBeDefined()

    await dialog.find('[data-test="rename-input"]').setValue('Cocina y office')

    const cells = dialog.findAll('[data-test="rename-preview"] tbody td').map((td) => td.text())

    expect(cells).toEqual(['Cocina', 'Cocina y office'])
    expect(writes(spy)).toHaveLength(0)

    await confirm.trigger('click')
    await settle()

    const patch = writes(spy)[0]

    expect(String(patch?.[0])).toBe('/api/v1/departments/3')
    expect(JSON.parse(String((patch?.[1] as RequestInit).body))).toEqual({
      name: 'Cocina y office',
    })
    expect(wrapper.find('[role="dialog"]').exists()).toBe(false)
    expect(announcement.value).toContain('Cocina y office')
  })

  it('un 422 al renombrar se queda en el dialogo, junto al campo', async () => {
    stubFetch(writeApi(departments(), VALIDATION_DUPLICATE))

    const wrapper = await mountAs(['employees:*'])

    await settle()
    await wrapper.find('[data-test="rename-3"]').trigger('click')
    await wrapper.find('[data-test="rename-input"]').setValue('Recepción')
    await buttonWith(wrapper.find('[role="dialog"]'), es.departments.rename.confirm).trigger(
      'click',
    )
    await settle()

    const dialog = wrapper.find('[role="dialog"]')

    expect(dialog.exists()).toBe(true)
    expect(dialog.text()).toContain('Ya existe un departamento con ese nombre.')
  })

  it.each([
    ['responsable', ['employees:read']],
    ['auditor', ['attendance:read', 'audit:read', 'reports:legal']],
  ])('%s: solo lectura, sin alta, renombrado, selector ni centro', async (_role, abilities) => {
    const spy = stubFetch(writeApi(departments(ACCOUNT_UUID, 'Jefatura de Cocina')))

    const wrapper = await mountAs(abilities)

    await settle()

    expect(wrapper.find('[data-test="department-3"]').text()).toContain('Jefatura de Cocina')
    expect(wrapper.find('[data-test="department-create"]').exists()).toBe(false)
    expect(wrapper.find('[data-test="rename-3"]').exists()).toBe(false)
    expect(wrapper.find('select').exists()).toBe(false)
    expect(wrapper.find('[data-test="site-section"]').exists()).toBe(false)
    expect(wrapper.text()).toContain(es.departments.subtitleRead)
    expect(spy.mock.calls.some((call) => String(call[0]).includes('/api/v1/site'))).toBe(false)
  })

  it('centro: muestra nombre y zona horaria en solo lectura, y renombra con antes y despues', async () => {
    const spy = stubFetch(writeApi(departments()))

    const wrapper = await mountAs(['employees:*'])

    await settle()

    expect(wrapper.find('[data-test="site-name"]').text()).toContain('Hotel Marina')
    expect(wrapper.find('[data-test="site-timezone"]').text()).toBe('Europe/Madrid')
    expect(wrapper.find('[data-test="site-timezone-note"]').text()).toBe(es.site.timezoneNote)

    await wrapper.find('[data-test="site-rename"]').trigger('click')

    const dialog = wrapper.find('[role="dialog"]')

    await dialog.find('[data-test="rename-input"]').setValue('Hotel Marina Playa')
    expect(dialog.findAll('[data-test="rename-preview"] tbody td').map((td) => td.text())).toEqual([
      'Hotel Marina',
      'Hotel Marina Playa',
    ])
    await buttonWith(dialog, es.departments.rename.confirm).trigger('click')
    await settle()

    const patch = writes(spy)[0]

    expect(String(patch?.[0])).toBe('/api/v1/site')
    // La zona horaria nunca viaja desde el panel.
    expect(JSON.parse(String((patch?.[1] as RequestInit).body))).toEqual({
      name: 'Hotel Marina Playa',
    })
    expect(announcement.value).toContain('Hotel Marina Playa')
  })
})
