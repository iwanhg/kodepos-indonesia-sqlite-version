<?php
/**
 * Diagnostics page under WooCommerce showing the status of the bundled
 * Kodepos SQLite data, no credentials to configure, nothing to save.
 *
 * @package Kodepos_Indonesia
 */

defined( 'ABSPATH' ) || exit;

class Kodepos_Settings {

	const PAGE_SLUG = 'kodepos-indonesia';

	/** @var Kodepos_DB */
	private $db;

	public function __construct( Kodepos_DB $db ) {
		$this->db = $db;

		add_action( 'admin_menu', array( $this, 'add_menu' ) );
	}

	public function add_menu() {
		add_submenu_page(
			'woocommerce',
			__( 'Kodepos Indonesia', 'kodepos-indonesia-sqlite-version' ),
			__( 'Kodepos Indonesia', 'kodepos-indonesia-sqlite-version' ),
			'manage_woocommerce',
			self::PAGE_SLUG,
			array( $this, 'render_page' )
		);
	}

	public function render_page() {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}

		$extension_ok = extension_loaded( 'pdo_sqlite' );
		$available    = $this->db->is_available();
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Kodepos Indonesia', 'kodepos-indonesia-sqlite-version' ); ?></h1>
			<p><?php esc_html_e( 'Cascading Indonesian address dropdowns are powered by a postal code database bundled with this plugin, no configuration needed.', 'kodepos-indonesia-sqlite-version' ); ?></p>

			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><?php esc_html_e( 'PHP pdo_sqlite extension', 'kodepos-indonesia-sqlite-version' ); ?></th>
					<td><?php echo $extension_ok ? '✅ ' . esc_html__( 'Enabled', 'kodepos-indonesia-sqlite-version' ) : '❌ ' . esc_html__( 'Not enabled', 'kodepos-indonesia-sqlite-version' ); ?></td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Postal code database', 'kodepos-indonesia-sqlite-version' ); ?></th>
					<td><?php echo $available ? '✅ ' . esc_html__( 'Loaded', 'kodepos-indonesia-sqlite-version' ) : '❌ ' . esc_html__( 'Unavailable', 'kodepos-indonesia-sqlite-version' ); ?></td>
				</tr>
				<?php if ( $available ) : ?>
				<tr>
					<th scope="row"><?php esc_html_e( 'Data version', 'kodepos-indonesia-sqlite-version' ); ?></th>
					<td><?php echo esc_html( $this->db->get_data_version() ); ?></td>
				</tr>
				<tr>
					<th scope="row"><?php esc_html_e( 'Rows', 'kodepos-indonesia-sqlite-version' ); ?></th>
					<td><?php echo esc_html( number_format_i18n( (int) $this->db->get_row_count() ) ); ?></td>
				</tr>
				<?php endif; ?>
			</table>

			<?php if ( ! $available ) : ?>
				<p class="description">
					<?php
					echo $extension_ok
						? esc_html__( 'The bundled data/kodepos.sqlite file could not be read. Reinstalling the plugin usually fixes this.', 'kodepos-indonesia-sqlite-version' )
						: esc_html__( 'Ask your hosting provider to enable the pdo_sqlite PHP extension, which ships with virtually all PHP builds.', 'kodepos-indonesia-sqlite-version' );
					?>
				</p>
			<?php endif; ?>
		</div>
		<?php
	}
}
