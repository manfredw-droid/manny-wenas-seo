<?php
/** Isolated failure/guard regressions. Not a WordPress runtime substitute.
 * php tests/rest-score-snapshot.php [source-root]
 */
define( 'ABSPATH', __DIR__ . '/' );
$state = array( 'result'=>array('score'=>31), 'stored'=>100, 'exists'=>true, 'calls'=>0, 'writes'=>0, 'allowed'=>true, 'revision'=>false, 'autosave'=>false, 'write_failure'=>false );
$hooks = array();
function add_action( $hook, $callback, $priority=10, $args=1 ) { global $hooks; $hooks[$hook]=array($callback,$args); }
function current_user_can() { global $state; return $state['allowed']; }
function wp_is_post_revision() { global $state; return $state['revision']; }
function wp_is_post_autosave() { global $state; return $state['autosave']; }
function get_post( $id ) { return (object)array('ID'=>$id,'post_type'=>'post','post_name'=>'garden','post_content'=>'body'); }
function apply_filters( $hook, $value ) { return $value; }
function get_post_meta() { global $state; return $state['stored']; }
function metadata_exists() { global $state; return $state['exists']; }
class WP_REST_Posts_Controller {}
class Other_Controller extends WP_REST_Posts_Controller {}
class WP_REST_Request {
    public $params; public $controller; public $method='POST'; public $defaults=array(); public $query=array(); public $body=array();
    function __construct($params) { $this->params=$params; $this->controller=new WP_REST_Posts_Controller(); }
    function get_attributes() { return array('callback'=>array($this->controller,'update_item')); }
    function get_method() { return $this->method; }
    function get_json_params() { return $this->params; }
    function get_body_params() { return $this->body; }
    function get_query_params() { return $this->query; }
    function get_param($key) { $p=array_merge($this->defaults,$this->query,$this->body,$this->params); return $p[$key]??null; }
}
class MWSEO_Options { static function get($key,$fallback) { return $fallback; } }
class MWSEO_Meta {
    static function get($id,$key) { return $key==='related'?array():''; }
    static function set($id,$key,$value) { global $state; ++$state['writes']; if(!$state['write_failure']){$state['stored']=$value;$state['exists']=true;} }
}
class MWSEO_Analyzer {
    static function analyze($input) { global $state; ++$state['calls']; if($state['result'] instanceof Throwable)throw $state['result']; return $state['result']; }
}
require ($argv[1]??dirname(__DIR__)).'/includes/class-mwseo-rest.php';
$checks=0;$failures=0;
function check($condition,$label) { global $checks,$failures; ++$checks; if(!$condition){++$failures;echo "FAIL $label\n";} }
if(!method_exists('MWSEO_Rest','refresh_score')) { echo "FAIL baseline lacks final REST refresh\n"; exit(1); }
function run_case($params,$changes=array(),$creating=false,$edit=null) {
    global $state;
    $state=array_merge(array('result'=>array('score'=>31),'stored'=>100,'exists'=>true,'calls'=>0,'writes'=>0,'allowed'=>true,'revision'=>false,'autosave'=>false,'write_failure'=>false),$changes);
    $request=new WP_REST_Request($params); if($edit)$edit($request);
    MWSEO_Rest::refresh_score(get_post(42),$request,$creating);
    return array($state,$request);
}
MWSEO_Rest::init(); check(isset($hooks['rest_api_init']),'hooks registered');
MWSEO_Rest::score_hooks(); check(isset($hooks['rest_after_insert_post'],$hooks['rest_after_insert_page']),'post/page final hooks registered');
list($s,$request)=run_case(array('content'=>'new')); check($s['calls']===1&&$s['stored']===31&&$s['writes']===1,'relevant update refreshes');
MWSEO_Rest::refresh_score(get_post(42),$request,false); check($state['calls']===1&&$state['writes']===1,'same request/post deduplicated');
foreach(array('title','slug') as $field){list($s)=run_case(array($field=>'new'));check($s['calls']===1,$field.' relevant');}
foreach(array('_mwseo_title','_mwseo_desc','_mwseo_focus','_mwseo_related') as $key){list($s)=run_case(array('meta'=>array($key=>'')));check($s['calls']===1,$key.' relevant, including empty');}
foreach(array(array('status'=>'draft'),array('excerpt'=>'new'),array('meta'=>array('_mwseo_noindex'=>true)),array()) as $params){list($s)=run_case($params);check($s['calls']===0&&$s['writes']===0,'irrelevant update skipped');}
list($s)=run_case(array(),array(),true); check($s['calls']===1,'creation gets initial snapshot');
foreach(array(55,0,null) as $score){list($s)=run_case(array('content'=>'new','meta'=>array('_mwseo_score'=>$score)));check($s['calls']===0&&$s['writes']===0,'explicit score/reset precedence');}
foreach(array('allowed'=>false,'revision'=>true,'autosave'=>true) as $key=>$value){list($s)=run_case(array('content'=>'new'),array($key=>$value));check($s['calls']===0&&$s['writes']===0,$key.' guard');}
list($s)=run_case(array('content'=>'new'),array(),false,function($r){$r->controller=new Other_Controller();});check($s['calls']===0,'subclass/custom controller excluded');
list($s)=run_case(array('content'=>'new'),array(),false,function($r){$r->method='GET';});check($s['calls']===0,'GET excluded');
foreach(array('PUT','PATCH')as$method){list($s)=run_case(array('content'=>'new'),array(),false,function($r)use($method){$r->method=$method;});check($s['calls']===1,$method.' update supported');}
list($s)=run_case(array(),array(),false,function($r){$r->defaults=array('content'=>'default','meta'=>array('_mwseo_focus'=>'default'));});check($s['calls']===0,'defaults not treated as supplied changes');
foreach(array('query','body') as $source){list($s)=run_case(array(),array(),false,function($r)use($source){$r->$source=array('content'=>'new');});check($s['calls']===1,$source.' supplied parameters honored');}
foreach(array(null,false,array(),array('score'=>null),array('score'=>'0'),array('score'=>-1),array('score'=>101),new RuntimeException('fixture')) as $result){list($s)=run_case(array('content'=>'new'),array('result'=>$result));check($s['writes']===0&&$s['stored']===100,'invalid result preserves snapshot');}
list($s)=run_case(array('content'=>''),array('result'=>array('score'=>0),'stored'=>'','exists'=>false));check($s['writes']===1&&$s['stored']===0,'valid zero is stored, not confused with absent');
list($s)=run_case(array('content'=>'new'),array('stored'=>'31'));check($s['calls']===1&&$s['writes']===0,'unchanged score avoids redundant write');
list($s)=run_case(array('content'=>'new'),array('write_failure'=>true));check($s['stored']===100&&$s['writes']===1,'failed write retains previous snapshot');
echo "$checks checks, $failures failures\n"; exit($failures?1:0);
