<?php
/**
 * Photos par pièce + matière — taxonomies sur les médias + édition rapide
 *
 * Déclare deux taxonomies privées sur les attachments :
 *   - media_room    : la pièce où la photo est prise
 *   - media_essence : l'essence de bois du luminaire visible
 *
 * ORIGINE : ce code vivait dans le snippet Code Snippets « Photos par pièce +
 * matière — taxonomies attachments (S28) ». Rapatrié dans le thème le 09/09/2026.
 *
 * POURQUOI IL DOIT ÊTRE ICI ET PAS DANS UN SNIPPET
 * Le thème LIT ces deux taxonomies sans les déclarer :
 *   - functions.php, sapi_filter_attachment_ids_by_term() et
 *     sapi_get_product_photo_ids() — filtrage des photos par pièce et essence ;
 *   - page-inspiration.php — les filtres « Pièce » et « Essence de bois » de la
 *     galerie, et les attributs data-rooms / data-essences des tuiles.
 * Tant que la déclaration vivait ailleurs, désactiver ce snippet faisait
 * renvoyer du vide à ces filtres, sans erreur, sans page blanche, sans que rien
 * ne le signale. Une dépendance dure du thème vers un snippet, non déclarée.
 *
 * ⚠️ LES DONNÉES NE SONT PAS ICI. Les termes (« Salon », « Peuplier »…) et les
 * ~274 photos taguées vivent en base de données. Ce fichier ne fait que déclarer
 * que ces deux taxonomies existent. Le déplacer ne touche à aucun tag.
 *
 * ⚠️ NE JAMAIS INCLURE CE FICHIER SANS SON GARDE-FOU
 * Il déclare ses fonctions au premier niveau, donc PHP les déclare à la
 * COMPILATION, dès l'ouverture du fichier. Si le snippet Code Snippets homonyme
 * est encore actif, l'inclure sans condition donne « Cannot redeclare function »
 * et une page blanche sur tout le site, front ET admin — donc sans accès à
 * l'écran qui permettrait de désactiver le snippet.
 * Un `return;` en tête de CE fichier ne protégerait rien : il s'exécuterait
 * après la compilation, c'est-à-dire trop tard.
 * Le garde-fou vit donc au point d'inclusion, dans functions.php :
 *     if (!function_exists('sapi_register_media_room_taxonomy')) {
 *       require_once .../inc/sapi-media-taxonomies.php;
 *     }
 * Ce test porte sur la PREMIÈRE fonction du lot, et c'est suffisant : le snippet
 * les déclare toutes ensemble, donc soit elles existent toutes, soit aucune.
 *
 * @package theme-sapi-maison
 */

if (!defined('ABSPATH')) exit;


/* ============================================================
   1. DÉCLARATION DES 2 TAXONOMIES SUR LES ATTACHMENTS
   ============================================================ */

function sapi_register_media_room_taxonomy() {
    register_taxonomy('media_room', 'attachment', [
        'labels' => [
            'name'                       => 'Pièces',
            'singular_name'              => 'Pièce',
            'menu_name'                  => 'Pièces',
            'all_items'                  => 'Toutes les pièces',
            'edit_item'                  => 'Modifier la pièce',
            'view_item'                  => 'Voir la pièce',
            'update_item'                => 'Mettre à jour la pièce',
            'add_new_item'               => 'Ajouter une pièce',
            'new_item_name'              => 'Nom de la nouvelle pièce',
            'search_items'               => 'Rechercher une pièce',
            'not_found'                  => 'Aucune pièce trouvée',
            'no_terms'                   => 'Aucune pièce',
            'items_list'                 => 'Liste des pièces',
            'items_list_navigation'      => 'Navigation liste des pièces',
            'separate_items_with_commas' => 'Séparer les pièces par des virgules',
            'add_or_remove_items'        => 'Ajouter ou supprimer des pièces',
            'choose_from_most_used'      => 'Choisir parmi les pièces les plus utilisées',
        ],
        'public'             => false,
        'publicly_queryable' => false,
        'show_ui'            => true,
        'show_in_menu'       => true,
        'show_in_nav_menus'  => false,
        'show_admin_column'  => true,
        'show_in_rest'       => true,
        'hierarchical'       => true,
        'rewrite'            => false,
        'query_var'          => false,
    ]);
}
add_action('init', 'sapi_register_media_room_taxonomy', 0);

