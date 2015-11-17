<?php
/*
Plugin Name: Simple Facebook Instant Articles
Version: 0.5.0
Description: Add support to Facebook Instant Articles
Author: Jake Spurlock, Human Made Limited
*/

require_once( 'includes/functions.php' );

class Simple_FB_Instant_Articles {

	/**
	 * The one instance of Simple_FB_Instant_Articles.
	 *
	 * @var Simple_FB_Instant_Articles
	 */
	private static $instance;

	/**
	 * Endpoint query var.
	 */
	private $token = 'fb';

	/**
	 * Endpoint query var.
	 */
	private $endpoint = 'fb-instant';


	/**
	 * Image Size - 2048x2048 recommended resolution.
	 * @see https://developers.facebook.com/docs/instant-articles/reference/image
	 */
	public $image_size = array( 2048, 2048 );

	/**
	 * Instantiate or return the one Simple_FB_Instant_Articles instance.
	 *
	 * @return Simple_FB_Instant_Articles
	 */
	public static function instance( $file = null, $version = '' ) {
		if ( is_null( self::$instance ) ) {
			self::$instance = new self( $file, $version );
		}

		return self::$instance;
	}

	/**
	 * Template Path.
	 */
	private $template_path;

	/**
	 * Initiate actions.
	 *
	 * @return Simple_FB_Instant_Articles
	 */
	public function __construct( $file, $version ) {

		add_action( 'init', array( $this, 'init' ) );
		add_action( 'init', array( $this, 'add_feed' ) );
		add_action( 'wp', array( $this, 'add_actions' ) );

		// Render post content into FB IA format.
		add_action( 'simple_fb_pre_render', array( $this, 'setup_content_mods' ) );
		add_action( 'simple_fb_before_feed', array( $this, 'setup_content_mods' ) );

		// Setup the props.
		$this->version       = $version;
		$this->dir           = dirname( $file );
		$this->file          = $file;
		$this->template_path = trailingslashit( $this->dir ) . 'templates/';
		$this->home_url      = trailingslashit( home_url() );
	}

	/**
	 * Kickoff method.
	 *
	 * @return void
	 */
	public function init() {
		add_rewrite_endpoint( $this->endpoint, EP_PERMALINK );
	}

	/**
	 * Add the template redirect.
	 */
	public function add_actions() {
		if ( ! is_singular() ) {
			return;
		}

		if ( false !== get_query_var( $this->endpoint, false ) ) {
			add_action( 'template_redirect', array( $this, 'template_redirect' ) );
		}
	}

	/**
	 * Redirect the template for the Instant Article post.
	 */
	public function template_redirect() {
		$this->render( get_queried_object_id() );
		exit;
	}

	/**
	 * Based on the post ID, render the Instant Articles page.
	 *
	 * @param  int   $post_id Post ID.
	 *
	 * @return void
	 */
	public function render( $post_id ) {
		do_action( 'simple_fb_pre_render', $post_id );
		include( apply_filters( 'simple_fb_article_template_file', $this->template_path . '/article.php' ) );
	}

	/**
	 * Register FB feed.
	 *
	 * @return void
	 */
	public function add_feed() {
		$feed_slug = apply_filters( 'simple_fb_feed_slug', $this->token );
		add_feed( $feed_slug, array( $this, 'feed_template' ) );
	}

	/**
	 * Load feed template.
	 *
	 * @return void
	 */
	public function feed_template() {
		global $wp_query;

		// Prevent 404 on feed
		$wp_query->is_404 = false;
		status_header( 200 );

		// Any functions hooked in here must NOT output any data or else feed will break.
		do_action( 'simple_fb_before_feed' );

		$template = trailingslashit( $this->template_path ) . 'feed.php';;

		if ( 0 === validate_file( $template ) ) {
			require( $template );
		}


		// Any functions hooked in here must NOT output any data or else feed will break.
		do_action( 'simple_fb_after_feed' );
	}

