<?php
/*
 Plugin Name: Posts filter multiselect
 Plugin URI: https://elearn.jp/wpman/column/posts-filter-multiselect.html
 Description: Each pull-down menu on the post list page will be changed from single selection to multiple selection. Also, tags and author filters will be added to allow you to narrow down your search.
 Author: tmatsuur
 Version: 2.2.1
 Author URI: https://12net.jp/
Text Domain: posts-filter-multiselect
Domain Path: /languages
 */

/*
 Copyright (C) 2015-2024 tmatsuur (Email: takenori dot matsuura at 12net dot jp)
 This program is licensed under the GNU GPL Version 2.
 */

define( 'POSTS_FILTER_MULTISELECT_DOMAIN', 'posts-filter-multiselect' );
define( 'POSTS_FILTER_MULTISELECT_DB_VERSION_NAME', 'posts-filter-multiselect-db-version' );
define( 'POSTS_FILTER_MULTISELECT_DB_VERSION', '2.2.1' );

$plugin_posts_filter_multiselect = new posts_filter_multiselect();

class posts_filter_multiselect {
	const PROPERTIES_NAME = '-properties';
	var $get_params = array();
	var $font_weight_normal = true;
	var $ui_theme = 'redmond';	// see jquery ui themes
	var $standard_keys = array( 's', 'post_status', 'post_type', 'action', 'action2', 'filter_action', 'paged', 'mode', 'post',
		'author', 'category_name' );
	var $has_edit_published_posts = false;

	/**
	 * Plugin initialize.
	 *
	 * @access public
	 */
	public function __construct() {
		register_activation_hook( __FILE__ , array( &$this , 'activation' ) );
		register_deactivation_hook( __FILE__ , array( &$this , 'deactivation' ) );

		global $pagenow;
		if ( isset( $pagenow ) && in_array( $pagenow, array( 'edit.php' ) ) ) {
			add_action( 'pre_get_posts', array( $this, 'pre_get_posts' ) );
			add_action( 'admin_enqueue_scripts', array( $this, 'admin_enqueue_scripts' ) );
			add_action( 'admin_head', array( $this, 'admin_head' ) );
			add_action( 'admin_footer', array( $this, 'admin_footer' ) );
		}
		add_action( 'admin_init', array( $this, 'ajax_inline_save' ) );	// @since 2.2.1
	}

	/**
	 * Plugin activation.
	 *
	 * @access public
	 */
	public function activation() {
		if ( get_option( POSTS_FILTER_MULTISELECT_DB_VERSION_NAME ) != POSTS_FILTER_MULTISELECT_DB_VERSION ) {
			update_option( POSTS_FILTER_MULTISELECT_DB_VERSION_NAME, POSTS_FILTER_MULTISELECT_DB_VERSION );
		}
	}

	/**
	 * Plugin deactivation.
	 *
	 * @access public
	 */
	public function deactivation() {
		delete_option( POSTS_FILTER_MULTISELECT_DB_VERSION_NAME );
	}

	/**
	 * @access public (for action)
	 *
	 * @param WP_Query $query
	 */
	public function pre_get_posts( $query ) {
		$this->get_params = array();
		foreach ( array_keys( $_GET ) as $key ) {
			if ( ! in_array( $key, $this->standard_keys ) && is_string( $_GET[$key] ) ) {
				$this->get_params[$key] = explode( ',', $_GET[$key] );

				if ( 'modified_author' === $key ) {	// [1.4.0] add.
					$query->set( 'meta_query',
						array( 'relation' => 'AND',
							array(
								'key' => '_edit_last',
								'value' => intval( $_GET[$key] ),
								'compare' => '=',
								'type' => 'UNSIGNED'
							),
						)
					);
					$query->set( $key, '' );
				} elseif ( count( $this->get_params[$key] ) > 1 ) {
					if ( 'm' === $key ) {
						$date_query = array( 'relation'=>'OR' );
						foreach ( $this->get_params[$key] as $yyyymm ) {
							if ( preg_match( '/^[0-9]+$/u', $yyyymm ) ) {
								$yyyy = intval( substr( $yyyymm, 0, 4 ) );
								if ( strlen( $yyyymm ) > 5 ) {
									$mm2 = $mm = intval( substr( $yyyymm, 4, 2 ) );
								} else {
									$mm = 1;
									$mm2 = 12;
								}
								if ( strlen( $yyyymm ) > 7 ) {
									$date_query[] = array( 'year'=>$yyyy, 'month'=>$mm, 'day'=>intval( substr( $yyyymm, 6, 2 ) ) );
								} else {
									$date_query[] = array(
										'compare'=>'BETWEEN',
										'inclusive'=>true,
										'after'=>$yyyy.'/'.$mm.'/1',
										'before'	=>date( 'Y/m/d H:i:s', strtotime( '+1 month '.$yyyy.'/'.$mm2.'/1' )-1 ) );
								}
							}
						}
						if ( count( $date_query ) > 1 ) {
							$query->set( 'm', '' );
							$query->set( 'date_query', $date_query );
						}
					} elseif ( 'tag__in' === $key ) {
						$query->set( $key, $this->get_params[$key] );
					} elseif ( 'post_format' === $key ) {	// [1.3.2] for post_format
						$slugs = array_keys( get_post_format_slugs() );
						$post_format = array();
						foreach ( $this->get_params[$key] as $term ) {
							if ( in_array( $term, $slugs ) ) {
								$post_format[] = 'post-format-' . $term;
							}
						}
						$query->set( $key, $post_format );
					} else {
						$query->set( $key, $_GET[$key] );
					}
				} else {
					if ( '0' === $_GET[$key] ) {
						$query->set( $key, '' );
					} else {
						// may be tag__in
						if ( 'tag__in' === $key && '-1' === $_GET[$key] ) {	// [1.2.0] add.
							$query->set( 'tag__in',  '' );
							$query->set( 'tag__not_in', get_tags( array( 'fields'=>'ids' ) ));
						} elseif ( 'post_format' === $key && ! empty( $_GET[$key] ) ) {	// [1.3.2] for post_format
							$slugs = array_keys( get_post_format_slugs() );
							if ( in_array( $_GET[$key], $slugs ) ) {
								$query->set( $key, 'post-format-' . $_GET[$key] );
							}
						} else {
							$query->set( $key, $_GET[$key] );
						}
					}
				}
			} elseif ( 'author' === $key ) { // [2.1.0] add.
				$this->get_params[$key] = explode( ',', $_GET[$key] );
			}
		}
	}

