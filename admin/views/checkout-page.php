<?php if ( ! defined( 'ABSPATH' ) ) exit; ?>
<?php
$cfg        = mkcp_checkout_config();
$is_premium = mkcp_license_has( 'premium' );
$saved      = isset( $_GET['saved'] );

$icons = [
    'shopping-cart' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><circle cx="9" cy="21" r="1"/><circle cx="20" cy="21" r="1"/><path d="M1 1h4l2.68 13.39a2 2 0 0 0 2 1.61h9.72a2 2 0 0 0 2-1.61L23 6H6"/></svg>',
    'credit-card'   => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect x="1" y="4" width="22" height="16" rx="2"/><line x1="1" y1="10" x2="23" y2="10"/></svg>',
    'image'         => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="18" height="18" rx="2"/><circle cx="8.5" cy="8.5" r="1.5"/><polyline points="21 15 16 10 5 21"/></svg>',
    'layout'        => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="18" height="18" rx="2"/><line x1="3" y1="9" x2="21" y2="9"/><line x1="9" y1="9" x2="9" y2="21"/></svg>',
    'check'         => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>',
    'shield'        => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>',
    'arrow-left'    => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><line x1="19" y1="12" x2="5" y2="12"/><polyline points="12 19 5 12 12 5"/></svg>',
    'plus'          => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>',
    'type'          => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><polyline points="4 7 4 4 20 4 20 7"/><line x1="9" y1="20" x2="15" y2="20"/><line x1="12" y1="4" x2="12" y2="20"/></svg>',
    'minus'         => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="5" y1="12" x2="19" y2="12"/></svg>',
    'check-circle'  => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>',
];

$logo_url = '';
if ( ! empty( $cfg['header_logo_id'] ) ) {
    $logo_url = wp_get_attachment_image_url( $cfg['header_logo_id'], 'medium' ) ?: '';
}
?>

