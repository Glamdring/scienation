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
	var scienationOptions = {
		selected: [<?php echo $selectedJSArray; ?>],
		branchesUrl: "<?php echo plugins_url('branches.json', __FILE__); ?>"
	};
</script>
</head>
<input type="text" style="width: 433px;" id="branchSearchBox" placeholder="Select a branch of science..." />
<div id="branches" style="height: 310px; overflow: auto;">
</div>        
<?php
}