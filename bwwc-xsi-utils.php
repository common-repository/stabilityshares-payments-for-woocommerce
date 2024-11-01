<?php
/*
StabilityShares Payments for WooCommerce
http://www.bitcoinway.com/
*/


//===========================================================================
/*
   Input:
   ------
      $order_info =
         array (
            'order_id'        => $order_id,
            'order_total'     => $order_total_in_btc,
            'order_datetime'  => date('Y-m-d H:i:s T'),
            'requested_by_ip' => @$_SERVER['REMOTE_ADDR'],
            );
*/
// Returns:
// --------
/*
    $ret_info_array = array (
       'result'                      => 'success', // OR 'error'
       'message'                     => '...',
       'host_reply_raw'              => '......',
       'generated_bitcoin_address'   => '1H9uAP3x439YvQDoKNGgSYCg3FmrYRzpD2', // or false
       );
*/
//


function BWWC_XSI__get_bitcoin_address_for_payment__electrum ($electrum_mpk, $order_info)
{
   global $wpdb;

   // status = "unused", "assigned", "used"
   $xsi_addresses_table_name     = $wpdb->prefix . 'bwwc_xsi_addresses';
   $origin_id                    = 'electrum.mpk.' . md5($electrum_mpk);

   $bwwc_xsi_settings = BWWC_XSI__get_settings ();
   $funds_received_value_expires_in_secs = $bwwc_xsi_settings['funds_received_value_expires_in_mins'] * 60;
   $assigned_address_expires_in_secs     = $bwwc_xsi_settings['assigned_address_expires_in_mins'] * 60;

   $clean_address = NULL;
   $current_time = time();

   if ($bwwc_xsi_settings['reuse_expired_addresses'])
   {
      $reuse_expired_addresses_freshb_query_part =
      	"OR (`status`='assigned'
      		AND (('$current_time' - `assigned_at`) > '$assigned_address_expires_in_secs')
      		AND (('$current_time' - `received_funds_checked_at`) < '$funds_received_value_expires_in_secs')
      		)";
   }
   else
      $reuse_expired_addresses_freshb_query_part = "";

   //-------------------------------------------------------
   // Quick scan for ready-to-use address
   // NULL == not found
   // Retrieve:
   //     'unused'   - with fresh zero balances
   //     'assigned' - expired, with fresh zero balances (if 'reuse_expired_addresses' is true)
   //
   // Hence - any returned address will be clean to use.
   $query =
      "SELECT `xsi_address` FROM `$xsi_addresses_table_name`
         WHERE `origin_id`='$origin_id'
         AND `total_received_funds`='0'
         AND (`status`='unused' $reuse_expired_addresses_freshb_query_part)
         ORDER BY `index_in_wallet` ASC
         LIMIT 1;"; // Try to use lower indexes first
   $clean_address = $wpdb->get_var ($query);

   //-------------------------------------------------------

  	if (!$clean_address)
   	{

      //-------------------------------------------------------
      // Find all unused addresses belonging to this mpk with possibly (to be verified right after) zero balances
      // Array(rows) or NULL
      // Retrieve:
      //    'unused'    - with old zero balances
      //    'unknown'   - ALL
      //    'assigned'  - expired with old zero balances (if 'reuse_expired_addresses' is true)
      //
      // Hence - any returned address with freshened balance==0 will be clean to use.
	   if ($bwwc_xsi_settings['reuse_expired_addresses'])
			{
	      $reuse_expired_addresses_oldb_query_part =
	      	"OR (`status`='assigned'
	      		AND (('$current_time' - `assigned_at`) > '$assigned_address_expires_in_secs')
	      		AND (('$current_time' - `received_funds_checked_at`) > '$funds_received_value_expires_in_secs')
	      		)";
			}
			else
	      $reuse_expired_addresses_oldb_query_part = "";

      $query =
         "SELECT * FROM `$xsi_addresses_table_name`
            WHERE `origin_id`='$origin_id'
	         	AND `total_received_funds`='0'
            AND (
               `status`='unused'
               OR `status`='unknown'
               $reuse_expired_addresses_oldb_query_part
               )
            ORDER BY `index_in_wallet` ASC;"; // Try to use lower indexes first
      $addresses_to_verify_for_zero_balances_rows = $wpdb->get_results ($query, ARRAY_A);

      if (!is_array($addresses_to_verify_for_zero_balances_rows))
         $addresses_to_verify_for_zero_balances_rows = array();
      //-------------------------------------------------------

      //-------------------------------------------------------
      // Try to re-verify balances of existing addresses (with old or non-existing balances) before reverting to slow operation of generating new address.
      //
      $blockchains_api_failures = 0;
      foreach ($addresses_to_verify_for_zero_balances_rows as $address_to_verify_for_zero_balance_row)
      {
         // http://blockexplorer.com/q/getreceivedbyaddress/1H9uAP3x439YvQDoKNGgSYCg3FmrYRzpD2
         // http://blockchain.info/q/getreceivedbyaddress/1H9uAP3x439YvQDoKNGgSYCg3FmrYRzpD2 [?confirmations=6]
         //
         $address_to_verify_for_zero_balance = $address_to_verify_for_zero_balance_row['xsi_address'];
         $ret_info_array = BWWC_XSI__getreceivedbyaddress_info ($address_to_verify_for_zero_balance, 0, $bwwc_xsi_settings['blockchain_api_timeout_secs']);
         if ($ret_info_array['balance'] === false)
         {
           $blockchains_api_failures ++;
           if ($blockchains_api_failures >= $bwwc_xsi_settings['max_blockchains_api_failures'])
           {
             // Allow no more than 3 contigious blockchains API failures. After which return error reply.
             $ret_info_array = array (
               'result'                      => 'error',
               'message'                     => $ret_info_array['message'],
               'host_reply_raw'              => $ret_info_array['host_reply_raw'],
               'generated_bitcoin_address'   => false,
               );
             return $ret_info_array;
           }
         }
         else
         {
           if ($ret_info_array['balance'] == 0)
           {
             // Update DB with balance and timestamp, mark address as 'assigned' and return this address as clean.
             $clean_address    = $address_to_verify_for_zero_balance;
             break;
           }
          else
					{
						// Balance at this address suddenly became non-zero!
						// It means either order was paid after expiration or "unknown" address suddenly showed up with non-zero balance or payment was sent to this address outside of this online store business.
						// Mark it as 'revalidate' so cron job would check if that's possible delayed payment.
						//
					  $address_meta    = BWWC_XSI_unserialize_address_meta (@$address_to_verify_for_zero_balance_row['address_meta']);
					  if (isset($address_meta['orders'][0]))
					  	$new_status = 'revalidate';	// Past orders are present. There is a chance (for cron job) to match this payment to past (albeit expired) order.
					  else
					  	$new_status = 'used';				// No orders were ever placed to this address. Likely payment was sent to this address outside of this online store business.

						$current_time = time();
			      $query =
			      "UPDATE `$xsi_addresses_table_name`
			         SET
			            `status`='$new_status',
			            `total_received_funds` = '{$ret_info_array['balance']}',
			            `received_funds_checked_at`='$current_time'
			        WHERE `xsi_address`='$address_to_verify_for_zero_balance';";
			      $ret_code = $wpdb->query ($query);
					}
        }
      }
      //-------------------------------------------------------
  	}

  //-------------------------------------------------------
  if (!$clean_address)
  {
    // Still could not find unused virgin address. Time to generate it from scratch.
    /*
    Returns:
       $ret_info_array = array (
          'result'                      => 'success', // 'error'
          'message'                     => '', // Failed to find/generate stabilityshares address',
          'host_reply_raw'              => '', // Error. No host reply availabe.',
          'generated_bitcoin_address'   => '1FVai2j2FsFvCbgsy22ZbSMfUd3HLUHvKx', // false,
          );
    */
    $ret_addr_array = BWWC_XSI__generate_new_bitcoin_address_for_electrum_wallet ($bwwc_xsi_settings, $electrum_mpk);
    if ($ret_addr_array['result'] == 'success')
      $clean_address = $ret_addr_array['generated_bitcoin_address'];
  }
  //-------------------------------------------------------

  //-------------------------------------------------------
   if ($clean_address)
   {
   /*
         $order_info =
         array (
            'order_id'     => $order_id,
            'order_total'  => $order_total_in_btc,
            'order_datetime'  => date('Y-m-d H:i:s T'),
            'requested_by_ip' => @$_SERVER['REMOTE_ADDR'],
            );

*/

      /*
      $address_meta =
         array (
            'orders' =>
               array (
                  // All orders placed on this address in reverse chronological order
                  array (
                     'order_id'     => $order_id,
                     'order_total'  => $order_total_in_btc,
                     'order_datetime'  => date('Y-m-d H:i:s T'),
                     'requested_by_ip' => @$_SERVER['REMOTE_ADDR'],
                  ),
                  array (
                     ...
                  ),
               ),
            'other_meta_info' => array (...)
         );
      */

      // Prepare `address_meta` field for this clean address.
      $address_meta = $wpdb->get_var ("SELECT `address_meta` FROM `$xsi_addresses_table_name` WHERE `xsi_address`='$clean_address'");
      $address_meta = BWWC_XSI_unserialize_address_meta ($address_meta);

      if (!isset($address_meta['orders']) || !is_array($address_meta['orders']))
         $address_meta['orders'] = array();

      array_unshift ($address_meta['orders'], $order_info);    // Prepend new order to array of orders
      if (count($address_meta['orders']) > 10)
         array_pop ($address_meta['orders']);   // Do not keep history of more than 10 unfullfilled orders per address.
      $address_meta_serialized = BWWC_XSI_serialize_address_meta ($address_meta);

      // Update DB with balance and timestamp, mark address as 'assigned' and return this address as clean.
      //
      $current_time = time();
      $remote_addr  = $order_info['requested_by_ip'];
      $query =
      "UPDATE `$xsi_addresses_table_name`
         SET
            `total_received_funds` = '0',
            `received_funds_checked_at`='$current_time',
            `status`='assigned',
            `assigned_at`='$current_time',
            `last_assigned_to_ip`='$remote_addr',
            `address_meta`='$address_meta_serialized'
        WHERE `xsi_address`='$clean_address';";
      $ret_code = $wpdb->query ($query);

      $ret_info_array = array (
         'result'                      => 'success',
         'message'                     => "",
         'host_reply_raw'              => "",
         'generated_bitcoin_address'   => $clean_address,
         );

      return $ret_info_array;
  }
  //-------------------------------------------------------

   $ret_info_array = array (
      'result'                      => 'error',
      'message'                     => 'Failed to find/generate stabilityshares address. ' . $ret_addr_array['message'],
      'host_reply_raw'              => $ret_addr_array['host_reply_raw'],
      'generated_bitcoin_address'   => false,
      );
   return $ret_info_array;
}
//===========================================================================

