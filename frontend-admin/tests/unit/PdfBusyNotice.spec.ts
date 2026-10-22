// Aviso de «motor de PDF ocupado» (503 `pdf-rendering-busy`, RF-IN-01..03).
// No es un fallo del informe: hay un tope de PDF simultaneos y basta esperar o
// llevarse el mismo informe en otro formato.
import { announcement, clearAnnouncement } from '@kronoqr/web-kit/announcer'
import { beforeEach, describe, expect, it } from 'vitest'
import PdfBusyNotice from '@/features/reports/PdfBusyNotice.vue'
import es from '@/shared/i18n/locales/es.json'
import { mountView } from './support/harness'

beforeEach(() => {
  clearAnnouncement()
})

describe('PdfBusyNotice', () => {
  it('dice cuanto esperar cuando el servidor da Retry-After y lo anuncia una sola vez, por su role=alert', async () => {
    const wrapper = await mountView(PdfBusyNotice, {
      props: { retryAfterSeconds: 30, formats: ['csv', 'xlsx'], disabled: false },
    })
    const expected = es.reports.pdfBusy.message.replace('{seconds}', '30')

    expect(wrapper.find('[data-test="pdf-busy"]').text()).toContain(expected)
    // Una sola voz: el role=alert. La region viva global no repite el texto.
    expect(wrapper.find('[data-test="pdf-busy"]').attributes('role')).toBe('alert')
    expect(announcement.value).toBe('')

    wrapper.unmount()
  })

  it('sin Retry-After usa el texto sin espera', async () => {
    const wrapper = await mountView(PdfBusyNotice, {
      props: { retryAfterSeconds: null, formats: ['csv'], disabled: false },
    })

    expect(wrapper.find('[data-test="pdf-busy"]').text()).toContain(
      es.reports.pdfBusy.messageNoWait,
    )

    wrapper.unmount()
  })

  it('ofrece cada formato alternativo y emite el elegido', async () => {
    const wrapper = await mountView(PdfBusyNotice, {
      props: { retryAfterSeconds: 10, formats: ['csv', 'xlsx'], disabled: false },
    })

    expect(wrapper.find('[data-test="pdf-busy-use-csv"]').text()).toBe(
      es.reports.pdfBusy.useFormat.replace('{format}', 'CSV'),
    )

    await wrapper.find('[data-test="pdf-busy-use-xlsx"]').trigger('click')

    expect(wrapper.emitted('use')).toEqual([['xlsx']])

    wrapper.unmount()
  })

  it('con la pantalla ocupada deshabilita los botones y es un aviso (role=alert)', async () => {
    const wrapper = await mountView(PdfBusyNotice, {
      props: { retryAfterSeconds: 10, formats: ['csv'], disabled: true },
    })

    expect(wrapper.find('[data-test="pdf-busy"]').attributes('role')).toBe('alert')
    expect(wrapper.find('[data-test="pdf-busy-use-csv"]').attributes('disabled')).toBeDefined()

    wrapper.unmount()
  })
})
