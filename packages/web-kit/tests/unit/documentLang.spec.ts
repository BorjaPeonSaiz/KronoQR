// PA7-001 · WCAG 3.1.1: el `lang` del documento sigue al idioma activo.
import { nextTick, ref } from 'vue'
import { describe, expect, it } from 'vitest'
import { syncDocumentLang } from '../../src/documentLang'

describe('syncDocumentLang (PA7-001, WCAG 3.1.1)', () => {
  it('fija el idioma activo de inmediato', () => {
    const target = { lang: 'es' }

    syncDocumentLang(() => 'en', target)

    expect(target.lang).toBe('en')
  })

  it('sigue los cambios de idioma', async () => {
    const locale = ref('es')
    const target = { lang: '' }

    syncDocumentLang(() => locale.value, target)
    expect(target.lang).toBe('es')

    locale.value = 'en'
    await nextTick()

    expect(target.lang).toBe('en')
  })

  it('no vacia el atributo si el idioma llega vacio', async () => {
    const locale = ref('en')
    const target = { lang: '' }

    syncDocumentLang(() => locale.value, target)
    locale.value = ''
    await nextTick()

    expect(target.lang).toBe('en')
  })

  it('deja de seguir al detenerlo y usa el elemento raiz por defecto', async () => {
    const locale = ref('en')
    const stop = syncDocumentLang(() => locale.value)

    expect(document.documentElement.lang).toBe('en')

    stop()
    locale.value = 'es'
    await nextTick()

    expect(document.documentElement.lang).toBe('en')
  })
})