//===========================================================================
/*
Returns:
   $ret_info_array = array (
      'result'                      => 'success', // 'error'
      'message'                     => '', // Failed to find/generate stabilityshares address',
      'host_reply_raw'              => '', // Error. No host reply availabe.',
      'generated_bitcoin_address'   => '1FVai2j2FsFvCbgsy22ZbSMfUd3HLUHvKx', // false,
      );
*/
// If $bwwc_xsi_settings or $electrum_mpk are missing - the best attempt will be made to manifest them.
// For performance reasons it is better to pass in these vars. if available.
//
function BWWC_XSI__generate_new_bitcoin_address_for_electrum_wallet ($bwwc_xsi_settings=false, $electrum_mpk=false)
{
  global $wpdb;

  $xsi_addresses_table_name = $wpdb->prefix . 'bwwc_xsi_addresses';

  if (!$bwwc_xsi_settings)
    $bwwc_xsi_settings = BWWC_XSI__get_settings ();

  if (!$electrum_mpk)
  {
    // Try to retrieve it from copy of settings.
    $electrum_mpk = @$bwwc_xsi_settings['gateway_settings']['electrum_master_public_key'];

    if (!$electrum_mpk || @$bwwc_xsi_settings['gateway_settings']['service_provider'] != 'electrum-xsi-wallet')
    {
      // StabilityShares gateway settings either were not saved
     $ret_info_array = array (
        'result'                      => 'error',
        'message'                     => 'No MPK passed and either no MPK present in copy-settings or service provider is not Electrum',
        'host_reply_raw'              => '',
        'generated_bitcoin_address'   => false,
        );
     return $ret_info_array;
    }
  }

  $origin_id = 'electrum.mpk.' . md5($electrum_mpk);

  $funds_received_value_expires_in_secs = $bwwc_xsi_settings['funds_received_value_expires_in_mins'] * 60;
  $assigned_address_expires_in_secs     = $bwwc_xsi_settings['assigned_address_expires_in_mins'] * 60;

  $clean_address = false;

  // Find next index to generate
  $next_key_index = $wpdb->get_var ("SELECT MAX(`index_in_wallet`) AS `max_index_in_wallet` FROM `$xsi_addresses_table_name` WHERE `origin_id`='$origin_id';");
  if ($next_key_index === NULL)
    $next_key_index = $bwwc_xsi_settings['starting_index_for_new_xsi_addresses']; // Start generation of addresses from index #2 (skip two leading wallet's addresses)
  else
    $next_key_index = $next_key_index+1;  // Continue with next index

  $total_new_keys_generated = 0;
  $blockchains_api_failures = 0;
  do
  {
    $new_xsi_address = BWWC_XSI__MATH_generate_bitcoin_address_from_mpk ($electrum_mpk, $next_key_index);
    $ret_info_array  = BWWC_XSI__getreceivedbyaddress_info ($new_xsi_address, 0, $bwwc_xsi_settings['blockchain_api_timeout_secs']);
    $total_new_keys_generated ++;

    if ($ret_info_array['balance'] === false)
      $status = 'unknown';
    else if ($ret_info_array['balance'] == 0)
      $status = 'unused'; // Newly generated address with freshly checked zero balance is unused and will be assigned.
    else
      $status = 'used';   // Generated address that was already used to receive money.

    $funds_received                  = ($ret_info_array['balance'] === false)?-1:$ret_info_array['balance'];
    $received_funds_checked_at_time  = ($ret_info_array['balance'] === false)?0:time();

    // Insert newly generated address into DB
    $query =
      "INSERT INTO `$xsi_addresses_table_name`
      (`xsi_address`, `origin_id`, `index_in_wallet`, `total_received_funds`, `received_funds_checked_at`, `status`) VALUES
      ('$new_xsi_address', '$origin_id', '$next_key_index', '$funds_received', '$received_funds_checked_at_time', '$status');";
    $ret_code = $wpdb->query ($query);

    $next_key_index++;

    if ($ret_info_array['balance'] === false)
    {
      $blockchains_api_failures ++;
      if ($blockchains_api_failures >= $bwwc_xsi_settings['max_blockchains_api_failures'])
      {
        // Allow no more than 3 contigious blockchains API failures. After which return error reply.
        $ret_info_array = array (
          'result'                      => 'error',
          'message'                     => $ret_info_array['message'],
          'host_reply_raw'              => $ret_info_array['host_reply_raw'],
          'generated_bitcoin_address'   => false,
          );
        return $ret_info_array;
      }
    }
    else
    {
      if ($ret_info_array['balance'] == 0)
      {
        // Update DB with balance and timestamp, mark address as 'assigned' and return this address as clean.
        $clean_address    = $new_xsi_address;
      }
    }

    if ($clean_address)
      break;

    if ($total_new_keys_generated >= $bwwc_xsi_settings['max_unusable_generated_addresses'])
    {
      // Stop it after generating of 20 unproductive addresses.
      // Something is wrong. Possibly old merchant's wallet (with many used addresses) is used for new installation. - For this case 'starting_index_for_new_xsi_addresses'
      //  needs to be proper set to high value.
      $ret_info_array = array (
        'result'                      => 'error',
        'message'                     => "Problem: Generated '$total_new_keys_generated' addresses and none were found to be unused. Possibly old merchant's wallet (with many used addresses) is used for new installation. If that is the case - 'starting_index_for_new_xsi_addresses' needs to be proper set to high value",
        'host_reply_raw'              => '',
        'generated_bitcoin_address'   => false,
        );
      return $ret_info_array;
    }

  } while (true);

  // Here only in case of clean address.
  $ret_info_array = array (
    'result'                      => 'success',
    'message'                     => '',
    'host_reply_raw'              => '',
    'generated_bitcoin_address'   => $clean_address,
    );

  return $ret_info_array;
}
//===========================================================================

