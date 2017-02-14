<?php
/*
Plugin Name: Mailster Multi SMTP
Plugin URI: http://rxa.li/mailster?utm_campaign=wporg&utm_source=Multi+SMTP+for+Mailster
Description: Allows to use multiple SMTP connection for the Mailster Newsletter Plugin
Version: 1.0
Author: revaxarts.com
Author URI: https://mailster.co
Text Domain: mailster-multismtp
License: GPLv2 or later
*/


define( 'MAILSTER_MULTISMTP_VERSION', '1.0' );
define( 'MAILSTER_MULTISMTP_REQUIRED_VERSION', '2.2' );
define( 'MAILSTER_MULTISMTP_DOMAIN', 'mailster-multismtp' );


class MailsterMultiSMTP {

	private $plugin_path;
	private $plugin_url;

	public function __construct() {

		$this->plugin_path = plugin_dir_path( __FILE__ );
		$this->plugin_url = plugin_dir_url( __FILE__ );

		register_activation_hook( __FILE__, array( &$this, 'activate' ) );
		register_deactivation_hook( __FILE__, array( &$this, 'deactivate' ) );

		load_plugin_textdomain( 'mailster-multismtp' );

		add_action( 'init', array( &$this, 'init' ), 1 );
	}


	/**
	 *
	 *
	 * @param unknown $network_wide
	 */
	public function activate( $network_wide ) {

		if ( function_exists( 'mailster' ) ) {

			mailster_notice( sprintf( __( 'Change the delivery method on the %s!', 'mailster-multismtp' ), '<a href="edit.php?post_type=newsletter&page=mailster_settings&mailster_remove_notice=delivery_method#delivery">Settings Page</a>' ), '', false, 'delivery_method' );

			$defaults = array(
				'multismtp_current' => 0,
				'multismtp_campaignbased' => false,
				'multismtp' =>
				array( array(
					'active' => true,
					'send_limit' => mailster_option( 'send_limit', 10000 ),
					'send_period' => mailster_option( 'send_period', 24 ),
					'host' => mailster_option( 'smtp_host' ),
					'port' => mailster_option( 'smtp_port', 25 ),
					'timeout' => mailster_option( 'smtp_timeout', 10 ),
					'secure' => mailster_option( 'smtp_secure' ),
					'auth' => mailster_option( 'smtp_auth' ),
					'user' => mailster_option( 'smtp_user' ),
					'pwd' => mailster_option( 'smtp_pwd' ),
					),
				),
			);

			$mailster_options = mailster_options();

			foreach ( $defaults as $key => $value ) {
				if ( ! isset( $mailster_options[ $key ] ) ) {
					mailster_update_option( $key, $value );
				}
			}
		}

	}


	/**
	 *
	 *
	 * @param unknown $network_wide
	 */
	public function deactivate( $network_wide ) {

		if ( function_exists( 'mailster' ) ) {

			if ( mailster_option( 'deliverymethod' ) == 'multismtp' ) {
				mailster_update_option( 'deliverymethod', 'simple' );
				mailster_notice( sprintf( __( 'Change the delivery method on the %s!', 'mailster-multismtp' ), '<a href="edit.php?post_type=newsletter&page=mailster_settings&mailster_remove_notice=delivery_method#delivery">Settings Page</a>' ), '', false, 'delivery_method' );
			}
		}

	}


