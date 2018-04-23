<?php
/**
 * Give Export Donations Functions
 */


/**
 * AJAX
 *
 * @see http://wordpress.stackexchange.com/questions/58834/echo-all-meta-keys-of-a-custom-post-type
 *
 * @return string
 */
function give_export_donations_get_custom_fields() {

	global $wpdb;
	$post_type = 'give_payment';
	$responces = array();

	$form_id = isset( $_POST['form_id'] ) ? intval( $_POST['form_id'] ) : '';

	if ( empty( $form_id ) ) {
		return false;
	}

	$ffm_field_array          = array();
	$non_multicolumn_ffm_keys = array();

	// Is FFM available? Take care of repeater fields.
	if ( class_exists( 'Give_FFM_Render_Form' ) ) {

		// Get the custom fields for the payment's form.
		$ffm = new Give_FFM_Render_Form();
		list( $post_fields, $taxonomy_fields, $custom_fields ) = $ffm->get_input_fields( $form_id );

		foreach ( $custom_fields as $field ) {

			// Assemble multi-column repeater fields.
			if ( isset( $field['multiple'] ) && 'repeat' === $field['input_type'] ) {
				$non_multicolumn_ffm_keys[] = $field['name'];

				foreach ( $field['columns'] as $column ) {

					// All other fields.
					$ffm_field_array['repeaters'][] = array(
						'subkey'       => 'repeater_' . give_export_donations_create_column_key( $column ),
						'metakey'      => $field['name'],
						'label'        => $column,
						'multi'        => 'true',
						'parent_meta'  => $field['name'],
						'parent_title' => $field['label'],
					);
				}
			} else {
				// All other fields.
				$ffm_field_array['single'][] = array(
					'subkey'  => $field['name'],
					'metakey' => $field['name'],
					'label'   => $field['label'],
					'multi'   => 'false',
					'parent'  => '',
				);
				$non_multicolumn_ffm_keys[]  = $field['name'];
			}
		}

		$responces['ffm_fields'] = $ffm_field_array;
	}// End if().

	$args          = array(
		'give_forms'     => array( $form_id ),
		'posts_per_page' => - 1,
		'fields'         => 'ids',
	);
	$donation_list = implode( '\',\'', (array) give_get_payments( $args ) );

	$query = "
        SELECT DISTINCT($wpdb->paymentmeta.meta_key) 
        FROM $wpdb->posts 
        LEFT JOIN $wpdb->paymentmeta 
        ON $wpdb->posts.ID = $wpdb->paymentmeta.payment_id
        WHERE $wpdb->posts.post_type = '%s'
        AND $wpdb->posts.ID IN (%s)
        AND $wpdb->paymentmeta.meta_key != '' 
        AND $wpdb->paymentmeta.meta_key NOT RegExp '(^[_0-9].+$)'
    ";

	$meta_keys = $wpdb->get_col( $wpdb->prepare( $query, $post_type, $donation_list ) );

	// Unset ignored FFM keys.
	foreach ( $non_multicolumn_ffm_keys as $key ) {
		if ( ( $key = array_search( $key, $meta_keys ) ) !== false ) {
			unset( $meta_keys[ $key ] );
		}
	}

	if ( ! empty( $meta_keys ) ) {
		$responces['standard_fields'] = array_values( $meta_keys );
	}

	$query = "
        SELECT DISTINCT($wpdb->paymentmeta.meta_key) 
        FROM $wpdb->posts 
        LEFT JOIN $wpdb->paymentmeta 
        ON $wpdb->posts.ID = $wpdb->paymentmeta.payment_id 
        WHERE $wpdb->posts.post_type = '%s' 
        AND $wpdb->posts.ID IN (%s)
        AND $wpdb->paymentmeta.meta_key != '' 
        AND $wpdb->paymentmeta.meta_key NOT RegExp '^[^_]'
    ";

	$hidden_meta_keys   = $wpdb->get_col( $wpdb->prepare( $query, $post_type, $donation_list ) );
	$ignore_hidden_keys = array(
		'_give_payment_meta',
		'_give_payment_gateway',
		'_give_payment_form_title',
		'_give_payment_form_id',
		'_give_payment_price_id',
		'_give_payment_user_id',
		'_give_payment_user_email',
		'_give_payment_user_ip',
		'_give_payment_customer_id',
		'_give_payment_total',
		'_give_completed_date',
		'_give_donation_company',
		'_give_donor_billing_first_name',
		'_give_donor_billing_last_name',
		'_give_payment_donor_email',
		'_give_payment_donor_id',
		'_give_payment_date',
		'_give_donor_billing_address1',
		'_give_donor_billing_address2',
		'_give_donor_billing_city',
		'_give_donor_billing_zip',
		'_give_donor_billing_state',
		'_give_donor_billing_country',
		'_give_payment_import',
		'_give_payment_currency',
		'_give_payment_import_id',
		'_give_payment_donor_ip',
	);

	// Unset ignored FFM keys.
	foreach ( $ignore_hidden_keys as $key ) {
		if ( ( $key = array_search( $key, $hidden_meta_keys ) ) !== false ) {
			unset( $hidden_meta_keys[ $key ] );
		}
	}

	if ( ! empty( $hidden_meta_keys ) ) {
		$responces['hidden_fields'] = array_values( $hidden_meta_keys );
	}

	/**
	 * Filter to modify custom fields when select donation forms,
	 *
	 * @since 2.1
	 *
	 * @param array $responces
	 *
	 * @return array $responces
	 */
	wp_send_json( (array) apply_filters( 'give_export_donations_get_custom_fields', $responces ) );

}