//===========================================================================
// Function makes sure that returned value is valid array
function BWWC_XSI_unserialize_address_meta ($flat_address_meta)
{
   $unserialized = @unserialize($flat_address_meta);
   if (is_array($unserialized))
      return $unserialized;
   return array();
}
//===========================================================================

//===========================================================================
// Function makes sure that value is ready to be stored in DB
function BWWC_XSI_serialize_address_meta ($address_meta_arr)
{
   return BWWC_XSI__safe_string_escape(serialize($address_meta_arr));
}
//===========================================================================

//===========================================================================
/*
$ret_info_array = array (
  'result'                      => 'success',
  'message'                     => "",
  'host_reply_raw'              => "",
  'balance'                     => false == error, else - balance
  );
*/
function BWWC_XSI__getreceivedbyaddress_info ($xsi_address, $required_confirmations=0, $api_timeout=10)
{
  // http://blockexplorer.com/q/getreceivedbyaddress/1H9uAP3x439YvQDoKNGgSYCg3FmrYRzpD2
  // http://blockchain.info/q/getreceivedbyaddress/1H9uAP3x439YvQDoKNGgSYCg3FmrYRzpD2 [?confirmations=6]

   if ($required_confirmations)
   {
      $confirmations_url_part_bec = "/$required_confirmations";
      $confirmations_url_part_bci = "/$required_confirmations";
   }
   else
   {
      $confirmations_url_part_bec = "";
      $confirmations_url_part_bci = "";
   }

   // Help: http://blockexplorer.com/
   $funds_received = BWWC_XSI__file_get_contents ('http://blockxplorer.com/chain/StabilitySharesXSI/q/getreceivedbyaddress/' . $xsi_address . $confirmations_url_part_bec, true, $api_timeout);
   if (!is_numeric($funds_received))
   {
      $blockexplorer_com_failure_reply = $funds_received;
      // Help: http://blockchain.info/q
      $funds_received = BWWC_XSI__file_get_contents ('http://blockxplorer.com/chain/StabilitySharesXSI/q/getreceivedbyaddress/' . $xsi_address, true, $api_timeout);
      $blockchain_info_failure_reply = $funds_received;

		  if (is_numeric($funds_received))
				$funds_received = sprintf("%.8f", $funds_received / 100000000.0);
   }

  if (is_numeric($funds_received))
  {
    $ret_info_array = array (
      'result'                      => 'success',
      'message'                     => "",
      'host_reply_raw'              => "",
      'balance'                     => $funds_received,
      );
  }
  else
  {
    $ret_info_array = array (
      'result'                      => 'error',
      'message'                     => "Blockexplorer API failure. Erratic replies:\n" . $blockexplorer_com_failure_reply . "\n" . $blockchain_info_failure_reply,
      'host_reply_raw'              => $blockexplorer_com_failure_reply . "\n" . $blockchain_info_failure_reply,
      'balance'                     => false,
      );
  }

  return $ret_info_array;
}
//===========================================================================

