# Module Center Phase 2

Phase 2 adds the backend Module Center for local modules.

Supported:

- list installed and discovered modules;
- inspect manifest details and module operation logs;
- approve or reject newly discovered modules before first install;
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

## Administrator Review

Newly discovered modules enter `pending_review`.

An administrator must approve the module before first install. Rejected modules cannot be installed until approved again. Review decisions are recorded in module logs with actions `approve` and `reject`; reject reasons are stored in `last_error` and the module log `error_message`.

Backups are stored under:

```text
storage/modules/backups/{module}/{timestamp}-{version}-{suffix}
```

Rollback restores the latest backup for one installed module. Automatic rollback only handles zero or one missing migration between the current module and the backup. If more migrations need to be reversed, rollback stops and requires manual operator review.

Use this test command in the Windows development environment:

```bash
E:\code\user\.tools\php-8.3.32\php.exe E:\code\user\.tools\composer.phar run test:sqlite
```
