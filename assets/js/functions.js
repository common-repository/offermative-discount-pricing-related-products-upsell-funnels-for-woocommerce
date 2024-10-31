class OMFunctions {
	
	static initializeHandlebarsHelpers(){
		Handlebars.registerHelper('product', function(options) {
			try{
				let key = options.hash.key || ''
				let messageParams = JSON.parse(options.hash.messageParams) || {}

				let offer = messageParams.offer || {}
				let offeredProduct = offer.product || {}
				let offeredProductId = offeredProduct.id || {}

				if(messageParams.products){
					if(messageParams.products[offeredProductId]){
						switch(key){
							// case 'description':
							// 	key = 'shortDescription'
							// 	break
							default:
								break
						}

						let text = OMFunctions.htmlDecode((messageParams.products[offeredProductId][key]) ? messageParams.products[offeredProductId][key] : '')
						return new Handlebars.SafeString(encodeURIComponent(text))
					}
				}			
				return ''
			} catch(e){
				OMErrorHandler.log('In OMFunctions initializeHandlebarsHelpers:: ', e)
			}
		})

		Handlebars.registerHelper('category', function(options) {
			try{
				let key = options.hash.key || ''
				let messageParams = JSON.parse(options.hash.messageParams) || {}
				let offer = messageParams.offer || {}
				let offeredProduct = offer.product || {}
				let offeredProductId = offeredProduct.id || {}

				if(messageParams.productCategories){
					if(messageParams.productCategories[offeredProductId]){
						let text = OMFunctions.htmlDecode((messageParams.productCategories[offeredProductId][key]) ? messageParams.productCategories[offeredProductId][key] : '')
						return new Handlebars.SafeString(encodeURIComponent(text))
					}
				}
				return ''
			} catch(e){
				OMErrorHandler.log('In OMFunctions category:: ', e)
			}
		})

		Handlebars.registerHelper('discount', function(options) {
			try{
				let messageParams = JSON.parse(options.hash.messageParams) || {}
				let offer = messageParams.offer || {}
				let discountType  = (offer.discount.type != undefined && offer.discount.type != '') ? offer.discount.type : undefined
				let discountValue = (offer.discount.value != undefined && offer.discount.value != '') ? offer.discount.value : undefined
				let discountAmountText = ''

				if (discountValue != undefined && discountType != undefined) {
					if ('percent' === discountType) {
						discountAmountText = discountValue + '%'
					} else {
						discountAmountText = messageParams.currencySymbol + discountValue
					}
				}

				let text = OMFunctions.htmlDecode(discountAmountText)
				return new Handlebars.SafeString(encodeURIComponent(text))
			} catch(e){
				OMErrorHandler.log('In OMFunctions discount:: ', e)
			}
		})
	}

	static getDeviceType() {
		const ua = navigator.userAgent;
		if (/(tablet|ipad|playbook|silk)|(android(?!.*mobi))/i.test(ua)) {
			return "tablet";
		}
		if (/Mobile|iP(hone|od|ad)|Android|BlackBerry|IEMobile|Kindle|Silk-Accelerated|(hpw|web)OS|Opera M(obi|ini)/.test(ua)) {
			return "mobile";
		}
		return "desktop";
	}
	
	static getNumber(n) {
		try{
			return Math.floor(n/100) || 0
		} catch(e){
			OMErrorHandler.log('In OMFunctions getNumber:: ', e)
		}
	}

	static getDecimal(n) {
		try{
			n = n/100
			return (Math.floor((n - Math.floor(n)) * 100)) || 0
		} catch(e){
			OMErrorHandler.log('In OMFunctions getDecimal:: ', e)
		}
	}

	static encodeHtmlString(html) {
		try{
			return html.replace(/[\u00A0-\u9999<>&](?!#)/gim, function(i) {
				return '&#' + i.charCodeAt(0) + ';';
			});
		} catch(e){
			OMErrorHandler.log('In OMFunctions encodeHtmlString:: ', e)
		}
	}

	static decodeHtmlString(html) {
		try{
			return html.replace(/&#([0-9]{1,4});/gi, function(match, num) {
				return String.fromCharCode(parseInt(num));
			});	
		} catch(e){
			OMErrorHandler.log('In OMFunctions decodeHtmlString:: ', e)
		}
	}

	static htmlDecode(input) {
		let doc = new DOMParser().parseFromString(input, "text/html");
		return doc.documentElement.textContent;
	}

	static compileMessageContent(params) {
		try{
			let assetKeys = ['avatar', 'featuredImage', 'featuredIcon']
			let compiledContent = {}	
			let content = params.content || {}
			let msgParams = params.messageParams || {}
			let offer = msgParams.offer || {}
			let offerType = offer.type || ''

			//Code for replacing cartCTA with infoCTA if cartCTA is empty
			if(content['cartCTA'] == ''){
				content['cartCTA'] = content['infoCTA'] || ''
			}

			Object.keys(content).map((key) => {

				try{
					content[key] = OMFunctions.decodeHtmlString(content[key])

					if('category' == offerType){
						content[key] = content[key].replace(/{{product\.([\w]+){1}}}/gim,"{{category.$1}}")
					}

					//code for replacing placeholders like '{{discount}}' & '{{product.name}}'
					let text = content[key].replace(/{{([\w]+)}}/gim, "{{$1 messageParams='"+JSON.stringify(msgParams || {})+"'}}").replace(/{{([\w]+)\.([\w]+){1}}}/gim, "{{$1 key='$2' messageParams='"+JSON.stringify(msgParams || {})+"'}}")

					// Special case - prefix assets with site's url
					if (assetKeys.includes(key)) {
						text = params.assetsURL + '/' + text
					}
					compiledContent[key] = Handlebars.compile(text)
				} catch(e){
					OMErrorHandler.log('In OMFunctions compileMessageContent loop:: ', e)
				}

			})

			return compiledContent
		} catch(e){
			OMErrorHandler.log('In OMFunctions compileMessageContent:: ', e)
		}
	}

	static requestHandler(params) {
		try{
			let data = new FormData(),
				requestData = {security: omDashboardParams.security};

			requestData = {...requestData, ...params.requestData}

			for (let key in requestData) {
				data.append(key, requestData[key]);
			}

			m.request({
				method: params.method || 'POST',
				url: ajaxurl,
				params: {
					action: 'om_dashboard_controller',
				},
				body: data,
				withCredentials: params.withCredentials || false,
				responseType: params.responseType || "json"
			})
			.then((response) => {
				if(params.hasOwnProperty('callback')) {
					params.callback(response)
				}
			})
			.catch(error => {
				OMErrorHandler.log('In OMFunctions XHR Request Failed :: ', {params: requestData, error: error})
				return;
			});
		} catch(e){
			OMErrorHandler.log('In OMFunctions requestHandler:: ', e)
		}
	}

	static loadChart(params) {
		try{
			let element   = document.querySelectorAll(params.domSelector)[0]
			let chartData = params.chartData || []
			if (chartData.length <= 0) {
				if(element){
					if (!element.classList.contains('hidden')) {
						element.classList.add('hidden')
					}
				}
				return
			}
			let labels    = Object.keys(chartData)
			let values    = Object.values(chartData)
			if ( values.length > 0 ) {
				let data = {
					labels  : labels,
					datasets: [
						{
							values: values
						}
					]
				}
				if(element){
					if (element.classList.contains('hidden')) {
						element.classList.remove('hidden')
					}

					let chart = new frappe.Chart(params.domSelector, {
						parent     : params.domSelector,
						data       : data,
						type       : 'line',
						colors     : ['#5145cd'],
						lineOptions: {
							hideDots: 1
			},
			width      : 320,
						height     : 80,
						axisOptions: {
							xIsSeries: true
						},
						tooltipOptions: {
							formatTooltipX: d => d,
							formatTooltipY: d => omDashboardParams.currencySymbol + OMFunctions.getNumber(d) + omDashboardParams.decimalSeparator + OMFunctions.getDecimal(d),
						}
					})
				}
			}
		} catch(e){
			OMErrorHandler.log('In OMFunctions loadChart:: ', e)
		}
	}

	static formatCamelCaseString(string) {
		try{
			return string.replace(/([A-Z])/g, ' $1').replace(/^./, function(str){ return str.toUpperCase() })	
		} catch(e){
			OMErrorHandler.log('In OMFunctions formatCamelCaseString:: ', e)
		}
	}

	static insertString(index, string, insertStr) {
		try{
			if (index > 0)
			{
				return string.substring(0, index) + insertStr + string.substring(index, string.length)
			}

			return string;
		} catch(e){
			OMErrorHandler.log('In OMFunctions insertString:: ', e)
		}
	}

	static getEnhancedSelectFormatString() {
		try{
			return {
				'language': {
					errorLoading: function() {
						// Workaround for https://github.com/select2/select2/issues/4355 instead of i18n_ajax_error.
						return wc_enhanced_select_params.i18n_searching;
					},
					inputTooLong: function( args ) {
						var overChars = args.input.length - args.maximum;
	
						if ( 1 === overChars ) {
							return wc_enhanced_select_params.i18n_input_too_long_1;
						}
	
						return wc_enhanced_select_params.i18n_input_too_long_n.replace( '%qty%', overChars );
					},
					inputTooShort: function( args ) {
						var remainingChars = args.minimum - args.input.length;
	
						if ( 1 === remainingChars ) {
							return wc_enhanced_select_params.i18n_input_too_short_1;
						}
	
						return wc_enhanced_select_params.i18n_input_too_short_n.replace( '%qty%', remainingChars );
					},
					loadingMore: function() {
						return wc_enhanced_select_params.i18n_load_more;
					},
					maximumSelected: function( args ) {
						if ( args.maximum === 1 ) {
							return wc_enhanced_select_params.i18n_selection_too_long_1;
						}
	
						return wc_enhanced_select_params.i18n_selection_too_long_n.replace( '%qty%', args.maximum );
					},
					noResults: function() {
						return wc_enhanced_select_params.i18n_no_matches;
					},
					searching: function() {
						return wc_enhanced_select_params.i18n_searching;
					}
				}
			};
		} catch(e){
			OMErrorHandler.log('In OMFunctions getEnhancedSelectFormatString:: ', e)
		}	
	}

	// function to generate random key.
	static generateKey(){
		try{
			return Math.random().toString(36).substr(7)
		} catch(e){
			OMErrorHandler.log('In OMFunctions getEnhancedSelectFormatString:: ', e)
		}
	}

	// function to initialize WP Tinymce Editor
	static initializeWPEditor(params){
		if(!params){
			return
		}
		let id = params.id || ''
		if(!id){
			return
		}
		try{
			wp.editor.remove(id);
			wp.editor.initialize(id, 
								Object.assign({
										tinymce:  { height: 200,
											wpautop:true, 
											plugins : 'charmap colorpicker compat3x directionality fullscreen hr image lists media paste tabfocus textcolor wordpress wpautoresize wpdialogs wpeditimage wpemoji wpgallery wplink wptextpattern wpview', 
											toolbar1: 'formatselect bold,italic,strikethrough,|,bullist,numlist,blockquote,|,justifyleft,justifycenter,justifyright,|,link,unlink,wp_more,|,spellchecker,fullscreen,wp_adv',
											toolbar2: 'underline,justifyfull,forecolor,|,pastetext,pasteword,removeformat,|,media,charmap,|,outdent,indent,|,undo,redo,wp_help'},
										quicktags:  { buttons: 'strong,em,link,block,del,img,ul,ol,li,code,more,spell,close,fullscreen' },
										mediaButtons: true 
									},params));
		} catch(e){
			OMErrorHandler.log('In OMFunctions initializeWPEditor:: ', e)
		}
	}
}

OMFunctions.initializeHandlebarsHelpers()