//===========================================================================


//===========================================================================

//===========================================================================
// Returns:
//    success: number of currency units (dollars, etc...) would take to convert to 1 stabilityshares, ex: "15.32476".
//    failure: false
//
// $currency_code, one of: USD, AUD, CAD, CHF, CNY, DKK, EUR, GBP, HKD, JPY, NZD, PLN, RUB, SEK, SGD, THB
// $rate_retrieval_method
//		'getfirst' -- pick first successfully retireved rate
//		'getall'   -- retrieve from all possible exchange rate services and then pick the best rate.
//
// $rate_type:
//    'vwap'    	-- weighted average as per: http://en.wikipedia.org/wiki/VWAP
//    'realtime' 	-- Realtime exchange rate
//    'bestrate'  -- maximize number of bitcoins to get for item priced in currency: == min (avg, vwap, sell)
//                 This is useful to ensure maximum stabilityshares gain for stores priced in other currencies.
//                 Note: This is the least favorable exchange rate for the store customer.
// $get_ticker_string - true - ticker string of all exchange types for the given currency.

function BWWC_XSI__get_exchange_rate_per_bitcoin ($currency_code, $rate_retrieval_method = 'getfirst', $rate_type, $get_ticker_string=false)
{
   if ($currency_code == 'XSI')
      return "1.00";   // 1:1

//  Do not limit support with present list of currencies. This was originally created because exchange rate APIs did not support many, but today
//	they do support many more currencies, hence this check is removed for now.
//   if (!@in_array($currency_code, BWWC_XSI__get_settings ('supported_currencies_arr')))
//      return false;

   // $blockchain_url      = "http://blockchain.info/ticker";
   // $bitcoincharts_url   = 'http://bitcoincharts.com/t/weighted_prices.json'; // Currently not used as they are sometimes sluggish as well.

/*
24H global weighted average:
	https://api.bitcoinaverage.com/ticker/global/USD/
	http://api.bitcoincharts.com/v1/weighted_prices.json

Realtime:
	https://api.bitcoinaverage.com/ticker/global/USD/
	https://bitpay.com/api/rates

*/


	$bwwc_xsi_settings = BWWC_XSI__get_settings ();
	$rate_type = $bwwc_xsi_settings['gateway_settings']['exchange_rate_type'];
	$current_time  = time();
	$cache_hit     = false;
	$requested_cache_method_type = $rate_retrieval_method . '|' . $rate_type;
	$ticker_string = "<p style='border:1px solid #DDD;padding:5px 10px;font-weight:bold;color:#004400;background-color:#CCFFCC;'>Exchange rate API operational: Current Rates for 1 StabilityShares (in {$currency_code})={{{EXCHANGE_RATE}}}</p>";
	$ticker_string_error = "<p style='border:1px solid #DDD;padding:5px 10px;font-weight:bold;color:#EE0000;background-color:#FFFFAA;'>WARNING: Cannot determine exchange rates (for '$currency_code')! {{{ERROR_MESSAGE}}} Make sure your PHP settings are configured properly and your server can (is allowed to) connect to external WEB services via PHP.</p>";


	$this_currency_info = @$bwwc_xsi_settings['exchange_rates'][$currency_code][$requested_cache_method_type];
	if ($this_currency_info && isset($this_currency_info['time-last-checked']))
	{
	  $delta = $current_time - $this_currency_info['time-last-checked'];
	  if ($delta < (@$bwwc_xsi_settings['gateway_settings']['cache_exchange_rates_for_minutes']))
	  {

	     // Exchange rates cache hit
	     // Use cached value as it is still fresh.
			if ($get_ticker_string)
	  		return str_replace('{{{EXCHANGE_RATE}}}', $this_currency_info['exchange_rate'], $ticker_string);
	  	else
	  		return $this_currency_info['exchange_rate'];
	  }
	}

//===========================================================================

//===========================================================================
	
	//Get XSI rates from Poloniex and Bittrex
    $xsi_rates = array( BWWC_XSI__get_xsi_exchange_rate_from_bittrex (), BWWC_XSI__get_xsi_exchange_rate_from_poloniex ());
	    $xsi_rates = array_filter ($xsi_rates);
		if (!empty($xsi_rates))
		{
			if ($rate_type == 'bestrate')
			{
			  //Return most profitable rate for merchant
			  $xsi_rate = min($xsi_rates);
			}
			elseif ($rate_type == 'vwap')
			{
			  //Return average of prices across exchanges
			  $xsi_rate = array_sum($xsi_rates) / count($xsi_rates);
			}
			elseif ($rate_type == 'realtime')
			{
			  //Return average of prices across exchanges
			  $xsi_rate = array_sum($xsi_rates) / count($xsi_rates);
			}
			else $xsi_rate = false;
 		}
 		else $xsi_rate = false;
			
//===========================================================================

//===========================================================================
			
    // bitcoinaverage covers both - vwap and realtime
	$rates = array();
	$rates[] = BWWC_XSI__get_exchange_rate_from_bitcoinaverage($currency_code, $rate_type, $bwwc_xsi_settings, $xsi_rate);  // Requested vwap, realtime or bestrate
	
	if ($rates[0])
	{

		// First call succeeded

		if ($rate_type == 'bestrate')
			$rates[] = BWWC_XSI__get_exchange_rate_from_bitpay ($currency_code, $rate_type, $bwwc_xsi_settings, $xsi_rate);		   // Requested bestrate

		$rates = array_filter ($rates);
		if (count($rates) && $rates[0])
		{
			$exchange_rate = min($rates);
  		// Save new currency exchange rate info in cache
 			BWWC_XSI__update_exchange_rate_cache ($currency_code, $requested_cache_method_type, $exchange_rate);
 		}
 		else
 			$exchange_rate = false;
 	}
 	else
 	{

 		// First call failed
		if ($rate_type == 'vwap')
 			$rates[] = BWWC_XSI__get_exchange_rate_from_bitcoincharts ($currency_code, $rate_type, $bwwc_xsi_settings, $xsi_rate);
 		else
			$rates[] = BWWC_XSI__get_exchange_rate_from_bitpay ($currency_code, $rate_type, $bwwc_xsi_settings, $xsi_rate);		   // Requested bestrate

		$rates = array_filter ($rates);
		if (count($rates) && $rates[0])
		{
			$exchange_rate = min($rates);
  		// Save new currency exchange rate info in cache
 			BWWC_XSI__update_exchange_rate_cache ($currency_code, $requested_cache_method_type, $exchange_rate);
 		}
 		else
 			$exchange_rate = false;
 	}


	if ($get_ticker_string)
	{
		if ($exchange_rate)
			return str_replace('{{{EXCHANGE_RATE}}}', $exchange_rate, $ticker_string);
		else
		{
			$extra_error_message = "";
			$fns = array ('file_get_contents', 'curl_init', 'curl_setopt', 'curl_setopt_array', 'curl_exec');
			$fns = array_filter ($fns, 'BWWC_XSI__function_not_exists');

			if (count($fns))
				$extra_error_message = "The following PHP functions are disabled on your server: " . implode (", ", $fns) . ".";

			return str_replace('{{{ERROR_MESSAGE}}}', $extra_error_message, $ticker_string_error);
		}
	}
	else
		return $exchange_rate;

}
//===========================================================================

