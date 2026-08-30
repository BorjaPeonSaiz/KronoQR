// Dialogo de resolucion de una incidencia (RF-PA-05, RN-13).
//
// Lo que se afirma: que no se puede enviar sin nota (validacion local, antes de
// gastar una peticion), que un 422 del servidor se enseña con su detalle por
// campo, que un cierre con exito emite la incidencia entera para que la
// bandeja sustituya la fila, y que `Escape` cierra el dialogo.
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import ResolveIncidentDialog from '@/features/incidents/ResolveIncidentDialog.vue'
import { incident } from './support/fixtures'
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
  it('no deja confirmar sin nota, y lo dice antes de gastar una peticion', async () => {
    const spy = stubFetch(() => jsonResponse({}))
    const wrapper = await mountDialog()

    const submit = wrapper.get('button[type="button"]:not([disabled])')
    // El primer boton habilitado es «Cancelar»; el de confirmar esta deshabilitado.
    expect(submit.text()).toContain('Cancelar')

    const confirm = wrapper.findAll('button').find((button) => button.text().includes('Confirmar'))

    expect(confirm?.attributes('disabled')).toBeDefined()

    await wrapper.get('textarea').setValue('no')
    await settle()

    expect(
      wrapper
        .findAll('button')
        .find((button) => button.text().includes('Confirmar'))
        ?.attributes('disabled'),
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
})
