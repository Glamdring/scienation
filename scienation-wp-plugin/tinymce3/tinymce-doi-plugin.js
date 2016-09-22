(function() {
	tinymce.create('tinymce.plugins.ScienationPlugin', {
		init : function(ed, url) {
			var command = "scienation-reference";
			// Register commands
			ed.addCommand(command, function() {
				// Open window
				ed.windowManager.open({
					title: 'Insert reference',
					body: [
						{type: 'textbox', name: 'doi', label: 'DOI'},
						{type: 'textbox', name: 'link', label: 'Link'}
					],
					onsubmit: function(e) {
						ed.focus();
						var link = "";
						if (e.data.doi) {
							link = "https://dx.doi.org/" + e.data.doi;
						} else if (e.data.link) {
							link = link;
						} else {
							return;
						}
						ed.selection.setContent('<a href="' + link + '" data-reference="true">' + ed.selection.getContent() + '</a>');
					}
				});
			});

			// Register buttons
			ed.addButton('scienation', {
				title : 'Insert reference',
				image : url + '/reference.png',
				cmd : command
			});
		}
	});

	// Register plugin
	tinymce.PluginManager.add('scienation', tinymce.plugins.ScienationPlugin);
})();