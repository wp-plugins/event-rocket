<?php
defined( 'ABSPATH' ) or exit();


class EventRocket_EmbedEventsShortcode
{
	// Inputs
	protected $params = array();
	protected $content = '';

	// Positive posts/terms to query against
	protected $events = array();
	protected $venues = array();
	protected $organizers = array();
	protected $categories = array();
	protected $tags = array();

	// Negative posts/terms to query against
	protected $ignore_events = array();
	protected $ignore_venues = array();
	protected $ignore_organizers = array();
	protected $ignore_categories = array();
	protected $ignore_tags = array();

	// Miscellaneous conditions
	protected $tax_logic = 'OR';
	protected $from = '';
	protected $to = '';
	protected $limit = 20;
	protected $template = '';

	// Caching
	protected $cache_key = '';
	protected $cache_expiry = 0;

	// Nothing found fallbacks
	protected $nothing_found_text = '';
	protected $nothing_found_template = '';

	// Internal
	protected $args = array();
	protected $results = array();
	protected $event_post;
	protected $output = '';


	/**
	 * Sets up the [event_embed] shortcode.
	 *
	 * The actual shortcode name can be changed from "event_embed" to pretty much anything, using
	 * the eventrocket_embed_events_shortcode_name filter.
	 */
	public function __construct() {
		$shortcode = apply_filters( 'eventrocket_embed_events_shortcode_name', 'event_embed' );
		add_shortcode( $shortcode, array( $this, 'embed' ) );
	}

	/**
	 * Provides an alternative means of querying for events: any results that are found are
	 * returned in an array (which may be empty, if nothing is found).
	 *
	 * @param array $params
	 * @param string $content
	 * @return array
	 */
	public function obtain( array $params, $content = '' ) {
		$this->embed( $params, $content );
		return $this->results;
	}

	/**
	 * Provides a programmatic means of embedding events. The output is returned as a string.
	 *
	 * @param array $params
	 * @param string $content
	 * @return string
	 */
	public function get( array $params, $content = '' ) {
		return $this->embed( $params, $content );
	}

	/**
	 * Provides a programmatic means of embedding events. The output is printed directly.
	 *
	 * @param array $params
	 * @param string $content
	 */
	public function render( array $params, $content = '' ) {
		echo $this->embed( $params, $content );
	}

	/**
	 * Embedded events request and shortcode handler.
	 *
	 * @param $params
	 * @param $content
	 * @return string
	 */
	public function embed( $params, $content ) {
		if ( ! empty( $params ) && is_array( $params ) ) $this->params = $params;
		$this->content = trim( $content );
		$this->execute();
		return $this->output;
	}

	/**
	 * Parse the provided parameters, run the resulting query and build the output.
	 * Allows for retrieval of cached results where appropriate.
	 */
	protected function execute() {
		$this->parse();

		if ( ! $this->cache_get() ) {
			$this->query();
			$this->build();
		}
	}

	/**
	 * Pre-process and get what we need from any parameters that were provided.
	 */
	protected function parse() {
		$this->collect_post_tax_refs();
		$this->separate_ignore_values();
		$this->parse_post_tax_refs();
		$this->set_time_constraints();
		$this->set_limit();
		$this->set_template();
		$this->set_fallbacks();
		$this->set_cache();
	}

	/**
	 * The user can use singular or plural forms to describe the events, categories
	 * and tags they are interested in querying against: this method simply looks
	 * for one or other - or both - and forms a single list of each.
	 */
	protected function collect_post_tax_refs() {
		$this->events = $this->plural_prop_csv( 'event', 'events' );
		$this->venues = $this->plural_prop_csv( 'venue', 'venues' );
		$this->organizers = $this->plural_prop_csv( 'organizer', 'organizers' );
		$this->categories = $this->plural_prop_csv( 'category', 'categories' );
		$this->tags = $this->plural_prop_csv( 'tag', 'tags' );
	}

