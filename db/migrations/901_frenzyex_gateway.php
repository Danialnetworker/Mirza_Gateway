<?php

/**
 * Settings rows for the FrenzyEx slot, on installations that already exist.
 *
 * `db/tables/PaySetting.php` seeds only when the table is created, and
 * `update()` is a plain UPDATE — on a bot installed before this gateway
 * existed it matches zero rows, so an admin pasting their key would see it
 * accepted and saved nowhere.
 *
 * `statusfrenzyex` deliberately uses Mirza's legacy-compatible `0`/`1`
 * convention. Defaulting to `0` keeps an unconfigured gateway hidden.
 *
 * Earlier FrenzyEx packages briefly wrote `offfrenzyex` / `onfrenzyex`.
 * Normalize those values so upgraded installations remain compatible with
 * Financial-menu code that indexes status labels by `0` and `1`.
 */

return static function (PDO $pdo, Schema $schema): void {
    // [config-093] 0.9.3 moved PaySetting into `config` (scope 'pay'); 017_config_import copies existing rows first.
    if (!$schema->tableExists('config')) {
        return;
    }
    $defaults = ['apifrenzyex' => '0', 'secretfrenzyex' => '0', 'statusfrenzyex' => '0', 'minbalancefrenzyex' => '20000',
        'maxbalancefrenzyex' => '1000000', 'chashbackfrenzyex' => '0', 'helpfrenzyex' => '2'];
    // INSERT IGNORE: an admin who configured the gateway before upgrading keeps what they configured.
    $stmt = $pdo->prepare("INSERT IGNORE INTO config (scope, name, value) VALUES ('pay', :name, :value)");
    foreach ($defaults as $name => $value) {
        $stmt->execute([':name' => $name, ':value' => $value]);
    }
    // Earlier FrenzyEx packages briefly wrote offfrenzyex/onfrenzyex: keep 0/1 only.
    $pdo->exec("UPDATE config SET value = CASE WHEN LOWER(TRIM(value)) IN ('1', 'onfrenzyex') THEN '1' ELSE '0' END
        WHERE scope = 'pay' AND name = 'statusfrenzyex'");
};
