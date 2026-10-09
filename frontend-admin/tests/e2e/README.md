# Pruebas E2E del panel de gestión

Playwright sobre el build (`vite preview`), sin backend: las llamadas a `/api/v1/*` se
interceptan en `support/admin.ts` con las formas del contrato. `make e2e` las ejecuta junto a
las del quiosco y del portal.

```bash
npm run test:e2e                        # todo
npx playwright test --grep @RF-PA-03    # por etiqueta de requisito (§9.6)
npx playwright test --ui                # para depurar
```

## Qué hay aquí

| Fichero                                                                 | Cubre                                                                                                                                                                                        |
| ----------------------------------------------------------------------- | -------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- |
| `login.spec.ts`                                                         | `@RF-ID-01`, `@RF-ID-02` — acceso, redirección con `redirect`, rechazo, cierre de sesión                                                                                                     |
| `two-factor.spec.ts`                                                    | `@RF-ID-01`, `@RS-06` — segundo factor obligatorio: reto → código → plantilla, y alta con QR y secreto                                                                                       |
| `management-accounts.spec.ts`                                           | `@RF-ID-02`, `@RF-ID-10` — cuentas de gestión: filtros, alta con contraseña temporal, baja, restablecimientos y cambio obligado de la contraseña propia                                      |
| `departments.spec.ts`                                                   | `@RF-ID-03` — departamentos y centro: alta, renombrado, nombre del centro y lectura del auditor                                                                                              |
| `shell.spec.ts`                                                         | Sin etiqueta a propósito — el marco: `aria-current` y menú apilado por debajo de `md`                                                                                                        |
| `employees.spec.ts`                                                     | `@RF-GP-01`, `@RF-GP-02`, `@RF-GP-03`, `@RF-ID-03`, `@RN-14` — ficha, contratos (con relectura ante `409`), teletrabajo y baja                                                               |
| `pin-pending-import.spec.ts`                                            | `@RF-GP-05`, `@RF-ID-09`, `@RF-PD-03`, `@RL-05` — las altas importadas nacen sin PIN; aviso, enlace del resumen y emisión desde la ficha                                                     |
| `absences.spec.ts`                                                      | `@RF-GP-04`, `@RF-ID-03` — registrar, corregir y anular ausencias; vista del responsable sin nota                                                                                            |
| `workdays-journey.spec.ts`                                              | `@RF-GP-01`, `@RF-PA-03`, `@RN-13`, `@RF-AT-12` — plantilla → ficha → registro horario con su corrección y pausas                                                                            |
| `corrections.spec.ts`                                                   | `@RF-PA-04`, `@RN-01`, `@RN-02`, `@RN-05`, `@RN-13`, `@RN-14` — añadir, corregir y anular tramos                                                                                             |
| `live-presence.spec.ts`                                                 | `@RF-PA-01`, `@RF-PA-02`, `@RF-PA-03`, `@RNF-D-03`, `@RNF-P-04` — dos pestañas con Reverb simulado (`routeWebSocket`), degradación a sondeo, filtros, 500 filas y LCP                        |
| `incidents.spec.ts`                                                     | `@RF-PA-05`, `@RF-PR-06`, `@RN-16`, `@RN-18` — la bandeja lista y filtra al servidor, resuelve con nota, un `409` dice quién se adelantó, patrones anómalos y marca en el detalle de jornada |
| `compliance.spec.ts`                                                    | `@RF-PA-06` — vista de cumplimiento                                                                                                                                                          |
| `period-report.spec.ts` / `period-report-export.spec.ts`                | `@RF-IN-01`, `@RF-IN-02`, `@RF-IN-04`, `@RF-ID-03` — informe por periodo y sus descargas                                                                                                     |
| `report-exports.spec.ts`                                                | `@RF-IN-06` — exportaciones en segundo plano                                                                                                                                                 |
| `payroll-export.spec.ts`                                                | `@RF-IN-07` — salida a nómina                                                                                                                                                                |
| `adoption-dashboard.spec.ts`                                            | `@RF-IN-08`, `@RF-ID-03` — cuadro de impacto y adopción                                                                                                                                      |
| `credential-rotation.spec.ts` / `credential-instructions-sheet.spec.ts` | `@RF-QR-07`, `@RF-QR-08`, `@RL-05` — avance de la rotación de clave, hoja de instrucciones por idioma y recordatorio de la entrega                                                           |
| `devices.spec.ts`                                                       | `@RF-PA-07`, `@RF-PD-06`, `@RL-04`, `@RS-03` — vincular y desvincular quioscos, rechazo genérico del código y aviso de cola pendiente                                                        |
| `settings.spec.ts`                                                      | `@RF-PD-01`, `@RF-AT-12`, `@RF-ID-09`, `@RF-KI-07`, `@RF-KI-08`, `@RF-PR-05`, `@RF-PR-06`, `@RS-12` — ajustes operativos, incluida la longitud del PIN con su aviso de impacto               |
| `compliance-profile.spec.ts`                                            | `@RF-PD-07`, `@RF-AT-12` — perfil de cumplimiento                                                                                                                                            |
| `branding.spec.ts`                                                      | `@RF-PD-08` — marca, con el aviso en vivo y la confirmación del acento sin contraste (MB2)                                                                                                   |
| `setup-wizard.spec.ts`                                                  | `@RF-GP-05`, `@RF-PD-03`, `@RF-PD-04` — asistente de puesta en marcha                                                                                                                        |
| `support.spec.ts`                                                       | `@RF-PD-09`, `@RF-PD-11`, `@RL-19` — paquete de diagnóstico y accesos de soporte                                                                                                             |
| `data-export.spec.ts`                                                   | `@RF-PD-14`, `@RL-20` — exportación íntegra de datos                                                                                                                                         |
| `errors.spec.ts`                                                        | `@RF-PD-15` — histórico de errores                                                                                                                                                           |
| `accessibility.spec.ts`                                                 | `@axe-core/playwright` sobre las pantallas del panel, 0 violaciones críticas/graves                                                                                                          |

