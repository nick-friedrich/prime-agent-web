# Prime Agent Web

A local Laravel dashboard for Prime Agent. It starts with no demonstration data and reads agent sessions directly from the installed Prime Agent daemon.

## Start

```bash
composer setup
composer dev
```

`composer dev` starts the Prime Agent daemon when available, followed by Laravel, Vite, the queue worker, and application logs. Opening the dashboard directly also attempts to start the daemon automatically.

The dashboard then guides you through:

1. Installing/detecting Prime Agent
2. Starting the local runtime
3. Connecting an existing local Git repository
4. Starting a real agent with a goal

If the CLI is installed outside the web process path, add its absolute location to `.env`:

```dotenv
PRIME_AGENT_BINARY=/absolute/path/to/prime-agent
```

## Verify

```bash
composer test
npm run build
```
