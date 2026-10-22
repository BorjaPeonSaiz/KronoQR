// El menu, las rutas y los ambitos dicen lo mismo, y lo dicen contra lo que el
// servidor concede a cada rol (V2-PA-06, V2-PA-07, V21-3-PA-3, doc 02 §7.3).
//
// Hasta ahora cada prueba escribia a mano su conjunto de ambitos, asi que una
// fixture desfasada del servidor (un auditor con `attendance:read` cuando ya
// solo lleva `reports:legal`) pasaba en verde. Aqui los cuatro roles de gestion
// salen de `docs/api/role-abilities.json`, el catalogo que deja la cadena de
// migraciones de roles, y se comprueba:
//
//  1. que todo ambito que pide una ruta o una seccion del menu lo concede algun rol;
//  2. que el menu de cada rol es EXACTAMENTE el esperado (un cambio es consciente);
//  3. que una seccion del menu y su ruta exigen los mismos ambitos;
//  4. que la guarda de rutas deja entrar donde el menu ofrece y desvia donde no;
//  5. que las fixtures de los E2E llevan los ambitos del servidor.
//
// La interfaz solo evita frustracion; la seguridad es de la policy (regla dura 18).
import { readFileSync } from 'node:fs'
import { resolve } from 'node:path'
import type { RouteRecordRaw } from 'vue-router'
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest'
import { hasAbility } from '@/features/auth/abilities'
import { useSessionStore } from '@/features/auth/session.store'
import { routes } from '@/router'
import { registerAuthGuard } from '@/router/guards'
import { FALLBACK_SECTIONS, NAVIGATION_SECTIONS } from '@/shared/ui/navigation'
import { ADMIN_USER, AUDITOR_USER, MANAGER_USER, USER } from '../e2e/support/admin'
import { managementUser, setupStatus } from './support/fixtures'
import { createTestPinia, createTestRouter, jsonResponse, stubFetch } from './support/harness'

type Role = 'responsable_departamento' | 'rrhh' | 'auditor' | 'admin'

interface RoleCatalog {
  roles: Record<Role, string[]>
}

const CATALOG = JSON.parse(
  readFileSync(resolve(process.cwd(), '..', 'docs', 'api', 'role-abilities.json'), 'utf8'),
) as RoleCatalog

const ROLES = Object.keys(CATALOG.roles) as Role[]

/** El menu que debe ver cada rol, en el orden del menu. Cambiarlo es una decision, no un descuido. */
const EXPECTED_MENU: Record<Role, string[]> = {
  responsable_departamento: ['departments', 'absences', 'live', 'incidents', 'compliance'],
  rrhh: [
    'employees',
    'departments',
    'absences',
    'live',
    'incidents',
    'compliance',
    'credentials',
    'reports',
    'adoption-dashboard',
    'payroll-export',
    'legal-export',
  ],
  auditor: ['legal-export'],
  admin: [
    'employees',
    'departments',
    'absences',
    'live',
    'incidents',
    'compliance',
    'credentials',
    'reports',
    'adoption-dashboard',
    'payroll-export',
    'legal-export',
    'compliance-profile',
    'operational-settings',
    'devices',
    'branding',
    'accounts',
    'license',
    'support',
    'errors',
  ],
}

function flatRecords(records: readonly RouteRecordRaw[]): RouteRecordRaw[] {
  return records.flatMap((record) => [
    record,
    ...(record.children !== undefined ? flatRecords(record.children) : []),
  ])
}

function requiredBy(record: RouteRecordRaw): readonly string[] {
  const meta = record.meta

  return meta?.abilities ?? (meta?.ability === undefined ? [] : [meta.ability])
}

function menuFor(role: Role): string[] {
  const granted = CATALOG.roles[role]

  return NAVIGATION_SECTIONS.filter((section) =>
    section.abilities.some((ability) => hasAbility(granted, ability)),
  ).map((section) => section.name)
}

function signIn(role: Role): void {
  const session = useSessionStore()

  session.user = managementUser({ roles: [role], abilities: CATALOG.roles[role] })
  session.token = 'un-token'
  session.status = 'authenticated'
}

beforeEach(() => {
  window.sessionStorage.clear()
  createTestPinia()
  stubFetch(() => jsonResponse(setupStatus({ available: false })))
})

afterEach(() => {
  vi.unstubAllGlobals()
})

