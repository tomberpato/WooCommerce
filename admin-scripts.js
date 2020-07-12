jQuery(document).ready(function($) {
	$("button.button-secondary").click(function() {
		let machineId = $("input.machine_id").val();
		let machineKey = $("input.machine_key").val();
		let integratorId = $("input.integrator_id").val();

		if (machineId === '' || machineKey === '' || integratorId === '')
		{
			alert("Please enter all API-keys");
			return;
		}
		let ajax_data = {
			action: 'get_inventoryitem',
			machineId: machineId,
			machineKey: machineKey,
			integratorId: integratorId
		};
		$.ajax({
			type: 'POST',
			data: ajax_data,
			url: wp_admin.ajaxurl,
			dataType: 'json',
			success: function(response) {
				$("#status").val(response.status);
			},
			error: function(xhr, status) {
				$("#status").val('AJAX error: ' + xhr.status);
			}
		})
	})
});

/*
jQuery(document).ready(function($) {
    // REST API requests sent from client doesn't work for some reason
	$("button.button-secondary").click(function() {
		let dinkassaRequest = new XMLHttpRequest();
		dinkassaRequest.open('GET', 'https://www.dinkassa.se/api/inventoryitem?fetch=1', true);
		dinkassaRequest.setRequestHeader('machineId', $("input.machine_id").val());
		dinkassaRequest.setRequestHeader('machineKey', $("input.machine_key").val());
		dinkassaRequest.setRequestHeader('integratorId', $("input.integrator_id").val());
		dinkassaRequest.onload = function()
		{
			$("#status").val(dinkassaRequest.status);
		}
		dinkassaRequest.send();
	})
});
*/

// Sets the hidden input fields '_modified_custom_fields' and
// '_modified_builtin_fields' to true/false depending on whether
// custom/builtin product fields have been modified.
jQuery(document).ready(function($){
	$("#_modified_custom_fields").val(null);
	$("#_modified_builtin_fields").val(null);
	$("[id^='_custom_pf_']").on('change', function(event) {
		$("#_modified_custom_fields").val(1);
		let element_id = $(this).attr('id');
		if (element_id === '_custom_pf_description')
		{
			if ($("#_synchronize_description").val())
			{
				let description = $(this).val();
				$("#title").val(description);
			}
		}
	});
	$("#title").on('change', function(event) {
		if ($("#_synchronize_description").val()) {
			let product_name = $(this).val();
			$("#_custom_pf_description").val(product_name);
			$("#_modified_custom_fields").val(1);
		}
	});
	$("[id^='_visibility_']").on('change', function(event) {
		$("#_modified_builtin_fields").val(1);
	});
	$("#_regular_price").on('change', function(event) {
		if ($("#_synchronize_prices").val())
			$("#_modified_builtin_fields").val(1);
	});
	$("[id^='in-product_cat-']").on('change', function(event) {
		$("#_modified_builtin_fields").val(1);
	});
});

jQuery(document).ready(function($) {
	let sync_checkbox = $("input.synch_checkbox");
	let value = sync_checkbox.is(':checked');
	set_required_attribute($, value);
	sync_checkbox.click(function() {
		value = sync_checkbox.is(':checked');
		set_required_attribute($, value);
	});
});

function set_required_attribute($, value)
{
	$("input.machine_id").attr("required", value);
	$("input.machine_key").attr("required", value);
	$("input.integrator_id").attr("required", value);
}

jQuery(document).ready(function($) {
	let category_input = $("#_custom_pf_categoryname");
	$("input[id^='in-product_cat-']").on('change', function() {
		let selected_category = $(this).parent().text().trim();
		if (selected_category.localeCompare('Uncategorized') === 0)
			$(this).prop('checked', true); // Don't uncheck 'Uncategorized'
		else {
			let current_cat = category_input.val().trim();
			$(this).parent().css('font-weight', 'normal');
			if (selected_category.localeCompare(current_cat) === 0) {
				// Main category unchecked. Check 'Uncategorized' checkbox
				// and set main category option to blank
				select_uncategorized_category($);
			}
		}
	})
})

jQuery(document).ready(function($) {
	let category_input = $("#_custom_pf_categoryname");
	let category_id_input = $("#_custom_pf_current_cat_term_id");
	if (category_id_input.val() === "")
		select_uncategorized_category($); // New product. Set main category to 'Uncategorized'
	else {
		let category_id = category_id_input.val();
		let selector = 'input#in-product_cat-' + category_id;
		$(selector).parent().css('font-weight', 'bold');
	}
	category_input.on('change', function(event) {
		let selected_category = $(this).val().trim();
		let category_id = category_id_input.val();
		let selector = 'input#in-product_cat-' + category_id;
		$(selector).prop('checked', false);
		$(selector).parent().css('font-weight', 'normal');
		selector = "label.selectit:contains(" + selected_category + ") > input";
		$(selector).prop('checked', true);
		$(selector).parent().css('font-weight', 'bold');
		if (selected_category.localeCompare('Uncategorized') === 0)
		{
			category_input.prop("selectedIndex", -1);
		}
		category_id = $(selector).val();
		category_id_input.val(category_id)
	})
})

function select_uncategorized_category($)
{
	$("#_custom_pf_categoryname").prop("selectedIndex", -1);
	let selector = "label.selectit:contains(Uncategorized) > input";
	$(selector).prop('checked', true);
	$(selector).parent().css('font-weight', 'bold');
	let category_term_id = $(selector).val();
	$("#_custom_pf_current_cat_term_id").val(category_term_id);
}