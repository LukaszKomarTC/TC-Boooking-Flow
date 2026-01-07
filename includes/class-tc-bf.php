<?php
namespace TC_BF;

if ( ! defined('ABSPATH') ) exit;

final class Plugin {

	private static $instance = null;

	const GF_FORM_ID = 44;

	// GF field IDs used in your form 44 export
	const GF_FIELD_EVENT_ID      = 20;
	const GF_FIELD_EVENT_TITLE   = 1;
	const GF_FIELD_TOTAL         = 76;
	const GF_FIELD_SUBTOTAL      = 173; // undiscounted subtotal (part + rental)
	const GF_FIELD_START_RAW     = 132;
	const GF_FIELD_END_RAW       = 134;

	// Coupon / partner code (your form uses field 154 as "partner code" input)
	const GF_FIELD_COUPON_CODE   = 154;


	// Partner fields in GF44 (admin override + hidden fields)
	const GF_FIELD_PARTNER_OVERRIDE = 63;  // admin-only select
	const GF_FIELD_PARTNER_EMAIL    = 153; // hidden
	const GF_FIELD_PARTNER_ID       = 166; // hidden
	const GF_FIELD_PARTNER_COMM_PCT = 161; // hidden
	const GF_FIELD_DISCOUNT_PCT     = 152; // hidden

	// EB hidden field is 172 with inputName early_booking_discount_pct (dynamic population)
	const GF_FIELD_EB_PCT        = 172;

	// Optional “bicycle choice” concat fields in your snippet
	const GF_FIELD_BIKE_130      = 130;
	const GF_FIELD_BIKE_142      = 142;
	const GF_FIELD_BIKE_143      = 143;
	const GF_FIELD_BIKE_169      = 169;

	// Optional participant fields used in your snippet (keep compatible)
	const GF_FIELD_FIRST_NAME    = 9;
	const GF_FIELD_LAST_NAME     = 10;

	// Optional rental type select field (used in your validation snippet)
	// Values like: ROAD / MTB / eMTB / GRAVEL (sometimes prefixed by labels)
	const GF_FIELD_RENTAL_TYPE   = 106;

	// Per-event config meta
	const META_EB_ENABLED                = 'tc_ebd_enabled';
	const META_EB_RULES_JSON             = 'tc_ebd_rules_json';
	const META_EB_CAP                    = 'tc_ebd_cap';
	const META_EB_PARTICIPATION_ENABLED  = 'tc_ebd_participation_enabled';
	const META_EB_RENTAL_ENABLED         = 'tc_ebd_rental_enabled';

	// Booking meta keys stored on cart items
	const BK_EVENT_ID      = '_event_id';
	const BK_EVENT_TITLE   = '_event_title';
	const BK_ENTRY_ID      = '_entry_id';
	const BK_CUSTOM_COST   = '_custom_cost';

	const BK_SCOPE         = '_tc_scope';          // 'participation' | 'rental'
	const BK_EB_PCT        = '_eb_pct';            // snapshot pct
	const BK_EB_AMOUNT     = '_eb_amount';         // snapshot discount amount per line (per unit)
	const BK_EB_ELIGIBLE   = '_eb_eligible';       // 0/1
	const BK_EB_DAYS       = '_eb_days_before';    // snapshot days
	const BK_EB_BASE       = '_eb_base_price';     // snapshot base (per line, before EB)
	const BK_EB_EVENT_TS   = '_eb_event_start_ts'; // snapshot for audit

	// GF entry meta keys (dedupe)
	const GF_META_CART_ADDED = 'tc_cart_added';

	// Request cache
	private $calc_cache = [];

	public static function instance() : self {
		if ( self::$instance === null ) self::$instance = new self();
		return self::$instance;
	}
	private function __construct() { $this->hooks(); }

	private function hooks() : void {

		// Admin: product meta field
		if ( is_admin() ) {
			\TC_BF\Admin\Product_Meta::init();
			\TC_BF\Admin\Settings::init();
			\TC_BF\Admin\Partners::init();
		}

		// ---- GF: dynamic EB% population (field 172)
		add_filter('gform_field_value_early_booking_discount_pct', [ $this, 'gf_populate_eb_pct' ]);

		// ---- GF: partner override dropdown + populate partner hidden fields
		add_filter('gform_pre_render',            [ $this, 'gf_partner_prepare_form' ], 9);
		add_filter('gform_pre_validation',        [ $this, 'gf_partner_prepare_form' ], 9);
		add_filter('gform_pre_submission_filter', [ $this, 'gf_partner_prepare_form' ], 9);

		// ---- GF: server-side validation (tamper-proof + self-heal)
		add_filter('gform_validation', [ $this, 'gf_validation' ], 10, 1);

		// ---- GF: submission to cart (single source of truth)
		add_action('gform_after_submission', [ $this, 'gf_after_submission_add_to_cart' ], 10, 2);

		// ---- Woo Bookings: override booking cost when we pass _custom_cost (existing behavior)
		add_filter('woocommerce_bookings_calculated_booking_cost', [ $this, 'woo_override_booking_cost' ], 11, 3);

		// ---- EB: apply snapshot EB to eligible booking cart items
		add_action('woocommerce_before_calculate_totals', [ $this, 'woo_apply_eb_snapshot_to_cart' ], 20, 1);

		// ---- Cart display: show booking meta to the customer
		add_filter('woocommerce_get_item_data', [ $this, 'woo_cart_item_data' ], 20, 2);

		// ---- Order item meta: persist booking meta to line items (your pasted snippet)
		add_action('woocommerce_checkout_create_order_line_item', [ $this, 'woo_checkout_create_order_line_item' ], 20, 4);

		// ---- Partner order meta: persist partner accounting meta (kept, but base uses snapshot if present)
		add_action('woocommerce_checkout_create_order', [ $this, 'partner_persist_order_meta' ], 25, 2);

		// ---- Ledger: write EB + partner ledger on order (snapshot-driven)
		add_action('woocommerce_checkout_order_processed', [ $this, 'eb_write_order_ledger' ], 40, 3);

		// ---- GF notifications: custom event + fire on successful payment (parity with legacy snippets)
		add_filter('gform_notification_events', [ $this, 'gf_register_notification_events' ], 10, 1);
		add_action('woocommerce_payment_complete', [ $this, 'woo_fire_gf_paid_notifications' ], 20, 1);
		// Fallbacks for gateways / edge flows where payment_complete isn't triggered as expected
		add_action('woocommerce_order_status_processing', [ $this, 'woo_fire_gf_paid_notifications' ], 20, 2);
		add_action('woocommerce_order_status_completed',  [ $this, 'woo_fire_gf_paid_notifications' ], 20, 2);

		add_action('woocommerce_order_status_invoiced',   [ $this, 'woo_fire_gf_settled_notifications' ], 20, 2);
		// ---- Partner coupon: auto-apply partner coupon for logged-in partners (legacy parity)
		// Run late on wp_loaded so WC()->cart is available.
		add_action('wp_loaded', [ $this, 'maybe_auto_apply_partner_coupon' ], 30);
	}

