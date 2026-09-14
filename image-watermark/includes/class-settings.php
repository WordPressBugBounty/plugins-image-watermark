<?php
// exit if accessed directly
if ( ! defined( 'ABSPATH' ) )
	exit;

/**
 * Image Watermark settings class.
 *
 * @class Image_Watermark_Settings
 */
class Image_Watermark_Settings {
	private $plugin;

	/**
	 * Class constructor.
	 *
	 * @return void
	 */
	public function __construct( $plugin )	{
		$this->plugin = $plugin;

		// filters
		add_filter( 'wp_redirect', [ $this, 'preserve_tab_on_redirect' ], 10, 2 );
		
		// Initialize Settings API
		add_filter( 'iw_settings_pages', [ $this, 'settings_pages' ] );
		add_filter( 'iw_settings_data', [ $this, 'settings_data' ] );
		add_action( 'iw_settings_form', [ $this, 'settings_form' ], 10, 4 );
		add_action( 'iw_settings_sidebar', [ $this, 'render_settings_sidebar' ], 10, 4 );
		
		new Image_Watermark_Settings_API( [
			'domain'     => 'image-watermark',
			'prefix'     => 'iw',
			'slug'       => 'image-watermark',
			'plugin'     => 'Image Watermark',
			'plugin_url' => IMAGE_WATERMARK_URL,
			'asset_version' => $this->plugin->defaults['version'],
			'object'     => $this->plugin,
			'nested'     => true
		] );
	}

	/**
	 * Settings pages configuration.
	 * 
	 * @param array $pages
	 * @return array
	 */
	public function settings_pages( $pages ) {
		$pages['image-watermark'] = [
			'menu_slug'  => 'image-watermark',
			'page_title' => __( 'Image Watermark Options', 'image-watermark' ),
			'menu_title' => __( 'Watermark', 'image-watermark' ),
			'capability' => 'manage_options',
			'type'       => 'settings_page',
			'tabs'       => $this->get_settings_data()
		];
		
		return $pages;
	}

	/**
	 * Settings data configuration.
	 * 
	 * @param array $settings
	 * @return array
	 */
	public function settings_data( $settings ) {
		return $this->get_settings_data();
	}
	
	/**
	 * Render hidden inputs for settings form.
	 */
	public function settings_form( $setting, $page_type, $url_page, $tab_key ) {
		echo '<input type="hidden" name="iw_current_tab" value="' . esc_attr( $tab_key ) . '" />';
	}

	/**
	 * Render settings sidebar.
	 *
	 * @param string $setting
	 * @param string $page_type
	 * @param string $url_page
	 * @param string $tab_key
	 * @return void
	 */
	public function render_settings_sidebar( $setting, $page_type, $url_page, $tab_key ) {
		if ( $page_type !== 'settings_page' ) {
			return;
		}

		$version = isset( $this->plugin->defaults['version'] ) ? $this->plugin->defaults['version'] : '';
		$docs_link = sprintf(
			'<a href="%s" target="_blank">%s</a>',
			esc_url( 'http://www.dfactory.co/docs/image-watermark/?utm_source=image-watermark-settings&utm_medium=link&utm_campaign=docs' ),
			esc_html__( 'Documentation', 'image-watermark' )
		);
		$support_link = sprintf(
			'<a href="%s" target="_blank">%s</a>',
			esc_url( 'http://www.dfactory.co/support/?utm_source=image-watermark-settings&utm_medium=link&utm_campaign=support' ),
			esc_html__( 'Support forum', 'image-watermark' )
		);
		$rate_link = sprintf(
			'<a href="%s" target="_blank">%s</a>',
			esc_url( 'https://wordpress.org/support/plugin/image-watermark/reviews/' ),
			esc_html__( 'Rate it 5 stars', 'image-watermark' )
		);
		$plugin_link = sprintf(
			'<a href="%s" target="_blank">%s</a>',
			esc_url( 'http://www.dfactory.co/products/image-watermark/?utm_source=image-watermark-settings&utm_medium=link&utm_campaign=blog-about' ),
			esc_html__( 'plugin page', 'image-watermark' )
		);
		$other_link = sprintf(
			'<a href="%s" target="_blank">%s</a>',
			esc_url( 'http://www.dfactory.co/products/?utm_source=image-watermark-settings&utm_medium=link&utm_campaign=other-plugins' ),
			esc_html__( 'WordPress plugins', 'image-watermark' )
		);
		?>
		<div class="df-credits">
			<h3 class="hndle"><?php echo esc_html__( 'Image Watermark', 'image-watermark' ) . ' ' . esc_html( $version ); ?></h3>
			<div class="inside">
				<h4 class="inner"><?php esc_html_e( 'Need support?', 'image-watermark' ); ?></h4>
				<p class="inner">
					<?php
					/* translators: 1: Documentation link. 2: Support forum link. */
					$message = __( 'If you are having problems with this plugin, please browse its %1$s or ask in the %2$s.', 'image-watermark' );
					printf( wp_kses_post( $message ), wp_kses_post( $docs_link ), wp_kses_post( $support_link ) );
					?>
				</p>
				<hr />
				<h4 class="inner"><?php esc_html_e( 'Do you like this plugin?', 'image-watermark' ); ?></h4>
				<p class="inner">
					<?php
					/* translators: Link to the plugin review page. */
					$message = __( '%s on WordPress.org', 'image-watermark' );
					printf( wp_kses_post( $message ), wp_kses_post( $rate_link ) );
					echo '<br />';
					/* translators: Link to the plugin page. */
					$message = __( 'Blog about it and link to the %s.', 'image-watermark' );
					printf( wp_kses_post( $message ), wp_kses_post( $plugin_link ) );
					echo '<br />';
					/* translators: Link to the developer's other WordPress plugins. */
					$message = __( 'Check out our other %s.', 'image-watermark' );
					printf( wp_kses_post( $message ), wp_kses_post( $other_link ) );
					?>
				</p>
				<hr />
				<p class="df-link inner">
					<a href="<?php echo esc_url( 'http://www.dfactory.co/?utm_source=image-watermark-settings&utm_medium=link&utm_campaign=created-by' ); ?>" target="_blank" title="<?php esc_attr_e( 'Digital Factory', 'image-watermark' ); ?>">
						<img src="<?php echo esc_url( IMAGE_WATERMARK_URL . '/images/df-black-sm.png' ); ?>" alt="<?php esc_attr_e( 'Digital Factory', 'image-watermark' ); ?>" />
					</a>
				</p>
			</div>
		</div>
		<?php
	}

	/**
	 * Preserve tab parameter in redirect after saving settings.
	 *
	 * @param string $location
	 * @param int $status
	 * @return string
	 */
	public function preserve_tab_on_redirect( $location, $status ) {
		// Only on settings update
		if ( strpos( $location, 'page=image-watermark' ) === false )
			return $location;

		// Get the tab from POST or current
		$tab = isset( $_POST['iw_current_tab'] ) ? sanitize_key( $_POST['iw_current_tab'] ) : '';

		if ( empty( $tab ) )
			$tab = isset( $_GET['tab'] ) ? sanitize_key( $_GET['tab'] ) : 'watermark';

		// Add or update tab parameter
		$location = add_query_arg( 'tab', $tab, $location );

		return $location;
	}

