function addArticleComment(id) {
	try {

		var query = "backend.php?op=pluginhandler&plugin=social&method=add&param=" + param_escape(id);

		if (dijit.byId("addCommentDlg"))
			dijit.byId("addCommentDlg").destroyRecursive();

		dialog = new dijit.Dialog({
			id: "addCommentDlg",
			title: __("Add comment"),
			style: "width: 600px",
			execute: function() {
				if (this.validate()) {
					var query = dojo.objectToQuery(this.attr('value'));

					notify_progress("Saving comment...", true);

					new Ajax.Request("backend.php",	{
					parameters: query,
					onComplete: function(transport) {
						/*notify('');
						dialog.hide();

						var reply = JSON.parse(transport.responseText);

						cache_delete("article:" + id);

						var elem = $("POSTCOMMENT-" + id);

						if (elem) {
							Element.hide(elem);
							elem.innerHTML = reply.comment;

							if (reply.raw_length != 0)
								new Effect.Appear(elem);
						}*/

					}});
				}
			},
			href: query,
		});

		dialog.show();

	} catch (e) {
		exception_error("addArticleComment", e);
	}
}

