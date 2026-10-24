// Prueba de la regla propia `kronoqr-comments/no-plan-references` (doc 02
// §3.5): una regla de lint sin prueba puede dejar de vigilar sin que nadie se
// entere, y una que salta con lo que no debe acaba desactivada.
import { RuleTester } from 'eslint'
import tsParser from '@typescript-eslint/parser'
import vueParser from 'vue-eslint-parser'
import { describe, expect, it } from 'vitest'

import {
  findPlanReference,
  noPlanReferences,
  noPlanReferencesPlugin,
} from '../../eslint/no-plan-references.js'

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

/** Comentarios que NO son historia del plan: el motivo, o texto que se le parece. */
const allowed = [
  // El motivo, que es lo que la regla pide.
  '// El auditor no entra: solo lleva `reports:legal` (decision del propietario del 13-10-2026).',
  '/* RF-ID-10, ADR-051 */',
  '// (RF-QR-08, RN-05, RL-12, RS-03, RQ-13, RNF-P-01, RNF-D-03)',
  '// Regla dura 13: nada de cliente en el codigo (ADR-017).',
  '// Doc 01 §4 y doc 02 §8.1.',
  // «bloque», «tarea», «vuelta» y «revision» con su sentido de siempre.
  '// El bloque «que hacer» solo se enseña si hay algo que hacer.',
  '// Un PIN bloqueado por intentos: el bloqueo dura 15 min.',
  '// el backend, en paralelo a esta tarea, le da a cada tramo su tipo',
  '// La pausa: hora local de salida y hora de vuelta.',
  '// desde la siguiente revision nocturna; la revision humana decide.',
  // Versiones publicadas: datos de instalaciones reales.
  '// una copia anterior a la 2.2.0 (sin el objeto) no tumba la marca',
  '// Hasta la 2.2.x la eleccion se guardaba en la tablet.',
  '/** WCAG A y AA hasta la 2.2 y las buenas practicas de axe. */',
  // Falsos positivos de los ids cortos.
  '// fondo #C1C1C1 (#A1B2C3), token (--kq-c1) y titulo (h1)',
  '// (UTF-8), (ES-hosteleria), (ES, GB) y (AES-256, ISO-8601)',
  '// Tecnica ATT&CK (T1053.003), y T1053.003 en prosa',
  '// la hoja A4 (A4), el sonido (MP3), el prefijo (FH1) y `KQL2`',
  '// (doc 06 S6.5) y la huella (SHA-256, SHA256, SHA3-256)',
  '// (WCAG 3.1.1), (E2E), (CPU x4, 3G) y (RS-04, H-07)',
  // Un texto de la interfaz no es un comentario: lo vigila i18n, no esta regla.
  "const label = 'bloque 2, tarea 5.8, V21-1-RV-1'",
]

