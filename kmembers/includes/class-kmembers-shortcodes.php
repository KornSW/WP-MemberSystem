<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class KMembers_Shortcodes {
    private $settings; private $access; private $auth; private static $embed_stack=array(); private static $embed_context_stack=array();
    public function __construct($settings,$access,$auth){$this->settings=$settings;$this->access=$access;$this->auth=$auth;add_shortcode('kMemberEmbed',array($this,'embed'));add_shortcode('kMemberLogonState',array($this,'state'));}
    private function page_id($value){if(is_numeric($value))return absint($value);$p=get_page_by_path(sanitize_title($value),OBJECT,'page');return $p?$p->ID:0;}
    public function embed($atts){
        $atts=shortcode_atts(array('site'=>'','fallback'=>'','flow_identifier'=>'','defer_onboarding'=>''),$atts,'kMemberEmbed');
        $id=$this->page_id($atts['site']);if(!$id||in_array($id,self::$embed_stack,true))return '';

        $host_id=get_queried_object_id();
        $host_url=$host_id?get_permalink($host_id):home_url('/');
        $flow_identifier=sanitize_text_field((string)$atts['flow_identifier']);
        if(''===$flow_identifier)$flow_identifier=$this->access->get_flow_identifier($id);

        $defer_raw=strtolower(trim((string)$atts['defer_onboarding']));
        if(''===$defer_raw){
            $defer_onboarding=$this->access->get_defer_onboarding($id);
        }else{
            $defer_onboarding=in_array($defer_raw,array('1','true','yes','on'),true);
        }

        if(!$this->access->user_can_access($id)){
            $fallback_raw=trim((string)$atts['fallback']);
            $configured_login_slug=sanitize_title((string)$this->settings->get('login_slug','member-login'));
            if(''!==$fallback_raw && sanitize_title($fallback_raw)===$configured_login_slug){
                return $this->auth->render_embedded_login_form($host_url,$flow_identifier,$host_url,$defer_onboarding);
            }
        }

        $chosen=$this->access->user_can_access($id)?$id:$this->page_id($atts['fallback']);if(!$chosen)return '';if(!$this->access->user_can_access($chosen))return '';
        $post=get_post($chosen);if(!$post||'publish'!==$post->post_status)return '';

        self::$embed_stack[]=$chosen;
        self::$embed_context_stack[]=array(
            'return_to'=>$host_url,
            'source_url'=>$host_url,
            'flow_identifier'=>$flow_identifier,
            'defer_onboarding'=>$defer_onboarding,
        );
        $content=apply_filters('the_content',$post->post_content);
        array_pop(self::$embed_context_stack);
        array_pop(self::$embed_stack);
        return '<div class="kmembers-embed">'.$content.'</div>';
    }

    private function logged_in_message( $template, $name ) {
        $parts = explode( '{user}', (string) $template, 2 );
        if ( 2 === count( $parts ) ) {
            return esc_html( $parts[0] ) . '<strong>' . esc_html( $name ) . '</strong>' . esc_html( $parts[1] );
        }
        return esc_html( $template ) . ' <strong>' . esc_html( $name ) . '</strong>';
    }

    public function state(){
        if(is_user_logged_in()){
            $u=wp_get_current_user();$name=$u->display_name?:$u->user_login;
            $message=$this->logged_in_message($this->settings->get('logged_in_text'),$name);
            $links=array(
                '<a href="'.esc_url(wp_logout_url(home_url('/'))).'">'.esc_html($this->settings->get('logout_label')).'</a>',
                '<a href="'.esc_url($this->auth->account_url()).'">'.esc_html($this->settings->get('account_label')).'</a>'
            );
            $member=absint($this->settings->get('member_page_id'));
            $current_id=get_queried_object_id();
            if($member&&$member!==$current_id&&$this->access->user_can_access($member))$links[]='<a href="'.esc_url(get_permalink($member)).'">'.esc_html($this->settings->get('member_label')).'</a>';
            return '<div class="kmembers-logon-state"><div class="kmembers-logon-state__message">'.$message.'</div><div class="kmembers-logon-state__links">'.implode('<span class="kmembers-logon-state__separator" aria-hidden="true">|</span>',$links).'</div></div>';
        }
        $ctx=!empty(self::$embed_context_stack)?end(self::$embed_context_stack):array();
        $return_to=$ctx['return_to']??'';
        $flow_identifier=$ctx['flow_identifier']??'';
        $source_url=$ctx['source_url']??'';
        $defer_onboarding=!empty($ctx['defer_onboarding']);
        if(''===$source_url){
            $current_id=get_queried_object_id();
            if($current_id)$source_url=get_permalink($current_id);
        }
        return '<div class="kmembers-logon-state"><div class="kmembers-logon-state__message">'.esc_html($this->settings->get('logged_out_text')).'</div><div class="kmembers-logon-state__links"><a href="'.esc_url($this->auth->login_url($return_to,$flow_identifier,$source_url,$defer_onboarding)).'">'.esc_html($this->settings->get('login_label')).'</a></div></div>';
    }
}
