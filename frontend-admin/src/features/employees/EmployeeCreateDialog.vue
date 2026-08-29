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
import ErrorNotice from '@kronoqr/web-kit/components/ErrorNotice.vue'
import FormField from '@kronoqr/web-kit/components/FormField.vue'
import { todayInZone } from '@kronoqr/web-kit/datetime'
import { isApiError } from '@kronoqr/web-kit/http'
import { ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { useQuery } from '@tanstack/vue-query'
import { listDepartments } from '@/shared/api/organisation.api'
import type { CreateEmployeeRequest, EmployeeProvisioned } from '@/shared/api/types'
import BaseDialog from '@/shared/ui/BaseDialog.vue'
import { createEmployee } from './employees.api'

const props = defineProps<{ timezone: string }>()
const emit = defineEmits<{ close: []; created: [EmployeeProvisioned] }>()

const { t } = useI18n()

const departmentId = ref<number | null>(null)
const firstName = ref('')
const lastName = ref('')
const email = ref('')
const nationalId = ref('')
const locale = ref('es')
const hiredAt = ref(todayInZone(props.timezone))

// `useQuery` devuelve refs sueltas: se desestructuran para que la plantilla las
// desenvuelva sola. `departments.data` sin desestructurar seguiria siendo un Ref.
const { data: departments } = useQuery({
  queryKey: ['departments', 'all'],
  queryFn: () => listDepartments(),
})

const submitting = ref(false)
const error = ref<unknown>(null)

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
    locale: locale.value,
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
      <ErrorNotice v-if="error !== null" :error="error" class="sm:col-span-2" />

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

      <FormField v-slot="field" :label="t('employees.fields.locale')">
        <select :id="field.id" v-model="locale" :class="inputClass">
          <option value="es">{{ t('common.locales.es') }}</option>
          <option value="en">{{ t('common.locales.en') }}</option>
        </select>
      </FormField>

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
        type="submit"
        form="employee-create-form"
        :disabled="submitting"
        :aria-busy="submitting"
        class="rounded-kq-sm bg-kq-primary-strong px-4 py-2 font-semibold text-kq-on-primary disabled:opacity-60"
      >
        {{ submitting ? t('common.saving') : t('employees.create.submit') }}
      </button>
    </template>
  </BaseDialog>
</template>
