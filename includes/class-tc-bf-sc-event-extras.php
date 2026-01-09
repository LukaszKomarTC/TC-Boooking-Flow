<?php
namespace TC_BF;

use TC_BF\Admin\Settings;

if ( ! defined('ABSPATH') ) exit;

/**
 * SC Event Extras
 * Moves the key sc_event-related snippets into the plugin:
 * - Event meta box (event_price + rental_price_* + header options)
 * - Append Inscription (GF) + Participants (GravityView) blocks to sc_event content (optional per event)
 *
 * NOTE: If you still have the old Code Snippets versions active, DISABLE them to avoid duplicates.
 */
final class Sc_Event_Extras {

    const NONCE_KEY = 'tc_bf_sc_event_meta_nonce';

    public static function init() : void {
        // Ensure sc_event_tag taxonomy exists (keeps Event Tags box visible)
        add_action('init', [__CLASS__, 'ensure_event_tag_taxonomy'], 5);

        // Admin meta box
        add_action('add_meta_boxes', [__CLASS__, 'add_metabox']);
        add_action('save_post_sc_event', [__CLASS__, 'save_metabox'], 10, 2);
        add_action('admin_enqueue_scripts', [__CLASS__, 'admin_assets']);

        // Frontend content injections
        add_filter('the_content', [__CLASS__, 'filter_sc_event_content'], 11);

        // Per-event header CSS variables (migrated from snippet #163 style attribute)
        // This keeps your template + Additional CSS working even if the header wrapper no longer
        // carries an inline `style="--tc-..."` attribute.
        add_action('wp_head', [__CLASS__, 'output_sc_event_header_css_vars'], 5);

        // GF population + frontend JS (migrated from your "sc_event - add PHP and JS" snippet)
        add_action('wp', [__CLASS__, 'maybe_hook_gf_population']);
        add_action('wp_head', [__CLASS__, 'output_sc_event_inline_js'], 50);

        // Admin-only frontend diagnostics (helps verify injection/population quickly)
        add_action('wp_footer', [__CLASS__, 'output_admin_sc_event_diagnostics'], 5);
    }

    /**
     * Output per-event CSS variables used by your custom single-sc_event header.
     *
     * Legacy (snippet #163) used inline style attribute, e.g.
     *   style="--tc-logo-max:155px;--tc-title-max:70px;--tc-subtitle-max:40px;"
     *
     * After moving that logic into the plugin we keep the *same variables* by emitting
     * a tiny scoped <style> block.
     */
    public static function output_sc_event_header_css_vars() : void {
        if ( ! is_singular('sc_event') ) return;

        $event_id = (int) get_queried_object_id();
        if ( $event_id <= 0 ) return;

        // Read meta (all are optional)
        $logo_max   = trim((string) get_post_meta($event_id, 'tc_header_logo_max_width', true));
        $title_max  = trim((string) get_post_meta($event_id, 'tc_header_title_max_size', true));
        $sub_max    = trim((string) get_post_meta($event_id, 'tc_header_subtitle_size', true));
        $pad_bottom = trim((string) get_post_meta($event_id, 'tc_header_padding_bottom', true));
        $details_bt = trim((string) get_post_meta($event_id, 'tc_header_details_bottom', true));
        $logo_mb    = trim((string) get_post_meta($event_id, 'tc_header_logo_margin_bottom', true));

        $vars = [];
        if ( $logo_max !== '' && is_numeric($logo_max) ) {
            $px = (int) $logo_max;
            if ( $px > 0 ) $vars[] = "--tc-logo-max:{$px}px";
        }
        if ( $title_max !== '' && is_numeric($title_max) ) {
            $px = (int) $title_max;
            if ( $px > 0 ) $vars[] = "--tc-title-max:{$px}px";
        }
        if ( $sub_max !== '' && is_numeric($sub_max) ) {
            $px = (int) $sub_max;
            if ( $px > 0 ) {
                // Keep both names (old/new) to stay compatible with any CSS variant you used.
                $vars[] = "--tc-subtitle-max:{$px}px";
                $vars[] = "--tc-subtitle-size:{$px}px";
            }
        }
        if ( $pad_bottom !== '' && is_numeric($pad_bottom) ) {
            $px = (int) $pad_bottom;
            if ( $px >= 0 ) $vars[] = "--tc-header-padding-bottom:{$px}px";
        }
        if ( $details_bt !== '' && is_numeric($details_bt) ) {
            $px = (int) $details_bt;
            if ( $px > 0 ) $vars[] = "--tc-details-bottom:{$px}px";
        }
        if ( $logo_mb !== '' && is_numeric($logo_mb) ) {
            $px = (int) $logo_mb;
            if ( $px >= 0 ) {
                // Again, keep multiple names to be safe.
                $vars[] = "--tc-logo-margin-bottom:{$px}px";
                $vars[] = "--tc-logo-mb:{$px}px";
            }
        }

        if ( empty($vars) ) return;

        // Scope to this event only.
        $selector = 'body.postid-' . $event_id . ' .single-post-header';
        echo "\n<style id=\"tc-bf-sc-event-header-vars\">{$selector}{" . esc_html(implode(';', $vars)) . ";}</style>\n";
    }

