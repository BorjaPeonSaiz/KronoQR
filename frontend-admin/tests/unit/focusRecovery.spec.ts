// El foco no cae en <body> cuando lo que lo tenia se retira (V7-PA-4, V21-7-PA-1):
// «lo que se retira devuelve el foco a su origen». Hasta ahora ninguna prueba del
// repositorio medía adonde va el foco al abrir, cerrar o confirmar (V21-7-PA-1).
import { mount } from '@vue/test-utils'
import { afterEach, beforeEach, describe, expect, it } from 'vitest'
import { defineComponent, h, nextTick, ref } from 'vue'
import BaseDialog from '@/shared/ui/BaseDialog.vue'
import { installFocusRecovery } from '@/shared/ui/focusRecovery'

let uninstall: (() => void) | null = null

beforeEach(() => {
  document.body.innerHTML = '<main id="main"></main>'
  uninstall = installFocusRecovery({ fallback: () => document.getElementById('main') })
})

afterEach(() => {
  uninstall?.()
  document.body.innerHTML = ''
})

/** Deja pasar el callback del MutationObserver. */
async function settle(): Promise<void> {
  await nextTick()
  await new Promise<void>((resolve) => setTimeout(resolve, 0))
}

function main(): HTMLElement {
  return document.getElementById('main') as HTMLElement
}

describe('focusRecovery', () => {
  it('si se retira el control enfocado, el foco pasa al contenedor enfocable mas cercano', async () => {
    main().innerHTML =
      '<div id="region" tabindex="0" role="region" aria-label="Tabla"><button id="row">Resolver</button></div>'
    ;(document.getElementById('row') as HTMLElement).focus()
    expect(document.activeElement?.id).toBe('row')

    document.getElementById('row')?.remove()
    await settle()

    expect(document.activeElement?.id).toBe('region')
  })

  it('sin ningun ancestro enfocable, el foco va al contenido principal (no al enlace de salto)', async () => {
    main().innerHTML = '<div><button id="pdf">PDF</button></div>'
    ;(document.getElementById('pdf') as HTMLElement).focus()

    main().innerHTML = '<p>Otro aviso</p>'
    await settle()

    expect(document.activeElement).toBe(main())
    expect(main().getAttribute('tabindex')).toBe('-1')
  })

  it('no toca el foco si otra cosa lo ha recogido', async () => {
    main().innerHTML = '<button id="a">A</button><button id="b">B</button>'
    ;(document.getElementById('a') as HTMLElement).focus()
    ;(document.getElementById('b') as HTMLElement).focus()

    document.getElementById('a')?.remove()
    await settle()

    expect(document.activeElement?.id).toBe('b')
  })

  it('no mueve el foco si el control enfocado sigue en el documento', async () => {
    main().innerHTML = '<button id="a">A</button>'
    ;(document.getElementById('a') as HTMLElement).focus()

    main().insertAdjacentHTML('beforeend', '<p>algo nuevo</p>')
    await settle()

    expect(document.activeElement?.id).toBe('a')
  })

  it('un dialogo que confirma y retira la fila de la que salio deja el foco en la tabla', async () => {
    const Host = defineComponent({
      setup() {
        const rows = ref([1, 2])
        const open = ref(false)

        function confirm(): void {
          rows.value = rows.value.slice(1)
          open.value = false
        }

        return () =>
          h('div', [
            h(
              'div',
              { id: 'region', tabindex: 0, role: 'region', 'aria-label': 'Incidencias' },
              rows.value.map((row) =>
                h(
                  'button',
                  {
                    id: `row-${row}`,
                    key: row,
                    type: 'button',
                    onClick: () => (open.value = true),
                  },
                  `Resolver ${row}`,
                ),
              ),
            ),
            open.value
              ? h(
                  BaseDialog,
                  { title: 'Resolver', onClose: () => (open.value = false) },
                  {
                    default: () => h('input', { id: 'note', type: 'text' }),
                    actions: () =>
                      h('button', { id: 'ok', type: 'button', onClick: confirm }, 'Ok'),
                  },
                )
              : null,
          ])
      },
    })
    const wrapper = mount(Host, { attachTo: main() })
    const first = document.getElementById('row-1') as HTMLButtonElement

    first.focus()
    first.click()
    await nextTick()
    expect(document.activeElement?.id).toBe('note')
    ;(document.getElementById('ok') as HTMLButtonElement).click()
    await nextTick()
    await settle()

    // La fila 1 ya no existe: el foco esta en la tabla, no en <body>.
    expect(document.getElementById('row-1')).toBeNull()
    expect(document.activeElement?.id).toBe('region')

    wrapper.unmount()
  })
})

describe('focusRecovery: un control que se deshabilita (V21-7-PA-1)', () => {
  it('el foco no se queda en <body> cuando el boton enfocado pasa a deshabilitado', async () => {
    main().innerHTML = '<form id="form"><button id="go" type="submit">Generar</button></form>'
    const button = document.getElementById('go') as HTMLButtonElement

    button.focus()
    // jsdom conserva el foco en un control deshabilitado; Chromium lo suelta en <body>.
    button.blur()
    button.disabled = true
    await settle()

    expect(document.activeElement).toBe(main())
  })
})
