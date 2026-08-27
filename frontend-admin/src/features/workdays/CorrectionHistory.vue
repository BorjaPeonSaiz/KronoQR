<script setup lang="ts">
// El historico de correcciones de una jornada (RF-PA-03, RN-13, RL-04).
//
// **Nada se ha borrado y la pantalla no puede dar a entender lo contrario**
// (regla dura 5). Por eso cada asiento se pinta con el mismo patron con el que
// el panel confirma un cambio antes de hacerlo: que cambio, **desde que valor y
// hacia cual**. Quien lo mira tiene que poder responder «cuanto decia antes»
// sin abrir otra pantalla — y en una inspeccion, esa es exactamente la pregunta.
//
// Las horas de `before`/`after` llegan **solo en UTC**: son las marcas tal y como
// quedaron escritas en el libro de correcciones. Se convierten aqui con la zona
// del centro, que viaja en la respuesta, y nunca con la del navegador (regla
// dura 3). La zona va escrita al lado.
import {
  formatCivilDate,
  formatInstant,
  formatZoneLabel,
  readLocalTimestamp,
} from '@kronoqr/web-kit/datetime'
import { durationParts } from '@kronoqr/web-kit/workdayTotals'
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import type { ShiftMarks, WorkDayCorrection } from '@/shared/api/types'
import type { Change } from '@/shared/ui/change'
import ChangePreview from '@/shared/ui/ChangePreview.vue'

const props = defineProps<{
  corrections: readonly WorkDayCorrection[]
  /** Zona del centro, para leer en ella las marcas UTC del libro de correcciones. */
  timeZone: string
}>()

const { t, locale } = useI18n()

interface CorrectionView {
  key: string
  action: WorkDayCorrection['action']
  moment: string
  zoneLabel: string
  author: string
  reason: string
  reasonText: string | null
  entryUuid: string
  changes: Change[]
}

function instant(value: string): string {
  return formatInstant(value, props.timeZone, locale.value)
}

function duration(minutes: number): string {
  return t('workdays.duration', durationParts(minutes))
}

/** El momento de la correccion, ya resuelto por el servidor: aqui solo se lee. */
function performedAt(correction: WorkDayCorrection): string {
  const parts = readLocalTimestamp(correction.performed_at_local)

  return parts === null
    ? instant(correction.performed_at)
    : `${formatCivilDate(parts.date, locale.value)}, ${parts.time}`
}

type MarkField = 'in' | 'out' | 'worked' | 'version'

/**
 * El valor de un campo en una version del tramo.
 *
 * `absent` distingue las dos ausencias, que no significan lo mismo: en un alta
 * **no habia tramo antes**, y en una anulacion **no hay version posterior**.
 * Escribir «—» en los dos casos borraria de la pantalla que paso.
 */
function markValue(marks: ShiftMarks | null, field: MarkField, absent: string): string {
  if (marks === null) {
    return absent
  }

  switch (field) {
    case 'in':
      return instant(marks.clocked_in_at)
    case 'out':
      return marks.clocked_out_at === null
        ? t('workdays.history.openMark')
        : instant(marks.clocked_out_at)
    case 'worked':
      return duration(marks.worked_minutes)
    case 'version':
      return String(marks.version)
  }
}

/**
 * Solo las filas que de verdad cambiaron, y siempre al menos la version: una
 * tabla con cuatro filas iguales esconde la unica que no lo es.
 */
function changesOf(correction: WorkDayCorrection): Change[] {
  const fields: readonly { field: MarkField; label: string }[] = [
    { field: 'in', label: t('workdays.history.fields.in') },
    { field: 'out', label: t('workdays.history.fields.out') },
    { field: 'worked', label: t('workdays.history.fields.worked') },
    { field: 'version', label: t('workdays.history.fields.version') },
  ]

  const noBefore = t('workdays.history.noEntryBefore')
  const noAfter = t('workdays.history.noEntryAfter')

  const row = ({ field, label }: { field: MarkField; label: string }): Change => ({
    label,
    from: markValue(correction.before, field, noBefore),
    to: markValue(correction.after, field, noAfter),
  })

  const changes = fields.map(row).filter((change) => change.from !== change.to)

  return changes.length > 0
    ? changes
    : [row({ field: 'version', label: t('workdays.history.fields.version') })]
}

const items = computed<CorrectionView[]>(() =>
  props.corrections.map((correction, index) => ({
    key: `${correction.shift_entry_uuid}-${correction.performed_at}-${index}`,
    action: correction.action,
    moment: performedAt(correction),
    zoneLabel: formatZoneLabel(correction.performed_at, props.timeZone, locale.value),
    // El nombre de quien firmo la correccion, que es un dato de la correccion.
    // No sale su correo: esta pantalla no lo necesita.
    author: correction.performed_by.name,
    reason: t(`corrections.reasons.${correction.reason_code}`),
    reasonText: correction.reason_text,
    entryUuid: correction.shift_entry_uuid,
    changes: changesOf(correction),
  })),
)
</script>

<template>
  <section>
    <h3 class="text-lg font-semibold">{{ t('workdays.history.heading') }}</h3>

    <p v-if="corrections.length === 0" data-test="history-empty" class="mt-2 text-slate-700">
      {{ t('workdays.history.empty') }}
    </p>

    <template v-else>
      <p class="mt-1 max-w-prose text-slate-700">{{ t('workdays.history.notice') }}</p>

      <ol class="mt-3 flex flex-col gap-4">
        <li
          v-for="item of items"
          :key="item.key"
          data-test="correction"
          class="rounded border border-slate-300 bg-white p-4"
        >
          <h4 class="font-semibold">{{ t(`corrections.action.${item.action}`) }}</h4>
          <p class="text-slate-700">
            {{ t('workdays.history.by', { author: item.author, moment: item.moment }) }}
            <span class="text-slate-600">({{ item.zoneLabel }})</span>
          </p>
          <p class="mt-1">
            <span class="font-medium">{{ t('workdays.history.reason') }}:</span>
            {{ item.reason }}
          </p>
          <p v-if="item.reasonText !== null" class="mt-1 text-slate-700">{{ item.reasonText }}</p>

          <ChangePreview
            class="mt-3"
            :changes="item.changes"
            :caption="t('workdays.history.caption', { moment: item.moment })"
            :from-label="t('workdays.history.before')"
            :to-label="t('workdays.history.after')"
          />

          <p class="mt-2 font-mono text-sm text-slate-600">
            {{ t('workdays.history.entry', { uuid: item.entryUuid }) }}
          </p>
        </li>
      </ol>
    </template>
  </section>
</template>
