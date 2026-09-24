<script setup lang="ts">
// Cuadro de impacto y adopción (RF-IN-08, RNF-D-01, tarea 3.13): los doce
// indicadores del contrato (`AdoptionIndicatorKey`), con objetivo y variación
// contra el periodo anterior. «El cuadro que responde a "¿esto está
// sirviendo?" con datos» (doc 05 §5.4).
//
// SOLO `admin` Y `rrhh` (RF-IN-08, Anexo B): la ruta exige `REPORTS_MANAGE`
// (`reports:*`), el mismo ámbito que «Informes» y «Nómina» — ni
// `responsable_departamento` ni `auditor` lo llevan (doc 02 §7.3), así que
// ninguno de los dos ve la entrada del menú ni puede abrir la pantalla
// (`router/guards.ts` los manda a su primera sección alcanzable). La
// autorización real está en la policy del servidor (regla dura 18).
//
// CARGA AL ABRIR, SIN PARAMETROS: el propio contrato resuelve «sin `from` ni
// `to` se toma el mes natural anterior completo», en la zona del centro
// (regla dura 3) — NUNCA se adivina en el navegador. Los filtros de fecha se
// rellenan con lo que `meta.period` devuelve, mismo criterio que
// `ComplianceView.vue`.
//
// NADA SE CALCULA AQUÍ (regla dura 7): cada valor, delta y objetivo viene
// resuelto del servidor. Esta vista solo construye tarjetas de presentación:
// si un indicador está dentro o fuera de su objetivo -con texto e icono,
// nunca solo con color (doc 06 §6, regla 5)- y el signo de la variación.
// `previous`/`delta`/`current` a `null` se enseña con un texto, NUNCA como
// `0`: un periodo sin datos no es un valor de cero.
import { announce } from '@kronoqr/web-kit/announcer'
import EmptyState from '@kronoqr/web-kit/components/EmptyState.vue'
import ErrorNotice from '@kronoqr/web-kit/components/ErrorNotice.vue'
import LoadingPanel from '@kronoqr/web-kit/components/LoadingPanel.vue'
import { formatInstant } from '@kronoqr/web-kit/datetime'
import { downloadDocument } from '@kronoqr/web-kit/downloadDocument'
import { durationParts } from '@kronoqr/web-kit/workdayTotals'
import { computed, onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { RouterLink } from 'vue-router'
import type {
  AdoptionExportFormat,
  AdoptionIndicator,
  AdoptionIndicatorKey,
  AdoptionOriginShare,
  AdoptionReport,
} from '@/shared/api/types'
import ChartWithTable from '@/shared/ui/ChartWithTable.vue'
import type { ChartSeries } from '@/shared/ui/ChartWithTable.vue'
import {
  downloadAdoptionReport,
  getAdoptionReport,
  isAdoptionDashboardLicenseRequired,
  isAdoptionReportTooLarge,
  type AdoptionReportQuery,
} from './adoptionReport.api'

const FORMATS: readonly AdoptionExportFormat[] = ['csv', 'xlsx', 'pdf']

/**
 * Los seis indicadores con objetivo del doc 01 §1.3 (los seis que llevan
 * `target.comparison` distinto de `reduction`, más la línea base): una
 * tarjeta por cada uno (decisión 7 de la ficha), en este orden fijo. Los
 * otros seis del contrato (`offline_resolved_ratio`,
 * `incident_resolution_median_minutes`, `open_incidents`,
 * `employees_without_credential`, `worked_minutes`, `contracted_minutes`) se
 * enseñan como dato secundario dentro de la tarjeta que les corresponde, no
 * como tarjetas propias: son fotos o cifras sin objetivo, y RF-IN-08 pide
 * mostrarlas, no que compitan por atención con las seis que sí tienen un
 * compromiso a tres meses.
 */
const PRIMARY_INDICATOR_KEYS: readonly AdoptionIndicatorKey[] = [
  'workdays_complete_ratio',
  'qr_scans_ratio',
  'manual_corrections_ratio',
  'clocking_availability_ratio',
  'incident_resolution_mean_minutes',
  'baseline_manual_minutes_per_month',
]

/** Los cuatro indicadores porcentuales con comparación, para las barras «actual frente a anterior» (decisión 7 de la ficha). */
const COMPARISON_CHART_KEYS: readonly AdoptionIndicatorKey[] = [
  'workdays_complete_ratio',
  'qr_scans_ratio',
  'manual_corrections_ratio',
  'clocking_availability_ratio',
]

function testIdOf(key: AdoptionIndicatorKey): string {
  return key.replaceAll('_', '-')
}

const { t, locale } = useI18n()

// Como se llama cada campo de la consulta EN ESTA PANTALLA, para que un
// `422` (rango invertido, o superior al techo de `DateRange::MAXIMUM_DAYS`)
// diga «Hasta: …» y no «to: …» (mismo criterio que
// `PeriodReportView.vue`/`ComplianceView.vue`).
const fieldLabels = computed<Readonly<Record<string, string>>>(() => ({
  from: t('reports.adoption.filters.from'),
  to: t('reports.adoption.filters.to'),
}))

const from = ref('')
const to = ref('')

const report = ref<AdoptionReport | null>(null)
const loading = ref(true)
const error = ref<unknown>(null)

/** La consulta que produjo el cuadro en pantalla, para la exportación. */
const generatedQuery = ref<AdoptionReportQuery | null>(null)

function currentQuery(): AdoptionReportQuery {
  return {
    ...(from.value === '' ? {} : { from: from.value }),
    ...(to.value === '' ? {} : { to: to.value }),
  }
}

async function load(): Promise<void> {
  const query = currentQuery()

  loading.value = true
  error.value = null

  try {
    const result = await getAdoptionReport(query)

    report.value = result
    generatedQuery.value = { from: result.meta.period.from, to: result.meta.period.to }
    // Los filtros reflejan lo que el servidor ha resuelto de verdad (mismo
    // criterio que `ComplianceView.vue`): sin esto, el primer vistazo
    // enseñaría el mes natural anterior con los dos campos en blanco.
    from.value = result.meta.period.from
    to.value = result.meta.period.to

    announce(t('reports.adoption.announce.results'))
  } catch (caught) {
    // El cuadro anterior se retira: dejarlo en pantalla junto a un error
    // haría creer que esas cifras valen para el periodo recién pedido, y no
    // valen para ninguno (mismo criterio que `PeriodReportView.vue`).
    report.value = null
    generatedQuery.value = null
    error.value = caught
  } finally {
    loading.value = false
  }
}

onMounted(load)

const canSubmit = computed(() => !loading.value)

const generatedAtLabel = computed(() =>
  report.value === null
    ? ''
    : formatInstant(report.value.meta.generated_at, report.value.meta.time_zone, locale.value),
)

/**
 * Un porcentaje con DOS decimales, con el separador del idioma de la
 * interfaz («99,86 %» en es, «99.86 %» en en; segunda vuelta de la ficha,
 * bloqueante): un solo decimal colapsaba el margen de RNF-D-01 -99,86 % de
 * disponibilidad contra un objetivo de ≥ 99,9 % se leía «99.9 %» Y a la vez
 * «fuera del objetivo», una contradicción visible en pantalla-.
 */
function formatNumberWithTwoDecimals(value: number): string {
  return new Intl.NumberFormat(locale.value, {
    minimumFractionDigits: 2,
    maximumFractionDigits: 2,
  }).format(value)
}

function formatPercentage(value: number): string {
  return `${formatNumberWithTwoDecimals(value)} %`
}

/** Minutos enteros a horas y minutos, nunca decimales ambiguos: `8,08 h` no dice nada, `8 h 05 min` sí. Reutiliza `durationParts` de `@kronoqr/web-kit` (ADR-036): es la misma aritmética que ya usa el resto del panel. */
function formatMinutes(totalMinutes: number): string {
  return t('reports.adoption.duration', durationParts(totalMinutes))
}

function formatIndicatorValue(indicator: AdoptionIndicator, value: number): string {
  if (indicator.unit === 'percent') {
    return formatPercentage(value)
  }

  if (indicator.unit === 'minutes') {
    return formatMinutes(value)
  }

  return String(Math.round(value))
}

function indicatorByKey(key: AdoptionIndicatorKey): AdoptionIndicator | undefined {
  return report.value?.data.indicators.find((indicator) => indicator.key === key)
}

/** `null` cuando el indicador no tiene objetivo (foto, o sin comparación por definición). */
function targetLabel(indicator: AdoptionIndicator): string | null {
  const target = indicator.target

  if (target === null) {
    return null
  }

  if (target.comparison === 'reduction') {
    return t('reports.adoption.target.reduction', { value: target.value })
  }

  const formattedTarget = formatIndicatorValue(indicator, target.value)

  return target.comparison === 'at_least'
    ? t('reports.adoption.target.atLeast', { target: formattedTarget })
    : t('reports.adoption.target.atMost', { target: formattedTarget })
}

/** `null` cuando no hay veredicto posible: sin objetivo, sin dato, o el objetivo es una referencia (`reduction`) que el producto no puede verificar. */
function isWithinTarget(indicator: AdoptionIndicator): boolean | null {
  const target = indicator.target

  if (target === null || target.comparison === 'reduction' || indicator.current === null) {
    return null
  }

  return target.comparison === 'at_least'
    ? indicator.current >= target.value
    : indicator.current <= target.value
}

/**
 * La variación, NUNCA en el mismo formato que el valor absoluto cuando la
 * unidad es `percent`: un `delta` de porcentaje es una diferencia en PUNTOS
 * PORCENTUALES, no un segundo porcentaje («ha subido 2,30 pp», no
 * «+2,30 %», que confundiría signo de magnitud con signo de porcentaje;
 * segunda vuelta de la ficha).
 */
function formatDelta(indicator: AdoptionIndicator): string {
  if (indicator.delta === null) {
    return t('reports.adoption.noPreviousComparable')
  }

  const sign = indicator.delta > 0 ? '+' : indicator.delta < 0 ? '−' : '±'
  const magnitude = Math.abs(indicator.delta)

  if (indicator.unit === 'percent') {
    return t('reports.adoption.deltaPercentagePoints', {
      value: `${sign}${formatNumberWithTwoDecimals(magnitude)}`,
    })
  }

  return `${sign}${formatIndicatorValue(indicator, magnitude)}`
}

interface IndicatorCard {
  readonly indicator: AdoptionIndicator
  readonly testId: string
  readonly hasValue: boolean
  readonly valueLabel: string
  readonly targetLabel: string | null
  readonly deltaLabel: string
  readonly within: boolean | null
}

function buildCard(key: AdoptionIndicatorKey): IndicatorCard | null {
  const indicator = indicatorByKey(key)

  if (indicator === undefined) {
    return null
  }

  return {
    indicator,
    testId: testIdOf(key),
    hasValue: indicator.current !== null,
    valueLabel:
      indicator.current === null
        ? t('reports.adoption.noData')
        : formatIndicatorValue(indicator, indicator.current),
    targetLabel: targetLabel(indicator),
    deltaLabel: formatDelta(indicator),
    within: isWithinTarget(indicator),
  }
}

const indicatorCards = computed<readonly IndicatorCard[]>(() =>
  PRIMARY_INDICATOR_KEYS.map(buildCard).filter((card): card is IndicatorCard => card !== null),
)

/**
 * Vacío de verdad: sin jornadas -el ratio de jornadas completas no tiene
 * denominador- Y sin ningún fichaje aceptado -las cuatro cuotas de origen
 * son `null`- (segunda vuelta de la ficha, hallazgo de revisión). Los otros
 * ocho indicadores del contrato que no son ratios (`worked_minutes`,
 * `contracted_minutes`, `open_incidents`, `employees_without_credential`)
 * son recuentos: NUNCA son `null` -cero fichajes es un recuento válido, no
 * una ausencia de dato-, así que mirar si los doce son `null` dejaba el
 * `EmptyState` inalcanzable.
 */
const hasAnyData = computed(() => {
  const current = report.value

  if (current === null) {
    return false
  }

  const workdaysRatio = current.data.indicators.find(
    (indicator) => indicator.key === 'workdays_complete_ratio',
  )
  const noWorkdays = (workdaysRatio?.current ?? null) === null
  const noAcceptedScans = current.data.origin_breakdown.every((row) => row.share === null)

  return !(noWorkdays && noAcceptedScans)
})

function secondaryLabel(key: AdoptionIndicatorKey): string {
  const indicator = indicatorByKey(key)

  if (indicator === undefined || indicator.current === null) {
    return t('reports.adoption.noData')
  }

  return formatIndicatorValue(indicator, indicator.current)
}

// --- Reparto por origen: rosco (periodo actual) y barras de los cuatro
// indicadores porcentuales, actual frente a anterior --------------------

const originRows = computed<readonly AdoptionOriginShare[]>(
  () => report.value?.data.origin_breakdown ?? [],
)

const originCategories = computed<readonly string[]>(() =>
  originRows.value.map((row) => t(`reports.adoption.origin.${row.origin}`)),
)

/**
 * `share` viaja TAL CUAL desde el contrato, incluido `null` cuando el
 * periodo no tuvo ningún fichaje aceptado (segunda vuelta de la ficha,
 * hallazgo de revisión: la versión anterior lo convertía en `0`, y «nadie
 * fichó» y «nadie fichó por tarjeta» son afirmaciones distintas). ECharts
 * dibuja un hueco para el `null` -no una porción de valor cero- y la tabla
 * de datos alternativa lo dice con «sin datos» (`ChartWithTable.vue`), que es
 * lo que de verdad hace el gráfico accesible (doc 02 §3.3).
 */
const originPieSeries = computed<readonly ChartSeries[]>(() => [
  {
    name: t('reports.adoption.originHeading'),
    values: originRows.value.map((row) => row.share),
  },
])

const comparisonCategories = computed<readonly string[]>(() =>
  COMPARISON_CHART_KEYS.map((key) => t(`reports.adoption.indicators.${key}.label`)),
)

/**
 * `null` cuando el indicador no existe (no debería pasar: el contrato
 * siempre trae los doce) O cuando existe pero su `previous` es `null`: las
 * dos situaciones son «no hay dato», nunca «cero» (segunda vuelta de la
 * ficha, hallazgo de revisión: `indicatorByKey(key)?.previous !== null` daba
 * `true` para un indicador inexistente, porque `undefined !== null`).
 */
function previousValueOf(key: AdoptionIndicatorKey): number | null {
  return indicatorByKey(key)?.previous ?? null
}

/** Al menos uno de los cuatro tiene un `previous` real: decide si la serie «Periodo anterior» aparece, no si CADA barra la lleva (ver `previousValueOf`). */
const comparisonHasPrevious = computed(() =>
  COMPARISON_CHART_KEYS.some((key) => previousValueOf(key) !== null),
)

const comparisonSeries = computed<readonly ChartSeries[]>(() => {
  const series: ChartSeries[] = [
    {
      name: t('reports.adoption.currentSeries'),
      values: COMPARISON_CHART_KEYS.map((key) => indicatorByKey(key)?.current ?? null),
    },
  ]

  if (comparisonHasPrevious.value) {
    // Cada indicador SIN `previous` propio se queda en `null` -un hueco en
    // su barra-, aunque otro de los tres sí tenga dato: antes, con que uno
    // solo tuviera `previous`, los otros tres se dibujaban con una barra a
    // cero fabricada (segunda vuelta de la ficha, hallazgo de revisión).
    series.push({
      name: t('reports.adoption.previousSeries'),
      values: COMPARISON_CHART_KEYS.map((key) => previousValueOf(key)),
    })
  }

  return series
})

function formatShareValue(value: number): string {
  return formatPercentage(value)
}

// --- Exportación (CSV/XLSX/PDF), sobre el cuadro YA CALCULADO --------------

const downloading = ref<AdoptionExportFormat | null>(null)
const downloadError = ref<unknown>(null)

async function download(format: AdoptionExportFormat): Promise<void> {
  const query = generatedQuery.value

  if (query === null || downloading.value !== null) {
    return
  }

  downloading.value = format
  downloadError.value = null

  try {
    const downloaded = await downloadAdoptionReport(query, format)

    downloadDocument(downloaded.document)
    announce(t('reports.adoption.export.done', { format: format.toUpperCase() }))
  } catch (caught) {
    downloadError.value = caught
    announce(t('reports.adoption.export.failed'))
  } finally {
    downloading.value = null
  }
}

const fieldClass =
  'rounded-kq-sm border border-kq-border-strong bg-kq-surface-raised px-3 py-2 text-kq-text'
</script>

<template>
  <section>
    <div>
      <h1 class="text-2xl font-bold">{{ t('reports.adoption.title') }}</h1>
      <p class="mt-1 max-w-prose text-kq-text-muted">{{ t('reports.adoption.subtitle') }}</p>
    </div>

    <form class="mt-4 flex flex-wrap items-end gap-4" role="search" @submit.prevent="load">
      <fieldset class="flex flex-wrap items-end gap-4 border-0 p-0">
        <legend class="sr-only">{{ t('reports.adoption.filters.legend') }}</legend>

        <div class="flex flex-col gap-1">
          <label for="adoption-from" class="font-medium">{{
            t('reports.adoption.filters.from')
          }}</label>
          <input id="adoption-from" v-model="from" type="date" :class="fieldClass" />
        </div>

        <div class="flex flex-col gap-1">
          <label for="adoption-to" class="font-medium">{{
            t('reports.adoption.filters.to')
          }}</label>
          <input id="adoption-to" v-model="to" type="date" :class="fieldClass" />
        </div>
      </fieldset>

      <button
        type="submit"
        class="rounded-kq-sm bg-kq-primary-strong px-4 py-2 text-kq-on-primary hover:brightness-95 disabled:opacity-60"
        :disabled="!canSubmit"
        data-test="generate-adoption-report"
      >
        {{ t('reports.adoption.generate') }}
      </button>
    </form>

    <LoadingPanel v-if="loading" :label="t('reports.adoption.loading')" class="mt-4" />

    <template v-else-if="error !== null">
      <p
        v-if="isAdoptionDashboardLicenseRequired(error)"
        class="mt-4 max-w-3xl rounded-kq border border-kq-warning bg-kq-warning-soft p-4 text-kq-warning"
        data-test="license-required-notice"
      >
        {{ t('reports.adoption.licenseRequired') }}
        <RouterLink :to="{ name: 'license' }" class="font-medium underline">
          {{ t('license.notice.action') }}
        </RouterLink>
      </p>
      <p
        v-else-if="isAdoptionReportTooLarge(error)"
        class="mt-4 max-w-3xl rounded-kq border border-kq-border bg-kq-surface-alt p-4 text-kq-text"
        data-test="range-too-large-notice"
      >
        {{ t('reports.adoption.rangeTooLarge') }}
      </p>
      <ErrorNotice v-else :error="error" :field-labels="fieldLabels" class="mt-4" />
    </template>

    <template v-else-if="report !== null">
      <p class="mt-4 font-medium text-kq-text" data-test="current-period">
        {{ report.meta.period.from }} — {{ report.meta.period.to }}
      </p>
      <p class="text-sm text-kq-text-muted" data-test="generated-at">
        {{
          t('reports.adoption.generatedAt', { at: generatedAtLabel, zone: report.meta.time_zone })
        }}
      </p>
      <p class="text-sm text-kq-text-muted" data-test="previous-period">
        {{
          t('reports.adoption.previousPeriod', {
            from: report.meta.previous_period.from,
            to: report.meta.previous_period.to,
          })
        }}
      </p>

      <EmptyState
        v-if="!hasAnyData"
        class="mt-4"
        :title="t('reports.adoption.empty.title')"
        :description="t('reports.adoption.empty.description')"
      />

      <template v-else>
        <!-- Una tarjeta por indicador con objetivo del §1.3 (decision 7 de
             la ficha): valor, objetivo, variacion contra el periodo anterior
             -vacia, nunca 0, cuando no hay anterior- y estado con texto e
             icono, no solo con color (doc 06 §6, regla 5). -->
        <div class="mt-4 grid gap-4 sm:grid-cols-2 lg:grid-cols-3" data-test="indicator-cards">
          <article
            v-for="card of indicatorCards"
            :key="card.indicator.key"
            class="rounded-kq border border-kq-border bg-kq-surface-raised p-4"
            data-test="indicator-card"
            :data-indicator="card.indicator.key"
          >
            <h2 class="font-heading text-lg font-bold">
              {{ t(`reports.adoption.indicators.${card.indicator.key}.label`) }}
            </h2>
            <p class="mt-1 text-sm text-kq-text-muted">
              {{ t(`reports.adoption.indicators.${card.indicator.key}.help`) }}
            </p>

            <p class="mt-3 text-2xl font-bold tabular-nums" :data-test="`${card.testId}-value`">
              {{ card.valueLabel }}
            </p>
            <p v-if="card.targetLabel !== null" class="text-sm text-kq-text-muted">
              {{ card.targetLabel }}
            </p>

            <p
              v-if="card.within !== null"
              class="mt-2 inline-flex items-center gap-1 rounded-full px-3 py-1 text-sm font-semibold"
              :class="
                card.within
                  ? 'bg-kq-success-soft text-kq-success'
                  : 'bg-kq-danger-soft text-kq-danger'
              "
              :data-test="`${card.testId}-status`"
            >
              <span aria-hidden="true">{{ card.within ? '✓' : '✗' }}</span>
              {{
                card.within
                  ? t('reports.adoption.status.within')
                  : t('reports.adoption.status.outside')
              }}
            </p>

            <p
              v-if="card.indicator.key !== 'baseline_manual_minutes_per_month'"
              class="mt-2 text-sm text-kq-text-muted"
              :data-test="`${card.testId}-delta`"
            >
              {{ card.deltaLabel }}
            </p>

            <!-- Subindicador «resueltos sin servidor», junto a la
                 disponibilidad (decision 7 de la ficha). -->
            <p
              v-if="card.indicator.key === 'clocking_availability_ratio'"
              class="mt-2 text-sm text-kq-text-muted"
              data-test="availability-offline"
            >
              {{ t('reports.adoption.availabilityOffline') }}:
              {{ secondaryLabel('offline_resolved_ratio') }}
            </p>

            <!-- Mediana, como dato secundario del tiempo de resolucion. -->
            <p
              v-if="card.indicator.key === 'incident_resolution_mean_minutes'"
              class="mt-2 text-sm text-kq-text-muted"
              data-test="median-resolution"
            >
              {{
                t('reports.adoption.medianResolution', {
                  hours: secondaryLabel('incident_resolution_median_minutes'),
                })
              }}
            </p>

            <!-- La linea base declarada, o «no declarada»: el sistema no
                 puede medir el trabajo anterior a su instalacion, asi que sin
                 dato el indicador queda VACIO (decision 2.h de la ficha). -->
            <p
              v-if="card.indicator.key === 'baseline_manual_minutes_per_month' && card.hasValue"
              class="mt-2 text-sm text-kq-text-muted"
              data-test="baseline-declared"
            >
              {{ t('reports.adoption.baselineDeclared') }}
            </p>
            <p
              v-if="card.indicator.key === 'baseline_manual_minutes_per_month' && !card.hasValue"
              class="mt-2 text-sm text-kq-text-muted"
              data-test="baseline-not-declared"
            >
              {{ t('reports.adoption.baselineNotDeclared') }}
            </p>
          </article>
        </div>

        <!-- Reparto por origen: rosco del periodo actual, y barras de los
             cuatro indicadores porcentuales actual frente a anterior. Los
             dos con tabla de datos alternativa (doc 02 §3.3, primer uso de
             ECharts del proyecto). -->
        <div class="mt-6 grid gap-6 lg:grid-cols-2">
          <div data-test="origin-pie-chart">
            <ChartWithTable
              :title="t('reports.adoption.originHeading')"
              type="pie"
              :categories="originCategories"
              :series="originPieSeries"
              :format-value="formatShareValue"
            />
          </div>
          <div data-test="comparison-bar-chart">
            <ChartWithTable
              :title="t('reports.adoption.originVsPreviousHeading')"
              type="bar"
              :categories="comparisonCategories"
              :series="comparisonSeries"
              :format-value="formatShareValue"
            />
          </div>
        </div>

        <!-- Incidencias, credenciales y horas (decision 7 de la ficha):
             fotos de hoy sin comparacion (RF-IN-08) y horas frente a
             contratadas del periodo. -->
        <div class="mt-6 grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
          <article
            class="rounded-kq border border-kq-border bg-kq-surface-raised p-4"
            data-test="incidents-open-card"
          >
            <h2 class="font-heading text-lg font-bold">
              {{ t('reports.adoption.incidentsHeading') }}
            </h2>
            <p class="mt-3 text-2xl font-bold tabular-nums">
              {{ secondaryLabel('open_incidents') }}
            </p>
            <p class="text-sm text-kq-text-muted">{{ t('reports.adoption.incidentsOpen') }}</p>
            <p class="mt-1 text-sm text-kq-text-muted">{{ t('reports.adoption.openShiftFoto') }}</p>
          </article>

          <article
            class="rounded-kq border border-kq-border bg-kq-surface-raised p-4"
            data-test="credentials-card"
          >
            <h2 class="font-heading text-lg font-bold">
              {{ t('reports.adoption.credentialsHeading') }}
            </h2>
            <p class="mt-3 text-2xl font-bold tabular-nums">
              {{ secondaryLabel('employees_without_credential') }}
            </p>
            <p class="text-sm text-kq-text-muted">
              {{ t('reports.adoption.employeesWithoutCredential') }}
            </p>
            <p class="mt-1 text-sm text-kq-text-muted">{{ t('reports.adoption.openShiftFoto') }}</p>
          </article>

          <article
            class="rounded-kq border border-kq-border bg-kq-surface-raised p-4"
            data-test="worked-hours-card"
          >
            <h2 class="font-heading text-lg font-bold">
              {{ t('reports.adoption.hoursHeading') }}
            </h2>
            <p class="mt-3 tabular-nums">
              {{ t('reports.adoption.workedHours') }}:
              <span class="text-lg font-bold">{{ secondaryLabel('worked_minutes') }}</span>
            </p>
            <p class="tabular-nums">
              {{ t('reports.adoption.contractedHours') }}:
              <span class="text-lg font-bold">{{ secondaryLabel('contracted_minutes') }}</span>
            </p>
          </article>
        </div>

        <!-- Exportacion, sobre el cuadro YA CALCULADO (mismo criterio que
             `PeriodReportView.vue`). -->
        <section class="mt-6 flex flex-wrap items-center gap-3" data-test="adoption-export">
          <span class="font-medium">{{ t('reports.adoption.export.label') }}</span>
          <button
            v-for="format of FORMATS"
            :key="format"
            type="button"
            class="rounded-kq-sm border border-kq-border-strong px-3 py-1.5 text-kq-text hover:bg-kq-surface-alt disabled:opacity-50"
            :disabled="downloading !== null"
            :data-test="`export-${format}`"
            @click="download(format)"
          >
            {{ t(`reports.period.export.format.${format}`) }}
          </button>
          <p v-if="downloading !== null" class="text-kq-text-muted" data-test="export-running">
            {{ t('reports.adoption.export.running', { format: downloading.toUpperCase() }) }}
          </p>
        </section>

        <ErrorNotice v-if="downloadError !== null" :error="downloadError" class="mt-3" />

        <!-- Los criterios, tal cual los da el servidor (mismo patron que
             `PeriodReportView.vue`/`ComplianceView.vue`). -->
        <section class="mt-6 rounded-kq border border-kq-border bg-kq-surface-alt p-4">
          <h2 class="font-heading text-lg font-bold">{{ t('reports.adoption.criteria.title') }}</h2>
          <p class="mt-1 text-sm text-kq-text-muted">
            {{
              t('reports.adoption.criteria.generated', {
                timeZone: report.meta.time_zone,
                at: generatedAtLabel,
              })
            }}
          </p>
          <ul class="mt-2 list-disc space-y-1 pl-5" data-test="adoption-criteria">
            <li v-for="(criterion, index) of report.meta.criteria" :key="index">{{ criterion }}</li>
          </ul>
        </section>
      </template>
    </template>
  </section>
</template>
