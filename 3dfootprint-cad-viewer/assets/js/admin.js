(function () {
	'use strict';

	var input = document.getElementById('product-json-file');
	var selected = document.querySelector('[data-product-json-selected]');

	if (!input || !selected) {
		return;
	}

	input.addEventListener('change', function () {
		var file = input.files && input.files.length ? input.files[0] : null;
		var fallback = window.ProductJsonAdmin && window.ProductJsonAdmin.i18n
			? window.ProductJsonAdmin.i18n.noFileSelected
			: 'No file selected.';

		selected.textContent = file ? file.name : fallback;
	});
})();
