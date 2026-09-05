<?php
/**
 * Installation identity migration.
 *
 * Adds `activations.site_url_canonical` if missing, reports canonical
 * collisions, and backfills the new column. Nothing is deleted and the legacy
 * `site_url` column is never modified, so the migration is safe to re-run and
 * safe to leave in place if the application code is rolled back.
 *
 * Usage:
 *   php migrations/run_canonical_migration.php            (dry run: report only)
 *   php migrations/run_canonical_migration.php --apply    (add column + backfill)
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/security.php';

$apply = in_array('--apply', $argv, true);

$db = get_db_connection();

function out(string $line = ''): void
{
    echo $line . PHP_EOL;
}

// --- 1. Schema ------------------------------------------------------------
$has_column = (bool) $db->query("SHOW COLUMNS FROM `activations` LIKE 'site_url_canonical'")->fetch();
$has_index  = (bool) $db->query("SHOW INDEX FROM `activations` WHERE Key_name = 'idx_activations_canonical'")->fetch();

out('== schema ==');
out('site_url_canonical column : ' . ($has_column ? 'present' : 'MISSING'));
out('idx_activations_canonical : ' . ($has_index ? 'present' : 'MISSING'));

if (!$has_column) {
    if (!$apply) {
        out('  -> would add the column (re-run with --apply)');
    } else {
        $db->exec(
            "ALTER TABLE `activations`
             ADD COLUMN `site_url_canonical` VARCHAR(255) NULL DEFAULT NULL
             COMMENT 'Transport-independent installation identity: host[:port][/path]'
             AFTER `site_url`"
        );
        $has_column = true;
        out('  -> column added');
    }
}

if ($has_column && !$has_index) {
    if (!$apply) {
        out('  -> would add the index (re-run with --apply)');
    } else {
        $db->exec("ALTER TABLE `activations` ADD INDEX `idx_activations_canonical` (`license_id`, `site_url_canonical`)");
        out('  -> index added');
    }
}

if (!$has_column) {
    out('');
    out('Column not present yet; stopping before the backfill.');
    exit(0);
}

// --- 2. Preflight: canonical collisions -----------------------------------
// Two rows of the same license that canonicalize to one identity. Reported,
// never merged or deleted: which row survives is a business decision.
out('');
out('== preflight: canonical collisions ==');

$rows = $db->query("SELECT id, license_id, site_url, status FROM `activations` ORDER BY license_id, id")->fetchAll();

$groups = [];
$unmappable = [];
foreach ($rows as $row) {
    $canonical = canonicalize_site_url((string) $row['site_url']);
    if ($canonical === false) {
        $unmappable[] = $row;
        continue;
    }
    $groups[$row['license_id'] . "\0" . $canonical][] = $row;
}

$collisions = array_filter($groups, static fn(array $g): bool => count($g) > 1);

if (!$collisions) {
    out('none');
} else {
    foreach ($collisions as $key => $group) {
        [$license_id, $canonical] = explode("\0", $key, 2);
        out(sprintf(
            'license_id=%s  canonical=%s  count=%d',
            $license_id,
            $canonical,
            count($group)
        ));
        foreach ($group as $row) {
            out(sprintf('    activation id=%-6s status=%-12s legacy=%s', $row['id'], $row['status'], $row['site_url']));
        }
    }
    out('');
    out('No row was changed. Reconcile these with the license owner before adding');
    out('a UNIQUE index on (license_id, site_url_canonical).');
}

if ($unmappable) {
    out('');
    out('== rows whose legacy site_url cannot be canonicalized ==');
    foreach ($unmappable as $row) {
        out(sprintf('    activation id=%-6s license_id=%-6s legacy=%s', $row['id'], $row['license_id'], $row['site_url']));
    }
    out('These keep site_url_canonical = NULL and continue to match on the legacy column.');
}

// --- 3. Backfill ----------------------------------------------------------
out('');
out('== backfill ==');

$pending = $db->query("SELECT id, site_url FROM `activations` WHERE site_url_canonical IS NULL")->fetchAll();
out('rows needing a canonical value: ' . count($pending));

if (!$apply) {
    out('dry run: nothing written (re-run with --apply)');
    out('');
    out('changes = 0');
    exit(0);
}

$update = $db->prepare("UPDATE `activations` SET site_url_canonical = ? WHERE id = ? AND site_url_canonical IS NULL");
$changed = 0;
$skipped = 0;

foreach ($pending as $row) {
    $canonical = canonicalize_site_url((string) $row['site_url']);
    if ($canonical === false) {
        $skipped++;
        continue;
    }
    $update->execute([$canonical, $row['id']]);
    $changed += $update->rowCount();
}

out('changes = ' . $changed);
out('skipped (not canonicalizable) = ' . $skipped);

// --- 4. Would a unique index be safe? -------------------------------------
$dupes = $db->query(
    "SELECT COUNT(*) FROM (
        SELECT license_id, site_url_canonical
        FROM `activations`
        WHERE site_url_canonical IS NOT NULL
        GROUP BY license_id, site_url_canonical
        HAVING COUNT(*) > 1
     ) d"
)->fetchColumn();

out('');
out('== unique index readiness ==');
out((int) $dupes === 0
    ? 'safe: no duplicate (license_id, site_url_canonical) pairs remain'
    : 'NOT safe: ' . $dupes . ' duplicate pair(s) remain — do not add a UNIQUE index yet');
