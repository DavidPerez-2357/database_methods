# Code Quality Standards

## Senior Developer Mindset

When generating or reviewing code for this project, apply the standards of a senior developer:

- **Clean code**: Keep methods small, focused, and easy to understand. Favour readability over cleverness.
- **Well-structured**: Place every piece of code in the correct layer (see [architecture.md](architecture.md)). Core logic belongs in `src/`; driver-specific overrides go in `src/drivers/`.
- **Descriptive naming**: Use clear, self-documenting names for variables, methods, classes, and files. Avoid abbreviations unless widely understood (e.g., `id`, `sql`, `db`, `pdo`).
- **Comments**: Add comments only when they explain _why_ something is done, not _what_ it does. Complex logic, non-obvious decisions, or workarounds warrant a brief explanation.
- **Language conventions**: Follow PHP best practices as they apply to the PHP 5.4+ constraint.

## Version Awareness

Always consult `README.md` and `CONTRIBUTING.md` and use features compatible with **PHP 5.4+**. Do **not** suggest patterns, APIs, or syntax introduced in later PHP versions.

| Feature to avoid                         | Introduced in |
| ---------------------------------------- | ------------- |
| Variadic functions (`...$args`)          | PHP 5.6       |
| Scalar type declarations                 | PHP 7.0       |
| Return type hints                        | PHP 7.0       |
| `Throwable` interface                    | PHP 7.0       |
| Null coalescing operator (`??`)          | PHP 7.0       |
| Named arguments                          | PHP 8.0       |
| Match expressions                        | PHP 8.0       |
| Union types / intersection types         | PHP 8.0 / 8.1 |
| Enums                                    | PHP 8.1       |
| `readonly` properties                    | PHP 8.1       |

## Performance

- **Avoid redundant queries**: Design methods so callers do not need to run a SELECT immediately before or after a write.
- **Lazy SQL building**: `Query` builds its SQL string lazily: invalidate the cache (`$this->query = null`) whenever a setter changes state.
- **Fluent API**: New `Query` setter methods must return `$this` to support method chaining.

## Security

- **SQL injection prevention (identifiers)**: Any identifier interpolated directly into an SQL string **must** be validated before use. Use `SqlValidator::assertTable()` for table names, `SqlValidator::assertColumnIdentifier()` for INSERT/UPDATE column names, `SqlValidator::assertQualifiedIdentifier()` for qualified identifiers (e.g. `u.email`), and `SqlValidator::assertIdentifier()` for plain identifiers.
- **SQL injection prevention (values)**: Always use parameterised queries (pass values as a `$params` array to PDO); never concatenate user-supplied values directly into SQL strings.
- **No secrets in source code**: Never commit credentials, passwords, or other sensitive values.
- **Dependency hygiene**: The library has zero external dependencies by design; do not introduce Composer packages.
