import type { DOMWrapper } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import LegalExportView from '@/features/reports/LegalExportView.vue'
import es from '@/shared/i18n/locales/es.json'
import { announcement, clearAnnouncement } from '@kronoqr/web-kit/announcer'
import { announcement as liveAnnouncement } from '@kronoqr/web-kit/announcer'
import { reportExportRow } from './support/fixtures'
import {
  buttonWith,
  jsonResponse,
  mountView,
  problemResponse,
  settle,
  stubFetch,
} from './support/harness'

// La pantalla de la exportacion para la Inspeccion (RF-IN-05, RL-06).
//
// Lo que se comprueba aqui es lo que puede salir mal el dia del requerimiento:
// que la peticion lleve el periodo por fecha de jornada y no un instante, que el
// alcance vacio signifique «plantilla completa» y no un parametro en blanco, que
// el fichero se descargue y se suelte, y que las cifras que se enseñan sean las
// que devolvio el servidor y no un recuento propio.

type Wrapper = Awaited<ReturnType<typeof mountView>>

function csvResponse(shiftRows: number, corrections: number): Response {
  return new Response('﻿Tipo;Trabajador\r\nTRAMO;"Vilar, Lucia"\r\n', {
    status: 200,
    headers: {
      'Content-Type': 'text/csv; charset=utf-8',
      'Content-Disposition': 'attachment; filename=registro-horario-2026-01-01_2026-01-31.csv',
      'X-Kronoqr-Export-Shift-Rows': String(shiftRows),
      'X-Kronoqr-Export-Correction-Rows': String(corrections),
    },
  })
}

const PERSON = {
  uuid: '0199f0c2-1f4a-7c3e-9b21-4d5e6f7a8b90',
  first_name: 'Lucia',
  last_name: 'Vilar',
  department: { id: 3, name: 'Cocina' },
  status: 'terminated',
  hired_at: '2022-03-01',
  terminated_at: '2026-02-15',
}

function inputWith(wrapper: Wrapper, label: string): DOMWrapper<HTMLInputElement> {
  const field = wrapper
    .findAll('label')
    .find(
      (candidate) => candidate.attributes('for') !== undefined && candidate.text().includes(label),
    )
    ?.attributes('for')

  const input = wrapper.find<HTMLInputElement>(`#${CSS.escape(field ?? '')}`)

  if (!input.exists()) {
    throw new Error(`No hay ningun campo etiquetado «${label}»`)
  }

  return input
}

async function fillPeriod(wrapper: Wrapper, from: string, to: string): Promise<void> {
  await inputWith(wrapper, es.reports.legalExport.from).setValue(from)
  await inputWith(wrapper, es.reports.legalExport.to).setValue(to)
}

/** Elige a la persona del requerimiento con el buscador (alcance «una persona»). */
async function choosePerson(wrapper: Wrapper): Promise<void> {
  await wrapper.findAll('input[name="legal-export-scope"]')[1]?.setValue(true)
  await inputWith(wrapper, es.reports.legalExport.picker.textLabel).setValue('vilar')
  await buttonWith(wrapper, es.reports.legalExport.picker.search).trigger('click')
  await settle()
  await wrapper.find(`input[type="radio"][value="${PERSON.uuid}"]`).setValue(true)
  await buttonWith(wrapper, es.reports.legalExport.picker.choose).trigger('click')
  await settle()
}

let createObjectURL: ReturnType<typeof vi.fn>
let revokeObjectURL: ReturnType<typeof vi.fn>

beforeEach(() => {
  clearAnnouncement()

  createObjectURL = vi.fn(() => 'blob:kronoqr')
  revokeObjectURL = vi.fn()
  vi.stubGlobal('URL', { ...URL, createObjectURL, revokeObjectURL })
  vi.spyOn(HTMLAnchorElement.prototype, 'click').mockImplementation(() => {})
})

afterEach(() => {
  vi.unstubAllGlobals()
  vi.restoreAllMocks()
})

