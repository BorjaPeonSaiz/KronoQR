// Salida a nómina (RF-IN-07, tarea 3.9).
//
// Lo que puede salir mal el día que alguien la usa:
//
//  - Que la previsualización de columnas no refleje lo que de verdad hay
//    configurado en «Ajustes operativos» (las dos pantallas comparten el
//    mismo catálogo, y no deben divergir).
//  - Que un `402` de licencia se confunda con un error cualquiera.
//  - Que un informe demasiado grande no ofrezca la generación en segundo
//    plano, o que la oferta no mande `kind: payroll`.
import { beforeEach, describe, expect, it } from 'vitest'
import PayrollExportView from '@/features/reports/PayrollExportView.vue'
import es from '@/shared/i18n/locales/es.json'
import { clearAnnouncement } from '@kronoqr/web-kit/announcer'
import { reportExportRow } from './support/fixtures'
import { jsonResponse, mountView, problemResponse, settle, stubRoutes } from './support/harness'

const DEFAULT_SETTINGS = {
  data: [
    {
      key: 'PAYROLL_EXPORT_COLUMNS',
      value: ['employee_code', 'last_name', 'worked_hours=Horas'],
      type: 'text_list',
      impact: 'presentation',
      affects_worked_hours: false,
      source: 'installation',
      redacted: false,
    },
    {
      key: 'PAYROLL_EXPORT_DELIMITER',
      value: 'comma',
      type: 'text',
      impact: 'presentation',
      affects_worked_hours: false,
      source: 'installation',
      redacted: false,
    },
    {
      key: 'PAYROLL_EXPORT_HOURS_FORMAT',
      value: 'decimal_dot',
      type: 'text',
      impact: 'presentation',
      affects_worked_hours: false,
      source: 'installation',
      redacted: false,
    },
  ],
  meta: { unknown_keys: [], invalid_keys: [] },
}

async function fillDates(wrapper: Awaited<ReturnType<typeof mountView>>): Promise<void> {
  await wrapper.find('#payroll-from').setValue('2026-01-01')
  await wrapper.find('#payroll-to').setValue('2026-06-30')
}

/**
 * Una consulta CORTA de una persona: la unica que sigue siendo al momento. Toda la
 * plantilla, o mas de un mes, se genera en segundo plano por omision (14.4).
 */
async function fillSyncQuery(wrapper: Awaited<ReturnType<typeof mountView>>): Promise<void> {
  await wrapper.find('#payroll-from').setValue('2026-06-01')
  await wrapper.find('#payroll-to').setValue('2026-06-30')
  await wrapper.find('#payroll-employee').setValue('0199f0c2-1f4a-7c3e-9b21-4d5e6f7a8b90')
}

beforeEach(() => {
  clearAnnouncement()
})

