<script setup lang="ts">
// Primer uso de ECharts del proyecto (tarea 3.13, RF-IN-08).
//
// **TABLA DE DATOS ALTERNATIVA, CONMUTABLE.** Es lo que hace el grafico
// accesible (doc 02 §3.3): un boton con `aria-pressed` cambia entre el lienzo
// y una tabla `<table>` real con cabeceras, con LOS MISMOS DATOS que la serie
// -nunca un resumen aparte que pueda desincronizarse-. Por omision se enseña
// el grafico; quien navega con lector de pantalla o quien simplemente
// necesita el numero exacto pulsa el boton y lee la tabla.
//
// **ECHARTS SE CARGA EN DIFERIDO** (import dinamico de solo los modulos que
// hacen falta: `echarts/core` + `BarChart`/`PieChart` + `CanvasRenderer` +
// `Tooltip`/`Legend`/`Grid`), para no engordar el arranque del panel con una
// libreria que la mayoria de pantallas todavia no usa.
//
// **LOS COLORES SALEN DE LOS TOKENS `--kq-*` DEL `:root`, LEIDOS EN TIEMPO DE
// EJECUCION** (doc 06): ninguna SPA declara un color propio, y la marca
// blanca (tarea 5.8) cambia esos tokens sin que este componente tenga que
// saber que existe. Si el lienzo no llega a montarse -entorno sin lienzo, o
// un fallo de red al cargar el modulo-, el componente no se rompe: se queda
// en la tabla, que es autosuficiente.
//
// **`prefers-reduced-motion`** apaga la animacion de entrada del grafico
// (doc 06 §6, regla 10): la tabla nunca depende de movimiento.
import { computed, nextTick, onBeforeUnmount, onMounted, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'

/**
 * Una serie del grafico: una fila de barras, o el unico anillo de un rosco.
 *
 * `null` en `values` es un valor legitimo: «sin dato», nunca «cero» (segunda
 * vuelta de la tarea 3.13). ECharts dibuja un hueco en la barra o excluye la
 * porcion del rosco, y la tabla de datos alternativa escribe
 * `chartWithTable.noData` en esa celda -nunca `formatValue(0)`, que
 * convertiria «no se sabe» en una afirmacion («cero») que nadie midio-.
 */
export interface ChartSeries {
  readonly name: string
  readonly values: readonly (number | null)[]
}

/** Instancia minima de ECharts que este componente necesita, sin importar el tipo pesado del paquete. */
interface EChartsLikeInstance {
  setOption: (option: Record<string, unknown>, notMerge?: boolean) => void
  resize: () => void
  dispose: () => void
}

const props = withDefaults(
  defineProps<{
    /** `pie` para un rosco de reparto; `bar` para comparar series (actual frente a anterior). */
    type: 'pie' | 'bar'
    /** Titulo visible sobre el grafico y usado en la region viva del conmutador. */
    title: string
    /** Las categorias del eje X (`bar`) o las porciones (`pie`), en el mismo orden que `series[].values`. */
    categories: readonly string[]
    series: readonly ChartSeries[]
    /** Formatea un valor para la tabla y el tooltip. Por omision, el numero tal cual. */
    formatValue?: (value: number) => string
    /** Alto del lienzo en pixeles. */
    height?: number
  }>(),
  {
    formatValue: (value: number) => String(value),
    height: 280,
  },
)

const { t } = useI18n()

/** `chart` (por omision) o `table`. Conmutable con el boton, nunca con dos vistas simultaneas. */
const viewMode = ref<'chart' | 'table'>('chart')
const chartFailed = ref(false)

const container = ref<HTMLDivElement | null>(null)
let instance: EChartsLikeInstance | null = null

function isChartView(): boolean {
  return viewMode.value === 'chart' && !chartFailed.value
}

async function toggleView(): Promise<void> {
  if (chartFailed.value) {
    return
  }

  viewMode.value = viewMode.value === 'chart' ? 'table' : 'chart'

  if (viewMode.value === 'chart') {
    await nextTick()
    instance?.resize()
  }
}

/** Un token `--kq-*` del `:root`, o `fallback` si el entorno no tiene `window` (SSR, pruebas). */
function readToken(name: string, fallback: string): string {
  if (typeof window === 'undefined' || typeof document === 'undefined') {
    return fallback
  }

  const value = getComputedStyle(document.documentElement).getPropertyValue(name).trim()

  return value === '' ? fallback : value
}

function prefersReducedMotion(): boolean {
  return (
    typeof window !== 'undefined' &&
    typeof window.matchMedia === 'function' &&
    window.matchMedia('(prefers-reduced-motion: reduce)').matches
  )
}

/** El valor del tooltip de ECharts: `sin dato` para `null`/`undefined`, nunca `formatValue(0)`. */
function formatTooltipValue(value: unknown): string {
  return typeof value === 'number' ? props.formatValue(value) : t('chartWithTable.noData')
}

/**
 * La opcion de ECharts, construida SOLO con tokens `--kq-*` (doc 06): ningun
 * color propio de este componente. `readToken` trae el valor vigente en cada
 * llamada, asi que un cambio de marca en caliente (tarea 5.8) se refleja en
 * la proxima actualizacion.
 */
function buildOption(): Record<string, unknown> {
  const primary = readToken('--kq-color-primary-strong', '#b8542a')
  const accent = readToken('--kq-color-accent', '#7a9b76')
  const warning = readToken('--kq-color-warning', '#8a5312')
  const muted = readToken('--kq-color-text-muted', '#6b5d54')
  const textColor = readToken('--kq-color-text', '#3a2e28')
  const borderColor = readToken('--kq-color-border', '#eaddcf')
  const animation = !prefersReducedMotion()

  const common = {
    animation,
    color: [primary, accent, warning, muted],
    textStyle: { color: textColor },
  }

  if (props.type === 'pie') {
    const first = props.series[0]

    return {
      ...common,
      tooltip: {
        trigger: 'item',
        valueFormatter: formatTooltipValue,
      },
      legend: { bottom: 0, textStyle: { color: textColor } },
      series: [
        {
          type: 'pie',
          radius: ['45%', '70%'],
          avoidLabelOverlap: true,
          label: { color: textColor },
          data: (first?.values ?? []).map((value, index) => ({
            name: props.categories[index] ?? '',
            value,
          })),
        },
      ],
    }
  }

  return {
    ...common,
    tooltip: {
      trigger: 'axis',
      valueFormatter: formatTooltipValue,
    },
    legend: { top: 0, textStyle: { color: textColor } },
    grid: { left: 56, right: 16, top: 32, bottom: 32, containLabel: true },
    xAxis: {
      type: 'category',
      data: [...props.categories],
      axisLine: { lineStyle: { color: borderColor } },
      axisLabel: { color: textColor },
    },
    yAxis: {
      type: 'value',
      axisLine: { lineStyle: { color: borderColor } },
      axisLabel: { color: textColor },
      splitLine: { lineStyle: { color: borderColor } },
    },
    series: props.series.map((entry) => ({
      name: entry.name,
      type: 'bar',
      data: [...entry.values],
    })),
  }
}

/**
 * Carga ECharts EN DIFERIDO y monta el lienzo. Si algo falla -entorno sin
 * lienzo, fallo de red al cargar el modulo- se degrada a la tabla SIN
 * romper el componente: `chartFailed` deshabilita el conmutador y la tabla
 * queda como unica vista, que es autosuficiente por diseño.
 */
async function mountChart(): Promise<void> {
  if (container.value === null) {
    return
  }

  try {
    const [core, charts, components, renderers] = await Promise.all([
      import('echarts/core'),
      import('echarts/charts'),
      import('echarts/components'),
      import('echarts/renderers'),
    ])

    core.use([
      props.type === 'pie' ? charts.PieChart : charts.BarChart,
      components.TooltipComponent,
      components.LegendComponent,
      components.GridComponent,
      renderers.CanvasRenderer,
    ])

    if (container.value === null) {
      return
    }

    instance?.dispose()
    const created = core.init(container.value) as unknown as EChartsLikeInstance

    created.setOption(buildOption())
    instance = created
  } catch {
    // Degradacion deliberada (doc 06 §6, «el tiempo real degrada bien» aplica
    // igual aqui): sin lienzo no hay grafico, pero la tabla sigue sirviendo
    // los mismos datos.
    chartFailed.value = true
    viewMode.value = 'table'
  }
}

function handleResize(): void {
  instance?.resize()
}

onMounted(() => {
  void mountChart()
  window.addEventListener('resize', handleResize)
})

watch(
  () => [props.type, props.series, props.categories] as const,
  () => {
    if (instance === null) {
      return
    }

    instance.setOption(buildOption(), true)
  },
)

onBeforeUnmount(() => {
  window.removeEventListener('resize', handleResize)
  instance?.dispose()
  instance = null
})

/**
 * La misma matriz que pinta el grafico, para la tabla alternativa: una fila
 * por categoria, una columna por serie. `undefined` (indice fuera de rango,
 * no debería pasar con series bien formadas) se trata igual que `null`: sin
 * dato es sin dato, nunca `0`.
 */
const tableRows = computed(() =>
  props.categories.map((category, categoryIndex) => ({
    category,
    values: props.series.map((entry) => entry.values[categoryIndex] ?? null),
  })),
)
</script>

<template>
  <section class="flex flex-col gap-3" data-test="chart-with-table">
    <div class="flex flex-wrap items-center justify-between gap-2">
      <h3 class="font-heading text-base font-bold text-kq-text">{{ title }}</h3>
      <button
        type="button"
        class="rounded-kq-sm border border-kq-border-strong px-3 py-1.5 text-sm text-kq-text hover:bg-kq-surface-alt disabled:cursor-not-allowed disabled:opacity-50"
        :aria-pressed="!isChartView()"
        :disabled="chartFailed"
        data-test="toggle-view"
        @click="toggleView"
      >
        {{ isChartView() ? t('chartWithTable.viewAsTable') : t('chartWithTable.viewAsChart') }}
      </button>
    </div>

    <p v-if="chartFailed" class="text-sm text-kq-text-muted" data-test="chart-unavailable">
      {{ t('chartWithTable.unavailable') }}
    </p>

    <div
      v-show="isChartView()"
      ref="container"
      :style="{ height: `${height}px` }"
      role="img"
      :aria-label="title"
      data-test="chart-canvas"
    ></div>

    <table v-if="!isChartView()" class="w-full border-collapse text-left" data-test="chart-table">
      <caption class="sr-only">
        {{
          title
        }}
      </caption>
      <thead>
        <tr class="border-b border-kq-border-strong">
          <th scope="col" class="py-2 pr-3 font-semibold">
            {{ t('chartWithTable.category') }}
          </th>
          <th
            v-for="entry of series"
            :key="entry.name"
            scope="col"
            class="py-2 pr-3 text-right font-semibold"
          >
            {{ entry.name }}
          </th>
        </tr>
      </thead>
      <tbody>
        <tr v-for="row of tableRows" :key="row.category" class="border-b border-kq-border">
          <th scope="row" class="py-2 pr-3 font-normal">{{ row.category }}</th>
          <td
            v-for="(value, index) of row.values"
            :key="index"
            class="py-2 pr-3 text-right tabular-nums"
          >
            {{ value === null ? t('chartWithTable.noData') : formatValue(value) }}
          </td>
        </tr>
      </tbody>
    </table>
  </section>
</template>
