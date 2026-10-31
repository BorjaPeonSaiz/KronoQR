<script setup lang="ts">
// Readmision de VARIAS personas a la vez, con una fecha de alta comun
// (RN-27, ADR-062 §6): la vuelta de la temporada.
//
// TODO O NADA. Si alguna no se puede readmitir —no esta de baja, su ficha esta
// minimizada o la fecha no es posterior a su cese— el servidor responde `422` con
// un error POR POSICION (`employee_uuids.N`) y no escribe nada. Aqui se traduce
// cada posicion al nombre de la persona y se dice que hacer: quitarla de la
// seleccion o cambiar la fecha, y volver a confirmar. Nadie queda a medias.
//
// Cada persona conserva su departamento, su idioma y un contrato con los terminos
// del ultimo; los cambios individuales se hacen con la readmision de una en una.
// Con «PIN nuevo» cada una recibe uno, que se ve UNA vez al terminar.
import FormField from '@kronoqr/web-kit/components/FormField.vue'
import { formatCivilDate, todayInZone } from '@kronoqr/web-kit/datetime'
import { isApiError } from '@kronoqr/web-kit/http'
import { useQueryClient } from '@tanstack/vue-query'
import { computed, nextTick, onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { usePersonName } from '@/shared/people/personName'
import type { Employee, RehiredEmployee } from '@/shared/api/types'
import type { Change } from '@/shared/ui/change'
import ChangePreview from '@/shared/ui/ChangePreview.vue'
import ConfirmDialog from '@/shared/ui/ConfirmDialog.vue'
import { revealElement } from '@/shared/ui/revealElement'
import DateWarnings from './DateWarnings.vue'
import { addCivilDays } from './employmentDates'
import { getEmployee, rehireEmployees } from './employees.api'
import { isFutureHire, isRecentTermination } from './rehirePlan'

const props = defineProps<{
  /** Las personas elegidas, en el orden de la peticion: la posicion del error es la de esta lista. */
  people: readonly Employee[]
  timezone: string | undefined
}>()

const emit = defineEmits<{
  close: []
  rehired: [RehiredEmployee[]]
}>()

const { t, locale } = useI18n()
const { personName } = usePersonName()

const today = props.timezone === undefined ? '' : todayInZone(props.timezone)

const hiredAt = ref(today)
const resetPin = ref(false)
const busy = ref(false)
const error = ref<unknown>(null)
const dateInput = ref<HTMLInputElement | null>(null)
const errorsBox = ref<HTMLElement | null>(null)

function date(value: string | null): string {
  return value === null || value === '' ? t('common.empty') : formatCivilDate(value, locale.value)
}

function nameOf(person: Employee): string {
  return personName(person.first_name, person.last_name, person.anonymized_at)
}

/** El cese mas tardio de la seleccion: la fecha de alta comun tiene que ser posterior a todos. */
const latestTermination = computed(() =>
  props.people.reduce<string>(
    (latest, person) =>
      person.terminated_at !== null && person.terminated_at > latest
        ? person.terminated_at
        : latest,
    '',
  ),
)

const minHiredAt = computed(() =>
  latestTermination.value === '' ? undefined : addCivilDays(latestTermination.value, 1),
)

/** Personas para las que la fecha elegida no es posterior a su cese (lo mismo que dira el servidor). */
const tooEarly = computed(() =>
  hiredAt.value === ''
    ? []
    : props.people.flatMap((person, index) =>
        person.terminated_at !== null && hiredAt.value <= person.terminated_at
          ? [
              {
                index,
                message: t('employees.rehireBatch.notAfterTermination', {
                  date: date(person.terminated_at),
                }),
              },
            ]
          : [],
      ),
)

/** Errores por posicion que devolvio el servidor: `errors['employee_uuids.3']`. */
const serverPositions = computed(() => {
  if (!isApiError(error.value) || error.value.status !== 422) {
    return []
  }

  return Object.entries(error.value.fieldErrors).flatMap(([field, messages]) => {
    const match = /^employee_uuids\.(\d+)$/.exec(field)

    return match === null ? [] : [{ index: Number(match[1]), message: messages.join(' ') }]
  })
})

interface PositionError {
  index: number
  name: string
  message: string
}

/** Cada error con el nombre de la persona de esa posicion. Una posicion fuera de lista se rotula tal cual. */
const positionErrors = computed<PositionError[]>(() => {
  const merged = new Map<number, string[]>()

  for (const item of [...tooEarly.value, ...serverPositions.value]) {
    merged.set(item.index, [...(merged.get(item.index) ?? []), item.message])
  }

  return [...merged.entries()]
    .sort(([a], [b]) => a - b)
    .map(([index, messages]) => {
      const person = props.people[index]

      return {
        index,
        name:
          person === undefined
            ? t('employees.rehireBatch.position', { n: index + 1 })
            : nameOf(person),
        message: [...new Set(messages)].join(' '),
      }
    })
})

const dateErrors = computed<readonly string[]>(() => {
  const own = isApiError(error.value) ? (error.value.fieldErrors['hired_at'] ?? []) : []

  return own
})

// Cuando se hizo efectiva la baja de cada una. La lista no trae los periodos: se pide el detalle
// de las primeras `MAX_DETAIL_LOOKUPS` (de cinco en cinco) y el resto se aproxima por el cese,
// que peca de aviso de mas. Pedir 500 fichas para un aviso no compensa.
const MAX_DETAIL_LOOKUPS = 50
const LOOKUP_CONCURRENCY = 5
const queryClient = useQueryClient()
const offboardedAtByUuid = ref(new Map<string, string | null>())

onMounted(async () => {
  const pending = props.people
    .filter((person) => person.employment_periods === undefined)
    .slice(0, MAX_DETAIL_LOOKUPS)

  for (let index = 0; index < pending.length; index += LOOKUP_CONCURRENCY) {
    await Promise.all(
      pending.slice(index, index + LOOKUP_CONCURRENCY).map(async (person) => {
        try {
          const detail = await queryClient.fetchQuery({
            queryKey: ['employee', person.uuid] as const,
            queryFn: () => getEmployee(person.uuid),
            staleTime: 60_000,
          })
          const next = new Map(offboardedAtByUuid.value)

          next.set(person.uuid, detail.employment_periods?.at(-1)?.offboarded_at ?? null)
          offboardedAtByUuid.value = next
        } catch {
          // Sin detalle, el aviso se aproxima por el cese.
        }
      }),
    )
  }
})

function offboardedAtOf(person: Employee): string | null | undefined {
  return person.employment_periods !== undefined
    ? person.employment_periods.at(-1)?.offboarded_at
    : offboardedAtByUuid.value.get(person.uuid)
}

const recentCount = computed(
  () =>
    props.people.filter((person) =>
      isRecentTermination({
        offboardedAt: offboardedAtOf(person),
        terminatedAt: person.terminated_at,
        today,
        timezone: props.timezone,
      }),
    ).length,
)

const futureHire = computed(() => isFutureHire(hiredAt.value, today))

// Mientras alguien de la seleccion no admite la fecha, manda la lista de quien lo impide.
const showRecentWarning = computed(() => recentCount.value > 0 && tooEarly.value.length === 0)

const confirmDisabled = computed(
  () => hiredAt.value === '' || props.people.length === 0 || tooEarly.value.length > 0,
)

/** El `422` por posicion y por `hired_at` se pinta en la lista y el campo; el resto, en el aviso. */
const visibleError = computed<unknown>(() =>
  isApiError(error.value) &&
  error.value.status === 422 &&
  (serverPositions.value.length > 0 || dateErrors.value.length > 0)
    ? null
    : error.value,
)

const changes = computed<Change[]>(() => [
  {
    label: t('employees.fields.status'),
    from: t('employees.status.terminated'),
    to: t('employees.status.active'),
  },
  {
    label: t('employees.fields.hiredAt'),
    from: t('employees.rehireBatch.previousDates'),
    to: date(hiredAt.value),
  },
  {
    label: t('credentials.table.status'),
    from: t('credentials.status.revoked'),
    to: t('credentials.status.pending_print'),
  },
  {
    label: t('pin.field'),
    from: t('employees.rehireBatch.pinBefore'),
    to: resetPin.value ? t('employees.rehire.pin.new') : t('employees.rehire.pin.kept'),
  },
])

async function confirm(): Promise<void> {
  busy.value = true
  error.value = null

  try {
    const { rehired } = await rehireEmployees({
      employee_uuids: props.people.map((person) => person.uuid),
      hired_at: hiredAt.value,
      reset_pin: resetPin.value,
    })

    emit('rehired', rehired)
  } catch (caught) {
    error.value = caught

    if (isApiError(caught) && caught.status === 422 && dateErrors.value.length > 0) {
      dateInput.value?.focus()
    } else if (serverPositions.value.length > 0) {
      // La lista de quien lo impide esta en mitad del dialogo y quien confirma esta al final:
      // se trae a la vista (el lector de pantalla ya la lee, es una alerta).
      await nextTick()
      revealElement(errorsBox.value)
    }
  } finally {
    busy.value = false
  }
}

const inputClass =
  'rounded-kq-sm border border-kq-border-strong bg-kq-surface-raised px-3 py-2 text-kq-text'
</script>

<template>
  <ConfirmDialog
    :title="t('employees.rehireBatch.heading')"
    :confirm-label="t('employees.rehireBatch.confirmAction', people.length)"
    size="wide"
    :busy="busy"
    :error="visibleError"
    :confirm-disabled="confirmDisabled"
    @cancel="emit('close')"
    @confirm="confirm"
  >
    <p class="mb-4 max-w-prose">{{ t('employees.rehireBatch.explanation') }}</p>

    <details class="mb-4" data-test="rehire-batch-people">
      <summary class="cursor-pointer font-medium">
        {{ t('employees.rehireBatch.peopleSummary', people.length) }}
      </summary>
      <div
        tabindex="0"
        role="region"
        :aria-label="t('employees.rehireBatch.peopleSummary', people.length)"
        class="mt-2 max-h-48 overflow-y-auto"
      >
        <ul class="list-disc pl-5">
          <li v-for="person of people" :key="person.uuid">
            {{ nameOf(person) }}
            <span class="text-kq-text-muted">
              {{ t('employees.rehireBatch.terminatedOn', { date: date(person.terminated_at) }) }}
            </span>
          </li>
        </ul>
      </div>
    </details>

    <div class="grid gap-4">
      <FormField
        v-slot="field"
        :label="t('employees.rehire.hiredAtLabel')"
        :hint="
          minHiredAt === undefined
            ? ''
            : t('employees.rehireBatch.hiredAtHint', { date: date(latestTermination) })
        "
        :errors="dateErrors"
        required
      >
        <input
          :id="field.id"
          ref="dateInput"
          v-model="hiredAt"
          type="date"
          required
          :class="inputClass"
          :aria-invalid="field.invalid ? 'true' : undefined"
          :aria-describedby="field.describedBy"
        />
      </FormField>

      <DateWarnings :visible="futureHire || showRecentWarning">
        <p v-if="futureHire" data-test="rehire-future-warning">
          {{ t('employees.rehire.futureHire', { date: date(hiredAt) }) }}
        </p>
        <p v-if="showRecentWarning" data-test="rehire-recent-warning">
          {{ t('employees.rehireBatch.recentTermination', recentCount) }}
        </p>
      </DateWarnings>

      <label class="flex items-start gap-2">
        <input v-model="resetPin" type="checkbox" class="mt-1" data-test="rehire-reset-pin" />
        <span>
          <span class="font-medium">{{ t('employees.rehireBatch.pinResetLabel') }}</span>
          <span class="block text-sm text-kq-text-muted">
            {{ t('employees.rehireBatch.pinResetHint') }}
          </span>
        </span>
      </label>
    </div>

    <!-- Todo o nada: la lista de quien lo impide, por posicion y con su nombre. -->
    <div
      v-if="positionErrors.length > 0"
      ref="errorsBox"
      role="alert"
      data-test="rehire-batch-errors"
      class="mt-4 rounded-kq border border-kq-danger bg-kq-danger-soft p-4 text-kq-danger"
    >
      <p class="font-semibold">{{ t('employees.rehireBatch.errorsTitle') }}</p>
      <ul class="mt-2 list-disc pl-5">
        <li v-for="item of positionErrors" :key="item.index" data-test="rehire-batch-error">
          <span class="font-medium">{{ item.name }}:</span> {{ item.message }}
        </li>
      </ul>
      <p class="mt-2">{{ t('employees.rehireBatch.errorsAdvice') }}</p>
    </div>

    <ChangePreview class="mt-4" :changes="changes" :caption="t('employees.rehireBatch.heading')" />

    <ul class="mt-4 list-disc pl-5 text-kq-text-muted">
      <li>{{ t('employees.rehireBatch.allOrNothing') }}</li>
      <li>{{ t('employees.rehire.consequenceCard') }}</li>
      <li>{{ t('employees.rehire.consequencePortal') }}</li>
    </ul>
  </ConfirmDialog>
</template>
