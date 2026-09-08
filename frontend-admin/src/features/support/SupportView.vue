<script setup lang="ts">
// Soporte (RF-PD-09, RF-PD-11, ADR-020): el paquete de diagnostico y los
// accesos temporales que el cliente concede al fabricante.
//
// DOS BLOQUES, DOS DECISIONES DISTINTAS (ver `README.md`):
//
//  1. El paquete se genera y se descarga con un clic. Va **anonimizado por
//     defecto**; incluir datos personales es una accion distinta, explicita,
//     avisada aqui mismo y auditada aparte (regla dura 16, RL-19).
//  2. Conceder un acceso emite un token que **se enseña una sola vez**: ni
//     esta pantalla ni el servidor lo vuelven a mostrar nunca. Si se pierde,
//     se revoca esa concesion y se crea otra.
//
// Esta pantalla NUNCA interpreta el contenido del paquete: lo descarga tal
// cual llega y lo suelta, como el CSV de la exportacion legal o el PDF de una
// credencial.
import { announce } from '@kronoqr/web-kit/announcer'
import EmptyState from '@kronoqr/web-kit/components/EmptyState.vue'
import ErrorNotice from '@kronoqr/web-kit/components/ErrorNotice.vue'
import FormField from '@kronoqr/web-kit/components/FormField.vue'
import LoadingPanel from '@kronoqr/web-kit/components/LoadingPanel.vue'
import { formatInstantWithZone, FALLBACK_TIMEZONE } from '@kronoqr/web-kit/datetime'
import { downloadDocument } from '@kronoqr/web-kit/downloadDocument'
import { useQuery } from '@tanstack/vue-query'
import { computed, onMounted, onUnmounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { getSite } from '@/shared/api/organisation.api'
import type { SupportGrant, SupportScope } from '@/shared/api/types'
import { DIAGNOSTICS_MANAGE, SUPPORT_MANAGE } from '@/features/auth/abilities'
import { useSessionStore } from '@/features/auth/session.store'
import ConfirmDialog from '@/shared/ui/ConfirmDialog.vue'
import {
  generateDiagnosticsBundle,
  grantSupportAccess,
  listSupportGrants,
  revokeSupportAccess,
} from './support.api'
import { elapsedSinceAccess } from './useSupportGrantRows'

/** Cada cuanto se repinta el tiempo transcurrido desde el ultimo uso. */
const CLOCK_TICK_MS = 30_000

const { t, locale } = useI18n()
const session = useSessionStore()

/**
 * Cortesia, no seguridad (regla dura 18): la ruta la alcanza quien lleva
 * `support:*` **o** `diagnostics:*`, pero cada bloque exige el suyo. Sin
 * esto, una sesion con un solo ambito (teorico hoy: solo `admin` los lleva
 * los dos, `['*']`) veria un formulario que el servidor rechazaria con
 * `403`.
 */
const canGenerateDiagnostics = computed(() => session.can(DIAGNOSTICS_MANAGE))
const canManageSupportGrants = computed(() => session.can(SUPPORT_MANAGE))

const { data: site } = useQuery({ queryKey: ['site'] as const, queryFn: getSite })
const timezone = computed(() => site.value?.timezone ?? FALLBACK_TIMEZONE)

const now = ref(Date.now())
let clockTimer: ReturnType<typeof setInterval> | undefined

onMounted(() => {
  clockTimer = setInterval(() => {
    now.value = Date.now()
  }, CLOCK_TICK_MS)
})

onUnmounted(() => {
  clearInterval(clockTimer)
})

function instantLabel(value: string | null): string {
  return value === null
    ? t('common.empty')
    : formatInstantWithZone(value, timezone.value, locale.value)
}

// --- Bloque 1: paquete de diagnostico (RF-PD-09) -----------------------------

/** Desmarcada por defecto: el paquete sale anonimizado salvo decision expresa. */
const includePersonalData = ref(false)
const periodDays = ref(7)
const generating = ref(false)
const generateError = ref<unknown>(null)
const downloadedFilename = ref<string | null>(null)

const periodDaysOptions = Array.from({ length: 31 }, (_unused, index) => index + 1)

async function generateBundle(): Promise<void> {
  generating.value = true
  generateError.value = null
  downloadedFilename.value = null

  try {
    const document_ = await generateDiagnosticsBundle({
      include_personal_data: includePersonalData.value,
      period_days: periodDays.value,
    })

    downloadDocument(document_)
    downloadedFilename.value = document_.filename
    announce(t('support.diagnostics.announceDownloaded', { filename: document_.filename }))
  } catch (failure) {
    generateError.value = failure
  } finally {
    generating.value = false
  }
}

// --- Bloque 2: accesos de soporte (RF-PD-11) ---------------------------------

const grants = ref<SupportGrant[]>([])
const grantsLoading = ref(true)
const grantsError = ref<unknown>(null)

async function loadGrants(): Promise<void> {
  grantsLoading.value = true
  grantsError.value = null

  try {
    grants.value = (await listSupportGrants()).data
  } catch (failure) {
    grantsError.value = failure
  } finally {
    grantsLoading.value = false
  }
}

onMounted(() => {
  if (canManageSupportGrants.value) {
    void loadGrants()
  }
})

const SCOPE_VALUES: readonly SupportScope[] = ['diagnostics', 'read_only', 'configuration']

const scopeOptions = computed(() =>
  SCOPE_VALUES.map((value) => ({
    value,
    label: t(`support.grant.scope.${value}.label`),
    description: t(`support.grant.scope.${value}.description`),
  })),
)

const reasonInput = ref('')
const scopeInput = ref<SupportScope>('diagnostics')
const hoursInput = ref<number | string>(24)
const granting = ref(false)
const grantError = ref<unknown>(null)
const issuedToken = ref<string | null>(null)
const copied = ref(false)

const fieldLabels = computed<Record<string, string>>(() => ({
  reason: t('support.grant.fields.reason'),
  scope: t('support.grant.fields.scope'),
  hours: t('support.grant.fields.hours'),
}))

/** Un entero, o `undefined` si lo escrito no lo es: el servidor decide el rango. */
function asInteger(raw: number | string): number | undefined {
  if (typeof raw === 'number') {
    return Number.isInteger(raw) ? raw : undefined
  }

  const trimmed = raw.trim()

  return /^\d+$/.test(trimmed) ? Number.parseInt(trimmed, 10) : undefined
}

const reasonLength = computed(() => reasonInput.value.trim().length)
const reasonValid = computed(() => reasonLength.value >= 3 && reasonLength.value <= 200)
const hoursValue = computed(() => asInteger(hoursInput.value))
const hoursValid = computed(
  () => hoursValue.value !== undefined && hoursValue.value >= 1 && hoursValue.value <= 72,
)

const canGrant = computed(() => reasonValid.value && hoursValid.value && !granting.value)

async function grant(): Promise<void> {
  if (!canGrant.value) {
    return
  }

  granting.value = true
  grantError.value = null

  try {
    // `canGrant` ya exige `hoursValid` (1..72): el valor nunca es
    // `undefined` en este punto. El respaldo es solo para que el tipo
    // compile sin fingir con `!`.
    const issued = await grantSupportAccess({
      reason: reasonInput.value.trim(),
      scope: scopeInput.value,
      hours: hoursValue.value ?? 24,
    })

    issuedToken.value = issued.data.token
    copied.value = false
    reasonInput.value = ''
    scopeInput.value = 'diagnostics'
    hoursInput.value = 24
    await loadGrants()
    announce(t('support.grant.announceGranted'))
  } catch (failure) {
    grantError.value = failure
  } finally {
    granting.value = false
  }
}

function fallbackCopy(value: string): void {
  const textarea = document.createElement('textarea')

  textarea.value = value
  textarea.setAttribute('readonly', '')
  textarea.style.position = 'fixed'
  textarea.style.opacity = '0'
  document.body.appendChild(textarea)
  textarea.select()
  document.execCommand('copy')
  document.body.removeChild(textarea)
}

async function copyToken(): Promise<void> {
  const value = issuedToken.value

  if (value === null) {
    return
  }

  try {
    if (navigator.clipboard?.writeText !== undefined) {
      await navigator.clipboard.writeText(value)
    } else {
      fallbackCopy(value)
    }
  } catch {
    fallbackCopy(value)
  }

  copied.value = true
  announce(t('support.grant.copied'))
}

function usedLabel(entry: SupportGrant): string {
  const elapsed = elapsedSinceAccess(entry.accessed_at, now.value)

  return elapsed === null
    ? t('support.grant.list.neverUsed')
    : t('support.grant.list.usedAgo', { hours: elapsed.hours, minutes: elapsed.minutes })
}

const revokeTarget = ref<SupportGrant | null>(null)
const revokeBusy = ref(false)
const revokeError = ref<unknown>(null)

function openRevoke(entry: SupportGrant): void {
  revokeTarget.value = entry
  revokeError.value = null
}

function closeRevoke(): void {
  revokeTarget.value = null
  revokeError.value = null
}

async function confirmRevoke(): Promise<void> {
  const target = revokeTarget.value

  if (target === null) {
    return
  }

  revokeBusy.value = true
  revokeError.value = null

  try {
    await revokeSupportAccess(target.uuid)
    await loadGrants()
    announce(t('support.grant.announceRevoked'))
    closeRevoke()
  } catch (failure) {
    revokeError.value = failure
  } finally {
    revokeBusy.value = false
  }
}

const buttonClass =
  'rounded-kq-sm border border-kq-border-strong bg-kq-surface-raised px-2 py-1 text-sm text-kq-text hover:bg-kq-surface-alt'
</script>

<template>
  <section class="flex flex-col gap-10">
    <header class="flex flex-col gap-2">
      <h1 class="text-2xl font-semibold">{{ t('support.heading') }}</h1>
      <p class="max-w-3xl text-kq-text-muted">{{ t('support.intro') }}</p>
    </header>

    <!-- Bloque 1: paquete de diagnostico (RF-PD-09) -->
    <section
      v-if="canGenerateDiagnostics"
      class="flex max-w-3xl flex-col gap-4"
      data-test="diagnostics-block"
    >
      <div>
        <h2 class="text-lg font-semibold">{{ t('support.diagnostics.heading') }}</h2>
        <p class="mt-1 text-kq-text-muted">{{ t('support.diagnostics.intro') }}</p>
      </div>

      <ErrorNotice v-if="generateError !== null" :error="generateError" />

      <p
        v-if="downloadedFilename !== null"
        role="status"
        class="rounded-kq border border-kq-success bg-kq-success-soft p-4 text-kq-success"
        data-test="diagnostics-success"
      >
        {{ t('support.diagnostics.success', { filename: downloadedFilename }) }}
      </p>

      <div class="flex flex-col gap-2">
        <label class="flex items-center gap-2 text-kq-text">
          <input v-model="includePersonalData" type="checkbox" data-test="include-personal-data" />
          {{ t('support.diagnostics.includePersonalData') }}
        </label>

        <p
          v-if="includePersonalData"
          role="alert"
          class="rounded-kq border border-kq-warning bg-kq-warning-soft p-4 text-kq-warning"
          data-test="personal-data-warning"
        >
          {{ t('support.diagnostics.personalDataWarning') }}
        </p>

        <FormField
          v-if="includePersonalData"
          :label="t('support.diagnostics.periodDays')"
          :hint="t('support.diagnostics.periodDaysHint')"
        >
          <template #default="{ id, describedBy }">
            <select
              :id="id"
              v-model.number="periodDays"
              data-test="period-days"
              :aria-describedby="describedBy"
              class="w-24 rounded-kq-sm border border-kq-border-strong bg-kq-surface-raised px-3 py-2 text-kq-text"
            >
              <option v-for="day of periodDaysOptions" :key="day" :value="day">{{ day }}</option>
            </select>
          </template>
        </FormField>
      </div>

      <div>
        <button
          type="button"
          class="rounded-kq-sm bg-kq-primary-strong px-4 py-2 font-semibold text-kq-on-primary disabled:opacity-60"
          :disabled="generating"
          :aria-busy="generating"
          data-test="generate-bundle"
          @click="generateBundle"
        >
          {{ generating ? t('support.diagnostics.generating') : t('support.diagnostics.generate') }}
        </button>
      </div>
    </section>

    <!-- Bloque 2: accesos de soporte (RF-PD-11) -->
    <section
      v-if="canManageSupportGrants"
      class="flex max-w-3xl flex-col gap-4"
      data-test="grants-block"
    >
      <div>
        <h2 class="text-lg font-semibold">{{ t('support.grant.heading') }}</h2>
        <p class="mt-1 text-kq-text-muted">{{ t('support.grant.intro') }}</p>
      </div>

      <form class="flex flex-col gap-4" novalidate @submit.prevent="grant">
        <ErrorNotice v-if="grantError !== null" :error="grantError" :field-labels="fieldLabels" />

        <FormField
          :label="t('support.grant.fields.reason')"
          :hint="t('support.grant.fields.reasonHint')"
          required
        >
          <template #default="{ id, describedBy }">
            <input
              :id="id"
              v-model="reasonInput"
              type="text"
              maxlength="200"
              autocomplete="off"
              data-test="reason"
              :aria-describedby="describedBy"
              class="rounded-kq-sm border border-kq-border-strong bg-kq-surface-raised px-3 py-2 text-kq-text"
            />
          </template>
        </FormField>

        <fieldset class="flex flex-col gap-3">
          <legend class="font-medium text-kq-text">{{ t('support.grant.fields.scope') }}</legend>
          <div v-for="option of scopeOptions" :key="option.value" class="flex flex-col gap-1">
            <label class="flex items-center gap-2 text-kq-text">
              <input
                v-model="scopeInput"
                type="radio"
                name="support-grant-scope"
                :value="option.value"
                :data-test="`scope-${option.value}`"
              />
              {{ option.label }}
            </label>
            <p class="pl-6 text-sm text-kq-text-muted">{{ option.description }}</p>
          </div>
          <p class="text-sm text-kq-text-muted">{{ t('support.grant.scopeNeverNote') }}</p>
        </fieldset>

        <FormField
          :label="t('support.grant.fields.hours')"
          :hint="t('support.grant.fields.hoursHint')"
        >
          <template #default="{ id, describedBy }">
            <input
              :id="id"
              v-model="hoursInput"
              type="number"
              inputmode="numeric"
              min="1"
              max="72"
              data-test="hours"
              :aria-describedby="describedBy"
              class="w-24 rounded-kq-sm border border-kq-border-strong bg-kq-surface-raised px-3 py-2 text-kq-text"
            />
          </template>
        </FormField>

        <div>
          <button
            type="submit"
            class="rounded-kq-sm bg-kq-primary-strong px-4 py-2 font-semibold text-kq-on-primary disabled:opacity-50"
            :disabled="!canGrant"
            :aria-busy="granting"
            data-test="grant-submit"
          >
            {{ granting ? t('support.grant.submitting') : t('support.grant.submit') }}
          </button>
        </div>
      </form>

      <!-- El token, UNA sola vez. No se guarda en ningun sitio salvo en este
           estado efimero de la vista, que desaparece al salir de la pantalla. -->
      <div
        v-if="issuedToken !== null"
        class="flex flex-col gap-2 rounded-kq border border-kq-warning bg-kq-warning-soft p-4"
        data-test="issued-token"
      >
        <p class="font-semibold text-kq-text">{{ t('support.grant.tokenIssued') }}</p>
        <p
          class="select-all break-all rounded-kq-sm border border-kq-border-strong bg-kq-surface-raised px-3 py-2 font-mono text-sm text-kq-text"
          data-test="token-value"
        >
          {{ issuedToken }}
        </p>
        <div class="flex items-center gap-3">
          <button type="button" :class="buttonClass" data-test="copy-token" @click="copyToken">
            {{ copied ? t('support.grant.copied') : t('support.grant.copy') }}
          </button>
        </div>
        <p class="text-sm text-kq-text">{{ t('support.grant.tokenWarning') }}</p>
      </div>

      <LoadingPanel v-if="grantsLoading" :label="t('support.grant.list.loading')" />
      <ErrorNotice v-else-if="grantsError !== null" :error="grantsError" />
      <EmptyState
        v-else-if="grants.length === 0"
        :title="t('support.grant.list.empty.title')"
        :description="t('support.grant.list.empty.description')"
      />

      <div
        v-else
        class="overflow-x-auto rounded-kq border border-kq-border bg-kq-surface-raised shadow-kq-soft"
      >
        <table class="w-full border-collapse text-left">
          <caption class="px-3 py-2 text-left text-sm text-kq-text-muted">
            {{
              t('support.grant.list.caption')
            }}
          </caption>
          <thead class="border-b border-kq-border bg-kq-surface-alt">
            <tr>
              <th scope="col" class="px-3 py-2">{{ t('support.grant.list.columns.reason') }}</th>
              <th scope="col" class="px-3 py-2">{{ t('support.grant.list.columns.scope') }}</th>
              <th scope="col" class="px-3 py-2">
                {{ t('support.grant.list.columns.grantedBy') }}
              </th>
              <th scope="col" class="px-3 py-2">
                {{ t('support.grant.list.columns.grantedAt') }}
              </th>
              <th scope="col" class="px-3 py-2">
                {{ t('support.grant.list.columns.expiresAt') }}
              </th>
              <th scope="col" class="px-3 py-2">{{ t('support.grant.list.columns.status') }}</th>
              <th scope="col" class="px-3 py-2">{{ t('support.grant.list.columns.usedAt') }}</th>
              <th scope="col" class="px-3 py-2">{{ t('support.grant.list.columns.actions') }}</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="entry of grants" :key="entry.uuid" class="border-b border-kq-border">
              <td class="px-3 py-2">{{ entry.reason }}</td>
              <td class="px-3 py-2">{{ t(`support.grant.scope.${entry.scope}.label`) }}</td>
              <td class="px-3 py-2">{{ entry.granted_by.name }}</td>
              <td class="px-3 py-2">{{ instantLabel(entry.granted_at) }}</td>
              <td class="px-3 py-2">{{ instantLabel(entry.expires_at) }}</td>
              <td class="px-3 py-2">
                <span
                  class="rounded-full px-2 py-0.5 text-sm"
                  :class="{
                    'bg-kq-success-soft text-kq-success': entry.status === 'active',
                    'bg-kq-surface-alt text-kq-text-muted': entry.status !== 'active',
                  }"
                  :data-test="`status-${entry.uuid}`"
                >
                  {{ t(`support.grant.status.${entry.status}`) }}
                </span>
              </td>
              <td class="px-3 py-2">{{ usedLabel(entry) }}</td>
              <td class="px-3 py-2">
                <button
                  v-if="entry.status === 'active'"
                  type="button"
                  :class="buttonClass"
                  :data-test="`revoke-${entry.uuid}`"
                  @click="openRevoke(entry)"
                >
                  {{ t('support.grant.revoke.action') }}
                </button>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </section>

    <ConfirmDialog
      v-if="revokeTarget !== null"
      :title="t('support.grant.revoke.heading')"
      :confirm-label="t('support.grant.revoke.action')"
      tone="danger"
      :busy="revokeBusy"
      :error="revokeError"
      @cancel="closeRevoke"
      @confirm="confirmRevoke"
    >
      <p>{{ t('support.grant.revoke.explanation', { reason: revokeTarget.reason }) }}</p>
    </ConfirmDialog>
  </section>
</template>
