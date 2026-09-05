-- Installation identity: add the canonical column alongside the legacy one.
--
-- Non-destructive: `site_url` is never read from, written to, renamed or
-- dropped by this migration. The new column starts NULL and is filled in by
-- migrations/run_canonical_migration.php, which can be re-run safely.
--
-- The index is deliberately NOT unique. Databases that already hold two rows
-- for the same installation (typically one `http://` and one `https://`) would
-- reject a unique index, and deleting one of those rows is a business decision,
-- not a migration step. Run the PHP migration first: it reports any collision.

ALTER TABLE `activations`
  ADD COLUMN `site_url_canonical` VARCHAR(255) NULL DEFAULT NULL
  COMMENT 'Transport-independent installation identity: host[:port][/path]'
  AFTER `site_url`;

ALTER TABLE `activations`
  ADD INDEX `idx_activations_canonical` (`license_id`, `site_url_canonical`);
