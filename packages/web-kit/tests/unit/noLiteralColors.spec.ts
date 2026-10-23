// Prueba de la regla propia `kronoqr-colors/no-literal-colors` (U1): una regla
// de lint sin prueba puede dejar de vigilar sin que nadie se entere.
import { RuleTester } from 'eslint'
import tsParser from '@typescript-eslint/parser'
import vueParser from 'vue-eslint-parser'
import { describe, expect, it } from 'vitest'

import { noLiteralColors, noLiteralColorsPlugin } from '../../eslint/no-literal-colors.js'

RuleTester.describe = describe
RuleTester.it = it
RuleTester.itOnly = it.only

const rule = noLiteralColorsPlugin.rules['no-literal-colors']

const tester = new RuleTester({
  languageOptions: { ecmaVersion: 2022, sourceType: 'module', parser: tsParser },
})

const vueTester = new RuleTester({
  languageOptions: {
    ecmaVersion: 2022,
    sourceType: 'module',
    parser: vueParser,
    parserOptions: { parser: tsParser },
  },
})

const error = [{ messageId: 'literalColor' as const }]

tester.run('no-literal-colors (.ts)', rule, {
  valid: [
    "const a = 'var(--kq-color-primary)'",
    "const anchor = 'https://x.test/page#section'",
    "const entity = '&#35;'",
    "const word = 'primary-#strong'",
    'const fn = `calc(1px + 2px)`',
    "const text = 'garbage(1)'",
    'const n = 0xfff',
    // Nombres de color: solo cuentan donde son un color, no una palabra.
    "const variant = 'red'",
    "const status = { tone: 'green', label: 'white paper' }",
    "const ok = { color: 'transparent', fill: 'currentColor', stroke: 'inherit' }",
    "const css = 'color: var(--kq-color-text); background: transparent; border: 1px solid var(--kq-color-border)'",
    "const tokens = { color: 'var(--kq-color-text)' }",
    // Comentarios: una referencia a una incidencia no es un color.
    '// Incidencia #123 y #1',
    '/* nombra el token --kq-color-text, no su valor */',
  ],
  invalid: [
    { code: "const a = '#b8542a'", errors: error },
    { code: "const a = '#fff'", errors: error },
    { code: "const a = '#ffff'", errors: error },
    { code: "const a = '#1a2b3c4d'", errors: error },
    { code: "const a = '#000'", errors: error },
    { code: "const a = 'ticket #123'", errors: error },
    { code: "const a = '#333'", errors: error },
    { code: "const a = '#999'", errors: error },
    { code: "const a = '#000000'", errors: error },
    { code: "const a = 'color: #FFF; top: 0'", errors: error },
    { code: "const a = 'rgb(0 0 0)'", errors: error },
    { code: "const a = 'RGBA(0, 0, 0, .5)'", errors: error },
    { code: "const a = 'oklch(60% 0.1 40)'", errors: error },
    { code: 'const a = `hsl(${h} 50% 50%)`', errors: error },
    { code: 'const a = `${x} #abc`', errors: error },
    // Funciones de color nuevas (V1-UX-2).
    { code: "const a = 'color-mix(in srgb, red 10%, white)'", errors: error },
    { code: "const a = 'color(display-p3 1 0 0)'", errors: error },
    { code: "const a = 'light-dark(white, black)'", errors: error },
    {
      code: "const a = 'COLOR-MIX(in oklab, var(--kq-color-primary) 10%, transparent)'",
      errors: error,
    },
    // Nombres de color donde son un color (V1-UX-2).
    { code: "const a = 'color: red'", errors: error },
    { code: "const a = 'border: 1px solid blue'", errors: error },
    { code: "const a = 'fill:rebeccapurple;stroke:transparent'", errors: error },
    { code: "const a = { color: 'red' }", errors: error },
    { code: "const a = { backgroundColor: 'White' }", errors: error },
    { code: "const a = { 'background-color': 'navy' }", errors: error },
    { code: 'const a = { stroke: `orange` }', errors: error },
    // Un hexadecimal en un comentario (R1-UX-01).
    { code: '// tono #b8542a', errors: [{ messageId: 'literalColorComment' as const }] },
    { code: '/* entry #064e3b */', errors: [{ messageId: 'literalColorComment' as const }] },
    { code: '/** rojo #abc */', errors: [{ messageId: 'literalColorComment' as const }] },
  ],
})

