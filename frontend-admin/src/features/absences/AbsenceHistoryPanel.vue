<script setup lang="ts">
// El historico de versiones de una ausencia (RF-GP-04, RN-13, RL-04).
//
// NADA SE HA BORRADO (regla dura 5): cada correccion crea una version nueva y
// la anterior se conserva. Este panel pinta el historico de la mas antigua a
// la mas reciente (decision 5 de la ficha: asi lo entrega
// `GET /absences/{uuid}`), y cada version cambiada se enseña con el mismo
// patron que el resto del panel -que cambio, desde que valor y hacia cual-,
// con el mismo `ChangePreview` que usa `CorrectionHistory.vue` del registro
// horario.
//
// SIN AUTOR: la forma acordada de `Absence` no lleva quien hizo cada
// correccion, solo cuando (`created_at`) y por que (`change_reason`). Se
// enseña lo que hay, sin inventar un dato que el contrato no da.
import { formatCivilDate, formatInstant } from '@kronoqr/web-kit/datetime'
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import type { Absence, AbsenceDetail } from '@/shared/api/types'
import type { Change } from '@/shared/ui/change'
import ChangePreview from '@/shared/ui/ChangePreview.vue'

/**
 * `detail.absence` es la version vigente (o la version pedida, si se pidio
 * por el `uuid` de una version anterior); `detail.history` son las versiones
 * anteriores, de la mas antigua a la mas reciente y sin incluirla (contrato,
 * `GET /absences/{uuid}`).
 */
const props = defineProps<{ detail: AbsenceDetail }>()

const { t, locale } = useI18n()

/** El historico completo, de la version 1 a la pedida, ordenado por version. */
const timeline = computed<Absence[]>(() =>
  [...props.detail.history, props.detail.absence].sort(
    (a: Absence, b: Absence) => a.version - b.version,
  ),
)

interface VersionView {
  key: string
  version: number
  moment: string
  reason: string | null
  changes: Change[]
}

function dateLabel(value: string): string {
  return formatCivilDate(value, locale.value)
}

function changesBetween(before: Absence, after: Absence): Change[] {
  const changes: Change[] = []

  if (before.type !== after.type) {
    changes.push({
      label: t('absences.fields.type'),
      from: t(`absences.types.${before.type}`),
      to: t(`absences.types.${after.type}`),
    })
  }

  if (before.starts_on !== after.starts_on) {
    changes.push({
      label: t('absences.fields.startsOn'),
      from: dateLabel(before.starts_on),
      to: dateLabel(after.starts_on),
    })
  }

  if (before.ends_on !== after.ends_on) {
    changes.push({
      label: t('absences.fields.endsOn'),
      from: dateLabel(before.ends_on),
      to: dateLabel(after.ends_on),
    })
  }

  // La nota solo se compara si las dos versiones la llevan (decision 5): quien
  // no tiene `employees:*` nunca la recibe, y comparar `undefined` con un
  // texto real fabricaria un cambio que no se puede mostrar.
  if (before.note !== undefined && after.note !== undefined && before.note !== after.note) {
    changes.push({
      label: t('absences.fields.note'),
      from: before.note ?? t('common.empty'),
      to: after.note ?? t('common.empty'),
    })
  }

  return changes
}

const versions = computed<VersionView[]>(() =>
  timeline.value.map((entry, index) => {
    const previous = timeline.value[index - 1]

    return {
      key: entry.uuid,
      version: entry.version,
      moment: formatInstant(entry.created_at, 'UTC', locale.value),
      reason: entry.change_reason,
      changes: previous === undefined ? [] : changesBetween(previous, entry),
    }
  }),
)

const voidedMoment = computed(() =>
  props.detail.absence.voided_at === null
    ? ''
    : formatInstant(props.detail.absence.voided_at, 'UTC', locale.value),
)
</script>

<template>
  <section>
    <p class="text-kq-text-muted">{{ t('absences.history.notice') }}</p>

    <ol class="mt-3 flex flex-col gap-4">
      <li
        v-for="entry of versions"
        :key="entry.key"
        data-test="absence-version"
        class="rounded-kq border border-kq-border bg-kq-surface-raised p-4"
      >
        <h4 class="font-semibold">
          {{
            entry.version === 1
              ? t('absences.history.registered')
              : t('absences.history.correctedToVersion', { version: entry.version })
          }}
        </h4>
        <p class="text-kq-text-muted">{{ t('absences.history.at', { moment: entry.moment }) }}</p>
        <p v-if="entry.reason !== null" class="mt-1">
          <span class="font-medium">{{ t('absences.history.reason') }}:</span>
          {{ entry.reason }}
        </p>

        <ChangePreview
          v-if="entry.changes.length > 0"
          class="mt-3"
          :changes="entry.changes"
          :caption="t('absences.history.caption', { moment: entry.moment })"
          :from-label="t('workdays.history.before')"
          :to-label="t('workdays.history.after')"
        />
      </li>

      <li
        v-if="detail.absence.status === 'voided'"
        data-test="absence-voided"
        class="rounded-kq border border-kq-danger bg-kq-danger-soft p-4"
      >
        <h4 class="font-semibold text-kq-danger">{{ t('absences.history.voided') }}</h4>
        <p class="text-kq-text-muted">{{ t('absences.history.at', { moment: voidedMoment }) }}</p>
        <p v-if="detail.absence.void_reason !== null" class="mt-1">
          <span class="font-medium">{{ t('absences.history.reason') }}:</span>
          {{ detail.absence.void_reason }}
        </p>
      </li>
    </ol>
  </section>
</template>
