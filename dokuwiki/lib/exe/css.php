<?php
/**
 * DokuWiki StyleSheet creator
 *
 * @license    GPL 2 (http://www.gnu.org/licenses/gpl.html)
 * @author     Andreas Gohr <andi@splitbrain.org>
 */

if(!defined('DOKU_INC')) define('DOKU_INC',dirname(__FILE__).'/../../');
if(!defined('NOSESSION')) define('NOSESSION',true); // we do not use a session or authentication here (better caching)
if(!defined('DOKU_DISABLE_GZIP_OUTPUT')) define('DOKU_DISABLE_GZIP_OUTPUT',1); // we gzip ourself here
if(!defined('NL')) define('NL',"\n");
require_once(DOKU_INC.'inc/init.php');

// Main (don't run when UNIT test)
if(!defined('SIMPLE_TEST')){
    header('Content-Type: text/css; charset=utf-8');
    css_out();
}


// ---------------------- functions ------------------------------

/**
 * Output all needed Styles
 *
 * @author Andreas Gohr <andi@splitbrain.org>
 */
function css_out(){
    global $conf;
    global $lang;
    global $config_cascade;
    global $INPUT;

    if ($INPUT->str('s') == 'feed') {
        $mediatypes = array('feed');
        $type = 'feed';
    } else {
        $mediatypes = array('screen', 'all', 'print');
        $type = '';
    }

    // decide from where to get the template
    $tpl = trim(preg_replace('/[^\w-]+/','',$INPUT->str('t')));
    if(!$tpl) $tpl = $conf['template'];

    // The generated script depends on some dynamic options
    $cache = new cache('styles'.$_SERVER['HTTP_HOST'].$_SERVER['SERVER_PORT'].DOKU_BASE.$tpl.$type,'.css');

    // load styl.ini
    $styleini = css_styleini($tpl);

    // if old 'default' userstyle setting exists, make it 'screen' userstyle for backwards compatibility
    if (isset($config_cascade['userstyle']['default'])) {
        $config_cascade['userstyle']['screen'] = $config_cascade['userstyle']['default'];
    }

    // cache influencers
    $tplinc = tpl_basedir($tpl);
    $cache_files = getConfigFiles('main');
    $cache_files[] = $tplinc.'style.ini';
    $cache_files[] = $tplinc.'style.local.ini'; // @deprecated
    $cache_files[] = DOKU_CONF."tpl/$tpl/style.ini";
    $cache_files[] = __FILE__;

    // Array of needed files and their web locations, the latter ones
    // are needed to fix relative paths in the stylesheets
    $files = array();
    foreach($mediatypes as $mediatype) {
        $files[$mediatype] = array();
        // load core styles
        $files[$mediatype][DOKU_INC.'lib/styles/'.$mediatype.'.css'] = DOKU_BASE.'lib/styles/';
        // load jQuery-UI theme
        if ($mediatype == 'screen') {
            $files[$mediatype][DOKU_INC.'lib/scripts/jquery/jquery-ui-theme/smoothness.css'] = DOKU_BASE.'lib/scripts/jquery/jquery-ui-theme/';
        }
        // load plugin styles
        $files[$mediatype] = array_merge($files[$mediatype], css_pluginstyles($mediatype));
        // load template styles
        if (isset($styleini['stylesheets'][$mediatype])) {
            $files[$mediatype] = array_merge($files[$mediatype], $styleini['stylesheets'][$mediatype]);
        }
        // load user styles
        if(isset($config_cascade['userstyle'][$mediatype])){
            $files[$mediatype][$config_cascade['userstyle'][$mediatype]] = DOKU_BASE;
        }

        $cache_files = array_merge($cache_files, array_keys($files[$mediatype]));
    }

    // check cache age & handle conditional request
    // This may exit if a cache can be used
    http_cached($cache->cache,
                $cache->useCache(array('files' => $cache_files)));

    // start output buffering
    ob_start();

    // build the stylesheet
    foreach ($mediatypes as $mediatype) {

        // print the default classes for interwiki links and file downloads
        if ($mediatype == 'screen') {
            print '@media screen {';
            css_interwiki();
            css_filetypes();
            print '}';
        }

        // load files
        $css_content = '';
        foreach($files[$mediatype] as $file => $location){
            $display = str_replace(fullpath(DOKU_INC), '', fullpath($file));
            $css_content .= "\n/* XXXXXXXXX $display XXXXXXXXX */\n";
            $css_content .= css_loadfile($file, $location);
        }
        switch ($mediatype) {
            case 'screen':
                print NL.'@media screen { /* START screen styles */'.NL.$css_content.NL.'} /* /@media END screen styles */'.NL;
                break;
            case 'print':
                print NL.'@media print { /* START print styles */'.NL.$css_content.NL.'} /* /@media END print styles */'.NL;
                break;
            case 'all':
            case 'feed':
            default:
                print NL.'/* START rest styles */ '.NL.$css_content.NL.'/* END rest styles */'.NL;
                break;
        }
    }
    // end output buffering and get contents
    $css = ob_get_contents();
    ob_end_clean();

    // apply style replacements
    $css = css_applystyle($css, $styleini['replacements']);

    // parse less
    $css = css_parseless($css);

    // compress whitespace and comments
    if($conf['compress']){
        $css = css_compress($css);
    }

    // embed small images right into the stylesheet
    if($conf['cssdatauri']){
        $base = preg_quote(DOKU_BASE,'#');
        $css = preg_replace_callback('#(url\([ \'"]*)('.$base.')(.*?(?:\.(png|gif)))#i','css_datauri',$css);
    }

    http_cached_finish($cache->cache, $css);
}