	/**
	 * Get settings data.
	 *
	 * @return array
	 */
	public function get_settings_data() {
		$page_heading = __( 'Image Watermark', 'image-watermark' );
		$image_sizes = get_intermediate_image_sizes();
		$image_sizes[] = 'full';
		sort( $image_sizes, SORT_STRING );
		$image_sizes_options = array_combine( $image_sizes, $image_sizes );
		$post_types = array_merge( [ 'post', 'page' ], get_post_types( [ '_builtin' => false ], 'names' ) );
		sort( $post_types, SORT_STRING );
		$post_type_options = array_combine( $post_types, $post_types );
		$watermark_on_value = [];
		if ( ! empty( $this->plugin->options['watermark_on'] ) && is_array( $this->plugin->options['watermark_on'] ) ) {
			$watermark_on_value = array_keys( $this->plugin->options['watermark_on'] );
		}
		$watermark_apply_on_value = 'everywhere';
		if ( ! empty( $this->plugin->options['watermark_apply_on'] ) && in_array( $this->plugin->options['watermark_apply_on'], [ 'everywhere', 'post_types' ], true ) ) {
			$watermark_apply_on_value = $this->plugin->options['watermark_apply_on'];
		} elseif ( ! empty( $this->plugin->options['watermark_cpt_on'] ) && is_array( $this->plugin->options['watermark_cpt_on'] ) ) {
			$cpt_on = $this->plugin->options['watermark_cpt_on'];
			$is_list = array_values( $cpt_on ) === $cpt_on;
			$has_everywhere = $is_list ? in_array( 'everywhere', $cpt_on, true ) : array_key_exists( 'everywhere', $cpt_on );
			$watermark_apply_on_value = $has_everywhere ? 'everywhere' : 'post_types';
		}
		$watermark_cpt_value = [];
		if ( ! empty( $this->plugin->options['watermark_cpt_on'] ) && is_array( $this->plugin->options['watermark_cpt_on'] ) ) {
			$cpt_on = $this->plugin->options['watermark_cpt_on'];
			$is_list = array_values( $cpt_on ) === $cpt_on;
			$watermark_cpt_value = $is_list ? $cpt_on : array_keys( $cpt_on );
			$watermark_cpt_value = array_values( array_diff( $watermark_cpt_value, [ 'everywhere' ] ) );
		}
		$settings = [
			'watermark' => [
				'option_name' => 'image_watermark_options',
				'validate'    => [ $this, 'validate_settings' ],
				'label'       => __( 'Watermark', 'image-watermark' ),
				'heading'     => $page_heading,
				'sections'    => [
					'image_watermark_general' => [
						'title' => __( 'Applying Watermark', 'image-watermark' ),
					],
					'image_watermark_position' => [
						'title' => __( 'Watermark Position', 'image-watermark' ),
					],
					'image_watermark_image' => [
						'title' => __( 'Watermark Settings', 'image-watermark' ),
					],
				],
				'fields'      => [
					// General Section
					'extension' => [
						'title'       => __( 'Image Processor', 'image-watermark' ),
						'section'     => 'image_watermark_general',
						'type'        => 'select',
						'parent'      => 'watermark_image',
						'options'     => $this->plugin->extensions,
						'description' => __( 'Select the image processing extension.', 'image-watermark' ),
					],
					'plugin_off' => [
						'title'   => __( 'Automatic Watermarking', 'image-watermark' ),
						'section' => 'image_watermark_general',
						'type'    => 'boolean',
						'parent'  => 'watermark_image',
						'label'   => __( 'Enable watermark for uploaded images.', 'image-watermark' ),
					],
					'manual_watermarking' => [
						'title'   => __( 'Manual Watermarking', 'image-watermark' ),
						'section' => 'image_watermark_general',
						'type'    => 'boolean',
						'parent'  => 'watermark_image',
						'label'   => __( 'Enable Apply Watermark option for Media Library images.', 'image-watermark' ),
					],
					'frontend_active' => [
						'title'   => __( 'Front-end Watermarking', 'image-watermark' ),
						'section' => 'image_watermark_general',
						'type'    => 'boolean',
						'parent'  => 'watermark_image',
						'label'   => __( 'Enable watermark for front-end image uploads (AJAX).', 'image-watermark' ),
					],
					'watermark_on' => [
						'title'    => __( 'Image Sizes', 'image-watermark' ),
						'section'  => 'image_watermark_general',
						'type'     => 'checkbox',
						'options'  => $image_sizes_options,
						'name' => 'image_watermark_options[watermark_on]',
						'value' => $watermark_on_value,
						'skip_saving' => true,
						'description' => wp_kses_post( __( 'Select the image sizes watermark will be applied to.', 'image-watermark' ) ),
					],
					'skip_small_images' => [
						'title'   => __( 'Skip Small Images', 'image-watermark' ),
						'section' => 'image_watermark_general',
						'type'    => 'boolean',
						'parent'  => 'watermark_image',
						'label'   => __( 'Skip watermarking for small image sizes.', 'image-watermark' ),
					],
					'small_image_threshold' => [
						'title'       => '',
						'section'     => 'image_watermark_general',
						'type'        => 'custom',
						'callback'    => [ $this, 'render_small_image_threshold' ],
						'description' => __( 'Skip watermarking when the original uploaded image is smaller than the minimum width or height in pixels.', 'image-watermark' ),
						'callback_args' => [
							'width' => [
								'name'  => 'image_watermark_options[watermark_image][min_image_width]',
								'value' => $this->plugin->options['watermark_image']['min_image_width'],
							],
							'height' => [
								'name'  => 'image_watermark_options[watermark_image][min_image_height]',
								'value' => $this->plugin->options['watermark_image']['min_image_height'],
							],
						],
						'condition'   => [
							'field'    => 'skip_small_images',
							'operator' => 'is',
							'value'    => 'true',
						],
						'animation'   => 'slide',
					],
					'watermark_apply_on' => [
						'title'    => __( 'Apply Watermark To', 'image-watermark' ),
						'section'  => 'image_watermark_general',
						'type'     => 'radio',
						'options'  => [
							'everywhere' => __( 'All uploads', 'image-watermark' ),
							'post_types' => __( 'Uploads attached to selected post types', 'image-watermark' ),
						],
						'name' => 'image_watermark_options[watermark_apply_on]',
						'value' => $watermark_apply_on_value,
						'description' => __( 'Post-type filtering is evaluated when an image is uploaded. Images uploaded directly to the Media Library are unattached and will be skipped. Assigning an existing image to a post later does not apply a watermark.', 'image-watermark' ),
					],
					'watermark_cpt_on' => [
						'title'    => __( 'Post Types', 'image-watermark' ),
						'section'  => 'image_watermark_general',
						'type'     => 'checkbox',
						'options'  => $post_type_options,
						'name' => 'image_watermark_options[watermark_cpt_on]',
						'value' => $watermark_cpt_value,
						'skip_saving' => true,
						'description' => __( 'Select the parent post types whose attached uploads should be watermarked.', 'image-watermark' ),
						'condition'   => [
							'field'    => 'watermark_apply_on',
							'operator' => 'is',
							'value'    => 'post_types',
						],
						'animation'   => 'slide',
					],

					// Position Section
					'alignment' => [
						'title'    => __( 'Watermark Alignment', 'image-watermark' ),
						'section'  => 'image_watermark_position',
						'type'     => 'custom',
						'callback' => [ $this, 'render_alignment' ],
						'description' => __( 'Select the watermark alignment.', 'image-watermark' ),
						'name' => 'image_watermark_options[watermark_image][position]',
					],
					'rotation' => [
						'title'        => __( 'Watermark Rotation', 'image-watermark' ),
						'section'      => 'image_watermark_position',
						'type'         => 'range',
						'parent'       => 'watermark_image',
						'name'         => 'image_watermark_options[watermark_image][rotation]',
						'value'        => $this->plugin->options['watermark_image']['rotation'],
						'min'          => 0,
						'max'          => 360,
						'step'         => 1,
						'before_field' => '<div class="iw-range-field">',
						'after_field'  => '</div>',
						'description'  => __( 'Rotate the watermark clockwise from 0 to 360 degrees. 0 and 360 produce no rotation.', 'image-watermark' ),
					],
					'offset' => [
						'title'    => __( 'Watermark Offset', 'image-watermark' ),
						'section'  => 'image_watermark_position',
						'type'     => 'custom',
						'callback' => [ $this, 'render_offset' ],
						'description' => __( 'Enter watermark offset value.', 'image-watermark' ),
						'callback_args' => [
							'x' => [
								'name' => 'image_watermark_options[watermark_image][offset_width]',
								'value' => $this->plugin->options['watermark_image']['offset_width'],
							],
							'y' => [
								'name' => 'image_watermark_options[watermark_image][offset_height]',
								'value' => $this->plugin->options['watermark_image']['offset_height'],
							],
						],
					],
					'offset_unit' => [
						'title'   => __( 'Offset Unit', 'image-watermark' ),
						'description' => __( 'Select the watermark offset unit.', 'image-watermark' ),
						'section' => 'image_watermark_position',
						'type'    => 'radio',
						'parent'  => 'watermark_image',
						'options' => [
							'pixels'      => __( 'pixels', 'image-watermark' ),
							'percentages' => __( 'percentages', 'image-watermark' ),
						],
					],

					// Image Section
					'type' => [
						'title'   => __( 'Watermark Type', 'image-watermark' ),
						'description' => __( 'Select the type of watermark to apply.', 'image-watermark' ),
						'section' => 'image_watermark_image',
						'type'    => 'radio',
						'parent'  => 'watermark_image',
						'options' => [
							'image' => __( 'Image', 'image-watermark' ),
							'text'  => __( 'Text', 'image-watermark' ),
						],
					],
					'preview' => [
						'title'    => __( 'Watermark Preview', 'image-watermark' ),
						'section'  => 'image_watermark_image',
						'type'     => 'custom',
						'callback' => [ $this, 'render_preview' ],
						'description' => __( 'Preview uses a 600 x 400 px stage and mirrors your size, rotation, alignment and offset settings.', 'image-watermark' ),
					],
					'image_ui' => [
						'title'    => __( 'Watermark Image', 'image-watermark' ),
						'section'  => 'image_watermark_image',
						'type'     => 'custom',
						'callback' => [ $this, 'render_watermark_image' ],
						'name' => 'image_watermark_options[watermark_image][url]',
						'description' => __( 'Save changes after selecting or removing the image.', 'image-watermark' ),
						'condition'   => [
							'field'    => 'type',
							'operator' => 'is',
							'value'    => 'image',
						],
						'animation'   => 'slide',
					],
					'text_string' => [
						'title'    => __( 'Watermark Text', 'image-watermark' ),
						'section'  => 'image_watermark_image',
						'type'     => 'text',
						'parent'   => 'watermark_image',
						'subclass' => 'regular-text',
						'description' => __( 'Enter the text to use as watermark.', 'image-watermark' ),
						'condition'   => [
							'field'    => 'type',
							'operator' => 'is',
							'value'    => 'text',
						],
						'animation'   => 'slide',
					],
					'text_font' => [
						'title'    => __( 'Font', 'image-watermark' ),
						'section'  => 'image_watermark_image',
						'type'     => 'select',
						'parent'   => 'watermark_image',
						'options'  => $this->plugin->get_allowed_fonts(),
						'description' => __( 'Select the font for the watermark text.', 'image-watermark' ),
						'condition'   => [
							'field'    => 'type',
							'operator' => 'is',
							'value'    => 'text',
						],
						'animation'   => 'slide',
					],
					'text_color' => [
						'title'       => __( 'Text Color', 'image-watermark' ),
						'section'     => 'image_watermark_image',
						'type'        => 'color',
						'parent'      => 'watermark_image',
						'subclass'    => 'iw-color-picker',
						'description' => __( 'Select the text color.', 'image-watermark' ),
						'condition'   => [
							'field'    => 'type',
							'operator' => 'is',
							'value'    => 'text',
						],
						'animation'   => 'slide',
					],
					'text_size' => [
						'title'       => __( 'Text Size', 'image-watermark' ),
						'section'     => 'image_watermark_image',
						'type'        => 'number',
						'parent'      => 'watermark_image',
						'min'         => 0,
						'max'         => 1000,
						'description' => __( 'Enter the text size in pixels.', 'image-watermark' ),
						'condition'   => [
							'field'    => 'type',
							'operator' => 'is',
							'value'    => 'text',
						],
						'animation'   => 'slide',
					],
					'size' => [
						'title'    => __( 'Watermark Size', 'image-watermark' ),
						'section'  => 'image_watermark_image',
						'type'     => 'radio',
						'parent'   => 'watermark_image',
						'name'     => 'image_watermark_options[watermark_image][watermark_size_type]',
						'value'    => $this->plugin->options['watermark_image']['watermark_size_type'],
						'options'  => [
							'0' => __( 'Original', 'image-watermark' ),
							'1' => __( 'Custom', 'image-watermark' ),
							'2' => __( 'Scaled', 'image-watermark' ),
						],
						'description' => __( 'Select how the watermark size is calculated.', 'image-watermark' ),
					],
					'size_custom' => [
						'title'    => '',
						'section'  => 'image_watermark_image',
						'type'     => 'custom',
						'callback' => [ $this, 'render_watermark_size_custom' ],
						'description' => __( 'These dimensions are used when the "Custom" method is selected above.', 'image-watermark' ),
						'callback_args' => [
							'width' => [
								'name' => 'image_watermark_options[watermark_image][absolute_width]',
								'value' => $this->plugin->options['watermark_image']['absolute_width'],
							],
							'height' => [
								'name' => 'image_watermark_options[watermark_image][absolute_height]',
								'value' => $this->plugin->options['watermark_image']['absolute_height'],
							],
						],
						'condition'   => [
							'field'    => 'size',
							'operator' => 'is',
							'value'    => '1',
						],
						'animation'   => 'slide',
					],
					'size_scaled' => [
						'title'    => '',
						'section'  => 'image_watermark_image',
						'type'     => 'range',
						'parent'   => 'watermark_image',
						'name'     => 'image_watermark_options[watermark_image][width]',
						'value'    => $this->plugin->options['watermark_image']['width'],
						'min'      => 0,
						'max'      => 100,
						'step'     => 1,
						'before_field' => '<div class="iw-range-field">',
						'after_field'  => '</div>',
						'description'  => __( 'Enter a number from 0 to 100. 100 makes the watermark image as wide as the image it is applied to.', 'image-watermark' ),
						'condition'   => [
							'field'    => 'size',
							'operator' => 'is',
							'value'    => '2',
						],
						'animation'   => 'slide',
					],
					'opacity' => [
						'title'    => __( 'Watermark Opacity', 'image-watermark' ),
						'section'  => 'image_watermark_image',
						'type'     => 'range',
						'parent'   => 'watermark_image',
						'name'     => 'image_watermark_options[watermark_image][transparent]',
						'value'    => $this->plugin->options['watermark_image']['transparent'],
						'min'      => 0,
						'max'      => 100,
						'step'     => 1,
						'before_field' => '<div class="iw-range-field">',
						'after_field'  => '</div>',
						'description'  => __( 'Adjust watermark opacity (0-100).', 'image-watermark' ),
					],
					'quality' => [
						'title'       => __( 'Image Quality', 'image-watermark' ),
						'section'     => 'image_watermark_image',
						'type'        => 'number',
						'parent'      => 'watermark_image',
						'min'         => 0,
						'max'         => 100,
						'description' => __( 'Set output image quality (0-100).', 'image-watermark' ),
						'condition'   => [
							'field'    => 'type',
							'operator' => 'is',
							'value'    => 'image',
						],
						'animation'   => 'slide',
					],
					'jpeg_format' => [
						'title'   => __( 'Image Format', 'image-watermark' ),
						'section' => 'image_watermark_image',
						'type'    => 'radio',
						'parent'  => 'watermark_image',
						'options' => [
							'baseline'    => __( 'Baseline', 'image-watermark' ),
							'progressive' => __( 'Progressive', 'image-watermark' ),
						],
						'description' => __( 'Select the image format.', 'image-watermark' ),
						'condition'   => [
							'field'    => 'type',
							'operator' => 'is',
							'value'    => 'image',
						],
						'animation'   => 'slide',
					],
				],
			],
			'protection' => [
				'option_name' => 'image_watermark_options',
				'validate'    => [ $this, 'validate_settings' ],
				'label'       => __( 'Protection', 'image-watermark' ),
				'heading'     => $page_heading,
				'sections'    => [
					'image_watermark_protection' => [
						'title' => __( 'Image Protection', 'image-watermark' ),
					],
				],
				'fields'      => [
					'rightclick' => [
						'title'   => __( 'Right Click', 'image-watermark' ),
						'section' => 'image_watermark_protection',
						'type'    => 'boolean',
						'parent'  => 'image_protection',
						'label'   => __( 'Disable right mouse click on images', 'image-watermark' ),
					],
					'draganddrop' => [
						'title'   => __( 'Drag and Drop', 'image-watermark' ),
						'section' => 'image_watermark_protection',
						'type'    => 'boolean',
						'parent'  => 'image_protection',
						'label'   => __( 'Prevent drag and drop', 'image-watermark' ),
					],
					'devtools' => [
						'title'   => __( 'Developer Tools', 'image-watermark' ),
						'section' => 'image_watermark_protection',
						'type'    => 'boolean',
						'parent'  => 'image_protection',
						'label'   => __( 'Disable developer tools', 'image-watermark' ),
					],
					'enable_toast' => [
						'title'   => __( 'Protection Notification', 'image-watermark' ),
						'section' => 'image_watermark_protection',
						'type'    => 'boolean',
						'parent'  => 'image_protection',
						'label'   => __( 'Show notification when right-click is disabled', 'image-watermark' ),
					],
					'toast_message' => [
						'title'    => '',
						'section'  => 'image_watermark_protection',
						'type'     => 'text',
						'parent'   => 'image_protection',
						'subclass' => 'regular-text',
						'description' => __( 'Enter image protection notification message.', 'image-watermark' ),
					],
					'forlogged' => [
						'title'   => __( 'Logged-in Users', 'image-watermark' ),
						'section' => 'image_watermark_protection',
						'type'    => 'boolean',
						'parent'  => 'image_protection',
						'label'   => __( 'Enable protection for logged-in users', 'image-watermark' ),
					],
				],
			],
			'status' => [
				'option_name' => 'image_watermark_options',
				'validate'    => [ $this, 'validate_settings' ],
				'label'       => __( 'Status', 'image-watermark' ),
				'heading'     => $page_heading,
				'sections'    => [
					'image_watermark_status' => [
						'title' => __( 'System Status', 'image-watermark' ),
					],
					'image_watermark_backup' => [
						'title' => __( 'Image Backup', 'image-watermark' ),
					],
					'image_watermark_other' => [
						'title' => __( 'Other', 'image-watermark' ),
					],
				],
				'fields'      => [
					'iw_status' => [
						'title'    => __( 'Current Status', 'image-watermark' ),
						'section'  => 'image_watermark_status',
						'type'     => 'custom',
						'callback' => [ $this, 'render_status' ],
					],
					'backup_image' => [
						'title'   => __( 'Backup Images', 'image-watermark' ),
						'section' => 'image_watermark_backup',
						'type'    => 'boolean',
						'parent'  => 'backup',
						'label'   => __( 'Backup original images', 'image-watermark' ),
						'description' => __( 'If enabled, original images are backed up before watermarking, allowing watermarks to be removed and originals restored.', 'image-watermark' ),
					],
					'preserve_timestamps' => [
						'title'	  => __( 'Preserve File Dates', 'image-watermark' ),
						'section' => 'image_watermark_backup',
						'type'	  => 'boolean',
						'parent'	  => 'backup',
						'label'	  => __( 'Preserve original file dates when copying or restoring', 'image-watermark' ),
						'description' => __( 'If enabled, backup and restore operations keep the original file timestamps (when supported by the server).', 'image-watermark' ),
					],
					'backup_folder' => [
						'title'    => __( 'Backup Location', 'image-watermark' ),
						'section'  => 'image_watermark_backup',
						'type'     => 'custom',
						'callback' => [ $this, 'render_backup_folder' ],
						'description' => __( 'Location where original images are stored when backups are enabled.', 'image-watermark' ),
					],
					'deactivation_delete' => [
						'title'   => __( 'Deactivation', 'image-watermark' ),
						'section' => 'image_watermark_other',
						'type'    => 'boolean',
						'parent'  => 'watermark_image',
						'label'   => __( 'Delete all database settings on plugin deactivation', 'image-watermark' ),
					],
				],
			],
		];

		return $settings;
	}