	/**
	 * Auto-apply partner coupon for logged-in users who have a discount__code set.
	 *
	 * Notes:
	 * - Woo coupon codes are effectively case-insensitive, but we normalize with wc_format_coupon_code().
	 * - We only auto-apply when there is at least one TC Booking Flow cart item.
	 */
	public function maybe_auto_apply_partner_coupon() : void {
		// Frontend only
		if ( is_admin() || wp_doing_ajax() || (defined('REST_REQUEST') && REST_REQUEST) ) return;
		if ( ! is_user_logged_in() ) return;
			if ( ! function_exists('WC') || ! WC() || ! WC()->cart ) return;
		$cart = WC()->cart;
		if ( $cart->is_empty() ) return;

		// Only apply if cart contains at least one TC Booking Flow item.
		$has_tc_item = false;
		foreach ( $cart->get_cart() as $cart_item ) {
			if ( isset($cart_item['booking']) && is_array($cart_item['booking']) && ! empty($cart_item['booking'][self::BK_EVENT_ID]) ) {
				$has_tc_item = true;
				break;
			}
		}
		if ( ! $has_tc_item ) return;

		$user_id = get_current_user_id();
		$code_raw = (string) get_user_meta( $user_id, 'discount__code', true );
		$code_raw = trim($code_raw);
		if ( $code_raw === '' ) return;

		$code = function_exists('wc_format_coupon_code') ? wc_format_coupon_code($code_raw) : strtolower($code_raw);
		if ( $code === '' ) return;

		// Already applied?
		$applied = array_map('wc_format_coupon_code', (array) $cart->get_applied_coupons());
		if ( in_array($code, $applied, true) ) return;

		// Coupon exists?
		if ( ! function_exists('wc_get_coupon_id_by_code') ) return;
		$coupon_id = (int) wc_get_coupon_id_by_code( $code );
		if ( $coupon_id <= 0 ) {
			// Some stores save post_title in uppercase; try raw too.
			$coupon_id = (int) wc_get_coupon_id_by_code( $code_raw );
		}
		if ( $coupon_id <= 0 ) {
			$this->log('partner.coupon.auto_apply.missing', ['user_id'=>$user_id,'code_raw'=>$code_raw,'code_norm'=>$code]);
			return;
		}

		$ok = $cart->add_discount( $code );
		$this->log('partner.coupon.auto_apply', ['user_id'=>$user_id,'code'=>$code,'ok'=>$ok ? 1 : 0]);
	}




	/* =========================================================
	 * GF: Partner override + partner fields population (GF44)
	 * ========================================================= */

	/**
	 * GF: Build admin partner dropdown choices + populate partner hidden fields.
	 *
	 * Priority rules:
	 * 1) Admin override (field 63)
	 * 2) Logged-in partner/hotel user (role=hotel + discount__code user meta)
	 * 3) Existing coupon code in GF field 154 (manual / legacy)
	 *
	 * This enables instant EB + partner discount visibility inside the GF form.
	 */
	public function gf_partner_prepare_form( $form ) {

		// Guard: GF not active or form not array
		if ( ! is_array($form) || empty($form['id']) ) return $form;

		$target_form_id = class_exists('\\TC_BF\\Admin\\Settings') ? (int) \TC_BF\Admin\Settings::get_form_id() : self::GF_FORM_ID;
		if ( (int) $form['id'] !== $target_form_id ) return $form;

		if ( ! is_user_logged_in() ) return $form;

		$user = wp_get_current_user();
		if ( ! $user || empty($user->ID) ) return $form;

		$is_admin = current_user_can('manage_options');
		$is_hotel = in_array('hotel', (array) $user->roles, true);

		// 1) Build dropdown for admin only (field 63)
		$partners = [];
		if ( $is_admin ) {

			$partners = $this->get_partner_users_for_dropdown();

			if ( ! empty($form['fields']) && is_array($form['fields']) ) {

				foreach ( $form['fields'] as &$field ) {

					$fid = 0;
					$choices = [];

					if ( is_object($field) ) {
						// GF_Field object (runtime)
						$fid     = (int) ($field->id ?? 0);
						$choices = is_array($field->choices ?? null) ? $field->choices : [];
					} elseif ( is_array($field) ) {
						// Array (exports / older contexts)
						$fid     = (int) ($field['id'] ?? 0);
						$choices = isset($field['choices']) && is_array($field['choices']) ? $field['choices'] : [];
					} else {
						continue;
					}

					if ( $fid !== self::GF_FIELD_PARTNER_OVERRIDE ) {
						continue;
					}

					// Ensure placeholder is first choice
					if ( empty($choices) || (string)($choices[0]['value'] ?? '___') !== '' ) {
						array_unshift($choices, [
							'text' => '— Select partner —',
							'value' => '',
							'isSelected' => false,
							'price' => '',
						]);
					}

					// Append dynamic partners
					foreach ( $partners as $p ) {
						$choices[] = [
							'text' => $p['label'],
							'value' => (string) $p['id'], // store user ID
							'isSelected' => false,
							'price' => '',
						];
					}

					// Write back choices
					if ( is_object($field) ) {
						$field->choices = $choices;
					} else {
						$field['choices'] = $choices;
					}

					// Add inline JS to instantly fill hidden fields on change
					$this->gf_register_partner_js_init( (int) $form['id'], $partners );

					break;
				}

				unset($field);
			}
		}

		// 2) Resolve partner context (priority rules)
		$ctx = $this->gf_resolve_partner_context( $is_admin, $is_hotel );

		// 3) Apply context into POST so GF calculations can use it
		// (GF expects input_<field_id> keys)
		if ( $ctx['partner_id'] > 0 ) {

			$_POST['input_' . self::GF_FIELD_PARTNER_ID]       = (string) $ctx['partner_id'];
			$_POST['input_' . self::GF_FIELD_PARTNER_EMAIL]    = (string) $ctx['partner_email'];
			$_POST['input_' . self::GF_FIELD_PARTNER_COMM_PCT] = (string) wc_format_decimal((float) $ctx['commission_pct'], 2);
			$_POST['input_' . self::GF_FIELD_COUPON_CODE]      = (string) $ctx['coupon_code'];
			$_POST['input_' . self::GF_FIELD_DISCOUNT_PCT]     = (string) wc_format_decimal((float) $ctx['discount_pct'], 2);

			$this->log('gf.partner.ctx', [
				'source' => $ctx['source'],
				'partner_id' => $ctx['partner_id'],
				'coupon' => $ctx['coupon_code'],
				'discount_pct' => $ctx['discount_pct'],
				'commission_pct' => $ctx['commission_pct'],
			]);

		} else {
			// If admin cleared override and user isn't a partner, ensure we don't keep stale partner values.
			if ( $is_admin || ! $is_hotel ) {
				$_POST['input_' . self::GF_FIELD_PARTNER_ID]       = '';
				$_POST['input_' . self::GF_FIELD_PARTNER_EMAIL]    = '';
				$_POST['input_' . self::GF_FIELD_PARTNER_COMM_PCT] = '';
				$_POST['input_' . self::GF_FIELD_DISCOUNT_PCT]     = '';
				// Do NOT wipe coupon code (154) if it was manually provided; resolver already handles it.
			}
		}

		return $form;
	}