	/**
	 * The event and taxonomy params can include "negative" or ignore values indicating
	 * posts or terms to ignore. This method separates the negatives out into a seperate
	 * set of lists.
	 */
	protected function separate_ignore_values() {
		$this->move_ignore_vals( $this->events, $this->ignore_events );
		$this->move_ignore_vals( $this->venues, $this->ignore_venues );
		$this->move_ignore_vals( $this->organizers, $this->ignore_organizers );
		$this->move_ignore_vals( $this->categories, $this->ignore_categories );
		$this->move_ignore_vals( $this->tags, $this->ignore_tags );
	}

	/**
	 * Moves any values in $list prefixed with a negative operator ("-") to the
	 * ignore list.
	 *
	 * @param array $list
	 * @param array $ignore_list
	 */
	protected function move_ignore_vals( array &$list, array &$ignore_list ) {
		$keep_list = array();

		foreach ( $list as $value ) {
			if ( 0 === strpos( $value, '-') ) $ignore_list[] = substr( $value, 1 );
			else $keep_list[] = $value;
		}

		$list = $keep_list;
	}

	/**
	 * The event and taxonomy params all accept a mix of IDs and slugs:
	 * this method converts any slugs in those params back into IDs.
	 */
	protected function parse_post_tax_refs() {
		$this->parse_post_refs( $this->events );
		$this->parse_post_refs( $this->ignore_events );

		$this->parse_post_refs( $this->venues, TribeEvents::VENUE_POST_TYPE );
		$this->parse_post_refs( $this->ignore_venues, TribeEvents::VENUE_POST_TYPE );

		$this->parse_post_refs( $this->organizers, TribeEvents::ORGANIZER_POST_TYPE );
		$this->parse_post_refs( $this->ignore_organizers, TribeEvents::ORGANIZER_POST_TYPE );

		$this->parse_tax_refs( $this->categories, TribeEvents::TAXONOMY );
		$this->parse_tax_refs( $this->ignore_categories, TribeEvents::TAXONOMY );

		$this->parse_tax_refs( $this->tags, 'post_tag' );
		$this->parse_tax_refs( $this->ignore_tags, 'post_tag' );

		// Default to an "OR" relationship between different tax queries, but allow for "AND"
		if ( isset( $this->params['logic'] ) && 'and' === strtolower( $this->params['logic'] ) )
			$this->tax_logic = 'AND';
	}

	/**
	 * Process the list of posts, turning any slugs into IDs.
	 *
	 * @param $list
	 * @param string $type
	 */
	protected function parse_post_refs( &$list, $type = TribeEvents::POSTTYPE ) {
		foreach ( $list as $index => $reference ) {
			$this->typify( $reference );
			if ( ! is_string( $reference ) ) continue;

			$event = get_posts( array(
				'name' => $reference,
				'post_type' => $type,
				'eventDisplay' => 'custom',
				'posts_per_page' => 1
			) );

			if ( empty( $event ) || ! is_array( $event ) ) $list[$index] = 0;
			else $list[$index] = $event[0]->ID;
		}
	}

	/**
	 * Process the list of terms for the specified taxonomy, converting
	 * any term slugs into term IDs and grouping terms together where
	 * an AND condition should be applied.
	 *
	 * @param $list
	 * @param $taxonomy
	 */
	protected function parse_tax_refs( &$list, $taxonomy ) {
		foreach ( $list as $index => $term ) {
			// Convert each list item to an array
			$list[$index] = array();

			// Each "term" may actually be multiple terms, joined via the "+" symbol to mark an "AND" condition
			$terms = explode( '+', $term );

			// Look at each term reference: convert slugs to numeric IDs and group terms together as needed
			foreach ( $terms as $term_ref ) {
				$this->typify( $term_ref ); // Convert numeric strings to actual integers, etc

				// If an integer, do not process further - just add it to the list
				if ( is_int( $term_ref ) ) {
					$list[$index][] = $term_ref;
				}
				// If a string, convert to an integer (ie, get the term ID) - then add to the list
				else {
					$term = get_term_by( 'slug', $term_ref, $taxonomy);
					if ( false === $term ) $list[$index][] = 0;
					else $list[$index][] = (int) $term->term_id;
				}
			}
		}
	}

