<script setup lang="ts">
// Cuentas de gestion (RF-ID-10): quien puede entrar en el panel, con que rol y
// en que estado, y las acciones de administracion (alta, baja, restablecer
// contrasena y segundo factor).
//
// Es la lista que la guia de endurecimiento manda revisar cada trimestre y en
// cada baja de personal. Por eso incluye las cuentas dadas de baja salvo que se
// filtre por estado: una baja no se borra y sigue siendo el autor de lo que
// firmo (regla dura 5).
//
// Solo `admin` (`accounts:*`). La policy del servidor es la que autoriza de
// verdad (regla dura 18); aqui la interfaz solo evita ofrecer lo que acabaria
// en `403`. Los datos que se muestran —nombre, correo, rol, estado— son los
// que hacen falta para decidir si una cuenta debe seguir ahi; nunca un secreto.
//
// Volumen: una instalacion tiene decenas de cuentas, no miles; se pagina en el
// servidor igualmente (mismo contrato que la plantilla) y los filtros viven en
// la query string de la ruta.
import { announce } from '@kronoqr/web-kit/announcer'
import EmptyState from '@kronoqr/web-kit/components/EmptyState.vue'
import ErrorNotice from '@kronoqr/web-kit/components/ErrorNotice.vue'
import LoadingPanel from '@kronoqr/web-kit/components/LoadingPanel.vue'
import { FALLBACK_TIMEZONE, formatInstantWithZone } from '@kronoqr/web-kit/datetime'
import { keepPreviousData, useQuery } from '@tanstack/vue-query'
import { computed, onUnmounted, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { RouterLink, useRoute, useRouter } from 'vue-router'
import { useSessionStore } from '@/features/auth/session.store'
import { getSite, listDepartments } from '@/shared/api/organisation.api'
import type {
  ManagementAccount,
  ManagementAccountStatus,
  ManagementRole,
  TemporaryPasswordIssued,
} from '@/shared/api/types'
import PaginationBar from '@/shared/ui/PaginationBar.vue'
import AccountActionDialog from './AccountActionDialog.vue'
import type { AccountActionKind } from './AccountActionDialog.vue'
import CreateAccountDialog from './CreateAccountDialog.vue'
import TemporaryPasswordDialog from './TemporaryPasswordDialog.vue'
import { ACCOUNT_LIST_PER_PAGE, listManagementAccounts } from './accounts.api'

const SEARCH_DEBOUNCE_MS = 300
const STATUSES: readonly ManagementAccountStatus[] = ['active', 'deactivated']
const ROLES: readonly ManagementRole[] = ['admin', 'rrhh', 'responsable_departamento', 'auditor']

interface Revealed {
  password: TemporaryPasswordIssued
  account: ManagementAccount
  reason: 'created' | 'reset'
}

const { t, locale } = useI18n()
const route = useRoute()
const router = useRouter()
const session = useSessionStore()

function queryParam(key: string): string {
  const raw = route.query[key]

  return typeof raw === 'string' ? raw : ''
}

const parsedPage = Number.parseInt(queryParam('page'), 10)
const page = ref(Number.isFinite(parsedPage) && parsedPage > 0 ? parsedPage : 1)

const initialQ = queryParam('q')
// Lo que se teclea y lo que se manda (con debounce) van separados: una peticion
// por tecla no es una busqueda, es ruido en el servidor.
const searchInput = ref(initialQ)
const qFilter = ref(initialQ)

const initialStatus = queryParam('status')
const statusFilter = ref<ManagementAccountStatus | ''>(
  (STATUSES as readonly string[]).includes(initialStatus)
    ? (initialStatus as ManagementAccountStatus)
    : '',
)

const initialRole = queryParam('role')
const roleFilter = ref<ManagementRole | ''>(
  (ROLES as readonly string[]).includes(initialRole) ? (initialRole as ManagementRole) : '',
)

const creating = ref(false)
const action = ref<{ kind: AccountActionKind; account: ManagementAccount } | null>(null)
// La contrasena temporal vive AQUI y solo mientras el dialogo esta abierto: no
// entra en la tienda, ni en la cache de consultas, ni en ningun almacenamiento.
const revealed = ref<Revealed | null>(null)

// Zona horaria del centro (ADR-040): el ultimo acceso se muestra en ella y con la
// zona escrita, nunca en la del navegador de quien mira.
const { data: site } = useQuery({ queryKey: ['site'], queryFn: getSite })
const timezone = computed(() => site.value?.timezone ?? FALLBACK_TIMEZONE)

// Departamentos, para avisar de un responsable que no lo es de ninguno: su rol existe pero
// no alcanza a nadie (RF-ID-03). El alcance lo da el departamento, no la cuenta.
const { data: departments } = useQuery({
  queryKey: ['departments', 'all'],
  queryFn: () => listDepartments(),
})
const managerUuids = computed(
  () =>
    new Set(
      (departments.value?.data ?? []).flatMap((department) =>
        department.manager_user_uuid == null ? [] : [department.manager_user_uuid],
      ),
    ),
)

/** Responsable de departamento activo que no dirige ninguno. Solo se avisa con los departamentos ya cargados. */
function reachesNobody(account: ManagementAccount): boolean {
  return (
    departments.value !== undefined &&
    account.status === 'active' &&
    account.roles.includes('responsable_departamento') &&
    !managerUuids.value.has(account.uuid)
  )
}

const query = computed(() => ({
  page: page.value,
  perPage: ACCOUNT_LIST_PER_PAGE,
  ...(qFilter.value === '' ? {} : { q: qFilter.value }),
  ...(statusFilter.value === '' ? {} : { status: statusFilter.value }),
  ...(roleFilter.value === '' ? {} : { role: roleFilter.value }),
}))

const {
  data: accounts,
  error,
  isPending,
  isFetching,
  refetch,
} = useQuery({
  queryKey: computed(() => ['management-accounts', query.value] as const),
  queryFn: () => listManagementAccounts(query.value),
  placeholderData: keepPreviousData,
})

const rows = computed(() => accounts.value?.data ?? [])
const meta = computed(() => accounts.value?.meta ?? null)
const hasFilters = computed(
  () => qFilter.value !== '' || statusFilter.value !== '' || roleFilter.value !== '',
)

watch(
  () => meta.value?.total_pages,
  (totalPages) => {
    if (totalPages !== undefined && page.value > totalPages) {
      page.value = Math.max(totalPages, 1)
    }
  },
)

watch(
  () => meta.value?.total,
  (total) => {
    if (total !== undefined) {
      announce(t('accounts.announce.results', { count: total }))
    }
  },
)

function resetToFirstPage(): void {
  page.value = 1
}

let searchDebounce: ReturnType<typeof setTimeout> | undefined

watch(searchInput, (value) => {
  if (searchDebounce !== undefined) {
    clearTimeout(searchDebounce)
  }

  searchDebounce = setTimeout(() => {
    qFilter.value = value.trim()
    resetToFirstPage()
  }, SEARCH_DEBOUNCE_MS)
})

onUnmounted(() => {
  if (searchDebounce !== undefined) {
    clearTimeout(searchDebounce)
  }
})

function clearSearch(): void {
  if (searchDebounce !== undefined) {
    clearTimeout(searchDebounce)
  }

  searchInput.value = ''
  qFilter.value = ''
  resetToFirstPage()
}

function clearFilters(): void {
  clearSearch()
  statusFilter.value = ''
  roleFilter.value = ''
}

watch([qFilter, statusFilter, roleFilter, page], ([q, status, role, currentPage]) => {
  const nextQuery: Record<string, string> = {}

  if (q !== '') {
    nextQuery['q'] = q
  }

  if (status !== '') {
    nextQuery['status'] = status
  }

  if (role !== '') {
    nextQuery['role'] = role
  }

  if (currentPage !== 1) {
    nextQuery['page'] = String(currentPage)
  }

  void router.replace({ query: nextQuery })
})

function isOwn(account: ManagementAccount): boolean {
  return account.uuid === session.user?.uuid
}

/** Por que una accion no esta disponible sobre esta fila, o `null` si lo esta. */
function blockedReason(account: ManagementAccount, kind: AccountActionKind): string | null {
  if (isOwn(account)) {
    return kind === 'resetPassword' ? t('accounts.blocked.ownPassword') : t('accounts.blocked.own')
  }

  if (kind === 'resetTwoFactor' && !account.two_factor_enabled) {
    return t('accounts.blocked.noTwoFactor')
  }

  return null
}

function open(kind: AccountActionKind, account: ManagementAccount): void {
  // No se usa `disabled` nativo: un boton deshabilitado no recibe el foco y la
  // explicacion quedaria fuera del alcance del teclado y del lector de pantalla.
  if (blockedReason(account, kind) !== null) {
    return
  }

  action.value = { kind, account }
}

function closeAction(): void {
  action.value = null
}

function onCreated(result: {
  account: ManagementAccount
  temporary_password: TemporaryPasswordIssued
}): void {
  creating.value = false
  revealed.value = {
    password: result.temporary_password,
    account: result.account,
    reason: 'created',
  }
  announce(t('accounts.announce.created', { name: result.account.name }))
  void refetch()
}

function onDeactivated(account: ManagementAccount): void {
  closeAction()
  announce(t('accounts.announce.deactivated', { name: account.name }))
  void refetch()
}

function onTwoFactorReset(account: ManagementAccount): void {
  closeAction()
  announce(t('accounts.announce.twoFactorReset', { name: account.name }))
  void refetch()
}

function onPasswordReset(account: ManagementAccount, password: TemporaryPasswordIssued): void {
  closeAction()
  revealed.value = { password, account, reason: 'reset' }
  announce(t('accounts.announce.passwordReset', { name: account.name }))
  void refetch()
}

function onGone(): void {
  closeAction()
  announce(t('accounts.announce.gone'))
  void refetch()
}

function closeReveal(): void {
  // Al soltar la referencia, la contrasena en claro deja de existir en el navegador.
  revealed.value = null
}

const selectClass =
  'rounded-kq-sm border border-kq-border-strong bg-kq-surface-raised px-3 py-2 text-kq-text'
const actionClass =
  'rounded-kq-sm border border-kq-border-strong bg-kq-surface-raised px-2 py-1 text-sm text-kq-text hover:bg-kq-surface-alt aria-disabled:opacity-60'
</script>

<template>
  <section>
    <div class="flex flex-wrap items-center justify-between gap-4">
      <div>
        <h1 class="text-2xl font-bold">{{ t('accounts.title') }}</h1>
        <p class="mt-1 text-kq-text-muted">{{ t('accounts.subtitle') }}</p>
      </div>
      <button
        type="button"
        class="rounded-kq-sm bg-kq-primary-strong px-4 py-2 font-semibold text-kq-on-primary"
        data-test="create-account"
        @click="creating = true"
      >
        {{ t('accounts.actions.create') }}
      </button>
    </div>

    <form class="mt-4 flex flex-wrap items-end gap-4" role="search" @submit.prevent>
      <fieldset class="flex flex-wrap items-end gap-4 border-0 p-0">
        <legend class="sr-only">{{ t('accounts.filters.legend') }}</legend>

        <div class="flex flex-col gap-1">
          <label for="accounts-search-filter" class="font-medium">
            {{ t('accounts.filters.search') }}
          </label>
          <div class="flex items-center gap-2">
            <input
              id="accounts-search-filter"
              v-model="searchInput"
              type="search"
              maxlength="100"
              :placeholder="t('accounts.filters.searchPlaceholder')"
              :class="selectClass"
              @keydown.esc="clearSearch"
            />
            <button
              v-if="searchInput !== ''"
              type="button"
              :class="selectClass"
              class="px-2 py-2 text-sm"
              @click="clearSearch"
            >
              <span aria-hidden="true">&times;</span>
              <span class="sr-only">{{ t('common.filters.clearSearch') }}</span>
            </button>
          </div>
        </div>

        <div class="flex flex-col gap-1">
          <label for="accounts-status-filter" class="font-medium">
            {{ t('accounts.filters.status') }}
          </label>
          <select
            id="accounts-status-filter"
            v-model="statusFilter"
            :class="selectClass"
            @change="resetToFirstPage"
          >
            <option value="">{{ t('accounts.filters.statusAll') }}</option>
            <option v-for="status of STATUSES" :key="status" :value="status">
              {{ t(`accounts.status.${status}`) }}
            </option>
          </select>
        </div>

        <div class="flex flex-col gap-1">
          <label for="accounts-role-filter" class="font-medium">
            {{ t('accounts.filters.role') }}
          </label>
          <select
            id="accounts-role-filter"
            v-model="roleFilter"
            :class="selectClass"
            @change="resetToFirstPage"
          >
            <option value="">{{ t('accounts.filters.roleAll') }}</option>
            <option v-for="role of ROLES" :key="role" :value="role">
              {{ t(`app.roles.${role}`) }}
            </option>
          </select>
        </div>
      </fieldset>

      <button
        v-if="hasFilters"
        type="button"
        class="rounded-kq-sm border border-kq-border-strong bg-kq-surface-raised px-3 py-2 text-kq-text hover:bg-kq-surface-alt"
        @click="clearFilters"
      >
        {{ t('common.filters.clear') }}
      </button>
    </form>

    <LoadingPanel v-if="isPending" :label="t('accounts.loading')" class="mt-4" />

    <ErrorNotice v-else-if="error !== null" :error="error" class="mt-4" />

    <template v-else>
      <EmptyState
        v-if="rows.length === 0"
        class="mt-4"
        :title="t('accounts.empty.title')"
        :description="hasFilters ? t('accounts.empty.filtered') : t('accounts.empty.description')"
      />

      <div
        v-else
        class="mt-4 overflow-x-auto rounded-kq border border-kq-border bg-kq-surface-raised shadow-kq-soft"
      >
        <table class="w-full border-collapse text-left">
          <caption class="sr-only">
            {{
              t('accounts.table.caption', { timezone })
            }}
          </caption>
          <thead class="border-b border-kq-border bg-kq-surface-alt">
            <tr>
              <th scope="col" class="px-3 py-2">{{ t('accounts.table.name') }}</th>
              <th scope="col" class="px-3 py-2">{{ t('accounts.table.email') }}</th>
              <th scope="col" class="px-3 py-2">{{ t('accounts.table.role') }}</th>
              <th scope="col" class="px-3 py-2">{{ t('accounts.table.status') }}</th>
              <th scope="col" class="px-3 py-2">{{ t('accounts.table.twoFactor') }}</th>
              <th scope="col" class="px-3 py-2">{{ t('accounts.table.password') }}</th>
              <th scope="col" class="px-3 py-2">
                {{ t('accounts.table.lastLogin') }}
                <span class="block text-xs font-normal text-kq-text-muted">{{ timezone }}</span>
              </th>
              <th scope="col" class="px-3 py-2">{{ t('accounts.table.actions') }}</th>
            </tr>
          </thead>
          <tbody>
            <tr
              v-for="account of rows"
              :key="account.uuid"
              class="border-b border-kq-border align-top"
              :data-test="`account-${account.uuid}`"
            >
              <th scope="row" class="px-3 py-2 font-medium">
                {{ account.name }}
                <span v-if="isOwn(account)" class="block text-xs font-normal text-kq-text-muted">
                  {{ t('accounts.table.you') }}
                </span>
              </th>
              <td class="px-3 py-2">{{ account.email }}</td>
              <td class="px-3 py-2">
                {{ account.roles.map((role) => t(`app.roles.${role}`)).join(', ') }}
                <span
                  v-if="reachesNobody(account)"
                  class="block text-sm text-kq-warning"
                  data-test="reaches-nobody"
                >
                  {{ t('accounts.table.reachesNobody') }}
                  <RouterLink :to="{ name: 'departments' }" class="underline">
                    {{ t('accounts.table.assignDepartment') }}
                  </RouterLink>
                </span>
              </td>
              <td class="px-3 py-2">{{ t(`accounts.status.${account.status}`) }}</td>
              <td class="px-3 py-2">
                {{ t(`accounts.twoFactor.${account.two_factor_enabled ? 'enabled' : 'disabled'}`) }}
              </td>
              <td class="px-3 py-2">{{ t(`accounts.password.${account.password_status}`) }}</td>
              <td class="px-3 py-2">
                {{
                  account.last_login_at === null
                    ? t('accounts.table.neverLoggedIn')
                    : formatInstantWithZone(account.last_login_at, timezone, locale)
                }}
              </td>
              <td class="px-3 py-2">
                <p v-if="account.status === 'deactivated'" class="text-kq-text-muted">
                  {{ t('accounts.table.noActions') }}
                </p>
                <ul v-else class="flex flex-col items-start gap-1.5">
                  <li
                    v-for="kind of ['resetPassword', 'resetTwoFactor', 'deactivate'] as const"
                    :key="kind"
                  >
                    <button
                      type="button"
                      :class="actionClass"
                      :aria-disabled="blockedReason(account, kind) !== null"
                      :aria-describedby="
                        blockedReason(account, kind) === null
                          ? undefined
                          : `blocked-${kind}-${account.uuid}`
                      "
                      :aria-label="t(`accounts.actions.${kind}.rowLabel`, { name: account.name })"
                      :data-test="`${kind}-${account.uuid}`"
                      @click="open(kind, account)"
                    >
                      {{ t(`accounts.actions.${kind}.button`) }}
                    </button>
                    <span
                      v-if="blockedReason(account, kind) !== null"
                      :id="`blocked-${kind}-${account.uuid}`"
                      class="mt-0.5 block max-w-56 text-xs text-kq-text-muted"
                    >
                      {{ blockedReason(account, kind) }}
                    </span>
                  </li>
                </ul>
              </td>
            </tr>
          </tbody>
        </table>
      </div>

      <PaginationBar
        v-if="meta !== null"
        :page="meta.page"
        :per-page="meta.per_page"
        :total="meta.total"
        :total-pages="meta.total_pages"
        :fetching="isFetching"
        :label="t('accounts.pagination.label')"
        @update:page="page = $event"
      />
    </template>

    <CreateAccountDialog v-if="creating" @close="creating = false" @created="onCreated" />

    <AccountActionDialog
      v-if="action !== null"
      :kind="action.kind"
      :account="action.account"
      @cancel="closeAction"
      @deactivated="onDeactivated"
      @two-factor-reset="onTwoFactorReset"
      @password-reset="onPasswordReset"
      @gone="onGone"
    />

    <TemporaryPasswordDialog
      v-if="revealed !== null"
      :password="revealed.password"
      :account-name="revealed.account.name"
      :account-email="revealed.account.email"
      :timezone="timezone"
      :reason="revealed.reason"
      @acknowledged="closeReveal"
    />
  </section>
</template>