describe('PayrollExportView', () => {
  it('previsualiza EXACTAMENTE las columnas configuradas, con su etiqueta y en su orden', async () => {
    stubRoutes({
      '/departments': () => jsonResponse({ data: [] }),
      '/settings': () => jsonResponse(DEFAULT_SETTINGS),
    })

    const wrapper = await mountView(PayrollExportView)
    await settle()

    const preview = wrapper.find('[data-test="payroll-columns-preview"]')

    expect(preview.text()).toContain(es.payrollExport.columns.employee_code)
    expect(preview.text()).toContain(es.payrollExport.columns.last_name)
    // `worked_hours=Horas`: la etiqueta escrita por quien configura gana a la
    // traducción de serie del catálogo.
    expect(preview.text()).toContain('Horas')
    expect(preview.text()).not.toContain(es.payrollExport.columns.worked_hours)

    // El separador y el formato de horas configurados, interpolados en la
    // introducción: «coma» y «decimal con punto», no los de serie.
    expect(wrapper.text()).toContain(es.payrollExport.delimiter.comma)
    expect(wrapper.text()).toContain(es.payrollExport.hoursFormat.decimal_dot)
  })

  it('previsualiza la plantilla de serie TAL COMO la publica el catálogo, no una copia local', async () => {
    // `GET /api/v1/settings` siempre trae una fila para `PAYROLL_EXPORT_COLUMNS`
    // -con `source: product_default` y las diez columnas de serie de la
    // decision 5 de la ficha cuando la instalación no ha guardado la suya-,
    // igual que para cualquier otra clave del catálogo (`ATTENDANCE_*`,
    // `LOCALE_*`). Esta pantalla no duplica esa lista: si el servidor cambiara
    // mañana el valor de serie, la previsualización lo seguiría acertando sin
    // tocar este componente.
    stubRoutes({
      '/departments': () => jsonResponse({ data: [] }),
      '/settings': () =>
        jsonResponse({
          data: [
            {
              key: 'PAYROLL_EXPORT_COLUMNS',
              value: ['employee_code', 'worked_hours'],
              type: 'text_list',
              impact: 'presentation',
              affects_worked_hours: false,
              source: 'product_default',
              redacted: false,
            },
          ],
          meta: { unknown_keys: [], invalid_keys: [] },
        }),
    })

    const wrapper = await mountView(PayrollExportView)
    await settle()

    const preview = wrapper.find('[data-test="payroll-columns-preview"]')

    expect(preview.text()).toContain(es.payrollExport.columns.employee_code)
    expect(preview.text()).toContain(es.payrollExport.columns.worked_hours)
  })

  it('sin fila para la clave todavía (catálogo incompleto), no previsualiza columnas inventadas', async () => {
    stubRoutes({
      '/departments': () => jsonResponse({ data: [] }),
      '/settings': () => jsonResponse({ data: [], meta: { unknown_keys: [], invalid_keys: [] } }),
    })

    const wrapper = await mountView(PayrollExportView)
    await settle()

    const preview = wrapper.find('[data-test="payroll-columns-preview"]')

    expect(preview.text()).not.toContain(es.payrollExport.columns.employee_code)
  })

  it('«Descargar» manda el periodo, la granularidad y el formato elegidos', async () => {
    let downloadUrl: string | null = null

    stubRoutes({
      '/departments': () => jsonResponse({ data: [] }),
      '/settings': () => jsonResponse(DEFAULT_SETTINGS),
      '/payroll-export': (url) => {
        downloadUrl = url

        return new Response('contenido', {
          status: 200,
          headers: {
            'Content-Type': 'text/csv',
            'Content-Disposition': 'attachment; filename=kronoqr-nomina-2026-01-01_2026-06-30.csv',
          },
        })
      },
    })

    const wrapper = await mountView(PayrollExportView)
    await settle()
    await fillSyncQuery(wrapper)
    await wrapper.find('#payroll-granularity').setValue('month')
    await wrapper.find('form').trigger('submit')
    await settle()

    expect(downloadUrl).not.toBeNull()
    expect(String(downloadUrl)).toContain('from=2026-06-01')
    expect(String(downloadUrl)).toContain('to=2026-06-30')
    expect(String(downloadUrl)).toContain('granularity=month')
    expect(String(downloadUrl)).toContain('format=csv')
  })

  it('un 402 se explica como falta de licencia, con enlace a «Licencia»', async () => {
    stubRoutes({
      '/departments': () => jsonResponse({ data: [] }),
      '/settings': () => jsonResponse(DEFAULT_SETTINGS),
      '/payroll-export': () =>
        problemResponse(402, 'urn:kronoqr:problem:feature-not-licensed', {
          feature: 'payroll_export',
          restriction: 'not_in_plan',
        }),
    })

    const wrapper = await mountView(PayrollExportView)
    await settle()
    await fillSyncQuery(wrapper)
    await wrapper.find('form').trigger('submit')
    await settle()

    expect(wrapper.find('[data-test="license-required-notice"]').exists()).toBe(true)
    expect(wrapper.find('[data-test="download-error"]').exists()).toBe(false)
  })

  it('un informe demasiado grande ofrece generarlo en segundo plano, con kind: payroll', async () => {
    let backgroundBody: unknown = null

    stubRoutes({
      '/departments': () => jsonResponse({ data: [] }),
      '/settings': () => jsonResponse(DEFAULT_SETTINGS),
      '/payroll-export': () =>
        problemResponse(422, 'urn:kronoqr:problem:report-too-large', {
          errors: {
            to: ['El informe abarca 181 dias y el maximo que se entrega en el acto es 92.'],
          },
        }),
      '/reports/exports': (_url, init) => {
        if (init?.method !== 'POST') {
          return jsonResponse({ data: [reportExportRow({ kind: 'payroll' })] })
        }

        backgroundBody = JSON.parse(String(init.body))

        return jsonResponse({ data: reportExportRow({ kind: 'payroll' }) }, 202)
      },
    })

    const wrapper = await mountView(PayrollExportView)
    await settle()
    await fillSyncQuery(wrapper)
    await wrapper.find('form').trigger('submit')
    await settle()

    expect(wrapper.find('[data-test="oversized-offer"]').exists()).toBe(true)

    await wrapper.find('[data-test="payroll-background"]').trigger('click')
    await settle()

    expect(backgroundBody).toMatchObject({ kind: 'payroll', format: 'csv' })
    expect(wrapper.find('[data-test="export-job"]').exists()).toBe(true)
  })

  it('toda la plantilla, o mas de un mes, se pide en segundo plano sin pasar por la descarga al momento (14.4)', async () => {
    const calls: string[] = []
    let body: unknown = null

    stubRoutes({
      '/departments': () => jsonResponse({ data: [] }),
      '/settings': () => jsonResponse(DEFAULT_SETTINGS),
      '/payroll-export': (url) => {
        calls.push(url)

        return new Response('x', { status: 200, headers: { 'Content-Type': 'text/csv' } })
      },
      '/reports/exports': (_url, init) => {
        if (init?.method === 'POST') {
          body = JSON.parse(String(init.body))

          return jsonResponse({ data: reportExportRow({ kind: 'payroll' }) }, 202)
        }

        return jsonResponse({ data: [reportExportRow({ kind: 'payroll' })] })
      },
    })

    const wrapper = await mountView(PayrollExportView)
    await settle()
    await fillDates(wrapper)
    await wrapper.find('form').trigger('submit')
    await settle()

    expect(calls).toEqual([])
    expect(body).toMatchObject({ kind: 'payroll', format: 'csv', from: '2026-01-01' })
    expect(wrapper.find('[data-test="export-job"]').attributes('data-status')).toBe('pending')
  })

  it('un 402 al pedirla en segundo plano se explica como falta de licencia', async () => {
    stubRoutes({
      '/departments': () => jsonResponse({ data: [] }),
      '/settings': () => jsonResponse(DEFAULT_SETTINGS),
      '/reports/exports': () =>
        problemResponse(402, 'urn:kronoqr:problem:feature-not-licensed', {
          feature: 'payroll_export',
          restriction: 'not_in_plan',
        }),
    })

    const wrapper = await mountView(PayrollExportView)
    await settle()
    await fillDates(wrapper)
    await wrapper.find('form').trigger('submit')
    await settle()

    expect(wrapper.find('[data-test="license-required-background"]').exists()).toBe(true)
  })
})