	/**
	 * Inspect the properties array for values assigned with either the $singular or $plural
	 * key: combine and extract the values, returning them as an array (may be empty if
	 * for instance no such shortcode params were supplied).
	 *
	 * @param $singular
	 * @param $plural
	 * @return array
	 */
	protected function plural_prop_csv( $singular, $plural ) {
		$singular = isset( $this->params[$singular] ) ? (string) $this->params[$singular] : '';
		$plural = isset( $this->params[$plural] ) ? (string) $this->params[$plural] : '';
		$combined = "$singular,$plural";

		$values = explode( ',', $combined );
		$result_set = array();

		foreach ( $values as $value ) {
			$value = trim( $value );
			if ( ! empty($value) && ! in_array( $value, $result_set ) )
				$result_set[] = trim($value);
		}

		return $result_set;
	}

	/**
	 * Looks for time (from/to) parameters, ensuring they are in a form we like.
	 */
	protected function set_time_constraints() {
		if ( isset( $this->params['from'] ) ) $this->time_from();
		if ( isset( $this->params['to' ] ) ) $this->time_to();
	}

	/**
	 * Ensure the from param is a well formed date. Convert to a standard format where possible
	 * and store.
	 */
	protected function time_from() {
		$datetime = strtotime( $this->params['from'] );
		if ( ! $datetime ) $this->from = '';
		else $this->from = date( 'Y-m-d H:i:s', $datetime );
	}

	/**
	 * Ensure the to param is a well formed date. Convert to a standard format where possible
	 * and store.
	 */
	protected function time_to() {
		$datetime = strtotime( $this->params['to'] );
		if ( ! $datetime ) $this->to = '';
		else $this->to = date( 'Y-m-d H:i:s', $datetime );
	}

	/**
	 * Set the number of posts to retreive
	 */
	protected function set_limit() {
		$this->limit = isset( $this->params['limit'] )
			? (int) $this->params['limit']
			: (int) get_option( 'posts_per_page', 20 );
	}

	/**
	 * Set the template to use.
	 *
	 * The template can live in the core The Events Calendar views directory, or else in the
	 * theme/child theme, or can be an absolute path.
	 */
	protected function set_template() {
		$this->template = ''; // Wipe clean
		$fallback = EVENTROCKET_INC . '/templates/embedded-events.php';

		// If there is no template and no inner content, assume the regular single event template
		if ( ! isset( $this->params['template'] ) && empty( $this->content ) ) $this->template = $fallback;
		elseif ( ! isset( $this->params['template'] ) ) return;

		// If not an absolute filepath use Tribe's template finder
		if ( isset( $this->params['template'] ) && 0 !== strpos( $this->params['template'], '/' ) )
			$this->template = TribeEventsTemplates::getTemplateHierarchy( $this->params['template'] );

		// Ensure the template exists
		if ( ! $this->template && file_exists( $this->params['template'] ) )
			$this->template = $this->params['template'];
	}

	/**
	 * Set the message to display - or template to pull in - should no results be found.
	 */
	protected function set_fallbacks() {
		// Has a (usually short) piece of text been provided, ie "Nothing found"?
		if ( isset( $this->params['nothing_found_text'] ) && is_string( $this->params['nothing_found_text'] ) )
			$this->nothing_found_text = $this->params['nothing_found_text'];

		// Has a template path been provided?
		if ( ! isset( $this->params['nothing_found_template'] ) ) return;

		// If not an absolute filepath use Tribe's template finder
		if ( isset( $this->params['nothing_found_template'] ) && 0 !== strpos( $this->params['nothing_found_template'], '/' ) )
			$this->nothing_found_template = TribeEventsTemplates::getTemplateHierarchy( $this->params['nothing_found_template'] );

		// Ensure the template exists
		if ( ! $this->nothing_found_template && file_exists( $this->params['nothing_found_template'] ) )
			$this->nothing_found_template = $this->params['nothing_found_template'];
	}

