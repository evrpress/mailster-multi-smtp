<?php
/*
Plugin Name: Mailster Multi SMTP
Plugin URI: https://mailster.co/?utm_campaign=wporg&utm_source=Multi+SMTP+for+Mailster
Description: Allows to use multiple SMTP connection for the Mailster Newsletter Plugin
Version: 1.2
Author: EverPress
Author URI: https://mailster.co
Text Domain: mailster-multismtp
License: GPLv2 or later
*/


define( 'MAILSTER_MULTISMTP_VERSION', '1.2' );
define( 'MAILSTER_MULTISMTP_REQUIRED_VERSION', '2.2' );
define( 'MAILSTER_MULTISMTP_FILE', __FILE__ );

require_once dirname( __FILE__ ) . '/classes/multismtp.class.php';
new MailsterMultiSMTP();

