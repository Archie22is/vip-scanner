<?php
/*
Plugin Name: VIP Scanner
Plugin URI: http://vip.wordpress.com
Description: Easy to use UI for the VIP Scanner.
Author: Automattic (Original code by Pross, Otto42, and Thorsten Ott)
Version: 0.3

License: GPLv2
*/
require_once( dirname( __FILE__ ) . '/vip-scanner/vip-scanner.php' );

class VIP_Scanner_UI {
	const key = 'vip-scanner';

	private static $instance;
	private $blocker_types;

	function __construct() {
		add_action( 'init', array( $this, 'init' ) );
		add_action( 'admin_init', array( $this, 'admin_init' ) );
		do_action( 'vip_scanner_loaded' );

		$this->blocker_types = apply_filters( 'vip_scanner_blocker_types', array(
			'blocker'  => __( 'Blockers', 'theme-check' ),
			'warning'  => __( 'Warnings', 'theme-check' ),
			'required' => __( 'Required', 'theme-check' ),
		) );
	}

	function init() {
		add_action( 'admin_menu', array( $this, 'add_menu_page' ) );
	}

	function admin_init() {
		if ( isset( $_POST['page'], $_POST['action'] ) && $_POST['page'] == self::key && $_POST['action'] == 'export' )
			$this->export();
	}

	static function get_instance() {
		if ( ! isset( self::$instance ) ) {
			$class_name = __CLASS__;
			self::$instance = new $class_name;
		}
		return self::$instance;
	}

	function add_menu_page() {
		$hook = add_submenu_page( 'tools.php', 'VIP Scanner', 'VIP Scanner', 'manage_options', self::key, array( $this, 'display_admin_page' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'admin_enqueue_scripts' ) );
	}

	function admin_enqueue_scripts( $hook ) {
		if ( 'tools_page_' . self::key !== $hook )
			return;

		wp_enqueue_style( 'vip-scanner-css', plugins_url( 'css/vip-scanner.css', __FILE__ ), array(), '20120320' );
	}

	function display_admin_page() {
		global $title;

		if ( !current_user_can( 'manage_options' ) )  {
			wp_die( __( 'You do not have sufficient permissions to access this page.' ) );
		}

		?>
		<div id="vip-scanner" class="wrap">
			<?php screen_icon( 'themes' ); ?>
			<h2><?php echo esc_html( $title ); ?></h2>
			<?php $this->display_vip_scanner_form(); ?>
			<?php $this->do_theme_review(); ?>
		</div>
		<?php
	}

	function display_vip_scanner_form() {
		$themes = wp_get_themes();
		$review_types = VIP_Scanner::get_instance()->get_review_types();
		$current_theme = isset( $_POST[ 'vip-scanner-theme-name' ] ) ? sanitize_text_field( $_POST[ 'vip-scanner-theme-name' ] ) : get_stylesheet();
		$current_review = isset( $_POST[ 'vip-scanner-review-type' ] ) ? sanitize_text_field( $_POST[ 'vip-scanner-review-type' ] ) : $review_types[0]; // TODO: eugh, need better error checking
		?>
		<form method="POST">
			<p>Select a theme and the review that you want to run:</p>
			<select name="vip-scanner-theme-name">
				<?php foreach ( $themes as $name => $location ) : ?>
					<option <?php selected( $current_theme, $location['Stylesheet'] ); ?> value="<?php echo esc_attr( $location['Stylesheet'] ); ?>"><?php echo esc_html( $location['Name'] ); ?></option>
				<?php endforeach; ?>
			</select>
			<select name="vip-scanner-review-type">
				<?php foreach ( $review_types as $review_type ) : ?>
					<option <?php selected( $current_review, $review_type ); ?> value="<?php echo esc_attr( $review_type ); ?>"><?php echo esc_html( $review_type ); ?></option>
				<?php endforeach; ?>
			</select>
			<?php submit_button( 'Check it!', 'primary', 'submit', false ); ?>
			<?php wp_nonce_field( 'vip-scan-theme', 'vip-scanner-nonce' ); ?>
			<input type="hidden" name="page" value="<?php echo self::key; ?>" />
		</form>
		<?php
	}

