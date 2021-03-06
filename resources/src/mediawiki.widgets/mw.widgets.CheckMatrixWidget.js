( function ( $, mw ) {
	/**
	 * A JavaScript version of CheckMatrixWidget.
	 *
	 * @class
	 * @extends OO.ui.Widget
	 *
	 * @constructor
	 * @param {Object} [config] Configuration options
	 * @cfg {Object} columns Required object representing the column labels and associated
	 *  tags in the matrix.
	 * @cfg {Object} rows Required object representing the row labels and associated
	 *  tags in the matrix.
	 * @cfg {string[]} [forcedOn] An array of column-row tags to be displayed as
	 *  enabled but unavailable to change
	 * @cfg {string[]} [forcedOff] An array of column-row tags to be displayed as
	 *  disnabled but unavailable to change
	 * @cfg {Object} Object mapping row label to tooltip content
	 */
	mw.widgets.CheckMatrixWidget = function MWWCheckMatrixWidget( config ) {
		var $headRow = $( '<tr>' ),
			$table = $( '<table>' ),
			widget = this;
		config = config || {};

		// Parent constructor
		mw.widgets.CheckMatrixWidget.parent.call( this, config );
		this.checkboxes = {};
		this.name = config.name;
		this.id = config.id;
		this.rows = config.rows || {};
		this.columns = config.columns || {};
		this.tooltips = config.tooltips || [];
		this.values = config.values || [];
		this.forcedOn = config.forcedOn || [];
		this.forcedOff = config.forcedOff || [];

		// Build header
		$headRow.append( $( '<td>' ).html( '&#160;' ) );

		// Iterate over the columns object (ignore the value)
		$.each( this.columns, function ( columnLabel ) {
			$headRow.append( $( '<td>' ).text( columnLabel ) );
		} );
		$table.append( $headRow );

		// Build table
		$.each( this.rows, function ( rowLabel, rowTag ) {
			var $row = $( '<tr>' ),
				labelField = new OO.ui.FieldLayout(
					new OO.ui.Widget(), // Empty widget, since we don't have the checkboxes here
					{
						label: rowLabel,
						help: widget.tooltips[ rowLabel ],
						align: 'inline'
					}
				);

			// Label
			$row.append( $( '<td>' ).append( labelField.$element ) );

			// Columns
			$.each( widget.columns, function ( columnLabel, columnTag ) {
				var thisTag = columnTag + '-' + rowTag,
					checkbox = new OO.ui.CheckboxInputWidget( {
						value: thisTag,
						name: widget.name ? widget.name + '[]' : undefined,
						id: widget.id ? widget.id + '-' + thisTag : undefined,
						selected: widget.isTagSelected( thisTag ),
						disabled: widget.isTagDisabled( thisTag )
					} );

				widget.checkboxes[ thisTag ] = checkbox;
				$row.append( $( '<td>' ).append( checkbox.$element ) );
			} );

			$table.append( $row );
		} );

		this.$element
			.addClass( 'mw-widget-checkMatrixWidget' )
			.append( $table );
	};

	/* Setup */

	OO.inheritClass( mw.widgets.CheckMatrixWidget, OO.ui.Widget );

	/* Methods */

	/**
	 * Check whether the given tag is selected
	 *
	 * @param {string} tagName Tag name
	 * @return {boolean} Tag is selected
	 */
	mw.widgets.CheckMatrixWidget.prototype.isTagSelected = function ( tagName ) {
		return (
			// If tag is not forced off
			this.forcedOff.indexOf( tagName ) === -1 &&
			(
				// If tag is in values
				this.values.indexOf( tagName ) > -1 ||
				// If tag is forced on
				this.forcedOn.indexOf( tagName ) > -1
			)
		);
	};

	/**
	 * Check whether the given tag is disabled
	 *
	 * @param {string} tagName Tag name
	 * @return {boolean} Tag is disabled
	 */
	mw.widgets.CheckMatrixWidget.prototype.isTagDisabled = function ( tagName ) {
		return (
			// If the entire widget is disabled
			this.isDisabled() ||
			// If tag is forced off or forced on
			this.forcedOff.indexOf( tagName ) > -1 ||
			this.forcedOn.indexOf( tagName ) > -1
		);
	};
	/**
	 * @inheritdoc
	 */
	mw.widgets.CheckMatrixWidget.prototype.setDisabled = function ( isDisabled ) {
		var widget = this;

		// Parent method
		mw.widgets.CheckMatrixWidget.parent.prototype.setDisabled.call( this, isDisabled );

		// setDisabled sometimes gets called before the widget is ready
		if ( this.checkboxes && Object.keys( this.checkboxes ).length > 0 ) {
			// Propagate to all checkboxes and update their disabled state
			$.each( this.checkboxes, function ( name, checkbox ) {
				checkbox.setDisabled( widget.isTagDisabled( name ) );
			} );
		}
	};
}( jQuery, mediaWiki ) );