add_action( 'wp_ajax_give_export_donations_get_custom_fields', 'give_export_donations_get_custom_fields' );

/**
 * Register the payments batch exporter
 *
 * @since  1.0
 */
function give_register_export_donations_batch_export() {
	add_action( 'give_batch_export_class_include', 'give_export_donations_include_export_class', 10, 1 );
}

add_action( 'give_register_batch_exporter', 'give_register_export_donations_batch_export', 10 );


/**
 * Includes the Give Export Donations Custom Exporter Class.
 *
 * @param $class Give_Export_Donations_CSV
 */
function give_export_donations_include_export_class( $class ) {
	if ( 'Give_Export_Donations_CSV' === $class ) {
		require_once GIVE_PLUGIN_DIR . 'includes/admin/tools/export/give-export-donations-exporter.php';
	}
}


/**
 * Create column key.
 *
 * @param $string
 *
 * @return string
 */
function give_export_donations_create_column_key( $string ) {
	return sanitize_key( str_replace( ' ', '_', $string ) );
}

/**
 * Filter to modify donation search form when search through AJAX
 *
 * @since 2.1
 *
 * @param $args
 *
 * @return  $args
 */
function give_export_donation_form_search_args( $args ) {
	if ( empty( $_POST['fields'] ) ) {
		return $args;
	}

	$fields = isset( $_POST['fields'] ) ? $_POST['fields'] : null;
	parse_str( $fields );

	if ( ! empty( $give_forms_categories ) && ! empty( $give_forms_tags ) ) {
		$args['tax_query']['relation'] = 'AND';
	}

	if ( ! empty( $give_forms_categories ) ) {
		$args['tax_query'][] = array(
			'taxonomy' => 'give_forms_category',
			'field'    => 'term_id',
			'terms'    => $give_forms_categories,
			'operator' => 'AND',
		);
	}

	if ( ! empty( $give_forms_tags ) ) {
		$args['tax_query'][] = array(
			'taxonomy' => 'give_forms_tag',
			'field'    => 'term_id',
			'terms'    => $give_forms_tags,
			'operator' => 'AND',
		);
	}

	return $args;
}

add_filter( 'give_ajax_form_search_args', 'give_export_donation_form_search_args' );

/**
 * Add Donation standard fields in export donation page
 *
 * @since 2.1
 */