	function do_theme_review() {
		if( ! isset( $_POST[ 'vip-scanner-nonce' ] ) || ! wp_verify_nonce( $_POST[ 'vip-scanner-nonce' ], 'vip-scan-theme' ) )
			return;

		if ( ! isset( $_POST[ 'vip-scanner-theme-name' ] ) )
			return;

		$theme = sanitize_text_field( $_POST[ 'vip-scanner-theme-name' ] );
		$review = isset( $_POST[ 'vip-scanner-review-type' ] ) ? sanitize_text_field( $_POST[ 'vip-scanner-review-type' ] ) : $review_types[0]; // TODO: eugh, need better error checking

		$scanner = VIP_Scanner::get_instance()->run_theme_review( $theme, $review );
		if ( $scanner ):
			$this->display_theme_review_result( $scanner, $theme );

			if ( count( $scanner->get_errors() ) ):
			?>

			<hr>

			<h2>Export Theme for VIP Review</h2>
			<p><?php _e( 'Since some errors were detected, please provide a clear and concise explanation of the results before submitting the theme for review.', 'theme-check' ); ?></p>

			<form method="POST">
				<textarea name="summary"></textarea>
				<?php submit_button( __( 'Export', 'theme-check' ) ); ?>
				<?php wp_nonce_field( 'export' ); ?>
				<input type="hidden" name="action" value="export">
				<input type="hidden" name="theme" value="<?php echo esc_attr( $theme ) ?>">
				<input type="hidden" name="review" value="<?php echo esc_attr( $review ) ?>">
				<input type="hidden" name="page" value="<?php echo self::key; ?>">
			</form>

		<?php
			endif;
		else:
			$this->display_scan_error();
		endif;
	}

	function display_theme_review_result( $scanner, $theme ) {
		global $SyntaxHighlighter;
		if ( isset( $SyntaxHighlighter ) ) {
			add_action( 'admin_footer', array( &$SyntaxHighlighter, 'maybe_output_scripts' ) );
		}

		$report   = $scanner->get_results();
		$blockers = $scanner->get_errors( array_keys( $this->blocker_types ) );
		$pass     = ! count( $blockers );
		$errors   = count($blockers);
		$notes    = count($scanner->get_errors()) - $errors;
		
		?>
		<div class="scan-info">
			Scanned Theme: <span class="theme-name"><?php echo $theme; ?></span>
		</div>
		
		<div class="scan-results result-<?php echo $pass ? 'pass' : 'fail'; ?>"><?php echo $pass ? __( 'Passed the Scan with no errors!', 'theme-check' ) : __( 'Failed to pass Scan', 'theme-check' ); ?></div>
		
		<table class="scan-results-table">
			<tr>
				<th><?php _e( 'Total Files', 'theme-check' ); ?></th>
				<td><?php echo intval( $report['total_files'] ); ?></td>
			</tr>
			<tr>
				<th><?php _e( 'Total Checks', 'theme-check' ); ?></th>
				<td><?php echo intval( $report['total_checks'] ); ?></td>
			</tr>
		</table>
		
		<h2 class="nav-tab-wrapper"><?php // Note: These are static tabs ?>
			<a href="#" class="nav-tab nav-tab-active"><?php echo $errors; ?> <?php echo __( 'Errors', 'theme-check' ); ?></a>
			<a href="#" class="nav-tab"><?php echo $notes; ?> <?php echo __( 'Notes', 'theme-check' ); ?></a>
		</h2>

		<?php foreach( $this->blocker_types as $type => $title ):
			$errors = $scanner->get_errors( array( $type ) );

			if ( ! count( $errors ) )
				continue;
			?>
			<h3><?php echo esc_html( $title ); ?></h3>
			<ol class="scan-results-list">
				<?php
				foreach( $errors as $result ) {
					$this->display_theme_review_result_row( $result, $scanner, $theme );
				}
				?>
			</ol>
		<?php endforeach; ?>
		<?php
	}