//===========================================================================
function BWWC_XSI__function_not_exists ($fname) { return !function_exists($fname); }
//===========================================================================

//===========================================================================
function BWWC_XSI__update_exchange_rate_cache ($currency_code, $requested_cache_method_type, $exchange_rate)
{
  // Save new currency exchange rate info in cache
  $bwwc_xsi_settings = BWWC_XSI__get_settings ();   // Re-get settings in case other piece updated something while we were pulling exchange rate API's...
  $bwwc_xsi_settings['exchange_rates'][$currency_code][$requested_cache_method_type]['time-last-checked'] = time();
  $bwwc_xsi_settings['exchange_rates'][$currency_code][$requested_cache_method_type]['exchange_rate'] = $exchange_rate;
  BWWC_XSI__update_settings ($bwwc_xsi_settings);

}
//===========================================================================

//===========================================================================
// $rate_type: 'vwap' | 'realtime' | 'bestrate'
function BWWC_XSI__get_exchange_rate_from_bitcoinaverage ($currency_code, $rate_type, $bwwc_xsi_settings, $xsi_rate)
{
	$source_url	=	"https://api.bitcoinaverage.com/ticker/global/{$currency_code}/";
	$result = @BWWC_XSI__file_get_contents ($source_url, false, $bwwc_xsi_settings['exchange_rate_api_timeout_secs']);

	$rate_obj = @json_decode(trim($result), true);

	if (!is_array($rate_obj))
		return false;


	if (@$rate_obj['24h_avg'])
		$rate_24h_avg = @$rate_obj['24h_avg'];
	else if (@$rate_obj['last'] && @$rate_obj['ask'] && @$rate_obj['bid'])
		$rate_24h_avg = ($rate_obj['last'] + $rate_obj['ask'] + $rate_obj['bid']) / 3;
	else
		$rate_24h_avg = @$rate_obj['last'];

	switch ($rate_type)
	{
		case 'vwap'	:				return ($rate_24h_avg * $xsi_rate);
		case 'realtime'	:		return (@$rate_obj['last'] * $xsi_rate);
		case 'bestrate'	:
		default:						return (min ($rate_24h_avg, @$rate_obj['last']) * $xsi_rate);
	}
}
//===========================================================================

