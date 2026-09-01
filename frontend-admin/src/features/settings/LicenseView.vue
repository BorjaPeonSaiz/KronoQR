<script setup lang="ts">
// La licencia de la instalacion (RF-PD-04, RF-PD-05, ADR-018, ADR-019, ADR-028).
//
// LO PRIMERO QUE DICE ESTA PANTALLA, ANTES QUE NADA: que el registro horario no
// depende de la licencia. Quien llega aqui suele llegar por un aviso, y la
// pregunta que trae es «¿ha dejado de funcionar algo importante?». La respuesta
// —no, se sigue fichando y se sigue pudiendo exportar para la Inspeccion— tiene
// que estar arriba y sin buscarla (ADR-019, regla dura 15).
//
// Despues, en este orden: en que estado esta, que se ha degradado y desde
// cuando, cuanto se esta usando frente a lo contratado, y como activar una clave
// nueva. Es el mismo orden y el mismo contenido que `php artisan license:show`,
// a proposito: las dos superficies salen del mismo calculo del servidor y no
// pueden decir cosas distintas en una revision comercial.
//
// NO SE VALIDA EL FORMATO DE LA CLAVE aqui. Quien decide si vale es la firma
// del servidor; una expresion regular en el panel seria una segunda fuente de
// verdad que algun dia rechazaria una clave legitima recien pagada.
import { announce } from '@kronoqr/web-kit/announcer'
import ErrorNotice from '@kronoqr/web-kit/components/ErrorNotice.vue'
import FormField from '@kronoqr/web-kit/components/FormField.vue'
import { computed, onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import type { License } from '@/shared/api/types'
import { formatLicenseDay } from './license.dates'
import { useLicenseStore } from './license.store'

const { t, locale } = useI18n()
const store = useLicenseStore()

const signedKey = ref('')
const activating = ref(false)
const activationError = ref<unknown>(null)
const activated = ref(false)

const license = computed<License | null>(() => store.license)

/**
 * Los nombres de campo del `422`, en el idioma de la interfaz.
 *
 * Sin esto, el aviso diria «signed_key: …», que es el nombre del campo del
 * contrato y no lo que la persona acaba de pegar en la pantalla.
 */
const fieldLabels = computed<Record<string, string>>(() => ({
  signed_key: t('license.fields.signedKey'),
}))

/**
 * Lo que se ha perdido HOY.
 *
 * Se filtran las funcionalidades que todavia no existen en esta version: al
 * cliente no se le anuncia la perdida de algo que no ha visto nunca. El servidor
 * las marca con `implemented`.
 */
const degraded = computed(() =>
  (license.value?.data.degraded_features ?? []).filter((entry) => entry.implemented),
)

const limits = computed(() => license.value?.data.limits ?? [])
const exceeded = computed(() => limits.value.filter((limit) => limit.exceeded))

function formatDay(instant: string | null): string {
  return formatLicenseDay(instant, locale.value)
}

async function activate(): Promise<void> {
  activating.value = true
  activationError.value = null
  activated.value = false

  try {
    await store.activate(signedKey.value)
    signedKey.value = ''
    activated.value = true
    announce(t('license.activated'))
  } catch (failure) {
    activationError.value = failure
  } finally {
    activating.value = false
  }
}

onMounted(() => {
  void store.load()
})
</script>

<template>
  <section class="flex flex-col gap-6">
    <header class="flex flex-col gap-2">
      <h1 class="text-2xl font-semibold">{{ t('license.heading') }}</h1>
      <p class="max-w-3xl text-kq-text-muted">{{ t('license.intro') }}</p>
    </header>

    <!-- La promesa del producto, arriba y siempre, pase lo que pase con la
         licencia (ADR-019). Es lo que responde la pregunta con la que se llega. -->
    <p
      class="max-w-3xl rounded-kq border border-kq-border bg-kq-surface-alt p-4 text-kq-text"
      role="note"
      data-test="never-degraded"
    >
      {{ t('license.neverDegraded') }}
    </p>

    <p v-if="store.loading" class="text-kq-text-muted" data-test="loading">
      {{ t('license.loading') }}
    </p>

    <ErrorNotice v-if="store.error !== null" :error="store.error" />

    <template v-if="license !== null">
      <!-- Estado -->
      <div
        class="flex max-w-3xl flex-col gap-2 rounded-kq border p-4"
        :class="{
          'border-kq-border bg-kq-surface-raised': license.data.severity === 'none',
          'border-kq-warning bg-kq-warning-soft': license.data.severity === 'warning',
          'border-kq-danger bg-kq-danger-soft': license.data.severity === 'critical',
        }"
        data-test="state"
        :data-state="license.data.state"
        :data-severity="license.data.severity"
      >
        <p class="font-semibold">{{ t(`license.states.${license.data.state}`) }}</p>
        <p data-test="state-detail">
          {{
            t(`license.detail.${license.data.state}`, {
              until: formatDay(license.data.valid_until),
              from: formatDay(license.data.valid_from),
              days: license.data.days_until_expiry ?? 0,
              elapsed: license.data.days_since_expiry ?? 0,
              warningDays: license.meta.expiry_warning_days,
            })
          }}
        </p>
        <p v-if="license.data.rejection_reason !== null" data-test="rejection">
          {{ t(`license.rejections.${license.data.rejection_reason}`) }}
        </p>
      </div>

      <!-- Que se contrato -->
      <div class="flex max-w-3xl flex-col gap-2" data-test="contract">
        <h2 class="text-lg font-semibold">{{ t('license.contract') }}</h2>
        <dl class="grid gap-2 sm:grid-cols-2">
          <div>
            <dt class="text-kq-text-muted">{{ t('license.fields.customer') }}</dt>
            <dd data-test="customer">{{ license.data.customer_name ?? '—' }}</dd>
          </div>
          <div>
            <dt class="text-kq-text-muted">{{ t('license.fields.plan') }}</dt>
            <dd data-test="plan">{{ license.data.plan ?? '—' }}</dd>
          </div>
          <div>
            <dt class="text-kq-text-muted">{{ t('license.fields.validity') }}</dt>
            <dd data-test="validity">
              {{ formatDay(license.data.valid_from) }} – {{ formatDay(license.data.valid_until) }}
            </dd>
          </div>
          <div>
            <dt class="text-kq-text-muted">{{ t('license.fields.fingerprint') }}</dt>
            <!-- La huella, nunca la clave: es lo que sirve para confirmar por
                 telefono cual esta activada. -->
            <dd data-test="fingerprint">{{ license.data.key_fingerprint ?? '—' }}</dd>
          </div>
        </dl>
      </div>

      <!-- Contratado frente a real (ADR-028) -->
      <div class="flex max-w-3xl flex-col gap-2" data-test="limits">
        <h2 class="text-lg font-semibold">{{ t('license.usage') }}</h2>
        <table class="w-full border-collapse text-left">
          <thead>
            <tr class="border-b border-kq-border">
              <th scope="col" class="py-2">{{ t('license.usageColumns.limit') }}</th>
              <th scope="col" class="py-2">{{ t('license.usageColumns.contracted') }}</th>
              <th scope="col" class="py-2">{{ t('license.usageColumns.actual') }}</th>
            </tr>
          </thead>
          <tbody>
            <tr
              v-for="limit of limits"
              :key="limit.limit"
              class="border-b border-kq-border"
              :data-test="`limit-${limit.limit}`"
              :data-exceeded="limit.exceeded"
            >
              <th scope="row" class="py-2 font-normal">{{ t(`license.limits.${limit.limit}`) }}</th>
              <td class="py-2">{{ limit.contracted ?? '—' }}</td>
              <td class="py-2" :class="{ 'font-semibold text-kq-warning': limit.exceeded }">
                {{ limit.actual }}
                <span v-if="limit.exceeded"
                  >({{ t('license.over', { excess: limit.excess }) }})</span
                >
              </td>
            </tr>
          </tbody>
        </table>
        <!-- Lo que un exceso NO significa. Quien lo ve esta buscando una
             explicacion a un problema, y este no lo es (ADR-028). -->
        <p v-if="exceeded.length > 0" class="text-kq-text-muted" data-test="excess-note">
          {{ t('license.excessNote') }}
        </p>
      </div>

      <!-- Que esta degradado y desde cuando -->
      <div class="flex max-w-3xl flex-col gap-2" data-test="degraded">
        <h2 class="text-lg font-semibold">{{ t('license.degraded') }}</h2>
        <p v-if="degraded.length === 0" data-test="degraded-none">
          {{ t('license.degradedNone') }}
        </p>
        <ul v-else class="flex list-disc flex-col gap-1 pl-5">
          <li
            v-for="entry of degraded"
            :key="entry.feature"
            :data-test="`degraded-${entry.feature}`"
          >
            {{ t(`license.features.${entry.feature}`) }} —
            {{
              t(`license.restrictions.${entry.restriction ?? 'unknown'}`, {
                since: formatDay(entry.since),
              })
            }}
          </li>
        </ul>
      </div>

      <!-- Activar una clave -->
      <form class="flex max-w-3xl flex-col gap-4" novalidate @submit.prevent="activate">
        <h2 class="text-lg font-semibold">{{ t('license.activate') }}</h2>

        <ErrorNotice
          v-if="activationError !== null"
          :error="activationError"
          :field-labels="fieldLabels"
        />

        <p v-if="activated" class="text-kq-success" role="status" data-test="activated">
          {{ t('license.activated') }}
        </p>

        <FormField :label="t('license.fields.signedKey')" :hint="t('license.hints.signedKey')">
          <template #default="{ id, describedBy }">
            <textarea
              :id="id"
              v-model="signedKey"
              rows="4"
              spellcheck="false"
              autocomplete="off"
              data-test="signed-key"
              :aria-describedby="describedBy"
              class="rounded-kq-sm border border-kq-border-strong bg-kq-surface-raised px-3 py-2 font-mono text-sm text-kq-text"
            ></textarea>
          </template>
        </FormField>

        <div>
          <button
            type="submit"
            class="rounded-kq-sm bg-kq-primary-strong px-4 py-2 text-kq-on-primary disabled:opacity-60"
            :disabled="activating || signedKey.trim() === ''"
            data-test="activate"
          >
            {{ activating ? t('license.activating') : t('license.activate') }}
          </button>
        </div>
      </form>
    </template>
  </section>
</template>
