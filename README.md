# Extroverting

WordPress theme for the Extroverting multi-city local events calendar: `theme.json` (v3) with a custom palette, raw-PHP templates under `templates/`, template parts under `parts/`. Templates render through `template-shell.php`, which wraps each fragment in a full document.

Designed to be used with [extroverting-core](https://github.com/smcnally/extroverting-core), which registers the CPTs these templates render; dashboard/account templates assume it is active.

## Install

The repo root **is** the theme directory — clone it straight into `wp-content/themes/` (e.g. `extroverting-theme/`) and activate "Extroverting". Subsequent updates are a plain `git pull`.

## Development

```bash
composer install
composer phpstan   # PHPStan level 5 with WordPress stubs
composer test      # PHPUnit via wp-phpunit (needs a local MySQL; see tests/wp-config.php)
```

CI (`.github/workflows/ci.yml`) runs PHPStan + PHPUnit on PHP 8.1 and 8.3 for every push to `main` and PR.

## Past events never linger on index pages

The event listings (home, city landers, event pages' "More in …") are
queried live against `ext_date >= today`, but a full-page cache keeps
serving yesterday's HTML — events dated before today stay visible for
days. Three defenses ship with the theme (`inc/class-cache-schedule.php`):

1. **Local-date cutoff** — `extroverting_theme_event_query()` compares
   `ext_date` against the site's local today (`current_time('Y-m-d')`),
   not UTC. The UTC date rolls over at 4:00 PM Pacific, which would
   otherwise drop same-day events from every index page in the evening.
2. **Nightly purge** — a WP-Cron event at local midnight + 5 min
   (`extroverting_nightly_cache_purge`) purges the page cache
   (`litespeed_purge_all` when LiteSpeed Cache is active), drops the
   `ext_city_event_counts_v1` transient, and fires
   `extroverting_nightly_cache_purged` for anything else. The scheduler
   reseats events that drifted (timezone/DST changes) on every `init`.
3. **Midnight TTL cap** — index pages cap their cache TTL at the next
   local midnight (`litespeed_control_set_ttl`), so even with WP-Cron
   dead a cached listing expires right after the date rolls over.

Cloudflare still applies its own HTML TTL (~2 h) to the origin responses;
without a purge token the worst case after midnight is that window.

## Event hero images and "About this event"

Imported events often carry neither a hero image nor a rich body, so
`inc/class-event-hero.php` resolves both at render time (transient-cached,
so at most one external request per source/venue per TTL window):

- `Event_Hero::hero( $event_id )` walks: the `extroverting_event_hero_url`
  filter → meta `ext_hero_url` → featured image → Open Graph/Twitter
  image on the event's source page (`ext_source_url`) → an image of the
  venue itself from Wikimedia Commons (credited in the hero, e.g. the
  Wende Museum). Returns `''` when nothing resolves and the template
  falls back to the venue-name placeholder.
- `Event_Hero::about( $event_id )` renders the imported description; when
  the event has none, it falls back to the description on the source
  listing page. `Event_Hero::source_link( $event_id )` gives the "View the
  original … listing" link.

External fetches never block a page on failure: they time out in 8 s and
are remembered (12 h hit / 6 h miss) in `ext_hero_*`, `ext_srcpage_*` and
`ext_venueimg_*` transients.

## Quiz blocks (GP-IQ)

Four server-rendered blocks live in `blocks/` and are registered from
`inc/quiz/class-quiz-blocks.php`. There is no build step.

| Block | Job |
|---|---|
| `extroverting/quiz` | Container. Resolves the event's quiz, seeds the `extroverting/quiz` Interactivity store, renders the children. |
| `extroverting/quiz-question` | Intro copy, progress, and question text. |
| `extroverting/quiz-answer` | Answer options and Back/Next; writes answers into shared state. |
| `extroverting/quiz-result` | Scored rows, banners, cautions, methodology, consumer line; Start over. |

Insert the **Quiz (provider-backed)** pattern on a page, then set the **Event ID**
in the Quiz block. `0` means "the `ext_event` being viewed", so the same markup
can move into an event template later. The editor never re-types quiz copy: it
comes from the source.

### Source

`Source` asks `GET /wp-json/extroverting/v1/quiz/{eventId}` — the existing
`gpiq-quiz-provider` seam. A different source plugs in with a filter:

```php
add_filter(
	'extroverting_theme_quiz_definition',
	function ( $definition, $event_id, $quiz_id ) {
		return $my_source->quiz( $event_id, $quiz_id );
	},
	10,
	3
);
```

`Normalizer` accepts IQ's `quiz-config.json` (root `quizzes`), the provider
endpoint shape (`questions`), and the bundled `quizQuestions` shape. It keeps
question/answer text, icons, counter-evidence notes, intro/success copy, and the
consumer-info line; markup is stripped so output is always escaped. Keep the
definition aligned with the provider that scores `/submit` — the blocks render
text, they do not score.

### Inter-block communication

The three blocks never touch each other's DOM. The container owns
`state.current`; Answer writes answers and advances the index; Question and
Result react; Result's "Start over" resets the same store. One quiz per page —
the store has a single `current` — enforced with `supports.multiple: false`.

### Routes

`/events/{slug}/quiz` is wired in `functions.php` (`add_rewrite_rule` plus
`templates/ext-event-quiz.html`), and the event CTA uses it. Flush rewrite rules
once after deploying. The core age gate covers the event route; quiz surfaces
are excluded from the page cache.

`/results` is still deferred. The current provider endpoint also returns
questions and the scored result but not IQ's `intro`/`success` copy — when the
provider passes that through, the blocks render it unchanged.

## License

GPL-2.0-or-later
