<script setup lang="ts">
// Eleccion de la persona de la exportacion para la Inspeccion (RF-IN-05). El
// `auditor` no puede leer la plantilla: esta es la unica via por
// la que llega a una persona sin pedir su UUID a RRHH.
//
// Tres decisiones que no son esteticas:
//
//  - **Nada de autocompletado.** Cada busqueda deja un asiento en la auditoria
//    (`personal_data.accessed`). Buscar mientras se teclea escribiria un asiento
//    por pulsacion y convertiria esto en un directorio. Se busca con Intro o con
//    el boton.
//  - **El DNI no se queda.** Se vacia el campo en cuanto se lanza la busqueda; no
//    va a Pinia, ni a la URL (la peticion es un `POST`), ni a la cache de
//    consultas: el resultado vive en este componente y muere con el.
//  - **El UUID se guarda pero no se ensena.** La persona se reconoce por su
//    nombre, su departamento y su situacion; el identificador es fontaneria.
//
// El componente vive dentro del `<form>` de la exportacion: Intro en el campo NO
// debe enviarla, asi que no hay `<form>` anidado y Intro se intercepta.
import { announce } from '@kronoqr/web-kit/announcer'
import ErrorNotice from '@kronoqr/web-kit/components/ErrorNotice.vue'
import FormField from '@kronoqr/web-kit/components/FormField.vue'
import { formatCivilDate } from '@kronoqr/web-kit/datetime'
import { isApiError } from '@kronoqr/web-kit/http'
import { computed, nextTick, ref, useId, useTemplateRef } from 'vue'
import { useI18n } from 'vue-i18n'
import { usePersonName } from '@/shared/people/personName'
import type { LegalExportEmployeeMatch, LegalExportEmployeeSearch } from '@/shared/api/types'
import { searchLegalExportEmployees } from './legalExport.api'

type SearchMode = 'text' | 'nationalId'

/** UUID de la persona elegida; vacio mientras no haya ninguna. */
const uuid = defineModel<string>({ default: '' })

const { t, locale } = useI18n()
const { personName, isMinimised } = usePersonName()

const modeName = useId()
const resultsName = useId()

const mode = ref<SearchMode>('text')
const term = ref('')
const searching = ref(false)
const error = ref<unknown>(null)
const matches = ref<readonly LegalExportEmployeeMatch[] | null>(null)
const truncated = ref(false)
/** Con que criterio se obtuvo `matches`: el mensaje de «sin resultados» depende de ello. */
const searchedBy = ref<SearchMode>('text')
const highlighted = ref('')
const selected = ref<LegalExportEmployeeMatch | null>(null)

const input = useTemplateRef<HTMLInputElement>('termInput')
const changeButton = useTemplateRef<HTMLButtonElement>('changeButton')

/** Se descarta la respuesta de una busqueda que ya no es la ultima. */
let searchCounter = 0

const fieldKey = computed(() => (mode.value === 'text' ? 'q' : 'national_id'))

const validationErrors = computed<readonly string[]>(() => {
  if (!isApiError(error.value) || error.value.kind !== 'validation') {
    return []
  }

  const messages = error.value.fieldErrors[fieldKey.value] ?? []

  return messages.length > 0 ? messages : [t('reports.legalExport.picker.invalid')]
})

/** Los 422 se pintan junto al campo; el resto (403, 429, red...) en el aviso. */
const noticeError = computed<unknown>(() =>
  error.value !== null && validationErrors.value.length === 0 ? error.value : null,
)

const canSearch = computed(() => term.value.trim() !== '' && !searching.value)

function describe(match: LegalExportEmployeeMatch): string {
  const parts = [
    isMinimised(match.first_name, match.last_name)
      ? personName('', '')
      : `${match.last_name}, ${match.first_name}`,
    match.department?.name ?? t('reports.legalExport.picker.noDepartment'),
  ]

  if (match.status === 'terminated') {
    parts.push(
      match.terminated_at === null
        ? t('reports.legalExport.picker.terminated')
        : t('reports.legalExport.picker.terminatedSince', {
            date: formatCivilDate(match.terminated_at, locale.value),
          }),
    )
  } else if (match.status === 'suspended') {
    parts.push(t('reports.legalExport.picker.suspended'))
  }

  return parts.join(' · ')
}

function clearResults(): void {
  matches.value = null
  truncated.value = false
  highlighted.value = ''
  error.value = null
}

function changeMode(next: SearchMode): void {
  mode.value = next
  // Invalida la busqueda en curso: su respuesta se descarta y el boton se libera.
  searchCounter++
  searching.value = false
  // Un DNI tecleado no debe sobrevivir a un cambio de criterio.
  term.value = ''
  clearResults()
}

async function search(): Promise<void> {
  const typed = term.value.trim()

  if (typed === '' || searching.value) {
    return
  }

  const criterion = mode.value
  const body: LegalExportEmployeeSearch =
    criterion === 'text' ? { q: typed } : { national_id: typed }

  // El DNI se borra del campo ANTES de esperar la respuesta, y el cuerpo ya esta
  // construido: no queda copia reactiva del documento.
  if (criterion === 'nationalId') {
    term.value = ''
  }

  const current = ++searchCounter

  searching.value = true
  clearResults()

  try {
    const found = await searchLegalExportEmployees(body)

    if (current !== searchCounter) {
      return
    }

    matches.value = found.data
    truncated.value = found.truncated
    searchedBy.value = criterion
    announce(t('reports.legalExport.picker.found', found.data.length))
  } catch (failure) {
    if (current !== searchCounter) {
      return
    }

    error.value = failure
    announce(t('reports.legalExport.picker.failed'))
  } finally {
    if (current === searchCounter) {
      searching.value = false
    }
  }
}