function give_export_donation_standard_fields() {
	?>
	<tr>
		<td scope="row" class="row-title">
			<label><?php _e( 'Standard Columns:', 'give' ); ?></label>
		</td>
		<td>
			<div class="give-clearfix">
				<ul class="give-export-option">
					<li class="give-export-option-fields give-export-option-payment-fields">
						<ul class="give-export-option-payment-fields-ul">

							<li class="give-export-option-label give-export-option-donation-label">
												<span>
													<?php _e( 'Donation Payment Fields', 'give' ); ?>
												</span>
							</li>

							<li class="give-export-option-start">
								<label for="give-export-donation-id">
									<input type="checkbox" checked
									       name="give_give_donations_export_option[donation_id]"
									       id="give-export-donation-id"><?php _e( 'Donation ID', 'give' ); ?>
								</label>
							</li>

							<?php
							if ( give_is_setting_enabled( give_get_option( 'sequential-ordering_status', 'disabled' ) ) ) {
								?>
								<li>
									<label for="give-export-seq-id">
										<input type="checkbox" checked
										       name="give_give_donations_export_option[seq_id]"
										       id="give-export-seq-id"><?php _e( 'Donation Number', 'give' ); ?>
									</label>
								</li>
								<?php
							}
							?>

							<li>
								<label for="give-export-donation-sum">
									<input type="checkbox" checked
									       name="give_give_donations_export_option[donation_total]"
									       id="give-export-donation-sum"><?php _e( 'Donation Total', 'give' ); ?>
								</label>
							</li>

							<li>
								<label for="give-export-donation-currency_code">
									<input type="checkbox" checked
									       name="give_give_donations_export_option[currency_code]"
									       id="give-export-donation-currency_code"><?php _e( 'Currency Code', 'give' ); ?>
								</label>
							</li>

							<li>
								<label for="give-export-donation-currency_symbol">
									<input type="checkbox" checked
									       name="give_give_donations_export_option[currency_symbol]"
									       id="give-export-donation-currency_symbol"><?php _e( 'Currency Symbol', 'give' ); ?>
								</label>
							</li>

							<li>
								<label for="give-export-donation-status">
									<input type="checkbox" checked
									       name="give_give_donations_export_option[donation_status]"
									       id="give-export-donation-status"><?php _e( 'Donation Status', 'give' ); ?>
								</label>
							</li>

							<li>
								<label for="give-export-donation-date">
									<input type="checkbox" checked
									       name="give_give_donations_export_option[donation_date]"
									       id="give-export-donation-date"><?php _e( 'Donation Date', 'give' ); ?>
								</label>
							</li>

							<li>
								<label for="give-export-donation-time">
									<input type="checkbox" checked
									       name="give_give_donations_export_option[donation_time]"
									       id="give-export-donation-time"><?php _e( 'Donation Time', 'give' ); ?>
								</label>
							</li>

							<li>
								<label for="give-export-payment-gateway">
									<input type="checkbox" checked
									       name="give_give_donations_export_option[payment_gateway]"
									       id="give-export-payment-gateway"><?php _e( 'Payment Gateway', 'give' ); ?>
								</label>
							</li>
						</ul>
					</li>

					<li class="give-export-option-fields give-export-option-form-fields">
						<ul class="give-export-option-form-fields-ul">

							<li class="give-export-option-label give-export-option-Form-label">
												<span>
													<?php _e( 'Donation Form Fields', 'give' ); ?>
												</span>
							</li>


							<li class="give-export-option-start">
								<label for="give-export-donation-form-id">
									<input type="checkbox" checked
									       name="give_give_donations_export_option[form_id]"
									       id="give-export-donation-form-id"><?php _e( 'Donation Form ID', 'give' ); ?>
								</label>
							</li>

							<li>
								<label for="give-export-donation-form-title">
									<input type="checkbox" checked
									       name="give_give_donations_export_option[form_title]"
									       id="give-export-donation-form-title"><?php _e( 'Donation Form Title', 'give' ); ?>
								</label>
							</li>

							<li>
								<label for="give-export-donation-form-level-id">
									<input type="checkbox" checked
									       name="give_give_donations_export_option[form_level_id]"
									       id="give-export-donation-form-level-id"><?php _e( 'Donation Form Level ID', 'give' ); ?>
								</label>
							</li>

							<li>
								<label for="give-export-donation-form-level-title">
									<input type="checkbox" checked
									       name="give_give_donations_export_option[form_level_title]"
									       id="give-export-donation-form-level-title"><?php _e( 'Donation Form Level Title', 'give' ); ?>
								</label>
							</li>
						</ul>
					</li>

					<li class="give-export-option-fields give-export-option-donor-fields">
						<ul class="give-export-option-donor-fields-ul">

							<li class="give-export-option-label give-export-option-donor-label">
												<span>
													<?php _e( 'Donor Fields', 'give' ); ?>
												</span>
							</li>

							<li class="give-export-option-start">
								<label for="give-export-first-name">
									<input type="checkbox" checked
									       name="give_give_donations_export_option[first_name]"
									       id="give-export-first-name"><?php _e( 'Donor\'s First Name', 'give' ); ?>
								</label>
							</li>

							<li>
								<label for="give-export-last-name">
									<input type="checkbox" checked
									       name="give_give_donations_export_option[last_name]"
									       id="give-export-last-name"><?php _e( 'Donor\'s Last Name', 'give' ); ?>
								</label>
							</li>

							<li>
								<label for="give-export-email">
									<input type="checkbox" checked
									       name="give_give_donations_export_option[email]"
									       id="give-export-email"><?php _e( 'Donor\'s Email', 'give' ); ?>
								</label>
							</li>

							<li>
								<label for="give-export-company">
									<input type="checkbox" checked
									       name="give_give_donations_export_option[company]"
									       id="give-export-company"><?php _e( 'Company Name', 'give' ); ?>
								</label>
							</li>

							<li>
								<label for="give-export-address">
									<input type="checkbox" checked
									       name="give_give_donations_export_option[address]"
									       id="give-export-address"><?php _e( 'Donor\'s Billing Address', 'give' ); ?>
								</label>
							</li>

							<li>
								<label for="give-export-userid">
									<input type="checkbox" checked
									       name="give_give_donations_export_option[userid]"
									       id="give-export-userid"><?php _e( 'User ID', 'give' ); ?>
								</label>
							</li>

							<li>
								<label for="give-export-donorid">
									<input type="checkbox" checked
									       name="give_give_donations_export_option[donorid]"
									       id="give-export-donorid"><?php _e( 'Donor ID', 'give' ); ?>
								</label>
							</li>

							<li>
								<label for="give-export-donor-ip">
									<input type="checkbox" checked
									       name="give_give_donations_export_option[donor_ip]"
									       id="give-export-donor-ip"><?php _e( 'Donor IP Address', 'give' ); ?>
								</label>
							</li>
						</ul>
					</li>
				</ul>
			</div>
		</td>
	</tr>
	<?php
}
add_action( 'give_export_donation_fields', 'give_export_donation_standard_fields', 10 );

