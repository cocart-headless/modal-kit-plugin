<?php
/**
 * Tidies the Playground demo after bin/demos.php has run: removes the
 * plugins and sample content WordPress ships with, and gives a block theme
 * a footer with links for the demo instead of its placeholder ones.
 *
 * `npm run blueprint` embeds it in the demo's blueprint. Never run on a real
 * site: it deletes the sample page and post.
 *
 * @package ModalKit
 */

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals -- A Playground script, not plugin code.

defined( 'ABSPATH' ) || exit;

if ( ! defined( 'MODAL_KIT_DEMO_SITE' ) ) {
	return;
}

require_once ABSPATH . 'wp-admin/includes/plugin.php';
require_once ABSPATH . 'wp-admin/includes/file.php';

// WordPress's own sample plugins.
$samples = array_values( array_intersect( array( 'hello.php', 'hello-dolly/hello.php', 'akismet/akismet.php' ), array_keys( get_plugins() ) ) );

if ( $samples ) {
	deactivate_plugins( $samples, true );
	delete_plugins( $samples );
}

// The sample page sits in the header menu; the demos have their own pages.
foreach ( array( 'sample-page' => 'page', 'hello-world' => 'post' ) as $slug => $type ) {
	$sample = get_page_by_path( $slug, OBJECT, $type );

	if ( $sample ) {
		wp_delete_post( $sample->ID, true );
	}
}

if ( ! wp_is_block_theme() ) {
	return;
}

$index    = get_page_by_path( 'modal-kit-demos' );
$together = get_page_by_path( 'modal-kit-demos/all-at-once' );
$styles   = get_page_by_path( 'modal-kit-demos/styles' );
$shop     = function_exists( 'wc_get_page_id' ) && wc_get_page_id( 'shop' ) > 0 ? get_permalink( wc_get_page_id( 'shop' ) ) : '';
$cart     = function_exists( 'wc_get_page_id' ) && wc_get_page_id( 'cart' ) > 0 ? get_permalink( wc_get_page_id( 'cart' ) ) : '';
$pro      = class_exists( '\ModalKit\Pro\Plugin' );

/**
 * A Navigation link block.
 *
 * @param string $label    Label.
 * @param string $url      Address.
 * @param bool   $external Opens in a new tab, out of the demo.
 */
$item = static function ( $label, $url, $external = false ) {
	$attrs = array(
		'label' => $label,
		'url'   => $url,
	);

	if ( $external ) {
		$attrs['opensInNewTab'] = true;
	}

	return '<!-- wp:navigation-link ' . wp_json_encode( $attrs, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) . ' /-->';
};

/**
 * A vertical list of links under a small heading.
 *
 * @param string   $title Heading.
 * @param string[] $links Navigation link blocks.
 */
$column = static function ( $title, $links ) {
	return '<!-- wp:group {"style":{"spacing":{"blockGap":"var:preset|spacing|20"}},"layout":{"type":"flex","orientation":"vertical"}} --><div class="wp-block-group">'
		. '<!-- wp:paragraph {"fontSize":"small"} --><p class="has-small-font-size"><strong>' . esc_html( $title ) . '</strong></p><!-- /wp:paragraph -->'
		. '<!-- wp:navigation {"overlayMenu":"never","layout":{"type":"flex","orientation":"vertical"},"fontSize":"small"} -->' . implode( '', $links ) . '<!-- /wp:navigation -->'
		. '</div><!-- /wp:group -->';
};

$demos = array();
if ( $index ) {
	$demos[] = $item( 'Welcome', get_permalink( $index ) );
	$demos[] = $item( 'Every demo', get_permalink( $index ) . '#demos' );
}
if ( $styles ) {
	$demos[] = $item( 'Styles', get_permalink( $styles ) );
}
if ( $together ) {
	$demos[] = $item( 'All at once', get_permalink( $together ) );
}
$demos[] = $item( 'Your modals', admin_url( 'edit.php?post_type=mk_modal' ) );

