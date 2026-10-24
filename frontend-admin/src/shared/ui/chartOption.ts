// La opcion de ECharts de `ChartWithTable`, como funcion pura de los tokens.
//
// **LOS COLORES SALEN DE LOS TOKENS `--kq-*`, SIN RESPALDO LITERAL** (doc 06):
// este modulo no contiene ni un color. Quien lo llama pasa
// un lector de tokens (`getComputedStyle` en el navegador; en las pruebas, el
// propio `theme.css`), de modo que la marca blanca y cualquier
// cambio del tema llegan al grafico, y una prueba compara lo que se pinta con
// lo que dice el tema. Si el lector no devuelve un token (entorno sin hoja de
// estilos), esa propiedad se omite y ECharts usa la suya: nunca un color
// inventado aqui.
//
// **EL COLOR NO ES LA UNICA SEÑAL** (WCAG 1.4.1): cada serie lleva un
// patron propio ademas del tono (lisa, rayas, puntos, rayas cruzadas), y la
// leyenda lo hereda. Con un acento de marca verdoso la salvia fija de la
// segunda serie se parece al acento de la primera; el patron las separa sin
// depender del tono. El rosco ya rotula cada porcion con su nombre.
import { chartSeriesTokens } from '@kronoqr/web-kit/chartSeries'

export type TokenReader = (name: string) => string

export interface ChartSeriesInput {
  readonly name: string
  readonly values: readonly (number | null)[]
}

export interface ChartOptionInput {
  readonly type: 'pie' | 'bar'
  readonly categories: readonly string[]
  readonly series: readonly ChartSeriesInput[]
  readonly animation: boolean
  readonly formatTooltipValue: (value: unknown) => string
}

/** Un patron de relleno de ECharts (`decal`) o `undefined` para la serie lisa. */
type Decal = Readonly<Record<string, unknown>> | undefined

/**
 * Los cuatro rellenos, en el orden de las series: lisa, rayas diagonales,
 * puntos y rayas en el otro sentido. Cuatro series como maximo ya tienen tono
 * propio (`chartSeriesTokens`); si hubiera mas, el ciclo se repite igual que los
 * colores.
 */
function decalsFor(stroke: string): readonly Decal[] {
  const color = stroke === '' ? undefined : stroke
  return [
    undefined,
    {
      symbol: 'rect',
      symbolSize: 1,
      rotation: Math.PI / 4,
      dashArrayX: [1, 0],
      dashArrayY: [3, 5],
      ...(color === undefined ? {} : { color }),
    },
    {
      symbol: 'circle',
      symbolSize: 1.4,
      dashArrayX: [6, 6],
      dashArrayY: [6, 6],
      ...(color === undefined ? {} : { color }),
    },
    {
      symbol: 'rect',
      symbolSize: 1,
      rotation: -Math.PI / 4,
      dashArrayX: [1, 0],
      dashArrayY: [3, 5],
      ...(color === undefined ? {} : { color }),
    },
  ]
}

const decalAt = (decals: readonly Decal[], index: number): Decal => decals[index % decals.length]

export function buildChartOption(
  input: ChartOptionInput,
  readToken: TokenReader,
): Record<string, unknown> {
  const text = readToken('--kq-color-text')
  const border = readToken('--kq-color-border')
  // El patron se dibuja con el color de la tarjeta: raya clara sobre el tono de la serie.
  const decals = decalsFor(readToken('--kq-color-surface-raised'))
  const palette = chartSeriesTokens.map((token) => readToken(token))
  const hasPalette = palette.every((color) => color !== '')

  const common = {
    animation: input.animation,
    ...(hasPalette ? { color: palette } : {}),
    ...(text === '' ? {} : { textStyle: { color: text } }),
  }
  const textStyle = text === '' ? {} : { textStyle: { color: text } }

  if (input.type === 'pie') {
    const first = input.series[0]

    return {
      ...common,
      tooltip: { trigger: 'item', valueFormatter: input.formatTooltipValue },
      legend: { bottom: 0, ...textStyle },
      series: [
        {
          type: 'pie',
          radius: ['45%', '70%'],
          avoidLabelOverlap: true,
          // Rotulado directo: cada porcion lleva su nombre al lado.
          label: { show: true, formatter: '{b}', ...(text === '' ? {} : { color: text }) },
          data: (first?.values ?? []).map((value, index) => ({
            name: input.categories[index] ?? '',
            value,
            itemStyle: { decal: decalAt(decals, index) },
          })),
        },
      ],
    }
  }

  const axisLine = border === '' ? {} : { axisLine: { lineStyle: { color: border } } }
  const axisLabel = text === '' ? {} : { axisLabel: { color: text } }

  return {
    ...common,
    tooltip: { trigger: 'axis', valueFormatter: input.formatTooltipValue },
    legend: { top: 0, ...textStyle },
    grid: { left: 56, right: 16, top: 32, bottom: 32, containLabel: true },
    xAxis: { type: 'category', data: [...input.categories], ...axisLine, ...axisLabel },
    yAxis: {
      type: 'value',
      ...axisLine,
      ...axisLabel,
      ...(border === '' ? {} : { splitLine: { lineStyle: { color: border } } }),
    },
    series: input.series.map((entry, index) => ({
      name: entry.name,
      type: 'bar',
      data: [...entry.values],
      itemStyle: { decal: decalAt(decals, index) },
    })),
  }
}
