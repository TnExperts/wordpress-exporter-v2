<?php
/**
 * WXR Export API
 *
 * @package Export
 * @subpackage WXR
 */

require_once dirname( __FILE__ ) . '/class-wp-export-content.php';
require_once dirname( __FILE__ ) . '/class-wp-xmlwriter.php';

/**
 * Export to a WXR instance.
 *
 * @todo Write more detailed documentation on what plugins are allowed/not allowed to do
 * when hooking into all the actions that allow them to add extension markup.  I think
 * handbook-level of detail is required.
 */
class WXR_Exporter {
	/**
	 * The version of WXR.
	 *
	 * @var string
	 */
	const WXR_VERSION = '1.3';

	/**
	 * The version of RSS.
	 *
	 * @var string
	 */
	const RSS_VERSION = '2.0';

	/**
	 * The WXR namespace URI.
	 *
	 * @var string
	 */
	const WXR_NAMESPACE_URI = 'http://wordpress.org/export/';

	/**
	 * The Dublin Core namespace URI.
	 *
	 * @var string
	 */
	const DUBLIN_CORE_NAMESPACE_URI = 'http://purl.org/dc/elements/1.1/';

	/**
	 * The RSS Content profile namespace URI.
	 *
	 * @var string
	 */
	const RSS_CONTENT_NAMESPACE_URI = 'http://purl.org/rss/1.0/modules/content/';

	/**
	 * The namespace prefix for the WXR Namespace.
	 *
	 * @var string
	 */
	const WXR_PREFIX = 'wxr';

	/**
	 * The namespace prefix for the Dublin Core Namespace
	 *
	 * @var string
	 */
	const DUBLIN_CORE_PREFIX = 'dc';

	/**
	 * The namespace prefix for the RSS Content Profile Namespace
	 *
	 * @var string
	 */
	const RSS_CONTENT_PREFIX = 'content';

	/**
	 * Export filters (i.e., what to export)
	 *
	 * @var array
	 */
	protected $filters;

	/**
	 * The content to export.
	 *
	 * @var WP_Export_Content
	 */
	protected $export_content;

	/**
	 * The writer to write to.
	 *
	 * @var WP_XMLWriter
	 */
	protected $writer;

	/**
	 *
	 * @var WP_Exporter_Logger
	 */
	protected $logger;

	/*
	 * @todo consider not output ANY element that has no content (and adjusting
	 * the schema, if necessary) to save space...as long as the importer would
	 * be able to properly import the instance; e.g., item/wxr:password, etc.
	 */

	/**
	 * Constructor
	 *
	 * @param array $filters
	 *
	 * @todo error checking on new WP_Export_Content() and new WP_XMLWriter()
	 */
	function __construct ( $filters ) {
		libxml_use_internal_errors( true );

		add_filter( 'wxr_export_skip_postmeta', array( __CLASS__, 'filter_postmeta' ), 10, 2 );
		add_filter( 'wxr_export_skip_usermeta', array( __CLASS__, 'filter_usermeta' ), 10, 2 );

		$this->filters = $filters;
		$this->export_content = new WP_Export_Content( $filters );
		$this->writer = new WP_XMLWriter( array( 'encoding' => $this->export_content->get_encoding() ) );
	}

	/**
	 * Destructor.
	 *
	 * Clear any libxml errors.
	 */
	function __destruct() {
		libxml_clear_errors();
	}

	function get_encoding() {
		return $this->export_content->get_encoding();
	}

	function user_ids() {
		return $this->export_content->user_ids();
	}

	function term_ids() {
		return $this->export_content->term_ids();
	}

	function link_ids() {
		return $this->export_content->link_ids();
	}

	function post_ids() {
		return $this->export_content->post_ids();
	}

	function media_ids() {
		return $this->export_content->media_ids();
	}

	function get_counts() {
		return $this->export_content->get_counts();
	}

	public function set_logger( $logger ) {
		$this->logger = $logger;
	}

