<?php
/*
 * Plugin name: Batcache Manager
 * Plugin URI: http://www.github.com/spacedmonkey/batcache-manager
 * Description: Cache clearing for batcache
 * Author: Jonathan Harris
 * Author URI: http://www.jonathandavidharris.co.uk
 * Version: 2.0.2
*/

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Batcache_Manager
 */
class Batcache_Manager {

	/**
	 * List of feeds
	 *
	 * @since    2.0.0
	 *
	 * @var array
	 */
	private array $feeds = array( 'rss', 'rss2', 'rdf', 'atom' );

	/**
	 * List of links to process
	 *
	 * @since    2.0.0
	 *
	 * @var array
	 */
	private array $links = array();

	/**
	 * Instance of this class.
	 *
	 * @since    2.0.0
	 *
	 * @var      Batcache_Manager|null
	 */
	protected static ?self $instance = null;

	/**
	 *
	 */
	/** @var bool Whether hooks have been registered. */
	private bool $hooks_registered = false;

	private function __construct() {}

	/**
	 * Register cache-clearing hooks.
	 *
	 * Deferred from the constructor so that hook registration only happens
	 * when the Batcache_Manager is actually needed (lazy initialization).
	 * Called automatically by get_instance() when the batcache globals
	 * are available.
	 */
	public function register_hooks(): void {
		if ( $this->hooks_registered ) {
			return;
		}

		global $batcache, $wp_object_cache;

		// Do not load if our advanced-cache.php isn't loaded
		if ( ! isset( $batcache ) || ! is_object( $batcache ) || ! method_exists( $wp_object_cache, 'incr' ) ) {
			return;
		}

		$this->hooks_registered = true;

		$batcache->configure_groups();

		// Core hooks: posts, terms, and comments — always registered.
		// These cover WooCommerce product/order updates and standard editorial changes.
		add_action( 'clean_post_cache', array( $this, 'action_clean_post_cache' ), 15 );
		add_action( 'clean_term_cache', array( $this, 'action_clean_term_cache' ), 10, 3 );
		add_action( 'clean_comment_cache', array( $this, 'action_update_comment' ) );
		add_action( 'comment_post', array( $this, 'action_update_comment' ) );
		add_action( 'wp_set_comment_status', array( $this, 'action_update_comment' ) );
		add_action( 'edit_comment', array( $this, 'action_update_comment' ) );

		// Extended hooks: users, widgets, customizer, theme switches, nav menus.
		// These are broader and trigger site-wide Batcache invalidation, which can
		// be expensive on managed infrastructure. Opt-in via filter:
		//   add_filter( 'pcm_batcache_manager_extended_hooks', '__return_true' );
		if ( apply_filters( 'pcm_batcache_manager_extended_hooks', false ) ) {
			add_action( 'clean_user_cache', array( $this, 'action_update_user' ) );
			add_action( 'profile_update', array( $this, 'action_update_user' ) );
			add_filter( 'widget_update_callback', array( $this, 'action_update_widget' ), 50 );
			add_action( 'customize_save_after', array( $this, 'flush_all' ) );
			add_action( 'switch_theme', array( $this, 'flush_all' ) );
			add_action( 'wp_update_nav_menu', array( $this, 'flush_all' ) );
		}

		// Add site aliases to list of links
		add_filter( 'batcache_manager_links', array( $this, 'add_site_alias' ) );

		// Do the flush of the urls on shutdown
		add_action( 'shutdown', array( $this, 'clear_urls' ) );
	}

	/**
	 * Return an instance of this class.
	 *
	 * @since     2.0.0
	 *
	 * @return    self    A single instance of this class.
	 */
	public static function get_instance(): self {
		if ( null === self::$instance ) {
			self::$instance = new self();
			self::$instance->register_hooks();
		}

		return self::$instance;
	}

	/**
	 * Register the lazy bootstrap hook used by the copied mu-plugin file.
	 */
	public static function bootstrap(): void {
		add_action( 'init', array( self::class, 'initialize' ) );
	}

	/**
	 * Initialize and expose the shared Batcache manager instance.
	 */
	public static function initialize(): void {
		global $batcache_manager;

		$batcache_manager = self::get_instance();
	}

	/**
	 * Determines whether a post type is considered "viewable".
	 *
	 * For built-in post types such as posts and pages, the 'public' value will be evaluated.
	 * For all others, the 'publicly_queryable' value will be used.
	 *
	 *
	 * @param string $post_type Post type.
	 *
	 * @return bool Whether the post type should be considered viewable.
	 */
	public function is_post_type_viewable( string $post_type ): bool {
		$post_type_object = get_post_type_object( $post_type );
		if ( empty( $post_type_object ) ) {
			return false;
		}

		return $post_type_object->publicly_queryable || ( $post_type_object->_builtin && $post_type_object->public );
	}

