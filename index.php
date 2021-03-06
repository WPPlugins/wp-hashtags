<?php

/*
Plugin Name: WordPress Hashtags
Description: WordPress Hashtags allows you to automatically fetch your blog content and detect hashtags and set them as links
Author: Samuel Elh
Version: 0.3.2
Author URI: http://samelh.com
*/

// Prevent direct access
defined('ABSPATH') || exit;

class WPHT
{

	protected static $instance = null;

	public static function instance() {
		return null == self::$instance ? new self : self::$instance;
	}

	public $settings;
	public $notice;

	function __construct() {

		$this->settings = array();
		$this->settings['filtered'] = strlen( get_option('wpht_filter') ) > 1 ? get_option('wpht_filter') : 'pc,pt,wc,wt,bbp,bp,ct';
		$this->settings['filter'] = explode( ',', $this->settings['filtered'] );
		$this->settings['def_path'] = 'https://twitter.com/hashtag/[hashtag]/';
		$this->settings['path'] = strlen( get_option('wpht_path') ) > 1 ? get_option('wpht_path') : $this->settings['def_path'];
		$this->settings['ignored'] = get_option('wpht_ignored') ? explode(',', get_option('wpht_ignored')) : array();
		$this->settings['link_settings'] = get_option('wpht_lsettings') ? get_option('wpht_lsettings') : "{'_blank': '', 'nofollow': '', 'title' : '[hashtag]', 'class': '', 'css': '' }";

		$this->settings['link_settings'] = stripslashes( $this->settings['link_settings'] );
		$this->settings['link_settings'] = str_replace( array( "'", addslashes("'") ), '"', $this->settings['link_settings'] );
		$this->settings['link_settings'] = stripslashes( $this->settings['link_settings'] );
		$this->settings['link_settings_json'] = json_decode( $this->settings['link_settings'], true );

		$ob = (object) $this->settings['link_settings_json'];
		$ob->title = ! empty( $ob->title ) ? (string) $ob->title : '[hashtag]';
		$ob->css = ! empty( $ob->css ) ? (string) $ob->css : '';
		$ob->class = ! empty( $ob->class ) ? (string) $ob->class : '';
		$ob->nofollow = ! empty( $ob->nofollow ) ? (string) $ob->nofollow : '';
		$ob->_blank = ! empty( $ob->_blank ) ? (string) $ob->_blank : '';

		$this->settings['link_settings_json'] = (array) $ob;

		$this->settings['link_settings_json']['title'] = str_replace( '{apos}', "'", $this->settings['link_settings_json']['title']);
		$this->settings['link_settings_json']['title'] = '' !== $this->settings['link_settings_json']['title'] ? $this->settings['link_settings_json']['title'] : '[hashtag]';


	}

	public function init() {

		if( in_array( 'pc', $this->settings['filter'] ) )
			add_filter('the_content', array( &$this, 'filter'));

		if( in_array( 'pt', $this->settings['filter'] ) )
			add_filter('the_title', array( &$this, 'filter'));

		if( in_array( 'wc', $this->settings['filter'] ) )
			add_filter('widget_text', array( &$this, 'filter'));

		if( in_array( 'wt', $this->settings['filter'] ) )
			add_filter('widget_title', array( &$this, 'filter'));

		if( in_array( 'bbp', $this->settings['filter'] ) ) {
			add_filter('bbp_get_topic_content', array( &$this, 'filter'));
			add_filter('bbp_get_reply_content', array( &$this, 'filter'));
		}
	
		if( in_array( 'ct', $this->settings['filter'] ) )
			add_filter('comment_text', array( &$this, 'filter'));

		if( in_array( 'bp', $this->settings['filter'] ) )
			add_filter('bp_get_activity_content_body', array( &$this, 'filter'));

		add_action( 'admin_menu', array( &$this, 'wpht_add_options_page'));
		add_filter( "plugin_action_links_".plugin_basename(__FILE__), array( &$this, 'wpht_push_settings_link'));
		add_action('admin_enqueue_scripts', array( &$this, 'wpht_admin_enqueue_scripts'));
		add_shortcode('wp-hashtag', array( &$this, 'wpht_shortcode'));

	}

	public function wpht_add_options_page() {
		add_options_page( 'WordPress Hashtags', 'WP Hashtags', 'manage_options', 'wordpress-hashtags', array( &$this, 'wpht_settings' ) );
	}

