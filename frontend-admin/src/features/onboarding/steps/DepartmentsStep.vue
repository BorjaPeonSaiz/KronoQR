<script setup lang="ts">
// Paso 4: departamentos (RF-PD-03). Omitible: se puede dar de alta la
// plantilla sin departamentos y crearlos mas tarde desde la gestion normal.
import ErrorNotice from '@kronoqr/web-kit/components/ErrorNotice.vue'
import FormField from '@kronoqr/web-kit/components/FormField.vue'
import { isApiError } from '@kronoqr/web-kit/http'
import { onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { createDepartment, listDepartments } from '@/shared/api/organisation.api'
import type { Department } from '@/shared/api/types'
import { useSetupStore } from '../setup.store'

const { t } = useI18n()
const setup = useSetupStore()

const departments = ref<Department[]>([])
const loading = ref(true)
const newName = ref('')
const adding = ref(false)
const finishing = ref(false)
const error = ref<unknown>(null)

async function load(): Promise<void> {
  loading.value = true
  error.value = null

  try {
    departments.value = (await listDepartments()).data
  } catch (caught) {
    error.value = caught
  } finally {
    loading.value = false
  }
}

onMounted(load)

function fieldErrors(field: string): readonly string[] {
  return isApiError(error.value) ? (error.value.fieldErrors[field] ?? []) : []
}

async function addDepartment(): Promise<void> {
  if (newName.value.trim() === '') {
    return
  }

  adding.value = true
  error.value = null

  try {
    const created = await createDepartment({ name: newName.value.trim() })

    departments.value = [...departments.value, created]
    newName.value = ''
  } catch (caught) {
    error.value = caught
  } finally {
    adding.value = false
  }
}

async function finish(state: 'completed' | 'skipped'): Promise<void> {
  finishing.value = true
  error.value = null

  try {
    await setup.recordStep('departments', state)
  } catch (caught) {
    error.value = caught
  } finally {
    finishing.value = false
  }
}
</script>

<template>
  <div class="flex flex-col gap-4">
    <h2 tabindex="-1" class="text-lg font-semibold text-kq-text focus:outline-none">
      {{ t('onboarding.steps.departments.heading') }}
    </h2>
    <p class="text-sm text-kq-text-muted">{{ t('onboarding.steps.departments.intro') }}</p>

    <p v-if="loading" class="text-kq-text-muted" role="status">
      {{ t('onboarding.steps.departments.loading') }}
    </p>

    <ErrorNotice
      v-if="error !== null"
      :error="error"
      :field-labels="{ name: t('onboarding.steps.departments.fields.name') }"
    />

    <template v-if="!loading">
      <ul v-if="departments.length > 0" class="flex flex-col gap-1" data-test="department-list">
        <li
          v-for="department of departments"
          :key="department.id"
          class="rounded-kq-sm border border-kq-border bg-kq-surface-raised px-3 py-2 text-kq-text"
        >
          {{ department.name }}
        </li>
      </ul>
      <p v-else class="text-kq-text-muted" data-test="no-departments">
        {{ t('onboarding.steps.departments.none') }}
      </p>

      <form class="flex flex-wrap items-end gap-3" novalidate @submit.prevent="addDepartment">
        <FormField
          v-slot="field"
          :label="t('onboarding.steps.departments.fields.name')"
          :errors="fieldErrors('name')"
        >
          <input
            :id="field.id"
            v-model="newName"
            type="text"
            :aria-describedby="field.describedBy"
            :aria-invalid="field.invalid"
            class="rounded-kq-sm border border-kq-border-strong bg-kq-surface-raised px-3 py-2 text-kq-text"
          />
        </FormField>
        <button
          type="submit"
          :disabled="adding || newName.trim() === ''"
          :aria-busy="adding"
          class="rounded-kq-sm border border-kq-border-strong bg-kq-surface-raised px-4 py-2 text-kq-text hover:bg-kq-surface-alt disabled:opacity-60"
        >
          {{
            adding
              ? t('onboarding.steps.departments.adding')
              : t('onboarding.steps.departments.add')
          }}
        </button>
      </form>

      <div class="flex gap-3">
        <button
          type="button"
          :disabled="finishing"
          :aria-busy="finishing"
          data-test="continue"
          class="rounded-kq-sm bg-kq-primary-strong px-4 py-2 font-semibold text-kq-on-primary disabled:opacity-60"
          @click="finish('completed')"
        >
          {{ t('onboarding.actions.continue') }}
        </button>
        <button
          type="button"
          :disabled="finishing"
          data-test="skip"
          class="rounded-kq-sm border border-kq-border-strong bg-kq-surface-raised px-4 py-2 text-kq-text hover:bg-kq-surface-alt disabled:opacity-60"
          @click="finish('skipped')"
        >
          {{ t('onboarding.actions.skip') }}
        </button>
      </div>
    </template>
  </div>
</template>
