<?php
/**
 * Title: Event Card (with Block Bindings)
 * Slug: extroverting/event-card-binding
 * Categories: extroverting
 * Description: An event row built from core blocks + Extroverting block bindings. Works in the Site Editor without PHP — bindings resolve ext_date, ext_venue, etc. from the current ext_event in a Query Loop.
 * Inserter: true
 */
?>
<!-- wp:group {"className":"ext-eventrow","layout":{"type":"constrained"}} -->
<div class="wp-block-group ext-eventrow">
	<!-- wp:columns {"verticalAlignment":"center"} -->
	<div class="wp-block-columns are-vertically-aligned-center">
		<!-- wp:column {"width":"72px"} -->
		<div class="wp-block-column" style="flex-basis:72px">
			<!-- wp:group {"className":"ext-datecell","layout":{"type":"constrained"}} -->
			<div class="wp-block-group ext-datecell">
				<!-- wp:paragraph {"metadata":{"bindings":{"content":{"source":"extroverting/event","args":{"field":"ext_date"}}}},"className":"dow","fontSize":"small"} -->
				<p class="wp-block-paragraph dow has-small-font-size"></p>
				<!-- /wp:paragraph -->
			</div>
			<!-- /wp:group -->
		</div>
		<!-- /wp:column -->
		<!-- wp:column -->
		<div class="wp-block-column">
			<!-- wp:post-title {"isLink":true,"level":3} /-->
			<!-- wp:paragraph {"metadata":{"bindings":{"content":{"source":"extroverting/event","args":{"field":"ext_time"}}}},"className":"ext-tnum","fontSize":"small"} -->
			<p class="wp-block-paragraph ext-tnum has-small-font-size"></p>
			<!-- /wp:paragraph -->
			<!-- wp:paragraph {"metadata":{"bindings":{"content":{"source":"extroverting/event","args":{"field":"ext_venue"}}}},"className":"ext-evmeta","fontSize":"small"} -->
			<p class="wp-block-paragraph ext-evmeta has-small-font-size"></p>
			<!-- /wp:paragraph -->
			<!-- wp:post-terms {"term":"ext_event_cat","className":"ext-tag ext-tag-cat"} /-->
		</div>
		<!-- /wp:column -->
	</div>
	<!-- /wp:columns -->
</div>
<!-- /wp:group -->
