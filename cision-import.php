<?php
/*
Plugin Name: Cision Import
Version: 0.2
Description: Plugin for importing content from Cision.
Author: Richard Sweeney
Author URI: http://richardsweeny.com
Plugin URI: http://richardsweeny.com
Text Domain: cision-import
Domain Path: /languages
*/


/** Load plugin translations */
add_action( 'plugins_loaded', 'cision_load_translations' );
function cision_load_translations() {
 	load_plugin_textdomain( 'cision-import', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );
}


/** Include Cision Class */
include_once plugin_dir_path( __FILE__ ) . 'includes/class-cision-import.php';


function cision_cron_add_cron_schedule( $schedules ) {
	$schedules['fifteen_mins'] = array(
		'interval' => MINUTE_IN_SECONDS * 15,
		'display'  => __( 'Every Fifteen Minutes', 'cision-import' ),
	);
	return $schedules;
}
add_filter( 'cron_schedules', 'cision_cron_add_cron_schedule' );


/** Set up the cron hooks */
function cision_set_cron_jobs() {
	if ( ! wp_next_scheduled( 'cision_cron_jobs' ) ) {
		wp_schedule_event( time(), 'fifteen_mins', 'cision_cron_jobs' );
	}
}
add_action( 'wp', 'cision_set_cron_jobs' );


/** Get Swedish pressreleases */
add_action( 'cision_cron_jobs', 'cision_get_swedish_pressreleases' );
function cision_get_swedish_pressreleases() {
	new Cision_Import( '3831630A5B6F4DA5AA35A882AEE196C9', 'sv' );
}


/** Get English pressreleases */
add_action( 'cision_cron_jobs', 'cision_get_english_pressreleases' );
function cision_get_english_pressreleases() {
	new Cision_Import( 'B4C9F44C0AA84EA986E6871644E36029', 'en' );
}


// Uncomment these babies to grab a feed directly and skip cron.
//add_action( 'init', 'cision_get_swedish_pressreleases' );
//add_action( 'init', 'cision_get_english_pressreleases' );