function sapi_register_media_essence_taxonomy() {
    register_taxonomy('media_essence', 'attachment', [
        'labels' => [
            'name'                       => 'Essences',
            'singular_name'              => 'Essence',
            'menu_name'                  => 'Essences',
            'all_items'                  => 'Toutes les essences',
            'edit_item'                  => 'Modifier l\'essence',
            'view_item'                  => 'Voir l\'essence',
            'update_item'                => 'Mettre à jour l\'essence',
            'add_new_item'               => 'Ajouter une essence',
            'new_item_name'              => 'Nom de la nouvelle essence',
            'search_items'               => 'Rechercher une essence',
            'not_found'                  => 'Aucune essence trouvée',
            'no_terms'                   => 'Aucune essence',
            'items_list'                 => 'Liste des essences',
            'items_list_navigation'      => 'Navigation liste des essences',
            'separate_items_with_commas' => 'Séparer les essences par des virgules',
            'add_or_remove_items'        => 'Ajouter ou supprimer des essences',
            'choose_from_most_used'      => 'Choisir parmi les essences les plus utilisées',
        ],
        'public'             => false,
        'publicly_queryable' => false,
        'show_ui'            => true,
        'show_in_menu'       => true,
        'show_in_nav_menus'  => false,
        'show_admin_column'  => true,
        'show_in_rest'       => true,
        'hierarchical'       => true,
        'rewrite'            => false,
        'query_var'          => false,
    ]);
}
add_action('init', 'sapi_register_media_essence_taxonomy', 0);


/* ============================================================
   2. UX : TAXONOMIES DANS LE PANNEAU D'ÉDITION RAPIDE
   ============================================================ */

/**
 * Ajoute les taxonomies dans le panneau d'édition rapide de l'attachment.
 * Inclut un hidden flag _submitted pour distinguer "form soumis" de "filtre
 * déclenché par autre process" lors de la sauvegarde.
 */
function sapi_add_taxonomies_to_attachment_quick_edit($form_fields, $post) {
    $taxonomies = ['media_room', 'media_essence'];

    foreach ($taxonomies as $tax_name) {
        $taxonomy = get_taxonomy($tax_name);
        if (!$taxonomy) continue;

        $terms = get_terms([
            'taxonomy'   => $tax_name,
            'hide_empty' => false,
            'orderby'    => 'name',
        ]);
        if (empty($terms) || is_wp_error($terms)) continue;

        $assigned_term_ids = wp_get_object_terms($post->ID, $tax_name, ['fields' => 'ids']);
        if (is_wp_error($assigned_term_ids)) $assigned_term_ids = [];

        $html = '<div style="display:flex;flex-wrap:wrap;gap:6px 14px;max-width:100%;">';
        // Hidden flag pour identifier qu'on a affiché et soumis notre form
        $html .= sprintf(
            '<input type="hidden" name="attachments[%d][%s_submitted]" value="1">',
            $post->ID,
            esc_attr($tax_name)
        );
        foreach ($terms as $term) {
            $checked = in_array($term->term_id, $assigned_term_ids) ? 'checked' : '';
            $html .= sprintf(
                '<label style="display:inline-flex;align-items:center;gap:4px;font-size:13px;cursor:pointer;"><input type="checkbox" name="attachments[%d][%s][]" value="%d" %s style="margin:0;"> %s</label>',
                $post->ID,
                esc_attr($tax_name),
                $term->term_id,
                $checked,
                esc_html($term->name)
            );
        }
        $html .= '</div>';

        $form_fields[$tax_name] = [
            'label' => esc_html($taxonomy->labels->name),
            'input' => 'html',
            'html'  => $html,
        ];
    }

    return $form_fields;
}
add_filter('attachment_fields_to_edit', 'sapi_add_taxonomies_to_attachment_quick_edit', 10, 2);

/**
 * Sauvegarde les taxonomies SEULEMENT si le hidden flag _submitted est présent.
 * Sinon, le filtre a été déclenché par un autre process (upload, REST, etc.)
 * et on ne touche pas aux assignations existantes.
 *
 * ⚠️ NE PAS « SIMPLIFIER » CE GARDE-FOU. Sans lui, tout enregistrement d'un
 * média par un autre chemin (upload, REST, retouche d'image) passe ici avec un
 * tableau vide et EFFACE les tags de la photo, silencieusement. Correctif du
 * 28/05/2026, conservé tel quel au rapatriement.
 */
function sapi_save_attachment_quick_edit_taxonomies($post, $attachment) {
    $taxonomies = ['media_room', 'media_essence'];

    foreach ($taxonomies as $tax_name) {
        $submitted_flag = $tax_name . '_submitted';

        // Notre form n'a pas été soumis → on ne touche pas (évite wipe accidentel)
        if (!isset($attachment[$submitted_flag])) {
            continue;
        }

        // Notre form a été soumis : traiter les valeurs (cochées ou aucune)
        $term_ids = isset($attachment[$tax_name])
            ? array_map('intval', (array) $attachment[$tax_name])
            : [];
        wp_set_object_terms($post['ID'], $term_ids, $tax_name, false);
    }

    return $post;
}
add_filter('attachment_fields_to_save', 'sapi_save_attachment_quick_edit_taxonomies', 10, 2);