	/**
	 * Return partner users list for admin dropdown.
	 *
	 * Partner definition (legacy parity):
	 * - has user meta discount__code (coupon code)
	 * Optional: role=hotel is a hint but discount__code is the real source.
	 */
	private function get_partner_users_for_dropdown() : array {

		$users = get_users([
			'fields' => ['ID','display_name','user_email'],
			'meta_query' => [
				[
					'key' => 'discount__code',
					'compare' => 'EXISTS',
				],
			],
			'number' => 500,
			'orderby' => 'display_name',
			'order' => 'ASC',
		]);

		$out = [];
		foreach ( $users as $u ) {
			$uid = (int) $u->ID;

			$code_raw = trim((string) get_user_meta($uid, 'discount__code', true));
			if ( $code_raw === '' ) continue;

			$code = function_exists('wc_format_coupon_code') ? wc_format_coupon_code($code_raw) : strtolower($code_raw);

			$out[] = [
				'id' => $uid,
				'email' => (string) $u->user_email,
				'display' => (string) $u->display_name,
				'code' => $code,
				'commission_pct' => (float) get_user_meta($uid, 'usrdiscount', true),
				'discount_pct' => $this->get_coupon_percent_amount($code),
				'label' => sprintf('%s (%s)', (string) $u->display_name, $code),
			];
		}

		return $out;
	}

	private function gf_resolve_partner_context( bool $is_admin, bool $is_hotel ) : array {

		$ctx = [
			'partner_id' => 0,
			'partner_email' => '',
			'coupon_code' => '',
			'discount_pct' => 0.0,
			'commission_pct' => 0.0,
			'source' => 'none',
		];

		// 1) Admin override
			// 1) Admin override
			if ( $is_admin ) {
				$override_raw = isset($_POST['input_' . self::GF_FIELD_PARTNER_OVERRIDE]) ? trim((string) $_POST['input_' . self::GF_FIELD_PARTNER_OVERRIDE]) : '';
				if ( $override_raw !== '' ) {
					if ( ctype_digit($override_raw) ) {
						$override_id = (int) $override_raw;
						if ( $override_id > 0 ) {
							$ctx = $this->partner_ctx_from_user_id($override_id);
							$ctx['source'] = 'admin_override_user';
							return $ctx;
						}
					} else {
						$ctx = $this->partner_ctx_from_code($override_raw);
						if ( $ctx['coupon_code'] !== '' ) {
							$ctx['source'] = 'admin_override_code';
							return $ctx;
						}
					}
				}
			}

		// 2) Logged-in hotel user
		if ( $is_hotel ) {
			$ctx = $this->partner_ctx_from_user_id( (int) get_current_user_id() );
			$ctx['source'] = $ctx['partner_id'] > 0 ? 'hotel_user' : 'hotel_user_missing_code';
			return $ctx;
		}

		// 3) Existing coupon code in GF field 154 (manual / legacy)
		$code_raw = isset($_POST['input_' . self::GF_FIELD_COUPON_CODE]) ? trim((string) $_POST['input_' . self::GF_FIELD_COUPON_CODE]) : '';
		if ( $code_raw !== '' ) {
			$code = function_exists('wc_format_coupon_code') ? wc_format_coupon_code($code_raw) : strtolower($code_raw);
			$ctx['coupon_code'] = $code;
			$ctx['discount_pct'] = $this->get_coupon_percent_amount($code);
			$ctx['source'] = 'manual_coupon';
		}

		return $ctx;
	}

	private function partner_ctx_from_user_id( int $uid ) : array {

		$u = get_user_by('id', $uid);
		if ( ! $u ) {
			return [
				'partner_id' => 0,
				'partner_email' => '',
				'coupon_code' => '',
				'discount_pct' => 0.0,
				'commission_pct' => 0.0,
				'source' => 'invalid_user',
			];
		}

		$code_raw = trim((string) get_user_meta($uid, 'discount__code', true));
		if ( $code_raw === '' ) {
			return [
				'partner_id' => 0,
				'partner_email' => '',
				'coupon_code' => '',
				'discount_pct' => 0.0,
				'commission_pct' => 0.0,
				'source' => 'missing_code',
			];
		}

		$code = function_exists('wc_format_coupon_code') ? wc_format_coupon_code($code_raw) : strtolower($code_raw);

		return [
			'partner_id' => (int) $uid,
			'partner_email' => (string) $u->user_email,
			'coupon_code' => (string) $code,
			'discount_pct' => $this->get_coupon_percent_amount($code),
			'commission_pct' => (float) get_user_meta($uid, 'usrdiscount', true),
			'source' => 'user_meta',
		];
	}


		private function partner_ctx_from_code( string $code_raw ) : array {
			$code_raw = trim($code_raw);
			if ( $code_raw === '' ) {
				return [
					'partner_id' => 0,
					'partner_email' => '',
					'coupon_code' => '',
					'discount_pct' => 0.0,
					'commission_pct' => 0.0,
					'source' => 'empty_code',
				];
			}

			$code = function_exists('wc_format_coupon_code') ? wc_format_coupon_code($code_raw) : strtolower($code_raw);

			// Find user with matching discount__code
			$q = new \WP_User_Query([
				'number'     => 1,
				'fields'     => 'ids',
				'meta_query' => [
					[
						'key'   => 'discount__code',
						'value' => $code,
					],
				],
			]);
			$ids = $q->get_results();
			$uid = $ids ? (int) $ids[0] : 0;
			if ( $uid > 0 ) {
				$ctx = $this->partner_ctx_from_user_id($uid);
				$ctx['source'] = 'code_lookup';
				return $ctx;
			}

			return [
				'partner_id' => 0,
				'partner_email' => '',
				'coupon_code' => $code,
				'discount_pct' => $this->get_coupon_percent_amount($code),
				'commission_pct' => 0.0,
				'source' => 'code_only',
			];
		}