    /**
     * Lightweight logger for this class (uses TC Booking Flow debug mode).
     */
    private static function log( string $context, array $data = [], string $level = 'info' ) : void {
        if ( ! class_exists('TC_BF\\Admin\\Settings') || ! \TC_BF\Admin\Settings::is_debug() ) return;
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

    /**
     * Hook GF population only on single sc_event pages.
     */
    public static function maybe_hook_gf_population() : void {
        if ( ! is_singular('sc_event') ) return;

        $event_id = (int) get_queried_object_id();
        $form_id  = absint( get_option(Settings::OPT_FORM_ID, 44) );
        if ( $form_id <= 0 ) return;

        self::log('frontend.gf_population.attach', [
            'event_id' => $event_id,
            'form_id'  => $form_id,
            'hook'     => 'wp',
        ]);

        add_filter('gform_pre_render', function($form) use ($form_id){
            return self::populate_choices($form, $form_id);
        });
        add_filter('gform_pre_validation', function($form) use ($form_id){
            return self::populate_choices($form, $form_id);
        });
        add_filter('gform_admin_pre_render', function($form) use ($form_id){
            return self::populate_choices($form, $form_id);
        });
        add_filter('gform_pre_submission_filter', function($form) use ($form_id){
            return self::populate_choices($form, $form_id);
        });
    }

    /**
     * Minimal local helper to produce the date range used for availability checks.
     * Uses start day 00:00 and end day +1 exclusive (matches your snippet's intention).
     */
    private static function get_event_date_range( int $event_id ) : array {
        if ( function_exists('tc_sc_event_dates') ) {
            $d = (array) tc_sc_event_dates($event_id);
            if ( ! empty($d['range_start_ts']) && ! empty($d['range_end_exclusive_ts']) ) {
                return [
                    'range_start_ts' => (int) $d['range_start_ts'],
                    'range_end_exclusive_ts' => (int) $d['range_end_exclusive_ts'],
                ];
            }
        }

        $start_ts = (int) get_post_meta($event_id, 'sc_event_date_time', true);
        if ( $start_ts <= 0 ) {
            return [ 'range_start_ts' => 0, 'range_end_exclusive_ts' => 0 ];
        }

        // Use UTC timestamps stored by Sugar Calendar.
        $start_day = (int) strtotime(gmdate('Y-m-d 00:00:00', $start_ts) . ' UTC');
        $end_excl  = $start_day + DAY_IN_SECONDS;
        return [ 'range_start_ts' => $start_day, 'range_end_exclusive_ts' => $end_excl ];
    }

    /**
     * GF population callback (ported from Code Snippets).
     * NOTE: Field IDs are still the current GF44 IDs (Phase 3 will replace these with mapping).
     */
    private static function populate_choices( $form, int $form_id ) {
        if ( empty($form['id']) || (int) $form['id'] !== (int) $form_id ) {
            return $form;
        }

        self::log('frontend.gf_population.run', [
            'form_id'   => (int) $form['id'],
            'source'    => (string) current_filter(),
        ]);

        $event_id = (int) get_queried_object_id();
        if ( $event_id <= 0 ) {
            $event_id = (int) get_the_ID();
        }
        if ( $event_id <= 0 || get_post_type($event_id) !== 'sc_event' ) {
            self::log('frontend.gf_population.skip', [
                'reason'   => 'not_sc_event',
                'event_id' => $event_id,
                'post_type'=> $event_id ? get_post_type($event_id) : '',
            ], 'warning');
            return $form;
        }

        self::log('frontend.sc_event.detected', [
            'event_id' => $event_id,
            'title'    => get_the_title($event_id),
        ]);

        // --- Hotel user list for "inscription_for" field (unchanged logic) ---
        $hotel_users = get_users([
            'role'    => 'hotel',
            'orderby' => 'user_nicename',
            'order'   => 'ASC',
        ]);
        $hotel_users_array = [];
        $current_user = wp_get_current_user();
        $hotel_users_array[] = [
            'text'  => $current_user->display_name . ' (' . __( '[:es]Reserva directa[:en]Direct booking[:]', 'tc-booking-flow' ) . ')',
            'value' => '',
        ];
        foreach ( $hotel_users as $user ) {
            $discount_code = (string) get_user_meta($user->ID, 'discount__code', true);
            $hotel_users_array[] = [
                'text'  => $user->display_name . ' (' . $discount_code . ')',
                'value' => $discount_code,
            ];
        }

        $cat_array = get_the_terms($event_id, 'sc_event_category');
        if ( empty($cat_array) || is_wp_error($cat_array) ) {
            self::log('frontend.gf_population.no_categories', [
                'event_id' => $event_id,
            ], 'warning');
            return $form;
        }

        self::log('frontend.sc_event.categories', [
            'event_id' => $event_id,
            'slugs'    => array_values(array_map(function($t){ return is_object($t) && isset($t->slug) ? (string)$t->slug : ''; }, (array)$cat_array)),
        ]);

        $d = self::get_event_date_range($event_id);
        if ( empty($d['range_start_ts']) || empty($d['range_end_exclusive_ts']) ) {
            self::log('frontend.gf_population.no_date_range', [
                'event_id' => $event_id,
                'range'    => $d,
            ], 'warning');
            return $form;
        }

        $dateTimeStart = new \DateTime('@' . (int) $d['range_start_ts']);
        $dateTimeEnd   = new \DateTime('@' . (int) $d['range_end_exclusive_ts']);

        // Build rental image choices per rental_* category
        foreach ( $cat_array as $cat_term ) {
            $cat_slug = (string) $cat_term->slug;

            // Map SC event category -> product category id
            $category_id = 0;
            if ( $cat_slug === 'rental_road' ) $category_id = 208;
            elseif ( $cat_slug === 'rental_mtb' ) $category_id = 207;
            elseif ( $cat_slug === 'rental_emtb' ) $category_id = 209;
            elseif ( $cat_slug === 'rental_gravel' ) $category_id = 219;
            else continue;

            $q = new \WP_Query([
                'post_type'      => 'product',
                'posts_per_page' => -1,
                'post_status'    => 'publish',
                'tax_query'      => [
                    'relation' => 'AND',
                    [
                        'taxonomy' => 'product_cat',
                        'terms'    => $category_id,
                    ],
                    [
                        'taxonomy' => 'product_type',
                        'terms'    => [ 'booking' ],
                        'field'    => 'slug',
                    ],
                ],
            ]);

            if ( ! $q->have_posts() ) {
                wp_reset_postdata();
                continue;
            }

            $choices = [];
            while ( $q->have_posts() ) {
                $q->the_post();

                $prod_id  = (int) get_the_ID();
                $_product = function_exists('wc_get_product') ? wc_get_product($prod_id) : null;
                if ( ! $_product || ! method_exists($_product,'has_resources') || ! $_product->has_resources() ) {
                    continue;
                }

                foreach ( (array) $_product->get_resources() as $resource ) {
                    if ( ! $resource || empty($resource->ID) ) continue;

                    $start_ts = (int) $dateTimeStart->getTimestamp();
                    $end_ts   = (int) $dateTimeEnd->getTimestamp();

                    // 1) Capacity
                    $available_qty = $resource->has_qty() ? (int) $resource->get_qty() : (int) $_product->get_qty();

                    // 2) Availability rules gate
                    if ( class_exists('WC_Product_Booking_Rule_Manager') ) {
                        $rules_ok = \WC_Product_Booking_Rule_Manager::check_range_availability_rules(
                            $_product,
                            (int) $resource->ID,
                            $start_ts,
                            $end_ts
                        );
                        if ( ! $rules_ok ) $available_qty = 0;
                    }

                    // 3) Subtract booked qty (incl in-cart)
                    if ( $available_qty > 0 && class_exists('WC_Bookings_Controller') ) {
                        $booking_ids = \WC_Bookings_Controller::get_bookings_in_date_range(
                            $start_ts,
                            $end_ts,
                            (int) $resource->ID,
                            true
                        );

                        $booked_qty_resource = 0;
                        if ( is_array($booking_ids) ) {
                            foreach ( $booking_ids as $booking_id ) {
                                $booking = function_exists('get_wc_booking') ? get_wc_booking($booking_id) : null;
                                if ( ! $booking ) continue;

                                $qty = 1;
                                if ( method_exists($booking,'get_persons') ) {
                                    $persons = (array) $booking->get_persons();
                                    $sum = 0; foreach ( $persons as $p ) { $sum += (int) $p; }
                                    if ( $sum > 0 ) $qty = $sum;
                                }
                                if ( method_exists($booking,'get_qty') ) {
                                    $qv = (int) $booking->get_qty();
                                    if ( $qv > 0 ) $qty = $qv;
                                }
                                $booked_qty_resource += max(1, $qty);
                            }
                        }

                        $available_qty = max(0, $available_qty - $booked_qty_resource);
                    }

                    $featured_image = function_exists('wp_get_attachment_url') ? wp_get_attachment_url($_product->get_image_id()) : '';
                    $size_line = "<br><p style='font-size:x-large;font-weight:bolder;margin-bottom:0'>"
                        . __( '[:es]Talla:[:en]Size[:]', 'tc-booking-flow' )
                        . "<span style='display:inline-block;width:6px'></span>"
                        . esc_html( (string) $resource->get_title() )
                        . "</p>";

                    $left_line = '';
                    if ( $available_qty > 0 ) {
                        $left_line = "<p style='margin-top:6px;margin-bottom:0;opacity:.85;font-size:14px;'>"
                            . (int) $available_qty . ' ' . __( '[:es]disponibles[:en]left[:]', 'tc-booking-flow' )
                            . "</p>";
                    }

                    // NOT AVAILABLE
                    if ( $available_qty <= 0 ) {
                        $choices[] = [
                            'value' => 'not_avail_' . (int) $resource->ID,
                            'imageChoices_image' => $featured_image,
                            'imageChoices_imageID' => $_product->get_image_id(),
                            'imageChoices_largeImage' => $featured_image,
                            'text' => get_the_title()
                                . $size_line
                                . "<p style='background-color:red;color:white;margin-bottom:-25px;position:relative;top:-150px;z-index:1;transform:rotate(-45deg)'>"
                                . __( '[:es]NO DISPONIBLE[:en]NOT AVAILABLE[:]', 'tc-booking-flow' )
                                . "</p>",
                        ];
                    } else {
                        // AVAILABLE
                        $choices[] = [
                            'value' => $prod_id . '_' . (int) $resource->ID,
                            'imageChoices_image' => $featured_image,
                            'imageChoices_imageID' => $_product->get_image_id(),
                            'imageChoices_largeImage' => $featured_image,
                            'text' => get_the_title() . $size_line . $left_line,
                        ];
                    }
                }
            }

            self::log('frontend.gf_population.choices_built', [
                'event_id'  => $event_id,
                'cat_slug'  => $cat_slug,
                'choices'   => is_array($choices) ? count($choices) : 0,
            ]);

            // Assign choices to the relevant image choice fields (GF44 legacy field IDs)
            foreach ( $form['fields'] as &$field ) {
                if ( function_exists('gf_image_choices') && gf_image_choices() ) {
                    $has = gf_image_choices()->field_has_image_choices_enabled($field);
                    if ( ! $has ) continue;

                    if ( $cat_slug === 'rental_road' && (int)$field->id === 130 && strpos((string)$field->cssClass, $cat_slug) !== false ) {
                        $field->choices = $choices;
                        self::log('frontend.gf_population.choices_set', ['cat'=>'rental_road','field_id'=>130,'count'=>count($choices)]);
                    }
                    if ( $cat_slug === 'rental_mtb' && (int)$field->id === 142 && strpos((string)$field->cssClass, $cat_slug) !== false ) {
                        $field->choices = $choices;
                        self::log('frontend.gf_population.choices_set', ['cat'=>'rental_mtb','field_id'=>142,'count'=>count($choices)]);
                    }
                    if ( $cat_slug === 'rental_emtb' && (int)$field->id === 143 && strpos((string)$field->cssClass, $cat_slug) !== false ) {
                        $field->choices = $choices;
                        self::log('frontend.gf_population.choices_set', ['cat'=>'rental_emtb','field_id'=>143,'count'=>count($choices)]);
                    }
                    if ( $cat_slug === 'rental_gravel' && (int)$field->id === 169 && strpos((string)$field->cssClass, $cat_slug) !== false ) {
                        $field->choices = $choices;
                        self::log('frontend.gf_population.choices_set', ['cat'=>'rental_gravel','field_id'=>169,'count'=>count($choices)]);
                    }
                }
            }

            wp_reset_postdata();
        }

        // Populate "in the name of" select
        foreach ( $form['fields'] as &$field ) {
            if ( $field->type === 'select' && strpos((string)$field->cssClass, 'inscription_for') !== false ) {
                $field->choices = $hotel_users_array;
            }
        }

        // Dynamic population for event/rental prices (using inputNames)
        add_filter('gform_field_value_event_price', function() use ($event_id){
            return get_post_meta($event_id, 'event_price', true);
        });
        add_filter('gform_field_value_rental_price_road', function() use ($event_id){
            return get_post_meta($event_id, 'rental_price_road', true);
        });
        add_filter('gform_field_value_rental_price_mtb', function() use ($event_id){
            return get_post_meta($event_id, 'rental_price_mtb', true);
        });
        add_filter('gform_field_value_rental_price_ebike', function() use ($event_id){
            return get_post_meta($event_id, 'rental_price_ebike', true);
        });
        add_filter('gform_field_value_rental_price_gravel', function() use ($event_id){
            return get_post_meta($event_id, 'rental_price_gravel', true);
        });

        return $form;
    }

    /**
     * Frontend inline JS for sc_event GF (ported from snippet).
     * Sets hidden date/event fields, hides unavailable modalities, removes rental options with empty prices,
     * disables "not_avail" bike radios.
     */
    public static function output_sc_event_inline_js() : void {
        if ( ! is_singular('sc_event') ) return;

        $event_id = (int) get_queried_object_id();
        if ( $event_id <= 0 ) $event_id = (int) get_the_ID();
        if ( $event_id <= 0 || get_post_type($event_id) !== 'sc_event' ) return;

        $form_id = absint( get_option(Settings::OPT_FORM_ID, 44) );
        if ( $form_id <= 0 ) return;

        self::log('frontend.inline_js.print', [
            'event_id' => $event_id,
            'form_id'  => $form_id,
        ]);

        $rental_price_road   = get_post_meta($event_id, 'rental_price_road', true);
        $rental_price_mtb    = get_post_meta($event_id, 'rental_price_mtb', true);
        $rental_price_ebike  = get_post_meta($event_id, 'rental_price_ebike', true);
        $rental_price_gravel = get_post_meta($event_id, 'rental_price_gravel', true);

        // Base participation price (GF field 50 in your current form uses this value for conditional logic / UI).
        $event_price         = get_post_meta($event_id, 'event_price', true);

        // Determine a sensible default rental type (used to reveal the correct bike field)
        $default_rental_class = '';
        $cat_terms = get_the_terms($event_id, 'sc_event_category');
        $slugs = $cat_terms && ! is_wp_error($cat_terms) ? wp_list_pluck($cat_terms, 'slug') : [];
        // If the event is a specific rental category, prefer that
        if ( in_array('rental_road', $slugs, true) )        $default_rental_class = 'road';
        elseif ( in_array('rental_mtb', $slugs, true) )    $default_rental_class = 'mtb';
        elseif ( in_array('rental_emtb', $slugs, true) )   $default_rental_class = 'ebike';
        elseif ( in_array('rental_gravel', $slugs, true) ) $default_rental_class = 'gravel';

        // Otherwise, if exactly one rental price is configured, auto-select it
        if ( $default_rental_class === '' ) {
            $avail = [];
            if ( (string)$rental_price_road   !== '' ) $avail[] = 'road';
            if ( (string)$rental_price_mtb    !== '' ) $avail[] = 'mtb';
            if ( (string)$rental_price_ebike  !== '' ) $avail[] = 'ebike';
            if ( (string)$rental_price_gravel !== '' ) $avail[] = 'gravel';
            if ( count($avail) === 1 ) $default_rental_class = $avail[0];
        }


        $start_ts = (int) get_post_meta($event_id, 'sc_event_date_time', true);
        $end_ts   = (int) get_post_meta($event_id, 'sc_event_end_date_time', true);
        if ( $start_ts <= 0 ) return;

        $cats = get_the_terms($event_id, 'sc_event_category');
        $js_array = wp_json_encode( $cats ? array_values($cats) : [] );

        $start_label = gmdate('d-m-Y H:i', $start_ts);
        $end_label   = $end_ts ? gmdate('d-m-Y H:i', $end_ts) : '';

        ?>
        <script>
        jQuery(function($){
            try { console.log('[TC_BF] inline_js running', {event_id: <?php echo (int)$event_id; ?>, form_id: <?php echo (int)$form_id; ?>}); } catch(e) {}
            var fid = <?php echo (int) $form_id; ?>;

            // Admin diagnostics: check DOM presence for key rental UI fields
            if (window.TC_BF_IS_ADMIN) {
                try {
                    var diag = {
                        hasForm: ($('#gform_'+fid).length > 0),
                        hasRentalSelect106: ($('#input_'+fid+'_106').length > 0),
                        hasRoadField130: ($('#field_'+fid+'_130').length > 0),
                        hasMtbField142: ($('#field_'+fid+'_142').length > 0),
                        hasEmtbField143: ($('#field_'+fid+'_143').length > 0),
                        hasGravelField169: ($('#field_'+fid+'_169').length > 0),
                        rentalSelectOptions: ($('#input_'+fid+'_106').length ? $('#input_'+fid+'_106 option').length : 0)
                    };
                    console.log('[TC_BF] DOM diag', diag);
                    // Also write into the floating admin diagnostics box if present
                    var $box = document.getElementById('tc-bf-diag-box');
                    if ($box) {
                        var pre = document.getElementById('tc-bf-diag-pre');
                        if (pre) pre.textContent = JSON.stringify(diag, null, 2);
                    }
                } catch(e) {}
            }

            // Set hidden date/event fields (GF44 legacy IDs)
            $("#input_"+fid+"_131").val("<?php echo esc_js($start_label); ?>");
            $("#input_"+fid+"_132").val("<?php echo (int) $start_ts; ?>");
            $("#input_"+fid+"_145").val("<?php echo (int) $event_id; ?>_<?php echo (int) $start_ts; ?>");
            if (<?php echo (int) $end_ts; ?> > 0) {
                $("#input_"+fid+"_133").val("<?php echo esc_js($end_label); ?>");
                $("#input_"+fid+"_134").val("<?php echo (int) $end_ts; ?>");
            }

            /**
             * IMPORTANT (GF conditional logic):
             * In your current form, the whole Rental section (section field id 75)
             * is controlled by conditional logic that depends on hidden/price fields:
             * 50, 55, 56, 170 (and sometimes gravel).
             *
             * If these aren't set at render time, the section stays hidden even if
             * bike fields exist. So we set them here (authoritative from event meta)
             * and trigger GF logic refresh.
             */
            var tcBfMetaPrices = {
                event_price: "<?php echo esc_js((string) $event_price); ?>",
                road:        "<?php echo esc_js((string) $rental_price_road); ?>",
                mtb:         "<?php echo esc_js((string) $rental_price_mtb); ?>",
                ebike:       "<?php echo esc_js((string) $rental_price_ebike); ?>",
                gravel:      "<?php echo esc_js((string) $rental_price_gravel); ?>"
            };

            function tcBfToFloat(v){
                if (v === null || typeof v === 'undefined') return 0;
                v = String(v).replace(/\s+/g,'').replace(/€/g,'').replace(/,/g,'.');
                var n = parseFloat(v);
                return isNaN(n) ? 0 : n;
            }

            // Availability flags used by GF conditional logic (Section 57 shows if ANY of these == 'X')
            // Derived from GF form export (Form 44):
            // - 55 = Road rental flag
            // - 50 = MTB rental flag
            // - 56 = E-bike rental flag
            // - 170 = Gravel rental flag
            var tcBfAvailFlags = {
                55: (tcBfToFloat(tcBfMetaPrices.road)   > 0) ? 'X' : '',
                50: (tcBfToFloat(tcBfMetaPrices.mtb)    > 0) ? 'X' : '',
                56: (tcBfToFloat(tcBfMetaPrices.ebike)  > 0) ? 'X' : '',
                170:(tcBfToFloat(tcBfMetaPrices.gravel) > 0) ? 'X' : ''
            };
            // Apply GF conditional-logic driver flags and re-run rules.
            function tcBfApplyDriverFlags(){
                // Set driver flags (X / empty) that control Section 57 visibility
                $.each(tcBfAvailFlags, function(fieldId, val){
                    var $inp = $("#input_"+fid+"_"+fieldId);
                    if (!$inp.length) return;
                    $inp.val(val).trigger('change');
                });

                // Re-run GF conditional logic / pricing calc if available
                try {
                    if (typeof window.gf_apply_rules === 'function') {
                        window.gf_apply_rules(fid, [], true);
                    }
                    if (typeof window.gformCalculateTotalPrice === 'function') {
                        window.gformCalculateTotalPrice(fid);
                    }
                } catch(e) {}
            }

            /**
             * Rental UI lifecycle repair (Roadmap #1)
             *
             * When the Rental section is hidden, Gravity Forms disables all inputs inside it.
             * With Image Choices, GF may re-render markup after conditional-logic changes,
             * and the disabled state can persist when the section becomes visible again.
             *
             * We *only* repair enabled/disabled state when the section is visible.
             * We still keep any "not_avail_*" options disabled.
             */
            function tcBfRepairRentalBikeChoices(){
                // Section field id that wraps all rental UI (legacy GF44)
                var $section = $('#field_'+fid+'_57');
                if (!$section.length) return;

                // Consider both display and GF visibility classes
                var isVisible = $section.is(':visible') && !$section.hasClass('gfield_visibility_hidden');
                if (!isVisible) return;

                // Bike image-choice fields (legacy GF44)
                var bikeFields = [130, 142, 143, 169];
                for (var i=0; i<bikeFields.length; i++) {
                    var fieldId = bikeFields[i];
                    var $f = $('#field_'+fid+'_'+fieldId);
                    if (!$f.length) continue;
                    $f.find('input[type=radio]').each(function(){
                        var $r = $(this);
                        var v = String($r.val() || '');
                        // Re-enable everything first (GF may have disabled it)
                        $r.prop('disabled', false);
                        // Then keep not-available options disabled
                        if (v.indexOf('not_avail') >= 0) $r.prop('disabled', true);
                    });
                }
            }

            // Debounced scheduler so we can call repair after GF updates the DOM.
            var tcBfRepairTimer = null;
            function tcBfScheduleRepair(){
                if (tcBfRepairTimer) window.clearTimeout(tcBfRepairTimer);
                tcBfRepairTimer = window.setTimeout(function(){
                    try { tcBfRepairRentalBikeChoices(); } catch(e) {}
                }, 50);
            }

            // Run once now (non-AJAX) and also after GF finishes rendering (AJAX-safe)
            tcBfApplyDriverFlags();
            tcBfScheduleRepair();
            $(document).on('gform_post_render', function(e, formId){
                if (parseInt(formId,10) !== fid) return;
                tcBfApplyDriverFlags();
                tcBfScheduleRepair();
            });

            // After GF conditional logic runs (covers section hide/show toggles)
            $(document).on('gform_post_conditional_logic', function(e, formId){
                if (parseInt(formId,10) !== fid) return;
                tcBfScheduleRepair();
            });

            // Also schedule repair after any input changes inside the form
            // (safe fallback when third-party GF add-ons trigger logic without firing events).
            $(document).on('change', '#gform_'+fid+' input, #gform_'+fid+' select', function(){
                tcBfScheduleRepair();
            });
// -------------------------------------------------
            // Price helper fields (used by your Section #75 conditional logic)
            // -------------------------------------------------
            // Your form uses hidden/calculation fields to control visibility of the rental section.
            // These are set server-side (event meta) and then GF conditional logic evaluates them.
            // Field IDs per your current GF44:
            //  - 50  : participation / base event price
            //  - 55  : road rental price
            //  - 56  : mtb rental price
            //  - 170 : eMTB rental price
            // (We also set gravel if a matching input exists.)
            var priceFields = {
                50: "<?php echo esc_js((string)$event_price); ?>",
                55: "<?php echo esc_js((string)$rental_price_road); ?>",
                56: "<?php echo esc_js((string)$rental_price_mtb); ?>",
                170: "<?php echo esc_js((string)$rental_price_ebike); ?>",
                171: "<?php echo esc_js((string)$rental_price_gravel); ?>" // optional
            };
            $.each(priceFields, function(fieldId, val){
                var $inp = $("#input_"+fid+"_"+fieldId);
                if (!$inp.length) return;
                // Do not force empty values; set to 0 to make comparisons deterministic.
                if (val === null || val === undefined || val === '') val = '0';
                $inp.val(val).trigger('change');
            });

            // Ask Gravity Forms to re-evaluate conditional logic after we update helper fields.
            try {
                if (typeof window.gf_apply_rules === 'function') {
                    window.gf_apply_rules(fid, [], true);
                }
                if (typeof window.gformCalculateTotalPrice === 'function') {
                    window.gformCalculateTotalPrice(fid);
                }
            } catch(e) {}

            // Modalities tab show/hide
            try {
                $(".modalidades li.vc_tta-tab, .modalidades .vc_tta-panel").hide();
                var arr = <?php echo $js_array; ?>;
                for (var i=0;i<arr.length;i++) {
                    if (!arr[i] || !arr[i].slug) continue;
                    $("a[href='#"+arr[i].slug+"']").closest("li").show();
                    $("#"+arr[i].slug).show();
                }
            } catch(e) {}

            // Check first modality radio (GF44 id=4)
            $("input:radio[name='input_4']:first").prop('checked', true);

            // Rental select option classing & removal (GF44 id=106)
            var $sel = $("#input_"+fid+"_106");
            if ($sel.length) {
                $sel.find('option').eq(1).addClass('road');
                $sel.find('option').eq(2).addClass('mtb');
                $sel.find('option').eq(3).addClass('ebike');
                $sel.find('option').eq(4).addClass('gravel');

                if (tcBfToFloat(tcBfMetaPrices.road)   <= 0) $sel.find('.road').remove();
                if (tcBfToFloat(tcBfMetaPrices.mtb)    <= 0) $sel.find('.mtb').remove();
                if (tcBfToFloat(tcBfMetaPrices.ebike)  <= 0) $sel.find('.ebike').remove();
                if (tcBfToFloat(tcBfMetaPrices.gravel) <= 0) $sel.find('.gravel').remove();

                // Auto-select rental type and reveal the corresponding bike-choice field
                var defaultRentalClass = "<?php echo esc_js((string)$default_rental_class); ?>";

                function tcBfUpdateBikeFields() {
                    // Field IDs (legacy GF44)
                    var map = {road:130, mtb:142, ebike:143, gravel:169};

                    // Hide all bike-choice fields first
                    $.each(map, function(cls, fieldId){
                        var $f = $('#field_'+fid+'_'+fieldId);
                        if ($f.length) $f.hide();
                    });

                    // Determine selected option class
                    var $opt = $sel.find('option:selected');
                    if (!$opt.length) return;

                    var cls = ($opt.attr('class') || '').split(' ')[0];
                    if (!cls || !map[cls]) return;

                    var $target = $('#field_'+fid+'_'+map[cls]);
                    if ($target.length) $target.show();

                    // Image-choice fields may have been disabled by GF when the section was hidden.
                    // Schedule a post-DOM repair after the show/hide finishes.
                    tcBfScheduleRepair();
                }

                function tcBfSelectByClass(cls) {
                    if (!cls) return false;
                    var $opt = $sel.find('option.'+cls).first();
                    if (!$opt.length) return false;
                    $sel.val($opt.val());
                    // Trigger change so GF conditional logic (if any) also reacts
                    $sel.trigger('change');
                    return true;
                }

                // If nothing selected, choose a sensible default:
                // - category-driven default (rental_emtb etc.)
                // - else if only one option remains after removals, select it
                if (!$sel.val()) {
                    if (!tcBfSelectByClass(defaultRentalClass)) {
                        var $realOpts = $sel.find('option').filter(function(){
                            var v = $(this).val();
                            return v && v !== '0';
                        });
                        if ($realOpts.length === 1) {
                            $sel.val($realOpts.first().val()).trigger('change');
                        }
                    }
                }

                // Update bike fields now + on change
                $sel.on('change', tcBfUpdateBikeFields);
                tcBfUpdateBikeFields();

                // After switching rental type, schedule a repair (Image Choices often re-render)
                tcBfScheduleRepair();

            }

            // Disable not_avail radios
            $("input:radio").each(function(){
                var v = String($(this).val()||'');
                if (v.indexOf('not_avail') >= 0) $(this).prop('disabled', true);
            });

            // Final pass on initial load
            tcBfScheduleRepair();

            // Admin-only quick DOM sanity check
            if (window.TC_BF_IS_ADMIN) {
                try {
                    var dom = {
                        rental_select: !!$("#input_"+fid+"_106").length,
                        img_road: !!$("#field_"+fid+"_130").length,
                        img_mtb: !!$("#field_"+fid+"_142").length,
                        img_emtb: !!$("#field_"+fid+"_143").length,
                        img_gravel: !!$("#field_"+fid+"_169").length,
                        total_field: !!$("#input_"+fid+"_76").length
                    };
                    console.log('[TC_BF] GF DOM check', dom);
                } catch(e) {}
            }
        });
        </script>
        <?php
    }

    /**
     * Admin-only diagnostic footer to confirm injections and field presence.
     */
    public static function output_admin_sc_event_diagnostics() : void {
        if ( ! is_singular('sc_event') ) return;
        if ( ! current_user_can('manage_options') ) return;

        $event_id = (int) get_queried_object_id();
        if ( $event_id <= 0 ) $event_id = (int) get_the_ID();
        if ( $event_id <= 0 || get_post_type($event_id) !== 'sc_event' ) return;

        $form_id = absint( get_option(Settings::OPT_FORM_ID, 44) );
        $inscription = (string) get_post_meta($event_id, 'inscription', true);

        echo "\n<!-- TC_BF_DIAG event_id={$event_id} form_id={$form_id} inscription={$inscription} -->\n";
        echo "<script>window.TC_BF_IS_ADMIN=true;</script>";
        echo '<div id="tc-bf-diag-box" style="position:fixed;bottom:10px;right:10px;z-index:99999;background:#fff;border:2px solid #111;padding:10px;font:12px/1.3 monospace;max-width:420px;box-shadow:0 4px 18px rgba(0,0,0,.2)">';
        echo '<div style="font-weight:700;margin-bottom:6px">TC Booking Flow – Diagnostics</div>';
        echo '<div>event_id: <b>'.(int)$event_id.'</b></div>';
        echo '<div>form_id: <b>'.(int)$form_id.'</b></div>';
        echo '<div>inscription meta: <b>'.esc_html($inscription ?: '(empty)').'</b></div>';
        echo '<div style="opacity:.75;margin-top:6px">DOM check:</div>';
        echo '<pre id="tc-bf-diag-pre" style="white-space:pre-wrap;max-height:220px;overflow:auto;background:#f7f7f7;border:1px solid #ddd;padding:6px;margin:6px 0 0"></pre>';
        echo '<div style="opacity:.75;margin-top:6px">(Also logged to console)</div>';
        echo '</div>';
    }

    /**
     * qTranslate-X friendly helper (no-op if qTranslate not present).
     */
    public static function tr( string $text ) : string {
        if ( function_exists('qtranxf_useCurrentLanguageIfNotFoundUseDefaultLanguage') ) {
            return (string) qtranxf_useCurrentLanguageIfNotFoundUseDefaultLanguage($text);
        }
        return $text;
    }

    /**
     * Ensure sc_event_tag taxonomy exists and is registered for sc_event.
     * This mirrors your legacy snippet and keeps the "Event Tags" meta box visible.
     */
    public static function ensure_event_tag_taxonomy() : void {
        if ( ! post_type_exists('sc_event') ) return;

        add_post_type_support('sc_event', 'custom-fields');

        $labels = [
            'name'          => __( 'Event Tags', 'tc-booking-flow' ),
            'singular_name' => __( 'Event Tag', 'tc-booking-flow' ),
        ];

        if ( ! taxonomy_exists('sc_event_tag') ) {
            register_taxonomy(
                'sc_event_tag',
                ['sc_event'],
                [
                    'labels'            => $labels,
                    'hierarchical'      => true,
                    'public'            => true,
                    'show_ui'           => true,
                    'show_admin_column' => true,
                    'show_in_rest'      => true,
                    'rewrite'           => [ 'slug' => 'sc_event_tags' ],
                ]
            );
        } else {
            register_taxonomy_for_object_type('sc_event_tag', 'sc_event');
        }
    }

    public static function add_metabox() : void {
        // Avoid duplicate meta boxes if legacy Code Snippets meta box is still active
        if ( function_exists('se_cal_add_post_meta_box') || function_exists('sc_event_meta') ) {
            return;
        }

        add_meta_box(
            'tc-bf-sc-event-meta',
            'TC — Event meta fields',
            [__CLASS__, 'render_metabox'],
            'sc_event',
            'normal',
            'high'
        );
    }

    public static function render_metabox( \WP_Post $post ) : void {
        wp_nonce_field(self::NONCE_KEY, self::NONCE_KEY);

        $get = function(string $key, string $default='') use ($post) : string {
            $v = get_post_meta($post->ID, $key, true);
            return is_scalar($v) ? (string) $v : $default;
        };

        $event_price          = $get('event_price');
        $member_price         = $get('member_price');
        $rental_price_road    = $get('rental_price_road');
        $rental_price_mtb     = $get('rental_price_mtb');
        $rental_price_ebike   = $get('rental_price_ebike');
        $rental_price_gravel  = $get('rental_price_gravel');

        $inscription          = $get('inscription', 'No'); // Yes/No
        $participants         = $get('participants', 'No'); // Yes/No

        $feat_img            = $get('feat_img');

        // Header meta (mirrors legacy snippet #163)
        $tc_title_mode        = $get('tc_header_title_mode', 'default');
        $tc_title_custom      = $get('tc_header_title_custom');
        $tc_subtitle          = $get('tc_header_subtitle');
        $tc_show_divider      = $get('tc_header_show_divider', '');

        $tc_logo_mode         = $get('tc_header_logo_mode', 'none');
        $tc_logo_id           = absint( $get('tc_header_logo_id', '0') );
        $tc_logo_url          = $get('tc_header_logo_url');

        $tc_show_back_link    = $get('tc_header_show_back_link', '0');
        $tc_back_link_url     = $get('tc_header_back_link_url', '/eventos_listado');
        $tc_back_link_label   = $get('tc_header_back_link_label', '[:en]<< All events[:es]<< Todos los eventos[:]');
        $tc_details_position  = $get('tc_header_details_position', 'content');
        $tc_show_shopkeeper_meta = $get('tc_header_show_shopkeeper_meta', '');

        // Per-event sizing controls
        $tc_subtitle_size         = absint( $get('tc_header_subtitle_size', '0') );
        $tc_header_padding_bottom = absint( $get('tc_header_padding_bottom', '0') );
        $tc_details_bottom        = absint( $get('tc_header_details_bottom', '0') );
        $tc_logo_margin_bottom    = absint( $get('tc_header_logo_margin_bottom', '0') );
        $tc_logo_max_width        = absint( $get('tc_header_logo_max_width', '0') );
        $tc_title_max_size        = absint( $get('tc_header_title_max_size', '0') );

        ?>
        <style>
            .tc-bf-grid{display:grid;grid-template-columns:1fr 1fr;gap:14px 18px;max-width:980px}
            .tc-bf-grid label{display:block;font-weight:600;margin:0 0 4px}
            .tc-bf-grid input[type="text"], .tc-bf-grid input[type="number"], .tc-bf-grid select{width:100%}
            .tc-bf-section{margin:18px 0 0;padding-top:16px;border-top:1px solid #e6e6e6}
            .tc-bf-h{font-size:13px;font-weight:700;margin:0 0 10px}
            .tc-bf-help{font-size:12px;opacity:.8;margin-top:4px}
            .tc-bf-inline{display:flex;gap:10px;align-items:center;flex-wrap:wrap}
            .tc-bf-logo-preview{display:inline-flex;align-items:center;justify-content:center;width:160px;height:80px;border:1px solid #ddd;background:#fff;overflow:hidden}
            .tc-bf-logo-preview img{max-width:100%;max-height:100%;display:block}
        </style>

        <div class="tc-bf-grid">
            <div>
                <label for="event_price">Participation price (event_price)</label>
                <input id="event_price" name="event_price" type="number" step="0.01" min="0" value="<?php echo esc_attr($event_price); ?>" placeholder="e.g. 20">
            </div>

            <div>
                <label for="member_price">Member price (member_price)</label>
                <input id="member_price" name="member_price" type="number" step="0.01" min="0" value="<?php echo esc_attr($member_price); ?>" placeholder="optional">
                <div class="tc-bf-help">Optional alternate price (legacy field).</div>
            </div>

            <div>
                <label>Inscription block</label>
                <select name="inscription">
                    <option value="No" <?php selected($inscription, 'No'); ?>>No</option>
                    <option value="Yes" <?php selected($inscription, 'Yes'); ?>>Yes</option>
                </select>
                <div class="tc-bf-help">If Yes, the Gravity Form will be appended to the event content.</div>
            </div>

            <div>
                <label for="rental_price_road">Rental price ROAD (rental_price_road)</label>
                <input id="rental_price_road" name="rental_price_road" type="number" step="0.01" min="0" value="<?php echo esc_attr($rental_price_road); ?>" placeholder="e.g. 30">
            </div>

            <div>
                <label>Participants list block</label>
                <select name="participants">
                    <option value="No" <?php selected($participants, 'No'); ?>>No</option>
                    <option value="Yes" <?php selected($participants, 'Yes'); ?>>Yes</option>
                </select>
                <div class="tc-bf-help">If Yes, the GravityView list will be appended to the event content.</div>
            </div>

            <div>
                <label for="rental_price_mtb">Rental price MTB (rental_price_mtb)</label>
                <input id="rental_price_mtb" name="rental_price_mtb" type="number" step="0.01" min="0" value="<?php echo esc_attr($rental_price_mtb); ?>" placeholder="e.g. 30">
            </div>

            <div>
                <label for="rental_price_ebike">Rental price eMTB (rental_price_ebike)</label>
                <input id="rental_price_ebike" name="rental_price_ebike" type="number" step="0.01" min="0" value="<?php echo esc_attr($rental_price_ebike); ?>" placeholder="e.g. 30">
            </div>

            <div>
                <label for="rental_price_gravel">Rental price GRAVEL (rental_price_gravel)</label>
                <input id="rental_price_gravel" name="rental_price_gravel" type="number" step="0.01" min="0" value="<?php echo esc_attr($rental_price_gravel); ?>" placeholder="e.g. 30">
            </div>

            <div>
                <label for="feat_img">Featured image override (feat_img)</label>
                <input id="feat_img" name="feat_img" type="text" value="<?php echo esc_attr($feat_img); ?>" placeholder="optional">
                <div class="tc-bf-help">Legacy helper field used by your event template.</div>
            </div>
        </div>

        <div class="tc-bf-section">
            <p class="tc-bf-h">Header / UI (optional) — migrated from snippet #163</p>

            <div class="tc-bf-grid">
                <div>
                    <label for="tc_header_title_mode">Title mode</label>
                    <select id="tc_header_title_mode" name="tc_header_title_mode">
                        <option value="default" <?php selected($tc_title_mode, 'default'); ?>>Default (post title)</option>
                        <option value="custom" <?php selected($tc_title_mode, 'custom'); ?>>Custom</option>
                        <option value="hide" <?php selected($tc_title_mode, 'hide'); ?>>Hide</option>
                    </select>
                </div>
                <div>
                    <label for="tc_header_title_custom">Custom title (qTranslate supported)</label>
                    <input id="tc_header_title_custom" name="tc_header_title_custom" type="text" value="<?php echo esc_attr($tc_title_custom); ?>" placeholder="[:en]...[:es]...[:]">
                </div>

                <div>
                    <label for="tc_header_subtitle">Subtitle (qTranslate supported)</label>
                    <input id="tc_header_subtitle" name="tc_header_subtitle" type="text" value="<?php echo esc_attr($tc_subtitle); ?>" placeholder="optional">
                </div>
                <div>
                    <label>
                        <input type="checkbox" name="tc_header_show_divider" value="1" <?php checked($tc_show_divider, '1'); ?> />
                        Show divider under title
                    </label>
                    <label style="display:block;margin-top:8px">
                        <input type="checkbox" name="tc_header_show_shopkeeper_meta" value="1" <?php checked($tc_show_shopkeeper_meta, '1'); ?> />
                        Show Shopkeeper post meta if no subtitle is set
                    </label>
                </div>

                <div>
                    <label for="tc_header_logo_mode">Logo mode</label>
                    <select id="tc_header_logo_mode" name="tc_header_logo_mode">
                        <option value="none" <?php selected($tc_logo_mode, 'none'); ?>>No logo</option>
                        <option value="media" <?php selected($tc_logo_mode, 'media'); ?>>Media library</option>
                        <option value="url" <?php selected($tc_logo_mode, 'url'); ?>>URL</option>
                    </select>
                </div>

                <div>
                    <label>Logo (media)</label>
                    <div class="tc-bf-inline">
                        <span class="tc-bf-logo-preview" id="tc-bf-logo-preview">
                            <?php
                            if ( $tc_logo_id ) {
                                $src = wp_get_attachment_image_url($tc_logo_id, 'medium');
                                if ( $src ) echo '<img src="'.esc_url($src).'" alt="" />';
                            }
                            ?>
                        </span>
                        <input type="hidden" id="tc_header_logo_id" name="tc_header_logo_id" value="<?php echo esc_attr((string)$tc_logo_id); ?>">
                        <button type="button" class="button" id="tc-bf-logo-pick">Select</button>
                        <button type="button" class="button" id="tc-bf-logo-clear">Clear</button>
                    </div>
                    <div class="tc-bf-help">Used when Logo mode = Media library.</div>
                </div>

                <div>
                    <label for="tc_header_logo_url">Logo URL</label>
                    <input id="tc_header_logo_url" name="tc_header_logo_url" type="text" value="<?php echo esc_attr($tc_logo_url); ?>" placeholder="https://...">
                    <div class="tc-bf-help">Used when Logo mode = URL.</div>
                </div>
            </div>

            <p>
                <label>
                    <input type="checkbox" name="tc_header_show_back_link" value="1" <?php checked($tc_show_back_link, '1'); ?> />
                    Show “All events” link in header (top-left)
                </label>
            </p>
            <p>
                <label for="tc_header_back_link_url">Back link URL</label>
                <input id="tc_header_back_link_url" name="tc_header_back_link_url" type="text" value="<?php echo esc_attr($tc_back_link_url); ?>" placeholder="/eventos_listado">
            </p>
            <p>
                <label for="tc_header_back_link_label">Back link label (supports qTranslate tags)</label>
                <input id="tc_header_back_link_label" name="tc_header_back_link_label" type="text"
                       value="<?php echo esc_attr($tc_back_link_label); ?>"
                       placeholder="[:en]<< All events[:es]<< Todos los eventos[:]">
            </p>
            <p>
                <label for="tc_header_details_position">Event details block</label>
                <select id="tc_header_details_position" name="tc_header_details_position">
                    <option value="content" <?php selected($tc_details_position, 'content'); ?>>Keep inside content</option>
                    <option value="header" <?php selected($tc_details_position, 'header'); ?>>Move to header bottom</option>
                </select>
            </p>

            <div class="tc-bf-section">
                <p class="tc-bf-h">Per-event sizing (optional)</p>
                <div class="tc-bf-grid">
                    <div>
                        <label for="tc_header_title_max_size">Title max size (px)</label>
                        <input id="tc_header_title_max_size" name="tc_header_title_max_size" type="number" min="28" max="140" step="1" value="<?php echo esc_attr((string)$tc_title_max_size); ?>" placeholder="62" />
                        <div class="tc-bf-help">Desktop cap. Mobile still scales down automatically.</div>
                    </div>
                    <div>
                        <label for="tc_header_subtitle_size">Subtitle max size (px)</label>
                        <input id="tc_header_subtitle_size" name="tc_header_subtitle_size" type="number" min="10" max="60" step="1" value="<?php echo esc_attr((string)$tc_subtitle_size); ?>" placeholder="20" />
                        <div class="tc-bf-help">Desktop cap. Mobile still scales down.</div>
                    </div>
                    <div>
                        <label for="tc_header_padding_bottom">Header padding bottom (px)</label>
                        <input id="tc_header_padding_bottom" name="tc_header_padding_bottom" type="number" min="0" max="200" step="1" value="<?php echo esc_attr((string)$tc_header_padding_bottom); ?>" placeholder="80" />
                        <div class="tc-bf-help">Reserves space so details bar fits on desktop.</div>
                    </div>
                    <div>
                        <label for="tc_header_details_bottom">Details bottom offset (px)</label>
                        <input id="tc_header_details_bottom" name="tc_header_details_bottom" type="number" min="0" max="120" step="1" value="<?php echo esc_attr((string)$tc_details_bottom); ?>" placeholder="18" />
                        <div class="tc-bf-help">Distance from bottom (desktop, when absolute).</div>
                    </div>
                    <div>
                        <label for="tc_header_logo_margin_bottom">Logo margin bottom (px)</label>
                        <input id="tc_header_logo_margin_bottom" name="tc_header_logo_margin_bottom" type="number" min="0" max="80" step="1" value="<?php echo esc_attr((string)$tc_logo_margin_bottom); ?>" placeholder="10" />
                        <div class="tc-bf-help">Space under logo before title.</div>
                    </div>
                    <div>
                        <label for="tc_header_logo_max_width">Logo max width (px)</label>
                        <input id="tc_header_logo_max_width" name="tc_header_logo_max_width" type="number" min="20" max="500" step="1" value="<?php echo esc_attr((string)$tc_logo_max_width); ?>" placeholder="220" />
                        <div class="tc-bf-help">Desktop cap for header logo (leave empty = default).</div>
                    </div>
                </div>
            </div>
        </div>
        <?php
    }

    public static function save_metabox( int $post_id, \WP_Post $post ) : void {
        // Avoid double-saving if legacy Code Snippets save handler is active
        if ( function_exists('ssu_save_post_custom_meta_fields') ) {
            return;
        }
        if ( defined('DOING_AUTOSAVE') && DOING_AUTOSAVE ) return;
        if ( ! current_user_can('edit_post', $post_id) ) return;

        if ( ! isset($_POST[self::NONCE_KEY]) || ! wp_verify_nonce($_POST[self::NONCE_KEY], self::NONCE_KEY) ) return;

        $save_num = function(string $key) use ($post_id) : void {
            if ( ! isset($_POST[$key]) ) return;
            $v = trim((string) $_POST[$key]);
            if ( $v === '' ) { delete_post_meta($post_id, $key); return; }
            update_post_meta($post_id, $key, wc_format_decimal($v, 2));
        };

        foreach (['event_price','member_price','rental_price_road','rental_price_mtb','rental_price_ebike','rental_price_gravel'] as $k) {
            $save_num($k);
        }

        // Simple text fields (legacy)
        foreach (['feat_img','tc_header_title_custom','tc_header_subtitle','tc_header_logo_url','tc_header_back_link_url','tc_header_back_link_label'] as $k) {
            if ( ! isset($_POST[$k]) ) continue;
            $v = trim((string) $_POST[$k]);
            if ( $v === '' ) { delete_post_meta($post_id, $k); continue; }
            update_post_meta($post_id, $k, sanitize_text_field($v));
        }

        $save_yesno = function(string $key) use ($post_id) : void {
            if ( ! isset($_POST[$key]) ) return;
            $v = ($_POST[$key] === 'Yes') ? 'Yes' : 'No';
            update_post_meta($post_id, $key, $v);
        };
        $save_yesno('inscription');
        $save_yesno('participants');

        // Header/UI fields
        if ( isset($_POST['tc_header_title_mode']) ) {
            $v = (string) $_POST['tc_header_title_mode'];
            if ( ! in_array($v, ['default','custom','hide'], true) ) $v = 'default';
            update_post_meta($post_id, 'tc_header_title_mode', $v);
        }

        if ( isset($_POST['tc_header_logo_mode']) ) {
            $v = (string) $_POST['tc_header_logo_mode'];
            if ( ! in_array($v, ['none','media','url'], true) ) $v = 'none';
            update_post_meta($post_id, 'tc_header_logo_mode', $v);
        }

        $logo_id = isset($_POST['tc_header_logo_id']) ? absint($_POST['tc_header_logo_id']) : 0;
        update_post_meta($post_id, 'tc_header_logo_id', $logo_id);

        foreach (['tc_header_show_divider','tc_header_show_shopkeeper_meta'] as $k) {
            $val = isset($_POST[$k]) ? '1' : '';
            update_post_meta($post_id, $k, $val);
        }

        $tc_show_back_link = isset($_POST['tc_header_show_back_link']) ? '1' : '0';
        update_post_meta($post_id, 'tc_header_show_back_link', $tc_show_back_link);

        if ( isset($_POST['tc_header_details_position']) ) {
            $val = (string) $_POST['tc_header_details_position'];
            if ( ! in_array($val, ['content','header'], true) ) $val = 'content';
            update_post_meta($post_id, 'tc_header_details_position', $val);
        }

        // Per-event sizing (ints, allow 0 => default)
        $ints = [
            'tc_header_subtitle_size',
            'tc_header_padding_bottom',
            'tc_header_details_bottom',
            'tc_header_logo_margin_bottom',
            'tc_header_logo_max_width',
            'tc_header_title_max_size',
        ];
        foreach ( $ints as $k ) {
            if ( ! isset($_POST[$k]) ) continue;
            $v = absint($_POST[$k]);
            update_post_meta($post_id, $k, $v);
        }
    }

    public static function admin_assets( string $hook ) : void {
        if ( ! is_admin() ) return;
        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        if ( ! $screen || $screen->post_type !== 'sc_event' ) return;
        if ( $hook !== 'post.php' && $hook !== 'post-new.php' ) return;

        // Media picker for header logo
        wp_enqueue_media();

        $js = <<<'JS'
(function($){
  $(function(){
    var frame;
    var $id = $('#tc_header_logo_id');
    var $preview = $('#tc-bf-logo-preview');

    $('#tc-bf-logo-pick').on('click', function(e){
      e.preventDefault();
      if(frame){ frame.open(); return; }
      frame = wp.media({ title: 'Select header logo', button: { text: 'Use this logo' }, multiple: false });
      frame.on('select', function(){
        var att = frame.state().get('selection').first().toJSON();
        if(!att || !att.id){ return; }
        $id.val(att.id);
        var url = (att.sizes && att.sizes.medium) ? att.sizes.medium.url : att.url;
        $preview.html('<img src="'+url+'" alt="" />');
      });
      frame.open();
    });

    $('#tc-bf-logo-clear').on('click', function(e){
      e.preventDefault();
      $id.val('0');
      $preview.empty();
    });
  });
})(jQuery);
JS;
        wp_add_inline_script('jquery', $js);
    }

    /**
     * Append inscription (GF) and participants (GravityView) blocks to sc_event content.
     * Mirrors your snippet behavior, but uses the configured GF form ID.
     */
    public static function filter_sc_event_content( string $content ) : string {
        if ( ! is_singular('sc_event') ) return $content;

        $post_id = get_the_ID();
        if ( ! $post_id ) return $content;

        $custom_content = $content;

        // Inscription section
        if ( get_post_meta($post_id, 'inscription', true) === 'Yes' ) {
            $start_timestamp = get_post_meta($post_id, 'sc_event_date_time', true);
            if ( ! empty($start_timestamp) ) {

                $form_id = absint( get_option(Settings::OPT_FORM_ID, 44) );

                if ( $start_timestamp < time() ) {
                    $inscription_form = '<div style="padding:14px 16px;background:#f7f7f7;border:1px solid #e5e5e5;">'
                        . esc_html( self::tr('[:es]Formulario de inscripción no disponible. Este evento ya ha tenido lugar.[:en]Inscription form not available. This event has already taken place.[:]') )
                        . '</div>';
                } else {
                    $inscription_form = do_shortcode( '[gravityform id="' . $form_id . '" title="false" description="false" ajax="false"]' );
                }

                $inscription_content = do_shortcode( '
                    [vc_row]
                    [vc_column]
                    [vc_separator css=".vc_custom_1607950580058{margin-top: 30px !important;margin-bottom: 30px !important;}"]
                    [vc_column_text]<h3>' . self::tr("[:en]Inscription form[:es]Formulario de inscripción[:]") . '</h3>[/vc_column_text]
                    ' . $inscription_form . '
                    [/vc_column]
                    [/vc_row]
                ' );

                $custom_content .= $inscription_content;

            } else {
                $custom_content .= esc_html( self::tr('[:es]No se ha encontrado la fecha de inicio del evento.[:en]Event start date not found.[:]') );
            }
        }

        // Participants section (GravityView)
        if ( get_post_meta($post_id, 'participants', true) === 'Yes' ) {
            $participants_content = do_shortcode( "
                [vc_row]
                [vc_column]
                [vc_separator css='.vc_custom_1607950580058{margin-top: 30px !important;margin-bottom: 30px !important;}']
                [vc_column_text]<h3 id='participantes'>" . self::tr("[:en]List of participants[:es]Listado de participantes[:]") . "</h3>[/vc_column_text]
                [gravityview id='37950' search_field='145' search_value='" . $post_id . "_" . get_post_meta( $post_id, 'sc_event_date_time', true ) . "']
                [/vc_column]
                [/vc_row]
            " );

            $custom_content .= $participants_content;
        }
        // If details are moved to header, remove duplicate details from content.
        $custom_content = self::remove_details_from_content_when_in_header( $custom_content, $post_id );



        return $custom_content;
    }

    /**
     * Determine whether the SC Event header is rendered "with-thumb" (background image).
     * Mirrors the logic in single-sc_event.php enough to safely decide if we can move details to header.
     */
    private static function header_has_thumb( int $post_id ) : bool {
        if ( ! function_exists('has_post_thumbnail') ) return false;
        if ( ! has_post_thumbnail( $post_id ) ) return false;

        // Tossa Cycling meta: allow disabling featured header image per-event
        $feat_img = get_post_meta( $post_id, 'feat_img', true );
        if ( $feat_img === 'No' ) return false;

        // Shopkeeper toggle stored on post (same key the template checks)
        $opt = get_post_meta( $post_id, 'post_featured_image_meta_box_check', true );
        if ( $opt && $opt !== 'on' ) return false;

        return true;
    }

    /**
     * If details are set to "header", remove the Sugar Calendar details block from THE CONTENT.
     * This prevents a duplicate details line (one in header + one in content).
     *
     * We remove only the node with id="sc_event_details_{post_id}".
     */
    private static function remove_details_from_content_when_in_header( string $content, int $post_id ) : string {

        $details_position = get_post_meta( $post_id, 'tc_header_details_position', true );
        if ( $details_position !== 'header' ) return $content;

        // Only remove when header can actually display it (with-thumb).
        if ( ! self::header_has_thumb( $post_id ) ) return $content;

        $target_id = 'sc_event_details_' . $post_id;

        if ( strpos( $content, $target_id ) === false ) return $content;

        // DOM removal preferred
        if ( class_exists( '\DOMDocument' ) ) {
            libxml_use_internal_errors( true );
            $dom = new \DOMDocument();

            $html = '<!doctype html><html><head><meta charset="utf-8"></head><body>' . $content . '</body></html>';
            if ( $dom->loadHTML( $html ) ) {
                $node = $dom->getElementById( $target_id );
                if ( $node && $node->parentNode ) {
                    $node->parentNode->removeChild( $node );
                }

                $body = $dom->getElementsByTagName('body')->item(0);
                if ( $body ) {
                    $out = '';
                    foreach ( $body->childNodes as $child ) {
                        $out .= $dom->saveHTML( $child );
                    }
                    return $out;
                }
            }
        }

        // Fallback regex (first match)
        $content = preg_replace(
            '~<div[^>]*\bid="' . preg_quote( $target_id, '~' ) . '"[^>]*>.*?</div>~si',
            '',
            $content,
            1
        );

        return $content;
    }
}
