// Estilo del paquete compartido, identico al de cada SPA (doc 02 §3.5): mismas
// reglas, mismo criterio, para que "compartido" no signifique "con otro
// estandar de calidad".
import { defineConfigWithVueTs, vueTsConfigs } from '@vue/eslint-config-typescript'
import skipFormatting from '@vue/eslint-config-prettier/skip-formatting'
import pluginVue from 'eslint-plugin-vue'

import { identifierLanguage } from './eslint/identifier-language.js'
import { noLiteralColors } from './eslint/no-literal-colors.js'
import { noBrowserStorage } from './eslint/no-browser-storage.js'
import { noPlanReferences } from './eslint/no-plan-references.js'

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
  // Sin colores literales (docs/06). Excepcion: los dos ficheros que fijan
  // por contrato valores de color del producto (acento de serie y pares de contraste).
  noLiteralColors(undefined, ['**/*.d.ts', 'src/branding.ts', 'src/themePairs.ts']),
  // Datos del registro fuera del navegador. Unica excepcion: la cache de marca,
  // que es dato de marca del cliente y no personal.
  noBrowserStorage({
    exceptions: [
      {
        file: 'src/brandingState.ts',
        allow: ['localStorage'],
        reason:
          'Cache de la ultima marca valida (MB4): pinta sin parpadeo; es marca, no datos personales.',
      },
    ],
  }),
  // Comentarios con el motivo, no con la historia del plan ni de las revisiones (doc 02 §3.5).
  // La propia regla se excluye: sus ejemplos son lo que detecta.
  noPlanReferences(
    ['src/**/*.{ts,mts,tsx,vue,js,mjs}', 'eslint/**/*.js'],
    ['**/*.d.ts', 'eslint/no-plan-references.js'],
  ),
  skipFormatting,
)