describe('catalogo de ambitos del servidor', () => {
  it('declara los cuatro roles de gestion y ningun comodin total', () => {
    expect(ROLES.sort()).toEqual(['admin', 'auditor', 'responsable_departamento', 'rrhh'])

    for (const role of ROLES) {
      expect(CATALOG.roles[role]).not.toContain('*')
    }
  })

  it('todo ambito que piden las rutas y el menu lo concede algun rol (no hay erratas)', () => {
    const granted = ROLES.flatMap((role) => CATALOG.roles[role])
    const required = [
      ...flatRecords(routes).flatMap(requiredBy),
      ...NAVIGATION_SECTIONS.flatMap((section) => section.abilities),
    ]

    const unreachable = [...new Set(required)].filter((ability) => !hasAbility(granted, ability))

    expect(unreachable).toEqual([])
  })
})

describe.each(ROLES)('menu y rutas del rol %s', (role) => {
  it('el menu es exactamente el esperado', () => {
    expect(menuFor(role)).toEqual(EXPECTED_MENU[role])
  })

  it('la guarda deja entrar a cada seccion del menu y desvia el resto', async () => {
    const visible = new Set(menuFor(role))
    const router = createTestRouter()

    registerAuthGuard(router)
    signIn(role)

    for (const section of NAVIGATION_SECTIONS) {
      await router.push({ name: section.name })

      const arrivedAt = router.currentRoute.value.name

      if (visible.has(section.name)) {
        expect(arrivedAt, `${role} deberia poder abrir «${section.name}»`).toBe(section.name)
      } else {
        expect(arrivedAt, `${role} no deberia abrir «${section.name}»`).not.toBe(section.name)
        // El desvio es a una seccion que SI alcanza, nunca a «sin permiso» teniendo alguna.
        expect(visible.has(String(arrivedAt)) || arrivedAt === 'forbidden').toBe(true)
      }
    }
  })
})

describe('menu y rutas dicen lo mismo', () => {
  it('cada seccion del menu tiene una ruta con el nombre de la seccion y los mismos ambitos', () => {
    const records = flatRecords(routes)

    for (const section of NAVIGATION_SECTIONS) {
      const record = records.find((candidate) => candidate.name === section.name)

      expect(record, `la seccion «${section.name}» no tiene ruta`).toBeDefined()
      expect([...(record ? requiredBy(record) : [])].sort()).toEqual([...section.abilities].sort())
    }
  })

  it('las rutas de pantalla con ambito que no estan en el menu cuelgan de una seccion', () => {
    const menuNames = new Set(NAVIGATION_SECTIONS.map((section) => section.name))
    const orphans = flatRecords(routes)
      .filter(
        (record) =>
          typeof record.name === 'string' &&
          requiredBy(record).length > 0 &&
          !menuNames.has(record.name) &&
          record.meta?.section === undefined,
      )
      .map((record) => String(record.name))

    expect(orphans).toEqual([])
  })

  it('las secciones de reserva existen en el menu', () => {
    const names = new Set(NAVIGATION_SECTIONS.map((section) => section.name))

    expect(FALLBACK_SECTIONS.map((section) => section.name).filter((n) => !names.has(n))).toEqual(
      [],
    )
  })
})

describe('rutas de un solo rol por URL tecleada (V2-PA-06)', () => {
  it.each([
    ['rrhh', '/accounts', 'employees'],
    ['rrhh', '/settings', 'employees'],
    ['responsable_departamento', '/accounts', 'live'],
    ['responsable_departamento', '/settings', 'live'],
    ['auditor', '/accounts', 'legal-export'],
    ['auditor', '/settings', 'legal-export'],
    ['auditor', '/live', 'legal-export'],
  ] as const)('%s en %s acaba en «%s»', async (role, path, landing) => {
    const router = createTestRouter()

    registerAuthGuard(router)
    signIn(role)
    await router.push(path)

    expect(router.currentRoute.value.name).toBe(landing)
  })

  it('el admin llega a /accounts y /settings', async () => {
    const router = createTestRouter()

    registerAuthGuard(router)
    signIn('admin')

    await router.push('/accounts')
    expect(router.currentRoute.value.name).toBe('accounts')

    await router.push('/settings')
    expect(router.currentRoute.value.name).toBe('operational-settings')
  })
})

describe('las fixtures de los E2E llevan los ambitos del servidor (V2-PA-07)', () => {
  it.each([
    ['rrhh', USER],
    ['responsable_departamento', MANAGER_USER],
    ['admin', ADMIN_USER],
    ['auditor', AUDITOR_USER],
  ] as const)('%s', (role, fixture) => {
    expect(fixture.roles).toEqual([role])
    expect([...fixture.abilities].sort()).toEqual([...CATALOG.roles[role]].sort())
  })
})
