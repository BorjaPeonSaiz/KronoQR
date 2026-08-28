<script setup lang="ts">
// Ficha de un empleado (RF-GP-01, RF-GP-03, RF-ID-09).
//
// Es la pantalla donde se corrigen datos, se restablece el PIN y se da de baja.
// Las tres cosas son actos serios y las tres pasan por el mismo patron: se
// enseña QUE se va a cambiar, DESDE que valor y HACIA cual, y solo despues se
// confirma. Ninguna borra nada: la baja es logica y el historico se conserva
// (regla dura 5, RL-02).
import { announce } from '@kronoqr/web-kit/announcer'
import EmptyState from '@kronoqr/web-kit/components/EmptyState.vue'
import ErrorNotice from '@kronoqr/web-kit/components/ErrorNotice.vue'
import FormField from '@kronoqr/web-kit/components/FormField.vue'
import LoadingPanel from '@kronoqr/web-kit/components/LoadingPanel.vue'
import { formatCivilDate, formatInstantWithZone, todayInZone } from '@kronoqr/web-kit/datetime'
import { computed, reactive, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { RouterLink } from 'vue-router'
import { useQuery, useQueryClient } from '@tanstack/vue-query'
import { ATTENDANCE_READ } from '@/features/auth/abilities'
import { useSessionStore } from '@/features/auth/session.store'
import CredentialRowActions from '@/features/credentials/CredentialRowActions.vue'
import { STATUS_PILL_CLASS as CREDENTIAL_STATUS_PILL_CLASS } from '@/features/credentials/credentialStatusPill'
import { fetchCredentialStatusFor } from '@/features/credentials/credentials.api'
import { listDepartments, listSites } from '@/shared/api/organisation.api'
import type { Employee, IssuedPin, UpdateEmployeeRequest } from '@/shared/api/types'
import type { Change } from '@/shared/ui/change'
import ChangePreview from '@/shared/ui/ChangePreview.vue'
import ConfirmDialog from '@/shared/ui/ConfirmDialog.vue'
import PinRevealDialog from './PinRevealDialog.vue'
import {
  deliverEmployeePin,
  getEmployee,
  offboardEmployee,
  resetEmployeePin,
  updateEmployee,
} from './employees.api'

const props = defineProps<{ uuid: string }>()

const { t, locale } = useI18n()
const queryClient = useQueryClient()
const session = useSessionStore()

/**
 * El registro horario de esta persona solo se ofrece a quien puede leerlo. Es
 * cortesia, no seguridad: quien no lo tenga recibiria un 403 del servidor
 * (regla dura 18), pero un enlace que lleva a «sin permiso» no ayuda a nadie.
 */
const canReadAttendance = computed(() => session.can(ATTENDANCE_READ))

const {
  data: employee,
  error,
  isPending,
} = useQuery({
  queryKey: computed(() => ['employee', props.uuid] as const),
  queryFn: () => getEmployee(props.uuid),
})

const { data: sites } = useQuery({ queryKey: ['sites'], queryFn: listSites })
const { data: departments } = useQuery({
  queryKey: ['departments', 'all'],
  queryFn: () => listDepartments(),
})

const site = computed(
  () =>
    (sites.value?.data ?? []).find((candidate) => candidate.id === employee.value?.site_id) ?? null,
)
const timezone = computed(() => site.value?.timezone ?? 'UTC')

// --- Tarjeta QR (RF-QR-04, RF-QR-06, RF-QR-08) --------------------------------
//
// La misma fila que veria RRHH en el tablero de credenciales, pero de esta
// persona sola: se pide con `employee_uuid` en vez de traer el tablero entero
// y filtrar en cliente, porque cada lectura del tablero audita como
// divulgado TODO lo que devuelve (ADR-037).

const {
  data: credentialRow,
  error: credentialError,
  isPending: credentialPending,
} = useQuery({
  queryKey: computed(() => ['credential-board', 'employee', props.uuid] as const),
  queryFn: () => fetchCredentialStatusFor(props.uuid),
})

function instantInSiteZone(value: string | null): string {
  return value === null
    ? t('common.empty')
    : formatInstantWithZone(value, timezone.value, locale.value)
}

async function refreshCredential(): Promise<void> {
  await queryClient.invalidateQueries({ queryKey: ['credential-board'] })
}

function departmentName(id: number | null): string {
  if (id === null) {
    return t('employees.fields.departmentNone')
  }

  return (departments.value?.data ?? []).find((item) => item.id === id)?.name ?? '—'
}

function siteName(id: number | undefined): string {
  if (id === undefined) {
    return '—'
  }

  return (sites.value?.data ?? []).find((candidate) => candidate.id === id)?.name ?? '—'
}

const fullName = computed(() =>
  employee.value === undefined ? '' : `${employee.value.first_name} ${employee.value.last_name}`,
)

// --- Edicion de la ficha -----------------------------------------------------

const editing = ref(false)
const confirmingEdit = ref(false)
const saving = ref(false)
const saveError = ref<unknown>(null)

const form = reactive({
  firstName: '',
  lastName: '',
  email: '',
  siteId: 0,
  departmentId: null as number | null,
  status: 'active' as 'active' | 'suspended',
  locale: 'es',
})

/** Solo los departamentos del centro elegido: uno de otro hotel es un dato imposible. */
const departmentsOfSite = computed(() =>
  (departments.value?.data ?? []).filter((item) => item.site_id === form.siteId),
)

function loadForm(source: Employee): void {
  form.firstName = source.first_name
  form.lastName = source.last_name
  form.email = source.email ?? ''
  form.siteId = source.site_id
  form.departmentId = source.department_id
  form.status = source.status === 'suspended' ? 'suspended' : 'active'
  form.locale = source.locale
}

watch(
  employee,
  (value) => {
    if (value !== undefined && !editing.value) {
      loadForm(value)
    }
  },
  { immediate: true },
)

function startEditing(): void {
  if (employee.value !== undefined) {
    loadForm(employee.value)
  }

  saveError.value = null
  editing.value = true
}

interface PendingUpdate {
  body: UpdateEmployeeRequest
  changes: Change[]
}

const pendingUpdate = computed<PendingUpdate>(() => {
  const current = employee.value
  const body: UpdateEmployeeRequest = {}
  const changes: Change[] = []

  if (current === undefined) {
    return { body, changes }
  }

  if (form.firstName !== current.first_name) {
    body.first_name = form.firstName
    changes.push({
      label: t('employees.fields.firstName'),
      from: current.first_name,
      to: form.firstName,
    })
  }

  if (form.lastName !== current.last_name) {
    body.last_name = form.lastName
    changes.push({
      label: t('employees.fields.lastName'),
      from: current.last_name,
      to: form.lastName,
    })
  }

  const email = form.email.trim() === '' ? null : form.email.trim()

  if (email !== (current.email ?? null)) {
    body.email = email
    changes.push({
      label: t('employees.fields.email'),
      from: current.email ?? t('common.empty'),
      to: email ?? t('common.empty'),
    })
  }

  if (form.siteId !== current.site_id) {
    body.site_id = form.siteId
    changes.push({
      label: t('employees.fields.site'),
      from: siteName(current.site_id),
      to: siteName(form.siteId),
    })
  }

  if (form.departmentId !== current.department_id) {
    body.department_id = form.departmentId
    changes.push({
      label: t('employees.fields.department'),
      from: departmentName(current.department_id),
      to: departmentName(form.departmentId),
    })
  }

  if (current.status !== 'terminated' && form.status !== current.status) {
    body.status = form.status
    changes.push({
      label: t('employees.fields.status'),
      from: t(`employees.status.${current.status}`),
      to: t(`employees.status.${form.status}`),
    })
  }

  if (form.locale !== current.locale) {
    body.locale = form.locale
    changes.push({
      label: t('employees.fields.locale'),
      from: current.locale,
      to: form.locale,
    })
  }

  return { body, changes }
})

async function confirmUpdate(): Promise<void> {
  saving.value = true
  saveError.value = null

  try {
    await updateEmployee(props.uuid, pendingUpdate.value.body)
    await invalidate()
    announce(t('employees.announce.updated'))
    confirmingEdit.value = false
    editing.value = false
  } catch (caught) {
    saveError.value = caught
  } finally {
    saving.value = false
  }
}

// --- PIN ---------------------------------------------------------------------

const confirmingPinReset = ref(false)
const confirmingPinDelivery = ref(false)
const pinBusy = ref(false)
const pinError = ref<unknown>(null)

/** El PIN en claro, solo mientras el dialogo esta abierto. Nunca se persiste. */
const revealedPin = ref<IssuedPin | null>(null)

const pinResetChanges = computed<Change[]>(() => [
  {
    label: t('pin.field'),
    from: t(`pin.status.${employee.value?.pin_status ?? 'pending'}`),
    to: t('pin.status.issued'),
  },
])

const pinDeliveryChanges = computed<Change[]>(() => [
  {
    label: t('pin.field'),
    from: t(`pin.status.${employee.value?.pin_status ?? 'pending'}`),
    to: t('pin.status.delivered'),
  },
])

async function confirmPinReset(): Promise<void> {
  pinBusy.value = true
  pinError.value = null

  try {
    revealedPin.value = await resetEmployeePin(props.uuid)
    confirmingPinReset.value = false
    await invalidate()
    announce(t('pin.announce.reset'))
  } catch (caught) {
    pinError.value = caught
  } finally {
    pinBusy.value = false
  }
}

async function confirmPinDelivery(): Promise<void> {
  pinBusy.value = true
  pinError.value = null

  try {
    await deliverEmployeePin(props.uuid)
    confirmingPinDelivery.value = false
    await invalidate()
    announce(t('pin.announce.delivered'))
  } catch (caught) {
    pinError.value = caught
  } finally {
    pinBusy.value = false
  }
}

function closePinDialog(): void {
  revealedPin.value = null
}

async function onPinDeliveredFromDialog(): Promise<void> {
  closePinDialog()
  await invalidate()
  announce(t('pin.announce.delivered'))
}

// --- Baja --------------------------------------------------------------------

const offboarding = ref(false)
const offboardBusy = ref(false)
const offboardError = ref<unknown>(null)
const terminatedAt = ref('')
const offboardReasonKey = ref('')
const offboardReasonText = ref('')

const OFFBOARD_REASONS = [
  'endOfContract',
  'resignation',
  'dismissal',
  'retirement',
  'other',
] as const

const offboardReason = computed(() =>
  offboardReasonKey.value === 'other'
    ? offboardReasonText.value.trim()
    : offboardReasonKey.value === ''
      ? ''
      : t(`employees.offboard.reasons.${offboardReasonKey.value}`),
)

const offboardChanges = computed<Change[]>(() => [
  {
    label: t('employees.fields.status'),
    from: t(`employees.status.${employee.value?.status ?? 'active'}`),
    to: t('employees.status.terminated'),
  },
  {
    label: t('employees.fields.terminatedAt'),
    from: t('common.empty'),
    to:
      terminatedAt.value === ''
        ? t('common.empty')
        : formatCivilDate(terminatedAt.value, locale.value),
  },
  {
    label: t('employees.offboard.reasonLabel'),
    from: t('common.empty'),
    to: offboardReason.value === '' ? t('common.empty') : offboardReason.value,
  },
])

function startOffboarding(): void {
  terminatedAt.value = todayInZone(timezone.value)
  offboardReasonKey.value = ''
  offboardReasonText.value = ''
  offboardError.value = null
  offboarding.value = true
}

async function confirmOffboard(): Promise<void> {
  offboardBusy.value = true
  offboardError.value = null

  try {
    await offboardEmployee(props.uuid, {
      terminated_at: terminatedAt.value,
      reason: offboardReason.value,
    })
    await invalidate()
    announce(t('employees.announce.offboarded'))
    offboarding.value = false
  } catch (caught) {
    offboardError.value = caught
  } finally {
    offboardBusy.value = false
  }
}

async function invalidate(): Promise<void> {
  await queryClient.invalidateQueries({ queryKey: ['employee', props.uuid] })
  await queryClient.invalidateQueries({ queryKey: ['employees'] })
}

const inputClass =
  'rounded-kq-sm border border-kq-border-strong bg-kq-surface-raised px-3 py-2 text-kq-text'

const STATUS_PILL_CLASS: Record<Employee['status'], string> = {
  active: 'bg-kq-success-soft text-kq-success',
  suspended: 'bg-kq-warning-soft text-kq-warning',
  terminated: 'bg-kq-danger-soft text-kq-danger',
}
</script>

<template>
  <section>
    <RouterLink :to="{ name: 'employees' }" class="text-kq-primary-strong underline">
      {{ t('employees.detail.backToList') }}
    </RouterLink>

    <LoadingPanel v-if="isPending" :label="t('employees.detail.loading')" class="mt-4" />
    <ErrorNotice v-else-if="error !== null" :error="error" class="mt-4" />

    <template v-else-if="employee !== undefined">
      <header class="mt-4 flex flex-wrap items-center justify-between gap-4">
        <div>
          <h1 class="text-2xl font-bold">{{ fullName }}</h1>
          <p class="mt-1 font-mono text-kq-text-muted">{{ employee.employee_code }}</p>
        </div>
        <p class="rounded-full px-3 py-1 text-sm" :class="STATUS_PILL_CLASS[employee.status]">
          {{ t(`employees.status.${employee.status}`) }}
        </p>
      </header>

      <!-- Datos -->
      <section
        class="mt-6 rounded-kq border border-kq-border bg-kq-surface-raised p-4 shadow-kq-soft"
      >
        <div class="flex flex-wrap items-center justify-between gap-3">
          <h2 class="text-xl font-semibold">{{ t('employees.detail.dataHeading') }}</h2>
          <button
            v-if="!editing"
            type="button"
            class="rounded-kq-sm border border-kq-border-strong bg-kq-surface-raised px-3 py-2 text-kq-text hover:bg-kq-surface-alt"
            @click="startEditing"
          >
            {{ t('common.edit') }}
          </button>
        </div>

        <dl v-if="!editing" class="mt-4 grid gap-3 sm:grid-cols-2">
          <div>
            <dt class="font-medium text-kq-text-muted">{{ t('employees.fields.site') }}</dt>
            <dd>{{ siteName(employee.site_id) }}</dd>
          </div>
          <div>
            <dt class="font-medium text-kq-text-muted">{{ t('employees.fields.department') }}</dt>
            <dd>{{ departmentName(employee.department_id) }}</dd>
          </div>
          <div>
            <dt class="font-medium text-kq-text-muted">{{ t('employees.fields.email') }}</dt>
            <dd>{{ employee.email ?? t('employees.fields.emailAbsent') }}</dd>
          </div>
          <div>
            <dt class="font-medium text-kq-text-muted">{{ t('employees.fields.locale') }}</dt>
            <dd>{{ employee.locale }}</dd>
          </div>
          <div>
            <dt class="font-medium text-kq-text-muted">{{ t('employees.fields.hiredAt') }}</dt>
            <dd>{{ formatCivilDate(employee.hired_at, locale) }}</dd>
          </div>
          <div v-if="employee.terminated_at !== null">
            <dt class="font-medium text-kq-text-muted">{{ t('employees.fields.terminatedAt') }}</dt>
            <dd>{{ formatCivilDate(employee.terminated_at, locale) }}</dd>
          </div>
        </dl>

        <form
          v-else
          id="employee-edit-form"
          class="mt-4 grid gap-4 sm:grid-cols-2"
          novalidate
          @submit.prevent="confirmingEdit = true"
        >
          <FormField v-slot="field" :label="t('employees.fields.firstName')" required>
            <input
              :id="field.id"
              v-model="form.firstName"
              type="text"
              required
              :class="inputClass"
            />
          </FormField>
          <FormField v-slot="field" :label="t('employees.fields.lastName')" required>
            <input
              :id="field.id"
              v-model="form.lastName"
              type="text"
              required
              :class="inputClass"
            />
          </FormField>
          <FormField
            v-slot="field"
            :label="t('employees.fields.email')"
            :hint="t('employees.fields.emailHint')"
          >
            <input
              :id="field.id"
              v-model="form.email"
              type="email"
              :class="inputClass"
              :aria-describedby="field.describedBy"
            />
          </FormField>
          <FormField v-slot="field" :label="t('employees.fields.site')">
            <select :id="field.id" v-model.number="form.siteId" :class="inputClass">
              <option
                v-for="candidate of sites?.data ?? []"
                :key="candidate.id"
                :value="candidate.id"
              >
                {{ candidate.name }}
              </option>
            </select>
          </FormField>
          <FormField v-slot="field" :label="t('employees.fields.department')">
            <select :id="field.id" v-model="form.departmentId" :class="inputClass">
              <option :value="null">{{ t('employees.fields.departmentNone') }}</option>
              <option v-for="item of departmentsOfSite" :key="item.id" :value="item.id">
                {{ item.name }}
              </option>
            </select>
          </FormField>
          <FormField
            v-if="employee.status !== 'terminated'"
            v-slot="field"
            :label="t('employees.fields.status')"
            :hint="t('employees.detail.statusHint')"
          >
            <select
              :id="field.id"
              v-model="form.status"
              :class="inputClass"
              :aria-describedby="field.describedBy"
            >
              <option value="active">{{ t('employees.status.active') }}</option>
              <option value="suspended">{{ t('employees.status.suspended') }}</option>
            </select>
          </FormField>
          <FormField v-slot="field" :label="t('employees.fields.locale')">
            <select :id="field.id" v-model="form.locale" :class="inputClass">
              <option value="es">{{ t('common.locales.es') }}</option>
              <option value="en">{{ t('common.locales.en') }}</option>
            </select>
          </FormField>

          <div class="flex gap-3 sm:col-span-2">
            <button
              type="button"
              class="rounded-kq-sm border border-kq-border-strong bg-kq-surface-raised px-4 py-2 text-kq-text hover:bg-kq-surface-alt"
              @click="editing = false"
            >
              {{ t('common.cancel') }}
            </button>
            <button
              type="submit"
              :disabled="pendingUpdate.changes.length === 0"
              class="rounded-kq-sm bg-kq-primary-strong px-4 py-2 font-semibold text-kq-on-primary disabled:opacity-60"
            >
              {{ t('employees.detail.reviewChanges') }}
            </button>
          </div>
        </form>
      </section>

      <!-- PIN -->
      <section
        class="mt-6 rounded-kq border border-kq-border bg-kq-surface-raised p-4 shadow-kq-soft"
      >
        <h2 class="text-xl font-semibold">{{ t('pin.heading') }}</h2>
        <p class="mt-1 text-kq-text-muted">{{ t('pin.explanation') }}</p>
        <p class="mt-3">
          <span class="font-medium">{{ t('pin.field') }}:</span>
          {{ t(`pin.status.${employee.pin_status}`) }}
        </p>
        <p class="mt-1 text-sm text-kq-text-muted">
          {{ t(`pin.statusHint.${employee.pin_status}`) }}
        </p>

        <div class="mt-4 flex flex-wrap gap-3">
          <button
            type="button"
            class="rounded-kq-sm border border-kq-border-strong bg-kq-surface-raised px-4 py-2 text-kq-text hover:bg-kq-surface-alt"
            @click="confirmingPinReset = true"
          >
            {{ t('pin.actions.reset') }}
          </button>
          <button
            v-if="employee.pin_status === 'issued'"
            type="button"
            class="rounded-kq-sm bg-kq-primary-strong px-4 py-2 font-semibold text-kq-on-primary"
            @click="confirmingPinDelivery = true"
          >
            {{ t('pin.actions.registerDelivery') }}
          </button>
        </div>
      </section>

      <!-- Registro horario (RF-PA-03) -->
      <section
        v-if="canReadAttendance"
        class="mt-6 rounded-kq border border-kq-border bg-kq-surface-raised p-4 shadow-kq-soft"
      >
        <h2 class="text-xl font-semibold">{{ t('workdays.title') }}</h2>
        <p class="mt-1 max-w-prose text-kq-text-muted">{{ t('workdays.fromEmployee') }}</p>
        <RouterLink
          :to="{ name: 'employee-workdays', params: { uuid: employee.uuid } }"
          class="mt-3 inline-block text-kq-primary-strong underline"
        >
          {{ t('workdays.linkFromEmployee') }}
        </RouterLink>
      </section>

      <!-- Credencial (RF-QR-04, RF-QR-06, RF-QR-08) -->
      <section
        class="mt-6 rounded-kq border border-kq-border bg-kq-surface-raised p-4 shadow-kq-soft"
      >
        <h2 class="text-xl font-semibold">{{ t('employees.detail.credentialHeading') }}</h2>

        <LoadingPanel v-if="credentialPending" :label="t('credentials.loading')" class="mt-4" />
        <ErrorNotice v-else-if="credentialError !== null" :error="credentialError" class="mt-4" />
        <EmptyState
          v-else-if="credentialRow === null || credentialRow === undefined"
          class="mt-4"
          :title="t('employees.detail.credentialEmpty.title')"
          :description="t('employees.detail.credentialEmpty.description')"
        />

        <div v-else class="mt-4 rounded-kq border border-kq-border bg-kq-surface-raised p-4">
          <dl class="grid gap-3 sm:grid-cols-2">
            <div>
              <dt class="font-medium text-kq-text-muted">{{ t('credentials.table.site') }}</dt>
              <dd>{{ credentialRow.site_name }}</dd>
            </div>
            <div>
              <dt class="font-medium text-kq-text-muted">
                {{ t('credentials.table.department') }}
              </dt>
              <dd>{{ credentialRow.department_name ?? t('credentials.table.noDepartment') }}</dd>
            </div>
            <div>
              <dt class="font-medium text-kq-text-muted">{{ t('credentials.table.status') }}</dt>
              <dd>
                <span
                  class="rounded-full px-2 py-0.5 text-sm"
                  :class="CREDENTIAL_STATUS_PILL_CLASS[credentialRow.status]"
                >
                  {{ t(`credentials.status.${credentialRow.status}`) }}
                </span>
              </dd>
            </div>
            <div>
              <dt class="font-medium text-kq-text-muted">{{ t('credentials.table.printedAt') }}</dt>
              <dd>{{ instantInSiteZone(credentialRow.credential?.printed_at ?? null) }}</dd>
            </div>
            <div>
              <dt class="font-medium text-kq-text-muted">
                {{ t('credentials.table.deliveredAt') }}
              </dt>
              <dd>{{ instantInSiteZone(credentialRow.credential?.delivered_at ?? null) }}</dd>
            </div>
          </dl>

          <div class="mt-4">
            <CredentialRowActions
              :row="credentialRow"
              :time-zone="timezone"
              @changed="refreshCredential"
            />
          </div>
        </div>
      </section>

      <!-- Baja -->
      <section
        class="mt-6 rounded-kq border border-kq-border bg-kq-surface-raised p-4 shadow-kq-soft"
      >
        <h2 class="text-xl font-semibold">{{ t('employees.offboard.heading') }}</h2>
        <p class="mt-1 max-w-prose text-kq-text-muted">{{ t('employees.offboard.explanation') }}</p>
        <p v-if="employee.status === 'terminated'" class="mt-3">
          {{
            t('employees.offboard.alreadyTerminated', {
              date: formatCivilDate(employee.terminated_at ?? '', locale),
            })
          }}
        </p>
        <button
          v-else
          type="button"
          class="mt-3 rounded-kq-sm bg-kq-danger px-4 py-2 font-semibold text-kq-on-danger"
          @click="startOffboarding"
        >
          {{ t('employees.offboard.action') }}
        </button>
      </section>
    </template>

    <!-- Confirmaciones -->
    <ConfirmDialog
      v-if="confirmingEdit"
      :title="t('employees.detail.confirmHeading')"
      :confirm-label="t('employees.detail.confirmAction')"
      :busy="saving"
      :error="saveError"
      size="wide"
      @cancel="confirmingEdit = false"
      @confirm="confirmUpdate"
    >
      <p class="mb-4">{{ t('employees.detail.confirmExplanation') }}</p>
      <ChangePreview
        :changes="pendingUpdate.changes"
        :caption="t('employees.detail.confirmHeading')"
      />
    </ConfirmDialog>

    <ConfirmDialog
      v-if="confirmingPinReset"
      :title="t('pin.reset.heading')"
      :confirm-label="t('pin.reset.action')"
      tone="danger"
      :busy="pinBusy"
      :error="pinError"
      @cancel="confirmingPinReset = false"
      @confirm="confirmPinReset"
    >
      <p class="mb-4">{{ t('pin.reset.explanation') }}</p>
      <ChangePreview :changes="pinResetChanges" :caption="t('pin.reset.heading')" />
      <p class="mt-4 rounded-kq-sm border border-kq-warning bg-kq-warning-soft p-3 text-kq-warning">
        {{ t('pin.reset.warning') }}
      </p>
    </ConfirmDialog>

    <ConfirmDialog
      v-if="confirmingPinDelivery"
      :title="t('pin.delivery.heading')"
      :confirm-label="t('pin.delivery.action')"
      :busy="pinBusy"
      :error="pinError"
      @cancel="confirmingPinDelivery = false"
      @confirm="confirmPinDelivery"
    >
      <p class="mb-4">{{ t('pin.delivery.explanation', { name: fullName }) }}</p>
      <ChangePreview :changes="pinDeliveryChanges" :caption="t('pin.delivery.heading')" />
      <p class="mt-4 text-sm text-kq-text-muted">{{ t('pin.delivery.auditNotice') }}</p>
    </ConfirmDialog>

    <ConfirmDialog
      v-if="offboarding"
      :title="t('employees.offboard.heading')"
      :confirm-label="t('employees.offboard.confirmAction')"
      tone="danger"
      size="wide"
      :busy="offboardBusy"
      :error="offboardError"
      :confirm-disabled="terminatedAt === '' || offboardReason === ''"
      @cancel="offboarding = false"
      @confirm="confirmOffboard"
    >
      <div class="grid gap-4 sm:grid-cols-2">
        <FormField
          v-slot="field"
          :label="t('employees.fields.terminatedAt')"
          :hint="t('employees.offboard.dateHint')"
          required
        >
          <input
            :id="field.id"
            v-model="terminatedAt"
            type="date"
            required
            :class="inputClass"
            :aria-describedby="field.describedBy"
          />
        </FormField>

        <FormField
          v-slot="field"
          :label="t('employees.offboard.reasonLabel')"
          :hint="t('employees.offboard.reasonHint')"
          required
        >
          <select
            :id="field.id"
            v-model="offboardReasonKey"
            :class="inputClass"
            :aria-describedby="field.describedBy"
          >
            <option value="">{{ t('employees.offboard.reasonPlaceholder') }}</option>
            <option v-for="key of OFFBOARD_REASONS" :key="key" :value="key">
              {{ t(`employees.offboard.reasons.${key}`) }}
            </option>
          </select>
        </FormField>

        <FormField
          v-if="offboardReasonKey === 'other'"
          v-slot="field"
          class="sm:col-span-2"
          :label="t('employees.offboard.reasonOtherLabel')"
          required
        >
          <input
            :id="field.id"
            v-model="offboardReasonText"
            type="text"
            maxlength="200"
            :class="inputClass"
          />
        </FormField>
      </div>

      <ChangePreview
        class="mt-4"
        :changes="offboardChanges"
        :caption="t('employees.offboard.heading')"
      />

      <ul class="mt-4 list-disc pl-5 text-kq-text-muted">
        <li>{{ t('employees.offboard.consequenceCredential') }}</li>
        <li>{{ t('employees.offboard.consequenceScan') }}</li>
        <li>{{ t('employees.offboard.consequenceHistory') }}</li>
      </ul>
    </ConfirmDialog>

    <PinRevealDialog
      v-if="revealedPin !== null && employee !== undefined"
      :pin="revealedPin"
      :employee-name="fullName"
      :employee-code="employee.employee_code"
      @acknowledged="closePinDialog"
      @delivered="onPinDeliveredFromDialog"
    />
  </section>
</template>