	/**
	 * Validate settings.
	 *
	 * @param array $input
	 * @return array
	 */
	public function validate_settings( $input ) {
		$existing = $this->get_existing_options();
		$review_update = $this->is_review_notice_update( $input, $existing );

		if ( ! current_user_can( 'manage_options' ) && ! ( $review_update && current_user_can( 'install_plugins' ) ) ) {
			return $existing;
		}
		if ( $review_update && ! current_user_can( 'manage_options' ) ) {
			return $this->apply_review_notice_update( $input, $existing );
		}

		if ( isset( $_POST['reset_image_watermark_options'] ) ) {
			$defaults = $this->plugin->defaults['options'];
			$defaults['watermark_image']['review_notice'] = false;
			$defaults['watermark_image']['review_delay_date'] = 0;
			add_settings_error( 'image_watermark_options', 'settings_restored', __( 'Settings restored to defaults.', 'image-watermark' ), 'updated' );
			return $defaults;
		}

		if ( ! is_array( $input ) ) {
			add_settings_error( 'image_watermark_options', 'invalid_input', __( 'Invalid settings input. Existing settings were preserved.', 'image-watermark' ), 'error' );
			return $existing;
		}
		$is_settings_submission = isset( $_POST['option_page'] ) && $_POST['option_page'] === 'image_watermark_options';
		if ( ! $is_settings_submission && isset( $input['watermark_image'] ) && is_array( $input['watermark_image'] ) ) {
			// Review-notice state is changed by the authorized system lifecycle with
			// update_option(). Preserve that narrow programmatic path without allowing
			// a malformed Settings API submission to assign arbitrary raw input.
			if ( array_key_exists( 'review_notice', $input['watermark_image'] ) ) {
				$value = $input['watermark_image']['review_notice'];
				if ( $value === true || $value === false || $value === 1 || $value === 0 || $value === '1' || $value === '0' || $value === 'true' || $value === 'false' ) {
					$existing['watermark_image']['review_notice'] = ( $value === true || $value === 1 || $value === '1' || $value === 'true' );
				}
			}
			if ( array_key_exists( 'review_delay_date', $input['watermark_image'] ) && is_scalar( $input['watermark_image']['review_delay_date'] ) && filter_var( $input['watermark_image']['review_delay_date'], FILTER_VALIDATE_INT ) !== false && (int) $input['watermark_image']['review_delay_date'] >= 0 ) {
				$existing['watermark_image']['review_delay_date'] = (int) $input['watermark_image']['review_delay_date'];
			}
		}

		$current_tab = isset( $_POST['iw_current_tab'] ) && is_scalar( $_POST['iw_current_tab'] ) ? sanitize_key( $_POST['iw_current_tab'] ) : '';
		if ( $current_tab === '' && isset( $_GET['tab'] ) && is_scalar( $_GET['tab'] ) ) {
			$current_tab = sanitize_key( $_GET['tab'] );
		}
		if ( $current_tab === '' ) {
			$current_tab = 'watermark';
		}

		$config = $this->get_settings_data();
		if ( ! isset( $config[$current_tab] ) ) {
			add_settings_error( 'image_watermark_options', 'invalid_tab', __( 'Invalid settings tab. Please try again.', 'image-watermark' ), 'error' );
			return $existing;
		}

		// The Settings API has already unslashed $input. Do not read the raw option
		// payload from $_POST or unslash it again; this method is reusable by P00-12.
		$output = $existing;
		$this->validate_settings_fields( $config[$current_tab]['fields'], $input, $output );

		if ( $current_tab === 'watermark' ) {
			$this->validate_watermark_custom_fields( $input, $output );
		}

		add_settings_error( 'image_watermark_options', 'settings_saved', __( 'Settings saved.', 'image-watermark' ), 'updated' );
		return $output;
	}

