<?php
if(PHP_SAPI!=='cli')exit(1);
$root=getenv('WP_QA_ROOT');if(!$root || !is_file($root.'/wp-load.php'))throw new RuntimeException('WP_QA_ROOT required.');
$_SERVER['HTTP_HOST']='127.0.0.1:8771';$_SERVER['REQUEST_URI']='/';require $root.'/wp-load.php';
if(home_url()!=='http://127.0.0.1:8771' || !class_exists('WooCommerce'))throw new RuntimeException('Local WooCommerce only.');
$output=getenv('WP_QA_OUTPUT')?:dirname($root);if(!is_dir($output))wp_mkdir_p($output);$path=rtrim($output,'/\\').'/widgets-fixture.json';
if(in_array('--restore',$argv,true)){
    if(!is_file($path))throw new RuntimeException('Missing widget restore manifest.');$fixture=json_decode(file_get_contents($path),true);
    if(($fixture['base']??'')!==home_url())throw new RuntimeException('Wrong restore site.');
    foreach($fixture['options']as$key=>$value){if($value===null)delete_option($key);else update_option($key,$value);}
    if(!empty($fixture['products']))foreach($fixture['products']as$id){$product=wc_get_product($id);if($product && strpos($product->get_name(),'QA WIDGET ')===0)$product->delete(true);}
    foreach(array_merge($fixture['posts'],[$fixture['coupon']])as$id){if(strpos(get_the_title($id),'QA WIDGET ')===0 || $id===$fixture['coupon'])wp_delete_post($id,true);}
    $fixture['restored']=true;file_put_contents($path,wp_json_encode($fixture,JSON_PRETTY_PRINT));echo "PASS: restored widget/sidebar/site options and removed dedicated browser content.\n";exit;
}
if(is_file($path)){$previous=json_decode(file_get_contents($path),true);if(empty($previous['restored']))throw new RuntimeException('Restore previous widget fixture before reseeding.');}
$backup=[];foreach(['sidebars_widgets','widget_wuai_sluyu_service','wuai_sluyu_options']as$key)$backup[$key]=get_option($key,null);
$users=[];$posts=[];$products=[];$_POST=[];
foreach(['owner','reader']as$name){$login='qa_widget_browser_'.$name;$user=get_user_by('login',$login);$uid=$user?$user->ID:wp_create_user($login,'qa-local-only',$login.'@example.test');if(is_wp_error($uid))throw new RuntimeException('Cannot create fixture user.');wp_update_user(['ID'=>$uid,'user_pass'=>'qa-local-only','display_name'=>'Widget Browser '.ucfirst($name)]);(new WP_User($uid))->set_role('subscriber');$users[$name]=(int)$uid;foreach(['_wuai_banned_until','_wuai_membership_state','_wuai_membership_sync','_wuai_membership_grants','_wuai_member_until']as$key)delete_user_meta($uid,$key);$wpdb->delete(wuai_sluyu_table('events'),['user_id'=>$uid]);}
wuai_sluyu_event($users['reader'],'widget-browser-balance','qa',123);update_user_meta($users['reader'],'_wuai_member_until',time()+DAY_IN_SECONDS);
$options=wuai_sluyu_get_options();$options['enable_loader']='0';$options['show_resource_box']='1';$options['article_show_toc']='1';
$coupon=new WC_Coupon();$coupon->set_code('qa-widget-browser-public');$coupon->set_discount_type('percent');$coupon->set_amount(15);$coupon->save();$options['public_coupon_codes']=$coupon->get_code();update_option('wuai_sluyu_options',$options);
$product=new WC_Product_Simple();$product->set_name('QA WIDGET visible product');$product->set_status('publish');$product->set_regular_price('12');$product->save();$products[]=$product->get_id();
foreach(['member','password','page','empty']as$key){$id=wp_insert_post(['post_type'=>$key==='page'?'page':'post','post_status'=>'publish','post_author'=>$users['owner'],'post_title'=>'QA WIDGET '.$key,'post_password'=>$key==='password'?'locked':'','post_content'=>$key==='empty'?'<p>No section headings here.</p>':'<h2>Widget section one</h2><p>Harmless public content.</p><h3>Widget subsection two</h3><p>Second paragraph.</p>']);$posts[$key]=(int)$id;if($key!=='empty')update_post_meta($id,'_wuai_download_settings',['url'=>'https://example.test/widget-browser-private.zip','password'=>'widget-browser-code','access'=>'member','cost'=>0,'version'=>wp_generate_uuid4()]);}
$hidden=wuai_sluyu_protected_validate(['protected_body'=>'<h2>Private widget heading</h2><p>widget-protected-body</p>','access'=>'member','cost'=>0]);update_post_meta($posts['member'],wuai_sluyu_protected_key(),$hidden);
$widgets=get_option('widget_wuai_sluyu_service',[]);$sidebar=[];$footer=[];$i=801;
foreach(['user','toc','download','products','coupons','about','listings']as$mode){if(isset($widgets[$i]))throw new RuntimeException('QA widget slot already occupied.');$widgets[$i]=['mode'=>$mode,'count'=>3,'title'=>'QA WIDGET '.$mode];$sidebar[]='wuai_sluyu_service-'.$i;$i++;}
foreach(['toc','download']as$mode){if(isset($widgets[$i]))throw new RuntimeException('QA footer widget slot already occupied.');$widgets[$i]=['mode'=>$mode,'count'=>3,'title'=>'QA WIDGET footer '.$mode];$footer[]='wuai_sluyu_service-'.$i;$i++;}
$bars=get_option('sidebars_widgets',[]);$bars['sluyu-sidebar']=$sidebar;$bars['sluyu-footer']=$footer;update_option('widget_wuai_sluyu_service',$widgets);update_option('sidebars_widgets',$bars);
$fixture=['base'=>home_url(),'options'=>$backup,'users'=>$users,'posts'=>$posts,'products'=>$products,'coupon'=>$coupon->get_id(),'restored'=>false];file_put_contents($path,wp_json_encode($fixture,JSON_PRETTY_PRINT));
echo "PASS: dedicated widget fixture configured; restore with --restore after browser tests.\n";