	/**
	 * Setup all filters to modify content ready for Facebook IA.
	 *
	 * Hooked in just before the content is rendered in both feeds and single post view
	 * for Facebook IA only.
	 *
	 * This function is added to the following actions:
	 * 1) simple_fb_pre_render
	 * 2) simple_fb_before_feed
	 */
	public function setup_content_mods() {

		// Shortcodes - overwrite WP native ones with FB IA format.
		add_shortcode( 'gallery', array( $this, 'gallery_shortcode' ) );
		add_shortcode( 'caption', array( $this, 'image_shortcode' ) );

		// Shortcodes - custom galleries.
		add_shortcode( 'sigallery', array( $this, 'api_galleries_shortcode' ) );

		// Render social embeds into FB IA format.
		add_filter( 'embed_handler_html', array( $this, 'reformat_social_embed' ), 10, 3 );
		add_filter( 'embed_oembed_html', array( $this, 'reformat_social_embed' ), 10, 4 );

		// Modify the content.
		add_filter( 'the_content', array( $this, 'reformat_post_content' ), 1000 );
		add_action( 'the_content', array( $this, 'append_google_analytics_code' ), 1100 );
		add_action( 'the_content', array( $this, 'append_ad_code' ), 1100 );

		// Post URL for the feed.
		add_filter( 'the_permalink_rss', array( $this, 'rss_permalink' ) );

		// Render post content into FB IA format - using DOM object.
		add_action( 'simple_fb_reformat_post_content', array( $this, 'render_pull_quotes' ), 10, 2 );
		add_action( 'simple_fb_reformat_post_content', array( $this, 'render_images' ), 10, 2 );
	}

	public function rss_permalink( $link ) {

		return esc_url( $link . $this->endpoint );
	}

	/**
	 * Gallery Shortcode.
	 *
	 * @param  array     $atts       Array of attributes passed to shortcode.
	 * @param  string    $content    The content passed to the shortcode.
	 *
	 * @return string                The generated content.
	 */
	public function gallery_shortcode( $atts, $content = '' ) {

		// Get the image IDs.
		$ids = explode( ',', $atts['ids'] );

		ob_start(); ?>

		<figure class="op-slideshow">
			<?php foreach ( $ids as $id ) {
				echo $this->image_shortcode( array( 'id' => $id ) );
			} ?>
		</figure>

		<?php return ob_get_clean();
	}

	/**
	 * Caption shortcode - overwrite WP native shortcode.
	 * Format images in caption shortcodes into FB IA format.
	 *
	 * @param $atts           Array of attributes passed to shortcode.
	 * @param string $content The content passed to the shortcode.
	 *
	 * @return string|void    FB IA formatted images markup.
	 */
	public function image_shortcode( $atts, $content = '' ) {

		// Get attachment ID from the shortcode attribute.
		$attachment_id = isset( $atts['id'] ) ? (int) str_replace( 'attachment_', '', $atts['id'] ) : '';

		// Get image info.
		$image     = wp_get_attachment_image_src( $attachment_id, $this->image_size );
		$image_url = isset( $image[0] ) ? $image[0] : '';

		// Stop - if image URL is empty.
		if ( ! $image_url ) {
			return;
		}

		// FB IA image format.
		ob_start(); ?>

		<figure>
			<img src="<?php echo esc_url( $image_url ); ?>" />
			<?php simple_fb_image_caption( $attachment_id ); ?>
		</figure>

		<?php return ob_get_clean();
	}

	/**
	 * Convert custom gallery shortcode - sigallery,
	 * into FB IA image gallery format.
	 *
	 * @param $atts        Array of attributes passed to shortcode.
	 *
	 * @return string|void Return FB IA image gallery markup for sigallery shortcode,
	 *                     On error - nothing.
	 */
	public function api_galleries_shortcode( $atts ) {

		// Stop - if gallery ID is empty.
		if ( ! $atts['id'] ) {
			return;
		}

		// Stop - if can't get the API gallery.
		if ( ! $gallery = \USAT\API_Galleries\get_gallery( $atts['id'] ) ) {
			return;
		}

		// Display API gallery in FB IA format.
		ob_start();
		?>

		<figure class="op-slideshow">
			<?php foreach ( $gallery->images as $key => $image ) : ?>
				<figure>
					<img src="<?php echo esc_url( $image->url ); ?>" />
					<?php if ( $image->custom_caption ) : ?>
						<figcaption><h1><?php echo esc_html( strip_tags( $image->custom_caption ) ); ?></h1></figcaption>
					<?php endif; ?>
				</figure>
			<?php endforeach; ?>

			<?php if ( $atts['title'] ) : ?>
				<figcaption><h1><?php echo esc_html( $atts['title'] ); ?></h1></figcaption>
			<?php endif;?>
		</figure>

		<?php
		return ob_get_clean();
	}

