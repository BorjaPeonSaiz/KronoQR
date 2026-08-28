<script setup lang="ts">
// El historico de correcciones de una jornada propia (RL-05, RN-13, RL-04).
//
// **Nada se ha borrado y esta pantalla no puede dar a entender lo contrario**
// (regla dura 5): si alguien cambio una hora, aparece aqui con quien lo hizo,
// cuando y por que, y con lo que decia antes. Es justo lo que la persona
// trabajadora tiene derecho a poder mirar de su propio registro.
//
// Las horas de `before`/`after` llegan **solo en UTC**: son las marcas tal y
// como quedaron escritas en el libro de correcciones. Se convierten aqui con la
// zona del centro, que viaja en la respuesta, y nunca con la del navegador
// (regla dura 3).
//
// El momento en el que se firmo la correccion (`performed_at`) es distinto: el
// servidor lo manda TAMBIEN ya resuelto en la zona del centro
// (`performed_at_local`), y esa hora resuelta **se lee, no se convierte**
// (ADR-036). Convertir `performed_at` otra vez en el navegador repetiria un
// calculo que ya se hizo con la zona buena, y en una noche de cambio de hora
// las dos cuentas no tienen por que dar lo mismo — que es exactamente el
// defecto que esta migracion corrige.
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

const props = defineProps<{
  corrections: readonly WorkDayCorrection[]
  /** Zona del centro, para leer en ella las marcas UTC del libro de correcciones. */
  timeZone: string
}>()

const { t, locale } = useI18n()

function instant(value: string): string {
  return formatInstant(value, props.timeZone, locale.value)
}

function duration(minutes: number): string {
  return t('myRecords.duration', durationParts(minutes))
}

/**
 * El momento de la correccion, ya resuelto por el servidor: aqui solo se lee.
 *
 * `performed_at_local` es la pieza que esta pantalla no leia antes de esta
 * migracion (ADR-036): reconvertia `performed_at` (UTC), que es correcto salvo
 * que la tabla de zonas horarias del navegador difiera de la del servidor
 * justo la noche que cambia — precisamente cuando mas importa que panel y
 * portal digan la misma hora.
 */
function performedAt(correction: WorkDayCorrection): string {
  const parts = readLocalTimestamp(correction.performed_at_local)

  return parts === null
    ? instant(correction.performed_at)
    : `${formatCivilDate(parts.date, locale.value)}, ${parts.time}`
}

interface MarksView {
  clockIn: string
  clockOut: string
  worked: string
}

function marksView(marks: ShiftMarks | null): MarksView | null {
  if (marks === null) {
    return null
  }

  return {
    clockIn: instant(marks.clocked_in_at),
    clockOut:
      marks.clocked_out_at === null
        ? t('myRecords.history.openMark')
        : instant(marks.clocked_out_at),
    worked: duration(marks.worked_minutes),
  }
}

interface CorrectionView {
  key: string
  action: WorkDayCorrection['action']
  moment: string
  zoneLabel: string
  author: string
  reason: string
  reasonText: string | null
  before: MarksView | null
  after: MarksView | null
}

const items = computed<CorrectionView[]>(() =>
  props.corrections.map((correction, index) => ({
    key: `${correction.shift_entry_uuid}-${correction.performed_at}-${index}`,
    action: correction.action,
    moment: performedAt(correction),
    zoneLabel: formatZoneLabel(correction.performed_at, props.timeZone, locale.value),
    // El nombre de quien firmo la correccion. No sale su correo: no hace falta
    // para saber quien cambio unas horas.
    author: correction.performed_by.name,
    reason: t(`myRecords.reasons.${correction.reason_code}`),
    reasonText: correction.reason_text,
    before: marksView(correction.before),
    after: marksView(correction.after),
  })),
)
</script>

<template>
  <section>
    <h3 class="font-heading text-lg font-semibold text-kq-text">
      {{ t('myRecords.history.heading') }}
    </h3>

    <p v-if="corrections.length === 0" data-test="history-empty" class="mt-2 text-kq-text-muted">
      {{ t('myRecords.history.empty') }}
    </p>

    <template v-else>
      <p class="mt-1 max-w-prose text-kq-text-muted">{{ t('myRecords.history.notice') }}</p>

      <ol class="mt-3 flex flex-col gap-4">
        <li
          v-for="item of items"
          :key="item.key"
          data-test="correction"
          class="rounded-kq border border-kq-border bg-kq-surface-raised p-4 shadow-kq-soft"
        >
          <h4
            class="inline-block rounded-full bg-kq-warning-soft px-3 py-1 text-sm font-semibold text-kq-warning"
          >
            {{ t(`myRecords.correctionAction.${item.action}`) }}
          </h4>
          <p class="mt-2 text-kq-text-muted">
            {{ t('myRecords.history.by', { author: item.author, moment: item.moment }) }}
            <span class="text-kq-text-muted">({{ item.zoneLabel }})</span>
          </p>
          <p class="mt-1 text-kq-text">
            <span class="font-medium">{{ t('myRecords.history.reason') }}:</span>
            {{ item.reason }}
          </p>
          <p v-if="item.reasonText !== null" class="mt-1 text-kq-text-muted">
            {{ item.reasonText }}
          </p>

          <table class="mt-3 w-full border-collapse text-left text-sm">
            <caption class="sr-only">
              {{
                t('myRecords.history.caption', { moment: item.moment })
              }}
            </caption>
            <thead>
              <tr class="border-b border-kq-border">
                <th scope="col" class="py-1 pr-3"></th>
                <th scope="col" class="py-1 pr-3">{{ t('myRecords.history.fields.in') }}</th>
                <th scope="col" class="py-1 pr-3">{{ t('myRecords.history.fields.out') }}</th>
                <th scope="col" class="py-1 pr-3">{{ t('myRecords.history.fields.worked') }}</th>
              </tr>
            </thead>
            <tbody>
              <!--
                El estado anterior a la correccion se ve, no se tacha (regla dura
                5): esta fila nunca lleva `line-through` ni ningun otro estilo que
                sugiera «esto se ha borrado». `text-kq-text-muted` la distingue de
                la fila «despues» sin dar a entender que ha desaparecido.
              -->
              <tr class="border-b border-kq-border text-kq-text-muted">
                <th scope="row" class="py-1 pr-3 font-medium">
                  {{ t('myRecords.history.before') }}
                </th>
                <td class="py-1 pr-3">
                  {{ item.before?.clockIn ?? t('myRecords.history.noEntryBefore') }}
                </td>
                <td class="py-1 pr-3">{{ item.before?.clockOut ?? '—' }}</td>
                <td class="py-1 pr-3">{{ item.before?.worked ?? '—' }}</td>
              </tr>
              <tr class="text-kq-text">
                <th scope="row" class="py-1 pr-3 font-medium">
                  {{ t('myRecords.history.after') }}
                </th>
                <td class="py-1 pr-3">
                  {{ item.after?.clockIn ?? t('myRecords.history.noEntryAfter') }}
                </td>
                <td class="py-1 pr-3">{{ item.after?.clockOut ?? '—' }}</td>
                <td class="py-1 pr-3">{{ item.after?.worked ?? '—' }}</td>
              </tr>
            </tbody>
          </table>
        </li>
      </ol>
    </template>
  </section>
</template>
