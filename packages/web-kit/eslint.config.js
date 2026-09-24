// Estilo del paquete compartido, identico al de cada SPA (doc 02 §3.5): mismas
// reglas, mismo criterio, para que "compartido" no signifique "con otro
// estandar de calidad".
import { defineConfigWithVueTs, vueTsConfigs } from '@vue/eslint-config-typescript'
import skipFormatting from '@vue/eslint-config-prettier/skip-formatting'
import pluginVue from 'eslint-plugin-vue'

import { identifierLanguage } from './eslint/identifier-language.js'

export default defineConfigWithVueTs(
  {
    name: 'kronoqr/files-to-lint',
    files: ['**/*.{ts,mts,tsx,vue,js,mjs}'],
  },
  {
    name: 'kronoqr/files-to-ignore',
    ignores: ['**/dist/**', '**/coverage/**', '**/node_modules/**'],
  },
  pluginVue.configs['flat/recommended'],
  vueTsConfigs.strict,
  {
    name: 'kronoqr/rules',
    rules: {
      '@typescript-eslint/no-explicit-any': 'error',
      // Identica a la de las tres SPA y por el mismo motivo (RS-04, H-07):
      // `flat/recommended` la declara `warn` y solo bloqueaba por
      // `--max-warnings 0`. Y aqui pesa mas que en ninguna: lo que se escriba
      // con `v-html` en un componente compartido se renderiza en las tres.
      'vue/no-v-html': 'error',
    },
  },
  // Identificadores en ingles en src/** (doc 02 §3.5, decision del 24-09-2026).
  identifierLanguage(),
  skipFormatting,
)