	/**
	 * Generate the WXR export.
	 *
	 * @param string $file The file to write to.  If `$file` is "php://output"
	 * 					   will write to standard out; if `$file` is null,
	 * 					   will write to a string in memory (which can be
	 * 					   retrieved with WP_XMLWriter::flush() or
	 * 					   WP_XMLWriter::outputMemory()).
	 * @return int|string If writer opened with a filename, the number of bytes
	 * 					  written (which may be 0);  If writer opened for memory
	 * 					  buffer, the current contents of the memory buffer.
	 */
	function export( $file = null ) {
		/**
		 * Fires at the beginning of an export, before any headers are sent.
		 *
		 * @since 2.3.0
		 *
		 * @param array $filters An array of export $filters.
		 */
		do_action( 'export_wp', $this->filters );

		$this->open( $file) ;

		$this->write_before_rss();

		$this->write_rss();

		return $this->writer->endDocument();
	}

	/**
	 * Open a file to write to.
	 *
	 * @see WP_XMLWriter::openDocument().
	 *
	 * @param string $file The file to write to.
	 * @return bool True on success, false on error.
	 */
	protected function open( $file ) {
		if ( ! $this->writer->openDocument( $file ) ) {
			return false;
		}

		$this->writer->setIndent( true );
		$this->writer->setIndentString( "\t" );

		return true;
	}