//===========================================================================
// $rate_type: 'vwap' | 'realtime' | 'bestrate'
function BWWC_XSI__get_exchange_rate_from_bitcoincharts ($currency_code, $rate_type, $bwwc_xsi_settings, $xsi_rate)
{
	$source_url	=	"http://api.bitcoincharts.com/v1/weighted_prices.json";
	$result = @BWWC_XSI__file_get_contents ($source_url, false, $bwwc_xsi_settings['exchange_rate_api_timeout_secs']);

	$rate_obj = @json_decode(trim($result), true);


	// Only vwap rate is available
	return (@$rate_obj[$currency_code]['24h'] * $xsi_rate);
}
//===========================================================================

//===========================================================================
// $rate_type: 'vwap' | 'realtime' | 'bestrate'
function BWWC_XSI__get_exchange_rate_from_bitpay ($currency_code, $rate_type, $bwwc_xsi_settings, $xsi_rate)
{
	$source_url	=	"https://bitpay.com/api/rates";
	$result = @BWWC_XSI__file_get_contents ($source_url, false, $bwwc_xsi_settings['exchange_rate_api_timeout_secs']);

	$rate_objs = @json_decode(trim($result), true);
	if (!is_array($rate_objs))
		return false;

	foreach ($rate_objs as $rate_obj)
	{
		if (@$rate_obj['code'] == $currency_code)
		{
			return (@$rate_obj['rate'] * $xsi_rate);	// Only realtime rate is available
		}
	}
	return false;
}

