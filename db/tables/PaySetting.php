<?php

$min = 20000;
$max = 1000000;
$textbotlang = $schema->context('textbotlang');

$values = [
    'Cartstatus' => 'oncard',
    'CartDirect' => '@cart',
    'cardnumber' => '603700000000',
    'namecard' => $textbotlang['db_defaults']['namecardNotSet'],
    'Cartstatuspv' => 'offcardpv',
    'apinowpayment' => '0',
    'nowpaymentstatus' => 'offnowpayment',
    'digistatus' => 'offdigi',
    'statusSwapWallet' => 'offnSolutions',
    'statusaqayepardakht' => 'offaqayepardakht',
    'merchant_id_aqayepardakht' => '0',
    'minbalance' => '20000',
    'maxbalance' => '1000000',
    'marchent_tronseller' => '0',
    'walletaddress' => '0',
    'statustarnado' => 'offternado',
    'apiternado' => '0',
    'feestatusternado' => 'offfeeternado',
    'feeternado' => '0',
    'chashbackcart' => '0',
    'chashbackstar' => '0',
    'chashbackperfect' => '0',
    'chashbackaqaypardokht' => '0',
    'chashbackiranpay1' => '0',
    'chashbackiranpay2' => '0',
    'chashbackiranpay3' => '0',
    'chashbackplisio' => '0',
    'chashbackzarinpal' => '0',
    'cashbacknowpayment' => '0',
    'checkpaycartfirst' => 'offpayverify',
    'zarinpalstatus' => 'offzarinpal',
    'merchant_zarinpal' => '0',
    'statusiranpay3' => 'oniranpay3',
    'apiiranpay' => '0',
    'autoconfirmcart' => 'offauto',
    'statusstar' => '0',
    'statusnowpayment' => '0',
    'Exception_auto_cart' => '{}',
    'marchent_floypay' => '0',
    'minbalancecart' => $min,
    'maxbalancecart' => $max,
    'minbalancestar' => $min,
    'maxbalancestar' => $max,
    'minbalanceplisio' => $min,
    'maxbalanceplisio' => $max,
    'minbalancedigitaltron' => $min,
    'maxbalancedigitaltron' => $max,
    'minbalanceiranpay1' => $min,
    'maxbalanceiranpay1' => $max,
    'minbalanceiranpay2' => $min,
    'maxbalanceiranpay2' => $max,
    'minbalanceaqayepardakht' => $min,
    'maxbalanceaqayepardakht' => $max,
    'minbalancepaynotverify' => $min,
    'maxbalancepaynotverify' => $max,
    'minbalanceperfect' => $min,
    'maxbalanceperfect' => $max,
    'minbalancezarinpal' => $min,
    'maxbalancezarinpal' => $max,
    'minbalanceiranpay' => $min,
    'maxbalanceiranpay' => $max,
    'minbalancenowpayment' => $min,
    'maxbalancenowpayment' => $max,
    'helpcart' => '2',
    'helpaqayepardakht' => '2',
    'helpstar' => '2',
    'helpplisio' => '2',
    'helpiranpay1' => '2',
    'helpiranpay2' => '2',
    'helpiranpay3' => '2',
    'helpiranpay4' => '2',
    'apiiranpay4' => '0',
    'endpointiranpay4' => '0',
    'statusiranpay4' => 'offiranpay4',
    'minbalanceiranpay4' => '20000',
    'maxbalanceiranpay4' => '1000000',
    'dailylimitiranpay4' => '0',
    'chashbackiranpay4' => '0',
    // Tronado (card-to-card, settled in TRX). Off until the admin has pasted
    // the API key, the IPN signing key and a TRX wallet — keyboard.php checks
    // all three before showing the buyer a button.
    'statustronado' => 'offtronado',
    'apitronado' => '0',
    'ipnkeytronado' => '0',
    // The key the IPN signing key last replaced, and this installation's own
    // seal key. Seeded empty only so a fresh install has the rows; both are
    // written by tronadoStoreSecret()/tronadoMetaKey(), which upsert, so no
    // migration is needed for shops that upgrade without running bootstrap.
    // (This seed list is INSERT IGNORE on every apply, so it never overwrites
    // a key that has already been generated — see db/Schema.php::insert.)
    'ipnkeyprevtronado' => '0',
    'metakeytronado' => '0',
    'wallettronado' => '0',
    'minbalancetronado' => '50000',
    'maxbalancetronado' => '500000',
    'chashbacktronado' => '0',
    'helptronado' => '2',
    'helpperfectmony' => '2',
    'helpzarinpal' => '2',
    'helpnowpayment' => '2',
    'helpofflinearze' => '2',
];

$seed = [];
foreach ($values as $name => $value) {
    $seed[] = ['NamePay' => $name, 'ValuePay' => $value];
}

return [
    'create' => <<<SQL
        NamePay varchar(500) PRIMARY KEY NOT NULL,
        ValuePay TEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL
        SQL,
    'seed' => $seed,
];
