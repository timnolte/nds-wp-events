<?php
/**
 * NDS WordPress Events.
 *
 * @package   NDS_WordPress_Events
 * @author    Tim Nolte <tim.nolte@ndigitals.com>
 * @license   GPL-2.0+
 * @link      http://www.ndigitals.com
 * @copyright 2013 NDigital Solutions
 */

/**
 * Include Events widget class.
 */
require( NDSWP_EVENTS_PATH . '/inc/widgets/class-upcoming-events.php' );

/**
 * Plugin class.
 *
 * @package NDS_WP_Events
 * @author  Tim Nolte <tim.nolte@ndigitals.com>
 */
class NDS_WP_Events
{

    /**
     * Plugin version, used for cache-busting of style and script file references.
     *
     * @since   1.0.0
     *
     * @var     string
     */
    const VERSION = '1.0.0';

    /**
     * Unique identifier for your plugin.
     *
     * The variable name is used as the text domain when internationalizing strings of text.
     * Its value should match the Text Domain file header in the main plugin file.
     *
     * @since    1.0.0
     *
     * @var      string
     */
    protected $plugin_slug = 'nds-wp-events';

    /**
     * Unique identifier for the plugin custom post type
     *
     * @since   1.0.0
     *
     * @var     string
     */
    protected $plugin_post_type = 'nds_wp_events';

    /**
     * Unique prefix for custom meta fields
     *
     * @since   1.0.0
     *
     * @var     string
     */
    protected $plugin_custom_field_prefix = 'nds_wp_event';

    /**
     * Instance of this class.
     *
     * @since    1.0.0
     *
     * @var      object
     */
    protected static $instance = NULL;

    /**
     * Initialize the plugin by setting localization and loading public scripts and styles.
     *
     * @since     1.0.0
     */
    private function __construct()
    {

        // Load plugin text domain
        add_action( 'init', array( $this, 'load_plugin_textdomain' ) );

        // Activate plugin when new blog is added
        add_action( 'wpmu_new_blog', array( $this, 'activate_new_site' ) );

        // Load public-facing style sheet and JavaScript.
        add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_styles' ) );
        add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_scripts' ) );

        // Define custom functionality. Read more about actions and filters: http://codex.wordpress.org/Plugin_API#Hooks.2C_Actions_and_Filters
        add_action( 'init', array( $this, 'events_register' ) );
        add_action( 'init', array( $this, 'events_category_taxonomy' ), 0 );
        add_action( 'init', array( $this, 'events_tag_taxonomy' ), 0 );
        add_action( 'pre_get_posts', array( $this, 'events_query' ) );
        if ( function_exists( 'register_sidebar' ) )
        {
            add_action( 'widgets_init', array( $this, 'events_widget_areas_init' ) );
        }
        add_action( 'widgets_init', array( $this, 'events_widgets_register' ) );

    }

    /**
     * Return the plugin slug.
     *
     * @since    1.0.0
     *
     * @return   string     Plugin slug variable.
     */
    public function get_plugin_slug()
    {
        return $this->plugin_slug;
    }

    /**
     * Return the plugin custom post type identifier.
     *
     * @since   1.0.0
     *
     * @return  string      Plugin custom post type identifier variable.
     */
    public function get_plugin_post_type()
    {
        return $this->plugin_post_type;
    }

    /**
     * Return the plugin custom meta field prefix.
     *
     * @since   1.0.0
     *
     * @return  string      Plugin custom meta field variable.
     */
    public function get_plugin_field_prefix()
    {
        return $this->plugin_custom_field_prefix;
    }

    /**
     * Return an instance of this class.
     *
     * @since     1.0.0
     *
     * @return    object    A single instance of this class.
     */
    public static function get_instance()
    {

        // If the single instance hasn't been set, set it now.
        if ( NULL == self::$instance )
        {
            self::$instance = new self;
        }

        return self::$instance;
    }

    /**
     * Fired when the plugin is activated.
     *
     * @since    1.0.0
     *
     * @param    boolean $network_wide    True if WPMU superadmin uses "Network Activate" action, false if WPMU is disabled or plugin is activated on an individual blog.
     */
    public static function activate( $network_wide )
    {
        if ( function_exists( 'is_multisite' ) && is_multisite() )
        {
            if ( $network_wide )
            {
                // Get all blog ids
                $blog_ids = self::get_blog_ids();

                foreach ( $blog_ids as $blog_id )
                {
                    switch_to_blog( $blog_id );
                    self::single_activate();
                }
                restore_current_blog();
            }
            else
            {
                self::single_activate();
            }
        }
        else
        {
            self::single_activate();
        }
    }

