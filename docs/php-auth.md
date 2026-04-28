# Autenticacion PHP actual

## Enfoque real de despliegue

El hosting actual soporta PHP y MySQL, asi que la autenticacion activa del proyecto se implementa aqui, mientras que las APIs cloud futuras quedaran desacopladas.

## Archivos principales

- `login.php`
- `logout.php`
- `dashboard.php`
- `portal/admin.php`
- `portal/medico.php`
- `portal/soporte.php`
- `portal/paciente.php`
- `config/database.php`
- `includes/auth.php`

## Base de datos

El esquema reutiliza:

- `roles`
- `users`
- `user_roles`

La tabla `auth_sessions` queda disponible para una evolucion futura, aunque la version actual usa sesiones PHP nativas.

## Flujo

1. El usuario abre `login.php`.
2. Se valida correo y contrasena contra `users`.
3. Se crea sesion PHP.
4. Se redirige al dashboard segun el rol primario.
5. Cada pagina protegida comprueba el rol permitido.

## Siguiente paso recomendado

- Crear el primer usuario admin real en MySQL
- Enlazar la landing con `login.php`
- Subir este bloque PHP al hosting