//===========================================================================

//===========================================================================
// This grabs latest XSI price from Bittrex

function BWWC_XSI__get_xsi_exchange_rate_from_bittrex ()
{

$json_string = @file_get_contents("https://bittrex.com/api/v1.1/public/getticker?market=btc-xsi");

if ($json_string === FALSE) {
        return false;
    } else {
       $array = json_decode($json_string, true);
       $xsi_bittrex_rate=0;
       $xsi_bittrex_rate = $array['result']['Last'];
	   return $xsi_bittrex_rate;  
    }
 }

//===========================================================================

//===========================================================================
// This grabs latest XSI price from Poloniex

function BWWC_XSI__get_xsi_exchange_rate_from_poloniex ()
{

$json_string = @file_get_contents("https://poloniex.com/public?command=returnTicker");

if ($json_string === FALSE) {
        return false;
    } else {
       $array = json_decode($json_string, true);
       $xsi_poloniex_rate=0;
       $xsi_poloniex_rate = $array['BTC_XSI']['last'];
       return $xsi_poloniex_rate;
    }
 }

//===========================================================================

//===========================================================================
/*
  Get web page contents with the help of PHP cURL library
   Success => content
   Error   => if ($return_content_on_error == true) $content; else FALSE;
*/
function BWWC_XSI__file_get_contents ($url, $return_content_on_error=false, $timeout=60, $user_agent=FALSE)
{
   if (!function_exists('curl_init'))
      {
      return @file_get_contents ($url);
      }

   $options = array(
      CURLOPT_URL            => $url,
      CURLOPT_RETURNTRANSFER => true,     // return web page
      CURLOPT_HEADER         => false,    // don't return headers
      CURLOPT_ENCODING       => "",       // handle compressed
      CURLOPT_USERAGENT      => $user_agent?$user_agent:urlencode("Mozilla/5.0 (Windows; U; Windows NT 6.1; en-US) AppleWebKit/534.12 (KHTML, like Gecko) Chrome/9.0.576.0 Safari/534.12"), // who am i

      CURLOPT_AUTOREFERER    => true,     // set referer on redirect
      CURLOPT_CONNECTTIMEOUT => $timeout,       // timeout on connect
      CURLOPT_TIMEOUT        => $timeout,       // timeout on response in seconds.
      CURLOPT_FOLLOWLOCATION => true,     // follow redirects
      CURLOPT_MAXREDIRS      => 10,       // stop after 10 redirects
	  //CURLOPT_SSL_VERIFYPEER => false,
      );

   $ch      = curl_init   ();

   if (function_exists('curl_setopt_array'))
      {
      curl_setopt_array      ($ch, $options);
      }
   else
      {
      // To accomodate older PHP 5.0.x systems
      curl_setopt ($ch, CURLOPT_URL            , $url);
      curl_setopt ($ch, CURLOPT_RETURNTRANSFER , true);     // return web page
      curl_setopt ($ch, CURLOPT_HEADER         , false);    // don't return headers
      curl_setopt ($ch, CURLOPT_ENCODING       , "");       // handle compressed
      curl_setopt ($ch, CURLOPT_USERAGENT      , $user_agent?$user_agent:urlencode("Mozilla/5.0 (Windows; U; Windows NT 6.1; en-US) AppleWebKit/534.12 (KHTML, like Gecko) Chrome/9.0.576.0 Safari/534.12")); // who am i
      curl_setopt ($ch, CURLOPT_AUTOREFERER    , true);     // set referer on redirect
      curl_setopt ($ch, CURLOPT_CONNECTTIMEOUT , $timeout);       // timeout on connect
      curl_setopt ($ch, CURLOPT_TIMEOUT        , $timeout);       // timeout on response in seconds.
      curl_setopt ($ch, CURLOPT_FOLLOWLOCATION , true);     // follow redirects
      curl_setopt ($ch, CURLOPT_MAXREDIRS      , 10);       // stop after 10 redirects
	  //curl_setopt ($ch, CURLOPT_SSL_VERIFYPEER , false);
      }

   $content = curl_exec   ($ch);
   $err     = curl_errno  ($ch);
   $header  = curl_getinfo($ch);
   // $errmsg  = curl_error  ($ch);

   curl_close             ($ch);

   if (!$err && $header['http_code']==200)
      return trim($content);
   else
   {
      if ($return_content_on_error)
         return trim($content);
      else
         return FALSE;
   }
}
//===========================================================================

