<?php
function print_branches_html($selected) {
	$selectedJSArray = '';
	$delimiter = '';
	foreach ($selected as $branch) {
		$selectedJSArray .= $delimiter . '"' . $branch . '"';
		$delimiter = ',';
	}
	?>
<script type="text/javascript">
   var branchListFullyVisible = true; 
   var selected = [<?php echo $selectedJSArray; ?>];
   
   jQuery(document).ready(function() {
	   // delaying the loading with a second so that it doesn't interfere with the visual page loading
	   setTimeout(function() {
		   jQuery.get("<?php echo plugins_url('branches.json', __FILE__); ?>", function(branches) {
			   var container = jQuery("#branches");
			   var elements = [];
			   appendBranch(elements, branches);
			   container.append(elements.join(""));
			   container.tree({
				   onCheck: { 
					   ancestors: 'nothing', 
					   descendants: 'nothing' 
				   }, 
				   onUncheck: { 
					   ancestors: 'nothing' 
				   },
				   dnd: false,
				   selectable: false,
				   collapseEffect: null,
				   checkbox: false
			   });
			   
			   // if there are any selected items, show them
			   if (selected.length > 0) {
				   jQuery(".branchName").each(function() {
					   var branch = jQuery(this);
					   // also show all parents of the li to the top
					   var currentLi = branch.parent();
					   // the "found" ones here are the pre-selected ones
					   if (currentLi.hasClass("found")) {
						   while (currentLi.parent().parent().prop("tagName").toLowerCase() != "div") {
							   currentLi = currentLi.parent().parent();
							   if (!currentLi.hasClass("found")) {
								   currentLi.addClass("found");
								   currentLi.removeClass("collapseChildren");
							   } else {
								   break;
							   }
						   } 
					   }
				   });
				   showElements(container);
			   }
			   
			   jQuery("#branchSearchBox").keyup(function() {
				   delay(function() {
					   jQuery("#branches li").removeClass("found show collapseChildren");
					   var text = jQuery("#branchSearchBox").val().toLowerCase();
	
					   // only start filtering after the 2nd character
					   if (text.length < 3) {
						   text = "";
					   }
					   // avoid duplicate traversals if the list is already visible
					   if (!text && branchListFullyVisible) {
						   return;
					   }
					   jQuery(".branchName").each(function() {
						   var branch = jQuery(this);
						   if (branch.text().toLowerCase().indexOf(text) <= -1) {
							   branch.parent().hide(); // hide the li
						   } else {
							   var currentLi = branch.parent();
							   currentLi.addClass("found");
							   if (!currentLi.hasClass("leaf")) {
								   // collapse all children, so that they are accessible
								   currentLi.addClass("collapseChildren");
								   currentLi.find("li").each(function() {
									   jQuery(this).addClass("show");
								   });
							   }
							   // (if the input is empty, all nodes will be shown anyway)
							   if (text) {
								   // also show all parents of the li to the top
								   while (currentLi.parent().parent().prop("tagName").toLowerCase() != "div") {
									   currentLi = currentLi.parent().parent();
									   if (!currentLi.hasClass("found")) {
										   currentLi.addClass("found");
										   currentLi.removeClass("collapseChildren");
									   } else {
										   break;
									   }
								   }
							   }
						   }
					   });
					   showElements(container);
					   if (!text) {
						   branchListFullyVisible = true;
					   } else {
						   branchListFullyVisible = false;
					   }
			   }, 500);
		   });
		 });
	   }, 1000);
   });
   
   function showElements(container) {
	   // now expand all visible ones
	   jQuery("#branches .show").each(function() {
		   jQuery(this).show();
	   });
	   jQuery("#branches .found").each(function() {
		   var currentLi = jQuery(this);
		   currentLi.show();
		   if (currentLi.parent().parent().prop("tagName").toLowerCase() != "div") {
			   container.tree("expand", currentLi.parent().parent());
		   }
		   if (currentLi.hasClass("collapseChildren")) {
			   container.tree("collapse", currentLi);
		   }
	   });
   }
   
   function appendBranch(container, branches) {
	   container.push("<ul>");
	   for (var i = 0; i < branches.length; i ++) {
		   var branch = branches[i]; 
		   var cssClass = "collapsed";
		   if (branch.children.length == 0) {
			   cssClass = "leaf";
		   }
		   checked = "";
		   if (selected.indexOf(branch.name) != -1) {
				checked = ' checked="true"';
				cssClass = 'found';
		   }
		   container.push('<li class="' + cssClass + '" id="scienceBranchLi' + branch.id +'"><input type="checkbox" name="scienceBranch[]" id="scienceBranch' + branch.id + 
			   '" value="' + branch.name + '"' + checked + '/><label for="scienceBranch' + branch.id + '" class="branchName">' + branch.name + '</label>');

		   if (branches.length > 0) {
			   appendBranch(container, branch.children);
		   }
		   container.push("</li>");
		   
	   }
	   container.push("</ul>");
   }
   
   var delay = (function(){
	 var timer = 0;
	 return function(callback, ms){
	   clearTimeout (timer);
	   timer = setTimeout(callback, ms);
	 };
   })();
</script>
</head>
<input type="text" style="width: 433px;" id="branchSearchBox" placeholder="Select a branch of science..." />
<div id="branches" style="height: 310px; overflow: auto;">
</div>        
<?php
}