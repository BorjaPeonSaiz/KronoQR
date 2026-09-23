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
    await fillDates(wrapper)
    await wrapper.find('#payroll-granularity').setValue('month')
    await wrapper.find('form').trigger('submit')
    await settle()

    expect(downloadUrl).not.toBeNull()
    expect(String(downloadUrl)).toContain('from=2026-01-01')
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
    await fillDates(wrapper)
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
        backgroundBody = init?.body === undefined ? null : JSON.parse(String(init.body))

        return jsonResponse({
          data: {
            uuid: '0199f7b1-0000-7c3d-9e4f-5a6b7c8d9e02',
            kind: 'payroll',
            format: 'csv',
            status: 'pending',
            parameters: {
              from: '2026-01-01',
              to: '2026-06-30',
              granularity: 'range',
              group_by: 'employee',
              include_open_shifts: false,
              department_id: null,
              employee_uuid: null,
            },
            requested_at: '2026-07-01T08:00:00.000000Z',
            started_at: null,
            completed_at: null,
            failed_at: null,
            failure_reason: null,
            file_name: null,
            size_bytes: null,
            row_count: null,
            criteria: [],
            expires_at: null,
            download: null,
          },
        })
      },
    })

    const wrapper = await mountView(PayrollExportView)
    await settle()
    await fillDates(wrapper)
    await wrapper.find('form').trigger('submit')
    await settle()

    expect(wrapper.find('[data-test="oversized-offer"]').exists()).toBe(true)

    await wrapper.find('[data-test="payroll-background"]').trigger('click')
    await settle()

    expect(backgroundBody).toMatchObject({ kind: 'payroll', format: 'csv' })
    expect(wrapper.find('[data-test="background-requested"]').exists()).toBe(true)
  })
})
