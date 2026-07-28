/**
 * MK Cart Popup — Checkout Admin JS
 *
 * Handles logo upload and the footer block builder on the Cart Checkout settings page.
 */

jQuery( function ( $ ) {

    // ── Logo upload ───────────────────────────────────────────────────────────

    var $logoId      = $( '#mkcp-checkout-logo-id' );
    var $logoPreview = $( '#mkcp-checkout-logo-preview' );
    var $logoRemove  = $( '#mkcp-checkout-logo-remove' );

    $( '#mkcp-checkout-logo-upload' ).on( 'click', function () {
        var frame = wp.media( {
            title:    'Logo kiezen',
            button:   { text: 'Selecteren' },
            multiple: false,
            library:  { type: 'image' }
        } );
        frame.on( 'select', function () {
            var att = frame.state().get( 'selection' ).first().toJSON();
            $logoId.val( att.id );
            $logoPreview.attr( 'src', att.url ).show();
            $logoRemove.show();
        } );
        frame.open();
    } );

    $logoRemove.on( 'click', function () {
        $logoId.val( '' );
        $logoPreview.hide().attr( 'src', '' );
        $( this ).hide();
    } );


    // ── Afhaallocaties: inklapbare kaarten ────────────────────────────────────

    $( document ).on( 'click', '.mkcp-pu-loc-header', function () {
        var $header = $( this );
        var $body   = $header.next( '.mkcp-pu-loc-body' );
        var isOpen  = $header.attr( 'aria-expanded' ) === 'true';

        $header.attr( 'aria-expanded', isOpen ? 'false' : 'true' );
        $body.prop( 'hidden', isOpen );
    } );

    // Statuspil + adres-preview in de kaartkop live bijwerken, zonder op te
    // hoeven slaan — anders blijft "Uit"/"Actief" hangen op de waarde van de
    // laatste paginalaad, ook nadat de admin de knop al heeft omgezet.
    $( document ).on( 'change', '.mkcp-pu-loc-enable-input', function () {
        var $loc = $( this ).closest( '.mkcp-pu-loc' );
        var on   = $( this ).is( ':checked' );

        $loc.toggleClass( 'is-enabled', on );
        $loc.find( '.mkcp-pu-loc-status' ).first().text( on ? 'Actief' : 'Uit' );
    } );

    $( document ).on( 'input', '.mkcp-pu-loc-address-input', function () {
        var firstLine = String( $( this ).val() ).split( /\r\n|\r|\n/ )[ 0 ].trim();
        $( this ).closest( '.mkcp-pu-loc' )
            .find( '.mkcp-pu-loc-subtitle' ).first()
            .text( firstLine !== '' ? firstLine : 'Nog geen adres ingesteld' );
    } );

    // Tijd-invoer van een gesloten dag visueel dimmen zodra de "open"-toggle
    // omgezet wordt, zonder op te hoeven slaan (mirror van de is-closed-klasse
    // die de PHP-template bij het laden al meegeeft).
    $( document ).on( 'change', '.mkcp-pu-hours-open-input', function () {
        $( this ).closest( '.mkcp-pu-hours-row' ).toggleClass( 'is-closed', ! $( this ).is( ':checked' ) );
    } );


    // ── Footer block builder ──────────────────────────────────────────────────

    var $list = $( '#mkcp-footer-block-list' );
    var $json = $( '#mkcp-footer-blocks-json' );

    if ( ! $list.length ) return;

    // Sortable
    if ( typeof Sortable !== 'undefined' ) {
        Sortable.create( $list[0], {
            handle:    '.mkcp-fblock-drag',
            animation: 150,
            onEnd:     serialize
        } );
    }

    function generateId() {
        return 'blk_' + Math.random().toString( 36 ).substr( 2, 9 );
    }

    function escAttr( s ) {
        return String( s )
            .replace( /&/g, '&amp;' )
            .replace( /"/g, '&quot;' )
            .replace( /</g, '&lt;' )
            .replace( />/g, '&gt;' );
    }

    function escHtml( s ) {
        return String( s )
            .replace( /&/g, '&amp;' )
            .replace( /</g, '&lt;' )
            .replace( />/g, '&gt;' );
    }

    function serialize() {
        var blocks = [];
        $list.find( '.mkcp-fblock' ).each( function () {
            var $b   = $( this );
            var type = $b.data( 'type' );
            var block = {
                id:      $b.data( 'id' ),
                type:    type,
                zone:    'footer',
                enabled: $b.find( '.mkcp-fblock-toggle' ).is( ':checked' )
            };
            switch ( type ) {
                case 'text':
                    block.content = $b.find( '.mkcp-fblock-content' ).val();
                    break;
                case 'divider':
                    block.style = $b.find( '.mkcp-fblock-style' ).val() || 'solid';
                    break;
                case 'usp':
                    block.icon = $b.find( '.mkcp-fblock-icon' ).val() || 'check';
                    block.text = $b.find( '.mkcp-fblock-text' ).val();
                    break;
                case 'image':
                    block.url  = $b.find( '.mkcp-fblock-url'  ).val();
                    block.alt  = $b.find( '.mkcp-fblock-alt'  ).val();
                    block.link = $b.find( '.mkcp-fblock-link' ).val();
                    break;
            }
            blocks.push( block );
        } );
        $json.val( JSON.stringify( blocks ) );
    }

    function makeBlock( block ) {
        var type    = block.type    || 'text';
        var id      = block.id      || generateId();
        var enabled = block.enabled !== false;

        var typeLabels = { text: 'Tekst', divider: 'Scheidingslijn', usp: 'USP', image: 'Afbeelding' };
        var label      = typeLabels[ type ] || type;

        var fields = '';
        switch ( type ) {
            case 'text':
                fields = '<textarea class="mkcp-fblock-content widefat" rows="3" placeholder="Tekst inhoud...">' + escHtml( block.content || '' ) + '</textarea>';
                break;

            case 'divider':
                var styles    = [ 'solid', 'dashed', 'dotted' ];
                var styleOpts = styles.map( function ( s ) {
                    return '<option value="' + s + '"' + ( ( block.style || 'solid' ) === s ? ' selected' : '' ) + '>' + s.charAt(0).toUpperCase() + s.slice(1) + '</option>';
                } ).join( '' );
                fields = '<label style="font-size:12px;color:var(--mkcp-ui-text2)">Lijnstijl&nbsp; <select class="mkcp-fblock-style">' + styleOpts + '</select></label>';
                break;

            case 'usp':
                var icons    = [ 'check', 'shield', 'truck', 'phone', 'star' ];
                var iconOpts = icons.map( function ( ic ) {
                    return '<option value="' + ic + '"' + ( ( block.icon || 'check' ) === ic ? ' selected' : '' ) + '>' + ic + '</option>';
                } ).join( '' );
                fields = '<div style="display:flex;gap:10px;align-items:center">'
                    + '<label style="font-size:12px;color:var(--mkcp-ui-text2);white-space:nowrap">Icoon&nbsp;<select class="mkcp-fblock-icon">' + iconOpts + '</select></label>'
                    + '<input class="mkcp-fblock-text" type="text" style="flex:1" placeholder="USP tekst..." value="' + escAttr( block.text || '' ) + '">'
                    + '</div>';
                break;

            case 'image':
                fields = '<div style="display:flex;flex-direction:column;gap:8px">'
                    + '<div style="display:flex;gap:8px;align-items:center">'
                    +   '<input class="mkcp-fblock-url" type="text" style="flex:1" placeholder="Afbeelding URL..." value="' + escAttr( block.url || '' ) + '">'
                    +   '<button type="button" class="button mkcp-fblock-upload">Uploaden</button>'
                    + '</div>'
                    + '<input class="mkcp-fblock-alt widefat" type="text" placeholder="Alt tekst..." value="' + escAttr( block.alt || '' ) + '">'
                    + '<input class="mkcp-fblock-link widefat" type="text" placeholder="Link URL (optioneel)..." value="' + escAttr( block.link || '' ) + '">'
                    + '</div>';
                break;
        }

        var $item = $(
            '<div class="mkcp-fblock" data-type="' + type + '" data-id="' + id + '">'
                + '<div class="mkcp-fblock-head">'
                    + '<span class="mkcp-fblock-drag" title="Slepen naar positie">⠿</span>'
                    + '<label class="mkcp-fblock-label">'
                        + '<input type="checkbox" class="mkcp-fblock-toggle"' + ( enabled ? ' checked' : '' ) + '>'
                        + ' <strong>' + label + '</strong>'
                    + '</label>'
                    + '<button type="button" class="mkcp-fblock-delete" title="Verwijderen">✕</button>'
                + '</div>'
                + '<div class="mkcp-fblock-body">' + fields + '</div>'
            + '</div>'
        );

        $item.find( 'input, select, textarea' ).on( 'input change', serialize );

        $item.find( '.mkcp-fblock-delete' ).on( 'click', function () {
            $item.remove();
            serialize();
        } );

        $item.find( '.mkcp-fblock-upload' ).on( 'click', function () {
            var $urlInput = $item.find( '.mkcp-fblock-url' );
            var frame = wp.media( {
                title:    'Afbeelding kiezen',
                button:   { text: 'Selecteren' },
                multiple: false,
                library:  { type: 'image' }
            } );
            frame.on( 'select', function () {
                var att = frame.state().get( 'selection' ).first().toJSON();
                $urlInput.val( att.url ).trigger( 'input' );
            } );
            frame.open();
        } );

        return $item;
    }

    // Laad bestaande blokken
    try {
        var existing = JSON.parse( $json.val() || '[]' );
        $.each( existing, function ( i, b ) {
            $list.append( makeBlock( b ) );
        } );
    } catch ( e ) {}

    // Voeg toe knoppen
    $( '.mkcp-footer-add-block' ).on( 'click', function () {
        var $item = makeBlock( { type: $( this ).data( 'type' ), id: generateId(), enabled: true } );
        $list.append( $item );
        serialize();
        $item[0].scrollIntoView( { behavior: 'smooth', block: 'nearest' } );
    } );

    // Serialize bij submit
    $( '#mkcp-form' ).on( 'submit', serialize );

    serialize();


    // ── Checkout content builder ────────────────────────────────────────────────
    // Zelfde bediening als de winkelwagen-popup builder (admin/assets/builder.js):
    // sleep-uit-palet, zone-kaarten met teller, bewerk-modal met dupliceren/aan-uit/
    // verwijderen — bewust zonder de live-preview-simulatie van de popup-builder,
    // die builder-specifieke mock-cart-HTML nabouwt en hier niet van toepassing is.
    initCheckoutBuilder();

    function initCheckoutBuilder() {
        var $json = $( '#mkcp-co-blocks-json' );
        if ( ! $json.length ) return;

        var blocks       = [];
        var editingIndex = -1;
        var pendingZone  = null;

        var ZONE_LABELS = {};
        $( '.mkcp-zone[data-zone]' ).each( function () {
            ZONE_LABELS[ $( this ).data( 'zone' ) ] = $( this ).find( '.mkcp-zone-label' ).text();
        } );
        var ZONE_ORDER = Object.keys( ZONE_LABELS );

        var TYPE_LABELS = { text: 'Tekst', divider: 'Scheidingslijn', usp: 'USP', image: 'Afbeelding', banner: 'Banner', button: 'Knop' };
        var CO_FIELDS   = ( typeof mkcpCheckoutBuilder !== 'undefined' && mkcpCheckoutBuilder.fields ) || {};

        var DRAG = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" width="14" height="14">' +
            '<circle cx="9" cy="7" r="1" fill="currentColor" stroke="none"/><circle cx="15" cy="7" r="1" fill="currentColor" stroke="none"/>' +
            '<circle cx="9" cy="12" r="1" fill="currentColor" stroke="none"/><circle cx="15" cy="12" r="1" fill="currentColor" stroke="none"/>' +
            '<circle cx="9" cy="17" r="1" fill="currentColor" stroke="none"/><circle cx="15" cy="17" r="1" fill="currentColor" stroke="none"/></svg>';
        var EDIT = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" width="13" height="13">' +
            '<path d="M11 4H4a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2v-7"/>' +
            '<path d="M18.5 2.5a2.121 2.121 0 0 1 3 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>';
        var DEL = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" width="13" height="13">' +
            '<polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/>' +
            '<path d="M10 11v6"/><path d="M14 11v6"/><path d="M9 6V4h6v2"/></svg>';
        var TOGGLE_ON  = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" width="13" height="13"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>';
        var TOGGLE_OFF = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" width="13" height="13"><path d="M17.94 17.94A10.07 10.07 0 0 1 12 20c-7 0-11-8-11-8a18.45 18.45 0 0 1 5.06-5.94"/><path d="M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 8 11 8a18.5 18.5 0 0 1-2.16 3.19"/><line x1="1" y1="1" x2="23" y2="23"/></svg>';
        var DUP = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" width="13" height="13"><rect x="9" y="9" width="13" height="13" rx="2"/><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"/></svg>';

        function blockZoneKey( block ) {
            return ( typeof block.zone === 'string' && block.zone.indexOf( 'field:' ) === 0 ) ? 'field' : block.zone;
        }

        function blockPreviewText( block ) {
            if ( block.type === 'text' )    return ( $( '<div>' ).html( block.content || '' ).text() || '' ).slice( 0, 50 ) || '(leeg)';
            if ( block.type === 'divider' ) return '— scheidingslijn —';
            if ( block.type === 'usp' )     return block.text || 'USP tekst';
            if ( block.type === 'image' )   return block.url ? block.url.split( '/' ).pop() : 'afbeelding';
            if ( block.type === 'banner' )  return block.text || 'Banner tekst';
            if ( block.type === 'button' )  return block.text || 'Knoptekst';
            return '';
        }

        function buildBlockEl( block, idx ) {
            var isEnabled = block.enabled !== false;
            var badge     = TYPE_LABELS[ block.type ] || block.type;
            if ( blockZoneKey( block ) === 'field' && block.zone.length > 6 ) {
                badge += ' · ' + ( CO_FIELDS[ block.zone.slice( 6 ) ] || block.zone.slice( 6 ) );
            }
            return $( '<div class="mkcp-block-item' + ( isEnabled ? '' : ' is-disabled' ) + '" />' )
                .attr( 'data-idx', idx )
                .attr( 'data-type', block.type )
                .append(
                    $( '<span class="mkcp-block-handle">' ).html( DRAG ),
                    $( '<span class="mkcp-block-badge">' ).text( badge ),
                    $( '<span class="mkcp-block-preview">' ).text( blockPreviewText( block ) ),
                    $( '<div class="mkcp-block-actions">' ).append(
                        $( '<button type="button" class="mkcp-block-action js-mkcp-co-toggle" title="' + ( isEnabled ? 'Verbergen' : 'Tonen' ) + '">' ).html( isEnabled ? TOGGLE_ON : TOGGLE_OFF ),
                        $( '<button type="button" class="mkcp-block-action js-mkcp-co-dup" title="Dupliceren">' ).html( DUP ),
                        $( '<button type="button" class="mkcp-block-action js-mkcp-co-edit" title="Bewerken">' ).html( EDIT ),
                        $( '<button type="button" class="mkcp-block-action js-mkcp-co-delete" title="Verwijderen">' ).html( DEL )
                    )
                );
        }

        function renderAllZones() {
            ZONE_ORDER.forEach( function ( zone ) {
                var $list = $( '.js-mkcp-co-zone[data-zone="' + zone + '"]' );
                $list.empty();
                blocks.forEach( function ( block, idx ) {
                    if ( blockZoneKey( block ) === zone ) $list.append( buildBlockEl( block, idx ) );
                } );
            } );
            updateZoneCounts();
        }

        function updateZoneCounts() {
            ZONE_ORDER.forEach( function ( zone ) {
                var count = blocks.filter( function ( b ) { return blockZoneKey( b ) === zone; } ).length;
                $( '.mkcp-zone[data-zone="' + zone + '"] .mkcp-zone-count' ).text( count );
            } );
        }

        function serializeToInput() {
            $json.val( JSON.stringify( blocks ) );
        }

        function syncBlocksFromDom() {
            var newBlocks = [];
            ZONE_ORDER.forEach( function ( zone ) {
                $( '.js-mkcp-co-zone[data-zone="' + zone + '"] .mkcp-block-item' ).each( function () {
                    var idx = parseInt( $( this ).attr( 'data-idx' ), 10 );
                    if ( blocks[ idx ] ) newBlocks.push( blocks[ idx ] );
                } );
            } );
            blocks = newBlocks;
            renderAllZones();
            serializeToInput();
        }

        // ── Sortable: herordenen binnen én tussen zones ─────────────────────────
        // De veld-zone ("field") staat in een eigen groep: een blok dat daarin
        // belandt heeft een verplichte veld-keuze nodig die bij een sleep-actie
        // niet vanzelf is in te vullen, dus die zone wisselt niet met de rest.
        function initSortable() {
            $( '.js-mkcp-co-zone' ).each( function () {
                var zone = $( this ).data( 'zone' );
                Sortable.create( this, {
                    group      : zone === 'field' ? 'mkcp-co-field' : 'mkcp-co-structural',
                    handle     : '.mkcp-block-handle',
                    animation  : 150,
                    ghostClass : 'sortable-ghost',
                    chosenClass: 'sortable-chosen',
                    onEnd      : syncBlocksFromDom,
                } );
            } );

            // Palet → zone: native HTML5-drag (geen SortableJS-kloon nodig, er is
            // geen preview-frame hier om schoon te houden).
            $( '#mkcp-co-block-picker' ).on( 'dragstart', '.mkcp-block-add-btn', function ( e ) {
                e.originalEvent.dataTransfer.effectAllowed = 'copy';
                e.originalEvent.dataTransfer.setData( 'text/plain', $( this ).data( 'type' ) );
                $( '.js-mkcp-co-zone' ).addClass( 'mkcp-zone-drop-target' );
            } );
            $( '#mkcp-co-block-picker' ).on( 'dragend dragcancel', '.mkcp-block-add-btn', function () {
                $( '.js-mkcp-co-zone' ).removeClass( 'mkcp-zone-drop-target sortable-over' );
            } );

            $( '.js-mkcp-co-zone' ).each( function () {
                var el   = this;
                var zone = $( el ).data( 'zone' );
                el.addEventListener( 'dragover', function ( e ) {
                    if ( ! e.dataTransfer.types.includes( 'text/plain' ) ) return;
                    e.preventDefault();
                    e.dataTransfer.dropEffect = 'copy';
                    $( el ).addClass( 'sortable-over' );
                } );
                el.addEventListener( 'dragleave', function ( e ) {
                    if ( ! el.contains( e.relatedTarget ) ) $( el ).removeClass( 'sortable-over' );
                } );
                el.addEventListener( 'drop', function ( e ) {
                    var type = e.dataTransfer.getData( 'text/plain' );
                    if ( ! type ) return;
                    e.preventDefault();
                    $( el ).removeClass( 'sortable-over' );
                    $( '.js-mkcp-co-zone' ).removeClass( 'mkcp-zone-drop-target' );
                    pendingZone = zone;
                    openEditor( type, null );
                } );
            } );

            $( '#mkcp-co-block-picker .mkcp-block-add-btn' ).attr( 'draggable', 'true' );
        }

        // ── Bewerk-modal ─────────────────────────────────────────────────────────

        function esc( s ) { return $( '<div>' ).text( s == null ? '' : s ).html(); }
        function sel( current, value ) { return current === value ? ' selected' : ''; }

        function buildZoneSelect( currentZone ) {
            var defaultZone = blockZoneKey( { zone: currentZone } ) || pendingZone || ZONE_ORDER[ 0 ];
            var html = '<select id="mkcp-co-editor-zone">';
            ZONE_ORDER.forEach( function ( z ) {
                html += '<option value="' + z + '"' + sel( defaultZone, z ) + '>' + esc( ZONE_LABELS[ z ] ) + '</option>';
            } );
            return html + '</select>';
        }

        function fieldTargetOptions( selected ) {
            var html = '<option value="">— kies een veld —</option>';
            Object.keys( CO_FIELDS ).forEach( function ( key ) {
                html += '<option value="' + esc( key ) + '"' + sel( key, selected ) + '>' + esc( CO_FIELDS[ key ] ) + '</option>';
            } );
            return html;
        }

        function buildEditorFields( type, block ) {
            var zoneKey    = blockZoneKey( block );
            var fieldValue = zoneKey === 'field' && typeof block.zone === 'string' ? block.zone.slice( 6 ) : '';

            var zoneField = '<div class="mkcp-editor-field"><label>Zone</label>' + buildZoneSelect( block.zone ) + '</div>'
                + '<div class="mkcp-editor-field" id="mkcp-co-editor-target-wrap" style="' + ( zoneKey === 'field' ? '' : 'display:none' ) + '">'
                + '<label>Veld</label><select id="mkcp-co-editor-target">' + fieldTargetOptions( fieldValue ) + '</select></div>';

            if ( type === 'text' ) {
                return zoneField +
                    '<div class="mkcp-editor-field"><label>Inhoud (HTML toegestaan)</label>' +
                    '<textarea id="mkcp-co-editor-text" rows="4">' + esc( block.content || '' ) + '</textarea></div>' +
                    '<div class="mkcp-editor-field mkcp-editor-row">' +
                    '<div><label>Uitlijning</label><select id="mkcp-co-editor-text-align">' +
                    '<option value=""' + sel( block.align, '' ) + '>Standaard</option>' +
                    '<option value="left"' + sel( block.align, 'left' ) + '>Links</option>' +
                    '<option value="center"' + sel( block.align, 'center' ) + '>Gecentreerd</option>' +
                    '<option value="right"' + sel( block.align, 'right' ) + '>Rechts</option>' +
                    '</select></div>' +
                    '<div><label>Kleur</label><input type="color" id="mkcp-co-editor-text-color" value="' + esc( block.color || '#000000' ) + '"></div>' +
                    '</div>';
            }
            if ( type === 'divider' ) {
                return zoneField +
                    '<div class="mkcp-editor-field"><label>Stijl</label><select id="mkcp-co-editor-divider-style">' +
                    '<option value="solid"' + sel( block.style, 'solid' ) + '>Doorgetrokken lijn</option>' +
                    '<option value="dashed"' + sel( block.style, 'dashed' ) + '>Gestippeld</option>' +
                    '<option value="dotted"' + sel( block.style, 'dotted' ) + '>Punten</option>' +
                    '</select></div>';
            }
            if ( type === 'usp' ) {
                return zoneField +
                    '<div class="mkcp-editor-field"><label>Icoon</label><select id="mkcp-co-editor-usp-icon">' +
                    '<option value="check"' + sel( block.icon, 'check' ) + '>Vinkje</option>' +
                    '<option value="star"' + sel( block.icon, 'star' ) + '>Ster</option>' +
                    '<option value="shield"' + sel( block.icon, 'shield' ) + '>Schild</option>' +
                    '<option value="truck"' + sel( block.icon, 'truck' ) + '>Vrachtwagen</option>' +
                    '<option value="phone"' + sel( block.icon, 'phone' ) + '>Telefoon</option>' +
                    '</select></div>' +
                    '<div class="mkcp-editor-field"><label>Tekst</label>' +
                    '<input type="text" id="mkcp-co-editor-usp-text" value="' + esc( block.text || '' ) + '" placeholder="Gratis retour binnen 30 dagen"></div>';
            }
            if ( type === 'image' ) {
                return zoneField +
                    '<div class="mkcp-editor-field"><label>Afbeelding URL</label><div class="mkcp-media-row">' +
                    '<input type="url" id="mkcp-co-editor-image-url" value="' + esc( block.url || '' ) + '" placeholder="https://...">' +
                    '<button type="button" id="mkcp-co-editor-media-btn" class="mkcp-btn mkcp-btn--secondary" style="flex-shrink:0;white-space:nowrap">Kies</button>' +
                    '</div></div>' +
                    '<div class="mkcp-editor-field"><label>Link URL (optioneel)</label>' +
                    '<input type="url" id="mkcp-co-editor-image-link" value="' + esc( block.link || '' ) + '" placeholder="https://..."></div>' +
                    '<div class="mkcp-editor-field"><label>Alt-tekst</label>' +
                    '<input type="text" id="mkcp-co-editor-image-alt" value="' + esc( block.alt || '' ) + '" placeholder="Beschrijving afbeelding"></div>';
            }
            if ( type === 'banner' ) {
                return zoneField +
                    '<div class="mkcp-editor-field"><label>Tekst</label>' +
                    '<input type="text" id="mkcp-co-editor-banner-text" value="' + esc( block.text || '' ) + '" placeholder="Melding tekst"></div>' +
                    '<div class="mkcp-editor-field"><label>Stijl</label><select id="mkcp-co-editor-banner-variant">' +
                    '<option value="info"' + sel( block.variant, 'info' ) + '>Info (blauw)</option>' +
                    '<option value="success"' + sel( block.variant, 'success' ) + '>Succes (groen)</option>' +
                    '<option value="warning"' + sel( block.variant, 'warning' ) + '>Waarschuwing (oranje)</option>' +
                    '<option value="danger"' + sel( block.variant, 'danger' ) + '>Gevaar (rood)</option>' +
                    '</select></div>';
            }
            if ( type === 'button' ) {
                return zoneField +
                    '<div class="mkcp-editor-field"><label>Knoptekst</label>' +
                    '<input type="text" id="mkcp-co-editor-button-text" value="' + esc( block.text || '' ) + '" placeholder="Klik hier"></div>' +
                    '<div class="mkcp-editor-field"><label>Link URL</label>' +
                    '<input type="url" id="mkcp-co-editor-button-url" value="' + esc( block.url || '' ) + '" placeholder="https://..."></div>' +
                    '<div class="mkcp-editor-field mkcp-editor-row">' +
                    '<div><label>Stijl</label><select id="mkcp-co-editor-button-variant">' +
                    '<option value="primary"' + sel( block.variant, 'primary' ) + '>Primair</option>' +
                    '<option value="secondary"' + sel( block.variant, 'secondary' ) + '>Secundair</option>' +
                    '<option value="ghost"' + sel( block.variant, 'ghost' ) + '>Ghost</option>' +
                    '</select></div>' +
                    '<div><label>Uitlijning</label><select id="mkcp-co-editor-button-align">' +
                    '<option value="center"' + sel( block.align, 'center' ) + '>Midden</option>' +
                    '<option value="left"' + sel( block.align, 'left' ) + '>Links</option>' +
                    '<option value="right"' + sel( block.align, 'right' ) + '>Rechts</option>' +
                    '</select></div></div>';
            }
            return zoneField;
        }

        function openEditor( type, idx ) {
            editingIndex = ( idx === null ) ? -1 : idx;
            var block = ( idx !== null ) ? $.extend( {}, blocks[ idx ] ) : { type: type, zone: pendingZone || ZONE_ORDER[ 0 ] };
            pendingZone = null;

            $( '#mkcp-co-editor-title' ).text( ( idx === null ? 'Nieuw' : 'Bewerk' ) + ' ' + ( TYPE_LABELS[ type ] || type ) + ' blok' );
            $( '#mkcp-co-block-editor' ).attr( 'data-type', type );
            $( '#mkcp-co-editor-body' ).html( buildEditorFields( type, block ) );

            $( '#mkcp-co-editor-zone' ).on( 'change', function () {
                $( '#mkcp-co-editor-target-wrap' ).toggle( $( this ).val() === 'field' );
            } );

            if ( type === 'image' ) {
                $( '#mkcp-co-editor-body' ).on( 'click', '#mkcp-co-editor-media-btn', function () {
                    var frame = wp.media( { title: 'Afbeelding kiezen', button: { text: 'Selecteren' }, multiple: false, library: { type: 'image' } } );
                    frame.on( 'select', function () {
                        var att = frame.state().get( 'selection' ).first().toJSON();
                        $( '#mkcp-co-editor-image-url' ).val( att.url );
                    } );
                    frame.open();
                } );
            }

            $( '#mkcp-co-block-editor' ).show();
            $( '#mkcp-co-block-editor' ).find( 'input, textarea, select' ).first().focus();
        }

        function closeEditor() {
            $( '#mkcp-co-block-editor' ).hide().empty().end().find( '#mkcp-co-editor-body' ).off( 'click' );
        }

        function saveEditor() {
            var type    = $( '#mkcp-co-block-editor' ).attr( 'data-type' );
            var zoneSel = $( '#mkcp-co-editor-zone' ).val() || ZONE_ORDER[ 0 ];
            var zone    = zoneSel;

            if ( zoneSel === 'field' ) {
                var target = $( '#mkcp-co-editor-target' ).val();
                if ( ! target ) { $( '#mkcp-co-editor-target' ).focus(); return; }
                zone = 'field:' + target;
            }

            var block;
            if ( type === 'text' )         block = { type: 'text',    zone: zone, content: $( '#mkcp-co-editor-text' ).val(), align: $( '#mkcp-co-editor-text-align' ).val(), color: $( '#mkcp-co-editor-text-color' ).val() };
            else if ( type === 'divider' ) block = { type: 'divider', zone: zone, style: $( '#mkcp-co-editor-divider-style' ).val() };
            else if ( type === 'usp' )     block = { type: 'usp',     zone: zone, icon: $( '#mkcp-co-editor-usp-icon' ).val(), text: $( '#mkcp-co-editor-usp-text' ).val() };
            else if ( type === 'image' )   block = { type: 'image',   zone: zone, url: $( '#mkcp-co-editor-image-url' ).val(), link: $( '#mkcp-co-editor-image-link' ).val(), alt: $( '#mkcp-co-editor-image-alt' ).val() };
            else if ( type === 'banner' )  block = { type: 'banner',  zone: zone, text: $( '#mkcp-co-editor-banner-text' ).val(), variant: $( '#mkcp-co-editor-banner-variant' ).val() };
            else if ( type === 'button' )  block = { type: 'button',  zone: zone, text: $( '#mkcp-co-editor-button-text' ).val(), url: $( '#mkcp-co-editor-button-url' ).val(), variant: $( '#mkcp-co-editor-button-variant' ).val(), align: $( '#mkcp-co-editor-button-align' ).val() };
            if ( ! block ) return;

            if ( editingIndex === -1 ) {
                block.id      = generateId();
                block.enabled = true;
                blocks.push( block );
            } else {
                block.id      = blocks[ editingIndex ].id;
                block.enabled = blocks[ editingIndex ].enabled;
                blocks[ editingIndex ] = block;
            }

            closeEditor();
            renderAllZones();
            serializeToInput();
        }

        // ── Events ───────────────────────────────────────────────────────────────

        $( '#mkcp-co-block-picker' ).on( 'click', '.mkcp-block-add-btn', function () {
            pendingZone = null;
            openEditor( $( this ).data( 'type' ), null );
        } );

        $( '#mkcp-co-zones' ).on( 'click', '.js-mkcp-co-toggle', function ( e ) {
            e.stopPropagation();
            var idx = parseInt( $( this ).closest( '.mkcp-block-item' ).attr( 'data-idx' ), 10 );
            blocks[ idx ].enabled = blocks[ idx ].enabled === false;
            renderAllZones();
            serializeToInput();
        } );

        $( '#mkcp-co-zones' ).on( 'click', '.js-mkcp-co-dup', function ( e ) {
            e.stopPropagation();
            var idx  = parseInt( $( this ).closest( '.mkcp-block-item' ).attr( 'data-idx' ), 10 );
            var copy = $.extend( true, {}, blocks[ idx ] );
            copy.id  = generateId();
            blocks.splice( idx + 1, 0, copy );
            renderAllZones();
            serializeToInput();
        } );

        $( '#mkcp-co-zones' ).on( 'click', '.js-mkcp-co-edit', function ( e ) {
            e.stopPropagation();
            var idx = parseInt( $( this ).closest( '.mkcp-block-item' ).attr( 'data-idx' ), 10 );
            openEditor( blocks[ idx ].type, idx );
        } );

        $( '#mkcp-co-zones' ).on( 'click', '.js-mkcp-co-delete', function ( e ) {
            e.stopPropagation();
            var idx = parseInt( $( this ).closest( '.mkcp-block-item' ).attr( 'data-idx' ), 10 );
            blocks.splice( idx, 1 );
            renderAllZones();
            serializeToInput();
        } );

        $( '#mkcp-co-editor-close, #mkcp-co-editor-cancel' ).on( 'click', closeEditor );
        $( '#mkcp-co-block-editor' ).on( 'click', function ( e ) { if ( e.target === this ) closeEditor(); } );
        $( '#mkcp-co-editor-save' ).on( 'click', saveEditor );
        $( document ).on( 'keydown.mkcp-co-editor', function ( e ) {
            if ( e.key === 'Escape' && $( '#mkcp-co-block-editor' ).is( ':visible' ) ) closeEditor();
        } );

        $( '#mkcp-form' ).on( 'submit', serializeToInput );

        // ── Init ─────────────────────────────────────────────────────────────────

        try { blocks = JSON.parse( $json.val() ) || []; } catch ( e ) { blocks = []; }
        renderAllZones();
        initSortable();
    }

} );