	/**
	 * Whether the taxonomy object is public.
	 *
	 * Checks to make sure that the taxonomy is an object first. Then Gets the
	 * object, and finally returns the public value in the object.
	 *
	 * A false return value might also mean that the taxonomy does not exist.
	 *
	 * @since 2.0.0
	 *
	 * @param string $taxonomy Name of taxonomy object.
	 *
	 * @return bool Whether the taxonomy is public.
	 */
	public function is_taxonomy_viewable( string $taxonomy ): bool {
		if ( ! taxonomy_exists( $taxonomy ) ) {
			return false;
		}

		$taxonomy = get_taxonomy( $taxonomy );

		return $taxonomy->public;
	}

	/**
	 * Clear post on post update
	 *
	 * @param $post_id
	 */
	public function action_clean_post_cache( int $post_id ): void {

		$post = get_post( $post_id );
		if (
			! $post instanceof \WP_Post
			|| empty( $post->post_type )
			|| ! $this->is_post_type_viewable( $post->post_type )
			|| ! in_array( get_post_status( $post_id ), array( 'publish', 'trash' ), true )
		) {
			return;
		}

		// Only flush the permalink of the specific post that changed.
		// Date archives, author archives, feeds, and the homepage are intentionally
		// NOT flushed here — those shared URLs should only be invalidated on a
		// full manual flush, not on every individual page save.
		$permalink = get_permalink( $post );
		if ( ! empty( $permalink ) ) {
			$this->links[] = $permalink;
		}

		// WooCommerce product: also purge Edge Cache for the specific URL
		// via the canonical cache service so timestamps and audit stay consistent.
		if ( 'product' === $post->post_type ) {
			$product_url = get_permalink( $post );
			self::clear_url( $product_url );
			pcm_cache_service()->purge_edge_cache_url( $product_url, 'woo-product-update' );
		}
	}

	/**
	 * Clear terms on term update
	 *
	 * @param array $ids Single or list of Term IDs.
	 * @param string $taxonomy
	 * @param bool $clean_taxonomy Optional. Whether to clean taxonomy wide caches (true), or just individual
	 * term object caches (false). Default true. Only support in WP 4.5
	 */
	public function action_clean_term_cache( array $ids, string $taxonomy, bool $clean_taxonomy = true ): void {
		// Clear taxonomy global caches. If false, lets not both.
		if ( ! $clean_taxonomy ) {
			return;
		}
		// If not a public taxonomy, don't clear caches.
		if ( ! $this->is_taxonomy_viewable( $taxonomy ) ) {
			return;
		}

		foreach ( $ids as $term ) {
			$this->setup_term_urls( $term, $taxonomy );
		}
	}

	/**
	 * Clear post page on comment update
	 *
	 * @param $comment_id
	 */
	public function action_update_comment( int|string $comment_id ): void {
		$comment = get_comment( $comment_id );
		if ( ! $comment instanceof \WP_Comment ) {
			return;
		}

		$post_id = (int) $comment->comment_post_ID;
		$this->setup_post_urls( $post_id );
		$this->setup_post_comment_urls( $post_id, (int) $comment->comment_ID );
	}

	/**
	 * Clear author links on update user.
	 *
	 * @param $user_id
	 */
	public function action_update_user( int $user_id ): void {
		$this->setup_author_urls( $user_id );
	}

	public function flush_all(): void {
		if ( function_exists( 'batcache_flush_all' ) ) {
			batcache_flush_all();
		}
	}

	/**
	 * Flush all of the caches when a widget is updated.
	 *
	 * @param  array $instance The current widget instance's settings.
	 *
	 * @return array $instance
	 */
	public function action_update_widget( array $instance ): array {
		$this->flush_all();

		return $instance;
	}

	/**
	 * Get term archive and feed links for each term
	 *
	 * @param $term
	 * @param $taxonomy
	 */
	public function setup_term_urls( int|string $term, string $taxonomy ): void {

		$term_link = get_term_link( $term, $taxonomy );
		if ( ! is_wp_error( $term_link ) ) {
			$this->links[] = $term_link;
		}
		foreach ( $this->feeds as $feed ) {
			$term_link_feed = get_term_feed_link( $term, $taxonomy, $feed );
			if ( $term_link_feed ) {
				$this->links[] = $term_link_feed;
			}
		}

		$taxonomy_object = get_taxonomy( $taxonomy );
		if ( $taxonomy_object->show_in_rest && $taxonomy_object->rest_base ) {
			$base          = $taxonomy_object->rest_base;
			$this->links[] = get_rest_url( null, '/wp/v2/' . $base );
			$this->links[] = get_rest_url( null, '/wp/v2/' . $base . '/' . $term );
		}
	}

