import type { DOMWrapper } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import LegalExportView from '@/features/reports/LegalExportView.vue'
import es from '@/shared/i18n/locales/es.json'
import { announcement, clearAnnouncement } from '@kronoqr/web-kit/announcer'
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
    const fetchSpy = stubFetch(() => csvResponse(1240, 17))
    const wrapper = await mountView(LegalExportView)

    await fillPeriod(wrapper, '2026-01-01', '2026-01-31')
    await wrapper.find('form').trigger('submit')
    await settle()

    const [url, init] = fetchSpy.mock.calls[0] as [string, RequestInit]

    expect(url).toBe('/api/v1/reports/legal-export?from=2026-01-01&to=2026-01-31')

    // Sin persona, el parametro no viaja: el alcance es la plantilla completa, y
    // un `employee_uuid=` vacio seria un alcance que el servidor tendria que
    // adivinar.
    expect(url).not.toContain('employee_uuid')

    // RL-06: se pide un fichero tabular, no JSON. Un `Accept` equivocado es la
    // forma de recibir un 406 que nadie sabe explicar.
    expect(new Headers(init.headers).get('Accept')).toContain('text/csv')

    // Y se suelta en el acto: es una lista nominal de la plantilla con sus
    // horas, no se queda viva en el navegador.
    expect(revokeObjectURL).toHaveBeenCalledWith('blob:kronoqr')
  })

  it('enseña las cifras que devolvio el servidor, no un recuento propio', async () => {
    stubFetch(() => csvResponse(1240, 17))
    const wrapper = await mountView(LegalExportView)

    await fillPeriod(wrapper, '2026-01-01', '2026-01-31')
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
      wrapper.find<HTMLButtonElement>('button[type="submit"]').attributes('disabled'),
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

    expect(submit.attributes('disabled')).toBeDefined()
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
