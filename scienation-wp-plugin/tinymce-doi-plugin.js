tinymce.PluginManager.add('scienation', function(editor, url) {
    // Add a button that opens a window
    editor.addButton('doi', {
        text: 'DOI',
        icon: false,
        onclick: function() {
            // Open window
            editor.windowManager.open({
                title: 'Insert DOI reference',
                body: [
                    {type: 'textbox', name: 'doi', label: 'DOI'},
					{type: 'textbox', name: 'link', label: 'Link'}
                ],
                onsubmit: function(e) {
                    editor.focus();
					var link = "";
					if (e.data.doi) {
						link = "https://dx.doi.org/" + e.data.doi;
					} else if (e.data.link) {
						link = link;
					} else {
						return;
					}
                    editor.selection.setContent('<a href="' + link + '" data-reference="true">' + editor.selection.getContent() + '</a>');
                }
            });
        }
    });
});