// Componente compartido `ChartWithTable` (tarea 3.13, RF-IN-08): primer uso
// de ECharts del proyecto, con tabla de datos alternativa conmutable.
//
// EL GRAFICO NO SE MONTA EN JSDOM (no hay lienzo real): ECharts se sustituye
// por un doble explicito, igual que el resto de la suite sustituye `fetch`
// (`support/harness.ts`). Lo que se comprueba aqui es el CONTRATO -las mismas
// props, la misma tabla, el mismo conmutador- y no el dibujo del lienzo, que
// es cosa de la libreria y no de este componente.
import { beforeEach, describe, expect, it, vi } from 'vitest'
import ChartWithTable from '@/shared/ui/ChartWithTable.vue'
import { mountView, settle } from './support/harness'

const setOption = vi.fn()
const resize = vi.fn()
const dispose = vi.fn()
const init = vi.fn(() => ({ setOption, resize, dispose }))
const use = vi.fn()

vi.mock('echarts/core', () => ({ init, use }))
vi.mock('echarts/charts', () => ({ BarChart: {}, PieChart: {} }))
vi.mock('echarts/components', () => ({
  TooltipComponent: {},
  LegendComponent: {},
  GridComponent: {},
}))
vi.mock('echarts/renderers', () => ({ CanvasRenderer: {} }))

beforeEach(() => {
  setOption.mockClear()
  resize.mockClear()
  dispose.mockClear()
  init.mockClear()
  use.mockClear()
})

describe('ChartWithTable', () => {
  it('por omision enseña el grafico, con el conmutador sin pulsar', async () => {
    const wrapper = await mountView(ChartWithTable, {
      props: {
        type: 'bar',
        title: 'Reparto de ejemplo',
        categories: ['A', 'B'],
        series: [{ name: 'Serie 1', values: [10, 20] }],
      },
    })
    await settle()

    expect(wrapper.find('[data-test="chart-canvas"]').isVisible()).toBe(true)
    expect(wrapper.find('[data-test="chart-table"]').exists()).toBe(false)
    expect(wrapper.get('[data-test="toggle-view"]').attributes('aria-pressed')).toBe('false')
  })

  it('el conmutador cambia a la tabla, con los MISMOS datos que la serie', async () => {
    const wrapper = await mountView(ChartWithTable, {
      props: {
        type: 'bar',
        title: 'Reparto de ejemplo',
        categories: ['QR', 'PIN'],
        series: [
          { name: 'Actual', values: [97, 3] },
          { name: 'Anterior', values: [95, 5] },
        ],
        formatValue: (value: number) => `${value} %`,
      },
    })
    await settle()

    await wrapper.get('[data-test="toggle-view"]').trigger('click')

    expect(wrapper.get('[data-test="toggle-view"]').attributes('aria-pressed')).toBe('true')
    expect(wrapper.find('[data-test="chart-canvas"]').isVisible()).toBe(false)

    const table = wrapper.get('[data-test="chart-table"]')
    const headers = table.findAll('th')

    // Cabecera + una columna por serie (`category`, «Actual», «Anterior»).
    expect(headers.map((header) => header.text())).toEqual(
      expect.arrayContaining(['Actual', 'Anterior']),
    )

    const rows = table.findAll('tbody tr')

    expect(rows).toHaveLength(2)
    expect(rows[0]?.text()).toContain('QR')
    expect(rows[0]?.text()).toContain('97 %')
    expect(rows[0]?.text()).toContain('95 %')
    expect(rows[1]?.text()).toContain('PIN')
    expect(rows[1]?.text()).toContain('3 %')
    expect(rows[1]?.text()).toContain('5 %')

    // Se puede volver al grafico: no es una vista de un solo sentido.
    await wrapper.get('[data-test="toggle-view"]').trigger('click')
    expect(wrapper.get('[data-test="toggle-view"]').attributes('aria-pressed')).toBe('false')
    expect(wrapper.find('[data-test="chart-table"]').exists()).toBe(false)
  })

  it('un valor `null` se enseña como «sin datos» en la tabla, nunca como formatValue(0) (segunda vuelta de la ficha)', async () => {
    const wrapper = await mountView(ChartWithTable, {
      props: {
        type: 'pie',
        title: 'Rosco de ejemplo',
        categories: ['QR', 'PIN', 'Manual', 'Importación'],
        series: [{ name: 'Actual', values: [null, null, null, null] }],
        formatValue: (value: number) => `${value} %`,
      },
    })
    await settle()

    await wrapper.get('[data-test="toggle-view"]').trigger('click')

    const table = wrapper.get('[data-test="chart-table"]')
    const cells = table.findAll('tbody td')

    expect(cells).toHaveLength(4)

    for (const cell of cells) {
      expect(cell.text()).toBe('Sin datos')
      expect(cell.text()).not.toContain('0 %')
    }
  })

  it('sin formateador, el valor se enseña tal cual', async () => {
    const wrapper = await mountView(ChartWithTable, {
      props: {
        type: 'pie',
        title: 'Rosco de ejemplo',
        categories: ['QR'],
        series: [{ name: 'Actual', values: [42] }],
      },
    })
    await settle()

    await wrapper.get('[data-test="toggle-view"]').trigger('click')

    expect(wrapper.get('[data-test="chart-table"]').text()).toContain('42')
  })
})
