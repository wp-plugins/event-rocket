<?php
class EventRocketEmbedEventsShortcode
{
	protected $params = array();
	protected $content = '';
	protected $events = array();
	protected $ignore_events = array();
	protected $categories = array();
	protected $ignore_categories = array();
	protected $tags = array();
	protected $ignore_tags = array();
	protected $from = '';
	protected $to = '';
	protected $limit = 20;
	protected $template = '';
	protected $args = array();
	protected $results = array();
	protected $event_post;
	protected $output = '';


	public function __construct() {
		$shortcode = apply_filters( 'eventrocket_embed_events_shortcode_name', 'event_embed' );
		add_shortcode( $shortcode, array( $this, 'shortcode' ) );
	}

	/**
	 * Provides a programmatic means of using the event embed shortcode, returning
	 * the shortcode output as a string.
	 *
	 * @param array $params
	 * @param string $content
	 * @return string
	 */
	public function get( array $params, $content = '' ) {
		return $this->shortcode( $params, $content );
	}

	/**
	 * Provides a programmatic means of using the event embed shortcode, printing
	 * the shortcode output directly.
	 *
	 * @param array $params
	 * @param string $content
	 */
	public function render( array $params, $content = '' ) {
		echo $this->shortcode( $params, $content );
	}

	/**
	 * Shortcode handler.
	 *
	 * @param $params
	 * @param $content
	 * @return string
	 */
	public function shortcode( $params, $content ) {
		if ( ! empty( $params ) && is_array( $params ) ) $this->params = $params;
		$this->content = trim( $content );
		$this->execute();
		return $this->output;
	}

	/**
	 * Parse the provided parameters, run the resulting query and build the output.
	 */
	protected function execute() {
		$this->parse();
		$this->query();
		$this->build();
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
	}

	/**
	 * The user can use singular or plural forms to describe the events, categories
	 * and tags they are interested in querying against: this method simply looks
	 * for one or other - or both - and forms a single list of each.
	 */
	protected function collect_post_tax_refs() {
		$this->events = $this->plural_prop_csv( 'event', 'events' );
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

		$this->parse_tax_refs( $this->categories, TribeEvents::TAXONOMY );
		$this->parse_tax_refs( $this->ignore_categories, TribeEvents::TAXONOMY );

		$this->parse_tax_refs( $this->tags, 'post_tag' );
		$this->parse_tax_refs( $this->ignore_tags, 'post_tag' );
	}

	/**
	 * Process the list of posts, turning any slugs into IDs.
	 *
	 * @param $list
	 */
	protected function parse_post_refs( &$list ) {
		foreach ( $list as $index => $reference ) {
			$this->typify( $reference );
			if ( ! is_string( $reference ) ) continue;

			$event = get_posts( array(
				'name' => $reference,
				'post_type' => TribeEvents::POSTTYPE,
				'eventDisplay' => 'custom',
				'posts_per_page' => 1
			) );

			if ( empty( $event ) || ! is_array( $event ) ) $list[$index] = 0;
			else $list[$index] = $event[0]->ID;
		}
	}

	/**
	 * Process the list of terms for the specified taxonomy, converting
	 * any term slugs into term IDs.
	 *
	 * @param $list
	 * @param $taxonomy
	 */
	protected function parse_tax_refs( &$list, $taxonomy ) {
		foreach ( $list as $index => $reference ) {
			$this->typify( $reference );
			if ( ! is_string( $reference ) ) continue;

			$term = get_term_by( 'slug', $reference, $taxonomy );
			if ( false === $term ) $list[$index] = 0;
			else $list[$index] = (int) $term->term_id;
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
		$fallback = EVENTROCKET_INC . '/shortcodes/embed-events-template.php';

		// If there is no template and no inner content, assume the regular single event template
		if ( ! isset( $this->params['template'] ) && empty( $this->content ) ) $this->template = $fallback;
		elseif ( ! isset( $this->params['template'] ) ) return;

		// If not an absolute filepath use Tribe's template finder
		if ( isset( $this->params['template'] ) && 0 !== strpos( $this->params['template'], '/' ) )
			$this->template = TribeEventsTemplates::getTemplateHierarchy( $this->params['template'] );

		// Ensure the template exists
		if ( ! $this->template && file_exists( $this->params['template'] ) )
			$this->template = (string) $this->params['template'];
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
		$this->args_time();
		$this->args_limit();
		$this->args_display_type();
		$this->results = tribe_get_events( $this->args );
	}

	/**
	 * Populate the post (event) and taxonomy query arguments.
	 */
	protected function args_post_tax() {
		$tax_args = array();

		if ( ! empty( $this->events ) ) $this->args['post__in'] = $this->events;
		if ( ! empty( $this->ignore_events ) ) $this->args['post__not_in'] = $this->ignore_events;

		if ( ! empty( $this->categories ) ) $tax_args[] = array(
			'taxonomy' => TribeEvents::TAXONOMY,
			'field' => 'id',
			'terms' => $this->categories
		);

		if ( ! empty( $this->ignore_categories ) ) $tax_args[] = array(
			'taxonomy' => TribeEvents::TAXONOMY,
			'field' => 'id',
			'terms' => $this->ignore_categories,
			'operator' => 'NOT IN'
		);

		if ( ! empty( $this->tags) ) $this->args['tag__in'] = $this->tags;
		if ( ! empty( $this->ignore_tags ) ) $this->args['tag__not_in'] = $this->ignore_tags;

		if ( ! empty( $tax_args ) ) $this->args['tax_query'] = $tax_args;
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
		$this->args['eventDisplay'] = ( isset( $this->args['start_date'] ) || isset( $this->args['end_date'] ) )
			? 'custom' : 'upcoming';
	}

	/**
	 * Take the query result set and build the actual output.
	 */
	protected function build() {
		ob_start();
		foreach ( $this->results as $this->event_post ) $this->build_item();
		$this->output = ob_get_clean();
		$this->output = apply_filters( 'eventrocket_embed_event_output', $this->output );
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
		if ( null === $parser ) $parser = new EventRocketEmbeddedEventTemplateParser;
		$parser->process( $this->content );
		print do_shortcode( $parser->output );
	}
}

// Set the shortcode up and/or possibly define the event_embed() helper
if ( function_exists( 'event_embed' ) ) new EventRocketEmbedEventsShortcode;
else return;

/**
 * @return EventRocketEmbedEventsShortcode
 */
function event_embed() {
	static $object = null;
	if ( null === $object ) $object = new EventRocketEmbedEventsShortcode;
	return $object;
}

// Call once to ensure the [event-embed] object is created
event_embed();