<?php
/** Focused HTTP smoke against an already running disposable equinox_ WordPress site. */
require $argv[1];
if(strpos($wpdb->prefix,'equinox_')!==0)throw new RuntimeException('Disposable equinox_ prefix required');
wp_set_current_user(1); switch_theme('twentytwentyfive'); update_option('blog_public',1);
$failures=0; $checks=0;
function smoke($ok,$label){global $failures,$checks;++$checks;if(!$ok)++$failures;echo($ok?'PASS ':'FAIL ').$label."\n";}
function fetch_smoke($path){$r=wp_remote_get(home_url($path),array('timeout'=>20));if(is_wp_error($r))throw new RuntimeException($r->get_error_message());return wp_remote_retrieve_body($r);}
$fixture_id=wp_insert_post(array('post_title'=>'Equinox frontend fixture','post_content'=>'<!-- wp:paragraph --><p>Equinox garden content with a <a href="/">home link</a>.</p><!-- /wp:paragraph -->','post_status'=>'publish','post_author'=>1));
foreach(array('title'=>'Equinox frontend SEO','desc'=>'Equinox explicit frontend description.','cornerstone'=>'1')as$key=>$value)update_post_meta($fixture_id,'_mwseo_'.$key,$value);
$url=get_permalink($fixture_id); $html=fetch_smoke('/?p='.$fixture_id);
smoke(strpos($html,'<title>Equinox frontend SEO</title>')!==false,'title works without editor visit');
smoke((bool)preg_match('/<meta name="description" content="Equinox explicit frontend description\\."/',$html),'description works without editor visit');
smoke(strpos($html,'rel="canonical" href="'.esc_url($url).'"')!==false,'canonical is preserved');
smoke(strpos($html,'application/ld+json')!==false && strpos($html,'"@graph"')!==false && strpos($html,'"Article"')!==false,'connected schema graph remains present');
$sitemap=fetch_smoke('/?mwseo_sitemap=post'); $llms=fetch_smoke('/?mwseo_llms=1');
smoke(strpos($sitemap,'<urlset')!==false && strpos($sitemap,esc_xml($url))!==false,'sitemap includes indexable fixture');
smoke(strpos($llms,$url)!==false,'llms.txt includes indexable anchor fixture');
update_post_meta($fixture_id,'_mwseo_noindex',true);update_post_meta($fixture_id,'_mwseo_nofollow',true);delete_transient('mwseo_sitemap_cache');
$html=fetch_smoke('/?p='.$fixture_id);
smoke((bool)preg_match('/<meta name=[\'"]robots[\'"][^>]*noindex[^>]*nofollow/',$html),'robots follows stored noindex/nofollow');
smoke(strpos(fetch_smoke('/?mwseo_sitemap=post'),esc_xml($url))===false,'noindex is excluded from sitemap');
smoke(strpos(fetch_smoke('/?mwseo_llms=1'),$url)===false,'noindex anchor excluded from llms.txt');
wp_delete_post($fixture_id,true);
echo "$checks frontend HTTP checks, $failures failures\n";exit($failures?1:0);