/**
 * Uses phpless to parse LESS in our CSS
 *
 * most of this function is error handling to show a nice useful error when
 * LESS compilation fails
 *
 * @param $css
 * @return string
 */
function css_parseless($css) {
    $less = new lessc();
    $less->importDir[] = DOKU_INC;

    if (defined('DOKU_UNITTEST')){
        $less->importDir[] = TMP_DIR;
    }

    try {
        return $less->compile($css);
    } catch(Exception $e) {
        // get exception message
        $msg = str_replace(array("\n", "\r", "'"), array(), $e->getMessage());

        // try to use line number to find affected file
        if(preg_match('/line: (\d+)$/', $msg, $m)){
            $msg = substr($msg, 0, -1* strlen($m[0])); //remove useless linenumber
            $lno = $m[1];

            // walk upwards to last include
            $lines = explode("\n", $css);
            for($i=$lno-1; $i>=0; $i--){
                if(preg_match('/\/(\* XXXXXXXXX )(.*?)( XXXXXXXXX \*)\//', $lines[$i], $m)){
                    // we found it, add info to message
                    $msg .= ' in '.$m[2].' at line '.($lno-$i);
                    break;
                }
            }
        }

        // something went wrong
        $error = 'A fatal error occured during compilation of the CSS files. '.
            'If you recently installed a new plugin or template it '.
            'might be broken and you should try disabling it again. ['.$msg.']';

        echo ".dokuwiki:before {
            content: '$error';
            background-color: red;
            display: block;
            background-color: #fcc;
            border-color: #ebb;
            color: #000;
            padding: 0.5em;
        }";

        exit;
    }
}

/**
 * Does placeholder replacements in the style according to
 * the ones defined in a templates style.ini file
 *
 * This also adds the ini defined placeholders as less variables
 * (sans the surrounding __ and with a ini_ prefix)
 *
 * @author Andreas Gohr <andi@splitbrain.org>
 */
function css_applystyle($css, $replacements) {
    // we convert ini replacements to LESS variable names
    // and build a list of variable: value; pairs
    $less = '';
    foreach((array) $replacements as $key => $value) {
        $lkey = trim($key, '_');
        $lkey = '@ini_'.$lkey;
        $less .= "$lkey: $value;\n";

        $replacements[$key] = $lkey;
    }

    // we now replace all old ini replacements with LESS variables
    $css = strtr($css, $replacements);

    // now prepend the list of LESS variables as the very first thing
    $css = $less.$css;
    return $css;
}