describe('exportacion para la Inspeccion', () => {
  it('pide el periodo por fecha de jornada y descarga el fichero', async () => {
    const fetchSpy = stubFetch((url) =>
      url.includes('/employees/search')
        ? jsonResponse({ data: [PERSON], truncated: false })
        : csvResponse(1240, 17),
    )
    const wrapper = await mountView(LegalExportView)

    await fillPeriod(wrapper, '2026-01-01', '2026-01-31')
    await choosePerson(wrapper)
    await wrapper.find('form').trigger('submit')
    await settle()

    const [url, init] = fetchSpy.mock.calls.find((call) =>
      String(call[0]).includes('/legal-export?'),
    ) as [string, RequestInit]

    // Una persona en un periodo corto sigue siendo al momento: el endpoint sincrono se mantiene.
    expect(url).toBe(
      `/api/v1/reports/legal-export?from=2026-01-01&to=2026-01-31&employee_uuid=${PERSON.uuid}`,
    )

    // RL-06: se pide un fichero tabular, no JSON. Un `Accept` equivocado es la
    // forma de recibir un 406 que nadie sabe explicar.
    expect(new Headers(init.headers).get('Accept')).toContain('text/csv')

    // Y se suelta en el acto: es una lista nominal de la plantilla con sus
    // horas, no se queda viva en el navegador.
    expect(revokeObjectURL).toHaveBeenCalledWith('blob:kronoqr')
  })

  it('enseña las cifras que devolvio el servidor, no un recuento propio', async () => {
    stubFetch((url) =>
      url.includes('/employees/search')
        ? jsonResponse({ data: [PERSON], truncated: false })
        : csvResponse(1240, 17),
    )
    const wrapper = await mountView(LegalExportView)

    await fillPeriod(wrapper, '2026-01-01', '2026-01-31')
    await choosePerson(wrapper)
    await wrapper.find('form').trigger('submit')
    await settle()

    // Son las mismas que quedan en `audit_log`: permiten decir cuanto se
    // entrego sin abrir el fichero.
    expect(wrapper.text()).toContain('1240')
    expect(wrapper.text()).toContain('17')
    expect(announcement.value).toContain('1240')
  })

  it('acota a la persona elegida en el buscador y el uuid llega a la exportacion', async () => {
    const fetchSpy = stubFetch((url) =>
      url.includes('/employees/search')
        ? jsonResponse({ data: [PERSON], truncated: false })
        : csvResponse(12, 1),
    )
    const wrapper = await mountView(LegalExportView)

    await fillPeriod(wrapper, '2026-01-01', '2026-01-31')
    await wrapper.findAll('input[name="legal-export-scope"]')[1]?.setValue(true)
    await settle()

    // Ya no hay un campo de texto para teclear el UUID en crudo.
    expect(wrapper.text()).not.toContain('0199f0c2-1f4a-7c3e-9b21-4d5e6f7a8b90')

    await inputWith(wrapper, es.reports.legalExport.picker.textLabel).setValue('amrani')
    await buttonWith(wrapper, es.reports.legalExport.picker.search).trigger('click')
    await settle()
    await wrapper
      .find('input[type="radio"][value="0199f0c2-1f4a-7c3e-9b21-4d5e6f7a8b90"]')
      .setValue(true)
    await buttonWith(wrapper, es.reports.legalExport.picker.choose).trigger('click')
    await settle()

    // El uuid se guarda pero no se enseña.
    expect(wrapper.text()).not.toContain('0199f0c2-1f4a-7c3e-9b21-4d5e6f7a8b90')

    await wrapper.find('form').trigger('submit')
    await settle()

    const exportCall = fetchSpy.mock.calls
      .map((call) => String(call[0]))
      .find((url) => url.includes('/legal-export?'))

    expect(exportCall).toContain('employee_uuid=0199f0c2-1f4a-7c3e-9b21-4d5e6f7a8b90')
  })

  it('no deja generar una persona sin haberla elegido', async () => {
    stubFetch(() => csvResponse(0, 0))
    const wrapper = await mountView(LegalExportView)

    await fillPeriod(wrapper, '2026-01-01', '2026-01-31')
    await wrapper.findAll('input[name="legal-export-scope"]')[1]?.setValue(true)

    expect(
      wrapper.find<HTMLButtonElement>('button[type="submit"]').attributes('aria-disabled'),
    ).toBeDefined()
  })

  it('el DNI se borra del campo al buscar y no viaja en la URL', async () => {
    const fetchSpy = stubFetch(() => jsonResponse({ data: [], truncated: false }))
    const wrapper = await mountView(LegalExportView)

    await wrapper.findAll('input[name="legal-export-scope"]')[1]?.setValue(true)
    await wrapper.findAll('input[type="radio"][value="nationalId"]')[0]?.setValue(true)
    const field = inputWith(wrapper, es.reports.legalExport.picker.nationalIdLabel)

    await field.setValue('00000000T')
    await buttonWith(wrapper, es.reports.legalExport.picker.search).trigger('click')
    await settle()

    const [url, init] = fetchSpy.mock.calls[0] as [string, RequestInit]

    expect(url).not.toContain('00000000T')
    expect(init.method).toBe('POST')
    expect(init.body).toBe(JSON.stringify({ national_id: '00000000T' }))
    expect(field.element.value).toBe('')
    expect(wrapper.text()).toContain(es.reports.legalExport.picker.noneByNationalId)
  })

  it('no llama al servidor con un periodo invertido y dice por que', async () => {
    // No se da la vuelta a las fechas: el fichero que acabaria en un expediente
    // llevaria escrito un periodo que nadie pidio.
    const fetchSpy = stubFetch(() => csvResponse(0, 0))
    const wrapper = await mountView(LegalExportView)

    await fillPeriod(wrapper, '2026-01-31', '2026-01-01')
    await wrapper.find('form').trigger('submit')
    await settle()

    expect(fetchSpy).not.toHaveBeenCalled()
    expect(wrapper.text()).toContain(es.reports.legalExport.inverted)
  })

  it('no ofrece generar sin periodo', async () => {
    stubFetch(() => csvResponse(0, 0))
    const wrapper = await mountView(LegalExportView)

    const submit = wrapper.find<HTMLButtonElement>('button[type="submit"]')

    expect(submit.attributes('aria-disabled')).toBeDefined()
  })

  it('explica un 403 sin enseñar el detalle tecnico', async () => {
    // La autorizacion de verdad esta en el servidor (regla dura 18). Cuando
    // responde que no, la pantalla dice que hacer, no el codigo HTTP.
    stubFetch(() => problemResponse(403, 'urn:kronoqr:problem:forbidden'))
    const wrapper = await mountView(LegalExportView)

    await fillPeriod(wrapper, '2026-01-01', '2026-01-31')
    await wrapper.find('form').trigger('submit')
    await settle()

    expect(wrapper.text()).toContain(es.errors.forbidden.title)
    expect(wrapper.text()).not.toContain('403')
  })

  it('dice que el fichero no se filtra por centro, en vez de dejar que se suponga', async () => {
    // El endpoint tiene dos alcances: la plantilla completa o una persona. Un
    // filtro de centro que el servidor ignora dejaria a quien exporta convencido
    // de haber acotado lo que entrego.
    stubFetch(() => csvResponse(0, 0))
    const wrapper = await mountView(LegalExportView)

    expect(wrapper.text()).toContain(es.reports.legalExport.noSiteFilter)
    expect(wrapper.text()).toContain(es.reports.legalExport.contents.corrections)
  })
})

