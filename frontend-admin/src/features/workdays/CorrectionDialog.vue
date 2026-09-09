<script setup lang="ts">
// Añadir, corregir o anular un tramo (RF-PA-04, RN-13, ADR-026, ADR-035).
//
// UN SOLO DIALOGO, TRES MODOS, porque las tres operaciones comparten casi
// todo: el catálogo de motivos (Anexo C), el texto libre obligatorio a partir
// de 20 caracteres cuando el motivo es `OTROS`, y la misma regla de fondo —
// «qué se va a cambiar, desde qué valor y hacia cuál», antes de confirmar
// (regla dura 5). Separarlo en tres componentes habría triplicado esa parte
// sin ganar nada.
//
// LO QUE CAMBIA, DESDE QUE VALOR, HACIA CUAL: `changes` alimenta el mismo
// `ChangePreview` que usa `CredentialRowActions`. En un alta, el «antes» es
// que no existía ningún tramo; en una anulación, el «después» es que deja de
// contar. Ninguno de los dos casos se pinta como un hueco vacío.
//
// LAS HORAS SE TECLEAN EN LA ZONA DEL CENTRO, NUNCA EN LA DEL NAVEGADOR
// (regla dura 3). `zonedTime.ts` hace la única conversión que faltaba en el
// panel: de lo que alguien escribe pensando en la hora del centro al
// `UtcTimestamp` que exige el contrato. En el modo «corregir», los campos se
// rellenan con `clocked_in_at_local`/`clocked_out_at_local`, que el servidor
// ya resolvió: no se convierte nada, se lee (mismo criterio que
// `ShiftEntryTable`/`CorrectionHistory`).
//
// UN 409 NO PIERDE LO ESCRITO (segunda vuelta, hallazgo 2 de la revisión): el
// formulario NUNCA se desmonta, ni siquiera cuando el tramo ya no es la
// versión vigente. El aviso se pinta ENCIMA, con los campos tal y como se
// dejaron.
//
// LOS TRES 409 NO SON EL MISMO AVISO (hallazgo 1). `POST /shift-entries` y
// `PATCH` devuelven `409` por tres causas distintas -versión superada
// (RN-13/ADR-035), turno ya abierto (RN-01) y solape (RN-02)-, y solo la
// primera significa "recarga la jornada": las otras dos son un dato mal
// escrito que se corrige sin perder nada ni pedirle a nadie que recargue.
// Se distinguen por el `type` del `Problem` (`ProblemDetails` del contrato);
// el backend (bloque A2, en paralelo a esta tarea) es quien le da a cada
// causa un `type` propio -antes, las tres compartían
// `urn:kronoqr:problem:conflict`, indistinguible desde aquí-.
import ErrorNotice from '@kronoqr/web-kit/components/ErrorNotice.vue'
import FormField from '@kronoqr/web-kit/components/FormField.vue'
import { formatCivilDate, formatInstant, readLocalTimestamp } from '@kronoqr/web-kit/datetime'
import { isApiError, type ApiError } from '@kronoqr/web-kit/http'
import { computed, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import BaseDialog from '@/shared/ui/BaseDialog.vue'
import type { Change } from '@/shared/ui/change'
import ChangePreview from '@/shared/ui/ChangePreview.vue'
import type {
  CorrectedShiftEntry,
  CorrectionReasonCode,
  CorrectShiftEntryRequest,
  WorkDayShiftEntry,
} from '@/shared/api/types'
import { addShiftEntry, correctShiftEntry, voidShiftEntry } from './corrections.api'
import { toLocalInputValue, zonedInputToUtcIso } from './zonedTime'

/** Los `type` de `Problem` que ya distingue el `409` de un tramo (bloque A2, en paralelo). */
const SHIFT_ENTRY_SUPERSEDED_TYPE = 'urn:kronoqr:problem:shift-entry-superseded'
const SHIFT_ALREADY_OPEN_TYPE = 'urn:kronoqr:problem:shift-already-open'
const OVERLAPPING_SHIFT_ENTRY_TYPE = 'urn:kronoqr:problem:overlapping-shift-entry'

/**
 * El `422` de RN-05/ADR-035 (corregir la entrada movería la jornada a otro
 * día). El bloque A2 le va a dar este `type` propio; hasta que llegue en
 * `schema.d.ts`, es el mismo texto literal que ya usa el doble del E2E.
 */
const WORK_DATE_CHANGE_PROBLEM_TYPE = 'urn:kronoqr:problem:correction-would-change-work-date'

type ConflictKind = 'superseded' | 'shiftAlreadyOpen' | 'overlap' | 'generic'

interface Conflict {
  kind: ConflictKind
  message: string
}

/** Los nueve motivos del Anexo C, en el orden en que aparecen en la guía de RRHH. */
const REASON_CODES: readonly CorrectionReasonCode[] = [
  'OLVIDO_FICHAJE_ENTRADA',
  'OLVIDO_FICHAJE_SALIDA',
  'FALLO_TECNICO_QUIOSCO',
  'TARJETA_NO_DISPONIBLE',
  'CREDENCIAL_NO_ENTREGADA',
  'ERROR_DE_ESCANEO_DUPLICADO',
  'AJUSTE_ACORDADO_CON_RRHH',
  'ALTA_RETROACTIVA',
  'OTROS',
]

/** Al menos 20 caracteres cuando el motivo es `OTROS` (Anexo C). */
const OTHER_REASON_MIN_LENGTH = 20

type CorrectionMode = 'add' | 'correct' | 'void'

const props = defineProps<{
  mode: CorrectionMode
  employeeUuid: string
  employeeName: string
  timeZone: string
  /**
   * Obligatorio en 'correct'/'void'. En 'add' no hay jornada todavía: la
   * elige quien rellena el formulario. Tipado `| undefined` y no `?:` porque
   * `exactOptionalPropertyTypes` distingue «no lo paso» de «lo paso a
   * `undefined`», y quien contiene este diálogo hace lo segundo (computed que
   * vale `undefined` en el modo 'add').
   */
  workDate: string | undefined
  /** Obligatorio en 'correct'/'void': el tramo vigente sobre el que se actúa. */
  entry: WorkDayShiftEntry | undefined
}>()

const emit = defineEmits<{
  success: [CorrectedShiftEntry]
  cancel: []
  /** Un 409: el tramo ya no es el vigente. Quien contiene este diálogo debe recargar la jornada. */
  stale: []
}>()

const { t, locale } = useI18n()

/** La clave de `corrections.actions.*`/`corrections.action.*`: 'add' se llama 'create' ahí. */
const actionKey = computed(() => (props.mode === 'add' ? 'create' : props.mode))

function localInputFor(local: string | null): string {
  if (local === null) {
    return ''
  }

  const parts = readLocalTimestamp(local)

  return parts === null ? '' : toLocalInputValue(parts.date, parts.time)
}

const originalClockIn =
  props.mode === 'add' || props.entry === undefined
    ? ''
    : localInputFor(props.entry.clocked_in_at_local)
const originalClockOut =
  props.mode === 'add' || props.entry === undefined
    ? ''
    : localInputFor(props.entry.clocked_out_at_local)

const workDateInput = ref(props.mode === 'add' ? '' : (props.workDate ?? ''))
const clockInInput = ref(originalClockIn)
const clockOutInput = ref(originalClockOut)
const reasonCode = ref<CorrectionReasonCode | ''>('')
const reasonText = ref('')
const submitting = ref(false)
const error = ref<unknown>(null)
/** El `409`: distinto de `error` porque no viene con `fieldErrors` que pintar
 * en un campo, y porque el formulario sigue vivo mientras se muestra (hallazgo 2). */
const conflict = ref<Conflict | null>(null)
/** El `422` de RN-05/ADR-035: se pinta como pista propia del campo, no como
 * el texto crudo del servidor (hallazgo 4). */
const workDateChangeNotice = ref(false)

// Cambiar de motivo desde `OTROS` a otro no debe dejar viajando una
// justificacion escrita para un motivo distinto (hallazgo 6b).
watch(reasonCode, (value) => {
  if (value !== 'OTROS') {
    reasonText.value = ''
  }
})

const reasonTextValue = computed(() => reasonText.value.trim())
const reasonTextRequired = computed(() => reasonCode.value === 'OTROS')
const reasonTextTooShort = computed(
  () => reasonTextRequired.value && reasonTextValue.value.length < OTHER_REASON_MIN_LENGTH,
)
const hasReason = computed(() => reasonCode.value !== '' && !reasonTextTooShort.value)

/**
 * Vaciar un campo que antes tenia valor NO es un cambio (hallazgo 3): no hay
 * forma de retirar una marca ya registrada (regla dura 5, ADR-035), y
 * contarlo como cambio habilitaba el envio y acababa en un `422` colgado de
 * `clocked_in_at` con el campo, sin más, ausente de la respuesta.
 */
const clockInCleared = computed(
  () => props.mode === 'correct' && originalClockIn !== '' && clockInInput.value === '',
)
const clockOutCleared = computed(
  () => props.mode === 'correct' && originalClockOut !== '' && clockOutInput.value === '',
)

const clockInChanged = computed(
  () => clockInInput.value !== originalClockIn && !clockInCleared.value,
)
const clockOutChanged = computed(
  () => clockOutInput.value !== originalClockOut && !clockOutCleared.value,
)

function isNonexistentLocal(value: string): boolean {
  return value !== '' && zonedInputToUtcIso(value, props.timeZone) === null
}

/** La hora tecleada no existio en `timeZone` (el hueco del cambio de horario de primavera, hallazgo 5). */
const clockInNonexistent = computed(() => isNonexistentLocal(clockInInput.value))
const clockOutNonexistent = computed(() => isNonexistentLocal(clockOutInput.value))

const clockInValid = computed(() => clockInInput.value !== '' && !clockInNonexistent.value)

const canSubmit = computed(() => {
  if (
    !hasReason.value ||
    submitting.value ||
    clockInNonexistent.value ||
    clockOutNonexistent.value
  ) {
    return false
  }

  if (props.mode === 'add') {
    return workDateInput.value !== '' && clockInValid.value
  }

  if (props.mode === 'correct') {
    return (clockInChanged.value || clockOutChanged.value) && props.entry !== undefined
  }

  return props.entry !== undefined
})

const clockInHint = computed(() =>
  clockInCleared.value
    ? t('corrections.dialog.clockInCannotBeCleared')
    : t('corrections.dialog.zoneHint', { zone: props.timeZone }),
)

const clockOutHint = computed(() => {
  if (clockOutCleared.value) {
    return t('corrections.dialog.clockOutCannotBeCleared')
  }

  return props.mode === 'correct' && originalClockOut === ''
    ? t('corrections.dialog.clockOutCloseHint')
    : t('corrections.dialog.clockOutOpenHint')
})

function fieldErrors(field: string): readonly string[] {
  if (field === 'clocked_in_at' && clockInNonexistent.value) {
    return [t('corrections.dialog.timeDoesNotExist')]
  }

  if (field === 'clocked_out_at' && clockOutNonexistent.value) {
    return [t('corrections.dialog.timeDoesNotExist')]
  }

  if (field === 'clocked_in_at' && workDateChangeNotice.value) {
    return [t('corrections.wouldChangeWorkDate')]
  }

  return isApiError(error.value) ? (error.value.fieldErrors[field] ?? []) : []
}

function conflictKindFor(caught: ApiError): ConflictKind {
  switch (caught.problem?.type) {
    case SHIFT_ENTRY_SUPERSEDED_TYPE:
      return 'superseded'
    case SHIFT_ALREADY_OPEN_TYPE:
      return 'shiftAlreadyOpen'
    case OVERLAPPING_SHIFT_ENTRY_TYPE:
      return 'overlap'
    default:
      // Cualquier otro `409`: un conflicto real, pero sin causa reconocida
      // desde aqui. El mensaje no afirma lo que no sabe -nunca "ya no es la
      // version vigente" cuando podria ser un solape o un turno abierto.
      return 'generic'
  }
}

function conflictMessageFor(kind: ConflictKind): string {
  if (kind === 'superseded') {
    return t('corrections.supersededNotice')
  }

  if (kind === 'shiftAlreadyOpen') {
    return t('corrections.conflict.shiftAlreadyOpen')
  }

  if (kind === 'overlap') {
    return t('corrections.conflict.overlap')
  }

  return t('corrections.conflict.generic')
}

/**
 * El `422` de RN-05/ADR-035. Se reconoce por `type` en cuanto el bloque A2 lo
 * publique; hasta entonces, por el campo reservado `clocked_in_at`: en modo
 * «correct» con la entrada cambiada, el unico `422` que puede caer ahi hoy es
 * este (`CorrectionWouldChangeWorkDate`, `bootstrap/app.php`) -no hay ninguna
 * otra regla de validacion que señale ese campo con la entrada ya cambiada.
 */
function isWorkDateChangeProblem(caught: ApiError): boolean {
  if (caught.status !== 422) {
    return false
  }

  if (caught.problem?.type === WORK_DATE_CHANGE_PROBLEM_TYPE) {
    return true
  }

  return props.mode === 'correct' && clockInChanged.value && 'clocked_in_at' in caught.fieldErrors
}

/** `2026-08-14, 06:00`, tal cual lo escribió quien rellena el formulario: sin convertir nada. */
function formatTyped(value: string): string {
  const [date, time] = value.split('T')

  return date === undefined || time === undefined
    ? ''
    : `${formatCivilDate(date, locale.value)}, ${time}`
}

function instant(value: string): string {
  return formatInstant(value, props.timeZone, locale.value)
}

const changes = computed<Change[]>(() => {
  const inLabel = t('workdays.history.fields.in')
  const outLabel = t('workdays.history.fields.out')
  const noBefore = t('workdays.history.noEntryBefore')
  const noAfter = t('workdays.history.noEntryAfter')
  const openMark = t('workdays.history.openMark')

  if (props.mode === 'add') {
    return [
      {
        label: inLabel,
        from: noBefore,
        to: clockInInput.value === '' ? '' : formatTyped(clockInInput.value),
      },
      {
        label: outLabel,
        from: noBefore,
        to: clockOutInput.value === '' ? openMark : formatTyped(clockOutInput.value),
      },
    ]
  }

  const entry = props.entry

  if (entry === undefined) {
    return []
  }

  if (props.mode === 'void') {
    return [
      { label: inLabel, from: instant(entry.clocked_in_at), to: noAfter },
      {
        label: outLabel,
        from: entry.clocked_out_at === null ? openMark : instant(entry.clocked_out_at),
        to: noAfter,
      },
    ]
  }

  // 'correct': solo las filas que de verdad cambian (mismo criterio que
  // `CorrectionHistory.changesOf`), para que se vea la que importa.
  const rows: Change[] = []

  if (clockInChanged.value) {
    rows.push({
      label: inLabel,
      from: instant(entry.clocked_in_at),
      to: clockInInput.value === '' ? '' : formatTyped(clockInInput.value),
    })
  }

  if (clockOutChanged.value) {
    rows.push({
      label: outLabel,
      from: entry.clocked_out_at === null ? openMark : instant(entry.clocked_out_at),
      to: clockOutInput.value === '' ? openMark : formatTyped(clockOutInput.value),
    })
  }

  return rows
})

function close(): void {
  emit('cancel')
}

async function submit(): Promise<void> {
  if (!canSubmit.value) {
    return
  }

  const reason = reasonCode.value

  if (reason === '') {
    return
  }

  submitting.value = true
  error.value = null
  conflict.value = null
  workDateChangeNotice.value = false

  // Solo viaja el texto libre cuando el motivo ES «OTROS» (hallazgo 6b): un
  // cambio de motivo hacia otro ya vacia `reasonText` (el `watch` de arriba),
  // pero esta es la guarda de fondo si algo llega a saltarselo.
  const reasonTextPayload =
    reason === 'OTROS' && reasonTextValue.value !== '' ? reasonTextValue.value : null

  try {
    let result: CorrectedShiftEntry

    if (props.mode === 'add') {
      const clockedInAt = zonedInputToUtcIso(clockInInput.value, props.timeZone)

      if (clockedInAt === null) {
        return
      }

      const clockedOutAt =
        clockOutInput.value === '' ? null : zonedInputToUtcIso(clockOutInput.value, props.timeZone)

      result = await addShiftEntry({
        employee_uuid: props.employeeUuid,
        work_date: workDateInput.value,
        clocked_in_at: clockedInAt,
        clocked_out_at: clockedOutAt,
        reason_code: reason,
        reason_text: reasonTextPayload,
      })
    } else if (props.entry === undefined) {
      return
    } else if (props.mode === 'correct') {
      const body: CorrectShiftEntryRequest = { reason_code: reason, reason_text: reasonTextPayload }

      // Un campo ausente significa «no lo toques» (regla dura 5, ADR-035): solo
      // se manda lo que de verdad cambió.
      if (clockInChanged.value) {
        const clockedInAt = zonedInputToUtcIso(clockInInput.value, props.timeZone)

        if (clockedInAt !== null) {
          body.clocked_in_at = clockedInAt
        }
      }

      if (clockOutChanged.value && clockOutInput.value !== '') {
        const clockedOutAt = zonedInputToUtcIso(clockOutInput.value, props.timeZone)

        if (clockedOutAt !== null) {
          body.clocked_out_at = clockedOutAt
        }
      }

      result = await correctShiftEntry(props.entry.uuid, body)
    } else {
      result = await voidShiftEntry(props.entry.uuid, {
        reason_code: reason,
        reason_text: reasonTextPayload,
      })
    }

    emit('success', result)
  } catch (caught) {
    if (!isApiError(caught)) {
      error.value = caught
    } else if (caught.status === 409) {
      const kind = conflictKindFor(caught)

      conflict.value = { kind, message: conflictMessageFor(kind) }

      // Solo la versión superada implica que la jornada cambió de verdad por
      // debajo: es la única causa para la que recargar en el acto tiene
      // sentido. Un turno ya abierto o un solape son datos mal escritos, no
      // una jornada distinta a la que se ve en pantalla.
      if (kind === 'superseded') {
        emit('stale')
      }
    } else if (isWorkDateChangeProblem(caught)) {
      workDateChangeNotice.value = true
    } else {
      error.value = caught
    }
  } finally {
    submitting.value = false
  }
}

const inputClass =
  'rounded-kq-sm border border-kq-border-strong bg-kq-surface-raised px-3 py-2 text-kq-text'
</script>

<template>
  <BaseDialog :title="t(`corrections.actions.${actionKey}`)" size="wide" @close="close">
    <p class="text-kq-text-muted" data-test="dialog-context">
      {{ t('corrections.dialog.contextEmployee', { name: employeeName }) }}
      <template v-if="mode !== 'add'">
        {{
          t('corrections.dialog.contextWorkDate', {
            date: formatCivilDate(workDate ?? '', locale),
            zone: timeZone,
          })
        }}
      </template>
    </p>

    <div
      v-if="conflict !== null"
      role="alert"
      class="mt-4 rounded-kq border border-kq-warning bg-kq-warning-soft p-4"
      data-test="dialog-conflict"
    >
      <p class="font-semibold text-kq-warning">{{ conflict.message }}</p>
    </div>

    <form id="correction-form" class="mt-4 flex flex-col gap-4" novalidate @submit.prevent="submit">
      <ErrorNotice v-if="error !== null" :error="error" data-test="dialog-error" />

      <FormField
        v-if="mode === 'add'"
        v-slot="field"
        :label="t('corrections.dialog.workDateLabel')"
        :hint="t('corrections.dialog.workDateHint')"
        :errors="fieldErrors('work_date')"
        required
      >
        <input
          :id="field.id"
          v-model="workDateInput"
          type="date"
          required
          :class="inputClass"
          :aria-describedby="field.describedBy"
          :aria-invalid="field.invalid"
          data-test="dialog-work-date"
        />
      </FormField>

      <FormField
        v-if="mode !== 'void'"
        v-slot="field"
        :label="t('corrections.dialog.clockInLabel')"
        :hint="clockInHint"
        :errors="fieldErrors('clocked_in_at')"
        :required="mode === 'add'"
      >
        <input
          :id="field.id"
          v-model="clockInInput"
          type="datetime-local"
          :required="mode === 'add'"
          :class="inputClass"
          :aria-describedby="field.describedBy"
          :aria-invalid="field.invalid"
          data-test="dialog-clock-in"
        />
      </FormField>

      <FormField
        v-if="mode !== 'void'"
        v-slot="field"
        :label="t('corrections.dialog.clockOutLabel')"
        :hint="clockOutHint"
        :errors="fieldErrors('clocked_out_at')"
      >
        <input
          :id="field.id"
          v-model="clockOutInput"
          type="datetime-local"
          :class="inputClass"
          :aria-describedby="field.describedBy"
          :aria-invalid="field.invalid"
          data-test="dialog-clock-out"
        />
      </FormField>

      <p v-if="mode === 'void'" class="text-kq-text-muted" data-test="dialog-void-summary">
        {{ t('corrections.dialog.voidSummary') }}
      </p>

      <FormField
        v-slot="field"
        :label="t('corrections.reasonLabel')"
        :hint="t('corrections.reasonHint')"
        :errors="fieldErrors('reason_code')"
        required
      >
        <select
          :id="field.id"
          v-model="reasonCode"
          required
          :class="inputClass"
          :aria-describedby="field.describedBy"
          :aria-invalid="field.invalid"
          data-test="dialog-reason"
        >
          <option value="" disabled>{{ t('corrections.reasonPlaceholder') }}</option>
          <option v-for="code of REASON_CODES" :key="code" :value="code">
            {{ t(`corrections.reasons.${code}`) }}
          </option>
        </select>
        <p v-if="reasonCode !== ''" class="text-sm text-kq-text-muted">
          {{ t(`corrections.reasonHints.${reasonCode}`) }}
        </p>
      </FormField>

      <FormField
        v-if="reasonTextRequired"
        v-slot="field"
        :label="t('corrections.reasonOtherLabel')"
        :hint="t('corrections.reasonOtherHint')"
        :errors="
          reasonTextTooShort ? [t('corrections.reasonOtherHint')] : fieldErrors('reason_text')
        "
        required
      >
        <textarea
          :id="field.id"
          v-model="reasonText"
          rows="3"
          maxlength="500"
          required
          :class="inputClass"
          :aria-describedby="field.describedBy"
          :aria-invalid="field.invalid"
          data-test="dialog-reason-text"
        />
      </FormField>

      <ChangePreview
        v-if="changes.length > 0"
        :changes="changes"
        :caption="t('corrections.dialog.previewCaption')"
        data-test="dialog-preview"
      />

      <p
        v-if="mode === 'correct' && !clockInChanged && !clockOutChanged"
        class="text-sm text-kq-text-muted"
        data-test="dialog-no-change"
      >
        {{ t('corrections.dialog.noChange') }}
      </p>

      <p class="text-sm text-kq-text-muted">{{ t('corrections.notice') }}</p>
    </form>

    <template #actions>
      <button
        type="button"
        class="rounded-kq-sm border border-kq-border-strong bg-kq-surface-raised px-4 py-2 text-kq-text hover:bg-kq-surface-alt"
        data-test="dialog-cancel"
        @click="close"
      >
        {{
          conflict?.kind === 'superseded'
            ? t('corrections.retryOnCurrentVersion')
            : t('common.cancel')
        }}
      </button>
      <button
        v-if="conflict === null || conflict.kind !== 'superseded'"
        type="submit"
        form="correction-form"
        :disabled="!canSubmit"
        :aria-busy="submitting"
        class="rounded-kq-sm px-4 py-2 font-semibold text-kq-on-primary disabled:opacity-60"
        :class="mode === 'void' ? 'bg-kq-danger' : 'bg-kq-primary-strong'"
        data-test="dialog-submit"
      >
        {{ submitting ? t('common.saving') : t(`corrections.actions.${actionKey}`) }}
      </button>
    </template>
  </BaseDialog>
</template>
