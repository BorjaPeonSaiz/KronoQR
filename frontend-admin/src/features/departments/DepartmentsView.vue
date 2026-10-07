<script setup lang="ts">
// Departamentos del centro y su responsable (RF-ID-03).
//
// El responsable es un atributo del DEPARTAMENTO, no de la cuenta: asignarlo
// desplaza al anterior y es lo que da alcance a un `responsable_departamento`.
// Solo `admin` (con `accounts:*`) lo cambia; el resto de roles que llegan aqui
// lo ven en solo lectura. La policy del servidor autoriza de verdad (regla dura
// 18); a quien no es `admin` ni siquiera se le ofrece el selector.
//
// El nombre del responsable llega en `Department.manager_name`, para todos los roles:
// las cuentas solo se piden (y solo las lee `admin`) para llenar el selector.
import { announce } from '@kronoqr/web-kit/announcer'
import EmptyState from '@kronoqr/web-kit/components/EmptyState.vue'
import ErrorNotice from '@kronoqr/web-kit/components/ErrorNotice.vue'
import LoadingPanel from '@kronoqr/web-kit/components/LoadingPanel.vue'
import { isApiError } from '@kronoqr/web-kit/http'
import { useQuery, useQueryClient } from '@tanstack/vue-query'
import { computed, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { listManagementAccounts } from '@/features/accounts/accounts.api'
import { ACCOUNTS_MANAGE } from '@/features/auth/abilities'
import { useSessionStore } from '@/features/auth/session.store'
import { listDepartments, updateDepartment } from '@/shared/api/organisation.api'
import type { Department, ManagementAccount } from '@/shared/api/types'

const { t } = useI18n()
const session = useSessionStore()
const queryClient = useQueryClient()

const MANAGERS_PER_PAGE = 100

const canAssign = computed(() => session.can(ACCOUNTS_MANAGE))

async function loadActiveManagers(): Promise<ManagementAccount[]> {
  const first = await listManagementAccounts({
    page: 1,
    perPage: MANAGERS_PER_PAGE,
    role: 'responsable_departamento',
    status: 'active',
  })
  const rest = await Promise.all(
    Array.from({ length: Math.max(first.meta.total_pages - 1, 0) }, (_, index) =>
      listManagementAccounts({
        page: index + 2,
        perPage: MANAGERS_PER_PAGE,
        role: 'responsable_departamento',
        status: 'active',
      }),
    ),
  )

  return [first, ...rest].flatMap((page) => page.data)
}

const {
  data: departments,
  error,
  isPending,
} = useQuery({ queryKey: ['departments', 'all'], queryFn: () => listDepartments() })

// Cuentas activas con rol de responsable, TODAS: el contrato pagina (100 como mucho por
// pagina), asi que se piden las paginas siguientes hasta cubrir `total_pages`. Un
// selector con un recorte silencioso dejaria sin asignar a quien esta en la pagina 2.
// Las de baja no se piden: no se pueden asignar, y un responsable de baja se avisa
// aparte con su nombre (`manager_name`).
const { data: managers } = useQuery({
  queryKey: ['management-accounts', 'managers'],
  queryFn: loadActiveManagers,
  enabled: canAssign,
})

// Solo `admin` las tiene (y solo para el selector y para señalar un responsable que ya no
// esta entre las cuentas activas).
const activeManagers = computed(() => managers.value ?? [])
const activeManagerUuids = computed(
  () => new Set(activeManagers.value.map((account) => account.uuid)),
)

// La eleccion pendiente de cada fila, mientras no se guarda.
const draft = ref<Record<number, string | null>>({})
const saving = ref<number | null>(null)
const rowError = ref<{ id: number; error: unknown } | null>(null)

function current(department: Department): string | null {
  return department.manager_user_uuid ?? null
}

function chosen(department: Department): string {
  const value = department.id in draft.value ? draft.value[department.id] : current(department)

  return value ?? ''
}

function choose(department: Department, event: Event): void {
  const value = (event.target as HTMLSelectElement).value

  draft.value = { ...draft.value, [department.id]: value === '' ? null : value }
}

function changed(department: Department): boolean {
  return department.id in draft.value && draft.value[department.id] !== current(department)
}

function managerName(department: Department): string {
  const uuid = current(department)

  if (uuid === null) {
    return t('departments.noManager')
  }

  return department.manager_name ?? t('departments.hasManager')
}

function managerDeactivated(department: Department): boolean {
  const uuid = current(department)

  // Asignado, pero ya no es una cuenta activa con rol de responsable (baja o cambio de rol).
  return (
    canAssign.value &&
    managers.value !== undefined &&
    uuid !== null &&
    !activeManagerUuids.value.has(uuid)
  )
}

function inlineErrors(departmentId: number): readonly string[] {
  const failure = rowError.value

  return failure !== null && failure.id === departmentId && isApiError(failure.error)
    ? (failure.error.fieldErrors['manager_user_uuid'] ?? [])
    : []
}

function otherError(departmentId: number): unknown {
  const failure = rowError.value

  if (failure === null || failure.id !== departmentId) {
    return null
  }

  return isApiError(failure.error) && failure.error.kind === 'validation' ? null : failure.error
}

async function save(department: Department): Promise<void> {
  saving.value = department.id
  rowError.value = null

  try {
    await updateDepartment(department.id, { manager_user_uuid: draft.value[department.id] ?? null })

    draft.value = Object.fromEntries(
      Object.entries(draft.value).filter(([key]) => Number(key) !== department.id),
    )
    announce(t('departments.announce.saved', { name: department.name }))
    await queryClient.invalidateQueries({ queryKey: ['departments'] })
  } catch (caught) {
    rowError.value = { id: department.id, error: caught }
    announce(t('departments.announce.failed', { name: department.name }))
  } finally {
    saving.value = null
  }
}

const selectClass =
  'rounded-kq-sm border border-kq-border-strong bg-kq-surface-raised px-3 py-2 text-kq-text'
</script>

<template>
  <section>
    <h1 class="text-2xl font-bold">{{ t('departments.title') }}</h1>
    <p class="mt-1 text-kq-text-muted">{{ t('departments.subtitle') }}</p>

    <LoadingPanel v-if="isPending" :label="t('departments.loading')" class="mt-4" />
    <ErrorNotice v-else-if="error !== null" :error="error" class="mt-4" />

    <template v-else>
      <EmptyState
        v-if="(departments?.data ?? []).length === 0"
        class="mt-4"
        :title="t('departments.empty.title')"
        :description="t('departments.empty.description')"
      />

      <div
        v-else
        class="mt-4 overflow-x-auto rounded-kq border border-kq-border bg-kq-surface-raised shadow-kq-soft"
      >
        <table class="w-full border-collapse text-left">
          <caption class="sr-only">
            {{
              t('departments.table.caption')
            }}
          </caption>
          <thead class="border-b border-kq-border bg-kq-surface-alt">
            <tr>
              <th scope="col" class="px-3 py-2">{{ t('departments.table.name') }}</th>
              <th scope="col" class="px-3 py-2">{{ t('departments.table.manager') }}</th>
              <th v-if="canAssign" scope="col" class="px-3 py-2">
                {{ t('departments.table.assign') }}
              </th>
            </tr>
          </thead>
          <tbody>
            <tr
              v-for="department of departments?.data ?? []"
              :key="department.id"
              class="border-b border-kq-border align-top"
              :data-test="`department-${department.id}`"
            >
              <th scope="row" class="px-3 py-2 font-medium">{{ department.name }}</th>
              <td class="px-3 py-2" data-test="department-manager">
                {{ managerName(department) }}
                <span
                  v-if="managerDeactivated(department)"
                  class="block text-sm text-kq-warning"
                  data-test="department-manager-deactivated"
                >
                  {{ t('departments.deactivatedHint') }}
                </span>
              </td>
              <td v-if="canAssign" class="px-3 py-2">
                <div class="flex flex-wrap items-start gap-2">
                  <div class="flex flex-col gap-1">
                    <label :for="`manager-${department.id}`" class="sr-only">
                      {{ t('departments.assignLabel', { name: department.name }) }}
                    </label>
                    <select
                      :id="`manager-${department.id}`"
                      :value="chosen(department)"
                      :class="selectClass"
                      :aria-invalid="inlineErrors(department.id).length > 0"
                      :aria-describedby="
                        inlineErrors(department.id).length > 0
                          ? `manager-error-${department.id}`
                          : undefined
                      "
                      @change="choose(department, $event)"
                    >
                      <option value="">{{ t('departments.noManager') }}</option>
                      <option
                        v-for="account of activeManagers"
                        :key="account.uuid"
                        :value="account.uuid"
                      >
                        {{ account.name }}
                      </option>
                    </select>
                    <p
                      v-if="inlineErrors(department.id).length > 0"
                      :id="`manager-error-${department.id}`"
                      class="text-sm font-medium text-kq-danger"
                    >
                      {{ inlineErrors(department.id).join(' ') }}
                    </p>
                  </div>
                  <button
                    type="button"
                    :disabled="!changed(department) || saving === department.id"
                    :aria-busy="saving === department.id"
                    class="rounded-kq-sm bg-kq-primary-strong px-3 py-2 font-semibold text-kq-on-primary disabled:opacity-60"
                    :data-test="`save-manager-${department.id}`"
                    @click="save(department)"
                  >
                    {{ saving === department.id ? t('common.saving') : t('departments.save') }}
                  </button>
                </div>
                <p v-if="changed(department)" class="mt-1 text-sm text-kq-text-muted">
                  {{ t('departments.displaceNotice') }}
                </p>
                <ErrorNotice
                  v-if="otherError(department.id) !== null"
                  :error="otherError(department.id)"
                  class="mt-2"
                />
              </td>
            </tr>
          </tbody>
        </table>
      </div>
    </template>
  </section>
</template>
