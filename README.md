# Evaluator

Aplicacion web en `PHP` para gestion basica de clases, seleccion aleatoria de alumnos y registro de evaluaciones.

## Stack

- `PHP 8.2+`
- `MySQL/MariaDB` o `SQLite`
- `HTML`, `CSS` y `JavaScript`

## Estructura

- `app/` logica de aplicacion
- `public/` punto de entrada web y recursos publicos
- `storage/` datos temporales, importaciones y exportaciones

## Configuracion

La aplicacion carga su configuracion desde un archivo `.env` no versionado.

Archivos de ejemplo incluidos:

- `.env.example`
- `.env.hostinger.example`

## Despliegue

Para produccion, configura el `.env` directamente en el servidor. No subas credenciales ni datos reales al repositorio.
