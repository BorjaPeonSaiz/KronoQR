<script setup lang="ts">
// El centro de la instalacion: nombre editable y zona horaria en solo lectura.
//
// Hay un unico centro por instalacion (ADR-040). La zona horaria decide a que
// jornada pertenece cada turno (RN-05): cambiarla tiene efecto sobre el registro
// legal y espera a su propia decision (R6-AR-02), asi que aqui solo se MUESTRA.
// Solo se monta para quien puede escribir: `GET /site` no es de lectura abierta.
import { announce } from '@kronoqr/web-kit/announcer'
import ErrorNotice from '@kronoqr/web-kit/components/ErrorNotice.vue'
import LoadingPanel from '@kronoqr/web-kit/components/LoadingPanel.vue'
import { useQuery, useQueryClient } from '@tanstack/vue-query'
import { ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { getSite, updateSite } from '@/shared/api/organisation.api'
import NameChangeDialog from './NameChangeDialog.vue'

const { t } = useI18n()
const queryClient = useQueryClient()

const { data: site, error, isPending } = useQuery({ queryKey: ['site'], queryFn: getSite })

const renaming = ref(false)

async function save(name: string): Promise<void> {
  await updateSite({ name })
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
          <dd class="font-medium" data-test="site-timezone">{{ site.timezone }}</dd>
        </div>
      </dl>
      <p class="mt-3 text-sm text-kq-text-muted" data-test="site-timezone-note">
        {{ t('site.timezoneNote') }}
      </p>
    </div>

    <NameChangeDialog
      v-if="renaming && site !== undefined"
      :title="t('site.renameTitle')"
      :field-label="t('site.name')"
      :current-name="site.name"
      :save="save"
      @saved="saved"
      @cancel="renaming = false"
    />
  </section>
</template>
