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
import { isApiError } from '@kronoqr/web-kit/http'
import { computed, nextTick, reactive, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { usePersonName } from '@/shared/people/personName'
import { useOfferedLocales } from '@/shared/i18n/useOfferedLocales'
import { RouterLink } from 'vue-router'
import { useQuery, useQueryClient } from '@tanstack/vue-query'
import { ATTENDANCE_READ } from '@/features/auth/abilities'
import { useSessionStore } from '@/features/auth/session.store'
import CredentialRowActions from '@/features/credentials/CredentialRowActions.vue'
import { STATUS_PILL_CLASS as CREDENTIAL_STATUS_PILL_CLASS } from '@/features/credentials/credentialStatusPill'
import { fetchCredentialStatusFor } from '@/features/credentials/credentials.api'
import { getSite, listDepartments } from '@/shared/api/organisation.api'
import type {
  Employee,
  IssuedPin,
  RehiredEmployee,
  UpdateEmployeeRequest,
} from '@/shared/api/types'
import type { Change } from '@/shared/ui/change'
import ChangePreview from '@/shared/ui/ChangePreview.vue'
import ConfirmDialog from '@/shared/ui/ConfirmDialog.vue'
import { revealElement } from '@/shared/ui/revealElement'
import CancelScheduledOffboardingDialog from './CancelScheduledOffboardingDialog.vue'
import EmployeeContractsSection from './EmployeeContractsSection.vue'
import EmploymentPeriodsSection from './EmploymentPeriodsSection.vue'
import OffboardDialog from './OffboardDialog.vue'
import { type EmploymentProblemKey } from './employmentProblems'
import PinRevealDialog from './PinRevealDialog.vue'
import RehireDialog from './RehireDialog.vue'
import RehireOutcome from './RehireOutcome.vue'
import TeleworkingBadge from './TeleworkingBadge.vue'
import TeleworkingCheckbox from './TeleworkingCheckbox.vue'
import { deliverEmployeePin, getEmployee, resetEmployeePin, updateEmployee } from './employees.api'

const props = defineProps<{ uuid: string }>()

const { t, locale } = useI18n()
const { personName, isMinimised } = usePersonName()
const { locales: offered, multilingual } = useOfferedLocales()
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
  refetch,
} = useQuery({
  queryKey: computed(() => ['employee', props.uuid] as const),
  queryFn: () => getEmployee(props.uuid),
})

// El centro de la instalacion (ADR-040): de el sale la zona horaria con la que
// se presentan los instantes de esta ficha.
const { data: site } = useQuery({ queryKey: ['site'], queryFn: getSite })
const { data: departments } = useQuery({
  queryKey: ['departments', 'all'],
  queryFn: () => listDepartments(),
})

const timezone = computed(() => site.value?.timezone ?? 'UTC')

// --- Tarjeta QR (RF-QR-04, RF-QR-06, RF-QR-08) --------------------------------
//
// La misma fila que veria RRHH en el tablero de credenciales, pero de esta
// persona sola: se pide con `employee_uuid` en vez de traer el
// tablero entero y filtrar en cliente. Leer el tablero audita como divulgado
// TODO lo que devuelve; leer la ficha de una persona, como esta, no deja
// asiento (ADR-037, condicion 3): se divulga lo minimo y se audita lo justo.

// El servidor solo devuelve fila para empleados de alta (activos): de baja o
// en suspension, `data` viene vacio. Pedirla igualmente dejaria a la ficha
// esperando para siempre una fila que nunca llega, asi que aqui no se pide
// —la peticion queda deshabilitada— y la plantilla explica por que, con un
// enlace al tablero de credenciales, que es donde de verdad se gestiona la
// tarjeta de esa persona.
const employeeIsActive = computed(() => employee.value?.status === 'active')

const {
  data: credentialRow,
  error: credentialError,
  isPending: credentialPending,
} = useQuery({
  queryKey: computed(() => ['credential-board', 'employee', props.uuid] as const),
  queryFn: () => {
    const current = employee.value

    if (current === undefined) {
      return Promise.resolve(null)
    }

    return fetchCredentialStatusFor(props.uuid)
  },
  enabled: employeeIsActive,
})

