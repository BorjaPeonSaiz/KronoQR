# accounts

Cuentas de gestión del panel: alta, baja y restablecimiento de contraseña y de segundo factor
(RF-ID-10, [ADR-051](../../../../docs/adr/ADR-051-cuentas-de-gestion-desde-el-panel-con-contrasenas-temporales.md)). Ruta `/accounts`, solo `admin` (`accounts:*`).

**Contraseñas temporales, entregadas en mano.** El alta no lleva campo de contraseña: la genera el
servidor, se enseña una sola vez y se entrega de viva voz o en papel, nunca por correo (regla
dura 12). Quien entra con ella queda obligado a cambiarla (`auth/ChangePasswordView.vue`).

- `accounts.api.ts` — listar, crear, dar de baja y restablecer contraseña o segundo factor.
- `AccountsView.vue` — la lista, incluidas las cuentas dadas de baja (una baja no se borra y sigue
  siendo autora de lo que firmó, regla dura 5).
- `CreateAccountDialog.vue` — alta. Un responsable de departamento nace sin alcance: se le asigna
  en `departments/`.
- `AccountActionDialog.vue` — baja y restablecimientos, con su «antes → después» (`ChangePreview`)
  y motivo donde el contrato lo exige.
- `TemporaryPasswordDialog.vue` — visualización única de la contraseña temporal, mismo patrón que
  `employees/PinRevealDialog.vue`: sin copiar al portapapeles y solo se cierra confirmando la
  entrega.
- `actorReauth.ts` + `ActorReauthFields.vue` — reautenticación de quien actúa al crear, restablecer
  la contraseña o retirar el segundo factor: código de su segundo factor o, si no tiene ninguno,
  su contraseña actual. No se guarda en ningún sitio.

Carpeta por _feature_, no por tipo de fichero (doc 02 §3.5).
