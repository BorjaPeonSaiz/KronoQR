<script setup lang="ts">
// Vista de cumplimiento (RF-PA-06, tarea 3.4): descanso insuficiente entre
// jornadas (RN-10), jornada diaria excesiva (RN-11), tramo continuado sin
// pausa (RN-12, suspendida) y exceso semanal informativo (RN-17), para el
// alcance de quien pregunta.
//
// CARGA AL ABRIR, SIN PARAMETROS (decision 11 de la ficha de la tarea 3.4): al
// contrario que el informe por periodo (`reports/PeriodReportView.vue`), que
// exige elegir fechas antes de gastar una consulta cara, aqui los hallazgos
// son escasos -solo incumplimientos- y la ventana ya esta acotada por el
// servidor (28 dias hasta hoy, por omision): abrir la pantalla sin nada que
// enseñar seria peor que la peticion de partida. El servidor devuelve
// `meta.from`/`meta.to` ya resueltos, y son los que rellenan los filtros.
//
// NADA SE CALCULA AQUI (regla dura 7): los minutos medidos, el umbral y la
// diferencia de cada hallazgo vienen del servidor. EL UMBRAL SE ENSEÑA CON EL
// NOMBRE DEL PERFIL QUE LO FIJA (regla dura 14): un aviso cuyo criterio no se
// ve es un aviso que nadie defiende ante un empleado.
import { announce } from '@kronoqr/web-kit/announcer'
import EmptyState from '@kronoqr/web-kit/components/EmptyState.vue'
import ErrorNotice from '@kronoqr/web-kit/components/ErrorNotice.vue'
import LoadingPanel from '@kronoqr/web-kit/components/LoadingPanel.vue'
import { formatInstant } from '@kronoqr/web-kit/datetime'
import { durationParts } from '@kronoqr/web-kit/workdayTotals'
import { useQuery } from '@tanstack/vue-query'
import { computed, onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { listDepartments } from '@/shared/api/organisation.api'
import type {
  ComplianceRuleName,
  ComplianceRuleStatus,
  ComplianceSummary,
} from '@/shared/api/types'
import ComplianceFindingsTable from './ComplianceFindingsTable.vue'
import { getComplianceSummary, type ComplianceSummaryQuery } from './compliance.api'
import { groupFindingsByRule, ruleLabelKey, suspensionReasonKey } from './compliancePresentation'

const RULES: readonly ComplianceRuleName[] = [
  'insufficient_rest',
  'daily_excess',
  'missing_break',
  'weekly_excess',
]

const { t, locale } = useI18n()

// Como se llama cada campo de la consulta EN ESTA PANTALLA, para que un `422`
// (rango superior a `REPORTING_COMPLIANCE_MAX_RANGE_DAYS`) diga «Hasta: …» y
// no «to: …».
const fieldLabels = computed<Readonly<Record<string, string>>>(() => ({
  from: t('complianceSummary.filters.from'),
  to: t('complianceSummary.filters.to'),
  department_id: t('complianceSummary.filters.department'),
  rule: t('complianceSummary.filters.rule'),
}))

const { data: departments } = useQuery({
  queryKey: ['departments'] as const,
  queryFn: listDepartments,
})
const departmentOptions = computed(() => departments.value?.data ?? [])

const from = ref('')
const to = ref('')
const departmentFilter = ref<number | ''>('')
const ruleFilter = ref<ComplianceRuleName | ''>('')

const summary = ref<ComplianceSummary | null>(null)
const loading = ref(true)
const error = ref<unknown>(null)

function currentQuery(): ComplianceSummaryQuery {
  return {
    // `undefined` no se serializa (`compliance.api.ts`): sin fecha, el
    // servidor aplica sus 28 dias por omision, no un rango vacio.
    ...(from.value === '' ? {} : { from: from.value }),
    ...(to.value === '' ? {} : { to: to.value }),
    ...(departmentFilter.value === '' ? {} : { departmentId: departmentFilter.value }),
    ...(ruleFilter.value === '' ? {} : { rule: ruleFilter.value }),
  }
}

async function load(): Promise<void> {
  loading.value = true
  error.value = null

  try {
    const result = await getComplianceSummary(currentQuery())

    summary.value = result
    // Los filtros de fecha reflejan lo que el servidor ha resuelto de verdad:
    // sin esto, el primer vistazo enseñaria 28 dias de hallazgos con los dos
    // campos en blanco, y nadie sabria que rango esta mirando.
    from.value = result.meta.from
    to.value = result.meta.to

    announce(t('complianceSummary.announce.results', { count: result.data.length }))
  } catch (caught) {
    // El resultado anterior se retira: dejarlo en pantalla junto a un error
    // haria creer que las alertas siguen valiendo para el periodo que se
    // acaba de pedir, y no valen para ninguno.
    summary.value = null
    error.value = caught
  } finally {
    loading.value = false
  }
}

onMounted(load)

/**
 * El instante de generacion, resuelto en la zona del centro (regla dura 3):
 * NUNCA se enseña el ISO/UTC crudo, que es la hora de nadie (`datetime.ts`,
 * mismo patron que `LivePresenceView.vue`).
 */
const generatedAtLabel = computed(() =>
  summary.value === null
    ? ''
    : formatInstant(summary.value.meta.generated_at, summary.value.meta.time_zone, locale.value),
)

/** Las cuatro reglas, en el orden de `meta.rules[]` -el mismo de las tarjetas y de la tabla-. */
const rules = computed<readonly ComplianceRuleStatus[]>(() => summary.value?.meta.rules ?? [])

const groups = computed(() =>
  summary.value === null ? [] : groupFindingsByRule(summary.value.data, rules.value),
)

interface RuleCard {
  rule: ComplianceRuleStatus
  label: string
  /** El umbral en palabras y con el nombre del perfil (regla dura 14): «12 h según el perfil ES-hosteleria». */
  thresholdSentence: string
  /** La clave i18n del motivo de suspension, ya resuelta a texto; `null` si la regla si se evalua. */
  suspensionReason: string | null
  count: number
}

/** Las cuatro tarjetas, calculadas una sola vez por regla: la plantilla no vuelve a llamar a nada. */
const ruleCards = computed<RuleCard[]>(() => {
  const profile = summary.value?.meta.profile.name ?? ''
  const byRule = summary.value?.meta.totals.by_rule

  return rules.value.map((rule) => {
    const duration = t('complianceSummary.duration', durationParts(rule.threshold_minutes))
    const reasonKey = suspensionReasonKey(rule)

    return {
      rule,
      label: t(ruleLabelKey(rule.rule)),
      thresholdSentence: t('complianceSummary.threshold', { duration, profile }),
      suspensionReason: reasonKey === null ? null : t(reasonKey),
      count: byRule?.[rule.rule] ?? 0,
    }
  })
})

const selectClass =
  'rounded-kq-sm border border-kq-border-strong bg-kq-surface-raised px-3 py-2 text-kq-text'
</script>

<template>
  <section>
    <div>
      <h1 class="text-2xl font-bold">{{ t('complianceSummary.title') }}</h1>
      <p class="mt-1 max-w-prose text-kq-text-muted">{{ t('complianceSummary.subtitle') }}</p>
    </div>

    <form class="mt-4 flex flex-wrap items-end gap-4" role="search" @submit.prevent="load">
      <fieldset class="flex flex-wrap items-end gap-4 border-0 p-0">
        <legend class="sr-only">{{ t('complianceSummary.filters.legend') }}</legend>

        <div class="flex flex-col gap-1">
          <label for="compliance-from" class="font-medium">
            {{ t('complianceSummary.filters.from') }}
          </label>
          <input id="compliance-from" v-model="from" type="date" :class="selectClass" />
        </div>

        <div class="flex flex-col gap-1">
          <label for="compliance-to" class="font-medium">
            {{ t('complianceSummary.filters.to') }}
          </label>
          <input id="compliance-to" v-model="to" type="date" :class="selectClass" />
        </div>

        <div class="flex flex-col gap-1">
          <label for="compliance-department" class="font-medium">
            {{ t('complianceSummary.filters.department') }}
          </label>
          <select id="compliance-department" v-model="departmentFilter" :class="selectClass">
            <option value="">{{ t('complianceSummary.filters.departmentAll') }}</option>
            <option
              v-for="department of departmentOptions"
              :key="department.id"
              :value="department.id"
            >
              {{ department.name }}
            </option>
          </select>
        </div>

        <div class="flex flex-col gap-1">
          <label for="compliance-rule" class="font-medium">
            {{ t('complianceSummary.filters.rule') }}
          </label>
          <select id="compliance-rule" v-model="ruleFilter" :class="selectClass">
            <option value="">{{ t('complianceSummary.filters.ruleAll') }}</option>
            <option v-for="rule of RULES" :key="rule" :value="rule">
              {{ t(ruleLabelKey(rule)) }}
            </option>
          </select>
        </div>
      </fieldset>

      <button
        type="submit"
        class="rounded-kq-sm bg-kq-primary-strong px-4 py-2 text-kq-on-primary hover:brightness-95 disabled:opacity-60"
        :disabled="loading"
        data-test="apply-filters"
      >
        {{ t('complianceSummary.filters.apply') }}
      </button>
    </form>

    <LoadingPanel v-if="loading" :label="t('complianceSummary.loading')" class="mt-4" />

    <ErrorNotice
      v-else-if="error !== null"
      :error="error"
      :field-labels="fieldLabels"
      class="mt-4"
    />

    <template v-else-if="summary !== null">
      <!-- La zona horaria del centro, A LA VISTA junto a la cabecera y los
           filtros (correccion de UI/UX, segunda vuelta de la tarea 3.4): antes
           solo vivia en un `<caption>` invisible y al pie de la pantalla. Mismo
           patron que `LivePresenceView.vue` con su «Foto de las …». -->
      <p class="mt-4 text-sm text-kq-text-muted" data-test="generated-at">
        {{
          t('complianceSummary.generatedAt', {
            moment: generatedAtLabel,
            zone: summary.meta.time_zone,
          })
        }}
      </p>

      <!-- Cuatro tarjetas, una por regla, en el orden de `meta.rules[]`: el
           umbral se enseña en palabras y con el nombre del perfil (regla dura
           14), y la suspendida lo dice sin callarlo. -->
      <div class="mt-4 grid gap-4 sm:grid-cols-2 lg:grid-cols-4" data-test="rule-cards">
        <article
          v-for="card of ruleCards"
          :key="card.rule.rule"
          class="rounded-kq border border-kq-border bg-kq-surface-raised p-4"
          data-test="rule-card"
        >
          <h2 class="font-heading text-lg font-bold">{{ card.label }}</h2>
          <p class="mt-1 text-kq-text-muted">{{ card.thresholdSentence }}</p>

          <p
            v-if="!card.rule.evaluated"
            class="mt-2 inline-flex items-center gap-1 rounded-full bg-kq-warning-soft px-3 py-1 text-sm font-semibold text-kq-warning"
            data-test="rule-suspended"
          >
            {{ t('complianceSummary.notEvaluated') }}
          </p>
          <p
            v-if="card.suspensionReason !== null"
            class="mt-1 text-sm text-kq-text-muted"
            data-test="rule-suspended-reason"
          >
            {{ card.suspensionReason }}
          </p>

          <p class="mt-3 text-2xl font-bold tabular-nums" data-test="rule-count">
            {{ card.count }}
          </p>
        </article>
      </div>

      <EmptyState
        v-if="summary.data.length === 0"
        class="mt-6"
        :title="t('complianceSummary.empty.title')"
        :description="t('complianceSummary.empty.description')"
      />

      <template v-else>
        <h2 class="sr-only">{{ t('complianceSummary.findingsHeading') }}</h2>
        <ComplianceFindingsTable :groups="groups" :time-zone="summary.meta.time_zone" />
      </template>

      <!-- Los criterios, tal cual los da el servidor: no se reordenan ni se
           resumen (mismo patron que `reports/PeriodReportView.vue`). -->
      <section class="mt-6 rounded-kq border border-kq-border bg-kq-surface-alt p-4">
        <h2 class="font-heading text-lg font-bold">{{ t('complianceSummary.criteria.title') }}</h2>
        <p class="mt-1 text-sm text-kq-text-muted">
          {{
            t('complianceSummary.criteria.generated', {
              timeZone: summary.meta.time_zone,
              at: generatedAtLabel,
            })
          }}
        </p>
        <p class="mt-1 text-sm text-kq-text-muted" data-test="employees-evaluated">
          {{
            t('complianceSummary.criteria.employeesEvaluated', {
              affected: summary.meta.totals.employees_affected,
              evaluated: summary.meta.totals.employees_evaluated,
            })
          }}
        </p>
        <ul class="mt-2 list-disc space-y-1 pl-5" data-test="compliance-criteria">
          <li v-for="(criterion, index) of summary.meta.criteria" :key="index">{{ criterion }}</li>
        </ul>
      </section>
    </template>
  </section>
</template>
