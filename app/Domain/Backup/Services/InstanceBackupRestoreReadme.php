<?php

namespace App\Domain\Backup\Services;

/**
 * Restore instructions written to README.md at the root of an instance backup zip.
 */
final class InstanceBackupRestoreReadme
{
    public function contents(?string $filename = null): string
    {
        $connection = (string) config('database.default');
        $database = (string) config("database.connections.{$connection}.database");
        $username = (string) config("database.connections.{$connection}.username");
        $backupName = trim((string) config('backup.backup.name', 'nrth'), '/');
        if ($backupName === '') {
            $backupName = 'nrth';
        }

        $version = $this->version();
        $zipName = $filename !== null && $filename !== '' ? $filename : 'THIS-BACKUP.zip';
        $encrypted = filled(config('backup.backup.password'));

        $encryptionNote = $encrypted
            ? "This zip is encrypted. `unzip` needs the `BACKUP_ARCHIVE_PASSWORD` from the server that created it. Store that password with the zip. Without it, the archive cannot be opened.\n"
            : "This zip is not encrypted.\n";

        $versionStep = $version !== null
            ? "This backup was taken from nrth **{$version}**. After install, pin the code to that release before you restore, so the database matches the app:\n\n```bash\ncd /opt/nrth\n./scripts/update --ref v{$version}\n```\n"
            : "After install, check out the nrth release this backup was taken from (`./scripts/update --ref vX.Y.Z`) before you restore.\n";

        return <<<MD
        # nrth instance backup

        This zip is a snapshot of one nrth install: the Postgres database, the application tree, and every uploaded file. It is not a full operating-system image. A new machine still gets the app from git. This file is the restore guide for that zip.

        {$encryptionNote}
        ## What is in this zip

        - `db-dumps/*.sql` — Postgres dump (`pg_dump`, no owner, no ACLs). The database client must be Postgres **16**.
        - `storage/` — uploaded files the way the app stores them, including numbered media folders.
        - `instance_backup/` — those same uploaded files again. Restore copies this folder onto `storage`. The `README.txt` in that folder is not copied.
        - `takeout_backup/` — the same files with readable names, grouped by business (expense receipts, invoices, payment receipts, estimates, logos, bank statements). Open this when you want to look at a file. Restore does not copy it onto the app.
        - The rest of the project tree. `vendor`, `node_modules`, and `storage/framework` are left out, so do not boot a server from the unzipped tree alone.

        This backup's database name was `{$database}` and its database user was `{$username}`. The dump has no owner, so a new install can import it with that install's own database user.

        If a `.env` file is in this zip, it contains the old `APP_KEY` and database password. Treat it as a secret. Copy `APP_KEY` onto the new server. Keep the new server's `DB_PASSWORD` if Postgres was already initialized with it.

        ## 1. Install nrth on the new machine

        Ubuntu 22.04 or 24.04. This creates `/opt/nrth`, a `.env`, and an empty database. The admin account created here is replaced when you restore.

        Production (HTTPS via Caddy):

        ```bash
        curl -fsSL https://raw.githubusercontent.com/mortolian/nrth/master/scripts/install.sh \\
          | sudo bash -s -- --accept-data-risk --production --install-dir /opt/nrth
        ```

        Private LAN (HTTP on port 8000):

        ```bash
        curl -fsSL https://raw.githubusercontent.com/mortolian/nrth/master/scripts/install.sh \\
          | sudo bash -s -- --accept-data-risk --lan --install-dir /opt/nrth
        ```

        {$versionStep}
        ## 2. Keep the original APP_KEY

        Encrypted settings in the database (outbound email password, offsite backup credentials) only decrypt with the `APP_KEY` from the server that created this backup. On the new machine, put that key in `/opt/nrth/.env` as `APP_KEY=base64:...`. Do not run `php artisan key:generate`.

        Then reload it:

        ```bash
        cd /opt/nrth
        ./scripts/compose.sh up -d --force-recreate app horizon scheduler
        ```

        ## 3. Copy the zip into the app container

        Keep the `.zip` file. Restore reads the zip, not an unzipped folder on your laptop. From the install directory, with `{$zipName}` in the current directory:

        ```bash
        cd /opt/nrth
        ./scripts/compose.sh exec -T app mkdir -p /var/www/html/storage/app/private/{$backupName}
        ./scripts/compose.sh cp {$zipName} app:/var/www/html/storage/app/private/{$backupName}/{$zipName}
        ```

        ## 4. Restore the database and uploaded files

        This stops the app, drops the live database, imports the dump, and copies `instance_backup` onto `storage`. `takeout_backup` is left unused. Postgres stays up. Expect downtime.

        Save the script as `restore-backup.sh` in `/opt/nrth`, review it, then run `bash restore-backup.sh`.

        ```bash
        #!/usr/bin/env bash
        # nrth instance restore — review before running
        # Backup: {$zipName}
        # Replaces the live Postgres database. Expect downtime.
        set -euo pipefail

        cd "\${INSTALL_DIR:-\$(pwd)}"

        env_value() {
          grep -E "^\$1=" .env | head -1 | cut -d= -f2- | tr -d '"' | tr -d "'"
        }

        RUNNER="./scripts/compose.sh"
        DB_NAME="\$(env_value DB_DATABASE)"
        DB_USER="\$(env_value DB_USERNAME)"
        ZIP="/var/www/html/storage/app/private/{$backupName}/{$zipName}"
        WORKDIR="/tmp/nrth-restore-\$\$"
        HOST_DUMP="\$(pwd)/.nrth-restore-\$\$-dump.sql"

        echo "Extracting backup zip (app still running)…"
        \$RUNNER exec -T app mkdir -p "\$WORKDIR"
        \$RUNNER exec -T app unzip -o "\$ZIP" -d "\$WORKDIR"

        DUMP="\$(\$RUNNER exec -T app sh -c "ls \$WORKDIR/db-dumps/*.sql 2>/dev/null | head -1")"
        if [ -z "\$DUMP" ]; then
          echo "No SQL dump found in \$WORKDIR/db-dumps — aborting." >&2
          exit 1
        fi
        echo "Using dump: \$DUMP"
        \$RUNNER exec -T app cat "\$DUMP" > "\$HOST_DUMP"

        echo "Stopping app services (postgres stays up)…"
        \$RUNNER stop app scheduler
        \$RUNNER stop horizon 2>/dev/null || true
        \$RUNNER stop worker 2>/dev/null || true

        echo "Recreating database \$DB_NAME…"
        \$RUNNER exec -T postgres psql -U "\$DB_USER" -d postgres -v ON_ERROR_STOP=1 -c "SELECT pg_terminate_backend(pid) FROM pg_stat_activity WHERE datname = '\$DB_NAME' AND pid <> pg_backend_pid();"
        \$RUNNER exec -T postgres psql -U "\$DB_USER" -d postgres -v ON_ERROR_STOP=1 -c "DROP DATABASE IF EXISTS \\"\$DB_NAME\\";"
        \$RUNNER exec -T postgres psql -U "\$DB_USER" -d postgres -v ON_ERROR_STOP=1 -c "CREATE DATABASE \\"\$DB_NAME\\" OWNER \\"\$DB_USER\\";"

        echo "Importing dump…"
        \$RUNNER exec -T -i postgres psql -U "\$DB_USER" -d "\$DB_NAME" -v ON_ERROR_STOP=1 < "\$HOST_DUMP"
        rm -f "\$HOST_DUMP"

        echo "Restoring stored files from instance_backup (not takeout_backup)…"
        \$RUNNER exec -T app sh -c 'mkdir -p /var/www/html/storage
        WORKDIR="'"\$WORKDIR"'"
        if [ -d "\$WORKDIR/instance_backup/app" ]; then
          find "\$WORKDIR/instance_backup" -mindepth 1 -maxdepth 1 ! -name README.txt -exec cp -a {} /var/www/html/storage/ \\;
        elif [ -d "\$WORKDIR/storage/app" ]; then
          cp -a "\$WORKDIR/storage/." /var/www/html/storage/
        elif [ -d "\$WORKDIR/var/www/html/storage/app" ]; then
          cp -a "\$WORKDIR/var/www/html/storage/." /var/www/html/storage/
        else
          found=\$(find "\$WORKDIR" -type d -path "*/storage/app" | head -1)
          if [ -z "\$found" ]; then
            echo "The zip has no storage files. Aborting so receipts and uploads are not left behind." >&2
            exit 1
          fi
          cp -a "\$(dirname "\$found")/." /var/www/html/storage/
        fi'

        echo "Starting services…"
        \$RUNNER start app scheduler
        \$RUNNER start horizon 2>/dev/null || true
        \$RUNNER start worker 2>/dev/null || true

        echo "Cleaning up extract dir…"
        \$RUNNER exec -T app rm -rf "\$WORKDIR"

        echo "Applying migrations if this code is newer than the backup…"
        \$RUNNER exec -T app php artisan migrate --force

        echo "Done. Open the app and sign in with an account from the backup."
        ```

        On a laptop install, use `./vendor/bin/sail` anywhere the script says `./scripts/compose.sh`.

        If this code is the same release as the backup, the migrate step changes nothing. If you installed a newer release, it applies the migrations that sit between the two.

        ## Same server

        To put this backup back onto the server that created it, use **Settings → Backups & exports → Instance restore guide**. That script uses this machine's database name and user. Copy an offsite-only zip back to `storage/app/private/{$backupName}/` first.

        Sign-in after restore uses the accounts stored in the backup, not an admin created by a fresh installer.
        MD;
    }

    private function version(): ?string
    {
        $path = base_path('version.txt');
        if (! is_file($path)) {
            return null;
        }

        $version = trim((string) file_get_contents($path));
        if (! preg_match('/^\d+\.\d+\.\d+$/', $version)) {
            return null;
        }

        return $version;
    }
}
