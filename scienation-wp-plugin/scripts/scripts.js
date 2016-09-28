//TODO minify
function update_output(target, value) {
	var id = jQuery(target).attr("id");
	jQuery("#" + id + "_output").val(value);
}