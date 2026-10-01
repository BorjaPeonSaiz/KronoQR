// PA7-001 · WCAG 3.1.1: el `lang` del documento sigue al idioma activo de la SPA.
import { syncDocumentLang } from '@kronoqr/web-kit/documentLang'
import { nextTick } from 'vue'
import { describe, expect, it } from 'vitest'
import { createAppI18n } from '@/shared/i18n'

describe('lang del documento (PA7-001, WCAG 3.1.1)', () => {
  it('declara el idioma activo y lo actualiza al cambiarlo', async () => {
    const i18n = createAppI18n('es')
    const stop = syncDocumentLang(() => i18n.global.locale.value)

    expect(document.documentElement.lang).toBe('es')

    i18n.global.locale.value = 'en'
    await nextTick()

    expect(document.documentElement.lang).toBe('en')

    stop()
  })
})