	/**
	 * Home page / blog page and feed links
	 */
	public function setup_site_urls(): void {
		if ( 'page' === get_option( 'show_on_front' ) ) {
			$this->links[] = get_permalink( get_option( 'page_for_posts' ) );
		}

		$this->links[] = home_url( '/' );

		foreach ( $this->feeds as $feed ) {
			$this->links[] = get_feed_link( $feed );
		}
	}

	/**
	 * Get permalink, date archives and custom post type links
	 *
	 * @param $post
	 */
	public function setup_post_urls( int|\WP_Post $post ): void {
		$post = get_post( $post );

		$this->links[] = get_permalink( $post );
		if ( 'post' === $post->post_type ) {
			$year          = get_the_time( 'Y', $post );
			$month         = get_the_time( 'm', $post );
			$day           = get_the_time( 'd', $post );
			$this->links[] = get_year_link( $year );
			$this->links[] = get_month_link( $year, $month );
			$this->links[] = get_day_link( $year, $month, $day );
		} elseif ( ! in_array( $post->post_type, get_post_types( array( 'public' => true ) ), true ) ) {
			$archive_link = get_post_type_archive_link( $post->post_type );
			if ( $archive_link ) {
				$this->links[] = $archive_link;
			}
			foreach ( $this->feeds as $feed ) {
				$archive_link_feed = get_post_type_archive_feed_link( $post->post_type, $feed );
				if ( $archive_link_feed ) {
					$this->links[] = $archive_link_feed;
				}
			}
		}
		$post_type = get_post_type_object( $post->post_type );
		if ( $post_type->show_in_rest && $post_type->rest_base ) {
			$base          = $post_type->rest_base;
			$this->links[] = get_rest_url( null, '/wp/v2/' . $base );
			$this->links[] = get_rest_url( null, '/wp/v2/' . $base . '/' . $post->ID );
		}
	}

	/**
	 * Author profile and feed links
	 *
	 * @param $author_id
	 */
	public function setup_author_urls( int $author_id ): void {
		$this->links[] = get_author_posts_url( $author_id );
		foreach ( $this->feeds as $feed ) {
			$this->links[] = get_author_feed_link( $author_id, $feed );
		}
		$this->links[] = get_rest_url( null, '/wp/v2/users' );
		$this->links[] = get_rest_url( null, '/wp/v2/users/' . $author_id );
	}

	/**
	 * Get feed urls for comments for single posts
	 *
	 * @param $post_id
	 */
	public function setup_post_comment_urls( int $post_id, int $comment_id = 0 ): void {
		foreach ( $this->feeds as $feed ) {
			$this->links[] = get_post_comments_feed_link( $post_id, $feed );
		}

		foreach ( $this->feeds as $feed ) {
			$this->links[] = get_feed_link( 'comments_' . $feed );
		}
		$this->links[] = get_rest_url( null, '/wp/v2/comments' );
		$this->links[] = get_rest_url( null, '/wp/v2/comments/' . $comment_id );
	}


	/**
	 * Work around for those using Domain mapping or have CMS on different url.
	 *
	 * @param $links
	 */
	public function add_site_alias( array $links ): array {
		$home = wp_parse_url( home_url(), PHP_URL_HOST );

		$compare_urls = array(
			wp_parse_url( get_option( 'home' ), PHP_URL_HOST ),
			wp_parse_url( get_option( 'siteurl' ), PHP_URL_HOST ),
			wp_parse_url( site_url(), PHP_URL_HOST ),
		);

		// Compare home, site urls with filtered home url
		foreach ( $compare_urls as $compare_url ) {
			if ( $compare_url !== $home ) {
				foreach ( $links as $url ) {
					$links[] = str_replace( $home, $compare_url, $url );
				}
			}
		}

		return $links;
	}

	/**
	 * Loop around all urls and clear
	 */
	public function clear_urls(): void {
		if ( empty( $this->get_links() ) ) {
			return;
		}

		foreach ( $this->get_links() as $url ) {
			self::clear_url( $url );
		}
		// Clear out links
		$this->links = array();

		update_option( PCM_Options::FLUSH_SINGLE_PAGE_TIMESTAMP->value, pcm_format_flush_timestamp() );
	}

	/**
	 *
	 * @param $url
	 *
	 * @return bool
	 */
	public static function clear_url( string $url ): bool {
		return pcm_flush_batcache_url( $url );
	}

	/**
	 * Filter links
	 *
	 * @return array
	 */
	public function get_links(): array {
		$this->links = apply_filters( 'batcache_manager_links', $this->links );

		return array_unique( $this->links );
	}
}

Batcache_Manager::bootstrap();
