if( typeof removeablePanels != 'undefined' && removeablePanels.length > 0 ){
	removeablePanels.forEach(function(panel){
		wp.data.dispatch( 'core/edit-post').removeEditorPanel( 'taxonomy-panel-' + panel );
	});
}