	/**
	 * Read coupon % for percent coupons. Returns 0 if not found or not percent type.
	 */
	private function get_coupon_percent_amount( string $code ) : float {

		$code = trim($code);
		if ( $code === '' ) return 0.0;
		if ( ! class_exists('\\WC_Coupon') ) return 0.0;

		try {
			$c = new \WC_Coupon($code);
			if ( ! $c || ! $c->get_id() ) return 0.0;

			$type = (string) $c->get_discount_type();
			if ( $type !== 'percent' ) return 0.0;

			return (float) $c->get_amount();
		} catch ( \Throwable $e ) {
			return 0.0;
		}
	}

	/**
	 * Inline JS: for admin override dropdown (field 63),
	 * instantly populate the hidden partner fields so GF calcs update immediately.
	 */
	private function gf_register_partner_js_init( int $form_id, array $partners ) : void {

		if ( ! class_exists('\GFFormDisplay') ) return;

		$map = [];
		foreach ( $partners as $p ) {
			$row = [
				'partner_id'      => (int) $p['id'],
				'partner_email'   => (string) $p['email'],
				'coupon_code'     => (string) $p['code'],
				'discount_pct'    => (float) $p['discount_pct'],
				'commission_pct'  => (float) $p['commission_pct'],
			];
			$map[(string)$p['id']] = $row;
			if ( ! empty($p['code']) ) {
				$map[(string)$p['code']] = $row; // support dropdown values = coupon code
			}
		}

		$json = wp_json_encode($map);

		$override_id = 'input_' . $form_id . '_' . self::GF_FIELD_PARTNER_OVERRIDE;
		$hid_partner_id = 'input_' . $form_id . '_' . self::GF_FIELD_PARTNER_ID;
		$hid_partner_email = 'input_' . $form_id . '_' . self::GF_FIELD_PARTNER_EMAIL;
		$hid_comm_pct = 'input_' . $form_id . '_' . self::GF_FIELD_PARTNER_COMM_PCT;
		$hid_coupon = 'input_' . $form_id . '_' . self::GF_FIELD_COUPON_CODE;
		$hid_disc_pct = 'input_' . $form_id . '_' . self::GF_FIELD_DISCOUNT_PCT;

		$eb_pct_id = 'input_' . $form_id . '_' . self::GF_FIELD_EB_PCT;
		$eb_amt_id = 'input_' . $form_id . '_' . self::GF_FIELD_EB_AMOUNT;
		$partner_amt_id = 'input_' . $form_id . '_' . self::GF_FIELD_PARTNER_DISCOUNT_AMT;
		$total_client_id = 'input_' . $form_id . '_' . self::GF_FIELD_TOTAL_CLIENT;

		$script = "(function($){
"
			. "  var map = {$json} || {};
"
			. "  function setVal(domId, v){
"
			. "    var el = document.getElementById(domId);
"
			. "    if(!el) return;
"
			. "    el.value = (v === null || typeof v === 'undefined') ? '' : v;
"
			. "    $(el).trigger('change');
"
			. "  }
"
			. "  function showField(fid){ var f=document.getElementById(fid); if(!f) return; f.style.display=''; $(f).find(':input').prop('disabled', false); }
"
			. "  function hideField(fid){ var f=document.getElementById(fid); if(!f) return; f.style.display='none'; $(f).find(':input').prop('disabled', true); }
"
			. "  function refreshBreakdown(){
"
			. "    var ebPct = parseFloat((document.getElementById('{$eb_pct_id}')||{}).value||'0') || 0;
"
			. "    var ebAmt = parseFloat((document.getElementById('{$eb_amt_id}')||{}).value||'0') || 0;
"
			. "    var pAmt  = parseFloat((document.getElementById('{$partner_amt_id}')||{}).value||'0') || 0;
"
			. "    if (ebPct>0 || ebAmt>0) showField('field_{$form_id}_".self::GF_FIELD_EB_AMOUNT."'); else hideField('field_{$form_id}_".self::GF_FIELD_EB_AMOUNT."');
"
			. "    if (pAmt>0) showField('field_{$form_id}_".self::GF_FIELD_PARTNER_DISCOUNT_AMT."'); else hideField('field_{$form_id}_".self::GF_FIELD_PARTNER_DISCOUNT_AMT."');
"
			. "    if ((document.getElementById('{$total_client_id}')||{}).value) showField('field_{$form_id}_".self::GF_FIELD_TOTAL_CLIENT."');
"
			. "  }
"
			. "  function applyPartner(key){
"
			. "    var p = map[String(key)] || null;
"
			. "    if(!p){
"
			. "      setVal('{$hid_partner_id}', '');
"
			. "      setVal('{$hid_partner_email}', '');
"
			. "      setVal('{$hid_comm_pct}', '');
"
			. "      setVal('{$hid_coupon}', '');
"
			. "      setVal('{$hid_disc_pct}', '');
"
			. "      refreshBreakdown();
"
			. "      return;
"
			. "    }
"
			. "    setVal('{$hid_partner_id}', p.partner_id);
"
			. "    setVal('{$hid_partner_email}', p.partner_email);
"
			. "    setVal('{$hid_comm_pct}', p.commission_pct);
"
			. "    setVal('{$hid_coupon}', p.coupon_code);
"
			. "    setVal('{$hid_disc_pct}', p.discount_pct);
"
			. "    refreshBreakdown();
"
			. "  }
"
			. "  $(document).on('change', '#{$override_id}', function(){ applyPartner($(this).val()); });
"
			. "  $(document).on('change', '#{$eb_pct_id},#{$eb_amt_id},#{$partner_amt_id},#{$total_client_id}', function(){ refreshBreakdown(); });
"
			. "  $(document).on('gform_post_render', function(e, formId){ if(parseInt(formId,10)!=={$form_id}) return; refreshBreakdown(); });
"
			. "  $(function(){ refreshBreakdown(); });
"
			. "})(jQuery);";

		\GFFormDisplay::add_init_script(
			$form_id,
			'tc_bf_partner_override_' . $form_id,
			\GFFormDisplay::ON_PAGE_RENDER,
			$script
		);
	}


	/* =========================================================
	 * Debug logger
	 * ========================================================= */

	private function is_debug() : bool {
		return class_exists('TC_BF\Admin\Settings') && \TC_BF\Admin\Settings::is_debug();
	}