	public function wpht_push_settings_link($links) {
	    array_push( $links, '<a href="options-general.php?page=wordpress-hashtags">' . __( 'Settings' ) . '</a>' );
	  	return $links;
	}

	public function wpht_shortcode( $atts, $content = null ) {
		return do_shortcode( $this->filter( $content ) );
	}

	public function wpht_admin_enqueue_scripts() {
		if( isset( $_GET['page'] ) && $_GET['page'] == 'wordpress-hashtags' ) {
			wp_enqueue_script('wpht-admin-js', plugin_dir_url(__FILE__) . 'assets/admin.js' );
			wp_enqueue_style('wpht-admin-css', plugin_dir_url(__FILE__) . 'assets/admin.css' );
		}
	}

	public function filter( $content ) {

		if( is_feed() )
			return $content;

		$original_content = $content;

		$content = mb_convert_encoding( (string) $content, 'UTF-8', 'UTF-8');
		$content = html_entity_decode($content, ENT_QUOTES);
		$content = apply_filters( 'wpht_pre_filter_content', $content, $original_content );

		preg_match_all( apply_filters( "wpht_regex_pattern", '/#(\w+)/u' ), strip_tags($content), $hashtags );

		$hashtags = array_unique( array_filter( $hashtags[1] ) );

		if( !empty( $this->settings['ignored'] ) ) :

			foreach( $this->settings['ignored'] as $ignored ) :;

				foreach( $hashtags as $key => $hashtag )
					if( $hashtag == $ignored ) unset( $hashtags[$key] );

			endforeach;

		endif;

		$parsedHashtags = array();

		$href 	= $this->settings['path'];
		$title 	= $this->settings['link_settings_json']['title'];
		
		$atts 	= ' title="' . $title . '"';
		
		$class 	= $this->settings['link_settings_json']['class'];
		$atts 	.= $class !== '' ? ' class="'. $class .'"' : '';

		$style 	= $this->settings['link_settings_json']['css'];
		$atts 	.= $style !== '' ? ' style="'. $style .'"' : '';

		$blank 	= $this->settings['link_settings_json']['_blank'];
		$atts 	.= $blank == '1' ? ' target="_blank"' : '';

		$nofollow 	= $this->settings['link_settings_json']['nofollow'];
		$atts 		.= $nofollow == '1' ? ' rel="nofollow"' : '';

		$link = '<a href="' . $href . '"' . $atts . '>#[hashtag]</a>';

		foreach( $hashtags as $hashtag ) :;

			$parsedHashtags[] = str_replace( '[hashtag]', $hashtag, $link );

		endforeach;

		$tarHashtags = array();

		foreach( $hashtags as $hashtag ) :;

			$tarHashtags[] = '#' . $hashtag;

		endforeach; 

		$content = str_replace( $tarHashtags, $parsedHashtags, $content );

		return apply_filters( 'wpht_filter_content', $content, $original_content );

	}

