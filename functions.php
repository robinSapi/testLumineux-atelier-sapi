<?php

if (!defined('ABSPATH')) {
  exit;
}

/* ─── Anti-spam : rate limiting formulaires de contact ─── */
function sapi_check_form_rate_limit($form_id = 'contact', $max_hits = 5) {
  $ip  = md5(isset($_SERVER['REMOTE_ADDR']) ? sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR'])) : 'unknown');
  $key = 'sapi_form_rl_' . $form_id . '_' . $ip;
  $hits = (int) get_transient($key);
  if ($hits >= $max_hits) {
    return false;
  }
  set_transient($key, $hits + 1, HOUR_IN_SECONDS);
  return true;
}

/* ─────────────────────────────────────────────────────────────────────────
 * Anti-spam formulaires de contact (2026-08-05) — couches AJOUTÉES au nonce +
 * honeypot + rate limit existants (on n'en retire aucun). Résistant à la
 * rotation d'IP du bot, sans friction pour les vrais clients, sans dépendance
 * externe. Utilisé par page-contact.php + sapi_ajax_robin_contact() +
 * sapi_ajax_megafilter_surmesure() (contact de la modale Conseiller).
 * ───────────────────────────────────────────────────────────────────────── */

/**
 * Time-trap : génère un timestamp SIGNÉ côté serveur au rendu du formulaire.
 * La signature empêche un bot d'envoyer simplement time() courant.
 * @return array{ts:int,sig:string}
 */
function sapi_time_trap_new() {
  $ts = time();
  return ['ts' => $ts, 'sig' => hash_hmac('sha256', (string) $ts, wp_salt('auth'))];
}

/**
 * Vérifie un time-trap : signature valide ET délai humain (≥ min, ≤ max).
 * Un bot poste en < 1 s ; un humain met toujours plusieurs secondes.
 * Le vrai levier anti-bot est la borne BASSE (3 s). La borne HAUTE n'est qu'un
 * garde-fou de sanité, fixée à 24 h (et non 1 h) pour NE PAS créer de faux
 * positif si la page est servie en cache (LiteSpeed) : le nonce de ces mêmes
 * formulaires tolère déjà ~12-24 h, on s'aligne dessus.
 * @return bool true si la soumission est plausible (humaine)
 */
function sapi_time_trap_valid($ts, $sig, $min = 3, $max = DAY_IN_SECONDS) {
  $ts = (int) $ts;
  if ($ts <= 0 || !is_string($sig) || $sig === '') return false;
  $expected = hash_hmac('sha256', (string) $ts, wp_salt('auth'));
  if (!hash_equals($expected, $sig)) return false;
  $elapsed = time() - $ts;
  return ($elapsed >= $min && $elapsed <= $max);
}

/**
 * Filtre anti-junk CONSERVATEUR (zéro faux positif visé). Rejette si :
 *  - le domaine de l'email est un domaine de test/réservé ;
 *  - le message est présent mais purement numérique ou < 5 caractères (hors espaces) ;
 *  - le message est vide ET requis par le formulaire ($require_message).
 * NE filtre PAS sur la présence d'URL ni sur des mots-clés (un client peut coller
 * un lien Pinterest ou un nom de modèle).
 *
 * @param string $email
 * @param string $message
 * @param bool   $require_message  true si ce formulaire exige un message
 * @return bool true si la soumission ressemble à du spam
 */
function sapi_is_junk_contact($email, $message, $require_message = false) {
  $bad_domains = ['example.com', 'example.org', 'example.net', 'test.com'];
  $email = strtolower(trim((string) $email));
  if ($email !== '') {
    $at = strrpos($email, '@');
    if ($at !== false) {
      $domain = substr($email, $at + 1);
      if (in_array($domain, $bad_domains, true)) return true;
    }
  }
  $msg = trim((string) $message);
  if ($msg === '') {
    return (bool) $require_message; // vide = junk seulement si le message est requis
  }
  if (preg_match('/^\s*\d+\s*$/', $msg)) return true;            // purement numérique
  if (function_exists('mb_strlen')) {
    if (mb_strlen(preg_replace('/\s+/', '', $msg)) < 5) return true;
  } elseif (strlen(preg_replace('/\s+/', '', $msg)) < 5) {
    return true;
  }
  return false;
}

// S28 Phase 2 — Page admin migration repeater galerie_produit → 8 Gallery ACF.
// Chargée uniquement en admin pour ne pas peser sur le front.
if (is_admin()) {
  require_once get_template_directory() . '/inc/sapi-migrate-galerie.php';
  // Tâche 5 — page admin « Règles de filtrage » du Conseiller.
  require_once get_template_directory() . '/inc/conseiller-rules-admin.php';
}

// Catalogue B2B (prescripteurs) — Temps 1. Source de données + mapping specs +
// champs ACF éditoriaux. Chargé front ET admin (ACF acf/init + rendu template).
require_once get_template_directory() . '/inc/catalogue-data.php';

// Catalogue B2B — couche PRIX (page /catalogue-prix + futur tarif pro). Fichier
// SÉPARÉ : catalogue-data.php garde sa garantie « zéro prix », qui est ce qui
// rend /catalogue auditable. Chargé après lui (il l'enrichit, il en dépend).
require_once get_template_directory() . '/inc/catalogue-data-pro.php';

// Catalogue B2B — Temps 2. Export PDF (mPDF via Composer/vendor, généré en CI).
// Réutilise la source de données + le mapping du Temps 1. Guardé si vendor absent.
require_once get_template_directory() . '/inc/catalogue-pdf.php';

// Catalogue — tarif professionnel (PDF remisé, admin SEULEMENT). Aucune route
// publique : un admin_post gardé par nonce + capacité. Chargé après le PDF
// public, dont il réutilise l'instance mPDF, la CSS et le cache.
require_once get_template_directory() . '/inc/catalogue-pdf-pro.php';
if (is_admin()) {
  require_once get_template_directory() . '/inc/catalogue-pro-admin.php';
}

function sapi_maison_setup() {
  add_theme_support('title-tag');
  add_theme_support('post-thumbnails');
  add_theme_support('html5', ['search-form', 'comment-form', 'comment-list', 'gallery', 'caption']);
  add_theme_support('custom-logo', [
    'height' => 120,
    'width' => 300,
    'flex-height' => true,
    'flex-width' => true,
  ]);
  add_theme_support('woocommerce');
  add_theme_support('wc-product-gallery-zoom');
  add_theme_support('wc-product-gallery-lightbox');
  add_theme_support('wc-product-gallery-slider');

  register_nav_menus([
    'primary'       => __('Menu principal', 'theme-sapi-maison'),
    'footer'        => __('Menu pied de page', 'theme-sapi-maison'),
    'footer_social' => __('Footer – Réseaux sociaux', 'theme-sapi-maison'),
    'footer_legal'  => __('Footer – Mentions légales', 'theme-sapi-maison'),
  ]);
}
add_action('after_setup_theme', 'sapi_maison_setup');

/**
 * Walker pour les menus footer : rend des <a> simples (pas de <ul><li>).
 */
class Sapi_Footer_Walker extends Walker_Nav_Menu {
  public function start_lvl(&$output, $depth = 0, $args = null) {}
  public function end_lvl(&$output, $depth = 0, $args = null) {}

  public function start_el(&$output, $item, $depth = 0, $args = null, $id = 0) {
    $atts = [];
    $atts['href']  = !empty($item->url) ? $item->url : '';
    $atts['title'] = !empty($item->attr_title) ? $item->attr_title : '';

    if ('_blank' === $item->target) {
      $atts['target'] = '_blank';
      $atts['rel']    = 'noopener';
    }

    $attributes = '';
    foreach ($atts as $attr => $value) {
      if (!empty($value)) {
        $attributes .= ' ' . $attr . '="' . esc_attr($value) . '"';
      }
    }

    $output .= '<a' . $attributes . '>' . esc_html($item->title) . '</a>';
  }

  public function end_el(&$output, $item, $depth = 0, $args = null) {}
}

/**
 * Ajouter "Accueil" en premier élément du menu mobile uniquement.
 */
add_filter('wp_nav_menu_items', function ($items, $args) {
  if (isset($args->menu_id) && 'mobile-nav-menu' === $args->menu_id) {
    $home = '<li class="menu-item menu-item-home"><a href="' . esc_url(home_url('/')) . '">' . esc_html__('Accueil', 'theme-sapi-maison') . '</a></li>';
    $items = $home . $items;
  }
  return $items;
}, 10, 2);

/**
 * Ajouter une classe CSS "menu-separator-before" sur l'item "Accessoires"
 * pour afficher un séparateur visuel dans le sous-menu "Nos créations".
 */
add_filter('wp_nav_menu_objects', function ($items) {
  foreach ($items as $item) {
    if (strtolower(trim($item->title)) === 'accessoires' && $item->menu_item_parent != 0) {
      $item->classes[] = 'menu-separator-before';
    }
  }
  return $items;
});

/**
 * Fallbacks : affichent les liens hardcodés si les menus WordPress ne sont pas encore créés.
 */
function sapi_fallback_primary_menu() {
  ?>
  <nav class="primary-nav" aria-label="<?php esc_attr_e('Menu principal', 'theme-sapi-maison'); ?>">
    <ul class="nav-menu">
      <li class="menu-item menu-item-has-children">
        <a href="<?php echo esc_url(home_url('/mes-creations/')); ?>">Mes créations</a>
        <ul class="sub-menu">
          <li class="menu-item"><a href="<?php echo esc_url(home_url('/categorie-produit/suspensions/')); ?>">Suspensions</a></li>
          <li class="menu-item"><a href="<?php echo esc_url(home_url('/categorie-produit/lampadaires/')); ?>">Lampadaires</a></li>
          <li class="menu-item"><a href="<?php echo esc_url(home_url('/categorie-produit/appliques/')); ?>">Appliques</a></li>
          <li class="menu-item"><a href="<?php echo esc_url(home_url('/categorie-produit/lampesaposer/')); ?>">Lampe à poser</a></li>
        </ul>
      </li>
      <li class="menu-item"><a href="<?php echo esc_url(home_url('/sur-mesure/')); ?>">Sur mesure</a></li>
      <li class="menu-item"><a href="<?php echo esc_url(home_url('/lumiere-dartisan/')); ?>">L'artisan</a></li>
      <li class="menu-item"><a href="<?php echo esc_url(home_url('/conseils-eclaires/')); ?>">Conseils</a></li>
      <li class="menu-item"><a href="<?php echo esc_url(home_url('/contact/')); ?>">Contact</a></li>
    </ul>
  </nav>
  <?php
}

function sapi_fallback_mobile_menu() {
  ?>
  <nav class="mobile-menu-nav" aria-label="<?php esc_attr_e('Menu mobile', 'theme-sapi-maison'); ?>">
    <ul class="mobile-nav-menu" id="mobile-nav-menu">
      <li class="menu-item menu-item-home"><a href="<?php echo esc_url(home_url('/')); ?>">Accueil</a></li>
      <li class="menu-item menu-item-has-children">
        <a href="<?php echo esc_url(home_url('/mes-creations/')); ?>">Mes créations</a>
        <ul class="sub-menu">
          <li class="menu-item"><a href="<?php echo esc_url(home_url('/categorie-produit/suspensions/')); ?>">Suspensions</a></li>
          <li class="menu-item"><a href="<?php echo esc_url(home_url('/categorie-produit/lampadaires/')); ?>">Lampadaires</a></li>
          <li class="menu-item"><a href="<?php echo esc_url(home_url('/categorie-produit/appliques/')); ?>">Appliques</a></li>
          <li class="menu-item"><a href="<?php echo esc_url(home_url('/categorie-produit/lampesaposer/')); ?>">Lampe à poser</a></li>
        </ul>
      </li>
      <li class="menu-item"><a href="<?php echo esc_url(home_url('/sur-mesure/')); ?>">Sur mesure</a></li>
      <li class="menu-item"><a href="<?php echo esc_url(home_url('/lumiere-dartisan/')); ?>">L'artisan</a></li>
      <li class="menu-item"><a href="<?php echo esc_url(home_url('/conseils-eclaires/')); ?>">Conseils</a></li>
      <li class="menu-item"><a href="<?php echo esc_url(home_url('/contact/')); ?>">Contact</a></li>
    </ul>
  </nav>
  <?php
}

function sapi_fallback_footer_nav() {
  echo '<a href="' . esc_url(home_url('/mes-creations/')) . '">Mes créations</a>';
  echo '<a href="' . esc_url(home_url('/lumiere-dartisan/')) . '">L\'artisan</a>';
  echo '<a href="' . esc_url(home_url('/conseils-eclaires/')) . '">Conseils</a>';
  echo '<a href="' . esc_url(home_url('/contact/')) . '">Contact</a>';
}

function sapi_fallback_social_menu() {
  echo '<a href="https://www.instagram.com/atelier_sapi/" target="_blank" rel="noopener">Instagram</a>';
  echo '<a href="https://www.facebook.com/ateliersapi" target="_blank" rel="noopener">Facebook</a>';
  echo '<a href="https://www.pinterest.fr/ateliersapi/" target="_blank" rel="noopener">Pinterest</a>';
  echo '<a href="' . esc_url(home_url('/actus/')) . '">Actus</a>';
}

function sapi_fallback_legal_menu() {
  echo '<a href="' . esc_url(home_url('/mentions-legales/')) . '">Mentions légales</a>';
  echo '<a href="' . esc_url(home_url('/cgv/')) . '">CGV</a>';
  echo '<a href="' . esc_url(home_url('/politique-de-confidentialite/')) . '">Confidentialité</a>';
}

// Précharger Square Peg avant le premier paint (élimine le FOUT / chargement en deux temps)
add_action('wp_head', function() {
  $uri = get_template_directory_uri();
  echo '<link rel="preload" href="' . esc_url($uri) . '/assets/fonts/SquarePeg-Regular.woff2" as="font" type="font/woff2" crossorigin>' . "\n";
  echo '<link rel="preload" href="' . esc_url($uri) . '/assets/fonts/SquarePeg-Regular-latin-ext.woff2" as="font" type="font/woff2" crossorigin>' . "\n";

  // Preload LCP : première image du carousel avec srcset (accueil uniquement)
  if (is_front_page()) {
    $categories_order = ['suspensions', 'appliques-murales', 'lampes-a-poser', 'lampadaires'];
    foreach ($categories_order as $cat_slug) {
      $term = get_term_by('slug', $cat_slug, 'product_cat');
      if (!$term) continue;
      $q = new WP_Query([
        'post_type' => 'product',
        'posts_per_page' => 1,
        'tax_query' => [['taxonomy' => 'product_cat', 'field' => 'term_id', 'terms' => $term->term_id]],
        'fields' => 'ids',
      ]);
      if ($q->have_posts()) {
        $lcp_ids = sapi_get_product_photo_ids($q->posts[0], 'ambiance', 1);
        if (!empty($lcp_ids)) {
          $lcp_id = $lcp_ids[0];
          $lcp_src = wp_get_attachment_image_url($lcp_id, 'full');
          $lcp_srcset = wp_get_attachment_image_srcset($lcp_id, 'full');
          echo '<link rel="preload" href="' . esc_url($lcp_src) . '" as="image" fetchpriority="high"';
          if ($lcp_srcset) {
            echo ' imagesrcset="' . esc_attr($lcp_srcset) . '" imagesizes="100vw"';
          }
          echo '>' . "\n";
        }
        wp_reset_postdata();
        break;
      }
      wp_reset_postdata();
    }
  }
}, 1);

/**
 * Dequeue scripts/styles not needed on non-WooCommerce pages
 */
function sapi_dequeue_unnecessary_assets() {
  if (is_front_page() || is_page()) {
    // jQuery UI datepicker — only needed on admin/checkout
    wp_dequeue_script('jquery-ui-datepicker');
    wp_dequeue_style('jquery-ui-datepicker');
  }
}
add_action('wp_enqueue_scripts', 'sapi_dequeue_unnecessary_assets', 100);

function sapi_maison_enqueue_assets() {
  // Montserrat only from Google Fonts — Square Peg is self-hosted (Safari fix)
  $fonts = [
    'family' => 'Montserrat:wght@300;400;500;600;700;900',
    'display' => 'swap',
  ];
  wp_enqueue_style('sapi-maison-fonts', add_query_arg($fonts, 'https://fonts.googleapis.com/css2'));

  // CRITICAL: Use filemtime for automatic cache busting
  wp_enqueue_style('sapi-maison-style', get_stylesheet_uri(), ['sapi-maison-fonts'], filemtime(get_stylesheet_directory() . '/style.css'));

  // Menu burger JavaScript - chargé sur toutes les pages
  $menu_js_path = get_template_directory() . '/assets/menu.js';
  wp_enqueue_script('sapi-maison-menu', get_template_directory_uri() . '/assets/menu.js', [], file_exists($menu_js_path) ? filemtime($menu_js_path) : '1.0.0', true);
  wp_localize_script('sapi-maison-menu', 'sapiMenu', [
    'miniCartNonce' => wp_create_nonce('sapi-update-mini-cart-qty'),
    'wcAjaxUrl'     => home_url('/?wc-ajax='),
  ]);

  // Product name formatter - chargé sur toutes les pages (prénom en Montserrat, reste en Square Peg)
  $formatter_js_path = get_template_directory() . '/assets/product-name-formatter.js';
  wp_enqueue_script('sapi-maison-product-formatter', get_template_directory_uri() . '/assets/product-name-formatter.js', [], file_exists($formatter_js_path) ? filemtime($formatter_js_path) : '1.0.0', true);

  /* ⚠️ `inc/guide-data.php` DOIT ÊTRE CHARGÉ ICI, avant le bloc `sapi-project`.
     Ses autres `require_once` sont tous PLUS BAS dans cette fonction, ou
     conditionnés à `is_shop() || is_product()`. `sapi_guide_get_steps()`
     n'existait donc pas au moment de transmettre l'ordre canonique des clés :
     il partait VIDE, sur toutes les pages, et le repli `['piece']` côté JS
     rendait l'étape 3 entièrement inopérante — sans que rien ne casse, et sans
     que la recette puisse le voir, puisque `?piece=salon` seul se comporte
     alors exactement comme avant.
     Trouvé en relecture. Le chargement est idempotent, il n'y a pas de coût. */
  $sapi_guide_data_path = get_template_directory() . '/inc/guide-data.php';
  if (file_exists($sapi_guide_data_path)) require_once $sapi_guide_data_path;

  // Sapi Project (F2a) — module localStorage "Mon projet", chargé toutes pages (léger, ~3KB)
  // Source de vérité pour les cards Conseiller, la modale tunnel, la fiche produit (F2b).
  $sapi_project_js_path = get_template_directory() . '/assets/sapi-project.js';
  if (file_exists($sapi_project_js_path)) {
    wp_enqueue_script('sapi-project', get_template_directory_uri() . '/assets/sapi-project.js', [], filemtime($sapi_project_js_path), true);
    wp_localize_script('sapi-project', 'SAPI_PROJECT', [
      'ajaxUrl' => admin_url('admin-ajax.php'),
      'nonce'   => wp_create_nonce('sapi-megafilter'),
      /* ⚠️ L'ORDRE CANONIQUE DES CLÉS, ET SON DOMICILE UNIQUE.
         Il sert à trois choses qui doivent s'accorder : écrire l'adresse
         toujours dans le même ordre (sinon la même sélection existe en
         centaines de variantes pour le cache et pour Google), calculer la
         signature de déduplication, et effacer proprement toutes les clés
         quand le visiteur recommence.
         Il est ici parce que `sapi-project.js` est le seul fichier chargé sur
         TOUTES les pages où la question se pose. `SAPI_IMMERSION` n'existe que
         sur l'immersion. Ne pas le recopier ailleurs. */
      /* La table « style → essence », telle que Robin l'a réglée dans son
         panneau. Transmise pour que la fiche produit et la photo obéissent au
         MÊME réglage que le moteur de filtrage — sinon le bouton ne piloterait
         que la moitié du site, ce qui serait pire qu'un bouton inerte. */
      'styleEssence' => function_exists('sapi_conseiller_get_rules')
        ? (array) (sapi_conseiller_get_rules()['style_essence'] ?? [])
        : [],
      /* La table « type d'escalier → taille », même origine et même raison que
         `styleEssence` : le navigateur présélectionne la variation, le serveur
         choisit les modèles. S'ils ne lisent pas la même table, le visiteur
         voit une taille recommandée qui ne correspond pas à la sélection. */
      'escalierMap' => function_exists('sapi_conseiller_get_rules')
        ? (array) (sapi_conseiller_get_rules()['escalier_map'] ?? [])
        : [],
      /* Les possessifs tutoyés (« ton salon », « ta cuisine »). Il en existait
         une copie manuscrite dans sapi-modal-conseiller.js, sans lecteur,
         « conservée pour le prochain écran qui tutoiera une pièce » — cet écran
         est arrivé (le rappel de projet du sélecteur), et il est sur des pages
         où ce fichier n'est même pas chargé. On transmet la table PHP plutôt
         que d'en écrire une troisième : « ton » ou « ta » se trompe vite, et
         une divergence donnerait « ton chambre » à un endroit et pas à l'autre. */
      'possessifs' => function_exists('sapi_piece_possessive_map')
        ? sapi_piece_possessive_map() : [],
      'ordreCles' => function_exists('sapi_guide_get_steps')
        ? array_values(array_filter(array_map(function ($st) { return isset($st['id']) ? $st['id'] : ''; }, sapi_guide_get_steps())))
        : [],
    ]);
  }

  // CINÉTIQUE interactions (bento animations, custom cursor, parallax, quantity buttons, showcase slideshow)
  // Chargé sur homepage, pages produit ET pages catégorie
  if (is_front_page() || (class_exists('WooCommerce') && (is_product() || is_product_category()))) {
    $cinetique_js_path = get_template_directory() . '/assets/cinetique.js';
    wp_enqueue_script('sapi-maison-cinetique', get_template_directory_uri() . '/assets/cinetique.js', [], file_exists($cinetique_js_path) ? filemtime($cinetique_js_path) : '1.0.0', true);
  }

  // Homepage fullscreen carousel
  if (is_front_page()) {
    $carousel_js_path = get_template_directory() . '/assets/homepage-carousel.js';
    wp_enqueue_script('sapi-maison-homepage-carousel', get_template_directory_uri() . '/assets/homepage-carousel.js', [], file_exists($carousel_js_path) ? filemtime($carousel_js_path) : '1.0.0', true);
  }

  // FAQ accordion — articles de blog (Yoast FAQ block)
  if (is_single() && 'post' === get_post_type()) {
    $faq_js_path = get_template_directory() . '/assets/faq-accordion.js';
    if (file_exists($faq_js_path)) {
      wp_enqueue_script('sapi-maison-faq-accordion', get_template_directory_uri() . '/assets/faq-accordion.js', [], filemtime($faq_js_path), true);
    }
  }

  // WooCommerce shop interactions (filters, animations)
  if (class_exists('WooCommerce') && (is_shop() || is_product_category() || is_product())) {
    $shop_js_path = get_template_directory() . '/assets/shop.js';
    if (file_exists($shop_js_path)) {
      wp_enqueue_script('sapi-maison-shop', get_template_directory_uri() . '/assets/shop.js', ['jquery'], filemtime($shop_js_path), true);

      // Pass WooCommerce data to JS
      wp_localize_script('sapi-maison-shop', 'sapiShop', [
        'ajaxUrl' => admin_url('admin-ajax.php'),
        'cartUrl' => wc_get_cart_url(),
        'checkoutUrl' => wc_get_checkout_url(),
        'currency' => get_woocommerce_currency_symbol(),
      ]);
    }

    // Editorial Carousel removed — products now displayed in grid

  }

  // Room picker (homepage + page conseils-eclaires) : question pièce (cases) +
  // champ texte libre. Le submit du champ libre redirige vers
  // /mes-creations/?freetext=… pour auto-ouvrir la modale en chat S2.
  /* ⚠️ LA BOUTIQUE AUSSI, DEPUIS LE 28/08. Il y a TROIS sélecteurs de pièce, et
     le troisième sert de hero à /mes-creations/ tant qu'aucune pièce n'est
     choisie. Ce script y est désormais nécessaire : c'est lui qui pose le
     rappel « tu cherchais pour ton salon ». Il ne fait rien s'il ne trouve pas
     de sélecteur — donc rien sur /mes-creations/ avec une pièce déjà choisie,
     où le hero devient l'immersion. (Les fiches produit, elles, ne sont pas
     concernées du tout : `is_shop()` y est faux.) */
  if (is_front_page() || is_page_template('page-conseils-eclaires.php')
      || (class_exists('WooCommerce') && is_shop())) {
    $room_picker_js_path = get_template_directory() . '/assets/sapi-room-picker.js';
    if (file_exists($room_picker_js_path)) {
      wp_enqueue_script(
        'sapi-room-picker',
        get_template_directory_uri() . '/assets/sapi-room-picker.js',
        /* ⚠️ DÉPENDANCE RÉELLE : ce script lit le projet mémorisé. Sans la
           déclarer, l'ordre des balises ne tiendrait qu'à l'ordre des appels
           dans ce fichier — c'est-à-dire à rien. */
        ['sapi-project'],
        filemtime($room_picker_js_path),
        true
      );
      wp_localize_script('sapi-room-picker', 'SAPI_ROOM_PICKER', [
        'creationsUrl' => home_url('/mes-creations/'),
      ]);
    }
  }


  // Méga-filtre intelligent + modale Conseiller V3
  // - is_shop() : tous les scripts (méga-filtre, cards Conseil/Mon projet, modale)
  // - is_product() (F2b) : on étend à la fiche produit pour la modale partagée
  //   (la pill "Comment choisir ?" déclenche un sapi:open-modal).
  if (class_exists('WooCommerce') && (is_shop() || is_product())) {
    require_once get_template_directory() . '/inc/guide-data.php';

    // Pills catégorie sur /mes-creations/ (Chantier 3) — filtrage AJAX-less
    // de la grille basse par data-categories.
    $mes_creations_pills_js_path = get_template_directory() . '/assets/sapi-mes-creations-pills.js';
    if (file_exists($mes_creations_pills_js_path)) {
      wp_enqueue_script(
        'sapi-mes-creations-pills',
        get_template_directory_uri() . '/assets/sapi-mes-creations-pills.js',
        [],
        filemtime($mes_creations_pills_js_path),
        true
      );
    }

    // Tracking GA4 sur /mes-creations/ (Chantier 4) — 5 events via dataLayer.push
    $mes_creations_ga4_js_path = get_template_directory() . '/assets/sapi-mes-creations-ga4.js';
    if (file_exists($mes_creations_ga4_js_path)) {
      wp_enqueue_script(
        'sapi-mes-creations-ga4',
        get_template_directory_uri() . '/assets/sapi-mes-creations-ga4.js',
        [],
        filemtime($mes_creations_ga4_js_path),
        true
      );
    }

    // Tâche 4b — sapi-cards-conseiller.js SUPPRIMÉ : le filtrage JS (règles
    // métier dupliquées + sapiMegaFilter) est retiré, le filtrage est 100%
    // côté serveur. Le room-picker (cartes = liens ?piece=) et le catalogue
    // n'ont plus besoin de ce contrôleur. Les seuls textes qu'il localisait
    // encore utiles (conseils génériques par pièce) sont déplacés sur
    // sapi-modal-conseiller (global SAPI_CARDS_CONSEILLER conservé, cf. plus bas).

    // Refonte /mes-creations/ — état B « immersion » : contrôleur de la
    // séquence (machine à écrire, révélation sélection, header/bandeau
    // over-photo). Chargé partout sur la boutique : il s'auto-désactive si
    // le body n'est pas en mode immersion (pas de ?piece= valide).
    $immersion_js_path = get_template_directory() . '/assets/sapi-mescreations-immersion.js';
    if (file_exists($immersion_js_path)) {
      wp_enqueue_script(
        'sapi-mescreations-immersion',
        get_template_directory_uri() . '/assets/sapi-mescreations-immersion.js',
        ['sapi-project'],
        filemtime($immersion_js_path),
        true
      );
      $immersion_piece = sapi_mescreations_immersion_piece();
      /* ⚠️ LES RÉPONSES COMPLÈTES, PAS SEULEMENT LA PIÈCE.
         Le JS s'en sert pour deux choses, et les deux cassent s'il ne connaît
         que la pièce :
           • semer sa baseline de déduplication — sinon il croit que le serveur
             n'a rendu qu'une sélection niveau-pièce, envoie une requête pour
             rien à chaque visite, et REMPLACE la bonne sélection de l'URL par
             l'ancien projet du visiteur ;
           • écrire le projet mémorisé à l'arrivée, sans re-valider l'URL de
             son côté (ce qui ferait une quatrième copie de la whitelist).
         `signature` est calculée ici, dans l'ordre canonique du questionnaire :
         c'est la seule façon d'être sûr que les deux côtés produisent la même
         chaîne. */
      $immersion_answers = $immersion_piece && function_exists('sapi_mescreations_immersion_answers')
        ? sapi_mescreations_immersion_answers()
        : [];
      if ($immersion_piece) $immersion_answers['piece'] = $immersion_piece;
      wp_localize_script('sapi-mescreations-immersion', 'SAPI_IMMERSION', [
        'ajaxUrl'     => admin_url('admin-ajax.php'),
        'nonce'       => wp_create_nonce('sapi-megafilter'),
        'piece'       => $immersion_piece,
        'possessive'  => $immersion_piece ? sapi_piece_possessive($immersion_piece) : '',
        'answers'     => $immersion_answers,
        'signature'   => function_exists('sapi_immersion_signature')
          ? sapi_immersion_signature($immersion_answers) : '',
      ]);
    }

    // F2a Phase 3 — modale tunnel 2 portes (S0/S1/S3) + Phase 4 (S2)
    $modal_conseiller_js_path = get_template_directory() . '/assets/sapi-modal-conseiller.js';
    if (file_exists($modal_conseiller_js_path)) {
      wp_enqueue_script(
        'sapi-modal-conseiller',
        get_template_directory_uri() . '/assets/sapi-modal-conseiller.js',
        ['sapi-project'],
        filemtime($modal_conseiller_js_path),
        true
      );
      $sapi_modal_tt = sapi_time_trap_new(); // time-trap signé (contact de la modale)
      wp_localize_script('sapi-modal-conseiller', 'SAPI_MODAL_CONSEILLER', [
        'ajaxUrl'        => admin_url('admin-ajax.php'),
        'nonce'          => wp_create_nonce('sapi-megafilter'),
        'ts'             => $sapi_modal_tt['ts'],
        'tsig'           => $sapi_modal_tt['sig'],
        'steps'          => sapi_guide_get_steps(),
        'icons'          => sapi_guide_get_icons(),
        'maxMessages'    => 15,
        // F2b Phase 2 — Mode court : whitelist des steps utilisés sur fiche produit
        // (le reste est skip même si la visibility le permettrait).
        'shortSteps'     => ['piece', 'taille', 'taille_escalier', 'style'],
        // F2b Phase 2 — Conseils de style fixes (pattern legacy pg_style:* —
        // textes pré-générés affichés immédiatement, zéro IA).
        'styleConseils'  => sapi_megafilter_get_style_conseils(),
        'sizeConseils'   => sapi_megafilter_get_size_conseils(),
        // F2b Phase 2 — Contexte produit (null hors is_product, sinon ID + nom).
        'product'        => is_product() ? [
          'id'   => get_queried_object_id(),
          'name' => get_the_title(get_queried_object_id()),
        ] : null,
        // Round 3 — Lot C2/C4 : URL formulaire + email contact pour les CTAs
        // de l'écran s-contact et de la card sur-mesure routée contact.
        'contactSurmesureUrl' => home_url('/sur-mesure/'),
        'contactEmail'        => 'robin@atelier-sapi.fr',
        /* Les trois textes de l'entrée directe par la card sur-mesure, plus la
           table des possessifs à la première personne. Envoyés au JS parce que
           cet écran se remplit sans aller-retour serveur : c'est tout l'intérêt
           du chemin, l'écran s'ouvre instantanément. */
        'surmesure'           => sapi_surmesure_contact_texts(),
        'possessifsMiens'     => sapi_piece_possessive_mienne_map(),
      ]);
      // Tâche 4b — conseils génériques par pièce (bulle initiale du chat),
      // anciennement portés par SAPI_CARDS_CONSEILLER de sapi-cards-conseiller.js
      // (supprimé). On garde le même nom de global, réduit aux clés que la
      // modale lit réellement (getInitialChatAdvice : genericAdvice + fallback).
      wp_localize_script('sapi-modal-conseiller', 'SAPI_CARDS_CONSEILLER', [
        'genericAdvice'  => sapi_megafilter_get_generic_advices(),
        'fallbackAdvice' => __('Voici la sélection que je te propose dans le catalogue de Robin.', 'theme-sapi-maison'),
      ]);
    }

    // F2b — Pill "Comment choisir ?" / "Adapter à mon projet" sur fiche produit
    if (is_product()) {
      $help_pill_js_path = get_template_directory() . '/assets/sapi-help-pill.js';
      if (file_exists($help_pill_js_path)) {
        wp_enqueue_script(
          'sapi-help-pill',
          get_template_directory_uri() . '/assets/sapi-help-pill.js',
          // `jquery` : la pill écoute found_variation / reset_data, qui sont
          // des événements jQuery de WooCommerce. Ça marchait sans, par chance.
          ['sapi-project', 'sapi-modal-conseiller', 'jquery'],
          filemtime($help_pill_js_path),
          true
        );
      }

      // F2b Phase 3 — Pré-sélection variation au load + listener apply event
      $preselect_js_path = get_template_directory() . '/assets/sapi-product-preselect.js';
      if (file_exists($preselect_js_path)) {
        wp_enqueue_script(
          'sapi-product-preselect',
          get_template_directory_uri() . '/assets/sapi-product-preselect.js',
          ['sapi-project', 'jquery'],
          filemtime($preselect_js_path),
          true
        );
      }
    }

  }

  // Cart page JS — enqueued when is_cart() returns true
  if (class_exists('WooCommerce') && is_cart()) {
    $cart_js_path = get_template_directory() . '/assets/cart-page.js';
    if (file_exists($cart_js_path)) {
      wp_enqueue_script('sapi-maison-cart-page', get_template_directory_uri() . '/assets/cart-page.js', [], filemtime($cart_js_path), true);
    }
  }

  // Galerie Inspiration — page autonome (CSS dédié, masonry CSS columns).
  // Slug 'inspiration' → WordPress charge page-inspiration.php via la hiérarchie
  // de templates, sans qu'il faille assigner le template depuis l'admin.
  if (is_page('inspiration') || is_page_template('page-inspiration.php')) {
    $inspiration_css_path = get_template_directory() . '/assets/inspiration.css';
    if (file_exists($inspiration_css_path)) {
      wp_enqueue_style('sapi-maison-inspiration', get_template_directory_uri() . '/assets/inspiration.css', ['sapi-maison-style'], filemtime($inspiration_css_path));
    }
    $inspiration_js_path = get_template_directory() . '/assets/inspiration.js';
    if (file_exists($inspiration_js_path)) {
      wp_enqueue_script('sapi-maison-inspiration', get_template_directory_uri() . '/assets/inspiration.js', [], filemtime($inspiration_js_path), true);
      wp_localize_script('sapi-maison-inspiration', 'sapiInspiration', [
        'ajaxUrl' => admin_url('admin-ajax.php'),
        'nonce'   => wp_create_nonce('sapi_inspiration_brevo_nonce'),
      ]);
    }
  }


  // Scroll Dots — mobile slide indicators (grilles verticales → sliders horizontaux)
  $scroll_dots_path = get_template_directory() . '/assets/scroll-dots.js';
  if (file_exists($scroll_dots_path)) {
    wp_enqueue_script('sapi-maison-scroll-dots', get_template_directory_uri() . '/assets/scroll-dots.js', [], filemtime($scroll_dots_path), true);
  }

  // Guide luminaire — helpers PHP utilisés par le méga-filtre (et F1b à venir)
  require_once get_template_directory() . '/inc/guide-data.php';

  // Bandeau réassurance : randomisation mobile + cleanup localStorage legacy
  $bandeau_path = get_template_directory() . '/assets/bandeau-reassurance.js';
  if (file_exists($bandeau_path)) {
    wp_enqueue_script('sapi-bandeau-reassurance', get_template_directory_uri() . '/assets/bandeau-reassurance.js', [], filemtime($bandeau_path), true);
  }
}
add_action('wp_enqueue_scripts', 'sapi_maison_enqueue_assets');

/**
 * Tracking des produits récemment consultés (cookie woocommerce_recently_viewed)
 * WooCommerce ne remplit ce cookie que si le widget "Recently Viewed" est actif.
 * On le gère manuellement ici pour toujours avoir les données.
 */
add_action('template_redirect', function () {
  if (!class_exists('WooCommerce')) return; // garde WC explicite (wc_setcookie plus bas)
  if (!is_singular('product')) return;

  $product_id = get_queried_object_id();
  if (!$product_id) return;

  $viewed = !empty($_COOKIE['woocommerce_recently_viewed'])
    ? array_filter(array_map('absint', explode('|', wp_unslash($_COOKIE['woocommerce_recently_viewed']))))
    : [];

  // Retirer l'ID s'il existe déjà pour le mettre en premier
  $viewed = array_diff($viewed, [$product_id]);
  array_unshift($viewed, $product_id);

  // Limiter à 15 produits
  $viewed = array_slice($viewed, 0, 15);

  wc_setcookie('woocommerce_recently_viewed', implode('|', $viewed));
});

/**
 * Shortcode classique [woocommerce_cart] — Enveloppe dans .sapi-cart-outer
 * Injecte la progress bar + réassurances (même rendu que le bloc WC Blocks)
 */
add_action('woocommerce_before_cart', function () {
  ?>
  <div class="sapi-cart-outer">
    <div class="cart-page-cinetique">
      <div class="checkout-progress">
        <div class="progress-step active">
          <span class="step-number">1</span>
          <span class="step-label"><?php esc_html_e('Panier', 'theme-sapi-maison'); ?></span>
        </div>
        <div class="progress-line"></div>
        <div class="progress-step">
          <span class="step-number">2</span>
          <span class="step-label"><?php esc_html_e('Paiement', 'theme-sapi-maison'); ?></span>
        </div>
      </div>
      <p class="cart-subtitle"><?php esc_html_e('Plus que quelques clics avant de recevoir votre luminaire !', 'theme-sapi-maison'); ?></p>
  <?php
});

add_action('woocommerce_after_cart', function () {
  ?>
      <div class="cart-reassurance">
        <div class="reassurance-item">
          <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
            <circle cx="12" cy="12" r="10"/>
            <polyline points="12 6 12 12 16 14"/>
          </svg>
          <div class="reassurance-text">
            <strong><?php esc_html_e('Fabrication < 5 jours', 'theme-sapi-maison'); ?></strong>
            <span><?php esc_html_e('Fait main dans l\'atelier lyonnais de Robin', 'theme-sapi-maison'); ?></span>
          </div>
        </div>
        <div class="reassurance-item">
          <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
            <rect x="1" y="3" width="15" height="13"/>
            <polygon points="16 8 20 8 23 11 23 16 16 16 16 8"/>
            <circle cx="5.5" cy="18.5" r="2.5"/>
            <circle cx="18.5" cy="18.5" r="2.5"/>
          </svg>
          <div class="reassurance-text">
            <strong><?php esc_html_e('Livraison 48-72h', 'theme-sapi-maison'); ?></strong>
            <span><?php esc_html_e('Soigneusement emballé', 'theme-sapi-maison'); ?></span>
          </div>
        </div>
        <div class="reassurance-item">
          <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
            <polyline points="1 4 1 10 7 10"/>
            <path d="M3.51 15a9 9 0 1 0 2.13-9.36L1 10"/>
          </svg>
          <div class="reassurance-text">
            <strong><?php esc_html_e('Retours 30 jours', 'theme-sapi-maison'); ?></strong>
            <span><?php esc_html_e('Satisfait ou remboursé', 'theme-sapi-maison'); ?></span>
          </div>
        </div>
      </div>
    </div>
  </div>
  <?php
});

/**
 * render_block — Enveloppe le bloc panier WooCommerce dans .sapi-cart-outer
 * Ce wrapper est injecté côté PHP AVANT React et ne sera jamais touché par React.
 * Il permet de scoper notre CSS avec une spécificité garantie.
 */
// Remplace "Supprimer l'élément" par "Supprimer" dans le panier (rendu React)
add_action('wp_footer', function () {
  if (!function_exists('is_cart') || !is_cart()) return;

  // Mapping slug → product_id pour les cross-sells (utilisé par le JS ci-dessous)
  $cross_sell_ids = WC()->cart->get_cross_sells();
  $slug_map = [];
  foreach ($cross_sell_ids as $pid) {
    $p = wc_get_product($pid);
    if ($p) {
      $slug_map[$p->get_slug()] = $pid;
    }
  }
  ?>
  <script>
  var sapiCrossSellMap = <?php echo wp_json_encode($slug_map); ?>;
  (function () {
    function replaceCartTexts() {
      // "Supprimer l'élément" → "Supprimer"
      document.querySelectorAll('.wc-block-cart-item__remove-link').forEach(function (el) {
        if (el.textContent.trim() === "Supprimer l\u2019\u00e9l\u00e9ment") {
          el.textContent = 'Supprimer';
        }
      });
      // Titre cross-sell → texte personnalisé
      document.querySelectorAll('.wp-block-heading').forEach(function (el) {
        if (el.textContent.trim().indexOf('peut vous int') !== -1) {
          el.textContent = 'Avez-vous d\u00e9j\u00e0 la bonne ampoule\u00a0?';
        }
      });
    }
    // Cross-sell: ajout direct au panier en AJAX (un clic = produit ajouté)
    function fixCrossSellButtons() {
      document.querySelectorAll('.wc-block-cart__main .wc-block-components-product-button__button').forEach(function (btn) {
        if (btn.dataset.fixedAjax) return;
        btn.dataset.fixedAjax = 'true';

        var li = btn.closest('li') || btn.closest('.wc-block-grid__product');
        if (!li) return;
        var productLink = li.querySelector('a[href]');
        if (!productLink) return;
        var url = productLink.getAttribute('href');

        // Extraire le slug depuis l'URL du produit
        var slug = url.replace(/\/$/, '').split('/').pop();
        var productId = (window.sapiCrossSellMap || {})[slug];
        if (!productId) return;

        var originalText = btn.textContent;

        btn.addEventListener('click', function (e) {
          e.preventDefault();
          e.stopPropagation();
          e.stopImmediatePropagation();

          if (btn.disabled) return;
          btn.disabled = true;
          btn.textContent = 'Ajout\u2026';

          var formData = new FormData();
          formData.append('product_id', productId);
          formData.append('quantity', 1);

          fetch('/?wc-ajax=add_to_cart', {
            method: 'POST',
            body: formData
          })
          .then(function (r) { return r.json(); })
          .then(function (data) {
            if (data.error) {
              btn.textContent = 'Erreur';
              setTimeout(function () {
                btn.textContent = originalText;
                btn.disabled = false;
              }, 2000);
            } else {
              btn.textContent = 'Ajout\u00e9 \u2713';
              setTimeout(function () { location.reload(); }, 800);
            }
          })
          .catch(function () {
            btn.textContent = 'Erreur';
            setTimeout(function () {
              btn.textContent = originalText;
              btn.disabled = false;
            }, 2000);
          });
        }, true);
      });
    }

    replaceCartTexts();
    fixCrossSellButtons();
    new MutationObserver(function () {
      replaceCartTexts();
      fixCrossSellButtons();
    }).observe(document.body, { childList: true, subtree: true });
  })();
  </script>
  <?php
});

// Panier vide : 3 derniers produits vus (fallback produits populaires) + remplacement JS
add_action('wp_footer', function () {
  if (!function_exists('is_cart') || !is_cart()) return;
  if (!WC()->cart || !WC()->cart->is_empty()) return;

  $products_data = [];
  $has_viewed = false;

  // 1. Essayer les produits récemment vus (cookie WooCommerce)
  if (!empty($_COOKIE['woocommerce_recently_viewed'])) {
    $viewed_ids = array_filter(array_map('absint', explode('|', wp_unslash($_COOKIE['woocommerce_recently_viewed']))));
    $viewed_ids = array_slice($viewed_ids, 0, 3);
    foreach ($viewed_ids as $vid) {
      $vp = wc_get_product($vid);
      if (!$vp || !$vp->is_visible()) continue;
      $name_parts = explode(' ', $vp->get_name(), 2);
      $vp_cats = get_the_terms($vid, 'product_cat');
      $vp_cat_name = '';
      if ($vp_cats && !is_wp_error($vp_cats)) {
        foreach ($vp_cats as $vc) {
          if ($vc->slug !== 'non-classe' && $vc->slug !== 'uncategorized') { $vp_cat_name = $vc->name; break; }
        }
      }
      $products_data[] = [
        'url'   => $vp->get_permalink(),
        'name'  => $name_parts[0],
        'desc'  => isset($name_parts[1]) ? $name_parts[1] : '',
        'cat'   => $vp_cat_name,
        'img'   => get_the_post_thumbnail_url($vid, 'medium') ?: '',
      ];
    }
    if (count($products_data) > 0) {
      $has_viewed = true;
    }
  }

  // 2. Fallback : 3 produits les plus vendus
  if (count($products_data) < 3) {
    // Récupérer les IDs déjà présents pour ne pas les dupliquer
    $existing_urls = array_column($products_data, 'url');
    $popular_query = new WP_Query([
      'post_type'      => 'product',
      'posts_per_page' => 3 - count($products_data),
      'post_status'    => 'publish',
      'meta_key'       => 'total_sales',
      'orderby'        => 'meta_value_num',
      'order'          => 'DESC',
      'tax_query'      => [[
        'taxonomy' => 'product_visibility',
        'field'    => 'name',
        'terms'    => 'exclude-from-catalog',
        'operator' => 'NOT IN',
      ]],
    ]);
    if ($popular_query->have_posts()) {
      while ($popular_query->have_posts()) {
        $popular_query->the_post();
        $p = wc_get_product(get_the_ID());
        if (!$p) continue;
        if (in_array($p->get_permalink(), $existing_urls)) continue;
        $name_parts = explode(' ', $p->get_name(), 2);
        $p_cats = get_the_terms(get_the_ID(), 'product_cat');
        $p_cat_name = '';
        if ($p_cats && !is_wp_error($p_cats)) {
          foreach ($p_cats as $pc) {
            if ($pc->slug !== 'non-classe' && $pc->slug !== 'uncategorized') { $p_cat_name = $pc->name; break; }
          }
        }
        $products_data[] = [
          'url'   => get_permalink(),
          'name'  => $name_parts[0],
          'desc'  => isset($name_parts[1]) ? $name_parts[1] : '',
          'cat'   => $p_cat_name,
          'img'   => get_the_post_thumbnail_url(get_the_ID(), 'medium') ?: '',
        ];
        if (count($products_data) >= 3) break;
      }
      wp_reset_postdata();
    }
  }

  $shop_url = esc_url(wc_get_page_permalink('shop'));
  ?>
  <script>
  (function() {
    var popularProducts = <?php echo wp_json_encode(array_values($products_data)); ?>;
    var shopUrl = <?php echo wp_json_encode($shop_url); ?>;
    var sectionTitle = <?php echo wp_json_encode($has_viewed ? 'Consultés récemment' : 'La sélection de l\'artisan'); ?>;
    var done = false;

    function replaceEmptyCart() {
      if (done) return;

      // Chercher le texte "vide" partout dans la page (tous types d'éléments)
      var allEls = document.querySelectorAll('h1, h2, h3, h4, p, strong, span, div');
      var emptyMsg = null;
      for (var i = 0; i < allEls.length; i++) {
        if (allEls[i].textContent.indexOf('vide') !== -1 && allEls[i].children.length < 3) {
          emptyMsg = allEls[i];
          break;
        }
      }
      if (!emptyMsg) return;

      done = true;

      // Remonter au conteneur le plus logique
      var container = emptyMsg.closest('.wp-block-woocommerce-empty-cart-block')
        || emptyMsg.closest('.wp-block-woocommerce-cart')
        || emptyMsg.closest('.wc-block-cart')
        || emptyMsg.closest('.sapi-cart-outer')
        || emptyMsg.closest('.page-content')
        || emptyMsg.closest('main')
        || emptyMsg.parentElement;

      var productsHTML = '';
      if (popularProducts.length > 0) {
        productsHTML = '<div class="empty-cart-viewed"><h2 class="empty-cart-viewed-title">' + sectionTitle + '</h2><div class="empty-cart-viewed-grid">';
        for (var j = 0; j < popularProducts.length; j++) {
          var pr = popularProducts[j];
          productsHTML += '<a href="' + pr.url + '" class="empty-cart-viewed-card">';
          if (pr.img) {
            productsHTML += '<div class="empty-cart-viewed-image"><img src="' + pr.img + '" alt="' + pr.name + '" loading="lazy" /></div>';
          }
          productsHTML += '<div class="empty-cart-viewed-info">';
          productsHTML += '<h3>' + pr.name + '</h3>';
          if (pr.desc) productsHTML += '<span class="empty-cart-viewed-desc">' + pr.desc + '</span>';
          if (pr.cat) productsHTML += '<span class="empty-cart-viewed-cat">' + pr.cat + '</span>';
          productsHTML += '</div></a>';
        }
        productsHTML += '</div></div>';
      }

      container.innerHTML =
        '<section class="empty-cart-page">' +
          '<div class="empty-cart-content">' +
            '<div class="empty-cart-icon"><svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5"><circle cx="9" cy="21" r="1"/><circle cx="20" cy="21" r="1"/><path d="M1 1h4l2.68 13.39a2 2 0 0 0 2 1.61h9.72a2 2 0 0 0 2-1.61L23 6H6"/></svg></div>' +
            '<h1 class="empty-cart-title">Votre panier est vide... pour l\u2019instant\u00a0!</h1>' +
            '<p class="empty-cart-text">Les luminaires de Robin n\u2019attendent que vous. Laissez-vous inspirer par ses cr\u00e9ations artisanales.</p>' +
          '</div>' +
          '<div class="empty-cart-cta"><a href="' + shopUrl + '" class="empty-cart-btn">D\u00e9couvrir les cr\u00e9ations</a></div>' +
          productsHTML +
        '</section>';
    }

    // Tenter plusieurs fois après le rendu React (mobile peut être plus lent)
    setTimeout(replaceEmptyCart, 300);
    setTimeout(replaceEmptyCart, 800);
    setTimeout(replaceEmptyCart, 1500);
    setTimeout(replaceEmptyCart, 3000);
    setTimeout(replaceEmptyCart, 5000);
    var obs = new MutationObserver(function() { if (!done) replaceEmptyCart(); });
    obs.observe(document.body, { childList: true, subtree: true, characterData: true });
  })();
  </script>
  <?php
});

// Supprime la limite de caractères sur la description courte dans le panier WooCommerce Blocks
add_filter('wc_blocks_product_short_description_character_limit', '__return_false');


add_filter('render_block', function ($content, $block) {
  if ($block['blockName'] === 'woocommerce/cart') {
    ob_start();
    ?>
    <div class="sapi-cart-outer">
      <div class="cart-page-cinetique">
        <div class="checkout-progress">
          <div class="progress-step active">
            <span class="step-number">1</span>
            <span class="step-label"><?php esc_html_e('Panier', 'theme-sapi-maison'); ?></span>
          </div>
          <div class="progress-line"></div>
          <div class="progress-step">
            <span class="step-number">2</span>
            <span class="step-label"><?php esc_html_e('Paiement', 'theme-sapi-maison'); ?></span>
          </div>
        </div>
        <p class="cart-subtitle"><?php esc_html_e('Plus que quelques clics avant de recevoir votre luminaire !', 'theme-sapi-maison'); ?></p>
      <?php echo $content; ?>
        <div class="cart-reassurance">
          <div class="reassurance-item">
            <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
              <circle cx="12" cy="12" r="10"/>
              <polyline points="12 6 12 12 16 14"/>
            </svg>
            <div class="reassurance-text">
              <strong><?php esc_html_e('Fabrication < 5 jours', 'theme-sapi-maison'); ?></strong>
              <span><?php esc_html_e('Fait main dans l\'atelier lyonnais de Robin', 'theme-sapi-maison'); ?></span>
            </div>
          </div>
          <div class="reassurance-item">
            <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
              <rect x="1" y="3" width="15" height="13"/>
              <polygon points="16 8 20 8 23 11 23 16 16 16 16 8"/>
              <circle cx="5.5" cy="18.5" r="2.5"/>
              <circle cx="18.5" cy="18.5" r="2.5"/>
            </svg>
            <div class="reassurance-text">
              <strong><?php esc_html_e('Livraison 48-72h', 'theme-sapi-maison'); ?></strong>
              <span><?php esc_html_e('Soigneusement emballé', 'theme-sapi-maison'); ?></span>
            </div>
          </div>
          <div class="reassurance-item">
            <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
              <polyline points="1 4 1 10 7 10"/>
              <path d="M3.51 15a9 9 0 1 0 2.13-9.36L1 10"/>
            </svg>
            <div class="reassurance-text">
              <strong><?php esc_html_e('Retours 30 jours', 'theme-sapi-maison'); ?></strong>
              <span><?php esc_html_e('Satisfait ou remboursé', 'theme-sapi-maison'); ?></span>
            </div>
          </div>
        </div>
      </div>
    </div>
    <script>
    (function() {
      function addShippingNote() {
        var shipping = document.querySelector('.sapi-cart-outer .wc-block-components-totals-shipping');
        if (shipping && !shipping.querySelector('.cart-shipping-note')) {
          var note = document.createElement('p');
          note.className = 'cart-shipping-note';
          note.textContent = 'Choisissez votre mode de livraison \u00e0 la prochaine \u00e9tape';
          shipping.appendChild(note);
        }
      }
      // WC Blocks renders via React — observe DOM changes
      var observer = new MutationObserver(addShippingNote);
      var target = document.querySelector('.sapi-cart-outer');
      if (target) observer.observe(target, { childList: true, subtree: true });
      addShippingNote();
    })();
    </script>
    <?php
    return ob_get_clean();
  }
  if ($block['blockName'] === 'woocommerce/checkout') {
    // Sur la page thank you, le template thankyou.php gère déjà sa propre progress bar
    if (function_exists('is_wc_endpoint_url') && is_wc_endpoint_url('order-received')) {
      return $content;
    }
    ob_start();
    ?>
    <div class="sapi-checkout-outer">
      <div class="checkout-page-cinetique">
        <div class="checkout-progress">
          <div class="progress-step completed">
            <span class="step-number">1</span>
            <span class="step-label"><?php esc_html_e('Panier', 'theme-sapi-maison'); ?></span>
          </div>
          <div class="progress-line completed"></div>
          <div class="progress-step active">
            <span class="step-number">2</span>
            <span class="step-label"><?php esc_html_e('Paiement', 'theme-sapi-maison'); ?></span>
          </div>
        </div>
        <h1 class="checkout-title"><?php esc_html_e('Finaliser ma commande', 'theme-sapi-maison'); ?></h1>
        <p class="checkout-subtitle"><?php esc_html_e('Votre luminaire sera bientôt chez vous !', 'theme-sapi-maison'); ?></p>
      <?php echo $content; ?>
      </div>
    </div>
    <?php
    return ob_get_clean();
  }
  return $content;
}, 10, 2);

/**
 * Helper: extract video thumbnail URL from YouTube or Vimeo URL
 */
function sapi_get_video_thumbnail($url) {
  if (!$url) return '';

  // YouTube (watch, embed, shorts, youtu.be)
  if (preg_match('/(?:youtube\.com\/(?:watch\?v=|embed\/|shorts\/)|youtu\.be\/)([a-zA-Z0-9_-]{11})/', $url, $m)) {
    return 'https://img.youtube.com/vi/' . $m[1] . '/hqdefault.jpg';
  }

  // Vimeo – use oembed API (cached by WordPress transients)
  if (preg_match('/vimeo\.com\/(\d+)/', $url, $m)) {
    $transient_key = 'sapi_vimeo_thumb_' . $m[1];
    $cached = get_transient($transient_key);
    if ($cached) return $cached;

    $response = wp_remote_get('https://vimeo.com/api/v2/video/' . $m[1] . '.json');
    if (!is_wp_error($response)) {
      $data = json_decode(wp_remote_retrieve_body($response), true);
      if (!empty($data[0]['thumbnail_large'])) {
        set_transient($transient_key, $data[0]['thumbnail_large'], DAY_IN_SECONDS * 30);
        return $data[0]['thumbnail_large'];
      }
    }
  }

  return '';
}

/**
 * Mapping `type_photo` (string du repeater) → nom du champ Gallery ACF cible.
 * Source de vérité unique, partagée entre la migration Phase 2
 * (inc/sapi-migrate-galerie.php) et le helper dual-read Phase 3
 * (sapi_get_product_photo_ids).
 *
 * Tolère 'taille'/'tailles' et 'studio'/'packshot' (legacy → canonique).
 *
 * @param string $type Type photo (ex: 'ambiance', 'detail', 'vue de dessous')
 * @return string|null Nom du Gallery ACF (ex: 'galerie_ambiance') ou null si type inconnu
 */
function sapi_type_to_gallery_name($type) {
  static $map = [
    'ambiance'         => 'galerie_ambiance',
    'detail'           => 'galerie_detail',
    'vue de dessous'   => 'galerie_vue_de_dessous',
    'taille'           => 'galerie_tailles',   // singulier dans repeater → pluriel dans Gallery
    'tailles'          => 'galerie_tailles',
    'studio'           => 'galerie_packshot',  // legacy "studio" remappé sur packshot
    'packshot'         => 'galerie_packshot',
    'fabrication'      => 'galerie_fabrication',
    'client'           => 'galerie_client',
    'accessoires'      => 'galerie_accessoires',
  ];
  $type = (string) $type;
  return isset($map[$type]) ? $map[$type] : null;
}

/**
 * Récupère les IDs d'attachments d'un produit, filtré par type / pièce / essence.
 *
 * Lit exclusivement les 8 Gallery ACF (galerie_ambiance, galerie_detail, etc.)
 * via le mapping `sapi_type_to_gallery_name()`. Si la Gallery cible est vide,
 * retourne `[]` — le repeater `galerie_produit` n'est plus consulté en
 * fallback (retiré en S28 Phase 6b-1, après que toutes les surfaces aient
 * migré vers les Gallery).
 *
 * Pour le fallback "pièce sans photo taguée → photo par défaut" (sémantique
 * Phase 4b), voir le wrapper `sapi_get_product_photo_ids_with_fallback`.
 *
 * @param int|WP_Post $post_id  ID du produit (ou WP_Post)
 * @param string      $type     Type photo ('ambiance', 'detail', 'fabrication',
 *                              'client', 'tailles', 'packshot', 'vue de dessous',
 *                              'accessoires') ou '' pour tous les types
 * @param int         $limit    Max N IDs retournés (0 = sans limite)
 * @param string|null $piece    Slug taxonomie media_room (ex: 'salon') ou null
 * @param string|null $essence  Slug taxonomie media_essence (ex: 'peuplier') ou null
 * @return int[] Array d'IDs d'attachments, ordre préservé, sans 0
 */
function sapi_get_product_photo_ids($post_id, $type = '', $limit = 0, $piece = null, $essence = null) {
  if (!$post_id || !function_exists('get_field')) return [];

  $ids = [];

  if ($type === '') {
    // Tous types : concatène les 8 Gallery dans l'ordre canonique puis dédoublonne.
    $all_types_ordered = ['ambiance', 'detail', 'vue de dessous', 'tailles', 'packshot', 'fabrication', 'client', 'accessoires'];
    foreach ($all_types_ordered as $t) {
      $ids = array_merge($ids, sapi_get_product_photo_ids($post_id, $t, 0, null, null));
    }
    $ids = array_values(array_unique($ids));
  } else {
    // Type spécifique : lecture exclusive de la Gallery cible.
    $gallery_name = sapi_type_to_gallery_name($type);
    if ($gallery_name) {
      $gallery_ids = get_field($gallery_name, $post_id);
      if (!empty($gallery_ids) && is_array($gallery_ids)) {
        foreach ($gallery_ids as $item) {
          $id = sapi_get_acf_image_id($item);
          if ($id) $ids[] = (int) $id;
        }
      }
    }
  }

  // Filtres taxonomies APRÈS le merge — on filtre le pool complet,
  // pas la troncature.
  if ($piece !== null && $piece !== '' && !empty($ids)) {
    $ids = sapi_filter_attachment_ids_by_term($ids, 'media_room', $piece);
  }
  if ($essence !== null && $essence !== '' && !empty($ids)) {
    $ids = sapi_filter_attachment_ids_by_term($ids, 'media_essence', $essence);
  }

  // Limit appliqué en dernier (après filtres taxo).
  if ($limit > 0 && count($ids) > $limit) {
    $ids = array_slice($ids, 0, $limit);
  }

  return $ids;
}

/**
 * Wrapper auto-fallback du helper canonique (S28 Phase 4b).
 *
 * Stratégie : essaie ($piece + $essence) → si vide, retire $essence → si encore
 * vide, retire $piece aussi. Le helper canonique sapi_get_product_photo_ids
 * reste sémantiquement strict (jamais de fallback silencieux) — ce wrapper
 * est dédié aux surfaces "swap par pièce" (Phase 4b) qui veulent une photo
 * de la pièce SI possible, et sinon retomber gracieusement sur la photo
 * par défaut sans afficher de trou.
 *
 * @param int|WP_Post $post_id ID du produit (ou WP_Post)
 * @param string      $type    Type photo ('ambiance', 'detail', ...) ou ''
 * @param int         $limit   Max N IDs (0 = sans limite)
 * @param string|null $piece   Slug media_room ou null
 * @param string|null $essence Slug media_essence ou null
 * @return int[] IDs d'attachments, jamais vide tant que le produit a au moins
 *               une photo du $type demandé (ou tout type si $type === '')
 */
function sapi_get_product_photo_ids_with_fallback($post_id, $type = '', $limit = 0, $piece = null, $essence = null) {
  $has_piece   = ($piece !== null && $piece !== '');
  $has_essence = ($essence !== null && $essence !== '');

  // Tentative 1 : avec tous les filtres
  $ids = sapi_get_product_photo_ids($post_id, $type, $limit, $piece, $essence);
  if (!empty($ids)) return $ids;

  // Tentative 2 : on retire $essence (le filtre essence est plus restrictif que pièce)
  if ($has_piece && $has_essence) {
    $ids = sapi_get_product_photo_ids($post_id, $type, $limit, $piece, null);
    if (!empty($ids)) return $ids;
  }

  // Tentative 3 : on retire $piece aussi → photo par défaut
  if ($has_piece || $has_essence) {
    $ids = sapi_get_product_photo_ids($post_id, $type, $limit, null, null);
  }

  return $ids;
}

/**
 * Le prix d'un produit peut-il monter à cause d'un Product Add-On payant ?
 *
 * Sert à décider si le label « À partir de » du bloc prix est vrai. Sur un
 * produit simple sans add-on payant, ce label annonce une version plus chère
 * qui n'existe pas.
 *
 * ⚠️ On passe par le helper du plugin (`get_product_addons`) et PAS par le
 * meta `_product_addons` en direct : le meta ne contient que les add-ons posés
 * sur CE produit, alors que le plugin en ajoute aussi par catégorie (add-ons
 * globaux). Lire le meta seul ferait disparaître le label sur un produit qui a
 * bel et bien des options payantes, sans que rien ne le signale.
 *
 * Plugin absent ou désactivé = aucun add-on nulle part = le prix ne peut pas
 * monter par ce biais. `false` est alors la bonne réponse, pas un repli.
 *
 * @param int $product_id
 * @return bool
 */
function sapi_product_has_paid_addon($product_id) {
  if (!$product_id || !class_exists('WC_Product_Addons_Helper')) return false;

  $addons = WC_Product_Addons_Helper::get_product_addons($product_id);
  if (empty($addons) || !is_array($addons)) return false;

  foreach ($addons as $addon) {
    $type = isset($addon['type']) ? $addon['type'] : '';

    // 'heading' est purement décoratif, il ne porte jamais de prix.
    if ($type === 'heading') continue;

    // 'custom_price' : c'est l'acheteur qui saisit le montant. Rien n'est stocké
    // dans la configuration, et pourtant le total monte.
    if ($type === 'custom_price') return true;

    // ⚠️ Depuis la v3.0 du plugin, `options` n'existe QUE pour multiple_choice
    // et checkbox. Les sept autres types (input_multiplier, custom_text,
    // custom_textarea, file_upload, datepicker, heading, custom_price) portent
    // leur prix ICI, au niveau de l'add-on. Ne lire que `options` marcherait
    // aujourd'hui — les deux seuls add-ons du site sont des multiple_choice —
    // et le jour où une gravure à 15 € est ajoutée, le label disparaîtrait
    // sans un mot alors que le prix monte. Vérifié dans addons.js 8.4.1 :
    // la branche input_multiplier lit `$addon.data('price')`, jamais d'options.
    if (isset($addon['price']) && (float) $addon['price'] > 0) return true;

    // multiple_choice et checkbox : la seule famille dont le prix vit dans les
    // options. Couvre flat_fee, quantity_based et percentage_based — dans les
    // trois cas une valeur strictement positive augmente le total.
    if (empty($addon['options']) || !is_array($addon['options'])) continue;
    foreach ($addon['options'] as $option) {
      if (isset($option['price']) && (float) $option['price'] > 0) return true;
    }
  }

  return false;
}

/**
 * Handler AJAX (S28 Phase 4b) — renvoie pour une liste d'IDs produit la map
 * `{ product_id: [url1, url2, ...] }` des photos filtrées par pièce/essence,
 * avec auto-fallback sur la photo par défaut si la pièce n'a aucune photo
 * taguée. Consommé par assets/sapi-photo-swap.js.
 *
 * Paramètres GET :
 *  - piece   : slug media_room (ex: 'salon'), obligatoire mais peut être ''
 *  - essence : slug media_essence (ex: 'peuplier'), optionnel
 *  - ids     : CSV d'IDs produit (max 50)
 *  - type    : 'ambiance' | 'detail' (whitelisté)
 *  - count   : 1 ou 2 (clamp 1..4)
 *  - size    : whitelist d'image sizes WP
 *  - _wpnonce : nonce sapi_photo_swap
 *
 * Réponse : JSON { "5035": ["https://...", "https://..."], "5036": [...], ... }
 * Produits sans piece-photo NE FIGURENT PAS dans la map (le défaut côté front
 * reste, progressive enhancement).
 */
function sapi_ajax_get_piece_photos() {
  check_ajax_referer('sapi_photo_swap', '_wpnonce');

  $piece   = isset($_GET['piece'])   ? sanitize_key(wp_unslash($_GET['piece']))   : '';
  $essence = isset($_GET['essence']) ? sanitize_key(wp_unslash($_GET['essence'])) : '';
  $type    = isset($_GET['type'])    ? sanitize_key(wp_unslash($_GET['type']))    : 'ambiance';
  $count   = isset($_GET['count'])   ? (int) $_GET['count'] : 1;
  $size    = isset($_GET['size'])    ? sanitize_key(wp_unslash($_GET['size']))    : 'large';

  $valid_types = ['ambiance', 'detail', 'fabrication', 'client', 'tailles', 'packshot', 'accessoires'];
  if (!in_array($type, $valid_types, true)) $type = 'ambiance';

  // Sizes whitelistées — celles effectivement utilisées par les call-sites.
  $valid_sizes = ['large', 'full', 'woocommerce_thumbnail', 'woocommerce_single'];
  if (!in_array($size, $valid_sizes, true)) $size = 'large';

  if ($count < 1) $count = 1;
  if ($count > 4) $count = 4;

  $raw_ids = isset($_GET['ids']) ? explode(',', sanitize_text_field(wp_unslash($_GET['ids']))) : [];
  $ids = [];
  foreach ($raw_ids as $r) {
    $i = (int) $r;
    if ($i > 0) $ids[] = $i;
    if (count($ids) >= 50) break; // hard cap anti-abus
  }

  $piece_or_null   = $piece !== ''   ? $piece   : null;
  $essence_or_null = $essence !== '' ? $essence : null;

  $map = [];
  foreach ($ids as $pid) {
    // Wrapper auto-fallback : si la pièce n'a pas de photo, retombe sur le défaut.
    // Mais ici on veut RIEN renvoyer si la photo retournée = la photo par défaut,
    // pour économiser des swaps inutiles. → on appelle d'abord SANS fallback,
    // et si vide, on ne renvoie rien. Le client garde son rendu PHP par défaut.
    if ($piece_or_null === null && $essence_or_null === null) continue; // pas de pièce ni d'essence → no-op
    $photo_ids = sapi_get_product_photo_ids($pid, $type, $count, $piece_or_null, $essence_or_null);
    if (empty($photo_ids)) {
      // 2e tentative : sans essence (si on en avait une)
      if ($essence_or_null !== null) {
        $photo_ids = sapi_get_product_photo_ids($pid, $type, $count, $piece_or_null, null);
      }
    }
    if (empty($photo_ids)) continue; // pièce sans photo → rien envoyé, défaut conservé

    $urls = [];
    foreach ($photo_ids as $aid) {
      $url = wp_get_attachment_image_url($aid, $size);
      if ($url) $urls[] = $url;
    }
    if (!empty($urls)) $map[(string) $pid] = $urls;
  }

  wp_send_json($map);
}
add_action('wp_ajax_sapi_get_piece_photos',        'sapi_ajax_get_piece_photos');
add_action('wp_ajax_nopriv_sapi_get_piece_photos', 'sapi_ajax_get_piece_photos');

/**
 * Enqueue du module JS sapi-photo-swap (S28 Phase 4b).
 * Enqueue global (footer) — le JS no-op si la page n'a aucun élément
 * [data-piece-swap]. Coût négligeable, évite la complexité d'un enqueue
 * conditionnel par template.
 */
function sapi_enqueue_photo_swap() {
  $base = get_template_directory_uri();
  $dir  = get_template_directory();
  $path = $dir . '/assets/sapi-photo-swap.js';
  if (!file_exists($path)) return;

  wp_enqueue_script(
    'sapi-photo-swap',
    $base . '/assets/sapi-photo-swap.js',
    // `sapi-project` porte les tables de traduction du projet ET la mémoire
    // que ce script lit déjà. La dépendance était omise (« pas en
    // wp_register_script » — inexact, elle est enqueuée globalement plus haut),
    // donc l'ordre n'était garanti que par chance.
    ['sapi-project'],
    filemtime($path),
    true // footer
  );
  wp_localize_script('sapi-photo-swap', 'SAPI_PHOTO_SWAP', [
    'ajaxUrl' => admin_url('admin-ajax.php'),
    'nonce'   => wp_create_nonce('sapi_photo_swap'),
  ]);
}
add_action('wp_enqueue_scripts', 'sapi_enqueue_photo_swap');

/**
 * Filtre une liste d'IDs d'attachments par un slug de terme dans une taxonomie.
 * Préserve l'ordre des IDs en entrée.
 *
 * Optimisation : utilise get_objects_in_term() qui fait une seule requête DB
 * par (taxonomy, term) — plus performant que wp_get_object_terms() appelé
 * dans une boucle sur chaque attachment. Cache statique par (taxonomy, term)
 * pour éviter une 2e requête si le même filtre est appliqué dans la même
 * request (ex: galerie_ambiance + galerie_detail filtrés sur même $piece).
 *
 * @param int[]  $attachment_ids IDs d'attachments à filtrer
 * @param string $taxonomy       'media_room' ou 'media_essence'
 * @param string $term_slug      Slug du terme (ex: 'salon', 'peuplier')
 * @return int[] IDs filtrés, ordre d'entrée préservé. [] si term inconnu.
 */
function sapi_filter_attachment_ids_by_term($attachment_ids, $taxonomy, $term_slug) {
  if (empty($attachment_ids) || !$taxonomy || !$term_slug) return [];

  static $cache = [];
  $cache_key = $taxonomy . '|' . $term_slug;

  if (!isset($cache[$cache_key])) {
    $term = get_term_by('slug', $term_slug, $taxonomy);
    if (!$term || is_wp_error($term)) {
      $cache[$cache_key] = []; // term inconnu : cache une réponse vide
    } else {
      $tagged_ids = get_objects_in_term((int) $term->term_id, $taxonomy);
      if (is_wp_error($tagged_ids) || empty($tagged_ids)) {
        $cache[$cache_key] = [];
      } else {
        // Set keyed by int ID pour lookup O(1) dans la boucle d'intersect.
        $cache[$cache_key] = array_flip(array_map('intval', $tagged_ids));
      }
    }
  }

  $tagged_set = $cache[$cache_key];
  if (empty($tagged_set)) return [];

  $filtered = [];
  foreach ($attachment_ids as $id) {
    $id = (int) $id;
    if (isset($tagged_set[$id])) $filtered[] = $id;
  }
  return $filtered;
}

/**
 * Helper bas niveau : itère les rows du repeater galerie_produit d'un produit.
 * Renvoie un tableau de ['type' => string, 'image_id' => int, 'index' => int]
 * pour chaque row dont l'image est résolvable en attachment ID.
 *
 * - Le type est normalisé en string (gère le cas array ['value' => ...]).
 * - L'image est résolue via sapi_get_acf_image_id (ID, array ACF ou URL).
 * - Les rows sans image résolvable sont skippées silencieusement.
 * - L'ordre du repeater est préservé.
 *
 * Utilisé par la page admin de migration (inc/sapi-migrate-galerie.php) et,
 * à terme, par les autres consommateurs qui dupliquent aujourd'hui la logique
 * de parcours (page-inspiration.php).
 *
 * @param int $post_id Product ID
 * @return array       Liste d'entrées ['type', 'image_id', 'index']
 */
function sapi_iterate_product_photos($post_id) {
  if (!function_exists('get_field')) return [];
  $galerie = get_field('galerie_produit', $post_id);
  if (empty($galerie) || !is_array($galerie)) return [];

  $out = [];
  foreach ($galerie as $index => $row) {
    $type = isset($row['type_photo']) ? $row['type_photo'] : '';
    if (is_array($type)) $type = isset($type['value']) ? $type['value'] : '';
    $img_id = sapi_get_acf_image_id(isset($row['image']) ? $row['image'] : null);
    if (!$img_id) continue;
    $out[] = ['type' => (string) $type, 'image_id' => (int) $img_id, 'index' => (int) $index];
  }
  return $out;
}

/**
 * Get product photos as URLs (backward-compatible wrapper).
 *
 * @param int    $post_id  Product post ID
 * @param string $type     Photo type filter
 * @param int    $limit    Max number of photos (0 = all)
 * @param string $size     WordPress image size (default 'full')
 * @return array           Array of image URLs
 */
function sapi_get_product_photos($post_id, $type = '', $limit = 0, $size = 'full') {
  $ids = sapi_get_product_photo_ids($post_id, $type, $limit);
  $urls = [];
  foreach ($ids as $id) {
    $url = wp_get_attachment_image_url($id, $size);
    if ($url) $urls[] = $url;
  }
  return $urls;
}

/**
 * Helper: output an <img> tag with proper srcset from a media library filename.
 * Looks up the attachment by filename, caches the ID, and uses wp_get_attachment_image().
 *
 * @param string $filename  Filename relative to uploads (e.g. '2025/05/IMG_1928.jpg')
 * @param string $size      WordPress image size (default 'large')
 * @param array  $attr      Extra attributes for the img tag (alt, class, loading, etc.)
 * @return string            HTML <img> tag with srcset, or fallback <img> if not found
 */
function sapi_image($filename, $size = 'large', $attr = []) {
  // Cache attachment IDs to avoid repeated DB queries
  static $cache = [];

  if (!isset($cache[$filename])) {
    global $wpdb;
    $cache[$filename] = $wpdb->get_var($wpdb->prepare(
      "SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_wp_attached_file' AND meta_value = %s LIMIT 1",
      $filename
    ));
  }

  $id = $cache[$filename];

  if ($id) {
    return wp_get_attachment_image((int) $id, $size, false, $attr);
  }

  // Fallback: hardcoded img without srcset
  $alt = isset($attr['alt']) ? $attr['alt'] : '';
  $class = isset($attr['class']) ? $attr['class'] : '';
  $loading = isset($attr['loading']) ? $attr['loading'] : 'lazy';
  return sprintf(
    '<img src="%s" alt="%s" class="%s" loading="%s">',
    esc_url(home_url('/wp-content/uploads/' . $filename)),
    esc_attr($alt),
    esc_attr($class),
    esc_attr($loading)
  );
}

/**
 * Helper: extract attachment ID from ACF image field (handles all return formats)
 * Returns 0 if the field contains a raw URL string (no attachment ID available).
 */
function sapi_get_acf_image_id($field_value) {
  if (!$field_value) return 0;
  if (is_array($field_value) && isset($field_value['ID'])) {
    return (int) $field_value['ID'];
  } elseif (is_numeric($field_value)) {
    return (int) $field_value;
  }
  return 0;
}

/**
 * Helper: extract URL from ACF image field (handles all return formats)
 * Centralized here to avoid duplication across templates.
 */
function sapi_get_acf_image_url($field_value, $size = 'full') {
  if (!$field_value) return '';
  // Try to get ID and use WordPress for proper size
  $id = sapi_get_acf_image_id($field_value);
  if ($id) {
    return wp_get_attachment_image_url($id, $size);
  }
  // Fallback: raw URL from array or string
  if (is_array($field_value) && isset($field_value['url'])) {
    return $field_value['url'];
  } elseif (is_string($field_value) && strpos($field_value, 'http') === 0) {
    return $field_value;
  }
  return '';
}

/**
 * Page confirmation (thankyou) : on rend manuellement les détails commande
 * et les adresses client dans notre template custom (2 colonnes).
 * Empêcher WooCommerce de les re-afficher via do_action('woocommerce_thankyou').
 */
remove_action('woocommerce_thankyou', 'woocommerce_order_details_table', 10);

// Masquer "Informations complémentaires" (champs additionnels checkout) sur la confirmation
add_action('woocommerce_order_details_after_customer_details', function() {
  // Vide : empêche les hooks suivants de s'exécuter en retirant tout
  remove_all_actions('woocommerce_order_details_after_customer_details', 20);
  remove_all_actions('woocommerce_order_details_after_customer_details', 30);
  remove_all_actions('woocommerce_order_details_after_customer_details', 40);
}, 1);

/**
 * Migration unique — supprime Elementor de la page checkout (ID 13).
 * S'exécute une fois au premier chargement admin, puis ne fait plus rien.
 */
add_action('admin_init', function () {
  if (!function_exists('wc_get_page_id')) return; // garde WC : évite le fatal admin si WooCommerce est désactivé
  $page_id = wc_get_page_id('checkout');
  if (!$page_id || $page_id < 1) return;
  if (get_post_meta($page_id, '_wp_page_template', true) !== 'elementor_header_footer') return;

  // 1. Template → défaut du thème
  update_post_meta($page_id, '_wp_page_template', 'default');

  // 2. Supprime toutes les metas Elementor
  foreach (['_elementor_data', '_elementor_template_type', '_elementor_edit_mode',
            '_elementor_version', '_elementor_css', '_elementor_controls_usage'] as $meta) {
    delete_post_meta($page_id, $meta);
  }

  // 3. Injecte le bloc WooCommerce Checkout dans post_content
  wp_update_post([
    'ID'           => $page_id,
    'post_content' => '<!-- wp:woocommerce/checkout --><div class="wp-block-woocommerce-checkout alignwide wc-block-checkout is-loading"></div><!-- /wp:woocommerce/checkout -->',
  ]);
});


function sapi_maison_content_width() {
  $GLOBALS['content_width'] = 1200;
}
add_action('after_setup_theme', 'sapi_maison_content_width', 0);




function sapi_maison_cart_count() {
  if (!function_exists('WC')) {
    return 0;
  }
  return WC()->cart ? WC()->cart->get_cart_contents_count() : 0;
}

/**
 * Génère le shippingDetails Schema.org à partir des zones WooCommerce.
 */
function sapi_get_shipping_schema($product) {
  $shipping_details = [];
  $zones = WC_Shipping_Zones::get_zones();

  foreach ($zones as $zone_data) {
    $zone = new WC_Shipping_Zone($zone_data['zone_id']);
    $locations = $zone->get_zone_locations();
    $methods = $zone->get_shipping_methods(true);

    // Collecter les pays de cette zone
    $countries = [];
    foreach ($locations as $location) {
      if ($location->type === 'country') {
        $countries[] = $location->code;
      } elseif ($location->type === 'continent') {
        $countries[] = $location->code;
      }
    }

    if (empty($countries) || empty($methods)) {
      continue;
    }

    foreach ($methods as $method) {
      if ($method->id === 'local_pickup') {
        continue;
      }

      foreach ($countries as $country_code) {
        $detail = [
          '@type' => 'OfferShippingDetails',
          'shippingDestination' => [
            '@type' => 'DefinedRegion',
            'addressCountry' => $country_code
          ],
          'deliveryTime' => [
            '@type' => 'ShippingDeliveryTime',
            'handlingTime' => [
              '@type' => 'QuantitativeValue',
              'minValue' => 3,
              'maxValue' => 5,
              'unitCode' => 'd'
            ],
            'transitTime' => [
              '@type' => 'QuantitativeValue',
              'minValue' => 1,
              'maxValue' => 2,
              'unitCode' => 'd'
            ]
          ]
        ];

        // Tarif : flat_rate ou free_shipping
        if ($method->id === 'flat_rate') {
          $cost = $method->get_option('cost');
          if ($cost !== '' && $cost !== false) {
            $detail['shippingRate'] = [
              '@type' => 'MonetaryAmount',
              'value' => $cost,
              'currency' => 'EUR'
            ];
          }
        } elseif ($method->id === 'free_shipping') {
          $detail['shippingRate'] = [
            '@type' => 'MonetaryAmount',
            'value' => '0',
            'currency' => 'EUR'
          ];
        }

        $shipping_details[] = $detail;
      }
    }
  }

  return !empty($shipping_details) ? $shipping_details : [];
}

function sapi_maison_structured_data() {
  if (!class_exists('WooCommerce')) return; // garde WC (is_product / WC_Shipping_Zones plus bas)
  if (is_product()) {
    global $product;

    if (!$product) {
      return;
    }

    $schema = [
      '@context' => 'https://schema.org',
      '@type' => 'Product',
      'name' => get_the_title(),
      'description' => wp_strip_all_tags(get_the_excerpt()),
      'url' => get_permalink(),
      'sku' => $product->get_sku(),
      'brand' => [
        '@type' => 'Brand',
        'name' => 'Atelier Sâpi'
      ],
      'offers' => [
        '@type' => 'Offer',
        'url' => get_permalink(),
        'priceCurrency' => 'EUR',
        'price' => $product->get_price(),
        'priceValidUntil' => gmdate('Y-m-d', strtotime('+1 year')),
        'availability' => $product->is_in_stock() ? 'https://schema.org/InStock' : 'https://schema.org/OutOfStock',
        'seller' => [
          '@type' => 'Organization',
          'name' => 'Atelier Sâpi'
        ],
        'shippingDetails' => sapi_get_shipping_schema($product),
        'hasMerchantReturnPolicy' => [
          '@type' => 'MerchantReturnPolicy',
          'applicableCountry' => 'FR',
          'returnPolicyCategory' => 'https://schema.org/MerchantReturnFiniteReturnWindow',
          'merchantReturnDays' => 30,
          'returnMethod' => 'https://schema.org/ReturnByMail',
          'returnFees' => 'https://schema.org/ReturnFeesCustomerResponsibility',
          'refundType' => 'https://schema.org/FullRefund',
          'returnPolicySeasonalOverride' => []
        ]
      ]
    ];

    if (has_post_thumbnail()) {
      $schema['image'] = get_the_post_thumbnail_url(null, 'full');
    }

    if ($product->get_rating_count() > 0) {
      $schema['aggregateRating'] = [
        '@type' => 'AggregateRating',
        'ratingValue' => $product->get_average_rating(),
        'reviewCount' => $product->get_rating_count()
      ];
    }

    echo '<script type="application/ld+json">' . wp_json_encode($schema, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . '</script>';
  }

  // Organization schema supprimé — Yoast le génère déjà dans son @graph
}
add_action('wp_head', 'sapi_maison_structured_data');

function sapi_maison_open_graph() {
  if (!class_exists('WooCommerce')) return; // garde WC (is_product)
  if (is_product()) {
    global $product;

    if (!$product) {
      return;
    }

    echo '<meta property="og:type" content="product">' . "\n";
    echo '<meta property="og:title" content="' . esc_attr(get_the_title()) . '">' . "\n";
    echo '<meta property="og:description" content="' . esc_attr(wp_strip_all_tags(get_the_excerpt())) . '">' . "\n";
    echo '<meta property="og:url" content="' . esc_url(get_permalink()) . '">' . "\n";
    echo '<meta property="og:site_name" content="Atelier Sâpi">' . "\n";

    if (has_post_thumbnail()) {
      echo '<meta property="og:image" content="' . esc_url(get_the_post_thumbnail_url(null, 'large')) . '">' . "\n";
    }

    echo '<meta property="product:price:amount" content="' . esc_attr($product->get_price()) . '">' . "\n";
    echo '<meta property="product:price:currency" content="EUR">' . "\n";
  } elseif (is_front_page()) {
    echo '<meta property="og:type" content="website">' . "\n";
    echo '<meta property="og:title" content="' . esc_attr(get_bloginfo('name')) . '">' . "\n";
    echo '<meta property="og:description" content="' . esc_attr(get_bloginfo('description')) . '">' . "\n";
    echo '<meta property="og:url" content="' . esc_url(home_url()) . '">' . "\n";
    echo '<meta property="og:site_name" content="Atelier Sâpi">' . "\n";
  }

  echo '<meta name="twitter:card" content="summary_large_image">' . "\n";
}
add_action('wp_head', 'sapi_maison_open_graph');

// Meta descriptions (SEO)
function sapi_maison_meta_description() {
  $description = '';

  if (is_singular('product')) {
    global $product;
    if ($product) {
      $description = wp_strip_all_tags($product->get_short_description());
      if (empty($description)) {
        $description = wp_strip_all_tags($product->get_description());
      }
    }
  } elseif (class_exists('WooCommerce') && is_product_category()) {
    $term = get_queried_object();
    if ($term) {
      $descs = [
        'suspension' => 'Découvrez les suspensions artisanales en bois de l\'Atelier Sâpi. Luminaires suspendus design, découpés au laser et assemblés à la main à Lyon.',
        'lampadaire' => 'Les lampadaires en bois sculptés de Robin transforment vos espaces. Éclairage d\'ambiance unique, fabriqués en France à Lyon.',
        'applique' => 'Appliques murales artisanales en bois. Créez des jeux de lumière poétiques sur vos murs. Chaque pièce est unique.',
        'lampe-a-poser' => 'Lampes à poser portables en bois. Déplacez-les où vous voulez pour créer une bulle de lumière intime.',
        'accessoires' => 'Accessoires pour luminaires artisanaux. Ampoules, câbles textile et pièces détachées pour vos créations Atelier Sâpi.',
      ];
      $description = isset($descs[$term->slug]) ? $descs[$term->slug] : wp_strip_all_tags(term_description($term->term_id, 'product_cat'));
    }
  } elseif (class_exists('WooCommerce') && is_shop()) {
    $description = 'Luminaires artisanaux en bois, découpés au laser et assemblés à la main à Lyon. Suspensions, lampadaires, appliques et lampes design.';
  } elseif (is_front_page()) {
    $description = get_bloginfo('description') ?: 'Luminaires artisanaux en bois sculptés à la main à Lyon. Suspensions, lampadaires, appliques et lampes design. 100% français.';
  }

  if (!empty($description)) {
    $description = mb_substr(trim($description), 0, 160);
    echo '<meta name="description" content="' . esc_attr($description) . '">' . "\n";
  }
}
add_action('wp_head', 'sapi_maison_meta_description', 6);

function sapi_maison_breadcrumbs() {
  if (is_front_page()) {
    return;
  }

  $breadcrumbs = [];
  $breadcrumbs[] = [
    'name' => 'Accueil',
    'url' => home_url('/')
  ];

  if (is_product()) {
    $breadcrumbs[] = [
      'name' => 'Mes créations',
      'url' => home_url('/mes-creations/')
    ];
    $terms = get_the_terms(get_the_ID(), 'product_cat');
    if ($terms && !is_wp_error($terms)) {
      $main_term = $terms[0];
      $breadcrumbs[] = [
        'name' => $main_term->name,
        'url' => get_term_link($main_term)
      ];
    }
    $breadcrumbs[] = [
      'name' => get_the_title(),
      'url' => ''
    ];
  } elseif (is_product_category()) {
    $breadcrumbs[] = [
      'name' => 'Mes créations',
      'url' => home_url('/mes-creations/')
    ];
    $term = get_queried_object();
    $breadcrumbs[] = [
      'name' => $term->name,
      'url' => ''
    ];
  }

  // SVG flèche comme séparateur
  $bulb = '<span class="breadcrumb-separator"><svg width="8" height="12" viewBox="0 0 8 12" fill="none" xmlns="http://www.w3.org/2000/svg"><path d="M1.5 1L6.5 6L1.5 11" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/></svg></span>';

  // BreadcrumbList JSON-LD supprimé — Yoast le génère déjà dans son @graph
  echo '<nav class="breadcrumbs" aria-label="Fil d\'Ariane"><div class="breadcrumbs-inner">';
  foreach ($breadcrumbs as $index => $crumb) {
    $position = $index + 1;

    if ($crumb['url']) {
      echo '<a href="' . esc_url($crumb['url']) . '">' . esc_html($crumb['name']) . '</a>';
      if ($position < count($breadcrumbs)) {
        echo ' ' . $bulb . ' ';
      }
    } else {
      echo '<span class="breadcrumb-current">' . esc_html($crumb['name']) . '</span>';
    }
  }
  echo '</div></nav>';
}

// Redirect static category pages to WooCommerce native categories
add_action('template_redirect', function() {
  if (!is_page()) {
    return;
  }

  $page_slug = get_post_field('post_name', get_queried_object_id());

  // Map static page slugs to WooCommerce category slugs
  $category_redirects = [
    'nos-lampadaires' => 'lampadaire',
    'nos-suspensions' => 'suspension',
    'nos-appliques' => 'applique',
    'nos-lampes-a-poser' => 'lampe-a-poser',
    'les-accessoires' => 'accessoires',
  ];

  if (isset($category_redirects[$page_slug])) {
    $category_slug = $category_redirects[$page_slug];
    $category_link = get_term_link($category_slug, 'product_cat');

    if (!is_wp_error($category_link)) {
      wp_redirect($category_link, 301);
      exit;
    }
  }
});

// Remove default product meta display (SKU, Categories, Tags)
remove_action('woocommerce_single_product_summary', 'woocommerce_template_single_meta', 40);

// Ensure WooCommerce scripts are loaded - CRITICAL for cart functionality
add_action('wp_enqueue_scripts', function() {
  // Use class_exists instead of function_exists for reliability
  if (class_exists('WooCommerce')) {
    // Cart fragments for AJAX cart updates (CRITICAL for add-to-cart to work)
    wp_enqueue_script('wc-cart-fragments');
  }
}, 25);

// Variation scripts for variable products
add_action('wp_enqueue_scripts', function() {
  if (class_exists('WooCommerce') && is_product()) {
    wp_enqueue_script('wc-add-to-cart-variation');
  }
}, 30);

/**
 * Réordonner les données panier : variations avant add-ons.
 *
 * WooCommerce Blocks (Store API) appelle ce filtre avec un array vide,
 * puis le plugin WC Product Add-Ons y ajoute ses données.
 * Les variations sont affichées séparément par Blocks via $cart_item['variation'].
 *
 * Stratégie : on injecte les variations dans item_data à priorité 5 (avant les
 * add-ons à priorité 10+). Le filtre rest_request_after_callbacks vide ensuite
 * le champ variation[] de la réponse Store API pour éviter le doublon.
 * Le CSS cache aussi le 2ème .product-details en sécurité.
 */
add_filter('woocommerce_get_item_data', function($item_data, $cart_item) {
  if (empty($cart_item['variation'])) {
    return $item_data;
  }

  // Construire les entrées de variation
  $variation_entries = [];
  foreach ($cart_item['variation'] as $attr_key => $attr_val) {
    if (empty($attr_val)) {
      continue;
    }
    $taxonomy = str_replace('attribute_', '', $attr_key);
    $label    = wc_attribute_label($taxonomy);
    if (taxonomy_exists($taxonomy)) {
      $term = get_term_by('slug', $attr_val, $taxonomy);
      $value = $term ? $term->name : $attr_val;
    } else {
      $value = $attr_val;
    }
    $variation_entries[] = [
      'key'     => $label,
      'value'   => $value,
      'display' => esc_html($value),
    ];
  }

  // Variations d'abord, puis add-ons
  return array_merge($variation_entries, $item_data);
}, 5, 2);

// Supprimer les variations de la réponse Store API pour éviter le doublon
// (les variations sont déjà dans item_data grâce au filtre ci-dessus)
add_filter('rest_request_after_callbacks', function($response, $handler, $request) {
  if (! ($response instanceof WP_REST_Response)) {
    return $response;
  }
  $route = $request->get_route();
  if (strpos($route, 'wc/store/v1/cart') === false && strpos($route, 'wc/store/v1/batch') === false) {
    return $response;
  }
  $data = $response->get_data();
  // Panier complet (/wc/store/v1/cart)
  if (isset($data['items']) && is_array($data['items'])) {
    foreach ($data['items'] as &$item) {
      if (isset($item['variation'])) {
        $item['variation'] = [];
      }
    }
    unset($item);
    $response->set_data($data);
  }
  // Endpoints batch ou item individuel
  if (isset($data['variation'])) {
    $data['variation'] = [];
    $response->set_data($data);
  }
  return $response;
}, 10, 3);

// Update cart count fragment after AJAX add-to-cart
add_filter('woocommerce_add_to_cart_fragments', function($fragments) {
  $count = WC()->cart->get_cart_contents_count();
  ob_start();
  ?>
  <span class="cart-count<?php echo $count === 0 ? ' cart-count--empty' : ''; ?>"><?php echo $count > 0 ? esc_html($count) : ''; ?></span>
  <?php
  $fragments['.cart-count'] = ob_get_clean();

  // Mini cart fragment for sliding panel
  ob_start();
  sapi_render_mini_cart_contents();
  $fragments['.mini-cart-body'] = ob_get_clean();

  // Mini cart total
  ob_start();
  ?>
  <div class="mini-cart-total">
    <span><?php esc_html_e('Total', 'theme-sapi-maison'); ?></span>
    <strong class="total-amount"><?php echo WC()->cart->get_cart_total(); ?></strong>
  </div>
  <?php
  $fragments['.mini-cart-total'] = ob_get_clean();

  return $fragments;
});

/**
 * Render mini cart contents for AJAX refresh
 */
function sapi_render_mini_cart_contents() {
  // Garde WC : appelée depuis header.php sur TOUTES les pages. La garde de header.php
  // (function_exists('sapi_render_mini_cart_contents')) est toujours vraie donc inefficace.
  // Si WooCommerce est absent, on rend un mini-panier vide neutre (sans lien shop) et on sort.
  if (!function_exists('WC') || !WC()->cart) {
    ?>
    <div class="mini-cart-body">
      <div class="mini-cart-empty">
        <p><?php esc_html_e('Votre panier est vide', 'theme-sapi-maison'); ?></p>
      </div>
    </div>
    <?php
    return;
  }
  if (!WC()->cart || WC()->cart->is_empty()) {
    ?>
    <div class="mini-cart-body">
      <div class="mini-cart-empty">
        <svg width="64" height="64" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
          <circle cx="9" cy="21" r="1"/>
          <circle cx="20" cy="21" r="1"/>
          <path d="M1 1h4l2.68 13.39a2 2 0 0 0 2 1.61h9.72a2 2 0 0 0 2-1.61L23 6H6"/>
        </svg>
        <p><?php esc_html_e('Votre panier est vide', 'theme-sapi-maison'); ?></p>
        <a href="<?php echo esc_url(wc_get_page_permalink('shop')); ?>" class="btn-continue">
          <?php esc_html_e('Voir les créations', 'theme-sapi-maison'); ?>
        </a>
      </div>
    </div>
    <?php
    return;
  }
  ?>
  <div class="mini-cart-body">
    <div class="mini-cart-items">
      <?php foreach (WC()->cart->get_cart() as $cart_item_key => $cart_item) :
        $product = $cart_item['data'];
        $product_id = $cart_item['product_id'];
        $quantity = $cart_item['quantity'];
        $product_permalink = $product->is_visible() ? $product->get_permalink($cart_item) : '';
        $display_name = $product->is_type('variation') ? wc_get_product($product_id)->get_name() : $product->get_name();
      ?>
        <div class="mini-cart-item" data-key="<?php echo esc_attr($cart_item_key); ?>">
          <div class="mini-cart-item-image">
            <?php echo $product->get_image('thumbnail'); ?>
          </div>
          <div class="mini-cart-item-details">
            <span class="mini-cart-item-name">
              <?php echo $product_permalink ? '<a href="' . esc_url($product_permalink) . '">' . esc_html($display_name) . '</a>' : esc_html($display_name); ?>
            </span>
            <div class="mini-cart-item-meta">
              <?php if (!empty($cart_item['variation'])) : ?>
                <?php foreach ($cart_item['variation'] as $attr_key => $attr_value) :
                  if (empty($attr_value)) continue;
                  $attr_name = wc_attribute_label(str_replace('attribute_', '', $attr_key), $product);
                ?>
                  <div class="mini-cart-var-line">
                    <span class="mini-cart-var-label"><?php echo esc_html($attr_name); ?> :</span>
                    <span class="mini-cart-var-value"><?php echo esc_html($attr_value); ?></span>
                  </div>
                <?php endforeach; ?>
              <?php endif; ?>
              <?php
              // Afficher les add-ons — double approche pour fiabilité
              $addon_rendered = false;

              // Méthode 1: données directes du plugin Product Add-Ons
              if (!empty($cart_item['addons']) && is_array($cart_item['addons'])) :
                foreach ($cart_item['addons'] as $addon) :
                  $name = isset($addon['name']) ? $addon['name'] : '';
                  $value = isset($addon['value']) ? $addon['value'] : '';
                  if (empty($name) || empty($value)) continue;
                  $addon_rendered = true;
              ?>
                  <div class="mini-cart-var-line">
                    <span class="mini-cart-var-label"><?php echo esc_html(rtrim($name, ': ')); ?> :</span>
                    <span class="mini-cart-var-value"><?php echo esc_html($value); ?></span>
                  </div>
              <?php endforeach; endif;

              // Méthode 2 (fallback): parsing du texte WooCommerce
              if (!$addon_rendered) :
                $flat_data = wc_get_formatted_cart_item_data($cart_item, true);
                if (!empty($flat_data)) :
                  $lines = array_filter(explode("\n", trim($flat_data)));
                  foreach ($lines as $line) :
                    $parts = explode(': ', $line, 2);
                    if (count($parts) === 2) :
              ?>
                  <div class="mini-cart-var-line">
                    <span class="mini-cart-var-label"><?php echo esc_html(rtrim($parts[0], ': ')); ?> :</span>
                    <span class="mini-cart-var-value"><?php echo esc_html($parts[1]); ?></span>
                  </div>
              <?php endif; endforeach; endif; endif; ?>
            </div>
            <div class="mini-cart-item-bottom">
              <span class="mini-cart-item-price">
                <?php echo WC()->cart->get_product_price($product); ?>
              </span>
              <div class="mini-cart-qty-selector" data-cart-item-key="<?php echo esc_attr($cart_item_key); ?>">
                <button class="mini-cart-qty-btn mini-cart-qty-minus" aria-label="<?php esc_attr_e('Diminuer la quantité', 'theme-sapi-maison'); ?>">−</button>
                <span class="mini-cart-qty-value"><?php echo esc_html($quantity); ?></span>
                <button class="mini-cart-qty-btn mini-cart-qty-plus" aria-label="<?php esc_attr_e('Augmenter la quantité', 'theme-sapi-maison'); ?>">+</button>
              </div>
            </div>
          </div>
          <button class="mini-cart-item-remove" data-cart-item-key="<?php echo esc_attr($cart_item_key); ?>" aria-label="<?php esc_attr_e('Retirer du panier', 'theme-sapi-maison'); ?>">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
              <line x1="18" y1="6" x2="6" y2="18"/>
              <line x1="6" y1="6" x2="18" y2="18"/>
            </svg>
          </button>
        </div>
      <?php endforeach; ?>
    </div>
  </div>
  <?php
}

/**
 * AJAX handler: update mini-cart item quantity
 */
function sapi_update_mini_cart_qty() {
  if (!function_exists('WC') || !WC()->cart) { wp_send_json_error(['message' => 'Boutique momentanément indisponible.']); return; } // garde WC
  // Verify nonce
  if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'sapi-update-mini-cart-qty')) {
    wp_send_json_error(['message' => 'Invalid nonce']);
    return;
  }

  $cart_item_key = sanitize_text_field($_POST['cart_item_key'] ?? '');
  $quantity = absint($_POST['quantity'] ?? 0);

  if (!$cart_item_key || !WC()->cart) {
    wp_send_json_error();
  }

  if ($quantity === 0) {
    WC()->cart->remove_cart_item($cart_item_key);
  } else {
    WC()->cart->set_quantity($cart_item_key, $quantity);
  }

  WC()->cart->calculate_totals();

  // Return updated fragments
  $fragments = apply_filters('woocommerce_add_to_cart_fragments', array());
  wp_send_json_success(array('fragments' => $fragments));
}
add_action('wp_ajax_sapi_update_mini_cart_qty', 'sapi_update_mini_cart_qty');
add_action('wp_ajax_nopriv_sapi_update_mini_cart_qty', 'sapi_update_mini_cart_qty');
add_action('wc_ajax_sapi_update_mini_cart_qty', 'sapi_update_mini_cart_qty');

/**
 * Custom variation attribute display with visible labels
 * Add classes and data attributes for JS-powered swatches
 */
add_filter('woocommerce_dropdown_variation_attribute_options_html', function($html, $args) {
  $args = wp_parse_args(apply_filters('woocommerce_dropdown_variation_attribute_options_args', $args), [
    'options'          => false,
    'attribute'        => false,
    'product'          => false,
    'selected'         => false,
    'name'             => '',
    'id'               => '',
    'class'            => '',
    'show_option_none' => __('Choose an option', 'woocommerce'),
  ]);

  // Only modify for specific attributes
  $attribute = $args['attribute'];
  $product = $args['product'];
  $options = $args['options'];

  // Check if this is a material/finish attribute (common swatch candidates)
  $swatch_attributes = ['pa_essence', 'pa_materiau', 'pa_finition', 'pa_couleur', 'essence', 'materiau', 'finition', 'couleur'];
  $is_swatch_attribute = false;

  foreach ($swatch_attributes as $swatch_attr) {
    if (stripos($attribute, $swatch_attr) !== false) {
      $is_swatch_attribute = true;
      break;
    }
  }

  if (!$is_swatch_attribute || empty($options)) {
    return $html;
  }

  // Get attribute taxonomy for swatch images
  $attribute_taxonomy = wc_attribute_taxonomy_name(str_replace('pa_', '', $attribute));

  // Premium wood gradients from Design System
  $wood_gradients = [
    'okoume'   => 'linear-gradient(135deg, #D4B896 0%, #C4A882 50%, #B89B74 100%)',
    'peuplier' => 'linear-gradient(135deg, #F5EAD6 0%, #E8DCC4 50%, #DDD0B8 100%)',
    'noyer'    => 'linear-gradient(135deg, #8B6F5C 0%, #7A5F4D 50%, #6B5242 100%)',
    // Fallbacks pour autres bois
    'chene'    => 'linear-gradient(135deg, #C4A77D 0%, #B8996D 50%, #A08660 100%)',
    'hetre'    => 'linear-gradient(135deg, #E8D4B8 0%, #DEC8A7 50%, #D4BC96 100%)',
    'bouleau'  => 'linear-gradient(135deg, #F5E6D3 0%, #EDDEC7 50%, #E8D4BC 100%)',
    'frene'    => 'linear-gradient(135deg, #D9C8A5 0%, #CFBE9B 50%, #C9B58F 100%)',
    'erable'   => 'linear-gradient(135deg, #E8D8C8 0%, #DECCBA 50%, #D4C4B0 100%)',
    'merisier' => 'linear-gradient(135deg, #B87333 0%, #A46428 50%, #8B4513 100%)',
    'pin'      => 'linear-gradient(135deg, #DEB887 0%, #D1A876 50%, #C4A46C 100%)',
  ];

  // Build custom swatch HTML
  $swatch_html = '<div class="attribute-swatch" data-attribute="' . esc_attr($attribute) . '">';

  foreach ($options as $option) {
    $term = get_term_by('slug', $option, $attribute_taxonomy);
    $term_name = $term ? $term->name : $option;
    $is_selected = $args['selected'] === $option ? ' selected' : '';

    // Get wood slug for class mapping
    $wood_slug = sanitize_title($option);

    // Normalize wood names (handle accents)
    $wood_class = $wood_slug;
    $wood_class = str_replace(['okoumé', 'okoume'], 'okoume', $wood_class);

    // Get wood gradient from Design System
    $wood_gradient = isset($wood_gradients[$wood_slug]) ? $wood_gradients[$wood_slug] : '';

    // Try to get a term image (if using ACF or similar)
    $term_image = '';
    if ($term && function_exists('get_field')) {
      $term_image = get_field('swatch_image', $term);
    }

    // Check for default wood image in theme assets
    $default_image_path = get_template_directory() . '/assets/images/wood/' . $wood_slug . '.jpg';
    $default_image_url = '';
    if (file_exists($default_image_path)) {
      $default_image_url = get_template_directory_uri() . '/assets/images/wood/' . $wood_slug . '.jpg';
    }

    // Use premium .material-option structure for wood essences
    $swatch_html .= '<label class="swatch-item material-option' . $is_selected . '" data-value="' . esc_attr($option) . '" data-wood="' . esc_attr($wood_class) . '">';

    // Premium circular swatch with gradient
    $swatch_html .= '<div class="material-swatch ' . esc_attr($wood_class) . '"';
    if ($wood_gradient && !$term_image && !$default_image_url) {
      $swatch_html .= ' style="background: ' . esc_attr($wood_gradient) . ';"';
    }
    $swatch_html .= '>';

    if ($term_image) {
      // ACF image - render inside the circular swatch
      $swatch_html .= '<img src="' . esc_url($term_image['sizes']['thumbnail']) . '" alt="' . esc_attr($term_name) . '" style="width: 100%; height: 100%; object-fit: cover; border-radius: 50%;">';
    } elseif ($default_image_url) {
      // Default theme image - render inside the circular swatch
      $swatch_html .= '<img src="' . esc_url($default_image_url) . '" alt="' . esc_attr($term_name) . '" style="width: 100%; height: 100%; object-fit: cover; border-radius: 50%;">';
    }
    // If no image, the CSS gradient will show via inline style or CSS class

    $swatch_html .= '</div>';

    // Material info with name
    $swatch_html .= '<div class="material-info">';
    $swatch_html .= '<span class="material-name">' . esc_html($term_name) . '</span>';
    $swatch_html .= '</div>';

    $swatch_html .= '</label>';
  }

  $swatch_html .= '</div>';

  // Keep the original select but add a wrapper class
  $html = str_replace('<select', '<select class="swatch-select-hidden"', $html);

  return $swatch_html . $html;
}, 10, 2);

/**
 * WooCommerce gallery thumbnail size
 * Ensure thumbnails are at least 100px
 */
add_filter('woocommerce_gallery_thumbnail_size', function() {
  return [100, 100];
});

/**
 * Increase gallery image size
 */
add_filter('woocommerce_gallery_image_size', function() {
  return 'woocommerce_single';
});

/**
 * Custom SEO titles for product category pages
 */
add_filter('document_title_parts', function($title) {
  // Garde WC : ce filtre est joué sur le <title> de CHAQUE page. is_product_category()
  // est une fonction WC → early-return si WC absent (comportement inchangé sinon).
  if (!function_exists('is_product_category') || !is_product_category()) return $title;
  {
    $term = get_queried_object();
    $category_titles = [
      'suspension' => 'Suspensions artisanales en bois',
      'lampadaire' => 'Lampadaires en bois design',
      'applique' => 'Appliques murales artisanales',
      'lampe-a-poser' => 'Lampes à poser en bois',
      'accessoires' => 'Accessoires pour luminaires',
    ];

    if (isset($category_titles[$term->slug])) {
      $title['title'] = $category_titles[$term->slug];
    }
  }
  return $title;
});

/**
 * Register custom image sizes for better product display
 */
add_action('after_setup_theme', function() {
  add_image_size('product-card', 400, 400, true);
  add_image_size('product-gallery-thumb', 120, 120, true);
}, 11);

// Progress bar panier/checkout migrée dans le filtre render_block (compatible WooCommerce Blocks)

/**
 * AJAX Add to Cart - Safari Compatible
 * Utilise wc-ajax pour que les plugins (Product Add-Ons) chargent leurs hooks frontend
 */
add_action('wp_ajax_sapi_add_to_cart', 'sapi_ajax_add_to_cart');
add_action('wp_ajax_nopriv_sapi_add_to_cart', 'sapi_ajax_add_to_cart');
add_action('wc_ajax_sapi_add_to_cart', 'sapi_ajax_add_to_cart');

function sapi_ajax_add_to_cart() {
  if (!function_exists('WC') || !WC()->cart) { wp_send_json_error(['message' => 'Boutique momentanément indisponible.']); return; } // garde WC
  // Verify nonce
  if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'sapi-add-to-cart')) {
    wp_send_json_error(['message' => 'Invalid nonce']);
    return;
  }

  $product_id   = isset($_POST['product_id'])   ? absint($_POST['product_id'])   : 0;
  $quantity     = isset($_POST['quantity'])      ? absint($_POST['quantity'])      : 1;
  $variation_id = isset($_POST['variation_id']) ? absint($_POST['variation_id']) : 0;

  // Collect variation attributes (attribute_pa_*)
  $variation = [];
  foreach ($_POST as $key => $value) {
    if (strpos($key, 'attribute_') === 0) {
      $variation[sanitize_title(wp_unslash($key))] = sanitize_text_field(wp_unslash($value));
    }
  }

  if ($product_id <= 0) {
    wp_send_json_error(['message' => 'Invalid product']);
    return;
  }

  // Add to cart (works for simple and variable products)
  $cart_item_key = WC()->cart->add_to_cart($product_id, $quantity, $variation_id, $variation);

  if ($cart_item_key) {
    // Get refreshed fragments
    ob_start();
    woocommerce_mini_cart();
    $mini_cart = ob_get_clean();

    $data = [
      'fragments' => apply_filters('woocommerce_add_to_cart_fragments', [
        'div.widget_shopping_cart_content' => '<div class="widget_shopping_cart_content">' . $mini_cart . '</div>',
      ]),
      'cart_hash' => WC()->cart->get_cart_hash(),
      'cart_quantity' => WC()->cart->get_cart_contents_count(),
    ];

    wp_send_json_success($data);
  } else {
    wp_send_json_error(['message' => 'Could not add to cart']);
  }
}

/**
 * Calculate estimated delivery date (Phase 4 - Proposal B)
 * Fabrication (3 jours ouvrés) + Livraison (2 jours ouvrés)
 *
 * @return string Formatted date "12 février"
 */
function sapi_get_estimated_delivery_date() {
  $business_days_to_add = 5; // 3 jours fabrication + 2 jours livraison
  $current_date = new DateTime();
  $days_added = 0;

  while ($days_added < $business_days_to_add) {
    $current_date->modify('+1 day');
    $day_of_week = $current_date->format('N'); // 1 (lundi) à 7 (dimanche)

    // Skip weekends (6 = samedi, 7 = dimanche)
    if ($day_of_week < 6) {
      $days_added++;
    }
  }

  // Format: "12 février"
  $months_fr = [
    1 => 'janvier', 2 => 'février', 3 => 'mars', 4 => 'avril',
    5 => 'mai', 6 => 'juin', 7 => 'juillet', 8 => 'août',
    9 => 'septembre', 10 => 'octobre', 11 => 'novembre', 12 => 'décembre'
  ];

  $day = $current_date->format('j');
  $month = $months_fr[(int)$current_date->format('n')];

  return $day . ' ' . $month;
}

/**
 * AJAX Buy Now - Express Checkout (Phase 4 - Proposal B)
 * Adds product to cart and redirects directly to checkout
 */
add_action('wp_ajax_sapi_buy_now', 'sapi_ajax_buy_now');
add_action('wp_ajax_nopriv_sapi_buy_now', 'sapi_ajax_buy_now');
add_action('wc_ajax_sapi_buy_now', 'sapi_ajax_buy_now');

function sapi_ajax_buy_now() {
  if (!function_exists('WC') || !WC()->cart) { wp_send_json_error(['message' => 'Boutique momentanément indisponible.']); return; } // garde WC
  // Verify nonce
  if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'sapi-buy-now')) {
    wp_send_json_error(['message' => 'Session expirée, veuillez recharger la page']);
    return;
  }

  $product_id = isset($_POST['product_id']) ? absint($_POST['product_id']) : 0;
  $quantity = isset($_POST['quantity']) ? absint($_POST['quantity']) : 1;
  $variation_id = isset($_POST['variation_id']) ? absint($_POST['variation_id']) : 0;

  if ($product_id <= 0) {
    wp_send_json_error(['message' => 'Produit invalide']);
    return;
  }

  // Get product to check if it's variable
  $product = wc_get_product($product_id);
  if (!$product) {
    wp_send_json_error(['message' => 'Produit introuvable']);
    return;
  }

  // For variable products, ensure variation is selected
  if ($product->is_type('variable') && !$variation_id) {
    wp_send_json_error(['message' => 'Veuillez sélectionner toutes les options']);
    return;
  }

  // Clear cart for true express checkout experience
  WC()->cart->empty_cart();

  // Add to cart
  $cart_item_key = false;
  if ($variation_id > 0) {
    // Variable product - get variation attributes
    $variation_data = [];
    foreach ($_POST as $key => $value) {
      if (strpos($key, 'attribute_') === 0) {
        $variation_data[$key] = sanitize_text_field($value);
      }
    }
    $cart_item_key = WC()->cart->add_to_cart($product_id, $quantity, $variation_id, $variation_data);
  } else {
    // Simple product
    $cart_item_key = WC()->cart->add_to_cart($product_id, $quantity);
  }

  if ($cart_item_key) {
    wp_send_json_success([
      'message' => 'Redirection vers la commande...',
      'checkout_url' => wc_get_checkout_url(),
    ]);
  } else {
    wp_send_json_error(['message' => 'Impossible d\'ajouter au panier']);
  }
}

/**
 * Limiteur de débit IA (par IP) — partagé par les endpoints Conseiller (chat,
 * freetext, refine, contact…). NB : le quiz V1 `sapi_ajax_guide_results` +
 * `sapi_guide_build_system_prompt` ont été retirés (Tâche 7), remplacés par la
 * modale V3 ; ce limiteur reste utilisé par les autres endpoints.
 */
function sapi_guide_check_rate_limit() {
  $ip  = md5(isset($_SERVER['REMOTE_ADDR']) ? sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR'])) : 'unknown');
  $key = 'sapi_guide_rl_' . $ip;
  $hits = (int) get_transient($key);
  if ($hits >= 30) {
    return false;
  }
  set_transient($key, $hits + 1, HOUR_IN_SECONDS);
  return true;
}

/**
 * ── Phase B : Contact form from guide results ──
 * Receives the client's message + contact info + full guide context.
 * Sends a lead email to Robin.
 */


/**
 * ── Contact inline depuis la card Robin-Conseil ──
 * Envoie vers Brevo (liste dédiée) + email de notification à Robin.
 */
add_action('wp_ajax_sapi_robin_contact', 'sapi_ajax_robin_contact');
add_action('wp_ajax_nopriv_sapi_robin_contact', 'sapi_ajax_robin_contact');

function sapi_ajax_robin_contact() {
  if (!isset($_POST['nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['nonce'])), 'sapi-guide-results')) {
    wp_send_json_error(['message' => 'Nonce invalide']);
    return;
  }

  // Honeypot
  if (!empty($_POST['website'])) {
    wp_send_json_error(['message' => 'Spam détecté.']);
    return;
  }

  // Time-trap signé (rejet silencieux si trop rapide / signature invalide)
  if (!sapi_time_trap_valid($_POST['sapi_ts'] ?? 0, $_POST['sapi_tsig'] ?? '')) {
    wp_send_json_error(['message' => 'Spam détecté.']);
    return;
  }

  // Rate limiting (5 soumissions/heure par IP)
  if (!sapi_check_form_rate_limit('robin_contact')) {
    wp_send_json_error(['message' => 'Trop de messages envoyés. Réessayez plus tard.']);
    return;
  }

  $email   = sanitize_email(wp_unslash($_POST['email'] ?? ''));
  $phone   = sanitize_text_field(wp_unslash($_POST['phone'] ?? ''));
  $message = sanitize_textarea_field(wp_unslash($_POST['message'] ?? ''));
  $project = sanitize_text_field(wp_unslash($_POST['project'] ?? ''));
  $page    = sanitize_text_field(wp_unslash($_POST['page'] ?? ''));

  // Filtre anti-junk (message optionnel ici → n'exige pas un message)
  if (sapi_is_junk_contact($email, $message, false)) {
    wp_send_json_error(['message' => 'Spam détecté.']);
    return;
  }

  if (empty($email) || !is_email($email)) {
    wp_send_json_error(['message' => 'Email requis']);
    return;
  }

  // 1. Ajouter le contact à la liste Brevo #6 (newsletter) avec SOURCE=ficheproduit.
  //    On n'envoie QUE l'attribut SOURCE : les détails (message, projet, page,
  //    téléphone) partent dans l'email de notif ci-dessous. Envoyer SMS/MESSAGE/
  //    PROJET/PAGE faisait rejeter tout l'ajout par Brevo (400 — tél non E.164
  //    ou attributs non définis dans le compte), d'où des contacts jamais inscrits.
  $api_key = defined('BREVO_API_KEY') ? BREVO_API_KEY : '';
  if ($api_key) {
    $response = wp_remote_post('https://api.brevo.com/v3/contacts', [
      'headers' => [
        'api-key'      => $api_key,
        'Content-Type' => 'application/json',
        'Accept'       => 'application/json',
      ],
      'body' => wp_json_encode([
        'email'         => $email,
        'listIds'       => [6],
        'attributes'    => ['SOURCE' => 'ficheproduit'],
        'updateEnabled' => true,
      ]),
      'timeout' => 15,
    ]);

    if (is_wp_error($response)) {
      error_log('[sapi-brevo-ficheproduit] Erreur HTTP (' . $email . ') : ' . $response->get_error_message());
    } else {
      $code = wp_remote_retrieve_response_code($response);
      if ($code < 200 || $code >= 300) {
        error_log('[sapi-brevo-ficheproduit] Brevo a répondu ' . $code . ' (' . $email . ') : ' . wp_remote_retrieve_body($response));
      }
    }
  }

  // 2. Email de notification à Robin
  $body  = "Demande de contact depuis « Contacter Robin »\n";
  $body .= "=============================================\n\n";
  $body .= "EMAIL : " . esc_html($email) . "\n";
  if ($phone) {
    $body .= "TÉLÉPHONE : " . esc_html($phone) . "\n";
  }
  if ($message) {
    $body .= "\nMESSAGE : " . esc_html($message) . "\n";
  }
  if ($project) {
    $body .= "\nPROJET : " . esc_html($project) . "\n";
  }
  $body .= "\nPAGE : " . esc_html($page) . "\n";
  $body .= "DATE : " . wp_date('d/m/Y H:i') . "\n";

  wp_mail(
    'contact@atelier-sapi.fr',
    '[Mon Projet] Demande de contact',
    $body,
    [
      'Content-Type: text/plain; charset=UTF-8',
      'Reply-To: ' . $email,
    ]
  );

  wp_send_json_success(['message' => 'Envoyé']);
}

/**
 * ── Phase C : Smart refinement — AI routes client message ──
 * Client sends a follow-up message after seeing initial results.
 * Claude decides: refine products, show contact form, or both.
 */


/* ═══════════════════════════════════════════════════════════
   MÉGA-FILTRE INTELLIGENT (F1b) — IA dans la modale "Décrire mon projet"

   Deux endpoints AJAX dédiés :
   - sapi_megafilter_freetext : extraction de filtres structurés depuis texte
     libre (Haiku — rapide, déterministe)
   - sapi_megafilter_chat     : conversation libre dans la modale, peut
     ajuster les chips et router vers le formulaire de contact (Sonnet)

   Réutilise sapi_guide_check_rate_limit() et sapi_guide_query_all_products()
   du Conseiller. Endpoints distincts de sapi_ajax_guide_* pour éviter de
   mélanger les contextes (anciens orphelins, à supprimer en F1d).
═══════════════════════════════════════════════════════════ */

/**
 * Whitelist des slugs valides par clé de filtre, dérivée de
 * sapi_guide_get_steps() (source de vérité unique). Sert à la fois pour
 * lister les valeurs autorisées dans le system prompt et pour filtrer
 * les hallucinations Claude.
 */
function sapi_megafilter_filters_whitelist() {
  require_once get_template_directory() . '/inc/guide-data.php';
  $whitelist = [];
  foreach (sapi_guide_get_steps() as $step) {
    $slugs = [];
    foreach ($step['choices'] as $choice) {
      $slugs[] = $choice['slug'];
    }
    $whitelist[$step['id']] = $slugs;
  }
  return $whitelist;
}

/**
 * Wrapper Claude API local au méga-filtre. Retourne le texte brut de la
 * réponse (ou null en cas d'erreur). Le parsing JSON est délégué à
 * sapi_megafilter_parse_json() pour rester tolérant aux fences markdown.
 *
 * Note : on duplique légèrement sapi_guide_call_claude{,_refine} pour
 * isoler le nouveau contexte. Un refactor global est prévu en F1d.
 */

/**
 * Retire les tirets longs (— cadratin, – demi-cadratin) de tout texte destiné
 * au visiteur. Règle absolue de la marque : Robin n'en veut nulle part.
 *
 * ⚠️ POURQUOI UNE FONCTION ET PAS SEULEMENT UNE CONSIGNE DE PROMPT.
 * La règle est bien écrite dans assets/guide-prompt-regles.txt, et les
 * exemples qui en étaient truffés ont été nettoyés (ils apprenaient au modèle
 * à en produire). Mais une consigne de prompt n'est pas une contrainte : le
 * modèle peut passer outre, et il le fera un jour. Précédent dans ce même
 * fichier : la limite « max 300 caractères » du conseil, écrite dans le
 * prompt et jamais appliquée nulle part.
 *
 * Le tiret d'union normal (-) est conservé : mots composés, sur-mesure,
 * plages de mesures (10-20 m²).
 */
function sapi_strip_long_dashes($text) {
  if (!is_string($text) || $text === '') return $text;
  // Plage chiffrée : « 10–20 » devient « 10-20 », pas « 10, 20 ».
  $text = preg_replace('/(\d)\s*[—–]\s*(\d)/u', '$1-$2', $text);
  // Incise : « c'est idéal — le luminaire… » devient « c'est idéal, le luminaire… »
  $text = preg_replace('/\s*[—–]\s*/u', ', ', $text);
  // Un tiret déjà précédé d'une virgule en produisait deux.
  $text = preg_replace('/,\s*,/u', ',', $text);
  return trim($text);
}

/**
 * Borne dure de la longueur du conseil IA.
 *
 * ⚠️ POURQUOI CETTE FONCTION EXISTE — c'est le précédent que cite déjà le
 * commentaire de `sapi_strip_long_dashes()` juste au-dessus.
 * Le prompt dit « 1 à 2 phrases, max 300 caractères ». **C'est une consigne,
 * pas une contrainte** : rien ne l'appliquait à la réception, et un modèle qui
 * rend 450 caractères passait tel quel. Or TOUTE la mise en page du hero
 * immersif est dimensionnée en supposant un texte borné — sur mobile, le texte
 * débordait de sa zone et se faisait couper. La borne sur laquelle repose la
 * mise en page n'existait tout simplement pas.
 *
 * On coupe à la dernière phrase COMPLÈTE qui tient. Couper au milieu d'une
 * phrase donnerait un conseil de Robin qui s'interrompt, ce qui est pire qu'un
 * conseil un peu long. À défaut de ponctuation, on coupe au dernier mot entier
 * et on pose des points de suspension.
 *
 * 320 et non 300 : on laisse au modèle une marge de dépassement raisonnable
 * plutôt que d'amputer une phrase à 305 caractères qui allait très bien.
 */
function sapi_borner_conseil($text, $max = 320) {
  if (!is_string($text)) return '';
  $text = trim($text);
  $len = function_exists('mb_strlen') ? mb_strlen($text, 'UTF-8') : strlen($text);
  if ($len <= $max) return $text;

  $coupe = function_exists('mb_substr') ? mb_substr($text, 0, $max, 'UTF-8') : substr($text, 0, $max);

  // 1. Dernière fin de phrase dans la portion retenue.
  if (preg_match_all('/[.!?…]/u', $coupe, $m, PREG_OFFSET_CAPTURE)) {
    $dernier = end($m[0]);
    /* ⚠️ TOUT RESTE EN OCTETS ICI, ET C'EST VOULU.
       `PREG_OFFSET_CAPTURE` rend un offset en octets, `strlen` compte des
       octets, `substr` coupe des octets : la coupe tombe donc exactement après
       le dernier octet du caractère trouvé, jamais au milieu.
       Ne PAS « corriger » en `mb_substr` avec cet offset — ce serait mélanger
       octets et caractères, et casser l'UTF-8 sans rien faire planter. */
    $prefixe = substr($coupe, 0, $dernier[1] + strlen($dernier[0]));
    $phrase = trim($prefixe);
    /* Le seuil est BAS, volontairement. Il ne sert qu'à écarter une coupe
       absurde (« Oui. »). Une première phrase complète, même courte, vaut
       toujours mieux qu'une longue phrase amputée : c'est la règle du
       docblock. Un seuil élevé la retournait contre elle-même — sur le
       dépassement le plus probable, une accroche courte suivie d'une longue
       phrase, il refusait la coupe propre et rendait un conseil de Robin qui
       s'interrompt en plein milieu. */
    $lp = function_exists('mb_strlen') ? mb_strlen($phrase, 'UTF-8') : strlen($phrase);
    if ($lp >= 40) return $phrase;
  }

  // 2. Repli : dernier mot entier.
  $espace = function_exists('mb_strrpos') ? mb_strrpos($coupe, ' ', 0, 'UTF-8') : strrpos($coupe, ' ');
  if ($espace !== false && $espace > 0) {
    $coupe = function_exists('mb_substr') ? mb_substr($coupe, 0, $espace, 'UTF-8') : substr($coupe, 0, $espace);
  }
  return rtrim($coupe, " ,;:") . '…';
}

function sapi_megafilter_call_claude($model, $system, array $messages, $max_tokens = 1024) {
  $api_key = defined('ANTHROPIC_API_KEY') ? ANTHROPIC_API_KEY : '';
  if (empty($api_key)) {
    return null;
  }

  $body = [
    'model'      => $model,
    'max_tokens' => $max_tokens,
    'system'     => $system,
    'messages'   => $messages,
  ];

  $response = wp_remote_post('https://api.anthropic.com/v1/messages', [
    'timeout' => 30,
    'headers' => [
      'Content-Type'      => 'application/json',
      'x-api-key'         => $api_key,
      'anthropic-version' => '2023-06-01',
    ],
    'body' => wp_json_encode($body),
  ]);

  if (is_wp_error($response)) {
    error_log('Sapi MegaFilter Claude API error: ' . $response->get_error_message());
    return null;
  }

  $status   = wp_remote_retrieve_response_code($response);
  $raw_body = wp_remote_retrieve_body($response);

  if ($status !== 200) {
    error_log('Sapi MegaFilter Claude API HTTP ' . $status . ': ' . $raw_body);
    return null;
  }

  $data = json_decode($raw_body, true);
  if (!isset($data['content'][0]['text'])) {
    return null;
  }

  return $data['content'][0]['text'];
}

/**
 * Parse JSON tolérant — gère plusieurs cas pathologiques :
 *  1. JSON pur
 *  2. JSON entouré de fences markdown ```json ... ```
 *  3. Prose avant + bloc ```json ... ``` après (cas rencontré quand les
 *     exemples conversationnels priment dans le prompt — Claude écrit
 *     la prose ET le JSON)
 *  4. Prose mélangée avec une accolade {...} valide quelque part
 * Retourne null si rien n'a pu être extrait.
 */
function sapi_megafilter_parse_json($text) {
  if (!is_string($text)) return null;
  $clean = trim($text);

  // Stratégie 1 : essai direct (cas idéal)
  $direct = json_decode($clean, true);
  if (is_array($direct)) return $direct;

  // Stratégie 2 : fences markdown au début/fin
  $stripped = preg_replace('/^```(?:json)?\s*/i', '', $clean);
  $stripped = preg_replace('/\s*```$/', '', $stripped);
  $stripped = trim($stripped);
  $direct = json_decode($stripped, true);
  if (is_array($direct)) return $direct;

  // Stratégie 3 : trouve un bloc ```json ... ``` (ou ``` ... ```) n'importe où
  if (preg_match('/```(?:json)?\s*(\{[\s\S]*?\})\s*```/i', $clean, $m)) {
    $direct = json_decode(trim($m[1]), true);
    if (is_array($direct)) return $direct;
  }

  // Stratégie 4 : extrait la 1re accolade ouvrante à la dernière fermante
  $first = strpos($clean, '{');
  $last  = strrpos($clean, '}');
  if ($first !== false && $last !== false && $last > $first) {
    $candidate = substr($clean, $first, $last - $first + 1);
    $direct = json_decode($candidate, true);
    if (is_array($direct)) return $direct;
  }

  return null;
}

/**
 * Charge le contenu des 4 fichiers prompt V2 (assets/guide-prompt-*.txt) et
 * retourne la concaténation prête à coller en tête d'un system prompt
 * méga-filtre V3.
 *
 * Pattern repris du prompt par étape de la V2 (fonction supprimée le 28/08)
 * pour rapatrier la
 * voix Robin (ton chaleureux, tutoiement) et les règles métier dures
 * (cuisine sans lampe à poser, multi-ampoules, escalier, applique kit
 * prise, etc.) dans les prompts V3.
 *
 * @param bool $with_exemples Inclure guide-prompt-exemples.txt (verbeux,
 *                            à réserver aux prompts conversationnels —
 *                            risque de pollution de sortie JSON sinon).
 * @return string
 */
function sapi_megafilter_load_v2_prompts($with_exemples = false) {
  $theme_dir = get_template_directory();
  $ton    = @file_get_contents($theme_dir . '/assets/guide-prompt-ton.txt')    ?: '';
  $savoir = @file_get_contents($theme_dir . '/assets/guide-prompt-savoir.txt') ?: '';
  $regles = @file_get_contents($theme_dir . '/assets/guide-prompt-regles.txt') ?: '';

  $out  = $ton . "\n\n" . $savoir . "\n\n" . $regles . "\n\n";

  if ($with_exemples) {
    $exemples = @file_get_contents($theme_dir . '/assets/guide-prompt-exemples.txt') ?: '';
    if ($exemples) {
      $out .= "EXEMPLES DE CONSEILS PAR ÉTAPE (pour le ton et la direction) :\n" . $exemples . "\n\n";
    }
  }

  return $out;
}

/**
 * System prompt — extraction freetext (Haiku).
 */
function sapi_megafilter_build_freetext_prompt(array $whitelist) {
  $labels = [
    'piece'           => 'pièce',
    'taille'          => 'taille de pièce',
    'taille_escalier' => 'type d\'escalier (uniquement si piece=escalier)',
    'eclairage'       => 'source principale ou secondaire',
    'sortie'          => 'où installer (plafond, mur, prise)',
    'hauteur'         => 'hauteur sous plafond',
    'table'           => 'au-dessus d\'une table/lit',
    'style'           => 'style d\'intérieur',
  ];

  // Injecte ton + savoir + regles V2 en tête (PAS exemples : risque de
  // polluer la sortie JSON stricte attendue par cet endpoint Haiku).
  $prompt  = sapi_megafilter_load_v2_prompts(false);

  $prompt .= "Tu es l'assistant de Robin, l'artisan menuisier lyonnais qui fabrique des luminaires en bois à la découpe laser.\n";
  $prompt .= "Un visiteur décrit son projet en quelques mots. Ton rôle : extraire les filtres structurés qu'il indique et lui répondre en 1-2 phrases.\n\n";

  $prompt .= "FILTRES DISPONIBLES (utilise UNIQUEMENT ces slugs exacts) :\n";
  foreach ($labels as $key => $label) {
    if (!isset($whitelist[$key])) continue;
    $prompt .= '- ' . $key . ' (' . $label . ') : ' . implode(' | ', $whitelist[$key]) . "\n";
  }

  $prompt .= "\nFORMAT DE RÉPONSE (JSON strict, sans markdown, sans prose autour) :\n";
  $prompt .= "{\n";
  $prompt .= '  "filters": { "piece": "chambre", "sortie": "mur" },' . "\n";
  $prompt .= '  "message": "Très bien, ...",' . "\n";
  $prompt .= '  "action": "contact",' . "\n";
  $prompt .= '  "contact_kind": "sur-mesure",' . "\n";
  $prompt .= '  "contact_subject": "Projet ...",' . "\n";
  $prompt .= '  "contact_message": "Bonjour Robin, ...",' . "\n";
  $prompt .= '  "piece_hors_perimetre": "salle de bain"' . "\n";
  $prompt .= "}\n\n";
  /* ⚠️ CE CHAMP SERT À ROBIN, PAS AU VISITEUR : il lui dit quelles pièces on
     lui demande et qu'il ne couvre pas. Il ne s'affiche nulle part côté site. */
  $prompt .= "`piece_hors_perimetre` : À REMPLIR UNIQUEMENT si tu as déclenché la règle PIÈCE HORS PÉRIMÈTRE. Recopie le mot du visiteur, au singulier et en minuscules (« salle de bain », « garage », « véranda »). Dans TOUS les autres cas, omets ce champ.\n";
  $prompt .= "⚠️ NE LE REMPLIS PAS pour une pièce que tu as simplement rapprochée d'une autre du catalogue : un couloir est une entrée, une salle à manger est un salon. Ce champ ne sert qu'aux pièces que le catalogue NE COUVRE PAS.\n\n";

  $prompt .= "3 VOIES DE SORTIE — arbitre selon la nature du projet du visiteur :\n";
  $prompt .= "1) PROJET STANDARD : tu extrais les filtres possibles depuis sa phrase. Renvoie {filters: {...}, message: \"...\", action: null}\n";
  $prompt .= "   Exemples de déductions à faire (extrait ce que tu peux INFÉRER, pas seulement ce qui est explicite) :\n";
  $prompt .= "   - \"applique pour ma chambre\" → piece=chambre, sortie=mur (applique = mur)\n";
  $prompt .= "   - \"suspension salon\" → piece=salon, sortie=plafond (suspension = plafond)\n";
  $prompt .= "   - \"lampadaire chambre\" → piece=chambre, sortie=pas-de-sortie (lampadaire = prise 230V)\n";
  $prompt .= "   - \"lampe à poser bureau\" → piece=bureau, sortie=pas-de-sortie\n";
  $prompt .= "2) PROJET INCOMPLET : il manque une info essentielle pour proposer une sélection. Tu poses une question de précision dans message : {filters: {}, message: \"...\", action: null}\n";
  $prompt .= "3) PROJET CONTACT : la demande sort du périmètre catalogue, ou nécessite un échange direct. Renvoie {filters: {...} OU {}, message: \"...\", action: \"contact\", contact_kind: \"pro\"|\"sur-mesure\"|\"simple\"}\n\n";

  $prompt .= "CRITÈRES POUR `action: \"contact\"` :\n";
  $prompt .= "- Multi-luminaires : visiteur cherche plusieurs lampes pour un même projet (≥2 explicitement)\n";
  $prompt .= "- Pro / B2B : hôtel, restaurant, bureaux d'entreprise, salle d'événement, cadeau d'entreprise, retail, espace public\n";
  $prompt .= "- Dimensions custom : hauteur précise hors catalogue, format inhabituel demandé\n";
  $prompt .= "- Essence custom : bois non catalogue (chêne, noyer, etc.)\n";
  $prompt .= "- Combinaison qui sort manifestement du catalogue (style/format/usage spécial)\n";
  /* Périmètre des pièces — décision Robin du 26/08. Miroir EXACT de la règle du
     prompt de chat : c'est ce prompt-ci qui traite le PREMIER message, donc
     celui qui a laissé passer « une lampe pour ma salle de bain » et enchaîné
     sur la vasque et la prise. Si les deux règles divergent, le visiteur reçoit
     deux comportements différents selon qu'il parle une ou deux fois. */
  $prompt .= "- PIÈCE HORS PÉRIMÈTRE : le catalogue ne couvre que cuisine, bureau (ou atelier), salon (ou salle à manger), chambre, chambre d'enfant, entrée (ou couloir), cage d'escalier. Toute AUTRE pièce — salle de bain, salle d'eau, buanderie, garage, cave, terrasse, extérieur, véranda — est hors périmètre.\n";
  $prompt .= "  → Renvoie `{filters: {}, message: \"...\", action: \"contact\", contact_kind: \"sur-mesure\"}`. NE POSE PAS de question de précision : la réponse ne changerait rien, la pièce reste hors catalogue.\n";
  $prompt .= "  → Dans `message`, dis en une ou deux phrases que pour cette pièce c'est Robin lui-même qui doit répondre — humidité et sécurité électrique, ça se juge au cas par cas — et invite à lui écrire.\n";
  $prompt .= "  → NE mets PAS de `piece` approchante dans `filters` : une salle de bain n'est pas une cuisine. Mieux vaut ne rien proposer que proposer à côté.\n\n";

  $prompt .= "CHOIX DE `contact_kind` :\n";
  $prompt .= "- \"pro\" : projet professionnel/B2B. CTA principal côté UI = \"Ouvrir le formulaire sur-mesure\".\n";
  $prompt .= "- \"sur-mesure\" : résidentiel avec demande très spécifique (custom dimensions, essence, design). CTAs côté UI = \"Formulaire sur-mesure\" + \"Email\" côte à côte.\n";
  $prompt .= "- \"simple\" : résidentiel léger qui veut juste un échange rapide. CTA principal = \"M'envoyer un email\".\n\n";

  $prompt .= "RÈGLE DU CAS PAR CAS (très important) :\n";
  /* ⚠️ Cette règle encourage les modèles approchants ; la règle « pièce hors
     périmètre » les interdit. Sans l'exception ci-dessous, les deux se
     contredisaient sur exactement le cas visé — une salle de bain aurait reçu
     `piece: cuisine`, slug valide, et le visiteur serait reparti avec une
     sélection de cuisine. Défaut relevé en relecture avant mise en ligne. */
  $prompt .= "- EXCEPTION QUI PRIME SUR TOUT LE RESTE : si la PIÈCE est hors périmètre (voir plus haut), cette règle ne s'applique PAS. Aucun modèle approchant, aucune pièce approchante, `filters` vide. Une salle de bain n'est pas une cuisine.\n";
  $prompt .= "- Si malgré la complexité tu peux quand même proposer 1-2 modèles approchants du catalogue, fais-le : remplis `filters` AVEC `action: \"contact\"`. Le visiteur voit la sélection ET la porte sur-mesure côte à côte.\n";
  $prompt .= "- Si l'écart est trop grand (ex: hôtelier 30 chambres) : bascule directement en `action: \"contact\"` avec `filters: {}` — ne simule pas une recherche catalogue qui n'a aucun sens.\n\n";

  $prompt .= "CHAMPS BONUS pour l'UI contact (à remplir SI action = \"contact\") :\n";
  $prompt .= "- `contact_subject` : résumé court du projet (1 ligne max, ex: \"Projet hôtel — 30 chambres à équiper\").\n";
  $prompt .= "- `contact_message` : pré-remplissage MINIMAL du formulaire, écrit COMME SI le visiteur l'écrivait à Robin (1re personne visiteur). RÈGLES STRICTES :\n";
  $prompt .= "  - Format : 1 à 2 phrases max, jamais plus.\n";
  $prompt .= "  - Contenu : UNIQUEMENT un rappel sobre de ce que le visiteur a explicitement dit dans son message. RIEN d'inventé, RIEN de supposé.\n";
  $prompt .= "  - N'invente JAMAIS de détails (ne mentionne pas le style, les dimensions, les essences, les types de luminaires, l'ambiance, etc. SAUF si le visiteur les a explicitement cités).\n";
  $prompt .= "  - Ne fais PAS la liste \"je préciserai X, Y, Z\" — le visiteur complète seul ses précisions.\n";
  $prompt .= "  - Ne signe pas avec des formules type \"Hâte d'en discuter\", \"Cordialement\", etc.\n";
  $prompt .= "  - Exemple visiteur \"Je suis hôtelier, 30 chambres à équiper\" → contact_message OK : \"Bonjour Robin, je suis hôtelier et je cherche à équiper 30 chambres.\"\n";
  $prompt .= "  - Exemple À ÉVITER : \"Bonjour Robin… je préciserai le style, les dimensions, les types de luminaires, les essences… Hâte d'en discuter.\" (trop long, suppose à la place du visiteur).\n\n";

  $prompt .= "⚠️ CONTRAINTE IMPORTANTE sur le champ `message` quand action=\"contact\" :\n";
  $prompt .= "Quand action=\"contact\", le `message` s'affiche AU-DESSUS d'un formulaire intégré (email + textarea). Le visiteur ne peut PLUS te répondre en chat — il n'a plus que ce formulaire pour communiquer. Donc le `message` :\n";
  $prompt .= "- DOIT être une intro d'accueil chaleureuse qui valide le projet (1-2 phrases courtes).\n";
  $prompt .= "- DOIT inviter à utiliser le formulaire (\"laisse tes coordonnées à Robin\", \"complète juste le formulaire ci-dessous, Robin te recontactera\").\n";
  $prompt .= "- NE DOIT PAS poser de questions ouvertes (\"combien de…\", \"quelle ambiance…\", \"as-tu des contraintes…\") — le visiteur ne peut PAS y répondre en chat. Les précisions à demander vont dans `contact_message` sous forme déclarative.\n";
  $prompt .= "- Exemple OK : \"Super, un projet hôtel pour les couloirs, c'est un beau chantier. Laisse tes coordonnées et précise ton projet ci-dessous — Robin te recontacte sous 48h pour qu'on en discute ensemble.\"\n";
  $prompt .= "- Exemple À ÉVITER : \"J'aimerais en savoir plus : combien de couloirs, quelle ambiance ?\" (questions ouvertes = visiteur bloqué).\n";
  $prompt .= "- Ne dis JAMAIS \"je vais te recontacter\" — c'est Robin qui recontacte, pas toi (assistant). Dis \"Robin te recontacte\".\n\n";

  $prompt .= "RÈGLES :\n";
  $prompt .= "- N'invente PAS de slug : utilise exactement ceux listés dans FILTRES DISPONIBLES.\n";
  $prompt .= "- `message` : 1-2 phrases chaleureuses, tutoiement, ton artisan. Mentionne ce que tu as compris du projet.\n";
  $prompt .= "- Pas d'emoji, pas de markdown dans `message`.\n\n";

  $prompt .= "⚠️ FORMAT DE SORTIE — IMPÉRATIF :\n";
  $prompt .= "Ta réponse DOIT être UNIQUEMENT le JSON décrit ci-dessus. RIEN d'autre :\n";
  $prompt .= "- PAS de prose avant le JSON\n";
  $prompt .= "- PAS de prose après le JSON\n";
  $prompt .= "- PAS de bloc ```markdown autour\n";
  $prompt .= "Premier caractère = `{`, dernier caractère = `}`. Point.\n";

  return $prompt;
}

/**
 * System prompt — conversation libre (Sonnet).
 */
function sapi_megafilter_build_chat_prompt(array $current_filters, array $all_products, array $whitelist, array $matching_ids = [], array $relachements = []) {
  // Round 2 — 1.3 / Round 3 — pivot assistant : contexte d'interaction EN PREMIER
  // (avant ton/savoir/regles/exemples) pour que l'assistant arrête de prétendre que
  // le visiteur voit la grille pendant le chat, et ne se prenne pas pour Robin.
  $prompt  = "CONTEXTE D'INTERACTION :\n";
  $prompt .= "Tu es l'assistant de Robin, présent dans une modale flottante ouverte par-dessus la grille des modèles.\n";
  $prompt .= "TANT QUE le visiteur n'a pas cliqué sur \"Voir la sélection\" pour fermer la modale,\n";
  $prompt .= "IL NE VOIT PAS la grille en dessous (elle est masquée par la modale).\n";
  $prompt .= "Ne dis donc JAMAIS \"tu vois les modèles à côté\", \"regarde la sélection\", ou équivalent.\n";
  $prompt .= "Présente-lui la sélection en mots, comme si vous étiez au téléphone ensemble.\n\n";

  // Injecte ton + savoir + règles + exemples V2. Les exemples guident le ton
  // conversationnel — c'est ce qui les distingue du prompt de recommandation
  // ci-dessous, qui ne les charge pas.
  $prompt .= sapi_megafilter_load_v2_prompts(true);

  $prompt .= "Tu es l'assistant de Robin, l'artisan menuisier lyonnais qui fabrique des luminaires en bois à la découpe laser dans son atelier à Lyon.\n";
  $prompt .= "Tu accompagnes un visiteur qui explore la collection de Robin sur le site atelier-sapi.fr.\n\n";

  $prompt .= "TON :\n";
  $prompt .= "- Chaleureux, simple, tutoiement systématique\n";
  $prompt .= "- Assistant passionné par le travail de Robin, pas vendeur\n";
  $prompt .= "- 2-4 phrases max par réponse\n";
  $prompt .= "- Tu peux mentionner la fabrication (Robin découpe au laser dans son atelier à Lyon, bois français) si pertinent\n";
  $prompt .= "- Parle de Robin à la 3e personne (\"Robin a conçu\", \"son atelier\", \"ses créations\"). N'utilise PAS \"je conçois\", \"mon atelier\", \"mes créations\" — ces formules désignent le travail de Robin, pas le tien.\n";
  $prompt .= "- Pas d'emoji, pas de markdown\n\n";

  $prompt .= "PROJET DU VISITEUR :\n";
  if (empty($current_filters)) {
    $prompt .= "(aucun filtre indiqué pour l'instant)\n";
  } else {
    $human_labels = sapi_megafilter_labels_from_slugs($current_filters);
    $prompt .= sapi_megafilter_format_project_text($current_filters, $human_labels) . "\n";
  }

  // Contrat enrichi : catalogue split (présentés/écartés) + réponses élargies.
  // Remplace l'ancienne section "CATALOGUE COMPLET" : maintenant l'IA sait
  // précisément ce que le visiteur voit dans la grille.
  $prompt .= sapi_megafilter_format_fallback_notes($relachements);
  $prompt .= sapi_megafilter_format_catalog_split($all_products, $matching_ids);

  // Round 3.1 — Fix 1 : ambiance lumineuse alignée sur le catalogue présenté.
  $prompt .= sapi_megafilter_lighting_ambiance_block();

  $prompt .= sapi_megafilter_adaptive_consigne_block();

  $prompt .= "\nSLUGS VALIDES (pour `filters_update`) :\n";
  foreach ($whitelist as $key => $slugs) {
    $prompt .= '- ' . $key . ' : ' . implode(' | ', $slugs) . "\n";
  }

  $prompt .= "\nFORMAT DE RÉPONSE (JSON strict, sans markdown, sans prose autour) :\n";
  $prompt .= "{\n";
  $prompt .= '  "message": "Réponse de Robin en 2-4 phrases...",' . "\n";
  $prompt .= '  "filters_update": { "piece": "cuisine", "style": null },' . "\n";
  $prompt .= '  "action": "contact",' . "\n";
  $prompt .= '  "contact_kind": "pro",' . "\n";
  $prompt .= '  "contact_subject": "Projet hôtel, 30 chambres équipées",' . "\n";
  $prompt .= '  "contact_message": "Bonjour Robin, je suis hôtelier...",' . "\n";
  $prompt .= '  "piece_hors_perimetre": "salle de bain"' . "\n";
  $prompt .= "}\n\n";
  /* ⚠️ CE CHAMP SERT À ROBIN, PAS AU VISITEUR : il lui dit quelles pièces on
     lui demande et qu'il ne couvre pas. Il ne s'affiche nulle part côté site. */
  $prompt .= "`piece_hors_perimetre` : À REMPLIR UNIQUEMENT si tu as déclenché la règle PIÈCE HORS PÉRIMÈTRE. Recopie le mot du visiteur, au singulier et en minuscules (« salle de bain », « garage », « véranda »). Dans TOUS les autres cas, omets ce champ.\n";
  $prompt .= "⚠️ NE LE REMPLIS PAS pour une pièce que tu as simplement rapprochée d'une autre du catalogue : un couloir est une entrée, une salle à manger est un salon. Ce champ ne sert qu'aux pièces que le catalogue NE COUVRE PAS.\n\n";

  // Round 3 — Lot C1 : aligne les 3 voies de sortie sur le freetext (Haiku).
  $prompt .= "3 VOIES DE SORTIE — arbitre au fil de la conversation :\n";
  $prompt .= "1) Conversation NORMALE : `message` + éventuellement `filters_update`, pas d'`action`.\n";
  $prompt .= "2) Demande INCOMPLÈTE : tu poses une question de précision dans `message`, pas d'`action`.\n";
  $prompt .= "3) PROJET CONTACT : la demande sort du périmètre catalogue ou nécessite un échange direct → `action: \"contact\"` + `contact_kind: \"pro\"|\"sur-mesure\"|\"simple\"` + `contact_subject` + `contact_message`.\n\n";

  $prompt .= "CRITÈRES POUR `action: \"contact\"` :\n";
  $prompt .= "- Multi-luminaires (≥2 lampes pour un même projet), pro/B2B (hôtel, restaurant, retail, espace public), dimensions custom, essence custom (chêne, noyer, etc.), combinaison hors catalogue.\n";
  /* Périmètre des pièces — décision Robin du 26/08.
     Le site ne sait conseiller que les sept pièces du room-picker. Une salle de
     bain, un garage, une terrasse posent des questions d'humidité et de sécurité
     électrique sur du bois : seul Robin peut y répondre, et une IA qui improvise
     là-dessus engage sa responsabilité.
     ⚠️ Les synonymes SONT dans le périmètre : le picker dit « Entrée / Couloir »,
     « Salon / Salle à manger », « Bureau / Atelier ». Un couloir n'est donc PAS
     hors périmètre — il est une entrée. Ne pas confondre « pièce absente de la
     liste des slugs » et « pièce hors périmètre », l'erreur a déjà été faite. */
  $prompt .= "- PIÈCE HORS PÉRIMÈTRE : le catalogue ne couvre que cuisine, bureau (ou atelier), salon (ou salle à manger), chambre, chambre d'enfant, entrée (ou couloir), cage d'escalier. Toute AUTRE pièce — salle de bain, salle d'eau, buanderie, garage, cave, terrasse, extérieur, véranda — est hors périmètre.\n";
  $prompt .= "  → Dans ce cas : `action: \"contact\"`, `contact_kind: \"sur-mesure\"`, et NE POSE PAS de question de précision. Dis simplement, en une ou deux phrases, que pour cette pièce c'est Robin lui-même qui doit répondre (humidité, sécurité électrique : ça se juge au cas par cas), et invite à lui écrire.\n";
  $prompt .= "  → NE remplis PAS `filters_update` avec une pièce approchante : une salle de bain n'est pas une cuisine. Mieux vaut ne rien proposer que proposer à côté.\n\n";

  $prompt .= "CHOIX DE `contact_kind` :\n";
  $prompt .= "- \"pro\" : projet professionnel/B2B → CTA UI principal = formulaire sur-mesure.\n";
  $prompt .= "- \"sur-mesure\" : résidentiel avec demande très spécifique → CTAs UI = formulaire + email côte à côte.\n";
  $prompt .= "- \"simple\" : résidentiel léger qui veut juste un échange rapide → CTA UI principal = email direct.\n\n";

  $prompt .= "RÈGLE DU CAS PAR CAS :\n";
  /* Miroir de l'exception du prompt d'extraction — voir le commentaire là-bas.
     Les deux prompts DOIVENT rester identiques sur ce point : s'ils divergent,
     le visiteur reçoit deux comportements selon qu'il parle une ou deux fois. */
  $prompt .= "- EXCEPTION QUI PRIME SUR TOUT LE RESTE : si la PIÈCE est hors périmètre (voir plus haut), cette règle ne s'applique PAS. Aucun modèle approchant, aucune pièce approchante, pas de `filters_update` de pièce. Une salle de bain n'est pas une cuisine.\n";
  $prompt .= "- Si malgré la complexité tu peux quand même proposer 1-2 modèles approchants, fais-le : remplis `filters_update` AVEC `action: \"contact\"`. Le visiteur voit la sélection ET la porte sur-mesure côte à côte.\n";
  $prompt .= "- Si l'écart est trop grand (ex: hôtelier 30 chambres) : bascule directement en `action: \"contact\"` sans `filters_update`.\n\n";

  $prompt .= "CHAMPS BONUS pour l'UI contact (à remplir SI action = \"contact\") :\n";
  $prompt .= "- `contact_subject` : résumé court du projet (1 ligne, ex: \"Projet hôtel — 30 chambres à équiper\").\n";
  $prompt .= "- `contact_message` : pré-remplissage MINIMAL du formulaire, 1re personne visiteur. RÈGLES STRICTES :\n";
  $prompt .= "  - Format : 1 à 2 phrases max, jamais plus.\n";
  $prompt .= "  - Contenu : UNIQUEMENT un rappel sobre de ce que le visiteur a explicitement dit. RIEN d'inventé, RIEN de supposé (pas de style, dimensions, essences, types de luminaires, ambiance si pas mentionnés par le visiteur).\n";
  $prompt .= "  - Ne fais PAS la liste \"je préciserai X, Y, Z\" — le visiteur complète seul.\n";
  $prompt .= "  - Ne signe pas (\"Hâte d'en discuter\", etc.).\n";
  $prompt .= "  - Exemple OK : \"Bonjour Robin, je suis hôtelier et je cherche à équiper 30 chambres.\"\n";
  $prompt .= "  - Exemple À ÉVITER : long pré-remplissage avec détails supposés.\n\n";

  $prompt .= "⚠️ CONTRAINTE IMPORTANTE sur le champ `message` quand action=\"contact\" :\n";
  $prompt .= "Quand action=\"contact\", le `message` s'affiche AU-DESSUS d'un formulaire intégré (email + textarea). Le visiteur ne peut PLUS te répondre en chat — il n'a plus que ce formulaire pour communiquer. Donc le `message` :\n";
  $prompt .= "- DOIT être une intro d'accueil chaleureuse qui valide le projet (1-2 phrases courtes).\n";
  $prompt .= "- DOIT inviter à utiliser le formulaire (\"laisse tes coordonnées à Robin\", \"complète juste le formulaire ci-dessous, Robin te recontactera\").\n";
  $prompt .= "- NE DOIT PAS poser de questions ouvertes (\"combien de…\", \"quelle ambiance…\") — le visiteur ne peut PAS y répondre en chat. Les précisions vont dans `contact_message` sous forme déclarative.\n";
  $prompt .= "- Ne dis JAMAIS \"je vais te recontacter\" — c'est Robin qui recontacte, pas toi (assistant). Dis \"Robin te recontacte\".\n\n";

  $prompt .= "RÈGLES :\n";
  $prompt .= "- `message` : obligatoire, 2-4 phrases.\n";
  $prompt .= "- `filters_update` : optionnel. À inclure UNIQUEMENT si tu veux changer les chips suite au message du visiteur (ex. il précise, change d'avis). Utilise les slugs exacts. `null` pour supprimer un filtre. Ne touche pas aux chips non concernés.\n";
  $prompt .= "- Ne nomme JAMAIS de modèle précis dans `message` (le visiteur les voit dans la grille à côté). Présente plutôt l'ambiance, la matière, le format.\n\n";

  $prompt .= "⚠️ FORMAT DE SORTIE — IMPÉRATIF :\n";
  $prompt .= "Les EXEMPLES de ton plus haut sont là pour calibrer TA VOIX dans le champ `message`. Ta réponse, elle, DOIT être UNIQUEMENT le JSON décrit ci-dessus. RIEN d'autre :\n";
  $prompt .= "- PAS de prose avant le JSON\n";
  $prompt .= "- PAS de prose après le JSON\n";
  $prompt .= "- PAS de bloc ```markdown autour\n";
  $prompt .= "- Tout ton texte conversationnel va DANS le champ `message`, pas dehors.\n";
  $prompt .= "Premier caractère de ta réponse = `{`, dernier caractère = `}`. Point.\n";

  return $prompt;
}

/* ── Endpoint A1 : extraction freetext (Haiku) ───────────────────────── */
add_action('wp_ajax_sapi_megafilter_freetext', 'sapi_ajax_megafilter_freetext');
add_action('wp_ajax_nopriv_sapi_megafilter_freetext', 'sapi_ajax_megafilter_freetext');

function sapi_ajax_megafilter_freetext() {
  if (!isset($_POST['nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['nonce'])), 'sapi-megafilter')) {
    wp_send_json_error(['message' => 'Nonce invalide']);
    return;
  }

  if (!sapi_guide_check_rate_limit()) {
    wp_send_json_error([
      'message'  => 'rate_limit',
      'fallback' => 'Trop de demandes pour le moment, réessaie dans une heure ou contacte-moi directement via le formulaire.',
    ]);
    return;
  }

  $message = sanitize_textarea_field(wp_unslash($_POST['message'] ?? ''));
  if (empty($message) || mb_strlen($message) > 500) {
    wp_send_json_error(['message' => 'Message invalide']);
    return;
  }

  $session_id = isset($_POST['session_id']) ? sanitize_text_field(wp_unslash($_POST['session_id'])) : '';
  if (empty($session_id)) {
    $session_id = 'mfs_' . bin2hex(random_bytes(8));
  }

  $whitelist = sapi_megafilter_filters_whitelist();
  $system_prompt = sapi_megafilter_build_freetext_prompt($whitelist);

  $ai_text = sapi_megafilter_call_claude(
    'claude-haiku-4-5',
    $system_prompt,
    [['role' => 'user', 'content' => $message]],
    512
  );

  if (!$ai_text) {
    wp_send_json_error([
      'message'  => 'api_error',
      'fallback' => 'Je n\'arrive pas à analyser ton message pour l\'instant. Tu peux réessayer ou m\'écrire directement.',
    ]);
    return;
  }

  // Round 3 fix — Le prompt Lot C1 permet 3 voies de sortie : standard
  // (filters peuplés), incomplet (filters vide + question dans message),
  // contact (action=contact sans filters). On accepte donc tant qu'on a
  // un JSON parsé valide avec au moins un message ou une action — sinon
  // seulement fallback parse_error.
  $parsed = sapi_megafilter_parse_json($ai_text);
  $has_message = ($parsed && isset($parsed['message']) && is_string($parsed['message']) && $parsed['message'] !== '');
  $has_action  = ($parsed && isset($parsed['action']) && is_string($parsed['action']));
  if (!$parsed || (!$has_message && !$has_action)) {
    wp_send_json_error([
      'message'  => 'parse_error',
      'fallback' => 'Je n\'ai pas bien compris ton message. Tu peux reformuler ou m\'écrire directement.',
    ]);
    return;
  }

  // filters peut être absent (cas action=contact direct ou question de
  // précision). On normalise à array vide pour la suite du traitement.
  $raw_filters = (isset($parsed['filters']) && is_array($parsed['filters'])) ? $parsed['filters'] : [];
  $clean_filters = [];
  foreach ($raw_filters as $key => $val) {
    if (!isset($whitelist[$key])) continue;
    if (!is_string($val)) continue;
    if (!in_array($val, $whitelist[$key], true)) continue;
    $clean_filters[$key] = $val;
  }

  $robin_message = (isset($parsed['message']) && is_string($parsed['message']))
    ? sapi_strip_long_dashes(sanitize_textarea_field($parsed['message']))
    : '';

  // Round 2 — 4.1.c : on propage `action: contact` quand l'IA route vers le
  // formulaire (projet hors-norme : pro, sur-mesure explicite, demande
  // spéciale). Le JS affichera un CTA Contact au lieu de "Voir la sélection".
  // Round 3 — Lot C1 : enrichissement avec contact_kind/subject/message pour
  // que le front puisse construire l'UI dédiée + pré-remplir le formulaire.
  $action = null;
  $contact_kind = null;
  $contact_subject = '';
  $contact_message = '';
  $piece_hors_perimetre = '';
  if (isset($parsed['action']) && $parsed['action'] === 'contact') {
    $action = 'contact';
    $allowed_kinds = ['pro', 'sur-mesure', 'simple'];
    if (isset($parsed['contact_kind']) && is_string($parsed['contact_kind']) && in_array($parsed['contact_kind'], $allowed_kinds, true)) {
      $contact_kind = $parsed['contact_kind'];
    }
    if (isset($parsed['contact_subject']) && is_string($parsed['contact_subject'])) {
      /* ⚠️ Le filtre des tirets s'appliquait au MESSAGE et pas au SUJET —
         deux lignes écrites côte à côte, une seule protégée. Le tiret
         cadratin survivait donc à l'endroit le plus visible : l'objet du
         mail de Robin, et le tableau de bord. Relevé par Robin sur une
         capture le 26/08. */
      $contact_subject = sapi_strip_long_dashes(sanitize_text_field($parsed['contact_subject']));
    }
    if (isset($parsed['contact_message']) && is_string($parsed['contact_message'])) {
      $contact_message = sapi_strip_long_dashes(sanitize_textarea_field($parsed['contact_message']));
    }
    /* ⚠️ LU UNIQUEMENT DANS LA BRANCHE `action === contact`, ET C'EST LE
       GARDE-FOU. Hors de cette branche, un modèle bavard pourrait remplir ce
       champ pour une pièce parfaitement couverte, et Robin lirait « salon » en
       tête de son classement des pièces qu'il ne fait pas. */
    if (isset($parsed['piece_hors_perimetre']) && is_string($parsed['piece_hors_perimetre'])) {
      $piece_hors = trim(sanitize_text_field($parsed['piece_hors_perimetre']));
      // 60 caractères = la colonne. Une « pièce » plus longue est une phrase,
      // donc une réponse hors sujet : on la laisse tomber plutôt que la couper.
      if ($piece_hors !== '' && mb_strlen($piece_hors) <= 60) {
        $piece_hors_perimetre = function_exists('mb_strtolower') ? mb_strtolower($piece_hors) : strtolower($piece_hors);
      }
    }
  }

  wp_send_json_success([
    'filters'         => $clean_filters,
    'message'         => $robin_message,
    'action'          => $action,
    'contact_kind'    => $contact_kind,
    'contact_subject' => $contact_subject,
    'contact_message' => $contact_message,
    'piece_hors_perimetre' => $piece_hors_perimetre,
    'session_id'      => $session_id,
  ]);
}

/* ── Endpoint A2 : conversation libre (Sonnet) ───────────────────────── */
add_action('wp_ajax_sapi_megafilter_chat', 'sapi_ajax_megafilter_chat');
add_action('wp_ajax_nopriv_sapi_megafilter_chat', 'sapi_ajax_megafilter_chat');

function sapi_ajax_megafilter_chat() {
  if (!isset($_POST['nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['nonce'])), 'sapi-megafilter')) {
    wp_send_json_error(['message' => 'Nonce invalide']);
    return;
  }

  if (!sapi_guide_check_rate_limit()) {
    wp_send_json_success([
      'message'      => 'Je ne peux pas répondre davantage pour le moment. Si tu veux, écris-moi directement via le formulaire et je te répondrai personnellement.',
      'action'       => 'contact',
      'conversation' => [],
    ]);
    return;
  }

  $user_message = sanitize_textarea_field(wp_unslash($_POST['user_message'] ?? ''));
  if (empty($user_message) || mb_strlen($user_message) > 1000) {
    wp_send_json_error(['message' => 'Message invalide']);
    return;
  }

  $session_id = isset($_POST['session_id']) ? sanitize_text_field(wp_unslash($_POST['session_id'])) : '';
  if (empty($session_id)) {
    $session_id = 'mfs_' . bin2hex(random_bytes(8));
  }

  $filters_raw = isset($_POST['current_filters']) ? wp_unslash($_POST['current_filters']) : '{}';
  $current_filters = json_decode($filters_raw, true);
  if (!is_array($current_filters)) $current_filters = [];

  $conversation_raw = isset($_POST['conversation']) ? wp_unslash($_POST['conversation']) : '[]';
  $conversation = json_decode($conversation_raw, true);
  if (!is_array($conversation)) $conversation = [];

  // Garde-fou serveur : 15 échanges utilisateur max (= 30 messages user+assistant)
  $user_msg_count = 0;
  foreach ($conversation as $m) {
    if (isset($m['role']) && $m['role'] === 'user') $user_msg_count++;
  }
  if ($user_msg_count >= 15) {
    wp_send_json_success([
      'message'      => 'On a bien discuté ! Pour aller plus loin, écris-moi directement via le formulaire de contact et on continuera ensemble.',
      'action'       => 'contact',
      'conversation' => $conversation,
      'session_id'   => $session_id,
    ]);
    return;
  }

  $whitelist = sapi_megafilter_filters_whitelist();
  $clean_current = [];
  foreach ($current_filters as $k => $v) {
    if (!isset($whitelist[$k])) continue;
    if (!is_string($v)) continue;
    if (!in_array($v, $whitelist[$k], true)) continue;
    $clean_current[$k] = $v;
  }

  $all_products = sapi_guide_query_all_products([]);

  /* ⚠️ SÉLECTION RECALCULÉE ICI, PLUS JAMAIS LUE DEPUIS LE POST.
     Le JS envoyait `matching_product_ids`, qu'il obtenait de
     `window.sapiMegaFilter` — objet SUPPRIMÉ avec le filtrage navigateur
     (Tâche 4b). Depuis, `buildFilterMeta()` tombait silencieusement sur son
     repli et envoyait une liste VIDE. Le prompt affichait donc
     « PRODUITS PRÉSENTÉS AU VISITEUR (0) : (aucun) », et la consigne qui suit
     dit : « si aucun produit présenté, propose chaleureusement le sur-mesure ».
     → **Dans CHAQUE conversation, Robin orientait vers le sur-mesure des
     visiteurs pour qui il avait des modèles en stock.** Invisible à l'écran,
     invisible dans les logs : rien ne plantait, l'IA répondait simplement à
     côté. Le même piège que `$sapi_filter_rules` : « rien de cassé » voulait
     dire « rien ne s'applique ».
     Le correctif était DÉJÀ ÉCRIT et commenté quinze lignes plus loin, sur
     l'endpoint conseil (`sapi_ajax_megafilter_advice`) — il n'avait été posé
     que sur un des deux endpoints. On applique ici le même moteur réel. */
  $chat_cats     = function_exists('sapi_guide_get_categories') ? sapi_guide_get_categories($clean_current) : [];
  $chat_res      = function_exists('sapi_guide_query_products') ? sapi_guide_query_products($clean_current, $chat_cats) : ['products' => []];
  $chat_products = isset($chat_res['products']) ? $chat_res['products'] : [];
  if (function_exists('sapi_conseiller_rank_products')) {
    $chat_products = sapi_conseiller_rank_products($chat_products, $clean_current);
  }
  $matching_ids = array_values(array_filter(array_map(function ($p) {
    return isset($p['id']) ? (int) $p['id'] : 0;
  }, $chat_products)));
  // Les relâchements viennent du MOTEUR, plus du navigateur — cf. la fonction.
  $relachements = isset($chat_res['fallback_notes']) ? $chat_res['fallback_notes'] : [];

  $system_prompt = sapi_megafilter_build_chat_prompt($clean_current, $all_products, $whitelist, $matching_ids, $relachements);

  $messages = [];
  foreach ($conversation as $msg) {
    if (!isset($msg['role']) || !isset($msg['content'])) continue;
    $role = ($msg['role'] === 'assistant') ? 'assistant' : 'user';
    $messages[] = ['role' => $role, 'content' => sanitize_textarea_field($msg['content'])];
  }
  $messages[] = ['role' => 'user', 'content' => $user_message];

  $ai_text = sapi_megafilter_call_claude(
    'claude-sonnet-4-6',
    $system_prompt,
    $messages,
    1024
  );

  if (!$ai_text) {
    wp_send_json_error([
      'message'  => 'api_error',
      'fallback' => 'Je n\'arrive pas à te répondre pour l\'instant. Tu peux contacter Robin directement via le formulaire.',
    ]);
    return;
  }

  $parsed = sapi_megafilter_parse_json($ai_text);

  // Si le JSON est foireux, on tombe sur le texte brut comme message neutre
  $robin_message = '';
  $filters_update = null;
  $action = null;

  if ($parsed && isset($parsed['message']) && is_string($parsed['message'])) {
    $robin_message = sapi_strip_long_dashes(sanitize_textarea_field($parsed['message']));
  } else {
    $robin_message = sanitize_textarea_field($ai_text);
  }

  if ($parsed && isset($parsed['filters_update']) && is_array($parsed['filters_update'])) {
    $filters_update = [];
    foreach ($parsed['filters_update'] as $k => $v) {
      if (!isset($whitelist[$k])) continue;
      if ($v === null) {
        $filters_update[$k] = null;
      } elseif (is_string($v) && in_array($v, $whitelist[$k], true)) {
        $filters_update[$k] = $v;
      }
    }
    if (empty($filters_update)) $filters_update = null;
  }

  // Round 3 — Lot C1 : enrichissement avec contact_kind/subject/message,
  // miroir du endpoint freetext. Permet à l'UI de construire CTAs adaptés
  // + pré-remplir le formulaire /sur-mesure/.
  $contact_kind = null;
  $contact_subject = '';
  $contact_message = '';
  $piece_hors_perimetre = '';
  if ($parsed && isset($parsed['action']) && $parsed['action'] === 'contact') {
    $action = 'contact';
    $allowed_kinds = ['pro', 'sur-mesure', 'simple'];
    if (isset($parsed['contact_kind']) && is_string($parsed['contact_kind']) && in_array($parsed['contact_kind'], $allowed_kinds, true)) {
      $contact_kind = $parsed['contact_kind'];
    }
    if (isset($parsed['contact_subject']) && is_string($parsed['contact_subject'])) {
      /* ⚠️ Le filtre des tirets s'appliquait au MESSAGE et pas au SUJET —
         deux lignes écrites côte à côte, une seule protégée. Le tiret
         cadratin survivait donc à l'endroit le plus visible : l'objet du
         mail de Robin, et le tableau de bord. Relevé par Robin sur une
         capture le 26/08. */
      $contact_subject = sapi_strip_long_dashes(sanitize_text_field($parsed['contact_subject']));
    }
    if (isset($parsed['contact_message']) && is_string($parsed['contact_message'])) {
      $contact_message = sapi_strip_long_dashes(sanitize_textarea_field($parsed['contact_message']));
    }
    /* ⚠️ LU UNIQUEMENT DANS LA BRANCHE `action === contact`, ET C'EST LE
       GARDE-FOU. Hors de cette branche, un modèle bavard pourrait remplir ce
       champ pour une pièce parfaitement couverte, et Robin lirait « salon » en
       tête de son classement des pièces qu'il ne fait pas. */
    if (isset($parsed['piece_hors_perimetre']) && is_string($parsed['piece_hors_perimetre'])) {
      $piece_hors = trim(sanitize_text_field($parsed['piece_hors_perimetre']));
      // 60 caractères = la colonne. Une « pièce » plus longue est une phrase,
      // donc une réponse hors sujet : on la laisse tomber plutôt que la couper.
      if ($piece_hors !== '' && mb_strlen($piece_hors) <= 60) {
        $piece_hors_perimetre = function_exists('mb_strtolower') ? mb_strtolower($piece_hors) : strtolower($piece_hors);
      }
    }
  }

  $new_conversation = array_merge($conversation, [
    ['role' => 'user',      'content' => $user_message],
    ['role' => 'assistant', 'content' => $robin_message],
  ]);

  wp_send_json_success([
    'message'         => $robin_message,
    'filters_update' => $filters_update,
    'action'          => $action,
    'contact_kind'    => $contact_kind,
    'contact_subject' => $contact_subject,
    'contact_message' => $contact_message,
    'piece_hors_perimetre' => $piece_hors_perimetre,
    'conversation'    => $new_conversation,
    'session_id'      => $session_id,
  ]);
}

/* ═══════════════════════════════════════════════════════════
   MÉGA-FILTRE F2a / F2a-bis — Endpoints additionnels
   - sapi_megafilter_advice    : phrase IA conseillère unique, appelée à la sortie
     de la modale (Sonnet, sans cache). Stockée dans sapiProject.advice_text côté
     front et réutilisée sans nouvel appel.
   - sapi_megafilter_surmesure : soumission form sur-mesure (email Robin)
═══════════════════════════════════════════════════════════ */

/**
 * Textes génériques pré-rédigés par pièce — utilisés en fallback si l'IA plante
 * et en affichage par défaut sur la card "Mon projet" tant qu'aucun parcours
 * n'a abouti dans la modale. Source de vérité unique partagée PHP / JS.
 */
/**
 * Liste des pièces /mes-creations/ (slug → libellé).
 * Le hero est désormais statique (titre fixe "Mes créations") : cette liste
 * sert à archive-product.php pour énumérer les slugs et lire les photos ACF
 * par pièce (hero_<slug>), qui alimentent le bandeau de la card "Mon projet".
 */
function sapi_get_hero_piece_titles() {
  return [
    'default' => __('Mes Créations', 'theme-sapi-maison'),
    'pieces'  => [
      'salon'    => __('Pour un salon', 'theme-sapi-maison'),
      'chambre'  => __('Pour une chambre', 'theme-sapi-maison'),
      'chambre-enfant' => __('Pour une chambre enfant', 'theme-sapi-maison'),
      'cuisine'  => __('Pour une cuisine', 'theme-sapi-maison'),
      'bureau'   => __('Pour un bureau', 'theme-sapi-maison'),
      'entree'   => __('Pour une entrée', 'theme-sapi-maison'),
      'escalier' => __('Pour un escalier', 'theme-sapi-maison'),
    ],
  ];
}

function sapi_megafilter_get_generic_advices() {
  return [
    'cuisine'  => __("Pour une cuisine, je te propose les modèles où l'ampoule reste à découvert. La lumière descend franchement sur le plan de travail, sans zone d'ombre.", 'theme-sapi-maison'),
    'bureau'   => __("Pour un bureau, je te propose les modèles où l'ampoule reste à découvert. La lumière est directe et tranchée, idéale pour la concentration sans fatiguer les yeux.", 'theme-sapi-maison'),
    'salon'    => __("Pour un salon, je te propose des luminaires à ampoule entourée. La lumière passe à travers le bois et dessine ses motifs au mur, l'ambiance s'installe.", 'theme-sapi-maison'),
    'chambre'  => __("Pour une chambre, je te propose des modèles à ampoule entourée. Une lumière douce et diffuse, qui invite au calme et révèle les jeux du bois.", 'theme-sapi-maison'),
    'chambre-enfant' => __("Pour une chambre d'enfant, je te propose des modèles à ampoule entourée. Une lumière douce et tamisée, jamais agressive, qui rassure au moment du coucher et fait danser les ombres du bois au plafond.", 'theme-sapi-maison'),
    'entree'   => __("Pour une entrée, je te propose des modèles à ampoule entourée. La lumière joue avec les découpes du bois, donne le ton dès le pas de porte.", 'theme-sapi-maison'),
    'escalier' => __("Pour un escalier, je te propose les modèles hauts qui occupent le volume. La cage se révèle par étages, l'œil suit la lumière en montant.", 'theme-sapi-maison'),
  ];
}

function sapi_megafilter_generic_advice_for($piece) {
  $advices = sapi_megafilter_get_generic_advices();
  if (is_string($piece) && isset($advices[$piece])) return $advices[$piece];
  return __('Voici la sélection que je te propose dans le catalogue de Robin.', 'theme-sapi-maison');
}

/**
 * Refonte /mes-creations/ — état B « immersion ».
 * Résout la pièce d'arrivée depuis ?piece=<slug>, validée contre la whitelist
 * des pièces du méga-filtre. Retourne le slug validé, ou '' si absent/invalide.
 * Source unique de vérité pour : le body_class, le rendu serveur du hero
 * immersif, et le flag JS SAPI_IMMERSION.
 */
/**
 * Les réponses du questionnaire portées par l'URL, validées.
 *
 * ⚠️ POURQUOI L'URL DOIT LES PORTER TOUTES.
 * `/mes-creations/` était une page de PIÈCE où la sélection était un effet de
 * bord : le template ne lisait que `?piece=`, alors que le moteur se règle
 * d'abord sur `sortie` — le critère le plus déterminant était précisément
 * celui que l'adresse ne savait pas exprimer. Une sélection n'était donc ni
 * partageable ni rechargeable.
 *
 * ⚠️ LECTURE INDIFFÉRENTE À L'ORDRE DES PARAMÈTRES. Les liens déjà partagés
 * (newsletters, Pinterest) ne doivent jamais casser. C'est l'ÉCRITURE qui
 * devra produire un ordre constant — sinon la même sélection existe en
 * centaines de variantes pour le cache et pour Google.
 *
 * ⚠️ `?piece=` SEUL RESTE VALIDE POUR TOUJOURS. Les cartes-pièces de l'accueil
 * et de la page conseils n'écrivent que ça, et des liens sont dans la nature.
 * Cette fonction ne fait qu'AJOUTER la lecture des six autres clés.
 *
 * La validation réutilise `sapi_megafilter_sanitize_project()` : appartenance
 * stricte à la whitelist, elle-même dérivée du questionnaire. Aucune table
 * recopiée.
 */
function sapi_mescreations_immersion_answers() {
  $brut = [];
  foreach (array_keys(sapi_megafilter_filters_whitelist()) as $cle) {
    if (isset($_GET[$cle]) && is_string($_GET[$cle])) {
      $brut[$cle] = sanitize_text_field(wp_unslash($_GET[$cle]));
    }
  }
  list($propre) = sapi_megafilter_sanitize_project($brut);
  return $propre;
}

/**
 * La signature d'un jeu de réponses, dans un ORDRE FIXE.
 *
 * ⚠️ C'EST CE QUI EMPÊCHE UNE REQUÊTE INUTILE À CHAQUE VISITE.
 * Le JS compare la signature du projet mémorisé à celle de ce que le serveur a
 * rendu, pour ne pas redemander une sélection identique. Cette comparaison est
 * une égalité de chaînes JSON, donc **sensible à l'ordre des clés**. Tant que
 * la signature n'avait qu'une clé (`piece`), l'ordre ne se voyait pas. À sept
 * clés, si PHP et JS ne sérialisent pas dans le même ordre, la déduplication
 * échoue TOUJOURS — sans rien casser de visible : une requête part pour rien à
 * chaque chargement, et la bonne sélection de l'URL se fait remplacer par
 * l'ancien projet du visiteur.
 *
 * L'ordre canonique est celui du questionnaire (`sapi_guide_get_steps()`), la
 * source unique. Le miroir JS est dans `sapi-mescreations-immersion.js`.
 */
function sapi_immersion_signature(array $answers) {
  $ordonne = [];
  if (function_exists('sapi_guide_get_steps')) {
    foreach (sapi_guide_get_steps() as $step) {
      $id = isset($step['id']) ? $step['id'] : '';
      if ($id !== '' && isset($answers[$id])) $ordonne[$id] = $answers[$id];
    }
  }
  // Filet : une clé hors questionnaire ne doit pas disparaître en silence.
  foreach ($answers as $k => $v) {
    if (!isset($ordonne[$k])) $ordonne[$k] = $v;
  }
  return wp_json_encode($ordonne, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

function sapi_mescreations_immersion_piece() {
  if (empty($_GET['piece'])) return '';
  // L'immersion s'active sur ?piece= valide. En pratique ces URLs viennent du
  // room-picker (les cartes-pièces sont des liens ?piece=) ; la reprise auto qui
  // ajoutait ?piece= sans clic a été retirée → un revenant arrive sur le picker.
  $piece = sanitize_key(wp_unslash($_GET['piece']));
  $whitelist = function_exists('sapi_megafilter_filters_whitelist') ? sapi_megafilter_filters_whitelist() : [];
  $valid = isset($whitelist['piece']) && is_array($whitelist['piece']) ? $whitelist['piece'] : [];
  return in_array($piece, $valid, true) ? $piece : '';
}

/**
 * Choix de pièces du room-picker (home + état A /mes-creations/).
 * Source UNIQUE (ordre d'affichage + libellés courts) : évite la duplication
 * du tableau entre front-page.php et woocommerce/archive-product.php.
 * Les icônes SVG correspondantes sont fournies par sapi_guide_get_icons().
 */
function sapi_room_choices() {
  return [
    ['label' => 'Salon',          'slug' => 'salon',          'icon' => 'sofa'],
    ['label' => 'Cuisine',        'slug' => 'cuisine',        'icon' => 'dining'],
    ['label' => 'Chambre',        'slug' => 'chambre',        'icon' => 'bed'],
    ['label' => 'Chambre enfant', 'slug' => 'chambre-enfant', 'icon' => 'teddy'],
    ['label' => 'Bureau',         'slug' => 'bureau',         'icon' => 'monitor'],
    ['label' => 'Entrée',         'slug' => 'entree',         'icon' => 'door'],
    ['label' => 'Escalier',       'slug' => 'escalier',       'icon' => 'stairs'],
  ];
}

/**
 * Forme possessive tutoyée d'une pièce (« ton salon », « ta cuisine »…).
 *
 * ⚠️ C'EST ICI LA SOURCE, ET NULLE PART AILLEURS. Le sens était inverse jusqu'au
 * 28/08 : le JS portait sa propre copie (`PIECE_TUTOIEMENT`) et ce docblock s'en
 * disait le « miroir ». Cette copie est supprimée ; la table part maintenant au
 * navigateur dans `SAPI_PROJECT.possessifs`, via `sapi_piece_possessive_map()`.
 * Si tu ajoutes une pièce au questionnaire, c'est cette table qu'il faut
 * compléter — les deux côtés la reçoivent.
 *
 * « votre » serait neutre, mais « ton/ta » s'accorde au genre : d'où une table
 * explicite plutôt qu'une règle. Repli sur « ta pièce » si la clé est inconnue —
 * ⚠️ le navigateur, lui, préfère se taire que dire « ta pièce » (voir
 * sapi-room-picker.js).
 */
function sapi_piece_possessive_map() {
  return [
    'cuisine'        => 'ta cuisine',
    'bureau'         => 'ton bureau',
    'salon'          => 'ton salon',
    'chambre'        => 'ta chambre',
    'chambre-enfant' => "ta chambre d'enfant",
    'entree'         => 'ton entrée',
    'escalier'       => 'ta cage d\'escalier',
  ];
}
function sapi_piece_possessive($piece) {
  $map = sapi_piece_possessive_map();
  return isset($map[$piece]) ? $map[$piece] : 'ta pièce';
}

/**
 * La même chose, mais À LA PREMIÈRE PERSONNE.
 *
 * ⚠️ CE N'EST PAS LA TABLE CI-DESSUS AVEC UN AUTRE PRONOM. Celle du dessus est
 * la voix de Robin (« ton salon ») ; celle-ci est la voix du VISITEUR, parce
 * qu'elle sert à pré-rédiger le message qu'il enverra et qu'il signera.
 * ⚠️ Et ce n'est pas mécanique : « mon salon », « ma cuisine », mais surtout
 * PAS « ma chambre enfant ». D'où une table explicite, comme l'autre.
 * Repli volontairement neutre : sans clé connue, on écrit « ma pièce » plutôt
 * que d'inventer un genre.
 */
function sapi_piece_possessive_mienne_map() {
  return [
    'cuisine'        => 'ma cuisine',
    'bureau'         => 'mon bureau',
    'salon'          => 'mon salon',
    'chambre'        => 'ma chambre',
    'chambre-enfant' => "la chambre de mon enfant",
    'entree'         => 'mon entrée',
    'escalier'       => "ma cage d'escalier",
  ];
}
function sapi_piece_possessive_mienne($piece) {
  $map = sapi_piece_possessive_mienne_map();
  return isset($map[$piece]) ? $map[$piece] : 'ma pièce';
}

/**
 * Les TROIS textes de l'écran « contacter Robin » quand on y arrive par la card
 * sur-mesure du carrousel (et non par la fin du questionnaire).
 *
 * Pourquoi ils existent : par ce chemin, on saute le questionnaire, donc l'IA
 * n'a rien rédigé. Il faut de quoi remplir l'écran sans elle, et sans latence.
 *
 * ⚠️ TROIS TEXTES, PAS TREIZE. Une première version prévoyait un message
 * rédigé par pièce, puis deux versions selon que le projet était renseigné ou
 * non. Robin a tranché pour le minimum : un modèle unique où la pièce se
 * glisse, plus une phrase d'appoint. Moins de matière à maintenir, et surtout
 * moins de chemins à tester.
 *
 * ⚠️ `message` est écrit à la PREMIÈRE personne : c'est le visiteur qui
 * l'enverra. Ne pas y mettre la voix de Robin.
 * ⚠️ La ligne vide finale n'est pas une coquetterie : elle ouvre une ligne
 * sous la phrase, pour que le visiteur écrive en dessous plutôt qu'au bout.
 * Le curseur, lui, n'est pas placé : on ne donne pas le focus à ce champ, ça
 * ouvrirait le clavier dès l'affichage sur téléphone.
 */
function sapi_surmesure_contact_texts() {
  return [
    'accueil' => __("Pour ce projet, un modèle sur-mesure est sans doute une très bonne idée. Dis-moi ce que tu as en tête, je te réponds sous 48h.", 'theme-sapi-maison'),
    /* Ajoutée à la précédente UNIQUEMENT si le projet contient autre chose que
       la pièce. Elle répond à la question que se pose le visiteur à ce
       moment-là : « est-ce que je dois tout retaper ? » */
    'appoint' => __("Les détails de ton projet me seront envoyés avec ton message.", 'theme-sapi-maison'),
    /* %s = la pièce au possessif première personne. */
    'message' => __("Bonjour Robin, je cherche un luminaire sur-mesure pour %s.\n\n", 'theme-sapi-maison'),
  ];
}

/**
 * Ajoute la classe body `mescreations-immersion` quand on est sur la boutique
 * avec un ?piece= valide → le CSS bascule en état B (masque hero croquis +
 * cards conseiller, affiche le hero immersif) et sapi-cards-conseiller.js se
 * met en retrait (catalogue bas laissé intact).
 */
add_filter('body_class', function ($classes) {
  if (function_exists('is_shop') && is_shop() && sapi_mescreations_immersion_piece() !== '') {
    // Classe DISTINCTE du nom de la section (.mescreations-immersion) : sinon
    // la règle de base .mescreations-immersion{display:flex;width:100vw;
    // overflow:hidden;…} s'appliquerait AUSSI au <body> (collision de classe).
    $classes[] = 'mescreations-immersion-on';
  }
  return $classes;
});

/* Reprise auto retirée (décision Robin) : un revenant arrive sur le room-picker,
   pas directement dans l'immersion. L'immersion s'obtient via un clic sur une
   carte-pièce (lien ?piece=) ; plus de redirection automatique. */

/**
 * Sanitise un payload {answers, labels} en ne gardant que les paires reconnues
 * dans la whitelist du méga-filtre. Retourne [$clean_answers, $clean_labels].
 */
function sapi_megafilter_sanitize_project($answers_raw, $labels_raw = []) {
  $whitelist = sapi_megafilter_filters_whitelist();
  $clean_answers = [];
  $clean_labels  = [];
  if (!is_array($answers_raw)) return [$clean_answers, $clean_labels];

  foreach ($answers_raw as $k => $v) {
    $key = sanitize_key($k);
    if (!isset($whitelist[$key])) continue;
    if (!is_string($v)) continue;
    if (!in_array($v, $whitelist[$key], true)) continue;
    $clean_answers[$key] = $v;
  }

  if (is_array($labels_raw)) {
    foreach ($labels_raw as $k => $v) {
      $key = sanitize_key($k);
      if (!isset($clean_answers[$key])) continue; // pas de label sans answer
      if (!is_string($v)) continue;
      $clean_labels[$key] = sanitize_text_field($v);
    }
  }

  return [$clean_answers, $clean_labels];
}

/**
 * Construit un résumé textuel multi-ligne du projet visiteur, avec des CLÉS
 * EXPLICITES qui lèvent l'ambiguïté pour l'IA (ex. "Emplacement de la sortie
 * électrique : Au mur" plutôt que "Sortie : Au mur" qui pouvait être lu
 * comme "la pièce est au mur"). Utilisé dans les prompts IA et l'email
 * sur-mesure à Robin.
 */
function sapi_megafilter_format_project_text(array $answers, array $labels) {
  $key_labels = [
    'piece'           => 'Pièce où installer le luminaire',
    'taille'          => 'Taille de la pièce',
    'taille_escalier' => "Type d'escalier",
    'eclairage'       => "Rôle d'éclairage attendu",
    'sortie'          => "Emplacement de la sortie électrique",
    'hauteur'         => 'Hauteur sous plafond',
    'table'           => "Sera-t-il au-dessus d'un meuble (table/lit/bureau)",
    'style'           => 'Style décoratif souhaité',
  ];
  $parts = [];
  foreach ($key_labels as $k => $label) {
    if (!isset($answers[$k])) continue;
    $value = isset($labels[$k]) ? $labels[$k] : $answers[$k];
    $parts[] = '- ' . $label . ' : ' . $value;
  }
  return implode("\n", $parts);
}

/**
 * Mappe un tableau de slugs {key => slug} vers le tableau de labels affichables
 * correspondants {key => label} en lookup sur sapi_guide_get_steps()[].choices[].
 * Permet à sapi_megafilter_build_chat_prompt de réutiliser
 * sapi_megafilter_format_project_text quand le POST ne fournit que les slugs.
 */
function sapi_megafilter_labels_from_slugs(array $filters) {
  if (empty($filters)) return [];
  $steps = function_exists('sapi_guide_get_steps') ? sapi_guide_get_steps() : [];
  $by_step = [];
  foreach ($steps as $step) {
    if (!isset($step['id']) || !isset($step['choices'])) continue;
    $by_step[$step['id']] = $step['choices'];
  }
  $out = [];
  foreach ($filters as $key => $slug) {
    if (!is_string($slug) || $slug === '') continue;
    if (!isset($by_step[$key])) continue;
    foreach ($by_step[$key] as $choice) {
      if (isset($choice['slug'], $choice['label']) && $choice['slug'] === $slug) {
        $out[$key] = $choice['label'];
        break;
      }
    }
  }
  return $out;
}

/**
 * F2b — Modale Conseiller V3 partagée entre /mes-creations/ et fiche produit.
 *
 * Le markup ne change pas selon la page : c'est sapi-modal-conseiller.js qui
 * adapte l'écran à l'ouverture (S0/S1/S2-chat/S3, et bientôt s-product-recap).
 *
 * Hookée sur wp_footer pour éviter de dupliquer le markup dans deux templates.
 * Condition d'activation : is_shop() || is_product() — les scripts associés
 * (sapi-modal-conseiller, sapi-project, sapi-cards-conseiller) sont enqueués
 * en miroir dans la même condition.
 */
function sapi_render_conseiller_modal() {
  if (!class_exists('WooCommerce')) return;
  if (!(is_shop() || is_product())) return;

  $pencil_svg = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M21.174 6.812a1 1 0 0 0-3.986-3.987L3.842 16.174a2 2 0 0 0-.5.83l-1.321 4.352a.5.5 0 0 0 .623.622l4.353-1.32a2 2 0 0 0 .83-.497z"/><path d="m15 5 4 4"/></svg>';
  $arrow_svg  = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><line x1="5" y1="12" x2="19" y2="12"/><polyline points="12 5 19 12 12 19"/></svg>';
  ?>
  <div class="conseiller-modal" data-conseiller-modal hidden>
    <div class="conseiller-card conseiller-card--modal" role="dialog" aria-modal="true" aria-label="<?php esc_attr_e('Conseil de Robin', 'theme-sapi-maison'); ?>" data-modal-card>

      <!-- Round 4 — Bouton close visible toutes tailles, top-right (mockup-11). -->
      <button type="button" class="modal__close" data-action="close" aria-label="<?php esc_attr_e('Fermer', 'theme-sapi-maison'); ?>">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
          <line x1="18" y1="6" x2="6" y2="18"/>
          <line x1="6" y1="6" x2="18" y2="18"/>
        </svg>
      </button>


      <!-- ═══ S0 — Accueil hybride (question pièce + texte libre) ═══════ -->
      <section class="modal__screen" data-screen="s0" hidden>
        <header class="modal__head">
          <div class="conseiller-sig conseiller-sig--v1">
            <span class="conseiller-sig__avatar"><?php echo sapi_image('2026/03/Robin-face-avec-Alice-lhelice.jpg', 'thumbnail', ['alt' => 'Robin, artisan de l\'Atelier Sâpi', 'class' => 'conseiller-sig__img', 'loading' => 'lazy']); ?></span>
            <span class="conseiller-sig__text">
              <span class="conseiller-sig__who">Le conseil de Robin</span>
              <span class="conseiller-sig__hook">Mon regard d'artisan sur ton projet</span>
            </span>
          </div>
          <span class="badge" data-s0-badge>
            <?php echo $pencil_svg; // phpcs:ignore WordPress.Security.EscapeOutput ?>
            <span data-s0-badge-text><?php esc_html_e('Conseil de Robin', 'theme-sapi-maison'); ?></span>
          </span>
        </header>

        <div class="modal__body">
          <div class="modal__body-content">
            <h2 class="h2" data-s0-question aria-live="polite">…</h2>

            <div class="choices" data-s0-choices></div>

            <div class="separator-or" aria-hidden="true">
              <span class="separator-or__text"><?php esc_html_e('ou', 'theme-sapi-maison'); ?></span>
            </div>

            <form class="text-input-wrap" data-s0-form>
              <input type="text" class="text-input" data-s0-input
                     placeholder="<?php esc_attr_e('Décris ton projet en quelques mots…', 'theme-sapi-maison'); ?>"
                     maxlength="500"
                     aria-label="<?php esc_attr_e('Décris ton projet en quelques mots', 'theme-sapi-maison'); ?>">
              <button type="submit" class="text-submit" aria-label="<?php esc_attr_e('Envoyer', 'theme-sapi-maison'); ?>">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M5 12h14M13 5l7 7-7 7"/></svg>
              </button>
            </form>
          </div>
        </div>

        <footer class="modal__foot">
          <span style="font-size:11.5px;color:var(--color-wood-mid);font-style:italic;text-align:center;" data-s0-reassure>
            <?php esc_html_e('Robin t\'aide à choisir, sans engagement', 'theme-sapi-maison'); ?>
          </span>
          <button type="button" class="footer-link" data-action="s0-reset" data-s0-reset-wrap hidden>
            <?php esc_html_e('Effacer et recommencer', 'theme-sapi-maison'); ?>
          </button>
        </footer>
      </section>

      <!-- ═══ S1 — Questions guidées (progress sticky bottom du body) ═══════ -->
      <section class="modal__screen" data-screen="s1" hidden>
        <header class="modal__head">
          <div class="conseiller-sig conseiller-sig--v1">
            <span class="conseiller-sig__avatar"><?php echo sapi_image('2026/03/Robin-face-avec-Alice-lhelice.jpg', 'thumbnail', ['alt' => 'Robin, artisan de l\'Atelier Sâpi', 'class' => 'conseiller-sig__img', 'loading' => 'lazy']); ?></span>
            <span class="conseiller-sig__text"><span class="conseiller-sig__who">Le conseil de Robin</span><span class="conseiller-sig__hook">On affine ton projet</span></span>
          </div>
          <span class="badge">
            <?php echo $pencil_svg; // phpcs:ignore WordPress.Security.EscapeOutput ?>
            <?php esc_html_e('Conseil de Robin', 'theme-sapi-maison'); ?>
          </span>
        </header>

        <div class="modal__body">
          <div class="modal__body-content">
            <h2 class="h2" data-question-title aria-live="polite">…</h2>
            <div class="choices" data-choices></div>
          </div>
          <div class="progress" aria-hidden="true">
            <div class="progress__bar" data-progress-fill style="width: 0%"></div>
          </div>
        </div>

        <footer class="modal__foot">
          <button type="button" class="footer-link" data-action="back">← <?php esc_html_e('Étape précédente', 'theme-sapi-maison'); ?></button>
        </footer>
      </section>

      <!-- ═══ S2-chat — Conversation libre avec Robin (chat bubbles + input) ═══ -->
      <section class="modal__screen" data-screen="s2-chat" hidden>
        <header class="modal__head">
          <div class="conseiller-sig conseiller-sig--v1">
            <span class="conseiller-sig__avatar"><?php echo sapi_image('2026/03/Robin-face-avec-Alice-lhelice.jpg', 'thumbnail', ['alt' => 'Robin, artisan de l\'Atelier Sâpi', 'class' => 'conseiller-sig__img', 'loading' => 'lazy']); ?></span>
            <span class="conseiller-sig__text">
              <span class="conseiller-sig__who">Le conseil de Robin</span>
              <span class="conseiller-sig__hook">Discutons de ton projet</span>
            </span>
          </div>
          <span class="badge">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M8 12h.01M12 12h.01M16 12h.01M21 12c0 4.418-4.03 8-9 8-1.476 0-2.866-.317-4.083-.876L3 21l1.876-4.917A7.997 7.997 0 0 1 3 12c0-4.418 4.03-8 9-8s9 3.582 9 8z"/></svg>
            <?php esc_html_e('Échange avec Robin', 'theme-sapi-maison'); ?>
          </span>
        </header>

        <div class="modal__body">
          <div class="modal__body-content" style="justify-content: flex-start;">
            <div class="chat-bubbles" data-chat-messages></div>
            <form class="text-input-wrap" data-chat-form>
              <input type="text" class="text-input" data-chat-input
                     placeholder="<?php esc_attr_e('Ta réponse…', 'theme-sapi-maison'); ?>"
                     maxlength="1000"
                     aria-label="<?php esc_attr_e('Message', 'theme-sapi-maison'); ?>">
              <button type="submit" class="text-submit" aria-label="<?php esc_attr_e('Envoyer', 'theme-sapi-maison'); ?>">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M5 12h14M13 5l7 7-7 7"/></svg>
              </button>
            </form>
          </div>
        </div>

        <?php /* ⚠️ DEUX BOUTONS DANS LE MARKUP, MAIS JAMAIS LES DEUX À L'ÉCRAN.
                 `revealChatCta()` en montre exactement un, selon qu'on connaît
                 la pièce ou non — la logique est là-bas, et nulle part ailleurs.
                 Pièce connue → « Voir la sélection » seul. Pièce inconnue en
                 fin de conversation → « En parler à Robin » seul, promu en
                 bouton plein. Pièce inconnue en cours de conversation → cette
                 barre est masquée entièrement : on ne montre pas la sortie à
                 quelqu'un à qui l'IA vient de poser une question.
                 Le libellé du premier est FIXE : la variante « Voir les
                 appliques » a existé une journée, elle est morte avec la
                 décision de renvoyer vers Robin les pièces hors périmètre. */ ?>
        <footer class="modal__foot modal__foot--duo" data-chat-cta hidden>
          <button type="button" class="action-btn action-btn--primary" data-action="apply" data-chat-cta-primary>
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M3 12h18M13 5l7 7-7 7"/></svg>
            <span data-chat-cta-label><?php esc_html_e('Voir la sélection pour mon projet', 'theme-sapi-maison'); ?></span>
          </button>
          <button type="button" class="action-btn action-btn--ghost" data-action="chat-contact" data-chat-cta-contact hidden>
            <?php esc_html_e('En parler à Robin', 'theme-sapi-maison'); ?>
          </button>
        </footer>
      </section>

      <!-- ═══ S-product-recap — Récap fiche produit (essence + taille + conseils) ═══ -->
      <section class="modal__screen" data-screen="s-product-recap" hidden>
        <header class="modal__head">
          <div class="conseiller-sig conseiller-sig--v1">
            <span class="conseiller-sig__avatar"><?php echo sapi_image('2026/03/Robin-face-avec-Alice-lhelice.jpg', 'thumbnail', ['alt' => 'Robin, artisan de l\'Atelier Sâpi', 'class' => 'conseiller-sig__img', 'loading' => 'lazy']); ?></span>
            <span class="conseiller-sig__text"><span class="conseiller-sig__who">Le conseil de Robin</span><span class="conseiller-sig__hook">Mon conseil pour toi</span></span>
          </div>
          <span class="badge">
            <?php echo $pencil_svg; // phpcs:ignore WordPress.Security.EscapeOutput ?>
            <?php esc_html_e('Mon conseil pour toi', 'theme-sapi-maison'); ?>
          </span>
        </header>

        <div class="modal__body">
          <div class="modal__body-content">
            <?php /* ⚠️ DEUX BLOCS, DEUX NATURES, ET LE PIÈGE EST LE MOT « TAILLE ».
                     En haut ce que le VISITEUR a dit — dont « Taille » qualifie
                     sa PIÈCE. En bas ce que Robin recommande sur CE modèle —
                     dont « Taille » qualifie le LUMINAIRE, en centimètres.
                     Empilés sans distinction, l'écran afficherait « Taille :
                     Grand » puis « Taille : 90 cm » et le visiteur croirait
                     qu'on lui parle du même objet. D'où les deux libellés
                     explicites ET les deux formes visuelles : pastilles à plat
                     pour le projet, encart blanc pour la recommandation.
                     La phrase d'intro « Pour ton bureau, Robin recommande : » a
                     été retirée : la pastille dit la pièce mieux qu'elle, et sa
                     place finance les pastilles sur mobile. */ ?>
            <span class="chips-label" data-product-recap-project-label hidden><?php esc_html_e('Ton projet', 'theme-sapi-maison'); ?></span>
            <div class="recap-group__chips" data-product-recap-project hidden></div>

            <span class="chips-label" data-product-recap-reco-label hidden><?php esc_html_e('Ce que je te recommande sur ce modèle', 'theme-sapi-maison'); ?></span>
            <?php /* ⚠️ CHAQUE EXPLICATION EST DANS LA CARTE, SOUS SA PROPRE LIGNE.
                     Les deux phrases flottaient auparavant sous l'encart, à la
                     suite l'une de l'autre : rien ne disait laquelle expliquait
                     l'essence et laquelle expliquait la taille. Elles occupent
                     maintenant une ligne pleine largeur juste après la valeur
                     qu'elles commentent, et elles se masquent avec elle.
                     Demande de Robin en recette. */ ?>
            <div class="recap-card" data-product-recap-card hidden>
              <span class="recap-card__item-label" data-product-recap-essence hidden><?php esc_html_e('Essence', 'theme-sapi-maison'); ?></span>
              <span class="recap-card__item-value" data-product-recap-essence-value></span>
              <p class="recap-card__note" data-product-recap-conseil hidden></p>
              <span class="recap-card__item-label" data-product-recap-taille hidden><?php esc_html_e('Taille du luminaire', 'theme-sapi-maison'); ?></span>
              <span class="recap-card__item-value" data-product-recap-taille-value></span>
              <p class="recap-card__note" data-product-recap-conseil-taille hidden></p>
            </div>

            <div class="actions-3">
              <button type="button" class="action-btn action-btn--primary" data-action="product-apply">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M5 13l4 4L19 7"/></svg>
                <?php esc_html_e('Appliquer cette sélection', 'theme-sapi-maison'); ?>
              </button>
              <button type="button" class="action-btn action-btn--secondary" data-action="product-modify">
                <?php /* ⚠️ Libellé corrigé : ce bouton EFFACE tout le projet et
                         repart de la première question (`modifyProductAnswers`).
                         « Modifier » laissait attendre une retouche. */ ?>
                <?php esc_html_e('Recommencer mon projet', 'theme-sapi-maison'); ?>
              </button>
            </div>
          </div>
        </div>

        <footer class="modal__foot">
          <a class="footer-link" href="<?php echo esc_url(home_url('/contact/')); ?>">
            <?php esc_html_e('Contacter Robin', 'theme-sapi-maison'); ?>
          </a>
        </footer>
      </section>

      <!-- ═══ S-contact — Mini-form contact intégré (projet pro / sur-mesure) ═══ -->
      <section class="modal__screen" data-screen="s-contact" hidden>

        <!-- État form (défaut) -->
        <div data-contact-state="form">
          <header class="modal__head">
            <div class="conseiller-sig conseiller-sig--v1">
              <span class="conseiller-sig__avatar"><?php echo sapi_image('2026/03/Robin-face-avec-Alice-lhelice.jpg', 'thumbnail', ['alt' => 'Robin, artisan de l\'Atelier Sâpi', 'class' => 'conseiller-sig__img', 'loading' => 'lazy']); ?></span>
              <span class="conseiller-sig__text"><span class="conseiller-sig__who">Le conseil de Robin</span><span class="conseiller-sig__hook">Échangeons ensemble</span></span>
            </div>
            <span class="badge">
              <?php echo $pencil_svg; // phpcs:ignore WordPress.Security.EscapeOutput ?>
              <span data-contact-badge-text><?php esc_html_e('Échangeons ensemble', 'theme-sapi-maison'); ?></span>
            </span>
          </header>

          <div class="modal__body">
            <div class="modal__body-content">
              <p class="quote" data-contact-message></p>
              <div data-contact-recap></div>

              <form class="contact-form" data-contact-form novalidate>
                <input type="email" name="email" required
                       placeholder="<?php esc_attr_e('Ton email', 'theme-sapi-maison'); ?>"
                       aria-label="<?php esc_attr_e('Ton email', 'theme-sapi-maison'); ?>">
                <textarea name="description" rows="5"
                          placeholder="<?php esc_attr_e('Décris ton projet…', 'theme-sapi-maison'); ?>"
                          aria-label="<?php esc_attr_e('Ton message', 'theme-sapi-maison'); ?>"
                          data-contact-message-field></textarea>
                <input type="text" name="website" tabindex="-1" autocomplete="off" aria-hidden="true" style="position:absolute;left:-10000px;width:1px;height:1px;">
                <button type="submit" data-contact-submit>
                  <?php esc_html_e('Envoyer ma demande', 'theme-sapi-maison'); ?>
                  <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M5 12h14M13 5l7 7-7 7"/></svg>
                </button>
                <p class="contact-reassure"><?php esc_html_e('Réponse de Robin sous 48h · Aucun engagement', 'theme-sapi-maison'); ?></p>
                <p class="contact-disclaimer"><?php esc_html_e('En envoyant ta demande, tu acceptes de recevoir occasionnellement des nouvelles de l\'Atelier Sâpi. Désinscription possible à tout moment.', 'theme-sapi-maison'); ?></p>
              </form>
            </div>
          </div>

          <footer class="modal__foot">
            <button type="button" class="footer-link" data-action="back-to-chat">
              ← <?php esc_html_e('Reprendre la discussion', 'theme-sapi-maison'); ?>
            </button>
          </footer>
        </div>

        <!-- État succès -->
        <div data-contact-state="success" hidden>
          <header class="modal__head">
            <div class="conseiller-sig conseiller-sig--v1">
              <span class="conseiller-sig__avatar"><?php echo sapi_image('2026/03/Robin-face-avec-Alice-lhelice.jpg', 'thumbnail', ['alt' => 'Robin, artisan de l\'Atelier Sâpi', 'class' => 'conseiller-sig__img', 'loading' => 'lazy']); ?></span>
              <span class="conseiller-sig__text"><span class="conseiller-sig__who">Le conseil de Robin</span><span class="conseiller-sig__hook">Merci, à très vite</span></span>
            </div>
            <span class="badge">
              <?php echo $pencil_svg; // phpcs:ignore WordPress.Security.EscapeOutput ?>
              <?php esc_html_e('Demande envoyée', 'theme-sapi-maison'); ?>
            </span>
          </header>
          <div class="modal__body">
            <div class="modal__body-content">
              <?php /* ⚠️ NE PAS REPROMETTRE D'ACCUSÉ DE RÉCEPTION.
                       Cet écran annonçait « tu vas recevoir un email de
                       confirmation ». Il n'en existe aucun : l'endpoint
                       n'envoie qu'UN seul mail, à Robin. Le visiteur
                       surveillait sa boîte pour rien, et certains renvoyaient
                       le formulaire — les doublons arrivaient chez Robin.
                       Décision Robin du 26/08 : retirer la promesse plutôt que
                       d'écrire le mail. Si l'accusé est créé un jour, c'est
                       ici qu'on pourra le réannoncer, et pas avant.
                       Tirets cadratins retirés au passage (règle de marque). */ ?>
              <h2 class="h2"><?php esc_html_e('Reçu, Robin t\'écrit sous 48h', 'theme-sapi-maison'); ?></h2>
              <p class="subtitle"><?php esc_html_e('Merci pour ta demande. Robin te répondra personnellement à l\'adresse que tu viens de laisser.', 'theme-sapi-maison'); ?></p>
            </div>
          </div>
          <footer class="modal__foot">
            <button type="button" class="action-btn action-btn--secondary" data-action="close">
              <?php esc_html_e('Fermer', 'theme-sapi-maison'); ?>
            </button>
          </footer>
        </div>

      </section>

      <!-- ═══ S3 — Carrefour récap "Ton projet" + 3 actions ═══════ -->
      <section class="modal__screen" data-screen="s3" hidden>
        <header class="modal__head">
          <div class="conseiller-sig conseiller-sig--v1">
            <span class="conseiller-sig__avatar"><?php echo sapi_image('2026/03/Robin-face-avec-Alice-lhelice.jpg', 'thumbnail', ['alt' => 'Robin, artisan de l\'Atelier Sâpi', 'class' => 'conseiller-sig__img', 'loading' => 'lazy']); ?></span>
            <span class="conseiller-sig__text"><span class="conseiller-sig__who">Le conseil de Robin</span><span class="conseiller-sig__hook">On récapitule ?</span></span>
          </div>
          <span class="badge">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M9 12l2 2 4-4"/></svg>
            <?php esc_html_e('Ton projet', 'theme-sapi-maison'); ?>
          </span>
        </header>

        <div class="modal__body">
          <div class="modal__body-content">
            <h2 class="h2"><?php esc_html_e('Voici ton projet', 'theme-sapi-maison'); ?></h2>

            <div class="recap-groups" data-recap-chips></div>

            <p class="recap-hint">
              <?php esc_html_e('Tu peux modifier n\'importe quelle réponse en cliquant sur une chip', 'theme-sapi-maison'); ?>
            </p>

            <div class="actions-3">
              <button type="button" class="action-btn action-btn--primary" data-action="s3-view">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M3 12h18M13 5l7 7-7 7"/></svg>
                <?php esc_html_e('Voir la sélection pour mon projet', 'theme-sapi-maison'); ?>
              </button>
              <button type="button" class="action-btn action-btn--secondary" data-action="s3-refine">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M9 9h.01M15 9h.01M9 15c1 1 2 1.5 3 1.5s2-.5 3-1.5"/></svg>
                <?php esc_html_e('Préciser avec Robin', 'theme-sapi-maison'); ?>
              </button>
              <button type="button" class="footer-link" data-action="s3-reset" style="margin-top: 4px;">
                ↻ <?php esc_html_e('Recommencer', 'theme-sapi-maison'); ?>
              </button>
            </div>
          </div>
        </div>
      </section>

    </div><!-- /.conseiller-card--modal -->
  </div>
  <?php
}
add_action('wp_footer', 'sapi_render_conseiller_modal');

/* ── Helpers du contrat IA : catalogue présenté / écarté, contraintes relâchées
   ─────────────────────────────────────────────────────────────────────────────
   Utilisés par sapi_ajax_megafilter_advice ET sapi_ajax_megafilter_chat.
   ⚠️ Ces sections sont construites À PARTIR DU MOTEUR, plus à partir de ce que
   le navigateur envoie : les produits correspondants sont recalculés côté
   serveur, et les contraintes relâchées viennent de `fallback_notes`. Deux
   fonctions qui lisaient `$_POST` ont été retirées pour cette raison — elles
   recevaient toujours des tableaux vides depuis la suppression de l'objet JS
   qui les remplissait.
   ───────────────────────────────────────────────────────────────────────────── */

// Construit les 2 sections "PRODUITS PRÉSENTÉS" + "PRODUITS ÉCARTÉS" depuis un
// $all_products (sapi_guide_query_all_products) + les matching_ids.
// Round 2 — 4.2 : enrichi avec essences disponibles + prix dès, lus depuis
// $p['variations'] (essence) et $p['price_min_raw'] (déjà calculés dans
// sapi_guide_collect_results) — l'IA peut désormais parler de matière et de
// prix sans inventer.
function sapi_megafilter_format_catalog_split(array $all_products, array $matching_ids) {
  $matching_set = array_flip(array_map('intval', $matching_ids));
  $presented = [];
  $ecarted = [];
  foreach ($all_products as $p) {
    $id = isset($p['id']) ? intval($p['id']) : 0;
    if ($id <= 0) continue;
    $cats = isset($p['categories']) && is_array($p['categories']) ? implode(', ', $p['categories']) : '';
    $format = isset($p['format']) ? $p['format'] : '';
    $ampoule = isset($p['type_ampoule']) ? $p['type_ampoule'] : '';

    // Essences uniques depuis les variations (typiquement Peuplier, Okoumé)
    $essences_uniques = [];
    if (!empty($p['variations']) && is_array($p['variations'])) {
      foreach ($p['variations'] as $v) {
        if (!empty($v['essence']) && !in_array($v['essence'], $essences_uniques, true)) {
          $essences_uniques[] = $v['essence'];
        }
      }
    }
    $essences = empty($essences_uniques) ? '?' : implode(', ', $essences_uniques);

    // Prix dès — utilise price_min_raw (float) pour formater proprement
    $prix = '?';
    if (isset($p['price_min_raw']) && $p['price_min_raw'] > 0) {
      $prix = number_format((float) $p['price_min_raw'], 0, ',', ' ') . '€';
    }

    $line = '- ' . (isset($p['title']) ? $p['title'] : '?')
          . ' | Catégorie : ' . $cats
          . ' | Format : ' . $format
          . ' | Ampoule : ' . $ampoule
          . ' | Essences : ' . $essences
          . ' | Prix dès : ' . $prix;
    if (isset($matching_set[$id])) $presented[] = $line;
    else                            $ecarted[] = $line;
  }

  $out = "\nPRODUITS PRÉSENTÉS AU VISITEUR APRÈS FILTRAGE (" . count($presented) . ") :\n";
  $out .= empty($presented) ? "(aucun)\n" : implode("\n", $presented) . "\n";

  if (!empty($ecarted)) {
    $out .= "\nPRODUITS ÉCARTÉS PAR LE FILTRE (" . count($ecarted) . ") — non visibles par le visiteur :\n";
    $out .= implode("\n", $ecarted) . "\n";
  }
  return $out;
}

/**
 * Les relâchements de contrainte, dits à l'IA — depuis ce que le MOTEUR a fait.
 *
 * ⚠️ POURQUOI CETTE FONCTION REMPLACE `format_ignored_answers()`.
 * L'ancienne attendait la liste des critères relâchés **du navigateur**, via
 * `$_POST['ignored_answers']`. Or l'objet JS qui la calculait a été supprimé
 * lors d'une refonte : le client envoyait donc **toujours un tableau vide**, et
 * le bloc n'apparaissait dans AUCUN prompt.
 *
 * Conséquence, tous les jours et sans trace : quand le moteur ne trouvait rien
 * avec le bon type d'ampoule, il relâchait la contrainte ET écrivait une note
 * explicite — « signale-le honnêtement au client comme un compromis, ne dis pas
 * que c'est le choix idéal ». Cette consigne ne parvenait jamais au modèle.
 * **Robin présentait un compromis comme un idéal.**
 *
 * Le moteur produit ces notes lui-même (`sapi_guide_query_products`), déjà
 * rédigées pour l'IA. On les lui transmet telles quelles : le serveur cesse de
 * demander au navigateur ce qu'il sait déjà — même discipline que pour les
 * produits correspondants, recalculés côté serveur pour la même raison.
 */
function sapi_megafilter_format_fallback_notes($notes) {
  if (!is_array($notes) || empty($notes)) return '';
  $lignes = [];
  foreach ($notes as $n) {
    if (is_string($n) && trim($n) !== '') $lignes[] = '- ' . trim($n);
  }
  if (empty($lignes)) return '';
  return "\nCONTRAINTES RELÂCHÉES POUR TROUVER DES MODÈLES :\n"
       . implode("\n", $lignes) . "\n";
}

// Round 3.1 — Fix 1 : ambiance lumineuse = catalogue présenté est la source
// de vérité (pas les règles théoriques de savoir.txt). Inséré dans les
// prompts chat + advice juste après le catalogue split.
function sapi_megafilter_lighting_ambiance_block() {
  $out  = "\nDESCRIPTION DE L'AMBIANCE LUMINEUSE — règle stricte :\n\n";
  $out .= "Si tu décris l'effet lumineux ou l'ambiance des modèles présentés, tu DOIS te baser sur le\n";
  $out .= "type d'ampoule des modèles PRÉSENTÉS (colonne \"Ampoule\" dans le catalogue ci-dessus), PAS\n";
  $out .= "sur les règles générales du savoir métier.\n\n";
  $out .= "Mapping ampoule → vocabulaire à utiliser :\n";
  $out .= "- ampoule_degagee   → \"lumière directe\", \"ampoule visible\", \"éclat franc\",\n";
  $out .= "                      \"lumière qui descend franchement\", \"sans zone d'ombre\"\n";
  $out .= "- semi_degagee      → \"lumière mi-tamisée\", \"diffusion mesurée\"\n";
  $out .= "- ampoule_entouree  → \"lumière diffuse\", \"douceur tamisée\", \"ombres décoratives\",\n";
  $out .= "                      \"lumière filtrée par le bois\", \"ambiance cocon\"\n\n";
  $out .= "Si la majorité du catalogue présenté est \"ampoule_entouree\", tu décris une ambiance douce\n";
  $out .= "et diffuse — JAMAIS \"ampoule à découvert\" ou \"lumière franche\", même si la pièce est une\n";
  $out .= "cuisine ou un bureau. Le catalogue présenté est la source de vérité, les règles théoriques\n";
  $out .= "du savoir métier sont secondaires quand il y a conflit avec le catalogue concret.\n\n";
  $out .= "Si le catalogue présenté contient un mélange de types d'ampoule, reste plus générique : ne\n";
  $out .= "décris pas l'effet lumineux précis.\n";
  return $out;
}

// Bloc consigne adaptative à ajouter au system prompt advice + chat.
// Round 3 — Lot A : réécriture complète. L'IA ne révèle JAMAIS le mécanisme
// interne de filtrage au visiteur. Présentation comme proposition d'artisan,
// sur-mesure comme porte de sortie naturelle (jamais aveu d'échec).
function sapi_megafilter_adaptive_consigne_block() {
  $out  = "\nPRÉSENTATION DE LA SÉLECTION AU VISITEUR :\n";
  $out .= "- Si AUCUN produit présenté au visiteur (liste vide) : propose chaleureusement le sur-mesure (Robin peut créer un modèle qui n'existe pas dans le catalogue), sans baratin, sans promesse de modèles imaginaires.\n";
  $out .= "- Si la sélection présentée correspond EXACTEMENT à la demande de départ : présente la sélection naturellement.\n";
  $out .= "- Si la sélection s'écarte de la demande de départ : DIS-LE, simplement et sans t'excuser. Le visiteur a le droit de savoir que ce qu'il voit n'est pas exactement ce qu'il a demandé — c'est ce qui fait la différence entre un conseil et un argumentaire.\n";
  $out .= "  Formule-le en artisan, jamais en machine : \"je n'ai pas exactement ça, voilà ce qui s'en approche le plus\". Puis présente la sélection, et propose le sur-mesure comme suite naturelle.\n";
  $out .= "  ⚠️ DIRE L'ÉCART N'EST PAS DIRE LE MÉCANISME. Tu reconnais que la sélection s'éloigne de la demande ; tu n'expliques JAMAIS comment le catalogue a été parcouru pour y arriver. Voir le vocabulaire interdit ci-dessous : il reste interdit dans tous les cas.\n";
  $out .= "  Ce que le visiteur ne doit JAMAIS lire, c'est une sélection approchante présentée comme un choix idéal. C'est la seule chose qui abîme la confiance.\n";

  $out .= "\nVOCABULAIRE STRICTEMENT INTERDIT — ne le mentionne JAMAIS au visiteur :\n";
  $out .= "- \"j'ai élargi\", \"j'ai relâché\", \"j'ai mis de côté\", \"j'ai assoupli\", \"j'ai été plus large sur…\", \"j'ai un peu débordé sur d'autres pièces\"\n";
  $out .= "- \"comme je n'avais pas grand-chose à te montrer\", \"sinon je n'avais que 2-3 modèles\"\n";
  $out .= "- \"contrainte\", \"paramètre\", \"préférence\", \"filtre\", \"critère\", \"sélection élargie\", \"élargissement\"\n";
  $out .= "Le visiteur ne sait pas comment fonctionne le filtre en interne, et n'a pas à le savoir.\n";
  $out .= "⚠️ CETTE INTERDICTION PORTE SUR LE VOCABULAIRE, PAS SUR L'HONNÊTETÉ. Tu peux et tu dois dire qu'une sélection s'approche sans correspondre exactement — avec des mots d'artisan (\"je n'ai pas exactement ça\", \"voilà ce qui s'en rapproche le plus\"), jamais avec des mots de machine.\n";

  $out .= "\nEXEMPLES CANONIQUES (le ton, pas le texte exact à recopier — voix d'assistant qui parle de Robin à la 3e personne) :\n";
  $out .= "- \"Tu cherches plutôt du moderne pour ta cuisine. Voici la sélection que je te propose dans le catalogue de Robin — si tu ne trouves pas exactement ce que tu imaginais, Robin peut aussi imaginer quelque chose de sur-mesure avec toi.\"\n";
  $out .= "- \"Voici ma proposition pour ton salon. Pense à vérifier les dimensions sur chaque fiche pour être sûr du rendu — et n'hésite pas à demander à Robin si tu veux en parler directement.\"\n";
  $out .= "- \"Voici ce que je te propose dans la collection de Robin. Si tu cherches quelque chose de très précis qui ne figure pas dans ces modèles, Robin peut imaginer du sur-mesure avec toi.\"\n";
  $out .= "- \"Voici ma sélection. Si tu as besoin de quelque chose de très spécifique pour ton projet, Robin peut concevoir du sur-mesure — il suffit que vous échangiez ensemble.\"\n";
  $out .= "- (écart assumé) \"Je n'ai pas exactement ce que tu cherches pour ta cuisine, mais voilà ce qui s'en rapproche le plus dans le catalogue de Robin. Et s'il te faut vraiment autre chose, il peut le concevoir avec toi.\"\n";

  $out .= "\nCONTENU DE LA PHRASE :\n";
  $out .= "- N'ÉNUMÈRE PAS chaque réponse du projet. Va à l'essentiel.\n";
  $out .= "- Si le style est \"Pas de préférence\" (ou \"neutre\"), NE LE MENTIONNE PAS du tout — ce n'est pas une info.\n";
  $out .= "- Évite les tournures qui confondent une caractéristique de la PIÈCE avec une RÉPONSE du visiteur. Exemple à NE PAS faire : \"ta cuisine est au mur\" (la cuisine n'est PAS au mur — c'est l'arrivée électrique qui est au mur, ce qui détermine le type de produit côté filtre).\n";
  $out .= "- Dans TOUS les cas : NE NOMME PAS de modèle précis du catalogue — le visiteur les voit dans la grille juste après.\n";
  $out .= "- Le sur-mesure est ta porte de sortie naturelle quand la sélection s'écarte de la demande initiale. JAMAIS comme un aveu d'échec, toujours comme une alternative que tu peux proposer.\n";
  $out .= "- Si la section CONTRAINTES RELÂCHÉES apparaît plus bas, c'est que le catalogue ne contenait rien d'exact : dans ce cas, dire l'écart n'est pas optionnel.\n";

  return $out;
}

/* ── Endpoint F2a-bis : phrase IA conseillère unique, appelée à la sortie modale
   ─────────────────────────────────────────────────────────────────────────────
   - Modèle Sonnet (qualité du ton, sortie unique → on peut se permettre le coût)
   - Input :  answers + labels + matching_product_ids + ignored_answers
              (+ conversation optionnel en sortie de S2)
   - Output : { advice_text: "..." }
   - Pas de cache serveur — chaque parcours est unique
   - Fallback : texte générique correspondant à la pièce
   ───────────────────────────────────────────────────────────────────────────── */
add_action('wp_ajax_sapi_megafilter_advice', 'sapi_ajax_megafilter_advice');
add_action('wp_ajax_nopriv_sapi_megafilter_advice', 'sapi_ajax_megafilter_advice');

function sapi_ajax_megafilter_advice() {
  if (!isset($_POST['nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['nonce'])), 'sapi-megafilter')) {
    wp_send_json_error(['message' => 'Nonce invalide']);
    return;
  }

  $answers_raw = isset($_POST['answers']) ? json_decode(wp_unslash($_POST['answers']), true) : [];
  $labels_raw  = isset($_POST['labels'])  ? json_decode(wp_unslash($_POST['labels']),  true) : [];
  list($answers, $labels) = sapi_megafilter_sanitize_project($answers_raw, $labels_raw);

  $piece = isset($answers['piece']) ? $answers['piece'] : '';

  // Fallback si projet sans pièce
  if (empty($answers) || empty($piece)) {
    wp_send_json_success([
      'advice_text' => sapi_megafilter_generic_advice_for($piece),
    ]);
    return;
  }

  // Rate-limit : on tombe sur le texte générique de la pièce (pas d'erreur visible)
  if (!sapi_guide_check_rate_limit()) {
    wp_send_json_success([
      'advice_text' => sapi_megafilter_generic_advice_for($piece),
    ]);
    return;
  }

  // Conversation optionnelle (présente en sortie de S2 mode texte libre)
  $conversation_raw = isset($_POST['conversation']) ? json_decode(wp_unslash($_POST['conversation']), true) : [];
  $conversation_block = '';
  if (is_array($conversation_raw) && !empty($conversation_raw)) {
    $lines = [];
    foreach ($conversation_raw as $msg) {
      if (!isset($msg['role'], $msg['content'])) continue;
      $role = ($msg['role'] === 'assistant') ? 'Robin' : 'Visiteur';
      $lines[] = $role . ' : ' . sanitize_textarea_field($msg['content']);
    }
    if (!empty($lines)) {
      $conversation_block = "\n\nÉCHANGES avec le visiteur :\n" . implode("\n", $lines);
    }
  }

  $project_text = sapi_megafilter_format_project_text($answers, $labels);

  // Contexte filtre : quels produits sont présentés au visiteur. Refonte
  // (filtrage 100% serveur) : le JS ne calcule PLUS la sélection, donc on la
  // détermine ICI via le moteur réel (mêmes règles que l'immersion / l'aperçu
  // admin). Sinon matching_ids serait vide → l'IA croirait qu'aucun modèle ne
  // correspond et pousserait le sur-mesure à tort.
  $adv_cats     = function_exists('sapi_guide_get_categories') ? sapi_guide_get_categories($answers) : [];
  $adv_res      = function_exists('sapi_guide_query_products')  ? sapi_guide_query_products($answers, $adv_cats) : ['products' => []];
  $adv_products = isset($adv_res['products']) ? $adv_res['products'] : [];
  if (function_exists('sapi_conseiller_rank_products')) {
    $adv_products = sapi_conseiller_rank_products($adv_products, $answers);
  }
  $matching_ids = array_values(array_filter(array_map(function ($p) {
    return isset($p['id']) ? (int) $p['id'] : 0;
  }, $adv_products)));
  // Les relâchements viennent du MOTEUR, plus du navigateur — cf. la fonction.
  $relachements = isset($adv_res['fallback_notes']) ? $adv_res['fallback_notes'] : [];
  $all_products = sapi_guide_query_all_products([]);
  $catalog_split_block  = sapi_megafilter_format_catalog_split($all_products, $matching_ids);
  $ignored_answers_block = sapi_megafilter_format_fallback_notes($relachements);

  // Injecte ton + savoir + règles V2 en tête, SANS les exemples : la sortie
  // est un JSON court d'une à deux phrases, pas une conversation.
  $system_prompt  = sapi_megafilter_load_v2_prompts(false);

  $system_prompt .= "Tu es l'assistant de Robin, l'artisan menuisier lyonnais qui fabrique des luminaires en bois à la découpe laser dans son atelier de Lyon.\n";
  $system_prompt .= "Un visiteur vient de te décrire son projet (via questionnaire ou conversation libre). Tu lui présentes la sélection que tu as faite pour lui dans le catalogue de Robin, en 1-2 phrases personnalisées.\n\n";
  $system_prompt .= "TON :\n";
  $system_prompt .= "- Tutoiement, chaleureux, passionné par le travail de Robin, jamais vendeur\n";
  $system_prompt .= "- Évoque concrètement ce que tu as compris du projet et pourquoi la sélection lui correspond\n";
  $system_prompt .= "- Tu peux mentionner une essence de bois, un format, une ambiance — mais PAS de modèle précis (le visiteur va les voir juste après)\n";
  $system_prompt .= "- Parle de Robin à la 3e personne (\"Robin a conçu\", \"son atelier\", \"ses créations\"). N'utilise PAS \"je conçois\", \"mon atelier\", \"mes créations\".\n";
  $system_prompt .= "- Pas d'emoji, pas de markdown, pas de signature (elle est ajoutée séparément côté front)\n";
  $system_prompt .= "- Format : 1 à 2 phrases, max 300 caractères\n\n";

  $system_prompt .= sapi_megafilter_adaptive_consigne_block();

  $system_prompt .= "\nFORMAT DE RÉPONSE (JSON strict, sans markdown) :\n";
  $system_prompt .= "{ \"advice_text\": \"...\" }\n\n";

  $system_prompt .= "⚠️ FORMAT DE SORTIE — IMPÉRATIF :\n";
  $system_prompt .= "Ta réponse DOIT être UNIQUEMENT le JSON ci-dessus. RIEN d'autre :\n";
  $system_prompt .= "- PAS de prose avant le JSON\n";
  $system_prompt .= "- PAS de prose après le JSON\n";
  $system_prompt .= "- PAS de bloc ```markdown autour\n";
  $system_prompt .= "Premier caractère = `{`, dernier caractère = `}`. Point.\n";

  $user_msg  = "PROJET DU VISITEUR :\n" . $project_text;
  $user_msg .= $ignored_answers_block;
  $user_msg .= $catalog_split_block;
  // Round 3.1 — Fix 1 : ambiance lumineuse alignée sur le catalogue présenté.
  $user_msg .= sapi_megafilter_lighting_ambiance_block();
  $user_msg .= $conversation_block;

  $ai_text = sapi_megafilter_call_claude(
    'claude-sonnet-4-6',
    $system_prompt,
    [['role' => 'user', 'content' => $user_msg]],
    512
  );

  $advice = '';
  if ($ai_text) {
    $parsed = sapi_megafilter_parse_json($ai_text);
    if ($parsed && isset($parsed['advice_text']) && is_string($parsed['advice_text'])) {
      /* La borne s'applique APRÈS le nettoyage des tirets : celui-ci remplace
         « — » par « , », donc il peut allonger le texte d'un caractère par
         tiret. Borner avant laisserait repasser au-dessus de la limite. */
      $advice = sapi_borner_conseil(sapi_strip_long_dashes(sanitize_textarea_field($parsed['advice_text'])));
    }
  }

  // Fallback gracieux si IA plante ou format inattendu
  if (empty($advice)) {
    $advice = sapi_megafilter_generic_advice_for($piece);
  }

  wp_send_json_success(['advice_text' => $advice]);
}

/* ── F2b Phase 2 : conseils de style pré-générés pour le récap fiche produit
   ─────────────────────────────────────────────────────────────────────────────
   Pattern d'origine pré-F1c (assets/guide-conseils.json → pg_style:*) : textes
   fixes affichés immédiatement, ZÉRO appel IA. La modale produit reproduit
   à l'identique ce comportement statique.
   ───────────────────────────────────────────────────────────────────────────── */

function sapi_megafilter_get_style_conseils() {
  return [
    'moderne' => __("Le Peuplier, clair et lumineux, s'accordera parfaitement avec ton intérieur moderne.", 'theme-sapi-maison'),
    'ancien'  => __("L'Okoumé, chaud et ambré, s'intègrera naturellement dans ton intérieur aux tons chauds.", 'theme-sapi-maison'),
    'neutre'  => __("Les deux essences sont belles, tu pourras voir les photos de chaque finition sur la fiche.", 'theme-sapi-maison'),
  ];
}

function sapi_megafilter_get_size_conseils() {
  return [
    'petite'  => __("Cette taille s'adapte bien à un petit espace sans être trop imposante.", 'theme-sapi-maison'),
    'moyenne' => __("Cette taille standard convient à la plupart des pièces.", 'theme-sapi-maison'),
    'grande'  => __("Cette grande taille créera un point focal fort dans ton espace.", 'theme-sapi-maison'),
  ];
}

/* ── Endpoint F2a-3 : soumission form sur-mesure (email Robin) ────────────────── */
add_action('wp_ajax_sapi_megafilter_surmesure', 'sapi_ajax_megafilter_surmesure');
add_action('wp_ajax_nopriv_sapi_megafilter_surmesure', 'sapi_ajax_megafilter_surmesure');

function sapi_ajax_megafilter_surmesure() {
  if (!isset($_POST['nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['nonce'])), 'sapi-megafilter')) {
    wp_send_json_error(['message' => 'Nonce invalide']);
    return;
  }

  // Rate limit anti-spam : même limite que l'IA (1h glissante côté IP)
  if (!sapi_guide_check_rate_limit()) {
    wp_send_json_error([
      'message'  => 'rate_limit',
      'fallback' => 'Trop de demandes pour le moment. Réessaie dans une heure ou écris-moi directement à robin@atelier-sapi.fr.',
    ]);
    return;
  }

  $email = isset($_POST['email']) ? sanitize_email(wp_unslash($_POST['email'])) : '';
  $description = isset($_POST['description']) ? sanitize_textarea_field(wp_unslash($_POST['description'])) : '';

  if (!is_email($email)) {
    wp_send_json_error(['message' => 'Email invalide']);
    return;
  }

  $description_len = mb_strlen($description);
  if ($description_len > 0 && $description_len > 1500) {
    wp_send_json_error(['message' => 'Description trop longue']);
    return;
  }

  // Honeypot anti-bot : si le champ "website" est rempli, on retourne success silencieusement
  if (!empty($_POST['website'])) {
    wp_send_json_success(['message' => 'Merci.']);
    return;
  }

  // Time-trap signé + filtre anti-junk (mêmes couches que les autres contacts).
  // Rejet SILENCIEUX (success sans envoi) — cohérent avec le honeypot ci-dessus.
  if (!sapi_time_trap_valid($_POST['sapi_ts'] ?? 0, $_POST['sapi_tsig'] ?? '')) {
    wp_send_json_success(['message' => 'Merci.']);
    return;
  }
  if (sapi_is_junk_contact($email, $description, false)) {
    wp_send_json_success(['message' => 'Merci.']);
    return;
  }

  // Inscription à la liste Brevo #6 (newsletter) avec SOURCE=conseiller, pour que
  // ces prospects entrent dans la séquence d'accueil -10 % (branche A). Les deux
  // sources (conseiller-modal + card-mes-creations) sont stampées 'conseiller'.
  // On n'envoie QUE l'attribut SOURCE : ajouter tél/message/projet ferait rejeter
  // tout l'ajout par Brevo (400). Non bloquant : l'email de notif et la réponse
  // JSON ci-dessous continuent même si Brevo échoue.
  $api_key = defined('BREVO_API_KEY') ? BREVO_API_KEY : '';
  if ($api_key) {
    $brevo_response = wp_remote_post('https://api.brevo.com/v3/contacts', [
      'headers' => [
        'api-key'      => $api_key,
        'Content-Type' => 'application/json',
        'Accept'       => 'application/json',
      ],
      'body' => wp_json_encode([
        'email'         => $email,
        'listIds'       => [6],
        'attributes'    => ['SOURCE' => 'conseiller'],
        'updateEnabled' => true,
      ]),
      'timeout' => 15,
    ]);

    if (is_wp_error($brevo_response)) {
      error_log('[sapi-brevo-conseiller] Erreur HTTP (' . $email . ') : ' . $brevo_response->get_error_message());
    } else {
      $brevo_code = wp_remote_retrieve_response_code($brevo_response);
      if ($brevo_code < 200 || $brevo_code >= 300) {
        error_log('[sapi-brevo-conseiller] Brevo a répondu ' . $brevo_code . ' (' . $email . ') : ' . wp_remote_retrieve_body($brevo_response));
      }
    }
  }

  // Snapshot projet (optionnel)
  $project_text_snapshot = '';
  if (isset($_POST['project'])) {
    $project_raw = json_decode(wp_unslash($_POST['project']), true);
    if (is_array($project_raw)) {
      $answers_raw = isset($project_raw['answers']) ? $project_raw['answers'] : [];
      $labels_raw  = isset($project_raw['labels'])  ? $project_raw['labels']  : [];
      list($answers, $labels) = sapi_megafilter_sanitize_project($answers_raw, $labels_raw);
      if (!empty($answers)) {
        $project_text_snapshot = sapi_megafilter_format_project_text($answers, $labels);
      }
    }
  }

  // Round 3 — Lot C2 v2 : enrichissement avec contact_kind / contact_subject /
  // source quand la demande vient de la modale conseiller (action=contact IA).
  $allowed_kinds   = ['pro', 'sur-mesure', 'simple'];
  $contact_kind    = isset($_POST['contact_kind']) && in_array($_POST['contact_kind'], $allowed_kinds, true)
                       ? sanitize_text_field(wp_unslash($_POST['contact_kind'])) : '';
  $contact_subject = isset($_POST['contact_subject']) ? sanitize_text_field(wp_unslash($_POST['contact_subject'])) : '';
  $source          = isset($_POST['source']) && $_POST['source'] === 'conseiller-modal' ? 'conseiller-modal' : 'card-mes-creations';

  // Construction de l'email à Robin
  $to      = 'robin@atelier-sapi.fr';
  $subject_prefix = ($source === 'conseiller-modal' && $contact_kind === 'pro') ? '[Pro] ' : '[Sur-mesure] ';
  $subject = $subject_prefix . ($contact_subject !== '' ? $contact_subject : ('Nouvelle demande de ' . $email));
  $body    = "Nouvelle demande reçue depuis " . ($source === 'conseiller-modal' ? 'la modale Conseiller' : '/mes-creations/') . "\n\n";
  $body   .= "── Email visiteur ──\n" . $email . "\n\n";
  if ($contact_kind !== '') {
    $kind_labels = ['pro' => 'Professionnel / B2B', 'sur-mesure' => 'Résidentiel sur-mesure', 'simple' => 'Résidentiel — échange rapide'];
    $body .= "── Type de projet (détection IA) ──\n" . $kind_labels[$contact_kind] . "\n\n";
  }
  if ($contact_subject !== '') {
    $body .= "── Sujet (détection IA) ──\n" . $contact_subject . "\n\n";
  }
  if ($project_text_snapshot !== '') {
    $body .= "── Projet en cours ──\n" . $project_text_snapshot . "\n\n";
  }
  $body   .= "── Description ──\n" . ($description !== '' ? $description : '(pas de description)') . "\n\n";
  $body   .= "── Source ──\n";
  $body   .= 'Page : ' . (isset($_POST['source_url']) ? esc_url_raw(wp_unslash($_POST['source_url'])) : '(non transmise)') . "\n";
  /* IP tronquée ici aussi : ce mail reste des années dans la boîte de Robin,
     et une adresse complète ne lui apprend rien qu'il puisse utiliser. */
  $body   .= 'IP : ' . (isset($_SERVER['REMOTE_ADDR']) ? sapi_tronquer_ip(sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR']))) : '?') . "\n";
  $body   .= 'Date : ' . current_time('mysql') . "\n";
  /* ⚠️ CE NUMÉRO EST LE SEUL PONT ENTRE CE MAIL ET LE TABLEAU DE BORD.
     L'e-mail du visiteur n'est plus stocké en base (décision du 27/08) : sans
     ce numéro, Robin lit une session dans le tableau, veut y répondre, et n'a
     aucun moyen de savoir lequel des mails de l'après-midi lui correspond. Il
     l'appariait à l'œil sur la date — impossible avec deux demandes dans la
     même heure. Le serveur connaît déjà ce numéro ici : il s'en sert vingt
     lignes plus bas pour marquer la ligne. Ne pas le retirer. */
  $sid_pour_mail = isset($_POST['session_id']) ? sanitize_text_field(wp_unslash($_POST['session_id'])) : '';
  $body   .= 'Session : ' . ($sid_pour_mail !== '' ? $sid_pour_mail : '(non transmise)') . "\n";

  $headers = [
    'Content-Type: text/plain; charset=UTF-8',
    'Reply-To: ' . $email,
  ];

  $sent = wp_mail($to, $subject, $body, $headers);

  /* ⚠️ « CONTACTS ENVOYÉS » DOIT COMPTER DES MAILS REÇUS, PAS DES CLICS.
     Le client marquait la session `contact_submitted = 1` AVANT même d'appeler
     ce point d'entrée, et rien ne le rétractait ensuite. Étaient donc comptés
     comme envoyés : les rejets honeypot, time-trap et anti-junk — qui
     répondent « success » EN SILENCE par conception, donc que le client ne peut
     pas distinguer d'un vrai envoi — plus les échecs de `wp_mail` et les
     coupures réseau. Robin lisait « 12 contacts », en recevait 9, et cherchait
     les 3 autres.
     Seul le serveur sait si le mail est parti. C'est donc lui qui l'écrit, et
     uniquement ici, après un envoi réussi. Rien ne fuit vers un robot : la
     réponse HTTP est inchangée. */
  if ($sent && function_exists('sapi_megafilter_marquer_contact_envoye')) {
    sapi_megafilter_marquer_contact_envoye($sid_pour_mail);
  }

  if (!$sent) {
    error_log('Sapi MegaFilter sur-mesure : wp_mail failed pour ' . $email);
    wp_send_json_error([
      'message'  => 'send_failed',
      'fallback' => 'L\'envoi a échoué. Tu peux m\'écrire directement à robin@atelier-sapi.fr.',
    ]);
    return;
  }

  wp_send_json_success([
    'message' => 'Reçu, Robin t\'écrit sous 48h.',
  ]);
}

/* ═══════════════════════════════════════════════════════════
   ROBIN CONSEILLER V2 — SUPPRIMÉ LE 28/08 (583 lignes)
═══════════════════════════════════════════════════════════
   Sept fonctions `sapi_robin_*` d'une version antérieure du Conseiller :
   validation de réponse IA, recommandation finale, parcours sur-mesure, et
   trois appels Claude. Plus AUCUN appelant hors du bloc lui-même — vérifié
   fonction par fonction sur tout le thème avant suppression.

   ⚠️ POURQUOI ÇA COMPTAIT. Ce bloc contenait sa propre copie de la
   conversion escalier → taille (`ouvert ? grande : petite`), périmée et
   contredisant celle du moteur. Une recherche sur « taille_escalier » la
   trouvait, et rien n'indiquait qu'elle était morte : on pouvait la corriger,
   la croire corrigée partout, et repartir avec le bug intact.

   Ce que ce bloc appelait vit ailleurs : le Conseiller V3 passe par
   `sapi_immersion_build_context()` et les deux points d'entrée IA.
═══════════════════════════════════════════════════════════ */

/**
 * Step 1 → WooCommerce product categories
 */
function sapi_guide_get_categories(array $answers) {
  // Refonte filtrage (Tâche 1) : lit la config unique $sapi_filter_rules au lieu
  // de règles en dur. Comportement identique aux entrées réelles.
  $rules = sapi_conseiller_get_rules();

  $sortie    = isset($answers['sortie'])    ? $answers['sortie']    : '';
  $piece     = isset($answers['piece'])     ? $answers['piece']     : '';
  $eclairage = isset($answers['eclairage']) ? $answers['eclairage'] : '';
  $cuisine_remove = (isset($rules['cuisine_remove']) && is_array($rules['cuisine_remove']))
    ? $rules['cuisine_remove'] : ['lampesaposer'];

  // Choix de la table de catégories (principale vs secondaire), repli sur la
  // ligne 'ne-sais-pas' pour toute sortie inconnue (comportement actuel).
  $map_key = ($eclairage === 'secondaire') ? 'cats_secondaire_by_sortie' : 'cats_by_sortie';
  $map = (isset($rules[$map_key]) && is_array($rules[$map_key])) ? $rules[$map_key] : [];
  if (isset($map[$sortie]) && is_array($map[$sortie])) {
    $cats = $map[$sortie];
  } elseif (isset($map['ne-sais-pas']) && is_array($map['ne-sais-pas'])) {
    $cats = $map['ne-sais-pas'];
  } else {
    $cats = ($eclairage === 'secondaire')
      ? ['lampadaires', 'lampesaposer', 'appliques']
      : ['suspensions', 'lampadaires', 'lampesaposer', 'appliques'];
  }
  $cats = array_values($cats);

  // Retraits en cuisine (jamais de lampe à poser ; + lampadaires en Tâche 2).
  if ($piece === 'cuisine') {
    $cats = array_values(array_diff($cats, $cuisine_remove));
  }

  return $cats;
}

/**
 * Get ampoule type filter based on room (lit la config unique — Tâche 1).
 */
function sapi_guide_get_ampoule_filter($piece, $taille = '') {
  $rules = sapi_conseiller_get_rules();

  $skip = (isset($rules['ampoule_skip_when_grande']) && is_array($rules['ampoule_skip_when_grande']))
    ? $rules['ampoule_skip_when_grande'] : ['cuisine', 'bureau'];
  if ($taille === 'grande' && in_array($piece, $skip, true)) {
    return null; // grande pièce de travail : tous les types OK
  }

  $map = (isset($rules['ampoule_by_piece']) && is_array($rules['ampoule_by_piece']))
    ? $rules['ampoule_by_piece'] : [];
  if (array_key_exists($piece, $map)) {
    return is_array($map[$piece]) ? $map[$piece] : null;
  }
  return null; // escalier / pièce inconnue : pas de filtre
}

/**
 * Query main products based on guide answers
 */
function sapi_guide_query_products(array $answers, array $categories) {
  // "Je ne sais pas" → pas de filtre taille (sécurité si appelé directement)
  if (isset($answers['taille']) && $answers['taille'] === 'ne-sais-pas') {
    unset($answers['taille']);
  }

  $tax_query = ['relation' => 'AND'];

  // Category filter
  $tax_query[] = [
    'taxonomy' => 'product_cat',
    'field'    => 'slug',
    'terms'    => $categories,
    'operator' => 'IN',
  ];

  // Format des suspensions : règles lues depuis la config unique (Tâche 1).
  $rules = sapi_conseiller_get_rules();
  $piece   = isset($answers['piece'])   ? $answers['piece']   : '';
  $taille  = isset($answers['taille'])  ? $answers['taille']  : '';
  $hauteur = isset($answers['hauteur']) ? $answers['hauteur'] : '';

  $eclairage = isset($answers['eclairage']) ? $answers['eclairage'] : '';

  $v_haute  = !empty($rules['vertical_haute']);
  $v_entree = !isset($rules['vertical_entree_confort']) || $rules['vertical_entree_confort'];
  $v_petite = !isset($rules['vertical_petite_confort']) || $rules['vertical_petite_confort'];
  $h_petite_haute = !isset($rules['horizontal_petite_haute']) || $rules['horizontal_petite_haute'];

  $allow_vertical = (
    $piece === 'escalier' ||
    ($v_haute && $hauteur === 'haute') ||
    ($v_entree && $piece === 'entree' && in_array($hauteur, ['haute', 'confortable'], true)) ||
    ($v_petite && $taille === 'petite' && in_array($hauteur, ['haute', 'confortable'], true))
  );

  if (in_array('suspensions', $categories) && !$allow_vertical) {
    $tax_query[] = [
      'taxonomy' => 'pa_format',
      'field'    => 'slug',
      'terms'    => ['vertical'],
      'operator' => 'NOT IN',
    ];
  }

  // Règle B : exclure format horizontal dans les espaces étroits + hauts
  $exclude_horizontal = (
    ($piece === 'escalier') ||
    ($h_petite_haute && $taille === 'petite' && $hauteur === 'haute')
  );
  if ($exclude_horizontal && in_array('suspensions', $categories)) {
    $tax_query[] = [
      'taxonomy' => 'pa_format',
      'field'    => 'slug',
      'terms'    => ['horizontal'],
      'operator' => 'NOT IN',
    ];
  }

  // Ampoule filter based on room (reuses $piece from above)
  $ampoule_filter = sapi_guide_get_ampoule_filter($piece, $taille);
  $has_ampoule_filter = false;

  if ($ampoule_filter) {
    $tax_query[] = [
      'taxonomy' => 'pa_type-ampoule',
      'field'    => 'slug',
      'terms'    => $ampoule_filter,
      'operator' => 'IN',
    ];
    $has_ampoule_filter = true;
  }

  $args = [
    'post_type'      => 'product',
    'post_status'    => 'publish',
    'posts_per_page' => 20,
    'tax_query'      => $tax_query,
    'orderby'        => 'menu_order date',
    'order'          => 'ASC',
  ];

  $query = new WP_Query($args);
  $fallback_notes = [];

  // Fallback: if no results with ampoule filter, retry without it
  if (!$query->have_posts() && $has_ampoule_filter) {
    wp_reset_postdata();
    array_pop($tax_query); // Remove ampoule filter
    $args['tax_query'] = $tax_query;
    $query = new WP_Query($args);
    $ideal_types = implode(' ou ', $ampoule_filter);
    $fallback_notes[] = "ATTENTION : aucun produit avec ampoule $ideal_types n'était disponible dans cette catégorie. Les produits ci-dessous peuvent avoir un type d'ampoule différent de l'idéal pour cette pièce. Signale-le honnêtement au client comme un compromis, ne dis pas que c'est le choix idéal.";
  }

  // Second fallback: if still no results, drop format exclusion too
  if (!$query->have_posts() && !$allow_vertical) {
    wp_reset_postdata();
    $args['tax_query'] = [
      ['taxonomy' => 'product_cat', 'field' => 'slug', 'terms' => $categories, 'operator' => 'IN'],
    ];
    $query = new WP_Query($args);
    $fallback_notes[] = "ATTENTION : le filtre de format a aussi été relâché. Des formats normalement exclus peuvent apparaître.";
  }

  $results = sapi_guide_collect_results($query, $answers);

  // Fallback: if grande pièce excluded all products via ≤2 tailles, retry without size filter
  if (empty($results) && $taille === 'grande' && $query->have_posts()) {
    $query->rewind_posts();
    $answers_no_taille = $answers;
    unset($answers_no_taille['taille']);
    $results = sapi_guide_collect_results($query, $answers_no_taille);
    $fallback_notes[] = "ATTENTION : les produits proposés n'ont pas tous 3 tailles ou plus. La recommandation de taille peut ne pas correspondre parfaitement à une grande pièce.";
  }

  wp_reset_postdata();
  return ['products' => $results, 'fallback_notes' => $fallback_notes];
}

/**
 * Query complementary products for grande pièce
 */
function sapi_guide_query_complements(array $answers, array $main_categories) {
  // Complements: jamais de suspension (nécessite sortie plafond dédiée)
  $complement_pool = ['lampadaires', 'lampesaposer', 'appliques'];
  $complement_cats = array_values(array_diff($complement_pool, $main_categories));

  if (empty($complement_cats)) {
    $complement_cats = ['lampadaires', 'lampesaposer'];
  }

  $args = [
    'post_type'      => 'product',
    'post_status'    => 'publish',
    'posts_per_page' => 6,
    'tax_query'      => [
      ['taxonomy' => 'product_cat', 'field' => 'slug', 'terms' => $complement_cats, 'operator' => 'IN'],
    ],
    'orderby'        => 'menu_order',
    'order'          => 'ASC',
  ];

  $query = new WP_Query($args);
  $results = sapi_guide_collect_results($query, $answers);
  wp_reset_postdata();
  return $results;
}

/**
 * Query ALL published products (full catalog, no filters).
 * Used for refinement calls so Claude can pick from any product.
 */
function sapi_guide_query_all_products($answers = []) {
  $args = [
    'post_type'      => 'product',
    'post_status'    => 'publish',
    'posts_per_page' => 50,
    'orderby'        => 'menu_order date',
    'order'          => 'ASC',
  ];
  $query = new WP_Query($args);
  $results = sapi_guide_collect_results($query, $answers, true);
  wp_reset_postdata();
  return $results;
}

/* `sapi_guide_build_filter_context()` (55 lignes) supprimée le 28/08 : jamais
   appelée, nulle part dans le thème. Elle mettait en phrase les contraintes du
   filtrage pour l'IA — ce que fait aujourd'hui
   `sapi_megafilter_format_fallback_notes()`, à partir de ce que le moteur
   rapporte lui-même plutôt que d'une reconstitution. */

/**
 * Process query results into product data arrays
 */
function sapi_guide_collect_results($query, array $answers, $skip_exclusions = false) {
  // Exclusion « grande pièce + suspension ≤2 tailles » : pilotée par la config.
  $sapi_rules = sapi_conseiller_get_rules();
  $grande_exclut_2 = !isset($sapi_rules['grande_exclut_2_tailles']) || $sapi_rules['grande_exclut_2_tailles'];
  // Determine preferred essence from style answer
  /* ⚠️ L'ESSENCE VIENT DU RÉGLAGE DE ROBIN, plus d'une table écrite en dur.
     Son panneau d'administration proposait « Style → essence de bois » : il
     pouvait le changer, l'enregistrer, la page confirmait — et le site
     continuait de servir l'okoumé. La valeur était validée, sauvegardée, et
     relue par PERSONNE. C'est le pire défaut trouvé dans ce chantier, parce
     que c'est le seul que Robin ne pouvait pas diagnostiquer seul : un bouton
     qui ment dans sa propre interface.
     La même table est transmise au JS (`SAPI_PROJECT.styleEssence`) pour que
     la fiche produit obéisse au même réglage. Une seule source. */
  $style = isset($answers['style']) ? $answers['style'] : '';
  $preferred_essence = '';
  if ($style !== '') {
    $regles = function_exists('sapi_conseiller_get_rules') ? sapi_conseiller_get_rules() : [];
    $map = (isset($regles['style_essence']) && is_array($regles['style_essence'])) ? $regles['style_essence'] : [];
    if (!empty($map[$style])) $preferred_essence = $map[$style];
  }

  // Determine preferred size index from room size
  $taille_answer = isset($answers['taille']) ? $answers['taille'] : '';
  $size_index = 0; // petite = smallest
  if ($taille_answer === 'moyenne') $size_index = 1;
  if ($taille_answer === 'grande') $size_index = 2;

  $products = [];
  while ($query->have_posts()) {
    $query->the_post();
    $product = wc_get_product(get_the_ID());
    if (!$product || $product->get_status() !== 'publish') {
      continue;
    }

    $image_id  = $product->get_image_id();
    $price     = $product->get_price_html();
    $variation_label = '';
    $size_label = '';

    // Get category slugs
    $cats = get_the_terms($product->get_id(), 'product_cat');
    $cat_slugs = [];
    if ($cats && !is_wp_error($cats)) {
      foreach ($cats as $c) {
        $cat_slugs[] = $c->slug;
      }
    }

    // Get format attribute (nom + slug — le slug sert au moteur de classement)
    $format_terms = get_the_terms($product->get_id(), 'pa_format');
    $format = ($format_terms && !is_wp_error($format_terms)) ? $format_terms[0]->name : '';
    $format_slug = ($format_terms && !is_wp_error($format_terms)) ? $format_terms[0]->slug : '';

    // Get ampoule attribute (nom + slug)
    $ampoule_terms = get_the_terms($product->get_id(), 'pa_type-ampoule');
    $ampoule = ($ampoule_terms && !is_wp_error($ampoule_terms)) ? $ampoule_terms[0]->name : '';
    $ampoule_slug = ($ampoule_terms && !is_wp_error($ampoule_terms)) ? $ampoule_terms[0]->slug : '';

    // Match preferred essence + size variation
    if ($product->is_type('variable')) {
      $variations = $product->get_available_variations();

      // Determine preferred taille slug for this product
      $preferred_taille_slug = '';
      if ($taille_answer) {
        $taille_terms = wc_get_product_terms($product->get_id(), 'pa_taille', ['orderby' => 'menu_order']);

        // Grande pièce + suspension : exclure les produits avec 2 tailles ou moins (sauf en refine)
        if ($grande_exclut_2 && !$skip_exclusions && $taille_answer === 'grande' && !empty($taille_terms) && count($taille_terms) <= 2 && array_intersect($cat_slugs, ['suspensions'])) {
          continue;
        }

        if (!empty($taille_terms)) {
          $idx = min($size_index, count($taille_terms) - 1);
          $preferred_taille_slug = $taille_terms[$idx]->slug;
          $size_label = $taille_terms[$idx]->name;
        }
      }

      // Find best variation matching both essence + taille
      $best_var = null;
      $fallback_essence = null;
      $fallback_taille = null;

      foreach ($variations as $var) {
        $mat = isset($var['attributes']['attribute_pa_materiau']) ? $var['attributes']['attribute_pa_materiau'] : '';
        $tai = isset($var['attributes']['attribute_pa_taille']) ? $var['attributes']['attribute_pa_taille'] : '';

        // Empty attribute = "any" in WooCommerce
        $essence_ok = (!$preferred_essence || $mat === $preferred_essence || $mat === '');
        $taille_ok  = (!$preferred_taille_slug || $tai === $preferred_taille_slug || $tai === '');

        if ($essence_ok && $taille_ok) {
          $best_var = $var;
          break;
        }
        if ($essence_ok && !$fallback_essence) $fallback_essence = $var;
        if ($taille_ok && !$fallback_taille) $fallback_taille = $var;
      }

      if (!$best_var) {
        $best_var = $fallback_essence ?: $fallback_taille;
      }

      if ($best_var) {
        if (!empty($best_var['image_id'])) {
          $image_id = $best_var['image_id'];
        }
        $var_product = wc_get_product($best_var['variation_id']);
        if ($var_product) {
          $price = $var_product->get_price_html();
        }
        if ($preferred_essence) {
          $term = get_term_by('slug', $preferred_essence, 'pa_materiau');
          $variation_label = $term ? $term->name : ucfirst($preferred_essence);
        }
      }

      // Collect ALL variations for refine context
      $all_vars = [];
      foreach ($variations as $var) {
        $mat_slug = isset($var['attributes']['attribute_pa_materiau']) ? $var['attributes']['attribute_pa_materiau'] : '';
        $tai_slug = isset($var['attributes']['attribute_pa_taille']) ? $var['attributes']['attribute_pa_taille'] : '';
        $mat_name = '';
        if ($mat_slug) {
          $mat_term = get_term_by('slug', $mat_slug, 'pa_materiau');
          $mat_name = $mat_term ? $mat_term->name : ucfirst($mat_slug);
        }
        $tai_name = '';
        if ($tai_slug) {
          $tai_term = get_term_by('slug', $tai_slug, 'pa_taille');
          $tai_name = $tai_term ? $tai_term->name : ucfirst($tai_slug);
        }
        $var_img_id = !empty($var['image_id']) ? (int) $var['image_id'] : 0;
        $var_product_obj = wc_get_product($var['variation_id']);
        $all_vars[] = [
          'variation_id' => (int) $var['variation_id'],
          'essence'      => $mat_name,
          'taille'       => $tai_name,
          'image_url'    => $var_img_id ? wp_get_attachment_url($var_img_id) : '',
          'price'        => $var_product_obj ? $var_product_obj->get_price_html() : '',
        ];
      }
    }

    // Ambiance photo for full-width banner
    $pid = $product->get_id();
    $ambiance_photos = sapi_get_product_photos($pid, 'ambiance', 2);
    // Prefer second ambiance photo, fallback to first
    $ambiance_url = isset($ambiance_photos[1]) ? $ambiance_photos[1] : (isset($ambiance_photos[0]) ? $ambiance_photos[0] : '');

    // Hover image (first gallery image for card hover effect)
    $hover_image_url = '';
    $gallery_ids = $product->get_gallery_image_ids();
    if (!empty($gallery_ids)) {
      $hover_image_url = wp_get_attachment_image_url($gallery_ids[0], 'woocommerce_thumbnail');
    }

    // Category label for card
    $cat_names = wp_get_post_terms($product->get_id(), 'product_cat', ['fields' => 'names']);
    $cat_label = !empty($cat_names) ? $cat_names[0] : '';

    // Round 2 — 4.2 : prix min raw (float) pour le catalogue passé à l'IA.
    // Pour les produits variables : min des prix de variation (sans taxes,
    // version raw — pas le HTML formaté).
    $price_min_raw = 0.0;
    if ($product->is_type('variable')) {
      $var_prices = $product->get_variation_prices(false);
      if (!empty($var_prices['price'])) {
        $price_min_raw = (float) min($var_prices['price']);
      }
    } else {
      $raw = $product->get_price();
      $price_min_raw = is_numeric($raw) ? (float) $raw : 0.0;
    }

    $products[] = [
      'id'              => $product->get_id(),
      'title'           => $product->get_name(),
      'price'           => $price,
      'price_min_raw'   => $price_min_raw,
      'image'           => $image_id ? wp_get_attachment_url($image_id) : '',
      'image_alt'       => $image_id ? get_post_meta($image_id, '_wp_attachment_image_alt', true) : '',
      'hover_image'     => $hover_image_url,
      'permalink'       => get_permalink($product->get_id()),
      'variation_label' => $variation_label,
      'size_label'      => $size_label,
      'categories'      => $cat_slugs,
      'category_label'  => $cat_label,
      'format'          => $format,
      'format_slug'     => $format_slug,
      'type_ampoule'    => $ampoule,
      'type_ampoule_slug' => $ampoule_slug,
      'total_sales'     => (int) $product->get_total_sales(),
      'ambiance'        => $ambiance_url,
      'variations'        => isset($all_vars) ? $all_vars : [],
      'best_variation_id' => isset($best_var) ? (int) $best_var['variation_id'] : 0,
    ];
  }

  return $products;
}

/**
 * Refonte filtrage Conseiller — valeurs PAR DÉFAUT du moteur de filtrage.
 * Socle en dur calqué sur le simulateur (assets/guide-filtrage-simulateur.html).
 * Toute clé non surchargée par l'admin (Tâche 5, option `sapi_conseiller_rules`)
 * retombe ici. NE PAS lire directement : passer par sapi_conseiller_get_rules().
 */
function sapi_conseiller_default_rules() {
  return [
    // ── Filtre DUR : ampoule par pièce ──
    'ampoule_by_piece' => [
      'cuisine'  => ['ampoule_degagee', 'semi_degagee'],
      'bureau'   => ['ampoule_degagee', 'semi_degagee'],
      'salon'    => ['ampoule_entouree', 'semi_degagee'],
      'chambre'  => ['ampoule_entouree', 'semi_degagee'],
      'chambre-enfant' => ['ampoule_entouree', 'semi_degagee'],
      'entree'   => ['ampoule_entouree', 'semi_degagee'],
      'escalier' => null,
    ],
    'ampoule_skip_when_grande' => ['cuisine', 'bureau'],
    'cats_by_sortie' => [
      'plafond'       => ['suspensions'],
      'mur'           => ['appliques'],
      'pas-de-sortie' => ['lampadaires', 'lampesaposer', 'appliques'],
      'ne-sais-pas'   => ['suspensions', 'lampadaires', 'lampesaposer', 'appliques'],
      ''              => ['suspensions', 'lampadaires', 'lampesaposer', 'appliques'],
    ],
    'cats_secondaire_by_sortie' => [
      'plafond'       => ['suspensions'],
      'mur'           => ['appliques'],
      'pas-de-sortie' => ['lampadaires', 'lampesaposer', 'appliques'],
      'ne-sais-pas'   => ['lampadaires', 'lampesaposer', 'appliques'],
      ''              => ['lampadaires', 'lampesaposer'],
    ],
    'extras_slugs' => ['accessoires', 'carte-cadeau'],

    // ── Couche PRIORITÉ (ne fait que CLASSER, n'exclut jamais) ──
    'prio'       => true,
    'prio_mode'  => 'souple', // 'souple' | 'strict'
    'importance' => ['categorie', 'ampoule', 'format'],
    'ampoule_pref_by_piece' => [
      'salon'          => 'ampoule_entouree',
      'chambre'        => 'ampoule_entouree',
      'chambre-enfant' => 'ampoule_entouree',
      'entree'         => 'ampoule_entouree',
      'cuisine'        => 'ampoule_degagee',
      'bureau'         => 'ampoule_degagee',
      'escalier'       => null,
    ],
    'format_pref_by_piece' => [
      'salon' => '', 'chambre' => '', 'chambre-enfant' => '', 'cuisine' => '',
      'bureau' => '', 'entree' => '', 'escalier' => '',
    ],
    'cat_priority_by_sortie' => [
      'plafond' => '', 'mur' => '', 'pas-de-sortie' => '', 'ne-sais-pas' => '', '' => '',
    ],

    // ── Réglages divers ──
    /* ⚠️ `neutre` → PEUPLIER, décision Robin du 26/08 : « pas de préférence »
       n'est pas un refus de choisir, c'est une délégation. Le défaut disait
       encore « aucune essence », l'état d'avant cette décision. */
    'style_essence' => ['moderne' => 'peuplier', 'ancien' => 'okoume', 'neutre' => 'peuplier'],
    /* ⚠️ `standard` → MOYENNE, ET NON PLUS « PETITE ». Le serveur choisissait
       comme pour une petite pièce là où le navigateur choisissait la moyenne
       (décision Robin du 27/08 : « recommander la taille moyenne par défaut »).
       Deux visiteurs identiques recevaient donc deux recommandations
       différentes selon le chemin emprunté. Cette table est désormais la SEULE
       source : elle est transmise au navigateur dans `SAPI_PROJECT.escalierMap`.
       `ouvert` reste `grande` côté moteur — c'est le vocabulaire des tailles,
       que le moteur comprend. Le navigateur, lui, le traduit en « la plus
       grande option disponible » : voir la note dans sapi-project.js. */
    'escalier_map'  => ['standard' => 'moyenne', 'ouvert' => 'grande'],

    // ── Filtre DUR : cuisine retire lampe à poser ET lampadaire (Tâche 2) ──
    'cuisine_remove' => ['lampesaposer', 'lampadaires'],
    // ── Filtre DUR : format des suspensions (Tâche 2 : vertical dès plafond haut) ──
    'vertical_haute'           => true,
    'vertical_entree_confort'  => true,
    'vertical_petite_confort'  => true,
    'horizontal_petite_haute'  => true,
    // ── Taille ──
    'grande_exclut_2_tailles'  => true,
  ];
}

/**
 * Règles de filtrage EFFECTIVES = défauts surchargés par l'option admin
 * `sapi_conseiller_rules` (Tâche 5), au niveau des clés de 1er rang (l'admin
 * sauvegarde la valeur complète de chaque règle éditée → array_merge suffit).
 * `apply_filters('sapi_conseiller_rules', …)` permet à l'aperçu live (5.4)
 * d'injecter des règles « draft » NON sauvegardées le temps d'une requête, sans
 * toucher à l'option. SEULE source pour tout le filtre PHP — ne jamais relire
 * l'array en dur ailleurs (cf. piège « config en variable locale » corrigé).
 */
function sapi_conseiller_get_rules() {
  $defaults = sapi_conseiller_default_rules();
  $saved = get_option('sapi_conseiller_rules', []);
  $rules = (is_array($saved) && $saved) ? array_merge($defaults, $saved) : $defaults;
  return apply_filters('sapi_conseiller_rules', $rules);
}

/**
 * Refonte filtrage Conseiller (Tâche 1) — couche PRIORITÉ / classement.
 *
 * Reproduit la mécanique du simulateur (assets/guide-filtrage-simulateur.html,
 * fonctions ranks/score) : la priorité ne fait que CLASSER (n'exclut jamais).
 * Chaque produit reçoit un rang 0/1 par critère (ampoule / catégorie / format),
 * combinés en un score lexicographique selon l'ordre d'importance ; tri stable
 * (à score égal, on garde l'ordre d'entrée = ventes / menu_order). En mode
 * 'strict', on ne garde que le meilleur score.
 *
 * @param array $products Produits enrichis (sortie de sapi_guide_collect_results)
 * @param array $answers  Réponses {piece, sortie, taille, ...}
 * @param array|null $rules Config ; par défaut le global $sapi_filter_rules
 * @return array Produits reclassés
 */
function sapi_conseiller_rank_products(array $products, array $answers, $rules = null) {
  if ($rules === null) {
    $rules = sapi_conseiller_get_rules();
  }
  if (empty($products) || empty($rules['prio'])) {
    return $products; // priorité désactivée → ordre inchangé (ventes / menu_order)
  }

  $piece  = isset($answers['piece'])  ? $answers['piece']  : '';
  $sortie = isset($answers['sortie']) ? $answers['sortie'] : '';
  $taille = isset($answers['taille']) ? $answers['taille'] : '';
  $importance = (isset($rules['importance']) && is_array($rules['importance']))
    ? $rules['importance'] : ['categorie', 'ampoule', 'format'];

  // Préférence ampoule : seulement si un filtre ampoule est actif ET que la
  // préférée en fait partie (cf. getAmpPref du simulateur).
  $amp_pref = '';
  $amp_filter = function_exists('sapi_guide_get_ampoule_filter')
    ? sapi_guide_get_ampoule_filter($piece, $taille) : null;
  if ($amp_filter && is_array($amp_filter)) {
    $pp = isset($rules['ampoule_pref_by_piece'][$piece]) ? $rules['ampoule_pref_by_piece'][$piece] : '';
    if ($pp && in_array($pp, $amp_filter, true)) $amp_pref = $pp;
  }
  $cat_pref = isset($rules['cat_priority_by_sortie'][$sortie]) ? $rules['cat_priority_by_sortie'][$sortie] : '';
  $fmt_pref = isset($rules['format_pref_by_piece'][$piece]) ? $rules['format_pref_by_piece'][$piece] : '';

  // Rang + score par produit, en conservant l'index d'origine pour un tri stable.
  $tmp = [];
  foreach ($products as $idx => $p) {
    $cats = isset($p['categories']) && is_array($p['categories']) ? $p['categories'] : [];
    $is_susp = in_array('suspensions', $cats, true);
    $r_amp = $amp_pref ? (((isset($p['type_ampoule_slug']) ? $p['type_ampoule_slug'] : '') === $amp_pref) ? 0 : 1) : 0;
    $r_cat = $cat_pref ? (in_array($cat_pref, $cats, true) ? 0 : 1) : 0;
    $r_fmt = ($fmt_pref && $is_susp) ? (((isset($p['format_slug']) ? $p['format_slug'] : '') === $fmt_pref) ? 0 : 1) : 0;

    $score = 0;
    foreach ($importance as $crit) {
      $rk = ($crit === 'ampoule') ? $r_amp : (($crit === 'categorie') ? $r_cat : $r_fmt);
      $score = $score * 10 + $rk;
    }
    $tmp[] = ['score' => $score, 'idx' => $idx, 'p' => $p];
  }

  usort($tmp, function ($a, $b) {
    if ($a['score'] !== $b['score']) return $a['score'] - $b['score'];
    return $a['idx'] - $b['idx']; // tri stable : ordre d'origine à score égal
  });

  // Mode strict : ne garder que le(s) meilleur(s) score(s).
  if (isset($rules['prio_mode']) && $rules['prio_mode'] === 'strict' && !empty($tmp)) {
    $best = $tmp[0]['score'];
    $tmp = array_values(array_filter($tmp, function ($x) use ($best) { return $x['score'] === $best; }));
  }

  return array_map(function ($x) { return $x['p']; }, $tmp);
}

/* ═══════════════════════════════════════════════════════════
   CONTEXTE D'IMMERSION — LA SÉLECTION ET SON DÉCOR, D'UN SEUL CALCUL
   ═══════════════════════════════════════════════════════════

   ⚠️ POURQUOI CETTE FONCTION EXISTE — à lire avant de la contourner.

   Deux objets différents portaient le même mot « sélection », et le template
   les avait soudés dans une seule variable, `$imm_piece` :
     • LA SÉLECTION = le résultat du moteur, fonction de six critères — et
       `piece` est le MOINS déterminant d'entre eux. `sapi_guide_get_categories()`
       choisit sur `sortie` d'abord ; la pièce n'y sert qu'à retirer les lampes
       à poser en cuisine.
     • LE DÉCOR = la photo, le possessif « pour ton salon », le titre. Fonction
       d'UN seul critère, la pièce.

   Tant que les deux sortaient d'endroits différents, ils pouvaient se
   contredire — et ils l'ont fait. Défaut constaté par Robin le 26/08 : le
   conseil disait « ma sélection d'appliques pour ton couloir », juste, tandis
   que le titre annonçait l'entrée et le carrousel montrait des suspensions.
   L'endpoint d'affinage ne renvoyait que `html` et `count` : AUCUN affinage ne
   POUVAIT corriger le décor. Ce n'était pas un oubli, c'était structurel.

   Le précédent est `sapi_immersion_render_product_card()` juste en dessous :
   une source unique pour le markup d'une card, partagée entre le template et
   l'AJAX. Même réflexe ici, pour le décor.

   → TROIS APPELANTS, un seul calcul : le template (archive-product.php),
     l'endpoint du moment 2, et tout ce qui viendra ensuite.

   @param array $answers Réponses du questionnaire (déjà validées/slugifiées)
   @return array products, category_label, possessive, title, phrase, fallback_notes
*/
function sapi_immersion_build_context(array $answers) {
  $piece = isset($answers['piece']) ? $answers['piece'] : '';

  /* Escalier → taille. Cette conversion vivait en double (endpoint + ailleurs) ;
     elle est ici pour que les deux appelants la subissent à l'identique. */
  if ($piece === 'escalier' && !empty($answers['taille_escalier'])) {
    $rules = function_exists('sapi_conseiller_get_rules') ? sapi_conseiller_get_rules() : [];
    $map = isset($rules['escalier_map']) ? $rules['escalier_map'] : ['standard' => 'moyenne', 'ouvert' => 'grande'];
    /* Repli sur `moyenne` et non `petite` : un type d'escalier inconnu n'est
       pas une raison de proposer le plus petit modèle du catalogue. */
    $answers['taille'] = isset($map[$answers['taille_escalier']]) ? $map[$answers['taille_escalier']] : 'moyenne';
  }

  // ── La sélection (le moteur, inchangé : il est l'acquis, on ne le refait pas)
  $cats = function_exists('sapi_guide_get_categories') ? sapi_guide_get_categories($answers) : [];
  $res  = ($cats && function_exists('sapi_guide_query_products'))
    ? sapi_guide_query_products($answers, $cats)
    : ['products' => [], 'fallback_notes' => []];
  $products = isset($res['products']) ? $res['products'] : [];
  if (function_exists('sapi_conseiller_rank_products')) {
    $products = sapi_conseiller_rank_products($products, $answers);
  }
  // Plafond APRÈS le classement : les 4 MEILLEURS, pas 4 au hasard.
  if (function_exists('sapi_immersion_max_products')) {
    $products = array_slice($products, 0, sapi_immersion_max_products());
  }

  // ── Le décor, calculé sur les MÊMES réponses
  $possessive = function_exists('sapi_piece_possessive') ? sapi_piece_possessive($piece) : 'ta pièce';
  $phrase = function_exists('sapi_megafilter_generic_advice_for') ? sapi_megafilter_generic_advice_for($piece) : '';

  /* La catégorie dominante : elle n'existe que si le moteur n'en a déduit
     qu'UNE. Deux catégories ou plus, et annoncer « ma sélection d'appliques »
     serait un mensonge — on retombe alors sur le titre neutre. */
  $cat_label = '';
  if (is_array($cats) && count($cats) === 1) {
    $term = get_term_by('slug', (string) $cats[0], 'product_cat');
    if ($term && !is_wp_error($term)) $cat_label = $term->name;
  }

  /* Le titre dit la catégorie DÈS QU'ELLE EST CERTAINE. C'est précisément ce
     qui manquait dans la capture de Robin : le conseil parlait d'appliques,
     le titre parlait de la pièce, et rien ne raccrochait les deux. */
  /* « Mes appliques pour ton entrée » plutôt que « Ma sélection d'appliques
     pour ton entrée » : dix caractères de moins, et Robin parle à la première
     personne comme partout ailleurs sur le site. Le calcul de largeur comptait :
     à 13 px capitales espacées, le titre long dépassait les 327 px utiles d'un
     iPhone et passait à deux lignes, ce qui rognait la photo des cards. */
  $title = $cat_label
    ? sprintf(
        __('Mes %s pour %s', 'theme-sapi-maison'),
        function_exists('mb_strtolower') ? mb_strtolower($cat_label, 'UTF-8') : strtolower($cat_label),
        $possessive
      )
    : sprintf(__('Ma sélection pour %s', 'theme-sapi-maison'), $possessive);

  return [
    'products'       => $products,
    'categories'     => is_array($cats) ? array_values($cats) : [],
    'category_label' => $cat_label,
    'possessive'     => $possessive,
    'title'          => $title,
    'phrase'         => $phrase,
    /* Le moteur SAIT quand il a relâché une contrainte, et il le dit à l'IA.
       Le hero, lui, jetait l'information : le visiteur voyait un compromis
       présenté comme un idéal. On la remonte ; où l'afficher est une décision
       de Robin (la mise en page du hero est calibrée au pixel). */
    'fallback_notes' => isset($res['fallback_notes']) ? $res['fallback_notes'] : [],
  ];
}

/**
 * Rendu d'UNE card produit du slider immersion (.product-card-cinetique, design
 * catalogue + photo d'ambiance adaptée à la pièce). Utilisé par le template
 * (archive-product.php) ET l'endpoint AJAX du « moment 2 » (fermeture modale).
 * Échoue le HTML.
 *
 * @param array  $prod  Un produit enrichi (sortie de sapi_guide_collect_results)
 * @param string $piece Slug pièce (pour la photo d'ambiance taguée)
 */
function sapi_immersion_render_product_card(array $prod, $piece = '') {
  if (empty($prod['id'])) return;
  $cat_label = '';
  if (!empty($prod['category_label'])) {
    $cat_label = str_replace(
      ['Suspensions', 'Appliques', 'Lampadaires', 'Lampes à poser'],
      ['Suspension',  'Applique',  'Lampadaire',  'Lampe à poser'],
      $prod['category_label']
    );
  }
  $has_var   = !empty($prod['variations']);
  $hover     = !empty($prod['hover_image']);
  $cats_attr = !empty($prod['categories']) ? implode(' ', $prod['categories']) : '';
  $amb_ids = function_exists('sapi_get_product_photo_ids_with_fallback')
    ? sapi_get_product_photo_ids_with_fallback($prod['id'], 'ambiance', 1, $piece) : [];
  $amb_id = !empty($amb_ids) ? (int) $amb_ids[0] : 0;
  ?>
  <div class="product-card-cinetique" data-product-id="<?php echo esc_attr($prod['id']); ?>" data-categories="<?php echo esc_attr($cats_attr); ?>">
    <a href="<?php echo esc_url($prod['permalink']); ?>" class="product-card-link">
      <div class="product-media<?php echo $hover ? ' has-hover-image' : ''; ?>">
        <?php if ($amb_id) : ?>
          <span class="product-image-main"><?php echo wp_get_attachment_image($amb_id, 'large', false, ['alt' => get_the_title($prod['id']), 'loading' => 'lazy']); ?></span>
        <?php elseif (!empty($prod['image'])) : ?>
          <span class="product-image-main"><img src="<?php echo esc_url($prod['image']); ?>" alt="<?php echo esc_attr($prod['title']); ?>" loading="lazy"></span>
        <?php endif; ?>
        <?php if ($hover) : ?>
          <span class="product-image-hover"><img src="<?php echo esc_url($prod['hover_image']); ?>" alt="" loading="lazy"></span>
        <?php endif; ?>
      </div>
      <div class="product-info">
        <h3 class="product-name"><?php echo esc_html($prod['title']); ?></h3>
        <?php if ($cat_label) : ?><p class="product-category"><?php echo esc_html($cat_label); ?></p><?php endif; ?>
        <div class="product-price">
          <?php if ($has_var) : ?><span class="price-from"><?php esc_html_e('À partir de', 'theme-sapi-maison'); ?></span><?php endif; ?>
          <span class="price-value"><?php echo $has_var ? wp_kses_post(wc_price($prod['price_min_raw'])) : wp_kses_post($prod['price']); ?></span>
        </div>
      </div>
      <div class="product-actions">
        <span class="btn-view"><?php esc_html_e('Découvrir', 'theme-sapi-maison'); ?> &#8702;</span>
      </div>
    </a>
  </div>
  <?php
}

/**
 * Endpoint « moment 2 » (refonte filtrage) — appelé à la fermeture de la modale
 * Conseiller sur /mes-creations/ (terminée OU abandonnée). Re-filtre + classe la
 * sélection avec les réponses données (le MÊME moteur serveur que le chargement)
 * et renvoie les cards rendues. Le JS ne fait que remplacer le slider.
 */
add_action('wp_ajax_sapi_immersion_selection', 'sapi_ajax_immersion_selection');
add_action('wp_ajax_nopriv_sapi_immersion_selection', 'sapi_ajax_immersion_selection');
/**
 * Nombre de MODÈLES affichés dans le carrousel de l'immersion.
 *
 * Robin veut 5 propositions au total, la 5ᵉ étant toujours la carte
 * « Créons ensemble » (sur-mesure). Celle-ci est rendue par le template
 * (archive-product.php) et conservée telle quelle par swapCards() au moment 2 :
 * elle est donc TOUJOURS présente et occupe le dernier emplacement.
 * Il reste 4 places pour les modèles du catalogue.
 *
 * ⚠️ Le plafond s'applique APRÈS le classement par priorité, jamais avant :
 * couper d'abord reviendrait à garder 4 modèles au hasard puis à les ordonner,
 * au lieu de garder les 4 MEILLEURS.
 */
function sapi_immersion_max_products() {
  return 4;
}

function sapi_ajax_immersion_selection() {
  $nonce = isset($_POST['nonce']) ? sanitize_text_field(wp_unslash($_POST['nonce'])) : '';
  if (!wp_verify_nonce($nonce, 'sapi-megafilter')) {
    wp_send_json_error(['message' => 'nonce'], 403);
  }
  $raw = isset($_POST['answers']) ? json_decode(wp_unslash($_POST['answers']), true) : [];
  if (!is_array($raw)) $raw = [];

  // Ne garder que les clés du questionnaire, valeurs en slug.
  $whitelist = function_exists('sapi_megafilter_filters_whitelist') ? sapi_megafilter_filters_whitelist() : [];
  $allowed = array_merge(array_keys($whitelist), ['taille_escalier']);
  $answers = [];
  foreach ($raw as $k => $v) {
    $key = sanitize_key($k);
    if (in_array($key, $allowed, true) && (is_string($v) || is_numeric($v))) {
      $answers[$key] = sanitize_key($v);
    }
  }

  $piece = isset($answers['piece']) ? $answers['piece'] : '';

  /* Sélection ET décor du même calcul — voir le commentaire de
     sapi_immersion_build_context(). Avant, cet endpoint ne renvoyait que des
     cards : le titre et la pill restaient figés sur ce que le serveur avait
     rendu au chargement, et pouvaient contredire les produits affichés. */
  $ctx = sapi_immersion_build_context($answers);
  $products = $ctx['products'];

  ob_start();
  foreach ($products as $prod) {
    if (function_exists('sapi_immersion_render_product_card')) {
      sapi_immersion_render_product_card($prod, $piece);
    }
  }
  $html = ob_get_clean();

  wp_send_json_success([
    'html'           => $html,
    'count'          => count($products),
    'title'          => $ctx['title'],
    'possessive'     => $ctx['possessive'],
    'category_label' => $ctx['category_label'],
    'fallback_notes' => $ctx['fallback_notes'],
  ]);
}

/**
 * Find a product in the data array by ID
 */
function sapi_guide_find_product_by_id(array $products, $id) {
  foreach ($products as $p) {
    if ((int) $p['id'] === (int) $id) {
      return $p;
    }
  }
  return null;
}

/**
 * Pick up to $count products from the filtered list.
 * Normal mode: best seller, newest, 2nd best seller, random.
 * Diversify mode (grappe): one product per format (horizontal, boule, vertical…),
 * prioritized by total_sales within each format.
 */
function sapi_guide_pick_four(array $products, $count = 4, $diversify_format = false) {
  if (count($products) <= $count) {
    return $products;
  }

  // Grappe: pick one product per format, best seller within each
  if ($diversify_format) {
    $by_format = [];
    foreach ($products as $p) {
      $fmt = !empty($p['format']) ? strtolower($p['format']) : 'autre';
      if (!isset($by_format[$fmt])) {
        $by_format[$fmt] = [];
      }
      $by_format[$fmt][] = $p;
    }
    // Sort each format group by sales desc
    foreach ($by_format as &$group) {
      usort($group, function ($a, $b) {
        return $b['total_sales'] - $a['total_sales'];
      });
    }
    unset($group);

    // Pick best seller from each format
    $picked = [];
    foreach ($by_format as $group) {
      $picked[] = $group[0];
      if (count($picked) >= $count) {
        break;
      }
    }

    // If not enough formats, fill from remaining best sellers
    if (count($picked) < $count) {
      $picked_ids = array_map(function ($p) { return $p['id']; }, $picked);
      $remaining = array_filter($products, function ($p) use ($picked_ids) {
        return !in_array($p['id'], $picked_ids);
      });
      usort($remaining, function ($a, $b) {
        return $b['total_sales'] - $a['total_sales'];
      });
      foreach ($remaining as $p) {
        $picked[] = $p;
        if (count($picked) >= $count) {
          break;
        }
      }
    }

    return $picked;
  }

  // Normal mode
  $picked = [];
  $remaining = $products;

  // 1) Best seller
  usort($remaining, function ($a, $b) {
    return $b['total_sales'] - $a['total_sales'];
  });
  $picked[] = array_shift($remaining);

  // 2) Newest (highest ID = most recently created)
  usort($remaining, function ($a, $b) {
    return $b['id'] - $a['id'];
  });
  $picked[] = array_shift($remaining);

  // 3) 2nd best seller (best seller among remaining)
  usort($remaining, function ($a, $b) {
    return $b['total_sales'] - $a['total_sales'];
  });
  $picked[] = array_shift($remaining);

  // 4) Random among remaining (only when picking 4)
  if ($count >= 4 && !empty($remaining)) {
    $picked[] = $remaining[array_rand($remaining)];
  }

  return $picked;
}

/**
 * Build the system prompt for Claude with filtered products and client answers
 */
/**
 * Call Claude API and return parsed response
 */
function sapi_guide_call_claude($system_prompt) {
  $api_key = defined('ANTHROPIC_API_KEY') ? ANTHROPIC_API_KEY : '';
  if (empty($api_key)) {
    return null;
  }

  $body = [
    'model'      => 'claude-sonnet-4-6',
    'max_tokens' => 1024,
    'system'     => $system_prompt,
    'messages'   => [
      ['role' => 'user', 'content' => 'Voici mes réponses au questionnaire. Explique-moi pourquoi cette sélection de luminaires correspond à mes critères.'],
    ],
  ];

  $response = wp_remote_post('https://api.anthropic.com/v1/messages', [
    'timeout' => 30,
    'headers' => [
      'Content-Type'     => 'application/json',
      'x-api-key'        => $api_key,
      'anthropic-version' => '2023-06-01',
    ],
    'body' => wp_json_encode($body),
  ]);

  if (is_wp_error($response)) {
    error_log('Sapi Guide Claude API error: ' . $response->get_error_message());
    return null;
  }

  $status = wp_remote_retrieve_response_code($response);
  $raw_body = wp_remote_retrieve_body($response);

  if ($status !== 200) {
    error_log('Sapi Guide Claude API HTTP ' . $status . ': ' . $raw_body);
    return null;
  }

  $data = json_decode($raw_body, true);
  if (!isset($data['content'][0]['text'])) {
    return null;
  }

  $text = $data['content'][0]['text'];

  // Clean markdown code fences if present
  $text = preg_replace('/^```json\s*/i', '', trim($text));
  $text = preg_replace('/\s*```$/i', '', $text);

  $parsed = json_decode(trim($text), true);
  if (!$parsed || (!isset($parsed['conseils_text']) && !isset($parsed['recommendation']))) {
    // If Claude didn't return valid JSON, use the raw text as conseils_text
    return [
      'conseils_text' => $text,
    ];
  }

  return $parsed;
}

/**
 * ── Refinement: Build system prompt with full catalog ──
 * Used when the client sends a follow-up message after seeing initial results.
 */
function sapi_guide_build_refine_prompt(array $all_products, array $answers, $filter_context, array $current_product_ids) {
  $theme_dir = get_stylesheet_directory();
  $refine_rules = file_get_contents($theme_dir . '/assets/guide-prompt-refine.txt');
  $ton          = file_get_contents($theme_dir . '/assets/guide-prompt-ton.txt');

  $prompt = $refine_rules . "\n\n" . $ton . "\n\n";

  // Full catalog
  $prompt .= "CATALOGUE COMPLET (tous les luminaires disponibles) :\n";
  foreach ($all_products as $p) {
    $is_current = in_array((int) $p['id'], $current_product_ids, true) ? ' [ACTUELLEMENT AFFICHÉ]' : '';
    $prompt .= '- ' . $p['title']
      . ' | ID : ' . $p['id']
      . ' | Catégorie : ' . implode(', ', $p['categories'])
      . ' | Format : ' . $p['format']
      . ' | Ampoule : ' . $p['type_ampoule']
      . ' | Ventes : ' . $p['total_sales']
      . $is_current . "\n";

    // List all variations
    if (!empty($p['variations'])) {
      $var_parts = [];
      foreach ($p['variations'] as $v) {
        $label = trim($v['essence'] . ' ' . $v['taille']);
        $var_parts[] = $label . ' (var:' . $v['variation_id'] . ')';
      }
      $prompt .= '  Variations : ' . implode(', ', $var_parts) . "\n";
      if (!empty($p['best_variation_id'])) {
        $best_label = '';
        foreach ($p['variations'] as $v) {
          if ($v['variation_id'] === $p['best_variation_id']) {
            $best_label = trim($v['essence'] . ' ' . $v['taille']);
            break;
          }
        }
        $prompt .= '  [RECOMMANDÉ : var:' . $p['best_variation_id'] . ($best_label ? ' ' . $best_label : '') . "]\n";
      }
    }
  }

  // Quiz answers
  $prompt .= "\nRÉPONSES INITIALES DU CLIENT AU QUESTIONNAIRE :\n";
  $labels = [
    'piece'     => 'Pièce',
    'taille'    => 'Taille de la pièce',
    'eclairage' => 'Type d\'éclairage',
    'sortie'    => 'Sortie électrique',
    'hauteur'   => 'Hauteur sous-plafond',
    'table'     => 'Au-dessus d\'une table',
    'style'     => 'Style intérieur',
  ];
  foreach ($labels as $key => $label) {
    $val = isset($answers[$key]) ? $answers[$key] : 'Non demandé';
    $prompt .= '- ' . $label . ' : ' . $val . "\n";
  }

  // Filter context from first round
  $prompt .= "\nFILTRES APPLIQUÉS LORS DE LA PREMIÈRE RECOMMANDATION :\n";
  $prompt .= $filter_context . "\n";

  // Currently displayed products
  $prompt .= "\nPRODUITS ACTUELLEMENT AFFICHÉS AU CLIENT (IDs) : " . implode(', ', $current_product_ids) . "\n";

  // Response format
  $prompt .= "\nFORMAT DE RÉPONSE (JSON strict, sans commentaires) :\n";
  $prompt .= "{\n";
  $prompt .= '  "action": "refine" | "contact" | "both",' . "\n";
  $prompt .= '  "recommendation": "Texte de réponse pour le client (max 80 mots)...",' . "\n";
  $prompt .= '  "product_ids": [{"product_id": 123, "variation_id": 456}, {"product_id": 789}]' . "\n";
  $prompt .= "}\n";
  $prompt .= "Note : product_ids est obligatoire si action = \"refine\" ou \"both\". Vide [] si action = \"contact\".\n";
  $prompt .= "Chaque entrée de product_ids DOIT contenir un product_id. Le variation_id est optionnel : utilise-le pour recommander une essence/taille spécifique. Si omis, la variation recommandée initialement sera utilisée.\n";

  return $prompt;
}

/**
 * ── Refinement: Call Claude API with conversation history ──
 * Similar to sapi_guide_call_claude() but supports multi-turn messages.
 */
function sapi_guide_call_claude_refine($system_prompt, array $messages) {
  $api_key = defined('ANTHROPIC_API_KEY') ? ANTHROPIC_API_KEY : '';
  if (empty($api_key)) {
    return null;
  }

  $body = [
    'model'      => 'claude-sonnet-4-6',
    'max_tokens' => 1024,
    'system'     => $system_prompt,
    'messages'   => $messages,
  ];

  $response = wp_remote_post('https://api.anthropic.com/v1/messages', [
    'timeout' => 30,
    'headers' => [
      'Content-Type'      => 'application/json',
      'x-api-key'         => $api_key,
      'anthropic-version' => '2023-06-01',
    ],
    'body' => wp_json_encode($body),
  ]);

  if (is_wp_error($response)) {
    error_log('Sapi Guide Refine API error: ' . $response->get_error_message());
    return null;
  }

  $status   = wp_remote_retrieve_response_code($response);
  $raw_body = wp_remote_retrieve_body($response);

  if ($status !== 200) {
    error_log('Sapi Guide Refine API HTTP ' . $status . ': ' . $raw_body);
    return null;
  }

  $data = json_decode($raw_body, true);
  if (!isset($data['content'][0]['text'])) {
    return null;
  }

  $text = $data['content'][0]['text'];

  // Clean markdown code fences if present
  $text = preg_replace('/^```json\s*/i', '', trim($text));
  $text = preg_replace('/\s*```$/i', '', $text);

  $parsed = json_decode(trim($text), true);
  if (!$parsed || !isset($parsed['action'])) {
    // Fallback: treat as contact intent with raw text
    return [
      'action'         => 'contact',
      'recommendation' => $text,
      'product_ids'    => [],
    ];
  }

  return $parsed;
}

/**
 * Custom product search endpoint with metadata support
 * Searches in: title, content, dimensions, wood type, price
 */
function sapi_register_product_search_endpoint() {
  register_rest_route('sapi/v1', '/products/search', [
    'methods' => 'GET',
    'callback' => 'sapi_product_search',
    'permission_callback' => '__return_true',
    'args' => [
      'query' => [
        'required' => true,
        'type' => 'string',
        'sanitize_callback' => 'sanitize_text_field',
      ],
    ],
  ]);
}
add_action('rest_api_init', 'sapi_register_product_search_endpoint');

function sapi_product_search($request) {
  if (!function_exists('wc_get_product')) return new WP_REST_Response([], 200); // garde WC (endpoint public)
  $query_string = $request->get_param('query');

  if (empty($query_string) || strlen($query_string) < 2) {
    return new WP_REST_Response([], 200);
  }

  $query_lower = strtolower($query_string);
  $results = [];

  // Get ALL published products to filter client-side
  $all_products_args = [
    'post_type' => 'product',
    'post_status' => 'publish',
    'posts_per_page' => -1, // Get all products
    'tax_query' => [
      [
        'taxonomy' => 'product_cat',
        'field' => 'slug',
        'terms' => ['accessoires'], // Exclude accessories category
        'operator' => 'NOT IN',
      ],
    ],
  ];

  $all_products = new WP_Query($all_products_args);
  $is_numeric_search = is_numeric($query_string);
  $dimension_search = $is_numeric_search ? floatval($query_string) : 0;

  if ($all_products->have_posts()) {
    while ($all_products->have_posts()) {
      $all_products->the_post();
      $product_id = get_the_ID();
      $product = wc_get_product($product_id);

      if (!$product) continue;

      $title = strtolower($product->get_name());
      $score = 0;

      // 1. Search in product title (highest priority)
      if (stripos($title, $query_lower) !== false) {
        $score += 100;
      }

      // 2. Search in wood type (materiau)
      $wood_essence = get_field('essence_de_bois', $product_id);
      if (!$wood_essence) {
        // Try pa_materiau attribute (used in variations)
        $wood_attr = $product->get_attribute('pa_materiau');
        if ($wood_attr) {
          $wood_essence = strtolower($wood_attr);
        }
      }
      if ($wood_essence && stripos(strtolower($wood_essence), $query_lower) !== false) {
        $score += 50;
      }

      // 3. Search in dimensions if numeric query
      if ($is_numeric_search && $dimension_search > 0) {
        // Try ACF field first
        $acf_dimension = get_field('hauteur_cm', $product_id);
        if ($acf_dimension) {
          $dim = floatval($acf_dimension);
          // ±20cm tolerance
          if ($dim >= ($dimension_search - 20) && $dim <= ($dimension_search + 20)) {
            $score += 75;
          }
        } else {
          // Try pa_taille attribute (e.g., "50-cm", "100-cm", "130-cm")
          $taille_attr = $product->get_attribute('pa_taille');
          if ($taille_attr) {
            // Extract numeric value from "XXX-cm" format
            preg_match('/(\d+)/', $taille_attr, $matches);
            if (!empty($matches[1])) {
              $dim = floatval($matches[1]);
              // ±20cm tolerance
              if ($dim >= ($dimension_search - 20) && $dim <= ($dimension_search + 20)) {
                $score += 75;
              }
            }
          } else {
            // Fallback to WooCommerce dimensions
            $height = floatval($product->get_height());
            $width = floatval($product->get_width());
            $length = floatval($product->get_length());
            $max_dim = max($height, $width, $length);

            if ($max_dim > 0 && $max_dim >= ($dimension_search - 20) && $max_dim <= ($dimension_search + 20)) {
              $score += 75;
            }
          }
        }
      }

      // 4. Search in categories
      $categories = get_the_terms($product_id, 'product_cat');
      if ($categories && !is_wp_error($categories)) {
        foreach ($categories as $cat) {
          if (stripos(strtolower($cat->name), $query_lower) !== false) {
            $score += 25;
          }
        }
      }

      // Only include products with a score > 0
      if ($score > 0) {
        $results[] = [
          'score' => $score,
          'data' => sapi_format_product_for_search($product),
        ];
      }
    }
    wp_reset_postdata();
  }

  // Sort by score (highest first)
  usort($results, function($a, $b) {
    return $b['score'] - $a['score'];
  });

  // Extract just the product data and limit to 8 results
  $final_results = array_slice(array_map(function($item) {
    return $item['data'];
  }, $results), 0, 8);

  return new WP_REST_Response($final_results, 200);
}

function sapi_format_product_for_search($product) {
  $product_id = $product->get_id();

  // Get categories
  $categories = get_the_terms($product_id, 'product_cat');
  $category_names = [];
  if ($categories && !is_wp_error($categories)) {
    foreach ($categories as $cat) {
      if ($cat->slug !== 'uncategorized') {
        $category_names[] = $cat->name;
      }
    }
  }

  // Get image
  $image_id = $product->get_image_id();
  $image_url = $image_id ? wp_get_attachment_image_url($image_id, 'woocommerce_thumbnail') : '';

  // Get metadata
  $wood_essence = get_field('essence_de_bois', $product_id);
  $dimensions = get_field('hauteur_cm', $product_id);

  return [
    'id' => $product_id,
    'title' => $product->get_name(),
    'link' => get_permalink($product_id),
    'image' => $image_url,
    'price' => $product->get_price_html(),
    'categories' => $category_names,
    'wood' => $wood_essence,
    'dimensions' => $dimensions,
  ];
}

/**
 * NOTE : la case opt-in newsletter du checkout (enregistrement du champ +
 * sauvegarde de la meta _sapi_newsletter_optin) ainsi que toutes les syncs
 * Brevo liées aux commandes (#6 Newsletter + #12 Commande récente) ont été
 * migrées vers le snippet Code Snippets « sapi-brevo-sync-commandes »
 * (voir snippet-brevo-sync-commandes.php). functions.php ne garde ici que
 * la sauvegarde de la note client et de la meta opt-in au retry paiement. — 2026-06-02
 */

// Sauvegarder la note et l'opt-in newsletter depuis la page Order Pay.
// La sync Brevo correspondante est gérée par le snippet « sapi-brevo-sync-commandes »
// (hook woocommerce_before_pay_action priorité 25, après ce handler).
add_action('woocommerce_before_pay_action', function ($order) {
  // Note de commande
  if (! empty($_POST['sapi_order_note'])) {
    $note = sanitize_textarea_field(wp_unslash($_POST['sapi_order_note']));
    if ($note) {
      $order->add_order_note(esc_html($note), 1); // 1 = note client
      $order->set_customer_note($note);
    }
  }
  // Newsletter opt-in
  if (! empty($_POST['sapi_newsletter_optin'])) {
    $order->update_meta_data('_sapi_newsletter_optin', 'yes');
  }
  $order->save();
  // La sync Brevo #6 au retry est déclenchée par le snippet
  // « sapi-brevo-sync-commandes » (même hook, priorité 25).
});

/**
 * ============================================================
 * NEWSLETTER BREVO — AJAX subscription
 * ============================================================
 */
add_action('wp_ajax_sapi_newsletter_subscribe', 'sapi_newsletter_subscribe');
add_action('wp_ajax_nopriv_sapi_newsletter_subscribe', 'sapi_newsletter_subscribe');

function sapi_newsletter_subscribe() {
    check_ajax_referer('sapi_newsletter_nonce', 'nonce');

    // Honeypot
    if (!empty($_POST['website'])) {
        wp_send_json_error(['message' => 'Spam détecté.']);
    }

    // Rate limiting (5 inscriptions/heure par IP)
    if (!sapi_check_form_rate_limit('newsletter')) {
        wp_send_json_error(['message' => 'Trop de tentatives. Réessayez plus tard.']);
    }

    $email = isset($_POST['email']) ? sanitize_email($_POST['email']) : '';
    if (!is_email($email)) {
        wp_send_json_error(['message' => 'Adresse email invalide.']);
    }

    $api_key = defined('BREVO_API_KEY') ? BREVO_API_KEY : '';
    if (!$api_key) {
        wp_send_json_error(['message' => 'Configuration newsletter manquante.']);
    }

    $response = wp_remote_post('https://api.brevo.com/v3/contacts', [
        'headers' => [
            'api-key'      => $api_key,
            'Content-Type' => 'application/json',
            'Accept'       => 'application/json',
        ],
        'body' => wp_json_encode([
            'email'            => $email,
            'listIds'          => [6],
            'updateEnabled'    => true,
            'attributes'       => ['SOURCE' => 'homepage'],
        ]),
        'timeout' => 15,
    ]);

    if (is_wp_error($response)) {
        wp_send_json_error(['message' => 'Erreur de connexion. Réessayez plus tard.']);
    }

    $code = wp_remote_retrieve_response_code($response);

    if ($code === 201 || $code === 204) {
        wp_send_json_success(['message' => 'Bienvenue dans la famille Sâpi !']);
    }

    $body = json_decode(wp_remote_retrieve_body($response), true);

    if ($code === 400 && isset($body['message']) && strpos($body['message'], 'already exist') !== false) {
        wp_send_json_success(['message' => 'Vous êtes déjà inscrit(e) !']);
    }

    wp_send_json_error(['message' => 'Une erreur est survenue. Réessayez plus tard.']);
}

/**
 * ============================================================
 * CUSTOM POST TYPE : Projets Sur Mesure
 * ============================================================
 */
function sapi_register_cpt_projet_sur_mesure() {
  register_post_type('projet_sur_mesure', [
    'labels' => [
      'name'               => 'Projets Sur Mesure',
      'singular_name'      => 'Projet Sur Mesure',
      'add_new'            => 'Ajouter un projet',
      'add_new_item'       => 'Ajouter un projet sur mesure',
      'edit_item'          => 'Modifier le projet',
      'new_item'           => 'Nouveau projet',
      'view_item'          => 'Voir le projet',
      'search_items'       => 'Rechercher un projet',
      'not_found'          => 'Aucun projet trouvé',
      'not_found_in_trash' => 'Aucun projet dans la corbeille',
    ],
    'public'        => false,
    'show_ui'       => true,
    'show_in_menu'  => true,
    'menu_position' => 25,
    'menu_icon'     => 'dashicons-lightbulb',
    'supports'      => ['title', 'editor', 'thumbnail', 'revisions'],
    'has_archive'   => false,
    'rewrite'       => false,
  ]);
}
add_action('init', 'sapi_register_cpt_projet_sur_mesure');

/**
 * ACF fields for Projets Sur Mesure
 */
function sapi_register_acf_projet_sur_mesure() {
  if (!function_exists('acf_add_local_field_group')) return;

  acf_add_local_field_group([
    'key'      => 'group_projet_sur_mesure',
    'title'    => 'Détails du projet',
    'fields'   => [
      [
        'key'           => 'field_psm_type_client',
        'label'         => 'Type de projet',
        'name'          => 'type_client',
        'type'          => 'button_group',
        'choices'       => [
          'particulier'   => 'Particulier',
          'professionnel' => 'Professionnel',
        ],
        'default_value' => 'particulier',
        'layout'        => 'horizontal',
        'instructions'  => 'Permettra de filtrer l\'affichage Pro / Particulier sur le site.',
      ],
      [
        'key'          => 'field_psm_sous_titre',
        'label'        => 'Sous-titre',
        'name'         => 'sous_titre',
        'type'         => 'text',
        'placeholder'  => 'Ex : Restaurant gastronomique à Lyon',
        'instructions' => 'Courte accroche affichée en gras au début de la description dans la modale (optionnel)',
      ],
      [
        'key'   => 'field_psm_essence',
        'label' => 'Essence de bois',
        'name'  => 'essence_bois',
        'type'  => 'text',
        'placeholder' => 'Ex: Peuplier, Okoumé, Chêne...',
      ],
      [
        'key'   => 'field_psm_dimensions',
        'label' => 'Dimensions',
        'name'  => 'dimensions_projet',
        'type'  => 'text',
        'placeholder' => 'Ex: 60 x 40 cm',
      ],
      [
        'key'   => 'field_psm_piece',
        'label' => 'Pièce de destination',
        'name'  => 'piece_destination',
        'type'  => 'text',
        'placeholder' => 'Ex: Salon, Chambre, Restaurant...',
      ],
      [
        'key'   => 'field_psm_temoignage',
        'label' => 'Témoignage client',
        'name'  => 'temoignage_client',
        'type'  => 'textarea',
        'rows'  => 3,
        'instructions' => 'Citation du client (optionnel)',
      ],
      [
        'key'   => 'field_psm_nom_client',
        'label' => 'Nom du client',
        'name'  => 'nom_client',
        'type'  => 'text',
        'placeholder' => 'Ex: Marie, Restaurant Le Comptoir...',
        'instructions' => 'Prénom ou nom affiché sous le témoignage (optionnel)',
      ],
      [
        'key'          => 'field_psm_photo_2',
        'label'        => 'Photo 2',
        'name'         => 'photo_2',
        'type'         => 'image',
        'return_format' => 'array',
        'preview_size'  => 'medium',
        'instructions'  => 'Photo supplémentaire (optionnel)',
      ],
      [
        'key'          => 'field_psm_photo_3',
        'label'        => 'Photo 3',
        'name'         => 'photo_3',
        'type'         => 'image',
        'return_format' => 'array',
        'preview_size'  => 'medium',
        'instructions'  => 'Photo supplémentaire (optionnel)',
      ],
      [
        'key'          => 'field_psm_photo_4',
        'label'        => 'Photo 4',
        'name'         => 'photo_4',
        'type'         => 'image',
        'return_format' => 'array',
        'preview_size'  => 'medium',
        'instructions'  => 'Photo supplémentaire (optionnel)',
      ],
    ],
    'location' => [
      [
        [
          'param'    => 'post_type',
          'operator' => '==',
          'value'    => 'projet_sur_mesure',
        ],
      ],
    ],
    'position' => 'normal',
    'style'    => 'default',
  ]);
}
add_action('acf/init', 'sapi_register_acf_projet_sur_mesure');

// Notice admin : limite de 6 projets affichés sur le site
function sapi_projet_sur_mesure_admin_notice() {
  $screen = get_current_screen();
  if (!$screen || $screen->post_type !== 'projet_sur_mesure') return;

  if ($screen->base === 'edit') {
    $count = wp_count_posts('projet_sur_mesure')->publish;
    $msg = 'Seuls les <strong>6 projets les plus récents</strong> sont affichés sur la page Sur Mesure du site.';
    if ($count > 6) {
      $hidden = $count - 6;
      $msg .= ' ' . $hidden . ' projet' . ($hidden > 1 ? 's ne sont pas visibles' : ' n\'est pas visible') . ' actuellement.';
    }
    echo '<div class="notice notice-info"><p>' . $msg . '</p></div>';
  }
}
add_action('admin_notices', 'sapi_projet_sur_mesure_admin_notice');

// Colonne admin "Type" dans la liste des projets sur mesure
function sapi_psm_admin_columns($columns) {
  $new = [];
  foreach ($columns as $key => $label) {
    $new[$key] = $label;
    if ($key === 'title') {
      $new['type_client'] = 'Type';
    }
  }
  return $new;
}
add_filter('manage_projet_sur_mesure_posts_columns', 'sapi_psm_admin_columns');

function sapi_psm_admin_column_content($column, $post_id) {
  if ($column !== 'type_client') return;
  $type = get_field('type_client', $post_id);
  $labels = ['particulier' => 'Particulier', 'professionnel' => 'Pro'];
  echo esc_html($labels[$type] ?? 'Particulier');
}
add_action('manage_projet_sur_mesure_posts_custom_column', 'sapi_psm_admin_column_content', 10, 2);

/*
 * ACF fields for Product media (video + photo gallery repeater)
 * Created MANUALLY via ACF Pro UI — not registered in PHP.
 *
 * Field names expected by the template:
 *   - video_produit     (oEmbed)       → URL YouTube/Vimeo
 *   - galerie_produit   (Repeater)     → Photos supplémentaires
 *     ├─ type_photo     (Select)       → ambiance / detail / taille / client / fabrication
 *     └─ image          (Image, array) → Photo
 *
 * Location: Post Type = product
 */

/**
 * Handle "Sur Mesure" contact form submission
 */
function sapi_handle_surmesure_form() {
  if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !isset($_POST['sapi_surmesure_nonce'])) {
    return ['submitted' => false, 'success' => false, 'error' => ''];
  }

  if (!wp_verify_nonce($_POST['sapi_surmesure_nonce'], 'sapi_surmesure_form')) {
    return ['submitted' => true, 'success' => false, 'error' => 'Erreur de sécurité. Veuillez réessayer.'];
  }

  // Honeypot
  if (!empty($_POST['website'])) {
    return ['submitted' => true, 'success' => false, 'error' => 'Spam détecté.'];
  }

  // Time-trap signé (trop rapide / signature invalide → traité comme le honeypot)
  if (!sapi_time_trap_valid($_POST['sapi_ts'] ?? 0, $_POST['sapi_tsig'] ?? '')) {
    return ['submitted' => true, 'success' => false, 'error' => 'Spam détecté.'];
  }

  // Rate limiting (5 soumissions/heure par IP)
  if (!sapi_check_form_rate_limit('surmesure')) {
    return ['submitted' => true, 'success' => false, 'error' => 'Trop de messages envoyés. Réessayez plus tard.'];
  }

  $name    = sanitize_text_field($_POST['fullname'] ?? '');
  $email   = sanitize_email($_POST['email'] ?? '');
  $message = sanitize_textarea_field($_POST['message'] ?? '');

  // Filtre anti-junk (message requis sur ce formulaire)
  if (sapi_is_junk_contact($email, $message, true)) {
    return ['submitted' => true, 'success' => false, 'error' => 'Spam détecté.'];
  }

  if (empty($name) || empty($email) || empty($message)) {
    return ['submitted' => true, 'success' => false, 'error' => 'Veuillez remplir tous les champs.'];
  }

  if (!is_email($email)) {
    return ['submitted' => true, 'success' => false, 'error' => 'Adresse email invalide.'];
  }

  // Inscription à la liste Brevo #6 (newsletter) avec SOURCE=surmesure, source
  // dédiée au formulaire de la page /sur-mesure/ (distincte de 'conseiller').
  // On n'envoie QUE l'attribut SOURCE : ajouter nom/message ferait rejeter tout
  // l'ajout par Brevo (400). Non bloquant : l'email de notif ci-dessous continue.
  $api_key = defined('BREVO_API_KEY') ? BREVO_API_KEY : '';
  if ($api_key) {
    $brevo_response = wp_remote_post('https://api.brevo.com/v3/contacts', [
      'headers' => [
        'api-key'      => $api_key,
        'Content-Type' => 'application/json',
        'Accept'       => 'application/json',
      ],
      'body' => wp_json_encode([
        'email'         => $email,
        'listIds'       => [6],
        'attributes'    => ['SOURCE' => 'surmesure'],
        'updateEnabled' => true,
      ]),
      'timeout' => 15,
    ]);

    if (is_wp_error($brevo_response)) {
      error_log('[sapi-brevo-surmesure] Erreur HTTP /sur-mesure/ (' . $email . ') : ' . $brevo_response->get_error_message());
    } else {
      $brevo_code = wp_remote_retrieve_response_code($brevo_response);
      if ($brevo_code < 200 || $brevo_code >= 300) {
        error_log('[sapi-brevo-surmesure] Brevo a répondu ' . $brevo_code . ' /sur-mesure/ (' . $email . ') : ' . wp_remote_retrieve_body($brevo_response));
      }
    }
  }

  $to      = 'contact@atelier-sapi.fr';
  $subject = '[Sur Mesure] Nouveau projet de ' . $name;
  $body    = "Nom: $name\n";
  $body   .= "Email: $email\n\n";
  // Projet Robin (si existant)
  $robin_project = sanitize_textarea_field($_POST['robin_project'] ?? '');
  if (!empty($robin_project)) {
    $body .= "Projet du client (questionnaire):\n$robin_project\n\n";
  }

  $body   .= "Projet sur mesure:\n$message";

  $headers = [
    'Content-Type: text/plain; charset=UTF-8',
    'From: ' . get_bloginfo('name') . ' <noreply@' . wp_parse_url(home_url(), PHP_URL_HOST) . '>',
    'Reply-To: ' . $name . ' <' . $email . '>',
  ];

  if (wp_mail($to, $subject, $body, $headers)) {
    return ['submitted' => true, 'success' => true, 'error' => ''];
  }

  return ['submitted' => true, 'success' => false, 'error' => "Erreur lors de l'envoi. Veuillez réessayer ou contacter Robin directement par email."];
}

/* ═══════════════════════════════════════════════════════════
   CONSEILLER V3 — Sessions tracking (table + endpoint + admin)
   Refonte complète : drop ancienne table V2, nouveau schéma adapté
   au parcours V3 (sapiProject + chat + advice + contact), endpoint
   UPSERT par session_id, page admin avec dashboard + drill-down.
═══════════════════════════════════════════════════════════ */

/**
 * Crée la table sessions V3.
 */
function sapi_megafilter_create_sessions_table() {
  global $wpdb;
  $table = $wpdb->prefix . 'sapi_megafilter_sessions';
  $charset = $wpdb->get_charset_collate();

  /* ⚠️ AUCUN COMMENTAIRE À L'INTÉRIEUR DE CETTE CHAÎNE. `dbDelta` découpe le
     contenu des parenthèses ligne par ligne et traite CHAQUE ligne comme une
     définition de colonne : il essaierait de créer des colonnes nommées `/*`,
     `(salle`, `pour`… Les ALTER échouent, et avec l'affichage des erreurs
     activé, MySQL les imprime en haut de la page publique.
     Ce que portent les colonnes non évidentes :
     - `piece_hors_perimetre` : le mot du visiteur quand l'IA juge la pièce hors
       catalogue (salle de bain, garage…). Jamais pour une pièce simplement
       rapprochée d'une autre : « couloir » devient « entrée », ce n'est pas
       hors périmètre.
     - `table_reponse`, `ai_freetext_used`, `matching_product_ids`,
       `ip_address`, `contact_email` : conservées, plus alimentées (voir les
       notes aux endroits où l'écriture a été retirée). */
  $sql = "CREATE TABLE $table (
    id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
    session_id VARCHAR(36) NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    entry_point VARCHAR(30) DEFAULT '',
    entry_url VARCHAR(500) DEFAULT '',
    referrer VARCHAR(500) DEFAULT '',

    piece VARCHAR(50) DEFAULT '',
    taille VARCHAR(50) DEFAULT '',
    taille_escalier VARCHAR(50) DEFAULT '',
    eclairage VARCHAR(50) DEFAULT '',
    sortie VARCHAR(50) DEFAULT '',
    hauteur VARCHAR(50) DEFAULT '',
    table_reponse VARCHAR(50) DEFAULT '',
    style VARCHAR(50) DEFAULT '',
    answers_completed TINYINT(1) DEFAULT 0,

    ai_freetext_used TINYINT(1) DEFAULT 0,
    ai_freetext_input TEXT DEFAULT NULL,
    ai_chat_used TINYINT(1) DEFAULT 0,
    ai_chat_messages MEDIUMTEXT DEFAULT NULL,
    ai_call_count INT(11) DEFAULT 0,
    advice_text TEXT DEFAULT NULL,

    matching_product_ids TEXT DEFAULT NULL,

    contact_triggered TINYINT(1) DEFAULT 0,
    piece_hors_perimetre VARCHAR(60) DEFAULT '',
    contact_kind VARCHAR(20) DEFAULT '',
    contact_subject VARCHAR(200) DEFAULT '',
    contact_message TEXT DEFAULT NULL,
    contact_email VARCHAR(100) DEFAULT '',
    contact_submitted TINYINT(1) DEFAULT 0,
    contact_submitted_at DATETIME DEFAULT NULL,

    device_type VARCHAR(40) DEFAULT '',
    ip_address VARCHAR(45) DEFAULT '',
    location VARCHAR(200) DEFAULT '',

    PRIMARY KEY (id),
    UNIQUE KEY session_id (session_id),
    KEY created_at (created_at),
    KEY entry_point (entry_point),
    KEY piece (piece)
  ) $charset;";

  require_once ABSPATH . 'wp-admin/includes/upgrade.php';
  dbDelta($sql);
}
add_action('after_switch_theme', 'sapi_megafilter_create_sessions_table');

function sapi_megafilter_maybe_create_table() {
  global $wpdb;
  $table = $wpdb->prefix . 'sapi_megafilter_sessions';
  if ($wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table)) !== $table) {
    sapi_megafilter_create_sessions_table();
  }
}

/* Version du schéma. À INCRÉMENTER à chaque colonne ajoutée au CREATE TABLE.
   1 → ajout de `piece_hors_perimetre` (28/08). */
if (!defined('SAPI_SESSIONS_DB_VERSION')) define('SAPI_SESSIONS_DB_VERSION', '1');

/**
 * Applique au schéma existant les colonnes ajoutées depuis.
 *
 * ⚠️ SANS ÇA, UNE COLONNE AJOUTÉE N'EXISTE JAMAIS EN BASE. `dbDelta` n'était
 * appelé que par `sapi_megafilter_create_sessions_table()`, elle-même appelée
 * uniquement quand la table est ABSENTE (et au changement de thème). Sur un
 * site déjà installé, ajouter une colonne au `CREATE TABLE` ne produisait donc
 * rien du tout — et l'`INSERT` qui la mentionne échoue en silence : `$wpdb`
 * renvoie `false`, la session n'est pas enregistrée, et personne ne l'apprend.
 * On aurait perdu toutes les sessions jusqu'à ce que quelqu'un s'en aperçoive.
 *
 * ⚠️ SUR `init`, PAS `admin_init`. Les sessions sont écrites depuis le site
 * public : si la migration n'attendait qu'une visite de l'admin, un visiteur
 * arrivé avant Robin tomberait précisément sur l'`INSERT` cassé.
 * Le contrôle ne coûte rien : `get_option` sur une option autochargée.
 */
function sapi_megafilter_maybe_upgrade_table() {
  if (get_option('sapi_megafilter_db_version') === SAPI_SESSIONS_DB_VERSION) return;
  global $wpdb;
  $table = $wpdb->prefix . 'sapi_megafilter_sessions';
  if ($wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table)) !== $table) return;
  sapi_megafilter_create_sessions_table(); // dbDelta ajoute les colonnes manquantes

  /* ⚠️ ON VÉRIFIE AVANT DE DIRE QUE C'EST FAIT. `dbDelta` ne lève jamais
     d'erreur : il renvoie une liste de messages, et un ALTER refusé (droits,
     table verrouillée) passe inaperçu. Poser la version sans contrôler
     reviendrait à graver un échec pour toujours — la migration ne serait plus
     jamais retentée, et l'INSERT qui mentionne la colonne échouerait à chaque
     session, en silence. C'est précisément ce que cette fonction existe pour
     empêcher : elle ne doit pas rater de la même façon. */
  $colonne = $wpdb->get_var($wpdb->prepare(
    "SHOW COLUMNS FROM $table LIKE %s", 'piece_hors_perimetre'
  ));
  if ($colonne) {
    update_option('sapi_megafilter_db_version', SAPI_SESSIONS_DB_VERSION, true);
  } else {
    error_log('Sapi : la colonne piece_hors_perimetre n\'a pas pu être ajoutée. '
      . 'Les sessions ne seront pas enregistrées tant que ce n\'est pas réglé. '
      . 'Nouvel essai à la prochaine requête.');
  }
}
add_action('init', 'sapi_megafilter_maybe_upgrade_table');

/**
 * Migration idempotente V2 → V3 : DROP ancienne table sapi_robin_sessions
 * (plus alimentée depuis F1c, suppression robin-conseiller.js) puis CREATE
 * sapi_megafilter_sessions. Idempotent via une option WP qui marque la
 * migration comme effectuée.
 */
function sapi_megafilter_migrate_v3() {
  if (get_option('sapi_megafilter_sessions_v3_migrated') === 'yes') return;
  global $wpdb;
  $old_table = $wpdb->prefix . 'sapi_robin_sessions';
  $wpdb->query("DROP TABLE IF EXISTS $old_table");
  sapi_megafilter_create_sessions_table();
  update_option('sapi_megafilter_sessions_v3_migrated', 'yes', true);
}
add_action('admin_init', 'sapi_megafilter_migrate_v3');

/**
 * Efface, UNE FOIS, les adresses IP et e-mails déjà enregistrés.
 *
 * ⚠️ REMPLACE la migration de troncature du 26/08, devenue sans objet : il ne
 * s'agit plus de réduire ces données mais de ne plus les détenir du tout
 * (décision Robin du 27/08 : « des données pour faire des statistiques, pas
 * pour identifier »).
 *
 * Sans cette passe, la mise en conformité serait cosmétique : les nouvelles
 * lignes seraient propres et la table continuerait de garder des mois d'IP
 * tronquées et d'adresses e-mail. Ce sont les anciennes qui posent le problème.
 *
 * Irréversible, et c'est le but. Les colonnes restent — on ne supprime pas une
 * colonne sans nécessité — mais elles sont vidées et ne sont plus alimentées.
 *
 * ⚠️ L'option n'est posée qu'en cas de succès : une opération unique et
 * irréversible ne doit jamais se déclarer faite sur un échec, sinon plus rien
 * ne repassera jamais.
 */
function sapi_megafilter_effacer_donnees_nominatives() {
  if (get_option('sapi_megafilter_donnees_effacees') === 'yes') return;
  global $wpdb;
  $table = $wpdb->prefix . 'sapi_megafilter_sessions';
  $r = $wpdb->query(
    "UPDATE $table SET ip_address = '', contact_email = ''
      WHERE ip_address <> '' OR contact_email <> ''"
  );
  if ($r !== false) {
    update_option('sapi_megafilter_donnees_effacees', 'yes', true);
  } else {
    error_log('Sapi : effacement des IP et e-mails incomplet, sera retenté au prochain passage en admin.');
  }
}
add_action('admin_init', 'sapi_megafilter_effacer_donnees_nominatives');

/**
 * Endpoint AJAX V3 — UPSERT par session_id. Appelé via navigator.sendBeacon
 * depuis sapi-modal-conseiller.js aux moments clés (ouverture, transition
 * d'écran, fermeture, submit contact). Payload accepté en JSON via
 * php://input (sendBeacon) ou en POST classique en fallback.
 */
add_action('wp_ajax_sapi_megafilter_log_session', 'sapi_megafilter_log_session');
add_action('wp_ajax_nopriv_sapi_megafilter_log_session', 'sapi_megafilter_log_session');

/**
 * Marque une session comme ayant RÉELLEMENT abouti à un mail reçu par Robin.
 *
 * Appelée uniquement depuis `sapi_ajax_megafilter_surmesure`, après un
 * `wp_mail()` réussi. C'est le seul endroit du code qui connaisse la vérité :
 * les rejets anti-spam répondent « success » en silence, donc le navigateur ne
 * peut pas la connaître.
 *
 * UPDATE seulement, jamais d'INSERT : si la session n'existe pas (modale
 * jamais ouverte, formulaire atteint autrement), on ne crée pas de ligne
 * fantôme. Zéro ligne affectée est un résultat normal, pas une erreur.
 */
/**
 * Affiche une date de la table des sessions, TELLE QU'ELLE EST STOCKÉE.
 *
 * ⚠️ NE PAS REMPLACER PAR `wp_date()`. WordPress force PHP en UTC, donc `date()`
 * restitue exactement la chaîne enregistrée, tandis que `wp_date()` la
 * reconvertit vers le fuseau du site et AJOUTE deux heures à une valeur déjà
 * locale. Les deux cohabitaient : le tableau utilisait `date()`, le détail
 * `wp_date()`. La même session s'affichait « 26/08 · 14:03 » dans la liste et
 * « 26/08/2026 à 16:03 » dans son propre détail, et un contact envoyé deux
 * minutes après la création apparaissait deux heures après, parfois dans le
 * futur.
 *
 * TRANCHÉ LE 28/08, ET LE CONTRAIRE DE CE QU'ON CROIT D'ABORD. `created_at`
 * vient de l'horloge MySQL (`DEFAULT CURRENT_TIMESTAMP`), `contact_submitted_at`
 * de celle de WordPress (`current_time('mysql')`). On ne touche à rien, pour
 * deux raisons :
 *   1. aligner le STOCKAGE créerait une frontière entre les lignes d'avant et
 *      d'après, pire qu'un écart qui n'existe pas aujourd'hui ;
 *   2. aligner la LECTURE serait une erreur. Les fenêtres de période
 *      interrogent `created_at`, donc elles doivent rester sur `NOW()` :
 *      comparer une colonne MySQL à une horloge MySQL est juste quoi qu'il
 *      arrive, tandis qu'une borne calculée par WordPress serait juste
 *      seulement tant que les deux machines coïncident. J'ai fait ce
 *      changement puis je l'ai défait — il désalignait ce qu'il prétendait
 *      unifier.
 * Ce qui manquait n'était donc pas un correctif mais une alerte : un bandeau
 * sur la page d'admin prévient si les deux horloges cessent d'être d'accord.
 */
/**
 * Les libellés du tableau de bord, CONSTRUITS DEPUIS LE QUESTIONNAIRE.
 *
 * ⚠️ Ils étaient recopiés à la main, en deux exemplaires (liste et détail), et
 * ils avaient dérivé. Sur sept clés, quatre affichaient un repli `ucfirst` au
 * lieu du vrai libellé, et douze entrées désignaient des slugs qui n'existent
 * plus : `standard`/`intime`/`spacieuse`/`droit`/`tournant` pour la taille,
 * `aucune`/`inconnu` pour la sortie, `haut`/`tres_haut` pour la hauteur,
 * `appoint` pour l'éclairage. Robin lisait « Pas-de-sortie » et « Ne-sais-pas »
 * là où il aurait dû lire des phrases, et « Standard (2,50 m) » sous un choix
 * qui dit en réalité « moins de 2,50 m ».
 *
 * En les dérivant de `sapi_guide_get_steps()` — la source qui sert à POSER les
 * questions — la divergence devient impossible, et un nouveau choix ajouté au
 * questionnaire apparaît tout seul dans le tableau.
 *
 * @return array [stepId => [slug => libellé]]
 */
/**
 * Tronque une adresse IP. Ce n'est plus un anonymiseur de stockage.
 *
 * IPv4 : le dernier octet devient 0 (`77.196.117.198` → `77.196.117.0`).
 * IPv6 : on ne garde que le préfixe /48, les trois premiers groupes.
 *
 * ⚠️ SON RÔLE A CHANGÉ LE 28/08. Elle servait à ranger une IP amputée dans la
 * table ; depuis, **plus aucune IP n'est stockée**. Il lui reste deux emplois,
 * tous deux des transmissions à un tiers :
 *   1. l'adresse envoyée au fournisseur de géolocalisation ;
 *   2. la ligne « IP » du mail envoyé à Robin, qui reste des années dans sa
 *      boîte alors que la table, elle, purge à 425 jours.
 * Dans les deux cas on transmet le strict nécessaire : de quoi situer une
 * ville, pas de quoi désigner une personne. C'est la recommandation de la
 * CNIL. Ne pas la supprimer en croyant qu'elle est devenue inutile.
 */
function sapi_tronquer_ip($ip) {
  $ip = is_string($ip) ? trim($ip) : '';
  if ($ip === '') return '';
  if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
    $o = explode('.', $ip);
    $o[3] = '0';
    return implode('.', $o);
  }
  if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
    /* ⚠️ ON OPÈRE SUR LES OCTETS, JAMAIS SUR LA CHAÎNE.
       J'avais d'abord découpé sur « : » et gardé trois groupes. C'est faux, à
       cause de la forme compressée :
         `2a01::5678`  → « 2a01::5678:: » : l'adresse ENTIÈRE conservée, sous
                          un nom qui prétend le contraire ;
         `2001:db8::1` → « 2001:db8::: » : même pas une IPv6 valide.
       `SUBSTRING_INDEX` sur une chaîne qui contient moins de trois « : »
       renvoie la chaîne entière — MySQL ne tronque rien, il ajoute juste « :: ».
       Sur seize octets, il n'y a plus d'ambiguïté : on garde les six premiers
       (le /48) et on met le reste à zéro. */
    $bin = @inet_pton($ip);
    if ($bin !== false && strlen($bin) === 16) {
      $tronquee = @inet_ntop(substr($bin, 0, 6) . str_repeat("\0", 10));
      if ($tronquee !== false) return $tronquee;
    }
    return '';
  }
  return ''; // ni v4 ni v6 : on ne conserve rien plutôt qu'une valeur douteuse
}

/**
 * Purge les sessions de plus de SAPI_SESSIONS_TTL_DAYS jours.
 *
 * ⚠️ POURQUOI CETTE PURGE EXISTE. La table contenait des emails, des adresses
 * IP, une géolocalisation et le texte libre écrit par les visiteurs — sans
 * AUCUNE limite de durée ni moyen d'effacement en masse. Une demande de
 * suppression aurait obligé Robin à retrouver les lignes à la main, dans une
 * table de plusieurs milliers d'entrées. En pratique, intenable.
 *
 * Le précédent existait déjà dans le thème : `sapi_catalogue_pro_purge_old()`
 * purge les PDF à 30 jours « parce qu'ils portent des noms de clients ». Le
 * même raisonnement n'avait simplement jamais été appliqué ici.
 *
 * ⚠️ 425 JOURS, ET PAS 365. L'usage réel de Robin est de comparer un mois au
 * même mois de l'an passé. À 365 jours exactement, le décembre précédent est
 * effacé la veille du jour où il en a besoin : la rétention est juste d'un
 * cheveu trop courte pour l'usage qu'elle prétend servir. Quatorze mois
 * laissent la marge nécessaire, et restent une durée courte et défendable.
 */
if (!defined('SAPI_SESSIONS_TTL_DAYS')) define('SAPI_SESSIONS_TTL_DAYS', 425);

/* ═══════════════════════════════════════════════════════════
   GÉOLOCALISATION DES SESSIONS — ACTIVE, FOURNISSEUR FREEIPAPI
   ═══════════════════════════════════════════════════════════
   Elle a été coupée le 27/08 puis rétablie le 28/08 avec un autre
   fournisseur. Ce qui a motivé la coupure, pour ne pas y revenir :

   1. L'appel partait en **HTTP non chiffré** : l'adresse du visiteur
      circulait en clair sur le réseau.
   2. Le **HTTPS d'ip-api.com est réservé à leur offre payante**. Y basculer
      renvoie un 403 — et un 403 n'est PAS une erreur réseau : le code le
      prenait pour une réponse valide, n'y trouvait pas de ville, et sortait
      sans un mot.
   3. Leurs conditions **interdisent l'usage commercial** en offre gratuite.

   FreeIPAPI répond aux trois : HTTPS gratuit, usage commercial autorisé,
   serveurs en Europe, 60 requêtes par minute. Et l'IP qui lui est envoyée
   est **tronquée** — la ville reste juste, l'immeuble se perd.

   ⚠️ CE QUE LA COLONNE « LIEU » NE DIT PAS. Sur une IP v4 tronquée en /24,
   la ville est fiable. Sur une **IPv6 tronquée en /48**, non : elle renvoie
   la ville du préfixe de l'opérateur, pas celle du visiteur — `2a01:cb00::`
   donne « Neuilly-sur-Seine ». Les FAI français sont massivement en IPv6.
   Une part croissante des sessions va donc se ranger sous une poignée de
   villes d'opérateurs. Ne pas lire cette colonne comme « d'où viennent mes
   visiteurs ». Et elle mélange trois époques : IP entière + ip-api avant le
   27/08, rien pendant la coupure, IP tronquée + FreeIPAPI depuis.

   Changer de fournisseur : les trois points ci-dessus sont les critères. */
if (!defined('SAPI_GEOLOC_ACTIVE')) define('SAPI_GEOLOC_ACTIVE', true);

function sapi_megafilter_purge_sessions() {
  global $wpdb;
  $table = $wpdb->prefix . 'sapi_megafilter_sessions';
  return $wpdb->query($wpdb->prepare(
    "DELETE FROM $table WHERE created_at < DATE_SUB(NOW(), INTERVAL %d DAY)",
    (int) SAPI_SESSIONS_TTL_DAYS
  ));
}
add_action('sapi_megafilter_purge_sessions_event', 'sapi_megafilter_purge_sessions');

add_action('init', function () {
  if (!wp_next_scheduled('sapi_megafilter_purge_sessions_event')) {
    wp_schedule_event(time() + HOUR_IN_SECONDS, 'daily', 'sapi_megafilter_purge_sessions_event');
  }
});

function sapi_megafilter_admin_labels() {
  static $cache = null;
  if ($cache !== null) return $cache;

  /* ⚠️ `inc/guide-data.php` N'EST PAS CHARGÉ EN ADMIN.
     Tous ses `require_once` sont dans des chemins front ou des endpoints AJAX
     du conseiller — aucun ne passe par cette page. Sans cette ligne, la
     fonction rendait un tableau VIDE, le mémorisait pour toute la requête, et
     le tableau de bord affichait les slugs bruts : « grande »,
     « pas-de-sortie », « moderne ». C'est-à-dire PIRE que les tables écrites à
     la main qu'elle remplace. Trouvé en relecture avant livraison. */
  $fichier = get_template_directory() . '/inc/guide-data.php';
  if (file_exists($fichier)) require_once $fichier;

  /* Le cache n'est posé QUE sur un succès. Mémoriser un échec le rendrait
     définitif pour la requête, et invisible — le motif exact que ce dépôt
     paye depuis le début. */
  if (!function_exists('sapi_guide_get_steps')) return [];

  $labels = [];
  foreach (sapi_guide_get_steps() as $step) {
    if (empty($step['id']) || empty($step['choices'])) continue;
    $map = [];
    foreach ($step['choices'] as $choice) {
      if (empty($choice['slug'])) continue;
      $texte = isset($choice['label']) ? $choice['label'] : $choice['slug'];
      /* `dim` porte la précision utile (« intime », « < 2,50 m »). Sans elle,
         Taille et Hauteur affichent toutes deux « Standard » : deux colonnes,
         le même mot, aucune dimension. */
      if (!empty($choice['dim'])) $texte .= ' (' . $choice['dim'] . ')';
      $map[$choice['slug']] = $texte;
    }
    $labels[$step['id']] = $map;
  }
  $cache = $labels;
  return $cache;
}

function sapi_megafilter_date_fr($mysql_datetime, $format = 'd/m/Y à H:i') {
  if (empty($mysql_datetime)) return '';
  $ts = strtotime($mysql_datetime);
  return $ts ? date($format, $ts) : '';
}

function sapi_megafilter_marquer_contact_envoye($session_id) {
  if (!is_string($session_id) || $session_id === '') return;
  /* ⚠️ LONGUEUR TOLÉRANTE, ET C'EST INDISPENSABLE.
     Le client produit 16 caractères via `crypto`, mais son repli — navigateurs
     sans `crypto.getRandomValues` — en produit 19. Un contrôle en `{16}` strict
     rejetait donc 100 % de ces visiteurs, en silence : le mail partait, Robin
     le recevait, et le compteur restait à zéro. On aurait remplacé un
     sur-comptage par un sous-comptage, ce qui est tout aussi faux.
     On valide la FORME (préfixe + hexadécimal), pas une longueur exacte : le
     but est d'écarter une valeur fabriquée, pas de deviner l'algorithme du
     navigateur. Trouvé en relecture avant livraison. */
  if (!preg_match('/^mfs_[0-9a-f]{8,32}$/', $session_id)) return;
  global $wpdb;
  $table = $wpdb->prefix . 'sapi_megafilter_sessions';
  $wpdb->update(
    $table,
    ['contact_submitted' => 1, 'contact_submitted_at' => current_time('mysql')],
    ['session_id' => $session_id],
    ['%d', '%s'],
    ['%s']
  );
}

function sapi_megafilter_log_session() {
  // sendBeacon sends as application/x-www-form-urlencoded or text/plain
  // Payload : sendBeacon envoie en application/json ou text/plain → on lit
  // d'abord php://input. Fallback sur $_POST si l'appelant utilise un POST
  // classique.
  $raw = file_get_contents('php://input');
  $data = [];
  if (!empty($raw)) {
    $decoded = json_decode($raw, true);
    if (is_array($decoded)) {
      $data = $decoded;
    } else {
      parse_str($raw, $data);
    }
  }
  if (empty($data)) {
    $data = $_POST;
  }

  // Nonce (sapi-megafilter — identique aux autres endpoints V3).
  $nonce = isset($data['nonce']) ? sanitize_text_field($data['nonce']) : '';
  if (!wp_verify_nonce($nonce, 'sapi-megafilter')) {
    wp_send_json_error(['message' => 'Nonce invalide']);
  }

  $session_id = isset($data['session_id']) ? sanitize_text_field($data['session_id']) : '';
  if (empty($session_id)) {
    wp_send_json_error(['message' => 'session_id manquant']);
  }

  // Champs simples (tous optionnels) — on construit le payload UPDATE/INSERT
  // en ne gardant que ceux explicitement présents pour ne pas écraser des
  // valeurs déjà tracées lors d'un appel précédent.
  $update_data = [];
  $update_formats = [];

  if (array_key_exists('entry_point', $data)) {
    $update_data['entry_point'] = sanitize_key($data['entry_point']);
    $update_formats[] = '%s';
  }
  if (array_key_exists('entry_url', $data)) {
    $update_data['entry_url'] = esc_url_raw($data['entry_url']);
    $update_formats[] = '%s';
  }

  // answers (JSON object) → éclate sur les colonnes piece/taille/…
  if (!empty($data['answers'])) {
    $raw_answers = is_string($data['answers']) ? json_decode($data['answers'], true) : $data['answers'];
    if (is_array($raw_answers)) {
      $answer_map = [
        'piece'           => 'piece',
        'taille'          => 'taille',
        'taille_escalier' => 'taille_escalier',
        'eclairage'       => 'eclairage',
        'sortie'          => 'sortie',
        /* `table` retiré : la question « au-dessus d'un meuble ? » ne fait plus
           partie du parcours, le navigateur ne l'envoie donc jamais. La colonne
           `table_reponse` est conservée — d'anciennes lignes en portent encore
           la réponse, et le détail sait toujours les afficher — mais plus rien
           ne l'alimente. Ne pas remettre cette entrée sans remettre la
           question : elle écrirait une colonne que personne ne renseigne. */
        'hauteur'         => 'hauteur',
        'style'           => 'style',
      ];
      /* ⚠️ ON VALIDE CONTRE LE QUESTIONNAIRE, PAS SEULEMENT ON NETTOIE.
         `sanitize_text_field` accepte n'importe quel mot. Or une réponse peut
         arriver ici directement depuis l'adresse — `/mes-creations/?piece=xxx`
         suffisait à écrire `xxx` dans la colonne `piece`, donc dans le
         graphique « Top pièces demandées » du tableau de bord. Pas de faille de
         sécurité (tout est échappé à l'affichage), mais un classement que
         n'importe qui peut bourrer depuis la barre d'adresse, et « Salon » avec
         une majuscule qui crée une deuxième entrée à côté de « salon ».
         `sapi_megafilter_sanitize_project()` ne garde que les slugs qui
         existent réellement dans le questionnaire. */
      list($valides, ) = function_exists('sapi_megafilter_sanitize_project')
        ? sapi_megafilter_sanitize_project($raw_answers)
        : [[], []];
      foreach ($answer_map as $payload_key => $column) {
        if (isset($valides[$payload_key])) {
          $update_data[$column] = $valides[$payload_key];
          $update_formats[] = '%s';
        }
      }
    }
  }
  if (array_key_exists('answers_completed', $data)) {
    $update_data['answers_completed'] = !empty($data['answers_completed']) ? 1 : 0;
    $update_formats[] = '%d';
  }

  // IA
  if (array_key_exists('ai_freetext_input', $data)) {
    $input = sanitize_textarea_field($data['ai_freetext_input']);
    $update_data['ai_freetext_input'] = $input;
    $update_formats[] = '%s';
    /* `ai_freetext_used` n'est plus écrit : il ne disait rien de plus que
       « `ai_freetext_input` n'est pas vide », et deux colonnes qui portent le
       même fait finissent toujours par se contredire. Aucun filtre ni aucun
       affichage ne la lisait. Colonne conservée pour ne pas casser l'existant. */
  }
  if (array_key_exists('ai_chat_messages', $data)) {
    $raw_msgs = is_string($data['ai_chat_messages']) ? json_decode($data['ai_chat_messages'], true) : $data['ai_chat_messages'];
    if (is_array($raw_msgs)) {
      $sanitized = [];
      foreach ($raw_msgs as $m) {
        if (!is_array($m)) continue;
        $sanitized[] = [
          'role'    => isset($m['role']) ? sanitize_key($m['role']) : '',
          'content' => isset($m['content']) ? sanitize_textarea_field($m['content']) : '',
        ];
      }
      /* ⚠️ JSON_UNESCAPED_UNICODE EST INDISPENSABLE À LA RECHERCHE.
         Sans lui, « éclairage » est stocké littéralement « éclairage » :
         la lettre accentuée n'existe plus dans les octets, et AUCUNE recherche
         ne peut la retrouver — ni avec accent, ni sans, ni avec une collation
         accent-insensible. Robin aurait tapé « éclairage », obtenu zéro
         résultat, puis ouvert une session au hasard et vu le mot écrit noir sur
         blanc dans le détail (l'affichage, lui, décode le JSON).
         Défaut créé en ajoutant cette colonne à la recherche, attrapé en
         relecture : requête valide, résultat vide, aucune erreur. Le motif
         exact documenté au §4 de questions_ouvertes.md.
         ⚠️ Ne répare QUE les lignes écrites après ce commit. */
      $update_data['ai_chat_messages'] = wp_json_encode($sanitized, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
      $update_formats[] = '%s';
      $update_data['ai_chat_used'] = !empty($sanitized) ? 1 : 0;
      $update_formats[] = '%d';
    }
  }
  if (array_key_exists('ai_call_count', $data)) {
    $update_data['ai_call_count'] = (int) $data['ai_call_count'];
    $update_formats[] = '%d';
  }
  if (array_key_exists('advice_text', $data)) {
    $update_data['advice_text'] = sanitize_textarea_field($data['advice_text']);
    $update_formats[] = '%s';
  }

  /* CATALOGUE PRÉSENTÉ — RETIRÉ (décision Robin du 28/08).
     Ce bloc attendait une liste que le navigateur ne lui a jamais envoyée : le
     scan du DOM qui devait la produire visait deux sélecteurs absents de ce
     thème. La colonne est vide sur 100 % des lignes depuis le premier jour, et
     l'encart du détail ne s'est donc jamais affiché.
     Le remettre demanderait de rattacher au journal la liste que le serveur
     calcule déjà dans les deux points d'entrée IA — un chantier à part, pas une
     réparation de ce bloc. Colonne conservée, plus personne ne l'écrit. */

  // Contact
  if (array_key_exists('contact_triggered', $data)) {
    $update_data['contact_triggered'] = !empty($data['contact_triggered']) ? 1 : 0;
    $update_formats[] = '%d';
  }
  if (array_key_exists('piece_hors_perimetre', $data)) {
    $update_data['piece_hors_perimetre'] = mb_substr(sanitize_text_field($data['piece_hors_perimetre']), 0, 60);
    $update_formats[] = '%s';
  }
  if (array_key_exists('contact_kind', $data)) {
    $update_data['contact_kind'] = sanitize_key($data['contact_kind']);
    $update_formats[] = '%s';
  }
  if (array_key_exists('contact_subject', $data)) {
    $update_data['contact_subject'] = sanitize_text_field($data['contact_subject']);
    $update_formats[] = '%s';
  }
  if (array_key_exists('contact_message', $data)) {
    $update_data['contact_message'] = sanitize_textarea_field($data['contact_message']);
    $update_formats[] = '%s';
  }
  /* ⚠️ L'E-MAIL N'EST PLUS STOCKÉ — décision Robin du 27/08.
     Il arrivait déjà dans le mail que Robin reçoit ; le garder en base n'ajoutait
     rien qu'une donnée nominative à protéger. Conséquence assumée : le bouton
     « Répondre par email » du tableau de bord a été retiré, Robin répond depuis
     sa boîte. La clé est ignorée si elle arrive encore. */
  if (array_key_exists('contact_submitted', $data)) {
    $submitted = !empty($data['contact_submitted']) ? 1 : 0;
    $update_data['contact_submitted'] = $submitted;
    $update_formats[] = '%d';
    if ($submitted) {
      $update_data['contact_submitted_at'] = current_time('mysql');
      $update_formats[] = '%s';
    }
  }

  global $wpdb;
  $table = $wpdb->prefix . 'sapi_megafilter_sessions';
  $existing = $wpdb->get_var($wpdb->prepare("SELECT id FROM $table WHERE session_id = %s", $session_id));

  if ($existing) {
    if (!empty($update_data)) {
      /* ⚠️ ON JOURNALISE L'ÉCHEC. `$wpdb->update` renvoie `false` sur erreur —
         colonne absente, table verrouillée — et ce retour était jeté. Une
         mesure qui s'arrête de fonctionner sans le dire est pire qu'une mesure
         absente : Robin lirait des chiffres qui stagnent en croyant que le
         trafic baisse. */
      if ($wpdb->update($table, $update_data, ['session_id' => $session_id], $update_formats, ['%s']) === false) {
        error_log('Sapi : mise à jour de session impossible — ' . $wpdb->last_error);
      }
    }
    $row_id = (int) $existing;
    $is_insert = false;
  } else {
    // INSERT — on enrichit avec les métadonnées techniques dérivées de
    // $_SERVER (device, IP, referrer). Le tracking client n'a pas besoin
    // de les envoyer.
    $insert_data = $update_data;
    $insert_formats = $update_formats;

    $insert_data['session_id'] = $session_id;
    $insert_formats[] = '%s';

    $user_agent = isset($_SERVER['HTTP_USER_AGENT']) ? sanitize_text_field(wp_unslash($_SERVER['HTTP_USER_AGENT'])) : '';
    $device = 'Desktop';
    if (preg_match('/Mobile|Android|iPhone/i', $user_agent)) {
      $device = 'Mobile';
    } elseif (preg_match('/Tablet|iPad/i', $user_agent)) {
      $device = 'Tablette';
    }
    $browser = 'Autre';
    if (strpos($user_agent, 'Chrome') !== false && strpos($user_agent, 'Edg') === false) {
      $browser = 'Chrome';
    } elseif (strpos($user_agent, 'Safari') !== false && strpos($user_agent, 'Chrome') === false) {
      $browser = 'Safari';
    } elseif (strpos($user_agent, 'Firefox') !== false) {
      $browser = 'Firefox';
    } elseif (strpos($user_agent, 'Edg') !== false) {
      $browser = 'Edge';
    }
    $insert_data['device_type'] = $device . ' · ' . $browser;
    $insert_formats[] = '%s';

    $ip = '';
    if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
      $ip = explode(',', sanitize_text_field(wp_unslash($_SERVER['HTTP_X_FORWARDED_FOR'])))[0];
    } elseif (!empty($_SERVER['REMOTE_ADDR'])) {
      $ip = sanitize_text_field(wp_unslash($_SERVER['REMOTE_ADDR']));
    }
    $ip = trim($ip);
    /* ⚠️ L'IP N'EST PLUS STOCKÉE DU TOUT — décision Robin du 27/08 :
       « je veux des données pour faire des statistiques, pas pour identifier ».
       Elle était tronquée depuis la veille ; elle n'est désormais plus écrite
       en base, ni entière ni tronquée. La colonne reste (on ne supprime pas une
       colonne sans nécessité) mais elle n'est plus alimentée.
       Elle vit encore quelques millisecondes en mémoire, uniquement pour
       demander la VILLE au service de géolocalisation — et c'est la version
       TRONQUÉE qui part, jamais l'adresse entière. */
    $insert_data['ip_address'] = '';
    $insert_formats[] = '%s';

    $insert_data['referrer'] = isset($_SERVER['HTTP_REFERER']) ? esc_url_raw(wp_unslash($_SERVER['HTTP_REFERER'])) : '';
    $insert_formats[] = '%s';

    if ($wpdb->insert($table, $insert_data, $insert_formats) === false) {
      /* Même raison qu'à la mise à jour ci-dessus, en plus grave : ici c'est la
         session ENTIÈRE qui est perdue, pas seulement sa dernière retouche. */
      error_log('Sapi : enregistrement de session impossible — ' . $wpdb->last_error);
    }
    $row_id = (int) $wpdb->insert_id;
    $is_insert = true;
  }

  // Géolocalisation async (shutdown) — seulement à l'INSERT pour éviter
  // d'appeler le fournisseur de géoloc à chaque update.
  if ($is_insert && $row_id) {
    /* ⚠️ LA CONDITION, PAS UN `return`. J'avais écrit `if (!SAPI_GEOLOC_ACTIVE)
       return;` ici : ça ne sortait pas du bloc, ça sortait de TOUTE la
       fonction, donc du `wp_send_json_success()` final — sur chaque INSERT.
       La ligne était bien écrite en base, mais l'endpoint répondait « 0 » au
       lieu de son JSON. Sans conséquence aujourd'hui (le client envoie en
       `sendBeacon` et ne lit jamais la réponse), et c'est bien le problème :
       la première ligne ajoutée après ce bloc ne se serait jamais exécutée,
       sur les INSERT seulement. Invisible en recette. */
    $ip_for_geo = sapi_tronquer_ip($ip); // tronquée : la ville reste juste, l'immeuble se perd
    if ($ip_for_geo && SAPI_GEOLOC_ACTIVE) {
      add_action('shutdown', function () use ($ip_for_geo, $row_id) {
        /* ⚠️ FOURNISSEUR CHANGÉ — ip-api.com a été abandonné pour deux raisons
           cumulées : son HTTPS est réservé à l'offre payante (l'appel partait
           donc en clair), et ses conditions interdisent l'usage commercial en
           offre gratuite — un site marchand n'y avait pas droit.
           FreeIPAPI : HTTPS gratuit, usage commercial autorisé, serveurs en
           Europe, 60 requêtes par minute. Champs vérifiés dans leur
           documentation : cityName / regionName / countryName. */
        $resp = wp_remote_get("https://free.freeipapi.com/api/json/{$ip_for_geo}", ['timeout' => 5]);
        /* ⚠️ CHAQUE SORTIE LAISSE UNE TRACE. L'ancien appel sortait muet dans
           les quatre cas ci-dessous : la colonne restait vide et personne ne
           l'apprenait jamais. On remplacerait un fournisseur muet par un
           autre. `error_log` écrit dans le journal d'erreurs PHP de o2switch. */
        if (is_wp_error($resp)) {
          error_log('Sapi géoloc : appel FreeIPAPI en échec — ' . $resp->get_error_message());
          return;
        }
        /* ⚠️ UN 200 N'EST PAS UNE RÉUSSITE. Un dépassement de quota renvoie un
           code d'erreur HTTP avec un corps valide : `is_wp_error` ne le voit
           pas. C'est exactement ce qui rendait l'ancien appel muet. */
        $code = (int) wp_remote_retrieve_response_code($resp);
        if ($code !== 200) {
          /* 429 = les 60 requêtes par minute sont dépassées. */
          error_log('Sapi géoloc : FreeIPAPI a répondu ' . $code . ($code === 429 ? ' (quota de 60 req/min dépassé)' : ''));
          return;
        }
        $body = json_decode(wp_remote_retrieve_body($resp), true);
        if (!is_array($body)) {
          error_log('Sapi géoloc : réponse FreeIPAPI illisible.');
          return;
        }
        if (empty($body['cityName'])) {
          /* Cas normal et fréquent : IP privée, réservée, ou inconnue du
             fournisseur. FreeIPAPI répond alors 200 avec tous les champs à
             null. On ne journalise pas, ce n'est pas une panne. */
          return;
        }
        $location = implode(', ', array_filter([$body['cityName'], $body['regionName'] ?? '', $body['countryName'] ?? '']));
        global $wpdb;
        $table = $wpdb->prefix . 'sapi_megafilter_sessions';
        $wpdb->update($table, ['location' => mb_substr($location, 0, 200)], ['id' => $row_id], ['%s'], ['%d']);
      });
    }
  }

  wp_send_json_success(['logged' => true]);
}

/**
 * Menu admin — Conseiller V3. Slug : sapi-conseiller-sessions.
 */
function sapi_megafilter_admin_menu() {
  add_menu_page(
    'Robin Conseiller — Sessions',
    'Robin Conseiller',
    'manage_woocommerce',
    'sapi-conseiller-sessions',
    'sapi_megafilter_admin_page',
    'dashicons-lightbulb',
    26
  );
}
add_action('admin_menu', 'sapi_megafilter_admin_menu');

/**
 * Export CSV — version minimaliste pour Commit 1. Sera étendue (filtres
 * respectés) en chantier 5.
 */
function sapi_megafilter_export_csv() {
  if (!isset($_GET['sapi_megafilter_export']) || $_GET['sapi_megafilter_export'] !== '1') return;
  if (!current_user_can('manage_woocommerce')) return;
  if (!isset($_GET['_wpnonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_GET['_wpnonce'])), 'sapi_megafilter_export')) return;

  global $wpdb;
  $table = $wpdb->prefix . 'sapi_megafilter_sessions';
  // Respecter les filtres en cours (mêmes paramètres GET que la page admin).
  $filters = sapi_megafilter_admin_read_filters();
  list($where_sql, $args) = sapi_megafilter_admin_build_where($filters);
  $sql = "SELECT * FROM $table $where_sql ORDER BY created_at DESC";
  $rows = $args ? $wpdb->get_results($wpdb->prepare($sql, $args), ARRAY_A) : $wpdb->get_results($sql, ARRAY_A);

  header('Content-Type: text/csv; charset=utf-8');
  header('Content-Disposition: attachment; filename=conseiller-sessions-' . wp_date('Y-m-d') . '.csv');

  $out = fopen('php://output', 'w');
  fprintf($out, chr(0xEF) . chr(0xBB) . chr(0xBF));

  /* Colonnes retirées le 27/08 : « IP » et « Contact email » ne sont plus
     alimentées (décision Robin : des statistiques, pas des identifiants), et
     « Produits matchés » ne l'a jamais été. Les exporter aurait produit trois
     colonnes systématiquement vides. */
  fputcsv($out, [
    'Date', 'Provenance', 'Device', 'Lieu',
    'Pièce', 'Taille', 'Sortie', 'Hauteur', 'Éclairage', 'Style',
    'Quiz complet', 'IA chat', 'Nb appels IA', 'Advice text',
    'Contact', 'Contact kind', 'Contact sujet', 'Contact message',
    'Referrer', 'URL d\'entrée',
  ], ';');

  foreach ($rows as $r) {
    fputcsv($out, [
      $r['created_at'],
      $r['entry_point'],
      $r['device_type'],
      $r['location'],
      $r['piece'],
      $r['taille'],
      $r['sortie'],
      $r['hauteur'],
      $r['eclairage'],
      $r['style'],
      $r['answers_completed'] ? 'Oui' : 'Non',
      $r['ai_chat_used'] ? 'Oui' : 'Non',
      $r['ai_call_count'],
      $r['advice_text'],
      $r['contact_submitted'] ? 'Oui' : 'Non',
      $r['contact_kind'],
      $r['contact_subject'],
      $r['contact_message'],
      $r['referrer'],
      $r['entry_url'],
    ], ';');
  }
  fclose($out);
  exit;
}
add_action('admin_init', 'sapi_megafilter_export_csv');

/**
 * Enqueue CSS/JS admin — uniquement sur la page Conseiller (slug
 * sapi-conseiller-sessions, hook toplevel_page_sapi-conseiller-sessions).
 */
function sapi_megafilter_admin_enqueue($hook) {
  if ($hook !== 'toplevel_page_sapi-conseiller-sessions') return;
  $base = get_template_directory_uri();
  $dir  = get_template_directory();
  wp_enqueue_style(
    'sapi-admin-conseiller',
    $base . '/assets/admin-conseiller.css',
    [],
    filemtime($dir . '/assets/admin-conseiller.css')
  );
  wp_enqueue_script(
    'sapi-admin-conseiller',
    $base . '/assets/admin-conseiller.js',
    [],
    filemtime($dir . '/assets/admin-conseiller.js'),
    true
  );
  wp_localize_script('sapi-admin-conseiller', 'SAPI_ADMIN_CONSEILLER', [
    'ajaxUrl' => admin_url('admin-ajax.php'),
    'nonce'   => wp_create_nonce('sapi-admin-conseiller'),
  ]);
}
add_action('admin_enqueue_scripts', 'sapi_megafilter_admin_enqueue');

/**
 * Lit les filtres globaux depuis $_GET et retourne un tableau sanitisé.
 * Utilisé par la page admin et l'export CSV pour garantir la cohérence.
 */
function sapi_megafilter_admin_read_filters() {
  $valid_periods  = ['7d', '30d', 'all'];
  /* `conseils_picker` ajouté le 28/08 : le sélecteur de pièce existe sur DEUX
     pages (l'accueil et /conseils-eclaires/), et les confondre reviendrait à ne
     pas savoir laquelle des deux amène des visiteurs. */
  $valid_entries  = ['home_picker', 'conseils_picker', 'mes_creations', 'product_pill', 'freetext'];
  $valid_pieces   = ['salon', 'cuisine', 'chambre', 'chambre-enfant', 'bureau', 'entree', 'escalier'];
  $valid_devices  = ['desktop', 'mobile'];
  $valid_statuses = ['chat', 'contact', 'complete'];

  $period = isset($_GET['period']) ? sanitize_key($_GET['period']) : '7d';
  if (!in_array($period, $valid_periods, true)) $period = '7d';

  $entry = isset($_GET['entry']) ? sanitize_key($_GET['entry']) : '';
  if ($entry && !in_array($entry, $valid_entries, true)) $entry = '';

  $piece = isset($_GET['piece']) ? sanitize_key($_GET['piece']) : '';
  if ($piece && !in_array($piece, $valid_pieces, true)) $piece = '';

  $device = isset($_GET['device']) ? sanitize_key($_GET['device']) : '';
  if ($device && !in_array($device, $valid_devices, true)) $device = '';

  $status = isset($_GET['status']) ? sanitize_key($_GET['status']) : '';
  if ($status && !in_array($status, $valid_statuses, true)) $status = '';

  $q = isset($_GET['q']) ? sanitize_text_field(wp_unslash($_GET['q'])) : '';

  return [
    'period' => $period,
    'entry'  => $entry,
    'piece'  => $piece,
    'device' => $device,
    'status' => $status,
    'q'      => $q,
  ];
}

/**
 * Construit la clause WHERE prepared depuis les filtres lus.
 * @return array [$where_sql (string '' ou 'WHERE ...'), $args (array)]
 */
function sapi_megafilter_admin_build_where($filters) {
  global $wpdb;
  $where = [];
  $args  = [];

  if (!empty($filters['period']) && $filters['period'] !== 'all') {
    $days = ($filters['period'] === '30d') ? 30 : 7;
    /* ⚠️ `NOW()`, PAS `current_time()`. J'ai voulu calculer cette borne côté
       PHP pour « unifier les horloges » — c'était l'inverse. `created_at` est
       posé par MySQL (`DEFAULT CURRENT_TIMESTAMP`) : comparer cette colonne à
       `NOW()`, c'est comparer une horloge à elle-même, ce qui est juste quoi
       qu'il arrive. Une borne venue de WordPress serait juste seulement tant
       que les deux machines coïncident — et fausse en silence sinon.
       Le désaccord d'horloges, lui, est signalé par un bandeau sur cette page. */
    $where[] = 'created_at >= DATE_SUB(NOW(), INTERVAL %d DAY)';
    $args[] = $days;
  }
  if (!empty($filters['entry'])) {
    $where[] = 'entry_point = %s';
    $args[] = $filters['entry'];
  }
  if (!empty($filters['piece'])) {
    $where[] = 'piece = %s';
    $args[] = $filters['piece'];
  }
  if (!empty($filters['device'])) {
    $where[] = 'device_type LIKE %s';
    $args[] = ($filters['device'] === 'mobile') ? 'Mobile%' : 'Desktop%';
  }
  switch ($filters['status'] ?? '') {
    case 'chat':     $where[] = 'ai_chat_used = 1'; break;
    case 'contact':  $where[] = 'contact_triggered = 1'; break;
    case 'complete': $where[] = 'answers_completed = 1'; break;
  }
  if (!empty($filters['q'])) {
    $q = '%' . $wpdb->esc_like($filters['q']) . '%';
    /* ⚠️ `ai_freetext_input` N'ÉTAIT écrite par personne : le serveur savait la
       traiter, le client ne l'envoyait jamais. C'est réparé depuis, mais elle
       ne couvre QUE les phrases saisies dans un sélecteur de pièce, avant
       l'arrivée — celles tapées dans la modale vivent dans `ai_chat_messages`.
       La recherche promettait « texte libre » et n'interrogeait que la
       première ; on cherche donc dans les deux, avec `contact_subject` (le résumé rédigé par
       l'IA) et `contact_message`.
       C'est ce qui rend enfin trouvables les PIÈCES HORS PÉRIMÈTRE : une
       demande pour une salle de bain n'a pas de `piece`, n'entre dans aucun
       agrégat et n'a aucun filtre — mais les mots « salle de bain » sont dans
       la conversation. Chercher devient le seul chemin vers l'information la
       plus utile du tableau : ce que les gens demandent et que Robin ne vend pas. */
    /* Ni `ip_address` ni `contact_email` : ces colonnes ne sont plus alimentées
       depuis le 27/08. Les proposer à la recherche promettrait un résultat qui
       ne peut plus arriver. */
    $where[] = '(location LIKE %s OR ai_freetext_input LIKE %s'
             . ' OR ai_chat_messages LIKE %s OR contact_subject LIKE %s OR contact_message LIKE %s)';
    array_push($args, $q, $q, $q, $q, $q);
  }

  $where_sql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';
  return [$where_sql, $args];
}

/**
 * Calcule les stats dashboard selon les filtres en cours.
 */
function sapi_megafilter_admin_compute_stats($filters) {
  global $wpdb;
  $table = $wpdb->prefix . 'sapi_megafilter_sessions';
  list($where_sql, $args) = sapi_megafilter_admin_build_where($filters);

  $total_q = "SELECT COUNT(*) FROM $table $where_sql";
  $total = (int) ($args ? $wpdb->get_var($wpdb->prepare($total_q, $args)) : $wpdb->get_var($total_q));

  $completed_q = "SELECT COUNT(*) FROM $table $where_sql " . ($where_sql ? 'AND' : 'WHERE') . ' answers_completed = 1';
  $completed = (int) ($args ? $wpdb->get_var($wpdb->prepare($completed_q, $args)) : $wpdb->get_var($completed_q));

  $chat_q = "SELECT COUNT(*) FROM $table $where_sql " . ($where_sql ? 'AND' : 'WHERE') . ' ai_chat_used = 1';
  $chat_used = (int) ($args ? $wpdb->get_var($wpdb->prepare($chat_q, $args)) : $wpdb->get_var($chat_q));

  $contacts_q = "SELECT COUNT(*) FROM $table $where_sql " . ($where_sql ? 'AND' : 'WHERE') . ' contact_submitted = 1';
  $contacts = (int) ($args ? $wpdb->get_var($wpdb->prepare($contacts_q, $args)) : $wpdb->get_var($contacts_q));

  /* Delta % vs période précédente (sauf si period = 'all').
     ⚠️ IL DOIT PORTER LES MÊMES FILTRES QUE LE CHIFFRE QU'IL COMPARE.
     La requête n'avait aucune clause en dehors des dates : filtrer sur
     « Pièce : Salon » comparait *les sessions salon de cette semaine* à
     *TOUTES les sessions de la semaine dernière*, et affichait un « ▼ 78 % »
     mécanique et vide de sens. Robin lit ce chiffre pour savoir si ça monte ou
     ça descend. */
  $delta_pct = null;
  if (!empty($filters['period']) && $filters['period'] !== 'all') {
    $days = ($filters['period'] === '30d') ? 30 : 7;
    /* On reconstruit la clause SANS la période — elle est remplacée par la
       fenêtre précédente — mais AVEC tous les autres filtres actifs. */
    $filtres_prec = $filters;
    $filtres_prec['period'] = 'all';
    list($prev_where, $prev_args) = sapi_megafilter_admin_build_where($filtres_prec);
    $prev_where .= ($prev_where ? ' AND' : 'WHERE')
      . ' created_at >= DATE_SUB(NOW(), INTERVAL %d DAY) AND created_at < DATE_SUB(NOW(), INTERVAL %d DAY)';
    $prev_args = array_merge($prev_args, [$days * 2, $days]);
    $prev = (int) $wpdb->get_var($wpdb->prepare(
      "SELECT COUNT(*) FROM $table $prev_where",
      $prev_args
    ));
    if ($prev > 0) {
      $delta_pct = (int) round((($total - $prev) / $prev) * 100);
    } elseif ($total > 0) {
      $delta_pct = 100;
    }
  }

  // Top pièces
  $pieces_q = "SELECT piece, COUNT(*) AS c FROM $table $where_sql " . ($where_sql ? 'AND' : 'WHERE') . " piece != '' GROUP BY piece ORDER BY c DESC LIMIT 6";
  $top_pieces = $args ? $wpdb->get_results($wpdb->prepare($pieces_q, $args)) : $wpdb->get_results($pieces_q);

  // Top styles
  $styles_q = "SELECT style, COUNT(*) AS c FROM $table $where_sql " . ($where_sql ? 'AND' : 'WHERE') . " style != '' GROUP BY style ORDER BY c DESC LIMIT 3";
  $top_styles = $args ? $wpdb->get_results($wpdb->prepare($styles_q, $args)) : $wpdb->get_results($styles_q);

  // Top provenance
  /* ⚠️ 6 ET NON 4. Il n'y a que quatre provenances possibles aujourd'hui, mais
     les anciennes lignes en portent une cinquième (`freetext`, plus jamais
     écrite). Avec un plafond à 4, une provenance bien réelle disparaissait du
     graphique sans un mot dès que l'ancienne était nombreuse — un chiffre faux
     par omission, le pire genre : rien ne manque à l'écran. */
  $entry_q = "SELECT entry_point, COUNT(*) AS c FROM $table $where_sql " . ($where_sql ? 'AND' : 'WHERE') . " entry_point != '' GROUP BY entry_point ORDER BY c DESC LIMIT 6";
  $top_entry = $args ? $wpdb->get_results($wpdb->prepare($entry_q, $args)) : $wpdb->get_results($entry_q);

  /* Les pièces qu'on demande à Robin et qu'il ne fait pas. Pas de LIMIT
     serré : c'est une liste courte par nature, et une pièce qui revient trois
     fois vaut d'être vue. */
  $hors_q = "SELECT piece_hors_perimetre AS mot, COUNT(*) AS c FROM $table $where_sql "
    . ($where_sql ? 'AND' : 'WHERE') . " piece_hors_perimetre != '' GROUP BY piece_hors_perimetre ORDER BY c DESC LIMIT 8";
  $top_hors = $args ? $wpdb->get_results($wpdb->prepare($hors_q, $args)) : $wpdb->get_results($hors_q);

  // Breakdown contacts par kind (uniquement parmi les soumis)
  $contact_break_q = "SELECT contact_kind, COUNT(*) AS c FROM $table $where_sql " . ($where_sql ? 'AND' : 'WHERE') . " contact_submitted = 1 GROUP BY contact_kind";
  $contact_breakdown = $args ? $wpdb->get_results($wpdb->prepare($contact_break_q, $args)) : $wpdb->get_results($contact_break_q);

  return [
    'total'             => $total,
    'completed'         => $completed,
    'chat_used'         => $chat_used,
    'contacts'          => $contacts,
    'delta_pct'         => $delta_pct,
    'top_pieces'        => $top_pieces ?: [],
    'top_styles'        => $top_styles ?: [],
    'top_entry'         => $top_entry ?: [],
    'contact_breakdown' => $contact_breakdown ?: [],
    'top_hors'          => $top_hors ?: [],
  ];
}

/**
 * Page admin V3 — version complète (mockup-12) : header + dashboard 4 stats
 * + 2 ranking cards + filtres globaux + tableau filtrable + drill-down modal.
 */
function sapi_megafilter_admin_page() {
  global $wpdb;
  $table = $wpdb->prefix . 'sapi_megafilter_sessions';

  sapi_megafilter_maybe_create_table();

  $filters = sapi_megafilter_admin_read_filters();
  list($where_sql, $args) = sapi_megafilter_admin_build_where($filters);

  $per_page = 30;
  $paged = isset($_GET['paged']) ? max(1, (int) $_GET['paged']) : 1;
  $offset = ($paged - 1) * $per_page;

  $count_q = "SELECT COUNT(*) FROM $table $where_sql";
  $total_filtered = (int) ($args ? $wpdb->get_var($wpdb->prepare($count_q, $args)) : $wpdb->get_var($count_q));
  $total_pages = max(1, ceil($total_filtered / $per_page));

  $rows_q = "SELECT * FROM $table $where_sql ORDER BY created_at DESC LIMIT %d OFFSET %d";
  $rows_args = array_merge($args, [$per_page, $offset]);
  $rows = $wpdb->get_results($wpdb->prepare($rows_q, $rows_args));

  $stats = sapi_megafilter_admin_compute_stats($filters);

  // URL export avec filtres préservés
  $export_args = array_filter(array_merge($filters, ['sapi_megafilter_export' => '1']));
  $export_url  = wp_nonce_url(
    add_query_arg($export_args, admin_url('admin.php?page=sapi-conseiller-sessions')),
    'sapi_megafilter_export'
  );

  // Labels d'affichage (mockup-12)
  $entry_labels = [
    'home_picker'    => 'Accueil',
    'conseils_picker' => 'Conseils éclairés',
    'mes_creations' => 'Mes créations',
    'product_pill'  => 'Fiche produit',
    /* Plus jamais écrite depuis le 28/08 : c'était une méthode dans une
       colonne de pages. Conservée pour que les anciennes lignes restent
       lisibles. */
    'freetext'      => 'Texte libre (ancien)',
  ];
  $entry_pill_class = [
    'home_picker'   => 'pill--home',
    'conseils_picker' => 'pill--home',
    'mes_creations' => 'pill--mes-creations',
    'product_pill'  => 'pill--product',
    'freetext'      => 'pill--freetext',
  ];
  $contact_kind_labels = [
    'pro'        => 'PRO',
    'sur-mesure' => 'SUR-MESURE',
    'simple'     => 'SIMPLE',
  ];
  $contact_kind_pill = [
    'pro'        => 'pill--pro',
    'sur-mesure' => 'pill--sur-mesure',
    'simple'     => 'pill--simple',
  ];
  /* Construits depuis le questionnaire — voir sapi_megafilter_admin_labels().
     Ne PAS les réécrire à la main : c'est ce qui les avait fait diverger. */
  $sapi_labels     = sapi_megafilter_admin_labels();
  $piece_labels    = $sapi_labels['piece'] ?? [];
  $taille_labels   = ($sapi_labels['taille'] ?? []) + ($sapi_labels['taille_escalier'] ?? []);
  $sortie_labels   = $sapi_labels['sortie'] ?? [];
  $style_labels    = $sapi_labels['style'] ?? [];

  // Helpers d'affichage
  $period = $filters['period'];
  $period_label_map = ['7d' => '7 jours', '30d' => '30 jours', 'all' => 'Tout'];
  $period_label = $period_label_map[$period] ?? '7 jours';

  $completed_pct = $stats['total'] > 0 ? round(($stats['completed'] / $stats['total']) * 100) : 0;
  $chat_pct      = $stats['total'] > 0 ? round(($stats['chat_used'] / $stats['total']) * 100) : 0;

  // Top valeurs absolues pour normaliser les barres
  $max_piece = $stats['top_pieces'] ? max(array_map(function($r) { return (int)$r->c; }, $stats['top_pieces'])) : 1;
  $max_style = $stats['top_styles'] ? max(array_map(function($r) { return (int)$r->c; }, $stats['top_styles'])) : 1;
  $max_entry = $stats['top_entry']  ? max(array_map(function($r) { return (int)$r->c; }, $stats['top_entry']))  : 1;
  $sum_piece = $stats['top_pieces'] ? array_sum(array_map(function($r) { return (int)$r->c; }, $stats['top_pieces'])) : 0;
  $sum_style = $stats['top_styles'] ? array_sum(array_map(function($r) { return (int)$r->c; }, $stats['top_styles'])) : 0;
  $sum_entry = $stats['top_entry']  ? array_sum(array_map(function($r) { return (int)$r->c; }, $stats['top_entry']))  : 0;

  // Breakdown contacts en texte
  $contact_sub_parts = [];
  foreach ($stats['contact_breakdown'] as $row) {
    if (!$row->contact_kind) continue;
    $label = $contact_kind_labels[$row->contact_kind] ?? strtoupper($row->contact_kind);
    $contact_sub_parts[] = (int)$row->c . ' ' . strtolower($label);
  }
  $contact_sub = $contact_sub_parts ? implode(' · ', $contact_sub_parts) : 'Aucun contact';
  ?>
  <div class="wrap sapi-conseiller-admin">

    <div class="header-row">
      <h1>Robin Conseiller — Sessions <span class="count">(<?php echo esc_html($total_filtered); ?>)</span></h1>
      <a href="<?php echo esc_url($export_url); ?>" class="button button-primary">📥 Exporter CSV</a>
    </div>

    <?php
    /* ⚠️ LE SEUL ENDROIT OÙ UN DÉSACCORD D'HORLOGES SE VOIT.
       `created_at` est posé par MySQL, `contact_submitted_at` par WordPress.
       Tant que les deux sont d'accord — c'est le cas aujourd'hui — ce tableau
       est cohérent. Si elles cessent de l'être, un contact envoyé deux minutes
       après une session s'afficherait deux heures après, parfois dans le
       futur : des dates plausibles, simplement fausses, et rien pour le dire.
       ⚠️ NE PAS ACCUSER L'HÉBERGEUR DANS CE MESSAGE. La cause la plus probable
       n'est pas une dérive de serveur mais un fuseau WordPress modifié dans
       Réglages → Général — un écart rond de 60 ou 120 minutes en est la
       signature. Un avertissement qui désigne le mauvais coupable, et qui
       reste affiché en permanence, est celui qu'on apprend à ignorer. */
    $ecart_horloges = 0;
    $mysql_now = $wpdb->get_var('SELECT NOW()');
    if ($mysql_now) {
      $ecart_horloges = abs(strtotime($mysql_now) - strtotime(current_time('mysql')));
    }
    if ($ecart_horloges > 300) : ?>
      <div class="notice notice-warning" style="margin:12px 0;">
        <p><strong>Les deux horloges du site ne sont plus d'accord</strong> —
        environ <?php echo esc_html(round($ecart_horloges / 60)); ?> minutes d'écart
        entre la base de données et WordPress. Sur ce tableau, la date de création
        d'une session et la date d'envoi d'un contact ne viennent pas de la même
        horloge : elles sont donc décalées l'une par rapport à l'autre, et un
        contact peut sembler parti avant la session qui l'a produit.</p>
        <p>Si l'écart est rond (60 ou 120 minutes), commence par
        <strong>Réglages → Général → Fuseau horaire</strong> : il doit être sur
        <em>Paris</em>. Si l'écart est irrégulier, c'est une dérive de serveur,
        et là c'est pour l'hébergeur.</p>
      </div>
    <?php endif; ?>

    <!-- ═══════════════ DASHBOARD ═══════════════ -->
    <div class="dashboard">
      <div class="stat-card">
        <div class="stat-card__label">Sessions · <?php echo esc_html($period_label); ?></div>
        <div class="stat-card__value"><?php echo esc_html($stats['total']); ?></div>
        <div class="stat-card__sub">
          <?php if ($stats['delta_pct'] !== null) : ?>
            <?php if ($stats['delta_pct'] >= 0) : ?>
              <span class="delta-up">▲ <?php echo esc_html($stats['delta_pct']); ?> %</span>
            <?php else : ?>
              <span class="delta-down">▼ <?php echo esc_html(abs($stats['delta_pct'])); ?> %</span>
            <?php endif; ?>
            vs période précédente
          <?php else : ?>
            &nbsp;
          <?php endif; ?>
        </div>
      </div>
      <div class="stat-card">
        <div class="stat-card__label">Quiz complétés</div>
        <div class="stat-card__value"><?php echo esc_html($completed_pct); ?> %</div>
        <div class="stat-card__sub"><?php echo esc_html($stats['completed']); ?> sessions sur <?php echo esc_html($stats['total']); ?></div>
      </div>
      <div class="stat-card">
        <div class="stat-card__label">IA chat utilisée</div>
        <div class="stat-card__value"><?php echo esc_html($chat_pct); ?> %</div>
        <div class="stat-card__sub"><?php echo esc_html($stats['chat_used']); ?> conversations</div>
      </div>
      <div class="stat-card">
        <div class="stat-card__label">Contacts envoyés</div>
        <div class="stat-card__value"><?php echo esc_html($stats['contacts']); ?></div>
        <div class="stat-card__sub"><?php echo esc_html($contact_sub); ?></div>
      </div>
    </div>

    <!-- ═══════════════ RANKING CARDS ═══════════════ -->
    <div class="dashboard-row-2">
      <div class="ranking-card">
        <div class="ranking-card__title">Top pièces demandées (<?php echo esc_html($period_label); ?>)</div>
        <?php if (empty($stats['top_pieces'])) : ?>
          <p style="color:#8c8f94;font-size:12px;">Aucune donnée.</p>
        <?php else : ?>
          <?php foreach ($stats['top_pieces'] as $row) :
            $piece_label = $piece_labels[$row->piece] ?? ucfirst($row->piece);
            $bar_pct = $max_piece > 0 ? round(((int)$row->c / $max_piece) * 100) : 0;
            $share_pct = $sum_piece > 0 ? round(((int)$row->c / $sum_piece) * 100) : 0;
          ?>
            <div class="ranking-row">
              <div class="ranking-row__label"><?php echo esc_html($piece_label); ?></div>
              <div class="ranking-row__bar"><div class="ranking-row__fill" style="width:<?php echo esc_attr($bar_pct); ?>%;"></div></div>
              <div class="ranking-row__pct"><?php echo esc_html($share_pct); ?> %</div>
            </div>
          <?php endforeach; ?>
        <?php endif; ?>
      </div>

      <div class="ranking-card">
        <div class="ranking-card__title">Top styles demandés (<?php echo esc_html($period_label); ?>)</div>
        <?php if (empty($stats['top_styles'])) : ?>
          <p style="color:#8c8f94;font-size:12px;">Aucune donnée.</p>
        <?php else : ?>
          <?php foreach ($stats['top_styles'] as $row) :
            $style_label = $style_labels[$row->style] ?? ucfirst($row->style);
            $bar_pct = $max_style > 0 ? round(((int)$row->c / $max_style) * 100) : 0;
            $share_pct = $sum_style > 0 ? round(((int)$row->c / $sum_style) * 100) : 0;
          ?>
            <div class="ranking-row">
              <div class="ranking-row__label"><?php echo esc_html($style_label); ?></div>
              <div class="ranking-row__bar"><div class="ranking-row__fill" style="width:<?php echo esc_attr($bar_pct); ?>%;background:#E35B24;"></div></div>
              <div class="ranking-row__pct"><?php echo esc_html($share_pct); ?> %</div>
            </div>
          <?php endforeach; ?>
        <?php endif; ?>

        <?php /* Les pièces qu'on te demande et que tu ne fais pas. La donnée
                 vient de l'IA, qui recopie le mot du visiteur au moment où elle
                 le renvoie vers toi — donc uniquement pour les pièces hors
                 catalogue, jamais pour un couloir qu'elle a rangé en entrée. */ ?>
        <div style="margin-top: 22px;" class="ranking-card__title">Pièces hors catalogue demandées (<?php echo esc_html($period_label); ?>)</div>
        <?php if (empty($stats['top_hors'])) : ?>
          <p style="color:#8c8f94;font-size:12px;">Aucune pour l'instant.</p>
        <?php else :
          $max_hors = 0;
          foreach ($stats['top_hors'] as $row) { $max_hors = max($max_hors, (int) $row->c); }
          foreach ($stats['top_hors'] as $row) :
            $bar_pct = $max_hors > 0 ? round(((int) $row->c / $max_hors) * 100) : 0;
          ?>
            <div class="ranking-row">
              <div class="ranking-row__label"><?php echo esc_html(ucfirst($row->mot)); ?></div>
              <div class="ranking-row__bar"><div class="ranking-row__fill" style="width:<?php echo esc_attr($bar_pct); ?>%;background:#8A6A2F;"></div></div>
              <?php /* Un NOMBRE, pas un pourcentage : « 40 % des pièces hors
                       catalogue » ne veut rien dire pour Robin, alors que
                       « 12 demandes de salle de bain » se décide. */ ?>
              <div class="ranking-row__pct"><?php echo esc_html((int) $row->c); ?></div>
            </div>
          <?php endforeach; ?>
        <?php endif; ?>

        <div style="margin-top: 22px;" class="ranking-card__title">Provenance des sessions (<?php echo esc_html($period_label); ?>)</div>
        <?php if (empty($stats['top_entry'])) : ?>
          <p style="color:#8c8f94;font-size:12px;">Aucune donnée.</p>
        <?php else : ?>
          <?php
          $entry_bar_colors = [
            'home_picker'   => '#2E7D32',
            'conseils_picker' => '#558B2F',
            'mes_creations' => '#E35B24',
            'product_pill'  => '#1565C0',
            'freetext'      => '#7B1FA2',
          ];
          foreach ($stats['top_entry'] as $row) :
            $entry_label = $entry_labels[$row->entry_point] ?? ucfirst($row->entry_point);
            $bar_color = $entry_bar_colors[$row->entry_point] ?? '#937D68';
            $bar_pct = $max_entry > 0 ? round(((int)$row->c / $max_entry) * 100) : 0;
            $share_pct = $sum_entry > 0 ? round(((int)$row->c / $sum_entry) * 100) : 0;
          ?>
            <div class="ranking-row">
              <div class="ranking-row__label"><?php echo esc_html($entry_label); ?></div>
              <div class="ranking-row__bar"><div class="ranking-row__fill" style="width:<?php echo esc_attr($bar_pct); ?>%;background:<?php echo esc_attr($bar_color); ?>;"></div></div>
              <div class="ranking-row__pct"><?php echo esc_html($share_pct); ?> %</div>
            </div>
          <?php endforeach; ?>
        <?php endif; ?>
      </div>
    </div>

    <!-- ═══════════════ FILTRES ═══════════════ -->
    <form method="get" class="filters">
      <input type="hidden" name="page" value="sapi-conseiller-sessions">
      <span class="filter-label">Période :</span>
      <select name="period" class="filter-select" onchange="this.form.submit()">
        <option value="7d"  <?php selected($filters['period'], '7d'); ?>>7 derniers jours</option>
        <option value="30d" <?php selected($filters['period'], '30d'); ?>>30 derniers jours</option>
        <option value="all" <?php selected($filters['period'], 'all'); ?>>Tout</option>
      </select>
      <span class="filter-sep"></span>
      <span class="filter-label">Provenance :</span>
      <select name="entry" class="filter-select" onchange="this.form.submit()">
        <option value="">Toutes</option>
        <option value="home_picker"   <?php selected($filters['entry'], 'home_picker'); ?>>Accueil</option>
        <option value="conseils_picker" <?php selected($filters['entry'], 'conseils_picker'); ?>>Conseils éclairés</option>
        <option value="mes_creations" <?php selected($filters['entry'], 'mes_creations'); ?>>Mes créations</option>
        <option value="product_pill"  <?php selected($filters['entry'], 'product_pill'); ?>>Fiche produit</option>
        <option value="freetext"      <?php selected($filters['entry'], 'freetext'); ?>>Texte libre (ancien)</option>
      </select>
      <span class="filter-sep"></span>
      <span class="filter-label">Pièce :</span>
      <select name="piece" class="filter-select" onchange="this.form.submit()">
        <option value="">Toutes</option>
        <?php foreach ($piece_labels as $slug => $label) : ?>
          <option value="<?php echo esc_attr($slug); ?>" <?php selected($filters['piece'], $slug); ?>><?php echo esc_html($label); ?></option>
        <?php endforeach; ?>
      </select>
      <span class="filter-sep"></span>
      <select name="device" class="filter-select" onchange="this.form.submit()">
        <option value="">Tous devices</option>
        <option value="desktop" <?php selected($filters['device'], 'desktop'); ?>>Desktop</option>
        <option value="mobile"  <?php selected($filters['device'], 'mobile'); ?>>Mobile</option>
      </select>
      <select name="status" class="filter-select" onchange="this.form.submit()">
        <option value="">Toutes les sessions</option>
        <option value="chat"     <?php selected($filters['status'], 'chat'); ?>>Avec chat IA</option>
        <option value="contact"  <?php selected($filters['status'], 'contact'); ?>>Avec contact</option>
        <option value="complete" <?php selected($filters['status'], 'complete'); ?>>Quiz complets</option>
      </select>
      <input type="search" name="q" class="filter-search" placeholder="Rechercher : « salle de bain », une ville, un mot du projet…" value="<?php echo esc_attr($filters['q']); ?>">
    </form>

    <!-- ═══════════════ TABLEAU ═══════════════ -->
    <div class="table-wrap">
      <table class="widefat">
        <thead>
          <tr>
            <th class="nowrap">Date</th>
            <th>Provenance</th>
            <th class="nowrap">Device</th>
            <th>Lieu</th>
            <th>Pièce</th>
            <th>Taille</th>
            <th>Sortie</th>
            <th>Style</th>
            <th class="center">IA</th>
            <th class="center">Chat</th>
            <th>Contact</th>
          </tr>
        </thead>
        <tbody>
          <?php if (empty($rows)) : ?>
            <tr><td colspan="11" style="text-align:center;color:#8c8f94;padding:24px;">Aucune session pour ces filtres.</td></tr>
          <?php else : ?>
            <?php foreach ($rows as $r) :
              $entry_class = $entry_pill_class[$r->entry_point] ?? '';
              $entry_label = $entry_labels[$r->entry_point] ?? '—';
              $piece_label = $piece_labels[$r->piece] ?? ($r->piece ?: '—');
              $taille_raw  = $r->taille ?: $r->taille_escalier;
              $taille_label = $taille_labels[$taille_raw] ?? ($taille_raw ?: '—');
              $sortie_label = $sortie_labels[$r->sortie] ?? ($r->sortie ?: '—');
              $style_label = $style_labels[$r->style] ?? ($r->style ?: '—');
            ?>
              <tr data-session-id="<?php echo esc_attr($r->session_id); ?>">
                <td class="nowrap"><?php echo esc_html(sapi_megafilter_date_fr($r->created_at, 'd/m · H:i')); ?></td>
                <td>
                  <?php if ($r->entry_point) : ?>
                    <span class="pill <?php echo esc_attr($entry_class); ?> pill--small"><?php echo esc_html($entry_label); ?></span>
                  <?php else : ?><span class="dash">—</span><?php endif; ?>
                </td>
                <td class="nowrap muted"><?php echo esc_html($r->device_type ?: '—'); ?></td>
                <td><?php echo esc_html($r->location ?: '—'); ?></td>
                <td><?php echo esc_html($piece_label); ?></td>
                <td><?php echo $taille_raw ? esc_html($taille_label) : '<span class="dash">—</span>'; ?></td>
                <td><?php echo $r->sortie ? esc_html($sortie_label) : '<span class="dash">—</span>'; ?></td>
                <td><?php echo $r->style ? esc_html($style_label) : '<span class="dash">—</span>'; ?></td>
                <td class="center muted"><?php echo $r->ai_call_count > 0 ? esc_html($r->ai_call_count) : '<span class="dash">—</span>'; ?></td>
                <td class="center">
                  <?php if ($r->ai_chat_used) :
                    $chat_count = 0;
                    if (!empty($r->ai_chat_messages)) {
                      $msgs = json_decode($r->ai_chat_messages, true);
                      if (is_array($msgs)) $chat_count = count($msgs);
                    }
                  ?>
                    <span class="check">✓</span> <small class="muted">(<?php echo esc_html($chat_count); ?>)</small>
                  <?php else : ?><span class="dash">—</span><?php endif; ?>
                </td>
                <td>
                  <?php if ($r->contact_submitted) :
                    $kind_class = $contact_kind_pill[$r->contact_kind] ?? '';
                    $kind_label = $contact_kind_labels[$r->contact_kind] ?? strtoupper($r->contact_kind);
                  ?>
                    <span class="pill <?php echo esc_attr($kind_class); ?> pill--small"><?php echo esc_html($kind_label); ?></span>
                  <?php elseif ($r->contact_triggered) : ?>
                    <span class="pill pill--outline pill--small">Abandon</span>
                  <?php else : ?>
                    <span class="dash">—</span>
                  <?php endif; ?>
                </td>
              </tr>
            <?php endforeach; ?>
          <?php endif; ?>
        </tbody>
      </table>

      <div class="pagination">
        <div>
          <?php if ($total_filtered > 0) :
            $start = $offset + 1;
            $end = min($offset + $per_page, $total_filtered);
          ?>
            Affichage <?php echo esc_html($start); ?> - <?php echo esc_html($end); ?> sur <?php echo esc_html($total_filtered); ?>
          <?php else : ?>
            &nbsp;
          <?php endif; ?>
        </div>
        <?php if ($total_pages > 1) : ?>
          <div class="pagination__pages">
            <?php
            $links = paginate_links([
              'base'      => add_query_arg('paged', '%#%'),
              'format'    => '',
              'current'   => $paged,
              'total'     => $total_pages,
              'prev_text' => '‹ Précédent',
              'next_text' => 'Suivant ›',
              'type'      => 'plain',
            ]);
            echo wp_kses($links, [
              'a'    => ['href' => [], 'class' => []],
              'span' => ['class' => []],
            ]);
            ?>
          </div>
        <?php endif; ?>
      </div>
    </div>

  </div>

  <!-- ═══════════════ DRILL-DOWN MODAL (rempli via JS) ═══════════════ -->
  <div class="drill-overlay" id="sapi-drill">
    <div class="drill">
      <div class="drill__header">
        <h2>Session</h2>
        <button class="drill__close" type="button">×</button>
      </div>
      <div class="drill__body">
        <div class="drill-loading">Chargement…</div>
      </div>
      <div class="drill__actions">
        <div class="left"></div>
        <button class="delete-button" type="button">Supprimer</button>
      </div>
    </div>
  </div>
  <?php
}

/**
 * Endpoint AJAX — récupère le HTML du drill-down détail pour une session.
 * Renvoie { title, html, actions_left }.
 */
add_action('wp_ajax_sapi_megafilter_get_session_detail', 'sapi_megafilter_ajax_get_session_detail');
function sapi_megafilter_ajax_get_session_detail() {
  if (!current_user_can('manage_woocommerce')) {
    wp_send_json_error(['message' => 'Forbidden']);
  }
  if (!isset($_POST['nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['nonce'])), 'sapi-admin-conseiller')) {
    wp_send_json_error(['message' => 'Nonce invalide']);
  }
  $session_id = isset($_POST['session_id']) ? sanitize_text_field(wp_unslash($_POST['session_id'])) : '';
  if (empty($session_id)) {
    wp_send_json_error(['message' => 'session_id manquant']);
  }
  global $wpdb;
  $table = $wpdb->prefix . 'sapi_megafilter_sessions';
  $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE session_id = %s", $session_id));
  if (!$row) {
    wp_send_json_error(['message' => 'Session non trouvée']);
  }
  $payload = sapi_megafilter_render_session_detail($row);
  wp_send_json_success($payload);
}

/**
 * Endpoint AJAX — supprime une session (depuis le drill-down).
 */
add_action('wp_ajax_sapi_megafilter_delete_session', 'sapi_megafilter_ajax_delete_session');
function sapi_megafilter_ajax_delete_session() {
  if (!current_user_can('manage_woocommerce')) {
    wp_send_json_error(['message' => 'Forbidden']);
  }
  if (!isset($_POST['nonce']) || !wp_verify_nonce(sanitize_text_field(wp_unslash($_POST['nonce'])), 'sapi-admin-conseiller')) {
    wp_send_json_error(['message' => 'Nonce invalide']);
  }
  $session_id = isset($_POST['session_id']) ? sanitize_text_field(wp_unslash($_POST['session_id'])) : '';
  if (empty($session_id)) {
    wp_send_json_error(['message' => 'session_id manquant']);
  }
  global $wpdb;
  $table = $wpdb->prefix . 'sapi_megafilter_sessions';
  $wpdb->delete($table, ['session_id' => $session_id], ['%s']);
  wp_send_json_success(['deleted' => true]);
}

/**
 * Render le HTML du drill-down pour une session. Renvoie { title, html,
 * actions_left }.
 */
function sapi_megafilter_render_session_detail($r) {
  $entry_labels = [
    'home_picker'   => 'page d\'accueil',
    'conseils_picker' => 'page Conseils éclairés',
    'mes_creations' => 'page Mes créations',
    'product_pill'  => 'fiche produit',
    'freetext'      => 'texte libre (ancien)',
  ];
  $entry_pill_class = [
    'home_picker'   => 'pill--home',
    'conseils_picker' => 'pill--home',
    'mes_creations' => 'pill--mes-creations',
    'product_pill'  => 'pill--product',
    'freetext'      => 'pill--freetext',
  ];
  $contact_kind_labels = [
    'pro'        => 'PRO',
    'sur-mesure' => 'SUR-MESURE',
    'simple'     => 'SIMPLE',
  ];
  $contact_kind_pill = [
    'pro'        => 'pill--pro',
    'sur-mesure' => 'pill--sur-mesure',
    'simple'     => 'pill--simple',
  ];
  /* Construits depuis le questionnaire — voir sapi_megafilter_admin_labels(). */
  $sapi_labels      = sapi_megafilter_admin_labels();
  $piece_labels     = $sapi_labels['piece'] ?? [];
  $style_labels     = $sapi_labels['style'] ?? [];
  $taille_labels    = ($sapi_labels['taille'] ?? []) + ($sapi_labels['taille_escalier'] ?? []);
  $sortie_labels    = $sapi_labels['sortie'] ?? [];
  $hauteur_labels   = $sapi_labels['hauteur'] ?? [];
  $eclairage_labels = $sapi_labels['eclairage'] ?? [];
  /* `table` : question retirée du parcours, la colonne n'est plus jamais
     écrite. Table vide plutôt que des libellés qui laisseraient croire le
     contraire. */
  $table_labels     = [];
  $key_labels = [
    'piece'           => 'Pièce',
    'taille'          => 'Taille',
    'taille_escalier' => 'Taille escalier',
    'sortie'          => 'Sortie élec',
    'hauteur'         => 'Hauteur',
    'eclairage'       => 'Éclairage',
    'table_reponse'   => 'Au-dessus d\'un meuble',
    'style'           => 'Style',
  ];
  $value_lookup = [
    'piece'           => $piece_labels,
    'taille'          => $taille_labels,
    'taille_escalier' => $taille_labels,
    'sortie'          => $sortie_labels,
    'hauteur'         => $hauteur_labels,
    'eclairage'       => $eclairage_labels,
    'table_reponse'   => $table_labels,
    'style'           => $style_labels,
  ];

  // ── Title
  $date_h = sapi_megafilter_date_fr($r->created_at);
  $kind = $r->contact_kind;
  $kind_suffix = $r->contact_submitted && $kind ? ' — ' . ($contact_kind_labels[$kind] ?? strtoupper($kind)) : '';
  $piece_suffix = $r->piece ? ' — ' . ($piece_labels[$r->piece] ?? ucfirst($r->piece)) : '';
  $title_extra = $kind_suffix ?: $piece_suffix;
  $title = sprintf(
    'Session du %s <span style="font-size:0.7em;color:#8c8f94;font-weight:400;">%s</span>',
    esc_html($date_h),
    esc_html($title_extra)
  );

  // ── Body sections
  ob_start();

  // Provenance
  $entry_class = $entry_pill_class[$r->entry_point] ?? 'pill--outline';
  $entry_label = ucfirst($r->entry_point ?: 'inconnu');
  if ($r->entry_point === 'home_picker') $entry_label = 'Accueil';
  if ($r->entry_point === 'conseils_picker') $entry_label = 'Conseils éclairés';
  if ($r->entry_point === 'mes_creations') $entry_label = 'Mes créations';
  if ($r->entry_point === 'product_pill') $entry_label = 'Fiche produit';
  if ($r->entry_point === 'freetext') $entry_label = 'Texte libre (ancien)';
  ?>
  <div class="drill-section">
    <h3>Provenance</h3>
    <div style="font-size:13px;">
      <span class="pill <?php echo esc_attr($entry_class); ?> pill--small"><?php echo esc_html($entry_label); ?></span>
      <?php if (!empty($r->entry_url)) : ?>
        &nbsp;via <strong><?php echo esc_html($entry_url_label = $entry_labels[$r->entry_point] ?? $r->entry_url); ?></strong>
      <?php endif; ?>
      <?php if (!empty($r->ai_freetext_input)) : ?>
        · saisie initiale :
    </div>
    <div class="quote-box" style="margin-top:8px;">« <?php echo esc_html($r->ai_freetext_input); ?> »</div>
      <?php else : ?>
    </div>
      <?php endif; ?>
  </div>

  <?php
  // Récap projet
  $answer_map = [
    'piece'           => $r->piece,
    'taille'          => $r->taille,
    'taille_escalier' => $r->taille_escalier,
    'sortie'          => $r->sortie,
    'hauteur'         => $r->hauteur,
    'eclairage'       => $r->eclairage,
    'table_reponse'   => $r->table_reponse,
    'style'           => $r->style,
  ];
  $non_empty = array_filter($answer_map, function($v) { return !empty($v); });
  if (!empty($non_empty)) : ?>
  <div class="drill-section">
    <h3>Récap projet</h3>
    <div class="answer-grid">
      <?php foreach ($non_empty as $key => $val) :
        $label = $key_labels[$key] ?? $key;
        $val_map = $value_lookup[$key] ?? [];
        $val_label = $val_map[$val] ?? ucfirst($val);
      ?>
        <div><span class="a-key"><?php echo esc_html($label); ?> :</span> <span class="a-val"><?php echo esc_html($val_label); ?></span></div>
      <?php endforeach; ?>
    </div>
  </div>
  <?php endif; ?>

  <?php
  // Conversation chat
  $chat_msgs = [];
  if (!empty($r->ai_chat_messages)) {
    $decoded = json_decode($r->ai_chat_messages, true);
    if (is_array($decoded)) $chat_msgs = $decoded;
  }
  if (!empty($chat_msgs)) : ?>
  <div class="drill-section">
    <h3>Conversation chat (<?php echo esc_html(count($chat_msgs)); ?> échanges)</h3>
    <div class="chat-thread">
      <?php foreach ($chat_msgs as $m) :
        if (!is_array($m) || empty($m['content'])) continue;
        $is_robin = isset($m['role']) && $m['role'] === 'assistant';
        $cls = $is_robin ? 'chat-msg--robin' : 'chat-msg--visitor';
      ?>
        <div class="chat-msg <?php echo esc_attr($cls); ?>">
          <div class="chat-msg__bubble"><?php echo esc_html($m['content']); ?></div>
        </div>
      <?php endforeach; ?>
    </div>
  </div>
  <?php endif; ?>

  <?php if (!empty($r->advice_text)) : ?>
  <div class="drill-section">
    <h3>Phrase IA finale (advice_text)</h3>
    <div class="quote-box"><?php echo esc_html($r->advice_text); ?></div>
  </div>
  <?php endif; ?>

  <?php
  // Contact
  if ($r->contact_triggered) : ?>
  <div class="drill-section">
    <h3>Demande de contact</h3>
    <div class="contact-box">
      <?php if ($r->contact_kind) :
        $kind_class = $contact_kind_pill[$r->contact_kind] ?? '';
        $kind_label = $contact_kind_labels[$r->contact_kind] ?? strtoupper($r->contact_kind);
      ?>
        <div class="contact-box__row">
          <span class="contact-box__key">Type :</span>
          <span class="contact-box__val"><span class="pill <?php echo esc_attr($kind_class); ?> pill--small"><?php echo esc_html($kind_label); ?></span> (kind = <?php echo esc_html($r->contact_kind); ?>)</span>
        </div>
      <?php endif; ?>
      <?php if (!empty($r->contact_subject)) : ?>
        <div class="contact-box__row"><span class="contact-box__key">Sujet :</span> <span class="contact-box__val"><?php echo esc_html($r->contact_subject); ?></span></div>
      <?php endif; ?>
      <?php if (!empty($r->contact_message)) : ?>
        <div class="contact-box__row"><span class="contact-box__key">Message :</span></div>
        <div class="contact-box__row" style="padding-left:6px;color:#1d2327;font-style:italic;font-size:12.5px;">
          « <?php echo esc_html($r->contact_message); ?> »
        </div>
      <?php endif; ?>
      <div class="contact-box__row">
        <span class="contact-box__key">Envoyé :</span>
        <span class="contact-box__val">
          <?php if ($r->contact_submitted) : ?>
            <span class="check">✓ Oui</span>
            <?php if ($r->contact_submitted_at) : ?>
              · <?php echo esc_html(sapi_megafilter_date_fr($r->contact_submitted_at)); ?>
            <?php endif; ?>
          <?php else : ?>
            <span style="color:#8c8f94;">Non (abandon)</span>
          <?php endif; ?>
        </span>
      </div>
    </div>
  </div>
  <?php endif; ?>

  <?php /* L'encart « Catalogue présenté » a été retiré le 28/08 : la colonne
     `matching_product_ids` n'a jamais été alimentée (voir la note à l'endroit
     de l'écriture), donc `!empty()` était faux sur chaque ligne et l'encart
     n'a jamais existé à l'écran. On retire un encart fantôme, pas une
     fonctionnalité. */ ?>

  <!-- Technique -->
  <div class="drill-section">
    <h3>Technique</h3>
    <div class="tech-grid">
      <div><span class="t-key">Session ID :</span> <?php echo esc_html($r->session_id); ?></div>
      <div><span class="t-key">Device :</span> <?php echo esc_html($r->device_type ?: '—'); ?></div>
      <div><span class="t-key">Localisation :</span> <?php echo esc_html($r->location ?: '—'); ?></div>
      <div><span class="t-key">Référent :</span> <?php echo esc_html($r->referrer ?: '—'); ?></div>
      <div><span class="t-key">Appels IA :</span> <?php echo (int)$r->ai_call_count; ?></div>
      <div><span class="t-key">Page d'entrée :</span> <?php echo esc_html($r->entry_url ?: '—'); ?></div>
      <div><span class="t-key">Créée :</span> <?php echo esc_html(sapi_megafilter_date_fr($r->created_at, 'd/m/Y H:i:s')); ?></div>
    </div>
  </div>
  <?php

  $html = ob_get_clean();

  // Actions left (mailto si email)
  $actions_left = '';
  /* Il y avait ici un bouton « Répondre par email », alimenté par l'adresse
     stockée en base. Retiré avec elle : Robin reçoit la demande dans sa boîte
     et y répond de là. `$actions_left` reste, il servira au prochain bouton. */

  return [
    'title'        => $title,
    'html'         => $html,
    'actions_left' => $actions_left,
  ];
}

// ─── AJAX: Render product cards for Conseils page ───


/* ─── Google Reviews (Places API New) ─── */
function sapi_get_google_reviews() {
  $api_key  = defined('SAPI_GOOGLE_API_KEY') ? SAPI_GOOGLE_API_KEY : '';
  $place_id = defined('SAPI_GOOGLE_PLACE_ID') ? SAPI_GOOGLE_PLACE_ID : '';

  if (empty($api_key) || empty($place_id)) {
    return null;
  }

  // Cache 6h via transient
  $cache_key = 'sapi_google_reviews_' . md5($place_id);
  $cached = get_transient($cache_key);
  if ($cached !== false && is_array($cached) && isset($cached['rating'])) {
    return $cached;
  }
  // Nettoyer un éventuel transient invalide
  if ($cached !== false) {
    delete_transient($cache_key);
  }

  $url = 'https://places.googleapis.com/v1/places/' . $place_id;

  $response = wp_remote_get($url, [
    'timeout' => 10,
    'headers' => [
      'X-Goog-Api-Key'    => $api_key,
      'X-Goog-FieldMask'  => 'reviews,rating,userRatingCount',
      'Referer'            => home_url('/'),
    ],
  ]);

  if (is_wp_error($response)) {
    return null;
  }

  $body = json_decode(wp_remote_retrieve_body($response), true);
  if (empty($body) || !isset($body['rating'])) {
    return null;
  }

  $result = [
    'rating'      => floatval($body['rating']),
    'total'       => intval($body['userRatingCount'] ?? 0),
    'reviews'     => [],
  ];

  if (!empty($body['reviews'])) {
    foreach ($body['reviews'] as $review) {
      // Prefer originalText (French) over translated text
      $text = '';
      $lang = '';
      if (!empty($review['originalText']['text'])) {
        $text = $review['originalText']['text'];
        $lang = $review['originalText']['languageCode'] ?? '';
      } elseif (!empty($review['text']['text'])) {
        $text = $review['text']['text'];
        $lang = $review['text']['languageCode'] ?? '';
      }

      $result['reviews'][] = [
        'author'  => $review['authorAttribution']['displayName'] ?? '',
        'rating'  => intval($review['rating'] ?? 5),
        'text'    => $text,
        'time'    => $review['relativePublishTimeDescription'] ?? '',
        'photo'   => $review['authorAttribution']['photoUri'] ?? '',
        'lang'    => $lang,
      ];
    }

    // P14 : avis en français d'abord (tri stable). Code langue Places, sinon
    // heuristique sur les accents français quand la langue n'est pas exposée.
    $fr = [];
    $other = [];
    foreach ($result['reviews'] as $r) {
      $is_fr = (stripos($r['lang'], 'fr') === 0);
      if (!$is_fr && $r['lang'] === '') {
        $is_fr = (bool) preg_match('/[àâçéèêëîïôûùü]/iu', $r['text']);
      }
      if ($is_fr) { $fr[] = $r; } else { $other[] = $r; }
    }
    $result['reviews'] = array_merge($fr, $other);
  }

  set_transient($cache_key, $result, 6 * HOUR_IN_SECONDS);

  return $result;
}



// ─── Robots.txt — règles supplémentaires ─────────────────────────────────────
// Bloque les URLs parasites crawlées par Google (audit GSC 2 avril 2026)
add_filter( 'robots_txt', function ( $output ) {
  $output .= "\n# Atelier Sapi — règles personnalisées\n";
  $output .= "Disallow: /*?wc-ajax=*\n";
  $output .= "Disallow: /wp-json/complianz/\n";
  $output .= "Disallow: /*?PageSpeed=*\n";
  return $output;
}, 99 );

/**
 * ============================================================
 * COUPON BIENVENUE10 — Auto-application via cookie
 * ============================================================
 * Le snippet popup cookies (sapi-cookie-popup) pose le cookie
 * `sapi_pending_coupon` quand un visiteur laisse son email. On lit ce cookie
 * ici pour appliquer automatiquement le coupon BIENVENUE10 dès qu'un produit
 * est ajouté au panier, puis on détruit le cookie pour ne plus rejouer.
 *
 * Sécurité : la valeur du cookie est ignorée et le code est forcé en dur à
 * 'BIENVENUE10' — ça empêche de faire appliquer un autre coupon en manipulant
 * le cookie côté client.
 */
add_action('woocommerce_add_to_cart', 'sapi_auto_apply_welcome_coupon');
add_action('woocommerce_cart_loaded_from_session', 'sapi_auto_apply_welcome_coupon');

function sapi_auto_apply_welcome_coupon() {
  if (empty($_COOKIE['sapi_pending_coupon'])) return;
  if (!function_exists('WC') || !WC()->cart) return;

  $cart = WC()->cart;
  if ($cart->is_empty()) return;

  $coupon_code = 'bienvenue10'; // WC normalise en lowercase
  if ($cart->has_discount($coupon_code)) {
    sapi_clear_pending_coupon_cookie();
    return;
  }

  // apply_coupon() affiche lui-même les notices WC (succès ou refus).
  // Qu'il soit accepté ou refusé, on détruit le cookie pour ne pas rejouer
  // à chaque ajout panier suivant (sinon spam de notices).
  $cart->apply_coupon($coupon_code);
  sapi_clear_pending_coupon_cookie();
}

function sapi_clear_pending_coupon_cookie() {
  if (!headers_sent()) {
    $path   = defined('COOKIEPATH') && COOKIEPATH ? COOKIEPATH : '/';
    $domain = defined('COOKIE_DOMAIN') ? COOKIE_DOMAIN : '';
    setcookie('sapi_pending_coupon', '', time() - 3600, $path, $domain);
  }
  unset($_COOKIE['sapi_pending_coupon']);
}

/**
 * Notice WC personnalisée lors de l'application du coupon BIENVENUE10.
 * Remplace le "Code promo appliqué avec succès" générique par un message
 * de bienvenue aligné sur le ton de l'Atelier.
 */
add_filter('woocommerce_coupon_success_message', 'sapi_welcome_coupon_success_message', 10, 3);

function sapi_welcome_coupon_success_message($msg, $msg_code, $coupon) {
  if (!$coupon || !is_object($coupon) || !method_exists($coupon, 'get_code')) return $msg;
  if (strtolower($coupon->get_code()) !== 'bienvenue10') return $msg;
  if ((int) $msg_code !== WC_Coupon::WC_COUPON_SUCCESS) return $msg;

  return 'Bienvenue à l\'Atelier Sâpi ! Votre réduction de 10% a été appliquée. 🌿';
}

/**
 * Galerie Inspiration — handler AJAX inscription newsletter Brevo (liste #6).
 * Mirroir du handler `sapi_brevo_subscribe` du snippet popup cookies, mais
 * avec SOURCE = "inspiration" pour distinguer l'origine côté Brevo.
 *
 * Pourquoi un handler dédié plutôt que de réutiliser celui du snippet :
 * - Le snippet hardcode SOURCE = 'popup', non paramétrable.
 * - Garde le snippet (gestion par Robin via Code Snippets) totalement indépendant
 *   du déploiement du thème.
 */
if (!function_exists('sapi_get_brevo_api_key')) {
  function sapi_get_brevo_api_key() {
    foreach (['BREVO_API_KEY', 'SAPI_BREVO_API_KEY', 'SIB_API_KEY', 'SENDINBLUE_API_KEY'] as $const) {
      if (defined($const) && constant($const)) {
        return constant($const);
      }
    }
    $key = get_option('sib_api_key_v3');
    if (!empty($key)) return $key;
    $options = get_option('mailin_options');
    if (is_array($options)) {
      foreach (['api_key_v3', 'api_key', 'access_key', 'apikey'] as $k) {
        if (!empty($options[$k])) return $options[$k];
      }
    }
    return null;
  }
}

add_action('wp_ajax_nopriv_sapi_inspiration_brevo_subscribe', 'sapi_inspiration_brevo_subscribe');
add_action('wp_ajax_sapi_inspiration_brevo_subscribe', 'sapi_inspiration_brevo_subscribe');
function sapi_inspiration_brevo_subscribe() {
  if (!check_ajax_referer('sapi_inspiration_brevo_nonce', 'nonce', false)) {
    wp_send_json_error(['message' => 'invalid_nonce'], 403);
  }

  /* ⚠️ TROIS COUCHES AJOUTÉES LE 28/08. Ce formulaire n'avait que le jeton
     ci-dessus, contrairement aux quatre autres du site — et un jeton posé dans
     la page ne protège de rien : il suffit de le lire. Ordre des contrôles
     calqué sur `sapi_ajax_robin_contact()`, pour qu'on n'ait qu'un seul
     raisonnement anti-spam à tenir en tête. */

  // Piège à robots : champ invisible, un humain ne peut pas le remplir.
  if (!empty($_POST['website'])) {
    wp_send_json_error(['message' => 'spam'], 400);
  }

  /* Délai de saisie, mesuré par le navigateur. Un robot poste en quelques
     millisecondes ; une personne met plusieurs secondes à lire, cliquer et
     taper son adresse. 2 secondes est volontairement bas : on cherche à
     écarter l'envoi automatique, pas à chronométrer les gens pressés.
     ⚠️ PAS `sapi_time_trap_valid()` ICI. Cette page est mise en cache :
     l'horodatage signé au rendu serait celui de la génération du cache, sa
     borne basse serait franchie d'office et sa borne haute finirait par
     refuser tout le monde. Explication complète dans page-inspiration.php. */
  $delai = isset($_POST['delai_ms']) ? (int) $_POST['delai_ms'] : -1;
  if ($delai < 2000) {
    wp_send_json_error(['message' => 'spam'], 400);
  }

  /* Limite de fréquence : 5 par heure et par adresse.
     ⚠️ Un identifiant DISTINCT des autres formulaires. Avec un identifiant
     partagé, une personne qui écrit à Robin depuis la page contact aurait
     consommé le quota de son inscription à la newsletter. */
  /* ⚠️ L'ADRESSE EST VALIDÉE AVANT DE CONSOMMER LE QUOTA. Dans l'autre ordre,
     cinq fautes de frappe suffisaient à bloquer une vraie personne pendant une
     heure — et le quota existe pour arrêter les robots, pas les maladroits. */
  $email = isset($_POST['email']) ? sanitize_email(wp_unslash($_POST['email'])) : '';
  if (empty($email) || !is_email($email)) {
    wp_send_json_error(['message' => 'invalid_email'], 400);
  }

  if (!sapi_check_form_rate_limit('inspiration_newsletter')) {
    wp_send_json_error(['message' => 'rate_limited'], 429);
  }

  $api_key = sapi_get_brevo_api_key();
  if (empty($api_key)) {
    wp_send_json_error(['message' => 'no_api_key'], 500);
  }

  $response = wp_remote_post('https://api.brevo.com/v3/contacts', [
    'timeout' => 10,
    'headers' => [
      'accept'       => 'application/json',
      'content-type' => 'application/json',
      'api-key'      => $api_key,
    ],
    'body' => wp_json_encode([
      'email'         => $email,
      'listIds'       => [6],
      'updateEnabled' => true,
      'attributes'    => [
        'SOURCE' => 'inspiration',
      ],
    ]),
  ]);

  if (is_wp_error($response)) {
    wp_send_json_error(['message' => 'http_error', 'details' => $response->get_error_message()], 500);
  }

  $code = wp_remote_retrieve_response_code($response);
  if ($code >= 200 && $code < 300) {
    wp_send_json_success();
  }

  $body = wp_remote_retrieve_body($response);
  wp_send_json_error(['message' => 'brevo_error', 'code' => $code, 'body' => $body], $code);
}
