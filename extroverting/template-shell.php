<?php
/**
 * Full-document wrapper for this theme's raw-PHP fragment templates.
 *
 * This theme's templates are .html fragments containing raw PHP. The
 * block-theme canvas (wp-includes/template-canvas.php) renders templates via
 * do_blocks(), which does NOT execute that PHP — it prints the source as
 * literal text. functions.php therefore routes every frontend request through
 * this shell instead: it includes the resolved fragment as PHP inside a proper
 * HTML document with wp_head()/wp_footer().
 *
 * @package Extroverting
 */

defined( 'ABSPATH' ) || exit;

$fragment = isset( $GLOBALS['extroverting_fragment'] ) ? $GLOBALS['extroverting_fragment'] : '';
?>
<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
	<meta charset="<?php bloginfo( 'charset' ); ?>" />
	<meta name="viewport" content="width=device-width, initial-scale=1" />
	<?php wp_head(); ?>
</head>
<body <?php body_class(); ?>>
<?php wp_body_open(); ?>
<?php
if ( $fragment && is_file( $fragment ) ) {
	include $fragment;
}
?>
<?php wp_footer(); ?>
</body>
</html>
