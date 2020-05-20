if (!String.prototype.format) {
	String.prototype.format = function() {
		var args = arguments;
		return this.replace(/{(\d+)}/g, function(match, number) {
			return typeof args[number] != 'undefined' ? args[number] : match;
		});
	};
}

function isFormData( obj ){
	return (obj instanceof FormData) ? true : false;
}

function isElement( obj ){
	try {
		return obj instanceof HTMLElement;
	} catch(e){
		return( typeof obj === 'object' ) &&
			( obj.nodeType === 1 ) && ( typeof obj.style === 'object' ) &&
			( typeof obj.ownerDocument === 'object' );
	}
}

function apiPromise( method, url, data ){
	return new Promise( function( resolve, reject ){
		var request = new XMLHttpRequest();

		if( 'withCredentials' in request ){
			request.open(method, url, true);
		} else if( typeof XDomainRequest != 'undefined' ){
			request = new XDomainRequest();
			request.open( method, url );
		} else {
			request = null;
		}

		request.onload = function(){
			if( request.status === 200 ){
				resolve( request.response );
			} else {
				reject( Error( 'Interaction failed:' + request.statusText ) );
			}
		};

		request.onerror = function(){
			reject( Error( 'Network error.' ) );
		};

		data = (isFormData(data)) ? data : toFormData(data);

		request.send( data );
	});
}

function toFormData(item){
	var formData = new FormData();

	if( isElement(item) ){
		if( item.tagName === 'FORM' ){
			item = objectifyForm(item);
		}
	}
	
	if( typeof item === 'object' ){
		Object.keys(item).forEach(function(key) {
			//if( Array.isArray(item[key] ) )
			item[key] = JSON.stringify(item[key]);

			formData.append(key, item[key]);
		});
	} else {
		return false;
	}

	return formData;
}

var typingTimer;              //timer identifier
var doneTypingInterval = 350; //time in ms

function xhynkTaxTermQuery( el, e, checkTermsFor ){
	// Not typing, if we have a value, do stuff
	var parent = el.parentNode;
	var target = parent.querySelector('.target');
			
	if( target == null ){
		target = parent.parentNode.querySelector('.target');
	}

	target.classList.add('loading');

	if( el.value && el.value.length > 1 ){
		typingTimer = setTimeout(function(){
			var results;

			var thisTax  = el.getAttribute('data-tax');
			var thisType = el.getAttribute('data-post-type');

			var data = {
				search:    el.value,
				taxonomy:  thisTax,
				post_type: thisType
			};

			if( checkTermsFor != null )
				data.check_terms_for = parseInt(checkTermsFor);

			apiPromise('POST',  ajaxurl + '?action=term_query_advanced', toFormData(data) ).then(function(response){
				target.classList.remove('loading');
				var terms = JSON.parse(response);

				if( terms != null && terms.length > 0 ){
					target.innerHTML = '';
					
					terms.forEach(function(term){
						if( ! term.has_term ){
							target.innerHTML += '<div onclick="xhynkAddTermToObject(this, event, {0});" data-term-id="{1}" data-tax="{2}">{3}</div>'.format(checkTermsFor, term.id, thisTax, term.name);
						}
					});
				} else {
					target.innerHTML = '<div><strong>No Results Found for "{0}"</strong></div>'.format(el.value);
				}
			});
		}, doneTypingInterval);
	} else {
		target.classList.remove('loading');
			target.innerHTML = '';
	}
}

function xhynkAddTermToObject( el, e, post_id ){
	if( e != null )
		e.preventDefault();
	
	el.classList.add('loading');

	var thisTerm  = parseInt(el.getAttribute('data-term-id') );
	var thisTax   = el.getAttribute('data-tax');
	var container = el.closest('.xhynk-meta-box');
	var tagTarget = container.querySelector('.tag-container');
	var none      = tagTarget.querySelector('.no-rows-found');

	var data = {
		post_id: post_id,
		term_id: thisTerm,
		taxonomy: thisTax
	};

	apiPromise( 'POST', ajaxurl + '?action=add_term_to_object', toFormData(data) ).then(function(response){
		var response = JSON.parse(response);

		if( response.toLowerCase() == 'success' ){
			var newTag = document.createElement('span');
			newTag.classList.add('tag', 'removable');

			newTag.innerHTML = el.innerText;
			newTag.innerHTML += '<span class="close" onclick="xhynkRemoveTermFromObject(this,event,{0});" data-term-id="{1}" data-tax="{2}"><svg viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"></line><line x1="6" y1="6" x2="18" y2="18"></line></svg></span></span>'.format(post_id, thisTerm, thisTax);

			tagTarget.insertBefore(newTag,none);
			el.remove();
		} else {
			alert( 'Something went wrong' );
		}
		/*if( target != null ){
			var args = json.args;
			target = document.querySelector(target);

			var tag = document.createElement('span');
			tag.classList.add('tag','removable','inserted');
			tag.setAttribute('data-id',term_id);
			tag.innerHTML = '{0}<span class="close" onclick="removeTermFromPost(this,event,{1},{2},\'{3}\'); return false;"><svg viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"></line><line x1="6" y1="6" x2="18" y2="18"></line></svg></span>'.format(args.term_name,post_id,term_id,tax);
			
			var noRowsFound = target.querySelector('.no-rows-found');
			target.insertBefore( tag, noRowsFound );

			whirlocalAdminAlert( {type: 'success', content: 'Successfully added {0}'.format(data.tax)} );

			setTimeout(function(){
				tag.classList.remove('inserted');
			},250);
		}*/
	});
}

function xhynkRemoveTermFromObject( el, e, post_id ){
	if( e != null )
		e.preventDefault();
	
	el.closest('.tag').classList.add('loading');

	var thisTerm  = parseInt(el.getAttribute('data-term-id') );
	var thisTax   = el.getAttribute('data-tax');

	var data = {
		post_id: post_id,
		term_id: thisTerm,
		taxonomy: thisTax
	};

	apiPromise( 'POST', ajaxurl + '?action=remove_term_from_object', toFormData(data) ).then(function(response){
		var response = JSON.parse(response);

		if( response.toLowerCase() == 'success' ){
			el.closest('.tag').remove();
		} else {
			alert( 'Something went wrong' );
		}
	});
}