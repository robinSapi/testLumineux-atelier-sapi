<?php
/**
 * « Vous aimerez aussi » sur les fiches accessoires
 *
 * ORIGINE : ce code vivait dans le snippet Code Snippets « Sâpi - Vous aimerez
 * aussi sur les fiches accessoires ». Rapatrié dans le thème le 09/09/2026.
 *
 * PROBLÈME
 * Sur une fiche accessoire, le bloc « Vous aimerez aussi » est rempli par
 * wc_get_related_products(), qui pioche dans la même catégorie. Résultat :
 * une fiche accessoire ne propose que d'autres accessoires, et le visiteur
 * tourne en boucle sur des objets à 8 et 40 € sans jamais voir un luminaire.
 * La fiche « Câble et douille pour lampe de chevet » est la première page du
 * site en impressions Google avec 4 601 sur 3 mois, et c'est un cul-de-sac.
 *
 * CE QUE FAIT CE FICHIER
 * Sur les fiches de la catégorie « accessoires » uniquement, il remplace les
 * produits similaires par des luminaires de la catégorie qui correspond
 * réellement à l'accessoire, les meilleures ventes d'abord.
 *
 * Mapping, d'après l'usage décrit dans chaque fiche :
 *   - câble et douille pour lampe de chevet -> lampes à poser (1m50, interrupteur)
 *   - pavillon, douille et câble            -> suspensions (pavillon DCL, 90 cm)
 *   - pied de lampadaire                    -> lampadaires
 *   - ampoules et reste                     -> suspensions (75 % du chiffre)
 *
 * Si la catégorie visée n'a pas assez de produits, le complément est pris
 * dans l'ensemble du catalogue hors accessoires et carte cadeau, pour ne
 * jamais laisser un trou dans la grille à 4 colonnes.
 *
 * LE BOUTON SOUS LA GRILLE
 * Corrigé côté thème le 09/09/2026 : woocommerce/single-product.php lit
 * désormais les catégories des produits RÉELLEMENT affichés, donc le bouton
 * suit ce que ce fichier met dans la grille. (L'ancien commentaire disait
 * l'inverse et est devenu faux le jour de cette livraison.)
 * ⚠️ Conséquence à connaître : quand la passe 2 ci-dessous complète depuis le
 * catalogue, le bouton nomme la catégorie majoritaire du complément, pas la
 * cible du mapping. C'est cohérent avec l'écran, mais ça bascule sans prévenir.
 *
 * POURQUOI IL EST ICI ET PLUS DANS UN SNIPPET
 * Il forme une seule et même mécanique avec le bouton de single-product.php.
 * Séparés, les deux moitiés se déployaient indépendamment, aucun audit
 * test↔master ne voyait la moitié snippet, et rien ne garantissait que test et
 * prod exécutent le même code.
 *
 * ⚠️ NE JAMAIS INCLURE CE FICHIER SANS SON GARDE-FOU
 * Il déclare ses fonctions au premier niveau, donc PHP les déclare à la
 * COMPILATION, dès l'ouverture du fichier. Si le snippet Code Snippets homonyme
 * est encore actif, l'inclure sans condition donne « Cannot redeclare function »
 * et une page blanche sur tout le site, front ET admin. Un `return;` en tête de
 * CE fichier ne protégerait rien : il s'exécuterait après la compilation.
 * Le garde-fou vit donc au point d'inclusion, dans functions.php :
 *     if (!function_exists('sapi_related_accessoires_vers_luminaires')) {
 *       require_once .../inc/sapi-related-accessoires.php;
 *     }
 * Il évite aussi le double `add_filter` ci-dessous, qui filtrerait la grille
 * deux fois.
 *
 * @package theme-sapi-maison
 */

if (!defined('ABSPATH')) exit;

add_filter( 'woocommerce_related_products', 'sapi_related_accessoires_vers_luminaires', 10, 3 );

/**
 * WooCommerce mélange le tableau juste après notre filtre. On neutralise
 * ce mélange pour le seul appel en cours : le filtre se retire lui-même.
 */
function sapi_related_pas_de_melange( $melanger ) {
	remove_filter( 'woocommerce_product_related_posts_shuffle', 'sapi_related_pas_de_melange', 99 );
	return false;
}

/**
 * Construit les arguments de requête communs aux deux passes.
 *
 * On ramène un pool large trié par date, et le classement par ventes se
 * fait ensuite en PHP. Faire le tri en SQL avec un meta_query
 * OR EXISTS / NOT EXISTS donnerait une clé de tri arbitraire aux produits
 * dépourvus de la méta total_sales : WordPress transforme alors ses INNER
 * JOIN en LEFT JOIN, le premier JOIN ne filtre plus sur la meta_key, et le
 * GROUP BY garde une méta au hasard. Un _thumbnail_id élevé suffirait à
 * placer en tête un produit jamais vendu.
 */
