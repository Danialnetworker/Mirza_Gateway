<?php

return static function (PDO $pdo, Schema $schema): void {
    $statements = [
        'invoice' => [
            ['Volume', 'varchar(200)', null, 'ALTER TABLE `invoice` CHANGE `Volume` `Volume` VARCHAR(200)'],
            ['price_product', 'varchar(200)', null, 'ALTER TABLE `invoice` CHANGE `price_product` `price_product` VARCHAR(200)'],
            ['name_product', 'varchar(200)', null, 'ALTER TABLE `invoice` CHANGE `name_product` `name_product` VARCHAR(200)'],
            ['username', 'varchar(200)', null, 'ALTER TABLE `invoice` CHANGE `username` `username` VARCHAR(200)'],
            ['Service_location', 'varchar(200)', null, 'ALTER TABLE `invoice` CHANGE `Service_location` `Service_location` VARCHAR(200)'],
            ['time_sell', 'varchar(200)', null, 'ALTER TABLE `invoice` CHANGE `time_sell` `time_sell` VARCHAR(200)'],
        ],
        'marzban_panel' => [
            ['password_panel', 'text', 'utf8mb4_bin', 'ALTER TABLE `marzban_panel` MODIFY password_panel TEXT COLLATE utf8mb4_bin'],
            ['name_panel', 'varchar(255)', 'utf8mb4_bin', 'ALTER TABLE `marzban_panel` MODIFY name_panel VARCHAR(255) COLLATE utf8mb4_bin'],
        ],
        'product' => [
            ['name_product', 'varchar(1000)', 'utf8mb4_bin', 'ALTER TABLE `product` MODIFY name_product VARCHAR(1000) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin'],
        ],
        'help' => [
            ['name_os', 'varchar(500)', 'utf8mb4_bin', 'ALTER TABLE `help` MODIFY name_os VARCHAR(500) COLLATE utf8mb4_bin'],
        ],
    ];

    $columnInfo = $pdo->prepare("SELECT COLUMN_TYPE, COLLATION_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?");
    foreach ($statements as $table => $queries) {
        if (!$schema->tableExists($table)) {
            continue;
        }
        foreach ($queries as [$column, $type, $collation, $query]) {
            try {
                $columnInfo->execute([$table, $column]);
                $current = $columnInfo->fetch(PDO::FETCH_ASSOC);
                if ($current && strtolower($current['COLUMN_TYPE']) === $type && ($collation === null || $current['COLLATION_NAME'] === $collation)) {
                    continue;
                }
                $pdo->exec($query);
            } catch (Throwable $e) {
                error_log("[db:migration:column_types:$table] " . $e->getMessage());
            }
        }
    }
};