/**
 * Add Donation Custom fields in export donation page
 *
 * @since 2.1
 */
function give_export_donation_custom_fields() {
	?>
	<tr
		class="give-hidden give-export-donations-hide give-export-donations-standard-fields">
		<td scope="row" class="row-title">
			<label><?php _e( 'Custom Field Columns:', 'give' ); ?></label>
		</td>
		<td class="give-field-wrap">
			<div class="give-clearfix">
				<ul class="give-export-option-ul"></ul>
				<p class="give-field-description"><?php _e( 'The following fields may have been created by custom code, or another plugin.', 'give' ); ?></p>
			</div>
		</td>
	</tr>
	<?php
}
add_action( 'give_export_donation_fields', 'give_export_donation_custom_fields', 30 );


/**
 * Add Donation hidden fields in export donation page
 *
 * @since 2.1
 */
function give_export_donation_hidden_fields() {
	?>

	<tr class="give-hidden give-export-donations-hide give-export-donations-hidden-fields">
		<td scope="row" class="row-title">
			<label><?php _e( 'Hidden Custom Field Columns:', 'give' ); ?></label>
		</td>
		<td class="give-field-wrap">
			<div class="give-clearfix">
				<ul class="give-export-option-ul"></ul>
				<p class="give-field-description"><?php _e( 'The following hidden custom fields contain data created by Give Core, a Give Add-on, another plugin, etc.<br/>Hidden fields are generally used for programming logic, but you may contain data you would like to export.', 'give' ); ?></p>
			</div>
		</td>
	</tr>
	<?php
}
add_action( 'give_export_donation_fields', 'give_export_donation_hidden_fields', 40 );

