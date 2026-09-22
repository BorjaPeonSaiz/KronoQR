// Alta de una ausencia (RF-GP-04).
//
// Lo que se comprueba: el buscador de persona reutiliza la API de plantilla,
// «other» exige nota (decision 2 de la ficha) y sin ella el envio queda
// bloqueado aunque el resto del formulario este completo.
import { afterEach, describe, expect, it, vi } from 'vitest'
import AbsenceRegisterDialog from '@/features/absences/AbsenceRegisterDialog.vue'
import { employee, employeeCollection } from './support/fixtures'
import { jsonResponse, mountView, settle, stubFetch } from './support/harness'

afterEach(() => {
  vi.unstubAllGlobals()
})

/** El buscador tiene 300 ms de rebote: se espera en tiempo real y de sobra. */
async function waitForDebounce(): Promise<void> {
  await new Promise((resolve) => setTimeout(resolve, 350))
}

async function selectSearchedEmployee(
  wrapper: Awaited<ReturnType<typeof mountView>>,
): Promise<void> {
  await wrapper.find('[data-test="register-person-search"]').setValue('Youssef')
  await waitForDebounce()
  await settle()

  await wrapper.find('[data-test^="register-person-option-"]').trigger('click')
  await settle()
}

describe('AbsenceRegisterDialog', () => {
  it('busca por la API de plantilla y permite elegir a la persona encontrada', async () => {
    stubFetch(() => jsonResponse(employeeCollection([employee()])))

    const wrapper = await mountView(AbsenceRegisterDialog)

    await selectSearchedEmployee(wrapper)

    expect(wrapper.find('[data-test="register-person-selected"]').exists()).toBe(true)
    expect(wrapper.find('[data-test="register-person-results"]').exists()).toBe(false)
  })

  it('con el tipo «otro», exige nota: sin ella el envío queda bloqueado', async () => {
    stubFetch(() => jsonResponse(employeeCollection([employee()])))

    const wrapper = await mountView(AbsenceRegisterDialog)

    await selectSearchedEmployee(wrapper)
    await wrapper.find('[data-test="register-type"]').setValue('other')
    await wrapper.find('[data-test="register-starts-on"]').setValue('2026-03-10')
    await wrapper.find('[data-test="register-ends-on"]').setValue('2026-03-10')

    expect(wrapper.find('[data-test="register-submit"]').attributes('disabled')).toBeDefined()

    await wrapper.find('[data-test="register-note"]').setValue('Permiso por traslado de domicilio.')

    expect(wrapper.find('[data-test="register-submit"]').attributes('disabled')).toBeUndefined()
  })

  it('con vacaciones, no exige nota: el envío no depende de rellenarla', async () => {
    stubFetch(() => jsonResponse(employeeCollection([employee()])))

    const wrapper = await mountView(AbsenceRegisterDialog)

    await selectSearchedEmployee(wrapper)
    await wrapper.find('[data-test="register-type"]').setValue('vacation')
    await wrapper.find('[data-test="register-starts-on"]').setValue('2026-03-10')
    await wrapper.find('[data-test="register-ends-on"]').setValue('2026-03-14')

    expect(wrapper.find('[data-test="register-submit"]').attributes('disabled')).toBeUndefined()
  })
})