	/**
	 * init function.
	 *
	 * init the plugin
	 *
	 * @access public
	 * @return void
	 */
	public function init() {

		if ( ! function_exists( 'mailster' ) ) {

			add_action( 'admin_notices', array( $this, 'notice' ) );

		} else {

			add_filter( 'mailster_delivery_methods', array( $this, 'delivery_method' ) );
			add_action( 'mailster_deliverymethod_tab_multismtp', array( $this, 'deliverytab' ) );

			add_filter( 'mailster_verify_options', array( $this, 'verify_options' ) );

			if ( mailster_option( 'deliverymethod' ) == 'multismtp' ) {
				add_action( 'add_meta_boxes', array( $this, 'add_meta_boxes' ) );
				add_action( 'save_post', array( $this, 'save_post' ), 10, 2 );
				add_action( 'mailster_initsend', array( $this, 'initsend' ) );
				add_action( 'mailster_presend', array( $this, 'presend' ) );
				add_action( 'mailster_dosend', array( $this, 'dosend' ) );
				add_filter( 'pre_set_transient__mailster_send_period', array( $this, 'save_sent_within_period' ) );
			}
		}

	}


	/**
	 * initsend function.
	 *
	 * uses mailster_initsend hook to set initial settings
	 *
	 * @access public
	 * @return void
	 * @param mixed $mailobject
	 */
	public function initsend( $mailobject ) {

		global $mailster_multismtp_sent_within_period, $mailster_multismtp_sentlimitreached;

		$server = $this->getnextserver();

		if ( $server ) {

			$mailobject->mailer->Mailer = 'smtp';
			$mailobject->mailer->SMTPSecure = $server['secure'];
			$mailobject->mailer->Host = $server['host'];
			$mailobject->mailer->Port = $server['port'];
			$mailobject->mailer->SMTPAuth = ! ! $server['auth'];

			if ( $mailobject->mailer->SMTPAuth ) {
				$mailobject->mailer->AuthType = $server['auth'];
				$mailobject->mailer->Username = $server['user'];
				$mailobject->mailer->Password = $server['pwd'];

			}
		} else {

			$mailster_multismtp_sentlimitreached = true;

		}

	}




	/**
	 * getnextserver function.
	 *
	 * get the next available server
	 *
	 * @access public
	 * @param unknown $use   (optional)
	 * @param unknown $round (optional)
	 * @return void
	 */
	public function getnextserver( $use = null, $round = 0 ) {

		global $mailster_multismtp_current, $mailster_multismtp_sent_within_period, $mailster_multismtp_sentlimitreached;

		$mailster_multismtp_current = is_null( $use ) ? mailster_option( 'multismtp_current', 0 ) : $use;
		// get all servers
		$servers = $this->getactiveservers();

		// seems no server has limits left
		if ( $round > count( $servers ) ) { return false; }

		// use first if current not available
		if ( ! isset( $servers[ $mailster_multismtp_current ] ) ) {
			return $this->getnextserver( 0, $round + 1 ); }

		$server = $servers[ $mailster_multismtp_current ];

		// define some transients for the limits
		if ( ! get_transient( '_mailster_send_period_timeout_' . $mailster_multismtp_current ) ) {
			set_transient( '_mailster_send_period_timeout_' . $mailster_multismtp_current, true, $server['send_period'] * 3600 );
		} else {

			$mailster_multismtp_sent_within_period = get_transient( '_mailster_send_period_' . $mailster_multismtp_current );

		}

		if ( ! $mailster_multismtp_sent_within_period ) { $mailster_multismtp_sent_within_period = 0; }

		$mailster_multismtp_sentlimitreached = $mailster_multismtp_sent_within_period >= $server['send_limit'];

		// send limit has been reached
		if ( $mailster_multismtp_sentlimitreached ) {
			// next server
			return $this->getnextserver( $mailster_multismtp_current + 1, $round + 1 );
		}
		// user next next time
		mailster_update_option( 'multismtp_current', $mailster_multismtp_current + 1 );

		return $server;

	}


	/**
	 *
	 *
	 * @param unknown $value
	 * @return unknown
	 */
	public function save_sent_within_period( $value ) {

		global $mailster_multismtp_current, $mailster_multismtp_sent_within_period, $mailster_multismtp_sentlimitreached;

		if ( $mailster_multismtp_sent_within_period ) { set_transient( '_mailster_send_period_' . $mailster_multismtp_current, $mailster_multismtp_sent_within_period ); }

		return $value;
	}