### El segundo factor (`two-factor.spec.ts`, RS-06)

`stubManagementApi(page, { twoFactor: 'verify' | 'enrol' })` sustituye la respuesta de `POST
/auth/login` por un `202` con el reto (`TwoFactorChallenge`) en vez de la sesión directa —
`'verify'` simula una cuenta con TOTP ya activo, `'enrol'` la primera vez, sin segundo factor
todavía. El código válido en los dos dobles es la constante `TOTP_CODE`; cualquier otro se
rechaza con `401`. El `challenge_token` **no** vive en `sessionStorage` — es estado efímero del
propio componente (`session.store.ts`) — así que no hay nada que comprobar ahí; lo que se
prueba es que la pantalla pide el código o el alta, y que entrar deja la misma sesión que el
acceso directo.

El QR del alta se genera con `@kronoqr/web-kit/qr/renderQrPath` (carga diferida, el mismo
codificador que usa el aviso de privacidad del quiosco): la prueba no decodifica el QR, solo
comprueba que aparece con su `role="img"` y que el secreto en base32 sigue disponible en texto
al lado, para quien no puede escanearlo.

### La bandeja de incidencias (`incidents.spec.ts`, RF-PA-05)

`stubManagementApi` gana tres opciones para esta tarea: `role: 'manager'` cambia la cuenta que
entra por `logIn`/`logInAsManager` de `USER` (RRHH, alcance completo) a `MANAGER_USER` (un
`responsable_departamento` con `incidents:*` y sin `employees:*` ni `credentials:*`, el ejemplo
del contrato de `GET /auth/me`); `resolveOutcome: 'conflict'` hace que
`POST /incidents/{id}/resolve` responda `409` en vez de cerrar la incidencia, y las relecturas
posteriores devuelven `INCIDENT_CLOSED_BY_OTHER`; `workdays` sustituye la respuesta de
`GET /employees/{uuid}/workdays` para el caso con una incidencia incrustada
(`WORKDAYS_WITH_INCIDENT`). `RecordedRequest` ahora también guarda el `body` de cada petición,
que es lo que permite comprobar que la nota de cierre llegó tal cual se escribió.

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

## Dónde se ejecuta

En la CI, en la etapa ⑦ (`.github/workflows/ci.yml`, una entrada de la matriz por aplicación), o
en el host. No en el contenedor `node-admin`: es Alpine (musl) y el Chromium de Playwright no
arranca ahí.

## Lo que falta

- El recorrido de exportación legal («auditor entra → genera → descarga el CSV», deuda de la
  tarea 1.17): hoy solo se comprueba la navegación hasta `/reports/legal-export`.
