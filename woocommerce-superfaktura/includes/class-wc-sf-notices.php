<?php
/**
 * SuperFaktúra WooCommerce admin notices: announcements (such as eFaktúra) and failed document warnings.
 *
 * @package   SuperFaktúra WooCommerce
 * @author    2day.sk <superfaktura@2day.sk>
 * @copyright 2022 2day.sk s.r.o., Webikon s.r.o.
 * @license   GPL-2.0+
 * @link      https://www.superfaktura.sk/integracia/
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * WC_SF_Notices.
 *
 * Shows announcements (a notice on the Dashboard, Plugins and WooCommerce screens and a banner on the plugin settings tab)
 * and a warning when documents could not be created or updated in SuperFaktúra. Each notice can be
 * closed per user; the failed document warning comes back when a newer failure appears.
 *
 * The announcements are downloaded once a day from a signed file, so their texts can change without a plugin update.
 * Until the first successful download, the bundled eFaktúra announcement is shown.
 *
 * @package SuperFaktúra WooCommerce
 * @author  2day.sk <superfaktura@2day.sk>
 */
class WC_SF_Notices {

	/**
	 * User meta key holding the closed notices.
	 */
	const USER_META = 'wc_sf_dismissed_notices';

	/**
	 * Transient caching the unresolved document failures.
	 */
	const ERRORS_TRANSIENT = 'wc_sf_document_errors';

	/**
	 * The bundled eFaktúra announcement is shown until this date (site time), because its text speaks about the start in the future.
	 */
	const EFAKTURA_UNTIL = '2027-01-01';

	/**
	 * How far back failed documents are reported.
	 */
	const ERRORS_DAYS = 30;

	/**
	 * How many failures are kept for the warning.
	 */
	const ERRORS_LIMIT = 50;

	/**
	 * Address of the announcements file. Its signature is at the same address with ".sig" appended.
	 */
	const MESSAGES_URL = 'https://sf.2day.sk/woocommerce/messages.json';

	/**
	 * Ed25519 public key (base64) the announcements file must be signed with.
	 */
	const MESSAGES_PUBLIC_KEY = '6+NxdOzFZSwRENwYZixLJeEwRmZ2WgsAvkdn2jk2eKg=';

	/**
	 * Option holding the last valid announcements file.
	 */
	const MESSAGES_OPTION = 'wc_sf_remote_messages';

	/**
	 * Option holding the last download failure (time and reason), for support. Removed after a successful download.
	 */
	const MESSAGES_ERROR_OPTION = 'wc_sf_remote_messages_error';

	/**
	 * WP-Cron hook downloading the announcements.
	 */
	const MESSAGES_CRON = 'wc_sf_fetch_messages';

	/**
	 * Largest accepted announcements file, in bytes.
	 */
	const MESSAGES_MAX_SIZE = 65536;

	/**
	 * Largest accepted announcement image, in bytes.
	 */
	const IMAGE_MAX_SIZE = 204800;

	/**
	 * Most images one announcements file may use.
	 */
	const IMAGES_LIMIT = 4;

	/**
	 * Format of the stored announcements option. A stored value of another format is ignored.
	 */
	const MESSAGES_SCHEMA = 1;

	/**
	 * Images bundled with the plugin, by SHA-256 checksum. An announcement using one of them shows the plugin's own file.
	 */
	const BUNDLED_IMAGES = array(
		'075c109cb7888a6c9d0b23b15985a6dc190ce9b37e8a6273b052b1196d15bde1' => 'assets/images/efaktura-badge.png',
	);

	/**
	 * Instance of WC_SuperFaktura.
	 *
	 * @var WC_SuperFaktura
	 */
	private $wc_sf;

	/**
	 * Failures for the current user, computed once per request.
	 *
	 * @var array|null
	 */
	private $user_errors = null;

	/**
	 * Announcements to show to the current user, computed once per request and placement.
	 *
	 * @var array
	 */
	private $shown_messages = array();

	/**
	 * Why the last download failed.
	 *
	 * @var string
	 */
	private $download_error = '';

	/**
	 * Constructor.
	 *
	 * @param WC_SuperFaktura $wc_sf Instance of WC_SuperFaktura.
	 */
	public function __construct( $wc_sf ) {
		$this->wc_sf = $wc_sf;
	}

	/**
	 * Register hooks.
	 */
	public function init() {
		// WP-Cron runs outside the admin.
		add_action( self::MESSAGES_CRON, array( $this, 'fetch_messages' ) );

		if ( ! is_admin() ) {
			return;
		}

		add_action( 'admin_init', array( $this, 'schedule_messages' ) );
		add_action( 'admin_notices', array( $this, 'render_notices' ) );
		add_action( 'woocommerce_sections_superfaktura', array( $this, 'render_efaktura_hero' ), 20 );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_scripts' ) );
		add_action( 'wp_ajax_wc_sf_dismiss_notice', array( $this, 'ajax_dismiss' ) );
	}

	/**
	 * Forget the cached failures, so a new log entry is reflected right away.
	 */
	public static function flush_errors() {
		delete_transient( self::ERRORS_TRANSIENT );
	}

	/**
	 * Load styles and the script that remembers closed notices, only where a notice is shown.
	 */
	public function enqueue_scripts() {
		$errors = $this->get_user_errors();
		if ( ! $this->get_message( 'notice' ) && ! $this->get_message( 'hero' ) && ! $errors['errors'] ) {
			return;
		}

		wp_enqueue_style( 'wc-sf-admin-notices', plugins_url( 'assets/css/admin-notices.css', WC_SF_FILE_PATH ), array(), $this->wc_sf->version );
		wp_enqueue_script( 'wc-sf-admin-notices', plugins_url( 'assets/js/admin-notices.js', WC_SF_FILE_PATH ), array( 'jquery' ), $this->wc_sf->version, true );
		wp_localize_script(
			'wc-sf-admin-notices',
			'wc_sf_notices',
			array(
				'ajax_url' => admin_url( 'admin-ajax.php' ),
				'nonce'    => wp_create_nonce( 'wc_sf_notices' ),
			)
		);
	}

