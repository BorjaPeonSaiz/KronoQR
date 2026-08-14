// Estilo del frontend (doc 02 §3.5).
//
// - eslint-plugin-vue en flat/recommended: prioridades A y B de la guia de
//   estilo oficial de Vue 3 (nombres de varias palabras, props tipadas, v-for
//   con key, v-if y v-for nunca en el mismo elemento).
// - @typescript-eslint en modo estricto, con no-explicit-any como error: lo
//   desconocido es unknown y se estrecha.
// - Prettier al final, para que no discuta con nadie sobre formato.
//
// La configuracion definitiva de convenciones es de la tarea 0.7.
import { defineConfigWithVueTs, vueTsConfigs } from '@vue/eslint-config-typescript'
import skipFormatting from '@vue/eslint-config-prettier/skip-formatting'
import pluginVue from 'eslint-plugin-vue'

export default defineConfigWithVueTs(
  {
    name: 'kronoqr/files-to-lint',
    files: ['**/*.{ts,mts,tsx,vue,js,mjs}'],
  },
  {
    name: 'kronoqr/files-to-ignore',
    ignores: ['**/dist/**', '**/dev-dist/**', '**/coverage/**', '**/node_modules/**'],
  },
  pluginVue.configs['flat/recommended'],
  vueTsConfigs.strict,
  {
    name: 'kronoqr/rules',
    rules: {
      // Redundante con la configuracion estricta, y a proposito: es la regla
      // que el doc 02 §3.5 nombra explicitamente, y no debe caerse sin que
      // alguien lo decida.
      '@typescript-eslint/no-explicit-any': 'error',
    },
  },
  {
    name: 'kronoqr/node-scripts',
    files: ['scripts/**/*.mjs', 'eslint.config.js'],
    languageOptions: {
      ecmaVersion: 'latest',
      sourceType: 'module',
      globals: { process: 'readonly', console: 'readonly' },
    },
  },
  skipFormatting,
)