$site = array(
	$item( 'modalkitwp.com', 'https://modalkitwp.com/', true ),
	$item( 'Docs', 'https://modalkitwp.com/docs/', true ),
	$item( $pro ? 'Pricing' : 'Modal Kit Pro', $pro ? 'https://modalkitwp.com/pricing/' : 'https://modalkitwp.com/pro/', true ),
	$item( 'Support', 'https://modalkitwp.com/support/', true ),
);

$footer = '<!-- wp:group {"align":"full","style":{"spacing":{"padding":{"top":"var:preset|spacing|60","bottom":"var:preset|spacing|50","left":"var:preset|spacing|50","right":"var:preset|spacing|50"}}},"layout":{"type":"constrained","contentSize":"1200px"}} -->'
	. '<div class="wp-block-group alignfull" style="padding-top:var(--wp--preset--spacing--60);padding-right:var(--wp--preset--spacing--50);padding-bottom:var(--wp--preset--spacing--50);padding-left:var(--wp--preset--spacing--50)">'
	. '<!-- wp:group {"layout":{"type":"flex","flexWrap":"wrap","justifyContent":"space-between","verticalAlignment":"top"}} --><div class="wp-block-group">'
	. '<!-- wp:group {"style":{"spacing":{"blockGap":"var:preset|spacing|20"}},"layout":{"type":"flex","orientation":"vertical"}} --><div class="wp-block-group">'
	. '<!-- wp:site-title {"level":0} /-->'
	. '<!-- wp:site-tagline {"fontSize":"small"} /-->'
	. '</div><!-- /wp:group -->'
	. '<!-- wp:group {"style":{"spacing":{"blockGap":"var:preset|spacing|60"}},"layout":{"type":"flex","flexWrap":"wrap","verticalAlignment":"top"}} --><div class="wp-block-group">'
	. $column( 'This demo', $demos )
	. $column( 'Modal Kit', $site )
	. '</div><!-- /wp:group -->'
	. '</div><!-- /wp:group -->'
	. '<!-- wp:paragraph {"fontSize":"small","style":{"spacing":{"margin":{"top":"var:preset|spacing|50"}}}} --><p class="has-small-font-size" style="margin-top:var(--wp--preset--spacing--50)">A demo site in your browser, made by WordPress Playground. Nothing you do here is saved or sent anywhere.</p><!-- /wp:paragraph -->'
	. '</div><!-- /wp:group -->';

// The header's menu: the theme's Navigation block shows the newest menu when
// it has none of its own, instead of a list of every page (WooCommerce's
// Cart, Checkout and My account included).
$menu = array();
if ( $index ) {
	$menu[] = $item( 'Every demo', get_permalink( $index ) . '#demos' );
}
if ( $styles ) {
	$menu[] = $item( 'Styles', get_permalink( $styles ) );
}
if ( $together ) {
	$menu[] = $item( 'All at once', get_permalink( $together ) );
}
if ( $shop ) {
	$menu[] = $item( 'Shop', $shop );
}
if ( $cart ) {
	$menu[] = $item( 'Cart', $cart );
}

wp_insert_post(
	array(
		'post_type'    => 'wp_navigation',
		'post_status'  => 'publish',
		'post_title'   => 'Demo menu',
		'post_content' => implode( '', $menu ),
	)
);

// A saved copy of the theme's footer part, as the Site Editor makes one.
$existing = get_posts(
	array(
		'post_type'      => 'wp_template_part',
		'name'           => 'footer',
		'post_status'    => 'any',
		'posts_per_page' => 1,
		// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query -- One lookup in a demo.
		'tax_query'      => array(
			array(
				'taxonomy' => 'wp_theme',
				'field'    => 'name',
				'terms'    => get_stylesheet(),
			),
		),
	)
);

$part = wp_insert_post(
	array(
		'ID'           => $existing ? $existing[0]->ID : 0,
		'post_type'    => 'wp_template_part',
		'post_status'  => 'publish',
		'post_name'    => 'footer',
		'post_title'   => 'Footer',
		'post_content' => $footer,
	)
);

if ( $part && ! is_wp_error( $part ) ) {
	wp_set_object_terms( $part, get_stylesheet(), 'wp_theme' );
	wp_set_object_terms( $part, 'footer', 'wp_template_part_area' );
}
