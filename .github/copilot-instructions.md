# Copilot Coding Agent Instructions: DatabaseMethods

## Project Overview

DatabaseMethods is a lightweight PHP library that cuts database boilerplate down to its essentials. Its public API centers on three focused classes: `Query` (SQL builder), `Database` (PDO wrapper), and `PdoParameterBuilder` (named-parameter helper), supported internally by `SqlValidator`, `SqlDialect`, and `QueryRunner`. Everything works without Composer or external dependencies.

> Compatible with PHP **5.4** and above. Supports MySQL, PostgreSQL, SQLite, and SQL Server.

## Tech Stack

| Layer           | Technology                                              |
| --------------- | ------------------------------------------------------- |
| Language        | PHP 5.4+                                                |
| Database access | PDO (built-in PHP extension)                            |
| Drivers         | `Mysql`, `Postgres`, `Sql` (SQL Server), `Sqlite`       |
| Testing         | Custom dependency-free runner (`tests/run.php`)         |
| Package manager | None (plain `require_once`, no Composer)                |

## Getting Started

```bash
# No install step required: just require the entry-point file in your code:
# require_once 'DatabaseMethods.php';

# Run the test suite
php tests/run.php
```

## Detailed Instructions

Consult the following files for in-depth guidelines:

- [.github/instructions/code-quality.md](.github/instructions/code-quality.md): Senior developer mindset, PHP version awareness, performance and security rules.
- [.github/instructions/architecture.md](.github/instructions/architecture.md): Project structure, layered architecture, and key invariants for each class.
- [.github/instructions/conventions.md](.github/instructions/conventions.md): Naming, PHP style, language rules, no external dependencies, and testing.
- [.github/instructions/workflow.md](.github/instructions/workflow.md): Commit messages (gitmoji), pull request titles, and branch naming.
