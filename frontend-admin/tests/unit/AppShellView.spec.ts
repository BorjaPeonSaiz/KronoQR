import { mount } from '@vue/test-utils'
import { describe, expect, it } from 'vitest'
import App from '@/App.vue'
import { createAppRouter } from '@/router'
import { createAppI18n } from '@/shared/i18n'
import en from '@/shared/i18n/locales/en.json'
import es from '@/shared/i18n/locales/es.json'
import AppShellView from '@/shared/ui/AppShellView.vue'

describe('AppShellView', () => {
  it('muestra los textos en espanol', () => {
    const wrapper = mount(AppShellView, {
      global: { plugins: [createAppI18n('es')] },
    })

    expect(wrapper.text()).toContain(es.app.title)
  })

  it('muestra los textos en ingles sin tocar la plantilla', () => {
    const wrapper = mount(AppShellView, {
      global: { plugins: [createAppI18n('en')] },
    })

    expect(wrapper.text()).toContain(en.app.title)
  })

  it('se pinta a traves del router desde la raiz de la aplicacion', async () => {
    const router = createAppRouter()
    await router.push('/')
    await router.isReady()

    const wrapper = mount(App, {
      global: { plugins: [router, createAppI18n('es')] },
    })

    expect(wrapper.text()).toContain(es.app.title)
  })
})
