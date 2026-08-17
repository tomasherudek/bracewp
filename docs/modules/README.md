# Module specs

Every module starts as a spec file in this directory, named `NNN-<slug>.md` (for example `001-staging-guard.md`). No code is written before the spec is approved.

A spec must answer these narrowing questions:

1. Problem and who it is for (dev, owner, or both).
2. Scope fence: enumerate explicitly what is in and what is out. For anything search-replace shaped: strings only? serialized arrays? JSON?
3. Destructive? If yes, all three are required: dry-run UX, backup scope, undo path. Destructive work must go through the `Brace\Services\DestructiveOperation` contract.
4. Requirements (PHP extensions, WP features, server abilities) plus the graceful-degradation message shown when they are unmet.
5. Settings surface (fewest possible) and defaults.
6. WP-CLI surface (`wp brace <module> <cmd>`); every capability must be scriptable.
7. Test dataset: which fixture in `tests/fixtures/` proves correctness.
8. Inspiration plugin(s): mine their documentation for a feature checklist and gaps.
9. Out-of-scope list: what we deliberately do not do.

Flow: idea, spec draft here, spec approved, build, PR gates (PHPCS, PHPStan, tests, Plugin Check), release. The spec doubles as raw material for the article documenting the build.
