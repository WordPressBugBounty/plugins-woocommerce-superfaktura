/**
 * SuperFaktúra setup wizard.
 *
 * All steps are rendered by PHP in one form; this script switches the steps, loads the company data from
 * SuperFaktúra, keeps the recommendations in sync, builds the summary and saves.
 */
( function () {
	'use strict';

	var data = window.wcSfSetup || {};
	var i18n = data.i18n || {};
	var form = document.getElementById( 'wc-sf-setup-form' );
	if ( ! form ) {
		return;
	}

	var LAST_STEP = 6;
	var DONE_STEP = 7;
	var state = {
		step: 0,
		max: 0,
		connected: false,
		connectKey: '',
		companyKey: '',
		loadedKey: '',
		leaving: false,
		companyRequest: 0,
		loading: false,
		companyOk: false,
		verifying: false,
		saving: false
	};

	function $( selector, context ) {
		return ( context || document ).querySelector( selector );
	}

	function $$( selector, context ) {
		return Array.prototype.slice.call( ( context || document ).querySelectorAll( selector ) );
	}

	// Replaces %s, %d and numbered %1$s placeholders, without special handling of "$" in the values.
	function format( text ) {
		var values = Array.prototype.slice.call( arguments, 1 );
		var index = 0;
		return String( text ).replace( /%(?:(\d+)\$)?[sd]/g, function ( match, position ) {
			var value = position ? values[ parseInt( position, 10 ) - 1 ] : values[ index++ ];
			return undefined === value ? '' : String( value );
		} );
	}

	function capitalize( text ) {
		return text ? text.charAt( 0 ).toUpperCase() + text.slice( 1 ) : text;
	}

	function section( step ) {
		return $( '[data-step="' + step + '"]', form );
	}

	function optionText( select, value ) {
		var option = $( 'option[value="' + CSS.escape( String( value ) ) + '"]', select );
		return option ? option.text : '';
	}

	/* Requests ------------------------------------------------------------------------------------------- */

	function request( action ) {
		var body = new FormData( form );
		body.append( 'action', action );
		body.append( 'nonce', data.nonce );

		return fetch( data.ajaxUrl, { method: 'POST', credentials: 'same-origin', body: body } )
			.catch( function () {
				throw new Error( i18n.failed );
			} )
			.then( function ( response ) {
				return response.text().then( function ( text ) {
					// An expired nonce or a lost session answers -1 or 0.
					if ( '-1' === text.trim() || '0' === text.trim() ) {
						throw new Error( i18n.expired );
					}
					try {
						return JSON.parse( text );
					} catch ( error ) {
						throw new Error( i18n.failed );
					}
				} );
			} )
			.then( function ( json ) {
				if ( ! json || ! json.success ) {
					throw new Error( ( json && json.data && json.data.message ) || i18n.failed );
				}
				return json.data;
			} );
	}

	function credentialsKey() {
		var lang = $( 'input[name="lang"]:checked', form );
		return [
			lang ? lang.value : '',
			form.elements.email.value,
			form.elements.apikey.value,
			form.elements.sandbox.checked ? 'yes' : 'no',
			form.elements.company_hint.value
		].join( '|' );
	}

	function showError( element, message ) {
		element.textContent = message;
		element.hidden = ! message;
	}

	function setBusy( button, busy, label ) {
		button.setAttribute( 'aria-busy', busy ? 'true' : 'false' );
		button.classList.toggle( 'is-busy', busy );
		if ( busy ) {
			button.innerHTML = '<span class="wc-sf-spinner" aria-hidden="true"></span>' + label;
		} else {
			button.textContent = label;
		}
	}

	/* Step 1: connection ------------------------------------------------------------------------------- */

	// Links to SuperFaktúra follow the chosen account country and the sandbox.
	function updateSfLinks() {
		var lang = $( 'input[name="lang"]:checked', form );
		var hosts = ( data.hosts || {} )[ lang ? lang.value : 'sk' ];
		if ( ! hosts ) {
			return;
		}
		var host = form.elements.sandbox.checked ? hosts.sandbox : hosts.live;
		$$( 'a[data-sf-path]' ).forEach( function ( link ) {
			link.href = host + link.getAttribute( 'data-sf-path' );
		} );
	}

	// Step 1 is done once the current account details are verified and a company is chosen.
	function stepOneReady() {
		return state.connected && state.connectKey === credentialsKey() && !! form.elements.company_id.value;
	}

	function updateConnectNext() {
		var current = state.connectKey === credentialsKey();
		var ready = stepOneReady();
		var next = $( '[data-go="2"]', section( 1 ) );
		next.disabled = ! ready;
		var note = $( '[data-next-note]', section( 1 ) );
		if ( note ) {
			note.hidden = ready;
			note.textContent = state.connected && current ? i18n.companyNote : i18n.verifyNote;
		}

		// After a change of the account details, the companies of the previous check no longer apply.
		$( '#wc-sf-companies' ).hidden = ! ( state.connected && current );
		if ( ! state.verifying ) {
			$( '#wc-sf-verify' ).textContent = state.connected && ! current ? i18n.verifyAgain : i18n.verify;
		}
	}

	function verify( auto ) {
		if ( state.verifying ) {
			return;
		}
		var button = $( '#wc-sf-verify' );
		var key = credentialsKey();

		state.verifying = true;
		setBusy( button, true, i18n.verifying );
		showError( $( '#wc-sf-connect-error' ), '' );

		request( 'wc_sf_setup_connect' )
			.then( function ( result ) {
				var select = form.elements.company_id;
				var wanted = select.value || form.elements.company_hint.value || select.getAttribute( 'data-initial' ) || '';
				select.innerHTML = '';
				if ( result.companies.length > 1 ) {
					// Never pick one of several companies for the user.
					var placeholder = new Option( i18n.chooseCompany, '' );
					placeholder.disabled = true;
					select.add( placeholder );
				}
				result.companies.forEach( function ( company ) {
					select.add( new Option( company.name + ' (ID ' + company.id + ')', company.id ) );
				} );
				select.value = wanted && $( 'option[value="' + CSS.escape( wanted ) + '"]', select ) ? wanted : select.options[ 0 ].value;
				state.connected = true;
				state.connectKey = key;
				if ( key === credentialsKey() ) {
					// The company is chosen from the list now.
					$( '#wc-sf-company-hint' ).hidden = true;
				}
				connectionChanged();
				updateConnectNext();
				if ( ! auto ) {
					select.focus();
				}
			} )
			.catch( function ( error ) {
				state.connected = false;
				$( '#wc-sf-company-hint' ).hidden = false;
				showError( $( '#wc-sf-connect-error' ), error.message );
			} )
			.then( function () {
				state.verifying = false;
				setBusy( button, false, i18n.verify );
				updateConnectNext();
			} );
	}

	/* Step 2: company data ------------------------------------------------------------------------------ */

	function fillSelect( select, items, current, defaultLabel, initial ) {
		select.innerHTML = '';
		var fallback = items.filter( function ( item ) {
			return item.default;
		} )[ 0 ];
		select.add( new Option( fallback ? format( defaultLabel, fallback.label ) : i18n.sfDefaultPlain, '' ) );
		items.forEach( function ( item ) {
			select.add( new Option( item.label, item.id ) );
		} );
		if ( current && ! $( 'option[value="' + CSS.escape( current ) + '"]', select ) ) {
			// Keep a saved item the list does not contain, so saving does not change it.
			select.add( new Option( format( i18n.currentId, current ), current ) );
		}
		select.value = current || '';
		select.setAttribute( 'data-initial', initial || '' );
	}

	function fillTypes( select, items, current, recommended ) {
		var saved = null !== current && undefined !== current && '' !== String( current ) ? String( current ) : '';
		select.innerHTML = '';
		select.add( new Option( i18n.notUsed, '0' ) );
		items.forEach( function ( item ) {
			select.add( new Option( item.label, item.key ) );
		} );

		var has = function ( value ) {
			return !! $( 'option[value="' + CSS.escape( String( value ) ) + '"]', select );
		};
		if ( saved && ! has( saved ) ) {
			// Keep a saved type the list does not offer (e.g. "other" or one added by a filter).
			select.add( new Option( 'other' === saved ? i18n.other : saved, saved ) );
		}

		var rec = '0';
		( recommended || [] ).some( function ( value ) {
			if ( value && has( value ) ) {
				rec = String( value );
				return true;
			}
			return false;
		} );

		// A configured site keeps what it uses now, a new one starts with the recommendation.
		select.value = saved || ( data.configured ? '0' : rec );
		select.setAttribute( 'data-rec-type', rec );
		select.setAttribute( 'data-initial', saved || '0' );
	}

	function showCompanyState( loading, error ) {
		$( '#wc-sf-company-loading' ).hidden = ! loading;
		$( '#wc-sf-docs' ).hidden = loading || !! error || ! state.companyOk;
		$( '#wc-sf-company-failed' ).hidden = ! error;
		$( '#wc-sf-company-error' ).textContent = error || '';
	}

	function loadCompany( force ) {
		var key = credentialsKey() + '|' + form.elements.company_id.value;
		if ( ! force && key === state.loadedKey && state.companyOk ) {
			showCompanyState( false, '' );
			return;
		}
		if ( ! force && key === state.companyKey && state.loading ) {
			return;
		}

		var id = ++state.companyRequest;
		state.companyKey = key;
		state.companyOk = false;
		state.loading = true;
		showCompanyState( true, '' );
		renderTabs();
		updateStepButtons();

		request( 'wc_sf_setup_company' )
			.then( function ( result ) {
				if ( id !== state.companyRequest ) {
					return;
				}
				var current = data.current || {};
				var lang = $( 'input[name="lang"]:checked', form );
				// The saved IDs belong to the saved company. Another company keeps only the ones in its own lists, the
				// rest starts from its defaults in SuperFaktúra.
				var same = form.elements.company_id.value === current.companyId && ( lang && lang.value === current.lang );
				var pick = function ( value, items ) {
					return same || items.some( function ( item ) {
						return item.id === value;
					} ) ? value : '';
				};
				var fill = function ( select, items, value ) {
					fillSelect( select, items, pick( value, items ), i18n.sfDefault, value );
				};
				fill( form.elements.seq_invoice, result.sequences.regular || [], current.seqInvoice );
				fill( form.elements.seq_proforma, result.sequences.proforma || [], current.seqProforma );
				fill( form.elements.seq_cancel, result.sequences.cancel || [], current.seqCancel );
				fill( form.elements.logo, result.logos || [], current.logo );
				fill( form.elements.bank, result.banks || [], current.bank );

				$$( 'select[data-pay-type]', form ).forEach( function ( select ) {
					var gateway = ( data.gateways || {} )[ select.getAttribute( 'data-pay-type' ) ] || {};
					fillTypes( select, result.payTypes || [], gateway.current, gateway.payTypes );
				} );
				$$( 'select[data-shipping]', form ).forEach( function ( select ) {
					var method = ( data.shipping || {} )[ select.getAttribute( 'data-shipping' ) ] || {};
					fillTypes( select, result.delivery || [], method.current, [ method.recommended ] );
				} );

				state.companyOk = true;
				state.loadedKey = key;
				state.loading = false;
				showCompanyState( false, '' );
				updateRows();
				updatePills();
			} )
			.catch( function ( error ) {
				if ( id !== state.companyRequest ) {
					return;
				}
				state.companyKey = '';
				state.loadedKey = '';
				state.loading = false;
				showCompanyState( false, error.message );
			} )
			.then( function () {
				if ( id === state.companyRequest ) {
					renderTabs();
					updateStepButtons();
				}
			} );
	}

	/* Steps 3 to 5: visibility and recommendations --------------------------------------------------------- */

	function field( row, name ) {
		return $( '[data-f="' + name + '"]', row );
	}

	function statusName( status ) {
		return ( data.statusNames || {} )[ status ] || status;
	}

	// The invoice is paid anyway when its status is one of the paid statuses in the settings.
	function paidByStatus( row ) {
		var doc = field( row, 'doc' ).value;
		return ( 'both' === doc || 'invoice' === doc ) && ( data.paidStatuses || [] ).indexOf( field( row, 'invoice' ).value ) !== -1;
	}

	function updateRows() {
		var anyProforma = false;
		$$( '[data-gateway]', form ).forEach( function ( row ) {
			var doc = field( row, 'doc' ).value;
			if ( 'both' === doc || 'proforma' === doc ) {
				anyProforma = true;
			}
			$$( '[data-show]', row ).forEach( function ( element ) {
				element.hidden = element.getAttribute( 'data-show' ).split( ' ' ).indexOf( doc ) === -1;
			} );

			$( '[data-same-status]', row ).hidden = ! ( 'both' === doc && field( row, 'proforma' ).value === field( row, 'invoice' ).value );

			var paid = field( row, 'paid' );
			var note = $( '[data-paid-note]', row );
			if ( ! paid.hasAttribute( 'data-user' ) ) {
				paid.setAttribute( 'data-user', paid.checked ? 'yes' : 'no' );
			}
			var forced = paidByStatus( row );
			paid.disabled = forced;
			paid.checked = forced || 'yes' === paid.getAttribute( 'data-user' );
			field( row, 'paid_auto' ).disabled = ! forced;
			note.hidden = ! forced;
			note.textContent = forced ? format( i18n.paidBySetting, statusName( field( row, 'invoice' ).value ) ) : '';
		} );

		var pairing = $( '#wc-sf-pairing' );
		if ( pairing ) {
			pairing.hidden = ! anyProforma;
		}

		$$( '[data-requires]', form ).forEach( function ( label ) {
			var required = form.elements[ label.getAttribute( 'data-requires' ) ];
			var inactive = !! required && ! required.checked;
			label.classList.toggle( 'is-inactive', inactive );
			var requiresNote = $( '[data-requires-note]', label );
			if ( requiresNote ) {
				requiresNote.hidden = ! inactive || ! $( 'input', label ).checked;
			}
		} );
	}

	function setPill( container, visible ) {
		var pill = $( '[data-pill]', container );
		if ( pill ) {
			pill.hidden = ! visible;
		}
	}

	function setRecommendation( container, match, text ) {
		var alt = $( '[data-rec-alt]', container );
		if ( ! alt ) {
			return;
		}
		alt.hidden = match || ! text;
		$( '[data-rec-text]', alt ).textContent = text;
	}

	function gatewayText( parts ) {
		var texts = [];
		if ( 'manual' === parts.doc ) {
			texts.push( i18n.sumManual );
		}
		if ( 'both' === parts.doc || 'proforma' === parts.doc ) {
			texts.push( format( i18n.sumProforma, statusName( parts.proforma ) ) );
		}
		if ( 'both' === parts.doc || 'invoice' === parts.doc ) {
			texts.push( format( i18n.sumInvoice, statusName( parts.invoice ) ) );
			if ( parts.paid ) {
				texts.push( i18n.sumPaid );
			}
		}
		if ( parts.payType ) {
			texts.push( format( i18n.sumPayType, parts.payType ) );
		}
		return capitalize( texts.join( ', ' ) );
	}

	function gatewayMatches( row, rec ) {
		var doc = field( row, 'doc' ).value;
		var payType = field( row, 'pay_type' );
		var match = doc === rec.doc && payType.value === payType.getAttribute( 'data-rec-type' );
		if ( match && ( 'both' === doc || 'invoice' === doc ) ) {
			match = field( row, 'invoice' ).value === rec.invoice && ( paidByStatus( row ) || field( row, 'paid' ).checked === !! rec.paid );
		}
		if ( match && ( 'both' === doc || 'proforma' === doc ) ) {
			match = field( row, 'proforma' ).value === rec.proforma;
		}
		return match;
	}

	function updatePills() {
		$$( '[data-gateway]', form ).forEach( function ( row ) {
			var rec = JSON.parse( row.getAttribute( 'data-rec' ) || '{}' );
			var match = gatewayMatches( row, rec );
			var payType = field( row, 'pay_type' );
			var recType = payType.getAttribute( 'data-rec-type' );
			setPill( row, match );
			setRecommendation( row, match, gatewayText( {
				doc: rec.doc,
				proforma: rec.proforma,
				invoice: rec.invoice,
				paid: rec.paid,
				payType: recType && '0' !== recType ? optionText( payType, recType ) : ''
			} ) );
		} );

		$$( '[data-rec-shipping]', form ).forEach( function ( row ) {
			var rec = row.getAttribute( 'data-rec-shipping' );
			var select = $( 'select', row );
			var match = select.value === rec;
			setPill( row, '0' !== rec && match );
			setRecommendation( row, match, optionText( select, rec ) );
			if ( '0' === rec ) {
				// The note about an unrecognized method is needed only until a type is chosen.
				$( '[data-why]', row ).hidden = '0' !== select.value;
			}
		} );

		$$( '[data-rec]:not([data-gateway])', form ).forEach( function ( container ) {
			var control = $( 'select, input', container );
			var rec = container.getAttribute( 'data-rec' );
			var current = 'checkbox' === control.type ? ( control.checked ? 'yes' : 'no' ) : control.value;
			setPill( container, current === rec );
			if ( 'SELECT' === control.tagName ) {
				setRecommendation( container, current === rec, optionText( control, rec ) );
			}
		} );

		$$( '.wc-sf-choice', form ).forEach( function ( choice ) {
			choice.classList.toggle( 'is-checked', $( 'input', choice ).checked );
		} );
	}

	function useRecommendation( button ) {
		var row = button.closest( '[data-gateway]' );
		if ( row ) {
			var rec = JSON.parse( row.getAttribute( 'data-rec' ) || '{}' );
			field( row, 'doc' ).value = rec.doc;
			if ( '0' !== rec.proforma ) {
				field( row, 'proforma' ).value = rec.proforma;
			}
			if ( '0' !== rec.invoice ) {
				field( row, 'invoice' ).value = rec.invoice;
			}
			field( row, 'paid' ).setAttribute( 'data-user', rec.paid ? 'yes' : 'no' );
			var payType = field( row, 'pay_type' );
			payType.value = payType.getAttribute( 'data-rec-type' ) || '0';
		} else {
			var shipping = button.closest( '[data-rec-shipping]' );
			var container = shipping || button.closest( '[data-rec]' );
			$( 'select', container ).value = shipping ? shipping.getAttribute( 'data-rec-shipping' ) : container.getAttribute( 'data-rec' );
		}
		updateRows();
		updatePills();
		var control = ( row || button.closest( '[data-rec-shipping], [data-rec]' ) ).querySelector( 'select' );
		if ( control ) {
			control.focus();
		}
	}

	/* Summary ---------------------------------------------------------------------------------------- */

	function visibleIn( element, container ) {
		for ( var node = element; node && node !== container; node = node.parentElement ) {
			if ( node.hidden ) {
				return false;
			}
		}
		return true;
	}

	function controlValue( control ) {
		if ( 'checkbox' === control.type ) {
			return control.checked ? 'yes' : 'no';
		}
		return control.value;
	}

	function controlText( control ) {
		if ( 'SELECT' === control.tagName ) {
			return control.selectedIndex >= 0 ? control.options[ control.selectedIndex ].text : '';
		}
		if ( 'checkbox' === control.type ) {
			return control.checked ? i18n.yes : i18n.no;
		}
		return control.value;
	}

	function isChanged( controls ) {
		if ( ! data.configured ) {
			return false;
		}
		return controls.some( function ( control ) {
			if ( ! control.hasAttribute( 'data-initial' ) || control.disabled ) {
				return false;
			}
			var initial = control.getAttribute( 'data-initial' );
			if ( 'radio' === control.type ) {
				return control.checked && control.value !== initial;
			}
			return controlValue( control ) !== initial;
		} );
	}

	function addSummaryRow( list, label, text ) {
		var dt = document.createElement( 'dt' );
		var dd = document.createElement( 'dd' );
		dt.textContent = label;
		dd.textContent = text;
		if ( data.configured ) {
			var pill = document.createElement( 'span' );
			pill.className = 'wc-sf-pill wc-sf-pill-changed';
			pill.textContent = i18n.changed;
			dd.appendChild( document.createTextNode( ' ' ) );
			dd.appendChild( pill );
		}
		list.appendChild( dt );
		list.appendChild( dd );
	}

	// Whether saving turns on the manual buttons: any manual gateway on a new install, a newly manual one otherwise.
	function newlyManual() {
		return $$( '[data-gateway]', form ).some( function ( row ) {
			var doc = field( row, 'doc' );
			return 'manual' === doc.value && ( ! data.configured || 'manual' !== doc.getAttribute( 'data-initial' ) );
		} );
	}

	// Whether a gateway creates the invoice in the "Processing" status.
	function invoiceAtProcessing() {
		return $$( '[data-gateway]', form ).some( function ( row ) {
			var doc = field( row, 'doc' ).value;
			return ( 'both' === doc || 'invoice' === doc ) && 'processing' === field( row, 'invoice' ).value;
		} );
	}

	function buildSummary() {
		var target = $( '#wc-sf-summary' );
		var template = $( '#wc-sf-sum-group' );
		target.innerHTML = '';

		for ( var step = 1; step < LAST_STEP; step++ ) {
			var part = section( step );
			var group = template.content.firstElementChild.cloneNode( true );
			var list = $( 'dl', group );
			$( 'h2', group ).textContent = part.getAttribute( 'data-title' );
			$( 'button', group ).setAttribute( 'data-go', step );

			$$( '[data-sum], [data-sum-row]', part ).forEach( function ( item ) {
				if ( ! visibleIn( item, part ) ) {
					return;
				}
				var label;
				var text;
				var controls;

				if ( item.hasAttribute( 'data-gateway' ) ) {
					label = item.getAttribute( 'data-sum-row' );
					controls = $$( 'select, input[type=checkbox]', item ).filter( function ( control ) {
						return visibleIn( control, item );
					} );
					var payType = field( item, 'pay_type' );
					text = gatewayText( {
						doc: field( item, 'doc' ).value,
						proforma: field( item, 'proforma' ).value,
						invoice: field( item, 'invoice' ).value,
						paid: field( item, 'paid' ).checked,
						payType: '0' !== payType.value ? controlText( payType ) : ''
					} );
				} else if ( item.hasAttribute( 'data-sum-row' ) ) {
					label = item.getAttribute( 'data-sum-row' );
					controls = $$( 'select', item );
					text = controlText( controls[ 0 ] );
				} else if ( item.hasAttribute( 'data-sum-secret' ) ) {
					// Only that a new key was entered, never the key itself.
					if ( ! $( 'input', item ).value ) {
						return;
					}
					label = item.getAttribute( 'data-sum' );
					controls = [];
					text = i18n.newKey;
				} else {
					label = item.getAttribute( 'data-sum' );
					controls = $$( 'select, input', item );
					var radio = $( 'input[type=radio]:checked', item );
					text = radio ? $( 'b', radio.parentElement ).textContent : controlText( controls[ 0 ] );
					var requiresNote = $( '[data-requires-note]', item );
					if ( requiresNote && ! requiresNote.hidden ) {
						text += ' (' + requiresNote.textContent.trim() + ')';
					}
				}

				var dt = document.createElement( 'dt' );
				var dd = document.createElement( 'dd' );
				dt.textContent = label;
				dd.textContent = text;
				if ( isChanged( controls ) || ( data.configured && item.hasAttribute( 'data-sum-secret' ) ) ) {
					var pill = document.createElement( 'span' );
					pill.className = 'wc-sf-pill wc-sf-pill-changed';
					pill.textContent = i18n.changed;
					dd.appendChild( document.createTextNode( ' ' ) );
					dd.appendChild( pill );
				}
				list.appendChild( dt );
				list.appendChild( dd );
			} );

			if ( 3 === step ) {
				// Global settings saved together with the payments, see ajax_save().
				if ( ! data.configured && ! data.skippedFix && invoiceAtProcessing() ) {
					addSummaryRow( list, i18n.skippedFix, i18n.yes );
				}
				if ( ! data.manualOn && newlyManual() ) {
					addSummaryRow( list, i18n.manualOn, i18n.yes );
				}
			}

			if ( list.children.length ) {
				target.appendChild( group );
			}
		}

		var pairing = $( '#wc-sf-pairing' );
		var pairingMissing = !! pairing && ! pairing.hidden && ! $( '#wc-sf-pairing-done' ).checked;
		$( '#wc-sf-pairing-warning' ).hidden = ! pairingMissing;
		$( '#wc-sf-done-pairing' ).hidden = ! pairingMissing;
		$( '#wc-sf-done-sandbox' ).hidden = ! form.elements.sandbox.checked;
	}

	/* Navigation ------------------------------------------------------------------------------------- */

	function updateStepButtons() {
		var next = $( '[data-go="3"]', section( 2 ) );
		if ( next ) {
			next.disabled = state.loading || ! state.companyOk;
		}
		updateConnectNext();
	}

	function renderTabs() {
		var step = state.step;
		var tabs = $( '#wc-sf-steps' );
		tabs.hidden = step < 1 || step > LAST_STEP;
		$$( '[data-step-tab]', tabs ).forEach( function ( tab ) {
			var number = parseInt( tab.getAttribute( 'data-step-tab' ), 10 );
			var button = $( 'button', tab );
			tab.classList.toggle( 'is-current', number === step );
			// Steps after the connection need a verified account, the ones after the documents its company data.
			var blocked = ( number > 1 && ! stepOneReady() ) || ( number > 2 && ! state.companyOk );
			// Green only before the current step; a later step visited before stays grey but can be opened again.
			tab.classList.toggle( 'is-done', number < step && ! blocked );
			tab.classList.toggle( 'is-visited', number > step && number <= state.max && ! blocked );
			button.disabled = number === step || number > state.max || blocked;
			if ( button.disabled ) {
				button.removeAttribute( 'data-go' );
			} else {
				button.setAttribute( 'data-go', number );
			}
			if ( number === step ) {
				button.setAttribute( 'aria-current', 'step' );
				$( '#wc-sf-step-now' ).textContent = format( i18n.stepOf, number, LAST_STEP, tab.getAttribute( 'data-title' ) );
			} else {
				button.removeAttribute( 'aria-current' );
			}
		} );
		$( '#wc-sf-step-now' ).hidden = tabs.hidden;
	}

	function go( step ) {
		// The documents need a verified account, payments, shipping and the summary the data of its company.
		if ( step > 1 && step <= LAST_STEP && ! stepOneReady() ) {
			step = 1;
		} else if ( step > 2 && step <= LAST_STEP && ! state.companyOk ) {
			step = 2;
		}
		if ( step === 2 ) {
			loadCompany();
		}
		if ( step === LAST_STEP ) {
			buildSummary();
		}

		state.step = step;
		if ( step <= LAST_STEP ) {
			state.max = Math.max( state.max, step );
		}

		$$( '[data-step]', form ).forEach( function ( part ) {
			part.hidden = parseInt( part.getAttribute( 'data-step' ), 10 ) !== step;
		} );
		$( '#wc-sf-leave' ).hidden = step === DONE_STEP;

		renderTabs();

		if ( step === 1 && data.configured && ! state.connected ) {
			verify( true );
		}

		updateStepButtons();
		window.scrollTo( 0, 0 );
		var heading = $( 'h1', section( step ) );
		if ( heading && step > 0 ) {
			heading.setAttribute( 'tabindex', '-1' );
			heading.focus( { preventScroll: true } );
		}
	}

	function save() {
		if ( state.saving ) {
			return;
		}
		var button = $( '#wc-sf-save' );
		var label = button.textContent;
		state.saving = true;
		setBusy( button, true, i18n.saving );
		showError( $( '#wc-sf-save-error' ), '' );

		request( 'wc_sf_setup_save' )
			.then( function () {
				go( DONE_STEP );
			} )
			.catch( function ( error ) {
				showError( $( '#wc-sf-save-error' ), error.message );
			} )
			.then( function () {
				state.saving = false;
				setBusy( button, false, label );
			} );
	}

	/* Events ----------------------------------------------------------------------------------------- */

	document.addEventListener( 'click', function ( event ) {
		var leave = event.target.closest( '#wc-sf-leave' );
		if ( leave && ( event.ctrlKey || event.metaKey || event.shiftKey || 0 !== event.button ) ) {
			// Opens in a new tab, the wizard stays.
			return;
		}
		if ( leave ) {
			if ( hasChoices() && ! window.confirm( i18n.leave ) ) {
				event.preventDefault();
				return;
			}
			state.leaving = true;
			return;
		}

		var button = event.target.closest( 'button' );
		if ( ! button ) {
			return;
		}
		if ( button.hasAttribute( 'data-go' ) && ! button.disabled ) {
			go( parseInt( button.getAttribute( 'data-go' ), 10 ) );
			return;
		}
		if ( 'wc-sf-verify' === button.id ) {
			verify( false );
			return;
		}
		if ( 'wc-sf-company-retry' === button.id ) {
			loadCompany( true );
			var loading = $( '#wc-sf-company-loading' );
			loading.setAttribute( 'tabindex', '-1' );
			loading.focus();
			return;
		}
		if ( 'wc-sf-save' === button.id ) {
			save();
			return;
		}
		if ( button.hasAttribute( 'data-use-rec' ) ) {
			useRecommendation( button );
			return;
		}
		if ( button.hasAttribute( 'data-copy' ) ) {
			var text = button.getAttribute( 'data-copy' );
			if ( ! button.hasAttribute( 'data-label' ) ) {
				button.setAttribute( 'data-label', button.textContent );
			}
			var done = function () {
				button.textContent = i18n.copied;
				$( '#wc-sf-live' ).textContent = i18n.copied;
				window.setTimeout( function () {
					button.textContent = button.getAttribute( 'data-label' );
					$( '#wc-sf-live' ).textContent = '';
				}, 1400 );
			};
			try {
				navigator.clipboard.writeText( text ).then( done, function () {
					selectText( button.previousElementSibling );
				} );
			} catch ( error ) {
				selectText( button.previousElementSibling );
			}
		}
	} );

	// Choices made after the connection would be lost by leaving.
	function hasChoices() {
		return state.max >= 2 && state.step !== DONE_STEP && ! state.saving;
	}

	window.addEventListener( 'beforeunload', function ( event ) {
		if ( hasChoices() && ! state.leaving ) {
			event.preventDefault();
			event.returnValue = '';
		}
	} );

	function selectText( element ) {
		if ( ! element ) {
			return;
		}
		var range = document.createRange();
		range.selectNodeContents( element );
		var selection = window.getSelection();
		selection.removeAllRanges();
		selection.addRange( range );
	}

	// Changing the account or company invalidates the loaded company data until step 2 loads it again.
	function connectionChanged() {
		var key = credentialsKey() + '|' + form.elements.company_id.value;
		if ( state.loading && key !== state.companyKey ) {
			state.companyRequest++;
			state.loading = false;
			showCompanyState( false, '' );
		}
		// Going back to the account details of the last load keeps its data and the choices made since.
		state.companyOk = !! state.loadedKey && key === state.loadedKey;
		renderTabs();
	}

	form.addEventListener( 'change', function ( event ) {
		var target = event.target;
		if ( 'paid' === target.getAttribute( 'data-f' ) && ! target.disabled ) {
			target.setAttribute( 'data-user', target.checked ? 'yes' : 'no' );
		}
		if ( 'shipping_item_name' === target.name && ! target.value.trim() ) {
			// An empty name would leave shipping off the invoice, so it is not accepted.
			target.value = target.getAttribute( 'data-initial' );
		}
		if ( section( 1 ).contains( target ) ) {
			connectionChanged();
			updateSfLinks();
		}
		updateRows();
		updatePills();
		updateConnectNext();
	} );

	form.addEventListener( 'input', function ( event ) {
		if ( section( 1 ).contains( event.target ) ) {
			connectionChanged();
		}
		updateConnectNext();
	} );

	form.addEventListener( 'keydown', function ( event ) {
		if ( 'Enter' === event.key && 'INPUT' === event.target.tagName && section( 1 ).contains( event.target ) && 'checkbox' !== event.target.type && 'radio' !== event.target.type ) {
			event.preventDefault();
			verify( false );
		}
	} );

	form.addEventListener( 'submit', function ( event ) {
		event.preventDefault();
	} );

	updateSfLinks();
	updateRows();
	updatePills();
	go( 0 );
}() );