vueTester.run('no-literal-colors (.vue)', rule, {
  valid: [
    {
      filename: 'style-ok.vue',
      code: '<template><p /></template><style>p { color: var(--kq-color-text); background: transparent }</style>',
    },
    {
      filename: 'attr-ok.vue',
      code: '<template><svg fill="currentColor" stroke="none" class="text-kq-primary-strong" /></template>',
    },
    {
      filename: 'ok.vue',
      code: '<template><p class="bg-kq-surface text-kq-text" :style="{ color: `var(--kq-color-text)` }">#123</p></template>',
    },
    {
      filename: 'ok.vue',
      code: '<script setup lang="ts">const a = "var(--kq-color-text)"</script><template><i /></template>',
    },
  ],
  invalid: [
    { filename: 'a.vue', code: '<template><path fill="#000" /></template>', errors: error },
    { filename: 'a2.vue', code: '<template><path fill="red" /></template>', errors: error },
    { filename: 'a3.vue', code: '<template><path :stroke="\'blue\'" /></template>', errors: error },
    {
      filename: 'a4.vue',
      code: '<template><p :style="{ backgroundColor: \'gold\' }" /></template>',
      errors: error,
    },
    {
      filename: 'a5.vue',
      code: '<template><p style="color: crimson" /></template>',
      errors: error,
    },
    {
      filename: 'a6.vue',
      code: '<template><!-- #b8542a --><p /></template>',
      errors: [{ messageId: 'literalColorComment' as const }],
    },
    // Bloques <style> (V1-UX-2).
    {
      filename: 's1.vue',
      code: '<template><p /></template><style scoped>p { color: #fff }</style>',
      errors: [{ messageId: 'literalColorStyle' as const }],
    },
    {
      filename: 's2.vue',
      code: '<template><p /></template><style>p { background: red }</style>',
      errors: [{ messageId: 'literalColorStyle' as const }],
    },
    {
      filename: 's3.vue',
      code: '<template><p /></template><style>p { color: color-mix(in srgb, var(--kq-color-text) 50%, transparent) }</style>',
      errors: [{ messageId: 'literalColorStyle' as const }],
    },
    {
      filename: 'b.vue',
      code: '<template><p :style="{ color: \'#b8542a\' }" /></template>',
      errors: error,
    },
    {
      filename: 'c.vue',
      code: '<template><p :style="{ color: `rgb(0 0 0)` }" /></template>',
      errors: error,
    },
    {
      filename: 'd.vue',
      code: '<script setup lang="ts">const a: string = "#333"</script><template><i /></template>',
      errors: error,
    },
  ],
})

describe('noLiteralColors (mensaje)', () => {
  const message = rule.meta?.messages?.literalColor ?? ''

  it('sugiere utilidades y tokens que existen, no una funcion que web-kit no exporta', () => {
    expect(message).not.toContain('readToken')
    expect(message).toContain('bg-kq-surface')
    expect(message).toContain('var(--kq-color-text)')
  })
})

describe('noLiteralColors (bloque de configuracion)', () => {
  it('activa la regla como error sobre src y propaga las rutas exentas', () => {
    const block = noLiteralColors(undefined, ['**/*.d.ts', 'src/branding.ts'])
    expect(block.rules).toEqual({ 'kronoqr-colors/no-literal-colors': 'error' })
    expect(block.files).toEqual(['src/**/*.{ts,mts,tsx,vue,js,mjs}'])
    expect(block.ignores).toContain('src/branding.ts')
  })
})
