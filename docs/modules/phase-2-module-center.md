# Module Center Phase 2

Phase 2 adds the backend Module Center for local modules.

Supported:

- list installed and discovered modules;
- inspect manifest details and module operation logs;
- discover, install, enable, disable, and uninstall-preserve modules;
- upgrade from the local module directory;
- upload a zip package to install or upgrade a module;
- record module version snapshots;
- run module migrations and migration tracking;
- roll back to the latest code backup.

Not supported in Phase 2:

- third-party review workflow;
- signatures;
- marketplace or remote repository;
- automatic update scheduler;
- destructive uninstall.

Backups are stored under:

```text
storage/modules/backups/{module}/{timestamp}-{version}-{suffix}
```

Rollback restores the latest backup for one installed module. Automatic rollback only handles zero or one missing migration between the current module and the backup. If more migrations need to be reversed, rollback stops and requires manual operator review.

Use this test command in the Windows development environment:

```bash
E:\code\user\.tools\php-8.3.32\php.exe E:\code\user\.tools\composer.phar run test:sqlite
```
