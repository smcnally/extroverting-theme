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

### Not wired here

`/events/{slug}/quiz` and `/results` still need the deferred rewrite + template
work noted in the project's `wp-content/README.md`; the blocks are page-ready
meanwhile.
The current provider endpoint also returns questions and the scored result but
not IQ's `intro`/`success` copy — when the provider passes that through, the
blocks render it unchanged.

## License

GPL-2.0-or-later