<div id="mkcp-checkout-wrap">

    <a href="<?php echo esc_url( admin_url( 'admin.php?page=mkcp-settings' ) ); ?>" class="mkcp-back-link">
        <?php echo $icons['arrow-left']; ?> Terug naar Cart Popup
    </a>

    <div class="mkcp-page-header">
        <h2><?php echo $icons['credit-card']; ?> Cart Checkout</h2>
        <p>Geef bezoekers een afleidingsvrije afrekenervaring met een aangepaste header en footer op de checkoutpagina.</p>
    </div>

    <?php if ( $saved ) : ?>
    <div class="notice notice-success is-dismissible" style="margin-bottom:20px"><p>Instellingen opgeslagen.</p></div>
    <?php endif; ?>

    <?php if ( ! $is_premium ) : ?>
    <div class="mkcp-glass" style="margin-bottom:20px;border-color:#f59e0b">
        <div class="mkcp-glass-body" style="display:flex;align-items:center;gap:12px">
            <span style="color:#f59e0b;flex-shrink:0"><?php echo $icons['shield']; ?></span>
            <span style="font-size:13px;color:var(--mkcp-ui-text2)">
                <strong>Premium vereist</strong> — Cart Checkout aanpassingen zijn beschikbaar met een premium licentie.
                <a href="<?php echo esc_url( admin_url( 'admin.php?page=mkcp-settings&tab=licentie' ) ); ?>" style="color:var(--mkcp-ui-accent)">Licentie activeren →</a>
            </span>
        </div>
    </div>
    <?php endif; ?>

    <form method="post" action="">
        <?php wp_nonce_field( 'mkcp_save_checkout', 'mkcp_checkout_nonce' ); ?>

        <?php
        $disabled   = ! $is_premium ? 'disabled' : '';
        $opacity    = ! $is_premium ? 'opacity:.5;pointer-events:none' : '';
        $co_enabled = ! empty( $cfg['checkout_enabled'] );
        ?>

        <!-- ── Master toggle ────────────────────────────────────────────────── -->
        <div class="mkcp-glass" style="margin-bottom:20px;<?php echo $co_enabled ? 'border-color:var(--mkcp-ui-accent)' : ''; ?>">
            <div class="mkcp-glass-body" style="display:flex;justify-content:space-between;align-items:center">
                <div>
                    <strong style="font-size:14px;color:var(--mkcp-ui-text)">Cart Checkout inschakelen</strong>
                    <p style="font-size:12px;color:var(--mkcp-ui-text3);margin:4px 0 0">Schakel de checkout-aanpassingen (header, stappenindicator, footer) in of uit — onafhankelijk van de cart popup.</p>
                </div>
                <label class="mkcp-toggle" style="flex-shrink:0;margin-left:20px">
                    <input type="checkbox" name="mkcp_checkout_enabled" value="1"
                        <?php checked( $co_enabled ); ?>
                        <?php echo $disabled; ?>>
                    <span class="mkcp-toggle-track"><span class="mkcp-toggle-thumb"></span></span>
                </label>
            </div>
        </div>

        <!-- ── Checkout Header ──────────────────────────────────────────────── -->

        <div class="mkcp-glass" style="margin-bottom:20px">
            <div class="mkcp-glass-header">
                <div class="mkcp-header-icon"><?php echo $icons['image']; ?></div>
                <h3>Checkout Header</h3>
                <?php if ( ! $is_premium ) : ?>
                <span class="mkcp-premium-badge">Premium</span>
                <?php endif; ?>
            </div>
            <div class="mkcp-glass-body" style="<?php echo $opacity; ?>">

                <!-- Toggle -->
                <div class="mkcp-field-row" style="display:flex;justify-content:space-between;align-items:center;padding-bottom:16px;border-bottom:1px solid var(--mkcp-ui-border)">
                    <div>
                        <strong style="font-size:13px">Aangepaste header inschakelen</strong>
                        <p style="font-size:12px;color:var(--mkcp-ui-text3);margin:3px 0 0">Verbergt de thema-header en toont alleen het logo op de checkoutpagina.</p>
                    </div>
                    <label class="mkcp-toggle">
                        <input type="checkbox" name="mkcp_checkout_header_enabled" value="1"
                            <?php checked( ! empty( $cfg['header_enabled'] ) ); ?>
                            <?php echo $disabled; ?>>
                        <span class="mkcp-toggle-slider"></span>
                    </label>
                </div>

                <!-- Achtergrondkleur -->
                <div style="display:flex;align-items:center;gap:14px;padding:16px 0;border-bottom:1px solid var(--mkcp-ui-border)">
                    <div style="flex:1">
                        <strong style="font-size:13px">Achtergrondkleur</strong>
                        <p style="font-size:12px;color:var(--mkcp-ui-text3);margin:3px 0 0">Kleur van de checkout header balk.</p>
                    </div>
                    <input type="color" name="mkcp_checkout_header_bg"
                        value="<?php echo esc_attr( $cfg['header_bg'] ?: '#ffffff' ); ?>"
                        <?php echo $disabled; ?>
                        style="width:44px;height:36px;border:1px solid var(--mkcp-ui-border);border-radius:6px;cursor:pointer;padding:2px">
                </div>

                <!-- Logo -->
                <div style="padding-top:16px">
                    <strong style="font-size:13px;display:block;margin-bottom:4px">Logo</strong>
                    <p style="font-size:12px;color:var(--mkcp-ui-text3);margin:0 0 14px">Upload een eigen logo, of laat het veld leeg om het site-logo te gebruiken.</p>

                    <input type="hidden" name="mkcp_checkout_header_logo_id" id="mkcp-checkout-logo-id"
                        value="<?php echo esc_attr( $cfg['header_logo_id'] ?: '' ); ?>">

                    <div class="mkcp-logo-upload-wrap">
                        <div class="mkcp-logo-preview-wrap">
                            <?php if ( $logo_url ) : ?>
                                <img id="mkcp-checkout-logo-preview" src="<?php echo esc_url( $logo_url ); ?>" alt="">
                            <?php else : ?>
                                <img id="mkcp-checkout-logo-preview" src="" alt="" style="display:none">
                                <span class="mkcp-logo-preview-placeholder">Geen logo</span>
                            <?php endif; ?>
                        </div>
                        <div style="display:flex;flex-direction:column;gap:8px;justify-content:center">
                            <button type="button" id="mkcp-checkout-logo-upload" class="mkcp-btn mkcp-btn--secondary" <?php echo $disabled; ?>>
                                <?php echo $icons['image']; ?> Logo uploaden
                            </button>
                            <button type="button" id="mkcp-checkout-logo-remove" class="mkcp-btn mkcp-btn--ghost"
                                style="font-size:11px;<?php echo $logo_url ? '' : 'display:none'; ?>"
                                <?php echo $disabled; ?>>
                                Verwijderen
                            </button>
                            <span style="font-size:11px;color:var(--mkcp-ui-text3)">Max. hoogte 60px op frontend</span>
                        </div>
                    </div>
                </div>

            </div>
        </div>


        <!-- ── Checkout Footer ──────────────────────────────────────────────── -->

        <div class="mkcp-glass" style="margin-bottom:20px">
            <div class="mkcp-glass-header">
                <div class="mkcp-header-icon"><?php echo $icons['layout']; ?></div>
                <h3>Checkout Footer</h3>
                <?php if ( ! $is_premium ) : ?>
                <span class="mkcp-premium-badge">Premium</span>
                <?php endif; ?>
            </div>
            <div class="mkcp-glass-body" style="<?php echo $opacity; ?>">

                <!-- Toggle -->
                <div class="mkcp-field-row" style="display:flex;justify-content:space-between;align-items:center;padding-bottom:16px;border-bottom:1px solid var(--mkcp-ui-border);margin-bottom:20px">
                    <div>
                        <strong style="font-size:13px">Aangepaste footer inschakelen</strong>
                        <p style="font-size:12px;color:var(--mkcp-ui-text3);margin:3px 0 0">Verbergt de thema-footer en toont de blokken hieronder op de checkoutpagina.</p>
                    </div>
                    <label class="mkcp-toggle">
                        <input type="checkbox" name="mkcp_checkout_footer_enabled" value="1"
                            <?php checked( ! empty( $cfg['footer_enabled'] ) ); ?>
                            <?php echo $disabled; ?>>
                        <span class="mkcp-toggle-slider"></span>
                    </label>
                </div>

                <!-- Block builder -->
                <input type="hidden" name="mkcp_footer_blocks" id="mkcp-footer-blocks-json"
                    value="<?php echo esc_attr( wp_json_encode(
                        array_values( array_filter( $cfg['footer_blocks'] ?? [], fn($b) => ($b['zone'] ?? '') === 'footer' ) )
                    ) ); ?>">

                <div style="font-size:12px;font-weight:600;color:var(--mkcp-ui-text3);text-transform:uppercase;letter-spacing:.5px;margin-bottom:10px">Footer blokken</div>

                <div id="mkcp-footer-block-list"></div>

                <!-- Lege staat -->
                <div id="mkcp-footer-empty" style="padding:20px;text-align:center;font-size:12px;color:var(--mkcp-ui-text3);border:1px dashed var(--mkcp-ui-border);border-radius:8px;display:none">
                    Nog geen blokken. Voeg blokken toe via de knoppen hieronder.
                </div>

                <div class="mkcp-footer-add-row">
                    <button type="button" class="mkcp-footer-add-block" data-type="text" <?php echo $disabled; ?>>
                        <?php echo $icons['type']; ?> Tekst
                    </button>
                    <button type="button" class="mkcp-footer-add-block" data-type="usp" <?php echo $disabled; ?>>
                        <?php echo $icons['check-circle']; ?> USP
                    </button>
                    <button type="button" class="mkcp-footer-add-block" data-type="divider" <?php echo $disabled; ?>>
                        <?php echo $icons['minus']; ?> Scheidingslijn
                    </button>
                    <button type="button" class="mkcp-footer-add-block" data-type="image" <?php echo $disabled; ?>>
                        <?php echo $icons['image']; ?> Afbeelding
                    </button>
                </div>

                <p style="font-size:11px;color:var(--mkcp-ui-text3);margin:12px 0 0">
                    Sleep blokken om de volgorde aan te passen. De blokken worden naast elkaar weergegeven in de footer.
                </p>

            </div>
        </div>


        <!-- ── Save bar ──────────────────────────────────────────────────────── -->

        <div class="mkcp-save-bar">
            <button type="submit" class="mkcp-btn mkcp-btn--primary" <?php echo $disabled; ?>>
                <?php echo $icons['check']; ?> Opslaan
            </button>
            <?php if ( ! $is_premium ) : ?>
            <span style="font-size:12px;color:var(--mkcp-ui-text3)">Upgrade naar premium om op te slaan.</span>
            <?php endif; ?>
        </div>

    </form>

</div>

<script>
// Toon/verberg leeg-blok boodschap op basis van blokaantal
(function($) {
    function checkEmpty() {
        var count = $('#mkcp-footer-block-list .mkcp-fblock').length;
        $('#mkcp-footer-empty').toggle( count === 0 );
    }
    $(document).on('DOMSubtreeModified', '#mkcp-footer-block-list', checkEmpty);
    $(function() { checkEmpty(); });
})(jQuery);
</script>
