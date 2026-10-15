<script setup lang="ts">
// El centro de la instalacion: nombre editable y zona horaria, editable hasta el primer fichaje.
//
// Hay un unico centro por instalacion (ADR-040). La zona horaria decide a que
// jornada pertenece cada turno (RN-05), asi que RN-23 (ADR-056) la deja fija con el
// primer fichaje: mientras `timezone_locked` es `false` se puede cambiar desde un
// dialogo con su «antes → despues»; cuando es `true` solo se MUESTRA, con la nota
// que explica por que y como se corrige una zona mal puesta (orden de consola en el
// servidor). Si el primer fichaje llega mientras el dialogo esta abierto, el `409`
// `site-time-zone-locked` se explica y se relee `['site']`.
// Solo se monta para quien puede escribir: `GET /site` no es de lectura abierta.
import { announce } from '@kronoqr/web-kit/announcer'
import ErrorNotice from '@kronoqr/web-kit/components/ErrorNotice.vue'
import LoadingPanel from '@kronoqr/web-kit/components/LoadingPanel.vue'
import { useQuery, useQueryClient } from '@tanstack/vue-query'
import { ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { getSite, updateSite } from '@/shared/api/organisation.api'
import NameChangeDialog from './NameChangeDialog.vue'
import TimezoneChangeDialog from './TimezoneChangeDialog.vue'

const { t } = useI18n()
const queryClient = useQueryClient()

const { data: site, error, isPending } = useQuery({ queryKey: ['site'], queryFn: getSite })

const renaming = ref(false)
const changingTimezone = ref(false)
const lockedWhileEditing = ref(false)

async function save(name: string): Promise<void> {
  await updateSite({ name })
}

async function saveTimezone(timezone: string): Promise<void> {
  await updateSite({ timezone })
}

async function timezoneSaved(timezone: string): Promise<void> {
  changingTimezone.value = false
  lockedWhileEditing.value = false
  announce(t('site.announce.timezoneSaved', { timezone }))
  await queryClient.invalidateQueries({ queryKey: ['site'] })
}

async function timezoneLocked(): Promise<void> {
  changingTimezone.value = false
  lockedWhileEditing.value = true
  announce(t('site.errors.timezoneLocked'))
  await queryClient.invalidateQueries({ queryKey: ['site'] })
}

async function saved(name: string): Promise<void> {
  renaming.value = false
  announce(t('site.announce.saved', { name }))
  await queryClient.invalidateQueries({ queryKey: ['site'] })
}
</script>

<template>
  <section aria-labelledby="site-heading" class="mt-6" data-test="site-section">
    <h2 id="site-heading" class="text-xl font-semibold">{{ t('site.heading') }}</h2>

    <LoadingPanel v-if="isPending" :label="t('site.loading')" class="mt-3" />
    <ErrorNotice v-else-if="error !== null" :error="error" class="mt-3" />

    <div
      v-else-if="site !== undefined"
      class="mt-3 rounded-kq border border-kq-border bg-kq-surface-raised p-4 shadow-kq-soft"
    >
      <dl class="grid gap-3 sm:grid-cols-2">
        <div>
          <dt class="text-sm text-kq-text-muted">{{ t('site.name') }}</dt>
          <dd class="flex flex-wrap items-center gap-3 font-medium" data-test="site-name">
            <span>{{ site.name }}</span>
            <button
              type="button"
              class="rounded-kq-sm border border-kq-border-strong bg-kq-surface-raised px-3 py-1 text-kq-text hover:bg-kq-surface-alt"
              data-test="site-rename"
              @click="renaming = true"
            >
              {{ t('site.rename') }}
            </button>
          </dd>
        </div>
        <div>
          <dt class="text-sm text-kq-text-muted">{{ t('site.timezone') }}</dt>
          <dd class="flex flex-wrap items-center gap-3 font-medium">
            <span data-test="site-timezone">{{ site.timezone }}</span>
            <button
              v-if="!site.timezone_locked"
              type="button"
              class="rounded-kq-sm border border-kq-border-strong bg-kq-surface-raised px-3 py-1 text-kq-text hover:bg-kq-surface-alt"
              data-test="site-change-timezone"
              @click="changingTimezone = true"
            >
              {{ t('site.changeTimezone') }}
            </button>
          </dd>
        </div>
      </dl>
      <p
        v-if="lockedWhileEditing"
        class="mt-3 text-sm font-medium text-kq-danger"
        data-test="site-timezone-locked-error"
      >
        {{ t('site.errors.timezoneLocked') }}
      </p>
      <p class="mt-3 text-sm text-kq-text-muted" data-test="site-timezone-note">
        {{ site.timezone_locked ? t('site.timezoneLocked') : t('site.timezoneOpen') }}
      </p>
    </div>

    <NameChangeDialog
      v-if="renaming && site !== undefined"
      :title="t('site.renameTitle')"
      :failed-announcement="t('site.announce.failed')"
      :field-label="t('site.name')"
      :current-name="site.name"
      :save="save"
      @saved="saved"
      @cancel="renaming = false"
    />

    <TimezoneChangeDialog
      v-if="changingTimezone && site !== undefined && !site.timezone_locked"
      :current-timezone="site.timezone"
      :save="saveTimezone"
      @saved="timezoneSaved"
      @locked="timezoneLocked"
      @cancel="changingTimezone = false"
    />
  </section>
</template>