	/**
	 * getactiveservers function.
	 *
	 * uses the mailster_presend hook to apply settings before each mail
	 *
	 * @access public
	 * @param mixed $mailobject
	 * @return void
	 */
	public function getactiveservers() {

		$servers = mailster_option( 'multismtp', array() );

		$return = array();

		$i = 0;
		foreach ( $servers as $server ) {
			if ( isset( $server['active'] ) && $server['active'] ) {
				$return[ $i++ ] = $server; }
		}

		return $return;
	}




	/**
	 * presend function.
	 *
	 * uses the mailster_presend hook to apply settings before each mail
	 *
	 * @access public
	 * @return void
	 * @param mixed $mailobject
	 */
	public function presend( $mailobject ) {

		$mailobject->pre_send();

	}


	/**
	 * dosend function.
	 *
	 * uses the ymail_dosend hook and triggers the send
	 *
	 * @access public
	 * @return void
	 * @param mixed $mailobject
	 */
	public function dosend( $mailobject ) {

		global $mailster_multismtp_current, $mailster_multismtp_sent_within_period, $mailster_multismtp_sentlimitreached;

		if ( ! $mailster_multismtp_sentlimitreached ) {
			$mailobject->do_send();
		} else {
			add_filter( 'pre_set_transient__mailster_send_period', create_function( '$value', 'return ' . mailster_option( 'send_limit' ) . ';' ) );

			// get the earliest possible time
			$servers = $this->getactiveservers();
			$count = count( $servers );
			$time = $count ? 10000000000 : time();
			for ( $i = 0; $i < $count; $i++ ) {
				$time = min( $time, get_option( '_transient_timeout__mailster_send_period_timeout_' . $i, $time ) );
			}
			update_option( '_transient_timeout__mailster_send_period_timeout', $time );

			$msg = __( 'Sent limit of all servers has been reached!', 'mailster-multismtp' );
			$mailobject->set_error( $msg );
		}
		if ( $mailobject->sent ) {
			$mailster_multismtp_sent_within_period++;
		} else {
			$servers = $this->getactiveservers();
			$mailobject->set_error( sprintf( __( 'Server #%1$d (%2$s) threw that error', 'mailster-multismtp' ), intval( $mailster_multismtp_current ) + 1, $servers[ $mailster_multismtp_current ]['host'] ) );
		}

	}


	/**
	 * save_post function.
	 *
	 * @access public
	 * @return void
	 * @param mixed $post_id
	 * @param mixed $post
	 */
	public function save_post( $post_id, $post ) {

		if ( isset( $_POST['mailster_multismtp'] ) && $post->post_type == 'newsletter' ) {

			$save = get_post_meta( $post_id, 'mailster-multismtp', true );
			$save = wp_parse_args( $_POST['mailster_multismtp'], $save );
			update_post_meta( $post_id, 'mailster-multismtp', $save );

		}

	}


	/**
	 * add_meta_boxes function.
	 *
	 * @access public
	 * @return void
	 */
	public function add_meta_boxes() {

		global $post;

		if ( mailster_option( 'multismtp_campaignbased' ) ) {
			add_meta_box( 'mailster_multismtp', 'Multi SMTP', array( $this, 'metabox' ), 'newsletter', 'side', 'low' );
		}
	}