	function wpht_settings() {

		if( isset( $_POST['submit'] ) ) {

			$filtered 	= isset( $_POST['wpht_filtered'] ) ? $_POST['wpht_filtered'] : $this->settings['filtered'];
			$filtered 	= array_filter( array_unique( explode( ',', $filtered ) ) );
			$filtered 	= empty( $filtered ) ? array('none') : $filtered;
			$filtered 	= implode( ',', $filtered );

			$ignored 	= isset( $_POST['wpht_ignored'] ) ? $_POST['wpht_ignored'] : false;
			$ignored 	= array_filter( array_unique( explode( ',', $ignored ) ) );
			$ignored 	= empty( $ignored ) ? false : $ignored;
			$ignored 	= $ignored ? implode( ',', $ignored ) : false;

			update_option( 'wpht_filter', $filtered );

			if( isset( $_POST['wpht_path'] ) && $_POST['wpht_path'] !== $this->settings['def_path'] )
				update_option( 'wpht_path', $_POST['wpht_path'] );
			else
				delete_option( 'wpht_path' );

			if( $ignored )
				update_option( 'wpht_ignored', $ignored );
			else
				delete_option( 'wpht_ignored' );

			if( isset( $_POST['wpht_lsettings'] ) )
				update_option('wpht_lsettings', $_POST['wpht_lsettings'] ); // $_POST['wpht_lsettings'] for a hidden field of a JSON object constructed and validated with JS
			
			$this->notice = '<div id="updated" class="updated notice is-dismissible"><p>Changes saved successfully.</p></div>';

			$this::__construct();

		}

		?>

			<div class="wrap">

				<div class="wpht_left">

					<?php echo isset( $this->notice ) ? $this->notice : ''; ?>

					<h2>WordPress Hashtags &raquo; Settings</h2>

					<form method="post" onchange="wpht_change()">

						<table class="wpht">
							
							<tr>
								<td valign="top"><h3>Filter</h3></td>
								<td>

									<label><input type="checkbox" class="wpht_filters" id="pc" <?php echo in_array( 'pc', $this->settings['filter'] ) ? 'checked="checked"' : ''; ?>/>Posts/pages content</label><br/>

									<label><input type="checkbox" class="wpht_filters" id="pt" <?php echo in_array( 'pt', $this->settings['filter'] ) ? 'checked="checked"' : ''; ?> />Posts/pages title</label><br/>

									<label><input type="checkbox" class="wpht_filters" id="ct" <?php echo in_array( 'ct', $this->settings['filter'] ) ? 'checked="checked"' : ''; ?> />Comments text</label><br/>

									<label><input type="checkbox" class="wpht_filters" id="wc" <?php echo in_array( 'wc', $this->settings['filter'] ) ? 'checked="checked"' : ''; ?> />Widgets content</label><br/>

									<label><input type="checkbox" class="wpht_filters" id="wt" <?php echo in_array( 'wt', $this->settings['filter'] ) ? 'checked="checked"' : ''; ?> />Widgets title</label><br/>

									<?php if( function_exists('bbpress') ) : ?>
										<label><input type="checkbox" class="wpht_filters" id="bbp" <?php echo in_array( 'bbp', $this->settings['filter'] ) ? 'checked="checked"' : ''; ?> />bbPress topic and replies</label><br/>
									<?php endif; ?>

									<?php if( function_exists('bbpress') ) : ?>
										<label><input type="checkbox" class="wpht_filters" id="bp" <?php echo in_array( 'bp', $this->settings['filter'] ) ? 'checked="checked"' : ''; ?> />BuddyPress activity</label>
									<?php endif; ?>

								</td>
							</tr>
							<tr>
								<td><h3>Hashtag URL</h3></td>
								<td>
									<input type="text" value="<?php echo $this->settings['path']; ?>" name="wpht_path" size="50" />
									<i style="display:block">Use <code>[hashtag]</code> for the hashtag name</i>
								</td>
							</tr>

							<tr>
								<td><h3>Ignore hashtags</h3></td>
								<td>
									<div id="ignored-hashtags"></div>
						        	<span class="add-hashtag" onclick="wpht_addHT()" title="Add hashtag">+</span>
									<textarea id="ignore-hashtags" placeholder="add hashtags separated by commas" name="wpht_ignored"><?php echo implode(',', $this->settings['ignored']), !empty($this->settings['ignored']) ? ',' : ''; ?></textarea>
								</td>
							</tr>

							<tr>
								<td valign="top"><h3>Link settings</h3></td>
								<td>

									<label>
										<strong>Title:</strong><i> Use <code>[hashtag]</code> for the hashtag name</i><br/>
										<input type="text" id="_link-title" size="50" value="<?php echo $this->settings['link_settings_json']['title']; ?>" />
									</label><br/>
									<label>
										<strong>CSS class(s):</strong><br/>
										<input type="text" size="50" id="_link-class" value="<?php echo $this->settings['link_settings_json']['class']; ?>" />
									</label><br/>
									<label>
										<strong>Inline CSS style:</strong><br/>
										<input type="text" size="50" id="_link-css" value="<?php echo $this->settings['link_settings_json']['css']; ?>" />
									</label><br/>
									<label><input type="checkbox" id="_new-tab" <?php echo $this->settings['link_settings_json']['_blank'] == '1' ? 'checked="checked"' : ''; ?>/>Open in new tab</label><br/>
									<label><input type="checkbox" id="_no-follow" <?php echo $this->settings['link_settings_json']['nofollow'] == '1' ? 'checked="checked"' : ''; ?>/>Enable <code>rel=nofollow</code></label><br/>
								</td>
							</tr>

							<tr>
								<td><h3>Preview</h3></td>
								<td><h2><?php echo do_shortcode('[wp-hashtag]#WordPress[/wp-hashtag]'); ?></h2></td>
							</tr>

							<tr>
								<td>
									<input type="hidden" value="<?php echo $this->settings['filtered']; ?>" id="wpht_filtered" name="wpht_filtered" />
									<input type="hidden" value="<?php echo str_replace('"','\'',$this->settings['link_settings']); ?>" id="wpht_lsettings" name="wpht_lsettings" />
									<?php submit_button(); ?>
								</td>
							</tr>

