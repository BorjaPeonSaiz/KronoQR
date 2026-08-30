<script setup lang="ts">
// Cerrar una incidencia, con nota obligatoria (RF-PA-05, RN-13).
//
// LO QUE SE VA A CAMBIAR, DESDE QUE VALOR, HACIA CUAL: el cuerpo del dialogo
// dice de que incidencia se trata (tipo, persona, jornada) y en que consiste
// cada desenlace, antes de pedir la nota que lo explica.
//
// ESTO NO TOCA EL REGISTRO HORARIO. Resolver una incidencia no cierra ningun
// turno ni mueve ninguna marca (RN-08): si hace falta rectificar, se hace
// aparte con `attendance:correct`. El texto del dialogo lo dice porque es
// facil confundir «he mirado la incidencia» con «he corregido el tramo».
//
// SE RESUELVE UNA SOLA VEZ. Un `409` significa que alguien se adelanto: el
// dialogo no ofrece reintentar, solo enseña quien fue (si se pudo averiguar) y
// se cierra.
import ErrorNotice from '@kronoqr/web-kit/components/ErrorNotice.vue'
import { formatInstant } from '@kronoqr/web-kit/datetime'
import { isApiError } from '@kronoqr/web-kit/http'
import { computed, ref, useId } from 'vue'
import { useI18n } from 'vue-i18n'
import BaseDialog from '@/shared/ui/BaseDialog.vue'
import { describeIncidentContext } from './incidentContext'
import { useIncidentsStore } from './incidents.store'
import type { Incident, IncidentOutcome } from '@/shared/api/types'

const props = defineProps<{ incident: Incident; timeZone: string }>()

const emit = defineEmits<{ resolved: [incident: Incident]; cancel: [] }>()

const { t, locale } = useI18n()
const store = useIncidentsStore()

const NOTE_MIN = 3
const NOTE_MAX = 1_000

const outcome = ref<IncidentOutcome>('resolved')
const note = ref('')
const submitting = ref(false)
const error = ref<unknown>(null)
/** Distinto de `error`: un 409 no es un fallo que se corrija reenviando el formulario. */
const conflictClosed = ref(false)

const noteId = useId()
const counterId = useId()

const contextLines = computed(() => describeIncidentContext(props.incident.context, t))

const noteLength = computed(() => note.value.trim().length)
const noteTooShort = computed(() => noteLength.value > 0 && noteLength.value < NOTE_MIN)
const noteTooLong = computed(() => note.value.length > NOTE_MAX)

const localErrors = computed<string[]>(() => {
  const messages: string[] = []

  if (noteTooShort.value) {
    messages.push(t('incidents.dialog.noteTooShort', { min: NOTE_MIN }))
  }

  if (noteTooLong.value) {
    messages.push(t('incidents.dialog.noteTooLong', { max: NOTE_MAX }))
  }

  return messages
})

const canSubmit = computed(
  () => noteLength.value >= NOTE_MIN && note.value.length <= NOTE_MAX && !submitting.value,
)

const conflictIncident = computed(() => store.conflict?.incident ?? null)

/**
 * Nulo solo cuando no se pudo averiguar quien fue (busqueda sin resultado, o
 * la respuesta del servidor sin `resolved_by` a pesar de estar cerrada, que no
 * deberia pasar pero no es motivo para que el dialogo se rompa).
 */
const conflictResolvedByName = computed(() => conflictIncident.value?.resolved_by?.name ?? null)

function close(): void {
  emit('cancel')
}

async function submit(): Promise<void> {
  if (!canSubmit.value) {
    return
  }

  submitting.value = true
  error.value = null

  try {
    const resolved = await store.resolve(props.incident.id, {
      outcome: outcome.value,
      note: note.value.trim(),
    })

    emit('resolved', resolved)
  } catch (caught) {
    if (isApiError(caught) && caught.status === 409) {
      conflictClosed.value = true
    } else {
      error.value = caught
    }
  } finally {
    submitting.value = false
  }
}

function conflictMoment(): string {
  const resolvedAt = conflictIncident.value?.resolved_at

  return resolvedAt === null || resolvedAt === undefined
    ? ''
    : formatInstant(resolvedAt, props.timeZone, locale.value)
}
</script>

