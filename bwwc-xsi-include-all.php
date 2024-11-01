<?php
/*
StabilityShares Payments for WooCommerce
http://www.bitcoinway.com/
*/

//---------------------------------------------------------------------------
// Global definitions
if (!defined('BWWC_XSI_PLUGIN_NAME'))
  {
  define('BWWC_XSI_VERSION',           '3.10');

  //-----------------------------------------------
  define('BWWC_XSI_EDITION',           'Standard');    


  //-----------------------------------------------
  define('BWWC_XSI_SETTINGS_NAME',     'BWWC-XSI-Settings');
  define('BWWC_XSI_PLUGIN_NAME',       'StabilityShares Payments for WooCommerce');   


  // i18n plugin domain for language files
  define('BWWC_XSI_I18N_DOMAIN',       'bwwc');

  if (extension_loaded('gmp') && !defined('USE_EXT'))
    define ('USE_EXT', 'GMP');
  else if (extension_loaded('bcmath') && !defined('USE_EXT'))
    define ('USE_EXT', 'BCMATH');
  }
//---------------------------------------------------------------------------

// This loads the phpecc modules and selects best math library

if(! class_exists('bcmath_utils'))			{	require_once (dirname(__FILE__) . '/phpecc/classes/util/bcmath_Utils.php');}
if(! class_exists('gmp_utils'))				{	require_once (dirname(__FILE__) . '/phpecc/classes/util/gmp_Utils.php');}
if(! interface_exists('CurveFpInterface'))	{	require_once (dirname(__FILE__) . '/phpecc/classes/interface/CurveFpInterface.php');}
if(! class_exists('CurveFp'))				{	require_once (dirname(__FILE__) . '/phpecc/classes/CurveFp.php');}
if(! interface_exists('PointInterface'))		{	require_once (dirname(__FILE__) . '/phpecc/classes/interface/PointInterface.php');}
if(! class_exists('Point'))				{	require_once (dirname(__FILE__) . '/phpecc/classes/Point.php');}
if(! class_exists('NumberTheory'))				{	require_once (dirname(__FILE__) . '/phpecc/classes/NumberTheory.php');}

require_once (dirname(__FILE__) . '/bwwc-xsi-cron.php');
require_once (dirname(__FILE__) . '/bwwc-xsi-mpkgen.php');
require_once (dirname(__FILE__) . '/bwwc-xsi-utils.php');
require_once (dirname(__FILE__) . '/bwwc-xsi-admin.php');
require_once (dirname(__FILE__) . '/bwwc-xsi-render-settings.php');
require_once (dirname(__FILE__) . '/bwwc-xsi-stabilityshares-gateway.php');

?>