describe('exportacion para la Inspeccion en diferido (RC2, V5-PA-4)', () => {
  const NOT_SYNC = '/api/v1/reports/legal-export?'

  /** Un servidor de exportaciones: una fila que avanza segun lo que haya hecho el test. */
  function exportsServer(initial = reportExportRow()) {
    let row = initial
    const calls: { method: string; url: string; body: unknown }[] = []

    const spy = stubFetch((url, init) => {
      const method = init?.method ?? 'GET'

      calls.push({
        method,
        url,
        body: init?.body === undefined ? null : JSON.parse(String(init.body)),
      })

      if (url.includes('/employees/search')) {
        return jsonResponse({ data: [PERSON], truncated: false })
      }

      if (url.startsWith('/api/v1/reports/exports') && url.endsWith('/cancel')) {
        row = reportExportRow({
          ...row,
          status: 'cancelled',
          progress: null,
          cancelled_at: '2026-07-01T08:01:00.000000Z',
        })

        return jsonResponse({ data: row })
      }

      if (url === '/api/v1/reports/exports' && method === 'POST') {
        return jsonResponse({ data: row }, 202)
      }

      if (url.startsWith('/api/v1/reports/exports/')) {
        return jsonResponse({ data: row })
      }

      if (url.startsWith('/api/v1/reports/exports')) {
        return jsonResponse({ data: [row] })
      }

      return jsonResponse({}, 404)
    })

    return {
      spy,
      calls,
      set: (next: ReturnType<typeof reportExportRow>) => {
        row = next
      },
    }
  }

  async function submitWholeWorkforce(wrapper: Wrapper): Promise<void> {
    await fillPeriod(wrapper, '2026-01-01', '2026-01-31')
    await wrapper.find('form').trigger('submit')
    await settle()
  }

  it('toda la plantilla se pide en segundo plano (kind legal, csv), sin pasar por el endpoint sincrono', async () => {
    const server = exportsServer()
    const wrapper = await mountView(LegalExportView)

    await submitWholeWorkforce(wrapper)

    const post = server.calls.find((call) => call.method === 'POST')

    expect(post?.body).toMatchObject({
      kind: 'legal',
      format: 'csv',
      from: '2026-01-01',
      to: '2026-01-31',
    })
    expect(server.calls.some((call) => call.url.startsWith(NOT_SYNC))).toBe(false)
    expect(wrapper.find('[data-test="export-job"]').attributes('data-status')).toBe('pending')
    expect(liveAnnouncement.value).toBe(es.reportExports.job.announce.started)
  })

  it('un periodo de mas de un mes tambien, aunque sea de una persona', async () => {
    const server = exportsServer()
    const wrapper = await mountView(LegalExportView)

    await fillPeriod(wrapper, '2026-01-01', '2026-03-31')
    await choosePerson(wrapper)
    await wrapper.find('form').trigger('submit')
    await settle()

    expect(server.calls.some((call) => call.method === 'POST')).toBe(true)
  })

  it('con el avance en curso, la barra lleva el porcentaje y la fase; sin porcentaje es indeterminada', async () => {
    exportsServer(
      reportExportRow({
        status: 'running',
        progress: { stage: 'writing', percent: 42, updated_at: '2026-07-01T08:00:30.000000Z' },
      }),
    )
    const wrapper = await mountView(LegalExportView)

    await submitWholeWorkforce(wrapper)

    const bar = wrapper.find('[data-test="export-job-progress"]')

    expect(bar.attributes('role')).toBe('progressbar')
    expect(bar.attributes('aria-valuenow')).toBe('42')
    expect(wrapper.find('[data-test="export-job-phase"]').text()).toBe(
      es.reportExports.job.stage.writing,
    )
  })

  it('cancelar la exportacion en curso la deja cancelada y lo anuncia', async () => {
    const server = exportsServer(
      reportExportRow({
        status: 'running',
        progress: { stage: 'reading', percent: null, updated_at: '2026-07-01T08:00:30.000000Z' },
      }),
    )
    const wrapper = await mountView(LegalExportView)

    await submitWholeWorkforce(wrapper)

    expect(
      wrapper.find('[data-test="export-job-progress"]').attributes('aria-valuenow'),
    ).toBeUndefined()

    await wrapper.find('[data-test="export-job-cancel"]').trigger('click')
    await settle()

    expect(
      server.calls.some((call) => call.method === 'POST' && call.url.endsWith('/cancel')),
    ).toBe(true)
    expect(wrapper.find('[data-test="export-job"]').attributes('data-status')).toBe('cancelled')
    expect(wrapper.find('[data-test="export-job-ended"]').text()).toBe(
      es.reportExports.job.cancelled,
    )
    expect(liveAnnouncement.value.trim()).toBe(es.reportExports.job.announce.cancelled)
  })

  it('si ya habia una en curso (409) enseña esa, con el enlace a la existente', async () => {
    const existing = reportExportRow({ status: 'running' })

    stubFetch((url, init) => {
      if (url === '/api/v1/reports/exports' && init?.method === 'POST') {
        return problemResponse(409, 'urn:kronoqr:problem:report-export-in-progress', {
          export: existing,
        })
      }

      return jsonResponse({ data: [existing] })
    })
    const wrapper = await mountView(LegalExportView)

    await submitWholeWorkforce(wrapper)

    expect(wrapper.find('[data-test="export-job"]').attributes('data-status')).toBe('running')
    expect(wrapper.find('[data-test="export-job-existing"]').text()).toContain(
      es.reportExports.job.alreadyRunning,
    )
    expect(liveAnnouncement.value).toBe(es.reportExports.job.announce.alreadyRunning)
  })

  it('al completarse ofrece descargar con un enlace nuevo y no anuncia cada sondeo', async () => {
    const done = reportExportRow({
      status: 'completed',
      file_name: 'registro-horario.csv',
      download: {
        url: '/api/v1/reports/exports/x/download?token=t',
        expires_at: '2026-07-01T08:15:00.000000Z',
      },
    })
    const server = exportsServer(done)
    const wrapper = await mountView(LegalExportView)

    await submitWholeWorkforce(wrapper)

    expect(wrapper.find('[data-test="export-job-done"]').exists()).toBe(true)

    server.spy.mockClear()
    await wrapper.find('[data-test="export-job-download"]').trigger('click')
    await settle()

    // Cada descarga pide el estado de nuevo (el enlace es de un solo uso).
    expect(
      server.spy.mock.calls.some(
        (call) => String(call[0]) === `/api/v1/reports/exports/${done.uuid}`,
      ),
    ).toBe(true)
  })

  it('un fallo al pedirla se cuenta con ErrorNotice y se anuncia', async () => {
    stubFetch((url, init) =>
      url === '/api/v1/reports/exports' && init?.method === 'POST'
        ? problemResponse(403, 'urn:kronoqr:problem:forbidden')
        : jsonResponse({ data: [] }),
    )
    const wrapper = await mountView(LegalExportView)

    await submitWholeWorkforce(wrapper)

    expect(wrapper.find('[data-test="export-job-error"]').exists()).toBe(true)
    expect(wrapper.find('[data-test="export-job"]').exists()).toBe(false)
    expect(liveAnnouncement.value).toBe(es.reportExports.job.announce.requestFailed)
  })
})
