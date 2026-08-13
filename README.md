# Prime Agent Web

A Laravel and TailwindCSS mission-control interface for orchestrating multiple Prime Agent projects and autonomous agents.

## Features

- Multi-project workspace with project-level filtering
- Agent deployment with model, project, and goal configuration
- Agent status controls for running, paused, and idle states
- Persistent tasks, progress, token usage, and runtime activity
- Responsive desktop and mobile layouts
- Keyboard-accessible quick search (`⌘/Ctrl + K`)

## Local setup

```bash
composer install
npm install
cp .env.example .env
php artisan key:generate
touch database/database.sqlite
php artisan migrate --seed
npm run build
php artisan serve
```

Run the verification suite with:

```bash
php artisan test
npm run build
```