	/**
	 * metabox function.
	 *
	 * @access public
	 * @return void
	 */
	public function metabox() {

		global $post;

		$readonly = ( in_array( $post->post_status, array( 'finished', 'active' ) ) || $post->post_status == 'autoresponder' && ! empty( $_GET['showstats'] ) ) ? 'readonly disabled' : '';

		$data = wp_parse_args( get_post_meta( $post->ID, 'mailster-multismtp', true ), array(
				'use_global' => true,
				'selected' => null,
		) );

		$server = $this->getactiveservers();

?>
		<style>#mailster_multismtp {display: inherit;}</style>
		<p><label><input type="radio" name="mailster_multismtp[use_global]" value="1" <?php echo $readonly ?><?php checked( ! empty( $data['use_global'] ) ) ?> onchange="jQuery('.mailster-multismtp-server').prop('disabled', jQuery(this).is(':checked')).prop('readonly', jQuery(this).is(':checked'))"> <?php _e( 'use global settings', 'mailster-multismtp' ); ?></label></p><hr>
		<p><label><input type="radio" name="mailster_multismtp[use_global]" value="0" <?php echo $readonly ?><?php checked( empty( $data['use_global'] ) ) ?> onchange="jQuery('.mailster-multismtp-server').prop('disabled', !jQuery(this).is(':checked')).prop('readonly', !jQuery(this).is(':checked'))"> <?php _e( 'use these servers for this campaign', 'mailster-multismtp' ); ?></label></p>
		<h4></h4>
		<ul>
	<?php

	if ( ! empty( $data['use_global'] ) ) { $readonly = 'readonly disabled'; }

	foreach ( $server as $i => $option ) {
		if ( ! isset( $option['active'] ) ) {
			continue;
		}
		?>
			<li><label><input type="checkbox" class="mailster-multismtp-server" name="mailster_multismtp[selected][]" value="<?php echo $i ?>" <?php echo $readonly ?><?php checked( is_null( $data['selected'] ) || in_array( $i, $data['selected'] ) ) ?>> <?php echo '#' . ( $i + 1 ) . ' <strong>' . esc_attr( $option['host'] ) . '</strong>'; ?></label></li>
		<?php
	}
	?>
		</ul>
		<?php
	}



	/**
	 * delivery_method function.
	 *
	 * add the delivery method to the options
	 *
	 * @access public
	 * @param mixed $delivery_methods
	 * @return void
	 */
	public function delivery_method( $delivery_methods ) {
		$delivery_methods['multismtp'] = 'Multi SMTP';
		return $delivery_methods;
	}