    /**
     * Fired when the plugin is deactivated.
     *
     * @since    1.0.0
     *
     * @param    boolean $network_wide    True if WPMU superadmin uses "Network Deactivate" action, false if WPMU is disabled or plugin is deactivated on an individual blog.
     */
    public static function deactivate( $network_wide )
    {
        if ( function_exists( 'is_multisite' ) && is_multisite() )
        {
            if ( $network_wide )
            {
                // Get all blog ids
                $blog_ids = self::get_blog_ids();

                foreach ( $blog_ids as $blog_id )
                {
                    switch_to_blog( $blog_id );
                    self::single_deactivate();
                }
                restore_current_blog();
            }
            else
            {
                self::single_deactivate();
            }
        }
        else
        {
            self::single_deactivate();
        }
    }

    /**
     * Fired when a new site is activated with a WPMU environment.
     *
     * @since    1.0.0
     *
     * @param    int $blog_id ID of the new blog.
     */
    public function activate_new_site( $blog_id )
    {
        if ( 1 !== did_action( 'wpmu_new_blog' ) )
        {
            return;
        }

        switch_to_blog( $blog_id );
        self::single_activate();
        restore_current_blog();
    }

    /**
     * Get all blog ids of blogs in the current network that are:
     * - not archived
     * - not spam
     * - not deleted
     *
     * @since    1.0.0
     *
     * @return   array|false    The blog ids, false if no matches.
     */
    private static function get_blog_ids()
    {
        global $wpdb;

        // get an array of blog ids
        $sql = "SELECT blog_id FROM $wpdb->blogs
			WHERE archived = '0' AND spam = '0'
			AND deleted = '0'";

        return $wpdb->get_col( $sql );
    }

    /**
     * Fired for each blog when the plugin is activated.
     *
     * @since    1.0.0
     */
    private static function single_activate()
    {
        // TODO: Define activation functionality here
    }

    /**
     * Fired for each blog when the plugin is deactivated.
     *
     * @since    1.0.0
     */
    private static function single_deactivate()
    {
        // TODO: Define deactivation functionality here
    }

    /**
     * Load the plugin text domain for translation.
     *
     * @since    1.0.0
     */
    public function load_plugin_textdomain()
    {

        $domain = $this->plugin_slug;
        $locale = apply_filters( 'plugin_locale', get_locale(), $domain );

        load_textdomain( $domain, trailingslashit( WP_LANG_DIR ) . $domain . '/' . $domain . '-' . $locale . '.mo' );
        load_plugin_textdomain( $domain, FALSE, basename( dirname( __FILE__ ) ) . '/languages' );
    }

    /**
     * Register and enqueue public-facing style sheet.
     *
     * @since    1.0.0
     */
    public function enqueue_styles()
    {
        wp_enqueue_style(
            $this->plugin_slug . '-plugin-styles',
            NDSWP_EVENTS_URL . 'assets/css/frontend.css',
            array(),
            self::VERSION
        );
    }

    /**
     * Register and enqueues public-facing JavaScript files.
     *
     * @since    1.0.0
     */
    public function enqueue_scripts()
    {
        wp_enqueue_script(
            $this->plugin_slug . '-plugin-script',
            NDSWP_EVENTS_URL . 'assets/js/frontend.js',
            array( 'jquery' ),
            self::VERSION
        );
    }

    /**
     * NOTE:  Actions are points in the execution of a page or process
     *        lifecycle that WordPress fires.
     *
     *        WordPress Actions: http://codex.wordpress.org/Plugin_API#Actions
     *        Action Reference:  http://codex.wordpress.org/Plugin_API/Action_Reference
     *
     * @since    1.0.0
     */
    public function action_method_name()
    {
        // TODO: Define your action hook callback here
    }

    /**
     * NOTE:  Filters are points of execution in which WordPress modifies data
     *        before saving it or sending it to the browser.
     *
     *        WordPress Filters: http://codex.wordpress.org/Plugin_API#Filters
     *        Filter Reference:  http://codex.wordpress.org/Plugin_API/Filter_Reference
     *
     * @since    1.0.0
     */
    public function filter_method_name()
    {
        // TODO: Define your filter hook callback here
    }

    /**
     * Create an Event Post Type
     */
    public function events_register()
    {

        $labels = array(
            'name'               => _x( 'Events', 'post type general name' ),
            'singular_name'      => _x( 'Event', 'post type singular name' ),
            'menu_name'          => __( 'Events' ),
            'add_new'            => _x( 'Add New', 'event' ),
            'add_new_item'       => __( 'Add New Event' ),
            'edit_item'          => __( 'Edit Event' ),
            'new_item'           => __( 'New Event' ),
            'all_items'          => __( 'All Events' ),
            'view_item'          => __( 'View Event' ),
            'search_items'       => __( 'Search Events' ),
            'not_found'          => __( 'Nothing found' ),
            'not_found_in_trash' => __( 'Nothing found in Trash' ),
            'parent_item_colon'  => ''
        );

        $args = array(
            'labels'             => $labels,
            'public'             => TRUE,
            'publicly_queryable' => TRUE,
            'show_ui'            => TRUE,
            'show_in_nav_menus'  => FALSE,
            'query_var'          => TRUE,
            'rewrite'            => array( "slug" => "events" ), // Permalinks format
            'capability_type'    => 'post',
            'hierarchical'       => FALSE,
            'menu_position'      => NULL,
            'menu_icon'          => NULL,
            'has_archive'        => TRUE,
            'supports'           => array( 'title', 'editor', 'excerpt', 'thumbnail' ),
            'taxonomies'         => array( $this->plugin_post_type . '_category', $this->plugin_post_type . '_tag' )
        );

        register_post_type( $this->plugin_post_type, $args );
    }