	/**
	 * Determines if the output should be cached.
	 */
	protected function set_cache() {
		// Has a cache param been set?
		$cache = isset( $this->params['cache'] ) ? $this->params['cache'] : null;
		$cache = apply_filters( 'eventrocket_embed_event_cache_expiry', $cache, $this->params );

		// No caching? Bail
		if ( null === $cache ) return;

		// Cache for the default period?
		if ( 'auto' === strtolower( $cache ) || 'on' === strtolower( $cache ) )
			$this->cache_expiry = (int) apply_filters( 'eventrocket_embed_event_cache_default_value', HOUR_IN_SECONDS * 2 );

		// Cache for a specified amount of time?
		elseif ( is_numeric( $cache ) && $cache == absint( $cache ) )
			$this->cache_expiry = absint( $cache );

		// Create the cache key
		$this->cache_key = hash( 'md5', join( '|', $this->params ) );
	}

	/**
	 * Accepts a value and if it appears to be a string it is returned as-is. If it
	 * appears to be a number expressed as a string then it is converted to an int
	 * and, if it is numeric, it is simply returned as an int.
	 *
	 * @param $value
	 */
	protected function typify( &$value ) {
		$value = is_numeric( $value ) ? (int) $value : (string) $value;
	}

	/**
	 * Retrieve the events based on the parameters provided.
	 */
	protected function query() {
		$this->args = array( 'post_type' => TribeEvents::POSTTYPE ); // Reset
		$this->args_post_tax();
		$this->args_venue_organizer();
		$this->args_time();
		$this->args_limit();
		$this->args_display_type();
		$this->args = apply_filters( 'eventrocket_embed_event_args', $this->args, $this->params );
		$this->results = tribe_get_events( $this->args );
	}

	/**
	 * Populate the post (event) and taxonomy query arguments.
	 */
	protected function args_post_tax() {
		$tax_args = array();

		if ( ! empty( $this->events ) ) $this->args['post__in'] = $this->events;
		if ( ! empty( $this->ignore_events ) ) $this->args['post__not_in'] = $this->ignore_events;

		if ( ! empty( $this->categories ) )
			$this->build_tax_args( $tax_args, TribeEvents::TAXONOMY, $this->categories );

		if ( ! empty( $this->ignore_categories ) )
			$this->build_tax_args( $tax_args, TribeEvents::TAXONOMY, $this->ignore_categories, true );

		/*if ( ! empty( $this->tags) ) $this->args['tag__in'] = $this->tags[0];
		if ( ! empty( $this->ignore_tags ) ) $this->args['tag__not_in'] = $this->ignore_tags[0];*/

		if ( ! empty( $this->tags) )
			$this->build_tax_args( $tax_args, 'post_tag', $this->tags );

		if ( ! empty( $this->ignore_tags ) )
			$this->build_tax_args( $tax_args, 'post_tag', $this->ignore_tags, true );

		if ( ! empty( $tax_args ) ) {
			$tax_args['relation'] = $this->tax_logic;
			$this->args['tax_query'] = $tax_args;
		}
	}

	/**
	 * Helper that puts together a set of tax query arguments for a term or group of terms.
	 *
	 * @param array $tax_args
	 * @param $taxonomy
	 * @param $term_set
	 * @param $exclude
	 */
	protected function build_tax_args( array &$tax_args, $taxonomy, $term_set, $exclude = false ) {
		foreach ( $term_set as $terms ) {
			$operator = $exclude ? 'NOT IN' : ( count( $terms ) > 1 ? 'AND' : 'IN' );

			$tax_args[] = array(
				'taxonomy' => $taxonomy,
				'field'    => 'id',
				'terms'    => $terms,
				'operator' => $operator
			);
		}
	}

