( function( $ ){
	var defaults = {
		'partner':0,
		'wclass':'km_widget',
		'wparam':'seq_id',
		'noselect':false
	};

	var methods =
	{
		init: function()
		{
			var urls = [ document.getElementById( 'km_widget_script' ).src, window.location.href ];
			$.each( urls, function( index, pageURL ) {
				if ( pageURL.indexOf( '?' ) > -1 )
				{
					var parts = pageURL.split( '?' );
					var sPageURL = parts[1];
					var sURLVariables = sPageURL.split('&');
					$.each( sURLVariables, function( index, value ) {
						var sParameterName = value.split( '=' );
						if ( defaults.hasOwnProperty( sParameterName[0] ) )
							defaults[sParameterName[0]] = sParameterName[1];

						if ( sParameterName[0] == defaults.wparam ) {
							$.fn.KMWidget( 'open', sParameterName[1] );
							return this;
						}
					});
				}
			});

			$( '.'+defaults.wclass ).click(function(){
				$.fn.KMWidget( 'open', $(this) );
				return false;
			});

			return this;
		},

		config: function( options )
		{
			defaults = $.extend( defaults, options );
			return this;
		},

		open: function( el )
		{
			var wWidth = 790;
			var wHeight = 512;
			var wwPos = parseInt($(window).width())/2 + $(window).scrollLeft() - (wWidth / 2);
			var whPos = parseInt($(window).height())/2 + $(window).scrollTop() - (wHeight / 2);

			if ( typeof el == 'string' )
				uri = 'ordering/sale/seq/' + el;
			else
			{
				var params = el.data( 'kmwidget' ) || {};
				var uri = '';
				if ( 'cityID' in params )
					uri = 'cinemas/city/'+params.cityID;
				else if ( 'cinemaID' in params )
					uri = 'schedule/cinema/'+params.cinemaID;
				else if ( 'sessionID' in params )
					uri = 'ordering/hallplan/session/'+params.sessionID;
				else
					uri = 'cinemas/city/1';
			}

			uri += '?' + $.param( { 'partner':defaults.partner, 'noselect':defaults.noselect, 'wparam':defaults.wparam } );
			uri += '&backURL=' + encodeURIComponent( window.location.href );
			uri += '&s=' + Math.random();

			var iframe = $( '#kmWidget' + defaults.partner );
			if ( iframe.length == 0 )
			{
				iframe = $('<iframe />', {
					id:'kmWidget' + defaults.partner,
					src:location.protocol+'//widget.kinomax.ru/web/' + uri,
					width:wWidth,
					height:wHeight
				}).css({'z-index':'9999','position':'absolute','left':wwPos+'px','top':whPos+'px','border':'0'})
					.appendTo('body');

				addEventListener( "message", function( event )
					{
						if ( event.data == 'close' )
						{
							$( '#kmWidget' + defaults.partner ).hide();
							$( '#kmWidget_overlay' ).remove();
							$('body').css('overflow', 'auto');
						}
					},
					false );
			}
			else
			{
				iframe.css({'left':wwPos+'px','top':whPos+'px'});
				iframe.attr( 'src', location.protocol+'//widget.kinomax.ru/web/' + uri );
				iframe.show();
			}

			$('<div/>', {
				id:'kmWidget_overlay'
			}).css({
				'background-color':'#000',
				'height':$(document).height(),
				'opacity':0.8,
				'width':$(document).width(),
				'position':'fixed',
				'left':0,
				'top':0,
				'z-index':1001
			}).appendTo( 'body' );
			$('body').css('overflow', 'hidden');

			$(window).resize(function(){
				$( '#kmWidget' + defaults.partner ).css({
					'left':parseInt($(window).width())/2 + $(window).scrollLeft() - (wWidth / 2)+'px',
					'top':parseInt($(window).height())/2 + $(window).scrollTop() - (wHeight / 2)+'px'
				});
			});
		}
	};

	$.fn.KMWidget = function( method )
	{
		if ( methods[method] )
			return methods[method].apply( this, Array.prototype.slice.call( arguments, 1 ) );
		else if ( typeof method == 'object' || !method )
			return methods.init.apply( this, arguments );
		else
		{
			$.error( 'Метод с именем ' + method + ' не найден в jQuery.KMWidget' );
			return this;
		}
	}
}( jQuery ));

$.fn.KMWidget( 'init' );