	/**
	 * Print the plugin's admin notices.
	 */
	public function render_notices() {
		$this->render_document_errors();

		$message = $this->get_message( 'notice' );
		if ( $message ) {
			$this->render_message_notice( $message );
		}
	}

	/**
	 * AJAX: remember that the current user closed a notice.
	 */
	public function ajax_dismiss() {
		check_ajax_referer( 'wc_sf_notices', 'nonce' );

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( array( 'message' => 'Unauthorized' ), 403 );
		}

		$notice = isset( $_POST['notice'] ) ? sanitize_key( wp_unslash( $_POST['notice'] ) ) : '';
		$known  = array( 'efaktura_notice', 'efaktura_hero', 'document_errors' );
		$data   = $this->get_messages();
		foreach ( $data['messages'] as $message ) {
			if ( is_array( $message ) && ! empty( $message['id'] ) && is_string( $message['id'] ) ) {
				$known[] = $this->dismiss_key( $message['id'], 'notice' );
				$known[] = $this->dismiss_key( $message['id'], 'hero' );
			}
		}
		if ( ! in_array( $notice, $known, true ) ) {
			wp_send_json_error( array( 'message' => 'Unknown notice' ), 400 );
		}

		$dismissed = $this->get_dismissed();

		if ( 'document_errors' === $notice ) {
			// Remember the newest failure the user has seen, so only newer ones bring the warning back.
			$value  = isset( $_POST['value'] ) ? absint( wp_unslash( $_POST['value'] ) ) : 0;
			$stored = isset( $dismissed[ $notice ] ) ? (int) $dismissed[ $notice ] : 0;
			$data   = $this->get_errors();
			if ( $stored > $data['max_id'] ) {
				// The log numbering started over, the stored id no longer applies.
				$stored = 0;
			}
			$dismissed[ $notice ] = max( $value, $stored );
		} else {
			$dismissed[ $notice ] = time();
		}

		// Stored per site, because the failure ids come from each site's own log table.
		update_user_option( get_current_user_id(), self::USER_META, $dismissed );

