<?php
/**
 * Script module dependencies for the quiz store.
 *
 * Hand-written because this theme has no build step. Without the explicit
 * @wordpress/interactivity dependency WordPress does not add the runtime to
 * the import map, so the bare import in view.js would fail to resolve.
 *
 * @package Extroverting
 */

$mtime = filemtime( __DIR__ . '/view.js' );

return array(
	'dependencies' => array( '@wordpress/interactivity' ),
	'version'      => $mtime ? (string) $mtime : '0.2.0',
);