	private function log( string $context, array $data = [], string $level = 'info' ) : void {
		if ( ! $this->is_debug() ) return;
		$row = [
			'time'    => (string) current_time('mysql'),
			'context' => $context,
			'data'    => (string) wp_json_encode($data, JSON_UNESCAPED_SLASHES),
		];
		\TC_BF\Admin\Settings::append_log($row, 50);
		if ( function_exists('wc_get_logger') ) {
			try {
				wc_get_logger()->log($level, $row['context'].' '.$row['data'], ['source' => 'tc-booking-flow']);
			} catch ( \Throwable $e ) {}
		}
	}

	/* =========================================================
	 * Early Booking calculation (single source of truth)
	 * ========================================================= */

	public function get_event_config( int $event_id ) : array {

		// NOTE: This config is per sc_event. Rules are stored as JSON (schema v1) in META_EB_RULES_JSON.
		// We keep backwards compatibility with the earlier "[{days,pct},...]" array format.
		$cfg = [
			'enabled' => false,
			'participation_enabled' => true,
			'rental_enabled'        => true,
			'version' => 1,
			'global_cap' => [ 'enabled' => false, 'amount' => 0.0 ],
			'steps' => [],
		];

		$enabled = get_post_meta($event_id, self::META_EB_ENABLED, true);
		if ( $enabled !== '' ) {
			$val = strtolower(trim((string)$enabled));
			$cfg['enabled'] = in_array($val, ['1','yes','true','on'], true);
		}

		// Legacy cap meta (deprecated): if present we interpret it as a GLOBAL cap amount (in currency).
		$cap = get_post_meta($event_id, self::META_EB_CAP, true);
		if ( $cap !== '' && is_numeric($cap) ) {
			$cfg['global_cap'] = [ 'enabled' => true, 'amount' => (float) $cap ];
		}

		$rules_json = (string) get_post_meta($event_id, self::META_EB_RULES_JSON, true);
		if ( $rules_json !== '' ) {
			$decoded = json_decode($rules_json, true);
			if ( is_array($decoded) ) {
				// Schema v1: object with steps.
				if ( isset($decoded['steps']) && is_array($decoded['steps']) ) {
					$cfg['version'] = isset($decoded['version']) ? (int) $decoded['version'] : 1;
					if ( isset($decoded['global_cap']) && is_array($decoded['global_cap']) ) {
						$cfg['global_cap']['enabled'] = ! empty($decoded['global_cap']['enabled']);
						$cfg['global_cap']['amount']  = isset($decoded['global_cap']['amount']) ? (float) $decoded['global_cap']['amount'] : 0.0;
					}
					$steps = [];
					foreach ( $decoded['steps'] as $s ) {
						if ( ! is_array($s) ) continue;
						$min = isset($s['min_days_before']) ? (int) $s['min_days_before'] : 0;
						$type = isset($s['type']) ? strtolower((string) $s['type']) : 'percent';
						$value = isset($s['value']) ? (float) $s['value'] : 0.0;
						if ( $min < 0 || $value <= 0 ) continue;
						if ( ! in_array($type, ['percent','fixed'], true) ) $type = 'percent';
						$cap_s = [ 'enabled' => false, 'amount' => 0.0 ];
						if ( isset($s['cap']) && is_array($s['cap']) ) {
							$cap_s['enabled'] = ! empty($s['cap']['enabled']);
							$cap_s['amount']  = isset($s['cap']['amount']) ? (float) $s['cap']['amount'] : 0.0;
						}
						$steps[] = [
							'min_days_before' => $min,
							'type' => $type,
							'value' => $value,
							'cap' => $cap_s,
						];
					}
					usort($steps, function($a,$b){ return ((int)$b['min_days_before']) <=> ((int)$a['min_days_before']); });
					$cfg['steps'] = $steps;
				} else {
					// Legacy array: [ {days,pct}, ... ]
					$steps = [];
					foreach ( $decoded as $row ) {
						if ( ! is_array($row) ) continue;
						if ( ! isset($row['days'], $row['pct']) ) continue;
						if ( ! is_numeric($row['days']) || ! is_numeric($row['pct']) ) continue;
						$steps[] = [
							'min_days_before' => (int) $row['days'],
							'type' => 'percent',
							'value' => (float) $row['pct'],
							'cap' => [ 'enabled' => false, 'amount' => 0.0 ],
						];
					}
					usort($steps, function($a,$b){ return ((int)$b['min_days_before']) <=> ((int)$a['min_days_before']); });
					$cfg['steps'] = $steps;
				}
			}
		}

		$p = get_post_meta($event_id, self::META_EB_PARTICIPATION_ENABLED, true);
		if ( $p !== '' ) {
			$val = strtolower(trim((string)$p));
			$cfg['participation_enabled'] = in_array($val, ['1','yes','true','on'], true);
		}

		$r = get_post_meta($event_id, self::META_EB_RENTAL_ENABLED, true);
		if ( $r !== '' ) {
			$val = strtolower(trim((string)$r));
			$cfg['rental_enabled'] = in_array($val, ['1','yes','true','on'], true);
		}

		return $cfg;
	}

	private function select_eb_step( int $days_before, array $steps ) : array {
		if ( ! $steps ) return [];
		// steps already sorted desc by min_days_before
		foreach ( $steps as $s ) {
			$min = (int) ($s['min_days_before'] ?? 0);
			if ( $days_before >= $min ) return (array) $s;
		}
		return [];
	}

	private function compute_eb_amount( float $base_total, array $step, array $global_cap ) : array {
		$base_total = max(0.0, $base_total);
		if ( $base_total <= 0 || ! $step ) return [ 'amount' => 0.0, 'effective_pct' => 0.0 ];
		$type = strtolower((string)($step['type'] ?? 'percent'));
		$value = (float) ($step['value'] ?? 0.0);
		if ( $value <= 0 ) return [ 'amount' => 0.0, 'effective_pct' => 0.0 ];
		$amount = 0.0;
		if ( $type === 'fixed' ) {
			$amount = $value;
		} else {
			$amount = $base_total * ($value / 100);
		}
		// Step cap (amount)
		if ( isset($step['cap']) && is_array($step['cap']) && ! empty($step['cap']['enabled']) ) {
			$cap_amt = (float) ($step['cap']['amount'] ?? 0.0);
			if ( $cap_amt > 0 ) $amount = min($amount, $cap_amt);
		}
		// Global cap (amount)
		if ( isset($global_cap['enabled']) && $global_cap['enabled'] ) {
			$g = (float) ($global_cap['amount'] ?? 0.0);
			if ( $g > 0 ) $amount = min($amount, $g);
		}
		$amount = min($amount, $base_total);
		$effective_pct = $base_total > 0 ? (($amount / $base_total) * 100) : 0.0;
		return [ 'amount' => (float) $amount, 'effective_pct' => (float) $effective_pct ];
	}