/**
 * Load style ini contents
 *
 * Loads and merges style.ini files from template and config and prepares
 * the stylesheet modes
 *
 * @author Andreas Gohr <andi@splitbrain.org>
 * @param string $tpl the used template
 * @return array with keys 'stylesheets' and 'replacements'
 */
function css_styleini($tpl) {
    $stylesheets = array(); // mode, file => base
    $replacements = array(); // placeholder => value

    // load template's style.ini
    $incbase = tpl_incdir($tpl);
    $webbase = tpl_basedir($tpl);
    $ini = $incbase.'style.ini';
    if(file_exists($ini)){
        $data = parse_ini_file($ini, true);

        // stylesheets
        if(is_array($data['stylesheets'])) foreach($data['stylesheets'] as $file => $mode){
            $stylesheets[$mode][$incbase.$file] = $webbase;
        }

        // replacements
        if(is_array($data['replacements'])){
            $replacements = array_merge($replacements, css_fixreplacementurls($data['replacements'],$webbase));
        }
    }

    // load template's style.local.ini
    // @deprecated 2013-08-03
    $ini = $incbase.'style.local.ini';
    if(file_exists($ini)){
        $data = parse_ini_file($ini, true);

        // stylesheets
        if(is_array($data['stylesheets'])) foreach($data['stylesheets'] as $file => $mode){
            $stylesheets[$mode][$incbase.$file] = $webbase;
        }

        // replacements
        if(is_array($data['replacements'])){
            $replacements = array_merge($replacements, css_fixreplacementurls($data['replacements'],$webbase));
        }
    }

    // load configs's style.ini
    $webbase = DOKU_BASE;
    $ini = DOKU_CONF."tpl/$tpl/style.ini";
    $incbase = dirname($ini).'/';
    if(file_exists($ini)){
        $data = parse_ini_file($ini, true);

        // stylesheets
        if(is_array($data['stylesheets'])) foreach($data['stylesheets'] as $file => $mode){
            $stylesheets[$mode][$incbase.$file] = $webbase;
        }

        // replacements
        if(is_array($data['replacements'])){
            $replacements = array_merge($replacements, css_fixreplacementurls($data['replacements'],$webbase));
        }
    }

    return array(
        'stylesheets' => $stylesheets,
        'replacements' => $replacements
    );
}

function css_fixreplacementurls($replacements, $location) {
    foreach($replacements as $key => $value) {
        $replacements[$key] = preg_replace('#(url\([ \'"]*)(?!/|data:|http://|https://| |\'|")#','\\1'.$location,$value);
    }
    return $replacements;
}

/**
 * Prints classes for interwikilinks
 *
 * Interwiki links have two classes: 'interwiki' and 'iw_$name>' where
 * $name is the identifier given in the config. All Interwiki links get
 * an default style with a default icon. If a special icon is available
 * for an interwiki URL it is set in it's own class. Both classes can be
 * overwritten in the template or userstyles.
 *
 * @author Andreas Gohr <andi@splitbrain.org>
 */
function css_interwiki(){

    // default style
    echo 'a.interwiki {';
    echo ' background: transparent url('.DOKU_BASE.'lib/images/interwiki.png) 0px 1px no-repeat;';
    echo ' padding: 1px 0px 1px 16px;';
    echo '}';

    // additional styles when icon available
    $iwlinks = getInterwiki();
    foreach(array_keys($iwlinks) as $iw){
        $class = preg_replace('/[^_\-a-z0-9]+/i','_',$iw);
        if(@file_exists(DOKU_INC.'lib/images/interwiki/'.$iw.'.png')){
            echo "a.iw_$class {";
            echo '  background-image: url('.DOKU_BASE.'lib/images/interwiki/'.$iw.'.png)';
            echo '}';
        }elseif(@file_exists(DOKU_INC.'lib/images/interwiki/'.$iw.'.gif')){
            echo "a.iw_$class {";
            echo '  background-image: url('.DOKU_BASE.'lib/images/interwiki/'.$iw.'.gif)';
            echo '}';
        }
    }
}