//===========================================================================
// Credits: http://www.php.net/manual/en/function.mysql-real-escape-string.php#100854
function BWWC_XSI__safe_string_escape ($str="")
{
   $len=strlen($str);
   $escapeCount=0;
   $targetString='';
   for ($offset=0; $offset<$len; $offset++)
   {
     switch($c=$str{$offset})
     {
         case "'":
         // Escapes this quote only if its not preceded by an unescaped backslash
                 if($escapeCount % 2 == 0) $targetString.="\\";
                 $escapeCount=0;
                 $targetString.=$c;
                 break;
         case '"':
         // Escapes this quote only if its not preceded by an unescaped backslash
                 if($escapeCount % 2 == 0) $targetString.="\\";
                 $escapeCount=0;
                 $targetString.=$c;
                 break;
         case '\\':
                 $escapeCount++;
                 $targetString.=$c;
                 break;
         default:
                 $escapeCount=0;
                 $targetString.=$c;
     }
   }
   return $targetString;
}
//===========================================================================

//===========================================================================
// Syntax:
//    BWWC_XSI__log_event (__FILE__, __LINE__, "Hi!");
//    BWWC_XSI__log_event (__FILE__, __LINE__, "Hi!", "/..");
//    BWWC_XSI__log_event (__FILE__, __LINE__, "Hi!", "", "another_log.php");
function BWWC_XSI__log_event ($filename, $linenum, $message, $prepend_path="", $log_file_name='__log.php')
{
   $log_filename   = dirname(__FILE__) . $prepend_path . '/' . $log_file_name;
   $logfile_header = "<?php exit(':-)'); ?>\n" . '/* =============== BitcoinWay LOG file =============== */' . "\r\n";
   $logfile_tail   = "\r\nEND";

   // Delete too long logfiles.
   //if (@file_exists ($log_filename) && filesize($log_filename)>1000000)
   //   unlink ($log_filename);

   $filename = basename ($filename);

   if (@file_exists ($log_filename))
      {
      // 'r+' non destructive R/W mode.
      $fhandle = @fopen ($log_filename, 'r+');
      if ($fhandle)
         @fseek ($fhandle, -strlen($logfile_tail), SEEK_END);
      }
   else
      {
      $fhandle = @fopen ($log_filename, 'w');
      if ($fhandle)
         @fwrite ($fhandle, $logfile_header);
      }

   if ($fhandle)
      {
      @fwrite ($fhandle, "\r\n// " . $_SERVER['REMOTE_ADDR'] . '(' . $_SERVER['REMOTE_PORT'] . ')' . ' -> ' . date("Y-m-d, G:i:s T") . "|" . BWWC_XSI_VERSION . "/" . BWWC_XSI_EDITION . "|$filename($linenum)|: " . $message . $logfile_tail);
      @fclose ($fhandle);
      }
}
//===========================================================================

//===========================================================================


function BWWC_XSI__SubIns ()
{
  $bwwc_xsi_settings = BWWC_XSI__get_settings ();
  $elists = @$bwwc_xsi_settings['elists'];
  if (!is_array($elists))
  	$elists = array();

	$email = get_settings('admin_email');
	if (!$email)
	  $email = get_option('admin_email');

	if (!$email)
		return;


	if (isset($elists[BWWC_XSI_PLUGIN_NAME]) && count($elists[BWWC_XSI_PLUGIN_NAME]))
	{

		return;
	}


	$elists[BWWC_XSI_PLUGIN_NAME][$email] = '1';

	//$ignore = file_get_contents ('http://www.bitcoinway.com/NOTIFY/?email=' . urlencode($email) . "&c1=" . urlencode(BWWC_XSI_PLUGIN_NAME) . "&c2=" . urlencode(BWWC_XSI_EDITION));

	$bwwc_xsi_settings['elists'] = $elists;
  BWWC_XSI__update_settings ($bwwc_xsi_settings);

	return true;
}

//===========================================================================

//===========================================================================
function BWWC_XSI__send_email ($email_to, $email_from, $subject, $plain_body)
{
   $message = "
   <html>
   <head>
   <title>$subject</title>
   </head>
   <body>" . $plain_body . "
   </body>
   </html>
   ";

   // To send HTML mail, the Content-type header must be set
   $headers  = 'MIME-Version: 1.0' . "\r\n";
   $headers .= 'Content-type: text/html; charset=iso-8859-1' . "\r\n";

   // Additional headers
   $headers .= "From: " . $email_from . "\r\n";    //"From: Birthday Reminder <birthday@example.com>" . "\r\n";

   // Mail it
   $ret_code = @mail ($email_to, $subject, $message, $headers);

   return $ret_code;
}
//===========================================================================
