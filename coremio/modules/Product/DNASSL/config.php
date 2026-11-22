<?php
return [
    'created_at'         => 1792800000,
    'group'              => 'ssl',
    'status'             => false,
    'meta'               => [
        'name'         => 'DNA SSL',
        'version'      => '1.1.0',
        'author'       => 'Domain Name API',
        'logo'         => 'logo.svg',
        'descriptions' => 'Sell Sectigo, DigiCert, GeoTrust, RapidSSL and other SSL certificates from your Domain Name API reseller account, with catalogue import, daily cost sync and automatic issuance.',
    ],
    'settings'           => [
        'reseller_id'      => '',
        'api_key'          => '',
        'test_mode'        => 0,
        'revoke_on_cancel' => 0,
        'margin'           => 30,
        'auto_sync_cost'   => false,
    ],
    'addon-params'       => [
        'sans_count' => [
            'label'       => '{lang.addon-sans_count}',
            'description' => '{lang.addon-sans_count-desc}',
        ],
    ],
    'requirement-params' => [
        'csr'                => [
            'label'       => '{lang.req-csr}',
            'description' => '{lang.req-csr-desc}',
            'aliases'     => ['csr-code'],
        ],
        'dcv_method'         => [
            'label'   => '{lang.req-dcv_method}',
            'aliases' => ['dcv-method'],
        ],
        'verification_email' => [
            'label'   => '{lang.req-verification_email}',
            'aliases' => ['verification-email'],
        ],
        'additional_domains' => [
            'label'       => '{lang.req-additional_domains}',
            'description' => '{lang.req-additional_domains-desc}',
            'aliases'     => ['additional-domains'],
        ],
    ],
];