    /**
     * Setup custom category for Events.
     */
    public function events_category_taxonomy()
    {

        $labels = array(
            'name'                       => _x( 'Event Categories', 'taxonomy general name' ),
            'singular_name'              => _x( 'Event Category', 'taxonomy singular name' ),
            'menu_name'                  => __( 'Categories' ),
            'search_items'               => __( 'Search Event Categories' ),
            'popular_items'              => __( 'Popular Event Categories' ),
            'all_items'                  => __( 'All Event Categories' ),
            'parent_item'                => NULL,
            'parent_item_colon'          => NULL,
            'edit_item'                  => __( 'Edit Event Category' ),
            'update_item'                => __( 'Update Event Category' ),
            'add_new_item'               => __( 'Add New Event Category' ),
            'new_item_name'              => __( 'New Event Category Name' ),
            'separate_items_with_commas' => __( 'Separate event categories with commas' ),
            'add_or_remove_items'        => __( 'Add or remove event categories' ),
            'choose_from_most_used'      => __( 'Choose from the most used event categories' )
        );

        register_taxonomy(
            $this->plugin_post_type . '_category',
            NULL,
            array(
                 'label'             => __( 'Event Category' ),
                 'labels'            => $labels,
                 'show_ui'           => TRUE,
                 'show_in_nav_menus' => FALSE,
                 'query_var'         => TRUE,
                 'rewrite'           => array( 'slug' => 'event-category' ),
                 'hierarchical'      => TRUE
            )
        );
    }


    /**
     * Setup custom tags for Events.
     */
    public function events_tag_taxonomy()
    {

        $labels = array(
            'name'                       => _x( 'Event Tags', 'taxonomy general name' ),
            'singular_name'              => _x( 'Event Tag', 'taxonomy singular name' ),
            'menu_name'                  => __( 'Tags' ),
            'search_items'               => __( 'Search Event Tags' ),
            'popular_items'              => __( 'Popular Event Tags' ),
            'all_items'                  => __( 'All Event Tags' ),
            'parent_item'                => NULL,
            'parent_item_colon'          => NULL,
            'edit_item'                  => __( 'Edit Event Tag' ),
            'update_item'                => __( 'Update Event Tag' ),
            'add_new_item'               => __( 'Add New Event Tag' ),
            'new_item_name'              => __( 'New Event Tag Name' ),
            'separate_items_with_commas' => __( 'Separate event tags with commas' ),
            'add_or_remove_items'        => __( 'Add or remove event tags' ),
            'choose_from_most_used'      => __( 'Choose from the most used event tags' )
        );

        register_taxonomy(
            $this->plugin_post_type . '_tag',
            NULL,
            array(
                 'label'             => __( 'Event Tag' ),
                 'labels'            => $labels,
                 'show_ui'           => TRUE,
                 'show_tagcloud'     => TRUE,
                 'show_in_nav_menus' => FALSE,
                 'query_var'         => TRUE,
                 'rewrite'           => array( 'slug' => 'event-tag' ),
                 'hierarchical'      => FALSE
            )
        );
    }


    /**
     * Customize the frontend Events Query using Post Meta
     *
     * @param object $query data
     */
    public function events_query( $query )
    {
        // http://codex.wordpress.org/Function_Reference/current_time
        $current_time = current_time( 'timestamp' );

        global $wp_the_query;

        // Non-Admin Listing
        if ( $wp_the_query === $query && !is_admin() && is_post_type_archive( $this->plugin_post_type ) )
        {
            $meta_query = array(
                array(
                    'key'     => $this->plugin_custom_field_prefix . '_end_date',
                    'value'   => $current_time,
                    'compare' => '>'
                )
            );
            $query->set( 'meta_query', $meta_query );
            $query->set( 'orderby', 'meta_value_num' );
            $query->set( 'meta_key', $this->plugin_custom_field_prefix . '_start_date' );
            $query->set( 'order', 'ASC' );
        }

    }


    /**
     * Register site widget sidebars
     */
    public function events_widget_areas_init()
    {
        register_sidebar(
            array(
                 'name'          => __( 'Events Sidebar', 'events-sidebar' ),
                 'id'            => 'events-sidebar',
                 'before_widget' => '<aside class="events-sidebar">',
                 'after_widget'  => '</aside>',
                 'before_title'  => NULL,
                 'after_title'   => NULL
            )
        );
    }


    /**
     * Register a Events widget.
     */
    public function events_widgets_register()
    {
        register_widget( 'NDS_WordPress_Upcoming_Events_Widget' );
    }

}