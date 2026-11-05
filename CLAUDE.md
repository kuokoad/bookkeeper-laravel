@AGENTS.md

<!-- Shared agent instructions live in AGENTS.md. Add only Claude Code–specific notes below. -->

## Claude Code notes
- Browser testing and screenshots: `playwright-cli` only, saved to `screenshots/`.
- Before claiming work is done: `/verify`; for changes that touch figures, also run `php artisan nuna:reconcile`.
- Bugs and wrong figures: `/investigate` before editing.
- Review before committing: `/review`.
- Architecture changes: `/plan-eng-review docs/ARCHITECTURE.md`.
