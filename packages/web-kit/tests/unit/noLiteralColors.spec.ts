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
  ],
})

vueTester.run('no-literal-colors (.vue)', rule, {
  valid: [
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

describe('noLiteralColors (bloque de configuracion)', () => {
  it('activa la regla como error sobre src y propaga las rutas exentas', () => {
    const block = noLiteralColors(undefined, ['**/*.d.ts', 'src/branding.ts'])
    expect(block.rules).toEqual({ 'kronoqr-colors/no-literal-colors': 'error' })
    expect(block.files).toEqual(['src/**/*.{ts,mts,tsx,vue,js,mjs}'])
    expect(block.ignores).toContain('src/branding.ts')
  })
})
