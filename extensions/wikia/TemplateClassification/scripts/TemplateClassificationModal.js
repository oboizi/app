/**
 * TemplateClassificationModal module
 *
 * Initiates modal and opens it on entry point click
 * Provides two params in init method for handling save and providing selected type
 */
define('TemplateClassificationModal',
	['jquery', 'mw', 'wikia.loader', 'wikia.nirvana', 'wikia.tracker', 'TemplateClassificationLabeling'],
function ($, mw, loader, nirvana, tracker, labeling) {
	'use strict';

	var $classificationForm,
		$typeLabel,
		modalConfig,
		messagesLoaded,
		saveHandler = falseFunction,
		typeGetter = falseFunction;

	/**
	 * @param {function} typeGetterProvided Method that should return type in json format,
	 *  	also can return a promise that will return type
	 *  	eg. return from typeGetterProvided [{type:'exampletype'}]
	 * @param {function} saveHandlerProvided Method that should handle modal save,
	 *  	receives {string} selectedType as parameter
	 */
	function init(typeGetterProvided, saveHandlerProvided) {
		saveHandler = saveHandlerProvided;
		typeGetter = typeGetterProvided;
		$typeLabel = $('.template-classification-type-text');

		$('.template-classification-edit').click(function (e) {
			e.preventDefault();
			openEditModal('editType');
		});
	}

	function openEditModal(modeProvided) {
		var messagesLoader = falseFunction,
			classificationFormLoader = falseFunction;

		labeling.init(modeProvided);

		if (!messagesLoaded) {
			messagesLoader = getMessages;
		}

		if (!$classificationForm) {
			classificationFormLoader = getTemplateClassificationEditForm;
		}

		// Fetch all data and open modal
		$.when(
			classificationFormLoader(),
			typeGetter(),
			messagesLoader()
		).done(handleRequestsForModal);
	}

	function getTypeMessage(templateType) {
		return mw.message('template-classification-type-' + mw.html.escape(templateType));
	}

	function handleRequestsForModal(classificationForm, templateType, loaderRes) {
		if (loaderRes) {
			mw.messages.set(loaderRes.messages);
			messagesLoaded = true;
		}

		if (classificationForm) {
			$classificationForm = $(classificationForm[0]);
		}

		if (templateType) {
			// Mark selected type
			var selectedType = $classificationForm.find('input[value="' + templateType + '"]');

			if (selectedType.length > 0) {
				$classificationForm.find('input[checked="checked"]').removeAttr('checked');
				selectedType.attr('checked', 'checked');
			}
		}

		// Set modal content
		setupTemplateClassificationModal(
			labeling.prepareContent($classificationForm[0].outerHTML)
		);

		require(['wikia.ui.factory'], function (uiFactory) {
			/* Initialize the modal component */
			uiFactory.init(['modal']).then(createComponent);

			// Track - open TC modal
			tracker.track({
				trackingMethod: 'both',
				category: 'template-classification-dialog',
				action: 'open'
			});
		});
	}

	/**
	 * Creates modal UI component
	 * One of sub-tasks for getting modal shown
	 */
	function createComponent(uiModal) {
		/* Create the wrapping JS Object using the modalConfig */
		uiModal.createComponent(modalConfig, processInstance);
	}

	/**
	 * CreateComponent callback that finally shows modal
	 * and binds submit action to Done button
	 * One of sub-tasks for getting modal shown
	 */
	function processInstance(modalInstance) {
		/* Submit template type edit form on Done button click */
		modalInstance.bind('done', function runSave(e) {
			processSave(modalInstance);

			// Track - primary-button click
			tracker.track({
				trackingMethod: 'both',
				category: 'template-classification-dialog',
				action: 'primary-button',
				label: $(e.currentTarget).text()
			});
		});

		modalInstance.bind('close', function () {
			// Track - close TC modal
			tracker.track({
				trackingMethod: 'both',
				category: 'template-classification-dialog',
				action: 'close',
				label: 'close-event'
			});
		});

		modalInstance.bind('option-select', function(e) {
			var $input = $(e.currentTarget).find('input:radio');

			$input.prop('checked', true);

			// Track - click to change a template's type
			tracker.track({
				trackingMethod: 'both',
				category: 'template-classification-dialog',
				action: 'change',
				label: $input.val()
			});
		});

		/* Show the modal */
		modalInstance.show();
	}

	function processSave(modalInstance) {
		var templateType = $('#TemplateClassificationEditForm [name="template-classification-types"]:checked').val();

		saveHandler(templateType);

		modalInstance.trigger('close');
	}

	function updateEntryPointLabel(templateType) {
		$typeLabel
			.data('type', mw.html.escape(templateType))
			.html(getTypeMessage(templateType).escaped());
	}

	function setupTemplateClassificationModal(content) {
		/* Modal component configuration */
		modalConfig = {
			vars: {
				id: 'TemplateClassificationEditModal',
				classes: ['template-classification-edit-modal'],
				size: 'small', // size of the modal
				content: content, // content
				title: labeling.getTitle()
			}
		};

		var modalButtons = [
			{
				vars: {
						value: labeling.getConfirmButtonLabel(),
						classes: ['normal', 'primary'],
						data: [
							{
								key: 'event',
								value: 'done'
							}
						]
					}
			},
			{
				vars: {
					value: mw.message('template-classification-edit-modal-cancel-button-text').escaped(),
					data: [
						{
							key: 'event',
							value: 'close'
						}
					]
				}
			}
		];

		modalConfig.vars.buttons = modalButtons;
	}

	function getTemplateClassificationEditForm() {
		return nirvana.sendRequest({
			controller: 'TemplateClassification',
			method: 'getTemplateClassificationEditForm',
			type: 'get',
			format: 'html'
		});
	}

	function getMessages() {
		return loader({
			type: loader.MULTI,
			resources: {
				messages: 'TemplateClassificationModal,TemplateClassificationTypes'
			}
		});
	}

	function falseFunction() {
		return false;
	}

	return {
		init: init,
		open: openEditModal,
		updateEntryPointLabel: updateEntryPointLabel
	};
});