/**
 * Prints classes for file download links
 *
 * @author Andreas Gohr <andi@splitbrain.org>
 */
function css_filetypes(){

    // default style
    echo '.mediafile {';
    echo ' background: transparent url('.DOKU_BASE.'lib/images/fileicons/file.png) 0px 1px no-repeat;';
    echo ' padding-left: 18px;';
    echo ' padding-bottom: 1px;';
    echo '}';

    // additional styles when icon available
    // scan directory for all icons
    $exts = array();
    if($dh = opendir(DOKU_INC.'lib/images/fileicons')){
        while(false !== ($file = readdir($dh))){
            if(preg_match('/([_\-a-z0-9]+(?:\.[_\-a-z0-9]+)*?)\.(png|gif)/i',$file,$match)){
                $ext = strtolower($match[1]);
                $type = '.'.strtolower($match[2]);
                if($ext!='file' && (!isset($exts[$ext]) || $type=='.png')){
                    $exts[$ext] = $type;
                }
            }
        }
        closedir($dh);
    }
    foreach($exts as $ext=>$type){
        $class = preg_replace('/[^_\-a-z0-9]+/','_',$ext);
        echo ".mf_$class {";
        echo '  background-image: url('.DOKU_BASE.'lib/images/fileicons/'.$ext.$type.')';
        echo '}';
    }
}

/**
 * Loads a given file and fixes relative URLs with the
 * given location prefix
 */
function css_loadfile($file,$location=''){
    $css_file = new DokuCssFile($file);
    return $css_file->load($location);
}

class DokuCssFile {

    protected $filepath;
    protected $location;
    private   $relative_path = null;

    public function __construct($file) {
        $this->filepath = $file;
    }

    public function load($location='') {
        if (!@file_exists($this->filepath)) return '';

        $css = io_readFile($this->filepath);
        if (!$location) return $css;

        $this->location = $location;

        $css = preg_replace_callback('#(url\( *)([\'"]?)(.*?)(\2)( *\))#',array($this,'replacements'),$css);
        $css = preg_replace_callback('#(@import\s+)([\'"])(.*?)(\2)#',array($this,'replacements'),$css);

        return $css;
    }

    private function getRelativePath(){

        if (is_null($this->relative_path)) {
            $basedir = array(DOKU_INC);
            if (defined('DOKU_UNITTEST')) {
                $basedir[] = realpath(TMP_DIR);
            }
            $regex = '#^('.join('|',$basedir).')#';

            $this->relative_path = preg_replace($regex, '', dirname($this->filepath));
        }

        return $this->relative_path;
    }

    public function replacements($match) {

        if (preg_match('#^(/|data:|https?://)#',$match[3])) {
            return $match[0];
        }
        else if (substr($match[3],-5) == '.less') {
            if ($match[3]{0} != '/') {
                $match[3] = $this->getRelativePath() . '/' . $match[3];
            }
        }
        else {
            $match[3] = $this->location . $match[3];
        }

        return join('',array_slice($match,1));
    }
}

/**
 * Convert local image URLs to data URLs if the filesize is small
 *
 * Callback for preg_replace_callback
 */
function css_datauri($match){
    global $conf;

    $pre   = unslash($match[1]);
    $base  = unslash($match[2]);
    $url   = unslash($match[3]);
    $ext   = unslash($match[4]);

    $local = DOKU_INC.$url;
    $size  = @filesize($local);
    if($size && $size < $conf['cssdatauri']){
        $data = base64_encode(file_get_contents($local));
    }
    if($data){
        $url = 'data:image/'.$ext.';base64,'.$data;
    }else{
        $url = $base.$url;
    }
    return $pre.$url;
}


