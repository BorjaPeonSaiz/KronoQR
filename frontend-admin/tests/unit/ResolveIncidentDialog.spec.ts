// Dialogo de resolucion de una incidencia (RF-PA-05, RN-13).
//
// Lo que se afirma: que no se puede enviar sin nota (validacion local, antes de
// gastar una peticion), que un 422 del servidor se enseña con su detalle por
// campo, que un cierre con exito emite la incidencia entera para que la
// bandeja sustituya la fila, y que `Escape` cierra el dialogo.
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import ResolveIncidentDialog from '@/features/incidents/ResolveIncidentDialog.vue'
import es from '@/shared/i18n/locales/es.json'
import { useIncidentsStore } from '@/features/incidents/incidents.store'
import { incident, incidentCollection } from './support/fixtures'
import {
  createTestPinia,
  jsonResponse,
  mountView,
  problemResponse,
  settle,
  stubFetch,
} from './support/harness'

type Wrapper = Awaited<ReturnType<typeof mountView>>

async function mountDialog(): Promise<Wrapper> {
  const pinia = createTestPinia()

  return mountView(ResolveIncidentDialog, {
    props: { incident: incident(), timeZone: 'Europe/Madrid' },
    pinia,
  })
}

beforeEach(() => {
  createTestPinia()
})

afterEach(() => {
  vi.unstubAllGlobals()
})

describe('ResolveIncidentDialog', () => {
  it('sin persona, el cuerpo nombra el quiosco en vez de una persona y se puede resolver', async () => {
    const wrapper = await mountView(ResolveIncidentDialog, {
      props: {
        incident: incident({
          type: 'discarded_scan',
          employee: null,
          context: { device_uuid: '0199f3c9-1b7d-7a44-8e02-3c4d5e6f7a81', attribution: 'none' },
        }),
        timeZone: 'Europe/Madrid',
      },
      pinia: createTestPinia(),
    })

    expect(wrapper.text()).toContain('Fichaje sin persona en 0199f3c9…')
    expect(wrapper.text()).toContain('atribuido por: sin identificar')

    wrapper.unmount()
  })

  it('no deja confirmar sin nota, y lo dice antes de gastar una peticion', async () => {
    const spy = stubFetch(() => jsonResponse({}))
    const wrapper = await mountDialog()

    const submit = wrapper.get('button[type="button"]:not([disabled])')
    // El primer boton habilitado es «Cancelar»; el de confirmar esta deshabilitado.
    expect(submit.text()).toContain('Cancelar')

    const confirm = wrapper.findAll('button').find((button) => button.text().includes('Confirmar'))

    expect(confirm?.attributes('aria-disabled')).toBeDefined()

    await wrapper.get('textarea').setValue('no')
    await settle()

    expect(
      wrapper
        .findAll('button')
        .find((button) => button.text().includes('Confirmar'))
        ?.attributes('aria-disabled'),
    ).toBeDefined()
    expect(wrapper.text()).toContain('Escribe al menos 3 caracteres.')
    expect(spy).not.toHaveBeenCalled()
  })

  it('avisa cuando la nota supera el máximo, sin dejar enviar', async () => {
    const wrapper = await mountDialog()

    await wrapper.get('textarea').setValue('x'.repeat(1_001))
    await settle()

    expect(wrapper.text()).toContain('El texto no puede superar los 1000 caracteres.')
  })

  it('al confirmar, cierra la incidencia y emite la fila entera para sustituirla', async () => {
    const closed = { ...incident(), status: 'resolved' as const }
    stubFetch((url, init) => {
      expect(url).toContain('/api/v1/incidents/412/resolve')
      expect(init?.method).toBe('POST')

      return jsonResponse(closed)
    })
    const wrapper = await mountDialog()

    await wrapper.get('textarea').setValue('Corregido con el parte de turno.')
    await settle()
    await wrapper
      .findAll('button')
      .find((button) => button.text().includes('Confirmar'))
      ?.trigger('click')
    await settle()

    expect(wrapper.emitted('resolved')?.[0]).toEqual([closed])
  })

  it('un 422 del servidor se enseña junto al formulario, sin cerrar el dialogo', async () => {
    stubFetch(() =>
      problemResponse(422, 'urn:kronoqr:problem:validation-failed', {
        errors: { note: ['La nota no puede estar vacía.'] },
      }),
    )
    const wrapper = await mountDialog()

    await wrapper.get('textarea').setValue('Una nota valida de sobra.')
    await settle()
    await wrapper
      .findAll('button')
      .find((button) => button.text().includes('Confirmar'))
      ?.trigger('click')
    await settle()

    expect(wrapper.text()).toContain('La nota no puede estar vacía.')
    expect(wrapper.emitted('resolved')).toBeUndefined()
  })

  it('Escape cierra el dialogo sin confirmar nada', async () => {
    const wrapper = await mountDialog()

    await wrapper.find('[role="dialog"]').trigger('keydown', { key: 'Escape' })

    expect(wrapper.emitted('cancel')).toHaveLength(1)
  })
  describe('conflicto al resolver (RN-19)', () => {
    async function conflictFor(closed: ReturnType<typeof incident>): Promise<Wrapper> {
      stubFetch((url, init) =>
        init?.method === 'POST'
          ? problemResponse(409, 'urn:kronoqr:problem:conflict')
          : jsonResponse(incidentCollection(url.includes('status=resolved') ? [closed] : [])),
      )

      const pinia = createTestPinia()
      const open = incident()

      useIncidentsStore(pinia).entries = [open]

      const wrapper = await mountView(ResolveIncidentDialog, {
        props: { incident: open, timeZone: 'Europe/Madrid' },
        pinia,
      })

      await wrapper.get('textarea').setValue('Corregido con el parte de turno.')
      await settle()
      await wrapper
        .findAll('button')
        .find((button) => button.text().includes('Confirmar'))
        ?.trigger('click')
      await settle(8)

      return wrapper
    }

    it('RN-19: una incidencia cerrada por el sistema dice que se cerro sola, no que otra persona la resolvio', async () => {
      const wrapper = await conflictFor(
        incident({
          status: 'resolved',
          resolved_at: '2026-03-15T08:05:00.000000Z',
          resolved_by: null,
          resolution_note: null,
        }),
      )

      expect(wrapper.get('[data-test="conflict-closed-by-system"]').text()).toBe(
        es.incidents.dialog.conflict.closedBySystem,
      )
      expect(wrapper.text()).not.toContain(es.incidents.dialog.conflict.resolvedByUnknown)
    })

    it('RN-19: con autor sigue nombrando a la persona', async () => {
      const wrapper = await conflictFor(
        incident({
          status: 'resolved',
          resolved_at: '2026-03-15T08:05:00.000000Z',
          resolved_by: { uuid: '0199f0aa-2222-7000-8000-0123456789ab', name: 'Marta Ruiz' },
          resolution_note: 'Ya corregida.',
        }),
      )

      expect(wrapper.find('[data-test="conflict-closed-by-system"]').exists()).toBe(false)
      expect(wrapper.text()).toContain('Marta Ruiz')
    })
  })
})
