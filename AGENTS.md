# Project development requirements

## Static analysis

- Run `composer analyse` after changing PHP code.
- New or changed PHP code must pass Larastan/PHPStan at the level configured in `phpstan.neon`.
- Fix reported type errors instead of suppressing them. Add an ignore only when the report is a demonstrated false positive, and document the reason next to the ignore.

## Tests

- Every behavior change or bug fix must include relevant automated tests.
- Prefer feature tests for HTTP, console, database, and end-to-end application behavior; use unit tests for isolated logic.
- Run the smallest relevant test set while developing, then run `composer test` before considering the work complete.
- Run `composer check` to execute both static analysis and the full test suite before handing work off.
