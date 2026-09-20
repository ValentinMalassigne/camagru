# Camagru — Agent instructions

## Source of truth
Before anything else, read `PROJECT_CONTEXT.md` in full. It is authoritative and wins over your
habits and defaults. If this file and the spec conflict, or the spec is ambiguous,
stop and ask the user. Do not silently pick one.

## When an instruction is a problem
If any instruction in `AGENTS.md`, `PROJECT_CONTEXT.md` or `COMPATIBILITY.md` seems
impossible to follow, unclear, contradictory, outdated, or harmful to the project (for
example it blocks a feature, conflicts with another rule, or a clearly better option
exists), stop and ask the user for their opinion. Do not ignore it, reinterpret it or work
around it silently. Explain the issue in a few lines, give your suggested option(s) with
their trade-offs, and wait for the answer. If the user decides to change the instruction,
propose the exact new wording. Do not edit `AGENTS.md` or `PROJECT_CONTEXT.md` yourself
unless the user asks.

## Project in short
Camagru (école 42): a small PHP web app. Users take a webcam photo or upload one, the
server composites an overlay with GD, and the result goes into a public gallery with
likes and comments. Pass/fail constraints at evaluation (spec section 1): PHP standard
library only, vanilla JS with browser-native APIs only, no Composer/npm/framework,
no console output (browser and containers), no security leak.
Project choice: plain hand-written CSS, no CSS framework of any kind.

## Workflow
- Work on one feature at a time (for example: Docker + DB, then auth, then gallery,
  then editor). After each one, summarize what you did, how you verified it, and give the user a short Firefox 41 / Chrome 46 smoke-test
  list for that feature (pages load, narrow layout, clean console, the feature's flow).
  Wait for the user's go-ahead, which includes their VM results, before starting the next.
- Browser compatibility: the user tests Firefox 41 and Chrome 46 in a VM. You cannot run
  these browsers, so never claim a feature is compatible; ask the user to verify. When the
  user reports a problem, fix it with a workaround and add an entry to `COMPATIBILITY.md`.
- When all mandatory features are done, do these two steps before anything else, in this order:
  1. **Security review.** Remind the user that it is time for the final security review,
     then run it with them using the checklist in spec section 9. The mandatory part is
     not finished until it is done.
  2. **Full compatibility pass.** The user re-tests every page and flow in Firefox 41 and
     Chrome 46 in the VM. Fix what fails and update `COMPATIBILITY.md`.
- Stay in scope: no extra features, files or dependencies beyond the spec. Bonus items
  only after the mandatory part is done and validated.
- Ask before deviating from the spec, or when a simple solution isn't possible.
- Do not commit or push unless the user explicitly asks.
- No destructive commands without asking (`rm -rf`, `docker volume rm`,
  `docker system prune`, `docker compose down -v`, rewriting git history).

## Console output policy
The goal is zero output in every console (browser and containers). If a startup line
from an official image (Postgres, nginx, php-fpm) cannot be silenced, it is accepted,
but only after you tried to silence it and listed it in `NOTES.md` with its source.
Anything produced while the app is running (errors, warnings, request or access lines,
notices from our own code) is never accepted.

## Code rules
- Clean and humanly understandable. Small functions (one job each), one class per file,
  English identifiers, one naming convention, no dead code, no leftover TODOs.
- Comment every class, function, method and non-trivial block, in English: purpose,
  parameters, return value and logic. For security code, also explain why it exists.
  A student must be able to follow the code from the comments alone.
- No debug output (`var_dump`, `console.log`, temporary `error_log`). The only logging
  allowed is the deliberate file logging described in the spec.
- Do not write unit tests.
- Use only functions from the PHP standard library and the extensions and tools listed in
  the "Allowed PHP extensions and tools" table of spec section 2. If you need anything
  else, stop and ask. Document every non-obvious tool (`msmtp`, `fileinfo`/`finfo`, `GD`,
  `pdo_pgsql`, `openssl`) in `NOTES.md` with a one-line justification.
- Client code follows the defensive coding rules of spec section 11 (ES5-style JS, webcam
  wrapper, no `canvas.toBlob`, flexbox-only CSS). Before using any browser API, syntax or
  CSS feature outside those rules, read `COMPATIBILITY.md` and reuse its workarounds.

## Compatibility log (`COMPATIBILITY.md`)
- Keep a living log of every function, API, syntax or CSS feature that caused an issue in
  Firefox 41 or Chrome 46, with its workaround (format in spec section 11).
- Read it before writing client code. Add or update an entry whenever the user reports a
  compatibility problem. Never delete entries.

## Secrets
- Only a git-ignored `.env` is used. There is no `.env.example`.
- The user creates and fills `.env` by hand. Never create, generate or invent values
  for it. Variable names are listed in spec section 10; if you need a new variable,
  ask the user to add it and note its name in `NOTES.md`.
- Never put real values (secrets, addresses, hosts) in any committed file (code, docker
  files, docs, logs, command output). Reference variables by name only.
- If `.env` is missing when you need to run the stack, ask the user to create it.

## Verification
- Never claim something works without running it. If you can't test it (this includes
  anything in Firefox 41 and Chrome 46), say so and ask the user to verify.
- After each feature, check the matching items of spec section 13, including
  `docker compose logs` (see the console output policy) and the browser console.
- `docker compose up --build` must work from a fresh clone once the user has created
  `.env` by hand. That is the only manual step: the DB schema is created automatically
  when it is missing (spec section 10).
- Verify the DB setup on an empty database and on an already-initialised one. Wiping the
  DB volume to test the empty case needs the user's approval first.

## Commands
docker compose up --build    # start the whole stack (needs .env created by hand)
docker compose logs          # check server-side output
docker compose down          # stop (never add -v without asking)