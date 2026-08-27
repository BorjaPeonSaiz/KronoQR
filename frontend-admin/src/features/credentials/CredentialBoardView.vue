<script setup lang="ts">
// Panel de estado de credenciales (RF-QR-04, RF-QR-06, RF-QR-08).
//
// Para que existe, literalmente: para que RRHH vea de un vistazo QUIEN NO PUEDE
// FICHAR TODAVIA. Sin esta pantalla el problema se descubre delante del quiosco
// a las 06:00.
//
// La fila es del empleado y no de la credencial: quien no tiene ninguna emitida
// es precisamente el caso que hay que ver, y un listado de credenciales lo
// dejaria fuera.
//
// Los cinco estados son derivados (`printed_at`, `delivered_at`, `revoked_at`).
// Aqui no se recalculan: los da el servidor y se pintan tal cual.
//
// Lo que esta pantalla NO tiene, a proposito: un boton de reimprimir. No existe
// (ADR-034). Reponer una tarjeta perdida son dos actos separados —revocar y
// volver a emitir— y cada uno deja su asiento en `audit_log`.
import { announce } from '@kronoqr/web-kit/announcer'
import EmptyState from '@kronoqr/web-kit/components/EmptyState.vue'
import ErrorNotice from '@kronoqr/web-kit/components/ErrorNotice.vue'
import LoadingPanel from '@kronoqr/web-kit/components/LoadingPanel.vue'
import { FALLBACK_TIMEZONE, formatInstantWithZone } from '@kronoqr/web-kit/datetime'
import { downloadDocument } from '@kronoqr/web-kit/downloadDocument'
import { computed, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { useQuery, useQueryClient } from '@tanstack/vue-query'
import { useRoute } from 'vue-router'
import { listSites } from '@/shared/api/organisation.api'
import type { CredentialStatusRow } from '@/shared/api/types'
import type { Change } from '@/shared/ui/change'
import ChangePreview from '@/shared/ui/ChangePreview.vue'
import ConfirmDialog from '@/shared/ui/ConfirmDialog.vue'
import {
  deliverCredential,
  fetchCredentialBoard,
  issueCredential,
  printCredential,
  printCredentialBatch,
  revokeCredential,
} from './credentials.api'

/** Motivos de revocacion. Un catalogo evita el «se perdio» sin mas contexto. */
const REVOCATION_REASONS = ['lost', 'stolen', 'damaged', 'offboarding', 'printFailed'] as const

type RowActionKind = 'issue' | 'print' | 'deliver' | 'revoke'

const { t, locale } = useI18n()
const route = useRoute()
const queryClient = useQueryClient()

const initialSite = Number.parseInt(String(route.query['site'] ?? ''), 10)
const siteFilter = ref<number | ''>(Number.isFinite(initialSite) ? initialSite : '')
const pendingOnly = ref(false)

const { data: sites } = useQuery({ queryKey: ['sites'], queryFn: listSites })

const boardQuery = computed(() => ({
  ...(siteFilter.value === '' ? {} : { siteId: siteFilter.value }),
  ...(pendingOnly.value ? { pendingOnly: true } : {}),
}))

const {
  data: board,
  error,
  isPending,
} = useQuery({
  queryKey: computed(() => ['credential-board', boardQuery.value] as const),
  queryFn: () => fetchCredentialBoard(boardQuery.value),
})

const rows = computed(() => board.value?.data ?? [])
const summary = computed(() => board.value?.summary ?? [])

const timezones = computed(
  () => new Map((sites.value?.data ?? []).map((site) => [site.id, site.timezone])),
)

function zoneOf(siteId: number): string {
  return timezones.value.get(siteId) ?? FALLBACK_TIMEZONE
}

function instant(value: string | null, siteId: number): string {
  return value === null
    ? t('common.empty')
    : formatInstantWithZone(value, zoneOf(siteId), locale.value)
}

/** Cuantas tarjetas entraran en el proximo lote del alcance elegido. */
const pendingPrintInScope = computed(() =>
  summary.value.reduce((total, site) => total + site.pending_print, 0),
)

const scopeName = computed(() => {
  if (siteFilter.value === '') {
    return t('credentials.batch.allSites')
  }

  return (sites.value?.data ?? []).find((site) => site.id === siteFilter.value)?.name ?? ''
})

// --- Acciones ----------------------------------------------------------------

const action = ref<{ kind: RowActionKind; row: CredentialStatusRow } | null>(null)
const batching = ref(false)
const busy = ref(false)
const actionError = ref<unknown>(null)
const revocationReasonKey = ref('')
const revocationReasonText = ref('')

const revocationReason = computed(() =>
  revocationReasonKey.value === 'other'
    ? revocationReasonText.value.trim()
    : revocationReasonKey.value === ''
      ? ''
      : t(`credentials.revoke.reasons.${revocationReasonKey.value}`),
)

function openAction(kind: RowActionKind, row: CredentialStatusRow): void {
  actionError.value = null
  revocationReasonKey.value = ''
  revocationReasonText.value = ''
  action.value = { kind, row }
}

function closeAction(): void {
  action.value = null
  actionError.value = null
}

function changesFor(kind: RowActionKind, row: CredentialStatusRow): Change[] {
  const to: Record<RowActionKind, string> = {
    issue: t('credentials.status.pending_print'),
    print: t('credentials.status.pending_delivery'),
    deliver: t('credentials.status.delivered'),
    revoke: t('credentials.status.revoked'),
  }

  return [
    {
      label: t('credentials.table.status'),
      from: t(`credentials.status.${row.status}`),
      to: to[kind],
    },
  ]
}

async function refreshBoard(): Promise<void> {
  await queryClient.invalidateQueries({ queryKey: ['credential-board'] })
}

async function runAction(): Promise<void> {
  const current = action.value

  if (current === null) {
    return
  }

  busy.value = true
  actionError.value = null

  try {
    await perform(current.kind, current.row)
    await refreshBoard()
    closeAction()
  } catch (caught) {
    actionError.value = caught
  } finally {
    busy.value = false
  }
}

async function perform(kind: RowActionKind, row: CredentialStatusRow): Promise<void> {
  if (kind === 'issue') {
    // `reissue: false` siempre: aqui solo se emite a quien NO tiene credencial
    // activa —sin ninguna, o con la ultima revocada—. Una reemision revoca la
    // anterior y exige motivo, y ese camino pasa por «revocar» primero.
    await issueCredential({ employee_uuid: row.employee_uuid, reissue: false })
    announce(t('credentials.announce.issued', { name: row.full_name }))

    return
  }

  const credentialUuid = row.credential?.uuid

  if (credentialUuid === undefined) {
    return
  }

  if (kind === 'print') {
    const document_ = await printCredential(credentialUuid)

    if (document_ !== null) {
      downloadDocument(document_)
    }

    announce(t('credentials.announce.printed', { name: row.full_name }))

    return
  }

  if (kind === 'deliver') {
    await deliverCredential(credentialUuid)
    announce(t('credentials.announce.delivered', { name: row.full_name }))

    return
  }

  await revokeCredential(credentialUuid, { reason: revocationReason.value })
  announce(t('credentials.announce.revoked', { name: row.full_name }))
}

async function runBatch(): Promise<void> {
  busy.value = true
  actionError.value = null

  try {
    const document_ = await printCredentialBatch(
      siteFilter.value === '' ? {} : { site_id: siteFilter.value },
    )

    if (document_ === null) {
      // 204: no habia nada pendiente. Es la idempotencia del lote, no un fallo.
      announce(t('credentials.announce.batchEmpty'))
    } else {
      downloadDocument(document_)
      announce(
        t('credentials.announce.batchPrinted', {
          count: document_.printedCount ?? pendingPrintInScope.value,
        }),
      )
    }

    await refreshBoard()
    batching.value = false
  } catch (caught) {
    actionError.value = caught
  } finally {
    busy.value = false
  }
}

const selectClass =
  'rounded border border-slate-400 px-3 py-2 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-slate-900'
const rowButtonClass =
  'rounded border border-slate-400 px-2 py-1 text-sm focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-slate-900'
</script>

<template>
  <section>
    <div class="flex flex-wrap items-center justify-between gap-4">
      <div>
        <h1 class="text-2xl font-bold">{{ t('credentials.title') }}</h1>
        <p class="mt-1 max-w-prose text-slate-700">{{ t('credentials.subtitle') }}</p>
      </div>
      <button
        type="button"
        class="rounded bg-slate-900 px-4 py-2 font-semibold text-white focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-slate-900"
        @click="
          () => {
            actionError = null
            batching = true
          }
        "
      >
        {{ t('credentials.batch.action') }}
      </button>
    </div>

    <form class="mt-4 flex flex-wrap items-end gap-4" @submit.prevent>
      <fieldset class="flex flex-wrap items-end gap-4 border-0 p-0">
        <legend class="sr-only">{{ t('credentials.filters.legend') }}</legend>

        <div class="flex flex-col gap-1">
          <label for="credentials-site-filter" class="font-medium">
            {{ t('credentials.filters.site') }}
          </label>
          <select id="credentials-site-filter" v-model="siteFilter" :class="selectClass">
            <option value="">{{ t('credentials.filters.siteAll') }}</option>
            <option v-for="site of sites?.data ?? []" :key="site.id" :value="site.id">
              {{ site.name }}
            </option>
          </select>
        </div>

        <label class="flex items-center gap-2">
          <input v-model="pendingOnly" type="checkbox" />
          <span>{{ t('credentials.filters.pendingOnly') }}</span>
        </label>
      </fieldset>
    </form>

    <!-- Recuento por centro. `summary` no se filtra: dice «faltan 3 de 60», no
         «faltan 3 de 3» (contrato, SiteCredentialCoverage). -->
    <ul v-if="summary.length > 0" class="mt-4 grid gap-3 sm:grid-cols-2">
      <li
        v-for="coverage of summary"
        :key="coverage.site_id"
        class="rounded border border-slate-300 bg-white p-4"
      >
        <p class="font-semibold">{{ coverage.site_name }}</p>
        <p class="mt-1">
          {{
            t('credentials.summary.withoutDelivered', {
              missing: coverage.without_delivered_credential,
              total: coverage.employees,
            })
          }}
        </p>
        <p class="text-slate-700">
          {{ t('credentials.summary.pendingPrint', { count: coverage.pending_print }) }}
        </p>
      </li>
    </ul>

    <LoadingPanel v-if="isPending" :label="t('credentials.loading')" class="mt-4" />
    <ErrorNotice v-else-if="error !== null" :error="error" class="mt-4" />
    <EmptyState
      v-else-if="rows.length === 0"
      class="mt-4"
      :title="t('credentials.empty.title')"
      :description="
        pendingOnly ? t('credentials.empty.allDelivered') : t('credentials.empty.description')
      "
    />

    <div v-else class="mt-4 overflow-x-auto rounded border border-slate-300 bg-white">
      <table class="w-full border-collapse text-left">
        <caption class="px-3 py-2 text-left text-sm text-slate-600">
          {{
            t('credentials.table.caption')
          }}
        </caption>
        <thead class="border-b border-slate-300 bg-slate-50">
          <tr>
            <th scope="col" class="px-3 py-2">{{ t('credentials.table.employee') }}</th>
            <th scope="col" class="px-3 py-2">{{ t('credentials.table.site') }}</th>
            <th scope="col" class="px-3 py-2">{{ t('credentials.table.department') }}</th>
            <th scope="col" class="px-3 py-2">{{ t('credentials.table.status') }}</th>
            <th scope="col" class="px-3 py-2">{{ t('credentials.table.printedAt') }}</th>
            <th scope="col" class="px-3 py-2">{{ t('credentials.table.deliveredAt') }}</th>
            <th scope="col" class="px-3 py-2">{{ t('credentials.table.actions') }}</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="row of rows" :key="row.employee_uuid" class="border-b border-slate-200">
            <th scope="row" class="px-3 py-2 font-medium">
              {{ row.full_name }}
              <span class="block font-mono text-sm font-normal text-slate-600">
                {{ row.employee_code }}
              </span>
            </th>
            <td class="px-3 py-2">{{ row.site_name }}</td>
            <td class="px-3 py-2">
              {{ row.department_name ?? t('credentials.table.noDepartment') }}
            </td>
            <td class="px-3 py-2">{{ t(`credentials.status.${row.status}`) }}</td>
            <td class="px-3 py-2">
              {{ instant(row.credential?.printed_at ?? null, row.site_id) }}
            </td>
            <td class="px-3 py-2">
              {{ instant(row.credential?.delivered_at ?? null, row.site_id) }}
            </td>
            <td class="px-3 py-2">
              <div class="flex flex-wrap gap-2">
                <button
                  v-if="row.status === 'no_credential' || row.status === 'revoked'"
                  type="button"
                  :class="rowButtonClass"
                  @click="openAction('issue', row)"
                >
                  {{ t('credentials.actions.issue') }}
                </button>
                <button
                  v-if="row.status === 'pending_print'"
                  type="button"
                  :class="rowButtonClass"
                  @click="openAction('print', row)"
                >
                  {{ t('credentials.actions.print') }}
                </button>
                <button
                  v-if="row.status === 'pending_delivery'"
                  type="button"
                  :class="rowButtonClass"
                  @click="openAction('deliver', row)"
                >
                  {{ t('credentials.actions.deliver') }}
                </button>
                <button
                  v-if="
                    row.credential !== null &&
                    row.status !== 'revoked' &&
                    row.status !== 'no_credential'
                  "
                  type="button"
                  :class="rowButtonClass"
                  @click="openAction('revoke', row)"
                >
                  {{ t('credentials.actions.revoke') }}
                </button>
              </div>
            </td>
          </tr>
        </tbody>
      </table>
    </div>

    <!-- Confirmaciones -->
    <ConfirmDialog
      v-if="action !== null"
      :title="t(`credentials.confirm.${action.kind}.heading`)"
      :confirm-label="t(`credentials.confirm.${action.kind}.action`)"
      :tone="action.kind === 'issue' ? 'normal' : 'danger'"
      :busy="busy"
      :error="actionError"
      size="wide"
      :confirm-disabled="action.kind === 'revoke' && revocationReason === ''"
      @cancel="closeAction"
      @confirm="runAction"
    >
      <p class="mb-4">
        {{ t(`credentials.confirm.${action.kind}.explanation`, { name: action.row.full_name }) }}
      </p>

      <div v-if="action.kind === 'revoke'" class="mb-4 grid gap-4 sm:grid-cols-2">
        <div class="flex flex-col gap-1">
          <label for="revocation-reason" class="font-medium">
            {{ t('credentials.revoke.reasonLabel') }}
          </label>
          <select id="revocation-reason" v-model="revocationReasonKey" :class="selectClass">
            <option value="">{{ t('credentials.revoke.reasonPlaceholder') }}</option>
            <option v-for="key of REVOCATION_REASONS" :key="key" :value="key">
              {{ t(`credentials.revoke.reasons.${key}`) }}
            </option>
            <option value="other">{{ t('credentials.revoke.reasons.other') }}</option>
          </select>
        </div>
        <div v-if="revocationReasonKey === 'other'" class="flex flex-col gap-1">
          <label for="revocation-reason-text" class="font-medium">
            {{ t('credentials.revoke.reasonOtherLabel') }}
          </label>
          <input
            id="revocation-reason-text"
            v-model="revocationReasonText"
            type="text"
            maxlength="190"
            :class="selectClass"
          />
        </div>
      </div>

      <ChangePreview
        :changes="changesFor(action.kind, action.row)"
        :caption="t(`credentials.confirm.${action.kind}.heading`)"
      />

      <p class="mt-4 text-sm text-slate-600">
        {{ t(`credentials.confirm.${action.kind}.notice`) }}
      </p>
    </ConfirmDialog>

    <ConfirmDialog
      v-if="batching"
      :title="t('credentials.batch.heading')"
      :confirm-label="t('credentials.batch.confirmAction')"
      tone="danger"
      :busy="busy"
      :error="actionError"
      @cancel="batching = false"
      @confirm="runBatch"
    >
      <p>
        {{
          t('credentials.batch.explanation', {
            count: pendingPrintInScope,
            scope: scopeName,
          })
        }}
      </p>
      <p class="mt-3 rounded border border-amber-400 bg-amber-50 p-3 text-amber-900">
        {{ t('credentials.batch.warning') }}
      </p>
      <p class="mt-3 text-sm text-slate-600">{{ t('credentials.batch.bearerNotice') }}</p>
    </ConfirmDialog>
  </section>
</template>