	/**
	 * deliverytab function.
	 *
	 * the content of the tab for the options
	 *
	 * @access public
	 * @return void
	 */
	public function deliverytab() {

			wp_enqueue_script( 'mailster-multismtp-settings-script', $this->plugin_url . '/js/script.js', array( 'jquery' ), MAILSTER_MULTISMTP_VERSION );
			wp_enqueue_style( 'mailster-multismtp-settings-style', $this->plugin_url . '/css/style.css', array(), MAILSTER_MULTISMTP_VERSION );

?>
		<?php /*
		?><table class="form-table">
			<tr valign="top">
				<th scope="row"><?php _e('Campaign based', 'mailster-multismtp'); ?></th>
				<td><label><input type="checkbox" name="mailster_options[multismtp_campaignbased]" value="1" <?php checked(mailster_option('multismtp_campaignbased')) ?>> <?php _e('select servers on a campaign basis', 'mailster-multismtp') ?></label> </td>
			</tr>
		</table>
		<?php */ ?>
		<h4><?php _e( 'SMTP Servers', 'mailster-multismtp' ); ?>:</h4>
		<p class="description"><?php _e( 'Add new SMTP servers with the button. You can disable each server with the checkbox on the top. The used server will be changed every time you send a message. If you define limits for each server the general limits get overwritten with the proper values', 'mailster-multismtp' ); ?></p>
	<?php
		$options = mailster_option( 'multismtp' );

		ksort( $options );

	foreach ( $options as $i => $option ) {
?>
		<div class="mailster-multismtp-server">
		<div class="mailster-multismtp-buttons">
			<a class="mailster-multismtp-remove" href="#"><?php _e( 'remove', 'mailster-multismtp' ); ?></a>
		</div>
		<h5><?php echo esc_attr( $option['host'] ); ?></h5>
		<table class="form-table">
			<tr valign="top">
				<th scope="row"><?php _e( 'Active', 'mailster-multismtp' ); ?></th>
				<td><label><input type="hidden" name="mailster_options[multismtp][<?php echo $i ?>][active]" value=""><input type="checkbox" name="mailster_options[multismtp][<?php echo $i ?>][active]" value="1" <?php checked( isset( $option['active'] ) && $option['active'] ) ?>> <?php _e( 'use this server', 'mailster-multismtp' ) ?></label> </td>
			</tr>
			<tr valign="top">
				<th scope="row"><?php _e( 'Limits', 'mailster-multismtp' ); ?><p class="description"><?php _e( 'define the limits for this server', 'mailster-multismtp' ); ?></p></th>
				<td><p><?php echo sprintf( __( 'Send max %1$s within %2$s hours', 'mailster-multismtp' ), '<input type="text" name="mailster_options[multismtp][' . $i . '][send_limit]" value="' . $option['send_limit'] . '" class="small-text">', '<input type="text" name="mailster_options[multismtp][' . $i . '][send_period]" value="' . $option['send_period'] . '" class="small-text">' )?></p>
			<p class="description"><?php echo sprintf( __( 'You can still send %1$s mails within the next %2$s', 'mailster-multismtp' ), '<strong>' . max( 0, $option['send_limit'] -( ( get_transient( '_mailster_send_period_timeout_' . $i ) ? get_transient( '_mailster_send_period_' . $i ) : 0 ) ) ) . '</strong>' , '<strong>' . human_time_diff( ( get_transient( '_mailster_send_period_timeout_' . $i ) ? get_option( '_transient_timeout__mailster_send_period_timeout_' . $i, ( time() + $option['send_period'] * 3600 ) ) : time() + $option['send_period'] * 3600 ) ) . '</strong>' ); ?>
			</p>
				</td>
			</tr>
			<tr valign="top">
				<th scope="row">SMTP Host : Port</th>
				<td><input type="text" name="mailster_options[multismtp][<?php echo $i ?>][host]" value="<?php echo esc_attr( $option['host'] ); ?>" class="regular-text ">:<input type="text" name="mailster_options[multismtp][<?php echo $i ?>][port]" value="<?php echo $option['port']; ?>" class="small-text smtp"></td>
			</tr>
			<tr valign="top">
				<th scope="row">Timeout</th>
				<td><span><input type="text" name="mailster_options[multismtp][<?php echo $i ?>][timeout]" value="<?php echo $option['timeout']; ?>" class="small-text"> <?php _e( 'seconds', 'mailster-multismtp' ); ?></span></td>
			</tr>
			<tr valign="top">
				<th scope="row"><?php _e( 'Secure connection', 'mailster-multismtp' ) ?></th>
				<?php $secure = $option['secure']; ?>
				<td>
				<label><input type="radio" name="mailster_options[multismtp][<?php echo $i ?>][secure]" value="" <?php if ( ! $secure ) { echo ' checked'; } ?> class="smtp secure" data-port="25"> <?php _e( 'none', 'mailster-multismtp' ) ?></label>
				<label><input type="radio" name="mailster_options[multismtp][<?php echo $i ?>][secure]" value="ssl" <?php if ( $secure == 'ssl' ) { echo ' checked'; } ?> class="smtp secure" data-port="465"> SSL</label>
				<label><input type="radio" name="mailster_options[multismtp][<?php echo $i ?>][secure]" value="tls" <?php if ( $secure == 'tls' ) { echo ' checked'; } ?> class="smtp secure" data-port="465"> TLS</label>
				 </td>
			</tr>
			<tr valign="top">
				<th scope="row">SMTPAuth</th>
				<td>
				<?php $smtpauth = $option['auth'];?>
				<label>
				<select name="mailster_options[multismtp][<?php echo $i ?>][auth]">
					<option value="0" <?php selected( ! $smtpauth );?>><?php _e( 'none', 'mailster' ) ?></option>
					<option value="PLAIN" <?php selected( 'PLAIN', $smtpauth );?>>PLAIN</option>
					<option value="LOGIN" <?php selected( 'LOGIN', $smtpauth );?>>LOGIN</option>
					<option value="CRAM-MD5" <?php selected( 'CRAM-MD5', $smtpauth );?>>CRAM-MD5</option>
				</select></label>
				</td>
			</tr>
			<tr valign="top">
				<th scope="row"><?php _e( 'Username', 'mailster-multismtp' ) ?></th>
				<td><input type="text" name="mailster_options[multismtp][<?php echo $i ?>][user]" value="<?php echo esc_attr( $option['user'] ); ?>" class="regular-text"></td>
			</tr>
			<tr valign="top">
				<th scope="row"><?php _e( 'Password', 'mailster-multismtp' ) ?></th>
				<td><input type="password" name="mailster_options[multismtp][<?php echo $i ?>][pwd]" value="<?php echo esc_attr( $option['pwd'] ); ?>" class="regular-text"></td>
			</tr>
		</table>
		</div>

<?php
	}

?>
		<input type="hidden" name="mailster_options[multismtp_current]" value="<?php echo esc_attr( mailster_option( 'multismtp_current' ) ); ?>">
		<p><a class="button mailster-multismtp-add"><?php _e( 'add SMTP Server', 'mailster-multismtp' ); ?></a></p>
	<?php

	}