	/**
	 * Validate a request-local text-preview overlay without saving it.
	 *
	 * @param array $input Raw preview fields after request unslashing.
	 * @param array $existing Current complete settings snapshot.
	 * @return array
	 */
	public function validate_text_preview_options( $input, $existing ) {
		if ( ! is_array( $input ) || ! is_array( $existing ) || ! isset( $existing['watermark_image'] ) || ! is_array( $existing['watermark_image'] ) ) {
			return [ 'success' => false, 'error' => __( 'Invalid text preview options.', 'image-watermark' ) ];
		}

		$config = $this->get_settings_data();
		$fields = $config['watermark']['fields'];
		$output = $existing;
		$preview_fields = [
			'text_string' => 'text_string',
			'text_font'   => 'text_font',
			'text_color'  => 'text_color',
			'text_size'   => 'text_size',
			'transparent' => 'opacity',
			'rotation'    => 'rotation',
		];
		foreach ( $preview_fields as $field_key => $settings_field_key ) {
			if ( ! array_key_exists( $field_key, $input ) ) {
				continue;
			}
			$value = $input[$field_key];
			if ( ! $this->validate_settings_field_value( $field_key, $fields[$settings_field_key], $value ) ) {
				return [ 'success' => false, 'error' => __( 'Invalid text preview options.', 'image-watermark' ) ];
			}
			$output['watermark_image'][$field_key] = $value;
		}

		if ( array_key_exists( 'position', $input ) ) {
			if ( ! $this->is_valid_watermark_position( $input['position'] ) ) {
				return [ 'success' => false, 'error' => __( 'Invalid text preview options.', 'image-watermark' ) ];
			}
			$output['watermark_image']['position'] = sanitize_key( $input['position'] );
		}

		$output['watermark_image']['type'] = 'text';
		$text = isset( $output['watermark_image']['text_string'] ) ? trim( $output['watermark_image']['text_string'] ) : '';
		$font = isset( $output['watermark_image']['text_font'] ) ? $output['watermark_image']['text_font'] : '';
		$font_path = $this->plugin->get_font_path( $font );
		if ( $text === '' || ! $font_path || ! is_file( $font_path ) ) {
			return [ 'success' => false, 'error' => __( 'Invalid text preview options.', 'image-watermark' ) ];
		}

		return [ 'success' => true, 'options' => $output ];
	}

