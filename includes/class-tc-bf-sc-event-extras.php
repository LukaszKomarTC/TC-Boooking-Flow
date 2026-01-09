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

        $categories = [];
        foreach ( $cat_array as $cat ) {
            $categories[] = [
                'slug' => (string) $cat->slug,
                'name' => (string) $cat->name,
            ];
        }

        // Default: show only matching modalities tabs in the UI (legacy behavior)
        $js_array = wp_json_encode($categories);

        $event_price = (string) get_post_meta($event_id, 'event_price', true);
        $rental_price_road = (string) get_post_meta($event_id, 'rental_price_road', true);
        $rental_price_mtb  = (string) get_post_meta($event_id, 'rental_price_mtb', true);
        $rental_price_ebike= (string) get_post_meta($event_id, 'rental_price_ebike', true);
        $rental_price_gravel = (string) get_post_meta($event_id, 'rental_price_gravel', true);

        // Populate booking date/time labels for JS (used by inline script)
        $start_ts = (int) get_post_meta($event_id, 'sc_event_date_time', true);
        $end_ts   = (int) get_post_meta($event_id, 'sc_event_end_date_time', true);

        $start_label = $start_ts ? gmdate('d/m/Y H:i', $start_ts + (int) ( get_option('gmt_offset') * HOUR_IN_SECONDS )) : '';
        $end_label   = $end_ts ? gmdate('d/m/Y H:i', $end_ts + (int) ( get_option('gmt_offset') * HOUR_IN_SECONDS )) : '';

        // Attach computed data onto the form array so JS can access it via inline script
        $form['tc_bf_sc_event'] = [
            'event_id'            => $event_id,
            'start_ts'            => $start_ts,
            'end_ts'              => $end_ts,
            'start_label'         => $start_label,
            'end_label'           => $end_label,
            'categories'          => $categories,
            'event_price'         => $event_price,
            'rental_price_road'   => $rental_price_road,
            'rental_price_mtb'    => $rental_price_mtb,
            'rental_price_ebike'  => $rental_price_ebike,
            'rental_price_gravel' => $rental_price_gravel,
        ];

        return $form;
    }

    /**
     * Output inline JS on single sc_event pages to:
     * - set hidden fields
     * - enforce rental section conditional logic driver values
     * - repair disabled image-choice inputs after GF show/hide re-render
     */
    public static function output_sc_event_inline_js() : void {
        if ( ! is_singular('sc_event') ) return;

        $event_id = (int) get_queried_object_id();
        if ( $event_id <= 0 ) return;

        $form_id  = absint( get_option(Settings::OPT_FORM_ID, 44) );
        if ( $form_id <= 0 ) return;

        $start_ts = (int) get_post_meta($event_id, 'sc_event_date_time', true);
        $end_ts   = (int) get_post_meta($event_id, 'sc_event_end_date_time', true);

        $start_label = $start_ts ? gmdate('d/m/Y H:i', $start_ts + (int) ( get_option('gmt_offset') * HOUR_IN_SECONDS )) : '';
        $end_label   = $end_ts ? gmdate('d/m/Y H:i', $end_ts + (int) ( get_option('gmt_offset') * HOUR_IN_SECONDS )) : '';

        $cat_array = get_the_terms($event_id, 'sc_event_category');
        $categories = [];
        if ( ! empty($cat_array) && ! is_wp_error($cat_array) ) {
            foreach ( $cat_array as $cat ) {
                $categories[] = [
                    'slug' => (string) $cat->slug,
                    'name' => (string) $cat->name,
                ];
            }
        }
        $js_array = wp_json_encode($categories);

        $event_price = (string) get_post_meta($event_id, 'event_price', true);
        $rental_price_road = (string) get_post_meta($event_id, 'rental_price_road', true);
        $rental_price_mtb  = (string) get_post_meta($event_id, 'rental_price_mtb', true);
        $rental_price_ebike= (string) get_post_meta($event_id, 'rental_price_ebike', true);
        $rental_price_gravel = (string) get_post_meta($event_id, 'rental_price_gravel', true);

        $default_rental_class = '';
        if ( is_array($categories) ) {
            foreach ( $categories as $c ) {
                if ( empty($c['slug']) ) continue;
                $slug = (string) $c['slug'];
                if ( $slug === 'road' ) { $default_rental_class = 'road'; break; }
                if ( $slug === 'mtb' )  { $default_rental_class = 'mtb'; }
                if ( $slug === 'emtb' ) { $default_rental_class = 'ebike'; }
                if ( $slug === 'gravel' ) { $default_rental_class = 'gravel'; }
            }
        }

        $is_admin = current_user_can('manage_options') ? 1 : 0;

        echo "\n<script id=\"tc-bf-sc-event-inline-js\">\n";
        ?>
        (function($){
            if (typeof $ === 'undefined') return;
            try { console.log('[TC_BF] inline_js running', {event_id: <?php echo (int)$event_id; ?>, form_id: <?php echo (int)$form_id; ?>}); } catch(e) {}
            var fid = <?php echo (int) $form_id; ?>;

            // Admin diagnostics: check DOM presence for key rental UI fields
            if (<?php echo (int) $is_admin; ?>) {
                window.TC_BF_IS_ADMIN = true;
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

            /**
             * Normalize any locale-ish numeric string into a strict dot-decimal string.
             *
             * Why:
             * Gravity Forms re-parses numeric inputs after conditional logic show/hide.
             * If a hidden/calculation input contains a comma-decimal (e.g. "30,00"),
             * GF may treat the comma as a thousands separator under decimal_dot,
             * turning it into 3000. We therefore always write dot-decimals internally.
             */
            function tcBfNormalizeDot(v){
                var n = tcBfToFloat(v);
                // Keep deterministic 2 decimals so GF comparisons are stable.
                return (Math.round(n * 100) / 100).toFixed(2);
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
                // CRITICAL: always write dot-decimal into GF-parsed inputs to avoid 30,00 → 3000,00 after show/hide.
                $inp.val(tcBfNormalizeDot(val)).trigger('change');
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
                }

                // Default selection if none
                if (!$sel.val() && defaultRentalClass) {
                    var $opt = $sel.find('option.'+defaultRentalClass).first();
                    if ($opt.length) $sel.val($opt.val());
                }
                tcBfUpdateBikeFields();

                $sel.on('change', function(){
                    tcBfUpdateBikeFields();
                    tcBfScheduleRepair();
                });
            }

        })(jQuery);
        <?php
        echo "\n</script>\n";
    }

    /**
     * Floating admin diagnostic box on single sc_event pages.
     */
    public static function output_admin_sc_event_diagnostics() : void {
        if ( ! is_singular('sc_event') ) return;
        if ( ! current_user_can('manage_options') ) return;
        ?>
        <div id="tc-bf-diag-box" style="position:fixed;bottom:10px;right:10px;z-index:99999;background:#111;color:#fff;padding:10px 12px;border-radius:6px;max-width:360px;max-height:240px;overflow:auto;font:12px/1.3 monospace;opacity:0.92;">
            <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:6px;">
                <strong>TC_BF diag</strong>
                <a href="#" onclick="this.parentNode.parentNode.style.display='none';return false;" style="color:#fff;text-decoration:none;font-weight:bold;">×</a>
            </div>
            <pre id="tc-bf-diag-pre" style="margin:0;white-space:pre-wrap;"></pre>
        </div>
        <?php
    }

    /**
     * Ensure sc_event_tag taxonomy exists (Sugar Calendar hides the metabox if the taxonomy is missing).
     * This is harmless if Sugar Calendar already registered it.
     */
    public static function ensure_event_tag_taxonomy() : void {
        if ( taxonomy_exists('sc_event_tag') ) return;
        register_taxonomy(
            'sc_event_tag',
            ['sc_event'],
            [
                'label'        => 'Event Tags',
                'rewrite'      => ['slug' => 'sc-event-tag'],
                'hierarchical' => false,
                'show_ui'      => true,
                'show_in_rest' => true,
            ]
        );
    }

    /**
     * Add the sc_event meta box (ported from your snippets).
     */
    public static function add_metabox() : void {
        add_meta_box(
            'tc_bf_sc_event_meta_box',
            'TC — Event options',
            [__CLASS__, 'render_metabox'],
            'sc_event',
            'normal',
            'default'
        );
    }

    /**
     * Render the meta box HTML (event price + rental prices + header options).
     */
    public static function render_metabox( \WP_Post $post ) : void {
        wp_nonce_field( self::NONCE_KEY, self::NONCE_KEY );

        $event_price = get_post_meta( $post->ID, 'event_price', true );
        $rental_price_road = get_post_meta( $post->ID, 'rental_price_road', true );
        $rental_price_mtb  = get_post_meta( $post->ID, 'rental_price_mtb', true );
        $rental_price_ebike= get_post_meta( $post->ID, 'rental_price_ebike', true );
        $rental_price_gravel= get_post_meta( $post->ID, 'rental_price_gravel', true );

        $feat_img    = get_post_meta( $post->ID, 'feat_img', true );
        $inscription = get_post_meta( $post->ID, 'inscription', true );
        $participants= get_post_meta( $post->ID, 'participants', true );

        // Header controls
        $title_mode  = get_post_meta( $post->ID, 'tc_header_title_mode', true ) ?: 'default';
        $title_custom= get_post_meta( $post->ID, 'tc_header_title_custom', true );
        $subtitle    = get_post_meta( $post->ID, 'tc_header_subtitle', true );

        $logo_mode   = get_post_meta( $post->ID, 'tc_header_logo_mode', true ) ?: 'none';
        $logo_id     = (int) get_post_meta( $post->ID, 'tc_header_logo_id', true );
        $logo_url    = get_post_meta( $post->ID, 'tc_header_logo_url', true );

        $show_divider= (int) get_post_meta( $post->ID, 'tc_header_show_divider', true );
        $show_meta   = (int) get_post_meta( $post->ID, 'tc_header_show_shopkeeper_meta', true );

        $details_pos = get_post_meta( $post->ID, 'tc_header_details_position', true ) ?: 'content';

        // NEW: per-event sizing (ints)
        $subtitle_size = (int) get_post_meta( $post->ID, 'tc_header_subtitle_size', true );
        $pad_bottom    = (int) get_post_meta( $post->ID, 'tc_header_padding_bottom', true );
        $details_bottom= (int) get_post_meta( $post->ID, 'tc_header_details_bottom', true );
        $logo_mb       = (int) get_post_meta( $post->ID, 'tc_header_logo_margin_bottom', true );
        $logo_max      = (int) get_post_meta( $post->ID, 'tc_header_logo_max_width', true );
        $title_max     = (int) get_post_meta( $post->ID, 'tc_header_title_max_size', true );

        $back_url      = get_post_meta( $post->ID, 'tc_header_back_link_url', true );
        $back_label    = get_post_meta( $post->ID, 'tc_header_back_link_label', true ) ?: self::tr('[:es]Volver[:en]Back[:]');

        $show_back_link = get_post_meta( $post->ID, 'tc_header_show_back_link', true );
        $show_back_link = ($show_back_link === '' ? '1' : (string) $show_back_link);

        $logo_preview = '';
        if ( $logo_id ) {
            $src = wp_get_attachment_image_url( $logo_id, 'medium' );
            if ( $src ) $logo_preview = '<img src="' . esc_url($src) . '" alt="" style="max-width:180px;height:auto;display:block;" />';
        }

        ?>
        <style>
            .tc-bf-meta-grid{display:grid;grid-template-columns: 1fr 1fr;gap:16px;}
            .tc-bf-meta-grid .tc-bf-col{background:#fff;border:1px solid #e5e5e5;padding:12px;border-radius:6px;}
            .tc-bf-meta-grid label{display:block;font-weight:600;margin:6px 0 4px;}
            .tc-bf-meta-grid input[type=text], .tc-bf-meta-grid input[type=number], .tc-bf-meta-grid select{width:100%;}
            .tc-bf-sub{font-size:12px;color:#666;margin-top:4px;}
            .tc-bf-inline{display:flex;gap:8px;align-items:center;}
            .tc-bf-inline input[type=checkbox]{margin:0;}
            .tc-bf-row{margin-bottom:10px;}
            .tc-bf-hr{margin:12px 0;border:0;border-top:1px solid #eee;}
            .tc-bf-logo-preview{margin-top:8px;}
            .tc-bf-actions{display:flex;gap:8px;flex-wrap:wrap;}
        </style>

        <div class="tc-bf-meta-grid">
            <div class="tc-bf-col">
                <h4>Event pricing</h4>

                <div class="tc-bf-row">
                    <label for="event_price">Event price (€)</label>
                    <input type="text" id="event_price" name="event_price" value="<?php echo esc_attr($event_price); ?>" placeholder="e.g. 20,00" />
                    <div class="tc-bf-sub">Used for participation product pricing.</div>
                </div>

                <hr class="tc-bf-hr" />

                <h4>Rental prices (€)</h4>

                <div class="tc-bf-row">
                    <label for="rental_price_road">Road rental</label>
                    <input type="text" id="rental_price_road" name="rental_price_road" value="<?php echo esc_attr($rental_price_road); ?>" placeholder="e.g. 30,00" />
                </div>

                <div class="tc-bf-row">
                    <label for="rental_price_mtb">MTB rental</label>
                    <input type="text" id="rental_price_mtb" name="rental_price_mtb" value="<?php echo esc_attr($rental_price_mtb); ?>" placeholder="e.g. 30,00" />
                </div>

                <div class="tc-bf-row">
                    <label for="rental_price_ebike">eMTB rental</label>
                    <input type="text" id="rental_price_ebike" name="rental_price_ebike" value="<?php echo esc_attr($rental_price_ebike); ?>" placeholder="e.g. 30,00" />
                </div>

                <div class="tc-bf-row">
                    <label for="rental_price_gravel">Gravel rental</label>
                    <input type="text" id="rental_price_gravel" name="rental_price_gravel" value="<?php echo esc_attr($rental_price_gravel); ?>" placeholder="e.g. 30,00" />
                    <div class="tc-bf-sub">Optional (shown only if your form has a gravel field/input).</div>
                </div>

                <hr class="tc-bf-hr" />

                <h4>Content blocks</h4>

                <div class="tc-bf-row">
                    <label for="feat_img">Show featured header image?</label>
                    <select id="feat_img" name="feat_img">
                        <option value="" <?php selected($feat_img, ''); ?>>Default</option>
                        <option value="Yes" <?php selected($feat_img, 'Yes'); ?>>Yes</option>
                        <option value="No" <?php selected($feat_img, 'No'); ?>>No</option>
                    </select>
                </div>

                <div class="tc-bf-row">
                    <label for="inscription">Append inscription form to content?</label>
                    <select id="inscription" name="inscription">
                        <option value="" <?php selected($inscription, ''); ?>>Default</option>
                        <option value="Yes" <?php selected($inscription, 'Yes'); ?>>Yes</option>
                        <option value="No" <?php selected($inscription, 'No'); ?>>No</option>
                    </select>
                </div>

                <div class="tc-bf-row">
                    <label for="participants">Append participants list to content?</label>
                    <select id="participants" name="participants">
                        <option value="" <?php selected($participants, ''); ?>>Default</option>
                        <option value="Yes" <?php selected($participants, 'Yes'); ?>>Yes</option>
                        <option value="No" <?php selected($participants, 'No'); ?>>No</option>
                    </select>
                </div>
            </div>

            <div class="tc-bf-col">
                <h4>Header layout</h4>

                <div class="tc-bf-row">
                    <label for="tc_header_details_position">Event details position</label>
                    <select id="tc_header_details_position" name="tc_header_details_position">
                        <option value="content" <?php selected($details_pos,'content'); ?>>Keep in content (default)</option>
                        <option value="header"  <?php selected($details_pos,'header'); ?>>Move into header (with-thumb only)</option>
                    </select>
                    <div class="tc-bf-sub">When “header”, the plugin removes the details block from content to avoid duplicates.</div>
                </div>

                <hr class="tc-bf-hr" />

                <h4>Header title</h4>

                <div class="tc-bf-row">
                    <label for="tc_header_title_mode">Title mode</label>
                    <select id="tc_header_title_mode" name="tc_header_title_mode">
                        <option value="default" <?php selected($title_mode,'default'); ?>>Default (post title)</option>
                        <option value="custom"  <?php selected($title_mode,'custom'); ?>>Custom</option>
                        <option value="hide"    <?php selected($title_mode,'hide'); ?>>Hide</option>
                    </select>
                </div>

                <div class="tc-bf-row">
                    <label for="tc_header_title_custom">Custom title</label>
                    <input type="text" id="tc_header_title_custom" name="tc_header_title_custom" value="<?php echo esc_attr($title_custom); ?>" />
                </div>

                <div class="tc-bf-row">
                    <label for="tc_header_subtitle">Subtitle</label>
                    <input type="text" id="tc_header_subtitle" name="tc_header_subtitle" value="<?php echo esc_attr($subtitle); ?>" />
                </div>

                <hr class="tc-bf-hr" />

                <h4>Header logo</h4>

                <div class="tc-bf-row">
                    <label for="tc_header_logo_mode">Logo mode</label>
                    <select id="tc_header_logo_mode" name="tc_header_logo_mode">
                        <option value="none"  <?php selected($logo_mode,'none'); ?>>None</option>
                        <option value="media" <?php selected($logo_mode,'media'); ?>>Media Library</option>
                        <option value="url"   <?php selected($logo_mode,'url'); ?>>URL</option>
                    </select>
                </div>

                <div class="tc-bf-row">
                    <label>Media logo</label>
                    <input type="hidden" id="tc_header_logo_id" name="tc_header_logo_id" value="<?php echo (int) $logo_id; ?>" />
                    <div class="tc-bf-actions">
                        <button class="button" id="tc-bf-logo-pick" type="button">Select logo</button>
                        <button class="button" id="tc-bf-logo-clear" type="button">Clear</button>
                    </div>
                    <div class="tc-bf-logo-preview" id="tc-bf-logo-preview"><?php echo $logo_preview; ?></div>
                </div>

                <div class="tc-bf-row">
                    <label for="tc_header_logo_url">Logo URL</label>
                    <input type="text" id="tc_header_logo_url" name="tc_header_logo_url" value="<?php echo esc_attr($logo_url); ?>" />
                </div>

                <hr class="tc-bf-hr" />

                <h4>Back link (optional)</h4>

                <div class="tc-bf-row">
                    <label class="tc-bf-inline">
                        <input type="checkbox" name="tc_header_show_back_link" value="1" <?php checked($show_back_link,'1'); ?> />
                        Show back link
                    </label>
                </div>

                <div class="tc-bf-row">
                    <label for="tc_header_back_link_url">Back link URL</label>
                    <input type="text" id="tc_header_back_link_url" name="tc_header_back_link_url" value="<?php echo esc_attr($back_url); ?>" placeholder="e.g. /events/" />
                </div>

                <div class="tc-bf-row">
                    <label for="tc_header_back_link_label">Back link label</label>
                    <input type="text" id="tc_header_back_link_label" name="tc_header_back_link_label" value="<?php echo esc_attr($back_label); ?>" />
                </div>

                <hr class="tc-bf-hr" />

                <h4>Header extras</h4>

                <div class="tc-bf-row">
                    <label class="tc-bf-inline">
                        <input type="checkbox" name="tc_header_show_divider" value="1" <?php checked($show_divider, 1); ?> />
                        Show divider line under header
                    </label>
                </div>

                <div class="tc-bf-row">
                    <label class="tc-bf-inline">
                        <input type="checkbox" name="tc_header_show_shopkeeper_meta" value="1" <?php checked($show_meta, 1); ?> />
                        Show Shopkeeper post meta (if used by theme)
                    </label>
                </div>

                <hr class="tc-bf-hr" />

                <h4>Header sizing (per-event)</h4>

                <div class="tc-bf-row">
                    <label for="tc_header_logo_max_width">Logo max width (px)</label>
                    <input type="number" id="tc_header_logo_max_width" name="tc_header_logo_max_width" value="<?php echo (int) $logo_max; ?>" min="0" step="1" />
                </div>

                <div class="tc-bf-row">
                    <label for="tc_header_logo_margin_bottom">Logo margin bottom (px)</label>
                    <input type="number" id="tc_header_logo_margin_bottom" name="tc_header_logo_margin_bottom" value="<?php echo (int) $logo_mb; ?>" min="0" step="1" />
                </div>

                <div class="tc-bf-row">
                    <label for="tc_header_title_max_size">Title max size (px)</label>
                    <input type="number" id="tc_header_title_max_size" name="tc_header_title_max_size" value="<?php echo (int) $title_max; ?>" min="0" step="1" />
                </div>

                <div class="tc-bf-row">
                    <label for="tc_header_subtitle_size">Subtitle size (px)</label>
                    <input type="number" id="tc_header_subtitle_size" name="tc_header_subtitle_size" value="<?php echo (int) $subtitle_size; ?>" min="0" step="1" />
                </div>

                <div class="tc-bf-row">
                    <label for="tc_header_padding_bottom">Header padding bottom (px)</label>
                    <input type="number" id="tc_header_padding_bottom" name="tc_header_padding_bottom" value="<?php echo (int) $pad_bottom; ?>" min="0" step="1" />
                </div>

                <div class="tc-bf-row">
                    <label for="tc_header_details_bottom">Details bottom offset (px)</label>
                    <input type="number" id="tc_header_details_bottom" name="tc_header_details_bottom" value="<?php echo (int) $details_bottom; ?>" min="0" step="1" />
                </div>

                <div class="tc-bf-sub">
                    These values output CSS variables on the front-end (scoped to this event):
                    <code>--tc-logo-max</code>, <code>--tc-title-max</code>, <code>--tc-subtitle-size</code>,
                    <code>--tc-header-padding-bottom</code>, <code>--tc-details-bottom</code>, <code>--tc-logo-mb</code>.
                </div>
            </div>
        </div>
        <?php
    }

    /**
     * Save meta box values.
     */
    public static function save_metabox( int $post_id, \WP_Post $post ) : void {
        if ( defined('DOING_AUTOSAVE') && DOING_AUTOSAVE ) return;
        if ( ! isset($_POST[self::NONCE_KEY]) || ! wp_verify_nonce( $_POST[self::NONCE_KEY], self::NONCE_KEY ) ) return;
        if ( ! current_user_can( 'edit_post', $post_id ) ) return;
        if ( $post->post_type !== 'sc_event' ) return;

        $save_num = function(string $key) use ($post_id) : void {
            if ( ! isset($_POST[$key]) ) return;
            $v = trim((string) $_POST[$key]);
            if ( $v === '' ) { delete_post_meta($post_id, $key); return; }
            // Allow comma decimals; store as-is (frontend normalizes when injecting into GF inputs)
            update_post_meta($post_id, $key, sanitize_text_field($v));
        };

        foreach (['event_price','rental_price_road','rental_price_mtb','rental_price_ebike','rental_price_gravel'] as $k) {
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
                [gravityview id='37950' search_field='145' search_value='"
                . $post_id . "_" . get_post_meta( $post_id, 'sc_event_date_time', true ) . "']
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

    /**
     * Helper translation wrapper for qTranslate style strings.
     */
    private static function tr( string $text ) : string {
        if ( function_exists('qtranxf_useCurrentLanguageIfNotFoundUseDefaultLanguage') ) {
            try {
                return (string) qtranxf_useCurrentLanguageIfNotFoundUseDefaultLanguage( $text );
            } catch ( \Throwable $e ) {}
        }
        return $text;
    }
}
