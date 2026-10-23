import { describe, expect, it } from 'vitest'
import PaginationBar from '@/shared/ui/PaginationBar.vue'
import { mountView, settle } from './support/harness'

describe('PaginationBar (RF-GP-01, RF-QR-08)', () => {
  it('muestra el resumen «de X a Y de Z» sin redondear la cuenta', async () => {
    const wrapper = await mountView(PaginationBar, {
      props: { page: 2, perPage: 25, total: 120, totalPages: 5, label: 'Paginación de prueba' },
    })

    await settle()

    expect(wrapper.text()).toContain('26–50 de 120')
    expect(wrapper.text()).toContain('Página 2 de 5')
  })

  it('el aria-label del `<nav>` es el que le pasa quien lo usa', async () => {
    const wrapper = await mountView(PaginationBar, {
      props: {
        page: 1,
        perPage: 25,
        total: 3,
        totalPages: 1,
        label: 'Paginación de credenciales',
      },
    })

    await settle()

    expect(wrapper.find('nav').attributes('aria-label')).toBe('Paginación de credenciales')
  })

  it('deshabilita «anterior» en la primera página y «siguiente» en la última', async () => {
    const wrapper = await mountView(PaginationBar, {
      props: { page: 1, perPage: 25, total: 10, totalPages: 1, label: 'Paginación' },
    })

    await settle()

    const buttons = wrapper.findAll('button')

    expect(buttons[0]?.attributes('aria-disabled')).toBeDefined()
    expect(buttons[1]?.attributes('aria-disabled')).toBeDefined()
  })

  it('emite `update:page` acotado entre 1 y el total de páginas', async () => {
    const wrapper = await mountView(PaginationBar, {
      props: { page: 5, perPage: 25, total: 125, totalPages: 5, label: 'Paginación' },
    })

    await settle()

    await wrapper.findAll('button')[1]?.trigger('click')

    // En la ultima pagina, «siguiente» esta deshabilitado: no debe emitir nada.
    expect(wrapper.emitted('update:page')).toBeUndefined()

    await wrapper.findAll('button')[0]?.trigger('click')

    expect(wrapper.emitted('update:page')?.at(0)).toEqual([4])
  })

  it('avisa de que sigue actualizando cuando `fetching` es verdadero', async () => {
    const wrapper = await mountView(PaginationBar, {
      props: {
        page: 1,
        perPage: 25,
        total: 10,
        totalPages: 1,
        label: 'Paginación',
        fetching: true,
      },
    })

    await settle()

    expect(wrapper.text()).toContain('Actualizando…')
  })
})

describe('PaginationBar: accesibilidad (V3-UX-05, V7-PA-10)', () => {
  it('«Actualizando…» vive en una region viva que existe tambien en reposo', async () => {
    const props = { page: 1, perPage: 25, total: 10, totalPages: 1, label: 'Paginación' }
    const idle = await mountView(PaginationBar, { props: { ...props, fetching: false } })
    const busy = await mountView(PaginationBar, { props: { ...props, fetching: true } })

    expect(idle.find('[role="status"]').exists()).toBe(true)
    expect(idle.find('[role="status"]').text()).toBe('')
    expect(busy.find('[role="status"]').text()).toBe('Actualizando…')
  })

  it('«Página N de M» no es aria-current: no es un enlace de un conjunto', async () => {
    const wrapper = await mountView(PaginationBar, {
      props: { page: 2, perPage: 25, total: 80, totalPages: 4, label: 'Paginación' },
    })

    expect(wrapper.find('[aria-current]').exists()).toBe(false)
    expect(wrapper.text()).toContain('Página 2 de 4')
  })
})