	public function calculate_for_event( int $event_id ) : array {

		if ( $event_id <= 0 ) return ['enabled'=>false,'pct'=>0.0,'days_before'=>0,'event_start_ts'=>0,'cfg'=>[]];

		if ( isset($this->calc_cache[$event_id]) ) return $this->calc_cache[$event_id];

		$cfg = $this->get_event_config($event_id);
		if ( empty($cfg['enabled']) ) {
			return $this->calc_cache[$event_id] = [
				'enabled'=>false,'pct'=>0.0,'days_before'=>0,'event_start_ts'=>0,'cfg'=>$cfg
			];
		}

		$start_ts = 0;
		// canonical helper from your system
		if ( function_exists('tc_sc_event_dates') ) {
			$d = tc_sc_event_dates($event_id);
			if ( is_array($d) && ! empty($d['start_ts']) ) $start_ts = (int) $d['start_ts'];
		}
		// fallback
		if ( $start_ts <= 0 ) $start_ts = (int) get_post_meta($event_id, 'sc_event_date_time', true);

		$now_ts = (int) current_time('timestamp'); // site TZ
		$days_before = 0;
		if ( $start_ts > 0 ) {
			$days_before = (int) floor( ($start_ts - $now_ts) / DAY_IN_SECONDS );
			if ( $days_before < 0 ) $days_before = 0;
		}

		$step = $this->select_eb_step($days_before, (array) ($cfg['steps'] ?? []));
		// For GF display we only expose percent steps. Fixed discounts are handled server-side.
		$pct = 0.0;
		if ( $step && strtolower((string)($step['type'] ?? 'percent')) === 'percent' ) {
			$pct = (float) ($step['value'] ?? 0.0);
			if ( $pct < 0 ) $pct = 0.0;
		}

		return $this->calc_cache[$event_id] = [
			'enabled'=>true,
			'pct'=>(float)$pct,
			'days_before'=>$days_before,
			'event_start_ts'=>$start_ts,
			'cfg'=>$cfg,
			'step'=>$step,
		];
	}

	/* =========================================================
	 * GF helpers (dedupe + EB population)
	 * ========================================================= */

	private function gf_entry_mark_cart_added( int $entry_id ) : void {
		if ( function_exists('gform_update_meta') ) {
			gform_update_meta($entry_id, self::GF_META_CART_ADDED, '1');
		}
	}
	private function gf_entry_was_cart_added( int $entry_id ) : bool {
		if ( ! function_exists('gform_get_meta') ) return false;
		return (string) gform_get_meta($entry_id, self::GF_META_CART_ADDED) === '1';
	}

/**
 * Guard against duplicate cart adds when legacy snippets (or refresh/back) already added items
 * but GF entry meta has not yet been marked.
 */
private function cart_contains_entry_id( int $entry_id ) : bool {
	if ( ! function_exists('WC') || ! WC() || ! WC()->cart ) return false;
	$cart = WC()->cart->get_cart();
	if ( ! is_array($cart) ) return false;

	foreach ( $cart as $cart_item ) {
		if ( ! is_array($cart_item) ) continue;
		// Our booking payload lives under 'booking'
		if ( isset($cart_item['booking']) && is_array($cart_item['booking']) ) {
			$bid = isset($cart_item['booking'][ self::BK_ENTRY_ID ]) ? (int) $cart_item['booking'][ self::BK_ENTRY_ID ] : 0;
			if ( $bid === $entry_id ) return true;
		}
		// Back-compat: some flows store entry id on the line item meta directly
		if ( isset($cart_item['_gf_entry_id']) && (int) $cart_item['_gf_entry_id'] === $entry_id ) return true;
	}
	return false;
}


	public function gf_populate_eb_pct( $value ) {

		if ( ! is_singular('sc_event') ) return $value;

		$event_id = (int) get_queried_object_id();
		$calc = $this->calculate_for_event($event_id);

		return (string) wc_format_decimal((float) $calc['pct'], 2);
	}

