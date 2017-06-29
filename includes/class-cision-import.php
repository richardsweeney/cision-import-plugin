<?php

class Cision_Import {

	/**
	 * @var string Name of the post type.
	 */
	private $post_type = 'post';

	/**
	 * @var string Language of the feed to retrieve/post to add.
	 */
	private $lang = null;

	/**
	 * @var string ID of the Cision feed.
	 */
	private $feed_id = null;

	/**
	 * @var string Cision endpoint URL.
	 */
	private $endpoint = 'http://publish.ne.cision.com/papi/NewsFeed/';

	/**
	 * @var string Cision endpoint URL.
	 */
	private $single_endpoint = 'http://publish.ne.cision.com/papi/Release/';

	/**
	 * @var string Meta key to attach Cision URL to a post.
	 */
	private $meta_key = '_cision_id';

	/**
	 * @var string ACF field ID
	 */
	private $regulatory_field_id = 'field_57fe3c295f180';

	/**
	 * @var string Path to the log file
	 */
	private $log = null;


	/**
	 * Create the an instance of the class.
	 *
	 * @param string $feed_id The ID of the Cision feed.
	 * @param string $lang Language Code, either 'sv' or 'en'.
	 */
	public function __construct( $feed_id, $lang ) {
		$this->feed_id = $feed_id;
		$this->lang    = strtolower( $lang );
		$this->log     = plugin_dir_path( __FILE__ ) . '-' . $lang . '-log.txt';

		$ids = $this->get_feed();

		if ( ! empty( $ids ) ) {
			$this->log_feed( $ids );
		}
	}


	/**
	 * Get a feed and check if it needs to be added to the
	 * databse.
	 *
	 * @return bool|array False if the feed couldn't be retrieved or an
	 *                    array of post IDs that were added to the database.
	 */
	public function get_feed() {
		$added_items = [];

		$feed_items = $this->retrieve_feed();
		if ( ! $feed_items || empty( $feed_items ) ) {
			return false;
		}

		foreach ( $feed_items as $feed_item ) {
			if ( ! $this->item_exists( $feed_item->Id ) ) {
				$id            = $this->insert_feed_item_as_post( $feed_item );
				$added_items[] = $id;
			}
		}

		return $added_items;
	}


	/**
	 * Log the stored IDs.
	 *
	 * @param array $ids .
	 */
	public function log_feed( $ids ) {
		if ( false === $ids || empty( $ids ) ) {
			return;
		}

		foreach ( $ids as $id ) {
			file_put_contents( $this->log, "$id\n", FILE_APPEND );
		}
	}


	public function get_json_from_url( $url ) {
		$feed = wp_remote_get( $url );
		if ( 200 !== wp_remote_retrieve_response_code( $feed ) ) {
			return false;
		}

		return json_decode( wp_remote_retrieve_body( $feed ) );
	}


	/**
	 * Retrieve a feed from cision.
	 *
	 * @return bool|array False on failure or an array of feed item objects.
	 */
	public function retrieve_feed() {
		$url = $this->endpoint . $this->feed_id . '?format=json';

		$feed = $this->get_json_from_url( $url );
		if ( ! $feed ) {
			return false;
		}

		return $feed->Releases;
	}


	/**
	 * Get a single feed item from cision.
	 *
	 * @param string $encrypted_id ID for the release.
	 *
	 * @return array|bool|mixed|object
	 */
	public function retrieve_single_feed_item( $encrypted_id ) {
		$url = $this->single_endpoint . $encrypted_id . '?format=json';

		$feed = $this->get_json_from_url( $url );
		if ( ! $feed ) {
			return false;
		}

		return $feed->Release;
	}


	/**
	 * Get the post content from a feed item and add add link to the content
	 * as a PDF.
	 *
	 * @param $feed_item
	 *
	 * @return string Post Content.
	 */
	protected function get_post_content( $feed_item ) {
		$content = $feed_item->HtmlBody;

		if ( empty( $feed_item->Files ) ) {
			return $content;
		}

		$pdf = false;
		foreach ( $feed_item->Files as $file ) {
			$file_type = wp_check_filetype( $file->Url );
			if ( 'pdf' === $file_type['ext'] ) {
				$pdf = $file->Url;
				break;
			}
		}

		if ( $pdf !== false ) {
			$string  = ( 'sv' === $this->lang ) ? 'Ladda ner som PDF' : 'Download as PDF';
			$content .= "\n\n" . sprintf(
					'<a target="_blank" href="%s">%s</a></p>',
					$pdf,
					$string
				);
		}

		return $content;
	}


	/**
	 * Check if an item has already been added to the database.
	 *
	 * @param string $meta_value The metadata value to compare.
	 *
	 * @return bool true if item exists, false if not.
	 */
	public function item_exists( $meta_value ) {
		$results = get_posts( [
			'post_type'      => $this->post_type,
			'meta_key'       => $this->meta_key,
			'meta_value'     => $meta_value,
			'posts_per_page' => 1,
		] );

		if ( ! empty( $results ) ) {
			return true;
		}

		return false;
	}


