/* WooCommerce XML Feed Importer Admin Script */

(function() {
    'use strict';

    let currentFeedId = '';

    // Initialize trigger buttons
    document.addEventListener('DOMContentLoaded', function() {
        const triggerButtons = document.querySelectorAll('.trigger-btn');
        triggerButtons.forEach(function(btn) {
            btn.addEventListener('click', function(e) {
                e.preventDefault();
                const feedId = this.getAttribute('data-feed-id');
                const feedName = this.getAttribute('data-feed-name');
                const feedDataStr = this.getAttribute('data-feed-data');
                
                try {
                    const feedData = JSON.parse(feedDataStr);
                    wpfiOpenTriggerModal(feedId, feedName, feedData);
                } catch (err) {
                    console.error('Error parsing feed data:', err);
                    alert('Error loading feed data');
                }
            });
        });

        // Close modal when clicking outside
        const modal = document.getElementById('triggerModal');
        if (modal) {
            modal.addEventListener('click', function(e) {
                if (e.target === this) {
                    wpfiCloseTriggerModal();
                }
            });
        }
    });

    window.wpfiOpenTriggerModal = function(feedId, feedName, feedData) {
        currentFeedId = feedId;
        document.getElementById('modalFeedName').value = feedName || '';
        document.getElementById('modalFeedUrl').value = feedData.url || '';
        document.getElementById('modalFormat').value = (feedData.format || 'xml').toUpperCase();

        const authType = feedData.auth?.type || 'none';
        document.getElementById('modalAuthType').value = authType.charAt(0).toUpperCase() + authType.slice(1).replace('_', ' ');

        const pathParams = feedData.auth?.path_params || {};
        if (Object.keys(pathParams).length > 0) {
            document.getElementById('modalPathParams').style.display = 'block';
            let text = '';
            for (const [key, value] of Object.entries(pathParams)) {
                text += key + ' = ' + value + '\n';
            }
            document.getElementById('modalPathParamsText').value = text.trim();
        } else {
            document.getElementById('modalPathParams').style.display = 'none';
        }

        const queryParams = feedData.auth?.query_params || {};
        if (Object.keys(queryParams).length > 0) {
            document.getElementById('modalQueryParams').style.display = 'block';
            let text = '';
            for (const [key, value] of Object.entries(queryParams)) {
                text += key + ' = ' + value + '\n';
            }
            document.getElementById('modalQueryParamsText').value = text.trim();
        } else {
            document.getElementById('modalQueryParams').style.display = 'none';
        }

        const mappings = feedData.map || {};
        let mappingText = '';
        for (const [key, value] of Object.entries(mappings)) {
            mappingText += key + ' = ' + value + '\n';
        }
        document.getElementById('modalMappings').value = mappingText.trim();
        document.getElementById('modalSkipZeroStock').checked = feedData.skip_zero_stock === 1;
        document.getElementById('triggerStatus').style.display = 'none';
        document.getElementById('triggerStatus').innerHTML = '';
        document.getElementById('triggerModal').classList.add('active');
    };

    window.wpfiCloseTriggerModal = function() {
        document.getElementById('triggerModal').classList.remove('active');
        currentFeedId = '';
    };

    window.wpfiConfirmTrigger = function() {
        if (!currentFeedId) {
            alert('Feed ID not set');
            return;
        }

        const startBtn = document.getElementById('startBtn');
        const cancelBtn = document.getElementById('cancelBtn');
        const spinner = document.getElementById('triggerSpinner');
        const statusDiv = document.getElementById('triggerStatus');

        startBtn.disabled = true;
        cancelBtn.disabled = true;
        spinner.style.display = 'inline';

        const formData = new FormData();
        formData.append('action', 'wpfi_trigger_feed');
        formData.append('feed_id', currentFeedId);
        formData.append('nonce', wpfiAdmin.nonce);

        console.log('[WPFI] Sending trigger request for feed:', currentFeedId);
        console.log('[WPFI] AJAX URL:', wpfiAdmin.ajaxUrl);

        fetch(wpfiAdmin.ajaxUrl, {
            method: 'POST',
            body: formData
        })
        .then(function(response) {
            console.log('[WPFI] Response status:', response.status);
            if (!response.ok) {
                throw new Error('HTTP ' + response.status);
            }
            return response.json();
        })
        .then(function(data) {
            console.log('[WPFI] Response data:', data);
            spinner.style.display = 'none';
            statusDiv.style.display = 'block';

            if (data.success) {
                statusDiv.className = 'trigger-status success';
                statusDiv.innerHTML = '<strong>✓ Import Started</strong><br>' + (data.data?.message || 'Feed import completed successfully.');
                setTimeout(function() {
                    wpfiCloseTriggerModal();
                    location.reload();
                }, 2000);
            } else {
                statusDiv.className = 'trigger-status error';
                statusDiv.innerHTML = '<strong>✗ Import Failed</strong><br>' + (data.data?.message || 'An error occurred while starting the import.');
                startBtn.disabled = false;
                cancelBtn.disabled = false;
            }
        })
        .catch(function(error) {
            console.error('[WPFI] Fetch error:', error);
            spinner.style.display = 'none';
            statusDiv.style.display = 'block';
            statusDiv.className = 'trigger-status error';
            statusDiv.innerHTML = '<strong>✗ Error</strong><br>Network error: ' + error.message;
            startBtn.disabled = false;
            cancelBtn.disabled = false;
        });
    };
})();