	/**
	 * Render social embeds into FB IA format.
	 *
	 * Social embeds Ref: https://developers.facebook.com/docs/instant-articles/reference/social
	 *
	 * @param string   $html    HTML markup to be embeded into post sontent.
	 * @param string   $url     The attempted embed URL.
	 * @param array    $attr    An array of shortcode attributes.
	 * @param int|null $post_ID Post ID for which embeded URLs are processed.
	 *
	 * @return string           FB IA formatted markup for social embeds.
	 */
	public function reformat_social_embed( $html, $url, $attr, $post_ID = null ) {

		return '<figure class="op-social"><iframe>' . $html . '</iframe></figure>';
	}

	/**
	 * Setup DOM and XPATH objects for formatting post content.
	 * Introduces `simple_fb_reformat_post_content` filter, so that post content
	 * can be formatted as necessary and dom/xpath objects re-used.
	 *
	 * @param $post_content Post content that needs to be formatted into FB IA format.
	 *
	 * @return string|void  Post content in FB IA format if dom is generated for post content,
	 *                      Otherwise, nothing.
	 */
	public function reformat_post_content( $post_content ) {

		$dom = new \DOMDocument();

		// Parse post content to generate DOM document.
		// Use loadHTML as it doesn't need to be well-formed to load.
		@$dom->loadHTML( '<html><body>' . $post_content . '</body></html>' );

		// Stop - if dom isn't generated.
		if ( ! $dom ) {
			return;
		}

		$xpath = new \DOMXPath( $dom );

		// Allow to render post content via action.
		do_action_ref_array( 'simple_fb_reformat_post_content', array( &$dom, &$xpath ) );

		// Get the FB IA formatted post content HTML.
		$body_node = $dom->getElementsByTagName( 'body' )->item( 0 );

		return $this->get_html_for_node( $body_node );

	}

	/**
	 * Renders pull quotes into FB AI format.
	 * Ref: https://developers.facebook.com/docs/instant-articles/reference/pullquote
	 *
	 * @param DOMDocument $dom   DOM object generated for post content.
	 * @param DOMXPath    $xpath XPATH object generated for post content.
	 */
	public function render_pull_quotes( \DOMDocument &$dom, \DOMXPath &$xpath ) {

		// HTML allowed in blockquotes.
		$allowed_html = array(
			'em'     => array(),
			'i'      => array(),
			'b'      => array(),
			'strong' => array(),
			'a'      => array( 'href' => true ),
		);

		// Pull quotes - with <cite> element.
		foreach ( $xpath->query( '//blockquote' ) as $node ) {

			// Treat the first <cite> tag found as THE citation.
			$cite = $node->getElementsByTagName( 'cite' );
			$cite = ( $cite->length > 0 ) ? $cite->item( 0 ) : null;

			if ( $cite ) {
				$cite->parentNode->removeChild( $cite );
			}

			$aside    = $dom->createElement( 'aside' );
			$html     = wp_kses( $this->get_html_for_node( $node ), $allowed_html );
			$fragment = $dom->createDocumentFragment();

			$fragment->appendXML( $html );
			$aside->appendChild( $fragment );

			if ( $cite ) {
				$aside->appendChild( $cite );
			}

			$node->parentNode->replaceChild( $aside, $node );

		}
	}