		wp_send_json_success();
	}

	/**
	 * Notices the current user closed.
	 *
	 * @return array
	 */
	private function get_dismissed() {
		$dismissed = get_user_option( self::USER_META, get_current_user_id() );

		return is_array( $dismissed ) ? $dismissed : array();
	}

	/**
	 * Is the current admin screen the SuperFaktúra settings tab?
	 *
	 * @return bool
	 */
	private function is_settings_tab() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		return isset( $_GET['page'], $_GET['tab'] ) && 'wc-settings' === $_GET['page'] && 'superfaktura' === $_GET['tab'];
	}

	/**
	 * Is the current admin screen one where the announcement notice may appear (Dashboard, Plugins, WooCommerce screens)?
	 *
	 * @return bool
	 */
	private function is_notice_screen() {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen ) {
			return false;
		}

		$screen_ids = array( 'dashboard', 'plugins' );
		if ( function_exists( 'wc_get_screen_ids' ) ) {
			$screen_ids = array_merge( $screen_ids, wc_get_screen_ids() );
		}

		return in_array( $screen->id, $screen_ids, true );
	}

	/**
	 * Are announcements downloaded from the announcements file?
	 *
	 * @return bool
	 */
	private function remote_messages_enabled() {
		/**
		 * Filter whether announcements are downloaded once a day from the plugin author's server.
		 * When disabled, only the bundled eFaktúra announcement is shown.
		 *
		 * @since 1.58.0
		 *
		 * @param bool $enabled Whether to download announcements, by default the "Announcements" setting.
		 */
		return (bool) apply_filters( 'sf_remote_messages_enabled', 'no' !== get_option( 'woocommerce_sf_remote_messages', 'yes' ) );
	}

	/**
	 * Schedule the daily download of announcements, or stop it when it is disabled.
	 */
	public function schedule_messages() {
		$scheduled = wp_next_scheduled( self::MESSAGES_CRON );

		if ( ! $this->remote_messages_enabled() ) {
			if ( $scheduled ) {
				// Turned off: no more downloads, so an old failure is no longer relevant.
				wp_clear_scheduled_hook( self::MESSAGES_CRON );
				$this->clear_fetch_error();
			}
			return;
		}

		if ( ! $scheduled ) {
			// Spread the first download over an hour, so sites do not ask at the same moment after an update.
			wp_schedule_event( time() + wp_rand( MINUTE_IN_SECONDS, HOUR_IN_SECONDS ), 'daily', self::MESSAGES_CRON );
		}
	}

	/**
	 * Stop the daily download of announcements (plugin deactivation).
	 */
	public static function unschedule_messages() {
		wp_clear_scheduled_hook( self::MESSAGES_CRON );
	}

	/**
	 * Download the announcements file, check its signature and keep it when it is valid.
	 *
	 * Anything wrong (network error, too large, bad signature, older than the stored file) leaves the stored
	 * announcements untouched. Failures are not written to the API log, which lists only SuperFaktura API requests.
	 */
	public function fetch_messages() {
		if ( ! $this->remote_messages_enabled() || ! function_exists( 'sodium_crypto_sign_verify_detached' ) ) {
			return;
		}

		// The site does not allow outgoing requests to this server (WP_HTTP_BLOCK_EXTERNAL), so there is nothing to report.
		$http = new WP_Http();
		if ( $http->block_request( self::MESSAGES_URL ) ) {
			$this->clear_fetch_error();
			return;
		}

		$body = $this->download( self::MESSAGES_URL, self::MESSAGES_MAX_SIZE );
		$sig  = null === $body ? null : $this->download( self::MESSAGES_URL . '.sig', 1024 );
		if ( null === $body || null === $sig ) {
			$this->record_fetch_error( 'Announcements could not be downloaded.', $this->download_error );
			return;
		}

		$signature = base64_decode( trim( $sig ), true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
		$valid     = false;
		if ( false !== $signature && 64 === strlen( $signature ) ) {
			try {
				$valid = sodium_crypto_sign_verify_detached( $signature, $body, base64_decode( self::MESSAGES_PUBLIC_KEY ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
			} catch ( Throwable $e ) {
				$valid = false;
			}
		}
		if ( ! $valid ) {
			$this->record_fetch_error( 'Announcements were rejected, their signature is not valid.' );
			return;
		}

		$data = json_decode( $body, true );
		if ( ! is_array( $data ) || 1 !== ( $data['version'] ?? null ) || ! isset( $data['messages'] ) || ! is_array( $data['messages'] ) ) {
			$this->record_fetch_error( 'Announcements were rejected, their format is not supported.' );
			return;
		}

		$issued = isset( $data['issued'] ) ? (int) $data['issued'] : 0;
		$stored = $this->get_stored_messages();

		// A file dated in the future would block every later file, so it is not accepted.
		if ( $issued > time() + DAY_IN_SECONDS ) {
			$this->record_fetch_error( 'Announcements were rejected, their date is in the future.' );
			return;
		}

		// The same file as stored: nothing to do. An older file, even with a valid signature, must not replace a newer one.
		if ( $stored && $issued <= $stored['issued'] ) {
			$this->clear_fetch_error();
			return;
		}

		$messages = array();
		foreach ( $data['messages'] as $message ) {
			$message = $this->sanitize_message( $message );
			if ( $message ) {
				$messages[] = $message;
			}
		}

		$images = $this->download_images( $messages, $stored ? $stored['images'] : array() );
		if ( null === $images ) {
			$this->record_fetch_error( 'Announcements were rejected, an image could not be downloaded or does not match.', $this->download_error );
			return;
		}

		update_option(
			self::MESSAGES_OPTION,
			array(
				'schema'   => self::MESSAGES_SCHEMA,
				'issued'   => $issued,
				'fetched'  => time(),
				'messages' => $messages,
				'images'   => $images,
			),
			false
		);
		$this->clear_fetch_error();
	}

	/**
	 * Remember why the last download of announcements failed, so support can find it when announcements do not change.
	 *
	 * @param string $message What went wrong.
	 * @param string $detail  Cause reported by the connection, if any.
	 */
	private function record_fetch_error( $message, $detail = '' ) {
		update_option(
			self::MESSAGES_ERROR_OPTION,
			array(
				'time'  => time(),
				'error' => trim( $message . ' ' . $detail ),
			),
			false
		);
	}

	/**
	 * Forget the last failure after a successful download.
	 */
	private function clear_fetch_error() {
		if ( false !== get_option( self::MESSAGES_ERROR_OPTION ) ) {
			delete_option( self::MESSAGES_ERROR_OPTION );
		}
	}

	/**
	 * The stored announcements, when they are in the current format.
	 *
	 * @return array|null With 'issued', 'messages' and 'images'.
	 */
	private function get_stored_messages() {
		$stored = get_option( self::MESSAGES_OPTION );

		if (
			! is_array( $stored )
			|| self::MESSAGES_SCHEMA !== ( $stored['schema'] ?? null )
			|| ! isset( $stored['messages'], $stored['images'] )
			|| ! is_array( $stored['messages'] )
			|| ! is_array( $stored['images'] )
		) {
			return null;
		}

		return array(
			'issued'   => (int) ( $stored['issued'] ?? 0 ),
			'messages' => $stored['messages'],
			'images'   => $stored['images'],
		);
	}

	/**
	 * Download a file from the plugin author's server.
	 *
	 * @param string $url      File address.
	 * @param int    $max_size Largest accepted size in bytes.
	 * @return string|null File content, or null on any failure.
	 */
	private function download( $url, $max_size ) {
		$this->download_error = '';
		$response             = wp_safe_remote_get(
			$url,
			array(
				'timeout'             => 5,
				'redirection'         => 0,
				// Only the plugin version, not the site address WordPress sends by default.
				'user-agent'          => 'SuperFaktura-WooCommerce/' . $this->wc_sf->version,
				'limit_response_size' => $max_size + 1,
			)
		);

		if ( is_wp_error( $response ) ) {
			$this->download_error = '(' . $response->get_error_message() . ')';
			return null;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( 200 !== $code ) {
			$this->download_error = '(HTTP ' . $code . ')';
			return null;
		}

		$body = (string) wp_remote_retrieve_body( $response );
		if ( '' === $body || strlen( $body ) > $max_size ) {
			$this->download_error = '(' . strlen( $body ) . ' B)';
			return null;
		}

		return $body;
	}

	/**
	 * Download the images the announcements use and check them against the checksums in the signed file.
	 *
	 * Images bundled with the plugin are not downloaded, images already stored are reused.
	 *
	 * @param array $messages Sanitized announcements.
	 * @param array $stored   Images stored from the previous file, data URIs keyed by checksum.
	 * @return array|null Images as data URIs keyed by their SHA-256 checksum, or null when any image fails.
	 */
	private function download_images( $messages, $stored ) {
		$files = array();
		foreach ( $messages as $message ) {
			foreach ( array( 'notice', 'hero' ) as $placement ) {
				if ( ! empty( $message[ $placement ]['image'] ) ) {
					$image                     = $message[ $placement ]['image'];
					$files[ $image['sha256'] ] = $image['file'];
				}
			}
		}

		if ( count( $files ) > self::IMAGES_LIMIT ) {
			return null;
		}

		$base   = substr( self::MESSAGES_URL, 0, strrpos( self::MESSAGES_URL, '/' ) + 1 );
		$images = array();
		foreach ( $files as $sha256 => $file ) {
			if ( isset( self::BUNDLED_IMAGES[ $sha256 ] ) ) {
				continue;
			}
			if ( isset( $stored[ $sha256 ] ) && is_string( $stored[ $sha256 ] ) ) {
				$images[ $sha256 ] = $stored[ $sha256 ];
				continue;
			}

			$content = $this->download( $base . rawurlencode( $file ), self::IMAGE_MAX_SIZE );
			if ( null === $content || ! hash_equals( $sha256, hash( 'sha256', $content ) ) ) {
				return null;
			}

			$size  = @getimagesizefromstring( $content ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- hostile input must not print warnings
			$types = array(
				IMAGETYPE_PNG  => 'image/png',
				IMAGETYPE_JPEG => 'image/jpeg',
				IMAGETYPE_WEBP => 'image/webp',
			);
			if ( ! $size || ! isset( $types[ $size[2] ] ) ) {
				return null;
			}

			$images[ $sha256 ] = 'data:' . $types[ $size[2] ] . ';base64,' . base64_encode( $content ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
		}

		return $images;
	}

	/**
	 * Check one announcement from the file and keep only what the plugin can show safely.
	 *
	 * @param mixed $message Announcement from the file.
	 * @return array|null Sanitized announcement, or null when it is not valid.
	 */
	private function sanitize_message( $message ) {
		if ( ! is_array( $message ) || ! isset( $message['id'] ) || ! is_string( $message['id'] ) || ! preg_match( '/^[a-z0-9-]{1,40}$/', $message['id'] ) ) {
			return null;
		}

		$clean = array(
			'id'   => $message['id'],
			'lang' => array(),
		);

		// A targeting field that is present but not valid drops the whole announcement, so a typo cannot show it to everyone.
		if ( isset( $message['lang'] ) ) {
			if ( ! is_array( $message['lang'] ) ) {
				return null;
			}
			foreach ( $message['lang'] as $lang ) {
				if ( ! is_string( $lang ) || ! in_array( $lang, array( 'sk', 'cz', 'at' ), true ) ) {
					return null;
				}
				$clean['lang'][] = $lang;
			}
		}

		foreach ( array( 'from', 'until' ) as $key ) {
			if ( isset( $message[ $key ] ) ) {
				if ( ! is_string( $message[ $key ] ) || ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $message[ $key ] ) ) {
					return null;
				}
				$clean[ $key ] = $message[ $key ];
			}
		}

		foreach ( array( 'min_version', 'max_version' ) as $key ) {
			if ( isset( $message[ $key ] ) ) {
				if ( ! is_string( $message[ $key ] ) || ! preg_match( '/^\d+(\.\d+){0,3}$/', $message[ $key ] ) ) {
					return null;
				}
				$clean[ $key ] = $message[ $key ];
			}
		}

		foreach ( array( 'notice', 'hero' ) as $placement ) {
			if ( isset( $message[ $placement ] ) ) {
				$part = $this->sanitize_message_part( $message[ $placement ] );
				if ( $part ) {
					$clean[ $placement ] = $part;
				}
			}
		}

		return ( isset( $clean['notice'] ) || isset( $clean['hero'] ) ) ? $clean : null;
	}

	/**
	 * Check the notice or banner part of an announcement.
	 *
	 * @param mixed $part Part from the file.
	 * @return array|null Sanitized part, or null when it has nothing to show.
	 */
	private function sanitize_message_part( $part ) {
		if ( ! is_array( $part ) ) {
			return null;
		}

		$clean = array();

		foreach ( array( 'eyebrow', 'title' ) as $key ) {
			if ( isset( $part[ $key ] ) && is_string( $part[ $key ] ) && '' !== trim( $part[ $key ] ) ) {
				$clean[ $key ] = $this->plain_text( $part[ $key ] );
			}
		}

		$clean['paragraphs'] = array();
		if ( isset( $part['paragraphs'] ) && is_array( $part['paragraphs'] ) ) {
			foreach ( array_slice( $part['paragraphs'], 0, 5 ) as $paragraph ) {
				if ( is_string( $paragraph ) && '' !== trim( $paragraph ) ) {
					$clean['paragraphs'][] = $this->kses_message( $paragraph );
				}
			}
		}

		foreach ( array( 'cta', 'link' ) as $key ) {
			if ( isset( $part[ $key ]['label'], $part[ $key ]['url'] ) && is_string( $part[ $key ]['label'] ) && is_string( $part[ $key ]['url'] ) ) {
				$url = esc_url_raw( $part[ $key ]['url'], array( 'https' ) );
				if ( '' !== $url && '' !== trim( $part[ $key ]['label'] ) ) {
					$clean[ $key ] = array(
						'label' => $this->plain_text( $part[ $key ]['label'] ),
						'url'   => $url,
					);
				}
			}
		}

		if ( isset( $part['image'] ) && is_array( $part['image'] ) ) {
			$image = $part['image'];
			if (
				isset( $image['file'], $image['sha256'], $image['width'], $image['height'] )
				&& is_string( $image['file'] ) && preg_match( '/^[a-z0-9][a-z0-9._-]{0,80}\.(png|jpe?g|webp)$/', $image['file'] )
				&& is_string( $image['sha256'] ) && preg_match( '/^[a-f0-9]{64}$/', $image['sha256'] )
			) {
				$clean['image'] = array(
					'file'   => $image['file'],
					'sha256' => $image['sha256'],
					'width'  => max( 1, min( 600, (int) $image['width'] ) ),
					'height' => max( 1, min( 600, (int) $image['height'] ) ),
					'alt'    => isset( $image['alt'] ) && is_string( $image['alt'] ) ? $this->plain_text( $image['alt'] ) : '',
				);
			}
		}

		return ( isset( $clean['title'] ) || $clean['paragraphs'] ) ? $clean : null;
	}

	/**
	 * Text of an announcement field without HTML.
	 *
	 * @param string $text Text from the file.
	 * @return string
	 */
	private function plain_text( $text ) {
		return trim( wp_strip_all_tags( $text ) );
	}

	/**
	 * Allow only simple formatting and https links in announcement paragraphs. Every link opens in a new tab.
	 *
	 * @param string $html Paragraph HTML.
	 * @return string
	 */
	private function kses_message( $html ) {
		$html = wp_kses(
			force_balance_tags( $html ),
			array(
				'a'      => array( 'href' => true ),
				'strong' => array(),
				'em'     => array(),
				'br'     => array(),
				'span'   => array(
					'class'       => true,
					'aria-hidden' => true,
				),
			),
			array( 'https' )
		);

		// Rebuild each link from its parsed href. wp_kses() turns a link with another protocol into a relative one,
		// such links keep only their text.
		return preg_replace_callback(
			'#<a\b([^>]*)>(.*?)</a>#is',
			function ( $match ) {
				$attributes = wp_kses_hair( $match[1], array( 'https' ) );
				$href       = isset( $attributes['href']['value'] ) ? $attributes['href']['value'] : '';
				if ( 0 !== strpos( $href, 'https://' ) ) {
					return $match[2];
				}
				return '<a href="' . esc_url( $href ) . '" target="_blank" rel="noopener">' . $match[2] . '</a>';
			},
			$html
		);
	}

	/**
	 * The bundled eFaktúra announcement, shown until the announcements file is downloaded for the first time.
	 *
	 * The texts are in Slovak on purpose: the announcement is shown only for Slovak SuperFaktúra accounts,
	 * and translations from WordPress.org language packs would arrive later than the update itself.
	 *
	 * @return array
	 */
	private function get_bundled_messages() {
		$new_tab = '<span class="screen-reader-text"> (otvorí sa v novej karte)</span>';
		$blog    = 'https://www.superfaktura.sk/blog/superfaktura-zabezpecuje-dorucovanie-efaktur/';

		return array(
			array(
				'id'     => 'efaktura',
				'lang'   => array( 'sk' ),
				'until'  => self::EFAKTURA_UNTIL,
				'notice' => array(
					'eyebrow'    => 'Nová legislatívna povinnosť od 1. 1. 2027',
					'title'      => 'So SuperFaktúrou sú e-shopy na eFaktúru pripravené.',
					'paragraphs' => array(
						'Od 1. 1. 2027 budú platitelia DPH pri fakturácii firmám a verejnej správe povinne vystavovať a prijímať eFaktúry. So SuperFaktúrou máte na túto zmenu pripravené riešenie – od vystavenia cez odoslanie cez Peppol až po prijatie eFaktúry.',
						'Zatiaľ stačí si <a href="' . $blog . '" target="_blank" rel="noopener">vybrať SuperFaktúru ako svojho digitálneho poštára' . $new_tab . '</a>, o ďalších krokoch Vás budeme informovať <span aria-hidden="true">☺️</span>',
					),
					'cta'        => array(
						'label' => 'Aktivovať SuperFaktúru ako digitálneho poštára',
						'url'   => 'https://vpds.financnasprava.sk/vyber-certifikovaneho-poskytovatela?id=100',
					),
					'link'       => array(
						'label' => 'Čo je eFaktúra?',
						'url'   => 'https://www.superfaktura.sk/efaktura/',
					),
					'image'      => array(
						'bundled' => 'assets/images/efaktura-badge.png',
						'width'   => 130,
						'height'  => 93,
						'alt'     => 'U nás má eFaktúra zelenú!',
					),
				),
				'hero'   => array(
					'eyebrow'    => 'Nová legislatívna povinnosť od 1. 1. 2027',
					'title'      => 'So SuperFaktúrou sú e-shopy na eFaktúru pripravené.',
					'paragraphs' => array(
						'Od 1. 1. 2027 budú platitelia DPH pri fakturácii firmám a verejnej správe povinne vystavovať a prijímať eFaktúry. <strong>So SuperFaktúrou máte na túto zmenu pripravené riešenie – eFaktúru u nás vystavíte, odošlete cez sieť Peppol aj prijmete. Všetko priamo v prostredí SuperFaktúry, ktoré už poznáte a používate.</strong>',
						'Zatiaľ stačí jediné – <a href="' . $blog . '" target="_blank" rel="noopener">vybrať si SuperFaktúru ako svojho digitálneho poštára' . $new_tab . '</a>. O ďalších krokoch Vás budeme priebežne informovať <span aria-hidden="true">☺️</span>',
					),
					'cta'        => array(
						'label' => 'Aktivovať SuperFaktúru ako digitálneho poštára',
						'url'   => 'https://vpds.financnasprava.sk/vyber-certifikovaneho-poskytovatela?id=100',
					),
					'link'       => array(
						'label' => 'Čo je eFaktúra?',
						'url'   => 'https://www.superfaktura.sk/efaktura/',
					),
					'image'      => array(
						'bundled' => 'assets/images/efaktura-badge.png',
						'width'   => 260,
						'height'  => 186,
						'alt'     => 'U nás má eFaktúra zelenú!',
					),
				),
			),
		);
	}

	/**
	 * Announcements to choose from: the downloaded ones once a valid file was downloaded, the bundled ones before that.
	 *
	 * @return array With 'messages' and 'images' (data URIs keyed by checksum).
	 */
	private function get_messages() {
		if ( $this->remote_messages_enabled() ) {
			$stored = $this->get_stored_messages();
			if ( $stored ) {
				return array(
					'messages' => $stored['messages'],
					'images'   => $stored['images'],
				);
			}
		}

		return array(
			'messages' => $this->get_bundled_messages(),
			'images'   => array(),
		);
	}

	/**
	 * Key under which closing an announcement part is remembered. The eFaktúra announcement keeps the keys of version 1.56.0.
	 *
	 * @param string $id        Announcement id.
	 * @param string $placement 'notice' or 'hero'.
	 * @return string
	 */
	private function dismiss_key( $id, $placement ) {
		return 'efaktura' === $id ? 'efaktura_' . $placement : 'msg_' . $id . '_' . $placement;
	}

	/**
	 * The announcement to show to the current user in a placement.
	 *
	 * @param string $placement 'notice' (Dashboard, Plugins and WooCommerce screens) or 'hero' (on the plugin settings tab).
	 * @return array|null Announcement with the part to show under 'part' and its image source under 'image_src'.
	 */
	private function get_message( $placement ) {
		if ( array_key_exists( $placement, $this->shown_messages ) ) {
			return $this->shown_messages[ $placement ];
		}

		$this->shown_messages[ $placement ] = null;

		if ( ! current_user_can( 'manage_woocommerce' ) || ( defined( 'DOING_AJAX' ) && DOING_AJAX ) ) {
			return null;
		}

		$screen_ok = true;

		// The banner on the settings tab takes the place of the notice there.
		if ( 'notice' === $placement && $this->is_settings_tab() ) {
			$screen_ok = false;
		}

		// Elsewhere the notice appears only on the Dashboard, the Plugins screen and WooCommerce screens.
		if ( 'notice' === $placement && ! $this->is_notice_screen() ) {
			$screen_ok = false;
		}
		if ( 'hero' === $placement && ! $this->is_settings_tab() ) {
			$screen_ok = false;
		}

		// Keep the API log free for troubleshooting.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( 'hero' === $placement && isset( $_GET['section'] ) && 'api_log' === $_GET['section'] ) {
			$screen_ok = false;
		}

		// The stored announcements can be large, do not load them where nothing can be shown (unless a filter may force it).
		if ( ! $screen_ok && ! has_filter( 'sf_show_efaktura_banner' ) ) {
			return null;
		}

		$data      = $this->get_messages();
		$dismissed = $this->get_dismissed();
		$lang      = get_option( 'woocommerce_sf_lang', 'sk' );
		$today     = current_time( 'Y-m-d' );

		foreach ( $data['messages'] as $message ) {
			if ( ! is_array( $message ) || empty( $message['id'] ) || ! is_string( $message['id'] ) || empty( $message[ $placement ] ) || ! is_array( $message[ $placement ] ) ) {
				continue;
			}
			$message['lang'] = isset( $message['lang'] ) && is_array( $message['lang'] ) ? $message['lang'] : array();

			$show = $screen_ok;

			if ( $message['lang'] && ! in_array( $lang, $message['lang'], true ) ) {
				$show = false;
			}
			if ( isset( $message['from'] ) && $today < $message['from'] ) {
				$show = false;
			}
			if ( isset( $message['until'] ) && $today >= $message['until'] ) {
				$show = false;
			}
			if ( isset( $message['min_version'] ) && version_compare( $this->wc_sf->version, $message['min_version'], '<' ) ) {
				$show = false;
			}
			if ( isset( $message['max_version'] ) && version_compare( $this->wc_sf->version, $message['max_version'], '>' ) ) {
				$show = false;
			}
			if ( ! empty( $dismissed[ $this->dismiss_key( $message['id'], $placement ) ] ) ) {
				$show = false;
			}

			/**
			 * Filter whether an announcement is shown.
			 *
			 * @since 1.56.0
			 * @since 1.58.0 Applies to every announcement, the third parameter is the announcement id.
			 *
			 * @param bool   $show    Whether to show it.
			 * @param string $variant 'notice' (Dashboard, Plugins and WooCommerce screens) or 'hero' (on the plugin settings tab).
			 * @param string $id      Announcement id, 'efaktura' for the eFaktúra announcement.
			 */
			if ( ! apply_filters( 'sf_show_efaktura_banner', $show, $placement, $message['id'] ) ) {
				continue;
			}

			$part               = $message[ $placement ];
			$part['paragraphs'] = isset( $part['paragraphs'] ) && is_array( $part['paragraphs'] ) ? $part['paragraphs'] : array();
			$image_src          = '';
			$bundled            = ! empty( $part['image']['bundled'] ) ? $part['image']['bundled'] : ( self::BUNDLED_IMAGES[ $part['image']['sha256'] ?? '' ] ?? '' );
			if ( $bundled ) {
				// The plugin's own file, cached by the browser like in version 1.57.
				$image_src = esc_url( add_query_arg( 'ver', $this->wc_sf->version, plugins_url( $bundled, WC_SF_FILE_PATH ) ) );
			} elseif ( ! empty( $part['image']['sha256'] ) && isset( $data['images'][ $part['image']['sha256'] ] ) ) {
				// A data URI built from a downloaded image that matched its signed checksum.
				$image_src = esc_attr( $data['images'][ $part['image']['sha256'] ] );
			}

			$this->shown_messages[ $placement ] = array(
				'key'       => $this->dismiss_key( $message['id'], $placement ),
				'part'      => $part,
				'image_src' => $image_src,
			);
			break;
		}

		return $this->shown_messages[ $placement ];
	}

	/**
	 * Hidden text for screen readers telling that a link opens in a new tab, in the SuperFaktúra account language.
	 *
	 * @return string
	 */
	private function new_tab_text() {
		$texts = array(
			'sk' => ' (otvorí sa v novej karte)',
			'cz' => ' (otevře se v nové kartě)',
			'at' => ' (öffnet sich in einem neuen Tab)',
		);
		$lang  = get_option( 'woocommerce_sf_lang', 'sk' );

		return '<span class="screen-reader-text">' . esc_html( $texts[ $lang ] ?? $texts['sk'] ) . '</span>';
	}

	/**
	 * Print an announcement as a notice for the Dashboard, Plugins and WooCommerce screens.
	 *
	 * @param array $message Announcement from get_message().
	 */
	private function render_message_notice( $message ) {
		$part = $message['part'];
		?>
		<div class="notice notice-info is-dismissible wc-sf-efaktura-notice" data-wc-sf-notice="<?php echo esc_attr( $message['key'] ); ?>">
			<?php if ( $message['image_src'] ) : ?>
			<img class="wc-sf-efaktura-badge" src="<?php echo $message['image_src']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- esc_url() or esc_attr() in get_message() ?>" width="<?php echo (int) $part['image']['width']; ?>" height="<?php echo (int) $part['image']['height']; ?>" alt="<?php echo esc_attr( $part['image']['alt'] ); ?>">
			<?php endif; ?>
			<div class="wc-sf-efaktura-notice__body">
				<?php if ( ! empty( $part['eyebrow'] ) ) : ?>
				<p class="wc-sf-efaktura-eyebrow"><?php echo esc_html( $part['eyebrow'] ); ?></p>
				<?php endif; ?>
				<?php if ( ! empty( $part['title'] ) ) : ?>
				<p class="wc-sf-efaktura-notice__title"><strong><?php echo esc_html( $part['title'] ); ?></strong></p>
				<?php endif; ?>
				<?php foreach ( $part['paragraphs'] as $paragraph ) : ?>
				<p><?php echo $this->kses_message( $paragraph ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- filtered by kses_message() ?></p>
				<?php endforeach; ?>
				<?php if ( ! empty( $part['cta'] ) || ! empty( $part['link'] ) ) : ?>
				<p class="wc-sf-efaktura-actions">
					<?php if ( ! empty( $part['cta'] ) ) : ?>
					<a class="button button-primary" href="<?php echo esc_url( $part['cta']['url'] ); ?>" target="_blank" rel="noopener"><?php echo esc_html( $part['cta']['label'] ) . $this->new_tab_text(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped label and fixed markup ?></a>
					<?php endif; ?>
					<?php if ( ! empty( $part['link'] ) ) : ?>
					<a href="<?php echo esc_url( $part['link']['url'] ); ?>" target="_blank" rel="noopener"><?php echo esc_html( $part['link']['label'] ) . $this->new_tab_text(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped label and fixed markup ?></a>
					<?php endif; ?>
				</p>
				<?php endif; ?>
			</div>
		</div>
		<?php
	}

	/**
	 * Print the announcement banner under the section links of the plugin settings tab.
	 */
	public function render_efaktura_hero() {
		$message = $this->get_message( 'hero' );
		if ( ! $message ) {
			return;
		}

		$part   = $message['part'];
		$closes = array(
			'sk' => 'Skryť banner',
			'cz' => 'Skrýt banner',
			'at' => 'Banner ausblenden',
		);
		$close  = $closes[ get_option( 'woocommerce_sf_lang', 'sk' ) ] ?? $closes['sk'];
		?>
		<div class="wc-sf-efaktura-hero" data-wc-sf-notice="<?php echo esc_attr( $message['key'] ); ?>">
			<div class="wc-sf-efaktura-hero__body">
				<?php if ( ! empty( $part['eyebrow'] ) ) : ?>
				<p class="wc-sf-efaktura-hero__eyebrow"><?php echo esc_html( $part['eyebrow'] ); ?></p>
				<?php endif; ?>
				<?php if ( ! empty( $part['title'] ) ) : ?>
				<h2 class="wc-sf-efaktura-hero__title"><?php echo esc_html( $part['title'] ); ?></h2>
				<?php endif; ?>
				<?php foreach ( $part['paragraphs'] as $paragraph ) : ?>
				<p><?php echo $this->kses_message( $paragraph ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- filtered by kses_message() ?></p>
				<?php endforeach; ?>
				<?php if ( ! empty( $part['cta'] ) || ! empty( $part['link'] ) ) : ?>
				<p class="wc-sf-efaktura-hero__actions">
					<?php if ( ! empty( $part['cta'] ) ) : ?>
					<a class="wc-sf-efaktura-hero__cta" href="<?php echo esc_url( $part['cta']['url'] ); ?>" target="_blank" rel="noopener"><?php echo esc_html( $part['cta']['label'] ) . $this->new_tab_text(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped label and fixed markup ?></a>
					<?php endif; ?>
					<?php if ( ! empty( $part['link'] ) ) : ?>
					<a class="wc-sf-efaktura-hero__link" href="<?php echo esc_url( $part['link']['url'] ); ?>" target="_blank" rel="noopener"><?php echo esc_html( $part['link']['label'] ) . $this->new_tab_text(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped label and fixed markup ?></a>
					<?php endif; ?>
				</p>
				<?php endif; ?>
			</div>
			<?php if ( $message['image_src'] ) : ?>
			<img class="wc-sf-efaktura-hero__badge" src="<?php echo $message['image_src']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- esc_url() or esc_attr() in get_message() ?>" width="<?php echo (int) $part['image']['width']; ?>" height="<?php echo (int) $part['image']['height']; ?>" alt="<?php echo esc_attr( $part['image']['alt'] ); ?>">
			<?php endif; ?>
			<button type="button" class="wc-sf-efaktura-hero__close" data-wc-sf-dismiss aria-label="<?php echo esc_attr( $close ); ?>">
				<span class="dashicons dashicons-no-alt" aria-hidden="true"></span>
			</button>
		</div>
		<?php
	}

	/**
	 * Unresolved document failures from the last days, newest first.
	 *
	 * A failure counts until the same document of the same order is created or updated successfully later,
	 * or the document is attached to the order in another way. Failures of orders that are deleted, trashed,
	 * cancelled or failed are left out, and so is a failed proforma invoice when the invoice already exists.
	 *
	 * @return array With 'errors' (rows with id, order_id, document_type, request_type, response_status,
	 *               response_message and time), 'more' (whether more failures exist than kept) and 'max_id'
	 *               (the newest log entry id).
	 */
	private function get_errors() {
		$cached = get_transient( self::ERRORS_TRANSIENT );
		if ( is_array( $cached ) && isset( $cached['errors'], $cached['max_id'], $cached['more'] ) ) {
			return $cached;
		}

		global $wpdb;
		$table  = $wpdb->prefix . 'wc_sf_log';
		$cutoff = gmdate( 'Y-m-d H:i:s', current_time( 'timestamp' ) - self::ERRORS_DAYS * DAY_IN_SECONDS );

		// A site of a network may not have the log table yet; do not print database errors on admin pages.
		$suppress = $wpdb->suppress_errors( true );

		// Look only at the newest entries, so a large log does not slow down the admin.
		$max_id = (int) $wpdb->get_var( "SELECT MAX(id) FROM {$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows   = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id, order_id, document_type, request_type, response_status, response_message, time FROM {$table} WHERE id > %d AND id <= %d AND time >= %s AND request_type IN ('create', 'edit') ORDER BY id DESC LIMIT 2000", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				max( 0, $max_id - 20000 ),
				$max_id,
				$cutoff
			),
			ARRAY_A
		);

		$wpdb->suppress_errors( $suppress );

		$errors = array();
		$more   = false;
		$seen   = array();
		foreach ( (array) $rows as $row ) {
			$key = $row['order_id'] . '|' . $row['document_type'];
			if ( isset( $seen[ $key ] ) ) {
				// A newer entry for this document already decided it.
				continue;
			}
			$seen[ $key ] = true;

			if ( empty( $row['response_status'] ) ) {
				// The newest attempt succeeded.
				continue;
			}

			$order = $row['order_id'] ? wc_get_order( $row['order_id'] ) : false;
			if ( ! $order || in_array( $order->get_status(), array( 'trash', 'cancelled', 'failed' ), true ) ) {
				continue;
			}

			// A retry or the related invoice from SuperFaktúra can attach the document without a log entry.
			if ( 'create' === $row['request_type'] && $order->get_meta( 'wc_sf_internal_' . $row['document_type'] . '_id', true ) ) {
				continue;
			}

			// The proforma invoice is no longer needed once the invoice exists.
			if ( 'proforma' === $row['document_type'] && $order->get_meta( 'wc_sf_internal_regular_id', true ) ) {
				continue;
			}

			if ( count( $errors ) >= self::ERRORS_LIMIT ) {
				$more = true;
				break;
			}

			$errors[] = $row;
		}

		$cached = array(
			'errors' => $errors,
			'more'   => $more,
			'max_id' => $max_id,
		);
		set_transient( self::ERRORS_TRANSIENT, $cached, HOUR_IN_SECONDS );

		return $cached;
	}

	/**
	 * Unresolved failures the current user has not closed yet.
	 *
	 * @return array With 'errors' (newest first) and 'more' (whether more failures exist than listed).
	 */
	private function get_user_errors() {
		if ( null !== $this->user_errors ) {
			return $this->user_errors;
		}

		$this->user_errors = array(
			'errors' => array(),
			'more'   => false,
		);

		if ( ! current_user_can( 'manage_woocommerce' ) || ( defined( 'DOING_AJAX' ) && DOING_AJAX ) ) {
			return $this->user_errors;
		}

		$dismissed = $this->get_dismissed();
		$seen      = isset( $dismissed['document_errors'] ) ? (int) $dismissed['document_errors'] : 0;
		$data      = $this->get_errors();

		// The log numbering started over (e.g. the table was emptied), so the stored id no longer applies.
		if ( $seen > $data['max_id'] ) {
			$seen = 0;
		}

		$errors = array_values(
			array_filter(
				$data['errors'],
				function ( $row ) use ( $seen ) {
					return (int) $row['id'] > $seen;
				}
			)
		);

		// Failures beyond the kept ones are older than all kept ones, so they matter only if the oldest kept one is new to the user.
		$last = end( $data['errors'] );
		$more = $data['more'] && $last && (int) $last['id'] > $seen;

		/**
		 * Filter the failed documents reported in the admin.
		 *
		 * @since 1.56.0
		 *
		 * @param array $errors Unresolved failures, newest first, each with id, order_id, document_type, request_type,
		 *                      response_status, response_message and time. Return an empty array to hide the warning.
		 */
		$errors = (array) apply_filters( 'sf_admin_document_errors', $errors );

		// Keep only complete rows of orders that still exist, whatever a filter returned.
		$errors = array_values(
			array_filter(
				$errors,
				function ( $row ) {
					return is_array( $row ) && isset( $row['id'], $row['order_id'], $row['document_type'], $row['time'] ) && array_key_exists( 'response_message', $row );
				}
			)
		);

		$this->user_errors = array(
			'errors' => $errors,
			'more'   => $more && $errors,
		);

		return $this->user_errors;
	}

	/**
	 * Print the warning about documents that could not be created or updated.
	 */
	private function render_document_errors() {
		$data   = $this->get_user_errors();
		$errors = $data['errors'];
		if ( ! $errors ) {
			return;
		}

		$types = array(
			'regular'  => __( 'Invoice', 'woocommerce-superfaktura' ),
			'proforma' => __( 'Proforma invoice', 'woocommerce-superfaktura' ),
			'cancel'   => __( 'Credit note', 'woocommerce-superfaktura' ),
		);

		$count   = count( $errors );
		$shown   = array_slice( $errors, 0, 3 );
		$newest  = max( array_map( 'intval', wp_list_pluck( $errors, 'id' ) ) );
		$log_url = admin_url( 'admin.php?page=wc-settings&tab=superfaktura&section=api_log' );
		?>
		<div class="notice notice-error is-dismissible wc-sf-document-errors" data-wc-sf-notice="document_errors" data-wc-sf-value="<?php echo esc_attr( $newest ); ?>">
			<p>
				<strong><?php esc_html_e( 'SuperFaktúra', 'woocommerce-superfaktura' ); ?></strong>:
				<?php
				if ( $data['more'] ) {
					// Translators: %d Number of documents.
					echo esc_html( sprintf( __( 'More than %d documents could not be created or updated in SuperFaktúra in the last 30 days.', 'woocommerce-superfaktura' ), $count ) );
				} else {
					// Translators: %d Number of documents.
					echo esc_html( sprintf( _n( '%d document could not be created or updated in SuperFaktúra in the last 30 days.', '%d documents could not be created or updated in SuperFaktúra in the last 30 days.', $count, 'woocommerce-superfaktura' ), $count ) );
				}
				?>
			</p>
			<ul class="wc-sf-document-errors__list">
				<?php
				foreach ( $shown as $row ) :
					$order = wc_get_order( $row['order_id'] );
					if ( ! $order ) {
						continue;
					}
					$type = isset( $types[ $row['document_type'] ] ) ? $types[ $row['document_type'] ] : $row['document_type'];
					?>
					<li>
						<a href="<?php echo esc_url( $order->get_edit_order_url() ); ?>">
							<?php
							// Translators: %s Order number.
							echo esc_html( sprintf( __( 'Order #%s', 'woocommerce-superfaktura' ), $order->get_order_number() ) );
							?>
						</a>
						– <?php echo esc_html( $type ); ?>: <?php echo esc_html( preg_replace( '/ in \S+:\d+$/', '', wp_strip_all_tags( (string) $row['response_message'] ) ) ); ?>
						<span class="wc-sf-document-errors__time">(<?php echo esc_html( mysql2date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $row['time'] ) ); ?>)</span>
						<?php
						$explanation = $this->wc_sf->get_log_explanation( $row );
						if ( $explanation ) {
							echo '<br><span class="wc-sf-document-errors__explanation">' . wp_kses( $explanation, array( 'a' => array( 'href' => array() ) ) ) . '</span>';
						}
						?>
					</li>
				<?php endforeach; ?>
				<?php if ( $data['more'] ) : ?>
					<li><?php esc_html_e( 'and more', 'woocommerce-superfaktura' ); ?></li>
				<?php elseif ( $count > count( $shown ) ) : ?>
					<li>
						<?php
						$more = $count - count( $shown );
						// Translators: %d Number of further documents.
						echo esc_html( sprintf( _n( 'and %d more', 'and %d more', $more, 'woocommerce-superfaktura' ), $more ) );
						?>
					</li>
				<?php endif; ?>
			</ul>
			<p><a href="<?php echo esc_url( $log_url ); ?>"><?php esc_html_e( 'Open API log', 'woocommerce-superfaktura' ); ?></a></p>
		</div>
		<?php
	}
}
