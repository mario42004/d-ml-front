<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/layout.php';

require_product_access('smart_tales');
set_current_product('smart_tales');

$membership = current_membership('smart_tales');

render_app_header('Smart Tales | Solucion');
?>
<section class="page-stack">
  <section class="hero">
    <div class="portal-hero">
      <div>
        <span class="role-badge">Smart Tales</span>
        <h1>Cuentos personalizados con voces familiares y biblioteca narrativa.</h1>
        <p class="lead">Este producto nace de la documentacion funcional de <strong>smart_tale</strong> y ya queda integrado en el sistema comun de usuarios, productos y permisos de <strong>d-ml</strong>. Su siguiente paso es conectar perfiles infantiles, voces autorizadas, generacion segura de historias y audio sintetizado sin abrir un sistema paralelo.</p>
      </div>
      <aside class="route-card">
        <strong>Rol actual</strong>
        <code><?= htmlspecialchars((string) ($membership['role_name'] ?? 'User'), ENT_QUOTES, 'UTF-8') ?></code>
        <p class="route-note">Tu acceso ya existe dentro del portal. La capa de generacion se conectara mediante servicios dedicados.</p>
      </aside>
    </div>
  </section>

  <section class="panel-grid">
    <article class="card">
      <span class="section-tag">Producto</span>
      <h2>Que cubre Smart Tales</h2>
      <p>Perfiles de menores, configuracion de tema y tono, clonacion de voz con consentimiento, generacion de texto infantil y entrega de audio listo para escuchar. El objetivo es una rutina nocturna emocional, repetible y trazable.</p>
    </article>

    <article class="card">
      <span class="section-tag">Integracion</span>
      <h2>Conectado al sistema comun</h2>
      <p>La identidad del usuario, sus permisos y su acceso al producto se resuelven en el mismo sistema de informacion que ya usan Audioprint y Qvoice. La logica intensiva del producto vivira fuera del portal, desacoplada en servicios de LLM, TTS y almacenamiento.</p>
    </article>
  </section>

  <section class="panel-grid">
    <article class="card">
      <span class="section-tag">Modelo inicial</span>
      <h2>Entidades ya previstas</h2>
      <ul class="service-list">
        <li>Perfiles infantiles con edad, idioma y preferencias narrativas</li>
        <li>Perfiles de voz con consentimiento y referencia al proveedor</li>
        <li>Solicitudes de cuento con texto, audio, estado y trazabilidad</li>
      </ul>
    </article>

    <article class="card">
      <span class="section-tag">Siguiente entrega</span>
      <h2>MVP recomendado</h2>
      <p>El MVP debe abrir CRUD de menores, alta de voces autorizadas y generacion de cuentos bajo guardrails. La integracion detallada queda documentada en <code>docs/smart-tales-integration.md</code>.</p>
    </article>
  </section>
</section>
<?php render_app_footer(); ?>