function choose(): void {
  const match = matches.value?.find((candidate) => candidate.uuid === highlighted.value)

  if (match === undefined) {
    return
  }

  selected.value = match
  uuid.value = match.uuid
  clearResults()
  term.value = ''
  announce(t('reports.legalExport.picker.chosen', { person: describe(match) }))
  void nextTick(() => changeButton.value?.focus())
}

function change(): void {
  selected.value = null
  uuid.value = ''
  void nextTick(() => input.value?.focus())
}
</script>

<template>
  <div class="flex flex-col gap-3">
    <div
      v-if="selected !== null"
      class="flex flex-wrap items-center gap-3 rounded-kq border border-kq-border-strong bg-kq-surface-raised p-3"
    >
      <p class="font-medium">
        {{ t('reports.legalExport.picker.willExport', { person: describe(selected) }) }}
      </p>
      <button
        ref="changeButton"
        type="button"
        class="rounded-kq-sm border border-kq-border-strong px-3 py-1 text-kq-text hover:bg-kq-surface-alt"
        @click="change"
      >
        {{ t('reports.legalExport.picker.change') }}
      </button>
    </div>

    <template v-else>
      <fieldset class="flex flex-wrap gap-4">
        <legend class="mb-1 font-medium">
          {{ t('reports.legalExport.picker.searchBy') }}
        </legend>
        <label class="flex items-center gap-2">
          <input
            type="radio"
            :name="modeName"
            value="text"
            :checked="mode === 'text'"
            @change="changeMode('text')"
          />
          {{ t('reports.legalExport.picker.byText') }}
        </label>
        <label class="flex items-center gap-2">
          <input
            type="radio"
            :name="modeName"
            value="nationalId"
            :checked="mode === 'nationalId'"
            @change="changeMode('nationalId')"
          />
          {{ t('reports.legalExport.picker.byNationalId') }}
        </label>
      </fieldset>

      <div class="flex flex-wrap items-end gap-3">
        <div class="min-w-64 flex-1">
          <FormField
            :label="
              mode === 'text'
                ? t('reports.legalExport.picker.textLabel')
                : t('reports.legalExport.picker.nationalIdLabel')
            "
            :hint="
              mode === 'text'
                ? t('reports.legalExport.picker.textHint')
                : t('reports.legalExport.picker.nationalIdHint')
            "
            :errors="validationErrors"
          >
            <template #default="{ id, describedBy, invalid }">
              <input
                :id="id"
                ref="termInput"
                v-model="term"
                type="text"
                autocomplete="off"
                autocapitalize="off"
                spellcheck="false"
                :aria-describedby="describedBy"
                :aria-invalid="invalid"
                class="rounded-kq-sm border border-kq-border-strong bg-kq-surface-raised px-3 py-2 text-kq-text"
                @keydown.enter.prevent="search"
              />
            </template>
          </FormField>
        </div>
        <button
          v-aria-disabled.reason="!canSearch"
          type="button"
          class="rounded-kq-sm bg-kq-primary-strong px-4 py-2 text-kq-on-primary hover:brightness-95 aria-disabled:opacity-50"
          @click="search"
        >
          {{
            searching
              ? t('reports.legalExport.picker.searching')
              : t('reports.legalExport.picker.search')
          }}
        </button>
      </div>

      <p class="text-sm text-kq-text-muted">{{ t('reports.legalExport.picker.audited') }}</p>

      <ErrorNotice v-if="noticeError !== null" :error="noticeError" />

      <template v-if="matches !== null">
        <p v-if="matches.length === 0" class="text-kq-text-muted">
          {{
            searchedBy === 'nationalId'
              ? t('reports.legalExport.picker.noneByNationalId')
              : t('reports.legalExport.picker.noneByText')
          }}
        </p>

        <fieldset v-else class="flex flex-col gap-2">
          <legend class="mb-1 font-medium">
            {{ t('reports.legalExport.picker.resultsLegend', matches.length) }}
          </legend>
          <label
            v-for="match of matches"
            :key="match.uuid"
            class="flex items-start gap-2 rounded-kq-sm border border-kq-border bg-kq-surface-raised p-2"
          >
            <input
              v-model="highlighted"
              type="radio"
              :name="resultsName"
              :value="match.uuid"
              class="mt-1"
            />
            <span>{{ describe(match) }}</span>
          </label>
          <div>
            <button
              v-aria-disabled.reason="highlighted === ''"
              type="button"
              class="rounded-kq-sm bg-kq-primary-strong px-4 py-2 text-kq-on-primary hover:brightness-95 aria-disabled:opacity-50"
              @click="choose"
            >
              {{ t('reports.legalExport.picker.choose') }}
            </button>
          </div>
        </fieldset>

        <p
          v-if="truncated"
          class="rounded-kq border border-kq-warning bg-kq-warning-soft p-3 text-kq-warning"
        >
          {{ t('reports.legalExport.picker.truncated') }}
        </p>
      </template>
    </template>
  </div>
</template>
