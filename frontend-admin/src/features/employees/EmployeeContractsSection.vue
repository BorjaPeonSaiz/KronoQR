<script setup lang="ts">
// Contratos de una persona dentro de su ficha (RF-GP-02, RF-IN-03).
//
// La serie historica entera, del mas antiguo al mas reciente, y el alta de uno
// nuevo. No hay editar ni borrar: el contrato de la API no los ofrece y un
// contrato firmado no se reescribe, se sustituye por otro que cierra el
// anterior (regla dura 5). El orden lo da el servidor; aqui no se reordena.
//
// El alta solo se ofrece a quien tiene `employees:*` y, ademas, a una persona
// que no esta de baja: es cortesia de interfaz, la policy es la del servidor.
import { announce } from '@kronoqr/web-kit/announcer'
import EmptyState from '@kronoqr/web-kit/components/EmptyState.vue'
import ErrorNotice from '@kronoqr/web-kit/components/ErrorNotice.vue'
import LoadingPanel from '@kronoqr/web-kit/components/LoadingPanel.vue'
import { formatCivilDate, todayInZone } from '@kronoqr/web-kit/datetime'
import { useQuery, useQueryClient } from '@tanstack/vue-query'
import { computed, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { EMPLOYEES_MANAGE } from '@/features/auth/abilities'
import { useSessionStore } from '@/features/auth/session.store'
import { getSite } from '@/shared/api/organisation.api'
import type { EmploymentContract } from '@/shared/api/types'
import ContractRegisterDialog from './ContractRegisterDialog.vue'
import { listEmploymentContracts } from './contracts.api'

const props = defineProps<{
  employeeUuid: string
  /** Una persona de baja conserva su historico, pero no admite contratos nuevos. */
  canRegister: boolean
}>()

const { t, locale } = useI18n()
const queryClient = useQueryClient()
const session = useSessionStore()

const mayManage = computed(() => session.can(EMPLOYEES_MANAGE) && props.canRegister)

const contractsKey = computed(() => ['employee-contracts', props.employeeUuid] as const)

const { data, error, isPending, refetch } = useQuery({
  queryKey: contractsKey,
  queryFn: () => listEmploymentContracts(props.employeeUuid),
})

// La zona del centro decide cual es «hoy» (regla dura 3), no la del navegador.
const { data: site } = useQuery({ queryKey: ['site'], queryFn: getSite })

const contracts = computed(() => data.value?.data ?? [])
const current = computed<EmploymentContract | null>(
  () => contracts.value.find((contract) => contract.is_current) ?? null,
)

const registering = ref(false)
const today = computed(() => todayInZone(site.value?.timezone ?? 'UTC'))

function formatHours(value: number): string {
  return t('contracts.hoursValue', {
    hours: new Intl.NumberFormat(locale.value, { maximumFractionDigits: 2 }).format(value),
  })
}

async function reread(): Promise<void> {
  await queryClient.invalidateQueries({ queryKey: contractsKey.value })
}

async function onCreated(): Promise<void> {
  registering.value = false
  await reread()
  announce(t('contracts.announce.registered'))
}

async function onConflict(): Promise<void> {
  // El estado cambio por debajo: se relee la serie y el dialogo, que sigue
  // abierto, recalcula su vista previa con el contrato vigente de ahora.
  await reread()
  announce(t('contracts.announce.reread'))
}
</script>

<template>
  <section
    id="contracts"
    class="mt-6 rounded-kq border border-kq-border bg-kq-surface-raised p-4 shadow-kq-soft"
    aria-labelledby="contracts-heading"
    data-test="contracts-section"
  >
    <div class="flex flex-wrap items-center justify-between gap-3">
      <h2 id="contracts-heading" class="text-xl font-semibold">{{ t('contracts.heading') }}</h2>
      <button
        v-if="mayManage"
        type="button"
        class="rounded-kq-sm bg-kq-primary-strong px-4 py-2 font-semibold text-kq-on-primary"
        data-test="contract-register-open"
        @click="registering = true"
      >
        {{ t('contracts.actions.register') }}
      </button>
    </div>
    <p class="mt-1 max-w-prose text-kq-text-muted">{{ t('contracts.explanation') }}</p>

    <LoadingPanel v-if="isPending" :label="t('contracts.loading')" class="mt-4" />

    <div v-else-if="error !== null" class="mt-4 flex flex-col gap-3">
      <ErrorNotice :error="error" />
      <button
        type="button"
        class="self-start rounded-kq-sm border border-kq-border-strong bg-kq-surface-raised px-4 py-2 text-kq-text hover:bg-kq-surface-alt"
        data-test="contracts-retry"
        @click="() => refetch()"
      >
        {{ t('contracts.retry') }}
      </button>
    </div>

    <EmptyState
      v-else-if="contracts.length === 0"
      class="mt-4"
      :title="t('contracts.empty.title')"
      :description="t('contracts.empty.description')"
    />

    <div
      v-else
      tabindex="0"
      role="region"
      :aria-label="t('contracts.table.caption')"
      class="mt-4 overflow-x-auto"
    >
      <table class="w-full border-collapse text-left" data-test="contracts-table">
        <caption class="sr-only">
          {{
            t('contracts.table.caption')
          }}
        </caption>
        <thead>
          <tr class="border-b border-kq-border">
            <th scope="col" class="py-2 pr-4 font-semibold">
              {{ t('contracts.fields.validFrom') }}
            </th>
            <th scope="col" class="py-2 pr-4 font-semibold">{{ t('contracts.fields.validTo') }}</th>
            <th scope="col" class="py-2 pr-4 font-semibold">
              {{ t('contracts.fields.weeklyHours') }}
            </th>
            <th scope="col" class="py-2 pr-4 font-semibold">
              {{ t('contracts.fields.annualHours') }}
            </th>
            <th scope="col" class="py-2 font-semibold">{{ t('contracts.fields.scheduleType') }}</th>
          </tr>
        </thead>
        <tbody>
          <tr
            v-for="contract of contracts"
            :key="contract.id"
            class="border-b border-kq-border"
            data-test="contract-row"
            :data-current="contract.is_current"
          >
            <th scope="row" class="py-2 pr-4 font-medium">
              {{ formatCivilDate(contract.valid_from, locale) }}
            </th>
            <td class="py-2 pr-4">
              <span v-if="contract.valid_to !== null">
                {{ formatCivilDate(contract.valid_to, locale) }}
              </span>
              <span
                v-else
                class="rounded-full bg-kq-success-soft px-2 py-0.5 text-sm text-kq-success"
              >
                {{ t('contracts.current') }}
              </span>
            </td>
            <td class="py-2 pr-4 tabular-nums">{{ formatHours(contract.weekly_hours) }}</td>
            <td class="py-2 pr-4 tabular-nums">
              {{ contract.annual_hours === null ? '—' : formatHours(contract.annual_hours) }}
            </td>
            <td class="py-2">{{ t(`contracts.schedule.${contract.schedule_type}`) }}</td>
          </tr>
        </tbody>
      </table>
    </div>

    <ContractRegisterDialog
      v-if="registering"
      :employee-uuid="employeeUuid"
      :current="current"
      :today="today"
      @close="registering = false"
      @created="onCreated"
      @conflict="onConflict"
    />
  </section>
</template>