	protected function args_venue_organizer() {
		$meta_queries = array();

		if ( ! empty( $this->venues ) )
			$meta_queries[] = $this->form_meta_arg( '_EventVenueID', $this->venues, 'IN' );

		if ( ! empty( $this->ignore_venues ) )
			$meta_queries[] = $this->form_meta_arg( '_EventVenueID', $this->ignore_venues, 'NOT IN' );

		if ( ! empty( $this->organizers ) )
			$meta_queries[] = $this->form_meta_arg( '_EventOrganizerID', $this->organizers, 'IN' );

		if ( ! empty( $this->ignore_organizers ) )
			$meta_queries[] = $this->form_meta_arg( '_EventOrganizerID', $this->ignore_organizers, 'NOT IN' );

		if ( ! isset( $this->args['meta_query'] ) ) $this->args['meta_query'] = $meta_queries;
		else $this->args['meta_query'] = array_merge( $meta_queries, $this->args['meta_query'] );
	}

	protected function form_meta_arg( $key, $value, $compare ) {
		return array(
			'key' => $key,
			'value' => $value,
			'compare' => $compare
		);
	}

	protected function args_time() {
		if ( ! empty( $this->from ) ) $this->args['start_date'] = $this->from;
		if ( ! empty( $this->to ) ) $this->args['end_date'] = $this->to;
	}

	protected function args_limit() {
		$this->args['posts_per_page'] = $this->limit;
	}

	/**
	 * Set the eventDisplay query argument appropriately.
	 */
	protected function args_display_type() {
		$this->args['eventDisplay'] = ( isset( $this->args['start_date'] ) || isset( $this->args['end_date'] ) || isset( $this->args['post__in'] ) )
			? 'custom' : 'list';
	}

	/**
	 * Take the query result set and build the actual output.
	 */
	protected function build() {
		if ( ! empty( $this->results ) ) $this->build_normal();
		else $this->build_no_results();
	}

	/**
	 * Builds the output when we have some results from the query.
	 */
	protected function build_normal() {
		ob_start();
		foreach ( $this->results as $this->event_post ) $this->build_item();
		$this->output = ob_get_clean();
		$this->output = apply_filters( 'eventrocket_embed_event_output', $this->output );
		if ( $this->cache_expiry && $this->cache_key ) $this->cache_output();
	}

	/**
	 * Stores the generated output in the cache.
	 */
	protected function cache_output() {
		set_transient( $this->cache_key, $this->output, $this->cache_expiry );
	}

	/**
	 * @return bool
	 */
	protected function cache_get() {
		if ( ! $this->cache_expiry ) return false;
		$cached_output = get_transient( $this->cache_key );

		if ( ! $cached_output ) return false;
		$this->output = $cached_output;
		return true;
	}

	/**
	 * Builds the output where no results were returned.
	 */
	protected function build_no_results() {
		if ( ! empty( $this->nothing_found_text ) )
			$this->output = apply_filters( 'eventrocket_embed_event_output', $this->nothing_found_text );

		elseif ( ! empty( $this->nothing_found_template ) ) {
			ob_start();
			include $this->nothing_found_template;
			$this->output = ob_get_clean();
			$this->output = apply_filters( 'eventrocket_embed_event_output', $this->output );
		}
	}

	/**
	 * Decide whether to pull in a template to render each event or to use
	 * an inline template.
	 */
	protected function build_item() {
		if ( ! is_a( $this->event_post, 'WP_Post' ) ) return;
		$GLOBALS['post'] = $this->event_post;
		setup_postdata( $GLOBALS['post'] );
		ob_start();

		if ( ! empty( $this->template ) ) include $this->template;
		elseif ( ! empty( $this->content ) ) $this->build_inline_output();

		echo apply_filters( 'eventrocket_embed_event_single_output', ob_get_clean(), get_the_ID() );
		wp_reset_postdata();
	}

	protected function build_inline_output() {
		static $parser = null;
		if ( null === $parser ) $parser = new EventRocket_EmbeddedEventTemplateParser;
		$parser->process( $this->content );
		print do_shortcode( $parser->output );
	}
}

/**
 * @return EventRocket_EmbedEventsShortcode
 */
function event_embed() {
	static $object = null;
	if ( null === $object ) $object = new EventRocket_EmbedEventsShortcode;
	return $object;
}

// Call once to ensure the [event-embed] object is created
event_embed();