<template>
  <BaseDialog :title="t('incidents.dialog.title')" @close="close">
    <p class="text-kq-text-muted">
      {{
        t('incidents.dialog.for', {
          type: t(`incidents.types.${incident.type}`),
          name: incident.employee.full_name,
          date: incident.work_date,
        })
      }}
    </p>

    <ul v-if="contextLines.length > 0" class="mt-3 list-disc pl-5 text-kq-text">
      <li v-for="line of contextLines" :key="line.key">{{ line.text }}</li>
    </ul>

    <template v-if="conflictClosed">
      <div role="alert" class="mt-4 rounded-kq border border-kq-warning bg-kq-warning-soft p-4">
        <p class="font-semibold text-kq-warning">{{ t('incidents.dialog.conflict.title') }}</p>
        <p class="mt-1 text-kq-text">
          <template v-if="conflictResolvedByName !== null && conflictIncident !== null">
            {{
              t('incidents.dialog.conflict.resolvedBy', {
                name: conflictResolvedByName,
                outcome: t(`incidents.outcomes.${conflictIncident.status}`),
                moment: conflictMoment(),
              })
            }}
          </template>
          <template v-else>{{ t('incidents.dialog.conflict.resolvedByUnknown') }}</template>
        </p>
        <p
          v-if="
            conflictIncident?.resolution_note !== null &&
            conflictIncident?.resolution_note !== undefined
          "
          class="mt-1 text-kq-text-muted"
          data-test="conflict-note"
        >
          {{ t('incidents.dialog.conflict.note', { note: conflictIncident.resolution_note }) }}
        </p>
      </div>
    </template>

    <form v-else class="mt-4 flex flex-col gap-4" novalidate @submit.prevent="submit">
      <fieldset class="border-0 p-0">
        <legend class="font-medium">{{ t('incidents.dialog.outcomeLegend') }}</legend>
        <div class="mt-2 flex flex-col gap-2">
          <label class="flex items-center gap-2">
            <input v-model="outcome" type="radio" name="incident-outcome" value="resolved" />
            {{ t('incidents.dialog.outcome.resolved') }}
          </label>
          <label class="flex items-center gap-2">
            <input v-model="outcome" type="radio" name="incident-outcome" value="dismissed" />
            {{ t('incidents.dialog.outcome.dismissed') }}
          </label>
        </div>
      </fieldset>

      <div class="flex flex-col gap-1">
        <label :for="noteId" class="font-medium">{{ t('incidents.dialog.note') }}</label>
        <p :id="`${noteId}-hint`" class="text-sm text-kq-text-muted">
          {{ t('incidents.dialog.noteHint') }}
        </p>
        <textarea
          :id="noteId"
          v-model="note"
          rows="4"
          :maxlength="NOTE_MAX"
          :aria-describedby="`${noteId}-hint ${counterId}`"
          :aria-invalid="localErrors.length > 0"
          class="rounded-kq-sm border border-kq-border-strong bg-kq-surface-raised px-3 py-2 text-kq-text"
        />
        <p :id="counterId" class="text-sm text-kq-text-muted">
          {{ t('incidents.dialog.noteCounter', { count: note.length, max: NOTE_MAX }) }}
        </p>
        <ul v-if="localErrors.length > 0" class="text-sm text-kq-danger">
          <li v-for="message of localErrors" :key="message">{{ message }}</li>
        </ul>
      </div>

      <ErrorNotice v-if="error !== null" :error="error" />
    </form>

    <template #actions>
      <button
        type="button"
        class="rounded-kq-sm border border-kq-border-strong bg-kq-surface-raised px-4 py-2 text-kq-text hover:bg-kq-surface-alt"
        @click="close"
      >
        {{ conflictClosed ? t('incidents.dialog.conflict.acknowledge') : t('common.cancel') }}
      </button>
      <button
        v-if="!conflictClosed"
        type="button"
        :disabled="!canSubmit"
        :aria-busy="submitting"
        class="rounded-kq-sm bg-kq-primary-strong px-4 py-2 font-semibold text-kq-on-primary disabled:opacity-60"
        @click="submit"
      >
        {{ submitting ? t('common.saving') : t('incidents.dialog.submit') }}
      </button>
    </template>
  </BaseDialog>
</template>