	function display_theme_review_result_row( $error, $scanner, $theme ) {
		global $SyntaxHighlighter;

		$level = $error['level'];
		$description = $error['description'];

		$file = '';
		if ( is_array( $error['file'] ) ) {
			if ( ! empty( $error['file'][0] ) )
				$file .= $error['file'][0];
			if ( ! empty( $error['file'][1] ) )
				$file .= ': ' . $error['file'][1];
		} else if ( ! empty( $error['file'] ) ) {
			$file_full_path = $error['file'];
			$file_theme_path = substr( $file_full_path, strrpos( $file_full_path, sprintf( '/%s/', $theme ) ) );
			$file = strrchr( $file_full_path, sprintf( '/%s/', $theme ) );
			if ( ! $file && ! empty( $file_theme_path ) )
				$file = $file_theme_path;
		}

		$lines = ! empty( $error['lines'] ) ? $error['lines'] : array();

		?>
		<li class="scan-result-<?php echo strtolower( $level ); ?>">
			<span class="scan-description"><?php echo $description; ?></span>

			<?php if( ! empty( $file ) ) : ?>
				<span class="scan-file">
					<?php echo $file; ?>
				</span>
			<?php endif; ?>

			<?php if( ! empty( $lines ) ) : ?>
				<div class="scan-lines">
				<?php foreach( $lines as $line ) : ?>
					<div class="scan-line">
						<?php
						if ( isset( $SyntaxHighlighter ) ) {
							// TODO: Should detect file type and set appropriate brush
							$line_shortcode = '[sourcecode language="php" htmlscript="true" light="true"]' . html_entity_decode( $line ) . '[/sourcecode]';
							echo $SyntaxHighlighter->parse_shortcodes( $line_shortcode );
						} else {
							echo '<pre>' . html_entity_decode( $line ) . '</pre>';
						}
						?>
					</div>
				<?php endforeach; ?>
				</div>
			<?php endif; ?>

		</li>
		<?php
	}

	function display_plaintext_result_row( $error, $theme ) {
		$description = $error['description'];

		$file = '';
		if ( is_array( $error['file'] ) ) {
			if ( ! empty( $error['file'][0] ) )
				$file .= $error['file'][0];
			if ( ! empty( $error['file'][1] ) )
				$file .= ': ' . $error['file'][1];
		} else if ( ! empty( $error['file'] ) ) {
			$file_full_path = $error['file'];
			$file_theme_path = substr( $file_full_path, strrpos( $file_full_path, sprintf( '/%s/', $theme ) ) );
			$file = strrchr( $file_full_path, sprintf( '/%s/', $theme ) );
			if ( ! $file && ! empty( $file_theme_path ) )
				$file = $file_theme_path;
		}

		echo "* $file - $description" . PHP_EOL;
	}

	function display_scan_error() {
		echo 'Uh oh! Looks like something went wrong :(';
	}

	function export() {

		// Check nonce and permissions
		check_admin_referer( 'export' );

		if ( ! isset( $_POST['theme'] ) )
			return;

		if ( ! isset( $_POST['review'] ) )
			return;

		$theme = sanitize_text_field( $_POST[ 'theme' ] );
		$review = sanitize_text_field( $_POST[ 'review' ] );
		$summary = $_POST['summary'];
		$scanner = VIP_Scanner::get_instance()->run_theme_review( $theme, $review );

		if ( $scanner ) {
			$report   = $scanner->get_results();
			$blockers = count( $scanner->get_errors( array_keys( $this->blocker_types ) ) );

			$filename = date( 'Ymd' ) . '.' . $theme . '.' . $review . '.VIP-Scanner.txt';
			header( 'Content-Type: text/plain' );
			//header( 'Content-Disposition: attachment; filename="' . $filename . '"' );

			$title = "$theme - $review";
			echo $title . PHP_EOL;
			echo str_repeat( '=', strlen( $title ) ) . PHP_EOL . PHP_EOL;

			_e( 'Total Files', 'theme-check' );
			echo ':  ';
			echo intval( $report['total_files'] );
			echo PHP_EOL;

			_e( 'Total Checks', 'theme-check' );
			echo ': ';
			echo intval( $report['total_checks'] );
			echo PHP_EOL;

			_e( 'Errors', 'theme-check' );
			echo ':       ';
			echo intval( $blockers );
			echo PHP_EOL;

			echo PHP_EOL;

			foreach( $this->blocker_types as $type => $title ) {
				$errors = $scanner->get_errors( array( $type ) );

				if ( ! count( $errors ) )
					continue;

				echo "## " . esc_html( $title ) . PHP_EOL;

				foreach ( $errors as $result )
					$this->display_plaintext_result_row( $result, $theme );

				echo PHP_EOL;
			}

			echo "## Summary" . PHP_EOL;
			echo strip_tags( $summary ?: 'No summary given.' );
			exit;
		}

		// redirect with error message
	}
}

// Initialize!
VIP_Scanner_UI::get_instance();
