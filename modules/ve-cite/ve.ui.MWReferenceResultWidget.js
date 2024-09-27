'use strict';

/*!
 * VisualEditor UserInterface MWReferenceResultWidget class.
 *
 * @copyright 2011-2018 VisualEditor Team's Cite sub-team and others; see AUTHORS.txt
 * @license MIT
 */

/**
 * Creates an ve.ui.MWReferenceResultWidget object.
 *
 * @constructor
 * @extends OO.ui.OptionWidget
 * @param {Object} config Configuration options
 * @param {Object} config.item Result item
 */
ve.ui.MWReferenceResultWidget = function VeUiMWReferenceResultWidget( config ) {
	// Parent constructor
	ve.ui.MWReferenceResultWidget.super.apply( this, arguments );

	// Initialization
	const item = config.item;

	this.$element
		.addClass( 've-ui-mwReferenceResultWidget' );
	if ( item.reference.extendsRef !== undefined ) {
		this.$element.addClass( 've-ui-mwReferenceResultWidget-sub' );
	}

	const $footnoteLabel = $( '<div>' )
		.addClass( 've-ui-mwReferenceResultWidget-footnote' )
		.text( '[' + item.footnoteLabel + ']' );

	const $name = $( '<div>' )
		.addClass( 've-ui-mwReferenceResultWidget-name' )
		.toggleClass( 've-ui-mwReferenceResultWidget-name-autogenerated', /^:\d+$/.test( item.name ) )
		.text( item.name );

	this.setLabel( $footnoteLabel.add( $name ).add( item.$refContent ) );
	this.setData( item.reference );
};

/* Inheritance */

OO.inheritClass( ve.ui.MWReferenceResultWidget, OO.ui.OptionWidget );
