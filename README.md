# Prime Agent Web

An experimental, local-first web dashboard for managing [Prime Agent](https://app.primeintellect.ai/) sessions across your Git projects.

> [!WARNING]
> **Early development:** Prime Agent Web is an unfinished prototype. Features, setup steps, and data structures may change without notice. Do not rely on it for production workflows yet.

> [!IMPORTANT]
> **Independent project:** This is a community project and is not affiliated with, endorsed by, sponsored by, or maintained by Prime Intellect. Prime Agent and Prime Intellect are trademarks of their respective owners.

## What it does

Prime Agent Web connects to the Prime Agent CLI and daemon running on your machine. It does not use demonstration data: the projects you connect and the sessions shown in the dashboard are real local resources.

- Detects your local Prime Agent installation
- Starts the Prime Agent daemon when needed
- Connects existing local Git repositories as projects
- Starts daemon-backed agents with a name and goal
- Lists and filters sessions reported by the local daemon
- Keeps application data local in SQLite

## Tech stack

| Layer | Technology |
| --- | --- |
| Backend | PHP 8.3+, Laravel 13 |
| Frontend | Blade templates, vanilla JavaScript |
| Styling | Tailwind CSS 4 |
| Build tooling | Vite 8, npm |
| Database | SQLite |
| Process integration | Symfony Process |
| Testing and analysis | PHPUnit 12, Larastan/PHPStan |

## Requirements

- PHP 8.3 or newer
- [Composer](https://getcomposer.org/)
- Node.js and npm
- Git
- Prime Agent CLI, available as `prime-agent` in the web process `PATH` or configured with `PRIME_AGENT_BINARY`

## Getting started

Clone the repository, install its dependencies, prepare the local environment, and build the frontend:

```bash
git clone https://github.com/nick-friedrich/prime-agent-web.git
cd prime-agent-web
composer setup
```

Start the development services:

```bash
composer dev
```

The command starts the Prime Agent daemon when it is available, then runs Laravel, Vite, the queue worker, and the application log viewer. Open the local URL printed in the terminal and follow the dashboard checklist to connect a Git repository and start an agent.

If Prime Agent is installed outside the `PATH` visible to the web process, add its absolute path to `.env`:

```dotenv
PRIME_AGENT_BINARY=/absolute/path/to/prime-agent
```

## Development

Run the complete test suite and static analysis:

```bash
composer check
```

Build the production frontend assets:

```bash
npm run build
```

## Security

Prime Agent can modify files and run commands inside connected repositories. Only connect projects you trust, review their instructions, and inspect agent changes before committing them. This dashboard is intended to run locally and does not currently provide authentication for deployment on a public server.

## Contributing

Issues and pull requests are welcome while the project takes shape. Because it is still early, consider opening an issue before investing in a large change.