	/**
	 * Apply configured fields to a snapshot, retaining existing invalid values.
	 *
	 * @param array $fields Settings field definitions.
	 * @param array $input Normalized settings input.
	 * @param array $output Validated output, passed by reference.
	 * @return void
	 */
	private function validate_settings_fields( $fields, $input, &$output ) {
		foreach ( $fields as $field_key => $field ) {
			if ( ! empty( $field['skip_saving'] ) || $field['type'] === 'custom' ) {
				continue;
			}
			$parent = isset( $field['parent'] ) ? $field['parent'] : null;
			$has_parent = $parent && isset( $input[$parent] ) && is_array( $input[$parent] );
			$has_value = $parent ? ( $has_parent && array_key_exists( $field_key, $input[$parent] ) ) : array_key_exists( $field_key, $input );
			$value = $has_value ? ( $parent ? $input[$parent][$field_key] : $input[$field_key] ) : null;
			if ( ! $has_value ) {
				if ( $has_parent && $field['type'] === 'boolean' ) {
					$output[$parent][$field_key] = false;
				}
				continue;
			}
			if ( $this->validate_settings_field_value( $field_key, $field, $value ) ) {
				if ( $parent ) {
					$output[$parent][$field_key] = $value;
				} else {
					$output[$field_key] = $value;
				}
			}
		}
	}

	/**
	 * Normalize a configured field and report whether it is valid.
	 *
	 * @param string $field_key Field identifier.
	 * @param array  $field Field definition.
	 * @param mixed  $value Value, passed by reference.
	 * @return bool
	 */
	private function validate_settings_field_value( $field_key, $field, &$value ) {
		$valid = ! is_array( $value ) && ! is_object( $value );
		if ( $valid && $field['type'] === 'boolean' ) {
			$value = ( $value === true || $value === 1 || $value === '1' || $value === 'true' );
		} elseif ( $valid && ( $field['type'] === 'number' || $field['type'] === 'range' ) ) {
			// The shared watermark rotation leaf is integer-only: booleans and
			// fractions must not become an angle.
			$integer_only = $field_key === 'rotation' && isset( $field['parent'] ) && $field['parent'] === 'watermark_image';
			$valid = $integer_only
				? ( ! is_bool( $value ) && filter_var( $value, FILTER_VALIDATE_INT ) !== false )
				: is_numeric( $value );
			if ( $valid ) {
				$value = (int) $value;
				if ( isset( $field['min'] ) ) {
					$value = max( (int) $field['min'], $value );
				}
				if ( isset( $field['max'] ) ) {
					$value = min( (int) $field['max'], $value );
				}
			}
		} elseif ( $valid && $field['type'] === 'color' ) {
			$value = sanitize_text_field( $value );
			$valid = (bool) preg_match( '/^#[a-f0-9]{6}$/i', $value );
		} elseif ( $valid && ( $field['type'] === 'select' || $field['type'] === 'radio' ) ) {
			$value = sanitize_text_field( $value );
			$valid = ( $field_key === 'extension' && $value === '' ) || ( isset( $field['options'] ) && array_key_exists( $value, $field['options'] ) );
		} elseif ( $valid ) {
			$value = sanitize_text_field( $value );
		}
		return $valid;
	}

