// Titulo de la pestaña por pantalla (WCAG 2.4.2, R7-PO-03) y plurales
// (V7-PO-2).
import { describe, expect, it } from 'vitest'
import PeriodSummary from '@/features/my-records/PeriodSummary.vue'
import { bindDocumentTitle } from '@/router/documentTitle'
import { createAppI18n } from '@/shared/i18n'
import { shiftEntry, workDay } from './support/fixtures'
import { createTestPinia, createTestRouter, mountView } from './support/harness'

describe('titulo de la pestaña', () => {
  it('cada pantalla tiene el suyo, con el nombre de la instalacion, y sigue al idioma', async () => {
    createTestPinia()
    const router = createTestRouter()
    const i18n = createAppI18n('es')
    const binding = bindDocumentTitle({ router, i18n, applicationName: () => 'Hotel Marina' })

    await router.push('/login')
    expect(document.title).toBe('Acceso al portal · Hotel Marina')

    await router.push('/records')
    expect(document.title).toBe('Mi registro horario · Hotel Marina')

    await router.push('/export')
    expect(document.title).toBe('Descargar mi historial · Hotel Marina')

    await router.push('/no-existe')
    expect(document.title).toBe('Esta página no existe · Hotel Marina')

    i18n.global.locale.value = 'en'
    expect(document.title).toContain('· Hotel Marina')
    expect(document.title).not.toContain('Descargar')

    binding.stop()
  })

  it('los titulos de las tres pantallas son distintos entre si', async () => {
    createTestPinia()
    const router = createTestRouter()
    const binding = bindDocumentTitle({
      router,
      i18n: createAppI18n('es'),
      applicationName: () => 'KronoQR',
    })
    const titles = new Set<string>()

    for (const path of ['/login', '/records', '/export']) {
      await router.push(path)
      titles.add(document.title)
    }

    expect(titles.size).toBe(3)
    binding.stop()
  })

  it('refresh() recompone el titulo despues de que la marca lo sobrescriba', async () => {
    createTestPinia()
    const router = createTestRouter()
    const binding = bindDocumentTitle({
      router,
      i18n: createAppI18n('es'),
      applicationName: () => 'KronoQR',
    })

    await router.push('/export')
    document.title = 'KronoQR'
    binding.refresh()

    expect(document.title).toBe('Descargar mi historial · KronoQR')
    binding.stop()
  })
})

describe('plurales del resumen', () => {
  function summary(count: number, provisional: number) {
    const days = Array.from({ length: count }, (_, index) =>
      workDay({
        work_date: `2026-03-${String(10 + index).padStart(2, '0')}`,
        has_open_shift: index < provisional,
        shift_entries: [shiftEntry()],
        total_minutes: 485,
      }),
    )

    return mountView(PeriodSummary, {
      props: { days, from: '2026-03-10', to: '2026-03-20', today: null },
    })
  }

  it('una jornada va en singular', async () => {
    const wrapper = await summary(1, 1)

    expect(wrapper.find('[data-test="period-days"]').text()).toBe(
      '1 jornada con registro, del 2026-03-10 al 2026-03-20.',
    )
    expect(wrapper.find('[data-test="period-provisional"]').text()).toContain(
      '1 jornada tiene un turno abierto',
    )
  })

  it('varias van en plural', async () => {
    const wrapper = await summary(3, 2)

    expect(wrapper.find('[data-test="period-days"]').text()).toContain('3 jornadas con registro')
    expect(wrapper.find('[data-test="period-provisional"]').text()).toContain(
      '2 jornadas tienen un turno abierto',
    )
  })

  it('en ingles tambien: 1 work day / 2 work days', async () => {
    const one = await mountView(PeriodSummary, {
      props: {
        days: [workDay({ has_open_shift: true })],
        from: '2026-03-10',
        to: '2026-03-20',
        today: null,
      },
      locale: 'en',
    })

    expect(one.find('[data-test="period-days"]').text()).toContain('1 work day with records')
    expect(one.find('[data-test="period-provisional"]').text()).toContain('1 work day has')
  })
})