/**
 * Returns a list of possible Plugin Styles (no existance check here)
 *
 * @author Andreas Gohr <andi@splitbrain.org>
 */
function css_pluginstyles($mediatype='screen'){
    global $lang;
    $list = array();
    $plugins = plugin_list();
    foreach ($plugins as $p){
        $list[DOKU_PLUGIN."$p/$mediatype.css"]  = DOKU_BASE."lib/plugins/$p/";
        $list[DOKU_PLUGIN."$p/$mediatype.less"]  = DOKU_BASE."lib/plugins/$p/";
        // alternative for screen.css
        if ($mediatype=='screen') {
            $list[DOKU_PLUGIN."$p/style.css"]  = DOKU_BASE."lib/plugins/$p/";
            $list[DOKU_PLUGIN."$p/style.less"]  = DOKU_BASE."lib/plugins/$p/";
        }
    }
    return $list;
}

/**
 * Very simple CSS optimizer
 *
 * @author Andreas Gohr <andi@splitbrain.org>
 */
function css_compress($css){
    //strip comments through a callback
    $css = preg_replace_callback('#(/\*)(.*?)(\*/)#s','css_comment_cb',$css);

    //strip (incorrect but common) one line comments
    $css = preg_replace('/(?<!:)\/\/.*$/m','',$css);

    // strip whitespaces
    $css = preg_replace('![\r\n\t ]+!',' ',$css);
    $css = preg_replace('/ ?([;,{}\/]) ?/','\\1',$css);
    $css = preg_replace('/ ?: /',':',$css);

    // number compression
    $css = preg_replace('/([: ])0+(\.\d+?)0*((?:pt|pc|in|mm|cm|em|ex|px)\b|%)(?=[^\{]*[;\}])/', '$1$2$3', $css); // "0.1em" to ".1em", "1.10em" to "1.1em"
    $css = preg_replace('/([: ])\.(0)+((?:pt|pc|in|mm|cm|em|ex|px)\b|%)(?=[^\{]*[;\}])/', '$1$2', $css); // ".0em" to "0"
    $css = preg_replace('/([: ]0)0*(\.0*)?((?:pt|pc|in|mm|cm|em|ex|px)(?=[^\{]*[;\}])\b|%)/', '$1', $css); // "0.0em" to "0"
    $css = preg_replace('/([: ]\d+)(\.0*)((?:pt|pc|in|mm|cm|em|ex|px)(?=[^\{]*[;\}])\b|%)/', '$1$3', $css); // "1.0em" to "1em"
    $css = preg_replace('/([: ])0+(\d+|\d*\.\d+)((?:pt|pc|in|mm|cm|em|ex|px)(?=[^\{]*[;\}])\b|%)/', '$1$2$3', $css); // "001em" to "1em"

    // shorten attributes (1em 1em 1em 1em -> 1em)
    $css = preg_replace('/(?<![\w\-])((?:margin|padding|border|border-(?:width|radius)):)([\w\.]+)( \2)+(?=[;\}]| !)/', '$1$2', $css); // "1em 1em 1em 1em" to "1em"
    $css = preg_replace('/(?<![\w\-])((?:margin|padding|border|border-(?:width)):)([\w\.]+) ([\w\.]+) \2 \3(?=[;\}]| !)/', '$1$2 $3', $css); // "1em 2em 1em 2em" to "1em 2em"

    // shorten colors
    $css = preg_replace("/#([0-9a-fA-F]{1})\\1([0-9a-fA-F]{1})\\2([0-9a-fA-F]{1})\\3(?=[^\{]*[;\}])/", "#\\1\\2\\3", $css);

    return $css;
}

/**
 * Callback for css_compress()
 *
 * Keeps short comments (< 5 chars) to maintain typical browser hacks
 *
 * @author Andreas Gohr <andi@splitbrain.org>
 */
function css_comment_cb($matches){
    if(strlen($matches[2]) > 4) return '';
    return $matches[0];
}

//Setup VIM: ex: et ts=4 :
