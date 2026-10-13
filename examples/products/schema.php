<?php
declare(strict_types=1);

// SQLite demo schema; production applications own their migrations and policies.
return static function (\FloCMS\Core\Database $db): void {
    $db->query('CREATE TABLE IF NOT EXISTS products (id INTEGER PRIMARY KEY, name TEXT NOT NULL, price_cents INTEGER NOT NULL, featured INTEGER NOT NULL, owner_id INTEGER NOT NULL, version INTEGER NOT NULL DEFAULT 1)');
};
