<?php
if( !defined( 'ABSPATH') && !defined('WP_UNINSTALL_PLUGIN') )
    exit();
include_once(dirname(__FILE__) . '/yfp-spam-protection.php');
$optKey = YFP_Spam_Protection::WP_OPTION_KEY;
delete_option($optKey);
