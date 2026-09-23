<?php 
if ( ! defined( 'ABSPATH' ) ) { exit; }

return array(
    'root' => array(
        'name' => 'wpdevteam/betterlinks',
        'pretty_version' => 'dev-latest',
        'version' => 'dev-latest',
        'reference' => 'aac47581c7ed6eb4f4ad2797191f40ce17d61efa',
        'type' => 'library',
        'install_path' => __DIR__ . '/../../',
        'aliases' => array(),
        'dev' => true,
    ),
    'versions' => array(
        'a5hleyrich/wp-background-processing' => array(
            'pretty_version' => '1.3.1',
            'version' => '1.3.1.0',
            'reference' => '6d1e48165e461260075b9f161b3861c7278f71e7',
            'type' => 'library',
            'install_path' => __DIR__ . '/../a5hleyrich/wp-background-processing',
            'aliases' => array(),
            'dev_requirement' => false,
        ),
        'matomo/device-detector' => array(
            'pretty_version' => '6.4.1',
            'version' => '6.4.1.0',
            'reference' => '0d364e0dd6c177da3c24cd4049178026324fd7ac',
            'type' => 'library',
            'install_path' => __DIR__ . '/../matomo/device-detector',
            'aliases' => array(),
            'dev_requirement' => false,
        ),
        'mustangostang/spyc' => array(
            'pretty_version' => '0.6.3',
            'version' => '0.6.3.0',
            'reference' => '4627c838b16550b666d15aeae1e5289dd5b77da0',
            'type' => 'library',
            'install_path' => __DIR__ . '/../mustangostang/spyc',
            'aliases' => array(),
            'dev_requirement' => false,
        ),
        'piwik/device-detector' => array(
            'dev_requirement' => false,
            'replaced' => array(
                0 => '6.4.1',
            ),
        ),
        'priyomukul/wp-notice' => array(
            'pretty_version' => 'v3.x-dev',
            'version' => '3.9999999.9999999.9999999-dev',
            'reference' => '3e1a8baec94938e055199e3c6d1206b8f1d07e3e',
            'type' => 'library',
            'install_path' => __DIR__ . '/../priyomukul/wp-notice',
            'aliases' => array(),
            'dev_requirement' => false,
        ),
        'wpdevteam/betterlinks' => array(
            'pretty_version' => 'dev-latest',
            'version' => 'dev-latest',
            'reference' => 'aac47581c7ed6eb4f4ad2797191f40ce17d61efa',
            'type' => 'library',
            'install_path' => __DIR__ . '/../../',
            'aliases' => array(),
            'dev_requirement' => false,
        ),
    ),
);
