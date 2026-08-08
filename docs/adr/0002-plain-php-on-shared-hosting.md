# Plain structured PHP on ICDSoft shared hosting — no framework, no Node

The platform is built as plain, lightly structured modern PHP (PDO, a thin router, server-rendered pages, vanilla JS for live behavior) with numbered SQL migrations, deployed by uploading files over SSH. We deliberately avoid a PHP framework (Laravel, etc.) and the Node/WebApps runtime.

The target is ICDSoft shared hosting, where PHP + MySQL + cron are confidently available on the current plan, while the persistent-process/Node "WebApps" platform is only a maybe (not present on every plan; WebSocket support undocumented). Plain PHP has no build step to break, deploys trivially, stays legible for a small single-league app, and rides only on capabilities we have confirmed. A future reader expecting a modern framework or a JS SPA should know the shared-hosting constraint and the "keep it straightforward" goal drove this choice.