	/**
	 * Determine whether a programmatic update changes only review-notice fields.
	 *
	 * @param mixed $input Candidate option value.
	 * @param array $existing Saved option value.
	 * @return bool
	 */
	private function is_review_notice_update( $input, $existing ) {
		if ( ! is_array( $input ) || ! isset( $input['watermark_image'] ) || ! is_array( $input['watermark_image'] ) ) {
			return false;
		}
		if ( ! array_key_exists( 'review_notice', $input['watermark_image'] ) && ! array_key_exists( 'review_delay_date', $input['watermark_image'] ) ) {
			return false;
		}
		$candidate = $input;
		$saved = $existing;
		unset( $candidate['watermark_image']['review_notice'], $candidate['watermark_image']['review_delay_date'] );
		unset( $saved['watermark_image']['review_notice'], $saved['watermark_image']['review_delay_date'] );
		return $candidate === $saved;
	}

	/**
	 * Apply the narrow install_plugins-authorized review-notice update path.
	 *
	 * @param array $input Candidate option value.
	 * @param array $existing Saved option value.
	 * @return array
	 */
	private function apply_review_notice_update( $input, $existing ) {
		if ( array_key_exists( 'review_notice', $input['watermark_image'] ) ) {
			$value = $input['watermark_image']['review_notice'];
			if ( $value === true || $value === false || $value === 1 || $value === 0 || $value === '1' || $value === '0' || $value === 'true' || $value === 'false' ) {
				$existing['watermark_image']['review_notice'] = ( $value === true || $value === 1 || $value === '1' || $value === 'true' );
			}
		}
		if ( array_key_exists( 'review_delay_date', $input['watermark_image'] ) && is_scalar( $input['watermark_image']['review_delay_date'] ) && filter_var( $input['watermark_image']['review_delay_date'], FILTER_VALIDATE_INT ) !== false && (int) $input['watermark_image']['review_delay_date'] >= 0 ) {
			$existing['watermark_image']['review_delay_date'] = (int) $input['watermark_image']['review_delay_date'];
		}
		return $existing;
	}

	/**
	 * Return the saved option shape merged with documented defaults.
	 *
	 * @return array
	 */
	private function get_existing_options() {
		$stored = get_option( 'image_watermark_options', [] );
		$defaults = $this->plugin->defaults['options'];
		if ( ! is_array( $stored ) ) {
			$stored = [];
		}
		$output = array_merge( $defaults, $stored );
		foreach ( [ 'watermark_image', 'image_protection', 'backup' ] as $group ) {
			$output[$group] = array_merge( $defaults[$group], isset( $stored[$group] ) && is_array( $stored[$group] ) ? $stored[$group] : [] );
		}
		return $output;
	}

	/**
	 * Validate fields rendered outside the generic Settings API field loop.
	 * Offsets deliberately accept every signed PHP integer so placements outside an
	 * image remain supported; malformed and non-integer offsets keep the saved value.
	 *
	 * @param array $input Normalized Settings API input.
	 * @param array $output Validated output, passed by reference.
	 * @return void
	 */
	private function validate_watermark_custom_fields( $input, &$output ) {
		$image = isset( $input['watermark_image'] ) && is_array( $input['watermark_image'] ) ? $input['watermark_image'] : [];

		if ( array_key_exists( 'watermark_on', $input ) ) {
			$selected = $input['watermark_on'];
			if ( $selected === 'empty' ) {
				$output['watermark_on'] = [];
			} elseif ( is_array( $selected ) ) {
				$allowed = get_intermediate_image_sizes();
				$allowed[] = 'full';
				$output['watermark_on'] = [];
				foreach ( $selected as $size ) {
					if ( is_scalar( $size ) && in_array( sanitize_key( $size ), $allowed, true ) ) {
						$output['watermark_on'][sanitize_key( $size )] = 1;
					}
				}
			}
		}

		if ( array_key_exists( 'watermark_apply_on', $input ) && is_scalar( $input['watermark_apply_on'] ) ) {
			$apply_on = sanitize_key( $input['watermark_apply_on'] );
			if ( in_array( $apply_on, [ 'everywhere', 'post_types' ], true ) ) {
				$output['watermark_apply_on'] = $apply_on;
			}
		}

		if ( $output['watermark_apply_on'] === 'post_types' && array_key_exists( 'watermark_cpt_on', $input ) ) {
			$selected = $input['watermark_cpt_on'];
			if ( $selected === 'empty' ) {
				$output['watermark_cpt_on'] = [];
			} elseif ( is_array( $selected ) ) {
				$allowed = array_merge( [ 'post', 'page' ], get_post_types( [ '_builtin' => false ], 'names' ) );
				$output['watermark_cpt_on'] = [];
				foreach ( $selected as $post_type ) {
					if ( is_scalar( $post_type ) && in_array( sanitize_key( $post_type ), $allowed, true ) ) {
						$output['watermark_cpt_on'][sanitize_key( $post_type )] = 1;
					}
				}
			}
		}

		if ( isset( $image['position'] ) && $this->is_valid_watermark_position( $image['position'] ) ) {
			$output['watermark_image']['position'] = sanitize_key( $image['position'] );
		}
		foreach ( [ 'offset_width', 'offset_height' ] as $offset ) {
			if ( array_key_exists( $offset, $image ) && is_scalar( $image[$offset] ) && filter_var( $image[$offset], FILTER_VALIDATE_INT ) !== false ) {
				$output['watermark_image'][$offset] = (int) $image[$offset];
			}
		}
		if ( array_key_exists( 'url', $image ) && is_scalar( $image['url'] ) && filter_var( $image['url'], FILTER_VALIDATE_INT ) !== false && (int) $image['url'] >= 0 ) {
			$output['watermark_image']['url'] = (int) $image['url'];
		}
		$size_type = null;
		if ( isset( $image['watermark_size_type'] ) && is_scalar( $image['watermark_size_type'] ) && filter_var( $image['watermark_size_type'], FILTER_VALIDATE_INT ) !== false ) {
			$candidate_size_type = (int) $image['watermark_size_type'];
			if ( in_array( $candidate_size_type, [ 0, 1, 2 ], true ) ) {
				$size_type = $candidate_size_type;
			}
		}
		$valid_dimensions = true;
		$dimensions = [];
		foreach ( [ 'absolute_width', 'absolute_height' ] as $dimension ) {
			if ( array_key_exists( $dimension, $image ) ) {
				if ( ! is_scalar( $image[$dimension] ) || filter_var( $image[$dimension], FILTER_VALIDATE_INT ) === false || (int) $image[$dimension] < 0 ) {
					$valid_dimensions = false;
				} else {
					$dimensions[$dimension] = (int) $image[$dimension];
				}
			}
		}
		$effective_size_type = $size_type !== null ? $size_type : $output['watermark_image']['watermark_size_type'];
		if ( $effective_size_type === 1 && ( count( $dimensions ) !== 2 || $dimensions['absolute_width'] <= 0 || $dimensions['absolute_height'] <= 0 ) ) {
			$valid_dimensions = false;
		}
		if ( ! $valid_dimensions ) {
			add_settings_error( 'image_watermark_options', 'invalid_custom_dimensions', __( 'Custom watermark dimensions must both be positive whole numbers.', 'image-watermark' ), 'error' );
			return;
		}
		if ( $size_type !== null ) {
			$output['watermark_image']['watermark_size_type'] = $size_type;
		}
		foreach ( $dimensions as $dimension => $value ) {
			$output['watermark_image'][$dimension] = $value;
		}
	}

