/**
 * PerryLabs SEO + AEO — Meta Box JavaScript
 *
 * Live Google search preview and character counters with color feedback.
 * No focus keyword. No SEO score. No readability. Just the essentials.
 */

(function ($) {
	'use strict';

	var config = window.perryLabsSEO || {};
	var separator = config.separator || '|';
	var siteName = config.siteName || '';
	var siteUrl = config.siteUrl || '';

	/**
	 * Character counter configuration.
	 */
	var limits = {
		title: { good: 30, warn: 60, max: 70 },
		desc: { good: 70, warn: 160, max: 200 },
	};

	/**
	 * Update a character counter bar and text.
	 *
	 * @param {string} type  - 'title' or 'desc'
	 * @param {number} length - Current character count.
	 */
	function updateCounter(type, length) {
		var limit = limits[type];
		var barId = type === 'title' ? '#perrylabs-seo-title-bar' : '#perrylabs-seo-desc-bar';
		var textId = type === 'title' ? '#perrylabs-seo-title-count' : '#perrylabs-seo-desc-count';
		var maxDisplay = type === 'title' ? 60 : 160;

		var $bar = $(barId);
		var $text = $(textId);

		// Calculate bar width as percentage of max.
		var percentage = Math.min((length / limit.max) * 100, 100);
		$bar.css('width', percentage + '%');

		// Determine color state.
		var state;
		if (length === 0) {
			state = '';
			$bar.css('width', '0%');
		} else if (length <= limit.good) {
			state = 'good';
		} else if (length <= limit.warn) {
			state = 'good';
		} else if (length <= limit.max) {
			state = 'warning';
		} else {
			state = 'danger';
		}

		// Update bar color.
		$bar.removeClass('perrylabs-seo-counter__bar--good perrylabs-seo-counter__bar--warning perrylabs-seo-counter__bar--danger');
		if (state) {
			$bar.addClass('perrylabs-seo-counter__bar--' + state);
		}

		// Update text.
		$text.text(length + ' / ' + maxDisplay);
		$text.removeClass('perrylabs-seo-counter__text--good perrylabs-seo-counter__text--warning perrylabs-seo-counter__text--danger');
		if (state) {
			$text.addClass('perrylabs-seo-counter__text--' + state);
		}
	}

	/**
	 * Resolve template tags in a title string.
	 *
	 * @param {string} template - Title template.
	 * @param {string} postTitle - The post title.
	 * @return {string} Resolved title.
	 */
	function resolveTitle(template, postTitle) {
		if (!template) {
			return postTitle + ' ' + separator + ' ' + siteName;
		}

		if (template.indexOf('%') !== -1) {
			return template
				.replace(/%title%/g, postTitle)
				.replace(/%sitename%/g, siteName)
				.replace(/%sep%/g, separator);
		}

		return template + ' ' + separator + ' ' + siteName;
	}

	/**
	 * Update the Google search preview.
	 */
	function updatePreview() {
		var seoTitle = $('#perrylabs-seo-title').val();
		var seoDesc = $('#perrylabs-seo-description').val();
		var postTitle = $('#perrylabs-seo-post-title').val() || $('#title').val() || 'Post Title';
		var postExcerpt = $('#perrylabs-seo-post-excerpt').val() || '';

		// Title preview.
		var previewTitle = resolveTitle(seoTitle, postTitle);
		$('#perrylabs-seo-preview-title').text(previewTitle);

		// Description preview.
		var previewDesc = seoDesc || postExcerpt || 'No description set. Google will auto-generate one from your content.';
		$('#perrylabs-seo-preview-desc').text(previewDesc);

		// Update title counter — count the resolved title length.
		var titleLength = seoTitle ? seoTitle.length : 0;
		if (titleLength > 0) {
			// If using template tags, count the resolved length.
			var resolvedLength = resolveTitle(seoTitle, postTitle).length;
			updateCounter('title', resolvedLength);
		} else {
			// Count what the auto-generated title would be.
			var autoTitle = postTitle + ' ' + separator + ' ' + siteName;
			updateCounter('title', autoTitle.length);
		}

		// Update description counter.
		var descLength = seoDesc ? seoDesc.length : 0;
		updateCounter('desc', descLength);
	}

	/**
	 * Initialize on DOM ready.
	 */
	$(function () {
		// Only run on pages with our meta box.
		if (!$('#perrylabs-seo-title').length) {
			return;
		}

		// Initial update.
		updatePreview();

		// Live updates on input.
		$('#perrylabs-seo-title, #perrylabs-seo-description').on('input keyup', updatePreview);

		// Also watch the WP post title field (classic editor).
		$('#title').on('input keyup', function () {
			$('#perrylabs-seo-post-title').val($(this).val());
			updatePreview();
		});

		// Gutenberg: watch for title changes via MutationObserver.
		var gutenbergTitle = document.querySelector('.editor-post-title__input, .wp-block-post-title');
		if (gutenbergTitle) {
			var observer = new MutationObserver(function () {
				var newTitle = gutenbergTitle.textContent || gutenbergTitle.innerText || '';
				$('#perrylabs-seo-post-title').val(newTitle.trim());
				updatePreview();
			});

			observer.observe(gutenbergTitle, {
				childList: true,
				subtree: true,
				characterData: true,
			});

			// Also catch direct input events on contenteditable.
			gutenbergTitle.addEventListener('input', function () {
				var newTitle = gutenbergTitle.textContent || gutenbergTitle.innerText || '';
				$('#perrylabs-seo-post-title').val(newTitle.trim());
				updatePreview();
			});
		}

		// Social image upload button.
		$('.perrylabs-seo-upload-btn').on('click', function (e) {
			e.preventDefault();

			var button = $(this);
			var targetId = button.data('target');
			var $input = $('#' + targetId);

			var frame = wp.media({
				title: config.i18n ? config.i18n.selectImage : 'Select Social Image',
				button: { text: config.i18n ? config.i18n.useImage : 'Use this image' },
				multiple: false,
				library: { type: 'image' },
			});

			frame.on('select', function () {
				var attachment = frame.state().get('selection').first().toJSON();
				$input.val(attachment.url);

				// Update or create preview.
				var $preview = button.closest('.perrylabs-seo-image-field').find('.perrylabs-seo-image-preview');
				if ($preview.length) {
					$preview.html('<img src="' + attachment.url + '" style="max-width:200px;height:auto;" />');
				} else {
					button.closest('.perrylabs-seo-image-field').append(
						'<div class="perrylabs-seo-image-preview" style="margin-top:8px;">' +
						'<img src="' + attachment.url + '" style="max-width:200px;height:auto;" />' +
						'</div>'
					);
				}
			});

			frame.open();
		});

		// Alternate URLs repeater.
		$('#perrylabs-seo-add-alternate').on('click', function () {
			var row = '<div class="perrylabs-seo-alternate-row" style="display:flex;gap:6px;margin-bottom:6px;">' +
				'<input type="url" name="_perrylabs_seo_alternate_urls[]" value="" class="widefat" placeholder="https://" />' +
				'<button type="button" class="button perrylabs-seo-remove-alternate">&times;</button>' +
				'</div>';
			$('#perrylabs-seo-alternate-urls').append(row);
		});

		$(document).on('click', '.perrylabs-seo-remove-alternate', function () {
			$(this).closest('.perrylabs-seo-alternate-row').remove();
		});
	});
})(jQuery);
