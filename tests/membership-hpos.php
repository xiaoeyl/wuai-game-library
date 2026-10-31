<?php
// Run only against prepare-membership-hpos.py's independent SQLite snapshot.
// This wrapper does not switch the shared CPT site or claim MySQL coverage.
if(PHP_SAPI!=='cli')exit(1);
function membership_hpos_check($condition,$message){if(!$condition)throw new RuntimeException($message);}
function membership_hpos_path($path){$resolved=realpath($path);return $resolved?strtolower(str_replace('\\','/',rtrim($resolved,'/\\'))):false;}
$root=getenv('WP_QA_ROOT');$target=$root?membership_hpos_path($root):false;
membership_hpos_check($target && is_file($root.'/membership-hpos-isolated.json'),'Prepare a separate HPOS snapshot first.');
$marker=json_decode(file_get_contents($root.'/membership-hpos-isolated.json'),true);
membership_hpos_check(is_array($marker) && ($marker['kind']??'')==='WUAI_MEMBERSHIP_HPOS_ISOLATED_V1','Explicit isolated snapshot marker required.');
$source=membership_hpos_path($marker['source']??'');$database=membership_hpos_path($marker['database']??'');$source_db=membership_hpos_path($marker['source_database']??'');
membership_hpos_check($target===membership_hpos_path($marker['target']??'') && $source && $source!==$target && strpos($source,$target.'/')!==0 && strpos($target,$source.'/')!==0,'Source and target roots must be distinct and non-overlapping.');
membership_hpos_check($database && $source_db && $database!==$source_db && strpos($database,$target.'/wp-content/database/')===0 && strpos($source_db,$source.'/wp-content/database/')===0,'The snapshot database must be independent and inside its copied site.');
membership_hpos_check(!defined('ABSPATH') && !defined('DB_PATH'),'Run in a fresh PHP process.');
$config=file_get_contents($root.'/wp-config.php');
membership_hpos_check(strpos($config,'http://127.0.0.1:8771')!==false,'Only localhost QA configuration is allowed.');
membership_hpos_check(!preg_match('/define\s*\(\s*[\'"](?:DB_PATH|DB_DIR|DB_FILE|FQDB|FQDBDIR|WP_CONTENT_DIR|WP_PLUGIN_DIR|WPMU_PLUGIN_DIR|WP_CACHE)[\'"]/i',$config),'Copied config must not override isolated database/content/cache paths.');
// These guards apply before WordPress loads; plugins cannot select the source DB.
define('DB_PATH',$marker['database']);define('DB_ENGINE','sqlite');define('WP_CONTENT_DIR',$root.'/wp-content');define('WP_CACHE',false);
membership_hpos_check(!is_file(WP_CONTENT_DIR.'/object-cache.php') && !is_file(WP_CONTENT_DIR.'/advanced-cache.php'),'Do not share a persistent object cache with the CPT site.');
if(!is_dir(WP_CONTENT_DIR.'/mu-plugins'))mkdir(WP_CONTENT_DIR.'/mu-plugins',0777,true);
$helper=WP_CONTENT_DIR.'/mu-plugins/wuai-membership-hpos-qa.php';
if(is_file($helper))membership_hpos_check(strpos(file_get_contents($helper),'WUAI_MEMBERSHIP_HPOS_QA_GUARD')!==false,'Refuse to replace an unrelated helper.');
$helper_php=<<<'PHP'
<?php
// WUAI_MEMBERSHIP_HPOS_QA_GUARD: this copied installation is CLI-only.
if(!defined('ABSPATH'))exit;
if(PHP_SAPI!=='cli'){http_response_code(403);exit;}
add_filter('pre_wp_mail','__return_true',PHP_INT_MAX);
add_filter('woocommerce_payment_gateways','__return_empty_array',PHP_INT_MAX);
add_filter('woocommerce_available_payment_gateways','__return_empty_array',PHP_INT_MAX);
add_filter('pre_http_request',function(){return new WP_Error('qa_network_disabled','No outgoing HTTP in the isolated HPOS test.');},PHP_INT_MAX);
PHP;
membership_hpos_check(file_put_contents($helper,$helper_php)!==false,'Write CLI-only network/mail guard in the copied site.');
$_SERVER['HTTP_HOST']='127.0.0.1:8771';$_SERVER['REQUEST_URI']='/';require $root.'/wp-load.php';
membership_hpos_check(membership_hpos_path(ABSPATH)===$target && membership_hpos_path(DB_PATH)===$database && defined('DB_ENGINE') && DB_ENGINE==='sqlite','WordPress loaded only the isolated SQLite snapshot.');
membership_hpos_check(home_url()==='http://127.0.0.1:8771' && class_exists('WooCommerce') && function_exists('wuai_sluyu_membership_sync'),'Integrated local WooCommerce theme required.');
$synchronizer=wc_get_container()->get('Automattic\WooCommerce\Internal\DataStores\Orders\DataSynchronizer');
membership_hpos_check($synchronizer->create_database_tables(),'HPOS tables must be supported by this SQLite adapter.');
// Use WooCommerce's normal synchronizer and safety checks, never the dangerous
// allow-changing-storage-while-sync-pending filter or a direct options-table edit.
$deadline=time()+180;$last=[];
while($batch=$synchronizer->get_next_batch_to_process(100)){
    membership_hpos_check(time()<$deadline && $batch!==$last,'HPOS migration stalled or exceeded its bounded time; leave the copied site for diagnosis.');
    $last=$batch;$synchronizer->process_batch($batch);
}
membership_hpos_check(!$synchronizer->has_orders_pending_sync(),'All copied CPT orders are synchronized before storage changes.');
update_option('woocommerce_custom_orders_table_enabled','yes');
update_option('woocommerce_custom_orders_table_data_sync_enabled','no');
update_option('woocommerce_custom_orders_table_background_sync_mode','off');
update_option('woocommerce_hpos_datastore_caching_enabled','yes');
membership_hpos_check(Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled(),'HPOS is the real authoritative order store.');
membership_hpos_check(WC_Data_Store::load('order')->get_current_class_name()==='Automattic\WooCommerce\Internal\DataStores\Orders\OrdersTableDataStore','Fresh orders use the HPOS datastore, not CPT.');
echo "Isolated HPOS SQLite fixture ready: compatibility sync disabled; datastore caching enabled.\n";
require __DIR__.'/membership-state.php';
echo "PASS: membership CLI suite used authoritative HPOS tables in an independent SQLite snapshot. Shared CPT site was not changed. This is not a MySQL/production gateway/browser compatibility claim.\n";