	/**
	 * Check a watermark alignment against the shared settings allowlist.
	 *
	 * @param mixed $position Candidate alignment.
	 * @return bool
	 */
	private function is_valid_watermark_position( $position ) {
		$positions = [ 'top_left', 'top_center', 'top_right', 'middle_left', 'middle_center', 'middle_right', 'bottom_left', 'bottom_center', 'bottom_right' ];
		return is_scalar( $position ) && in_array( sanitize_key( $position ), $positions, true );
	}

	/**
	 * Render Alignment field.
	 */
	public function render_alignment( $args ) {
		$base_id = ! empty( $args['html_id'] ) ? $args['html_id'] : 'iw-watermark-alignment';
		$options = $this->plugin->options;
		$position = $options['watermark_image']['position'];
		$positions = [
			'top_left', 'top_center', 'top_right',
			'middle_left', 'middle_center', 'middle_right',
			'bottom_left', 'bottom_center', 'bottom_right',
		];
		?>
		<div class="iw-alignment-grid" id="<?php echo esc_attr( $base_id ); ?>" role="radiogroup" aria-label="<?php esc_attr_e( 'Watermark Alignment', 'image-watermark' ); ?>">
			<?php foreach ( $positions as $pos ) : ?>
				<div class="iw-alignment-cell">
					<input type="radio" id="iw-alignment-<?php echo esc_attr( $pos ); ?>" name="<?php echo esc_attr( $args['name'] ); ?>" value="<?php echo esc_attr( $pos ); ?>" <?php checked( $position, $pos ); ?> />
					<label for="iw-alignment-<?php echo esc_attr( $pos ); ?>" title="<?php echo esc_attr( ucwords( str_replace( '_', ' ', $pos ) ) ); ?>">
						<span class="screen-reader-text"><?php echo esc_html( str_replace( '_', ' ', $pos ) ); ?></span>
					</label>
				</div>
			<?php endforeach; ?>
		</div>
		<?php
	}

	/**
	 * Render Offset field.
	 */
	public function render_offset( $args ) {
		$base_id = ! empty( $args['html_id'] ) ? $args['html_id'] : 'iw-watermark-offset';
		$offset_x_id = $base_id . '-x';
		$offset_y_id = $base_id . '-y';
		?>
		<div class="iw-field-group iw-offset-group">
			<label for="<?php echo esc_attr( $offset_x_id ); ?>"><?php esc_html_e( 'x:', 'image-watermark' ); ?> <input type="number" id="<?php echo esc_attr( $offset_x_id ); ?>" name="<?php echo esc_attr( $args['callback_args']['x']['name'] ); ?>" value="<?php echo esc_attr( $args['callback_args']['x']['value'] ); ?>" min="0" max="100" /></label>

			<label for="<?php echo esc_attr( $offset_y_id ); ?>"><?php esc_html_e( 'y:', 'image-watermark' ); ?> <input type="number" id="<?php echo esc_attr( $offset_y_id ); ?>" name="<?php echo esc_attr( $args['callback_args']['y']['name'] ); ?>" value="<?php echo esc_attr( $args['callback_args']['y']['value'] ); ?>" min="0" max="100" /></label>
		</div>
		<?php
	}

	/**
	 * Render small image threshold field.
	 */
	public function render_small_image_threshold( $args ) {
		$base_id = ! empty( $args['html_id'] ) ? $args['html_id'] : 'iw-small-image-threshold';
		$width_id = $base_id . '-width';
		$height_id = $base_id . '-height';
		?>
		<div class="iw-field-group iw-offset-group">
			<label for="<?php echo esc_attr( $width_id ); ?>"><?php esc_html_e( 'w:', 'image-watermark' ); ?> <input type="number" id="<?php echo esc_attr( $width_id ); ?>" name="<?php echo esc_attr( $args['callback_args']['width']['name'] ); ?>" value="<?php echo esc_attr( $args['callback_args']['width']['value'] ); ?>" min="0" /></label>

			<label for="<?php echo esc_attr( $height_id ); ?>"><?php esc_html_e( 'h:', 'image-watermark' ); ?> <input type="number" id="<?php echo esc_attr( $height_id ); ?>" name="<?php echo esc_attr( $args['callback_args']['height']['name'] ); ?>" value="<?php echo esc_attr( $args['callback_args']['height']['value'] ); ?>" min="0" /></label>
		</div>
		<?php
	}

	/**
	 * Render Status field using the shared diagnostics service.
	 */
	public function render_status( $args ) {
		$diagnostics = $this->plugin->get_diagnostics();

		if ( ! $diagnostics ) {
			echo '<p>' . esc_html__( 'Diagnostics service unavailable.', 'image-watermark' ) . '</p>';
			return;
		}

		$report    = $diagnostics->system_report();
		$readiness = $diagnostics->readiness( $report );
		$labels    = $diagnostics->section_labels();
		$plain_txt = $diagnostics->plain_text_report( $report, $readiness );

		$banner_map = [
			'ready'       => [
				'label' => __( 'Ready', 'image-watermark' ),
				'class' => 'notice-success',
				'desc'  => __( 'Watermark is configured and ready to apply.', 'image-watermark' ),
			],
			'needs_setup' => [
				'label' => __( 'Needs Setup', 'image-watermark' ),
				'class' => 'notice-warning',
				'desc'  => __( 'Some settings need attention before watermarking will work.', 'image-watermark' ),
			],
			'degraded'    => [
				'label' => __( 'Degraded', 'image-watermark' ),
				'class' => 'notice-error',
				'desc'  => __( 'Critical issues are preventing watermarking from working correctly.', 'image-watermark' ),
			],
		];

		$banner = isset( $banner_map[ $readiness ] ) ? $banner_map[ $readiness ] : $banner_map['degraded'];
		?>
		<div class="iw-readiness-banner notice inline <?php echo esc_attr( $banner['class'] ); ?>">
			<p>
				<strong><?php echo esc_html( $banner['label'] ); ?>:</strong>
				<?php echo esc_html( $banner['desc'] ); ?>
			</p>
		</div>

		<div class="iw-status-sections">
		<?php foreach ( $report as $section_key => $items ) :
			if ( empty( $items ) ) {
				continue;
			}
			$section_label = isset( $labels[ $section_key ] ) ? $labels[ $section_key ] : $section_key;
		?>
			<div class="iw-status-section">
				<h3 class="iw-status-section-heading"><?php echo esc_html( $section_label ); ?></h3>
				<ul class="iw-status-list">
				<?php foreach ( $items as $item ) : ?>
					<li class="iw-status-item">
						<span class="iw-status-dot <?php echo esc_attr( $item['status'] ); ?>"></span>
						<span class="iw-status-text">
							<strong><?php echo esc_html( $item['label'] ); ?>:</strong>
							<?php echo wp_kses_post( $item['message'] ); ?>
							<?php if ( ! empty( $item['hint'] ) ) : ?>
								<p class="description"><?php echo wp_kses_post( $item['hint'] ); ?></p>
							<?php endif; ?>
						</span>
					</li>
				<?php endforeach; ?>
				</ul>
			</div>
		<?php endforeach; ?>
		</div>

		<div class="iw-copy-report-wrap">
			<textarea id="iw-copy-report-text" class="screen-reader-text" readonly aria-hidden="true"><?php echo esc_textarea( $plain_txt ); ?></textarea>
			<button type="button" class="button button-primary iw-copy-report" data-target="iw-copy-report-text">
				<?php esc_html_e( 'Copy Status Report', 'image-watermark' ); ?>
			</button>
			<span class="iw-copy-report-notice" aria-live="polite"></span>
		</div>
		<?php
	}