						</table>

					</form>

					<h3>Using shortcodes</h3>

					<p>
					If there is any place we can not fetch its content and parse hashtags, you can simply use the shortcode <code>[wp-hashtag]</code>:
					<li>Plain text: <code>[wp-hashtag]#hashtag[/wp-hashtag]</code></li>
					<li>PHP template, etc: <code>&lt;?php echo do_shortcode('[wp-hashtag]#hashtag[/wp-hashtag]'); ?&gt;</code></li>
					</p>

				</div>
				<div class="wpht_right">

					<h3>Check out more of our premium plugins</h3>
					<?php if( function_exists('bbpress')) : ?>
						<li><a target="_blank" href="http://go.samelh.com/get/bbpress-ultimate/">bbPress Ultimate</a> adds more features to your forums and bbPress/BuddyPress profiles..</li>
						<li><a target="_blank" href="http://go.samelh.com/get/bbpress-thread-prefixes/">bbPress Thread Prefixes</a> enables thread prefixes in your blog, just like any other forum board!</li>
					<?php endif; ?>
					<li><a target="_blank" href="http://go.samelh.com/get/youtube-information/">YouTube Information</a>: easily embed YouTube video/channel info and stats, video cards, channel cards, widgets, shortcodes..</li>
					<li><a target="_blank" href="http://go.samelh.com/get/wpchats/">WpChats</a> bringing instant live chat &amp; private messaging feature to your site..</li>
					<p>View more of our <a target="_blank" href="https://profiles.wordpress.org/elhardoum#content-plugins">free</a> and <a target="_blank" href="http://codecanyon.net/user/samiel/portfolio?ref=samiel">premium</a> plugins.</p>
					<p><hr/></p>

					<h3>Subscribe, Join our mailing list</h3>
					<p><i>Join our mailing list today for more WordPress tips and tricks and awesome free and premium plugins</i><p>
					<form action="//samelh.us12.list-manage.com/subscribe/post?u=677d27f6f70087b832c7d6b67&amp;id=7b65601974" method="post" name="mc-embedded-subscribe-form" class="validate" target="_blank" novalidate="">
						<label><strong>Email:</strong><br/>
							<input type="email" value="<?php echo wp_get_current_user()->email; ?>" name="EMAIL" class="required email" id="mce-EMAIL" />
						</label>
						<br/>
						<label><strong>Your name:</strong><br/>
							<input type="text" value="<?php echo wp_get_current_user()->user_nicename; ?>" name="FNAME" class="" id="mce-FNAME" />
						</label>
						<br/>
					    <input type="submit" value="Subscribe" name="subscribe" id="mc-embedded-subscribe" class="button" />
					</form>
					<p><hr/></p>

					<h3>Are you looking for help?</h3>
					<p>Don't worry, we got you covered:</p>
					<li><a href="http://wordpress.org/support/plugin/wp-hashtags">Go to plugin support forum on WordPress</a></li>
					<li><a href="http://support.samelh.com/">Try our Support forum</a></li>
					<li><a href="http://blog.samelh.com/">Browse our blog for tutorials</a></li>
					<p><hr/></p>

					<p>
						<li><a href="https://wordpress.org/support/view/plugin-reviews/wp-hashtags?rate=5#postform">Give us &#9733;&#9733;&#9733;&#9733;&#9733; rating</a></li>
						<li><a href="http://twitter.com/samuel_elh">Follow @Samuel_Elh on Twitter</a></li>
					</p>

					<p>Thank you! :)</p>

				</div>

			</div>

		<?php

	}

}

// Load it!
WPHT::instance()->init();