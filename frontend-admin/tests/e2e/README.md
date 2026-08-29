# Pruebas E2E del panel de gestión

Playwright sobre el build (`vite preview`), sin backend: las llamadas a `/api/v1/*` se
interceptan en `support/admin.ts` con las formas del contrato. `make e2e` las ejecuta junto a
las del quiosco.

```bash
npm run test:e2e                        # todo
npx playwright test --grep @RF-PA-03    # por etiqueta de requisito (§9.6)
npx playwright test --ui                # para depurar
```

## Qué hay aquí

| Fichero                    | Cubre                                                                                           |
| -------------------------- | ----------------------------------------------------------------------------------------------- |
| `login.spec.ts`            | `@RF-ID-01`, `@RF-ID-02` — acceso, redirección con `redirect`, rechazo, cierre de sesión        |
| `workdays-journey.spec.ts` | `@RF-GP-01`, `@RF-PA-03`, `@RN-13` — plantilla → ficha → registro horario con su corrección     |
| `accessibility.spec.ts`    | `@RF-ID-01`, `@RF-GP-01`, `@RF-PA-03` con `@axe-core/playwright`, 0 violaciones críticas/graves |

## Se prueba el BUILD, no `vite dev`

`playwright.config.ts` construye y sirve `dist/` con `vite preview`, que es exactamente lo que
se despliega. El build se hace en el propio `webServer` para que el E2E nunca corra sobre un
`dist/` viejo.

## El navegador está en otra zona horaria a propósito

`timezoneId: 'Atlantic/Canary'`. Las horas que muestra el panel vienen resueltas en la zona
del centro (`Europe/Madrid`, regla dura 3); si alguien reconvirtiera en el cliente, el turno
de las 06:00 saldría a las 05:00 y `workdays-journey.spec.ts` fallaría.

## El backend no participa

Lo que se prueba aquí es el recorrido de la persona por el panel. Lo que el servidor autoriza
o deniega —policies, ámbitos de token, 403 por rol— se prueba en el backend (regla dura 18,
`tests/Feature/AuthorizationNegativeTest.php` y compañía). Una ruta que el doble no prevé
responde `404 problem+json` para que una pantalla nueva falle aquí y no se quede esperando.

## Lo que falta

- Recorridos de credenciales (tablero, impresión, entrega) y de exportación legal
  («auditor entra → genera → descarga el CSV», deuda de la tarea 1.17).
- Ejecución en la CI: la etapa ⑦ (`.github/workflows/e2e.yml`) sigue siendo el marcador de la
  tarea 3.7, también para el quiosco.
