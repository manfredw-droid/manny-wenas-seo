<?php
/** Run only against a disposable equinox_ database: php native-editor-wordpress.php /path/wp-load.php */
require $argv[1];
if ( strpos( $wpdb->prefix, 'equinox_' ) !== 0 ) { throw new RuntimeException( 'Disposable equinox_ prefix required' ); }
wp_set_current_user( 1 );
$server = rest_get_server(); $checks = 0; $failures = 0; $ids = array(); $autosaves = array();
function check_native( $ok, $label ) {
	global $checks, $failures; ++$checks;
	if ( ! $ok ) { ++$failures; }
	echo ( $ok ? 'PASS ' : 'FAIL ' ) . $label . "\n";
}
function native_request( $route, $params = array(), $method = 'POST' ) {
	global $server;
	$r = new WP_REST_Request( $method, '/wp/v2/' . $route );
	if ( 'GET' === $method ) { $r->set_param( 'context', 'edit' ); }
	else { $r->set_header( 'Content-Type', 'application/json' ); $r->set_body( wp_json_encode( $params ) ); }
	return $server->dispatch( $r );
}
function editorial_meta( $fixture_id ) {
	return array_filter( get_post_meta( $fixture_id ), function ( $key ) { return strpos( $key, '_mwseo_' ) === 0 && $key !== '_mwseo_score'; }, ARRAY_FILTER_USE_KEY );
}
$seed = array( '_mwseo_focus'=>'garden', '_mwseo_related'=>array('light','seasons'), '_mwseo_title'=>'Garden title', '_mwseo_desc'=>'Garden description', '_mwseo_noindex'=>true, '_mwseo_nofollow'=>true, '_mwseo_cornerstone'=>true );
foreach ( array( 'post'=>'posts', 'page'=>'pages' ) as $type=>$base ) {
	$fixture_id = wp_insert_post( array( 'post_type'=>$type, 'post_title'=>'Equinox garden', 'post_content'=>'<!-- wp:paragraph --><p>Garden light and seasons.</p><!-- /wp:paragraph -->', 'post_status'=>'publish', 'post_author'=>1 ) ); $ids[]=$fixture_id;
	foreach ( $seed as $key=>$value ) { update_post_meta( $fixture_id, $key, $value ); }
	$before = editorial_meta( $fixture_id );
	$r = native_request( "$base/$fixture_id", array(), 'GET' ); $meta = $r->get_data()['meta'];
	check_native( $r->get_status()===200 && !array_diff_assoc(array_map('wp_json_encode',$seed),array_map('wp_json_encode',$meta)), "$type edit GET exposes all fields" );
	check_native( !array_key_exists('_mwseo_score',$meta), "$type edit context omits server-derived score" );
	check_native( editorial_meta( $fixture_id )===$before, "$type opening does not write" );
	$r = native_request( "$base/$fixture_id", array( 'title'=>'Equinox changed title' ) );
	check_native( $r->get_status()===200 && editorial_meta( $fixture_id )===$before, "$type title-only update preserves SEO" );
	$r = native_request( "$base/$fixture_id", array( 'meta'=>$seed ) );
	check_native( $r->get_status()===200 && editorial_meta( $fixture_id )===$before, "$type unchanged full meta save preserves stored values" );
	$r = native_request( "$base/$fixture_id", array( 'meta'=>array('_mwseo_focus'=>'new focus') ) );
	$after=editorial_meta($fixture_id); unset($after['_mwseo_focus']); $expected=$before; unset($expected['_mwseo_focus']);
	check_native( $r->get_status()===200 && $after===$expected, "$type absent fields are not cleared" );
	$empty=array('_mwseo_focus'=>'','_mwseo_related'=>array(),'_mwseo_title'=>'','_mwseo_desc'=>'','_mwseo_noindex'=>false,'_mwseo_nofollow'=>false,'_mwseo_cornerstone'=>false);
	$r=native_request("$base/$fixture_id",array('meta'=>$empty));
	$reopened=native_request("$base/$fixture_id",array(),'GET')->get_data()['meta'];
	check_native($r->get_status()===200 && !array_diff_assoc(array_map('wp_json_encode',$empty),array_map('wp_json_encode',$reopened)),"$type explicit clears and false survive reopen with correct types");
	update_post_meta($fixture_id,'_yoast_wpseo_focuskw','old fallback'); update_post_meta($fixture_id,'_yoast_wpseo_is_cornerstone','1');
	check_native(MWSEO_Meta::get($fixture_id,'focus')==='' && MWSEO_Meta::get($fixture_id,'cornerstone')===false,"$type explicit empty/false suppress legacy fallbacks");
	$r=native_request("$base/$fixture_id",array('meta'=>$seed));
	check_native($r->get_status()===200 && MWSEO_Meta::get($fixture_id,'focus')==='garden' && MWSEO_Meta::get($fixture_id,'cornerstone'),"$type refill after clear");
	$score=MWSEO_Rest::analyze_post(get_post($fixture_id));
	check_native((int)get_post_meta($fixture_id,'_mwseo_score',true)===$score['score'],"$type final stored score refreshed");
	$before=editorial_meta($fixture_id); $oldscore=get_post_meta($fixture_id,'_mwseo_score',true);
	$_POST=array('mwseo_nonce'=>wp_create_nonce('mwseo_save'),'mwseo_focus'=>'stale POST');
	wp_update_post(array('ID'=>$fixture_id,'post_title'=>'Simulated late metabox POST')); $_POST=array();
	check_native(editorial_meta($fixture_id)===$before && get_post_meta($fixture_id,'_mwseo_score',true)===$oldscore,"$type late legacy POST cannot overwrite REST metadata or score");
	$autosaves[]=array($type,$base,$fixture_id,$before,$oldscore);
}
// Legacy interoperability: effective fallbacks enter editor state but are not written on read.
$fixture_id=wp_insert_post(array('post_title'=>'Legacy fallback','post_status'=>'draft','post_author'=>1)); $ids[]=$fixture_id;
update_post_meta($fixture_id,'_yoast_wpseo_focuskw','legacy focus'); update_post_meta($fixture_id,'_yoast_wpseo_is_cornerstone','1');
$m=native_request("posts/$fixture_id",array(),'GET')->get_data()['meta'];
check_native($m['_mwseo_focus']==='legacy focus' && $m['_mwseo_cornerstone']===true && !metadata_exists('post',$fixture_id,'_mwseo_focus'),'effective legacy fallback loads without storage writes');
$m['_mwseo_desc']='new description'; $r=native_request("posts/$fixture_id",array('meta'=>$m));
check_native($r->get_status()===200 && MWSEO_Meta::get($fixture_id,'focus')==='legacy focus' && MWSEO_Meta::get($fixture_id,'cornerstone')===true,'full native meta save preserves untouched effective fallback fields');
$r=native_request("posts/$fixture_id",array('meta'=>array('_mwseo_score'=>55)));
check_native($r->get_status()===200 && (int)get_post_meta($fixture_id,'_mwseo_score',true)===55,'explicit API score operations remain available');
// Empty new post, hostile types, preflight and authorization.
$r=native_request('posts',array('title'=>'Empty Equinox','status'=>'draft')); $fixture_id=$r->get_data()['id']??0; if($fixture_id)$ids[]=$fixture_id;
$m=native_request("posts/$fixture_id",array(),'GET')->get_data()['meta'];
check_native($fixture_id && $m['_mwseo_related']===array() && $m['_mwseo_noindex']===false && $m['_mwseo_focus']==='', 'new draft REST defaults match native controls');
$before=editorial_meta($fixture_id); $r=native_request("posts/$fixture_id",array('meta'=>array('_mwseo_related'=>array(array('invalid'=>true)))));
check_native($r->get_status()===400 && editorial_meta($fixture_id)===$before,'invalid array type rejected');
update_post_meta($fixture_id,'_mwseo_noindex',false); update_post_meta($fixture_id,'_mwseo_focus','retain');
$before=editorial_meta($fixture_id); $r=native_request("posts/$fixture_id",array('title'=>'Must not persist','meta'=>array('_mwseo_focus'=>null,'_mwseo_noindex'=>null)));
check_native($r->get_status()===409 && editorial_meta($fixture_id)===$before,'unsafe null reset still rejected before mutation');
wp_set_current_user(0); $r=native_request("posts/$fixture_id",array('meta'=>array('_mwseo_focus'=>'forbidden')));
check_native($r->get_status()>=400 && editorial_meta($fixture_id)===$before,'unauthorized meta update refused');
wp_set_current_user(1);
check_native(!has_action('save_post',array('MWSEO_Metabox','save')) && !has_action('add_meta_boxes',array('MWSEO_Metabox','register')),'no legacy writer or metabox registration');
// Real screen gating, including an empty new post; no saved blocks are required.
require_once ABSPATH.'wp-admin/includes/admin.php';
set_current_screen('post'); $screen=get_current_screen(); $screen->post_type='post'; $screen->is_block_editor(true);
$GLOBALS['post']=get_post($fixture_id);
MWSEO_Companion::block_assets();
check_native(wp_script_is('mwseo-companion-block','enqueued') && wp_script_is('mwseo-companion-core','enqueued'),'native assets load on a block editor screen');
wp_dequeue_script('mwseo-companion-block');wp_dequeue_script('mwseo-companion-core');wp_dequeue_style('mwseo-companion');
$screen->is_block_editor(false); MWSEO_Companion::block_assets(); ob_start(); MWSEO_Metabox::editor_notice(); $notice=ob_get_clean();
check_native(strpos($notice,'requires the block editor')!==false && !wp_script_is('mwseo-companion-block','enqueued') && !wp_style_is('mwseo-companion','enqueued'),'Classic screen shows PHP notice without SEO editor assets');
set_current_screen('dashboard'); ob_start(); MWSEO_Metabox::editor_notice(); $notice=ob_get_clean(); MWSEO_Companion::block_assets();
check_native($notice==='' && !wp_script_is('mwseo-companion-core','enqueued'),'unrelated admin screen has no editor notice or editor assets');
foreach($autosaves as$fixture){ list($type,$base,$fixture_id,$before,$oldscore)=$fixture;
	$r=native_request("$base/$fixture_id/autosaves",array('content'=>'<!-- wp:paragraph --><p>Unsaved different content.</p><!-- /wp:paragraph -->','meta'=>array('_mwseo_focus'=>'unsaved recovery','_mwseo_noindex'=>false)));
	$data=$r->get_data(); $rev=$data['id']??0;
	check_native($r->get_status()<300 && ($data['parent']??0)===$fixture_id && editorial_meta($fixture_id)===$before && get_post_meta($fixture_id,'_mwseo_score',true)===$oldscore,"$type autosave leaves published parent SEO and score intact");
	check_native($rev && get_post_meta($rev,'_mwseo_focus',true)==='unsaved recovery' && in_array('_mwseo_focus',wp_post_revision_meta_keys($type),true),"$type autosave retains recoverable SEO metadata");
	if($rev) { wp_restore_post_revision($rev); check_native(MWSEO_Meta::get($fixture_id,'focus')==='unsaved recovery' && MWSEO_Meta::get($fixture_id,'noindex')===false,"$type WordPress revision restoration recovers SEO"); }
}
// Core may update an author's unlocked draft itself; never copy draft input to a published parent.
$draft_id=wp_insert_post(array('post_title'=>'Equinox draft autosave','post_status'=>'draft','post_author'=>1,'post_content'=>'Saved draft'));
$ids[]=$draft_id;update_post_meta($draft_id,'_mwseo_focus','saved draft focus');update_post_meta($draft_id,'_mwseo_score',42);
$r=native_request("posts/$draft_id/autosaves",array('content'=>'Unsaved draft content','meta'=>array('_mwseo_focus'=>'unsaved draft focus')));
check_native($r->get_status()<300 && (int)get_post_meta($draft_id,'_mwseo_score',true)===42,'draft autosave follows core semantics without refreshing saved score');
check_native(get_post_meta($draft_id,'_mwseo_focus',true)==='saved draft focus','core author-draft autosave retains last explicitly saved SEO (recovery limit documented)');
foreach($ids as$fixture_id)wp_delete_post($fixture_id,true);
echo "WordPress $wp_version / PHP ".PHP_VERSION.": $checks checks, $failures failures\n";
exit($failures?1:0);