const forbidden = [
  // «bloque N»
  '// el auditor ya no entra (bloque 25).',
  '// Cuentas de gestion (RF-ID-10, bloque 12c).',
  '/* decision del propietario, bloque 14.4 */',
  '// --- Bloque 1: paquete de diagnostico',
  '// llego en los bloques 12 y 13',
  '/**\n * Idiomas (bloque 26 de la 2.2.1).\n */\nconst a = 1',
  '// el backend (bloque A2, en paralelo) le da su tipo',
  // Tareas y fichas del plan
  '// Marca blanca (RF-PD-08, tarea 5.8).',
  '// llego en las tareas 3.5 y 3.10',
  '// Hoja de instrucciones (tarea 5.11b, RL-05)',
  '/**\n * redactado (tarea\n * 3.3).\n */\nconst a = 1',
  '// el umbral de la ficha 3.3',
  '// El quiosco tiene su propio canal desde la 1.8.',
  '// el mismo patron de la 2.4',
  // Decisiones de una ficha
  '// solo `admin` puede resolver (decision 8 de la ficha)',
  '// «tras ese fichaje» de la decision 5-, asi que',
  '// (decision 13 de la segunda vuelta)',
  '// un `POST` explicito (decision de la 5.5)',
  // Hallazgos y revisiones
  '// idiomas (RF-PD-01, hallazgo B1 del cierre de la Fase 5)',
  '// sin colores literales (hallazgos U1 y V1-UX-2)',
  '// inaccesible por teclado (hallazgo de revision)',
  '// se colaba como 03:30 (hallazgo de la revision)',
  '// SIN TOKEN (revision de la 3.3)',
  '// (correccion de revision de UI/UX)',
  '// tres casos (14, revision de codigo)',
  '// reautenticacion (revision de seguridad del 12c)',
  '// La auditoria de cierre de la Fase 1 lo señalo',
  '// (segunda vuelta de la tarea 3.4)',
  '/* Techo absoluto (revision de la segunda\n * vuelta) */',
  // Ids de verificacion largos
  '// V21-1-RV-1',
  '// (V21-3-PA-4)',
  '// (V1-RV-02, V2-PO-2)',
  '// V6-KI-02 y V7-KI-03',
  '// (R1-RV-03; R2-PA-01)',
  '// R3-KI-02, R6-BD-01, R1-UX-01',
  '// (V1-UX-1)',
  '// Sin literales (KI7-01)',
  '// region con nombre (AX7-03)',
  '// lang del documento (WCAG 3.1.1, PA7-001)',
  '// selector (PO7-05)',
  '// PIN-03: fallo tecnico local, no un rechazo.',
  '// para la base anterior a AUD-1',
  // Ids cortos citados
  '// la misma hora la noche que cambia (C1).',
  '// margen (C4, RN-05)',
  '// en el servidor (RF-QR-08, V5-PA-2, HC1)',
  '// sin colores literales (U1)',
  '// la marca (MB3), su cache (MB4) y la previsualizacion (DC5)',
  '// (K1) y (K2)',
  '// decodificacion (KP2; RNF-P-03)',
  '// arranque (KT5)',
  '// cifrado en reposo (A1 de la revision)',
  '// solo https (F15 del dictamen de seguridad)',
  '// (U1 y U2)',
  '// (Inspeccion, nomina: RC2, V5-PA-4)',
  '// QR en negro (U1: excepcion cerrada)',
  '// G1: como `claim()` para en la primera fila',
  '/**\n * KP1: la primera comprobacion espera.\n */\nconst a = 1',
]

tester.run('no-plan-references (.ts)', rule, {
  valid: allowed,
  invalid: forbidden.map((code) => ({ code, errors: error })),
})

vueTester.run('no-plan-references (.vue)', rule, {
  valid: [
    '<template><!-- Seccion 1: paquete de diagnostico --><p /></template>',
    '<template><!-- Contraste (WCAG 2.2 AA, RF-PD-08, ADR-036): token --kq-c1 --><h1 /></template>',
    '<script setup lang="ts">\n// Marca (RF-PD-08, regla dura 13).\nconst a = 1\n</script>',
  ],
  invalid: [
    '<template><!-- Bloque 2: accesos de soporte --><p /></template>',
    '<template><!-- Hoja de instrucciones (tarea 5.11b, RL-05) --><p /></template>',
    '<template><!-- virtualiza las filas (V5-PA-1, AX7-01). --><p /></template>',
    '<template><!-- Contraste en vivo (MB2). --><p /></template>',
    '<template><!-- DIFERENCIADO (revision de la\n     segunda vuelta) --><p /></template>',
    '<script setup lang="ts">\n// bloque 25\nconst a = 1\n</script>',
    '<script setup lang="ts">\n// D9 (V7-KI-05): el idioma es de quien ficha.\nconst a = 1\n</script>',
  ].map((code) => ({ code, errors: error })),
})

describe('lo que se informa', () => {
  it('cita la referencia encontrada en una sola linea, sin el margen del bloque', () => {
    expect(findPlanReference(' Techo (revision de la segunda\n * vuelta)')).toBe('segunda vuelta')
    expect(findPlanReference(' G1: como claim()')).toBe('G1')
    expect(findPlanReference(' sin nada que contar (RF-PD-08)')).toBeNull()
  })
})

describe('configuracion compartida', () => {
  it('vigila src/** y deja fuera los .d.ts generados del contrato', () => {
    const config = noPlanReferences()

    expect(config.files).toEqual(['src/**/*.{ts,mts,tsx,vue,js,mjs}'])
    expect(config.ignores).toEqual(['**/*.d.ts'])
    expect(config.rules['kronoqr-comments/no-plan-references']).toBe('error')
  })
})