	public function gf_validation( array $validation_result ) : array {
		$form = isset($validation_result['form']) ? $validation_result['form'] : null;
		$form_id = (int) (is_array($form) && isset($form['id']) ? $form['id'] : 0);
		$target_form_id = \TC_BF\Admin\Settings::get_form_id();
		if ( $form_id !== $target_form_id ) return $validation_result;

		// Basic required field: event_id
		$event_id = isset($_POST['input_' . self::GF_FIELD_EVENT_ID]) ? (int) $_POST['input_' . self::GF_FIELD_EVENT_ID] : 0;
		if ( $event_id <= 0 || get_post_type($event_id) !== 'sc_event' ) {
			$validation_result['is_valid'] = false;
			$validation_result['form'] = $this->gf_mark_field_invalid($form, self::GF_FIELD_EVENT_ID, __('Invalid event. Please reload the event page and try again.', 'tc-booking-flow'));
			return $validation_result;
		}

		// Authoritative base price comes from event meta.
		// IMPORTANT: GF field TOTAL (76) represents the client-facing total, which may include rental.
		$part_price   = $this->money_to_float( get_post_meta($event_id, 'event_price', true) );

// IMPORTANT:
// - Field 76 (GF "Total") can become discounted if you show EB/partner totals in the form.
// - For validation we must compare against the *undiscounted* subtotal.
//   We use field 173 ("S") if present; otherwise we fall back to field 76.
$posted_subtotal = 0.0;
if ( isset($_POST['input_' . self::GF_FIELD_SUBTOTAL]) ) {
	$posted_subtotal = (float) $_POST['input_' . self::GF_FIELD_SUBTOTAL];
} elseif ( isset($_POST['input_' . $subtotal_field_id]) ) {
	$posted_subtotal = (float) $_POST['input_' . self::GF_FIELD_TOTAL];
}

		if ( $part_price > 0 ) {

			// Determine expected rental price (fixed per-event) based on rental type select (106)
			// or, as a fallback, based on which bike choice field has a value.
			$rental_raw  = isset($_POST['input_' . self::GF_FIELD_RENTAL_TYPE]) ? trim((string) $_POST['input_' . self::GF_FIELD_RENTAL_TYPE]) : '';
			$meta_key    = '';
			if ( $rental_raw !== '' ) {
				$rt = strtoupper($rental_raw);
				if ( strpos($rt, 'ROAD') === 0 )        $meta_key = 'rental_price_road';
				elseif ( strpos($rt, 'MTB') === 0 )     $meta_key = 'rental_price_mtb';
				elseif ( strpos($rt, 'EMTB') === 0 )    $meta_key = 'rental_price_ebike';
				elseif ( strpos($rt, 'E-MTB') === 0 )   $meta_key = 'rental_price_ebike';
				elseif ( strpos($rt, 'E MTB') === 0 )   $meta_key = 'rental_price_ebike';
				elseif ( strpos($rt, 'GRAVEL') === 0 )  $meta_key = 'rental_price_gravel';
			}

			// Fallback: detect from selected bike field
			if ( $meta_key === '' ) {
				if ( ! empty($_POST['input_' . self::GF_FIELD_BIKE_130]) )      $meta_key = 'rental_price_road';
				elseif ( ! empty($_POST['input_' . self::GF_FIELD_BIKE_142]) ) $meta_key = 'rental_price_mtb';
				elseif ( ! empty($_POST['input_' . self::GF_FIELD_BIKE_143]) ) $meta_key = 'rental_price_ebike';
				elseif ( ! empty($_POST['input_' . self::GF_FIELD_BIKE_169]) ) $meta_key = 'rental_price_gravel';
			}

			$rental_price = 0.0;
			if ( $meta_key !== '' ) {
				$rental_price = $this->money_to_float( get_post_meta($event_id, $meta_key, true) );
			}

			$expected_total = $part_price + $rental_price;

			$subtotal_field_id = isset($_POST['input_' . self::GF_FIELD_SUBTOTAL]) ? self::GF_FIELD_SUBTOTAL : self::GF_FIELD_TOTAL;

			if ( $posted_subtotal > 0 ) {
				// Drift/tamper check (2 cents tolerance)
				if ( abs($posted_subtotal - $expected_total) > 0.02 ) {
					$validation_result['is_valid'] = false;
					$validation_result['form'] = $this->gf_mark_field_invalid($form, $subtotal_field_id, __('Total: Price mismatch. Please refresh the page and submit again.', 'tc-booking-flow'));
					return $validation_result;
				}
			} else {
				// Self-heal: set the client total server-side
				$_POST['input_' . $subtotal_field_id] = wc_format_decimal($expected_total, 2);
				// Keep legacy field 76 in sync if subtotal field is 173
				if ( $subtotal_field_id === self::GF_FIELD_SUBTOTAL ) {
					$_POST['input_' . self::GF_FIELD_TOTAL] = wc_format_decimal($expected_total, 2);
				}
			}
		}


		// Rental consistency: if any bike choice is present, require product_id + resource_id.
		$bike_raw = '';
		foreach ( [self::GF_FIELD_BIKE_130, self::GF_FIELD_BIKE_142, self::GF_FIELD_BIKE_143, self::GF_FIELD_BIKE_169] as $fid ) {
			$k = 'input_' . $fid;
			if ( ! empty($_POST[$k]) ) { $bike_raw = (string) $_POST[$k]; break; }
		}
		if ( $bike_raw !== '' ) {
			$parts = explode('_', $bike_raw);
			$pid = isset($parts[0]) ? (int) $parts[0] : 0;
			$rid = isset($parts[1]) ? (int) $parts[1] : 0;
			if ( $pid <= 0 || $rid <= 0 ) {
				$validation_result['is_valid'] = false;
				$validation_result['form'] = $this->gf_mark_field_invalid($form, self::GF_FIELD_RENTAL_TYPE, __('Invalid bicycle selection. Please reselect your bicycle and try again.', 'tc-booking-flow'));
				return $validation_result;
			}
		}

		$validation_result['form'] = $form;
		return $validation_result;
	}

	private function gf_mark_field_invalid( $form, int $field_id, string $message ) {
		if ( ! is_array($form) || empty($form['fields']) ) return $form;
		foreach ( $form['fields'] as &$field ) {
			$fid = (int) (is_object($field) ? $field->id : (is_array($field) ? ($field['id'] ?? 0) : 0));
			if ( $fid === $field_id ) {
				if ( is_object($field) ) {
					$field->failed_validation = true;
					$field->validation_message = $message;
				} elseif ( is_array($field) ) {
					$field['failed_validation'] = true;
					$field['validation_message'] = $message;
				}
				break;
			}
		}
		return $form;
	}

	/* =========================================================
	 * GF → Cart
	 * ========================================================= */

	public function gf_after_submission_add_to_cart( $entry, $form ) {

		if ( ! class_exists('WC') || ! function_exists('WC') || ! WC()->cart ) {
			return;
		}

		$entry_id = (int) rgar($entry, 'id');
		if ( $entry_id && $this->gf_entry_is_cart_added($entry_id) ) {
			return;
		}

		$event_id = (int) rgar($entry, (string) self::GF_FIELD_EVENT_ID);
		if ( ! $event_id ) {
			return;
		}

		$event_slug = (string) rgar($entry, (string) self::GF_FIELD_EVENT_UNIQUE_ID);
		if ( $event_slug === '' ) {
			$event_slug = (string) $event_id;
		}

		// Resolve partner context again from submitted entry (admin override + hotels + manual coupon)
		$ctx = $this->gf_resolve_partner_context();

		// Participation product id is resolved by plugin logic (event categories etc)
		$participation_product_id = $this->resolve_participation_product_id($event_id);
		if ( ! $participation_product_id ) {
			return;
		}

		$quantity = 1;
		$tc_group_id = 'tc_' . $event_slug . '_' . wp_generate_password(8, false, false);

		// Participation custom cost (base after discounts) comes from hidden field 153 if present
		$base_after_all = $this->money_to_float( rgar($entry, (string) self::GF_FIELD_BASE_AFTER_ALL_DISCOUNTS) );
		if ( $base_after_all <= 0 ) {
			// fallback to event price
			$base_after_all = $this->money_to_float( get_post_meta($event_id, 'event_price', true) );
		}
		$base_after_all = max(0.0, $base_after_all);

		$meta_part = [
			'tc_group_id' => $tc_group_id,
			'tc_scope'    => 'participation',
			'booking'     => [
				'event_id' => $event_id,
				'event_slug' => $event_slug,
				self::BK_CUSTOM_COST => wc_format_decimal($base_after_all, 2),
			],
			'tc_partner'  => [
				'partner_id' => (int) ($ctx['partner_id'] ?? 0),
				'partner_email' => (string) ($ctx['partner_email'] ?? ''),
				'coupon_code' => (string) ($ctx['coupon_code'] ?? ''),
				'discount_pct' => (float) ($ctx['discount_pct'] ?? 0.0),
				'commission_pct' => (float) ($ctx['commission_pct'] ?? 0.0),
				'source' => (string) ($ctx['source'] ?? ''),
			],
		];

		WC()->cart->add_to_cart($participation_product_id, $quantity, 0, [], $meta_part);

		// Rental? determine from entry
		$has_rental = ! empty( rgar($entry, (string) self::GF_FIELD_HAS_RENTAL) );
		if ( $has_rental ) {
			$product_id_bicycle = (int) rgar($entry, (string) self::GF_FIELD_BIKE_PRODUCT_ID);
			$resource_id_bicycle = (int) rgar($entry, (string) self::GF_FIELD_BIKE_RESOURCE_ID);
			if ( $product_id_bicycle > 0 ) {
				$rental_price = $this->money_to_float( rgar($entry, (string) self::GF_FIELD_RENTAL_PRICE) );
				if ( $rental_price <= 0 ) {
					$bike_type = strtolower(trim((string) rgar($entry, (string) self::GF_FIELD_BIKE_TYPE)));
					if ( $bike_type !== '' ) {
						$key = 'rental_price_' . $bike_type;
						$rental_price = $this->money_to_float( get_post_meta($event_id, $key, true) );
					}
				}
				$rental_price = max(0.0, $rental_price);

				$meta_rental = [
					'tc_group_id' => $tc_group_id,
					'tc_scope'    => 'rental',
					'booking'     => [
						'event_id' => $event_id,
						'event_slug' => $event_slug,
						self::BK_CUSTOM_COST => wc_format_decimal($rental_price, 2),
					],
					'tc_partner'  => $meta_part['tc_partner'],
				];

				WC()->cart->add_to_cart($product_id_bicycle, $quantity, $resource_id_bicycle, [], $meta_rental);
			}
		}

		if ( $entry_id ) {
			$this->gf_entry_mark_cart_added($entry_id);
		}
	}

