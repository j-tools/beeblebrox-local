Migrations live here. The first install loads db/schema.sql instead, which already has every
migration folded in; tools/migrate.php then records them all as applied so it never runs one over
the top of a schema that already has it.

Each file must be re-runnable. MySQL has no ADD COLUMN IF NOT EXISTS, so a step asks
information_schema and prepares either the ALTER or SELECT 1 — applying one by hand and leaving
schema_migrations empty is normal, and the runner must survive it.
