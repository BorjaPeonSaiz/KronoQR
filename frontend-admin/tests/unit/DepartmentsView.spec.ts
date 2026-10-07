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

function departments(managerUuid: string | null = null) {
  return {
    data: [
      { id: 3, name: 'Cocina', manager_user_uuid: managerUuid },
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
      return jsonResponse(managementAccountCollection([COOK, GONE, OTHER]))
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
    stubFetch(api(departments(ACCOUNT_UUID)))

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

  it('rrhh: solo lectura, sin selector ni peticion de cuentas', async () => {
    const spy = stubFetch(api(departments(ACCOUNT_UUID)))

    const wrapper = await mountAs(['employees:*'])

    await settle()

    expect(wrapper.find('select').exists()).toBe(false)
    expect(wrapper.find('[data-test="save-manager-3"]').exists()).toBe(false)
    expect(wrapper.find('[data-test="department-3"]').text()).toContain(es.departments.hasManager)
    expect(spy.mock.calls.some((call) => String(call[0]).includes('management-accounts'))).toBe(
      false,
    )
  })
})