	public function eb_write_order_ledger( $order_id, $posted_data, $order ) {

		if ( ! $order || ! is_a($order, 'WC_Order') ) {
			$order = wc_get_order($order_id);
			if ( ! $order ) return;
		}

		$subtotal_original = 0.0;
		$eb_amount_total   = 0.0;
		$eb_pct_seen       = null;
		$eb_days_seen      = null;
		$start_event_id    = 0;
		$start_ts          = 0;

		foreach ( $order->get_items() as $item ) {

			$event_id = (int) $item->get_meta('_event_id', true);
			if ( $event_id <= 0 ) continue;

			$start_event_id = $start_event_id ?: $event_id;

			// base snapshot
			$base = $item->get_meta('_eb_base_price', true);
			$qty  = max(1, (int) $item->get_quantity());

			$line_base = ($base !== '') ? ((float)$base * $qty) : (float) $item->get_subtotal();

			$subtotal_original += $line_base;

			$eligible = (int) $item->get_meta('_eb_eligible', true);
			$pct = (float) $item->get_meta('_eb_pct', true);
			$amt = (float) $item->get_meta('_eb_amount', true);

			if ( $eligible && $base !== '' ) {
				if ( $amt > 0 ) {
					$eb_amount_total += min($line_base, $amt * $qty);
				} elseif ( $pct > 0 ) {
					$eb_amount_total += $line_base - ($line_base * (1 - ($pct/100)));
				}
				if ( $eb_pct_seen === null ) $eb_pct_seen = $pct;
				$days = $item->get_meta('_eb_days_before', true);
				if ( $days !== '' && $eb_days_seen === null ) $eb_days_seen = (int) $days;
				$ts = $item->get_meta('_eb_event_start_ts', true);
				if ( $ts !== '' && ! $start_ts ) $start_ts = (int) $ts;
			}
		}

		if ( $subtotal_original <= 0 ) return;

		$partner_discount_pct    = (float) $order->get_meta('partner_discount_pct', true);
		$partner_commission_rate = (float) $order->get_meta('partner_commission_rate', true);
		if ( $partner_discount_pct < 0 ) $partner_discount_pct = 0;
		if ( $partner_commission_rate < 0 ) $partner_commission_rate = 0;

		$partner_base_total = max(0, $subtotal_original - $eb_amount_total);
		$posted_subtotal       = $partner_base_total * (1 - ($partner_discount_pct / 100));
		$partner_commission = $partner_base_total * ($partner_commission_rate / 100);
		$client_discount    = max(0.0, $partner_base_total - $posted_subtotal);

		$order->update_meta_data('subtotal_original', wc_format_decimal($subtotal_original, 2));

		if ( $start_event_id ) $order->update_meta_data('eb_event_id', (string)$start_event_id);
		if ( $start_ts ) $order->update_meta_data('eb_event_start_ts', (string)$start_ts);

		$order->update_meta_data('early_booking_discount_pct', wc_format_decimal((float)($eb_pct_seen ?? 0.0), 2));
		$order->update_meta_data('early_booking_discount_amount', wc_format_decimal($eb_amount_total, 2));

		if ( $eb_days_seen !== null ) {
			$order->update_meta_data('eb_days_before', (string)$eb_days_seen);
		}

		$order->update_meta_data('partner_base_total', wc_format_decimal($partner_base_total, 2));
		$order->update_meta_data('client_total', wc_format_decimal($posted_subtotal, 2));
		$order->update_meta_data('client_discount', wc_format_decimal($client_discount, 2));
		$order->update_meta_data('partner_commission', wc_format_decimal($partner_commission, 2));
		$order->update_meta_data('tc_ledger_version', '2');

		$order->save();
	}

/**
 * Validate participation product is a booking product (and exists).
 */
private function is_valid_participation_product( int $product_id ) : bool {
	if ( $product_id <= 0 ) return false;
	$p = wc_get_product($product_id);
	if ( ! $p ) return false;
	return ( function_exists('is_wc_booking_product') && is_wc_booking_product($p) );
}

/**
 * Find participation product by matching any sc_event_category slug against product meta.
 * Meta key: tc_participation_category_key (set on the product).
 */
private function find_participation_product_by_category_slugs( array $slugs ) : int {

	$slugs = array_values(array_filter(array_map(function($s){
		$s = strtolower((string)$s);
		return preg_replace('/[^a-z0-9_\-]/', '', $s);
	}, $slugs)));

	if ( ! $slugs ) return 0;

	static $cache = [];
	$ck = implode('|', $slugs);
	if ( isset($cache[$ck]) ) return (int) $cache[$ck];

	$q = new \WP_Query([
		'post_type'      => 'product',
		'post_status'    => 'publish',
		'posts_per_page' => 10,
		'fields'         => 'ids',
		'meta_query'     => [
			[
				'key'     => \TC_BF\Admin\Product_Meta::META_KEY,
				'value'   => $slugs,
				'compare' => 'IN',
			],
		],
	]);

	if ( $q->have_posts() ) {
		foreach ( $q->posts as $pid ) {
			$pid = (int) $pid;
			if ( $this->is_valid_participation_product($pid) ) {
				return $cache[$ck] = $pid;
			}
		}
	}

	return $cache[$ck] = 0;
}

}