	/**
	 * Reformat images into FB AI format.
	 *
	 * Ensure they are child of <figure>.
	 * Consider <img> with parent <figure> already been converted to FB IA format.
	 *
	 * Ref: https://developers.facebook.com/docs/instant-articles/reference/image
	 *
	 * @param DOMDocument $dom   DOM object generated for post content.
	 * @param DOMXPath    $xpath XPATH object generated for post content.
	 */
	public function render_images( \DOMDocument &$dom, \DOMXPath &$xpath ) {

		// Images - with parent that's not <figure>.
		foreach ( $xpath->query( '//img[not(parent::figure)]' ) as $node ) {

			$figure = $dom->createElement( 'figure' );

			$node->parentNode->replaceChild( $figure, $node );

			$figure->appendChild( $node );

		}
	}

	/**
	 * Append Google Analytics (GA) script in the FB IA format
	 * to the post content.
	 *
	 * @param string $post_content Post content.
	 *
	 * @return string Post content with added GA script in FB IA format.
	 */
	public function append_google_analytics_code( $post_content ) {

		$post_content .= $this->get_google_analytics_code();
		return $post_content;
	}

	/**
	 * Get GA script in the FB IA format.
	 *
	 * Ref: https://developers.facebook.com/docs/instant-articles/reference/analytics
	 *
	 * @return string GA script in FB IA format.
	 */
	public function get_google_analytics_code() {

		$analytics_template_file = trailingslashit( $this->template_path ) . 'script-ga.php';
		$ga_profile_id           = get_option( 'lawrence_ga_tracking_id' );

		if ( ! $ga_profile_id ) {
			return;
		}

		ob_start();
		require( $analytics_template_file );
		return ob_get_clean();

	}

	/**
	 * Append Ad script in the FB IA format to the post content.
	 *
	 * @param string $post_content Post content.
	 *
	 * @return string Post content with added ad script in FB IA format.
	 */
	public function append_ad_code( $post_content ) {

		$post_content .= $this->get_ad_code();
		return $post_content;
	}

	/**
	 * Get Ad code in the FB IA format.
	 *
	 * @return string Ad script in FB IA format.
	 */
	public function get_ad_code() {

		ob_start();
		require( trailingslashit( $this->template_path ) . 'ad.php' );
		return ob_get_clean();
	}

	/**
	 * Get Ad targeting args.
	 *
	 * @return array Targeting params.
	 */
	protected function get_ad_targeting_params() {

		// Note use of get_the_terms + wp_list_pluck as these are cached ang get_the_* is not.
		$tags    = wp_list_pluck( get_the_terms( $post, 'post_tag' ), 'name' );
		$cats    = wp_list_pluck( get_the_terms( $post, 'category' ), 'name' );
		$authors = wp_list_pluck( array_filter( (array) get_coauthors( get_the_ID() ) ), 'display_name' );

		$url_bits = parse_url( home_url() );

		$targeting_params = array(
			// Merge, Remove dupes, and fix keys order.
			'kw'         => array_values( array_unique( array_merge( $cats, $tags, $authors ) ) ),
			'category'   => $cats,
			'domainName' => isset( $url_bits['host'] ) ? $url_bits['host'] : '',
		);

		return $targeting_params;
	}

	/**
	 * Output Ad targeting JS.
	 *
	 * @return void
	 */
	public function ad_targeting_js() {

		foreach ( $this->get_ad_targeting_params() as $key => $value ) {
			printf( ".setTargeting( '%s', %s )", esc_js( $key ), wp_json_encode( $value ) );
		}
	}

	/**
	 * Generates HTML string for DOM node object.
	 *
	 * @param DOMNode $node Node object to generate the HTML string for.
	 *
	 * @return string       HTML string/markup for supplied DOM node.
	 */
	protected function get_html_for_node( \DOMNode $node ) {

		$node_html  = '';

		foreach ( $node->childNodes as $child_node ) {
			$node_html .= $child_node->ownerDocument->saveHTML( $child_node );
		}

		return $node_html;
	}

}

/**
 * Instantiate or return the one Simple_FB_Instant_Articles instance.
 *
 * @return Simple_FB_Instant_Articles
 */
function simple_fb_instant_articles( $file, $version ) {
	return Simple_FB_Instant_Articles::instance( $file, $version );
}

// Kick off the plugin on init.
simple_fb_instant_articles( __FILE__, '0.5.0' );