	/**
	 * Write everything before the RSS root element.
	 */
	protected function write_before_rss() {
		$this->writer->writeXmlDecl( '1.0', $this->export_content->get_encoding() );

		$this->writer->writeComment( '
	This is a WordPress eXtended RSS file generated by WordPress as an export of your site.
	It contains information about your site\'s posts, pages, comments, categories, and other content.
	You may use this file to transfer that content from one site to another.
	This file is not intended to serve as a complete backup of your site.
	To import this information into a WordPress site follow these steps:
	1. Log in to that site as an administrator.
	2. Go to Tools: Import in the WordPress admin panel.
	3. Install the "WordPress" importer from the list.
	4. Activate & Run Importer.
	5. Upload this file using the form provided on that page.
	6. You will first be asked to map the authors in this export file to users
	   on the site. For each author, you may choose to map to an
	   existing user on the site or to create a new user.
	7. WordPress will then import each of the posts, pages, comments, categories, etc.
	   contained in this file into your site.
');
	}

	/**
	 * Write the RSS root element, it's attributes and children
	 *
	 * @return bool True on success, false on error.
	 */
	protected function write_rss() {
		$attrs = array(
			'version' => self::RSS_VERSION,
			// this would be sooooo much easier if PHP allowed
			// class const's in interpolated strings :-(
			'Q{' . self::WXR_NAMESPACE_URI . "}version" => self::WXR_VERSION,
		);
		$nsDecls = array(
			self::WXR_PREFIX => self::WXR_NAMESPACE_URI,
			self::DUBLIN_CORE_PREFIX => self::DUBLIN_CORE_NAMESPACE_URI,
			self::RSS_CONTENT_PREFIX => self::RSS_CONTENT_NAMESPACE_URI,
		);

		/**
		 * Plugins that expect to output extension elements.  The importer can use
		 * the plugin's $slug & $url to warn users who perform an import that some
		 * information in the export won't be imported unless these plugins are installed and
		 * activated.
		 *
		 * @since x.y.z
		 *
		 * @todo The $plugins param is actually an array of the hash below.  As far as I know,
		 * there is no convention for this in the PHP Documentation Standards
		 * (https://make.wordpress.org/core/handbook/best-practices/inline-documentation-standards/php/#1-1-parameters-that-are-arrays)

		 * @param array $plugins {
		 *     @type string $prefix Our "preferred" namespace prefix.
		 *     @type string $namespace-uri The namespaceURI for our extension elements/attributes.
		 * }
		 * @param array $filters
		 */
		$ext_namespaces = apply_filters( 'wxr_export_extension_namespaces', array(), $this->filters );

		foreach ( $ext_namespaces as $ext_namespace ) {
			if ( ! isset( $ext_namespace['namespace-uri'] ) ||
					in_array( $ext_namespace['namespace-uri'], array( null, '', self::WXR_NAMESPACE_URI ) ) ) {
				// plugins are not allowed to use the empty namespace (i.e., RSS's namespace)
				// nor the WXR namespace
				continue;
			}

			// uniqueify the prefix
			$ext_namespace['prefix'] = $this->unique_prefix( $ext_namespace['prefix'], $ext_namespace['namespace-uri'] );

			$nsDecls[$ext_namespace['prefix']] = $ext_namespace['namespace-uri'];
		}

		foreach ( $nsDecls as $prefix => $uri ) {
			$attrs["xmlns:{$prefix}"] = $uri;
		}
		$this->writer->startElement( 'rss', $attrs );

		$this->write_channel();

		$this->writer->endElement();// </rss>

		$extention_markup_output = apply_filters( 'wxr_export_extension_markup', array() );
		foreach ( $extention_markup_output as $ext_namespace ) {
			// add a PI so the importer can inform the user that about
			// possible extension markup added by specific plugins
			$required = array(
				'namespace-uri',
				'plugin-name',
				'plugin-slug',
				'plugin-uri',
			);
			$content = '';
			$valid = true;
			foreach ( $required as $key ) {
				if ( empty( $ext_namespace[$key] ) ) {
					$valid = false;
					break;
				}
				else {
					$content .= "$key='{$ext_namespace[$key]}' ";
				}
			}
			if ( ! $valid ) {
				continue;
			}

			$this->writer->writePI( 'WXR_Importer', $content );
		}

		return true;
	}

	/**
	 * Write the RSS channel element and it's children
	 */
	protected function write_channel() {
		$this->writer->startElement( 'channel' );

		$site_metadata = $this->export_content->site_metadata();

		$this->writer->writeElement( 'title', $site_metadata['name'] );
		$this->writer->writeElement( 'link', $site_metadata['url'] );
		$this->writer->writeElement( 'description', $site_metadata['description'] );
		$this->writer->writeElement( 'pubDate', $site_metadata['pubDate'] );
		$this->writer->writeElement( 'language', $site_metadata['language'] );

		// this writes self::WXR_NAMESPACE_URI as the content of <docs>,
		// not <wxr:docs>
		$this->writer->writeElement( 'docs', self::WXR_NAMESPACE_URI );
		$this->writer->writeElement( 'generator', $site_metadata['generator'],
			array( 'Q{' . self::WXR_NAMESPACE_URI . '}wp_version' => $site_metadata['wp_version'] ) );

		if ( is_multisite() ) {
			$this->write_wxr_element( 'site_url', $site_metadata['site_url'] ) ;
		}

		try {
			foreach ( $this->export_content->users() as $user ) {
				$this->write_user( $user );
			}
		}
		catch ( WP_Iterator_Exception $exception ) {
			// @todo should we do anything with this exception?
		}

		try {
			foreach ( $this->export_content->terms() as $term ) {
				$this->write_term( $term );
			}
		}
		catch ( WP_Iterator_Exception $exception ) {
			// @todo should we do anything with this exception?
		}

		try {
			foreach ( $this->export_content->links() as $link ) {
				$this->write_link( $link );
			}
		}
		catch ( WP_Iterator_Exception $exception ) {
			// @todo should we do anything with this exception?
		}

		/**
		 * blah, blah, blah
		 *
		 * Functions hooked to this action SHOULD generally output elements
		 * in the their own namespace (which SHOULD be registered with the
		 * wxr_export_plugins action).
		 *
		 * The only time plugins are allowed to output elements/attributes in
		 * the WXR namespace is if they have also hooked into the 'export_filters'
		 * action to produce a completely custom export (e.g., to export ONLY
		 * terms, users, etc).
		 *
		 * If they output elements in the empty namespace then those elements
		 * MUST conform to the RSS 2.0 spec and the RSS Advisory Board's Best Practices
		 * Profile (@link http://www.rssboard.org/rss-profile) AND not be among
		 * those RSS elements that are already output by this exporter.
		 *
		 * @since x.y.z
		 *
		 * @param WP_XMLWriter $writer The WP_XMLWriter instance to write to.
		 * @param array $args The arguments passed to export_wp().
		 */
		do_action( 'wxr_export_rss_channel', $this->writer, $this->filters );

		try {
			foreach ( $this->export_content->posts() as $post ) {
				$this->write_post( $post );
			}
		}
		catch ( WP_Iterator_Exception $exception ) {
			// @todo should we do anything with this exception?
		}

		try {
			foreach ( $this->export_content->media() as $media ) {
				$this->write_post( $media );
			}
		}
		catch ( WP_Iterator_Exception $exception ) {
			// @todo should we do anything with this exception?
		}

		$this->writer->endElement();// </channel>
	}

	/**
	 * Write a single user
	 *
	 * @param WP_User $user The user to write.  Note that $user is a WP_User
	 * 						object "augmented" by WP_Export_Content::exportify_user().
	 */
	protected function write_user( $user ) {
		$this->start_wxr_element( 'user' );

		/**
		 * Allow extension markup to be added to an exported user.
		 *
		 * Functions hooked to this action MUST output elements/attributes
		 * in the their own namespace (which SHOULD be registered with the
		 * wxr_export_plugins action).  That is, they are NOT allowed to
		 * output elements/attributes in the empty namespace nor the WXR namespace.
		 *
		 * @since x.y.z
		 *
		 * @param WP_XMLWriter $writer The WP_XMLWriter instance to write to.
		 * @param WP_User $user The user.
		 *
		 * Note: this action is done here (instead of at the end of this method)
		 * in case plugins want to output attributes on wxr:user.
		 */
		do_action( 'wxr_export_user', $this->writer, $user );

		$this->write_wxr_element( 'id', intval( $user->ID ) );
		$this->write_wxr_element( 'login', $user->user_login );
		$this->write_wxr_element( 'email', $user->user_email );
		$this->write_wxr_element( 'display_name', $user->display_name );
		$this->write_wxr_element( 'first_name', $user->first_name );
		$this->write_wxr_element( 'last_name', $user->last_name );

		foreach ( $user->meta as $meta ) {
			$this->write_meta( $meta, 'user' );
		}

		$this->writer->endElement(); // </wxr:user>

		$this->logger->info( sprintf(
			__( 'Exported user "%s"', 'wordpress-importer' ),
			$user->user_login
		) );
		do_action( 'wxr_exporter.wrote.user', 1, (array) $user );
	}

	/**
	 * Write a single term.
	 *
	 * @param WP_Term $term The term to write.  Note that $term is a WP_Term
	 * 						object "augmented" by WP_Export_Content::exportify_term().
	 */
	protected function write_term( $term ) {
		$this->start_wxr_element( 'term' );

		/**
		 * Allow extension markup to a added to an exported term.
		 *
		 * Functions hooked to this action MUST output elements/attributes
		 * in the their own namespace (which SHOULD be registered with the
		 * wxr_export_plugins action).  That is, they are NOT allowed to
		 * output elements/attributes in the empty namespace nor the WXR namespace.
		 *
		 * @since x.y.z
		 *
		 * @param WP_XMLWriter $writer The WP_XMLWriter instance to write to.
		 * @param WP_Term $term The term.
		 *
		 * Note: this action is done here (instead of at the end of this method)
		 * in case plugins want to output attributes on wxr:term.
		 */
		do_action( 'wxr_export_term', $this->writer, $term );

		$this->write_wxr_element( 'id', intval( $term->term_id ) );
		$this->write_wxr_element( 'name', $term->name );
		$this->write_wxr_element( 'slug', $term->slug );
		$this->write_wxr_element( 'taxonomy', $term->taxonomy );

		if ( $term->parent ) {
			$this->write_wxr_element( 'parent', $term->parent );
		}
		if ( ! empty( $term->description ) ) {
			$this->write_wxr_element( 'description', $term->description );
		}

		foreach ( $term->meta as $meta ) {
			$this->write_meta( $meta, 'term' );
		}

		$this->writer->endElement(); // </wxr:term>

		$this->logger->info( sprintf(
			__( 'Exported term "%s" (%s)', 'wordpress-importer' ),
			$term->name,
			$term->taxonomy
		) );
		do_action( 'wxr_exporter.wrote.term', 1, (array) $term );
	}

	/**
	 * Write a single link.
	 *
	 * @param WP_Term $link The term to write.  Note that $term is a WP_Term
	 * 						object "augmented" by WP_Export_Content::exportify_term().
	 */
	protected function write_link( $link ) {
		$this->start_wxr_element( 'link' );

		/**
		 * Allow extension markup to a added to an exported term.
		 *
		 * Functions hooked to this action MUST output elements/attributes
		 * in the their own namespace (which SHOULD be registered with the
		 * wxr_export_plugins action).  That is, they are NOT allowed to
		 * output elements/attributes in the empty namespace nor the WXR namespace.
		 *
		 * @since x.y.z
		 *
		 * @param WP_XMLWriter $writer The WP_XMLWriter instance to write to.
		 * @param WP_Term $term The term.
		 *
		 * Note: this action is done here (instead of at the end of this method)
		 * in case plugins want to output attributes on wxr:link.
		 */
		do_action( 'wxr_export_link', $this->writer, $link );

		$this->write_wxr_element( 'id', intval( $link->link_id ) );
		$this->write_wxr_element( 'url', $link->link_url );
		$this->write_wxr_element( 'name', $link->link_name );
		$this->write_wxr_element( 'image', $link->link_image );
		$this->write_wxr_element( 'target', $link->link_target );

		$this->write_wxr_element( 'description', $link->link_description );
		$this->write_wxr_element( 'visible', $link->link_visible );
		$this->write_wxr_element( 'owner', $link->link_owner );
		$this->write_wxr_element( 'rating', $link->link_rating );
		$this->write_wxr_element( 'updated', $link->link_updated );
		$this->write_wxr_element( 'rel', $link->link_rel );
		$this->write_wxr_element( 'notes', $link->link_notes );
		$this->write_wxr_element( 'rss', $link->link_rss );

		foreach ( $link->link_category as $category ) {
			$this->write_wxr_element( 'category', $category->slug );
		}

		$this->writer->endElement(); // </wxr:link>

		$this->logger->info( sprintf(
			__( 'Exported link "%s"', 'wordpress-importer' ),
			$link->link_name
		) );
		do_action( 'wxr_exporter.wrote.link', 1, (array) $link );
	}

	/**
	 * Write a single post.
	 *
	 * @param WP_Post $post The post to write.  Note that $post is a WP_Post
	 * 						object "augmented" by WP_Export_Content::exportify_post().
	 */
	protected function write_post( $post ) {
		$this->writer->startElement( 'item' );

		/**
		 * Allow plugins to add extension markup to an exported post.
		 *
		 * Functions hooked to this action MUST output elements/attributes
		 * in the their own namespace (which SHOULD be registered with the
		 * wxr_export_plugins action).  That is, they are NOT allowed to
		 * output elements/attributes in the empty namespace nor the WXR namespace.
		 *
		 * @since x.y.z
		 *
		 * @param WP_XMLWriter $writer The WP_XMLWriter instance to write to.
		 * @param WP_Post $post The post.
		 *
		 * Note: this action is done here (instead of at the end of this method)
		 * in case plugins want to output attributes on RSS's item.
		 */
		do_action( 'wxr_export_post', $this->writer, $post );

		$this->writer->writeElement( 'title', $post->post_title );
		$this->writer->writeElement( 'link', $post->permalink );
		$this->writer->writeElement( 'guid', $post->guid, array( 'isPermaLink' => 'false' ) );
		$this->writer->writeElement( 'description', $post->excerpt );

		$this->writer->writeElement( 'Q{' . self::DUBLIN_CORE_NAMESPACE_URI . '}creator', $post->post_author );
		$this->writer->writeElement( 'Q{' . self::RSS_CONTENT_NAMESPACE_URI . '}encoded', $post->content );

		$this->write_wxr_element( 'id', intval( $post->ID ) );
		$this->write_wxr_element( 'date', $post->post_date );
		$this->write_wxr_element( 'date_gmt', $post->post_date_gmt );
		$this->write_wxr_element( 'comment_status', $post->comment_status );
		$this->write_wxr_element( 'ping_status', $post->ping_status );
		$this->write_wxr_element( 'name', $post->post_name );
		$this->write_wxr_element( 'status', $post->post_status );
		$this->write_wxr_element( 'parent', $post->post_parent );
		$this->write_wxr_element( 'menu_order', $post->menu_order );
		$this->write_wxr_element( 'type', $post->post_type );
		$this->write_wxr_element( 'password', $post->post_password );
		$this->write_wxr_element( 'is_sticky', $post->is_sticky );

		if ( 'attachment' === $post->post_type ) {
			$this->write_wxr_element( 'attachment_url', wp_get_attachment_url( $post->ID ) );
		}

		foreach ( $post->terms as $term ) {
			$this->write_post_term( $term );
		}

		foreach ( $post->meta as $meta ) {
			$this->write_meta( $meta, 'post' );
		}
		foreach ( $post->comments as $comment ) {
			$this->write_comment( $comment );
		}

		$this->writer->endElement(); // </item>

		$post_type_object = get_post_type_object( $post->post_type );
		$this->logger->info( sprintf(
			__( 'Exported ' . ( 'attachment' === $post->post_type ? 'media' : 'post' ) . ' "%s" (%s)', 'wordpress-importer' ),
			$post->post_title,
			$post_type_object->labels->singular_name
		) );
		do_action( 'wxr_exporter.wrote.post', 1, (array) $post );
	}

	/**
	 * Write a single term attached to a post.
	 *
	 * @param WP_Term $term The term to write.
	 */
	protected function write_post_term( $term ) {
		$attrs = array(
			'domain' => $term->taxonomy,
			'Q{' . self::WXR_NAMESPACE_URI . "}slug" => $term->slug,
		);
		$this->writer->startElement( 'category', $attrs );

		/**
		 * Allow extension markup to be added to a term attached to a post.
		 *
		 * Functions hooked to this action MUST output only attributes
		 * in the their own namespace (which SHOULD be registered with the
		 * wxr_export_plugins action).  That is, they are NOT allowed to
		 * output any child elements, nor attributes in the empty namespace
		 * nor the WXR namespace.
		 *
		 * @since x.y.z
		 *
		 * @param WP_XMLWriter $writer The WP_XMLWriter instance to write to.
		 * @param WP_Term $term The term.
		 *
		 * Note: this action is done here (instead of at the end of this method)
		 * in case plugins want to output attributes on RSS's category.
		 */
		do_action( 'wxr_export_post_term', $this->writer, $term );

		$this->writer->text( $term->name );

		$this->writer->endElement(); // </category>
	}

	/**
	 * Write a single post comment.
	 *
	 * @param WP_Comment $comment The comment to write.
	 */
	protected function write_comment( $comment ) {
		$this->start_wxr_element( 'comment' );

		/**
		 * Allow extension markup to be added to a comment.
		 *
		 * Functions hooked to this action MUST output elements/attributes
		 * in the their own namespace (which SHOULD be registered with the
		 * wxr_export_plugins action).  That is, they are NOT allowed to
		 * output elements/attributes in the empty namespace nor the WXR namespace.
		 *
		 * @since x.y.z
		 *
		 * @param WP_XMLWriter $writer The WP_XMLWriter instance to write to.
		 * @param WP_Comment $c The comment.
		 *
		 * Note: this action is done here (instead of at the end of this method)
		 * in case plugins want to output attributes on wxr:comment.

		 * @todo should we also pass the WP_Post object as well?
		 */
		do_action( 'wxr_export_comment', $this->writer, $comment );

		$this->write_wxr_element( 'id', intval( $comment->comment_ID ) );
		$this->write_wxr_element( 'author', $comment->comment_author );
		$this->write_wxr_element( 'author_email', $comment->comment_author_email );
		$this->write_wxr_element( 'author_url', $comment->comment_author_url );
		$this->write_wxr_element( 'author_IP', $comment->comment_author_IP );
		$this->write_wxr_element( 'date', $comment->comment_date );
		$this->write_wxr_element( 'date_gmt', $comment->comment_date_gmt );
		$this->write_wxr_element( 'content', $comment->comment_content );
		$this->write_wxr_element( 'approved', $comment->comment_approved );
		$this->write_wxr_element( 'type', $comment->comment_type );
		$this->write_wxr_element( 'parent', $comment->comment_parent );
		$this->write_wxr_element( 'user_id', intval( $comment->user_id ) );

		foreach ( $comment->meta as $meta ) {
			$this->write_meta( $writer, $meta, 'comment' );
		}

		$this->writer->endElement(); // </wxr:comment>

		$this->logger->info( sprintf(
			__( 'Exported comment ID "%d"', 'wordpress-importer' ),
			$comment->comment_ID
		) );
		do_action( 'wxr_exporter.wrote.comment', 1, (array) $comment );
	}

	/**
	 * Write a single meta.
	 *
	 * @param WP_Meta $meta
	 * @param string $type The type of meta (e.g., 'post', 'user', 'term', 'comment').
	 */
	protected function write_meta( $meta, $type ) {
		$this->start_wxr_element( 'meta' );

		/**
		 * Allow plugins to add extension markup to a meta.
		 *
		 * Functions hooked to this action MUST output elements/attributes
		 * in the their own namespace (which SHOULD be registered with the
		 * wxr_export_plugins action).  That is, they are NOT allowed to
		 * output elements/attributes in the empty namespace nor the WXR namespace.
		 *
		 * @since x.y.z
		 *
		 * @param WP_XMLWriter $writer The WP_XMLWriter instance.
		 * @param object $meta {
		 *     @type string $meta_key The meta key.
		 *     @type string $meta_value The meta value.
		 * }
		 * @param string $type The type of meta (e.g. 'post', 'user', 'term', 'comment').
		 *
		 * Note: this action is done here (instead of at the end of this method)
		 * in case plugins want to output attributes on wxr:meta.
		 */
		do_action( 'wxr_export_meta', $this->writer, $meta, $type );

		$this->write_wxr_element( 'key', $meta->meta_key );
		$this->write_wxr_element( 'value', $meta->meta_value );

		$this->writer->endElement(); // </wxr:meta>
	}

	/**
	 * Write the start tag for an element in the WXR namespace, possibly including attributes.
	 *
	 * @param string $localName The local-name of the element.
	 * @param array $attributes Attributes for the element.  Keys are attribute names, values
	 * 							attribute values.
	 *
	 * Note: this method exists only to make it easier to gauarantee that all WXR elements
	 * have the correct namespace URI.
	 */
	private function start_wxr_element( $localName, $attributes = array() ) {
		$this->writer->startElement( 'Q{' . self::WXR_NAMESPACE_URI . '}' . $localName, $attributes );
	}

	/**
	 * Write an element in the WXR namespace, include it's text content and attributes.
	 *
	 * @param string $localName The local-name of the element.
	 * @param array $attributes Attributes for the element.  Keys are attribute names, values
	 * 							attribute values.
	 *
	 * Note: this method exists only to make it easier to gauarantee that all WXR elements
	 * have the correct namespace URI.
	 */
	private function write_wxr_element ( $localName, $content, $attributes = array() ) {
		$this->writer->writeElement( 'Q{' . self::WXR_NAMESPACE_URI . '}' . $localName, $content, $attributes );
	}

	/**
	 * Guarantee that a namespace prefix is unique.
	 *
	 * @since x.y.z
	 *
	 * @param string $prefix The preferred prefix.
	 * @return string
	 */
	private function unique_prefix( $prefix ) {
		static $prefixes = array( self::WXR_PREFIX, self::RSS_CONTENT_PREFIX, self::DUBLIN_CORE_PREFIX );

 		$int = 0;
 		$orig_prefix = $prefix;

		while ( in_array( $prefix, $prefixes ) ) {
 			$prefix = sprintf( "%s%d", $orig_prefix, $int++ );
		}

		$prefixes[] = $prefix;

		return $prefix;
	}

	/**
	 *
	 * @since ? This function is in the standard exporter but has no @since tag.
	 *
	 * @param bool   $return_me
	 * @param string $meta_key
	 * @return bool
	 */
	static function filter_postmeta( $return_me, $meta_key ) {
		if ( '_edit_lock' == $meta_key ) {
			$return_me = true;
		}
		return $return_me;
	}

	/**
	 * Do not allow 'first_name' and 'last_name' user metas to be output
	 * since they are already output with the <wxr:user/> element.
	 *
	 * @since x.y.z
	 *
	 * @param bool   $return_me
	 * @param string $meta_key
	 * @return bool
	 */
	static function filter_usermeta( $return_me, $meta_key ) {
		if ( in_array( $meta_key, array( 'first_name', 'last_name' ) ) ) {
			$return_me = true;
		}
		return $return_me;
	}
}