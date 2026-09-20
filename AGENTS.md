# Camagru — Agent instructions

## Source of truth
Before anything else, read `PROJECT_CONTEXT.md` in full. It is authoritative and wins over your
habits and defaults. If this file and the spec conflict, or the spec is ambiguous,
stop and ask the user. Do not silently pick one.

## Project in short
Camagru (école 42): a small PHP web app. Users take a webcam photo or upload one, the
server composites an overlay with GD, and the result goes into a public gallery with
likes and comments. Pass/fail constraints at evaluation (spec section 1): PHP standard
library only, vanilla JS with browser-native APIs only, no Composer/npm/framework, zero
console output (browser and containers), no security leak.

## Workflow
- Work on one feature at a time (for example: Docker + DB, then auth, then gallery,
  then editor). After each one, summarize what you did, how you verified it, and wait
  for the user's go-ahead before starting the next.
- Stay in scope: no extra features, files or dependencies beyond the spec. Bonus items
  only after the mandatory part is done and validated.
- Ask before deviating from the spec, or when a simple solution isn't possible.
- Do not commit or push unless the user explicitly asks.
- No destructive commands without asking (`rm -rf`, `docker volume rm`,
  `docker system prune`, `docker compose down -v`, rewriting git history).

## Code rules
- Clean and humanly understandable. Small functions (one job each), one class per file,
  English identifiers, one naming convention, no dead code, no leftover TODOs.
- Comment every class, function, method and non-trivial block, in English: purpose,
  parameters, return value and logic. For security code, also explain why it exists.
  A student must be able to follow the code from the comments alone.
- No debug output (`var_dump`, `console.log`, temporary `error_log`). The only logging
  allowed is the deliberate file logging described in the spec.
- Do not write unit tests.
- Use only functions from the PHP standard library and the extensions listed in spec
  section 2. Document every non-obvious tool (`msmtp`, `finfo`, `GD`, `pdo_pgsql`) in
  `NOTES.md` with a one-line justification.

## Secrets
- Only edit `.env.example` and `.env`. Never put real secrets in code, logs, examples
  or command output. `.env` stays git-ignored.

## Verification
- Never claim something works without running it. If you can't test it, say so and ask
  the user to verify.
- After each feature, check the matching items of spec section 13, including
  `docker compose logs` (no errors or warnings during normal use) and the browser console.
- `docker compose up --build` must work from a fresh clone with no manual steps.

## Commands
docker compose up --build    # start the whole stack
docker compose logs          # check server-side output
docker compose down          # stop (never add -v without asking)