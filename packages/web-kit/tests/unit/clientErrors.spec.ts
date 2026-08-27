// Captura de errores del panel y del portal (regla dura 21: sin PII).
//
// El saneado no es una buena intencion: cada prueba de este bloque rompe la
// garantia que dice comprobar si la implementacion la pierde.
import { describe, expect, it, vi } from 'vitest'
import { createApp, type ComponentPublicInstance } from 'vue'
import {
  createWebErrorReporter,
  installGlobalErrorCapture,
  sanitizeContext,
} from '../../src/clientErrors'

const fixedNow = () => new Date('2026-08-27T06:00:00Z')

// Raiz minima para montar una `App` real de Vue en las pruebas de enganche.
const StubRoot = { render: () => null }

describe('sanitizeContext', () => {
  it('descarta por nombre de clave todo lo que pueda llevar una persona dentro', () => {
    const out = sanitizeContext({
      employee_name: 'Amrani',
      qr_payload: 'FH1.a3.xxx.yyy',
      session_token: 'abc',
      pin_digits: '123456',
      email_address: 'a@b.c',
      status: 500,
    })

    expect(out).toEqual({ status: 500 })
  })

  it('descarta objetos, arrays, funciones y null: la via de colar una estructura entera', () => {
    const out = sanitizeContext({
      response: { name: 'Amrani' },
      list: [1, 2, 3],
      fn: () => 'x',
      nothing: null,
      ok: true,
    })

    expect(out).toEqual({ ok: true })
  })

  it('trunca las cadenas y descarta numeros no finitos', () => {
    const out = sanitizeContext({ message: 'x'.repeat(500), rate: Infinity })

    expect(out.rate).toBeUndefined()
    expect(out.message).toHaveLength(200)
  })

  it('acota el numero de claves', () => {
    const big: Record<string, number> = {}
    for (let i = 0; i < 40; i++) big[`k${i}`] = i

    expect(Object.keys(sanitizeContext(big))).toHaveLength(12)
  })
})

describe('createWebErrorReporter', () => {
  it('acumula eventos con app, version e instante, y los sanea', () => {
    const reporter = createWebErrorReporter({ app: 'admin', appVersion: '1.0.0', now: fixedNow })

    reporter.report('web.vue_error', { message: 'boom', token: 'secreto' })

    expect(reporter.pending()).toEqual([
      {
        code: 'web.vue_error',
        occurred_at: '2026-08-27T06:00:00.000Z',
        app: 'admin',
        app_version: '1.0.0',
        context: { message: 'boom' },
      },
    ])
  })

  it('tiene techo: los mas antiguos se descartan, no la memoria', () => {
    const reporter = createWebErrorReporter({
      app: 'portal',
      appVersion: '1.0.0',
      now: fixedNow,
      maxBuffered: 3,
    })

    for (let i = 0; i < 5; i++) reporter.report('web.unhandled_error', { seq: i })

    expect(reporter.size()).toBe(3)
    expect(reporter.pending()[0]?.context).toEqual({ seq: 2 })
  })

  it('acknowledge descarta solo lo confirmado', () => {
    const reporter = createWebErrorReporter({ app: 'admin', appVersion: '1.0.0', now: fixedNow })
    reporter.report('web.unhandled_error', { seq: 0 })
    reporter.report('web.unhandled_error', { seq: 1 })

    reporter.acknowledge(1)

    expect(reporter.size()).toBe(1)
    expect(reporter.pending()[0]?.context).toEqual({ seq: 1 })
  })
})

describe('installGlobalErrorCapture', () => {
  it('captura los errores del arbol de Vue sin silenciarlos', () => {
    const reporter = createWebErrorReporter({ app: 'admin', appVersion: '1.0.0', now: fixedNow })
    const app = createApp(StubRoot)
    const consoleError = vi.spyOn(console, 'error').mockImplementation(() => undefined)

    installGlobalErrorCapture(app, reporter)
    const instance = { $options: { name: 'WorkdayTable' } } as unknown as ComponentPublicInstance
    app.config.errorHandler?.(new RangeError('sin datos'), instance, 'render')

    expect(reporter.pending()[0]).toMatchObject({
      code: 'web.vue_error',
      context: { message: 'RangeError: sin datos', component: 'WorkdayTable', hook: 'render' },
    })
    expect(consoleError).toHaveBeenCalledOnce()
    consoleError.mockRestore()
  })

  it('captura los errores globales de window y las promesas sin catch', () => {
    const reporter = createWebErrorReporter({ app: 'portal', appVersion: '1.0.0', now: fixedNow })
    installGlobalErrorCapture(createApp(StubRoot), reporter)

    window.dispatchEvent(
      new ErrorEvent('error', { error: new TypeError('roto'), filename: 'app.js', lineno: 42 }),
    )
    window.dispatchEvent(
      new PromiseRejectionEvent('unhandledrejection', {
        promise: Promise.reject(new Error('sin catch')).catch(() => undefined) as Promise<never>,
        reason: new Error('sin catch'),
      }),
    )

    const codes = reporter.pending().map((event) => event.code)
    expect(codes).toEqual(['web.unhandled_error', 'web.unhandled_rejection'])
    expect(reporter.pending()[0]?.context).toMatchObject({ source: 'app.js:42' })
  })
})