	/**
	 * notice function.
	 *
	 * Notice if Mailster is not available
	 *
	 * @access public
	 * @return void
	 */
	public function notice() {
?>
	<div id="message" class="error">
	  <p>
	   <strong>Multi SMTP for Mailster</strong> requires the <a href="http://rxa.li/mailster?utm_campaign=wporg&utm_source=Multi+SMTP+for+Mailster">Mailster Newsletter Plugin</a>, at least version <strong><?php echo MAILSTER_MULTISMTP_REQUIRED_VERSION?></strong>. Plugin deactivated.
	  </p>
	</div>
		<?php
	}


	/**
	 * mailster_amazonses_verify_options function.
	 *
	 * some verification if options are saved
	 *
	 * @access public
	 * @param mixed $options
	 * @return void
	 */
	public function verify_options( $options ) {

		// only if delivery method is active
		if ( $options['deliverymethod'] == 'multismtp' ) {

			$count = count( $options['multismtp'] );
			$time = time();
			$send_limit = $send_period = 0;
			for ( $i = 0; $i < $count; $i++ ) {
				if ( ! isset( $options['multismtp'][ $i ]['active'] ) ) { continue; }
				$time = min( $time, get_option( '_transient_timeout__mailster_send_period_timeout_' . $i, $time ) );
				$send_limit += intval( $options['multismtp'][ $i ]['send_limit'] );
				$send_period = max( $options['multismtp'][ $i ]['send_period'], $send_period );
				if ( function_exists( 'fsockopen' ) ) {
					$host = $options['multismtp'][ $i ]['host'];
					$port = $options['multismtp'][ $i ]['port'];
					$conn = fsockopen( $host, $port, $errno, $errstr, $options['multismtp'][ $i ]['timeout'] );

					if ( is_resource( $conn ) ) {

						fclose( $conn );

					} else {

						add_settings_error( 'mailster_options', 'mailster_options', sprintf( __( 'Not able to connected to %1$s via port %2$s! You may not be able to send mails cause of the locked port %3$s. Please contact your host or choose a different delivery method!', 'mailster-multismtp' ), '"' . $host . '"', $port, $port ) );
						unset( $options['multismtp'][ $i ]['active'] );

					}
				}
			}

			$options['send_limit'] = $send_limit;
			$options['send_period'] = $send_period;

		}

		return $options;
	}


}


new MailsterMultiSMTP();