	/**
	 * Render Backup Folder field.
	 */
	public function render_backup_folder( $args ) {
		$backup_dir = defined( 'IMAGE_WATERMARK_BACKUP_DIR' ) ? IMAGE_WATERMARK_BACKUP_DIR : '';
		?>
		<code><?php echo esc_html( $backup_dir ? $backup_dir : __( 'Not defined', 'image-watermark' ) ); ?></code>
		<?php
	}

	/**
	 * Render Watermark Preview.
	 */
	public function render_preview( $args ) {
		$base_id = ! empty( $args['html_id'] ) ? $args['html_id'] : 'iw-watermark-preview';
		$stage_id = $base_id . '-stage';
		$placeholder_id = $base_id . '-placeholder';
		$image_id = $base_id . '-image';
		$text_id = $base_id . '-text';
		$origin_label_id = $base_id . '-origin-label';
		$origin_size_id = $base_id . '-origin-size';
		$options = $this->plugin->options;
		$type = $options['watermark_image']['type'];
		$watermark_id = isset( $options['watermark_image']['url'] ) ? (int) $options['watermark_image']['url'] : 0;
		$image_data = $watermark_id ? wp_get_attachment_image_src( $watermark_id, 'full', false ) : false;
		$image_url = $image_data ? $image_data[0] : '';
		$image_width = $image_data ? (int) $image_data[1] : 0;
		$image_height = $image_data ? (int) $image_data[2] : 0;

		$text = isset( $options['watermark_image']['text_string'] ) ? $options['watermark_image']['text_string'] : '';
		$font = isset( $options['watermark_image']['text_font'] ) ? $options['watermark_image']['text_font'] : 'Lato-Regular.ttf';
		$text_size = isset( $options['watermark_image']['text_size'] ) ? (int) $options['watermark_image']['text_size'] : 20;
		$text_color = isset( $options['watermark_image']['text_color'] ) ? $options['watermark_image']['text_color'] : '#ffffff';
		?>
		<div id="<?php echo esc_attr( $base_id ); ?>">
			<div id="<?php echo esc_attr( $stage_id ); ?>" data-stage-width="600" data-stage-height="400">
				<div class="iw-preview-stage-inner">
					<div id="<?php echo esc_attr( $placeholder_id ); ?>" class="iw-preview-placeholder"<?php echo ( $type === 'image' && $image_url ) ? ' style="display: none;"' : ''; ?>>
								<?php echo ( $type === 'text' ) ? esc_html__( 'Enter watermark text to preview.', 'image-watermark' ) : esc_html__( 'No watermark image has been selected yet.', 'image-watermark' ); ?>
					</div>
					<img id="<?php echo esc_attr( $image_id ); ?>" class="iw-preview-watermark" src="<?php echo esc_url( $image_url ); ?>" data-natural-width="<?php echo esc_attr( $image_width ); ?>" data-natural-height="<?php echo esc_attr( $image_height ); ?>" alt="<?php esc_attr_e( 'Watermark image preview', 'image-watermark' ); ?>" />
					<div id="<?php echo esc_attr( $text_id ); ?>" class="iw-preview-watermark iw-preview-watermark-text" data-font="<?php echo esc_attr( $font ); ?>" data-size="<?php echo esc_attr( $text_size ); ?>" data-color="<?php echo esc_attr( $text_color ); ?>"><?php echo esc_html( $text ); ?></div>
				</div>
			</div>
			<p class="iw-preview-origin">
				<span id="<?php echo esc_attr( $origin_label_id ); ?>"><?php echo ( $type === 'text' ) ? esc_html__( 'Original text size:', 'image-watermark' ) : esc_html__( 'Original watermark image:', 'image-watermark' ); ?></span>
				<span id="<?php echo esc_attr( $origin_size_id ); ?>">
					<?php
					if ( $type === 'image' ) {
						echo ( $image_width && $image_height )
							? esc_html( $image_width . ' x ' . $image_height . ' px' )
							: esc_html__( 'Not available.', 'image-watermark' );
					} else {
						echo esc_html__( 'Will update as you type.', 'image-watermark' );
					}
					?>
				</span>
			</p>
		</div>
		<?php
	}

	/**
	 * Render Watermark Image Selection.
	 */
	public function render_watermark_image( $args ) {
		$base_id = ! empty( $args['html_id'] ) ? $args['html_id'] : 'iw-watermark-image-ui';
		$input_id = $base_id . '-input';
		$select_id = $base_id . '-select';
		$remove_id = $base_id . '-remove';
		$options = $this->plugin->options;

		if ( $options['watermark_image']['url'] !== null && $options['watermark_image']['url'] != 0 ) {
			$image = wp_get_attachment_image_src( $options['watermark_image']['url'], [ 300, 300 ], false );
			$image_selected = true;
		} else {
			$image_selected = false;
		}
		?>

		<input id="<?php echo esc_attr( $input_id ); ?>" type="hidden" name="<?php echo esc_attr( $args['name'] ); ?>" value="<?php echo (int) $options['watermark_image']['url']; ?>" />

		<div id="<?php echo esc_attr( $base_id ); ?>" class="iw-field-group iw-image-ui iw-buttons-group horizontal">
			<input id="<?php echo esc_attr( $select_id ); ?>" type="button" class="button outline" value="<?php echo esc_attr__( 'Select image', 'image-watermark' ); ?>" />
			<input id="<?php echo esc_attr( $remove_id ); ?>" type="button" class="button outline" value="<?php echo esc_attr__( 'Remove image', 'image-watermark' ); ?>" <?php if ( $image_selected === false ) echo 'disabled="disabled"'; ?>/>
		</div>
		<?php
	}


	/**
	 * Render Watermark Custom Size.
	 */
	public function render_watermark_size_custom( $args ) {
		$base_id = ! empty( $args['html_id'] ) ? $args['html_id'] : 'iw-watermark-size-custom';
		$width_id = $base_id . '-width';
		$height_id = $base_id . '-height';
		?>
		<div class="iw-field-group iw-size-custom-group">
			<label>
				<span><?php esc_html_e( 'x:', 'image-watermark' ); ?></span> <input id="<?php echo esc_attr( $width_id ); ?>" type="text" size="5" name="<?php echo esc_attr( $args['callback_args']['width']['name'] ); ?>" value="<?php echo esc_attr( $args['callback_args']['width']['value'] ); ?>"> <span><?php esc_html_e( 'px', 'image-watermark' ); ?></span>
			</label>
			<label>
				<span><?php esc_html_e( 'y:', 'image-watermark' ); ?></span> <input id="<?php echo esc_attr( $height_id ); ?>" type="text" size="5" name="<?php echo esc_attr( $args['callback_args']['height']['name'] ); ?>" value="<?php echo esc_attr( $args['callback_args']['height']['value'] ); ?>"> <span><?php esc_html_e( 'px', 'image-watermark' ); ?></span>
			</label>
		</div>
		<?php
	}











}
