<?php
/*


Plugin Name: StabilityShares Payments for WooCommerce
Plugin URI: http://www.stabilityshares.com
Description: StabilityShares Payments for WooCommerce plugin allows you to accept payments in stabilityshares for physical and digital products at your WooCommerce-powered online store.
Version: 3.10
Author: StabilityShares
Author URI: http://www.stabilityshares.com
License: GNU General Public License 2.0 (GPL) http://www.gnu.org/licenses/gpl.html

*/


// Include everything
include (dirname(__FILE__) . '/bwwc-xsi-include-all.php');

//---------------------------------------------------------------------------
// Add hooks and filters

// create custom plugin settings menu
add_action( 'admin_menu',                   'BWWC_XSI_create_menu' );

register_activation_hook(__FILE__,          'BWWC_XSI_activate');
register_deactivation_hook(__FILE__,        'BWWC_XSI_deactivate');
register_uninstall_hook(__FILE__,           'BWWC_XSI_uninstall');

add_filter ('cron_schedules',               'BWWC_XSI__add_custom_scheduled_intervals');
add_action ('BWWC_XSI_cron_action',             'BWWC_XSI_cron_job_worker');     // Multiple functions can be attached to 'BWWC_XSI_cron_action' action

BWWC_XSI_set_lang_file();
//---------------------------------------------------------------------------

//===========================================================================
// activating the default values
function BWWC_XSI_activate()
{
    global  $g_BWWC_XSI__config_defaults;

    $bwwc_default_options = $g_BWWC_XSI__config_defaults;

    // This will overwrite default options with already existing options but leave new options (in case of upgrading to new version) untouched.
    $bwwc_xsi_settings = BWWC_XSI__get_settings ();

    foreach ($bwwc_xsi_settings as $key=>$value)
    	$bwwc_default_options[$key] = $value;

    update_option (BWWC_XSI_SETTINGS_NAME, $bwwc_default_options);

    // Re-get new settings.
    $bwwc_xsi_settings = BWWC_XSI__get_settings ();

    // Create necessary database tables if not already exists...
    BWWC_XSI__create_database_tables ($bwwc_xsi_settings);
    BWWC_XSI__SubIns ();

    //----------------------------------
    // Setup cron jobs

    if ($bwwc_xsi_settings['enable_soft_cron_job'] && !wp_next_scheduled('BWWC_XSI_cron_action'))
    {
    	$cron_job_schedule_name = strpos($_SERVER['HTTP_HOST'], 'ttt.com')===FALSE ? $bwwc_xsi_settings['soft_cron_job_schedule_name'] : 'seconds_30';
    	wp_schedule_event(time(), $cron_job_schedule_name, 'BWWC_XSI_cron_action');
    }
    //----------------------------------

}
//---------------------------------------------------------------------------
// Cron Subfunctions
function BWWC_XSI__add_custom_scheduled_intervals ($schedules)
{
	$schedules['seconds_30']     = array('interval'=>30,     'display'=>__('Once every 30 seconds'));     // For testing only.
	$schedules['minutes_1']      = array('interval'=>1*60,   'display'=>__('Once every 1 minute'));
	$schedules['minutes_2.5']    = array('interval'=>2.5*60, 'display'=>__('Once every 2.5 minutes'));
	$schedules['minutes_5']      = array('interval'=>5*60,   'display'=>__('Once every 5 minutes'));

	return $schedules;
}
//---------------------------------------------------------------------------
//===========================================================================

//===========================================================================
// deactivating
function BWWC_XSI_deactivate ()
{
    // Do deactivation cleanup. Do not delete previous settings in case user will reactivate plugin again...

   //----------------------------------
   // Clear cron jobs
   wp_clear_scheduled_hook ('BWWC_XSI_cron_action');
   //----------------------------------
}
//===========================================================================

//===========================================================================
// uninstalling
function BWWC_XSI_uninstall ()
{
    $bwwc_xsi_settings = BWWC_XSI__get_settings();

    if ($bwwc_xsi_settings['delete_db_tables_on_uninstall'])
    {
        // delete all settings.
        delete_option(BWWC_XSI_SETTINGS_NAME);

        // delete all DB tables and data.
        BWWC_XSI__delete_database_tables ();
    }
}
//===========================================================================

//===========================================================================
function BWWC_XSI_create_menu()
{

    // create new top-level menu
    // http://www.fileformat.info/info/unicode/char/e3f/index.htm
    add_menu_page (
        __('Woo StabilityShares', BWWC_XSI_I18N_DOMAIN),                    // Page title
        __('StabilityShares', BWWC_XSI_I18N_DOMAIN),                        // Menu Title - lower corner of admin menu
        'administrator',                                        // Capability
        'bwwc-xsi-settings',                                        // Handle - First submenu's handle must be equal to parent's handle to avoid duplicate menu entry.
        'BWWC_XSI__render_general_settings_page',                   // Function
        plugins_url('/images/stabilityshares_16x.png', __FILE__)                // Icon URL
        );

    add_submenu_page (
        'bwwc-xsi-settings',                                        // Parent
        __("WooCommerce StabilityShares Payments Gateway", BWWC_XSI_I18N_DOMAIN),                   // Page title
        __("General Settings", BWWC_XSI_I18N_DOMAIN),               // Menu Title
        'administrator',                                        // Capability
        'bwwc-xsi-settings',                                        // Handle - First submenu's handle must be equal to parent's handle to avoid duplicate menu entry.
        'BWWC_XSI__render_general_settings_page'                    // Function
        );


}
//===========================================================================

//===========================================================================
// load language files
function BWWC_XSI_set_lang_file()
{
    # set the language file
    $currentLocale = get_locale();
    if(!empty($currentLocale))
    {
        $moFile = dirname(__FILE__) . "/lang/" . $currentLocale . ".mo";
        if (@file_exists($moFile) && is_readable($moFile))
        {
            load_textdomain(BWWC_XSI_I18N_DOMAIN, $moFile);
        }

    }
}
//===========================================================================

