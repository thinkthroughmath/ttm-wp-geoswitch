<?php
if ( ! defined( 'ABSPATH' ) )
        die( 'This is just a Wordpress plugin.' );

if ( ! defined( 'GEOSWITCH_PLUGIN_DIR' ) )
    define( 'GEOSWITCH_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
require_once GEOSWITCH_PLUGIN_DIR . 'vendor/autoload.php';

class GeoSwitch {
    private static $initialized = false;
    private static $user_ip = null;
    private static $record = null;
    private static $data_source = null;
    private static $useKm = true;
    private static $cookie_name = 'geoswitch_locale';
    private static $state_cookie = null;

	public static function init() {
        if (self::$initialized) {
            return;
        }
        self::$initialized = true;

        self::$user_ip = self::get_user_ip();

        self::$state_cookie = self::get_state_cookie();

        if(is_null(self::$state_cookie)){
            try {
                $opt = get_option('geoswitch_options');
                $useKM = ($opt['units'] == 'km');
                self::$data_source = self::request_record($opt);
                self::$record = self::$data_source->city(self::$user_ip);
                $state_info = array();
                $state_info['name'] = self::get_state(null, null);
                $state_info['code'] = self::get_state_code(null, null);
                self::set_state_cookie($state_info);
            } catch (Exception $e) {
                self::$record = null;
            }
        }

        add_shortcode('geoswitch', array( 'GeoSwitch', 'switch_block' ));
        add_shortcode('geoswitch_case', array( 'GeoSwitch', 'switch_case' ));

        add_shortcode('geoswitch_state', array( 'GeoSwitch', 'get_state' ));
        add_shortcode('geoswitch_state_code', array( 'GeoSwitch', 'get_state_code' ));
    }

    public static function request_record($opts){
        $data_source = (is_null($opts['data_source'])) ? 'localdb' : $opts['data_source'];
        return ($data_source == 'webservice') ? self::build_client($opts) : self::build_reader($opts);
    }

    public static function build_client($opts){
        return new GeoIp2\WebService\Client($opts['service_user_name'], $opts['service_license_key']);
    }

    public static function build_reader($opts){
        $database = GEOSWITCH_PLUGIN_DIR . 'database/' . $opts['database_name'];
        return new GeoIp2\Database\Reader($database);
    }

	public static function switch_block($atts, $content) {
		$str = do_shortcode($content);
        $arr = explode('#', $str, 3);

        return count($arr) == 3
            ? substr($arr[2], 0, intval($arr[1]))
            : '';
    }

    public static function existing_state_cookie(){
        return isset($_COOKIE[self::$cookie_name]);
    }

    public static function get_state_cookie(){
        return self::existing_state_cookie() ? $_COOKIE[self::$cookie_name] : null;
    }

    public static function set_state_cookie($cookie_data){
	$host = bloginfo('url');    
	setcookie(self::$cookie_name."[code]", $cookie_data['code'], time() + (86400 * 3000), COOKIEPATH, $host); // 86400 = 1 day
        setcookie(self::$cookie_name."[name]", $cookie_data['name'], time() + (86400 * 3000), COOKIEPATH, $host); // 86400 = 1 day
    }

	public static function switch_case($atts, $content) {
        $expandedContent = do_shortcode($content);

        if (!self::existing_state_cookie() && is_null(self::$record)) {
            if (!empty($atts['state']) ||
                !empty($atts['state_code'])) {
                    return '';
            }
            return '#'.strlen($expandedContent).'#'.$expandedContent;
        }


        if ((empty($atts['state']) || strcasecmp($atts['state'], self::get_state($atts, $content)) == 0)
            &&
            (empty($atts['state_code']) || strcasecmp($atts['state_code'], self::get_state_code($atts, $content)) == 0)) {
            return '#'.strlen($expandedContent).'#'.$expandedContent;
        }
        return '';
    }

    public static function get_state($atts, $content) {
        if(self::existing_state_cookie()){
            $state_cookie = self::get_state_cookie();
            return $state_cookie['name'];
        }else{
            if (is_null(self::$record)) {
                return '?';
            }
            return self::$record->mostSpecificSubdivision->name;
        }
    }

    public static function get_state_code($atts, $content) {
        if(self::existing_state_cookie()){
            $state_cookie = self::get_state_cookie();
            return $state_cookie['code'];
        }else{
            if (is_null(self::$record)) {
                return '?';
            }
            return self::$record->mostSpecificSubdivision->isoCode;
        }
    }

    public static function activation() {
        $default_options=array(
            'database_name'=>'GeoLite2-City.mmdb',
            'units'=>'km'
         );
        add_option('geoswitch_options',$default_options);
    }

    public static function deactivation() {
        unregister_setting('geoswitch_options', 'geoswitch_options');
        delete_option('geoswitch_options');
    }

    private static function get_user_ip() {
        if ( ! empty( $_SERVER['HTTP_CLIENT_IP'] ) ) {
            //check ip from share internet
            $ip = $_SERVER['HTTP_CLIENT_IP'];
        } elseif ( ! empty( $_SERVER['HTTP_X_FORWARDED_FOR'] ) ) {
            //to check ip is pass from proxy
            $ip = $_SERVER['HTTP_X_FORWARDED_FOR'];
        } else {
            $ip = $_SERVER['REMOTE_ADDR'];
        }
        return $ip;
    }
}