function instantInSiteZone(value: string | null): string {
  return value === null
    ? t('common.empty')
    : formatInstantWithZone(value, timezone.value, locale.value)
}

async function refreshCredential(): Promise<void> {
  await queryClient.invalidateQueries({ queryKey: ['credential-board'] })
}

function teleworkingText(value: boolean): string {
  return t(value ? 'employees.teleworking.yes' : 'employees.teleworking.no')
}

function departmentName(id: number | null): string {
  if (id === null) {
    return t('employees.fields.departmentNone')
  }

  return (departments.value?.data ?? []).find((item) => item.id === id)?.name ?? '—'
}

const fullName = computed(() =>
  employee.value === undefined
    ? ''
    : personName(employee.value.first_name, employee.value.last_name, employee.value.anonymized_at),
)

/** Ficha minimizada por retencion (ADR-060): sin nombre, correo, PIN ni contratos que gestionar. */
const minimised = computed(
  () =>
    employee.value !== undefined &&
    isMinimised(employee.value.first_name, employee.value.last_name, employee.value.anonymized_at),
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
  departmentId: null as number | null,
  status: 'active' as 'active' | 'suspended',
  locale: 'es',
  teleworking: false,
})

function loadForm(source: Employee): void {
  form.firstName = source.first_name
  form.lastName = source.last_name
  form.email = source.email ?? ''
  form.departmentId = source.department_id
  form.status = source.status === 'suspended' ? 'suspended' : 'active'
  form.locale = source.locale
  form.teleworking = source.teleworking
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

  if (form.teleworking !== current.teleworking) {
    body.teleworking = form.teleworking
    changes.push({
      label: t('employees.fields.teleworking'),
      from: teleworkingText(current.teleworking),
      to: teleworkingText(form.teleworking),
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
    if (isTerminatedConflict(caught)) {
      // Una ficha dada de baja no se modifica (ADR-046): la baja entro primero.
      // Se cierra la edicion, se dice por que y se recarga para ver el estado
      // real. Cualquier OTRO 409 (correo o documento duplicado, escritura
      // concurrente) conserva lo escrito y se pinta con su detalle.
      confirmingEdit.value = false
      editing.value = false
      await reportConflict()
    } else {
      saveError.value = caught
    }
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

// Sin PIN (`pending`) no hay nada que restablecer: la misma accion `pin/reset` se
// presenta como emision, con su propio rotulo y texto.
const pinIssuing = computed(() => employee.value?.pin_status === 'pending')
const pinResetKey = computed(() => (pinIssuing.value ? 'pin.issue' : 'pin.reset'))

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

// Otra persona de RRHH pudo emitir el PIN desde que se cargo la ficha: se relee antes
// de abrir el dialogo para que rotulo y advertencia reflejen el estado real. Si la
// relectura falla, se mantiene el estado ya cargado.
async function openPinReset(): Promise<void> {
  try {
    await queryClient.fetchQuery({
      queryKey: ['employee', props.uuid] as const,
      queryFn: () => getEmployee(props.uuid),
      staleTime: 0,
    })
  } catch {
    // Se sigue con el estado cargado.
  }

  confirmingPinReset.value = true
}

async function confirmPinReset(): Promise<void> {
  // El estado previo se fija antes de actuar: tras refrescar la ficha ya sera `issued`.
  const wasIssuing = pinIssuing.value

  pinBusy.value = true
  pinError.value = null

  try {
    revealedPin.value = await resetEmployeePin(props.uuid)
    confirmingPinReset.value = false
    await invalidate()
    announce(t(wasIssuing ? 'pin.announce.issued' : 'pin.announce.reset'))
  } catch (caught) {
    if (isTerminatedConflict(caught)) {
      confirmingPinReset.value = false
      await reportConflict()
    } else {
      pinError.value = caught
    }
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
    if (isTerminatedConflict(caught)) {
      confirmingPinDelivery.value = false
      await reportConflict()
    } else {
      pinError.value = caught
    }
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

// --- Conflicto (409) ---------------------------------------------------------

/** `type` del 409 «la persona ya esta de baja» (ADR-046); el resto de 409 llevan `conflict`. */
const EMPLOYEE_TERMINATED_TYPE = 'urn:kronoqr:problem:employee-terminated'

function isTerminatedConflict(caught: unknown): boolean {
  return (
    isApiError(caught) && caught.status === 409 && caught.problem?.type === EMPLOYEE_TERMINATED_TYPE
  )
}

/**
 * La causa del aviso de conflicto en pantalla, o `null` si no hay. Casi siempre es «ya esta
 * de baja» (ADR-046); la baja programada y la readmision añaden las suyas (ADR-062).
 */
const conflictKey = ref<EmploymentProblemKey | null>(null)
const conflictBox = ref<HTMLElement | null>(null)

/** El aviso es un `role="alert"`: el lector de pantalla lo lee solo, sin `announce`. */
async function reportConflict(key: EmploymentProblemKey = 'employeeTerminated'): Promise<void> {
  conflictKey.value = key
  // El aviso esta arriba y quien actuo, abajo (la baja es el ultimo apartado): se trae a la vista.
  await nextTick()
  revealElement(conflictBox.value)
  await invalidate()
}

// --- Baja, baja programada y readmision --------------------------------------
//
// Los dialogos viven en sus componentes (`OffboardDialog`, `RehireDialog`...); aqui
// solo se decide cual esta abierto y que se hace al terminar. Todos acaban igual:
// se relee la ficha (la verdad es la del servidor) y se anuncia el resultado.

const offboarding = ref(false)
const cancellingOffboarding = ref(false)
const rehiring = ref(false)

// Abrir una accion nueva descarta el aviso de un conflicto anterior: ya no habla de lo que se hace.
watch([offboarding, cancellingOffboarding, rehiring], ([a, b, c]) => {
  if (a || b || c) {
    conflictKey.value = null
  }
})

/** El resultado de la readmision, con el PIN en claro si se pidio. Solo vive mientras se ensena. */
const rehireResult = ref<RehiredEmployee | null>(null)

/** Baja efectiva (`terminated`) o baja solo programada (`active` con fecha de cese). */
const isTerminated = computed(() => employee.value?.status === 'terminated')
const isScheduled = computed(() => employee.value?.offboarding?.state === 'scheduled')

/**
 * Baja programada cuya fecha de cese ya paso (hoy en la zona del centro) y que la tarea del
 * sistema aun no ha aplicado: rige igualmente, la fecha ya no cambia y anularla responde 409
 * (`offboarding-due`). Solo queda aplicarla, y el boton de anular no se ofrece.
 */
const scheduledOverdue = computed(
  () =>
    isScheduled.value &&
    site.value !== undefined &&
    employee.value?.terminated_at != null &&
    employee.value.terminated_at < todayInZone(site.value.timezone),
)

/** Periodos de alta de la persona, del mas antiguo al mas reciente (solo en la ficha). */
const periods = computed(() => employee.value?.employment_periods ?? [])

async function onOffboardDone(updated: Employee): Promise<void> {
  offboarding.value = false
  await invalidate()
  announce(
    t(
      updated.offboarding?.state === 'scheduled'
        ? 'employees.announce.offboardScheduled'
        : 'employees.announce.offboarded',
      { date: formatCivilDate(updated.terminated_at ?? '', locale.value) },
    ),
  )
}

async function onCancelOffboardingDone(): Promise<void> {
  cancellingOffboarding.value = false
  await invalidate()
  announce(t('employees.announce.offboardCancelled'))
}

async function onRehired(result: RehiredEmployee): Promise<void> {
  rehiring.value = false
  rehireResult.value = result
  await invalidate()
  announce(t('employees.announce.rehired'))
}

function closeRehireResult(): void {
  // Al soltar la referencia, el PIN en claro deja de existir en el navegador.
  rehireResult.value = null
}

/** La ficha ya no esta como se veia (otra persona actuo antes): se cierra el dialogo y se relee. */
async function onStale(key: EmploymentProblemKey): Promise<void> {
  offboarding.value = false
  cancellingOffboarding.value = false
  rehiring.value = false
  await reportConflict(key)
}

async function invalidate(): Promise<void> {
  await queryClient.invalidateQueries({ queryKey: ['employee', props.uuid] })
  await queryClient.invalidateQueries({ queryKey: ['employees'] })
}

const inputClass =
  'rounded-kq-sm border border-kq-border-strong bg-kq-surface-raised px-3 py-2 text-kq-text'

/** Una baja programada es una persona en alta con fecha de cese: se pinta como aviso, no como baja. */
const SCHEDULED_PILL_CLASS = 'bg-kq-warning-soft text-kq-warning'

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

    <p
      v-if="conflictKey !== null"
      ref="conflictBox"
      role="alert"
      data-test="employee-conflict"
      class="mt-4 rounded-kq border border-kq-warning bg-kq-warning-soft p-4 text-kq-warning"
    >
      <template v-if="conflictKey === 'employeeTerminated'">
        {{ t('employees.conflict.terminated') }}
      </template>
      <template v-else>
        <span class="font-semibold">{{ t(`employees.problems.${conflictKey}.title`) }}</span>
        {{ t(`employees.problems.${conflictKey}.advice`) }}
      </template>
    </p>

    <!-- El h1 es SIEMPRE el mismo y esta siempre: el cambio de pantalla lleva el foco aqui
         y el titulo de la pestaña dice lo mismo. El nombre es un dato de la persona y va debajo
         (nunca en el titulo ni en el encabezado de la pantalla). -->
    <h1 class="mt-4 text-2xl font-bold">{{ t('app.titles.employee') }}</h1>

    <LoadingPanel v-if="isPending" :label="t('employees.detail.loading')" class="mt-4" />
    <ErrorNotice v-else-if="error !== null" :error="error" class="mt-4" @retry="refetch()" />

    <template v-else-if="employee !== undefined">
      <header class="mt-4 flex flex-wrap items-center justify-between gap-4">
        <div>
          <p class="text-xl font-semibold" data-test="employee-name">{{ fullName }}</p>
          <p class="mt-1 font-mono text-kq-text-muted">{{ employee.employee_code }}</p>
        </div>
        <p
          class="rounded-full px-3 py-1 text-sm"
          :class="isScheduled ? SCHEDULED_PILL_CLASS : STATUS_PILL_CLASS[employee.status]"
          data-test="employee-status"
        >
          {{
            isScheduled ? t('employees.status.scheduled') : t(`employees.status.${employee.status}`)
          }}
        </p>
      </header>

      <p
        v-if="minimised"
        role="status"
        data-test="employee-minimised"
        class="mt-4 rounded-kq border border-kq-border-strong bg-kq-surface-alt p-4 text-kq-text"
      >
        {{ t('employees.detail.minimised') }}
        <span v-if="employee.anonymized_at !== null" data-test="employee-minimised-at">
          {{
            t('employees.detail.minimisedAt', { date: instantInSiteZone(employee.anonymized_at) })
          }}
        </span>
      </p>

      <!-- Datos -->
      <section
        class="mt-6 rounded-kq border border-kq-border bg-kq-surface-raised p-4 shadow-kq-soft"
      >
        <div class="flex flex-wrap items-center justify-between gap-3">
          <h2 class="text-xl font-semibold">{{ t('employees.detail.dataHeading') }}</h2>
          <button
            v-if="!editing && !minimised"
            type="button"
            class="rounded-kq-sm border border-kq-border-strong bg-kq-surface-raised px-3 py-2 text-kq-text hover:bg-kq-surface-alt"
            @click="startEditing"
          >
            {{ t('common.edit') }}
          </button>
        </div>

        <dl v-if="!editing" class="mt-4 grid gap-3 sm:grid-cols-2">
          <div>
            <dt class="font-medium text-kq-text-muted">{{ t('employees.fields.department') }}</dt>
            <dd>{{ departmentName(employee.department_id) }}</dd>
          </div>
          <div>
            <dt class="font-medium text-kq-text-muted">{{ t('employees.fields.email') }}</dt>
            <dd>{{ employee.email ?? t('employees.fields.emailAbsent') }}</dd>
          </div>
          <div v-if="multilingual">
            <dt class="font-medium text-kq-text-muted">{{ t('employees.fields.locale') }}</dt>
            <dd>{{ employee.locale }}</dd>
          </div>
          <div>
            <dt class="font-medium text-kq-text-muted">{{ t('employees.fields.hiredAt') }}</dt>
            <dd>{{ formatCivilDate(employee.hired_at, locale) }}</dd>
          </div>
          <div>
            <dt class="font-medium text-kq-text-muted">{{ t('employees.fields.teleworking') }}</dt>
            <dd><TeleworkingBadge :teleworking="employee.teleworking" /></dd>
          </div>
          <div v-if="employee.terminated_at !== null">
            <dt class="font-medium text-kq-text-muted">
              {{
                isScheduled
                  ? t('employees.fields.terminatedAtScheduled')
                  : t('employees.fields.terminatedAt')
              }}
            </dt>
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
          <FormField v-slot="field" :label="t('employees.fields.department')">
            <select :id="field.id" v-model="form.departmentId" :class="inputClass">
              <option :value="null">{{ t('employees.fields.departmentNone') }}</option>
              <option v-for="item of departments?.data ?? []" :key="item.id" :value="item.id">
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
          <FormField v-if="multilingual" v-slot="field" :label="t('employees.fields.locale')">
            <select :id="field.id" v-model="form.locale" :class="inputClass">
              <option v-for="option in offered" :key="option" :value="option">
                {{ t(`common.locales.${option}`) }}
              </option>
            </select>
          </FormField>

          <TeleworkingCheckbox v-model="form.teleworking" class="sm:col-span-2" />

          <div class="flex gap-3 sm:col-span-2">
            <button
              type="button"
              class="rounded-kq-sm border border-kq-border-strong bg-kq-surface-raised px-4 py-2 text-kq-text hover:bg-kq-surface-alt"
              @click="editing = false"
            >
              {{ t('common.cancel') }}
            </button>
            <button
              v-aria-disabled.reason="pendingUpdate.changes.length === 0"
              type="submit"
              class="rounded-kq-sm bg-kq-primary-strong px-4 py-2 font-semibold text-kq-on-primary aria-disabled:opacity-60"
            >
              {{ t('employees.detail.reviewChanges') }}
            </button>
          </div>
        </form>
      </section>

      <!-- PIN -->
      <section
        v-if="!minimised"
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
            @click="openPinReset"
          >
            {{ pinIssuing ? t('pin.actions.issue') : t('pin.actions.reset') }}
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

      <!-- Contratos (RF-GP-02) -->
      <EmployeeContractsSection
        v-if="!minimised"
        :employee-uuid="employee.uuid"
        :can-register="employee.status !== 'terminated'"
      />

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
        v-if="!minimised"
        class="mt-6 rounded-kq border border-kq-border bg-kq-surface-raised p-4 shadow-kq-soft"
      >
        <h2 class="text-xl font-semibold">{{ t('employees.detail.credentialHeading') }}</h2>

        <EmptyState
          v-if="!employeeIsActive"
          class="mt-4"
          :title="t('employees.detail.credentialInactive.title')"
          :description="t('employees.detail.credentialInactive.description')"
        >
          <RouterLink :to="{ name: 'credentials' }" class="text-kq-primary-strong underline">
            {{ t('employees.detail.credentialInactive.link') }}
          </RouterLink>
        </EmptyState>

        <template v-else>
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
                <dt class="font-medium text-kq-text-muted">
                  {{ t('credentials.table.printedAt') }}
                </dt>
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
              <CredentialRowActions :row="credentialRow" :on-changed="refreshCredential" />
            </div>
          </div>
        </template>
      </section>

      <!-- Baja -->
      <section
        class="mt-6 rounded-kq border border-kq-border bg-kq-surface-raised p-4 shadow-kq-soft"
      >
        <h2 class="text-xl font-semibold">{{ t('employees.offboard.heading') }}</h2>
        <p class="mt-1 max-w-prose text-kq-text-muted">{{ t('employees.offboard.explanation') }}</p>

        <!-- Baja programada: ficha hasta su ultimo dia; se cambia o se anula mientras no pase. -->
        <div v-if="isScheduled" class="mt-3" data-test="offboard-scheduled">
          <p
            role="status"
            class="rounded-kq-sm border border-kq-warning bg-kq-warning-soft p-3 text-kq-warning"
          >
            {{
              t(
                scheduledOverdue
                  ? 'employees.offboard.overdueSummary'
                  : 'employees.offboard.scheduledSummary',
                { date: formatCivilDate(employee.terminated_at ?? '', locale) },
              )
            }}
          </p>
          <div class="mt-3 flex flex-wrap gap-3">
            <button
              type="button"
              data-test="offboard-reschedule"
              class="rounded-kq-sm border border-kq-border-strong bg-kq-surface-raised px-4 py-2 text-kq-text hover:bg-kq-surface-alt"
              @click="offboarding = true"
            >
              {{
                t(
                  scheduledOverdue
                    ? 'employees.offboard.overdueAction'
                    : 'employees.offboard.rescheduleAction',
                )
              }}
            </button>
            <button
              v-if="!scheduledOverdue"
              type="button"
              data-test="offboard-cancel"
              class="rounded-kq-sm border border-kq-border-strong bg-kq-surface-raised px-4 py-2 text-kq-text hover:bg-kq-surface-alt"
              @click="cancellingOffboarding = true"
            >
              {{ t('employees.offboard.cancelAction') }}
            </button>
          </div>
        </div>

        <!-- Baja efectiva: la historia se conserva y se puede volver a dar de alta (RN-27). -->
        <template v-else-if="isTerminated">
          <p class="mt-3">
            {{
              t('employees.offboard.alreadyTerminated', {
                date: formatCivilDate(employee.terminated_at ?? '', locale),
              })
            }}
          </p>
          <div v-if="!minimised" class="mt-3">
            <button
              type="button"
              data-test="rehire-open"
              class="rounded-kq-sm bg-kq-primary-strong px-4 py-2 font-semibold text-kq-on-primary"
              @click="rehiring = true"
            >
              {{ t('employees.rehire.action') }}
            </button>
            <p class="mt-2 max-w-prose text-sm text-kq-text-muted">
              {{ t('employees.rehire.actionHint') }}
            </p>
          </div>
        </template>

        <button
          v-else
          type="button"
          data-test="offboard-open"
          class="mt-3 rounded-kq-sm bg-kq-danger px-4 py-2 font-semibold text-kq-on-danger"
          @click="offboarding = true"
        >
          {{ t('employees.offboard.action') }}
        </button>
      </section>

      <!-- Historia de alta y baja (RN-27) -->
      <EmploymentPeriodsSection
        :employee-uuid="employee.uuid"
        :periods="periods"
        :timezone="site?.timezone"
        :minimised="minimised"
        @corrected="invalidate"
        @stale="onStale"
      />
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
      :title="t(`${pinResetKey}.heading`)"
      :confirm-label="t(`${pinResetKey}.action`)"
      tone="danger"
      :busy="pinBusy"
      :error="pinError"
      @cancel="confirmingPinReset = false"
      @confirm="confirmPinReset"
    >
      <p class="mb-4">{{ t(`${pinResetKey}.explanation`) }}</p>
      <ChangePreview :changes="pinResetChanges" :caption="t(`${pinResetKey}.heading`)" />
      <p class="mt-4 rounded-kq-sm border border-kq-warning bg-kq-warning-soft p-3 text-kq-warning">
        {{ t(`${pinResetKey}.warning`) }}
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

    <OffboardDialog
      v-if="offboarding && employee !== undefined"
      :employee="employee"
      :timezone="site?.timezone"
      @close="offboarding = false"
      @done="onOffboardDone"
      @stale="onStale"
    />

    <CancelScheduledOffboardingDialog
      v-if="cancellingOffboarding && employee !== undefined"
      :employee="employee"
      @close="cancellingOffboarding = false"
      @done="onCancelOffboardingDone"
      @stale="onStale"
    />

    <RehireDialog
      v-if="rehiring && employee !== undefined"
      :employee="employee"
      :timezone="site?.timezone"
      @close="rehiring = false"
      @rehired="onRehired"
      @stale="onStale"
    />

    <RehireOutcome
      v-if="rehireResult !== null"
      :results="[rehireResult]"
      @done="closeRehireResult"
      @pin-delivered="invalidate"
    />

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
