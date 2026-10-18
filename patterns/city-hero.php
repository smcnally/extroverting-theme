<?php
/**
 * Title: City Hero
 * Slug: extroverting/city-hero
 * Categories: extroverting
 * Description: Hero header for a city lander — title, state, and event count. Uses block bindings for city meta.
 */
?>
<!-- wp:group {"style":{"color":{"background":"var(--wp--preset--color--ink)","text":"#ffffff"},"spacing":{"padding":{"top":"var(--wp--preset--spacing--40)","bottom":"var(--wp--preset--spacing--40)"}}},"className":"ext-city-hero","layout":{"type":"constrained"}} -->
<div class="wp-block-group ext-city-hero has-text-color has-background" style="background-color:var(--wp--preset--color--ink);color:#ffffff;padding-top:var(--wp--preset--spacing--40);padding-bottom:var(--wp--preset--spacing--40)">
	<!-- wp:post-title {"level":1,"textColor":"white","fontSize":"xx-large"} /-->
	<!-- wp:paragraph {"metadata":{"bindings":{"content":{"source":"extroverting/city","args":{"field":"ext_state"}}}},"textColor":"on-dark","fontSize":"medium"} -->
	<p class="wp-block-paragraph has-on-dark-color has-text-color has-medium-font-size"></p>
	<!-- /wp:paragraph -->
</div>
<!-- /wp:group -->
