import { beforeEach, describe, expect, it } from 'vitest'
import { announce, announcement, clearAnnouncement } from '@/shared/ui/announcer'

beforeEach(() => {
  clearAnnouncement()
})

describe('region viva', () => {
  it('publica el ultimo cambio anunciado', () => {
    announce('Entrega registrada')

    expect(announcement.value).toBe('Entrega registrada')
  })

  it('cambia el contenido aunque el texto se repita, para que se lea dos veces', () => {
    announce('Entrega registrada')
    const first = announcement.value

    announce('Entrega registrada')

    expect(announcement.value).not.toBe(first)
    expect(announcement.value.trim()).toBe('Entrega registrada')
  })

  it('se puede vaciar al cambiar de pantalla', () => {
    announce('algo')
    clearAnnouncement()

    expect(announcement.value).toBe('')
  })
})
