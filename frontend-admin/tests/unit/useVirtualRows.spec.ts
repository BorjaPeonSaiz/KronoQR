// El mecanismo comun de virtualizacion de las tablas largas del panel
// (RF-IN-01, RF-PA-06, RNF-P-04).
//
// Por debajo del umbral se pintan todas las filas; por encima, solo la ventana
// visible con su sobrepintado, y el alto total reserva siempre el de TODAS las
// filas. jsdom no maqueta: se le da al contenedor un alto de 600 px para que el
// virtualizador tenga ventana.
import { computed, defineComponent, h, ref } from 'vue'
import { mount } from '@vue/test-utils'
import { settle } from './support/harness'
import { afterAll, beforeAll, describe, expect, it } from 'vitest'
import {
  useVirtualRows,
  VIRTUALIZE_FROM,
  type VirtualRows,
} from '@/shared/virtualization/useVirtualRows'

const ROW_HEIGHT = 40
const VIEWPORT_HEIGHT_PX = 600
const OVERSCAN = 10
/** Filas que caben enteras en la ventana. */
const FITTING = VIEWPORT_HEIGHT_PX / ROW_HEIGHT

const heightDescriptor = Object.getOwnPropertyDescriptor(HTMLElement.prototype, 'offsetHeight')
const widthDescriptor = Object.getOwnPropertyDescriptor(HTMLElement.prototype, 'offsetWidth')

beforeAll(() => {
  Object.defineProperty(HTMLElement.prototype, 'offsetHeight', {
    configurable: true,
    get: () => VIEWPORT_HEIGHT_PX,
  })
  Object.defineProperty(HTMLElement.prototype, 'offsetWidth', {
    configurable: true,
    get: () => 1200,
  })
})

afterAll(() => {
  for (const [name, descriptor] of [
    ['offsetHeight', heightDescriptor],
    ['offsetWidth', widthDescriptor],
  ] as const) {
    if (descriptor === undefined) {
      Reflect.deleteProperty(HTMLElement.prototype, name)
    } else {
      Object.defineProperty(HTMLElement.prototype, name, descriptor)
    }
  }
})

interface Item {
  id: number
}

function items(count: number): Item[] {
  return Array.from({ length: count }, (_, index) => ({ id: 1000 + index }))
}

/** Monta un componente que usa el composable sobre un contenedor real y devuelve su resultado. */
async function mountRows(
  count: number,
  extra: { key?: (item: Item, index: number) => string; overscan?: number } = {},
): Promise<VirtualRows<Item>> {
  let result: VirtualRows<Item> | null = null

  const Host = defineComponent({
    setup() {
      const scroller = ref<HTMLElement | null>(null)
      const list = computed(() => items(count))

      result = useVirtualRows(list, { rowHeight: ROW_HEIGHT, scroller, ...extra })

      return () => h('div', { ref: scroller })
    },
  })

  mount(Host, { attachTo: document.body })
  await settle()

  if (result === null) {
    throw new Error('el composable no se ha ejecutado')
  }

  return result
}

describe('useVirtualRows', () => {
  it('por debajo del umbral pinta todas las filas, cada una en su posicion', async () => {
    const rows = await mountRows(VIRTUALIZE_FROM - 1)

    expect(rows.virtual.value).toBe(false)
    expect(rows.visibleRows.value).toHaveLength(VIRTUALIZE_FROM - 1)
    expect(rows.visibleRows.value[3]).toEqual({
      item: { id: 1003 },
      index: 3,
      start: 3 * ROW_HEIGHT,
      key: '3',
    })
    expect(rows.totalHeight.value).toBe((VIRTUALIZE_FROM - 1) * ROW_HEIGHT)
  })

  it('en el umbral pinta solo la ventana visible mas el sobrepintado', async () => {
    const rows = await mountRows(VIRTUALIZE_FROM)

    expect(rows.virtual.value).toBe(true)
    expect(rows.visibleRows.value.length).toBeLessThanOrEqual(FITTING + OVERSCAN + 1)
    expect(rows.visibleRows.value.length).toBeGreaterThan(0)
    expect(rows.visibleRows.value[0]?.index).toBe(0)
    expect(rows.totalHeight.value).toBe(VIRTUALIZE_FROM * ROW_HEIGHT)
  })

  it('con 20 000 filas el DOM no crece y el alto reserva todas', async () => {
    const rows = await mountRows(20_000)

    expect(rows.visibleRows.value.length).toBeLessThan(VIRTUALIZE_FROM)
    expect(rows.totalHeight.value).toBe(20_000 * ROW_HEIGHT)
  })

  it('usa la clave de la fila, en las dos ramas, y el indice si no se da', async () => {
    const key = (item: Item): string => `id-${item.id}`

    expect((await mountRows(5, { key })).visibleRows.value.map((row) => row.key)).toEqual([
      'id-1000',
      'id-1001',
      'id-1002',
      'id-1003',
      'id-1004',
    ])
    expect((await mountRows(200, { key })).visibleRows.value[0]?.key).toBe('id-1000')
    expect((await mountRows(200)).visibleRows.value[0]?.key).toBe('0')
  })

  it('el sobrepintado se puede ajustar', async () => {
    const narrow = (await mountRows(200, { overscan: 0 })).visibleRows.value.length
    const wide = (await mountRows(200, { overscan: 20 })).visibleRows.value.length

    expect(wide).toBeGreaterThan(narrow)
  })

  it('sin filas no hay nada que pintar ni alto que reservar', async () => {
    const rows = await mountRows(0)

    expect(rows.visibleRows.value).toEqual([])
    expect(rows.totalHeight.value).toBe(0)
  })
})