	/**
	 * Determine if you want to use traditional jquery.multiselect.js.
	 *
	 * @access private
	 *
	 * @return bool.
	 */
	private function _use_multiselect_js() {
		return $this->_is_wp_version( '5.5', '<' );
	}

	/**
	 * @access public (for action)
	 *
	 * @param string $hook_suffix
	 */
	public function admin_enqueue_scripts( $hook_suffix ) {
		if ( $this->_use_multiselect_js() ) {
			wp_enqueue_script( POSTS_FILTER_MULTISELECT_DOMAIN.'-script', plugins_url( basename( dirname( __FILE__ ) ).'/js/jquery.multiselect.min.js' ), array( 'jquery-ui-core', 'jquery-ui-widget' ) );
		}
		wp_enqueue_style( 'jquery-ui-css', plugins_url( basename( dirname( __FILE__ ) ).'/css/themes/'.$this->ui_theme.'/jquery-ui.min.css' ) );
		wp_enqueue_style( POSTS_FILTER_MULTISELECT_DOMAIN.'-style', plugins_url( basename( dirname( __FILE__ ) ).'/css/jquery.multiselect.css' ) );

		if ( $this->_is_wp_version( '5.7', '>=' ) ) {
			$color = '#2c3338';
			$color_hover = '#2271b1';
			$border_color = '#2271b1';
			$bgcolor = '#1e90ff';
			$bgcolor_header = '#2271b1';
		} else {
			switch( get_user_meta( get_current_user_id(), 'admin_color', true ) ) {
				case 'light':
					$color_hover = $color = $border_color = $bgcolor = '#04a4cc';
					$bgcolor_header = '#888';
					break;
				case 'blue':
					$color_hover = $color = $border_color = $bgcolor = '#e1a948';
					$bgcolor_header = '#4796b3';
					break;
				case 'coffee':
					$color_hover = $color = $border_color = $bgcolor = '#c7a589';
					$bgcolor_header = '#46403c';
					break;
				case 'ectoplasm':
					$color_hover = $color = $border_color = $bgcolor = '#a3b745';
					$bgcolor_header = '#413256';
					break;
				case 'midnight':
					$color_hover = $color = $border_color = $bgcolor = '#e14d43';
					$bgcolor_header = '#26292c';
					break;
				case 'ocean':
					$color_hover = $color = $border_color = $bgcolor = '#9ebaa0';
					$bgcolor_header = '#627c83';
					break;
				case 'sunrise':
					$color_hover = $color = $border_color = $bgcolor = '#dd823b';
					$bgcolor_header = '#be3631';
					break;
				case 'modern':	// 5.7.0 >=
					$color_hover = $color = $border_color = '#2271b1';
					$bgcolor = '#1e90ff';
					$bgcolor_header = '#1e90ff';
					break;
				default:
					$color_hover = $color = $border_color = '#0071a1';
					$bgcolor = '#0073aa';
					$bgcolor_header = '#32373c';
					break;
			}
		}

		$inline_style = <<<EOT
/* adjustment */
.fixed .column-modified { width: 10%; }
.ui-multiselect { line-height: 1.55em; position: relative; margin-left: 2px; margin-top: 1px; outline: none; overflow: hidden; }
.ui-multiselect.ui-state-default { border: 1px solid #7e8993; color: {$color}; }
.ui-multiselect span.ui-icon-triangle-2-n-s { position: absolute; right: 0; }
.ui-multiselect span { white-space: nowrap; }

.ui-widget-content { background-image: none; }
.ui-multiselect-header { margin-bottom: 0; }
.ui-helper-reset li { margin-top: 3px; margin-bottom: 3px; }
.ui-multiselect-checkboxes li { padding-right: 0; }
#post-query-submit { margin-left: 2px; }

.ui-narrow-keyword { margin-top: 3px; width: calc( 100% - 4px ); }

.ui-multiselect-header span.ui-icon,
.ui-multiselect span.ui-icon {
	background-image: none;
	text-indent: 0;
    width: 20px;
    height: 16px;
}
.ui-multiselect-header span.ui-icon:before,
.ui-multiselect span.ui-icon:before {
    display: inline-block;
	font-family: dashicons;
    line-height: 1;
    font-weight: 400;
    font-style: normal;
    speak: none;
    text-decoration: inherit;
    text-transform: none;
    text-rendering: auto;
    -webkit-font-smoothing: antialiased;
    -moz-osx-font-smoothing: grayscale;
    font-size: 16px;
    vertical-align: text-top;
    text-align: center;
    transition: color .1s ease-in;
}

.ui-multiselect .ui-icon.ui-icon-triangle-2-n-s:before { color: {$color}; content: '\\f347'; }
.ui-multiselect-header .ui-icon.ui-icon-check:before { content: '\\f147'; }
.ui-multiselect-header .ui-icon.ui-icon-closethick:before { content: '\\f335'; }
.ui-multiselect-header .ui-icon.ui-icon-circle-close { text-align: left; }
.ui-multiselect-header .ui-icon.ui-icon-circle-close:before { content: '\\f153'; }
EOT;
		if ( $this->_use_multiselect_js() ) {
			$inline_style .= ".ui-multiselect.ui-state-active .ui-icon.ui-icon-triangle-2-n-s:before { content: '\\f343'; }\n";
		} else {
			$inline_style .= ".ui-multiselect[aria-expanded='true'] .ui-icon.ui-icon-triangle-2-n-s:before { content: '\\f343'; }\n";
		}

		$inline_style .= <<<EOT
.ui-widget-content .ui-state-hover { font-weight: bold; color: #ffffff; background-color: {$bgcolor}; background-image: none; border-color: {$border_color}; border-radius: 0; }
.ui-widget-content { border: 1px solid {$border_color}; background-color: #ffffff; color: #32373c; border-radius: 0; }
.ui-state-default,.ui-widget-content .ui-state-default,.ui-widget-header .ui-state-default { background: #ffffff; border: 1px solid {$border_color}; border-radius: 3px; }
.ui-multiselect.ui-state-default:hover { color: {$color_hover}; box-shadow: {$bgcolor} 0px 0px 1px 1px; border-color: {$border_color}; }
.ui-multiselect.ui-state-active { color: {$color}; box-shadow: {$bgcolor} 0px 0px 1px 1px; border-color: {$border_color}; }
.ui-widget-header { border: none; background-image: none; background-color: {$bgcolor_header}; color: #f0f0f0; border-radius: 3px; }
EOT;
		if ( $this->font_weight_normal ) {
			$inline_style .= <<<EOT
.ui-state-default, .ui-widget-content .ui-state-default, .ui-widget-header .ui-state-default { font-weight: normal; }
.ui-state-active, .ui-widget-content .ui-state-active, .ui-widget-header .ui-state-active { font-weight: normal; }
.ui-state-hover, .ui-widget-content .ui-state-hover, .ui-widget-header .ui-state-hover, .ui-state-focus, .ui-widget-content .ui-state-focus, .ui-widget-header .ui-state-focus { font-weight: normal; }
EOT;
		}
		wp_add_inline_style( POSTS_FILTER_MULTISELECT_DOMAIN.'-style', $inline_style );
	}

	/**
	 * @access public (for action)
	 */
	public function admin_head() {
		add_action( 'restrict_manage_posts', array( $this, 'restrict_manage_posts' ) );											// @since 2.1.0
		global $post_type;
		if ( !empty( $post_type ) && $this->_is_wp_version( '3.5', '>=' ) ) {
			load_plugin_textdomain( POSTS_FILTER_MULTISELECT_DOMAIN, false, plugin_basename( dirname( __FILE__ ) ).'/languages' );
			add_filter( "manage_edit-{$post_type}_sortable_columns", array( $this, 'manage_posts_sortable_columns' ), 10, 1 );	// @since 3.5.0
			add_filter( "manage_{$post_type}_posts_columns", array( $this, 'manage_posts_columns' ), 10, 1 );					// @since 3.0.0
			add_action( "manage_{$post_type}_posts_custom_column", array( $this, 'manage_posts_custom_column' ), 10, 2 );		// @since 3.1.0
			$this->has_edit_published_posts = current_user_can( 'edit_published_posts' );										// @since 2.1.1
		}
	}

	/**
	 * @access public (for ajax inline-save action)
	 */
	public function ajax_inline_save() {
		if ( defined( 'DOING_AJAX' ) && DOING_AJAX && 'inline-save' === $_POST['action'] &&
			isset( $_POST['post_ID'] ) && (int) $_POST['post_ID'] ) {
			$post = get_post( $_POST['post_ID'], ARRAY_A );
			if ( $_POST['post_type'] === $post['post_type'] && $this->_is_wp_version( '3.5', '>=' ) ) {
				add_action( 'restrict_manage_posts', array( $this, 'restrict_manage_posts' ) );												// @since 2.1.0

				load_plugin_textdomain( POSTS_FILTER_MULTISELECT_DOMAIN, false, plugin_basename( dirname( __FILE__ ) ).'/languages' );
				add_filter( "manage_edit-{$post['post_type']}_sortable_columns", array( $this, 'manage_posts_sortable_columns' ), 10, 1 );	// @since 3.5.0
				add_filter( "manage_{$post['post_type']}_posts_columns", array( $this, 'manage_posts_columns' ), 10, 1 );					// @since 3.0.0
				add_action( "manage_{$post['post_type']}_posts_custom_column", array( $this, 'manage_posts_custom_column' ), 10, 2 );		// @since 3.1.0
				$this->has_edit_published_posts = current_user_can( 'edit_published_posts' );												// @since 2.1.1
			}
		}
	}

	/**
	 * Handle the multiple selectable pull-down menus.
	 *
	 * @access public (for action)
	 */
	public function admin_footer() {
?>
<script type="text/javascript">
//<![CDATA[
( function ( $ ) {
<?php if ( ! $this->_use_multiselect_js() ) { ?>
	document.addEventListener('DOMContentLoaded', function() {
		var get_filter = JSON.parse( '<?php echo json_encode( $this->get_params ); ?>' );
		$( '#posts-filter input[name=filter_action]' ).siblings( 'select' ).each( function () {
			first_text = $(this).find(':first' ).text();
			var multiselect_option = [];
			$(this).find( 'option' ).each( function () {
				multiselect_option.push( {value: $(this).attr( 'value' ), text: $(this).text(), checked: $(this).prop( 'selected' )} );
			} );
			let multiselect_id = $(this).attr( 'id' );
			let multiselect_content =
				'<div id="multiselect-' + multiselect_id + '" class="ui-multiselect-menu ui-widget ui-widget-content ui-corner-all" style="display: none;">' +
				'<div class="ui-widget-header ui-corner-all ui-multiselect-header ui-helper-clearfix"><ul class="ui-helper-reset">' +
				'<li><a class="ui-multiselect-all" href="#"><span class="ui-icon ui-icon-check"></span><span><?php _e( 'Select all' ); ?></span></a></li>' +
				'<li><a class="ui-multiselect-none" href="#"><span class="ui-icon ui-icon-closethick"></span><span><?php _e( 'Deselect' ); ?></span></a></li>' +
				'<li class="ui-multiselect-close"><a href="#" class="ui-multiselect-close"><span class="ui-icon ui-icon-circle-close"></span></a></li>' +
				'</ul></div>' +
				'<ul class="ui-multiselect-checkboxes ui-helper-reset" style="height: 17.5rem;">';
			for ( let key in multiselect_option ) {
				multiselect_content += '<li role="menuitem" class=" "><label for="ui-multiselect-' + multiselect_id + '-option-' + key + '" title="" class="ui-corner-all"><input id="ui-multiselect-' + multiselect_id + '-option-' + key + '" name="multiselect_' + multiselect_id + '" type="checkbox" value="' + multiselect_option[key].value + '" title="' + multiselect_option[key].text + '" ' + ( multiselect_option[key].checked ? 'checked="checked" aria-selected="true"': '' ) + '><span>' + multiselect_option[key].text + '</span></label></li>';
			}
			if ( 8 < multiselect_option.length ) {
				multiselect_content += '</ul><input type="text" id="find-' + multiselect_id + '" value="" placeholder="..." autocomplete="off" class="ui-narrow-keyword" /></div>';
			} else {
				multiselect_content += '</ul></div>';
			}
			let multiselect_menu = $( multiselect_content );
			$( 'body' ).append( multiselect_menu );
			multiselect_menu.find( '.ui-multiselect-checkboxes li' ).on( 'hover', function () {
				$(this).find( 'label' ).addClass( 'ui-state-hover' );
			}, function () {
				$(this).find( 'label' ).removeClass( 'ui-state-hover' );
			} ).on( 'click', function () {
			} );
			multiselect_menu.on( 'menuOpen', function () {
				if ( 'none' == $(this).css( 'display' ) ) {
					if ( $( '.ui-multiselect[aria-expanded="true"]' ) ) {
						$( '#' + $( '.ui-multiselect[aria-expanded="true"]' ).attr( 'aria-controls' ) ).trigger( 'menuClose' );
					}
					let button = $( '.ui-multiselect[aria-controls="' + $(this).attr( 'id' ) + '"]' );
					button.attr( 'aria-expanded', 'true' );
					$(this).css( 'left', button.offset().left ).css( 'top', button.offset().top + button.innerHeight() + 2 ).show();
				}
			} ).on( 'menuClose', function () {
				$( '.ui-multiselect[aria-controls="' + $(this).attr( 'id' ) + '"]' ).attr( 'aria-expanded', 'false' );
				$(this).hide();
			} ).on( 'prevItem', function () {
				let currentItem = $(this).prop( 'currentItem' );
				if ( null == currentItem ) {
					currentItem = 0;
				} else {
					$(this).find( '.ui-multiselect-checkboxes li label.ui-state-hover' ).removeClass( 'ui-state-hover' );
					currentItem--;
					if ( currentItem < 0 ) {
						currentItem = $(this).find( '.ui-multiselect-checkboxes li' ).length - 1;
					}
				}
				let currentMenu = $(this).find( '.ui-multiselect-checkboxes li' ).eq( currentItem );
				currentMenu.find( 'label' ).addClass( 'ui-state-hover' );
				$(this).prop( 'currentItem', currentItem );

				let menuList = $(this).find( '.ui-multiselect-checkboxes' );
				let y_pos = currentMenu.offset().top - menuList.offset().top;
				if ( y_pos < 0 ) {
					menuList.scrollTop( menuList.scrollTop() -
						( $(this).find( '.ui-multiselect-checkboxes li' ).eq( 1 ).offset().top - $(this).find( '.ui-multiselect-checkboxes li' ).eq( 0 ).offset().top ) );
				} else if ( y_pos + currentMenu.height() > menuList.height() ) {
					menuList.scrollTop( y_pos +
						( $(this).find( '.ui-multiselect-checkboxes li' ).eq( 1 ).offset().top - $(this).find( '.ui-multiselect-checkboxes li' ).eq( 0 ).offset().top ) - menuList.height() );
				}
			} ).on( 'nextItem', function () {
				let currentItem = $(this).prop( 'currentItem' );
				if ( null == currentItem ) {
					currentItem = 0;
				} else {
					$(this).find( '.ui-multiselect-checkboxes li label.ui-state-hover' ).removeClass( 'ui-state-hover' );
					currentItem++;
					if ( currentItem >= $(this).find( '.ui-multiselect-checkboxes li' ).length ) {
						currentItem = 0;
					}
				}
				let currentMenu = $(this).find( '.ui-multiselect-checkboxes li' ).eq( currentItem );
				currentMenu.find( 'label' ).addClass( 'ui-state-hover' );
				$(this).prop( 'currentItem', currentItem );

				let menuList = $(this).find( '.ui-multiselect-checkboxes' );
				let y_pos = currentMenu.offset().top -menuList.offset().top + currentMenu.height();
				if ( 0 < currentItem && y_pos > menuList.height() ) {
					menuList.scrollTop( menuList.scrollTop() +
						$(this).find( '.ui-multiselect-checkboxes li' ).eq( 1 ).offset().top - $(this).find( '.ui-multiselect-checkboxes li' ).eq( 0 ).offset().top );
				} else if ( 0 == currentItem && 0 < menuList.scrollTop() ) {
					menuList.scrollTop( 0 );
				}
			} ).on( 'currentMove', function ( e, target ) {
				let currentItem = $(this).prop( 'currentItem' );
				if ( null != currentItem ) {
					$(this).find( '.ui-multiselect-checkboxes li' ).eq( currentItem ).find( 'label' ).removeClass( 'ui-state-hover' );
				}
				currentItem = $(this).find( '.ui-multiselect-checkboxes li' ).index( target );
				$(this).find( '.ui-multiselect-checkboxes li' ).eq( currentItem ).find( 'label' ).addClass( 'ui-state-hover' );
				$(this).prop( 'currentItem', currentItem );
			} ).on( 'toggleCheck', function () {
				let currentItem = $(this).prop( 'currentItem' );
				if ( null != currentItem ) {
					$(this).find( '.ui-multiselect-checkboxes li' ).eq( currentItem ).each( function () {
						$(this).find( 'input[type=checkbox]' ).trigger( 'click' );
					} );
				}
			} ).on( 'updateSelected', function () {
				let selectedText = '', selectedValue = '';
				let multiselect_checkboxes = $(this).find( '.ui-multiselect-checkboxes' );
				multiselect_checkboxes.find( 'li input:checked' ).each( function () {
					if ( '' !== selectedText ) {
						selectedText += ',';
						selectedValue += ',';
					}
					selectedText += $(this).attr( 'title' );
					selectedValue += $(this).val();
				} );
				if ( '' == selectedText ) {
					selectedText = multiselect_checkboxes.find( 'li input:first' ).attr( 'title' );
					selectedValue = multiselect_checkboxes.find( 'li input:first' ).val();
				}
				$(this).prop( 'selectedText', selectedText );
				$(this).prop( 'selectedValue', selectedValue );
				$( '.ui-multiselect[aria-controls="' + $(this).attr( 'id' ) + '"]' ).trigger( 'updateText' );
			} );
			multiselect_menu.find( '.ui-multiselect-checkboxes li' ).on( 'mouseenter', function ( e ) {
				$(this).parents( '.ui-multiselect-menu' ).trigger( 'currentMove', $( e.target ).parents( 'li' ) );
			} );

			multiselect_menu.find( '.ui-multiselect-all' ).on( 'click', function () {
				let selectedText = '', selectedValue = '';
				$(this).parents( '.ui-multiselect-menu' ).find( '.ui-multiselect-checkboxes input[type=checkbox]' ).each( function () {
					if ( 0 < parseInt( $(this).val() ) ) {
						$(this).prop( 'checked', true );
						if ( '' !== selectedText ) {
							selectedText += ',';
							selectedValue += ',';
						}
						selectedText += $(this).attr( 'title' );
						selectedValue += $(this).val();
					} else {
						$(this).prop( 'checked', false );
					}
				} );
				$(this).parents( '.ui-multiselect-menu' ).prop( 'selectedText', selectedText );
				$(this).parents( '.ui-multiselect-menu' ).prop( 'selectedValue', selectedValue );
				$( '.ui-multiselect[aria-controls="' + $(this).parents( '.ui-multiselect-menu' ).attr( 'id' ) + '"]' ).trigger( 'updateText' ).trigger( 'focus' );
				return false;
			} );
			multiselect_menu.find( '.ui-multiselect-none' ).on( 'click', function () {
				$(this).parents( '.ui-multiselect-menu' ).find( '.ui-multiselect-checkboxes input[type=checkbox]' ).filter( ':checked' ).each( function () {
					$(this).prop( 'checked', false );
				} );
				$(this).parents( '.ui-multiselect-menu' ).find( '.ui-multiselect-checkboxes input[type=checkbox]' ).first().trigger( 'click' );
				$( '.ui-multiselect[aria-controls="' + $(this).parents( '.ui-multiselect-menu' ).attr( 'id' ) + '"]' ).trigger( 'focus' );
				return false;
			} );
			multiselect_menu.find( '.ui-multiselect-close' ).on( 'click', function () {
				$(this).parents( '.ui-multiselect-menu' ).trigger( 'menuClose' );
				$( '.ui-multiselect[aria-controls="' + $(this).parents( '.ui-multiselect-menu' ).attr( 'id' ) + '"]' ).trigger( 'focus' );
				return false;
			} );
			multiselect_menu.find( '.ui-multiselect-checkboxes input[type=checkbox]' ).on( 'focus', function () {
				$(this).parents( '.ui-multiselect-checkboxes' ).find( 'li label.ui-state-hover' ).removeClass( 'ui-state-hover' );
				$(this).parents( 'li' ).find( 'label' ).addClass( 'ui-state-hover' );
				let multiselect_menu = $(this).parents( '.ui-multiselect-menu' );
				multiselect_menu.prop( 'currentItem', $(this).parents( '.ui-multiselect-checkboxes' ).find( 'li' ).index( $(this).parents( 'li' ) ) );
				$( '.ui-multiselect[aria-controls="' + multiselect_menu.attr( 'id' ) + '"]' ).trigger( 'focus' );
				return false;
			} ).on( 'change', function ( e ) {
				let selectedText = '', selectedValue = '';
				let multiselect_checkboxes = $(this).parents( '.ui-multiselect-checkboxes' );
				if ( '0' === e.target.value ) {
					multiselect_checkboxes.find( 'li input[value!=0]:checked' ).prop( 'checked', false );
					selectedText = e.target.title;
					selectedValue = e.target.value;
				} else if ( '-1' === e.target.value ) {
					multiselect_checkboxes.find( 'li input[value!=-1]:checked' ).prop( 'checked', false );
					selectedText = e.target.title;
					selectedValue = e.target.value;
				} else if ( '' === e.target.value )	{ // [1.3.2] for post_format
					multiselect_checkboxes.find( 'li input[value!=""]:checked' ).prop( 'checked', false );
					selectedText = e.target.title;
					selectedValue = e.target.value;
				} else {
					multiselect_checkboxes.find( 'li input[value=0]:checked,li input[value=-1]:checked,li input[value=""]:checked' ).prop( 'checked', false );
					multiselect_checkboxes.find( 'li input:checked' ).each( function () {
						if ( '' !== selectedText ) {
							selectedText += ',';
							selectedValue += ',';
						}
						selectedText += $(this).attr( 'title' );
						selectedValue += $(this).val();
					} );
				}
				if ( '' == selectedText ) {
					selectedText = multiselect_checkboxes.find( 'li input:first' ).attr( 'title' );
					selectedValue = multiselect_checkboxes.find( 'li input:first' ).val();
				}
				$(this).parents( '.ui-multiselect-menu' ).prop( 'selectedText', selectedText );
				$(this).parents( '.ui-multiselect-menu' ).prop( 'selectedValue', selectedValue );
				$( '.ui-multiselect[aria-controls="' + $(this).parents( '.ui-multiselect-menu' ).attr( 'id' ) + '"]' ).trigger( 'updateText' );
			} );

			let multiselect_button = $( '<button type="button" class="ui-multiselect ui-widget ui-state-default ui-corner-all" aria-haspopup="true" aria-expanded="false" aria-controls="multiselect-' + multiselect_id + '" style="width: ' + Math.ceil( multiselect_menu.innerWidth() + 1 ) + 'px;"><span class="ui-icon ui-icon-triangle-2-n-s"></span><span class="ui-text">' + $(this).find( 'option[value=' + $(this).val() + ']' ).text() + '</span></button>' );
			$(this).hide().after( multiselect_button );
			multiselect_button.on( 'click', function ( e ) {
				$( '#' + $(this).attr( 'aria-controls' ) ).trigger( 'false' == $(this).attr( 'aria-expanded' )? 'menuOpen': 'menuClose' );
				return false;
			} ).on( 'focus', function ( e ) {
				$(this).addClass( 'ui-state-active' ).addClass( 'ui-state-focus' );
			} ).on( 'blur', function ( e ) {
				if ( 'ui-narrow-keyword' === mouseDown.target.className ) { // [2.2.0]
					return;
				}
				$(this).removeClass( 'ui-state-active' ).removeClass( 'ui-state-focus' );
				if ( 'BODY' == document.activeElement.tagName &&
					'true' == $(this).attr( 'aria-expanded' ) &&
					null != mouseDown && 15 > ( e.timeStamp - mouseDown.timeStamp ) ) {
					if ( 0 < $( mouseDown.target ).parents( '.ui-multiselect-menu' ).length ) {
						$(this).trigger( 'focus' );
					} else {
						$( '#' + $(this).attr( 'aria-controls' ) ).trigger( 'menuClose' );
					}
				}
			} ).on( 'keydown', function ( e ) {
				if ( 'true' == $(this).attr( 'aria-expanded' ) ) {
					if ( 9 == e.keyCode || 27 == e.keyCode ) {	// tab, esc
						$( '#' + $(this).attr( 'aria-controls' ) ).trigger( 'menuClose' );
						return false;
					} else if ( 32 == e.keyCode ) {	// space
						$( '#' + $(this).attr( 'aria-controls' ) ).trigger( 'toggleCheck' );
						return false;
					} else if ( 37 == e.keyCode || 38 == e.keyCode ) {	// left, up
						$( '#' + $(this).attr( 'aria-controls' ) ).trigger( 'prevItem' );
						return false;
					} else if ( 39 == e.keyCode || 40 == e.keyCode ) {	// right, down
						$( '#' + $(this).attr( 'aria-controls' ) ).trigger( 'nextItem' );
						return false;
					}
				}
			} ).on( 'updateText', function () {
				let selectedText = $( '#' + $(this).attr( 'aria-controls' ) ).prop( 'selectedText' );
				if ( 'string' === typeof selectedText ) {
					$(this).find( '.ui-text' ).text( selectedText );
				}
				$(this).prop( 'selectedValue', $( '#' + $(this).attr( 'aria-controls' ) ).prop( 'selectedValue' ) );
			} );

			multiselect_menu.find( '.ui-narrow-keyword' ).on( 'input', function () {	// [2.2.0]
				let newValue = $(this).val();
				if ( $(this).data( 'prevKeyword' ) !== $(this).val() ) {
					$(this).data( 'prevKeyword', newValue );
					if ( newValue ) {
						$(this).prev().find( 'li label input' ).each( function () {
							if ( 0 >= parseInt( $(this).val() ) ||
								$(this).prop( 'checked' ) ||
								-1 !== $(this).attr( 'title' ).toLowerCase().indexOf( newValue.toLowerCase() ) ) {
								$(this).parents( 'li[role="menuitem"]' ).removeClass( 'hidden' );
							} else {
								$(this).parents( 'li[role="menuitem"]' ).addClass( 'hidden' );
							}
						} );
					} else {
						$(this).prev().find( 'li.hidden' ).each( function () {
							$(this).removeClass( 'hidden' );
						} );
					}
				}
				return false;
			} );

			let selectedVal = get_filter[$(this).attr('name')];
			if ( Array.isArray( selectedVal ) && selectedVal.length > 1 ) {
				for ( var key in selectedVal ) {
					multiselect_menu.find( '[value='+selectedVal[key]+']' ).each( function () {
						if ( ! $(this).prop( 'checked' ) ) {
							$(this).trigger( 'click' );
						}
					} );
				}
			}
			multiselect_menu.trigger( 'updateSelected' );
		} );
		$( '#posts-filter' ).on( 'submit', function () {
			$( '#posts-filter input[name=filter_action]' ).siblings( 'select' ).each( function () {
				let selected = $(this).next().prop( 'selectedValue' );
				if ( selected == '' ) selected = '0';
				$(this).find( 'option:selected' ).val( selected );
			} );
			return true;
		} );
		var mouseDown = null;
		$( document ).on( 'mousedown', function ( e ) {
			if ( 0 === e.button ) {
				mouseDown = e;
			}
		} );
		var waitingMoveMenu = null;
		$( window ).on( 'resize', function( e ) {
			if ( waitingMoveMenu ) {
				window.clearTimeout( waitingMoveMenu );
			}
			waitingMoveMenu = window.setTimeout( function () {
				$( ".ui-multiselect[aria-expanded='true']" ).each( function () {
					$( '#' + $(this).attr( 'aria-controls' ) ).trigger( 'menuClose' ).trigger( 'menuOpen' );
				} );
				waitingMoveMenu = null;
			} , 500 );
		} );
<?php } else { ?>
	$(document).ready( function () {
		var get_filter = $.parseJSON( '<?php echo json_encode( $this->get_params ); ?>' );
		$( '#posts-filter input[name=filter_action]' ).siblings( 'select' ).each( function () {
			first_text = $(this).find(':first' ).text();
			$(this).multiselect( {
				checkAllText: '<?php _e( 'Select all' ); ?>',
				uncheckAllText: '<?php _e( 'Deselect' ); ?>',
				noneSelectedText: first_text,
				selectedText: function ( numChecked, numTotal, checkedItems ) {
					let text = '';
					if ( numChecked > 0 ) {
						for ( var key in checkedItems ) {
							if ( text != '' ) text += ',';
							text += $( checkedItems[key] ).attr( 'title' );
						}
					}
					return text;
				}
			} );
			$(this).on( 'multiselectclick', function( event, ui ) {
				if ( ui.checked ) {
					if ( ui.value == 0 )
						$(this).multiselect( 'widget' ).find( '[value!=0]:checked' ).click();
					else if ( ui.value == -1 )
						$(this).multiselect( 'widget' ).find( '[value!=-1]:checked' ).click();
					else if ( ui.value == '' )	// [1.3.2] for post_format
						$(this).multiselect( 'widget' ).find( '[value!=""]:checked' ).click();
					else
						$(this).multiselect( 'widget' ).find( '[value=0]:checked,[value=-1]:checked,[value=""]:checked' ).click();
				}
			} );
			let selectedVal = get_filter[$(this).attr('name')];
			if ( Array.isArray( selectedVal ) && selectedVal.length > 1 ) {
				for ( var key in selectedVal ) {
					$(this).multiselect( 'widget' ).find( '[value='+selectedVal[key]+']' ).each( function () {
						if ( !$(this).is( ':checked' ) ) $(this).click();
					} );
				}
			}
		} );
		$( '#posts-filter' ).on( 'submit', function () {
			$( '#posts-filter input[name=filter_action]' ).siblings( 'select' ).each( function () {
				let selected = $(this).multiselect( 'getChecked' ).map( function () { return this.value; } ).get().join();
				if ( selected == '' ) selected = '0';
				$(this).find( 'option:selected' ).val( selected );
			} );
			return true;
		} );
<?php } ?>
	} );
} )( jQuery );
//]]>
</script>
<?php
	}

	/**
	 * Add a pull-down menu for post tags.
	 *
	 * @since 2.1.0 Add a pull-down menu for author.
	 *
	 * @access public (for action)
	 *
	 * @param string $post_type
	 */
	public function restrict_manage_posts( $post_type = null ) {
		if ( is_null( $post_type ) ) {	// support 4.4.0
			$post_type = get_query_var( 'post_type', null );
			if ( is_null( $post_type ) ) {
				$post_type = 'post';
			}
		}
		if ( is_null( get_post_type_object( $post_type ) ) ) {
			// If the post type is invalid, nothing will be output.
			return;
		}

		if ( is_object_in_taxonomy( $post_type, 'post_tag' ) ) {
			$labels = get_taxonomy_labels( get_taxonomy( 'post_tag' ) );
			$tag__in = get_query_var( 'tag__in', null );
			if ( empty( $tag__in ) ) {
				$tag__not_in = get_query_var( 'tag__not_in', null );	// [1.2.0] add.
				if ( empty( $tag__not_in ) ) {
					$tag = get_term_by( 'slug', get_query_var( 'tag', null ), 'post_tag' );	// [1.4.0] add.
					if ( $tag ) {
						$tag__in = (string)$tag->term_id;
					} else {
						$tag__in = '';	// [1.1.2] may be bug.
					}
				} else {
					$tag__in = '-1';
				}
			} else {
				$tag__in = implode( ',', $tag__in );
			}
			$dropdown_options = array(
				'show_option_all' => __( $labels->all_items ),
				'show_option_none' => __( $labels->no_terms ),
				'hide_empty' => 0,
				'hierarchical' => 1,
				'show_count' => 0,
				'taxonomy' => 'post_tag',
				'name'=>'tag__in',
				'orderby' => 'name',
				'hide_if_empty' => true,
				'selected' => $tag__in
			);
			$filter_by_item = isset( get_taxonomy( 'post_tag' )->labels->filter_by_item )? get_taxonomy( 'post_tag' )->labels->filter_by_item: null;
			if ( empty( $filter_by_item ) ) {
				$filter_by_item = __( 'Filter by tag', POSTS_FILTER_MULTISELECT_DOMAIN );
			}
			echo '<label class="screen-reader-text" for="tag__in">' . esc_html( $filter_by_item ) . '</label>';
			wp_dropdown_categories( $dropdown_options );
		}

		// [2.1.0] add.
		if ( ( 'page' == $post_type && current_user_can( 'edit_pages' ) ) ||
			( 'page' != $post_type && current_user_can( 'edit_posts' ) ) ) {
			global $wpdb;
			$sql = "SELECT DISTINCT {$wpdb->posts}.post_author FROM {$wpdb->posts}
						WHERE {$wpdb->posts}.post_type = %s
						AND {$wpdb->posts}.post_status IN ('publish','future','draft','pending','private')";
			$author_ids = $wpdb->get_col( $wpdb->prepare( $sql, $post_type ) );

			if ( is_array( $author_ids ) && 1 < count( $author_ids ) ) {
				// wp_dropdown_users function cannot include multisite site administrators.
				$_users = get_users( array(
					'include' => $author_ids,
					'orderby' => 'display_name',
					'order'   => 'ASC',
					'fields'  => array( 'ID', 'display_name' )
				) );
				$user_ids = array();
				$users    = array();
				$unknown  = array();
				foreach ( $_users as $user ) {
					$user_ids[] = $user->ID;
					$users[$user->display_name . $user->ID] = $user;
				}
				foreach ( $author_ids as $author_id ) {
					if ( ! in_array( $author_id, $user_ids ) ) {
						$userdata = get_userdata( $author_id );
						if ( $userdata ) {
							$users[$userdata->display_name . $userdata->ID] = (object)array(
								'ID'           => $userdata->ID,
								'display_name' => $userdata->display_name
							);
						} else {
							// The author has probably been removed.
							$unknown_name = sprintf( __( '? Unknown %d', POSTS_FILTER_MULTISELECT_DOMAIN ), $author_id );
							$unknown[$unknown_name] = (object)array(
								'ID'           => $author_id,
								'display_name' => $unknown_name
							);
						}
					}
				}
				ksort( $users );
				if ( 0 < count( $unknown ) ) {
					ksort( $unknown );
					$users = array_merge( $users, $unknown );
				}

				$author = get_query_var( 'author', '' );
				$author_selected = explode( ',', (string)$author );
				if ( is_array( $author_selected ) && 0 < count( $author_selected ) ) {
					$author = intval( $author_selected[0] );
				} else {
					$author = 0;
				}

				$output = '<label class="screen-reader-text" for="author__in">' . esc_html( __( 'Filter by author', POSTS_FILTER_MULTISELECT_DOMAIN ) ) . '</label>';
				$output .= "<select name=\"author\" id=\"author\" class=\"postform\">\n";
				$output .= "\t<option value=\"0\">" . esc_html( __( 'All Authors', POSTS_FILTER_MULTISELECT_DOMAIN ) ) . "</option>\n";
				foreach ( $users as $user ) {
					$_selected = selected( $user->ID, $author, false );
					$output   .= "\t<option value=\"$user->ID\"{$_selected}>" . esc_html( $user->display_name ) . "</option>\n";
				}
				$output .= "</select>\n";
				echo $output;
			}
		}
	}

	/**
	 * @since 2.1.0
	 * @access public (for filter)
	 *
	 * @param string $fields
	 * @param WP_Query $query
	 * @return string
	 */
	public function field_is_post_author( $fields, $query ) {
		return 'post_author';
	}

	/**
	 * @since 1.3.0
	 * @access public (for filter)
	 *
	 * @param array $sortable_columns
	 * @return array
	 */
	public function manage_posts_sortable_columns( $sortable_columns ) {
		$sortable_columns['modified'] = array( 'modified', true );
		return $sortable_columns;
	}

	/**
	 * @since 1.3.0
	 * @access public (for filter)
	 *
	 * @param array $posts_columns
	 * @param string $post_type
	 * @return array
	 */
	public function manage_posts_columns( $posts_columns, $post_type = '' ) {
		$posts_columns['modified'] = __( 'Modified', POSTS_FILTER_MULTISELECT_DOMAIN );
		return $posts_columns;
	}

	/**
	 * Handle the post modified column output.
	 *
	 * @since 1.3.0
	 * @access public (for action)
	 *
	 * @global string $mode List table view mode.
	 *
	 * @param string $column_name
	 * @param int $post_ID
	 */
	public function manage_posts_custom_column( $column_name, $post_ID ) {
		if ( 'modified' === $column_name ) {	// [1.4.0] Only for modified column
			$post = get_post( $post_ID );
			if ( ! $post ) {
				return;
			}

			if ( $this->has_edit_published_posts ) {	// [2.1.1]
				// [1.4.0] Add modified author link.
				$last_id = get_post_meta( $post->ID, '_edit_last', true );
				if ( $last_id ) {
					$last_user = get_userdata( $last_id );

					$args = array(
						'post_type'       => $post->post_type,
						'modified_author' => get_the_author_meta( 'ID', $last_id ),
					);
					$url = add_query_arg( $args, 'edit.php' );
					echo sprintf(
						'<a href="%s">%s</a><br />',
						esc_url( $url ),
						apply_filters( 'the_modified_author', $last_user->display_name )
					);
				} else {
					echo '(none)<br />';
				}
			}

			if ( $this->_is_wp_version( '5.5', '<' ) ) {
				$datetime = mysql2date( __( 'Y/m/d g:i:s a' ), $post->post_modified, false );
			} else {
				$datetime = sprintf(
					__( '%1$s at %2$s' ),
					mysql2date( __( 'Y/m/d' ), $post->post_modified, false ),
					mysql2date( __( 'g:i a' ), $post->post_modified, false )
				);
			}
			$time = get_post_modified_time( 'G', true, $post );
			$time_diff = time() - $time;
			if ( $time_diff > 0 && $time_diff < DAY_IN_SECONDS ) {
				$h_time = sprintf( __( '%s ago' ), human_time_diff( $time ) );
			} else {
				$h_time = mysql2date( __( 'Y/m/d' ), $post->post_modified );
			}

			global $mode;
			if ( 'excerpt' === $mode ) {
				echo apply_filters( 'post_date_column_time', $datetime, $post, 'modified', $mode );
			} else {
				echo '<abbr title="' . $datetime . '">' . apply_filters( 'post_date_column_time', $h_time, $post, 'modified', $mode ) . '</abbr>';
			}
		}
	}

	/**
	 * Compares WordPress version number strings.
	 *
	 * @since 1.3.0
	 * @access private.
	 *
	 * @global string $wp_version
	 *
	 * @param string $version Version number.
	 * @param string $compare Operator. Default is '>='.
	 * @return bool.
	 */
	private function _is_wp_version( $version, $compare = '>=' ) {
		return version_compare( $GLOBALS['wp_version'], $version, $compare );
	}
}