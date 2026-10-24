// Prueba de la regla propia `kronoqr-comments/no-plan-references` (V21-1-RV-5,
// C8): una regla de lint sin prueba puede dejar de vigilar sin que nadie se entere.
import { RuleTester } from 'eslint'
import tsParser from '@typescript-eslint/parser'
import vueParser from 'vue-eslint-parser'
import { describe, expect, it } from 'vitest'

import { noPlanReferences, noPlanReferencesPlugin } from '../../eslint/no-plan-references.js'

RuleTester.describe = describe
RuleTester.it = it
RuleTester.itOnly = it.only

const rule = noPlanReferencesPlugin.rules['no-plan-references']

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

const error = [{ messageId: 'planReference' as const }]

tester.run('no-plan-references (.ts)', rule, {
  valid: [
    '// El auditor no entra: solo lleva `reports:legal` (decision del propietario del 13-10-2026).',
    '// El bloque «que hacer» solo se enseña si hay algo que hacer.',
    '// Un PIN bloqueado por intentos: el bloqueo dura 15 min.',
    '/* RF-ID-10, ADR-051 */',
    // Un texto de la interfaz no es un comentario: lo vigila i18n, no esta regla.
    "const label = 'bloque 2'",
  ],
  invalid: [
    { code: '// el auditor ya no entra (bloque 25).', errors: error },
    { code: '// Cuentas de gestion (RF-ID-10, bloque 12c).', errors: error },
    { code: '/* decision del propietario, bloque 14.4 */', errors: error },
    { code: '// --- Bloque 1: paquete de diagnostico', errors: error },
    { code: '// llego en los bloques 12 y 13', errors: error },
    { code: '/**\n * Idiomas (bloque 26 de la 2.2.1).\n */\nconst a = 1', errors: error },
  ],
})

vueTester.run('no-plan-references (.vue)', rule, {
  valid: ['<template><!-- Seccion 1: paquete de diagnostico --><p /></template>'],
  invalid: [
    { code: '<template><!-- Bloque 2: accesos de soporte --><p /></template>', errors: error },
    {
      code: '<script setup lang="ts">\n// bloque 25\nconst a = 1\n</script>',
      errors: error,
    },
  ],
})

describe('configuracion compartida', () => {
  it('vigila src/** y deja fuera los .d.ts generados del contrato', () => {
    const config = noPlanReferences()

    expect(config.files).toEqual(['src/**/*.{ts,mts,tsx,vue,js,mjs}'])
    expect(config.ignores).toEqual(['**/*.d.ts'])
    expect(config.rules['kronoqr-comments/no-plan-references']).toBe('error')
  })
})
