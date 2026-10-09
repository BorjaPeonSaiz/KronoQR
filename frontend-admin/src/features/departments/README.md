# departments

Departamentos del centro, su responsable y el nombre del centro (RF-ID-03). Ruta `/departments`.

**Lectura abierta a los cuatro roles**; crear y renombrar exigen `employees:*` (`admin` y `rrhh`), y
asignar el responsable `accounts:*` (solo `admin`). La vista oculta lo que el rol no puede hacer;
la policy del servidor es la que autoriza (regla dura 18).

- `DepartmentsView.vue` — lista, alta y renombrado de departamentos y selector de responsable. El
  responsable es un atributo del departamento, no de la cuenta: asignarlo desplaza al anterior y es
  lo que da alcance a un `responsable_departamento`. El selector pide todas las páginas de cuentas
  activas con ese rol.
- `NameChangeDialog.vue` — renombrado de un departamento o del centro, con su «antes → después»
  (`ChangePreview`).
- `SiteSection.vue` — el centro de la instalación (uno por instalación, ADR-040): nombre editable y
  zona horaria en solo lectura. Solo se monta para quien puede escribir.

Las llamadas (`/site`, `/departments`) viven en `@/shared/api/organisation.api.ts` porque también las
usan otras pantallas.

Carpeta por _feature_, no por tipo de fichero (doc 02 §3.5).
