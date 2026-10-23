// U2 / R7-UX-02 / V6-UX-3: el grafico pinta con los tokens del tema, sin colores
// propios, y sus series se distinguen sin depender del tono.
//
// Los tokens se leen de `theme.css` tal como lo consumen las SPA: lo que esta
// prueba compara con la opcion de ECharts es la fuente de verdad, no una copia.
import { chartSeriesTokens } from '@kronoqr/web-kit/chartSeries'
import { contrastRatio, WCAG_AA_MINIMUM } from '@kronoqr/web-kit/contrast'
import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import { describe, expect, it } from 'vitest'
import { buildChartOption, type ChartOptionInput } from '@/shared/ui/chartOption'

const css = readFileSync(
  resolve(__dirname, '../../../packages/web-kit/src/theme.css'),
  'utf8',
).replace(/\/\*[\s\S]*?\*\//g, '')
const rootBlock = css.slice(css.indexOf(':root'), css.indexOf('@theme'))
const theme = new Map<string, string>()
for (const match of rootBlock.matchAll(/(--[\w-]+)\s*:\s*([^;]+);/g)) {
  theme.set(match[1] ?? '', (match[2] ?? '').trim())
}
const fromTheme = (name: string): string => theme.get(name) ?? ''

const base: Omit<ChartOptionInput, 'type'> = {
  categories: ['QR', 'PIN', 'Manual', 'Importacion'],
  series: [
    { name: 'Actual', values: [10, 20, 5, 1] },
    { name: 'Anterior', values: [9, 18, 6, null] },
    { name: 'Objetivo', values: [12, 12, 12, 12] },
  ],
  animation: false,
  formatTooltipValue: (value) => String(value),
}

type Option = {
  color?: string[]
  textStyle?: { color?: string }
  series: Array<{
    itemStyle?: { decal?: unknown }
    data: Array<{ itemStyle?: { decal?: unknown } }>
  }>
}

const bar = (reader = fromTheme): Option =>
  buildChartOption({ ...base, type: 'bar' }, reader) as unknown as Option
const pie = (reader = fromTheme): Option =>
  buildChartOption({ ...base, type: 'pie' }, reader) as unknown as Option

describe('chartOption: los colores son los del tema', () => {
  it('la paleta de series es, en orden, el valor de cada token de chartSeriesTokens', () => {
    expect(bar().color).toEqual(chartSeriesTokens.map(fromTheme))
    expect(pie().color).toEqual(chartSeriesTokens.map(fromTheme))
  })

  it('el texto es el del tema y cambia si el tema cambia (marca en caliente)', () => {
    expect(bar().textStyle?.color).toBe(fromTheme('--kq-color-text'))

    const branded = (name: string): string =>
      name === '--kq-color-text' ? 'var(--otro)' : fromTheme(name)

    expect(bar(branded).textStyle?.color).toBe('var(--otro)')
  })

  it('sin hoja de estilos no inventa colores: omite la propiedad', () => {
    const option = bar(() => '')

    expect(option.color).toBeUndefined()
    expect(option.textStyle).toBeUndefined()
    expect(JSON.stringify(option)).not.toMatch(/#[0-9a-f]{3,8}|rgb|hsl/i)
  })

  it('cada serie se mide como componente grafico contra la tarjeta (>= 3:1)', () => {
    const card = fromTheme('--kq-color-surface-raised')

    for (const token of chartSeriesTokens) {
      expect(contrastRatio(fromTheme(token), card), token).toBeGreaterThanOrEqual(
        WCAG_AA_MINIMUM.large,
      )
    }
  })
})

describe('chartOption: las series se distinguen sin depender del tono (V6-UX-3)', () => {
  it('cada serie de barras lleva un relleno distinto', () => {
    const decals = bar().series.map((entry) => JSON.stringify(entry.itemStyle?.decal ?? null))

    expect(new Set(decals).size).toBe(decals.length)
  })

  it('cada porcion del rosco lleva un relleno distinto', () => {
    const slices = pie().series[0]?.data ?? []
    const decals = slices.map((slice) => JSON.stringify(slice.itemStyle?.decal ?? null))

    expect(new Set(decals).size).toBe(slices.length)
  })

  it('el patron se dibuja con el color de la tarjeta, no con uno propio', () => {
    const decal = bar().series[1]?.itemStyle?.decal as { color?: string }

    expect(decal.color).toBe(fromTheme('--kq-color-surface-raised'))
  })

  it('el rosco rotula cada porcion con su nombre', () => {
    const option = pie() as unknown as {
      series: Array<{ label: { show: boolean; formatter: string } }>
    }

    expect(option.series[0]?.label).toMatchObject({ show: true, formatter: '{b}' })
  })

  it('un null sigue siendo hueco: no se convierte en cero', () => {
    const second = bar().series[1] as unknown as { data: Array<number | null> }

    expect(second.data[3]).toBeNull()
  })
})