	/**
	 * Get term ID for a pressrelease.
	 *
	 * @return bool|int.
	 */
	protected function get_pressrelease_term_id() {
		global $wpdb;
		$term_slug = ( 'en' === $this->lang ) ? 'pressrelease' : 'pressreleaser';

		$sql = "SELECT t.term_id FROM $wpdb->terms as t LEFT JOIN $wpdb->term_taxonomy as tt ON t.term_id = tt.term_id WHERE t.name = %s AND tt.taxonomy = 'category'";

		$term_id = $wpdb->get_var( $wpdb->prepare( $sql, $term_slug ) );

		return ( $term_id ) ? (int) $term_id : false;
	}


	/**
	 * Set post category for pressreleases.
	 *
	 * @param $insert_id
	 *
	 * @return mixed.
	 */
	protected function set_post_category( $insert_id ) {
		$term_id = $this->get_pressrelease_term_id();
		if ( $term_id ) {
			return wp_set_object_terms( $insert_id, $term_id, 'category' );
		}

		return false;
	}


	/**
	 * Set the language of the post.
	 *
	 * @param $insert_id
	 *
	 * @return bool|int|null|string
	 */
	protected function set_post_lang( $insert_id ) {
		global $sitepress;

		if ( $sitepress ) {
			return $sitepress->set_element_language_details( $insert_id, 'post_' . $this->post_type, false, $this->lang );
		}

		return false;
	}


	/**
	 * Set a flag (as an ACF field) if the post is regulatory.
	 *
	 * @param int $insert_id
	 * @param object $feed_item
	 *
	 * @return void
	 */
	protected function set_post_regulatory_meta( $insert_id, $feed_item ) {
		if ( ! function_exists( 'update_field' ) ) {
			return;
		}

		$is_regulatory = ( isset( $feed_item->IsRegulatory ) && true === $feed_item->IsRegulatory ) ? 1 : 0;

		update_field( $this->regulatory_field_id, $is_regulatory, $insert_id );
	}


	/**
	 * Add a post thumbnail if one exists in cision.
	 *
	 * @param object $feed_item .
	 * @param int $insert_id Post ID.
	 *
	 * @todo Fix Proper name for Image object
	 *
	 * @return bool|int
	 */
	protected function set_post_thumbnail( $feed_item, $insert_id ) {
		if ( empty( $feed_item->Images ) ) {
			return false;
		}

		$image = array_shift( $feed_item->Images );

		if ( isset( $$image->Url ) ) {
			$attachment_id = $this->add_image_to_lib( $image->Url );
			if ( $attachment_id ) {
				return set_post_thumbnail( $insert_id, $attachment_id );
			}
		}

		return false;
	}


	/**
	 * Insert a feed item to the database.
	 *
	 * @param object $feed_item .
	 *
	 * @return bool|int False on failure, post_id on success.
	 */
	public function insert_feed_item_as_post( $feed_item ) {
		$feed_item = $this->retrieve_single_feed_item( $feed_item->EncryptedId );
		if ( ! $feed_item ) {
			return false;
		}

		$post_content = $this->get_post_content( $feed_item );

		$args = [
			'post_title'   => $feed_item->Title,
			'post_content' => make_clickable( $post_content ),
			'post_excerpt' => $feed_item->Intro,
			'post_status'  => 'publish',
			'post_date'    => date( 'Y-m-d H:i:s', strtotime( $feed_item->PublishDate ) ),
			'post_type'    => $this->post_type,
		];

		$insert_id = wp_insert_post( $args );
		if ( ! $insert_id ) {
			return false;
		}

		// Add the metadata
		update_post_meta( $insert_id, $this->meta_key, $feed_item->Id );

//		$this->set_post_thumbnail( $feed_item, $insert_id );
		$this->set_post_category( $insert_id );
		$this->set_post_lang( $insert_id );
		$this->set_post_regulatory_meta( $insert_id, $feed_item );

		return $insert_id;
	}


	/**
	 * Add an image to the media library.
	 *
	 * @param string $url URL to the file.
	 *
	 * @return bool|int
	 */
	protected function add_image_to_lib( $url ) {
		require ABSPATH . 'wp-admin/includes/image.php';

		$upload_dir = wp_get_upload_dir();
		$img        = wp_get_image_editor( $url );

		if ( is_wp_error( $img ) ) {
			return false;
		}

		$file      = wp_remote_get( $url );
		$headers   = wp_remote_retrieve_headers( $file );
		$mime_type = $headers['content-type'];
		$extension = explode( '/', $mime_type );

		// Create a random name
		$rand_name = substr( str_shuffle( "0123456789abcdefghijklmnopqrstuvwxyz" ), 0, 20 );

		// Add the corrent file type
		$rand_name = $rand_name . '.' . $extension[1];

		// Full path to the file
		$filename = path_join( $upload_dir['path'], $rand_name );
		$result   = $img->save( $filename, $mime_type );

		if ( ! $result ) {
			return false;
		}

		$attachment    = [
			'guid'           => $upload_dir['url'] . '/' . $rand_name,
			'post_mime_type' => $mime_type,
			'post_title'     => $rand_name,
			'post_content'   => '',
			'post_status'    => 'inherit',
		];
		$attachment_id = wp_insert_attachment( $attachment, $filename );

		if ( ! $attachment_id ) {
			return false;
		}

		$attach_data = wp_generate_attachment_metadata( $attachment_id, $filename );
		wp_update_attachment_metadata( $attachment_id, $attach_data );

		return $attachment_id;
	}

}
