<?php

/**
 * Settings rows for the Tronado gateway, on installations that already exist.
 *
 * `db/tables/PaySetting.php` seeds only when the table is created, and
 * `update()` is a plain UPDATE — on a bot installed before this gateway existed
 * it matches zero rows, so an admin pasting a key would see it accepted and
 * saved nowhere. This migration prevents that.
 *
 * `statustronado` lands on `offtronado`: the gateway cannot take money until an
 * admin has pasted the API key, the IPN signing key and a TRX wallet, and a
 * buyer-facing button shown before that is a dead end.
 */

return static function (PDO $pdo, Schema $schema): void {
    if (!$schema->tableExists('PaySetting')) {
        return;
    }

    $defaults = [
        'statustronado' => 'offtronado',
        'apitronado' => '0',
        'ipnkeytronado' => '0',
        'wallettronado' => '0',
        'minbalancetronado' => '50000',
        'maxbalancetronado' => '500000',
        'chashbacktronado' => '0',
        'helptronado' => '2',
    ];

    // INSERT IGNORE rather than delete-then-insert: an admin who configured the
    // gateway before upgrading keeps what they configured.
    $stmt = $pdo->prepare('INSERT IGNORE INTO PaySetting (NamePay, ValuePay) VALUES (:name, :value)');
    foreach ($defaults as $name => $value) {
        $stmt->execute([':name' => $name, ':value' => $value]);
    }
};
