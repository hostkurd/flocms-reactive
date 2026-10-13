<?php
declare(strict_types=1);

// SQLite demo schema only. Production applications own their migrations/data.
return static function (\FloCMS\Core\Database $db): void {
    $db->query('CREATE TABLE IF NOT EXISTS countries (id INTEGER PRIMARY KEY)');
    $db->query('CREATE TABLE IF NOT EXISTS cities (id INTEGER PRIMARY KEY, country_id INTEGER NOT NULL REFERENCES countries(id), UNIQUE(id, country_id))');
    $db->query('CREATE TABLE IF NOT EXISTS delivery_addresses (user_id INTEGER PRIMARY KEY, name TEXT NOT NULL, country_id INTEGER NOT NULL REFERENCES countries(id), city_id INTEGER NOT NULL, FOREIGN KEY(city_id, country_id) REFERENCES cities(id, country_id))');
    foreach ([1, 2, 3] as $id) { $db->query('INSERT OR IGNORE INTO countries (id) VALUES (?)', [$id]); }
    foreach ([[1,1], [2,1], [3,2], [4,2]] as $city) { $db->query('INSERT OR IGNORE INTO cities (id, country_id) VALUES (?, ?)', $city); }
};
