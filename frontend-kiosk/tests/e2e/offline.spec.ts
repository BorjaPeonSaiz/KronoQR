// CICLO OFFLINE COMPLETO (RQ-05). Es el criterio de terminado de la tarea 1.9.
//
// Escenario del doc 01 §11:
//
//   Dado un quiosco sin conexion a internet
//   Cuando un empleado ficha a las 08:00
//   Entonces el quiosco confirma el fichaje localmente
//   Y encola el evento con su scan_id y occurred_at 08:00
//   Cuando se recupera la conexion a las 09:30
//   Entonces el evento se sincroniza con occurred_at 08:00 y recorded_at 09:30
//
// Aqui se comprueba la mitad de cliente: que la cola existe EN INDEXEDDB, que
// el `occurred_at` que viaja al reconectar es el del escaneo y no el de la
// llegada, y que nada se borra sin que el servidor lo confirme.

import { expect, test } from '@playwright/test'
import { FIXTURE_PAYLOAD, stubKioskApi } from './support/kiosk'
import { announceOnline, readQueue, seedQueue, stubBatchApi } from './support/offlineQueue'

test.beforeEach(async ({ page }) => {
  await stubKioskApi(page)
})

test(
  'ficha sin red, encola en IndexedDB y consolida con el `occurred_at` original',
  { tag: ['@RF-KI-03', '@RF-KI-04', '@RQ-05'] },
  async ({ page }) => {
    // 1. Sin servidor: el envio individual no llega a ninguna parte.
    await page.route('**/api/v1/scan', async (route) => route.abort('failed'))
    const batch = await stubBatchApi(page)

    await page.goto('/')

    // 2. La confirmacion es LOCAL y honesta: ni entrada ni salida, «pendiente».
    await expect(page.getByTestId('scan-confirmation')).toHaveAttribute('data-kind', 'pending')
    await expect(page.getByTestId('confirmation-pending-badge')).toBeVisible()

    // 3. El fichaje esta escrito en IndexedDB, con su `scan_id` y su hora real.
    await expect.poll(async () => (await readQueue(page)).length).toBeGreaterThan(0)
    const queued = await readQueue(page)
    const first = queued[0]

    expect(first?.qr_payload).toBe(FIXTURE_PAYLOAD)
    expect(first?.intent).toBe('auto')
    expect(first?.scan_id).toMatch(
      /^[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/,
    )
    const occurredAt = first?.occurred_at ?? ''
    expect(Number.isNaN(Date.parse(occurredAt))).toBe(false)

    // 4. Vuelve la red.
    await page.unroute('**/api/v1/scan')
    await announceOnline(page)

    // 5. Se sincroniza por lote, con el `occurred_at` del escaneo.
    await expect.poll(() => batch.calls.length).toBeGreaterThan(0)
    const sent = batch.calls[0]?.scans.find((item) => item.scan_id === first?.scan_id)
    expect(sent?.occurred_at).toBe(occurredAt)

    // 6. Y solo AHORA desaparece de la cola: tras confirmacion del servidor.
    await expect
      .poll(async () => (await readQueue(page)).some((row) => row.scan_id === first?.scan_id))
      .toBe(false)
  },
)

test(
  'un lote desordenado se envia ordenado por `occurred_at`',
  { tag: ['@RF-KI-03', '@RQ-05'] },
  async ({ page }) => {
    // La entrada y la salida de una jornada entera atrapadas sin red. Si se
    // enviaran del reves, el servidor veria una salida sin turno abierto.
    await page.route('**/api/v1/scan', async (route) => route.abort('failed'))
    const batch = await stubBatchApi(page)

    await page.goto('/')
    await expect.poll(async () => (await readQueue(page)).length).toBeGreaterThan(0)

    await seedQueue(page, [
      {
        scan_id: '0199f13a-7c22-7b41-9e88-0c4d5e6f7a81',
        occurred_at: '2026-08-14T14:03:12.000Z',
        qr_payload: FIXTURE_PAYLOAD,
      },
      {
        scan_id: '0199f0c2-1f4a-7c3e-9b21-4d5e6f7a8b90',
        occurred_at: '2026-08-14T05:58:31.000Z',
        qr_payload: FIXTURE_PAYLOAD,
      },
    ])

    await page.unroute('**/api/v1/scan')
    await announceOnline(page)

    await expect.poll(() => batch.calls.length).toBeGreaterThan(0)
    const sent = batch.calls[0]?.scans.map((item) => item.occurred_at) ?? []
    const chronological = [...sent].sort()
    expect(sent).toEqual(chronological)
    expect(sent[0]).toBe('2026-08-14T05:58:31.000Z')

    // La clave del lote es propia, no un `scan_id` reciclado.
    expect(batch.calls[0]?.idempotencyKey).not.toBe('0199f0c2-1f4a-7c3e-9b21-4d5e6f7a8b90')
    expect(batch.calls[0]?.idempotencyKey).toMatch(
      /^[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/,
    )
  },
)

test(
  'un `503` elemento a elemento NO borra el fichaje de la cola',
  { tag: ['@RF-KI-03', '@RQ-05'] },
  async ({ page }) => {
    await page.route('**/api/v1/scan', async (route) => route.abort('failed'))
    const batch = await stubBatchApi(page, 503)

    await page.goto('/')
    await expect.poll(async () => (await readQueue(page)).length).toBeGreaterThan(0)
    const before = await readQueue(page)

    await page.unroute('**/api/v1/scan')
    await announceOnline(page)

    await expect.poll(() => batch.calls.length).toBeGreaterThan(0)

    // El servidor no decidio nada sobre este escaneo: sigue en disco.
    const after = await readQueue(page)
    expect(after.some((row) => row.scan_id === before[0]?.scan_id)).toBe(true)
  },
)

test(
  'el indicador dice cuantos fichajes quedan pendientes',
  { tag: ['@RF-KI-04'] },
  async ({ page }) => {
    await page.route('**/api/v1/scan', async (route) => route.abort('failed'))
    await stubBatchApi(page)

    await page.goto('/')

    const badge = page.getByTestId('connection-status')
    await expect(badge).toBeVisible()
    await expect
      .poll(async () => Number(await badge.getAttribute('data-pending')))
      .toBeGreaterThan(0)
    await expect(badge).toContainText('pendiente')
  },
)

test(
  'la cola sobrevive a un reinicio de la tablet',
  { tag: ['@RF-KI-03', '@RQ-05'] },
  async ({ page }) => {
    // Nada de lo que hay en memoria cuenta: lo que vale es lo que quedo escrito.
    await page.route('**/api/v1/scan', async (route) => route.abort('failed'))
    await page.route('**/api/v1/scan/batch', async (route) => route.abort('failed'))

    await page.goto('/')
    await expect.poll(async () => (await readQueue(page)).length).toBeGreaterThan(0)
    const before = await readQueue(page)

    // Reinicio: se recarga la aplicacion entera.
    await page.reload()

    const after = await readQueue(page)
    expect(after.some((row) => row.scan_id === before[0]?.scan_id)).toBe(true)
  },
)