function sapi_related_args_requete( $exclus, $tax_clause ) {

	// On n'exclut les ruptures que si la boutique les masque déjà partout.
	$visibilite_exclue = array( 'exclude-from-catalog' );
	if ( 'yes' === get_option( 'woocommerce_hide_out_of_stock_items' ) ) {
		$visibilite_exclue[] = 'outofstock';
	}

	return array(
		'post_type'           => 'product',
		'post_status'         => 'publish',
		'posts_per_page'      => 100,
		'post__not_in'        => $exclus,
		'fields'              => 'ids',
		'ignore_sticky_posts' => true,
		'no_found_rows'       => true,
		'orderby'             => 'date',
		'order'               => 'DESC',
		'tax_query'           => array(
			'relation' => 'AND',
			$tax_clause,
			array(
				'taxonomy' => 'product_visibility',
				'field'    => 'name',
				'terms'    => $visibilite_exclue,
				'operator' => 'NOT IN',
			),
		),
	);
}

/**
 * Trie des IDs produits par ventes décroissantes, date décroissante à égalité.
 */
function sapi_related_tri_ventes( $ids, $limite ) {

	$ids = array_values( array_unique( array_map( 'absint', (array) $ids ) ) );
	if ( empty( $ids ) || $limite < 1 ) {
		return array();
	}

	// Une seule requête pour toutes les métas : 'fields' => 'ids'
	// n'amorce pas le cache, sinon chaque get_post_meta requêterait.
	update_meta_cache( 'post', $ids );

	$scores = array();
	foreach ( $ids as $rang => $id ) {
		// $rang conserve l'ordre par date, qui sert de départage.
		$scores[ $id ] = array( (int) get_post_meta( $id, 'total_sales', true ), -$rang );
	}

	uasort(
		$scores,
		function ( $a, $b ) {
			$cmp = $b[0] <=> $a[0];
			return $cmp ? $cmp : ( $b[1] <=> $a[1] );
		}
	);

	return array_slice( array_keys( $scores ), 0, $limite );
}

function sapi_related_accessoires_vers_luminaires( $related, $product_id, $args ) {

	// Uniquement à l'affichage d'une fiche produit en front.
	// Écarte aussi l'appel REST de purge des transients WooCommerce.
	if ( ! is_singular( 'product' ) ) {
		return $related;
	}

	$product_id = absint( $product_id );
	if ( ! $product_id ) {
		return $related;
	}

	// Le produit courant est-il un accessoire, sous-catégories comprises ?
	$terme_acc = get_term_by( 'slug', 'accessoires', 'product_cat' );
	if ( ! $terme_acc || is_wp_error( $terme_acc ) ) {
		return $related;
	}
	$ids_acc = array_merge(
		array( (int) $terme_acc->term_id ),
		(array) get_term_children( $terme_acc->term_id, 'product_cat' )
	);
	if ( ! has_term( $ids_acc, 'product_cat', $product_id ) ) {
		return $related;
	}

	// Vers quelle famille de luminaires renvoyer.
	$mapping = array(
		'cable-et-douille-pour-lampe-de-chevet' => 'lampesaposer',
		'pavillon-douille-et-cable'             => 'suspensions',
		'pied-de-lampadaire'                    => 'lampadaires',
	);
	$slug  = get_post_field( 'post_name', $product_id );
	$cible = isset( $mapping[ $slug ] ) ? $mapping[ $slug ] : 'suspensions';

	if ( ! term_exists( $cible, 'product_cat' ) ) {
		return $related;
	}

	// WooCommerce passe 'limit', pas 'posts_per_page'.
	// Le plafond évite de partir sur 1000 lors des purges de transients.
	$limite = isset( $args['limit'] ) ? absint( $args['limit'] ) : 4;
	if ( $limite < 1 || $limite > 12 ) {
		$limite = 4;
	}

	// On respecte les exclusions demandées par l'appelant.
	$exclus = isset( $args['excluded_ids'] ) ? array_map( 'absint', (array) $args['excluded_ids'] ) : array();
	$exclus = array_values( array_unique( array_merge( array( $product_id ), $exclus ) ) );

	// Passe 1 : la catégorie visée.
	$requete = new WP_Query(
		sapi_related_args_requete(
			$exclus,
			array(
				'taxonomy' => 'product_cat',
				'field'    => 'slug',
				'terms'    => array( $cible ),
			)
		)
	);
	$ids = sapi_related_tri_ventes( $requete->posts, $limite );

	// Passe 2 : compléter si la catégorie visée n'a pas assez de produits,
	// sinon la grille à 4 colonnes affiche un trou.
	if ( count( $ids ) < $limite ) {
		$complement = new WP_Query(
			sapi_related_args_requete(
				array_values( array_unique( array_merge( $exclus, $ids ) ) ),
				array(
					'taxonomy' => 'product_cat',
					'field'    => 'slug',
					'terms'    => array( 'accessoires', 'carte-cadeau' ),
					'operator' => 'NOT IN',
				)
			)
		);
		$ids = array_merge( $ids, sapi_related_tri_ventes( $complement->posts, $limite - count( $ids ) ) );
	}

	// Rien trouvé : on rend la main au comportement WooCommerce d'origine.
	if ( empty( $ids ) ) {
		return $related;
	}

	// WooCommerce mélange juste après nous. On l'en empêche pour cet appel.
	add_filter( 'woocommerce_product_related_posts_shuffle', 'sapi_related_pas_de_melange', 99 );

	return $ids;
}
