# Autenticacion inicial

## Objetivo

Definir una base de acceso segura para el portal de productos de `d-ml`, con especial foco en `Audioprint` y el panel administrativo.

## Componentes

- Esquema SQL para `roles`, `users`, `user_roles` y `auth_sessions`
- Sesiones PHP para el portal publicado
- Helpers de autorizacion por rol y por solucion en `includes/auth.php`
- Formularios de acceso y alta en `login.php` y `signup.php`

## Flujo actual

1. El usuario inicia sesion con correo y contrasena.
2. El portal valida credenciales y crea una sesion autenticada.
3. La sesion resuelve los productos y roles disponibles para ese usuario.
4. El usuario entra en el panel general o en la solucion habilitada.
5. Las rutas protegidas validan acceso antes de mostrar contenido o ejecutar acciones.

## Siguiente fase recomendada

- Reforzar politicas de contrasena y rotacion
- Registrar mejor auditoria de acciones administrativas
- Añadir recuperacion de contrasena
- Revisar permisos finos por solucion y por accion
