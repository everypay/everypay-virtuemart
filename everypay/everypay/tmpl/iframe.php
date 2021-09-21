<?php

defined('_JEXEC') or die();

$payformUrl = 'https://js.everypay.gr/v3';

if ($viewData['isSandbox'] == '1') {
    $payformUrl = 'https://sandbox-js.everypay.gr/v3';
}

JHtml::_(
    'script',
    $payformUrl,
    array('version' => 'auto', 'absolute' => true),
    array('defer' => 'defer')
);

JHtml::_(
    'stylesheet',
    JUri::base() . '/plugins/vmpayment/everypay/assets/everypay_modal.css',
    array('version' => 'auto', 'relative' => true),
    array('defer' => 'defer')
);


JHtml::_(
    'script',
    JUri::base() . '/plugins/vmpayment/everypay/assets/everypay.js',
    array('version' => 'auto', 'relative' => true),
    array('defer' => 'defer')
);
