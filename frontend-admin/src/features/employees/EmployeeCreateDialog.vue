<script setup lang="ts">
// Alta de empleado (RF-GP-01).
//
// Cuatro cosas que el formulario dice en voz alta porque son decisiones del
// producto y no descuidos:
//  - El correo es opcional y ninguna funcionalidad lo exige (regla dura 12).
//  - El documento de identidad NO se almacena: se convierte en huella en el
//    servidor y el valor en claro no sobrevive a la peticion (RL-08).
//  - El codigo de empleado lo genera el servidor. No hay campo para teclearlo.
//  - El centro no se elige: hay exactamente uno por instalacion (ADR-040) y el
//    servidor adscribe el alta a el. Su zona horaria solo sirve aqui para
//    proponer «hoy» como fecha de alta.
//
// El alta emite el PIN en la misma transaccion, asi que al terminar hay un
// secreto que enseñar una sola vez: por eso emite `created` con la respuesta
// entera y no solo con la ficha.
//
// Dos cosas mas (RN-27, ADR-062 §6):
//  - Con fecha de alta FUTURA se avisa de que los fichajes anteriores a esa fecha
//    se rechazan (decision del propietario, 31-10-2026): es un aviso, no un bloqueo.
//  - Si el documento es de una persona de BAJA, el `409` lleva su `uuid` y el
//    dialogo lo cuenta con su salida: no se da de alta otra vez, se la da de alta
//    DE NUEVO en su ficha.
import ErrorNotice from '@kronoqr/web-kit/components/ErrorNotice.vue'
import FormField from '@kronoqr/web-kit/components/FormField.vue'
import { formatCivilDate, todayInZone } from '@kronoqr/web-kit/datetime'
import { isApiError } from '@kronoqr/web-kit/http'
import { computed, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { RouterLink } from 'vue-router'
import { useOfferedLocales } from '@/shared/i18n/useOfferedLocales'
import { useQuery } from '@tanstack/vue-query'
import { listDepartments } from '@/shared/api/organisation.api'
import type { CreateEmployeeRequest, EmployeeProvisioned } from '@/shared/api/types'
import BaseDialog from '@/shared/ui/BaseDialog.vue'
import TeleworkingCheckbox from './TeleworkingCheckbox.vue'
import { rehirableEmployeeUuid } from './employmentProblems'
import { createEmployee } from './employees.api'
import { isFutureHire } from './rehirePlan'

const props = defineProps<{ timezone: string }>()
const emit = defineEmits<{ close: []; created: [EmployeeProvisioned] }>()

const { t, locale: uiLocale } = useI18n()
const { locales: offered, multilingual, defaultLocale } = useOfferedLocales()

const departmentId = ref<number | null>(null)
const firstName = ref('')
const lastName = ref('')
const email = ref('')
const nationalId = ref('')
const locale = ref<string>(defaultLocale.value)
const hiredAt = ref(todayInZone(props.timezone))
const teleworking = ref(false)

// `useQuery` devuelve refs sueltas: se desestructuran para que la plantilla las
// desenvuelva sola. `departments.data` sin desestructurar seguiria siendo un Ref.
const { data: departments } = useQuery({
  queryKey: ['departments', 'all'],
  queryFn: () => listDepartments(),
})

const submitting = ref(false)
const error = ref<unknown>(null)

/** Hoy en la zona del centro (ADR-040), para avisar de un alta futura. */
const today = todayInZone(props.timezone)
const futureHire = computed(() => isFutureHire(hiredAt.value, today))

/** La persona de baja con ese documento, si el `409` la ofrece (solo a `rrhh+`). */
const rehirableUuid = computed(() => rehirableEmployeeUuid(error.value))

function fieldErrors(field: string): readonly string[] {
  return isApiError(error.value) ? (error.value.fieldErrors[field] ?? []) : []
}

async function submit(): Promise<void> {
  submitting.value = true
  error.value = null

  const body: CreateEmployeeRequest = {
    department_id: departmentId.value,
    first_name: firstName.value.trim(),
    last_name: lastName.value.trim(),
    email: email.value.trim() === '' ? null : email.value.trim(),
    national_id: nationalId.value.trim() === '' ? null : nationalId.value.trim(),
    hired_at: hiredAt.value,
    ...(multilingual.value ? { locale: locale.value } : {}),
    teleworking: teleworking.value,
  }

  try {
    emit('created', await createEmployee(body))
  } catch (caught) {
    error.value = caught
  } finally {
    submitting.value = false
  }
}

const inputClass =
  'rounded-kq-sm border border-kq-border-strong bg-kq-surface-raised px-3 py-2 text-kq-text'
</script>

<template>
  <BaseDialog :title="t('employees.create.heading')" size="wide" @close="emit('close')">
    <form
      id="employee-create-form"
      class="grid gap-4 sm:grid-cols-2"
      novalidate
      @submit.prevent="submit"
    >
      <div
        v-if="rehirableUuid !== null"
        role="alert"
        data-test="rehirable-notice"
        class="rounded-kq border border-kq-warning bg-kq-warning-soft p-4 text-kq-warning sm:col-span-2"
      >
        <p class="font-semibold">{{ t('employees.create.rehirableTitle') }}</p>
        <p class="mt-1">{{ t('employees.create.rehirableAdvice') }}</p>
        <RouterLink
          :to="{ name: 'employee', params: { uuid: rehirableUuid } }"
          class="mt-2 inline-block font-semibold underline"
          data-test="rehirable-link"
        >
          {{ t('employees.create.rehirableLink') }}
        </RouterLink>
      </div>
      <ErrorNotice v-else-if="error !== null" :error="error" class="sm:col-span-2" />

      <FormField
        v-slot="field"
        :label="t('employees.fields.department')"
        :hint="t('employees.fields.departmentHint')"
        :errors="fieldErrors('department_id')"
      >
        <select
          :id="field.id"
          v-model="departmentId"
          :class="inputClass"
          :aria-describedby="field.describedBy"
        >
          <option :value="null">{{ t('employees.fields.departmentNone') }}</option>
          <option
            v-for="department of departments?.data ?? []"
            :key="department.id"
            :value="department.id"
          >
            {{ department.name }}
          </option>
        </select>
      </FormField>

      <FormField
        v-slot="field"
        :label="t('employees.fields.firstName')"
        :errors="fieldErrors('first_name')"
        required
      >
        <input
          :id="field.id"
          v-model="firstName"
          type="text"
          required
          maxlength="100"
          :class="inputClass"
          :aria-describedby="field.describedBy"
          :aria-invalid="field.invalid"
        />
      </FormField>

      <FormField
        v-slot="field"
        :label="t('employees.fields.lastName')"
        :errors="fieldErrors('last_name')"
        required
      >
        <input
          :id="field.id"
          v-model="lastName"
          type="text"
          required
          maxlength="150"
          :class="inputClass"
          :aria-describedby="field.describedBy"
          :aria-invalid="field.invalid"
        />
      </FormField>

      <FormField
        v-slot="field"
        :label="t('employees.fields.email')"
        :hint="t('employees.fields.emailHint')"
        :errors="fieldErrors('email')"
      >
        <input
          :id="field.id"
          v-model="email"
          type="email"
          maxlength="190"
          :class="inputClass"
          :aria-describedby="field.describedBy"
          :aria-invalid="field.invalid"
        />
      </FormField>

      <FormField
        v-slot="field"
        :label="t('employees.fields.nationalId')"
        :hint="t('employees.fields.nationalIdHint')"
        :errors="fieldErrors('national_id')"
      >
        <input
          :id="field.id"
          v-model="nationalId"
          type="text"
          maxlength="32"
          autocomplete="off"
          :class="inputClass"
          :aria-describedby="field.describedBy"
          :aria-invalid="field.invalid"
        />
      </FormField>

      <FormField
        v-slot="field"
        :label="t('employees.fields.hiredAt')"
        :errors="fieldErrors('hired_at')"
        required
      >
        <input
          :id="field.id"
          v-model="hiredAt"
          type="date"
          required
          :class="inputClass"
          :aria-describedby="field.describedBy"
          :aria-invalid="field.invalid"
        />
      </FormField>

      <p
        v-if="futureHire"
        role="status"
        data-test="create-future-hire"
        class="rounded-kq-sm border border-kq-warning bg-kq-warning-soft p-3 text-kq-warning sm:col-span-2"
      >
        {{ t('employees.create.futureHire', { date: formatCivilDate(hiredAt, uiLocale) }) }}
      </p>

      <FormField v-if="multilingual" v-slot="field" :label="t('employees.fields.locale')">
        <select :id="field.id" v-model="locale" :class="inputClass">
          <option v-for="option in offered" :key="option" :value="option">
            {{ t(`common.locales.${option}`) }}
          </option>
        </select>
      </FormField>

      <TeleworkingCheckbox v-model="teleworking" class="sm:col-span-2" />

      <p class="text-sm text-kq-text-muted sm:col-span-2">{{ t('employees.create.pinNotice') }}</p>
    </form>

    <template #actions>
      <button
        type="button"
        class="rounded-kq-sm border border-kq-border-strong bg-kq-surface-raised px-4 py-2 text-kq-text hover:bg-kq-surface-alt"
        @click="emit('close')"
      >
        {{ t('common.cancel') }}
      </button>
      <button
        v-aria-disabled.busy="submitting"
        type="submit"
        form="employee-create-form"
        :aria-busy="submitting"
        class="rounded-kq-sm bg-kq-primary-strong px-4 py-2 font-semibold text-kq-on-primary aria-disabled:opacity-60"
      >
        {{ submitting ? t('common.saving') : t('employees.create.submit') }}
      </button>
    </template>
  </BaseDialog>
</template>
