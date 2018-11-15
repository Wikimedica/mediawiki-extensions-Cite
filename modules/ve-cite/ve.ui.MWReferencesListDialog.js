/*!
 * VisualEditor user interface MWReferencesListDialog class.
 *
 * @copyright 2011-2018 VisualEditor Team's Cite sub-team and others; see AUTHORS.txt
 * @license MIT
 */

/**
 * Dialog for editing MediaWiki references lists.
 *
 * @class
 * @extends ve.ui.NodeDialog
 *
 * @constructor
 * @param {Object} [config] Configuration options
 */
ve.ui.MWReferencesListDialog = function VeUiMWReferencesListDialog( config ) {
	// Parent constructor
	ve.ui.MWReferencesListDialog.super.call( this, config );
};

/* Inheritance */

OO.inheritClass( ve.ui.MWReferencesListDialog, ve.ui.NodeDialog );

/* Static Properties */

ve.ui.MWReferencesListDialog.static.name = 'referencesList';

ve.ui.MWReferencesListDialog.static.title =
	OO.ui.deferMsg( 'cite-ve-dialog-referenceslist-title' );

ve.ui.MWReferencesListDialog.static.modelClasses = [ ve.dm.MWReferencesListNode ];

ve.ui.MWReferencesListDialog.static.size = 'medium';

ve.ui.MWReferencesListDialog.static.actions = [
	{
		action: 'apply',
		label: OO.ui.deferMsg( 'visualeditor-dialog-action-apply' ),
		flags: [ 'progressive', 'primary' ],
		modes: 'edit'
	},
	{
		label: OO.ui.deferMsg( 'visualeditor-dialog-action-cancel' ),
		flags: [ 'safe', 'back' ],
		modes: 'edit'
	}
];

/* Methods */

/**
 * @inheritdoc
 */
ve.ui.MWReferencesListDialog.prototype.getBodyHeight = function () {
	return Math.max( 150, Math.ceil( this.editPanel.$element[ 0 ].scrollHeight ) );
};

/**
 * @inheritdoc
 */
ve.ui.MWReferencesListDialog.prototype.initialize = function () {
	var groupField, responsiveField;

	// Parent method
	ve.ui.MWReferencesListDialog.super.prototype.initialize.call( this );

	// Properties
	this.panels = new OO.ui.StackLayout();
	this.editPanel = new OO.ui.PanelLayout( {
		scrollable: true, padded: true
	} );
	this.optionsFieldset = new OO.ui.FieldsetLayout();

	this.groupInput = new ve.ui.MWReferenceGroupInputWidget( {
		$overlay: this.$overlay,
		emptyGroupName: ve.msg( 'cite-ve-dialog-reference-options-group-placeholder' )
	} );
	groupField = new OO.ui.FieldLayout( this.groupInput, {
		align: 'top',
		label: ve.msg( 'cite-ve-dialog-reference-options-group-label' )
	} );

	this.responsiveCheckbox = new OO.ui.CheckboxInputWidget();
	responsiveField = new OO.ui.FieldLayout( this.responsiveCheckbox, {
		align: 'inline',
		label: ve.msg( 'cite-ve-dialog-reference-options-responsive-label' )
	} );

	// Initialization
	this.optionsFieldset.addItems( [ groupField, responsiveField ] );
	this.editPanel.$element.append( this.optionsFieldset.$element );
	this.panels.addItems( [ this.editPanel ] );
	this.$body.append( this.panels.$element );
};

/**
 * Handle change event.
 */
ve.ui.MWReferencesListDialog.prototype.onChange = function () {
	this.updateActions();
};

/**
 * Update the 'apply' action according to whether there are changes
 */
ve.ui.MWReferencesListDialog.prototype.updateActions = function () {
	this.actions.setAbilities( { apply: this.isModified() } );
};

/**
 * @return {boolean}
 */
ve.ui.MWReferencesListDialog.prototype.isModified = function () {
	var refGroup, listGroup, oldListGroup, isResponsive, oldResponsive;
	if ( !this.selectedNode ) {
		return true;
	}

	refGroup = this.groupInput.getValue();
	listGroup = 'mwReference/' + refGroup;
	isResponsive = this.responsiveCheckbox.isSelected();

	oldListGroup = this.selectedNode.getAttribute( 'listGroup' );
	oldResponsive = this.selectedNode.getAttribute( 'isResponsive' );

	return listGroup !== oldListGroup || isResponsive !== oldResponsive;
};

/**
 * @inheritdoc
 */
ve.ui.MWReferencesListDialog.prototype.getActionProcess = function ( action ) {
	if ( action === 'apply' ) {
		return new OO.ui.Process( function () {
			var refGroup, listGroup, isResponsive, mwData, attrChanges, doc,
				surfaceModel = this.getFragment().getSurface();

			// Save changes
			refGroup = this.groupInput.getValue();
			listGroup = 'mwReference/' + refGroup;
			isResponsive = this.responsiveCheckbox.isSelected();

			if ( this.selectedNode ) {
				// Edit existing model
				doc = surfaceModel.getDocument();

				if ( this.isModified() ) {
					// newFromAttributeChanges doesn't do the smart-replacing
					// for nested attributes, so make a copy of the mw
					// attribute so we can disable autoGenerated now we've
					// changed it.
					mwData = ve.copy( this.selectedNode.getAttribute( 'mw' ) ) || {};
					delete mwData.autoGenerated;

					attrChanges = {
						listGroup: listGroup,
						refGroup: refGroup,
						isResponsive: isResponsive,
						mw: mwData
					};
					surfaceModel.change(
						ve.dm.TransactionBuilder.static.newFromAttributeChanges(
							doc, this.selectedNode.getOuterRange().start, attrChanges
						)
					);
				}
			}

			this.close( { action: action } );
		}, this );
	}
	// Parent method
	return ve.ui.MWReferencesListDialog.super.prototype.getActionProcess.call( this, action );
};

/**
 * @inheritdoc
 */
ve.ui.MWReferencesListDialog.prototype.getSetupProcess = function ( data ) {
	return ve.ui.MWReferencesListDialog.super.prototype.getSetupProcess.call( this, data )
		.next( function () {
			if ( !( this.selectedNode instanceof ve.dm.MWReferencesListNode ) ) {
				throw new Error( 'Cannot open dialog: references list must be selected' );
			}

			this.actions.setMode( 'edit' );

			this.groupInput.setValue( this.selectedNode.getAttribute( 'refGroup' ) );
			this.groupInput.populateMenu( this.getFragment().getDocument().getInternalList() );

			this.responsiveCheckbox.setSelected( this.selectedNode.getAttribute( 'isResponsive' ) );

			this.groupInput.connect( this, { change: 'onChange' } );
			this.responsiveCheckbox.connect( this, { change: 'onChange' } );

			this.updateActions();
		}, this );
};

/**
 * @inheritdoc
 */
ve.ui.MWReferencesListDialog.prototype.getTeardownProcess = function ( data ) {
	return ve.ui.MWReferencesListDialog.super.prototype.getTeardownProcess.call( this, data )
		.next( function () {
			this.groupInput.disconnect( this );
			this.responsiveCheckbox.disconnect( this );
		}, this );
};

/**
 * @inheritdoc
 */
ve.ui.MWReferencesListDialog.prototype.getReadyProcess = function ( data ) {
	return ve.ui.MWReferencesListDialog.super.prototype.getReadyProcess.call( this, data )
		.next( function () {
			this.groupInput.focus();
		}, this );
};

/* Registration */

ve.ui.windowFactory.register( ve.ui.MWReferencesListDialog );
