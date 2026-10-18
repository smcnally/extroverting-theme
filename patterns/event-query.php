<?php
/**
 * Title: Upcoming Events (Query Loop)
 * Slug: extroverting/event-query
 * Categories: extroverting
 * Description: A Query Loop that lists upcoming ext_event posts for the current city. Filter by city via URL ?city= or block context. Uses REST ordering (meta ext_date ASC).
 * Inserter: true
 */
?>
<!-- wp:query {"query":{"perPage":10,"postType":"ext_event","order":"asc","orderBy":"date","meta_query":[{"key":"ext_date","value":"","compare":">="}]},"className":"ext-eventlist","layout":{"type":"default"}} -->
<div class="wp-block-query ext-eventlist">
	<!-- wp:post-template -->
		<!-- wp:group {"className":"ext-eventrow","layout":{"type":"constrained"}} -->
		<div class="wp-block-group ext-eventrow">
			<!-- wp:post-title {"isLink":true,"level":3} /-->
			<!-- wp:paragraph {"metadata":{"bindings":{"content":{"source":"extroverting/event","args":{"field":"ext_venue"}}}},"fontSize":"small"} -->
			<p class="wp-block-paragraph has-small-font-size"></p>
			<!-- /wp:paragraph -->
		</div>
		<!-- /wp:group -->
	<!-- /wp:post-template -->
	<!-- wp:query-pagination {"layout":{"type":"flex","justifyContent":"center"}} -->
		<!-- wp:query-pagination-previous /-->
		<!-- wp:query-pagination-numbers /-->
		<!-- wp:query-pagination-next /-->
	<!-- /wp:query-pagination -->
	<!-- wp:query-no-results -->
		<!-- wp:paragraph {"align":"center"} -->
		<p class="has-text-align-center">No events yet — new ones land regularly. Check back soon.</p>
		<!-- /wp:paragraph -->
	<!-- /wp:query-no-results -->
</div>
<!-- /wp:query -->
